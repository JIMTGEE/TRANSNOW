<?php
// employer/post-job.php
// Post new job page with PDO implementation

error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../includes/auth.php';

// Require employer login
requireEmployerLogin();

// Get database connection
$pdo = getDB();
if (!$pdo) {
    die("Database connection failed");
}

// Get current employer ID
$employer_id = getCurrentEmployerId($pdo);
if (!$employer_id) {
    die("Employer ID not found");
}

// Get employer company info
$employer = fetchOne("SELECT e.*, u.email, u.created_at as account_created 
                      FROM employers e 
                      JOIN users u ON e.user_id = u.id 
                      WHERE e.id = ?", [$employer_id]);

if (!$employer) {
    die("Employer not found");
}

// Get job categories for dropdown
$categories = fetchAll("SELECT id, name FROM job_categories WHERE is_active = 1 ORDER BY name");
if ($categories === false) {
    $categories = [];
}

// Get job types for dropdown
$job_types = fetchAll("SELECT id, name FROM job_types WHERE is_active = 1 ORDER BY name");
if ($job_types === false) {
    $job_types = [];
}

// Handle form submission
$errors = [];
$success = false;
$job_id = null;
$form_data = $_POST;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Sanitize and validate inputs
    $title = isset($_POST['title']) ? trim($_POST['title']) : '';
    $category_id = isset($_POST['category_id']) ? (int)$_POST['category_id'] : 0;
    $job_type_id = isset($_POST['job_type_id']) ? (int)$_POST['job_type_id'] : 0;
    $description = isset($_POST['description']) ? trim($_POST['description']) : '';
    $requirements = isset($_POST['requirements']) ? trim($_POST['requirements']) : '';
    $responsibilities = isset($_POST['responsibilities']) ? trim($_POST['responsibilities']) : '';
    $benefits = isset($_POST['benefits']) ? trim($_POST['benefits']) : '';
    $experience_level = isset($_POST['experience_level']) ? $_POST['experience_level'] : 'mid';
    $salary_min = !empty($_POST['salary_min']) ? (float)$_POST['salary_min'] : null;
    $salary_max = !empty($_POST['salary_max']) ? (float)$_POST['salary_max'] : null;
    $salary_currency = isset($_POST['salary_currency']) ? $_POST['salary_currency'] : 'USD';
    $salary_type = isset($_POST['salary_type']) ? $_POST['salary_type'] : 'yearly';
    $is_salary_negotiable = isset($_POST['is_salary_negotiable']) ? 1 : 0;
    $is_salary_visible = isset($_POST['is_salary_visible']) ? 1 : 0;
    $location_type = isset($_POST['location_type']) ? $_POST['location_type'] : 'onsite';
    $address = isset($_POST['address']) ? trim($_POST['address']) : '';
    $city = isset($_POST['city']) ? trim($_POST['city']) : '';
    $state = isset($_POST['state']) ? trim($_POST['state']) : '';
    $country = isset($_POST['country']) ? trim($_POST['country']) : '';
    $application_deadline = !empty($_POST['application_deadline']) ? $_POST['application_deadline'] : null;
    $total_vacancies = isset($_POST['total_vacancies']) ? (int)$_POST['total_vacancies'] : 1;
    $status = isset($_POST['status']) ? $_POST['status'] : 'draft';
    $is_featured = isset($_POST['is_featured']) ? 1 : 0;
    $is_urgent = isset($_POST['is_urgent']) ? 1 : 0;
    
    // Skills array
    $skills = isset($_POST['skills']) ? $_POST['skills'] : [];
    $required_skills = isset($_POST['required_skills']) ? $_POST['required_skills'] : [];
    
    // Validation
    if (empty($title)) {
        $errors['title'] = 'Job title is required';
    }
    
    if ($category_id <= 0) {
        $errors['category_id'] = 'Please select a job category';
    }
    
    if ($job_type_id <= 0) {
        $errors['job_type_id'] = 'Please select a job type';
    }
    
    if (empty($description)) {
        $errors['description'] = 'Job description is required';
    }
    
    // Strip tags for character count
    $description_text = strip_tags($description);
    if (strlen($description_text) < 100) {
        $errors['description'] = 'Job description should be at least 100 characters';
    }
    
    if ($salary_min && $salary_max && $salary_min > $salary_max) {
        $errors['salary'] = 'Minimum salary cannot be greater than maximum salary';
    }
    
    if ($application_deadline && strtotime($application_deadline) < strtotime('today')) {
        $errors['application_deadline'] = 'Application deadline must be in the future';
    }
    
    if ($total_vacancies < 1) {
        $errors['total_vacancies'] = 'Total vacancies must be at least 1';
    }
    
    // If no errors, insert job
    if (empty($errors)) {
        try {
            // Generate slug from title
            $slug = createSlug($title);
            
            // Check if slug exists and make it unique
            $existing = fetchOne("SELECT id FROM jobs WHERE slug = ? AND employer_id = ?", [$slug, $employer_id]);
            if ($existing) {
                $slug = $slug . '-' . uniqid();
            }
            
            // Begin transaction
            $pdo->beginTransaction();
            
            // Insert job
            $sql = "INSERT INTO jobs (
                employer_id, title, slug, description, requirements, responsibilities, benefits,
                category_id, job_type_id, experience_level, salary_min, salary_max, salary_currency,
                salary_type, is_salary_negotiable, is_salary_visible, location_type, address, city,
                state, country, application_deadline, total_vacancies, status, is_featured, is_urgent,
                created_at, updated_at
            ) VALUES (
                :employer_id, :title, :slug, :description, :requirements, :responsibilities, :benefits,
                :category_id, :job_type_id, :experience_level, :salary_min, :salary_max, :salary_currency,
                :salary_type, :is_salary_negotiable, :is_salary_visible, :location_type, :address, :city,
                :state, :country, :application_deadline, :total_vacancies, :status, :is_featured, :is_urgent,
                NOW(), NOW()
            )";
            
            $stmt = $pdo->prepare($sql);
            $params = [
                ':employer_id' => $employer_id,
                ':title' => $title,
                ':slug' => $slug,
                ':description' => $description,
                ':requirements' => $requirements,
                ':responsibilities' => $responsibilities,
                ':benefits' => $benefits,
                ':category_id' => $category_id,
                ':job_type_id' => $job_type_id,
                ':experience_level' => $experience_level,
                ':salary_min' => $salary_min,
                ':salary_max' => $salary_max,
                ':salary_currency' => $salary_currency,
                ':salary_type' => $salary_type,
                ':is_salary_negotiable' => $is_salary_negotiable,
                ':is_salary_visible' => $is_salary_visible,
                ':location_type' => $location_type,
                ':address' => $address,
                ':city' => $city,
                ':state' => $state,
                ':country' => $country,
                ':application_deadline' => $application_deadline,
                ':total_vacancies' => $total_vacancies,
                ':status' => $status,
                ':is_featured' => $is_featured,
                ':is_urgent' => $is_urgent
            ];
            
            $stmt->execute($params);
            $job_id = $pdo->lastInsertId();
            
            // Insert skills
            if (!empty($skills)) {
                $skill_sql = "INSERT INTO job_skills (job_id, skill_name, is_required) VALUES (?, ?, ?)";
                $skill_stmt = $pdo->prepare($skill_sql);
                
                foreach ($skills as $index => $skill) {
                    if (!empty(trim($skill))) {
                        $is_required = in_array($index, $required_skills) ? 1 : 0;
                        $skill_stmt->execute([$job_id, trim($skill), $is_required]);
                    }
                }
            }
            
            // Commit transaction
            $pdo->commit();
            
            // Log activity
            if (function_exists('logUserActivity')) {
                logUserActivity('job_posted', "Posted new job: {$title}", $pdo);
            }
            
            // Set success message
            $_SESSION['flash_message'] = [
                'type' => 'success',
                'text' => 'Job posted successfully!'
            ];
            
            // Redirect based on action
            if (isset($_POST['save_and_preview'])) {
                header("Location: preview-job.php?id=" . $job_id);
                exit();
            } elseif (isset($_POST['save_and_publish'])) {
                // Update status to active
                $updateStmt = $pdo->prepare("UPDATE jobs SET status = 'active', published_at = NOW() WHERE id = ?");
                $updateStmt->execute([$job_id]);
                $_SESSION['flash_message']['text'] = 'Job published successfully!';
                header("Location: manage-jobs.php");
                exit();
            } else {
                header("Location: manage-jobs.php");
                exit();
            }
            
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors['database'] = 'Failed to post job: ' . $e->getMessage();
            error_log("Job posting error: " . $e->getMessage());
        }
    }
}

