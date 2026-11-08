<?php
// seekers/profile.php - Advanced Seeker Profile Page
// Version: 4.0 - Added CV upload and language management

// Start session (if not already started)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include global configuration and helper functions
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../includes/auth.php';

// Check if user is logged in and is a seeker
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'seeker') {
    header('Location: ../login.php');
    exit();
}

// Get database connection (PDO)
$pdo = getDB();
$userId = $_SESSION['user_id'];

// Fetch seeker profile with all details
$seeker = fetchOne("
    SELECT 
        s.*,
        u.email,
        u.is_verified as user_verified,
        u.created_at as user_created_at
    FROM seekers s
    INNER JOIN users u ON s.user_id = u.id
    WHERE s.user_id = ?
", [$userId]);

if (!$seeker) {
    // Redirect to profile creation if no profile exists
    header('Location: profile-setup.php');
    exit();
}

// Fetch seeker languages
$seekerLanguages = fetchAll("
    SELECT 
        sl.*,
        l.name as language_name,
        l.code as language_code
    FROM seeker_languages sl
    INNER JOIN languages l ON sl.language_id = l.id
    WHERE sl.seeker_id = ?
    ORDER BY 
        FIELD(sl.proficiency_level, 'native', 'fluent', 'advanced', 'intermediate', 'beginner'),
        l.name ASC
", [$seeker['id']]);

// Fetch all available languages for dropdown
$availableLanguages = fetchAll("
    SELECT id, name, code 
    FROM languages 
    WHERE is_active = 1 
    ORDER BY name ASC
");

// Handle profile update
$updateSuccess = false;
$updateError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        
        // Update profile information
        if ($_POST['action'] === 'update_profile') {
            // Sanitize inputs
            $first_name = trim($_POST['first_name']);
            $last_name = trim($_POST['last_name']);
            $phone = trim($_POST['phone']);
            $whatsapp_number = trim($_POST['whatsapp_number']);
            $headline = trim($_POST['headline']);
            $bio = trim($_POST['bio']);
            $location = trim($_POST['location']);
            $current_job_title = trim($_POST['current_job_title']);
            $current_company = trim($_POST['current_company']);
            $years_experience = intval($_POST['years_experience']);
            $highest_education = trim($_POST['highest_education']);
            $skills = trim($_POST['skills']);
            $expected_salary_min = !empty($_POST['expected_salary_min']) ? floatval($_POST['expected_salary_min']) : null;
            $expected_salary_max = !empty($_POST['expected_salary_max']) ? floatval($_POST['expected_salary_max']) : null;
            
            // Calculate profile completion percentage
            $completionFields = [
                'first_name', 'last_name', 'phone', 'headline', 'bio', 
                'location', 'current_job_title', 'current_company', 
                'years_experience', 'highest_education', 'skills'
            ];
            
            $filledFields = 0;
            foreach ($completionFields as $field) {
                if (!empty($$field)) $filledFields++;
            }
            
            // Add languages to completion calculation
            if (!empty($seekerLanguages)) {
                $filledFields++;
            }
            $completionFields[] = 'languages';
            
            $profile_completion_percent = round(($filledFields / count($completionFields)) * 100);
            
            // Update query
            $sql = "UPDATE seekers SET 
                    first_name = ?, last_name = ?, phone = ?, whatsapp_number = ?,
                    headline = ?, bio = ?, location = ?, current_job_title = ?,
                    current_company = ?, years_experience = ?, highest_education = ?,
                    skills = ?, expected_salary_min = ?, expected_salary_max = ?,
                    profile_completion_percent = ?, updated_at = NOW()
                    WHERE user_id = ?";
            
            $result = executeQuery($sql, [
                $first_name, $last_name, $phone, $whatsapp_number,
                $headline, $bio, $location, $current_job_title,
                $current_company, $years_experience, $highest_education,
                $skills, $expected_salary_min, $expected_salary_max,
                $profile_completion_percent, $userId
            ]);
            
            if ($result) {
                $updateSuccess = true;
                // Refresh seeker data
                $seeker = fetchOne("
                    SELECT s.*, u.email, u.is_verified as user_verified
                    FROM seekers s
                    INNER JOIN users u ON s.user_id = u.id
                    WHERE s.user_id = ?
                ", [$userId]);
            } else {
                $updateError = "Failed to update profile. Please try again.";
            }
        }
        
        // Add language
        elseif ($_POST['action'] === 'add_language') {
            $language_id = intval($_POST['language_id']);
            $proficiency_level = $_POST['proficiency_level'];
            
            // Check if language already exists for this seeker
            $existing = fetchOne("
                SELECT id FROM seeker_languages 
                WHERE seeker_id = ? AND language_id = ?
            ", [$seeker['id'], $language_id]);
            
            if (!$existing) {
                $result = executeQuery("
                    INSERT INTO seeker_languages (seeker_id, language_id, proficiency_level) 
                    VALUES (?, ?, ?)
                ", [$seeker['id'], $language_id, $proficiency_level]);
                
                if ($result) {
                    $updateSuccess = true;
                } else {
                    $updateError = "Failed to add language.";
                }
            } else {
                $updateError = "This language is already added.";
            }
            
            // Refresh languages
            $seekerLanguages = fetchAll("
                SELECT 
                    sl.*,
                    l.name as language_name,
                    l.code as language_code
                FROM seeker_languages sl
                INNER JOIN languages l ON sl.language_id = l.id
                WHERE sl.seeker_id = ?
                ORDER BY 
                    FIELD(sl.proficiency_level, 'native', 'fluent', 'advanced', 'intermediate', 'beginner'),
                    l.name ASC
            ", [$seeker['id']]);
        }
        
        // Update language proficiency
        elseif ($_POST['action'] === 'update_language') {
            $language_id = intval($_POST['language_id']);
            $proficiency_level = $_POST['proficiency_level'];
            
            $result = executeQuery("
                UPDATE seeker_languages 
                SET proficiency_level = ?, updated_at = NOW()
                WHERE seeker_id = ? AND language_id = ?
            ", [$proficiency_level, $seeker['id'], $language_id]);
            
            if ($result) {
                $updateSuccess = true;
            } else {
                $updateError = "Failed to update language proficiency.";
            }
            
            // Refresh languages
            $seekerLanguages = fetchAll("
                SELECT 
                    sl.*,
                    l.name as language_name,
                    l.code as language_code
                FROM seeker_languages sl
                INNER JOIN languages l ON sl.language_id = l.id
                WHERE sl.seeker_id = ?
                ORDER BY 
                    FIELD(sl.proficiency_level, 'native', 'fluent', 'advanced', 'intermediate', 'beginner'),
                    l.name ASC
            ", [$seeker['id']]);
        }
        
        // Remove language
        elseif ($_POST['action'] === 'remove_language') {
            $language_id = intval($_POST['language_id']);
            
            $result = executeQuery("
                DELETE FROM seeker_languages 
                WHERE seeker_id = ? AND language_id = ?
            ", [$seeker['id'], $language_id]);
            
            if ($result) {
                $updateSuccess = true;
            } else {
                $updateError = "Failed to remove language.";
            }
            
            // Refresh languages
            $seekerLanguages = fetchAll("
                SELECT 
                    sl.*,
                    l.name as language_name,
                    l.code as language_code
                FROM seeker_languages sl
                INNER JOIN languages l ON sl.language_id = l.id
                WHERE sl.seeker_id = ?
                ORDER BY 
                    FIELD(sl.proficiency_level, 'native', 'fluent', 'advanced', 'intermediate', 'beginner'),
                    l.name ASC
            ", [$seeker['id']]);
        }
        
        // Handle CV upload
        elseif ($_POST['action'] === 'upload_cv') {
            if (isset($_FILES['cv_file']) && $_FILES['cv_file']['error'] === UPLOAD_ERR_OK) {
                $allowed_types = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
                $file_type = $_FILES['cv_file']['type'];
                
                if (in_array($file_type, $allowed_types)) {
                    $upload_dir = __DIR__ . '/../uploads/resumes/';
                    
                    // Create directory if it doesn't exist
                    if (!file_exists($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }
                    
                    // Generate unique filename
                    $file_extension = pathinfo($_FILES['cv_file']['name'], PATHINFO_EXTENSION);
                    $file_name = 'resume_' . $seeker['id'] . '_' . time() . '.' . $file_extension;
                    $file_path = $upload_dir . $file_name;
                    
                    if (move_uploaded_file($_FILES['cv_file']['tmp_name'], $file_path)) {
                        // Delete old resume if exists
                        if (!empty($seeker['resume_url'])) {
                            $old_file = __DIR__ . '/..' . $seeker['resume_url'];
                            if (file_exists($old_file)) {
                                unlink($old_file);
                            }
                        }
                        
                        // Update database with relative path
                        $resume_url = '/uploads/resumes/' . $file_name;
                        $result = executeQuery("
                            UPDATE seekers SET resume_url = ?, updated_at = NOW() 
                            WHERE id = ?
                        ", [$resume_url, $seeker['id']]);
                        
                        if ($result) {
                            $updateSuccess = true;
                            // Refresh seeker data
                            $seeker['resume_url'] = $resume_url;
                        } else {
                            $updateError = "Failed to update resume in database.";
                        }
                    } else {
                        $updateError = "Failed to upload file.";
                    }
                } else {
                    $updateError = "Invalid file type. Please upload PDF or Word document.";
                }
            } else {
                $updateError = "No file selected or upload error.";
            }
        }
        
        // Delete CV
        elseif ($_POST['action'] === 'delete_cv') {
            if (!empty($seeker['resume_url'])) {
                $file_path = __DIR__ . '/..' . $seeker['resume_url'];
                if (file_exists($file_path)) {
                    unlink($file_path);
                }
                
                $result = executeQuery("
                    UPDATE seekers SET resume_url = NULL, updated_at = NOW() 
                    WHERE id = ?
                ", [$seeker['id']]);
                
                if ($result) {
                    $updateSuccess = true;
                    $seeker['resume_url'] = null;
                } else {
                    $updateError = "Failed to delete resume.";
                }
            }
        }
    }
}

// Fetch seeker preferences
$preferences = fetchOne("
    SELECT * FROM seeker_preferences 
    WHERE seeker_id = ? AND is_active = 1
", [$seeker['id']]);

// Fetch recent applications
$recentApplications = fetchAll("
    SELECT 
        a.*,
        j.title as job_title,
        j.slug as job_slug,
        j.location_type,
        j.city,
        j.country,
        e.company_name,
        e.company_logo
    FROM applications a
    INNER JOIN jobs j ON a.job_id = j.id
    INNER JOIN employers e ON j.employer_id = e.id
    WHERE a.seeker_id = ?
    ORDER BY a.applied_at DESC
    LIMIT 5
", [$seeker['id']]);

// Fetch saved jobs
$savedJobs = fetchAll("
    SELECT 
        sj.*,
        j.title as job_title,
        j.slug as job_slug,
        j.location_type,
        j.city,
        j.salary_min,
        j.salary_max,
        j.salary_currency,
        e.company_name,
        e.company_logo
    FROM saved_jobs sj
    INNER JOIN jobs j ON sj.job_id = j.id
    INNER JOIN employers e ON j.employer_id = e.id
    WHERE sj.seeker_id = ?
    ORDER BY sj.created_at DESC
    LIMIT 4
", [$seeker['id']]);

// Fetch saved by employers (who saved this candidate)
$savedByEmployers = fetchAll("
    SELECT 
        sc.*,
        e.company_name,
        e.company_logo,
        e.industry,
        e.city as employer_city,
        e.country as employer_country
    FROM saved_candidates sc
    INNER JOIN employers e ON sc.employer_id = e.id
    WHERE sc.seeker_id = ?
    ORDER BY sc.created_at DESC
    LIMIT 6
", [$seeker['id']]);

// Fetch job recommendations based on skills and preferences
$recommendedJobs = fetchAll("
    SELECT 
        j.id, j.title, j.slug, j.description,
        j.salary_min, j.salary_max, j.salary_currency,
        j.location_type, j.city, j.country,
        j.experience_level, j.is_urgent,
        j.created_at,
        e.company_name, e.company_logo,
        jt.name as job_type_name,
        jc.name as category_name
    FROM jobs j
    INNER JOIN employers e ON j.employer_id = e.id
    LEFT JOIN job_types jt ON j.job_type_id = jt.id
    LEFT JOIN job_categories jc ON j.category_id = jc.id
    WHERE j.status = 'active'
    AND e.is_verified = 1
    AND (j.application_deadline >= CURDATE() OR j.application_deadline IS NULL)
    ORDER BY 
        CASE 
            WHEN j.experience_level = ? THEN 1
            WHEN j.salary_min BETWEEN ? AND ? THEN 2
            ELSE 3
        END,
        j.created_at DESC
    LIMIT 6
", [
    $seeker['years_experience'] < 2 ? 'entry' : ($seeker['years_experience'] < 5 ? 'mid' : 'senior'),
    $seeker['expected_salary_min'] ?? 0,
    $seeker['expected_salary_max'] ?? 1000000
]);

// Fetch statistics
$stats = fetchOne("
    SELECT 
        (SELECT COUNT(*) FROM applications WHERE seeker_id = ?) as total_applications,
        (SELECT COUNT(*) FROM applications WHERE seeker_id = ? AND application_status = 'interview') as total_interviews,
        (SELECT COUNT(*) FROM applications WHERE seeker_id = ? AND application_status = 'hired') as total_hired,
        (SELECT COUNT(*) FROM saved_jobs WHERE seeker_id = ?) as total_saved,
        (SELECT COUNT(*) FROM saved_candidates WHERE seeker_id = ?) as total_saved_by,
        (SELECT COUNT(*) FROM job_views jv 
         INNER JOIN jobs j ON jv.job_id = j.id 
         WHERE jv.user_id = ?) as total_job_views,
        (SELECT COUNT(*) FROM applications WHERE seeker_id = ? AND match_score >= 80) as high_match_count,
        (SELECT COUNT(*) FROM applications WHERE seeker_id = ? AND application_status = 'reviewed') as reviewed_count
", [
    $seeker['id'], $seeker['id'], $seeker['id'], 
    $seeker['id'], $seeker['id'], $userId,
    $seeker['id'], $seeker['id']
]);

// Parse skills into array
$skillsArray = !empty($seeker['skills']) ? array_map('trim', explode(',', $seeker['skills'])) : [];

// Format name
$fullName = htmlspecialchars($seeker['first_name'] . ' ' . $seeker['last_name']);
$initials = strtoupper(substr($seeker['first_name'], 0, 1) . substr($seeker['last_name'], 0, 1));

// Function to get proficiency level badge color
function getProficiencyBadge($level) {
    $colors = [
        'beginner' => 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300',
        'intermediate' => 'bg-blue-100 text-blue-600 dark:bg-blue-900 dark:text-blue-300',
        'advanced' => 'bg-green-100 text-green-600 dark:bg-green-900 dark:text-green-300',
        'fluent' => 'bg-purple-100 text-purple-600 dark:bg-purple-900 dark:text-purple-300',
        'native' => 'bg-yellow-100 text-yellow-600 dark:bg-yellow-900 dark:text-yellow-300'
    ];
    return $colors[$level] ?? $colors['intermediate'];
}

// Function to get proficiency level label
function getProficiencyLabel($level) {
    $labels = [
        'beginner' => 'Beginner',
        'intermediate' => 'Intermediate',
        'advanced' => 'Advanced',
        'fluent' => 'Fluent',
        'native' => 'Native'
    ];
    return $labels[$level] ?? ucfirst($level);
}
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $fullName; ?> - Seeker Profile | TransNow</title>
    <meta name="description" content="Professional profile of <?php echo $fullName; ?> - <?php echo htmlspecialchars($seeker['headline'] ?? 'Job Seeker'); ?>">
    
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Ccircle cx='50' cy='50' r='45' fill='%234f46e5'/%3E%3Cpath d='M30 40 L70 40 L65 70 L35 70 Z' fill='white'/%3E%3Ccircle cx='50' cy='30' r='8' fill='white'/%3E%3C/svg%3E">
    
    <!-- Preconnect to CDNs -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://cdnjs.cloudflare.com">
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- AOS Animation -->
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <style>
        * {
            font-family: 'Inter', sans-serif;
        }
        
        /* Custom Animations */
        @keyframes float {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }
        
        @keyframes pulse-glow {
            0%, 100% { box-shadow: 0 0 0 0 rgba(79, 70, 229, 0.5); }
            50% { box-shadow: 0 0 30px 10px rgba(79, 70, 229, 0.3); }
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
        
        @keyframes shimmer {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }
        
        @keyframes rotate {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        
        /* Custom Classes */
        .animate-float {
            animation: float 6s ease-in-out infinite;
        }
        
        .animate-pulse-glow {
            animation: pulse-glow 3s ease-in-out infinite;
        }
        
        .gradient-bg {
            background: linear-gradient(-45deg, #4f46e5, #7c3aed, #db2777, #4f46e5);
            background-size: 400% 400%;
            animation: gradient 15s ease infinite;
        }
        
        .slide-in {
            animation: slideIn 0.6s ease-out forwards;
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
            box-shadow: 0 20px 40px -15px rgba(79, 70, 229, 0.3);
        }
        
        .hover-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, rgba(79, 70, 229, 0.1), rgba(124, 58, 237, 0.1));
            opacity: 0;
            transition: opacity 0.4s;
            pointer-events: none;
            z-index: 1;
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
        
        /* Loading Skeleton */
        .skeleton {
            background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
            background-size: 200% 100%;
            animation: shimmer 1.5s infinite;
        }
        
        /* Skill Tags */
        .skill-tag {
            background: linear-gradient(135deg, #4f46e5, #7c3aed);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 9999px;
            font-size: 0.875rem;
            font-weight: 500;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .skill-tag:hover {
            transform: scale(1.05);
            box-shadow: 0 10px 20px -5px rgba(79, 70, 229, 0.5);
        }
        
        /* Profile Header */
        .profile-header {
            position: relative;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 2rem;
            overflow: hidden;
        }
        
        .profile-header::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 50%);
            animation: rotate 20s linear infinite;
        }
        
        /* Stat Card */
        .stat-card {
            background: white;
            border-radius: 1.5rem;
            padding: 1.5rem;
            box-shadow: 0 10px 30px -10px rgba(0, 0, 0, 0.1);
            transition: all 0.3s;
            border: 1px solid rgba(79, 70, 229, 0.1);
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 40px -15px rgba(79, 70, 229, 0.3);
            border-color: rgba(79, 70, 229, 0.3);
        }
        
        /* Progress Bar */
        .progress-bar {
            height: 0.5rem;
            background: #e5e7eb;
            border-radius: 9999px;
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #4f46e5, #7c3aed);
            border-radius: 9999px;
            transition: width 1s ease;
            position: relative;
            overflow: hidden;
        }
        
        .progress-fill::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            animation: shimmer 2s infinite;
        }
        
        /* Timeline */
        .timeline-item {
            position: relative;
            padding-left: 2rem;
            padding-bottom: 2rem;
            border-left: 2px solid #e5e7eb;
        }
        
        .timeline-item:last-child {
            border-left-color: transparent;
        }
        
        .timeline-item::before {
            content: '';
            position: absolute;
            left: -0.5rem;
            top: 0;
            width: 1rem;
            height: 1rem;
            background: linear-gradient(135deg, #4f46e5, #7c3aed);
            border-radius: 50%;
            border: 2px solid white;
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.2);
        }
        
        /* Floating Action Button */
        .fab {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            z-index: 50;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .fab:hover {
            transform: scale(1.1) rotate(90deg);
        }
        
        /* Dark mode support */
        @media (prefers-color-scheme: dark) {
            .dark-mode-aware {
                background: #1f2937;
                color: #ffffff;
            }
        }
        
        /* Mobile Optimizations */
        @media (max-width: 768px) {
            .profile-header {
                border-radius: 1rem;
            }
        }
        
        /* Animated Underline */
        .animated-underline {
            position: relative;
        }
        
        .animated-underline::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            width: 0;
            height: 2px;
            background: linear-gradient(90deg, #4f46e5, #7c3aed);
            transition: width 0.3s ease;
        }
        
        .animated-underline:hover::after {
            width: 100%;
        }
        
        /* Tab Navigation */
        .tab-button {
            position: relative;
            padding: 1rem 1.5rem;
            font-weight: 600;
            color: #6b7280;
            transition: all 0.3s;
            cursor: pointer;
        }
        
        .tab-button.active {
            color: #4f46e5;
        }
        
        .tab-button.active::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, #4f46e5, #7c3aed);
            border-radius: 3px 3px 0 0;
            animation: slideIn 0.3s ease;
        }
        
        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
            z-index: 100;
            align-items: center;
            justify-content: center;
        }
        
        .modal.active {
            display: flex;
        }
        
        .modal-content {
            background: white;
            border-radius: 2rem;
            padding: 2rem;
            max-width: 600px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            animation: slideIn 0.3s ease;
        }
        
        /* CV Upload Area */
        .cv-upload-area {
            border: 2px dashed #e5e7eb;
            border-radius: 1rem;
            padding: 2rem;
            text-align: center;
            transition: all 0.3s;
            cursor: pointer;
        }
        
        .cv-upload-area:hover {
            border-color: #4f46e5;
            background: rgba(79, 70, 229, 0.05);
        }
        
        .cv-upload-area.dragover {
            border-color: #4f46e5;
            background: rgba(79, 70, 229, 0.1);
        }
        
        /* Language Proficiency Stars */
        .proficiency-stars {
            display: inline-flex;
            gap: 0.25rem;
        }
        
        .proficiency-star {
            color: #d1d5db;
            font-size: 1rem;
        }
        
        .proficiency-star.filled {
            color: #fbbf24;
        }
        
        .proficiency-star.half-filled {
            color: #fbbf24;
            position: relative;
        }
        
        .proficiency-star.half-filled::after {
            content: '\f089';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            position: absolute;
            left: 0;
            top: 0;
            color: #fbbf24;
            width: 50%;
            overflow: hidden;
        }
        
        /* Share Button */
        .share-button {
            transition: all 0.3s;
        }
        
        .share-button:hover {
            transform: translateY(-2px);
        }
    </style>
</head>
<body class="bg-gray-50 dark:bg-gray-900 text-gray-900 dark:text-white transition-colors duration-300 overflow-x-hidden">

<!-- Floating Action Button -->
<div class="fab flex flex-col space-y-3">
    <button onclick="window.scrollTo({top: 0, behavior: 'smooth'})" 
            id="scrollTopBtn" 
            class="w-14 h-14 bg-gradient-to-r from-indigo-600 to-purple-600 text-white rounded-full shadow-2xl flex items-center justify-center opacity-0 invisible transition-all duration-300 hover:shadow-indigo-500/50">
        <i class="fas fa-arrow-up text-xl"></i>
    </button>
    <button onclick="openEditModal()"
            class="w-14 h-14 bg-gradient-to-r from-green-500 to-emerald-600 text-white rounded-full shadow-2xl flex items-center justify-center animate-pulse-glow">
        <i class="fas fa-pen text-xl"></i>
    </button>
</div>

<!-- Dark Mode Toggle -->
<button id="darkModeToggle" 
        class="fixed top-24 right-4 z-50 w-12 h-12 glass dark:glass-dark text-white rounded-full flex items-center justify-center hover:scale-110 transition-all duration-300">
    <i class="fas fa-moon text-xl" id="darkModeIcon"></i>
</button>

<!-- Navigation -->
<nav class="fixed w-full z-40 transition-all duration-300" id="navbar">
    <div class="container mx-auto px-4 py-4">
        <div class="glass dark:glass-dark rounded-2xl px-6 py-3">
            <div class="flex items-center justify-between">
                <!-- Logo -->
                <a href="../index.php" class="flex items-center space-x-3 group">
                    <div class="w-12 h-12 bg-gradient-to-r from-indigo-600 to-purple-600 rounded-xl flex items-center justify-center transform group-hover:rotate-12 transition-all duration-300 shadow-lg">
                        <i class="fas fa-globe-asia text-white text-2xl"></i>
                    </div>
                    <span class="text-2xl font-bold">
                        <span class="gradient-text">TransNow</span>
                    </span>
                </a>
                
                <!-- Desktop Menu -->
                <div class="hidden lg:flex items-center space-x-8">
                    <a href="../index.php" class="animated-underline font-medium text-gray-700 dark:text-gray-300 hover:text-indigo-600 dark:hover:text-indigo-400 transition">
                        <i class="fas fa-home mr-2"></i>Home
                    </a>
                    <a href="jobs.php" class="animated-underline font-medium text-gray-700 dark:text-gray-300 hover:text-indigo-600 dark:hover:text-indigo-400 transition">
                        <i class="fas fa-search mr-2"></i>Find Jobs
                    </a>
                    <a href="applications.php" class="animated-underline font-medium text-gray-700 dark:text-gray-300 hover:text-indigo-600 dark:hover:text-indigo-400 transition">
                        <i class="fas fa-paper-plane mr-2"></i>Applications
                    </a>
                    <a href="saved-jobs.php" class="animated-underline font-medium text-gray-700 dark:text-gray-300 hover:text-indigo-600 dark:hover:text-indigo-400 transition">
                        <i class="fas fa-bookmark mr-2"></i>Saved Jobs
                    </a>
                </div>
                
                <!-- User Menu -->
                <div class="hidden lg:block">
                    <div class="relative group">
                        <button class="flex items-center space-x-3 bg-gray-100 dark:bg-gray-800 px-4 py-2 rounded-xl hover:bg-gray-200 dark:hover:bg-gray-700 transition">
                            <div class="w-10 h-10 bg-gradient-to-r from-indigo-600 to-purple-600 rounded-full flex items-center justify-center text-white font-bold">
                                <?php echo $initials; ?>
                            </div>
                            <div class="text-left">
                                <div class="font-semibold"><?php echo $fullName; ?></div>
                                <div class="text-xs text-gray-500 dark:text-gray-400">Job Seeker</div>
                            </div>
                            <i class="fas fa-chevron-down text-xs"></i>
                        </button>
                        
                        <div class="absolute right-0 mt-2 w-64 bg-white dark:bg-gray-800 rounded-xl shadow-2xl opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-300 transform origin-top-right scale-95 group-hover:scale-100">
                            <div class="py-2">
                                <div class="px-4 py-3 border-b dark:border-gray-700">
                                    <p class="text-sm text-gray-500">Signed in as</p>
                                    <p class="font-semibold truncate"><?php echo htmlspecialchars($seeker['email']); ?></p>
                                </div>
                                <a href="dashboard.php" class="block px-4 py-3 hover:bg-indigo-50 dark:hover:bg-gray-700 transition">
                                    <i class="fas fa-tachometer-alt mr-3 text-indigo-600"></i>Dashboard
                                </a>
                                <a href="profile.php" class="block px-4 py-3 bg-indigo-50 dark:bg-gray-700 text-indigo-600 dark:text-indigo-400 transition">
                                    <i class="fas fa-user-circle mr-3"></i>My Profile
                                </a>
                                <a href="applications.php" class="block px-4 py-3 hover:bg-indigo-50 dark:hover:bg-gray-700 transition">
                                    <i class="fas fa-paper-plane mr-3 text-indigo-600"></i>My Applications
                                    <?php if ($stats['total_applications'] > 0): ?>
                                    <span class="float-right bg-indigo-100 dark:bg-indigo-900 text-indigo-600 dark:text-indigo-400 px-2 py-1 rounded-full text-xs">
                                        <?php echo $stats['total_applications']; ?>
                                    </span>
                                    <?php endif; ?>
                                </a>
                                <a href="saved-jobs.php" class="block px-4 py-3 hover:bg-indigo-50 dark:hover:bg-gray-700 transition">
                                    <i class="fas fa-bookmark mr-3 text-indigo-600"></i>Saved Jobs
                                    <?php if ($stats['total_saved'] > 0): ?>
                                    <span class="float-right bg-indigo-100 dark:bg-indigo-900 text-indigo-600 dark:text-indigo-400 px-2 py-1 rounded-full text-xs">
                                        <?php echo $stats['total_saved']; ?>
                                    </span>
                                    <?php endif; ?>
                                </a>
                                <a href="settings.php" class="block px-4 py-3 hover:bg-indigo-50 dark:hover:bg-gray-700 transition">
                                    <i class="fas fa-cog mr-3 text-indigo-600"></i>Settings
                                </a>
                                <div class="border-t dark:border-gray-700 my-2"></div>
                                <a href="../logout.php" class="block px-4 py-3 text-red-600 hover:bg-red-50 dark:hover:bg-gray-700 transition">
                                    <i class="fas fa-sign-out-alt mr-3"></i>Logout
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Mobile Menu Button -->
                <button id="mobileMenuBtn" class="lg:hidden w-12 h-12 glass dark:glass-dark rounded-xl flex items-center justify-center">
                    <i class="fas fa-bars text-xl"></i>
                </button>
            </div>
        </div>
    </div>
    
    <!-- Mobile Menu -->
    <div id="mobileMenu" class="hidden lg:hidden container mx-auto px-4 mt-2">
        <div class="glass dark:glass-dark rounded-2xl p-6">
            <div class="flex flex-col space-y-4">
                <a href="../index.php" class="py-2 hover:text-indigo-600 transition"><i class="fas fa-home mr-2"></i>Home</a>
                <a href="jobs.php" class="py-2 hover:text-indigo-600 transition"><i class="fas fa-search mr-2"></i>Find Jobs</a>
                <a href="applications.php" class="py-2 hover:text-indigo-600 transition"><i class="fas fa-paper-plane mr-2"></i>Applications</a>
                <a href="saved-jobs.php" class="py-2 hover:text-indigo-600 transition"><i class="fas fa-bookmark mr-2"></i>Saved Jobs</a>
                <div class="border-t dark:border-gray-700 my-2"></div>
                <a href="dashboard.php" class="py-2 hover:text-indigo-600 transition"><i class="fas fa-tachometer-alt mr-2"></i>Dashboard</a>
                <a href="profile.php" class="py-2 text-indigo-600 font-semibold"><i class="fas fa-user-circle mr-2"></i>Profile</a>
                <a href="settings.php" class="py-2 hover:text-indigo-600 transition"><i class="fas fa-cog mr-2"></i>Settings</a>
                <a href="../logout.php" class="py-2 text-red-600 hover:text-red-700 transition"><i class="fas fa-sign-out-alt mr-2"></i>Logout</a>
            </div>
        </div>
    </div>
</nav>

<!-- Main Content -->
<main class="pt-32 pb-20">
    <div class="container mx-auto px-4">
        <!-- Success/Error Messages -->
        <?php if ($updateSuccess): ?>
        <div class="mb-8 bg-green-100 dark:bg-green-900 border-l-4 border-green-500 text-green-700 dark:text-green-300 p-4 rounded-r-xl slide-in" id="successMessage">
            <div class="flex items-center">
                <i class="fas fa-check-circle text-2xl mr-3"></i>
                <div>
                    <p class="font-semibold">Profile updated successfully!</p>
                    <p class="text-sm">Your changes have been saved.</p>
                </div>
                <button onclick="document.getElementById('successMessage').remove()" class="ml-auto">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if ($updateError): ?>
        <div class="mb-8 bg-red-100 dark:bg-red-900 border-l-4 border-red-500 text-red-700 dark:text-red-300 p-4 rounded-r-xl slide-in">
            <div class="flex items-center">
                <i class="fas fa-exclamation-circle text-2xl mr-3"></i>
                <div>
                    <p class="font-semibold">Error!</p>
                    <p class="text-sm"><?php echo $updateError; ?></p>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Profile Header -->
        <div class="profile-header mb-8" data-aos="fade-down">
            <div class="relative z-10 p-8 md:p-12">
                <div class="flex flex-col md:flex-row items-start md:items-center gap-8">
                    <!-- Profile Image -->
                    <div class="relative group">
                        <div class="w-32 h-32 md:w-40 md:h-40 rounded-3xl bg-gradient-to-r from-indigo-600 to-purple-600 p-1">
                            <div class="w-full h-full rounded-3xl bg-white dark:bg-gray-800 overflow-hidden">
                                <?php if ($seeker['profile_picture']): ?>
                                <img src="<?php echo htmlspecialchars($seeker['profile_picture']); ?>" 
                                     alt="<?php echo $fullName; ?>"
                                     class="w-full h-full object-cover">
                                <?php else: ?>
                                <div class="w-full h-full flex items-center justify-center text-5xl font-bold text-indigo-600">
                                    <?php echo $initials; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <button onclick="document.getElementById('profilePictureInput').click()" 
                                class="absolute -bottom-2 -right-2 w-10 h-10 bg-gradient-to-r from-indigo-600 to-purple-600 text-white rounded-full flex items-center justify-center opacity-0 group-hover:opacity-100 transition-all duration-300 shadow-lg hover:scale-110">
                            <i class="fas fa-camera"></i>
                        </button>
                        <input type="file" id="profilePictureInput" accept="image/*" class="hidden">
                    </div>
                    
                    <!-- Profile Info -->
                    <div class="flex-1 text-white">
                        <div class="flex flex-wrap items-center gap-4 mb-4">
                            <h1 class="text-4xl md:text-5xl font-bold"><?php echo $fullName; ?></h1>
                            <?php if ($seeker['user_verified']): ?>
                            <span class="bg-green-500 text-white px-4 py-2 rounded-full text-sm font-semibold flex items-center">
                                <i class="fas fa-check-circle mr-2"></i>Verified
                            </span>
                            <?php endif; ?>
                        </div>
                        
                        <?php if ($seeker['headline']): ?>
                        <p class="text-2xl text-indigo-100 mb-4"><?php echo htmlspecialchars($seeker['headline']); ?></p>
                        <?php endif; ?>
                        
                        <div class="flex flex-wrap gap-6">
                            <?php if ($seeker['location']): ?>
                            <div class="flex items-center">
                                <i class="fas fa-map-marker-alt mr-2 text-indigo-200"></i>
                                <span><?php echo htmlspecialchars($seeker['location']); ?></span>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($seeker['current_job_title']): ?>
                            <div class="flex items-center">
                                <i class="fas fa-briefcase mr-2 text-indigo-200"></i>
                                <span><?php echo htmlspecialchars($seeker['current_job_title']); ?>
                                <?php if ($seeker['current_company']): ?> at <?php echo htmlspecialchars($seeker['current_company']); ?><?php endif; ?>
                                </span>
                            </div>
                            <?php endif; ?>
                            
                            <div class="flex items-center">
                                <i class="fas fa-calendar-alt mr-2 text-indigo-200"></i>
                                <span>Member since <?php echo date('F Y', strtotime($seeker['user_created_at'])); ?></span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Action Buttons -->
                    <div class="flex flex-col gap-3">
                        <?php if ($seeker['resume_url']): ?>
                        <a href="<?php echo htmlspecialchars($seeker['resume_url']); ?>" 
                           target="_blank"
                           class="glass hover:bg-white/20 text-white px-6 py-3 rounded-xl transition-all duration-300 flex items-center justify-center gap-2">
                            <i class="fas fa-download"></i>
                            <span>Download CV</span>
                        </a>
                        <?php endif; ?>
                        <button onclick="shareProfile()" class="glass hover:bg-white/20 text-white px-6 py-3 rounded-xl transition-all duration-300 flex items-center justify-center gap-2">
                            <i class="fas fa-share-alt"></i>
                            <span>Share Profile</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Profile Completion Bar -->
        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6 mb-8" data-aos="fade-up">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h3 class="text-lg font-semibold">Profile Completion</h3>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Complete your profile to get better job matches</p>
                </div>
                <span class="text-3xl font-bold gradient-text"><?php echo $seeker['profile_completion_percent']; ?>%</span>
            </div>
            <div class="progress-bar">
                <div class="progress-fill" style="width: <?php echo $seeker['profile_completion_percent']; ?>%"></div>
            </div>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mt-6">
                <div class="text-center">
                    <div class="text-2xl font-bold text-indigo-600"><?php echo $stats['total_applications']; ?></div>
                    <div class="text-sm text-gray-500">Applications</div>
                </div>
                <div class="text-center">
                    <div class="text-2xl font-bold text-green-600"><?php echo $stats['total_interviews']; ?></div>
                    <div class="text-sm text-gray-500">Interviews</div>
                </div>
                <div class="text-center">
                    <div class="text-2xl font-bold text-purple-600"><?php echo $stats['high_match_count']; ?></div>
                    <div class="text-sm text-gray-500">High Matches</div>
                </div>
                <div class="text-center">
                    <div class="text-2xl font-bold text-blue-600"><?php echo $stats['total_saved_by']; ?></div>
                    <div class="text-sm text-gray-500">Saved by Employers</div>
                </div>
            </div>
        </div>

        <!-- Tab Navigation -->
        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl mb-8 overflow-hidden" data-aos="fade-up">
            <div class="flex overflow-x-auto">
                <button class="tab-button active" onclick="switchTab('overview')">
                    <i class="fas fa-user mr-2"></i>Overview
                </button>
                <button class="tab-button" onclick="switchTab('applications')">
                    <i class="fas fa-paper-plane mr-2"></i>Applications
                    <?php if ($stats['total_applications'] > 0): ?>
                    <span class="ml-2 bg-indigo-100 dark:bg-indigo-900 text-indigo-600 dark:text-indigo-400 px-2 py-1 rounded-full text-xs">
                        <?php echo $stats['total_applications']; ?>
                    </span>
                    <?php endif; ?>
                </button>
                <button class="tab-button" onclick="switchTab('saved')">
                    <i class="fas fa-bookmark mr-2"></i>Saved Jobs
                    <?php if ($stats['total_saved'] > 0): ?>
                    <span class="ml-2 bg-indigo-100 dark:bg-indigo-900 text-indigo-600 dark:text-indigo-400 px-2 py-1 rounded-full text-xs">
                        <?php echo $stats['total_saved']; ?>
                    </span>
                    <?php endif; ?>
                </button>
                <button class="tab-button" onclick="switchTab('insights')">
                    <i class="fas fa-chart-line mr-2"></i>Insights
                </button>
                <button class="tab-button" onclick="switchTab('employers')">
                    <i class="fas fa-building mr-2"></i>Interested Employers
                </button>
            </div>
        </div>

        <!-- Overview Tab -->
        <div id="tab-overview" class="tab-content">
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <!-- Left Column - Personal Info -->
                <div class="lg:col-span-2 space-y-8">
                    <!-- Bio -->
                    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-8 hover-card" data-aos="fade-up">
                        <h2 class="text-2xl font-bold mb-6 flex items-center">
                            <i class="fas fa-user-circle text-indigo-600 mr-3"></i>
                            About Me
                        </h2>
                        <?php if ($seeker['bio']): ?>
                        <p class="text-gray-700 dark:text-gray-300 leading-relaxed text-lg">
                            <?php echo nl2br(htmlspecialchars($seeker['bio'])); ?>
                        </p>
                        <?php else: ?>
                        <p class="text-gray-500 dark:text-gray-400 italic">
                            No bio added yet. Click the edit button to add information about yourself.
                        </p>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Skills -->
                    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-8 hover-card" data-aos="fade-up">
                        <h2 class="text-2xl font-bold mb-6 flex items-center">
                            <i class="fas fa-code text-indigo-600 mr-3"></i>
                            Skills & Expertise
                        </h2>
                        <?php if (!empty($skillsArray)): ?>
                        <div class="flex flex-wrap gap-3">
                            <?php foreach ($skillsArray as $skill): ?>
                            <span class="skill-tag">
                                <?php echo htmlspecialchars(trim($skill)); ?>
                                <i class="fas fa-check-circle text-xs"></i>
                            </span>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <p class="text-gray-500 dark:text-gray-400 italic">
                            No skills added yet. Add your skills to get better job matches.
                        </p>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Languages Section (NEW) -->
                    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-8 hover-card" data-aos="fade-up">
                        <div class="flex items-center justify-between mb-6">
                            <h2 class="text-2xl font-bold flex items-center">
                                <i class="fas fa-language text-indigo-600 mr-3"></i>
                                Languages
                            </h2>
                            <button onclick="openLanguageModal()" 
                                    class="px-4 py-2 bg-gradient-to-r from-indigo-600 to-purple-600 text-white rounded-xl text-sm font-semibold hover:shadow-lg hover:scale-105 transition-all duration-300">
                                <i class="fas fa-plus mr-2"></i>Add Language
                            </button>
                        </div>
                        
                        <?php if (empty($seekerLanguages)): ?>
                        <p class="text-gray-500 dark:text-gray-400 italic text-center py-8">
                            No languages added yet. Add languages to showcase your communication skills.
                        </p>
                        <?php else: ?>
                        <div class="space-y-4">
                            <?php foreach ($seekerLanguages as $lang): ?>
                            <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-700 rounded-xl group">
                                <div class="flex items-center gap-4">
                                    <div class="w-10 h-10 bg-gradient-to-r from-indigo-600 to-purple-600 rounded-lg flex items-center justify-center text-white font-bold">
                                        <?php echo strtoupper(substr($lang['language_code'], 0, 2)); ?>
                                    </div>
                                    <div>
                                        <h4 class="font-semibold"><?php echo htmlspecialchars($lang['language_name']); ?></h4>
                                        <div class="flex items-center gap-2 mt-1">
                                            <span class="text-sm px-3 py-1 rounded-full <?php echo getProficiencyBadge($lang['proficiency_level']); ?>">
                                                <?php echo getProficiencyLabel($lang['proficiency_level']); ?>
                                            </span>
                                            <!-- Proficiency Stars -->
                                            <div class="proficiency-stars">
                                                <?php
                                                $levelMap = [
                                                    'beginner' => 1,
                                                    'intermediate' => 2,
                                                    'advanced' => 3,
                                                    'fluent' => 4,
                                                    'native' => 5
                                                ];
                                                $level = $levelMap[$lang['proficiency_level']] ?? 2;
                                                for ($i = 1; $i <= 5; $i++):
                                                    if ($i <= $level):
                                                ?>
                                                <i class="fas fa-star text-yellow-400"></i>
                                                <?php else: ?>
                                                <i class="far fa-star text-gray-300"></i>
                                                <?php endif; endfor; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="flex gap-2 opacity-0 group-hover:opacity-100 transition-opacity">
                                    <button onclick="editLanguage(<?php echo $lang['language_id']; ?>, '<?php echo $lang['proficiency_level']; ?>')" 
                                            class="w-8 h-8 bg-blue-100 dark:bg-blue-900 text-blue-600 dark:text-blue-300 rounded-lg hover:bg-blue-200 dark:hover:bg-blue-800 transition">
                                        <i class="fas fa-pen text-sm"></i>
                                    </button>
                                    <button onclick="removeLanguage(<?php echo $lang['language_id']; ?>)" 
                                            class="w-8 h-8 bg-red-100 dark:bg-red-900 text-red-600 dark:text-red-300 rounded-lg hover:bg-red-200 dark:hover:bg-red-800 transition">
                                        <i class="fas fa-trash text-sm"></i>
                                    </button>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Experience & Education -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                        <!-- Experience -->
                        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-8 hover-card" data-aos="fade-up">
                            <h2 class="text-xl font-bold mb-4 flex items-center">
                                <i class="fas fa-briefcase text-indigo-600 mr-3"></i>
                                Experience
                            </h2>
                            <?php if ($seeker['years_experience'] > 0): ?>
                            <div class="text-center">
                                <div class="text-5xl font-bold gradient-text mb-2"><?php echo $seeker['years_experience']; ?></div>
                                <div class="text-gray-500">Years of Experience</div>
                            </div>
                            <?php if ($seeker['current_job_title']): ?>
                            <div class="mt-4 p-4 bg-gray-50 dark:bg-gray-700 rounded-xl">
                                <p class="font-semibold">Current Position</p>
                                <p><?php echo htmlspecialchars($seeker['current_job_title']); ?></p>
                                <?php if ($seeker['current_company']): ?>
                                <p class="text-sm text-gray-500">at <?php echo htmlspecialchars($seeker['current_company']); ?></p>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                            <?php else: ?>
                            <p class="text-gray-500 dark:text-gray-400 italic">
                                No experience information added yet.
                            </p>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Education -->
                        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-8 hover-card" data-aos="fade-up">
                            <h2 class="text-xl font-bold mb-4 flex items-center">
                                <i class="fas fa-graduation-cap text-indigo-600 mr-3"></i>
                                Education
                            </h2>
                            <?php if ($seeker['highest_education']): ?>
                            <div class="text-center">
                                <div class="text-lg font-semibold mb-2">Highest Degree</div>
                                <div class="text-2xl font-bold gradient-text"><?php echo htmlspecialchars($seeker['highest_education']); ?></div>
                            </div>
                            <?php else: ?>
                            <p class="text-gray-500 dark:text-gray-400 italic">
                                No education information added yet.
                            </p>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Expected Salary -->
                    <?php if ($seeker['expected_salary_min'] || $seeker['expected_salary_max']): ?>
                    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-8 hover-card" data-aos="fade-up">
                        <h2 class="text-2xl font-bold mb-6 flex items-center">
                            <i class="fas fa-dollar-sign text-indigo-600 mr-3"></i>
                            Expected Salary
                        </h2>
                        <div class="flex items-center justify-center space-x-4">
                            <?php if ($seeker['expected_salary_min']): ?>
                            <div class="text-center">
                                <div class="text-sm text-gray-500">Minimum</div>
                                <div class="text-3xl font-bold text-indigo-600">
                                    $<?php echo number_format($seeker['expected_salary_min']); ?>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($seeker['expected_salary_min'] && $seeker['expected_salary_max']): ?>
                            <i class="fas fa-arrow-right text-2xl text-gray-400"></i>
                            <?php endif; ?>
                            
                            <?php if ($seeker['expected_salary_max']): ?>
                            <div class="text-center">
                                <div class="text-sm text-gray-500">Maximum</div>
                                <div class="text-3xl font-bold text-purple-600">
                                    $<?php echo number_format($seeker['expected_salary_max']); ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Right Column - Sidebar -->
                <div class="space-y-8">
                    <!-- Contact Info -->
                    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-8 hover-card" data-aos="fade-up">
                        <h2 class="text-xl font-bold mb-6 flex items-center">
                            <i class="fas fa-address-card text-indigo-600 mr-3"></i>
                            Contact Information
                        </h2>
                        
                        <div class="space-y-4">
                            <?php if ($seeker['email']): ?>
                            <div class="flex items-center p-3 bg-gray-50 dark:bg-gray-700 rounded-xl">
                                <i class="fas fa-envelope text-indigo-600 w-8"></i>
                                <div class="flex-1">
                                    <div class="text-sm text-gray-500">Email</div>
                                    <div class="font-medium"><?php echo htmlspecialchars($seeker['email']); ?></div>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($seeker['phone']): ?>
                            <div class="flex items-center p-3 bg-gray-50 dark:bg-gray-700 rounded-xl">
                                <i class="fas fa-phone text-indigo-600 w-8"></i>
                                <div class="flex-1">
                                    <div class="text-sm text-gray-500">Phone</div>
                                    <div class="font-medium"><?php echo htmlspecialchars($seeker['phone']); ?></div>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($seeker['whatsapp_number']): ?>
                            <div class="flex items-center p-3 bg-gray-50 dark:bg-gray-700 rounded-xl">
                                <i class="fab fa-whatsapp text-green-600 w-8"></i>
                                <div class="flex-1">
                                    <div class="text-sm text-gray-500">WhatsApp</div>
                                    <div class="font-medium"><?php echo htmlspecialchars($seeker['whatsapp_number']); ?></div>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($seeker['location']): ?>
                            <div class="flex items-center p-3 bg-gray-50 dark:bg-gray-700 rounded-xl">
                                <i class="fas fa-map-marker-alt text-indigo-600 w-8"></i>
                                <div class="flex-1">
                                    <div class="text-sm text-gray-500">Location</div>
                                    <div class="font-medium"><?php echo htmlspecialchars($seeker['location']); ?></div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Job Preferences -->
                    <?php if ($preferences): ?>
                    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-8 hover-card" data-aos="fade-up">
                        <h2 class="text-xl font-bold mb-6 flex items-center">
                            <i class="fas fa-sliders-h text-indigo-600 mr-3"></i>
                            Job Preferences
                        </h2>
                        
                        <?php 
                        $preferredCategories = !empty($preferences['preferred_categories']) ? json_decode($preferences['preferred_categories'], true) : [];
                        $preferredJobTypes = !empty($preferences['preferred_job_types']) ? json_decode($preferences['preferred_job_types'], true) : [];
                        $preferredLocations = !empty($preferences['preferred_locations']) ? json_decode($preferences['preferred_locations'], true) : [];
                        ?>
                        
                        <?php if (!empty($preferredCategories)): ?>
                        <div class="mb-4">
                            <div class="text-sm text-gray-500 mb-2">Preferred Categories</div>
                            <div class="flex flex-wrap gap-2">
                                <?php foreach ($preferredCategories as $cat): ?>
                                <span class="bg-indigo-100 dark:bg-indigo-900 text-indigo-600 dark:text-indigo-400 px-3 py-1 rounded-full text-sm">
                                    <?php echo htmlspecialchars($cat); ?>
                                </span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($preferredJobTypes)): ?>
                        <div class="mb-4">
                            <div class="text-sm text-gray-500 mb-2">Preferred Job Types</div>
                            <div class="flex flex-wrap gap-2">
                                <?php foreach ($preferredJobTypes as $type): ?>
                                <span class="bg-green-100 dark:bg-green-900 text-green-600 dark:text-green-400 px-3 py-1 rounded-full text-sm">
                                    <?php echo htmlspecialchars($type); ?>
                                </span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($preferredLocations)): ?>
                        <div>
                            <div class="text-sm text-gray-500 mb-2">Preferred Locations</div>
                            <div class="flex flex-wrap gap-2">
                                <?php foreach ($preferredLocations as $loc): ?>
                                <span class="bg-purple-100 dark:bg-purple-900 text-purple-600 dark:text-purple-400 px-3 py-1 rounded-full text-sm">
                                    <i class="fas fa-map-marker-alt mr-1"></i>
                                    <?php echo htmlspecialchars($loc); ?>
                                </span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Resume/CV Section (Enhanced) -->
                    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-8 hover-card" data-aos="fade-up">
                        <h2 class="text-xl font-bold mb-6 flex items-center">
                            <i class="fas fa-file-pdf text-indigo-600 mr-3"></i>
                            Resume/CV
                        </h2>
                        
                        <?php if ($seeker['resume_url']): ?>
                        <div class="mb-4 p-4 bg-green-50 dark:bg-green-900/20 rounded-xl border border-green-200 dark:border-green-800">
                            <div class="flex items-center gap-4">
                                <div class="w-12 h-12 bg-gradient-to-r from-green-500 to-emerald-600 rounded-xl flex items-center justify-center text-white text-2xl">
                                    <i class="fas fa-file-pdf"></i>
                                </div>
                                <div class="flex-1">
                                    <div class="font-semibold">Current Resume</div>
                                    <div class="text-sm text-gray-500">Uploaded: Recently</div>
                                </div>
                                <form method="POST" class="inline" onsubmit="return confirm('Are you sure you want to delete your resume?');">
                                    <input type="hidden" name="action" value="delete_cv">
                                    <button type="submit" class="text-red-500 hover:text-red-700">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                        
                        <a href="<?php echo htmlspecialchars($seeker['resume_url']); ?>" 
                           target="_blank"
                           class="flex items-center p-4 bg-gradient-to-r from-indigo-50 to-purple-50 dark:from-gray-700 dark:to-gray-600 rounded-xl hover:shadow-lg transition-all duration-300 group mb-4">
                            <div class="w-12 h-12 bg-gradient-to-r from-indigo-600 to-purple-600 rounded-xl flex items-center justify-center text-white text-2xl group-hover:scale-110 transition-transform">
                                <i class="fas fa-download"></i>
                            </div>
                            <div class="flex-1 ml-4">
                                <div class="font-semibold">Download Resume</div>
                                <div class="text-sm text-gray-500">Click to view or download</div>
                            </div>
                            <i class="fas fa-download text-indigo-600 group-hover:translate-y-1 transition-transform"></i>
                        </a>
                        <?php endif; ?>
                        
                        <!-- CV Upload Form -->
                        <form method="POST" enctype="multipart/form-data" class="mt-4">
                            <input type="hidden" name="action" value="upload_cv">
                            
                            <div class="cv-upload-area" id="cvUploadArea">
                                <i class="fas fa-cloud-upload-alt text-4xl text-indigo-600 mb-3"></i>
                                <p class="font-semibold mb-2">Upload your Resume/CV</p>
                                <p class="text-sm text-gray-500 mb-3">PDF or Word (Max 5MB)</p>
                                <input type="file" name="cv_file" id="cvFile" accept=".pdf,.doc,.docx" class="hidden" onchange="this.form.submit()">
                                <button type="button" onclick="document.getElementById('cvFile').click()" 
                                        class="px-6 py-2 bg-gradient-to-r from-indigo-600 to-purple-600 text-white rounded-xl text-sm font-semibold hover:shadow-lg transition-all duration-300">
                                    Select File
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Applications Tab -->
        <div id="tab-applications" class="tab-content hidden">
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-8" data-aos="fade-up">
                <div class="flex items-center justify-between mb-8">
                    <h2 class="text-2xl font-bold flex items-center">
                        <i class="fas fa-paper-plane text-indigo-600 mr-3"></i>
                        My Applications
                    </h2>
                    <a href="applications.php" class="text-indigo-600 hover:underline flex items-center">
                        View All
                        <i class="fas fa-arrow-right ml-2"></i>
                    </a>
                </div>
                
                <?php if (empty($recentApplications)): ?>
                <div class="text-center py-12">
                    <div class="w-24 h-24 bg-gray-100 dark:bg-gray-700 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-paper-plane text-3xl text-gray-400"></i>
                    </div>
                    <h3 class="text-xl font-semibold mb-2">No Applications Yet</h3>
                    <p class="text-gray-500 dark:text-gray-400 mb-6">Start applying to jobs that match your skills</p>
                    <a href="jobs.php" 
                       class="inline-flex items-center px-6 py-3 bg-gradient-to-r from-indigo-600 to-purple-600 text-white rounded-xl font-semibold hover:shadow-lg hover:scale-105 transition-all duration-300">
                        <i class="fas fa-search mr-2"></i>
                        Browse Jobs
                    </a>
                </div>
                <?php else: ?>
                <div class="space-y-4">
                    <?php foreach ($recentApplications as $app): ?>
                    <div class="group bg-gray-50 dark:bg-gray-700 rounded-xl p-6 hover:shadow-xl transition-all duration-300 border border-transparent hover:border-indigo-200 dark:hover:border-indigo-800">
                        <div class="flex flex-wrap items-start gap-4">
                            <img src="<?php echo htmlspecialchars($app['company_logo'] ?? 'https://ui-avatars.com/api/?name='.urlencode($app['company_name']).'&background=random'); ?>" 
                                 alt="<?php echo htmlspecialchars($app['company_name']); ?>"
                                 class="w-16 h-16 rounded-xl object-cover">
                            
                            <div class="flex-1">
                                <div class="flex flex-wrap items-start justify-between gap-4">
                                    <div>
                                        <h3 class="text-xl font-bold mb-2">
                                            <a href="job-details.php?slug=<?php echo htmlspecialchars($app['job_slug']); ?>" class="hover:text-indigo-600 transition">
                                                <?php echo htmlspecialchars($app['job_title']); ?>
                                            </a>
                                        </h3>
                                        <p class="text-gray-600 dark:text-gray-400 mb-2">
                                            <?php echo htmlspecialchars($app['company_name']); ?>
                                        </p>
                                        <div class="flex items-center gap-4 text-sm text-gray-500">
                                            <span><i class="fas fa-map-marker-alt mr-1 text-indigo-500"></i>
                                                <?php echo ucfirst($app['location_type']); ?>
                                                <?php if ($app['city']): ?> • <?php echo htmlspecialchars($app['city']); ?><?php endif; ?>
                                            </span>
                                            <span><i class="far fa-clock mr-1 text-indigo-500"></i>
                                                Applied <?php echo date('M d, Y', strtotime($app['applied_at'])); ?>
                                            </span>
                                        </div>
                                    </div>
                                    
                                    <div class="text-right">
                                        <?php
                                        $statusColors = [
                                            'applied' => 'bg-blue-100 text-blue-600 dark:bg-blue-900 dark:text-blue-400',
                                            'reviewed' => 'bg-purple-100 text-purple-600 dark:bg-purple-900 dark:text-purple-400',
                                            'shortlisted' => 'bg-yellow-100 text-yellow-600 dark:bg-yellow-900 dark:text-yellow-400',
                                            'interview' => 'bg-green-100 text-green-600 dark:bg-green-900 dark:text-green-400',
                                            'rejected' => 'bg-red-100 text-red-600 dark:bg-red-900 dark:text-red-400',
                                            'hired' => 'bg-emerald-100 text-emerald-600 dark:bg-emerald-900 dark:text-emerald-400'
                                        ];
                                        $statusClass = $statusColors[$app['application_status']] ?? 'bg-gray-100 text-gray-600';
                                        ?>
                                        <span class="inline-block px-4 py-2 rounded-full text-sm font-semibold <?php echo $statusClass; ?>">
                                            <?php echo ucfirst($app['application_status']); ?>
                                        </span>
                                        
                                        <?php if ($app['match_score'] > 0): ?>
                                        <div class="mt-2 text-sm">
                                            <span class="font-semibold">Match Score:</span>
                                            <span class="ml-1 <?php echo $app['match_score'] >= 80 ? 'text-green-600' : ($app['match_score'] >= 60 ? 'text-yellow-600' : 'text-gray-600'); ?>">
                                                <?php echo $app['match_score']; ?>%
                                            </span>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <?php if ($app['cover_letter']): ?>
                                <div class="mt-4 p-4 bg-white dark:bg-gray-600 rounded-lg">
                                    <p class="text-sm text-gray-600 dark:text-gray-300 line-clamp-2">
                                        <span class="font-semibold">Cover Letter:</span>
                                        <?php echo htmlspecialchars(substr($app['cover_letter'], 0, 200)) . '...'; ?>
                                    </p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Saved Jobs Tab -->
        <div id="tab-saved" class="tab-content hidden">
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-8" data-aos="fade-up">
                <div class="flex items-center justify-between mb-8">
                    <h2 class="text-2xl font-bold flex items-center">
                        <i class="fas fa-bookmark text-indigo-600 mr-3"></i>
                        Saved Jobs
                    </h2>
                    <a href="saved-jobs.php" class="text-indigo-600 hover:underline flex items-center">
                        Manage Saved Jobs
                        <i class="fas fa-arrow-right ml-2"></i>
                    </a>
                </div>
                
                <?php if (empty($savedJobs)): ?>
                <div class="text-center py-12">
                    <div class="w-24 h-24 bg-gray-100 dark:bg-gray-700 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-bookmark text-3xl text-gray-400"></i>
                    </div>
                    <h3 class="text-xl font-semibold mb-2">No Saved Jobs</h3>
                    <p class="text-gray-500 dark:text-gray-400 mb-6">Save jobs you're interested in to apply later</p>
                    <a href="jobs.php" 
                       class="inline-flex items-center px-6 py-3 bg-gradient-to-r from-indigo-600 to-purple-600 text-white rounded-xl font-semibold hover:shadow-lg hover:scale-105 transition-all duration-300">
                        <i class="fas fa-search mr-2"></i>
                        Browse Jobs
                    </a>
                </div>
                <?php else: ?>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <?php foreach ($savedJobs as $job): ?>
                    <div class="group bg-gray-50 dark:bg-gray-700 rounded-xl p-6 hover:shadow-xl transition-all duration-300 border border-transparent hover:border-indigo-200 dark:hover:border-indigo-800">
                        <div class="flex items-start gap-4">
                            <img src="<?php echo htmlspecialchars($job['company_logo'] ?? 'https://ui-avatars.com/api/?name='.urlencode($job['company_name']).'&background=random'); ?>" 
                                 alt="<?php echo htmlspecialchars($job['company_name']); ?>"
                                 class="w-16 h-16 rounded-xl object-cover">
                            
                            <div class="flex-1">
                                <h3 class="text-lg font-bold mb-2">
                                    <a href="job-details.php?slug=<?php echo htmlspecialchars($job['job_slug']); ?>" class="hover:text-indigo-600 transition">
                                        <?php echo htmlspecialchars($job['job_title']); ?>
                                    </a>
                                </h3>
                                <p class="text-sm text-gray-600 dark:text-gray-400 mb-3">
                                    <?php echo htmlspecialchars($job['company_name']); ?>
                                </p>
                                <div class="flex flex-wrap gap-3 text-sm text-gray-500">
                                    <span><i class="fas fa-map-marker-alt mr-1 text-indigo-500"></i>
                                        <?php echo ucfirst($job['location_type']); ?>
                                    </span>
                                    <?php if ($job['salary_min']): ?>
                                    <span><i class="fas fa-dollar-sign mr-1 text-indigo-500"></i>
                                        $<?php echo number_format($job['salary_min']); ?>+
                                    </span>
                                    <?php endif; ?>
                                </div>
                                <div class="mt-4 flex items-center justify-between">
                                    <span class="text-xs text-gray-500">
                                        Saved <?php echo date('M d, Y', strtotime($job['created_at'])); ?>
                                    </span>
                                    <a href="job-details.php?slug=<?php echo htmlspecialchars($job['job_slug']); ?>" 
                                       class="text-indigo-600 hover:underline text-sm font-semibold">
                                        Apply Now <i class="fas fa-arrow-right ml-1"></i>
                                    </a>
                                </div>
                            </div>
                        </div>
                        <?php if ($job['notes']): ?>
                        <div class="mt-4 p-3 bg-white dark:bg-gray-600 rounded-lg text-sm">
                            <i class="fas fa-sticky-note text-indigo-500 mr-2"></i>
                            <?php echo htmlspecialchars($job['notes']); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Insights Tab -->
        <div id="tab-insights" class="tab-content hidden">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                <!-- Application Stats Chart -->
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-8" data-aos="fade-up">
                    <h2 class="text-xl font-bold mb-6 flex items-center">
                        <i class="fas fa-chart-pie text-indigo-600 mr-3"></i>
                        Application Status
                    </h2>
                    <canvas id="applicationChart" class="w-full h-64"></canvas>
                </div>
                
                <!-- Match Score Distribution -->
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-8" data-aos="fade-up">
                    <h2 class="text-xl font-bold mb-6 flex items-center">
                        <i class="fas fa-chart-bar text-indigo-600 mr-3"></i>
                        Match Score Distribution
                    </h2>
                    <canvas id="matchChart" class="w-full h-64"></canvas>
                </div>
                
                <!-- Recommended Jobs -->
                <div class="lg:col-span-2 bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-8" data-aos="fade-up">
                    <div class="flex items-center justify-between mb-6">
                        <h2 class="text-xl font-bold flex items-center">
                            <i class="fas fa-star text-indigo-600 mr-3"></i>
                            Recommended Jobs For You
                        </h2>
                        <a href="jobs.php?recommended=1" class="text-indigo-600 hover:underline flex items-center text-sm">
                            View All Recommendations
                            <i class="fas fa-arrow-right ml-2"></i>
                        </a>
                    </div>
                    
                    <?php if (empty($recommendedJobs)): ?>
                    <p class="text-gray-500 dark:text-gray-400 text-center py-8">
                        No job recommendations available at the moment.
                    </p>
                    <?php else: ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                        <?php foreach ($recommendedJobs as $job): ?>
                        <div class="group bg-gray-50 dark:bg-gray-700 rounded-xl p-6 hover:shadow-xl transition-all duration-300">
                            <div class="flex items-start gap-3 mb-4">
                                <img src="<?php echo htmlspecialchars($job['company_logo'] ?? 'https://ui-avatars.com/api/?name='.urlencode($job['company_name']).'&background=random'); ?>" 
                                     alt="<?php echo htmlspecialchars($job['company_name']); ?>"
                                     class="w-12 h-12 rounded-xl object-cover">
                                <div class="flex-1">
                                    <h3 class="font-semibold hover:text-indigo-600 transition">
                                        <a href="job-details.php?slug=<?php echo htmlspecialchars($job['slug']); ?>">
                                            <?php echo htmlspecialchars($job['title']); ?>
                                        </a>
                                    </h3>
                                    <p class="text-sm text-gray-600 dark:text-gray-400">
                                        <?php echo htmlspecialchars($job['company_name']); ?>
                                    </p>
                                </div>
                                <?php if ($job['is_urgent']): ?>
                                <span class="bg-red-500 text-white text-xs px-2 py-1 rounded-full">Urgent</span>
                                <?php endif; ?>
                            </div>
                            
                            <div class="flex items-center justify-between text-sm mb-3">
                                <span class="text-gray-500">
                                    <i class="fas fa-map-marker-alt mr-1 text-indigo-500"></i>
                                    <?php echo ucfirst($job['location_type']); ?>
                                </span>
                                <?php if ($job['salary_min']): ?>
                                <span class="text-gray-500">
                                    <i class="fas fa-dollar-sign mr-1 text-indigo-500"></i>
                                    $<?php echo number_format($job['salary_min']); ?>+
                                </span>
                                <?php endif; ?>
                            </div>
                            
                            <a href="job-details.php?slug=<?php echo htmlspecialchars($job['slug']); ?>" 
                               class="block w-full text-center bg-gradient-to-r from-indigo-600 to-purple-600 text-white py-2 rounded-lg text-sm font-semibold hover:shadow-lg hover:scale-105 transition-all duration-300">
                                View Job
                            </a>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Interested Employers Tab -->
        <div id="tab-employers" class="tab-content hidden">
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-8" data-aos="fade-up">
                <h2 class="text-2xl font-bold mb-8 flex items-center">
                    <i class="fas fa-building text-indigo-600 mr-3"></i>
                    Employers Interested in Your Profile
                </h2>
                
                <?php if (empty($savedByEmployers)): ?>
                <div class="text-center py-12">
                    <div class="w-24 h-24 bg-gray-100 dark:bg-gray-700 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-building text-3xl text-gray-400"></i>
                    </div>
                    <h3 class="text-xl font-semibold mb-2">No Interest Yet</h3>
                    <p class="text-gray-500 dark:text-gray-400 mb-6">Complete your profile to get noticed by employers</p>
                    <button onclick="openEditModal()" 
                            class="inline-flex items-center px-6 py-3 bg-gradient-to-r from-indigo-600 to-purple-600 text-white rounded-xl font-semibold hover:shadow-lg hover:scale-105 transition-all duration-300">
                        <i class="fas fa-pen mr-2"></i>
                        Complete Profile
                    </button>
                </div>
                <?php else: ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <?php foreach ($savedByEmployers as $emp): ?>
                    <div class="group bg-gray-50 dark:bg-gray-700 rounded-xl p-6 hover:shadow-xl transition-all duration-300">
                        <div class="text-center mb-4">
                            <img src="<?php echo htmlspecialchars($emp['company_logo'] ?? 'https://ui-avatars.com/api/?name='.urlencode($emp['company_name']).'&background=4f46e5&color=fff'); ?>" 
                                 alt="<?php echo htmlspecialchars($emp['company_name']); ?>"
                                 class="w-24 h-24 rounded-2xl mx-auto object-cover mb-4 group-hover:scale-110 transition-transform duration-300">
                            <h3 class="text-xl font-bold mb-1"><?php echo htmlspecialchars($emp['company_name']); ?></h3>
                            <?php if ($emp['industry']): ?>
                            <p class="text-sm text-gray-600 dark:text-gray-400"><?php echo htmlspecialchars($emp['industry']); ?></p>
                            <?php endif; ?>
                        </div>
                        
                        <div class="space-y-2 text-sm text-gray-500 mb-4">
                            <?php if ($emp['employer_city'] || $emp['employer_country']): ?>
                            <p><i class="fas fa-map-marker-alt mr-2 text-indigo-500"></i>
                                <?php echo $emp['employer_city'] ? htmlspecialchars($emp['employer_city'] . ', ') : ''; ?>
                                <?php echo htmlspecialchars($emp['employer_country'] ?? 'Global'); ?>
                            </p>
                            <?php endif; ?>
                            
                            <p><i class="far fa-clock mr-2 text-indigo-500"></i>
                                Saved you on <?php echo date('M d, Y', strtotime($emp['created_at'])); ?>
                            </p>
                        </div>
                        
                        <?php if ($emp['notes']): ?>
                        <div class="mb-4 p-3 bg-white dark:bg-gray-600 rounded-lg text-sm">
                            <i class="fas fa-sticky-note text-indigo-500 mr-2"></i>
                            <?php echo htmlspecialchars($emp['notes']); ?>
                        </div>
                        <?php endif; ?>
                        
                        <div class="flex gap-2">
                            <a href="../employer/profile.php?id=<?php echo $emp['employer_id']; ?>" 
                               class="flex-1 text-center bg-gradient-to-r from-indigo-600 to-purple-600 text-white py-2 rounded-lg text-sm font-semibold hover:shadow-lg hover:scale-105 transition-all duration-300">
                                View Company
                            </a>
                            <button onclick="showInterest(<?php echo $emp['employer_id']; ?>)" 
                                    class="w-10 h-10 border-2 border-indigo-600 text-indigo-600 rounded-lg flex items-center justify-center hover:bg-indigo-50 dark:hover:bg-gray-600 transition-all duration-300">
                                <i class="fas fa-heart"></i>
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>

<!-- Edit Profile Modal -->
<div id="editProfileModal" class="modal">
    <div class="modal-content dark:bg-gray-800">
        <div class="flex items-center justify-between mb-6">
            <h2 class="text-2xl font-bold">Edit Profile</h2>
            <button onclick="closeEditModal()" class="w-10 h-10 rounded-full bg-gray-100 dark:bg-gray-700 flex items-center justify-center hover:bg-gray-200 dark:hover:bg-gray-600 transition">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <form method="POST" action="" enctype="multipart/form-data" class="space-y-6">
            <input type="hidden" name="action" value="update_profile">
            
            <!-- Personal Information -->
            <div>
                <h3 class="text-lg font-semibold mb-4">Personal Information</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium mb-2">First Name *</label>
                        <input type="text" name="first_name" required
                               value="<?php echo htmlspecialchars($seeker['first_name']); ?>"
                               class="w-full px-4 py-3 rounded-xl border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 focus:ring-2 focus:ring-indigo-500 transition">
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-2">Last Name *</label>
                        <input type="text" name="last_name" required
                               value="<?php echo htmlspecialchars($seeker['last_name']); ?>"
                               class="w-full px-4 py-3 rounded-xl border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 focus:ring-2 focus:ring-indigo-500 transition">
                    </div>
                </div>
            </div>
            
            <!-- Contact Information -->
            <div>
                <h3 class="text-lg font-semibold mb-4">Contact Information</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium mb-2">Phone</label>
                        <input type="tel" name="phone"
                               value="<?php echo htmlspecialchars($seeker['phone'] ?? ''); ?>"
                               class="w-full px-4 py-3 rounded-xl border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 focus:ring-2 focus:ring-indigo-500 transition">
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-2">WhatsApp Number</label>
                        <input type="tel" name="whatsapp_number"
                               value="<?php echo htmlspecialchars($seeker['whatsapp_number'] ?? ''); ?>"
                               class="w-full px-4 py-3 rounded-xl border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 focus:ring-2 focus:ring-indigo-500 transition">
                    </div>
                </div>
            </div>
            
            <!-- Professional Information -->
            <div>
                <h3 class="text-lg font-semibold mb-4">Professional Information</h3>
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium mb-2">Professional Headline</label>
                        <input type="text" name="headline"
                               value="<?php echo htmlspecialchars($seeker['headline'] ?? ''); ?>"
                               placeholder="e.g., Senior Software Engineer specializing in AI"
                               class="w-full px-4 py-3 rounded-xl border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 focus:ring-2 focus:ring-indigo-500 transition">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium mb-2">Bio / About Me</label>
                        <textarea name="bio" rows="5"
                                  class="w-full px-4 py-3 rounded-xl border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 focus:ring-2 focus:ring-indigo-500 transition"><?php echo htmlspecialchars($seeker['bio'] ?? ''); ?></textarea>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium mb-2">Location</label>
                        <input type="text" name="location"
                               value="<?php echo htmlspecialchars($seeker['location'] ?? ''); ?>"
                               placeholder="e.g., Beijing, China"
                               class="w-full px-4 py-3 rounded-xl border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 focus:ring-2 focus:ring-indigo-500 transition">
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium mb-2">Current Job Title</label>
                            <input type="text" name="current_job_title"
                                   value="<?php echo htmlspecialchars($seeker['current_job_title'] ?? ''); ?>"
                                   class="w-full px-4 py-3 rounded-xl border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 focus:ring-2 focus:ring-indigo-500 transition">
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-2">Current Company</label>
                            <input type="text" name="current_company"
                                   value="<?php echo htmlspecialchars($seeker['current_company'] ?? ''); ?>"
                                   class="w-full px-4 py-3 rounded-xl border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 focus:ring-2 focus:ring-indigo-500 transition">
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium mb-2">Years of Experience</label>
                            <input type="number" name="years_experience" min="0" max="50"
                                   value="<?php echo $seeker['years_experience'] ?? 0; ?>"
                                   class="w-full px-4 py-3 rounded-xl border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 focus:ring-2 focus:ring-indigo-500 transition">
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-2">Highest Education</label>
                            <select name="highest_education"
                                    class="w-full px-4 py-3 rounded-xl border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 focus:ring-2 focus:ring-indigo-500 transition">
                                <option value="">Select Education Level</option>
                                <option value="High School" <?php echo ($seeker['highest_education'] ?? '') == 'High School' ? 'selected' : ''; ?>>High School</option>
                                <option value="Associate Degree" <?php echo ($seeker['highest_education'] ?? '') == 'Associate Degree' ? 'selected' : ''; ?>>Associate Degree</option>
                                <option value="Bachelor's Degree" <?php echo ($seeker['highest_education'] ?? '') == "Bachelor's Degree" ? 'selected' : ''; ?>>Bachelor's Degree</option>
                                <option value="Master's Degree" <?php echo ($seeker['highest_education'] ?? '') == "Master's Degree" ? 'selected' : ''; ?>>Master's Degree</option>
                                <option value="PhD" <?php echo ($seeker['highest_education'] ?? '') == 'PhD' ? 'selected' : ''; ?>>PhD</option>
                                <option value="MBA" <?php echo ($seeker['highest_education'] ?? '') == 'MBA' ? 'selected' : ''; ?>>MBA</option>
                            </select>
                        </div>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium mb-2">Skills (comma separated)</label>
                        <textarea name="skills" rows="3"
                                  placeholder="e.g., Python, JavaScript, Project Management, Communication"
                                  class="w-full px-4 py-3 rounded-xl border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 focus:ring-2 focus:ring-indigo-500 transition"><?php echo htmlspecialchars($seeker['skills'] ?? ''); ?></textarea>
                        <p class="text-xs text-gray-500 mt-1">Separate skills with commas</p>
                    </div>
                </div>
            </div>
            
            <!-- Salary Expectations -->
            <div>
                <h3 class="text-lg font-semibold mb-4">Salary Expectations (USD)</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium mb-2">Minimum</label>
                        <input type="number" name="expected_salary_min" min="0" step="1000"
                               value="<?php echo $seeker['expected_salary_min'] ?? ''; ?>"
                               class="w-full px-4 py-3 rounded-xl border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 focus:ring-2 focus:ring-indigo-500 transition">
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-2">Maximum</label>
                        <input type="number" name="expected_salary_max" min="0" step="1000"
                               value="<?php echo $seeker['expected_salary_max'] ?? ''; ?>"
                               class="w-full px-4 py-3 rounded-xl border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 focus:ring-2 focus:ring-indigo-500 transition">
                    </div>
                </div>
            </div>
            
            <!-- Form Actions -->
            <div class="flex gap-4 pt-4">
                <button type="submit" 
                        class="flex-1 bg-gradient-to-r from-indigo-600 to-purple-600 text-white px-6 py-3 rounded-xl font-semibold hover:shadow-lg hover:scale-105 transition-all duration-300">
                    Save Changes
                </button>
                <button type="button" onclick="closeEditModal()"
                        class="flex-1 border-2 border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 px-6 py-3 rounded-xl font-semibold hover:bg-gray-100 dark:hover:bg-gray-700 transition-all duration-300">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Add Language Modal -->
<div id="addLanguageModal" class="modal">
    <div class="modal-content dark:bg-gray-800">
        <div class="flex items-center justify-between mb-6">
            <h2 class="text-2xl font-bold">Add Language</h2>
            <button onclick="closeLanguageModal()" class="w-10 h-10 rounded-full bg-gray-100 dark:bg-gray-700 flex items-center justify-center hover:bg-gray-200 dark:hover:bg-gray-600 transition">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <form method="POST" action="" class="space-y-6">
            <input type="hidden" name="action" value="add_language">
            
            <div>
                <label class="block text-sm font-medium mb-2">Select Language *</label>
                <select name="language_id" required
                        class="w-full px-4 py-3 rounded-xl border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 focus:ring-2 focus:ring-indigo-500 transition">
                    <option value="">Choose a language</option>
                    <?php foreach ($availableLanguages as $lang): ?>
                    <option value="<?php echo $lang['id']; ?>"><?php echo htmlspecialchars($lang['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div>
                <label class="block text-sm font-medium mb-2">Proficiency Level *</label>
                <select name="proficiency_level" required
                        class="w-full px-4 py-3 rounded-xl border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 focus:ring-2 focus:ring-indigo-500 transition">
                    <option value="beginner">Beginner</option>
                    <option value="intermediate" selected>Intermediate</option>
                    <option value="advanced">Advanced</option>
                    <option value="fluent">Fluent</option>
                    <option value="native">Native</option>
                </select>
            </div>
            
            <div class="flex gap-4 pt-4">
                <button type="submit" 
                        class="flex-1 bg-gradient-to-r from-indigo-600 to-purple-600 text-white px-6 py-3 rounded-xl font-semibold hover:shadow-lg hover:scale-105 transition-all duration-300">
                    Add Language
                </button>
                <button type="button" onclick="closeLanguageModal()"
                        class="flex-1 border-2 border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 px-6 py-3 rounded-xl font-semibold hover:bg-gray-100 dark:hover:bg-gray-700 transition-all duration-300">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Language Modal -->
<div id="editLanguageModal" class="modal">
    <div class="modal-content dark:bg-gray-800">
        <div class="flex items-center justify-between mb-6">
            <h2 class="text-2xl font-bold">Edit Language Proficiency</h2>
            <button onclick="closeEditLanguageModal()" class="w-10 h-10 rounded-full bg-gray-100 dark:bg-gray-700 flex items-center justify-center hover:bg-gray-200 dark:hover:bg-gray-600 transition">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <form method="POST" action="" id="editLanguageForm" class="space-y-6">
            <input type="hidden" name="action" value="update_language">
            <input type="hidden" name="language_id" id="editLanguageId">
            
            <div>
                <label class="block text-sm font-medium mb-2">Proficiency Level *</label>
                <select name="proficiency_level" id="editProficiencyLevel" required
                        class="w-full px-4 py-3 rounded-xl border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 focus:ring-2 focus:ring-indigo-500 transition">
                    <option value="beginner">Beginner</option>
                    <option value="intermediate">Intermediate</option>
                    <option value="advanced">Advanced</option>
                    <option value="fluent">Fluent</option>
                    <option value="native">Native</option>
                </select>
            </div>
            
            <div class="flex gap-4 pt-4">
                <button type="submit" 
                        class="flex-1 bg-gradient-to-r from-indigo-600 to-purple-600 text-white px-6 py-3 rounded-xl font-semibold hover:shadow-lg hover:scale-105 transition-all duration-300">
                    Update Proficiency
                </button>
                <button type="button" onclick="closeEditLanguageModal()"
                        class="flex-1 border-2 border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 px-6 py-3 rounded-xl font-semibold hover:bg-gray-100 dark:hover:bg-gray-700 transition-all duration-300">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Remove Language Form (Hidden) -->
<form id="removeLanguageForm" method="POST" class="hidden">
    <input type="hidden" name="action" value="remove_language">
    <input type="hidden" name="language_id" id="removeLanguageId">
</form>

<!-- Footer -->
<footer class="bg-gray-900 text-white pt-16 pb-8">
    <div class="container mx-auto px-4">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-8 mb-12">
            <div>
                <div class="flex items-center space-x-3 mb-6">
                    <div class="w-12 h-12 bg-gradient-to-r from-indigo-600 to-purple-600 rounded-xl flex items-center justify-center">
                        <i class="fas fa-globe-asia text-white text-xl"></i>
                    </div>
                    <span class="text-2xl font-bold gradient-text">TransNow</span>
                </div>
                <p class="text-gray-400 text-sm leading-relaxed">
                    Connecting global talents with premier opportunities worldwide.
                </p>
            </div>
            
            <div>
                <h4 class="text-lg font-semibold mb-4">Quick Links</h4>
                <ul class="space-y-2 text-gray-400">
                    <li><a href="../index.php" class="hover:text-white transition">Home</a></li>
                    <li><a href="jobs.php" class="hover:text-white transition">Find Jobs</a></li>
                    <li><a href="applications.php" class="hover:text-white transition">Applications</a></li>
                    <li><a href="saved-jobs.php" class="hover:text-white transition">Saved Jobs</a></li>
                </ul>
            </div>
            
            <div>
                <h4 class="text-lg font-semibold mb-4">Support</h4>
                <ul class="space-y-2 text-gray-400">
                    <li><a href="#" class="hover:text-white transition">Help Center</a></li>
                    <li><a href="#" class="hover:text-white transition">FAQs</a></li>
                    <li><a href="#" class="hover:text-white transition">Privacy Policy</a></li>
                    <li><a href="#" class="hover:text-white transition">Terms of Service</a></li>
                </ul>
            </div>
            
            <div>
                <h4 class="text-lg font-semibold mb-4">Connect With Us</h4>
                <div class="flex space-x-4">
                    <a href="#" class="w-10 h-10 bg-gray-800 rounded-lg flex items-center justify-center hover:bg-indigo-600 transition">
                        <i class="fab fa-linkedin-in"></i>
                    </a>
                    <a href="#" class="w-10 h-10 bg-gray-800 rounded-lg flex items-center justify-center hover:bg-indigo-600 transition">
                        <i class="fab fa-twitter"></i>
                    </a>
                    <a href="#" class="w-10 h-10 bg-gray-800 rounded-lg flex items-center justify-center hover:bg-indigo-600 transition">
                        <i class="fab fa-weixin"></i>
                    </a>
                </div>
            </div>
        </div>
        
        <div class="border-t border-gray-800 pt-8 text-center text-gray-400 text-sm">
            <p>&copy; <?php echo date('Y'); ?> TransNow. All rights reserved.</p>
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
    offset: 100
});

// Navbar scroll effect
const navbar = document.getElementById('navbar');
window.addEventListener('scroll', () => {
    if (window.scrollY > 50) {
        navbar.classList.add('bg-white/90', 'dark:bg-gray-900/90', 'backdrop-blur-lg', 'shadow-lg');
    } else {
        navbar.classList.remove('bg-white/90', 'dark:bg-gray-900/90', 'backdrop-blur-lg', 'shadow-lg');
    }
});

// Scroll to top button
const scrollTopBtn = document.getElementById('scrollTopBtn');
window.addEventListener('scroll', () => {
    if (window.scrollY > 500) {
        scrollTopBtn.classList.remove('opacity-0', 'invisible');
        scrollTopBtn.classList.add('opacity-100', 'visible');
    } else {
        scrollTopBtn.classList.add('opacity-0', 'invisible');
        scrollTopBtn.classList.remove('opacity-100', 'visible');
    }
});

// Dark Mode Toggle
const darkModeToggle = document.getElementById('darkModeToggle');
const darkModeIcon = document.getElementById('darkModeIcon');

if (localStorage.theme === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
    document.documentElement.classList.add('dark');
    darkModeIcon.classList.remove('fa-moon');
    darkModeIcon.classList.add('fa-sun');
} else {
    document.documentElement.classList.remove('dark');
    darkModeIcon.classList.remove('fa-sun');
    darkModeIcon.classList.add('fa-moon');
}

darkModeToggle.addEventListener('click', () => {
    document.documentElement.classList.toggle('dark');
    
    if (document.documentElement.classList.contains('dark')) {
        localStorage.theme = 'dark';
        darkModeIcon.classList.remove('fa-moon');
        darkModeIcon.classList.add('fa-sun');
    } else {
        localStorage.theme = 'light';
        darkModeIcon.classList.remove('fa-sun');
        darkModeIcon.classList.add('fa-moon');
    }
});

// Mobile Menu
const mobileMenuBtn = document.getElementById('mobileMenuBtn');
const mobileMenu = document.getElementById('mobileMenu');

mobileMenuBtn.addEventListener('click', () => {
    mobileMenu.classList.toggle('hidden');
    const icon = mobileMenuBtn.querySelector('i');
    icon.classList.toggle('fa-bars');
    icon.classList.toggle('fa-times');
});

// Tab Switching
function switchTab(tabName) {
    // Hide all tabs
    document.querySelectorAll('.tab-content').forEach(tab => {
        tab.classList.add('hidden');
    });
    
    // Show selected tab
    document.getElementById(`tab-${tabName}`).classList.remove('hidden');
    
    // Update tab buttons
    document.querySelectorAll('.tab-button').forEach(button => {
        button.classList.remove('active');
    });
    
    // Find and activate clicked button (this will be called from button onclick)
    event.currentTarget.classList.add('active');
}

// Modal Functions
function openEditModal() {
    document.getElementById('editProfileModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeEditModal() {
    document.getElementById('editProfileModal').classList.remove('active');
    document.body.style.overflow = 'auto';
}

// Language Modal Functions
function openLanguageModal() {
    document.getElementById('addLanguageModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeLanguageModal() {
    document.getElementById('addLanguageModal').classList.remove('active');
    document.body.style.overflow = 'auto';
}

function editLanguage(languageId, proficiencyLevel) {
    document.getElementById('editLanguageId').value = languageId;
    document.getElementById('editProficiencyLevel').value = proficiencyLevel;
    document.getElementById('editLanguageModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeEditLanguageModal() {
    document.getElementById('editLanguageModal').classList.remove('active');
    document.body.style.overflow = 'auto';
}

function removeLanguage(languageId) {
    if (confirm('Are you sure you want to remove this language?')) {
        document.getElementById('removeLanguageId').value = languageId;
        document.getElementById('removeLanguageForm').submit();
    }
}

// Close modals when clicking outside
document.getElementById('editProfileModal')?.addEventListener('click', (e) => {
    if (e.target === document.getElementById('editProfileModal')) {
        closeEditModal();
    }
});

document.getElementById('addLanguageModal')?.addEventListener('click', (e) => {
    if (e.target === document.getElementById('addLanguageModal')) {
        closeLanguageModal();
    }
});

document.getElementById('editLanguageModal')?.addEventListener('click', (e) => {
    if (e.target === document.getElementById('editLanguageModal')) {
        closeEditLanguageModal();
    }
});

// CV Upload Area Drag & Drop
const cvUploadArea = document.getElementById('cvUploadArea');
if (cvUploadArea) {
    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
        cvUploadArea.addEventListener(eventName, preventDefaults, false);
    });
    
    function preventDefaults(e) {
        e.preventDefault();
        e.stopPropagation();
    }
    
    ['dragenter', 'dragover'].forEach(eventName => {
        cvUploadArea.addEventListener(eventName, highlight, false);
    });
    
    ['dragleave', 'drop'].forEach(eventName => {
        cvUploadArea.addEventListener(eventName, unhighlight, false);
    });
    
    function highlight() {
        cvUploadArea.classList.add('dragover');
    }
    
    function unhighlight() {
        cvUploadArea.classList.remove('dragover');
    }
    
    cvUploadArea.addEventListener('drop', handleDrop, false);
    
    function handleDrop(e) {
        const dt = e.dataTransfer;
        const files = dt.files;
        const fileInput = document.getElementById('cvFile');
        
        if (files.length > 0) {
            fileInput.files = files;
            // Submit the form automatically
            fileInput.form.submit();
        }
    }
}

// Share Profile
function shareProfile() {
    if (navigator.share) {
        navigator.share({
            title: '<?php echo $fullName; ?> - Professional Profile',
            text: 'Check out my profile on TransNow!',
            url: window.location.href
        });
    } else {
        // Fallback - copy to clipboard
        navigator.clipboard.writeText(window.location.href).then(() => {
            alert('Profile link copied to clipboard!');
        });
    }
}

// Show Interest
function showInterest(employerId) {
    alert('Interest shown! The employer will be notified.');
}

// Initialize Charts
document.addEventListener('DOMContentLoaded', function() {
    // Application Status Chart
    const appCtx = document.getElementById('applicationChart')?.getContext('2d');
    if (appCtx) {
        new Chart(appCtx, {
            type: 'doughnut',
            data: {
                labels: ['Applied', 'Reviewed', 'Interview', 'Rejected', 'Hired'],
                datasets: [{
                    data: [
                        <?php 
                        echo $stats['total_applications'] - $stats['reviewed_count'] - $stats['total_interviews'] - ($stats['total_hired'] ?? 0); 
                        ?>,
                        <?php echo $stats['reviewed_count'] ?? 0; ?>,
                        <?php echo $stats['total_interviews'] ?? 0; ?>,
                        0,
                        <?php echo $stats['total_hired'] ?? 0; ?>
                    ],
                    backgroundColor: [
                        '#3b82f6',
                        '#8b5cf6',
                        '#10b981',
                        '#ef4444',
                        '#059669'
                    ],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });
    }
    
    // Match Score Chart
    const matchCtx = document.getElementById('matchChart')?.getContext('2d');
    if (matchCtx) {
        new Chart(matchCtx, {
            type: 'bar',
            data: {
                labels: ['0-40%', '41-60%', '61-80%', '81-100%'],
                datasets: [{
                    label: 'Number of Applications',
                    data: [0, 0, <?php echo $stats['high_match_count'] ?? 0; ?>, 0],
                    backgroundColor: [
                        '#ef4444',
                        '#f59e0b',
                        '#3b82f6',
                        '#10b981'
                    ]
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            }
        });
    }
});

// Profile Picture Upload (simulated)
document.getElementById('profilePictureInput')?.addEventListener('change', function(e) {
    if (e.target.files.length > 0) {
        alert('Profile picture upload functionality would be implemented here with AJAX');
        // In production, you'd upload via AJAX and update the image
    }
});
</script>

<!-- Structured Data for SEO -->
<script type="application/ld+json">
{
    "@context": "https://schema.org",
    "@type": "ProfilePage",
    "name": "<?php echo $fullName; ?>",
    "description": "Professional profile of <?php echo $fullName; ?> - <?php echo htmlspecialchars($seeker['headline'] ?? 'Job Seeker'); ?>",
    "mainEntity": {
        "@type": "Person",
        "name": "<?php echo $fullName; ?>",
        "email": "<?php echo $seeker['email']; ?>",
        "jobTitle": "<?php echo htmlspecialchars($seeker['current_job_title'] ?? ''); ?>",
        "worksFor": {
            "@type": "Organization",
            "name": "<?php echo htmlspecialchars($seeker['current_company'] ?? ''); ?>"
        },
        "skills": "<?php echo htmlspecialchars($seeker['skills'] ?? ''); ?>",
        "alumniOf": {
            "@type": "EducationalOrganization",
            "name": "<?php echo htmlspecialchars($seeker['highest_education'] ?? ''); ?>"
        },
        "knowsLanguage": [
            <?php 
            $langs = [];
            foreach ($seekerLanguages as $lang) {
                $langs[] = '"' . $lang['language_name'] . '"';
            }
            echo implode(', ', $langs);
            ?>
        ]
    }
}
</script>
</body>
</html>