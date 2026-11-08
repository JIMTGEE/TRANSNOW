<?php
/**
 * admin/settings.php - System Settings Dashboard
 * Version: 2.0 - Updated with password change integration and better UX
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
    $_SESSION['error'] = 'Database connection failed';
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

// ===== SETTINGS HANDLING =====

// Initialize settings array with defaults
$defaultSettings = [
    // General Settings
    'site_name' => 'TransNow Ghana',
    'site_tagline' => 'Connect Global Talents with Premier Opportunities',
    'site_description' => 'TransNow connects job seekers with premier opportunities across Ghana.',
    'site_keywords' => 'jobs ghana, careers, employment, translation jobs, sales jobs',
    'admin_email' => 'admin@transnow.com',
    'support_email' => 'support@transnow.com',
    'contact_email' => 'contact@transnow.com',
    'contact_phone' => '',
    'contact_address' => '',
    'timezone' => 'Africa/Accra',
    'date_format' => 'Y-m-d',
    'time_format' => 'H:i:s',
    
    // Job Settings
    'jobs_per_page' => 20,
    'featured_jobs_limit' => 8,
    'recent_jobs_limit' => 10,
    'job_expiry_days' => 30,
    'urgent_jobs_limit' => 4,
    'allow_job_editing' => 1,
    'job_approval_required' => 0,
    'max_job_postings_per_employer' => 50,
    'default_salary_currency' => 'GHS',
    
    // User Settings
    'allow_registration' => 1,
    'require_email_verification' => 1,
    'session_timeout_minutes' => 120,
    'max_login_attempts' => 5,
    'lockout_duration_minutes' => 30,
    'password_min_length' => 8,
    'password_require_special' => 1,
    'password_require_number' => 1,
    'password_require_uppercase' => 1,
    
    // Email Settings
    'email_from_name' => 'TransNow Ghana',
    'email_from_address' => 'noreply@transnow.com',
    'email_smtp_host' => '',
    'email_smtp_port' => 587,
    'email_smtp_encryption' => 'tls',
    'email_smtp_username' => '',
    'email_smtp_password' => '',
    
    // Notification Settings
    'notifications_enabled' => 1,
    'job_match_notifications' => 1,
    'application_update_notifications' => 1,
    'admin_notifications' => 1,
    
    // SEO & Analytics
    'google_analytics_id' => '',
    'facebook_pixel_id' => '',
    'enable_sitemap' => 1,
    
    // Security Settings
    'force_https' => 1,
    'csrf_protection' => 1,
    'rate_limiting_enabled' => 1,
    'recaptcha_enabled' => 0,
    'recaptcha_site_key' => '',
    'recaptcha_secret_key' => '',
    
    // Maintenance Mode
    'maintenance_mode' => 0,
    'maintenance_message' => 'We are currently performing scheduled maintenance. Please check back soon.',
    'maintenance_allowed_ips' => '',
    
    // Social Media
    'facebook_url' => '',
    'twitter_url' => '',
    'linkedin_url' => '',
    'instagram_url' => '',
    'whatsapp_number' => '+86 191 5768 8742',
    'wechat_id' => 'TransNow Ghana',
    
    // File Upload Settings
    'max_file_size' => 10,
    'allowed_file_types' => 'jpg,jpeg,png,gif,pdf,doc,docx',
    
    // Cache Settings
    'cache_enabled' => 1,
    'cache_duration_minutes' => 60,
];

// Fetch current settings from database
$currentSettings = [];
try {
    $settingsFromDB = fetchAll("
        SELECT setting_key, setting_value, setting_type 
        FROM settings 
        ORDER BY category, setting_key
    ");
    
    foreach ($settingsFromDB as $setting) {
        $key = $setting['setting_key'];
        $value = $setting['setting_value'];
        $type = $setting['setting_type'];
        
        switch ($type) {
            case 'integer':
                $value = (int)$value;
                break;
            case 'boolean':
                $value = (bool)$value;
                break;
            case 'json':
            case 'array':
                $value = json_decode($value, true) ?: [];
                break;
        }
        
        $currentSettings[$key] = $value;
    }
} catch (Exception $e) {
    error_log("Settings fetch error: " . $e->getMessage());
}

// Merge with defaults
$settings = array_merge($defaultSettings, $currentSettings);

// Handle form submission
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $category = $_POST['category'] ?? 'general';
    
    // Define fields for each category
    $fields = [];
    switch ($category) {
        case 'general':
            $fields = ['site_name', 'site_tagline', 'site_description', 'site_keywords',
                      'admin_email', 'support_email', 'contact_email', 'contact_phone',
                      'contact_address', 'timezone', 'date_format', 'time_format'];
            break;
        case 'jobs':
            $fields = ['jobs_per_page', 'featured_jobs_limit', 'recent_jobs_limit',
                      'job_expiry_days', 'urgent_jobs_limit', 'allow_job_editing',
                      'job_approval_required', 'max_job_postings_per_employer',
                      'default_salary_currency'];
            break;
        case 'users':
            $fields = ['allow_registration', 'require_email_verification',
                      'session_timeout_minutes', 'max_login_attempts',
                      'lockout_duration_minutes', 'password_min_length',
                      'password_require_special', 'password_require_number',
                      'password_require_uppercase'];
            break;
        case 'email':
            $fields = ['email_from_name', 'email_from_address', 'email_smtp_host',
                      'email_smtp_port', 'email_smtp_encryption', 'email_smtp_username',
                      'email_smtp_password'];
            break;
        case 'notifications':
            $fields = ['notifications_enabled', 'job_match_notifications',
                      'application_update_notifications', 'admin_notifications'];
            break;
        case 'seo':
            $fields = ['google_analytics_id', 'facebook_pixel_id', 'enable_sitemap'];
            break;
        case 'security':
            $fields = ['force_https', 'csrf_protection', 'rate_limiting_enabled',
                      'recaptcha_enabled', 'recaptcha_site_key', 'recaptcha_secret_key'];
            break;
        case 'maintenance':
            $fields = ['maintenance_mode', 'maintenance_message', 'maintenance_allowed_ips'];
            break;
        case 'social':
            $fields = ['facebook_url', 'twitter_url', 'linkedin_url', 'instagram_url',
                      'whatsapp_number', 'wechat_id'];
            break;
    }
    
    try {
        $pdo->beginTransaction();
        
        foreach ($fields as $field) {
            $value = $_POST[$field] ?? null;
            
            // Handle checkboxes
            $checkboxFields = ['allow_job_editing', 'job_approval_required',
                'allow_registration', 'require_email_verification',
                'notifications_enabled', 'job_match_notifications',
                'application_update_notifications', 'admin_notifications',
                'enable_sitemap', 'force_https', 'csrf_protection',
                'rate_limiting_enabled', 'recaptcha_enabled', 'maintenance_mode',
                'password_require_special', 'password_require_number', 'password_require_uppercase'];
            
            if (in_array($field, $checkboxFields)) {
                $value = isset($_POST[$field]) ? 1 : 0;
            }
            
            if ($value !== null) {
                // Determine type
                $type = 'string';
                if (is_numeric($value) && !is_string($value)) {
                    $type = 'integer';
                } elseif (in_array($field, $checkboxFields)) {
                    $type = 'boolean';
                }
                
                // Check if setting exists
                $exists = fetchOne("SELECT COUNT(*) as count FROM settings WHERE setting_key = ?", [$field]);
                
                if ($exists && $exists['count'] > 0) {
                    executeQuery(
                        "UPDATE settings SET setting_value = ?, setting_type = ?, updated_at = NOW() WHERE setting_key = ?",
                        [$value, $type, $field]
                    );
                } else {
                    executeQuery(
                        "INSERT INTO settings (setting_key, setting_value, setting_type, category, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())",
                        [$field, $value, $type, $category]
                    );
                }
            }
        }
        
        $pdo->commit();
        $message = 'Settings saved successfully!';
        $messageType = 'success';
        
        // Refresh settings
        header('Location: settings.php?tab=' . $category . '&saved=1');
        exit();
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $message = 'Error saving settings: ' . $e->getMessage();
        $messageType = 'error';
        error_log("Settings save error: " . $e->getMessage());
    }
}

// Check for saved parameter
if (isset($_GET['saved']) && $_GET['saved'] == 1) {
    $message = 'Settings saved successfully!';
    $messageType = 'success';
}

// Get active tab
$activeTab = $_GET['tab'] ?? 'general';

// Get avatar URL
$avatarUrl = 'https://ui-avatars.com/api/?name=' . urlencode('Admin') . '&background=2563eb&color=fff&size=128&bold=true';

// Get timezone list
$timezones = DateTimeZone::listIdentifiers();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - Admin Dashboard | TransNow Ghana</title>
    
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
        
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        .animate-slideIn { animation: slideIn 0.3s ease-out; }
        
        .brand-gradient {
            background: linear-gradient(135deg, #1E3A8A 0%, #2563EB 50%, #3B82F6 100%);
        }
        
        .hover-card {
            transition: all 0.3s ease;
        }
        .hover-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1);
        }
        
        .settings-tab {
            transition: all 0.3s ease;
            position: relative;
        }
        
        .settings-tab.active {
            background: linear-gradient(135deg, rgba(37, 99, 235, 0.1), rgba(59, 130, 246, 0.1));
            color: #2563eb;
            border-bottom: 2px solid #2563eb;
        }
        
        .dark .settings-tab.active {
            background: rgba(37, 99, 235, 0.2);
            color: #60a5fa;
            border-bottom-color: #60a5fa;
        }
        
        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 24px;
        }
        
        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #cbd5e0;
            transition: .4s;
            border-radius: 34px;
        }
        
        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 20px;
            width: 20px;
            left: 2px;
            bottom: 2px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        
        input:checked + .toggle-slider {
            background: linear-gradient(90deg, #2563eb, #3b82f6);
        }
        
        input:checked + .toggle-slider:before {
            transform: translateX(26px);
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
        
        .settings-nav-card {
            transition: all 0.2s ease;
            cursor: pointer;
        }
        
        .settings-nav-card:hover {
            background: linear-gradient(135deg, rgba(37, 99, 235, 0.05), rgba(59, 130, 246, 0.05));
            border-color: #2563eb;
        }
        
        .settings-nav-card.active {
            background: linear-gradient(135deg, rgba(37, 99, 235, 0.1), rgba(59, 130, 246, 0.1));
            border-color: #2563eb;
        }
    </style>
</head>
<body class="bg-gray-50 dark:bg-gray-900 text-gray-900 dark:text-white">

<!-- Flash Messages -->
<?php if ($message): ?>
<div id="flashMessage" class="fixed top-4 right-4 z-50 <?php echo $messageType === 'success' ? 'bg-green-500' : 'bg-red-500'; ?> text-white px-6 py-3 rounded-lg shadow-lg animate-slideIn flex items-center">
    <i class="fas <?php echo $messageType === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?> mr-2"></i>
    <span><?php echo htmlspecialchars($message); ?></span>
    <button onclick="this.parentElement.remove()" class="ml-4 hover:text-gray-200">
        <i class="fas fa-times"></i>
    </button>
</div>
<?php endif; ?>

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
            
            <div class="mt-6 p-4 bg-gray-50 dark:bg-gray-700 rounded-xl">
                <div class="flex items-center space-x-3">
                    <img src="<?php echo $avatarUrl; ?>" alt="Admin" class="w-12 h-12 rounded-lg border-2 border-blue-200">
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
           <a href="applications.php" class="flex items-center space-x-3 px-4 py-3 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                    <i class="fas fa-file-alt w-5"></i>
                    <span>Applications</span>
                </a>
            <a href="jobs.php" class="flex items-center space-x-3 px-4 py-3 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                <i class="fas fa-briefcase w-5"></i><span>Jobs</span>
            </a>
             <a href="categories.php" class="flex items-center space-x-3 px-4 py-3 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                    <i class="fas fa-tags w-5"></i>
                    <span>Categories</span>
                </a>
             <a href="reports.php" class="flex items-center space-x-3 px-4 py-3 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                    <i class="fas fa-chart-bar w-5"></i>
                    <span>Reports</span>
                </a>
            <a href="settings.php" class="flex items-center space-x-3 px-4 py-3 bg-blue-50 dark:bg-blue-900/20 text-blue-600 dark:text-blue-400 rounded-xl font-medium">
                <i class="fas fa-cog w-5"></i><span>Settings</span>
            </a>
            
            <div class="border-t border-gray-200 dark:border-gray-700 my-4"></div>
            
            <a href="change-password.php" class="flex items-center space-x-3 px-4 py-3 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
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
                    System <span class="text-blue-600">Settings</span>
                </h1>
                
                <div class="flex items-center space-x-3">
                    <a href="dashboard.php" class="px-4 py-2 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-600 transition flex items-center space-x-2">
                        <i class="fas fa-arrow-left"></i>
                        <span class="hidden sm:inline">Dashboard</span>
                    </a>
                </div>
            </div>
        </header>

        <!-- Content -->
        <div class="p-6">
            <div class="max-w-6xl mx-auto">
                
                <!-- Quick Actions Cards -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
                    <!-- Password Change Card -->
                    <a href="change-password.php" class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 hover-card border-2 border-transparent hover:border-blue-500 transition-all group">
                        <div class="flex items-center justify-between mb-4">
                            <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900/30 rounded-xl flex items-center justify-center group-hover:scale-110 transition-transform">
                                <i class="fas fa-key text-blue-600 text-xl"></i>
                            </div>
                            <i class="fas fa-arrow-right text-gray-400 group-hover:text-blue-600 group-hover:translate-x-1 transition-all"></i>
                        </div>
                        <h3 class="text-lg font-bold mb-1">Change Password</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Update your admin account password for security</p>
                    </a>
                    
                    <!-- Clear Cache Card -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 hover-card border-2 border-transparent hover:border-purple-500 transition-all group">
                        <div class="flex items-center justify-between mb-4">
                            <div class="w-12 h-12 bg-purple-100 dark:bg-purple-900/30 rounded-xl flex items-center justify-center group-hover:scale-110 transition-transform">
                                <i class="fas fa-database text-purple-600 text-xl"></i>
                            </div>
                            <i class="fas fa-arrow-right text-gray-400 group-hover:text-purple-600 group-hover:translate-x-1 transition-all"></i>
                        </div>
                        <h3 class="text-lg font-bold mb-1">Clear Cache</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Clear system cache to refresh data</p>
                    </div>
                    
                    <!-- Backup Card -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 hover-card border-2 border-transparent hover:border-green-500 transition-all group">
                        <div class="flex items-center justify-between mb-4">
                            <div class="w-12 h-12 bg-green-100 dark:bg-green-900/30 rounded-xl flex items-center justify-center group-hover:scale-110 transition-transform">
                                <i class="fas fa-download text-green-600 text-xl"></i>
                            </div>
                            <i class="fas fa-arrow-right text-gray-400 group-hover:text-green-600 group-hover:translate-x-1 transition-all"></i>
                        </div>
                        <h3 class="text-lg font-bold mb-1">Backup Database</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Export and download database backup</p>
                    </div>
                </div>
                
                <!-- Account Security Alert -->
                <div class="mb-6 p-4 bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-xl">
                    <div class="flex items-start">
                        <i class="fas fa-shield-alt text-yellow-600 text-xl mr-3 mt-0.5"></i>
                        <div class="flex-1">
                            <h4 class="font-semibold text-yellow-800 dark:text-yellow-300">Account Security</h4>
                            <p class="text-sm text-yellow-700 dark:text-yellow-400">
                                Last password change: 
                                <?php 
                                // You can fetch actual last password change from logs if implemented
                                echo 'Not tracked'; 
                                ?>.
                                <a href="change-password.php" class="font-medium underline ml-2">Change password now</a>
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Settings Tabs -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg overflow-hidden">
                    <div class="border-b border-gray-200 dark:border-gray-700">
                        <div class="flex flex-wrap">
                            <a href="?tab=general" class="settings-tab px-5 py-3 text-sm font-medium <?php echo $activeTab === 'general' ? 'active' : ''; ?>">
                                <i class="fas fa-globe mr-2"></i>General
                            </a>
                            <a href="?tab=jobs" class="settings-tab px-5 py-3 text-sm font-medium <?php echo $activeTab === 'jobs' ? 'active' : ''; ?>">
                                <i class="fas fa-briefcase mr-2"></i>Jobs
                            </a>
                            <a href="?tab=users" class="settings-tab px-5 py-3 text-sm font-medium <?php echo $activeTab === 'users' ? 'active' : ''; ?>">
                                <i class="fas fa-users mr-2"></i>Users
                            </a>
                            <a href="?tab=email" class="settings-tab px-5 py-3 text-sm font-medium <?php echo $activeTab === 'email' ? 'active' : ''; ?>">
                                <i class="fas fa-envelope mr-2"></i>Email
                            </a>
                            <a href="?tab=notifications" class="settings-tab px-5 py-3 text-sm font-medium <?php echo $activeTab === 'notifications' ? 'active' : ''; ?>">
                                <i class="fas fa-bell mr-2"></i>Notifications
                            </a>
                            <a href="?tab=seo" class="settings-tab px-5 py-3 text-sm font-medium <?php echo $activeTab === 'seo' ? 'active' : ''; ?>">
                                <i class="fas fa-chart-line mr-2"></i>SEO
                            </a>
                            <a href="?tab=security" class="settings-tab px-5 py-3 text-sm font-medium <?php echo $activeTab === 'security' ? 'active' : ''; ?>">
                                <i class="fas fa-shield-alt mr-2"></i>Security
                            </a>
                            <a href="?tab=maintenance" class="settings-tab px-5 py-3 text-sm font-medium <?php echo $activeTab === 'maintenance' ? 'active' : ''; ?>">
                                <i class="fas fa-tools mr-2"></i>Maintenance
                            </a>
                            <a href="?tab=social" class="settings-tab px-5 py-3 text-sm font-medium <?php echo $activeTab === 'social' ? 'active' : ''; ?>">
                                <i class="fas fa-share-alt mr-2"></i>Social
                            </a>
                        </div>
                    </div>
                    
                    <div class="p-6">
                        <form method="POST" action="?tab=<?php echo $activeTab; ?>" id="settingsForm">
                            <input type="hidden" name="category" value="<?php echo $activeTab; ?>">
                            
                            <!-- General Settings -->
                            <?php if ($activeTab === 'general'): ?>
                            <div class="space-y-6">
                                <h3 class="text-lg font-bold mb-4 flex items-center">
                                    <i class="fas fa-globe text-blue-500 mr-2"></i>General Settings
                                </h3>
                                
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label class="form-label">Site Name</label>
                                        <input type="text" name="site_name" class="form-input" value="<?php echo htmlspecialchars($settings['site_name']); ?>" required>
                                    </div>
                                    <div>
                                        <label class="form-label">Site Tagline</label>
                                        <input type="text" name="site_tagline" class="form-input" value="<?php echo htmlspecialchars($settings['site_tagline']); ?>">
                                    </div>
                                    <div class="md:col-span-2">
                                        <label class="form-label">Site Description</label>
                                        <textarea name="site_description" rows="2" class="form-input"><?php echo htmlspecialchars($settings['site_description']); ?></textarea>
                                    </div>
                                    <div class="md:col-span-2">
                                        <label class="form-label">Meta Keywords</label>
                                        <input type="text" name="site_keywords" class="form-input" value="<?php echo htmlspecialchars($settings['site_keywords']); ?>">
                                    </div>
                                    <div>
                                        <label class="form-label">Admin Email</label>
                                        <input type="email" name="admin_email" class="form-input" value="<?php echo htmlspecialchars($settings['admin_email']); ?>" required>
                                    </div>
                                    <div>
                                        <label class="form-label">Support Email</label>
                                        <input type="email" name="support_email" class="form-input" value="<?php echo htmlspecialchars($settings['support_email']); ?>">
                                    </div>
                                    <div>
                                        <label class="form-label">Contact Email</label>
                                        <input type="email" name="contact_email" class="form-input" value="<?php echo htmlspecialchars($settings['contact_email']); ?>">
                                    </div>
                                    <div>
                                        <label class="form-label">Contact Phone</label>
                                        <input type="text" name="contact_phone" class="form-input" value="<?php echo htmlspecialchars($settings['contact_phone']); ?>">
                                    </div>
                                    <div class="md:col-span-2">
                                        <label class="form-label">Contact Address</label>
                                        <textarea name="contact_address" rows="2" class="form-input"><?php echo htmlspecialchars($settings['contact_address']); ?></textarea>
                                    </div>
                                    <div>
                                        <label class="form-label">Timezone</label>
                                        <select name="timezone" class="form-input">
                                            <?php foreach ($timezones as $tz): ?>
                                            <option value="<?php echo $tz; ?>" <?php echo $settings['timezone'] == $tz ? 'selected' : ''; ?>><?php echo $tz; ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="form-label">Date Format</label>
                                        <input type="text" name="date_format" class="form-input" value="<?php echo htmlspecialchars($settings['date_format']); ?>">
                                    </div>
                                    <div>
                                        <label class="form-label">Time Format</label>
                                        <input type="text" name="time_format" class="form-input" value="<?php echo htmlspecialchars($settings['time_format']); ?>">
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Jobs Settings -->
                            <?php if ($activeTab === 'jobs'): ?>
                            <div class="space-y-6">
                                <h3 class="text-lg font-bold mb-4 flex items-center">
                                    <i class="fas fa-briefcase text-blue-500 mr-2"></i>Job Settings
                                </h3>
                                
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label class="form-label">Jobs Per Page</label>
                                        <input type="number" name="jobs_per_page" class="form-input" value="<?php echo $settings['jobs_per_page']; ?>" min="1" max="100">
                                    </div>
                                    <div>
                                        <label class="form-label">Featured Jobs Limit</label>
                                        <input type="number" name="featured_jobs_limit" class="form-input" value="<?php echo $settings['featured_jobs_limit']; ?>" min="1" max="50">
                                    </div>
                                    <div>
                                        <label class="form-label">Recent Jobs Limit</label>
                                        <input type="number" name="recent_jobs_limit" class="form-input" value="<?php echo $settings['recent_jobs_limit']; ?>" min="1" max="50">
                                    </div>
                                    <div>
                                        <label class="form-label">Job Expiry Days</label>
                                        <input type="number" name="job_expiry_days" class="form-input" value="<?php echo $settings['job_expiry_days']; ?>" min="1" max="365">
                                    </div>
                                    <div>
                                        <label class="form-label">Urgent Jobs Limit</label>
                                        <input type="number" name="urgent_jobs_limit" class="form-input" value="<?php echo $settings['urgent_jobs_limit']; ?>" min="1" max="20">
                                    </div>
                                    <div>
                                        <label class="form-label">Max Jobs Per Employer</label>
                                        <input type="number" name="max_job_postings_per_employer" class="form-input" value="<?php echo $settings['max_job_postings_per_employer']; ?>" min="0">
                                    </div>
                                    <div>
                                        <label class="form-label">Default Salary Currency</label>
                                        <select name="default_salary_currency" class="form-input">
                                            <option value="GHS" <?php echo $settings['default_salary_currency'] == 'GHS' ? 'selected' : ''; ?>>GHS (₵)</option>
                                            <option value="USD" <?php echo $settings['default_salary_currency'] == 'USD' ? 'selected' : ''; ?>>USD ($)</option>
                                        </select>
                                    </div>
                                    
                                    <div class="md:col-span-2 space-y-3">
                                        <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-700 rounded-lg">
                                            <div>
                                                <p class="font-medium">Allow Job Editing</p>
                                                <p class="text-xs text-gray-500">Allow employers to edit posted jobs</p>
                                            </div>
                                            <label class="toggle-switch">
                                                <input type="checkbox" name="allow_job_editing" <?php echo $settings['allow_job_editing'] ? 'checked' : ''; ?>>
                                                <span class="toggle-slider"></span>
                                            </label>
                                        </div>
                                        <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-700 rounded-lg">
                                            <div>
                                                <p class="font-medium">Job Approval Required</p>
                                                <p class="text-xs text-gray-500">Jobs need admin approval before publishing</p>
                                            </div>
                                            <label class="toggle-switch">
                                                <input type="checkbox" name="job_approval_required" <?php echo $settings['job_approval_required'] ? 'checked' : ''; ?>>
                                                <span class="toggle-slider"></span>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Users Settings -->
                            <?php if ($activeTab === 'users'): ?>
                            <div class="space-y-6">
                                <h3 class="text-lg font-bold mb-4 flex items-center">
                                    <i class="fas fa-users text-blue-500 mr-2"></i>User Settings
                                </h3>
                                
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label class="form-label">Session Timeout (minutes)</label>
                                        <input type="number" name="session_timeout_minutes" class="form-input" value="<?php echo $settings['session_timeout_minutes']; ?>" min="5" max="1440">
                                    </div>
                                    <div>
                                        <label class="form-label">Max Login Attempts</label>
                                        <input type="number" name="max_login_attempts" class="form-input" value="<?php echo $settings['max_login_attempts']; ?>" min="1" max="20">
                                    </div>
                                    <div>
                                        <label class="form-label">Lockout Duration (minutes)</label>
                                        <input type="number" name="lockout_duration_minutes" class="form-input" value="<?php echo $settings['lockout_duration_minutes']; ?>" min="1" max="1440">
                                    </div>
                                    <div>
                                        <label class="form-label">Password Min Length</label>
                                        <input type="number" name="password_min_length" class="form-input" value="<?php echo $settings['password_min_length']; ?>" min="6" max="32">
                                    </div>
                                    
                                    <div class="md:col-span-2 space-y-3">
                                        <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-700 rounded-lg">
                                            <div>
                                                <p class="font-medium">Allow Registration</p>
                                                <p class="text-xs text-gray-500">Allow new users to register</p>
                                            </div>
                                            <label class="toggle-switch">
                                                <input type="checkbox" name="allow_registration" <?php echo $settings['allow_registration'] ? 'checked' : ''; ?>>
                                                <span class="toggle-slider"></span>
                                            </label>
                                        </div>
                                        <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-700 rounded-lg">
                                            <div>
                                                <p class="font-medium">Require Email Verification</p>
                                                <p class="text-xs text-gray-500">Users must verify email address</p>
                                            </div>
                                            <label class="toggle-switch">
                                                <input type="checkbox" name="require_email_verification" <?php echo $settings['require_email_verification'] ? 'checked' : ''; ?>>
                                                <span class="toggle-slider"></span>
                                            </label>
                                        </div>
                                        <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-700 rounded-lg">
                                            <div>
                                                <p class="font-medium">Password Require Special Character</p>
                                                <p class="text-xs text-gray-500">Password must contain special character</p>
                                            </div>
                                            <label class="toggle-switch">
                                                <input type="checkbox" name="password_require_special" <?php echo $settings['password_require_special'] ? 'checked' : ''; ?>>
                                                <span class="toggle-slider"></span>
                                            </label>
                                        </div>
                                        <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-700 rounded-lg">
                                            <div>
                                                <p class="font-medium">Password Require Number</p>
                                                <p class="text-xs text-gray-500">Password must contain a number</p>
                                            </div>
                                            <label class="toggle-switch">
                                                <input type="checkbox" name="password_require_number" <?php echo $settings['password_require_number'] ? 'checked' : ''; ?>>
                                                <span class="toggle-slider"></span>
                                            </label>
                                        </div>
                                        <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-700 rounded-lg">
                                            <div>
                                                <p class="font-medium">Password Require Uppercase</p>
                                                <p class="text-xs text-gray-500">Password must contain uppercase letter</p>
                                            </div>
                                            <label class="toggle-switch">
                                                <input type="checkbox" name="password_require_uppercase" <?php echo $settings['password_require_uppercase'] ? 'checked' : ''; ?>>
                                                <span class="toggle-slider"></span>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Email Settings -->
                            <?php if ($activeTab === 'email'): ?>
                            <div class="space-y-6">
                                <h3 class="text-lg font-bold mb-4 flex items-center">
                                    <i class="fas fa-envelope text-blue-500 mr-2"></i>Email Settings
                                </h3>
                                
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label class="form-label">From Name</label>
                                        <input type="text" name="email_from_name" class="form-input" value="<?php echo htmlspecialchars($settings['email_from_name']); ?>">
                                    </div>
                                    <div>
                                        <label class="form-label">From Address</label>
                                        <input type="email" name="email_from_address" class="form-input" value="<?php echo htmlspecialchars($settings['email_from_address']); ?>">
                                    </div>
                                    <div>
                                        <label class="form-label">SMTP Host</label>
                                        <input type="text" name="email_smtp_host" class="form-input" value="<?php echo htmlspecialchars($settings['email_smtp_host']); ?>">
                                    </div>
                                    <div>
                                        <label class="form-label">SMTP Port</label>
                                        <input type="number" name="email_smtp_port" class="form-input" value="<?php echo $settings['email_smtp_port']; ?>">
                                    </div>
                                    <div>
                                        <label class="form-label">SMTP Encryption</label>
                                        <select name="email_smtp_encryption" class="form-input">
                                            <option value="tls" <?php echo $settings['email_smtp_encryption'] == 'tls' ? 'selected' : ''; ?>>TLS</option>
                                            <option value="ssl" <?php echo $settings['email_smtp_encryption'] == 'ssl' ? 'selected' : ''; ?>>SSL</option>
                                            <option value="none" <?php echo $settings['email_smtp_encryption'] == 'none' ? 'selected' : ''; ?>>None</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="form-label">SMTP Username</label>
                                        <input type="text" name="email_smtp_username" class="form-input" value="<?php echo htmlspecialchars($settings['email_smtp_username']); ?>">
                                    </div>
                                    <div>
                                        <label class="form-label">SMTP Password</label>
                                        <input type="password" name="email_smtp_password" class="form-input" value="<?php echo htmlspecialchars($settings['email_smtp_password']); ?>" placeholder="Leave blank to keep unchanged">
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Notifications Settings -->
                            <?php if ($activeTab === 'notifications'): ?>
                            <div class="space-y-6">
                                <h3 class="text-lg font-bold mb-4 flex items-center">
                                    <i class="fas fa-bell text-blue-500 mr-2"></i>Notification Settings
                                </h3>
                                
                                <div class="space-y-3">
                                    <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-700 rounded-lg">
                                        <div>
                                            <p class="font-medium">Enable Notifications</p>
                                            <p class="text-xs text-gray-500">Global notification toggle</p>
                                        </div>
                                        <label class="toggle-switch">
                                            <input type="checkbox" name="notifications_enabled" <?php echo $settings['notifications_enabled'] ? 'checked' : ''; ?>>
                                            <span class="toggle-slider"></span>
                                        </label>
                                    </div>
                                    <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-700 rounded-lg">
                                        <div>
                                            <p class="font-medium">Job Match Notifications</p>
                                            <p class="text-xs text-gray-500">Notify seekers about matching jobs</p>
                                        </div>
                                        <label class="toggle-switch">
                                            <input type="checkbox" name="job_match_notifications" <?php echo $settings['job_match_notifications'] ? 'checked' : ''; ?>>
                                            <span class="toggle-slider"></span>
                                        </label>
                                    </div>
                                    <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-700 rounded-lg">
                                        <div>
                                            <p class="font-medium">Application Update Notifications</p>
                                            <p class="text-xs text-gray-500">Notify about application status changes</p>
                                        </div>
                                        <label class="toggle-switch">
                                            <input type="checkbox" name="application_update_notifications" <?php echo $settings['application_update_notifications'] ? 'checked' : ''; ?>>
                                            <span class="toggle-slider"></span>
                                        </label>
                                    </div>
                                    <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-700 rounded-lg">
                                        <div>
                                            <p class="font-medium">Admin Notifications</p>
                                            <p class="text-xs text-gray-500">Notify admins about important events</p>
                                        </div>
                                        <label class="toggle-switch">
                                            <input type="checkbox" name="admin_notifications" <?php echo $settings['admin_notifications'] ? 'checked' : ''; ?>>
                                            <span class="toggle-slider"></span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <!-- SEO Settings -->
                            <?php if ($activeTab === 'seo'): ?>
                            <div class="space-y-6">
                                <h3 class="text-lg font-bold mb-4 flex items-center">
                                    <i class="fas fa-chart-line text-blue-500 mr-2"></i>SEO & Analytics
                                </h3>
                                
                                <div class="grid grid-cols-1 gap-4">
                                    <div>
                                        <label class="form-label">Google Analytics ID</label>
                                        <input type="text" name="google_analytics_id" class="form-input" value="<?php echo htmlspecialchars($settings['google_analytics_id']); ?>" placeholder="UA-XXXXX-X or G-XXXXXXXX">
                                    </div>
                                    <div>
                                        <label class="form-label">Facebook Pixel ID</label>
                                        <input type="text" name="facebook_pixel_id" class="form-input" value="<?php echo htmlspecialchars($settings['facebook_pixel_id']); ?>">
                                    </div>
                                    <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-700 rounded-lg">
                                        <div>
                                            <p class="font-medium">Enable Sitemap</p>
                                            <p class="text-xs text-gray-500">Generate XML sitemap automatically</p>
                                        </div>
                                        <label class="toggle-switch">
                                            <input type="checkbox" name="enable_sitemap" <?php echo $settings['enable_sitemap'] ? 'checked' : ''; ?>>
                                            <span class="toggle-slider"></span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Security Settings -->
                            <?php if ($activeTab === 'security'): ?>
                            <div class="space-y-6">
                                <h3 class="text-lg font-bold mb-4 flex items-center">
                                    <i class="fas fa-shield-alt text-blue-500 mr-2"></i>Security Settings
                                </h3>
                                
                                <div class="space-y-3">
                                    <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-700 rounded-lg">
                                        <div>
                                            <p class="font-medium">Force HTTPS</p>
                                            <p class="text-xs text-gray-500">Redirect all HTTP traffic to HTTPS</p>
                                        </div>
                                        <label class="toggle-switch">
                                            <input type="checkbox" name="force_https" <?php echo $settings['force_https'] ? 'checked' : ''; ?>>
                                            <span class="toggle-slider"></span>
                                        </label>
                                    </div>
                                    <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-700 rounded-lg">
                                        <div>
                                            <p class="font-medium">CSRF Protection</p>
                                            <p class="text-xs text-gray-500">Cross-Site Request Forgery protection</p>
                                        </div>
                                        <label class="toggle-switch">
                                            <input type="checkbox" name="csrf_protection" <?php echo $settings['csrf_protection'] ? 'checked' : ''; ?>>
                                            <span class="toggle-slider"></span>
                                        </label>
                                    </div>
                                    <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-700 rounded-lg">
                                        <div>
                                            <p class="font-medium">Rate Limiting</p>
                                            <p class="text-xs text-gray-500">Limit requests per IP address</p>
                                        </div>
                                        <label class="toggle-switch">
                                            <input type="checkbox" name="rate_limiting_enabled" <?php echo $settings['rate_limiting_enabled'] ? 'checked' : ''; ?>>
                                            <span class="toggle-slider"></span>
                                        </label>
                                    </div>
                                    <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-700 rounded-lg">
                                        <div>
                                            <p class="font-medium">reCAPTCHA</p>
                                            <p class="text-xs text-gray-500">Enable Google reCAPTCHA on forms</p>
                                        </div>
                                        <label class="toggle-switch">
                                            <input type="checkbox" name="recaptcha_enabled" <?php echo $settings['recaptcha_enabled'] ? 'checked' : ''; ?>>
                                            <span class="toggle-slider"></span>
                                        </label>
                                    </div>
                                    
                                    <?php if ($settings['recaptcha_enabled']): ?>
                                    <div>
                                        <label class="form-label">reCAPTCHA Site Key</label>
                                        <input type="text" name="recaptcha_site_key" class="form-input" value="<?php echo htmlspecialchars($settings['recaptcha_site_key']); ?>">
                                    </div>
                                    <div>
                                        <label class="form-label">reCAPTCHA Secret Key</label>
                                        <input type="text" name="recaptcha_secret_key" class="form-input" value="<?php echo htmlspecialchars($settings['recaptcha_secret_key']); ?>">
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Maintenance Settings -->
                            <?php if ($activeTab === 'maintenance'): ?>
                            <div class="space-y-6">
                                <h3 class="text-lg font-bold mb-4 flex items-center">
                                    <i class="fas fa-tools text-blue-500 mr-2"></i>Maintenance Mode
                                </h3>
                                
                                <div class="space-y-4">
                                    <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-700 rounded-lg">
                                        <div>
                                            <p class="font-medium">Maintenance Mode</p>
                                            <p class="text-xs text-gray-500">Put the site in maintenance mode</p>
                                        </div>
                                        <label class="toggle-switch">
                                            <input type="checkbox" name="maintenance_mode" <?php echo $settings['maintenance_mode'] ? 'checked' : ''; ?>>
                                            <span class="toggle-slider"></span>
                                        </label>
                                    </div>
                                    <div>
                                        <label class="form-label">Maintenance Message</label>
                                        <textarea name="maintenance_message" rows="3" class="form-input"><?php echo htmlspecialchars($settings['maintenance_message']); ?></textarea>
                                    </div>
                                    <div>
                                        <label class="form-label">Allowed IPs (one per line)</label>
                                        <textarea name="maintenance_allowed_ips" rows="3" class="form-input" placeholder="127.0.0.1"><?php echo htmlspecialchars($settings['maintenance_allowed_ips']); ?></textarea>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Social Settings -->
                            <?php if ($activeTab === 'social'): ?>
                            <div class="space-y-6">
                                <h3 class="text-lg font-bold mb-4 flex items-center">
                                    <i class="fas fa-share-alt text-blue-500 mr-2"></i>Social Media Links
                                </h3>
                                
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label class="form-label"><i class="fab fa-facebook mr-2"></i>Facebook URL</label>
                                        <input type="url" name="facebook_url" class="form-input" value="<?php echo htmlspecialchars($settings['facebook_url']); ?>">
                                    </div>
                                    <div>
                                        <label class="form-label"><i class="fab fa-twitter mr-2"></i>Twitter URL</label>
                                        <input type="url" name="twitter_url" class="form-input" value="<?php echo htmlspecialchars($settings['twitter_url']); ?>">
                                    </div>
                                    <div>
                                        <label class="form-label"><i class="fab fa-linkedin mr-2"></i>LinkedIn URL</label>
                                        <input type="url" name="linkedin_url" class="form-input" value="<?php echo htmlspecialchars($settings['linkedin_url']); ?>">
                                    </div>
                                    <div>
                                        <label class="form-label"><i class="fab fa-instagram mr-2"></i>Instagram URL</label>
                                        <input type="url" name="instagram_url" class="form-input" value="<?php echo htmlspecialchars($settings['instagram_url']); ?>">
                                    </div>
                                    <div>
                                        <label class="form-label"><i class="fab fa-whatsapp mr-2"></i>WhatsApp Number</label>
                                        <input type="text" name="whatsapp_number" class="form-input" value="<?php echo htmlspecialchars($settings['whatsapp_number']); ?>">
                                    </div>
                                    <div>
                                        <label class="form-label"><i class="fab fa-weixin mr-2"></i>WeChat ID</label>
                                        <input type="text" name="wechat_id" class="form-input" value="<?php echo htmlspecialchars($settings['wechat_id']); ?>">
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Save Button -->
                            <div class="flex justify-end mt-8 pt-4 border-t border-gray-200 dark:border-gray-700">
                                <button type="submit" class="px-6 py-2.5 brand-gradient text-white rounded-lg hover:opacity-90 transition hover:scale-105 transform duration-200 flex items-center space-x-2">
                                    <i class="fas fa-save"></i>
                                    <span>Save Settings</span>
                                </button>
                            </div>
                        </form>
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
                <img src="<?php echo $avatarUrl; ?>" alt="Admin" class="w-12 h-12 rounded-lg border-2 border-blue-200">
                <div>
                    <p class="font-semibold">Administrator</p>
                    <p class="text-sm text-gray-500 dark:text-gray-400"><?php echo htmlspecialchars($adminData['email'] ?? ''); ?></p>
                </div>
            </div>
        </div>
        
        <nav class="space-y-1">
            <a href="dashboard.php" class="block px-4 py-3 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">Dashboard</a>
            <a href="users.php" class="block px-4 py-3 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">Users</a>
            <a href="jobs.php" class="block px-4 py-3 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">Jobs</a>
            <a href="comments.php" class="block px-4 py-3 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">Comments</a>
            <a href="testimonials.php" class="block px-4 py-3 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">Testimonials</a>
            <a href="settings.php" class="block px-4 py-3 bg-blue-50 dark:bg-blue-900/20 text-blue-600 rounded-lg font-medium">Settings</a>
            <div class="border-t border-gray-200 dark:border-gray-700 my-4"></div>
            <a href="change-password.php" class="block px-4 py-3 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">Change Password</a>
            <a href="/" target="_blank" class="block px-4 py-3 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">View Site</a>
            <a href="/logout.php" class="block px-4 py-3 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg">Logout</a>
        </nav>
    </div>
</div>

<script>
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

darkModeToggle?.addEventListener('click', () => {
    document.documentElement.classList.toggle('dark');
    const isDark = document.documentElement.classList.contains('dark');
    localStorage.theme = isDark ? 'dark' : 'light';
    darkModeIcon?.classList.toggle('fa-moon', !isDark);
    darkModeIcon?.classList.toggle('fa-sun', isDark);
});

// Mobile Sidebar
const mobileMenuBtn = document.getElementById('mobileMenuBtn');
const mobileSidebar = document.getElementById('mobileSidebar');

mobileMenuBtn?.addEventListener('click', () => mobileSidebar?.classList.remove('hidden'));
function closeMobileSidebar() { mobileSidebar?.classList.add('hidden'); }

// Auto-hide flash message
setTimeout(() => {
    const flash = document.getElementById('flashMessage');
    if (flash) {
        flash.style.transition = 'opacity 0.5s';
        flash.style.opacity = '0';
        setTimeout(() => flash?.remove(), 500);
    }
}, 5000);

// Keyboard shortcuts
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        closeMobileSidebar();
    }
    if ((e.ctrlKey || e.metaKey) && e.key === 's') {
        e.preventDefault();
        document.getElementById('settingsForm')?.submit();
    }
});
</script>

</body>
</html>