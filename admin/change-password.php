<?php
/**
 * admin/change-password.php - Admin Password Change Page
 * Version: 1.0 - Secure password update for administrators
 */

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
try {
    $pdo = getDB();
} catch (Exception $e) {
    $_SESSION['error'] = 'Database connection failed: ' . $e->getMessage();
    header('Location: dashboard.php');
    exit();
}

// Get admin info
$adminData = fetchOne("
    SELECT u.* 
    FROM users u 
    WHERE u.id = ? AND u.user_type = 'admin'
", [$_SESSION['user_id']]);

if (!$adminData) {
    $_SESSION['error'] = 'Admin account not found';
    header('Location: /logout.php');
    exit();
}

// Initialize variables
$error = '';
$success = '';
$currentPassword = '';
$newPassword = '';
$confirmPassword = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    
    // Validation
    $errors = [];
    
    if (empty($currentPassword)) {
        $errors[] = 'Current password is required';
    }
    
    if (empty($newPassword)) {
        $errors[] = 'New password is required';
    }
    
    if (empty($confirmPassword)) {
        $errors[] = 'Please confirm your new password';
    }
    
    if ($newPassword !== $confirmPassword) {
        $errors[] = 'New password and confirmation do not match';
    }
    
    // Password strength validation
    if (strlen($newPassword) < 8) {
        $errors[] = 'New password must be at least 8 characters long';
    }
    
    if (!preg_match('/[A-Z]/', $newPassword)) {
        $errors[] = 'New password must contain at least one uppercase letter';
    }
    
    if (!preg_match('/[a-z]/', $newPassword)) {
        $errors[] = 'New password must contain at least one lowercase letter';
    }
    
    if (!preg_match('/[0-9]/', $newPassword)) {
        $errors[] = 'New password must contain at least one number';
    }
    
    if (!preg_match('/[!@#$%^&*(),.?":{}|<>]/', $newPassword)) {
        $errors[] = 'New password must contain at least one special character';
    }
    
    // Verify current password
    if (empty($errors)) {
        try {
            // Get current password hash
            $userData = fetchOne("SELECT password_hash FROM users WHERE id = ?", [$_SESSION['user_id']]);
            
            if (!$userData || !password_verify($currentPassword, $userData['password_hash'])) {
                $errors[] = 'Current password is incorrect';
            }
            
            // Check if new password is same as old
            if (password_verify($newPassword, $userData['password_hash'])) {
                $errors[] = 'New password cannot be the same as your current password';
            }
            
        } catch (Exception $e) {
            $errors[] = 'Error verifying password: ' . $e->getMessage();
            error_log("Password verification error: " . $e->getMessage());
        }
    }
    
    // Update password if no errors
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            // Hash the new password
            $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT, ['cost' => 10]);
            
            // Update password
            $stmt = $pdo->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$newPasswordHash, $_SESSION['user_id']]);
            
            // Log the password change (optional - you can create a password_change_logs table)
            // $logStmt = $pdo->prepare("INSERT INTO password_change_logs (user_id, changed_at, ip_address) VALUES (?, NOW(), ?)");
            // $logStmt->execute([$_SESSION['user_id'], $_SERVER['REMOTE_ADDR'] ?? '']);
            
            $pdo->commit();
            
            $success = 'Your password has been updated successfully!';
            
            // Clear form fields
            $currentPassword = '';
            $newPassword = '';
            $confirmPassword = '';
            
            // Optional: Force re-login after password change
            // Uncomment the following lines to force re-login
            // session_destroy();
            // header('Location: /login.php?message=password_changed');
            // exit();
            
        } catch (PDOException $e) {
            $pdo->rollBack();
            $error = 'Error updating password: ' . $e->getMessage();
            error_log("Password update error: " . $e->getMessage());
        }
    } else {
        $error = implode('<br>', $errors);
    }
}

