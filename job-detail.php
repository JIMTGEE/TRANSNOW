<?php
/**
 * job_detail.php - TransNow Ghana Job Detail Page
 * Version: 2.1 - Fixed QR Code with QuickChart API
 */

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/functions.php';

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Get job ID from URL
$job_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$job_id) {
    header('Location: /jobs.php');
    exit;
}

try {
    $pdo = getDB();
} catch (Exception $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Fetch job details with all fields
$job = null;
try {
    $job_sql = "
        SELECT j.*
        FROM jobs j
        WHERE j.id = ? AND j.status = 'Active'
    ";
    $job_stmt = $pdo->prepare($job_sql);
    $job_stmt->execute([$job_id]);
    $job = $job_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$job) {
        header('Location: /jobs.php');
        exit;
    }
    
    // Increment view count
    $update_sql = "UPDATE jobs SET total_views = total_views + 1 WHERE id = ?";
    $update_stmt = $pdo->prepare($update_sql);
    $update_stmt->execute([$job_id]);
    $job['total_views']++;
    
} catch (PDOException $e) {
    error_log("Job detail query error: " . $e->getMessage());
    header('Location: /jobs.php');
    exit;
}

// Fetch similar jobs (same category or location)
$similar_jobs = [];
try {
    $similar_sql = "
        SELECT id, title, slug, city, employment_type, job_category, 
               salary_min, salary_max, salary_currency, salary_type,
               location_type, created_at, overview, description
        FROM jobs 
        WHERE status = 'Active' 
        AND id != ? 
        AND (job_category = ? OR city = ?)
        ORDER BY created_at DESC 
        LIMIT 4
    ";
    $similar_stmt = $pdo->prepare($similar_sql);
    $similar_stmt->execute([$job_id, $job['job_category'], $job['city']]);
    $similar_jobs = $similar_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Similar jobs query error: " . $e->getMessage());
    $similar_jobs = [];
}

// Saved jobs functionality
$saved_jobs = $_SESSION['saved_jobs'] ?? [];
$is_saved = in_array($job_id, $saved_jobs);

// Helper functions
function timeAgo($datetime) {
    if (empty($datetime)) return 'Recently';
    $time = strtotime($datetime);
    $diff = time() - $time;
    
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . ' min ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hour' . (floor($diff / 3600) > 1 ? 's' : '') . ' ago';
    if ($diff < 604800) return floor($diff / 86400) . ' day' . (floor($diff / 86400) > 1 ? 's' : '') . ' ago';
    if ($diff < 2592000) return floor($diff / 604800) . ' week' . (floor($diff / 604800) > 1 ? 's' : '') . ' ago';
    return date('M d, Y', $time);
}

function formatSalaryDisplay($min, $max, $currency, $type) {
    if (!$min && !$max) return 'Salary not disclosed';
    
    $currency_symbol = $currency === 'GHS' ? '₵' : ($currency === 'USD' ? '$' : $currency);
    $type_display = $type === 'Monthly' ? 'month' : ($type === 'Yearly' ? 'year' : 'hour');
    
    if ($min && $max && $min != $max) {
        return $currency_symbol . number_format($min) . ' - ' . $currency_symbol . number_format($max) . ' / ' . $type_display;
    } elseif ($min) {
        return $currency_symbol . number_format($min) . ' / ' . $type_display;
    } elseif ($max) {
        return 'Up to ' . $currency_symbol . number_format($max) . ' / ' . $type_display;
    }
    return 'Salary not disclosed';
}

function getCompanyDisplay($job) {
    $description = $job['description'] ?? '';
    $overview = $job['overview'] ?? '';
    $title = $job['title'] ?? '';
    
    // Try to extract company from description
    if (preg_match('/Company:\s*([^\n]+)/i', $description, $matches)) {
        return trim($matches[1]);
    }
    if (preg_match('/Company:\s*([^\n]+)/i', $overview, $matches)) {
        return trim($matches[1]);
    }
    
    // Try to extract from title (e.g., "Company Name is hiring...")
    if (preg_match('/^([A-Z][A-Za-z\s&]+)(?:\s+is\s+hiring|\s+-|\s+–|\s*$)/', $title, $matches)) {
        return trim($matches[1]);
    }
    
    $defaultCompanies = [
        'Translation' => 'Translation & Language Services',
        'Sales' => 'Sales & Marketing Company',
        'Education' => 'Educational Institution',
        'Administration' => 'Administrative Services',
        'Cooking' => 'Food & Hospitality Services',
        'Driving' => 'Transportation Services',
        'Maintenance' => 'Maintenance & Technical Services',
        'Construction' => 'Construction & Engineering',
        'Warehouse' => 'Logistics & Warehousing',
        'Security' => 'Security Services',
        'Office Clerk' => 'Office & Clerical Services',
        'Secretary' => 'Secretarial Services',
        'Customer Service' => 'Customer Care Services',
        'IT & Technology' => 'Technology Solutions',
        'Healthcare' => 'Healthcare Services'
    ];
    
    return $defaultCompanies[$job['job_category']] ?? 'Recruiting Company';
}

