<?php
// public/seekers/preferences.php - Job Seeker Preferences Page

// Check if session is already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and is a seeker
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'seeker') {
    header("Location: ../../login.php");
    exit();
}

// Database connection
require_once __DIR__ . '/../../config/database.php';
$db = Database::getInstance()->getConnection();

$userId = $_SESSION['user_id'];
$errors = [];
$success = false;

// Get seeker information
try {
    $seekerQuery = "SELECT s.*, u.email 
                    FROM seekers s 
                    JOIN users u ON s.user_id = u.id 
                    WHERE s.user_id = :user_id";
    $stmt = $db->prepare($seekerQuery);
    $stmt->execute(['user_id' => $userId]);
    $seeker = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$seeker) {
        header("Location: dashboard.php");
        exit();
    }
} catch (PDOException $e) {
    error_log("Seeker query error: " . $e->getMessage());
    header("Location: dashboard.php");
    exit();
}

// Get existing preferences
try {
    $prefQuery = "SELECT * FROM seeker_preferences WHERE seeker_id = :seeker_id";
    $stmt = $db->prepare($prefQuery);
    $stmt->execute(['seeker_id' => $userId]);
    $preferences = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$preferences) {
        // Create default preferences if none exist
        $defaultPrefQuery = "INSERT INTO seeker_preferences (seeker_id, created_at) VALUES (:seeker_id, NOW())";
        $stmt = $db->prepare($defaultPrefQuery);
        $stmt->execute(['seeker_id' => $userId]);
        
        // Fetch the newly created preferences
        $stmt = $db->prepare($prefQuery);
        $stmt->execute(['seeker_id' => $userId]);
        $preferences = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // Decode JSON fields
    $preferences['preferred_categories'] = json_decode($preferences['preferred_categories'] ?? '[]', true) ?? [];
    $preferences['preferred_job_types'] = json_decode($preferences['preferred_job_types'] ?? '[]', true) ?? [];
    $preferences['preferred_location_types'] = json_decode($preferences['preferred_location_types'] ?? '[]', true) ?? [];
    $preferences['preferred_locations'] = json_decode($preferences['preferred_locations'] ?? '[]', true) ?? [];
    
} catch (PDOException $e) {
    error_log("Preferences query error: " . $e->getMessage());
    $preferences = [
        'preferred_categories' => [],
        'preferred_job_types' => [],
        'preferred_location_types' => [],
        'preferred_locations' => [],
        'min_salary' => null,
        'max_salary' => null,
        'experience_level_preference' => 'any',
        'notification_frequency' => 'daily'
    ];
}

// Get all categories and job types for dropdowns
try {
    // Get job categories
    $categoriesQuery = "SELECT id, name FROM job_categories WHERE is_active = 1 ORDER BY name";
    $stmt = $db->prepare($categoriesQuery);
    $stmt->execute();
    $jobCategories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get job types
    $jobTypesQuery = "SELECT id, name FROM job_types WHERE is_active = 1 ORDER BY name";
    $stmt = $db->prepare($jobTypesQuery);
    $stmt->execute();
    $jobTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get popular skills for suggestions
    $skillsQuery = "SELECT DISTINCT skill_name 
                    FROM job_skills 
                    GROUP BY skill_name 
                    ORDER BY COUNT(*) DESC 
                    LIMIT 20";
    $stmt = $db->prepare($skillsQuery);
    $stmt->execute();
    $popularSkills = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    
} catch (PDOException $e) {
    error_log("Categories error: " . $e->getMessage());
    $jobCategories = [];
    $jobTypes = [];
    $popularSkills = [];
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get form data
    $preferredCategories = $_POST['preferred_categories'] ?? [];
    $preferredJobTypes = $_POST['preferred_job_types'] ?? [];
    $preferredLocationTypes = $_POST['preferred_location_types'] ?? [];
    $preferredLocations = array_filter(array_map('trim', explode(',', $_POST['preferred_locations'] ?? '')));
    $minSalary = !empty($_POST['min_salary']) ? floatval($_POST['min_salary']) : null;
    $maxSalary = !empty($_POST['max_salary']) ? floatval($_POST['max_salary']) : null;
    $experienceLevel = $_POST['experience_level'] ?? 'any';
    $notificationFrequency = $_POST['notification_frequency'] ?? 'daily';
    $skills = array_filter(array_map('trim', explode(',', $_POST['skills'] ?? '')));
    
    // Validation
    if ($minSalary && $maxSalary && $minSalary > $maxSalary) {
        $errors[] = "Minimum salary cannot be greater than maximum salary";
    }
    
    if (count($preferredLocations) > 10) {
        $errors[] = "You can only select up to 10 preferred locations";
    }
    
    if (count($skills) > 20) {
        $errors[] = "You can only add up to 20 skills";
    }
    
    // If no errors, update preferences
    if (empty($errors)) {
        try {
            $db->beginTransaction();
            
            // Update seeker skills
            if (!empty($skills)) {
                $skillsJson = json_encode($skills);
                $updateSkillsQuery = "UPDATE seekers SET skills = :skills WHERE user_id = :user_id";
                $stmt = $db->prepare($updateSkillsQuery);
                $stmt->execute([
                    'skills' => $skillsJson,
                    'user_id' => $userId
                ]);
            }
            
            // Update preferences
            $updateQuery = "UPDATE seeker_preferences 
                           SET preferred_categories = :categories,
                               preferred_job_types = :job_types,
                               preferred_location_types = :location_types,
                               preferred_locations = :locations,
                               min_salary = :min_salary,
                               max_salary = :max_salary,
                               experience_level_preference = :experience_level,
                               notification_frequency = :notification_frequency,
                               updated_at = NOW()
                           WHERE seeker_id = :seeker_id";
            
            $stmt = $db->prepare($updateQuery);
            $stmt->execute([
                'categories' => json_encode($preferredCategories),
                'job_types' => json_encode($preferredJobTypes),
                'location_types' => json_encode($preferredLocationTypes),
                'locations' => json_encode($preferredLocations),
                'min_salary' => $minSalary,
                'max_salary' => $maxSalary,
                'experience_level' => $experienceLevel,
                'notification_frequency' => $notificationFrequency,
                'seeker_id' => $userId
            ]);
            
            $db->commit();
            $success = true;
            
            // Update session
            $_SESSION['success_message'] = "Your preferences have been updated successfully!";
            
        } catch (PDOException $e) {
            $db->rollBack();
            error_log("Preferences update error: " . $e->getMessage());
            $errors[] = "Failed to update preferences. Please try again.";
        }
    }
}

