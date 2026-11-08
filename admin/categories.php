<?php
// admin/categories.php - Enhanced Admin Categories Management
// Version: 2.0 - Complete category management with job viewing

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

// Handle AJAX requests
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    
    // Get category jobs
    if ($_GET['ajax'] == 'get_category_jobs') {
        $category_id = isset($_GET['category_id']) ? intval($_GET['category_id']) : 0;
        
        if ($category_id > 0) {
            try {
                $jobs = fetchAll("
                    SELECT 
                        j.id,
                        j.title,
                        j.slug,
                        j.status,
                        j.is_featured,
                        j.is_urgent,
                        j.created_at,
                        j.published_at,
                        j.total_applications,
                        j.total_views,
                        j.salary_min,
                        j.salary_max,
                        j.salary_currency,
                        j.location_type,
                        j.city,
                        j.country,
                        e.id as employer_id,
                        e.company_name,
                        e.company_logo,
                        e.is_verified as employer_verified,
                        e.industry,
                        jt.name as job_type_name,
                        jt.slug as job_type_slug,
                        (SELECT COUNT(*) FROM applications a WHERE a.job_id = j.id) as application_count
                    FROM jobs j
                    INNER JOIN employers e ON j.employer_id = e.id
                    LEFT JOIN job_types jt ON j.job_type_id = jt.id
                    WHERE j.category_id = ?
                    ORDER BY 
                        CASE j.status 
                            WHEN 'active' THEN 1 
                            WHEN 'paused' THEN 2 
                            WHEN 'draft' THEN 3 
                            WHEN 'closed' THEN 4 
                            WHEN 'expired' THEN 5 
                        END,
                        j.created_at DESC
                ", [$category_id]);
                
                // Get category info
                $category = fetchOne("SELECT name FROM job_categories WHERE id = ?", [$category_id]);
                
                echo json_encode([
                    'success' => true, 
                    'jobs' => $jobs,
                    'category_name' => $category ? $category['name'] : 'Unknown',
                    'count' => count($jobs)
                ]);
            } catch (Exception $e) {
                echo json_encode([
                    'success' => false, 
                    'message' => 'Database error: ' . $e->getMessage()
                ]);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid category ID']);
        }
        exit();
    }
    
    // Get single category details for editing
    if ($_GET['ajax'] == 'get_category') {
        $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        
        if ($id > 0) {
            $category = fetchOne("SELECT * FROM job_categories WHERE id = ?", [$id]);
            if ($category) {
                echo json_encode(['success' => true, 'category' => $category]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Category not found']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid ID']);
        }
        exit();
    }
    
    // Toggle category status
    if ($_GET['ajax'] == 'toggle_status') {
        $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        
        if ($id > 0) {
            $category = fetchOne("SELECT is_active FROM job_categories WHERE id = ?", [$id]);
            if ($category) {
                $newStatus = $category['is_active'] ? 0 : 1;
                $result = executeQuery("UPDATE job_categories SET is_active = ?, updated_at = NOW() WHERE id = ?", [$newStatus, $id]);
                
                if ($result) {
                    echo json_encode([
                        'success' => true, 
                        'is_active' => $newStatus,
                        'message' => 'Status updated successfully'
                    ]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Failed to update status']);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'Category not found']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid ID']);
        }
        exit();
    }
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // Add new category
    if ($action === 'add_category') {
        $name = trim($_POST['name'] ?? '');
        $slug = trim($_POST['slug'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $icon = trim($_POST['icon'] ?? 'fa-folder');
        $color = trim($_POST['color'] ?? '#4f46e5');
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        // Generate slug if not provided
        if (empty($slug)) {
            $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $name)));
        }
        
        // Validate
        $errors = [];
        if (empty($name)) {
            $errors[] = 'Category name is required';
        }
        
        // Check if slug exists
        $existing = fetchOne("SELECT id FROM job_categories WHERE slug = ?", [$slug]);
        if ($existing) {
            $errors[] = 'Category slug already exists. Please use a different slug.';
        }
        
        if (empty($errors)) {
            $result = executeQuery("
                INSERT INTO job_categories (name, slug, description, icon, color, is_active, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ", [$name, $slug, $description, $icon, $color, $is_active]);
            
            if ($result) {
                $_SESSION['success'] = 'Category added successfully';
                
                // Log activity
                logUserActivity('add_category', "Added category: $name");
            } else {
                $_SESSION['error'] = 'Failed to add category';
            }
        } else {
            $_SESSION['error'] = implode('<br>', $errors);
        }
        
        header('Location: categories.php');
        exit();
    }
    
    // Edit category
    elseif ($action === 'edit_category') {
        $id = intval($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $slug = trim($_POST['slug'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $icon = trim($_POST['icon'] ?? 'fa-folder');
        $color = trim($_POST['color'] ?? '#4f46e5');
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        // Generate slug if not provided
        if (empty($slug)) {
            $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $name)));
        }
        
        // Validate
        $errors = [];
        if (empty($name)) {
            $errors[] = 'Category name is required';
        }
        
        // Check if slug exists for other categories
        $existing = fetchOne("SELECT id FROM job_categories WHERE slug = ? AND id != ?", [$slug, $id]);
        if ($existing) {
            $errors[] = 'Category slug already exists. Please use a different slug.';
        }
        
        if (empty($errors)) {
            $result = executeQuery("
                UPDATE job_categories 
                SET name = ?, slug = ?, description = ?, icon = ?, color = ?, is_active = ?, updated_at = NOW() 
                WHERE id = ?
            ", [$name, $slug, $description, $icon, $color, $is_active, $id]);
            
            if ($result) {
                $_SESSION['success'] = 'Category updated successfully';
                
                // Log activity
                logUserActivity('edit_category', "Updated category: $name");
            } else {
                $_SESSION['error'] = 'Failed to update category';
            }
        } else {
            $_SESSION['error'] = implode('<br>', $errors);
        }
        
        header('Location: categories.php');
        exit();
    }
    
    // Delete category
    elseif ($action === 'delete_category') {
        $id = intval($_POST['id'] ?? 0);
        
        // Check if category has jobs
        $jobCount = fetchOne("SELECT COUNT(*) as count FROM jobs WHERE category_id = ?", [$id]);
        
        if ($jobCount && $jobCount['count'] > 0) {
            $_SESSION['error'] = 'Cannot delete category with existing jobs. Please reassign jobs first.';
        } else {
            // Get category name for logging
            $category = fetchOne("SELECT name FROM job_categories WHERE id = ?", [$id]);
            
            $result = executeQuery("DELETE FROM job_categories WHERE id = ?", [$id]);
            
            if ($result) {
                $_SESSION['success'] = 'Category deleted successfully';
                
                // Log activity
                if ($category) {
                    logUserActivity('delete_category', "Deleted category: {$category['name']}");
                }
            } else {
                $_SESSION['error'] = 'Failed to delete category';
            }
        }
        
        header('Location: categories.php');
        exit();
    }
    
    // Bulk actions
    elseif ($action === 'bulk_action') {
        $bulkAction = $_POST['bulk_action'] ?? '';
        $categoryIds = $_POST['category_ids'] ?? [];
        
        if (!empty($categoryIds) && is_array($categoryIds)) {
            $ids = array_map('intval', $categoryIds);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            
            if ($bulkAction === 'activate') {
                executeQuery("UPDATE job_categories SET is_active = 1, updated_at = NOW() WHERE id IN ($placeholders)", $ids);
                $_SESSION['success'] = 'Selected categories activated successfully';
                
                // Log activity
                logUserActivity('bulk_activate', 'Activated ' . count($ids) . ' categories');
                
            } elseif ($bulkAction === 'deactivate') {
                executeQuery("UPDATE job_categories SET is_active = 0, updated_at = NOW() WHERE id IN ($placeholders)", $ids);
                $_SESSION['success'] = 'Selected categories deactivated successfully';
                
                // Log activity
                logUserActivity('bulk_deactivate', 'Deactivated ' . count($ids) . ' categories');
                
            } elseif ($bulkAction === 'delete') {
                // Check if any category has jobs
                $categoriesWithJobs = fetchAll("
                    SELECT c.id, c.name, COUNT(j.id) as job_count 
                    FROM job_categories c
                    LEFT JOIN jobs j ON c.id = j.category_id
                    WHERE c.id IN ($placeholders)
                    GROUP BY c.id
                    HAVING job_count > 0
                ", $ids);
                
                if (!empty($categoriesWithJobs)) {
                    $names = array_column($categoriesWithJobs, 'name');
                    $_SESSION['error'] = 'Cannot delete categories with jobs: ' . implode(', ', $names);
                } else {
                    executeQuery("DELETE FROM job_categories WHERE id IN ($placeholders)", $ids);
                    $_SESSION['success'] = 'Selected categories deleted successfully';
                    
                    // Log activity
                    logUserActivity('bulk_delete', 'Deleted ' . count($ids) . ' categories');
                }
            }
        } else {
            $_SESSION['error'] = 'No categories selected';
        }
        
        header('Location: categories.php');
        exit();
    }
}

// Get filter parameters
$search = $_GET['search'] ?? '';
$status = $_GET['status'] ?? '';
$sort = $_GET['sort'] ?? 'name_asc';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 12;

// Build query conditions
$conditions = [];
$params = [];

if (!empty($search)) {
    $conditions[] = "(c.name LIKE ? OR c.description LIKE ? OR c.slug LIKE ?)";
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

if ($status !== '') {
    $conditions[] = "c.is_active = ?";
    $params[] = $status;
}

$whereClause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";

// Get total count for pagination
$countQuery = "
    SELECT COUNT(DISTINCT c.id) as total 
    FROM job_categories c
    $whereClause
";
$totalResult = fetchOne($countQuery, $params);
$totalCategories = $totalResult['total'] ?? 0;
$totalPages = ceil($totalCategories / $perPage);
$offset = ($page - 1) * $perPage;

// Build order by
$orderBy = "c.name ASC";
switch ($sort) {
    case 'name_desc':
        $orderBy = "c.name DESC";
        break;
    case 'jobs_desc':
        $orderBy = "job_count DESC, c.name ASC";
        break;
    case 'jobs_asc':
        $orderBy = "job_count ASC, c.name ASC";
        break;
    case 'newest':
        $orderBy = "c.created_at DESC, c.name ASC";
        break;
    case 'oldest':
        $orderBy = "c.created_at ASC, c.name ASC";
        break;
}

// Fetch categories with comprehensive stats
$categories = fetchAll("
    SELECT 
        c.id,
        c.name,
        c.slug,
        c.description,
        c.icon,
        c.color,
        c.is_active,
        c.created_at,
        c.updated_at,
        COUNT(DISTINCT j.id) as job_count,
        COUNT(DISTINCT CASE WHEN j.status = 'active' THEN j.id END) as active_jobs,
        COUNT(DISTINCT CASE WHEN j.status = 'paused' THEN j.id END) as paused_jobs,
        COUNT(DISTINCT CASE WHEN j.status = 'draft' THEN j.id END) as draft_jobs,
        COUNT(DISTINCT CASE WHEN j.status = 'closed' THEN j.id END) as closed_jobs,
        COUNT(DISTINCT CASE WHEN j.status = 'expired' THEN j.id END) as expired_jobs,
        COUNT(DISTINCT CASE WHEN j.is_featured = 1 THEN j.id END) as featured_jobs,
        COUNT(DISTINCT CASE WHEN j.is_urgent = 1 THEN j.id END) as urgent_jobs,
        COALESCE(SUM(j.total_applications), 0) as total_applications,
        COALESCE(SUM(j.total_views), 0) as total_views,
        MAX(j.created_at) as latest_job_date,
        MIN(j.salary_min) as min_salary,
        MAX(j.salary_max) as max_salary
    FROM job_categories c
    LEFT JOIN jobs j ON c.id = j.category_id
    $whereClause
    GROUP BY c.id
    ORDER BY $orderBy
    LIMIT $offset, $perPage
", $params);

// Get total stats for header with more details
$totalStats = fetchOne("
    SELECT 
        COUNT(*) as total_categories,
        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_categories,
        SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive_categories,
        (SELECT COUNT(DISTINCT category_id) FROM jobs WHERE category_id IS NOT NULL) as categories_with_jobs,
        (SELECT COUNT(*) FROM jobs) as total_jobs,
        (SELECT COUNT(*) FROM jobs WHERE status = 'active') as active_jobs,
        (SELECT COUNT(*) FROM jobs WHERE is_featured = 1) as featured_jobs,
        (SELECT COUNT(*) FROM jobs WHERE is_urgent = 1) as urgent_jobs,
        (SELECT COUNT(*) FROM applications) as total_applications
    FROM job_categories
", []);

// Get popular categories (for quick view) with active jobs
$popularCategories = fetchAll("
    SELECT 
        c.id,
        c.name,
        c.slug,
        c.icon,
        c.color,
        COUNT(j.id) as job_count,
        COUNT(DISTINCT e.id) as employer_count
    FROM job_categories c
    LEFT JOIN jobs j ON c.id = j.category_id AND j.status = 'active'
    LEFT JOIN employers e ON j.employer_id = e.id
    WHERE c.is_active = 1
    GROUP BY c.id
    HAVING job_count > 0
    ORDER BY job_count DESC
    LIMIT 12
", []);

// Get category performance metrics for chart
$categoryPerformance = fetchAll("
    SELECT 
        c.name,
        COUNT(DISTINCT j.id) as job_count,
        COUNT(DISTINCT a.id) as application_count,
        COUNT(DISTINCT CASE WHEN a.application_status = 'hired' THEN a.id END) as hire_count
    FROM job_categories c
    LEFT JOIN jobs j ON c.id = j.category_id AND j.status = 'active'
    LEFT JOIN applications a ON j.id = a.job_id
    WHERE c.is_active = 1
    GROUP BY c.id
    HAVING job_count > 0
    ORDER BY job_count DESC
    LIMIT 10
", []);

// Prepare chart data
$chartLabels = [];
$chartJobData = [];
$chartAppData = [];
$chartHireData = [];

foreach ($categoryPerformance as $perf) {
    $chartLabels[] = $perf['name'];
    $chartJobData[] = (int)$perf['job_count'];
    $chartAppData[] = (int)$perf['application_count'];
    $chartHireData[] = (int)$perf['hire_count'];
}

// Icon options for categories
$iconOptions = [
    'fa-folder' => 'Folder',
    'fa-laptop-code' => 'Technology',
    'fa-stethoscope' => 'Healthcare',
    'fa-chart-line' => 'Finance',
    'fa-graduation-cap' => 'Education',
    'fa-building' => 'Business',
    'fa-paint-brush' => 'Design',
    'fa-calculator' => 'Accounting',
    'fa-gavel' => 'Legal',
    'fa-truck' => 'Logistics',
    'fa-utensils' => 'Hospitality',
    'fa-hammer' => 'Construction',
    'fa-headset' => 'Customer Service',
    'fa-chart-bar' => 'Marketing',
    'fa-users' => 'HR',
    'fa-flask' => 'Science',
    'fa-newspaper' => 'Media',
    'fa-shopping-cart' => 'Retail',
    'fa-shield-alt' => 'Security',
    'fa-wrench' => 'Maintenance'
];

// Color options
$colorOptions = [
    '#4f46e5' => 'Indigo',
    '#7c3aed' => 'Purple',
    '#db2777' => 'Pink',
    '#dc2626' => 'Red',
    '#ea580c' => 'Orange',
    '#d97706' => 'Amber',
    '#65a30d' => 'Lime',
    '#059669' => 'Emerald',
    '#0891b2' => 'Cyan',
    '#2563eb' => 'Blue',
    '#4b5563' => 'Gray'
];

// Avatar URL for admin
$avatarUrl = 'https://ui-avatars.com/api/?name=' . urlencode('Admin') . '&background=4f46e5&color=fff&size=128&bold=true';
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Categories Management - TransNow Admin</title>
    
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
    
    <!-- DataTables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.dataTables.min.css">
    
    <!-- Select2 -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    
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
        .status-inactive { @apply bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300; }
        
        /* Job Status Badges */
        .job-status-active { @apply bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300; }
        .job-status-paused { @apply bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300; }
        .job-status-draft { @apply bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300; }
        .job-status-closed { @apply bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-300; }
        .job-status-expired { @apply bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300; }
        
        /* Modal Styles */
        .modal {
            transition: opacity 0.3s ease;
        }
        
        .modal.hidden {
            opacity: 0;
            pointer-events: none;
        }
        
        .modal.show {
            opacity: 1;
            pointer-events: auto;
        }
        
        /* Category Cards */
        .category-card {
            cursor: pointer;
            position: relative;
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
        
        /* Job Table */
        .job-row {
            transition: all 0.2s ease;
        }
        
        .job-row:hover {
            background-color: rgba(79, 70, 229, 0.05);
        }
        
        /* Color Picker */
        .color-option {
            width: 30px;
            height: 30px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
            border: 2px solid transparent;
        }
        
        .color-option:hover {
            transform: scale(1.1);
        }
        
        .color-option.selected {
            border-color: #000;
            transform: scale(1.1);
        }
        
        /* Icon Picker */
        .icon-option {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s;
            border: 2px solid transparent;
        }
        
        .icon-option:hover {
            background-color: #f3f4f6;
            transform: scale(1.1);
        }
        
        .icon-option.selected {
            border-color: #4f46e5;
            background-color: #eef2ff;
        }
        
        /* Mobile Optimizations */
        @media (max-width: 768px) {
            .categories-grid {
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
        
        /* Select2 Customization */
        .select2-container--default .select2-selection--single {
            background-color: #f3f4f6;
            border: none;
            border-radius: 0.75rem;
            height: 46px;
            padding: 8px 12px;
        }
        
        .dark .select2-container--default .select2-selection--single {
            background-color: #374151;
        }
        
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 30px;
            color: #1f2937;
        }
        
        .dark .select2-container--default .select2-selection--single .select2-selection__rendered {
            color: #f3f4f6;
        }
        
        .select2-dropdown {
            background-color: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 0.75rem;
            overflow: hidden;
        }
        
        .dark .select2-dropdown {
            background-color: #1f2937;
            border-color: #4b5563;
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
                    <?php 
                    $jobCount = fetchOne("SELECT COUNT(*) as count FROM jobs")['count'] ?? 0;
                    if ($jobCount > 0): 
                    ?>
                    <span class="ml-auto bg-purple-500 text-white text-xs px-2 py-1 rounded-full"><?php echo $jobCount; ?></span>
                    <?php endif; ?>
                </a>
                <a href="applications.php" class="flex items-center space-x-3 px-4 py-3 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                    <i class="fas fa-file-alt w-5"></i>
                    <span>Applications</span>
                    <?php 
                    $appCount = fetchOne("SELECT COUNT(*) as count FROM applications")['count'] ?? 0;
                    if ($appCount > 0): 
                    ?>
                    <span class="ml-auto bg-green-500 text-white text-xs px-2 py-1 rounded-full"><?php echo $appCount; ?></span>
                    <?php endif; ?>
                </a>
                <a href="categories.php" class="flex items-center space-x-3 px-4 py-3 bg-indigo-50 dark:bg-indigo-900/20 text-indigo-600 dark:text-indigo-400 rounded-xl font-medium">
                    <i class="fas fa-tags w-5"></i>
                    <span>Categories</span>
                    <?php if (($totalStats['total_categories'] ?? 0) > 0): ?>
                    <span class="ml-auto bg-indigo-500 text-white text-xs px-2 py-1 rounded-full"><?php echo $totalStats['total_categories']; ?></span>
                    <?php endif; ?>
                </a>
              
                <a href="reports.php" class="flex items-center space-x-3 px-4 py-3 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                    <i class="fas fa-chart-bar w-5"></i>
                    <span>Reports</span>
                </a>
                <a href="settings.php" class="flex items-center space-x-3 px-4 py-3 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                    <i class="fas fa-cog w-5"></i>
                    <span>Settings</span>
                </a>
                <a href="email-queue.php" class="flex items-center space-x-3 px-4 py-3 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                    <i class="fas fa-envelope w-5"></i>
                    <span>Email Queue</span>
                    <?php 
                    $emailCount = fetchOne("SELECT COUNT(*) as count FROM email_queue WHERE status = 'pending'")['count'] ?? 0;
                    if ($emailCount > 0): 
                    ?>
                    <span class="ml-auto bg-yellow-500 text-white text-xs px-2 py-1 rounded-full"><?php echo $emailCount; ?></span>
                    <?php endif; ?>
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
                    <p class="text-sm opacity-90 mb-2">Total Categories</p>
                    <div class="text-2xl font-bold"><?php echo number_format($totalStats['total_categories'] ?? 0); ?></div>
                    <div class="flex items-center justify-between text-xs mt-2">
                        <span>Active: <?php echo number_format($totalStats['active_categories'] ?? 0); ?></span>
                        <span>With Jobs: <?php echo number_format($totalStats['categories_with_jobs'] ?? 0); ?></span>
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
                    
                    <!-- Page Title with Breadcrumb -->
                    <div class="hidden md:block">
                        <h1 class="text-2xl font-bold">
                            Categories <span class="gradient-text">Management</span>
                        </h1>
                        <p class="text-sm text-gray-500 mt-1">
                            <a href="dashboard.php" class="hover:text-indigo-600">Dashboard</a> / Categories
                        </p>
                    </div>
                    
                    <!-- Right Actions -->
                    <div class="flex items-center space-x-4">
                        <!-- Quick Stats -->
                        <div class="hidden lg:flex items-center space-x-4 text-sm">
                            <div class="flex items-center">
                                <span class="w-2 h-2 bg-green-500 rounded-full mr-2 animate-pulse"></span>
                                <span><?php echo number_format($totalStats['active_categories'] ?? 0); ?> Active</span>
                            </div>
                            <div class="flex items-center">
                                <span class="w-2 h-2 bg-purple-500 rounded-full mr-2"></span>
                                <span><?php echo number_format($totalStats['total_jobs'] ?? 0); ?> Jobs</span>
                            </div>
                            <div class="flex items-center">
                                <span class="w-2 h-2 bg-yellow-500 rounded-full mr-2"></span>
                                <span><?php echo number_format($totalStats['total_applications'] ?? 0); ?> Applications</span>
                            </div>
                        </div>
                        
                        <!-- Export Button -->
                        <button onclick="exportCategories()" class="hidden md:flex items-center space-x-2 px-4 py-2 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-xl hover:bg-gray-200 dark:hover:bg-gray-600 transition">
                            <i class="fas fa-download"></i>
                            <span>Export</span>
                        </button>
                        
                        <!-- Add Category Button -->
                        <button onclick="openAddCategoryModal()" class="px-4 py-2 bg-gradient-to-r from-indigo-600 to-purple-600 text-white rounded-xl font-medium hover:shadow-lg transition-all duration-300 flex items-center space-x-2">
                            <i class="fas fa-plus-circle"></i>
                            <span class="hidden sm:inline">Add Category</span>
                        </button>
                    </div>
                </div>
            </div>
        </header>

        <!-- Dashboard Content -->
        <div class="p-6">
            <!-- Welcome Banner -->
            <div class="mb-8 bg-gradient-to-r from-indigo-500 via-purple-500 to-pink-500 rounded-3xl p-6 text-white" data-aos="fade-down">
                <div class="flex items-center justify-between flex-wrap gap-4">
                    <div class="flex items-center space-x-4">
                        <div class="w-16 h-16 bg-white/20 rounded-2xl flex items-center justify-center">
                            <i class="fas fa-tags text-3xl"></i>
                        </div>
                        <div>
                            <h3 class="text-xl font-bold mb-1">Manage Job Categories</h3>
                            <p class="text-white/90">Organize jobs by categories. Click on any category to view its jobs and details.</p>
                        </div>
                    </div>
                    <div class="flex items-center space-x-3">
                        <span class="text-sm bg-white/20 px-4 py-2 rounded-xl">
                            <i class="fas fa-calendar mr-2"></i>
                            <?php echo date('l, F j, Y'); ?>
                        </span>
                        <span class="text-sm bg-white/20 px-4 py-2 rounded-xl">
                            <i class="fas fa-clock mr-2"></i>
                            <?php echo date('h:i A'); ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Stats Cards with More Details -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6 hover-card" data-aos="fade-up">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-12 h-12 bg-indigo-100 dark:bg-indigo-900/20 rounded-xl flex items-center justify-center">
                            <i class="fas fa-tags text-indigo-600 dark:text-indigo-400 text-xl"></i>
                        </div>
                        <span class="text-sm text-gray-500">Total Categories</span>
                    </div>
                    <h3 class="text-3xl font-bold mb-2"><?php echo number_format($totalStats['total_categories'] ?? 0); ?></h3>
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-green-500 flex items-center">
                            <i class="fas fa-check-circle mr-1"></i> <?php echo number_format($totalStats['active_categories'] ?? 0); ?> active
                        </span>
                        <span class="text-gray-500">
                            <?php echo number_format($totalStats['inactive_categories'] ?? 0); ?> inactive
                        </span>
                    </div>
                    <div class="mt-3 w-full h-1.5 bg-gray-200 dark:bg-gray-700 rounded-full overflow-hidden">
                        <?php $activePercent = ($totalStats['total_categories'] ?? 0) > 0 ? (($totalStats['active_categories'] ?? 0) / ($totalStats['total_categories'] ?? 1)) * 100 : 0; ?>
                        <div class="progress-bar h-full rounded-full" style="width: <?php echo $activePercent; ?>%"></div>
                    </div>
                </div>

                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6 hover-card" data-aos="fade-up" data-aos-delay="50">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-12 h-12 bg-purple-100 dark:bg-purple-900/20 rounded-xl flex items-center justify-center">
                            <i class="fas fa-briefcase text-purple-600 dark:text-purple-400 text-xl"></i>
                        </div>
                        <span class="text-sm text-gray-500">Jobs in Categories</span>
                    </div>
                    <h3 class="text-3xl font-bold mb-2"><?php echo number_format($totalStats['total_jobs'] ?? 0); ?></h3>
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-green-500 flex items-center">
                            <i class="fas fa-play-circle mr-1"></i> <?php echo number_format($totalStats['active_jobs'] ?? 0); ?> active
                        </span>
                        <span class="text-yellow-500 flex items-center">
                            <i class="fas fa-star mr-1"></i> <?php echo number_format($totalStats['featured_jobs'] ?? 0); ?> featured
                        </span>
                    </div>
                    <div class="mt-2 flex items-center text-xs text-gray-500">
                        <i class="fas fa-exclamation-circle text-red-500 mr-1"></i>
                        <?php echo number_format($totalStats['urgent_jobs'] ?? 0); ?> urgent
                    </div>
                </div>

                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6 hover-card" data-aos="fade-up" data-aos-delay="100">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-12 h-12 bg-green-100 dark:bg-green-900/20 rounded-xl flex items-center justify-center">
                            <i class="fas fa-check-circle text-green-600 dark:text-green-400 text-xl"></i>
                        </div>
                        <span class="text-sm text-gray-500">Categories with Jobs</span>
                    </div>
                    <h3 class="text-3xl font-bold mb-2"><?php echo number_format($totalStats['categories_with_jobs'] ?? 0); ?></h3>
                    <div class="flex items-center text-sm">
                        <span class="text-green-500">
                            <?php 
                            $percentage = ($totalStats['total_categories'] ?? 0) > 0 
                                ? round((($totalStats['categories_with_jobs'] ?? 0) / ($totalStats['total_categories'] ?? 1)) * 100) 
                                : 0;
                            echo $percentage; ?>% utilization
                        </span>
                    </div>
                    <div class="mt-3 flex items-center text-xs text-gray-500">
                        <i class="fas fa-building mr-1"></i>
                        <?php echo number_format($totalStats['total_applications'] ?? 0); ?> total applications
                    </div>
                </div>

                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6 hover-card" data-aos="fade-up" data-aos-delay="150">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-12 h-12 bg-yellow-100 dark:bg-yellow-900/20 rounded-xl flex items-center justify-center">
                            <i class="fas fa-chart-line text-yellow-600 dark:text-yellow-400 text-xl"></i>
                        </div>
                        <span class="text-sm text-gray-500">Performance</span>
                    </div>
                    <h3 class="text-3xl font-bold mb-2">
                        <?php 
                        $avgJobs = ($totalStats['categories_with_jobs'] ?? 0) > 0 
                            ? round(($totalStats['total_jobs'] ?? 0) / ($totalStats['categories_with_jobs'] ?? 1), 1) 
                            : 0;
                        echo number_format($avgJobs, 1); ?>
                    </h3>
                    <div class="flex items-center text-sm">
                        <span class="text-yellow-500">avg jobs/category</span>
                    </div>
                    <div class="mt-3 flex items-center text-xs text-gray-500">
                        <i class="fas fa-eye mr-1"></i>
                        <?php 
                        $totalViews = array_sum(array_column($categories, 'total_views'));
                        echo number_format($totalViews); ?> total views
                    </div>
                </div>
            </div>

            <!-- Performance Chart -->
            <?php if (!empty($categoryPerformance)): ?>
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6 mb-8" data-aos="fade-up">
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-xl font-bold">Category Performance</h2>
                    <div class="flex space-x-2">
                        <button class="px-3 py-1 bg-indigo-100 dark:bg-indigo-900 text-indigo-600 dark:text-indigo-400 rounded-lg text-sm font-medium" onclick="toggleChartType('bar')">
                            <i class="fas fa-chart-bar"></i>
                        </button>
                        <button class="px-3 py-1 bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-400 rounded-lg text-sm font-medium" onclick="toggleChartType('line')">
                            <i class="fas fa-chart-line"></i>
                        </button>
                    </div>
                </div>
                <div class="chart-container" style="height: 300px;">
                    <canvas id="performanceChart"></canvas>
                </div>
            </div>
            <?php endif; ?>

            <!-- Search and Filter Bar -->
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6 mb-8" data-aos="fade-up">
                <form method="GET" action="" class="flex flex-col md:flex-row gap-4">
                    <div class="flex-1 relative">
                        <i class="fas fa-search absolute left-4 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                        <input type="text" 
                               name="search" 
                               placeholder="Search categories by name, description or slug..." 
                               value="<?php echo htmlspecialchars($search); ?>"
                               class="w-full pl-12 pr-4 py-3 bg-gray-100 dark:bg-gray-700 border-0 rounded-xl focus:ring-2 focus:ring-indigo-500">
                    </div>
                    
                    <div class="md:w-48">
                        <select name="status" class="w-full px-4 py-3 bg-gray-100 dark:bg-gray-700 border-0 rounded-xl focus:ring-2 focus:ring-indigo-500">
                            <option value="">All Status</option>
                            <option value="1" <?php echo $status === '1' ? 'selected' : ''; ?>>Active Only</option>
                            <option value="0" <?php echo $status === '0' ? 'selected' : ''; ?>>Inactive Only</option>
                        </select>
                    </div>
                    
                    <div class="md:w-48">
                        <select name="sort" class="w-full px-4 py-3 bg-gray-100 dark:bg-gray-700 border-0 rounded-xl focus:ring-2 focus:ring-indigo-500">
                            <option value="name_asc" <?php echo $sort === 'name_asc' ? 'selected' : ''; ?>>Name (A-Z)</option>
                            <option value="name_desc" <?php echo $sort === 'name_desc' ? 'selected' : ''; ?>>Name (Z-A)</option>
                            <option value="jobs_desc" <?php echo $sort === 'jobs_desc' ? 'selected' : ''; ?>>Most Jobs</option>
                            <option value="jobs_asc" <?php echo $sort === 'jobs_asc' ? 'selected' : ''; ?>>Least Jobs</option>
                            <option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>Newest First</option>
                            <option value="oldest" <?php echo $sort === 'oldest' ? 'selected' : ''; ?>>Oldest First</option>
                        </select>
                    </div>
                    
                    <div class="flex gap-2">
                        <button type="submit" class="px-6 py-3 bg-indigo-600 text-white rounded-xl hover:bg-indigo-700 transition font-medium">
                            Apply Filters
                        </button>
                        <a href="categories.php" class="px-6 py-3 bg-gray-200 dark:bg-gray-600 text-gray-700 dark:text-gray-200 rounded-xl hover:bg-gray-300 dark:hover:bg-gray-500 transition font-medium">
                            Clear
                        </a>
                    </div>
                </form>
            </div>

            <!-- Bulk Actions Bar -->
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6 mb-8 transition-all duration-300" id="bulkActionsBar" style="display: none;">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div class="flex items-center space-x-4">
                        <span class="text-sm font-medium bg-indigo-100 dark:bg-indigo-900 text-indigo-600 dark:text-indigo-400 px-3 py-1 rounded-full" id="selectedCount">0 items selected</span>
                        <select id="bulkActionSelect" class="px-4 py-2 bg-gray-100 dark:bg-gray-700 border-0 rounded-lg focus:ring-2 focus:ring-indigo-500">
                            <option value="">Select Action</option>
                            <option value="activate">Activate Selected</option>
                            <option value="deactivate">Deactivate Selected</option>
                            <option value="delete">Delete Selected</option>
                        </select>
                        <button onclick="executeBulkAction()" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition">
                            Apply
                        </button>
                    </div>
                    <div class="flex items-center space-x-2">
                        <button onclick="selectAllCategories()" class="text-sm text-indigo-600 hover:underline">Select All</button>
                        <span class="text-gray-300">|</span>
                        <button onclick="clearSelection()" class="text-sm text-gray-500 hover:text-gray-700 dark:hover:text-gray-300">
                            Clear Selection
                        </button>
                    </div>
                </div>
            </div>

            <!-- Categories Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-8 categories-grid">
                <?php if (empty($categories)): ?>
                <div class="lg:col-span-3 text-center py-20 bg-white dark:bg-gray-800 rounded-2xl shadow-xl" data-aos="fade-up">
                    <i class="fas fa-tags text-6xl text-gray-400 mb-6"></i>
                    <h3 class="text-2xl font-bold mb-4">No Categories Found</h3>
                    <p class="text-gray-600 dark:text-gray-400 mb-8 max-w-md mx-auto">
                        <?php if (!empty($search)): ?>
                            No categories match your search criteria. Try different keywords or clear filters.
                        <?php else: ?>
                            Get started by creating your first job category to organize jobs on your platform.
                        <?php endif; ?>
                    </p>
                    <div class="flex flex-wrap gap-4 justify-center">
                        <?php if (!empty($search) || $status !== ''): ?>
                        <a href="categories.php" class="px-6 py-3 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-xl hover:bg-gray-300 dark:hover:bg-gray-600 transition font-medium">
                            <i class="fas fa-times mr-2"></i>Clear Filters
                        </a>
                        <?php endif; ?>
                        <button onclick="openAddCategoryModal()" class="inline-flex items-center space-x-2 bg-gradient-to-r from-indigo-600 to-purple-600 text-white px-8 py-3 rounded-xl font-semibold hover:shadow-xl transition-all duration-300">
                            <i class="fas fa-plus-circle"></i>
                            <span>Add New Category</span>
                        </button>
                    </div>
                </div>
                <?php else: ?>
                    <?php foreach ($categories as $cat): 
                        $bgColor = $cat['color'] ?? '#4f46e5';
                        $icon = $cat['icon'] ?? 'fa-folder';
                    ?>
                    <div class="category-card bg-white dark:bg-gray-800 rounded-2xl shadow-xl overflow-hidden hover-card relative" 
                         data-aos="fade-up"
                         data-category-id="<?php echo $cat['id']; ?>"
                         onclick="viewCategoryJobs(<?php echo $cat['id']; ?>, '<?php echo htmlspecialchars(addslashes($cat['name'])); ?>')">
                        
                        <!-- Card Header with Color -->
                        <div class="h-3" style="background: <?php echo $bgColor; ?>"></div>
                        
                        <div class="p-6">
                            <!-- Checkbox for bulk actions -->
                            <div class="absolute top-4 left-4 z-10" onclick="event.stopPropagation()">
                                <input type="checkbox" 
                                       class="category-checkbox w-5 h-5 text-indigo-600 rounded border-gray-300 focus:ring-indigo-500"
                                       value="<?php echo $cat['id']; ?>"
                                       onchange="updateBulkSelection()">
                            </div>
                            
                            <!-- Action Buttons -->
                            <div class="absolute top-4 right-4 flex space-x-2 z-10" onclick="event.stopPropagation()">
                                <button onclick="event.stopPropagation(); toggleCategoryStatus(<?php echo $cat['id']; ?>, <?php echo $cat['is_active'] ? 0 : 1; ?>)" 
                                        class="w-8 h-8 bg-gray-100 dark:bg-gray-700 rounded-lg flex items-center justify-center hover:bg-<?php echo $cat['is_active'] ? 'yellow' : 'green'; ?>-100 dark:hover:bg-<?php echo $cat['is_active'] ? 'yellow' : 'green'; ?>-900/30 transition group"
                                        title="<?php echo $cat['is_active'] ? 'Deactivate' : 'Activate'; ?>">
                                    <i class="fas fa-<?php echo $cat['is_active'] ? 'pause' : 'play'; ?> text-gray-600 dark:text-gray-400 group-hover:text-<?php echo $cat['is_active'] ? 'yellow' : 'green'; ?>-600"></i>
                                </button>
                                <button onclick="event.stopPropagation(); openEditCategoryModal(<?php echo $cat['id']; ?>)" 
                                        class="w-8 h-8 bg-gray-100 dark:bg-gray-700 rounded-lg flex items-center justify-center hover:bg-indigo-100 dark:hover:bg-indigo-900/30 transition group"
                                        title="Edit Category">
                                    <i class="fas fa-edit text-gray-600 dark:text-gray-400 group-hover:text-indigo-600"></i>
                                </button>
                                <button onclick="event.stopPropagation(); confirmDeleteCategory(<?php echo $cat['id']; ?>, '<?php echo htmlspecialchars(addslashes($cat['name'])); ?>', <?php echo $cat['job_count']; ?>)" 
                                        class="w-8 h-8 bg-gray-100 dark:bg-gray-700 rounded-lg flex items-center justify-center hover:bg-red-100 dark:hover:bg-red-900/30 transition group"
                                        title="Delete Category">
                                    <i class="fas fa-trash text-gray-600 dark:text-gray-400 group-hover:text-red-600"></i>
                                </button>
                            </div>
                            
                            <!-- Category Icon with Color -->
                            <div class="w-16 h-16 rounded-2xl flex items-center justify-center mb-4" 
                                 style="background: <?php echo $bgColor; ?>20; color: <?php echo $bgColor; ?>">
                                <i class="fas <?php echo $icon; ?> text-3xl"></i>
                            </div>
                            
                            <!-- Category Name and Status -->
                            <div class="flex items-center justify-between mb-3 pr-16">
                                <h3 class="text-xl font-bold truncate"><?php echo htmlspecialchars($cat['name']); ?></h3>
                                <span class="status-badge <?php echo $cat['is_active'] ? 'status-active' : 'status-inactive'; ?> flex-shrink-0">
                                    <?php echo $cat['is_active'] ? 'Active' : 'Inactive'; ?>
                                </span>
                            </div>
                            
                            <!-- Slug -->
                            <p class="text-sm text-gray-500 dark:text-gray-400 mb-3 flex items-center">
                                <i class="fas fa-link mr-1"></i> 
                                <span class="truncate">/<?php echo htmlspecialchars($cat['slug']); ?></span>
                            </p>
                            
                            <!-- Description -->
                            <?php if (!empty($cat['description'])): ?>
                            <p class="text-gray-600 dark:text-gray-400 text-sm mb-4 line-clamp-2">
                                <?php echo htmlspecialchars($cat['description']); ?>
                            </p>
                            <?php endif; ?>
                            
                            <!-- Stats Grid -->
                            <div class="grid grid-cols-3 gap-2 mb-4">
                                <div class="bg-gray-50 dark:bg-gray-700/50 rounded-xl p-2 text-center">
                                    <div class="text-lg font-bold" style="color: <?php echo $bgColor; ?>"><?php echo number_format($cat['job_count']); ?></div>
                                    <div class="text-xs text-gray-500">Total</div>
                                </div>
                                <div class="bg-gray-50 dark:bg-gray-700/50 rounded-xl p-2 text-center">
                                    <div class="text-lg font-bold text-green-600"><?php echo number_format($cat['active_jobs']); ?></div>
                                    <div class="text-xs text-gray-500">Active</div>
                                </div>
                                <div class="bg-gray-50 dark:bg-gray-700/50 rounded-xl p-2 text-center">
                                    <div class="text-lg font-bold text-purple-600"><?php echo number_format($cat['total_applications']); ?></div>
                                    <div class="text-xs text-gray-500">Apps</div>
                                </div>
                            </div>
                            
                            <!-- Additional Stats -->
                            <div class="flex flex-wrap items-center gap-2 text-xs text-gray-500 border-t dark:border-gray-700 pt-4">
                                <span class="flex items-center bg-yellow-50 dark:bg-yellow-900/20 px-2 py-1 rounded-full">
                                    <i class="fas fa-star text-yellow-500 mr-1 text-[10px]"></i>
                                    <?php echo number_format($cat['featured_jobs']); ?> Featured
                                </span>
                                <span class="flex items-center bg-red-50 dark:bg-red-900/20 px-2 py-1 rounded-full">
                                    <i class="fas fa-exclamation-circle text-red-500 mr-1 text-[10px]"></i>
                                    <?php echo number_format($cat['urgent_jobs']); ?> Urgent
                                </span>
                                <span class="flex items-center bg-blue-50 dark:bg-blue-900/20 px-2 py-1 rounded-full">
                                    <i class="fas fa-eye text-blue-500 mr-1 text-[10px]"></i>
                                    <?php echo number_format($cat['total_views']); ?> Views
                                </span>
                            </div>
                            
                            <!-- Job Status Distribution -->
                            <?php if ($cat['job_count'] > 0): ?>
                            <div class="mt-4">
                                <div class="flex items-center justify-between text-xs mb-1">
                                    <span>Job Distribution</span>
                                    <span><?php echo round(($cat['active_jobs'] / $cat['job_count']) * 100); ?>% Active</span>
                                </div>
                                <div class="flex h-1.5 bg-gray-200 dark:bg-gray-700 rounded-full overflow-hidden">
                                    <?php 
                                    $activeWidth = ($cat['active_jobs'] / max($cat['job_count'], 1)) * 100;
                                    $pausedWidth = ($cat['paused_jobs'] / max($cat['job_count'], 1)) * 100;
                                    $draftWidth = ($cat['draft_jobs'] / max($cat['job_count'], 1)) * 100;
                                    $closedWidth = ($cat['closed_jobs'] / max($cat['job_count'], 1)) * 100;
                                    $expiredWidth = ($cat['expired_jobs'] / max($cat['job_count'], 1)) * 100;
                                    ?>
                                    <?php if ($activeWidth > 0): ?>
                                    <div class="bg-green-500 h-full" style="width: <?php echo $activeWidth; ?>%"></div>
                                    <?php endif; ?>
                                    <?php if ($pausedWidth > 0): ?>
                                    <div class="bg-yellow-500 h-full" style="width: <?php echo $pausedWidth; ?>%"></div>
                                    <?php endif; ?>
                                    <?php if ($draftWidth > 0): ?>
                                    <div class="bg-gray-500 h-full" style="width: <?php echo $draftWidth; ?>%"></div>
                                    <?php endif; ?>
                                    <?php if ($closedWidth > 0): ?>
                                    <div class="bg-purple-500 h-full" style="width: <?php echo $closedWidth; ?>%"></div>
                                    <?php endif; ?>
                                    <?php if ($expiredWidth > 0): ?>
                                    <div class="bg-red-500 h-full" style="width: <?php echo $expiredWidth; ?>%"></div>
                                    <?php endif; ?>
                                </div>
                                <div class="flex flex-wrap gap-2 mt-2 text-[10px]">
                                    <?php if ($cat['active_jobs'] > 0): ?>
                                    <span class="flex items-center"><span class="w-2 h-2 bg-green-500 rounded-full mr-1"></span> Active: <?php echo $cat['active_jobs']; ?></span>
                                    <?php endif; ?>
                                    <?php if ($cat['paused_jobs'] > 0): ?>
                                    <span class="flex items-center"><span class="w-2 h-2 bg-yellow-500 rounded-full mr-1"></span> Paused: <?php echo $cat['paused_jobs']; ?></span>
                                    <?php endif; ?>
                                    <?php if ($cat['draft_jobs'] > 0): ?>
                                    <span class="flex items-center"><span class="w-2 h-2 bg-gray-500 rounded-full mr-1"></span> Draft: <?php echo $cat['draft_jobs']; ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Latest Job Info -->
                            <?php if (!empty($cat['latest_job_date'])): ?>
                            <div class="mt-3 text-xs text-gray-500 flex items-center">
                                <i class="far fa-clock mr-1"></i>
                                Latest job: <?php echo timeAgo($cat['latest_job_date']); ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Card Footer with Created Date -->
                        <div class="px-6 py-3 bg-gray-50 dark:bg-gray-700/50 border-t dark:border-gray-700 text-xs text-gray-500 flex items-center justify-between">
                            <span>
                                <i class="far fa-calendar-alt mr-1"></i>
                                Created <?php echo timeAgo($cat['created_at']); ?>
                            </span>
                            <span class="text-indigo-600 dark:text-indigo-400 text-xs font-medium">
                                Click to view jobs →
                            </span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
            <div class="flex flex-col sm:flex-row justify-between items-center mt-8 gap-4" data-aos="fade-up">
                <div class="text-sm text-gray-500">
                    Showing <?php echo $offset + 1; ?> to <?php echo min($offset + $perPage, $totalCategories); ?> of <?php echo $totalCategories; ?> categories
                </div>
                <nav class="inline-flex rounded-xl shadow-lg bg-white dark:bg-gray-800 overflow-hidden">
                    <?php if ($page > 1): ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" 
                       class="px-4 py-3 text-gray-700 dark:text-gray-300 hover:bg-indigo-50 dark:hover:bg-gray-700 transition border-r dark:border-gray-700">
                        <i class="fas fa-chevron-left"></i>
                    </a>
                    <?php endif; ?>
                    
                    <?php
                    $start = max(1, $page - 2);
                    $end = min($totalPages, $page + 2);
                    
                    if ($start > 1) {
                        echo '<a href="?' . http_build_query(array_merge($_GET, ['page' => 1])) . '" class="px-5 py-3 text-gray-700 dark:text-gray-300 hover:bg-indigo-50 dark:hover:bg-gray-700 transition border-r dark:border-gray-700 font-medium">1</a>';
                        if ($start > 2) {
                            echo '<span class="px-5 py-3 text-gray-500 border-r dark:border-gray-700">...</span>';
                        }
                    }
                    
                    for ($i = $start; $i <= $end; $i++):
                    ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" 
                       class="px-5 py-3 <?php echo $i == $page ? 'bg-indigo-600 text-white' : 'text-gray-700 dark:text-gray-300 hover:bg-indigo-50 dark:hover:bg-gray-700'; ?> transition border-r dark:border-gray-700 font-medium">
                        <?php echo $i; ?>
                    </a>
                    <?php endfor; ?>
                    
                    <?php if ($end < $totalPages): ?>
                        <?php if ($end < $totalPages - 1): ?>
                        <span class="px-5 py-3 text-gray-500 border-r dark:border-gray-700">...</span>
                        <?php endif; ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $totalPages])); ?>" 
                           class="px-5 py-3 text-gray-700 dark:text-gray-300 hover:bg-indigo-50 dark:hover:bg-gray-700 transition border-r dark:border-gray-700 font-medium">
                            <?php echo $totalPages; ?>
                        </a>
                    <?php endif; ?>
                    
                    <?php if ($page < $totalPages): ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" 
                       class="px-4 py-3 text-gray-700 dark:text-gray-300 hover:bg-indigo-50 dark:hover:bg-gray-700 transition">
                        <i class="fas fa-chevron-right"></i>
                    </a>
                    <?php endif; ?>
                </nav>
            </div>
            <?php endif; ?>

            <!-- Popular Categories Section -->
            <?php if (!empty($popularCategories)): ?>
            <div class="mt-12" data-aos="fade-up">
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-2xl font-bold">Popular Categories <span class="text-sm font-normal text-gray-500 ml-2">(with active jobs)</span></h2>
                    <a href="#top" class="text-indigo-600 hover:underline text-sm">Back to Top ↑</a>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6">
                    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-4">
                        <?php foreach ($popularCategories as $pop): 
                            $popColor = $pop['color'] ?? '#4f46e5';
                            $popIcon = $pop['icon'] ?? 'fa-folder';
                        ?>
                        <button onclick="viewCategoryJobs(<?php echo $pop['id']; ?>, '<?php echo htmlspecialchars(addslashes($pop['name'])); ?>')" 
                                class="group text-center p-4 hover:bg-indigo-50 dark:hover:bg-gray-700 rounded-xl transition-all duration-300 hover:scale-105">
                            <div class="w-16 h-16 rounded-xl flex items-center justify-center mx-auto mb-3 group-hover:scale-110 transition-transform" 
                                 style="background: <?php echo $popColor; ?>20; color: <?php echo $popColor; ?>">
                                <i class="fas <?php echo $popIcon; ?> text-2xl"></i>
                            </div>
                            <h3 class="font-semibold mb-1 truncate"><?php echo htmlspecialchars($pop['name']); ?></h3>
                            <p class="text-xs" style="color: <?php echo $popColor; ?>"><?php echo number_format($pop['job_count']); ?> jobs</p>
                            <?php if (!empty($pop['employer_count'])): ?>
                            <p class="text-[10px] text-gray-500 mt-1"><?php echo $pop['employer_count']; ?> employers</p>
                            <?php endif; ?>
                        </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Recent Activity -->
            <div class="mt-12 grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Category Creation Timeline -->
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6" data-aos="fade-up">
                    <h2 class="text-xl font-bold mb-6">Recent Category Activity</h2>
                    <div class="space-y-4">
                        <?php 
                        $recentCategories = fetchAll("
                            SELECT name, created_at 
                            FROM job_categories 
                            ORDER BY created_at DESC 
                            LIMIT 8
                        ");
                        
                        if (!empty($recentCategories)):
                            foreach ($recentCategories as $rc):
                        ?>
                        <div class="flex items-center justify-between py-2 border-b dark:border-gray-700 last:border-0">
                            <div class="flex items-center space-x-3">
                                <div class="w-8 h-8 bg-indigo-100 dark:bg-indigo-900/30 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-folder text-indigo-600 dark:text-indigo-400 text-sm"></i>
                                </div>
                                <div>
                                    <p class="font-medium"><?php echo htmlspecialchars($rc['name']); ?></p>
                                    <p class="text-xs text-gray-500">Category created</p>
                                </div>
                            </div>
                            <span class="text-xs text-gray-500"><?php echo timeAgo($rc['created_at']); ?></span>
                        </div>
                        <?php 
                            endforeach;
                        else:
                        ?>
                        <p class="text-gray-500 text-center py-4">No recent activity</p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Top Categories by Applications -->
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6" data-aos="fade-up">
                    <h2 class="text-xl font-bold mb-6">Top Categories by Applications</h2>
                    <div class="space-y-4">
                        <?php 
                        $topAppCategories = fetchAll("
                            SELECT 
                                c.name,
                                c.color,
                                c.icon,
                                COUNT(DISTINCT a.id) as app_count,
                                COUNT(DISTINCT CASE WHEN a.application_status = 'hired' THEN a.id END) as hire_count
                            FROM job_categories c
                            LEFT JOIN jobs j ON c.id = j.category_id
                            LEFT JOIN applications a ON j.id = a.job_id
                            WHERE c.is_active = 1
                            GROUP BY c.id
                            HAVING app_count > 0
                            ORDER BY app_count DESC
                            LIMIT 8
                        ");
                        
                        if (!empty($topAppCategories)):
                            $maxApps = max(array_column($topAppCategories, 'app_count'));
                            foreach ($topAppCategories as $tac):
                                $tacColor = $tac['color'] ?? '#4f46e5';
                                $tacIcon = $tac['icon'] ?? 'fa-folder';
                                $percentage = ($tac['app_count'] / $maxApps) * 100;
                        ?>
                        <div>
                            <div class="flex items-center justify-between mb-1">
                                <div class="flex items-center space-x-2">
                                    <div class="w-6 h-6 rounded-md flex items-center justify-center" style="background: <?php echo $tacColor; ?>20; color: <?php echo $tacColor; ?>">
                                        <i class="fas <?php echo $tacIcon; ?> text-xs"></i>
                                    </div>
                                    <span class="text-sm font-medium"><?php echo htmlspecialchars($tac['name']); ?></span>
                                </div>
                                <div class="flex items-center space-x-3">
                                    <span class="text-sm font-bold" style="color: <?php echo $tacColor; ?>"><?php echo number_format($tac['app_count']); ?></span>
                                    <?php if ($tac['hire_count'] > 0): ?>
                                    <span class="text-xs text-green-600 bg-green-100 dark:bg-green-900 px-2 py-1 rounded-full">
                                        <?php echo $tac['hire_count']; ?> hired
                                    </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="w-full h-2 bg-gray-200 dark:bg-gray-700 rounded-full overflow-hidden">
                                <div class="h-full rounded-full" style="width: <?php echo $percentage; ?>%; background: <?php echo $tacColor; ?>"></div>
                            </div>
                        </div>
                        <?php 
                            endforeach;
                        else:
                        ?>
                        <p class="text-gray-500 text-center py-4">No application data available</p>
                        <?php endif; ?>
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
                    <a href="categories.php" class="block px-4 py-2 bg-indigo-50 dark:bg-indigo-900/20 text-indigo-600 rounded-lg">Categories</a>
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

<!-- Add Category Modal -->
<div id="addCategoryModal" class="fixed inset-0 z-50 flex items-center justify-center hidden modal">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeAddCategoryModal()"></div>
    <div class="relative bg-white dark:bg-gray-800 rounded-3xl shadow-2xl w-full max-w-2xl mx-4 max-h-[90vh] overflow-y-auto">
        <div class="p-8">
            <div class="flex items-center justify-between mb-6">
                <h2 class="text-2xl font-bold">Add New Category</h2>
                <button onclick="closeAddCategoryModal()" class="w-10 h-10 bg-gray-100 dark:bg-gray-700 rounded-lg flex items-center justify-center hover:bg-gray-200 dark:hover:bg-gray-600 transition">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <form method="POST" action="" id="addCategoryForm">
                <input type="hidden" name="action" value="add_category">
                
                <div class="space-y-6">
                    <!-- Category Name -->
                    <div>
                        <label class="block text-sm font-medium mb-2">Category Name <span class="text-red-500">*</span></label>
                        <input type="text" 
                               name="name" 
                               id="categoryName"
                               required
                               class="w-full px-4 py-3 bg-gray-100 dark:bg-gray-700 border-0 rounded-xl focus:ring-2 focus:ring-indigo-500"
                               placeholder="e.g., Technology, Healthcare, Finance...">
                    </div>
                    
                    <!-- Slug -->
                    <div>
                        <label class="block text-sm font-medium mb-2">Slug (URL)</label>
                        <div class="flex items-center space-x-2">
                            <span class="text-gray-500">/</span>
                            <input type="text" 
                                   name="slug" 
                                   id="categorySlug"
                                   class="flex-1 px-4 py-3 bg-gray-100 dark:bg-gray-700 border-0 rounded-xl focus:ring-2 focus:ring-indigo-500"
                                   placeholder="technology (auto-generated if empty)">
                        </div>
                        <p class="text-xs text-gray-500 mt-1">Leave empty to auto-generate from name</p>
                    </div>
                    
                    <!-- Icon Selection -->
                    <div>
                        <label class="block text-sm font-medium mb-3">Category Icon</label>
                        <div class="grid grid-cols-5 gap-2 max-h-48 overflow-y-auto p-2 bg-gray-50 dark:bg-gray-700 rounded-xl">
                            <?php foreach ($iconOptions as $iconClass => $iconName): ?>
                            <div class="icon-option <?php echo $iconClass == 'fa-folder' ? 'selected' : ''; ?>" 
                                 data-icon="<?php echo $iconClass; ?>"
                                 onclick="selectIcon(this, 'add')"
                                 title="<?php echo $iconName; ?>">
                                <i class="fas <?php echo $iconClass; ?>"></i>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <input type="hidden" name="icon" id="addCategoryIcon" value="fa-folder">
                    </div>
                    
                    <!-- Color Selection -->
                    <div>
                        <label class="block text-sm font-medium mb-3">Category Color</label>
                        <div class="flex flex-wrap gap-3">
                            <?php foreach ($colorOptions as $colorCode => $colorName): ?>
                            <div class="color-option <?php echo $colorCode == '#4f46e5' ? 'selected' : ''; ?>" 
                                 style="background-color: <?php echo $colorCode; ?>"
                                 onclick="selectColor(this, '<?php echo $colorCode; ?>', 'add')"
                                 title="<?php echo $colorName; ?>"></div>
                            <?php endforeach; ?>
                        </div>
                        <input type="hidden" name="color" id="addCategoryColor" value="#4f46e5">
                    </div>
                    
                    <!-- Description -->
                    <div>
                        <label class="block text-sm font-medium mb-2">Description</label>
                        <textarea name="description" 
                                  id="addCategoryDescription"
                                  rows="4"
                                  class="w-full px-4 py-3 bg-gray-100 dark:bg-gray-700 border-0 rounded-xl focus:ring-2 focus:ring-indigo-500"
                                  placeholder="Describe this category..."></textarea>
                    </div>
                    
                    <!-- Active Status -->
                    <div>
                        <label class="flex items-center space-x-3">
                            <input type="checkbox" name="is_active" value="1" checked class="w-5 h-5 text-indigo-600 rounded border-gray-300 focus:ring-indigo-500">
                            <span class="text-sm font-medium">Active (visible to users)</span>
                        </label>
                    </div>
                </div>
                
                <div class="flex items-center justify-end space-x-4 mt-8">
                    <button type="button" onclick="closeAddCategoryModal()" class="px-6 py-3 border border-gray-300 dark:border-gray-600 rounded-xl hover:bg-gray-100 dark:hover:bg-gray-700 transition">
                        Cancel
                    </button>
                    <button type="submit" class="px-6 py-3 bg-gradient-to-r from-indigo-600 to-purple-600 text-white rounded-xl hover:shadow-lg transition-all duration-300">
                        <i class="fas fa-plus-circle mr-2"></i>
                        Add Category
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Category Modal -->
<div id="editCategoryModal" class="fixed inset-0 z-50 flex items-center justify-center hidden modal">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeEditCategoryModal()"></div>
    <div class="relative bg-white dark:bg-gray-800 rounded-3xl shadow-2xl w-full max-w-2xl mx-4 max-h-[90vh] overflow-y-auto">
        <div class="p-8">
            <div class="flex items-center justify-between mb-6">
                <h2 class="text-2xl font-bold">Edit Category</h2>
                <button onclick="closeEditCategoryModal()" class="w-10 h-10 bg-gray-100 dark:bg-gray-700 rounded-lg flex items-center justify-center hover:bg-gray-200 dark:hover:bg-gray-600 transition">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <form method="POST" action="" id="editCategoryForm">
                <input type="hidden" name="action" value="edit_category">
                <input type="hidden" name="id" id="editCategoryId">
                
                <div class="space-y-6">
                    <!-- Category Name -->
                    <div>
                        <label class="block text-sm font-medium mb-2">Category Name <span class="text-red-500">*</span></label>
                        <input type="text" 
                               name="name" 
                               id="editCategoryName"
                               required
                               class="w-full px-4 py-3 bg-gray-100 dark:bg-gray-700 border-0 rounded-xl focus:ring-2 focus:ring-indigo-500">
                    </div>
                    
                    <!-- Slug -->
                    <div>
                        <label class="block text-sm font-medium mb-2">Slug (URL)</label>
                        <div class="flex items-center space-x-2">
                            <span class="text-gray-500">/</span>
                            <input type="text" 
                                   name="slug" 
                                   id="editCategorySlug"
                                   class="flex-1 px-4 py-3 bg-gray-100 dark:bg-gray-700 border-0 rounded-xl focus:ring-2 focus:ring-indigo-500">
                        </div>
                    </div>
                    
                    <!-- Icon Selection -->
                    <div>
                        <label class="block text-sm font-medium mb-3">Category Icon</label>
                        <div class="grid grid-cols-5 gap-2 max-h-48 overflow-y-auto p-2 bg-gray-50 dark:bg-gray-700 rounded-xl" id="editIconContainer">
                            <?php foreach ($iconOptions as $iconClass => $iconName): ?>
                            <div class="icon-option" 
                                 data-icon="<?php echo $iconClass; ?>"
                                 onclick="selectIcon(this, 'edit')"
                                 title="<?php echo $iconName; ?>">
                                <i class="fas <?php echo $iconClass; ?>"></i>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <input type="hidden" name="icon" id="editCategoryIcon">
                    </div>
                    
                    <!-- Color Selection -->
                    <div>
                        <label class="block text-sm font-medium mb-3">Category Color</label>
                        <div class="flex flex-wrap gap-3" id="editColorContainer">
                            <?php foreach ($colorOptions as $colorCode => $colorName): ?>
                            <div class="color-option" 
                                 style="background-color: <?php echo $colorCode; ?>"
                                 onclick="selectColor(this, '<?php echo $colorCode; ?>', 'edit')"
                                 title="<?php echo $colorName; ?>"></div>
                            <?php endforeach; ?>
                        </div>
                        <input type="hidden" name="color" id="editCategoryColor">
                    </div>
                    
                    <!-- Description -->
                    <div>
                        <label class="block text-sm font-medium mb-2">Description</label>
                        <textarea name="description" 
                                  id="editCategoryDescription"
                                  rows="4"
                                  class="w-full px-4 py-3 bg-gray-100 dark:bg-gray-700 border-0 rounded-xl focus:ring-2 focus:ring-indigo-500"></textarea>
                    </div>
                    
                    <!-- Active Status -->
                    <div>
                        <label class="flex items-center space-x-3">
                            <input type="checkbox" name="is_active" id="editCategoryActive" value="1" class="w-5 h-5 text-indigo-600 rounded border-gray-300 focus:ring-indigo-500">
                            <span class="text-sm font-medium">Active (visible to users)</span>
                        </label>
                    </div>
                </div>
                
                <div class="flex items-center justify-end space-x-4 mt-8">
                    <button type="button" onclick="closeEditCategoryModal()" class="px-6 py-3 border border-gray-300 dark:border-gray-600 rounded-xl hover:bg-gray-100 dark:hover:bg-gray-700 transition">
                        Cancel
                    </button>
                    <button type="submit" class="px-6 py-3 bg-gradient-to-r from-indigo-600 to-purple-600 text-white rounded-xl hover:shadow-lg transition-all duration-300">
                        <i class="fas fa-save mr-2"></i>
                        Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Category Jobs Modal -->
<div id="viewJobsModal" class="fixed inset-0 z-50 flex items-center justify-center hidden modal">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeViewJobsModal()"></div>
    <div class="relative bg-white dark:bg-gray-800 rounded-3xl shadow-2xl w-full max-w-7xl mx-4 max-h-[90vh] overflow-y-auto">
        <div class="p-8">
            <div class="flex items-center justify-between mb-6 sticky top-0 bg-white dark:bg-gray-800 z-10 pb-4 border-b dark:border-gray-700">
                <div>
                    <h2 class="text-2xl font-bold" id="modalCategoryTitle">Jobs in Category</h2>
                    <p class="text-gray-500 mt-1" id="modalJobCount"></p>
                </div>
                <div class="flex items-center space-x-3">
                    <button onclick="exportCategoryJobs()" class="px-4 py-2 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-xl hover:bg-gray-200 dark:hover:bg-gray-600 transition">
                        <i class="fas fa-download mr-2"></i>Export
                    </button>
                    <button onclick="closeViewJobsModal()" class="w-10 h-10 bg-gray-100 dark:bg-gray-700 rounded-lg flex items-center justify-center hover:bg-gray-200 dark:hover:bg-gray-600 transition">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
            
            <!-- Loading Spinner -->
            <div id="jobsLoadingSpinner" class="text-center py-12">
                <div class="inline-block animate-spin rounded-full h-12 w-12 border-4 border-indigo-600 border-t-transparent"></div>
                <p class="mt-4 text-gray-500">Loading jobs...</p>
            </div>
            
            <!-- Jobs Table -->
            <div id="jobsTableContainer" style="display: none;">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700" id="jobsDataTable">
                        <thead>
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Job Details</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Company</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Location</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Salary</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Applications</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Posted</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700" id="jobsTableBody">
                            <!-- Jobs will be inserted here -->
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- No Jobs Message -->
            <div id="noJobsMessage" class="text-center py-16 hidden">
                <div class="w-24 h-24 bg-gray-100 dark:bg-gray-700 rounded-full flex items-center justify-center mx-auto mb-4">
                    <i class="fas fa-briefcase text-4xl text-gray-400"></i>
                </div>
                <h3 class="text-xl font-bold mb-2">No Jobs Found</h3>
                <p class="text-gray-500 mb-6 max-w-md mx-auto">There are no jobs in this category yet. Jobs will appear here when employers post them.</p>
                <button onclick="closeViewJobsModal()" class="px-6 py-3 bg-indigo-600 text-white rounded-xl hover:bg-indigo-700 transition">
                    Close
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="deleteConfirmModal" class="fixed inset-0 z-50 flex items-center justify-center hidden modal">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeDeleteModal()"></div>
    <div class="relative bg-white dark:bg-gray-800 rounded-3xl shadow-2xl w-full max-w-md mx-4">
        <div class="p-8 text-center">
            <div class="w-20 h-20 bg-red-100 dark:bg-red-900/30 rounded-full flex items-center justify-center mx-auto mb-4">
                <i class="fas fa-exclamation-triangle text-4xl text-red-600"></i>
            </div>
            
            <h2 class="text-2xl font-bold mb-2">Delete Category</h2>
            <p class="text-gray-500 mb-2">Are you sure you want to delete <span id="deleteCategoryName" class="font-semibold text-gray-900 dark:text-white"></span>?</p>
            <p id="deleteWarning" class="text-sm text-red-500 mb-6"></p>
            
            <form method="POST" action="" id="deleteForm">
                <input type="hidden" name="action" value="delete_category">
                <input type="hidden" name="id" id="deleteCategoryId">
                
                <div class="flex space-x-4">
                    <button type="button" onclick="closeDeleteModal()" class="flex-1 px-6 py-3 border border-gray-300 dark:border-gray-600 rounded-xl hover:bg-gray-100 dark:hover:bg-gray-700 transition">
                        Cancel
                    </button>
                    <button type="submit" class="flex-1 px-6 py-3 bg-red-600 text-white rounded-xl hover:bg-red-700 transition" id="deleteConfirmButton">
                        <i class="fas fa-trash mr-2"></i>
                        Delete
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Export Modal -->
<div id="exportModal" class="fixed inset-0 z-50 flex items-center justify-center hidden modal">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeExportModal()"></div>
    <div class="relative bg-white dark:bg-gray-800 rounded-3xl shadow-2xl w-full max-w-md mx-4">
        <div class="p-8">
            <div class="flex items-center justify-between mb-6">
                <h2 class="text-2xl font-bold">Export Categories</h2>
                <button onclick="closeExportModal()" class="w-10 h-10 bg-gray-100 dark:bg-gray-700 rounded-lg flex items-center justify-center hover:bg-gray-200 dark:hover:bg-gray-600 transition">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <div class="space-y-4">
                <p class="text-gray-600 dark:text-gray-400">Choose export format:</p>
                
                <button onclick="exportAs('csv')" class="w-full flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-700 rounded-xl hover:bg-indigo-50 dark:hover:bg-indigo-900/20 transition group">
                    <div class="flex items-center space-x-3">
                        <div class="w-10 h-10 bg-green-100 dark:bg-green-900/30 rounded-lg flex items-center justify-center">
                            <i class="fas fa-file-csv text-green-600 text-xl"></i>
                        </div>
                        <div class="text-left">
                            <p class="font-semibold">CSV Format</p>
                            <p class="text-xs text-gray-500">Comma separated values</p>
                        </div>
                    </div>
                    <i class="fas fa-download text-gray-400 group-hover:text-indigo-600 transition"></i>
                </button>
                
                <button onclick="exportAs('excel')" class="w-full flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-700 rounded-xl hover:bg-indigo-50 dark:hover:bg-indigo-900/20 transition group">
                    <div class="flex items-center space-x-3">
                        <div class="w-10 h-10 bg-green-600 rounded-lg flex items-center justify-center">
                            <i class="fas fa-file-excel text-white text-xl"></i>
                        </div>
                        <div class="text-left">
                            <p class="font-semibold">Excel Format</p>
                            <p class="text-xs text-gray-500">Microsoft Excel spreadsheet</p>
                        </div>
                    </div>
                    <i class="fas fa-download text-gray-400 group-hover:text-indigo-600 transition"></i>
                </button>
                
                <button onclick="exportAs('pdf')" class="w-full flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-700 rounded-xl hover:bg-indigo-50 dark:hover:bg-indigo-900/20 transition group">
                    <div class="flex items-center space-x-3">
                        <div class="w-10 h-10 bg-red-500 rounded-lg flex items-center justify-center">
                            <i class="fas fa-file-pdf text-white text-xl"></i>
                        </div>
                        <div class="text-left">
                            <p class="font-semibold">PDF Format</p>
                            <p class="text-xs text-gray-500">Portable Document Format</p>
                        </div>
                    </div>
                    <i class="fas fa-download text-gray-400 group-hover:text-indigo-600 transition"></i>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Scripts -->
<script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

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

// Performance Chart
<?php if (!empty($categoryPerformance)): ?>
let performanceChart;
let currentChartType = 'bar';

function initPerformanceChart() {
    const ctx = document.getElementById('performanceChart').getContext('2d');
    
    performanceChart = new Chart(ctx, {
        type: currentChartType,
        data: {
            labels: <?php echo json_encode($chartLabels); ?>,
            datasets: [
                {
                    label: 'Jobs',
                    data: <?php echo json_encode($chartJobData); ?>,
                    backgroundColor: 'rgba(79, 70, 229, 0.8)',
                    borderColor: '#4f46e5',
                    borderWidth: 2,
                    borderRadius: 6
                },
                {
                    label: 'Applications',
                    data: <?php echo json_encode($chartAppData); ?>,
                    backgroundColor: 'rgba(124, 58, 237, 0.8)',
                    borderColor: '#7c3aed',
                    borderWidth: 2,
                    borderRadius: 6
                },
                {
                    label: 'Hires',
                    data: <?php echo json_encode($chartHireData); ?>,
                    backgroundColor: 'rgba(16, 185, 129, 0.8)',
                    borderColor: '#10b981',
                    borderWidth: 2,
                    borderRadius: 6
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'top',
                    labels: {
                        usePointStyle: true,
                        boxWidth: 8
                    }
                },
                tooltip: {
                    mode: 'index',
                    intersect: false,
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
                        minRotation: 45
                    }
                }
            }
        }
    });
}

function toggleChartType(type) {
    currentChartType = type;
    if (performanceChart) {
        performanceChart.destroy();
    }
    initPerformanceChart();
    
    // Update button styles
    document.querySelectorAll('[onclick*="toggleChartType"]').forEach(btn => {
        btn.classList.remove('bg-indigo-100', 'text-indigo-600');
        btn.classList.add('bg-gray-100', 'text-gray-600');
    });
    event.target.closest('button').classList.remove('bg-gray-100', 'text-gray-600');
    event.target.closest('button').classList.add('bg-indigo-100', 'text-indigo-600');
}

initPerformanceChart();
<?php endif; ?>

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

// Auto-generate slug from name
document.getElementById('categoryName')?.addEventListener('input', function() {
    const slugField = document.getElementById('categorySlug');
    if (slugField.value === '') {
        slugField.value = this.value
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-|-$/g, '');
    }
});

// Icon Selection
function selectIcon(element, modal) {
    // Remove selected class from all icons in this modal
    const container = modal === 'add' ? document.querySelector('#addCategoryModal .grid') : document.getElementById('editIconContainer');
    container.querySelectorAll('.icon-option').forEach(opt => {
        opt.classList.remove('selected');
    });
    
    // Add selected class to clicked icon
    element.classList.add('selected');
    
    // Update hidden input
    const iconClass = element.getAttribute('data-icon');
    document.getElementById(modal === 'add' ? 'addCategoryIcon' : 'editCategoryIcon').value = iconClass;
}

// Color Selection
function selectColor(element, colorCode, modal) {
    // Remove selected class from all colors in this modal
    const container = modal === 'add' ? document.querySelector('#addCategoryModal .flex-wrap') : document.getElementById('editColorContainer');
    container.querySelectorAll('.color-option').forEach(opt => {
        opt.classList.remove('selected');
    });
    
    // Add selected class to clicked color
    element.classList.add('selected');
    
    // Update hidden input
    document.getElementById(modal === 'add' ? 'addCategoryColor' : 'editCategoryColor').value = colorCode;
}

// Modal Functions
function openAddCategoryModal() {
    document.getElementById('addCategoryModal').classList.remove('hidden');
    document.getElementById('addCategoryModal').classList.add('show');
}

function closeAddCategoryModal() {
    document.getElementById('addCategoryModal').classList.add('hidden');
    document.getElementById('addCategoryModal').classList.remove('show');
    document.getElementById('addCategoryForm').reset();
    
    // Reset icon and color selections
    document.querySelectorAll('#addCategoryModal .icon-option').forEach(opt => {
        opt.classList.remove('selected');
    });
    document.querySelector('#addCategoryModal .icon-option[data-icon="fa-folder"]').classList.add('selected');
    document.getElementById('addCategoryIcon').value = 'fa-folder';
    
    document.querySelectorAll('#addCategoryModal .color-option').forEach(opt => {
        opt.classList.remove('selected');
    });
    document.querySelector('#addCategoryModal .color-option[style*="#4f46e5"]').classList.add('selected');
    document.getElementById('addCategoryColor').value = '#4f46e5';
}

function openEditCategoryModal(categoryId) {
    // Fetch category data via AJAX
    fetch(`categories.php?ajax=get_category&id=${categoryId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const cat = data.category;
                
                document.getElementById('editCategoryId').value = cat.id;
                document.getElementById('editCategoryName').value = cat.name;
                document.getElementById('editCategorySlug').value = cat.slug;
                document.getElementById('editCategoryDescription').value = cat.description || '';
                document.getElementById('editCategoryActive').checked = cat.is_active == 1;
                
                // Set icon
                const iconClass = cat.icon || 'fa-folder';
                document.getElementById('editCategoryIcon').value = iconClass;
                document.querySelectorAll('#editIconContainer .icon-option').forEach(opt => {
                    opt.classList.remove('selected');
                    if (opt.getAttribute('data-icon') === iconClass) {
                        opt.classList.add('selected');
                    }
                });
                
                // Set color
                const colorCode = cat.color || '#4f46e5';
                document.getElementById('editCategoryColor').value = colorCode;
                document.querySelectorAll('#editColorContainer .color-option').forEach(opt => {
                    opt.classList.remove('selected');
                    if (opt.style.backgroundColor === colorCode || 
                        (colorCode === '#4f46e5' && opt.style.backgroundColor === 'rgb(79, 70, 229)')) {
                        opt.classList.add('selected');
                    }
                });
                
                document.getElementById('editCategoryModal').classList.remove('hidden');
                document.getElementById('editCategoryModal').classList.add('show');
            } else {
                Swal.fire('Error', data.message || 'Failed to load category', 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            Swal.fire('Error', 'Failed to load category data', 'error');
        });
}

function closeEditCategoryModal() {
    document.getElementById('editCategoryModal').classList.add('hidden');
    document.getElementById('editCategoryModal').classList.remove('show');
}

function viewCategoryJobs(categoryId, categoryName) {
    document.getElementById('modalCategoryTitle').textContent = `Jobs in "${categoryName}"`;
    document.getElementById('viewJobsModal').classList.remove('hidden');
    document.getElementById('viewJobsModal').classList.add('show');
    
    // Show loading, hide table and no jobs message
    document.getElementById('jobsLoadingSpinner').style.display = 'block';
    document.getElementById('jobsTableContainer').style.display = 'none';
    document.getElementById('noJobsMessage').classList.add('hidden');
    document.getElementById('modalJobCount').textContent = '';
    
    // Fetch jobs via AJAX
    fetch(`categories.php?ajax=get_category_jobs&category_id=${categoryId}`)
        .then(response => response.json())
        .then(data => {
            document.getElementById('jobsLoadingSpinner').style.display = 'none';
            
            if (data.success && data.jobs && data.jobs.length > 0) {
                document.getElementById('modalJobCount').textContent = `${data.jobs.length} job(s) found in this category`;
                displayJobsInModal(data.jobs);
                document.getElementById('jobsTableContainer').style.display = 'block';
            } else {
                document.getElementById('noJobsMessage').classList.remove('hidden');
                document.getElementById('modalJobCount').textContent = '0 jobs found';
            }
        })
        .catch(error => {
            console.error('Error:', error);
            document.getElementById('jobsLoadingSpinner').style.display = 'none';
            document.getElementById('noJobsMessage').classList.remove('hidden');
            document.getElementById('noJobsMessage').querySelector('p').textContent = 'Error loading jobs. Please try again.';
        });
}

function displayJobsInModal(jobs) {
    const tbody = document.getElementById('jobsTableBody');
    tbody.innerHTML = '';
    
    jobs.forEach(job => {
        const row = document.createElement('tr');
        row.className = 'job-row';
        
        // Status badge class
        const statusClass = job.status === 'active' ? 'job-status-active' :
                           job.status === 'paused' ? 'job-status-paused' :
                           job.status === 'draft' ? 'job-status-draft' :
                           job.status === 'closed' ? 'job-status-closed' :
                           'job-status-expired';
        
        // Format salary
        let salaryText = 'Not specified';
        if (job.salary_min && job.salary_max) {
            const currency = job.salary_currency || 'USD';
            salaryText = `${formatNumber(job.salary_min)} - ${formatNumber(job.salary_max)} ${currency}`;
        } else if (job.salary_min) {
            salaryText = `From ${formatNumber(job.salary_min)} ${job.salary_currency || 'USD'}`;
        } else if (job.salary_max) {
            salaryText = `Up to ${formatNumber(job.salary_max)} ${job.salary_currency || 'USD'}`;
        }
        
        // Format location
        let locationText = job.location_type || 'Not specified';
        if (job.city) {
            locationText += `, ${job.city}`;
        }
        if (job.country) {
            locationText += `, ${job.country}`;
        }
        
        row.innerHTML = `
            <td class="px-6 py-4">
                <div>
                    <div class="font-medium text-indigo-600 dark:text-indigo-400">${escapeHtml(job.title)}</div>
                    <div class="flex items-center mt-1 space-x-2">
                        ${job.is_featured ? '<span class="text-xs bg-yellow-500 text-white px-2 py-0.5 rounded-full">Featured</span>' : ''}
                        ${job.is_urgent ? '<span class="text-xs bg-red-500 text-white px-2 py-0.5 rounded-full">Urgent</span>' : ''}
                    </div>
                </div>
            </td>
            <td class="px-6 py-4">
                <div class="flex items-center">
                    <img src="${job.company_logo || 'https://ui-avatars.com/api/?name=' + encodeURIComponent(job.company_name) + '&background=4f46e5&color=fff'}" 
                         alt="" 
                         class="w-8 h-8 rounded-lg object-cover mr-3"
                         onerror="this.src='https://ui-avatars.com/api/?name=' + encodeURIComponent('${job.company_name}') + '&background=4f46e5&color=fff'">
                    <div>
                        <div class="font-medium">${escapeHtml(job.company_name)}</div>
                        ${job.employer_verified ? '<i class="fas fa-check-circle text-blue-500 text-xs" title="Verified Employer"></i>' : ''}
                        ${job.industry ? `<div class="text-xs text-gray-500">${escapeHtml(job.industry)}</div>` : ''}
                    </div>
                </div>
            </td>
            <td class="px-6 py-4">
                <span class="text-sm">${escapeHtml(job.job_type_name || 'N/A')}</span>
            </td>
            <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-400">
                <i class="fas fa-map-marker-alt mr-1 text-gray-400"></i>
                ${escapeHtml(locationText)}
            </td>
            <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-400">
                ${escapeHtml(salaryText)}
            </td>
            <td class="px-6 py-4">
                <span class="px-3 py-1 rounded-full text-xs font-semibold ${statusClass}">
                    ${escapeHtml(job.status).toUpperCase()}
                </span>
            </td>
            <td class="px-6 py-4 text-center">
                <span class="font-semibold">${job.application_count || 0}</span>
            </td>
            <td class="px-6 py-4 text-sm text-gray-500">
                ${timeAgo(job.created_at)}
            </td>
            <td class="px-6 py-4">
                <div class="flex space-x-2">
                    <a href="job-details.php?id=${job.id}" target="_blank" 
                       class="text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300 p-2 hover:bg-indigo-50 dark:hover:bg-indigo-900/20 rounded-lg transition"
                       title="View Details">
                        <i class="fas fa-external-link-alt"></i>
                    </a>
                    <a href="../employer/application-details.php?job_id=${job.id}" target="_blank"
                       class="text-green-600 hover:text-green-800 dark:text-green-400 dark:hover:text-green-300 p-2 hover:bg-green-50 dark:hover:bg-green-900/20 rounded-lg transition"
                       title="View Applications">
                        <i class="fas fa-file-alt"></i>
                    </a>
                </div>
            </td>
        `;
        
        tbody.appendChild(row);
    });
    
    // Initialize DataTable
    if ($.fn.DataTable.isDataTable('#jobsDataTable')) {
        $('#jobsDataTable').DataTable().destroy();
    }
    
    $('#jobsDataTable').DataTable({
        pageLength: 10,
        lengthMenu: [[10, 25, 50, -1], [10, 25, 50, "All"]],
        responsive: true,
        language: {
            search: "_INPUT_",
            searchPlaceholder: "Search jobs...",
            lengthMenu: "Show _MENU_ jobs",
            info: "Showing _START_ to _END_ of _TOTAL_ jobs",
            infoEmpty: "Showing 0 to 0 of 0 jobs",
            infoFiltered: "(filtered from _MAX_ total jobs)",
            paginate: {
                first: "First",
                last: "Last",
                next: "Next",
                previous: "Previous"
            }
        },
        columnDefs: [
            { orderable: false, targets: [8] }
        ]
    });
}

function closeViewJobsModal() {
    document.getElementById('viewJobsModal').classList.add('hidden');
    document.getElementById('viewJobsModal').classList.remove('show');
    
    // Destroy DataTable instance
    if ($.fn.DataTable.isDataTable('#jobsDataTable')) {
        $('#jobsDataTable').DataTable().destroy();
    }
}

function toggleCategoryStatus(id, newStatus) {
    const action = newStatus ? 'activate' : 'deactivate';
    const actionText = newStatus ? 'activate' : 'deactivate';
    
    Swal.fire({
        title: `Confirm ${actionText}`,
        text: `Are you sure you want to ${actionText} this category?`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: newStatus ? '#10b981' : '#f59e0b',
        cancelButtonColor: '#6b7280',
        confirmButtonText: `Yes, ${actionText}!`
    }).then((result) => {
        if (result.isConfirmed) {
            fetch(`categories.php?ajax=toggle_status&id=${id}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Success!',
                            text: data.message,
                            timer: 2000,
                            showConfirmButton: false
                        }).then(() => {
                            location.reload();
                        });
                    } else {
                        Swal.fire('Error', data.message, 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    Swal.fire('Error', 'Failed to update status', 'error');
                });
        }
    });
}

function confirmDeleteCategory(id, name, jobCount) {
    document.getElementById('deleteCategoryId').value = id;
    document.getElementById('deleteCategoryName').textContent = name;
    
    const warningEl = document.getElementById('deleteWarning');
    const deleteBtn = document.getElementById('deleteConfirmButton');
    
    if (jobCount > 0) {
        warningEl.textContent = `This category has ${jobCount} job(s). You cannot delete it until all jobs are reassigned.`;
        deleteBtn.disabled = true;
        deleteBtn.classList.add('opacity-50', 'cursor-not-allowed');
    } else {
        warningEl.textContent = 'This action cannot be undone.';
        deleteBtn.disabled = false;
        deleteBtn.classList.remove('opacity-50', 'cursor-not-allowed');
    }
    
    document.getElementById('deleteConfirmModal').classList.remove('hidden');
    document.getElementById('deleteConfirmModal').classList.add('show');
}

function closeDeleteModal() {
    document.getElementById('deleteConfirmModal').classList.add('hidden');
    document.getElementById('deleteConfirmModal').classList.remove('show');
}

// Bulk Actions
let selectedCategories = new Set();

function updateBulkSelection() {
    const checkboxes = document.querySelectorAll('.category-checkbox:checked');
    selectedCategories = new Set(Array.from(checkboxes).map(cb => cb.value));
    
    const bulkBar = document.getElementById('bulkActionsBar');
    const selectedCount = document.getElementById('selectedCount');
    
    if (selectedCategories.size > 0) {
        bulkBar.style.display = 'block';
        selectedCount.textContent = `${selectedCategories.size} item(s) selected`;
    } else {
        bulkBar.style.display = 'none';
    }
}

function selectAllCategories() {
    document.querySelectorAll('.category-checkbox').forEach(cb => {
        cb.checked = true;
    });
    updateBulkSelection();
}

function clearSelection() {
    document.querySelectorAll('.category-checkbox').forEach(cb => {
        cb.checked = false;
    });
    selectedCategories.clear();
    document.getElementById('bulkActionsBar').style.display = 'none';
}

function executeBulkAction() {
    const action = document.getElementById('bulkActionSelect').value;
    
    if (!action) {
        Swal.fire({
            icon: 'warning',
            title: 'No Action Selected',
            text: 'Please select an action to perform.'
        });
        return;
    }
    
    if (selectedCategories.size === 0) {
        Swal.fire({
            icon: 'warning',
            title: 'No Categories Selected',
            text: 'Please select at least one category.'
        });
        return;
    }
    
    let confirmMessage = '';
    let confirmButtonColor = '#4f46e5';
    
    switch (action) {
        case 'activate':
            confirmMessage = `Are you sure you want to activate ${selectedCategories.size} selected categorie(s)?`;
            confirmButtonColor = '#10b981';
            break;
        case 'deactivate':
            confirmMessage = `Are you sure you want to deactivate ${selectedCategories.size} selected categorie(s)?`;
            confirmButtonColor = '#f59e0b';
            break;
        case 'delete':
            confirmMessage = `Are you sure you want to delete ${selectedCategories.size} selected categorie(s)? This action cannot be undone.`;
            confirmButtonColor = '#ef4444';
            break;
    }
    
    Swal.fire({
        title: 'Confirm Action',
        text: confirmMessage,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: confirmButtonColor,
        cancelButtonColor: '#6b7280',
        confirmButtonText: 'Yes, proceed!'
    }).then((result) => {
        if (result.isConfirmed) {
            // Create form and submit
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '';
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'bulk_action';
            form.appendChild(actionInput);
            
            const bulkActionInput = document.createElement('input');
            bulkActionInput.type = 'hidden';
            bulkActionInput.name = 'bulk_action';
            bulkActionInput.value = action;
            form.appendChild(bulkActionInput);
            
            selectedCategories.forEach(id => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'category_ids[]';
                input.value = id;
                form.appendChild(input);
            });
            
            document.body.appendChild(form);
            form.submit();
        }
    });
}

// Export Functions
function exportCategories() {
    document.getElementById('exportModal').classList.remove('hidden');
    document.getElementById('exportModal').classList.add('show');
}

function closeExportModal() {
    document.getElementById('exportModal').classList.add('hidden');
    document.getElementById('exportModal').classList.remove('show');
}

function exportAs(format) {
    closeExportModal();
    
    // Show loading
    Swal.fire({
        title: 'Exporting...',
        text: 'Please wait while we generate your export.',
        allowOutsideClick: false,
        didOpen: () => {
            Swal.showLoading();
        }
    });
    
    // Build export URL
    const params = new URLSearchParams(window.location.search);
    params.append('export', format);
    
    // Redirect to export endpoint
    window.location.href = `export-categories.php?${params.toString()}`;
    
    // Close loading after a delay
    setTimeout(() => {
        Swal.close();
    }, 2000);
}

function exportCategoryJobs() {
    // Get current category ID from modal
    const categoryId = document.querySelector('.category-card:hover')?.getAttribute('data-category-id');
    if (categoryId) {
        window.location.href = `export-jobs.php?category_id=${categoryId}`;
    }
}

// Helper Functions
function escapeHtml(unsafe) {
    if (!unsafe) return '';
    return String(unsafe)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

function formatNumber(num) {
    if (!num) return '0';
    return new Intl.NumberFormat().format(num);
}

function timeAgo(date) {
    if (!date) return 'N/A';
    
    const seconds = Math.floor((new Date() - new Date(date)) / 1000);
    
    let interval = Math.floor(seconds / 31536000);
    if (interval > 1) return interval + ' years ago';
    
    interval = Math.floor(seconds / 2592000);
    if (interval >= 1) return interval + ' month' + (interval > 1 ? 's' : '') + ' ago';
    
    interval = Math.floor(seconds / 86400);
    if (interval >= 1) return interval + ' day' + (interval > 1 ? 's' : '') + ' ago';
    
    interval = Math.floor(seconds / 3600);
    if (interval >= 1) return interval + ' hour' + (interval > 1 ? 's' : '') + ' ago';
    
    interval = Math.floor(seconds / 60);
    if (interval >= 1) return interval + ' minute' + (interval > 1 ? 's' : '') + ' ago';
    
    return 'just now';
}

// Auto-hide flash message
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
    // Escape to close modals
    if (e.key === 'Escape') {
        closeAddCategoryModal();
        closeEditCategoryModal();
        closeViewJobsModal();
        closeDeleteModal();
        closeExportModal();
        mobileSidebar?.classList.add('hidden');
    }
    
    // Ctrl/Cmd + N to add new category
    if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
        e.preventDefault();
        openAddCategoryModal();
    }
    
    // Ctrl/Cmd + F to focus search
    if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
        e.preventDefault();
        document.querySelector('input[name="search"]')?.focus();
    }
    
    // Ctrl/Cmd + E to export
    if ((e.ctrlKey || e.metaKey) && e.key === 'e') {
        e.preventDefault();
        exportCategories();
    }
});

// Prevent checkbox click from triggering card click
document.querySelectorAll('.category-checkbox').forEach(cb => {
    cb.addEventListener('click', (e) => e.stopPropagation());
});

// Infinite scroll or load more (optional)
let loading = false;
let currentPage = <?php echo $page; ?>;
let totalPages = <?php echo $totalPages; ?>;

window.addEventListener('scroll', () => {
    if (loading || currentPage >= totalPages) return;
    
    const scrollHeight = document.documentElement.scrollHeight;
    const scrollTop = document.documentElement.scrollTop;
    const clientHeight = document.documentElement.clientHeight;
    
    if (scrollTop + clientHeight >= scrollHeight - 200) {
        loading = true;
        currentPage++;
        
        // Show loading indicator
        const loader = document.createElement('div');
        loader.className = 'text-center py-8';
        loader.innerHTML = '<div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600"></div><p class="mt-2 text-gray-500">Loading more...</p>';
        document.querySelector('.categories-grid').appendChild(loader);
        
        // Fetch next page via AJAX
        const url = new URL(window.location.href);
        url.searchParams.set('page', currentPage);
        url.searchParams.set('ajax', 'load_more');
        
        fetch(url.toString())
            .then(response => response.text())
            .then(html => {
                loader.remove();
                // Append new cards
                const temp = document.createElement('div');
                temp.innerHTML = html;
                const newCards = temp.querySelectorAll('.category-card');
                newCards.forEach(card => {
                    document.querySelector('.categories-grid').appendChild(card);
                });
                loading = false;
            })
            .catch(error => {
                console.error('Error:', error);
                loader.remove();
                loading = false;
            });
    }
});
</script>
</body>
</html>