<?php
// admin/interviews.php - Enhanced Admin Interview Management
// Version: 1.0 - Complete interview management system

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

// Handle actions
$action = $_GET['action'] ?? '';
$message = '';
$error = '';

// Process actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $interview_id = $_POST['interview_id'] ?? 0;
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    // Verify CSRF token
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf_token)) {
        $error = 'Invalid security token';
    } else {
        if ($action === 'create' || $action === 'edit') {
            // Validate required fields
            $application_id = intval($_POST['application_id'] ?? 0);
            $interview_date = $_POST['interview_date'] ?? '';
            $interview_type = $_POST['interview_type'] ?? 'video';
            $duration_minutes = intval($_POST['duration_minutes'] ?? 30);
            $timezone = $_POST['timezone'] ?? date_default_timezone_get();
            $status = $_POST['status'] ?? 'scheduled';
            
            // Validate interviewers
            $interviewer_name = trim($_POST['interviewer_name'] ?? '');
            $interviewer_email = trim($_POST['interviewer_email'] ?? '');
            
            // Validate meeting details
            $meeting_link = trim($_POST['meeting_link'] ?? '');
            $location = trim($_POST['location'] ?? '');
            $notes = trim($_POST['notes'] ?? '');
            
            $errors = [];
            
            if (!$application_id) {
                $errors[] = 'Please select an application';
            }
            if (!$interview_date) {
                $errors[] = 'Interview date is required';
            }
            if (!$interviewer_name) {
                $errors[] = 'Interviewer name is required';
            }
            if (!$interviewer_email || !filter_var($interviewer_email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Valid interviewer email is required';
            }
            
            if (empty($errors)) {
                if ($action === 'create') {
                    // Check if interview already exists for this application
                    $existing = fetchOne("
                        SELECT id FROM interviews 
                        WHERE application_id = ? 
                        AND status IN ('scheduled', 'confirmed')
                    ", [$application_id]);
                    
                    if ($existing) {
                        $error = 'An active interview already exists for this application';
                    } else {
                        // Create new interview
                        $result = executeQuery("
                            INSERT INTO interviews (
                                application_id, interview_type, interview_date, 
                                duration_minutes, timezone, meeting_link, location,
                                interviewer_name, interviewer_email, notes, status,
                                created_at
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                        ", [
                            $application_id, $interview_type, $interview_date,
                            $duration_minutes, $timezone, $meeting_link, $location,
                            $interviewer_name, $interviewer_email, $notes, $status
                        ]);
                        
                        if ($result) {
                            $new_id = getLastInsertId();
                            
                            // Create notification for seeker
                            $appData = fetchOne("
                                SELECT a.seeker_id, u.id as user_id, j.title as job_title
                                FROM applications a
                                INNER JOIN jobs j ON a.job_id = j.id
                                INNER JOIN users u ON a.seeker_id = (
                                    SELECT id FROM seekers WHERE id = a.seeker_id
                                ) AND u.user_type = 'seeker'
                                WHERE a.id = ?
                            ", [$application_id]);
                            
                            if ($appData) {
                                createNotification(
                                    $appData['user_id'],
                                    'interview',
                                    'Interview Scheduled',
                                    "Your interview for {$appData['job_title']} has been scheduled for " . date('M j, Y g:i A', strtotime($interview_date))
                                );
                            }
                            
                            $_SESSION['success'] = 'Interview created successfully';
                            header('Location: interviews.php');
                            exit();
                        } else {
                            $error = 'Failed to create interview';
                        }
                    }
                } elseif ($action === 'edit' && $interview_id) {
                    // Update interview
                    $result = executeQuery("
                        UPDATE interviews SET
                            interview_type = ?,
                            interview_date = ?,
                            duration_minutes = ?,
                            timezone = ?,
                            meeting_link = ?,
                            location = ?,
                            interviewer_name = ?,
                            interviewer_email = ?,
                            notes = ?,
                            status = ?,
                            updated_at = NOW()
                        WHERE id = ?
                    ", [
                        $interview_type, $interview_date, $duration_minutes,
                        $timezone, $meeting_link, $location, $interviewer_name,
                        $interviewer_email, $notes, $status, $interview_id
                    ]);
                    
                    if ($result) {
                        $_SESSION['success'] = 'Interview updated successfully';
                        header('Location: interviews.php');
                        exit();
                    } else {
                        $error = 'Failed to update interview';
                    }
                }
            } else {
                $error = implode('<br>', $errors);
            }
        } elseif ($action === 'cancel' && $interview_id) {
            // Cancel interview
            $result = executeQuery("
                UPDATE interviews SET 
                    status = 'cancelled',
                    updated_at = NOW()
                WHERE id = ?
            ", [$interview_id]);
            
            if ($result) {
                // Get interview details for notification
                $interview = fetchOne("
                    SELECT i.*, a.seeker_id, j.title as job_title
                    FROM interviews i
                    INNER JOIN applications a ON i.application_id = a.id
                    INNER JOIN jobs j ON a.job_id = j.id
                    WHERE i.id = ?
                ", [$interview_id]);
                
                if ($interview) {
                    $userData = fetchOne("
                        SELECT u.id as user_id
                        FROM users u
                        INNER JOIN seekers s ON u.id = s.user_id
                        WHERE s.id = ?
                    ", [$interview['seeker_id']]);
                    
                    if ($userData) {
                        createNotification(
                            $userData['user_id'],
                            'interview',
                            'Interview Cancelled',
                            "Your interview for {$interview['job_title']} has been cancelled."
                        );
                    }
                }
                
                $_SESSION['success'] = 'Interview cancelled successfully';
            } else {
                $error = 'Failed to cancel interview';
            }
            header('Location: interviews.php');
            exit();
        } elseif ($action === 'complete' && $interview_id) {
            // Mark interview as completed with feedback
            $feedback_notes = trim($_POST['feedback_notes'] ?? '');
            $rating = intval($_POST['rating'] ?? 0);
            
            $result = executeQuery("
                UPDATE interviews SET 
                    status = 'completed',
                    feedback_notes = ?,
                    rating = ?,
                    updated_at = NOW()
                WHERE id = ?
            ", [$feedback_notes, $rating, $interview_id]);
            
            if ($result) {
                $_SESSION['success'] = 'Interview marked as completed';
            } else {
                $error = 'Failed to update interview';
            }
            header('Location: interviews.php');
            exit();
        } elseif ($action === 'bulk') {
            $selected_ids = $_POST['selected_ids'] ?? [];
            $bulk_action = $_POST['bulk_action'] ?? '';
            
            if (!empty($selected_ids) && in_array($bulk_action, ['cancel', 'reschedule', 'delete'])) {
                $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
                
                if ($bulk_action === 'cancel') {
                    executeQuery("
                        UPDATE interviews SET 
                            status = 'cancelled',
                            updated_at = NOW()
                        WHERE id IN ($placeholders)
                    ", $selected_ids);
                    $_SESSION['success'] = 'Selected interviews cancelled';
                } elseif ($bulk_action === 'delete') {
                    executeQuery("
                        DELETE FROM interviews 
                        WHERE id IN ($placeholders)
                    ", $selected_ids);
                    $_SESSION['success'] = 'Selected interviews deleted';
                }
            }
            header('Location: interviews.php');
            exit();
        }
    }
}

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Get filter parameters
$status_filter = $_GET['status'] ?? '';
$type_filter = $_GET['type'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$search = trim($_GET['search'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Build WHERE clause for interviews
$where_conditions = ["1=1"];
$params = [];

if ($status_filter) {
    $where_conditions[] = "i.status = ?";
    $params[] = $status_filter;
}

if ($type_filter) {
    $where_conditions[] = "i.interview_type = ?";
    $params[] = $type_filter;
}

if ($date_from) {
    $where_conditions[] = "DATE(i.interview_date) >= ?";
    $params[] = $date_from;
}

if ($date_to) {
    $where_conditions[] = "DATE(i.interview_date) <= ?";
    $params[] = $date_to;
}

if ($search) {
    $where_conditions[] = "(j.title LIKE ? OR e.company_name LIKE ? OR CONCAT(s.first_name, ' ', s.last_name) LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

$where_clause = implode(' AND ', $where_conditions);

// Get total count for pagination
$total_count = fetchOne("
    SELECT COUNT(*) as count
    FROM interviews i
    INNER JOIN applications a ON i.application_id = a.id
    INNER JOIN jobs j ON a.job_id = j.id
    INNER JOIN employers e ON j.employer_id = e.id
    INNER JOIN seekers s ON a.seeker_id = s.id
    WHERE $where_clause
", $params)['count'] ?? 0;

$total_pages = ceil($total_count / $per_page);

// Fetch interviews with details
$interviews = fetchAll("
    SELECT 
        i.*,
        a.id as application_id,
        a.application_status,
        a.match_score,
        j.id as job_id,
        j.title as job_title,
        j.slug as job_slug,
        j.location_type,
        j.city as job_city,
        j.country as job_country,
        e.id as employer_id,
        e.company_name,
        e.company_logo,
        e.industry,
        e.is_verified as employer_verified,
        s.id as seeker_id,
        s.first_name,
        s.last_name,
        s.profile_picture as seeker_avatar,
        s.headline as seeker_headline,
        s.phone,
        s.whatsapp_number,
        s.current_job_title,
        s.current_company,
        s.years_experience,
        s.highest_education,
        s.skills,
        (SELECT COUNT(*) FROM interviews i2 WHERE i2.application_id = a.id) as total_interviews
    FROM interviews i
    INNER JOIN applications a ON i.application_id = a.id
    INNER JOIN jobs j ON a.job_id = j.id
    INNER JOIN employers e ON j.employer_id = e.id
    INNER JOIN seekers s ON a.seeker_id = s.id
    WHERE $where_clause
    ORDER BY 
        CASE 
            WHEN i.status IN ('scheduled', 'confirmed') AND i.interview_date >= NOW() THEN 0
            WHEN i.status = 'completed' THEN 1
            ELSE 2
        END,
        i.interview_date ASC,
        i.created_at DESC
    LIMIT ? OFFSET ?
", array_merge($params, [$per_page, $offset]));

// Fetch statistics
$stats = fetchOne("
    SELECT 
        COUNT(*) as total_interviews,
        SUM(CASE WHEN i.status = 'scheduled' AND i.interview_date > NOW() THEN 1 ELSE 0 END) as upcoming,
        SUM(CASE WHEN i.status = 'scheduled' AND i.interview_date <= NOW() THEN 1 ELSE 0 END) as overdue,
        SUM(CASE WHEN i.status = 'completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN i.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
        SUM(CASE WHEN i.status = 'confirmed' THEN 1 ELSE 0 END) as confirmed,
        SUM(CASE WHEN DATE(i.interview_date) = CURDATE() AND i.status IN ('scheduled', 'confirmed') THEN 1 ELSE 0 END) as today,
        SUM(CASE WHEN WEEK(i.interview_date) = WEEK(NOW()) AND YEAR(i.interview_date) = YEAR(NOW()) THEN 1 ELSE 0 END) as this_week,
        SUM(CASE WHEN MONTH(i.interview_date) = MONTH(NOW()) AND YEAR(i.interview_date) = YEAR(NOW()) THEN 1 ELSE 0 END) as this_month,
        AVG(i.rating) as avg_rating
    FROM interviews i
", []) ?: [
    'total_interviews' => 0,
    'upcoming' => 0,
    'overdue' => 0,
    'completed' => 0,
    'cancelled' => 0,
    'confirmed' => 0,
    'today' => 0,
    'this_week' => 0,
    'this_month' => 0,
    'avg_rating' => 0
];

// Fetch applications without interviews for dropdown
$applicationsWithoutInterviews = fetchAll("
    SELECT 
        a.id,
        a.match_score,
        a.applied_at,
        j.id as job_id,
        j.title as job_title,
        e.company_name,
        CONCAT(s.first_name, ' ', s.last_name) as seeker_name,
        s.profile_picture as seeker_avatar,
        a.application_status
    FROM applications a
    INNER JOIN jobs j ON a.job_id = j.id
    INNER JOIN employers e ON j.employer_id = e.id
    INNER JOIN seekers s ON a.seeker_id = s.id
    WHERE a.application_status IN ('shortlisted', 'interview', 'offered')
    AND NOT EXISTS (
        SELECT 1 FROM interviews i 
        WHERE i.application_id = a.id 
        AND i.status IN ('scheduled', 'confirmed')
    )
    ORDER BY a.applied_at DESC
    LIMIT 50
", []);

// Fetch calendar data for the next 30 days
$calendarData = fetchAll("
    SELECT 
        DATE(i.interview_date) as date,
        COUNT(*) as count,
        SUM(CASE WHEN i.status = 'completed' THEN 1 ELSE 0 END) as completed_count,
        SUM(CASE WHEN i.status IN ('scheduled', 'confirmed') AND i.interview_date > NOW() THEN 1 ELSE 0 END) as upcoming_count
    FROM interviews i
    WHERE i.interview_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
    GROUP BY DATE(i.interview_date)
    ORDER BY date ASC
", []);

$calendarMap = [];
foreach ($calendarData as $item) {
    $calendarMap[$item['date']] = $item;
}

// Get timezone list
$timezones = DateTimeZone::listIdentifiers();

// Avatar URL for admin
$avatarUrl = 'https://ui-avatars.com/api/?name=' . urlencode('Admin') . '&background=4f46e5&color=fff&size=128&bold=true';
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Interview Management - Admin | TransNow</title>
    
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Ccircle cx='50' cy='50' r='45' fill='%234f46e5'/%3E%3Cpath d='M30 40 L70 40 L65 70 L35 70 Z' fill='white'/%3E%3Ccircle cx='50' cy='30' r='8' fill='white'/%3E%3C/svg%3E">
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- AOS Animation -->
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    
    <!-- Flatpickr for date picking -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/themes/material_blue.css">
    
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
            @apply px-3 py-1 rounded-full text-xs font-semibold;
        }
        
        .status-scheduled { @apply bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300; }
        .status-confirmed { @apply bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300; }
        .status-completed { @apply bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-300; }
        .status-cancelled { @apply bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300; }
        .status-rescheduled { @apply bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300; }
        
        /* Interview Type Badges */
        .type-phone { @apply bg-indigo-100 text-indigo-800 dark:bg-indigo-900 dark:text-indigo-300; }
        .type-video { @apply bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-300; }
        .type-in_person { @apply bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-300; }
        
        /* Chart Container */
        .chart-container {
            position: relative;
            height: 300px;
            width: 100%;
        }
        
        /* Calendar Styles */
        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 4px;
        }
        
        .calendar-day {
            @apply aspect-square p-2 border border-gray-200 dark:border-gray-700 rounded-lg hover:shadow-md transition-all cursor-pointer;
        }
        
        .calendar-day.has-interviews {
            @apply bg-indigo-50 dark:bg-indigo-900/20 border-indigo-300 dark:border-indigo-700;
        }
        
        .calendar-day.today {
            @apply bg-gradient-to-r from-indigo-500 to-purple-500 text-white border-0;
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
        
        /* Mobile Optimizations */
        @media (max-width: 768px) {
            .dashboard-grid {
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
        
        /* Modal Styles */
        .modal {
            transition: opacity 0.25s ease;
        }
        
        .modal.active {
            opacity: 1;
            pointer-events: auto;
        }
        
        .modal-content {
            transform: scale(0.9);
            transition: transform 0.25s ease;
        }
        
        .modal.active .modal-content {
            transform: scale(1);
        }
    </style>
</head>
<body class="bg-gray-50 dark:bg-gray-900 text-gray-900 dark:text-white transition-colors duration-300">

<!-- Flash Messages -->
<?php 
if (isset($_SESSION['success'])) {
    echo '<div class="fixed top-20 right-4 z-50 bg-green-500 text-white px-6 py-3 rounded-xl shadow-lg animate-slideIn" id="flashMessage">
            <i class="fas fa-check-circle mr-2"></i>' . htmlspecialchars($_SESSION['success']) . '
        </div>';
    unset($_SESSION['success']);
}
if (isset($_SESSION['error'])) {
    echo '<div class="fixed top-20 right-4 z-50 bg-red-500 text-white px-6 py-3 rounded-xl shadow-lg animate-slideIn" id="flashMessage">
            <i class="fas fa-exclamation-circle mr-2"></i>' . htmlspecialchars($_SESSION['error']) . '
        </div>';
    unset($_SESSION['error']);
}
if ($error) {
    echo '<div class="fixed top-20 right-4 z-50 bg-red-500 text-white px-6 py-3 rounded-xl shadow-lg animate-slideIn" id="flashMessage">
            <i class="fas fa-exclamation-circle mr-2"></i>' . $error . '
        </div>';
}
?>

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
                
                <!-- Applications -->
                <a href="applications.php" class="flex items-center space-x-3 px-4 py-3 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                    <i class="fas fa-file-alt w-5"></i>
                    <span>Applications</span>
                </a>
                
                <!-- Interviews (Active) -->
                <a href="interviews.php" class="flex items-center space-x-3 px-4 py-3 bg-indigo-50 dark:bg-indigo-900/20 text-indigo-600 dark:text-indigo-400 rounded-xl font-medium">
                    <i class="fas fa-calendar-alt w-5"></i>
                    <span>Interviews</span>
                    <?php if ($stats['upcoming'] > 0): ?>
                    <span class="ml-auto bg-red-500 text-white text-xs px-2 py-1 rounded-full"><?php echo $stats['upcoming']; ?></span>
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
                    <p class="text-sm opacity-90 mb-2">Interview Stats</p>
                    <div class="flex items-center justify-between text-xs">
                        <span>Completion Rate</span>
                        <span class="font-semibold">
                            <?php 
                            $completionRate = $stats['total_interviews'] > 0 
                                ? round(($stats['completed'] / $stats['total_interviews']) * 100) 
                                : 0;
                            echo $completionRate; ?>%
                        </span>
                    </div>
                    <div class="flex items-center justify-between text-xs mt-1">
                        <span>Avg. Rating</span>
                        <span class="font-semibold">
                            <?php echo $stats['avg_rating'] ? number_format($stats['avg_rating'], 1) : 'N/A'; ?>
                        </span>
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
                        Interview <span class="gradient-text">Management</span>
                    </h1>
                    
                    <!-- Right Actions -->
                    <div class="flex items-center space-x-4">
                        <!-- Today's Interviews -->
                        <?php if ($stats['today'] > 0): ?>
                        <div class="hidden md:flex items-center space-x-2 px-4 py-2 bg-green-100 dark:bg-green-900 text-green-600 dark:text-green-400 rounded-xl">
                            <i class="fas fa-calendar-day"></i>
                            <span class="font-semibold"><?php echo $stats['today']; ?> Today</span>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Quick Actions -->
                        <div class="relative">
                            <button id="quickActionsBtn" class="px-4 py-2 bg-gradient-to-r from-indigo-600 to-purple-600 text-white rounded-xl font-medium hover:shadow-lg transition-all duration-300 flex items-center space-x-2">
                                <i class="fas fa-plus-circle"></i>
                                <span>Actions</span>
                                <i class="fas fa-chevron-down text-xs"></i>
                            </button>
                            
                            <!-- Quick Actions Dropdown -->
                            <div id="quickActionsDropdown" class="absolute right-0 mt-2 w-64 bg-white dark:bg-gray-800 rounded-2xl shadow-2xl border border-gray-200 dark:border-gray-700 hidden z-50">
                                <div class="p-2">
                                    <button onclick="openCreateModal()" class="w-full text-left flex items-center space-x-3 px-4 py-3 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                                        <i class="fas fa-calendar-plus text-indigo-600 w-5"></i>
                                        <span>Schedule Interview</span>
                                    </button>
                                    <a href="?status=scheduled" class="flex items-center space-x-3 px-4 py-3 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                                        <i class="fas fa-clock text-purple-600 w-5"></i>
                                        <span>View Scheduled</span>
                                    </a>
                                    <a href="?status=completed" class="flex items-center space-x-3 px-4 py-3 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                                        <i class="fas fa-check-circle text-green-600 w-5"></i>
                                        <span>Completed</span>
                                    </a>
                                    <a href="?status=cancelled" class="flex items-center space-x-3 px-4 py-3 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                                        <i class="fas fa-times-circle text-red-600 w-5"></i>
                                        <span>Cancelled</span>
                                    </a>
                                    <div class="border-t border-gray-200 dark:border-gray-700 my-2"></div>
                                    <button onclick="exportData()" class="w-full text-left flex items-center space-x-3 px-4 py-3 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                                        <i class="fas fa-download text-blue-600 w-5"></i>
                                        <span>Export Report</span>
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
            <!-- Stats Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 xl:grid-cols-6 gap-4 mb-8">
                <!-- Total Interviews -->
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-4 hover-card" data-aos="fade-up">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-500 mb-1">Total Interviews</p>
                            <h3 class="text-2xl font-bold"><?php echo number_format($stats['total_interviews']); ?></h3>
                        </div>
                        <div class="w-12 h-12 bg-indigo-100 dark:bg-indigo-900/20 rounded-xl flex items-center justify-center">
                            <i class="fas fa-calendar-alt text-indigo-600 dark:text-indigo-400 text-xl"></i>
                        </div>
                    </div>
                </div>
                
                <!-- Upcoming -->
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-4 hover-card" data-aos="fade-up" data-aos-delay="50">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-500 mb-1">Upcoming</p>
                            <h3 class="text-2xl font-bold text-blue-600"><?php echo $stats['upcoming']; ?></h3>
                        </div>
                        <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900/20 rounded-xl flex items-center justify-center">
                            <i class="fas fa-arrow-up text-blue-600 dark:text-blue-400 text-xl"></i>
                        </div>
                    </div>
                </div>
                
                <!-- Today -->
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-4 hover-card" data-aos="fade-up" data-aos-delay="100">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-500 mb-1">Today</p>
                            <h3 class="text-2xl font-bold text-green-600"><?php echo $stats['today']; ?></h3>
                        </div>
                        <div class="w-12 h-12 bg-green-100 dark:bg-green-900/20 rounded-xl flex items-center justify-center">
                            <i class="fas fa-sun text-green-600 dark:text-green-400 text-xl"></i>
                        </div>
                    </div>
                </div>
                
                <!-- This Week -->
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-4 hover-card" data-aos="fade-up" data-aos-delay="150">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-500 mb-1">This Week</p>
                            <h3 class="text-2xl font-bold text-purple-600"><?php echo $stats['this_week']; ?></h3>
                        </div>
                        <div class="w-12 h-12 bg-purple-100 dark:bg-purple-900/20 rounded-xl flex items-center justify-center">
                            <i class="fas fa-calendar-week text-purple-600 dark:text-purple-400 text-xl"></i>
                        </div>
                    </div>
                </div>
                
                <!-- Completed -->
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-4 hover-card" data-aos="fade-up" data-aos-delay="200">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-500 mb-1">Completed</p>
                            <h3 class="text-2xl font-bold text-emerald-600"><?php echo $stats['completed']; ?></h3>
                        </div>
                        <div class="w-12 h-12 bg-emerald-100 dark:bg-emerald-900/20 rounded-xl flex items-center justify-center">
                            <i class="fas fa-check-circle text-emerald-600 dark:text-emerald-400 text-xl"></i>
                        </div>
                    </div>
                </div>
                
                <!-- Cancelled -->
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-4 hover-card" data-aos="fade-up" data-aos-delay="250">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-500 mb-1">Cancelled</p>
                            <h3 class="text-2xl font-bold text-red-600"><?php echo $stats['cancelled']; ?></h3>
                        </div>
                        <div class="w-12 h-12 bg-red-100 dark:bg-red-900/20 rounded-xl flex items-center justify-center">
                            <i class="fas fa-times-circle text-red-600 dark:text-red-400 text-xl"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filters and Search -->
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6 mb-8" data-aos="fade-up">
                <form method="GET" action="" class="space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
                        <!-- Search -->
                        <div class="lg:col-span-2">
                            <div class="relative">
                                <i class="fas fa-search absolute left-4 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                                <input type="text" 
                                       name="search" 
                                       value="<?php echo htmlspecialchars($search); ?>"
                                       placeholder="Search by job, company, or candidate..."
                                       class="w-full pl-12 pr-4 py-3 bg-gray-100 dark:bg-gray-700 rounded-xl border-0 focus:ring-2 focus:ring-indigo-500">
                            </div>
                        </div>
                        
                        <!-- Status Filter -->
                        <div>
                            <select name="status" class="w-full px-4 py-3 bg-gray-100 dark:bg-gray-700 rounded-xl border-0 focus:ring-2 focus:ring-indigo-500">
                                <option value="">All Status</option>
                                <option value="scheduled" <?php echo $status_filter === 'scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                                <option value="confirmed" <?php echo $status_filter === 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                                <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                            </select>
                        </div>
                        
                        <!-- Type Filter -->
                        <div>
                            <select name="type" class="w-full px-4 py-3 bg-gray-100 dark:bg-gray-700 rounded-xl border-0 focus:ring-2 focus:ring-indigo-500">
                                <option value="">All Types</option>
                                <option value="phone" <?php echo $type_filter === 'phone' ? 'selected' : ''; ?>>Phone</option>
                                <option value="video" <?php echo $type_filter === 'video' ? 'selected' : ''; ?>>Video</option>
                                <option value="in_person" <?php echo $type_filter === 'in_person' ? 'selected' : ''; ?>>In Person</option>
                            </select>
                        </div>
                        
                        <!-- Date Range -->
                        <div class="flex space-x-2">
                            <input type="text" 
                                   name="date_from" 
                                   id="dateFrom"
                                   value="<?php echo htmlspecialchars($date_from); ?>"
                                   placeholder="From"
                                   class="w-full px-4 py-3 bg-gray-100 dark:bg-gray-700 rounded-xl border-0 focus:ring-2 focus:ring-indigo-500">
                            <input type="text" 
                                   name="date_to" 
                                   id="dateTo"
                                   value="<?php echo htmlspecialchars($date_to); ?>"
                                   placeholder="To"
                                   class="w-full px-4 py-3 bg-gray-100 dark:bg-gray-700 rounded-xl border-0 focus:ring-2 focus:ring-indigo-500">
                        </div>
                    </div>
                    
                    <div class="flex justify-end space-x-3">
                        <a href="interviews.php" class="px-6 py-2 border border-gray-300 dark:border-gray-600 rounded-xl hover:bg-gray-100 dark:hover:bg-gray-700 transition">
                            Clear Filters
                        </a>
                        <button type="submit" class="px-6 py-2 bg-gradient-to-r from-indigo-600 to-purple-600 text-white rounded-xl hover:shadow-lg transition">
                            Apply Filters
                        </button>
                    </div>
                </form>
            </div>

            <!-- Calendar Preview -->
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6 mb-8" data-aos="fade-up">
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-xl font-bold">Interview Calendar</h2>
                    <div class="flex space-x-2">
                        <button onclick="previousMonth()" class="w-10 h-10 bg-gray-100 dark:bg-gray-700 rounded-lg flex items-center justify-center hover:bg-gray-200 dark:hover:bg-gray-600 transition">
                            <i class="fas fa-chevron-left"></i>
                        </button>
                        <span class="px-4 py-2 bg-gray-100 dark:bg-gray-700 rounded-lg font-semibold" id="currentMonthDisplay">
                            <?php echo date('F Y'); ?>
                        </span>
                        <button onclick="nextMonth()" class="w-10 h-10 bg-gray-100 dark:bg-gray-700 rounded-lg flex items-center justify-center hover:bg-gray-200 dark:hover:bg-gray-600 transition">
                            <i class="fas fa-chevron-right"></i>
                        </button>
                    </div>
                </div>
                
                <div class="calendar-grid" id="calendarGrid">
                    <!-- Calendar will be populated by JavaScript -->
                </div>
            </div>

            <!-- Bulk Actions -->
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6 mb-8" data-aos="fade-up">
                <form method="POST" action="?action=bulk" id="bulkForm" class="flex items-center justify-between">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <div class="flex items-center space-x-4">
                        <input type="checkbox" id="selectAll" class="w-5 h-5 text-indigo-600 rounded border-gray-300 focus:ring-indigo-500">
                        <span class="text-sm font-medium">Select All</span>
                        <select name="bulk_action" class="px-4 py-2 bg-gray-100 dark:bg-gray-700 rounded-xl border-0 focus:ring-2 focus:ring-indigo-500">
                            <option value="">Bulk Actions</option>
                            <option value="cancel">Cancel Selected</option>
                            <option value="delete">Delete Selected</option>
                        </select>
                        <button type="submit" class="px-6 py-2 bg-indigo-600 text-white rounded-xl hover:bg-indigo-700 transition" onclick="return confirm('Are you sure you want to perform this bulk action?')">
                            Apply
                        </button>
                    </div>
                </form>
            </div>

            <!-- Interviews Table -->
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl overflow-hidden" data-aos="fade-up">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-700">
                            <tr>
                                <th class="px-6 py-4 text-left">
                                    <span class="text-xs font-medium text-gray-500 uppercase tracking-wider">Select</span>
                                </th>
                                <th class="px-6 py-4 text-left">
                                    <span class="text-xs font-medium text-gray-500 uppercase tracking-wider">Candidate</span>
                                </th>
                                <th class="px-6 py-4 text-left">
                                    <span class="text-xs font-medium text-gray-500 uppercase tracking-wider">Job & Company</span>
                                </th>
                                <th class="px-6 py-4 text-left">
                                    <span class="text-xs font-medium text-gray-500 uppercase tracking-wider">Interview Details</span>
                                </th>
                                <th class="px-6 py-4 text-left">
                                    <span class="text-xs font-medium text-gray-500 uppercase tracking-wider">Interviewer</span>
                                </th>
                                <th class="px-6 py-4 text-left">
                                    <span class="text-xs font-medium text-gray-500 uppercase tracking-wider">Status</span>
                                </th>
                                <th class="px-6 py-4 text-right">
                                    <span class="text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            <?php if (empty($interviews)): ?>
                            <tr>
                                <td colspan="7" class="px-6 py-12 text-center">
                                    <div class="flex flex-col items-center">
                                        <i class="fas fa-calendar-times text-5xl text-gray-400 mb-4"></i>
                                        <h3 class="text-xl font-semibold mb-2">No Interviews Found</h3>
                                        <p class="text-gray-500 mb-6">No interviews match your current filters.</p>
                                        <button onclick="openCreateModal()" class="px-6 py-3 bg-gradient-to-r from-indigo-600 to-purple-600 text-white rounded-xl font-semibold hover:shadow-lg transition">
                                            <i class="fas fa-plus-circle mr-2"></i>
                                            Schedule New Interview
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($interviews as $interview): ?>
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50 transition">
                                    <td class="px-6 py-4">
                                        <input type="checkbox" name="selected_ids[]" value="<?php echo $interview['id']; ?>" form="bulkForm" class="row-checkbox w-5 h-5 text-indigo-600 rounded border-gray-300 focus:ring-indigo-500">
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="flex items-center space-x-3">
                                            <img src="<?php 
                                                echo !empty($interview['seeker_avatar']) 
                                                    ? htmlspecialchars($interview['seeker_avatar']) 
                                                    : 'https://ui-avatars.com/api/?name=' . urlencode($interview['first_name'] . '+' . $interview['last_name']) . '&background=4f46e5&color=fff';
                                            ?>" alt="" class="w-10 h-10 rounded-lg object-cover">
                                            <div>
                                                <p class="font-semibold"><?php echo htmlspecialchars($interview['first_name'] . ' ' . $interview['last_name']); ?></p>
                                                <p class="text-xs text-gray-500"><?php echo htmlspecialchars($interview['seeker_headline'] ?? 'Job Seeker'); ?></p>
                                                <?php if (!empty($interview['match_score'])): ?>
                                                <span class="text-xs bg-green-100 dark:bg-green-900 text-green-600 dark:text-green-400 px-2 py-1 rounded-full mt-1 inline-block">
                                                    <?php echo $interview['match_score']; ?>% Match
                                                </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="space-y-2">
                                            <p class="font-semibold"><?php echo htmlspecialchars($interview['job_title']); ?></p>
                                            <div class="flex items-center space-x-2">
                                                <img src="<?php 
                                                    echo !empty($interview['company_logo']) 
                                                        ? htmlspecialchars($interview['company_logo']) 
                                                        : 'https://ui-avatars.com/api/?name=' . urlencode($interview['company_name']) . '&background=4f46e5&color=fff';
                                                ?>" alt="" class="w-6 h-6 rounded object-cover">
                                                <span class="text-sm"><?php echo htmlspecialchars($interview['company_name']); ?></span>
                                                <?php if ($interview['employer_verified']): ?>
                                                <i class="fas fa-check-circle text-blue-500 text-xs"></i>
                                                <?php endif; ?>
                                            </div>
                                            <p class="text-xs text-gray-500">
                                                <i class="fas fa-map-marker-alt mr-1"></i>
                                                <?php echo ucfirst($interview['location_type'] ?? 'onsite'); ?>
                                                <?php if (!empty($interview['job_city'])): ?> - <?php echo htmlspecialchars($interview['job_city']); ?><?php endif; ?>
                                            </p>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="space-y-2">
                                            <div class="flex items-center space-x-2">
                                                <span class="status-badge type-<?php echo $interview['interview_type']; ?>">
                                                    <i class="fas <?php 
                                                        echo $interview['interview_type'] === 'phone' ? 'fa-phone' : 
                                                            ($interview['interview_type'] === 'video' ? 'fa-video' : 'fa-building'); 
                                                    ?> mr-1"></i>
                                                    <?php echo ucfirst(str_replace('_', ' ', $interview['interview_type'])); ?>
                                                </span>
                                                <span class="text-sm font-semibold">
                                                    <?php echo date('M j, Y', strtotime($interview['interview_date'])); ?>
                                                </span>
                                            </div>
                                            <div class="flex items-center space-x-2 text-sm">
                                                <i class="fas fa-clock text-gray-400"></i>
                                                <span><?php echo date('g:i A', strtotime($interview['interview_date'])); ?></span>
                                                <span class="text-gray-400">(<?php echo $interview['duration_minutes']; ?> min)</span>
                                            </div>
                                            <div class="flex items-center space-x-2 text-sm">
                                                <i class="fas fa-globe text-gray-400"></i>
                                                <span><?php echo $interview['timezone']; ?></span>
                                            </div>
                                            <?php if ($interview['total_interviews'] > 1): ?>
                                            <span class="text-xs bg-purple-100 dark:bg-purple-900 text-purple-600 dark:text-purple-400 px-2 py-1 rounded-full">
                                                Round <?php echo $interview['total_interviews']; ?>
                                            </span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="space-y-2">
                                            <p class="font-semibold"><?php echo htmlspecialchars($interview['interviewer_name']); ?></p>
                                            <p class="text-sm text-gray-500"><?php echo htmlspecialchars($interview['interviewer_email']); ?></p>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <span class="status-badge status-<?php echo $interview['status']; ?>">
                                            <?php echo ucfirst($interview['status']); ?>
                                        </span>
                                        <?php if (strtotime($interview['interview_date']) < time() && $interview['status'] === 'scheduled'): ?>
                                        <span class="block mt-2 text-xs bg-red-100 dark:bg-red-900 text-red-600 dark:text-red-400 px-2 py-1 rounded-full">
                                            <i class="fas fa-exclamation-circle mr-1"></i> Overdue
                                        </span>
                                        <?php endif; ?>
                                        <?php if (!empty($interview['rating'])): ?>
                                        <div class="flex items-center mt-2">
                                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <i class="fas fa-star <?php echo $i <= $interview['rating'] ? 'text-yellow-400' : 'text-gray-300'; ?> text-xs"></i>
                                            <?php endfor; ?>
                                        </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 text-right">
                                        <div class="flex items-center justify-end space-x-2">
                                            <button onclick="viewInterview(<?php echo htmlspecialchars(json_encode($interview)); ?>)" 
                                                    class="w-8 h-8 bg-gray-100 dark:bg-gray-700 rounded-lg flex items-center justify-center hover:bg-indigo-100 dark:hover:bg-indigo-900/20 transition group"
                                                    title="View Details">
                                                <i class="fas fa-eye text-gray-600 dark:text-gray-400 group-hover:text-indigo-600"></i>
                                            </button>
                                            <?php if ($interview['status'] !== 'completed' && $interview['status'] !== 'cancelled'): ?>
                                            <button onclick="editInterview(<?php echo htmlspecialchars(json_encode($interview)); ?>)" 
                                                    class="w-8 h-8 bg-gray-100 dark:bg-gray-700 rounded-lg flex items-center justify-center hover:bg-blue-100 dark:hover:bg-blue-900/20 transition group"
                                                    title="Edit">
                                                <i class="fas fa-edit text-gray-600 dark:text-gray-400 group-hover:text-blue-600"></i>
                                            </button>
                                            <?php endif; ?>
                                            <?php if ($interview['status'] === 'scheduled' || $interview['status'] === 'confirmed'): ?>
                                            <button onclick="completeInterview(<?php echo $interview['id']; ?>)" 
                                                    class="w-8 h-8 bg-gray-100 dark:bg-gray-700 rounded-lg flex items-center justify-center hover:bg-green-100 dark:hover:bg-green-900/20 transition group"
                                                    title="Mark Completed">
                                                <i class="fas fa-check-circle text-gray-600 dark:text-gray-400 group-hover:text-green-600"></i>
                                            </button>
                                            <button onclick="cancelInterview(<?php echo $interview['id']; ?>)" 
                                                    class="w-8 h-8 bg-gray-100 dark:bg-gray-700 rounded-lg flex items-center justify-center hover:bg-red-100 dark:hover:bg-red-900/20 transition group"
                                                    title="Cancel">
                                                <i class="fas fa-times-circle text-gray-600 dark:text-gray-400 group-hover:text-red-600"></i>
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <div class="px-6 py-4 bg-gray-50 dark:bg-gray-700 border-t border-gray-200 dark:border-gray-600">
                    <div class="flex items-center justify-between">
                        <p class="text-sm text-gray-500">
                            Showing <span class="font-medium"><?php echo $offset + 1; ?></span> to 
                            <span class="font-medium"><?php echo min($offset + $per_page, $total_count); ?></span> of 
                            <span class="font-medium"><?php echo $total_count; ?></span> results
                        </p>
                        <div class="flex space-x-2">
                            <?php if ($page > 1): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" 
                               class="px-4 py-2 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 transition">
                                Previous
                            </a>
                            <?php endif; ?>
                            
                            <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" 
                               class="px-4 py-2 <?php echo $i === $page ? 'bg-indigo-600 text-white' : 'bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 hover:bg-gray-100 dark:hover:bg-gray-700'; ?> rounded-lg transition">
                                <?php echo $i; ?>
                            </a>
                            <?php endfor; ?>
                            
                            <?php if ($page < $total_pages): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" 
                               class="px-4 py-2 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 transition">
                                Next
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
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
                    <a href="interviews.php" class="block px-4 py-2 bg-indigo-50 dark:bg-indigo-900/20 text-indigo-600 rounded-lg">Interviews</a>
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

<!-- Create/Edit Interview Modal -->
<div id="interviewModal" class="fixed inset-0 z-50 flex items-center justify-center hidden modal" style="background-color: rgba(0,0,0,0.5);">
    <div class="modal-content bg-white dark:bg-gray-800 rounded-2xl shadow-2xl w-full max-w-4xl max-h-[90vh] overflow-y-auto">
        <div class="p-6 border-b border-gray-200 dark:border-gray-700">
            <div class="flex items-center justify-between">
                <h2 class="text-2xl font-bold" id="modalTitle">Schedule Interview</h2>
                <button onclick="closeModal()" class="w-10 h-10 bg-gray-100 dark:bg-gray-700 rounded-lg flex items-center justify-center hover:bg-gray-200 dark:hover:bg-gray-600 transition">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>
        
        <form method="POST" action="" id="interviewForm" class="p-6">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="interview_id" id="interviewId" value="">
            <input type="hidden" name="action" id="formAction" value="create">
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Left Column -->
                <div class="space-y-6">
                    <!-- Application Selection -->
                    <div>
                        <label class="block text-sm font-medium mb-2">Select Application *</label>
                        <select name="application_id" id="applicationId" class="w-full px-4 py-3 bg-gray-100 dark:bg-gray-700 rounded-xl border-0 focus:ring-2 focus:ring-indigo-500" required>
                            <option value="">Choose an application...</option>
                            <?php foreach ($applicationsWithoutInterviews as $app): ?>
                            <option value="<?php echo $app['id']; ?>" 
                                    data-seeker="<?php echo htmlspecialchars($app['seeker_name']); ?>"
                                    data-job="<?php echo htmlspecialchars($app['job_title']); ?>"
                                    data-company="<?php echo htmlspecialchars($app['company_name']); ?>">
                                <?php echo htmlspecialchars($app['seeker_name'] . ' - ' . $app['job_title'] . ' (' . $app['company_name'] . ')'); ?>
                                <?php if ($app['match_score']): ?> - <?php echo $app['match_score']; ?>% Match<?php endif; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="text-xs text-gray-500 mt-1">Only shortlisted and interview-ready applications shown</p>
                    </div>
                    
                    <!-- Interview Type -->
                    <div>
                        <label class="block text-sm font-medium mb-2">Interview Type *</label>
                        <div class="grid grid-cols-3 gap-3">
                            <label class="relative flex items-center justify-center p-4 bg-gray-100 dark:bg-gray-700 rounded-xl cursor-pointer hover:bg-gray-200 dark:hover:bg-gray-600 transition has-[:checked]:bg-indigo-50 dark:has-[:checked]:bg-indigo-900/20 has-[:checked]:ring-2 has-[:checked]:ring-indigo-500">
                                <input type="radio" name="interview_type" value="phone" class="sr-only" checked>
                                <div class="text-center">
                                    <i class="fas fa-phone text-indigo-600 text-xl mb-2"></i>
                                    <span class="block text-sm font-medium">Phone</span>
                                </div>
                            </label>
                            <label class="relative flex items-center justify-center p-4 bg-gray-100 dark:bg-gray-700 rounded-xl cursor-pointer hover:bg-gray-200 dark:hover:bg-gray-600 transition has-[:checked]:bg-indigo-50 dark:has-[:checked]:bg-indigo-900/20 has-[:checked]:ring-2 has-[:checked]:ring-indigo-500">
                                <input type="radio" name="interview_type" value="video" class="sr-only">
                                <div class="text-center">
                                    <i class="fas fa-video text-purple-600 text-xl mb-2"></i>
                                    <span class="block text-sm font-medium">Video</span>
                                </div>
                            </label>
                            <label class="relative flex items-center justify-center p-4 bg-gray-100 dark:bg-gray-700 rounded-xl cursor-pointer hover:bg-gray-200 dark:hover:bg-gray-600 transition has-[:checked]:bg-indigo-50 dark:has-[:checked]:bg-indigo-900/20 has-[:checked]:ring-2 has-[:checked]:ring-indigo-500">
                                <input type="radio" name="interview_type" value="in_person" class="sr-only">
                                <div class="text-center">
                                    <i class="fas fa-building text-orange-600 text-xl mb-2"></i>
                                    <span class="block text-sm font-medium">In Person</span>
                                </div>
                            </label>
                        </div>
                    </div>
                    
                    <!-- Date and Time -->
                    <div>
                        <label class="block text-sm font-medium mb-2">Interview Date & Time *</label>
                        <input type="text" 
                               name="interview_date" 
                               id="interviewDateTime"
                               class="w-full px-4 py-3 bg-gray-100 dark:bg-gray-700 rounded-xl border-0 focus:ring-2 focus:ring-indigo-500"
                               placeholder="Select date and time"
                               required>
                    </div>
                    
                    <!-- Duration and Timezone -->
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium mb-2">Duration (minutes)</label>
                            <select name="duration_minutes" class="w-full px-4 py-3 bg-gray-100 dark:bg-gray-700 rounded-xl border-0 focus:ring-2 focus:ring-indigo-500">
                                <option value="15">15 minutes</option>
                                <option value="30" selected>30 minutes</option>
                                <option value="45">45 minutes</option>
                                <option value="60">1 hour</option>
                                <option value="90">1.5 hours</option>
                                <option value="120">2 hours</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-2">Timezone</label>
                            <select name="timezone" class="w-full px-4 py-3 bg-gray-100 dark:bg-gray-700 rounded-xl border-0 focus:ring-2 focus:ring-indigo-500">
                                <?php foreach ($timezones as $tz): ?>
                                <option value="<?php echo $tz; ?>" <?php echo $tz === date_default_timezone_get() ? 'selected' : ''; ?>>
                                    <?php echo str_replace('_', ' ', $tz); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Status -->
                    <div>
                        <label class="block text-sm font-medium mb-2">Status</label>
                        <select name="status" class="w-full px-4 py-3 bg-gray-100 dark:bg-gray-700 rounded-xl border-0 focus:ring-2 focus:ring-indigo-500">
                            <option value="scheduled">Scheduled</option>
                            <option value="confirmed">Confirmed</option>
                        </select>
                    </div>
                </div>
                
                <!-- Right Column -->
                <div class="space-y-6">
                    <!-- Interviewer Details -->
                    <div>
                        <h3 class="font-semibold mb-4">Interviewer Details</h3>
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium mb-2">Interviewer Name *</label>
                                <input type="text" 
                                       name="interviewer_name" 
                                       id="interviewerName"
                                       class="w-full px-4 py-3 bg-gray-100 dark:bg-gray-700 rounded-xl border-0 focus:ring-2 focus:ring-indigo-500"
                                       placeholder="e.g., John Smith"
                                       required>
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-2">Interviewer Email *</label>
                                <input type="email" 
                                       name="interviewer_email" 
                                       id="interviewerEmail"
                                       class="w-full px-4 py-3 bg-gray-100 dark:bg-gray-700 rounded-xl border-0 focus:ring-2 focus:ring-indigo-500"
                                       placeholder="interviewer@company.com"
                                       required>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Meeting Details -->
                    <div>
                        <h3 class="font-semibold mb-4">Meeting Details</h3>
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium mb-2">Meeting Link (for video/phone)</label>
                                <input type="url" 
                                       name="meeting_link" 
                                       id="meetingLink"
                                       class="w-full px-4 py-3 bg-gray-100 dark:bg-gray-700 rounded-xl border-0 focus:ring-2 focus:ring-indigo-500"
                                       placeholder="https://meet.google.com/...">
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-2">Physical Location (for in-person)</label>
                                <textarea name="location" 
                                          id="location"
                                          rows="2"
                                          class="w-full px-4 py-3 bg-gray-100 dark:bg-gray-700 rounded-xl border-0 focus:ring-2 focus:ring-indigo-500"
                                          placeholder="Office address, room number, etc."></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Notes -->
                    <div>
                        <label class="block text-sm font-medium mb-2">Additional Notes</label>
                        <textarea name="notes" 
                                  id="notes"
                                  rows="4"
                                  class="w-full px-4 py-3 bg-gray-100 dark:bg-gray-700 rounded-xl border-0 focus:ring-2 focus:ring-indigo-500"
                                  placeholder="Any special instructions, topics to cover, etc."></textarea>
                    </div>
                </div>
            </div>
            
            <!-- Selected Application Preview -->
            <div id="selectedAppPreview" class="mt-6 p-4 bg-indigo-50 dark:bg-indigo-900/20 rounded-xl hidden">
                <h4 class="font-semibold mb-2">Selected Application</h4>
                <div class="flex items-center space-x-4">
                    <img src="" alt="" id="previewAvatar" class="w-12 h-12 rounded-lg object-cover">
                    <div>
                        <p class="font-semibold" id="previewSeeker"></p>
                        <p class="text-sm text-gray-600 dark:text-gray-400" id="previewJob"></p>
                    </div>
                </div>
            </div>
            
            <div class="mt-8 flex justify-end space-x-3">
                <button type="button" onclick="closeModal()" class="px-6 py-3 border border-gray-300 dark:border-gray-600 rounded-xl hover:bg-gray-100 dark:hover:bg-gray-700 transition">
                    Cancel
                </button>
                <button type="submit" class="px-6 py-3 bg-gradient-to-r from-indigo-600 to-purple-600 text-white rounded-xl hover:shadow-lg transition">
                    Schedule Interview
                </button>
            </div>
        </form>
    </div>
</div>

<!-- View Interview Modal -->
<div id="viewModal" class="fixed inset-0 z-50 flex items-center justify-center hidden modal" style="background-color: rgba(0,0,0,0.5);">
    <div class="modal-content bg-white dark:bg-gray-800 rounded-2xl shadow-2xl w-full max-w-3xl">
        <div class="p-6 border-b border-gray-200 dark:border-gray-700">
            <div class="flex items-center justify-between">
                <h2 class="text-2xl font-bold">Interview Details</h2>
                <button onclick="closeViewModal()" class="w-10 h-10 bg-gray-100 dark:bg-gray-700 rounded-lg flex items-center justify-center hover:bg-gray-200 dark:hover:bg-gray-600 transition">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>
        
        <div class="p-6">
            <div id="interviewDetails"></div>
            
            <div class="mt-8 flex justify-end space-x-3">
                <button type="button" onclick="closeViewModal()" class="px-6 py-3 border border-gray-300 dark:border-gray-600 rounded-xl hover:bg-gray-100 dark:hover:bg-gray-700 transition">
                    Close
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Complete Interview Modal -->
<div id="completeModal" class="fixed inset-0 z-50 flex items-center justify-center hidden modal" style="background-color: rgba(0,0,0,0.5);">
    <div class="modal-content bg-white dark:bg-gray-800 rounded-2xl shadow-2xl w-full max-w-md">
        <div class="p-6 border-b border-gray-200 dark:border-gray-700">
            <h2 class="text-2xl font-bold">Complete Interview</h2>
        </div>
        
        <form method="POST" action="?action=complete" class="p-6">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="interview_id" id="completeInterviewId" value="">
            
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium mb-2">Rating</label>
                    <div class="flex items-center space-x-2" id="ratingStars">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                        <button type="button" onclick="setRating(<?php echo $i; ?>)" class="text-3xl text-gray-300 hover:text-yellow-400 focus:outline-none rating-star" data-value="<?php echo $i; ?>">
                            <i class="far fa-star"></i>
                        </button>
                        <?php endfor; ?>
                    </div>
                    <input type="hidden" name="rating" id="ratingValue" value="0">
                </div>
                
                <div>
                    <label class="block text-sm font-medium mb-2">Feedback Notes</label>
                    <textarea name="feedback_notes" rows="5" class="w-full px-4 py-3 bg-gray-100 dark:bg-gray-700 rounded-xl border-0 focus:ring-2 focus:ring-indigo-500" placeholder="Enter your feedback about the interview..."></textarea>
                </div>
            </div>
            
            <div class="mt-8 flex justify-end space-x-3">
                <button type="button" onclick="closeCompleteModal()" class="px-6 py-3 border border-gray-300 dark:border-gray-600 rounded-xl hover:bg-gray-100 dark:hover:bg-gray-700 transition">
                    Cancel
                </button>
                <button type="submit" class="px-6 py-3 bg-gradient-to-r from-indigo-600 to-purple-600 text-white rounded-xl hover:shadow-lg transition">
                    Complete Interview
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Cancel Interview Modal -->
<div id="cancelModal" class="fixed inset-0 z-50 flex items-center justify-center hidden modal" style="background-color: rgba(0,0,0,0.5);">
    <div class="modal-content bg-white dark:bg-gray-800 rounded-2xl shadow-2xl w-full max-w-md">
        <div class="p-6 border-b border-gray-200 dark:border-gray-700">
            <h2 class="text-2xl font-bold">Cancel Interview</h2>
        </div>
        
        <form method="POST" action="?action=cancel" class="p-6">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="interview_id" id="cancelInterviewId" value="">
            
            <p class="text-gray-600 dark:text-gray-400 mb-6">Are you sure you want to cancel this interview? This action cannot be undone.</p>
            
            <div class="flex justify-end space-x-3">
                <button type="button" onclick="closeCancelModal()" class="px-6 py-3 border border-gray-300 dark:border-gray-600 rounded-xl hover:bg-gray-100 dark:hover:bg-gray-700 transition">
                    No, Keep It
                </button>
                <button type="submit" class="px-6 py-3 bg-red-600 text-white rounded-xl hover:bg-red-700 transition">
                    Yes, Cancel Interview
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Scripts -->
<script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

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

// Initialize Flatpickr for date inputs
flatpickr("#interviewDateTime", {
    enableTime: true,
    dateFormat: "Y-m-d H:i:s",
    minDate: "today",
    time_24hr: false,
    minuteIncrement: 15
});

flatpickr("#dateFrom", {
    dateFormat: "Y-m-d"
});

flatpickr("#dateTo", {
    dateFormat: "Y-m-d"
});

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

// Select All Checkboxes
const selectAll = document.getElementById('selectAll');
if (selectAll) {
    selectAll.addEventListener('change', function() {
        document.querySelectorAll('.row-checkbox').forEach(checkbox => {
            checkbox.checked = this.checked;
        });
    });
}

// Calendar Functions
let currentDate = new Date();
let currentMonth = currentDate.getMonth();
let currentYear = currentDate.getFullYear();

function generateCalendar(month, year) {
    const firstDay = new Date(year, month, 1);
    const lastDay = new Date(year, month + 1, 0);
    const startingDay = firstDay.getDay(); // 0 = Sunday, 1 = Monday, etc.
    const monthLength = lastDay.getDate();
    
    let calendarHTML = '';
    
    // Day headers
    const days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    days.forEach(day => {
        calendarHTML += `<div class="text-center text-sm font-medium py-2">${day}</div>`;
    });
    
    // Empty cells before first day
    for (let i = 0; i < startingDay; i++) {
        calendarHTML += '<div></div>';
    }
    
    // Calendar days
    const today = new Date();
    const calendarMap = <?php echo json_encode($calendarMap); ?>;
    
    for (let day = 1; day <= monthLength; day++) {
        const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
        const hasInterviews = calendarMap[dateStr];
        const isToday = today.getDate() === day && today.getMonth() === month && today.getFullYear() === year;
        
        let dayClass = 'calendar-day';
        if (hasInterviews) dayClass += ' has-interviews';
        if (isToday) dayClass += ' today';
        
        calendarHTML += `
            <div class="${dayClass}" onclick="filterByDate('${dateStr}')">
                <div class="font-semibold ${isToday ? 'text-white' : ''}">${day}</div>
                ${hasInterviews ? `
                    <div class="text-xs mt-1 ${isToday ? 'text-white' : 'text-indigo-600 dark:text-indigo-400'}">
                        ${hasInterviews.count} interview${hasInterviews.count > 1 ? 's' : ''}
                    </div>
                ` : ''}
            </div>
        `;
    }
    
    document.getElementById('calendarGrid').innerHTML = calendarHTML;
    document.getElementById('currentMonthDisplay').textContent = 
        new Date(year, month).toLocaleString('default', { month: 'long', year: 'numeric' });
}

function previousMonth() {
    currentMonth--;
    if (currentMonth < 0) {
        currentMonth = 11;
        currentYear--;
    }
    generateCalendar(currentMonth, currentYear);
}

function nextMonth() {
    currentMonth++;
    if (currentMonth > 11) {
        currentMonth = 0;
        currentYear++;
    }
    generateCalendar(currentMonth, currentYear);
}

function filterByDate(date) {
    window.location.href = `?date_from=${date}&date_to=${date}`;
}

// Initialize calendar
generateCalendar(currentMonth, currentYear);

// Modal Functions
function openCreateModal() {
    document.getElementById('modalTitle').textContent = 'Schedule Interview';
    document.getElementById('formAction').value = 'create';
    document.getElementById('interviewId').value = '';
    document.getElementById('interviewForm').reset();
    document.getElementById('selectedAppPreview').classList.add('hidden');
    document.getElementById('interviewModal').classList.remove('hidden');
    document.getElementById('interviewModal').classList.add('active');
}

function closeModal() {
    document.getElementById('interviewModal').classList.add('hidden');
    document.getElementById('interviewModal').classList.remove('active');
}

function editInterview(interview) {
    document.getElementById('modalTitle').textContent = 'Edit Interview';
    document.getElementById('formAction').value = 'edit';
    document.getElementById('interviewId').value = interview.id;
    
    // Fill form fields
    document.getElementById('applicationId').value = interview.application_id;
    document.querySelector(`input[name="interview_type"][value="${interview.interview_type}"]`).checked = true;
    document.getElementById('interviewDateTime').value = interview.interview_date;
    document.querySelector(`select[name="duration_minutes"]`).value = interview.duration_minutes;
    document.querySelector(`select[name="timezone"]`).value = interview.timezone;
    document.querySelector(`select[name="status"]`).value = interview.status;
    document.getElementById('interviewerName').value = interview.interviewer_name;
    document.getElementById('interviewerEmail').value = interview.interviewer_email;
    document.getElementById('meetingLink').value = interview.meeting_link || '';
    document.getElementById('location').value = interview.location || '';
    document.getElementById('notes').value = interview.notes || '';
    
    // Show preview
    showApplicationPreview(interview);
    
    document.getElementById('interviewModal').classList.remove('hidden');
    document.getElementById('interviewModal').classList.add('active');
}

function showApplicationPreview(interview) {
    const preview = document.getElementById('selectedAppPreview');
    document.getElementById('previewSeeker').textContent = interview.first_name + ' ' + interview.last_name;
    document.getElementById('previewJob').textContent = interview.job_title + ' at ' + interview.company_name;
    document.getElementById('previewAvatar').src = interview.seeker_avatar || 
        'https://ui-avatars.com/api/?name=' + encodeURIComponent(interview.first_name + '+' + interview.last_name) + '&background=4f46e5&color=fff';
    preview.classList.remove('hidden');
}

function viewInterview(interview) {
    let html = `
        <div class="space-y-6">
            <div class="flex items-center space-x-4 pb-4 border-b border-gray-200 dark:border-gray-700">
                <img src="${interview.seeker_avatar || 'https://ui-avatars.com/api/?name=' + encodeURIComponent(interview.first_name + '+' + interview.last_name) + '&background=4f46e5&color=fff'}" 
                     alt="" class="w-16 h-16 rounded-xl object-cover">
                <div>
                    <h3 class="text-xl font-bold">${interview.first_name} ${interview.last_name}</h3>
                    <p class="text-gray-600 dark:text-gray-400">${interview.seeker_headline || 'Job Seeker'}</p>
                </div>
            </div>
            
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <p class="text-sm text-gray-500 mb-1">Job Position</p>
                    <p class="font-semibold">${interview.job_title}</p>
                </div>
                <div>
                    <p class="text-sm text-gray-500 mb-1">Company</p>
                    <p class="font-semibold">${interview.company_name}</p>
                </div>
                <div>
                    <p class="text-sm text-gray-500 mb-1">Interview Type</p>
                    <span class="status-badge type-${interview.interview_type}">
                        ${interview.interview_type === 'phone' ? 'Phone' : (interview.interview_type === 'video' ? 'Video' : 'In Person')}
                    </span>
                </div>
                <div>
                    <p class="text-sm text-gray-500 mb-1">Status</p>
                    <span class="status-badge status-${interview.status}">
                        ${interview.status.charAt(0).toUpperCase() + interview.status.slice(1)}
                    </span>
                </div>
                <div class="col-span-2">
                    <p class="text-sm text-gray-500 mb-1">Date & Time</p>
                    <p class="font-semibold">${new Date(interview.interview_date).toLocaleString()} (${interview.duration_minutes} minutes)</p>
                </div>
                <div class="col-span-2">
                    <p class="text-sm text-gray-500 mb-1">Interviewer</p>
                    <p class="font-semibold">${interview.interviewer_name}</p>
                    <p class="text-sm text-gray-600 dark:text-gray-400">${interview.interviewer_email}</p>
                </div>
    `;
    
    if (interview.meeting_link) {
        html += `
            <div class="col-span-2">
                <p class="text-sm text-gray-500 mb-1">Meeting Link</p>
                <a href="${interview.meeting_link}" target="_blank" class="text-indigo-600 hover:underline break-all">${interview.meeting_link}</a>
            </div>
        `;
    }
    
    if (interview.location) {
        html += `
            <div class="col-span-2">
                <p class="text-sm text-gray-500 mb-1">Location</p>
                <p class="text-gray-600 dark:text-gray-400">${interview.location}</p>
            </div>
        `;
    }
    
    if (interview.notes) {
        html += `
            <div class="col-span-2">
                <p class="text-sm text-gray-500 mb-1">Notes</p>
                <p class="text-gray-600 dark:text-gray-400">${interview.notes}</p>
            </div>
        `;
    }
    
    if (interview.feedback_notes) {
        html += `
            <div class="col-span-2">
                <p class="text-sm text-gray-500 mb-1">Feedback</p>
                <p class="text-gray-600 dark:text-gray-400">${interview.feedback_notes}</p>
            </div>
        `;
    }
    
    if (interview.rating) {
        html += `
            <div class="col-span-2">
                <p class="text-sm text-gray-500 mb-1">Rating</p>
                <div class="flex items-center">
        `;
        for (let i = 1; i <= 5; i++) {
            html += `<i class="fas fa-star ${i <= interview.rating ? 'text-yellow-400' : 'text-gray-300'} text-lg"></i>`;
        }
        html += `</div></div>`;
    }
    
    html += `</div></div>`;
    
    document.getElementById('interviewDetails').innerHTML = html;
    document.getElementById('viewModal').classList.remove('hidden');
    document.getElementById('viewModal').classList.add('active');
}

function closeViewModal() {
    document.getElementById('viewModal').classList.add('hidden');
    document.getElementById('viewModal').classList.remove('active');
}

function completeInterview(id) {
    document.getElementById('completeInterviewId').value = id;
    document.getElementById('completeModal').classList.remove('hidden');
    document.getElementById('completeModal').classList.add('active');
}

function closeCompleteModal() {
    document.getElementById('completeModal').classList.add('hidden');
    document.getElementById('completeModal').classList.remove('active');
    document.getElementById('ratingValue').value = 0;
    document.querySelectorAll('.rating-star i').forEach(star => {
        star.className = 'far fa-star';
    });
}

function cancelInterview(id) {
    document.getElementById('cancelInterviewId').value = id;
    document.getElementById('cancelModal').classList.remove('hidden');
    document.getElementById('cancelModal').classList.add('active');
}

function closeCancelModal() {
    document.getElementById('cancelModal').classList.add('hidden');
    document.getElementById('cancelModal').classList.remove('active');
}

// Rating stars
function setRating(value) {
    document.getElementById('ratingValue').value = value;
    document.querySelectorAll('.rating-star').forEach((star, index) => {
        const icon = star.querySelector('i');
        if (index < value) {
            icon.className = 'fas fa-star text-yellow-400';
        } else {
            icon.className = 'far fa-star text-gray-300';
        }
    });
}

// Export data
function exportData() {
    const filters = new URLSearchParams(window.location.search);
    window.location.href = '/admin/export-interviews.php?' + filters.toString();
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
        closeModal();
        closeViewModal();
        closeCompleteModal();
        closeCancelModal();
        quickActionsDropdown?.classList.add('hidden');
        mobileSidebar?.classList.add('hidden');
    }
    // Ctrl/Cmd + N to create new interview
    if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
        e.preventDefault();
        openCreateModal();
    }
});
</script>
</body>
</html>