function extractContactInfo($job) {
    $contacts = [
        'email' => $job['application_email'] ?? null,
        'phone' => $job['application_phone'] ?? null,
        'whatsapp' => null,
        'wechat' => null
    ];
    
    $full_text = ($job['description'] ?? '') . ' ' . ($job['overview'] ?? '') . ' ' . ($job['application_instructions'] ?? '');
    
    // Extract phone numbers (Ghana format) if not already set
    if (empty($contacts['phone'])) {
        if (preg_match('/(?:0|\+233)[2-9]\d{8}/', $full_text, $matches)) {
            $contacts['phone'] = $matches[0];
        } elseif (preg_match('/(\d{9,11})/', $full_text, $matches)) {
            $contacts['phone'] = $matches[1];
        }
    }
    
    // Extract email if not already set
    if (empty($contacts['email'])) {
        if (preg_match('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $full_text, $matches)) {
            $contacts['email'] = $matches[0];
        }
    }
    
    // Extract WhatsApp
    if (preg_match('/(?:WhatsApp|Whats App|whatsapp)[:\s]*([+\d\s]+)/i', $full_text, $matches)) {
        $contacts['whatsapp'] = trim($matches[1]);
    } elseif (!empty($contacts['phone']) && stripos($full_text, 'whatsapp') !== false) {
        $contacts['whatsapp'] = $contacts['phone'];
    }
    
    // Extract WeChat
    if (preg_match('/(?:WeChat|wechat)[:\s]*([a-zA-Z0-9_-]+)/i', $full_text, $matches)) {
        $contacts['wechat'] = trim($matches[1]);
    }
    
    return $contacts;
}

function generateEmailTemplate($job, $job_url) {
    $company = getCompanyDisplay($job);
    $job_title = htmlspecialchars($job['title']);
    $location = htmlspecialchars($job['city'] ? $job['city'] . ', Ghana' : 'Ghana');
    $salary = formatSalaryDisplay($job['salary_min'], $job['salary_max'], $job['salary_currency'], $job['salary_type']);
    
    $subject = "Application for " . $job_title . " - TransNow Ghana";
    
    $body = "Dear Hiring Manager,\n\n";
    $body .= "I am writing to express my interest in the " . $job_title . " position at " . $company . ", as advertised on TransNow Ghana.\n\n";
    $body .= "Job Details:\n";
    $body .= "- Position: " . $job_title . "\n";
    $body .= "- Company: " . $company . "\n";
    $body .= "- Location: " . $location . "\n";
    $body .= "- Salary: " . $salary . "\n";
    $body .= "- Job Reference: #" . $job['id'] . "\n\n";
    $body .= "I believe my skills and experience make me a strong candidate for this role. ";
    $body .= "I would welcome the opportunity to discuss my qualifications further.\n\n";
    $body .= "[Please attach your CV/Resume and cover letter here]\n\n";
    $body .= "I can be reached at:\n";
    $body .= "Phone: [Your Phone Number]\n";
    $body .= "Email: [Your Email Address]\n\n";
    $body .= "Thank you for considering my application. I look forward to hearing from you.\n\n";
    $body .= "Best regards,\n";
    $body .= "[Your Full Name]\n\n";
    $body .= "---\n";
    $body .= "This application was sent via TransNow Ghana - Bridging China & Ghana for a shared future.\n";
    $body .= "Job Link: " . $job_url;
    
    return [
        'subject' => rawurlencode($subject),
        'body' => rawurlencode($body)
    ];
}

/**
 * Generate QR Code using multiple fallback methods
 */
function generateQRCode($url, $size = 200) {
    // Method 1: QuickChart API (free, reliable, supports dark mode)
    $quickchart_url = 'https://quickchart.io/qr?text=' . urlencode($url) . '&size=' . $size;
    
    // Method 2: API QR Code (backup)
    $apiqr_url = 'https://api.qrserver.com/v1/create-qr-code/?size=' . $size . 'x' . $size . '&data=' . urlencode($url);
    
    return [
        'primary' => $quickchart_url,
        'fallback' => $apiqr_url
    ];
}

$contacts = extractContactInfo($job);
$job_url = 'https://trnasnow.com/job-detail.php?id=' . $job_id;
$email_template = generateEmailTemplate($job, $job_url);

// Generate QR Code URLs
$qr_urls = generateQRCode($job_url);

// Format the description for display
$description = nl2br(htmlspecialchars($job['description']));
$overview = !empty($job['overview']) ? nl2br(htmlspecialchars($job['overview'])) : '';

// Check if we have any contact method
$has_contact = !empty($contacts['email']) || !empty($contacts['phone']) || !empty($contacts['whatsapp']) || !empty($job['application_email']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($job['title']); ?> - TransNow Ghana</title>
    <meta name="description" content="<?php echo htmlspecialchars(substr($job['overview'] ?? $job['description'], 0, 160)); ?>">
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- QR Code Styling Library (as additional fallback) -->
    <script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.3/build/qrcode.min.js"></script>
    
    <style>
        * { font-family: 'Inter', sans-serif; }
        
        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --gray-50: #f9fafb;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-700: #374151;
            --gray-800: #1f2937;
            --gray-900: #111827;
        }
        
        body {
            background-color: var(--gray-50);
        }
        
        .dark body {
            background-color: var(--gray-900);
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            font-weight: 600;
            padding: 0.75rem 1.5rem;
            border-radius: 12px;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            box-shadow: 0 4px 6px -1px rgb(37 99 235 / 0.2);
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgb(37 99 235 / 0.3);
        }
        
        .btn-secondary {
            background: white;
            color: var(--gray-700);
            font-weight: 500;
            padding: 0.75rem 1.5rem;
            border-radius: 12px;
            border: 1px solid var(--gray-200);
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }
        
        .dark .btn-secondary {
            background: var(--gray-800);
            color: #e5e7eb;
            border-color: var(--gray-700);
        }
        
        .btn-secondary:hover {
            border-color: var(--primary);
            color: var(--primary);
            background: #eff6ff;
        }
        
        .btn-success {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            font-weight: 600;
            padding: 0.75rem 1.5rem;
            border-radius: 12px;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            box-shadow: 0 4px 6px -1px rgb(16 185 129 / 0.2);
        }
        
        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgb(16 185 129 / 0.3);
        }
        
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .badge-urgent {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            color: #991b1b;
        }
        
        .badge-featured {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            color: #92400e;
        }
        
        .badge-remote {
            background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
            color: #065f46;
        }
        
        .job-detail-card {
            background: white;
            border-radius: 24px;
            border: 1px solid var(--gray-200);
            overflow: hidden;
        }
        
        .dark .job-detail-card {
            background: var(--gray-800);
            border-color: var(--gray-700);
        }
        
        .info-item {
            display: flex;
            align-items: flex-start;
            gap: 1rem;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--gray-100);
        }
        
        .dark .info-item {
            border-bottom-color: var(--gray-700);
        }
        
        .info-item:last-child {
            border-bottom: none;
        }
        
        .section-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .dark .section-title {
            color: white;
        }
        
        .similar-job-card {
            background: white;
            border-radius: 16px;
            border: 1px solid var(--gray-200);
            padding: 1.25rem;
            transition: all 0.2s ease;
        }
        
        .dark .similar-job-card {
            background: var(--gray-800);
            border-color: var(--gray-700);
        }
        
        .similar-job-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px -5px rgb(0 0 0 / 0.1);
            border-color: var(--primary);
        }
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .animate-fadeInUp {
            animation: fadeInUp 0.5s ease-out forwards;
        }
        
        .sticky-sidebar {
            position: sticky;
            top: 84px;
        }
        
        .line-clamp-2 {
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        
        .qr-container {
            background: white;
            padding: 1rem;
            border-radius: 16px;
            display: inline-block;
        }
        
        .dark .qr-container {
            background: var(--gray-700);
        }
        
        #qrcode-canvas {
            max-width: 100%;
            height: auto;
        }
    </style>
