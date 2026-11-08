<?php
// seekers/job-details.php - Enhanced Job Details Page with Maps, Clickable Contacts & Smart Application
// Version: 7.0 - Added Google Maps integration, clickable phone/email, smart resume handling

// Error reporting for development (remove in production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration files
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../includes/auth.php';

// Check if user is logged in and is a seeker
if (!isLoggedIn() || getUserType() !== 'seeker') {
    $_SESSION['error'] = 'Please login as a job seeker to access this page';
    header('Location: /login.php');
    exit();
}

// Get job slug from URL
$jobSlug = $_GET['slug'] ?? '';
if (empty($jobSlug)) {
    header('Location: jobs.php');
    exit();
}

// Get current user ID and seeker ID
$userId = $_SESSION['user_id'];
$seekerId = getCurrentSeekerId();

if (!$seekerId) {
    $_SESSION['error'] = 'Please complete your profile first';
    header('Location: profile.php?edit=1');
    exit();
}

// ===== FETCH JOB DETAILS WITH ALL RELATED DATA =====
$job = fetchOne("
    SELECT 
        j.*,
        e.id as employer_id, 
        e.company_name, 
        e.company_logo, 
        e.company_description,
        e.company_website,
        e.company_phone,
        e.company_email,
        e.company_size,
        e.industry,
        e.is_verified,
        e.address as company_address,
        e.city as company_city,
        e.country as company_country,
        jt.name as job_type_name,
        jt.slug as job_type_slug,
        jc.name as category_name,
        jc.slug as category_slug,
        (SELECT COUNT(*) FROM saved_jobs WHERE job_id = j.id AND seeker_id = ?) as is_saved,
        (SELECT COUNT(*) FROM applications WHERE job_id = j.id AND seeker_id = ?) as has_applied,
        (SELECT application_status FROM applications WHERE job_id = j.id AND seeker_id = ?) as application_status
    FROM jobs j
    INNER JOIN employers e ON j.employer_id = e.id
    LEFT JOIN job_types jt ON j.job_type_id = jt.id
    LEFT JOIN job_categories jc ON j.category_id = jc.id
    WHERE j.slug = ? AND j.status = 'active'
    LIMIT 1
", [$seekerId, $seekerId, $seekerId, $jobSlug]);

// If job not found, redirect
if (!$job) {
    header('Location: jobs.php');
    exit();
}

// Update job view count
executeQuery("UPDATE jobs SET total_views = total_views + 1 WHERE id = ?", [$job['id']]);

// Log view in job_views table
$ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
executeQuery("
    INSERT INTO job_views (job_id, user_id, ip_address, user_agent, viewed_at) 
    VALUES (?, ?, ?, ?, NOW())
", [$job['id'], $userId, $ipAddress, $userAgent]);

// ===== FETCH RELATED DATA =====

// 1. Job skills
$jobSkills = fetchAll("
    SELECT skill_name, is_required 
    FROM job_skills 
    WHERE job_id = ?
    ORDER BY is_required DESC, skill_name ASC
", [$job['id']]);

// 2. Similar jobs based on category and skills
$similarJobs = fetchAll("
    SELECT 
        j.id, j.title, j.slug, j.location_type, j.city, j.created_at,
        e.company_name, e.company_logo,
        jt.name as job_type_name,
        (SELECT COUNT(*) FROM saved_jobs WHERE job_id = j.id AND seeker_id = ?) as is_saved
    FROM jobs j
    INNER JOIN employers e ON j.employer_id = e.id
    LEFT JOIN job_types jt ON j.job_type_id = jt.id
    WHERE j.status = 'active' 
        AND j.id != ? 
        AND j.category_id = ?
    ORDER BY j.is_featured DESC, j.created_at DESC
    LIMIT 4
", [$seekerId, $job['id'], $job['category_id']]);

// If not enough similar jobs by category, get jobs from same company
if (count($similarJobs) < 4) {
    $moreJobs = fetchAll("
        SELECT 
            j.id, j.title, j.slug, j.location_type, j.city, j.created_at,
            e.company_name, e.company_logo,
            jt.name as job_type_name,
            (SELECT COUNT(*) FROM saved_jobs WHERE job_id = j.id AND seeker_id = ?) as is_saved
        FROM jobs j
        INNER JOIN employers e ON j.employer_id = e.id
        LEFT JOIN job_types jt ON j.job_type_id = jt.id
        WHERE j.status = 'active' 
            AND j.id != ? 
            AND j.employer_id = ?
        ORDER BY j.created_at DESC
        LIMIT " . (4 - count($similarJobs)), 
        [$seekerId, $job['id'], $job['employer_id']]
    );
    $similarJobs = array_merge($similarJobs, $moreJobs);
}

// 3. Calculate match score based on seeker profile
$seekerProfile = fetchOne("
    SELECT 
        s.years_experience, s.skills, s.highest_education,
        s.resume_url, s.phone, s.whatsapp_number,
        sp.preferred_categories, sp.preferred_job_types, 
        sp.preferred_location_types, sp.min_salary, sp.max_salary
    FROM seekers s
    LEFT JOIN seeker_preferences sp ON s.id = sp.seeker_id
    WHERE s.user_id = ?
", [$userId]);

// Get seeker's existing resume
$seekerResume = $seekerProfile['resume_url'] ?? null;

$matchScore = 0;
$matchCriteria = [];

if ($seekerProfile) {
    // Parse seeker skills
    $seekerSkills = array_map('trim', explode(',', $seekerProfile['skills'] ?? ''));
    $jobRequiredSkills = array_filter($jobSkills, function($skill) { 
        return $skill['is_required']; 
    });
    $jobAllSkills = array_column($jobSkills, 'skill_name');
    
    // Calculate skill match (40% of total score)
    if (!empty($jobRequiredSkills)) {
        $matchedRequired = array_intersect($seekerSkills, array_column($jobRequiredSkills, 'skill_name'));
        $skillScore = (count($matchedRequired) / count($jobRequiredSkills)) * 40;
        $matchScore += $skillScore;
        $matchCriteria['skills'] = [
            'score' => round($skillScore),
            'matched' => $matchedRequired,
            'total' => count($jobRequiredSkills)
        ];
    }
    
    // Experience match (30% of total score)
    if ($job['experience_level']) {
        $expLevels = ['entry' => 1, 'mid' => 3, 'senior' => 6, 'executive' => 10];
        $requiredExp = $expLevels[$job['experience_level']] ?? 3;
        $seekerExp = $seekerProfile['years_experience'] ?? 0;
        
        if ($seekerExp >= $requiredExp) {
            $expScore = 30;
        } elseif ($seekerExp >= $requiredExp * 0.7) {
            $expScore = 20;
        } elseif ($seekerExp >= $requiredExp * 0.5) {
            $expScore = 10;
        } else {
            $expScore = 0;
        }
        $matchScore += $expScore;
        $matchCriteria['experience'] = [
            'score' => $expScore,
            'required' => $requiredExp,
            'has' => $seekerExp
        ];
    }
    
    // Location match (15% of total score)
    if ($seekerProfile['preferred_location_types']) {
        $prefLocations = json_decode($seekerProfile['preferred_location_types'], true) ?? [];
        if (in_array($job['location_type'], $prefLocations)) {
            $matchScore += 15;
            $matchCriteria['location'] = ['score' => 15, 'matched' => true];
        } else {
            $matchCriteria['location'] = ['score' => 0, 'matched' => false];
        }
    }
    
    // Job type match (15% of total score)
    if ($seekerProfile['preferred_job_types']) {
        $prefTypes = json_decode($seekerProfile['preferred_job_types'], true) ?? [];
        if (in_array($job['job_type_id'], $prefTypes)) {
            $matchScore += 15;
            $matchCriteria['job_type'] = ['score' => 15, 'matched' => true];
        } else {
            $matchCriteria['job_type'] = ['score' => 0, 'matched' => false];
        }
    }
}

$matchScore = round($matchScore);

// 4. Check application eligibility
$canApply = true;
$applicationError = '';

if ($job['has_applied'] > 0) {
    $canApply = false;
    $applicationError = 'You have already applied for this position';
} elseif ($job['application_deadline'] && strtotime($job['application_deadline']) < time()) {
    $canApply = false;
    $applicationError = 'Application deadline has passed';
} elseif ($job['total_applications'] >= $job['total_vacancies'] * 10) {
    $canApply = false;
    $applicationError = 'This position has received maximum applications';
}

// 5. Company reviews/ratings
$companyReviews = fetchAll("
    SELECT 
        s.first_name, s.last_name, s.profile_picture,
        a.rating, a.review_text, a.created_at
    FROM applications a
    INNER JOIN seekers s ON a.seeker_id = s.id
    WHERE a.employer_id = ? AND a.rating IS NOT NULL
    ORDER BY a.created_at DESC
    LIMIT 3
", [$job['employer_id']]);

// Calculate average rating
$avgRating = fetchOne("
    SELECT AVG(rating) as avg_rating, COUNT(*) as total_reviews
    FROM applications
    WHERE employer_id = ? AND rating IS NOT NULL
", [$job['employer_id']]);

// 6. Parse job benefits into array
$benefits = [];
if (!empty($job['benefits'])) {
    $benefits = explode("\n", trim($job['benefits']));
    $benefits = array_filter(array_map('trim', $benefits));
}

// 7. Default benefits if none provided
if (empty($benefits)) {
    $benefits = [
        'Competitive salary package',
        'Health insurance coverage',
        'Professional development opportunities',
        'Flexible working hours',
        'Remote work options',
        'Paid time off and holidays',
        'Career growth potential',
        'Modern work environment'
    ];
}

// 8. Format salary
$salaryDisplay = 'Negotiable';
if ($job['salary_min'] && $job['salary_max'] && $job['is_salary_visible']) {
    $salaryDisplay = ($job['salary_currency'] ?? 'USD') . ' ' . number_format($job['salary_min']) . ' - ' . number_format($job['salary_max']);
    if ($job['salary_type']) {
        $salaryDisplay .= ' /' . $job['salary_type'];
    }
    if ($job['is_salary_negotiable']) {
        $salaryDisplay .= ' (Negotiable)';
    }
}

// 9. Generate QR Code URL (using a free QR code API)
$jobUrl = 'https://' . $_SERVER['HTTP_HOST'] . '/seekers/job-details.php?slug=' . urlencode($job['slug']);
$qrCodeUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . urlencode($jobUrl);

// 10. Generate Google Maps URL for location
$mapLocation = '';
if ($job['address']) {
    $mapLocation = $job['address'];
} elseif ($job['city'] && $job['country']) {
    $mapLocation = $job['city'] . ', ' . $job['country'];
} elseif ($job['city']) {
    $mapLocation = $job['city'];
} elseif ($job['country']) {
    $mapLocation = $job['country'];
}

$googleMapsUrl = $mapLocation ? 'https://www.google.com/maps/search/?api=1&query=' . urlencode($mapLocation) : '';

// Page title
$pageTitle = $job['title'] . ' at ' . $job['company_name'] . ' - TransNow';
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <meta name="description" content="<?php echo htmlspecialchars(substr(strip_tags($job['description']), 0, 160)); ?>">
    <meta name="keywords" content="<?php echo htmlspecialchars($job['title']); ?>, <?php echo htmlspecialchars($job['company_name']); ?>, <?php echo htmlspecialchars($job['category_name'] ?? 'job'); ?>, career">
    
    <!-- Open Graph / Social Media Meta Tags -->
    <meta property="og:title" content="<?php echo htmlspecialchars($job['title']); ?> at <?php echo htmlspecialchars($job['company_name']); ?>">
    <meta property="og:description" content="<?php echo htmlspecialchars(substr(strip_tags($job['description']), 0, 200)); ?>">
    <meta property="og:image" content="<?php echo htmlspecialchars($job['company_logo'] ?? 'https://ui-avatars.com/api/?name='.urlencode($job['company_name']).'&background=4f46e5&color=fff&size=200'); ?>">
    <meta property="og:url" content="<?php echo htmlspecialchars($jobUrl); ?>">
    <meta property="og:type" content="website">
    
    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?php echo htmlspecialchars($job['title']); ?> at <?php echo htmlspecialchars($job['company_name']); ?>">
    <meta name="twitter:description" content="<?php echo htmlspecialchars(substr(strip_tags($job['description']), 0, 200)); ?>">
    <meta name="twitter:image" content="<?php echo htmlspecialchars($job['company_logo'] ?? 'https://ui-avatars.com/api/?name='.urlencode($job['company_name']).'&background=4f46e5&color=fff&size=200'); ?>">
    
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
    
    <!-- Google Fonts - Inter & Noto Sans SC -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Noto+Sans+SC:wght@300;400;500;700&display=swap" rel="stylesheet">
    
    <!-- AOS Animation -->
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    
    <!-- SweetAlert2 -->
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    
    <!-- Quill Rich Text Editor -->
    <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
    
    <!-- Leaflet Maps (Lightweight alternative to Google Maps) -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    
    <style>
        * {
            font-family: 'Inter', 'Noto Sans SC', sans-serif;
        }
        
        /* Custom Animations */
        @keyframes float {
            0%, 100% { transform: translateY(0) rotate(0deg); }
            25% { transform: translateY(-20px) rotate(2deg); }
            75% { transform: translateY(20px) rotate(-2deg); }
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
            from { transform: translateY(50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        @keyframes shimmer {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }
        
        /* Custom Classes */
        .animate-float {
            animation: float 8s ease-in-out infinite;
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
        
        /* Badge Styles */
        .badge-urgent {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
        }
        
        .badge-featured {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: white;
        }
        
        .badge-new {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
        }
        
        /* Match Score Circle */
        .match-score-circle {
            position: relative;
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: conic-gradient(#4f46e5 <?php echo $matchScore; ?>%, #e5e7eb <?php echo $matchScore; ?>%);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .match-score-circle::before {
            content: '';
            position: absolute;
            width: 70px;
            height: 70px;
            border-radius: 50%;
            background: white;
        }
        
        .match-score-value {
            position: relative;
            font-size: 1.5rem;
            font-weight: bold;
            color: #4f46e5;
        }
        
        /* QR Code Modal */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(5px);
            z-index: 9999;
            display: none;
            align-items: center;
            justify-content: center;
        }
        
        .modal-content {
            background: white;
            border-radius: 2rem;
            padding: 2rem;
            max-width: 400px;
            width: 90%;
            animation: slideIn 0.3s ease-out;
        }
        
        .dark .modal-content {
            background: #1f2937;
            color: white;
        }
        
        /* Map Container */
        .map-container {
            height: 200px;
            width: 100%;
            border-radius: 1rem;
            overflow: hidden;
            margin-bottom: 1rem;
        }
        
        /* Clickable contact styles */
        .contact-link {
            transition: all 0.2s ease;
            padding: 0.25rem 0.5rem;
            border-radius: 0.5rem;
            display: inline-flex;
            align-items: center;
        }
        
        .contact-link:hover {
            background-color: rgba(79, 70, 229, 0.1);
            color: #4f46e5;
        }
        
        .contact-link i {
            margin-right: 0.5rem;
        }
        
        /* Resume preview */
        .resume-preview {
            border: 2px dashed #e5e7eb;
            border-radius: 1rem;
            padding: 1rem;
            transition: all 0.2s ease;
        }
        
        .resume-preview:hover {
            border-color: #4f46e5;
            background-color: rgba(79, 70, 229, 0.05);
        }
        
        .dark .resume-preview:hover {
            background-color: rgba(79, 70, 229, 0.1);
        }
        
        /* Job Description Styling */
        .job-description {
            line-height: 1.8;
            color: #374151;
        }
        
        .job-description h1, 
        .job-description h2, 
        .job-description h3, 
        .job-description h4 {
            font-weight: bold;
            margin-top: 1.5rem;
            margin-bottom: 1rem;
            color: #111827;
        }
        
        .job-description h2 {
            font-size: 1.5rem;
        }
        
        .job-description h3 {
            font-size: 1.25rem;
        }
        
        .job-description ul, 
        .job-description ol {
            margin-left: 1.5rem;
            margin-bottom: 1rem;
        }
        
        .job-description ul {
            list-style-type: disc;
        }
        
        .job-description ol {
            list-style-type: decimal;
        }
        
        .job-description p {
            margin-bottom: 1rem;
        }
        
        .job-description strong {
            font-weight: 600;
            color: #111827;
        }
        
        /* Dark mode adjustments */
        .dark .job-description {
            color: #d1d5db;
        }
        
        .dark .job-description h1,
        .dark .job-description h2,
        .dark .job-description h3,
        .dark .job-description h4,
        .dark .job-description strong {
            color: #f9fafb;
        }
        
        /* Mobile Optimizations */
        @media (max-width: 768px) {
            .hero-title {
                font-size: 2rem;
                line-height: 1.2;
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
        
        /* Sticky Sidebar */
        .sticky-sidebar {
            position: sticky;
            top: 100px;
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
            transform: scale(1.1);
        }
        
        /* Progress Steps */
        .progress-step {
            position: relative;
            padding-left: 2rem;
            padding-bottom: 2rem;
        }
        
        .progress-step::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            width: 2px;
            height: 100%;
            background: linear-gradient(180deg, #4f46e5, #7c3aed);
        }
        
        .progress-step:last-child::before {
            display: none;
        }
        
        .progress-step .step-dot {
            position: absolute;
            left: -6px;
            top: 0;
            width: 14px;
            height: 14px;
            border-radius: 50%;
            background: white;
            border: 2px solid #4f46e5;
            z-index: 1;
        }
        
        .progress-step.completed .step-dot {
            background: #4f46e5;
            border-color: #4f46e5;
        }
        
        .progress-step.completed::before {
            background: #4f46e5;
        }
        
        /* Dark mode support */
        @media (prefers-color-scheme: dark) {
            .dark-mode-aware {
                background: #1f2937;
                color: #ffffff;
            }
        }
        
        /* Print styles */
        @media print {
            .no-print {
                display: none !important;
            }
            
            body {
                background: white;
                color: black;
            }
        }
    </style>
</head>
<body class="bg-gray-50 dark:bg-gray-900 text-gray-900 dark:text-white transition-colors duration-300 overflow-x-hidden">

<!-- QR Code Share Modal -->
<div id="qrModal" class="modal-overlay no-print" onclick="if(event.target === this) closeQRModal()">
    <div class="modal-content text-center">
        <h3 class="text-2xl font-bold mb-4 gradient-text">Share This Job</h3>
        
        <!-- QR Code -->
        <div class="bg-white p-4 rounded-2xl inline-block mb-4">
            <img src="<?php echo htmlspecialchars($qrCodeUrl); ?>" 
                 alt="QR Code for <?php echo htmlspecialchars($job['title']); ?>"
                 class="w-48 h-48 mx-auto">
        </div>
        
        <!-- Job Info -->
        <h4 class="font-bold text-lg mb-2"><?php echo htmlspecialchars($job['title']); ?></h4>
        <p class="text-gray-600 dark:text-gray-400 mb-4"><?php echo htmlspecialchars($job['company_name']); ?></p>
        
        <!-- Job Link -->
        <div class="bg-gray-100 dark:bg-gray-700 rounded-xl p-3 mb-4">
            <p class="text-sm truncate"><?php echo htmlspecialchars($jobUrl); ?></p>
        </div>
        
        <!-- Action Buttons -->
        <div class="flex flex-wrap gap-3 justify-center">
            <button onclick="downloadQRCode()" class="px-4 py-2 bg-gradient-to-r from-indigo-600 to-purple-600 text-white rounded-xl font-semibold hover:shadow-lg transition-all">
                <i class="fas fa-download mr-2"></i>Download QR
            </button>
            <button onclick="copyJobLink()" class="px-4 py-2 border-2 border-indigo-600 text-indigo-600 dark:text-indigo-400 rounded-xl font-semibold hover:bg-indigo-50 dark:hover:bg-indigo-900/30 transition-all">
                <i class="fas fa-link mr-2"></i>Copy Link
            </button>
            <button onclick="closeQRModal()" class="px-4 py-2 bg-gray-200 dark:bg-gray-600 rounded-xl font-semibold hover:bg-gray-300 dark:hover:bg-gray-500 transition-all">
                <i class="fas fa-times mr-2"></i>Close
            </button>
        </div>
        
        <!-- Share Text -->
        <p class="text-sm text-gray-500 mt-4">Scan QR code or share link with friends</p>
    </div>
</div>

<!-- Floating Action Button -->
<div class="fab flex flex-col space-y-3 no-print">
    <button onclick="window.scrollTo({top: 0, behavior: 'smooth'})" 
            id="scrollTopBtn" 
            class="w-14 h-14 bg-gradient-to-r from-indigo-600 to-purple-600 text-white rounded-full shadow-2xl flex items-center justify-center opacity-0 invisible transition-all duration-300 hover:shadow-indigo-500/50 shine">
        <i class="fas fa-arrow-up text-xl"></i>
    </button>
    <?php if ($canApply): ?>
    <button onclick="openApplyModal()" 
            class="w-14 h-14 bg-gradient-to-r from-green-500 to-emerald-600 text-white rounded-full shadow-2xl flex items-center justify-center animate-pulse-glow">
        <i class="fas fa-paper-plane text-xl"></i>
    </button>
    <?php endif; ?>
</div>

<!-- Dark Mode Toggle -->
<button id="darkModeToggle" 
        class="fixed top-24 right-4 z-50 w-12 h-12 glass dark:glass-dark text-white rounded-full flex items-center justify-center hover:scale-110 transition-all duration-300 no-print">
    <i class="fas fa-moon text-xl" id="darkModeIcon"></i>
</button>

<!-- Navigation -->
<nav class="fixed w-full z-40 transition-all duration-300 no-print" id="navbar">
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
                        <i class="fas fa-search mr-2"></i>Browse Jobs
                    </a>
                    <a href="dashboard.php" class="animated-underline font-medium text-gray-700 dark:text-gray-300 hover:text-indigo-600 dark:hover:text-indigo-400 transition">
                        <i class="fas fa-tachometer-alt mr-2"></i>Dashboard
                    </a>
                    <a href="applications.php" class="animated-underline font-medium text-gray-700 dark:text-gray-300 hover:text-indigo-600 dark:hover:text-indigo-400 transition">
                        <i class="fas fa-paper-plane mr-2"></i>Applications
                    </a>
                </div>
                
                <!-- User Menu -->
                <div class="hidden lg:flex items-center space-x-4">
                    <div class="relative group">
                        <button class="flex items-center space-x-3 bg-gray-100 dark:bg-gray-800 px-4 py-2 rounded-xl hover:bg-gray-200 dark:hover:bg-gray-700 transition">
                            <div class="w-8 h-8 bg-gradient-to-r from-indigo-600 to-purple-600 rounded-full flex items-center justify-center">
                                <i class="fas fa-user text-white text-sm"></i>
                            </div>
                            <span class="font-medium"><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Account'); ?></span>
                            <i class="fas fa-chevron-down text-xs"></i>
                        </button>
                        <div class="absolute right-0 mt-2 w-48 bg-white dark:bg-gray-800 rounded-xl shadow-2xl opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-300 transform origin-top-right scale-95 group-hover:scale-100">
                            <div class="py-2">
                                <a href="dashboard.php" class="block px-4 py-2 hover:bg-indigo-50 dark:hover:bg-gray-700 transition">
                                    <i class="fas fa-tachometer-alt mr-2 text-indigo-600"></i> Dashboard
                                </a>
                                <a href="profile.php" class="block px-4 py-2 hover:bg-indigo-50 dark:hover:bg-gray-700 transition">
                                    <i class="fas fa-user-circle mr-2 text-indigo-600"></i> Profile
                                </a>
                                <a href="applications.php" class="block px-4 py-2 hover:bg-indigo-50 dark:hover:bg-gray-700 transition">
                                    <i class="fas fa-paper-plane mr-2 text-indigo-600"></i> Applications
                                </a>
                                <a href="saved-jobs.php" class="block px-4 py-2 hover:bg-indigo-50 dark:hover:bg-gray-700 transition">
                                    <i class="fas fa-bookmark mr-2 text-indigo-600"></i> Saved Jobs
                                </a>
                                <div class="border-t dark:border-gray-700 my-2"></div>
                                <a href="../logout.php" class="block px-4 py-2 text-red-600 hover:bg-red-50 dark:hover:bg-gray-700 transition">
                                    <i class="fas fa-sign-out-alt mr-2"></i> Logout
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
                <a href="../index.php" class="py-2 hover:text-indigo-600 transition">
                    <i class="fas fa-home mr-2"></i>Home
                </a>
                <a href="jobs.php" class="py-2 hover:text-indigo-600 transition">
                    <i class="fas fa-search mr-2"></i>Browse Jobs
                </a>
                <a href="dashboard.php" class="py-2 hover:text-indigo-600 transition">
                    <i class="fas fa-tachometer-alt mr-2"></i>Dashboard
                </a>
                <a href="applications.php" class="py-2 hover:text-indigo-600 transition">
                    <i class="fas fa-paper-plane mr-2"></i>Applications
                </a>
                <a href="saved-jobs.php" class="py-2 hover:text-indigo-600 transition">
                    <i class="fas fa-bookmark mr-2"></i>Saved Jobs
                </a>
                <a href="profile.php" class="py-2 hover:text-indigo-600 transition">
                    <i class="fas fa-user-circle mr-2"></i>Profile
                </a>
                <div class="border-t dark:border-gray-700 my-2"></div>
                <a href="../logout.php" class="py-2 text-red-600 hover:text-red-700 transition">
                    <i class="fas fa-sign-out-alt mr-2"></i>Logout
                </a>
            </div>
        </div>
    </div>
</nav>

<!-- Breadcrumb -->
<div class="pt-28 pb-4 bg-white dark:bg-gray-800 border-b dark:border-gray-700 no-print">
    <div class="container mx-auto px-4">
        <div class="flex items-center text-sm text-gray-600 dark:text-gray-400">
            <a href="../index.php" class="hover:text-indigo-600 transition">Home</a>
            <i class="fas fa-chevron-right mx-2 text-xs"></i>
            <a href="jobs.php" class="hover:text-indigo-600 transition">Jobs</a>
            <i class="fas fa-chevron-right mx-2 text-xs"></i>
            <span class="text-indigo-600 dark:text-indigo-400 font-medium truncate"><?php echo htmlspecialchars($job['title']); ?></span>
        </div>
    </div>
</div>

<!-- Main Content -->
<div class="container mx-auto px-4 py-8">
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        <!-- Main Content - Left Column -->
        <div class="lg:col-span-2">
            <!-- Job Header Card -->
            <div class="bg-white dark:bg-gray-800 rounded-3xl shadow-xl p-8 mb-8" data-aos="fade-up">
                <div class="flex flex-col md:flex-row md:items-start justify-between mb-6">
                    <div class="flex items-start space-x-4">
                        <img src="<?php echo htmlspecialchars($job['company_logo'] ?? 'https://ui-avatars.com/api/?name='.urlencode($job['company_name']).'&background=4f46e5&color=fff&size=128'); ?>" 
                             alt="<?php echo htmlspecialchars($job['company_name']); ?>"
                             class="w-20 h-20 md:w-24 md:h-24 rounded-2xl object-cover border-4 border-indigo-100 dark:border-indigo-900">
                        <div>
                            <div class="flex items-center flex-wrap gap-3 mb-2">
                                <h1 class="text-2xl md:text-3xl font-bold"><?php echo htmlspecialchars($job['title']); ?></h1>
                                <?php if ($job['is_urgent']): ?>
                                <span class="badge-urgent px-3 py-1 rounded-full text-xs font-bold">URGENT</span>
                                <?php endif; ?>
                                <?php if ($job['is_featured']): ?>
                                <span class="badge-featured px-3 py-1 rounded-full text-xs font-bold">FEATURED</span>
                                <?php endif; ?>
                            </div>
                            <div class="flex items-center flex-wrap gap-4 text-gray-600 dark:text-gray-400">
                                <a href="#company" class="hover:text-indigo-600 transition">
                                    <i class="fas fa-building mr-2 text-indigo-500"></i>
                                    <?php echo htmlspecialchars($job['company_name']); ?>
                                    <?php if ($job['is_verified']): ?>
                                    <i class="fas fa-check-circle text-blue-500 ml-1" title="Verified Employer"></i>
                                    <?php endif; ?>
                                </a>
                                <?php if ($mapLocation): ?>
                                <a href="<?php echo $googleMapsUrl; ?>" target="_blank" class="hover:text-indigo-600 transition contact-link">
                                    <i class="fas fa-map-marker-alt mr-2 text-indigo-500"></i>
                                    <?php echo ucfirst($job['location_type'] ?? 'Onsite'); ?>
                                    <?php if ($job['city']): ?> - <?php echo htmlspecialchars($job['city']); ?><?php endif; ?>
                                    <?php if ($job['country']): ?>, <?php echo htmlspecialchars($job['country']); ?><?php endif; ?>
                                </a>
                                <?php else: ?>
                                <span>
                                    <i class="fas fa-map-marker-alt mr-2 text-indigo-500"></i>
                                    <?php echo ucfirst($job['location_type'] ?? 'Onsite'); ?>
                                    <?php if ($job['city']): ?> - <?php echo htmlspecialchars($job['city']); ?><?php endif; ?>
                                    <?php if ($job['country']): ?>, <?php echo htmlspecialchars($job['country']); ?><?php endif; ?>
                                </span>
                                <?php endif; ?>
                                <span>
                                    <i class="fas fa-clock mr-2 text-indigo-500"></i>
                                    <?php echo htmlspecialchars($job['job_type_name'] ?? 'Full Time'); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Match Score -->
                    <div class="mt-4 md:mt-0 flex flex-col items-center">
                        <div class="match-score-circle">
                            <span class="match-score-value"><?php echo $matchScore; ?>%</span>
                        </div>
                        <span class="text-sm text-gray-500 mt-2">Match Score</span>
                    </div>
                </div>
                
                <!-- Key Details Grid -->
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                    <div class="bg-indigo-50 dark:bg-indigo-900/30 rounded-xl p-4 text-center">
                        <i class="fas fa-dollar-sign text-indigo-600 text-xl mb-2"></i>
                        <p class="text-sm text-gray-600 dark:text-gray-400">Salary</p>
                        <p class="font-semibold"><?php echo $salaryDisplay; ?></p>
                    </div>
                    <div class="bg-indigo-50 dark:bg-indigo-900/30 rounded-xl p-4 text-center">
                        <i class="fas fa-briefcase text-indigo-600 text-xl mb-2"></i>
                        <p class="text-sm text-gray-600 dark:text-gray-400">Experience</p>
                        <p class="font-semibold"><?php echo ucfirst($job['experience_level'] ?? 'Entry Level'); ?></p>
                    </div>
                    <div class="bg-indigo-50 dark:bg-indigo-900/30 rounded-xl p-4 text-center">
                        <i class="fas fa-users text-indigo-600 text-xl mb-2"></i>
                        <p class="text-sm text-gray-600 dark:text-gray-400">Vacancies</p>
                        <p class="font-semibold"><?php echo $job['total_vacancies'] ?? 1; ?></p>
                    </div>
                    <div class="bg-indigo-50 dark:bg-indigo-900/30 rounded-xl p-4 text-center">
                        <i class="fas fa-calendar-alt text-indigo-600 text-xl mb-2"></i>
                        <p class="text-sm text-gray-600 dark:text-gray-400">Posted</p>
                        <p class="font-semibold">
                            <?php 
                            $timeDiff = time() - strtotime($job['created_at']);
                            if ($timeDiff < 86400) {
                                echo 'Today';
                            } elseif ($timeDiff < 172800) {
                                echo 'Yesterday';
                            } else {
                                echo floor($timeDiff / 86400) . ' days ago';
                            }
                            ?>
                        </p>
                    </div>
                </div>
                
                <!-- Application Status / Actions -->
                <div class="flex flex-wrap gap-4 no-print">
                    <?php if ($job['has_applied'] > 0): ?>
                        <div class="flex-1 bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400 px-6 py-4 rounded-xl flex items-center justify-center">
                            <i class="fas fa-check-circle text-2xl mr-3"></i>
                            <div>
                                <p class="font-semibold">Application Submitted</p>
                                <p class="text-sm">Status: <?php echo ucfirst($job['application_status'] ?? 'Applied'); ?></p>
                            </div>
                        </div>
                        <a href="applications.php" class="bg-indigo-600 hover:bg-indigo-700 text-white px-8 py-4 rounded-xl font-semibold transition-all duration-300 flex items-center">
                            <i class="fas fa-eye mr-2"></i> View Application
                        </a>
                    <?php elseif ($canApply): ?>
                        <button onclick="openApplyModal()" class="flex-1 bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-700 hover:to-purple-700 text-white px-6 py-4 rounded-xl font-semibold transition-all duration-300 transform hover:scale-105 hover:shadow-2xl shine flex items-center justify-center">
                            <i class="fas fa-paper-plane mr-2"></i>
                            Apply Now
                        </button>
                        <button onclick="saveJob(<?php echo $job['id']; ?>)" 
                                class="px-6 py-4 border-2 border-indigo-600 text-indigo-600 dark:text-indigo-400 rounded-xl font-semibold hover:bg-indigo-50 dark:hover:bg-indigo-900/30 transition-all duration-300 flex items-center <?php echo $job['is_saved'] ? 'bg-indigo-50 dark:bg-indigo-900/30' : ''; ?>"
                                id="saveJobBtn">
                            <i class="fas <?php echo $job['is_saved'] ? 'fa-bookmark' : 'fa-bookmark'; ?> mr-2"></i>
                            <span id="saveBtnText"><?php echo $job['is_saved'] ? 'Saved' : 'Save Job'; ?></span>
                        </button>
                    <?php else: ?>
                        <div class="flex-1 bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-400 px-6 py-4 rounded-xl">
                            <i class="fas fa-exclamation-triangle mr-2"></i>
                            <?php echo $applicationError; ?>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Deadline Warning -->
                <?php if ($job['application_deadline']): ?>
                <div class="mt-4 p-4 bg-yellow-50 dark:bg-yellow-900/30 text-yellow-700 dark:text-yellow-400 rounded-xl flex items-center">
                    <i class="fas fa-clock mr-3"></i>
                    <span>
                        <strong>Application Deadline:</strong> 
                        <?php echo date('F j, Y', strtotime($job['application_deadline'])); ?>
                        (<?php 
                        $daysLeft = floor((strtotime($job['application_deadline']) - time()) / 86400);
                        echo $daysLeft > 0 ? "$daysLeft days remaining" : "Today";
                        ?>)
                    </span>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Match Analysis Card -->
            <?php if (!empty($matchCriteria)): ?>
            <div class="bg-white dark:bg-gray-800 rounded-3xl shadow-xl p-8 mb-8" data-aos="fade-up">
                <h2 class="text-2xl font-bold mb-6 flex items-center">
                    <i class="fas fa-chart-pie text-indigo-600 mr-3"></i>
                    Your Match Analysis
                </h2>
                
                <div class="space-y-4">
                    <?php if (isset($matchCriteria['skills'])): ?>
                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <span class="font-medium">Skills Match</span>
                            <span class="text-indigo-600 font-bold"><?php echo $matchCriteria['skills']['score']; ?>/40</span>
                        </div>
                        <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-3">
                            <div class="bg-indigo-600 h-3 rounded-full" style="width: <?php echo ($matchCriteria['skills']['score'] / 40) * 100; ?>%"></div>
                        </div>
                        <?php if (!empty($matchCriteria['skills']['matched'])): ?>
                        <p class="text-sm text-green-600 dark:text-green-400 mt-2">
                            <i class="fas fa-check-circle mr-1"></i>
                            Matched: <?php echo implode(', ', $matchCriteria['skills']['matched']); ?>
                        </p>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (isset($matchCriteria['experience'])): ?>
                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <span class="font-medium">Experience Match</span>
                            <span class="text-indigo-600 font-bold"><?php echo $matchCriteria['experience']['score']; ?>/30</span>
                        </div>
                        <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-3">
                            <div class="bg-indigo-600 h-3 rounded-full" style="width: <?php echo ($matchCriteria['experience']['score'] / 30) * 100; ?>%"></div>
                        </div>
                        <p class="text-sm text-gray-600 dark:text-gray-400 mt-2">
                            Required: <?php echo $matchCriteria['experience']['required']; ?>+ years • 
                            You have: <?php echo $matchCriteria['experience']['has']; ?> years
                        </p>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (isset($matchCriteria['location'])): ?>
                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <span class="font-medium">Location Preference</span>
                            <span class="text-indigo-600 font-bold"><?php echo $matchCriteria['location']['score']; ?>/15</span>
                        </div>
                        <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-3">
                            <div class="bg-indigo-600 h-3 rounded-full" style="width: <?php echo ($matchCriteria['location']['score'] / 15) * 100; ?>%"></div>
                        </div>
                        <?php if ($matchCriteria['location']['matched']): ?>
                        <p class="text-sm text-green-600 dark:text-green-400 mt-2">
                            <i class="fas fa-check-circle mr-1"></i>
                            This job matches your preferred work location type
                        </p>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (isset($matchCriteria['job_type'])): ?>
                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <span class="font-medium">Job Type Match</span>
                            <span class="text-indigo-600 font-bold"><?php echo $matchCriteria['job_type']['score']; ?>/15</span>
                        </div>
                        <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-3">
                            <div class="bg-indigo-600 h-3 rounded-full" style="width: <?php echo ($matchCriteria['job_type']['score'] / 15) * 100; ?>%"></div>
                        </div>
                        <?php if ($matchCriteria['job_type']['matched']): ?>
                        <p class="text-sm text-green-600 dark:text-green-400 mt-2">
                            <i class="fas fa-check-circle mr-1"></i>
                            This matches your preferred job type
                        </p>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Job Description -->
            <div class="bg-white dark:bg-gray-800 rounded-3xl shadow-xl p-8 mb-8" data-aos="fade-up">
                <h2 class="text-2xl font-bold mb-6 flex items-center">
                    <i class="fas fa-file-alt text-indigo-600 mr-3"></i>
                    Job Description
                </h2>
                
                <div class="job-description prose dark:prose-invert max-w-none">
                    <?php echo nl2br(htmlspecialchars($job['description'])); ?>
                </div>
                
                <?php if (!empty($job['responsibilities'])): ?>
                <h3 class="text-xl font-bold mt-8 mb-4">Responsibilities</h3>
                <div class="job-description">
                    <?php echo nl2br(htmlspecialchars($job['responsibilities'])); ?>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($job['requirements'])): ?>
                <h3 class="text-xl font-bold mt-8 mb-4">Requirements</h3>
                <div class="job-description">
                    <?php echo nl2br(htmlspecialchars($job['requirements'])); ?>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Required Skills -->
            <?php if (!empty($jobSkills)): ?>
            <div class="bg-white dark:bg-gray-800 rounded-3xl shadow-xl p-8 mb-8" data-aos="fade-up">
                <h2 class="text-2xl font-bold mb-6 flex items-center">
                    <i class="fas fa-code text-indigo-600 mr-3"></i>
                    Required Skills
                </h2>
                
                <div class="flex flex-wrap gap-3">
                    <?php foreach ($jobSkills as $skill): ?>
                    <span class="px-4 py-2 <?php echo $skill['is_required'] ? 'bg-indigo-100 dark:bg-indigo-900 text-indigo-700 dark:text-indigo-300' : 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300'; ?> rounded-full text-sm font-medium">
                        <?php echo htmlspecialchars($skill['skill_name']); ?>
                        <?php if ($skill['is_required']): ?>
                        <span class="ml-1 text-xs">(Required)</span>
                        <?php endif; ?>
                    </span>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Benefits -->
            <div class="bg-white dark:bg-gray-800 rounded-3xl shadow-xl p-8 mb-8" data-aos="fade-up">
                <h2 class="text-2xl font-bold mb-6 flex items-center">
                    <i class="fas fa-gift text-indigo-600 mr-3"></i>
                    Benefits & Perks
                </h2>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <?php foreach ($benefits as $benefit): ?>
                    <div class="flex items-start space-x-3">
                        <div class="w-6 h-6 bg-green-100 dark:bg-green-900 rounded-full flex items-center justify-center flex-shrink-0 mt-0.5">
                            <i class="fas fa-check text-green-600 dark:text-green-400 text-xs"></i>
                        </div>
                        <span><?php echo htmlspecialchars($benefit); ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- Application Progress Timeline (if applied) -->
            <?php if ($job['has_applied'] > 0): ?>
            <div class="bg-white dark:bg-gray-800 rounded-3xl shadow-xl p-8 mb-8" data-aos="fade-up">
                <h2 class="text-2xl font-bold mb-6 flex items-center">
                    <i class="fas fa-chart-line text-indigo-600 mr-3"></i>
                    Application Progress
                </h2>
                
                <div class="progress-step <?php echo in_array($job['application_status'], ['applied', 'reviewed', 'shortlisted', 'interviewed', 'offered', 'hired']) ? 'completed' : ''; ?>">
                    <div class="step-dot"></div>
                    <h4 class="font-semibold">Application Submitted</h4>
                    <p class="text-sm text-gray-600 dark:text-gray-400">Your application has been received</p>
                </div>
                
                <div class="progress-step <?php echo in_array($job['application_status'], ['reviewed', 'shortlisted', 'interviewed', 'offered', 'hired']) ? 'completed' : ''; ?>">
                    <div class="step-dot"></div>
                    <h4 class="font-semibold">Under Review</h4>
                    <p class="text-sm text-gray-600 dark:text-gray-400">Employer is reviewing your application</p>
                </div>
                
                <div class="progress-step <?php echo in_array($job['application_status'], ['shortlisted', 'interviewed', 'offered', 'hired']) ? 'completed' : ''; ?>">
                    <div class="step-dot"></div>
                    <h4 class="font-semibold">Shortlisted</h4>
                    <p class="text-sm text-gray-600 dark:text-gray-400">You've been shortlisted for this position</p>
                </div>
                
                <div class="progress-step <?php echo in_array($job['application_status'], ['interviewed', 'offered', 'hired']) ? 'completed' : ''; ?>">
                    <div class="step-dot"></div>
                    <h4 class="font-semibold">Interview</h4>
                    <p class="text-sm text-gray-600 dark:text-gray-400">Interview scheduled or completed</p>
                </div>
                
                <div class="progress-step <?php echo in_array($job['application_status'], ['offered', 'hired']) ? 'completed' : ''; ?>">
                    <div class="step-dot"></div>
                    <h4 class="font-semibold">Offer</h4>
                    <p class="text-sm text-gray-600 dark:text-gray-400">Job offer extended</p>
                </div>
                
                <div class="progress-step <?php echo $job['application_status'] == 'hired' ? 'completed' : ''; ?>">
                    <div class="step-dot"></div>
                    <h4 class="font-semibold">Hired</h4>
                    <p class="text-sm text-gray-600 dark:text-gray-400">Congratulations! You've been hired</p>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Sidebar - Right Column -->
        <div class="lg:col-span-1">
            <div class="sticky-sidebar space-y-6">
                <!-- Company Info Card -->
                <div id="company" class="bg-white dark:bg-gray-800 rounded-3xl shadow-xl p-6" data-aos="fade-up">
                    <h3 class="text-xl font-bold mb-4 flex items-center">
                        <i class="fas fa-building text-indigo-600 mr-2"></i>
                        About Company
                    </h3>
                    
                    <div class="text-center mb-4">
                        <img src="<?php echo htmlspecialchars($job['company_logo'] ?? 'https://ui-avatars.com/api/?name='.urlencode($job['company_name']).'&background=4f46e5&color=fff&size=128'); ?>" 
                             alt="<?php echo htmlspecialchars($job['company_name']); ?>"
                             class="w-24 h-24 rounded-2xl mx-auto mb-3 object-cover border-4 border-indigo-100 dark:border-indigo-900">
                        <h4 class="font-bold text-lg"><?php echo htmlspecialchars($job['company_name']); ?></h4>
                        <?php if ($job['is_verified']): ?>
                        <span class="inline-block bg-blue-100 dark:bg-blue-900 text-blue-600 dark:text-blue-400 text-xs px-2 py-1 rounded-full mt-1">
                            <i class="fas fa-check-circle mr-1"></i> Verified Employer
                        </span>
                        <?php endif; ?>
                    </div>
                    
                    <div class="space-y-3 mb-4">
                        <?php if ($job['industry']): ?>
                        <p class="text-sm flex items-start">
                            <i class="fas fa-industry text-indigo-500 mt-1 mr-3 w-4"></i>
                            <span><?php echo htmlspecialchars($job['industry']); ?></span>
                        </p>
                        <?php endif; ?>
                        
                        <?php if ($job['company_size']): ?>
                        <p class="text-sm flex items-start">
                            <i class="fas fa-users text-indigo-500 mt-1 mr-3 w-4"></i>
                            <span><?php echo htmlspecialchars($job['company_size']); ?> employees</span>
                        </p>
                        <?php endif; ?>
                        
                        <?php if ($job['company_phone']): ?>
                        <p class="text-sm flex items-start">
                            <i class="fas fa-phone text-indigo-500 mt-1 mr-3 w-4"></i>
                            <a href="tel:<?php echo htmlspecialchars($job['company_phone']); ?>" class="contact-link hover:text-indigo-600">
                                <?php echo htmlspecialchars($job['company_phone']); ?>
                            </a>
                        </p>
                        <?php endif; ?>
                        
                        <?php if ($job['company_email']): ?>
                        <p class="text-sm flex items-start">
                            <i class="fas fa-envelope text-indigo-500 mt-1 mr-3 w-4"></i>
                            <a href="mailto:<?php echo htmlspecialchars($job['company_email']); ?>" class="contact-link hover:text-indigo-600">
                                <?php echo htmlspecialchars($job['company_email']); ?>
                            </a>
                        </p>
                        <?php endif; ?>
                        
                        <?php if ($job['company_website']): ?>
                        <p class="text-sm flex items-start">
                            <i class="fas fa-globe text-indigo-500 mt-1 mr-3 w-4"></i>
                            <a href="<?php echo htmlspecialchars($job['company_website']); ?>" target="_blank" class="contact-link hover:text-indigo-600 truncate">
                                <?php echo preg_replace('#^https?://#', '', $job['company_website']); ?>
                            </a>
                        </p>
                        <?php endif; ?>
                        
                        <?php if ($job['company_address'] || $job['company_city'] || $job['company_country']): ?>
                        <p class="text-sm flex items-start">
                            <i class="fas fa-map-pin text-indigo-500 mt-1 mr-3 w-4"></i>
                            <a href="https://www.google.com/maps/search/?api=1&query=<?php echo urlencode($job['company_address'] ?? $job['company_city'] . ', ' . $job['company_country']); ?>" 
                               target="_blank" class="contact-link hover:text-indigo-600">
                                <?php 
                                echo $job['company_address'] ? htmlspecialchars($job['company_address']) . '<br>' : '';
                                echo $job['company_city'] ? htmlspecialchars($job['company_city']) . ', ' : '';
                                echo htmlspecialchars($job['company_country'] ?? '');
                                ?>
                            </a>
                        </p>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Company Map -->
                    <?php if ($job['company_address'] || ($job['company_city'] && $job['company_country'])): ?>
                    <div class="map-container mb-4" id="companyMap"></div>
                    <?php endif; ?>
                    
                    <?php if (!empty($job['company_description'])): ?>
                    <div class="mb-4">
                        <p class="text-sm text-gray-600 dark:text-gray-400 line-clamp-3">
                            <?php echo htmlspecialchars(substr($job['company_description'], 0, 150)) . '...'; ?>
                        </p>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Company Rating -->
                    <?php if ($avgRating && $avgRating['avg_rating']): ?>
                    <div class="flex items-center justify-between mb-4 p-3 bg-yellow-50 dark:bg-yellow-900/30 rounded-xl">
                        <div class="flex items-center">
                            <div class="text-yellow-400 mr-2">
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <i class="fas fa-star <?php echo $i <= round($avgRating['avg_rating']) ? 'text-yellow-400' : 'text-gray-300'; ?>"></i>
                                <?php endfor; ?>
                            </div>
                            <span class="font-semibold"><?php echo number_format($avgRating['avg_rating'], 1); ?></span>
                        </div>
                        <span class="text-sm text-gray-600 dark:text-gray-400">(<?php echo $avgRating['total_reviews']; ?> reviews)</span>
                    </div>
                    <?php endif; ?>
                    
                    <a href="#company-jobs" class="block w-full bg-indigo-600 hover:bg-indigo-700 text-white text-center py-3 rounded-xl font-semibold transition-all duration-300">
                        View All Jobs from this Company
                    </a>
                </div>
                
                <!-- Job Overview Card -->
                <div class="bg-white dark:bg-gray-800 rounded-3xl shadow-xl p-6" data-aos="fade-up" data-aos-delay="50">
                    <h3 class="text-xl font-bold mb-4 flex items-center">
                        <i class="fas fa-info-circle text-indigo-600 mr-2"></i>
                        Job Overview
                    </h3>
                    
                    <div class="space-y-4">
                        <div class="flex items-start">
                            <div class="w-10 h-10 bg-indigo-100 dark:bg-indigo-900 rounded-xl flex items-center justify-center mr-3">
                                <i class="fas fa-calendar-alt text-indigo-600"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600 dark:text-gray-400">Date Posted</p>
                                <p class="font-medium"><?php echo date('F j, Y', strtotime($job['created_at'])); ?></p>
                            </div>
                        </div>
                        
                        <?php if ($job['application_deadline']): ?>
                        <div class="flex items-start">
                            <div class="w-10 h-10 bg-indigo-100 dark:bg-indigo-900 rounded-xl flex items-center justify-center mr-3">
                                <i class="fas fa-hourglass-end text-indigo-600"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600 dark:text-gray-400">Deadline</p>
                                <p class="font-medium"><?php echo date('F j, Y', strtotime($job['application_deadline'])); ?></p>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <div class="flex items-start">
                            <div class="w-10 h-10 bg-indigo-100 dark:bg-indigo-900 rounded-xl flex items-center justify-center mr-3">
                                <i class="fas fa-map-marker-alt text-indigo-600"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600 dark:text-gray-400">Location Type</p>
                                <p class="font-medium"><?php echo ucfirst($job['location_type'] ?? 'Onsite'); ?></p>
                                <?php if ($job['city'] || $job['country']): ?>
                                <p class="text-sm text-gray-500">
                                    <?php echo $job['city'] ? htmlspecialchars($job['city']) . ', ' : ''; ?>
                                    <?php echo htmlspecialchars($job['country'] ?? ''); ?>
                                </p>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="flex items-start">
                            <div class="w-10 h-10 bg-indigo-100 dark:bg-indigo-900 rounded-xl flex items-center justify-center mr-3">
                                <i class="fas fa-briefcase text-indigo-600"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600 dark:text-gray-400">Job Type</p>
                                <p class="font-medium"><?php echo htmlspecialchars($job['job_type_name'] ?? 'Full Time'); ?></p>
                            </div>
                        </div>
                        
                        <div class="flex items-start">
                            <div class="w-10 h-10 bg-indigo-100 dark:bg-indigo-900 rounded-xl flex items-center justify-center mr-3">
                                <i class="fas fa-tachometer-alt text-indigo-600"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600 dark:text-gray-400">Experience Level</p>
                                <p class="font-medium"><?php echo ucfirst($job['experience_level'] ?? 'Entry Level'); ?></p>
                            </div>
                        </div>
                        
                        <div class="flex items-start">
                            <div class="w-10 h-10 bg-indigo-100 dark:bg-indigo-900 rounded-xl flex items-center justify-center mr-3">
                                <i class="fas fa-dollar-sign text-indigo-600"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600 dark:text-gray-400">Salary</p>
                                <p class="font-medium"><?php echo $salaryDisplay; ?></p>
                            </div>
                        </div>
                        
                        <div class="flex items-start">
                            <div class="w-10 h-10 bg-indigo-100 dark:bg-indigo-900 rounded-xl flex items-center justify-center mr-3">
                                <i class="fas fa-users text-indigo-600"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600 dark:text-gray-400">Vacancies</p>
                                <p class="font-medium"><?php echo $job['total_vacancies'] ?? 1; ?></p>
                            </div>
                        </div>
                        
                        <div class="flex items-start">
                            <div class="w-10 h-10 bg-indigo-100 dark:bg-indigo-900 rounded-xl flex items-center justify-center mr-3">
                                <i class="fas fa-eye text-indigo-600"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600 dark:text-gray-400">Views</p>
                                <p class="font-medium"><?php echo number_format($job['total_views'] ?? 0); ?></p>
                            </div>
                        </div>
                        
                        <div class="flex items-start">
                            <div class="w-10 h-10 bg-indigo-100 dark:bg-indigo-900 rounded-xl flex items-center justify-center mr-3">
                                <i class="fas fa-file-signature text-indigo-600"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600 dark:text-gray-400">Applications</p>
                                <p class="font-medium"><?php echo number_format($job['total_applications'] ?? 0); ?></p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Share Job Card -->
                <div class="bg-white dark:bg-gray-800 rounded-3xl shadow-xl p-6" data-aos="fade-up" data-aos-delay="100">
                    <h3 class="text-xl font-bold mb-4 flex items-center">
                        <i class="fas fa-share-alt text-indigo-600 mr-2"></i>
                        Share This Job
                    </h3>
                    
                    <div class="flex flex-wrap gap-2 mb-3">
                        <a href="https://www.linkedin.com/sharing/share-offsite/?url=<?php echo urlencode($jobUrl); ?>" 
                           target="_blank"
                           class="flex-1 bg-[#0077b5] hover:bg-[#0066a0] text-white px-3 py-2 rounded-xl transition-all duration-300 text-center">
                            <i class="fab fa-linkedin-in"></i>
                        </a>
                        <a href="https://twitter.com/intent/tweet?text=<?php echo urlencode('Check out this job: ' . $job['title'] . ' at ' . $job['company_name']); ?>&url=<?php echo urlencode($jobUrl); ?>" 
                           target="_blank"
                           class="flex-1 bg-[#1DA1F2] hover:bg-[#1a8cd8] text-white px-3 py-2 rounded-xl transition-all duration-300 text-center">
                            <i class="fab fa-twitter"></i>
                        </a>
                        <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo urlencode($jobUrl); ?>" 
                           target="_blank"
                           class="flex-1 bg-[#4267B2] hover:bg-[#365899] text-white px-3 py-2 rounded-xl transition-all duration-300 text-center">
                            <i class="fab fa-facebook-f"></i>
                        </a>
                        <a href="https://api.whatsapp.com/send?text=<?php echo urlencode('Check out this job: ' . $job['title'] . ' at ' . $job['company_name'] . ' - ' . $jobUrl); ?>" 
                           target="_blank"
                           class="flex-1 bg-[#25D366] hover:bg-[#20b859] text-white px-3 py-2 rounded-xl transition-all duration-300 text-center">
                            <i class="fab fa-whatsapp"></i>
                        </a>
                    </div>
                    
                    <div class="flex gap-2">
                        <button onclick="openQRModal()" class="flex-1 bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-700 hover:to-indigo-700 text-white px-4 py-3 rounded-xl transition-all duration-300 flex items-center justify-center">
                            <i class="fas fa-qrcode mr-2"></i>
                            QR Code
                        </button>
                        <button onclick="copyJobLink()" class="flex-1 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-300 px-4 py-3 rounded-xl transition-all duration-300 flex items-center justify-center">
                            <i class="fas fa-link mr-2"></i>
                            Copy Link
                        </button>
                    </div>
                </div>
                
                <!-- Report Job Card -->
                <div class="bg-white dark:bg-gray-800 rounded-3xl shadow-xl p-6" data-aos="fade-up" data-aos-delay="150">
                    <button onclick="reportJob()" class="w-full text-red-600 hover:text-red-700 flex items-center justify-center">
                        <i class="fas fa-flag mr-2"></i>
                        Report this job
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Similar Jobs Section -->
    <?php if (!empty($similarJobs)): ?>
    <div id="company-jobs" class="mt-16" data-aos="fade-up">
        <h2 class="text-3xl font-bold mb-8 flex items-center">
            <i class="fas fa-briefcase text-indigo-600 mr-3"></i>
            Similar Jobs You Might Like
        </h2>
        
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
            <?php foreach ($similarJobs as $similar): ?>
            <div class="group relative bg-white dark:bg-gray-800 rounded-2xl shadow-xl overflow-hidden hover-card">
                <div class="p-6">
                    <div class="flex items-center mb-4">
                        <img src="<?php echo htmlspecialchars($similar['company_logo'] ?? 'https://ui-avatars.com/api/?name='.urlencode($similar['company_name']).'&background=4f46e5&color=fff'); ?>" 
                             alt="<?php echo htmlspecialchars($similar['company_name']); ?>"
                             class="w-16 h-16 rounded-xl object-cover border-2 border-gray-200 dark:border-gray-700">
                    </div>
                    
                    <h3 class="text-xl font-bold mb-2 group-hover:text-indigo-600 transition">
                        <a href="job-details.php?slug=<?php echo htmlspecialchars($similar['slug']); ?>">
                            <?php echo htmlspecialchars($similar['title']); ?>
                        </a>
                    </h3>
                    
                    <p class="text-gray-600 dark:text-gray-400 mb-4">
                        <?php echo htmlspecialchars($similar['company_name']); ?>
                    </p>
                    
                    <div class="flex items-center justify-between text-sm mb-4">
                        <span class="text-gray-500 dark:text-gray-500">
                            <i class="fas fa-map-marker-alt mr-1 text-indigo-500"></i>
                            <?php echo ucfirst($similar['location_type']); ?>
                        </span>
                        <span class="text-gray-500 dark:text-gray-500">
                            <i class="far fa-clock mr-1"></i>
                            <?php 
                            $timeDiff = time() - strtotime($similar['created_at']);
                            echo floor($timeDiff / 86400) . 'd ago';
                            ?>
                        </span>
                    </div>
                    
                    <div class="flex items-center justify-between">
                        <span class="px-3 py-1 bg-indigo-100 dark:bg-indigo-900 text-indigo-600 dark:text-indigo-400 text-sm rounded-full">
                            <?php echo htmlspecialchars($similar['job_type_name'] ?? 'Full Time'); ?>
                        </span>
                        
                        <button onclick="quickSaveJob(<?php echo $similar['id']; ?>, this)" 
                                class="text-gray-400 hover:text-indigo-600 transition">
                            <i class="fas <?php echo $similar['is_saved'] ? 'fa-bookmark text-indigo-600' : 'fa-bookmark'; ?>"></i>
                        </button>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Company Reviews -->
    <?php if (!empty($companyReviews)): ?>
    <div class="mt-16" data-aos="fade-up">
        <h2 class="text-3xl font-bold mb-8 flex items-center">
            <i class="fas fa-star text-yellow-400 mr-3"></i>
            What People Say About <?php echo htmlspecialchars($job['company_name']); ?>
        </h2>
        
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <?php foreach ($companyReviews as $review): ?>
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6">
                <div class="flex items-center mb-4">
                    <img src="<?php echo htmlspecialchars($review['profile_picture'] ?? 'https://ui-avatars.com/api/?name='.urlencode($review['first_name'].'+'.$review['last_name']).'&background=4f46e5&color=fff'); ?>" 
                         alt="<?php echo htmlspecialchars($review['first_name']); ?>"
                         class="w-12 h-12 rounded-full mr-4 object-cover">
                    <div>
                        <h4 class="font-semibold"><?php echo htmlspecialchars($review['first_name'] . ' ' . $review['last_name']); ?></h4>
                        <div class="flex text-yellow-400 text-sm">
                            <?php for($i = 1; $i <= 5; $i++): ?>
                                <i class="fas fa-star <?php echo $i <= $review['rating'] ? 'text-yellow-400' : 'text-gray-300'; ?>"></i>
                            <?php endfor; ?>
                        </div>
                    </div>
                </div>
                
                <p class="text-gray-600 dark:text-gray-400 text-sm italic">
                    "<?php echo htmlspecialchars(substr($review['review_text'] ?? 'Great company to work with!', 0, 100)) . '...'; ?>"
                </p>
                
                <p class="text-xs text-gray-500 mt-2">
                    <?php echo date('F j, Y', strtotime($review['created_at'])); ?>
                </p>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Apply Modal -->
<div id="applyModal" class="fixed inset-0 z-50 hidden overflow-y-auto no-print" aria-labelledby="modal-title" role="dialog" aria-modal="true">
    <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
        <div class="fixed inset-0 bg-gray-500 bg-opacity-75 dark:bg-gray-900 dark:bg-opacity-75 transition-opacity" aria-hidden="true"></div>
        
        <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
        
        <div class="inline-block align-bottom bg-white dark:bg-gray-800 rounded-3xl text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-2xl sm:w-full">
            <div class="bg-gradient-to-r from-indigo-600 to-purple-600 px-6 py-4">
                <div class="flex items-center justify-between">
                    <h3 class="text-2xl font-bold text-white" id="modal-title">
                        Apply for <?php echo htmlspecialchars($job['title']); ?>
                    </h3>
                    <button onclick="closeApplyModal()" class="text-white hover:text-gray-200 transition">
                        <i class="fas fa-times text-2xl"></i>
                    </button>
                </div>
            </div>
            
            <form id="applyForm" class="p-6" enctype="multipart/form-data">
                <input type="hidden" name="job_id" value="<?php echo $job['id']; ?>">
                <input type="hidden" name="employer_id" value="<?php echo $job['employer_id']; ?>">
                
                <!-- Cover Letter -->
                <div class="mb-6">
                    <label class="block text-sm font-medium mb-2">Cover Letter <span class="text-red-500">*</span></label>
                    <div id="coverLetterEditor" style="height: 200px;"></div>
                    <textarea name="cover_letter" id="coverLetter" class="hidden"></textarea>
                    <p class="text-sm text-gray-500 mt-2">Introduce yourself and explain why you're a good fit for this position.</p>
                </div>
                
                <!-- Resume Selection - Enhanced with existing resume detection -->
                <div class="mb-6">
                    <label class="block text-sm font-medium mb-3">Resume <span class="text-red-500">*</span></label>
                    
                    <?php if (!empty($seekerResume)): ?>
                    <!-- Existing Resume Option -->
                    <div class="mb-4 p-4 bg-green-50 dark:bg-green-900/30 rounded-xl border-2 border-green-200 dark:border-green-800">
                        <div class="flex items-center mb-3">
                            <input type="radio" name="resume_option" id="use_existing" value="existing" checked class="w-4 h-4 text-indigo-600 focus:ring-indigo-500">
                            <label for="use_existing" class="ml-3 font-medium">Use existing resume</label>
                        </div>
                        <div class="ml-7 resume-preview">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center">
                                    <i class="fas fa-file-pdf text-red-500 text-2xl mr-3"></i>
                                    <div>
                                        <p class="font-medium"><?php echo basename($seekerResume); ?></p>
                                        <p class="text-xs text-gray-500">Uploaded on your profile</p>
                                    </div>
                                </div>
                                <a href="<?php echo htmlspecialchars($seekerResume); ?>" target="_blank" class="text-indigo-600 hover:text-indigo-700">
                                    <i class="fas fa-external-link-alt"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Upload New Resume Option -->
                    <div class="mb-4 p-4 bg-blue-50 dark:bg-blue-900/30 rounded-xl border-2 border-blue-200 dark:border-blue-800">
                        <div class="flex items-center mb-3">
                            <input type="radio" name="resume_option" id="upload_new" value="new" <?php echo empty($seekerResume) ? 'checked' : ''; ?> class="w-4 h-4 text-indigo-600 focus:ring-indigo-500">
                            <label for="upload_new" class="ml-3 font-medium">Upload new resume</label>
                        </div>
                        <div class="ml-7">
                            <div class="flex flex-col sm:flex-row sm:items-center gap-3">
                                <input type="file" name="resume" id="resume" accept=".pdf,.doc,.docx" class="hidden">
                                <button type="button" onclick="document.getElementById('resume').click()" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm transition">
                                    <i class="fas fa-upload mr-2"></i> Choose File
                                </button>
                                <span id="resumeName" class="text-sm text-gray-600 dark:text-gray-400">No file chosen</span>
                            </div>
                            <p class="text-xs text-gray-500 mt-2">Accepted formats: PDF, DOC, DOCX (Max 5MB)</p>
                        </div>
                    </div>
                    
                    <!-- Update Profile Option -->
                    <?php if (!empty($seekerResume)): ?>
                    <div class="flex items-center text-sm">
                        <input type="checkbox" name="update_profile_resume" id="update_profile_resume" class="rounded text-indigo-600 focus:ring-indigo-500 mr-2">
                        <label for="update_profile_resume">Also update my profile with this new resume</label>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Expected Salary -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                    <div>
                        <label class="block text-sm font-medium mb-2">Expected Salary (<?php echo $job['salary_currency'] ?? 'USD'; ?>)</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <span class="text-gray-500 sm:text-sm">$</span>
                            </div>
                            <input type="number" name="expected_salary" id="expectedSalary" 
                                   class="w-full pl-8 px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700"
                                   placeholder="e.g., 5000"
                                   value="<?php echo htmlspecialchars($seekerProfile['expected_salary_min'] ?? ''); ?>">
                        </div>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium mb-2">Notice Period (Days)</label>
                        <select name="notice_period" id="noticePeriod" 
                                class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700">
                            <option value="0">Immediately</option>
                            <option value="7">1 week</option>
                            <option value="15">2 weeks</option>
                            <option value="30" selected>1 month</option>
                            <option value="45">1.5 months</option>
                            <option value="60">2 months</option>
                            <option value="90">3 months</option>
                        </select>
                    </div>
                </div>
                
                <!-- Additional Questions -->
                <div class="mb-6">
                    <label class="block text-sm font-medium mb-2">Why should we hire you? (Optional)</label>
                    <textarea name="additional_info" rows="3" 
                              class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700"
                              placeholder="Tell us more about your relevant experience and skills..."></textarea>
                </div>
                
                <!-- Quick Apply Options -->
                <div class="mb-6 grid grid-cols-1 md:grid-cols-2 gap-4">
                    <label class="flex items-center p-3 bg-gray-50 dark:bg-gray-700 rounded-xl cursor-pointer hover:bg-indigo-50 dark:hover:bg-indigo-900/30 transition">
                        <input type="checkbox" name="notify_by_email" checked class="rounded text-indigo-600 focus:ring-indigo-500 mr-3">
                        <span class="text-sm">Email me application updates</span>
                    </label>
                    
                    <label class="flex items-center p-3 bg-gray-50 dark:bg-gray-700 rounded-xl cursor-pointer hover:bg-indigo-50 dark:hover:bg-indigo-900/30 transition">
                        <input type="checkbox" name="save_to_profile" checked class="rounded text-indigo-600 focus:ring-indigo-500 mr-3">
                        <span class="text-sm">Save this application to profile</span>
                    </label>
                </div>
                
                <!-- Terms -->
                <div class="mb-6 p-4 bg-gray-50 dark:bg-gray-700 rounded-xl">
                    <label class="flex items-start">
                        <input type="checkbox" name="terms" id="terms" class="rounded text-indigo-600 focus:ring-indigo-500 mr-3 mt-1">
                        <span class="text-sm">I confirm that the information provided is accurate and I agree to the <a href="#" class="text-indigo-600 hover:underline">terms and conditions</a> and <a href="#" class="text-indigo-600 hover:underline">privacy policy</a>.</span>
                    </label>
                </div>
                
                <!-- Submit Buttons -->
                <div class="flex flex-col sm:flex-row space-y-3 sm:space-y-0 sm:space-x-4">
                    <button type="submit" id="submitApplication" class="flex-1 bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-700 hover:to-purple-700 text-white px-6 py-4 rounded-xl font-semibold transition-all duration-300 transform hover:scale-105">
                        <i class="fas fa-paper-plane mr-2"></i>
                        Submit Application
                    </button>
                    <button type="button" onclick="closeApplyModal()" class="px-6 py-4 border-2 border-gray-300 dark:border-gray-600 rounded-xl font-semibold hover:bg-gray-100 dark:hover:bg-gray-700 transition">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Footer -->
<footer class="bg-gray-900 text-white pt-20 pb-8 mt-20 no-print">
    <div class="container mx-auto px-4">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-12 mb-16">
            <!-- About -->
            <div>
                <div class="flex items-center space-x-3 mb-6">
                    <div class="w-12 h-12 bg-gradient-to-r from-indigo-600 to-purple-600 rounded-xl flex items-center justify-center">
                        <i class="fas fa-globe-asia text-white text-2xl"></i>
                    </div>
                    <span class="text-2xl font-bold">
                        <span class="gradient-text">TransNow</span>
                    </span>
                </div>
                <p class="text-gray-400 mb-6">
                    Connecting Chinese students and international talents with premier global opportunities.
                </p>
            </div>
            
            <!-- Quick Links -->
            <div>
                <h3 class="text-xl font-bold mb-6">Quick Links</h3>
                <ul class="space-y-3 text-gray-400">
                    <li><a href="jobs.php" class="hover:text-white transition">Browse Jobs</a></li>
                    <li><a href="dashboard.php" class="hover:text-white transition">Dashboard</a></li>
                    <li><a href="profile.php" class="hover:text-white transition">Profile</a></li>
                    <li><a href="applications.php" class="hover:text-white transition">Applications</a></li>
                </ul>
            </div>
            
            <!-- Categories -->
            <div>
                <h3 class="text-xl font-bold mb-6">Categories</h3>
                <ul class="space-y-3 text-gray-400">
                    <li><a href="jobs.php?category=technology" class="hover:text-white transition">Technology</a></li>
                    <li><a href="jobs.php?category=finance" class="hover:text-white transition">Finance</a></li>
                    <li><a href="jobs.php?category=marketing" class="hover:text-white transition">Marketing</a></li>
                    <li><a href="jobs.php?category=engineering" class="hover:text-white transition">Engineering</a></li>
                </ul>
            </div>
            
            <!-- Support -->
            <div>
                <h3 class="text-xl font-bold mb-6">Support</h3>
                <ul class="space-y-3 text-gray-400">
                    <li><a href="#" class="hover:text-white transition">Help Center</a></li>
                    <li><a href="#" class="hover:text-white transition">FAQs</a></li>
                    <li><a href="#" class="hover:text-white transition">Contact Us</a></li>
                    <li><a href="#" class="hover:text-white transition">Privacy Policy</a></li>
                </ul>
            </div>
        </div>
        
        <div class="border-t border-gray-800 pt-8 text-center text-gray-400">
            <p>&copy; <?php echo date('Y'); ?> TransNow. All rights reserved.</p>
        </div>
    </div>
</footer>

<!-- Scripts -->
<script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.quilljs.com/1.3.6/quill.js"></script>

<script>
// Initialize AOS
AOS.init({
    duration: 1000,
    once: true,
    offset: 100,
    easing: 'ease-out-cubic'
});

// Initialize company map if location exists
<?php if ($job['company_address'] || ($job['company_city'] && $job['company_country'])): ?>
document.addEventListener('DOMContentLoaded', function() {
    const mapContainer = document.getElementById('companyMap');
    if (mapContainer) {
        // Use OpenStreetMap/Nominatim to get coordinates
        const address = '<?php echo addslashes($job['company_address'] ?? $job['company_city'] . ', ' . $job['company_country']); ?>';
        
        fetch(`https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(address)}`)
            .then(response => response.json())
            .then(data => {
                if (data && data[0]) {
                    const lat = parseFloat(data[0].lat);
                    const lon = parseFloat(data[0].lon);
                    
                    const map = L.map(mapContainer).setView([lat, lon], 13);
                    
                    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                        attribution: '© OpenStreetMap contributors'
                    }).addTo(map);
                    
                    L.marker([lat, lon]).addTo(map)
                        .bindPopup('<?php echo addslashes($job['company_name']); ?>')
                        .openPopup();
                } else {
                    mapContainer.innerHTML = '<div class="flex items-center justify-center h-full bg-gray-100 dark:bg-gray-700 rounded-xl"><p class="text-gray-500">Map location not available</p></div>';
                }
            })
            .catch(() => {
                mapContainer.innerHTML = '<div class="flex items-center justify-center h-full bg-gray-100 dark:bg-gray-700 rounded-xl"><p class="text-gray-500">Map location not available</p></div>';
            });
    }
});
<?php endif; ?>

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
    darkModeIcon?.classList.remove('fa-moon');
    darkModeIcon?.classList.add('fa-sun');
} else {
    document.documentElement.classList.remove('dark');
    darkModeIcon?.classList.remove('fa-sun');
    darkModeIcon?.classList.add('fa-moon');
}

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

// Mobile Menu
const mobileMenuBtn = document.getElementById('mobileMenuBtn');
const mobileMenu = document.getElementById('mobileMenu');

mobileMenuBtn.addEventListener('click', () => {
    mobileMenu.classList.toggle('hidden');
    const icon = mobileMenuBtn.querySelector('i');
    icon.classList.toggle('fa-bars');
    icon.classList.toggle('fa-times');
});

// QR Code Modal Functions
function openQRModal() {
    document.getElementById('qrModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeQRModal() {
    document.getElementById('qrModal').style.display = 'none';
    document.body.style.overflow = 'auto';
}

function downloadQRCode() {
    const link = document.createElement('a');
    link.href = '<?php echo $qrCodeUrl; ?>';
    link.download = '<?php echo preg_replace('/[^a-zA-Z0-9]/', '_', $job['title']); ?>_QR.png';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    
    Swal.fire({
        icon: 'success',
        title: 'QR Code Downloaded!',
        showConfirmButton: false,
        timer: 1500
    });
}

// Apply Modal Functions
function openApplyModal() {
    document.getElementById('applyModal').classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}

function closeApplyModal() {
    document.getElementById('applyModal').classList.add('hidden');
    document.body.style.overflow = 'auto';
}

// Initialize Quill Editor
var quill = new Quill('#coverLetterEditor', {
    theme: 'snow',
    placeholder: 'Write your cover letter here...',
    modules: {
        toolbar: [
            ['bold', 'italic', 'underline'],
            [{ 'list': 'ordered'}, { 'list': 'bullet' }],
            ['clean']
        ]
    }
});

// Update hidden textarea on change
quill.on('text-change', function() {
    document.getElementById('coverLetter').value = quill.root.innerHTML;
});

// File input handling
document.getElementById('resume').addEventListener('change', function(e) {
    const fileName = e.target.files[0] ? e.target.files[0].name : 'No file chosen';
    document.getElementById('resumeName').textContent = fileName;
    document.getElementById('upload_new').checked = true;
});

// Save/Unsave Job
function saveJob(jobId) {
    const btn = document.getElementById('saveJobBtn');
    const btnText = document.getElementById('saveBtnText');
    const icon = btn.querySelector('i');
    
    fetch('ajax/save-job.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'job_id=' + jobId + '&action=' + (btnText.textContent === 'Saved' ? 'unsave' : 'save')
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            if (data.saved) {
                icon.classList.add('text-indigo-600');
                btnText.textContent = 'Saved';
                btn.classList.add('bg-indigo-50', 'dark:bg-indigo-900/30');
                Swal.fire({
                    icon: 'success',
                    title: 'Job Saved!',
                    text: 'This job has been added to your saved jobs',
                    showConfirmButton: false,
                    timer: 1500
                });
            } else {
                icon.classList.remove('text-indigo-600');
                btnText.textContent = 'Save Job';
                btn.classList.remove('bg-indigo-50', 'dark:bg-indigo-900/30');
                Swal.fire({
                    icon: 'success',
                    title: 'Job Unsaved',
                    text: 'This job has been removed from your saved jobs',
                    showConfirmButton: false,
                    timer: 1500
                });
            }
        }
    });
}

// Quick save for similar jobs
function quickSaveJob(jobId, btn) {
    const icon = btn.querySelector('i');
    const isSaved = icon.classList.contains('fa-bookmark') && icon.classList.contains('text-indigo-600');
    
    fetch('ajax/save-job.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'job_id=' + jobId + '&action=' + (isSaved ? 'unsave' : 'save')
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            if (data.saved) {
                icon.classList.add('text-indigo-600');
                Swal.fire({
                    icon: 'success',
                    title: 'Saved!',
                    showConfirmButton: false,
                    timer: 1000
                });
            } else {
                icon.classList.remove('text-indigo-600');
            }
        }
    });
}

// Copy job link
function copyJobLink() {
    const url = '<?php echo $jobUrl; ?>';
    navigator.clipboard.writeText(url).then(() => {
        Swal.fire({
            icon: 'success',
            title: 'Link Copied!',
            text: 'Job link has been copied to clipboard',
            showConfirmButton: false,
            timer: 1500
        });
    });
}

// Report job
function reportJob() {
    Swal.fire({
        title: 'Report this job?',
        text: 'Please let us know why you are reporting this job posting',
        input: 'textarea',
        inputPlaceholder: 'Reason for reporting...',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        confirmButtonText: 'Submit Report',
        cancelButtonText: 'Cancel',
        inputValidator: (value) => {
            if (!value) {
                return 'Please provide a reason'
            }
        }
    }).then((result) => {
        if (result.isConfirmed) {
            fetch('ajax/report-job.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'job_id=<?php echo $job['id']; ?>&reason=' + encodeURIComponent(result.value)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire(
                        'Reported!',
                        'Thank you for helping us maintain quality job postings.',
                        'success'
                    );
                }
            });
        }
    });
}

// Handle Application Form Submit
document.getElementById('applyForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    // Get form data
    const coverLetter = quill.root.innerText.trim();
    const resumeOption = document.querySelector('input[name="resume_option"]:checked');
    const terms = document.getElementById('terms').checked;
    
    // Validate
    if (!coverLetter) {
        Swal.fire({
            icon: 'error',
            title: 'Cover Letter Required',
            text: 'Please write a cover letter for your application'
        });
        return;
    }
    
    if (!resumeOption) {
        Swal.fire({
            icon: 'error',
            title: 'Resume Required',
            text: 'Please select or upload a resume'
        });
        return;
    }
    
    if (resumeOption.value === 'new' && !document.getElementById('resume').files[0]) {
        Swal.fire({
            icon: 'error',
            title: 'Resume Required',
            text: 'Please select a resume file to upload'
        });
        return;
    }
    
    if (!terms) {
        Swal.fire({
            icon: 'error',
            title: 'Terms Required',
            text: 'Please accept the terms and conditions'
        });
        return;
    }
    
    // Show loading
    Swal.fire({
        title: 'Submitting Application...',
        html: 'Please wait while we process your application',
        allowOutsideClick: false,
        didOpen: () => {
            Swal.showLoading();
        }
    });
    
    // Create FormData for file upload
    const formData = new FormData(this);
    formData.append('cover_letter', quill.root.innerHTML);
    
    // Submit via AJAX
    fetch('ajax/submit-application.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            Swal.fire({
                icon: 'success',
                title: 'Application Submitted!',
                html: 'Your application has been sent successfully.<br>You will be notified of any updates.',
                confirmButtonColor: '#4f46e5'
            }).then(() => {
                closeApplyModal();
                window.location.reload();
            });
        } else {
            Swal.fire({
                icon: 'error',
                title: 'Submission Failed',
                text: data.message || 'Please try again later'
            });
        }
    })
    .catch(error => {
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'An error occurred. Please try again.'
        });
    });
});

