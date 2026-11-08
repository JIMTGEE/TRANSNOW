<?php
/**
 * jobs.php - TransNow Ghana Jobs Listing Page
 * Version: 2.2 - Fixed for Actual Database Schema
 */

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/functions.php';

// Start session only if not already started (functions.php already starts it)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

try {
    $pdo = getDB();
} catch (Exception $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Get filter parameters from URL
$search = trim($_GET['search'] ?? '');
$location = trim($_GET['location'] ?? '');
$category = $_GET['category'] ?? '';
$employment_type = $_GET['employment_type'] ?? '';
$experience_level = $_GET['experience_level'] ?? '';
$location_type = $_GET['location_type'] ?? '';
$salary_min = $_GET['salary_min'] ?? '';
$salary_max = $_GET['salary_max'] ?? '';
$is_urgent = isset($_GET['is_urgent']) ? 1 : 0;
$is_featured = isset($_GET['is_featured']) ? 1 : 0;
$sort = $_GET['sort'] ?? 'newest';
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 12;
$offset = ($page - 1) * $per_page;
$view_mode = $_SESSION['job_view_mode'] ?? 'grid';
$saved_jobs = $_SESSION['saved_jobs'] ?? [];

// Handle view mode toggle
if (isset($_GET['view_mode']) && in_array($_GET['view_mode'], ['grid', 'list'])) {
    $view_mode = $_GET['view_mode'];
    $_SESSION['job_view_mode'] = $view_mode;
}

// Handle save/unsave job
if (isset($_GET['save_job']) && is_numeric($_GET['save_job'])) {
    $job_id = (int)$_GET['save_job'];
    if (in_array($job_id, $saved_jobs)) {
        $saved_jobs = array_diff($saved_jobs, [$job_id]);
        $save_message = 'Job removed from saved list';
    } else {
        $saved_jobs[] = $job_id;
        $save_message = 'Job saved successfully';
    }
    $_SESSION['saved_jobs'] = $saved_jobs;
}

// Build the WHERE clause for filtering
$where_clauses = ["j.status = 'Active'"];
$params = [];

if (!empty($search)) {
    $where_clauses[] = "(j.title LIKE ? OR j.overview LIKE ? OR j.description LIKE ? OR j.job_category LIKE ? OR j.city LIKE ?)";
    $search_param = "%$search%";
    $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param, $search_param]);
}

if (!empty($location)) {
    $where_clauses[] = "(j.city LIKE ? OR j.state LIKE ? OR j.country LIKE ? OR j.address LIKE ?)";
    $location_param = "%$location%";
    $params = array_merge($params, [$location_param, $location_param, $location_param, $location_param]);
}

if (!empty($category)) {
    $where_clauses[] = "j.job_category = ?";
    $params[] = $category;
}

if (!empty($employment_type)) {
    $where_clauses[] = "j.employment_type = ?";
    $params[] = $employment_type;
}

if (!empty($experience_level)) {
    $where_clauses[] = "j.experience_level = ?";
    $params[] = $experience_level;
}

if (!empty($location_type)) {
    $where_clauses[] = "j.location_type = ?";
    $params[] = $location_type;
}

if (!empty($salary_min)) {
    $where_clauses[] = "j.salary_min >= ?";
    $params[] = floatval($salary_min);
}

if (!empty($salary_max)) {
    $where_clauses[] = "j.salary_max <= ?";
    $params[] = floatval($salary_max);
}

if ($is_urgent) {
    $where_clauses[] = "j.is_urgent = 1";
}

if ($is_featured) {
    $where_clauses[] = "j.is_featured = 1";
}

$where_sql = implode(' AND ', $where_clauses);

// Build ORDER BY clause
switch ($sort) {
    case 'oldest':
        $order_sql = "j.created_at ASC";
        break;
    case 'salary_high':
        $order_sql = "COALESCE(j.salary_max, j.salary_min) DESC";
        break;
    case 'salary_low':
        $order_sql = "COALESCE(j.salary_min, j.salary_max) ASC";
        break;
    case 'urgent':
        $order_sql = "j.is_urgent DESC, j.is_featured DESC, j.created_at DESC";
        break;
    case 'popular':
        $order_sql = "j.total_views DESC, j.created_at DESC";
        break;
    default:
        $order_sql = "j.is_featured DESC, j.created_at DESC";
}

// Get total count for pagination
try {
    $count_sql = "SELECT COUNT(*) as total FROM jobs j WHERE $where_sql";
    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->execute($params);
    $total_jobs = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
} catch (PDOException $e) {
    $total_jobs = 0;
    error_log("Count query error: " . $e->getMessage());
}

$total_pages = ceil($total_jobs / $per_page);

// Fetch jobs with pagination - NO company_name column (doesn't exist in your table)
$jobs = [];
try {
    $jobs_sql = "
        SELECT j.*, 
               (SELECT COUNT(*) FROM comments WHERE job_id = j.id AND is_deleted = 0) as comment_count
        FROM jobs j
        WHERE $where_sql
        ORDER BY $order_sql
        LIMIT $per_page OFFSET $offset
    ";
    $jobs_stmt = $pdo->prepare($jobs_sql);
    $jobs_stmt->execute($params);
    $jobs = $jobs_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Jobs query error: " . $e->getMessage());
    $jobs = [];
}

// Get featured jobs for sidebar (is_featured column exists but may not be used yet)
$featured_jobs = [];
try {
    $featured_sql = "
        SELECT id, title, slug, salary_min, salary_max, salary_currency, salary_type, location_type, city, employment_type, job_category
        FROM jobs 
        WHERE status = 'Active' 
        ORDER BY total_views DESC, created_at DESC 
        LIMIT 5
    ";
    $featured_stmt = $pdo->query($featured_sql);
    $featured_jobs = $featured_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Featured jobs query error: " . $e->getMessage());
}

