<?php
/**
 * admin/jobs.php - Admin Jobs Management
 * Version: 7.0 - Added Company Management Integration
 */

// Error reporting for development
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Set timezone
date_default_timezone_set('Africa/Accra');

// Include configuration files
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../includes/auth.php';

// Require admin login
requireAdminLogin();

// Get database connection with error handling
try {
    $pdo = getDB();
} catch (Exception $e) {
    $_SESSION['error'] = 'Database connection failed: ' . $e->getMessage();
    header('Location: /error.php');
    exit();
}

// Get admin info
try {
    $adminData = fetchOne("
        SELECT u.* 
        FROM users u 
        WHERE u.id = ? AND u.user_type = 'admin'
    ", [$_SESSION['user_id']]);
} catch (Exception $e) {
    error_log("Admin fetch error: " . $e->getMessage());
    $adminData = null;
}

if (!$adminData) {
    $_SESSION['error'] = 'Admin account not found';
    header('Location: /logout.php');
    exit();
}

// ==================== HELPER FUNCTIONS ====================

/**
 * Get status badge class for admin display
 */
function getAdminStatusBadgeClass($status) {
    $classes = [
        'Active' => 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400',
        'Draft' => 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300',
        'Paused' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400',
        'Closed' => 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400',
        'Expired' => 'bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-400'
    ];
    return $classes[$status] ?? 'bg-gray-100 text-gray-800';
}

/**
 * Format salary for admin display
 */
function formatAdminSalary($min, $max, $currency, $type) {
    if (!$min && !$max) return '<span class="text-gray-400">Not specified</span>';
    
    $symbol = $currency === 'GHS' ? '₵' : ($currency === 'USD' ? '$' : $currency);
    $period = $type === 'Monthly' ? 'mo' : ($type === 'Yearly' ? 'yr' : 'hr');
    
    if ($min && $max && $min != $max) {
        return $symbol . number_format($min) . ' - ' . $symbol . number_format($max) . '/' . $period;
    } elseif ($min) {
        return $symbol . number_format($min) . '/' . $period;
    } elseif ($max) {
        return 'Up to ' . $symbol . number_format($max) . '/' . $period;
    }
    return '<span class="text-gray-400">Not specified</span>';
}

/**
 * Generate URL-friendly slug from title
 */
function generateSlug($title, $pdo, $excludeId = 0) {
    $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($title));
    $slug = trim($slug, '-');
    if (empty($slug)) $slug = 'job';
    
    try {
        $checkSlug = fetchOne("SELECT id FROM jobs WHERE slug = ? AND id != ?", [$slug, $excludeId]);
        if ($checkSlug) {
            $slug .= '-' . uniqid();
        }
    } catch (Exception $e) {
        $slug .= '-' . time();
    }
    
    return $slug;
}

/**
 * Build query string for redirects
 */
function buildRedirectQuery() {
    $params = $_GET;
    unset($params['ajax'], $params['_']);
    return !empty($params) ? '?' . http_build_query($params) : '';
}

// ==================== HANDLE AJAX REQUESTS ====================

