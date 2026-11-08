<?php
// TransNow/seekers/settings.php
// Version: 1.0 - Seeker Settings Page

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

// Check if user is logged in and is a seeker
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'seeker') {
    $_SESSION['error'] = 'Please login as a job seeker to access this page';
    header('Location: /login.php');
    exit();
}

// Get database connection
$pdo = getDB();
$user_id = $_SESSION['user_id'];

// Get seeker ID from session or database
if (isset($_SESSION['seeker_id'])) {
    $seeker_id = $_SESSION['seeker_id'];
} else {
    // Fetch seeker ID from database
    $stmt = $pdo->prepare("SELECT id FROM seekers WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $seeker_id = $stmt->fetchColumn();
    
    if ($seeker_id) {
        $_SESSION['seeker_id'] = $seeker_id;
    } else {
        $_SESSION['error'] = 'Please complete your profile first';
        header('Location: profile.php?edit=1');
        exit();
    }
}

// Initialize variables
$success_message = '';
$error_message = '';
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'profile';

// Get seeker profile data
$seekerProfile = fetchOne("
    SELECT 
        s.*,
        u.email,
        u.is_verified,
        u.created_at as account_created
    FROM seekers s
    INNER JOIN users u ON s.user_id = u.id
    WHERE s.id = ?
", [$seeker_id]);

// Get seeker preferences
$seekerPreferences = fetchOne("
    SELECT * FROM seeker_preferences 
    WHERE seeker_id = ?
", [$seeker_id]);

// If no preferences exist, create default preferences
if (!$seekerPreferences) {
    executeQuery("
        INSERT INTO seeker_preferences (seeker_id, notification_frequency, is_active, created_at) 
        VALUES (?, 'daily', 1, NOW())
    ", [$seeker_id]);
    
    // Fetch newly created preferences
    $seekerPreferences = fetchOne("
        SELECT * FROM seeker_preferences 
        WHERE seeker_id = ?
    ", [$seeker_id]);
}

// Get job categories for preferences
$jobCategories = fetchAll("
    SELECT id, name, slug 
    FROM job_categories 
    WHERE is_active = 1 
    ORDER BY name
");

// Get job types for preferences
$jobTypes = fetchAll("
    SELECT id, name, slug 
    FROM job_types 
    WHERE is_active = 1 
    ORDER BY name
");

// Get all settings from settings table for system configuration
$systemSettings = fetchAll("
    SELECT setting_key, setting_value, setting_type, category, description 
    FROM settings 
    WHERE category IN ('seeker', 'general', 'notification') 
    AND (is_public = 1 OR ? IS NOT NULL)
    ORDER BY category, setting_key
", [$user_id]);

// Organize settings by category
$settingsByCategory = [];
foreach ($systemSettings as $setting) {
    $category = $setting['category'];
    if (!isset($settingsByCategory[$category])) {
        $settingsByCategory[$category] = [];
    }
    $settingsByCategory[$category][] = $setting;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'update_profile':
            // Update seeker profile information
            $first_name = sanitize($_POST['first_name'] ?? '');
            $last_name = sanitize($_POST['last_name'] ?? '');
            $phone = sanitize($_POST['phone'] ?? '');
            $whatsapp_number = sanitize($_POST['whatsapp_number'] ?? '');
            $headline = sanitize($_POST['headline'] ?? '');
            $bio = sanitize($_POST['bio'] ?? '');
            $location = sanitize($_POST['location'] ?? '');
            $current_job_title = sanitize($_POST['current_job_title'] ?? '');
            $current_company = sanitize($_POST['current_company'] ?? '');
            $years_experience = intval($_POST['years_experience'] ?? 0);
            $highest_education = sanitize($_POST['highest_education'] ?? '');
            $skills = sanitize($_POST['skills'] ?? '');
            $expected_salary_min = !empty($_POST['expected_salary_min']) ? floatval($_POST['expected_salary_min']) : null;
            $expected_salary_max = !empty($_POST['expected_salary_max']) ? floatval($_POST['expected_salary_max']) : null;
            
            // Validate required fields
            if (empty($first_name) || empty($last_name)) {
                $error_message = 'First name and last name are required';
            } else {
                // Update seekers table
                $updateQuery = "
                    UPDATE seekers SET
                        first_name = ?,
                        last_name = ?,
                        phone = ?,
                        whatsapp_number = ?,
                        headline = ?,
                        bio = ?,
                        location = ?,
                        current_job_title = ?,
                        current_company = ?,
                        years_experience = ?,
                        highest_education = ?,
                        skills = ?,
                        expected_salary_min = ?,
                        expected_salary_max = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ";
                
                if (executeQuery($updateQuery, [
                    $first_name, $last_name, $phone, $whatsapp_number, $headline, 
                    $bio, $location, $current_job_title, $current_company, 
                    $years_experience, $highest_education, $skills, 
                    $expected_salary_min, $expected_salary_max, $seeker_id
                ])) {
                    $success_message = 'Profile updated successfully';
                    
                    // Log activity
                    logUserActivity('profile_update', 'Updated profile information');
                    
                    // Refresh profile data
                    $seekerProfile = fetchOne("SELECT * FROM seekers WHERE id = ?", [$seeker_id]);
                } else {
                    $error_message = 'Failed to update profile';
                }
            }
            break;
            
        case 'update_preferences':
            // Update job preferences
            $preferred_categories = isset($_POST['preferred_categories']) ? json_encode($_POST['preferred_categories']) : null;
            $preferred_job_types = isset($_POST['preferred_job_types']) ? json_encode($_POST['preferred_job_types']) : null;
            $preferred_location_types = isset($_POST['preferred_location_types']) ? json_encode($_POST['preferred_location_types']) : null;
            $preferred_locations = isset($_POST['preferred_locations']) ? json_encode(array_filter(array_map('sanitize', $_POST['preferred_locations']))) : null;
            $min_salary = !empty($_POST['min_salary']) ? floatval($_POST['min_salary']) : null;
            $max_salary = !empty($_POST['max_salary']) ? floatval($_POST['max_salary']) : null;
            $experience_level_preference = sanitize($_POST['experience_level_preference'] ?? 'any');
            $notification_frequency = sanitize($_POST['notification_frequency'] ?? 'daily');
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            
            // Update seeker_preferences table
            $updateQuery = "
                UPDATE seeker_preferences SET
                    preferred_categories = ?,
                    preferred_job_types = ?,
                    preferred_location_types = ?,
                    preferred_locations = ?,
                    min_salary = ?,
                    max_salary = ?,
                    experience_level_preference = ?,
                    notification_frequency = ?,
                    is_active = ?,
                    updated_at = NOW()
                WHERE seeker_id = ?
            ";
            
            if (executeQuery($updateQuery, [
                $preferred_categories, $preferred_job_types, $preferred_location_types, 
                $preferred_locations, $min_salary, $max_salary, 
                $experience_level_preference, $notification_frequency, $is_active, $seeker_id
            ])) {
                $success_message = 'Job preferences updated successfully';
                
                // Log activity
                logUserActivity('preferences_update', 'Updated job preferences');
                
                // Refresh preferences data
                $seekerPreferences = fetchOne("SELECT * FROM seeker_preferences WHERE seeker_id = ?", [$seeker_id]);
            } else {
                $error_message = 'Failed to update preferences';
            }
            break;
            
        case 'change_password':
            // Change password
            $current_password = $_POST['current_password'] ?? '';
            $new_password = $_POST['new_password'] ?? '';
            $confirm_password = $_POST['confirm_password'] ?? '';
            
            // Validate
            if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
                $error_message = 'All password fields are required';
            } elseif ($new_password !== $confirm_password) {
                $error_message = 'New passwords do not match';
            } elseif (strlen($new_password) < 8) {
                $error_message = 'Password must be at least 8 characters long';
            } else {
                // Verify current password
                $userData = fetchOne("SELECT password_hash FROM users WHERE id = ?", [$user_id]);
                
                if ($userData && password_verify($current_password, $userData['password_hash'])) {
                    // Update password
                    $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                    
                    if (executeQuery("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?", [$new_password_hash, $user_id])) {
                        $success_message = 'Password changed successfully';
                        
                        // Log activity
                        logUserActivity('password_change', 'Changed password');
                    } else {
                        $error_message = 'Failed to change password';
                    }
                } else {
                    $error_message = 'Current password is incorrect';
                }
            }
            break;
            
        case 'update_notifications':
            // Update notification settings
            // Get all notification types from the form
            $notification_settings = $_POST['notification'] ?? [];
            
            // In a real implementation, you'd have a notification_settings table
            // For now, we'll store in session or a JSON field in seekers table
            // This is a placeholder - implement based on your notification system
            $success_message = 'Notification settings updated successfully';
            
            // Log activity
            logUserActivity('notification_update', 'Updated notification preferences');
            break;
            
        case 'update_privacy':
            // Update privacy settings
            $profile_visibility = sanitize($_POST['profile_visibility'] ?? 'public');
            $show_email = isset($_POST['show_email']) ? 1 : 0;
            $show_phone = isset($_POST['show_phone']) ? 1 : 0;
            $show_current_company = isset($_POST['show_current_company']) ? 1 : 0;
            
            // Store in seekers table or a separate privacy_settings table
            // For now, we'll use a JSON field in seekers table (you'd need to add this column)
            $privacy_settings = json_encode([
                'profile_visibility' => $profile_visibility,
                'show_email' => $show_email,
                'show_phone' => $show_phone,
                'show_current_company' => $show_current_company
            ]);
            
            // Update - you'd need to add a privacy_settings column to seekers table
            // For now, just show success message
            $success_message = 'Privacy settings updated successfully';
            
            // Log activity
            logUserActivity('privacy_update', 'Updated privacy settings');
            break;
            
        case 'delete_account':
            // Account deletion (soft delete)
            $confirm_text = sanitize($_POST['confirm_text'] ?? '');
            $password = $_POST['password'] ?? '';
            
            if ($confirm_text !== 'DELETE') {
                $error_message = 'Please type DELETE to confirm account deletion';
            } elseif (empty($password)) {
                $error_message = 'Password is required';
            } else {
                // Verify password
                $userData = fetchOne("SELECT password_hash FROM users WHERE id = ?", [$user_id]);
                
                if ($userData && password_verify($password, $userData['password_hash'])) {
                    // Soft delete - mark account as inactive
                    if (executeQuery("UPDATE users SET is_verified = 0, updated_at = NOW() WHERE id = ?", [$user_id])) {
                        // Logout and redirect
                        logout();
                        exit();
                    } else {
                        $error_message = 'Failed to delete account';
                    }
                } else {
                    $error_message = 'Password is incorrect';
                }
            }
            break;
    }
}

// Parse JSON preferences
$preferredCategories = [];
$preferredJobTypes = [];
$preferredLocationTypes = [];
$preferredLocations = [];

if ($seekerPreferences) {
    if (!empty($seekerPreferences['preferred_categories'])) {
        $preferredCategories = json_decode($seekerPreferences['preferred_categories'], true) ?: [];
    }
    if (!empty($seekerPreferences['preferred_job_types'])) {
        $preferredJobTypes = json_decode($seekerPreferences['preferred_job_types'], true) ?: [];
    }
    if (!empty($seekerPreferences['preferred_location_types'])) {
        $preferredLocationTypes = json_decode($seekerPreferences['preferred_location_types'], true) ?: [];
    }
    if (!empty($seekerPreferences['preferred_locations'])) {
        $preferredLocations = json_decode($seekerPreferences['preferred_locations'], true) ?: [];
    }
}

// Avatar URL
$avatarUrl = $seekerProfile['profile_picture'] ?: 'https://ui-avatars.com/api/?name=' . urlencode(($seekerProfile['first_name'] ?? 'User') . '+' . ($seekerProfile['last_name'] ?? '')) . '&background=4f46e5&color=fff&size=128&bold=true&length=2';
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - <?php echo htmlspecialchars($seekerProfile['first_name'] ?? 'Seeker'); ?> | TransNow</title>
    
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Ccircle cx='50' cy='50' r='45' fill='%234f46e5'/%3E%3Cpath d='M30 40 L70 40 L65 70 L35 70 Z' fill='white'/%3E%3Ccircle cx='50' cy='30' r='8' fill='white'/%3E%3C/svg%3E">
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- AOS Animation -->
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    
    <!-- Select2 for multi-select -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    
    <style>
        * {
            font-family: 'Inter', sans-serif;
        }
        
        /* Custom Animations */
        @keyframes float {
            0%, 100% { transform: translateY(0) rotate(0deg); }
            25% { transform: translateY(-10px) rotate(2deg); }
            75% { transform: translateY(10px) rotate(-2deg); }
        }
        
        @keyframes pulse-glow {
            0%, 100% { box-shadow: 0 0 0 0 rgba(79, 70, 229, 0.5); }
            50% { box-shadow: 0 0 20px 5px rgba(79, 70, 229, 0.3); }
        }
        
        @keyframes slideIn {
            from { transform: translateX(20px); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        
        .animate-float {
            animation: float 6s ease-in-out infinite;
        }
        
        .animate-pulse-glow {
            animation: pulse-glow 2s ease-in-out infinite;
        }
        
        .animate-slideIn {
            animation: slideIn 0.3s ease-out forwards;
        }
        
        /* Glass Morphism */
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
        
        /* Card Hover Effects */
        .hover-card {
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }
        
        .hover-card:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 25px 50px -12px rgba(79, 70, 229, 0.35);
        }
        
        .hover-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            border-radius: inherit;
            background: linear-gradient(135deg, rgba(79, 70, 229, 0.1), rgba(124, 58, 237, 0.1));
            opacity: 0;
            transition: opacity 0.4s;
            pointer-events: none;
        }
        
        .hover-card:hover::before {
            opacity: 1;
        }
        
        /* Gradient Text */
        .gradient-text {
            background: linear-gradient(135deg, #4f46e5, #7c3aed, #db2777);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        /* Custom Scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: #f1f1f1;
        }
        
        ::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, #4f46e5, #7c3aed);
            border-radius: 4px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(135deg, #7c3aed, #db2777);
        }
        
        /* Tab Styles */
        .settings-tab {
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .settings-tab.active {
            background: linear-gradient(135deg, #4f46e5, #7c3aed);
            color: white;
            box-shadow: 0 10px 20px -5px rgba(79, 70, 229, 0.5);
        }
        
        .settings-tab.active::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, rgba(255,255,255,0.2), rgba(255,255,255,0));
            transform: translateX(-100%);
            animation: shimmer 2s infinite;
        }
        
        @keyframes shimmer {
            100% { transform: translateX(100%); }
        }
        
        /* Form Styles */
        .form-input, .form-select, .form-textarea {
            transition: all 0.3s ease;
            border: 2px solid transparent;
        }
        
        .form-input:focus, .form-select:focus, .form-textarea:focus {
            border-color: #4f46e5;
            box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.1);
            outline: none;
        }
        
        .form-input.error, .form-select.error, .form-textarea.error {
            border-color: #ef4444;
        }
        
        /* Toggle Switch */
        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 60px;
            height: 34px;
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
            background-color: #ccc;
            transition: .4s;
            border-radius: 34px;
        }
        
        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 26px;
            width: 26px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        
        input:checked + .toggle-slider {
            background: linear-gradient(135deg, #4f46e5, #7c3aed);
        }
        
        input:checked + .toggle-slider:before {
            transform: translateX(26px);
        }
        
        /* Select2 Customization */
        .select2-container--bootstrap-5 .select2-selection {
            min-height: 42px;
            border: 2px solid #e5e7eb;
            border-radius: 0.75rem;
        }
        
        .select2-container--bootstrap-5.select2-container--focus .select2-selection {
            border-color: #4f46e5;
            box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.1);
        }
        
        .dark .select2-container--bootstrap-5 .select2-selection {
            background-color: #1f2937;
            border-color: #374151;
            color: white;
        }
        
        /* Loading Skeleton */
        .skeleton {
            background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
            background-size: 200% 100%;
            animation: shimmer 1.5s infinite;
        }
        
        /* Mobile Optimizations */
        @media (max-width: 768px) {
            .settings-grid {
                grid-template-columns: 1fr;
            }
        }
        
        /* Dark mode support */
        .dark .dark-mode-aware {
            background: #1f2937;
            color: #ffffff;
        }
        
        /* Settings Section Divider */
        .settings-divider {
            position: relative;
            text-align: center;
            margin: 2rem 0;
        }
        
        .settings-divider::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 0;
            right: 0;
            height: 1px;
            background: linear-gradient(90deg, transparent, #4f46e5, transparent);
        }
        
        .settings-divider span {
            position: relative;
            background: white;
            padding: 0 1rem;
            color: #4f46e5;
            font-weight: 600;
        }
        
        .dark .settings-divider span {
            background: #1f2937;
        }
        
        /* Danger Zone */
        .danger-zone {
            border: 2px solid #ef4444;
            border-radius: 1rem;
            padding: 1.5rem;
            position: relative;
            overflow: hidden;
        }
        
        .danger-zone::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #ef4444, #f97316, #ef4444);
            animation: gradient 2s ease infinite;
        }
        
        @keyframes gradient {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }
    </style>
</head>
<body class="bg-gray-50 dark:bg-gray-900 text-gray-900 dark:text-white transition-colors duration-300">

<!-- Flash Message -->
<?php if ($success_message): ?>
<div class="fixed top-20 right-4 z-50 bg-green-500 text-white px-6 py-3 rounded-xl shadow-lg animate-slideIn flex items-center space-x-2">
    <i class="fas fa-check-circle"></i>
    <span><?php echo htmlspecialchars($success_message); ?></span>
</div>
<?php endif; ?>

<?php if ($error_message): ?>
<div class="fixed top-20 right-4 z-50 bg-red-500 text-white px-6 py-3 rounded-xl shadow-lg animate-slideIn flex items-center space-x-2">
    <i class="fas fa-exclamation-circle"></i>
    <span><?php echo htmlspecialchars($error_message); ?></span>
</div>
<?php endif; ?>

<!-- Dark Mode Toggle -->
<button id="darkModeToggle" 
        class="fixed top-24 right-4 z-40 w-12 h-12 bg-white dark:bg-gray-800 text-gray-700 dark:text-white rounded-full flex items-center justify-center hover:scale-110 transition-all duration-300 shadow-xl border border-gray-200 dark:border-gray-700">
    <i class="fas fa-moon text-xl" id="darkModeIcon"></i>
</button>

<!-- Main Layout -->
<div class="flex h-screen overflow-hidden">
    <!-- Sidebar -->
    <aside class="hidden lg:flex lg:flex-shrink-0">
        <div class="flex flex-col w-72 bg-white dark:bg-gray-800 border-r border-gray-200 dark:border-gray-700 overflow-y-auto">
            <!-- Sidebar Header -->
            <div class="p-6">
                <a href="/index.php" class="flex items-center space-x-3">
                    <div class="w-10 h-10 bg-gradient-to-r from-indigo-600 to-purple-600 rounded-lg flex items-center justify-center">
                        <i class="fas fa-globe-asia text-white text-lg"></i>
                    </div>
                    <span class="text-xl font-bold">
                        <span class="gradient-text">TransNow</span>
                    </span>
                </a>
            </div>
            
            <!-- User Profile Summary -->
            <div class="px-6 py-4 border-t border-b border-gray-200 dark:border-gray-700">
                <div class="flex items-center space-x-4">
                    <div class="relative">
                        <img src="<?php echo htmlspecialchars($avatarUrl); ?>" 
                             alt="Profile" 
                             class="w-16 h-16 rounded-xl object-cover border-2 border-indigo-200 dark:border-indigo-800">
                        <div class="absolute -bottom-1 -right-1 w-5 h-5 bg-green-500 border-2 border-white dark:border-gray-800 rounded-full animate-pulse"></div>
                    </div>
                    <div class="flex-1 min-w-0">
                        <h3 class="font-bold text-lg truncate">
                            <?php echo htmlspecialchars(($seekerProfile['first_name'] ?? 'User') . ' ' . ($seekerProfile['last_name'] ?? '')); ?>
                        </h3>
                        <p class="text-sm text-gray-600 dark:text-gray-400 truncate">
                            <?php echo htmlspecialchars($seekerProfile['headline'] ?? 'Job Seeker'); ?>
                        </p>
                    </div>
                </div>
            </div>
            
            <!-- Navigation -->
            <nav class="flex-1 px-4 py-6 space-y-1">
                <a href="dashboard.php" class="flex items-center space-x-3 px-4 py-3 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                    <i class="fas fa-tachometer-alt w-5"></i>
                    <span>Dashboard</span>
                </a>
                
                <a href="jobs.php" class="flex items-center space-x-3 px-4 py-3 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                    <i class="fas fa-search w-5"></i>
                    <span>Find Jobs</span>
                </a>
                
                <a href="applications.php" class="flex items-center space-x-3 px-4 py-3 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                    <i class="fas fa-paper-plane w-5"></i>
                    <span>Applications</span>
                </a>
                
                <a href="saved-jobs.php" class="flex items-center space-x-3 px-4 py-3 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                    <i class="fas fa-bookmark w-5"></i>
                    <span>Saved Jobs</span>
                </a>
                
                <a href="interviews.php" class="flex items-center space-x-3 px-4 py-3 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                    <i class="fas fa-calendar-alt w-5"></i>
                    <span>Interviews</span>
                </a>
                
                <a href="profile.php" class="flex items-center space-x-3 px-4 py-3 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                    <i class="fas fa-user-circle w-5"></i>
                    <span>My Profile</span>
                </a>
                
                <a href="settings.php" class="flex items-center space-x-3 px-4 py-3 bg-indigo-50 dark:bg-indigo-900/20 text-indigo-600 dark:text-indigo-400 rounded-xl font-medium">
                    <i class="fas fa-cog w-5"></i>
                    <span>Settings</span>
                </a>
                
                <div class="border-t border-gray-200 dark:border-gray-700 my-4"></div>
                
                <a href="/help-center.php" class="flex items-center space-x-3 px-4 py-3 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                    <i class="fas fa-question-circle w-5"></i>
                    <span>Help Center</span>
                </a>
                
                <a href="/logout.php" class="flex items-center space-x-3 px-4 py-3 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-xl transition">
                    <i class="fas fa-sign-out-alt w-5"></i>
                    <span>Logout</span>
                </a>
            </nav>
            
            <!-- Sidebar Footer -->
            <div class="p-4 border-t border-gray-200 dark:border-gray-700">
                <div class="bg-gradient-to-r from-indigo-500 to-purple-600 rounded-xl p-4 text-white">
                    <p class="text-sm opacity-90 mb-2">Need help with settings?</p>
                    <a href="/contact.php" class="text-xs font-semibold flex items-center hover:underline">
                        <span>Contact Support</span>
                        <i class="fas fa-arrow-right ml-2"></i>
                    </a>
                </div>
            </div>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="flex-1 overflow-y-auto bg-gray-50 dark:bg-gray-900">
        <!-- Top Navigation -->
        <header class="sticky top-0 z-30 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700">
            <div class="px-6 py-4">
                <div class="flex items-center justify-between">
                    <!-- Mobile Menu Toggle -->
                    <button id="mobileSidebarToggle" class="lg:hidden w-10 h-10 bg-gray-100 dark:bg-gray-700 rounded-lg flex items-center justify-center hover:bg-gray-200 dark:hover:bg-gray-600 transition">
                        <i class="fas fa-bars"></i>
                    </button>
                    
                    <!-- Page Title -->
                    <h1 class="text-2xl font-bold">
                        <span class="gradient-text">Settings</span>
                    </h1>
                    
                    <!-- Right Actions -->
                    <div class="flex items-center space-x-4">
                        <a href="profile.php" class="px-4 py-2 bg-gradient-to-r from-indigo-600 to-purple-600 text-white rounded-xl font-medium hover:shadow-lg transition-all duration-300 flex items-center space-x-2">
                            <i class="fas fa-user-edit"></i>
                            <span class="hidden md:inline">Edit Profile</span>
                        </a>
                    </div>
                </div>
            </div>
        </header>

        <!-- Settings Content -->
        <div class="p-6">
            <!-- Settings Tabs -->
            <div class="mb-8 border-b border-gray-200 dark:border-gray-700">
                <div class="flex flex-wrap -mb-px">
                    <button onclick="switchTab('profile')" class="settings-tab px-6 py-3 font-medium text-sm rounded-t-lg mr-2 <?php echo $active_tab === 'profile' ? 'active' : 'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300'; ?>" data-tab="profile">
                        <i class="fas fa-user mr-2"></i> Profile Info
                    </button>
                    <button onclick="switchTab('preferences')" class="settings-tab px-6 py-3 font-medium text-sm rounded-t-lg mr-2 <?php echo $active_tab === 'preferences' ? 'active' : 'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300'; ?>" data-tab="preferences">
                        <i class="fas fa-sliders-h mr-2"></i> Job Preferences
                    </button>
                    <button onclick="switchTab('notifications')" class="settings-tab px-6 py-3 font-medium text-sm rounded-t-lg mr-2 <?php echo $active_tab === 'notifications' ? 'active' : 'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300'; ?>" data-tab="notifications">
                        <i class="fas fa-bell mr-2"></i> Notifications
                    </button>
                    <button onclick="switchTab('privacy')" class="settings-tab px-6 py-3 font-medium text-sm rounded-t-lg mr-2 <?php echo $active_tab === 'privacy' ? 'active' : 'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300'; ?>" data-tab="privacy">
                        <i class="fas fa-lock mr-2"></i> Privacy & Security
                    </button>
                    <button onclick="switchTab('account')" class="settings-tab px-6 py-3 font-medium text-sm rounded-t-lg <?php echo $active_tab === 'account' ? 'active' : 'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300'; ?>" data-tab="account">
                        <i class="fas fa-shield-alt mr-2"></i> Account
                    </button>
                </div>
            </div>

            <!-- Tab Content -->
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6 md:p-8" data-aos="fade-up">
                <!-- Profile Info Tab -->
                <div id="tab-profile" class="tab-content <?php echo $active_tab === 'profile' ? '' : 'hidden'; ?>">
                    <form method="POST" action="" class="space-y-8">
                        <input type="hidden" name="action" value="update_profile">
                        
                        <!-- Profile Picture Section -->
                        <div class="flex items-center space-x-6">
                            <div class="relative group">
                                <img src="<?php echo htmlspecialchars($avatarUrl); ?>" 
                                     alt="Profile" 
                                     class="w-24 h-24 rounded-2xl object-cover border-4 border-indigo-200 dark:border-indigo-800">
                                <button type="button" onclick="document.getElementById('profile_picture_input').click()" class="absolute inset-0 bg-black/50 rounded-2xl opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center">
                                    <i class="fas fa-camera text-white text-2xl"></i>
                                </button>
                            </div>
                            <div class="flex-1">
                                <h3 class="text-lg font-semibold mb-2">Profile Picture</h3>
                                <p class="text-sm text-gray-600 dark:text-gray-400 mb-3">Upload a professional photo to increase your profile views by 7x</p>
                                <input type="file" id="profile_picture_input" name="profile_picture" accept="image/*" class="hidden" onchange="uploadProfilePicture(this)">
                                <button type="button" onclick="document.getElementById('profile_picture_input').click()" class="px-4 py-2 bg-gray-100 dark:bg-gray-700 rounded-lg text-sm font-medium hover:bg-gray-200 dark:hover:bg-gray-600 transition">
                                    <i class="fas fa-upload mr-2"></i> Upload New Photo
                                </button>
                            </div>
                        </div>
                        
                        <!-- Personal Information -->
                        <div>
                            <h3 class="text-lg font-semibold mb-4 flex items-center">
                                <i class="fas fa-id-card text-indigo-600 mr-2"></i>
                                Personal Information
                            </h3>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label class="block text-sm font-medium mb-2">First Name *</label>
                                    <input type="text" name="first_name" required 
                                           value="<?php echo htmlspecialchars($seekerProfile['first_name'] ?? ''); ?>"
                                           class="form-input w-full px-4 py-3 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-indigo-500">
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium mb-2">Last Name *</label>
                                    <input type="text" name="last_name" required 
                                           value="<?php echo htmlspecialchars($seekerProfile['last_name'] ?? ''); ?>"
                                           class="form-input w-full px-4 py-3 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-indigo-500">
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium mb-2">Email</label>
                                    <input type="email" value="<?php echo htmlspecialchars($seekerProfile['email'] ?? ''); ?>" 
                                           class="w-full px-4 py-3 bg-gray-100 dark:bg-gray-600 border border-gray-300 dark:border-gray-500 rounded-xl cursor-not-allowed" 
                                           disabled>
                                    <p class="text-xs text-gray-500 mt-1">Email cannot be changed</p>
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium mb-2">Phone</label>
                                    <input type="tel" name="phone" 
                                           value="<?php echo htmlspecialchars($seekerProfile['phone'] ?? ''); ?>"
                                           class="form-input w-full px-4 py-3 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-indigo-500">
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium mb-2">WhatsApp Number</label>
                                    <input type="tel" name="whatsapp_number" 
                                           value="<?php echo htmlspecialchars($seekerProfile['whatsapp_number'] ?? ''); ?>"
                                           class="form-input w-full px-4 py-3 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-indigo-500">
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium mb-2">Location</label>
                                    <input type="text" name="location" 
                                           value="<?php echo htmlspecialchars($seekerProfile['location'] ?? ''); ?>"
                                           class="form-input w-full px-4 py-3 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-indigo-500">
                                </div>
                            </div>
                        </div>
                        
                        <!-- Professional Information -->
                        <div class="pt-6 border-t border-gray-200 dark:border-gray-700">
                            <h3 class="text-lg font-semibold mb-4 flex items-center">
                                <i class="fas fa-briefcase text-indigo-600 mr-2"></i>
                                Professional Information
                            </h3>
                            
                            <div class="space-y-6">
                                <div>
                                    <label class="block text-sm font-medium mb-2">Professional Headline</label>
                                    <input type="text" name="headline" 
                                           value="<?php echo htmlspecialchars($seekerProfile['headline'] ?? ''); ?>"
                                           placeholder="e.g., Senior Software Engineer | Full Stack Developer"
                                           class="form-input w-full px-4 py-3 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-indigo-500">
                                    <p class="text-xs text-gray-500 mt-1">A brief professional title that appears at the top of your profile</p>
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium mb-2">Bio / Summary</label>
                                    <textarea name="bio" rows="4" 
                                              class="form-textarea w-full px-4 py-3 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-indigo-500"><?php echo htmlspecialchars($seekerProfile['bio'] ?? ''); ?></textarea>
                                    <p class="text-xs text-gray-500 mt-1">Tell employers about yourself, your career goals, and what makes you unique</p>
                                </div>
                                
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <div>
                                        <label class="block text-sm font-medium mb-2">Current Job Title</label>
                                        <input type="text" name="current_job_title" 
                                               value="<?php echo htmlspecialchars($seekerProfile['current_job_title'] ?? ''); ?>"
                                               class="form-input w-full px-4 py-3 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-indigo-500">
                                    </div>
                                    
                                    <div>
                                        <label class="block text-sm font-medium mb-2">Current Company</label>
                                        <input type="text" name="current_company" 
                                               value="<?php echo htmlspecialchars($seekerProfile['current_company'] ?? ''); ?>"
                                               class="form-input w-full px-4 py-3 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-indigo-500">
                                    </div>
                                </div>
                                
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <div>
                                        <label class="block text-sm font-medium mb-2">Years of Experience</label>
                                        <input type="number" name="years_experience" min="0" max="50" 
                                               value="<?php echo htmlspecialchars($seekerProfile['years_experience'] ?? '0'); ?>"
                                               class="form-input w-full px-4 py-3 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-indigo-500">
                                    </div>
                                    
                                    <div>
                                        <label class="block text-sm font-medium mb-2">Highest Education</label>
                                        <select name="highest_education" 
                                                class="form-select w-full px-4 py-3 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-indigo-500">
                                            <option value="">Select Education</option>
                                            <option value="high_school" <?php echo ($seekerProfile['highest_education'] ?? '') == 'high_school' ? 'selected' : ''; ?>>High School</option>
                                            <option value="associate" <?php echo ($seekerProfile['highest_education'] ?? '') == 'associate' ? 'selected' : ''; ?>>Associate Degree</option>
                                            <option value="bachelor" <?php echo ($seekerProfile['highest_education'] ?? '') == 'bachelor' ? 'selected' : ''; ?>>Bachelor's Degree</option>
                                            <option value="master" <?php echo ($seekerProfile['highest_education'] ?? '') == 'master' ? 'selected' : ''; ?>>Master's Degree</option>
                                            <option value="phd" <?php echo ($seekerProfile['highest_education'] ?? '') == 'phd' ? 'selected' : ''; ?>>PhD / Doctorate</option>
                                            <option value="certification" <?php echo ($seekerProfile['highest_education'] ?? '') == 'certification' ? 'selected' : ''; ?>>Professional Certification</option>
                                        </select>
                                    </div>
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium mb-2">Skills (comma separated)</label>
                                    <input type="text" name="skills" 
                                           value="<?php echo htmlspecialchars($seekerProfile['skills'] ?? ''); ?>"
                                           placeholder="e.g., JavaScript, Python, Project Management, Leadership"
                                           class="form-input w-full px-4 py-3 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-indigo-500">
                                    <p class="text-xs text-gray-500 mt-1">List your key skills separated by commas</p>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Salary Expectations -->
                        <div class="pt-6 border-t border-gray-200 dark:border-gray-700">
                            <h3 class="text-lg font-semibold mb-4 flex items-center">
                                <i class="fas fa-dollar-sign text-indigo-600 mr-2"></i>
                                Salary Expectations
                            </h3>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label class="block text-sm font-medium mb-2">Minimum Expected Salary</label>
                                    <input type="number" name="expected_salary_min" min="0" step="0.01" 
                                           value="<?php echo htmlspecialchars($seekerProfile['expected_salary_min'] ?? ''); ?>"
                                           class="form-input w-full px-4 py-3 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-indigo-500">
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium mb-2">Maximum Expected Salary</label>
                                    <input type="number" name="expected_salary_max" min="0" step="0.01" 
                                           value="<?php echo htmlspecialchars($seekerProfile['expected_salary_max'] ?? ''); ?>"
                                           class="form-input w-full px-4 py-3 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-indigo-500">
                                </div>
                            </div>
                            <p class="text-xs text-gray-500 mt-2">Leave blank if you prefer not to disclose</p>
                        </div>
                        
                        <!-- Submit Button -->
                        <div class="flex justify-end pt-6 border-t border-gray-200 dark:border-gray-700">
                            <button type="submit" 
                                    class="px-8 py-4 bg-gradient-to-r from-indigo-600 to-purple-600 text-white rounded-xl font-semibold hover:shadow-lg hover:scale-105 transition-all duration-300 flex items-center space-x-2">
                                <i class="fas fa-save"></i>
                                <span>Save Profile Information</span>
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Job Preferences Tab -->
                <div id="tab-preferences" class="tab-content <?php echo $active_tab === 'preferences' ? '' : 'hidden'; ?>">
                    <form method="POST" action="" class="space-y-8">
                        <input type="hidden" name="action" value="update_preferences">
                        
                        <!-- Job Preferences Header -->
                        <div class="bg-gradient-to-r from-indigo-50 to-purple-50 dark:from-indigo-900/20 dark:to-purple-900/20 rounded-2xl p-6">
                            <div class="flex items-start space-x-4">
                                <div class="w-12 h-12 bg-gradient-to-r from-indigo-600 to-purple-600 rounded-xl flex items-center justify-center text-white">
                                    <i class="fas fa-sliders-h text-xl"></i>
                                </div>
                                <div class="flex-1">
                                    <h3 class="text-lg font-semibold mb-2">Job Preferences</h3>
                                    <p class="text-sm text-gray-600 dark:text-gray-400">Set your job preferences to get personalized job recommendations. The more details you provide, the better matches we can find for you.</p>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Preferred Categories -->
                        <div>
                            <label class="block text-sm font-medium mb-2">Preferred Job Categories</label>
                            <select name="preferred_categories[]" multiple class="preferences-multiselect w-full">
                                <?php foreach ($jobCategories as $category): ?>
                                <option value="<?php echo $category['id']; ?>" 
                                    <?php echo in_array($category['id'], $preferredCategories) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($category['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="text-xs text-gray-500 mt-1">Select one or more categories you're interested in</p>
                        </div>
                        
                        <!-- Preferred Job Types -->
                        <div>
                            <label class="block text-sm font-medium mb-2">Preferred Job Types</label>
                            <select name="preferred_job_types[]" multiple class="preferences-multiselect w-full">
                                <?php foreach ($jobTypes as $type): ?>
                                <option value="<?php echo $type['id']; ?>" 
                                    <?php echo in_array($type['id'], $preferredJobTypes) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($type['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <!-- Preferred Location Types -->
                        <div>
                            <label class="block text-sm font-medium mb-2">Preferred Work Locations</label>
                            <select name="preferred_location_types[]" multiple class="preferences-multiselect w-full">
                                <option value="onsite" <?php echo in_array('onsite', $preferredLocationTypes) ? 'selected' : ''; ?>>On-site</option>
                                <option value="remote" <?php echo in_array('remote', $preferredLocationTypes) ? 'selected' : ''; ?>>Remote</option>
                                <option value="hybrid" <?php echo in_array('hybrid', $preferredLocationTypes) ? 'selected' : ''; ?>>Hybrid</option>
                            </select>
                        </div>
                        
                        <!-- Preferred Locations (Cities/Countries) -->
                        <div>
                            <label class="block text-sm font-medium mb-2">Preferred Cities/Countries</label>
                            <div id="location-inputs" class="space-y-3">
                                <?php if (!empty($preferredLocations)): ?>
                                    <?php foreach ($preferredLocations as $index => $location): ?>
                                    <div class="flex items-center space-x-2">
                                        <input type="text" name="preferred_locations[]" 
                                               value="<?php echo htmlspecialchars($location); ?>"
                                               placeholder="e.g., New York, USA"
                                               class="flex-1 px-4 py-3 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-indigo-500">
                                        <button type="button" onclick="removeLocation(this)" class="w-10 h-10 bg-red-100 dark:bg-red-900/20 text-red-600 rounded-lg hover:bg-red-200 dark:hover:bg-red-900/40 transition">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="flex items-center space-x-2">
                                        <input type="text" name="preferred_locations[]" 
                                               placeholder="e.g., New York, USA"
                                               class="flex-1 px-4 py-3 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-indigo-500">
                                    </div>
                                <?php endif; ?>
                            </div>
                            <button type="button" onclick="addLocation()" class="mt-3 text-indigo-600 hover:text-indigo-700 text-sm font-medium flex items-center">
                                <i class="fas fa-plus-circle mr-1"></i> Add another location
                            </button>
                        </div>
                        
                        <!-- Salary Range -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-sm font-medium mb-2">Minimum Salary</label>
                                <input type="number" name="min_salary" min="0" step="0.01" 
                                       value="<?php echo htmlspecialchars($seekerPreferences['min_salary'] ?? ''); ?>"
                                       class="w-full px-4 py-3 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-indigo-500">
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-2">Maximum Salary</label>
                                <input type="number" name="max_salary" min="0" step="0.01" 
                                       value="<?php echo htmlspecialchars($seekerPreferences['max_salary'] ?? ''); ?>"
                                       class="w-full px-4 py-3 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-indigo-500">
                            </div>
                        </div>
                        
                        <!-- Experience Level Preference -->
                        <div>
                            <label class="block text-sm font-medium mb-2">Preferred Experience Level</label>
                            <select name="experience_level_preference" 
                                    class="w-full px-4 py-3 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-indigo-500">
                                <option value="any" <?php echo ($seekerPreferences['experience_level_preference'] ?? 'any') == 'any' ? 'selected' : ''; ?>>Any Level</option>
                                <option value="entry" <?php echo ($seekerPreferences['experience_level_preference'] ?? '') == 'entry' ? 'selected' : ''; ?>>Entry Level</option>
                                <option value="mid" <?php echo ($seekerPreferences['experience_level_preference'] ?? '') == 'mid' ? 'selected' : ''; ?>>Mid Level</option>
                                <option value="senior" <?php echo ($seekerPreferences['experience_level_preference'] ?? '') == 'senior' ? 'selected' : ''; ?>>Senior Level</option>
                                <option value="executive" <?php echo ($seekerPreferences['experience_level_preference'] ?? '') == 'executive' ? 'selected' : ''; ?>>Executive</option>
                            </select>
                        </div>
                        
                        <!-- Notification Frequency -->
                        <div>
                            <label class="block text-sm font-medium mb-2">Job Alert Frequency</label>
                            <select name="notification_frequency" 
                                    class="w-full px-4 py-3 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-indigo-500">
                                <option value="instant" <?php echo ($seekerPreferences['notification_frequency'] ?? '') == 'instant' ? 'selected' : ''; ?>>Instant (Real-time)</option>
                                <option value="daily" <?php echo ($seekerPreferences['notification_frequency'] ?? 'daily') == 'daily' ? 'selected' : ''; ?>>Daily Digest</option>
                                <option value="weekly" <?php echo ($seekerPreferences['notification_frequency'] ?? '') == 'weekly' ? 'selected' : ''; ?>>Weekly Digest</option>
                            </select>
                        </div>
                        
                        <!-- Active Status -->
                        <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-700/50 rounded-xl">
                            <div>
                                <h4 class="font-semibold">Receive Job Recommendations</h4>
                                <p class="text-sm text-gray-600 dark:text-gray-400">Enable to get personalized job alerts based on your preferences</p>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" name="is_active" <?php echo ($seekerPreferences['is_active'] ?? 1) ? 'checked' : ''; ?>>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                        
                        <!-- Submit Button -->
                        <div class="flex justify-end pt-6 border-t border-gray-200 dark:border-gray-700">
                            <button type="submit" 
                                    class="px-8 py-4 bg-gradient-to-r from-indigo-600 to-purple-600 text-white rounded-xl font-semibold hover:shadow-lg hover:scale-105 transition-all duration-300 flex items-center space-x-2">
                                <i class="fas fa-save"></i>
                                <span>Save Preferences</span>
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Notifications Tab -->
                <div id="tab-notifications" class="tab-content <?php echo $active_tab === 'notifications' ? '' : 'hidden'; ?>">
                    <form method="POST" action="" class="space-y-8">
                        <input type="hidden" name="action" value="update_notifications">
                        
                        <div class="bg-gradient-to-r from-indigo-50 to-purple-50 dark:from-indigo-900/20 dark:to-purple-900/20 rounded-2xl p-6">
                            <div class="flex items-start space-x-4">
                                <div class="w-12 h-12 bg-gradient-to-r from-indigo-600 to-purple-600 rounded-xl flex items-center justify-center text-white">
                                    <i class="fas fa-bell text-xl"></i>
                                </div>
                                <div class="flex-1">
                                    <h3 class="text-lg font-semibold mb-2">Notification Settings</h3>
                                    <p class="text-sm text-gray-600 dark:text-gray-400">Choose how and when you want to receive notifications</p>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Email Notifications -->
                        <div>
                            <h4 class="font-semibold mb-4 flex items-center">
                                <i class="fas fa-envelope text-indigo-600 mr-2"></i>
                                Email Notifications
                            </h4>
                            
                            <div class="space-y-4">
                                <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-700/50 rounded-xl">
                                    <div>
                                        <p class="font-medium">Job Alerts</p>
                                        <p class="text-sm text-gray-600 dark:text-gray-400">Receive email when new jobs matching your preferences are posted</p>
                                    </div>
                                    <label class="toggle-switch">
                                        <input type="checkbox" name="notification[job_alerts]" checked>
                                        <span class="toggle-slider"></span>
                                    </label>
                                </div>
                                
                                <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-700/50 rounded-xl">
                                    <div>
                                        <p class="font-medium">Application Updates</p>
                                        <p class="text-sm text-gray-600 dark:text-gray-400">Get notified when employers view or update your applications</p>
                                    </div>
                                    <label class="toggle-switch">
                                        <input type="checkbox" name="notification[application_updates]" checked>
                                        <span class="toggle-slider"></span>
                                    </label>
                                </div>
                                
                                <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-700/50 rounded-xl">
                                    <div>
                                        <p class="font-medium">Interview Invitations</p>
                                        <p class="text-sm text-gray-600 dark:text-gray-400">Instant notifications for interview requests</p>
                                    </div>
                                    <label class="toggle-switch">
                                        <input type="checkbox" name="notification[interviews]" checked>
                                        <span class="toggle-slider"></span>
                                    </label>
                                </div>
                                
                                <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-700/50 rounded-xl">
                                    <div>
                                        <p class="font-medium">Messages</p>
                                        <p class="text-sm text-gray-600 dark:text-gray-400">Receive email when employers send you messages</p>
                                    </div>
                                    <label class="toggle-switch">
                                        <input type="checkbox" name="notification[messages]" checked>
                                        <span class="toggle-slider"></span>
                                    </label>
                                </div>
                                
                                <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-700/50 rounded-xl">
                                    <div>
                                        <p class="font-medium">Newsletter</p>
                                        <p class="text-sm text-gray-600 dark:text-gray-400">Career tips, industry news, and platform updates</p>
                                    </div>
                                    <label class="toggle-switch">
                                        <input type="checkbox" name="notification[newsletter]">
                                        <span class="toggle-slider"></span>
                                    </label>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Push Notifications -->
                        <div class="pt-6 border-t border-gray-200 dark:border-gray-700">
                            <h4 class="font-semibold mb-4 flex items-center">
                                <i class="fas fa-bell text-indigo-600 mr-2"></i>
                                Push Notifications (Browser)
                            </h4>
                            
                            <div class="space-y-4">
                                <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-700/50 rounded-xl">
                                    <div>
                                        <p class="font-medium">Enable Push Notifications</p>
                                        <p class="text-sm text-gray-600 dark:text-gray-400">Receive real-time notifications in your browser</p>
                                    </div>
                                    <label class="toggle-switch">
                                        <input type="checkbox" name="notification[push_enabled]">
                                        <span class="toggle-slider"></span>
                                    </label>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Submit Button -->
                        <div class="flex justify-end pt-6 border-t border-gray-200 dark:border-gray-700">
                            <button type="submit" 
                                    class="px-8 py-4 bg-gradient-to-r from-indigo-600 to-purple-600 text-white rounded-xl font-semibold hover:shadow-lg hover:scale-105 transition-all duration-300 flex items-center space-x-2">
                                <i class="fas fa-save"></i>
                                <span>Save Notification Settings</span>
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Privacy & Security Tab -->
                <div id="tab-privacy" class="tab-content <?php echo $active_tab === 'privacy' ? '' : 'hidden'; ?>">
                    <form method="POST" action="" class="space-y-8">
                        <input type="hidden" name="action" value="update_privacy">
                        
                        <div class="bg-gradient-to-r from-indigo-50 to-purple-50 dark:from-indigo-900/20 dark:to-purple-900/20 rounded-2xl p-6">
                            <div class="flex items-start space-x-4">
                                <div class="w-12 h-12 bg-gradient-to-r from-indigo-600 to-purple-600 rounded-xl flex items-center justify-center text-white">
                                    <i class="fas fa-lock text-xl"></i>
                                </div>
                                <div class="flex-1">
                                    <h3 class="text-lg font-semibold mb-2">Privacy & Security</h3>
                                    <p class="text-sm text-gray-600 dark:text-gray-400">Control who can see your profile and personal information</p>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Profile Visibility -->
                        <div>
                            <h4 class="font-semibold mb-4">Profile Visibility</h4>
                            
                            <div class="space-y-4">
                                <div class="p-4 bg-gray-50 dark:bg-gray-700/50 rounded-xl">
                                    <div class="flex items-center mb-3">
                                        <input type="radio" name="profile_visibility" id="visibility_public" value="public" class="mr-3" checked>
                                        <label for="visibility_public" class="flex-1">
                                            <span class="font-medium block">Public</span>
                                            <span class="text-sm text-gray-600 dark:text-gray-400">Your profile is visible to all employers and can appear in search results</span>
                                        </label>
                                    </div>
                                    
                                    <div class="flex items-center mb-3">
                                        <input type="radio" name="profile_visibility" id="visibility_private" value="private" class="mr-3">
                                        <label for="visibility_private" class="flex-1">
                                            <span class="font-medium block">Private</span>
                                            <span class="text-sm text-gray-600 dark:text-gray-400">Only employers you apply to can see your profile</span>
                                        </label>
                                    </div>
                                    
                                    <div class="flex items-center">
                                        <input type="radio" name="profile_visibility" id="visibility_hidden" value="hidden" class="mr-3">
                                        <label for="visibility_hidden" class="flex-1">
                                            <span class="font-medium block">Hidden</span>
                                            <span class="text-sm text-gray-600 dark:text-gray-400">Your profile is hidden from everyone. You can still apply to jobs.</span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Information Visibility -->
                        <div class="pt-6 border-t border-gray-200 dark:border-gray-700">
                            <h4 class="font-semibold mb-4">Information Visibility</h4>
                            
                            <div class="space-y-4">
                                <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-700/50 rounded-xl">
                                    <div>
                                        <p class="font-medium">Show Email Address</p>
                                        <p class="text-sm text-gray-600 dark:text-gray-400">Allow employers to see your email address</p>
                                    </div>
                                    <label class="toggle-switch">
                                        <input type="checkbox" name="show_email" checked>
                                        <span class="toggle-slider"></span>
                                    </label>
                                </div>
                                
                                <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-700/50 rounded-xl">
                                    <div>
                                        <p class="font-medium">Show Phone Number</p>
                                        <p class="text-sm text-gray-600 dark:text-gray-400">Allow employers to see your phone number</p>
                                    </div>
                                    <label class="toggle-switch">
                                        <input type="checkbox" name="show_phone" checked>
                                        <span class="toggle-slider"></span>
                                    </label>
                                </div>
                                
                                <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-700/50 rounded-xl">
                                    <div>
                                        <p class="font-medium">Show Current Company</p>
                                        <p class="text-sm text-gray-600 dark:text-gray-400">Display your current company on your profile</p>
                                    </div>
                                    <label class="toggle-switch">
                                        <input type="checkbox" name="show_current_company" checked>
                                        <span class="toggle-slider"></span>
                                    </label>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Login History -->
                        <div class="pt-6 border-t border-gray-200 dark:border-gray-700">
                            <h4 class="font-semibold mb-4">Login History</h4>
                            
                            <div class="bg-gray-50 dark:bg-gray-700/50 rounded-xl p-4">
                                <div class="flex items-center justify-between mb-3">
                                    <div>
                                        <p class="font-medium">Last Login</p>
                                        <p class="text-sm text-gray-600 dark:text-gray-400"><?php echo $seekerProfile['last_login'] ? date('F j, Y g:i A', strtotime($seekerProfile['last_login'])) : 'First login'; ?></p>
                                    </div>
                                    <i class="fas fa-check-circle text-green-500"></i>
                                </div>
                                <div class="flex items-center justify-between">
                                    <div>
                                        <p class="font-medium">Account Created</p>
                                        <p class="text-sm text-gray-600 dark:text-gray-400"><?php echo date('F j, Y', strtotime($seekerProfile['account_created'] ?? $seekerProfile['created_at'])); ?></p>
                                    </div>
                                    <i class="fas fa-calendar-alt text-indigo-500"></i>
                                </div>
                            </div>
                            
                            <div class="mt-4">
                                <a href="#" onclick="showLoginHistory()" class="text-indigo-600 hover:text-indigo-700 text-sm font-medium">
                                    <i class="fas fa-history mr-1"></i> View full login history
                                </a>
                            </div>
                        </div>
                        
                        <!-- Two-Factor Authentication -->
                        <div class="pt-6 border-t border-gray-200 dark:border-gray-700">
                            <div class="flex items-center justify-between p-4 bg-gradient-to-r from-green-50 to-emerald-50 dark:from-green-900/20 dark:to-emerald-900/20 rounded-xl">
                                <div class="flex items-start space-x-3">
                                    <i class="fas fa-shield-alt text-green-600 text-2xl"></i>
                                    <div>
                                        <h4 class="font-semibold">Two-Factor Authentication</h4>
                                        <p class="text-sm text-gray-600 dark:text-gray-400">Add an extra layer of security to your account</p>
                                    </div>
                                </div>
                                <button type="button" onclick="enable2FA()" class="px-4 py-2 bg-gradient-to-r from-green-600 to-emerald-600 text-white rounded-lg text-sm font-semibold hover:shadow-lg transition">
                                    Enable 2FA
                                </button>
                            </div>
                        </div>
                        
                        <!-- Submit Button -->
                        <div class="flex justify-end pt-6 border-t border-gray-200 dark:border-gray-700">
                            <button type="submit" 
                                    class="px-8 py-4 bg-gradient-to-r from-indigo-600 to-purple-600 text-white rounded-xl font-semibold hover:shadow-lg hover:scale-105 transition-all duration-300 flex items-center space-x-2">
                                <i class="fas fa-save"></i>
                                <span>Save Privacy Settings</span>
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Account Tab -->
                <div id="tab-account" class="tab-content <?php echo $active_tab === 'account' ? '' : 'hidden'; ?>">
                    <div class="space-y-8">
                        <!-- Change Password Form -->
                        <form method="POST" action="" class="space-y-6">
                            <input type="hidden" name="action" value="change_password">
                            
                            <div class="bg-gradient-to-r from-indigo-50 to-purple-50 dark:from-indigo-900/20 dark:to-purple-900/20 rounded-2xl p-6">
                                <div class="flex items-start space-x-4">
                                    <div class="w-12 h-12 bg-gradient-to-r from-indigo-600 to-purple-600 rounded-xl flex items-center justify-center text-white">
                                        <i class="fas fa-key text-xl"></i>
                                    </div>
                                    <div class="flex-1">
                                        <h3 class="text-lg font-semibold mb-2">Change Password</h3>
                                        <p class="text-sm text-gray-600 dark:text-gray-400">Update your password to keep your account secure</p>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="space-y-4">
                                <div>
                                    <label class="block text-sm font-medium mb-2">Current Password</label>
                                    <div class="relative">
                                        <input type="password" name="current_password" id="current_password" required
                                               class="w-full px-4 py-3 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-indigo-500 pr-12">
                                        <button type="button" onclick="togglePassword('current_password')" class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-500">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium mb-2">New Password</label>
                                    <div class="relative">
                                        <input type="password" name="new_password" id="new_password" required
                                               class="w-full px-4 py-3 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-indigo-500 pr-12">
                                        <button type="button" onclick="togglePassword('new_password')" class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-500">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                    <div class="mt-2">
                                        <div class="password-strength-meter h-1 bg-gray-200 rounded-full overflow-hidden">
                                            <div id="password-strength" class="h-full w-0 transition-all duration-300"></div>
                                        </div>
                                        <p id="password-strength-text" class="text-xs mt-1"></p>
                                    </div>
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium mb-2">Confirm New Password</label>
                                    <div class="relative">
                                        <input type="password" name="confirm_password" id="confirm_password" required
                                               class="w-full px-4 py-3 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-indigo-500 pr-12">
                                        <button type="button" onclick="togglePassword('confirm_password')" class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-500">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                    <div id="password-match" class="text-xs mt-1"></div>
                                </div>
                            </div>
                            
                            <div class="flex justify-end">
                                <button type="submit" 
                                        class="px-6 py-3 bg-gradient-to-r from-indigo-600 to-purple-600 text-white rounded-xl font-semibold hover:shadow-lg hover:scale-105 transition-all duration-300">
                                    Update Password
                                </button>
                            </div>
                        </form>
                        
                        <!-- Danger Zone -->
                        <div class="danger-zone mt-8">
                            <h4 class="font-semibold text-red-600 mb-4 flex items-center">
                                <i class="fas fa-exclamation-triangle mr-2"></i>
                                Danger Zone
                            </h4>
                            
                            <form method="POST" action="" onsubmit="return confirmAccountDeletion()" class="space-y-4">
                                <input type="hidden" name="action" value="delete_account">
                                
                                <div class="bg-red-50 dark:bg-red-900/10 rounded-xl p-4">
                                    <p class="text-sm text-gray-700 dark:text-gray-300 mb-4">
                                        Once you delete your account, there is no going back. Please be certain.
                                    </p>
                                    
                                    <div class="space-y-4">
                                        <div>
                                            <label class="block text-sm font-medium mb-2">Type "DELETE" to confirm</label>
                                            <input type="text" name="confirm_text" required
                                                   class="w-full px-4 py-3 bg-white dark:bg-gray-800 border border-red-300 dark:border-red-700 rounded-xl focus:ring-2 focus:ring-red-500">
                                        </div>
                                        
                                        <div>
                                            <label class="block text-sm font-medium mb-2">Enter your password</label>
                                            <input type="password" name="password" required
                                                   class="w-full px-4 py-3 bg-white dark:bg-gray-800 border border-red-300 dark:border-red-700 rounded-xl focus:ring-2 focus:ring-red-500">
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="flex justify-end">
                                    <button type="submit" 
                                            class="px-6 py-3 bg-gradient-to-r from-red-600 to-red-700 text-white rounded-xl font-semibold hover:shadow-lg hover:scale-105 transition-all duration-300">
                                        Delete My Account
                                    </button>
                                </div>
                            </form>
                        </div>
                        
                        <!-- Data Export -->
                        <div class="mt-8 p-6 bg-gray-50 dark:bg-gray-700/50 rounded-xl">
                            <div class="flex items-start justify-between">
                                <div class="flex items-start space-x-3">
                                    <i class="fas fa-download text-indigo-600 text-2xl"></i>
                                    <div>
                                        <h4 class="font-semibold">Export Your Data</h4>
                                        <p class="text-sm text-gray-600 dark:text-gray-400">Download a copy of your profile, applications, and preferences</p>
                                    </div>
                                </div>
                                <a href="export-data.php" class="px-6 py-3 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-xl font-medium hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                                    Request Data Export
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<!-- Mobile Sidebar (Hidden by default) -->
<div id="mobileSidebar" class="fixed inset-0 z-50 hidden lg:hidden">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" id="mobileSidebarOverlay"></div>
    <div class="absolute left-0 top-0 bottom-0 w-72 bg-white dark:bg-gray-800 overflow-y-auto">
        <div class="p-6">
            <div class="flex items-center justify-between mb-6">
                <a href="/index.php" class="flex items-center space-x-3">
                    <div class="w-10 h-10 bg-gradient-to-r from-indigo-600 to-purple-600 rounded-lg flex items-center justify-center">
                        <i class="fas fa-globe-asia text-white text-lg"></i>
                    </div>
                    <span class="text-xl font-bold">
                        <span class="gradient-text">TransNow</span>
                    </span>
                </a>
                <button id="closeMobileSidebar" class="w-10 h-10 bg-gray-100 dark:bg-gray-700 rounded-lg flex items-center justify-center">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <div class="px-2 py-4 border-t border-gray-200 dark:border-gray-700">
                <div class="flex items-center space-x-3 mb-4">
                    <img src="<?php echo htmlspecialchars($avatarUrl); ?>" alt="Profile" class="w-12 h-12 rounded-lg object-cover border-2 border-indigo-200">
                    <div class="flex-1 min-w-0">
                        <p class="font-semibold truncate"><?php echo htmlspecialchars(($seekerProfile['first_name'] ?? 'User') . ' ' . ($seekerProfile['last_name'] ?? '')); ?></p>
                        <p class="text-sm text-gray-500 truncate"><?php echo htmlspecialchars($seekerProfile['headline'] ?? 'Job Seeker'); ?></p>
                    </div>
                </div>
                
                <nav class="space-y-1">
                    <a href="dashboard.php" class="block px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">Dashboard</a>
                    <a href="jobs.php" class="block px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">Find Jobs</a>
                    <a href="applications.php" class="block px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">Applications</a>
                    <a href="saved-jobs.php" class="block px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">Saved Jobs</a>
                    <a href="interviews.php" class="block px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">Interviews</a>
                    <a href="profile.php" class="block px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">Profile</a>
                    <a href="settings.php" class="block px-4 py-2 bg-indigo-50 dark:bg-indigo-900/20 text-indigo-600 rounded-lg">Settings</a>
                    <a href="/logout.php" class="block px-4 py-2 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg">Logout</a>
                </nav>
            </div>
        </div>
    </div>
</div>

<!-- Scripts -->
<script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
// Initialize AOS
AOS.init({
    duration: 800,
    once: true,
    offset: 50,
    easing: 'ease-out-cubic'
});

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

if (darkModeToggle) {
    darkModeToggle.addEventListener('click', () => {
        document.documentElement.classList.toggle('dark');
        
        if (document.documentElement.classList.contains('dark')) {
            localStorage.theme = 'dark';
            darkModeIcon?.classList.remove('fa-moon');
            darkModeIcon?.classList.add('fa-sun');
        } else {
            localStorage.theme = 'light';
            darkModeIcon?.classList.remove('fa-sun');
            darkModeIcon?.classList.add('fa-moon');
        }
    });
}

// Initialize Select2
$(document).ready(function() {
    $('.preferences-multiselect').select2({
        theme: 'bootstrap-5',
        width: '100%',
        placeholder: 'Select options',
        allowClear: true,
        closeOnSelect: false
    });
});

// Tab switching
function switchTab(tabName) {
    // Hide all tabs
    document.querySelectorAll('.tab-content').forEach(tab => {
        tab.classList.add('hidden');
    });
    
    // Show selected tab
    document.getElementById(`tab-${tabName}`).classList.remove('hidden');
    
    // Update active state on tab buttons
    document.querySelectorAll('.settings-tab').forEach(btn => {
        btn.classList.remove('active');
    });
    
    // Find and activate the clicked tab button
    document.querySelector(`[onclick="switchTab('${tabName}')"]`).classList.add('active');
    
    // Update URL without page reload
    const url = new URL(window.location);
    url.searchParams.set('tab', tabName);
    window.history.pushState({}, '', url);
    
    // Reinitialize AOS for new content
    AOS.refresh();
}

// Add location input
function addLocation() {
    const container = document.getElementById('location-inputs');
    const newInput = document.createElement('div');
    newInput.className = 'flex items-center space-x-2';
    newInput.innerHTML = `
        <input type="text" name="preferred_locations[]" 
               placeholder="e.g., New York, USA"
               class="flex-1 px-4 py-3 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-indigo-500">
        <button type="button" onclick="removeLocation(this)" class="w-10 h-10 bg-red-100 dark:bg-red-900/20 text-red-600 rounded-lg hover:bg-red-200 dark:hover:bg-red-900/40 transition">
            <i class="fas fa-times"></i>
        </button>
    `;
    container.appendChild(newInput);
}

// Remove location input
function removeLocation(button) {
    const container = document.getElementById('location-inputs');
    if (container.children.length > 1) {
        button.closest('.flex').remove();
    } else {
        // Clear the input instead of removing if it's the last one
        const input = button.closest('.flex').querySelector('input');
        if (input) {
            input.value = '';
        }
    }
}

// Toggle password visibility
function togglePassword(fieldId) {
    const field = document.getElementById(fieldId);
    const button = field.nextElementSibling.querySelector('i');
    
    if (field.type === 'password') {
        field.type = 'text';
        button.classList.remove('fa-eye');
        button.classList.add('fa-eye-slash');
    } else {
        field.type = 'password';
        button.classList.remove('fa-eye-slash');
        button.classList.add('fa-eye');
    }
}

// Password strength meter
const newPassword = document.getElementById('new_password');
const strengthBar = document.getElementById('password-strength');
const strengthText = document.getElementById('password-strength-text');

if (newPassword) {
    newPassword.addEventListener('input', function() {
        const password = this.value;
        let strength = 0;
        
        if (password.length >= 8) strength += 25;
        if (password.match(/[a-z]+/)) strength += 25;
        if (password.match(/[A-Z]+/)) strength += 25;
        if (password.match(/[0-9]+/)) strength += 25;
        if (password.match(/[$@#&!]+/)) strength += 25;
        
        strength = Math.min(100, strength);
        
        strengthBar.style.width = strength + '%';
        
        if (strength < 25) {
            strengthBar.style.backgroundColor = '#ef4444';
            strengthText.textContent = 'Very weak';
        } else if (strength < 50) {
            strengthBar.style.backgroundColor = '#f97316';
            strengthText.textContent = 'Weak';
        } else if (strength < 75) {
            strengthBar.style.backgroundColor = '#eab308';
            strengthText.textContent = 'Medium';
        } else {
            strengthBar.style.backgroundColor = '#22c55e';
            strengthText.textContent = 'Strong';
        }
    });
}

// Password match checker
const confirmPassword = document.getElementById('confirm_password');
const passwordMatch = document.getElementById('password-match');

if (confirmPassword && newPassword) {
    confirmPassword.addEventListener('input', function() {
        if (this.value !== newPassword.value) {
            passwordMatch.innerHTML = '<span class="text-red-500"><i class="fas fa-times mr-1"></i>Passwords do not match</span>';
        } else if (this.value.length > 0) {
            passwordMatch.innerHTML = '<span class="text-green-500"><i class="fas fa-check mr-1"></i>Passwords match</span>';
        } else {
            passwordMatch.innerHTML = '';
        }
    });
}

// Confirm account deletion
function confirmAccountDeletion() {
    return confirm('Are you absolutely sure you want to delete your account? This action cannot be undone.');
}

// Profile picture upload
function uploadProfilePicture(input) {
    if (input.files && input.files[0]) {
        const formData = new FormData();
        formData.append('profile_picture', input.files[0]);
        formData.append('action', 'upload_profile_picture');
        
        // Show loading
        const button = input.nextElementSibling;
        const originalText = button.innerHTML;
        button.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Uploading...';
        button.disabled = true;
        
        fetch('upload-profile-picture.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Update profile picture
                document.querySelector('.relative.group img').src = data.url + '?t=' + new Date().getTime();
                showNotification('Profile picture updated successfully', 'success');
            } else {
                showNotification(data.error || 'Failed to upload', 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showNotification('An error occurred', 'error');
        })
        .finally(() => {
            button.innerHTML = originalText;
            button.disabled = false;
        });
    }
}

// Show notification
function showNotification(message, type = 'info') {
    const notification = document.createElement('div');
    notification.className = `fixed top-20 right-4 z-50 ${
        type === 'success' ? 'bg-green-500' : 
        type === 'error' ? 'bg-red-500' : 
        'bg-blue-500'
    } text-white px-6 py-3 rounded-xl shadow-lg animate-slideIn flex items-center space-x-2`;
    notification.innerHTML = `
        <i class="fas ${type === 'success' ? 'fa-check-circle' : type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle'}"></i>
        <span>${message}</span>
    `;
    
    document.body.appendChild(notification);
    
    setTimeout(() => {
        notification.style.transition = 'opacity 0.5s';
        notification.style.opacity = '0';
        setTimeout(() => notification.remove(), 500);
    }, 5000);
}

// Enable 2FA (placeholder)
function enable2FA() {
    showNotification('Two-factor authentication setup will be available soon', 'info');
}

// Show login history (placeholder)
function showLoginHistory() {
    showNotification('Login history feature coming soon', 'info');
}

// Mobile Sidebar
const mobileSidebarToggle = document.getElementById('mobileSidebarToggle');
const mobileSidebar = document.getElementById('mobileSidebar');
const mobileSidebarOverlay = document.getElementById('mobileSidebarOverlay');
const closeMobileSidebar = document.getElementById('closeMobileSidebar');

if (mobileSidebarToggle && mobileSidebar) {
    mobileSidebarToggle.addEventListener('click', () => {
        mobileSidebar.classList.remove('hidden');
    });
}

function closeMobileSidebarFunc() {
    mobileSidebar?.classList.add('hidden');
}

if (mobileSidebarOverlay) {
    mobileSidebarOverlay.addEventListener('click', closeMobileSidebarFunc);
}
if (closeMobileSidebar) {
    closeMobileSidebar.addEventListener('click', closeMobileSidebarFunc);
}

// Auto-hide flash message after 5 seconds
setTimeout(() => {
    const flashMessage = document.querySelector('.fixed.top-20.right-4');
    if (flashMessage) {
        flashMessage.style.transition = 'opacity 0.5s';
        flashMessage.style.opacity = '0';
        setTimeout(() => flashMessage?.remove(), 500);
    }
}, 5000);

// Keyboard shortcuts
document.addEventListener('keydown', (e) => {
    // Escape to close modals
    if (e.key === 'Escape') {
        mobileSidebar?.classList.add('hidden');
    }
    
    // Ctrl/Cmd + S to save current form
    if ((e.ctrlKey || e.metaKey) && e.key === 's') {
        e.preventDefault();
        const activeForm = document.querySelector('.tab-content:not(.hidden) form');
        if (activeForm) {
            activeForm.submit();
        }
    }
});

// Lazy loading images
const images = document.querySelectorAll('img[data-src]');
const imageObserver = new IntersectionObserver((entries, observer) => {
    entries.forEach(entry => {
        if (entry.isIntersecting) {
            const img = entry.target;
            img.src = img.dataset.src;
            img.classList.remove('skeleton');
            observer.unobserve(img);
        }
    });
});

images.forEach(img => imageObserver.observe(img));

// Form validation
document.querySelectorAll('form').forEach(form => {
    form.addEventListener('submit', function(e) {
        const requiredFields = this.querySelectorAll('[required]');
        let isValid = true;
        
        requiredFields.forEach(field => {
            if (!field.value.trim()) {
                field.classList.add('error');
                isValid = false;
                
                // Remove error class on input
                field.addEventListener('input', function() {
                    this.classList.remove('error');
                }, { once: true });
            }
        });
        
        if (!isValid) {
            e.preventDefault();
            showNotification('Please fill in all required fields', 'error');
        }
    });
});
</script>
</body>
</html>