</head>
<body class="antialiased">

<!-- Navigation -->
<nav class="sticky top-0 z-40 bg-white/80 dark:bg-gray-900/80 backdrop-blur-xl border-b border-gray-200 dark:border-gray-800">
    <div class="container mx-auto px-4">
        <div class="flex items-center justify-between h-16">
            <a href="/" class="flex items-center gap-3 group">
                <div class="w-9 h-9 bg-gradient-to-br from-blue-600 to-indigo-700 rounded-xl flex items-center justify-center shadow-lg shadow-blue-500/20 group-hover:scale-110 transition-transform">
                    <i class="fas fa-briefcase text-white text-lg"></i>
                </div>
                <span class="text-xl font-bold bg-gradient-to-r from-blue-600 to-indigo-700 bg-clip-text text-transparent">TransNow Ghana</span>
            </a>
            
            <div class="hidden md:flex items-center gap-1">
                <a href="/" class="px-4 py-2 text-gray-600 dark:text-gray-300 hover:text-blue-600 dark:hover:text-blue-400 font-medium transition">Home</a>
                <a href="/jobs.php" class="px-4 py-2 text-blue-600 dark:text-blue-400 font-medium bg-blue-50 dark:bg-blue-900/20 rounded-lg">Find Jobs</a>
                <a href="/testimonials.php" class="px-4 py-2 text-gray-600 dark:text-gray-300 hover:text-blue-600 dark:hover:text-blue-400 font-medium transition">Success Stories</a>
                <a href="/about.php" class="px-4 py-2 text-gray-600 dark:text-gray-300 hover:text-blue-600 dark:hover:text-blue-400 font-medium transition">About</a>
            </div>
            
            <div class="flex items-center gap-3">
                <button id="darkModeToggle" class="w-10 h-10 rounded-xl bg-gray-100 dark:bg-gray-800 flex items-center justify-center hover:bg-gray-200 dark:hover:bg-gray-700 transition">
                    <i class="fas fa-moon text-gray-600 dark:text-gray-300" id="darkModeIcon"></i>
                </button>
                <a href="/login.php" class="hidden sm:flex items-center gap-2 px-4 py-2 bg-gradient-to-r from-blue-600 to-indigo-700 text-white rounded-xl font-medium hover:shadow-lg hover:shadow-blue-500/25 transition">
                    <i class="fas fa-user-shield"></i>
                    <span>Admin</span>
                </a>
                <button id="mobileMenuBtn" class="md:hidden w-10 h-10 rounded-xl bg-gray-100 dark:bg-gray-800 flex items-center justify-center">
                    <i class="fas fa-bars text-gray-600 dark:text-gray-300"></i>
                </button>
            </div>
        </div>
        
        <div id="mobileMenu" class="md:hidden hidden pb-4 space-y-1">
            <a href="/" class="block px-4 py-3 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 rounded-xl transition">Home</a>
            <a href="/jobs.php" class="block px-4 py-3 text-blue-600 dark:text-blue-400 bg-blue-50 dark:bg-blue-900/20 rounded-xl font-medium">Find Jobs</a>
            <a href="/testimonials.php" class="block px-4 py-3 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 rounded-xl transition">Success Stories</a>
            <a href="/about.php" class="block px-4 py-3 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 rounded-xl transition">About</a>
        </div>
    </div>
</nav>

<!-- Breadcrumb -->
<div class="bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700">
    <div class="container mx-auto px-4 py-3">
        <nav class="flex items-center gap-2 text-sm">
            <a href="/" class="text-gray-500 dark:text-gray-400 hover:text-blue-600 dark:hover:text-blue-400">
                <i class="fas fa-home"></i>
            </a>
            <i class="fas fa-chevron-right text-xs text-gray-400"></i>
            <a href="/jobs.php" class="text-gray-500 dark:text-gray-400 hover:text-blue-600 dark:hover:text-blue-400">
                Jobs
            </a>
            <i class="fas fa-chevron-right text-xs text-gray-400"></i>
            <a href="/jobs.php?category=<?php echo urlencode($job['job_category']); ?>" class="text-gray-500 dark:text-gray-400 hover:text-blue-600 dark:hover:text-blue-400">
                <?php echo htmlspecialchars($job['job_category']); ?>
            </a>
            <i class="fas fa-chevron-right text-xs text-gray-400"></i>
            <span class="text-gray-900 dark:text-white font-medium truncate"><?php echo htmlspecialchars($job['title']); ?></span>
        </nav>
    </div>
</div>

