<?php
/**
 * login.php - TransNow Admin Portal Login
 * Version: 7.0 - Professional Blue UI, Admin Only
 */

// Error reporting for development
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Start session with proper configuration
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 86400,
        'path' => '/',
        'domain' => '',
        'secure' => false,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

// Redirect if already logged in as admin
if (isset($_SESSION['user_id']) && isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'admin') {
    header('Location: admin/dashboard.php');
    exit();
}

// Include configuration files
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/functions.php';

// Get database connection
$pdo = getDB();

// Initialize variables
$error = '';
$success = '';
$email = '';

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']);
    
    if (empty($email) || empty($password)) {
        $error = 'Please enter both email and password';
    } else {
        // Only query admin users
        $stmt = $pdo->prepare("
            SELECT id, email, password_hash, user_type, is_verified, last_login 
            FROM users 
            WHERE email = ? AND user_type = 'admin'
        ");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            // Verify password
            if (password_verify($password, $user['password_hash'])) {
                // Check if verified
                if (!$user['is_verified']) {
                    $error = 'Please verify your email address first.';
                } else {
                    // Set session variables
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['user_email'] = $user['email'];
                    $_SESSION['user_type'] = $user['user_type'];
                    $_SESSION['user_name'] = 'Administrator';
                    
                    // Update last login
                    $updateStmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                    $updateStmt->execute([$user['id']]);
                    
                    // Set remember me cookie if requested
                    if ($remember) {
                        $token = bin2hex(random_bytes(32));
                        $expires = date('Y-m-d H:i:s', strtotime('+30 days'));
                        
                        setcookie('remember_token', $token, time() + (86400 * 30), '/', '', true, true);
                        setcookie('remember_email', $email, time() + (86400 * 30), '/', '', true, true);
                    }
                    
                    // Redirect to admin dashboard
                    header('Location: admin/dashboard.php');
                    exit();
                }
            } else {
                $error = 'Invalid email or password';
            }
        } else {
            $error = 'No admin account found with these credentials';
        }
    }
}

// Check for remember me cookie
if (!isset($_SESSION['user_id']) && isset($_COOKIE['remember_token']) && isset($_COOKIE['remember_email'])) {
    $token = $_COOKIE['remember_token'];
    $email = $_COOKIE['remember_email'];
    
    $stmt = $pdo->prepare("
        SELECT id, email, user_type, is_verified 
        FROM users 
        WHERE email = ? AND user_type = 'admin'
    ");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user && $user['is_verified']) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_type'] = $user['user_type'];
        $_SESSION['user_name'] = 'Administrator';
        
        header('Location: admin/dashboard.php');
        exit();
    }
}

// Get some stats for display
$stats = [
    'total_jobs' => 0,
    'total_companies' => 0,
    'total_testimonials' => 0
];

