<?php
// employer/manage-jobs.php
// Professional Job Management Page with PDO implementation

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
$employer = fetchOne("SELECT company_name, company_logo FROM employers WHERE id = ?", [$employer_id]);

// Pagination and filtering
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;

// Filters
$status_filter = isset($_GET['status']) ? sanitize($_GET['status']) : '';
$search_term = isset($_GET['search']) ? sanitize($_GET['search']) : '';
$category_filter = isset($_GET['category']) ? (int)$_GET['category'] : 0;
$sort_by = isset($_GET['sort']) ? sanitize($_GET['sort']) : 'created_at';
$sort_order = isset($_GET['order']) && strtoupper($_GET['order']) === 'ASC' ? 'ASC' : 'DESC';

// Build query conditions
$conditions = ["j.employer_id = :employer_id"];
$params = [':employer_id' => $employer_id];

if (!empty($status_filter)) {
    $conditions[] = "j.status = :status";
    $params[':status'] = $status_filter;
}

if (!empty($search_term)) {
    $conditions[] = "(j.title LIKE :search OR j.description LIKE :search OR j.city LIKE :search)";
    $params[':search'] = "%$search_term%";
}

if ($category_filter > 0) {
    $conditions[] = "j.category_id = :category";
    $params[':category'] = $category_filter;
}

$where_clause = implode(' AND ', $conditions);

// Validate sort column (prevent SQL injection)
$allowed_sort_columns = ['title', 'created_at', 'status', 'application_deadline', 'total_applications', 'total_views'];
if (!in_array($sort_by, $allowed_sort_columns)) {
    $sort_by = 'created_at';
}

// Get total jobs count
$count_query = "SELECT COUNT(*) as total 
                FROM jobs j 
                WHERE $where_clause";
$total_jobs = fetchOne($count_query, $params)['total'] ?? 0;
$total_pages = ceil($total_jobs / $per_page);

// Get jobs with pagination
$jobs_query = "SELECT j.*, 
                      jc.name as category_name,
                      jt.name as job_type_name,
                      (SELECT COUNT(*) FROM applications WHERE job_id = j.id) as application_count,
                      (SELECT COUNT(*) FROM job_views WHERE job_id = j.id) as view_count
               FROM jobs j
               LEFT JOIN job_categories jc ON j.category_id = jc.id
               LEFT JOIN job_types jt ON j.job_type_id = jt.id
               WHERE $where_clause
               ORDER BY j.$sort_by $sort_order
               LIMIT :offset, :per_page";