// Get last password change info (optional - if you have a log table)
$lastChangeInfo = null;
// Uncomment if you have password_change_logs table
/*
try {
    $lastChangeInfo = fetchOne("
        SELECT changed_at, ip_address 
        FROM password_change_logs 
        WHERE user_id = ? 
        ORDER BY changed_at DESC 
        LIMIT 1
    ", [$_SESSION['user_id']]);
} catch (Exception $e) {
    // Silently fail
}
*/
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password - Admin Dashboard | TransNow Ghana</title>
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <style>
        * { font-family: 'Inter', sans-serif; }
        
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: #f1f1f1; }
        ::-webkit-scrollbar-thumb { background: #cbd5e0; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        .dark ::-webkit-scrollbar-track { background: #1f2937; }
        .dark ::-webkit-scrollbar-thumb { background: #4b5563; }
        .dark ::-webkit-scrollbar-thumb:hover { background: #6b7280; }
        
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        .animate-slideIn { animation: slideIn 0.3s ease-out; }
        
        .hover-card {
            transition: all 0.3s ease;
        }
        .hover-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.02);
        }
        
        .brand-gradient {
            background: linear-gradient(135deg, #1E3A8A 0%, #2563EB 50%, #3B82F6 100%);
        }
        
        .form-input {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid #d1d5db;
            border-radius: 0.75rem;
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
        
        .password-strength {
            height: 4px;
            border-radius: 2px;
            background: #e5e7eb;
            margin-top: 0.5rem;
            overflow: hidden;
        }
        
        .password-strength-bar {
            height: 100%;
            width: 0%;
            transition: width 0.3s, background-color 0.3s;
            border-radius: 2px;
        }
        
        .strength-weak { width: 25%; background: #ef4444; }
        .strength-fair { width: 50%; background: #f59e0b; }
        .strength-good { width: 75%; background: #3b82f6; }
        .strength-strong { width: 100%; background: #10b981; }
        
        .requirement-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.75rem;
            color: #6b7280;
        }
        .requirement-item.valid {
            color: #10b981;
        }
        .dark .requirement-item {
            color: #9ca3af;
        }
        .dark .requirement-item.valid {
            color: #34d399;
        }
    </style>
</head>
<body class="bg-gray-50 dark:bg-gray-900 text-gray-900 dark:text-white">

<!-- Flash Messages -->
<?php if (isset($_SESSION['success'])): ?>
<div class="fixed top-4 right-4 z-50 bg-green-500 text-white px-6 py-3 rounded-lg shadow-lg animate-slideIn flex items-center">
    <i class="fas fa-check-circle mr-2"></i>
    <span><?php echo htmlspecialchars($_SESSION['success']); ?></span>
    <button onclick="this.parentElement.remove()" class="ml-4 hover:text-gray-200">
        <i class="fas fa-times"></i>
    </button>
</div>
<?php unset($_SESSION['success']); endif; ?>

<?php if (isset($_SESSION['error'])): ?>
<div class="fixed top-4 right-4 z-50 bg-red-500 text-white px-6 py-3 rounded-lg shadow-lg animate-slideIn flex items-center">
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
                    <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($adminData['email'] ?? 'Admin'); ?>&background=2563eb&color=fff&size=64" 
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
           
            <a href="jobs.php" class="flex items-center space-x-3 px-4 py-3 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                <i class="fas fa-briefcase w-5"></i><span>Jobs</span>
            </a>
           
            <a href="applications.php" class="flex items-center space-x-3 px-4 py-3 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                    <i class="fas fa-file-alt w-5"></i>
                    <span>Applications</span>
                </a>
            <a href="settings.php" class="flex items-center space-x-3 px-4 py-3 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                <i class="fas fa-cog w-5"></i><span>Settings</span>
            </a>
             <a href="reports.php" class="flex items-center space-x-3 px-4 py-3 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                    <i class="fas fa-chart-bar w-5"></i>
                    <span>Reports</span>
                </a>
            
            <div class="border-t border-gray-200 dark:border-gray-700 my-4"></div>
            
            <a href="change-password.php" class="flex items-center space-x-3 px-4 py-3 bg-blue-50 dark:bg-blue-900/20 text-blue-600 dark:text-blue-400 rounded-xl font-medium">
                <i class="fas fa-key w-5"></i><span>Change Password</span>
            </a>
            <a href="/" target="_blank" class="flex items-center space-x-3 px-4 py-3 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                <i class="fas fa-external-link-alt w-5"></i><span>View Site</span>
            </a>
            <a href="/logout.php" class="flex items-center space-x-3 px-4 py-3 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-xl transition">
                <i class="fas fa-sign-out-alt w-5"></i><span>Logout</span>
            </a>
        </nav>
    </aside>

    <!-- Main Content -->
    <main class="flex-1 overflow-x-hidden">
        <!-- Top Bar -->
        <header class="sticky top-0 z-30 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700">
            <div class="px-6 py-4 flex items-center justify-between">
                <button id="mobileMenuBtn" class="lg:hidden w-10 h-10 bg-gray-100 dark:bg-gray-700 rounded-lg flex items-center justify-center hover:bg-gray-200 dark:hover:bg-gray-600 transition">
                    <i class="fas fa-bars"></i>
                </button>
                
                <h1 class="text-2xl font-bold">
                    <span class="text-blue-600">Change</span> Password
                </h1>
                
                <div class="w-10"></div>
            </div>
        </header>

        <!-- Content -->
        <div class="p-6">
            <div class="max-w-2xl mx-auto">
                <!-- Breadcrumb -->
                <nav class="flex items-center gap-2 text-sm mb-6 text-gray-500">
                    <a href="dashboard.php" class="hover:text-blue-600">Dashboard</a>
                    <i class="fas fa-chevron-right text-xs"></i>
                    <span class="text-gray-900 dark:text-white">Change Password</span>
                </nav>
                
                <!-- Main Card -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 md:p-8 hover-card">
                    <div class="flex items-center gap-4 mb-6">
                        <div class="w-14 h-14 brand-gradient rounded-xl flex items-center justify-center">
                            <i class="fas fa-lock text-white text-xl"></i>
                        </div>
                        <div>
                            <h2 class="text-xl font-bold">Update Your Password</h2>
                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                Choose a strong, unique password to keep your account secure
                            </p>
                        </div>
                    </div>
                    
                    <!-- Error Message -->
                    <?php if ($error): ?>
                    <div class="mb-6 p-4 bg-red-50 dark:bg-red-900/20 border-l-4 border-red-500 rounded-lg">
                        <div class="flex items-start">
                            <i class="fas fa-exclamation-circle text-red-500 mr-3 mt-0.5"></i>
                            <div class="text-red-700 dark:text-red-300 text-sm"><?php echo $error; ?></div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Success Message -->
                    <?php if ($success): ?>
                    <div class="mb-6 p-4 bg-green-50 dark:bg-green-900/20 border-l-4 border-green-500 rounded-lg">
                        <div class="flex items-center">
                            <i class="fas fa-check-circle text-green-500 mr-3"></i>
                            <div class="text-green-700 dark:text-green-300"><?php echo $success; ?></div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Last Password Change Info -->
                    <?php if ($lastChangeInfo): ?>
                    <div class="mb-6 p-3 bg-blue-50 dark:bg-blue-900/20 rounded-lg text-sm text-gray-600 dark:text-gray-400">
                        <i class="fas fa-history mr-2 text-blue-500"></i>
                        Last password change: <?php echo date('F d, Y \a\t h:i A', strtotime($lastChangeInfo['changed_at'])); ?>
                        <?php if ($lastChangeInfo['ip_address']): ?>
                        <span class="ml-2 text-xs">(IP: <?php echo htmlspecialchars($lastChangeInfo['ip_address']); ?>)</span>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    
                    <form method="POST" action="" id="passwordForm" class="space-y-6">
                        <!-- Current Password -->
                        <div>
                            <label for="current_password" class="form-label">
                                <i class="fas fa-key text-blue-500 mr-2"></i>Current Password
                            </label>
                            <div class="relative">
                                <input type="password" 
                                       name="current_password" 
                                       id="current_password"
                                       value="<?php echo htmlspecialchars($currentPassword); ?>"
                                       class="form-input pr-12"
                                       placeholder="Enter your current password"
                                       required>
                                <button type="button" 
                                        onclick="togglePasswordVisibility('current_password', 'toggleCurrentIcon')"
                                        class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400 hover:text-blue-500 transition">
                                    <i class="fas fa-eye" id="toggleCurrentIcon"></i>
                                </button>
                            </div>
                        </div>
                        
                        <!-- New Password -->
                        <div>
                            <label for="new_password" class="form-label">
                                <i class="fas fa-lock text-blue-500 mr-2"></i>New Password
                            </label>
                            <div class="relative">
                                <input type="password" 
                                       name="new_password" 
                                       id="new_password"
                                       class="form-input pr-12"
                                       placeholder="Enter new password"
                                       oninput="checkPasswordStrength()"
                                       required>
                                <button type="button" 
                                        onclick="togglePasswordVisibility('new_password', 'toggleNewIcon')"
                                        class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400 hover:text-blue-500 transition">
                                    <i class="fas fa-eye" id="toggleNewIcon"></i>
                                </button>
                            </div>
                            
                            <!-- Password Strength Meter -->
                            <div class="password-strength">
                                <div id="passwordStrengthBar" class="password-strength-bar"></div>
                            </div>
                            <p id="passwordStrengthText" class="text-xs text-gray-500 mt-1">Enter a password to check strength</p>
                            
                            <!-- Password Requirements -->
                            <div class="mt-3 p-3 bg-gray-50 dark:bg-gray-700/50 rounded-lg">
                                <p class="text-xs font-medium mb-2 text-gray-600 dark:text-gray-400">Password Requirements:</p>
                                <div class="grid grid-cols-2 gap-2">
                                    <div class="requirement-item" id="req-length">
                                        <i class="far fa-circle text-xs"></i>
                                        <span>At least 8 characters</span>
                                    </div>
                                    <div class="requirement-item" id="req-uppercase">
                                        <i class="far fa-circle text-xs"></i>
                                        <span>One uppercase letter</span>
                                    </div>
                                    <div class="requirement-item" id="req-lowercase">
                                        <i class="far fa-circle text-xs"></i>
                                        <span>One lowercase letter</span>
                                    </div>
                                    <div class="requirement-item" id="req-number">
                                        <i class="far fa-circle text-xs"></i>
                                        <span>One number</span>
                                    </div>
                                    <div class="requirement-item" id="req-special">
                                        <i class="far fa-circle text-xs"></i>
                                        <span>One special character</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Confirm New Password -->
                        <div>
                            <label for="confirm_password" class="form-label">
                                <i class="fas fa-check-circle text-blue-500 mr-2"></i>Confirm New Password
                            </label>
                            <div class="relative">
                                <input type="password" 
                                       name="confirm_password" 
                                       id="confirm_password"
                                       class="form-input pr-12"
                                       placeholder="Confirm new password"
                                       oninput="checkPasswordMatch()"
                                       required>
                                <button type="button" 
                                        onclick="togglePasswordVisibility('confirm_password', 'toggleConfirmIcon')"
                                        class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400 hover:text-blue-500 transition">
                                    <i class="fas fa-eye" id="toggleConfirmIcon"></i>
                                </button>
                            </div>
                            <p id="passwordMatchMessage" class="text-xs mt-1"></p>
                        </div>
                        
                        <!-- Security Tips -->
                        <div class="p-4 bg-yellow-50 dark:bg-yellow-900/20 rounded-lg">
                            <h4 class="font-medium text-sm mb-2 flex items-center">
                                <i class="fas fa-shield-alt text-yellow-600 mr-2"></i>
                                Security Tips
                            </h4>
                            <ul class="text-xs text-gray-600 dark:text-gray-400 space-y-1">
                                <li>• Use a unique password that you don't use elsewhere</li>
                                <li>• Avoid using personal information like names or birthdays</li>
                                <li>• Consider using a password manager</li>
                                <li>• Never share your password with anyone</li>
                            </ul>
                        </div>
                        
                        <!-- Form Actions -->
                        <div class="flex items-center justify-between pt-4 border-t border-gray-200 dark:border-gray-700">
                            <a href="dashboard.php" class="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300 transition">
                                <i class="fas fa-arrow-left mr-2"></i>Back to Dashboard
                            </a>
                            <button type="submit" 
                                    id="submitBtn"
                                    class="px-6 py-2.5 brand-gradient text-white rounded-lg hover:opacity-90 transition hover:scale-105 transform duration-200 flex items-center gap-2">
                                <i class="fas fa-save"></i>
                                <span>Update Password</span>
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- Additional Info Card -->
                <div class="mt-6 bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 hover-card">
                    <h3 class="font-semibold mb-3 flex items-center">
                        <i class="fas fa-info-circle text-blue-500 mr-2"></i>
                        Account Information
                    </h3>
                    <div class="grid sm:grid-cols-2 gap-4 text-sm">
                        <div>
                            <p class="text-gray-500 dark:text-gray-400">Logged in as:</p>
                            <p class="font-medium"><?php echo htmlspecialchars($adminData['email'] ?? ''); ?></p>
                        </div>
                        <div>
                            <p class="text-gray-500 dark:text-gray-400">Account Type:</p>
                            <p class="font-medium">
                                <span class="px-2 py-1 bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300 rounded-full text-xs">Administrator</span>
                            </p>
                        </div>
                        <div>
                            <p class="text-gray-500 dark:text-gray-400">Last Login:</p>
                            <p class="font-medium"><?php echo $adminData['last_login'] ? date('M d, Y h:i A', strtotime($adminData['last_login'])) : 'N/A'; ?></p>
                        </div>
                        <div>
                            <p class="text-gray-500 dark:text-gray-400">Account Created:</p>
                            <p class="font-medium"><?php echo $adminData['created_at'] ? date('M d, Y', strtotime($adminData['created_at'])) : 'N/A'; ?></p>
                        </div>
                    </div>
                </div>
            </div>
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
        
        <div class="p-4 bg-gray-50 dark:bg-gray-700 rounded-xl mb-6">
            <div class="flex items-center space-x-3">
                <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($adminData['email'] ?? 'Admin'); ?>&background=2563eb&color=fff&size=64" 
                     alt="Admin" class="w-12 h-12 rounded-lg border-2 border-blue-200">
                <div>
                    <p class="font-semibold">Administrator</p>
                    <p class="text-sm text-gray-500 dark:text-gray-400"><?php echo htmlspecialchars($adminData['email'] ?? ''); ?></p>
                </div>
            </div>
        </div>
        
        <nav class="space-y-1">
            <a href="dashboard.php" class="block px-4 py-3 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg transition">Dashboard</a>

            <a href="jobs.php" class="block px-4 py-3 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg transition">Jobs</a>
          
            <a href="settings.php" class="block px-4 py-3 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg transition">Settings</a>
            <div class="border-t border-gray-200 dark:border-gray-700 my-4"></div>
            <a href="change-password.php" class="block px-4 py-3 bg-blue-50 dark:bg-blue-900/20 text-blue-600 rounded-lg font-medium">Change Password</a>
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

// ==================== MOBILE SIDEBAR ====================
const mobileMenuBtn = document.getElementById('mobileMenuBtn');
const mobileSidebar = document.getElementById('mobileSidebar');

mobileMenuBtn?.addEventListener('click', () => mobileSidebar?.classList.remove('hidden'));
function closeMobileSidebar() { mobileSidebar?.classList.add('hidden'); }

// ==================== PASSWORD TOGGLE ====================
function togglePasswordVisibility(inputId, iconId) {
    const input = document.getElementById(inputId);
    const icon = document.getElementById(iconId);
    
    if (input && icon) {
        if (input.type === 'password') {
            input.type = 'text';
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
        } else {
            input.type = 'password';
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
        }
    }
}

// ==================== PASSWORD STRENGTH CHECKER ====================
function checkPasswordStrength() {
    const password = document.getElementById('new_password').value;
    const strengthBar = document.getElementById('passwordStrengthBar');
    const strengthText = document.getElementById('passwordStrengthText');
    
    // Check requirements
    const hasLength = password.length >= 8;
    const hasUpper = /[A-Z]/.test(password);
    const hasLower = /[a-z]/.test(password);
    const hasNumber = /[0-9]/.test(password);
    const hasSpecial = /[!@#$%^&*(),.?":{}|<>]/.test(password);
    
    // Update requirement indicators
    updateRequirement('req-length', hasLength);
    updateRequirement('req-uppercase', hasUpper);
    updateRequirement('req-lowercase', hasLower);
    updateRequirement('req-number', hasNumber);
    updateRequirement('req-special', hasSpecial);
    
    // Calculate strength
    let strength = 0;
    if (hasLength) strength++;
    if (hasUpper) strength++;
    if (hasLower) strength++;
    if (hasNumber) strength++;
    if (hasSpecial) strength++;
    
    // Update strength bar
    strengthBar.className = 'password-strength-bar';
    if (password.length === 0) {
        strengthBar.style.width = '0%';
        strengthText.textContent = 'Enter a password to check strength';
    } else if (strength <= 2) {
        strengthBar.classList.add('strength-weak');
        strengthText.textContent = 'Weak password - add more variety';
    } else if (strength === 3) {
        strengthBar.classList.add('strength-fair');
        strengthText.textContent = 'Fair password - could be stronger';
    } else if (strength === 4) {
        strengthBar.classList.add('strength-good');
        strengthText.textContent = 'Good password - almost there!';
    } else {
        strengthBar.classList.add('strength-strong');
        strengthText.textContent = 'Strong password! ✓';
    }
    
    // Check password match
    checkPasswordMatch();
}

function updateRequirement(elementId, isValid) {
    const element = document.getElementById(elementId);
    if (element) {
        const icon = element.querySelector('i');
        if (isValid) {
            element.classList.add('valid');
            icon.classList.remove('fa-circle');
            icon.classList.add('fa-check-circle');
        } else {
            element.classList.remove('valid');
            icon.classList.remove('fa-check-circle');
            icon.classList.add('fa-circle');
        }
    }
}

// ==================== PASSWORD MATCH CHECKER ====================
function checkPasswordMatch() {
    const newPassword = document.getElementById('new_password').value;
    const confirmPassword = document.getElementById('confirm_password').value;
    const messageEl = document.getElementById('passwordMatchMessage');
    
    if (confirmPassword.length === 0) {
        messageEl.textContent = '';
        messageEl.className = 'text-xs mt-1';
    } else if (newPassword === confirmPassword) {
        messageEl.textContent = '✓ Passwords match';
        messageEl.className = 'text-xs mt-1 text-green-600 dark:text-green-400';
    } else {
        messageEl.textContent = '✗ Passwords do not match';
        messageEl.className = 'text-xs mt-1 text-red-600 dark:text-red-400';
    }
}

// ==================== FORM SUBMISSION ====================
const passwordForm = document.getElementById('passwordForm');
const submitBtn = document.getElementById('submitBtn');

passwordForm?.addEventListener('submit', function(e) {
    const newPassword = document.getElementById('new_password').value;
    const confirmPassword = document.getElementById('confirm_password').value;
    const currentPassword = document.getElementById('current_password').value;
    
    // Prevent double submission
    if (submitBtn.disabled) {
        e.preventDefault();
        return;
    }
    
    // Final validation
    if (newPassword !== confirmPassword) {
        e.preventDefault();
        alert('New password and confirmation do not match!');
        return;
    }
    
    if (newPassword === currentPassword) {
        e.preventDefault();
        alert('New password cannot be the same as your current password!');
        return;
    }
    
    // Check password strength
    const hasLength = newPassword.length >= 8;
    const hasUpper = /[A-Z]/.test(newPassword);
    const hasLower = /[a-z]/.test(newPassword);
    const hasNumber = /[0-9]/.test(newPassword);
    const hasSpecial = /[!@#$%^&*(),.?":{}|<>]/.test(newPassword);
    
    if (!hasLength || !hasUpper || !hasLower || !hasNumber || !hasSpecial) {
        e.preventDefault();
        alert('Please ensure your new password meets all requirements!');
        return;
    }
    
    // Disable button and show loading state
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i><span>Updating...</span>';
    submitBtn.disabled = true;
    
    // Re-enable after timeout (in case of network issues)
    setTimeout(() => {
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    }, 5000);
});

// ==================== KEYBOARD SHORTCUTS ====================
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        closeMobileSidebar();
    }
    
    // Ctrl+Enter to submit form
    if (e.ctrlKey && e.key === 'Enter' && passwordForm) {
        passwordForm.dispatchEvent(new Event('submit', { cancelable: true }));
    }
});

// ==================== AUTO-HIDE FLASH MESSAGES ====================
setTimeout(() => {
    document.querySelectorAll('.fixed.top-4.right-4').forEach(el => {
        el.style.transition = 'opacity 0.5s';
        el.style.opacity = '0';
        setTimeout(() => el?.remove(), 500);
    });
}, 5000);

// ==================== INITIALIZE ====================
document.addEventListener('DOMContentLoaded', function() {
    // Trigger initial password strength check if there's a value
    if (document.getElementById('new_password').value) {
        checkPasswordStrength();
    }
    if (document.getElementById('confirm_password').value) {
        checkPasswordMatch();
    }
});
</script>

</body>
</html>