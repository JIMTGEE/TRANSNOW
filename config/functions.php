<?php
// config/functions.php - Common helper functions

require_once __DIR__ . '/database.php';

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Get database connection (PDO)
 */
function getDBConnection() {
    return getDB();
}

/**
 * Check if user is logged in
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

/**
 * Get current user type
 */
function getUserType() {
    return $_SESSION['user_type'] ?? null;
}

/**
 * Get current user ID
 */
function getCurrentUserId() {
    return $_SESSION['user_id'] ?? null;
}

/**
 * Create a URL-friendly slug from a string
 */
function createSlug($string) {
    $string = strtolower($string);
    $string = str_replace(
        ['á', 'é', 'í', 'ó', 'ú', 'ñ', 'ü', 'ç', 'ß', 'æ', 'œ', ' ', '&'],
        ['a', 'e', 'i', 'o', 'u', 'n', 'u', 'c', 'ss', 'ae', 'oe', '-', 'and'],
        $string
    );
    $string = preg_replace('/[^a-z0-9-]/', '', $string);
    $string = preg_replace('/-+/', '-', $string);
    $string = trim($string, '-');
    
    if (empty($string)) {
        return 'job-' . uniqid();
    }
    
    return $string;
}

/**
 * Fetch single row from database
 */
function fetchOne($query, $params = []) {
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("fetchOne failed: " . $e->getMessage());
        return null;
    }
}

/**
 * Fetch multiple rows from database
 */
function fetchAll($query, $params = []) {
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("fetchAll failed: " . $e->getMessage());
        return [];
    }
}

/**
 * Execute query with parameters
 */
function executeQuery($query, $params = []) {
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare($query);
        return $stmt->execute($params);
    } catch (PDOException $e) {
        error_log("executeQuery failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Execute INSERT query and return last insert ID
 */
function executeInsert($query, $params = []) {
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        return $pdo->lastInsertId();
    } catch (PDOException $e) {
        error_log("executeInsert failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Get last insert ID
 */
function getLastInsertId() {
    try {
        $pdo = getDB();
        return $pdo->lastInsertId();
    } catch (PDOException $e) {
        error_log("getLastInsertId failed: " . $e->getMessage());
        return 0;
    }
}

/**
 * Format salary for display
 */
function formatSalary($min, $max, $currency, $type) {
    if (!$min && !$max) return 'Salary not specified';
    $currency = $currency ?: 'GHS';
    $type = $type ?: 'Monthly';
    if ($min && $max) {
        return number_format($min) . ' - ' . number_format($max) . ' ' . $currency . ' / ' . $type;
    } elseif ($min) {
        return 'From ' . number_format($min) . ' ' . $currency . ' / ' . $type;
    } elseif ($max) {
        return 'Up to ' . number_format($max) . ' ' . $currency . ' / ' . $type;
    }
    return 'Salary negotiable';
}

/**
 * Get status badge class
 */
function getStatusBadgeClass($status) {
    $classes = [
        'Active' => 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400',
        'Draft' => 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-400',
        'Paused' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400',
        'Closed' => 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400',
        'Expired' => 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-400',
    ];
    return $classes[$status] ?? 'bg-gray-100 text-gray-800';
}

/**
 * Get employment type badge class
 */
function getEmploymentTypeBadge($type) {
    $classes = [
        'Full-time' => 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400',
        'Part-time' => 'bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-400',
        'Contract' => 'bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-400',
        'Freelance' => 'bg-teal-100 text-teal-800 dark:bg-teal-900/30 dark:text-teal-400',
        'Remote' => 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400',
        'Internship' => 'bg-pink-100 text-pink-800 dark:bg-pink-900/30 dark:text-pink-400',
    ];
    return $classes[$type] ?? 'bg-gray-100 text-gray-800';
}

/**
 * Sanitize input
 */
function sanitize($input) {
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
}

/**
 * Validate email
 */
function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

/**
 * Generate random string
 */
function generateRandomString($length = 32) {
    return bin2hex(random_bytes($length / 2));
}

/**
 * Display flash message
 */
function displayFlashMessage() {
    $html = '';
    
    if (isset($_SESSION['success'])) {
        $html = '<div class="fixed top-20 right-4 z-50 bg-green-500 text-white px-6 py-3 rounded-xl shadow-lg animate-slideIn">
                    <i class="fas fa-check-circle mr-2"></i>
                    ' . htmlspecialchars($_SESSION['success']) . '
                </div>';
        unset($_SESSION['success']);
    }
    
    if (isset($_SESSION['error'])) {
        $html = '<div class="fixed top-20 right-4 z-50 bg-red-500 text-white px-6 py-3 rounded-xl shadow-lg animate-slideIn">
                    <i class="fas fa-exclamation-circle mr-2"></i>
                    ' . htmlspecialchars($_SESSION['error']) . '
                </div>';
        unset($_SESSION['error']);
    }
    
    return $html;
}

/**
 * Get user IP
 */
function getUserIP() {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        return $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return $_SERVER['HTTP_X_FORWARDED_FOR'];
    } else {
        return $_SERVER['REMOTE_ADDR'];
    }
}

/**
 * Increment job view count
 */
function incrementJobViews($jobId) {
    $query = "UPDATE jobs SET total_views = total_views + 1 WHERE id = ?";
    return executeQuery($query, [$jobId]);
}

/**
 * Get related jobs
 */
function getRelatedJobs($jobId, $category, $limit = 3) {
    $query = "SELECT id, title, slug, job_category, employment_type, city, country, salary_min, salary_max, salary_currency, salary_type 
              FROM jobs 
              WHERE status = 'Active' AND id != ? AND job_category = ?
              ORDER BY created_at DESC 
              LIMIT ?";
    return fetchAll($query, [$jobId, $category, $limit]);
}
?>