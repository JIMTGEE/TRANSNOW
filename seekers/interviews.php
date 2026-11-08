<?php
// seekers/interviews.php - Enhanced Seeker Interviews Management
// Version: 1.0 - Complete interview tracking and management system

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

// Check if user is logged in and is a seeker
if (!isLoggedIn() || getUserType() !== 'seeker') {
    $_SESSION['error'] = 'Please login as a job seeker to access this page';
    header('Location: /login.php');
    exit();
}

// Get database connection (PDO)
$pdo = getDB();
$user_id = getCurrentUserId();
$seeker_id = getCurrentSeekerId($pdo);

// If seeker_id not found in session, try to fetch it
if (!$seeker_id) {
    // Fetch seeker ID from database using PDO
    $stmt = $pdo->prepare("SELECT id FROM seekers WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $seeker_id = $stmt->fetchColumn();
    
    if ($seeker_id) {
        $_SESSION['seeker_id'] = $seeker_id;
    } else {
        // Redirect to profile completion if no seeker record
        $_SESSION['error'] = 'Please complete your profile first';
        header('Location: profile.php?edit=1');
        exit();
    }
}

// ===== FETCH INTERVIEW DATA WITH ALL RELATED INFORMATION =====

// Helper function for safe fetching
function safeFetchAllInterviews($query, $params = [], $default = []) {
    try {
        $result = fetchAll($query, $params);
        return $result !== false ? $result : $default;
    } catch (Exception $e) {
        error_log("Interviews fetch error: " . $e->getMessage());
        return $default;
    }
}

function safeFetchOneInterview($query, $params = [], $default = null) {
    try {
        $result = fetchOne($query, $params);
        return $result !== null ? $result : $default;
    } catch (Exception $e) {
        error_log("Interviews fetch one error: " . $e->getMessage());
        return $default;
    }
}

// 1. UPCOMING INTERVIEWS (Scheduled/Confirmed)
$upcomingInterviews = safeFetchAllInterviews("
    SELECT 
        i.id,
        i.interview_type,
        i.interview_date,
        i.duration_minutes,
        i.timezone,
        i.meeting_link,
        i.location,
        i.interviewer_name,
        i.interviewer_email,
        i.notes,
        i.status as interview_status,
        i.seeker_confirmed,
        i.employer_confirmed,
        i.created_at as interview_scheduled_at,
        
        a.id as application_id,
        a.application_status,
        a.match_score,
        a.applied_at,
        
        j.id as job_id,
        j.title as job_title,
        j.slug as job_slug,
        j.description as job_description,
        j.location_type,
        j.city,
        j.country,
        j.salary_min,
        j.salary_max,
        j.salary_currency,
        
        e.id as employer_id,
        e.company_name,
        e.company_logo,
        e.company_website,
        e.industry,
        e.company_size,
        e.city as company_city,
        e.country as company_country,
        e.is_verified as employer_verified,
        
        jt.name as job_type,
        jc.name as category_name,
        
        TIMESTAMPDIFF(HOUR, NOW(), i.interview_date) as hours_until_interview,
        CASE 
            WHEN i.interview_date < NOW() THEN 'past'
            WHEN i.interview_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 24 HOUR) THEN 'today'
            WHEN i.interview_date BETWEEN DATE_ADD(NOW(), INTERVAL 24 HOUR) AND DATE_ADD(NOW(), INTERVAL 72 HOUR) THEN 'soon'
            ELSE 'upcoming'
        END as time_category
    FROM interviews i
    INNER JOIN applications a ON i.application_id = a.id
    INNER JOIN jobs j ON a.job_id = j.id
    INNER JOIN employers e ON j.employer_id = e.id
    LEFT JOIN job_types jt ON j.job_type_id = jt.id
    LEFT JOIN job_categories jc ON j.category_id = jc.id
    WHERE a.seeker_id = ?
    AND i.status IN ('scheduled', 'confirmed')
    AND i.interview_date >= DATE_SUB(NOW(), INTERVAL 1 HOUR)  -- Include interviews that just started
    ORDER BY 
        CASE 
            WHEN i.interview_date < NOW() THEN 4  -- Past (but still show if within last hour)
            WHEN i.interview_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 24 HOUR) THEN 1
            WHEN i.interview_date BETWEEN DATE_ADD(NOW(), INTERVAL 24 HOUR) AND DATE_ADD(NOW(), INTERVAL 72 HOUR) THEN 2
            ELSE 3
        END,
        i.interview_date ASC
", [$seeker_id]);

