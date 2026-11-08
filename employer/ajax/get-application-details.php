<?php
// employer/ajax/get-application-details.php
// AJAX endpoint for quick view modal

session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

// Require employer login
requireEmployerLogin();

// Get database connection
$pdo = getDB();
$employer_id = getCurrentEmployerId($pdo);

if (!isset($_GET['id'])) {
    die('<div class="text-center text-red-600 p-8">Application ID not provided</div>');
}

$application_id = (int)$_GET['id'];

// Get application details
$application = fetchOne("SELECT 
    a.*,
    j.id as job_id,
    j.title as job_title,
    j.description as job_description,
    j.requirements as job_requirements,
    j.salary_min,
    j.salary_max,
    j.salary_currency,
    j.location_type,
    j.city,
    j.country,
    s.id as seeker_id,
    s.first_name,
    s.last_name,
    s.phone,
    s.whatsapp_number,
    s.profile_picture,
    s.headline,
    s.bio,
    s.resume_url,
    s.location,
    s.current_job_title,
    s.current_company,
    s.years_experience,
    s.highest_education,
    s.skills,
    s.expected_salary_min,
    s.expected_salary_max,
    u.email as seeker_email,
    (SELECT COUNT(*) FROM applications WHERE seeker_id = s.id) as total_applications
FROM applications a
JOIN jobs j ON a.job_id = j.id
JOIN seekers s ON a.seeker_id = s.id
JOIN users u ON s.user_id = u.id
WHERE a.id = ? AND j.employer_id = ?", [$application_id, $employer_id]);

if (!$application) {
    die('<div class="text-center text-red-600 p-8">Application not found</div>');
}

// Get seeker languages
$languages = fetchAll("SELECT l.name, sl.proficiency_level 
                       FROM seeker_languages sl
                       JOIN languages l ON sl.language_id = l.id
                       WHERE sl.seeker_id = ?", [$application['seeker_id']]);

// Get application timeline
$timeline = fetchAll("SELECT 
    'applied' as event_type,
    a.created_at as event_date,
    'Application submitted' as description
FROM applications a
WHERE a.id = ?
UNION
SELECT 
    'status_change' as event_type,
    a.updated_at as event_date,
    CONCAT('Status changed to ', a.status) as description
FROM applications a
WHERE a.id = ? AND a.updated_at > a.created_at
ORDER BY event_date DESC", [$application_id, $application_id]);
?>

<div class="relative">
    <!-- Close button -->
    <button onclick="closeModal()" class="absolute top-4 right-4 text-gray-400 hover:text-gray-600 transition">
        <i class="fas fa-times text-xl"></i>
    </button>
    
    <!-- Header -->
    <div class="border-b border-gray-200 pb-4 mb-4">
        <h2 class="text-2xl font-bold text-gray-800">Application Details</h2>
        <p class="text-sm text-gray-500 mt-1">ID: #<?= str_pad($application['id'], 6, '0', STR_PAD_LEFT) ?></p>
    </div>
    
    <div class="space-y-6">
        <!-- Candidate Info -->
        <div class="flex items-start space-x-4">
            <?php if (!empty($application['profile_picture'])): ?>
                <img src="<?= htmlspecialchars($application['profile_picture']) ?>" 
                     alt="Profile" 
                     class="h-20 w-20 rounded-full object-cover">
            <?php else: ?>
                <div class="h-20 w-20 rounded-full bg-gradient-to-r from-blue-500 to-blue-600 flex items-center justify-center text-white text-2xl font-bold">
                    <?= strtoupper(substr($application['first_name'] ?? 'U', 0, 1) . substr($application['last_name'] ?? 'S', 0, 1)) ?>
                </div>
            <?php endif; ?>
            
            <div class="flex-1">
                <h3 class="text-xl font-semibold text-gray-900">
                    <?= htmlspecialchars($application['first_name'] . ' ' . $application['last_name']) ?>
                </h3>
                <p class="text-gray-600"><?= htmlspecialchars($application['headline'] ?? $application['current_job_title'] ?? 'Job Seeker') ?></p>
                
                <div class="mt-2 flex flex-wrap gap-2">
                    <?php if ($application['current_company']): ?>
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                            <i class="fas fa-building mr-1"></i> <?= htmlspecialchars($application['current_company']) ?>
                        </span>
                    <?php endif; ?>
                    
                    <?php if ($application['years_experience'] > 0): ?>
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                            <i class="fas fa-briefcase mr-1"></i> <?= $application['years_experience'] ?> years exp
                        </span>
                    <?php endif; ?>
                    
                    <?php if ($application['highest_education']): ?>
                        <span class="inline-flex items