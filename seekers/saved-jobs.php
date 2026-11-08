<?php
// seekers/saved-jobs.php - Enhanced Saved Jobs Page for Seekers
// Version: 1.0 - Full-featured saved jobs management

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

// Check if user is logged in and is a seeker
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'seeker') {
    $_SESSION['error'] = 'Please login as a job seeker to access this page';
    header('Location: /login.php');
    exit();
}

// Get database connection (PDO)
$pdo = getDB();
$user_id = $_SESSION['user_id'];

// Get seeker ID from session or database
if (isset($_SESSION['seeker_id'])) {
    $seeker_id = $_SESSION['seeker_id'];
} else {
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

// Get seeker profile for header info
$seekerProfile = fetchOne("
    SELECT first_name, last_name, profile_picture, headline
    FROM seekers 
    WHERE id = ?
", [$seeker_id]);

// ===== HANDLE ACTIONS =====
$action = $_GET['action'] ?? '';
$job_id = isset($_GET['job_id']) ? (int)$_GET['job_id'] : 0;

if ($action === 'remove' && $job_id > 0) {
    // Remove from saved jobs
    $result = executeQuery("DELETE FROM saved_jobs WHERE seeker_id = ? AND job_id = ?", [$seeker_id, $job_id]);
    if ($result) {
        $_SESSION['success'] = 'Job removed from saved list';
    } else {
        $_SESSION['error'] = 'Failed to remove job';
    }
    header('Location: saved-jobs.php');
    exit();
}

if ($action === 'clear_all') {
    // Clear all saved jobs
    $result = executeQuery("DELETE FROM saved_jobs WHERE seeker_id = ?", [$seeker_id]);
    if ($result) {
        $_SESSION['success'] = 'All saved jobs cleared';
    } else {
        $_SESSION['error'] = 'Failed to clear saved jobs';
    }
    header('Location: saved-jobs.php');
    exit();
}

if ($action === 'apply_all' && isset($_POST['job_ids'])) {
    // Apply to multiple selected jobs
    $job_ids = $_POST['job_ids'];
    $success_count = 0;
    $fail_count = 0;
    
    foreach ($job_ids as $job_id) {
        // Check if already applied
        $existing = fetchOne("SELECT id FROM applications WHERE seeker_id = ? AND job_id = ?", [$seeker_id, $job_id]);
        if (!$existing) {
            $result = executeQuery("
                INSERT INTO applications (job_id, seeker_id, application_status, applied_at) 
                VALUES (?, ?, 'applied', NOW())
            ", [$job_id, $seeker_id]);
            
            if ($result) {
                $success_count++;
                // Remove from saved jobs after applying
                executeQuery("DELETE FROM saved_jobs WHERE seeker_id = ? AND job_id = ?", [$seeker_id, $job_id]);
                
                // Create notification
                createNotification($user_id, 'application_update', 'Application Submitted', 
                    'Your application has been successfully submitted', 'inapp');
            } else {
                $fail_count++;
            }
        } else {
            // Already applied, just remove from saved
            executeQuery("DELETE FROM saved_jobs WHERE seeker_id = ? AND job_id = ?", [$seeker_id, $job_id]);
            $success_count++;
        }
    }
    
    if ($success_count > 0) {
        $_SESSION['success'] = "Successfully applied to $success_count jobs";
    }
    if ($fail_count > 0) {
        $_SESSION['error'] = "Failed to apply to $fail_count jobs";
    }
    header('Location: saved-jobs.php');
    exit();
}

// ===== FILTERS AND SORTING =====
$sort = $_GET['sort'] ?? 'recent';
$filter_company = isset($_GET['company']) ? (int)$_GET['company'] : 0;
$filter_location = $_GET['location'] ?? '';
$filter_job_type = $_GET['job_type'] ?? '';
$search = $_GET['search'] ?? '';

// Build the WHERE clause
$where = "sj.seeker_id = ?";
$params = [$seeker_id];

if ($filter_company > 0) {
    $where .= " AND e.id = ?";
    $params[] = $filter_company;
}

if (!empty($filter_location)) {
    $where .= " AND (j.city LIKE ? OR j.country LIKE ? OR j.location_type = ?)";
    $params[] = "%$filter_location%";
    $params[] = "%$filter_location%";
    $params[] = $filter_location;
}

if (!empty($filter_job_type)) {
    $where .= " AND jt.slug = ?";
    $params[] = $filter_job_type;
}

if (!empty($search)) {
    $where .= " AND (j.title LIKE ? OR j.description LIKE ? OR e.company_name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

// Determine ORDER BY clause
$orderBy = match($sort) {
    'recent' => "sj.created_at DESC",
    'oldest' => "sj.created_at ASC",
    'company_asc' => "e.company_name ASC",
    'company_desc' => "e.company_name DESC",
    'deadline_asc' => "j.application_deadline ASC",
    'deadline_desc' => "j.application_deadline DESC",
    'salary_high' => "j.salary_max DESC",
    'salary_low' => "j.salary_max ASC",
    default => "sj.created_at DESC"
};

// ===== FETCH SAVED JOBS =====
$savedJobs = fetchAll("
    SELECT 
        sj.id as saved_id,
        sj.created_at as saved_date,
        sj.notes,
        j.id as job_id,
        j.title,
        j.slug,
        j.description,
        j.salary_min,
        j.salary_max,
        j.salary_currency,
        j.location_type,
        j.city,
        j.country,
        j.experience_level,
        j.is_urgent,
        j.is_featured,
        j.application_deadline,
        j.created_at as job_created,
        j.total_vacancies,
        e.id as employer_id,
        e.company_name,
        e.company_logo,
        e.is_verified,
        e.industry,
        jt.id as job_type_id,
        jt.name as job_type_name,
        jt.slug as job_type_slug,
        jc.name as category_name,
        jc.slug as category_slug,
        (SELECT COUNT(*) FROM applications a WHERE a.job_id = j.id) as total_applications,
        (SELECT COUNT(*) FROM applications a WHERE a.job_id = j.id AND a.seeker_id = ?) as already_applied
    FROM saved_jobs sj
    INNER JOIN jobs j ON sj.job_id = j.id
    INNER JOIN employers e ON j.employer_id = e.id
    LEFT JOIN job_types jt ON j.job_type_id = jt.id
    LEFT JOIN job_categories jc ON j.category_id = jc.id
    WHERE $where
    ORDER BY $orderBy
", array_merge([$seeker_id], $params));

$totalSaved = count($savedJobs);

// ===== FETCH ALL COMPANIES FOR FILTER =====
$companies = fetchAll("
    SELECT DISTINCT e.id, e.company_name, COUNT(sj.id) as job_count
    FROM saved_jobs sj
    INNER JOIN jobs j ON sj.job_id = j.id
    INNER JOIN employers e ON j.employer_id = e.id
    WHERE sj.seeker_id = ?
    GROUP BY e.id
    ORDER BY e.company_name
", [$seeker_id]);

// ===== FETCH JOB TYPES FOR FILTER =====
$jobTypes = fetchAll("
    SELECT DISTINCT jt.id, jt.name, jt.slug, COUNT(sj.id) as job_count
    FROM saved_jobs sj
    INNER JOIN jobs j ON sj.job_id = j.id
    INNER JOIN job_types jt ON j.job_type_id = jt.id
    WHERE sj.seeker_id = ?
    GROUP BY jt.id
    ORDER BY jt.name
", [$seeker_id]);

// ===== FETCH LOCATIONS FOR FILTER =====
$locations = fetchAll("
    SELECT DISTINCT 
        CASE 
            WHEN j.location_type = 'remote' THEN 'Remote'
            WHEN j.city IS NOT NULL AND j.city != '' THEN j.city
            ELSE j.country
        END as location,
        COUNT(sj.id) as job_count
    FROM saved_jobs sj
    INNER JOIN jobs j ON sj.job_id = j.id
    WHERE sj.seeker_id = ?
    GROUP BY location
    ORDER BY location
", [$seeker_id]);

// Avatar URL for header
$avatarUrl = $seekerProfile['profile_picture'] ?? 'https://ui-avatars.com/api/?name=' . urlencode(($seekerProfile['first_name'] ?? 'User') . '+' . ($seekerProfile['last_name'] ?? '')) . '&background=4f46e5&color=fff&size=128&bold=true&length=2';
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Saved Jobs - <?php echo htmlspecialchars($seekerProfile['first_name'] ?? 'Seeker'); ?> | TransNow</title>
    
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
    
    <!-- Select2 for better dropdowns -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    
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
            from { transform: translateY(20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
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
        
        .animate-fadeIn {
            animation: fadeIn 0.3s ease-out forwards;
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
        
        /* Badge Styles */
        .badge-urgent {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
        }
        
        .badge-featured {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: white;
        }
        
        .badge-expiring {
            background: linear-gradient(135deg, #f97316, #ea580c);
            color: white;
        }
        
        /* Checkbox Custom */
        .custom-checkbox {
            width: 20px;
            height: 20px;
            border: 2px solid #e5e7eb;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s;
            position: relative;
        }
        
        .custom-checkbox:checked {
            background: linear-gradient(135deg, #4f46e5, #7c3aed);
            border-color: transparent;
        }
        
        .custom-checkbox:checked::after {
            content: '✓';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            color: white;
            font-size: 12px;
        }
        
        /* Loading Skeleton */
        .skeleton {
            background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
            background-size: 200% 100%;
            animation: shimmer 1.5s infinite;
        }
        
        /* Tooltip */
        .tooltip {
            position: relative;
        }
        
        .tooltip:hover::after {
            content: attr(data-tooltip);
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            background: #1f2937;
            color: white;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 12px;
            white-space: nowrap;
            z-index: 50;
            margin-bottom: 8px;
        }
        
        /* Mobile Optimizations */
        @media (max-width: 768px) {
            .saved-jobs-grid {
                grid-template-columns: 1fr;
            }
        }
        
        /* Print Styles */
        @media print {
            .no-print {
                display: none;
            }
            .print-only {
                display: block;
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
    <!-- Sidebar (same as dashboard) -->
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
                            <?php echo htmlspecialchars($seekerProfile['headline'] ?? 'Job Seeker'); ?>
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
                <a href="saved-jobs.php" class="flex items-center space-x-3 px-4 py-3 bg-indigo-50 dark:bg-indigo-900/20 text-indigo-600 dark:text-indigo-400 rounded-xl font-medium">
                    <i class="fas fa-bookmark w-5"></i>
                    <span>Saved Jobs</span>
                    <?php if ($totalSaved > 0): ?>
                    <span class="ml-auto bg-indigo-500 text-white text-xs px-2 py-1 rounded-full"><?php echo $totalSaved; ?></span>
                    <?php endif; ?>
                </a>
                <a href="interviews.php" class="flex items-center space-x-3 px-4 py-3 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                    <i class="fas fa-calendar-alt w-5"></i>
                    <span>Interviews</span>
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
                        <i class="fas fa-bookmark mr-2 text-indigo-600"></i>
                        Saved Jobs
                    </h1>
                    
                    <!-- Right Actions -->
                    <div class="flex items-center space-x-4">
                        <!-- Search -->
                        <div class="relative hidden md:block">
                            <form method="GET" id="searchForm">
                                <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                                <input type="text" 
                                       name="search" 
                                       value="<?php echo htmlspecialchars($search); ?>"
                                       placeholder="Search saved jobs..."
                                       class="pl-10 pr-4 py-2 bg-gray-100 dark:bg-gray-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 w-64">
                                <?php if (!empty($sort)): ?>
                                <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sort); ?>">
                                <?php endif; ?>
                                <?php if (!empty($filter_company)): ?>
                                <input type="hidden" name="company" value="<?php echo $filter_company; ?>">
                                <?php endif; ?>
                                <?php if (!empty($filter_location)): ?>
                                <input type="hidden" name="location" value="<?php echo htmlspecialchars($filter_location); ?>">
                                <?php endif; ?>
                                <?php if (!empty($filter_job_type)): ?>
                                <input type="hidden" name="job_type" value="<?php echo htmlspecialchars($filter_job_type); ?>">
                                <?php endif; ?>
                            </form>
                        </div>
                        
                        <!-- Quick Actions -->
                        <div class="relative">
                            <button id="quickActionsBtn" class="px-4 py-2 bg-gradient-to-r from-indigo-600 to-purple-600 text-white rounded-xl font-medium hover:shadow-lg transition-all duration-300 flex items-center space-x-2">
                                <i class="fas fa-ellipsis-v"></i>
                                <span class="hidden md:inline">Actions</span>
                            </button>
                            
                            <!-- Quick Actions Dropdown -->
                            <div id="quickActionsDropdown" class="absolute right-0 mt-2 w-64 bg-white dark:bg-gray-800 rounded-2xl shadow-2xl border border-gray-200 dark:border-gray-700 hidden z-50">
                                <div class="p-2">
                                    <?php if ($totalSaved > 0): ?>
                                    <button onclick="selectAllJobs()" class="w-full text-left flex items-center space-x-3 px-4 py-3 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                                        <i class="fas fa-check-square text-indigo-600 w-5"></i>
                                        <span>Select All</span>
                                    </button>
                                    <button onclick="deselectAllJobs()" class="w-full text-left flex items-center space-x-3 px-4 py-3 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                                        <i class="fas fa-square text-gray-400 w-5"></i>
                                        <span>Deselect All</span>
                                    </button>
                                    <div class="border-t dark:border-gray-700 my-2"></div>
                                    <button onclick="applySelectedJobs()" class="w-full text-left flex items-center space-x-3 px-4 py-3 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                                        <i class="fas fa-paper-plane text-green-600 w-5"></i>
                                        <span>Apply Selected</span>
                                    </button>
                                    <button onclick="clearSelectedJobs()" class="w-full text-left flex items-center space-x-3 px-4 py-3 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                                        <i class="fas fa-trash-alt text-red-600 w-5"></i>
                                        <span>Remove Selected</span>
                                    </button>
                                    <div class="border-t dark:border-gray-700 my-2"></div>
                                    <?php endif; ?>
                                    <a href="?action=clear_all" onclick="return confirm('Are you sure you want to clear all saved jobs?')" class="flex items-center space-x-3 px-4 py-3 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                                        <i class="fas fa-trash text-red-600 w-5"></i>
                                        <span>Clear All</span>
                                    </a>
                                    <button onclick="exportSavedJobs()" class="w-full text-left flex items-center space-x-3 px-4 py-3 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                                        <i class="fas fa-download text-purple-600 w-5"></i>
                                        <span>Export List</span>
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
            <!-- Header Stats -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8" data-aos="fade-down">
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-gray-500 dark:text-gray-400 text-sm">Saved Jobs</p>
                            <h3 class="text-3xl font-bold mt-2"><?php echo $totalSaved; ?></h3>
                        </div>
                        <div class="w-14 h-14 bg-indigo-100 dark:bg-indigo-900/20 rounded-xl flex items-center justify-center">
                            <i class="fas fa-bookmark text-indigo-600 dark:text-indigo-400 text-2xl"></i>
                        </div>
                    </div>
                </div>
                
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-gray-500 dark:text-gray-400 text-sm">Expiring Soon</p>
                            <h3 class="text-3xl font-bold mt-2">
                                <?php 
                                $expiring = array_filter($savedJobs, function($job) {
                                    return !empty($job['application_deadline']) && 
                                           strtotime($job['application_deadline']) < strtotime('+7 days');
                                });
                                echo count($expiring);
                                ?>
                            </h3>
                        </div>
                        <div class="w-14 h-14 bg-orange-100 dark:bg-orange-900/20 rounded-xl flex items-center justify-center">
                            <i class="fas fa-clock text-orange-600 dark:text-orange-400 text-2xl"></i>
                        </div>
                    </div>
                </div>
                
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-gray-500 dark:text-gray-400 text-sm">Companies</p>
                            <h3 class="text-3xl font-bold mt-2"><?php echo count($companies); ?></h3>
                        </div>
                        <div class="w-14 h-14 bg-purple-100 dark:bg-purple-900/20 rounded-xl flex items-center justify-center">
                            <i class="fas fa-building text-purple-600 dark:text-purple-400 text-2xl"></i>
                        </div>
                    </div>
                </div>
                
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-gray-500 dark:text-gray-400 text-sm">Applied From Saved</p>
                            <h3 class="text-3xl font-bold mt-2">
                                <?php 
                                $applied = array_filter($savedJobs, function($job) {
                                    return $job['already_applied'] > 0;
                                });
                                echo count($applied);
                                ?>
                            </h3>
                        </div>
                        <div class="w-14 h-14 bg-green-100 dark:bg-green-900/20 rounded-xl flex items-center justify-center">
                            <i class="fas fa-check-circle text-green-600 dark:text-green-400 text-2xl"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filters Bar -->
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6 mb-8" data-aos="fade-up">
                <form method="GET" id="filterForm" class="grid grid-cols-1 md:grid-cols-5 gap-4">
                    <div>
                        <label class="block text-sm font-medium mb-2">Sort By</label>
                        <select name="sort" class="w-full px-4 py-2 bg-gray-100 dark:bg-gray-700 rounded-lg focus:ring-2 focus:ring-indigo-500" onchange="this.form.submit()">
                            <option value="recent" <?php echo $sort == 'recent' ? 'selected' : ''; ?>>Recently Saved</option>
                            <option value="oldest" <?php echo $sort == 'oldest' ? 'selected' : ''; ?>>Oldest First</option>
                            <option value="deadline_asc" <?php echo $sort == 'deadline_asc' ? 'selected' : ''; ?>>Deadline (Earliest)</option>
                            <option value="deadline_desc" <?php echo $sort == 'deadline_desc' ? 'selected' : ''; ?>>Deadline (Latest)</option>
                            <option value="salary_high" <?php echo $sort == 'salary_high' ? 'selected' : ''; ?>>Highest Salary</option>
                            <option value="salary_low" <?php echo $sort == 'salary_low' ? 'selected' : ''; ?>>Lowest Salary</option>
                            <option value="company_asc" <?php echo $sort == 'company_asc' ? 'selected' : ''; ?>>Company (A-Z)</option>
                            <option value="company_desc" <?php echo $sort == 'company_desc' ? 'selected' : ''; ?>>Company (Z-A)</option>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium mb-2">Company</label>
                        <select name="company" class="w-full px-4 py-2 bg-gray-100 dark:bg-gray-700 rounded-lg focus:ring-2 focus:ring-indigo-500" onchange="this.form.submit()">
                            <option value="">All Companies</option>
                            <?php foreach ($companies as $company): ?>
                            <option value="<?php echo $company['id']; ?>" <?php echo $filter_company == $company['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($company['company_name']); ?> (<?php echo $company['job_count']; ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium mb-2">Job Type</label>
                        <select name="job_type" class="w-full px-4 py-2 bg-gray-100 dark:bg-gray-700 rounded-lg focus:ring-2 focus:ring-indigo-500" onchange="this.form.submit()">
                            <option value="">All Types</option>
                            <?php foreach ($jobTypes as $type): ?>
                            <option value="<?php echo $type['slug']; ?>" <?php echo $filter_job_type == $type['slug'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($type['name']); ?> (<?php echo $type['job_count']; ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium mb-2">Location</label>
                        <select name="location" class="w-full px-4 py-2 bg-gray-100 dark:bg-gray-700 rounded-lg focus:ring-2 focus:ring-indigo-500" onchange="this.form.submit()">
                            <option value="">All Locations</option>
                            <?php foreach ($locations as $loc): ?>
                            <option value="<?php echo htmlspecialchars($loc['location']); ?>" <?php echo $filter_location == $loc['location'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($loc['location']); ?> (<?php echo $loc['job_count']; ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="flex items-end">
                        <a href="saved-jobs.php" class="w-full px-6 py-2 bg-gray-200 dark:bg-gray-600 text-center rounded-lg hover:bg-gray-300 dark:hover:bg-gray-500 transition">
                            Clear Filters
                        </a>
                    </div>
                </form>
            </div>

            <!-- Saved Jobs Grid -->
            <div class="space-y-6" data-aos="fade-up">
                <!-- Mobile Search (visible on mobile only) -->
                <div class="md:hidden mb-4">
                    <form method="GET">
                        <div class="relative">
                            <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                            <input type="text" 
                                   name="search" 
                                   value="<?php echo htmlspecialchars($search); ?>"
                                   placeholder="Search saved jobs..."
                                   class="w-full pl-10 pr-4 py-3 bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 focus:ring-2 focus:ring-indigo-500">
                        </div>
                    </form>
                </div>

                <?php if (empty($savedJobs)): ?>
                <!-- Empty State -->
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-12 text-center">
                    <div class="w-32 h-32 bg-gradient-to-r from-indigo-100 to-purple-100 dark:from-indigo-900/20 dark:to-purple-900/20 rounded-full flex items-center justify-center mx-auto mb-6">
                        <i class="fas fa-bookmark text-5xl text-indigo-600 dark:text-indigo-400"></i>
                    </div>
                    <h2 class="text-2xl font-bold mb-3">No Saved Jobs Yet</h2>
                    <p class="text-gray-600 dark:text-gray-400 mb-8 max-w-md mx-auto">
                        Start saving jobs you're interested in. They'll appear here for easy access when you're ready to apply.
                    </p>
                    <div class="flex flex-col sm:flex-row gap-4 justify-center">
                        <a href="jobs.php" class="inline-flex items-center space-x-3 bg-gradient-to-r from-indigo-600 to-purple-600 text-white px-8 py-4 rounded-xl font-semibold hover:shadow-xl hover:scale-105 transition-all duration-300">
                            <i class="fas fa-search"></i>
                            <span>Browse Jobs</span>
                        </a>
                        <a href="dashboard.php" class="inline-flex items-center space-x-3 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 px-8 py-4 rounded-xl font-semibold hover:bg-gray-300 dark:hover:bg-gray-600 transition">
                            <i class="fas fa-tachometer-alt"></i>
                            <span>Back to Dashboard</span>
                        </a>
                    </div>
                </div>
                <?php else: ?>

                <!-- Bulk Actions Bar -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-4 mb-4 flex items-center justify-between">
                    <div class="flex items-center space-x-4">
                        <input type="checkbox" id="selectAllCheckbox" class="custom-checkbox" onclick="toggleSelectAll(this)">
                        <label for="selectAllCheckbox" class="text-sm font-medium">Select All (<?php echo $totalSaved; ?>)</label>
                    </div>
                    <div class="flex items-center space-x-3">
                        <button onclick="applySelectedJobs()" class="px-4 py-2 bg-green-600 text-white rounded-lg text-sm hover:bg-green-700 transition flex items-center space-x-2">
                            <i class="fas fa-paper-plane"></i>
                            <span class="hidden md:inline">Apply Selected</span>
                        </button>
                        <button onclick="clearSelectedJobs()" class="px-4 py-2 bg-red-600 text-white rounded-lg text-sm hover:bg-red-700 transition flex items-center space-x-2">
                            <i class="fas fa-trash"></i>
                            <span class="hidden md:inline">Remove Selected</span>
                        </button>
                    </div>
                </div>

                <!-- Jobs List -->
                <form id="bulkActionForm" method="POST" action="?action=apply_all">
                    <div class="space-y-4">
                        <?php foreach ($savedJobs as $index => $job): 
                            $isExpiring = !empty($job['application_deadline']) && strtotime($job['application_deadline']) < strtotime('+7 days');
                            $isApplied = $job['already_applied'] > 0;
                            $logoUrl = $job['company_logo'] ?: 'https://ui-avatars.com/api/?name=' . urlencode($job['company_name']) . '&background=4f46e5&color=fff';
                        ?>
                        <div class="group relative bg-white dark:bg-gray-800 rounded-2xl shadow-xl hover:shadow-2xl transition-all duration-300 border-2 border-transparent hover:border-indigo-200 dark:hover:border-indigo-800" 
                             data-job-id="<?php echo $job['job_id']; ?>"
                             data-aos="fade-up" 
                             data-aos-delay="<?php echo min($index * 30, 300); ?>">
                            
                            <!-- Badges -->
                            <div class="absolute top-4 right-4 flex flex-wrap gap-2 z-10">
                                <?php if ($job['is_urgent']): ?>
                                <span class="badge-urgent px-3 py-1 rounded-full text-xs font-bold">URGENT</span>
                                <?php endif; ?>
                                <?php if ($job['is_featured']): ?>
                                <span class="badge-featured px-3 py-1 rounded-full text-xs font-bold">FEATURED</span>
                                <?php endif; ?>
                                <?php if ($isExpiring): ?>
                                <span class="badge-expiring px-3 py-1 rounded-full text-xs font-bold">EXPIRING SOON</span>
                                <?php endif; ?>
                                <?php if ($isApplied): ?>
                                <span class="bg-green-100 dark:bg-green-900 text-green-600 dark:text-green-400 px-3 py-1 rounded-full text-xs font-bold">
                                    <i class="fas fa-check-circle mr-1"></i>APPLIED
                                </span>
                                <?php endif; ?>
                            </div>
                            
                            <div class="p-6">
                                <div class="flex items-start">
                                    <!-- Checkbox for bulk actions -->
                                    <div class="mr-4 pt-2">
                                        <input type="checkbox" 
                                               name="job_ids[]" 
                                               value="<?php echo $job['job_id']; ?>" 
                                               class="job-checkbox custom-checkbox"
                                               <?php echo $isApplied ? 'disabled' : ''; ?>>
                                    </div>
                                    
                                    <!-- Company Logo -->
                                    <div class="flex-shrink-0 mr-6">
                                        <img src="<?php echo htmlspecialchars($logoUrl); ?>" 
                                             alt="<?php echo htmlspecialchars($job['company_name']); ?>"
                                             class="w-20 h-20 rounded-xl object-cover border-2 border-gray-200 dark:border-gray-700 group-hover:scale-110 transition-transform duration-300">
                                    </div>
                                    
                                    <!-- Job Details -->
                                    <div class="flex-1 min-w-0">
                                        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                                            <!-- Main Info -->
                                            <div class="lg:col-span-2">
                                                <div class="flex items-start justify-between mb-2">
                                                    <h2 class="text-2xl font-bold group-hover:text-indigo-600 transition">
                                                        <a href="job-details.php?slug=<?php echo htmlspecialchars($job['slug']); ?>">
                                                            <?php echo htmlspecialchars($job['title']); ?>
                                                        </a>
                                                    </h2>
                                                </div>
                                                
                                                <div class="flex flex-wrap items-center gap-3 mb-3">
                                                    <span class="text-gray-700 dark:text-gray-300 font-medium flex items-center">
                                                        <i class="fas fa-building mr-2 text-indigo-500"></i>
                                                        <?php echo htmlspecialchars($job['company_name']); ?>
                                                        <?php if ($job['is_verified']): ?>
                                                        <i class="fas fa-check-circle text-blue-500 ml-2" title="Verified Employer"></i>
                                                        <?php endif; ?>
                                                    </span>
                                                    <span class="text-sm px-3 py-1 bg-gray-100 dark:bg-gray-700 rounded-full">
                                                        <i class="fas fa-tag mr-1 text-indigo-500"></i>
                                                        <?php echo htmlspecialchars($job['category_name'] ?? 'General'); ?>
                                                    </span>
                                                    <span class="text-sm px-3 py-1 bg-gray-100 dark:bg-gray-700 rounded-full">
                                                        <i class="fas fa-briefcase mr-1 text-indigo-500"></i>
                                                        <?php echo htmlspecialchars($job['job_type_name'] ?? 'Full Time'); ?>
                                                    </span>
                                                </div>
                                                
                                                <p class="text-gray-600 dark:text-gray-400 mb-4 line-clamp-2">
                                                    <?php echo htmlspecialchars(substr($job['description'], 0, 200)) . '...'; ?>
                                                </p>
                                                
                                                <!-- Location & Salary -->
                                                <div class="flex flex-wrap gap-4 text-sm">
                                                    <span class="flex items-center text-gray-600 dark:text-gray-400">
                                                        <i class="fas fa-map-marker-alt mr-2 text-indigo-500"></i>
                                                        <?php 
                                                        if ($job['location_type'] == 'remote') {
                                                            echo 'Remote';
                                                        } else {
                                                            echo htmlspecialchars($job['city'] ?? '') . 
                                                                 (!empty($job['country']) ? ', ' . htmlspecialchars($job['country']) : '');
                                                        }
                                                        ?>
                                                    </span>
                                                    
                                                    <?php if (!empty($job['salary_min']) && !empty($job['salary_max'])): ?>
                                                    <span class="flex items-center text-gray-600 dark:text-gray-400">
                                                        <i class="fas fa-dollar-sign mr-2 text-indigo-500"></i>
                                                        <?php echo number_format($job['salary_min']); ?> - <?php echo number_format($job['salary_max']); ?> 
                                                        <?php echo $job['salary_currency'] ?? 'USD'; ?>/year
                                                    </span>
                                                    <?php endif; ?>
                                                    
                                                    <span class="flex items-center text-gray-600 dark:text-gray-400">
                                                        <i class="fas fa-users mr-2 text-indigo-500"></i>
                                                        <?php echo $job['total_applications']; ?> applicant(s)
                                                    </span>
                                                </div>
                                            </div>
                                            
                                            <!-- Right Column - Dates & Actions -->
                                            <div class="lg:text-right space-y-4">
                                                <!-- Saved Date -->
                                                <div>
                                                    <p class="text-sm text-gray-500 dark:text-gray-500">
                                                        <i class="far fa-clock mr-1"></i>
                                                        Saved <?php echo timeAgo($job['saved_date']); ?>
                                                    </p>
                                                    <?php if (!empty($job['application_deadline'])): ?>
                                                    <p class="text-sm mt-1 <?php echo $isExpiring ? 'text-orange-600 font-semibold' : 'text-gray-500'; ?>">
                                                        <i class="far fa-calendar-alt mr-1"></i>
                                                        Deadline: <?php echo date('M j, Y', strtotime($job['application_deadline'])); ?>
                                                    </p>
                                                    <?php endif; ?>
                                                    <?php if (!empty($job['notes'])): ?>
                                                    <p class="text-sm mt-1 text-gray-500 italic">
                                                        <i class="fas fa-sticky-note mr-1"></i>
                                                        <?php echo htmlspecialchars(substr($job['notes'], 0, 50)); ?>
                                                    </p>
                                                    <?php endif; ?>
                                                </div>
                                                
                                                <!-- Action Buttons -->
                                                <div class="flex lg:justify-end space-x-3">
                                                    <?php if (!$isApplied): ?>
                                                    <a href="job-details.php?slug=<?php echo htmlspecialchars($job['slug']); ?>#apply" 
                                                       class="px-4 py-2 bg-gradient-to-r from-indigo-600 to-purple-600 text-white rounded-xl font-semibold hover:shadow-lg hover:scale-105 transition-all duration-300 text-sm">
                                                        Apply Now
                                                    </a>
                                                    <?php else: ?>
                                                    <a href="applications.php" 
                                                       class="px-4 py-2 bg-green-600 text-white rounded-xl font-semibold hover:bg-green-700 transition text-sm flex items-center">
                                                        <i class="fas fa-check-circle mr-2"></i>Applied
                                                    </a>
                                                    <?php endif; ?>
                                                    
                                                    <a href="?action=remove&job_id=<?php echo $job['job_id']; ?>" 
                                                       onclick="return confirm('Remove this job from saved list?')"
                                                       class="w-10 h-10 bg-gray-100 dark:bg-gray-700 rounded-xl flex items-center justify-center hover:bg-red-100 dark:hover:bg-red-900/20 hover:text-red-600 transition group"
                                                       title="Remove from saved">
                                                        <i class="fas fa-trash"></i>
                                                    </a>
                                                </div>
                                                
                                                <!-- More Options -->
                                                <div class="flex lg:justify-end space-x-2">
                                                    <button onclick="shareJob(<?php echo $job['job_id']; ?>, '<?php echo htmlspecialchars($job['title']); ?>')" 
                                                            class="w-8 h-8 bg-gray-100 dark:bg-gray-700 rounded-lg flex items-center justify-center hover:bg-indigo-100 dark:hover:bg-indigo-900/20 hover:text-indigo-600 transition tooltip"
                                                            data-tooltip="Share">
                                                        <i class="fas fa-share-alt"></i>
                                                    </button>
                                                    <a href="job-details.php?slug=<?php echo htmlspecialchars($job['slug']); ?>" 
                                                       class="w-8 h-8 bg-gray-100 dark:bg-gray-700 rounded-lg flex items-center justify-center hover:bg-indigo-100 dark:hover:bg-indigo-900/20 hover:text-indigo-600 transition tooltip"
                                                       data-tooltip="View Details">
                                                        <i class="fas fa-external-link-alt"></i>
                                                    </a>
                                                    <button onclick="addNote(<?php echo $job['job_id']; ?>)" 
                                                            class="w-8 h-8 bg-gray-100 dark:bg-gray-700 rounded-lg flex items-center justify-center hover:bg-indigo-100 dark:hover:bg-indigo-900/20 hover:text-indigo-600 transition tooltip"
                                                            data-tooltip="Add Note">
                                                        <i class="fas fa-sticky-note"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Similar Jobs Suggestion (optional) -->
                            <?php if (!$isApplied && !$isExpiring): ?>
                            <div class="px-6 py-3 bg-gray-50 dark:bg-gray-700 border-t dark:border-gray-600">
                                <div class="flex items-center text-sm">
                                    <i class="fas fa-magic text-indigo-500 mr-2"></i>
                                    <span class="text-gray-600 dark:text-gray-400">Similar jobs from </span>
                                    <a href="jobs.php?company=<?php echo $job['employer_id']; ?>" class="ml-1 text-indigo-600 hover:underline">
                                        <?php echo htmlspecialchars($job['company_name']); ?>
                                    </a>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </form>

                <!-- Pagination (if needed) -->
                <?php if ($totalSaved > 20): ?>
                <div class="mt-8 flex justify-center">
                    <nav class="flex items-center space-x-2">
                        <a href="#" class="w-10 h-10 bg-white dark:bg-gray-800 rounded-lg flex items-center justify-center hover:bg-indigo-600 hover:text-white transition">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                        <a href="#" class="w-10 h-10 bg-indigo-600 text-white rounded-lg flex items-center justify-center">1</a>
                        <a href="#" class="w-10 h-10 bg-white dark:bg-gray-800 rounded-lg flex items-center justify-center hover:bg-indigo-600 hover:text-white transition">2</a>
                        <a href="#" class="w-10 h-10 bg-white dark:bg-gray-800 rounded-lg flex items-center justify-center hover:bg-indigo-600 hover:text-white transition">3</a>
                        <span class="text-gray-500">...</span>
                        <a href="#" class="w-10 h-10 bg-white dark:bg-gray-800 rounded-lg flex items-center justify-center hover:bg-indigo-600 hover:text-white transition">10</a>
                        <a href="#" class="w-10 h-10 bg-white dark:bg-gray-800 rounded-lg flex items-center justify-center hover:bg-indigo-600 hover:text-white transition">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    </nav>
                </div>
                <?php endif; ?>

                <?php endif; ?>
            </div>

            <!-- Bottom Section: Job Alerts -->
            <?php if (!empty($savedJobs)): ?>
            <div class="mt-8 bg-gradient-to-r from-indigo-600 to-purple-600 rounded-2xl shadow-xl p-8 text-white" data-aos="fade-up">
                <div class="flex flex-col md:flex-row items-center justify-between">
                    <div class="flex items-center space-x-4 mb-4 md:mb-0">
                        <div class="w-16 h-16 bg-white/20 rounded-2xl flex items-center justify-center">
                            <i class="fas fa-bell text-3xl"></i>
                        </div>
                        <div>
                            <h3 class="text-2xl font-bold mb-2">Get Job Alerts</h3>
                            <p class="text-indigo-100">Receive notifications when similar jobs are posted</p>
                        </div>
                    </div>
                    <button onclick="createJobAlert()" class="px-8 py-4 bg-white text-indigo-600 rounded-xl font-semibold hover:shadow-xl hover:scale-105 transition-all duration-300">
                        <i class="fas fa-bell mr-2"></i>
                        Create Alert for Saved Jobs
                    </button>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<!-- Mobile Sidebar (same as dashboard) -->
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
                    <img src="<?php echo htmlspecialchars($avatarUrl); ?>" alt="Profile" class="w-12 h-12 rounded-lg object-cover border-2 border-indigo-200">
                    <div class="flex-1 min-w-0">
                        <p class="font-semibold truncate"><?php echo htmlspecialchars(($seekerProfile['first_name'] ?? 'User') . ' ' . ($seekerProfile['last_name'] ?? '')); ?></p>
                        <p class="text-sm text-gray-500 truncate"><?php echo htmlspecialchars($seekerProfile['headline'] ?? 'Job Seeker'); ?></p>
                    </div>
                </div>
                
                <nav class="space-y-1">
                    <a href="dashboard.php" class="block px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">Dashboard</a>
                    <a href="jobs.php" class="block px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">Find Jobs</a>
                    <a href="applications.php" class="block px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">Applications</a>
                    <a href="saved-jobs.php" class="block px-4 py-2 bg-indigo-50 dark:bg-indigo-900/20 text-indigo-600 rounded-lg">Saved Jobs</a>
                    <a href="interviews.php" class="block px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">Interviews</a>
                    <a href="profile.php" class="block px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">Profile</a>
                    <a href="settings.php" class="block px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">Settings</a>
                    <a href="/logout.php" class="block px-4 py-2 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg">Logout</a>
                </nav>
            </div>
        </div>
    </div>
</div>

<!-- Add Note Modal -->
<div id="noteModal" class="fixed inset-0 z-50 hidden flex items-center justify-center">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeNoteModal()"></div>
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl p-8 max-w-md w-full mx-4 relative z-10 animate-slideIn">
        <h3 class="text-2xl font-bold mb-6">Add Note to Job</h3>
        <form id="noteForm" method="POST" action="saved-jobs.php">
            <input type="hidden" name="job_id" id="noteJobId">
            <input type="hidden" name="action" value="add_note">
            <div class="mb-6">
                <label class="block text-sm font-medium mb-2">Your Note</label>
                <textarea name="note" rows="4" class="w-full px-4 py-3 bg-gray-100 dark:bg-gray-700 rounded-lg focus:ring-2 focus:ring-indigo-500" placeholder="E.g., Why I'm interested, questions to ask, etc."></textarea>
            </div>
            <div class="flex space-x-3">
                <button type="submit" class="flex-1 bg-gradient-to-r from-indigo-600 to-purple-600 text-white py-3 rounded-xl font-semibold hover:shadow-lg transition">
                    Save Note
                </button>
                <button type="button" onclick="closeNoteModal()" class="flex-1 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-white py-3 rounded-xl font-semibold hover:bg-gray-300 dark:hover:bg-gray-600 transition">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Share Modal -->
<div id="shareModal" class="fixed inset-0 z-50 hidden flex items-center justify-center">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeShareModal()"></div>
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl p-8 max-w-md w-full mx-4 relative z-10 animate-slideIn">
        <h3 class="text-2xl font-bold mb-6">Share Job</h3>
        <div class="space-y-4">
            <div>
                <p class="text-sm text-gray-600 dark:text-gray-400 mb-2">Job Link</p>
                <div class="flex">
                    <input type="text" id="shareLink" readonly class="flex-1 px-4 py-3 bg-gray-100 dark:bg-gray-700 rounded-l-lg focus:ring-2 focus:ring-indigo-500">
                    <button onclick="copyShareLink()" class="px-4 py-3 bg-indigo-600 text-white rounded-r-lg hover:bg-indigo-700 transition">
                        <i class="fas fa-copy"></i>
                    </button>
                </div>
            </div>
            <div class="grid grid-cols-4 gap-3 pt-4">
                <button onclick="shareVia('linkedin')" class="p-3 bg-[#0077B5] text-white rounded-lg hover:scale-110 transition">
                    <i class="fab fa-linkedin-in text-xl"></i>
                </button>
                <button onclick="shareVia('twitter')" class="p-3 bg-[#1DA1F2] text-white rounded-lg hover:scale-110 transition">
                    <i class="fab fa-twitter text-xl"></i>
                </button>
                <button onclick="shareVia('facebook')" class="p-3 bg-[#4267B2] text-white rounded-lg hover:scale-110 transition">
                    <i class="fab fa-facebook-f text-xl"></i>
                </button>
                <button onclick="shareVia('wechat')" class="p-3 bg-[#07C160] text-white rounded-lg hover:scale-110 transition">
                    <i class="fab fa-weixin text-xl"></i>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Scripts -->
<script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

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
    $('select').select2({
        theme: 'classic',
        minimumResultsForSearch: 5
    });
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

// Bulk Actions
function selectAllJobs() {
    const checkboxes = document.querySelectorAll('.job-checkbox:not(:disabled)');
    checkboxes.forEach(cb => cb.checked = true);
    document.getElementById('selectAllCheckbox').checked = true;
}

function deselectAllJobs() {
    const checkboxes = document.querySelectorAll('.job-checkbox');
    checkboxes.forEach(cb => cb.checked = false);
    document.getElementById('selectAllCheckbox').checked = false;
}

function toggleSelectAll(checkbox) {
    const checkboxes = document.querySelectorAll('.job-checkbox:not(:disabled)');
    checkboxes.forEach(cb => cb.checked = checkbox.checked);
}

function applySelectedJobs() {
    const selected = document.querySelectorAll('.job-checkbox:checked');
    if (selected.length === 0) {
        alert('Please select at least one job');
        return;
    }
    
    if (confirm(`Apply to ${selected.length} selected job(s)?`)) {
        document.getElementById('bulkActionForm').submit();
    }
}

function clearSelectedJobs() {
    const selected = document.querySelectorAll('.job-checkbox:checked');
    if (selected.length === 0) {
        alert('Please select at least one job');
        return;
    }
    
    if (confirm(`Remove ${selected.length} selected job(s) from saved list?`)) {
        const jobIds = Array.from(selected).map(cb => cb.value);
        window.location.href = 'saved-jobs.php?action=remove_multiple&ids=' + jobIds.join(',');
    }
}

// Note Modal
const noteModal = document.getElementById('noteModal');
const noteJobId = document.getElementById('noteJobId');

function addNote(jobId) {
    noteJobId.value = jobId;
    noteModal.classList.remove('hidden');
}

function closeNoteModal() {
    noteModal.classList.add('hidden');
}

// Share Modal
const shareModal = document.getElementById('shareModal');
let currentShareJobId = null;
let currentShareJobTitle = '';

function shareJob(jobId, jobTitle) {
    currentShareJobId = jobId;
    currentShareJobTitle = jobTitle;
    const shareLink = document.getElementById('shareLink');
    shareLink.value = window.location.origin + '/seekers/job-details.php?slug=' + jobId; // You might want to use slug instead
    shareModal.classList.remove('hidden');
}

function closeShareModal() {
    shareModal.classList.add('hidden');
}

function copyShareLink() {
    const shareLink = document.getElementById('shareLink');
    shareLink.select();
    document.execCommand('copy');
    alert('Link copied to clipboard!');
}

function shareVia(platform) {
    const url = encodeURIComponent(document.getElementById('shareLink').value);
    const title = encodeURIComponent(currentShareJobTitle);
    let shareUrl = '';
    
    switch(platform) {
        case 'linkedin':
            shareUrl = `https://www.linkedin.com/sharing/share-offsite/?url=${url}`;
            break;
        case 'twitter':
            shareUrl = `https://twitter.com/intent/tweet?text=${title}&url=${url}`;
            break;
        case 'facebook':
            shareUrl = `https://www.facebook.com/sharer/sharer.php?u=${url}`;
            break;
        case 'wechat':
            shareUrl = `https://api.whatsapp.com/send?text=${title}%20${url}`; // Fallback
            break;
    }
    
    window.open(shareUrl, '_blank', 'width=600,height=400');
}

// Export Saved Jobs
function exportSavedJobs() {
    const jobs = <?php echo json_encode($savedJobs); ?>;
    let csv = 'Job Title,Company,Location,Job Type,Salary,Deadline,Saved Date\n';
    
    jobs.forEach(job => {
        const salary = job.salary_min && job.salary_max ? 
            `${job.salary_min}-${job.salary_max} ${job.salary_currency}` : 'Not specified';
        const deadline = job.application_deadline || 'No deadline';
        const savedDate = new Date(job.saved_date).toLocaleDateString();
        
        csv += `"${job.title}","${job.company_name}","${job.city || job.location_type}","${job.job_type_name}",${salary},${deadline},${savedDate}\n`;
    });
    
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'saved_jobs_export.csv';
    a.click();
}

// Create Job Alert
function createJobAlert() {
    // Get unique job types and categories from saved jobs
    const jobs = <?php echo json_encode($savedJobs); ?>;
    const jobTypes = [...new Set(jobs.map(j => j.job_type_id))];
    const categories = [...new Set(jobs.map(j => j.category_name))];
    
    // You can implement this to create a job alert based on saved jobs
    fetch('/ajax/create-job-alert.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            job_types: jobTypes,
            categories: categories,
            based_on_saved: true
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Job alert created successfully! You will be notified when similar jobs are posted.');
        } else {
            alert('Failed to create job alert. Please try again.');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred. Please try again.');
    });
}

// Keyboard shortcuts
document.addEventListener('keydown', (e) => {
    // Ctrl/Cmd + A to select all
    if ((e.ctrlKey || e.metaKey) && e.key === 'a') {
        e.preventDefault();
        selectAllJobs();
    }
    // Escape to close modals
    if (e.key === 'Escape') {
        closeNoteModal();
        closeShareModal();
        quickActionsDropdown?.classList.add('hidden');
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

// Lazy loading images
const images = document.querySelectorAll('img[data-src]');
const imageObserver = new IntersectionObserver((entries, observer) => {
    entries.forEach(entry => {
        if (entry.isIntersecting) {
            const img = entry.target;
            img.src = img.dataset.src;
            img.classList.remove('skeleton');
            observer.unobserve(img);
        }
    });
});

images.forEach(img => imageObserver.observe(img));

// Add loading skeleton effect to images
document.querySelectorAll('img').forEach(img => {
    if (!img.complete) {
        img.classList.add('skeleton');
        img.addEventListener('load', () => img.classList.remove('skeleton'));
        img.addEventListener('error', () => img.classList.remove('skeleton'));
    }
});
</script>
</body>
</html>