// Get all filter options for dynamic filtering
$filter_options = [];
try {
    // Get categories with counts
    $cat_sql = "
        SELECT job_category, COUNT(*) as count 
        FROM jobs 
        WHERE status = 'Active' AND job_category IS NOT NULL AND job_category != 'Other'
        GROUP BY job_category 
        ORDER BY count DESC
    ";
    $filter_options['categories'] = $pdo->query($cat_sql)->fetchAll(PDO::FETCH_ASSOC);
    
    // Get locations with counts
    $loc_sql = "
        SELECT city, COUNT(*) as count 
        FROM jobs 
        WHERE status = 'Active' AND city IS NOT NULL AND city != '' 
        GROUP BY city 
        ORDER BY count DESC 
        LIMIT 15
    ";
    $filter_options['locations'] = $pdo->query($loc_sql)->fetchAll(PDO::FETCH_ASSOC);
    
    // Get employment types with counts
    $emp_sql = "
        SELECT employment_type, COUNT(*) as count 
        FROM jobs 
        WHERE status = 'Active' AND employment_type IS NOT NULL 
        GROUP BY employment_type
    ";
    $filter_options['employment_types'] = $pdo->query($emp_sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Filter options query error: " . $e->getMessage());
}

// Get salary range for filter
$salary_stats = ['min' => 0, 'max' => 50000];
try {
    $salary_sql = "SELECT MIN(salary_min) as min_salary, MAX(salary_max) as max_salary FROM jobs WHERE status = 'Active'";
    $salary_stmt = $pdo->query($salary_sql);
    $salary_data = $salary_stmt->fetch(PDO::FETCH_ASSOC);
    if ($salary_data) {
        $salary_stats['min'] = floor($salary_data['min_salary'] ?? 0);
        $salary_stats['max'] = ceil($salary_data['max_salary'] ?? 50000);
    }
} catch (PDOException $e) {
    error_log("Salary stats query error: " . $e->getMessage());
}

// Helper function for time ago
function timeAgo($datetime) {
    if (empty($datetime)) return 'Recently';
    $time = strtotime($datetime);
    $diff = time() - $time;
    
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . ' min ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hour' . (floor($diff / 3600) > 1 ? 's' : '') . ' ago';
    if ($diff < 604800) return floor($diff / 86400) . ' day' . (floor($diff / 86400) > 1 ? 's' : '') . ' ago';
    if ($diff < 2592000) return floor($diff / 604800) . ' week' . (floor($diff / 604800) > 1 ? 's' : '') . ' ago';
    return date('M d, Y', $time);
}

// Helper function for pagination URLs
function buildPaginationUrl($page) {
    $params = $_GET;
    $params['page'] = $page;
    return '?' . http_build_query($params);
}

// Helper function to build URL with preserved filters
function buildFilterUrl($params, $reset_page = true) {
    $current = $_GET;
    if ($reset_page) {
        unset($current['page']);
    }
    $merged = array_merge($current, $params);
    return '?' . http_build_query($merged);
}

// Helper function to format salary
function formatSalaryDisplay($min, $max, $currency, $type) {
    if (!$min && !$max) return 'Salary not disclosed';
    
    $currency_symbol = $currency === 'GHS' ? '₵' : ($currency === 'USD' ? '$' : $currency);
    $type_display = $type === 'Monthly' ? 'month' : ($type === 'Yearly' ? 'year' : 'hour');
    
    if ($min && $max && $min != $max) {
        return $currency_symbol . number_format($min) . ' - ' . $currency_symbol . number_format($max) . ' / ' . $type_display;
    } elseif ($min) {
        return $currency_symbol . number_format($min) . ' / ' . $type_display;
    } elseif ($max) {
        return 'Up to ' . $currency_symbol . number_format($max) . ' / ' . $type_display;
    }
    return 'Salary not disclosed';
}

// Helper function to get company name from job data (since no company_name column)
function getCompanyDisplay($job) {
    // Extract company from description or overview if available
    $description = $job['description'] ?? '';
    $overview = $job['overview'] ?? '';
    
    // Look for common company indicators
    if (preg_match('/Company:\s*([^\n]+)/i', $description, $matches)) {
        return trim($matches[1]);
    }
    if (preg_match('/Company:\s*([^\n]+)/i', $overview, $matches)) {
        return trim($matches[1]);
    }
    
    // Default based on job category
    $defaultCompanies = [
        'Translation' => 'Translation Services',
        'Sales' => 'Sales & Marketing',
        'Education' => 'Education Institution',
        'Administration' => 'Administrative Services',
        'Cooking' => 'Food Services',
        'Driving' => 'Transportation Services',
        'Maintenance' => 'Maintenance Services',
        'Construction' => 'Construction Company',
        'Warehouse' => 'Warehouse Services',
        'Security' => 'Security Services',
        'Office Clerk' => 'Office Services',
        'Secretary' => 'Administrative Office',
        'Customer Service' => 'Customer Care',
        'IT & Technology' => 'IT Solutions',
        'Healthcare' => 'Healthcare Services'
    ];
    
    return $defaultCompanies[$job['job_category']] ?? 'Recruiting Company';
}

// Increment job view count (AJAX endpoint)
if (isset($_GET['ajax_view']) && is_numeric($_GET['ajax_view'])) {
    $job_id = (int)$_GET['ajax_view'];
    try {
        $update_sql = "UPDATE jobs SET total_views = total_views + 1 WHERE id = ?";
        $update_stmt = $pdo->prepare($update_sql);
        $update_stmt->execute([$job_id]);
    } catch (PDOException $e) {
        error_log("View increment error: " . $e->getMessage());
    }
    exit;
}

// DEBUG: Add ?debug=1 to URL to see what's happening
if (isset($_GET['debug'])) {
    echo "<div style='background:#f0f0f0;padding:20px;margin:20px;border:1px solid #ccc;'>";
    echo "<h3>Debug Information</h3>";
    echo "<p>Total Active Jobs: " . $total_jobs . "</p>";
    echo "<p>Jobs fetched: " . count($jobs) . "</p>";
    echo "<pre>";
    echo "SQL Query: " . str_replace($params, '?', $jobs_sql) . "\n\n";
    echo "Jobs Data:\n";
    print_r($jobs);
    echo "</pre>";
    echo "</div>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Browse Jobs in Ghana - Find Your Dream Career | TransNow Ghana</title>
    <meta name="description" content="Search through <?php echo number_format($total_jobs); ?>+ job opportunities across Ghana. Find full-time, part-time, remote, and hybrid jobs with top employers.">
    <meta name="keywords" content="jobs in Ghana, career opportunities, employment Ghana, job search, remote jobs Ghana">
    
    <!-- Open Graph Tags -->
    <meta property="og:title" content="Browse Jobs in Ghana - TransNow Ghana">
    <meta property="og:description" content="Find your dream job with <?php echo number_format($total_jobs); ?>+ opportunities across Ghana">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://trnasnow.com/jobs.php">
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <!-- AOS Animation -->
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    
    <style>
        * { font-family: 'Inter', sans-serif; }
        
        .brand-gradient {
            background: linear-gradient(135deg, #1E3A8A 0%, #2563EB 50%, #3B82F6 100%);
        }
        
        .gradient-text {
            background: linear-gradient(135deg, #1E3A8A, #2563EB, #3B82F6);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }
        
        .job-card {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border: 1px solid #E5E7EB;
            background: white;
            position: relative;
            overflow: hidden;
        }
        
        .job-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, transparent, #3B82F6, #1E3A8A, transparent);
            transition: left 0.5s;
        }
        
        .job-card:hover::before {
            left: 100%;
        }
        
        .job-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 20px 25px -12px rgba(0, 0, 0, 0.15);
            border-color: #3B82F6;
        }
        
        .dark .job-card {
            background: #1F2937;
            border-color: #374151;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #1E3A8A 0%, #2563EB 100%);
            color: white;
            font-weight: 600;
            padding: 0.75rem 1.5rem;
            border-radius: 0.75rem;
            transition: all 0.3s;
            position: relative;
            overflow: hidden;
        }
        
        .btn-primary::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.3);
            transform: translate(-50%, -50%);
            transition: width 0.5s, height 0.5s;
        }
        
        .btn-primary:hover::after {
            width: 200%;
            height: 200%;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px -5px rgba(30, 58, 138, 0.4);
        }
        
        .btn-outline {
            border: 2px solid #E5E7EB;
            color: #4B5563;
            font-weight: 500;
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            transition: all 0.3s;
        }
        
        .btn-outline:hover {
            border-color: #2563EB;
            color: #2563EB;
            transform: translateY(-1px);
        }
        
        .badge-urgent {
            background: linear-gradient(135deg, #FEE2E2, #FECACA);
            color: #DC2626;
            font-size: 0.7rem;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-weight: 700;
            letter-spacing: 0.5px;
        }
        
        .badge-featured {
            background: linear-gradient(135deg, #FEF3C7, #FDE68A);
            color: #D97706;
            font-size: 0.7rem;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-weight: 700;
        }
        
        .badge-remote {
            background: linear-gradient(135deg, #D1FAE5, #A7F3D0);
            color: #065F46;
            font-size: 0.7rem;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-weight: 600;
        }
        
        .badge-category {
            background: linear-gradient(135deg, #E0E7FF, #C7D2FE);
            color: #3730A3;
            font-size: 0.7rem;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-weight: 600;
        }
        
        .pagination-link {
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            transition: all 0.2s;
            color: #4B5563;
            font-weight: 500;
        }
        
        .pagination-link:hover {
            background: #EFF6FF;
            color: #2563EB;
            transform: translateY(-1px);
        }
        
        .pagination-link.active {
            background: linear-gradient(135deg, #1E3A8A 0%, #2563EB 100%);
            color: white;
            box-shadow: 0 4px 6px -1px rgba(37, 99, 235, 0.3);
        }
        
        .filter-section {
            border-bottom: 1px solid #E5E7EB;
            padding-bottom: 1rem;
            margin-bottom: 1rem;
        }
        
        .dark .filter-section {
            border-color: #374151;
        }
        
        .salary-slider {
            -webkit-appearance: none;
            width: 100%;
            height: 4px;
            border-radius: 5px;
            background: #E5E7EB;
            outline: none;
        }
        
        .salary-slider::-webkit-slider-thumb {
            -webkit-appearance: none;
            width: 16px;
            height: 16px;
            border-radius: 50%;
            background: #2563EB;
            cursor: pointer;
            box-shadow: 0 2px 6px rgba(37, 99, 235, 0.3);
        }
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .fade-in-up {
            animation: fadeInUp 0.6s ease-out;
        }
        
        .toast-notification {
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 1000;
            animation: slideInRight 0.3s ease-out;
        }
        
        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
        
        .scroll-top {
            position: fixed;
            bottom: 30px;
            right: 30px;
            z-index: 99;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s;
        }
        
        .scroll-top.show {
            opacity: 1;
            visibility: visible;
        }
        
        @media (max-width: 1023px) {
            .filter-sidebar {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                max-width: 320px;
                height: 100vh;
                background: white;
                z-index: 1000;
                transform: translateX(-100%);
                transition: transform 0.3s ease;
                overflow-y: auto;
                box-shadow: 2px 0 8px rgba(0, 0, 0, 0.1);
            }
            
            .filter-sidebar.open {
                transform: translateX(0);
            }
            
            .dark .filter-sidebar {
                background: #1F2937;
            }
        }
        
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: #F3F4F6;
        }
        
        ::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, #2563EB, #1E3A8A);
            border-radius: 8px;
        }
        
        .dark ::-webkit-scrollbar-track {
            background: #374151;
        }
        
        .job-list-item {
            transition: all 0.3s ease;
        }
        
        .job-list-item:hover {
            background: #F9FAFB;
            transform: translateX(4px);
        }
        
        .dark .job-list-item:hover {
            background: #374151;
        }
        
        .line-clamp-2 {
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        
        .line-clamp-1 {
            display: -webkit-box;
            -webkit-line-clamp: 1;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
    </style>
</head>
<body class="bg-gray-50 dark:bg-gray-900">

<!-- Toast Notification -->
<?php if (isset($save_message)): ?>
<div id="toastNotification" class="toast-notification bg-green-500 text-white px-6 py-3 rounded-lg shadow-lg">
    <i class="fas fa-check-circle mr-2"></i>
    <?php echo htmlspecialchars($save_message); ?>
</div>
<script>
    setTimeout(() => {
        const toast = document.getElementById('toastNotification');
        if (toast) toast.remove();
    }, 3000);
</script>
<?php endif; ?>

<!-- Navigation -->
<nav class="sticky top-0 z-40 bg-white/95 dark:bg-gray-900/95 backdrop-blur-md shadow-sm">
    <div class="container mx-auto px-4">
        <div class="flex justify-between items-center py-4">
            <a href="/" class="flex items-center space-x-3 group">
                <div class="w-10 h-10 brand-gradient rounded-xl flex items-center justify-center transform group-hover:scale-110 transition">
                    <i class="fas fa-briefcase text-white text-xl"></i>
                </div>
                <span class="text-2xl font-bold gradient-text">TransNow Ghana</span>
            </a>
            
            <div class="hidden md:flex items-center space-x-8">
                <a href="/" class="nav-link text-gray-700 dark:text-gray-200 font-medium hover:text-blue-600 transition">Home</a>
                <a href="/jobs.php" class="nav-link text-blue-600 font-medium border-b-2 border-blue-600">Find Jobs</a>
                <a href="/testimonials.php" class="nav-link text-gray-700 dark:text-gray-200 font-medium hover:text-blue-600 transition">Success Stories</a>
                <a href="/blog.php" class="block py-2 px-4 rounded-lg bg-blue-50 dark:bg-blue-900/30 text-[#2563EB]">Career Advice</a>
                <a href="/about.php" class="nav-link text-gray-700 dark:text-gray-200 font-medium hover:text-blue-600 transition">About</a>
            </div>
            
            <div class="flex items-center space-x-4">
                <button id="darkModeToggle" class="p-2 rounded-lg bg-gray-100 dark:bg-gray-800 hover:scale-110 transition">
                    <i class="fas fa-moon text-gray-600 dark:text-gray-300" id="darkModeIcon"></i>
                </button>
                <a href="/login.php" class="hidden sm:inline-flex items-center space-x-2 px-5 py-2.5 border-2 border-blue-600 text-blue-600 rounded-xl font-medium hover:bg-blue-600 hover:text-white transition-all">
                    <i class="fas fa-user-shield"></i>
                    <span>Admin</span>
                </a>
                <button id="mobileMenuBtn" class="md:hidden p-2 rounded-lg bg-gray-100 dark:bg-gray-800">
                    <i class="fas fa-bars text-gray-600 dark:text-gray-300"></i>
                </button>
            </div>
        </div>
        
        <div id="mobileMenu" class="md:hidden hidden pb-4 space-y-3">
            <a href="/" class="block py-2 px-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-800">Home</a>
            <a href="/jobs.php" class="block py-2 px-4 bg-blue-50 dark:bg-blue-900/30 text-blue-600 rounded-lg font-medium">Find Jobs</a>
            <a href="/testimonials.php" class="block py-2 px-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-800">Success Stories</a>
            <a href="/blog.php" class="block py-2 px-4 rounded-lg bg-blue-50 dark:bg-blue-900/30 text-[#2563EB]">Career Advice</a>
            <a href="/about.php" class="block py-2 px-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-800">About</a>
        </div>
    </div>
</nav>

<!-- Hero Section with Advanced Search -->
<section class="brand-gradient py-12 relative overflow-hidden">
    <div class="absolute inset-0 bg-black opacity-10"></div>
    <div class="absolute -top-40 -right-40 w-80 h-80 bg-white rounded-full opacity-10"></div>
    <div class="absolute -bottom-40 -left-40 w-80 h-80 bg-white rounded-full opacity-10"></div>
    
    <div class="container mx-auto px-4 relative z-10">
        <div class="max-w-4xl mx-auto text-center" data-aos="fade-up">
            <h1 class="text-3xl md:text-5xl font-bold text-white mb-4">
                <i class="fas fa-briefcase mr-3"></i>
                Find Your Dream Job
            </h1>
            <p class="text-white/90 text-lg mb-8">
                Discover <span class="font-bold"><?php echo number_format($total_jobs); ?></span>+ opportunities from top companies across Ghana
            </p>
            
            <!-- Advanced Search Bar -->
            <form method="GET" action="" class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl p-2 flex flex-col md:flex-row gap-2">
                <div class="flex-1 relative group">
                    <i class="fas fa-search absolute left-4 top-1/2 transform -translate-y-1/2 text-gray-400 group-hover:text-blue-500 transition"></i>
                    <input type="text" name="search" placeholder="Job title, skills, or keywords..." 
                           value="<?php echo htmlspecialchars($search); ?>"
                           class="w-full pl-12 pr-4 py-4 bg-transparent border-none focus:outline-none text-gray-900 dark:text-white placeholder-gray-400 rounded-xl">
                </div>
                <div class="flex-1 relative group">
                    <i class="fas fa-map-marker-alt absolute left-4 top-1/2 transform -translate-y-1/2 text-gray-400 group-hover:text-blue-500 transition"></i>
                    <input type="text" name="location" placeholder="City, region, or remote..." 
                           value="<?php echo htmlspecialchars($location); ?>"
                           class="w-full pl-12 pr-4 py-4 bg-transparent border-none focus:outline-none text-gray-900 dark:text-white placeholder-gray-400 rounded-xl">
                </div>
                <button type="submit" class="btn-primary flex items-center justify-center space-x-2 whitespace-nowrap px-8">
                    <i class="fas fa-search"></i>
                    <span>Search Jobs</span>
                </button>
            </form>
            
            <!-- Popular Searches -->
            <div class="mt-6 flex flex-wrap justify-center gap-2">
                <span class="text-white/70 text-sm">Popular:</span>
                <?php 
                $popular_searches = ['Remote', 'Full-time', 'Translation', 'Sales', 'IT'];
                foreach ($popular_searches as $term): 
                ?>
                <a href="<?php echo buildFilterUrl(['search' => $term]); ?>" class="text-white/80 hover:text-white text-sm bg-white/10 px-3 py-1 rounded-full transition">
                    <?php echo $term; ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</section>

<!-- Main Content -->
<section class="py-8">
    <div class="container mx-auto px-4">
        <div class="flex flex-col lg:flex-row gap-6">
            
            <!-- Mobile Filter Toggle -->
            <div class="lg:hidden flex items-center justify-between mb-4">
                <button id="mobileFilterToggle" class="btn-outline flex items-center space-x-2 px-4 py-2">
                    <i class="fas fa-sliders-h"></i>
                    <span>Filters</span>
                    <?php 
                    $active_filters = array_filter([$category, $employment_type, $experience_level, $location_type, $salary_min, $salary_max]);
                    if (count($active_filters) > 0): 
                    ?>
                    <span class="bg-blue-500 text-white text-xs rounded-full px-2 py-0.5"><?php echo count($active_filters); ?></span>
                    <?php endif; ?>
                </button>
                <div class="flex space-x-2">
                    <button onclick="toggleViewMode('grid')" class="p-2 rounded-lg <?php echo $view_mode === 'grid' ? 'bg-blue-100 text-blue-600 dark:bg-blue-900/30' : 'bg-gray-100 dark:bg-gray-800'; ?>">
                        <i class="fas fa-th"></i>
                    </button>
                    <button onclick="toggleViewMode('list')" class="p-2 rounded-lg <?php echo $view_mode === 'list' ? 'bg-blue-100 text-blue-600 dark:bg-blue-900/30' : 'bg-gray-100 dark:bg-gray-800'; ?>">
                        <i class="fas fa-list"></i>
                    </button>
                    <select id="mobileSortSelect" class="btn-outline py-2 px-3" onchange="window.location.href=this.value">
                        <option value="<?php echo buildFilterUrl(['sort' => 'newest']); ?>" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>Newest</option>
                        <option value="<?php echo buildFilterUrl(['sort' => 'popular']); ?>" <?php echo $sort === 'popular' ? 'selected' : ''; ?>>Popular</option>
                        <option value="<?php echo buildFilterUrl(['sort' => 'salary_high']); ?>" <?php echo $sort === 'salary_high' ? 'selected' : ''; ?>>Salary (High)</option>
                        <option value="<?php echo buildFilterUrl(['sort' => 'salary_low']); ?>" <?php echo $sort === 'salary_low' ? 'selected' : ''; ?>>Salary (Low)</option>
                        <option value="<?php echo buildFilterUrl(['sort' => 'urgent']); ?>" <?php echo $sort === 'urgent' ? 'selected' : ''; ?>>Urgent First</option>
                    </select>
                </div>
            </div>
            
            <!-- Advanced Filter Sidebar -->
            <aside class="lg:w-80 flex-shrink-0">
                <div id="filterSidebar" class="filter-sidebar lg:static lg:translate-x-0 lg:block lg:h-auto lg:overflow-visible">
                    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm">
                        <div class="p-5 border-b border-gray-200 dark:border-gray-700">
                            <div class="flex items-center justify-between">
                                <h3 class="text-xl font-bold gradient-text">
                                    <i class="fas fa-filter mr-2"></i>Filters
                                </h3>
                                <button id="closeFilterDrawer" class="lg:hidden p-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                            <?php if (count($active_filters) > 0): ?>
                            <a href="/jobs.php" class="text-sm text-blue-600 hover:text-blue-700 mt-2 inline-block">
                                <i class="fas fa-times-circle"></i> Clear all filters
                            </a>
                            <?php endif; ?>
                        </div>
                        
                        <form method="GET" action="" id="filterForm" class="p-5">
                            <!-- Preserve search params -->
                            <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
                            <input type="hidden" name="location" value="<?php echo htmlspecialchars($location); ?>">
                            
                            <!-- Job Category Filter -->
                            <div class="filter-section">
                                <label class="block font-semibold text-gray-900 dark:text-white mb-3">
                                    <i class="fas fa-tags mr-2 text-blue-500"></i>Job Category
                                </label>
                                <select name="category" class="w-full p-3 border border-gray-200 dark:border-gray-700 rounded-xl bg-gray-50 dark:bg-gray-700 focus:ring-2 focus:ring-blue-500 transition" onchange="this.form.submit()">
                                    <option value="">All Categories</option>
                                    <?php foreach ($filter_options['categories'] as $cat): ?>
                                    <option value="<?php echo htmlspecialchars($cat['job_category']); ?>" <?php echo $category === $cat['job_category'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cat['job_category']); ?> (<?php echo $cat['count']; ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <!-- Employment Type Filter -->
                            <div class="filter-section">
                                <label class="block font-semibold text-gray-900 dark:text-white mb-3">
                                    <i class="fas fa-clock mr-2 text-blue-500"></i>Employment Type
                                </label>
                                <div class="space-y-2">
                                    <?php 
                                    $types = ['Full-time', 'Part-time', 'Contract', 'Freelance', 'Internship'];
                                    foreach ($types as $type):
                                        $type_count = 0;
                                        foreach ($filter_options['employment_types'] as $et) {
                                            if ($et['employment_type'] === $type) {
                                                $type_count = $et['count'];
                                                break;
                                            }
                                        }
                                    ?>
                                    <label class="flex items-center justify-between cursor-pointer group">
                                        <div class="flex items-center space-x-3">
                                            <input type="radio" name="employment_type" value="<?php echo $type; ?>" 
                                                   <?php echo $employment_type === $type ? 'checked' : ''; ?>
                                                   class="w-4 h-4 text-blue-600 focus:ring-blue-500" onchange="this.form.submit()">
                                            <span class="text-gray-700 dark:text-gray-300 group-hover:text-blue-600 transition"><?php echo $type; ?></span>
                                        </div>
                                        <?php if ($type_count > 0): ?>
                                        <span class="text-xs text-gray-400"><?php echo $type_count; ?></span>
                                        <?php endif; ?>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            
                            <!-- Experience Level Filter -->
                            <div class="filter-section">
                                <label class="block font-semibold text-gray-900 dark:text-white mb-3">
                                    <i class="fas fa-chart-line mr-2 text-blue-500"></i>Experience Level
                                </label>
                                <div class="grid grid-cols-2 gap-2">
                                    <?php 
                                    $levels = ['Entry' => '🌱 Entry', 'Mid' => '📈 Mid', 'Senior' => '🎯 Senior', 'Executive' => '👔 Executive'];
                                    foreach ($levels as $value => $label):
                                    ?>
                                    <label class="flex items-center space-x-2 cursor-pointer group">
                                        <input type="radio" name="experience_level" value="<?php echo $value; ?>" 
                                               <?php echo $experience_level === $value ? 'checked' : ''; ?>
                                               class="w-4 h-4 text-blue-600" onchange="this.form.submit()">
                                        <span class="text-sm text-gray-700 dark:text-gray-300 group-hover:text-blue-600 transition"><?php echo $label; ?></span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            
                            <!-- Location Type Filter -->
                            <div class="filter-section">
                                <label class="block font-semibold text-gray-900 dark:text-white mb-3">
                                    <i class="fas fa-map-pin mr-2 text-blue-500"></i>Work Location
                                </label>
                                <div class="space-y-2">
                                    <?php 
                                    $locations = ['Onsite' => '🏢 On-site', 'Remote' => '🏠 Remote', 'Hybrid' => '🔄 Hybrid'];
                                    foreach ($locations as $value => $label):
                                    ?>
                                    <label class="flex items-center space-x-3 cursor-pointer group">
                                        <input type="radio" name="location_type" value="<?php echo $value; ?>" 
                                               <?php echo $location_type === $value ? 'checked' : ''; ?>
                                               class="w-4 h-4 text-blue-600" onchange="this.form.submit()">
                                        <span class="text-gray-700 dark:text-gray-300 group-hover:text-blue-600 transition"><?php echo $label; ?></span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            
                            <!-- Salary Range Filter -->
                            <div class="filter-section">
                                <label class="block font-semibold text-gray-900 dark:text-white mb-3">
                                    <i class="fas fa-money-bill-wave mr-2 text-blue-500"></i>Salary Range (GHS)
                                </label>
                                <div class="space-y-3">
                                    <div class="flex items-center justify-between gap-2">
                                        <div class="relative flex-1">
                                            <span class="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400">₵</span>
                                            <input type="number" name="salary_min" placeholder="Min" value="<?php echo htmlspecialchars($salary_min); ?>"
                                                   class="w-full pl-7 pr-3 py-2 border border-gray-200 dark:border-gray-700 rounded-lg bg-gray-50 dark:bg-gray-700">
                                        </div>
                                        <span class="text-gray-400">-</span>
                                        <div class="relative flex-1">
                                            <span class="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400">₵</span>
                                            <input type="number" name="salary_max" placeholder="Max" value="<?php echo htmlspecialchars($salary_max); ?>"
                                                   class="w-full pl-7 pr-3 py-2 border border-gray-200 dark:border-gray-700 rounded-lg bg-gray-50 dark:bg-gray-700">
                                        </div>
                                    </div>
                                    <input type="range" class="salary-slider w-full" min="<?php echo $salary_stats['min']; ?>" max="<?php echo $salary_stats['max']; ?>" 
                                           value="<?php echo $salary_max ?: $salary_stats['max']; ?>" step="1000" 
                                           onchange="this.form.salary_max.value=this.value; this.form.submit()">
                                    <div class="flex justify-between text-xs text-gray-500">
                                        <span>₵<?php echo number_format($salary_stats['min']); ?></span>
                                        <span>₵<?php echo number_format($salary_stats['max']); ?></span>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Special Filters -->
                            <div class="filter-section">
                                <div class="space-y-3">
                                    <label class="flex items-center justify-between cursor-pointer group">
                                        <div class="flex items-center space-x-3">
                                            <input type="checkbox" name="is_urgent" value="1" <?php echo $is_urgent ? 'checked' : ''; ?>
                                                   class="w-4 h-4 text-blue-600 rounded focus:ring-blue-500" onchange="this.form.submit()">
                                            <span class="text-gray-700 dark:text-gray-300 group-hover:text-blue-600 transition">
                                                <i class="fas fa-bolt text-red-500 mr-1"></i>Urgent Jobs Only
                                            </span>
                                        </div>
                                    </label>
                                    <label class="flex items-center justify-between cursor-pointer group">
                                        <div class="flex items-center space-x-3">
                                            <input type="checkbox" name="is_featured" value="1" <?php echo $is_featured ? 'checked' : ''; ?>
                                                   class="w-4 h-4 text-blue-600 rounded focus:ring-blue-500" onchange="this.form.submit()">
                                            <span class="text-gray-700 dark:text-gray-300 group-hover:text-blue-600 transition">
                                                <i class="fas fa-star text-yellow-500 mr-1"></i>Featured Jobs Only
                                            </span>
                                        </div>
                                    </label>
                                </div>
                            </div>
                            
                            <!-- Filter Actions -->
                            <div class="flex gap-3 mt-4">
                                <button type="submit" class="btn-primary flex-1 py-3">
                                    <i class="fas fa-filter mr-2"></i>Apply Filters
                                </button>
                                <a href="/jobs.php" class="btn-outline flex items-center justify-center px-4 hover:bg-gray-100 dark:hover:bg-gray-700">
                                    <i class="fas fa-undo-alt"></i>
                                </a>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Featured Jobs Sidebar -->
                    <?php if (!empty($featured_jobs)): ?>
                    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5 mt-6">
                        <h4 class="font-bold text-gray-900 dark:text-white mb-4">
                            <i class="fas fa-star text-yellow-500 mr-2"></i>Popular Jobs
                        </h4>
                        <div class="space-y-4">
                            <?php foreach ($featured_jobs as $featured): ?>
                            <a href="/job-detail.php?id=<?php echo $featured['id']; ?>" class="block group">
                                <div class="flex items-start space-x-3">
                                    <div class="w-10 h-10 bg-gradient-to-br from-blue-100 to-blue-200 dark:from-blue-900/30 dark:to-blue-800/30 rounded-lg flex items-center justify-center">
                                        <i class="fas fa-briefcase text-blue-600"></i>
                                    </div>
                                    <div class="flex-1">
                                        <h5 class="font-semibold text-gray-900 dark:text-white group-hover:text-blue-600 transition text-sm">
                                            <?php echo htmlspecialchars($featured['title']); ?>
                                        </h5>
                                        <p class="text-xs text-gray-500"><?php echo htmlspecialchars($featured['job_category'] ?? 'Job'); ?></p>
                                        <p class="text-xs text-green-600 mt-1">
                                            <?php echo formatSalaryDisplay($featured['salary_min'], $featured['salary_max'], $featured['salary_currency'], $featured['salary_type']); ?>
                                        </p>
                                    </div>
                                </div>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Overlay for mobile -->
                <div id="filterOverlay" class="fixed inset-0 bg-black/50 z-45 hidden lg:hidden"></div>
            </aside>
            
            <!-- Job Listings -->
            <main class="flex-1">
                <!-- Desktop Controls -->
                <div class="hidden lg:flex items-center justify-between mb-6 bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-200 dark:border-gray-700">
                    <div class="flex items-center space-x-4">
                        <div class="flex items-center space-x-2">
                            <i class="fas fa-briefcase text-blue-500"></i>
                            <span class="text-gray-600 dark:text-gray-400">
                                Showing <span class="font-semibold text-gray-900 dark:text-white"><?php echo count($jobs); ?></span> of 
                                <span class="font-semibold text-gray-900 dark:text-white"><?php echo number_format($total_jobs); ?></span> jobs
                            </span>
                        </div>
                        <?php if ($search || $location || $category || $employment_type): ?>
                        <div class="flex flex-wrap gap-2">
                            <?php if ($search): ?>
                            <span class="inline-flex items-center px-2 py-1 rounded-md text-xs bg-blue-100 text-blue-700">
                                Search: <?php echo htmlspecialchars($search); ?>
                                <a href="<?php echo buildFilterUrl(['search' => '']); ?>" class="ml-1 hover:text-blue-900">×</a>
                            </span>
                            <?php endif; ?>
                            <?php if ($category): ?>
                            <span class="inline-flex items-center px-2 py-1 rounded-md text-xs bg-blue-100 text-blue-700">
                                <?php echo htmlspecialchars($category); ?>
                                <a href="<?php echo buildFilterUrl(['category' => '']); ?>" class="ml-1 hover:text-blue-900">×</a>
                            </span>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="flex items-center space-x-3">
                        <div class="flex border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">
                            <button onclick="toggleViewMode('grid')" class="px-3 py-1.5 <?php echo $view_mode === 'grid' ? 'bg-blue-500 text-white' : 'bg-white dark:bg-gray-800 text-gray-600'; ?>">
                                <i class="fas fa-th"></i>
                            </button>
                            <button onclick="toggleViewMode('list')" class="px-3 py-1.5 <?php echo $view_mode === 'list' ? 'bg-blue-500 text-white' : 'bg-white dark:bg-gray-800 text-gray-600'; ?>">
                                <i class="fas fa-list"></i>
                            </button>
                        </div>
                        <select id="desktopSortSelect" class="border border-gray-200 dark:border-gray-700 rounded-lg px-3 py-1.5 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300" onchange="window.location.href=this.value">
                            <option value="<?php echo buildFilterUrl(['sort' => 'newest']); ?>" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>Newest First</option>
                            <option value="<?php echo buildFilterUrl(['sort' => 'oldest']); ?>" <?php echo $sort === 'oldest' ? 'selected' : ''; ?>>Oldest First</option>
                            <option value="<?php echo buildFilterUrl(['sort' => 'popular']); ?>" <?php echo $sort === 'popular' ? 'selected' : ''; ?>>Most Popular</option>
                            <option value="<?php echo buildFilterUrl(['sort' => 'salary_high']); ?>" <?php echo $sort === 'salary_high' ? 'selected' : ''; ?>>Salary: High to Low</option>
                            <option value="<?php echo buildFilterUrl(['sort' => 'salary_low']); ?>" <?php echo $sort === 'salary_low' ? 'selected' : ''; ?>>Salary: Low to High</option>
                            <option value="<?php echo buildFilterUrl(['sort' => 'urgent']); ?>" <?php echo $sort === 'urgent' ? 'selected' : ''; ?>>Urgent First</option>
                        </select>
                    </div>
                </div>
                
                <!-- Job Results -->
                <div id="jobResults">
                    <?php if (empty($jobs)): ?>
                    <div class="bg-white dark:bg-gray-800 rounded-2xl p-12 text-center border border-gray-200 dark:border-gray-700" data-aos="fade-up">
                        <div class="w-24 h-24 bg-gray-100 dark:bg-gray-700 rounded-full flex items-center justify-center mx-auto mb-4">
                            <i class="fas fa-search text-4xl text-gray-400"></i>
                        </div>
                        <h3 class="text-xl font-bold mb-2 text-gray-900 dark:text-white">No jobs found</h3>
                        <p class="text-gray-500 dark:text-gray-400 mb-6">Try adjusting your filters or search terms to find more opportunities</p>
                        <div class="flex flex-wrap justify-center gap-3">
                            <a href="/jobs.php" class="btn-primary inline-flex items-center">
                                <i class="fas fa-times mr-2"></i>Clear All Filters
                            </a>
                            <a href="/post-resume.php" class="btn-outline inline-flex items-center">
                                <i class="fas fa-file-upload mr-2"></i>Post Your Resume
                            </a>
                        </div>
                        <div class="mt-8">
                            <p class="text-sm text-gray-500 mb-3">Try these popular searches:</p>
                            <div class="flex flex-wrap justify-center gap-2">
                                <a href="<?php echo buildFilterUrl(['search' => 'Remote']); ?>" class="px-3 py-1 bg-gray-100 dark:bg-gray-700 rounded-full text-sm hover:bg-blue-100 transition">Remote Jobs</a>
                                <a href="<?php echo buildFilterUrl(['employment_type' => 'Full-time']); ?>" class="px-3 py-1 bg-gray-100 dark:bg-gray-700 rounded-full text-sm hover:bg-blue-100 transition">Full-time</a>
                                <a href="<?php echo buildFilterUrl(['category' => 'Translation']); ?>" class="px-3 py-1 bg-gray-100 dark:bg-gray-700 rounded-full text-sm hover:bg-blue-100 transition">Translation</a>
                                <a href="<?php echo buildFilterUrl(['category' => 'Sales']); ?>" class="px-3 py-1 bg-gray-100 dark:bg-gray-700 rounded-full text-sm hover:bg-blue-100 transition">Sales</a>
                            </div>
                        </div>
                    </div>
                    <?php else: ?>
                    
                    <!-- Grid View -->
                    <div id="gridView" class="<?php echo $view_mode === 'grid' ? '' : 'hidden'; ?>">
                        <div class="grid md:grid-cols-2 gap-5">
                            <?php foreach ($jobs as $index => $job): ?>
                            <div class="job-card rounded-xl overflow-hidden" data-aos="fade-up" data-aos-delay="<?php echo $index * 50; ?>">
                                <div class="relative h-1.5 brand-gradient"></div>
                                <div class="p-5">
                                    <!-- Badges -->
                                    <div class="flex flex-wrap gap-2 mb-3">
                                        <?php if ($job['is_urgent']): ?>
                                        <span class="badge-urgent"><i class="fas fa-bolt mr-1"></i>Urgent</span>
                                        <?php endif; ?>
                                        <?php if ($job['is_featured']): ?>
                                        <span class="badge-featured"><i class="fas fa-star mr-1"></i>Featured</span>
                                        <?php endif; ?>
                                        <?php if ($job['location_type'] === 'Remote'): ?>
                                        <span class="badge-remote"><i class="fas fa-home mr-1"></i>Remote</span>
                                        <?php elseif ($job['location_type'] === 'Hybrid'): ?>
                                        <span class="badge-remote"><i class="fas fa-sync-alt mr-1"></i>Hybrid</span>
                                        <?php endif; ?>
                                        <span class="badge-category">
                                            <i class="fas fa-tag mr-1"></i><?php echo htmlspecialchars($job['job_category']); ?>
                                        </span>
                                        <?php if ($job['comment_count'] > 0): ?>
                                        <span class="text-xs text-gray-500 bg-gray-100 dark:bg-gray-700 px-2 py-0.5 rounded-full">
                                            <i class="fas fa-comment mr-1"></i><?php echo $job['comment_count']; ?>
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <!-- Title and Save -->
                                    <div class="flex justify-between items-start mb-1">
                                        <h3 class="font-bold text-lg text-gray-900 dark:text-white hover:text-blue-600 transition flex-1">
                                            <a href="/job-detail.php?id=<?php echo $job['id']; ?>" onclick="trackJobView(<?php echo $job['id']; ?>)">
                                                <?php echo htmlspecialchars($job['title']); ?>
                                            </a>
                                        </h3>
                                        <button onclick="saveJob(<?php echo $job['id']; ?>)" class="text-gray-400 hover:text-yellow-500 transition ml-2">
                                            <i class="far fa-bookmark <?php echo in_array($job['id'], $saved_jobs) ? 'fas fa-bookmark text-yellow-500' : ''; ?>"></i>
                                        </button>
                                    </div>
                                    
                                    <p class="text-gray-600 dark:text-gray-400 text-sm mb-3">
                                        <i class="fas fa-building mr-2 text-blue-500"></i>
                                        <?php echo htmlspecialchars(getCompanyDisplay($job)); ?>
                                    </p>
                                    
                                    <!-- Overview -->
                                    <?php if (!empty($job['overview'])): ?>
                                    <p class="text-sm text-gray-600 dark:text-gray-300 mb-4 line-clamp-2">
                                        <?php echo htmlspecialchars(substr($job['overview'], 0, 120)) . (strlen($job['overview']) > 120 ? '...' : ''); ?>
                                    </p>
                                    <?php else: ?>
                                    <p class="text-sm text-gray-600 dark:text-gray-300 mb-4 line-clamp-2">
                                        <?php echo htmlspecialchars(substr($job['description'], 0, 120)) . (strlen($job['description']) > 120 ? '...' : ''); ?>
                                    </p>
                                    <?php endif; ?>
                                    
                                    <!-- Details Grid -->
                                    <div class="grid grid-cols-2 gap-2 text-sm mb-4">
                                        <div class="flex items-center text-gray-600 dark:text-gray-400">
                                            <i class="fas fa-briefcase w-5 text-blue-500"></i>
                                            <span><?php echo htmlspecialchars($job['employment_type']); ?></span>
                                        </div>
                                        <div class="flex items-center text-gray-600 dark:text-gray-400">
                                            <i class="fas fa-chart-line w-5 text-blue-500"></i>
                                            <span><?php echo htmlspecialchars($job['experience_level']); ?></span>
                                        </div>
                                        <div class="flex items-center text-gray-600 dark:text-gray-400">
                                            <i class="fas fa-map-marker-alt w-5 text-blue-500"></i>
                                            <span><?php echo htmlspecialchars($job['city'] ? $job['city'] . ', Ghana' : 'Ghana'); ?></span>
                                        </div>
                                        <div class="flex items-center text-gray-600 dark:text-gray-400">
                                            <i class="fas fa-clock w-5 text-blue-500"></i>
                                            <span><?php echo timeAgo($job['created_at']); ?></span>
                                        </div>
                                    </div>
                                    
                                    <!-- Salary and Action -->
                                    <div class="flex items-center justify-between pt-4 border-t border-gray-100 dark:border-gray-700">
                                        <div class="text-green-600 dark:text-green-400 font-semibold text-sm">
                                            <i class="fas fa-money-bill-wave mr-1"></i>
                                            <?php echo formatSalaryDisplay($job['salary_min'], $job['salary_max'], $job['salary_currency'], $job['salary_type']); ?>
                                        </div>
                                        <a href="/job-detail.php?id=<?php echo $job['id']; ?>" onclick="trackJobView(<?php echo $job['id']; ?>)" 
                                           class="text-blue-600 font-medium text-sm hover:text-blue-800 transition flex items-center">
                                            View Details <i class="fas fa-arrow-right ml-1 group-hover:translate-x-1 transition"></i>
                                        </a>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <!-- List View -->
                    <div id="listView" class="<?php echo $view_mode === 'list' ? '' : 'hidden'; ?> space-y-3">
                        <?php foreach ($jobs as $index => $job): ?>
                        <div class="job-list-item bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4" data-aos="fade-up" data-aos-delay="<?php echo $index * 30; ?>">
                            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                                <div class="flex-1">
                                    <div class="flex flex-wrap gap-2 mb-2">
                                        <?php if ($job['is_urgent']): ?>
                                        <span class="badge-urgent text-xs">Urgent</span>
                                        <?php endif; ?>
                                        <?php if ($job['is_featured']): ?>
                                        <span class="badge-featured text-xs">Featured</span>
                                        <?php endif; ?>
                                        <span class="text-xs text-gray-500"><?php echo htmlspecialchars($job['employment_type']); ?></span>
                                        <span class="text-xs bg-purple-100 text-purple-700 px-2 py-0.5 rounded-full"><?php echo htmlspecialchars($job['job_category']); ?></span>
                                    </div>
                                    <h3 class="font-bold text-lg text-gray-900 dark:text-white hover:text-blue-600 transition">
                                        <a href="/job-detail.php?id=<?php echo $job['id']; ?>" onclick="trackJobView(<?php echo $job['id']; ?>)">
                                            <?php echo htmlspecialchars($job['title']); ?>
                                        </a>
                                    </h3>
                                    <p class="text-gray-600 dark:text-gray-400 text-sm">
                                        <i class="fas fa-building mr-2 text-blue-500"></i>
                                        <?php echo htmlspecialchars(getCompanyDisplay($job)); ?>
                                        <span class="mx-2">•</span>
                                        <i class="fas fa-map-marker-alt mr-1 text-blue-500"></i>
                                        <?php echo htmlspecialchars($job['city'] ? $job['city'] : 'Ghana'); ?>
                                        <?php if ($job['location_type'] === 'Remote'): ?>
                                        <span class="ml-1 text-green-600">(Remote)</span>
                                        <?php endif; ?>
                                    </p>
                                    <?php if (!empty($job['overview'])): ?>
                                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-2 line-clamp-1">
                                        <?php echo htmlspecialchars(substr($job['overview'], 0, 100)); ?>...
                                    </p>
                                    <?php endif; ?>
                                </div>
                                <div class="flex flex-col items-end gap-2">
                                    <div class="text-green-600 dark:text-green-400 font-semibold text-sm">
                                        <?php echo formatSalaryDisplay($job['salary_min'], $job['salary_max'], $job['salary_currency'], $job['salary_type']); ?>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <span class="text-xs text-gray-500">
                                            <i class="far fa-calendar-alt mr-1"></i><?php echo timeAgo($job['created_at']); ?>
                                        </span>
                                        <button onclick="saveJob(<?php echo $job['id']; ?>)" class="text-gray-400 hover:text-yellow-500 transition">
                                            <i class="far fa-bookmark <?php echo in_array($job['id'], $saved_jobs) ? 'fas fa-bookmark text-yellow-500' : ''; ?>"></i>
                                        </button>
                                        <a href="/job-detail.php?id=<?php echo $job['id']; ?>" onclick="trackJobView(<?php echo $job['id']; ?>)" 
                                           class="btn-primary text-sm py-1.5 px-4">
                                            Apply Now
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                    <div class="mt-8 flex justify-center">
                        <nav class="flex items-center space-x-1">
                            <?php if ($page > 1): ?>
                            <a href="<?php echo buildPaginationUrl($page - 1); ?>" class="pagination-link">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                            <?php endif; ?>
                            
                            <?php
                            $start = max(1, $page - 2);
                            $end = min($total_pages, $page + 2);
                            
                            if ($start > 1): ?>
                            <a href="<?php echo buildPaginationUrl(1); ?>" class="pagination-link">1</a>
                            <?php if ($start > 2): ?>
                            <span class="px-2">...</span>
                            <?php endif; ?>
                            <?php endif; ?>
                            
                            <?php for ($i = $start; $i <= $end; $i++): ?>
                            <a href="<?php echo buildPaginationUrl($i); ?>" 
                               class="pagination-link <?php echo $page === $i ? 'active' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                            <?php endfor; ?>
                            
                            <?php if ($end < $total_pages): ?>
                            <?php if ($end < $total_pages - 1): ?>
                            <span class="px-2">...</span>
                            <?php endif; ?>
                            <a href="<?php echo buildPaginationUrl($total_pages); ?>" class="pagination-link"><?php echo $total_pages; ?></a>
                            <?php endif; ?>
                            
                            <?php if ($page < $total_pages): ?>
                            <a href="<?php echo buildPaginationUrl($page + 1); ?>" class="pagination-link">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                            <?php endif; ?>
                        </nav>
                    </div>
                    <?php endif; ?>
                    
                    <?php endif; ?>
                </div>
            </main>
        </div>
    </div>
</section>

<!-- Job Alerts CTA -->
<section class="bg-gradient-to-r from-blue-600 to-indigo-700 py-12 mt-8">
    <div class="container mx-auto px-4 text-center">
        <h2 class="text-2xl md:text-3xl font-bold text-white mb-3">Get Job Alerts</h2>
        <p class="text-white/80 mb-6">Never miss an opportunity. Get the latest jobs delivered to your inbox.</p>
        <form class="max-w-md mx-auto flex flex-col sm:flex-row gap-3" onsubmit="event.preventDefault(); alert('Job alerts feature coming soon!');">
            <input type="email" placeholder="Enter your email address" class="flex-1 px-4 py-3 rounded-lg focus:outline-none focus:ring-2 focus:ring-white">
            <button type="submit" class="bg-white text-blue-600 px-6 py-3 rounded-lg font-semibold hover:bg-gray-100 transition">
                Subscribe Now
            </button>
        </form>
    </div>
</section>

<!-- ========== FOOTER ========== -->
<footer class="bg-[#111827] text-white py-16">
    <div class="container mx-auto px-4">
        <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-8">
            <div class="lg:col-span-1">
                <div class="flex items-center space-x-3 mb-4">
                    <div class="w-10 h-10 brand-gradient rounded-xl flex items-center justify-center">
                        <i class="fas fa-handshake text-white"></i>
                    </div>
                    <span class="text-2xl font-bold gradient-text">TransNow Ghana</span>
                </div>
                <p class="text-gray-400 mb-4">Bridging Chinese employers and Ghanaian talent through communication, culture, and career opportunities.</p>
                
                <!-- Contact Information -->
                <div class="mb-4 space-y-2">
                    <div class="flex items-center space-x-3 text-gray-400">
                        <i class="fas fa-phone-alt text-[#10B981] w-5"></i>
                        <a href="tel:+233241334520" class="hover:text-white transition">+233 24 133 4520</a>
                    </div>
                    <div class="flex items-center space-x-3 text-gray-400">
                        <i class="fab fa-whatsapp text-[#25D366] w-5"></i>
                        <a href="https://wa.me/8619157688742" target="_blank" class="hover:text-white transition">+86 191 5768 8742 (WhatsApp)</a>
                    </div>
                    <div class="flex items-center space-x-3 text-gray-400">
                        <i class="fab fa-weixin text-[#07C160] w-5"></i>
                        <a href="https://u.wechat.com/EPqmYQJJDuNvJgHqgtMy00E?s=3" target="_blank" class="hover:text-white transition">WeChat: TransNow Ghana</a>
                    </div>
                </div>
                
                <!-- Affiliated Organization -->
                <div class="mb-4 p-3 bg-gray-800/50 rounded-lg border border-gray-700">
                    <p class="text-xs text-gray-400 mb-1">Affiliated Organization</p>
                    <a href="https://ci-ug.com" target="_blank" class="flex items-center space-x-2 hover:text-white transition">
                        <i class="fas fa-university text-[#3B82F6]"></i>
                        <span class="font-medium text-gray-300">Confucius Institute at University of Ghana (CI-UG)</span>
                    </a>
                </div>
                
                <div class="flex space-x-4">
                    <a href="#" class="w-10 h-10 bg-gray-800 rounded-full flex items-center justify-center hover:bg-[#1E3A8A] transition"><i class="fab fa-facebook-f"></i></a>
                    <a href="#" class="w-10 h-10 bg-gray-800 rounded-full flex items-center justify-center hover:bg-[#1E3A8A] transition"><i class="fab fa-twitter"></i></a>
                    <a href="#" class="w-10 h-10 bg-gray-800 rounded-full flex items-center justify-center hover:bg-[#1E3A8A] transition"><i class="fab fa-linkedin-in"></i></a>
                    <a href="https://u.wechat.com/EPqmYQJJDuNvJgHqgtMy00E?s=3" target="_blank" class="w-10 h-10 bg-gray-800 rounded-full flex items-center justify-center hover:bg-[#1E3A8A] transition"><i class="fab fa-weixin"></i></a>
                </div>
            </div>
            
            <div>
                <h4 class="font-semibold text-lg mb-4">Company</h4>
                <ul class="space-y-2 text-gray-400">
                    <li><a href="/about.php" class="hover:text-white transition">About Us</a></li>
               
                    <li><a href="/privacy.php" class="hover:text-white transition">Privacy Policy</a></li>
                    <li><a href="/terms.php" class="hover:text-white transition">Terms of Service</a></li>
                    <li><a href="https://ci-ug.com" target="_blank" class="hover:text-white transition">CI-UG (Partner)</a></li>
                </ul>
            </div>
            
            <div>
                <h4 class="font-semibold text-lg mb-4">Connect With Us</h4>
                <ul class="space-y-2 text-gray-400">
                    <li><a href="https://wa.me/8619157688742" target="_blank" class="hover:text-white transition flex items-center space-x-2">
                        <i class="fab fa-whatsapp text-[#25D366]"></i>
                        <span>WhatsApp</span>
                    </a></li>
                    <li><a href="https://u.wechat.com/EPqmYQJJDuNvJgHqgtMy00E?s=3" target="_blank" class="hover:text-white transition flex items-center space-x-2">
                        <i class="fab fa-weixin text-[#07C160]"></i>
                        <span>WeChat</span>
                    </a></li>
                    <li><a href="tel:+8619157688742" class="hover:text-white transition flex items-center space-x-2">
                        <i class="fas fa-phone-alt text-[#10B981]"></i>
                        <span>Call Us</span>
                    </a></li>
                </ul>
            </div>
        </div>
        
        <div class="border-t border-gray-800 mt-12 pt-8 text-center text-gray-400 text-sm">
            <p>&copy; <?php echo date('Y'); ?> TransNow Ghana. Bridging China & Ghana <i class="fas fa-heart text-[#10B981]"></i> for a shared future.</p>
            <p class="mt-2 text-xs text-gray-500">Developed by <a href="https://nerd-spring.com" target="_blank" class="hover:text-white transition">NerdSpring</a></p>
        </div>
    </div>
</footer>

<!-- Scroll to Top Button -->
<button id="scrollTop" class="scroll-top w-12 h-12 brand-gradient rounded-full shadow-lg hover:scale-110 transition">
    <i class="fas fa-arrow-up text-white"></i>
</button>

<script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
<script>
// Initialize AOS
AOS.init({
    duration: 600,
    once: true,
    offset: 50
});

// Dark Mode Toggle
const darkToggle = document.getElementById('darkModeToggle');
const darkIcon = document.getElementById('darkModeIcon');
const html = document.documentElement;

// Check for saved theme preference
if (localStorage.getItem('theme') === 'dark' || (!localStorage.getItem('theme') && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
    html.classList.add('dark');
    if (darkIcon) {
        darkIcon.classList.remove('fa-moon');
        darkIcon.classList.add('fa-sun');
    }
}

if (darkToggle) {
    darkToggle.addEventListener('click', () => {
        html.classList.toggle('dark');
        if (html.classList.contains('dark')) {
            localStorage.setItem('theme', 'dark');
            if (darkIcon) {
                darkIcon.classList.remove('fa-moon');
                darkIcon.classList.add('fa-sun');
            }
        } else {
            localStorage.setItem('theme', 'light');
            if (darkIcon) {
                darkIcon.classList.remove('fa-sun');
                darkIcon.classList.add('fa-moon');
            }
        }
    });
}

// Mobile Menu Toggle
const mobileBtn = document.getElementById('mobileMenuBtn');
const mobileMenu = document.getElementById('mobileMenu');
if (mobileBtn) {
    mobileBtn.addEventListener('click', () => {
        mobileMenu.classList.toggle('hidden');
        const icon = mobileBtn.querySelector('i');
        if (icon) {
            icon.classList.toggle('fa-bars');
            icon.classList.toggle('fa-times');
        }
    });
}

// Mobile Filter Drawer
const filterToggle = document.getElementById('mobileFilterToggle');
const filterSidebar = document.getElementById('filterSidebar');
const filterOverlay = document.getElementById('filterOverlay');
const closeFilterBtn = document.getElementById('closeFilterDrawer');

function openFilterDrawer() {
    if (filterSidebar) filterSidebar.classList.add('open');
    if (filterOverlay) filterOverlay.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}

function closeFilterDrawer() {
    if (filterSidebar) filterSidebar.classList.remove('open');
    if (filterOverlay) filterOverlay.classList.add('hidden');
    document.body.style.overflow = '';
}

if (filterToggle) filterToggle.addEventListener('click', openFilterDrawer);
if (closeFilterBtn) closeFilterBtn.addEventListener('click', closeFilterDrawer);
if (filterOverlay) filterOverlay.addEventListener('click', closeFilterDrawer);

// Close drawer on escape key
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && filterSidebar && filterSidebar.classList.contains('open')) {
        closeFilterDrawer();
    }
});

// View Mode Toggle
function toggleViewMode(mode) {
    const url = new URL(window.location.href);
    url.searchParams.set('view_mode', mode);
    url.searchParams.delete('page');
    window.location.href = url.toString();
}

// Save Job Function
function saveJob(jobId) {
    const url = new URL(window.location.href);
    url.searchParams.set('save_job', jobId);
    window.location.href = url.toString();
}

// Track Job View
function trackJobView(jobId) {
    fetch(`?ajax_view=${jobId}`, { method: 'GET', keepalive: true });
}

// Scroll to Top Button
const scrollTopBtn = document.getElementById('scrollTop');
if (scrollTopBtn) {
    window.addEventListener('scroll', () => {
        if (window.pageYOffset > 300) {
            scrollTopBtn.classList.add('show');
        } else {
            scrollTopBtn.classList.remove('show');
        }
    });
    
    scrollTopBtn.addEventListener('click', () => {
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });
}

// Keyboard shortcut for search (press '/' to focus search)
document.addEventListener('keydown', (e) => {
    if (e.key === '/' && !e.target.matches('input, textarea, select, button')) {
        e.preventDefault();
        const searchInput = document.querySelector('input[name="search"]');
        if (searchInput) searchInput.focus();
    }
});

// Auto-submit form on radio/checkbox change
document.querySelectorAll('input[type="radio"], input[type="checkbox"]').forEach(input => {
    if (input.closest('#filterForm')) {
        input.addEventListener('change', () => {
            input.closest('#filterForm').submit();
        });
    }
});
</script>

</body>
</html>