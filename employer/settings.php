<?php
// employer/settings.php
// Professional Employer Settings with PDO implementation

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../includes/auth.php';

// Require employer login
requireEmployerLogin();

// Get database connection
$pdo = getDB();
$employer_id = getCurrentEmployerId($pdo);
$user_id = $_SESSION['user_id'];

// Get employer and user info
$employer = fetchOne("SELECT e.*, u.email, u.username, u.created_at as account_created,
                      u.last_login, u.is_verified
                      FROM employers e 
                      JOIN users u ON e.user_id = u.id 
                      WHERE e.id = ?", [$employer_id]);

// Get employer preferences
$preferences = fetchOne("SELECT * FROM employer_preferences WHERE employer_id = ?", [$employer_id]);

// If no preferences exist, create default
if (!$preferences) {
    executeQuery(
        "INSERT INTO employer_preferences (employer_id, experience_range_min, experience_range_max, is_active) 
         VALUES (?, 0, 30, 1)",
        [$employer_id]
    );
    $preferences = fetchOne("SELECT * FROM employer_preferences WHERE employer_id = ?", [$employer_id]);
}

// Get all categories for preferences
$categories = fetchAll("SELECT id, name FROM job_categories WHERE is_active = 1 ORDER BY name");

// Get all job types
$job_types = fetchAll("SELECT id, name FROM job_types WHERE is_active = 1 ORDER BY name");

// Handle form submissions
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Update profile settings
    if (isset($_POST['update_profile'])) {
        $company_name = sanitize($_POST['company_name']);
        $company_email = sanitize($_POST['company_email']);
        $company_phone = sanitize($_POST['company_phone']);
        $company_website = sanitize($_POST['company_website']);
        $industry = sanitize($_POST['industry']);
        $company_size = sanitize($_POST['company_size']);
        $tax_id = sanitize($_POST['tax_id']);
        $address = sanitize($_POST['address']);
        $city = sanitize($_POST['city']);
        $country = sanitize($_POST['country']);
        
        // Handle logo upload
        $company_logo = $employer['company_logo'];
        if (isset($_FILES['company_logo']) && $_FILES['company_logo']['error'] === UPLOAD_ERR_OK) {
            $upload_result = uploadFile($_FILES['company_logo'], 'logos', ['image/jpeg', 'image/png', 'image/gif'], 2 * 1024 * 1024);
            if ($upload_result['success']) {
                $company_logo = $upload_result['path'];
            } else {
                $message = 'Logo upload failed: ' . $upload_result['message'];
                $message_type = 'error';
            }
        }
        
        // Update employers table
        $updated = executeQuery(
            "UPDATE employers SET 
                company_name = ?, company_email = ?, company_phone = ?, 
                company_website = ?, industry = ?, company_size = ?,
                tax_id = ?, address = ?, city = ?, country = ?, company_logo = ?
            WHERE id = ?",
            [
                $company_name, $company_email, $company_phone,
                $company_website, $industry, $company_size,
                $tax_id, $address, $city, $country, $company_logo,
                $employer_id
            ]
        );
        
        if ($updated) {
            $message = 'Profile settings updated successfully!';
            $message_type = 'success';
            logUserActivity('settings_update', 'Updated profile settings', $pdo);
            
            // Refresh employer data
            $employer = fetchOne("SELECT e.*, u.email, u.username, u.created_at as account_created,
                                 u.last_login, u.is_verified
                                 FROM employers e 
                                 JOIN users u ON e.user_id = u.id 
                                 WHERE e.id = ?", [$employer_id]);
        }
    }
    
    // Update notification settings
    if (isset($_POST['update_notifications'])) {
        $email_notifications = isset($_POST['email_notifications']) ? 1 : 0;
        $whatsapp_notifications = isset($_POST['whatsapp_notifications']) ? 1 : 0;
        $sms_notifications = isset($_POST['sms_notifications']) ? 1 : 0;
        $notify_new_applications = isset($_POST['notify_new_applications']) ? 1 : 0;
        $notify_application_updates = isset($_POST['notify_application_updates']) ? 1 : 0;
        $notify_messages = isset($_POST['notify_messages']) ? 1 : 0;
        $notify_job_expiry = isset($_POST['notify_job_expiry']) ? 1 : 0;
        $notify_candidate_matches = isset($_POST['notify_candidate_matches']) ? 1 : 0;
        
        // Store notification preferences in settings table or user meta
        // For this example, we'll use a simple approach with JSON in user meta
        // You may want to create a separate table for user preferences
        
        $notification_prefs = json_encode([
            'email_notifications' => $email_notifications,
            'whatsapp_notifications' => $whatsapp_notifications,
            'sms_notifications' => $sms_notifications,
            'notify_new_applications' => $notify_new_applications,
            'notify_application_updates' => $notify_application_updates,
            'notify_messages' => $notify_messages,
            'notify_job_expiry' => $notify_job_expiry,
            'notify_candidate_matches' => $notify_candidate_matches
        ]);
        
        // Check if setting exists
        $existing = fetchOne("SELECT id FROM settings WHERE setting_key = ?", ['employer_notifications_' . $employer_id]);
        
        if ($existing) {
            executeQuery(
                "UPDATE settings SET setting_value = ?, updated_at = NOW() WHERE setting_key = ?",
                [$notification_prefs, 'employer_notifications_' . $employer_id]
            );
        } else {
            executeQuery(
                "INSERT INTO settings (setting_key, setting_value, setting_type, category, description) 
                 VALUES (?, ?, 'json', 'employer_notifications', 'Employer notification preferences')",
                ['employer_notifications_' . $employer_id, $notification_prefs]
            );
        }
        
        $message = 'Notification settings updated successfully!';
        $message_type = 'success';
        logUserActivity('settings_update', 'Updated notification settings', $pdo);
    }
    
    // Update hiring preferences
    if (isset($_POST['update_hiring_prefs'])) {
        $required_categories = isset($_POST['required_categories']) ? json_encode($_POST['required_categories']) : null;
        $required_skills = sanitize($_POST['required_skills']);
        $skills_array = array_filter(array_map('trim', explode(',', $required_skills)));
        $required_skills_json = !empty($skills_array) ? json_encode($skills_array) : null;
        
        $experience_range_min = (int)$_POST['experience_range_min'];
        $experience_range_max = (int)$_POST['experience_range_max'];
        $education_level = sanitize($_POST['education_level']);
        $salary_budget_max = !empty($_POST['salary_budget_max']) ? (float)$_POST['salary_budget_max'] : null;
        $location_preference = sanitize($_POST['location_preference']);
        
        executeQuery(
            "UPDATE employer_preferences SET 
                required_categories = ?, required_skills = ?,
                experience_range_min = ?, experience_range_max = ?,
                education_level = ?, salary_budget_max = ?,
                location_preference = ?, updated_at = NOW()
            WHERE employer_id = ?",
            [
                $required_categories, $required_skills_json,
                $experience_range_min, $experience_range_max,
                $education_level, $salary_budget_max,
                $location_preference, $employer_id
            ]
        );
        
        $message = 'Hiring preferences updated successfully!';
        $message_type = 'success';
        logUserActivity('settings_update', 'Updated hiring preferences', $pdo);
        
        // Refresh preferences
        $preferences = fetchOne("SELECT * FROM employer_preferences WHERE employer_id = ?", [$employer_id]);
    }
    
    // Update security settings
    if (isset($_POST['update_security'])) {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        
        // Verify current password
        $user = fetchOne("SELECT password_hash FROM users WHERE id = ?", [$user_id]);
        
        if (password_verify($current_password, $user['password_hash'])) {
            if ($new_password === $confirm_password) {
                if (strlen($new_password) >= 8) {
                    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                    executeQuery("UPDATE users SET password_hash = ? WHERE id = ?", [$hashed_password, $user_id]);
                    
                    $message = 'Password updated successfully!';
                    $message_type = 'success';
                    logUserActivity('password_change', 'Changed account password', $pdo);
                } else {
                    $message = 'Password must be at least 8 characters long.';
                    $message_type = 'error';
                }
            } else {
                $message = 'New passwords do not match.';
                $message_type = 'error';
            }
        } else {
            $message = 'Current password is incorrect.';
            $message_type = 'error';
        }
    }
    
    // Toggle account status
    if (isset($_POST['deactivate_account'])) {
        $confirm = $_POST['confirm_deactivate'] ?? '';
        
        if ($confirm === 'DEACTIVATE') {
            executeQuery("UPDATE users SET is_active = 0 WHERE id = ?", [$user_id]);
            
            // Logout and redirect
            session_destroy();
            header('Location: ../login.php?msg=account_deactivated');
            exit();
        } else {
            $message = 'Please type DEACTIVATE to confirm.';
            $message_type = 'error';
        }
    }
}

// Get notification settings if they exist
$notification_settings = [];
$notification_prefs = fetchOne("SELECT setting_value FROM settings WHERE setting_key = ?", ['employer_notifications_' . $employer_id]);
if ($notification_prefs) {
    $notification_settings = json_decode($notification_prefs['setting_value'], true);
}

// Get unread notifications count
$unread_notifications = fetchOne("SELECT COUNT(*) as count 
                                  FROM notifications 
                                  WHERE user_id = ? AND channel = 'inapp' AND status = 'sent'", 
                                  [$user_id])['count'] ?? 0;

// Page title
$page_title = "Settings";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?> - JobPortal</title>
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    
    <!-- Custom Styles -->
    <style>
        .settings-sidebar {
            transition: all 0.3s ease;
        }
        .settings-sidebar a.active {
            background-color: #EBF5FF;
            border-color: #3B82F6;
            color: #1E40AF;
        }
        .settings-card {
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .settings-card:hover {
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1);
        }
        .animate-fadeIn {
            animation: fadeIn 0.3s ease-out forwards;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .toggle-checkbox:checked {
            right: 0;
            border-color: #3B82F6;
        }
        .toggle-checkbox:checked + .toggle-label {
            background-color: #3B82F6;
        }
    </style>
</head>
<body class="bg-gray-50">
    <!-- Navigation (same as dashboard) -->
    <nav class="bg-white shadow-lg border-b border-gray-200 fixed w-full z-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16">
                <div class="flex items-center">
                    <a href="dashboard.php" class="flex items-center space-x-3">
                        <i class="fas fa-briefcase text-2xl text-blue-600"></i>
                        <span class="font-bold text-xl text-gray-800">TRANSNOW</span>
                    </a>
                    <div class="hidden md:flex ml-10 space-x-8">
                        <a href="dashboard.php" class="text-gray-600 hover:text-blue-600 px-3 py-2 text-sm font-medium transition">Dashboard</a>
                        <a href="manage_jobs.php" class="text-gray-600 hover:text-blue-600 px-3 py-2 text-sm font-medium transition">My Jobs</a>
                        <a href="post-job.php" class="text-gray-600 hover:text-blue-600 px-3 py-2 text-sm font-medium transition">Post Job</a>
                        <a href="applications.php" class="text-gray-600 hover:text-blue-600 px-3 py-2 text-sm font-medium transition">Applications</a>
                        <a href="profile.php" class="text-gray-600 hover:text-blue-600 px-3 py-2 text-sm font-medium transition">Company Profile</a>
                    </div>
                </div>
                
                <div class="flex items-center space-x-4">
                    <!-- Notifications -->
                    <div class="relative">
                        <button id="notificationBtn" class="text-gray-600 hover:text-blue-600 focus:outline-none relative">
                            <i class="fas fa-bell text-xl"></i>
                            <?php if ($unread_notifications > 0): ?>
                                <span class="absolute -top-1 -right-1 bg-red-500 text-white text-xs rounded-full h-4 w-4 flex items-center justify-center">
                                    <?= min($unread_notifications, 9) . ($unread_notifications > 9 ? '+' : '') ?>
                                </span>
                            <?php endif; ?>
                        </button>
                        
                        <!-- Notification Dropdown -->
                        <div id="notificationDropdown" class="hidden absolute right-0 mt-2 w-80 bg-white rounded-lg shadow-xl border border-gray-200 z-50">
                            <div class="p-4 border-b border-gray-200">
                                <h3 class="font-semibold text-gray-800">Notifications</h3>
                            </div>
                            <div class="max-h-96 overflow-y-auto">
                                <?php
                                $notifications = fetchAll("SELECT * FROM notifications 
                                                          WHERE user_id = ? AND channel = 'inapp' 
                                                          ORDER BY created_at DESC LIMIT 5", 
                                                          [$user_id]);
                                
                                if (empty($notifications)): ?>
                                    <div class="p-4 text-center text-gray-500">
                                        <i class="fas fa-bell-slash text-3xl mb-2"></i>
                                        <p>No notifications</p>
                                    </div>
                                <?php else: 
                                    foreach ($notifications as $note): ?>
                                        <div class="p-4 hover:bg-gray-50 border-b border-gray-100 <?= $note['status'] === 'sent' ? 'bg-blue-50' : '' ?>">
                                            <p class="font-medium text-gray-800"><?= htmlspecialchars($note['subject']) ?></p>
                                            <p class="text-sm text-gray-600"><?= htmlspecialchars($note['message']) ?></p>
                                            <p class="text-xs text-gray-400 mt-1"><?= timeAgo($note['created_at']) ?></p>
                                        </div>
                                <?php endforeach; 
                                endif; ?>
                            </div>
                            <a href="notifications.php" class="block p-3 text-center text-blue-600 hover:bg-gray-50 rounded-b-lg border-t border-gray-200">
                                View All
                            </a>
                        </div>
                    </div>
                    
                    <!-- User Menu -->
                    <div class="relative">
                        <button id="userMenuBtn" class="flex items-center space-x-3 focus:outline-none">
                            <div class="flex items-center space-x-3">
                                <?php if (!empty($employer['company_logo'])): ?>
                                    <img src="<?= htmlspecialchars($employer['company_logo']) ?>" alt="Company Logo" class="h-8 w-8 rounded-full object-cover border-2 border-blue-500">
                                <?php else: ?>
                                    <div class="h-8 w-8 rounded-full bg-gradient-to-r from-blue-500 to-blue-600 flex items-center justify-center text-white font-bold">
                                        <?= strtoupper(substr($employer['company_name'] ?? 'C', 0, 1)) ?>
                                    </div>
                                <?php endif; ?>
                                <span class="text-gray-700"><?= htmlspecialchars($employer['company_name'] ?? 'Employer') ?></span>
                                <i class="fas fa-chevron-down text-gray-400 text-sm"></i>
                            </div>
                        </button>
                        
                        <div id="userDropdown" class="hidden absolute right-0 mt-2 w-48 bg-white rounded-lg shadow-xl border border-gray-200 z-50">
                            <a href="profile.php" class="block px-4 py-3 text-gray-700 hover:bg-gray-50 transition">
                                <i class="fas fa-user mr-2"></i> Profile
                            </a>
                            <a href="settings.php" class="block px-4 py-3 text-blue-600 bg-blue-50 transition">
                                <i class="fas fa-cog mr-2"></i> Settings
                            </a>
                            <hr class="border-gray-200">
                            <a href="logout.php" class="block px-4 py-3 text-red-600 hover:bg-red-50 transition">
                                <i class="fas fa-sign-out-alt mr-2"></i> Logout
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="pt-20 pb-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            
            <!-- Flash Messages -->
            <?php if ($message): ?>
                <div class="mb-6 animate-fadeIn">
                    <div class="rounded-lg p-4 <?= $message_type === 'success' ? 'bg-green-50 text-green-800 border border-green-200' : 'bg-red-50 text-red-800 border border-red-200' ?>">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <i class="fas <?= $message_type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle' ?>"></i>
                            </div>
                            <div class="ml-3">
                                <p class="text-sm font-medium"><?= $message ?></p>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
            <?= displayFlashMessage() ?>
            
            <!-- Header -->
            <div class="mb-8">
                <h1 class="text-3xl font-bold text-gray-900">Settings</h1>
                <p class="text-gray-600 mt-2">Manage your account settings and preferences</p>
            </div>
            
            <div class="flex flex-col lg:flex-row gap-8">
                <!-- Settings Sidebar -->
                <div class="lg:w-64 flex-shrink-0">
                    <div class="bg-white rounded-xl shadow-md overflow-hidden settings-sidebar sticky top-24">
                        <div class="p-4 border-b border-gray-200 bg-gray-50">
                            <h2 class="font-semibold text-gray-700">Settings Menu</h2>
                        </div>
                        <nav class="p-2">
                            <a href="#profile" onclick="showSection('profile')" id="link-profile" class="flex items-center px-4 py-3 text-gray-700 rounded-lg hover:bg-blue-50 transition active">
                                <i class="fas fa-user-circle w-6 text-blue-500"></i>
                                <span class="ml-3">Profile Settings</span>
                            </a>
                            <a href="#notifications" onclick="showSection('notifications')" id="link-notifications" class="flex items-center px-4 py-3 text-gray-700 rounded-lg hover:bg-blue-50 transition">
                                <i class="fas fa-bell w-6 text-yellow-500"></i>
                                <span class="ml-3">Notifications</span>
                            </a>
                            <a href="#hiring" onclick="showSection('hiring')" id="link-hiring" class="flex items-center px-4 py-3 text-gray-700 rounded-lg hover:bg-blue-50 transition">
                                <i class="fas fa-briefcase w-6 text-green-500"></i>
                                <span class="ml-3">Hiring Preferences</span>
                            </a>
                            <a href="#security" onclick="showSection('security')" id="link-security" class="flex items-center px-4 py-3 text-gray-700 rounded-lg hover:bg-blue-50 transition">
                                <i class="fas fa-shield-alt w-6 text-purple-500"></i>
                                <span class="ml-3">Security</span>
                            </a>
                            <a href="#billing" onclick="showSection('billing')" id="link-billing" class="flex items-center px-4 py-3 text-gray-700 rounded-lg hover:bg-blue-50 transition">
                                <i class="fas fa-credit-card w-6 text-indigo-500"></i>
                                <span class="ml-3">Billing</span>
                            </a>
                            <a href="#advanced" onclick="showSection('advanced')" id="link-advanced" class="flex items-center px-4 py-3 text-gray-700 rounded-lg hover:bg-blue-50 transition">
                                <i class="fas fa-cogs w-6 text-gray-500"></i>
                                <span class="ml-3">Advanced</span>
                            </a>
                        </nav>
                        
                        <!-- Account Status -->
                        <div class="p-4 border-t border-gray-200 bg-gray-50">
                            <div class="flex items-center justify-between">
                                <span class="text-sm text-gray-600">Account Status</span>
                                <span class="px-2 py-1 text-xs rounded-full bg-green-100 text-green-800">
                                    <i class="fas fa-circle text-xs mr-1"></i> Active
                                </span>
                            </div>
                            <p class="text-xs text-gray-500 mt-2">Member since <?= date('M Y', strtotime($employer['account_created'] ?? 'now')) ?></p>
                        </div>
                    </div>
                </div>
                
                <!-- Settings Content -->
                <div class="flex-1">
                    
                    <!-- Profile Settings Section -->
                    <div id="section-profile" class="settings-section">
                        <div class="bg-white rounded-xl shadow-md overflow-hidden">
                            <div class="p-6 border-b border-gray-200">
                                <h2 class="text-xl font-semibold text-gray-800">Profile Settings</h2>
                                <p class="text-sm text-gray-500 mt-1">Update your company information and contact details</p>
                            </div>
                            
                            <form method="POST" enctype="multipart/form-data" class="p-6">
                                <input type="hidden" name="update_profile" value="1">
                                
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <!-- Company Name -->
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">
                                            Company Name <span class="text-red-500">*</span>
                                        </label>
                                        <input type="text" name="company_name" required
                                               value="<?= htmlspecialchars($employer['company_name'] ?? '') ?>"
                                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition">
                                    </div>
                                    
                                    <!-- Company Email -->
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">
                                            Company Email <span class="text-red-500">*</span>
                                        </label>
                                        <input type="email" name="company_email" required
                                               value="<?= htmlspecialchars($employer['company_email'] ?? $employer['email'] ?? '') ?>"
                                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition">
                                    </div>
                                    
                                    <!-- Company Phone -->
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Phone Number</label>
                                        <input type="tel" name="company_phone"
                                               value="<?= htmlspecialchars($employer['company_phone'] ?? '') ?>"
                                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition">
                                    </div>
                                    
                                    <!-- Website -->
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Website</label>
                                        <input type="url" name="company_website"
                                               value="<?= htmlspecialchars($employer['company_website'] ?? '') ?>"
                                               placeholder="https://example.com"
                                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition">
                                    </div>
                                    
                                    <!-- Industry -->
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Industry</label>
                                        <select name="industry" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition">
                                            <option value="">Select Industry</option>
                                            <?php
                                            $industries = [
                                                'Technology', 'Healthcare', 'Finance', 'Education', 'Manufacturing',
                                                'Retail', 'Construction', 'Real Estate', 'Transportation', 'Hospitality',
                                                'Media', 'Consulting', 'Energy', 'Agriculture', 'Telecommunications',
                                                'E-commerce', 'Marketing', 'Human Resources', 'Legal', 'Other'
                                            ];
                                            foreach ($industries as $industry):
                                                $selected = ($employer['industry'] ?? '') === $industry ? 'selected' : '';
                                            ?>
                                                <option value="<?= $industry ?>" <?= $selected ?>><?= $industry ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <!-- Company Size -->
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Company Size</label>
                                        <select name="company_size" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition">
                                            <option value="">Select Size</option>
                                            <?php
                                            $sizes = [
                                                '1-10' => '1-10 employees',
                                                '11-50' => '11-50 employees',
                                                '51-200' => '51-200 employees',
                                                '201-500' => '201-500 employees',
                                                '501-1000' => '501-1000 employees',
                                                '1001-5000' => '1001-5000 employees',
                                                '5001+' => '5000+ employees'
                                            ];
                                            foreach ($sizes as $value => $label):
                                                $selected = ($employer['company_size'] ?? '') === $value ? 'selected' : '';
                                            ?>
                                                <option value="<?= $value ?>" <?= $selected ?>><?= $label ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <!-- Tax ID -->
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Tax ID / Business Registration</label>
                                        <input type="text" name="tax_id"
                                               value="<?= htmlspecialchars($employer['tax_id'] ?? '') ?>"
                                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition">
                                    </div>
                                    
                                    <!-- City -->
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">City</label>
                                        <input type="text" name="city"
                                               value="<?= htmlspecialchars($employer['city'] ?? '') ?>"
                                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition">
                                    </div>
                                    
                                    <!-- Country -->
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Country</label>
                                        <select name="country" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition">
                                            <option value="">Select Country</option>
                                            <?php
                                            $countries = [
                                                'Ghana', 'Nigeria', 'Kenya', 'South Africa', 'Egypt',
                                                'Morocco', 'Tanzania', 'Uganda', 'Ethiopia', 'Rwanda',
                                                'United States', 'United Kingdom', 'Canada', 'Australia',
                                                'Germany', 'France', 'China', 'India', 'Japan'
                                            ];
                                            foreach ($countries as $country):
                                                $selected = ($employer['country'] ?? 'Ghana') === $country ? 'selected' : '';
                                            ?>
                                                <option value="<?= $country ?>" <?= $selected ?>><?= $country ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <!-- Address (full width) -->
                                    <div class="md:col-span-2">
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Street Address</label>
                                        <textarea name="address" rows="2" 
                                                  class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition"><?= htmlspecialchars($employer['address'] ?? '') ?></textarea>
                                    </div>
                                    
                                    <!-- Logo Upload -->
                                    <div class="md:col-span-2">
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Company Logo</label>
                                        <div class="flex items-center space-x-4">
                                            <div class="flex-shrink-0">
                                                <?php if (!empty($employer['company_logo'])): ?>
                                                    <img src="<?= htmlspecialchars($employer['company_logo']) ?>" alt="Logo" class="h-16 w-16 object-cover rounded-lg border border-gray-200">
                                                <?php else: ?>
                                                    <div class="h-16 w-16 bg-gray-100 rounded-lg border border-dashed border-gray-300 flex items-center justify-center">
                                                        <i class="fas fa-image text-2xl text-gray-400"></i>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="flex-1">
                                                <input type="file" name="company_logo" id="company_logo" class="hidden" accept="image/*">
                                                <label for="company_logo" class="cursor-pointer inline-flex items-center px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50 transition">
                                                    <i class="fas fa-upload mr-2"></i> Upload New Logo
                                                </label>
                                                <p class="text-xs text-gray-500 mt-1">PNG, JPG, GIF up to 2MB</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="mt-6 flex justify-end">
                                    <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
                                        <i class="fas fa-save mr-2"></i> Save Profile Settings
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Notifications Section -->
                    <div id="section-notifications" class="settings-section hidden">
                        <div class="bg-white rounded-xl shadow-md overflow-hidden">
                            <div class="p-6 border-b border-gray-200">
                                <h2 class="text-xl font-semibold text-gray-800">Notification Settings</h2>
                                <p class="text-sm text-gray-500 mt-1">Choose how you want to receive notifications</p>
                            </div>
                            
                            <form method="POST" class="p-6">
                                <input type="hidden" name="update_notifications" value="1">
                                
                                <!-- Communication Channels -->
                                <h3 class="text-lg font-medium text-gray-800 mb-4">Communication Channels</h3>
                                <div class="space-y-4 mb-8">
                                    <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                                        <div>
                                            <p class="font-medium text-gray-700">Email Notifications</p>
                                            <p class="text-sm text-gray-500">Receive notifications via email</p>
                                        </div>
                                        <label class="relative inline-flex items-center cursor-pointer">
                                            <input type="checkbox" name="email_notifications" class="sr-only peer" <?= ($notification_settings['email_notifications'] ?? 1) ? 'checked' : '' ?>>
                                            <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                                        </label>
                                    </div>
                                    
                                    <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                                        <div>
                                            <p class="font-medium text-gray-700">WhatsApp Notifications</p>
                                            <p class="text-sm text-gray-500">Receive notifications on WhatsApp</p>
                                        </div>
                                        <label class="relative inline-flex items-center cursor-pointer">
                                            <input type="checkbox" name="whatsapp_notifications" class="sr-only peer" <?= ($notification_settings['whatsapp_notifications'] ?? 0) ? 'checked' : '' ?>>
                                            <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                                        </label>
                                    </div>
                                    
                                    <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                                        <div>
                                            <p class="font-medium text-gray-700">SMS Notifications</p>
                                            <p class="text-sm text-gray-500">Receive notifications via SMS</p>
                                        </div>
                                        <label class="relative inline-flex items-center cursor-pointer">
                                            <input type="checkbox" name="sms_notifications" class="sr-only peer" <?= ($notification_settings['sms_notifications'] ?? 0) ? 'checked' : '' ?>>
                                            <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                                        </label>
                                    </div>
                                </div>
                                
                                <!-- Notification Types -->
                                <h3 class="text-lg font-medium text-gray-800 mb-4">Notification Types</h3>
                                <div class="space-y-4">
                                    <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                                        <div>
                                            <p class="font-medium text-gray-700">New Applications</p>
                                            <p class="text-sm text-gray-500">Get notified when someone applies to your jobs</p>
                                        </div>
                                        <label class="relative inline-flex items-center cursor-pointer">
                                            <input type="checkbox" name="notify_new_applications" class="sr-only peer" <?= ($notification_settings['notify_new_applications'] ?? 1) ? 'checked' : '' ?>>
                                            <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                                        </label>
                                    </div>
                                    
                                    <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                                        <div>
                                            <p class="font-medium text-gray-700">Application Updates</p>
                                            <p class="text-sm text-gray-500">Get notified when application status changes</p>
                                        </div>
                                        <label class="relative inline-flex items-center cursor-pointer">
                                            <input type="checkbox" name="notify_application_updates" class="sr-only peer" <?= ($notification_settings['notify_application_updates'] ?? 1) ? 'checked' : '' ?>>
                                            <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                                        </label>
                                    </div>
                                    
                                    <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                                        <div>
                                            <p class="font-medium text-gray-700">Messages</p>
                                            <p class="text-sm text-gray-500">Get notified when you receive new messages</p>
                                        </div>
                                        <label class="relative inline-flex items-center cursor-pointer">
                                            <input type="checkbox" name="notify_messages" class="sr-only peer" <?= ($notification_settings['notify_messages'] ?? 1) ? 'checked' : '' ?>>
                                            <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                                        </label>
                                    </div>
                                    
                                    <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                                        <div>
                                            <p class="font-medium text-gray-700">Job Expiry</p>
                                            <p class="text-sm text-gray-500">Get notified when your jobs are about to expire</p>
                                        </div>
                                        <label class="relative inline-flex items-center cursor-pointer">
                                            <input type="checkbox" name="notify_job_expiry" class="sr-only peer" <?= ($notification_settings['notify_job_expiry'] ?? 1) ? 'checked' : '' ?>>
                                            <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                                        </label>
                                    </div>
                                    
                                    <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                                        <div>
                                            <p class="font-medium text-gray-700">Candidate Matches</p>
                                            <p class="text-sm text-gray-500">Get notified when we find candidates matching your criteria</p>
                                        </div>
                                        <label class="relative inline-flex items-center cursor-pointer">
                                            <input type="checkbox" name="notify_candidate_matches" class="sr-only peer" <?= ($notification_settings['notify_candidate_matches'] ?? 1) ? 'checked' : '' ?>>
                                            <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                                        </label>
                                    </div>
                                </div>
                                
                                <div class="mt-6 flex justify-end">
                                    <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
                                        <i class="fas fa-save mr-2"></i> Save Notification Settings
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Hiring Preferences Section -->
                    <div id="section-hiring" class="settings-section hidden">
                        <div class="bg-white rounded-xl shadow-md overflow-hidden">
                            <div class="p-6 border-b border-gray-200">
                                <h2 class="text-xl font-semibold text-gray-800">Hiring Preferences</h2>
                                <p class="text-sm text-gray-500 mt-1">Set your preferences to get better candidate matches</p>
                            </div>
                            
                            <form method="POST" class="p-6">
                                <input type="hidden" name="update_hiring_prefs" value="1">
                                
                                <div class="space-y-6">
                                    <!-- Preferred Categories -->
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-3">Preferred Job Categories</label>
                                        <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
                                            <?php 
                                            $selected_categories = json_decode($preferences['required_categories'] ?? '[]', true);
                                            foreach ($categories as $category): 
                                            ?>
                                                <label class="flex items-center space-x-2">
                                                    <input type="checkbox" name="required_categories[]" 
                                                           value="<?= $category['id'] ?>"
                                                           <?= in_array($category['id'], $selected_categories) ? 'checked' : '' ?>
                                                           class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                                    <span class="text-sm text-gray-700"><?= htmlspecialchars($category['name']) ?></span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    
                                    <!-- Required Skills -->
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Required Skills (comma separated)</label>
                                        <?php 
                                        $skills = json_decode($preferences['required_skills'] ?? '[]', true);
                                        $skills_string = is_array($skills) ? implode(', ', $skills) : '';
                                        ?>
                                        <input type="text" name="required_skills" 
                                               value="<?= htmlspecialchars($skills_string) ?>"
                                               placeholder="e.g., PHP, JavaScript, Project Management"
                                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition">
                                        <p class="text-xs text-gray-500 mt-1">Separate skills with commas</p>
                                    </div>
                                    
                                    <!-- Experience Range -->
                                    <div class="grid grid-cols-2 gap-4">
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-2">Min Experience (years)</label>
                                            <input type="number" name="experience_range_min" min="0" max="50"
                                                   value="<?= htmlspecialchars($preferences['experience_range_min'] ?? 0) ?>"
                                                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition">
                                        </div>
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-2">Max Experience (years)</label>
                                            <input type="number" name="experience_range_max" min="0" max="50"
                                                   value="<?= htmlspecialchars($preferences['experience_range_max'] ?? 30) ?>"
                                                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition">
                                        </div>
                                    </div>
                                    
                                    <!-- Education Level -->
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Minimum Education Level</label>
                                        <select name="education_level" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition">
                                            <option value="">Any Education Level</option>
                                            <option value="high_school" <?= ($preferences['education_level'] ?? '') === 'high_school' ? 'selected' : '' ?>>High School</option>
                                            <option value="diploma" <?= ($preferences['education_level'] ?? '') === 'diploma' ? 'selected' : '' ?>>Diploma</option>
                                            <option value="bachelors" <?= ($preferences['education_level'] ?? '') === 'bachelors' ? 'selected' : '' ?>>Bachelor's Degree</option>
                                            <option value="masters" <?= ($preferences['education_level'] ?? '') === 'masters' ? 'selected' : '' ?>>Master's Degree</option>
                                            <option value="phd" <?= ($preferences['education_level'] ?? '') === 'phd' ? 'selected' : '' ?>>PhD</option>
                                        </select>
                                    </div>
                                    
                                    <!-- Salary Budget -->
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Maximum Salary Budget (GHS/month)</label>
                                        <input type="number" name="salary_budget_max" step="0.01" min="0"
                                               value="<?= htmlspecialchars($preferences['salary_budget_max'] ?? '') ?>"
                                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition">
                                    </div>
                                    
                                    <!-- Location Preference -->
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Preferred Location</label>
                                        <input type="text" name="location_preference" 
                                               value="<?= htmlspecialchars($preferences['location_preference'] ?? '') ?>"
                                               placeholder="e.g., Accra, Kumasi, Remote"
                                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition">
                                    </div>
                                </div>
                                
                                <div class="mt-6 flex justify-end">
                                    <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
                                        <i class="fas fa-save mr-2"></i> Save Hiring Preferences
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Security Section -->
                    <div id="section-security" class="settings-section hidden">
                        <div class="bg-white rounded-xl shadow-md overflow-hidden">
                            <div class="p-6 border-b border-gray-200">
                                <h2 class="text-xl font-semibold text-gray-800">Security Settings</h2>
                                <p class="text-sm text-gray-500 mt-1">Manage your password and account security</p>
                            </div>
                            
                            <div class="p-6">
                                <!-- Change Password Form -->
                                <form method="POST" class="max-w-md">
                                    <input type="hidden" name="update_security" value="1">
                                    
                                    <h3 class="text-lg font-medium text-gray-800 mb-4">Change Password</h3>
                                    
                                    <div class="space-y-4">
                                        <!-- Current Password -->
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-2">Current Password</label>
                                            <div class="relative">
                                                <input type="password" name="current_password" id="current_password" required
                                                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition pr-10">
                                                <button type="button" onclick="togglePassword('current_password')" 
                                                        class="absolute right-3 top-2.5 text-gray-400 hover:text-gray-600">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                            </div>
                                        </div>
                                        
                                        <!-- New Password -->
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-2">New Password</label>
                                            <div class="relative">
                                                <input type="password" name="new_password" id="new_password" required minlength="8"
                                                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition pr-10">
                                                <button type="button" onclick="togglePassword('new_password')" 
                                                        class="absolute right-3 top-2.5 text-gray-400 hover:text-gray-600">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                            </div>
                                            <p class="text-xs text-gray-500 mt-1">Minimum 8 characters</p>
                                        </div>
                                        
                                        <!-- Confirm Password -->
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-2">Confirm New Password</label>
                                            <div class="relative">
                                                <input type="password" name="confirm_password" id="confirm_password" required
                                                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition pr-10">
                                                <button type="button" onclick="togglePassword('confirm_password')" 
                                                        class="absolute right-3 top-2.5 text-gray-400 hover:text-gray-600">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="mt-6">
                                        <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
                                            <i class="fas fa-key mr-2"></i> Update Password
                                        </button>
                                    </div>
                                </form>
                                
                                <hr class="my-8 border-gray-200">
                                
                                <!-- Two-Factor Authentication -->
                                <div class="mb-8">
                                    <h3 class="text-lg font-medium text-gray-800 mb-4">Two-Factor Authentication</h3>
                                    <div class="bg-gray-50 p-4 rounded-lg">
                                        <div class="flex items-center justify-between">
                                            <div>
                                                <p class="font-medium text-gray-700">Enable 2FA</p>
                                                <p class="text-sm text-gray-500">Add an extra layer of security to your account</p>
                                            </div>
                                            <button class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition text-sm">
                                                Setup 2FA
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Session Management -->
                                <div class="mb-8">
                                    <h3 class="text-lg font-medium text-gray-800 mb-4">Active Sessions</h3>
                                    <div class="bg-gray-50 p-4 rounded-lg">
                                        <div class="flex items-center space-x-3">
                                            <div class="bg-green-100 p-2 rounded-full">
                                                <i class="fas fa-laptop text-green-600"></i>
                                            </div>
                                            <div class="flex-1">
                                                <p class="font-medium text-gray-700">Current Session</p>
                                                <p class="text-xs text-gray-500">IP: <?= $_SERVER['REMOTE_ADDR'] ?> · Last active: now</p>
                                            </div>
                                            <span class="px-2 py-1 text-xs bg-green-100 text-green-800 rounded-full">Active</span>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Login History -->
                                <div>
                                    <h3 class="text-lg font-medium text-gray-800 mb-4">Recent Login Activity</h3>
                                    <div class="space-y-3">
                                        <?php
                                        // This would come from a login history table in a real app
                                        $logins = [
                                            ['date' => date('Y-m-d H:i', strtotime('-2 hours')), 'ip' => '197.251.233.1', 'device' => 'Chrome on Windows'],
                                            ['date' => date('Y-m-d H:i', strtotime('-2 days')), 'ip' => '197.251.233.1', 'device' => 'Chrome on Windows'],
                                            ['date' => date('Y-m-d H:i', strtotime('-1 week')), 'ip' => '197.251.233.1', 'device' => 'Firefox on Android'],
                                        ];
                                        foreach ($logins as $login): ?>
                                            <div class="flex items-center justify-between text-sm border-b border-gray-100 pb-2">
                                                <span class="text-gray-600"><?= $login['date'] ?></span>
                                                <span class="text-gray-600"><?= $login['ip'] ?></span>
                                                <span class="text-gray-600"><?= $login['device'] ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Billing Section -->
                    <div id="section-billing" class="settings-section hidden">
                        <div class="bg-white rounded-xl shadow-md overflow-hidden">
                            <div class="p-6 border-b border-gray-200">
                                <h2 class="text-xl font-semibold text-gray-800">Billing & Subscription</h2>
                                <p class="text-sm text-gray-500 mt-1">Manage your subscription and payment methods</p>
                            </div>
                            
                            <div class="p-6">
                                <!-- Current Plan -->
                                <div class="mb-8">
                                    <h3 class="text-lg font-medium text-gray-800 mb-4">Current Plan</h3>
                                    <div class="bg-gradient-to-r from-blue-50 to-indigo-50 p-6 rounded-xl border border-blue-100">
                                        <div class="flex items-center justify-between">
                                            <div>
                                                <span class="px-3 py-1 bg-blue-600 text-white text-xs rounded-full">Active</span>
                                                <h4 class="text-2xl font-bold text-gray-900 mt-3">Free Plan</h4>
                                                <p class="text-gray-600 mt-1">GHS 0 / month</p>
                                                <ul class="mt-4 space-y-2">
                                                    <li class="flex items-center text-sm text-gray-600">
                                                        <i class="fas fa-check text-green-500 mr-2"></i> Post up to 3 active jobs
                                                    </li>
                                                    <li class="flex items-center text-sm text-gray-600">
                                                        <i class="fas fa-check text-green-500 mr-2"></i> View up to 50 applications/month
                                                    </li>
                                                    <li class="flex items-center text-sm text-gray-600">
                                                        <i class="fas fa-check text-green-500 mr-2"></i> Basic candidate search
                                                    </li>
                                                </ul>
                                            </div>
                                            <div>
                                                <button class="px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
                                                    Upgrade Plan
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Payment Methods -->
                                <div class="mb-8">
                                    <h3 class="text-lg font-medium text-gray-800 mb-4">Payment Methods</h3>
                                    <div class="border border-gray-200 rounded-lg p-4">
                                        <div class="flex items-center justify-between">
                                            <div class="flex items-center space-x-3">
                                                <div class="bg-gray-100 p-2 rounded">
                                                    <i class="fas fa-credit-card text-gray-600"></i>
                                                </div>
                                                <div>
                                                    <p class="font-medium text-gray-700">No payment method added</p>
                                                    <p class="text-sm text-gray-500">Add a payment method to upgrade your plan</p>
                                                </div>
                                            </div>
                                            <button class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition">
                                                Add Method
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Billing History -->
                                <div>
                                    <h3 class="text-lg font-medium text-gray-800 mb-4">Billing History</h3>
                                    <div class="text-center py-8 text-gray-500">
                                        <i class="fas fa-receipt text-4xl mb-2 opacity-50"></i>
                                        <p>No billing history available</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Advanced Section -->
                    <div id="section-advanced" class="settings-section hidden">
                        <div class="bg-white rounded-xl shadow-md overflow-hidden">
                            <div class="p-6 border-b border-gray-200">
                                <h2 class="text-xl font-semibold text-gray-800">Advanced Settings</h2>
                                <p class="text-sm text-gray-500 mt-1">Advanced account options and data management</p>
                            </div>
                            
                            <div class="p-6">
                                <!-- Export Data -->
                                <div class="mb-8">
                                    <h3 class="text-lg font-medium text-gray-800 mb-4">Export Your Data</h3>
                                    <div class="bg-gray-50 p-4 rounded-lg">
                                        <p class="text-sm text-gray-600 mb-4">Download a copy of all your company data, including jobs, applications, and messages.</p>
                                        <button class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
                                            <i class="fas fa-download mr-2"></i> Export Data
                                        </button>
                                    </div>
                                </div>
                                
                                <!-- API Access -->
                                <div class="mb-8">
                                    <h3 class="text-lg font-medium text-gray-800 mb-4">API Access</h3>
                                    <div class="bg-gray-50 p-4 rounded-lg">
                                        <div class="flex items-center justify-between">
                                            <div>
                                                <p class="font-medium text-gray-700">API Key</p>
                                                <p class="text-sm text-gray-500">Use our API to integrate with your systems</p>
                                            </div>
                                            <button class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition">
                                                Generate Key
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Webhooks -->
                                <div class="mb-8">
                                    <h3 class="text-lg font-medium text-gray-800 mb-4">Webhooks</h3>
                                    <div class="bg-gray-50 p-4 rounded-lg">
                                        <p class="text-sm text-gray-600 mb-4">Configure webhooks to receive real-time notifications about applications and events.</p>
                                        <button class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition">
                                            Configure Webhooks
                                        </button>
                                    </div>
                                </div>
                                
                                <!-- Danger Zone -->
                                <div class="mt-8 p-4 border border-red-200 rounded-lg bg-red-50">
                                    <h3 class="text-lg font-semibold text-red-800 mb-2">Danger Zone</h3>
                                    <p class="text-sm text-red-600 mb-4">Once you deactivate your account, it will be hidden from public view. You can reactivate anytime.</p>
                                    
                                    <form method="POST" onsubmit="return confirm('Are you sure you want to deactivate your account? This will hide all your job postings.')">
                                        <input type="hidden" name="deactivate_account" value="1">
                                        <div class="flex items-center space-x-4">
                                            <input type="text" name="confirm_deactivate" placeholder="Type DEACTIVATE to confirm" 
                                                   class="flex-1 px-4 py-2 border border-red-300 rounded-lg focus:ring-2 focus:ring-red-500 focus:border-red-500 transition"
                                                   required>
                                            <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition">
                                                Deactivate Account
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Hidden Forms -->
    <form id="removeLogoForm" method="POST" class="hidden">
        <input type="hidden" name="remove_logo" value="1">
    </form>

    <!-- JavaScript -->
    <script>
        // Toggle dropdowns
        document.addEventListener('DOMContentLoaded', function() {
            // Notification dropdown
            const notificationBtn = document.getElementById('notificationBtn');
            const notificationDropdown = document.getElementById('notificationDropdown');
            
            if (notificationBtn) {
                notificationBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    notificationDropdown.classList.toggle('hidden');
                });
            }
            
            // User menu dropdown
            const userMenuBtn = document.getElementById('userMenuBtn');
            const userDropdown = document.getElementById('userDropdown');
            
            if (userMenuBtn) {
                userMenuBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    userDropdown.classList.toggle('hidden');
                });
            }
            
            // Close dropdowns when clicking outside
            document.addEventListener('click', function() {
                if (notificationDropdown) notificationDropdown.classList.add('hidden');
                if (userDropdown) userDropdown.classList.add('hidden');
            });
            
            // Check for hash in URL
            if (window.location.hash) {
                const section = window.location.hash.substring(1);
                showSection(section);
            } else {
                showSection('profile');
            }
        });

        // Section switching
        function showSection(section) {
            // Hide all sections
            document.querySelectorAll('.settings-section').forEach(el => {
                el.classList.add('hidden');
            });
            
            // Show selected section
            const sectionEl = document.getElementById('section-' + section);
            if (sectionEl) {
                sectionEl.classList.remove('hidden');
            }
            
            // Update active link
            document.querySelectorAll('.settings-sidebar a').forEach(link => {
                link.classList.remove('active', 'bg-blue-50', 'text-blue-700');
            });
            
            const activeLink = document.getElementById('link-' + section);
            if (activeLink) {
                activeLink.classList.add('active', 'bg-blue-50', 'text-blue-700');
            }
            
            // Update URL hash
            window.location.hash = section;
        }

        // Toggle password visibility
        function togglePassword(inputId) {
            const input = document.getElementById(inputId);
            const button = input.nextElementSibling;
            const icon = button.querySelector('i');
            
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

        // Remove logo
        function removeLogo() {
            if (confirm('Are you sure you want to remove your company logo?')) {
                document.getElementById('removeLogoForm').submit();
            }
        }

        // Preview logo before upload
        document.getElementById('company_logo')?.addEventListener('change', function(e) {
            if (this.files && this.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    // You could add a preview here
                    console.log('Logo selected');
                };
                reader.readAsDataURL(this.files[0]);
            }
        });

        // Form validation for password
        document.querySelector('form[onsubmit]')?.addEventListener('submit', function(e) {
            const password = document.getElementById('new_password')?.value;
            const confirm = document.getElementById('confirm_password')?.value;
            
            if (password && confirm && password !== confirm) {
                e.preventDefault();
                alert('New passwords do not match!');
            }
        });
    </script>
</body>
</html>