<?php
// includes/auth.php - Authentication functions (PDO version)
// Note: Core functions like isLoggedIn(), getUserType(), getCurrentUserId() 
// are already defined in config/functions.php

/**
 * Require user to be logged in
 */
function requireLogin() {
    if (!isLoggedIn()) {
        $_SESSION['error'] = 'Please login to access this page';
        header('Location: /login.php');
        exit();
    }
}

/**
 * Require seeker login
 */
function requireSeekerLogin() {
    requireLogin();
    
    if (getUserType() !== 'seeker') {
        $_SESSION['error'] = 'You must be a job seeker to access this page';
        header('Location: /index.php');
        exit();
    }
}

/**
 * Require employer login
 */
function requireEmployerLogin() {
    requireLogin();
    
    if (getUserType() !== 'employer') {
        $_SESSION['error'] = 'You must be an employer to access this page';
        header('Location: /index.php');
        exit();
    }
}

/**
 * Require admin login
 */
function requireAdminLogin() {
    requireLogin();
    
    if (getUserType() !== 'admin') {
        $_SESSION['error'] = 'Unauthorized access';
        header('Location: /index.php');
        exit();
    }
}

/**
 * Get database connection (PDO)
 */
function getSecureDBConnection() {
    try {
        return getDB(); // Uses the PDO connection from database.php
    } catch (Exception $e) {
        error_log("Database connection failed in auth.php: " . $e->getMessage());
        return null;
    }
}

/**
 * Get current seeker ID with improved error handling (PDO version)
 */
function getCurrentSeekerId($pdo = null) {
    if (!isLoggedIn() || getUserType() !== 'seeker') {
        return null;
    }
    
    // If seeker_id exists in session, return it
    if (isset($_SESSION['seeker_id'])) {
        return $_SESSION['seeker_id'];
    }
    
    // Get database connection if not provided
    if ($pdo === null) {
        try {
            $pdo = getDB();
        } catch (Exception $e) {
            error_log("Failed to get PDO connection in getCurrentSeekerId(): " . $e->getMessage());
            return null;
        }
    }
    
    // Check if connection is valid
    if (!$pdo) {
        error_log("Invalid database connection in getCurrentSeekerId()");
        return null;
    }
    
    $user_id = $_SESSION['user_id'];
    
    try {
        // Prepare and execute query
        $stmt = $pdo->prepare("SELECT id FROM seekers WHERE user_id = ?");
        $stmt->execute([$user_id]);
        
        if ($row = $stmt->fetch()) {
            $_SESSION['seeker_id'] = $row['id'];
            return $row['id'];
        }
    } catch (PDOException $e) {
        error_log("Query failed in getCurrentSeekerId(): " . $e->getMessage());
    }
    
    return null;
}

/**
 * Get current employer ID with error handling (PDO version)
 */
function getCurrentEmployerId($pdo = null) {
    if (!isLoggedIn() || getUserType() !== 'employer') {
        return null;
    }
    
    // If employer_id exists in session, return it
    if (isset($_SESSION['employer_id'])) {
        return $_SESSION['employer_id'];
    }
    
    // Get database connection if not provided
    if ($pdo === null) {
        try {
            $pdo = getDB();
        } catch (Exception $e) {
            error_log("Failed to get PDO connection in getCurrentEmployerId(): " . $e->getMessage());
            return null;
        }
    }
    
    // Check if connection is valid
    if (!$pdo) {
        error_log("Invalid database connection in getCurrentEmployerId()");
        return null;
    }
    
    $user_id = $_SESSION['user_id'];
    
    try {
        // Prepare and execute query
        $stmt = $pdo->prepare("SELECT id FROM employers WHERE user_id = ?");
        $stmt->execute([$user_id]);
        
        if ($row = $stmt->fetch()) {
            $_SESSION['employer_id'] = $row['id'];
            return $row['id'];
        }
    } catch (PDOException $e) {
        error_log("Query failed in getCurrentEmployerId(): " . $e->getMessage());
    }
    
    return null;
}

/**
 * Check if user has verified account (PDO version)
 */
function isUserVerified($pdo = null) {
    if (!isLoggedIn()) {
        return false;
    }
    
    // Get database connection if not provided
    if ($pdo === null) {
        try {
            $pdo = getDB();
        } catch (Exception $e) {
            error_log("Failed to get PDO connection in isUserVerified(): " . $e->getMessage());
            return false;
        }
    }
    
    if (!$pdo) {
        return false;
    }
    
    $user_id = $_SESSION['user_id'];
    
    try {
        $stmt = $pdo->prepare("SELECT is_verified FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        
        if ($row = $stmt->fetch()) {
            return (bool)$row['is_verified'];
        }
    } catch (PDOException $e) {
        error_log("Query failed in isUserVerified(): " . $e->getMessage());
    }
    
    return false;
}

/**
 * Get user data (PDO version)
 */
function getUserData($pdo = null, $user_id = null) {
    if (!isLoggedIn() && $user_id === null) {
        return null;
    }
    
    if ($user_id === null) {
        $user_id = $_SESSION['user_id'];
    }
    
    // Get database connection if not provided
    if ($pdo === null) {
        try {
            $pdo = getDB();
        } catch (Exception $e) {
            error_log("Failed to get PDO connection in getUserData(): " . $e->getMessage());
            return null;
        }
    }
    
    if (!$pdo) {
        return null;
    }
    
    try {
        $stmt = $pdo->prepare("SELECT id, email, user_type, is_verified, created_at, last_login FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        
        return $stmt->fetch();
    } catch (PDOException $e) {
        error_log("Query failed in getUserData(): " . $e->getMessage());
    }
    
    return null;
}

/**
 * Log user activity (PDO version)
 * @param string $action The action being logged
 * @param string|null $details Additional details
 * @param PDO|null $pdo Database connection (optional)
 */
function logUserActivity($action, $details = null, $pdo = null) {
    if (!isLoggedIn()) {
        return false;
    }
    
    // Get database connection if not provided
    if ($pdo === null) {
        try {
            $pdo = getDB();
        } catch (Exception $e) {
            error_log("Failed to get PDO connection in logUserActivity(): " . $e->getMessage());
            return false;
        }
    }
    
    if (!$pdo) {
        return false;
    }
    
    $user_id = $_SESSION['user_id'];
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    
    try {
        // Check if user_activity table exists
        $stmt = $pdo->prepare("INSERT INTO user_activity (user_id, action, details, ip_address, user_agent, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        return $stmt->execute([$user_id, $action, $details, $ip_address, $user_agent]);
    } catch (PDOException $e) {
        error_log("Query failed in logUserActivity(): " . $e->getMessage());
    }
    
    return false;
}
   
/**
 * Check if user has permission
 */
function hasPermission($permission) {
    // This can be expanded based on your permission system
    $user_type = getUserType();
    
    $permissions = [
        'admin' => ['all'],
        'employer' => ['post_jobs', 'view_applications', 'manage_company'],
        'seeker' => ['apply_jobs', 'save_jobs', 'view_profile']
    ];
    
    if ($user_type === 'admin') {
        return true;
    }
    
    return isset($permissions[$user_type]) && in_array($permission, $permissions[$user_type]);
}

/**
 * Generate CSRF token
 */
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF token
 */
function verifyCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Clear auth session
 */
function clearAuthSession() {
    // Unset all session variables
    $_SESSION = array();
    
    // Delete the session cookie
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    
    // Destroy the session
    session_destroy();
}

/**
 * Secure logout
 */
function logout() {
    clearAuthSession();
    
    // Redirect to login page
    header('Location: /login.php');
    exit();
}
?>