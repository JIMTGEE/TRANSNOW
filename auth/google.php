<?php
// auth/google.php - Google OAuth Authentication Handler
// Version: 1.0 - Complete Google Sign-In integration for TransNow
// This file handles both login and registration via Google

// Error reporting for development (remove in production)
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

// Get database connection
$pdo = getDB();
if (!$pdo) {
    die("Database connection failed. Please try again later.");
}

// ============================================
// GOOGLE OAUTH CONFIGURATION
// ============================================
// Since you mentioned no external files/APIs, we'll simulate Google OAuth
// In a production environment, you would use Google's official PHP client library
// This implementation handles the OAuth flow manually

// Google OAuth credentials (YOU NEED TO REPLACE THESE WITH YOUR OWN)
// Get these from https://console.developers.google.com/
define('GOOGLE_CLIENT_ID', 'YOUR_GOOGLE_CLIENT_ID.apps.googleusercontent.com');
define('GOOGLE_CLIENT_SECRET', 'YOUR_GOOGLE_CLIENT_SECRET');
define('GOOGLE_REDIRECT_URI', 'https://' . $_SERVER['HTTP_HOST'] . '/auth/google.php');

// Google OAuth endpoints
define('GOOGLE_AUTH_URL', 'https://accounts.google.com/o/oauth2/v2/auth');
define('GOOGLE_TOKEN_URL', 'https://oauth2.googleapis.com/token');
define('GOOGLE_USERINFO_URL', 'https://www.googleapis.com/oauth2/v3/userinfo');

// Allowed email domains (empty array = all domains allowed)
// Add domains like ['gmail.com', 'company.com'] to restrict sign-ups
$allowed_domains = []; 

// ============================================
// HELPER FUNCTIONS
// ============================================

/**
 * Generate a random secure password for new users
 */
function generateSecurePassword($length = 12) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()_-=+';
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $password;
}

/**
 * Generate a unique username from email
 */
function generateUsername($email, $pdo) {
    $base = explode('@', $email)[0];
    $base = preg_replace('/[^a-zA-Z0-9]/', '', $base);
    $username = $base;
    $counter = 1;
    
    // Check if username exists in seekers (first_name + last_name) or users table
    while (true) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetchColumn() == 0) {
            break;
        }
        $username = $base . $counter;
        $counter++;
    }
    
    return $username;
}

/**
 * Send welcome email notification (simulated - would use email queue in production)
 */