// Handle View Job AJAX request
if (isset($_GET['ajax']) && $_GET['ajax'] === 'view_job' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    
    try {
        $jobId = (int)$_GET['id'];
        
        $jobDetails = fetchOne("
            SELECT 
                j.*,
                c.name as company_name,
                c.email as company_email,
                c.phone as company_phone,
                c.website as company_website,
                c.logo as company_logo,
                c.description as company_description,
                c.address as company_address,
                (SELECT COUNT(*) FROM comments WHERE job_id = j.id AND is_deleted = 0) as total_comments
            FROM jobs j
            LEFT JOIN companies c ON j.company_id = c.id
            WHERE j.id = ?
        ", [$jobId]);
        
        if ($jobDetails) {
            $jobDetails['recent_comments'] = fetchAll("
                SELECT 
                    c.id,
                    c.author_name,
                    c.author_type,
                    c.content,
                    c.created_at
                FROM comments c
                WHERE c.job_id = ? AND c.is_deleted = 0
                ORDER BY c.created_at DESC
                LIMIT 5
            ", [$jobId]);
            
            echo json_encode(['success' => true, 'data' => $jobDetails]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Job not found']);
        }
    } catch (Exception $e) {
        error_log("AJAX view error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Error loading job details']);
    }
    exit();
}

// Handle Search Companies AJAX
if (isset($_GET['ajax']) && $_GET['ajax'] === 'search_companies' && isset($_GET['q'])) {
    header('Content-Type: application/json');
    
    try {
        $search = '%' . $_GET['q'] . '%';
        $companies = fetchAll("
            SELECT id, name, email, phone, logo 
            FROM companies 
            WHERE name LIKE ? OR email LIKE ? 
            ORDER BY name 
            LIMIT 10
        ", [$search, $search]);
        
        echo json_encode(['success' => true, 'data' => $companies]);
    } catch (Exception $e) {
        error_log("Company search error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Error searching companies']);
    }
    exit();
}

// Handle Get Company Details AJAX
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_company' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    
    try {
        $companyId = (int)$_GET['id'];
        $company = fetchOne("SELECT * FROM companies WHERE id = ?", [$companyId]);
        
        if ($company) {
            echo json_encode(['success' => true, 'data' => $company]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Company not found']);
        }
    } catch (Exception $e) {
        error_log("Get company error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Error loading company']);
    }
    exit();
}

// ==================== HANDLE POST ACTIONS ====================

// Only process POST requests for actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $redirectQuery = buildRedirectQuery();
    
    try {
        // Handle Delete Job
        if (isset($_POST['action']) && $_POST['action'] === 'delete' && isset($_POST['job_id'])) {
            $jobId = (int)$_POST['job_id'];
            
            $pdo->beginTransaction();
            
            // Check if job has comments
            $check = $pdo->prepare("SELECT COUNT(*) FROM comments WHERE job_id = ?");
            $check->execute([$jobId]);
            $commentCount = $check->fetchColumn();
            
            // Soft delete comments first
            $stmt = $pdo->prepare("UPDATE comments SET is_deleted = 1, deleted_at = NOW() WHERE job_id = ?");
            $stmt->execute([$jobId]);
            
            // Delete the job
            $stmt = $pdo->prepare("DELETE FROM jobs WHERE id = ?");
            $stmt->execute([$jobId]);
            
            $pdo->commit();
            
            $_SESSION['success'] = 'Job deleted successfully' . ($commentCount > 0 ? " ({$commentCount} comments archived)" : "");
        }
        
        // Handle Status Update
        elseif (isset($_POST['action']) && $_POST['action'] === 'update_status' && isset($_POST['job_id']) && isset($_POST['status'])) {
            $jobId = (int)$_POST['job_id'];
            $status = $_POST['status'];
            
            $allowedStatuses = ['Draft', 'Active', 'Paused', 'Closed', 'Expired'];
            
            if (in_array($status, $allowedStatuses)) {
                if ($status === 'Active') {
                    $stmt = $pdo->prepare("UPDATE jobs SET status = ?, updated_at = NOW(), published_at = COALESCE(published_at, NOW()) WHERE id = ?");
                    $stmt->execute([$status, $jobId]);
                } else {
                    $stmt = $pdo->prepare("UPDATE jobs SET status = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$status, $jobId]);
                }
                $_SESSION['success'] = 'Job status updated successfully';
            } else {
                $_SESSION['error'] = 'Invalid status value';
            }
        }
        
        // Handle Featured Toggle
        elseif (isset($_POST['action']) && $_POST['action'] === 'toggle_featured' && isset($_POST['job_id'])) {
            $jobId = (int)$_POST['job_id'];
            
            $current = fetchOne("SELECT is_featured FROM jobs WHERE id = ?", [$jobId]);
            if ($current) {
                $newValue = $current['is_featured'] ? 0 : 1;
                $stmt = $pdo->prepare("UPDATE jobs SET is_featured = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$newValue, $jobId]);
                $_SESSION['success'] = $newValue ? 'Job marked as featured' : 'Job unfeatured';
            }
        }
        
        // Handle Urgent Toggle
        elseif (isset($_POST['action']) && $_POST['action'] === 'toggle_urgent' && isset($_POST['job_id'])) {
            $jobId = (int)$_POST['job_id'];
            
            $current = fetchOne("SELECT is_urgent FROM jobs WHERE id = ?", [$jobId]);
            if ($current) {
                $newValue = $current['is_urgent'] ? 0 : 1;
                $stmt = $pdo->prepare("UPDATE jobs SET is_urgent = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$newValue, $jobId]);
                $_SESSION['success'] = $newValue ? 'Job marked as urgent' : 'Job unmarked as urgent';
            }
        }
        
        // Handle Save Job (Create or Update)
        elseif (isset($_POST['save_job'])) {
            $jobId = isset($_POST['job_id']) ? (int)$_POST['job_id'] : 0;
            
            // Validate required fields
            $title = trim($_POST['title'] ?? '');
            $jobCategory = $_POST['job_category'] ?? 'Other';
            $employmentType = $_POST['employment_type'] ?? 'Full-time';
            $description = trim($_POST['description'] ?? '');
            
            $errors = [];
            if (empty($title)) $errors[] = 'Job title is required';
            if (empty($description)) $errors[] = 'Job description is required';
            
            if (!empty($errors)) {
                throw new Exception(implode('<br>', $errors));
            }
            
            $pdo->beginTransaction();
            
            // Handle Company - Create or Get ID
            $companyId = null;
            $companyAction = $_POST['company_action'] ?? 'existing';
            
            if ($companyAction === 'new' && !empty($_POST['new_company_name'])) {
                // Create new company
                $stmt = $pdo->prepare("
                    INSERT INTO companies (name, email, phone, website, description, address, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    trim($_POST['new_company_name']),
                    $_POST['new_company_email'] ?? null,
                    $_POST['new_company_phone'] ?? null,
                    $_POST['new_company_website'] ?? null,
                    $_POST['new_company_description'] ?? null,
                    $_POST['new_company_address'] ?? null
                ]);
                $companyId = $pdo->lastInsertId();
            } elseif ($companyAction === 'existing' && !empty($_POST['company_id'])) {
                $companyId = (int)$_POST['company_id'];
                // Verify company exists
                $check = fetchOne("SELECT id FROM companies WHERE id = ?", [$companyId]);
                if (!$check) {
                    $companyId = null;
                }
            }
            
            $slug = generateSlug($title, $pdo, $jobId);
            $salaryMin = !empty($_POST['salary_min']) ? (float)$_POST['salary_min'] : null;
            $salaryMax = !empty($_POST['salary_max']) ? (float)$_POST['salary_max'] : null;
            $overview = trim($_POST['overview'] ?? '');
            
            if ($jobId > 0) {
                // Update existing job
                $sql = "UPDATE jobs SET 
                    title = ?, slug = ?, overview = ?,
                    job_category = ?, employment_type = ?,
                    description = ?, requirements = ?, responsibilities = ?, benefits = ?,
                    experience_level = ?, salary_min = ?, salary_max = ?, salary_currency = ?,
                    salary_type = ?, is_salary_negotiable = ?,
                    location_type = ?, address = ?, city = ?, state = ?, country = ?,
                    application_email = ?, application_phone = ?, application_instructions = ?,
                    status = ?, is_featured = ?, is_urgent = ?, company_id = ?, updated_at = NOW()
                    WHERE id = ?";
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $title, $slug, $overview,
                    $jobCategory, $employmentType,
                    $description, $_POST['requirements'] ?? null, $_POST['responsibilities'] ?? null, $_POST['benefits'] ?? null,
                    $_POST['experience_level'] ?? 'Mid', $salaryMin, $salaryMax, $_POST['salary_currency'] ?? 'GHS',
                    $_POST['salary_type'] ?? 'Monthly', isset($_POST['is_salary_negotiable']) ? 1 : 0,
                    $_POST['location_type'] ?? 'Onsite', $_POST['address'] ?? null, $_POST['city'] ?? null, $_POST['state'] ?? null, $_POST['country'] ?? 'Ghana',
                    $_POST['application_email'] ?? null, $_POST['application_phone'] ?? null, $_POST['application_instructions'] ?? null,
                    $_POST['status'] ?? 'Draft', isset($_POST['is_featured']) ? 1 : 0, isset($_POST['is_urgent']) ? 1 : 0,
                    $companyId,
                    $jobId
                ]);
                
                $_SESSION['success'] = 'Job updated successfully';
            } else {
                // Insert new job
                $status = $_POST['status'] ?? 'Draft';
                $publishedAt = ($status === 'Active') ? date('Y-m-d H:i:s') : null;
                
                $sql = "INSERT INTO jobs (
                    title, slug, overview,
                    job_category, employment_type,
                    description, requirements, responsibilities, benefits,
                    experience_level, salary_min, salary_max, salary_currency,
                    salary_type, is_salary_negotiable,
                    location_type, address, city, state, country,
                    application_email, application_phone, application_instructions,
                    status, is_featured, is_urgent, company_id, total_views,
                    created_at, updated_at, published_at
                ) VALUES (
                    ?, ?, ?,
                    ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?, ?, 0,
                    NOW(), NOW(), ?
                )";
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $title, $slug, $overview,
                    $jobCategory, $employmentType,
                    $description, $_POST['requirements'] ?? null, $_POST['responsibilities'] ?? null, $_POST['benefits'] ?? null,
                    $_POST['experience_level'] ?? 'Mid', $salaryMin, $salaryMax, $_POST['salary_currency'] ?? 'GHS',
                    $_POST['salary_type'] ?? 'Monthly', isset($_POST['is_salary_negotiable']) ? 1 : 0,
                    $_POST['location_type'] ?? 'Onsite', $_POST['address'] ?? null, $_POST['city'] ?? null, $_POST['state'] ?? null, $_POST['country'] ?? 'Ghana',
                    $_POST['application_email'] ?? null, $_POST['application_phone'] ?? null, $_POST['application_instructions'] ?? null,
                    $status, isset($_POST['is_featured']) ? 1 : 0, isset($_POST['is_urgent']) ? 1 : 0,
                    $companyId,
                    $publishedAt
                ]);
                
                $_SESSION['success'] = 'Job created successfully';
            }
            
            $pdo->commit();
            
            // Redirect to jobs list
            header('Location: jobs.php');
            exit();
        }
        
        // Handle Bulk Actions
        elseif (isset($_POST['bulk_action']) && isset($_POST['job_ids']) && is_array($_POST['job_ids'])) {
            $jobIds = array_filter(array_map('intval', $_POST['job_ids']));
            $bulkAction = $_POST['bulk_action'];
            
            if (empty($jobIds)) {
                throw new Exception('No jobs selected');
            }
            
            $pdo->beginTransaction();
            $placeholders = implode(',', array_fill(0, count($jobIds), '?'));
            
            switch ($bulkAction) {
                case 'activate':
                    $pdo->prepare("UPDATE jobs SET status = 'Active', updated_at = NOW(), published_at = COALESCE(published_at, NOW()) WHERE id IN ($placeholders)")->execute($jobIds);
                    $_SESSION['success'] = count($jobIds) . ' job(s) activated successfully';
                    break;
                case 'pause':
                    $pdo->prepare("UPDATE jobs SET status = 'Paused', updated_at = NOW() WHERE id IN ($placeholders)")->execute($jobIds);
                    $_SESSION['success'] = count($jobIds) . ' job(s) paused successfully';
                    break;
                case 'close':
                    $pdo->prepare("UPDATE jobs SET status = 'Closed', updated_at = NOW() WHERE id IN ($placeholders)")->execute($jobIds);
                    $_SESSION['success'] = count($jobIds) . ' job(s) closed successfully';
                    break;
                case 'feature':
                    $pdo->prepare("UPDATE jobs SET is_featured = 1, updated_at = NOW() WHERE id IN ($placeholders)")->execute($jobIds);
                    $_SESSION['success'] = count($jobIds) . ' job(s) marked as featured';
                    break;
                case 'unfeature':
                    $pdo->prepare("UPDATE jobs SET is_featured = 0, updated_at = NOW() WHERE id IN ($placeholders)")->execute($jobIds);
                    $_SESSION['success'] = count($jobIds) . ' job(s) unfeatured';
                    break;
                case 'urgent':
                    $pdo->prepare("UPDATE jobs SET is_urgent = 1, updated_at = NOW() WHERE id IN ($placeholders)")->execute($jobIds);
                    $_SESSION['success'] = count($jobIds) . ' job(s) marked as urgent';
                    break;
                case 'unurgent':
                    $pdo->prepare("UPDATE jobs SET is_urgent = 0, updated_at = NOW() WHERE id IN ($placeholders)")->execute($jobIds);
                    $_SESSION['success'] = count($jobIds) . ' job(s) unmarked as urgent';
                    break;
                case 'delete':
                    $check = $pdo->prepare("SELECT COUNT(*) FROM comments WHERE job_id IN ($placeholders)");
                    $check->execute($jobIds);
                    $commentCount = $check->fetchColumn();
                    
                    $pdo->prepare("UPDATE comments SET is_deleted = 1, deleted_at = NOW() WHERE job_id IN ($placeholders)")->execute($jobIds);
                    $pdo->prepare("DELETE FROM jobs WHERE id IN ($placeholders)")->execute($jobIds);
                    
                    $_SESSION['success'] = count($jobIds) . ' job(s) deleted successfully' . ($commentCount > 0 ? " ({$commentCount} comments archived)" : "");
                    break;
                default:
                    throw new Exception('Invalid bulk action');
            }
            
            $pdo->commit();
        }
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Admin jobs error: " . $e->getMessage());
        $_SESSION['error'] = $e->getMessage();
    }
    
    // Redirect to avoid form resubmission
    $redirectTo = 'jobs.php' . $redirectQuery;
    header('Location: ' . $redirectTo);
    exit();
}

// ==================== FETCH DATA FOR DISPLAY ====================

// Initialize variables
$editMode = false;
$editJob = null;
$editError = $_SESSION['form_error'] ?? '';
unset($_SESSION['form_error']);

// Handle Edit Job - Load job data for editing
if (isset($_GET['edit']) && !empty($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    $editMode = true;
    
    try {
        $editJob = fetchOne("
            SELECT j.*, c.name as company_name 
            FROM jobs j 
            LEFT JOIN companies c ON j.company_id = c.id 
            WHERE j.id = ?
        ", [$editId]);
        if (!$editJob) {
            $_SESSION['error'] = 'Job not found';
            header('Location: jobs.php');
            exit();
        }
    } catch (Exception $e) {
        $_SESSION['error'] = 'Error loading job: ' . $e->getMessage();
        header('Location: jobs.php');
        exit();
    }
}

// Get filter options for dropdowns
try {
    $categories = fetchAll("SELECT DISTINCT job_category FROM jobs WHERE job_category IS NOT NULL AND job_category != '' ORDER BY job_category");
    $locations = fetchAll("SELECT DISTINCT city FROM jobs WHERE city IS NOT NULL AND city != '' ORDER BY city LIMIT 20");
} catch (Exception $e) {
    error_log("Filter options error: " . $e->getMessage());
    $categories = [];
    $locations = [];
}

// Pagination and filtering
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Filter parameters
$statusFilter = $_GET['status'] ?? '';
$categoryFilter = $_GET['category'] ?? '';
$locationFilter = $_GET['location'] ?? '';
$employmentTypeFilter = $_GET['employment_type'] ?? '';
$search = $_GET['search'] ?? '';
$sort = $_GET['sort'] ?? 'newest';
$featuredFilter = isset($_GET['featured']) ? (int)$_GET['featured'] : -1;
$urgentFilter = isset($_GET['urgent']) ? (int)$_GET['urgent'] : -1;

// Build WHERE clause
$whereConditions = ["1=1"];
$params = [];

if ($statusFilter && $statusFilter !== 'all') {
    $whereConditions[] = "j.status = ?";
    $params[] = $statusFilter;
}
if ($categoryFilter && $categoryFilter !== 'all') {
    $whereConditions[] = "j.job_category = ?";
    $params[] = $categoryFilter;
}
if ($locationFilter && $locationFilter !== 'all') {
    $whereConditions[] = "j.city = ?";
    $params[] = $locationFilter;
}
if ($employmentTypeFilter && $employmentTypeFilter !== 'all') {
    $whereConditions[] = "j.employment_type = ?";
    $params[] = $employmentTypeFilter;
}
if ($featuredFilter !== -1) {
    $whereConditions[] = "j.is_featured = ?";
    $params[] = $featuredFilter;
}
if ($urgentFilter !== -1) {
    $whereConditions[] = "j.is_urgent = ?";
    $params[] = $urgentFilter;
}
if ($search) {
    $whereConditions[] = "(j.title LIKE ? OR j.description LIKE ? OR j.overview LIKE ? OR j.city LIKE ? OR c.name LIKE ?)";
    $searchTerm = "%$search%";
    array_push($params, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm);
}

$whereClause = implode(" AND ", $whereConditions);

// Get total count
try {
    $countQuery = "SELECT COUNT(*) as total FROM jobs j LEFT JOIN companies c ON j.company_id = c.id WHERE $whereClause";
    $countResult = fetchOne($countQuery, $params);
    $totalJobs = $countResult['total'] ?? 0;
} catch (Exception $e) {
    error_log("Count query error: " . $e->getMessage());
    $totalJobs = 0;
}
$totalPages = ceil($totalJobs / $perPage);

// Build ORDER BY clause
$orderByOptions = [
    'newest' => "j.created_at DESC",
    'oldest' => "j.created_at ASC",
    'title_asc' => "j.title ASC",
    'title_desc' => "j.title DESC",
    'salary_high' => "COALESCE(j.salary_max, j.salary_min) DESC",
    'salary_low' => "COALESCE(j.salary_min, j.salary_max) ASC",
    'views' => "j.total_views DESC, j.created_at DESC",
    'comments' => "(SELECT COUNT(*) FROM comments WHERE job_id = j.id AND is_deleted = 0) DESC, j.created_at DESC"
];
$orderBy = $orderByOptions[$sort] ?? "j.created_at DESC";

// Fetch jobs
try {
    $jobsQuery = "
        SELECT 
            j.id, j.title, j.slug, j.overview, j.description,
            j.job_category, j.employment_type, j.experience_level,
            j.location_type, j.city, j.state, j.country,
            j.salary_min, j.salary_max, j.salary_currency, j.salary_type,
            j.status, j.is_featured, j.is_urgent, j.total_views,
            j.created_at, j.updated_at, j.published_at, j.company_id,
            c.name as company_name,
            (SELECT COUNT(*) FROM comments WHERE job_id = j.id AND is_deleted = 0) as comment_count
        FROM jobs j
        LEFT JOIN companies c ON j.company_id = c.id
        WHERE $whereClause
        ORDER BY $orderBy
        LIMIT ? OFFSET ?
    ";
    $allParams = array_merge($params, [$perPage, $offset]);
    $jobs = fetchAll($jobsQuery, $allParams);
} catch (Exception $e) {
    error_log("Jobs query error: " . $e->getMessage());
    $jobs = [];
}

// Get statistics
try {
    $statsQuery = "
        SELECT 
            COUNT(*) as total_jobs,
            SUM(CASE WHEN status = 'Active' THEN 1 ELSE 0 END) as active_jobs,
            SUM(CASE WHEN status = 'Draft' THEN 1 ELSE 0 END) as draft_jobs,
            SUM(CASE WHEN status = 'Paused' THEN 1 ELSE 0 END) as paused_jobs,
            SUM(CASE WHEN status = 'Closed' THEN 1 ELSE 0 END) as closed_jobs,
            SUM(CASE WHEN status = 'Expired' THEN 1 ELSE 0 END) as expired_jobs,
            SUM(CASE WHEN is_featured = 1 THEN 1 ELSE 0 END) as featured_jobs,
            SUM(CASE WHEN is_urgent = 1 THEN 1 ELSE 0 END) as urgent_jobs,
            SUM(total_views) as total_views,
            (SELECT COUNT(*) FROM comments WHERE is_deleted = 0) as total_comments,
            (SELECT COUNT(*) FROM companies) as total_companies
        FROM jobs
    ";
    $stats = fetchOne($statsQuery, []) ?: [
        'total_jobs' => 0, 'active_jobs' => 0, 'draft_jobs' => 0,
        'paused_jobs' => 0, 'closed_jobs' => 0, 'expired_jobs' => 0,
        'featured_jobs' => 0, 'urgent_jobs' => 0, 'total_views' => 0,
        'total_comments' => 0, 'total_companies' => 0
    ];
} catch (Exception $e) {
    error_log("Stats query error: " . $e->getMessage());
    $stats = [
        'total_jobs' => 0, 'active_jobs' => 0, 'draft_jobs' => 0,
        'paused_jobs' => 0, 'closed_jobs' => 0, 'expired_jobs' => 0,
        'featured_jobs' => 0, 'urgent_jobs' => 0, 'total_views' => 0,
        'total_comments' => 0, 'total_companies' => 0
    ];
}

// Helper function for building URLs
function buildUrl($params = []) {
    $current = $_GET;
    unset($current['page'], $current['ajax'], $current['_']);
    $merged = array_merge($current, $params);
    return '?' . http_build_query(array_filter($merged, function($v) { return $v !== '' && $v !== -1 && $v !== 'all'; }));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Jobs Management - Admin Dashboard | TransNow Ghana</title>
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <style>
        * { font-family: 'Inter', sans-serif; }
        
        /* Custom Scrollbar */
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 10px; }
        ::-webkit-scrollbar-thumb { background: #cbd5e0; border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        .dark ::-webkit-scrollbar-track { background: #1f2937; }
        .dark ::-webkit-scrollbar-thumb { background: #4b5563; }
        .dark ::-webkit-scrollbar-thumb:hover { background: #6b7280; }
        
        /* Table Container */
        .table-container {
            position: relative;
            overflow-x: auto;
            overflow-y: visible;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: thin;
        }
        
        .table-container table {
            min-width: 1200px;
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }
        
        .table-container thead th {
            position: sticky;
            top: 0;
            background: #f9fafb;
            z-index: 10;
            white-space: nowrap;
        }
        .dark .table-container thead th {
            background: #374151;
        }
        
        .action-buttons {
            display: flex;
            gap: 0.25rem;
            flex-wrap: nowrap;
            white-space: nowrap;
        }
        
        .action-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            border-radius: 8px;
            transition: all 0.2s ease;
            flex-shrink: 0;
        }
        
        .action-btn:hover {
            transform: scale(1.1);
        }
        
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        .animate-slideIn { animation: slideIn 0.3s ease-out; }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        .animate-fadeIn { animation: fadeIn 0.2s ease-out; }
        
        .modal-backdrop {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            backdrop-filter: blur(4px);
            z-index: 1000;
        }
        
        .modal-content {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            z-index: 1001;
            max-height: 90vh;
            overflow-y: auto;
            animation: slideIn 0.3s ease-out;
        }
        
        .hover-card {
            transition: all 0.3s ease;
        }
        .hover-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.02);
        }
        
        .spinner {
            border: 3px solid rgba(37, 99, 235, 0.1);
            border-radius: 50%;
            border-top: 3px solid #2563eb;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .table-row-hover:hover {
            background-color: rgba(37, 99, 235, 0.03);
        }
        .dark .table-row-hover:hover {
            background-color: rgba(37, 99, 235, 0.08);
        }
        
        .form-input {
            width: 100%;
            padding: 0.625rem 1rem;
            border: 1px solid #d1d5db;
            border-radius: 0.5rem;
            background-color: #f9fafb;
            transition: all 0.2s;
        }
        .form-input:focus {
            outline: none;
            ring: 2px solid #2563eb;
            border-color: transparent;
        }
        .dark .form-input {
            background-color: #374151;
            border-color: #4b5563;
            color: white;
        }
        
        .form-label {
            display: block;
            font-size: 0.875rem;
            font-weight: 500;
            margin-bottom: 0.5rem;
            color: #374151;
        }
        .dark .form-label {
            color: #d1d5db;
        }
        
        .brand-gradient {
            background: linear-gradient(135deg, #1E3A8A 0%, #2563EB 50%, #3B82F6 100%);
        }
        
        .job-title-link {
            color: inherit;
            text-decoration: none;
            transition: color 0.2s;
        }
        .job-title-link:hover {
            color: #2563eb;
        }
        
        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            white-space: nowrap;
        }
        
        /* Company search dropdown */
        .company-search-results {
            max-height: 200px;
            overflow-y: auto;
            border: 1px solid #e5e7eb;
            border-radius: 0.5rem;
            margin-top: 0.25rem;
            background: white;
        }
        .dark .company-search-results {
            background: #374151;
            border-color: #4b5563;
        }
        .company-result-item {
            padding: 0.75rem 1rem;
            cursor: pointer;
            border-bottom: 1px solid #e5e7eb;
            transition: background-color 0.15s;
        }
        .company-result-item:last-child {
            border-bottom: none;
        }
        .company-result-item:hover {
            background-color: #f3f4f6;
        }
        .dark .company-result-item {
            border-bottom-color: #4b5563;
        }
        .dark .company-result-item:hover {
            background-color: #4b5563;
        }
    </style>
</head>
<body class="bg-gray-50 dark:bg-gray-900 text-gray-900 dark:text-white">

<!-- Flash Messages -->
<?php if (isset($_SESSION['success'])): ?>
<div id="flashSuccess" class="fixed top-4 right-4 z-50 bg-green-500 text-white px-6 py-3 rounded-lg shadow-lg animate-slideIn flex items-center">
    <i class="fas fa-check-circle mr-2"></i>
    <span><?php echo htmlspecialchars($_SESSION['success']); ?></span>
    <button onclick="this.parentElement.remove()" class="ml-4 hover:text-gray-200">
        <i class="fas fa-times"></i>
    </button>
</div>
<?php unset($_SESSION['success']); endif; ?>

<?php if (isset($_SESSION['error'])): ?>
<div id="flashError" class="fixed top-4 right-4 z-50 bg-red-500 text-white px-6 py-3 rounded-lg shadow-lg animate-slideIn flex items-center">
    <i class="fas fa-exclamation-circle mr-2"></i>
    <span><?php echo htmlspecialchars($_SESSION['error']); ?></span>
    <button onclick="this.parentElement.remove()" class="ml-4 hover:text-gray-200">
        <i class="fas fa-times"></i>
    </button>
</div>
<?php unset($_SESSION['error']); endif; ?>

<!-- Dark Mode Toggle -->
<button id="darkModeToggle" class="fixed bottom-4 right-4 z-40 w-12 h-12 bg-white dark:bg-gray-800 text-gray-700 dark:text-white rounded-full shadow-lg border border-gray-200 dark:border-gray-700 hover:scale-110 transition-all duration-300">
    <i class="fas fa-moon" id="darkModeIcon"></i>
</button>

<!-- Job Details Modal -->
<div id="jobModal" class="hidden">
    <div class="modal-backdrop" onclick="closeJobModal()"></div>
    <div class="modal-content w-11/12 max-w-4xl bg-white dark:bg-gray-800 rounded-2xl shadow-2xl">
        <div id="modalContent" class="p-6">
            <div class="flex flex-col justify-center items-center h-64">
                <div class="spinner mb-4"></div>
                <p class="text-gray-500">Loading job details...</p>
            </div>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="deleteModal" class="hidden">
    <div class="modal-backdrop" onclick="closeDeleteModal()"></div>
    <div class="modal-content w-11/12 max-w-md bg-white dark:bg-gray-800 rounded-2xl shadow-2xl p-6">
        <div class="text-center">
            <div class="w-16 h-16 bg-red-100 dark:bg-red-900/20 rounded-full flex items-center justify-center mx-auto mb-4">
                <i class="fas fa-exclamation-triangle text-red-600 text-2xl"></i>
            </div>
            <h3 class="text-xl font-bold mb-2">Delete Job</h3>
            <p class="text-gray-600 dark:text-gray-400 mb-6" id="deleteMessage">Are you sure you want to delete this job?</p>
            <form id="deleteForm" method="POST" action="">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="job_id" id="deleteJobId" value="">
                <div class="flex space-x-3 justify-center">
                    <button type="submit" class="px-6 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition">Delete</button>
                    <button type="button" onclick="closeDeleteModal()" class="px-6 py-2 bg-gray-200 dark:bg-gray-700 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Status Update Modal -->
<div id="statusModal" class="hidden">
    <div class="modal-backdrop" onclick="closeStatusModal()"></div>
    <div class="modal-content w-11/12 max-w-md bg-white dark:bg-gray-800 rounded-2xl shadow-2xl p-6">
        <div class="text-center">
            <div class="w-16 h-16 bg-blue-100 dark:bg-blue-900/20 rounded-full flex items-center justify-center mx-auto mb-4">
                <i class="fas fa-edit text-blue-600 text-2xl"></i>
            </div>
            <h3 class="text-xl font-bold mb-4">Update Job Status</h3>
            <form id="statusForm" method="POST" action="">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="job_id" id="statusJobId" value="">
                <div class="mb-4">
                    <select name="status" id="statusSelect" class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-gray-50 dark:bg-gray-700 focus:ring-2 focus:ring-blue-500">
                        <option value="Draft">Draft</option>
                        <option value="Active">Active</option>
                        <option value="Paused">Paused</option>
                        <option value="Closed">Closed</option>
                        <option value="Expired">Expired</option>
                    </select>
                </div>
                <div class="flex space-x-3 justify-center">
                    <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">Update</button>
                    <button type="button" onclick="closeStatusModal()" class="px-6 py-2 bg-gray-200 dark:bg-gray-700 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Main Layout -->
<div class="flex min-h-screen">
    <!-- Sidebar -->
    <aside class="hidden lg:flex lg:flex-shrink-0 w-72 bg-white dark:bg-gray-800 border-r border-gray-200 dark:border-gray-700 flex-col">
        <div class="p-6">
            <div class="flex items-center space-x-3">
                <div class="w-10 h-10 brand-gradient rounded-lg flex items-center justify-center">
                    <i class="fas fa-briefcase text-white"></i>
                </div>
                <span class="text-xl font-bold bg-gradient-to-r from-blue-600 to-indigo-600 bg-clip-text text-transparent">TransNow Ghana</span>
                <span class="text-xs bg-red-500 text-white px-2 py-1 rounded-full">Admin</span>
            </div>
            
            <div class="mt-6 p-4 bg-gray-50 dark:bg-gray-700 rounded-xl hover-card">
                <div class="flex items-center space-x-3">
                    <img src="https://ui-avatars.com/api/?name=Admin&background=2563eb&color=fff&size=64" 
                         alt="Admin" class="w-12 h-12 rounded-lg border-2 border-blue-200">
                    <div>
                        <p class="font-semibold">Administrator</p>
                        <p class="text-sm text-gray-500 dark:text-gray-400 truncate"><?php echo htmlspecialchars($adminData['email'] ?? ''); ?></p>
                    </div>
                </div>
            </div>
        </div>
        
        <nav class="flex-1 px-4 space-y-1">
            <a href="dashboard.php" class="flex items-center space-x-3 px-4 py-3 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                <i class="fas fa-tachometer-alt w-5"></i><span>Dashboard</span>
            </a>
            
            <a href="jobs.php" class="flex items-center space-x-3 px-4 py-3 bg-blue-50 dark:bg-blue-900/20 text-blue-600 dark:text-blue-400 rounded-xl font-medium">
                <i class="fas fa-briefcase w-5"></i><span>Jobs</span>
                <span class="ml-auto bg-blue-500 text-white text-xs px-2 py-1 rounded-full"><?php echo $stats['total_jobs']; ?></span>
            </a>
            <a href="companies.php" class="flex items-center space-x-3 px-4 py-3 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                <i class="fas fa-building w-5"></i><span>Companies</span>
                <span class="ml-auto bg-gray-500 text-white text-xs px-2 py-1 rounded-full"><?php echo $stats['total_companies']; ?></span>
            </a>
            <a href="applications.php" class="flex items-center space-x-3 px-4 py-3 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                 <i class="fas fa-file-alt w-5"></i><span>Applications</span>
            </a>
            <a href="reports.php" class="flex items-center space-x-3 px-4 py-3 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                 <i class="fas fa-chart-bar w-5"></i><span>Reports</span>
            </a>
            <a href="settings.php" class="flex items-center space-x-3 px-4 py-3 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                <i class="fas fa-cog w-5"></i><span>Settings</span>
            </a>
            
            <div class="border-t border-gray-200 dark:border-gray-700 my-4"></div>
            
            <a href="/" target="_blank" class="flex items-center space-x-3 px-4 py-3 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                <i class="fas fa-external-link-alt w-5"></i><span>View Site</span>
            </a>
            <a href="/logout.php" class="flex items-center space-x-3 px-4 py-3 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-xl transition">
                <i class="fas fa-sign-out-alt w-5"></i><span>Logout</span>
            </a>
        </nav>
    </aside>

    <!-- Main Content -->
    <main class="flex-1 min-w-0 overflow-x-auto">
        <!-- Top Bar -->
        <header class="sticky top-0 z-30 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700">
            <div class="px-6 py-4 flex items-center justify-between">
                <button id="mobileMenuBtn" class="lg:hidden w-10 h-10 bg-gray-100 dark:bg-gray-700 rounded-lg flex items-center justify-center hover:bg-gray-200 dark:hover:bg-gray-600 transition">
                    <i class="fas fa-bars"></i>
                </button>
                
                <h1 class="text-2xl font-bold">
                    <?php if ($editMode): ?>
                        <span class="text-blue-600">Edit Job</span>
                    <?php elseif (isset($_GET['new'])): ?>
                        <span class="text-blue-600">Post New Job</span>
                    <?php else: ?>
                        Jobs <span class="text-blue-600">Management</span>
                    <?php endif; ?>
                </h1>
                
                <div class="flex items-center space-x-4">
                    <?php if (!$editMode && !isset($_GET['new'])): ?>
                    <a href="?new=1" class="px-4 py-2 brand-gradient text-white rounded-lg hover:opacity-90 transition flex items-center space-x-2 hover:scale-105 transform duration-200">
                        <i class="fas fa-plus-circle"></i>
                        <span class="hidden sm:inline">Post New Job</span>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </header>

        <!-- Content -->
        <div class="p-6">
            <?php if ($editMode || isset($_GET['new'])): ?>
                <!-- Edit/Create Job Form -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 hover-card max-w-5xl mx-auto">
                    <?php if ($editError): ?>
                    <div class="mb-6 p-4 bg-red-100 dark:bg-red-900/20 text-red-600 rounded-lg">
                        <i class="fas fa-exclamation-circle mr-2"></i><?php echo $editError; ?>
                    </div>
                    <?php endif; ?>
                    
                    <form method="POST" action="jobs.php" class="space-y-6">
                        <input type="hidden" name="save_job" value="1">
                        <?php if ($editMode && $editJob): ?>
                        <input type="hidden" name="job_id" value="<?php echo $editJob['id']; ?>">
                        <?php endif; ?>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <!-- Company Information Section -->
                            <div class="md:col-span-2">
                                <h3 class="text-lg font-semibold mb-4 flex items-center">
                                    <i class="fas fa-building text-blue-500 mr-2"></i>
                                    Company Information
                                </h3>
                            </div>
                            
                            <div class="md:col-span-2">
                                <label class="form-label">Company</label>
                                <div class="flex items-center space-x-4 mb-3">
                                    <label class="flex items-center">
                                        <input type="radio" name="company_action" value="existing" checked class="mr-2 company-action-radio">
                                        <span>Select Existing Company</span>
                                    </label>
                                    <label class="flex items-center">
                                        <input type="radio" name="company_action" value="new" class="mr-2 company-action-radio">
                                        <span>Create New Company</span>
                                    </label>
                                </div>
                                
                                <!-- Existing Company Selection -->
                                <div id="existing-company-section">
                                    <div class="relative">
                                        <input type="text" 
                                               id="company-search" 
                                               placeholder="Search for a company by name or email..." 
                                               class="form-input"
                                               autocomplete="off"
                                               value="<?php echo htmlspecialchars($editJob['company_name'] ?? ''); ?>">
                                        <input type="hidden" name="company_id" id="selected-company-id" value="<?php echo $editJob['company_id'] ?? ''; ?>">
                                        <div id="company-search-results" class="company-search-results hidden"></div>
                                    </div>
                                    <p class="text-xs text-gray-500 mt-1">Start typing to search existing companies</p>
                                </div>
                                
                                <!-- New Company Form -->
                                <div id="new-company-section" class="hidden space-y-4 p-4 bg-gray-50 dark:bg-gray-700 rounded-lg">
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div>
                                            <label class="form-label">Company Name <span class="text-red-500">*</span></label>
                                            <input type="text" name="new_company_name" class="form-input" placeholder="e.g., Zonda Tec Ghana Ltd">
                                        </div>
                                        <div>
                                            <label class="form-label">Company Email</label>
                                            <input type="email" name="new_company_email" class="form-input" placeholder="hr@company.com">
                                        </div>
                                        <div>
                                            <label class="form-label">Company Phone</label>
                                            <input type="text" name="new_company_phone" class="form-input" placeholder="+233 XX XXX XXXX">
                                        </div>
                                        <div>
                                            <label class="form-label">Company Website</label>
                                            <input type="text" name="new_company_website" class="form-input" placeholder="https://www.company.com">
                                        </div>
                                        <div class="md:col-span-2">
                                            <label class="form-label">Company Address</label>
                                            <input type="text" name="new_company_address" class="form-input" placeholder="Full address">
                                        </div>
                                        <div class="md:col-span-2">
                                            <label class="form-label">Company Description</label>
                                            <textarea name="new_company_description" rows="2" class="form-input" placeholder="Brief description of the company..."></textarea>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Basic Information -->
                            <div class="md:col-span-2">
                                <h3 class="text-lg font-semibold mb-4 mt-4 flex items-center">
                                    <i class="fas fa-info-circle text-blue-500 mr-2"></i>
                                    Basic Information
                                </h3>
                            </div>
                            
                            <div>
                                <label class="form-label">Job Title <span class="text-red-500">*</span></label>
                                <input type="text" name="title" required class="form-input" 
                                       value="<?php echo htmlspecialchars($editJob['title'] ?? ''); ?>"
                                       placeholder="e.g., Chinese-English Translator">
                            </div>
                            
                            <div>
                                <label class="form-label">Job Category <span class="text-red-500">*</span></label>
                                <select name="job_category" required class="form-input">
                                    <option value="">Select Category</option>
                                    <?php
                                    $categoryOptions = ['Translation', 'Sales', 'Education', 'Administration', 'Cooking', 'Driving', 'Maintenance', 'Construction', 'Warehouse', 'Security', 'Office Clerk', 'Secretary', 'Customer Service', 'IT & Technology', 'Healthcare', 'Other'];
                                    foreach ($categoryOptions as $cat):
                                    ?>
                                    <option value="<?php echo $cat; ?>" <?php echo (isset($editJob['job_category']) && $editJob['job_category'] == $cat) ? 'selected' : ''; ?>><?php echo $cat; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div>
                                <label class="form-label">Employment Type <span class="text-red-500">*</span></label>
                                <select name="employment_type" required class="form-input">
                                    <?php
                                    $typeOptions = ['Full-time', 'Part-time', 'Contract', 'Freelance', 'Internship'];
                                    foreach ($typeOptions as $type):
                                    ?>
                                    <option value="<?php echo $type; ?>" <?php echo (isset($editJob['employment_type']) && $editJob['employment_type'] == $type) ? 'selected' : ''; ?>><?php echo $type; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div>
                                <label class="form-label">Experience Level</label>
                                <select name="experience_level" class="form-input">
                                    <?php
                                    $expOptions = ['Entry', 'Mid', 'Senior', 'Executive'];
                                    foreach ($expOptions as $exp):
                                    ?>
                                    <option value="<?php echo $exp; ?>" <?php echo (isset($editJob['experience_level']) && $editJob['experience_level'] == $exp) ? 'selected' : ''; ?>><?php echo $exp; ?> Level</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="md:col-span-2">
                                <label class="form-label">Overview / Short Summary</label>
                                <textarea name="overview" rows="3" class="form-input" 
                                          placeholder="Brief overview of the job..."><?php echo htmlspecialchars($editJob['overview'] ?? ''); ?></textarea>
                                <p class="text-xs text-gray-500 mt-1">This appears at the top of the job listing and in search results.</p>
                            </div>
                            
                            <!-- Salary Information -->
                            <div class="md:col-span-2">
                                <h3 class="text-lg font-semibold mb-4 mt-4 flex items-center">
                                    <i class="fas fa-money-bill-wave text-blue-500 mr-2"></i>
                                    Salary Information
                                </h3>
                            </div>
                            
                            <div>
                                <label class="form-label">Minimum Salary</label>
                                <input type="number" name="salary_min" step="0.01" class="form-input" 
                                       value="<?php echo $editJob['salary_min'] ?? ''; ?>"
                                       placeholder="e.g., 5000">
                            </div>
                            
                            <div>
                                <label class="form-label">Maximum Salary</label>
                                <input type="number" name="salary_max" step="0.01" class="form-input" 
                                       value="<?php echo $editJob['salary_max'] ?? ''; ?>"
                                       placeholder="e.g., 10000">
                            </div>
                            
                            <div>
                                <label class="form-label">Currency</label>
                                <select name="salary_currency" class="form-input">
                                    <option value="GHS" <?php echo (isset($editJob['salary_currency']) && $editJob['salary_currency'] == 'GHS') ? 'selected' : ''; ?>>GHS (₵)</option>
                                    <option value="USD" <?php echo (isset($editJob['salary_currency']) && $editJob['salary_currency'] == 'USD') ? 'selected' : ''; ?>>USD ($)</option>
                                </select>
                            </div>
                            
                            <div>
                                <label class="form-label">Salary Type</label>
                                <select name="salary_type" class="form-input">
                                    <option value="Hourly" <?php echo (isset($editJob['salary_type']) && $editJob['salary_type'] == 'Hourly') ? 'selected' : ''; ?>>Hourly</option>
                                    <option value="Monthly" <?php echo (isset($editJob['salary_type']) && $editJob['salary_type'] == 'Monthly') ? 'selected' : ''; ?>>Monthly</option>
                                    <option value="Yearly" <?php echo (isset($editJob['salary_type']) && $editJob['salary_type'] == 'Yearly') ? 'selected' : ''; ?>>Yearly</option>
                                </select>
                            </div>
                            
                            <div class="flex items-center">
                                <label class="flex items-center">
                                    <input type="checkbox" name="is_salary_negotiable" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500" 
                                           <?php echo (isset($editJob['is_salary_negotiable']) && $editJob['is_salary_negotiable']) ? 'checked' : ''; ?>>
                                    <span class="ml-2 text-sm">Salary Negotiable</span>
                                </label>
                            </div>
                            
                            <!-- Location Information -->
                            <div class="md:col-span-2">
                                <h3 class="text-lg font-semibold mb-4 mt-4 flex items-center">
                                    <i class="fas fa-map-marker-alt text-blue-500 mr-2"></i>
                                    Location Information
                                </h3>
                            </div>
                            
                            <div>
                                <label class="form-label">Location Type</label>
                                <select name="location_type" class="form-input">
                                    <option value="Onsite" <?php echo (isset($editJob['location_type']) && $editJob['location_type'] == 'Onsite') ? 'selected' : ''; ?>>Onsite</option>
                                    <option value="Remote" <?php echo (isset($editJob['location_type']) && $editJob['location_type'] == 'Remote') ? 'selected' : ''; ?>>Remote</option>
                                    <option value="Hybrid" <?php echo (isset($editJob['location_type']) && $editJob['location_type'] == 'Hybrid') ? 'selected' : ''; ?>>Hybrid</option>
                                </select>
                            </div>
                            
                            <div>
                                <label class="form-label">City</label>
                                <input type="text" name="city" class="form-input" 
                                       value="<?php echo htmlspecialchars($editJob['city'] ?? ''); ?>"
                                       placeholder="e.g., Accra">
                            </div>
                            
                            <div>
                                <label class="form-label">State/Region</label>
                                <input type="text" name="state" class="form-input" 
                                       value="<?php echo htmlspecialchars($editJob['state'] ?? ''); ?>"
                                       placeholder="e.g., Greater Accra">
                            </div>
                            
                            <div>
                                <label class="form-label">Country</label>
                                <input type="text" name="country" class="form-input" 
                                       value="<?php echo htmlspecialchars($editJob['country'] ?? 'Ghana'); ?>"
                                       placeholder="Country">
                            </div>
                            
                            <div class="md:col-span-2">
                                <label class="form-label">Address</label>
                                <input type="text" name="address" class="form-input" 
                                       value="<?php echo htmlspecialchars($editJob['address'] ?? ''); ?>"
                                       placeholder="Full address">
                            </div>
                            
                            <!-- Job Details -->
                            <div class="md:col-span-2">
                                <h3 class="text-lg font-semibold mb-4 mt-4 flex items-center">
                                    <i class="fas fa-align-left text-blue-500 mr-2"></i>
                                    Job Details
                                </h3>
                            </div>
                            
                            <div class="md:col-span-2">
                                <label class="form-label">Description <span class="text-red-500">*</span></label>
                                <textarea name="description" required rows="6" class="form-input" 
                                          placeholder="Detailed job description..."><?php echo htmlspecialchars($editJob['description'] ?? ''); ?></textarea>
                            </div>
                            
                            <div class="md:col-span-2">
                                <label class="form-label">Requirements</label>
                                <textarea name="requirements" rows="4" class="form-input" 
                                          placeholder="Job requirements..."><?php echo htmlspecialchars($editJob['requirements'] ?? ''); ?></textarea>
                            </div>
                            
                            <div class="md:col-span-2">
                                <label class="form-label">Responsibilities</label>
                                <textarea name="responsibilities" rows="4" class="form-input" 
                                          placeholder="Key responsibilities..."><?php echo htmlspecialchars($editJob['responsibilities'] ?? ''); ?></textarea>
                            </div>
                            
                            <div class="md:col-span-2">
                                <label class="form-label">Benefits</label>
                                <textarea name="benefits" rows="4" class="form-input" 
                                          placeholder="Benefits offered..."><?php echo htmlspecialchars($editJob['benefits'] ?? ''); ?></textarea>
                            </div>
                            
                            <!-- Application Information -->
                            <div class="md:col-span-2">
                                <h3 class="text-lg font-semibold mb-4 mt-4 flex items-center">
                                    <i class="fas fa-paper-plane text-blue-500 mr-2"></i>
                                    Application Information
                                </h3>
                            </div>
                            
                            <div>
                                <label class="form-label">Application Email</label>
                                <input type="email" name="application_email" class="form-input" 
                                       value="<?php echo htmlspecialchars($editJob['application_email'] ?? ''); ?>"
                                       placeholder="hr@company.com">
                            </div>
                            
                            <div>
                                <label class="form-label">Application Phone</label>
                                <input type="text" name="application_phone" class="form-input" 
                                       value="<?php echo htmlspecialchars($editJob['application_phone'] ?? ''); ?>"
                                       placeholder="+233 XX XXX XXXX">
                            </div>
                            
                            <div class="md:col-span-2">
                                <label class="form-label">Application Instructions</label>
                                <textarea name="application_instructions" rows="2" class="form-input" 
                                          placeholder="How to apply..."><?php echo htmlspecialchars($editJob['application_instructions'] ?? ''); ?></textarea>
                            </div>
                            
                            <!-- Additional Settings -->
                            <div class="md:col-span-2">
                                <h3 class="text-lg font-semibold mb-4 mt-4 flex items-center">
                                    <i class="fas fa-cog text-blue-500 mr-2"></i>
                                    Job Settings
                                </h3>
                            </div>
                            
                            <div>
                                <label class="form-label">Status</label>
                                <select name="status" class="form-input">
                                    <?php
                                    $statusOptions = ['Draft', 'Active', 'Paused', 'Closed', 'Expired'];
                                    foreach ($statusOptions as $stat):
                                    ?>
                                    <option value="<?php echo $stat; ?>" <?php echo (isset($editJob['status']) && $editJob['status'] == $stat) ? 'selected' : ''; ?>><?php echo $stat; ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="text-xs text-gray-500 mt-1">Only "Active" jobs appear on the public site.</p>
                            </div>
                            
                            <div class="flex items-center space-x-6">
                                <label class="flex items-center">
                                    <input type="checkbox" name="is_featured" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500" 
                                           <?php echo (isset($editJob['is_featured']) && $editJob['is_featured']) ? 'checked' : ''; ?>>
                                    <span class="ml-2 text-sm">Featured Job</span>
                                </label>
                                
                                <label class="flex items-center">
                                    <input type="checkbox" name="is_urgent" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500" 
                                           <?php echo (isset($editJob['is_urgent']) && $editJob['is_urgent']) ? 'checked' : ''; ?>>
                                    <span class="ml-2 text-sm">Urgent Hiring</span>
                                </label>
                            </div>
                        </div>
                        
                        <div class="flex justify-end space-x-3 pt-6 border-t border-gray-200 dark:border-gray-700">
                            <a href="jobs.php" class="px-6 py-2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition">
                                Cancel
                            </a>
                            <button type="submit" class="px-6 py-2 brand-gradient text-white rounded-lg hover:opacity-90 transition hover:scale-105 transform duration-200">
                                <i class="fas fa-save mr-2"></i>
                                <?php echo $editMode ? 'Update Job' : 'Post Job'; ?>
                            </button>
                        </div>
                    </form>
                </div>
            <?php else: ?>
                <!-- Stats Cards -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-4 hover-card">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm text-gray-500">Total Jobs</p>
                                <p class="text-2xl font-bold"><?php echo number_format($stats['total_jobs']); ?></p>
                            </div>
                            <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900/20 rounded-lg flex items-center justify-center">
                                <i class="fas fa-briefcase text-blue-600 text-xl"></i>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-4 hover-card">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm text-gray-500">Active Jobs</p>
                                <p class="text-2xl font-bold text-green-600"><?php echo number_format($stats['active_jobs']); ?></p>
                            </div>
                            <div class="w-12 h-12 bg-green-100 dark:bg-green-900/20 rounded-lg flex items-center justify-center">
                                <i class="fas fa-check-circle text-green-600 text-xl"></i>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-4 hover-card">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm text-gray-500">Total Views</p>
                                <p class="text-2xl font-bold text-purple-600"><?php echo number_format($stats['total_views']); ?></p>
                            </div>
                            <div class="w-12 h-12 bg-purple-100 dark:bg-purple-900/20 rounded-lg flex items-center justify-center">
                                <i class="fas fa-eye text-purple-600 text-xl"></i>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-4 hover-card">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm text-gray-500">Companies</p>
                                <p class="text-2xl font-bold text-indigo-600"><?php echo number_format($stats['total_companies']); ?></p>
                            </div>
                            <div class="w-12 h-12 bg-indigo-100 dark:bg-indigo-900/20 rounded-lg flex items-center justify-center">
                                <i class="fas fa-building text-indigo-600 text-xl"></i>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Quick Stats -->
                <div class="grid grid-cols-2 sm:grid-cols-5 gap-3 mb-6">
                    <div class="bg-white dark:bg-gray-800 rounded-lg p-3 text-center">
                        <p class="text-xs text-gray-500">Featured</p>
                        <p class="text-lg font-bold text-yellow-600"><?php echo $stats['featured_jobs']; ?></p>
                    </div>
                    <div class="bg-white dark:bg-gray-800 rounded-lg p-3 text-center">
                        <p class="text-xs text-gray-500">Urgent</p>
                        <p class="text-lg font-bold text-red-600"><?php echo $stats['urgent_jobs']; ?></p>
                    </div>
                    <div class="bg-white dark:bg-gray-800 rounded-lg p-3 text-center">
                        <p class="text-xs text-gray-500">Draft</p>
                        <p class="text-lg font-bold text-gray-600"><?php echo $stats['draft_jobs']; ?></p>
                    </div>
                    <div class="bg-white dark:bg-gray-800 rounded-lg p-3 text-center">
                        <p class="text-xs text-gray-500">Closed/Expired</p>
                        <p class="text-lg font-bold text-gray-600"><?php echo $stats['closed_jobs'] + $stats['expired_jobs']; ?></p>
                    </div>
                    <div class="bg-white dark:bg-gray-800 rounded-lg p-3 text-center">
                        <p class="text-xs text-gray-500">Comments</p>
                        <p class="text-lg font-bold text-orange-600"><?php echo $stats['total_comments']; ?></p>
                    </div>
                </div>

                <!-- Filters -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-4 mb-6">
                    <form method="GET" action="jobs.php" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                        <div>
                            <label class="block text-xs font-medium mb-1">Search</label>
                            <div class="relative">
                                <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 text-sm"></i>
                                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                                       placeholder="Title, description, company..." 
                                       class="w-full pl-9 pr-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg bg-gray-50 dark:bg-gray-700 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                            </div>
                        </div>
                        
                        <div>
                            <label class="block text-xs font-medium mb-1">Status</label>
                            <select name="status" class="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg bg-gray-50 dark:bg-gray-700">
                                <option value="all">All Status</option>
                                <option value="Active" <?php echo $statusFilter === 'Active' ? 'selected' : ''; ?>>Active</option>
                                <option value="Draft" <?php echo $statusFilter === 'Draft' ? 'selected' : ''; ?>>Draft</option>
                                <option value="Paused" <?php echo $statusFilter === 'Paused' ? 'selected' : ''; ?>>Paused</option>
                                <option value="Closed" <?php echo $statusFilter === 'Closed' ? 'selected' : ''; ?>>Closed</option>
                                <option value="Expired" <?php echo $statusFilter === 'Expired' ? 'selected' : ''; ?>>Expired</option>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-xs font-medium mb-1">Category</label>
                            <select name="category" class="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg bg-gray-50 dark:bg-gray-700">
                                <option value="all">All Categories</option>
                                <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo htmlspecialchars($cat['job_category']); ?>" <?php echo $categoryFilter === $cat['job_category'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cat['job_category']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-xs font-medium mb-1">Sort By</label>
                            <select name="sort" class="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg bg-gray-50 dark:bg-gray-700">
                                <option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>Newest</option>
                                <option value="oldest" <?php echo $sort === 'oldest' ? 'selected' : ''; ?>>Oldest</option>
                                <option value="salary_high" <?php echo $sort === 'salary_high' ? 'selected' : ''; ?>>Salary ↓</option>
                                <option value="salary_low" <?php echo $sort === 'salary_low' ? 'selected' : ''; ?>>Salary ↑</option>
                                <option value="views" <?php echo $sort === 'views' ? 'selected' : ''; ?>>Most Viewed</option>
                            </select>
                        </div>
                        
                        <div class="sm:col-span-2 lg:col-span-4 flex justify-between items-center">
                            <div class="flex space-x-2">
                                <button type="submit" class="px-4 py-2 brand-gradient text-white rounded-lg hover:opacity-90 transition text-sm flex items-center">
                                    <i class="fas fa-search mr-2"></i>Apply
                                </button>
                                <a href="jobs.php" class="px-4 py-2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition text-sm flex items-center">
                                    <i class="fas fa-times mr-2"></i>Clear
                                </a>
                            </div>
                            <p class="text-sm text-gray-500">Found <span class="font-semibold"><?php echo number_format($totalJobs); ?></span> jobs</p>
                        </div>
                    </form>
                </div>

                <!-- Bulk Actions Bar -->
                <div id="bulkActionsBar" class="bg-blue-600 text-white rounded-lg p-3 mb-4 hidden items-center justify-between animate-fadeIn">
                    <div class="flex items-center space-x-4">
                        <span class="font-medium"><span id="selectedCount">0</span> selected</span>
                        <button onclick="deselectAll()" class="text-white/80 hover:text-white transition text-sm">
                            <i class="fas fa-times mr-1"></i>Deselect
                        </button>
                    </div>
                    <div class="flex items-center space-x-2">
                        <select id="bulkAction" class="px-3 py-1.5 bg-white text-gray-900 rounded-lg border-0 text-sm">
                            <option value="">Bulk Action</option>
                            <option value="activate">Activate</option>
                            <option value="pause">Pause</option>
                            <option value="close">Close</option>
                            <option value="feature">Mark Featured</option>
                            <option value="unfeature">Remove Featured</option>
                            <option value="urgent">Mark Urgent</option>
                            <option value="unurgent">Remove Urgent</option>
                            <option value="delete">Delete</option>
                        </select>
                        <button onclick="confirmBulkAction()" class="px-3 py-1.5 bg-white text-blue-600 rounded-lg hover:bg-gray-100 transition text-sm font-medium">
                            Apply
                        </button>
                    </div>
                </div>

                <!-- Jobs Table -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg overflow-hidden">
                    <div class="table-container">
                        <form id="bulkForm" method="POST" action="jobs.php<?php echo buildRedirectQuery(); ?>">
                            <table class="w-full">
                                <thead>
                                    <tr class="border-b border-gray-200 dark:border-gray-700">
                                        <th class="px-4 py-3 w-10">
                                            <input type="checkbox" id="selectAll" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                        </th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Job</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Company</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Category</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Location</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Salary</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Stats</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Posted</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                    <?php if (empty($jobs)): ?>
                                    <tr>
                                        <td colspan="10" class="px-4 py-12 text-center text-gray-500">
                                            <i class="fas fa-briefcase text-5xl mb-4 text-gray-400"></i>
                                            <p class="text-lg mb-2">No jobs found</p>
                                            <p class="text-sm">Try adjusting your filters or <a href="?new=1" class="text-blue-600 hover:underline">post a new job</a></p>
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                        <?php foreach ($jobs as $job): ?>
                                        <tr class="table-row-hover">
                                            <td class="px-4 py-3">
                                                <input type="checkbox" name="job_ids[]" value="<?php echo $job['id']; ?>" class="job-checkbox rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                            </td>
                                            <td class="px-4 py-3">
                                                <div>
                                                    <a href="javascript:void(0)" onclick="viewJobDetails(<?php echo $job['id']; ?>)" class="job-title-link font-medium hover:text-blue-600">
                                                        <?php echo htmlspecialchars($job['title']); ?>
                                                    </a>
                                                    <div class="flex items-center gap-1 mt-1">
                                                        <?php if ($job['is_featured']): ?>
                                                        <span class="bg-yellow-100 text-yellow-800 text-xs px-1.5 py-0.5 rounded" title="Featured">⭐</span>
                                                        <?php endif; ?>
                                                        <?php if ($job['is_urgent']): ?>
                                                        <span class="bg-red-100 text-red-800 text-xs px-1.5 py-0.5 rounded" title="Urgent">🔥</span>
                                                        <?php endif; ?>
                                                        <span class="text-xs text-gray-500"><?php echo htmlspecialchars($job['employment_type']); ?></span>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-4 py-3">
                                                <span class="text-sm <?php echo $job['company_name'] ? 'text-gray-700 dark:text-gray-300' : 'text-gray-400'; ?>">
                                                    <?php echo htmlspecialchars($job['company_name'] ?? '—'); ?>
                                                </span>
                                            </td>
                                            <td class="px-4 py-3">
                                                <span class="px-2 py-1 bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 rounded-full text-xs font-medium whitespace-nowrap">
                                                    <?php echo htmlspecialchars($job['job_category'] ?? 'Other'); ?>
                                                </span>
                                            </td>
                                            <td class="px-4 py-3">
                                                <div class="flex items-center text-sm whitespace-nowrap">
                                                    <?php if ($job['location_type'] === 'Remote'): ?>
                                                        <i class="fas fa-globe text-blue-500 mr-1"></i><span>Remote</span>
                                                    <?php else: ?>
                                                        <i class="fas fa-map-marker-alt text-blue-500 mr-1"></i>
                                                        <span><?php echo htmlspecialchars($job['city'] ?? 'Ghana'); ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td class="px-4 py-3">
                                                <span class="text-sm font-medium text-green-600 dark:text-green-400 whitespace-nowrap">
                                                    <?php echo formatAdminSalary($job['salary_min'], $job['salary_max'], $job['salary_currency'], $job['salary_type']); ?>
                                                </span>
                                            </td>
                                            <td class="px-4 py-3">
                                                <span class="status-badge <?php echo getAdminStatusBadgeClass($job['status']); ?>">
                                                    <?php echo $job['status']; ?>
                                                </span>
                                            </td>
                                            <td class="px-4 py-3">
                                                <div class="flex items-center space-x-3 text-sm whitespace-nowrap">
                                                    <span title="Views"><i class="fas fa-eye text-gray-400 mr-1"></i><?php echo number_format($job['total_views']); ?></span>
                                                    <span title="Comments"><i class="fas fa-comment text-gray-400 mr-1"></i><?php echo $job['comment_count']; ?></span>
                                                </div>
                                            </td>
                                            <td class="px-4 py-3">
                                                <span class="text-sm whitespace-nowrap"><?php echo date('M d, Y', strtotime($job['created_at'])); ?></span>
                                            </td>
                                            <td class="px-4 py-3">
                                                <div class="action-buttons">
                                                    <button onclick="viewJobDetails(<?php echo $job['id']; ?>)" 
                                                            class="action-btn bg-blue-100 dark:bg-blue-900/30 text-blue-600 hover:bg-blue-200 dark:hover:bg-blue-800"
                                                            title="View">
                                                        <i class="fas fa-eye text-sm"></i>
                                                    </button>
                                                    <a href="?edit=<?php echo $job['id']; ?>" 
                                                       class="action-btn bg-indigo-100 dark:bg-indigo-900/30 text-indigo-600 hover:bg-indigo-200 dark:hover:bg-indigo-800"
                                                       title="Edit">
                                                        <i class="fas fa-edit text-sm"></i>
                                                    </a>
                                                    <button onclick="openStatusModal(<?php echo $job['id']; ?>, '<?php echo $job['status']; ?>')" 
                                                            class="action-btn bg-yellow-100 dark:bg-yellow-900/30 text-yellow-600 hover:bg-yellow-200 dark:hover:bg-yellow-800"
                                                            title="Status">
                                                        <i class="fas fa-sync-alt text-sm"></i>
                                                    </button>
                                                    <form method="POST" class="inline" onsubmit="return confirm('Toggle featured?')">
                                                        <input type="hidden" name="action" value="toggle_featured">
                                                        <input type="hidden" name="job_id" value="<?php echo $job['id']; ?>">
                                                        <button type="submit" 
                                                                class="action-btn <?php echo $job['is_featured'] ? 'bg-yellow-100 text-yellow-600' : 'bg-gray-100 text-gray-600'; ?>"
                                                                title="<?php echo $job['is_featured'] ? 'Unfeature' : 'Feature'; ?>">
                                                            <i class="fas fa-star text-sm"></i>
                                                        </button>
                                                    </form>
                                                    <form method="POST" class="inline" onsubmit="return confirm('Toggle urgent?')">
                                                        <input type="hidden" name="action" value="toggle_urgent">
                                                        <input type="hidden" name="job_id" value="<?php echo $job['id']; ?>">
                                                        <button type="submit" 
                                                                class="action-btn <?php echo $job['is_urgent'] ? 'bg-red-100 text-red-600' : 'bg-gray-100 text-gray-600'; ?>"
                                                                title="<?php echo $job['is_urgent'] ? 'Unmark Urgent' : 'Mark Urgent'; ?>">
                                                            <i class="fas fa-exclamation-circle text-sm"></i>
                                                        </button>
                                                    </form>
                                                    <button onclick="openDeleteModal(<?php echo $job['id']; ?>, '<?php echo htmlspecialchars(addslashes($job['title'])); ?>')" 
                                                            class="action-btn bg-red-100 dark:bg-red-900/30 text-red-600 hover:bg-red-200 dark:hover:bg-red-800"
                                                            title="Delete">
                                                        <i class="fas fa-trash text-sm"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </form>
                    </div>
                    
                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                    <div class="px-4 py-3 border-t border-gray-200 dark:border-gray-700 flex flex-wrap items-center justify-between gap-3">
                        <p class="text-sm text-gray-500">
                            Showing <?php echo $offset + 1; ?>-<?php echo min($offset + $perPage, $totalJobs); ?> of <?php echo $totalJobs; ?>
                        </p>
                        <div class="flex flex-wrap gap-1">
                            <?php if ($page > 1): ?>
                            <a href="<?php echo buildUrl(['page' => $page - 1]); ?>" class="px-3 py-1.5 rounded-lg bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 transition text-sm">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                            <?php endif; ?>
                            
                            <?php
                            $startPage = max(1, $page - 2);
                            $endPage = min($totalPages, $page + 2);
                            
                            if ($startPage > 1): ?>
                            <a href="<?php echo buildUrl(['page' => 1]); ?>" class="px-3 py-1.5 rounded-lg bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 transition text-sm">1</a>
                            <?php if ($startPage > 2): ?>
                            <span class="px-2 text-gray-400">...</span>
                            <?php endif; endif; ?>
                            
                            <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                            <a href="<?php echo buildUrl(['page' => $i]); ?>" 
                               class="px-3 py-1.5 rounded-lg <?php echo $i == $page ? 'bg-blue-600 text-white' : 'bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600'; ?> transition text-sm">
                                <?php echo $i; ?>
                            </a>
                            <?php endfor; ?>
                            
                            <?php if ($endPage < $totalPages): ?>
                            <?php if ($endPage < $totalPages - 1): ?>
                            <span class="px-2 text-gray-400">...</span>
                            <?php endif; ?>
                            <a href="<?php echo buildUrl(['page' => $totalPages]); ?>" class="px-3 py-1.5 rounded-lg bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 transition text-sm"><?php echo $totalPages; ?></a>
                            <?php endif; ?>
                            
                            <?php if ($page < $totalPages): ?>
                            <a href="<?php echo buildUrl(['page' => $page + 1]); ?>" class="px-3 py-1.5 rounded-lg bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 transition text-sm">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<!-- Mobile Sidebar -->
<div id="mobileSidebar" class="fixed inset-0 z-50 hidden lg:hidden">
    <div class="absolute inset-0 bg-black/50" onclick="closeMobileSidebar()"></div>
    <div class="absolute left-0 top-0 bottom-0 w-72 bg-white dark:bg-gray-800 overflow-y-auto p-6">
        <div class="flex items-center justify-between mb-6">
            <div class="flex items-center space-x-3">
                <div class="w-10 h-10 brand-gradient rounded-lg flex items-center justify-center">
                    <i class="fas fa-briefcase text-white"></i>
                </div>
                <span class="text-xl font-bold bg-gradient-to-r from-blue-600 to-indigo-600 bg-clip-text text-transparent">TransNow</span>
            </div>
            <button onclick="closeMobileSidebar()" class="w-10 h-10 bg-gray-100 dark:bg-gray-700 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-600 transition">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <nav class="space-y-1">
            <a href="dashboard.php" class="block px-4 py-3 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg transition">Dashboard</a>
            <a href="jobs.php" class="block px-4 py-3 bg-blue-50 dark:bg-blue-900/20 text-blue-600 rounded-lg font-medium">Jobs</a>
            <a href="companies.php" class="block px-4 py-3 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg transition">Companies</a>
            <a href="applications.php" class="block px-4 py-3 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg transition">Applications</a>
            <a href="reports.php" class="block px-4 py-3 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg transition">Reports</a>
            <a href="settings.php" class="block px-4 py-3 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg transition">Settings</a>
            <div class="border-t border-gray-200 dark:border-gray-700 my-4"></div>
            <a href="/" target="_blank" class="block px-4 py-3 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg transition">View Site</a>
            <a href="/logout.php" class="block px-4 py-3 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg transition">Logout</a>
        </nav>
    </div>
</div>

<script>
// ==================== DARK MODE ====================
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

darkModeToggle?.addEventListener('click', () => {
    document.documentElement.classList.toggle('dark');
    const isDark = document.documentElement.classList.contains('dark');
    localStorage.theme = isDark ? 'dark' : 'light';
    darkModeIcon?.classList.toggle('fa-moon', !isDark);
    darkModeIcon?.classList.toggle('fa-sun', isDark);
});

// ==================== COMPANY SELECTION ====================
const companyActionRadios = document.querySelectorAll('.company-action-radio');
const existingSection = document.getElementById('existing-company-section');
const newSection = document.getElementById('new-company-section');
const companySearch = document.getElementById('company-search');
const searchResults = document.getElementById('company-search-results');
const selectedCompanyId = document.getElementById('selected-company-id');

let searchTimeout;

// Toggle between existing and new company
companyActionRadios?.forEach(radio => {
    radio.addEventListener('change', function() {
        if (this.value === 'existing') {
            existingSection?.classList.remove('hidden');
            newSection?.classList.add('hidden');
        } else {
            existingSection?.classList.add('hidden');
            newSection?.classList.remove('hidden');
            selectedCompanyId.value = '';
            if (companySearch) companySearch.value = '';
        }
    });
});

// Company search functionality
companySearch?.addEventListener('input', function() {
    clearTimeout(searchTimeout);
    const query = this.value.trim();
    
    if (query.length < 2) {
        searchResults?.classList.add('hidden');
        return;
    }
    
    searchTimeout = setTimeout(() => {
        fetch(`jobs.php?ajax=search_companies&q=${encodeURIComponent(query)}`)
            .then(r => r.json())
            .then(data => {
                if (data.success && data.data.length > 0) {
                    searchResults.innerHTML = data.data.map(company => `
                        <div class="company-result-item" onclick="selectCompany(${company.id}, '${escapeHtml(company.name)}')">
                            <div class="font-medium">${escapeHtml(company.name)}</div>
                            <div class="text-xs text-gray-500">${escapeHtml(company.email || 'No email')}</div>
                        </div>
                    `).join('');
                    searchResults.classList.remove('hidden');
                } else {
                    searchResults.innerHTML = '<div class="p-3 text-center text-gray-500">No companies found</div>';
                    searchResults.classList.remove('hidden');
                }
            })
            .catch(() => {
                searchResults.innerHTML = '<div class="p-3 text-center text-red-500">Error searching</div>';
                searchResults.classList.remove('hidden');
            });
    }, 300);
});

function selectCompany(id, name) {
    selectedCompanyId.value = id;
    companySearch.value = name;
    searchResults.classList.add('hidden');
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Hide search results when clicking outside
document.addEventListener('click', (e) => {
    if (!companySearch?.contains(e.target) && !searchResults?.contains(e.target)) {
        searchResults?.classList.add('hidden');
    }
});

// ==================== MOBILE SIDEBAR ====================
const mobileMenuBtn = document.getElementById('mobileMenuBtn');
const mobileSidebar = document.getElementById('mobileSidebar');

mobileMenuBtn?.addEventListener('click', () => mobileSidebar?.classList.remove('hidden'));
function closeMobileSidebar() { mobileSidebar?.classList.add('hidden'); }

// ==================== BULK ACTIONS ====================
const selectAll = document.getElementById('selectAll');
const checkboxes = document.querySelectorAll('.job-checkbox');
const bulkBar = document.getElementById('bulkActionsBar');
const selectedCount = document.getElementById('selectedCount');

function updateBulkBar() {
    const checked = document.querySelectorAll('.job-checkbox:checked').length;
    if (checked > 0) {
        bulkBar?.classList.remove('hidden');
        bulkBar?.classList.add('flex');
        if (selectedCount) selectedCount.textContent = checked;
    } else {
        bulkBar?.classList.add('hidden');
        bulkBar?.classList.remove('flex');
    }
}

selectAll?.addEventListener('change', () => {
    checkboxes.forEach(cb => cb.checked = selectAll.checked);
    updateBulkBar();
});

checkboxes.forEach(cb => cb.addEventListener('change', () => {
    const allChecked = Array.from(checkboxes).every(cb => cb.checked);
    if (selectAll) {
        selectAll.checked = allChecked;
        selectAll.indeterminate = !allChecked && Array.from(checkboxes).some(cb => cb.checked);
    }
    updateBulkBar();
}));

function deselectAll() {
    if (selectAll) selectAll.checked = selectAll.indeterminate = false;
    checkboxes.forEach(cb => cb.checked = false);
    updateBulkBar();
}

// ==================== MODALS ====================
const deleteModal = document.getElementById('deleteModal');
const deleteJobId = document.getElementById('deleteJobId');
const deleteMessage = document.getElementById('deleteMessage');

function openDeleteModal(jobId, jobTitle) {
    deleteJobId.value = jobId;
    deleteMessage.textContent = `Are you sure you want to delete "${jobTitle}"? This action cannot be undone.`;
    deleteModal?.classList.remove('hidden');
}
function closeDeleteModal() { deleteModal?.classList.add('hidden'); }

const statusModal = document.getElementById('statusModal');
const statusJobId = document.getElementById('statusJobId');
const statusSelect = document.getElementById('statusSelect');

function openStatusModal(jobId, currentStatus) {
    statusJobId.value = jobId;
    if (statusSelect) {
        for (let opt of statusSelect.options) {
            if (opt.value === currentStatus) { opt.selected = true; break; }
        }
    }
    statusModal?.classList.remove('hidden');
}
function closeStatusModal() { statusModal?.classList.add('hidden'); }

// ==================== BULK ACTION CONFIRMATION ====================
function confirmBulkAction() {
    const action = document.getElementById('bulkAction')?.value;
    if (!action) { alert('Please select an action'); return; }
    
    const checked = document.querySelectorAll('.job-checkbox:checked').length;
    if (!checked) { alert('Please select at least one job'); return; }
    
    const actionTexts = {
        activate: 'activate', pause: 'pause', close: 'close',
        feature: 'mark as featured', unfeature: 'remove featured from',
        urgent: 'mark as urgent', unurgent: 'remove urgent from',
        delete: 'delete'
    };
    
    if (confirm(`Are you sure you want to ${actionTexts[action] || action} ${checked} job(s)?`)) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'bulk_action';
        input.value = action;
        document.getElementById('bulkForm').appendChild(input);
        document.getElementById('bulkForm').submit();
    }
}

// ==================== JOB DETAILS MODAL ====================
const jobModal = document.getElementById('jobModal');
const modalContent = document.getElementById('modalContent');

function viewJobDetails(jobId) {
    if (!jobModal) return;
    jobModal.classList.remove('hidden');
    modalContent.innerHTML = `<div class="p-6"><div class="flex justify-between mb-6"><h2 class="text-2xl font-bold">Loading...</h2><button onclick="closeJobModal()" class="text-gray-500"><i class="fas fa-times text-xl"></i></button></div><div class="flex flex-col items-center justify-center h-64"><div class="spinner mb-4"></div><p>Loading job details...</p></div></div>`;
    
    fetch(`jobs.php?ajax=view_job&id=${jobId}`)
        .then(r => r.json())
        .then(data => {
            if (data.success) displayJobDetails(data.data);
            else modalContent.innerHTML = `<div class="p-6"><div class="flex justify-between mb-6"><h2 class="text-2xl font-bold text-red-600">Error</h2><button onclick="closeJobModal()" class="text-gray-500"><i class="fas fa-times"></i></button></div><div class="text-center py-12"><i class="fas fa-exclamation-circle text-5xl text-red-500 mb-4"></i><p>Failed to load job</p><button onclick="closeJobModal()" class="mt-4 px-4 py-2 bg-blue-600 text-white rounded-lg">Close</button></div></div>`;
        })
        .catch(() => modalContent.innerHTML = `<div class="p-6"><div class="flex justify-between mb-6"><h2 class="text-2xl font-bold text-red-600">Error</h2><button onclick="closeJobModal()" class="text-gray-500"><i class="fas fa-times"></i></button></div><div class="text-center py-12"><i class="fas fa-exclamation-triangle text-5xl text-red-500 mb-4"></i><p>Error loading job</p><button onclick="closeJobModal()" class="mt-4 px-4 py-2 bg-blue-600 text-white rounded-lg">Close</button></div></div>`);
}

function displayJobDetails(job) {
    const salary = formatSalary(job.salary_min, job.salary_max, job.salary_currency, job.salary_type);
    const comments = job.recent_comments || [];
    
    modalContent.innerHTML = `
        <div class="p-6">
            <div class="flex justify-between items-start mb-6">
                <div>
                    <h2 class="text-2xl font-bold">${escapeHtml(job.title)}</h2>
                    <div class="flex items-center gap-2 mt-2">
                        ${job.is_featured ? '<span class="bg-yellow-100 text-yellow-800 text-xs px-2 py-1 rounded-full"><i class="fas fa-star mr-1"></i>Featured</span>' : ''}
                        ${job.is_urgent ? '<span class="bg-red-100 text-red-800 text-xs px-2 py-1 rounded-full"><i class="fas fa-exclamation-circle mr-1"></i>Urgent</span>' : ''}
                        <span class="text-sm px-2 py-1 bg-blue-100 text-blue-800 rounded-full">${escapeHtml(job.employment_type)}</span>
                    </div>
                    ${job.company_name ? `<div class="mt-2 text-gray-600 dark:text-gray-400"><i class="fas fa-building mr-1"></i>${escapeHtml(job.company_name)}</div>` : ''}
                </div>
                <button onclick="closeJobModal()" class="text-gray-500 hover:text-gray-700"><i class="fas fa-times text-xl"></i></button>
            </div>
            <div class="grid md:grid-cols-3 gap-6">
                <div class="md:col-span-2 space-y-4">
                    ${job.overview ? `<div class="bg-gray-50 dark:bg-gray-700 rounded-lg p-4"><h3 class="font-semibold mb-2">Overview</h3><p class="whitespace-pre-line">${escapeHtml(job.overview)}</p></div>` : ''}
                    <div class="bg-gray-50 dark:bg-gray-700 rounded-lg p-4"><h3 class="font-semibold mb-2">Description</h3><p class="whitespace-pre-line">${escapeHtml(job.description || 'No description')}</p></div>
                    ${job.requirements ? `<div class="bg-gray-50 dark:bg-gray-700 rounded-lg p-4"><h3 class="font-semibold mb-2">Requirements</h3><p class="whitespace-pre-line">${escapeHtml(job.requirements)}</p></div>` : ''}
                    ${job.responsibilities ? `<div class="bg-gray-50 dark:bg-gray-700 rounded-lg p-4"><h3 class="font-semibold mb-2">Responsibilities</h3><p class="whitespace-pre-line">${escapeHtml(job.responsibilities)}</p></div>` : ''}
                    ${job.benefits ? `<div class="bg-gray-50 dark:bg-gray-700 rounded-lg p-4"><h3 class="font-semibold mb-2">Benefits</h3><p class="whitespace-pre-line">${escapeHtml(job.benefits)}</p></div>` : ''}
                </div>
                <div class="space-y-4">
                    <div class="bg-gray-50 dark:bg-gray-700 rounded-lg p-4">
                        <h3 class="font-semibold mb-2">Details</h3>
                        <div class="space-y-1 text-sm">
                            <div><strong>Category:</strong> ${escapeHtml(job.job_category)}</div>
                            <div><strong>Type:</strong> ${escapeHtml(job.employment_type)}</div>
                            <div><strong>Experience:</strong> ${escapeHtml(job.experience_level)}</div>
                            <div><strong>Location Type:</strong> ${escapeHtml(job.location_type)}</div>
                            ${job.city ? `<div><strong>City:</strong> ${escapeHtml(job.city)}</div>` : ''}
                            <div><strong>Salary:</strong> ${salary}</div>
                        </div>
                    </div>
                    <div class="bg-gray-50 dark:bg-gray-700 rounded-lg p-4">
                        <h3 class="font-semibold mb-2">Stats</h3>
                        <div class="grid grid-cols-2 gap-2 text-center">
                            <div><div class="text-2xl font-bold">${numberFormat(job.total_views || 0)}</div><div class="text-xs">Views</div></div>
                            <div><div class="text-2xl font-bold">${numberFormat(job.total_comments || 0)}</div><div class="text-xs">Comments</div></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="flex justify-end gap-3 pt-4 border-t mt-6">
                <a href="?edit=${job.id}" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">Edit</a>
                <a href="/job-detail.php?id=${job.id}" target="_blank" class="px-4 py-2 bg-gray-200 dark:bg-gray-700 rounded-lg hover:bg-gray-300">View Public</a>
                <button onclick="closeJobModal()" class="px-4 py-2 bg-gray-200 dark:bg-gray-700 rounded-lg hover:bg-gray-300">Close</button>
            </div>
        </div>
    `;
}

function formatSalary(min, max, currency, type) {
    if (!min && !max) return 'Not specified';
    const sym = currency === 'GHS' ? '₵' : (currency === 'USD' ? '$' : currency);
    const period = type === 'Monthly' ? 'mo' : (type === 'Yearly' ? 'yr' : 'hr');
    if (min && max && min != max) return sym + Number(min).toLocaleString() + ' - ' + sym + Number(max).toLocaleString() + '/' + period;
    if (min) return sym + Number(min).toLocaleString() + '/' + period;
    return 'Up to ' + sym + Number(max).toLocaleString() + '/' + period;
}

function numberFormat(num) { return num ? num.toLocaleString() : '0'; }
function closeJobModal() { jobModal?.classList.add('hidden'); }

// ==================== KEYBOARD & AUTO-HIDE ====================
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        closeJobModal(); closeDeleteModal(); closeStatusModal(); closeMobileSidebar();
    }
    if (e.ctrlKey && e.key === 'f') {
        e.preventDefault();
        document.querySelector('input[name="search"]')?.focus();
    }
});

setTimeout(() => {
    document.querySelectorAll('.fixed.top-4.right-4').forEach(el => {
        el.style.transition = 'opacity 0.5s';
        el.style.opacity = '0';
        setTimeout(() => el?.remove(), 500);
    });
}, 4000);
</script>
</body>
</html>