try {
    $stats['total_jobs'] = $pdo->query("SELECT COUNT(*) FROM jobs WHERE status = 'Active'")->fetchColumn();
    $stats['total_companies'] = $pdo->query("SELECT COUNT(DISTINCT company_name) FROM jobs WHERE status = 'Active' AND company_name IS NOT NULL")->fetchColumn();
    $stats['total_testimonials'] = $pdo->query("SELECT COUNT(*) FROM testimonials WHERE is_active = 1")->fetchColumn();
} catch (PDOException $e) {
    // Silently fail stats
}
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TransNow Ghana - Admin Portal Login</title>
    <meta name="description" content="Secure admin portal login for TransNow Ghana job platform management.">
    
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Ccircle cx='50' cy='50' r='45' fill='%231E3A8A'/%3E%3Cpath d='M30 40 L70 40 L65 70 L35 70 Z' fill='white'/%3E%3C/svg%3E">
    
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
        
        /* Professional Blue Gradient */
        .brand-gradient {
            background: linear-gradient(135deg, #1E3A8A 0%, #2563EB 50%, #3B82F6 100%);
        }
        
        .gradient-text {
            background: linear-gradient(135deg, #1E3A8A, #2563EB, #3B82F6);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }
        
        .glass-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }
        
        .dark .glass-card {
            background: rgba(17, 24, 39, 0.95);
            border: 1px solid rgba(255, 255, 255, 0.05);
        }
        
        /* Animations */
        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-15px); }
        }
        
        @keyframes floatSlow {
            0%, 100% { transform: translateY(0px); }
            33% { transform: translateY(-20px); }
            66% { transform: translateY(10px); }
        }
        
        @keyframes pulse-blue {
            0%, 100% { opacity: 0.4; transform: scale(1); }
            50% { opacity: 0.7; transform: scale(1.05); }
        }
        
        @keyframes shimmer {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }
        
        .animate-float { animation: float 6s ease-in-out infinite; }
        .animate-float-slow { animation: floatSlow 12s ease-in-out infinite; }
        .animate-pulse-blue { animation: pulse-blue 4s ease-in-out infinite; }
        
        /* Form Input Styles */
        .form-input {
            transition: all 0.3s ease;
            background: #F9FAFB;
        }
        
        .dark .form-input {
            background: #1F2937;
        }
        
        .form-input:focus {
            transform: translateY(-2px);
            border-color: #2563EB;
            box-shadow: 0 10px 20px -5px rgba(37, 99, 235, 0.2);
        }
        
        /* Shine Effect */
        .shine {
            position: relative;
            overflow: hidden;
        }
        
        .shine::after {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 50%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transform: skewX(-25deg);
            animation: shimmer 3s infinite;
        }
        
        /* Floating Labels */
        .floating-label-group {
            position: relative;
        }
        
        .floating-label {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            background: transparent;
            padding: 0 0.25rem;
            color: #9CA3AF;
            transition: all 0.2s ease-out;
            pointer-events: none;
            font-size: 0.95rem;
        }
        
        .floating-label.active {
            top: 0;
            transform: translateY(-50%) scale(0.85);
            background: linear-gradient(135deg, #1E3A8A, #2563EB);
            color: white;
            padding: 0.125rem 0.75rem;
            border-radius: 1rem;
            left: 0.75rem;
        }
        
        /* Custom Scrollbar */
        ::-webkit-scrollbar { width: 10px; height: 10px; }
        ::-webkit-scrollbar-track { background: #F3F4F6; border-radius: 8px; }
        ::-webkit-scrollbar-thumb { background: linear-gradient(135deg, #1E3A8A, #3B82F6); border-radius: 8px; }
        
        .dark ::-webkit-scrollbar-track { background: #1F2937; }
        
        /* Button styles */
        .btn-primary {
            background: linear-gradient(135deg, #1E3A8A 0%, #2563EB 100%);
            color: white;
            font-weight: 600;
            padding: 0.875rem 1.5rem;
            border-radius: 0.75rem;
            transition: all 0.3s;
            box-shadow: 0 4px 6px -1px rgba(30, 58, 138, 0.2);
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(30, 58, 138, 0.3);
            background: linear-gradient(135deg, #1E3A8A 0%, #1D4ED8 100%);
        }
        
        /* Stat card */
        .stat-card {
            background: rgba(255, 255, 255, 0.8);
            backdrop-filter: blur(8px);
            border: 1px solid rgba(37, 99, 235, 0.1);
        }
        
        .dark .stat-card {
            background: rgba(31, 41, 55, 0.8);
            border: 1px solid rgba(37, 99, 235, 0.2);
        }
    </style>
</head>
<body class="bg-[#F3F4F6] dark:bg-gray-900 min-h-screen flex flex-col overflow-x-hidden">

<!-- Dark Mode Toggle -->
<button id="darkModeToggle" 
        class="fixed top-6 right-6 z-50 w-12 h-12 bg-white dark:bg-gray-800 rounded-full shadow-lg flex items-center justify-center hover:scale-110 transition-all duration-300 border border-gray-200 dark:border-gray-700">
    <i class="fas fa-moon text-gray-600 dark:text-gray-300 text-xl" id="darkModeIcon"></i>
</button>

<!-- Main Container -->
<div class="flex-grow flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8 relative overflow-hidden">
    <!-- Animated Background -->
    <div class="absolute inset-0 brand-gradient opacity-5 dark:opacity-10"></div>
    
    <!-- Floating Elements -->
    <div class="absolute inset-0 pointer-events-none">
        <div class="absolute top-20 left-10 w-96 h-96 bg-[#2563EB]/10 rounded-full blur-3xl animate-float"></div>
        <div class="absolute bottom-20 right-10 w-[500px] h-[500px] bg-[#1E3A8A]/10 rounded-full blur-3xl animate-float-slow"></div>
        <div class="absolute top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2 w-[600px] h-[600px] bg-[#3B82F6]/5 rounded-full blur-3xl animate-pulse-blue"></div>
    </div>
    
    <!-- Floating Icons -->
    <div class="absolute inset-0 pointer-events-none">
        <i class="fas fa-shield-alt absolute top-20 left-[15%] text-5xl text-[#1E3A8A]/10 animate-float"></i>
        <i class="fas fa-lock absolute bottom-32 right-[20%] text-4xl text-[#2563EB]/10 animate-float-slow"></i>
        <i class="fas fa-gear absolute top-1/3 right-[10%] text-6xl text-[#3B82F6]/10 animate-float"></i>
    </div>
    
    <div class="max-w-5xl w-full relative z-10">
        <div class="grid lg:grid-cols-5 gap-8 items-center">
            <!-- Left Column - Brand & Info -->
            <div class="lg:col-span-2 text-center lg:text-left" data-aos="fade-right" data-aos-duration="1000">
                <!-- Logo -->
                <a href="/" class="inline-flex items-center space-x-3 group mb-8">
                    <div class="w-14 h-14 brand-gradient rounded-xl flex items-center justify-center transform group-hover:rotate-12 transition-all duration-300 shadow-lg">
                        <i class="fas fa-briefcase text-white text-2xl"></i>
                    </div>
                    <span class="text-3xl font-bold gradient-text">TransNow Ghana</span>
                </a>
                
                <h1 class="text-4xl md:text-5xl font-extrabold mb-4 leading-tight">
                    <span class="text-[#111827] dark:text-white">Admin</span>
                    <span class="gradient-text block">Portal</span>
                </h1>
                <p class="text-lg text-[#4B5563] dark:text-gray-400 mb-8">
                    Secure access for TransNow Ghana administrators. Manage jobs, users, and platform settings.
                </p>
                
                <!-- Stats -->
                <div class="grid grid-cols-3 gap-3 mb-8">
                    <div class="stat-card rounded-xl p-4 text-center">
                        <i class="fas fa-briefcase text-2xl text-[#2563EB] mb-2"></i>
                        <div class="text-2xl font-bold text-[#111827] dark:text-white"><?php echo number_format($stats['total_jobs']); ?></div>
                        <div class="text-xs text-[#6B7280]">Active Jobs</div>
                    </div>
                    <div class="stat-card rounded-xl p-4 text-center">
                        <i class="fas fa-building text-2xl text-[#2563EB] mb-2"></i>
                        <div class="text-2xl font-bold text-[#111827] dark:text-white"><?php echo number_format($stats['total_companies']); ?></div>
                        <div class="text-xs text-[#6B7280]">Companies</div>
                    </div>
                    <div class="stat-card rounded-xl p-4 text-center">
                        <i class="fas fa-star text-2xl text-[#2563EB] mb-2"></i>
                        <div class="text-2xl font-bold text-[#111827] dark:text-white"><?php echo number_format($stats['total_testimonials']); ?></div>
                        <div class="text-xs text-[#6B7280]">Testimonials</div>
                    </div>
                </div>
                
                <!-- Security Badge -->
                <div class="flex items-center justify-center lg:justify-start space-x-3 text-[#6B7280] dark:text-gray-400">
                    <i class="fas fa-shield-alt text-[#10B981]"></i>
                    <span class="text-sm">256-bit SSL Encrypted Connection</span>
                </div>
            </div>
            
            <!-- Right Column - Login Form -->
            <div class="lg:col-span-3" data-aos="fade-left" data-aos-duration="1000">
                <div class="glass-card rounded-3xl shadow-2xl p-8 md:p-10">
                    <!-- Admin Badge -->
                    <div class="flex items-center justify-center mb-6">
                        <div class="brand-gradient text-white px-5 py-2 rounded-full text-sm font-semibold inline-flex items-center shadow-md">
                            <i class="fas fa-user-shield mr-2"></i>
                            ADMINISTRATOR ACCESS ONLY
                        </div>
                    </div>
                    
                    <h2 class="text-2xl font-bold text-center mb-2 text-[#111827] dark:text-white">
                        <i class="fas fa-lock text-[#2563EB] mr-2"></i>Secure Login
                    </h2>
                    <p class="text-center text-[#6B7280] dark:text-gray-400 mb-8">Enter your admin credentials to continue</p>
                    
                    <!-- Error Message -->
                    <?php if ($error): ?>
                    <div class="mb-6 bg-red-50 dark:bg-red-900/20 border-l-4 border-[#EF4444] p-4 rounded-lg" role="alert">
                        <div class="flex items-center">
                            <i class="fas fa-exclamation-circle text-[#EF4444] mr-3 text-xl"></i>
                            <p class="text-red-700 dark:text-red-300"><?php echo $error; ?></p>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($success): ?>
                    <div class="mb-6 bg-green-50 dark:bg-green-900/20 border-l-4 border-[#10B981] p-4 rounded-lg" role="alert">
                        <div class="flex items-center">
                            <i class="fas fa-check-circle text-[#10B981] mr-3 text-xl"></i>
                            <p class="text-green-700 dark:text-green-300"><?php echo $success; ?></p>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <form method="POST" action="" id="loginForm" class="space-y-6">
                        <!-- Email Field -->
                        <div class="floating-label-group">
                            <input type="email" 
                                   name="email" 
                                   id="email"
                                   value="<?php echo htmlspecialchars($email); ?>"
                                   class="form-input w-full px-5 py-4 border-2 border-gray-200 dark:border-gray-700 rounded-xl focus:border-[#2563EB] focus:ring-2 focus:ring-[#2563EB]/20 outline-none transition-all text-[#111827] dark:text-white peer"
                                   placeholder=" "
                                   required>
                            <label for="email" class="floating-label">
                                <i class="fas fa-envelope mr-2 text-[#2563EB]"></i>Admin Email Address
                            </label>
                            <i class="fas fa-check-circle absolute right-4 top-1/2 transform -translate-y-1/2 text-[#10B981] opacity-0 transition-opacity" id="emailValidIcon"></i>
                        </div>
                        
                        <!-- Password Field -->
                        <div class="floating-label-group">
                            <input type="password" 
                                   name="password" 
                                   id="password"
                                   class="form-input w-full px-5 py-4 border-2 border-gray-200 dark:border-gray-700 rounded-xl focus:border-[#2563EB] focus:ring-2 focus:ring-[#2563EB]/20 outline-none transition-all text-[#111827] dark:text-white peer"
                                   placeholder=" "
                                   required>
                            <label for="password" class="floating-label">
                                <i class="fas fa-key mr-2 text-[#2563EB]"></i>Password
                            </label>
                            <button type="button" 
                                    onclick="togglePasswordVisibility()"
                                    class="absolute right-4 top-1/2 transform -translate-y-1/2 text-gray-400 hover:text-[#2563EB] transition">
                                <i class="fas fa-eye" id="togglePasswordIcon"></i>
                            </button>
                        </div>
                        
                        <!-- Remember Me & Forgot Password -->
                        <div class="flex items-center justify-between">
                            <label class="flex items-center space-x-3 cursor-pointer group">
                                <input type="checkbox" 
                                       name="remember" 
                                       id="remember"
                                       class="w-5 h-5 text-[#2563EB] bg-gray-100 border-gray-300 rounded focus:ring-[#2563EB] focus:ring-2">
                                <span class="text-[#4B5563] dark:text-gray-300 group-hover:text-[#2563EB] transition">Remember me</span>
                            </label>
                            <a href="forgot-password.php" 
                               class="text-[#2563EB] hover:text-[#1E3A8A] font-medium hover:underline transition flex items-center text-sm">
                                <i class="fas fa-question-circle mr-1"></i>
                                Forgot Password?
                            </a>
                        </div>
                        
                        <!-- Submit Button -->
                        <button type="submit" 
                                class="btn-primary w-full text-base md:text-lg relative overflow-hidden group shine">
                            <span class="relative z-10 flex items-center justify-center">
                                <i class="fas fa-sign-in-alt mr-3 group-hover:translate-x-[-4px] transition-transform"></i>
                                Access Admin Dashboard
                                <i class="fas fa-arrow-right ml-3 group-hover:translate-x-2 transition-transform"></i>
                            </span>
                        </button>
                        
                        <!-- Back to Site Link -->
                        <div class="text-center pt-4">
                            <a href="/" class="text-[#6B7280] dark:text-gray-400 hover:text-[#2563EB] transition inline-flex items-center">
                                <i class="fas fa-arrow-left mr-2"></i>
                                Return to TransNow Ghana Homepage
                            </a>
                        </div>
                    </form>
                </div>
                
                <!-- Trust Badges -->
                <div class="mt-6 flex items-center justify-center space-x-6 text-[#9CA3AF] text-xs">
                    <i class="fas fa-shield-alt"></i>
                    <span>Secure Login</span>
                    <span class="w-1 h-1 bg-[#D1D5DB] rounded-full"></span>
                    <i class="fas fa-lock"></i>
                    <span>Encrypted</span>
                    <span class="w-1 h-1 bg-[#D1D5DB] rounded-full"></span>
                    <i class="fas fa-user-shield"></i>
                    <span>Authorized Personnel Only</span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Simplified Footer -->
<footer class="bg-white dark:bg-gray-800 border-t border-gray-200 dark:border-gray-700 py-4 relative z-10">
    <div class="container mx-auto px-4">
        <div class="flex flex-col md:flex-row justify-between items-center text-sm text-[#6B7280] dark:text-gray-400">
            <p>&copy; <?php echo date('Y'); ?> TransNow Ghana. All rights reserved.</p>
            <div class="flex space-x-6 mt-2 md:mt-0">
                <a href="/privacy.php" class="hover:text-[#2563EB] transition">Privacy Policy</a>
                <a href="/terms.php" class="hover:text-[#2563EB] transition">Terms of Service</a>
                <a href="/contact.php" class="hover:text-[#2563EB] transition">Contact Support</a>
            </div>
        </div>
    </div>
</footer>

<!-- Scripts -->
<script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>

<script>
// Initialize AOS
AOS.init({
    duration: 1000,
    once: true,
    offset: 50
});

// Dark Mode Toggle
const darkModeToggle = document.getElementById('darkModeToggle');
const darkModeIcon = document.getElementById('darkModeIcon');

function initDarkMode() {
    if (localStorage.theme === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
        document.documentElement.classList.add('dark');
        if (darkModeIcon) {
            darkModeIcon.classList.remove('fa-moon');
            darkModeIcon.classList.add('fa-sun');
        }
    } else {
        document.documentElement.classList.remove('dark');
        if (darkModeIcon) {
            darkModeIcon.classList.remove('fa-sun');
            darkModeIcon.classList.add('fa-moon');
        }
    }
}
initDarkMode();

if (darkModeToggle) {
    darkModeToggle.addEventListener('click', () => {
        document.documentElement.classList.toggle('dark');
        
        if (document.documentElement.classList.contains('dark')) {
            localStorage.theme = 'dark';
            if (darkModeIcon) {
                darkModeIcon.classList.remove('fa-moon');
                darkModeIcon.classList.add('fa-sun');
            }
        } else {
            localStorage.theme = 'light';
            if (darkModeIcon) {
                darkModeIcon.classList.remove('fa-sun');
                darkModeIcon.classList.add('fa-moon');
            }
        }
    });
}

// Floating Labels
document.querySelectorAll('.floating-label-group input').forEach(input => {
    const label = input.nextElementSibling;
    
    if (input.value) {
        label?.classList.add('active');
    }
    
    input.addEventListener('focus', () => {
        label?.classList.add('active');
    });
    
    input.addEventListener('blur', () => {
        if (!input.value) {
            label?.classList.remove('active');
        }
    });
});

// Email Validation
const emailInput = document.getElementById('email');
const emailValidIcon = document.getElementById('emailValidIcon');

if (emailInput && emailValidIcon) {
    emailInput.addEventListener('input', function() {
        const email = this.value;
        const isValid = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
        
        if (isValid && email.length > 0) {
            this.classList.add('border-[#10B981]', 'dark:border-[#10B981]');
            this.classList.remove('border-[#EF4444]', 'dark:border-[#EF4444]');
            emailValidIcon.classList.remove('opacity-0');
            emailValidIcon.classList.add('opacity-100');
        } else {
            this.classList.remove('border-[#10B981]', 'dark:border-[#10B981]');
            if (email.length > 0) {
                this.classList.add('border-[#EF4444]', 'dark:border-[#EF4444]');
            } else {
                this.classList.remove('border-[#EF4444]', 'dark:border-[#EF4444]');
            }
            emailValidIcon.classList.add('opacity-0');
            emailValidIcon.classList.remove('opacity-100');
        }
    });
}

// Password Visibility Toggle
function togglePasswordVisibility() {
    const passwordInput = document.getElementById('password');
    const toggleIcon = document.getElementById('togglePasswordIcon');
    
    if (passwordInput && toggleIcon) {
        if (passwordInput.type === 'password') {
            passwordInput.type = 'text';
            toggleIcon.classList.remove('fa-eye');
            toggleIcon.classList.add('fa-eye-slash');
        } else {
            passwordInput.type = 'password';
            toggleIcon.classList.remove('fa-eye-slash');
            toggleIcon.classList.add('fa-eye');
        }
    }
}

// Form Validation
const loginForm = document.getElementById('loginForm');
if (loginForm) {
    loginForm.addEventListener('submit', function(e) {
        const email = document.getElementById('email')?.value.trim();
        const password = document.getElementById('password')?.value;
        
        if (!email || !password) {
            e.preventDefault();
            alert('Please fill in all fields');
            return;
        }
        
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            e.preventDefault();
            alert('Please enter a valid email address');
            return;
        }
    });
}

// Keyboard Shortcuts
document.addEventListener('keydown', function(e) {
    // Ctrl+Enter to submit form
    if (e.ctrlKey && e.key === 'Enter' && loginForm) {
        loginForm.dispatchEvent(new Event('submit'));
    }
    
    // Escape to clear form
    if (e.key === 'Escape') {
        const emailField = document.getElementById('email');
        const passwordField = document.getElementById('password');
        if (emailField) emailField.value = '';
        if (passwordField) passwordField.value = '';
        if (emailField) emailField.dispatchEvent(new Event('input'));
    }
});

// Check for saved email in cookie
document.addEventListener('DOMContentLoaded', function() {
    const cookies = document.cookie.split(';');
    cookies.forEach(cookie => {
        const [name, value] = cookie.trim().split('=');
        if (name === 'remember_email') {
            const emailField = document.getElementById('email');
            const rememberCheck = document.getElementById('remember');
            if (emailField) {
                emailField.value = decodeURIComponent(value);
                emailField.dispatchEvent(new Event('input'));
            }
            if (rememberCheck) rememberCheck.checked = true;
        }
    });
});

// Prevent double submission
let isSubmitting = false;
if (loginForm) {
    loginForm.addEventListener('submit', function(e) {
        if (isSubmitting) {
            e.preventDefault();
            return;
        }
        isSubmitting = true;
        
        const submitBtn = this.querySelector('button[type="submit"]');
        if (submitBtn) {
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Authenticating...';
            submitBtn.disabled = true;
            
            setTimeout(() => {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
                isSubmitting = false;
            }, 10000);
        }
    });
}
</script>
</body>
</html>