// Close modal when clicking outside
window.addEventListener('click', function(e) {
    const modal = document.getElementById('applyModal');
    if (e.target === modal) {
        closeApplyModal();
    }
});

// Close QR modal with Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeQRModal();
        closeApplyModal();
        mobileMenu?.classList.add('hidden');
    }
});

// Smooth scroll for anchor links
document.querySelectorAll('a[href^="#"]').forEach(anchor => {
    anchor.addEventListener('click', function (e) {
        e.preventDefault();
        const target = document.querySelector(this.getAttribute('href'));
        if (target) {
            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    });
});

// Auto-populate cover letter template if empty
document.addEventListener('DOMContentLoaded', function() {
    const savedCoverLetter = localStorage.getItem('cover_letter_template');
    if (savedCoverLetter && quill.root.innerText.trim() === '') {
        Swal.fire({
            title: 'Use saved template?',
            text: 'Would you like to use your saved cover letter template?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Yes, use it',
            cancelButtonText: 'No, write new'
        }).then((result) => {
            if (result.isConfirmed) {
                quill.root.innerHTML = savedCoverLetter;
            }
        });
    }
});

// Save cover letter as template
document.addEventListener('keydown', function(e) {
    if (e.ctrlKey && e.key === 's') {
        e.preventDefault();
        const coverLetter = quill.root.innerHTML;
        if (coverLetter) {
            localStorage.setItem('cover_letter_template', coverLetter);
            Swal.fire({
                icon: 'success',
                title: 'Template Saved!',
                text: 'Cover letter template saved for future applications',
                timer: 1500,
                showConfirmButton: false
            });
        }
    }
});
</script>

<!-- Structured Data for SEO -->
<script type="application/ld+json">
{
    "@context": "https://schema.org",
    "@type": "JobPosting",
    "title": "<?php echo htmlspecialchars($job['title']); ?>",
    "description": "<?php echo htmlspecialchars(substr(strip_tags($job['description']), 0, 500)); ?>",
    "identifier": {
        "@type": "PropertyValue",
        "name": "<?php echo htmlspecialchars($job['company_name']); ?>",
        "value": "<?php echo $job['id']; ?>"
    },
    "datePosted": "<?php echo date('c', strtotime($job['created_at'])); ?>",
    <?php if ($job['application_deadline']): ?>
    "validThrough": "<?php echo date('c', strtotime($job['application_deadline'])); ?>",
    <?php endif; ?>
    "employmentType": "<?php echo strtoupper($job['job_type_name'] ?? 'FULL_TIME'); ?>",
    "hiringOrganization": {
        "@type": "Organization",
        "name": "<?php echo htmlspecialchars($job['company_name']); ?>",
        <?php if ($job['company_logo']): ?>
        "logo": "<?php echo htmlspecialchars($job['company_logo']); ?>",
        <?php endif; ?>
        "sameAs": "<?php echo htmlspecialchars($job['company_website'] ?? ''); ?>",
        "address": {
            "@type": "PostalAddress",
            <?php if ($job['company_address']): ?>
            "streetAddress": "<?php echo htmlspecialchars($job['company_address']); ?>",
            <?php endif; ?>
            <?php if ($job['company_city']): ?>
            "addressLocality": "<?php echo htmlspecialchars($job['company_city']); ?>",
            <?php endif; ?>
            <?php if ($job['country']): ?>
            "addressCountry": "<?php echo htmlspecialchars($job['company_country'] ?? $job['country']); ?>"
            <?php endif; ?>
        },
        "telephone": "<?php echo htmlspecialchars($job['company_phone'] ?? ''); ?>",
        "email": "<?php echo htmlspecialchars($job['company_email'] ?? ''); ?>"
    },
    "jobLocation": {
        "@type": "Place",
        "address": {
            "@type": "PostalAddress",
            <?php if ($job['city']): ?>
            "addressLocality": "<?php echo htmlspecialchars($job['city']); ?>",
            <?php endif; ?>
            <?php if ($job['country']): ?>
            "addressCountry": "<?php echo htmlspecialchars($job['country']); ?>"
            <?php endif; ?>
        }
    },
    <?php if ($job['salary_min'] && $job['salary_max']): ?>
    "baseSalary": {
        "@type": "MonetaryAmount",
        "currency": "<?php echo $job['salary_currency'] ?? 'USD'; ?>",
        "value": {
            "@type": "QuantitativeValue",
            "minValue": <?php echo $job['salary_min']; ?>,
            "maxValue": <?php echo $job['salary_max']; ?>,
            "unitText": "<?php echo strtoupper($job['salary_type'] ?? 'YEAR'); ?>"
        }
    },
    <?php endif; ?>
    "experienceRequirements": "<?php echo ucfirst($job['experience_level'] ?? 'Entry Level'); ?>",
    "jobBenefits": "<?php echo htmlspecialchars(substr(implode(', ', $benefits), 0, 200)); ?>"
}
</script>

</body>
</html>