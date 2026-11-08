<?php
// employer/candidates.php
// Browse and save candidates with advanced filtering

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../includes/auth.php';

// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Require employer login
requireEmployerLogin();

// Get database connection
$pdo = getDB();
if (!$pdo) {
    die("Database connection failed");
}

// Get employer ID from session
$user_id = $_SESSION['user_id'];

// Get employer info
$employer = fetchOne("SELECT e.*, u.email 
                      FROM employers e 
                      JOIN users u ON e.user_id = u.id 
                      WHERE e.user_id = ?", [$user_id]);

if (!$employer) {
    // If employer record not found, try to get it
    $employer = fetchOne("SELECT * FROM employers WHERE user_id = ?", [$user_id]);
}

$employer_id = $employer['id'] ?? 0;

if (!$employer_id) {
    die("Employer record not found");
}

// Get job categories for filter
$categories = fetchAll("SELECT id, name FROM job_categories WHERE is_active = 1 ORDER BY name") ?: [];

// Get job types for filter
$job_types = fetchAll("SELECT id, name FROM job_types WHERE is_active = 1 ORDER BY name") ?: [];

// Get languages for filter
$languages = fetchAll("SELECT id, name FROM languages WHERE is_active = 1 ORDER BY name") ?: [];

// Initialize filter variables
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$category = isset($_GET['category']) ? (int)$_GET['category'] : 0;
$job_type = isset($_GET['job_type']) ? (int)$_GET['job_type'] : 0;
$location = isset($_GET['location']) ? trim($_GET['location']) : '';
$experience_min = isset($_GET['experience_min']) ? (int)$_GET['experience_min'] : 0;
$experience_max = isset($_GET['experience_max']) ? (int)$_GET['experience_max'] : 30;
$language_id = isset($_GET['language']) ? (int)$_GET['language'] : 0;
$education = isset($_GET['education']) ? trim($_GET['education']) : '';
$salary_min = isset($_GET['salary_min']) ? (float)$_GET['salary_min'] : 0;
$salary_max = isset($_GET['salary_max']) ? (float)$_GET['salary_max'] : 0;
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'recent';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 12;
$offset = ($page - 1) * $per_page;

// Handle save/unsave candidate
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        if ($_POST['action'] === 'save_candidate' && isset($_POST['seeker_id'])) {
            $seeker_id = (int)$_POST['seeker_id'];
            $notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';
            
            // Check if already saved
            $existing = fetchOne("SELECT id FROM saved_candidates 
                                 WHERE employer_id = ? AND seeker_id = ?", 
                                 [$employer_id, $seeker_id]);
            
            if (!$existing) {
                $saved = executeQuery("INSERT INTO saved_candidates (employer_id, seeker_id, notes, created_at) 
                                      VALUES (?, ?, ?, NOW())", 
                                      [$employer_id, $seeker_id, $notes]);
                
                if ($saved) {
                    $message = "Candidate saved successfully!";
                    $message_type = "success";
                } else {
                    $message = "Failed to save candidate.";
                    $message_type = "error";
                }
            } else {
                $message = "Candidate already saved.";
                $message_type = "info";
            }
        } elseif ($_POST['action'] === 'unsave_candidate' && isset($_POST['seeker_id'])) {
            $seeker_id = (int)$_POST['seeker_id'];
            
            $unsaved = executeQuery("DELETE FROM saved_candidates 
                                    WHERE employer_id = ? AND seeker_id = ?", 
                                    [$employer_id, $seeker_id]);
            
            if ($unsaved) {
                $message = "Candidate removed from saved list.";
                $message_type = "success";
            } else {
                $message = "Failed to remove candidate.";
                $message_type = "error";
            }
        } elseif ($_POST['action'] === 'update_notes' && isset($_POST['saved_id']) && isset($_POST['notes'])) {
            $saved_id = (int)$_POST['saved_id'];
            $notes = trim($_POST['notes']);
            
            $updated = executeQuery("UPDATE saved_candidates SET notes = ? WHERE id = ? AND employer_id = ?", 
                                   [$notes, $saved_id, $employer_id]);
            
            if ($updated) {
                $message = "Notes updated successfully!";
                $message_type = "success";
            } else {
                $message = "Failed to update notes.";
                $message_type = "error";
            }
        }
    } catch (Exception $e) {
        $message = "An error occurred: " . $e->getMessage();
        $message_type = "error";
    }
    
    // Store message in session for next page load
    if (!empty($message)) {
        $_SESSION['flash_message'] = $message;
        $_SESSION['flash_message_type'] = $message_type;
    }
    
    // Redirect to prevent form resubmission
    $query_params = $_GET;
    unset($query_params['page']); // Reset to first page
    $redirect_url = 'candidates.php?' . http_build_query($query_params);
    header("Location: $redirect_url");
    exit();
}