// Get unread notifications count
$unread_notifications = 0;
if (isset($_SESSION['user_id'])) {
    $result = fetchOne("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND channel = 'inapp' AND status = 'sent'", [$_SESSION['user_id']]);
    $unread_notifications = $result ? $result['count'] : 0;
}

// Page title
$page_title = "Post New Job";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?> - Job Portal</title>
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    
    <!-- Quill Rich Text Editor -->
    <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
    
    <!-- Flatpickr CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    
    <style>
        .ql-editor {
            min-height: 200px;
            max-height: 400px;
            overflow-y: auto;
        }
        .error-border {
            border-color: #ef4444 !important;
        }
        .error-message {
            color: #ef4444;
            font-size: 0.875rem;
            margin-top: 0.25rem;
        }
        .skill-tag {
            display: inline-flex;
            align-items: center;
            background-color: #e5e7eb;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            margin: 0.25rem;
            font-size: 0.875rem;
        }
        .skill-tag button:hover {
            color: #ef4444;
        }
        
        /* Logout confirmation modal */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(0, 0, 0, 0.5);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }
        
        .modal-overlay.active {
            display: flex;
        }
        
        .modal-content {
            animation: slideIn 0.3s ease-out;
            max-width: 400px;
            width: 90%;
        }
        
        @keyframes slideIn {
            from {
                transform: translateY(-20px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
    </style>
</head>
<body class="bg-gray-50">
    <!-- Logout Confirmation Modal -->
    <div id="logoutModal" class="modal-overlay">
        <div class="modal-content bg-white rounded-xl shadow-2xl p-6">
            <div class="text-center">
                <div class="w-16 h-16 mx-auto bg-red-100 rounded-full flex items-center justify-center mb-4">
                    <i class="fas fa-sign-out-alt text-red-600 text-2xl"></i>
                </div>
                <h3 class="text-xl font-bold text-gray-900 mb-2">Confirm Logout</h3>
                <p class="text-gray-600 mb-6">Are you sure you want to logout from your employer account?</p>
                <div class="flex space-x-3">
                    <button onclick="closeLogoutModal()" 
                            class="flex-1 px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition font-medium">
                        <i class="fas fa-times mr-2"></i>Cancel
                    </button>
                    <a href="../logout.php" 
                       class="flex-1 px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition font-medium text-center">
                        <i class="fas fa-sign-out-alt mr-2"></i>Logout
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Navigation -->
    <nav class="bg-white shadow-lg border-b border-gray-200 fixed w-full z-50 top-0">
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
                        <a href="post-job.php" class="text-blue-600 border-b-2 border-blue-600 px-3 py-2 text-sm font-medium">Post Job</a>
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
                                if (isset($_SESSION['user_id'])) {
                                    $notifications = fetchAll("SELECT * FROM notifications 
                                                              WHERE user_id = ? AND channel = 'inapp' 
                                                              ORDER BY created_at DESC LIMIT 5", 
                                                              [$_SESSION['user_id']]);
                                } else {
                                    $notifications = [];
                                }
                                
                                if (empty($notifications)): ?>
                                    <div class="p-4 text-center text-gray-500">
                                        <i class="fas fa-bell-slash text-3xl mb-2"></i>
                                        <p>No notifications</p>
                                    </div>
                                <?php else: 
                                    foreach ($notifications as $note): ?>
                                        <div class="p-4 hover:bg-gray-50 border-b border-gray-100 <?= ($note['status'] ?? '') === 'sent' ? 'bg-blue-50' : '' ?>">
                                            <p class="font-medium text-gray-800"><?= htmlspecialchars($note['subject'] ?? '') ?></p>
                                            <p class="text-sm text-gray-600"><?= htmlspecialchars($note['message'] ?? '') ?></p>
                                            <p class="text-xs text-gray-400 mt-1"><?= isset($note['created_at']) ? timeAgo($note['created_at']) : '' ?></p>
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
                            <a href="settings.php" class="block px-4 py-3 text-gray-700 hover:bg-gray-50 transition">
                                <i class="fas fa-cog mr-2"></i> Settings
                            </a>
                            <hr class="border-gray-200">
                            <a href="#" onclick="showLogoutModal(); return false;" class="block px-4 py-3 text-red-600 hover:bg-red-50 transition">
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
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            
            <!-- Flash Messages -->
            <?php if (isset($_SESSION['flash_message'])): ?>
                <div class="mb-4 p-4 rounded-lg <?= $_SESSION['flash_message']['type'] === 'success' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' ?>">
                    <?= htmlspecialchars($_SESSION['flash_message']['text']) ?>
                </div>
                <?php unset($_SESSION['flash_message']); ?>
            <?php endif; ?>
            
            <!-- Header -->
            <div class="mb-8">
                <h1 class="text-3xl font-bold text-gray-900">Post a New Job</h1>
                <p class="text-gray-600 mt-2">Fill in the details below to create a new job listing</p>
            </div>

            <!-- Error Summary -->
            <?php if (!empty($errors)): ?>
                <div class="mb-6 bg-red-50 border-l-4 border-red-500 p-4 rounded-lg">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <i class="fas fa-exclamation-circle text-red-500"></i>
                        </div>
                        <div class="ml-3">
                            <h3 class="text-sm font-medium text-red-800">Please fix the following errors:</h3>
                            <ul class="mt-2 text-sm text-red-700 list-disc list-inside">
                                <?php foreach ($errors as $error): ?>
                                    <li><?= htmlspecialchars($error) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Job Post Form -->
            <form method="POST" action="" id="jobPostForm" class="space-y-6">
                <!-- Basic Information -->
                <div class="bg-white rounded-xl shadow-md overflow-hidden">
                    <div class="px-6 py-4 bg-gray-50 border-b border-gray-200">
                        <h2 class="text-lg font-semibold text-gray-800">
                            <i class="fas fa-info-circle text-blue-600 mr-2"></i>
                            Basic Information
                        </h2>
                    </div>
                    
                    <div class="p-6 space-y-6">
                        <!-- Job Title -->
                        <div>
                            <label for="title" class="block text-sm font-medium text-gray-700 mb-2">
                                Job Title <span class="text-red-500">*</span>
                            </label>
                            <input type="text" 
                                   id="title" 
                                   name="title" 
                                   value="<?= htmlspecialchars($_POST['title'] ?? '') ?>"
                                   class="w-full px-4 py-2 border <?= isset($errors['title']) ? 'error-border' : 'border-gray-300' ?> rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition"
                                   placeholder="e.g. Senior PHP Developer">
                            <?php if (isset($errors['title'])): ?>
                                <p class="error-message"><?= htmlspecialchars($errors['title']) ?></p>
                            <?php endif; ?>
                        </div>

                        <!-- Category and Type -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="category_id" class="block text-sm font-medium text-gray-700 mb-2">
                                    Job Category <span class="text-red-500">*</span>
                                </label>
                                <select id="category_id" 
                                        name="category_id" 
                                        class="w-full px-4 py-2 border <?= isset($errors['category_id']) ? 'error-border' : 'border-gray-300' ?> rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition">
                                    <option value="">Select Category</option>
                                    <?php foreach ($categories as $category): ?>
                                        <option value="<?= $category['id'] ?>" <?= (($_POST['category_id'] ?? '') == $category['id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($category['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (isset($errors['category_id'])): ?>
                                    <p class="error-message"><?= htmlspecialchars($errors['category_id']) ?></p>
                                <?php endif; ?>
                            </div>

                            <div>
                                <label for="job_type_id" class="block text-sm font-medium text-gray-700 mb-2">
                                    Job Type <span class="text-red-500">*</span>
                                </label>
                                <select id="job_type_id" 
                                        name="job_type_id" 
                                        class="w-full px-4 py-2 border <?= isset($errors['job_type_id']) ? 'error-border' : 'border-gray-300' ?> rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition">
                                    <option value="">Select Job Type</option>
                                    <?php foreach ($job_types as $type): ?>
                                        <option value="<?= $type['id'] ?>" <?= (($_POST['job_type_id'] ?? '') == $type['id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($type['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (isset($errors['job_type_id'])): ?>
                                    <p class="error-message"><?= htmlspecialchars($errors['job_type_id']) ?></p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Experience Level -->
                        <div>
                            <label for="experience_level" class="block text-sm font-medium text-gray-700 mb-2">
                                Experience Level
                            </label>
                            <select id="experience_level" 
                                    name="experience_level" 
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition">
                                <option value="entry" <?= (($_POST['experience_level'] ?? 'mid') == 'entry') ? 'selected' : '' ?>>Entry Level</option>
                                <option value="mid" <?= (($_POST['experience_level'] ?? 'mid') == 'mid') ? 'selected' : '' ?>>Mid Level</option>
                                <option value="senior" <?= (($_POST['experience_level'] ?? 'mid') == 'senior') ? 'selected' : '' ?>>Senior Level</option>
                                <option value="executive" <?= (($_POST['experience_level'] ?? 'mid') == 'executive') ? 'selected' : '' ?>>Executive</option>
                            </select>
                        </div>

                        <!-- Vacancies -->
                        <div>
                            <label for="total_vacancies" class="block text-sm font-medium text-gray-700 mb-2">
                                Number of Vacancies <span class="text-red-500">*</span>
                            </label>
                            <input type="number" 
                                   id="total_vacancies" 
                                   name="total_vacancies" 
                                   value="<?= htmlspecialchars($_POST['total_vacancies'] ?? 1) ?>"
                                   min="1"
                                   class="w-full px-4 py-2 border <?= isset($errors['total_vacancies']) ? 'error-border' : 'border-gray-300' ?> rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition">
                            <?php if (isset($errors['total_vacancies'])): ?>
                                <p class="error-message"><?= htmlspecialchars($errors['total_vacancies']) ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Job Description -->
                <div class="bg-white rounded-xl shadow-md overflow-hidden">
                    <div class="px-6 py-4 bg-gray-50 border-b border-gray-200">
                        <h2 class="text-lg font-semibold text-gray-800">
                            <i class="fas fa-file-alt text-blue-600 mr-2"></i>
                            Job Description
                        </h2>
                    </div>
                    
                    <div class="p-6 space-y-6">
                        <!-- Description -->
                        <div>
                            <label for="description" class="block text-sm font-medium text-gray-700 mb-2">
                                Full Job Description <span class="text-red-500">*</span>
                            </label>
                            <div id="description-editor" class="border <?= isset($errors['description']) ? 'error-border' : 'border-gray-300' ?> rounded-lg"></div>
                            <textarea name="description" id="description-hidden" class="hidden"><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
                            <?php if (isset($errors['description'])): ?>
                                <p class="error-message"><?= htmlspecialchars($errors['description']) ?></p>
                            <?php endif; ?>
                        </div>

                        <!-- Requirements -->
                        <div>
                            <label for="requirements" class="block text-sm font-medium text-gray-700 mb-2">
                                Requirements
                            </label>
                            <div id="requirements-editor" class="border border-gray-300 rounded-lg"></div>
                            <textarea name="requirements" id="requirements-hidden" class="hidden"><?= htmlspecialchars($_POST['requirements'] ?? '') ?></textarea>
                        </div>

                        <!-- Responsibilities -->
                        <div>
                            <label for="responsibilities" class="block text-sm font-medium text-gray-700 mb-2">
                                Responsibilities
                            </label>
                            <div id="responsibilities-editor" class="border border-gray-300 rounded-lg"></div>
                            <textarea name="responsibilities" id="responsibilities-hidden" class="hidden"><?= htmlspecialchars($_POST['responsibilities'] ?? '') ?></textarea>
                        </div>

                        <!-- Benefits -->
                        <div>
                            <label for="benefits" class="block text-sm font-medium text-gray-700 mb-2">
                                Benefits
                            </label>
                            <div id="benefits-editor" class="border border-gray-300 rounded-lg"></div>
                            <textarea name="benefits" id="benefits-hidden" class="hidden"><?= htmlspecialchars($_POST['benefits'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>

                <!-- Skills -->
                <div class="bg-white rounded-xl shadow-md overflow-hidden">
                    <div class="px-6 py-4 bg-gray-50 border-b border-gray-200">
                        <h2 class="text-lg font-semibold text-gray-800">
                            <i class="fas fa-tools text-blue-600 mr-2"></i>
                            Skills Required
                        </h2>
                    </div>
                    
                    <div class="p-6">
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                Add Skills
                            </label>
                            <div class="flex space-x-2">
                                <input type="text" 
                                       id="skill-input" 
                                       class="flex-1 px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition"
                                       placeholder="e.g. PHP, JavaScript, Laravel">
                                <button type="button" 
                                        onclick="addSkill()"
                                        class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
                                    <i class="fas fa-plus"></i> Add
                                </button>
                            </div>
                        </div>
                        
                        <div id="skills-container" class="flex flex-wrap items-center gap-2 p-4 bg-gray-50 rounded-lg min-h-[100px]">
                            <!-- Skills will be added here dynamically -->
                        </div>
                        
                        <p class="text-xs text-gray-500 mt-2">
                            <i class="fas fa-info-circle mr-1"></i>
                            Click on the star to mark a skill as required
                        </p>
                    </div>
                </div>

                <!-- Salary Information -->
                <div class="bg-white rounded-xl shadow-md overflow-hidden">
                    <div class="px-6 py-4 bg-gray-50 border-b border-gray-200">
                        <h2 class="text-lg font-semibold text-gray-800">
                            <i class="fas fa-money-bill-wave text-blue-600 mr-2"></i>
                            Salary Information
                        </h2>
                    </div>
                    
                    <div class="p-6 space-y-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="salary_min" class="block text-sm font-medium text-gray-700 mb-2">
                                    Minimum Salary
                                </label>
                                <input type="number" 
                                       id="salary_min" 
                                       name="salary_min" 
                                       value="<?= htmlspecialchars($_POST['salary_min'] ?? '') ?>"
                                       step="0.01"
                                       min="0"
                                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition"
                                       placeholder="e.g. 50000">
                            </div>

                            <div>
                                <label for="salary_max" class="block text-sm font-medium text-gray-700 mb-2">
                                    Maximum Salary
                                </label>
                                <input type="number" 
                                       id="salary_max" 
                                       name="salary_max" 
                                       value="<?= htmlspecialchars($_POST['salary_max'] ?? '') ?>"
                                       step="0.01"
                                       min="0"
                                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition"
                                       placeholder="e.g. 80000">
                            </div>
                        </div>

                        <?php if (isset($errors['salary'])): ?>
                            <p class="error-message"><?= htmlspecialchars($errors['salary']) ?></p>
                        <?php endif; ?>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div>
                                <label for="salary_currency" class="block text-sm font-medium text-gray-700 mb-2">
                                    Currency
                                </label>
                                <select id="salary_currency" 
                                        name="salary_currency" 
                                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition">
                                    <option value="USD" <?= (($_POST['salary_currency'] ?? 'USD') == 'USD') ? 'selected' : '' ?>>USD ($)</option>
                                    <option value="EUR" <?= (($_POST['salary_currency'] ?? 'USD') == 'EUR') ? 'selected' : '' ?>>EUR (€)</option>
                                    <option value="GBP" <?= (($_POST['salary_currency'] ?? 'USD') == 'GBP') ? 'selected' : '' ?>>GBP (£)</option>
                                    <option value="INR" <?= (($_POST['salary_currency'] ?? 'USD') == 'INR') ? 'selected' : '' ?>>INR (₹)</option>
                                </select>
                            </div>

                            <div>
                                <label for="salary_type" class="block text-sm font-medium text-gray-700 mb-2">
                                    Salary Type
                                </label>
                                <select id="salary_type" 
                                        name="salary_type" 
                                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition">
                                    <option value="hourly" <?= (($_POST['salary_type'] ?? 'yearly') == 'hourly') ? 'selected' : '' ?>>Per Hour</option>
                                    <option value="monthly" <?= (($_POST['salary_type'] ?? 'yearly') == 'monthly') ? 'selected' : '' ?>>Per Month</option>
                                    <option value="yearly" <?= (($_POST['salary_type'] ?? 'yearly') == 'yearly') ? 'selected' : '' ?>>Per Year</option>
                                </select>
                            </div>

                            <div class="flex items-end space-x-4">
                                <label class="flex items-center">
                                    <input type="checkbox" 
                                           name="is_salary_negotiable" 
                                           value="1"
                                           <?= isset($_POST['is_salary_negotiable']) ? 'checked' : '' ?>
                                           class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                    <span class="ml-2 text-sm text-gray-700">Negotiable</span>
                                </label>
                                
                                <label class="flex items-center">
                                    <input type="checkbox" 
                                           name="is_salary_visible" 
                                           value="1"
                                           <?= !isset($_POST['is_salary_visible']) || $_POST['is_salary_visible'] ? 'checked' : '' ?>
                                           class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                    <span class="ml-2 text-sm text-gray-700">Visible</span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Location Information -->
                <div class="bg-white rounded-xl shadow-md overflow-hidden">
                    <div class="px-6 py-4 bg-gray-50 border-b border-gray-200">
                        <h2 class="text-lg font-semibold text-gray-800">
                            <i class="fas fa-map-marker-alt text-blue-600 mr-2"></i>
                            Location
                        </h2>
                    </div>
                    
                    <div class="p-6 space-y-6">
                        <!-- Location Type -->
                        <div>
                            <label for="location_type" class="block text-sm font-medium text-gray-700 mb-2">
                                Location Type
                            </label>
                            <select id="location_type" 
                                    name="location_type" 
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition">
                                <option value="onsite" <?= (($_POST['location_type'] ?? 'onsite') == 'onsite') ? 'selected' : '' ?>>On-site</option>
                                <option value="remote" <?= (($_POST['location_type'] ?? 'onsite') == 'remote') ? 'selected' : '' ?>>Remote</option>
                                <option value="hybrid" <?= (($_POST['location_type'] ?? 'onsite') == 'hybrid') ? 'selected' : '' ?>>Hybrid</option>
                            </select>
                        </div>

                        <!-- Address Fields -->
                        <div id="location-fields">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div class="md:col-span-2">
                                    <label for="address" class="block text-sm font-medium text-gray-700 mb-2">
                                        Street Address
                                    </label>
                                    <input type="text" 
                                           id="address" 
                                           name="address" 
                                           value="<?= htmlspecialchars($_POST['address'] ?? '') ?>"
                                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition"
                                           placeholder="e.g. 123 Main Street">
                                </div>

                                <div>
                                    <label for="city" class="block text-sm font-medium text-gray-700 mb-2">
                                        City
                                    </label>
                                    <input type="text" 
                                           id="city" 
                                           name="city" 
                                           value="<?= htmlspecialchars($_POST['city'] ?? '') ?>"
                                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition"
                                           placeholder="e.g. New York">
                                </div>

                                <div>
                                    <label for="state" class="block text-sm font-medium text-gray-700 mb-2">
                                        State / Province
                                    </label>
                                    <input type="text" 
                                           id="state" 
                                           name="state" 
                                           value="<?= htmlspecialchars($_POST['state'] ?? '') ?>"
                                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition"
                                           placeholder="e.g. NY">
                                </div>

                                <div>
                                    <label for="country" class="block text-sm font-medium text-gray-700 mb-2">
                                        Country
                                    </label>
                                    <input type="text" 
                                           id="country" 
                                           name="country" 
                                           value="<?= htmlspecialchars($_POST['country'] ?? '') ?>"
                                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition"
                                           placeholder="e.g. United States">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Additional Information -->
                <div class="bg-white rounded-xl shadow-md overflow-hidden">
                    <div class="px-6 py-4 bg-gray-50 border-b border-gray-200">
                        <h2 class="text-lg font-semibold text-gray-800">
                            <i class="fas fa-clock text-blue-600 mr-2"></i>
                            Additional Information
                        </h2>
                    </div>
                    
                    <div class="p-6 space-y-6">
                        <!-- Application Deadline -->
                        <div>
                            <label for="application_deadline" class="block text-sm font-medium text-gray-700 mb-2">
                                Application Deadline
                            </label>
                            <input type="text" 
                                   id="application_deadline" 
                                   name="application_deadline" 
                                   value="<?= htmlspecialchars($_POST['application_deadline'] ?? '') ?>"
                                   class="w-full px-4 py-2 border <?= isset($errors['application_deadline']) ? 'error-border' : 'border-gray-300' ?> rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition"
                                   placeholder="Select a date"
                                   autocomplete="off">
                            <?php if (isset($errors['application_deadline'])): ?>
                                <p class="error-message"><?= htmlspecialchars($errors['application_deadline']) ?></p>
                            <?php endif; ?>
                        </div>

                        <!-- Job Status and Features -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="status" class="block text-sm font-medium text-gray-700 mb-2">
                                    Job Status
                                </label>
                                <select id="status" 
                                        name="status" 
                                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition">
                                    <option value="draft" <?= (($_POST['status'] ?? 'draft') == 'draft') ? 'selected' : '' ?>>Save as Draft</option>
                                    <option value="active" <?= (($_POST['status'] ?? 'draft') == 'active') ? 'selected' : '' ?>>Publish Immediately</option>
                                </select>
                            </div>

                            <div class="flex items-end space-x-4">
                                <label class="flex items-center">
                                    <input type="checkbox" 
                                           name="is_featured" 
                                           value="1"
                                           <?= isset($_POST['is_featured']) ? 'checked' : '' ?>
                                           class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                    <span class="ml-2 text-sm text-gray-700">Feature this job</span>
                                </label>
                                
                                <label class="flex items-center">
                                    <input type="checkbox" 
                                           name="is_urgent" 
                                           value="1"
                                           <?= isset($_POST['is_urgent']) ? 'checked' : '' ?>
                                           class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                    <span class="ml-2 text-sm text-gray-700">Mark as urgent</span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Form Actions -->
                <div class="flex items-center justify-between space-x-4">
                    <a href="manage-jobs.php" class="px-6 py-3 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition font-medium">
                        <i class="fas fa-arrow-left mr-2"></i>
                        Cancel
                    </a>
                    
                    <div class="flex space-x-4">
                        <button type="submit" 
                                name="save_and_preview"
                                class="px-6 py-3 border border-blue-600 text-blue-600 rounded-lg hover:bg-blue-50 transition font-medium">
                            <i class="fas fa-eye mr-2"></i>
                            Save & Preview
                        </button>
                        
                        <button type="submit" 
                                name="save_and_publish"
                                class="px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition font-medium">
                            <i class="fas fa-check-circle mr-2"></i>
                            Publish Job
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- JavaScript Libraries -->
    <script src="https://cdn.quilljs.com/1.3.6/quill.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

    <script>
        // Logout Modal Functions
        function showLogoutModal() {
            document.getElementById('logoutModal').classList.add('active');
        }
        
        function closeLogoutModal() {
            document.getElementById('logoutModal').classList.remove('active');
        }
        
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
            
            // Close modal when clicking outside
            const modal = document.getElementById('logoutModal');
            if (modal) {
                modal.addEventListener('click', function(e) {
                    if (e.target === modal) {
                        closeLogoutModal();
                    }
                });
            }
            
            // Close modal with Escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    closeLogoutModal();
                }
            });
        });

        // Initialize Quill editors
        const descriptionEditor = new Quill('#description-editor', {
            theme: 'snow',
            placeholder: 'Write a detailed job description...',
            modules: {
                toolbar: [
                    [{ 'header': [1, 2, 3, 4, 5, 6, false] }],
                    ['bold', 'italic', 'underline', 'strike'],
                    [{ 'list': 'ordered'}, { 'list': 'bullet' }],
                    ['link', 'clean']
                ]
            }
        });

        const requirementsEditor = new Quill('#requirements-editor', {
            theme: 'snow',
            placeholder: 'List the requirements for this position...',
            modules: {
                toolbar: [
                    ['bold', 'italic', 'underline'],
                    [{ 'list': 'ordered'}, { 'list': 'bullet' }],
                    ['link', 'clean']
                ]
            }
        });

        const responsibilitiesEditor = new Quill('#responsibilities-editor', {
            theme: 'snow',
            placeholder: 'Describe the key responsibilities...',
            modules: {
                toolbar: [
                    ['bold', 'italic', 'underline'],
                    [{ 'list': 'ordered'}, { 'list': 'bullet' }],
                    ['link', 'clean']
                ]
            }
        });

        const benefitsEditor = new Quill('#benefits-editor', {
            theme: 'snow',
            placeholder: 'List the benefits and perks...',
            modules: {
                toolbar: [
                    ['bold', 'italic', 'underline'],
                    [{ 'list': 'ordered'}, { 'list': 'bullet' }],
                    ['link', 'clean']
                ]
            }
        });

        // Set initial content if exists
        <?php if (!empty($_POST['description'])): ?>
        descriptionEditor.root.innerHTML = <?= json_encode($_POST['description']) ?>;
        <?php endif; ?>
        
        <?php if (!empty($_POST['requirements'])): ?>
        requirementsEditor.root.innerHTML = <?= json_encode($_POST['requirements']) ?>;
        <?php endif; ?>
        
        <?php if (!empty($_POST['responsibilities'])): ?>
        responsibilitiesEditor.root.innerHTML = <?= json_encode($_POST['responsibilities']) ?>;
        <?php endif; ?>
        
        <?php if (!empty($_POST['benefits'])): ?>
        benefitsEditor.root.innerHTML = <?= json_encode($_POST['benefits']) ?>;
        <?php endif; ?>

        // Update hidden textareas before form submit
        document.getElementById('jobPostForm').addEventListener('submit', function() {
            document.getElementById('description-hidden').value = descriptionEditor.root.innerHTML;
            document.getElementById('requirements-hidden').value = requirementsEditor.root.innerHTML;
            document.getElementById('responsibilities-hidden').value = responsibilitiesEditor.root.innerHTML;
            document.getElementById('benefits-hidden').value = benefitsEditor.root.innerHTML;
        });

        // Initialize Flatpickr for date picker
        flatpickr("#application_deadline", {
            minDate: "today",
            dateFormat: "Y-m-d",
            altInput: true,
            altFormat: "F j, Y",
            allowInput: true
        });

        // Skills Management
        let skills = [];
        let requiredSkills = [];

        function addSkill() {
            const input = document.getElementById('skill-input');
            const skill = input.value.trim();
            
            if (skill && !skills.includes(skill)) {
                skills.push(skill);
                renderSkills();
                input.value = '';
            }
        }

        function removeSkill(index) {
            skills.splice(index, 1);
            if (requiredSkills.includes(index)) {
                requiredSkills = requiredSkills.filter(i => i !== index);
            }
            renderSkills();
        }

        function toggleRequired(index) {
            if (requiredSkills.includes(index)) {
                requiredSkills = requiredSkills.filter(i => i !== index);
            } else {
                requiredSkills.push(index);
            }
            renderSkills();
        }

        function handleSkillInputKeyPress(event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                addSkill();
            }
        }

        function renderSkills() {
            const container = document.getElementById('skills-container');
            container.innerHTML = '';
            
            if (skills.length === 0) {
                container.innerHTML = '<p class="text-gray-500 text-sm w-full text-center py-4">No skills added yet. Add skills above.</p>';
                return;
            }
            
            skills.forEach((skill, index) => {
                const isRequired = requiredSkills.includes(index);
                
                const tag = document.createElement('div');
                tag.className = `skill-tag ${isRequired ? 'bg-yellow-100' : 'bg-gray-100'}`;
                tag.innerHTML = `
                    <i class="fas fa-star ${isRequired ? 'text-yellow-500' : 'text-gray-400'} mr-1 cursor-pointer" 
                       onclick="toggleRequired(${index})"
                       title="${isRequired ? 'Required' : 'Click to mark as required'}"></i>
                    <span>${skill}</span>
                    <button type="button" onclick="removeSkill(${index})" class="ml-2 text-gray-500 hover:text-red-600">
                        <i class="fas fa-times"></i>
                    </button>
                `;
                container.appendChild(tag);
            });
        }

        // Add event listener for Enter key
        document.getElementById('skill-input').addEventListener('keypress', handleSkillInputKeyPress);

        // Toggle location fields based on location type
        document.getElementById('location_type').addEventListener('change', function() {
            const locationFields = document.getElementById('location-fields');
            if (this.value === 'remote') {
                locationFields.style.opacity = '0.5';
                locationFields.querySelectorAll('input').forEach(input => {
                    input.disabled = true;
                });
            } else {
                locationFields.style.opacity = '1';
                locationFields.querySelectorAll('input').forEach(input => {
                    input.disabled = false;
                });
            }
        });

        // Trigger on page load
        document.getElementById('location_type').dispatchEvent(new Event('change'));

        // Salary validation
        document.getElementById('salary_min').addEventListener('change', validateSalary);
        document.getElementById('salary_max').addEventListener('change', validateSalary);

        function validateSalary() {
            const min = parseFloat(document.getElementById('salary_min').value) || 0;
            const max = parseFloat(document.getElementById('salary_max').value) || 0;
            
            if (min > 0 && max > 0 && min > max) {
                alert('Minimum salary cannot be greater than maximum salary');
                document.getElementById('salary_max').value = min;
            }
        }
    </script>
</body>
</html>