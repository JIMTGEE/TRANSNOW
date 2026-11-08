<?php
// config/functions.php
// Comprehensive utility functions for the Jobwall application
// Expanded with more features for authentication, job management, seeker/employer operations, notifications, and more.
// All functions are organized into sections for better readability.

// Require the database connection
require_once __DIR__ . '/database.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// SECTION 1: Input Sanitization and Validation Functions

/**
 * Sanitize input string to prevent XSS and SQL injection.
 * @param string $input The input string to sanitize.
 * @return string Sanitized input.
 */
function sanitizeInput(string $input): string {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

/**
 * Sanitize and validate email.
 * @param string $email The email to sanitize and validate.
 * @return string|false Sanitized email or false if invalid.
 */
function sanitizeEmail(string $email) {
    $email = filter_var(trim($email), FILTER_SANITIZE_EMAIL);
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : false;
}

/**
 * Sanitize phone number (international format).
 * @param string $phone The phone number.
 * @return string Sanitized phone or empty string if invalid.
 */
function sanitizePhone(string $phone): string {
    $phone = preg_replace('/[^0-9+]/', '', trim($phone));
    return (strlen($phone) >= 10 && strlen($phone) <= 15) ? $phone : '';
}

/**
 * Validate password strength (at least 8 chars, 1 upper, 1 lower, 1 number, 1 special).
 * @param string $password The password to validate.
 * @return bool True if strong.
 */
function isStrongPassword(string $password): bool {
    return preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{8,}$/', $password);
}

/**
 * Validate enum value against allowed options.
 * @param string $value Value to check.
 * @param array $enumOptions Array of allowed enum values.
 * @return bool True if valid.
 */
function isValidEnum(string $value, array $enumOptions): bool {
    return in_array($value, $enumOptions);
}

// Example enum options from DB schema
const APPLICATION_STATUSES = ['applied', 'reviewed', 'shortlisted', 'interview', 'offered', 'hired', 'rejected'];
const JOB_EXPERIENCE_LEVELS = ['entry', 'mid', 'senior', 'executive'];
const JOB_LOCATION_TYPES = ['onsite', 'remote', 'hybrid'];
const JOB_STATUSES = ['draft', 'active', 'paused', 'closed', 'expired'];
const USER_TYPES = ['seeker', 'employer', 'admin'];

// SECTION 2: Authentication and User Management Functions

/**
 * Hash a password using bcrypt.
 * @param string $password The plain password.
 * @return string Hashed password.
 */
function hashPassword(string $password): string {
    return password_hash($password, PASSWORD_BCRYPT);
}

/**
 * Verify a password against a hash.
 * @param string $password Plain password.
 * @param string $hash Hashed password.
 * @return bool True if matches.
 */
function verifyPassword(string $password, string $hash): bool {
    return password_verify($password, $hash);
}

/**
 * Generate a random token (e.g., for verification or reset).
 * @param int $length Token length.
 * @return string The token.
 */
function generateToken(int $length = 32): string {
    return bin2hex(random_bytes($length / 2));
}

/**
 * Register a new user.
 * @param array $data User data (email, password, user_type, etc.).
 * @return int|false User ID or false on failure.
 */
function registerUser(array $data) {
    $email = sanitizeEmail($data['email']);
    if (!$email || !isStrongPassword($data['password'])) {
        return false;
    }

    $hash = hashPassword($data['password']);
    $userType = isset($data['user_type']) && in_array($data['user_type'], USER_TYPES) ? $data['user_type'] : 'seeker';
    $verificationToken = generateToken();

    $sql = "INSERT INTO users (email, password_hash, user_type, verification_token) VALUES (?, ?, ?, ?)";
    $params = [$email, $hash, $userType, $verificationToken];

    try {
        $userId = insert($sql, $params);
        // Create seeker or employer profile based on type
        if ($userType === 'seeker') {
            createSeekerProfile($userId, $data);
        } elseif ($userType === 'employer') {
            createEmployerProfile($userId, $data);
        }
        return $userId;
    } catch (PDOException $e) {
        error_log("User registration failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Create seeker profile after registration.
 * @param int $userId User ID.
 * @param array $data Additional data.
 */
function createSeekerProfile(int $userId, array $data) {
    $firstName = sanitizeInput($data['first_name'] ?? '');
    $lastName = sanitizeInput($data['last_name'] ?? '');
    $phone = sanitizePhone($data['phone'] ?? '');

    $sql = "INSERT INTO seekers (user_id, first_name, last_name, phone) VALUES (?, ?, ?, ?)";
    insert($sql, [$userId, $firstName, $lastName, $phone]);
}

/**
 * Create employer profile after registration.
 * @param int $userId User ID.
 * @param array $data Additional data.
 */
function createEmployerProfile(int $userId, array $data) {
    $companyName = sanitizeInput($data['company_name'] ?? '');
    $companyEmail = sanitizeEmail($data['company_email'] ?? '');

    $sql = "INSERT INTO employers (user_id, company_name, company_email) VALUES (?, ?, ?)";
    insert($sql, [$userId, $companyName, $companyEmail]);
}

/**
 * Login a user.
 * @param string $email Email.
 * @param string $password Password.
 * @return bool True on success.
 */
function loginUser(string $email, string $password): bool {
    $sql = "SELECT id, password_hash, user_type FROM users WHERE email = ?";
    $user = fetchOne($sql, [$email]);

    if ($user && verifyPassword($password, $user['password_hash'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_type'] = $user['user_type'];
        updateLastLogin($user['id']);
        return true;
    }
    return false;
}

/**
 * Update last login timestamp.
 * @param int $userId User ID.
 */
function updateLastLogin(int $userId) {
    $sql = "UPDATE users SET last_login = NOW() WHERE id = ?";
    executeQuery($sql, [$userId]);
}

/**
 * Check if user is logged in.
 * @return bool True if logged in.
 */
function isLoggedIn(): bool {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Get current user ID.
 * @return int|false User ID or false.
 */
function getCurrentUserId() {
    return isLoggedIn() ? $_SESSION['user_id'] : false;
}

/**
 * Get current user type.
 * @return string|false User type or false.
 */
function getUserType() {
    return isLoggedIn() ? $_SESSION['user_type'] : false;
}

/**
 * Require authentication and redirect if not logged in.
 * @param string $redirectTo Redirect path if not authenticated.
 */
function requireAuth(string $redirectTo = '/login.php') {
    if (!isLoggedIn()) {
        redirect($redirectTo);
    }
}

/**
 * Require specific user type.
 * @param string $requiredType Required user type.
 * @param string $redirectTo Redirect if not matching.
 */
function requireUserType(string $requiredType, string $redirectTo = '/login.php') {
    requireAuth();
    if (getUserType() !== $requiredType) {
        redirect($redirectTo);
    }
}

/**
 * Logout user.
 */
function logoutUser() {
    session_destroy();
    redirect('/login.php');
}

/**
 * Verify user email with token.
 * @param string $email Email.
 * @param string $token Token.
 * @return bool True if verified.
 */
function verifyUserEmail(string $email, string $token): bool {
    $sql = "UPDATE users SET is_verified = 1, verification_token = NULL WHERE email = ? AND verification_token = ?";
    $stmt = executeQuery($sql, [$email, $token]);
    return $stmt->rowCount() > 0;
}

/**
 * Initiate password reset.
 * @param string $email Email.
 * @return string|false Reset token or false.
 */
function initiatePasswordReset(string $email) {
    $token = generateToken();
    $expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));

    $sql = "UPDATE users SET reset_token = ?, reset_token_expiry = ? WHERE email = ?";
    executeQuery($sql, [$token, $expiry, $email]);

    // Queue email (implement sendResetEmail separately)
    return $token;
}

/**
 * Reset password with token.
 * @param string $token Token.
 * @param string $newPassword New password.
 * @return bool True on success.
 */
function resetPassword(string $token, string $newPassword): bool {
    if (!isStrongPassword($newPassword)) {
        return false;
    }

    $hash = hashPassword($newPassword);
    $sql = "UPDATE users SET password_hash = ?, reset_token = NULL, reset_token_expiry = NULL 
            WHERE reset_token = ? AND reset_token_expiry > NOW()";
    $stmt = executeQuery($sql, [$hash, $token]);
    return $stmt->rowCount() > 0;
}

// SECTION 3: Slug and URL Helpers

/**
 * Generate a URL-friendly slug.
 * @param string $string The input string.
 * @return string The generated slug.
 */
function generateSlug(string $string): string {
    $string = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $string), '-'));
    return $string;
}

/**
 * Generate unique slug for jobs or categories.
 * @param string $title Title to slugify.
 * @param string $table Table name (jobs or job_categories).
 * @return string Unique slug.
 */
function generateUniqueSlug(string $title, string $table): string {
    $baseSlug = generateSlug($title);
    $slug = $baseSlug;
    $count = 1;

    while (true) {
        $sql = "SELECT COUNT(*) FROM $table WHERE slug = ?";
        $exists = fetchOne($sql, [$slug])['COUNT(*)'];
        if ($exists == 0) {
            break;
        }
        $slug = $baseSlug . '-' . $count++;
    }
    return $slug;
}

/**
 * Redirect to a path.
 * @param string $path Path to redirect to.
 */
function redirect(string $path) {
    header("Location: $path");
    exit;
}

// SECTION 4: Date, Time, and Formatting Helpers

/**
 * Format a timestamp to a readable date.
 * @param string $timestamp The timestamp.
 * @param string $format The desired format.
 * @return string Formatted date.
 */
function formatDate(string $timestamp, string $format = 'Y-m-d H:i:s'): string {
    return date($format, strtotime($timestamp));
}

/**
 * Calculate days between two dates.
 * @param string $date1 First date.
 * @param string $date2 Second date.
 * @return int Days difference.
 */
function daysBetween(string $date1, string $date2): int {
    $diff = strtotime($date2) - strtotime($date1);
    return abs(round($diff / 86400));
}

/**
 * Get time ago string (e.g., "2 hours ago").
 * @param string $timestamp Timestamp.
 * @return string Time ago.
 */
function timeAgo(string $timestamp): string {
    $time = strtotime($timestamp);
    $diff = time() - $time;

    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . ' minutes ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hours ago';
    if ($diff < 2592000) return floor($diff / 86400) . ' days ago';
    if ($diff < 31536000) return floor($diff / 2592000) . ' months ago';
    return floor($diff / 31536000) . ' years ago';
}

// SECTION 5: Database Helper Functions (Extended)

/**
 * Execute a prepared query with parameters.
 * @param string $sql SQL query.
 * @param array $params Parameters.
 * @return PDOStatement The statement object.
 */
function executeQuery(string $sql, array $params = []): PDOStatement {
    $stmt = getDB()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

/**
 * Fetch all rows from a query.
 * @param string $sql SQL query.
 * @param array $params Parameters.
 * @return array Fetched rows.
 */
function fetchAll(string $sql, array $params = []): array {
    return executeQuery($sql, $params)->fetchAll();
}

/**
 * Fetch a single row.
 * @param string $sql SQL query.
 * @param array $params Parameters.
 * @return array|false Fetched row or false.
 */
function fetchOne(string $sql, array $params = []) {
    return executeQuery($sql, $params)->fetch();
}

/**
 * Insert data and return last insert ID.
 * @param string $sql SQL insert query.
 * @param array $params Parameters.
 * @return int Last insert ID.
 */
function insert(string $sql, array $params = []): int {
    executeQuery($sql, $params);
    return getDB()->lastInsertId();
}

/**
 * Update data and return affected rows.
 * @param string $sql SQL update query.
 * @param array $params Parameters.
 * @return int Affected rows.
 */
function update(string $sql, array $params = []): int {
    return executeQuery($sql, $params)->rowCount();
}

/**
 * Delete data and return affected rows.
 * @param string $sql SQL delete query.
 * @param array $params Parameters.
 * @return int Affected rows.
 */
function delete(string $sql, array $params = []): int {
    return executeQuery($sql, $params)->rowCount();
}

// SECTION 6: File Upload and Management Helpers

/**
 * Upload a file to a specified directory.
 * @param array $file $_FILES array.
 * @param string $targetDir Target directory (e.g., 'uploads/resumes/').
 * @param array $allowedExtensions Allowed file extensions.
 * @param int $maxSize Max file size in bytes.
 * @return string|false Uploaded file path or false on error.
 */
function uploadFile(array $file, string $targetDir, array $allowedExtensions = ['pdf', 'jpg', 'png'], int $maxSize = 5000000): string|false {
    $targetDir = rtrim($targetDir, '/') . '/';
    if (!file_exists($targetDir)) {
        mkdir($targetDir, 0755, true);
    }

    $fileName = basename(sanitizeInput($file['name']));
    $targetFile = $targetDir . time() . '_' . $fileName;
    $fileType = strtolower(pathinfo($targetFile, PATHINFO_EXTENSION));

    if (!in_array($fileType, $allowedExtensions)) {
        return false;
    }

    if ($file['size'] > $maxSize) {
        return false;
    }

    if (move_uploaded_file($file['tmp_name'], $targetFile)) {
        return $targetFile;
    }
    return false;
}

/**
 * Delete a file.
 * @param string $filePath Path to file.
 * @return bool True if deleted.
 */
function deleteFile(string $filePath): bool {
    return file_exists($filePath) ? unlink($filePath) : false;
}

// SECTION 7: Job Management Functions

/**
 * Post a new job.
 * @param array $data Job data.
 * @return int|false Job ID or false.
 */
function postJob(array $data): int|false {
    requireUserType('employer');

    $employerId = getCurrentUserId();
    $title = sanitizeInput($data['title']);
    $slug = generateUniqueSlug($title, 'jobs');
    $description = sanitizeInput($data['description']);
    // Add other fields similarly...

    $sql = "INSERT INTO jobs (employer_id, title, slug, description, /* add other fields */) VALUES (?, ?, ?, ? /* add params */)";
    try {
        $jobId = insert($sql, [$employerId, $title, $slug, $description /* add params */]);
        // Add skills if provided
        if (isset($data['skills']) && is_array($data['skills'])) {
            addJobSkills($jobId, $data['skills']);
        }
        return $jobId;
    } catch (PDOException $e) {
        error_log("Job posting failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Add skills to a job.
 * @param int $jobId Job ID.
 * @param array $skills Array of skill names.
 */
function addJobSkills(int $jobId, array $skills) {
    foreach ($skills as $skill) {
        $skillName = sanitizeInput($skill);
        $isRequired = isset($data['is_required'][$skill]) ? 1 : 0; // Assuming form data
        $sql = "INSERT INTO job_skills (job_id, skill_name, is_required) VALUES (?, ?, ?)";
        insert($sql, [$jobId, $skillName, $isRequired]);
    }
}

/**
 * Get job by ID.
 * @param int $jobId Job ID.
 * @return array|false Job data.
 */
function getJobById(int $jobId) {
    $sql = "SELECT * FROM jobs WHERE id = ?";
    return fetchOne($sql, [$jobId]);
}

/**
 * Update job status.
 * @param int $jobId Job ID.
 * @param string $status New status.
 * @return bool True on success.
 */
function updateJobStatus(int $jobId, string $status): bool {
    if (!isValidEnum($status, JOB_STATUSES)) {
        return false;
    }
    $sql = "UPDATE jobs SET status = ? WHERE id = ?";
    return update($sql, [$status, $jobId]) > 0;
}

/**
 * Increment job view count.
 * @param int $jobId Job ID.
 * @param int $userId User ID (optional).
 */
function logJobView(int $jobId, int $userId = null) {
    $ip = $_SERVER['REMOTE_ADDR'];
    $userAgent = $_SERVER['HTTP_USER_AGENT'];

    $sql = "INSERT INTO job_views (job_id, user_id, ip_address, user_agent) VALUES (?, ?, ?, ?)";
    insert($sql, [$jobId, $userId, $ip, $userAgent]);

    // Update total_views
    $updateSql = "UPDATE jobs SET total_views = total_views + 1 WHERE id = ?";
    executeQuery($updateSql, [$jobId]);
}

// SECTION 8: Application Management Functions

/**
 * Submit a job application.
 * @param array $data Application data.
 * @return int|false Application ID or false.
 */
function submitApplication(array $data): int|false {
    requireUserType('seeker');

    $jobId = (int)$data['job_id'];
    $seekerId = getCurrentUserId();
    $coverLetter = sanitizeInput($data['cover_letter'] ?? '');
    // Handle resume upload if provided
    $resumeUrl = isset($_FILES['resume']) ? uploadFile($_FILES['resume'], 'uploads/resumes/', ['pdf']) : ($data['resume_url'] ?? '');

    $sql = "INSERT INTO applications (job_id, seeker_id, cover_letter, resume_url /* add other fields */) VALUES (?, ?, ?, ? /* add params */)";
    try {
        $appId = insert($sql, [$jobId, $seekerId, $coverLetter, $resumeUrl /* add params */]);
        // Update total_applications on job
        $updateSql = "UPDATE jobs SET total_applications = total_applications + 1 WHERE id = ?";
        executeQuery($updateSql, [$jobId]);
        // Calculate and set match_score
        $matchScore = calculateMatchScore(getJobById($jobId), getSeekerById($seekerId));
        updateApplicationMatchScore($appId, $matchScore);
        return $appId;
    } catch (PDOException $e) {
        error_log("Application submission failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Update application status.
 * @param int $appId Application ID.
 * @param string $status New status.
 * @return bool True on success.
 */
function updateApplicationStatus(int $appId, string $status): bool {
    if (!isValidEnum($status, APPLICATION_STATUSES)) {
        return false;
    }
    $sql = "UPDATE applications SET application_status = ? WHERE id = ?";
    return update($sql, [$status, $appId]) > 0;
}

/**
 * Log application view by employer.
 * @param int $appId Application ID.
 * @param int $employerId Employer ID.
 */
function logApplicationView(int $appId, int $employerId) {
    $sql = "INSERT INTO application_views (application_id, employer_id) VALUES (?, ?)";
    insert($sql, [$appId, $employerId]);
}

// SECTION 9: Matching and Recommendation Functions

/**
 * Calculate match score between job and seeker.
 * @param array $job Job data.
 * @param array $seeker Seeker data.
 * @return float Match score (0-100).
 */
function calculateMatchScore(array $job, array $seeker): float {
    $score = 0;

    // Skills match
    $jobSkillsSql = "SELECT skill_name FROM job_skills WHERE job_id = ?";
    $jobSkills = array_column(fetchAll($jobSkillsSql, [$job['id']]), 'skill_name');
    $seekerSkills = explode(',', $seeker['skills'] ?? '');
    $commonSkills = array_intersect($jobSkills, $seekerSkills);
    $score += (count($commonSkills) / max(1, count($jobSkills))) * 40;

    // Experience match
    $jobExp = ['entry' => 0, 'mid' => 3, 'senior' => 5, 'executive' => 10];
    $expDiff = abs(($seeker['years_experience'] ?? 0) - ($jobExp[$job['experience_level']] ?? 0));
    $score += max(0, 30 - $expDiff * 5);

    // Location match
    if ($job['location_type'] === 'remote' || strpos($seeker['location'] ?? '', $job['city'] ?? '') !== false) {
        $score += 20;
    }

    // Salary match
    if (($seeker['expected_salary_min'] ?? 0) <= ($job['salary_max'] ?? 0) && ($seeker['expected_salary_max'] ?? INF) >= ($job['salary_min'] ?? 0)) {
        $score += 10;
    }

    // Education match (simple)
    if (strtolower($seeker['highest_education'] ?? '') >= strtolower($job['education_level'] ?? '')) {
        $score += 10;
    }

    return min(100, max(0, round($score, 2)));
}

/**
 * Update match score in application.
 * @param int $appId Application ID.
 * @param float $score Score.
 */
function updateApplicationMatchScore(int $appId, float $score) {
    $sql = "UPDATE applications SET match_score = ? WHERE id = ?";
    executeQuery($sql, [$score, $appId]);
}

/**
 * Get recommended jobs for seeker.
 * @param int $seekerId Seeker ID.
 * @param int $limit Limit.
 * @return array Jobs.
 */
function getRecommendedJobs(int $seekerId, int $limit = 10): array {
    $seeker = getSeekerById($seekerId);
    $sql = "SELECT * FROM jobs WHERE status = 'active' LIMIT ?";
    $jobs = fetchAll($sql, [$limit]);
    foreach ($jobs as &$job) {
        $job['match_score'] = calculateMatchScore($job, $seeker);
    }
    usort($jobs, fn($a, $b) => $b['match_score'] <=> $a['match_score']);
    return $jobs;
}

// SECTION 10: Seeker and Employer Profile Functions

/**
 * Get seeker by ID.
 * @param int $seekerId Seeker ID.
 * @return array|false Seeker data.
 */
function getSeekerById(int $seekerId) {
    $sql = "SELECT * FROM seekers WHERE id = ?";
    return fetchOne($sql, [$seekerId]);
}

/**
 * Update seeker profile.
 * @param int $seekerId Seeker ID.
 * @param array $data Data to update.
 * @return bool True on success.
 */
function updateSeekerProfile(int $seekerId, array $data): bool {
    // Sanitize inputs...
    $fields = [];
    $params = [];
    foreach ($data as $key => $value) {
        if (in_array($key, ['first_name', 'last_name', 'phone', 'whatsapp_number', 'headline', 'bio', 'location', 'current_job_title', 'current_company', 'years_experience', 'highest_education', 'skills', 'expected_salary_min', 'expected_salary_max'])) {
            $fields[] = "$key = ?";
            $params[] = sanitizeInput($value); // Or specific sanitizers
        }
    }
    if (empty($fields)) return false;

    // Handle profile picture upload
    if (isset($_FILES['profile_picture'])) {
        $profilePic = uploadFile($_FILES['profile_picture'], 'uploads/profiles/', ['jpg', 'png']);
        if ($profilePic) {
            $fields[] = "profile_picture = ?";
            $params[] = $profilePic;
        }
    }

    // Handle resume
    if (isset($_FILES['resume'])) {
        $resume = uploadFile($_FILES['resume'], 'uploads/resumes/', ['pdf']);
        if ($resume) {
            $fields[] = "resume_url = ?";
            $params[] = $resume;
        }
    }

    $sql = "UPDATE seekers SET " . implode(', ', $fields) . " WHERE id = ?";
    $params[] = $seekerId;
    return update($sql, $params) > 0;
}

/**
 * Get employer by ID.
 * @param int $employerId Employer ID.
 * @return array|false Employer data.
 */
function getEmployerById(int $employerId) {
    $sql = "SELECT * FROM employers WHERE id = ?";
    return fetchOne($sql, [$employerId]);
}

/**
 * Update employer profile.
 * @param int $employerId Employer ID.
 * @param array $data Data to update.
 * @return bool True on success.
 */
function updateEmployerProfile(int $employerId, array $data): bool {
    // Similar to updateSeekerProfile, sanitize and update fields like company_name, company_email, etc.
    // Handle company_logo upload
    $fields = [];
    $params = [];
    // ... implement similarly
    return true; // Placeholder
}

// SECTION 11: Notification and Email Queue Functions

/**
 * Queue a notification.
 * @param int $userId User ID.
 * @param string $type Notification type.
 * @param array $metadata Metadata.
 * @param string $channel Channel (email, whatsapp, etc.).
 */
function queueNotification(int $userId, string $type, array $metadata, string $channel = 'email') {
    $message = generateNotificationMessage($type, $metadata); // Implement generator
    $sql = "INSERT INTO notifications (user_id, notification_type, message, metadata, channel) VALUES (?, ?, ?, ?, ?)";
    insert($sql, [$userId, $type, $message, json_encode($metadata), $channel]);
}

/**
 * Generate notification message from template.
 * @param string $type Type.
 * @param array $metadata Metadata.
 * @return string Message.
 */
function generateNotificationMessage(string $type, array $metadata): string {
    // Fetch template from notification_templates
    $sql = "SELECT content FROM notification_templates WHERE name = ?";
    $template = fetchOne($sql, [$type])['content'] ?? '';
    foreach ($metadata as $key => $value) {
        $template = str_replace("{{$key}}", $value, $template);
    }
    return $template;
}

/**
 * Queue email.
 * @param string $recipient Recipient email.
 * @param string $subject Subject.
 * @param string $body Body.
 * @param string $priority Priority.
 */
function queueEmail(string $recipient, string $subject, string $body, string $priority = 'normal') {
    $sql = "INSERT INTO email_queue (recipient_email, subject, body, priority) VALUES (?, ?, ?, ?)";
    insert($sql, [$recipient, $subject, $body, $priority]);
}

// SECTION 12: Interview Management Functions

/**
 * Schedule an interview.
 * @param array $data Interview data.
 * @return int|false Interview ID.
 */
function scheduleInterview(array $data): int|false {
    $appId = (int)$data['application_id'];
    $type = $data['interview_type'] ?? 'video';
    $date = $data['interview_date'];
    // ... other fields

    $sql = "INSERT INTO interviews (application_id, interview_type, interview_date /* add fields */) VALUES (?, ?, ? /* add params */)";
    try {
        return insert($sql, [$appId, $type, $date /* add params */]);
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Confirm interview.
 * @param int $interviewId Interview ID.
 * @param string $confirmer 'seeker' or 'employer'.
 * @return bool True on success.
 */
function confirmInterview(int $interviewId, string $confirmer) {
    $field = $confirmer === 'seeker' ? 'seeker_confirmed' : 'employer_confirmed';
    $sql = "UPDATE interviews SET $field = 1 WHERE id = ?";
    return update($sql, [1, $interviewId]) > 0;
}

// SECTION 13: Saved Items Functions

/**
 * Save a job for seeker.
 * @param int $seekerId Seeker ID.
 * @param int $jobId Job ID.
 * @return bool True if saved.
 */
function saveJob(int $seekerId, int $jobId): bool {
    $sql = "INSERT IGNORE INTO saved_jobs (seeker_id, job_id) VALUES (?, ?)";
    return insert($sql, [$seekerId, $jobId]) > 0;
}

/**
 * Unsave a job.
 * @param int $seekerId Seeker ID.
 * @param int $jobId Job ID.
 * @return bool True if unsaved.
 */
function unsaveJob(int $seekerId, int $jobId): bool {
    $sql = "DELETE FROM saved_jobs WHERE seeker_id = ? AND job_id = ?";
    return delete($sql, [$seekerId, $jobId]) > 0;
}

/**
 * Save a candidate for employer.
 * @param int $employerId Employer ID.
 * @param int $seekerId Seeker ID.
 * @return bool True if saved.
 */
function saveCandidate(int $employerId, int $seekerId): bool {
    $sql = "INSERT IGNORE INTO saved_candidates (employer_id, seeker_id) VALUES (?, ?)";
    return insert($sql, [$employerId, $seekerId]) > 0;
}

// SECTION 14: Messaging Functions

/**
 * Send a message.
 * @param array $data Message data.
 * @return int|false Message ID.
 */
function sendMessage(array $data): int|false {
    $senderId = getCurrentUserId();
    $receiverId = (int)$data['receiver_id'];
    $message = sanitizeInput($data['message']);
    $appId = isset($data['application_id']) ? (int)$data['application_id'] : null;

    $sql = "INSERT INTO messages (sender_id, receiver_id, application_id, message) VALUES (?, ?, ?, ?)";
    try {
        return insert($sql, [$senderId, $receiverId, $appId, $message]);
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Mark message as read.
 * @param int $messageId Message ID.
 * @return bool True on success.
 */
function markMessageRead(int $messageId): bool {
    $sql = "UPDATE messages SET is_read = 1, read_at = NOW() WHERE id = ?";
    return update($sql, [$messageId]) > 0;
}

// SECTION 15: Flash Messages and JSON Responses

/**
 * Set a flash message.
 * @param string $type 'success', 'error', etc.
 * @param string $message Message.
 */
function setFlashMessage(string $type, string $message) {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

/**
 * Get and clear flash message.
 * @return string HTML message or empty.
 */
function getFlashMessage(): string {
    if (isset($_SESSION['flash'])) {
        $type = $_SESSION['flash']['type'];
        $msg = $_SESSION['flash']['message'];
        unset($_SESSION['flash']);
        return "<div class='alert alert-$type'>$msg</div>";
    }
    return '';
}

/**
 * Send JSON response.
 * @param array $data Data.
 * @param int $status HTTP status.
 */
function jsonResponse(array $data, int $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// SECTION 16: Settings Management

/**
 * Get setting value by key.
 * @param string $key Setting key.
 * @param mixed $default Default value.
 * @return mixed Setting value.
 */
function getSetting(string $key, $default = null) {
    $sql = "SELECT setting_value, setting_type FROM settings WHERE setting_key = ?";
    $setting = fetchOne($sql, [$key]);
    if (!$setting) return $default;

    switch ($setting['setting_type']) {
        case 'json':
            return json_decode($setting['setting_value'], true);
        case 'boolean':
            return (bool)$setting['setting_value'];
        case 'integer':
            return (int)$setting['setting_value'];
        default:
            return $setting['setting_value'];
    }
}

/**
 * Update setting.
 * @param string $key Key.
 * @param mixed $value Value.
 * @param string $type Type.
 * @return bool True on success.
 */
function updateSetting(string $key, $value, string $type = 'string'): bool {
    if ($type === 'json') $value = json_encode($value);
    if ($type === 'boolean') $value = (int)$value;

    $sql = "INSERT INTO settings (setting_key, setting_value, setting_type) VALUES (?, ?, ?) 
            ON DUPLICATE KEY UPDATE setting_value = ?, setting_type = ?";
    return executeQuery($sql, [$key, $value, $type, $value, $type])->rowCount() > 0;
}

// SECTION 17: Miscellaneous Helpers

/**
 * Get all job categories.
 * @return array Categories.
 */
function getJobCategories(): array {
    $sql = "SELECT * FROM job_categories WHERE is_active = 1";
    return fetchAll($sql);
}

/**
 * Get all job types.
 * @return array Types.
 */
function getJobTypes(): array {
    $sql = "SELECT * FROM job_types WHERE is_active = 1";
    return fetchAll($sql);
}

/**
 * Log error.
 * @param string $message Message.
 */
function logError(string $message) {
    error_log($message, 3, __DIR__ . '/../logs/error.log');
}

// Add more functions as the project evolves, e.g., for admin reports, preferences matching, etc.
?>