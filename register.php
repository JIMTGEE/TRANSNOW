<?php
// register.php - TransNow Premium Job Portal
// Version: 8.1 - Instant Login, No Verification

// Error reporting for development (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start session with proper settings
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    $redirect = isset($_SESSION['user_type']) ? 
        ($_SESSION['user_type'] == 'seeker' ? 'seekers/dashboard.php' : 
         ($_SESSION['user_type'] == 'employer' ? 'employer/dashboard.php' : 'admin/dashboard.php')) : 
        'index.php';
    header("Location: " . $redirect);
    exit();
}

// Include database and functions
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/functions.php';

// Get database connection
try {
    $pdo = getDB();
    if (!$pdo) {
        throw new Exception("Database connection failed");
    }
} catch (Exception $e) {
    error_log("Database connection error in register.php: " . $e->getMessage());
    $pdo = null;
}

// Fetch job categories for preferences
$jobCategories = [];
$jobTypes = [];

if ($pdo) {
    try {
        $stmt = $pdo->prepare("SELECT id, name, slug FROM job_categories WHERE is_active = 1 ORDER BY name");
        $stmt->execute();
        $jobCategories = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $stmt2 = $pdo->prepare("SELECT id, name FROM job_types WHERE is_active = 1 ORDER BY name");
        $stmt2->execute();
        $jobTypes = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching categories/types: " . $e->getMessage());
    }
}

// Initialize session storage for multi-step form if not exists
if (!isset($_SESSION['registration'])) {
    $_SESSION['registration'] = [
        'step' => 1,
        'data' => []
    ];
}