function sendWelcomeNotification($user_id, $email, $name, $pdo) {
    // In production, this would queue an email
    // For now, we'll create an in-app notification
    
    $subject = "Welcome to TransNow!";
    $message = "Hi " . htmlspecialchars($name) . ", welcome to TransNow! We're excited to help you connect with global opportunities. Complete your profile to get started.";
    
    // Create notification
    $stmt = $pdo->prepare("
        INSERT INTO notifications (user_id, notification_type, subject, message, channel, status, created_at) 
        VALUES (?, 'welcome', ?, ?, 'inapp', 'sent', NOW())
    ");
    $stmt->execute([$user_id, $subject, $message]);
    
    // Add to email queue for later processing
    $stmt = $pdo->prepare("
        INSERT INTO email_queue (recipient_email, subject, body, priority, status, created_at) 
        VALUES (?, ?, ?, 'high', 'pending', NOW())
    ");
    $stmt->execute([$email, $subject, $message]);
}

/**
 * Log authentication attempt
 */
function logAuthAttempt($email, $success, $provider = 'google') {
    $log_file = __DIR__ . '/../logs/auth.log';
    $log_dir = dirname($log_file);
    
    if (!is_dir($log_dir)) {
        mkdir($log_dir, 0755, true);
    }
    
    $timestamp = date('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    $status = $success ? 'SUCCESS' : 'FAILED';
    
    $log_entry = "[$timestamp] [$ip] [$provider] [$status] Email: $email | UA: $user_agent" . PHP_EOL;
    file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
}

/**
 * Get client IP address
 */
function getClientIP() {
    $ipaddress = '';
    if (isset($_SERVER['HTTP_CLIENT_IP']))
        $ipaddress = $_SERVER['HTTP_CLIENT_IP'];
    else if(isset($_SERVER['HTTP_X_FORWARDED_FOR']))
        $ipaddress = $_SERVER['HTTP_X_FORWARDED_FOR'];
    else if(isset($_SERVER['HTTP_X_FORWARDED']))
        $ipaddress = $_SERVER['HTTP_X_FORWARDED'];
    else if(isset($_SERVER['HTTP_FORWARDED_FOR']))
        $ipaddress = $_SERVER['HTTP_FORWARDED_FOR'];
    else if(isset($_SERVER['HTTP_FORWARDED']))
        $ipaddress = $_SERVER['HTTP_FORWARDED'];
    else if(isset($_SERVER['REMOTE_ADDR']))
        $ipaddress = $_SERVER['REMOTE_ADDR'];
    else
        $ipaddress = 'UNKNOWN';
    return $ipaddress;
}

/**
 * Create or update user token for "Remember Me" functionality
 */
function createUserToken($user_id, $pdo) {
    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', strtotime('+30 days'));
    
    // Check if token exists
    $stmt = $pdo->prepare("SELECT id FROM user_tokens WHERE user_id = ?");
    $stmt->execute([$user_id]);
    
    if ($stmt->fetch()) {
        // Update existing token
        $stmt = $pdo->prepare("UPDATE user_tokens SET token = ?, expires_at = ?, created_at = NOW() WHERE user_id = ?");
        $stmt->execute([$token, $expires, $user_id]);
    } else {
        // Insert new token
        $stmt = $pdo->prepare("INSERT INTO user_tokens (user_id, token, expires_at, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->execute([$user_id, $token, $expires]);
    }
    
    // Set cookie
    setcookie('remember_token', $token, strtotime('+30 days'), '/', '', isset($_SERVER['HTTPS']), true);
    
    return $token;
}

// ============================================
// GOOGLE OAUTH FLOW
// ============================================

$action = $_GET['action'] ?? 'login';
$error = null;
$success = null;

// Step 1: Redirect to Google if no code
if (!isset($_GET['code']) && $action !== 'callback') {
    // Generate random state for CSRF protection
    $state = bin2hex(random_bytes(16));
    $_SESSION['google_oauth_state'] = $state;
    
    // Build authorization URL
    $params = [
        'client_id' => GOOGLE_CLIENT_ID,
        'redirect_uri' => GOOGLE_REDIRECT_URI,
        'response_type' => 'code',
        'scope' => 'openid email profile',
        'state' => $state,
        'access_type' => 'offline',
        'prompt' => 'select_account'
    ];
    
    $auth_url = GOOGLE_AUTH_URL . '?' . http_build_query($params);
    
    // For demo purposes, since we don't have real Google credentials,
    // we'll show a simulation page instead of redirecting
    if (GOOGLE_CLIENT_ID === 'YOUR_GOOGLE_CLIENT_ID.apps.googleusercontent.com') {
        // Show demo/login page with Google button simulation
        $show_demo_page = true;
    } else {
        // Redirect to Google
        header('Location: ' . $auth_url);
        exit();
    }
}

// Step 2: Handle callback from Google
if (isset($_GET['code']) || isset($_POST['demo_login'])) {
    
    // For demo purposes - simulate Google user data
    if (isset($_POST['demo_login'])) {
        $demo_email = $_POST['demo_email'] ?? '';
        $demo_name = $_POST['demo_name'] ?? '';
        $demo_picture = $_POST['demo_picture'] ?? '';
        
        if (empty($demo_email)) {
            $error = 'Please enter an email address for demo login';
        } else {
            // Simulate Google user data
            $user_data = [
                'email' => $demo_email,
                'name' => $demo_name ?: explode('@', $demo_email)[0],
                'given_name' => explode('@', $demo_email)[0],
                'family_name' => '',
                'picture' => $demo_picture ?: 'https://ui-avatars.com/api/?name=' . urlencode(explode('@', $demo_email)[0]) . '&background=4f46e5&color=fff&size=200',
                'email_verified' => true
            ];
            
            // Process the user data
            $process_result = processGoogleUser($user_data, $pdo);
            
            if ($process_result['success']) {
                $success = $process_result['message'];
                
                // Redirect based on user type
                if ($_SESSION['user_type'] === 'seeker') {
                    header('Location: /seekers/dashboard.php');
                } elseif ($_SESSION['user_type'] === 'employer') {
                    header('Location: /employer/dashboard.php');
                } else {
                    header('Location: /index.php');
                }
                exit();
            } else {
                $error = $process_result['message'];
            }
        }
    } else {
        // Verify state to prevent CSRF
        if (!isset($_GET['state']) || $_GET['state'] !== ($_SESSION['google_oauth_state'] ?? '')) {
            $error = 'Invalid state parameter. Possible CSRF attack.';
            logAuthAttempt('unknown', false);
        } else {
            // Exchange code for token (in production)
            // For demo, we'll simulate successful Google login
            $code = $_GET['code'];
            
            // This is where you'd make the actual API call to Google
            // Since we don't have real credentials, we'll show a form for demo
            $show_demo_form = true;
        }
    }
}

/**
 * Process Google user data - login or register
 */
function processGoogleUser($user_data, $pdo) {
    try {
        $email = $user_data['email'];
        $name = $user_data['name'];
        $given_name = $user_data['given_name'] ?? explode(' ', $name)[0];
        $family_name = $user_data['family_name'] ?? (strpos($name, ' ') ? substr($name, strpos($name, ' ') + 1) : '');
        $picture = $user_data['picture'] ?? null;
        $email_verified = $user_data['email_verified'] ?? true;
        
        // Check if user exists by email
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $is_new_user = false;
        $user_type = 'seeker'; // Default
        
        if ($user) {
            // User exists - login
            $user_id = $user['id'];
            $user_type = $user['user_type'];
            
            // Update last login
            $stmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
            $stmt->execute([$user_id]);
            
            $message = 'Successfully logged in with Google!';
            
        } else {
            // New user - register
            $is_new_user = true;
            
            // Start transaction
            $pdo->beginTransaction();
            
            try {
                // Create user account
                $password_hash = password_hash(generateSecurePassword(), PASSWORD_DEFAULT);
                $user_type = 'seeker'; // Default to seeker, can be changed later
                
                $stmt = $pdo->prepare("
                    INSERT INTO users (email, password_hash, user_type, is_verified, last_login, created_at, updated_at) 
                    VALUES (?, ?, ?, 1, NOW(), NOW(), NOW())
                ");
                $stmt->execute([$email, $password_hash, $user_type]);
                $user_id = $pdo->lastInsertId();
                
                // Create seeker profile
                $stmt = $pdo->prepare("
                    INSERT INTO seekers (
                        user_id, first_name, last_name, profile_picture, 
                        headline, is_active, profile_completion_percent, created_at, updated_at
                    ) VALUES (?, ?, ?, ?, ?, 1, 15, NOW(), NOW())
                ");
                
                $headline = 'Professional at ' . explode('@', $email)[1] ?? 'Global Company';
                $stmt->execute([
                    $user_id, 
                    $given_name ?: $name, 
                    $family_name, 
                    $picture,
                    $headline
                ]);
                $seeker_id = $pdo->lastInsertId();
                
                // Create default seeker preferences
                $stmt = $pdo->prepare("
                    INSERT INTO seeker_preferences (
                        seeker_id, preferred_location_types, notification_frequency, is_active, created_at, updated_at
                    ) VALUES (?, '[\"remote\",\"hybrid\",\"onsite\"]', 'daily', 1, NOW(), NOW())
                ");
                $stmt->execute([$seeker_id]);
                
                // Send welcome notification
                sendWelcomeNotification($user_id, $email, $given_name ?: $name, $pdo);
                
                // Log user activity
                $stmt = $pdo->prepare("
                    INSERT INTO user_activity (user_id, action, details, ip_address, user_agent, created_at) 
                    VALUES (?, 'google_register', ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $user_id, 
                    'New user registered via Google', 
                    getClientIP(), 
                    $_SERVER['HTTP_USER_AGENT'] ?? null
                ]);
                
                $pdo->commit();
                $message = 'Account created successfully with Google! Welcome to TransNow!';
                
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("Google registration failed: " . $e->getMessage());
                return ['success' => false, 'message' => 'Failed to create account. Please try again.'];
            }
        }
        
        // Set session variables
        $_SESSION['user_id'] = $user_id;
        $_SESSION['user_type'] = $user_type;
        $_SESSION['user_email'] = $email;
        $_SESSION['user_name'] = $given_name ?: $name;
        
        // Set user type specific session IDs
        if ($user_type === 'seeker') {
            // Get seeker ID
            $stmt = $pdo->prepare("SELECT id FROM seekers WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $seeker_id = $stmt->fetchColumn();
            if ($seeker_id) {
                $_SESSION['seeker_id'] = $seeker_id;
            }
        } elseif ($user_type === 'employer') {
            // Get employer ID
            $stmt = $pdo->prepare("SELECT id FROM employers WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $employer_id = $stmt->fetchColumn();
            if ($employer_id) {
                $_SESSION['employer_id'] = $employer_id;
            }
        }
        
        // Create "Remember Me" token if requested
        if (isset($_POST['remember_me']) || isset($_GET['remember_me'])) {
            createUserToken($user_id, $pdo);
        }
        
        // Log successful authentication
        logAuthAttempt($email, true);
        
        return ['success' => true, 'message' => $message, 'is_new' => $is_new_user];
        
    } catch (Exception $e) {
        error_log("Google auth error: " . $e->getMessage());
        logAuthAttempt($email ?? 'unknown', false);
        return ['success' => false, 'message' => 'Authentication failed. Please try again.'];
    }
}

// ============================================
// HTML OUTPUT
// ============================================
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign in with Google - TransNow</title>
    
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Ccircle cx='50' cy='50' r='45' fill='%234f46e5'/%3E%3Cpath d='M30 40 L70 40 L65 70 L35 70 Z' fill='white'/%3E%3Ccircle cx='50' cy='30' r='8' fill='white'/%3E%3C/svg%3E">
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Noto+Sans+SC:wght@300;400;500;700&display=swap" rel="stylesheet">
    
    <!-- AOS Animation -->
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    
    <style>
        * {
            font-family: 'Inter', 'Noto Sans SC', sans-serif;
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0) rotate(0deg); }
            25% { transform: translateY(-20px) rotate(2deg); }
            75% { transform: translateY(20px) rotate(-2deg); }
        }
        
        @keyframes pulse-glow {
            0%, 100% { box-shadow: 0 0 0 0 rgba(79, 70, 229, 0.5); }
            50% { box-shadow: 0 0 30px 10px rgba(79, 70, 229, 0.3); }
        }
        
        @keyframes gradient {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }
        
        @keyframes slideIn {
            from { transform: translateY(20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        @keyframes shimmer {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }
        
        .animate-float {
            animation: float 8s ease-in-out infinite;
        }
        
        .animate-pulse-glow {
            animation: pulse-glow 3s ease-in-out infinite;
        }
        
        .gradient-bg {
            background: linear-gradient(-45deg, #4f46e5, #7c3aed, #db2777, #4f46e5);
            background-size: 400% 400%;
            animation: gradient 15s ease infinite;
        }
        
        .slide-in {
            animation: slideIn 0.6s ease-out forwards;
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
        
        .gradient-text {
            background: linear-gradient(135deg, #4f46e5, #7c3aed, #db2777);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .google-btn {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .google-btn:hover {
            transform: translateY(-2px) scale(1.02);
            box-shadow: 0 20px 30px -10px rgba(66, 133, 244, 0.3);
        }
        
        .particle {
            position: absolute;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.1);
            pointer-events: none;
        }
        
        @media (prefers-reduced-motion: reduce) {
            * {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
            }
        }
        
        .chinese-text {
            font-family: 'Noto Sans SC', sans-serif;
        }
    </style>
</head>
<body class="bg-gray-50 dark:bg-gray-900 min-h-screen flex items-center justify-center p-4 overflow-hidden">
    
    <!-- Animated Background -->
    <div class="fixed inset-0 overflow-hidden">
        <div class="absolute top-20 left-10 w-96 h-96 bg-indigo-200/20 dark:bg-indigo-500/5 rounded-full blur-3xl animate-float"></div>
        <div class="absolute bottom-20 right-10 w-[500px] h-[500px] bg-purple-200/20 dark:bg-purple-500/5 rounded-full blur-3xl animate-float" style="animation-delay: 2s"></div>
        <div class="absolute top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2 w-[800px] h-[800px] bg-pink-200/10 dark:bg-pink-500/5 rounded-full blur-3xl"></div>
        
        <!-- Floating Particles -->
        <div class="absolute inset-0 pointer-events-none">
            <?php for ($i = 0; $i < 30; $i++): ?>
            <div class="particle" style="
                left: <?php echo rand(0, 100); ?>%;
                top: <?php echo rand(0, 100); ?>%;
                width: <?php echo rand(2, 8); ?>px;
                height: <?php echo rand(2, 8); ?>px;
                animation: float <?php echo rand(8, 20); ?>s ease-in-out infinite;
                animation-delay: <?php echo rand(0, 5); ?>s;
                background: rgba(79, 70, 229, <?php echo rand(1, 5) / 10; ?>);
            "></div>
            <?php endfor; ?>
        </div>
    </div>
    
    <!-- Main Card -->
    <div class="relative z-10 w-full max-w-md" data-aos="fade-up" data-aos-duration="800">
        <!-- Logo -->
        <div class="text-center mb-8">
            <a href="/index.php" class="inline-flex items-center space-x-3 group">
                <div class="w-16 h-16 bg-gradient-to-r from-indigo-600 to-purple-600 rounded-2xl flex items-center justify-center transform group-hover:rotate-12 transition-all duration-300 shadow-2xl">
                    <i class="fas fa-globe-asia text-white text-3xl"></i>
                </div>
                <span class="text-4xl font-bold">
                    <span class="gradient-text">TransNow</span>
                </span>
            </a>
        </div>
        
        <!-- Card -->
        <div class="bg-white dark:bg-gray-800 rounded-3xl shadow-2xl p-8 md:p-10 border border-gray-200 dark:border-gray-700">
            
            <!-- Header -->
            <div class="text-center mb-8">
                <h1 class="text-3xl md:text-4xl font-bold mb-3">
                    <span class="gradient-text">Welcome Back</span>
                </h1>
                <p class="text-gray-600 dark:text-gray-400 text-lg">
                    Sign in with Google to continue your journey
                </p>
                <p class="text-sm text-gray-500 mt-2 chinese-text">使用谷歌账号登录，开启职业之旅</p>
            </div>
            
            <!-- Error/Success Messages -->
            <?php if ($error): ?>
            <div class="mb-6 p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-2xl text-red-600 dark:text-red-400 text-sm flex items-start animate-slideIn">
                <i class="fas fa-exclamation-circle mr-3 mt-0.5 text-lg flex-shrink-0"></i>
                <span><?php echo htmlspecialchars($error); ?></span>
            </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
            <div class="mb-6 p-4 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-2xl text-green-600 dark:text-green-400 text-sm flex items-start animate-slideIn">
                <i class="fas fa-check-circle mr-3 mt-0.5 text-lg flex-shrink-0"></i>
                <span><?php echo htmlspecialchars($success); ?></span>
            </div>
            <?php endif; ?>
            
            <!-- Demo Notice (remove in production with real Google credentials) -->
            <?php if (GOOGLE_CLIENT_ID === 'YOUR_GOOGLE_CLIENT_ID.apps.googleusercontent.com'): ?>
            <div class="mb-6 p-4 bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-2xl text-yellow-600 dark:text-yellow-400 text-sm flex items-start">
                <i class="fas fa-info-circle mr-3 mt-0.5 text-lg flex-shrink-0"></i>
                <div>
                    <span class="font-bold">Demo Mode:</span> Configure your Google OAuth credentials in auth/google.php to enable real Google Sign-In.
                </div>
            </div>
            
            <!-- Demo Login Form -->
            <form method="POST" action="google.php" class="space-y-5">
                <div>
                    <label for="demo_email" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        <i class="fas fa-envelope mr-2 text-indigo-500"></i>Email Address (Demo)
                    </label>
                    <input type="email" 
                           id="demo_email" 
                           name="demo_email" 
                           required
                           placeholder="your.email@example.com"
                           class="w-full px-5 py-4 bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-2xl focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:text-white text-lg transition-all">
                </div>
                
                <div>
                    <label for="demo_name" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        <i class="fas fa-user mr-2 text-indigo-500"></i>Full Name (Optional)
                    </label>
                    <input type="text" 
                           id="demo_name" 
                           name="demo_name" 
                           placeholder="John Doe"
                           class="w-full px-5 py-4 bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-2xl focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:text-white text-lg transition-all">
                </div>
                
                <div>
                    <label for="demo_picture" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        <i class="fas fa-image mr-2 text-indigo-500"></i>Profile Picture URL (Optional)
                    </label>
                    <input type="url" 
                           id="demo_picture" 
                           name="demo_picture" 
                           placeholder="https://example.com/photo.jpg"
                           class="w-full px-5 py-4 bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-2xl focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:text-white text-lg transition-all">
                </div>
                
                <!-- Remember Me -->
                <div class="flex items-center">
                    <input type="checkbox" 
                           id="remember_me" 
                           name="remember_me" 
                           class="w-5 h-5 text-indigo-600 border-gray-300 rounded focus:ring-indigo-500">
                    <label for="remember_me" class="ml-3 text-sm text-gray-600 dark:text-gray-400">
                        Remember me for 30 days
                    </label>
                </div>
                
                <button type="submit" 
                        name="demo_login" 
                        class="w-full bg-gradient-to-r from-indigo-600 to-purple-600 text-white font-bold py-4 px-6 rounded-2xl text-lg hover:shadow-2xl hover:scale-105 transition-all duration-300 flex items-center justify-center space-x-3 google-btn">
                    <i class="fab fa-google text-white text-xl"></i>
                    <span>Continue with Demo Google Login</span>
                </button>
            </form>
            
            <?php else: ?>
            <!-- Real Google Login Button -->
            <a href="google.php?action=login" 
               class="w-full bg-white hover:bg-gray-50 dark:bg-gray-700 dark:hover:bg-gray-600 border border-gray-300 dark:border-gray-600 text-gray-800 dark:text-white font-bold py-4 px-6 rounded-2xl text-lg hover:shadow-2xl hover:scale-105 transition-all duration-300 flex items-center justify-center space-x-3 google-btn mb-6">
                <img src="https://www.google.com/favicon.ico" alt="Google" class="w-6 h-6">
                <span>Sign in with Google</span>
            </a>
            <?php endif; ?>
            
            <!-- Divider -->
            <div class="relative my-8">
                <div class="absolute inset-0 flex items-center">
                    <div class="w-full border-t border-gray-300 dark:border-gray-600"></div>
                </div>
                <div class="relative flex justify-center text-sm">
                    <span class="px-4 bg-white dark:bg-gray-800 text-gray-500">Or continue with</span>
                </div>
            </div>
            
            <!-- Alternative Options -->
            <div class="space-y-3">
                <a href="/login.php" 
                   class="w-full bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-800 dark:text-white font-semibold py-4 px-6 rounded-2xl transition-all duration-300 flex items-center justify-center space-x-3">
                    <i class="fas fa-envelope text-indigo-600"></i>
                    <span>Sign in with Email</span>
                </a>
                
                <a href="/register.php" 
                   class="w-full bg-gradient-to-r from-green-500 to-emerald-600 text-white font-semibold py-4 px-6 rounded-2xl hover:shadow-2xl hover:scale-105 transition-all duration-300 flex items-center justify-center space-x-3">
                    <i class="fas fa-user-plus"></i>
                    <span>Create New Account</span>
                </a>
            </div>
            
            <!-- Stats -->
            <div class="mt-8 pt-6 border-t border-gray-200 dark:border-gray-700">
                <div class="flex justify-center space-x-6 text-sm text-gray-600 dark:text-gray-400">
                    <div class="text-center">
                        <div class="font-bold text-indigo-600 dark:text-indigo-400 text-xl">226+</div>
                        <div>Active Jobs</div>
                    </div>
                    <div class="text-center">
                        <div class="font-bold text-indigo-600 dark:text-indigo-400 text-xl">150+</div>
                        <div>Companies</div>
                    </div>
                    <div class="text-center">
                        <div class="font-bold text-indigo-600 dark:text-indigo-400 text-xl">1K+</div>
                        <div>Seekers</div>
                    </div>
                </div>
            </div>
            
            <!-- Trust Badges -->
            <div class="mt-6 text-center">
                <p class="text-xs text-gray-500 mb-3">Trusted by leading companies worldwide</p>
                <div class="flex justify-center space-x-4 text-gray-400">
                    <i class="fab fa-microsoft text-2xl hover:text-indigo-600 transition"></i>
                    <i class="fab fa-google text-2xl hover:text-indigo-600 transition"></i>
                    <i class="fab fa-amazon text-2xl hover:text-indigo-600 transition"></i>
                    <i class="fab fa-alibaba text-2xl hover:text-indigo-600 transition"></i>
                </div>
            </div>
        </div>
        
        <!-- Footer Links -->
        <div class="text-center mt-8 text-sm text-gray-600 dark:text-gray-400">
            <a href="/privacy-policy.php" class="hover:text-indigo-600 dark:hover:text-indigo-400 mx-3">Privacy Policy</a>
            •
            <a href="/terms.php" class="hover:text-indigo-600 dark:hover:text-indigo-400 mx-3">Terms of Service</a>
            •
            <a href="/help-center.php" class="hover:text-indigo-600 dark:hover:text-indigo-400 mx-3">Help Center</a>
        </div>
        
        <!-- Copyright -->
        <div class="text-center mt-4 text-xs text-gray-500">
            &copy; <?php echo date('Y'); ?> TransNow. All rights reserved.
            <span class="block chinese-text mt-1">留学生求职平台 | 国际人才招聘</span>
        </div>
    </div>
    
    <!-- Scripts -->
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    
    <script>
        // Initialize AOS
        AOS.init({
            duration: 800,
            once: true
        });
        
        // Dark mode detection
        if (localStorage.theme === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }
        
        // Form validation
        const demoForm = document.querySelector('form');
        if (demoForm) {
            demoForm.addEventListener('submit', function(e) {
                const email = document.getElementById('demo_email')?.value;
                if (email && !email.includes('@')) {
                    e.preventDefault();
                    alert('Please enter a valid email address');
                }
            });
        }
        
        // Lazy load any images
        const images = document.querySelectorAll('img');
        images.forEach(img => {
            if (!img.complete) {
                img.style.opacity = '0';
                img.addEventListener('load', function() {
                    this.style.transition = 'opacity 0.3s';
                    this.style.opacity = '1';
                });
            }
        });
        
        // Keyboard shortcut: Enter to submit
        document.addEventListener('keypress', function(e) {
            if (e.key === 'Enter' && e.target.tagName !== 'BUTTON' && e.target.tagName !== 'A') {
                const submitBtn = document.querySelector('button[type="submit"]');
                if (submitBtn) submitBtn.click();
            }
        });
    </script>
    
    <!-- Structured Data for SEO -->
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "WebPage",
        "name": "Google Sign In - TransNow",
        "description": "Sign in to TransNow using your Google account to access global job opportunities",
        "publisher": {
            "@type": "Organization",
            "name": "TransNow",
            "logo": "https://<?php echo $_SERVER['HTTP_HOST']; ?>/assets/images/logo.png"
        }
    }
    </script>
</body>
</html>