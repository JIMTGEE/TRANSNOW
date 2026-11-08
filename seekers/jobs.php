<?php
// seekers/jobs.php - Enhanced Job Search & Browse
// Version: 6.0 - Fixed job display, optimized queries, added features

// Error reporting for development (remove in production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Start session
session_start();

// Include configuration
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../includes/auth.php';

// Get database connection (PDO)
$pdo = getDBConnection();

// ===== DEBUG: Check if jobs exist =====
$debug_jobs = fetchAll("
    SELECT 
        j.*,
        e.company_name,
        e.is_verified,
        jc.name as category_name,
        jt.name as job_type_name
    FROM jobs j
    LEFT JOIN employers e ON j.employer_id = e.id
    LEFT JOIN job_categories jc ON j.category_id = jc.id
    LEFT JOIN job_types jt ON j.job_type_id = jt.id
    LIMIT 10
");

if (empty($debug_jobs)) {
    error_log("No jobs found in database. Checking if jobs table exists and has data...");
    
    // Check if jobs table exists
    $table_check = fetchOne("SHOW TABLES LIKE 'jobs'");
    if (!$table_check) {
        error_log("ERROR: jobs table does not exist!");
    } else {
        // Count total jobs
        $job_count = fetchOne("SELECT COUNT(*) as count FROM jobs");
        error_log("Total jobs in database: " . ($job_count['count'] ?? 0));
        
        // Check job status distribution
        $status_counts = fetchAll("
            SELECT status, COUNT(*) as count 
            FROM jobs 
            GROUP BY status
        ");
        error_log("Job status distribution: " . print_r($status_counts, true));
    }
}

// ===== GET FILTER PARAMETERS =====
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = isset($_GET['per_page']) ? intval($_GET['per_page']) : 12;
$perPage = in_array($perPage, [12, 24, 48]) ? $perPage : 12;

$filters = [
    'keywords' => isset($_GET['keywords']) ? trim($_GET['keywords']) : '',
    'category_id' => isset($_GET['category_id']) ? intval($_GET['category_id']) : 0,
    'category_slug' => isset($_GET['category']) ? trim($_GET['category']) : '',
    'job_type_id' => isset($_GET['job_type_id']) ? intval($_GET['job_type_id']) : 0,
    'job_type_slug' => isset($_GET['job_type']) ? trim($_GET['job_type']) : '',
    'location_type' => isset($_GET['location_type']) ? trim($_GET['location_type']) : '',
    'experience_level' => isset($_GET['experience_level']) ? trim($_GET['experience_level']) : '',
    'country' => isset($_GET['country']) ? trim($_GET['country']) : '',
    'city' => isset($_GET['city']) ? trim($_GET['city']) : '',
    'salary_min' => isset($_GET['salary_min']) ? intval($_GET['salary_min']) : 0,
    'salary_max' => isset($_GET['salary_max']) ? intval($_GET['salary_max']) : 0,
    'is_featured' => isset($_GET['featured']) ? 1 : 0,
    'is_urgent' => isset($_GET['urgent']) ? 1 : 0,
    'sort' => isset($_GET['sort']) ? $_GET['sort'] : 'latest',
    'employer_id' => isset($_GET['employer_id']) ? intval($_GET['employer_id']) : 0,
    'skill' => isset($_GET['skill']) ? trim($_GET['skill']) : ''
];

// ===== FETCH FILTER OPTIONS FROM DATABASE =====

// 1. Job Categories with counts
$categories = fetchAll("
    SELECT 
        jc.id, 
        jc.name, 
        jc.slug,
        COUNT(DISTINCT j.id) as job_count
    FROM job_categories jc
    LEFT JOIN jobs j ON j.category_id = jc.id 
        AND j.status IN ('active', 'published')
    WHERE jc.is_active = 1 OR jc.is_active IS NULL
    GROUP BY jc.id
    ORDER BY job_count DESC, jc.name ASC
");

// 2. Job Types
$jobTypes = fetchAll("
    SELECT id, name, slug
    FROM job_types
    WHERE is_active = 1 OR is_active IS NULL
    ORDER BY name
");

// 3. Locations (cities with jobs)
$locations = fetchAll("
    SELECT DISTINCT 
        city, 
        country,
        COUNT(*) as job_count
    FROM jobs
    WHERE status IN ('active', 'published') 
        AND city IS NOT NULL 
        AND city != ''
    GROUP BY city, country
    ORDER BY job_count DESC
    LIMIT 20
");

// 4. Countries with jobs
$countries = fetchAll("
    SELECT DISTINCT 
        country,
        COUNT(*) as job_count
    FROM jobs
    WHERE status IN ('active', 'published') 
        AND country IS NOT NULL 
        AND country != ''
    GROUP BY country
    ORDER BY job_count DESC
");

// 5. Popular skills from jobs
$popularSkills = fetchAll("
    SELECT 
        skill_name,
        COUNT(*) as job_count
    FROM job_skills js
    INNER JOIN jobs j ON js.job_id = j.id
    WHERE j.status IN ('active', 'published')
    GROUP BY skill_name
    ORDER BY job_count DESC
    LIMIT 15
");

// 6. Get user's saved jobs if logged in
$savedJobIds = [];
$seekerData = null;

if (isset($_SESSION['user_id']) && $_SESSION['user_type'] == 'seeker') {
    $seekerData = fetchOne("SELECT id FROM seekers WHERE user_id = ?", [$_SESSION['user_id']]);
    if ($seekerData) {
        $savedJobs = fetchAll("SELECT job_id FROM saved_jobs WHERE seeker_id = ?", [$seekerData['id']]);
        $savedJobIds = array_column($savedJobs, 'job_id');
    }
}

// ===== BUILD JOBS QUERY WITH FILTERS =====

// Base conditions - MORE PERMISSIVE
$whereConditions = ["j.status IN ('active', 'published')"];
$params = [];

// Optional: Only filter by verified employers if you want to show only verified jobs
// Comment this out if you want to show all jobs regardless of employer verification
// $whereConditions[] = "e.is_verified = 1";

// Keyword search (title, description, company name)
if (!empty($filters['keywords'])) {
    $whereConditions[] = "(j.title LIKE ? OR j.description LIKE ? OR e.company_name LIKE ?)";
    $searchTerm = "%{$filters['keywords']}%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

// Category filter
if (!empty($filters['category_slug'])) {
    $whereConditions[] = "jc.slug = ?";
    $params[] = $filters['category_slug'];
} elseif ($filters['category_id'] > 0) {
    $whereConditions[] = "j.category_id = ?";
    $params[] = $filters['category_id'];
}

// Job type filter
if (!empty($filters['job_type_slug'])) {
    $whereConditions[] = "jt.slug = ?";
    $params[] = $filters['job_type_slug'];
} elseif ($filters['job_type_id'] > 0) {
    $whereConditions[] = "j.job_type_id = ?";
    $params[] = $filters['job_type_id'];
}

// Location type filter
if (!empty($filters['location_type'])) {
    $whereConditions[] = "j.location_type = ?";
    $params[] = $filters['location_type'];
}

// Experience level filter
if (!empty($filters['experience_level'])) {
    $whereConditions[] = "j.experience_level = ?";
    $params[] = $filters['experience_level'];
}

// Country filter
if (!empty($filters['country'])) {
    $whereConditions[] = "j.country = ?";
    $params[] = $filters['country'];
}

// City filter
if (!empty($filters['city'])) {
    $whereConditions[] = "j.city = ?";
    $params[] = $filters['city'];
}

// Salary range filter
if ($filters['salary_min'] > 0) {
    $whereConditions[] = "(j.salary_max >= ? OR j.salary_min >= ?)";
    $params[] = $filters['salary_min'];
    $params[] = $filters['salary_min'];
}

if ($filters['salary_max'] > 0) {
    $whereConditions[] = "j.salary_min <= ?";
    $params[] = $filters['salary_max'];
}

// Featured jobs filter
if ($filters['is_featured']) {
    $whereConditions[] = "j.is_featured = 1";
}

// Urgent jobs filter
if ($filters['is_urgent']) {
    $whereConditions[] = "j.is_urgent = 1";
}

// Employer filter
if ($filters['employer_id'] > 0) {
    $whereConditions[] = "j.employer_id = ?";
    $params[] = $filters['employer_id'];
}

// Skill filter
if (!empty($filters['skill'])) {
    $whereConditions[] = "EXISTS (SELECT 1 FROM job_skills js WHERE js.job_id = j.id AND js.skill_name LIKE ?)";
    $params[] = "%{$filters['skill']}%";
}

// Deadline filter (not expired) - MAKE THIS OPTIONAL
// Comment this out temporarily to see if jobs appear
// $whereConditions[] = "(j.application_deadline IS NULL OR j.application_deadline >= CURDATE())";

// Build WHERE clause
$whereClause = implode(" AND ", $whereConditions);

// Sorting
$orderBy = "j.created_at DESC"; // default
switch ($filters['sort']) {
    case 'latest':
        $orderBy = "j.created_at DESC";
        break;
    case 'oldest':
        $orderBy = "j.created_at ASC";
        break;
    case 'salary_high':
        $orderBy = "j.salary_max DESC, j.salary_min DESC";
        break;
    case 'salary_low':
        $orderBy = "j.salary_min ASC, j.salary_max ASC";
        break;
    case 'deadline':
        $orderBy = "j.application_deadline ASC";
        break;
}

// Count total jobs (for pagination)
$countSql = "SELECT COUNT(DISTINCT j.id) as total 
             FROM jobs j
             LEFT JOIN employers e ON j.employer_id = e.id
             LEFT JOIN job_types jt ON j.job_type_id = jt.id
             LEFT JOIN job_categories jc ON j.category_id = jc.id
             WHERE $whereClause";

$countResult = fetchOne($countSql, $params);
$totalJobs = $countResult['total'] ?? 0;
$totalPages = $totalJobs > 0 ? ceil($totalJobs / $perPage) : 0;
$offset = ($page - 1) * $perPage;

// Fetch jobs for current page
$jobsSql = "SELECT 
    j.id, 
    j.title, 
    j.slug, 
    LEFT(j.description, 300) as description, -- Truncate for display
    j.salary_min, 
    j.salary_max, 
    j.salary_currency,
    j.location_type, 
    j.city, 
    j.country,
    j.experience_level, 
    j.is_featured, 
    j.is_urgent,
    j.created_at, 
    j.published_at, 
    j.application_deadline,
    j.total_vacancies,
    j.status,
    e.id as employer_id, 
    e.company_name, 
    e.company_logo, 
    e.is_verified,
    jt.name as job_type_name, 
    jt.slug as job_type_slug,
    jc.name as category_name, 
    jc.slug as category_slug,
    (SELECT COUNT(*) FROM applications a WHERE a.job_id = j.id) as application_count
FROM jobs j
LEFT JOIN employers e ON j.employer_id = e.id
LEFT JOIN job_types jt ON j.job_type_id = jt.id
LEFT JOIN job_categories jc ON j.category_id = jc.id
WHERE $whereClause
ORDER BY 
    j.is_featured DESC,
    j.is_urgent DESC,
    $orderBy";

// Only add pagination if we have jobs
if ($totalJobs > 0) {
    $jobsSql .= " LIMIT ? OFFSET ?";
    $jobsParams = array_merge($params, [$perPage, $offset]);
    $jobs = fetchAll($jobsSql, $jobsParams);
} else {
    // Try without any filters to see if any jobs exist
    $testJobs = fetchAll("
        SELECT 
            j.id, j.title, j.status,
            e.company_name, e.is_verified
        FROM jobs j
        LEFT JOIN employers e ON j.employer_id = e.id
        LIMIT 5
    ");
    
    if (!empty($testJobs)) {
        error_log("Test jobs found: " . print_r($testJobs, true));
        // If test jobs found but filtered query returns none, use test jobs
        $jobs = $testJobs;
        $totalJobs = count($testJobs);
    } else {
        $jobs = [];
    }
}

// Get job skills for each job
$jobSkills = [];
if (!empty($jobs)) {
    $jobIds = array_column($jobs, 'id');
    if (!empty($jobIds)) {
        $placeholders = implode(',', array_fill(0, count($jobIds), '?'));
        $skillsData = fetchAll("
            SELECT job_id, skill_name 
            FROM job_skills 
            WHERE job_id IN ($placeholders)
            ORDER BY is_required DESC, skill_name
        ", $jobIds);
        
        foreach ($skillsData as $skill) {
            $jobSkills[$skill['job_id']][] = $skill['skill_name'];
        }
    }
}

// Get recommended jobs for sidebar
$recommendedJobs = [];
if (isset($_SESSION['user_id']) && $_SESSION['user_type'] == 'seeker' && $seekerData) {
    // Get seeker preferences for personalized recommendations
    $seekerPrefs = fetchOne("
        SELECT sp.*, s.skills, s.years_experience 
        FROM seeker_preferences sp
        INNER JOIN seekers s ON sp.seeker_id = s.id
        WHERE s.user_id = ?
    ", [$_SESSION['user_id']]);
    
    if ($seekerPrefs && !empty($seekerPrefs['preferred_categories'])) {
        // Build recommendation query based on preferences
        $recWhere = ["j.status IN ('active', 'published')"];
        $recParams = [];
        
        // Match by preferred categories
        $categories = json_decode($seekerPrefs['preferred_categories'], true);
        if (!empty($categories)) {
            $placeholders = implode(',', array_fill(0, count($categories), '?'));
            $recWhere[] = "j.category_id IN ($placeholders)";
            $recParams = array_merge($recParams, $categories);
        }
        
        // Exclude already applied jobs
        $recWhere[] = "j.id NOT IN (SELECT job_id FROM applications WHERE seeker_id = ?)";
        $recParams[] = $seekerData['id'];
        
        $recClause = implode(" AND ", $recWhere);
        
        $recommendedJobs = fetchAll("
            SELECT 
                j.id, j.title, j.slug,
                j.location_type, j.city,
                j.created_at,
                e.company_name, e.company_logo,
                jt.name as job_type_name
            FROM jobs j
            INNER JOIN employers e ON j.employer_id = e.id
            LEFT JOIN job_types jt ON j.job_type_id = jt.id
            WHERE $recClause
            ORDER BY j.created_at DESC
            LIMIT 5
        ", $recParams);
    }
}

// If no recommendations or seeker not logged in, get recent jobs
if (empty($recommendedJobs)) {
    $recommendedJobs = fetchAll("
        SELECT 
            j.id, j.title, j.slug,
            j.location_type, j.city,
            j.created_at,
            e.company_name, e.company_logo,
            jt.name as job_type_name
        FROM jobs j
        INNER JOIN employers e ON j.employer_id = e.id
        LEFT JOIN job_types jt ON j.job_type_id = jt.id
        WHERE j.status IN ('active', 'published')
        ORDER BY j.created_at DESC
        LIMIT 5
    ");
}

// Get urgent jobs count for badge
$urgentCount = fetchOne("
    SELECT COUNT(*) as count 
    FROM jobs j
    INNER JOIN employers e ON j.employer_id = e.id
    WHERE j.status IN ('active', 'published') 
        AND j.is_urgent = 1
")['count'] ?? 0;

// Get user info if logged in
$isLoggedIn = isset($_SESSION['user_id']);
$userType = $_SESSION['user_type'] ?? null;
$userName = '';

// Get user name based on type
if ($isLoggedIn) {
    if ($userType === 'seeker' && isset($_SESSION['seeker_id'])) {
        $userData = fetchOne("SELECT first_name, last_name FROM seekers WHERE id = ?", [$_SESSION['seeker_id']]);
        if ($userData) {
            $userName = trim($userData['first_name'] . ' ' . $userData['last_name']);
        }
    } elseif ($userType === 'employer' && isset($_SESSION['employer_id'])) {
        $userData = fetchOne("SELECT company_name FROM employers WHERE id = ?", [$_SESSION['employer_id']]);
        if ($userData) {
            $userName = $userData['company_name'];
        }
    }
    
    if (empty($userName)) {
        $userData = fetchOne("SELECT email FROM users WHERE id = ?", [$_SESSION['user_id']]);
        $userName = $userData['email'] ?? 'Account';
    }
}

// Page title based on filters
$pageTitle = "Browse Jobs";
if (!empty($filters['keywords'])) {
    $pageTitle = "Search: " . htmlspecialchars($filters['keywords']);
} elseif (!empty($filters['category_slug'])) {
    $categoryName = fetchOne("SELECT name FROM job_categories WHERE slug = ?", [$filters['category_slug']])['name'] ?? '';
    $pageTitle = $categoryName ? "$categoryName Jobs" : "Jobs by Category";
}

// Debug info - remove in production
$debug_info = [
    'total_jobs_in_db' => fetchOne("SELECT COUNT(*) as count FROM jobs")['count'] ?? 0,
    'active_jobs' => fetchOne("SELECT COUNT(*) as count FROM jobs WHERE status IN ('active', 'published')")['count'] ?? 0,
    'jobs_found' => count($jobs),
    'sql' => $jobsSql ?? 'No SQL',
    'params' => $jobsParams ?? []
];
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> - TransNow Job Portal</title>
    
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Ccircle cx='50' cy='50' r='45' fill='%234f46e5'/%3E%3Cpath d='M30 40 L70 40 L65 70 L35 70 Z' fill='white'/%3E%3Ccircle cx='50' cy='30' r='8' fill='white'/%3E%3C/svg%3E">
    
    <!-- CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    
    <style>
        /* Your existing styles remain exactly the same */
        * {
            font-family: 'Inter', sans-serif;
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }
        
        @keyframes pulse-glow {
            0%, 100% { box-shadow: 0 0 0 0 rgba(79, 70, 229, 0.5); }
            50% { box-shadow: 0 0 30px 10px rgba(79, 70, 229, 0.3); }
        }
        
        @keyframes slideIn {
            from { transform: translateY(30px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        .animate-float {
            animation: float 6s ease-in-out infinite;
        }
        
        .animate-pulse-glow {
            animation: pulse-glow 3s ease-in-out infinite;
        }
        
        .slide-in {
            animation: slideIn 0.5s ease-out forwards;
        }
        
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
        
        .hover-card {
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .hover-card:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 30px 60px -15px rgba(79, 70, 229, 0.4);
        }
        
        .gradient-text {
            background: linear-gradient(135deg, #4f46e5, #7c3aed, #db2777);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .badge-urgent {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
        }
        
        .badge-featured {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: white;
        }
        
        .badge-new {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
        }
        
        .filter-checkbox {
            width: 1.2rem;
            height: 1.2rem;
            border-radius: 0.25rem;
            border: 2px solid #d1d5db;
            transition: all 0.2s;
            cursor: pointer;
        }
        
        .filter-checkbox:checked {
            background-color: #4f46e5;
            border-color: #4f46e5;
        }
        
        .job-card {
            position: relative;
            overflow: hidden;
        }
        
        .save-btn {
            transition: all 0.3s;
        }
        
        .save-btn.saved {
            color: #ef4444;
            transform: scale(1.1);
        }
        
        .filter-tag {
            display: inline-flex;
            align-items: center;
            background: #e0e7ff;
            color: #4f46e5;
            padding: 0.5rem 1rem;
            border-radius: 2rem;
            font-size: 0.875rem;
            font-weight: 500;
        }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        
        .modal.active {
            display: flex;
        }
        
        .fab {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            z-index: 50;
        }
        
        @media (max-width: 1024px) {
            .filters-sidebar {
                position: fixed;
                top: 0;
                left: -100%;
                width: 100%;
                max-width: 400px;
                height: 100vh;
                z-index: 1000;
                transition: left 0.3s ease;
                overflow-y: auto;
                border-radius: 0 1.5rem 1.5rem 0;
            }
            
            .filters-sidebar.active {
                left: 0;
            }
        }

        /* Debug panel - remove in production */
        .debug-panel {
            position: fixed;
            bottom: 10px;
            left: 10px;
            background: rgba(0,0,0,0.8);
            color: #0f0;
            padding: 10px;
            border-radius: 5px;
            font-size: 12px;
            z-index: 9999;
            max-width: 400px;
            max-height: 300px;
            overflow: auto;
            display: none; /* Hide by default, enable if needed */
        }
    </style>
</head>
<body class="bg-gray-50 dark:bg-gray-900 text-gray-900 dark:text-white transition-colors duration-300">

<!-- Debug Panel - Remove in production -->
<div class="debug-panel" id="debugPanel" style="display: none;">
    <strong>Debug Info:</strong>
    <pre><?php print_r($debug_info); ?></pre>
    <button onclick="this.parentElement.style.display='none'" class="text-red-500">Close</button>
</div>

<!-- Floating Action Button for Mobile Filters -->
<div class="fab lg:hidden flex flex-col space-y-3">
    <button onclick="toggleFilters()" 
            class="w-14 h-14 bg-gradient-to-r from-indigo-600 to-purple-600 text-white rounded-full shadow-2xl flex items-center justify-center animate-pulse-glow">
        <i class="fas fa-sliders-h text-xl"></i>
    </button>
    <?php if ($urgentCount > 0): ?>
    <span class="absolute -top-2 -right-2 bg-red-500 text-white text-xs w-6 h-6 rounded-full flex items-center justify-center animate-pulse">
        <?php echo $urgentCount; ?>
    </span>
    <?php endif; ?>
</div>

<!-- Dark Mode Toggle -->
<button id="darkModeToggle" 
        class="fixed top-24 right-4 z-50 w-12 h-12 glass dark:glass-dark text-white rounded-full flex items-center justify-center hover:scale-110 transition-all duration-300 no-print">
    <i class="fas fa-moon text-xl" id="darkModeIcon"></i>
</button>

<!-- Navigation -->
<nav class="fixed w-full z-40 transition-all duration-300 no-print" id="navbar">
    <div class="container mx-auto px-4 py-4">
        <div class="glass dark:glass-dark rounded-2xl px-6 py-3">
            <div class="flex items-center justify-between">
                <!-- Logo -->
                <a href="../index.php" class="flex items-center space-x-3 group">
                    <div class="w-12 h-12 bg-gradient-to-r from-indigo-600 to-purple-600 rounded-xl flex items-center justify-center transform group-hover:rotate-12 transition-all duration-300 shadow-lg">
                        <i class="fas fa-globe-asia text-white text-2xl"></i>
                    </div>
                    <span class="text-2xl font-bold">
                        <span class="gradient-text">TransNow</span>
                    </span>
                    <?php if ($urgentCount > 0): ?>
                    <span class="bg-red-500 text-white text-xs px-2 py-1 rounded-full animate-pulse">
                        <?php echo $urgentCount; ?> Urgent
                    </span>
                    <?php endif; ?>
                </a>
                
                <!-- Desktop Menu -->
                <div class="hidden lg:flex items-center space-x-8">
                    <a href="../index.php" class="font-medium text-gray-700 dark:text-gray-300 hover:text-indigo-600 dark:hover:text-indigo-400 transition">
                        <i class="fas fa-home mr-1"></i> Home
                    </a>
                    <a href="jobs.php" class="font-medium text-indigo-600 dark:text-indigo-400 border-b-2 border-indigo-600">
                        <i class="fas fa-search mr-1"></i> Browse Jobs
                    </a>
                    <?php if ($isLoggedIn && $userType == 'seeker'): ?>
                    <a href="dashboard.php" class="font-medium text-gray-700 dark:text-gray-300 hover:text-indigo-600 dark:hover:text-indigo-400 transition">
                        <i class="fas fa-tachometer-alt mr-1"></i> Dashboard
                    </a>
                    <a href="saved-jobs.php" class="font-medium text-gray-700 dark:text-gray-300 hover:text-indigo-600 dark:hover:text-indigo-400 transition">
                        <i class="fas fa-bookmark mr-1"></i> Saved Jobs
                    </a>
                    <?php endif; ?>
                </div>
                
                <!-- Auth Buttons -->
                <div class="hidden lg:flex items-center space-x-4">
                    <?php if ($isLoggedIn): ?>
                        <div class="relative group">
                            <button class="flex items-center space-x-3 bg-gray-100 dark:bg-gray-800 px-4 py-2 rounded-xl hover:bg-gray-200 dark:hover:bg-gray-700 transition">
                                <div class="w-8 h-8 bg-gradient-to-r from-indigo-600 to-purple-600 rounded-full flex items-center justify-center">
                                    <i class="fas fa-user text-white text-sm"></i>
                                </div>
                                <span class="font-medium max-w-[150px] truncate"><?php echo htmlspecialchars($userName); ?></span>
                                <i class="fas fa-chevron-down text-xs"></i>
                            </button>
                            <div class="absolute right-0 mt-2 w-48 bg-white dark:bg-gray-800 rounded-xl shadow-2xl opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-300">
                                <div class="py-2">
                                    <a href="dashboard.php" class="block px-4 py-2 hover:bg-indigo-50 dark:hover:bg-gray-700 transition">
                                        <i class="fas fa-tachometer-alt mr-2 text-indigo-600"></i> Dashboard
                                    </a>
                                    <a href="profile.php" class="block px-4 py-2 hover:bg-indigo-50 dark:hover:bg-gray-700 transition">
                                        <i class="fas fa-user-circle mr-2 text-indigo-600"></i> Profile
                                    </a>
                                    <a href="applications.php" class="block px-4 py-2 hover:bg-indigo-50 dark:hover:bg-gray-700 transition">
                                        <i class="fas fa-paper-plane mr-2 text-indigo-600"></i> Applications
                                    </a>
                                    <a href="saved-jobs.php" class="block px-4 py-2 hover:bg-indigo-50 dark:hover:bg-gray-700 transition">
                                        <i class="fas fa-bookmark mr-2 text-indigo-600"></i> Saved Jobs
                                    </a>
                                    <div class="border-t dark:border-gray-700 my-2"></div>
                                    <a href="../logout.php" class="block px-4 py-2 text-red-600 hover:bg-red-50 dark:hover:bg-gray-700 transition">
                                        <i class="fas fa-sign-out-alt mr-2"></i> Logout
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <a href="../login.php" class="px-6 py-2 text-indigo-600 dark:text-indigo-400 font-medium hover:underline">Login</a>
                        <a href="../register.php" class="px-6 py-2 bg-gradient-to-r from-indigo-600 to-purple-600 text-white rounded-xl font-medium hover:shadow-xl hover:scale-105 transition-all duration-300">
                            Sign Up Free
                        </a>
                    <?php endif; ?>
                </div>
                
                <!-- Mobile Menu Button -->
                <button id="mobileMenuBtn" class="lg:hidden w-12 h-12 glass dark:glass-dark rounded-xl flex items-center justify-center">
                    <i class="fas fa-bars text-xl"></i>
                </button>
            </div>
        </div>
    </div>
    
    <!-- Mobile Menu -->
    <div id="mobileMenu" class="hidden lg:hidden container mx-auto px-4 mt-2">
        <div class="glass dark:glass-dark rounded-2xl p-6">
            <div class="flex flex-col space-y-4">
                <a href="../index.php" class="py-2 hover:text-indigo-600 transition">Home</a>
                <a href="jobs.php" class="py-2 text-indigo-600 font-semibold">Browse Jobs</a>
                <?php if ($isLoggedIn && $userType == 'seeker'): ?>
                <a href="dashboard.php" class="py-2 hover:text-indigo-600 transition">Dashboard</a>
                <a href="saved-jobs.php" class="py-2 hover:text-indigo-600 transition">Saved Jobs</a>
                <?php endif; ?>
                <?php if (!$isLoggedIn): ?>
                <div class="flex space-x-4 pt-4 border-t dark:border-gray-700">
                    <a href="../login.php" class="flex-1 text-center px-4 py-2 border border-indigo-600 text-indigo-600 rounded-xl">Login</a>
                    <a href="../register.php" class="flex-1 text-center px-4 py-2 bg-gradient-to-r from-indigo-600 to-purple-600 text-white rounded-xl">Sign Up</a>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</nav>

<!-- Page Header -->
<section class="pt-32 pb-12 bg-gradient-to-r from-indigo-600 to-purple-600 text-white relative overflow-hidden">
    <!-- Animated Background -->
    <div class="absolute inset-0">
        <div class="absolute top-20 left-10 w-96 h-96 bg-white/10 rounded-full blur-3xl animate-float"></div>
        <div class="absolute bottom-20 right-10 w-[500px] h-[500px] bg-purple-500/10 rounded-full blur-3xl animate-float" style="animation-delay: 2s"></div>
    </div>
    
    <div class="container mx-auto px-4 relative z-10">
        <div class="max-w-4xl mx-auto text-center">
            <h1 class="text-4xl md:text-5xl font-bold mb-6" data-aos="fade-down">
                Find Your <span class="text-yellow-300">Dream Job</span>
            </h1>
            <p class="text-xl text-indigo-100 mb-8" data-aos="fade-down" data-aos-delay="100">
                Browse <?php echo number_format($totalJobs); ?>+ opportunities from top employers worldwide
            </p>
            
            <!-- Quick Search Form -->
            <form action="jobs.php" method="GET" class="glass rounded-3xl p-4" data-aos="fade-up" data-aos-delay="200">
                <div class="flex flex-col md:flex-row gap-4">
                    <div class="flex-1 relative">
                        <i class="fas fa-search absolute left-4 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                        <input type="text" 
                               name="keywords" 
                               value="<?php echo htmlspecialchars($filters['keywords']); ?>"
                               placeholder="Job title, skills, or company..."
                               class="w-full pl-12 pr-4 py-4 bg-white/90 dark:bg-gray-800/90 rounded-xl border-0 focus:ring-2 focus:ring-indigo-500 text-gray-900 dark:text-white placeholder-gray-500">
                    </div>
                    <div class="md:w-64">
                        <select name="category" class="w-full px-4 py-4 bg-white/90 dark:bg-gray-800/90 rounded-xl border-0 focus:ring-2 focus:ring-indigo-500 text-gray-900 dark:text-white">
                            <option value="">All Categories</option>
                            <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo htmlspecialchars($cat['slug']); ?>" <?php echo $filters['category_slug'] == $cat['slug'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cat['name']); ?> (<?php echo $cat['job_count']; ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="md:w-40 bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-700 hover:to-purple-700 text-white font-bold py-4 px-6 rounded-xl transition-all duration-300 transform hover:scale-105 hover:shadow-2xl">
                        <i class="fas fa-search mr-2"></i> Search
                    </button>
                </div>
            </form>
            
            <!-- Active Filters -->
            <?php 
            $activeFilters = [];
            if (!empty($filters['keywords'])) $activeFilters[] = ['type' => 'keywords', 'label' => 'Search: ' . $filters['keywords']];
            if (!empty($filters['category_slug'])) {
                $catName = fetchOne("SELECT name FROM job_categories WHERE slug = ?", [$filters['category_slug']])['name'] ?? '';
                if ($catName) $activeFilters[] = ['type' => 'category', 'label' => $catName];
            }
            if (!empty($filters['job_type_slug'])) {
                $typeName = fetchOne("SELECT name FROM job_types WHERE slug = ?", [$filters['job_type_slug']])['name'] ?? '';
                if ($typeName) $activeFilters[] = ['type' => 'job_type', 'label' => $typeName];
            }
            if (!empty($filters['location_type'])) $activeFilters[] = ['type' => 'location_type', 'label' => ucfirst($filters['location_type'])];
            if (!empty($filters['experience_level'])) $activeFilters[] = ['type' => 'experience_level', 'label' => ucfirst($filters['experience_level'])];
            if (!empty($filters['country'])) $activeFilters[] = ['type' => 'country', 'label' => $filters['country']];
            if (!empty($filters['city'])) $activeFilters[] = ['type' => 'city', 'label' => $filters['city']];
            if ($filters['is_featured']) $activeFilters[] = ['type' => 'featured', 'label' => 'Featured'];
            if ($filters['is_urgent']) $activeFilters[] = ['type' => 'urgent', 'label' => 'Urgent'];
            ?>
            
            <div class="flex flex-wrap items-center justify-center gap-3 mt-6">
                <?php if (!empty($activeFilters)): ?>
                    <span class="text-sm text-white/80">Active Filters:</span>
                    <?php foreach ($activeFilters as $filter): ?>
                    <span class="filter-tag">
                        <?php echo htmlspecialchars($filter['label']); ?>
                        <a href="?<?php 
                            $newParams = $_GET;
                            unset($newParams[$filter['type']]);
                            if ($filter['type'] == 'category') unset($newParams['category_id']);
                            if ($filter['type'] == 'job_type') unset($newParams['job_type_id']);
                            echo http_build_query($newParams);
                        ?>" class="remove ml-2">
                            <i class="fas fa-times"></i>
                        </a>
                    </span>
                    <?php endforeach; ?>
                    
                    <?php if (count($activeFilters) > 1): ?>
                    <a href="jobs.php" class="text-sm text-white hover:underline bg-white/20 px-4 py-2 rounded-full">
                        <i class="fas fa-times-circle mr-1"></i> Clear All
                    </a>
                    <?php endif; ?>
                <?php endif; ?>
                
                <!-- Job Alert Button -->
                <?php if ($isLoggedIn && $userType == 'seeker' && !empty($filters)): ?>
                <button onclick="createJobAlert()" class="text-sm bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-full transition-all duration-300 flex items-center">
                    <i class="fas fa-bell mr-2"></i> Get Email Alerts
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<!-- Main Content -->
<section class="py-12">
    <div class="container mx-auto px-4">
        <div class="flex flex-col lg:flex-row gap-8">
            <!-- Filters Sidebar -->
            <div class="lg:w-1/4 filters-sidebar bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6 h-fit lg:sticky lg:top-24 no-print" id="filtersSidebar">
                <div class="flex items-center justify-between mb-6">
                    <h3 class="text-xl font-bold">
                        <i class="fas fa-sliders-h mr-2 text-indigo-600"></i> Filters
                    </h3>
                    <button onclick="toggleFilters()" class="lg:hidden text-gray-500 hover:text-gray-700">
                        <i class="fas fa-times text-2xl"></i>
                    </button>
                </div>
                
                <form action="jobs.php" method="GET" id="filterForm">
                    <!-- Preserve existing search keywords -->
                    <?php if (!empty($filters['keywords'])): ?>
                    <input type="hidden" name="keywords" value="<?php echo htmlspecialchars($filters['keywords']); ?>">
                    <?php endif; ?>
                    
                    <!-- Categories -->
                    <div class="filter-section mb-8">
                        <h4 class="font-semibold mb-4 flex items-center justify-between cursor-pointer" onclick="toggleSection('categories')">
                            <span><i class="fas fa-tags mr-2 text-indigo-600"></i> Categories</span>
                            <i class="fas fa-chevron-down transition-transform" id="chevron-categories"></i>
                        </h4>
                        <div id="section-categories" class="space-y-3 max-h-60 overflow-y-auto pr-2">
                            <?php foreach ($categories as $cat): ?>
                            <label class="flex items-center space-x-3 cursor-pointer group">
                                <input type="checkbox" 
                                       name="category_id" 
                                       value="<?php echo $cat['id']; ?>"
                                       <?php echo $filters['category_id'] == $cat['id'] ? 'checked' : ''; ?>
                                       class="filter-checkbox">
                                <span class="flex-1 text-sm group-hover:text-indigo-600 transition">
                                    <?php echo htmlspecialchars($cat['name']); ?>
                                </span>
                                <span class="text-xs bg-gray-100 dark:bg-gray-700 px-2 py-1 rounded-full">
                                    <?php echo $cat['job_count']; ?>
                                </span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <!-- Job Types -->
                    <div class="filter-section mb-8">
                        <h4 class="font-semibold mb-4 flex items-center justify-between cursor-pointer" onclick="toggleSection('types')">
                            <span><i class="fas fa-briefcase mr-2 text-indigo-600"></i> Job Type</span>
                            <i class="fas fa-chevron-down transition-transform" id="chevron-types"></i>
                        </h4>
                        <div id="section-types" class="space-y-3">
                            <?php foreach ($jobTypes as $type): ?>
                            <label class="flex items-center space-x-3 cursor-pointer group">
                                <input type="checkbox" 
                                       name="job_type_id" 
                                       value="<?php echo $type['id']; ?>"
                                       <?php echo $filters['job_type_id'] == $type['id'] ? 'checked' : ''; ?>
                                       class="filter-checkbox">
                                <span class="flex-1 text-sm group-hover:text-indigo-600 transition">
                                    <?php echo htmlspecialchars($type['name']); ?>
                                </span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <!-- Location Type -->
                    <div class="filter-section mb-8">
                        <h4 class="font-semibold mb-4 flex items-center justify-between cursor-pointer" onclick="toggleSection('locationType')">
                            <span><i class="fas fa-map-marker-alt mr-2 text-indigo-600"></i> Work Mode</span>
                            <i class="fas fa-chevron-down transition-transform" id="chevron-locationType"></i>
                        </h4>
                        <div id="section-locationType" class="space-y-3">
                            <?php 
                            $locationTypes = ['onsite' => 'On-site', 'remote' => 'Remote', 'hybrid' => 'Hybrid'];
                            foreach ($locationTypes as $value => $label): 
                            ?>
                            <label class="flex items-center space-x-3 cursor-pointer group">
                                <input type="checkbox" 
                                       name="location_type" 
                                       value="<?php echo $value; ?>"
                                       <?php echo $filters['location_type'] == $value ? 'checked' : ''; ?>
                                       class="filter-checkbox">
                                <span class="flex-1 text-sm group-hover:text-indigo-600 transition">
                                    <?php echo $label; ?>
                                </span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <!-- Experience Level -->
                    <div class="filter-section mb-8">
                        <h4 class="font-semibold mb-4 flex items-center justify-between cursor-pointer" onclick="toggleSection('experience')">
                            <span><i class="fas fa-chart-line mr-2 text-indigo-600"></i> Experience Level</span>
                            <i class="fas fa-chevron-down transition-transform" id="chevron-experience"></i>
                        </h4>
                        <div id="section-experience" class="space-y-3">
                            <?php 
                            $expLevels = ['entry' => 'Entry Level', 'mid' => 'Mid Level', 'senior' => 'Senior Level', 'executive' => 'Executive'];
                            foreach ($expLevels as $value => $label): 
                            ?>
                            <label class="flex items-center space-x-3 cursor-pointer group">
                                <input type="checkbox" 
                                       name="experience_level" 
                                       value="<?php echo $value; ?>"
                                       <?php echo $filters['experience_level'] == $value ? 'checked' : ''; ?>
                                       class="filter-checkbox">
                                <span class="flex-1 text-sm group-hover:text-indigo-600 transition">
                                    <?php echo $label; ?>
                                </span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <!-- Salary Range - Simplified -->
                    <div class="filter-section mb-8">
                        <h4 class="font-semibold mb-4">Salary Range</h4>
                        <div class="space-y-3">
                            <input type="number" 
                                   name="salary_min" 
                                   placeholder="Min Salary"
                                   value="<?php echo $filters['salary_min']; ?>"
                                   class="w-full px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600">
                            <input type="number" 
                                   name="salary_max" 
                                   placeholder="Max Salary"
                                   value="<?php echo $filters['salary_max']; ?>"
                                   class="w-full px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600">
                        </div>
                    </div>
                    
                    <!-- Countries -->
                    <?php if (!empty($countries)): ?>
                    <div class="filter-section mb-8">
                        <h4 class="font-semibold mb-4 flex items-center justify-between cursor-pointer" onclick="toggleSection('countries')">
                            <span><i class="fas fa-globe mr-2 text-indigo-600"></i> Country</span>
                            <i class="fas fa-chevron-down transition-transform" id="chevron-countries"></i>
                        </h4>
                        <div id="section-countries" class="space-y-3 max-h-60 overflow-y-auto pr-2">
                            <?php foreach ($countries as $country): ?>
                            <?php if (!empty($country['country'])): ?>
                            <label class="flex items-center space-x-3 cursor-pointer group">
                                <input type="checkbox" 
                                       name="country" 
                                       value="<?php echo htmlspecialchars($country['country']); ?>"
                                       <?php echo $filters['country'] == $country['country'] ? 'checked' : ''; ?>
                                       class="filter-checkbox">
                                <span class="flex-1 text-sm group-hover:text-indigo-600 transition">
                                    <?php echo htmlspecialchars($country['country']); ?>
                                </span>
                                <span class="text-xs bg-gray-100 dark:bg-gray-700 px-2 py-1 rounded-full">
                                    <?php echo $country['job_count']; ?>
                                </span>
                            </label>
                            <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Special Filters -->
                    <div class="filter-section mb-8">
                        <h4 class="font-semibold mb-4">Special Filters</h4>
                        <div class="space-y-3">
                            <label class="flex items-center space-x-3 cursor-pointer">
                                <input type="checkbox" name="featured" value="1" <?php echo $filters['is_featured'] ? 'checked' : ''; ?> class="filter-checkbox">
                                <span class="text-sm">Featured Jobs Only</span>
                            </label>
                            <label class="flex items-center space-x-3 cursor-pointer">
                                <input type="checkbox" name="urgent" value="1" <?php echo $filters['is_urgent'] ? 'checked' : ''; ?> class="filter-checkbox">
                                <span class="text-sm">Urgent Hiring</span>
                            </label>
                        </div>
                    </div>
                    
                    <!-- Sort By -->
                    <div class="filter-section mb-8">
                        <h4 class="font-semibold mb-4">Sort By</h4>
                        <select name="sort" class="w-full px-4 py-3 border rounded-xl dark:bg-gray-700 dark:border-gray-600">
                            <option value="latest" <?php echo $filters['sort'] == 'latest' ? 'selected' : ''; ?>>Latest Jobs</option>
                            <option value="oldest" <?php echo $filters['sort'] == 'oldest' ? 'selected' : ''; ?>>Oldest First</option>
                            <option value="salary_high" <?php echo $filters['sort'] == 'salary_high' ? 'selected' : ''; ?>>Highest Salary</option>
                            <option value="salary_low" <?php echo $filters['sort'] == 'salary_low' ? 'selected' : ''; ?>>Lowest Salary</option>
                            <option value="deadline" <?php echo $filters['sort'] == 'deadline' ? 'selected' : ''; ?>>Closing Soon</option>
                        </select>
                    </div>
                    
                    <!-- Results per page -->
                    <div class="filter-section mb-8">
                        <h4 class="font-semibold mb-4">Results Per Page</h4>
                        <select name="per_page" class="w-full px-4 py-3 border rounded-xl dark:bg-gray-700 dark:border-gray-600">
                            <option value="12" <?php echo $perPage == 12 ? 'selected' : ''; ?>>12 per page</option>
                            <option value="24" <?php echo $perPage == 24 ? 'selected' : ''; ?>>24 per page</option>
                            <option value="48" <?php echo $perPage == 48 ? 'selected' : ''; ?>>48 per page</option>
                        </select>
                    </div>
                    
                    <!-- Action Buttons -->
                    <div class="flex gap-3">
                        <button type="submit" class="flex-1 bg-gradient-to-r from-indigo-600 to-purple-600 text-white py-3 px-4 rounded-xl font-semibold hover:shadow-xl transition-all duration-300">
                            <i class="fas fa-filter mr-2"></i> Apply Filters
                        </button>
                        <a href="jobs.php" class="w-12 h-12 border-2 border-indigo-600 text-indigo-600 rounded-xl flex items-center justify-center hover:bg-indigo-50 dark:hover:bg-gray-700 transition-all duration-300">
                            <i class="fas fa-redo-alt"></i>
                        </a>
                    </div>
                </form>
            </div>
            
            <!-- Jobs List -->
            <div class="lg:w-3/4">
                <!-- Results Summary -->
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6 mb-6">
                    <div class="flex flex-col md:flex-row items-center justify-between">
                        <div>
                            <h2 class="text-2xl font-bold">
                                <?php echo number_format($totalJobs); ?> Jobs Found
                            </h2>
                            <p class="text-gray-600 dark:text-gray-400 mt-1">
                                <?php if (!empty($filters['keywords'])): ?>
                                    for "<?php echo htmlspecialchars($filters['keywords']); ?>"
                                <?php endif; ?>
                                <?php if (!empty($filters['category_slug'])): ?>
                                    in <?php echo htmlspecialchars($filters['category_slug']); ?>
                                <?php endif; ?>
                            </p>
                        </div>
                        
                        <!-- Mobile: Show/Hide Filters -->
                        <button onclick="toggleFilters()" class="lg:hidden mt-4 md:mt-0 px-6 py-3 border border-indigo-600 text-indigo-600 rounded-xl font-semibold hover:bg-indigo-50 dark:hover:bg-gray-700 transition">
                            <i class="fas fa-sliders-h mr-2"></i> Show Filters
                        </button>
                    </div>
                </div>
                
                <!-- Jobs Grid -->
                <?php if (empty($jobs)): ?>
                    <!-- No Results -->
                    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-12 text-center" data-aos="fade-up">
                        <div class="w-32 h-32 bg-gray-100 dark:bg-gray-700 rounded-full flex items-center justify-center mx-auto mb-6">
                            <i class="fas fa-search text-4xl text-gray-400"></i>
                        </div>
                        <h3 class="text-2xl font-bold mb-4">No Jobs Found</h3>
                        <p class="text-gray-600 dark:text-gray-400 mb-8 max-w-md mx-auto">
                            We couldn't find any jobs matching your criteria. Try adjusting your filters or search terms.
                        </p>
                        
                        <!-- Debug info - remove in production -->
                        <?php if ($debug_info['total_jobs_in_db'] == 0): ?>
                        <div class="mb-8 p-4 bg-yellow-100 dark:bg-yellow-900 rounded-lg">
                            <p class="text-yellow-800 dark:text-yellow-200">
                                <strong>Note:</strong> No jobs found in database. Please add some jobs first.
                            </p>
                        </div>
                        <?php endif; ?>
                        
                        <div class="flex flex-col sm:flex-row gap-4 justify-center">
                            <a href="jobs.php" class="px-8 py-4 bg-gradient-to-r from-indigo-600 to-purple-600 text-white rounded-xl font-semibold hover:shadow-xl transition-all duration-300">
                                <i class="fas fa-redo-alt mr-2"></i> Clear All Filters
                            </a>
                            <a href="#popular-searches" class="px-8 py-4 border-2 border-indigo-600 text-indigo-600 rounded-xl font-semibold hover:bg-indigo-50 dark:hover:bg-gray-700 transition-all duration-300">
                                <i class="fas fa-fire mr-2"></i> Popular Searches
                            </a>
                        </div>
                        
                        <!-- Popular Searches -->
                        <div id="popular-searches" class="mt-12">
                            <h4 class="font-semibold mb-4">Popular Searches</h4>
                            <div class="flex flex-wrap gap-3 justify-center">
                                <?php foreach (array_slice($categories, 0, 8) as $cat): ?>
                                <a href="jobs.php?category=<?php echo urlencode($cat['slug']); ?>" 
                                   class="px-4 py-2 bg-gray-100 dark:bg-gray-700 rounded-full text-sm hover:bg-indigo-100 dark:hover:bg-indigo-900 transition">
                                    <?php echo htmlspecialchars($cat['name']); ?>
                                </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- Job Cards -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <?php foreach ($jobs as $index => $job): ?>
                        <div class="job-card bg-white dark:bg-gray-800 rounded-2xl shadow-xl overflow-hidden hover-card relative"
                             data-aos="fade-up" 
                             data-aos-delay="<?php echo ($index % 6) * 50; ?>"
                             data-job-id="<?php echo $job['id']; ?>">
                            
                            <!-- Badges -->
                            <div class="absolute top-4 right-4 flex space-x-2 z-10">
                                <?php if (isset($job['is_urgent']) && $job['is_urgent']): ?>
                                <span class="badge-urgent px-3 py-1 rounded-full text-xs font-bold animate-pulse">URGENT</span>
                                <?php elseif (isset($job['is_featured']) && $job['is_featured']): ?>
                                <span class="badge-featured px-3 py-1 rounded-full text-xs font-bold">FEATURED</span>
                                <?php endif; ?>
                                
                                <?php if (isset($job['created_at']) && strtotime($job['created_at']) > strtotime('-3 days')): ?>
                                <span class="badge-new px-3 py-1 rounded-full text-xs font-bold">NEW</span>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Save Button -->
                            <?php if ($isLoggedIn && $userType == 'seeker'): ?>
                            <button onclick="toggleSaveJob(<?php echo $job['id']; ?>, this)" 
                                    class="save-btn absolute top-4 left-4 w-10 h-10 bg-white dark:bg-gray-700 rounded-full shadow-lg flex items-center justify-center z-10 <?php echo in_array($job['id'], $savedJobIds) ? 'saved' : ''; ?>">
                                <i class="fas fa-heart <?php echo in_array($job['id'], $savedJobIds) ? 'text-red-500' : 'text-gray-400'; ?>"></i>
                            </button>
                            <?php endif; ?>
                            
                            <div class="p-6">
                                <!-- Company Logo & Type -->
                                <div class="flex items-start justify-between mb-4">
                                    <?php 
                                    $logoUrl = isset($job['company_logo']) && !empty($job['company_logo']) 
                                        ? htmlspecialchars($job['company_logo']) 
                                        : 'https://ui-avatars.com/api/?name='.urlencode($job['company_name'] ?? 'Company').'&background=4f46e5&color=fff';
                                    ?>
                                    <img src="<?php echo $logoUrl; ?>" 
                                         alt="<?php echo htmlspecialchars($job['company_name'] ?? 'Company'); ?>"
                                         class="w-16 h-16 rounded-xl object-cover border-2 border-gray-200 dark:border-gray-700 group-hover:scale-110 transition-transform duration-300"
                                         onerror="this.src='https://ui-avatars.com/api/?name=<?php echo urlencode($job['company_name'] ?? 'Company'); ?>&background=4f46e5&color=fff'">
                                    <span class="px-3 py-1 bg-indigo-100 dark:bg-indigo-900 text-indigo-600 dark:text-indigo-300 text-sm rounded-full">
                                        <?php echo htmlspecialchars($job['job_type_name'] ?? $job['job_type_slug'] ?? 'Full Time'); ?>
                                    </span>
                                </div>
                                
                                <!-- Job Title -->
                                <h3 class="text-xl font-bold mb-2 hover:text-indigo-600 transition">
                                    <a href="job-details.php?slug=<?php echo htmlspecialchars($job['slug'] ?? $job['id']); ?>">
                                        <?php echo htmlspecialchars($job['title'] ?? 'Untitled Job'); ?>
                                    </a>
                                </h3>
                                
                                <!-- Company Name -->
                                <p class="text-gray-600 dark:text-gray-400 mb-3 flex items-center">
                                    <i class="fas fa-building mr-2 text-indigo-500"></i>
                                    <?php echo htmlspecialchars($job['company_name'] ?? 'Unknown Company'); ?>
                                    <?php if (!empty($job['is_verified'])): ?>
                                    <i class="fas fa-check-circle text-blue-500 ml-2" title="Verified Employer"></i>
                                    <?php endif; ?>
                                </p>
                                
                                <!-- Skills -->
                                <?php if (!empty($jobSkills[$job['id']])): ?>
                                <div class="flex flex-wrap gap-2 mb-4">
                                    <?php foreach (array_slice($jobSkills[$job['id']], 0, 3) as $skill): ?>
                                    <a href="jobs.php?skill=<?php echo urlencode($skill); ?>" 
                                       class="text-xs bg-gray-100 dark:bg-gray-700 px-3 py-1 rounded-full hover:bg-indigo-100 dark:hover:bg-indigo-900 transition">
                                        <?php echo htmlspecialchars($skill); ?>
                                    </a>
                                    <?php endforeach; ?>
                                    <?php if (count($jobSkills[$job['id']]) > 3): ?>
                                    <span class="text-xs text-gray-500">+<?php echo count($jobSkills[$job['id']]) - 3; ?> more</span>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                                
                                <!-- Job Details Grid -->
                                <div class="grid grid-cols-2 gap-3 mb-4">
                                    <div class="text-sm text-gray-600 dark:text-gray-400">
                                        <i class="fas fa-map-marker-alt mr-1 text-indigo-500"></i>
                                        <?php echo ucfirst($job['location_type'] ?? 'On-site'); ?>
                                    </div>
                                    <div class="text-sm text-gray-600 dark:text-gray-400">
                                        <i class="fas fa-briefcase mr-1 text-indigo-500"></i>
                                        <?php echo ucfirst($job['experience_level'] ?? 'Entry'); ?>
                                    </div>
                                    
                                    <?php if (!empty($job['city'])): ?>
                                    <div class="text-sm text-gray-600 dark:text-gray-400 col-span-2">
                                        <i class="fas fa-location-dot mr-1 text-indigo-500"></i>
                                        <?php echo htmlspecialchars($job['city']); ?>
                                        <?php if (!empty($job['country'])): ?>, <?php echo htmlspecialchars($job['country']); ?><?php endif; ?>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($job['salary_min']) && !empty($job['salary_max'])): ?>
                                    <div class="text-sm font-semibold text-green-600 dark:text-green-400 col-span-2">
                                        <i class="fas fa-dollar-sign mr-1"></i>
                                        <?php echo number_format($job['salary_min']); ?> - <?php echo number_format($job['salary_max']); ?> 
                                        <?php echo $job['salary_currency'] ?? 'USD'; ?>/year
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <!-- Card Footer -->
                            <div class="px-6 py-4 bg-gray-50 dark:bg-gray-700 border-t dark:border-gray-600">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center space-x-3">
                                        <span class="text-sm text-gray-500 dark:text-gray-400">
                                            <i class="far fa-clock mr-1"></i>
                                            <?php 
                                            if (!empty($job['created_at'])) {
                                                $timeDiff = time() - strtotime($job['created_at']);
                                                if ($timeDiff < 3600) {
                                                    echo floor($timeDiff / 60) . ' minutes ago';
                                                } elseif ($timeDiff < 86400) {
                                                    echo floor($timeDiff / 3600) . ' hours ago';
                                                } elseif ($timeDiff < 172800) {
                                                    echo 'Yesterday';
                                                } else {
                                                    echo floor($timeDiff / 86400) . ' days ago';
                                                }
                                            } else {
                                                echo 'Recently';
                                            }
                                            ?>
                                        </span>
                                        
                                        <?php if (!empty($job['application_deadline'])): ?>
                                        <?php 
                                        $daysLeft = floor((strtotime($job['application_deadline']) - time()) / 86400);
                                        if ($daysLeft > 0 && $daysLeft <= 7): 
                                        ?>
                                        <span class="text-xs <?php echo $daysLeft <= 3 ? 'text-red-500' : 'text-orange-500'; ?>">
                                            <i class="fas fa-hourglass-half mr-1"></i>
                                            <?php echo $daysLeft; ?> days left
                                        </span>
                                        <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="flex items-center space-x-2">
                                        <span class="text-xs text-gray-500">
                                            <i class="fas fa-users mr-1"></i>
                                            <?php echo $job['application_count'] ?? 0; ?> applied
                                        </span>
                                        <a href="job-details.php?slug=<?php echo htmlspecialchars($job['slug'] ?? $job['id']); ?>" 
                                           class="text-indigo-600 dark:text-indigo-400 hover:underline inline-flex items-center">
                                            View <i class="fas fa-arrow-right ml-1 text-xs"></i>
                                        </a>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Quick Apply Overlay -->
                            <?php if ($isLoggedIn && $userType == 'seeker'): ?>
                            <div class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-indigo-600 to-transparent p-6 transform translate-y-full group-hover:translate-y-0 transition-transform duration-300">
                                <button onclick="quickApply(<?php echo $job['id']; ?>, '<?php echo htmlspecialchars(addslashes($job['title'])); ?>')" 
                                        class="w-full bg-white text-indigo-600 py-3 rounded-xl font-semibold hover:shadow-lg transition-all duration-300">
                                    <i class="fas fa-bolt mr-2"></i> Quick Apply
                                </button>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                    <div class="mt-12 flex justify-center">
                        <nav class="inline-flex rounded-xl shadow-lg bg-white dark:bg-gray-800 p-2" aria-label="Pagination">
                            <?php if ($page > 1): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" 
                               class="px-4 py-3 text-gray-500 hover:text-indigo-600 rounded-lg transition">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                            <?php endif; ?>
                            
                            <?php
                            $start = max(1, $page - 2);
                            $end = min($totalPages, $page + 2);
                            
                            if ($start > 1) {
                                echo '<a href="?'.http_build_query(array_merge($_GET, ['page' => 1])).'" class="px-4 py-3 text-gray-500 hover:text-indigo-600 rounded-lg transition">1</a>';
                                if ($start > 2) echo '<span class="px-4 py-3 text-gray-400">...</span>';
                            }
                            
                            for ($i = $start; $i <= $end; $i++):
                            ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" 
                               class="px-4 py-3 <?php echo $i == $page ? 'bg-indigo-600 text-white' : 'text-gray-500 hover:text-indigo-600'; ?> rounded-lg transition">
                                <?php echo $i; ?>
                            </a>
                            <?php endfor; ?>
                            
                            <?php if ($end < $totalPages): ?>
                                <?php if ($end < $totalPages - 1) echo '<span class="px-4 py-3 text-gray-400">...</span>'; ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $totalPages])); ?>" 
                                   class="px-4 py-3 text-gray-500 hover:text-indigo-600 rounded-lg transition">
                                    <?php echo $totalPages; ?>
                                </a>
                            <?php endif; ?>
                            
                            <?php if ($page < $totalPages): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" 
                               class="px-4 py-3 text-gray-500 hover:text-indigo-600 rounded-lg transition">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                            <?php endif; ?>
                        </nav>
                    </div>
                    
                    <!-- Results Info -->
                    <div class="text-center text-sm text-gray-500 mt-4">
                        Showing <?php echo $offset + 1; ?> - <?php echo min($offset + $perPage, $totalJobs); ?> of <?php echo $totalJobs; ?> jobs
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<!-- Recommended Jobs Section -->
<?php if (!empty($recommendedJobs)): ?>
<section class="py-12 bg-gray-100 dark:bg-gray-800 no-print">
    <div class="container mx-auto px-4">
        <div class="flex items-center justify-between mb-8">
            <div>
                <h2 class="text-2xl md:text-3xl font-bold mb-2">
                    <i class="fas fa-star text-yellow-400 mr-2"></i> Recommended For You
                </h2>
                <p class="text-gray-600 dark:text-gray-400">Personalized job suggestions based on your profile</p>
            </div>
            <?php if ($isLoggedIn): ?>
            <a href="dashboard.php?tab=recommendations" class="text-indigo-600 dark:text-indigo-400 hover:underline">
                View All <i class="fas fa-arrow-right ml-1"></i>
            </a>
            <?php endif; ?>
        </div>
        
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5 gap-4">
            <?php foreach ($recommendedJobs as $job): ?>
            <div class="bg-white dark:bg-gray-700 rounded-xl shadow-lg p-4 hover:shadow-xl transition-all duration-300">
                <div class="flex items-start space-x-3 mb-3">
                    <?php 
                    $logoUrl = isset($job['company_logo']) && !empty($job['company_logo']) 
                        ? htmlspecialchars($job['company_logo']) 
                        : 'https://ui-avatars.com/api/?name='.urlencode($job['company_name'] ?? 'Company').'&background=4f46e5&color=fff';
                    ?>
                    <img src="<?php echo $logoUrl; ?>" 
                         alt="" 
                         class="w-10 h-10 rounded-lg object-cover">
                    <div class="flex-1 min-w-0">
                        <a href="job-details.php?slug=<?php echo htmlspecialchars($job['slug']); ?>" class="font-semibold hover:text-indigo-600 transition truncate block">
                            <?php echo htmlspecialchars($job['title']); ?>
                        </a>
                        <p class="text-xs text-gray-600 dark:text-gray-400 truncate"><?php echo htmlspecialchars($job['company_name']); ?></p>
                    </div>
                </div>
                <div class="flex items-center justify-between text-xs text-gray-500">
                    <span><i class="fas fa-map-marker-alt mr-1 text-indigo-500"></i> <?php echo ucfirst($job['location_type'] ?? 'On-site'); ?></span>
                    <span><i class="far fa-clock mr-1"></i> 
                        <?php 
                        if (!empty($job['created_at'])) {
                            $hours = floor((time() - strtotime($job['created_at'])) / 3600);
                            echo $hours < 24 ? $hours . 'h' : floor($hours/24) . 'd';
                        } else {
                            echo 'New';
                        }
                        ?>
                    </span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- Popular Skills Cloud -->
<?php if (!empty($popularSkills)): ?>
<section class="py-12 bg-white dark:bg-gray-900 no-print">
    <div class="container mx-auto px-4">
        <h2 class="text-2xl md:text-3xl font-bold mb-8 text-center">
            <i class="fas fa-cloud mr-2 text-indigo-600"></i> Popular Skills
        </h2>
        
        <div class="flex flex-wrap justify-center gap-3 max-w-4xl mx-auto">
            <?php foreach ($popularSkills as $skill): ?>
            <a href="jobs.php?skill=<?php echo urlencode($skill['skill_name']); ?>" 
               class="group relative px-6 py-3 bg-gray-100 dark:bg-gray-800 rounded-full hover:bg-indigo-600 dark:hover:bg-indigo-600 transition-all duration-300 hover:scale-110 hover:shadow-xl"
               style="font-size: <?php echo max(0.8, min(2, 0.8 + ($skill['job_count'] / 50))); ?>rem;">
                <span class="group-hover:text-white transition"><?php echo htmlspecialchars($skill['skill_name']); ?></span>
                <span class="absolute -top-2 -right-2 bg-indigo-600 text-white text-xs w-5 h-5 rounded-full flex items-center justify-center opacity-0 group-hover:opacity-100 transition">
                    <?php echo $skill['job_count']; ?>
                </span>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- Quick Apply Modal -->
<div class="modal" id="quickApplyModal">
    <div class="modal-content bg-white dark:bg-gray-800 rounded-3xl shadow-2xl p-8">
        <div class="flex items-center justify-between mb-6">
            <h3 class="text-2xl font-bold">Quick Apply</h3>
            <button onclick="closeModal()" class="text-gray-500 hover:text-gray-700 dark:hover:text-gray-300">
                <i class="fas fa-times text-2xl"></i>
            </button>
        </div>
        
        <form id="quickApplyForm" action="apply-job.php" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="job_id" id="applyJobId">
            
            <div class="space-y-6">
                <!-- Cover Letter -->
                <div>
                    <label class="block font-semibold mb-2">Cover Letter (Optional)</label>
                    <textarea name="cover_letter" rows="4" 
                              class="w-full px-4 py-3 border rounded-xl dark:bg-gray-700 dark:border-gray-600 focus:ring-2 focus:ring-indigo-500"
                              placeholder="Tell the employer why you're a great fit..."></textarea>
                </div>
                
                <!-- Resume Upload -->
                <div>
                    <label class="block font-semibold mb-2">Resume/CV</label>
                    <div class="border-2 border-dashed rounded-xl p-6 text-center hover:border-indigo-500 transition cursor-pointer"
                         onclick="document.getElementById('resumeUpload').click()">
                        <i class="fas fa-cloud-upload-alt text-4xl text-gray-400 mb-3"></i>
                        <p class="text-gray-600 dark:text-gray-400">Click to upload or drag and drop</p>
                        <p class="text-xs text-gray-500 mt-2">PDF, DOC, DOCX up to 5MB</p>
                        <input type="file" id="resumeUpload" name="resume" accept=".pdf,.doc,.docx" class="hidden">
                    </div>
                </div>
                
                <!-- Expected Salary -->
                <div>
                    <label class="block font-semibold mb-2">Expected Salary (Optional)</label>
                    <div class="flex items-center space-x-3">
                        <input type="number" name="expected_salary" placeholder="Amount" 
                               class="flex-1 px-4 py-3 border rounded-xl dark:bg-gray-700 dark:border-gray-600">
                        <select name="salary_currency" class="w-24 px-4 py-3 border rounded-xl dark:bg-gray-700 dark:border-gray-600">
                            <option value="USD">USD</option>
                            <option value="EUR">EUR</option>
                            <option value="GBP">GBP</option>
                            <option value="CNY">CNY</option>
                        </select>
                    </div>
                </div>
                
                <!-- Notice Period -->
                <div>
                    <label class="block font-semibold mb-2">Notice Period</label>
                    <select name="notice_period" class="w-full px-4 py-3 border rounded-xl dark:bg-gray-700 dark:border-gray-600">
                        <option value="0">Immediately</option>
                        <option value="15">15 days</option>
                        <option value="30">30 days</option>
                        <option value="45">45 days</option>
                        <option value="60">60 days</option>
                        <option value="90">90 days</option>
                    </select>
                </div>
                
                <!-- Submit Button -->
                <button type="submit" class="w-full bg-gradient-to-r from-indigo-600 to-purple-600 text-white py-4 rounded-xl font-semibold hover:shadow-xl transition-all duration-300">
                    <i class="fas fa-paper-plane mr-2"></i> Submit Application
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Footer -->
<footer class="bg-gray-900 text-white pt-20 pb-8 no-print">
    <div class="container mx-auto px-4">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-12 mb-16">
            <!-- About -->
            <div class="lg:col-span-2">
                <div class="flex items-center space-x-3 mb-6">
                    <div class="w-14 h-14 bg-gradient-to-r from-indigo-600 to-purple-600 rounded-xl flex items-center justify-center">
                        <i class="fas fa-globe-asia text-white text-2xl"></i>
                    </div>
                    <span class="text-3xl font-bold">
                        <span class="gradient-text">TransNow</span>
                    </span>
                </div>
                <p class="text-gray-400 mb-6 text-lg leading-relaxed">
                    Connecting Chinese students and international talents with premier global opportunities. Your career journey starts here.
                </p>
                <div class="flex space-x-4">
                    <a href="#" class="w-12 h-12 bg-gray-800 rounded-xl flex items-center justify-center hover:bg-indigo-600 transition-all duration-300 hover:scale-110">
                        <i class="fab fa-weixin text-xl"></i>
                    </a>
                    <a href="#" class="w-12 h-12 bg-gray-800 rounded-xl flex items-center justify-center hover:bg-indigo-600 transition-all duration-300 hover:scale-110">
                        <i class="fab fa-weibo text-xl"></i>
                    </a>
                    <a href="#" class="w-12 h-12 bg-gray-800 rounded-xl flex items-center justify-center hover:bg-indigo-600 transition-all duration-300 hover:scale-110">
                        <i class="fab fa-linkedin-in text-xl"></i>
                    </a>
                    <a href="#" class="w-12 h-12 bg-gray-800 rounded-xl flex items-center justify-center hover:bg-indigo-600 transition-all duration-300 hover:scale-110">
                        <i class="fab fa-twitter text-xl"></i>
                    </a>
                </div>
            </div>
            
            <!-- For Job Seekers -->
            <div>
                <h3 class="text-xl font-bold mb-6">For Job Seekers</h3>
                <ul class="space-y-4 text-gray-400">
                    <li><a href="jobs.php" class="hover:text-white transition flex items-center group">
                        <i class="fas fa-chevron-right mr-2 text-indigo-500 text-sm group-hover:translate-x-2 transition-transform"></i>
                        Browse Jobs
                    </a></li>
                    <li><a href="dashboard.php" class="hover:text-white transition flex items-center group">
                        <i class="fas fa-chevron-right mr-2 text-indigo-500 text-sm group-hover:translate-x-2 transition-transform"></i>
                        Dashboard
                    </a></li>
                    <li><a href="profile.php" class="hover:text-white transition flex items-center group">
                        <i class="fas fa-chevron-right mr-2 text-indigo-500 text-sm group-hover:translate-x-2 transition-transform"></i>
                        Profile
                    </a></li>
                    <li><a href="saved-jobs.php" class="hover:text-white transition flex items-center group">
                        <i class="fas fa-chevron-right mr-2 text-indigo-500 text-sm group-hover:translate-x-2 transition-transform"></i>
                        Saved Jobs
                    </a></li>
                </ul>
            </div>
            
            <!-- For Employers -->
            <div>
                <h3 class="text-xl font-bold mb-6">For Employers</h3>
                <ul class="space-y-4 text-gray-400">
                    <li><a href="../employer/post-job.php" class="hover:text-white transition flex items-center group">
                        <i class="fas fa-chevron-right mr-2 text-indigo-500 text-sm group-hover:translate-x-2 transition-transform"></i>
                        Post a Job
                    </a></li>
                    <li><a href="../employer/dashboard.php" class="hover:text-white transition flex items-center group">
                        <i class="fas fa-chevron-right mr-2 text-indigo-500 text-sm group-hover:translate-x-2 transition-transform"></i>
                        Dashboard
                    </a></li>
                    <li><a href="../employer/candidates.php" class="hover:text-white transition flex items-center group">
                        <i class="fas fa-chevron-right mr-2 text-indigo-500 text-sm group-hover:translate-x-2 transition-transform"></i>
                        Browse Candidates
                    </a></li>
                </ul>
            </div>
            
            <!-- Support -->
            <div>
                <h3 class="text-xl font-bold mb-6">Support</h3>
                <ul class="space-y-4 text-gray-400">
                    <li><a href="#" class="hover:text-white transition flex items-center group">
                        <i class="fas fa-chevron-right mr-2 text-indigo-500 text-sm group-hover:translate-x-2 transition-transform"></i>
                        Help Center
                    </a></li>
                    <li><a href="#" class="hover:text-white transition flex items-center group">
                        <i class="fas fa-chevron-right mr-2 text-indigo-500 text-sm group-hover:translate-x-2 transition-transform"></i>
                        FAQs
                    </a></li>
                    <li><a href="#" class="hover:text-white transition flex items-center group">
                        <i class="fas fa-chevron-right mr-2 text-indigo-500 text-sm group-hover:translate-x-2 transition-transform"></i>
                        Privacy Policy
                    </a></li>
                    <li><a href="#" class="hover:text-white transition flex items-center group">
                        <i class="fas fa-chevron-right mr-2 text-indigo-500 text-sm group-hover:translate-x-2 transition-transform"></i>
                        Contact Us
                    </a></li>
                </ul>
            </div>
        </div>
        
        <!-- Bottom Bar -->
        <div class="border-t border-gray-800 pt-8 mt-8">
            <div class="flex flex-col md:flex-row justify-between items-center text-gray-400">
                <p>&copy; <?php echo date('Y'); ?> TransNow. All rights reserved.</p>
                <p class="mt-4 md:mt-0">Made with <i class="fas fa-heart text-red-500 mx-2"></i> for global talents</p>
                <p class="mt-4 md:mt-0 text-sm chinese-text">留学生求职平台 | 国际人才招聘</p>
            </div>
        </div>
    </div>
</footer>

<!-- Scripts -->
<script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

<script>
// Initialize AOS
AOS.init({
    duration: 800,
    once: true,
    offset: 50
});

// Navbar scroll effect
const navbar = document.getElementById('navbar');
window.addEventListener('scroll', () => {
    if (window.scrollY > 50) {
        navbar.classList.add('bg-white/90', 'dark:bg-gray-900/90', 'backdrop-blur-lg', 'shadow-lg');
    } else {
        navbar.classList.remove('bg-white/90', 'dark:bg-gray-900/90', 'backdrop-blur-lg', 'shadow-lg');
    }
});

// Dark Mode Toggle
const darkModeToggle = document.getElementById('darkModeToggle');
const darkModeIcon = document.getElementById('darkModeIcon');

if (localStorage.theme === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
    document.documentElement.classList.add('dark');
    darkModeIcon.classList.remove('fa-moon');
    darkModeIcon.classList.add('fa-sun');
} else {
    document.documentElement.classList.remove('dark');
    darkModeIcon.classList.remove('fa-sun');
    darkModeIcon.classList.add('fa-moon');
}

darkModeToggle.addEventListener('click', () => {
    document.documentElement.classList.toggle('dark');
    
    if (document.documentElement.classList.contains('dark')) {
        localStorage.theme = 'dark';
        darkModeIcon.classList.remove('fa-moon');
        darkModeIcon.classList.add('fa-sun');
    } else {
        localStorage.theme = 'light';
        darkModeIcon.classList.remove('fa-sun');
        darkModeIcon.classList.add('fa-moon');
    }
});

// Mobile Menu
const mobileMenuBtn = document.getElementById('mobileMenuBtn');
const mobileMenu = document.getElementById('mobileMenu');

mobileMenuBtn.addEventListener('click', () => {
    mobileMenu.classList.toggle('hidden');
    const icon = mobileMenuBtn.querySelector('i');
    icon.classList.toggle('fa-bars');
    icon.classList.toggle('fa-times');
});

// Toggle Filters Sidebar (Mobile)
function toggleFilters() {
    const sidebar = document.getElementById('filtersSidebar');
    sidebar.classList.toggle('active');
}

// Toggle Filter Sections
function toggleSection(section) {
    const content = document.getElementById(`section-${section}`);
    const chevron = document.getElementById(`chevron-${section}`);
    
    if (content.style.display === 'none') {
        content.style.display = 'block';
        chevron.style.transform = 'rotate(0deg)';
    } else {
        content.style.display = 'none';
        chevron.style.transform = 'rotate(-90deg)';
    }
}

// Initialize filter sections
document.addEventListener('DOMContentLoaded', function() {
    const sections = ['categories', 'types', 'locationType', 'experience', 'countries'];
    sections.forEach(section => {
        const content = document.getElementById(`section-${section}`);
        const chevron = document.getElementById(`chevron-${section}`);
        if (content && chevron) {
            content.style.display = 'block';
            chevron.style.transform = 'rotate(0deg)';
        }
    });
});

// Save/Unsave Job
function toggleSaveJob(jobId, btn) {
    <?php if (!$isLoggedIn): ?>
    window.location.href = '../login.php?redirect=' + encodeURIComponent(window.location.href);
    return;
    <?php endif; ?>
    
    const icon = btn.querySelector('i');
    const isSaved = btn.classList.contains('saved');
    
    // Simulate success for demo - replace with actual AJAX call
    btn.classList.toggle('saved');
    if (btn.classList.contains('saved')) {
        icon.classList.remove('text-gray-400');
        icon.classList.add('text-red-500');
        showNotification('Job saved successfully!', 'success');
    } else {
        icon.classList.remove('text-red-500');
        icon.classList.add('text-gray-400');
        showNotification('Job removed from saved', 'info');
    }
    
    /* Uncomment for actual AJAX implementation
    fetch('ajax/toggle-save-job.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'job_id=' + jobId + '&action=' + (isSaved ? 'unsave' : 'save')
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            btn.classList.toggle('saved');
            if (btn.classList.contains('saved')) {
                icon.classList.remove('text-gray-400');
                icon.classList.add('text-red-500');
                showNotification('Job saved successfully!', 'success');
            } else {
                icon.classList.remove('text-red-500');
                icon.classList.add('text-gray-400');
                showNotification('Job removed from saved', 'info');
            }
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('An error occurred', 'error');
    });
    */
}

// Create Job Alert
function createJobAlert() {
    <?php if (!$isLoggedIn): ?>
    window.location.href = '../login.php?redirect=' + encodeURIComponent(window.location.href);
    return;
    <?php endif; ?>
    
    showNotification('Job alert created! You\'ll receive email notifications for new matches.', 'success');
}

// Quick Apply Modal
function quickApply(jobId, jobTitle) {
    <?php if (!$isLoggedIn): ?>
    window.location.href = '../login.php?redirect=' + encodeURIComponent(window.location.href);
    return;
    <?php endif; ?>
    
    document.getElementById('applyJobId').value = jobId;
    document.getElementById('quickApplyModal').classList.add('active');
    showNotification('Quick apply for: ' + jobTitle, 'info');
}

function closeModal() {
    document.getElementById('quickApplyModal').classList.remove('active');
}

// Close modal when clicking outside
window.addEventListener('click', function(event) {
    const modal = document.getElementById('quickApplyModal');
    if (event.target === modal) {
        closeModal();
    }
});

// Notification System
function showNotification(message, type = 'info') {
    const notification = document.createElement('div');
    notification.className = `fixed top-24 right-4 z-50 px-6 py-4 rounded-xl shadow-2xl transform transition-all duration-500 translate-x-0 ${
        type === 'success' ? 'bg-green-500' : 
        type === 'error' ? 'bg-red-500' : 
        'bg-indigo-600'
    } text-white`;
    notification.innerHTML = `
        <div class="flex items-center space-x-3">
            <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'} text-xl"></i>
            <span>${message}</span>
        </div>
    `;
    
    document.body.appendChild(notification);
    
    setTimeout(() => {
        notification.style.transform = 'translateX(400px)';
        setTimeout(() => notification.remove(), 500);
    }, 3000);
}

// Debug function - remove in production
function showDebug() {
    document.getElementById('debugPanel').style.display = 'block';
}

// Add keyboard shortcut for debug (Ctrl+Shift+D)
document.addEventListener('keydown', function(e) {
    if (e.ctrlKey && e.shiftKey && e.key === 'D') {
        e.preventDefault();
        showDebug();
    }
    
    // ESC to close modal
    if (e.key === 'Escape') {
        closeModal();
    }
    
    // Ctrl+K to focus search
    if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
        e.preventDefault();
        document.querySelector('input[name="keywords"]')?.focus();
    }
});
</script>

<!-- Structured Data for SEO -->
<script type="application/ld+json">
{
    "@context": "https://schema.org",
    "@type": "SearchResultsPage",
    "name": "Job Search Results",
    "description": "Browse <?php echo $totalJobs; ?> job opportunities",
    "url": "<?php echo 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $_SERVER['REQUEST_URI']; ?>",
    "numberOfItems": <?php echo $totalJobs; ?>
}
</script>
</body>
</html>