<!-- Main Content -->
<section class="py-8">
    <div class="container mx-auto px-4">
        <div class="flex flex-col lg:flex-row gap-6">
            
            <!-- Main Content -->
            <main class="flex-1">
                <!-- Job Header -->
                <div class="job-detail-card p-6 md:p-8 mb-6 animate-fadeInUp">
                    <!-- Badges -->
                    <div class="flex flex-wrap gap-2 mb-4">
                        <?php if ($job['is_urgent']): ?>
                        <span class="badge badge-urgent"><i class="fas fa-bolt"></i>Urgent</span>
                        <?php endif; ?>
                        <?php if ($job['is_featured']): ?>
                        <span class="badge badge-featured"><i class="fas fa-star"></i>Featured</span>
                        <?php endif; ?>
                        <?php if ($job['location_type'] === 'Remote'): ?>
                        <span class="badge badge-remote"><i class="fas fa-home"></i>Remote</span>
                        <?php elseif ($job['location_type'] === 'Hybrid'): ?>
                        <span class="badge badge-remote"><i class="fas fa-sync-alt"></i>Hybrid</span>
                        <?php endif; ?>
                        <span class="badge bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300">
                            <i class="fas fa-tag"></i><?php echo htmlspecialchars($job['job_category']); ?>
                        </span>
                    </div>
                    
                    <!-- Title and Actions -->
                    <div class="flex flex-wrap items-start justify-between gap-4 mb-4">
                        <h1 class="text-2xl md:text-3xl font-bold text-gray-900 dark:text-white">
                            <?php echo htmlspecialchars($job['title']); ?>
                        </h1>
                        <div class="flex items-center gap-2">
                            <a href="/jobs.php?save_job=<?php echo $job_id; ?>" class="btn-secondary !p-3" id="saveJobBtn">
                                <i class="<?php echo $is_saved ? 'fas' : 'far'; ?> fa-bookmark text-lg"></i>
                                <span class="hidden sm:inline"><?php echo $is_saved ? 'Saved' : 'Save'; ?></span>
                            </a>
                            <button onclick="shareJob()" class="btn-secondary !p-3">
                                <i class="fas fa-share-alt"></i>
                                <span class="hidden sm:inline">Share</span>
                            </button>
                        </div>
                    </div>
                    
                    <!-- Company -->
                    <div class="flex items-center gap-2 mb-6">
                        <div class="w-10 h-10 bg-gradient-to-br from-blue-100 to-indigo-100 dark:from-blue-900/30 dark:to-indigo-900/30 rounded-xl flex items-center justify-center">
                            <i class="fas fa-building text-blue-600 dark:text-blue-400"></i>
                        </div>
                        <div>
                            <p class="font-semibold text-gray-900 dark:text-white">
                                <?php echo htmlspecialchars(getCompanyDisplay($job)); ?>
                            </p>
                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                <i class="fas fa-map-marker-alt mr-1"></i>
                                <?php 
                                $location_parts = [];
                                if (!empty($job['address'])) $location_parts[] = htmlspecialchars($job['address']);
                                if (!empty($job['city'])) $location_parts[] = htmlspecialchars($job['city']);
                                if (!empty($job['state'])) $location_parts[] = htmlspecialchars($job['state']);
                                $location_parts[] = htmlspecialchars($job['country'] ?: 'Ghana');
                                echo implode(', ', array_filter($location_parts));
                                ?>
                            </p>
                        </div>
                    </div>
                    
                    <!-- Quick Info Grid -->
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 p-4 bg-gray-50 dark:bg-gray-700/50 rounded-xl mb-6">
                        <div class="text-center">
                            <i class="fas fa-briefcase text-blue-500 text-xl mb-1"></i>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Employment Type</p>
                            <p class="font-semibold text-gray-900 dark:text-white"><?php echo htmlspecialchars($job['employment_type']); ?></p>
                        </div>
                        <div class="text-center">
                            <i class="fas fa-chart-line text-blue-500 text-xl mb-1"></i>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Experience</p>
                            <p class="font-semibold text-gray-900 dark:text-white"><?php echo htmlspecialchars($job['experience_level']); ?></p>
                        </div>
                        <div class="text-center">
                            <i class="fas fa-clock text-blue-500 text-xl mb-1"></i>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Posted</p>
                            <p class="font-semibold text-gray-900 dark:text-white"><?php echo timeAgo($job['created_at']); ?></p>
                        </div>
                        <div class="text-center">
                            <i class="fas fa-eye text-blue-500 text-xl mb-1"></i>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Views</p>
                            <p class="font-semibold text-gray-900 dark:text-white"><?php echo number_format($job['total_views'] ?? 1); ?></p>
                        </div>
                    </div>
                    
                    <!-- Salary -->
                    <div class="p-4 bg-gradient-to-r from-green-50 to-emerald-50 dark:from-green-900/20 dark:to-emerald-900/20 rounded-xl mb-6">
                        <div class="flex items-center gap-3">
                            <div class="w-12 h-12 bg-green-100 dark:bg-green-900/30 rounded-xl flex items-center justify-center">
                                <i class="fas fa-money-bill-wave text-green-600 dark:text-green-400 text-xl"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600 dark:text-gray-400">Salary</p>
                                <p class="text-xl font-bold text-green-700 dark:text-green-400">
                                    <?php echo formatSalaryDisplay($job['salary_min'], $job['salary_max'], $job['salary_currency'], $job['salary_type']); ?>
                                </p>
                                <?php if ($job['is_salary_negotiable']): ?>
                                <p class="text-xs text-gray-500 mt-1">Salary is negotiable</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Job Overview -->
                    <?php if (!empty($overview)): ?>
                    <div class="mb-8">
                        <h2 class="section-title">
                            <i class="fas fa-info-circle text-blue-500"></i>
                            Overview
                        </h2>
                        <div class="prose prose-gray dark:prose-invert max-w-none">
                            <div class="text-gray-600 dark:text-gray-300 leading-relaxed">
                                <?php echo $overview; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Job Description -->
                    <div class="mb-8">
                        <h2 class="section-title">
                            <i class="fas fa-file-alt text-blue-500"></i>
                            Job Description
                        </h2>
                        <div class="prose prose-gray dark:prose-invert max-w-none">
                            <div class="text-gray-600 dark:text-gray-300 leading-relaxed">
                                <?php echo $description; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Responsibilities -->
                    <?php if (!empty($job['responsibilities'])): ?>
                    <div class="mb-8">
                        <h2 class="section-title">
                            <i class="fas fa-tasks text-blue-500"></i>
                            Responsibilities
                        </h2>
                        <div class="prose prose-gray dark:prose-invert max-w-none">
                            <div class="text-gray-600 dark:text-gray-300 leading-relaxed">
                                <?php echo nl2br(htmlspecialchars($job['responsibilities'])); ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Requirements -->
                    <?php if (!empty($job['requirements'])): ?>
                    <div class="mb-8">
                        <h2 class="section-title">
                            <i class="fas fa-check-circle text-blue-500"></i>
                            Requirements
                        </h2>
                        <div class="prose prose-gray dark:prose-invert max-w-none">
                            <div class="text-gray-600 dark:text-gray-300 leading-relaxed">
                                <?php echo nl2br(htmlspecialchars($job['requirements'])); ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Benefits -->
                    <?php if (!empty($job['benefits'])): ?>
                    <div class="mb-8">
                        <h2 class="section-title">
                            <i class="fas fa-gift text-blue-500"></i>
                            Benefits
                        </h2>
                        <div class="prose prose-gray dark:prose-invert max-w-none">
                            <div class="text-gray-600 dark:text-gray-300 leading-relaxed">
                                <?php echo nl2br(htmlspecialchars($job['benefits'])); ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Similar Jobs -->
                <?php if (!empty($similar_jobs)): ?>
                <div class="job-detail-card p-6 md:p-8 mb-6 animate-fadeInUp" style="animation-delay: 0.1s">
                    <h2 class="section-title">
                        <i class="fas fa-briefcase text-blue-500"></i>
                        Similar Jobs
                    </h2>
                    <div class="grid md:grid-cols-2 gap-4">
                        <?php foreach ($similar_jobs as $similar): ?>
                        <a href="/job-detail.php?id=<?php echo $similar['id']; ?>" class="similar-job-card block">
                            <h3 class="font-semibold text-gray-900 dark:text-white hover:text-blue-600 dark:hover:text-blue-400 transition mb-2">
                                <?php echo htmlspecialchars($similar['title']); ?>
                            </h3>
                            <p class="text-sm text-gray-500 dark:text-gray-400 mb-2">
                                <i class="fas fa-map-marker-alt mr-1"></i>
                                <?php echo htmlspecialchars($similar['city'] ?: 'Ghana'); ?>
                                <?php if ($similar['location_type'] === 'Remote'): ?>
                                <span class="ml-1 text-green-600">(Remote)</span>
                                <?php endif; ?>
                            </p>
                            <p class="text-sm text-gray-600 dark:text-gray-300 line-clamp-2 mb-3">
                                <?php 
                                $desc = !empty($similar['overview']) ? $similar['overview'] : $similar['description'];
                                echo htmlspecialchars(substr($desc, 0, 100)) . '...';
                                ?>
                            </p>
                            <div class="flex items-center justify-between">
                                <span class="text-sm font-semibold text-green-600 dark:text-green-400">
                                    <?php echo formatSalaryDisplay($similar['salary_min'], $similar['salary_max'], $similar['salary_currency'], $similar['salary_type']); ?>
                                </span>
                                <span class="text-xs text-gray-400">
                                    <?php echo timeAgo($similar['created_at']); ?>
                                </span>
                            </div>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </main>
            
            <!-- Sidebar -->
            <aside class="lg:w-96 flex-shrink-0">
                <div class="sticky-sidebar space-y-6">
                    
                    <!-- Apply Card -->
                    <div class="job-detail-card p-6 animate-fadeInUp" style="animation-delay: 0.1s">
                        <h3 class="font-bold text-lg text-gray-900 dark:text-white mb-4">
                            <i class="fas fa-paper-plane text-blue-500 mr-2"></i>
                            How to Apply
                        </h3>
                        
                        <?php if ($has_contact): ?>
                        <div class="space-y-3 mb-4">
                            <?php 
                            $display_email = !empty($contacts['email']) ? $contacts['email'] : $job['application_email'];
                            if (!empty($display_email)): 
                            ?>
                            <div class="info-item">
                                <i class="fas fa-envelope text-blue-500 w-5"></i>
                                <div class="flex-1">
                                    <p class="text-sm text-gray-500 dark:text-gray-400">Email Application</p>
                                    <a href="mailto:<?php echo htmlspecialchars($display_email); ?>?subject=<?php echo $email_template['subject']; ?>&body=<?php echo $email_template['body']; ?>" 
                                       class="font-medium text-gray-900 dark:text-white hover:text-blue-600 break-all">
                                        <?php echo htmlspecialchars($display_email); ?>
                                    </a>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php 
                            $display_phone = !empty($contacts['phone']) ? $contacts['phone'] : $job['application_phone'];
                            if (!empty($display_phone)): 
                            ?>
                            <div class="info-item">
                                <i class="fas fa-phone text-blue-500 w-5"></i>
                                <div class="flex-1">
                                    <p class="text-sm text-gray-500 dark:text-gray-400">Phone</p>
                                    <a href="tel:<?php echo htmlspecialchars($display_phone); ?>" class="font-medium text-gray-900 dark:text-white hover:text-blue-600">
                                        <?php echo htmlspecialchars($display_phone); ?>
                                    </a>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($contacts['whatsapp'])): ?>
                            <div class="info-item">
                                <i class="fab fa-whatsapp text-green-500 w-5"></i>
                                <div class="flex-1">
                                    <p class="text-sm text-gray-500 dark:text-gray-400">WhatsApp</p>
                                    <a href="https://wa.me/<?php echo preg_replace('/[^0-9+]/', '', $contacts['whatsapp']); ?>" target="_blank" class="font-medium text-gray-900 dark:text-white hover:text-green-600">
                                        <?php echo htmlspecialchars($contacts['whatsapp']); ?>
                                    </a>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($contacts['wechat'])): ?>
                            <div class="info-item">
                                <i class="fab fa-weixin text-green-600 w-5"></i>
                                <div class="flex-1">
                                    <p class="text-sm text-gray-500 dark:text-gray-400">WeChat</p>
                                    <p class="font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($contacts['wechat']); ?></p>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php else: ?>
                        <div class="p-4 bg-yellow-50 dark:bg-yellow-900/20 rounded-xl mb-4">
                            <p class="text-sm text-yellow-700 dark:text-yellow-300">
                                <i class="fas fa-info-circle mr-1"></i>
                                Contact information not provided. Please check the job description.
                            </p>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($job['application_instructions'])): ?>
                        <div class="p-4 bg-blue-50 dark:bg-blue-900/20 rounded-xl mb-4">
                            <p class="text-sm text-blue-700 dark:text-blue-300">
                                <i class="fas fa-info-circle mr-1"></i>
                                <?php echo nl2br(htmlspecialchars($job['application_instructions'])); ?>
                            </p>
                        </div>
                        <?php endif; ?>
                        
                        <?php 
                        $apply_email = !empty($contacts['email']) ? $contacts['email'] : $job['application_email'];
                        if (!empty($apply_email)): 
                        ?>
                        <a href="mailto:<?php echo htmlspecialchars($apply_email); ?>?subject=<?php echo $email_template['subject']; ?>&body=<?php echo $email_template['body']; ?>" 
                           class="w-full btn-primary mb-2 block text-center">
                            <i class="fas fa-paper-plane"></i>
                            Apply Now with Email Template
                        </a>
                        <?php endif; ?>
                        
                        <?php if (!empty($contacts['whatsapp'])): ?>
                        <a href="https://wa.me/<?php echo preg_replace('/[^0-9+]/', '', $contacts['whatsapp']); ?>" 
                           target="_blank"
                           class="w-full btn-success mb-2 block text-center">
                            <i class="fab fa-whatsapp"></i>
                            Apply via WhatsApp
                        </a>
                        <?php endif; ?>
                        
                        <a href="/jobs.php?save_job=<?php echo $job_id; ?>" class="w-full btn-secondary block text-center">
                            <i class="far fa-bookmark"></i>
                            Save for Later
                        </a>
                    </div>
                    
                    <!-- Job Summary Card -->
                    <div class="job-detail-card p-6 animate-fadeInUp" style="animation-delay: 0.15s">
                        <h3 class="font-bold text-lg text-gray-900 dark:text-white mb-4">
                            <i class="fas fa-list text-blue-500 mr-2"></i>
                            Job Summary
                        </h3>
                        
                        <div class="space-y-3">
                            <div class="info-item">
                                <i class="fas fa-calendar text-blue-500 w-5"></i>
                                <div>
                                    <p class="text-sm text-gray-500 dark:text-gray-400">Posted On</p>
                                    <p class="font-medium text-gray-900 dark:text-white"><?php echo date('F d, Y', strtotime($job['created_at'])); ?></p>
                                </div>
                            </div>
                            
                            <?php if (!empty($job['published_at'])): ?>
                            <div class="info-item">
                                <i class="fas fa-calendar-check text-blue-500 w-5"></i>
                                <div>
                                    <p class="text-sm text-gray-500 dark:text-gray-400">Published</p>
                                    <p class="font-medium text-gray-900 dark:text-white"><?php echo date('F d, Y', strtotime($job['published_at'])); ?></p>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <div class="info-item">
                                <i class="fas fa-tag text-blue-500 w-5"></i>
                                <div>
                                    <p class="text-sm text-gray-500 dark:text-gray-400">Job Category</p>
                                    <p class="font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($job['job_category']); ?></p>
                                </div>
                            </div>
                            
                            <div class="info-item">
                                <i class="fas fa-clock text-blue-500 w-5"></i>
                                <div>
                                    <p class="text-sm text-gray-500 dark:text-gray-400">Employment Type</p>
                                    <p class="font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($job['employment_type']); ?></p>
                                </div>
                            </div>
                            
                            <div class="info-item">
                                <i class="fas fa-chart-line text-blue-500 w-5"></i>
                                <div>
                                    <p class="text-sm text-gray-500 dark:text-gray-400">Experience Level</p>
                                    <p class="font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($job['experience_level']); ?></p>
                                </div>
                            </div>
                            
                            <div class="info-item">
                                <i class="fas fa-map-pin text-blue-500 w-5"></i>
                                <div>
                                    <p class="text-sm text-gray-500 dark:text-gray-400">Location Type</p>
                                    <p class="font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($job['location_type']); ?></p>
                                </div>
                            </div>
                            
                            <div class="info-item">
                                <i class="fas fa-map-marker-alt text-blue-500 w-5"></i>
                                <div>
                                    <p class="text-sm text-gray-500 dark:text-gray-400">Location</p>
                                    <p class="font-medium text-gray-900 dark:text-white">
                                        <?php 
                                        $location_parts = [];
                                        if (!empty($job['city'])) $location_parts[] = htmlspecialchars($job['city']);
                                        if (!empty($job['state'])) $location_parts[] = htmlspecialchars($job['state']);
                                        $location_parts[] = htmlspecialchars($job['country'] ?: 'Ghana');
                                        echo implode(', ', $location_parts);
                                        ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- QR Code Card -->
                    <div class="job-detail-card p-6 animate-fadeInUp text-center" style="animation-delay: 0.2s">
                        <h3 class="font-bold text-lg text-gray-900 dark:text-white mb-4">
                            <i class="fas fa-qrcode text-blue-500 mr-2"></i>
                            Scan to Apply
                        </h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
                            Scan this QR code with your phone camera to open this job on your mobile device.
                        </p>
                        <div class="qr-container inline-block mb-3">
                            <!-- Canvas for QR Code (JavaScript generated - most reliable) -->
                            <canvas id="qrcode-canvas" width="200" height="200" style="display: block; margin: 0 auto;"></canvas>
                            <!-- Fallback image (hidden by default, shown if canvas fails) -->
                            <img id="qrcode-fallback" 
                                 src="<?php echo htmlspecialchars($qr_urls['primary']); ?>" 
                                 alt="QR Code for <?php echo htmlspecialchars($job['title']); ?>" 
                                 width="200" 
                                 height="200"
                                 style="display: none; margin: 0 auto;"
                                 onerror="this.onerror=null; this.src='<?php echo htmlspecialchars($qr_urls['fallback']); ?>';">
                        </div>
                        <p class="text-xs text-gray-400 dark:text-gray-500 mt-2">
                            <i class="fas fa-mobile-alt mr-1"></i>
                            Point your camera at the QR code
                        </p>
                        <div class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-700">
                            <button onclick="copyJobLink()" class="text-sm text-blue-600 dark:text-blue-400 hover:underline">
                                <i class="fas fa-link mr-1"></i>Copy job link instead
                            </button>
                        </div>
                    </div>
                    
                    <!-- Share Widget -->
                    <div class="job-detail-card p-6 animate-fadeInUp" style="animation-delay: 0.25s">
                        <h3 class="font-bold text-lg text-gray-900 dark:text-white mb-4">
                            <i class="fas fa-share-alt text-blue-500 mr-2"></i>
                            Share This Job
                        </h3>
                        <div class="grid grid-cols-2 gap-2">
                            <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo urlencode($job_url); ?>" target="_blank" 
                               class="py-3 bg-[#1877F2] text-white rounded-xl flex items-center justify-center gap-2 hover:opacity-90 transition">
                                <i class="fab fa-facebook-f"></i>
                                <span class="text-sm">Facebook</span>
                            </a>
                            <a href="https://twitter.com/intent/tweet?url=<?php echo urlencode($job_url); ?>&text=<?php echo urlencode($job['title']); ?>" target="_blank" 
                               class="py-3 bg-black text-white rounded-xl flex items-center justify-center gap-2 hover:opacity-90 transition">
                                <i class="fab fa-x-twitter"></i>
                                <span class="text-sm">X</span>
                            </a>
                            <a href="https://www.linkedin.com/sharing/share-offsite/?url=<?php echo urlencode($job_url); ?>" target="_blank" 
                               class="py-3 bg-[#0A66C2] text-white rounded-xl flex items-center justify-center gap-2 hover:opacity-90 transition">
                                <i class="fab fa-linkedin-in"></i>
                                <span class="text-sm">LinkedIn</span>
                            </a>
                            <a href="https://wa.me/?text=<?php echo urlencode($job['title'] . ' - ' . $job_url); ?>" target="_blank" 
                               class="py-3 bg-[#25D366] text-white rounded-xl flex items-center justify-center gap-2 hover:opacity-90 transition">
                                <i class="fab fa-whatsapp"></i>
                                <span class="text-sm">WhatsApp</span>
                            </a>
                        </div>
                    </div>
                </div>
            </aside>
        </div>
    </div>