// Calculate job match percentage based on preferences
try {
    $matchQuery = "SELECT COUNT(*) as total_jobs,
                          COUNT(CASE WHEN j.category_id IN (" . (empty($preferences['preferred_categories']) ? '0' : implode(',', $preferences['preferred_categories'])) . ") THEN 1 END) as category_matches,
                          COUNT(CASE WHEN j.job_type_id IN (" . (empty($preferences['preferred_job_types']) ? '0' : implode(',', $preferences['preferred_job_types'])) . ") THEN 1 END) as type_matches
                   FROM jobs j
                   WHERE j.status = 'active' 
                   AND (j.application_deadline IS NULL OR j.application_deadline >= CURDATE())";
    
    $stmt = $db->prepare($matchQuery);
    $stmt->execute();
    $matchStats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $totalJobs = $matchStats['total_jobs'] ?? 0;
    $categoryMatches = $matchStats['category_matches'] ?? 0;
    $typeMatches = $matchStats['type_matches'] ?? 0;
    
    // Calculate match percentage (simplified)
    $matchPercentage = $totalJobs > 0 ? round((($categoryMatches + $typeMatches) / ($totalJobs * 2)) * 100) : 0;
    
} catch (PDOException $e) {
    error_log("Match calculation error: " . $e->getMessage());
    $matchPercentage = 0;
    $totalJobs = 0;
}

