<?php
// employer/dashboard.php - Employer Dashboard
// Version: 1.0 - Comprehensive employer management dashboard

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

// Require employer login
requireEmployerLogin();

// Get database connection
$pdo = getDB();
$user_id = $_SESSION['user_id'];

// Get employer info
$employerData = fetchOne("
    SELECT e.*, u.email, u.is_verified, u.created_at as account_created
    FROM employers e 
    INNER JOIN users u ON e.user_id = u.id 
    WHERE e.user_id = ?
", [$user_id]);

if (!$employerData) {
    $_SESSION['error'] = 'Employer profile not found';
    header('Location: /logout.php');
    exit();
}

$employer_id = $employerData['id'];

// ===== FETCH COMPREHENSIVE DASHBOARD STATISTICS =====

// 1. Overall Employer Statistics
$overallStats = fetchOne("
    SELECT 
        (SELECT COUNT(*) FROM jobs WHERE employer_id = ?) as total_jobs,
        (SELECT COUNT(*) FROM jobs WHERE employer_id = ? AND status = 'active') as active_jobs,
        (SELECT COUNT(*) FROM jobs WHERE employer_id = ? AND status = 'draft') as draft_jobs,
        (SELECT COUNT(*) FROM jobs WHERE employer_id = ? AND status = 'paused') as paused_jobs,
        (SELECT COUNT(*) FROM jobs WHERE employer_id = ? AND status = 'closed') as closed_jobs,
        (SELECT COUNT(*) FROM jobs WHERE employer_id = ? AND status = 'expired') as expired_jobs,
        (SELECT COUNT(*) FROM jobs WHERE employer_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) as new_jobs_week,
        (SELECT COUNT(*) FROM jobs WHERE employer_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) as new_jobs_month,
        (SELECT SUM(total_views) FROM jobs WHERE employer_id = ?) as total_views,
        (SELECT SUM(total_applications) FROM jobs WHERE employer_id = ?) as total_applications,
        (SELECT COUNT(*) FROM applications a 
         INNER JOIN jobs j ON a.job_id = j.id 
         WHERE j.employer_id = ?) as total_applications_detailed,
        (SELECT COUNT(*) FROM applications a 
         INNER JOIN jobs j ON a.job_id = j.id 
         WHERE j.employer_id = ? AND a.applied_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) as new_applications_week,
        (SELECT COUNT(*) FROM applications a 
         INNER JOIN jobs j ON a.job_id = j.id 
         WHERE j.employer_id = ? AND a.applied_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) as new_applications_month,
        (SELECT COUNT(*) FROM applications a 
         INNER JOIN jobs j ON a.job_id = j.id 
         WHERE j.employer_id = ? AND a.application_status = 'applied') as applied_applications,
        (SELECT COUNT(*) FROM applications a 
         INNER JOIN jobs j ON a.job_id = j.id 
         WHERE j.employer_id = ? AND a.application_status = 'reviewed') as reviewed_applications,
        (SELECT COUNT(*) FROM applications a 
         INNER JOIN jobs j ON a.job_id = j.id 
         WHERE j.employer_id = ? AND a.application_status = 'shortlisted') as shortlisted_applications,
        (SELECT COUNT(*) FROM applications a 
         INNER JOIN jobs j ON a.job_id = j.id 
         WHERE j.employer_id = ? AND a.application_status = 'interview') as interview_applications,
        (SELECT COUNT(*) FROM applications a 
         INNER JOIN jobs j ON a.job_id = j.id 
         WHERE j.employer_id = ? AND a.application_status = 'offered') as offered_applications,
        (SELECT COUNT(*) FROM applications a 
         INNER JOIN jobs j ON a.job_id = j.id 
         WHERE j.employer_id = ? AND a.application_status = 'hired') as hired_applications,
        (SELECT COUNT(*) FROM applications a 
         INNER JOIN jobs j ON a.job_id = j.id 
         WHERE j.employer_id = ? AND a.application_status = 'rejected') as rejected_applications,
        (SELECT COUNT(*) FROM interviews i 
         INNER JOIN applications a ON i.application_id = a.id 
         INNER JOIN jobs j ON a.job_id = j.id 
         WHERE j.employer_id = ? AND i.interview_date > NOW()) as upcoming_interviews,
        (SELECT COUNT(*) FROM interviews i 
         INNER JOIN applications a ON i.application_id = a.id 
         INNER JOIN jobs j ON a.job_id = j.id 
         WHERE j.employer_id = ? AND i.status = 'scheduled') as scheduled_interviews,
        (SELECT COUNT(*) FROM interviews i 
         INNER JOIN applications a ON i.application_id = a.id 
         INNER JOIN jobs j ON a.job_id = j.id 
         WHERE j.employer_id = ? AND i.status = 'completed') as completed_interviews,
        (SELECT COUNT(*) FROM saved_candidates WHERE employer_id = ?) as saved_candidates_count,
        (SELECT COUNT(*) FROM messages m 
         WHERE m.receiver_id = ? AND m.is_read = 0) as unread_messages
", array_fill(0, 28, $employer_id));

// Ensure $overallStats is not null
if (!$overallStats) {
    $overallStats = [
        'total_jobs' => 0,
        'active_jobs' => 0,
        'draft_jobs' => 0,
        'paused_jobs' => 0,
        'closed_jobs' => 0,
        'expired_jobs' => 0,
        'new_jobs_week' => 0,
        'new_jobs_month' => 0,
        'total_views' => 0,
        'total_applications' => 0,
        'total_applications_detailed' => 0,
        'new_applications_week' => 0,
        'new_applications_month' => 0,
        'applied_applications' => 0,
        'reviewed_applications' => 0,
        'shortlisted_applications' => 0,
        'interview_applications' => 0,
        'offered_applications' => 0,
        'hired_applications' => 0,
        'rejected_applications' => 0,
        'upcoming_interviews' => 0,
        'scheduled_interviews' => 0,
        'completed_interviews' => 0,
        'saved_candidates_count' => 0,
        'unread_messages' => 0
    ];
}

// 2. Recent Jobs
$recentJobs = fetchAll("
    SELECT 
        j.id,
        j.title,
        j.slug,
        j.status,
        j.is_featured,
        j.is_urgent,
        j.created_at,
        j.published_at,
        j.application_deadline,
        j.total_vacancies,
        j.total_applications,
        j.total_views,
        j.location_type,
        j.city,
        j.country,
        j.salary_min,
        j.salary_max,
        j.salary_currency,
        jc.name as category_name,
        jt.name as job_type_name,
        (SELECT COUNT(*) FROM applications WHERE job_id = j.id) as application_count
    FROM jobs j
    LEFT JOIN job_categories jc ON j.category_id = jc.id
    LEFT JOIN job_types jt ON j.job_type_id = jt.id
    WHERE j.employer_id = ?
    ORDER BY j.created_at DESC
    LIMIT 10
", [$employer_id]);

// 3. Recent Applications
$recentApplications = fetchAll("
    SELECT 
        a.id,
        a.application_status,
        a.match_score,
        a.applied_at,
        a.expected_salary,
        a.notice_period_days,
        a.is_favorite,
        j.id as job_id,
        j.title as job_title,
        j.slug as job_slug,
        j.location_type,
        j.city as job_city,
        s.id as seeker_id,
        s.first_name,
        s.last_name,
        s.profile_picture as seeker_avatar,
        s.location as seeker_location,
        s.current_job_title,
        s.current_company,
        s.years_experience,
        s.highest_education,
        s.skills,
        COALESCE((SELECT COUNT(*) FROM interviews WHERE application_id = a.id), 0) as interview_count
    FROM applications a
    INNER JOIN jobs j ON a.job_id = j.id
    INNER JOIN seekers s ON a.seeker_id = s.id
    WHERE j.employer_id = ?
    ORDER BY a.applied_at DESC
    LIMIT 10
", [$employer_id]);

// 4. Application Trends (last 30 days)
$applicationTrends = fetchAll("
    SELECT 
        DATE(a.applied_at) as date,
        COUNT(*) as count
    FROM applications a
    INNER JOIN jobs j ON a.job_id = j.id
    WHERE j.employer_id = ? AND a.applied_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY DATE(a.applied_at)
    ORDER BY date ASC
", [$employer_id]);

// Prepare chart data
$chartLabels = [];
$chartData = [];

$dateMap = [];
for ($i = 29; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $chartLabels[] = date('M d', strtotime($date));
    $dateMap[$date] = 0;
}

foreach ($applicationTrends as $trend) {
    if (isset($dateMap[$trend['date']])) {
        $dateMap[$trend['date']] = (int)$trend['count'];
    }
}

foreach ($dateMap as $count) {
    $chartData[] = $count;
}

// 5. Upcoming Interviews
$upcomingInterviews = fetchAll("
    SELECT 
        i.id,
        i.interview_type,
        i.interview_date,
        i.duration_minutes,
        i.meeting_link,
        i.location,
        i.status as interview_status,
        j.id as job_id,
        j.title as job_title,
        s.id as seeker_id,
        CONCAT(s.first_name, ' ', s.last_name) as seeker_name,
        s.profile_picture as seeker_avatar,
        s.email as seeker_email,
        s.phone as seeker_phone
    FROM interviews i
    INNER JOIN applications a ON i.application_id = a.id
    INNER JOIN jobs j ON a.job_id = j.id
    INNER JOIN seekers s ON a.seeker_id = s.id
    WHERE j.employer_id = ? 
        AND i.interview_date > NOW()
        AND i.status IN ('scheduled', 'confirmed')
    ORDER BY i.interview_date ASC
    LIMIT 10
", [$employer_id]);

// 6. Recent Messages
$recentMessages = fetchAll("
    SELECT 
        m.*,
        sender.email as sender_email,
        sender.user_type as sender_type,
        CASE 
            WHEN sender.user_type = 'seeker' THEN CONCAT((SELECT first_name FROM seekers WHERE user_id = sender.id), ' ', (SELECT last_name FROM seekers WHERE user_id = sender.id))
            WHEN sender.user_type = 'employer' THEN (SELECT company_name FROM employers WHERE user_id = sender.id)
            ELSE 'System'
        END as sender_name,
        CASE 
            WHEN sender.user_type = 'seeker' THEN (SELECT profile_picture FROM seekers WHERE user_id = sender.id)
            WHEN sender.user_type = 'employer' THEN (SELECT company_logo FROM employers WHERE user_id = sender.id)
            ELSE NULL
        END as sender_avatar
    FROM messages m
    INNER JOIN users sender ON m.sender_id = sender.id
    WHERE m.receiver_id = ?
    ORDER BY m.created_at DESC
    LIMIT 8
", [$user_id]);

// 7. Profile Completion Stats
$profileCompletion = [
    'company_info' => !empty($employerData['company_name']) && !empty($employerData['industry']),
    'contact_info' => !empty($employerData['company_email']) || !empty($employerData['company_phone']),
    'logo' => !empty($employerData['company_logo']),
    'description' => !empty($employerData['company_description']),
    'address' => !empty($employerData['address']) || (!empty($employerData['city']) && !empty($employerData['country'])),
    'website' => !empty($employerData['company_website']),
    'tax_id' => !empty($employerData['tax_id']),
    'size' => !empty($employerData['company_size'])
];

$completedItems = count(array_filter($profileCompletion));
$totalItems = count($profileCompletion);
$completionPercentage = $totalItems > 0 ? round(($completedItems / $totalItems) * 100) : 0;

// 8. Job Category Distribution for Employer
$categoryStats = fetchAll("
    SELECT 
        jc.name as category_name,
        COUNT(j.id) as job_count,
        SUM(j.total_applications) as total_applications
    FROM jobs j
    INNER JOIN job_categories jc ON j.category_id = jc.id
    WHERE j.employer_id = ?
    GROUP BY jc.id, jc.name
    ORDER BY job_count DESC
    LIMIT 6
", [$employer_id]);

// 9. Recommended Candidates (based on job requirements)
// Get skills from employer's jobs
$jobSkills = fetchAll("
    SELECT DISTINCT js.skill_name
    FROM job_skills js
    INNER JOIN jobs j ON js.job_id = j.id
    WHERE j.employer_id = ?
    LIMIT 10
", [$employer_id]);

$skillList = array_column($jobSkills, 'skill_name');

$recommendedCandidates = [];
if (!empty($skillList)) {
    $skillConditions = [];
    $params = [];
    foreach ($skillList as $index => $skill) {
        $skillConditions[] = "s.skills LIKE ?";
        $params[] = "%$skill%";
    }
    $whereClause = implode(' OR ', $skillConditions);
    
    $recommendedCandidates = fetchAll("
        SELECT 
            s.id,
            s.first_name,
            s.last_name,
            s.profile_picture,
            s.headline,
            s.current_job_title,
            s.current_company,
            s.years_experience,
            s.location,
            s.skills
        FROM seekers s
        WHERE s.is_active = 1 AND ($whereClause)
        AND s.id NOT IN (SELECT seeker_id FROM saved_candidates WHERE employer_id = ?)
        ORDER BY s.years_experience DESC
        LIMIT 5
    ", array_merge($params, [$employer_id]));
}

// Get avatar URL
$avatarUrl = !empty($employerData['company_logo']) 
    ? htmlspecialchars($employerData['company_logo']) 
    : 'https://ui-avatars.com/api/?name=' . urlencode($employerData['company_name']) . '&background=4f46e5&color=fff&size=128&bold=true';

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
    <title>Employer Dashboard - TransNow</title>
    
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
        
        .status-active { @apply bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300; }
        .status-draft { @apply bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300; }
        .status-paused { @apply bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-300; }
        .status-closed { @apply bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300; }
        .status-expired { @apply bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300; }
        .status-applied { @apply bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300; }
        .status-reviewed { @apply bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-300; }
        .status-shortlisted { @apply bg-indigo-100 text-indigo-800 dark:bg-indigo-900 dark:text-indigo-300; }
        .status-interview { @apply bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300; }
        .status-offered { @apply bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300; }
        .status-hired { @apply bg-emerald-100 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-300; }
        .status-rejected { @apply bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300; }
        
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
            
            <!-- Employer Profile Summary -->
            <div class="px-6 py-4 border-t border-b border-gray-200 dark:border-gray-700">
                <div class="flex items-center space-x-4">
                    <div class="relative">
                        <img src="<?php echo $avatarUrl; ?>" 
                             alt="<?php echo htmlspecialchars($employerData['company_name']); ?>" 
                             class="w-16 h-16 rounded-xl object-cover border-2 border-indigo-200 dark:border-indigo-800">
                        <div class="absolute -bottom-1 -right-1 w-5 h-5 bg-green-500 border-2 border-white dark:border-gray-800 rounded-full animate-pulse"></div>
                    </div>
                    <div class="flex-1 min-w-0">
                        <h3 class="font-bold text-lg truncate"><?php echo htmlspecialchars($employerData['company_name']); ?></h3>
                        <p class="text-sm text-gray-600 dark:text-gray-400 truncate">
                            <?php echo htmlspecialchars($employerData['industry'] ?? 'Employer'); ?>
                        </p>
                        <div class="flex items-center mt-1">
                            <?php if ($employerData['is_verified']): ?>
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
                        <div class="text-lg font-bold text-indigo-600"><?php echo $overallStats['new_jobs_week']; ?></div>
                        <div class="text-xs text-gray-500">Jobs This Week</div>
                    </div>
                    <div class="text-center p-2 bg-purple-50 dark:bg-purple-900/20 rounded-lg">
                        <div class="text-lg font-bold text-purple-600"><?php echo $overallStats['new_applications_week']; ?></div>
                        <div class="text-xs text-gray-500">Applications</div>
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
                
                <!-- Company Profile -->
                <a href="profile.php" class="flex items-center space-x-3 px-4 py-3 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition group">
                    <i class="fas fa-building w-5"></i>
                    <span>Company Profile</span>
                    <?php if ($completionPercentage < 100): ?>
                    <span class="ml-auto text-xs bg-yellow-500 text-white px-2 py-1 rounded-full group-hover:bg-yellow-600">!</span>
                    <?php endif; ?>
                </a>
                
                <!-- Post Job -->
                <a href="post-job.php" class="flex items-center space-x-3 px-4 py-3 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                    <i class="fas fa-plus-circle w-5"></i>
                    <span>Post New Job</span>
                </a>
                
                <!-- Manage Jobs -->
                <a href="manage-jobs.php" class="flex items-center space-x-3 px-4 py-3 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition group">
                    <i class="fas fa-briefcase w-5"></i>
                    <span>Manage Jobs</span>
                    <?php if (($overallStats['draft_jobs'] ?? 0) > 0): ?>
                    <span class="ml-auto bg-gray-500 text-white text-xs px-2 py-1 rounded-full group-hover:bg-gray-600"><?php echo $overallStats['draft_jobs']; ?></span>
                    <?php endif; ?>
                </a>
                
                <!-- Applications -->
                <a href="applications.php" class="flex items-center space-x-3 px-4 py-3 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition group">
                    <i class="fas fa-file-alt w-5"></i>
                    <span>Applications</span>
                    <?php if (($overallStats['applied_applications'] ?? 0) > 0): ?>
                    <span class="ml-auto bg-blue-500 text-white text-xs px-2 py-1 rounded-full group-hover:bg-blue-600"><?php echo $overallStats['applied_applications']; ?></span>
                    <?php endif; ?>
                </a>
                
                <!-- Saved Candidates -->
                <a href="candidates.php" class="flex items-center space-x-3 px-4 py-3 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                    <i class="fas fa-user-plus w-5"></i>
                    <span>Saved Candidates</span>
                    <?php if (($overallStats['saved_candidates_count'] ?? 0) > 0): ?>
                    <span class="ml-auto bg-purple-500 text-white text-xs px-2 py-1 rounded-full"><?php echo $overallStats['saved_candidates_count']; ?></span>
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
                            Complete your company profile to attract more candidates! (<?php echo $completionPercentage; ?>% done)
                        <?php elseif (empty($recentJobs)): ?>
                            Post your first job to start receiving applications.
                        <?php else: ?>
                            Review applications quickly to find the best talent!
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
                        Welcome back, <span class="gradient-text"><?php echo htmlspecialchars($employerData['company_name']); ?>!</span>
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
                                    <a href="post-job.php" class="flex items-center space-x-3 px-4 py-3 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                                        <i class="fas fa-plus-circle text-indigo-600 w-5"></i>
                                        <span>Post New Job</span>
                                    </a>
                                    <a href="manage-jobs.php" class="flex items-center space-x-3 px-4 py-3 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                                        <i class="fas fa-briefcase text-purple-600 w-5"></i>
                                        <span>Manage Jobs</span>
                                    </a>
                                    <a href="applications.php" class="flex items-center space-x-3 px-4 py-3 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                                        <i class="fas fa-file-alt text-green-600 w-5"></i>
                                        <span>View Applications</span>
                                    </a>
                                    <a href="profile.php" class="flex items-center space-x-3 px-4 py-3 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                                        <i class="fas fa-building text-blue-600 w-5"></i>
                                        <span>Update Profile</span>
                                    </a>
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
                                        <i class="fas fa-building text-indigo-600 w-5"></i>
                                        <span>Company Profile</span>
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
                            <h2 class="text-3xl font-bold mb-2">Your Hiring Dashboard</h2>
                            <p class="text-white/90 text-lg">
                                <?php if (($overallStats['total_applications_detailed'] ?? 0) > 0): ?>
                                    You've received <span class="font-bold"><?php echo $overallStats['total_applications_detailed']; ?></span> applications. Review them to find your next great hire!
                                <?php else: ?>
                                    Post your first job to start receiving applications from qualified candidates.
                                <?php endif; ?>
                            </p>
                        </div>
                        <a href="post-job.php" class="px-8 py-4 bg-white text-indigo-600 rounded-xl font-bold hover:shadow-2xl transition-all duration-300 hover:scale-105">
                            <i class="fas fa-plus-circle mr-2"></i>
                            Post a Job
                        </a>
                    </div>
                </div>
            </div>

            <!-- Key Metrics Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <!-- Total Jobs -->
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6 hover-card metric-card" data-aos="fade-up" data-aos-delay="0">
                    <div class="flex items-center justify-between mb-3">
                        <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900/20 rounded-xl flex items-center justify-center">
                            <i class="fas fa-briefcase text-blue-600 dark:text-blue-400 text-xl"></i>
                        </div>
                        <span class="text-xs font-semibold text-blue-600 bg-blue-100 dark:bg-blue-900/30 px-3 py-1 rounded-full">
                            +<?php echo $overallStats['new_jobs_week']; ?> this week
                        </span>
                    </div>
                    <h3 class="text-2xl font-bold mb-1"><?php echo number_format($overallStats['total_jobs']); ?></h3>
                    <p class="text-sm text-gray-600 dark:text-gray-400">Total Jobs Posted</p>
                    <div class="mt-4 grid grid-cols-2 gap-2 text-xs">
                        <div>
                            <span class="block font-semibold text-green-600"><?php echo $overallStats['active_jobs']; ?></span>
                            <span class="text-gray-500">Active</span>
                        </div>
                        <div>
                            <span class="block font-semibold text-gray-600"><?php echo $overallStats['draft_jobs']; ?></span>
                            <span class="text-gray-500">Draft</span>
                        </div>
                    </div>
                </div>

                <!-- Applications -->
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6 hover-card metric-card" data-aos="fade-up" data-aos-delay="50">
                    <div class="flex items-center justify-between mb-3">
                        <div class="w-12 h-12 bg-purple-100 dark:bg-purple-900/20 rounded-xl flex items-center justify-center">
                            <i class="fas fa-file-alt text-purple-600 dark:text-purple-400 text-xl"></i>
                        </div>
                    </div>
                    <h3 class="text-2xl font-bold mb-1"><?php echo number_format($overallStats['total_applications_detailed']); ?></h3>
                    <p class="text-sm text-gray-600 dark:text-gray-400">Total Applications</p>
                    <div class="mt-4 grid grid-cols-2 gap-2 text-xs">
                        <div>
                            <span class="block font-semibold text-yellow-600"><?php echo $overallStats['applied_applications']; ?></span>
                            <span class="text-gray-500">New</span>
                        </div>
                        <div>
                            <span class="block font-semibold text-green-600"><?php echo $overallStats['shortlisted_applications']; ?></span>
                            <span class="text-gray-500">Shortlisted</span>
                        </div>
                    </div>
                </div>

                <!-- Interviews -->
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6 hover-card metric-card" data-aos="fade-up" data-aos-delay="100">
                    <div class="flex items-center justify-between mb-3">
                        <div class="w-12 h-12 bg-green-100 dark:bg-green-900/20 rounded-xl flex items-center justify-center">
                            <i class="fas fa-calendar-check text-green-600 dark:text-green-400 text-xl"></i>
                        </div>
                    </div>
                    <h3 class="text-2xl font-bold mb-1"><?php echo number_format($overallStats['upcoming_interviews']); ?></h3>
                    <p class="text-sm text-gray-600 dark:text-gray-400">Upcoming Interviews</p>
                    <div class="mt-4 text-xs text-gray-500">
                        <?php echo $overallStats['completed_interviews']; ?> completed • <?php echo $overallStats['scheduled_interviews']; ?> scheduled
                    </div>
                </div>

                <!-- Views & Engagement -->
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6 hover-card metric-card" data-aos="fade-up" data-aos-delay="150">
                    <div class="flex items-center justify-between mb-3">
                        <div class="w-12 h-12 bg-yellow-100 dark:bg-yellow-900/20 rounded-xl flex items-center justify-center">
                            <i class="fas fa-eye text-yellow-600 dark:text-yellow-400 text-xl"></i>
                        </div>
                    </div>
                    <h3 class="text-2xl font-bold mb-1"><?php echo number_format($overallStats['total_views']); ?></h3>
                    <p class="text-sm text-gray-600 dark:text-gray-400">Total Job Views</p>
                    <div class="mt-4 w-full bg-gray-200 dark:bg-gray-700 rounded-full h-1.5">
                        <?php 
                        $avgViews = $overallStats['total_jobs'] > 0 ? round($overallStats['total_views'] / $overallStats['total_jobs']) : 0;
                        ?>
                        <div class="bg-yellow-500 h-1.5 rounded-full" style="width: <?php echo min(100, $avgViews); ?>%"></div>
                    </div>
                    <p class="text-xs text-gray-500 mt-2">Avg <?php echo number_format($avgViews); ?> views per job</p>
                </div>
            </div>

            <!-- Charts and Recent Activity -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
                <!-- Application Trends Chart -->
                <div class="lg:col-span-2 bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6" data-aos="fade-up">
                    <div class="flex items-center justify-between mb-6">
                        <div>
                            <h2 class="text-xl font-bold">Application Trends</h2>
                            <p class="text-sm text-gray-500 mt-1">Applications over the last 30 days</p>
                        </div>
                    </div>
                    <div class="chart-container" style="height: 250px;">
                        <canvas id="trendChart"></canvas>
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
                        <p class="text-xs text-gray-400 mt-2">Schedule interviews with shortlisted candidates</p>
                    </div>
                    <?php else: ?>
                    <div class="space-y-4">
                        <?php foreach ($upcomingInterviews as $interview): ?>
                        <div class="p-4 border border-gray-200 dark:border-gray-700 rounded-xl hover:shadow-lg transition interview-card <?php echo $interview['interview_type'] ?? 'video'; ?>">
                            <div class="flex items-start justify-between">
                                <div>
                                    <h3 class="font-semibold"><?php echo htmlspecialchars($interview['job_title'] ?? 'Interview'); ?></h3>
                                    <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">
                                        with <?php echo htmlspecialchars($interview['seeker_name']); ?>
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

            <!-- Recent Jobs -->
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6 mb-8" data-aos="fade-up">
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-xl font-bold">Recent Job Postings</h2>
                    <a href="manage-jobs.php" class="text-indigo-600 dark:text-indigo-400 hover:underline text-sm flex items-center">
                        View All <i class="fas fa-arrow-right ml-2"></i>
                    </a>
                </div>
                
                <?php if (empty($recentJobs)): ?>
                <div class="text-center py-12">
                    <i class="fas fa-briefcase text-4xl text-gray-400 mb-3"></i>
                    <p class="text-gray-500">No jobs posted yet</p>
                    <a href="post-job.php" class="inline-block mt-4 px-6 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition">
                        Post Your First Job
                    </a>
                </div>
                <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead>
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Job Title</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Category</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Applications</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Views</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Posted</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            <?php foreach ($recentJobs as $job): ?>
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50 transition">
                                <td class="px-4 py-3">
                                    <a href="job-details.php?id=<?php echo $job['id']; ?>" class="font-medium hover:text-indigo-600 transition">
                                        <?php echo htmlspecialchars($job['title']); ?>
                                    </a>
                                    <div class="text-xs text-gray-500 mt-1">
                                        <?php echo htmlspecialchars($job['job_type_name'] ?? 'Full Time'); ?>
                                        <?php if (!empty($job['city'])): ?>
                                        • <?php echo htmlspecialchars($job['city']); ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-sm">
                                    <?php echo htmlspecialchars($job['category_name'] ?? 'Uncategorized'); ?>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="status-badge status-<?php echo $job['status']; ?>">
                                        <?php echo ucfirst($job['status']); ?>
                                    </span>
                                    <?php if ($job['is_featured']): ?>
                                    <span class="ml-1 text-xs bg-yellow-500 text-white px-2 py-1 rounded-full">Featured</span>
                                    <?php endif; ?>
                                    <?php if ($job['is_urgent']): ?>
                                    <span class="ml-1 text-xs bg-red-500 text-white px-2 py-1 rounded-full">Urgent</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3 font-semibold">
                                    <?php echo $job['application_count']; ?>
                                </td>
                                <td class="px-4 py-3">
                                    <?php echo number_format($job['total_views']); ?>
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-500">
                                    <?php echo timeAgo($job['created_at']); ?>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex space-x-2">
                                        <a href="edit-job.php?id=<?php echo $job['id']; ?>" class="text-blue-600 hover:text-blue-800" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="applications.php?job_id=<?php echo $job['id']; ?>" class="text-green-600 hover:text-green-800" title="Applications">
                                            <i class="fas fa-file-alt"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <!-- Recent Applications & Recommended Candidates -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                <!-- Recent Applications -->
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6" data-aos="fade-up">
                    <div class="flex items-center justify-between mb-6">
                        <h2 class="text-xl font-bold">Recent Applications</h2>
                        <a href="applications.php" class="text-indigo-600 dark:text-indigo-400 hover:underline text-sm flex items-center">
                            View All <i class="fas fa-arrow-right ml-2"></i>
                        </a>
                    </div>
                    
                    <?php if (empty($recentApplications)): ?>
                    <div class="text-center py-8">
                        <i class="fas fa-file-alt text-4xl text-gray-400 mb-3"></i>
                        <p class="text-gray-500">No applications received yet</p>
                        <p class="text-xs text-gray-400 mt-2">Post jobs to start receiving applications</p>
                    </div>
                    <?php else: ?>
                    <div class="space-y-4 max-h-96 overflow-y-auto">
                        <?php foreach ($recentApplications as $app): ?>
                        <div class="flex items-start space-x-3 p-3 bg-gray-50 dark:bg-gray-700/50 rounded-xl hover:shadow-md transition">
                            <img src="<?php 
                                echo !empty($app['seeker_avatar']) ? htmlspecialchars($app['seeker_avatar']) : 'https://ui-avatars.com/api/?name=' . urlencode($app['first_name'] . '+' . $app['last_name']) . '&background=4f46e5&color=fff';
                            ?>" alt="" class="w-10 h-10 rounded-lg object-cover">
                            <div class="flex-1 min-w-0">
                                <div class="flex items-start justify-between">
                                    <div>
                                        <p class="font-semibold text-sm"><?php echo htmlspecialchars($app['first_name'] . ' ' . $app['last_name']); ?></p>
                                        <p class="text-xs text-gray-500 truncate">Applied for: 
                                            <a href="job-details.php?id=<?php echo $app['job_id']; ?>" class="hover:text-indigo-600">
                                                <?php echo htmlspecialchars($app['job_title']); ?>
                                            </a>
                                        </p>
                                    </div>
                                    <?php if (!empty($app['match_score'])): ?>
                                    <span class="text-xs <?php echo $app['match_score'] >= 80 ? 'bg-green-100 text-green-600' : ($app['match_score'] >= 60 ? 'bg-yellow-100 text-yellow-600' : 'bg-gray-100 text-gray-600'); ?> px-2 py-1 rounded-full">
                                        <?php echo $app['match_score']; ?>% match
                                    </span>
                                    <?php endif; ?>
                                </div>
                                <div class="flex items-center justify-between mt-2">
                                    <span class="status-badge status-<?php echo $app['application_status']; ?> text-xs">
                                        <i class="fas <?php echo getStatusIcon($app['application_status']); ?> mr-1"></i>
                                        <?php echo ucfirst($app['application_status']); ?>
                                    </span>
                                    <span class="text-xs text-gray-400"><?php echo timeAgo($app['applied_at']); ?></span>
                                </div>
                                <div class="mt-2 flex items-center space-x-3 text-xs">
                                    <?php if (!empty($app['current_job_title'])): ?>
                                    <span class="text-gray-500">
                                        <i class="fas fa-briefcase mr-1"></i> <?php echo htmlspecialchars($app['current_job_title']); ?>
                                    </span>
                                    <?php endif; ?>
                                    <?php if (!empty($app['years_experience'])): ?>
                                    <span class="text-gray-500">
                                        <i class="fas fa-calendar-alt mr-1"></i> <?php echo $app['years_experience']; ?> years
                                    </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="flex space-x-2">
                                <a href="application-details.php?id=<?php echo $app['id']; ?>" class="text-indigo-600 hover:text-indigo-800" title="View Application">
                                    <i class="fas fa-eye"></i>
                                </a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Recommended Candidates -->
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6" data-aos="fade-up">
                    <h2 class="text-xl font-bold mb-4 flex items-center">
                        <i class="fas fa-users text-blue-500 mr-2"></i>
                        Recommended Candidates
                    </h2>
                    
                    <?php if (empty($recommendedCandidates)): ?>
                    <div class="text-center py-8">
                        <i class="fas fa-user-plus text-4xl text-gray-400 mb-3"></i>
                        <p class="text-gray-500">No candidate recommendations yet</p>
                        <p class="text-xs text-gray-400 mt-2">Post jobs to get personalized candidate matches</p>
                    </div>
                    <?php else: ?>
                    <div class="space-y-4">
                        <?php foreach ($recommendedCandidates as $candidate): ?>
                        <div class="flex items-start space-x-3 p-3 bg-gray-50 dark:bg-gray-700/50 rounded-xl hover:shadow-md transition">
                            <img src="<?php 
                                echo !empty($candidate['profile_picture']) ? htmlspecialchars($candidate['profile_picture']) : 'https://ui-avatars.com/api/?name=' . urlencode($candidate['first_name'] . '+' . $candidate['last_name']) . '&background=4f46e5&color=fff';
                            ?>" alt="" class="w-10 h-10 rounded-lg object-cover">
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center justify-between">
                                    <p class="font-semibold text-sm"><?php echo htmlspecialchars($candidate['first_name'] . ' ' . $candidate['last_name']); ?></p>
                                    <span class="text-xs text-gray-500"><?php echo $candidate['years_experience']; ?> years exp</span>
                                </div>
                                <?php if (!empty($candidate['headline'])): ?>
                                <p class="text-xs text-gray-600 dark:text-gray-400"><?php echo htmlspecialchars($candidate['headline']); ?></p>
                                <?php endif; ?>
                                <div class="mt-2 flex items-center justify-between">
                                    <div class="text-xs text-gray-500">
                                        <?php if (!empty($candidate['location'])): ?>
                                        <i class="fas fa-map-marker-alt mr-1"></i> <?php echo htmlspecialchars($candidate['location']); ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="flex space-x-2">
                                        <a href="candidate-profile.php?id=<?php echo $candidate['id']; ?>" class="text-indigo-600 hover:text-indigo-800 text-xs">
                                            View Profile
                                        </a>
                                        <button onclick="saveCandidate(<?php echo $candidate['id']; ?>)" class="text-green-600 hover:text-green-800 text-xs">
                                            <i class="fas fa-save"></i> Save
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Recent Messages & Category Stats -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
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

                <!-- Job Categories Stats -->
                <?php if (!empty($categoryStats)): ?>
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6" data-aos="fade-up">
                    <h2 class="text-xl font-bold mb-4">Jobs by Category</h2>
                    <div class="space-y-4">
                        <?php foreach ($categoryStats as $cat): ?>
                        <div>
                            <div class="flex justify-between text-sm mb-1">
                                <span class="font-medium"><?php echo htmlspecialchars($cat['category_name']); ?></span>
                                <span class="text-gray-500"><?php echo $cat['job_count']; ?> jobs</span>
                            </div>
                            <div class="w-full h-2 bg-gray-200 dark:bg-gray-700 rounded-full overflow-hidden">
                                <?php $maxJobs = !empty($categoryStats) ? max(array_column($categoryStats, 'job_count')) : 1; ?>
                                <div class="progress-bar h-full rounded-full" style="width: <?php echo ($cat['job_count'] / max($maxJobs, 1)) * 100; ?>%"></div>
                            </div>
                            <div class="text-xs text-gray-500 mt-1">
                                <?php echo $cat['total_applications']; ?> applications
                            </div>
                        </div>
                        <?php endforeach; ?>
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
                    <img src="<?php echo $avatarUrl; ?>" alt="" class="w-12 h-12 rounded-lg object-cover">
                    <div class="flex-1 min-w-0">
                        <p class="font-semibold truncate"><?php echo htmlspecialchars($employerData['company_name']); ?></p>
                        <p class="text-sm text-gray-500 truncate"><?php echo htmlspecialchars($employerData['industry'] ?? 'Employer'); ?></p>
                    </div>
                </div>
                
                <nav class="space-y-1">
                    <a href="dashboard.php" class="block px-4 py-2 bg-indigo-50 dark:bg-indigo-900/20 text-indigo-600 rounded-lg">Dashboard</a>
                    <a href="profile.php" class="block px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">Company Profile</a>
                    <a href="post-job.php" class="block px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">Post New Job</a>
                    <a href="manage-jobs.php" class="block px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">Manage Jobs</a>
                    <a href="applications.php" class="block px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">Applications</a>
                    <a href="candidates.php" class="block px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">Saved Candidates</a>
                    <a href="interviews.php" class="block px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">Interviews</a>
                    <a href="/messages.php" class="block px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">Messages</a>
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

// Application Trends Chart
const ctx = document.getElementById('trendChart')?.getContext('2d');

if (ctx) {
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode($chartLabels); ?>,
            datasets: [
                {
                    label: 'Applications',
                    data: <?php echo json_encode($chartData); ?>,
                    borderColor: '#4f46e5',
                    backgroundColor: 'rgba(79, 70, 229, 0.1)',
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
                    },
                    ticks: {
                        maxRotation: 45,
                        minRotation: 45,
                        maxTicksLimit: 10
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

// Save candidate function
function saveCandidate(candidateId) {
    fetch('/ajax/save-candidate.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ candidate_id: candidateId })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            Swal.fire({
                icon: 'success',
                title: 'Saved!',
                text: 'Candidate saved to your list',
                timer: 2000,
                showConfirmButton: false
            });
        } else {
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: data.message || 'Could not save candidate'
            });
        }
    })
    .catch(error => console.error('Error:', error));
}

// Keyboard shortcuts
document.addEventListener('keydown', (e) => {
    // Escape to close modals
    if (e.key === 'Escape') {
        quickActionsDropdown?.classList.add('hidden');
        profileDropdown?.classList.add('hidden');
        mobileSidebar?.classList.add('hidden');
    }
    
    // Ctrl/Cmd + N for new job
    if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
        e.preventDefault();
        window.location.href = 'post-job.php';
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