</section>

<!-- ========== FOOTER ========== -->
<footer class="bg-[#111827] text-white py-16">
    <div class="container mx-auto px-4">
        <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-8">
            <div class="lg:col-span-1">
                <div class="flex items-center space-x-3 mb-4">
                    <div class="w-10 h-10 bg-gradient-to-br from-blue-600 to-indigo-700 rounded-xl flex items-center justify-center">
                        <i class="fas fa-handshake text-white"></i>
                    </div>
                    <span class="text-2xl font-bold bg-gradient-to-r from-blue-400 to-indigo-400 bg-clip-text text-transparent">TransNow Ghana</span>
                </div>
                <p class="text-gray-400 mb-4">Bridging Chinese employers and Ghanaian talent through communication, culture, and career opportunities.</p>
                
                <!-- Contact Information -->
                <div class="mb-4 space-y-2">
                    <div class="flex items-center space-x-3 text-gray-400">
                        <i class="fas fa-phone-alt text-[#10B981] w-5"></i>
                        <a href="tel:+233241334520" class="hover:text-white transition">+233 24 133 4520</a>
                    </div>
                    <div class="flex items-center space-x-3 text-gray-400">
                        <i class="fab fa-whatsapp text-[#25D366] w-5"></i>
                        <a href="https://wa.me/8619157688742" target="_blank" class="hover:text-white transition">+86 191 5768 8742 (WhatsApp)</a>
                    </div>
                    <div class="flex items-center space-x-3 text-gray-400">
                        <i class="fab fa-weixin text-[#07C160] w-5"></i>
                        <a href="https://u.wechat.com/EPqmYQJJDuNvJgHqgtMy00E?s=3" target="_blank" class="hover:text-white transition">WeChat: TransNow Ghana</a>
                    </div>
                </div>
                
                <!-- Affiliated Organization -->
                <div class="mb-4 p-3 bg-gray-800/50 rounded-lg border border-gray-700">
                    <p class="text-xs text-gray-400 mb-1">Affiliated Organization</p>
                    <a href="https://ci-ug.com" target="_blank" class="flex items-center space-x-2 hover:text-white transition">
                        <i class="fas fa-university text-[#3B82F6]"></i>
                        <span class="font-medium text-gray-300">Confucius Institute at University of Ghana (CI-UG)</span>
                    </a>
                </div>
                
                <div class="flex space-x-4">
                    <a href="#" class="w-10 h-10 bg-gray-800 rounded-full flex items-center justify-center hover:bg-[#1E3A8A] transition"><i class="fab fa-facebook-f"></i></a>
                    <a href="#" class="w-10 h-10 bg-gray-800 rounded-full flex items-center justify-center hover:bg-[#1E3A8A] transition"><i class="fab fa-twitter"></i></a>
                    <a href="#" class="w-10 h-10 bg-gray-800 rounded-full flex items-center justify-center hover:bg-[#1E3A8A] transition"><i class="fab fa-linkedin-in"></i></a>
                    <a href="https://u.wechat.com/EPqmYQJJDuNvJgHqgtMy00E?s=3" target="_blank" class="w-10 h-10 bg-gray-800 rounded-full flex items-center justify-center hover:bg-[#1E3A8A] transition"><i class="fab fa-weixin"></i></a>
                </div>
            </div>
            
            <div>
                <h4 class="font-semibold text-lg mb-4">Company</h4>
                <ul class="space-y-2 text-gray-400">
                    <li><a href="/about.php" class="hover:text-white transition">About Us</a></li>
                    <li><a href="/privacy.php" class="hover:text-white transition">Privacy Policy</a></li>
                    <li><a href="/terms.php" class="hover:text-white transition">Terms of Service</a></li>
                    <li><a href="https://ci-ug.com" target="_blank" class="hover:text-white transition">CI-UG (Partner)</a></li>
                </ul>
            </div>
            
            <div>
                <h4 class="font-semibold text-lg mb-4">Connect With Us</h4>
                <ul class="space-y-2 text-gray-400">
                    <li><a href="https://wa.me/8619157688742" target="_blank" class="hover:text-white transition flex items-center space-x-2">
                        <i class="fab fa-whatsapp text-[#25D366]"></i>
                        <span>WhatsApp</span>
                    </a></li>
                    <li><a href="https://u.wechat.com/EPqmYQJJDuNvJgHqgtMy00E?s=3" target="_blank" class="hover:text-white transition flex items-center space-x-2">
                        <i class="fab fa-weixin text-[#07C160]"></i>
                        <span>WeChat</span>
                    </a></li>
                    <li><a href="tel:+8619157688742" class="hover:text-white transition flex items-center space-x-2">
                        <i class="fas fa-phone-alt text-[#10B981]"></i>
                        <span>Call Us</span>
                    </a></li>
                </ul>
            </div>
        </div>
        
        <div class="border-t border-gray-800 mt-12 pt-8 text-center text-gray-400 text-sm">
            <p>&copy; <?php echo date('Y'); ?> TransNow Ghana. Bridging China & Ghana <i class="fas fa-heart text-[#10B981]"></i> for a shared future.</p>
            <p class="mt-2 text-xs text-gray-500">Developed by <a href="https://nerd-spring.com" target="_blank" class="hover:text-white transition">NerdSpring</a></p>
        </div>
    </div>