// Get saved candidates IDs for the current employer
$saved_candidates = fetchAll("SELECT seeker_id, id as saved_id, notes as saved_notes 
                             FROM saved_candidates WHERE employer_id = ?", [$employer_id]) ?: [];
$saved_ids = [];
$saved_notes_map = [];
foreach ($saved_candidates as $saved) {
    $saved_ids[] = $saved['seeker_id'];
    $saved_notes_map[$saved['seeker_id']] = [
        'saved_id' => $saved['saved_id'],
        'notes' => $saved['saved_notes']
    ];
}

// Check if we're viewing saved candidates only
$view = isset($_GET['view']) && $_GET['view'] === 'saved' ? 'saved' : 'all';

// Build the query for candidates
$query = "SELECT s.*, u.email, u.created_at as user_created,
          (SELECT GROUP_CONCAT(DISTINCT l.name SEPARATOR ', ') 
           FROM seeker_languages sl 
           JOIN languages l ON sl.language_id = l.id 
           WHERE sl.seeker_id = s.id) as languages,
          (SELECT COUNT(*) FROM applications a WHERE a.seeker_id = s.id) as application_count
          FROM seekers s
          JOIN users u ON s.user_id = u.id
          WHERE u.user_type = 'seeker' AND s.is_active = 1";

$count_query = "SELECT COUNT(DISTINCT s.id) as total 
                FROM seekers s
                JOIN users u ON s.user_id = u.id
                WHERE u.user_type = 'seeker' AND s.is_active = 1";

$params = [];
$count_params = [];

// Apply filters
if ($view === 'saved' && !empty($saved_ids)) {
    $placeholders = implode(',', array_fill(0, count($saved_ids), '?'));
    $query .= " AND s.id IN ($placeholders)";
    $count_query .= " AND s.id IN ($placeholders)";
    $params = array_merge($params, $saved_ids);
    $count_params = array_merge($count_params, $saved_ids);
} elseif ($view === 'saved' && empty($saved_ids)) {
    // No saved candidates, show empty result
    $query .= " AND 1=0";
    $count_query .= " AND 1=0";
}

if (!empty($search)) {
    $query .= " AND (s.first_name LIKE ? OR s.last_name LIKE ? OR s.headline LIKE ? OR s.bio LIKE ? OR s.skills LIKE ?)";
    $count_query .= " AND (s.first_name LIKE ? OR s.last_name LIKE ? OR s.headline LIKE ? OR s.bio LIKE ? OR s.skills LIKE ?)";
    $search_term = "%{$search}%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $count_params[] = $search_term;
    $count_params[] = $search_term;
    $count_params[] = $search_term;
    $count_params[] = $search_term;
    $count_params[] = $search_term;
}

// Note: JSON_CONTAINS might not work on all MariaDB versions
// Simplified filters for now
if (!empty($location)) {
    $query .= " AND s.location LIKE ?";
    $count_query .= " AND s.location LIKE ?";
    $params[] = "%{$location}%";
    $count_params[] = "%{$location}%";
}

if ($experience_min > 0) {
    $query .= " AND s.years_experience >= ?";
    $count_query .= " AND s.years_experience >= ?";
    $params[] = $experience_min;
    $count_params[] = $experience_min;
}

if ($experience_max < 30) {
    $query .= " AND s.years_experience <= ?";
    $count_query .= " AND s.years_experience <= ?";
    $params[] = $experience_max;
    $count_params[] = $experience_max;
}

if ($language_id > 0) {
    $query .= " AND EXISTS (SELECT 1 FROM seeker_languages sl 
               WHERE sl.seeker_id = s.id AND sl.language_id = ?)";
    $count_query .= " AND EXISTS (SELECT 1 FROM seeker_languages sl 
                    WHERE sl.seeker_id = s.id AND sl.language_id = ?)";
    $params[] = $language_id;
    $count_params[] = $language_id;
}

