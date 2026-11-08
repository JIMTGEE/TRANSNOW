<?php
// seekers/applications.php - Seeker Applications Management
// Version: 2.0 - Enhanced with full database integration

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
$seeker_id = getCurrentSeekerId($pdo);

if (!$seeker_id) {
    $_SESSION['error'] = 'Seeker profile not found';
    header('Location: /logout.php');
    exit();
}

// Get seeker info for header
$seekerData = fetchOne("
    SELECT first_name, last_name, profile_picture, headline 
    FROM seekers WHERE id = ?
", [$seeker_id]);

// Handle status filter
$statusFilter = isset($_GET['status']) ? sanitize($_GET['status']) : 'all';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Build query based on filters
$whereClause = "a.seeker_id = ?";
$params = [$seeker_id];

if ($statusFilter !== 'all') {
    $whereClause .= " AND a.application_status = ?";
    $params[] = $statusFilter;
}

// Get total count for pagination
$totalQuery = "
    SELECT COUNT(*) as total 
    FROM applications a
    WHERE $whereClause
";
$totalResult = fetchOne($totalQuery, $params);
$totalApplications = $totalResult ? $totalResult['total'] : 0;
$totalPages = ceil($totalApplications / $limit);

// Fetch applications with full details
$applications = fetchAll("
    SELECT 
        a.*,
        j.id as job_id,
        j.title as job_title,
        j.slug as job_slug,
        j.location_type,
        j.city as job_city,
        j.country as job_country,
        j.salary_min as job_salary_min,
        j.salary_max as job_salary_max,
        j.salary_currency,
        j.salary_type,
        j.experience_level,
        j.application_deadline,
        j.status as job_status,
        e.id as employer_id,
        e.company_name,
        e.company_logo,
        e.industry as company_industry,
        e.city as company_city,
        e.country as company_country,
        e.is_verified as employer_verified,
        (SELECT COUNT(*) FROM interviews WHERE application_id = a.id) as interview_count,
        (SELECT interview_date FROM interviews WHERE application_id = a.id ORDER BY interview_date DESC LIMIT 1) as last_interview_date,
        (SELECT status FROM interviews WHERE application_id = a.id ORDER BY interview_date DESC LIMIT 1) as last_interview_status,
        (SELECT COUNT(*) FROM messages WHERE application_id = a.id AND receiver_id = ? AND is_read = 0) as unread_messages
    FROM applications a
    INNER JOIN jobs j ON a.job_id = j.id
    INNER JOIN employers e ON j.employer_id = e.id
    WHERE $whereClause
    ORDER BY 
        CASE a.application_status
            WHEN 'interview' THEN 1
            WHEN 'shortlisted' THEN 2
            WHEN 'reviewed' THEN 3
            WHEN 'applied' THEN 4
            WHEN 'offered' THEN 5
            WHEN 'hired' THEN 6
            ELSE 7
        END,
        a.applied_at DESC
    LIMIT ? OFFSET ?
", array_merge([$user_id], $params, [$limit, $offset]));

// Get application statistics for this seeker
$stats = fetchOne("
    SELECT 
        COUNT(*) as total_applications,
        SUM(CASE WHEN application_status = 'applied' THEN 1 ELSE 0 END) as applied_count,
        SUM(CASE WHEN application_status = 'reviewed' THEN 1 ELSE 0 END) as reviewed_count,
        SUM(CASE WHEN application_status = 'shortlisted' THEN 1 ELSE 0 END) as shortlisted_count,
        SUM(CASE WHEN application_status = 'interview' THEN 1 ELSE 0 END) as interview_count,
        SUM(CASE WHEN application_status = 'offered' THEN 1 ELSE 0 END) as offered_count,
        SUM(CASE WHEN application_status = 'hired' THEN 1 ELSE 0 END) as hired_count,
        SUM(CASE WHEN application_status = 'rejected' THEN 1 ELSE 0 END) as rejected_count,
        SUM(CASE WHEN applied_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as applied_this_week,
        AVG(CASE WHEN match_score > 0 THEN match_score ELSE NULL END) as avg_match_score
    FROM applications
    WHERE seeker_id = ?
", [$seeker_id]);

// Get upcoming interviews related to applications
$upcomingInterviews = fetchAll("
    SELECT 
        i.*,
        a.job_id,
        j.title as job_title,
        e.company_name,
        e.company_logo
    FROM interviews i
    INNER JOIN applications a ON i.application_id = a.id
    INNER JOIN jobs j ON a.job_id = j.id
    INNER JOIN employers e ON j.employer_id = e.id
    WHERE a.seeker_id = ? 
        AND i.interview_date > NOW()
        AND i.status IN ('scheduled', 'confirmed')
    ORDER BY i.interview_date ASC
    LIMIT 5
", [$seeker_id]);

// Get recent activity timeline
$recentActivity = fetchAll("
    (SELECT 
        'application' as activity_type,
        a.id as reference_id,
        a.application_status as status,
        j.title as job_title,
        a.applied_at as created_at,
        NULL as interview_date,
        NULL as company_name
    FROM applications a
    INNER JOIN jobs j ON a.job_id = j.id
    WHERE a.seeker_id = ?
    ORDER BY a.applied_at DESC
    LIMIT 10)
    UNION ALL
    (SELECT 
        'interview' as activity_type,
        i.id as reference_id,
        i.status as status,
        j.title as job_title,
        i.created_at as created_at,
        i.interview_date,
        e.company_name
    FROM interviews i
    INNER JOIN applications a ON i.application_id = a.id
    INNER JOIN jobs j ON a.job_id = j.id
    INNER JOIN employers e ON j.employer_id = e.id
    WHERE a.seeker_id = ?
    ORDER BY i.created_at DESC
    LIMIT 10)
    ORDER BY created_at DESC
    LIMIT 15
", [$seeker_id, $seeker_id]);

// Get status colors and icons
function getStatusDetails($status) {
    $details = [
        'applied' => ['color' => 'blue', 'icon' => 'fa-paper-plane', 'label' => 'Applied'],
        'reviewed' => ['color' => 'purple', 'icon' => 'fa-eye', 'label' => 'Under Review'],
        'shortlisted' => ['color' => 'indigo', 'icon' => 'fa-star', 'label' => 'Shortlisted'],
        'interview' => ['color' => 'yellow', 'icon' => 'fa-calendar-check', 'label' => 'Interview'],
        'offered' => ['color' => 'green', 'icon' => 'fa-file-signature', 'label' => 'Offer Received'],
        'hired' => ['color' => 'emerald', 'icon' => 'fa-check-circle', 'label' => 'Hired'],
        'rejected' => ['color' => 'red', 'icon' => 'fa-times-circle', 'label' => 'Not Selected']
    ];
    return $details[$status] ?? ['color' => 'gray', 'icon' => 'fa-circle', 'label' => ucfirst($status)];
}

// Get avatar URL
$avatarUrl = !empty($seekerData['profile_picture']) 
    ? htmlspecialchars($seekerData['profile_picture']) 
    : 'https://ui-avatars.com/api/?name=' . urlencode(($seekerData['first_name'] ?? 'User') . '+' . ($seekerData['last_name'] ?? '')) . '&background=4f46e5&color=fff&size=128&bold=true';
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Applications - TransNow</title>
    
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
        
        @keyframes slideIn {
            from { transform: translateX(20px); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        
        .animate-float {
            animation: float 6s ease-in-out infinite;
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
        
        /* Gradient Text */
        .gradient-text {
            background: linear-gradient(135deg, #4f46e5, #7c3aed, #db2777);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
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
        
        /* Timeline */
        .timeline-item {
            position: relative;
            padding-left: 2rem;
            margin-bottom: 1.5rem;
        }
        
        .timeline-item::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0.25rem;
            width: 1rem;
            height: 1rem;
            border-radius: 50%;
            background: #4f46e5;
        }
        
        .timeline-item::after {
            content: '';
            position: absolute;
            left: 0.45rem;
            top: 1.5rem;
            width: 2px;
            height: calc(100% + 0.5rem);
            background: #e5e7eb;
        }
        
        .timeline-item:last-child::after {
            display: none;
        }
        
        .dark .timeline-item::after {
            background: #374151;
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
                             alt="<?php echo htmlspecialchars(($seekerData['first_name'] ?? 'User') . ' ' . ($seekerData['last_name'] ?? '')); ?>" 
                             class="w-16 h-16 rounded-xl object-cover border-2 border-indigo-200 dark:border-indigo-800">
                        <div class="absolute -bottom-1 -right-1 w-5 h-5 bg-green-500 border-2 border-white dark:border-gray-800 rounded-full animate-pulse"></div>
                    </div>
                    <div class="flex-1 min-w-0">
                        <h3 class="font-bold text-lg truncate"><?php echo htmlspecialchars(($seekerData['first_name'] ?? 'User') . ' ' . ($seekerData['last_name'] ?? '')); ?></h3>
                        <p class="text-sm text-gray-600 dark:text-gray-400 truncate">
                            <?php echo htmlspecialchars($seekerData['headline'] ?? 'Job Seeker'); ?>
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
                <a href="profile.php" class="flex items-center space-x-3 px-4 py-3 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                    <i class="fas fa-user-circle w-5"></i>
                    <span>My Profile</span>
                </a>
                <a href="jobs.php" class="flex items-center space-x-3 px-4 py-3 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                    <i class="fas fa-search w-5"></i>
                    <span>Browse Jobs</span>
                </a>
                <a href="applications.php" class="flex items-center space-x-3 px-4 py-3 bg-indigo-50 dark:bg-indigo-900/20 text-indigo-600 dark:text-indigo-400 rounded-xl font-medium">
                    <i class="fas fa-file-alt w-5"></i>
                    <span>My Applications</span>
                    <?php if (($stats['applied_count'] ?? 0) > 0): ?>
                    <span class="ml-auto bg-blue-500 text-white text-xs px-2 py-1 rounded-full"><?php echo $stats['applied_count']; ?></span>
                    <?php endif; ?>
                </a>
                <a href="saved-jobs.php" class="flex items-center space-x-3 px-4 py-3 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                    <i class="fas fa-bookmark w-5"></i>
                    <span>Saved Jobs</span>
                </a>
                <a href="interviews.php" class="flex items-center space-x-3 px-4 py-3 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                    <i class="fas fa-calendar-alt w-5"></i>
                    <span>Interviews</span>
                    <?php if (!empty($upcomingInterviews)): ?>
                    <span class="ml-auto bg-green-500 text-white text-xs px-2 py-1 rounded-full animate-pulse"><?php echo count($upcomingInterviews); ?></span>
                    <?php endif; ?>
                </a>
                <a href="/messages.php" class="flex items-center space-x-3 px-4 py-3 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                    <i class="fas fa-envelope w-5"></i>
                    <span>Messages</span>
                </a>
                <a href="/notifications.php" class="flex items-center space-x-3 px-4 py-3 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                    <i class="fas fa-bell w-5"></i>
                    <span>Notifications</span>
                </a>
                <div class="border-t border-gray-200 dark:border-gray-700 my-4"></div>
                <a href="/logout.php" class="flex items-center space-x-3 px-4 py-3 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-xl transition">
                    <i class="fas fa-sign-out-alt w-5"></i>
                    <span>Logout</span>
                </a>
            </nav>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="flex-1 overflow-y-auto bg-gray-50 dark:bg-gray-900">
        <!-- Top Navigation -->
        <header class="sticky top-0 z-30 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700">
            <div class="px-6 py-4">
                <div class="flex items-center justify-between">
                    <button id="mobileSidebarToggle" class="lg:hidden w-10 h-10 bg-gray-100 dark:bg-gray-700 rounded-lg flex items-center justify-center hover:bg-gray-200 dark:hover:bg-gray-600 transition">
                        <i class="fas fa-bars"></i>
                    </button>
                    
                    <h1 class="text-2xl font-bold hidden md:block">
                        My <span class="gradient-text">Applications</span>
                    </h1>
                    
                    <div class="flex items-center space-x-4">
                        <a href="jobs.php" class="px-4 py-2 bg-gradient-to-r from-indigo-600 to-purple-600 text-white rounded-xl font-medium hover:shadow-lg transition-all duration-300 flex items-center space-x-2">
                            <i class="fas fa-search"></i>
                            <span>Browse More Jobs</span>
                        </a>
                    </div>
                </div>
            </div>
        </header>

        <!-- Applications Content -->
        <div class="p-6">
            <!-- Stats Cards -->
            <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-4 mb-8">
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-4 text-center hover-card" data-aos="fade-up">
                    <div class="text-2xl font-bold text-indigo-600"><?php echo intval($stats['total_applications'] ?? 0); ?></div>
                    <div class="text-xs text-gray-500">Total</div>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-4 text-center hover-card" data-aos="fade-up" data-aos-delay="20">
                    <div class="text-2xl font-bold text-blue-600"><?php echo intval($stats['applied_count'] ?? 0); ?></div>
                    <div class="text-xs text-gray-500">Applied</div>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-4 text-center hover-card" data-aos="fade-up" data-aos-delay="40">
                    <div class="text-2xl font-bold text-purple-600"><?php echo intval($stats['reviewed_count'] ?? 0); ?></div>
                    <div class="text-xs text-gray-500">Reviewed</div>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-4 text-center hover-card" data-aos="fade-up" data-aos-delay="60">
                    <div class="text-2xl font-bold text-indigo-600"><?php echo intval($stats['shortlisted_count'] ?? 0); ?></div>
                    <div class="text-xs text-gray-500">Shortlisted</div>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-4 text-center hover-card" data-aos="fade-up" data-aos-delay="80">
                    <div class="text-2xl font-bold text-yellow-600"><?php echo intval($stats['interview_count'] ?? 0); ?></div>
                    <div class="text-xs text-gray-500">Interview</div>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-4 text-center hover-card" data-aos="fade-up" data-aos-delay="100">
                    <div class="text-2xl font-bold text-green-600"><?php echo intval($stats['offered_count'] ?? 0); ?></div>
                    <div class="text-xs text-gray-500">Offers</div>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-4 text-center hover-card" data-aos="fade-up" data-aos-delay="120">
                    <div class="text-2xl font-bold text-emerald-600"><?php echo intval($stats['hired_count'] ?? 0); ?></div>
                    <div class="text-xs text-gray-500">Hired</div>
                </div>
            </div>

            <!-- Upcoming Interviews Alert -->
            <?php if (!empty($upcomingInterviews)): ?>
            <div class="mb-8 bg-gradient-to-r from-yellow-400 to-orange-500 rounded-2xl p-6 text-white relative overflow-hidden" data-aos="fade-down">
                <div class="absolute top-0 right-0 w-32 h-32 bg-white/10 rounded-full -mr-16 -mt-16 animate-float"></div>
                <div class="relative z-10 flex items-center justify-between flex-wrap gap-4">
                    <div class="flex items-center space-x-4">
                        <div class="w-16 h-16 bg-white/20 rounded-2xl flex items-center justify-center">
                            <i class="fas fa-calendar-alt text-3xl"></i>
                        </div>
                        <div>
                            <h3 class="text-xl font-bold">Upcoming Interviews</h3>
                            <p class="text-white/90">You have <?php echo count($upcomingInterviews); ?> scheduled interviews</p>
                        </div>
                    </div>
                    <a href="interviews.php" class="px-6 py-3 bg-white text-orange-600 rounded-xl font-semibold hover:shadow-xl transition-all">
                        View Schedule
                    </a>
                </div>
            </div>
            <?php endif; ?>

            <!-- Filters -->
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6 mb-8" data-aos="fade-up">
                <div class="flex flex-wrap items-center gap-4">
                    <span class="text-sm font-medium text-gray-600 dark:text-gray-400">Filter by:</span>
                    <a href="?status=all" class="px-4 py-2 rounded-full text-sm font-medium transition-all <?php echo $statusFilter === 'all' ? 'bg-indigo-600 text-white' : 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-indigo-100 dark:hover:bg-indigo-900/30'; ?>">
                        All (<?php echo intval($stats['total_applications'] ?? 0); ?>)
                    </a>
                    <a href="?status=applied" class="px-4 py-2 rounded-full text-sm font-medium transition-all <?php echo $statusFilter === 'applied' ? 'bg-blue-600 text-white' : 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-blue-100 dark:hover:bg-blue-900/30'; ?>">
                        Applied (<?php echo intval($stats['applied_count'] ?? 0); ?>)
                    </a>
                    <a href="?status=reviewed" class="px-4 py-2 rounded-full text-sm font-medium transition-all <?php echo $statusFilter === 'reviewed' ? 'bg-purple-600 text-white' : 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-purple-100 dark:hover:bg-purple-900/30'; ?>">
                        Reviewed (<?php echo intval($stats['reviewed_count'] ?? 0); ?>)
                    </a>
                    <a href="?status=shortlisted" class="px-4 py-2 rounded-full text-sm font-medium transition-all <?php echo $statusFilter === 'shortlisted' ? 'bg-indigo-600 text-white' : 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-indigo-100 dark:hover:bg-indigo-900/30'; ?>">
                        Shortlisted (<?php echo intval($stats['shortlisted_count'] ?? 0); ?>)
                    </a>
                    <a href="?status=interview" class="px-4 py-2 rounded-full text-sm font-medium transition-all <?php echo $statusFilter === 'interview' ? 'bg-yellow-600 text-white' : 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-yellow-100 dark:hover:bg-yellow-900/30'; ?>">
                        Interview (<?php echo intval($stats['interview_count'] ?? 0); ?>)
                    </a>
                    <a href="?status=offered" class="px-4 py-2 rounded-full text-sm font-medium transition-all <?php echo $statusFilter === 'offered' ? 'bg-green-600 text-white' : 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-green-100 dark:hover:bg-green-900/30'; ?>">
                        Offered (<?php echo intval($stats['offered_count'] ?? 0); ?>)
                    </a>
                </div>
            </div>

            <!-- Applications List -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <!-- Main Applications List -->
                <div class="lg:col-span-2 space-y-6">
                    <?php if (empty($applications)): ?>
                    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-12 text-center" data-aos="fade-up">
                        <div class="w-24 h-24 bg-gray-100 dark:bg-gray-700 rounded-full flex items-center justify-center mx-auto mb-6">
                            <i class="fas fa-file-alt text-4xl text-gray-400"></i>
                        </div>
                        <h3 class="text-2xl font-bold mb-4">No Applications Found</h3>
                        <p class="text-gray-600 dark:text-gray-400 mb-8">
                            <?php echo $statusFilter !== 'all' ? 'No applications with status "' . ucfirst($statusFilter) . '"' : "You haven't applied to any jobs yet."; ?>
                        </p>
                        <a href="jobs.php" class="inline-flex items-center px-6 py-3 bg-gradient-to-r from-indigo-600 to-purple-600 text-white rounded-xl font-semibold hover:shadow-xl transition-all">
                            <i class="fas fa-search mr-2"></i>
                            Browse Jobs
                        </a>
                    </div>
                    <?php else: ?>
                        <?php foreach ($applications as $index => $app): 
                            $statusDetails = getStatusDetails($app['application_status']);
                        ?>
                        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl overflow-hidden hover-card" data-aos="fade-up" data-aos-delay="<?php echo min($index * 30, 300); ?>">
                            <div class="p-6">
                                <div class="flex items-start justify-between mb-4">
                                    <div class="flex items-start space-x-4">
                                        <img src="<?php echo !empty($app['company_logo']) ? htmlspecialchars($app['company_logo']) : 'https://ui-avatars.com/api/?name=' . urlencode($app['company_name'] ?? 'Company') . '&background=4f46e5&color=fff'; ?>" 
                                             alt="<?php echo htmlspecialchars($app['company_name'] ?? 'Company'); ?>"
                                             class="w-16 h-16 rounded-xl object-cover border-2 border-gray-200 dark:border-gray-700">
                                        <div>
                                            <h3 class="text-xl font-bold mb-2">
                                                <a href="job-details.php?slug=<?php echo htmlspecialchars($app['job_slug'] ?? ''); ?>" class="hover:text-indigo-600 transition">
                                                    <?php echo htmlspecialchars($app['job_title'] ?? 'Job Position'); ?>
                                                </a>
                                            </h3>
                                            <p class="text-gray-600 dark:text-gray-400 mb-2 flex items-center flex-wrap gap-2">
                                                <span class="flex items-center">
                                                    <i class="fas fa-building mr-1 text-indigo-500"></i>
                                                    <?php echo htmlspecialchars($app['company_name'] ?? 'Company'); ?>
                                                    <?php if (!empty($app['employer_verified'])): ?>
                                                    <i class="fas fa-check-circle text-blue-500 ml-1 text-xs" title="Verified Employer"></i>
                                                    <?php endif; ?>
                                                </span>
                                                <span class="text-gray-300 dark:text-gray-600">•</span>
                                                <span class="flex items-center">
                                                    <i class="fas fa-map-marker-alt mr-1 text-indigo-500"></i>
                                                    <?php 
                                                    if (!empty($app['job_city'])) {
                                                        echo htmlspecialchars($app['job_city']);
                                                    } else {
                                                        echo ucfirst($app['location_type'] ?? 'Remote');
                                                    }
                                                    ?>
                                                </span>
                                            </p>
                                        </div>
                                    </div>
                                    <span class="status-badge status-<?php echo $app['application_status']; ?>">
                                        <i class="fas <?php echo $statusDetails['icon']; ?> mr-1"></i>
                                        <?php echo $statusDetails['label']; ?>
                                    </span>
                                </div>

                                <!-- Application Details Grid -->
                                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-4">
                                    <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg p-3">
                                        <div class="text-xs text-gray-500 mb-1">Applied</div>
                                        <div class="font-semibold flex items-center">
                                            <i class="far fa-calendar mr-1 text-indigo-500"></i>
                                            <?php echo isset($app['applied_at']) ? date('M j, Y', strtotime($app['applied_at'])) : 'N/A'; ?>
                                        </div>
                                        <div class="text-xs text-gray-400"><?php echo isset($app['applied_at']) ? timeAgo($app['applied_at']) : ''; ?></div>
                                    </div>
                                    
                                    <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg p-3">
                                        <div class="text-xs text-gray-500 mb-1">Match Score</div>
                                        <div class="font-semibold flex items-center">
                                            <i class="fas fa-chart-line mr-1 <?php echo ($app['match_score'] ?? 0) >= 80 ? 'text-green-500' : (($app['match_score'] ?? 0) >= 60 ? 'text-yellow-500' : 'text-gray-500'); ?>"></i>
                                            <?php echo isset($app['match_score']) ? number_format($app['match_score'], 1) . '%' : 'N/A'; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg p-3">
                                        <div class="text-xs text-gray-500 mb-1">Expected Salary</div>
                                        <div class="font-semibold flex items-center">
                                            <i class="fas fa-dollar-sign mr-1 text-green-500"></i>
                                            <?php 
                                            if (!empty($app['expected_salary'])) {
                                                echo number_format($app['expected_salary']);
                                            } else {
                                                echo 'Not specified';
                                            }
                                            ?>
                                        </div>
                                    </div>
                                    
                                    <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg p-3">
                                        <div class="text-xs text-gray-500 mb-1">Notice Period</div>
                                        <div class="font-semibold flex items-center">
                                            <i class="fas fa-hourglass-half mr-1 text-yellow-500"></i>
                                            <?php echo $app['notice_period_days'] ?? 30; ?> days
                                        </div>
                                    </div>
                                </div>

                                <!-- Interview Info if any -->
                                <?php if (!empty($app['last_interview_date'])): ?>
                                <div class="mb-4 p-3 bg-yellow-50 dark:bg-yellow-900/20 rounded-lg border border-yellow-200 dark:border-yellow-800">
                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center space-x-3">
                                            <i class="fas fa-calendar-check text-yellow-600"></i>
                                            <span class="text-sm font-medium">Last Interview: <?php echo date('M j, Y g:i A', strtotime($app['last_interview_date'])); ?></span>
                                        </div>
                                        <?php if ($app['unread_messages'] > 0): ?>
                                        <span class="bg-red-500 text-white text-xs px-2 py-1 rounded-full animate-pulse">
                                            <?php echo $app['unread_messages']; ?> new message<?php echo $app['unread_messages'] > 1 ? 's' : ''; ?>
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <!-- Cover Letter Preview -->
                                <?php if (!empty($app['cover_letter'])): ?>
                                <div class="mb-4 p-3 bg-gray-50 dark:bg-gray-700/50 rounded-lg">
                                    <p class="text-sm text-gray-600 dark:text-gray-400 line-clamp-2">
                                        <i class="fas fa-quote-left mr-2 text-indigo-500"></i>
                                        <?php echo htmlspecialchars(substr($app['cover_letter'], 0, 200)) . '...'; ?>
                                    </p>
                                </div>
                                <?php endif; ?>

                                <!-- Action Buttons -->
                                <div class="flex flex-wrap gap-3 mt-4">
                                    <a href="application-details.php?id=<?php echo $app['id']; ?>" 
                                       class="flex-1 px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition text-center">
                                        <i class="fas fa-eye mr-2"></i>View Details
                                    </a>
                                    <a href="/messages.php?application=<?php echo $app['id']; ?>" 
                                       class="flex-1 px-4 py-2 border border-indigo-600 text-indigo-600 dark:text-indigo-400 rounded-lg hover:bg-indigo-50 dark:hover:bg-indigo-900/20 transition text-center relative">
                                        <i class="fas fa-envelope mr-2"></i>Messages
                                        <?php if ($app['unread_messages'] > 0): ?>
                                        <span class="absolute -top-2 -right-2 w-5 h-5 bg-red-500 text-white text-xs rounded-full flex items-center justify-center">
                                            <?php echo $app['unread_messages']; ?>
                                        </span>
                                        <?php endif; ?>
                                    </a>
                                    <?php if ($app['application_status'] === 'interview' && !empty($app['last_interview_date'])): ?>
                                    <a href="interview-details.php?application=<?php echo $app['id']; ?>" 
                                       class="px-4 py-2 border border-green-600 text-green-600 rounded-lg hover:bg-green-50 dark:hover:bg-green-900/20 transition">
                                        <i class="fas fa-calendar-alt"></i>
                                    </a>
                                    <?php endif; ?>
                                    <?php if ($app['application_status'] === 'offered'): ?>
                                    <button onclick="respondToOffer(<?php echo $app['id']; ?>)" 
                                            class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition">
                                        <i class="fas fa-check mr-2"></i>Respond
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <!-- Progress Bar for Application Journey -->
                            <div class="bg-gray-100 dark:bg-gray-700 px-6 py-3 border-t dark:border-gray-600">
                                <div class="flex items-center justify-between text-xs text-gray-600 dark:text-gray-400">
                                    <span class="flex items-center <?php echo in_array($app['application_status'], ['applied', 'reviewed', 'shortlisted', 'interview', 'offered', 'hired']) ? 'text-indigo-600 font-semibold' : ''; ?>">
                                        <i class="fas fa-check-circle mr-1"></i> Applied
                                    </span>
                                    <span class="flex-1 mx-2 h-0.5 bg-gray-300 dark:bg-gray-600"></span>
                                    <span class="flex items-center <?php echo in_array($app['application_status'], ['reviewed', 'shortlisted', 'interview', 'offered', 'hired']) ? 'text-indigo-600 font-semibold' : ''; ?>">
                                        <i class="fas fa-eye mr-1"></i> Reviewed
                                    </span>
                                    <span class="flex-1 mx-2 h-0.5 bg-gray-300 dark:bg-gray-600"></span>
                                    <span class="flex items-center <?php echo in_array($app['application_status'], ['shortlisted', 'interview', 'offered', 'hired']) ? 'text-indigo-600 font-semibold' : ''; ?>">
                                        <i class="fas fa-star mr-1"></i> Shortlisted
                                    </span>
                                    <span class="flex-1 mx-2 h-0.5 bg-gray-300 dark:bg-gray-600"></span>
                                    <span class="flex items-center <?php echo in_array($app['application_status'], ['interview', 'offered', 'hired']) ? 'text-indigo-600 font-semibold' : ''; ?>">
                                        <i class="fas fa-calendar-check mr-1"></i> Interview
                                    </span>
                                    <span class="flex-1 mx-2 h-0.5 bg-gray-300 dark:bg-gray-600"></span>
                                    <span class="flex items-center <?php echo in_array($app['application_status'], ['offered', 'hired']) ? 'text-green-600 font-semibold' : ''; ?>">
                                        <i class="fas fa-gift mr-1"></i> Offer
                                    </span>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>

                        <!-- Pagination -->
                        <?php if ($totalPages > 1): ?>
                        <div class="flex justify-center space-x-2 mt-8">
                            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                            <a href="?status=<?php echo $statusFilter; ?>&page=<?php echo $i; ?>" 
                               class="w-10 h-10 flex items-center justify-center rounded-lg transition-all <?php echo $page == $i ? 'bg-indigo-600 text-white' : 'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-indigo-100 dark:hover:bg-indigo-900/30'; ?>">
                                <?php echo $i; ?>
                            </a>
                            <?php endfor; ?>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <!-- Sidebar - Activity Timeline & Insights -->
                <div class="space-y-6">
                    <!-- Quick Stats Card -->
                    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6" data-aos="fade-up">
                        <h3 class="text-lg font-bold mb-4 flex items-center">
                            <i class="fas fa-chart-pie mr-2 text-indigo-600"></i>
                            Application Insights
                        </h3>
                        
                        <div class="space-y-4">
                            <div>
                                <div class="flex justify-between text-sm mb-1">
                                    <span class="text-gray-600 dark:text-gray-400">Success Rate</span>
                                    <span class="font-semibold">
                                        <?php 
                                        $total = intval($stats['total_applications'] ?? 0);
                                        $positive = intval($stats['interview_count'] ?? 0) + intval($stats['offered_count'] ?? 0) + intval($stats['hired_count'] ?? 0);
                                        $rate = $total > 0 ? round(($positive / $total) * 100) : 0;
                                        echo $rate . '%';
                                        ?>
                                    </span>
                                </div>
                                <div class="w-full h-2 bg-gray-200 dark:bg-gray-700 rounded-full overflow-hidden">
                                    <div class="progress-bar h-full rounded-full" style="width: <?php echo $rate; ?>%"></div>
                                </div>
                            </div>
                            
                            <div>
                                <div class="flex justify-between text-sm mb-1">
                                    <span class="text-gray-600 dark:text-gray-400">Interview Rate</span>
                                    <span class="font-semibold">
                                        <?php 
                                        $interviewRate = $total > 0 ? round((intval($stats['interview_count'] ?? 0) / $total) * 100) : 0;
                                        echo $interviewRate . '%';
                                        ?>
                                    </span>
                                </div>
                                <div class="w-full h-2 bg-gray-200 dark:bg-gray-700 rounded-full overflow-hidden">
                                    <div class="bg-yellow-500 h-full rounded-full" style="width: <?php echo $interviewRate; ?>%"></div>
                                </div>
                            </div>
                            
                            <div>
                                <div class="flex justify-between text-sm mb-1">
                                    <span class="text-gray-600 dark:text-gray-400">Average Match Score</span>
                                    <span class="font-semibold">
                                        <?php echo isset($stats['avg_match_score']) ? number_format($stats['avg_match_score'], 1) . '%' : 'N/A'; ?>
                                    </span>
                                </div>
                                <div class="w-full h-2 bg-gray-200 dark:bg-gray-700 rounded-full overflow-hidden">
                                    <div class="bg-green-500 h-full rounded-full" style="width: <?php echo $stats['avg_match_score'] ?? 0; ?>%"></div>
                                </div>
                            </div>
                            
                            <div class="pt-4 border-t dark:border-gray-700">
                                <div class="flex justify-between items-center">
                                    <span class="text-sm text-gray-600 dark:text-gray-400">Applied this week</span>
                                    <span class="text-2xl font-bold text-indigo-600"><?php echo intval($stats['applied_this_week'] ?? 0); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Upcoming Interviews (Compact) -->
                    <?php if (!empty($upcomingInterviews)): ?>
                    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6" data-aos="fade-up">
                        <h3 class="text-lg font-bold mb-4 flex items-center">
                            <i class="fas fa-calendar-alt mr-2 text-yellow-600"></i>
                            Upcoming Interviews
                        </h3>
                        
                        <div class="space-y-4">
                            <?php foreach ($upcomingInterviews as $interview): ?>
                            <div class="flex items-start space-x-3 p-3 bg-yellow-50 dark:bg-yellow-900/20 rounded-lg">
                                <div class="w-10 h-10 bg-gradient-to-r from-yellow-500 to-orange-500 rounded-lg flex items-center justify-center text-white flex-shrink-0">
                                    <i class="fas fa-video"></i>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="font-semibold text-sm truncate"><?php echo htmlspecialchars($interview['job_title'] ?? 'Interview'); ?></p>
                                    <p class="text-xs text-gray-500"><?php echo htmlspecialchars($interview['company_name'] ?? ''); ?></p>
                                    <p class="text-xs text-yellow-600 mt-1">
                                        <i class="far fa-clock mr-1"></i>
                                        <?php echo date('M j, g:i A', strtotime($interview['interview_date'])); ?>
                                    </p>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Recent Activity Timeline -->
                    <?php if (!empty($recentActivity)): ?>
                    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6" data-aos="fade-up">
                        <h3 class="text-lg font-bold mb-4 flex items-center">
                            <i class="fas fa-history mr-2 text-indigo-600"></i>
                            Recent Activity
                        </h3>
                        
                        <div class="space-y-4">
                            <?php foreach ($recentActivity as $activity): ?>
                            <div class="timeline-item">
                                <?php if ($activity['activity_type'] === 'application'): ?>
                                <div class="flex items-start">
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm font-semibold">Applied to <?php echo htmlspecialchars($activity['job_title'] ?? 'Job'); ?></p>
                                        <p class="text-xs text-gray-500 flex items-center mt-1">
                                            <i class="far fa-clock mr-1"></i>
                                            <?php echo timeAgo($activity['created_at']); ?>
                                        </p>
                                    </div>
                                    <span class="status-badge status-<?php echo $activity['status']; ?> text-xs py-1 px-2">
                                        <?php echo ucfirst($activity['status']); ?>
                                    </span>
                                </div>
                                <?php else: ?>
                                <div class="flex items-start">
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm font-semibold">Interview for <?php echo htmlspecialchars($activity['job_title'] ?? 'Job'); ?></p>
                                        <p class="text-xs text-gray-500 flex items-center mt-1">
                                            <i class="fas fa-building mr-1"></i>
                                            <?php echo htmlspecialchars($activity['company_name'] ?? ''); ?>
                                        </p>
                                        <p class="text-xs text-yellow-600 mt-1">
                                            <i class="far fa-calendar mr-1"></i>
                                            <?php echo date('M j, Y', strtotime($activity['interview_date'])); ?>
                                        </p>
                                    </div>
                                    <span class="text-xs text-gray-400"><?php echo timeAgo($activity['created_at']); ?></span>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Tips Card -->
                    <div class="bg-gradient-to-r from-indigo-500 to-purple-600 rounded-2xl shadow-xl p-6 text-white" data-aos="fade-up">
                        <h3 class="text-lg font-bold mb-3 flex items-center">
                            <i class="fas fa-lightbulb mr-2"></i>
                            Pro Tips
                        </h3>
                        <ul class="space-y-3 text-sm">
                            <li class="flex items-start">
                                <i class="fas fa-check-circle mt-1 mr-2 flex-shrink-0"></i>
                                <span>Follow up on applications after 1-2 weeks</span>
                            </li>
                            <li class="flex items-start">
                                <i class="fas fa-check-circle mt-1 mr-2 flex-shrink-0"></i>
                                <span>Prepare for interviews by researching the company</span>
                            </li>
                            <li class="flex items-start">
                                <i class="fas fa-check-circle mt-1 mr-2 flex-shrink-0"></i>
                                <span>Keep your profile updated for better matches</span>
                            </li>
                            <li class="flex items-start">
                                <i class="fas fa-check-circle mt-1 mr-2 flex-shrink-0"></i>
                                <span>Respond to messages promptly</span>
                            </li>
                        </ul>
                    </div>
                </div>
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
                        <p class="font-semibold truncate"><?php echo htmlspecialchars(($seekerData['first_name'] ?? 'User') . ' ' . ($seekerData['last_name'] ?? '')); ?></p>
                        <p class="text-sm text-gray-500 truncate"><?php echo htmlspecialchars($seekerData['headline'] ?? 'Job Seeker'); ?></p>
                    </div>
                </div>
                
                <nav class="space-y-1">
                    <a href="dashboard.php" class="block px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">Dashboard</a>
                    <a href="profile.php" class="block px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">My Profile</a>
                    <a href="jobs.php" class="block px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">Browse Jobs</a>
                    <a href="applications.php" class="block px-4 py-2 bg-indigo-50 dark:bg-indigo-900/20 text-indigo-600 rounded-lg">My Applications</a>
                    <a href="saved-jobs.php" class="block px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">Saved Jobs</a>
                    <a href="interviews.php" class="block px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">Interviews</a>
                    <a href="/messages.php" class="block px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">Messages</a>
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

// Respond to Offer Function
function respondToOffer(applicationId) {
    Swal.fire({
        title: 'Respond to Offer',
        text: 'How would you like to respond to this offer?',
        icon: 'question',
        showCancelButton: true,
        showDenyButton: true,
        confirmButtonText: 'Accept',
        denyButtonText: 'Decline',
        cancelButtonText: 'Think Later',
        confirmButtonColor: '#10b981',
        denyButtonColor: '#ef4444',
        cancelButtonColor: '#6b7280'
    }).then((result) => {
        if (result.isConfirmed) {
            // Handle accept
            window.location.href = `respond-offer.php?id=${applicationId}&action=accept`;
        } else if (result.isDenied) {
            // Handle decline
            window.location.href = `respond-offer.php?id=${applicationId}&action=decline`;
        }
    });
}

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
    // Escape to close mobile sidebar
    if (e.key === 'Escape') {
        mobileSidebar?.classList.add('hidden');
    }
});

// Confirm withdraw application
function withdrawApplication(applicationId) {
    Swal.fire({
        title: 'Withdraw Application?',
        text: 'Are you sure you want to withdraw this application? This action cannot be undone.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        cancelButtonColor: '#6b7280',
        confirmButtonText: 'Yes, withdraw'
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = `withdraw-application.php?id=${applicationId}`;
        }
    });
}
</script>
</body>
</html>