</footer>

<script>
// Dark Mode Toggle
const darkToggle = document.getElementById('darkModeToggle');
const darkIcon = document.getElementById('darkModeIcon');
const html = document.documentElement;

if (localStorage.getItem('theme') === 'dark' || (!localStorage.getItem('theme') && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
    html.classList.add('dark');
    if (darkIcon) {
        darkIcon.classList.remove('fa-moon');
        darkIcon.classList.add('fa-sun');
    }
}

if (darkToggle) {
    darkToggle.addEventListener('click', () => {
        html.classList.toggle('dark');
        if (html.classList.contains('dark')) {
            localStorage.setItem('theme', 'dark');
            darkIcon?.classList.replace('fa-moon', 'fa-sun');
        } else {
            localStorage.setItem('theme', 'light');
            darkIcon?.classList.replace('fa-sun', 'fa-moon');
        }
    });
}

// Mobile Menu
const mobileBtn = document.getElementById('mobileMenuBtn');
const mobileMenu = document.getElementById('mobileMenu');
if (mobileBtn) {
    mobileBtn.addEventListener('click', () => {
        mobileMenu.classList.toggle('hidden');
        const icon = mobileBtn.querySelector('i');
        icon?.classList.toggle('fa-bars');
        icon?.classList.toggle('fa-times');
    });
}

// Share Function
function shareJob() {
    if (navigator.share) {
        navigator.share({
            title: '<?php echo addslashes($job['title']); ?>',
            text: 'Check out this job opportunity on TransNow Ghana!',
            url: '<?php echo $job_url; ?>'
        }).catch(() => {});
    } else {
        copyJobLink();
    }
}

// Copy Job Link
function copyJobLink() {
    const url = '<?php echo $job_url; ?>';
    navigator.clipboard.writeText(url).then(() => {
        alert('Job link copied to clipboard!');
    }).catch(() => {
        // Fallback
        const textarea = document.createElement('textarea');
        textarea.value = url;
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        document.body.removeChild(textarea);
        alert('Job link copied to clipboard!');
    });
}

// Generate QR Code using JavaScript library (most reliable method)
(function() {
    const qrCanvas = document.getElementById('qrcode-canvas');
    const qrFallback = document.getElementById('qrcode-fallback');
    const jobUrl = '<?php echo $job_url; ?>';
    
    // Try to generate QR code using the library
    if (typeof QRCode !== 'undefined' && qrCanvas) {
        try {
            QRCode.toCanvas(qrCanvas, jobUrl, {
                width: 200,
                margin: 2,
                color: {
                    dark: '#000000',
                    light: '#ffffff'
                }
            }, function(error) {
                if (error) {
                    console.error('QR Code generation error:', error);
                    showFallback();
                } else {
                    // Success - hide fallback
                    if (qrFallback) qrFallback.style.display = 'none';
                    qrCanvas.style.display = 'block';
                }
            });
        } catch (e) {
            console.error('QR Code exception:', e);
            showFallback();
        }
    } else {
        showFallback();
    }
    
    function showFallback() {
        if (qrCanvas) qrCanvas.style.display = 'none';
        if (qrFallback) qrFallback.style.display = 'block';
    }
})();
</script>

</body>
</html>