// 2. PAST INTERVIEWS (Completed/Cancelled)
$pastInterviews = safeFetchAllInterviews("
    SELECT 
        i.id,
        i.interview_type,
        i.interview_date,
        i.duration_minutes,
        i.timezone,
        i.meeting_link,
        i.location,
        i.interviewer_name,
        i.notes,
        i.status as interview_status,
        i.feedback_notes,
        i.rating,
        i.seeker_confirmed,
        i.employer_confirmed,
        
        a.id as application_id,
        a.application_status,
        
        j.id as job_id,
        j.title as job_title,
        j.slug as job_slug,
        
        e.id as employer_id,
        e.company_name,
        e.company_logo,
        
        jt.name as job_type,
        
        CASE 
            WHEN i.status = 'completed' THEN 'completed'
            WHEN i.status = 'cancelled' THEN 'cancelled'
            WHEN i.status = 'rescheduled' THEN 'rescheduled'
            ELSE 'past'
        END as past_status,
        
        DATEDIFF(NOW(), i.interview_date) as days_ago
    FROM interviews i
    INNER JOIN applications a ON i.application_id = a.id
    INNER JOIN jobs j ON a.job_id = j.id
    INNER JOIN employers e ON j.employer_id = e.id
    LEFT JOIN job_types jt ON j.job_type_id = jt.id
    WHERE a.seeker_id = ?
    AND i.status IN ('completed', 'cancelled', 'rescheduled', 'no_show')
    ORDER BY i.interview_date DESC
    LIMIT 20
", [$seeker_id]);

// 3. INTERVIEW STATISTICS
$interviewStats = safeFetchOneInterview("
    SELECT 
        COUNT(*) as total_interviews,
        SUM(CASE WHEN i.status IN ('scheduled', 'confirmed') AND i.interview_date > NOW() THEN 1 ELSE 0 END) as upcoming,
        SUM(CASE WHEN i.status IN ('scheduled', 'confirmed') AND i.interview_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 24 HOUR) THEN 1 ELSE 0 END) as today,
        SUM(CASE WHEN i.status = 'completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN i.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
        SUM(CASE WHEN i.status = 'rescheduled' THEN 1 ELSE 0 END) as rescheduled,
        SUM(CASE WHEN i.rating >= 4 THEN 1 ELSE 0 END) as high_rated,
        AVG(CASE WHEN i.rating > 0 THEN i.rating ELSE NULL END) as avg_rating,
        MAX(i.interview_date) as last_interview_date,
        MIN(CASE WHEN i.interview_date > NOW() THEN i.interview_date ELSE NULL END) as next_interview_date
    FROM interviews i
    INNER JOIN applications a ON i.application_id = a.id
    WHERE a.seeker_id = ?
", [$seeker_id]);

// 4. APPLICATIONS WITH INTERVIEWS (for filtering)
$applicationsWithInterviews = safeFetchAllInterviews("
    SELECT DISTINCT
        a.id as application_id,
        j.id as job_id,
        j.title as job_title,
        e.company_name,
        COUNT(i.id) as interview_count,
        MAX(i.interview_date) as latest_interview
    FROM applications a
    INNER JOIN jobs j ON a.job_id = j.id
    INNER JOIN employers e ON j.employer_id = e.id
    INNER JOIN interviews i ON i.application_id = a.id
    WHERE a.seeker_id = ?
    GROUP BY a.id
    ORDER BY latest_interview DESC
", [$seeker_id]);

// 5. UPCOMING INTERVIEW REMINDERS (for the next 7 days)
$interviewReminders = safeFetchAllInterviews("
    SELECT 
        i.id,
        i.interview_date,
        i.interview_type,
        i.meeting_link,
        i.interviewer_name,
        j.title as job_title,
        e.company_name,
        e.company_logo,
        TIMESTAMPDIFF(HOUR, NOW(), i.interview_date) as hours_until,
        TIMESTAMPDIFF(DAY, NOW(), i.interview_date) as days_until
    FROM interviews i
    INNER JOIN applications a ON i.application_id = a.id
    INNER JOIN jobs j ON a.job_id = j.id
    INNER JOIN employers e ON j.employer_id = e.id
    WHERE a.seeker_id = ?
    AND i.status IN ('scheduled', 'confirmed')
    AND i.interview_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)
    ORDER BY i.interview_date ASC
", [$seeker_id]);

// 6. INTERVIEW TYPES BREAKDOWN
$interviewTypeBreakdown = safeFetchAllInterviews("
    SELECT 
        i.interview_type,
        COUNT(*) as count,
        SUM(CASE WHEN i.interview_date > NOW() THEN 1 ELSE 0 END) as upcoming_count
    FROM interviews i
    INNER JOIN applications a ON i.application_id = a.id
    WHERE a.seeker_id = ?
    GROUP BY i.interview_type
", [$seeker_id]);

// 7. MONTHLY INTERVIEW TRENDS (for chart)
$monthlyTrends = safeFetchAllInterviews("
    SELECT 
        DATE_FORMAT(i.interview_date, '%Y-%m') as month,
        COUNT(*) as total,
        SUM(CASE WHEN i.status = 'completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN i.status IN ('scheduled', 'confirmed') AND i.interview_date > NOW() THEN 1 ELSE 0 END) as scheduled
    FROM interviews i
    INNER JOIN applications a ON i.application_id = a.id
    WHERE a.seeker_id = ?
    AND i.interview_date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(i.interview_date, '%Y-%m')
    ORDER BY month DESC
    LIMIT 6
", [$seeker_id]);

// Prepare chart data
$chartMonths = [];
$chartTotals = [];
$chartCompleted = [];
foreach (array_reverse($monthlyTrends) as $trend) {
    $chartMonths[] = date('M Y', strtotime($trend['month'] . '-01'));
    $chartTotals[] = (int)$trend['total'];
    $chartCompleted[] = (int)$trend['completed'];
}

// 8. FEEDBACK PENDING (interviews that need feedback)
$feedbackPending = safeFetchAllInterviews("
    SELECT 
        i.id,
        i.interview_date,
        j.title as job_title,
        e.company_name,
        e.company_logo
    FROM interviews i
    INNER JOIN applications a ON i.application_id = a.id
    INNER JOIN jobs j ON a.job_id = j.id
    INNER JOIN employers e ON j.employer_id = e.id
    WHERE a.seeker_id = ?
    AND i.status = 'completed'
    AND (i.rating IS NULL OR i.rating = 0)
    AND i.interview_date <= NOW()
    ORDER BY i.interview_date DESC
    LIMIT 5
", [$seeker_id]);

// Get seeker profile for display
$seekerProfile = safeFetchOneInterview("
    SELECT first_name, last_name, profile_picture 
    FROM seekers 
    WHERE id = ?
", [$seeker_id]);

// Avatar URL
$avatarUrl = $seekerProfile['profile_picture'] ?? 'https://ui-avatars.com/api/?name=' . urlencode(($seekerProfile['first_name'] ?? 'User') . '+' . ($seekerProfile['last_name'] ?? '')) . '&background=4f46e5&color=fff&size=128&bold=true&length=2';

// Get current filter from URL
$filter = $_GET['filter'] ?? 'upcoming';
$search = $_GET['search'] ?? '';
?>

<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Interviews - <?php echo htmlspecialchars($seekerProfile['first_name'] ?? 'Seeker'); ?> | TransNow</title>
    
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Ccircle cx='50' cy='50' r='45' fill='%234f46e5'/%3E%3Cpath d='M30 40 L70 40 L65 70 L35 70 Z' fill='white'/%3E%3Ccircle cx='50' cy='30' r='8' fill='white'/%3E%3C/svg%3E">
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- FullCalendar -->
    <link href='https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css' rel='stylesheet' />
    <script src='https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js'></script>
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    
    <!-- AOS Animation -->
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    
    <style>
        * {
            font-family: 'Inter', sans-serif;
        }
        
        /* Custom Animations */
        @keyframes float {
            0%, 100% { transform: translateY(0) rotate(0deg); }
            25% { transform: translateY(-5px) rotate(1deg); }
            75% { transform: translateY(5px) rotate(-1deg); }
        }
        
        @keyframes pulse-glow {
            0%, 100% { box-shadow: 0 0 0 0 rgba(79, 70, 229, 0.5); }
            50% { box-shadow: 0 0 15px 5px rgba(79, 70, 229, 0.3); }
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
            transform: translateY(-5px) scale(1.02);
            box-shadow: 0 20px 40px -10px rgba(79, 70, 229, 0.3);
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
        
        /* Interview Status Badges */
        .badge-scheduled { @apply bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300; }
        .badge-confirmed { @apply bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300; }
        .badge-completed { @apply bg-emerald-100 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-300; }
        .badge-cancelled { @apply bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300; }
        .badge-rescheduled { @apply bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300; }
        .badge-no_show { @apply bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300; }
        
        /* Interview Type Icons */
        .type-phone { @apply bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-300; }
        .type-video { @apply bg-indigo-100 text-indigo-800 dark:bg-indigo-900 dark:text-indigo-300; }
        .type-in_person { @apply bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-300; }
        
        /* Countdown Timer */
        .countdown-timer {
            font-feature-settings: "tnum";
            font-variant-numeric: tabular-nums;
        }
        
        /* Calendar Customization */
        .fc-event {
            cursor: pointer;
            border-radius: 8px;
            border: none;
            padding: 2px 4px;
        }
        
        .fc-event-title {
            font-weight: 500;
            font-size: 0.85rem;
        }
        
        .fc-day-today {
            background-color: rgba(79, 70, 229, 0.05) !important;
        }
        
        /* Meeting Link Button */
        .meeting-link-btn {
            background: linear-gradient(135deg, #4f46e5, #7c3aed);
            transition: all 0.3s ease;
        }
        
        .meeting-link-btn:hover {
            transform: scale(1.05);
            box-shadow: 0 10px 25px -5px rgba(79, 70, 229, 0.5);
        }
        
        /* Timeline */
        .timeline-dot {
            width: 16px;
            height: 16px;
            border-radius: 50%;
            border: 3px solid white;
            box-shadow: 0 0 0 2px #4f46e5;
        }
        
        .timeline-line {
            position: absolute;
            left: 7px;
            top: 24px;
            width: 2px;
            height: calc(100% - 24px);
            background: linear-gradient(to bottom, #4f46e5, #7c3aed);
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .fc-toolbar-title {
                font-size: 1.2rem !important;
            }
            .fc-button {
                padding: 0.2rem 0.4rem !important;
                font-size: 0.8rem !important;
            }
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
    <!-- Sidebar (consistent with dashboard) -->
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
            
            <!-- User Profile Summary -->
            <div class="px-6 py-4 border-t border-b border-gray-200 dark:border-gray-700">
                <div class="flex items-center space-x-4">
                    <div class="relative">
                        <img src="<?php echo htmlspecialchars($avatarUrl); ?>" 
                             alt="Profile" 
                             class="w-16 h-16 rounded-xl object-cover border-2 border-indigo-200 dark:border-indigo-800">
                        <div class="absolute -bottom-1 -right-1 w-5 h-5 bg-green-500 border-2 border-white dark:border-gray-800 rounded-full animate-pulse"></div>
                    </div>
                    <div class="flex-1 min-w-0">
                        <h3 class="font-bold text-lg truncate">
                            <?php echo htmlspecialchars(($seekerProfile['first_name'] ?? 'User') . ' ' . ($seekerProfile['last_name'] ?? '')); ?>
                        </h3>
                        <p class="text-sm text-gray-600 dark:text-gray-400 truncate">
                            Job Seeker
                        </p>
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
                    <i class="fas fa-search w-5"></i>
                    <span>Find Jobs</span>
                </a>
                <a href="applications.php" class="flex items-center space-x-3 px-4 py-3 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                    <i class="fas fa-paper-plane w-5"></i>
                    <span>Applications</span>
                </a>
                <a href="saved-jobs.php" class="flex items-center space-x-3 px-4 py-3 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                    <i class="fas fa-bookmark w-5"></i>
                    <span>Saved Jobs</span>
                </a>
                <a href="interviews.php" class="flex items-center space-x-3 px-4 py-3 bg-indigo-50 dark:bg-indigo-900/20 text-indigo-600 dark:text-indigo-400 rounded-xl font-medium">
                    <i class="fas fa-calendar-alt w-5"></i>
                    <span>Interviews</span>
                    <?php if (($interviewStats['today'] ?? 0) > 0): ?>
                    <span class="ml-auto bg-red-500 text-white text-xs px-2 py-1 rounded-full animate-pulse"><?php echo $interviewStats['today']; ?></span>
                    <?php endif; ?>
                </a>
                <a href="profile.php" class="flex items-center space-x-3 px-4 py-3 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                    <i class="fas fa-user-circle w-5"></i>
                    <span>My Profile</span>
                </a>
                <a href="settings.php" class="flex items-center space-x-3 px-4 py-3 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                    <i class="fas fa-cog w-5"></i>
                    <span>Settings</span>
                </a>
                
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
                    <p class="text-sm opacity-90 mb-2">Interview tips & preparation</p>
                    <a href="/blog/interview-tips.php" class="text-xs font-semibold flex items-center hover:underline">
                        <span>Learn more</span>
                        <i class="fas fa-arrow-right ml-2"></i>
                    </a>
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
                    <div class="flex items-center space-x-4">
                        <h1 class="text-2xl font-bold">
                            <span class="gradient-text">My Interviews</span>
                        </h1>
                        <?php if (($interviewStats['upcoming'] ?? 0) > 0): ?>
                        <span class="bg-indigo-100 dark:bg-indigo-900 text-indigo-600 dark:text-indigo-400 px-4 py-2 rounded-xl text-sm font-semibold">
                            <?php echo $interviewStats['upcoming']; ?> Upcoming
                        </span>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Right Actions -->
                    <div class="flex items-center space-x-4">
                        <!-- Search -->
                        <div class="relative hidden md:block">
                            <input type="text" 
                                   id="searchInterviews" 
                                   placeholder="Search interviews..." 
                                   value="<?php echo htmlspecialchars($search); ?>"
                                   class="w-64 px-4 py-2 pl-10 bg-gray-100 dark:bg-gray-700 rounded-xl border-0 focus:ring-2 focus:ring-indigo-500 text-sm">
                            <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 text-sm"></i>
                        </div>
                        
                        <!-- Filter Dropdown -->
                        <div class="relative">
                            <button id="filterBtn" class="px-4 py-2 bg-gray-100 dark:bg-gray-700 rounded-xl hover:bg-gray-200 dark:hover:bg-gray-600 transition flex items-center space-x-2">
                                <i class="fas fa-filter"></i>
                                <span class="hidden sm:inline">Filter</span>
                                <i class="fas fa-chevron-down text-xs"></i>
                            </button>
                            
                            <div id="filterDropdown" class="absolute right-0 mt-2 w-48 bg-white dark:bg-gray-800 rounded-2xl shadow-2xl border border-gray-200 dark:border-gray-700 hidden z-50">
                                <div class="p-2">
                                    <a href="?filter=upcoming" class="flex items-center space-x-2 px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition <?php echo $filter === 'upcoming' ? 'bg-indigo-50 dark:bg-indigo-900/20 text-indigo-600' : ''; ?>">
                                        <i class="fas fa-calendar-check w-5"></i>
                                        <span>Upcoming</span>
                                    </a>
                                    <a href="?filter=today" class="flex items-center space-x-2 px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition <?php echo $filter === 'today' ? 'bg-indigo-50 dark:bg-indigo-900/20 text-indigo-600' : ''; ?>">
                                        <i class="fas fa-sun w-5"></i>
                                        <span>Today</span>
                                        <?php if (($interviewStats['today'] ?? 0) > 0): ?>
                                        <span class="ml-auto bg-red-500 text-white text-xs px-2 py-1 rounded-full"><?php echo $interviewStats['today']; ?></span>
                                        <?php endif; ?>
                                    </a>
                                    <a href="?filter=past" class="flex items-center space-x-2 px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition <?php echo $filter === 'past' ? 'bg-indigo-50 dark:bg-indigo-900/20 text-indigo-600' : ''; ?>">
                                        <i class="fas fa-history w-5"></i>
                                        <span>Past</span>
                                    </a>
                                    <a href="?filter=feedback" class="flex items-center space-x-2 px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition <?php echo $filter === 'feedback' ? 'bg-indigo-50 dark:bg-indigo-900/20 text-indigo-600' : ''; ?>">
                                        <i class="fas fa-star w-5"></i>
                                        <span>Needs Feedback</span>
                                        <?php if (count($feedbackPending) > 0): ?>
                                        <span class="ml-auto bg-yellow-500 text-white text-xs px-2 py-1 rounded-full"><?php echo count($feedbackPending); ?></span>
                                        <?php endif; ?>
                                    </a>
                                    <div class="border-t dark:border-gray-700 my-2"></div>
                                    <a href="?filter=all" class="flex items-center space-x-2 px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition <?php echo $filter === 'all' ? 'bg-indigo-50 dark:bg-indigo-900/20 text-indigo-600' : ''; ?>">
                                        <i class="fas fa-list w-5"></i>
                                        <span>All Interviews</span>
                                    </a>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Calendar View Toggle -->
                        <button id="toggleCalendar" class="w-10 h-10 bg-gray-100 dark:bg-gray-700 rounded-xl hover:bg-gray-200 dark:hover:bg-gray-600 transition flex items-center justify-center">
                            <i class="fas fa-calendar-alt"></i>
                        </button>
                    </div>
                </div>
            </div>
        </header>

        <!-- Dashboard Content -->
        <div class="p-6">
            <!-- Stats Cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4 mb-8" data-aos="fade-up">
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6 hover-card">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-500 mb-1">Total Interviews</p>
                            <h3 class="text-3xl font-bold"><?php echo $interviewStats['total_interviews'] ?? 0; ?></h3>
                        </div>
                        <div class="w-12 h-12 bg-indigo-100 dark:bg-indigo-900/20 rounded-xl flex items-center justify-center">
                            <i class="fas fa-calendar-check text-indigo-600 dark:text-indigo-400 text-xl"></i>
                        </div>
                    </div>
                </div>
                
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6 hover-card">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-500 mb-1">Upcoming</p>
                            <h3 class="text-3xl font-bold"><?php echo $interviewStats['upcoming'] ?? 0; ?></h3>
                        </div>
                        <div class="w-12 h-12 bg-green-100 dark:bg-green-900/20 rounded-xl flex items-center justify-center">
                            <i class="fas fa-clock text-green-600 dark:text-green-400 text-xl"></i>
                        </div>
                    </div>
                </div>
                
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6 hover-card">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-500 mb-1">Completed</p>
                            <h3 class="text-3xl font-bold"><?php echo $interviewStats['completed'] ?? 0; ?></h3>
                        </div>
                        <div class="w-12 h-12 bg-emerald-100 dark:bg-emerald-900/20 rounded-xl flex items-center justify-center">
                            <i class="fas fa-check-circle text-emerald-600 dark:text-emerald-400 text-xl"></i>
                        </div>
                    </div>
                </div>
                
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6 hover-card">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-500 mb-1">Avg. Rating</p>
                            <h3 class="text-3xl font-bold"><?php echo number_format($interviewStats['avg_rating'] ?? 0, 1); ?></h3>
                        </div>
                        <div class="w-12 h-12 bg-yellow-100 dark:bg-yellow-900/20 rounded-xl flex items-center justify-center">
                            <i class="fas fa-star text-yellow-600 dark:text-yellow-400 text-xl"></i>
                        </div>
                    </div>
                </div>
                
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6 hover-card">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-500 mb-1">Success Rate</p>
                            <h3 class="text-3xl font-bold">
                                <?php 
                                $total = $interviewStats['completed'] ?? 0;
                                $highRated = $interviewStats['high_rated'] ?? 0;
                                $rate = $total > 0 ? round(($highRated / $total) * 100) : 0;
                                echo $rate . '%';
                                ?>
                            </h3>
                        </div>
                        <div class="w-12 h-12 bg-purple-100 dark:bg-purple-900/20 rounded-xl flex items-center justify-center">
                            <i class="fas fa-chart-line text-purple-600 dark:text-purple-400 text-xl"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Calendar View (Hidden by default) -->
            <div id="calendarContainer" class="mb-8 hidden" data-aos="fade-up">
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6">
                    <div class="flex items-center justify-between mb-6">
                        <h2 class="text-xl font-bold">Interview Calendar</h2>
                        <button id="closeCalendar" class="text-gray-500 hover:text-gray-700 dark:hover:text-gray-300">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <div id="calendar"></div>
                </div>
            </div>

            <!-- Today's Interviews Section (if any) -->
            <?php 
            $todayInterviews = array_filter($upcomingInterviews, function($i) {
                return ($i['time_category'] ?? '') === 'today';
            });
            if (!empty($todayInterviews)): 
            ?>
            <div class="mb-8" data-aos="fade-up">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-xl font-bold flex items-center">
                        <i class="fas fa-sun text-yellow-500 mr-2"></i>
                        Today's Interviews
                        <span class="ml-3 bg-red-500 text-white text-xs px-2 py-1 rounded-full animate-pulse"><?php echo count($todayInterviews); ?></span>
                    </h2>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <?php foreach ($todayInterviews as $interview): ?>
                    <div class="bg-gradient-to-r from-indigo-500 to-purple-600 rounded-2xl shadow-xl p-6 text-white relative overflow-hidden group">
                        <div class="absolute top-0 right-0 w-32 h-32 bg-white/10 rounded-full -mr-8 -mt-8"></div>
                        <div class="absolute bottom-0 left-0 w-24 h-24 bg-white/10 rounded-full -ml-8 -mb-8"></div>
                        
                        <div class="relative z-10">
                            <div class="flex items-start justify-between mb-4">
                                <div>
                                    <span class="text-xs bg-white/20 px-3 py-1 rounded-full">
                                        <i class="fas fa-clock mr-1"></i>
                                        <?php echo date('g:i A', strtotime($interview['interview_date'])); ?>
                                    </span>
                                </div>
                                <span class="text-3xl font-bold countdown-timer" 
                                      data-target="<?php echo strtotime($interview['interview_date']); ?>"
                                      id="countdown-<?php echo $interview['id']; ?>">
                                    <?php 
                                    $hours = $interview['hours_until_interview'] ?? 0;
                                    if ($hours < 1) {
                                        $minutes = floor(($hours * 60) % 60);
                                        echo "in {$minutes}m";
                                    } elseif ($hours < 24) {
                                        echo "in {$hours}h";
                                    } else {
                                        $days = floor($hours / 24);
                                        echo "in {$days}d";
                                    }
                                    ?>
                                </span>
                            </div>
                            
                            <h3 class="text-xl font-bold mb-2"><?php echo htmlspecialchars($interview['job_title']); ?></h3>
                            <p class="text-white/90 mb-4 flex items-center">
                                <i class="fas fa-building mr-2"></i>
                                <?php echo htmlspecialchars($interview['company_name']); ?>
                            </p>
                            
                            <div class="flex flex-wrap gap-2 mb-6">
                                <span class="bg-white/20 px-3 py-1 rounded-full text-xs">
                                    <i class="fas fa-video mr-1"></i>
                                    <?php echo ucfirst(str_replace('_', ' ', $interview['interview_type'] ?? 'video')); ?>
                                </span>
                                <span class="bg-white/20 px-3 py-1 rounded-full text-xs">
                                    <i class="fas fa-user mr-1"></i>
                                    <?php echo htmlspecialchars($interview['interviewer_name'] ?? 'Interviewer'); ?>
                                </span>
                            </div>
                            
                            <?php if (!empty($interview['meeting_link'])): ?>
                            <a href="<?php echo htmlspecialchars($interview['meeting_link']); ?>" 
                               target="_blank"
                               class="block w-full bg-white text-indigo-600 text-center py-3 rounded-xl font-semibold hover:shadow-xl transition-all duration-300 hover:scale-105">
                                <i class="fas fa-video mr-2"></i>
                                Join Meeting
                            </a>
                            <?php else: ?>
                            <div class="text-center py-3 bg-white/20 rounded-xl">
                                <i class="fas fa-info-circle mr-2"></i>
                                Link will be provided soon
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Upcoming Interviews Section -->
            <div class="mb-8" data-aos="fade-up">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-xl font-bold flex items-center">
                        <i class="fas fa-calendar-alt text-indigo-600 mr-2"></i>
                        Upcoming Interviews
                    </h2>
                    <?php if (!empty($upcomingInterviews) && count($upcomingInterviews) > 3): ?>
                    <a href="?filter=upcoming" class="text-indigo-600 dark:text-indigo-400 hover:underline text-sm flex items-center">
                        View All <i class="fas fa-arrow-right ml-2"></i>
                    </a>
                    <?php endif; ?>
                </div>
                
                <?php if (empty($upcomingInterviews)): ?>
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-12 text-center">
                    <div class="w-24 h-24 bg-gray-100 dark:bg-gray-700 rounded-full flex items-center justify-center mx-auto mb-6">
                        <i class="fas fa-calendar-times text-4xl text-gray-400"></i>
                    </div>
                    <h3 class="text-2xl font-bold mb-3">No Upcoming Interviews</h3>
                    <p class="text-gray-600 dark:text-gray-400 mb-8 max-w-md mx-auto">
                        You don't have any scheduled interviews at the moment. Keep applying to jobs and get noticed by employers!
                    </p>
                    <a href="jobs.php" class="inline-flex items-center space-x-3 bg-gradient-to-r from-indigo-600 to-purple-600 text-white px-8 py-4 rounded-xl font-semibold hover:shadow-xl transition-all duration-300 hover:scale-105">
                        <i class="fas fa-search"></i>
                        <span>Browse Jobs</span>
                    </a>
                </div>
                <?php else: ?>
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <?php foreach ($upcomingInterviews as $index => $interview): ?>
                    <div class="group bg-white dark:bg-gray-800 rounded-2xl shadow-xl overflow-hidden hover-card border-l-4 border-indigo-500" 
                         data-aos="fade-up" 
                         data-aos-delay="<?php echo min($index * 50, 300); ?>">
                        
                        <!-- Interview Header -->
                        <div class="p-6">
                            <div class="flex items-start justify-between mb-4">
                                <div class="flex items-center space-x-3">
                                    <img src="<?php echo htmlspecialchars($interview['company_logo'] ?: 'https://ui-avatars.com/api/?name=' . urlencode($interview['company_name']) . '&background=4f46e5&color=fff'); ?>" 
                                         alt="<?php echo htmlspecialchars($interview['company_name']); ?>"
                                         class="w-14 h-14 rounded-xl object-cover border-2 border-gray-200 dark:border-gray-600">
                                    <div>
                                        <h3 class="font-bold text-lg group-hover:text-indigo-600 transition">
                                            <a href="job-details.php?slug=<?php echo htmlspecialchars($interview['job_slug']); ?>">
                                                <?php echo htmlspecialchars($interview['job_title']); ?>
                                            </a>
                                        </h3>
                                        <p class="text-sm text-gray-600 dark:text-gray-400">
                                            <?php echo htmlspecialchars($interview['company_name']); ?>
                                            <?php if (!empty($interview['employer_verified'])): ?>
                                            <i class="fas fa-check-circle text-blue-500 ml-1 text-xs" title="Verified Employer"></i>
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                </div>
                                <span class="badge-<?php echo $interview['interview_status']; ?> px-3 py-1 rounded-full text-xs font-semibold">
                                    <?php echo ucfirst($interview['interview_status']); ?>
                                </span>
                            </div>
                            
                            <!-- Interview Details -->
                            <div class="grid grid-cols-2 gap-4 mb-6">
                                <div class="bg-gray-50 dark:bg-gray-700/50 rounded-xl p-3">
                                    <p class="text-xs text-gray-500 mb-1">Date & Time</p>
                                    <p class="font-semibold text-sm">
                                        <i class="far fa-calendar mr-1 text-indigo-500"></i>
                                        <?php echo date('M j, Y', strtotime($interview['interview_date'])); ?>
                                    </p>
                                    <p class="text-sm mt-1">
                                        <i class="far fa-clock mr-1 text-indigo-500"></i>
                                        <?php echo date('g:i A', strtotime($interview['interview_date'])); ?>
                                        <?php if (!empty($interview['timezone'])): ?>
                                        <span class="text-xs text-gray-500">(<?php echo $interview['timezone']; ?>)</span>
                                        <?php endif; ?>
                                    </p>
                                </div>
                                
                                <div class="bg-gray-50 dark:bg-gray-700/50 rounded-xl p-3">
                                    <p class="text-xs text-gray-500 mb-1">Duration & Type</p>
                                    <p class="font-semibold text-sm">
                                        <i class="fas fa-hourglass-half mr-1 text-indigo-500"></i>
                                        <?php echo $interview['duration_minutes'] ?? 30; ?> minutes
                                    </p>
                                    <p class="text-sm mt-1 type-<?php echo $interview['interview_type']; ?> px-2 py-1 rounded-full text-xs inline-block">
                                        <i class="fas <?php echo $interview['interview_type'] === 'video' ? 'fa-video' : ($interview['interview_type'] === 'phone' ? 'fa-phone' : 'fa-building'); ?> mr-1"></i>
                                        <?php echo ucfirst(str_replace('_', ' ', $interview['interview_type'] ?? 'video')); ?>
                                    </p>
                                </div>
                            </div>
                            
                            <!-- Interviewer & Location -->
                            <div class="mb-6 space-y-2">
                                <?php if (!empty($interview['interviewer_name'])): ?>
                                <p class="text-sm flex items-center">
                                    <i class="fas fa-user-tie w-5 text-indigo-500"></i>
                                    <span class="text-gray-600 dark:text-gray-400 mr-2">Interviewer:</span>
                                    <span class="font-medium"><?php echo htmlspecialchars($interview['interviewer_name']); ?></span>
                                </p>
                                <?php endif; ?>
                                
                                <?php if (!empty($interview['interviewer_email'])): ?>
                                <p class="text-sm flex items-center">
                                    <i class="fas fa-envelope w-5 text-indigo-500"></i>
                                    <span class="text-gray-600 dark:text-gray-400 mr-2">Email:</span>
                                    <a href="mailto:<?php echo htmlspecialchars($interview['interviewer_email']); ?>" class="text-indigo-600 hover:underline">
                                        <?php echo htmlspecialchars($interview['interviewer_email']); ?>
                                    </a>
                                </p>
                                <?php endif; ?>
                                
                                <?php if (!empty($interview['location']) && $interview['interview_type'] === 'in_person'): ?>
                                <p class="text-sm flex items-start">
                                    <i class="fas fa-map-marker-alt w-5 text-indigo-500 mt-1"></i>
                                    <span class="text-gray-600 dark:text-gray-400 mr-2">Location:</span>
                                    <span class="font-medium"><?php echo nl2br(htmlspecialchars($interview['location'])); ?></span>
                                </p>
                                <?php endif; ?>
                                
                                <?php if (!empty($interview['notes'])): ?>
                                <p class="text-sm flex items-start">
                                    <i class="fas fa-sticky-note w-5 text-indigo-500 mt-1"></i>
                                    <span class="text-gray-600 dark:text-gray-400 mr-2">Notes:</span>
                                    <span class="font-medium"><?php echo nl2br(htmlspecialchars($interview['notes'])); ?></span>
                                </p>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Actions -->
                            <div class="flex items-center justify-between pt-4 border-t border-gray-200 dark:border-gray-700">
                                <div class="flex space-x-2">
                                    <?php if (!empty($interview['meeting_link'])): ?>
                                    <a href="<?php echo htmlspecialchars($interview['meeting_link']); ?>" 
                                       target="_blank"
                                       class="meeting-link-btn text-white px-4 py-2 rounded-xl text-sm font-semibold flex items-center">
                                        <i class="fas fa-video mr-2"></i>
                                        Join
                                    </a>
                                    <?php endif; ?>
                                    
                                    <button onclick="rescheduleInterview(<?php echo $interview['id']; ?>)" 
                                            class="px-4 py-2 border border-indigo-600 text-indigo-600 dark:text-indigo-400 rounded-xl text-sm font-semibold hover:bg-indigo-50 dark:hover:bg-indigo-900/20 transition">
                                        <i class="fas fa-calendar-alt mr-2"></i>
                                        Reschedule
                                    </button>
                                </div>
                                
                                <div class="text-right">
                                    <p class="text-xs text-gray-500">
                                        <?php echo timeAgo($interview['interview_scheduled_at']); ?>
                                    </p>
                                    <a href="application-details.php?id=<?php echo $interview['application_id']; ?>" 
                                       class="text-xs text-indigo-600 hover:underline">
                                        View Application
                                    </a>
                                </div>
                            </div>
                            
                            <!-- Confirmation Status -->
                            <div class="mt-4 flex items-center space-x-4 text-xs">
                                <div class="flex items-center">
                                    <div class="w-2 h-2 <?php echo $interview['seeker_confirmed'] ? 'bg-green-500' : 'bg-yellow-500'; ?> rounded-full mr-2"></div>
                                    <span class="text-gray-600 dark:text-gray-400">Your confirmation: <?php echo $interview['seeker_confirmed'] ? 'Confirmed' : 'Pending'; ?></span>
                                </div>
                                <div class="flex items-center">
                                    <div class="w-2 h-2 <?php echo $interview['employer_confirmed'] ? 'bg-green-500' : 'bg-yellow-500'; ?> rounded-full mr-2"></div>
                                    <span class="text-gray-600 dark:text-gray-400">Employer: <?php echo $interview['employer_confirmed'] ? 'Confirmed' : 'Pending'; ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Past Interviews Section -->
            <?php if (!empty($pastInterviews)): ?>
            <div class="mb-8" data-aos="fade-up">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-xl font-bold flex items-center">
                        <i class="fas fa-history text-gray-500 mr-2"></i>
                        Past Interviews
                    </h2>
                </div>
                
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead class="bg-gray-50 dark:bg-gray-700">
                                <tr>
                                    <th class="px-6 py-4 text-left text-sm font-semibold">Job & Company</th>
                                    <th class="px-6 py-4 text-left text-sm font-semibold">Date</th>
                                    <th class="px-6 py-4 text-left text-sm font-semibold">Type</th>
                                    <th class="px-6 py-4 text-left text-sm font-semibold">Status</th>
                                    <th class="px-6 py-4 text-left text-sm font-semibold">Rating</th>
                                    <th class="px-6 py-4 text-left text-sm font-semibold">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                <?php foreach ($pastInterviews as $interview): ?>
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50 transition">
                                    <td class="px-6 py-4">
                                        <div class="flex items-center space-x-3">
                                            <img src="<?php echo htmlspecialchars($interview['company_logo'] ?: 'https://ui-avatars.com/api/?name=' . urlencode($interview['company_name']) . '&background=4f46e5&color=fff'); ?>" 
                                                 alt="" 
                                                 class="w-10 h-10 rounded-lg object-cover">
                                            <div>
                                                <p class="font-semibold"><?php echo htmlspecialchars($interview['job_title']); ?></p>
                                                <p class="text-xs text-gray-500"><?php echo htmlspecialchars($interview['company_name']); ?></p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <p class="font-medium"><?php echo date('M j, Y', strtotime($interview['interview_date'])); ?></p>
                                        <p class="text-xs text-gray-500"><?php echo date('g:i A', strtotime($interview['interview_date'])); ?></p>
                                    </td>
                                    <td class="px-6 py-4">
                                        <span class="type-<?php echo $interview['interview_type']; ?> px-3 py-1 rounded-full text-xs">
                                            <?php echo ucfirst(str_replace('_', ' ', $interview['interview_type'])); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4">
                                        <span class="badge-<?php echo $interview['past_status']; ?> px-3 py-1 rounded-full text-xs">
                                            <?php echo ucfirst($interview['past_status']); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4">
                                        <?php if (!empty($interview['rating'])): ?>
                                        <div class="flex items-center">
                                            <span class="font-semibold mr-2"><?php echo $interview['rating']; ?>/5</span>
                                            <div class="flex text-yellow-400">
                                                <?php for($i = 1; $i <= 5; $i++): ?>
                                                <i class="fas fa-star <?php echo $i <= $interview['rating'] ? '' : 'text-gray-300'; ?> text-xs"></i>
                                                <?php endfor; ?>
                                            </div>
                                        </div>
                                        <?php else: ?>
                                        <span class="text-gray-400">No rating</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="flex space-x-2">
                                            <a href="interview-details.php?id=<?php echo $interview['id']; ?>" 
                                               class="text-indigo-600 hover:text-indigo-800"
                                               title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <?php if (empty($interview['rating'])): ?>
                                            <button onclick="provideFeedback(<?php echo $interview['id']; ?>)" 
                                                    class="text-green-600 hover:text-green-800"
                                                    title="Provide Feedback">
                                                <i class="fas fa-star"></i>
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Feedback Pending Section -->
            <?php if (!empty($feedbackPending) && $filter === 'feedback'): ?>
            <div class="mb-8" data-aos="fade-up">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-xl font-bold flex items-center">
                        <i class="fas fa-star text-yellow-500 mr-2"></i>
                        Interviews Needing Feedback
                    </h2>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <?php foreach ($feedbackPending as $feedback): ?>
                    <div class="bg-yellow-50 dark:bg-yellow-900/10 rounded-2xl shadow-xl p-6 border border-yellow-200 dark:border-yellow-800">
                        <div class="flex items-center space-x-3 mb-4">
                            <img src="<?php echo htmlspecialchars($feedback['company_logo'] ?: 'https://ui-avatars.com/api/?name=' . urlencode($feedback['company_name']) . '&background=4f46e5&color=fff'); ?>" 
                                 alt="" 
                                 class="w-12 h-12 rounded-lg object-cover">
                            <div>
                                <h3 class="font-semibold"><?php echo htmlspecialchars($feedback['job_title']); ?></h3>
                                <p class="text-sm text-gray-600 dark:text-gray-400"><?php echo htmlspecialchars($feedback['company_name']); ?></p>
                            </div>
                        </div>
                        <p class="text-sm text-gray-500 mb-4">
                            Interviewed on <?php echo date('M j, Y', strtotime($feedback['interview_date'])); ?>
                        </p>
                        <button onclick="provideFeedback(<?php echo $feedback['id']; ?>)" 
                                class="w-full bg-gradient-to-r from-yellow-500 to-orange-500 text-white py-3 rounded-xl font-semibold hover:shadow-lg transition-all">
                            <i class="fas fa-star mr-2"></i>
                            Rate Your Interview
                        </button>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Interview Tips Section -->
            <div class="mt-8 grid grid-cols-1 lg:grid-cols-3 gap-6" data-aos="fade-up">
                <div class="bg-gradient-to-r from-indigo-500 to-purple-600 rounded-2xl shadow-xl p-6 text-white lg:col-span-2">
                    <h3 class="text-xl font-bold mb-4 flex items-center">
                        <i class="fas fa-lightbulb mr-2"></i>
                        Interview Preparation Tips
                    </h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="flex items-start space-x-3">
                            <div class="w-8 h-8 bg-white/20 rounded-lg flex items-center justify-center flex-shrink-0">
                                <i class="fas fa-user-tie"></i>
                            </div>
                            <div>
                                <h4 class="font-semibold mb-1">Research the Company</h4>
                                <p class="text-sm text-white/80">Know their products, culture, and recent news</p>
                            </div>
                        </div>
                        <div class="flex items-start space-x-3">
                            <div class="w-8 h-8 bg-white/20 rounded-lg flex items-center justify-center flex-shrink-0">
                                <i class="fas fa-question-circle"></i>
                            </div>
                            <div>
                                <h4 class="font-semibold mb-1">Prepare Questions</h4>
                                <p class="text-sm text-white/80">Have 3-5 thoughtful questions ready</p>
                            </div>
                        </div>
                        <div class="flex items-start space-x-3">
                            <div class="w-8 h-8 bg-white/20 rounded-lg flex items-center justify-center flex-shrink-0">
                                <i class="fas fa-video"></i>
                            </div>
                            <div>
                                <h4 class="font-semibold mb-1">Test Your Tech</h4>
                                <p class="text-sm text-white/80">Check camera, mic, and internet before video calls</p>
                            </div>
                        </div>
                        <div class="flex items-start space-x-3">
                            <div class="w-8 h-8 bg-white/20 rounded-lg flex items-center justify-center flex-shrink-0">
                                <i class="fas fa-clock"></i>
                            </div>
                            <div>
                                <h4 class="font-semibold mb-1">Be Early</h4>
                                <p class="text-sm text-white/80">Join 5-10 minutes before scheduled time</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6">
                    <h3 class="text-xl font-bold mb-4 flex items-center">
                        <i class="fas fa-chart-pie mr-2 text-indigo-600"></i>
                        Interview Breakdown
                    </h3>
                    <div class="space-y-4">
                        <?php foreach ($interviewTypeBreakdown as $type): ?>
                        <div>
                            <div class="flex items-center justify-between text-sm mb-1">
                                <span class="capitalize"><?php echo str_replace('_', ' ', $type['interview_type']); ?></span>
                                <span class="font-semibold"><?php echo $type['count']; ?> (<?php echo $type['upcoming_count']; ?> upcoming)</span>
                            </div>
                            <div class="w-full h-2 bg-gray-200 dark:bg-gray-700 rounded-full overflow-hidden">
                                <div class="bg-indigo-600 h-full rounded-full" 
                                     style="width: <?php echo ($interviewStats['total_interviews'] > 0) ? ($type['count'] / $interviewStats['total_interviews'] * 100) : 0; ?>%"></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Interview Timeline -->
            <?php if (!empty($upcomingInterviews) || !empty($pastInterviews)): ?>
            <div class="mt-8 bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6" data-aos="fade-up">
                <h2 class="text-xl font-bold mb-6">Interview Timeline</h2>
                <div class="relative">
                    <div class="timeline-line"></div>
                    <div class="space-y-6">
                        <?php 
                        $timelineInterviews = array_slice(array_merge(
                            array_slice($upcomingInterviews, 0, 3),
                            array_slice($pastInterviews, 0, 3)
                        ), 0, 5);
                        
                        foreach ($timelineInterviews as $timeline):
                        ?>
                        <div class="relative pl-8">
                            <div class="timeline-dot absolute left-0 top-1"></div>
                            <div class="flex items-start justify-between">
                                <div>
                                    <p class="font-semibold"><?php echo htmlspecialchars($timeline['job_title'] ?? $timeline['job_title']); ?></p>
                                    <p class="text-sm text-gray-600 dark:text-gray-400">
                                        <?php echo htmlspecialchars($timeline['company_name']); ?>
                                    </p>
                                </div>
                                <div class="text-right">
                                    <p class="text-sm font-medium">
                                        <?php echo date('M j, Y', strtotime($timeline['interview_date'] ?? $timeline['interview_date'])); ?>
                                    </p>
                                    <p class="text-xs text-gray-500">
                                        <?php echo date('g:i A', strtotime($timeline['interview_date'] ?? $timeline['interview_date'])); ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
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
                    <img src="<?php echo htmlspecialchars($avatarUrl); ?>" alt="Profile" class="w-12 h-12 rounded-lg object-cover">
                    <div class="flex-1 min-w-0">
                        <p class="font-semibold truncate"><?php echo htmlspecialchars(($seekerProfile['first_name'] ?? 'User') . ' ' . ($seekerProfile['last_name'] ?? '')); ?></p>
                        <p class="text-sm text-gray-500 truncate">Job Seeker</p>
                    </div>
                </div>
                
                <nav class="space-y-1">
                    <a href="dashboard.php" class="block px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">Dashboard</a>
                    <a href="jobs.php" class="block px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">Find Jobs</a>
                    <a href="applications.php" class="block px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">Applications</a>
                    <a href="saved-jobs.php" class="block px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">Saved Jobs</a>
                    <a href="interviews.php" class="block px-4 py-2 bg-indigo-50 dark:bg-indigo-900/20 text-indigo-600 rounded-lg">Interviews</a>
                    <a href="profile.php" class="block px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">Profile</a>
                    <a href="settings.php" class="block px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">Settings</a>
                    <a href="/logout.php" class="block px-4 py-2 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg">Logout</a>
                </nav>
            </div>
        </div>
    </div>
</div>

<!-- Reschedule Interview Modal -->
<div id="rescheduleModal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" id="rescheduleModalOverlay"></div>
    <div class="absolute left-1/2 top-1/2 transform -translate-x-1/2 -translate-y-1/2 w-full max-w-lg bg-white dark:bg-gray-800 rounded-2xl shadow-2xl p-6">
        <div class="flex items-center justify-between mb-6">
            <h3 class="text-xl font-bold">Reschedule Interview</h3>
            <button id="closeRescheduleModal" class="text-gray-500 hover:text-gray-700">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <form id="rescheduleForm">
            <input type="hidden" id="rescheduleInterviewId" name="interview_id">
            
            <div class="mb-4">
                <label class="block text-sm font-medium mb-2">New Date & Time</label>
                <input type="datetime-local" 
                       id="newInterviewDate" 
                       name="new_date" 
                       class="w-full px-4 py-3 bg-gray-100 dark:bg-gray-700 rounded-xl border-0 focus:ring-2 focus:ring-indigo-500"
                       required>
            </div>
            
            <div class="mb-4">
                <label class="block text-sm font-medium mb-2">Reason for Rescheduling</label>
                <textarea id="rescheduleReason" 
                          name="reason" 
                          rows="3" 
                          class="w-full px-4 py-3 bg-gray-100 dark:bg-gray-700 rounded-xl border-0 focus:ring-2 focus:ring-indigo-500"
                          placeholder="Please provide a reason..."></textarea>
            </div>
            
            <div class="flex space-x-3">
                <button type="submit" 
                        class="flex-1 bg-gradient-to-r from-indigo-600 to-purple-600 text-white py-3 rounded-xl font-semibold hover:shadow-lg transition-all">
                    Request Reschedule
                </button>
                <button type="button" 
                        id="cancelReschedule"
                        class="px-6 py-3 border border-gray-300 dark:border-gray-600 rounded-xl font-semibold hover:bg-gray-100 dark:hover:bg-gray-700 transition">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Feedback Modal -->
<div id="feedbackModal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" id="feedbackModalOverlay"></div>
    <div class="absolute left-1/2 top-1/2 transform -translate-x-1/2 -translate-y-1/2 w-full max-w-lg bg-white dark:bg-gray-800 rounded-2xl shadow-2xl p-6">
        <div class="flex items-center justify-between mb-6">
            <h3 class="text-xl font-bold">Rate Your Interview</h3>
            <button id="closeFeedbackModal" class="text-gray-500 hover:text-gray-700">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <form id="feedbackForm">
            <input type="hidden" id="feedbackInterviewId" name="interview_id">
            
            <div class="mb-6 text-center">
                <p class="text-sm text-gray-500 mb-3">How would you rate this interview?</p>
                <div class="flex justify-center space-x-2 text-3xl" id="ratingStars">
                    <i class="far fa-star cursor-pointer hover:text-yellow-400 transition" data-rating="1"></i>
                    <i class="far fa-star cursor-pointer hover:text-yellow-400 transition" data-rating="2"></i>
                    <i class="far fa-star cursor-pointer hover:text-yellow-400 transition" data-rating="3"></i>
                    <i class="far fa-star cursor-pointer hover:text-yellow-400 transition" data-rating="4"></i>
                    <i class="far fa-star cursor-pointer hover:text-yellow-400 transition" data-rating="5"></i>
                </div>
                <input type="hidden" id="interviewRating" name="rating" value="0">
            </div>
            
            <div class="mb-4">
                <label class="block text-sm font-medium mb-2">Feedback (Optional)</label>
                <textarea id="interviewFeedback" 
                          name="feedback" 
                          rows="4" 
                          class="w-full px-4 py-3 bg-gray-100 dark:bg-gray-700 rounded-xl border-0 focus:ring-2 focus:ring-indigo-500"
                          placeholder="Share your experience..."></textarea>
            </div>
            
            <div class="flex space-x-3">
                <button type="submit" 
                        class="flex-1 bg-gradient-to-r from-indigo-600 to-purple-600 text-white py-3 rounded-xl font-semibold hover:shadow-lg transition-all">
                    Submit Feedback
                </button>
                <button type="button" 
                        id="cancelFeedback"
                        class="px-6 py-3 border border-gray-300 dark:border-gray-600 rounded-xl font-semibold hover:bg-gray-100 dark:hover:bg-gray-700 transition">
                    Skip
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Scripts -->
<script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>

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

// Calendar Toggle
const toggleCalendar = document.getElementById('toggleCalendar');
const calendarContainer = document.getElementById('calendarContainer');
const closeCalendar = document.getElementById('closeCalendar');

if (toggleCalendar && calendarContainer) {
    toggleCalendar.addEventListener('click', () => {
        calendarContainer.classList.toggle('hidden');
        if (!calendarContainer.classList.contains('hidden')) {
            setTimeout(() => {
                initCalendar();
            }, 100);
        }
    });
}

if (closeCalendar) {
    closeCalendar.addEventListener('click', () => {
        calendarContainer.classList.add('hidden');
    });
}

// Initialize Calendar
let calendar = null;
function initCalendar() {
    if (calendar) {
        calendar.destroy();
    }
    
    const calendarEl = document.getElementById('calendar');
    if (!calendarEl) return;
    
    calendar = new FullCalendar.Calendar(calendarEl, {
        initialView: 'dayGridMonth',
        headerToolbar: {
            left: 'prev,next today',
            center: 'title',
            right: 'dayGridMonth,timeGridWeek,timeGridDay'
        },
        events: [
            <?php foreach ($upcomingInterviews as $interview): ?>
            {
                id: '<?php echo $interview['id']; ?>',
                title: '<?php echo addslashes($interview['job_title']); ?>',
                start: '<?php echo date('Y-m-d\TH:i:s', strtotime($interview['interview_date'])); ?>',
                end: '<?php echo date('Y-m-d\TH:i:s', strtotime($interview['interview_date'] . ' + ' . ($interview['duration_minutes'] ?? 30) . ' minutes')); ?>',
                backgroundColor: '#4f46e5',
                borderColor: '#4f46e5',
                textColor: '#ffffff',
                extendedProps: {
                    company: '<?php echo addslashes($interview['company_name']); ?>',
                    type: '<?php echo $interview['interview_type']; ?>',
                    meetingLink: '<?php echo addslashes($interview['meeting_link'] ?? ''); ?>'
                }
            },
            <?php endforeach; ?>
            <?php foreach ($pastInterviews as $interview): ?>
            {
                id: '<?php echo $interview['id']; ?>',
                title: '<?php echo addslashes($interview['job_title']); ?>',
                start: '<?php echo date('Y-m-d\TH:i:s', strtotime($interview['interview_date'])); ?>',
                backgroundColor: '#9ca3af',
                borderColor: '#9ca3af',
                textColor: '#ffffff',
                extendedProps: {
                    company: '<?php echo addslashes($interview['company_name']); ?>',
                    type: '<?php echo $interview['interview_type']; ?>',
                    status: '<?php echo $interview['past_status']; ?>'
                }
            },
            <?php endforeach; ?>
        ],
        eventClick: function(info) {
            const props = info.event.extendedProps;
            if (props.meetingLink) {
                if (confirm(`Join interview for ${info.event.title} at ${props.company}?`)) {
                    window.open(props.meetingLink, '_blank');
                }
            } else {
                alert(`Interview: ${info.event.title}\nCompany: ${props.company}\nType: ${props.type}\nStatus: ${props.status || 'scheduled'}`);
            }
        },
        height: 'auto',
        themeSystem: 'standard'
    });
    
    calendar.render();
}

// Filter Dropdown
const filterBtn = document.getElementById('filterBtn');
const filterDropdown = document.getElementById('filterDropdown');

if (filterBtn && filterDropdown) {
    filterBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        filterDropdown.classList.toggle('hidden');
    });
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

// Close dropdowns when clicking outside
document.addEventListener('click', (e) => {
    if (filterBtn && !filterBtn.contains(e.target)) {
        filterDropdown?.classList.add('hidden');
    }
});

// Search functionality
const searchInput = document.getElementById('searchInterviews');
if (searchInput) {
    let searchTimeout;
    searchInput.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            const searchTerm = this.value;
            if (searchTerm.length > 2 || searchTerm.length === 0) {
                window.location.href = '?search=' + encodeURIComponent(searchTerm) + '&filter=<?php echo $filter; ?>';
            }
        }, 500);
    });
}

// Countdown Timers
function updateCountdowns() {
    document.querySelectorAll('.countdown-timer').forEach(el => {
        const targetTime = parseInt(el.dataset.target) * 1000;
        const now = new Date().getTime();
        const distance = targetTime - now;
        
        if (distance < 0) {
            el.textContent = 'Now';
            return;
        }
        
        const hours = Math.floor(distance / (1000 * 60 * 60));
        const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
        
        if (hours < 1) {
            el.textContent = `in ${minutes}m`;
        } else if (hours < 24) {
            el.textContent = `in ${hours}h ${minutes}m`;
        } else {
            const days = Math.floor(hours / 24);
            el.textContent = `in ${days}d`;
        }
    });
}

setInterval(updateCountdowns, 60000); // Update every minute
updateCountdowns();

// Reschedule Modal
const rescheduleModal = document.getElementById('rescheduleModal');
const rescheduleModalOverlay = document.getElementById('rescheduleModalOverlay');
const closeRescheduleModal = document.getElementById('closeRescheduleModal');
const cancelReschedule = document.getElementById('cancelReschedule');
const rescheduleForm = document.getElementById('rescheduleForm');

function rescheduleInterview(interviewId) {
    document.getElementById('rescheduleInterviewId').value = interviewId;
    rescheduleModal.classList.remove('hidden');
}

function closeRescheduleModalFunc() {
    rescheduleModal.classList.add('hidden');
}

if (rescheduleModalOverlay) {
    rescheduleModalOverlay.addEventListener('click', closeRescheduleModalFunc);
}
if (closeRescheduleModal) {
    closeRescheduleModal.addEventListener('click', closeRescheduleModalFunc);
}
if (cancelReschedule) {
    cancelReschedule.addEventListener('click', closeRescheduleModalFunc);
}

if (rescheduleForm) {
    rescheduleForm.addEventListener('submit', function(e) {
        e.preventDefault();
        
        const formData = new FormData(this);
        
        fetch('/ajax/reschedule-interview.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Reschedule request sent successfully!');
                closeRescheduleModalFunc();
                location.reload();
            } else {
                alert('Error: ' + (data.message || 'Could not reschedule interview'));
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred');
        });
    });
}

// Feedback Modal
const feedbackModal = document.getElementById('feedbackModal');
const feedbackModalOverlay = document.getElementById('feedbackModalOverlay');
const closeFeedbackModal = document.getElementById('closeFeedbackModal');
const cancelFeedback = document.getElementById('cancelFeedback');
const feedbackForm = document.getElementById('feedbackForm');
const ratingStars = document.getElementById('ratingStars');
const interviewRating = document.getElementById('interviewRating');

function provideFeedback(interviewId) {
    document.getElementById('feedbackInterviewId').value = interviewId;
    interviewRating.value = 0;
    
    // Reset stars
    document.querySelectorAll('#ratingStars i').forEach(star => {
        star.className = 'far fa-star cursor-pointer hover:text-yellow-400 transition';
    });
    
    feedbackModal.classList.remove('hidden');
}

// Rating stars interaction
if (ratingStars) {
    ratingStars.addEventListener('mouseover', (e) => {
        if (e.target.tagName === 'I') {
            const rating = parseInt(e.target.dataset.rating);
            highlightStars(rating);
        }
    });
    
    ratingStars.addEventListener('mouseout', () => {
        const currentRating = parseInt(interviewRating.value);
        highlightStars(currentRating);
    });
    
    ratingStars.addEventListener('click', (e) => {
        if (e.target.tagName === 'I') {
            const rating = parseInt(e.target.dataset.rating);
            interviewRating.value = rating;
            highlightStars(rating);
        }
    });
}

function highlightStars(rating) {
    document.querySelectorAll('#ratingStars i').forEach((star, index) => {
        if (index < rating) {
            star.className = 'fas fa-star text-yellow-400 cursor-pointer hover:text-yellow-400 transition';
        } else {
            star.className = 'far fa-star cursor-pointer hover:text-yellow-400 transition';
        }
    });
}

function closeFeedbackModalFunc() {
    feedbackModal.classList.add('hidden');
}

if (feedbackModalOverlay) {
    feedbackModalOverlay.addEventListener('click', closeFeedbackModalFunc);
}
if (closeFeedbackModal) {
    closeFeedbackModal.addEventListener('click', closeFeedbackModalFunc);
}
if (cancelFeedback) {
    cancelFeedback.addEventListener('click', closeFeedbackModalFunc);
}

if (feedbackForm) {
    feedbackForm.addEventListener('submit', function(e) {
        e.preventDefault();
        
        const formData = new FormData(this);
        
        fetch('/ajax/submit-interview-feedback.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Thank you for your feedback!');
                closeFeedbackModalFunc();
                location.reload();
            } else {
                alert('Error: ' + (data.message || 'Could not submit feedback'));
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred');
        });
    });
}

// Auto-refresh for upcoming interviews (every 5 minutes)
setInterval(() => {
    // Check for new interviews or status changes
    fetch('/ajax/check-interview-updates.php')
        .then(response => response.json())
        .then(data => {
            if (data.hasUpdates) {
                location.reload();
            }
        })
        .catch(error => console.error('Error checking updates:', error));
}, 300000); // 5 minutes

// Keyboard shortcuts
document.addEventListener('keydown', (e) => {
    // Escape to close modals
    if (e.key === 'Escape') {
        if (!rescheduleModal.classList.contains('hidden')) {
            closeRescheduleModalFunc();
        }
        if (!feedbackModal.classList.contains('hidden')) {
            closeFeedbackModalFunc();
        }
        if (!filterDropdown.classList.contains('hidden')) {
            filterDropdown.classList.add('hidden');
        }
        if (!calendarContainer.classList.contains('hidden')) {
            calendarContainer.classList.add('hidden');
        }
        if (!mobileSidebar.classList.contains('hidden')) {
            closeMobileSidebarFunc();
        }
    }
    
    // Ctrl/Cmd + K to focus search
    if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
        e.preventDefault();
        searchInput?.focus();
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