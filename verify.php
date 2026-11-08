<?php
// verify.php - Email verification handler
// Compatible with TransNow login system

session_start();

// Database configuration (using same as login.php)
define('DB_HOST', 'sql311.infinityfree.com');
define('DB_NAME', 'if0_40778153_jobwall');
define('DB_USER', 'if0_40778153');
define('DB_PASS', 'Kaokbnia');

/**
 * Get database connection (same function as login.php)
 */
function getDBConnection() {
    static $conn = null;
    if ($conn === null) {
        try {
            $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
            if ($conn->connect_error) {
                throw new Exception("Connection failed: " . $conn->connect_error);
            }
            $conn->set_charset("utf8mb4");
        } catch (Exception $e) {
            error_log("Database connection error: " . $e->getMessage());
            return false;
        }
    }
    return $conn;
}

$message = '';
$error = '';
$verified = false;

if (isset($_GET['token'])) {
    $token = $_GET['token'];
    
    if (empty($token)) {
        $error = 'Invalid verification token';
    } else {
        $conn = getDBConnection();
        if ($conn) {
            // Find user with this token
            $stmt = $conn->prepare("SELECT id, email, user_type FROM users WHERE verification_token = ? AND is_verified = 0");
            $stmt->bind_param("s", $token);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $user = $result->fetch_assoc();
                
                // Start transaction
                $conn->begin_transaction();
                
                try {
                    // Update user as verified
                    $updateStmt = $conn->prepare("UPDATE users SET is_verified = 1, verification_token = NULL WHERE id = ?");
                    $updateStmt->bind_param("i", $user['id']);
                    
                    if ($updateStmt->execute()) {
                        // If it's an employer, also update employer verification if needed
                        if ($user['user_type'] === 'employer') {
                            // You might want to auto-verify employers or leave for admin
                            // For now, we'll just log it
                            error_log("Employer verified: " . $user['email']);
                        }
                        
                        $conn->commit();
                        $verified = true;
                        $message = "Email verified successfully! You can now login to your account.";
                        
                        // Optional: Log successful verification
                        error_log("User verified: " . $user['email']);
                    } else {
                        throw new Exception("Failed to update user");
                    }
                    $updateStmt->close();
                    
                } catch (Exception $e) {
                    $conn->rollback();
                    $error = "Verification failed. Please try again.";
                    error_log("Verification error: " . $e->getMessage());
                }
            } else {
                // Check if already verified
                $checkVerified = $conn->prepare("SELECT id FROM users WHERE verification_token = ? AND is_verified = 1");
                $checkVerified->bind_param("s", $token);
                $checkVerified->execute();
                $verifiedResult = $checkVerified->get_result();
                
                if ($verifiedResult->num_rows > 0) {
                    $error = 'This email is already verified. <a href="login.php" class="text-indigo-600 hover:underline font-medium">Proceed to login</a>';
                } else {
                    $error = 'Invalid or expired verification token. <a href="resend-verification.php" class="text-indigo-600 hover:underline font-medium">Request a new one</a>';
                }
                $checkVerified->close();
            }
            $stmt->close();
        } else {
            $error = 'Database connection error. Please try again later.';
        }
    }
} else {
    $error = 'No verification token provided';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Verification - TransNow</title>
    <!-- Same styling as login.php -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Inter', sans-serif; }
        .gradient-bg {
            background: linear-gradient(-45deg, #4f46e5, #7c3aed, #db2777, #4f46e5);
            background-size: 400% 400%;
            animation: gradient 15s ease infinite;
        }
        @keyframes gradient {
            0%, 100% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
        }
        .glass-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }
        .dark .glass-card {
            background: rgba(17, 24, 39, 0.95);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        .success-checkmark {
            width: 80px;
            height: 80px;
            margin: 0 auto;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            animation: scaleIn 0.5s ease-out;
        }
        @keyframes scaleIn {
            from { transform: scale(0); opacity: 0; }
            to { transform: scale(1); opacity: 1; }
        }
    </style>
</head>
<body class="bg-gray-50 dark:bg-gray-900 min-h-screen flex items-center justify-center p-4">
    <!-- Animated Background -->
    <div class="fixed inset-0 gradient-bg opacity-10 dark:opacity-20"></div>
    
    <div class="max-w-md w-full relative z-10">
        <!-- Logo -->
        <div class="text-center mb-8">
            <a href="index.php" class="inline-flex items-center space-x-3 group">
                <div class="w-14 h-14 bg-gradient-to-r from-indigo-600 to-purple-600 rounded-xl flex items-center justify-center transform group-hover:rotate-12 transition-all duration-300 shadow-lg">
                    <i class="fas fa-globe-asia text-white text-2xl"></i>
                </div>
                <span class="text-3xl font-bold">
                    <span class="bg-gradient-to-r from-indigo-600 to-purple-600 bg-clip-text text-transparent">TransNow</span>
                </span>
            </a>
        </div>
        
        <!-- Main Card -->
        <div class="glass-card rounded-3xl shadow-2xl p-8 text-center">
            <?php if ($verified): ?>
                <!-- Success State -->
                <div class="success-checkmark bg-gradient-to-r from-green-500 to-emerald-500 mb-6">
                    <i class="fas fa-check text-white text-4xl"></i>
                </div>
                
                <h2 class="text-2xl font-bold text-gray-900 dark:text-white mb-4">
                    Email Verified!
                </h2>
                
                <p class="text-gray-600 dark:text-gray-400 mb-8">
                    <?php echo htmlspecialchars($message); ?>
                </p>
                
                <div class="space-y-3">
                    <a href="login.php" 
                       class="inline-block w-full bg-gradient-to-r from-indigo-600 to-purple-600 text-white font-semibold py-3 px-4 rounded-xl hover:shadow-xl hover:scale-105 transition-all duration-300">
                        <i class="fas fa-sign-in-alt mr-2"></i>
                        Proceed to Login
                    </a>
                    
                    <a href="index.php" 
                       class="inline-block w-full bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 font-semibold py-3 px-4 rounded-xl hover:bg-gray-200 dark:hover:bg-gray-700 transition-all duration-300">
                        <i class="fas fa-home mr-2"></i>
                        Return to Home
                    </a>
                </div>
                
            <?php else: ?>
                <!-- Error State -->
                <div class="w-20 h-20 bg-red-100 dark:bg-red-900/30 rounded-full flex items-center justify-center mx-auto mb-6">
                    <i class="fas fa-exclamation-triangle text-red-600 text-3xl"></i>
                </div>
                
                <h2 class="text-2xl font-bold text-gray-900 dark:text-white mb-4">
                    Verification Failed
                </h2>
                
                <div class="text-gray-600 dark:text-gray-400 mb-8">
                    <?php echo $error; ?>
                </div>
                
                <div class="space-y-3">
                    <a href="resend-verification.php" 
                       class="inline-block w-full bg-gradient-to-r from-indigo-600 to-purple-600 text-white font-semibold py-3 px-4 rounded-xl hover:shadow-xl hover:scale-105 transition-all duration-300">
                        <i class="fas fa-paper-plane mr-2"></i>
                        Resend Verification
                    </a>
                    
                    <a href="login.php" 
                       class="inline-block w-full bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 font-semibold py-3 px-4 rounded-xl hover:bg-gray-200 dark:hover:bg-gray-700 transition-all duration-300">
                        <i class="fas fa-arrow-left mr-2"></i>
                        Back to Login
                    </a>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Help Text -->
        <p class="text-center text-xs text-gray-500 dark:text-gray-400 mt-6">
            Need help? <a href="contact.php" class="text-indigo-600 hover:underline">Contact Support</a>
        </p>
    </div>
</body>
</html>