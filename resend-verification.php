<?php
// resend-verification.php - Resend verification email
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

$email = isset($_GET['email']) ? trim($_GET['email']) : '';
$message = '';
$error = '';
$showForm = true;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['email'])) {
    $email = trim($_POST['email']);
    
    if (empty($email)) {
        $error = 'Email is required';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address';
    } else {
        $conn = getDBConnection();
        if ($conn) {
            // Check if user exists and is not verified
            $stmt = $conn->prepare("SELECT id, email, verification_token FROM users WHERE email = ? AND is_verified = 0");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $user = $result->fetch_assoc();
                
                // Generate new verification token if none exists
                $token = $user['verification_token'];
                if (empty($token)) {
                    $token = bin2hex(random_bytes(32));
                    
                    // Update user with new token
                    $updateStmt = $conn->prepare("UPDATE users SET verification_token = ? WHERE id = ?");
                    $updateStmt->bind_param("si", $token, $user['id']);
                    $updateStmt->execute();
                    $updateStmt->close();
                }
                
                // In a real application, you would send an email here
                // For development/testing, we'll show the verification link
                $verificationLink = "verify.php?token=" . urlencode($token);
                
                // You can implement actual email sending here
                // For now, show success message with link
                $message = "Verification link generated successfully. <a href='$verificationLink' class='text-indigo-600 hover:underline font-medium'>Click here to verify your email</a>";
                $showForm = false;
                
                // Optional: Log that verification was requested
                error_log("Verification requested for email: " . $email);
                
            } else {
                // Check if email exists but is already verified
                $checkVerified = $conn->prepare("SELECT id FROM users WHERE email = ? AND is_verified = 1");
                $checkVerified->bind_param("s", $email);
                $checkVerified->execute();
                $verifiedResult = $checkVerified->get_result();
                
                if ($verifiedResult->num_rows > 0) {
                    $error = 'This email is already verified. <a href="login.php" class="text-indigo-600 hover:underline">Proceed to login</a>';
                } else {
                    $error = 'No account found with this email address. <a href="register.php" class="text-indigo-600 hover:underline">Register now</a>';
                }
                $checkVerified->close();
            }
            $stmt->close();
        } else {
            $error = 'Database connection error. Please try again later.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resend Verification - TransNow</title>
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
        <div class="glass-card rounded-3xl shadow-2xl p-8">
            <div class="text-center mb-8">
                <div class="w-20 h-20 bg-indigo-100 dark:bg-indigo-900/30 rounded-full flex items-center justify-center mx-auto mb-4">
                    <i class="fas fa-envelope-open-text text-indigo-600 text-3xl"></i>
                </div>
                <h2 class="text-2xl font-bold text-gray-900 dark:text-white mb-2">Resend Verification Email</h2>
                <p class="text-gray-600 dark:text-gray-400">Enter your email to receive a new verification link</p>
            </div>
            
            <?php if ($message): ?>
            <div class="mb-6 bg-green-50 dark:bg-green-900/20 border-l-4 border-green-500 p-4 rounded-lg">
                <div class="flex items-center">
                    <i class="fas fa-check-circle text-green-500 mr-3 text-xl"></i>
                    <div class="text-green-700 dark:text-green-300"><?php echo $message; ?></div>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
            <div class="mb-6 bg-red-50 dark:bg-red-900/20 border-l-4 border-red-500 p-4 rounded-lg">
                <div class="flex items-center">
                    <i class="fas fa-exclamation-circle text-red-500 mr-3 text-xl"></i>
                    <div class="text-red-700 dark:text-red-300"><?php echo $error; ?></div>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if ($showForm): ?>
            <form method="POST" action="">
                <div class="mb-6">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Email Address</label>
                    <input type="email" 
                           name="email" 
                           value="<?php echo htmlspecialchars($email); ?>"
                           class="w-full px-4 py-3 bg-gray-50 dark:bg-gray-800 border-2 border-gray-200 dark:border-gray-700 rounded-xl focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 dark:focus:ring-indigo-800 outline-none transition-all text-gray-900 dark:text-white"
                           placeholder="your@email.com"
                           required>
                </div>
                
                <button type="submit" 
                        class="w-full bg-gradient-to-r from-indigo-600 to-purple-600 text-white font-semibold py-3 px-4 rounded-xl hover:shadow-xl hover:scale-105 transition-all duration-300 mb-4">
                    <i class="fas fa-paper-plane mr-2"></i>
                    Send Verification Link
                </button>
            </form>
            <?php endif; ?>
            
            <div class="text-center space-y-3">
                <a href="login.php" class="text-indigo-600 dark:text-indigo-400 hover:underline block">
                    <i class="fas fa-arrow-left mr-1"></i>
                    Back to Login
                </a>
                
                <?php if (!$showForm && $message): ?>
                <a href="resend-verification.php" class="text-gray-600 dark:text-gray-400 hover:text-indigo-600 dark:hover:text-indigo-400 block text-sm">
                    Try another email
                </a>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Help Text -->
        <p class="text-center text-xs text-gray-500 dark:text-gray-400 mt-6">
            Having trouble? <a href="contact.php" class="text-indigo-600 hover:underline">Contact Support</a>
        </p>
    </div>
</body>
</html>