// Get seeker's skills for display
$seekerSkills = [];
if (!empty($seeker['skills'])) {
    $seekerSkills = json_decode($seeker['skills'], true) ?? [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JobWall - Job Preferences</title>
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Plus+Jakarta+Sans:wght@700;800&display=swap" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #0066FF 0%, #0099FF 100%);
            --primary-gradient-dark: linear-gradient(135deg, #0052CC 0%, #0088FF 100%);
            --success-gradient: linear-gradient(135deg, #10b981 0%, #34d399 100%);
            --warning-gradient: linear-gradient(135deg, #f59e0b 0%, #fbbf24 100%);
            --text-dark: #0A0A0A;
            --text-darker: #000000;
            --text-gray: #666666;
            --text-light-gray: #999999;
            --bg-white: #FFFFFF;
            --bg-offwhite: #F8FAFF;
            --bg-light: #f0f7ff;
            --shadow-sm: 0 2px 8px rgba(0, 102, 255, 0.08);
            --shadow-md: 0 4px 20px rgba(0, 102, 255, 0.12);
            --shadow-lg: 0 10px 40px rgba(0, 102, 255, 0.15);
            --border-light: 1px solid rgba(0, 102, 255, 0.1);
            --border-hover: 1px solid rgba(0, 102, 255, 0.3);
            --transition-smooth: all 0.5s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            color: var(--text-dark);
            background: var(--bg-light);
            line-height: 1.7;
            min-height: 100vh;
        }
        
        .preferences-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem;
        }
        
        /* Header */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 3rem;
            padding-bottom: 2rem;
            border-bottom: var(--border-light);
        }
        
        .logo {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 1.875rem;
            font-weight: 800;
            background: var(--primary-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .user-menu {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .user-avatar {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: var(--primary-gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 1.25rem;
        }
        
        .user-name {
            font-weight: 600;
            color: var(--text-darker);
        }
        
        .nav-menu {
            display: flex;
            gap: 2rem;
            align-items: center;
        }
        
        .nav-menu a {
            color: var(--text-gray);
            text-decoration: none;
            font-weight: 600;
            font-size: 0.95rem;
            transition: var(--transition-smooth);
            padding: 0.5rem 0;
            position: relative;
        }
        
        .nav-menu a::before {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            width: 0;
            height: 3px;
            background: var(--primary-gradient);
            border-radius: 2px;
            transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .nav-menu a:hover::before,
        .nav-menu a.active::before {
            width: 100%;
        }
        
        .nav-menu a:hover {
            color: var(--text-darker);
        }
        
        .nav-menu a.active {
            color: var(--text-darker);
        }
        
        /* Main Content */
        .content-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 3rem;
        }
        
        .content-header h1 {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 2.5rem;
            color: var(--text-darker);
        }
        
        .content-header p {
            color: var(--text-gray);
            margin-top: 0.5rem;
        }
        
        /* Match Stats */
        .match-stats {
            background: white;
            border-radius: 20px;
            padding: 2rem;
            box-shadow: var(--shadow-md);
            border: var(--border-light);
            margin-bottom: 3rem;
        }
        
        .match-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }
        
        .match-percentage {
            text-align: center;
        }
        
        .percentage-circle {
            position: relative;
            width: 120px;
            height: 120px;
            margin: 0 auto 1rem;
        }
        
        .circle-bg {
            fill: none;
            stroke: var(--bg-offwhite);
            stroke-width: 8;
        }
        
        .circle-progress {
            fill: none;
            stroke: url(#gradient);
            stroke-width: 8;
            stroke-linecap: round;
            transform: rotate(-90deg);
            transform-origin: 50% 50%;
            transition: stroke-dasharray 1s ease-in-out;
        }
        
        .percentage-text {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 2rem;
            font-weight: 800;
            background: var(--primary-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        .match-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 2rem;
        }
        
        .match-detail {
            text-align: center;
        }
        
        .match-number {
            font-size: 2.5rem;
            font-weight: 800;
            background: var(--primary-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            line-height: 1;
        }
        
        .match-label {
            color: var(--text-gray);
            margin-top: 0.5rem;
            font-size: 0.95rem;
        }
        
        /* Preferences Form */
        .preferences-form-container {
            background: white;
            border-radius: 24px;
            padding: 3rem;
            box-shadow: var(--shadow-lg);
            border: var(--border-light);
            margin-bottom: 3rem;
        }
        
        .form-section {
            margin-bottom: 3rem;
            padding-bottom: 3rem;
            border-bottom: var(--border-light);
        }
        
        .form-section:last-child {
            margin-bottom: 0;
            padding-bottom: 0;
            border-bottom: none;
        }
        
        .section-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .section-icon {
            width: 56px;
            height: 56px;
            background: var(--bg-offwhite);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #0066FF;
            font-size: 1.5rem;
            transition: var(--transition-smooth);
        }
        
        .section-title {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 1.5rem;
            color: var(--text-darker);
        }
        
        .section-description {
            color: var(--text-gray);
            margin-top: 0.5rem;
        }
        
        /* Form Styles */
        .form-group {
            margin-bottom: 2rem;
        }
        
        .form-group label {
            display: block;
            font-weight: 600;
            color: var(--text-darker);
            margin-bottom: 0.75rem;
            font-size: 1rem;
        }
        
        .form-help {
            color: var(--text-light-gray);
            font-size: 0.875rem;
            margin-top: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        /* Multi-select checkboxes */
        .checkbox-group {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }
        
        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 1rem;
            background: var(--bg-offwhite);
            border-radius: 12px;
            cursor: pointer;
            transition: var(--transition-smooth);
            border: var(--border-light);
        }
        
        .checkbox-item:hover {
            background: rgba(0, 102, 255, 0.05);
            transform: translateY(-2px);
            border-color: rgba(0, 102, 255, 0.3);
        }
        
        .checkbox-item input[type="checkbox"] {
            width: 20px;
            height: 20px;
            border-radius: 6px;
            border: var(--border-light);
            cursor: pointer;
            transition: var(--transition-smooth);
        }
        
        .checkbox-item input[type="checkbox"]:checked {
            background: #0066FF;
            border-color: #0066FF;
        }
        
        .checkbox-label {
            font-weight: 500;
            color: var(--text-dark);
        }
        
        /* Location Type Selection */
        .location-type-group {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
        }
        
        .location-type-btn {
            flex: 1;
            padding: 1.5rem 1rem;
            border: var(--border-light);
            background: var(--bg-offwhite);
            border-radius: 12px;
            cursor: pointer;
            transition: var(--transition-smooth);
            text-align: center;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.75rem;
        }
        
        .location-type-btn:hover {
            background: rgba(0, 102, 255, 0.05);
            transform: translateY(-2px);
            border-color: rgba(0, 102, 255, 0.3);
        }
        
        .location-type-btn.active {
            background: rgba(0, 102, 255, 0.1);
            border-color: #0066FF;
            color: #0066FF;
        }
        
        .location-type-btn i {
            font-size: 1.5rem;
        }
        
        /* Location Input */
        .locations-input {
            position: relative;
        }
        
        .locations-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-top: 1rem;
        }
        
        .location-tag {
            background: var(--bg-offwhite);
            padding: 0.5rem 1rem;
            border-radius: 50px;
            font-size: 0.875rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: var(--transition-smooth);
            border: var(--border-light);
        }
        
        .location-tag:hover {
            background: rgba(0, 102, 255, 0.1);
            border-color: #0066FF;
        }
        
        .remove-tag {
            background: none;
            border: none;
            color: var(--text-light-gray);
            cursor: pointer;
            padding: 0.25rem;
            font-size: 0.75rem;
        }
        
        .remove-tag:hover {
            color: #ef4444;
        }
        
        /* Salary Range */
        .salary-range {
            margin-top: 1rem;
        }
        
        .range-inputs {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .range-input {
            position: relative;
        }
        
        .range-input input {
            width: 100%;
            padding: 1rem 1.25rem;
            padding-right: 2.5rem;
            border: var(--border-light);
            border-radius: 12px;
            font-size: 1rem;
            font-family: 'Inter', sans-serif;
            transition: var(--transition-smooth);
            background: var(--bg-offwhite);
        }
        
        .range-input input:focus {
            outline: none;
            border: var(--border-hover);
            background: white;
            box-shadow: 0 0 0 4px rgba(0, 102, 255, 0.1);
        }
        
        .currency-symbol {
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-gray);
            font-weight: 600;
        }
        
        .range-slider {
            margin-top: 1.5rem;
        }
        
        .slider-container {
            position: relative;
            height: 4px;
            background: var(--bg-offwhite);
            border-radius: 2px;
            margin: 2rem 0;
        }
        
        .slider-track {
            position: absolute;
            height: 100%;
            background: var(--primary-gradient);
            border-radius: 2px;
        }
        
        .slider-handle {
            position: absolute;
            width: 24px;
            height: 24px;
            background: white;
            border: 3px solid #0066FF;
            border-radius: 50%;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            box-shadow: var(--shadow-sm);
            transition: var(--transition-smooth);
        }
        
        .slider-handle:hover {
            transform: translateY(-50%) scale(1.2);
            box-shadow: var(--shadow-md);
        }
        
        /* Skills Input */
        .skills-container {
            margin-top: 1rem;
        }
        
        .skills-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }
        
        .skill-tag {
            background: var(--primary-gradient);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 50px;
            font-size: 0.875rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: var(--transition-smooth);
        }
        
        .skill-tag:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }
        
        .skill-tag i {
            font-size: 0.75rem;
            cursor: pointer;
            opacity: 0.8;
        }
        
        .skill-tag i:hover {
            opacity: 1;
        }
        
        /* Select Input */
        .select-input {
            width: 100%;
            padding: 1rem 1.25rem;
            border: var(--border-light);
            border-radius: 12px;
            font-size: 1rem;
            font-family: 'Inter', sans-serif;
            background: var(--bg-offwhite);
            cursor: pointer;
            transition: var(--transition-smooth);
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%23666666' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14 2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 1rem center;
            background-size: 16px;
        }
        
        .select-input:focus {
            outline: none;
            border: var(--border-hover);
            background: white;
            box-shadow: 0 0 0 4px rgba(0, 102, 255, 0.1);
        }
        
        /* Submit Button */
        .submit-btn {
            background: var(--primary-gradient);
            color: white;
            border: none;
            padding: 1.25rem 3rem;
            border-radius: 12px;
            font-size: 1.125rem;
            font-weight: 700;
            cursor: pointer;
            transition: var(--transition-smooth);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            margin: 2rem auto 0;
            min-width: 200px;
        }
        
        .submit-btn:hover {
            background: var(--primary-gradient-dark);
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg);
        }
        
        .submit-btn:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none !important;
        }
        
        /* Success/Error Messages */
        .message-container {
            margin-bottom: 2rem;
        }
        
        .success-message {
            background: #d1fae5;
            border: 1px solid #a7f3d0;
            color: #065f46;
            padding: 1.5rem;
            border-radius: 12px;
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .error-message {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #7f1d1d;
            padding: 1.5rem;
            border-radius: 12px;
            margin-bottom: 2rem;
        }
        
        .error-list {
            list-style: none;
        }
        
        .error-list li {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 0.5rem;
        }
        
        /* Responsive Design */
        @media (max-width: 768px) {
            .preferences-container {
                padding: 1rem;
            }
            
            .header {
                flex-direction: column;
                gap: 1.5rem;
                align-items: stretch;
            }
            
            .nav-menu {
                justify-content: center;
                flex-wrap: wrap;
                gap: 1rem;
            }
            
            .content-header {
                flex-direction: column;
                gap: 1.5rem;
                text-align: center;
            }
            
            .preferences-form-container {
                padding: 1.5rem;
            }
            
            .checkbox-group {
                grid-template-columns: 1fr;
            }
            
            .location-type-group {
                flex-direction: column;
            }
            
            .range-inputs {
                grid-template-columns: 1fr;
            }
        }
        
        /* Animation */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .fade-in {
            animation: fadeIn 0.5s ease-out;
        }
        
        /* Loading Spinner */
        .spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="preferences-container fade-in">
        <!-- Header -->
        <div class="header">
            <a href="../../index.php" class="logo">
                <i class="fas fa-briefcase"></i>
                <span>JobWall</span>
            </a>
            
            <div class="user-menu">
                <div class="nav-menu">
                    <a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
                    <a href="profile/edit.php"><i class="fas fa-user"></i> Profile</a>
                    <a href="applications.php"><i class="fas fa-file-alt"></i> Applications</a>
                    <a href="saved-jobs.php"><i class="fas fa-bookmark"></i> Saved Jobs</a>
                    <a href="preferences.php" class="active"><i class="fas fa-sliders-h"></i> Preferences</a>
                    <a href="settings.php"><i class="fas fa-cog"></i> Settings</a>
                </div>
                
                <div class="user-info">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($seeker['first_name'] ?? '', 0, 1) . substr($seeker['last_name'] ?? '', 0, 1)); ?>
                    </div>
                    <div class="user-name"><?php echo htmlspecialchars($seeker['first_name'] ?? ''); ?></div>
                    <a href="../../login.php?logout=true" style="color: #ef4444; text-decoration: none;">
                        <i class="fas fa-sign-out-alt"></i>
                    </a>
                </div>
            </div>
        </div>
        
        <!-- Content Header -->
        <div class="content-header">
            <div>
                <h1>Job Preferences</h1>
                <p>Customize your job search preferences for better matches</p>
            </div>
            <div>
                <a href="dashboard.php" class="btn-outline" style="text-decoration: none; display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.75rem 1.5rem; border: 2px solid #0066FF; color: #0066FF; border-radius: 12px; font-weight: 600; transition: var(--transition-smooth);">
                    <i class="fas fa-arrow-left"></i>
                    Back to Dashboard
                </a>
            </div>
        </div>
        
        <!-- Match Statistics -->
        <div class="match-stats fade-in">
            <div class="match-header">
                <div>
                    <h3 style="font-size: 1.5rem; margin-bottom: 0.5rem;">Your Job Match Score</h3>
                    <p style="color: var(--text-gray);">Based on your current preferences and available jobs</p>
                </div>
            </div>
            
            <div class="match-percentage">
                <svg class="percentage-circle" viewBox="0 0 120 120">
                    <defs>
                        <linearGradient id="gradient" x1="0%" y1="0%" x2="100%" y2="100%">
                            <stop offset="0%" stop-color="#0066FF" />
                            <stop offset="100%" stop-color="#0099FF" />
                        </linearGradient>
                    </defs>
                    <circle class="circle-bg" cx="60" cy="60" r="54"></circle>
                    <circle class="circle-progress" cx="60" cy="60" r="54" 
                            stroke-dasharray="<?php echo $matchPercentage * 3.39; ?> 340"
                            stroke-dashoffset="0"></circle>
                    <text class="percentage-text" x="60" y="60"><?php echo $matchPercentage; ?>%</text>
                </svg>
                <p style="color: var(--text-gray); font-size: 0.95rem;">Match score with current preferences</p>
            </div>
            
            <div class="match-details">
                <div class="match-detail">
                    <div class="match-number"><?php echo $totalJobs; ?></div>
                    <div class="match-label">Total Jobs Available</div>
                </div>
                <div class="match-detail">
                    <div class="match-number"><?php echo $categoryMatches; ?></div>
                    <div class="match-label">Category Matches</div>
                </div>
                <div class="match-detail">
                    <div class="match-number"><?php echo $typeMatches; ?></div>
                    <div class="match-label">Job Type Matches</div>
                </div>
                <div class="match-detail">
                    <div class="match-number"><?php echo round(($categoryMatches + $typeMatches) / 2); ?></div>
                    <div class="match-label">Perfect Matches</div>
                </div>
            </div>
        </div>
        
        <!-- Success/Error Messages -->
        <div class="message-container">
            <?php if ($success): ?>
                <div class="success-message fade-in">
                    <i class="fas fa-check-circle"></i>
                    <div>
                        <strong>Preferences Updated!</strong>
                        <p>Your job preferences have been saved successfully.</p>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($errors)): ?>
                <div class="error-message fade-in">
                    <ul class="error-list">
                        <?php foreach ($errors as $error): ?>
                            <li>
                                <i class="fas fa-exclamation-circle"></i>
                                <?php echo htmlspecialchars($error); ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Preferences Form -->
        <form class="preferences-form-container fade-in" method="POST" action="">
            <!-- Job Categories -->
            <div class="form-section">
                <div class="section-header">
                    <div class="section-icon">
                        <i class="fas fa-list"></i>
                    </div>
                    <div>
                        <h3 class="section-title">Job Categories</h3>
                        <p class="section-description">Select industries and categories that interest you</p>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Preferred Job Categories</label>
                    <div class="form-help">
                        <i class="fas fa-info-circle"></i>
                        Select categories to receive relevant job recommendations
                    </div>
                    <div class="checkbox-group">
                        <?php foreach ($jobCategories as $category): ?>
                            <label class="checkbox-item">
                                <input type="checkbox" name="preferred_categories[]" 
                                       value="<?php echo $category['id']; ?>"
                                       <?php echo in_array($category['id'], $preferences['preferred_categories']) ? 'checked' : ''; ?>>
                                <span class="checkbox-label"><?php echo htmlspecialchars($category['name']); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            
            <!-- Job Type -->
            <div class="form-section">
                <div class="section-header">
                    <div class="section-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div>
                        <h3 class="section-title">Job Type & Schedule</h3>
                        <p class="section-description">Choose your preferred work arrangement</p>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Preferred Job Types</label>
                    <div class="form-help">
                        <i class="fas fa-info-circle"></i>
                        Select the type of employment you're looking for
                    </div>
                    <div class="checkbox-group">
                        <?php foreach ($jobTypes as $type): ?>
                            <label class="checkbox-item">
                                <input type="checkbox" name="preferred_job_types[]" 
                                       value="<?php echo $type['id']; ?>"
                                       <?php echo in_array($type['id'], $preferences['preferred_job_types']) ? 'checked' : ''; ?>>
                                <span class="checkbox-label"><?php echo htmlspecialchars($type['name']); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            
            <!-- Location Preferences -->
            <div class="form-section">
                <div class="section-header">
                    <div class="section-icon">
                        <i class="fas fa-map-marker-alt"></i>
                    </div>
                    <div>
                        <h3 class="section-title">Location Preferences</h3>
                        <p class="section-description">Set your preferred work locations and arrangements</p>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Work Arrangement</label>
                    <div class="form-help">
                        <i class="fas fa-info-circle"></i>
                        Select your preferred work location type
                    </div>
                    <div class="location-type-group">
                        <label class="location-type-btn <?php echo in_array('onsite', $preferences['preferred_location_types']) ? 'active' : ''; ?>">
                            <input type="checkbox" name="preferred_location_types[]" value="onsite" 
                                   <?php echo in_array('onsite', $preferences['preferred_location_types']) ? 'checked' : ''; ?> 
                                   style="display: none;">
                            <i class="fas fa-building"></i>
                            <span>On-site</span>
                        </label>
                        
                        <label class="location-type-btn <?php echo in_array('remote', $preferences['preferred_location_types']) ? 'active' : ''; ?>">
                            <input type="checkbox" name="preferred_location_types[]" value="remote" 
                                   <?php echo in_array('remote', $preferences['preferred_location_types']) ? 'checked' : ''; ?> 
                                   style="display: none;">
                            <i class="fas fa-home"></i>
                            <span>Remote</span>
                        </label>
                        
                        <label class="location-type-btn <?php echo in_array('hybrid', $preferences['preferred_location_types']) ? 'active' : ''; ?>">
                            <input type="checkbox" name="preferred_location_types[]" value="hybrid" 
                                   <?php echo in_array('hybrid', $preferences['preferred_location_types']) ? 'checked' : ''; ?> 
                                   style="display: none;">
                            <i class="fas fa-balance-scale"></i>
                            <span>Hybrid</span>
                        </label>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Preferred Locations</label>
                    <div class="form-help">
                        <i class="fas fa-info-circle"></i>
                        Enter cities or countries separated by commas
                    </div>
                    <input type="text" name="preferred_locations" 
                           placeholder="e.g., New York, London, Remote, Berlin" 
                           class="form-control"
                           value="<?php echo htmlspecialchars(implode(', ', $preferences['preferred_locations'])); ?>">
                    <div class="locations-tags" id="locationTags">
                        <?php foreach ($preferences['preferred_locations'] as $location): ?>
                            <?php if (!empty($location)): ?>
                                <span class="location-tag">
                                    <?php echo htmlspecialchars($location); ?>
                                    <button type="button" class="remove-tag" onclick="removeLocationTag(this)">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </span>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            
            <!-- Salary Expectations -->
            <div class="form-section">
                <div class="section-header">
                    <div class="section-icon">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                    <div>
                        <h3 class="section-title">Salary Expectations</h3>
                        <p class="section-description">Set your expected salary range</p>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Salary Range (per year)</label>
                    <div class="form-help">
                        <i class="fas fa-info-circle"></i>
                        Set your minimum and maximum expected salary
                    </div>
                    
                    <div class="range-inputs">
                        <div class="range-input">
                            <input type="number" name="min_salary" id="minSalary" 
                                   placeholder="Minimum salary"
                                   value="<?php echo $preferences['min_salary'] ?? ''; ?>"
                                   min="0" step="1000">
                            <span class="currency-symbol">USD</span>
                        </div>
                        
                        <div class="range-input">
                            <input type="number" name="max_salary" id="maxSalary" 
                                   placeholder="Maximum salary"
                                   value="<?php echo $preferences['max_salary'] ?? ''; ?>"
                                   min="0" step="1000">
                            <span class="currency-symbol">USD</span>
                        </div>
                    </div>
                    
                    <div class="range-slider">
                        <div class="slider-container">
                            <div class="slider-track" id="sliderTrack"></div>
                            <div class="slider-handle" id="minHandle"></div>
                            <div class="slider-handle" id="maxHandle"></div>
                        </div>
                        <div style="display: flex; justify-content: space-between; font-size: 0.875rem; color: var(--text-gray);">
                            <span id="minDisplay">$0</span>
                            <span id="maxDisplay">$200,000+</span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Skills & Experience -->
            <div class="form-section">
                <div class="section-header">
                    <div class="section-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div>
                        <h3 class="section-title">Experience & Skills</h3>
                        <p class="section-description">Define your experience level and key skills</p>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Experience Level Preference</label>
                    <select name="experience_level" class="select-input">
                        <option value="any" <?php echo ($preferences['experience_level_preference'] ?? 'any') === 'any' ? 'selected' : ''; ?>>Any Level</option>
                        <option value="entry" <?php echo ($preferences['experience_level_preference'] ?? '') === 'entry' ? 'selected' : ''; ?>>Entry Level (0-2 years)</option>
                        <option value="mid" <?php echo ($preferences['experience_level_preference'] ?? '') === 'mid' ? 'selected' : ''; ?>>Mid Level (3-7 years)</option>
                        <option value="senior" <?php echo ($preferences['experience_level_preference'] ?? '') === 'senior' ? 'selected' : ''; ?>>Senior Level (8+ years)</option>
                        <option value="executive" <?php echo ($preferences['experience_level_preference'] ?? '') === 'executive' ? 'selected' : ''; ?>>Executive Level</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Your Skills</label>
                    <div class="form-help">
                        <i class="fas fa-info-circle"></i>
                        Add your skills separated by commas. This helps with job matching.
                    </div>
                    <div class="skills-container">
                        <input type="text" id="skillsInput" 
                               placeholder="e.g., JavaScript, React, Project Management, Python"
                               class="form-control">
                        <div class="skills-tags" id="skillsTags">
                            <?php foreach ($seekerSkills as $skill): ?>
                                <?php if (!empty($skill)): ?>
                                    <span class="skill-tag">
                                        <?php echo htmlspecialchars($skill); ?>
                                        <i class="fas fa-times" onclick="removeSkillTag(this)"></i>
                                    </span>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                        <input type="hidden" name="skills" id="skillsHidden" value="<?php echo htmlspecialchars(implode(',', $seekerSkills)); ?>">
                    </div>
                </div>
            </div>
            
            <!-- Notification Preferences -->
            <div class="form-section">
                <div class="section-header">
                    <div class="section-icon">
                        <i class="fas fa-bell"></i>
                    </div>
                    <div>
                        <h3 class="section-title">Notification Preferences</h3>
                        <p class="section-description">Choose how often you want to receive job alerts</p>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Job Alert Frequency</label>
                    <select name="notification_frequency" class="select-input">
                        <option value="instant" <?php echo ($preferences['notification_frequency'] ?? 'daily') === 'instant' ? 'selected' : ''; ?>>Instant - Send immediately</option>
                        <option value="daily" <?php echo ($preferences['notification_frequency'] ?? 'daily') === 'daily' ? 'selected' : ''; ?>>Daily Digest</option>
                        <option value="weekly" <?php echo ($preferences['notification_frequency'] ?? 'daily') === 'weekly' ? 'selected' : ''; ?>>Weekly Digest</option>
                    </select>
                    <div class="form-help">
                        <i class="fas fa-info-circle"></i>
                        Receive job alerts based on your selected frequency
                    </div>
                </div>
            </div>
            
            <!-- Submit Button -->
            <button type="submit" class="submit-btn" id="submitBtn">
                <i class="fas fa-save"></i>
                Save Preferences
            </button>
        </form>
    </div>

    <script>
        // DOM Ready
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize salary slider
            initSalarySlider();
            
            // Initialize skills input
            initSkillsInput();
            
            // Initialize location input
            initLocationInput();
            
            // Initialize checkbox groups
            initCheckboxGroups();
        });
        
        // Salary Slider
        function initSalarySlider() {
            const minSalaryInput = document.getElementById('minSalary');
            const maxSalaryInput = document.getElementById('maxSalary');
            const minHandle = document.getElementById('minHandle');
            const maxHandle = document.getElementById('maxHandle');
            const sliderTrack = document.getElementById('sliderTrack');
            const minDisplay = document.getElementById('minDisplay');
            const maxDisplay = document.getElementById('maxDisplay');
            
            let minValue = parseInt(minSalaryInput.value) || 0;
            let maxValue = parseInt(maxSalaryInput.value) || 200000;
            let minLimit = 0;
            let maxLimit = 200000;
            let isDragging = false;
            let currentHandle = null;
            
            function updateSlider() {
                const minPercent = ((minValue - minLimit) / (maxLimit - minLimit)) * 100;
                const maxPercent = ((maxValue - minLimit) / (maxLimit - minLimit)) * 100;
                
                minHandle.style.left = `calc(${minPercent}% - 12px)`;
                maxHandle.style.left = `calc(${maxPercent}% - 12px)`;
                sliderTrack.style.left = `${minPercent}%`;
                sliderTrack.style.width = `${maxPercent - minPercent}%`;
                
                minDisplay.textContent = formatCurrency(minValue);
                maxDisplay.textContent = formatCurrency(maxValue);
                
                minSalaryInput.value = minValue;
                maxSalaryInput.value = maxValue;
            }
            
            function formatCurrency(value) {
                if (value >= 100000) {
                    return '$' + (value / 1000).toFixed(0) + 'k+';
                }
                return '$' + value.toLocaleString();
            }
            
            function startDrag(e, handle) {
                isDragging = true;
                currentHandle = handle;
                document.addEventListener('mousemove', drag);
                document.addEventListener('mouseup', stopDrag);
                document.addEventListener('touchmove', drag);
                document.addEventListener('touchend', stopDrag);
            }
            
            function drag(e) {
                if (!isDragging || !currentHandle) return;
                
                e.preventDefault();
                const sliderRect = document.querySelector('.slider-container').getBoundingClientRect();
                const clientX = e.type.includes('touch') ? e.touches[0].clientX : e.clientX;
                let percent = ((clientX - sliderRect.left) / sliderRect.width) * 100;
                percent = Math.max(0, Math.min(100, percent));
                
                const value = minLimit + (percent / 100) * (maxLimit - minLimit);
                
                if (currentHandle === minHandle) {
                    minValue = Math.min(value, maxValue - 10000);
                } else {
                    maxValue = Math.max(value, minValue + 10000);
                }
                
                updateSlider();
            }
            
            function stopDrag() {
                isDragging = false;
                currentHandle = null;
                document.removeEventListener('mousemove', drag);
                document.removeEventListener('mouseup', stopDrag);
                document.removeEventListener('touchmove', drag);
                document.removeEventListener('touchend', stopDrag);
            }
            
            // Initialize values from inputs
            minValue = parseInt(minSalaryInput.value) || 0;
            maxValue = parseInt(maxSalaryInput.value) || 200000;
            updateSlider();
            
            // Add event listeners
            minHandle.addEventListener('mousedown', (e) => startDrag(e, minHandle));
            maxHandle.addEventListener('mousedown', (e) => startDrag(e, maxHandle));
            minHandle.addEventListener('touchstart', (e) => startDrag(e, minHandle));
            maxHandle.addEventListener('touchstart', (e) => startDrag(e, maxHandle));
            
            // Update slider when inputs change
            minSalaryInput.addEventListener('input', function() {
                minValue = parseInt(this.value) || 0;
                if (minValue > maxValue - 10000) {
                    minValue = maxValue - 10000;
                }
                updateSlider();
            });
            
            maxSalaryInput.addEventListener('input', function() {
                maxValue = parseInt(this.value) || 200000;
                if (maxValue < minValue + 10000) {
                    maxValue = minValue + 10000;
                }
                updateSlider();
            });
        }
        
        // Skills Input
        function initSkillsInput() {
            const skillsInput = document.getElementById('skillsInput');
            const skillsTags = document.getElementById('skillsTags');
            const skillsHidden = document.getElementById('skillsHidden');
            
            skillsInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' || e.key === ',') {
                    e.preventDefault();
                    addSkill();
                }
            });
            
            skillsInput.addEventListener('blur', addSkill);
            
            function addSkill() {
                const skill = skillsInput.value.trim();
                if (skill && !skillExists(skill)) {
                    addSkillTag(skill);
                    skillsInput.value = '';
                    updateHiddenInput();
                }
            }
            
            function skillExists(skill) {
                const existingSkills = Array.from(skillsTags.children).map(tag => 
                    tag.textContent.replace('×', '').trim()
                );
                return existingSkills.includes(skill);
            }
            
            function addSkillTag(skill) {
                const tag = document.createElement('span');
                tag.className = 'skill-tag';
                tag.innerHTML = `
                    ${skill}
                    <i class="fas fa-times" onclick="removeSkillTag(this)"></i>
                `;
                skillsTags.appendChild(tag);
            }
        }
        
        function removeSkillTag(element) {
            element.parentElement.remove();
            updateHiddenInput();
        }
        
        function updateHiddenInput() {
            const skillsTags = document.getElementById('skillsTags');
            const skills = Array.from(skillsTags.children).map(tag => 
                tag.textContent.replace('×', '').trim()
            );
            document.getElementById('skillsHidden').value = skills.join(',');
        }
        
        // Location Input
        function initLocationInput() {
            const locationsInput = document.querySelector('input[name="preferred_locations"]');
            const locationTags = document.getElementById('locationTags');
            
            locationsInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' || e.key === ',') {
                    e.preventDefault();
                    addLocation();
                }
            });
            
            locationsInput.addEventListener('blur', addLocation);
            
            function addLocation() {
                const location = locationsInput.value.trim();
                if (location && !locationExists(location)) {
                    addLocationTag(location);
                    updateLocationsInput();
                }
            }
            
            function locationExists(location) {
                const existingLocations = Array.from(locationTags.children).map(tag => 
                    tag.textContent.replace('×', '').trim()
                );
                return existingLocations.includes(location);
            }
            
            function addLocationTag(location) {
                const tag = document.createElement('span');
                tag.className = 'location-tag';
                tag.innerHTML = `
                    ${location}
                    <button type="button" class="remove-tag" onclick="removeLocationTag(this)">
                        <i class="fas fa-times"></i>
                    </button>
                `;
                locationTags.appendChild(tag);
                locationsInput.value = '';
            }
        }
        
        function removeLocationTag(button) {
            button.parentElement.remove();
            updateLocationsInput();
        }
        
        function updateLocationsInput() {
            const locationTags = document.getElementById('locationTags');
            const locations = Array.from(locationTags.children).map(tag => 
                tag.textContent.replace('×', '').trim()
            );
            document.querySelector('input[name="preferred_locations"]').value = locations.join(', ');
        }
        
        // Checkbox Groups
        function initCheckboxGroups() {
            // Handle location type buttons
            const locationButtons = document.querySelectorAll('.location-type-btn');
            locationButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const checkbox = this.querySelector('input[type="checkbox"]');
                    checkbox.checked = !checkbox.checked;
                    this.classList.toggle('active', checkbox.checked);
                });
            });
            
            // Handle regular checkboxes
            const checkboxes = document.querySelectorAll('.checkbox-item');
            checkboxes.forEach(item => {
                item.addEventListener('click', function(e) {
                    if (e.target.type !== 'checkbox') {
                        const checkbox = this.querySelector('input[type="checkbox"]');
                        checkbox.checked = !checkbox.checked;
                    }
                });
            });
        }
        
        // Form submission
        document.querySelector('form').addEventListener('submit', function(e) {
            const submitBtn = document.getElementById('submitBtn');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<div class="spinner"></div> Saving...';
            
            // Validate salary
            const minSalary = parseInt(document.getElementById('minSalary').value) || 0;
            const maxSalary = parseInt(document.getElementById('maxSalary').value) || 0;
            
            if (maxSalary > 0 && minSalary > maxSalary) {
                e.preventDefault();
                alert('Minimum salary cannot be greater than maximum salary.');
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-save"></i> Save Preferences';
                return false;
            }
            
            return true;
        });
        
        // Auto-suggest skills
        const popularSkills = <?php echo json_encode($popularSkills); ?>;
        const skillsInput = document.getElementById('skillsInput');
        
        skillsInput.addEventListener('focus', function() {
            if (!this.dataset.suggestionsShown) {
                showSkillSuggestions();
                this.dataset.suggestionsShown = true;
            }
        });
        
        function showSkillSuggestions() {
            const suggestionsDiv = document.createElement('div');
            suggestionsDiv.className = 'skill-suggestions';
            suggestionsDiv.style.cssText = `
                background: white;
                border: var(--border-light);
                border-radius: 12px;
                margin-top: 0.5rem;
                padding: 1rem;
                box-shadow: var(--shadow-md);
                max-height: 200px;
                overflow-y: auto;
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
                gap: 0.5rem;
            `;
            
            popularSkills.forEach(skill => {
                const suggestion = document.createElement('button');
                suggestion.type = 'button';
                suggestion.textContent = skill;
                suggestion.style.cssText = `
                    padding: 0.5rem 1rem;
                    background: var(--bg-offwhite);
                    border: var(--border-light);
                    border-radius: 8px;
                    cursor: pointer;
                    transition: var(--transition-smooth);
                    text-align: left;
                    font-size: 0.875rem;
                `;
                suggestion.addEventListener('mouseenter', () => {
                    suggestion.style.background = 'rgba(0, 102, 255, 0.1)';
                    suggestion.style.borderColor = '#0066FF';
                });
                suggestion.addEventListener('mouseleave', () => {
                    suggestion.style.background = 'var(--bg-offwhite)';
                    suggestion.style.borderColor = 'rgba(0, 102, 255, 0.1)';
                });
                suggestion.addEventListener('click', () => {
                    if (!skillExists(skill)) {
                        addSkillTag(skill);
                        updateHiddenInput();
                    }
                    suggestionsDiv.remove();
                });
                suggestionsDiv.appendChild(suggestion);
            });
            
            skillsInput.parentNode.insertBefore(suggestionsDiv, skillsInput.nextSibling);
        }
        
        function skillExists(skill) {
            const skillsTags = document.getElementById('skillsTags');
            const existingSkills = Array.from(skillsTags.children).map(tag => 
                tag.textContent.replace('×', '').trim()
            );
            return existingSkills.includes(skill);
        }
    </script>
</body>
</html>