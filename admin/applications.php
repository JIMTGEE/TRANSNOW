<?php
// admin/applications.php - Admin Applications Management
// Version: 1.0 - Complete applications management system

// Error reporting for development
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration files
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../includes/auth.php';

// Require admin login
requireAdminLogin();

// Get database connection
$pdo = getDB();
$user_id = $_SESSION['user_id'];

// Get admin info
$adminData = fetchOne("
    SELECT u.* 
    FROM users u 
    WHERE u.id = ? AND u.user_type = 'admin'
", [$user_id]);

if (!$adminData) {
    $_SESSION['error'] = 'Admin account not found';
    header('Location: /logout.php');
    exit();
}

// Define the status icon function ONLY if it doesn't exist in functions.php
if (!function_exists('getStatusIcon')) {
    function getStatusIcon($status) {
        $icons = [
            'applied' => 'fa-paper-plane',
            'reviewed' => 'fa-eye',
            'shortlisted' => 'fa-star',
            'interview' => 'fa-calendar-check',
            'offered' => 'fa-gift',
            'hired' => 'fa-check-circle',
            'rejected' => 'fa-times-circle'
        ];
        return $icons[$status] ?? 'fa-circle';
    }
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $response = ['success' => false, 'message' => ''];
    
    // Update application status
    if (isset($_POST['action']) && $_POST['action'] === 'update_status') {
        $application_id = intval($_POST['application_id'] ?? 0);
        $new_status = $_POST['status'] ?? '';
        
        $valid_statuses = ['applied', 'reviewed', 'shortlisted', 'interview', 'offered', 'hired', 'rejected'];
        
        if ($application_id && in_array($new_status, $valid_statuses)) {
            $update = executeQuery("
                UPDATE applications 
                SET application_status = ?, updated_at = NOW() 
                WHERE id = ?
            ", [$new_status, $application_id]);
            
            if ($update) {
                // Get application details for notification
                $appDetails = fetchOne("
                    SELECT a.seeker_id, j.employer_id, j.title, s.user_id as seeker_user_id, e.user_id as employer_user_id
                    FROM applications a
                    INNER JOIN jobs j ON a.job_id = j.id
                    INNER JOIN seekers s ON a.seeker_id = s.id
                    INNER JOIN employers e ON j.employer_id = e.id
                    WHERE a.id = ?
                ", [$application_id]);
                
                if ($appDetails) {
                    // Notify seeker
                    createNotification(
                        $appDetails['seeker_user_id'],
                        'application_update',
                        'Application Status Updated',
                        "Your application for '{$appDetails['title']}' has been updated to: " . ucfirst($new_status),
                        'inapp'
                    );
                    
                    // Notify employer
                    createNotification(
                        $appDetails['employer_user_id'],
                        'application_update',
                        'Application Status Updated',
                        "Application for '{$appDetails['title']}' has been updated to: " . ucfirst($new_status),
                        'inapp'
                    );
                }
                
                $response['success'] = true;
                $response['message'] = 'Application status updated successfully';
            } else {
                $response['message'] = 'Failed to update application status';
            }
        } else {
            $response['message'] = 'Invalid application ID or status';
        }
    }
    
    // Bulk update status
    elseif (isset($_POST['action']) && $_POST['action'] === 'bulk_update') {
        $application_ids = isset($_POST['application_ids']) ? explode(',', $_POST['application_ids']) : [];
        $new_status = $_POST['status'] ?? '';
        
        $valid_statuses = ['applied', 'reviewed', 'shortlisted', 'interview', 'offered', 'hired', 'rejected'];
        
        if (!empty($application_ids) && in_array($new_status, $valid_statuses)) {
            $placeholders = implode(',', array_fill(0, count($application_ids), '?'));
            $params = array_merge([$new_status], $application_ids);
            
            $update = executeQuery("
                UPDATE applications 
                SET application_status = ?, updated_at = NOW() 
                WHERE id IN ($placeholders)
            ", $params);
            
            if ($update) {
                $response['success'] = true;
                $response['message'] = count($application_ids) . ' applications updated successfully';
            } else {
                $response['message'] = 'Failed to update applications';
            }
        } else {
            $response['message'] = 'Invalid selection or status';
        }
    }
    
    // Delete application
    elseif (isset($_POST['action']) && $_POST['action'] === 'delete') {
        $application_id = intval($_POST['application_id'] ?? 0);
        
        if ($application_id) {
            // Delete related records first
            executeQuery("DELETE FROM interviews WHERE application_id = ?", [$application_id]);
            executeQuery("DELETE FROM application_views WHERE application_id = ?", [$application_id]);
            
            // Delete the application
            $delete = executeQuery("DELETE FROM applications WHERE id = ?", [$application_id]);
            
            if ($delete) {
                $response['success'] = true;
                $response['message'] = 'Application deleted successfully';
            } else {
                $response['message'] = 'Failed to delete application';
            }
        } else {
            $response['message'] = 'Invalid application ID';
        }
    }
    
    // Add interview
    elseif (isset($_POST['action']) && $_POST['action'] === 'add_interview') {
        $application_id = intval($_POST['application_id'] ?? 0);
        $interview_date = $_POST['interview_date'] ?? '';
        $interview_type = $_POST['interview_type'] ?? 'video';
        $duration = intval($_POST['duration'] ?? 30);
        $meeting_link = $_POST['meeting_link'] ?? '';
        $notes = $_POST['notes'] ?? '';
        
        if ($application_id && $interview_date) {
            $insert = executeQuery("
                INSERT INTO interviews (
                    application_id, interview_type, interview_date, 
                    duration_minutes, meeting_link, notes, status, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, 'scheduled', NOW())
            ", [$application_id, $interview_type, $interview_date, $duration, $meeting_link, $notes]);
            
            if ($insert) {
                // Update application status to interview
                executeQuery("UPDATE applications SET application_status = 'interview', updated_at = NOW() WHERE id = ?", [$application_id]);
                
                $response['success'] = true;
                $response['message'] = 'Interview scheduled successfully';
            } else {
                $response['message'] = 'Failed to schedule interview';
            }
        } else {
            $response['message'] = 'Missing required fields';
        }
    }
    
    // Return JSON for AJAX requests
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode($response);
        exit();
    }
    
    // Regular form submission
    if ($response['success']) {
        $_SESSION['success'] = $response['message'];
    } else {
        $_SESSION['error'] = $response['message'];
    }
    
    header('Location: applications.php' . (isset($_GET['view']) ? '?view=' . $_GET['view'] : ''));
    exit();
}

// Get filter parameters
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$employer_filter = isset($_GET['employer_id']) ? intval($_GET['employer_id']) : 0;
$job_filter = isset($_GET['job_id']) ? intval($_GET['job_id']) : 0;
$seeker_filter = isset($_GET['seeker_id']) ? intval($_GET['seeker_id']) : 0;
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'newest';
$view_mode = isset($_GET['view']) ? $_GET['view'] : 'applications';

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Build the WHERE clause for applications
$where_conditions = ["1=1"];
$params = [];

if ($status_filter) {
    $where_conditions[] = "a.application_status = ?";
    $params[] = $status_filter;
}

if ($employer_filter) {
    $where_conditions[] = "e.id = ?";
    $params[] = $employer_filter;
}

if ($job_filter) {
    $where_conditions[] = "a.job_id = ?";
    $params[] = $job_filter;
}

if ($seeker_filter) {
    $where_conditions[] = "a.seeker_id = ?";
    $params[] = $seeker_filter;
}

if ($date_from) {
    $where_conditions[] = "DATE(a.applied_at) >= ?";
    $params[] = $date_from;
}

if ($date_to) {
    $where_conditions[] = "DATE(a.applied_at) <= ?";
    $params[] = $date_to;
}

if ($search) {
    $where_conditions[] = "(j.title LIKE ? OR e.company_name LIKE ? OR CONCAT(s.first_name, ' ', s.last_name) LIKE ? OR s.email LIKE ?)";
    $search_term = "%$search%";
    $params = array_merge($params, [$search_term, $search_term, $search_term, $search_term]);
}

// Order by
$order_by = "a.applied_at DESC";
switch ($sort) {
    case 'oldest':
        $order_by = "a.applied_at ASC";
        break;
    case 'match_high':
        $order_by = "a.match_score DESC, a.applied_at DESC";
        break;
    case 'match_low':
        $order_by = "a.match_score ASC, a.applied_at DESC";
        break;
    case 'company':
        $order_by = "e.company_name ASC, a.applied_at DESC";
        break;
    case 'job':
        $order_by = "j.title ASC, a.applied_at DESC";
        break;
}

$where_clause = implode(" AND ", $where_conditions);

// Get total count for pagination
$count_query = "
    SELECT COUNT(*) as total
    FROM applications a
    INNER JOIN jobs j ON a.job_id = j.id
    INNER JOIN employers e ON j.employer_id = e.id
    INNER JOIN seekers s ON a.seeker_id = s.id
    LEFT JOIN users su ON s.user_id = su.id
    WHERE $where_clause
";
$total_result = fetchOne($count_query, $params);
$total_applications = $total_result['total'] ?? 0;
$total_pages = ceil($total_applications / $per_page);

// Fetch applications with all details
$applications_query = "
    SELECT 
        a.id,
        a.cover_letter,
        a.resume_url,
        a.expected_salary,
        a.notice_period_days,
        a.application_status,
        a.match_score,
        a.is_favorite,
        a.applied_at,
        a.updated_at,
        
        -- Job details
        j.id as job_id,
        j.title as job_title,
        j.slug as job_slug,
        j.location_type,
        j.city,
        j.country,
        j.salary_min,
        j.salary_max,
        j.salary_currency,
        
        -- Employer details
        e.id as employer_id,
        e.company_name,
        e.company_logo,
        e.industry,
        e.is_verified as employer_verified,
        
        -- Seeker details
        s.id as seeker_id,
        s.first_name,
        s.last_name,
        s.profile_picture,
        s.headline,
        s.location,
        s.years_experience,
        s.highest_education,
        s.skills,
        su.email as seeker_email,
        su.is_verified as seeker_verified,
        
        -- Interview details (if any)
        (SELECT COUNT(*) FROM interviews i WHERE i.application_id = a.id) as interview_count,
        (SELECT MAX(interview_date) FROM interviews i WHERE i.application_id = a.id AND i.interview_date > NOW()) as next_interview,
        (SELECT GROUP_CONCAT(CONCAT(i.interview_type, ':', i.interview_date) SEPARATOR '|') 
         FROM interviews i WHERE i.application_id = a.id ORDER BY i.interview_date DESC LIMIT 1) as latest_interview,
        
        -- View count
        (SELECT COUNT(*) FROM application_views av WHERE av.application_id = a.id) as view_count
        
    FROM applications a
    INNER JOIN jobs j ON a.job_id = j.id
    INNER JOIN employers e ON j.employer_id = e.id
    INNER JOIN seekers s ON a.seeker_id = s.id
    LEFT JOIN users su ON s.user_id = su.id
    WHERE $where_clause
    ORDER BY $order_by
    LIMIT ? OFFSET ?
";

$fetch_params = array_merge($params, [$per_page, $offset]);
$applications = fetchAll($applications_query, $fetch_params);

// Get statistics for cards
$stats_query = "
    SELECT 
        COUNT(*) as total_applications,
        SUM(CASE WHEN application_status = 'applied' THEN 1 ELSE 0 END) as applied,
        SUM(CASE WHEN application_status = 'reviewed' THEN 1 ELSE 0 END) as reviewed,
        SUM(CASE WHEN application_status = 'shortlisted' THEN 1 ELSE 0 END) as shortlisted,
        SUM(CASE WHEN application_status = 'interview' THEN 1 ELSE 0 END) as interview,
        SUM(CASE WHEN application_status = 'offered' THEN 1 ELSE 0 END) as offered,
        SUM(CASE WHEN application_status = 'hired' THEN 1 ELSE 0 END) as hired,
        SUM(CASE WHEN application_status = 'rejected' THEN 1 ELSE 0 END) as rejected,
        COUNT(DISTINCT seeker_id) as unique_seekers,
        COUNT(DISTINCT job_id) as unique_jobs,
        COUNT(DISTINCT e.id) as unique_employers
    FROM applications a
    INNER JOIN jobs j ON a.job_id = j.id
    INNER JOIN employers e ON j.employer_id = e.id
";
$stats = fetchOne($stats_query, []) ?: [
    'total_applications' => 0,
    'applied' => 0,
    'reviewed' => 0,
    'shortlisted' => 0,
    'interview' => 0,
    'offered' => 0,
    'hired' => 0,
    'rejected' => 0,
    'unique_seekers' => 0,
    'unique_jobs' => 0,
    'unique_employers' => 0
];

// Get employers for filter dropdown
$employers = fetchAll("
    SELECT id, company_name, COUNT(DISTINCT j.id) as job_count
    FROM employers e
    LEFT JOIN jobs j ON e.id = j.employer_id
    WHERE e.is_verified = 1
    GROUP BY e.id
    ORDER BY company_name ASC
    LIMIT 100
");

// Get jobs for filter dropdown
$jobs = fetchAll("
    SELECT id, title, company_name
    FROM jobs j
    INNER JOIN employers e ON j.employer_id = e.id
    WHERE j.status = 'active'
    ORDER BY j.created_at DESC
    LIMIT 100
");

// Get recent activity for dashboard view
if ($view_mode === 'activity') {
    $recent_activity = fetchAll("
        (SELECT 
            'application' as type,
            a.id,
            a.application_status,
            a.applied_at as date,
            CONCAT(s.first_name, ' ', s.last_name) as seeker_name,
            j.title as job_title,
            e.company_name
        FROM applications a
        INNER JOIN seekers s ON a.seeker_id = s.id
        INNER JOIN jobs j ON a.job_id = j.id
        INNER JOIN employers e ON j.employer_id = e.id
        WHERE a.applied_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ORDER BY a.applied_at DESC
        LIMIT 20)
        
        UNION ALL
        
        (SELECT 
            'interview' as type,
            i.id,
            i.status as application_status,
            i.created_at as date,
            CONCAT(s.first_name, ' ', s.last_name) as seeker_name,
            j.title as job_title,
            e.company_name
        FROM interviews i
        INNER JOIN applications a ON i.application_id = a.id
        INNER JOIN seekers s ON a.seeker_id = s.id
        INNER JOIN jobs j ON a.job_id = j.id
        INNER JOIN employers e ON j.employer_id = e.id
        WHERE i.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ORDER BY i.created_at DESC
        LIMIT 20)
        
        ORDER BY date DESC
        LIMIT 30
    ");
}

// Get status counts for chart
$status_chart_data = fetchAll("
    SELECT 
        application_status,
        COUNT(*) as count
    FROM applications
    GROUP BY application_status
    ORDER BY FIELD(application_status, 
        'applied', 'reviewed', 'shortlisted', 'interview', 'offered', 'hired', 'rejected'
    )
");

$chart_labels = [];
$chart_counts = [];
foreach ($status_chart_data as $item) {
    $chart_labels[] = ucfirst($item['application_status']);
    $chart_counts[] = $item['count'];
}

// Avatar URL for admin
$avatarUrl = 'https://ui-avatars.com/api/?name=' . urlencode('Admin') . '&background=4f46e5&color=fff&size=128&bold=true';
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Applications Management - Admin | TransNow</title>
    
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Ccircle cx='50' cy='50' r='45' fill='%234f46e5'/%3E%3Cpath d='M30 40 L70 40 L65 70 L35 70 Z' fill='white'/%3E%3Ccircle cx='50' cy='30' r='8' fill='white'/%3E%3C/svg%3E">
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    
    <!-- AOS Animation -->
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    
    <!-- DataTables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.dataTables.min.css">
    
    <!-- Select2 -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    
    <!-- Datepicker -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    
    <style>
        * {
            font-family: 'Inter', sans-serif;
        }
        
        /* Custom Animations */
        @keyframes float {
            0%, 100% { transform: translateY(0) rotate(0deg); }
            25% { transform: translateY(-10px) rotate(2deg); }
            75% { transform: translateY(10px) rotate(-2deg); }
        }
        
        @keyframes pulse-glow {
            0%, 100% { box-shadow: 0 0 0 0 rgba(79, 70, 229, 0.5); }
            50% { box-shadow: 0 0 20px 5px rgba(79, 70, 229, 0.3); }
        }
        
        @keyframes slideIn {
            from { transform: translateX(20px); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        
        .animate-float {
            animation: float 6s ease-in-out infinite;
        }
        
        .animate-pulse-glow {
            animation: pulse-glow 2s ease-in-out infinite;
        }
        
        .animate-slideIn {
            animation: slideIn 0.3s ease-out forwards;
        }
        
        /* Glass Morphism */
        .glass {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .glass-dark {
            background: rgba(17, 24, 39, 0.7);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        /* Card Hover Effects */
        .hover-card {
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }
        
        .hover-card:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 25px 50px -12px rgba(79, 70, 229, 0.35);
        }
        
        .hover-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            border-radius: inherit;
            background: linear-gradient(135deg, rgba(79, 70, 229, 0.1), rgba(124, 58, 237, 0.1));
            opacity: 0;
            transition: opacity 0.4s;
            pointer-events: none;
        }
        
        .hover-card:hover::before {
            opacity: 1;
        }
        
        /* Gradient Text */
        .gradient-text {
            background: linear-gradient(135deg, #4f46e5, #7c3aed, #db2777);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        /* Custom Scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: #f1f1f1;
        }
        
        ::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, #4f46e5, #7c3aed);
            border-radius: 4px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(135deg, #7c3aed, #db2777);
        }
        
        /* Status Badges */
        .status-badge {
            @apply px-3 py-1 rounded-full text-xs font-semibold inline-flex items-center;
        }
        
        .status-applied { @apply bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300; }
        .status-reviewed { @apply bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-300; }
        .status-shortlisted { @apply bg-indigo-100 text-indigo-800 dark:bg-indigo-900 dark:text-indigo-300; }
        .status-interview { @apply bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300; }
        .status-offered { @apply bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300; }
        .status-hired { @apply bg-emerald-100 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-300; }
        .status-rejected { @apply bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300; }
        
        /* Table Styles */
        .dataTables_wrapper .dataTables_length,
        .dataTables_wrapper .dataTables_filter,
        .dataTables_wrapper .dataTables_info,
        .dataTables_wrapper .dataTables_paginate {
            @apply text-sm mb-4;
        }
        
        .dataTables_wrapper .dataTables_paginate .paginate_button {
            @apply px-3 py-2 mx-1 rounded-lg border border-gray-300 dark:border-gray-600 hover:bg-indigo-50 dark:hover:bg-gray-700;
        }
        
        .dataTables_wrapper .dataTables_paginate .paginate_button.current {
            @apply bg-indigo-600 text-white border-indigo-600;
        }
        
        /* Modal */
        .modal {
            transition: opacity 0.3s ease;
        }
        
        .modal.show {
            opacity: 1;
            pointer-events: auto;
        }
        
        /* Loading Skeleton */
        .skeleton {
            background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
            background-size: 200% 100%;
            animation: shimmer 1.5s infinite;
        }
        
        @keyframes shimmer {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }
        
        /* Progress Bar */
        .progress-bar {
            background: linear-gradient(90deg, #4f46e5, #7c3aed, #db2777);
            background-size: 200% 100%;
            animation: gradient 2s ease infinite;
        }
        
        @keyframes gradient {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }
        
        /* Select2 Customization */
        .select2-container--default .select2-selection--single {
            @apply bg-white dark:bg-gray-800 border-gray-300 dark:border-gray-600 rounded-lg h-10;
        }
        
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            @apply text-gray-700 dark:text-gray-300 leading-10;
        }
        
        .select2-container--default .select2-selection--single .select2-selection__arrow {
            @apply h-10;
        }
        
        .select2-dropdown {
            @apply bg-white dark:bg-gray-800 border-gray-300 dark:border-gray-600;
        }
        
        /* Mobile Optimizations */
        @media (max-width: 768px) {
            .applications-grid {
                grid-template-columns: 1fr;
            }
        }
        
        /* Dark mode support */
        @media (prefers-color-scheme: dark) {
            .dark-mode-aware {
                background: #1f2937;
                color: #ffffff;
            }
        }
        
        /* Filter Panel */
        .filter-panel {
            transition: all 0.3s ease;
        }
        
        .filter-panel.collapsed {
            max-height: 0;
            opacity: 0;
            overflow: hidden;
            margin: 0;
            padding: 0;
        }
    </style>
</head>
<body class="bg-gray-50 dark:bg-gray-900 text-gray-900 dark:text-white transition-colors duration-300">

<!-- Flash Message -->
<?php echo displayFlashMessage(); ?>

<!-- Dark Mode Toggle -->
<button id="darkModeToggle" 
        class="fixed top-24 right-4 z-40 w-12 h-12 bg-white dark:bg-gray-800 text-gray-700 dark:text-white rounded-full flex items-center justify-center hover:scale-110 transition-all duration-300 shadow-xl border border-gray-200 dark:border-gray-700">
    <i class="fas fa-moon text-xl" id="darkModeIcon"></i>
</button>

<!-- Main Layout -->
<div class="flex h-screen overflow-hidden">
    <!-- Sidebar -->
    <aside class="hidden lg:flex lg:flex-shrink-0">
        <div class="flex flex-col w-72 bg-white dark:bg-gray-800 border-r border-gray-200 dark:border-gray-700 overflow-y-auto">
            <!-- Sidebar Header -->
            <div class="p-6">
                <a href="/index.php" class="flex items-center space-x-3">
                    <div class="w-10 h-10 bg-gradient-to-r from-indigo-600 to-purple-600 rounded-lg flex items-center justify-center">
                        <i class="fas fa-globe-asia text-white text-lg"></i>
                    </div>
                    <span class="text-xl font-bold">
                        <span class="gradient-text">TransNow</span>
                    </span>
                    <span class="text-xs bg-red-500 text-white px-2 py-1 rounded-full">Admin</span>
                </a>
            </div>
            
            <!-- Admin Profile Summary -->
            <div class="px-6 py-4 border-t border-b border-gray-200 dark:border-gray-700">
                <div class="flex items-center space-x-4">
                    <div class="relative">
                        <img src="<?php echo htmlspecialchars($avatarUrl); ?>" 
                             alt="Admin" 
                             class="w-16 h-16 rounded-xl object-cover border-2 border-indigo-200 dark:border-indigo-800">
                        <div class="absolute -bottom-1 -right-1 w-5 h-5 bg-green-500 border-2 border-white dark:border-gray-800 rounded-full animate-pulse"></div>
                    </div>
                    <div class="flex-1 min-w-0">
                        <h3 class="font-bold text-lg truncate">Administrator</h3>
                        <p class="text-sm text-gray-600 dark:text-gray-400 truncate">
                            <?php echo htmlspecialchars($adminData['email'] ?? 'admin@transnow.com'); ?>
                        </p>
                        <div class="flex items-center mt-1">
                            <div class="w-2 h-2 bg-green-500 rounded-full mr-2"></div>
                            <span class="text-xs text-gray-500">Online</span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Navigation -->
            <nav class="flex-1 px-4 py-6 space-y-1">
                <!-- Dashboard -->
                <a href="dashboard.php" class="flex items-center space-x-3 px-4 py-3 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                    <i class="fas fa-tachometer-alt w-5"></i>
                    <span>Dashboard</span>
                </a>
             
                <!-- Jobs Management -->
                <a href="jobs.php" class="flex items-center space-x-3 px-4 py-3 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                    <i class="fas fa-briefcase w-5"></i>
                    <span>Jobs</span>
                </a>
                
                <!-- Applications (Active) -->
                <a href="applications.php" class="flex items-center space-x-3 px-4 py-3 bg-indigo-50 dark:bg-indigo-900/20 text-indigo-600 dark:text-indigo-400 rounded-xl font-medium">
                    <i class="fas fa-file-alt w-5"></i>
                    <span>Applications</span>
                    <?php if ($stats['total_applications'] > 0): ?>
                    <span class="ml-auto bg-indigo-500 text-white text-xs px-2 py-1 rounded-full"><?php echo $stats['total_applications']; ?></span>
                    <?php endif; ?>
                </a>
                
                <!-- Categories -->
                <a href="categories.php" class="flex items-center space-x-3 px-4 py-3 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                    <i class="fas fa-tags w-5"></i>
                    <span>Categories</span>
                </a>
               
                <!-- Reports -->
                <a href="reports.php" class="flex items-center space-x-3 px-4 py-3 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                    <i class="fas fa-chart-bar w-5"></i>
                    <span>Reports</span>
                </a>
                
                <!-- Settings -->
                <a href="settings.php" class="flex items-center space-x-3 px-4 py-3 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                    <i class="fas fa-cog w-5"></i>
                    <span>Settings</span>
                </a>
                
                <!-- Email Queue -->
                <a href="email-queue.php" class="flex items-center space-x-3 px-4 py-3 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                    <i class="fas fa-envelope w-5"></i>
                    <span>Email Queue</span>
                </a>
                
                <!-- Divider -->
                <div class="border-t border-gray-200 dark:border-gray-700 my-4"></div>
                
                <!-- Help & Support -->
                <a href="/help-center.php" class="flex items-center space-x-3 px-4 py-3 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                    <i class="fas fa-question-circle w-5"></i>
                    <span>Help Center</span>
                </a>
                
                <!-- Logout -->
                <a href="/logout.php" class="flex items-center space-x-3 px-4 py-3 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-xl transition">
                    <i class="fas fa-sign-out-alt w-5"></i>
                    <span>Logout</span>
                </a>
            </nav>
            
            <!-- Sidebar Footer -->
            <div class="p-4 border-t border-gray-200 dark:border-gray-700">
                <div class="bg-gradient-to-r from-indigo-500 to-purple-600 rounded-xl p-4 text-white">
                    <p class="text-sm opacity-90 mb-2">Applications Today</p>
                    <p class="text-2xl font-bold"><?php echo $stats['applied'] ?? 0; ?></p>
                    <p class="text-xs mt-2">Total: <?php echo $stats['total_applications']; ?></p>
                </div>
            </div>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="flex-1 overflow-y-auto bg-gray-50 dark:bg-gray-900">
        <!-- Top Navigation -->
        <header class="sticky top-0 z-30 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700">
            <div class="px-6 py-4">
                <div class="flex items-center justify-between">
                    <!-- Mobile Menu Toggle -->
                    <button id="mobileSidebarToggle" class="lg:hidden w-10 h-10 bg-gray-100 dark:bg-gray-700 rounded-lg flex items-center justify-center hover:bg-gray-200 dark:hover:bg-gray-600 transition">
                        <i class="fas fa-bars"></i>
                    </button>
                    
                    <!-- Page Title -->
                    <h1 class="text-2xl font-bold hidden md:block">
                        Applications <span class="gradient-text">Management</span>
                    </h1>
                    
                    <!-- Right Actions -->
                    <div class="flex items-center space-x-4">
                        <!-- Export Button -->
                        <button onclick="exportApplications()" class="px-4 py-2 bg-green-600 text-white rounded-xl font-medium hover:bg-green-700 transition-all duration-300 flex items-center space-x-2">
                            <i class="fas fa-download"></i>
                            <span class="hidden sm:inline">Export</span>
                        </button>
                        
                        <!-- View Toggle -->
                        <div class="flex bg-gray-100 dark:bg-gray-700 rounded-lg p-1">
                            <a href="?view=applications<?php echo $status_filter ? '&status='.$status_filter : ''; ?>" 
                               class="px-3 py-2 rounded-lg <?php echo $view_mode === 'applications' ? 'bg-white dark:bg-gray-600 shadow' : ''; ?>">
                                <i class="fas fa-list"></i>
                            </a>
                            <a href="?view=activity<?php echo $status_filter ? '&status='.$status_filter : ''; ?>" 
                               class="px-3 py-2 rounded-lg <?php echo $view_mode === 'activity' ? 'bg-white dark:bg-gray-600 shadow' : ''; ?>">
                                <i class="fas fa-history"></i>
                            </a>
                            <a href="?view=stats<?php echo $status_filter ? '&status='.$status_filter : ''; ?>" 
                               class="px-3 py-2 rounded-lg <?php echo $view_mode === 'stats' ? 'bg-white dark:bg-gray-600 shadow' : ''; ?>">
                                <i class="fas fa-chart-pie"></i>
                            </a>
                        </div>
                        
                        <!-- Quick Actions -->
                        <div class="relative">
                            <button id="quickActionsBtn" class="px-4 py-2 bg-gradient-to-r from-indigo-600 to-purple-600 text-white rounded-xl font-medium hover:shadow-lg transition-all duration-300 flex items-center space-x-2">
                                <i class="fas fa-plus-circle"></i>
                                <span class="hidden sm:inline">Actions</span>
                                <i class="fas fa-chevron-down text-xs"></i>
                            </button>
                            
                            <!-- Quick Actions Dropdown -->
                            <div id="quickActionsDropdown" class="absolute right-0 mt-2 w-64 bg-white dark:bg-gray-800 rounded-2xl shadow-2xl border border-gray-200 dark:border-gray-700 hidden z-50">
                                <div class="p-2">
                                    <button onclick="showBulkUpdateModal()" class="w-full text-left flex items-center space-x-3 px-4 py-3 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                                        <i class="fas fa-edit text-indigo-600 w-5"></i>
                                        <span>Bulk Update Status</span>
                                    </button>
                                    <button onclick="exportApplications()" class="w-full text-left flex items-center space-x-3 px-4 py-3 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                                        <i class="fas fa-file-export text-purple-600 w-5"></i>
                                        <span>Export to CSV</span>
                                    </button>
                                    <button onclick="window.print()" class="w-full text-left flex items-center space-x-3 px-4 py-3 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                                        <i class="fas fa-print text-green-600 w-5"></i>
                                        <span>Print Report</span>
                                    </button>
                                    <hr class="my-2 border-gray-200 dark:border-gray-700">
                                    <button onclick="resetFilters()" class="w-full text-left flex items-center space-x-3 px-4 py-3 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                                        <i class="fas fa-undo text-gray-600 w-5"></i>
                                        <span>Reset Filters</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <!-- Dashboard Content -->
        <div class="p-6">
            <!-- Stats Cards -->
            <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-8 gap-4 mb-8" data-aos="fade-down">
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-4 text-center hover-card">
                    <div class="text-2xl font-bold text-indigo-600"><?php echo $stats['total_applications']; ?></div>
                    <div class="text-xs text-gray-500">Total</div>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-4 text-center hover-card">
                    <div class="text-2xl font-bold text-blue-600"><?php echo $stats['applied']; ?></div>
                    <div class="text-xs text-gray-500">Applied</div>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-4 text-center hover-card">
                    <div class="text-2xl font-bold text-purple-600"><?php echo $stats['reviewed']; ?></div>
                    <div class="text-xs text-gray-500">Reviewed</div>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-4 text-center hover-card">
                    <div class="text-2xl font-bold text-indigo-600"><?php echo $stats['shortlisted']; ?></div>
                    <div class="text-xs text-gray-500">Shortlisted</div>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-4 text-center hover-card">
                    <div class="text-2xl font-bold text-yellow-600"><?php echo $stats['interview']; ?></div>
                    <div class="text-xs text-gray-500">Interview</div>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-4 text-center hover-card">
                    <div class="text-2xl font-bold text-green-600"><?php echo $stats['offered']; ?></div>
                    <div class="text-xs text-gray-500">Offered</div>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-4 text-center hover-card">
                    <div class="text-2xl font-bold text-emerald-600"><?php echo $stats['hired']; ?></div>
                    <div class="text-xs text-gray-500">Hired</div>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-4 text-center hover-card">
                    <div class="text-2xl font-bold text-red-600"><?php echo $stats['rejected']; ?></div>
                    <div class="text-xs text-gray-500">Rejected</div>
                </div>
            </div>

            <!-- Filter Bar -->
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6 mb-8" data-aos="fade-up">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-lg font-semibold flex items-center">
                        <i class="fas fa-filter mr-2 text-indigo-600"></i>
                        Filter Applications
                    </h2>
                    <button onclick="toggleFilters()" class="text-indigo-600 hover:text-indigo-700">
                        <i class="fas fa-chevron-up" id="filterToggleIcon"></i>
                    </button>
                </div>
                
                <div id="filterPanel">
                    <form method="GET" action="applications.php" class="space-y-4">
                        <input type="hidden" name="view" value="<?php echo htmlspecialchars($view_mode); ?>">
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                            <!-- Search -->
                            <div>
                                <label class="block text-sm font-medium mb-2">Search</label>
                                <div class="relative">
                                    <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                                    <input type="text" 
                                           name="search" 
                                           value="<?php echo htmlspecialchars($search); ?>"
                                           placeholder="Job title, company, seeker..."
                                           class="w-full pl-10 pr-4 py-2 bg-gray-100 dark:bg-gray-700 rounded-lg border-0 focus:ring-2 focus:ring-indigo-500">
                                </div>
                            </div>
                            
                            <!-- Status Filter -->
                            <div>
                                <label class="block text-sm font-medium mb-2">Status</label>
                                <select name="status" class="w-full px-4 py-2 bg-gray-100 dark:bg-gray-700 rounded-lg border-0 focus:ring-2 focus:ring-indigo-500">
                                    <option value="">All Statuses</option>
                                    <option value="applied" <?php echo $status_filter === 'applied' ? 'selected' : ''; ?>>Applied</option>
                                    <option value="reviewed" <?php echo $status_filter === 'reviewed' ? 'selected' : ''; ?>>Reviewed</option>
                                    <option value="shortlisted" <?php echo $status_filter === 'shortlisted' ? 'selected' : ''; ?>>Shortlisted</option>
                                    <option value="interview" <?php echo $status_filter === 'interview' ? 'selected' : ''; ?>>Interview</option>
                                    <option value="offered" <?php echo $status_filter === 'offered' ? 'selected' : ''; ?>>Offered</option>
                                    <option value="hired" <?php echo $status_filter === 'hired' ? 'selected' : ''; ?>>Hired</option>
                                    <option value="rejected" <?php echo $status_filter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                </select>
                            </div>
                            
                            <!-- Employer Filter -->
                            <div>
                                <label class="block text-sm font-medium mb-2">Employer</label>
                                <select name="employer_id" class="select2 w-full">
                                    <option value="">All Employers</option>
                                    <?php foreach ($employers as $emp): ?>
                                    <option value="<?php echo $emp['id']; ?>" <?php echo $employer_filter == $emp['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($emp['company_name']); ?> (<?php echo $emp['job_count']; ?> jobs)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <!-- Job Filter -->
                            <div>
                                <label class="block text-sm font-medium mb-2">Job</label>
                                <select name="job_id" class="select2 w-full">
                                    <option value="">All Jobs</option>
                                    <?php foreach ($jobs as $job): ?>
                                    <option value="<?php echo $job['id']; ?>" <?php echo $job_filter == $job['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($job['title']); ?> - <?php echo htmlspecialchars($job['company_name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <!-- Date From -->
                            <div>
                                <label class="block text-sm font-medium mb-2">From Date</label>
                                <input type="text" 
                                       name="date_from" 
                                       value="<?php echo htmlspecialchars($date_from); ?>"
                                       class="datepicker w-full px-4 py-2 bg-gray-100 dark:bg-gray-700 rounded-lg border-0 focus:ring-2 focus:ring-indigo-500"
                                       placeholder="YYYY-MM-DD">
                            </div>
                            
                            <!-- Date To -->
                            <div>
                                <label class="block text-sm font-medium mb-2">To Date</label>
                                <input type="text" 
                                       name="date_to" 
                                       value="<?php echo htmlspecialchars($date_to); ?>"
                                       class="datepicker w-full px-4 py-2 bg-gray-100 dark:bg-gray-700 rounded-lg border-0 focus:ring-2 focus:ring-indigo-500"
                                       placeholder="YYYY-MM-DD">
                            </div>
                            
                            <!-- Sort By -->
                            <div>
                                <label class="block text-sm font-medium mb-2">Sort By</label>
                                <select name="sort" class="w-full px-4 py-2 bg-gray-100 dark:bg-gray-700 rounded-lg border-0 focus:ring-2 focus:ring-indigo-500">
                                    <option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>Newest First</option>
                                    <option value="oldest" <?php echo $sort === 'oldest' ? 'selected' : ''; ?>>Oldest First</option>
                                    <option value="match_high" <?php echo $sort === 'match_high' ? 'selected' : ''; ?>>Highest Match</option>
                                    <option value="match_low" <?php echo $sort === 'match_low' ? 'selected' : ''; ?>>Lowest Match</option>
                                    <option value="company" <?php echo $sort === 'company' ? 'selected' : ''; ?>>Company Name</option>
                                    <option value="job" <?php echo $sort === 'job' ? 'selected' : ''; ?>>Job Title</option>
                                </select>
                            </div>
                            
                            <!-- Actions -->
                            <div class="flex items-end space-x-2">
                                <button type="submit" class="flex-1 px-4 py-2 bg-indigo-600 text-white rounded-lg font-medium hover:bg-indigo-700 transition">
                                    <i class="fas fa-filter mr-2"></i> Apply
                                </button>
                                <a href="applications.php?view=<?php echo $view_mode; ?>" class="px-4 py-2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg font-medium hover:bg-gray-300 dark:hover:bg-gray-600 transition">
                                    <i class="fas fa-times"></i>
                                </a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Main Content Area based on view mode -->
            <?php if ($view_mode === 'stats'): ?>
            <!-- Statistics View -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                <!-- Status Distribution Chart -->
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6" data-aos="fade-up">
                    <h2 class="text-xl font-bold mb-6">Application Status Distribution</h2>
                    <div class="chart-container" style="height: 300px;">
                        <canvas id="statusChart"></canvas>
                    </div>
                </div>
                
                <!-- Summary Stats -->
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6" data-aos="fade-up">
                    <h2 class="text-xl font-bold mb-6">Summary Statistics</h2>
                    <div class="space-y-4">
                        <div class="flex justify-between items-center p-4 bg-gray-50 dark:bg-gray-700 rounded-xl">
                            <span>Unique Seekers</span>
                            <span class="text-2xl font-bold text-indigo-600"><?php echo $stats['unique_seekers']; ?></span>
                        </div>
                        <div class="flex justify-between items-center p-4 bg-gray-50 dark:bg-gray-700 rounded-xl">
                            <span>Unique Jobs</span>
                            <span class="text-2xl font-bold text-purple-600"><?php echo $stats['unique_jobs']; ?></span>
                        </div>
                        <div class="flex justify-between items-center p-4 bg-gray-50 dark:bg-gray-700 rounded-xl">
                            <span>Unique Employers</span>
                            <span class="text-2xl font-bold text-green-600"><?php echo $stats['unique_employers']; ?></span>
                        </div>
                        <div class="flex justify-between items-center p-4 bg-gray-50 dark:bg-gray-700 rounded-xl">
                            <span>Average Applications per Job</span>
                            <span class="text-2xl font-bold text-yellow-600">
                                <?php echo $stats['unique_jobs'] > 0 ? round($stats['total_applications'] / $stats['unique_jobs'], 1) : 0; ?>
                            </span>
                        </div>
                        <div class="flex justify-between items-center p-4 bg-gray-50 dark:bg-gray-700 rounded-xl">
                            <span>Hire Rate</span>
                            <span class="text-2xl font-bold text-emerald-600">
                                <?php echo $stats['total_applications'] > 0 ? round(($stats['hired'] / $stats['total_applications']) * 100, 1) : 0; ?>%
                            </span>
                        </div>
                    </div>
                </div>
                
                <!-- Recent Activity Timeline -->
                <div class="lg:col-span-2 bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6" data-aos="fade-up">
                    <h2 class="text-xl font-bold mb-6">Application Timeline</h2>
                    <div class="relative">
                        <?php
                        $timeline_data = fetchAll("
                            SELECT 
                                DATE(a.applied_at) as date,
                                COUNT(*) as total,
                                SUM(CASE WHEN a.application_status = 'hired' THEN 1 ELSE 0 END) as hired
                            FROM applications a
                            WHERE a.applied_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                            GROUP BY DATE(a.applied_at)
                            ORDER BY date ASC
                        ");
                        
                        if (!empty($timeline_data)):
                        ?>
                        <div class="chart-container" style="height: 300px;">
                            <canvas id="timelineChart"></canvas>
                        </div>
                        <?php 
                        // Prepare timeline data for chart
                        $timeline_labels = [];
                        $timeline_totals = [];
                        $timeline_hired = [];
                        foreach ($timeline_data as $item) {
                            $timeline_labels[] = date('M d', strtotime($item['date']));
                            $timeline_totals[] = $item['total'];
                            $timeline_hired[] = $item['hired'];
                        }
                        endif;
                        ?>
                    </div>
                </div>
            </div>
            
            <?php elseif ($view_mode === 'activity'): ?>
            <!-- Activity View -->
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6" data-aos="fade-up">
                <h2 class="text-xl font-bold mb-6">Recent Activity</h2>
                
                <?php if (empty($recent_activity)): ?>
                <p class="text-gray-500 text-center py-8">No recent activity</p>
                <?php else: ?>
                <div class="space-y-4">
                    <?php foreach ($recent_activity as $activity): ?>
                    <div class="flex items-start space-x-4 p-4 bg-gray-50 dark:bg-gray-700/50 rounded-xl hover:shadow-md transition">
                        <div class="w-10 h-10 rounded-lg flex items-center justify-center <?php echo $activity['type'] === 'application' ? 'bg-blue-100 text-blue-600' : 'bg-green-100 text-green-600'; ?>">
                            <i class="fas <?php echo $activity['type'] === 'application' ? 'fa-file-alt' : 'fa-calendar-check'; ?>"></i>
                        </div>
                        <div class="flex-1">
                            <div class="flex items-center justify-between">
                                <div>
                                    <span class="font-semibold"><?php echo htmlspecialchars($activity['seeker_name']); ?></span>
                                    <span class="text-gray-500">applied for</span>
                                    <span class="font-semibold"><?php echo htmlspecialchars($activity['job_title']); ?></span>
                                    <span class="text-gray-500">at</span>
                                    <span class="font-semibold"><?php echo htmlspecialchars($activity['company_name']); ?></span>
                                </div>
                                <span class="text-sm text-gray-500"><?php echo timeAgo($activity['date']); ?></span>
                            </div>
                            <?php if ($activity['type'] === 'application'): ?>
                            <div class="mt-2">
                                <span class="status-badge status-<?php echo $activity['application_status']; ?>">
                                    <?php echo ucfirst($activity['application_status']); ?>
                                </span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            
            <?php else: ?>
            <!-- Applications List View -->
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6" data-aos="fade-up">
                <!-- Bulk Actions Bar -->
                <div id="bulkActionsBar" class="hidden bg-indigo-50 dark:bg-indigo-900/20 p-4 rounded-xl mb-6 flex items-center justify-between">
                    <div class="flex items-center space-x-4">
                        <span class="text-sm font-medium"><span id="selectedCount">0</span> applications selected</span>
                        <select id="bulkStatusSelect" class="px-3 py-2 bg-white dark:bg-gray-800 rounded-lg border border-gray-300 dark:border-gray-600">
                            <option value="">Change Status</option>
                            <option value="applied">Applied</option>
                            <option value="reviewed">Reviewed</option>
                            <option value="shortlisted">Shortlisted</option>
                            <option value="interview">Interview</option>
                            <option value="offered">Offered</option>
                            <option value="hired">Hired</option>
                            <option value="rejected">Rejected</option>
                        </select>
                        <button onclick="bulkUpdateStatus()" class="px-4 py-2 bg-indigo-600 text-white rounded-lg font-medium hover:bg-indigo-700 transition">
                            Apply
                        </button>
                    </div>
                    <button onclick="clearSelection()" class="text-gray-500 hover:text-gray-700">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <!-- Applications Table -->
                <?php if (empty($applications)): ?>
                <div class="text-center py-16">
                    <div class="w-24 h-24 bg-gray-100 dark:bg-gray-700 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-file-alt text-4xl text-gray-400"></i>
                    </div>
                    <h3 class="text-xl font-bold mb-2">No applications found</h3>
                    <p class="text-gray-500 mb-6">Try adjusting your filters or check back later</p>
                    <a href="applications.php" class="inline-flex items-center space-x-2 bg-gradient-to-r from-indigo-600 to-purple-600 text-white px-6 py-3 rounded-xl font-semibold hover:shadow-lg transition-all">
                        <i class="fas fa-undo"></i>
                        <span>Reset Filters</span>
                    </a>
                </div>
                <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700" id="applicationsTable">
                        <thead>
                            <tr>
                                <th class="px-4 py-3 text-left">
                                    <input type="checkbox" id="selectAll" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                </th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Seeker</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Job</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Company</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Match</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Applied</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            <?php foreach ($applications as $app): ?>
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50 transition" id="app-<?php echo $app['id']; ?>">
                                <td class="px-4 py-4">
                                    <input type="checkbox" class="app-checkbox rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" value="<?php echo $app['id']; ?>">
                                </td>
                                <td class="px-4 py-4">
                                    <div class="flex items-center space-x-3">
                                        <img src="<?php 
                                            echo !empty($app['profile_picture']) 
                                                ? htmlspecialchars($app['profile_picture']) 
                                                : 'https://ui-avatars.com/api/?name=' . urlencode($app['first_name'] . ' ' . $app['last_name']) . '&background=4f46e5&color=fff';
                                        ?>" alt="" class="w-10 h-10 rounded-lg object-cover">
                                        <div>
                                            <div class="font-medium"><?php echo htmlspecialchars($app['first_name'] . ' ' . $app['last_name']); ?></div>
                                            <div class="text-xs text-gray-500">
                                                <?php echo $app['years_experience'] ? $app['years_experience'] . ' yrs exp' : ''; ?>
                                                <?php if ($app['seeker_verified']): ?>
                                                <i class="fas fa-check-circle text-blue-500 ml-1" title="Verified"></i>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-4">
                                    <a href="job-details.php?id=<?php echo $app['job_id']; ?>" class="font-medium hover:text-indigo-600 transition">
                                        <?php echo htmlspecialchars($app['job_title']); ?>
                                    </a>
                                    <div class="text-xs text-gray-500">
                                        <i class="fas fa-map-marker-alt mr-1"></i> <?php echo ucfirst($app['location_type'] ?? 'onsite'); ?>
                                        <?php if (!empty($app['city'])): ?> - <?php echo htmlspecialchars($app['city']); ?><?php endif; ?>
                                    </div>
                                </td>
                                <td class="px-4 py-4">
                                    <div class="flex items-center">
                                        <img src="<?php 
                                            echo !empty($app['company_logo']) 
                                                ? htmlspecialchars($app['company_logo']) 
                                                : 'https://ui-avatars.com/api/?name=' . urlencode($app['company_name']) . '&background=4f46e5&color=fff';
                                        ?>" alt="" class="w-8 h-8 rounded-lg object-cover mr-2">
                                        <div>
                                            <div><?php echo htmlspecialchars($app['company_name']); ?></div>
                                            <?php if ($app['employer_verified']): ?>
                                            <i class="fas fa-check-circle text-blue-500 text-xs" title="Verified Employer"></i>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-4">
                                    <div class="relative">
                                        <button onclick="toggleStatusDropdown(<?php echo $app['id']; ?>)" 
                                                class="status-badge status-<?php echo $app['application_status']; ?> cursor-pointer hover:opacity-80 transition">
                                            <i class="fas <?php echo getStatusIcon($app['application_status']); ?> mr-1"></i>
                                            <?php echo ucfirst($app['application_status']); ?>
                                            <i class="fas fa-chevron-down ml-1 text-xs"></i>
                                        </button>
                                        
                                        <!-- Status Dropdown -->
                                        <div id="status-dropdown-<?php echo $app['id']; ?>" 
                                             class="absolute left-0 mt-2 w-48 bg-white dark:bg-gray-800 rounded-xl shadow-2xl border border-gray-200 dark:border-gray-700 hidden z-50">
                                            <div class="p-2">
                                                <?php 
                                                $statuses = ['applied', 'reviewed', 'shortlisted', 'interview', 'offered', 'hired', 'rejected'];
                                                foreach ($statuses as $status):
                                                ?>
                                                <button onclick="updateStatus(<?php echo $app['id']; ?>, '<?php echo $status; ?>')" 
                                                        class="w-full text-left px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg text-sm flex items-center space-x-2">
                                                    <i class="fas <?php echo getStatusIcon($status); ?>"></i>
                                                    <span><?php echo ucfirst($status); ?></span>
                                                </button>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <?php if ($app['interview_count'] > 0): ?>
                                    <div class="mt-1 text-xs text-green-600">
                                        <i class="fas fa-calendar-check mr-1"></i> <?php echo $app['interview_count']; ?> interview(s)
                                    </div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-4">
                                    <?php if (!empty($app['match_score'])): ?>
                                    <div class="flex items-center">
                                        <div class="w-16 bg-gray-200 dark:bg-gray-700 rounded-full h-2 mr-2">
                                            <div class="bg-green-500 h-2 rounded-full" style="width: <?php echo min(100, $app['match_score']); ?>%"></div>
                                        </div>
                                        <span class="text-sm font-semibold <?php echo $app['match_score'] >= 80 ? 'text-green-600' : ($app['match_score'] >= 60 ? 'text-yellow-600' : 'text-gray-600'); ?>">
                                            <?php echo $app['match_score']; ?>%
                                        </span>
                                    </div>
                                    <?php else: ?>
                                    <span class="text-gray-400">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-4 text-sm">
                                    <?php echo timeAgo($app['applied_at']); ?>
                                    <?php if ($app['view_count'] > 0): ?>
                                    <div class="text-xs text-gray-500">
                                        <i class="fas fa-eye mr-1"></i> <?php echo $app['view_count']; ?> views
                                    </div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-4">
                                    <div class="flex space-x-2">
                                        <button onclick="viewApplication(<?php echo $app['id']; ?>)" 
                                                class="w-8 h-8 bg-indigo-100 dark:bg-indigo-900 text-indigo-600 dark:text-indigo-400 rounded-lg flex items-center justify-center hover:bg-indigo-200 dark:hover:bg-indigo-800 transition"
                                                title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        
                                        <?php if ($app['application_status'] !== 'hired' && $app['application_status'] !== 'rejected'): ?>
                                        <button onclick="showInterviewModal(<?php echo $app['id']; ?>, '<?php echo htmlspecialchars($app['first_name'] . ' ' . $app['last_name']); ?>', '<?php echo htmlspecialchars($app['job_title']); ?>')" 
                                                class="w-8 h-8 bg-green-100 dark:bg-green-900 text-green-600 dark:text-green-400 rounded-lg flex items-center justify-center hover:bg-green-200 dark:hover:bg-green-800 transition"
                                                title="Schedule Interview">
                                            <i class="fas fa-calendar-plus"></i>
                                        </button>
                                        <?php endif; ?>
                                        
                                        <?php if (!empty($app['resume_url'])): ?>
                                        <a href="<?php echo htmlspecialchars($app['resume_url']); ?>" target="_blank"
                                           class="w-8 h-8 bg-purple-100 dark:bg-purple-900 text-purple-600 dark:text-purple-400 rounded-lg flex items-center justify-center hover:bg-purple-200 dark:hover:bg-purple-800 transition"
                                           title="View Resume">
                                            <i class="fas fa-file-pdf"></i>
                                        </a>
                                        <?php endif; ?>
                                        
                                        <button onclick="deleteApplication(<?php echo $app['id']; ?>)"
                                                class="w-8 h-8 bg-red-100 dark:bg-red-900 text-red-600 dark:text-red-400 rounded-lg flex items-center justify-center hover:bg-red-200 dark:hover:bg-red-800 transition"
                                                title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <div class="flex items-center justify-between mt-6">
                    <div class="text-sm text-gray-500">
                        Showing <?php echo $offset + 1; ?> to <?php echo min($offset + $per_page, $total_applications); ?> of <?php echo $total_applications; ?> applications
                    </div>
                    <div class="flex space-x-2">
                        <?php if ($page > 1): ?>
                        <a href="?page=<?php echo $page - 1; ?>&status=<?php echo urlencode($status_filter); ?>&employer_id=<?php echo $employer_filter; ?>&job_id=<?php echo $job_filter; ?>&seeker_id=<?php echo $seeker_filter; ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&search=<?php echo urlencode($search); ?>&sort=<?php echo urlencode($sort); ?>&view=<?php echo $view_mode; ?>" 
                           class="px-4 py-2 bg-gray-200 dark:bg-gray-700 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                        <?php endif; ?>
                        
                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                        <a href="?page=<?php echo $i; ?>&status=<?php echo urlencode($status_filter); ?>&employer_id=<?php echo $employer_filter; ?>&job_id=<?php echo $job_filter; ?>&seeker_id=<?php echo $seeker_filter; ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&search=<?php echo urlencode($search); ?>&sort=<?php echo urlencode($sort); ?>&view=<?php echo $view_mode; ?>" 
                           class="px-4 py-2 <?php echo $i == $page ? 'bg-indigo-600 text-white' : 'bg-gray-200 dark:bg-gray-700 hover:bg-gray-300 dark:hover:bg-gray-600'; ?> rounded-lg transition">
                            <?php echo $i; ?>
                        </a>
                        <?php endfor; ?>
                        
                        <?php if ($page < $total_pages): ?>
                        <a href="?page=<?php echo $page + 1; ?>&status=<?php echo urlencode($status_filter); ?>&employer_id=<?php echo $employer_filter; ?>&job_id=<?php echo $job_filter; ?>&seeker_id=<?php echo $seeker_filter; ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&search=<?php echo urlencode($search); ?>&sort=<?php echo urlencode($sort); ?>&view=<?php echo $view_mode; ?>" 
                           class="px-4 py-2 bg-gray-200 dark:bg-gray-700 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<!-- Mobile Sidebar (Hidden by default) -->
<div id="mobileSidebar" class="fixed inset-0 z-50 hidden lg:hidden">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" id="mobileSidebarOverlay"></div>
    <div class="absolute left-0 top-0 bottom-0 w-72 bg-white dark:bg-gray-800 overflow-y-auto">
        <div class="p-6">
            <div class="flex items-center justify-between mb-6">
                <a href="/index.php" class="flex items-center space-x-3">
                    <div class="w-10 h-10 bg-gradient-to-r from-indigo-600 to-purple-600 rounded-lg flex items-center justify-center">
                        <i class="fas fa-globe-asia text-white text-lg"></i>
                    </div>
                    <span class="text-xl font-bold">
                        <span class="gradient-text">TransNow</span>
                    </span>
                </a>
                <button id="closeMobileSidebar" class="w-10 h-10 bg-gray-100 dark:bg-gray-700 rounded-lg flex items-center justify-center">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <div class="px-2 py-4 border-t border-gray-200 dark:border-gray-700">
                <div class="flex items-center space-x-3 mb-4">
                    <img src="<?php echo htmlspecialchars($avatarUrl); ?>" alt="Admin" class="w-12 h-12 rounded-lg object-cover border-2 border-indigo-200">
                    <div class="flex-1 min-w-0">
                        <p class="font-semibold truncate">Administrator</p>
                        <p class="text-sm text-gray-500 truncate"><?php echo htmlspecialchars($adminData['email'] ?? ''); ?></p>
                    </div>
                </div>
                
                <nav class="space-y-1">
                    <a href="dashboard.php" class="block px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">Dashboard</a>
                    <a href="users.php" class="block px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">Users</a>
                    <a href="jobs.php" class="block px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">Jobs</a>
                    <a href="applications.php" class="block px-4 py-2 bg-indigo-50 dark:bg-indigo-900/20 text-indigo-600 rounded-lg">Applications</a>
                    <a href="categories.php" class="block px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">Categories</a>
                    <a href="job-types.php" class="block px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">Job Types</a>
                    <a href="reports.php" class="block px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">Reports</a>
                    <a href="settings.php" class="block px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">Settings</a>
                    <a href="email-queue.php" class="block px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">Email Queue</a>
                    <a href="/logout.php" class="block px-4 py-2 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg">Logout</a>
                </nav>
            </div>
        </div>
    </div>
</div>

<!-- Interview Modal -->
<div id="interviewModal" class="fixed inset-0 z-50 hidden flex items-center justify-center">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeInterviewModal()"></div>
    <div class="relative bg-white dark:bg-gray-800 rounded-2xl shadow-2xl w-full max-w-lg mx-4 p-6">
        <h2 class="text-2xl font-bold mb-4">Schedule Interview</h2>
        
        <form id="interviewForm" method="POST" class="space-y-4">
            <input type="hidden" name="action" value="add_interview">
            <input type="hidden" name="application_id" id="interview_app_id">
            
            <div class="p-4 bg-indigo-50 dark:bg-indigo-900/20 rounded-xl mb-4">
                <p class="text-sm">
                    <span class="font-semibold">Seeker:</span> <span id="interview_seeker_name"></span><br>
                    <span class="font-semibold">Job:</span> <span id="interview_job_title"></span>
                </p>
            </div>
            
            <div>
                <label class="block text-sm font-medium mb-2">Interview Type</label>
                <select name="interview_type" class="w-full px-4 py-2 bg-gray-100 dark:bg-gray-700 rounded-lg border-0 focus:ring-2 focus:ring-indigo-500" required>
                    <option value="phone">Phone Interview</option>
                    <option value="video" selected>Video Interview</option>
                    <option value="in_person">In-Person Interview</option>
                </select>
            </div>
            
            <div>
                <label class="block text-sm font-medium mb-2">Date & Time</label>
                <input type="datetime-local" 
                       name="interview_date" 
                       class="w-full px-4 py-2 bg-gray-100 dark:bg-gray-700 rounded-lg border-0 focus:ring-2 focus:ring-indigo-500"
                       required>
            </div>
            
            <div>
                <label class="block text-sm font-medium mb-2">Duration (minutes)</label>
                <select name="duration" class="w-full px-4 py-2 bg-gray-100 dark:bg-gray-700 rounded-lg border-0 focus:ring-2 focus:ring-indigo-500">
                    <option value="15">15 minutes</option>
                    <option value="30" selected>30 minutes</option>
                    <option value="45">45 minutes</option>
                    <option value="60">60 minutes</option>
                    <option value="90">90 minutes</option>
                </select>
            </div>
            
            <div>
                <label class="block text-sm font-medium mb-2">Meeting Link (if applicable)</label>
                <input type="url" 
                       name="meeting_link" 
                       class="w-full px-4 py-2 bg-gray-100 dark:bg-gray-700 rounded-lg border-0 focus:ring-2 focus:ring-indigo-500"
                       placeholder="https://meet.google.com/...">
            </div>
            
            <div>
                <label class="block text-sm font-medium mb-2">Notes</label>
                <textarea name="notes" 
                          rows="3"
                          class="w-full px-4 py-2 bg-gray-100 dark:bg-gray-700 rounded-lg border-0 focus:ring-2 focus:ring-indigo-500"
                          placeholder="Add any notes or instructions for the interview..."></textarea>
            </div>
            
            <div class="flex space-x-3 pt-4">
                <button type="submit" class="flex-1 bg-gradient-to-r from-indigo-600 to-purple-600 text-white py-3 rounded-xl font-semibold hover:shadow-lg transition">
                    Schedule Interview
                </button>
                <button type="button" onclick="closeInterviewModal()" class="px-6 py-3 border border-gray-300 dark:border-gray-600 rounded-xl font-semibold hover:bg-gray-100 dark:hover:bg-gray-700 transition">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<!-- View Application Modal -->
<div id="viewModal" class="fixed inset-0 z-50 hidden flex items-center justify-center overflow-y-auto">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeViewModal()"></div>
    <div class="relative bg-white dark:bg-gray-800 rounded-2xl shadow-2xl w-full max-w-4xl mx-4 my-8 p-6">
        <div class="flex items-center justify-between mb-6">
            <h2 class="text-2xl font-bold">Application Details</h2>
            <button onclick="closeViewModal()" class="w-10 h-10 bg-gray-100 dark:bg-gray-700 rounded-lg flex items-center justify-center hover:bg-gray-200 dark:hover:bg-gray-600 transition">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <div id="applicationDetails" class="space-y-6">
            <!-- Content will be loaded via AJAX -->
            <div class="text-center py-8">
                <i class="fas fa-spinner fa-spin text-4xl text-indigo-600"></i>
                <p class="mt-4">Loading application details...</p>
            </div>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="deleteModal" class="fixed inset-0 z-50 hidden flex items-center justify-center">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeDeleteModal()"></div>
    <div class="relative bg-white dark:bg-gray-800 rounded-2xl shadow-2xl w-full max-w-md mx-4 p-6">
        <div class="text-center">
            <div class="w-20 h-20 bg-red-100 dark:bg-red-900 rounded-full flex items-center justify-center mx-auto mb-4">
                <i class="fas fa-exclamation-triangle text-4xl text-red-600"></i>
            </div>
            <h2 class="text-2xl font-bold mb-2">Delete Application</h2>
            <p class="text-gray-500 mb-6">Are you sure you want to delete this application? This action cannot be undone.</p>
            
            <form method="POST" class="flex space-x-3">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="application_id" id="delete_app_id">
                <button type="submit" class="flex-1 bg-red-600 text-white py-3 rounded-xl font-semibold hover:bg-red-700 transition">
                    Yes, Delete
                </button>
                <button type="button" onclick="closeDeleteModal()" class="flex-1 bg-gray-200 dark:bg-gray-700 py-3 rounded-xl font-semibold hover:bg-gray-300 dark:hover:bg-gray-600 transition">
                    Cancel
                </button>
            </form>
        </div>
    </div>
</div>

<!-- Bulk Update Modal -->
<div id="bulkUpdateModal" class="fixed inset-0 z-50 hidden flex items-center justify-center">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeBulkUpdateModal()"></div>
    <div class="relative bg-white dark:bg-gray-800 rounded-2xl shadow-2xl w-full max-w-md mx-4 p-6">
        <h2 class="text-2xl font-bold mb-4">Bulk Update Status</h2>
        
        <form method="POST" id="bulkUpdateForm">
            <input type="hidden" name="action" value="bulk_update">
            <input type="hidden" name="application_ids" id="bulk_app_ids">
            
            <div class="p-4 bg-indigo-50 dark:bg-indigo-900/20 rounded-xl mb-4">
                <p class="text-sm">
                    <span class="font-semibold">Selected applications:</span> <span id="bulk_selected_count"></span>
                </p>
            </div>
            
            <div class="mb-4">
                <label class="block text-sm font-medium mb-2">New Status</label>
                <select name="status" class="w-full px-4 py-2 bg-gray-100 dark:bg-gray-700 rounded-lg border-0 focus:ring-2 focus:ring-indigo-500" required>
                    <option value="">Select Status</option>
                    <option value="applied">Applied</option>
                    <option value="reviewed">Reviewed</option>
                    <option value="shortlisted">Shortlisted</option>
                    <option value="interview">Interview</option>
                    <option value="offered">Offered</option>
                    <option value="hired">Hired</option>
                    <option value="rejected">Rejected</option>
                </select>
            </div>
            
            <div class="flex space-x-3">
                <button type="submit" class="flex-1 bg-gradient-to-r from-indigo-600 to-purple-600 text-white py-3 rounded-xl font-semibold hover:shadow-lg transition">
                    Update All
                </button>
                <button type="button" onclick="closeBulkUpdateModal()" class="px-6 py-3 border border-gray-300 dark:border-gray-600 rounded-xl font-semibold hover:bg-gray-100 dark:hover:bg-gray-700 transition">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Scripts -->
<script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

<script>
// Initialize AOS
AOS.init({
    duration: 800,
    once: true,
    offset: 50,
    easing: 'ease-out-cubic'
});

// Initialize Select2
$(document).ready(function() {
    $('.select2').select2({
        theme: 'classic',
        width: '100%'
    });
});

// Initialize Datepicker
flatpickr('.datepicker', {
    dateFormat: 'Y-m-d',
    allowInput: true
});

// Dark Mode Toggle
const darkModeToggle = document.getElementById('darkModeToggle');
const darkModeIcon = document.getElementById('darkModeIcon');

function initDarkMode() {
    if (localStorage.theme === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
        document.documentElement.classList.add('dark');
        darkModeIcon?.classList.remove('fa-moon');
        darkModeIcon?.classList.add('fa-sun');
    } else {
        document.documentElement.classList.remove('dark');
        darkModeIcon?.classList.remove('fa-sun');
        darkModeIcon?.classList.add('fa-moon');
    }
}
initDarkMode();

if (darkModeToggle) {
    darkModeToggle.addEventListener('click', () => {
        document.documentElement.classList.toggle('dark');
        
        if (document.documentElement.classList.contains('dark')) {
            localStorage.theme = 'dark';
            darkModeIcon?.classList.remove('fa-moon');
            darkModeIcon?.classList.add('fa-sun');
        } else {
            localStorage.theme = 'light';
            darkModeIcon?.classList.remove('fa-sun');
            darkModeIcon?.classList.add('fa-moon');
        }
    });
}

// Quick Actions Dropdown
const quickActionsBtn = document.getElementById('quickActionsBtn');
const quickActionsDropdown = document.getElementById('quickActionsDropdown');

if (quickActionsBtn && quickActionsDropdown) {
    quickActionsBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        quickActionsDropdown.classList.toggle('hidden');
    });
}

// Close dropdowns when clicking outside
document.addEventListener('click', (e) => {
    if (quickActionsBtn && !quickActionsBtn.contains(e.target)) {
        quickActionsDropdown?.classList.add('hidden');
    }
});

// Mobile Sidebar
const mobileSidebarToggle = document.getElementById('mobileSidebarToggle');
const mobileSidebar = document.getElementById('mobileSidebar');
const mobileSidebarOverlay = document.getElementById('mobileSidebarOverlay');
const closeMobileSidebar = document.getElementById('closeMobileSidebar');

if (mobileSidebarToggle && mobileSidebar) {
    mobileSidebarToggle.addEventListener('click', () => {
        mobileSidebar.classList.remove('hidden');
    });
}

function closeMobileSidebarFunc() {
    mobileSidebar?.classList.add('hidden');
}

if (mobileSidebarOverlay) {
    mobileSidebarOverlay.addEventListener('click', closeMobileSidebarFunc);
}
if (closeMobileSidebar) {
    closeMobileSidebar.addEventListener('click', closeMobileSidebarFunc);
}

// Filter Toggle
function toggleFilters() {
    const panel = document.getElementById('filterPanel');
    const icon = document.getElementById('filterToggleIcon');
    
    if (panel.classList.contains('hidden')) {
        panel.classList.remove('hidden');
        icon.classList.remove('fa-chevron-down');
        icon.classList.add('fa-chevron-up');
    } else {
        panel.classList.add('hidden');
        icon.classList.remove('fa-chevron-up');
        icon.classList.add('fa-chevron-down');
    }
}

// Reset Filters
function resetFilters() {
    window.location.href = 'applications.php?view=<?php echo $view_mode; ?>';
}

// Status Dropdown
function toggleStatusDropdown(appId) {
    // Close all other dropdowns
    document.querySelectorAll('[id^="status-dropdown-"]').forEach(dropdown => {
        if (dropdown.id !== `status-dropdown-${appId}`) {
            dropdown.classList.add('hidden');
        }
    });
    
    // Toggle current dropdown
    const dropdown = document.getElementById(`status-dropdown-${appId}`);
    dropdown.classList.toggle('hidden');
}

// Update Status
function updateStatus(appId, status) {
    // Hide dropdown
    document.getElementById(`status-dropdown-${appId}`)?.classList.add('hidden');
    
    // Send AJAX request
    fetch(window.location.href, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: new URLSearchParams({
            action: 'update_status',
            application_id: appId,
            status: status
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Reload page to show updated status
            location.reload();
        } else {
            alert(data.message || 'Error updating status');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred');
    });
}

// View Application
function viewApplication(appId) {
    document.getElementById('viewModal').classList.remove('hidden');
    
    // Load application details via AJAX
    fetch(`ajax/get-application-details.php?id=${appId}`)
        .then(response => response.text())
        .then(html => {
            document.getElementById('applicationDetails').innerHTML = html;
        })
        .catch(error => {
            document.getElementById('applicationDetails').innerHTML = `
                <div class="text-center py-8 text-red-600">
                    <i class="fas fa-exclamation-circle text-4xl mb-3"></i>
                    <p>Error loading application details</p>
                </div>
            `;
        });
}

function closeViewModal() {
    document.getElementById('viewModal').classList.add('hidden');
}

// Interview Modal
function showInterviewModal(appId, seekerName, jobTitle) {
    document.getElementById('interview_app_id').value = appId;
    document.getElementById('interview_seeker_name').textContent = seekerName;
    document.getElementById('interview_job_title').textContent = jobTitle;
    document.getElementById('interviewModal').classList.remove('hidden');
}

function closeInterviewModal() {
    document.getElementById('interviewModal').classList.add('hidden');
    document.getElementById('interviewForm').reset();
}

// Delete Application
function deleteApplication(appId) {
    document.getElementById('delete_app_id').value = appId;
    document.getElementById('deleteModal').classList.remove('hidden');
}

function closeDeleteModal() {
    document.getElementById('deleteModal').classList.add('hidden');
}

// Bulk Selection
const selectAll = document.getElementById('selectAll');
const checkboxes = document.querySelectorAll('.app-checkbox');
const bulkActionsBar = document.getElementById('bulkActionsBar');
const selectedCount = document.getElementById('selectedCount');

if (selectAll) {
    selectAll.addEventListener('change', function() {
        checkboxes.forEach(checkbox => {
            checkbox.checked = this.checked;
        });
        updateBulkActionsBar();
    });
}

checkboxes.forEach(checkbox => {
    checkbox.addEventListener('change', updateBulkActionsBar);
});

function updateBulkActionsBar() {
    const checked = document.querySelectorAll('.app-checkbox:checked');
    const count = checked.length;
    
    if (count > 0) {
        selectedCount.textContent = count;
        bulkActionsBar.classList.remove('hidden');
    } else {
        bulkActionsBar.classList.add('hidden');
        if (selectAll) selectAll.checked = false;
    }
}

function clearSelection() {
    checkboxes.forEach(checkbox => {
        checkbox.checked = false;
    });
    if (selectAll) selectAll.checked = false;
    bulkActionsBar.classList.add('hidden');
}

// Bulk Update
function showBulkUpdateModal() {
    const checked = document.querySelectorAll('.app-checkbox:checked');
    if (checked.length === 0) {
        alert('Please select at least one application');
        return;
    }
    
    const ids = Array.from(checked).map(cb => cb.value).join(',');
    document.getElementById('bulk_app_ids').value = ids;
    document.getElementById('bulk_selected_count').textContent = checked.length;
    document.getElementById('bulkUpdateModal').classList.remove('hidden');
}

function closeBulkUpdateModal() {
    document.getElementById('bulkUpdateModal').classList.add('hidden');
}

function bulkUpdateStatus() {
    const status = document.getElementById('bulkStatusSelect').value;
    if (!status) {
        alert('Please select a status');
        return;
    }
    
    const checked = document.querySelectorAll('.app-checkbox:checked');
    const ids = Array.from(checked).map(cb => cb.value).join(',');
    
    fetch(window.location.href, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: new URLSearchParams({
            action: 'bulk_update',
            application_ids: ids,
            status: status
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert(data.message || 'Error updating applications');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred');
    });
}

// Export Applications
function exportApplications() {
    // Build export URL with current filters
    const params = new URLSearchParams({
        export: 'csv',
        status: '<?php echo $status_filter; ?>',
        employer_id: '<?php echo $employer_filter; ?>',
        job_id: '<?php echo $job_filter; ?>',
        seeker_id: '<?php echo $seeker_filter; ?>',
        date_from: '<?php echo $date_from; ?>',
        date_to: '<?php echo $date_to; ?>',
        search: '<?php echo $search; ?>',
        sort: '<?php echo $sort; ?>'
    });
    
    window.location.href = 'export-applications.php?' + params.toString();
}

// Charts
<?php if ($view_mode === 'stats' && !empty($chart_labels)): ?>
// Status Distribution Chart
const ctx1 = document.getElementById('statusChart')?.getContext('2d');
if (ctx1) {
    new Chart(ctx1, {
        type: 'doughnut',
        data: {
            labels: <?php echo json_encode($chart_labels); ?>,
            datasets: [{
                data: <?php echo json_encode($chart_counts); ?>,
                backgroundColor: [
                    '#3b82f6', '#8b5cf6', '#6366f1', '#f59e0b', 
                    '#10b981', '#059669', '#ef4444'
                ],
                borderWidth: 0
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'right'
                }
            }
        }
    });
}

// Timeline Chart
<?php if (!empty($timeline_labels)): ?>
const ctx2 = document.getElementById('timelineChart')?.getContext('2d');
if (ctx2) {
    new Chart(ctx2, {
        type: 'line',
        data: {
            labels: <?php echo json_encode($timeline_labels); ?>,
            datasets: [
                {
                    label: 'Total Applications',
                    data: <?php echo json_encode($timeline_totals); ?>,
                    borderColor: '#4f46e5',
                    backgroundColor: 'rgba(79, 70, 229, 0.1)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4
                },
                {
                    label: 'Hired',
                    data: <?php echo json_encode($timeline_hired); ?>,
                    borderColor: '#10b981',
                    backgroundColor: 'rgba(16, 185, 129, 0.1)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom'
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        stepSize: 1
                    }
                }
            }
        }
    });
}
<?php endif; ?>
<?php endif; ?>

// Auto-hide flash message after 5 seconds
setTimeout(() => {
    const flashMessage = document.querySelector('.fixed.top-20.right-4');
    if (flashMessage) {
        flashMessage.style.transition = 'opacity 0.5s';
        flashMessage.style.opacity = '0';
        setTimeout(() => flashMessage?.remove(), 500);
    }
}, 5000);

// Keyboard shortcuts
document.addEventListener('keydown', (e) => {
    // Escape to close modals
    if (e.key === 'Escape') {
        closeViewModal();
        closeInterviewModal();
        closeDeleteModal();
        closeBulkUpdateModal();
        quickActionsDropdown?.classList.add('hidden');
        mobileSidebar?.classList.add('hidden');
    }
    
    // Ctrl/Cmd + F to focus search
    if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
        e.preventDefault();
        document.querySelector('input[name="search"]')?.focus();
    }
});

// Close dropdowns when clicking outside
document.addEventListener('click', (e) => {
    if (!e.target.closest('[id^="status-dropdown-"]') && !e.target.closest('.status-badge')) {
        document.querySelectorAll('[id^="status-dropdown-"]').forEach(dropdown => {
            dropdown.classList.add('hidden');
        });
    }
});
</script>
</body>
</html>