$stmt = $pdo->prepare($jobs_query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->bindValue(':per_page', $per_page, PDO::PARAM_INT);
$stmt->execute();
$jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get job categories for filter
$categories = fetchAll("SELECT id, name FROM job_categories WHERE is_active = 1 ORDER BY name");

// Get status counts for filter badges
$status_counts = fetchAll("SELECT status, COUNT(*) as count 
                           FROM jobs 
                           WHERE employer_id = ? 
                           GROUP BY status", [$employer_id]);

$status_counts_assoc = [];
foreach ($status_counts as $sc) {
    $status_counts_assoc[$sc['status']] = $sc['count'];
}

// Handle AJAX requests for job actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    $response = ['success' => false, 'message' => 'Invalid action'];
    
    // Toggle job status (active/paused)
    if ($_POST['action'] === 'toggle_status' && isset($_POST['job_id'])) {
        $job_id = (int)$_POST['job_id'];
        
        // Verify job belongs to employer
        $job = fetchOne("SELECT id, status FROM jobs WHERE id = ? AND employer_id = ?", 
                       [$job_id, $employer_id]);
        
        if ($job) {
            $new_status = $job['status'] === 'active' ? 'paused' : 'active';
            $updated = executeQuery("UPDATE jobs SET status = ? WHERE id = ?", 
                                   [$new_status, $job_id]);
            
            if ($updated) {
                logUserActivity('job_status_toggle', "Job #{$job_id} status changed to {$new_status}", $pdo);
                $response = ['success' => true, 'message' => 'Job status updated', 'new_status' => $new_status];
            } else {
                $response = ['success' => false, 'message' => 'Failed to update job status'];
            }
        } else {
            $response = ['success' => false, 'message' => 'Job not found'];
        }
    }
    
    // Toggle featured status
    if ($_POST['action'] === 'toggle_featured' && isset($_POST['job_id'])) {
        $job_id = (int)$_POST['job_id'];
        
        $job = fetchOne("SELECT id, is_featured FROM jobs WHERE id = ? AND employer_id = ?", 
                       [$job_id, $employer_id]);
        
        if ($job) {
            $new_featured = $job['is_featured'] ? 0 : 1;
            $updated = executeQuery("UPDATE jobs SET is_featured = ? WHERE id = ?", 
                                   [$new_featured, $job_id]);
            
            if ($updated) {
                logUserActivity('job_featured_toggle', "Job #{$job_id} featured status changed to {$new_featured}", $pdo);
                $response = ['success' => true, 'message' => 'Featured status updated', 'new_featured' => $new_featured];
            } else {
                $response = ['success' => false, 'message' => 'Failed to update featured status'];
            }
        } else {
            $response = ['success' => false, 'message' => 'Job not found'];
        }
    }
    
    // Delete job
    if ($_POST['action'] === 'delete_job' && isset($_POST['job_id'])) {
        $job_id = (int)$_POST['job_id'];
        
        // Verify job belongs to employer
        $job = fetchOne("SELECT id, title FROM jobs WHERE id = ? AND employer_id = ?", 
                       [$job_id, $employer_id]);
        
        if ($job) {
            // Check if there are applications
            $app_count = fetchOne("SELECT COUNT(*) as count FROM applications WHERE job_id = ?", [$job_id])['count'];
            
            if ($app_count > 0) {
                // Soft delete or just close if has applications
                executeQuery("UPDATE jobs SET status = 'closed' WHERE id = ?", [$job_id]);
                $response = ['success' => true, 'message' => 'Job closed successfully (had applications)'];
            } else {
                // Hard delete if no applications
                executeQuery("DELETE FROM job_skills WHERE job_id = ?", [$job_id]);
                executeQuery("DELETE FROM jobs WHERE id = ?", [$job_id]);
                $response = ['success' => true, 'message' => 'Job deleted successfully'];
            }
            
            logUserActivity('job_delete', "Job #{$job_id} deleted/closed", $pdo);
        } else {
            $response = ['success' => false, 'message' => 'Job not found'];
        }
    }
    
    // Duplicate job
    if ($_POST['action'] === 'duplicate_job' && isset($_POST['job_id'])) {
        $job_id = (int)$_POST['job_id'];
        
        // Get original job
        $original = fetchOne("SELECT * FROM jobs WHERE id = ? AND employer_id = ?", 
                            [$job_id, $employer_id]);
        
        if ($original) {
            try {
                $pdo->beginTransaction();
                
                // Create new job with draft status
                $slug = createUniqueSlug($original['title'] . '-copy', 'jobs');
                
                $insert_query = "INSERT INTO jobs (
                    employer_id, title, slug, description, requirements, responsibilities,
                    benefits, category_id, job_type_id, experience_level, salary_min, salary_max,
                    salary_currency, salary_type, is_salary_negotiable, is_salary_visible,
                    location_type, address, city, state, country, application_deadline,
                    total_vacancies, status, created_at
                ) VALUES (
                    :employer_id, :title, :slug, :description, :requirements, :responsibilities,
                    :benefits, :category_id, :job_type_id, :experience_level, :salary_min, :salary_max,
                    :salary_currency, :salary_type, :is_salary_negotiable, :is_salary_visible,
                    :location_type, :address, :city, :state, :country, :application_deadline,
                    :total_vacancies, 'draft', NOW()
                )";
                
                $stmt = $pdo->prepare($insert_query);
                $stmt->execute([
                    ':employer_id' => $employer_id,
                    ':title' => $original['title'] . ' (Copy)',
                    ':slug' => $slug,
                    ':description' => $original['description'],
                    ':requirements' => $original['requirements'],
                    ':responsibilities' => $original['responsibilities'],
                    ':benefits' => $original['benefits'],
                    ':category_id' => $original['category_id'],
                    ':job_type_id' => $original['job_type_id'],
                    ':experience_level' => $original['experience_level'],
                    ':salary_min' => $original['salary_min'],
                    ':salary_max' => $original['salary_max'],
                    ':salary_currency' => $original['salary_currency'],
                    ':salary_type' => $original['salary_type'],
                    ':is_salary_negotiable' => $original['is_salary_negotiable'],
                    ':is_salary_visible' => $original['is_salary_visible'],
                    ':location_type' => $original['location_type'],
                    ':address' => $original['address'],
                    ':city' => $original['city'],
                    ':state' => $original['state'],
                    ':country' => $original['country'],
                    ':application_deadline' => $original['application_deadline'],
                    ':total_vacancies' => $original['total_vacancies']
                ]);
                
                $new_job_id = $pdo->lastInsertId();
                
                // Copy job skills
                $skills = fetchAll("SELECT skill_name, is_required FROM job_skills WHERE job_id = ?", [$job_id]);
                foreach ($skills as $skill) {
                    executeQuery("INSERT INTO job_skills (job_id, skill_name, is_required) VALUES (?, ?, ?)",
                               [$new_job_id, $skill['skill_name'], $skill['is_required']]);
                }
                
                $pdo->commit();
                
                logUserActivity('job_duplicate', "Job #{$job_id} duplicated as #{$new_job_id}", $pdo);
                $response = ['success' => true, 'message' => 'Job duplicated successfully', 'new_job_id' => $new_job_id];
                
            } catch (Exception $e) {
                $pdo->rollBack();
                $response = ['success' => false, 'message' => 'Error duplicating job: ' . $e->getMessage()];
            }
        } else {
            $response = ['success' => false, 'message' => 'Original job not found'];
        }
    }
    
    echo json_encode($response);
    exit();
}

