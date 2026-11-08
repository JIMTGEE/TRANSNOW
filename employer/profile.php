<?php
// employer/profile.php
// Professional Company Profile Management with PDO implementation

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../includes/auth.php';

// Require employer login
requireEmployerLogin();

// Get database connection
$pdo = getDB();
$user_id = $_SESSION['user_id'];

// Get employer company info with user details
$stmt = $pdo->prepare("SELECT e.*, u.email, u.created_at as account_created,
                       u.last_login
                       FROM employers e 
                       JOIN users u ON e.user_id = u.id 
                       WHERE e.user_id = ?");
$stmt->execute([$user_id]);
$employer = $stmt->fetch(PDO::FETCH_ASSOC);
$employer_id = $employer['id'];

// Get company statistics for profile
$company_stats = [];

$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM jobs WHERE employer_id = ?");
$stmt->execute([$employer_id]);
$company_stats['total_jobs'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;

$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM jobs WHERE employer_id = ? AND status = 'active'");
$stmt->execute([$employer_id]);
$company_stats['active_jobs'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;

$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM applications a 
                       JOIN jobs j ON a.job_id = j.id 
                       WHERE j.employer_id = ? AND a.application_status = 'offered'");
$stmt->execute([$employer_id]);
$company_stats['total_hired'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;

$stmt = $pdo->prepare("SELECT SUM(total_views) as count FROM jobs WHERE employer_id = ?");
$stmt->execute([$employer_id]);
$company_stats['total_views'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;

// Get recent jobs for profile showcase
$stmt = $pdo->prepare("SELECT id, title, city, status, created_at, total_views
                       FROM jobs 
                       WHERE employer_id = ? 
                       ORDER BY created_at DESC 
                       LIMIT 3");
$stmt->execute([$employer_id]);
$recent_jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle profile update
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Determine which form was submitted
    if (isset($_POST['update_company'])) {
        // Update company information - using actual column names
        $company_name = $_POST['company_name'] ?? '';
        $company_description = $_POST['company_description'] ?? '';
        $industry = $_POST['industry'] ?? null;
        $company_website = $_POST['company_website'] ?? null;
        $company_phone = $_POST['company_phone'] ?? null;
        $company_email = $_POST['company_email'] ?? '';
        $address = $_POST['address'] ?? null;
        $city = $_POST['city'] ?? null;
        $country = $_POST['country'] ?? null;
        $company_size = $_POST['company_size'] ?? null;
        
        // Handle logo upload
        $company_logo = $employer['company_logo']; // Keep existing logo by default
        
        if (isset($_FILES['company_logo']) && $_FILES['company_logo']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = '../uploads/logos/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
            $file_type = $_FILES['company_logo']['type'];
            
            if (in_array($file_type, $allowed_types) && $_FILES['company_logo']['size'] <= 2 * 1024 * 1024) {
                $file_ext = pathinfo($_FILES['company_logo']['name'], PATHINFO_EXTENSION);
                $filename = 'logo_' . $employer_id . '_' . time() . '.' . $file_ext;
                $target_path = $upload_dir . $filename;
                
                if (move_uploaded_file($_FILES['company_logo']['tmp_name'], $target_path)) {
                    $company_logo = 'uploads/logos/' . $filename;
                } else {
                    $message = 'Logo upload failed. Please try again.';
                    $message_type = 'error';
                }
            } else {
                $message = 'Invalid file type or size. Please upload JPG, PNG, or GIF under 2MB.';
                $message_type = 'error';
            }
        }
        
        // Only proceed with update if no upload error message
        if (empty($message)) {
            // Update employers table with actual column names
            $sql = "UPDATE employers SET 
                    company_name = ?,
                    company_description = ?,
                    industry = ?,
                    company_website = ?,
                    company_phone = ?,
                    company_email = ?,
                    address = ?,
                    city = ?,
                    country = ?,
                    company_size = ?,
                    company_logo = ?
                    WHERE id = ?";
            
            $stmt = $pdo->prepare($sql);
            $result = $stmt->execute([
                $company_name,
                $company_description,
                $industry,
                $company_website,
                $company_phone,
                $company_email,
                $address,
                $city,
                $country,
                $company_size,
                $company_logo,
                $employer_id
            ]);
            
            // Update users table email if changed and not empty
            if (!empty($company_email) && $company_email !== $employer['email']) {
                $stmt = $pdo->prepare("UPDATE users SET email = ? WHERE id = ?");
                $stmt->execute([$company_email, $user_id]);
            }
            
            if ($result) {
                $message = 'Company profile updated successfully!';
                $message_type = 'success';
                
                // Refresh employer data
                $stmt = $pdo->prepare("SELECT e.*, u.email, u.created_at as account_created,
                                      u.last_login
                                      FROM employers e 
                                      JOIN users u ON e.user_id = u.id 
                                      WHERE e.id = ?");
                $stmt->execute([$employer_id]);
                $employer = $stmt->fetch(PDO::FETCH_ASSOC);
            } else {
                $message = 'No changes were made or update failed.';
                $message_type = 'warning';
            }
        }
    }
    
    if (isset($_POST['update_password'])) {
        // Handle password change
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        // Get user password
        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (password_verify($current_password, $user['password'])) {
            if ($new_password === $confirm_password) {
                if (strlen($new_password) >= 8) {
                    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $stmt->execute([$hashed_password, $user_id]);
                    
                    $message = 'Password updated successfully!';
                    $message_type = 'success';
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
    
    if (isset($_POST['remove_logo'])) {
        // Remove logo
        $stmt = $pdo->prepare("UPDATE employers SET company_logo = NULL WHERE id = ?");
        $stmt->execute([$employer_id]);
        $message = 'Logo removed successfully.';
        $message_type = 'success';
        
        // Refresh employer data
        $stmt = $pdo->prepare("SELECT e.*, u.email, u.created_at as account_created,
                              u.last_login
                              FROM employers e 
                              JOIN users u ON e.user_id = u.id 
                              WHERE e.id = ?");
        $stmt->execute([$employer_id]);
        $employer = $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

// Get unread notifications count
$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM notifications 
                       WHERE user_id = ? AND channel = 'inapp' AND status = 'sent'");
$stmt->execute([$user_id]);
$unread_notifications = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;

// Page title
$page_title = "Company Profile";

// REMOVE THIS ENTIRE BLOCK - IT'S CAUSING THE ERROR:
/*
function timeAgo($datetime) {
    if (!$datetime) return 'Never';
    
    $timestamp = strtotime($datetime);
    $diff = time() - $timestamp;
    
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . ' minutes ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hours ago';
    if ($diff < 2592000) return floor($diff / 86400) . ' days ago';
    return date('M j, Y', $timestamp);
}
*/
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?> - Job Portal</title>
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    
    <!-- Custom Styles -->
    <style>
        .profile-tab {
            transition: all 0.2s ease;
        }
        .profile-tab:hover {
            background-color: #F3F4F6;
        }
        .profile-tab.active {
            color: #2563EB;
            border-bottom-color: #2563EB;
        }
        .upload-area {
            transition: all 0.2s ease;
        }
        .upload-area:hover {
            border-color: #2563EB;
            background-color: #EFF6FF;
        }
        .animate-fadeIn {
            animation: fadeIn 0.3s ease-out forwards;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body class="bg-gray-50">
    <!-- Navigation -->
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
                        <a href="jobs.php" class="text-gray-600 hover:text-blue-600 px-3 py-2 text-sm font-medium transition">My Jobs</a>
                        <a href="post-job.php" class="text-gray-600 hover:text-blue-600 px-3 py-2 text-sm font-medium transition">Post Job</a>
                        <a href="applications.php" class="text-gray-600 hover:text-blue-600 px-3 py-2 text-sm font-medium transition">Applications</a>
                        <a href="profile.php" class="text-blue-600 border-b-2 border-blue-600 px-3 py-2 text-sm font-medium">Company Profile</a>
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
                                $stmt = $pdo->prepare("SELECT * FROM notifications 
                                                      WHERE user_id = ? AND channel = 'inapp' 
                                                      ORDER BY created_at DESC LIMIT 5");
                                $stmt->execute([$user_id]);
                                $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                
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
                                    <img src="../<?= htmlspecialchars($employer['company_logo']) ?>" alt="Company Logo" class="h-8 w-8 rounded-full object-cover border-2 border-blue-500">
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
                            <a href="settings.php" class="block px-4 py-3 text-gray-700 hover:bg-gray-50 transition">
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
                    <div class="p-4 rounded-lg <?= $message_type === 'success' ? 'bg-green-100 text-green-700 border border-green-400' : ($message_type === 'error' ? 'bg-red-100 text-red-700 border border-red-400' : 'bg-yellow-100 text-yellow-700 border border-yellow-400') ?>">
                        <?= htmlspecialchars($message) ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Profile Header -->
            <div class="relative mb-8 rounded-2xl overflow-hidden shadow-xl bg-gradient-to-r from-blue-600 to-blue-800 h-48 flex items-end">
                <div class="absolute bottom-0 left-0 right-0 bg-gradient-to-t from-black/50 to-transparent p-6">
                    <div class="flex items-end space-x-4">
                        <!-- Company Logo -->
                        <div class="bg-white rounded-2xl p-2 shadow-lg">
                            <?php if (!empty($employer['company_logo'])): ?>
                                <img src="../<?= htmlspecialchars($employer['company_logo']) ?>" 
                                     alt="Company Logo" 
                                     class="h-24 w-24 object-cover rounded-xl">
                            <?php else: ?>
                                <div class="h-24 w-24 bg-gradient-to-br from-blue-500 to-blue-600 rounded-xl flex items-center justify-center">
                                    <span class="text-white text-4xl font-bold">
                                        <?= strtoupper(substr($employer['company_name'] ?? 'C', 0, 1)) ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Company Name and Details -->
                        <div class="flex-1 text-white">
                            <h1 class="text-3xl font-bold mb-1"><?= htmlspecialchars($employer['company_name'] ?? 'Company Name') ?></h1>
                            <p class="text-blue-100 flex items-center">
                                <i class="fas fa-map-marker-alt mr-1"></i>
                                <?= htmlspecialchars($employer['city'] ?? 'Location not set') ?>, <?= htmlspecialchars($employer['country'] ?? 'Ghana') ?>
                                <span class="mx-2">•</span>
                                <i class="fas fa-calendar-alt mr-1"></i>
                                Member since <?= date('F Y', strtotime($employer['account_created'] ?? 'now')) ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Profile Tabs -->
            <div class="bg-white rounded-xl shadow-md mb-6">
                <div class="border-b border-gray-200">
                    <nav class="flex -mb-px">
                        <button onclick="showTab('company')" id="tabCompany" class="profile-tab active px-6 py-4 text-sm font-medium border-b-2 border-blue-600 text-blue-600">
                            <i class="fas fa-building mr-2"></i>Company Information
                        </button>
                        <button onclick="showTab('stats')" id="tabStats" class="profile-tab px-6 py-4 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-700">
                            <i class="fas fa-chart-bar mr-2"></i>Company Stats
                        </button>
                        <button onclick="showTab('security')" id="tabSecurity" class="profile-tab px-6 py-4 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-700">
                            <i class="fas fa-shield-alt mr-2"></i>Security
                        </button>
                    </nav>
                </div>
            </div>

            <!-- Tab Content -->
            <div class="animate-fadeIn">
                <!-- Company Information Tab -->
                <div id="companyTab" class="tab-content">
                    <form id="companyForm" method="POST" enctype="multipart/form-data" class="space-y-6">
                        <input type="hidden" name="update_company" value="1">
                        
                        <div class="bg-white rounded-xl shadow-md p-6">
                            <h2 class="text-xl font-semibold text-gray-800 mb-6">Basic Information</h2>
                            
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
                                            '1000+' => '1000+ employees'
                                        ];
                                        foreach ($sizes as $value => $label):
                                            $selected = ($employer['company_size'] ?? '') === $value ? 'selected' : '';
                                        ?>
                                            <option value="<?= $value ?>" <?= $selected ?>><?= $label ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <!-- Website -->
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Website</label>
                                    <input type="url" name="company_website" placeholder="https://example.com"
                                           value="<?= htmlspecialchars($employer['company_website'] ?? '') ?>"
                                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition">
                                </div>
                                
                                <!-- Company Email -->
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">
                                        Company Email <span class="text-red-500">*</span>
                                    </label>
                                    <input type="email" name="company_email" required
                                           value="<?= htmlspecialchars($employer['company_email'] ?? '') ?>"
                                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition">
                                </div>
                                
                                <!-- Company Phone -->
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Phone Number</label>
                                    <input type="tel" name="company_phone"
                                           value="<?= htmlspecialchars($employer['company_phone'] ?? '') ?>"
                                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition">
                                </div>
                            </div>
                            
                            <!-- Company Description -->
                            <div class="mt-6">
                                <label class="block text-sm font-medium text-gray-700 mb-2">Company Description</label>
                                <textarea name="company_description" rows="6" 
                                          class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition"
                                          placeholder="Tell job seekers about your company, mission, culture, and values..."><?= htmlspecialchars($employer['company_description'] ?? '') ?></textarea>
                                <p class="text-xs text-gray-500 mt-1">Maximum 2000 characters</p>
                            </div>
                        </div>
                        
                        <!-- Address Section -->
                        <div class="bg-white rounded-xl shadow-md p-6">
                            <h2 class="text-xl font-semibold text-gray-800 mb-6">Address</h2>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <!-- Street Address -->
                                <div class="md:col-span-2">
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Street Address</label>
                                    <input type="text" name="address"
                                           value="<?= htmlspecialchars($employer['address'] ?? '') ?>"
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
                            </div>
                        </div>
                        
                        <!-- Logo Upload Section -->
                        <div class="bg-white rounded-xl shadow-md p-6">
                            <h2 class="text-xl font-semibold text-gray-800 mb-6">Company Logo</h2>
                            
                            <div class="flex items-center space-x-6">
                                <!-- Logo Preview -->
                                <div class="flex-shrink-0">
                                    <?php if (!empty($employer['company_logo'])): ?>
                                        <img src="../<?= htmlspecialchars($employer['company_logo']) ?>" 
                                             alt="Current Logo" 
                                             class="h-32 w-32 object-cover rounded-lg border-2 border-gray-200">
                                    <?php else: ?>
                                        <div class="h-32 w-32 bg-gray-100 rounded-lg border-2 border-dashed border-gray-300 flex items-center justify-center">
                                            <i class="fas fa-image text-4xl text-gray-400"></i>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="flex-1">
                                    <div class="upload-area border-2 border-dashed border-gray-300 rounded-lg p-6 text-center">
                                        <input type="file" name="company_logo" id="logo" class="hidden" accept="image/*">
                                        <label for="logo" class="cursor-pointer">
                                            <i class="fas fa-cloud-upload-alt text-4xl text-gray-400 mb-2"></i>
                                            <p class="text-sm text-gray-600">Click to upload new logo</p>
                                            <p class="text-xs text-gray-500 mt-1">PNG, JPG, GIF up to 2MB</p>
                                        </label>
                                    </div>
                                    
                                    <?php if (!empty($employer['company_logo'])): ?>
                                        <div class="mt-4 flex space-x-3">
                                            <button type="button" onclick="removeLogo()" 
                                                    class="text-sm text-red-600 hover:text-red-800 transition">
                                                <i class="fas fa-trash-alt mr-1"></i> Remove Current Logo
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <div class="flex justify-end space-x-3">
                            <button type="reset" class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition">
                                Reset
                            </button>
                            <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
                                <i class="fas fa-save mr-2"></i> Save Changes
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Company Stats Tab -->
                <div id="statsTab" class="tab-content hidden">
                    <div class="bg-white rounded-xl shadow-md p-6">
                        <h2 class="text-xl font-semibold text-gray-800 mb-6">Company Performance</h2>
                        
                        <!-- Stats Grid -->
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                            <!-- Total Jobs -->
                            <div class="bg-gradient-to-br from-blue-50 to-blue-100 rounded-xl p-6">
                                <div class="flex items-center justify-between mb-2">
                                    <span class="text-blue-800 font-medium">Total Jobs</span>
                                    <i class="fas fa-briefcase text-blue-600 text-xl"></i>
                                </div>
                                <p class="text-3xl font-bold text-blue-900"><?= number_format($company_stats['total_jobs']) ?></p>
                                <p class="text-sm text-blue-700 mt-2">Lifetime postings</p>
                            </div>
                            
                            <!-- Active Jobs -->
                            <div class="bg-gradient-to-br from-green-50 to-green-100 rounded-xl p-6">
                                <div class="flex items-center justify-between mb-2">
                                    <span class="text-green-800 font-medium">Active Jobs</span>
                                    <i class="fas fa-check-circle text-green-600 text-xl"></i>
                                </div>
                                <p class="text-3xl font-bold text-green-900"><?= number_format($company_stats['active_jobs']) ?></p>
                                <p class="text-sm text-green-700 mt-2">Currently hiring</p>
                            </div>
                            
                            <!-- Total Hired -->
                            <div class="bg-gradient-to-br from-purple-50 to-purple-100 rounded-xl p-6">
                                <div class="flex items-center justify-between mb-2">
                                    <span class="text-purple-800 font-medium">Total Hired</span>
                                    <i class="fas fa-user-check text-purple-600 text-xl"></i>
                                </div>
                                <p class="text-3xl font-bold text-purple-900"><?= number_format($company_stats['total_hired']) ?></p>
                                <p class="text-sm text-purple-700 mt-2">Successful placements</p>
                            </div>
                            
                            <!-- Total Views -->
                            <div class="bg-gradient-to-br from-orange-50 to-orange-100 rounded-xl p-6">
                                <div class="flex items-center justify-between mb-2">
                                    <span class="text-orange-800 font-medium">Profile Views</span>
                                    <i class="fas fa-eye text-orange-600 text-xl"></i>
                                </div>
                                <p class="text-3xl font-bold text-orange-900"><?= number_format($company_stats['total_views']) ?></p>
                                <p class="text-sm text-orange-700 mt-2">All job listings</p>
                            </div>
                        </div>
                        
                        <!-- Recent Jobs Showcase -->
                        <h3 class="text-lg font-semibold text-gray-800 mb-4">Recent Job Postings</h3>
                        <div class="space-y-4">
                            <?php if (empty($recent_jobs)): ?>
                                <div class="text-center py-8 text-gray-500">
                                    <i class="fas fa-briefcase text-4xl mb-2 opacity-50"></i>
                                    <p>No jobs posted yet</p>
                                    <a href="post-job.php" class="mt-3 inline-block text-blue-600 hover:underline">
                                        Post your first job
                                    </a>
                                </div>
                            <?php else: 
                                foreach ($recent_jobs as $job): ?>
                                    <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                                        <div>
                                            <h4 class="font-medium text-gray-800"><?= htmlspecialchars($job['title']) ?></h4>
                                            <div class="flex items-center text-sm text-gray-600 mt-1">
                                                <i class="fas fa-map-marker-alt mr-1 text-gray-400"></i>
                                                <?= htmlspecialchars($job['city'] ?? 'Remote') ?>
                                                <span class="mx-2">•</span>
                                                <i class="fas fa-eye mr-1 text-gray-400"></i>
                                                <?= number_format($job['total_views'] ?? 0) ?> views
                                            </div>
                                        </div>
                                        <div class="text-right">
                                            <span class="px-2 py-1 text-xs rounded-full <?= $job['status'] === 'active' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800' ?>">
                                                <?= ucfirst($job['status']) ?>
                                            </span>
                                            <p class="text-xs text-gray-500 mt-1"><?= timeAgo($job['created_at']) ?></p>
                                        </div>
                                    </div>
                            <?php endforeach; 
                            endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Security Tab -->
                <div id="securityTab" class="tab-content hidden">
                    <div class="bg-white rounded-xl shadow-md p-6">
                        <h2 class="text-xl font-semibold text-gray-800 mb-6">Change Password</h2>
                        
                        <form method="POST" class="max-w-md space-y-4">
                            <input type="hidden" name="update_password" value="1">
                            
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
                            
                            <div class="pt-4">
                                <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
                                    <i class="fas fa-key mr-2"></i> Update Password
                                </button>
                            </div>
                        </form>
                        
                        <hr class="my-8 border-gray-200">
                        
                        <!-- Account Information -->
                        <h2 class="text-xl font-semibold text-gray-800 mb-4">Account Information</h2>
                        <div class="bg-gray-50 rounded-lg p-4 space-y-2">
                            <p class="text-sm">
                                <span class="font-medium text-gray-700">Account Type:</span>
                                <span class="text-gray-600 ml-2">Employer Account</span>
                            </p>
                            <p class="text-sm">
                                <span class="font-medium text-gray-700">Member Since:</span>
                                <span class="text-gray-600 ml-2"><?= date('F j, Y', strtotime($employer['account_created'] ?? 'now')) ?></span>
                            </p>
                            <p class="text-sm">
                                <span class="font-medium text-gray-700">Last Login:</span>
                                <span class="text-gray-600 ml-2"><?= timeAgo($employer['last_login'] ?? null) ?></span>
                            </p>
                            <p class="text-sm">
                                <span class="font-medium text-gray-700">Email Verified:</span>
                                <span class="text-green-600 ml-2"><i class="fas fa-check-circle"></i> Yes</span>
                            </p>
                        </div>
                        
                        <!-- Danger Zone -->
                        <div class="mt-8 p-4 border border-red-200 rounded-lg bg-red-50">
                            <h3 class="text-lg font-semibold text-red-800 mb-2">Danger Zone</h3>
                            <p class="text-sm text-red-600 mb-4">Once you delete your account, there is no going back. Please be certain.</p>
                            <button onclick="confirmDelete()" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition">
                                <i class="fas fa-exclamation-triangle mr-2"></i> Delete Account
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Hidden Form for Logo Removal -->
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
            
            // Logo upload preview
            const logoInput = document.getElementById('logo');
            if (logoInput) {
                logoInput.addEventListener('change', function(e) {
                    if (this.files && this.files[0]) {
                        // Optional: Add preview functionality
                        const reader = new FileReader();
                        reader.onload = function(e) {
                            // You can update a preview image here if you want
                            console.log('Logo selected');
                        };
                        reader.readAsDataURL(this.files[0]);
                    }
                });
            }
        });

        // Tab switching
        function showTab(tabName) {
            // Hide all tabs
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.add('hidden');
            });
            
            // Show selected tab
            document.getElementById(tabName + 'Tab').classList.remove('hidden');
            
            // Update tab buttons
            document.querySelectorAll('.profile-tab').forEach(btn => {
                btn.classList.remove('active', 'text-blue-600', 'border-blue-600');
                btn.classList.add('text-gray-500', 'border-transparent');
            });
            
            // Activate clicked tab
            const activeTab = document.getElementById('tab' + tabName.charAt(0).toUpperCase() + tabName.slice(1));
            if (activeTab) {
                activeTab.classList.add('active', 'text-blue-600', 'border-blue-600');
                activeTab.classList.remove('text-gray-500', 'border-transparent');
            }
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

        // Confirm account deletion
        function confirmDelete() {
            if (confirm('WARNING: This will permanently delete your account and all associated data. Are you absolutely sure?')) {
                if (confirm('This action cannot be undone. Type "DELETE" to confirm.')) {
                    window.location.href = 'delete-account.php';
                }
            }
        }

        // Handle URL hash for direct tab access
        if (window.location.hash) {
            const tab = window.location.hash.substring(1);
            if (['company', 'stats', 'security'].includes(tab)) {
                showTab(tab);
            }
        }
    </script>
</body>
</html>