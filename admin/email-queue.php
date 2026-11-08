<?php
// admin/email-queue.php - Email Queue Management System
// Version: 1.0 - Complete email queue management with all actions

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

// Get admin info for header
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

// ===== HANDLE POST REQUESTS =====
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
        $_SESSION['error'] = 'Invalid security token';
        header('Location: email-queue.php');
        exit();
    }

    $action = $_POST['action'] ?? '';

    try {
        // Begin transaction for multiple operations
        beginTransaction();

        switch ($action) {
            case 'send_now':
                if (isset($_POST['email_ids'])) {
                    $emailIds = array_map('intval', $_POST['email_ids']);
                    $placeholders = implode(',', array_fill(0, count($emailIds), '?'));
                    
                    // Update emails to processing status
                    executeQuery("
                        UPDATE email_queue 
                        SET status = 'processing', last_attempt = NOW() 
                        WHERE id IN ($placeholders) AND status = 'pending'
                    ", $emailIds);
                    
                    $message = count($emailIds) . ' email(s) queued for immediate sending';
                    $messageType = 'success';
                }
                break;

            case 'resend_failed':
                if (isset($_POST['email_ids'])) {
                    $emailIds = array_map('intval', $_POST['email_ids']);
                    $placeholders = implode(',', array_fill(0, count($emailIds), '?'));
                    
                    // Reset failed emails to pending
                    executeQuery("
                        UPDATE email_queue 
                        SET status = 'pending', attempts = 0, last_attempt = NULL 
                        WHERE id IN ($placeholders) AND status = 'failed'
                    ", $emailIds);
                    
                    $message = count($emailIds) . ' failed email(s) reset for retry';
                    $messageType = 'success';
                }
                break;

            case 'delete_selected':
                if (isset($_POST['email_ids'])) {
                    $emailIds = array_map('intval', $_POST['email_ids']);
                    $placeholders = implode(',', array_fill(0, count($emailIds), '?'));
                    
                    executeQuery("DELETE FROM email_queue WHERE id IN ($placeholders)", $emailIds);
                    
                    $message = count($emailIds) . ' email(s) deleted successfully';
                    $messageType = 'success';
                }
                break;

            case 'delete_all_sent':
                executeQuery("DELETE FROM email_queue WHERE status = 'sent'");
                $message = 'All sent emails have been cleared';
                $messageType = 'success';
                break;

            case 'delete_all_failed':
                executeQuery("DELETE FROM email_queue WHERE status = 'failed'");
                $message = 'All failed emails have been cleared';
                $messageType = 'success';
                break;

            case 'retry_all_failed':
                executeQuery("
                    UPDATE email_queue 
                    SET status = 'pending', attempts = 0, last_attempt = NULL 
                    WHERE status = 'failed'
                ");
                $message = 'All failed emails have been reset for retry';
                $messageType = 'success';
                break;

            case 'add_test_email':
                $recipient = filter_var($_POST['recipient_email'] ?? '', FILTER_VALIDATE_EMAIL);
                $subject = sanitize($_POST['subject'] ?? 'Test Email');
                $body = $_POST['body'] ?? 'This is a test email from TransNow';
                $priority = in_array($_POST['priority'] ?? '', ['low', 'normal', 'high']) ? $_POST['priority'] : 'normal';

                if ($recipient) {
                    executeQuery("
                        INSERT INTO email_queue (recipient_email, subject, body, priority, status, created_at)
                        VALUES (?, ?, ?, ?, 'pending', NOW())
                    ", [$recipient, $subject, $body, $priority]);
                    
                    $message = 'Test email added to queue successfully';
                    $messageType = 'success';
                } else {
                    $message = 'Invalid recipient email address';
                    $messageType = 'error';
                }
                break;

            case 'bulk_action':
                $bulkAction = $_POST['bulk_action'] ?? '';
                if (isset($_POST['email_ids']) && !empty($_POST['email_ids'])) {
                    $emailIds = array_map('intval', $_POST['email_ids']);
                    $placeholders = implode(',', array_fill(0, count($emailIds), '?'));
                    
                    switch ($bulkAction) {
                        case 'send':
                            executeQuery("
                                UPDATE email_queue 
                                SET status = 'pending', attempts = 0 
                                WHERE id IN ($placeholders) AND status != 'sent'
                            ", $emailIds);
                            $message = 'Selected emails queued for sending';
                            break;
                            
                        case 'delete':
                            executeQuery("DELETE FROM email_queue WHERE id IN ($placeholders)", $emailIds);
                            $message = 'Selected emails deleted';
                            break;
                            
                        case 'retry':
                            executeQuery("
                                UPDATE email_queue 
                                SET status = 'pending', attempts = 0, last_attempt = NULL 
                                WHERE id IN ($placeholders) AND status = 'failed'
                            ", $emailIds);
                            $message = 'Selected failed emails reset';
                            break;
                    }
                    $messageType = 'success';
                }
                break;
        }

        commitTransaction();

    } catch (Exception $e) {
        rollbackTransaction();
        $message = 'Error: ' . $e->getMessage();
        $messageType = 'error';
        error_log("Email queue action failed: " . $e->getMessage());
    }
}

// Generate CSRF token
$csrf_token = generateCSRFToken();

// ===== FETCH EMAIL QUEUE STATISTICS =====
$stats = fetchOne("
    SELECT 
        COUNT(*) as total_emails,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing,
        SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent,
        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
        SUM(CASE WHEN priority = 'high' AND status = 'pending' THEN 1 ELSE 0 END) as high_priority,
        SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) as today_count,
        SUM(CASE WHEN attempts >= 3 AND status = 'failed' THEN 1 ELSE 0 END) as max_attempts_failed,
        MAX(created_at) as last_email_time
", []);

// ===== FETCH EMAILS WITH PAGINATION AND FILTERS =====
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Build filter conditions
$whereConditions = [];
$params = [];
$filterStatus = $_GET['status'] ?? '';
$filterPriority = $_GET['priority'] ?? '';
$search = trim($_GET['search'] ?? '');

if ($filterStatus && in_array($filterStatus, ['pending', 'processing', 'sent', 'failed'])) {
    $whereConditions[] = "status = ?";
    $params[] = $filterStatus;
}

if ($filterPriority && in_array($filterPriority, ['low', 'normal', 'high'])) {
    $whereConditions[] = "priority = ?";
    $params[] = $filterPriority;
}

if ($search) {
    $whereConditions[] = "(recipient_email LIKE ? OR subject LIKE ? OR body LIKE ?)";
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

$whereClause = !empty($whereConditions) ? "WHERE " . implode(" AND ", $whereConditions) : "";

// Get total count for pagination
$countQuery = "SELECT COUNT(*) as total FROM email_queue $whereClause";
$totalResult = fetchOne($countQuery, $params);
$totalEmails = $totalResult['total'] ?? 0;
$totalPages = ceil($totalEmails / $perPage);

// Fetch emails for current page
$emails = fetchAll("
    SELECT 
        *,
        CASE 
            WHEN status = 'pending' AND priority = 'high' THEN 1
            WHEN status = 'pending' AND priority = 'normal' THEN 2
            WHEN status = 'pending' AND priority = 'low' THEN 3
            WHEN status = 'processing' THEN 4
            WHEN status = 'failed' THEN 5
            WHEN status = 'sent' THEN 6
            ELSE 7
        END as sort_order,
        TIMESTAMPDIFF(MINUTE, created_at, NOW()) as age_minutes,
        CASE 
            WHEN attempts >= 3 AND status = 'failed' THEN 'danger'
            WHEN status = 'failed' THEN 'warning'
            WHEN status = 'pending' AND priority = 'high' THEN 'info'
            ELSE NULL
        END as alert_type
    FROM email_queue
    $whereClause
    ORDER BY 
        sort_order ASC,
        CASE WHEN status = 'pending' THEN priority END DESC,
        created_at ASC
    LIMIT ? OFFSET ?
", array_merge($params, [$perPage, $offset]));

// Get hourly stats for chart
$hourlyStats = fetchAll("
    SELECT 
        DATE_FORMAT(created_at, '%Y-%m-%d %H:00') as hour,
        COUNT(*) as total,
        SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent_count,
        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count
    FROM email_queue
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
    GROUP BY DATE_FORMAT(created_at, '%Y-%m-%d %H:00')
    ORDER BY hour ASC
", []);

// Prepare chart data
$chartLabels = [];
$chartSent = [];
$chartFailed = [];
$chartPending = [];

foreach ($hourlyStats as $stat) {
    $chartLabels[] = date('H:00', strtotime($stat['hour']));
    $chartSent[] = (int)$stat['sent_count'];
    $chartFailed[] = (int)$stat['failed_count'];
}

// Get daily stats for week
$dailyStats = fetchAll("
    SELECT 
        DATE(created_at) as date,
        COUNT(*) as total,
        SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent,
        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
    FROM email_queue
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    GROUP BY DATE(created_at)
    ORDER BY date ASC
", []);

$dailyLabels = [];
$dailySent = [];
$dailyFailed = [];

foreach ($dailyStats as $stat) {
    $dailyLabels[] = date('D', strtotime($stat['date']));
    $dailySent[] = (int)$stat['sent'];
    $dailyFailed[] = (int)$stat['failed'];
}

// Get top recipients
$topRecipients = fetchAll("
    SELECT 
        recipient_email,
        COUNT(*) as email_count,
        SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent_count,
        MAX(created_at) as last_email
    FROM email_queue
    GROUP BY recipient_email
    ORDER BY email_count DESC
    LIMIT 10
", []);

// Avatar URL for admin
$avatarUrl = 'https://ui-avatars.com/api/?name=' . urlencode('Admin') . '&background=4f46e5&color=fff&size=128&bold=true';
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Queue Management - TransNow Admin</title>
    
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
        
        @keyframes shimmer {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
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
        
        .status-pending { @apply bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300; }
        .status-processing { @apply bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300; }
        .status-sent { @apply bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300; }
        .status-failed { @apply bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300; }
        
        .priority-high { @apply bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300; }
        .priority-normal { @apply bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300; }
        .priority-low { @apply bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300; }
        
        /* Chart Container */
        .chart-container {
            position: relative;
            height: 250px;
            width: 100%;
        }
        
        /* DataTables Customization */
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
        
        /* Loading Skeleton */
        .skeleton {
            background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
            background-size: 200% 100%;
            animation: shimmer 1.5s infinite;
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
        
        /* Email Preview Modal */
        .modal-overlay {
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
        }
        
        .email-body-preview {
            max-height: 400px;
            overflow-y: auto;
            @apply bg-gray-50 dark:bg-gray-700 p-4 rounded-lg;
        }
        
        /* Tooltip */
        .tooltip {
            @apply invisible absolute;
        }
        
        .has-tooltip:hover .tooltip {
            @apply visible z-50;
        }
        
        /* Mobile Optimizations */
        @media (max-width: 768px) {
            .table-responsive {
                display: block;
                width: 100%;
                overflow-x: auto;
            }
        }
        
        /* Dark mode support */
        @media (prefers-color-scheme: dark) {
            .dark-mode-aware {
                background: #1f2937;
                color: #ffffff;
            }
        }
    </style>
</head>
<body class="bg-gray-50 dark:bg-gray-900 text-gray-900 dark:text-white transition-colors duration-300">

<!-- Custom Flash Message -->
<?php if ($message): ?>
<div class="fixed top-24 right-4 z-50 animate-slideIn" id="flashMessage">
    <div class="<?php echo $messageType === 'success' ? 'bg-green-500' : ($messageType === 'error' ? 'bg-red-500' : 'bg-blue-500'); ?> text-white px-6 py-4 rounded-xl shadow-2xl flex items-center space-x-3">
        <i class="fas <?php echo $messageType === 'success' ? 'fa-check-circle' : ($messageType === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle'); ?> text-xl"></i>
        <span><?php echo htmlspecialchars($message); ?></span>
        <button onclick="this.parentElement.parentElement.remove()" class="ml-4 hover:text-white/80">
            <i class="fas fa-times"></i>
        </button>
    </div>
</div>
<?php endif; ?>

<!-- Display session flash messages -->
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
                <a href="dashboard.php" class="flex items-center space-x-3 px-4 py-3 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                    <i class="fas fa-tachometer-alt w-5"></i>
                    <span>Dashboard</span>
                </a>
             
                <a href="jobs.php" class="flex items-center space-x-3 px-4 py-3 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                    <i class="fas fa-briefcase w-5"></i>
                    <span>Jobs</span>
                </a>
                
                <a href="applications.php" class="flex items-center space-x-3 px-4 py-3 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                    <i class="fas fa-file-alt w-5"></i>
                    <span>Applications</span>
                </a>
                
                <a href="categories.php" class="flex items-center space-x-3 px-4 py-3 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                    <i class="fas fa-tags w-5"></i>
                    <span>Categories</span>
                </a>
           
                <a href="reports.php" class="flex items-center space-x-3 px-4 py-3 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                    <i class="fas fa-chart-bar w-5"></i>
                    <span>Reports</span>
                </a>
                
                <a href="settings.php" class="flex items-center space-x-3 px-4 py-3 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                    <i class="fas fa-cog w-5"></i>
                    <span>Settings</span>
                </a>
                
                <!-- Email Queue - Active -->
                <a href="email-queue.php" class="flex items-center space-x-3 px-4 py-3 bg-indigo-50 dark:bg-indigo-900/20 text-indigo-600 dark:text-indigo-400 rounded-xl font-medium">
                    <i class="fas fa-envelope w-5"></i>
                    <span>Email Queue</span>
                    <?php if (($stats['pending'] ?? 0) > 0): ?>
                    <span class="ml-auto bg-red-500 text-white text-xs px-2 py-1 rounded-full animate-pulse"><?php echo $stats['pending']; ?></span>
                    <?php endif; ?>
                </a>
                
                <!-- Divider -->
                <div class="border-t border-gray-200 dark:border-gray-700 my-4"></div>
                
                <a href="/help-center.php" class="flex items-center space-x-3 px-4 py-3 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                    <i class="fas fa-question-circle w-5"></i>
                    <span>Help Center</span>
                </a>
                
                <a href="/logout.php" class="flex items-center space-x-3 px-4 py-3 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-xl transition">
                    <i class="fas fa-sign-out-alt w-5"></i>
                    <span>Logout</span>
                </a>
            </nav>
            
            <!-- Sidebar Footer -->
            <div class="p-4 border-t border-gray-200 dark:border-gray-700">
                <div class="bg-gradient-to-r from-indigo-500 to-purple-600 rounded-xl p-4 text-white">
                    <p class="text-sm opacity-90 mb-2">Email Queue Status</p>
                    <div class="flex items-center justify-between text-xs">
                        <span>Pending</span>
                        <span class="font-bold"><?php echo $stats['pending'] ?? 0; ?></span>
                    </div>
                    <div class="flex items-center justify-between text-xs mt-1">
                        <span>Processing</span>
                        <span class="font-bold"><?php echo $stats['processing'] ?? 0; ?></span>
                    </div>
                    <div class="flex items-center justify-between text-xs mt-1">
                        <span>Failed</span>
                        <span class="font-bold text-red-200"><?php echo $stats['failed'] ?? 0; ?></span>
                    </div>
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
                        Email <span class="gradient-text">Queue Management</span>
                    </h1>
                    
                    <!-- Right Actions -->
                    <div class="flex items-center space-x-4">
                        <!-- Queue Stats -->
                        <div class="hidden md:flex items-center space-x-3">
                            <div class="flex items-center space-x-1">
                                <span class="w-2 h-2 bg-yellow-500 rounded-full"></span>
                                <span class="text-sm"><?php echo $stats['pending'] ?? 0; ?> Pending</span>
                            </div>
                            <div class="flex items-center space-x-1">
                                <span class="w-2 h-2 bg-green-500 rounded-full"></span>
                                <span class="text-sm"><?php echo $stats['sent'] ?? 0; ?> Sent</span>
                            </div>
                            <div class="flex items-center space-x-1">
                                <span class="w-2 h-2 bg-red-500 rounded-full"></span>
                                <span class="text-sm"><?php echo $stats['failed'] ?? 0; ?> Failed</span>
                            </div>
                        </div>
                        
                        <!-- Quick Actions -->
                        <div class="relative">
                            <button id="quickActionsBtn" class="px-4 py-2 bg-gradient-to-r from-indigo-600 to-purple-600 text-white rounded-xl font-medium hover:shadow-lg transition-all duration-300 flex items-center space-x-2">
                                <i class="fas fa-bolt"></i>
                                <span>Quick Actions</span>
                                <i class="fas fa-chevron-down text-xs"></i>
                            </button>
                            
                            <!-- Quick Actions Dropdown -->
                            <div id="quickActionsDropdown" class="absolute right-0 mt-2 w-64 bg-white dark:bg-gray-800 rounded-2xl shadow-2xl border border-gray-200 dark:border-gray-700 hidden z-50">
                                <div class="p-2">
                                    <button onclick="openAddEmailModal()" class="w-full text-left flex items-center space-x-3 px-4 py-3 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                                        <i class="fas fa-plus-circle text-green-600 w-5"></i>
                                        <span>Add Test Email</span>
                                    </button>
                                    <button onclick="processBulkAction('retry_all_failed')" class="w-full text-left flex items-center space-x-3 px-4 py-3 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                                        <i class="fas fa-redo-alt text-yellow-600 w-5"></i>
                                        <span>Retry All Failed</span>
                                    </button>
                                    <button onclick="processBulkAction('delete_all_sent')" class="w-full text-left flex items-center space-x-3 px-4 py-3 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                                        <i class="fas fa-trash-alt text-red-600 w-5"></i>
                                        <span>Clear Sent Emails</span>
                                    </button>
                                    <button onclick="processBulkAction('delete_all_failed')" class="w-full text-left flex items-center space-x-3 px-4 py-3 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                                        <i class="fas fa-trash-alt text-red-600 w-5"></i>
                                        <span>Clear Failed Emails</span>
                                    </button>
                                    <div class="border-t border-gray-200 dark:border-gray-700 my-2"></div>
                                    <button onclick="window.location.href='email-queue.php'" class="w-full text-left flex items-center space-x-3 px-4 py-3 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                                        <i class="fas fa-sync-alt text-blue-600 w-5"></i>
                                        <span>Refresh Page</span>
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
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-6 mb-8">
                <!-- Total Emails -->
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6 hover-card" data-aos="fade-up" data-aos-delay="0">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-12 h-12 bg-indigo-100 dark:bg-indigo-900/20 rounded-xl flex items-center justify-center">
                            <i class="fas fa-envelope text-indigo-600 dark:text-indigo-400 text-xl"></i>
                        </div>
                        <span class="text-sm text-gray-500">Total Emails</span>
                    </div>
                    <h3 class="text-3xl font-bold mb-2"><?php echo number_format($stats['total_emails'] ?? 0); ?></h3>
                    <div class="flex items-center text-sm">
                        <span class="text-purple-500 flex items-center">
                            <i class="fas fa-calendar-day mr-1"></i> <?php echo $stats['today_count'] ?? 0; ?> today
                        </span>
                    </div>
                </div>

                <!-- Pending -->
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6 hover-card" data-aos="fade-up" data-aos-delay="50">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-12 h-12 bg-yellow-100 dark:bg-yellow-900/20 rounded-xl flex items-center justify-center">
                            <i class="fas fa-clock text-yellow-600 dark:text-yellow-400 text-xl"></i>
                        </div>
                        <span class="text-sm text-gray-500">Pending</span>
                    </div>
                    <h3 class="text-3xl font-bold mb-2"><?php echo $stats['pending'] ?? 0; ?></h3>
                    <div class="flex items-center text-sm">
                        <span class="text-red-500 flex items-center">
                            <i class="fas fa-exclamation-triangle mr-1"></i> <?php echo $stats['high_priority'] ?? 0; ?> high priority
                        </span>
                    </div>
                </div>

                <!-- Processing -->
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6 hover-card" data-aos="fade-up" data-aos-delay="100">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900/20 rounded-xl flex items-center justify-center">
                            <i class="fas fa-spinner fa-pulse text-blue-600 dark:text-blue-400 text-xl"></i>
                        </div>
                        <span class="text-sm text-gray-500">Processing</span>
                    </div>
                    <h3 class="text-3xl font-bold mb-2"><?php echo $stats['processing'] ?? 0; ?></h3>
                    <div class="flex items-center text-sm">
                        <span class="text-gray-500">Being sent now</span>
                    </div>
                </div>

                <!-- Sent -->
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6 hover-card" data-aos="fade-up" data-aos-delay="150">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-12 h-12 bg-green-100 dark:bg-green-900/20 rounded-xl flex items-center justify-center">
                            <i class="fas fa-check-circle text-green-600 dark:text-green-400 text-xl"></i>
                        </div>
                        <span class="text-sm text-gray-500">Sent</span>
                    </div>
                    <h3 class="text-3xl font-bold mb-2"><?php echo $stats['sent'] ?? 0; ?></h3>
                    <div class="flex items-center text-sm">
                        <span class="text-gray-500">Successfully delivered</span>
                    </div>
                </div>

                <!-- Failed -->
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6 hover-card" data-aos="fade-up" data-aos-delay="200">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-12 h-12 bg-red-100 dark:bg-red-900/20 rounded-xl flex items-center justify-center">
                            <i class="fas fa-exclamation-circle text-red-600 dark:text-red-400 text-xl"></i>
                        </div>
                        <span class="text-sm text-gray-500">Failed</span>
                    </div>
                    <h3 class="text-3xl font-bold mb-2"><?php echo $stats['failed'] ?? 0; ?></h3>
                    <div class="flex items-center text-sm">
                        <span class="text-red-500 flex items-center">
                            <i class="fas fa-exclamation-triangle mr-1"></i> <?php echo $stats['max_attempts_failed'] ?? 0; ?> max attempts
                        </span>
                    </div>
                </div>
            </div>

            <!-- Charts Row -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                <!-- Hourly Activity Chart -->
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6" data-aos="fade-up">
                    <div class="flex items-center justify-between mb-6">
                        <h2 class="text-xl font-bold">Email Activity (Last 24 Hours)</h2>
                        <span class="text-sm text-gray-500">Updates every hour</span>
                    </div>
                    <div class="chart-container">
                        <canvas id="hourlyChart"></canvas>
                    </div>
                </div>

                <!-- Daily Stats Chart -->
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6" data-aos="fade-up">
                    <div class="flex items-center justify-between mb-6">
                        <h2 class="text-xl font-bold">Daily Statistics (Last 7 Days)</h2>
                        <span class="text-sm text-gray-500">Sent vs Failed</span>
                    </div>
                    <div class="chart-container">
                        <canvas id="dailyChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Filters and Search -->
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6 mb-8" data-aos="fade-up">
                <form method="GET" action="" class="flex flex-wrap items-center gap-4">
                    <!-- Status Filter -->
                    <div>
                        <label class="block text-sm font-medium mb-1">Status</label>
                        <select name="status" class="px-4 py-2 bg-gray-100 dark:bg-gray-700 rounded-lg border-0 focus:ring-2 focus:ring-indigo-500">
                            <option value="">All Statuses</option>
                            <option value="pending" <?php echo $filterStatus === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="processing" <?php echo $filterStatus === 'processing' ? 'selected' : ''; ?>>Processing</option>
                            <option value="sent" <?php echo $filterStatus === 'sent' ? 'selected' : ''; ?>>Sent</option>
                            <option value="failed" <?php echo $filterStatus === 'failed' ? 'selected' : ''; ?>>Failed</option>
                        </select>
                    </div>

                    <!-- Priority Filter -->
                    <div>
                        <label class="block text-sm font-medium mb-1">Priority</label>
                        <select name="priority" class="px-4 py-2 bg-gray-100 dark:bg-gray-700 rounded-lg border-0 focus:ring-2 focus:ring-indigo-500">
                            <option value="">All Priorities</option>
                            <option value="high" <?php echo $filterPriority === 'high' ? 'selected' : ''; ?>>High</option>
                            <option value="normal" <?php echo $filterPriority === 'normal' ? 'selected' : ''; ?>>Normal</option>
                            <option value="low" <?php echo $filterPriority === 'low' ? 'selected' : ''; ?>>Low</option>
                        </select>
                    </div>

                    <!-- Search -->
                    <div class="flex-1 min-w-[300px]">
                        <label class="block text-sm font-medium mb-1">Search</label>
                        <div class="relative">
                            <i class="fas fa-search absolute left-4 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                            <input type="text" 
                                   name="search" 
                                   value="<?php echo htmlspecialchars($search); ?>"
                                   placeholder="Search by email, subject, or content..."
                                   class="w-full pl-12 pr-4 py-2 bg-gray-100 dark:bg-gray-700 rounded-lg border-0 focus:ring-2 focus:ring-indigo-500">
                        </div>
                    </div>

                    <!-- Filter Actions -->
                    <div class="flex items-end space-x-2">
                        <button type="submit" class="px-6 py-2 bg-gradient-to-r from-indigo-600 to-purple-600 text-white rounded-lg font-medium hover:shadow-lg transition-all">
                            <i class="fas fa-filter mr-2"></i> Apply Filters
                        </button>
                        <a href="email-queue.php" class="px-6 py-2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg font-medium hover:bg-gray-300 dark:hover:bg-gray-600 transition-all">
                            <i class="fas fa-times mr-2"></i> Clear
                        </a>
                    </div>
                </form>
            </div>

            <!-- Email Queue Table -->
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6" data-aos="fade-up">
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-xl font-bold">Email Queue</h2>
                    <div class="flex items-center space-x-2">
                        <span class="text-sm text-gray-500">Total: <?php echo $totalEmails; ?> emails</span>
                        
                        <!-- Bulk Actions Dropdown -->
                        <div class="relative">
                            <button id="bulkActionsBtn" class="px-4 py-2 bg-gray-200 dark:bg-gray-700 rounded-lg font-medium hover:bg-gray-300 dark:hover:bg-gray-600 transition-all flex items-center space-x-2">
                                <i class="fas fa-tasks"></i>
                                <span>Bulk Actions</span>
                                <i class="fas fa-chevron-down text-xs"></i>
                            </button>
                            
                            <div id="bulkActionsDropdown" class="absolute right-0 mt-2 w-48 bg-white dark:bg-gray-800 rounded-2xl shadow-2xl border border-gray-200 dark:border-gray-700 hidden z-50">
                                <div class="p-2">
                                    <button onclick="processSelected('send')" class="w-full text-left flex items-center space-x-3 px-4 py-3 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                                        <i class="fas fa-paper-plane text-blue-600 w-5"></i>
                                        <span>Send Selected</span>
                                    </button>
                                    <button onclick="processSelected('retry')" class="w-full text-left flex items-center space-x-3 px-4 py-3 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                                        <i class="fas fa-redo-alt text-yellow-600 w-5"></i>
                                        <span>Retry Selected</span>
                                    </button>
                                    <button onclick="processSelected('delete')" class="w-full text-left flex items-center space-x-3 px-4 py-3 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                                        <i class="fas fa-trash-alt text-red-600 w-5"></i>
                                        <span>Delete Selected</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <?php if (empty($emails)): ?>
                <div class="text-center py-20">
                    <div class="w-24 h-24 bg-gray-100 dark:bg-gray-700 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-envelope-open-text text-3xl text-gray-400"></i>
                    </div>
                    <h3 class="text-xl font-semibold mb-2">No emails in queue</h3>
                    <p class="text-gray-500 mb-6">The email queue is currently empty</p>
                    <button onclick="openAddEmailModal()" class="inline-flex items-center space-x-2 bg-gradient-to-r from-indigo-600 to-purple-600 text-white px-6 py-3 rounded-xl font-semibold hover:shadow-lg transition-all">
                        <i class="fas fa-plus-circle"></i>
                        <span>Add Test Email</span>
                    </button>
                </div>
                <?php else: ?>
                <form id="bulkActionForm" method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="bulk_action" id="bulkActionInput">
                    <input type="hidden" name="bulk_action" value="" id="bulkActionType">
                    
                    <div class="table-responsive">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead>
                                <tr>
                                    <th class="px-4 py-3 text-left">
                                        <input type="checkbox" id="selectAll" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                    </th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status/Priority</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Recipient</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Subject</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Created</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Attempts</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                <?php foreach ($emails as $email): ?>
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50 transition <?php echo $email['alert_type'] === 'danger' ? 'bg-red-50 dark:bg-red-900/10' : ''; ?>" data-email-id="<?php echo $email['id']; ?>">
                                    <td class="px-4 py-3">
                                        <input type="checkbox" name="email_ids[]" value="<?php echo $email['id']; ?>" class="email-checkbox rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="flex flex-col space-y-1">
                                            <span class="status-badge status-<?php echo $email['status']; ?>">
                                                <i class="fas <?php 
                                                    echo $email['status'] === 'pending' ? 'fa-clock' : 
                                                        ($email['status'] === 'processing' ? 'fa-spinner fa-spin' : 
                                                        ($email['status'] === 'sent' ? 'fa-check-circle' : 'fa-exclamation-circle')); 
                                                ?> mr-1"></i>
                                                <?php echo ucfirst($email['status']); ?>
                                            </span>
                                            <span class="status-badge priority-<?php echo $email['priority']; ?> text-xs">
                                                <i class="fas fa-flag mr-1"></i>
                                                <?php echo ucfirst($email['priority']); ?> Priority
                                            </span>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="flex items-center">
                                            <i class="fas fa-user-circle text-gray-400 mr-2"></i>
                                            <span class="font-medium"><?php echo htmlspecialchars($email['recipient_email']); ?></span>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="max-w-xs">
                                            <p class="font-medium truncate"><?php echo htmlspecialchars($email['subject']); ?></p>
                                            <p class="text-xs text-gray-500 truncate"><?php echo htmlspecialchars(substr($email['body'] ?? '', 0, 50)) . '...'; ?></p>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="flex flex-col">
                                            <span class="text-sm"><?php echo date('M j, Y', strtotime($email['created_at'])); ?></span>
                                            <span class="text-xs text-gray-500"><?php echo date('g:i A', strtotime($email['created_at'])); ?></span>
                                            <span class="text-xs text-gray-400">(<?php echo $email['age_minutes']; ?> min ago)</span>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="text-center">
                                            <span class="font-semibold"><?php echo $email['attempts'] ?? 0; ?></span>
                                            <?php if (($email['attempts'] ?? 0) >= 3 && $email['status'] === 'failed'): ?>
                                            <i class="fas fa-exclamation-triangle text-red-500 ml-1" title="Max attempts reached"></i>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="flex items-center space-x-2">
                                            <!-- View Details -->
                                            <button onclick="viewEmailDetails(<?php echo htmlspecialchars(json_encode($email)); ?>)" 
                                                    class="p-2 bg-blue-100 dark:bg-blue-900 text-blue-600 dark:text-blue-400 rounded-lg hover:bg-blue-200 dark:hover:bg-blue-800 transition group relative has-tooltip"
                                                    title="View Details">
                                                <i class="fas fa-eye"></i>
                                                <span class="tooltip bg-gray-800 text-white text-xs rounded py-1 px-2 right-0 -top-8">View Details</span>
                                            </button>
                                            
                                            <!-- Resend (for failed emails) -->
                                            <?php if ($email['status'] === 'failed'): ?>
                                            <form method="POST" action="" class="inline">
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                <input type="hidden" name="action" value="resend_failed">
                                                <input type="hidden" name="email_ids[]" value="<?php echo $email['id']; ?>">
                                                <button type="submit" 
                                                        class="p-2 bg-yellow-100 dark:bg-yellow-900 text-yellow-600 dark:text-yellow-400 rounded-lg hover:bg-yellow-200 dark:hover:bg-yellow-800 transition group relative has-tooltip"
                                                        title="Resend Email">
                                                    <i class="fas fa-redo-alt"></i>
                                                    <span class="tooltip bg-gray-800 text-white text-xs rounded py-1 px-2 right-0 -top-8">Resend</span>
                                                </button>
                                            </form>
                                            <?php endif; ?>
                                            
                                            <!-- Send Now (for pending emails) -->
                                            <?php if ($email['status'] === 'pending'): ?>
                                            <form method="POST" action="" class="inline">
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                <input type="hidden" name="action" value="send_now">
                                                <input type="hidden" name="email_ids[]" value="<?php echo $email['id']; ?>">
                                                <button type="submit" 
                                                        class="p-2 bg-green-100 dark:bg-green-900 text-green-600 dark:text-green-400 rounded-lg hover:bg-green-200 dark:hover:bg-green-800 transition group relative has-tooltip"
                                                        title="Send Now">
                                                    <i class="fas fa-paper-plane"></i>
                                                    <span class="tooltip bg-gray-800 text-white text-xs rounded py-1 px-2 right-0 -top-8">Send Now</span>
                                                </button>
                                            </form>
                                            <?php endif; ?>
                                            
                                            <!-- Delete -->
                                            <form method="POST" action="" class="inline" onsubmit="return confirm('Are you sure you want to delete this email?');">
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                <input type="hidden" name="action" value="delete_selected">
                                                <input type="hidden" name="email_ids[]" value="<?php echo $email['id']; ?>">
                                                <button type="submit" 
                                                        class="p-2 bg-red-100 dark:bg-red-900 text-red-600 dark:text-red-400 rounded-lg hover:bg-red-200 dark:hover:bg-red-800 transition group relative has-tooltip"
                                                        title="Delete">
                                                    <i class="fas fa-trash-alt"></i>
                                                    <span class="tooltip bg-gray-800 text-white text-xs rounded py-1 px-2 right-0 -top-8">Delete</span>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </form>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                <div class="flex items-center justify-between mt-6">
                    <div class="text-sm text-gray-500">
                        Showing <?php echo $offset + 1; ?> to <?php echo min($offset + $perPage, $totalEmails); ?> of <?php echo $totalEmails; ?> emails
                    </div>
                    <div class="flex space-x-2">
                        <?php if ($page > 1): ?>
                        <a href="?page=<?php echo $page - 1; ?>&status=<?php echo urlencode($filterStatus); ?>&priority=<?php echo urlencode($filterPriority); ?>&search=<?php echo urlencode($search); ?>" 
                           class="px-4 py-2 bg-gray-200 dark:bg-gray-700 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                        <?php endif; ?>
                        
                        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                        <a href="?page=<?php echo $i; ?>&status=<?php echo urlencode($filterStatus); ?>&priority=<?php echo urlencode($filterPriority); ?>&search=<?php echo urlencode($search); ?>" 
                           class="px-4 py-2 <?php echo $i === $page ? 'bg-indigo-600 text-white' : 'bg-gray-200 dark:bg-gray-700 hover:bg-gray-300 dark:hover:bg-gray-600'; ?> rounded-lg transition">
                            <?php echo $i; ?>
                        </a>
                        <?php endfor; ?>
                        
                        <?php if ($page < $totalPages): ?>
                        <a href="?page=<?php echo $page + 1; ?>&status=<?php echo urlencode($filterStatus); ?>&priority=<?php echo urlencode($filterPriority); ?>&search=<?php echo urlencode($search); ?>" 
                           class="px-4 py-2 bg-gray-200 dark:bg-gray-700 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>

            <!-- Top Recipients -->
            <?php if (!empty($topRecipients)): ?>
            <div class="mt-8 grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6" data-aos="fade-up">
                    <h2 class="text-xl font-bold mb-6">Top Recipients</h2>
                    <div class="space-y-4">
                        <?php foreach ($topRecipients as $recipient): ?>
                        <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-700/50 rounded-xl">
                            <div class="flex items-center space-x-3">
                                <div class="w-10 h-10 bg-gradient-to-r from-indigo-500 to-purple-500 rounded-lg flex items-center justify-center text-white">
                                    <i class="fas fa-user"></i>
                                </div>
                                <div>
                                    <p class="font-semibold"><?php echo htmlspecialchars($recipient['recipient_email']); ?></p>
                                    <p class="text-xs text-gray-500">Last: <?php echo timeAgo($recipient['last_email']); ?></p>
                                </div>
                            </div>
                            <div class="text-right">
                                <span class="font-bold text-xl"><?php echo $recipient['email_count']; ?></span>
                                <span class="text-sm text-gray-500 ml-1">emails</span>
                                <p class="text-xs text-green-500"><?php echo $recipient['sent_count']; ?> sent</p>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Queue Health -->
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6" data-aos="fade-up">
                    <h2 class="text-xl font-bold mb-6">Queue Health</h2>
                    <div class="space-y-4">
                        <div>
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-sm">Success Rate</span>
                                <span class="text-sm font-semibold">
                                    <?php 
                                    $total = ($stats['sent'] ?? 0) + ($stats['failed'] ?? 0);
                                    $successRate = $total > 0 ? round(($stats['sent'] ?? 0) / $total * 100, 1) : 100;
                                    echo $successRate . '%';
                                    ?>
                                </span>
                            </div>
                            <div class="w-full h-3 bg-gray-200 dark:bg-gray-700 rounded-full overflow-hidden">
                                <div class="progress-bar h-full rounded-full" style="width: <?php echo $successRate; ?>%"></div>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-4 mt-4">
                            <div class="p-4 bg-green-50 dark:bg-green-900/20 rounded-xl">
                                <p class="text-sm text-gray-600 dark:text-gray-400 mb-1">Success Rate</p>
                                <p class="text-2xl font-bold text-green-600"><?php echo $stats['sent'] ?? 0; ?></p>
                                <p class="text-xs text-gray-500">Total sent</p>
                            </div>
                            <div class="p-4 bg-red-50 dark:bg-red-900/20 rounded-xl">
                                <p class="text-sm text-gray-600 dark:text-gray-400 mb-1">Failure Rate</p>
                                <p class="text-2xl font-bold text-red-600"><?php echo $stats['failed'] ?? 0; ?></p>
                                <p class="text-xs text-gray-500">Total failed</p>
                            </div>
                        </div>

                        <div class="mt-4 p-4 bg-indigo-50 dark:bg-indigo-900/20 rounded-xl">
                            <p class="text-sm text-gray-600 dark:text-gray-400 mb-2">Queue Processing</p>
                            <p class="text-sm">
                                <i class="fas fa-clock text-indigo-500 mr-2"></i>
                                Last email: <?php echo $stats['last_email_time'] ? timeAgo($stats['last_email_time']) : 'No emails'; ?>
                            </p>
                            <p class="text-sm mt-2">
                                <i class="fas fa-tachometer-alt text-indigo-500 mr-2"></i>
                                Average processing time: ~2-5 minutes
                            </p>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<!-- Mobile Sidebar -->
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
                    <a href="applications.php" class="block px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">Applications</a>
                    <a href="categories.php" class="block px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">Categories</a>
                    <a href="job-types.php" class="block px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">Job Types</a>
                    <a href="reports.php" class="block px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">Reports</a>
                    <a href="settings.php" class="block px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">Settings</a>
                    <a href="email-queue.php" class="block px-4 py-2 bg-indigo-50 dark:bg-indigo-900/20 text-indigo-600 rounded-lg">Email Queue</a>
                    <a href="/logout.php" class="block px-4 py-2 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg">Logout</a>
                </nav>
            </div>
        </div>
    </div>
</div>

<!-- Email Details Modal -->
<div id="emailDetailsModal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeEmailModal()"></div>
    <div class="absolute top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2 w-full max-w-3xl bg-white dark:bg-gray-800 rounded-2xl shadow-2xl p-6">
        <div class="flex items-center justify-between mb-6">
            <h3 class="text-2xl font-bold">Email Details</h3>
            <button onclick="closeEmailModal()" class="w-10 h-10 bg-gray-100 dark:bg-gray-700 rounded-lg flex items-center justify-center hover:bg-gray-200 dark:hover:bg-gray-600 transition">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <div class="space-y-4" id="emailDetailsContent">
            <!-- Content will be populated by JavaScript -->
        </div>
        
        <div class="mt-6 flex justify-end space-x-3">
            <button onclick="closeEmailModal()" class="px-6 py-2 border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 transition">
                Close
            </button>
        </div>
    </div>
</div>

<!-- Add Test Email Modal -->
<div id="addEmailModal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeAddEmailModal()"></div>
    <div class="absolute top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2 w-full max-w-2xl bg-white dark:bg-gray-800 rounded-2xl shadow-2xl p-6">
        <div class="flex items-center justify-between mb-6">
            <h3 class="text-2xl font-bold">Add Test Email</h3>
            <button onclick="closeAddEmailModal()" class="w-10 h-10 bg-gray-100 dark:bg-gray-700 rounded-lg flex items-center justify-center hover:bg-gray-200 dark:hover:bg-gray-600 transition">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <form method="POST" action="" id="addEmailForm">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="add_test_email">
            
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium mb-2">Recipient Email *</label>
                    <input type="email" 
                           name="recipient_email" 
                           required
                           placeholder="test@example.com"
                           class="w-full px-4 py-3 bg-gray-100 dark:bg-gray-700 rounded-xl border-0 focus:ring-2 focus:ring-indigo-500">
                </div>
                
                <div>
                    <label class="block text-sm font-medium mb-2">Subject *</label>
                    <input type="text" 
                           name="subject" 
                           required
                           placeholder="Email subject"
                           class="w-full px-4 py-3 bg-gray-100 dark:bg-gray-700 rounded-xl border-0 focus:ring-2 focus:ring-indigo-500">
                </div>
                
                <div>
                    <label class="block text-sm font-medium mb-2">Priority</label>
                    <select name="priority" class="w-full px-4 py-3 bg-gray-100 dark:bg-gray-700 rounded-xl border-0 focus:ring-2 focus:ring-indigo-500">
                        <option value="low">Low</option>
                        <option value="normal" selected>Normal</option>
                        <option value="high">High</option>
                    </select>
                </div>
                
                <div>
                    <label class="block text-sm font-medium mb-2">Email Body *</label>
                    <textarea name="body" 
                              rows="6"
                              required
                              placeholder="Email content..."
                              class="w-full px-4 py-3 bg-gray-100 dark:bg-gray-700 rounded-xl border-0 focus:ring-2 focus:ring-indigo-500"></textarea>
                </div>
            </div>
            
            <div class="mt-6 flex justify-end space-x-3">
                <button type="button" onclick="closeAddEmailModal()" class="px-6 py-2 border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 transition">
                    Cancel
                </button>
                <button type="submit" class="px-6 py-2 bg-gradient-to-r from-indigo-600 to-purple-600 text-white rounded-lg font-semibold hover:shadow-lg transition-all">
                    <i class="fas fa-plus-circle mr-2"></i>Add to Queue
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

<script>
// Initialize AOS
AOS.init({
    duration: 800,
    once: true,
    offset: 50,
    easing: 'ease-out-cubic'
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

// Hourly Activity Chart
const hourlyCtx = document.getElementById('hourlyChart')?.getContext('2d');
if (hourlyCtx) {
    new Chart(hourlyCtx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode($chartLabels); ?>,
            datasets: [
                {
                    label: 'Sent',
                    data: <?php echo json_encode($chartSent); ?>,
                    borderColor: '#10b981',
                    backgroundColor: 'rgba(16, 185, 129, 0.1)',
                    borderWidth: 2,
                    tension: 0.4,
                    fill: true
                },
                {
                    label: 'Failed',
                    data: <?php echo json_encode($chartFailed); ?>,
                    borderColor: '#ef4444',
                    backgroundColor: 'rgba(239, 68, 68, 0.1)',
                    borderWidth: 2,
                    tension: 0.4,
                    fill: true
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: true,
                    position: 'top'
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

// Daily Chart
const dailyCtx = document.getElementById('dailyChart')?.getContext('2d');
if (dailyCtx) {
    new Chart(dailyCtx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($dailyLabels); ?>,
            datasets: [
                {
                    label: 'Sent',
                    data: <?php echo json_encode($dailySent); ?>,
                    backgroundColor: '#10b981',
                    borderRadius: 6
                },
                {
                    label: 'Failed',
                    data: <?php echo json_encode($dailyFailed); ?>,
                    backgroundColor: '#ef4444',
                    borderRadius: 6
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: true,
                    position: 'top'
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

// Select All Checkboxes
const selectAll = document.getElementById('selectAll');
if (selectAll) {
    selectAll.addEventListener('change', function() {
        const checkboxes = document.querySelectorAll('.email-checkbox');
        checkboxes.forEach(checkbox => {
            checkbox.checked = this.checked;
        });
    });
}

// Bulk Actions Dropdown
const bulkActionsBtn = document.getElementById('bulkActionsBtn');
const bulkActionsDropdown = document.getElementById('bulkActionsDropdown');

if (bulkActionsBtn && bulkActionsDropdown) {
    bulkActionsBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        bulkActionsDropdown.classList.toggle('hidden');
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
    if (bulkActionsBtn && !bulkActionsBtn.contains(e.target)) {
        bulkActionsDropdown?.classList.add('hidden');
    }
    if (quickActionsBtn && !quickActionsBtn.contains(e.target)) {
        quickActionsDropdown?.classList.add('hidden');
    }
});

// Process selected emails
function processSelected(action) {
    const checkboxes = document.querySelectorAll('.email-checkbox:checked');
    if (checkboxes.length === 0) {
        alert('Please select at least one email');
        return;
    }
    
    let confirmMessage = '';
    switch(action) {
        case 'send':
            confirmMessage = 'Send selected emails now?';
            break;
        case 'retry':
            confirmMessage = 'Retry selected failed emails?';
            break;
        case 'delete':
            confirmMessage = 'Delete selected emails? This action cannot be undone.';
            break;
    }
    
    if (confirm(confirmMessage)) {
        document.getElementById('bulkActionType').value = action;
        document.getElementById('bulkActionForm').submit();
    }
}

// Process bulk actions
function processBulkAction(action) {
    let confirmMessage = '';
    switch(action) {
        case 'retry_all_failed':
            confirmMessage = 'Retry all failed emails?';
            break;
        case 'delete_all_sent':
            confirmMessage = 'Delete all sent emails? This action cannot be undone.';
            break;
        case 'delete_all_failed':
            confirmMessage = 'Delete all failed emails? This action cannot be undone.';
            break;
    }
    
    if (confirm(confirmMessage)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="${action}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// View email details
function viewEmailDetails(email) {
    const modal = document.getElementById('emailDetailsModal');
    const content = document.getElementById('emailDetailsContent');
    
    const statusClass = email.status === 'sent' ? 'text-green-600' : 
                        (email.status === 'failed' ? 'text-red-600' : 
                        (email.status === 'processing' ? 'text-blue-600' : 'text-yellow-600'));
    
    content.innerHTML = `
        <div class="grid grid-cols-2 gap-4 p-4 bg-gray-50 dark:bg-gray-700 rounded-xl">
            <div>
                <p class="text-sm text-gray-500 mb-1">Status</p>
                <p class="font-semibold ${statusClass}">
                    <i class="fas ${email.status === 'pending' ? 'fa-clock' : 
                                     (email.status === 'processing' ? 'fa-spinner fa-spin' : 
                                     (email.status === 'sent' ? 'fa-check-circle' : 'fa-exclamation-circle'))} mr-2"></i>
                    ${email.status.charAt(0).toUpperCase() + email.status.slice(1)}
                </p>
            </div>
            <div>
                <p class="text-sm text-gray-500 mb-1">Priority</p>
                <p class="font-semibold">${email.priority.charAt(0).toUpperCase() + email.priority.slice(1)}</p>
            </div>
            <div>
                <p class="text-sm text-gray-500 mb-1">Created At</p>
                <p class="font-semibold">${new Date(email.created_at).toLocaleString()}</p>
            </div>
            <div>
                <p class="text-sm text-gray-500 mb-1">Attempts</p>
                <p class="font-semibold">${email.attempts || 0}</p>
            </div>
        </div>
        
        <div class="p-4 bg-gray-50 dark:bg-gray-700 rounded-xl">
            <p class="text-sm text-gray-500 mb-2">Recipient</p>
            <p class="font-semibold text-lg">${email.recipient_email}</p>
        </div>
        
        <div class="p-4 bg-gray-50 dark:bg-gray-700 rounded-xl">
            <p class="text-sm text-gray-500 mb-2">Subject</p>
            <p class="font-semibold">${email.subject}</p>
        </div>
        
        <div class="p-4 bg-gray-50 dark:bg-gray-700 rounded-xl">
            <p class="text-sm text-gray-500 mb-2">Email Body</p>
            <div class="email-body-preview">
                ${email.body.replace(/\n/g, '<br>')}
            </div>
        </div>
        
        ${email.last_attempt ? `
        <div class="p-4 bg-gray-50 dark:bg-gray-700 rounded-xl">
            <p class="text-sm text-gray-500 mb-2">Last Attempt</p>
            <p>${new Date(email.last_attempt).toLocaleString()}</p>
        </div>
        ` : ''}
        
        ${email.sent_at ? `
        <div class="p-4 bg-gray-50 dark:bg-gray-700 rounded-xl">
            <p class="text-sm text-gray-500 mb-2">Sent At</p>
            <p>${new Date(email.sent_at).toLocaleString()}</p>
        </div>
        ` : ''}
    `;
    
    modal.classList.remove('hidden');
}

function closeEmailModal() {
    document.getElementById('emailDetailsModal').classList.add('hidden');
}

function openAddEmailModal() {
    document.getElementById('addEmailModal').classList.remove('hidden');
}

function closeAddEmailModal() {
    document.getElementById('addEmailModal').classList.add('hidden');
}

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

// Auto-hide flash message after 5 seconds
setTimeout(() => {
    const flashMessage = document.getElementById('flashMessage');
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
        closeEmailModal();
        closeAddEmailModal();
        quickActionsDropdown?.classList.add('hidden');
        bulkActionsDropdown?.classList.add('hidden');
        mobileSidebar?.classList.add('hidden');
    }
    
    // Ctrl/Cmd + A to select all
    if ((e.ctrlKey || e.metaKey) && e.key === 'a') {
        e.preventDefault();
        if (selectAll) {
            selectAll.click();
        }
    }
});

// Refresh data periodically (every 30 seconds for pending count)
setInterval(() => {
    // You could implement AJAX refresh here
    console.log('Refreshing data...');
}, 30000);
</script>
</body>
</html>
