<?php
// logout.php - User Logout Handler

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration files - use the updated ones
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

// Check if user is logged in
$user_type = $_SESSION['user_type'] ?? null;

// If user was logged in, log the logout activity
if (isset($_SESSION['user_id'])) {
    try {
        // Get database connection
        $pdo = getDB();
        
        if ($pdo) {
            // Update last_logout timestamp
            $stmt = $pdo->prepare("UPDATE users SET last_logout = NOW() WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            
            // Log the logout activity (optional)
            // You can enable this if you have a user_activity table
            // logUserActivity('logout', 'User logged out', $pdo);
        }
    } catch (Exception $e) {
        // Log error but don't stop logout process
        error_log("Logout logging failed: " . $e->getMessage());
    }
}

// Clear all session data
$_SESSION = array();

// Destroy the session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy the session
session_destroy();

// Set logout message
$logout_message = "You have been successfully logged out from your account.";
$logout_message_cn = "您已成功退出登录。";

// HTML response
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logged Out - TransNow</title>
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <style>
        * {
            font-family: 'Inter', sans-serif;
        }
        @keyframes float {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-20px); }
        }
        .animate-float {
            animation: float 6s ease-in-out infinite;
        }
    </style>
    
    <!-- Meta refresh redirect after 3 seconds -->
    <meta http-equiv="refresh" content="3;url=index.php">
</head>
<body class="bg-gradient-to-br from-indigo-50 via-white to-purple-50 dark:from-gray-900 dark:via-gray-800 dark:to-indigo-900 min-h-screen flex items-center justify-center p-4">
    
    <div class="max-w-md w-full">
        <!-- Floating Elements -->
        <div class="absolute inset-0 overflow-hidden pointer-events-none">
            <div class="absolute top-20 left-10 w-64 h-64 bg-indigo-200 dark:bg-indigo-900/20 rounded-full blur-3xl animate-float"></div>
            <div class="absolute bottom-20 right-10 w-80 h-80 bg-purple-200 dark:bg-purple-900/20 rounded-full blur-3xl animate-float" style="animation-delay: 2s"></div>
        </div>
        
        <!-- Main Card -->
        <div class="relative bg-white dark:bg-gray-800 rounded-3xl shadow-2xl p-8 text-center border border-gray-100 dark:border-gray-700" data-aos="fade-up">
            
            <!-- Success Icon -->
            <div class="w-24 h-24 bg-gradient-to-r from-green-500 to-emerald-500 rounded-full flex items-center justify-center mx-auto mb-6 shadow-lg animate-bounce">
                <i class="fas fa-check-circle text-white text-4xl"></i>
            </div>
            
            <!-- Title -->
            <h1 class="text-3xl font-bold mb-3 text-gray-900 dark:text-white">
                Logged Out Successfully
            </h1>
            
            <!-- Message -->
            <p class="text-gray-600 dark:text-gray-400 mb-2">
                <?php echo htmlspecialchars($logout_message); ?>
            </p>
            <p class="text-gray-500 dark:text-gray-500 mb-6 chinese-text">
                <?php echo htmlspecialchars($logout_message_cn); ?>
            </p>
            
            <!-- User Type Specific Message -->
            <?php if ($user_type === 'seeker'): ?>
            <p class="text-sm text-indigo-600 dark:text-indigo-400 mb-6">
                We hope to see you again soon!
            </p>
            <?php elseif ($user_type === 'employer'): ?>
            <p class="text-sm text-indigo-600 dark:text-indigo-400 mb-6">
                Thank you for using TransNow to find talent!
            </p>
            <?php endif; ?>
            
            <!-- Redirect Indicator -->
            <div class="flex items-center justify-center space-x-2 mb-8">
                <div class="w-2 h-2 bg-indigo-600 rounded-full animate-pulse"></div>
                <div class="w-2 h-2 bg-indigo-600 rounded-full animate-pulse" style="animation-delay: 0.2s"></div>
                <div class="w-2 h-2 bg-indigo-600 rounded-full animate-pulse" style="animation-delay: 0.4s"></div>
                <span class="text-sm text-gray-500 ml-2">Redirecting you in 3 seconds...</span>
            </div>
            
            <!-- Action Buttons -->
            <div class="flex space-x-4">
                <a href="index.php" 
                   class="flex-1 bg-gradient-to-r from-indigo-600 to-purple-600 text-white px-6 py-3 rounded-xl font-semibold hover:shadow-xl hover:scale-105 transition-all duration-300 flex items-center justify-center space-x-2">
                    <i class="fas fa-home"></i>
                    <span>Go to Homepage</span>
                </a>
                <a href="login.php" 
                   class="flex-1 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 px-6 py-3 rounded-xl font-semibold hover:bg-gray-200 dark:hover:bg-gray-600 transition-all duration-300 flex items-center justify-center space-x-2">
                    <i class="fas fa-sign-in-alt"></i>
                    <span>Login Again</span>
                </a>
            </div>
            
            <!-- Footer Links -->
            <div class="mt-8 pt-6 border-t border-gray-200 dark:border-gray-700">
                <div class="flex justify-center space-x-4 text-sm text-gray-500">
                    <a href="index.php" class="hover:text-indigo-600 transition">Home</a>
                    <span>•</span>
                    <a href="privacy-policy.php" class="hover:text-indigo-600 transition">Privacy</a>
                    <span>•</span>
                    <a href="terms.php" class="hover:text-indigo-600 transition">Terms</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Dark Mode Script (optional) -->
    <script>
        // Check for saved theme preference
        if (localStorage.theme === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }
    </script>
</body>
</html>