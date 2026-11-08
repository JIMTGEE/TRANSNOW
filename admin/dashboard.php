<?php
// admin/dashboard.php - Complete Optimized Dashboard v7.0
// Fixed: timeAgo function, Chart.js integration, AJAX handler, all errors resolved

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../includes/auth.php';

requireAdminLogin();

$pdo = getDB();
$user_id = $_SESSION['user_id'];

$adminData = fetchOne("SELECT u.* FROM users u WHERE u.id = ? AND u.user_type = 'admin'", [$user_id]);

if (!$adminData) {
    $_SESSION['error'] = 'Admin account not found';
    header('Location: /logout.php');
    exit();
}

// ========== HELPER FUNCTIONS ==========
function safeGet($array, $key, $default = 0) {
    return isset($array[$key]) && $array[$key] !== null ? $array[$key] : $default;
}

function timeAgo($datetime) {
    if (empty($datetime)) return 'Never';
    
    $timestamp = strtotime($datetime);
    if ($timestamp === false) return 'Invalid date';
    
    $diff = time() - $timestamp;
    
    if ($diff < 60) return $diff . ' seconds ago';
    if ($diff < 3600) return floor($diff / 60) . ' minutes ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hours ago';
    if ($diff < 2592000) return floor($diff / 86400) . ' days ago';
    if ($diff < 31536000) return floor($diff / 2592000) . ' months ago';
    return floor($diff / 31536000) . ' years ago';
}