// Handle AJAX requests for real-time validation
if (isset($_POST['ajax']) && $_POST['ajax'] === 'validate') {
    header('Content-Type: application/json');
    $errors = [];
    $field = $_POST['field'] ?? '';
    $value = trim($_POST['value'] ?? '');
    
    switch ($field) {
        case 'email':
            if (empty($value)) {
                $errors[$field] = 'Email is required';
            } elseif (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $errors[$field] = 'Please enter a valid email address';
            } else {
                // Check if email exists
                if ($pdo) {
                    try {
                        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                        $stmt->execute([$value]);
                        if ($stmt->fetch()) {
                            $errors[$field] = 'This email is already registered';
                        }
                    } catch (PDOException $e) {
                        error_log("Email check error: " . $e->getMessage());
                    }
                }
            }
            break;
            
        case 'password':
            if (empty($value)) {
                $errors[$field] = 'Password is required';
            } elseif (strlen($value) < 8) {
                $errors[$field] = 'Password must be at least 8 characters';
            } elseif (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)/', $value)) {
                $errors[$field] = 'Password must contain uppercase, lowercase, and number';
            }
            break;
            
        case 'company_name':
            if (empty($value)) {
                $errors[$field] = 'Company name is required';
            }
            break;
            
        case 'first_name':
            if (empty($value)) {
                $errors[$field] = 'First name is required';
            }
            break;
            
        case 'last_name':
            if (empty($value)) {
                $errors[$field] = 'Last name is required';
            }
            break;
    }
    
    echo json_encode(['errors' => $errors]);
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['ajax'])) {
    $currentStep = $_SESSION['registration']['step'];
    $errors = [];
    
    // Step 1: Account Type & Basic Info
    if ($currentStep === 1) {
        $userType = $_POST['user_type'] ?? 'seeker';
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        
        // Validation
        if (empty($email)) {
            $errors['email'] = 'Email is required';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Please enter a valid email address';
        } else {
            // Check if email exists
            if ($pdo) {
                try {
                    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                    $stmt->execute([$email]);
                    if ($stmt->fetch()) {
                        $errors['email'] = 'This email is already registered. Please login instead.';
                    }
                } catch (PDOException $e) {
                    error_log("Email check error: " . $e->getMessage());
                }
            }
        }
        
        if (empty($password)) {
            $errors['password'] = 'Password is required';
        } elseif (strlen($password) < 8) {
            $errors['password'] = 'Password must be at least 8 characters';
        } elseif (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)/', $password)) {
            $errors['password'] = 'Password must contain at least one uppercase letter, one lowercase letter, and one number';
        }
        
        if ($password !== $confirmPassword) {
            $errors['confirm_password'] = 'Passwords do not match';
        }
        
        // User type specific validation for step 1
        if ($userType === 'seeker') {
            $firstName = trim($_POST['first_name'] ?? '');
            $lastName = trim($_POST['last_name'] ?? '');
            
            if (empty($firstName)) {
                $errors['first_name'] = 'First name is required';
            }
            if (empty($lastName)) {
                $errors['last_name'] = 'Last name is required';
            }
            
            // Store in session
            $_SESSION['registration']['data']['first_name'] = $firstName;
            $_SESSION['registration']['data']['last_name'] = $lastName;
        } else {
            $companyName = trim($_POST['company_name'] ?? '');
            $industry = trim($_POST['industry'] ?? '');
            
            if (empty($companyName)) {
                $errors['company_name'] = 'Company name is required';
            }
            if (empty($industry)) {
                $errors['industry'] = 'Industry is required';
            }
            
            // Store in session
            $_SESSION['registration']['data']['company_name'] = $companyName;
            $_SESSION['registration']['data']['industry'] = $industry;
            $_SESSION['registration']['data']['company_size'] = $_POST['company_size'] ?? '';
        }
        
        if (empty($errors)) {
            // Store step 1 data in session
            $_SESSION['registration']['data']['user_type'] = $userType;
            $_SESSION['registration']['data']['email'] = $email;
            $_SESSION['registration']['data']['password'] = password_hash($password, PASSWORD_DEFAULT);
            
            // Move to step 2
            $_SESSION['registration']['step'] = 2;
            
            // Redirect to avoid form resubmission
            header("Location: register.php");
            exit();
        } else {
            $_SESSION['registration_errors'] = $errors;
            header("Location: register.php");
            exit();
        }
    }
    
    // Step 2: Profile Details (Seeker) or Company Details (Employer)
    elseif ($currentStep === 2) {
        $userType = $_SESSION['registration']['data']['user_type'];
        
        if ($userType === 'seeker') {
            // Seeker profile details
            $_SESSION['registration']['data']['headline'] = trim($_POST['headline'] ?? '');
            $_SESSION['registration']['data']['phone'] = trim($_POST['phone'] ?? '');
            $_SESSION['registration']['data']['whatsapp_number'] = trim($_POST['whatsapp_number'] ?? '');
            $_SESSION['registration']['data']['location'] = trim($_POST['location'] ?? '');
            $_SESSION['registration']['data']['current_job_title'] = trim($_POST['current_job_title'] ?? '');
            $_SESSION['registration']['data']['current_company'] = trim($_POST['current_company'] ?? '');
            $_SESSION['registration']['data']['years_experience'] = $_POST['years_experience'] ?? 0;
            $_SESSION['registration']['data']['highest_education'] = $_POST['highest_education'] ?? '';
            
            // Convert skills string to array for storage
            $skills = $_POST['skills'] ?? '';
            $skills_array = array_map('trim', explode(',', $skills));
            $skills_array = array_filter($skills_array);
            $_SESSION['registration']['data']['skills'] = $skills_array;
            
        } else {
            // Employer company details
            $_SESSION['registration']['data']['company_email'] = trim($_POST['company_email'] ?? '');
            $_SESSION['registration']['data']['company_phone'] = trim($_POST['company_phone'] ?? '');
            $_SESSION['registration']['data']['company_website'] = trim($_POST['company_website'] ?? '');
            $_SESSION['registration']['data']['company_description'] = trim($_POST['company_description'] ?? '');
            $_SESSION['registration']['data']['address'] = trim($_POST['address'] ?? '');
            $_SESSION['registration']['data']['city'] = trim($_POST['city'] ?? '');
            $_SESSION['registration']['data']['country'] = trim($_POST['country'] ?? '');
            $_SESSION['registration']['data']['tax_id'] = trim($_POST['tax_id'] ?? '');
        }
        
        // Move to step 3
        $_SESSION['registration']['step'] = 3;
        
        // Redirect to avoid form resubmission
        header("Location: register.php");
        exit();
    }
    
    // Step 3: Preferences & Final Submit
    elseif ($currentStep === 3) {
        $userType = $_SESSION['registration']['data']['user_type'];
        
        if (!$pdo) {
            // Database connection error, redirect to login with error
            $_SESSION['registration_error'] = "Database connection failed. Please try again later.";
            header("Location: login.php?error=registration_failed");
            exit();
        }
        
        try {
            // Start transaction
            $pdo->beginTransaction();
            
            // Insert into users table - set is_verified = 1 (no admin verification needed)
            $userStmt = $pdo->prepare("
                INSERT INTO users (email, password_hash, user_type, is_verified, created_at) 
                VALUES (?, ?, ?, 1, NOW())
            ");
            $userStmt->execute([
                $_SESSION['registration']['data']['email'],
                $_SESSION['registration']['data']['password'],
                $userType
            ]);
            $userId = $pdo->lastInsertId();
            
            if (!$userId) {
                throw new Exception("Failed to create user account");
            }
            
            if ($userType === 'seeker') {
                // Insert into seekers table
                $skills_json = json_encode($_SESSION['registration']['data']['skills'] ?? []);
                
                $seekerStmt = $pdo->prepare("
                    INSERT INTO seekers (
                        user_id, first_name, last_name, phone, whatsapp_number,
                        headline, location, current_job_title, current_company,
                        years_experience, highest_education, skills, is_active,
                        profile_completion_percent, created_at, updated_at
                    ) VALUES (
                        ?, ?, ?, ?, ?,
                        ?, ?, ?, ?,
                        ?, ?, ?, 1,
                        70, NOW(), NOW()
                    )
                ");
                
                $seekerStmt->execute([
                    $userId,
                    $_SESSION['registration']['data']['first_name'],
                    $_SESSION['registration']['data']['last_name'],
                    $_SESSION['registration']['data']['phone'] ?? null,
                    $_SESSION['registration']['data']['whatsapp_number'] ?? null,
                    $_SESSION['registration']['data']['headline'] ?? null,
                    $_SESSION['registration']['data']['location'] ?? null,
                    $_SESSION['registration']['data']['current_job_title'] ?? null,
                    $_SESSION['registration']['data']['current_company'] ?? null,
                    $_SESSION['registration']['data']['years_experience'] ?? 0,
                    $_SESSION['registration']['data']['highest_education'] ?? null,
                    $skills_json
                ]);
                $seekerId = $pdo->lastInsertId();
                
                // Insert seeker preferences
                $preferred_categories = isset($_POST['preferred_categories']) && is_array($_POST['preferred_categories']) ? 
                    json_encode(array_map('intval', $_POST['preferred_categories'])) : '[]';
                $preferred_job_types = isset($_POST['preferred_job_types']) && is_array($_POST['preferred_job_types']) ? 
                    json_encode(array_map('intval', $_POST['preferred_job_types'])) : '[]';
                $preferred_location_types = json_encode(['onsite', 'remote', 'hybrid']);
                $preferred_locations = !empty($_POST['preferred_locations']) ? 
                    json_encode(array_map('trim', explode(',', $_POST['preferred_locations']))) : '[]';
                $min_salary = !empty($_POST['min_salary']) ? floatval($_POST['min_salary']) : null;
                $max_salary = !empty($_POST['max_salary']) ? floatval($_POST['max_salary']) : null;
                $experience_level = $_POST['experience_level_preference'] ?? 'any';
                
                $prefStmt = $pdo->prepare("
                    INSERT INTO seeker_preferences (
                        seeker_id,
                        preferred_categories,
                        preferred_job_types,
                        preferred_location_types,
                        preferred_locations,
                        min_salary,
                        max_salary,
                        experience_level_preference,
                        notification_frequency,
                        is_active,
                        created_at,
                        updated_at
                    ) VALUES (
                        ?, ?, ?, ?, ?,
                        ?, ?, ?, 'daily', 1, NOW(), NOW()
                    )
                ");
                
                $prefStmt->execute([
                    $seekerId,
                    $preferred_categories,
                    $preferred_job_types,
                    $preferred_location_types,
                    $preferred_locations,
                    $min_salary,
                    $max_salary,
                    $experience_level
                ]);
                
            } else {
                // Insert into employers table - set is_verified = 1
                $employerStmt = $pdo->prepare("
                    INSERT INTO employers (
                        user_id, company_name, industry, company_size,
                        company_email, company_phone, company_website,
                        company_description, address, city, country, tax_id,
                        is_verified, created_at, updated_at
                    ) VALUES (
                        ?, ?, ?, ?,
                        ?, ?, ?,
                        ?, ?, ?, ?, ?,
                        1, NOW(), NOW()
                    )
                ");
                
                $employerStmt->execute([
                    $userId,
                    $_SESSION['registration']['data']['company_name'],
                    $_SESSION['registration']['data']['industry'],
                    $_SESSION['registration']['data']['company_size'] ?? null,
                    $_SESSION['registration']['data']['company_email'] ?? null,
                    $_SESSION['registration']['data']['company_phone'] ?? null,
                    $_SESSION['registration']['data']['company_website'] ?? null,
                    $_SESSION['registration']['data']['company_description'] ?? null,
                    $_SESSION['registration']['data']['address'] ?? null,
                    $_SESSION['registration']['data']['city'] ?? null,
                    $_SESSION['registration']['data']['country'] ?? null,
                    $_SESSION['registration']['data']['tax_id'] ?? null
                ]);
                $employerId = $pdo->lastInsertId();
                
                // Insert employer preferences
                $required_categories = isset($_POST['required_categories']) && is_array($_POST['required_categories']) ? 
                    json_encode(array_map('intval', $_POST['required_categories'])) : '[]';
                $required_skills = !empty($_POST['required_skills']) ? 
                    json_encode(array_map('trim', explode(',', $_POST['required_skills']))) : '[]';
                $exp_min = intval($_POST['experience_range_min'] ?? 0);
                $exp_max = intval($_POST['experience_range_max'] ?? 30);
                $education_level = $_POST['education_level'] ?? null;
                $location_preference = !empty($_POST['location_preference']) ? 
                    json_encode(array_map('trim', explode(',', $_POST['location_preference']))) : '[]';
                $salary_budget = !empty($_POST['salary_budget_max']) ? floatval($_POST['salary_budget_max']) : null;
                
                $empPrefStmt = $pdo->prepare("
                    INSERT INTO employer_preferences (
                        employer_id,
                        required_categories,
                        required_skills,
                        experience_range_min,
                        experience_range_max,
                        education_level,
                        location_preference,
                        salary_budget_max,
                        is_active,
                        created_at,
                        updated_at
                    ) VALUES (
                        ?, ?, ?, ?,
                        ?, ?, ?, ?,
                        1, NOW(), NOW()
                    )
                ");
                
                $empPrefStmt->execute([
                    $employerId,
                    $required_categories,
                    $required_skills,
                    $exp_min,
                    $exp_max,
                    $education_level,
                    $location_preference,
                    $salary_budget
                ]);
            }
            
            // Commit transaction
            $pdo->commit();
            
            // Clear registration session
            unset($_SESSION['registration']);
            
            // Set success message and redirect to login
            $_SESSION['registration_success'] = true;
            $_SESSION['registration_email'] = $_SESSION['registration']['data']['email'];
            
            // Redirect to login page with success flag
            header("Location: login.php?registered=1");
            exit();
            
        } catch (Exception $e) {
            // Rollback transaction
            if ($pdo && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            
            // Log the error for debugging
            error_log("Registration error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
            
            // Set user-friendly error message and redirect to login
            $_SESSION['registration_error'] = "Registration failed. Please try again or contact support.";
            header("Location: login.php?error=registration_failed");
            exit();
        }
    }
}

// Get statistics for display
$stats = [
    'verified_users' => 0,
    'active_jobs' => 0,
    'verified_companies' => 0,
    'pending_verifications' => 0
];

if ($pdo) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                (SELECT COUNT(*) FROM users WHERE is_verified = 1) as verified_users,
                (SELECT COUNT(*) FROM jobs WHERE status = 'active') as active_jobs,
                (SELECT COUNT(*) FROM employers WHERE is_verified = 1) as verified_companies,
                (SELECT COUNT(*) FROM users WHERE is_verified = 0) as pending_verifications
        ");
        $stmt->execute();
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$stats) {
            $stats = [
                'verified_users' => 0,
                'active_jobs' => 0,
                'verified_companies' => 0,
                'pending_verifications' => 0
            ];
        }
    } catch (PDOException $e) {
        error_log("Error fetching stats: " . $e->getMessage());
    }
}

$currentStep = $_SESSION['registration']['step'] ?? 1;
$registrationErrors = $_SESSION['registration_errors'] ?? [];
unset($_SESSION['registration_errors']);
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Join TransNow - Complete Your Profile | Global Career Platform</title>
    <meta name="description" content="Complete your profile and join TransNow to access global job opportunities. No verification required – log in immediately after registration.">
    
    <!-- Favicon -->
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Ccircle cx='50' cy='50' r='45' fill='%234f46e5'/%3E%3Cpath d='M30 40 L70 40 L65 70 L35 70 Z' fill='white'/%3E%3Ccircle cx='50' cy='30' r='8' fill='white'/%3E%3C/svg%3E">
    
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Noto+Sans+SC:wght@300;400;500;700&display=swap" rel="stylesheet">
    
    <!-- AOS Animation -->
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    
    <style>
        /* (All styles remain unchanged) */
        * {
            font-family: 'Inter', 'Noto Sans SC', sans-serif;
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0) rotate(0deg); }
            25% { transform: translateY(-20px) rotate(2deg); }
            75% { transform: translateY(20px) rotate(-2deg); }
        }
        
        @keyframes gradient {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }
        
        @keyframes slideIn {
            from { transform: translateY(30px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            10%, 30%, 50%, 70%, 90% { transform: translateX(-5px); }
            20%, 40%, 60%, 80% { transform: translateX(5px); }
        }
        
        @keyframes shimmer {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }
        
        @keyframes scaleIn {
            from { transform: scale(0); }
            to { transform: scale(1); }
        }
        
        .animate-float {
            animation: float 8s ease-in-out infinite;
        }
        
        .gradient-bg {
            background: linear-gradient(-45deg, #4f46e5, #7c3aed, #db2777, #4f46e5);
            background-size: 400% 400%;
            animation: gradient 15s ease infinite;
        }
        
        .slide-in {
            animation: slideIn 0.6s ease-out forwards;
        }
        
        .shake {
            animation: shake 0.5s ease-in-out;
        }
        
        .glass {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .glass-dark {
            background: rgba(17, 24, 39, 0.7);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .hover-card {
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .hover-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 30px 60px -15px rgba(79, 70, 229, 0.4);
        }
        
        .gradient-text {
            background: linear-gradient(135deg, #4f46e5, #7c3aed, #db2777);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        ::-webkit-scrollbar {
            width: 12px;
        }
        
        ::-webkit-scrollbar-track {
            background: #f1f1f1;
        }
        
        ::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, #4f46e5, #7c3aed);
            border-radius: 6px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(135deg, #7c3aed, #db2777);
        }
        
        .dark ::-webkit-scrollbar-track {
            background: #1f2937;
        }
        
        .form-input {
            transition: all 0.3s ease;
        }
        
        .form-input:focus {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px rgba(79, 70, 229, 0.3);
        }
        
        .input-error {
            border-color: #ef4444;
        }
        
        .strength-meter {
            transition: all 0.3s ease;
        }
        
        .strength-weak { background: #ef4444; width: 33.33%; }
        .strength-medium { background: #f59e0b; width: 66.66%; }
        .strength-strong { background: #10b981; width: 100%; }
        
        .type-toggle-btn {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .type-toggle-btn.active {
            transform: scale(1.02);
            box-shadow: 0 20px 40px -15px rgba(79, 70, 229, 0.5);
        }
        
        .particle {
            position: absolute;
            border-radius: 50%;
            background: rgba(79, 70, 229, 0.1);
            pointer-events: none;
        }
        
        .dark .particle {
            background: rgba(79, 70, 229, 0.2);
        }
        
        .spinner {
            width: 20px;
            height: 20px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 0.8s linear infinite;
            display: inline-block;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        .checkmark {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #10b981, #059669);
            margin: 0 auto 24px;
            animation: scaleIn 0.5s ease-out;
        }
        
        .step-indicator {
            position: relative;
            z-index: 1;
        }
        
        .step-indicator::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 0;
            right: 0;
            height: 2px;
            background: #e5e7eb;
            z-index: -1;
        }
        
        .dark .step-indicator::before {
            background: #4b5563;
        }
        
        .step-indicator .step {
            background: white;
            border: 2px solid #e5e7eb;
            transition: all 0.3s ease;
        }
        
        .dark .step-indicator .step {
            background: #1f2937;
            border-color: #4b5563;
        }
        
        .step-indicator .step.active {
            border-color: #4f46e5;
            background: #4f46e5;
            color: white;
        }
        
        .step-indicator .step.completed {
            border-color: #10b981;
            background: #10b981;
            color: white;
        }
        
        .floating-label-group {
            position: relative;
            margin-bottom: 1rem;
        }
        
        .floating-input {
            width: 100%;
            padding: 1rem 1rem 0.5rem;
            border: 1px solid #e5e7eb;
            border-radius: 0.75rem;
            background: transparent;
            transition: all 0.3s ease;
        }
        
        .dark .floating-input {
            border-color: #4b5563;
            color: white;
            background: #1f2937;
        }
        
        .floating-label {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            transition: all 0.2s ease;
            pointer-events: none;
            color: #9ca3af;
            background: transparent;
            padding: 0 4px;
        }
        
        .dark .floating-label {
            color: #6b7280;
        }
        
        .floating-input:focus ~ .floating-label,
        .floating-input:not(:placeholder-shown) ~ .floating-label {
            top: 0;
            transform: translateY(-50%) scale(0.85);
            background: white;
            color: #4f46e5;
        }
        
        .dark .floating-input:focus ~ .floating-label,
        .dark .floating-input:not(:placeholder-shown) ~ .floating-label {
            background: #1f2937;
            color: #818cf8;
        }
        
        .floating-input:focus {
            outline: none;
            border-color: #4f46e5;
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }
        
        .dark .floating-input:focus {
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.2);
        }
        
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
        
        .skills-container {
            border: 1px solid #e5e7eb;
            border-radius: 0.75rem;
            padding: 0.5rem;
            min-height: 100px;
            cursor: text;
        }
        
        .dark .skills-container {
            border-color: #4b5563;
            background: #1f2937;
        }
        
        .skill-tag {
            display: inline-flex;
            align-items: center;
            background: #4f46e5;
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            margin: 0.25rem;
            font-size: 0.875rem;
        }
        
        .skill-tag i {
            margin-left: 0.5rem;
            cursor: pointer;
            font-size: 0.75rem;
        }
        
        .countdown-buttons {
            display: flex;
            gap: 1rem;
            justify-content: center;
            margin-top: 2rem;
        }
        
        .redirect-btn {
            transition: all 0.3s ease;
        }
        
        .redirect-btn:hover {
            transform: translateY(-2px);
        }
        
        .dark select,
        .dark input,
        .dark textarea {
            background-color: #1f2937;
            border-color: #4b5563;
            color: #f3f4f6;
        }
        
        .dark select option {
            background-color: #1f2937;
            color: #f3f4f6;
        }
        
        .dark .bg-white {
            background-color: #1f2937;
        }
        
        .dark .text-gray-600 {
            color: #9ca3af;
        }
        
        .dark .text-gray-700 {
            color: #d1d5db;
        }
        
        .dark .border-gray-200 {
            border-color: #4b5563;
        }
        
        .dark .bg-gray-50 {
            background-color: #111827;
        }
        
        .dark .bg-indigo-50 {
            background-color: rgba(79, 70, 229, 0.1);
        }
        
        .dark .hover\:bg-gray-100:hover {
            background-color: #374151;
        }
        
        .dark .hover\:bg-indigo-100:hover {
            background-color: rgba(79, 70, 229, 0.2);
        }
        
        @media (max-width: 768px) {
            .register-container {
                padding: 1rem;
            }
        }
        
        .error-text {
            color: #ef4444;
            font-size: 0.75rem;
            margin-top: 0.25rem;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }
        
        .border-error {
            border-color: #ef4444 !important;
        }
        
        .contact-card {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border-radius: 1rem;
            padding: 1rem;
        }
        
        .dark .contact-card {
            background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
        }
        
        .contact-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.5rem;
            border-radius: 0.75rem;
            transition: all 0.3s ease;
        }
        
        .contact-item:hover {
            background: rgba(79, 70, 229, 0.1);
            transform: translateX(5px);
        }
        
        .contact-icon {
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background: #4f46e5;
            color: white;
        }
        
        .dark .contact-icon {
            background: #6366f1;
        }
    </style>
</head>
<body class="bg-gray-50 dark:bg-gray-900 text-gray-900 dark:text-white transition-colors duration-300 overflow-x-hidden min-h-screen flex flex-col">

<!-- Particle Background -->
<div class="fixed inset-0 pointer-events-none overflow-hidden">
    <?php for ($i = 0; $i < 30; $i++): ?>
    <div class="particle" style="
        left: <?php echo rand(0, 100); ?>%;
        top: <?php echo rand(0, 100); ?>%;
        width: <?php echo rand(2, 8); ?>px;
        height: <?php echo rand(2, 8); ?>px;
        animation: float <?php echo rand(8, 20); ?>s ease-in-out infinite;
        animation-delay: <?php echo rand(0, 5); ?>s;
    "></div>
    <?php endfor; ?>
</div>

<!-- Dark Mode Toggle -->
<button id="darkModeToggle" 
        class="fixed top-6 right-6 z-50 w-12 h-12 glass dark:glass-dark text-white rounded-full flex items-center justify-center hover:scale-110 transition-all duration-300 shadow-lg">
    <i class="fas fa-moon text-xl" id="darkModeIcon"></i>
</button>

<!-- Main Content -->
<main class="flex-grow flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8 relative z-10">
    <div class="max-w-6xl w-full">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 items-start">
            
            <!-- Left Column - Hero Content -->
            <div class="hidden lg:block" data-aos="fade-right" data-aos-duration="1000">
                <div class="sticky top-24">
                    <!-- Logo -->
                    <a href="index.php" class="inline-flex items-center space-x-3 group mb-12">
                        <div class="w-16 h-16 bg-gradient-to-r from-indigo-600 to-purple-600 rounded-2xl flex items-center justify-center transform group-hover:rotate-12 transition-all duration-300 shadow-xl">
                            <i class="fas fa-globe-asia text-white text-3xl"></i>
                        </div>
                        <span class="text-4xl font-bold">
                            <span class="gradient-text">TransNow</span>
                        </span>
                    </a>
                    
                    <h1 class="text-5xl font-bold mb-6 leading-tight">
                        Join <span class="gradient-text">TransNow</span><br>
                        Start Your Journey<br>
                        Today
                    </h1>
                    
                    <p class="text-xl text-gray-600 dark:text-gray-400 mb-8">
                        Fill out your profile and get immediate access to thousands of jobs or candidates. No verification wait time – log in right away!
                    </p>
                    
                    <!-- Stats -->
                    <div class="grid grid-cols-2 gap-6 mb-12">
                        <div class="bg-white dark:bg-gray-800 p-6 rounded-2xl shadow-xl hover-card transition-all duration-300">
                            <div class="text-3xl font-bold text-indigo-600 dark:text-indigo-400 mb-2 counter" data-target="<?php echo $stats['active_jobs'] ?? 0; ?>">
                                0
                            </div>
                            <div class="text-sm text-gray-600 dark:text-gray-400">Active Jobs</div>
                        </div>
                        <div class="bg-white dark:bg-gray-800 p-6 rounded-2xl shadow-xl hover-card transition-all duration-300">
                            <div class="text-3xl font-bold text-indigo-600 dark:text-indigo-400 mb-2 counter" data-target="<?php echo $stats['verified_companies'] ?? 0; ?>">
                                0
                            </div>
                            <div class="text-sm text-gray-600 dark:text-gray-400">Companies</div>
                        </div>
                        <div class="bg-white dark:bg-gray-800 p-6 rounded-2xl shadow-xl hover-card transition-all duration-300">
                            <div class="text-3xl font-bold text-indigo-600 dark:text-indigo-400 mb-2 counter" data-target="<?php echo $stats['verified_users'] ?? 0; ?>">
                                0
                            </div>
                            <div class="text-sm text-gray-600 dark:text-gray-400">Users</div>
                        </div>
                        <div class="bg-white dark:bg-gray-800 p-6 rounded-2xl shadow-xl hover-card transition-all duration-300">
                            <div class="text-3xl font-bold text-indigo-600 dark:text-indigo-400 mb-2 counter" data-target="0">
                                0
                            </div>
                            <div class="text-sm text-gray-600 dark:text-gray-400">Pending (none)</div>
                        </div>
                    </div>
                    
                    <!-- Contact Admin Section -->
                    <div class="contact-card mb-8">
                        <h3 class="font-semibold text-lg mb-4 flex items-center text-gray-900 dark:text-white">
                            <i class="fas fa-headset text-indigo-600 dark:text-indigo-400 mr-2"></i>
                            Need Help? Contact Admin
                        </h3>
                        <div class="space-y-3">
                            <div class="contact-item">
                                <div class="contact-icon">
                                    <i class="fas fa-phone-alt text-sm"></i>
                                </div>
                                <div>
                                    <p class="text-sm text-gray-500 dark:text-gray-400">Phone Number</p>
                                    <a href="tel:+86593121936" class="text-gray-900 dark:text-white font-medium hover:text-indigo-600 dark:hover:text-indigo-400 transition">
                                        +86 593 121936
                                    </a>
                                </div>
                            </div>
                            <div class="contact-item">
                                <div class="contact-icon">
                                    <i class="fab fa-whatsapp text-sm"></i>
                                </div>
                                <div>
                                    <p class="text-sm text-gray-500 dark:text-gray-400">WhatsApp</p>
                                    <a href="https://wa.me/8619157688742" target="_blank" class="text-gray-900 dark:text-white font-medium hover:text-green-500 transition">
                                        +86 191 5768 8742
                                    </a>
                                </div>
                            </div>
                            <div class="contact-item">
                                <div class="contact-icon">
                                    <i class="fas fa-envelope text-sm"></i>
                                </div>
                                <div>
                                    <p class="text-sm text-gray-500 dark:text-gray-400">Email</p>
                                    <a href="mailto:1259269019@qq.com" class="text-gray-900 dark:text-white font-medium hover:text-indigo-600 dark:hover:text-indigo-400 transition">
                                        1259269019@qq.com
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Quick Info -->
                    <div class="bg-indigo-50 dark:bg-indigo-900/20 p-6 rounded-2xl mb-8 border border-indigo-100 dark:border-indigo-800">
                        <h3 class="font-semibold text-lg mb-3 flex items-center text-gray-900 dark:text-white">
                            <i class="fas fa-rocket text-indigo-600 dark:text-indigo-400 mr-2"></i>
                            Why Join?
                        </h3>
                        <ul class="space-y-2 text-sm text-gray-600 dark:text-gray-400">
                            <li class="flex items-start">
                                <i class="fas fa-check-circle text-green-500 mr-2 mt-1"></i>
                                <span>Instant account activation – log in immediately</span>
                            </li>
                            <li class="flex items-start">
                                <i class="fas fa-chart-line text-indigo-500 mr-2 mt-1"></i>
                                <span>Access thousands of jobs and candidates</span>
                            </li>
                            <li class="flex items-start">
                                <i class="fas fa-bell text-indigo-500 mr-2 mt-1"></i>
                                <span>Receive personalized job alerts</span>
                            </li>
                        </ul>
                    </div>
                    
                    <!-- Testimonials -->
                    <div class="relative">
                        <div class="absolute -left-4 top-0 text-6xl text-indigo-200 dark:text-indigo-800">"</div>
                        <blockquote class="relative pl-8">
                            <p class="text-lg italic text-gray-700 dark:text-gray-300 mb-4">
                                I signed up and could start applying immediately – no waiting. The process was smooth and fast!
                            </p>
                            <footer class="flex items-center">
                                <img src="https://ui-avatars.com/api/?name=John+Lee&background=4f46e5&color=fff" 
                                     alt="John Lee" 
                                     class="w-12 h-12 rounded-full mr-4">
                                <div>
                                    <cite class="font-semibold not-italic text-gray-900 dark:text-white">John Lee</cite>
                                    <p class="text-sm text-gray-500 dark:text-gray-400">Software Engineer</p>
                                </div>
                            </footer>
                        </blockquote>
                    </div>
                </div>
            </div>
            
            <!-- Right Column - Registration Form -->
            <div data-aos="fade-left" data-aos-duration="1000">
                <div class="bg-white dark:bg-gray-800 rounded-3xl shadow-2xl overflow-hidden">
                    
                    <?php if (isset($_SESSION['registration_error'])): ?>
                    <!-- Error Message (should not happen normally, but just in case) -->
                    <div class="p-8 text-center">
                        <div class="w-20 h-20 bg-red-100 dark:bg-red-900/30 rounded-full flex items-center justify-center mx-auto mb-6">
                            <i class="fas fa-exclamation-triangle text-red-500 text-3xl"></i>
                        </div>
                        <h2 class="text-2xl font-bold text-red-600 dark:text-red-400 mb-3">
                            Registration Error
                        </h2>
                        <p class="text-gray-600 dark:text-gray-400 mb-6">
                            <?php 
                            echo htmlspecialchars($_SESSION['registration_error']);
                            unset($_SESSION['registration_error']);
                            ?>
                        </p>
                        <a href="register.php" class="inline-block px-6 py-3 bg-gradient-to-r from-indigo-600 to-purple-600 text-white rounded-xl font-semibold hover:shadow-xl transition">
                            <i class="fas fa-redo-alt mr-2"></i> Try Again
                        </a>
                    </div>
                    
                    <?php else: ?>
                    <!-- Form Header -->
                    <div class="bg-gradient-to-r from-indigo-600 to-purple-600 px-8 py-6 text-white">
                        <h2 class="text-3xl font-bold mb-2">
                            <?php 
                            if ($currentStep === 1) echo "Step 1: Basic Info";
                            elseif ($currentStep === 2) echo "Step 2: Profile Details";
                            else echo "Step 3: Preferences & Submit";
                            ?>
                        </h2>
                        <p class="text-indigo-100">
                            <?php if ($currentStep === 1): ?>
                                Tell us who you are
                            <?php elseif ($currentStep === 2): ?>
                                Complete your profile information
                            <?php else: ?>
                                Set your preferences and finish
                            <?php endif; ?>
                        </p>
                    </div>
                    
                    <!-- Progress Steps -->
                    <div class="px-8 pt-6">
                        <div class="step-indicator flex items-center justify-between relative">
                            <div class="step w-10 h-10 rounded-full flex items-center justify-center font-semibold z-10 
                                <?php echo $currentStep >= 1 ? 'active' : ''; ?> 
                                <?php echo $currentStep > 1 ? 'completed' : ''; ?>">
                                1
                            </div>
                            <div class="step w-10 h-10 rounded-full flex items-center justify-center font-semibold z-10 
                                <?php echo $currentStep >= 2 ? 'active' : ''; ?> 
                                <?php echo $currentStep > 2 ? 'completed' : ''; ?>">
                                2
                            </div>
                            <div class="step w-10 h-10 rounded-full flex items-center justify-center font-semibold z-10 
                                <?php echo $currentStep >= 3 ? 'active' : ''; ?>">
                                3
                            </div>
                        </div>
                        <div class="flex justify-between mt-2 text-xs text-gray-600 dark:text-gray-400 px-2">
                            <span>Account</span>
                            <span>Profile</span>
                            <span>Preferences</span>
                        </div>
                    </div>
                    
                    <!-- Display Step 1 Errors -->
                    <?php if (!empty($registrationErrors) && $currentStep === 1): ?>
                    <div class="px-8 pt-4">
                        <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-4">
                            <p class="text-red-600 dark:text-red-400 font-semibold mb-2">Please fix the following errors:</p>
                            <ul class="list-disc list-inside text-sm text-red-600 dark:text-red-400">
                                <?php foreach ($registrationErrors as $error): ?>
                                <li><?php echo htmlspecialchars($error); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Form -->
                    <form id="registerForm" method="POST" action="" class="p-8 space-y-6">
                        <!-- Step 1: Account Type & Basic Info -->
                        <div id="step1" style="<?php echo $currentStep !== 1 ? 'display: none;' : ''; ?>">
                            <!-- Account Type Selection -->
                            <div class="mb-6">
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                                    I want to join as
                                </label>
                                <div class="grid grid-cols-2 gap-4">
                                    <button type="button" 
                                            id="seekerTypeBtn"
                                            onclick="setUserType('seeker')"
                                            class="type-toggle-btn relative p-4 border-2 rounded-xl transition-all duration-300 <?php echo (!isset($_SESSION['registration']['data']['user_type']) || $_SESSION['registration']['data']['user_type'] === 'seeker') ? 'border-indigo-600 bg-indigo-50 dark:bg-indigo-900/20 active' : 'border-gray-200 dark:border-gray-700 hover:border-indigo-300 dark:hover:border-indigo-600'; ?>">
                                        <div class="text-center">
                                            <i class="fas fa-user-graduate text-2xl <?php echo (!isset($_SESSION['registration']['data']['user_type']) || $_SESSION['registration']['data']['user_type'] === 'seeker') ? 'text-indigo-600' : 'text-gray-400 dark:text-gray-500'; ?> mb-2"></i>
                                            <div class="font-semibold <?php echo (!isset($_SESSION['registration']['data']['user_type']) || $_SESSION['registration']['data']['user_type'] === 'seeker') ? 'text-indigo-600' : 'text-gray-600 dark:text-gray-400'; ?>">
                                                Job Seeker
                                            </div>
                                            <p class="text-xs text-gray-500 dark:text-gray-500 mt-1">Find your dream job</p>
                                        </div>
                                        <?php if (!isset($_SESSION['registration']['data']['user_type']) || $_SESSION['registration']['data']['user_type'] === 'seeker'): ?>
                                        <div class="absolute -top-2 -right-2 w-6 h-6 bg-indigo-600 rounded-full flex items-center justify-center">
                                            <i class="fas fa-check text-white text-xs"></i>
                                        </div>
                                        <?php endif; ?>
                                    </button>
                                    
                                    <button type="button" 
                                            id="employerTypeBtn"
                                            onclick="setUserType('employer')"
                                            class="type-toggle-btn relative p-4 border-2 rounded-xl transition-all duration-300 <?php echo (isset($_SESSION['registration']['data']['user_type']) && $_SESSION['registration']['data']['user_type'] === 'employer') ? 'border-indigo-600 bg-indigo-50 dark:bg-indigo-900/20 active' : 'border-gray-200 dark:border-gray-700 hover:border-indigo-300 dark:hover:border-indigo-600'; ?>">
                                        <div class="text-center">
                                            <i class="fas fa-building text-2xl <?php echo (isset($_SESSION['registration']['data']['user_type']) && $_SESSION['registration']['data']['user_type'] === 'employer') ? 'text-indigo-600' : 'text-gray-400 dark:text-gray-500'; ?> mb-2"></i>
                                            <div class="font-semibold <?php echo (isset($_SESSION['registration']['data']['user_type']) && $_SESSION['registration']['data']['user_type'] === 'employer') ? 'text-indigo-600' : 'text-gray-600 dark:text-gray-400'; ?>">
                                                Employer
                                            </div>
                                            <p class="text-xs text-gray-500 dark:text-gray-500 mt-1">Hire top talents</p>
                                        </div>
                                        <?php if (isset($_SESSION['registration']['data']['user_type']) && $_SESSION['registration']['data']['user_type'] === 'employer'): ?>
                                        <div class="absolute -top-2 -right-2 w-6 h-6 bg-indigo-600 rounded-full flex items-center justify-center">
                                            <i class="fas fa-check text-white text-xs"></i>
                                        </div>
                                        <?php endif; ?>
                                    </button>
                                </div>
                                <input type="hidden" name="user_type" id="userType" value="<?php echo htmlspecialchars($_SESSION['registration']['data']['user_type'] ?? 'seeker'); ?>">
                            </div>
                            
                            <!-- Seeker Fields Step 1 -->
                            <div id="seekerFields1" class="space-y-6" style="<?php echo (isset($_SESSION['registration']['data']['user_type']) && $_SESSION['registration']['data']['user_type'] === 'employer') ? 'display: none;' : ''; ?>">
                                <div class="grid grid-cols-2 gap-4">
                                    <div class="floating-label-group">
                                        <input type="text" 
                                               name="first_name" 
                                               id="firstName"
                                               value="<?php echo htmlspecialchars($_SESSION['registration']['data']['first_name'] ?? ''); ?>"
                                               placeholder=" "
                                               class="floating-input w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-transparent dark:bg-gray-700 dark:text-white form-input <?php echo isset($registrationErrors['first_name']) ? 'border-red-500' : ''; ?>">
                                        <label for="firstName" class="floating-label">First Name *</label>
                                        <?php if (isset($registrationErrors['first_name'])): ?>
                                        <p class="error-text"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($registrationErrors['first_name']); ?></p>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="floating-label-group">
                                        <input type="text" 
                                               name="last_name" 
                                               id="lastName"
                                               value="<?php echo htmlspecialchars($_SESSION['registration']['data']['last_name'] ?? ''); ?>"
                                               placeholder=" "
                                               class="floating-input w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-transparent dark:bg-gray-700 dark:text-white form-input <?php echo isset($registrationErrors['last_name']) ? 'border-red-500' : ''; ?>">
                                        <label for="lastName" class="floating-label">Last Name *</label>
                                        <?php if (isset($registrationErrors['last_name'])): ?>
                                        <p class="error-text"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($registrationErrors['last_name']); ?></p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Employer Fields Step 1 -->
                            <div id="employerFields1" class="space-y-6" style="<?php echo (isset($_SESSION['registration']['data']['user_type']) && $_SESSION['registration']['data']['user_type'] === 'employer') ? '' : 'display: none;'; ?>">
                                <div class="floating-label-group">
                                    <input type="text" 
                                           name="company_name" 
                                           id="companyName"
                                           value="<?php echo htmlspecialchars($_SESSION['registration']['data']['company_name'] ?? ''); ?>"
                                           placeholder=" "
                                           class="floating-input w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-transparent dark:bg-gray-700 dark:text-white form-input <?php echo isset($registrationErrors['company_name']) ? 'border-red-500' : ''; ?>">
                                    <label for="companyName" class="floating-label">Company Name *</label>
                                    <?php if (isset($registrationErrors['company_name'])): ?>
                                    <p class="error-text"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($registrationErrors['company_name']); ?></p>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="floating-label-group">
                                    <input type="text" 
                                           name="industry" 
                                           id="industry"
                                           value="<?php echo htmlspecialchars($_SESSION['registration']['data']['industry'] ?? ''); ?>"
                                           placeholder=" "
                                           class="floating-input w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-transparent dark:bg-gray-700 dark:text-white form-input <?php echo isset($registrationErrors['industry']) ? 'border-red-500' : ''; ?>">
                                    <label for="industry" class="floating-label">Industry *</label>
                                    <?php if (isset($registrationErrors['industry'])): ?>
                                    <p class="error-text"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($registrationErrors['industry']); ?></p>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="floating-label-group">
                                    <select name="company_size" id="companySize" class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                                        <option value="">Select Company Size</option>
                                        <option value="1-10" <?php echo ($_SESSION['registration']['data']['company_size'] ?? '') == '1-10' ? 'selected' : ''; ?>>1-10 employees</option>
                                        <option value="11-50" <?php echo ($_SESSION['registration']['data']['company_size'] ?? '') == '11-50' ? 'selected' : ''; ?>>11-50 employees</option>
                                        <option value="51-200" <?php echo ($_SESSION['registration']['data']['company_size'] ?? '') == '51-200' ? 'selected' : ''; ?>>51-200 employees</option>
                                        <option value="201-500" <?php echo ($_SESSION['registration']['data']['company_size'] ?? '') == '201-500' ? 'selected' : ''; ?>>201-500 employees</option>
                                        <option value="501-1000" <?php echo ($_SESSION['registration']['data']['company_size'] ?? '') == '501-1000' ? 'selected' : ''; ?>>501-1000 employees</option>
                                        <option value="1000+" <?php echo ($_SESSION['registration']['data']['company_size'] ?? '') == '1000+' ? 'selected' : ''; ?>>1000+ employees</option>
                                    </select>
                                </div>
                            </div>
                            
                            <!-- Common Fields Step 1 -->
                            <div class="floating-label-group">
                                <input type="email" 
                                       name="email" 
                                       id="email"
                                       value="<?php echo htmlspecialchars($_SESSION['registration']['data']['email'] ?? ''); ?>"
                                       placeholder=" "
                                       class="floating-input w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-transparent dark:bg-gray-700 dark:text-white form-input <?php echo isset($registrationErrors['email']) ? 'border-red-500' : ''; ?>">
                                <label for="email" class="floating-label">Email Address *</label>
                                <?php if (isset($registrationErrors['email'])): ?>
                                <p class="error-text"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($registrationErrors['email']); ?></p>
                                <?php endif; ?>
                            </div>
                            
                            <div class="floating-label-group">
                                <input type="password" 
                                       name="password" 
                                       id="password"
                                       placeholder=" "
                                       class="floating-input w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-transparent dark:bg-gray-700 dark:text-white form-input <?php echo isset($registrationErrors['password']) ? 'border-red-500' : ''; ?>">
                                <label for="password" class="floating-label">Password *</label>
                                <?php if (isset($registrationErrors['password'])): ?>
                                <p class="error-text"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($registrationErrors['password']); ?></p>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Password Strength Meter -->
                            <div id="passwordStrength" class="hidden mb-4">
                                <div class="flex items-center justify-between mb-1">
                                    <span class="text-xs text-gray-600 dark:text-gray-400">Password strength:</span>
                                    <span id="strengthText" class="text-xs font-semibold"></span>
                                </div>
                                <div class="h-1 bg-gray-200 dark:bg-gray-700 rounded-full overflow-hidden">
                                    <div id="strengthBar" class="h-full transition-all duration-300"></div>
                                </div>
                                <ul id="passwordRequirements" class="text-xs text-gray-500 dark:text-gray-500 mt-2 space-y-1">
                                    <li id="req-length"><i class="far fa-circle mr-1"></i> At least 8 characters</li>
                                    <li id="req-uppercase"><i class="far fa-circle mr-1"></i> One uppercase letter</li>
                                    <li id="req-lowercase"><i class="far fa-circle mr-1"></i> One lowercase letter</li>
                                    <li id="req-number"><i class="far fa-circle mr-1"></i> One number</li>
                                </ul>
                            </div>
                            
                            <div class="floating-label-group">
                                <input type="password" 
                                       name="confirm_password" 
                                       id="confirmPassword"
                                       placeholder=" "
                                       class="floating-input w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-transparent dark:bg-gray-700 dark:text-white form-input <?php echo isset($registrationErrors['confirm_password']) ? 'border-red-500' : ''; ?>">
                                <label for="confirmPassword" class="floating-label">Confirm Password *</label>
                                <?php if (isset($registrationErrors['confirm_password'])): ?>
                                <p class="error-text"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($registrationErrors['confirm_password']); ?></p>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Terms and Conditions -->
                            <div class="flex items-start mt-4">
                                <div class="flex items-center h-5">
                                    <input type="checkbox" 
                                           name="agree_terms" 
                                           id="agreeTerms"
                                           class="w-4 h-4 text-indigo-600 border-gray-300 dark:border-gray-600 rounded focus:ring-indigo-500 dark:bg-gray-700">
                                </div>
                                <label for="agreeTerms" class="ml-3 text-sm text-gray-600 dark:text-gray-400">
                                    I agree to the 
                                    <a href="#" class="text-indigo-600 dark:text-indigo-400 hover:underline">Terms of Service</a> 
                                    and 
                                    <a href="#" class="text-indigo-600 dark:text-indigo-400 hover:underline">Privacy Policy</a> *
                                </label>
                            </div>
                            <?php if (isset($registrationErrors['agree_terms'])): ?>
                            <p class="error-text"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($registrationErrors['agree_terms']); ?></p>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Step 2: Profile Details -->
                        <div id="step2" style="<?php echo $currentStep !== 2 ? 'display: none;' : ''; ?>">
                            <!-- Seeker Profile Fields -->
                            <div id="seekerFields2" style="<?php echo (isset($_SESSION['registration']['data']['user_type']) && $_SESSION['registration']['data']['user_type'] === 'employer') ? 'display: none;' : ''; ?>">
                                <div class="floating-label-group">
                                    <input type="text" 
                                           name="headline" 
                                           id="headline"
                                           value="<?php echo htmlspecialchars($_SESSION['registration']['data']['headline'] ?? ''); ?>"
                                           placeholder=" "
                                           class="floating-input w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-transparent dark:bg-gray-700 dark:text-white">
                                    <label for="headline" class="floating-label">Professional Headline (e.g., Senior Software Engineer)</label>
                                </div>
                                
                                <div class="grid grid-cols-2 gap-4">
                                    <div class="floating-label-group">
                                        <input type="tel" 
                                               name="phone" 
                                               id="phone"
                                               value="<?php echo htmlspecialchars($_SESSION['registration']['data']['phone'] ?? ''); ?>"
                                               placeholder=" "
                                               class="floating-input w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-transparent dark:bg-gray-700 dark:text-white">
                                        <label for="phone" class="floating-label">Phone Number</label>
                                    </div>
                                    
                                    <div class="floating-label-group">
                                        <input type="tel" 
                                               name="whatsapp_number" 
                                               id="whatsapp"
                                               value="<?php echo htmlspecialchars($_SESSION['registration']['data']['whatsapp_number'] ?? ''); ?>"
                                               placeholder=" "
                                               class="floating-input w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-transparent dark:bg-gray-700 dark:text-white">
                                        <label for="whatsapp" class="floating-label">WhatsApp Number</label>
                                    </div>
                                </div>
                                
                                <div class="floating-label-group">
                                    <input type="text" 
                                           name="location" 
                                           id="location"
                                           value="<?php echo htmlspecialchars($_SESSION['registration']['data']['location'] ?? ''); ?>"
                                           placeholder=" "
                                           class="floating-input w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-transparent dark:bg-gray-700 dark:text-white">
                                    <label for="location" class="floating-label">Location (City, Country)</label>
                                </div>
                                
                                <div class="grid grid-cols-2 gap-4">
                                    <div class="floating-label-group">
                                        <input type="text" 
                                               name="current_job_title" 
                                               id="currentJobTitle"
                                               value="<?php echo htmlspecialchars($_SESSION['registration']['data']['current_job_title'] ?? ''); ?>"
                                               placeholder=" "
                                               class="floating-input w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-transparent dark:bg-gray-700 dark:text-white">
                                        <label for="currentJobTitle" class="floating-label">Current Job Title</label>
                                    </div>
                                    
                                    <div class="floating-label-group">
                                        <input type="text" 
                                               name="current_company" 
                                               id="currentCompany"
                                               value="<?php echo htmlspecialchars($_SESSION['registration']['data']['current_company'] ?? ''); ?>"
                                               placeholder=" "
                                               class="floating-input w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-transparent dark:bg-gray-700 dark:text-white">
                                        <label for="currentCompany" class="floating-label">Current Company</label>
                                    </div>
                                </div>
                                
                                <div class="grid grid-cols-2 gap-4">
                                    <div class="floating-label-group">
                                        <select name="years_experience" class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                                            <option value="0">Years of Experience</option>
                                            <?php for($i = 0; $i <= 30; $i++): ?>
                                            <option value="<?php echo $i; ?>" <?php echo ($_SESSION['registration']['data']['years_experience'] ?? 0) == $i ? 'selected' : ''; ?>><?php echo $i; ?> <?php echo $i == 1 ? 'year' : 'years'; ?></option>
                                            <?php endfor; ?>
                                            <option value="30+" <?php echo ($_SESSION['registration']['data']['years_experience'] ?? '') == '30+' ? 'selected' : ''; ?>>30+ years</option>
                                        </select>
                                    </div>
                                    
                                    <div class="floating-label-group">
                                        <select name="highest_education" class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                                            <option value="">Highest Education</option>
                                            <option value="high_school" <?php echo ($_SESSION['registration']['data']['highest_education'] ?? '') == 'high_school' ? 'selected' : ''; ?>>High School</option>
                                            <option value="associate" <?php echo ($_SESSION['registration']['data']['highest_education'] ?? '') == 'associate' ? 'selected' : ''; ?>>Associate Degree</option>
                                            <option value="bachelor" <?php echo ($_SESSION['registration']['data']['highest_education'] ?? '') == 'bachelor' ? 'selected' : ''; ?>>Bachelor's Degree</option>
                                            <option value="master" <?php echo ($_SESSION['registration']['data']['highest_education'] ?? '') == 'master' ? 'selected' : ''; ?>>Master's Degree</option>
                                            <option value="phd" <?php echo ($_SESSION['registration']['data']['highest_education'] ?? '') == 'phd' ? 'selected' : ''; ?>>PhD</option>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="floating-label-group">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Skills (comma separated)</label>
                                    <div class="skills-container" onclick="document.getElementById('skillsInput').focus()">
                                        <div id="skillsTags" class="flex flex-wrap items-center">
                                            <?php if (isset($_SESSION['registration']['data']['skills']) && is_array($_SESSION['registration']['data']['skills'])): ?>
                                                <?php foreach($_SESSION['registration']['data']['skills'] as $skill): ?>
                                                <span class="skill-tag">
                                                    <?php echo htmlspecialchars($skill); ?>
                                                    <i class="fas fa-times" onclick="removeSkill('<?php echo htmlspecialchars($skill); ?>')"></i>
                                                </span>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                            <input type="text" 
                                                   id="skillsInput"
                                                   class="flex-1 min-w-[200px] p-2 outline-none bg-transparent dark:text-white"
                                                   placeholder="Type skill and press Enter"
                                                   onkeypress="handleSkillInput(event)">
                                        </div>
                                    </div>
                                    <input type="hidden" name="skills" id="skillsHidden" value="<?php echo isset($_SESSION['registration']['data']['skills']) ? htmlspecialchars(implode(',', $_SESSION['registration']['data']['skills'])) : ''; ?>">
                                </div>
                            </div>
                            
                            <!-- Employer Profile Fields -->
                            <div id="employerFields2" style="<?php echo (isset($_SESSION['registration']['data']['user_type']) && $_SESSION['registration']['data']['user_type'] === 'employer') ? '' : 'display: none;'; ?>">
                                <div class="floating-label-group">
                                    <input type="email" 
                                           name="company_email" 
                                           id="companyEmail"
                                           value="<?php echo htmlspecialchars($_SESSION['registration']['data']['company_email'] ?? ''); ?>"
                                           placeholder=" "
                                           class="floating-input w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-transparent dark:bg-gray-700 dark:text-white">
                                    <label for="companyEmail" class="floating-label">Company Email</label>
                                </div>
                                
                                <div class="floating-label-group">
                                    <input type="tel" 
                                           name="company_phone" 
                                           id="companyPhone"
                                           value="<?php echo htmlspecialchars($_SESSION['registration']['data']['company_phone'] ?? ''); ?>"
                                           placeholder=" "
                                           class="floating-input w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-transparent dark:bg-gray-700 dark:text-white">
                                    <label for="companyPhone" class="floating-label">Company Phone</label>
                                </div>
                                
                                <div class="floating-label-group">
                                    <input type="url" 
                                           name="company_website" 
                                           id="companyWebsite"
                                           value="<?php echo htmlspecialchars($_SESSION['registration']['data']['company_website'] ?? ''); ?>"
                                           placeholder=" "
                                           class="floating-input w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-transparent dark:bg-gray-700 dark:text-white">
                                    <label for="companyWebsite" class="floating-label">Company Website</label>
                                </div>
                                
                                <div class="floating-label-group">
                                    <textarea name="company_description" 
                                              id="companyDescription" 
                                              rows="4"
                                              placeholder=" "
                                              class="floating-input w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-transparent dark:bg-gray-700 dark:text-white"><?php echo htmlspecialchars($_SESSION['registration']['data']['company_description'] ?? ''); ?></textarea>
                                    <label for="companyDescription" class="floating-label">Company Description</label>
                                </div>
                                
                                <div class="floating-label-group">
                                    <input type="text" 
                                           name="address" 
                                           id="address"
                                           value="<?php echo htmlspecialchars($_SESSION['registration']['data']['address'] ?? ''); ?>"
                                           placeholder=" "
                                           class="floating-input w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-transparent dark:bg-gray-700 dark:text-white">
                                    <label for="address" class="floating-label">Street Address</label>
                                </div>
                                
                                <div class="grid grid-cols-2 gap-4">
                                    <div class="floating-label-group">
                                        <input type="text" 
                                               name="city" 
                                               id="city"
                                               value="<?php echo htmlspecialchars($_SESSION['registration']['data']['city'] ?? ''); ?>"
                                               placeholder=" "
                                               class="floating-input w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-transparent dark:bg-gray-700 dark:text-white">
                                        <label for="city" class="floating-label">City</label>
                                    </div>
                                    
                                    <div class="floating-label-group">
                                        <input type="text" 
                                               name="country" 
                                               id="country"
                                               value="<?php echo htmlspecialchars($_SESSION['registration']['data']['country'] ?? ''); ?>"
                                               placeholder=" "
                                               class="floating-input w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-transparent dark:bg-gray-700 dark:text-white">
                                        <label for="country" class="floating-label">Country</label>
                                    </div>
                                </div>
                                
                                <div class="floating-label-group">
                                    <input type="text" 
                                           name="tax_id" 
                                           id="taxId"
                                           value="<?php echo htmlspecialchars($_SESSION['registration']['data']['tax_id'] ?? ''); ?>"
                                           placeholder=" "
                                           class="floating-input w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-transparent dark:bg-gray-700 dark:text-white">
                                    <label for="taxId" class="floating-label">Tax ID / Business Registration Number</label>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Step 3: Preferences -->
                        <div id="step3" style="<?php echo $currentStep !== 3 ? 'display: none;' : ''; ?>">
                            <p class="text-sm text-indigo-600 dark:text-indigo-400 mb-4">
                                <i class="fas fa-info-circle mr-1"></i>
                                These preferences will help us match you better. You can change them later.
                            </p>
                            
                            <!-- Seeker Preferences -->
                            <div id="seekerFields3" style="<?php echo (isset($_SESSION['registration']['data']['user_type']) && $_SESSION['registration']['data']['user_type'] === 'employer') ? 'display: none;' : ''; ?>">
                                <div class="mb-4">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                        Preferred Job Categories
                                    </label>
                                    <select name="preferred_categories[]" multiple class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white" size="4">
                                        <?php foreach ($jobCategories as $cat): ?>
                                        <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="text-xs text-gray-500 mt-1">Hold Ctrl/Cmd to select multiple</p>
                                </div>
                                
                                <div class="mb-4">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                        Preferred Job Types
                                    </label>
                                    <select name="preferred_job_types[]" multiple class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white" size="3">
                                        <?php foreach ($jobTypes as $type): ?>
                                        <option value="<?php echo $type['id']; ?>"><?php echo htmlspecialchars($type['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="mb-4">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                        Preferred Locations (Optional)
                                    </label>
                                    <input type="text" 
                                           name="preferred_locations" 
                                           placeholder="e.g., New York, London, Remote"
                                           class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                                </div>
                                
                                <div class="grid grid-cols-2 gap-4">
                                    <div class="floating-label-group">
                                        <input type="number" 
                                               name="min_salary" 
                                               placeholder=" "
                                               class="floating-input w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                                        <label class="floating-label">Minimum Salary (USD)</label>
                                    </div>
                                    
                                    <div class="floating-label-group">
                                        <input type="number" 
                                               name="max_salary" 
                                               placeholder=" "
                                               class="floating-input w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                                        <label class="floating-label">Maximum Salary (USD)</label>
                                    </div>
                                </div>
                                
                                <div class="mb-4">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                        Experience Level Preference
                                    </label>
                                    <select name="experience_level_preference" class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                                        <option value="any">Any Level</option>
                                        <option value="entry">Entry Level</option>
                                        <option value="mid">Mid Level</option>
                                        <option value="senior">Senior Level</option>
                                        <option value="executive">Executive</option>
                                    </select>
                                </div>
                            </div>
                            
                            <!-- Employer Preferences -->
                            <div id="employerFields3" style="<?php echo (isset($_SESSION['registration']['data']['user_type']) && $_SESSION['registration']['data']['user_type'] === 'employer') ? '' : 'display: none;'; ?>">
                                <div class="mb-4">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                        Required Job Categories
                                    </label>
                                    <select name="required_categories[]" multiple class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white" size="4">
                                        <?php foreach ($jobCategories as $cat): ?>
                                        <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="mb-4">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                        Required Skills
                                    </label>
                                    <input type="text" 
                                           name="required_skills" 
                                           placeholder="e.g., PHP, JavaScript, Python (comma separated)"
                                           class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                                </div>
                                
                                <div class="grid grid-cols-2 gap-4">
                                    <div class="floating-label-group">
                                        <input type="number" 
                                               name="experience_range_min" 
                                               value="0"
                                               placeholder=" "
                                               class="floating-input w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                                        <label class="floating-label">Min Experience (years)</label>
                                    </div>
                                    
                                    <div class="floating-label-group">
                                        <input type="number" 
                                               name="experience_range_max" 
                                               value="30"
                                               placeholder=" "
                                               class="floating-input w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                                        <label class="floating-label">Max Experience (years)</label>
                                    </div>
                                </div>
                                
                                <div class="mb-4">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                        Education Level
                                    </label>
                                    <select name="education_level" class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                                        <option value="">Any</option>
                                        <option value="high_school">High School</option>
                                        <option value="associate">Associate Degree</option>
                                        <option value="bachelor">Bachelor's Degree</option>
                                        <option value="master">Master's Degree</option>
                                        <option value="phd">PhD</option>
                                    </select>
                                </div>
                                
                                <div class="mb-4">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                        Preferred Candidate Locations
                                    </label>
                                    <input type="text" 
                                           name="location_preference" 
                                           placeholder="e.g., New York, London, Remote"
                                           class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                                </div>
                                
                                <div class="floating-label-group">
                                    <input type="number" 
                                           name="salary_budget_max" 
                                           placeholder=" "
                                           class="floating-input w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                                    <label class="floating-label">Maximum Salary Budget (USD/year)</label>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Navigation Buttons -->
                        <div class="flex justify-between pt-4">
                            <?php if ($currentStep > 1 && !isset($_GET['registration'])): ?>
                            <button type="button" 
                                    onclick="goToStep(<?php echo $currentStep - 1; ?>)"
                                    class="px-6 py-3 border-2 border-indigo-600 text-indigo-600 dark:text-indigo-400 rounded-xl font-semibold hover:bg-indigo-50 dark:hover:bg-indigo-900/20 transition-all duration-300">
                                <i class="fas fa-arrow-left mr-2"></i>
                                Previous
                            </button>
                            <?php endif; ?>
                            
                            <?php if ($currentStep < 3 && !isset($_GET['registration'])): ?>
                            <button type="button" 
                                    id="nextBtn"
                                    onclick="validateAndNext(<?php echo $currentStep; ?>)"
                                    class="ml-auto px-8 py-3 bg-gradient-to-r from-indigo-600 to-purple-600 text-white rounded-xl font-semibold hover:shadow-xl hover:scale-105 transition-all duration-300 shine">
                                Next Step
                                <i class="fas fa-arrow-right ml-2"></i>
                            </button>
                            <?php elseif (!isset($_GET['registration'])): ?>
                            <button type="submit" 
                                    id="submitBtn"
                                    class="ml-auto px-8 py-3 bg-gradient-to-r from-indigo-600 to-purple-600 text-white rounded-xl font-semibold hover:shadow-xl hover:scale-105 transition-all duration-300 shine">
                                Complete Registration
                                <i class="fas fa-check ml-2"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                    </form>
                    
                    <!-- Login Link -->
                    <div class="px-8 pb-8 text-center">
                        <p class="text-sm text-gray-600 dark:text-gray-400">
                            Already have an account? 
                            <a href="login.php" class="font-semibold text-indigo-600 dark:text-indigo-400 hover:underline">
                                Sign in here
                            </a>
                        </p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</main>

<!-- Footer -->
<footer class="relative z-10 bg-white dark:bg-gray-800 border-t dark:border-gray-700 py-6 mt-12">
    <div class="container mx-auto px-4">
        <div class="flex flex-col md:flex-row justify-between items-center text-sm text-gray-600 dark:text-gray-400">
            <p>&copy; <?php echo date('Y'); ?> TransNow. All rights reserved.</p>
            <div class="flex space-x-6 mt-4 md:mt-0">
                <a href="#" class="hover:text-indigo-600 dark:hover:text-indigo-400 transition">Privacy</a>
                <a href="#" class="hover:text-indigo-600 dark:hover:text-indigo-400 transition">Terms</a>
                <a href="#" class="hover:text-indigo-600 dark:hover:text-indigo-400 transition">Contact</a>
                <a href="#" class="hover:text-indigo-600 dark:hover:text-indigo-400 transition">中文</a>
            </div>
        </div>
    </div>
</footer>

<!-- Scripts -->
<script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>

<script>
// (All scripts remain unchanged)
// Initialize AOS
AOS.init({
    duration: 1000,
    once: true,
    offset: 100,
    easing: 'ease-out-cubic'
});

// Check system preference for dark mode
function getSystemTheme() {
    return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
}

// Initialize dark mode based on system preference
function initDarkMode() {
    const systemTheme = getSystemTheme();
    const savedTheme = localStorage.getItem('theme');
    const isDark = savedTheme === 'dark' || (!savedTheme && systemTheme === 'dark');
    
    if (isDark) {
        document.documentElement.classList.add('dark');
        updateDarkModeIcon(true);
    } else {
        document.documentElement.classList.remove('dark');
        updateDarkModeIcon(false);
    }
}

function updateDarkModeIcon(isDark) {
    const darkModeIcon = document.getElementById('darkModeIcon');
    if (darkModeIcon) {
        if (isDark) {
            darkModeIcon.classList.remove('fa-moon');
            darkModeIcon.classList.add('fa-sun');
        } else {
            darkModeIcon.classList.remove('fa-sun');
            darkModeIcon.classList.add('fa-moon');
        }
    }
}

// Listen for system theme changes
window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (e) => {
    if (!localStorage.getItem('theme')) {
        if (e.matches) {
            document.documentElement.classList.add('dark');
            updateDarkModeIcon(true);
        } else {
            document.documentElement.classList.remove('dark');
            updateDarkModeIcon(false);
        }
    }
});

// Dark Mode Toggle
const darkModeToggle = document.getElementById('darkModeToggle');
if (darkModeToggle) {
    darkModeToggle.addEventListener('click', () => {
        const isDark = document.documentElement.classList.toggle('dark');
        localStorage.setItem('theme', isDark ? 'dark' : 'light');
        updateDarkModeIcon(isDark);
    });
}

// Initialize dark mode
initDarkMode();

// Counter Animation for stats
const counters = document.querySelectorAll('.counter');

const counterObserver = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
        if (entry.isIntersecting) {
            const counter = entry.target;
            const target = parseInt(counter.getAttribute('data-target'));
            if (isNaN(target)) return;
            
            let count = 0;
            const duration = 1500;
            const increment = target / (duration / 16);
            
            const updateCounter = () => {
                count += increment;
                if (count < target) {
                    counter.innerText = Math.ceil(count).toLocaleString();
                    requestAnimationFrame(updateCounter);
                } else {
                    counter.innerText = target.toLocaleString();
                }
            };
            
            updateCounter();
            counterObserver.unobserve(counter);
        }
    });
}, { threshold: 0.5 });

counters.forEach(counter => counterObserver.observe(counter));

// User Type Toggle
function setUserType(type) {
    const userTypeInput = document.getElementById('userType');
    if (userTypeInput) userTypeInput.value = type;
    
    const seekerBtn = document.getElementById('seekerTypeBtn');
    const employerBtn = document.getElementById('employerTypeBtn');
    
    if (!seekerBtn || !employerBtn) return;
    
    if (type === 'seeker') {
        // Update button styles
        seekerBtn.classList.add('border-indigo-600', 'bg-indigo-50', 'dark:bg-indigo-900/20', 'active');
        seekerBtn.classList.remove('border-gray-200', 'dark:border-gray-700');
        employerBtn.classList.remove('border-indigo-600', 'bg-indigo-50', 'dark:bg-indigo-900/20', 'active');
        employerBtn.classList.add('border-gray-200', 'dark:border-gray-700');
        
        // Update icons
        const seekerIcon = seekerBtn.querySelector('.fa-user-graduate');
        const seekerText = seekerBtn.querySelector('.font-semibold');
        const employerIcon = employerBtn.querySelector('.fa-building');
        const employerText = employerBtn.querySelector('.font-semibold');
        
        if (seekerIcon) {
            seekerIcon.classList.add('text-indigo-600');
            seekerIcon.classList.remove('text-gray-400');
        }
        if (seekerText) {
            seekerText.classList.add('text-indigo-600');
            seekerText.classList.remove('text-gray-600', 'dark:text-gray-400');
        }
        if (employerIcon) {
            employerIcon.classList.add('text-gray-400');
            employerIcon.classList.remove('text-indigo-600');
        }
        if (employerText) {
            employerText.classList.add('text-gray-600', 'dark:text-gray-400');
            employerText.classList.remove('text-indigo-600');
        }
        
        // Update checkmarks
        updateCheckmarks(seekerBtn, employerBtn);
        
        // Show/hide fields for all steps
        document.querySelectorAll('[id^="seekerFields"]').forEach(el => {
            if (el) el.style.display = 'block';
        });
        document.querySelectorAll('[id^="employerFields"]').forEach(el => {
            if (el) el.style.display = 'none';
        });
        
    } else {
        // Update button styles
        employerBtn.classList.add('border-indigo-600', 'bg-indigo-50', 'dark:bg-indigo-900/20', 'active');
        employerBtn.classList.remove('border-gray-200', 'dark:border-gray-700');
        seekerBtn.classList.remove('border-indigo-600', 'bg-indigo-50', 'dark:bg-indigo-900/20', 'active');
        seekerBtn.classList.add('border-gray-200', 'dark:border-gray-700');
        
        // Update icons
        const seekerIcon = seekerBtn.querySelector('.fa-user-graduate');
        const seekerText = seekerBtn.querySelector('.font-semibold');
        const employerIcon = employerBtn.querySelector('.fa-building');
        const employerText = employerBtn.querySelector('.font-semibold');
        
        if (employerIcon) {
            employerIcon.classList.add('text-indigo-600');
            employerIcon.classList.remove('text-gray-400');
        }
        if (employerText) {
            employerText.classList.add('text-indigo-600');
            employerText.classList.remove('text-gray-600', 'dark:text-gray-400');
        }
        if (seekerIcon) {
            seekerIcon.classList.add('text-gray-400');
            seekerIcon.classList.remove('text-indigo-600');
        }
        if (seekerText) {
            seekerText.classList.add('text-gray-600', 'dark:text-gray-400');
            seekerText.classList.remove('text-indigo-600');
        }
        
        // Update checkmarks
        updateCheckmarks(employerBtn, seekerBtn);
        
        // Show/hide fields for all steps
        document.querySelectorAll('[id^="seekerFields"]').forEach(el => {
            if (el) el.style.display = 'none';
        });
        document.querySelectorAll('[id^="employerFields"]').forEach(el => {
            if (el) el.style.display = 'block';
        });
    }
}

function updateCheckmarks(activeBtn, inactiveBtn) {
    // Add check to active button if not exists
    if (!activeBtn.querySelector('.absolute')) {
        const checkDiv = document.createElement('div');
        checkDiv.className = 'absolute -top-2 -right-2 w-6 h-6 bg-indigo-600 rounded-full flex items-center justify-center';
        checkDiv.innerHTML = '<i class="fas fa-check text-white text-xs"></i>';
        activeBtn.appendChild(checkDiv);
    }
    
    // Remove check from inactive button
    const inactiveCheck = inactiveBtn.querySelector('.absolute');
    if (inactiveCheck) inactiveCheck.remove();
}

// Password Strength Checker
const passwordInput = document.getElementById('password');
const strengthDiv = document.getElementById('passwordStrength');
const strengthBar = document.getElementById('strengthBar');
const strengthText = document.getElementById('strengthText');

if (passwordInput && strengthDiv) {
    passwordInput.addEventListener('focus', () => {
        strengthDiv.classList.remove('hidden');
    });

    passwordInput.addEventListener('input', function() {
        const password = this.value;
        
        const hasLength = password.length >= 8;
        const hasUppercase = /[A-Z]/.test(password);
        const hasLowercase = /[a-z]/.test(password);
        const hasNumber = /\d/.test(password);
        
        const reqLength = document.getElementById('req-length');
        const reqUppercase = document.getElementById('req-uppercase');
        const reqLowercase = document.getElementById('req-lowercase');
        const reqNumber = document.getElementById('req-number');
        
        updateRequirement(reqLength, hasLength);
        updateRequirement(reqUppercase, hasUppercase);
        updateRequirement(reqLowercase, hasLowercase);
        updateRequirement(reqNumber, hasNumber);
        
        const checks = [hasLength, hasUppercase, hasLowercase, hasNumber];
        const strength = checks.filter(Boolean).length;
        
        if (strengthBar && strengthText) {
            strengthBar.className = 'h-full transition-all duration-300';
            if (strength <= 2) {
                strengthBar.classList.add('strength-weak');
                strengthText.textContent = 'Weak';
                strengthText.className = 'text-xs font-semibold text-red-500';
            } else if (strength === 3) {
                strengthBar.classList.add('strength-medium');
                strengthText.textContent = 'Medium';
                strengthText.className = 'text-xs font-semibold text-yellow-500';
            } else {
                strengthBar.classList.add('strength-strong');
                strengthText.textContent = 'Strong';
                strengthText.className = 'text-xs font-semibold text-green-500';
            }
        }
    });
}

function updateRequirement(element, met) {
    if (!element) return;
    const icon = element.querySelector('i');
    if (met) {
        if (icon) icon.className = 'fas fa-check-circle text-green-500 mr-1';
        element.classList.add('text-green-600', 'dark:text-green-400');
        element.classList.remove('text-gray-500', 'dark:text-gray-500');
    } else {
        if (icon) icon.className = 'far fa-circle mr-1';
        element.classList.add('text-gray-500', 'dark:text-gray-500');
        element.classList.remove('text-green-600', 'dark:text-green-400');
    }
}

// Confirm password matching
const confirmInput = document.getElementById('confirmPassword');
if (confirmInput && passwordInput) {
    confirmInput.addEventListener('input', function() {
        const password = passwordInput.value;
        const confirm = this.value;
        
        if (confirm.length > 0) {
            if (password === confirm) {
                this.classList.remove('border-red-500');
                this.classList.add('border-green-500');
            } else {
                this.classList.remove('border-green-500');
                this.classList.add('border-red-500');
            }
        } else {
            this.classList.remove('border-red-500', 'border-green-500');
            this.classList.add('border-gray-300', 'dark:border-gray-600');
        }
    });
}

// Skills Input Handling
let skills = <?php echo isset($_SESSION['registration']['data']['skills']) ? json_encode($_SESSION['registration']['data']['skills']) : '[]'; ?>;

function handleSkillInput(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        const input = e.target;
        const skill = input.value.trim();
        
        if (skill && !skills.includes(skill)) {
            skills.push(skill);
            updateSkillsDisplay();
            input.value = '';
        }
    }
}

function removeSkill(skill) {
    skills = skills.filter(s => s !== skill);
    updateSkillsDisplay();
}

function updateSkillsDisplay() {
    const tagsContainer = document.getElementById('skillsTags');
    const hiddenInput = document.getElementById('skillsHidden');
    
    if (tagsContainer) {
        // Clear existing tags except the input
        const input = tagsContainer.querySelector('input');
        tagsContainer.innerHTML = '';
        
        // Add skill tags
        skills.forEach(skill => {
            const tag = document.createElement('span');
            tag.className = 'skill-tag';
            tag.innerHTML = `${escapeHtml(skill)} <i class="fas fa-times" onclick="removeSkill('${escapeHtml(skill)}')"></i>`;
            tagsContainer.appendChild(tag);
        });
        
        // Add back the input
        if (input) tagsContainer.appendChild(input);
    }
    
    if (hiddenInput) {
        hiddenInput.value = skills.join(',');
    }
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Real-time validation
async function validateField(field, value) {
    const formData = new FormData();
    formData.append('ajax', 'validate');
    formData.append('field', field);
    formData.append('value', value);
    
    try {
        const response = await fetch('register.php', {
            method: 'POST',
            body: formData
        });
        const data = await response.json();
        return data.errors;
    } catch (error) {
        console.error('Validation error:', error);
        return {};
    }
}

// Step navigation with validation
async function validateAndNext(currentStep) {
    const nextBtn = document.getElementById('nextBtn');
    if (!nextBtn) return;
    
    const originalText = nextBtn.innerHTML;
    
    // Show loading state
    nextBtn.disabled = true;
    nextBtn.innerHTML = '<span class="spinner mr-2"></span><span>Validating...</span>';
    
    let isValid = true;
    
    if (currentStep === 1) {
        // Validate step 1 fields
        const userType = document.getElementById('userType').value;
        const email = document.getElementById('email');
        const password = document.getElementById('password');
        const confirmPassword = document.getElementById('confirmPassword');
        const agreeTerms = document.getElementById('agreeTerms');
        
        // Remove all previous error messages
        document.querySelectorAll('.error-text').forEach(el => el.remove());
        document.querySelectorAll('.border-red-500').forEach(el => el.classList.remove('border-red-500'));
        
        // Email validation
        if (!email.value) {
            showFieldError('email', 'Email is required');
            isValid = false;
        } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value)) {
            showFieldError('email', 'Please enter a valid email address');
            isValid = false;
        } else {
            // Check if email exists
            const emailErrors = await validateField('email', email.value);
            if (emailErrors.email) {
                showFieldError('email', emailErrors.email);
                isValid = false;
            }
        }
        
        // Password validation
        if (!password.value) {
            showFieldError('password', 'Password is required');
            isValid = false;
        } else if (password.value.length < 8) {
            showFieldError('password', 'Password must be at least 8 characters');
            isValid = false;
        } else if (!/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)/.test(password.value)) {
            showFieldError('password', 'Password must contain uppercase, lowercase, and number');
            isValid = false;
        }
        
        if (password.value !== confirmPassword.value) {
            showFieldError('confirm_password', 'Passwords do not match');
            isValid = false;
        }
        
        if (!agreeTerms.checked) {
            showFieldError('agree_terms', 'You must agree to the Terms of Service');
            isValid = false;
        }
        
        // User type specific validation
        if (userType === 'seeker') {
            const firstName = document.getElementById('firstName');
            const lastName = document.getElementById('lastName');
            
            if (!firstName.value) {
                showFieldError('first_name', 'First name is required');
                isValid = false;
            }
            if (!lastName.value) {
                showFieldError('last_name', 'Last name is required');
                isValid = false;
            }
        } else {
            const companyName = document.getElementById('companyName');
            const industry = document.getElementById('industry');
            
            if (!companyName.value) {
                showFieldError('company_name', 'Company name is required');
                isValid = false;
            }
            if (!industry.value) {
                showFieldError('industry', 'Industry is required');
                isValid = false;
            }
        }
    }
    
    // Remove loading state
    nextBtn.disabled = false;
    nextBtn.innerHTML = originalText;
    
    if (isValid) {
        // Submit the form to move to next step
        document.getElementById('registerForm').submit();
    } else {
        // Scroll to first error
        const firstError = document.querySelector('.border-red-500');
        if (firstError) {
            firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }
}

function showFieldError(fieldName, message) {
    let field;
    if (fieldName === 'first_name') field = document.getElementById('firstName');
    else if (fieldName === 'last_name') field = document.getElementById('lastName');
    else if (fieldName === 'company_name') field = document.getElementById('companyName');
    else if (fieldName === 'industry') field = document.getElementById('industry');
    else if (fieldName === 'email') field = document.getElementById('email');
    else if (fieldName === 'password') field = document.getElementById('password');
    else if (fieldName === 'confirm_password') field = document.getElementById('confirmPassword');
    else if (fieldName === 'agree_terms') field = document.getElementById('agreeTerms');
    
    if (field) {
        field.classList.add('border-red-500');
        
        // Check if error message already exists
        let errorMsg = field.parentNode.querySelector('.error-text');
        if (!errorMsg && field.parentNode) {
            errorMsg = document.createElement('p');
            errorMsg.className = 'error-text';
            errorMsg.innerHTML = '<i class="fas fa-exclamation-circle"></i> ' + message;
            field.parentNode.appendChild(errorMsg);
        }
    }
}

function goToStep(step) {
    // Create a form to submit and change step
    const form = document.getElementById('registerForm');
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'go_to_step';
    input.value = step;
    form.appendChild(input);
    form.submit();
}

// Email format validation
const emailInput = document.getElementById('email');
if (emailInput) {
    emailInput.addEventListener('blur', async function() {
        const email = this.value;
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        
        this.classList.remove('border-red-500');
        const existingError = this.parentNode.querySelector('.error-text');
        if (existingError) existingError.remove();
        
        if (email && !emailRegex.test(email)) {
            this.classList.add('border-red-500');
            const errorMsg = document.createElement('p');
            errorMsg.className = 'error-text';
            errorMsg.innerHTML = '<i class="fas fa-exclamation-circle"></i>Please enter a valid email address';
            this.parentNode.appendChild(errorMsg);
        } else if (email) {
            const errors = await validateField('email', email);
            if (errors.email) {
                this.classList.add('border-red-500');
                const errorMsg = document.createElement('p');
                errorMsg.className = 'error-text';
                errorMsg.innerHTML = '<i class="fas fa-exclamation-circle"></i>' + errors.email;
                this.parentNode.appendChild(errorMsg);
            }
        }
    });
}

// Initialize form with saved data
document.addEventListener('DOMContentLoaded', function() {
    // Initialize skills display
    updateSkillsDisplay();
    
    // Initialize user type based on session data
    const userType = document.getElementById('userType');
    if (userType && userType.value === 'employer') {
        setUserType('employer');
    }
    
    // Initialize AOS
    if (typeof AOS !== 'undefined') {
        AOS.refresh();
    }
});
</script>

<!-- Structured Data for SEO -->
<script type="application/ld+json">
{
    "@context": "https://schema.org",
    "@type": "WebPage",
    "name": "Register - TransNow",
    "description": "Complete your profile and join TransNow. Instant account activation – log in immediately after registration.",
    "url": "https://<?php echo $_SERVER['HTTP_HOST']; ?>/register.php"
}
</script>

</body>
</html>