// Get unread notifications count
$unread_notifications = fetchOne("SELECT COUNT(*) as count 
                                  FROM notifications 
                                  WHERE user_id = ? AND channel = 'inapp' AND status = 'sent'", 
                                  [$_SESSION['user_id']])['count'] ?? 0;

// Page title
$page_title = "Manage Jobs";
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
    
    <!-- Custom Styles -->
    <style>
        .job-card {
            transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
        }
        .job-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }
        .filter-badge {
            transition: all 0.2s ease;
        }
        .filter-badge:hover {
            transform: scale(1.05);
        }
        .action-btn {
            transition: all 0.2s ease;
        }
        .action-btn:hover {
            transform: translateY(-1px);
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
        
        /* Confirmation Modal */
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
            max-width: 400px;
            width: 90%;
        }
    </style>
</head>
<body class="bg-gray-50">
    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal-overlay">
        <div class="modal-content bg-white rounded-xl shadow-2xl p-6">
            <div class="text-center">
                <div class="w-16 h-16 mx-auto bg-red-100 rounded-full flex items-center justify-center mb-4">
                    <i class="fas fa-exclamation-triangle text-red-600 text-2xl"></i>
                </div>
                <h3 class="text-xl font-bold text-gray-900 mb-2">Delete Job</h3>
                <p class="text-gray-600 mb-2" id="deleteJobTitle"></p>
                <p class="text-sm text-gray-500 mb-6">This action cannot be undone. Jobs with applications will be closed instead.</p>
                <div class="flex space-x-3">
                    <button onclick="closeDeleteModal()" 
                            class="flex-1 px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition font-medium">
                        <i class="fas fa-times mr-2"></i>Cancel
                    </button>
                    <button onclick="confirmDelete()" 
                            class="flex-1 px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition font-medium">
                        <i class="fas fa-trash mr-2"></i>Delete
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Navigation (same as dashboard.php) -->
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
                        <a href="manage-jobs.php" class="text-blue-600 border-b-2 border-blue-600 px-3 py-2 text-sm font-medium">Manage Jobs</a>
                        <a href="post-job.php" class="text-gray-600 hover:text-blue-600 px-3 py-2 text-sm font-medium transition">Post Job</a>
                        <a href="applications.php" class="text-gray-600 hover:text-blue-600 px-3 py-2 text-sm font-medium transition">Applications</a>
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
                <div class="flex flex-col md:flex-row md:items-center md:justify-between">
                    <div>
                        <h1 class="text-3xl font-bold text-gray-900">Manage Jobs</h1>
                        <p class="mt-2 text-sm text-gray-600">
                            View and manage all your job postings
                        </p>
                    </div>
                    <div class="mt-4 md:mt-0">
                        <a href="post-job.php" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
                            <i class="fas fa-plus-circle mr-2"></i>
                            Post New Job
                        </a>
                    </div>
                </div>
            </div>

            <!-- Stats Summary -->
            <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
                <a href="?status=active" class="filter-badge bg-white rounded-lg shadow-sm p-4 hover:shadow-md transition <?= $status_filter === 'active' ? 'ring-2 ring-blue-500' : '' ?>">
                    <p class="text-sm text-gray-600">Active</p>
                    <p class="text-2xl font-bold text-gray-900"><?= $status_counts_assoc['active'] ?? 0 ?></p>
                </a>
                <a href="?status=draft" class="filter-badge bg-white rounded-lg shadow-sm p-4 hover:shadow-md transition <?= $status_filter === 'draft' ? 'ring-2 ring-blue-500' : '' ?>">
                    <p class="text-sm text-gray-600">Draft</p>
                    <p class="text-2xl font-bold text-gray-900"><?= $status_counts_assoc['draft'] ?? 0 ?></p>
                </a>
                <a href="?status=paused" class="filter-badge bg-white rounded-lg shadow-sm p-4 hover:shadow-md transition <?= $status_filter === 'paused' ? 'ring-2 ring-blue-500' : '' ?>">
                    <p class="text-sm text-gray-600">Paused</p>
                    <p class="text-2xl font-bold text-gray-900"><?= $status_counts_assoc['paused'] ?? 0 ?></p>
                </a>
                <a href="?status=closed" class="filter-badge bg-white rounded-lg shadow-sm p-4 hover:shadow-md transition <?= $status_filter === 'closed' ? 'ring-2 ring-blue-500' : '' ?>">
                    <p class="text-sm text-gray-600">Closed</p>
                    <p class="text-2xl font-bold text-gray-900"><?= $status_counts_assoc['closed'] ?? 0 ?></p>
                </a>
                <a href="?status=expired" class="filter-badge bg-white rounded-lg shadow-sm p-4 hover:shadow-md transition <?= $status_filter === 'expired' ? 'ring-2 ring-blue-500' : '' ?>">
                    <p class="text-sm text-gray-600">Expired</p>
                    <p class="text-2xl font-bold text-gray-900"><?= $status_counts_assoc['expired'] ?? 0 ?></p>
                </a>
            </div>

            <!-- Filters and Search -->
            <div class="bg-white rounded-xl shadow-md p-6 mb-6">
                <form method="GET" class="space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <!-- Search -->
                        <div class="col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Search Jobs</label>
                            <div class="relative">
                                <input type="text" 
                                       name="search" 
                                       value="<?= htmlspecialchars($search_term) ?>"
                                       placeholder="Search by title, description, or location..."
                                       class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                <i class="fas fa-search absolute left-3 top-3 text-gray-400"></i>
                            </div>
                        </div>

                        <!-- Status Filter -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                            <select name="status" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                <option value="">All Status</option>
                                <option value="active" <?= $status_filter === 'active' ? 'selected' : '' ?>>Active</option>
                                <option value="draft" <?= $status_filter === 'draft' ? 'selected' : '' ?>>Draft</option>
                                <option value="paused" <?= $status_filter === 'paused' ? 'selected' : '' ?>>Paused</option>
                                <option value="closed" <?= $status_filter === 'closed' ? 'selected' : '' ?>>Closed</option>
                                <option value="expired" <?= $status_filter === 'expired' ? 'selected' : '' ?>>Expired</option>
                            </select>
                        </div>

                        <!-- Category Filter -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Category</label>
                            <select name="category" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                <option value="0">All Categories</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?= $cat['id'] ?>" <?= $category_filter == $cat['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($cat['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="flex justify-between items-center">
                        <div class="flex items-center space-x-2">
                            <label class="text-sm text-gray-600">Sort by:</label>
                            <select name="sort" class="text-sm border border-gray-300 rounded-lg px-2 py-1">
                                <option value="created_at" <?= $sort_by === 'created_at' ? 'selected' : '' ?>>Date Posted</option>
                                <option value="title" <?= $sort_by === 'title' ? 'selected' : '' ?>>Title</option>
                                <option value="status" <?= $sort_by === 'status' ? 'selected' : '' ?>>Status</option>
                                <option value="application_deadline" <?= $sort_by === 'application_deadline' ? 'selected' : '' ?>>Deadline</option>
                                <option value="total_applications" <?= $sort_by === 'total_applications' ? 'selected' : '' ?>>Applications</option>
                                <option value="total_views" <?= $sort_by === 'total_views' ? 'selected' : '' ?>>Views</option>
                            </select>
                            <select name="order" class="text-sm border border-gray-300 rounded-lg px-2 py-1">
                                <option value="DESC" <?= $sort_order === 'DESC' ? 'selected' : '' ?>>Descending</option>
                                <option value="ASC" <?= $sort_order === 'ASC' ? 'selected' : '' ?>>Ascending</option>
                            </select>
                        </div>

                        <div class="flex space-x-2">
                            <a href="manage-jobs.php" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition">
                                <i class="fas fa-times mr-2"></i>Clear Filters
                            </a>
                            <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
                                <i class="fas fa-filter mr-2"></i>Apply Filters
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Jobs List -->
            <div class="bg-white rounded-xl shadow-md overflow-hidden">
                <?php if (empty($jobs)): ?>
                    <div class="text-center py-12">
                        <div class="inline-flex items-center justify-center w-20 h-20 bg-gray-100 rounded-full mb-4">
                            <i class="fas fa-briefcase text-gray-400 text-3xl"></i>
                        </div>
                        <h3 class="text-lg font-medium text-gray-900 mb-2">No jobs found</h3>
                        <p class="text-gray-500 mb-6">Get started by posting your first job opening</p>
                        <a href="post-job.php" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
                            <i class="fas fa-plus-circle mr-2"></i>
                            Post a Job
                        </a>
                    </div>
                <?php else: ?>
                    <!-- Table Header (Desktop) -->
                    <div class="hidden md:grid grid-cols-12 gap-4 p-4 bg-gray-50 border-b border-gray-200 text-sm font-medium text-gray-600">
                        <div class="col-span-4">Job Details</div>
                        <div class="col-span-2">Category</div>
                        <div class="col-span-1 text-center">Status</div>
                        <div class="col-span-1 text-center">Applications</div>
                        <div class="col-span-1 text-center">Views</div>
                        <div class="col-span-1 text-center">Posted</div>
                        <div class="col-span-2 text-right">Actions</div>
                    </div>

                    <!-- Jobs -->
                    <?php foreach ($jobs as $job): ?>
                        <div class="job-card border-b border-gray-200 last:border-b-0">
                            <!-- Desktop View -->
                            <div class="hidden md:grid grid-cols-12 gap-4 p-4 items-center">
                                <div class="col-span-4">
                                    <div>
                                        <a href="view-job.php?id=<?= $job['id'] ?>" class="font-medium text-gray-900 hover:text-blue-600 transition">
                                            <?= htmlspecialchars($job['title']) ?>
                                        </a>
                                        <div class="flex items-center text-sm text-gray-500 mt-1">
                                            <i class="fas fa-map-marker-alt mr-1 text-xs"></i>
                                            <?= htmlspecialchars($job['city'] ?? $job['location_type'] ?? 'Remote') ?>
                                            <?php if ($job['is_featured']): ?>
                                                <span class="ml-2 px-2 py-0.5 bg-yellow-100 text-yellow-800 text-xs rounded-full">
                                                    <i class="fas fa-star mr-1"></i>Featured
                                                </span>
                                            <?php endif; ?>
                                            <?php if ($job['is_urgent']): ?>
                                                <span class="ml-2 px-2 py-0.5 bg-red-100 text-red-800 text-xs rounded-full">
                                                    Urgent
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-span-2">
                                    <span class="text-sm text-gray-600"><?= htmlspecialchars($job['category_name'] ?? 'Uncategorized') ?></span>
                                </div>
                                <div class="col-span-1 text-center">
                                    <span class="px-2 py-1 text-xs rounded-full 
                                        <?php
                                        $status_colors = [
                                            'active' => 'bg-green-100 text-green-800',
                                            'draft' => 'bg-gray-100 text-gray-800',
                                            'paused' => 'bg-yellow-100 text-yellow-800',
                                            'closed' => 'bg-red-100 text-red-800',
                                            'expired' => 'bg-orange-100 text-orange-800'
                                        ];
                                        echo $status_colors[$job['status']] ?? 'bg-gray-100 text-gray-800';
                                        ?>">
                                        <?= ucfirst($job['status']) ?>
                                    </span>
                                </div>
                                <div class="col-span-1 text-center">
                                    <a href="applications.php?job_id=<?= $job['id'] ?>" class="text-sm text-blue-600 hover:text-blue-800">
                                        <?= number_format($job['application_count']) ?>
                                    </a>
                                </div>
                                <div class="col-span-1 text-center text-sm text-gray-600">
                                    <?= number_format($job['view_count']) ?>
                                </div>
                                <div class="col-span-1 text-center">
                                    <span class="text-sm text-gray-500" title="<?= date('Y-m-d H:i', strtotime($job['created_at'])) ?>">
                                        <?= timeAgo($job['created_at']) ?>
                                    </span>
                                </div>
                                <div class="col-span-2 text-right space-x-2">
                                    <button onclick="toggleStatus(<?= $job['id'] ?>, '<?= $job['status'] ?>')" 
                                            class="action-btn text-<?= $job['status'] === 'active' ? 'yellow' : 'green' ?>-600 hover:text-<?= $job['status'] === 'active' ? 'yellow' : 'green' ?>-800"
                                            title="<?= $job['status'] === 'active' ? 'Pause' : 'Activate' ?>">
                                        <i class="fas fa-<?= $job['status'] === 'active' ? 'pause' : 'play' ?>"></i>
                                    </button>
                                    <a href="edit-job.php?id=<?= $job['id'] ?>" class="action-btn text-blue-600 hover:text-blue-800" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <a href="view-job.php?id=<?= $job['id'] ?>" class="action-btn text-purple-600 hover:text-purple-800" title="View">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="applications.php?job_id=<?= $job['id'] ?>" class="action-btn text-indigo-600 hover:text-indigo-800" title="Applications">
                                        <i class="fas fa-users"></i>
                                    </a>
                                    <button onclick="duplicateJob(<?= $job['id'] ?>)" class="action-btn text-green-600 hover:text-green-800" title="Duplicate">
                                        <i class="fas fa-copy"></i>
                                    </button>
                                    <?php if ($job['is_featured']): ?>
                                        <button onclick="toggleFeatured(<?= $job['id'] ?>, 0)" class="action-btn text-yellow-600 hover:text-yellow-800" title="Remove Featured">
                                            <i class="fas fa-star"></i>
                                        </button>
                                    <?php else: ?>
                                        <button onclick="toggleFeatured(<?= $job['id'] ?>, 1)" class="action-btn text-gray-400 hover:text-yellow-600" title="Mark as Featured">
                                            <i class="fas fa-star"></i>
                                        </button>
                                    <?php endif; ?>
                                    <button onclick="showDeleteModal(<?= $job['id'] ?>, '<?= htmlspecialchars(addslashes($job['title'])) ?>')" 
                                            class="action-btn text-red-600 hover:text-red-800" title="Delete">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </div>

                            <!-- Mobile View -->
                            <div class="md:hidden p-4">
                                <div class="flex justify-between items-start mb-2">
                                    <div>
                                        <a href="view-job.php?id=<?= $job['id'] ?>" class="font-medium text-gray-900 hover:text-blue-600">
                                            <?= htmlspecialchars($job['title']) ?>
                                        </a>
                                        <div class="flex items-center text-sm text-gray-500 mt-1">
                                            <i class="fas fa-map-marker-alt mr-1 text-xs"></i>
                                            <?= htmlspecialchars($job['city'] ?? $job['location_type'] ?? 'Remote') ?>
                                        </div>
                                    </div>
                                    <span class="px-2 py-1 text-xs rounded-full 
                                        <?= $status_colors[$job['status']] ?? 'bg-gray-100 text-gray-800' ?>">
                                        <?= ucfirst($job['status']) ?>
                                    </span>
                                </div>
                                
                                <div class="grid grid-cols-3 gap-2 text-sm mb-3">
                                    <div>
                                        <span class="text-gray-500">Category:</span>
                                        <span class="text-gray-900"><?= htmlspecialchars($job['category_name'] ?? 'Uncategorized') ?></span>
                                    </div>
                                    <div>
                                        <span class="text-gray-500">Apps:</span>
                                        <a href="applications.php?job_id=<?= $job['id'] ?>" class="text-blue-600"><?= $job['application_count'] ?></a>
                                    </div>
                                    <div>
                                        <span class="text-gray-500">Views:</span>
                                        <span><?= $job['view_count'] ?></span>
                                    </div>
                                </div>

                                <div class="flex items-center justify-between">
                                    <span class="text-xs text-gray-400"><?= timeAgo($job['created_at']) ?></span>
                                    <div class="space-x-2">
                                        <button onclick="toggleStatus(<?= $job['id'] ?>, '<?= $job['status'] ?>')" 
                                                class="text-<?= $job['status'] === 'active' ? 'yellow' : 'green' ?>-600">
                                            <i class="fas fa-<?= $job['status'] === 'active' ? 'pause' : 'play' ?>"></i>
                                        </button>
                                        <a href="edit-job.php?id=<?= $job['id'] ?>" class="text-blue-600">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="applications.php?job_id=<?= $job['id'] ?>" class="text-indigo-600">
                                            <i class="fas fa-users"></i>
                                        </a>
                                        <button onclick="showDeleteModal(<?= $job['id'] ?>, '<?= htmlspecialchars(addslashes($job['title'])) ?>')" 
                                                class="text-red-600">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                        <div class="px-4 py-3 bg-gray-50 border-t border-gray-200">
                            <div class="flex items-center justify-between">
                                <div class="text-sm text-gray-600">
                                    Showing <?= $offset + 1 ?> to <?= min($offset + $per_page, $total_jobs) ?> of <?= $total_jobs ?> jobs
                                </div>
                                <div class="flex space-x-2">
                                    <?php if ($page > 1): ?>
                                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" 
                                           class="px-3 py-1 bg-white border border-gray-300 rounded-lg text-gray-600 hover:bg-gray-50">
                                            Previous
                                        </a>
                                    <?php endif; ?>
                                    
                                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                        <?php if ($i == $page): ?>
                                            <span class="px-3 py-1 bg-blue-600 text-white rounded-lg"><?= $i ?></span>
                                        <?php elseif ($i >= $page - 2 && $i <= $page + 2): ?>
                                            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>" 
                                               class="px-3 py-1 bg-white border border-gray-300 rounded-lg text-gray-600 hover:bg-gray-50">
                                                <?= $i ?>
                                            </a>
                                        <?php endif; ?>
                                    <?php endfor; ?>
                                    
                                    <?php if ($page < $total_pages): ?>
                                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>" 
                                           class="px-3 py-1 bg-white border border-gray-300 rounded-lg text-gray-600 hover:bg-gray-50">
                                            Next
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script>
        // Toggle dropdowns (same as dashboard)
        document.addEventListener('DOMContentLoaded', function() {
            const notificationBtn = document.getElementById('notificationBtn');
            const notificationDropdown = document.getElementById('notificationDropdown');
            const userMenuBtn = document.getElementById('userMenuBtn');
            const userDropdown = document.getElementById('userDropdown');
            
            if (notificationBtn) {
                notificationBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    notificationDropdown.classList.toggle('hidden');
                });
            }
            
            if (userMenuBtn) {
                userMenuBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    userDropdown.classList.toggle('hidden');
                });
            }
            
            document.addEventListener('click', function() {
                if (notificationDropdown) notificationDropdown.classList.add('hidden');
                if (userDropdown) userDropdown.classList.add('hidden');
            });
        });

        // Variables for delete modal
        let currentJobId = null;
        
        function showDeleteModal(jobId, jobTitle) {
            currentJobId = jobId;
            document.getElementById('deleteJobTitle').innerHTML = `Are you sure you want to delete "<strong>${jobTitle}</strong>"?`;
            document.getElementById('deleteModal').classList.add('active');
        }
        
        function closeDeleteModal() {
            document.getElementById('deleteModal').classList.remove('active');
            currentJobId = null;
        }
        
        function confirmDelete() {
            if (!currentJobId) return;
            
            fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=delete_job&job_id=' + currentJobId
            })
            .then(response => response.json())
            .then(data => {
                closeDeleteModal();
                if (data.success) {
                    showMessage(data.message, 'success');
                    setTimeout(() => {
                        window.location.reload();
                    }, 1500);
                } else {
                    showMessage(data.message, 'error');
                }
            })
            .catch(error => {
                closeDeleteModal();
                showMessage('An error occurred', 'error');
            });
        }

        function toggleStatus(jobId, currentStatus) {
            fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=toggle_status&job_id=' + jobId
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showMessage(data.message, 'success');
                    setTimeout(() => {
                        window.location.reload();
                    }, 1000);
                } else {
                    showMessage(data.message, 'error');
                }
            })
            .catch(error => {
                showMessage('An error occurred', 'error');
            });
        }

        function toggleFeatured(jobId, makeFeatured) {
            fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=toggle_featured&job_id=' + jobId
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showMessage(data.message, 'success');
                    setTimeout(() => {
                        window.location.reload();
                    }, 1000);
                } else {
                    showMessage(data.message, 'error');
                }
            })
            .catch(error => {
                showMessage('An error occurred', 'error');
            });
        }

        function duplicateJob(jobId) {
            if (!confirm('Duplicate this job listing?')) return;
            
            fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=duplicate_job&job_id=' + jobId
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showMessage(data.message, 'success');
                    setTimeout(() => {
                        window.location.href = 'edit-job.php?id=' + data.new_job_id;
                    }, 1500);
                } else {
                    showMessage(data.message, 'error');
                }
            })
            .catch(error => {
                showMessage('An error occurred', 'error');
            });
        }

        function showMessage(message, type) {
            const flashDiv = document.createElement('div');
            flashDiv.className = `fixed top-20 right-4 z-50 text-white px-6 py-3 rounded-xl shadow-lg animate-slideIn ${
                type === 'success' ? 'bg-green-500' : 'bg-red-500'
            }`;
            flashDiv.innerHTML = `<i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'} mr-2"></i>${message}`;
            document.body.appendChild(flashDiv);
            
            setTimeout(() => {
                flashDiv.remove();
            }, 3000);
        }
        
        // Close modal with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeDeleteModal();
            }
        });

        // Close modal when clicking outside
        document.getElementById('deleteModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeDeleteModal();
            }
        });
    </script>
</body>
</html>