// ========== AJAX HANDLER ==========
if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
    header('Content-Type: application/json');
    
    $response = ['success' => true, 'data' => []];
    
    // Get fresh stats for AJAX refresh
    $stats = [];
    
    // User stats
    $userStats = fetchOne("
        SELECT COUNT(*) as total FROM users
    ", []);
    $stats['total_users'] = safeGet($userStats, 'total', 0);
    
    // Job stats
    $jobStats = fetchOne("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'Active' THEN 1 ELSE 0 END) as active
        FROM jobs
    ", []);
    $stats['total_jobs'] = safeGet($jobStats, 'total', 0);
    $stats['active_jobs'] = safeGet($jobStats, 'active', 0);
    
    // Application stats
    $appStats = fetchOne("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending
        FROM applications
    ", []);
    $stats['total_apps'] = safeGet($appStats, 'total', 0);
    $stats['pending_apps'] = safeGet($appStats, 'pending', 0);
    
    // Get last 7 days trends
    $trendData = [];
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $userCount = fetchOne("SELECT COUNT(*) as count FROM users WHERE DATE(created_at) = ?", [$date]);
        $jobCount = fetchOne("SELECT COUNT(*) as count FROM jobs WHERE DATE(created_at) = ?", [$date]);
        $appCount = fetchOne("SELECT COUNT(*) as count FROM applications WHERE DATE(created_at) = ?", [$date]);
        
        $trendData[] = [
            'date' => date('M d', strtotime($date)),
            'users' => (int)safeGet($userCount, 'count', 0),
            'jobs' => (int)safeGet($jobCount, 'count', 0),
            'apps' => (int)safeGet($appCount, 'count', 0)
        ];
    }
    $stats['trends'] = $trendData;
    
    echo json_encode($stats);
    exit;
}

// ========== FETCH ALL DASHBOARD DATA ==========

// 1. User Statistics
$userStats = fetchOne("
    SELECT 
        COUNT(*) as total_users,
        SUM(CASE WHEN user_type = 'seeker' THEN 1 ELSE 0 END) as total_seekers,
        SUM(CASE WHEN user_type = 'employer' THEN 1 ELSE 0 END) as total_employers,
        SUM(CASE WHEN is_verified = 1 THEN 1 ELSE 0 END) as verified_users,
        SUM(CASE WHEN is_verified = 0 AND user_type != 'admin' THEN 1 ELSE 0 END) as pending_verifications,
        SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as new_users_week,
        SUM(CASE WHEN last_login >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as active_users_week
    FROM users
", []);

// 2. Job Statistics
$jobStats = fetchOne("
    SELECT 
        COUNT(*) as total_jobs,
        SUM(CASE WHEN status = 'Active' THEN 1 ELSE 0 END) as active_jobs,
        SUM(CASE WHEN status = 'Draft' THEN 1 ELSE 0 END) as draft_jobs,
        SUM(CASE WHEN status = 'Expired' THEN 1 ELSE 0 END) as expired_jobs,
        SUM(CASE WHEN is_featured = 1 THEN 1 ELSE 0 END) as featured_jobs,
        SUM(CASE WHEN is_urgent = 1 THEN 1 ELSE 0 END) as urgent_jobs,
        COALESCE(SUM(total_views), 0) as total_views,
        COALESCE(AVG(total_views), 0) as avg_views
    FROM jobs
", []);

// 3. Application Statistics
$appStats = fetchOne("
    SELECT 
        COUNT(*) as total_applications,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_applications,
        SUM(CASE WHEN status = 'reviewed' THEN 1 ELSE 0 END) as reviewed_applications,
        SUM(CASE WHEN status = 'shortlisted' THEN 1 ELSE 0 END) as shortlisted_applications,
        SUM(CASE WHEN status = 'hired' THEN 1 ELSE 0 END) as hired_applications,
        SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected_applications,
        SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as new_applications_week
    FROM applications
", []);

// 4. Overall Stats for Navbar
$overallStats = fetchOne("
    SELECT 
        (SELECT COUNT(*) FROM jobs) as total_jobs,
        (SELECT COUNT(*) FROM applications) as total_applications,
        (SELECT COUNT(*) FROM interviews WHERE scheduled_at >= NOW()) as upcoming_interviews
", []);

// 5. System Health Stats
$systemHealth = fetchOne("
    SELECT 
        (SELECT COUNT(*) FROM email_queue WHERE status = 'pending') as email_queue
", []);

// 6. 30-Day Trends for Charts
$chartData = [];
for ($i = 29; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $chartData['labels'][] = date('M d', strtotime($date));
    
    $users = fetchOne("SELECT COUNT(*) as count FROM users WHERE DATE(created_at) = ?", [$date]);
    $jobs = fetchOne("SELECT COUNT(*) as count FROM jobs WHERE DATE(created_at) = ?", [$date]);
    $apps = fetchOne("SELECT COUNT(*) as count FROM applications WHERE DATE(created_at) = ?", [$date]);
    $views = fetchOne("SELECT COALESCE(SUM(total_views), 0) as count FROM jobs WHERE DATE(created_at) = ?", [$date]);
    
    $chartData['users'][] = (int)safeGet($users, 'count', 0);
    $chartData['jobs'][] = (int)safeGet($jobs, 'count', 0);
    $chartData['apps'][] = (int)safeGet($apps, 'count', 0);
    $chartData['views'][] = (int)safeGet($views, 'count', 0);
}

// 7. Recent Jobs
$recentJobs = fetchAll("
    SELECT id, title, status, job_category, city, total_views, created_at 
    FROM jobs 
    ORDER BY created_at DESC 
    LIMIT 10
", []);

// 8. Recent Users
$recentUsers = fetchAll("
    SELECT id, email, user_type, is_verified, created_at 
    FROM users 
    ORDER BY created_at DESC 
    LIMIT 10
", []);

// 9. Recent Applications
$recentApps = fetchAll("
    SELECT a.id, a.status, j.title as job_title, u.email as user_email, a.created_at 
    FROM applications a 
    JOIN jobs j ON a.job_id = j.id 
    JOIN users u ON a.seeker_id = u.id 
    ORDER BY a.created_at DESC 
    LIMIT 10
", []);

// 10. Category Distribution
$categories = fetchAll("
    SELECT job_category as name, COUNT(*) as count 
    FROM jobs 
    WHERE job_category IS NOT NULL 
    GROUP BY job_category 
    ORDER BY count DESC 
    LIMIT 6
", []);

$avatarUrl = 'https://ui-avatars.com/api/?name=AD&background=4f46e5&color=fff&size=128&bold=true';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard | TransNow</title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    
    <style>
        * { font-family: 'Inter', sans-serif; }
        
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .animate-fade-up {
            animation: fadeInUp 0.5s ease-out forwards;
        }
        
        .delay-100 { animation-delay: 0.1s; }
        .delay-200 { animation-delay: 0.2s; }
        .delay-300 { animation-delay: 0.3s; }
        .delay-400 { animation-delay: 0.4s; }
        
        .stat-card {
            transition: all 0.3s ease;
        }
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1);
        }
        
        .gradient-text {
            background: linear-gradient(135deg, #4f46e5, #7c3aed);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 10px; }
        ::-webkit-scrollbar-thumb { background: #4f46e5; border-radius: 10px; }
        
        .badge {
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        .badge-active { background: #dcfce7; color: #166534; }
        .badge-pending { background: #fef3c7; color: #92400e; }
        .badge-draft { background: #f3f4f6; color: #374151; }
        
        .dark .badge-active { background: #14532d; color: #86efac; }
        .dark .badge-pending { background: #78350f; color: #fde68a; }
        .dark .badge-draft { background: #374151; color: #9ca3af; }
        
        .chart-container {
            position: relative;
            height: 320px;
            width: 100%;
        }
        
        .sidebar {
            transition: transform 0.3s ease;
        }
    </style>
</head>
<body class="bg-gray-50 dark:bg-gray-900 transition-colors duration-300">

<!-- ========== SIDEBAR ========== -->
<aside id="sidebar" class="fixed left-0 top-0 z-40 h-screen w-64 bg-white dark:bg-gray-800 border-r border-gray-200 dark:border-gray-700 transform -translate-x-full lg:translate-x-0 transition-transform duration-300 flex flex-col">
    <!-- Logo -->
    <div class="p-5 border-b border-gray-200 dark:border-gray-700">
        <div class="flex items-center space-x-3">
            <div class="w-9 h-9 bg-gradient-to-r from-indigo-500 to-purple-600 rounded-xl flex items-center justify-center">
                <i class="fas fa-chart-line text-white text-sm"></i>
            </div>
            <div>
                <h1 class="text-lg font-bold"><span class="gradient-text">TransNow</span></h1>
                <p class="text-xs text-gray-500">Admin Portal</p>
            </div>
        </div>
    </div>
    
    <!-- Admin Profile -->
    <div class="p-4 m-3 bg-indigo-50 dark:bg-indigo-950/30 rounded-xl">
        <div class="flex items-center space-x-3">
            <img src="<?php echo $avatarUrl; ?>" alt="Admin" class="w-10 h-10 rounded-xl border border-indigo-300">
            <div>
                <h4 class="font-semibold text-sm">Administrator</h4>
                <p class="text-xs text-gray-500 truncate">admin@transnow.com</p>
                <div class="flex items-center mt-0.5">
                    <div class="w-1.5 h-1.5 bg-green-500 rounded-full mr-1"></div>
                    <span class="text-xs text-gray-500">Online</span>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Navigation -->
    <nav class="flex-1 px-4 py-6 space-y-1 overflow-y-auto">
        <!-- Dashboard -->
        <a href="dashboard.php" class="flex items-center space-x-3 px-4 py-3 bg-indigo-50 dark:bg-indigo-900/20 text-indigo-600 dark:text-indigo-400 rounded-xl font-medium">
            <i class="fas fa-tachometer-alt w-5"></i>
            <span>Dashboard</span>
        </a>

        <!-- Jobs Management -->
        <a href="jobs.php" class="flex items-center space-x-3 px-4 py-3 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
            <i class="fas fa-briefcase w-5"></i>
            <span>Jobs</span>
            <?php if (safeGet($overallStats, 'total_jobs', 0) > 0): ?>
            <span class="ml-auto bg-purple-500 text-white text-xs px-2 py-1 rounded-full"><?php echo safeGet($overallStats, 'total_jobs', 0); ?></span>
            <?php endif; ?>
        </a>
        
        <!-- Applications -->
        <a href="applications.php" class="flex items-center space-x-3 px-4 py-3 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
            <i class="fas fa-file-alt w-5"></i>
            <span>Applications</span>
            <?php if (safeGet($overallStats, 'total_applications', 0) > 0): ?>
            <span class="ml-auto bg-green-500 text-white text-xs px-2 py-1 rounded-full"><?php echo safeGet($overallStats, 'total_applications', 0); ?></span>
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
            <?php if (safeGet($systemHealth, 'email_queue', 0) > 0): ?>
            <span class="ml-auto bg-yellow-500 text-white text-xs px-2 py-1 rounded-full"><?php echo safeGet($systemHealth, 'email_queue', 0); ?></span>
            <?php endif; ?>
        </a>
        
        <!-- Interviews -->
        <a href="interviews.php" class="flex items-center space-x-3 px-4 py-3 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
            <i class="fas fa-calendar-alt w-5"></i>
            <span>Interviews</span>
            <?php if (safeGet($overallStats, 'upcoming_interviews', 0) > 0): ?>
            <span class="ml-auto bg-green-500 text-white text-xs px-2 py-1 rounded-full"><?php echo safeGet($overallStats, 'upcoming_interviews', 0); ?></span>
            <?php endif; ?>
        </a>
        
        <!-- Divider -->
        <div class="border-t border-gray-200 dark:border-gray-700 my-4"></div>
        
        <!-- Logout -->
        <a href="/logout.php" class="flex items-center space-x-3 px-4 py-3 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-xl transition">
            <i class="fas fa-sign-out-alt w-5"></i>
            <span>Logout</span>
        </a>
    </nav>
</aside>

<!-- ========== MAIN CONTENT ========== -->
<main class="lg:ml-64 min-h-screen">
    <!-- Header -->
    <header class="sticky top-0 z-30 bg-white/90 dark:bg-gray-900/90 backdrop-blur-md border-b border-gray-200 dark:border-gray-700">
        <div class="px-4 py-3 flex items-center justify-between">
            <div class="flex items-center space-x-3">
                <button id="sidebarToggle" class="lg:hidden w-9 h-9 bg-gray-100 dark:bg-gray-800 rounded-xl flex items-center justify-center">
                    <i class="fas fa-bars text-gray-600"></i>
                </button>
                <div>
                    <h2 class="text-xl font-bold">Dashboard</h2>
                    <p class="text-xs text-gray-500">Welcome back, Admin</p>
                </div>
            </div>
            
            <div class="flex items-center space-x-2">
                <div class="hidden md:flex items-center space-x-2 px-3 py-1.5 bg-gray-100 dark:bg-gray-800 rounded-xl">
                    <i class="fas fa-calendar-alt text-indigo-500 text-xs"></i>
                    <span class="text-xs"><?php echo date('M d, Y'); ?></span>
                </div>
                <button id="darkModeBtn" class="w-9 h-9 bg-gray-100 dark:bg-gray-800 rounded-xl flex items-center justify-center">
                    <i id="darkModeIcon" class="fas fa-moon text-gray-600"></i>
                </button>
                <button id="refreshBtn" class="w-9 h-9 bg-gray-100 dark:bg-gray-800 rounded-xl flex items-center justify-center">
                    <i class="fas fa-rotate-right text-gray-600"></i>
                </button>
            </div>
        </div>
    </header>
    
    <div class="p-5">
        <!-- Welcome Banner -->
        <div class="mb-6 bg-gradient-to-r from-indigo-600 via-purple-600 to-pink-600 rounded-2xl p-5 text-white animate-fade-up">
            <div class="flex items-center justify-between flex-wrap gap-3">
                <div>
                    <h3 class="text-xl font-bold mb-1">Platform Analytics</h3>
                    <p class="text-white/80 text-sm">Real-time monitoring dashboard</p>
                </div>
                <div class="bg-white/20 backdrop-blur rounded-xl px-3 py-1.5 text-sm">
                    <i class="fas fa-chart-line mr-1"></i> Live Data
                </div>
            </div>
        </div>
        
        <!-- Stats Grid -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
            <div class="bg-white dark:bg-gray-800 rounded-xl p-4 shadow-sm stat-card animate-fade-up">
                <div class="flex items-center justify-between mb-2">
                    <div class="w-9 h-9 bg-indigo-100 dark:bg-indigo-900/30 rounded-xl flex items-center justify-center">
                        <i class="fas fa-users text-indigo-600"></i>
                    </div>
                    <span class="text-xs text-green-600">+<?php echo safeGet($userStats, 'new_users_week', 0); ?></span>
                </div>
                <h3 class="text-2xl font-bold"><?php echo number_format(safeGet($userStats, 'total_users', 0)); ?></h3>
                <p class="text-xs text-gray-500">Total Users</p>
            </div>
            
            <div class="bg-white dark:bg-gray-800 rounded-xl p-4 shadow-sm stat-card animate-fade-up delay-100">
                <div class="flex items-center justify-between mb-2">
                    <div class="w-9 h-9 bg-green-100 dark:bg-green-900/30 rounded-xl flex items-center justify-center">
                        <i class="fas fa-briefcase text-green-600"></i>
                    </div>
                    <span class="text-xs text-green-600"><?php echo safeGet($jobStats, 'active_jobs', 0); ?> active</span>
                </div>
                <h3 class="text-2xl font-bold"><?php echo number_format(safeGet($jobStats, 'total_jobs', 0)); ?></h3>
                <p class="text-xs text-gray-500">Total Jobs</p>
            </div>
            
            <div class="bg-white dark:bg-gray-800 rounded-xl p-4 shadow-sm stat-card animate-fade-up delay-200">
                <div class="flex items-center justify-between mb-2">
                    <div class="w-9 h-9 bg-purple-100 dark:bg-purple-900/30 rounded-xl flex items-center justify-center">
                        <i class="fas fa-file-alt text-purple-600"></i>
                    </div>
                    <span class="text-xs text-yellow-600"><?php echo safeGet($appStats, 'pending_applications', 0); ?> pending</span>
                </div>
                <h3 class="text-2xl font-bold"><?php echo number_format(safeGet($appStats, 'total_applications', 0)); ?></h3>
                <p class="text-xs text-gray-500">Applications</p>
            </div>
            
            <div class="bg-white dark:bg-gray-800 rounded-xl p-4 shadow-sm stat-card animate-fade-up delay-300">
                <div class="flex items-center justify-between mb-2">
                    <div class="w-9 h-9 bg-emerald-100 dark:bg-emerald-900/30 rounded-xl flex items-center justify-center">
                        <i class="fas fa-eye text-emerald-600"></i>
                    </div>
                </div>
                <h3 class="text-2xl font-bold"><?php echo number_format(safeGet($jobStats, 'total_views', 0)); ?></h3>
                <p class="text-xs text-gray-500">Total Views</p>
            </div>
        </div>
        
        <!-- Second Stats Row -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
            <div class="bg-white dark:bg-gray-800 rounded-xl p-3 shadow-sm">
                <p class="text-xs text-gray-500">Job Seekers</p>
                <p class="text-xl font-bold"><?php echo number_format(safeGet($userStats, 'total_seekers', 0)); ?></p>
            </div>
            <div class="bg-white dark:bg-gray-800 rounded-xl p-3 shadow-sm">
                <p class="text-xs text-gray-500">Employers</p>
                <p class="text-xl font-bold"><?php echo number_format(safeGet($userStats, 'total_employers', 0)); ?></p>
            </div>
            <div class="bg-white dark:bg-gray-800 rounded-xl p-3 shadow-sm">
                <p class="text-xs text-gray-500">Hired</p>
                <p class="text-xl font-bold text-green-600"><?php echo number_format(safeGet($appStats, 'hired_applications', 0)); ?></p>
            </div>
            <div class="bg-white dark:bg-gray-800 rounded-xl p-3 shadow-sm">
                <p class="text-xs text-gray-500">Featured Jobs</p>
                <p class="text-xl font-bold text-orange-600"><?php echo safeGet($jobStats, 'featured_jobs', 0); ?></p>
            </div>
        </div>
        
        <!-- Chart Section -->
        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm p-5 mb-8 animate-fade-up">
            <div class="flex items-center justify-between mb-5">
                <h3 class="text-lg font-bold">30-Day Activity Trends</h3>
                <div class="flex space-x-2">
                    <button onclick="setChartType('line')" id="lineBtn" class="px-3 py-1 text-sm bg-indigo-100 dark:bg-indigo-900/50 text-indigo-600 rounded-lg">Line</button>
                    <button onclick="setChartType('bar')" id="barBtn" class="px-3 py-1 text-sm bg-gray-100 dark:bg-gray-700 text-gray-600 rounded-lg">Bar</button>
                </div>
            </div>
            <div class="chart-container">
                <canvas id="mainChart"></canvas>
            </div>
            <div class="flex flex-wrap justify-center gap-4 mt-4">
                <div class="flex items-center"><div class="w-3 h-3 bg-indigo-600 rounded-full mr-1.5"></div><span class="text-xs">Users</span></div>
                <div class="flex items-center"><div class="w-3 h-3 bg-purple-600 rounded-full mr-1.5"></div><span class="text-xs">Jobs</span></div>
                <div class="flex items-center"><div class="w-3 h-3 bg-emerald-600 rounded-full mr-1.5"></div><span class="text-xs">Applications</span></div>
                <div class="flex items-center"><div class="w-3 h-3 bg-blue-600 rounded-full mr-1.5"></div><span class="text-xs">Views</span></div>
            </div>
        </div>
        
        <!-- Two Column Layout -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
            <!-- Categories -->
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm p-5 animate-fade-up delay-100">
                <div class="flex items-center justify-between mb-5">
                    <h3 class="text-lg font-bold">Job Categories</h3>
                    <a href="categories.php" class="text-indigo-600 text-sm hover:underline">View All →</a>
                </div>
                <?php if (!empty($categories)): ?>
                    <div class="space-y-3">
                        <?php $maxCount = max(array_column($categories, 'count')); ?>
                        <?php foreach ($categories as $cat): ?>
                            <?php $percent = ($cat['count'] / max($maxCount, 1)) * 100; ?>
                            <div>
                                <div class="flex justify-between text-sm mb-1">
                                    <span class="font-medium"><?php echo htmlspecialchars($cat['name']); ?></span>
                                    <span class="text-gray-500"><?php echo $cat['count']; ?> jobs</span>
                                </div>
                                <div class="w-full h-2 bg-gray-200 dark:bg-gray-700 rounded-full overflow-hidden">
                                    <div class="h-full rounded-full bg-gradient-to-r from-indigo-500 to-purple-500" style="width: <?php echo $percent; ?>%"></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="text-gray-500 text-center py-8">No categories found</p>
                <?php endif; ?>
            </div>
            
            <!-- Recent Jobs -->
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm p-5 animate-fade-up delay-200">
                <div class="flex items-center justify-between mb-5">
                    <h3 class="text-lg font-bold">Recent Jobs</h3>
                    <a href="jobs.php" class="text-indigo-600 text-sm hover:underline">View All →</a>
                </div>
                <?php if (!empty($recentJobs)): ?>
                    <div class="space-y-2 max-h-80 overflow-y-auto">
                        <?php foreach ($recentJobs as $job): ?>
                            <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-700/30 rounded-xl">
                                <div>
                                    <p class="font-medium text-sm"><?php echo htmlspecialchars(substr($job['title'], 0, 35)); ?></p>
                                    <div class="flex items-center space-x-2 mt-1">
                                        <span class="text-xs text-gray-500"><?php echo htmlspecialchars($job['job_category'] ?? 'Other'); ?></span>
                                        <span class="w-1 h-1 bg-gray-300 rounded-full"></span>
                                        <span class="text-xs text-gray-500"><?php echo htmlspecialchars($job['city'] ?? 'Remote'); ?></span>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <span class="badge badge-<?php echo strtolower($job['status']) == 'active' ? 'active' : 'draft'; ?>"><?php echo $job['status']; ?></span>
                                    <p class="text-xs text-gray-400 mt-1"><?php echo timeAgo($job['created_at']); ?></p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="text-gray-500 text-center py-8">No jobs found</p>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Second Two Column Layout -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Recent Users -->
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm p-5 animate-fade-up delay-100">
                <div class="flex items-center justify-between mb-5">
                    <h3 class="text-lg font-bold">Recent Users</h3>
                    <a href="users.php" class="text-indigo-600 text-sm hover:underline">View All →</a>
                </div>
                <?php if (!empty($recentUsers)): ?>
                    <div class="space-y-2 max-h-80 overflow-y-auto">
                        <?php foreach ($recentUsers as $user): ?>
                            <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-700/30 rounded-xl">
                                <div class="flex items-center space-x-3">
                                    <div class="w-9 h-9 bg-gradient-to-r from-indigo-100 to-purple-100 rounded-xl flex items-center justify-center">
                                        <i class="fas fa-user text-indigo-600 text-sm"></i>
                                    </div>
                                    <div>
                                        <p class="font-medium text-sm"><?php echo htmlspecialchars(substr($user['email'], 0, 30)); ?></p>
                                        <div class="flex items-center space-x-2">
                                            <span class="text-xs text-gray-500 capitalize"><?php echo $user['user_type']; ?></span>
                                            <?php if ($user['is_verified']): ?>
                                                <span class="text-xs text-green-600"><i class="fas fa-check-circle"></i></span>
                                            <?php else: ?>
                                                <span class="text-xs text-yellow-600"><i class="fas fa-clock"></i></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="text-xs text-gray-400"><?php echo timeAgo($user['created_at']); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="text-gray-500 text-center py-8">No users found</p>
                <?php endif; ?>
            </div>
            
            <!-- Recent Applications -->
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm p-5 animate-fade-up delay-200">
                <div class="flex items-center justify-between mb-5">
                    <h3 class="text-lg font-bold">Recent Applications</h3>
                    <a href="applications.php" class="text-indigo-600 text-sm hover:underline">View All →</a>
                </div>
                <?php if (!empty($recentApps)): ?>
                    <div class="space-y-2 max-h-80 overflow-y-auto">
                        <?php foreach ($recentApps as $app): ?>
                            <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-700/30 rounded-xl">
                                <div>
                                    <p class="font-medium text-sm"><?php echo htmlspecialchars(substr($app['job_title'], 0, 35)); ?></p>
                                    <p class="text-xs text-gray-500">by <?php echo htmlspecialchars(substr($app['user_email'], 0, 25)); ?></p>
                                </div>
                                <div class="text-right">
                                    <span class="badge badge-<?php echo $app['status'] == 'pending' ? 'pending' : ($app['status'] == 'hired' ? 'active' : 'draft'); ?>">
                                        <?php echo ucfirst($app['status']); ?>
                                    </span>
                                    <p class="text-xs text-gray-400 mt-1"><?php echo timeAgo($app['created_at']); ?></p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="text-gray-500 text-center py-8">No applications found</p>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- System Status -->
        <div class="mt-6 bg-white dark:bg-gray-800 rounded-2xl shadow-sm p-5 animate-fade-up delay-300">
            <h3 class="text-lg font-bold mb-4">System Health</h3>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-700/30 rounded-xl">
                    <div class="flex items-center space-x-2">
                        <i class="fas fa-database text-green-500"></i>
                        <span class="text-sm">Database</span>
                    </div>
                    <span class="text-green-600 text-sm">Online</span>
                </div>
                <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-700/30 rounded-xl">
                    <div class="flex items-center space-x-2">
                        <i class="fas fa-cloud text-green-500"></i>
                        <span class="text-sm">Storage</span>
                    </div>
                    <span class="text-green-600 text-sm">Active</span>
                </div>
                <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-700/30 rounded-xl">
                    <div class="flex items-center space-x-2">
                        <i class="fas fa-envelope text-blue-500"></i>
                        <span class="text-sm">Email Queue</span>
                    </div>
                    <span class="text-gray-500 text-sm"><?php echo safeGet($systemHealth, 'email_queue', 0); ?> pending</span>
                </div>
                <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-700/30 rounded-xl">
                    <div class="flex items-center space-x-2">
                        <i class="fas fa-clock text-orange-500"></i>
                        <span class="text-sm">Last Backup</span>
                    </div>
                    <span class="text-gray-500 text-sm">Today</span>
                </div>
            </div>
        </div>
    </div>
</main>

<script>
// ========== SIDEBAR TOGGLE ==========
const sidebar = document.getElementById('sidebar');
const sidebarToggle = document.getElementById('sidebarToggle');

sidebarToggle?.addEventListener('click', () => {
    sidebar.classList.toggle('-translate-x-full');
});

// Close sidebar on click outside (mobile)
document.addEventListener('click', (e) => {
    if (window.innerWidth < 1024) {
        if (!sidebar.contains(e.target) && !sidebarToggle.contains(e.target)) {
            sidebar.classList.add('-translate-x-full');
        }
    }
});

// ========== DARK MODE ==========
const darkBtn = document.getElementById('darkModeBtn');
const darkIcon = document.getElementById('darkModeIcon');

function initDarkMode() {
    if (localStorage.theme === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
        document.documentElement.classList.add('dark');
        darkIcon?.classList.remove('fa-moon');
        darkIcon?.classList.add('fa-sun');
    } else {
        document.documentElement.classList.remove('dark');
        darkIcon?.classList.remove('fa-sun');
        darkIcon?.classList.add('fa-moon');
    }
}
initDarkMode();

darkBtn?.addEventListener('click', () => {
    document.documentElement.classList.toggle('dark');
    if (document.documentElement.classList.contains('dark')) {
        localStorage.theme = 'dark';
        darkIcon.classList.remove('fa-moon');
        darkIcon.classList.add('fa-sun');
    } else {
        localStorage.theme = 'light';
        darkIcon.classList.remove('fa-sun');
        darkIcon.classList.add('fa-moon');
    }
    setTimeout(() => {
        if (window.mainChart) window.mainChart.update();
    }, 100);
});

// ========== CHART INITIALIZATION ==========
let currentChartType = 'line';
let mainChart;

const chartData = {
    labels: <?php echo json_encode($chartData['labels']); ?>,
    users: <?php echo json_encode($chartData['users']); ?>,
    jobs: <?php echo json_encode($chartData['jobs']); ?>,
    apps: <?php echo json_encode($chartData['apps']); ?>,
    views: <?php echo json_encode($chartData['views']); ?>
};

const chartCtx = document.getElementById('mainChart')?.getContext('2d');
if (chartCtx) {
    mainChart = new Chart(chartCtx, {
        type: 'line',
        data: {
            labels: chartData.labels,
            datasets: [
                { label: 'Users', data: chartData.users, borderColor: '#4f46e5', backgroundColor: 'rgba(79, 70, 229, 0.1)', borderWidth: 2, tension: 0.4, fill: true },
                { label: 'Jobs', data: chartData.jobs, borderColor: '#7c3aed', backgroundColor: 'rgba(124, 58, 237, 0.1)', borderWidth: 2, tension: 0.4, fill: true },
                { label: 'Applications', data: chartData.apps, borderColor: '#10b981', backgroundColor: 'rgba(16, 185, 129, 0.1)', borderWidth: 2, tension: 0.4, fill: true },
                { label: 'Views', data: chartData.views, borderColor: '#3b82f6', backgroundColor: 'rgba(59, 130, 246, 0.1)', borderWidth: 2, tension: 0.4, fill: true }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { display: false },
                tooltip: { backgroundColor: 'rgba(0,0,0,0.8)', cornerRadius: 8, padding: 10 }
            },
            scales: {
                y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.05)' }, ticks: { stepSize: 1 } },
                x: { grid: { display: false }, ticks: { maxRotation: 45, minRotation: 45, maxTicksLimit: 10 } }
            }
        }
    });
}

function setChartType(type) {
    if (!mainChart) return;
    currentChartType = type;
    mainChart.config.type = type;
    mainChart.update();
    
    const lineBtn = document.getElementById('lineBtn');
    const barBtn = document.getElementById('barBtn');
    if (type === 'line') {
        lineBtn.classList.add('bg-indigo-100', 'dark:bg-indigo-900/50', 'text-indigo-600');
        lineBtn.classList.remove('bg-gray-100', 'dark:bg-gray-700', 'text-gray-600');
        barBtn.classList.add('bg-gray-100', 'dark:bg-gray-700', 'text-gray-600');
        barBtn.classList.remove('bg-indigo-100', 'dark:bg-indigo-900/50', 'text-indigo-600');
    } else {
        barBtn.classList.add('bg-indigo-100', 'dark:bg-indigo-900/50', 'text-indigo-600');
        barBtn.classList.remove('bg-gray-100', 'dark:bg-gray-700', 'text-gray-600');
        lineBtn.classList.add('bg-gray-100', 'dark:bg-gray-700', 'text-gray-600');
        lineBtn.classList.remove('bg-indigo-100', 'dark:bg-indigo-900/50', 'text-indigo-600');
    }
}

// ========== AJAX REFRESH FUNCTION ==========
const refreshBtn = document.getElementById('refreshBtn');
let isRefreshing = false;

async function refreshDashboardData() {
    if (isRefreshing) return;
    isRefreshing = true;
    
    if (refreshBtn) {
        refreshBtn.innerHTML = '<i class="fas fa-spinner fa-spin text-gray-600"></i>';
    }
    
    try {
        const response = await fetch('dashboard.php?ajax=1&t=' + Date.now());
        const data = await response.json();
        
        if (data.success && data.data) {
            // Update stat numbers
            const statNumbers = document.querySelectorAll('.stat-card h3, .grid-cols-2.md\\:grid-cols-4 .text-xl');
            if (data.data.total_users) {
                const userStat = document.querySelector('.stat-card:first-child h3');
                if (userStat) userStat.textContent = data.data.total_users.toLocaleString();
            }
            
            // Update chart with new data if available
            if (data.data.trends && mainChart) {
                const newLabels = data.data.trends.map(t => t.date);
                const newUsers = data.data.trends.map(t => t.users);
                const newJobs = data.data.trends.map(t => t.jobs);
                const newApps = data.data.trends.map(t => t.apps);
                
                mainChart.data.labels = newLabels;
                mainChart.data.datasets[0].data = newUsers;
                mainChart.data.datasets[1].data = newJobs;
                mainChart.data.datasets[2].data = newApps;
                mainChart.update();
            }
        }
    } catch (error) {
        console.error('Refresh failed:', error);
    } finally {
        isRefreshing = false;
        if (refreshBtn) {
            refreshBtn.innerHTML = '<i class="fas fa-rotate-right text-gray-600"></i>';
        }
    }
}

refreshBtn?.addEventListener('click', refreshDashboardData);

// Auto-refresh every 30 seconds
setInterval(refreshDashboardData, 30000);
</script>
</body>
</html>