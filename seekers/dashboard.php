<?php
// seekers/dashboard.php - Seeker Dashboard
// Version: 4.1 - Enhanced seeker management system with fixed database connections

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

// Require seeker login
requireSeekerLogin();

// Get database connection
$pdo = getDBConnection();
$user_id = $_SESSION['user_id'];

// Get seeker info
$seekerData = fetchOne("
    SELECT s.*, u.email, u.is_verified, u.created_at as account_created
    FROM seekers s 
    INNER JOIN users u ON s.user_id = u.id 
    WHERE s.user_id = ?
", [$user_id]);

if (!$seekerData) {
    $_SESSION['error'] = 'Seeker profile not found';
    header('Location: /logout.php');
    exit();
}

$seeker_id = $seekerData['id'];

// ===== FETCH COMPREHENSIVE DASHBOARD STATISTICS =====

// 1. Overall Seeker Statistics
$overallStats = fetchOne("
    SELECT 
        (SELECT COUNT(*) FROM applications WHERE seeker_id = ?) as total_applications,
        (SELECT COUNT(*) FROM applications WHERE seeker_id = ? AND application_status = 'applied') as pending_applications,
        (SELECT COUNT(*) FROM applications WHERE seeker_id = ? AND application_status = 'reviewed') as reviewed_applications,
        (SELECT COUNT(*) FROM applications WHERE seeker_id = ? AND application_status = 'shortlisted') as shortlisted_applications,
        (SELECT COUNT(*) FROM applications WHERE seeker_id = ? AND application_status = 'interview') as interview_applications,
        (SELECT COUNT(*) FROM applications WHERE seeker_id = ? AND application_status = 'offered') as offered_applications,
        (SELECT COUNT(*) FROM applications WHERE seeker_id = ? AND application_status = 'hired') as hired_applications,
        (SELECT COUNT(*) FROM applications WHERE seeker_id = ? AND application_status = 'rejected') as rejected_applications,
        (SELECT COUNT(*) FROM applications WHERE seeker_id = ? AND applied_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) as applications_this_week,
        (SELECT COUNT(*) FROM saved_jobs WHERE seeker_id = ?) as saved_jobs_count,
        (SELECT COUNT(*) FROM interviews i 
         INNER JOIN applications a ON i.application_id = a.id 
         WHERE a.seeker_id = ? AND i.interview_date > NOW()) as upcoming_interviews,
        (SELECT COUNT(*) FROM interviews i 
         INNER JOIN applications a ON i.application_id = a.id 
         WHERE a.seeker_id = ? AND i.status = 'completed') as completed_interviews,
        (SELECT COUNT(*) FROM messages m 
         WHERE m.receiver_id = ? AND m.is_read = 0) as unread_messages,
        (SELECT COUNT(*) FROM notifications n 
         WHERE n.user_id = ? AND n.status = 'pending') as unread_notifications
", array_fill(0, 15, $user_id));

// Ensure $overallStats is not null
if (!$overallStats) {
    $overallStats = [
        'total_applications' => 0,
        'pending_applications' => 0,
        'reviewed_applications' => 0,
        'shortlisted_applications' => 0,
        'interview_applications' => 0,
        'offered_applications' => 0,
        'hired_applications' => 0,
        'rejected_applications' => 0,
        'applications_this_week' => 0,
        'saved_jobs_count' => 0,
        'upcoming_interviews' => 0,
        'completed_interviews' => 0,
        'unread_messages' => 0,
        'unread_notifications' => 0
    ];
}

// 2. Recent Applications with Job Details
$recentApplications = fetchAll("
    SELECT 
        a.*,
        j.id as job_id,
        j.title as job_title,
        j.slug as job_slug,
        j.location_type,
        j.city,
        j.country,
        j.salary_min,
        j.salary_max,
        j.salary_currency,
        j.salary_type,
        e.id as employer_id,
        e.company_name,
        e.company_logo,
        e.industry,
        e.city as company_city,
        e.country as company_country,
        (SELECT COUNT(*) FROM interviews WHERE application_id = a.id) as interview_count,
        (SELECT interview_date FROM interviews WHERE application_id = a.id ORDER BY interview_date DESC LIMIT 1) as last_interview_date,
        (SELECT status FROM interviews WHERE application_id = a.id ORDER BY interview_date DESC LIMIT 1) as last_interview_status
    FROM applications a
    INNER JOIN jobs j ON a.job_id = j.id
    INNER JOIN employers e ON j.employer_id = e.id
    WHERE a.seeker_id = ?
    ORDER BY a.applied_at DESC
    LIMIT 10
", [$seeker_id]);

// 3. Saved Jobs with Status
$savedJobs = fetchAll("
    SELECT 
        sj.*,
        j.id as job_id,
        j.title as job_title,
        j.slug as job_slug,
        j.location_type,
        j.city,
        j.country,
        j.salary_min,
        j.salary_max,
        j.salary_currency,
        j.salary_type,
        j.status as job_status,
        j.application_deadline,
        j.created_at as job_posted,
        e.id as employer_id,
        e.company_name,
        e.company_logo,
        e.industry,
        e.city as company_city,
        e.country as company_country,
        e.is_verified as employer_verified,
        (SELECT COUNT(*) FROM applications WHERE job_id = j.id AND seeker_id = ?) as has_applied
    FROM saved_jobs sj
    INNER JOIN jobs j ON sj.job_id = j.id
    INNER JOIN employers e ON j.employer_id = e.id
    WHERE sj.seeker_id = ?
    ORDER BY sj.created_at DESC
    LIMIT 8
", [$seeker_id, $seeker_id]);

// 4. Upcoming Interviews
$upcomingInterviews = fetchAll("
    SELECT 
        i.*,
        a.job_id,
        j.title as job_title,
        j.slug as job_slug,
        e.id as employer_id,
        e.company_name,
        e.company_logo,
        e.industry,
        CONCAT(e.city, ', ', e.country) as company_location,
        i.interview_date,
        i.interview_type,
        i.duration_minutes,
        i.meeting_link,
        i.location,
        i.interviewer_name,
        i.interviewer_email,
        i.notes
    FROM interviews i
    INNER JOIN applications a ON i.application_id = a.id
    INNER JOIN jobs j ON a.job_id = j.id
    INNER JOIN employers e ON j.employer_id = e.id
    WHERE a.seeker_id = ? AND i.interview_date > NOW()
    ORDER BY i.interview_date ASC
", [$seeker_id]);

// 5. Job Recommendations Based on Profile
$searchTerms = $seekerData['headline'] . ' ' . ($seekerData['skills'] ?? '');
$recommendedJobs = fetchAll("
    SELECT 
        j.id,
        j.title,
        j.slug,
        j.description,
        j.location_type,
        j.city,
        j.country,
        j.salary_min,
        j.salary_max,
        j.salary_currency,
        j.salary_type,
        j.experience_level,
        j.created_at,
        j.is_featured,
        j.is_urgent,
        e.company_name,
        e.company_logo,
        e.industry,
        e.is_verified as employer_verified,
        jc.name as category_name,
        jt.name as job_type_name,
        (SELECT COUNT(*) FROM applications WHERE job_id = j.id) as application_count,
        CASE 
            WHEN j.application_deadline < CURDATE() THEN 'expired'
            ELSE 'active'
        END as status
    FROM jobs j
    INNER JOIN employers e ON j.employer_id = e.id
    INNER JOIN job_categories jc ON j.category_id = jc.id
    INNER JOIN job_types jt ON j.job_type_id = jt.id
    WHERE j.status = 'active' 
        AND j.application_deadline >= CURDATE()
        AND j.id NOT IN (SELECT job_id FROM applications WHERE seeker_id = ?)
    ORDER BY j.is_featured DESC, j.created_at DESC
    LIMIT 8
", [$seeker_id]);

// 6. Application Status Distribution for Chart
$applicationStats = fetchAll("
    SELECT 
        application_status,
        COUNT(*) as count
    FROM applications
    WHERE seeker_id = ?
    GROUP BY application_status
", [$seeker_id]);

// 7. Weekly Activity (Last 7 days)
$weeklyActivity = fetchAll("
    SELECT 
        DATE(created_at) as date,
        COUNT(*) as applications,
        0 as interviews
    FROM applications
    WHERE seeker_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    GROUP BY DATE(created_at)
    UNION ALL
    SELECT 
        DATE(i.created_at) as date,
        0 as applications,
        COUNT(*) as interviews
    FROM interviews i
    INNER JOIN applications a ON i.application_id = a.id
    WHERE a.seeker_id = ? AND i.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    GROUP BY DATE(i.created_at)
    ORDER BY date ASC
", [$seeker_id, $seeker_id]);

// Process weekly activity for chart
$weekDays = [];
$appData = [];
$interviewData = [];
$activityMap = [];

if ($weeklyActivity) {
    foreach ($weeklyActivity as $activity) {
        $date = $activity['date'];
        if (!isset($activityMap[$date])) {
            $activityMap[$date] = ['applications' => 0, 'interviews' => 0];
        }
        $activityMap[$date]['applications'] += (int)$activity['applications'];
        $activityMap[$date]['interviews'] += (int)$activity['interviews'];
    }
}

for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $weekDays[] = date('D', strtotime($date));
    $appData[] = isset($activityMap[$date]) ? $activityMap[$date]['applications'] : 0;
    $interviewData[] = isset($activityMap[$date]) ? $activityMap[$date]['interviews'] : 0;
}

// 8. Recent Messages
$recentMessages = fetchAll("
    SELECT 
        m.*,
        sender.email as sender_email,
        sender.user_type as sender_type,
        CASE 
            WHEN sender.user_type = 'employer' THEN (SELECT company_name FROM employers WHERE user_id = sender.id)
            WHEN sender.user_type = 'seeker' THEN CONCAT((SELECT first_name FROM seekers WHERE user_id = sender.id), ' ', (SELECT last_name FROM seekers WHERE user_id = sender.id))
            ELSE 'System'
        END as sender_name,
        CASE 
            WHEN sender.user_type = 'employer' THEN (SELECT company_logo FROM employers WHERE user_id = sender.id)
            WHEN sender.user_type = 'seeker' THEN (SELECT profile_picture FROM seekers WHERE user_id = sender.id)
            ELSE NULL
        END as sender_avatar
    FROM messages m
    INNER JOIN users sender ON m.sender_id = sender.id
    WHERE m.receiver_id = ?
    ORDER BY m.created_at DESC
    LIMIT 8
", [$user_id]);

// 9. Profile Completion Stats
$profileCompletion = [
    'personal_info' => !empty($seekerData['first_name']) && !empty($seekerData['last_name']) && !empty($seekerData['phone']),
    'professional' => !empty($seekerData['headline']) && !empty($seekerData['bio']),
    'experience' => !empty($seekerData['years_experience']) && !empty($seekerData['current_job_title']),
    'education' => !empty($seekerData['highest_education']),
    'skills' => !empty($seekerData['skills']),
    'resume' => !empty($seekerData['resume_url']),
    'photo' => !empty($seekerData['profile_picture']),
    'salary' => !empty($seekerData['expected_salary_min']) || !empty($seekerData['expected_salary_max']),
    'preferences' => false,
    'languages' => false
];

// Check preferences and languages
$prefCount = fetchOne("SELECT COUNT(*) as count FROM seeker_preferences WHERE seeker_id = ?", [$seeker_id]);
if ($prefCount) {
    $profileCompletion['preferences'] = $prefCount['count'] > 0;
}

$langCount = fetchOne("SELECT COUNT(*) as count FROM seeker_languages WHERE seeker_id = ?", [$seeker_id]);
if ($langCount) {
    $profileCompletion['languages'] = $langCount['count'] > 0;
}

$completedItems = count(array_filter($profileCompletion));
$totalItems = count($profileCompletion);
$completionPercentage = $totalItems > 0 ? round(($completedItems / $totalItems) * 100) : 0;

// 10. Similar Jobs Based on Applications
$similarJobs = fetchAll("
    SELECT DISTINCT
        j.id,
        j.title,
        j.slug,
        j.location_type,
        j.city,
        j.country,
        j.salary_min,
        j.salary_max,
        j.salary_currency,
        j.created_at,
        e.company_name,
        e.company_logo,
        e.industry,
        jc.name as category_name
    FROM jobs j
    INNER JOIN employers e ON j.employer_id = e.id
    INNER JOIN job_categories jc ON j.category_id = jc.id
    WHERE j.status = 'active'
        AND j.application_deadline >= CURDATE()
        AND j.category_id IN (
            SELECT DISTINCT j2.category_id 
            FROM applications a
            INNER JOIN jobs j2 ON a.job_id = j2.id
            WHERE a.seeker_id = ?
        )
        AND j.id NOT IN (SELECT job_id FROM applications WHERE seeker_id = ?)
    ORDER BY j.created_at DESC
    LIMIT 6
", [$seeker_id, $seeker_id]);

// 11. Recent Notifications
$recentNotifications = fetchAll("
    SELECT * FROM notifications 
    WHERE user_id = ? 
    ORDER BY created_at DESC 
    LIMIT 5
", [$user_id]);

// Get avatar URL
$avatarUrl = !empty($seekerData['profile_picture']) 
    ? htmlspecialchars($seekerData['profile_picture']) 
    : 'https://ui-avatars.com/api/?name=' . urlencode($seekerData['first_name'] . '+' . $seekerData['last_name']) . '&background=4f46e5&color=fff&size=128&bold=true';

// Helper function to format salary
if (!function_exists('formatSalary')) {
    function formatSalary($min, $max, $currency = 'USD') {
        if (empty($min) && empty($max)) return 'Not specified';
        $symbols = ['USD' => '$', 'EUR' => '€', 'GBP' => '£', 'INR' => '₹'];
        $symbol = isset($symbols[$currency]) ? $symbols[$currency] : $currency . ' ';
        
        if (!empty($min) && !empty($max)) {
            return $symbol . number_format($min) . ' - ' . $symbol . number_format($max);
        } elseif (!empty($min)) {
            return 'From ' . $symbol . number_format($min);
        } elseif (!empty($max)) {
            return 'Up to ' . $symbol . number_format($max);
        }
        return 'Not specified';
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Dashboard - TransNow</title>
    
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
    
    <!-- SweetAlert2 -->
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    
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
        
        .status-applied { @apply bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300; }
        .status-reviewed { @apply bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-300; }
        .status-shortlisted { @apply bg-indigo-100 text-indigo-800 dark:bg-indigo-900 dark:text-indigo-300; }
        .status-interview { @apply bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300; }
        .status-offered { @apply bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300; }
        .status-hired { @apply bg-emerald-100 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-300; }
        .status-rejected { @apply bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300; }
        .status-active { @apply bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300; }
        
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
        
        /* Metric Card */
        .metric-card {
            background: linear-gradient(135deg, rgba(79, 70, 229, 0.1), rgba(124, 58, 237, 0.1));
            border: 1px solid rgba(79, 70, 229, 0.2);
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
        
        /* Loading Skeleton */
        .skeleton {
            background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
            background-size: 200% 100%;
            animation: shimmer 1.5s infinite;
        }
        
        @keyframes shimmer {
            0% { background-position: -200% 0; }
            100% { background-position: 200% 0; }
        }
        
        /* Stat Trend */
        .stat-trend-up {
            @apply text-green-600 bg-green-100 dark:text-green-400 dark:bg-green-900/30;
        }
        
        .stat-trend-down {
            @apply text-red-600 bg-red-100 dark:text-red-400 dark:bg-red-900/30;
        }
        
        /* Job Card */
        .job-card {
            transition: all 0.3s ease;
        }
        
        .job-card:hover {
            transform: translateX(5px);
        }
        
        /* Interview Card */
        .interview-card {
            border-left: 4px solid;
        }
        
        .interview-card.video { border-left-color: #8b5cf6; }
        .interview-card.phone { border-left-color: #10b981; }
        .interview-card.in_person { border-left-color: #f59e0b; }
    </style>
</head>
<body class="bg-gray-50 dark:bg-gray-900 text-gray-900 dark:text-white transition-colors duration-300">

<!-- Flash Message -->
<?php if (function_exists('displayFlashMessage')) echo displayFlashMessage(); ?>

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
                </a>
            </div>
            
            <!-- Seeker Profile Summary -->
            <div class="px-6 py-4 border-t border-b border-gray-200 dark:border-gray-700">
                <div class="flex items-center space-x-4">
                    <div class="relative">
                        <img src="<?php echo $avatarUrl; ?>" 
                             alt="<?php echo htmlspecialchars($seekerData['first_name'] . ' ' . $seekerData['last_name']); ?>" 
                             class="w-16 h-16 rounded-xl object-cover border-2 border-indigo-200 dark:border-indigo-800">
                        <div class="absolute -bottom-1 -right-1 w-5 h-5 bg-green-500 border-2 border-white dark:border-gray-800 rounded-full animate-pulse"></div>
                    </div>
                    <div class="flex-1 min-w-0">
                        <h3 class="font-bold text-lg truncate"><?php echo htmlspecialchars($seekerData['first_name'] . ' ' . $seekerData['last_name']); ?></h3>
                        <p class="text-sm text-gray-600 dark:text-gray-400 truncate">
                            <?php echo htmlspecialchars($seekerData['headline'] ?? 'Job Seeker'); ?>
                        </p>
                        <div class="flex items-center mt-1">
                            <?php if ($seekerData['is_verified']): ?>
                                <i class="fas fa-check-circle text-blue-500 mr-1"></i>
                                <span class="text-xs text-gray-500">Verified</span>
                            <?php else: ?>
                                <span class="text-xs text-yellow-500">Pending Verification</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Profile Completion -->
                <div class="mt-4">
                    <div class="flex items-center justify-between text-sm mb-1">
                        <span class="text-gray-600 dark:text-gray-400">Profile Completion</span>
                        <span class="font-semibold text-indigo-600"><?php echo $completionPercentage; ?>%</span>
                    </div>
                    <div class="w-full h-2 bg-gray-200 dark:bg-gray-700 rounded-full overflow-hidden">
                        <div class="progress-bar h-full rounded-full" style="width: <?php echo $completionPercentage; ?>%"></div>
                    </div>
                </div>
                
                <!-- Quick Stats -->
                <div class="grid grid-cols-2 gap-2 mt-4">
                    <div class="text-center p-2 bg-indigo-50 dark:bg-indigo-900/20 rounded-lg">
                        <div class="text-lg font-bold text-indigo-600"><?php echo $overallStats['applications_this_week']; ?></div>
                        <div class="text-xs text-gray-500">This Week</div>
                    </div>
                    <div class="text-center p-2 bg-purple-50 dark:bg-purple-900/20 rounded-lg">
                        <div class="text-lg font-bold text-purple-600"><?php echo $overallStats['unread_notifications']; ?></div>
                        <div class="text-xs text-gray-500">Alerts</div>
                    </div>
                </div>
            </div>
            
            <!-- Navigation -->
            <nav class="flex-1 px-4 py-6 space-y-1">
                <!-- Dashboard -->
                <a href="dashboard.php" class="flex items-center space-x-3 px-4 py-3 bg-indigo-50 dark:bg-indigo-900/20 text-indigo-600 dark:text-indigo-400 rounded-xl font-medium">
                    <i class="fas fa-tachometer-alt w-5"></i>
                    <span>Dashboard</span>
                </a>
                
                <!-- Profile -->
                <a href="profile.php" class="flex items-center space-x-3 px-4 py-3 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition group">
                    <i class="fas fa-user-circle w-5"></i>
                    <span>My Profile</span>
                    <?php if ($completionPercentage < 100): ?>
                    <span class="ml-auto text-xs bg-yellow-500 text-white px-2 py-1 rounded-full group-hover:bg-yellow-600">!</span>
                    <?php endif; ?>
                </a>
                
                <!-- Browse Jobs -->
                <a href="jobs.php" class="flex items-center space-x-3 px-4 py-3 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                    <i class="fas fa-search w-5"></i>
                    <span>Browse Jobs</span>
                </a>
                
                <!-- My Applications -->
                <a href="applications.php" class="flex items-center space-x-3 px-4 py-3 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition group">
                    <i class="fas fa-file-alt w-5"></i>
                    <span>My Applications</span>
                    <?php if (($overallStats['pending_applications'] ?? 0) > 0): ?>
                    <span class="ml-auto bg-blue-500 text-white text-xs px-2 py-1 rounded-full group-hover:bg-blue-600"><?php echo $overallStats['pending_applications']; ?></span>
                    <?php endif; ?>
                </a>
                
                <!-- Saved Jobs -->
                <a href="saved-jobs.php" class="flex items-center space-x-3 px-4 py-3 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                    <i class="fas fa-bookmark w-5"></i>
                    <span>Saved Jobs</span>
                    <?php if (($overallStats['saved_jobs_count'] ?? 0) > 0): ?>
                    <span class="ml-auto bg-purple-500 text-white text-xs px-2 py-1 rounded-full"><?php echo $overallStats['saved_jobs_count']; ?></span>
                    <?php endif; ?>
                </a>
                
                <!-- Interviews -->
                <a href="interviews.php" class="flex items-center space-x-3 px-4 py-3 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition relative">
                    <i class="fas fa-calendar-alt w-5"></i>
                    <span>Interviews</span>
                    <?php if (($overallStats['upcoming_interviews'] ?? 0) > 0): ?>
                    <span class="ml-auto bg-green-500 text-white text-xs px-2 py-1 rounded-full animate-pulse"><?php echo $overallStats['upcoming_interviews']; ?></span>
                    <?php endif; ?>
                </a>
                
                <!-- Messages -->
                <a href="/messages.php" class="flex items-center space-x-3 px-4 py-3 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition relative">
                    <i class="fas fa-envelope w-5"></i>
                    <span>Messages</span>
                    <?php if (($overallStats['unread_messages'] ?? 0) > 0): ?>
                    <span class="ml-auto bg-red-500 text-white text-xs px-2 py-1 rounded-full"><?php echo $overallStats['unread_messages']; ?></span>
                    <?php endif; ?>
                </a>
                
                <!-- Notifications -->
                <a href="/notifications.php" class="flex items-center space-x-3 px-4 py-3 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                    <i class="fas fa-bell w-5"></i>
                    <span>Notifications</span>
                    <?php if (($overallStats['unread_notifications'] ?? 0) > 0): ?>
                    <span class="ml-auto bg-yellow-500 text-white text-xs px-2 py-1 rounded-full"><?php echo $overallStats['unread_notifications']; ?></span>
                    <?php endif; ?>
                </a>
                
                <!-- Settings -->
                <a href="settings.php" class="flex items-center space-x-3 px-4 py-3 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                    <i class="fas fa-cog w-5"></i>
                    <span>Settings</span>
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
            
            <!-- Sidebar Footer - Quick Tips -->
            <div class="p-4 border-t border-gray-200 dark:border-gray-700">
                <div class="bg-gradient-to-r from-indigo-500 to-purple-600 rounded-xl p-4 text-white">
                    <h4 class="font-semibold text-sm mb-2 flex items-center">
                        <i class="fas fa-lightbulb mr-2"></i>
                        Pro Tip
                    </h4>
                    <p class="text-xs opacity-90">
                        <?php if ($completionPercentage < 100): ?>
                            Complete your profile to get better job matches! (<?php echo $completionPercentage; ?>% done)
                        <?php elseif (empty($recommendedJobs)): ?>
                            Update your preferences to get personalized job recommendations.
                        <?php else: ?>
                            Apply to jobs quickly to increase your chances!
                        <?php endif; ?>
                    </p>
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
                        Welcome back, <span class="gradient-text"><?php echo htmlspecialchars($seekerData['first_name']); ?>!</span>
                        <span class="text-sm font-normal text-gray-500 ml-3">
                            <i class="far fa-calendar-alt mr-1"></i> <?php echo date('l, F j, Y'); ?>
                        </span>
                    </h1>
                    
                    <!-- Right Actions -->
                    <div class="flex items-center space-x-4">
                        <!-- Quick Actions Dropdown -->
                        <div class="relative">
                            <button id="quickActionsBtn" class="px-4 py-2 bg-gradient-to-r from-indigo-600 to-purple-600 text-white rounded-xl font-medium hover:shadow-lg transition-all duration-300 flex items-center space-x-2">
                                <i class="fas fa-bolt"></i>
                                <span>Quick Actions</span>
                                <i class="fas fa-chevron-down text-xs"></i>
                            </button>
                            
                            <!-- Quick Actions Dropdown Menu -->
                            <div id="quickActionsDropdown" class="absolute right-0 mt-2 w-64 bg-white dark:bg-gray-800 rounded-2xl shadow-2xl border border-gray-200 dark:border-gray-700 hidden z-50">
                                <div class="p-2">
                                    <a href="jobs.php" class="flex items-center space-x-3 px-4 py-3 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                                        <i class="fas fa-search text-indigo-600 w-5"></i>
                                        <span>Browse Jobs</span>
                                    </a>
                                    <a href="profile.php" class="flex items-center space-x-3 px-4 py-3 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                                        <i class="fas fa-user-edit text-purple-600 w-5"></i>
                                        <span>Update Profile</span>
                                    </a>
                                    <a href="applications.php" class="flex items-center space-x-3 px-4 py-3 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                                        <i class="fas fa-file-alt text-green-600 w-5"></i>
                                        <span>Track Applications</span>
                                    </a>
                                    <a href="saved-jobs.php" class="flex items-center space-x-3 px-4 py-3 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                                        <i class="fas fa-bookmark text-blue-600 w-5"></i>
                                        <span>View Saved Jobs</span>
                                    </a>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Notifications -->
                        <div class="relative">
                            <button id="notificationBtn" class="w-10 h-10 bg-gray-100 dark:bg-gray-700 rounded-lg flex items-center justify-center hover:bg-gray-200 dark:hover:bg-gray-600 transition relative">
                                <i class="fas fa-bell"></i>
                                <?php if (($overallStats['unread_notifications'] ?? 0) > 0): ?>
                                <span class="absolute -top-1 -right-1 w-5 h-5 bg-red-500 text-white text-xs rounded-full flex items-center justify-center animate-pulse">
                                    <?php echo min($overallStats['unread_notifications'], 9); ?>+
                                </span>
                                <?php endif; ?>
                            </button>
                            
                            <!-- Notifications Dropdown -->
                            <div id="notificationDropdown" class="absolute right-0 mt-2 w-96 bg-white dark:bg-gray-800 rounded-2xl shadow-2xl border border-gray-200 dark:border-gray-700 hidden z-50">
                                <div class="p-4 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
                                    <h3 class="font-bold">Notifications</h3>
                                    <a href="#" onclick="markAllNotificationsRead()" class="text-sm text-indigo-600 hover:underline">Mark all read</a>
                                </div>
                                <div class="max-h-96 overflow-y-auto">
                                    <?php if (empty($recentNotifications)): ?>
                                    <div class="p-8 text-center text-gray-500">
                                        <i class="fas fa-bell-slash text-4xl mb-3"></i>
                                        <p>No notifications</p>
                                    </div>
                                    <?php else: ?>
                                    <?php foreach ($recentNotifications as $notif): ?>
                                    <div class="p-4 border-b border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700 transition <?php echo ($notif['status'] ?? '') == 'pending' ? 'bg-blue-50 dark:bg-blue-900/10' : ''; ?>">
                                        <div class="flex items-start space-x-3">
                                            <div class="w-8 h-8 bg-gradient-to-r from-indigo-500 to-purple-500 rounded-lg flex items-center justify-center text-white flex-shrink-0">
                                                <i class="fas fa-bell text-sm"></i>
                                            </div>
                                            <div class="flex-1 min-w-0">
                                                <p class="text-sm"><?php echo htmlspecialchars($notif['message'] ?? 'New notification'); ?></p>
                                                <p class="text-xs text-gray-500 mt-1"><?php echo isset($notif['created_at']) ? timeAgo($notif['created_at']) : 'Just now'; ?></p>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                                <div class="p-3 border-t border-gray-200 dark:border-gray-700 text-center">
                                    <a href="/notifications.php" class="text-sm text-indigo-600 hover:underline">View all notifications</a>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Profile Quick Menu -->
                        <div class="relative">
                            <button id="profileMenuBtn" class="flex items-center space-x-3">
                                <img src="<?php echo $avatarUrl; ?>" 
                                     alt="Profile" 
                                     class="w-10 h-10 rounded-lg object-cover border-2 border-indigo-200 dark:border-indigo-800">
                            </button>
                            
                            <!-- Profile Dropdown -->
                            <div id="profileDropdown" class="absolute right-0 mt-2 w-48 bg-white dark:bg-gray-800 rounded-2xl shadow-2xl border border-gray-200 dark:border-gray-700 hidden z-50">
                                <div class="p-2">
                                    <a href="profile.php" class="flex items-center space-x-3 px-4 py-3 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                                        <i class="fas fa-user text-indigo-600 w-5"></i>
                                        <span>My Profile</span>
                                    </a>
                                    <a href="settings.php" class="flex items-center space-x-3 px-4 py-3 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                                        <i class="fas fa-cog text-purple-600 w-5"></i>
                                        <span>Settings</span>
                                    </a>
                                    <a href="/logout.php" class="flex items-center space-x-3 px-4 py-3 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition text-red-600">
                                        <i class="fas fa-sign-out-alt w-5"></i>
                                        <span>Logout</span>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <!-- Dashboard Content -->
        <div class="p-6">
            <!-- Welcome Banner -->
            <div class="mb-8 bg-gradient-to-r from-indigo-500 via-purple-500 to-pink-500 rounded-3xl p-8 text-white relative overflow-hidden" data-aos="fade-down">
                <div class="absolute top-0 right-0 w-64 h-64 bg-white/10 rounded-full -mr-32 -mt-32 animate-float"></div>
                <div class="absolute bottom-0 left-0 w-48 h-48 bg-white/10 rounded-full -ml-24 -mb-24 animate-float" style="animation-delay: 2s;"></div>
                
                <div class="relative z-10">
                    <div class="flex items-center justify-between flex-wrap gap-6">
                        <div>
                            <h2 class="text-3xl font-bold mb-2">Your Job Search Journey</h2>
                            <p class="text-white/90 text-lg">
                                <?php if (($overallStats['total_applications'] ?? 0) > 0): ?>
                                    You've applied to <span class="font-bold"><?php echo $overallStats['total_applications']; ?></span> jobs so far. Keep up the great work!
                                <?php else: ?>
                                    Ready to find your dream job? Start exploring opportunities now!
                                <?php endif; ?>
                            </p>
                        </div>
                        <a href="jobs.php" class="px-8 py-4 bg-white text-indigo-600 rounded-xl font-bold hover:shadow-2xl transition-all duration-300 hover:scale-105">
                            <i class="fas fa-search mr-2"></i>
                            Browse Jobs
                        </a>
                    </div>
                </div>
            </div>

            <!-- Key Metrics Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <!-- Total Applications -->
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6 hover-card metric-card" data-aos="fade-up" data-aos-delay="0">
                    <div class="flex items-center justify-between mb-3">
                        <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900/20 rounded-xl flex items-center justify-center">
                            <i class="fas fa-file-alt text-blue-600 dark:text-blue-400 text-xl"></i>
                        </div>
                        <span class="text-xs font-semibold text-blue-600 bg-blue-100 dark:bg-blue-900/30 px-3 py-1 rounded-full">
                            +<?php echo $overallStats['applications_this_week'] ?? 0; ?> this week
                        </span>
                    </div>
                    <h3 class="text-2xl font-bold mb-1"><?php echo number_format($overallStats['total_applications'] ?? 0); ?></h3>
                    <p class="text-sm text-gray-600 dark:text-gray-400">Total Applications</p>
                    <div class="mt-4 grid grid-cols-2 gap-2 text-xs">
                        <div>
                            <span class="block font-semibold text-yellow-600"><?php echo $overallStats['pending_applications'] ?? 0; ?></span>
                            <span class="text-gray-500">Pending</span>
                        </div>
                        <div>
                            <span class="block font-semibold text-green-600"><?php echo $overallStats['interview_applications'] ?? 0; ?></span>
                            <span class="text-gray-500">Interviews</span>
                        </div>
                    </div>
                </div>

                <!-- Saved Jobs -->
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6 hover-card metric-card" data-aos="fade-up" data-aos-delay="50">
                    <div class="flex items-center justify-between mb-3">
                        <div class="w-12 h-12 bg-purple-100 dark:bg-purple-900/20 rounded-xl flex items-center justify-center">
                            <i class="fas fa-bookmark text-purple-600 dark:text-purple-400 text-xl"></i>
                        </div>
                    </div>
                    <h3 class="text-2xl font-bold mb-1"><?php echo number_format($overallStats['saved_jobs_count'] ?? 0); ?></h3>
                    <p class="text-sm text-gray-600 dark:text-gray-400">Saved Jobs</p>
                    <a href="saved-jobs.php" class="mt-4 inline-flex items-center text-sm text-purple-600 hover:underline">
                        View saved jobs <i class="fas fa-arrow-right ml-2"></i>
                    </a>
                </div>

                <!-- Interviews -->
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6 hover-card metric-card" data-aos="fade-up" data-aos-delay="100">
                    <div class="flex items-center justify-between mb-3">
                        <div class="w-12 h-12 bg-green-100 dark:bg-green-900/20 rounded-xl flex items-center justify-center">
                            <i class="fas fa-calendar-check text-green-600 dark:text-green-400 text-xl"></i>
                        </div>
                    </div>
                    <h3 class="text-2xl font-bold mb-1"><?php echo number_format($overallStats['upcoming_interviews'] ?? 0); ?></h3>
                    <p class="text-sm text-gray-600 dark:text-gray-400">Upcoming Interviews</p>
                    <div class="mt-4 text-xs text-gray-500">
                        <?php echo $overallStats['completed_interviews'] ?? 0; ?> completed
                    </div>
                </div>

                <!-- Success Rate -->
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6 hover-card metric-card" data-aos="fade-up" data-aos-delay="150">
                    <div class="flex items-center justify-between mb-3">
                        <div class="w-12 h-12 bg-yellow-100 dark:bg-yellow-900/20 rounded-xl flex items-center justify-center">
                            <i class="fas fa-chart-line text-yellow-600 dark:text-yellow-400 text-xl"></i>
                        </div>
                    </div>
                    <?php 
                    $totalApps = $overallStats['total_applications'] ?? 0;
                    $successRate = $totalApps > 0 ? round((($overallStats['interview_applications'] ?? 0) / $totalApps) * 100) : 0;
                    ?>
                    <h3 class="text-2xl font-bold mb-1"><?php echo $successRate; ?>%</h3>
                    <p class="text-sm text-gray-600 dark:text-gray-400">Interview Rate</p>
                    <div class="mt-4 w-full bg-gray-200 dark:bg-gray-700 rounded-full h-1.5">
                        <div class="bg-yellow-500 h-1.5 rounded-full" style="width: <?php echo $successRate; ?>%"></div>
                    </div>
                </div>
            </div>

            <!-- Charts and Upcoming Interviews -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
                <!-- Weekly Activity Chart -->
                <div class="lg:col-span-2 bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6" data-aos="fade-up">
                    <div class="flex items-center justify-between mb-6">
                        <div>
                            <h2 class="text-xl font-bold">Weekly Activity</h2>
                            <p class="text-sm text-gray-500 mt-1">Your applications and interviews (last 7 days)</p>
                        </div>
                    </div>
                    <div class="chart-container" style="height: 250px;">
                        <canvas id="activityChart"></canvas>
                    </div>
                    <div class="flex items-center justify-center mt-4 space-x-6">
                        <div class="flex items-center">
                            <span class="w-3 h-3 bg-blue-500 rounded-full mr-2"></span>
                            <span class="text-sm">Applications</span>
                        </div>
                        <div class="flex items-center">
                            <span class="w-3 h-3 bg-green-500 rounded-full mr-2"></span>
                            <span class="text-sm">Interviews</span>
                        </div>
                    </div>
                </div>

                <!-- Upcoming Interviews -->
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6" data-aos="fade-up">
                    <h2 class="text-xl font-bold mb-4 flex items-center">
                        <i class="fas fa-calendar-alt text-green-500 mr-2"></i>
                        Upcoming Interviews
                        <?php if (!empty($upcomingInterviews)): ?>
                        <span class="ml-auto text-sm bg-green-100 dark:bg-green-900 text-green-600 dark:text-green-400 px-3 py-1 rounded-full">
                            <?php echo count($upcomingInterviews); ?>
                        </span>
                        <?php endif; ?>
                    </h2>
                    
                    <?php if (empty($upcomingInterviews)): ?>
                    <div class="text-center py-8">
                        <i class="fas fa-calendar-check text-4xl text-gray-400 mb-3"></i>
                        <p class="text-gray-500">No upcoming interviews</p>
                        <p class="text-xs text-gray-400 mt-2">Keep applying to get interview calls!</p>
                    </div>
                    <?php else: ?>
                    <div class="space-y-4">
                        <?php foreach ($upcomingInterviews as $interview): ?>
                        <div class="p-4 border border-gray-200 dark:border-gray-700 rounded-xl hover:shadow-lg transition interview-card <?php echo $interview['interview_type'] ?? 'video'; ?>">
                            <div class="flex items-start justify-between">
                                <div>
                                    <h3 class="font-semibold"><?php echo htmlspecialchars($interview['job_title'] ?? 'Interview'); ?></h3>
                                    <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">
                                        <?php echo htmlspecialchars($interview['company_name'] ?? 'Company'); ?>
                                    </p>
                                </div>
                                <span class="text-xs bg-green-100 dark:bg-green-900 text-green-600 dark:text-green-400 px-2 py-1 rounded-full">
                                    <?php echo ucfirst($interview['interview_type'] ?? 'Video'); ?>
                                </span>
                            </div>
                            <div class="mt-3 space-y-2 text-sm">
                                <div class="flex items-center text-gray-600 dark:text-gray-400">
                                    <i class="fas fa-clock w-4 mr-2"></i>
                                    <?php echo isset($interview['interview_date']) ? date('M j, Y g:i A', strtotime($interview['interview_date'])) : 'Date not set'; ?>
                                </div>
                                <?php if (!empty($interview['meeting_link'])): ?>
                                <div class="flex items-center text-indigo-600">
                                    <i class="fas fa-video w-4 mr-2"></i>
                                    <a href="<?php echo htmlspecialchars($interview['meeting_link']); ?>" target="_blank" class="hover:underline">
                                        Join Meeting
                                    </a>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($interview['location'])): ?>
                                <div class="flex items-center text-gray-600 dark:text-gray-400">
                                    <i class="fas fa-map-marker-alt w-4 mr-2"></i>
                                    <?php echo htmlspecialchars($interview['location']); ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Recent Applications -->
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6 mb-8" data-aos="fade-up">
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-xl font-bold">Recent Applications</h2>
                    <a href="applications.php" class="text-indigo-600 dark:text-indigo-400 hover:underline text-sm flex items-center">
                        View All <i class="fas fa-arrow-right ml-2"></i>
                    </a>
                </div>
                
                <?php if (empty($recentApplications)): ?>
                <div class="text-center py-12">
                    <i class="fas fa-file-alt text-4xl text-gray-400 mb-3"></i>
                    <p class="text-gray-500">No applications yet</p>
                    <a href="jobs.php" class="inline-block mt-4 px-6 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition">
                        Browse Jobs
                    </a>
                </div>
                <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead>
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Job Position</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Company</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Applied Date</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Match Score</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            <?php foreach ($recentApplications as $app): ?>
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50 transition">
                                <td class="px-4 py-3">
                                    <a href="job-details.php?slug=<?php echo $app['job_slug'] ?? ''; ?>" class="font-medium hover:text-indigo-600 transition">
                                        <?php echo htmlspecialchars($app['job_title'] ?? 'Job'); ?>
                                    </a>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center">
                                        <img src="<?php 
                                            echo !empty($app['company_logo']) ? htmlspecialchars($app['company_logo']) : 'https://ui-avatars.com/api/?name=' . urlencode($app['company_name'] ?? 'Company') . '&background=4f46e5&color=fff';
                                        ?>" alt="" class="w-6 h-6 rounded mr-2 object-cover">
                                        <span class="text-sm"><?php echo htmlspecialchars($app['company_name'] ?? 'Company'); ?></span>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-500">
                                    <?php echo isset($app['applied_at']) ? timeAgo($app['applied_at']) : 'N/A'; ?>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="status-badge status-<?php echo $app['application_status'] ?? 'applied'; ?>">
                                        <i class="fas <?php 
                                            echo ($app['application_status'] ?? 'applied') == 'applied' ? 'fa-paper-plane' : 
                                                (($app['application_status'] ?? '') == 'reviewed' ? 'fa-eye' : 
                                                (($app['application_status'] ?? '') == 'shortlisted' ? 'fa-star' : 
                                                (($app['application_status'] ?? '') == 'interview' ? 'fa-calendar-check' : 
                                                (($app['application_status'] ?? '') == 'offered' ? 'fa-gift' : 
                                                (($app['application_status'] ?? '') == 'hired' ? 'fa-check-circle' : 'fa-times-circle')))));
                                        ?> mr-1"></i>
                                        <?php echo ucfirst($app['application_status'] ?? 'applied'); ?>
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <?php if (!empty($app['match_score'])): ?>
                                    <div class="flex items-center">
                                        <span class="text-sm font-semibold <?php echo $app['match_score'] >= 80 ? 'text-green-600' : ($app['match_score'] >= 60 ? 'text-yellow-600' : 'text-gray-600'); ?>">
                                            <?php echo $app['match_score']; ?>%
                                        </span>
                                    </div>
                                    <?php else: ?>
                                    <span class="text-sm text-gray-400">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3">
                                    <a href="application-details.php?id=<?php echo $app['id']; ?>" class="text-indigo-600 hover:text-indigo-800 dark:hover:text-indigo-400">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <!-- Recommended Jobs & Saved Jobs -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                <!-- Recommended Jobs -->
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6" data-aos="fade-up">
                    <h2 class="text-xl font-bold mb-4 flex items-center">
                        <i class="fas fa-star text-yellow-500 mr-2"></i>
                        Recommended for You
                    </h2>
                    
                    <?php if (empty($recommendedJobs)): ?>
                    <div class="text-center py-8">
                        <i class="fas fa-briefcase text-4xl text-gray-400 mb-3"></i>
                        <p class="text-gray-500">No recommendations yet</p>
                        <p class="text-xs text-gray-400 mt-2">Complete your profile for better matches!</p>
                    </div>
                    <?php else: ?>
                    <div class="space-y-4">
                        <?php foreach ($recommendedJobs as $job): ?>
                        <div class="p-4 border border-gray-200 dark:border-gray-700 rounded-xl hover:shadow-lg transition job-card">
                            <div class="flex items-start justify-between">
                                <div class="flex items-start space-x-3">
                                    <img src="<?php 
                                        echo !empty($job['company_logo']) ? htmlspecialchars($job['company_logo']) : 'https://ui-avatars.com/api/?name=' . urlencode($job['company_name'] ?? 'Company') . '&background=4f46e5&color=fff';
                                    ?>" alt="" class="w-12 h-12 rounded-lg object-cover">
                                    <div>
                                        <a href="job-details.php?slug=<?php echo $job['slug'] ?? ''; ?>" class="font-semibold hover:text-indigo-600 transition">
                                            <?php echo htmlspecialchars($job['title'] ?? 'Job Position'); ?>
                                        </a>
                                        <p class="text-sm text-gray-600 dark:text-gray-400">
                                            <?php echo htmlspecialchars($job['company_name'] ?? 'Company'); ?>
                                            <?php if (!empty($job['employer_verified'])): ?>
                                            <i class="fas fa-check-circle text-blue-500 text-xs ml-1"></i>
                                            <?php endif; ?>
                                        </p>
                                        <div class="flex items-center text-xs text-gray-500 mt-2 space-x-2">
                                            <span class="bg-gray-100 dark:bg-gray-700 px-2 py-1 rounded-full">
                                                <?php echo htmlspecialchars($job['location_type'] ?? 'Onsite'); ?>
                                            </span>
                                            <?php if (!empty($job['city'])): ?>
                                            <span class="flex items-center">
                                                <i class="fas fa-map-marker-alt mr-1"></i>
                                                <?php echo htmlspecialchars($job['city']); ?>
                                            </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <?php if (!empty($job['is_featured'])): ?>
                                <span class="text-xs bg-yellow-500 text-white px-2 py-1 rounded-full">Featured</span>
                                <?php endif; ?>
                            </div>
                            <div class="mt-3 flex items-center justify-between">
                                <div class="text-sm">
                                    <?php if (!empty($job['salary_min']) || !empty($job['salary_max'])): ?>
                                    <span class="font-semibold text-green-600">
                                        <?php echo formatSalary($job['salary_min'] ?? null, $job['salary_max'] ?? null, $job['salary_currency'] ?? 'USD'); ?>
                                    </span>
                                    <?php endif; ?>
                                </div>
                                <a href="job-details.php?slug=<?php echo $job['slug'] ?? ''; ?>" 
                                   class="px-4 py-2 bg-indigo-600 text-white text-sm rounded-lg hover:bg-indigo-700 transition">
                                    View Job
                                </a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Saved Jobs -->
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6" data-aos="fade-up">
                    <h2 class="text-xl font-bold mb-4 flex items-center">
                        <i class="fas fa-bookmark text-purple-500 mr-2"></i>
                        Saved Jobs
                    </h2>
                    
                    <?php if (empty($savedJobs)): ?>
                    <div class="text-center py-8">
                        <i class="fas fa-bookmark text-4xl text-gray-400 mb-3"></i>
                        <p class="text-gray-500">No saved jobs yet</p>
                        <a href="jobs.php" class="inline-block mt-4 text-indigo-600 hover:underline">
                            Browse jobs to save
                        </a>
                    </div>
                    <?php else: ?>
                    <div class="space-y-4">
                        <?php foreach ($savedJobs as $job): ?>
                        <div class="p-4 border border-gray-200 dark:border-gray-700 rounded-xl hover:shadow-lg transition">
                            <div class="flex items-start justify-between">
                                <div class="flex items-start space-x-3">
                                    <img src="<?php 
                                        echo !empty($job['company_logo']) ? htmlspecialchars($job['company_logo']) : 'https://ui-avatars.com/api/?name=' . urlencode($job['company_name'] ?? 'Company') . '&background=4f46e5&color=fff';
                                    ?>" alt="" class="w-10 h-10 rounded-lg object-cover">
                                    <div>
                                        <a href="job-details.php?slug=<?php echo $job['job_slug'] ?? ''; ?>" class="font-semibold hover:text-indigo-600 transition">
                                            <?php echo htmlspecialchars($job['job_title'] ?? 'Job Position'); ?>
                                        </a>
                                        <p class="text-sm text-gray-600 dark:text-gray-400">
                                            <?php echo htmlspecialchars($job['company_name'] ?? 'Company'); ?>
                                        </p>
                                    </div>
                                </div>
                                <?php if (!empty($job['has_applied'])): ?>
                                <span class="text-xs bg-green-100 dark:bg-green-900 text-green-600 dark:text-green-400 px-2 py-1 rounded-full">
                                    Applied
                                </span>
                                <?php endif; ?>
                            </div>
                            <div class="mt-3 flex items-center justify-between text-sm">
                                <div class="flex items-center space-x-3 text-gray-500">
                                    <span class="flex items-center">
                                        <i class="fas fa-map-marker-alt mr-1"></i>
                                        <?php echo htmlspecialchars($job['city'] ?? ($job['location_type'] ?? 'Remote')); ?>
                                    </span>
                                    <span class="flex items-center">
                                        <i class="far fa-clock mr-1"></i>
                                        <?php echo isset($job['job_posted']) ? timeAgo($job['job_posted']) : 'Recently'; ?>
                                    </span>
                                </div>
                                <a href="job-details.php?slug=<?php echo $job['job_slug'] ?? ''; ?>" 
                                   class="text-indigo-600 hover:text-indigo-800">
                                    <i class="fas fa-arrow-right"></i>
                                </a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Recent Messages -->
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6" data-aos="fade-up">
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-xl font-bold">Recent Messages</h2>
                    <a href="/messages.php" class="text-indigo-600 dark:text-indigo-400 hover:underline text-sm flex items-center">
                        View All <i class="fas fa-arrow-right ml-2"></i>
                    </a>
                </div>
                
                <?php if (empty($recentMessages)): ?>
                <div class="text-center py-8">
                    <i class="fas fa-envelope text-4xl text-gray-400 mb-3"></i>
                    <p class="text-gray-500">No messages yet</p>
                </div>
                <?php else: ?>
                <div class="space-y-4">
                    <?php foreach ($recentMessages as $msg): ?>
                    <div class="flex items-start space-x-3 p-3 hover:bg-gray-50 dark:hover:bg-gray-700/50 rounded-xl transition <?php echo empty($msg['is_read']) ? 'bg-blue-50 dark:bg-blue-900/10' : ''; ?>">
                        <img src="<?php 
                            echo !empty($msg['sender_avatar']) ? htmlspecialchars($msg['sender_avatar']) : 'https://ui-avatars.com/api/?name=' . urlencode($msg['sender_name'] ?? 'User') . '&background=4f46e5&color=fff';
                        ?>" alt="" class="w-10 h-10 rounded-lg object-cover">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center justify-between">
                                <p class="font-semibold"><?php echo htmlspecialchars($msg['sender_name'] ?? 'User'); ?></p>
                                <p class="text-xs text-gray-500"><?php echo isset($msg['created_at']) ? timeAgo($msg['created_at']) : 'Recently'; ?></p>
                            </div>
                            <p class="text-sm text-gray-600 dark:text-gray-400 truncate">
                                <?php echo htmlspecialchars($msg['message'] ?? 'No message content'); ?>
                            </p>
                        </div>
                        <?php if (empty($msg['is_read'])): ?>
                        <span class="w-2 h-2 bg-blue-600 rounded-full"></span>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
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
                    <img src="<?php echo $avatarUrl; ?>" alt="" class="w-12 h-12 rounded-lg object-cover">
                    <div class="flex-1 min-w-0">
                        <p class="font-semibold truncate"><?php echo htmlspecialchars($seekerData['first_name'] . ' ' . $seekerData['last_name']); ?></p>
                        <p class="text-sm text-gray-500 truncate"><?php echo htmlspecialchars($seekerData['headline'] ?? 'Job Seeker'); ?></p>
                    </div>
                </div>
                
                <nav class="space-y-1">
                    <a href="dashboard.php" class="block px-4 py-2 bg-indigo-50 dark:bg-indigo-900/20 text-indigo-600 rounded-lg">Dashboard</a>
                    <a href="profile.php" class="block px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">My Profile</a>
                    <a href="jobs.php" class="block px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">Browse Jobs</a>
                    <a href="applications.php" class="block px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">My Applications</a>
                    <a href="saved-jobs.php" class="block px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">Saved Jobs</a>
                    <a href="interviews.php" class="block px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">Interviews</a>
                    <a href="/messages.php" class="block px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">Messages</a>
                    <a href="/notifications.php" class="block px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">Notifications</a>
                    <a href="settings.php" class="block px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">Settings</a>
                    <a href="/logout.php" class="block px-4 py-2 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg">Logout</a>
                </nav>
            </div>
        </div>
    </div>
</div>

<!-- Scripts -->
<script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

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

// Activity Chart
const ctx = document.getElementById('activityChart')?.getContext('2d');

if (ctx) {
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($weekDays); ?>,
            datasets: [
                {
                    label: 'Applications',
                    data: <?php echo json_encode($appData); ?>,
                    backgroundColor: '#3b82f6',
                    borderRadius: 6,
                    barPercentage: 0.6
                },
                {
                    label: 'Interviews',
                    data: <?php echo json_encode($interviewData); ?>,
                    backgroundColor: '#10b981',
                    borderRadius: 6,
                    barPercentage: 0.6
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    backgroundColor: 'rgba(0, 0, 0, 0.8)',
                    titleColor: '#fff',
                    bodyColor: '#fff',
                    padding: 12,
                    cornerRadius: 8
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: {
                        color: 'rgba(0, 0, 0, 0.05)'
                    },
                    ticks: {
                        stepSize: 1,
                        callback: function(value) {
                            if (Number.isInteger(value)) return value;
                        }
                    }
                },
                x: {
                    grid: {
                        display: false
                    }
                }
            }
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

// Notifications Dropdown
const notificationBtn = document.getElementById('notificationBtn');
const notificationDropdown = document.getElementById('notificationDropdown');

if (notificationBtn && notificationDropdown) {
    notificationBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        notificationDropdown.classList.toggle('hidden');
    });
}

// Profile Menu Dropdown
const profileMenuBtn = document.getElementById('profileMenuBtn');
const profileDropdown = document.getElementById('profileDropdown');

if (profileMenuBtn && profileDropdown) {
    profileMenuBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        profileDropdown.classList.toggle('hidden');
    });
}

// Close dropdowns when clicking outside
document.addEventListener('click', (e) => {
    if (quickActionsBtn && !quickActionsBtn.contains(e.target)) {
        quickActionsDropdown?.classList.add('hidden');
    }
    if (notificationBtn && !notificationBtn.contains(e.target)) {
        notificationDropdown?.classList.add('hidden');
    }
    if (profileMenuBtn && !profileMenuBtn.contains(e.target)) {
        profileDropdown?.classList.add('hidden');
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

// Mark all notifications as read
function markAllNotificationsRead() {
    fetch('/ajax/mark-all-notifications-read.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        }
    })
    .catch(error => console.error('Error:', error));
}

// Keyboard shortcuts
document.addEventListener('keydown', (e) => {
    // Escape to close modals
    if (e.key === 'Escape') {
        quickActionsDropdown?.classList.add('hidden');
        notificationDropdown?.classList.add('hidden');
        profileDropdown?.classList.add('hidden');
        mobileSidebar?.classList.add('hidden');
    }
});

// Auto-hide flash message after 5 seconds
setTimeout(() => {
    const flashMessage = document.querySelector('.fixed.top-20.right-4');
    if (flashMessage) {
        flashMessage.style.transition = 'opacity 0.5s';
        flashMessage.style.opacity = '0';
        setTimeout(() => flashMessage?.remove(), 500);
    }
}, 5000);
</script>
</body>
</html>