if (!empty($education)) {
    $query .= " AND s.highest_education = ?";
    $count_query .= " AND s.highest_education = ?";
    $params[] = $education;
    $count_params[] = $education;
}

if ($salary_min > 0) {
    $query .= " AND s.expected_salary_min >= ?";
    $count_query .= " AND s.expected_salary_min >= ?";
    $params[] = $salary_min;
    $count_params[] = $salary_min;
}

if ($salary_max > 0) {
    $query .= " AND s.expected_salary_max <= ?";
    $count_query .= " AND s.expected_salary_max <= ?";
    $params[] = $salary_max;
    $count_params[] = $salary_max;
}

// Apply sorting
switch ($sort) {
    case 'name_asc':
        $query .= " ORDER BY s.first_name ASC, s.last_name ASC";
        break;
    case 'name_desc':
        $query .= " ORDER BY s.first_name DESC, s.last_name DESC";
        break;
    case 'experience_high':
        $query .= " ORDER BY s.years_experience DESC";
        break;
    case 'experience_low':
        $query .= " ORDER BY s.years_experience ASC";
        break;
    case 'salary_high':
        $query .= " ORDER BY s.expected_salary_max DESC";
        break;
    case 'salary_low':
        $query .= " ORDER BY s.expected_salary_min ASC";
        break;
    case 'recent':
    default:
        $query .= " ORDER BY s.created_at DESC";
        break;
}

// Get total count for pagination
$total_result = fetchOne($count_query, $count_params);
$total_candidates = $total_result ? ($total_result['total'] ?? 0) : 0;
$total_pages = $total_candidates > 0 ? ceil($total_candidates / $per_page) : 0;

// Add pagination
$query .= " LIMIT ? OFFSET ?";
$params[] = $per_page;
$params[] = $offset;

// Execute query
$candidates = fetchAll($query, $params) ?: [];

// Get education levels for filter
$education_levels = [
    'high_school' => 'High School',
    'associate' => 'Associate Degree',
    'bachelor' => 'Bachelor\'s Degree',
    'master' => 'Master\'s Degree',
    'phd' => 'PhD',
    'certificate' => 'Certificate',
    'diploma' => 'Diploma'
];

// Get flash message
$flash_message = null;
if (isset($_SESSION['flash_message'])) {
    $flash_message = [
        'message' => $_SESSION['flash_message'],
        'type' => $_SESSION['flash_message_type'] ?? 'info'
    ];
    unset($_SESSION['flash_message']);
    unset($_SESSION['flash_message_type']);
}

