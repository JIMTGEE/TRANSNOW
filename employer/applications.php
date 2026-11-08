<?php
// employer/applications.php
// Professional Applications Management for Employers

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../includes/auth.php';

// Require employer login
requireEmployerLogin();

// Get database connection
$pdo = getDB();
$employer_id = getCurrentEmployerId($pdo);

// Get employer company info
$employer = fetchOne("SELECT e.*, u.email 
                      FROM employers e 
                      JOIN users u ON e.user_id = u.id 
                      WHERE e.id = ?", [$employer_id]);

// Get all jobs for this employer for filtering
$employer_jobs = fetchAll("SELECT id, title, total_applications 
                          FROM jobs 
                          WHERE employer_id = ? 
                          ORDER BY created_at DESC", [$employer_id]);

// Build filter conditions
$filters = [];
$params = [$employer_id];

// Job filter
if (isset($_GET['job_id']) && !empty($_GET['job_id'])) {
    $filters[] = "j.id = ?";
    $params[] = (int)$_GET['job_id'];
}

// Status filter
if (isset($_GET['status']) && !empty($_GET['status']) && $_GET['status'] !== 'all') {
    $filters[] = "a.status = ?";
    $params[] = $_GET['status'];
}

// Search filter
if (isset($_GET['search']) && !empty($_GET['search'])) {
    $filters[] = "(s.first_name LIKE ? OR s.last_name LIKE ? OR u.email LIKE ? OR j.title LIKE ?)";
    $search_term = '%' . $_GET['search'] . '%';
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

// Date range filter
if (isset($_GET['date_from']) && !empty($_GET['date_from'])) {
    $filters[] = "DATE(a.created_at) >= ?";
    $params[] = $_GET['date_from'];
}

if (isset($_GET['date_to']) && !empty($_GET['date_to'])) {
    $filters[] = "DATE(a.created_at) <= ?";
    $params[] = $_GET['date_to'];
}

// Match score filter
if (isset($_GET['min_score']) && !empty($_GET['min_score'])) {
    $filters[] = "a.match_score >= ?";
    $params[] = (float)$_GET['min_score'];
}

// Build WHERE clause
$where_clause = !empty($filters) ? "AND " . implode(" AND ", $filters) : "";

// Pagination
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 20;
$offset = ($page - 1) * $per_page;

// Get total count for pagination
$total_query = "SELECT COUNT(*) as count 
                FROM applications a 
                JOIN jobs j ON a.job_id = j.id 
                JOIN seekers s ON a.seeker_id = s.id
                JOIN users u ON s.user_id = u.id
                WHERE j.employer_id = ? $where_clause";

$total_applications = fetchOne($total_query, $params)['count'] ?? 0;
$total_pages = ceil($total_applications / $per_page);

// Get applications with all details
$applications_query = "SELECT 
    a.*,
    j.id as job_id,
    j.title as job_title,
    j.salary_min as job_salary_min,
    j.salary_max as job_salary_max,
    j.salary_currency as job_currency,
    j.location_type as job_location_type,
    j.city as job_city,
    j.country as job_country,
    s.id as seeker_id,
    s.first_name,
    s.last_name,
    s.phone,
    s.whatsapp_number,
    s.profile_picture,
    s.headline,
    s.bio,
    s.resume_url,
    s.location as seeker_location,
    s.current_job_title,
    s.current_company,
    s.years_experience,
    s.highest_education,
    s.skills,
    s.expected_salary_min,
    s.expected_salary_max,
    u.email as seeker_email,
    (SELECT COUNT(*) FROM applications WHERE seeker_id = s.id) as total_applications_by_seeker,
    (SELECT COUNT(*) FROM saved_candidates WHERE employer_id = ? AND seeker_id = s.id) as is_saved
FROM applications a 
JOIN jobs j ON a.job_id = j.id 
JOIN seekers s ON a.seeker_id = s.id
JOIN users u ON s.user_id = u.id
WHERE j.employer_id = ? $where_clause
ORDER BY 
    CASE a.status 
        WHEN 'applied' THEN 1
        WHEN 'reviewed' THEN 2
        WHEN 'shortlisted' THEN 3
        WHEN 'interview' THEN 4
        WHEN 'offered' THEN 5
        WHEN 'hired' THEN 6
        WHEN 'rejected' THEN 7
        ELSE 8
    END,
    a.created_at DESC
LIMIT ? OFFSET ?";

// Merge params for the main query
$query_params = array_merge([$employer_id, $employer_id], array_slice($params, 1), [$per_page, $offset]);
$applications = fetchAll($applications_query, $query_params);

// Get status counts for stats
$status_counts = fetchAll("SELECT 
    a.status,
    COUNT(*) as count,
    COUNT(*) * 100.0 / NULLIF(SUM(COUNT(*)) OVER(), 0) as percentage
FROM applications a
JOIN jobs j ON a.job_id = j.id
WHERE j.employer_id = ?
GROUP BY a.status
ORDER BY FIELD(status, 'applied', 'reviewed', 'shortlisted', 'interview', 'offered', 'hired', 'rejected')", [$employer_id]);

// Get recent activity for this employer
$recent_activity = fetchAll("SELECT 
    'application' as type,
    a.id as reference_id,
    a.status,
    s.first_name,
    s.last_name,
    j.title as job_title,
    a.created_at as date
FROM applications a
JOIN jobs j ON a.job_id = j.id
JOIN seekers s ON a.seeker_id = s.id
WHERE j.employer_id = ?
ORDER BY a.created_at DESC
LIMIT 10", [$employer_id]);

// Handle bulk actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    header('Content-Type: application/json');
    
    if (!isset($_POST['application_ids']) || empty($_POST['application_ids'])) {
        echo json_encode(['success' => false, 'message' => 'No applications selected']);
        exit();
    }
    
    $application_ids = array_map('intval', $_POST['application_ids']);
    $placeholders = implode(',', array_fill(0, count($application_ids), '?'));
    
    switch ($_POST['bulk_action']) {
        case 'update_status':
            if (!isset($_POST['new_status'])) {
                echo json_encode(['success' => false, 'message' => 'No status provided']);
                exit();
            }
            
            $new_status = sanitize($_POST['new_status']);
            
            // Verify all applications belong to this employer
            $verify = fetchAll("SELECT a.id, a.seeker_id, j.title 
                               FROM applications a 
                               JOIN jobs j ON a.job_id = j.id 
                               WHERE a.id IN ($placeholders) AND j.employer_id = ?", 
                               array_merge($application_ids, [$employer_id]));
            
            if (count($verify) === count($application_ids)) {
                $updated = executeQuery("UPDATE applications SET status = ? WHERE id IN ($placeholders)", 
                                       array_merge([$new_status], $application_ids));
                
                if ($updated) {
                    // Create notifications for seekers
                    foreach ($verify as $app) {
                        $subject = "Application Status Updated";
                        $message = "Your application for '{$app['title']}' has been updated to: " . ucfirst($new_status);
                        createNotification($app['seeker_id'], 'application_update', $subject, $message, 'both');
                    }
                    
                    logUserActivity('bulk_status_update', "Updated " . count($application_ids) . " applications to {$new_status}", $pdo);
                    
                    echo json_encode(['success' => true, 'message' => count($application_ids) . ' applications updated successfully']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Failed to update applications']);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'Some applications not found']);
            }
            break;
            
        case 'mark_favorite':
            // Save candidates
            foreach ($application_ids as $app_id) {
                $app = fetchOne("SELECT a.seeker_id FROM applications a 
                                JOIN jobs j ON a.job_id = j.id 
                                WHERE a.id = ? AND j.employer_id = ?", 
                                [$app_id, $employer_id]);
                
                if ($app) {
                    // Check if already saved
                    $exists = fetchOne("SELECT id FROM saved_candidates 
                                       WHERE employer_id = ? AND seeker_id = ?", 
                                       [$employer_id, $app['seeker_id']]);
                    
                    if (!$exists) {
                        executeQuery("INSERT INTO saved_candidates (employer_id, seeker_id) VALUES (?, ?)", 
                                   [$employer_id, $app['seeker_id']]);
                    }
                }
            }
            
            echo json_encode(['success' => true, 'message' => 'Candidates saved successfully']);
            break;
            
        case 'export':
            // Handle export functionality
            $export_data = fetchAll("SELECT 
                s.first_name, s.last_name, u.email, s.phone, s.current_job_title,
                j.title as job_title, a.status, a.match_score, a.expected_salary,
                a.notice_period_days, a.created_at as applied_date
            FROM applications a
            JOIN jobs j ON a.job_id = j.id
            JOIN seekers s ON a.seeker_id = s.id
            JOIN users u ON s.user_id = u.id
            WHERE a.id IN ($placeholders) AND j.employer_id = ?", 
            array_merge($application_ids, [$employer_id]));
            
            // Generate CSV
            $filename = 'applications_export_' . date('Y-m-d_His') . '.csv';
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            
            $output = fopen('php://output', 'w');
            fputcsv($output, ['First Name', 'Last Name', 'Email', 'Phone', 'Current Job', 'Job Applied', 'Status', 'Match Score', 'Expected Salary', 'Notice Period', 'Applied Date']);
            
            foreach ($export_data as $row) {
                fputcsv($output, $row);
            }
            
            fclose($output);
            exit();
            break;
    }
    exit();
}

// Handle single application status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_single_status') {
    header('Content-Type: application/json');
    
    if (!isset($_POST['application_id'], $_POST['status'])) {
        echo json_encode(['success' => false, 'message' => 'Missing parameters']);
        exit();
    }
    
    $application_id = (int)$_POST['application_id'];
    $new_status = sanitize($_POST['status']);
    
    // Verify application belongs to this employer
    $verify = fetchOne("SELECT a.id, a.seeker_id, j.title 
                       FROM applications a 
                       JOIN jobs j ON a.job_id = j.id 
                       WHERE a.id = ? AND j.employer_id = ?", 
                       [$application_id, $employer_id]);
    
    if ($verify) {
        $updated = executeQuery("UPDATE applications SET status = ? WHERE id = ?", 
                               [$new_status, $application_id]);
        
        if ($updated) {
            // Create notification for seeker
            $subject = "Application Status Updated";
            $message = "Your application for '{$verify['title']}' has been updated to: " . ucfirst($new_status);
            createNotification($verify['seeker_id'], 'application_update', $subject, $message, 'both');
            
            // Update job's total applications count if needed
            if ($new_status === 'hired') {
                executeQuery("UPDATE jobs SET total_applications = total_applications - 1 
                             WHERE id = (SELECT job_id FROM applications WHERE id = ?)", 
                             [$application_id]);
            }
            
            logUserActivity('application_status_update', "Updated application #{$application_id} to {$new_status}", $pdo);
            
            echo json_encode(['success' => true, 'message' => 'Status updated successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to update status']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Application not found']);
    }
    exit();
}

// Get unread notifications count
$unread_notifications = fetchOne("SELECT COUNT(*) as count 
                                  FROM notifications 
                                  WHERE user_id = ? AND channel = 'inapp' AND status = 'sent'", 
                                  [$_SESSION['user_id']])['count'] ?? 0;

// Page title
$page_title = "Manage Applications";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?> - Job Portal</title>
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    
    <!-- Select2 for better dropdowns -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    
    <!-- Date Range Picker -->
    <link rel="stylesheet" type="text/css" href="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.css" />
    
    <!-- Custom Styles -->
    <style>
        .stat-card {
            transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
        }
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }
        .application-row {
            transition: background-color 0.2s ease;
        }
        .application-row:hover {
            background-color: #F9FAFB;
        }
        .status-badge {
            transition: all 0.2s ease;
        }
        .status-badge:hover {
            filter: brightness(95%);
        }
        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
        .animate-slideIn {
            animation: slideIn 0.3s ease-out forwards;
        }
        
        /* Bulk actions bar */
        .bulk-actions-bar {
            transition: transform 0.3s ease-in-out;
        }
        .bulk-actions-bar.hidden {
            transform: translateY(100%);
        }
        
        /* Modal styles */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(0, 0, 0, 0.5);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }
        
        .modal-overlay.active {
            display: flex;
        }
        
        .modal-content {
            animation: slideIn 0.3s ease-out;
            max-width: 500px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }
        
        /* Loading spinner */
        .spinner {
            border: 3px solid #f3f3f3;
            border-top: 3px solid #3498db;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Match score indicator */
        .match-score-high {
            background: linear-gradient(135deg, #10B981 0%, #059669 100%);
        }
        .match-score-medium {
            background: linear-gradient(135deg, #F59E0B 0%, #D97706 100%);
        }
        .match-score-low {
            background: linear-gradient(135deg, #EF4444 0%, #DC2626 100%);
        }
    </style>
</head>
<body class="bg-gray-50">
    <!-- Bulk Actions Bar -->
    <div id="bulkActionsBar" class="bulk-actions-bar fixed bottom-0 left-0 right-0 bg-white shadow-2xl border-t border-gray-200 p-4 transform translate-y-full z-40">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-4">
                    <span class="text-sm font-medium text-gray-700">
                        <span id="selectedCount">0</span> application(s) selected
                    </span>
                    <button onclick="selectAll()" class="text-sm text-blue-600 hover:text-blue-800">
                        <i class="fas fa-check-double mr-1"></i>Select All
                    </button>
                    <button onclick="clearSelection()" class="text-sm text-gray-600 hover:text-gray-800">
                        <i class="fas fa-times mr-1"></i>Clear
                    </button>
                </div>
                <div class="flex items-center space-x-3">
                    <select id="bulkStatusSelect" class="rounded-lg border-gray-300 text-sm focus:ring-blue-500 focus:border-blue-500">
                        <option value="">Change Status to...</option>
                        <option value="reviewed">Reviewed</option>
                        <option value="shortlisted">Shortlisted</option>
                        <option value="interview">Interview</option>
                        <option value="offered">Offered</option>
                        <option value="hired">Hired</option>
                        <option value="rejected">Rejected</option>
                    </select>
                    <button onclick="bulkUpdateStatus()" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition text-sm">
                        <i class="fas fa-sync-alt mr-2"></i>Update
                    </button>
                    <button onclick="bulkSaveCandidates()" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition text-sm">
                        <i class="fas fa-save mr-2"></i>Save Candidates
                    </button>
                    <button onclick="bulkExport()" class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition text-sm">
                        <i class="fas fa-download mr-2"></i>Export
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick View Modal -->
    <div id="quickViewModal" class="modal-overlay">
        <div class="modal-content bg-white rounded-xl shadow-2xl">
            <div id="quickViewContent" class="p-6">
                <!-- Content loaded via AJAX -->
                <div class="flex justify-center items-center h-64">
                    <div class="spinner"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Navigation -->
    <nav class="bg-white shadow-lg border-b border-gray-200 fixed w-full z-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16">
                <div class="flex items-center">
                    <a href="dashboard.php" class="flex items-center space-x-3">
                        <i class="fas fa-briefcase text-2xl text-blue-600"></i>
                        <span class="font-bold text-xl text-gray-800">TRANSNOW</span>
                    </a>
                    <div class="hidden md:flex ml-10 space-x-8">
                        <a href="dashboard.php" class="text-gray-600 hover:text-blue-600 px-3 py-2 text-sm font-medium transition">Dashboard</a>
                        <a href="manage_jobs.php" class="text-gray-600 hover:text-blue-600 px-3 py-2 text-sm font-medium transition">My Jobs</a>
                        <a href="post-job.php" class="text-gray-600 hover:text-blue-600 px-3 py-2 text-sm font-medium transition">Post Job</a>
                        <a href="applications.php" class="text-blue-600 border-b-2 border-blue-600 px-3 py-2 text-sm font-medium">Applications</a>
                        <a href="profile.php" class="text-gray-600 hover:text-blue-600 px-3 py-2 text-sm font-medium transition">Company Profile</a>
                    </div>
                </div>
                
                <div class="flex items-center space-x-4">
                    <!-- Notifications -->
                    <div class="relative">
                        <button id="notificationBtn" class="text-gray-600 hover:text-blue-600 focus:outline-none relative">
                            <i class="fas fa-bell text-xl"></i>
                            <?php if ($unread_notifications > 0): ?>
                                <span class="absolute -top-1 -right-1 bg-red-500 text-white text-xs rounded-full h-4 w-4 flex items-center justify-center">
                                    <?= min($unread_notifications, 9) . ($unread_notifications > 9 ? '+' : '') ?>
                                </span>
                            <?php endif; ?>
                        </button>
                        
                        <!-- Notification Dropdown -->
                        <div id="notificationDropdown" class="hidden absolute right-0 mt-2 w-80 bg-white rounded-lg shadow-xl border border-gray-200 z-50">
                            <div class="p-4 border-b border-gray-200">
                                <h3 class="font-semibold text-gray-800">Notifications</h3>
                            </div>
                            <div class="max-h-96 overflow-y-auto">
                                <?php
                                $notifications = fetchAll("SELECT * FROM notifications 
                                                          WHERE user_id = ? AND channel = 'inapp' 
                                                          ORDER BY created_at DESC LIMIT 5", 
                                                          [$_SESSION['user_id']]);
                                
                                if (empty($notifications)): ?>
                                    <div class="p-4 text-center text-gray-500">
                                        <i class="fas fa-bell-slash text-3xl mb-2"></i>
                                        <p>No notifications</p>
                                    </div>
                                <?php else: 
                                    foreach ($notifications as $note): ?>
                                        <div class="p-4 hover:bg-gray-50 border-b border-gray-100 <?= $note['status'] === 'sent' ? 'bg-blue-50' : '' ?>">
                                            <p class="font-medium text-gray-800"><?= htmlspecialchars($note['subject']) ?></p>
                                            <p class="text-sm text-gray-600"><?= htmlspecialchars($note['message']) ?></p>
                                            <p class="text-xs text-gray-400 mt-1"><?= timeAgo($note['created_at']) ?></p>
                                        </div>
                                <?php endforeach; 
                                endif; ?>
                            </div>
                            <a href="notifications.php" class="block p-3 text-center text-blue-600 hover:bg-gray-50 rounded-b-lg border-t border-gray-200">
                                View All
                            </a>
                        </div>
                    </div>
                    
                    <!-- User Menu -->
                    <div class="relative">
                        <button id="userMenuBtn" class="flex items-center space-x-3 focus:outline-none">
                            <div class="flex items-center space-x-3">
                                <?php if (!empty($employer['company_logo'])): ?>
                                    <img src="<?= htmlspecialchars($employer['company_logo']) ?>" alt="Company Logo" class="h-8 w-8 rounded-full object-cover border-2 border-blue-500">
                                <?php else: ?>
                                    <div class="h-8 w-8 rounded-full bg-gradient-to-r from-blue-500 to-blue-600 flex items-center justify-center text-white font-bold">
                                        <?= strtoupper(substr($employer['company_name'] ?? 'C', 0, 1)) ?>
                                    </div>
                                <?php endif; ?>
                                <span class="text-gray-700"><?= htmlspecialchars($employer['company_name'] ?? 'Employer') ?></span>
                                <i class="fas fa-chevron-down text-gray-400 text-sm"></i>
                            </div>
                        </button>
                        
                        <div id="userDropdown" class="hidden absolute right-0 mt-2 w-48 bg-white rounded-lg shadow-xl border border-gray-200 z-50">
                            <a href="profile.php" class="block px-4 py-3 text-gray-700 hover:bg-gray-50 transition">
                                <i class="fas fa-user mr-2"></i> Profile
                            </a>
                            <a href="settings.php" class="block px-4 py-3 text-gray-700 hover:bg-gray-50 transition">
                                <i class="fas fa-cog mr-2"></i> Settings
                            </a>
                            <hr class="border-gray-200">
                            <a href="../logout.php" class="block px-4 py-3 text-red-600 hover:bg-red-50 transition">
                                <i class="fas fa-sign-out-alt mr-2"></i> Logout
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="pt-20 pb-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            
            <!-- Flash Messages -->
            <?= displayFlashMessage() ?>
            
            <!-- Header -->
            <div class="mb-8">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-3xl font-bold text-gray-800">Applications Management</h1>
                        <p class="text-gray-600 mt-2">Review and manage all job applications</p>
                    </div>
                    <div class="flex space-x-3">
                        <button onclick="exportCurrentView()" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition text-sm flex items-center">
                            <i class="fas fa-download mr-2"></i>Export View
                        </button>
                        <button onclick="resetFilters()" class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition text-sm flex items-center">
                            <i class="fas fa-undo mr-2"></i>Reset Filters
                        </button>
                    </div>
                </div>
            </div>

            <!-- Statistics Cards -->
            <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-4 mb-8">
                <?php
                $status_colors = [
                    'applied' => ['bg' => 'bg-blue-100', 'text' => 'text-blue-800', 'icon' => 'fas fa-paper-plane'],
                    'reviewed' => ['bg' => 'bg-purple-100', 'text' => 'text-purple-800', 'icon' => 'fas fa-eye'],
                    'shortlisted' => ['bg' => 'bg-indigo-100', 'text' => 'text-indigo-800', 'icon' => 'fas fa-star'],
                    'interview' => ['bg' => 'bg-yellow-100', 'text' => 'text-yellow-800', 'icon' => 'fas fa-calendar-check'],
                    'offered' => ['bg' => 'bg-green-100', 'text' => 'text-green-800', 'icon' => 'fas fa-gift'],
                    'hired' => ['bg' => 'bg-emerald-100', 'text' => 'text-emerald-800', 'icon' => 'fas fa-check-circle'],
                    'rejected' => ['bg' => 'bg-red-100', 'text' => 'text-red-800', 'icon' => 'fas fa-times-circle']
                ];
                
                foreach ($status_counts as $status): 
                    $color = $status_colors[$status['status']] ?? ['bg' => 'bg-gray-100', 'text' => 'text-gray-800', 'icon' => 'fas fa-circle'];
                ?>
                    <div class="stat-card bg-white rounded-xl shadow-sm p-4 border-l-4 border-<?= str_replace('bg-', '', $color['bg']) ?>">
                        <div class="flex items-center justify-between mb-2">
                            <div class="<?= $color['bg'] ?> p-2 rounded-lg">
                                <i class="<?= $color['icon'] ?> <?= $color['text'] ?> text-sm"></i>
                            </div>
                            <span class="text-xs text-gray-500"><?= number_format($status['percentage'], 1) ?>%</span>
                        </div>
                        <p class="text-2xl font-bold text-gray-800"><?= $status['count'] ?></p>
                        <p class="text-xs text-gray-600 uppercase tracking-wider"><?= ucfirst($status['status']) ?></p>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Filters -->
            <div class="bg-white rounded-xl shadow-md p-6 mb-8">
                <form method="GET" action="" class="space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <!-- Search -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Search</label>
                            <div class="relative">
                                <input type="text" 
                                       name="search" 
                                       value="<?= htmlspecialchars($_GET['search'] ?? '') ?>"
                                       placeholder="Name, email, job title..."
                                       class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                                <i class="fas fa-search absolute left-3 top-3 text-gray-400"></i>
                            </div>
                        </div>
                        
                        <!-- Job Filter -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Job Position</label>
                            <select name="job_id" class="w-full border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                                <option value="">All Jobs</option>
                                <?php foreach ($employer_jobs as $job): ?>
                                    <option value="<?= $job['id'] ?>" <?= (isset($_GET['job_id']) && $_GET['job_id'] == $job['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($job['title']) ?> (<?= $job['total_applications'] ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <!-- Status Filter -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Status</label>
                            <select name="status" class="w-full border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                                <option value="all">All Statuses</option>
                                <option value="applied" <?= (isset($_GET['status']) && $_GET['status'] === 'applied') ? 'selected' : '' ?>>Applied</option>
                                <option value="reviewed" <?= (isset($_GET['status']) && $_GET['status'] === 'reviewed') ? 'selected' : '' ?>>Reviewed</option>
                                <option value="shortlisted" <?= (isset($_GET['status']) && $_GET['status'] === 'shortlisted') ? 'selected' : '' ?>>Shortlisted</option>
                                <option value="interview" <?= (isset($_GET['status']) && $_GET['status'] === 'interview') ? 'selected' : '' ?>>Interview</option>
                                <option value="offered" <?= (isset($_GET['status']) && $_GET['status'] === 'offered') ? 'selected' : '' ?>>Offered</option>
                                <option value="hired" <?= (isset($_GET['status']) && $_GET['status'] === 'hired') ? 'selected' : '' ?>>Hired</option>
                                <option value="rejected" <?= (isset($_GET['status']) && $_GET['status'] === 'rejected') ? 'selected' : '' ?>>Rejected</option>
                            </select>
                        </div>
                        
                        <!-- Match Score -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Min Match Score</label>
                            <select name="min_score" class="w-full border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                                <option value="">Any Score</option>
                                <option value="80" <?= (isset($_GET['min_score']) && $_GET['min_score'] == 80) ? 'selected' : '' ?>>80%+ (High)</option>
                                <option value="60" <?= (isset($_GET['min_score']) && $_GET['min_score'] == 60) ? 'selected' : '' ?>>60%+ (Medium)</option>
                                <option value="40" <?= (isset($_GET['min_score']) && $_GET['min_score'] == 40) ? 'selected' : '' ?>>40%+ (Low)</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <!-- Date Range -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Date From</label>
                            <input type="date" 
                                   name="date_from" 
                                   value="<?= htmlspecialchars($_GET['date_from'] ?? '') ?>"
                                   class="w-full border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Date To</label>
                            <input type="date" 
                                   name="date_to" 
                                   value="<?= htmlspecialchars($_GET['date_to'] ?? '') ?>"
                                   class="w-full border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Per Page</label>
                            <select name="per_page" class="w-full border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                                <option value="20" <?= (isset($_GET['per_page']) && $_GET['per_page'] == 20) ? 'selected' : '' ?>>20 per page</option>
                                <option value="50" <?= (isset($_GET['per_page']) && $_GET['per_page'] == 50) ? 'selected' : '' ?>>50 per page</option>
                                <option value="100" <?= (isset($_GET['per_page']) && $_GET['per_page'] == 100) ? 'selected' : '' ?>>100 per page</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="flex justify-end space-x-3">
                        <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
                            <i class="fas fa-filter mr-2"></i>Apply Filters
                        </button>
                    </div>
                </form>
            </div>

            <!-- Applications Table -->
            <div class="bg-white rounded-xl shadow-md overflow-hidden">
                <div class="p-6 border-b border-gray-200">
                    <div class="flex items-center justify-between">
                        <h2 class="text-lg font-semibold text-gray-800">All Applications</h2>
                        <div class="flex items-center space-x-2">
                            <span class="text-sm text-gray-600">Total: <?= number_format($total_applications) ?></span>
                            <span class="text-gray-400">|</span>
                            <span class="text-sm text-gray-600">Page <?= $page ?> of <?= $total_pages ?></span>
                        </div>
                    </div>
                </div>
                
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left">
                                    <input type="checkbox" onclick="toggleAll(this)" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Applicant</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Job Position</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Match</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Applied</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php if (empty($applications)): ?>
                                <tr>
                                    <td colspan="7" class="px-6 py-12 text-center text-gray-500">
                                        <i class="fas fa-inbox text-5xl mb-3 opacity-50"></i>
                                        <p class="text-lg">No applications found</p>
                                        <p class="text-sm mt-1">Try adjusting your filters or check back later</p>
                                    </td>
                                </tr>
                            <?php else: 
                                foreach ($applications as $app): 
                                    // Calculate match score class
                                    $match_score = floatval($app['match_score'] ?? 0);
                                    if ($match_score >= 80) {
                                        $match_class = 'match-score-high';
                                        $match_text = 'text-white';
                                    } elseif ($match_score >= 60) {
                                        $match_class = 'match-score-medium';
                                        $match_text = 'text-white';
                                    } elseif ($match_score >= 40) {
                                        $match_class = 'match-score-low';
                                        $match_text = 'text-white';
                                    } else {
                                        $match_class = 'bg-gray-200';
                                        $match_text = 'text-gray-700';
                                    }
                            ?>
                                <tr class="application-row" id="application-<?= $app['id'] ?>">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <input type="checkbox" 
                                               name="application_ids[]" 
                                               value="<?= $app['id'] ?>" 
                                               class="application-checkbox rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                                               onchange="updateSelection()">
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <?php if (!empty($app['profile_picture'])): ?>
                                                <img src="<?= htmlspecialchars($app['profile_picture']) ?>" 
                                                     alt="Profile" 
                                                     class="h-10 w-10 rounded-full object-cover">
                                            <?php else: ?>
                                                <div class="h-10 w-10 rounded-full bg-gradient-to-r from-blue-500 to-blue-600 flex items-center justify-center text-white font-bold">
                                                    <?= strtoupper(substr($app['first_name'] ?? 'U', 0, 1) . substr($app['last_name'] ?? 'S', 0, 1)) ?>
                                                </div>
                                            <?php endif; ?>
                                            <div class="ml-4">
                                                <div class="text-sm font-medium text-gray-900">
                                                    <a href="#" onclick="quickView(<?= $app['id'] ?>); return false;" class="hover:text-blue-600">
                                                        <?= htmlspecialchars($app['first_name'] . ' ' . $app['last_name']) ?>
                                                    </a>
                                                    <?php if ($app['is_saved'] > 0): ?>
                                                        <i class="fas fa-star text-yellow-400 ml-1 text-xs" title="Saved candidate"></i>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="text-sm text-gray-500">
                                                    <?= htmlspecialchars($app['current_job_title'] ?? 'Not specified') ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="text-sm font-medium text-gray-900">
                                            <?= htmlspecialchars($app['job_title']) ?>
                                        </div>
                                        <div class="text-xs text-gray-500 flex items-center mt-1">
                                            <i class="fas fa-map-marker-alt mr-1"></i>
                                            <?= htmlspecialchars($app['job_city'] ?? 'Remote') ?>
                                            <?php if ($app['job_location_type'] !== 'onsite'): ?>
                                                <span class="ml-2 px-1.5 py-0.5 bg-blue-100 text-blue-800 rounded-full text-xs">
                                                    <?= ucfirst($app['job_location_type']) ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="relative w-16 h-16">
                                            <svg class="w-16 h-16 transform -rotate-90">
                                                <circle cx="32" cy="32" r="28" stroke="#e5e7eb" stroke-width="4" fill="none"/>
                                                <circle cx="32" cy="32" r="28" 
                                                        stroke="<?= $match_score >= 80 ? '#10B981' : ($match_score >= 60 ? '#F59E0B' : ($match_score >= 40 ? '#EF4444' : '#9CA3AF')) ?>"
                                                        stroke-width="4" 
                                                        fill="none"
                                                        stroke-dasharray="<?= 2 * pi() * 28 ?>"
                                                        stroke-dashoffset="<?= 2 * pi() * 28 * (1 - $match_score/100) ?>"
                                                        stroke-linecap="round"/>
                                            </svg>
                                            <div class="absolute inset-0 flex items-center justify-center">
                                                <span class="text-sm font-bold <?= $match_score >= 40 ? 'text-gray-900' : 'text-gray-500' ?>">
                                                    <?= number_format($match_score, 0) ?>%
                                                </span>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900"><?= timeAgo($app['created_at']) ?></div>
                                        <div class="text-xs text-gray-500"><?= date('M d, Y', strtotime($app['created_at'])) ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <select onchange="updateSingleStatus(<?= $app['id'] ?>, this.value)" 
                                                class="status-badge text-sm rounded-full px-3 py-1 font-medium border-0 focus:ring-2 focus:ring-offset-2
                                                <?php
                                                $colors = [
                                                    'applied' => 'bg-blue-100 text-blue-800 focus:ring-blue-500',
                                                    'reviewed' => 'bg-purple-100 text-purple-800 focus:ring-purple-500',
                                                    'shortlisted' => 'bg-indigo-100 text-indigo-800 focus:ring-indigo-500',
                                                    'interview' => 'bg-yellow-100 text-yellow-800 focus:ring-yellow-500',
                                                    'offered' => 'bg-green-100 text-green-800 focus:ring-green-500',
                                                    'hired' => 'bg-emerald-100 text-emerald-800 focus:ring-emerald-500',
                                                    'rejected' => 'bg-red-100 text-red-800 focus:ring-red-500'
                                                ];
                                                echo $colors[$app['status']] ?? 'bg-gray-100 text-gray-800 focus:ring-gray-500';
                                                ?>">
                                            <option value="applied" <?= $app['status'] === 'applied' ? 'selected' : '' ?>>Applied</option>
                                            <option value="reviewed" <?= $app['status'] === 'reviewed' ? 'selected' : '' ?>>Reviewed</option>
                                            <option value="shortlisted" <?= $app['status'] === 'shortlisted' ? 'selected' : '' ?>>Shortlisted</option>
                                            <option value="interview" <?= $app['status'] === 'interview' ? 'selected' : '' ?>>Interview</option>
                                            <option value="offered" <?= $app['status'] === 'offered' ? 'selected' : '' ?>>Offered</option>
                                            <option value="hired" <?= $app['status'] === 'hired' ? 'selected' : '' ?>>Hired</option>
                                            <option value="rejected" <?= $app['status'] === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                                        </select>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                                        <div class="flex space-x-2">
                                            <a href="application-details.php?id=<?= $app['id'] ?>" 
                                               class="text-blue-600 hover:text-blue-900 transition p-2 hover:bg-blue-50 rounded-lg"
                                               title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="#" onclick="quickView(<?= $app['id'] ?>); return false;" 
                                               class="text-green-600 hover:text-green-900 transition p-2 hover:bg-green-50 rounded-lg"
                                               title="Quick View">
                                                <i class="fas fa-search"></i>
                                            </a>
                                            <a href="message.php?user=<?= urlencode($app['seeker_email']) ?>" 
                                               class="text-purple-600 hover:text-purple-900 transition p-2 hover:bg-purple-50 rounded-lg"
                                               title="Send Message">
                                                <i class="fas fa-comment"></i>
                                            </a>
                                            <?php if ($app['resume_url']): ?>
                                                <a href="<?= htmlspecialchars($app['resume_url']) ?>" target="_blank"
                                                   class="text-gray-600 hover:text-gray-900 transition p-2 hover:bg-gray-50 rounded-lg"
                                                   title="Download Resume">
                                                    <i class="fas fa-file-pdf"></i>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; 
                            endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <div class="px-6 py-4 bg-gray-50 border-t border-gray-200">
                    <div class="flex items-center justify-between">
                        <div class="text-sm text-gray-600">
                            Showing <?= $offset + 1 ?> to <?= min($offset + $per_page, $total_applications) ?> of <?= number_format($total_applications) ?> results
                        </div>
                        <div class="flex space-x-2">
                            <?php if ($page > 1): ?>
                                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" 
                                   class="px-3 py-1 bg-white border border-gray-300 rounded-md hover:bg-gray-50 transition">
                                    <i class="fas fa-chevron-left"></i>
                                </a>
                            <?php endif; ?>
                            
                            <?php
                            $start_page = max(1, $page - 2);
                            $end_page = min($total_pages, $page + 2);
                            
                            for ($i = $start_page; $i <= $end_page; $i++):
                            ?>
                                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>" 
                                   class="px-3 py-1 <?= $i === $page ? 'bg-blue-600 text-white' : 'bg-white text-gray-700 hover:bg-gray-50' ?> border border-gray-300 rounded-md transition">
                                    <?= $i ?>
                                </a>
                            <?php endfor; ?>
                            
                            <?php if ($page < $total_pages): ?>
                                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>" 
                                   class="px-3 py-1 bg-white border border-gray-300 rounded-md hover:bg-gray-50 transition">
                                    <i class="fas fa-chevron-right"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Recent Activity -->
            <?php if (!empty($recent_activity)): ?>
            <div class="mt-8 bg-white rounded-xl shadow-md p-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">Recent Activity</h3>
                <div class="flow-root">
                    <ul class="-mb-8">
                        <?php foreach ($recent_activity as $index => $activity): ?>
                        <li>
                            <div class="relative pb-8">
                                <?php if ($index < count($recent_activity) - 1): ?>
                                    <span class="absolute top-4 left-4 -ml-px h-full w-0.5 bg-gray-200" aria-hidden="true"></span>
                                <?php endif; ?>
                                <div class="relative flex space-x-3">
                                    <div>
                                        <span class="h-8 w-8 rounded-full bg-blue-100 flex items-center justify-center">
                                            <i class="fas fa-file-alt text-blue-600 text-sm"></i>
                                        </span>
                                    </div>
                                    <div class="min-w-0 flex-1 pt-1.5 flex justify-between space-x-4">
                                        <div>
                                            <p class="text-sm text-gray-600">
                                                New application from <span class="font-medium text-gray-900"><?= htmlspecialchars($activity['first_name'] . ' ' . $activity['last_name']) ?></span>
                                                for <span class="font-medium text-gray-900"><?= htmlspecialchars($activity['job_title']) ?></span>
                                            </p>
                                        </div>
                                        <div class="text-right text-sm whitespace-nowrap text-gray-500">
                                            <span class="px-2 py-1 <?= $colors[$activity['status']]['bg'] ?? 'bg-gray-100' ?> <?= $colors[$activity['status']]['text'] ?? 'text-gray-800' ?> rounded-full text-xs">
                                                <?= ucfirst($activity['status']) ?>
                                            </span>
                                            <time datetime="<?= $activity['date'] ?>" class="ml-2"><?= timeAgo($activity['date']) ?></time>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- JavaScript Libraries -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/momentjs/latest/moment.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.min.js"></script>
    
    <script>
        // Initialize Select2 for better dropdowns
        $(document).ready(function() {
            $('select').not('.status-badge').select2({
                minimumResultsForSearch: 10
            });
        });

        // Toggle dropdowns
        document.addEventListener('DOMContentLoaded', function() {
            // Notification dropdown
            const notificationBtn = document.getElementById('notificationBtn');
            const notificationDropdown = document.getElementById('notificationDropdown');
            
            if (notificationBtn) {
                notificationBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    notificationDropdown.classList.toggle('hidden');
                });
            }
            
            // User menu dropdown
            const userMenuBtn = document.getElementById('userMenuBtn');
            const userDropdown = document.getElementById('userDropdown');
            
            if (userMenuBtn) {
                userMenuBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    userDropdown.classList.toggle('hidden');
                });
            }
            
            // Close dropdowns when clicking outside
            document.addEventListener('click', function() {
                if (notificationDropdown) notificationDropdown.classList.add('hidden');
                if (userDropdown) userDropdown.classList.add('hidden');
            });
        });

        // Selection management
        let selectedApplications = new Set();

        function toggleAll(checkbox) {
            const checkboxes = document.querySelectorAll('.application-checkbox');
            checkboxes.forEach(cb => {
                cb.checked = checkbox.checked;
                if (checkbox.checked) {
                    selectedApplications.add(cb.value);
                } else {
                    selectedApplications.delete(cb.value);
                }
            });
            updateSelection();
        }

        function updateSelection() {
            const checkboxes = document.querySelectorAll('.application-checkbox');
            checkboxes.forEach(cb => {
                if (cb.checked) {
                    selectedApplications.add(cb.value);
                } else {
                    selectedApplications.delete(cb.value);
                }
            });
            
            const count = selectedApplications.size;
            document.getElementById('selectedCount').textContent = count;
            
            // Show/hide bulk actions bar
            const bulkBar = document.getElementById('bulkActionsBar');
            if (count > 0) {
                bulkBar.classList.remove('translate-y-full');
            } else {
                bulkBar.classList.add('translate-y-full');
            }
            
            // Update select all checkbox
            const selectAllCheckbox = document.querySelector('input[onclick="toggleAll(this)"]');
            if (selectAllCheckbox) {
                const allCheckboxes = document.querySelectorAll('.application-checkbox');
                selectAllCheckbox.checked = allCheckboxes.length === count && count > 0;
                selectAllCheckbox.indeterminate = count > 0 && count < allCheckboxes.length;
            }
        }

        function selectAll() {
            const checkboxes = document.querySelectorAll('.application-checkbox');
            checkboxes.forEach(cb => {
                cb.checked = true;
                selectedApplications.add(cb.value);
            });
            updateSelection();
        }

        function clearSelection() {
            const checkboxes = document.querySelectorAll('.application-checkbox');
            checkboxes.forEach(cb => {
                cb.checked = false;
            });
            selectedApplications.clear();
            updateSelection();
        }

        // Bulk actions
        function bulkUpdateStatus() {
            const status = document.getElementById('bulkStatusSelect').value;
            if (!status) {
                alert('Please select a status');
                return;
            }
            
            if (selectedApplications.size === 0) {
                alert('No applications selected');
                return;
            }
            
            if (!confirm(`Are you sure you want to update ${selectedApplications.size} application(s) to ${status}?`)) {
                return;
            }
            
            performBulkAction('update_status', { new_status: status });
        }

        function bulkSaveCandidates() {
            if (selectedApplications.size === 0) {
                alert('No applications selected');
                return;
            }
            
            performBulkAction('mark_favorite');
        }

        function bulkExport() {
            if (selectedApplications.size === 0) {
                alert('No applications selected');
                return;
            }
            
            performBulkAction('export');
        }

        function performBulkAction(action, extraData = {}) {
            const formData = new FormData();
            formData.append('bulk_action', action);
            
            selectedApplications.forEach(id => {
                formData.append('application_ids[]', id);
            });
            
            Object.keys(extraData).forEach(key => {
                formData.append(key, extraData[key]);
            });
            
            // Show loading
            const btn = event?.target;
            if (btn) {
                const originalText = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Processing...';
                btn.disabled = true;
            }
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (action === 'export') {
                    return response.blob();
                }
                return response.json();
            })
            .then(data => {
                if (action === 'export') {
                    // Handle file download
                    const url = window.URL.createObjectURL(data);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = 'applications_export.csv';
                    document.body.appendChild(a);
                    a.click();
                    window.URL.revokeObjectURL(url);
                    a.remove();
                    
                    showMessage('Export started successfully', 'success');
                } else if (data.success) {
                    showMessage(data.message, 'success');
                    setTimeout(() => window.location.reload(), 1500);
                } else {
                    showMessage(data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showMessage('An error occurred', 'error');
            })
            .finally(() => {
                if (btn) {
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                }
            });
        }

        // Single status update
        function updateSingleStatus(applicationId, status) {
            const formData = new FormData();
            formData.append('action', 'update_single_status');
            formData.append('application_id', applicationId);
            formData.append('status', status);
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showMessage(data.message, 'success');
                } else {
                    showMessage(data.message, 'error');
                    // Revert select
                    location.reload();
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showMessage('An error occurred', 'error');
            });
        }

        // Quick view modal
        function quickView(applicationId) {
            const modal = document.getElementById('quickViewModal');
            const content = document.getElementById('quickViewContent');
            
            modal.classList.add('active');
            
            // Load application details via AJAX
            fetch(`ajax/get-application-details.php?id=${applicationId}`)
                .then(response => response.text())
                .then(html => {
                    content.innerHTML = html;
                })
                .catch(error => {
                    console.error('Error:', error);
                    content.innerHTML = '<div class="text-center text-red-600 p-8">Error loading application details</div>';
                });
        }

        function closeModal() {
            document.getElementById('quickViewModal').classList.remove('active');
        }

        // Close modal when clicking outside
        document.addEventListener('click', function(e) {
            const modal = document.getElementById('quickViewModal');
            if (e.target === modal) {
                closeModal();
            }
        });

        // Close modal with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal();
            }
        });

        // Show message
        function showMessage(message, type = 'success') {
            const flashDiv = document.createElement('div');
            flashDiv.className = `fixed top-20 right-4 z-50 ${type === 'success' ? 'bg-green-500' : 'bg-red-500'} text-white px-6 py-3 rounded-xl shadow-lg animate-slideIn`;
            flashDiv.innerHTML = `<i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'} mr-2"></i>${message}`;
            document.body.appendChild(flashDiv);
            
            setTimeout(() => {
                flashDiv.remove();
            }, 3000);
        }

        // Export current view
        function exportCurrentView() {
            const url = new URL(window.location.href);
            url.searchParams.set('export', 'csv');
            window.location.href = url.toString();
        }

        // Reset filters
        function resetFilters() {
            window.location.href = 'applications.php';
        }
    </script>
</body>
</html>