// Page title
$page_title = $view === 'saved' ? 'Saved Candidates' : 'Browse Candidates';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?> - TRANSNOW</title>
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body class="bg-gray-50">
    <!-- Notes Modal -->
    <div id="notesModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
        <div class="bg-white rounded-xl shadow-2xl p-6 max-w-md w-full mx-4">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-bold text-gray-900">Add Notes</h3>
                <button onclick="closeNotesModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form id="notesForm" method="POST">
                <input type="hidden" name="action" value="save_candidate">
                <input type="hidden" name="seeker_id" id="modalSeekerId">
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Notes (Optional)</label>
                    <textarea name="notes" id="modalNotes" rows="4" 
                              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                              placeholder="Add your notes about this candidate..."></textarea>
                </div>
                <div class="flex justify-end space-x-3">
                    <button type="button" onclick="closeNotesModal()" 
                            class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition">
                        Cancel
                    </button>
                    <button type="submit" 
                            class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
                        Save Candidate
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Notes Modal -->
    <div id="editNotesModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
        <div class="bg-white rounded-xl shadow-2xl p-6 max-w-md w-full mx-4">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-bold text-gray-900">Edit Notes</h3>
                <button onclick="closeEditNotesModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form id="editNotesForm" method="POST">
                <input type="hidden" name="action" value="update_notes">
                <input type="hidden" name="saved_id" id="editSavedId">
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Notes</label>
                    <textarea name="notes" id="editNotes" rows="4" 
                              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                              placeholder="Add your notes about this candidate..."></textarea>
                </div>
                <div class="flex justify-end space-x-3">
                    <button type="button" onclick="closeEditNotesModal()" 
                            class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition">
                        Cancel
                    </button>
                    <button type="submit" 
                            class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
                        Update Notes
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Navigation -->
    <nav class="bg-white shadow-lg border-b border-gray-200 fixed w-full z-40">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16">
                <div class="flex items-center">
                    <a href="dashboard.php" class="flex items-center space-x-3">
                        <i class="fas fa-briefcase text-2xl text-blue-600"></i>
                        <span class="font-bold text-xl text-gray-800">JobPortal</span>
                    </a>
                </div>
                
                <div class="flex items-center space-x-4">
                    <a href="../logout.php" class="text-gray-600 hover:text-red-600 transition">
                        <i class="fas fa-sign-out-alt mr-1"></i>Logout
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="pt-20 pb-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            
            <!-- Flash Messages -->
            <?php if ($flash_message): ?>
                <div class="mb-4 p-4 rounded-lg <?= $flash_message['type'] === 'success' ? 'bg-green-100 text-green-700 border border-green-400' : ($flash_message['type'] === 'error' ? 'bg-red-100 text-red-700 border border-red-400' : 'bg-blue-100 text-blue-700 border border-blue-400') ?>">
                    <i class="fas <?= $flash_message['type'] === 'success' ? 'fa-check-circle' : ($flash_message['type'] === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle') ?> mr-2"></i>
                    <?= htmlspecialchars($flash_message['message']) ?>
                </div>
            <?php endif; ?>
            
            <!-- Header -->
            <div class="mb-8 flex flex-col md:flex-row md:items-center md:justify-between">
                <div>
                    <h1 class="text-3xl font-bold text-gray-900"><?= $view === 'saved' ? 'Saved Candidates' : 'Browse Candidates' ?></h1>
                    <p class="text-gray-600 mt-2">
                        <?php if ($view === 'saved'): ?>
                            You have <?= count($saved_ids) ?> saved candidate<?= count($saved_ids) !== 1 ? 's' : '' ?>
                        <?php else: ?>
                            Find and save potential candidates for your job openings
                        <?php endif; ?>
                    </p>
                </div>
                <div class="mt-4 md:mt-0 flex space-x-3">
                    <a href="?view=all" class="px-4 py-2 <?= $view === 'all' ? 'bg-blue-600 text-white' : 'bg-white text-gray-700 hover:bg-gray-50' ?> rounded-lg shadow-sm transition font-medium border border-gray-300">
                        <i class="fas fa-users mr-2"></i>All Candidates
                    </a>
                    <a href="?view=saved" class="px-4 py-2 <?= $view === 'saved' ? 'bg-blue-600 text-white' : 'bg-white text-gray-700 hover:bg-gray-50' ?> rounded-lg shadow-sm transition font-medium border border-gray-300">
                        <i class="fas fa-bookmark mr-2"></i>Saved (<?= count($saved_ids) ?>)
                    </a>
                </div>
            </div>

            <!-- Search and Filters -->
            <div class="bg-white rounded-xl shadow-md p-6 mb-8">
                <form method="GET" action="" id="filterForm">
                    <?php if ($view === 'saved'): ?>
                        <input type="hidden" name="view" value="saved">
                    <?php endif; ?>
                    
                    <!-- Search Bar -->
                    <div class="mb-6">
                        <div class="relative">
                            <input type="text" name="search" id="search" 
                                   value="<?= htmlspecialchars($search) ?>"
                                   placeholder="Search by name, skills, headline, or bio..." 
                                   class="w-full pl-12 pr-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                            <i class="fas fa-search absolute left-4 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                        </div>
                    </div>
                    
                    <!-- Filter Grid -->
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                        <!-- Category Filter -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Category</label>
                            <select name="category" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="0">All Categories</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?= $cat['id'] ?>" <?= $category == $cat['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($cat['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <!-- Job Type Filter -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Job Type</label>
                            <select name="job_type" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="0">All Types</option>
                                <?php foreach ($job_types as $type): ?>
                                    <option value="<?= $type['id'] ?>" <?= $job_type == $type['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($type['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <!-- Location Filter -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Location</label>
                            <input type="text" name="location" value="<?= htmlspecialchars($location) ?>" 
                                   placeholder="City or region" 
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        
                        <!-- Language Filter -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Language</label>
                            <select name="language" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="0">All Languages</option>
                                <?php foreach ($languages as $lang): ?>
                                    <option value="<?= $lang['id'] ?>" <?= $language_id == $lang['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($lang['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <!-- Experience Range -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Min Experience</label>
                            <input type="number" name="experience_min" value="<?= $experience_min ?>" 
                                   min="0" max="50" placeholder="Years"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Max Experience</label>
                            <input type="number" name="experience_max" value="<?= $experience_max ?>" 
                                   min="0" max="50" placeholder="Years"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        
                        <!-- Education Filter -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Education</label>
                            <select name="education" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">All Education</option>
                                <?php foreach ($education_levels as $key => $value): ?>
                                    <option value="<?= $key ?>" <?= $education == $key ? 'selected' : '' ?>>
                                        <?= $value ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <!-- Salary Range -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Min Salary</label>
                            <input type="number" name="salary_min" value="<?= $salary_min ?>" 
                                   min="0" step="100" placeholder="GHS"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Max Salary</label>
                            <input type="number" name="salary_max" value="<?= $salary_max ?>" 
                                   min="0" step="100" placeholder="GHS"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        
                        <!-- Sort By -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Sort By</label>
                            <select name="sort" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="recent" <?= $sort == 'recent' ? 'selected' : '' ?>>Most Recent</option>
                                <option value="name_asc" <?= $sort == 'name_asc' ? 'selected' : '' ?>>Name (A-Z)</option>
                                <option value="name_desc" <?= $sort == 'name_desc' ? 'selected' : '' ?>>Name (Z-A)</option>
                                <option value="experience_high" <?= $sort == 'experience_high' ? 'selected' : '' ?>>Experience (High-Low)</option>
                                <option value="experience_low" <?= $sort == 'experience_low' ? 'selected' : '' ?>>Experience (Low-High)</option>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Filter Actions -->
                    <div class="mt-6 flex justify-between items-center">
                        <div>
                            <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition font-medium">
                                <i class="fas fa-search mr-2"></i>Apply Filters
                            </button>
                            <a href="?<?= $view === 'saved' ? 'view=saved' : '' ?>" class="ml-3 px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition">
                                <i class="fas fa-undo mr-2"></i>Reset
                            </a>
                        </div>
                        <div class="text-sm text-gray-600">
                            Showing <?= count($candidates) ?> of <?= $total_candidates ?> candidates
                        </div>
                    </div>
                </form>
            </div>

            <!-- Candidates Grid -->
            <?php if (empty($candidates)): ?>
                <div class="bg-white rounded-xl shadow-md p-12 text-center">
                    <div class="w-24 h-24 mx-auto bg-gray-100 rounded-full flex items-center justify-center mb-4">
                        <i class="fas fa-user-slash text-4xl text-gray-400"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-800 mb-2">No Candidates Found</h3>
                    <p class="text-gray-600 mb-6">
                        <?php if ($view === 'saved'): ?>
                            You haven't saved any candidates yet. Browse candidates and save them for later.
                        <?php else: ?>
                            No candidates match your current filters. Try adjusting your search criteria.
                        <?php endif; ?>
                    </p>
                    <?php if ($view === 'saved'): ?>
                        <a href="?view=all" class="inline-block px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
                            <i class="fas fa-users mr-2"></i>Browse All Candidates
                        </a>
                    <?php else: ?>
                        <a href="?" class="inline-block px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
                            <i class="fas fa-undo mr-2"></i>Reset Filters
                        </a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <?php foreach ($candidates as $candidate): 
                        $is_saved = in_array($candidate['id'], $saved_ids);
                        $saved_info = $is_saved && isset($saved_notes_map[$candidate['id']]) ? $saved_notes_map[$candidate['id']] : null;
                        
                        // Parse skills
                        $skills = [];
                        if (!empty($candidate['skills'])) {
                            if (is_string($candidate['skills']) && $candidate['skills'][0] === '[') {
                                $skills_raw = json_decode($candidate['skills'], true);
                                if (is_array($skills_raw)) {
                                    $skills = $skills_raw;
                                }
                            } else {
                                $skills = array_map('trim', explode(',', $candidate['skills']));
                            }
                        }
                        $skills = array_slice($skills, 0, 5);
                    ?>
                        <div class="bg-white rounded-xl shadow-md overflow-hidden hover:shadow-lg transition">
                            <div class="p-6">
                                <div class="flex items-start justify-between mb-4">
                                    <div class="flex items-center">
                                        <?php if (!empty($candidate['profile_picture'])): ?>
                                            <img src="<?= htmlspecialchars($candidate['profile_picture']) ?>" 
                                                 alt="Profile" 
                                                 class="h-16 w-16 rounded-full object-cover border-4 border-blue-100">
                                        <?php else: ?>
                                            <div class="h-16 w-16 rounded-full bg-gradient-to-r from-blue-500 to-blue-600 flex items-center justify-center text-white text-xl font-bold">
                                                <?= strtoupper(substr($candidate['first_name'] ?? 'U', 0, 1) . substr($candidate['last_name'] ?? 'S', 0, 1)) ?>
                                            </div>
                                        <?php endif; ?>
                                        <div class="ml-4">
                                            <h3 class="font-bold text-gray-900">
                                                <?= htmlspecialchars($candidate['first_name'] . ' ' . $candidate['last_name']) ?>
                                            </h3>
                                            <?php if (!empty($candidate['headline'])): ?>
                                                <p class="text-sm text-gray-600"><?= htmlspecialchars($candidate['headline']) ?></p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <!-- Save/Unsave Button -->
                                    <?php if ($is_saved): ?>
                                        <form method="POST" class="inline">
                                            <input type="hidden" name="action" value="unsave_candidate">
                                            <input type="hidden" name="seeker_id" value="<?= $candidate['id'] ?>">
                                            <button type="submit" class="text-blue-600 hover:text-blue-800" title="Remove from saved">
                                                <i class="fas fa-bookmark text-xl"></i>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <button onclick="showNotesModal(<?= $candidate['id'] ?>)" 
                                                class="text-gray-400 hover:text-blue-600 transition" title="Save candidate">
                                            <i class="far fa-bookmark text-xl"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Candidate Details -->
                                <div class="space-y-2 mb-4 text-sm">
                                    <div class="flex items-center">
                                        <i class="fas fa-map-marker-alt text-gray-400 w-5"></i>
                                        <span><?= htmlspecialchars($candidate['location'] ?? 'Location not specified') ?></span>
                                    </div>
                                    
                                    <div class="flex items-center">
                                        <i class="fas fa-briefcase text-gray-400 w-5"></i>
                                        <span><?= $candidate['years_experience'] ?> years experience</span>
                                    </div>
                                    
                                    <?php if (!empty($candidate['current_job_title'])): ?>
                                        <div class="flex items-center">
                                            <i class="fas fa-user-tie text-gray-400 w-5"></i>
                                            <span><?= htmlspecialchars($candidate['current_job_title']) ?></span>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($candidate['expected_salary_min']) || !empty($candidate['expected_salary_max'])): ?>
                                        <div class="flex items-center">
                                            <i class="fas fa-money-bill-wave text-gray-400 w-5"></i>
                                            <span>
                                                <?php if (!empty($candidate['expected_salary_min']) && !empty($candidate['expected_salary_max'])): ?>
                                                    GHS <?= number_format($candidate['expected_salary_min']) ?> - <?= number_format($candidate['expected_salary_max']) ?>
                                                <?php elseif (!empty($candidate['expected_salary_min'])): ?>
                                                    From GHS <?= number_format($candidate['expected_salary_min']) ?>
                                                <?php elseif (!empty($candidate['expected_salary_max'])): ?>
                                                    Up to GHS <?= number_format($candidate['expected_salary_max']) ?>
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($candidate['languages'])): ?>
                                        <div class="flex items-start">
                                            <i class="fas fa-language text-gray-400 w-5 mt-1"></i>
                                            <span class="flex-1"><?= htmlspecialchars($candidate['languages']) ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Skills -->
                                <?php if (!empty($skills)): ?>
                                    <div class="mb-4">
                                        <div class="flex flex-wrap gap-2">
                                            <?php foreach ($skills as $skill): ?>
                                                <span class="px-2 py-1 bg-blue-100 text-blue-800 text-xs rounded-full">
                                                    <?= htmlspecialchars($skill) ?>
                                                </span>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                
                                <!-- Saved Notes -->
                                <?php if ($is_saved && !empty($saved_info['notes'])): ?>
                                    <div class="mb-4 p-3 bg-yellow-50 rounded-lg border border-yellow-200">
                                        <p class="text-xs text-yellow-800">
                                            <i class="fas fa-sticky-note mr-1"></i>
                                            <?= nl2br(htmlspecialchars($saved_info['notes'])) ?>
                                        </p>
                                        <button onclick="editNotes(<?= $saved_info['saved_id'] ?>, '<?= htmlspecialchars(addslashes($saved_info['notes'])) ?>')" 
                                                class="text-xs text-blue-600 hover:text-blue-800 mt-1">
                                            <i class="fas fa-edit mr-1"></i>Edit
                                        </button>
                                    </div>
                                <?php endif; ?>
                                
                                <!-- Action Buttons -->
                                <div class="flex space-x-2">
                                    <a href="view-candidate.php?id=<?= $candidate['id'] ?>" 
                                       class="flex-1 px-3 py-2 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700 transition text-center">
                                        <i class="fas fa-eye mr-1"></i>View Profile
                                    </a>
                                    <a href="message.php" 
                                       class="flex-1 px-3 py-2 bg-green-600 text-white text-sm rounded-lg hover:bg-green-700 transition text-center">
                                        <i class="fas fa-comment mr-1"></i>Message
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="mt-8 flex justify-center">
                        <nav class="inline-flex rounded-md shadow">
                            <?php if ($page > 1): ?>
                                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" 
                                   class="px-4 py-2 bg-white border border-gray-300 text-sm font-medium text-gray-700 hover:bg-gray-50 rounded-l-md">
                                    <i class="fas fa-chevron-left mr-1"></i>Previous
                                </a>
                            <?php endif; ?>
                            
                            <?php
                            $start = max(1, $page - 2);
                            $end = min($total_pages, $page + 2);
                            for ($i = $start; $i <= $end; $i++):
                            ?>
                                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>" 
                                   class="px-4 py-2 bg-white border-t border-b border-gray-300 text-sm font-medium <?= $i == $page ? 'text-blue-600 bg-blue-50' : 'text-gray-700 hover:bg-gray-50' ?>">
                                    <?= $i ?>
                                </a>
                            <?php endfor; ?>
                            
                            <?php if ($page < $total_pages): ?>
                                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>" 
                                   class="px-4 py-2 bg-white border border-gray-300 text-sm font-medium text-gray-700 hover:bg-gray-50 rounded-r-md">
                                    Next<i class="fas fa-chevron-right ml-1"></i>
                                </a>
                            <?php endif; ?>
                        </nav>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- JavaScript -->
    <script>
        function showNotesModal(seekerId) {
            document.getElementById('modalSeekerId').value = seekerId;
            document.getElementById('modalNotes').value = '';
            document.getElementById('notesModal').classList.remove('hidden');
            document.getElementById('notesModal').classList.add('flex');
        }
        
        function closeNotesModal() {
            document.getElementById('notesModal').classList.add('hidden');
            document.getElementById('notesModal').classList.remove('flex');
        }
        
        function editNotes(savedId, notes) {
            document.getElementById('editSavedId').value = savedId;
            document.getElementById('editNotes').value = notes;
            document.getElementById('editNotesModal').classList.remove('hidden');
            document.getElementById('editNotesModal').classList.add('flex');
        }
        
        function closeEditNotesModal() {
            document.getElementById('editNotesModal').classList.add('hidden');
            document.getElementById('editNotesModal').classList.remove('flex');
        }
        
        // Close modals when clicking outside
        window.onclick = function(event) {
            const notesModal = document.getElementById('notesModal');
            const editNotesModal = document.getElementById('editNotesModal');
            
            if (event.target === notesModal) {
                closeNotesModal();
            }
            if (event.target === editNotesModal) {
                closeEditNotesModal();
            }
        }
    </script>
</body>
</html>