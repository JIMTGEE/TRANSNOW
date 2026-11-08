<?php
// admin/reports.php - Comprehensive Admin Reports Dashboard
// Version: 1.0 - Complete reporting system

// Error reporting for development
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration files
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../includes/auth.php';

// Require admin login
requireAdminLogin();

// Get database connection
$pdo = getDB();

// Get admin info
$adminData = fetchOne("
    SELECT u.* 
    FROM users u 
    WHERE u.id = ? AND u.user_type = 'admin'
", [$_SESSION['user_id']]);

if (!$adminData) {
    $_SESSION['error'] = 'Admin account not found';
    header('Location: /logout.php');
    exit();
}

// ===== HANDLE REPORT FILTERS =====
$reportType = $_GET['type'] ?? 'overview';
$dateRange = $_GET['range'] ?? '30';
$startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime("-{$dateRange} days"));
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$category = $_GET['category'] ?? 'all';
$employerId = $_GET['employer_id'] ?? 'all';
$status = $_GET['status'] ?? 'all';

// Validate dates
$startDate = date('Y-m-d', strtotime($startDate));
$endDate = date('Y-m-d', strtotime($endDate));

// ===== FETCH REPORT DATA BASED ON TYPE =====

// Initialize report data array
$reportData = [];
$chartLabels = [];
$chartData = [];
$secondaryChartLabels = [];
$secondaryChartData = [];

// 1. OVERVIEW REPORT
if ($reportType === 'overview') {
    // Key metrics
    $reportData['metrics'] = fetchOne("
        SELECT 
            (SELECT COUNT(*) FROM users WHERE created_at BETWEEN ? AND ?) as new_users,
            (SELECT COUNT(*) FROM users WHERE user_type = 'seeker' AND created_at BETWEEN ? AND ?) as new_seekers,
            (SELECT COUNT(*) FROM users WHERE user_type = 'employer' AND created_at BETWEEN ? AND ?) as new_employers,
            (SELECT COUNT(*) FROM jobs WHERE created_at BETWEEN ? AND ?) as new_jobs,
            (SELECT COUNT(*) FROM jobs WHERE status = 'active' AND (created_at BETWEEN ? AND ? OR 1=1)) as active_jobs,
            (SELECT COUNT(*) FROM applications WHERE applied_at BETWEEN ? AND ?) as new_applications,
            (SELECT COUNT(*) FROM applications WHERE application_status = 'hired' AND updated_at BETWEEN ? AND ?) as hires,
            (SELECT COUNT(*) FROM interviews WHERE created_at BETWEEN ? AND ?) as interviews_scheduled,
            (SELECT ROUND(AVG(match_score), 2) FROM applications WHERE match_score > 0 AND applied_at BETWEEN ? AND ?) as avg_match_score
    ", array_fill(0, 27, $startDate . ' 00:00:00'));

    // Daily activity for chart
    $dailyActivity = fetchAll("
        SELECT 
            DATE(activity_date) as date,
            SUM(users_count) as users,
            SUM(jobs_count) as jobs,
            SUM(applications_count) as applications
        FROM (
            SELECT DATE(created_at) as activity_date, COUNT(*) as users_count, 0 as jobs_count, 0 as applications_count
            FROM users WHERE created_at BETWEEN ? AND ?
            GROUP BY DATE(created_at)
            UNION ALL
            SELECT DATE(created_at), 0, COUNT(*), 0
            FROM jobs WHERE created_at BETWEEN ? AND ?
            GROUP BY DATE(created_at)
            UNION ALL
            SELECT DATE(applied_at), 0, 0, COUNT(*)
            FROM applications WHERE applied_at BETWEEN ? AND ?
            GROUP BY DATE(applied_at)
        ) combined
        GROUP BY DATE(activity_date)
        ORDER BY date ASC
    ", [$startDate . ' 00:00:00', $endDate . ' 23:59:59', 
        $startDate . ' 00:00:00', $endDate . ' 23:59:59',
        $startDate . ' 00:00:00', $endDate . ' 23:59:59']);

    // Fill in missing dates
    $dateMap = [];
    $current = strtotime($startDate);
    $end = strtotime($endDate);
    
    while ($current <= $end) {
        $date = date('Y-m-d', $current);
        $chartLabels[] = date('M d', $current);
        $dateMap[$date] = ['users' => 0, 'jobs' => 0, 'applications' => 0];
        $current = strtotime('+1 day', $current);
    }
    
    foreach ($dailyActivity as $activity) {
        if (isset($dateMap[$activity['date']])) {
            $dateMap[$activity['date']] = [
                'users' => (int)$activity['users'],
                'jobs' => (int)$activity['jobs'],
                'applications' => (int)$activity['applications']
            ];
        }
    }
    
    $chartData['users'] = array_column($dateMap, 'users');
    $chartData['jobs'] = array_column($dateMap, 'jobs');
    $chartData['applications'] = array_column($dateMap, 'applications');

    // Top categories
    $reportData['topCategories'] = fetchAll("
        SELECT 
            jc.name,
            COUNT(DISTINCT j.id) as job_count,
            COUNT(DISTINCT a.id) as application_count,
            COUNT(DISTINCT CASE WHEN a.application_status = 'hired' THEN a.id END) as hire_count
        FROM job_categories jc
        LEFT JOIN jobs j ON j.category_id = jc.id AND j.created_at BETWEEN ? AND ?
        LEFT JOIN applications a ON a.job_id = j.id
        GROUP BY jc.id
        HAVING job_count > 0 OR application_count > 0
        ORDER BY application_count DESC
        LIMIT 10
    ", [$startDate . ' 00:00:00', $endDate . ' 23:59:59']);

    // User growth
    $reportData['userGrowth'] = fetchAll("
        SELECT 
            DATE(created_at) as date,
            SUM(CASE WHEN user_type = 'seeker' THEN 1 ELSE 0 END) as seekers,
            SUM(CASE WHEN user_type = 'employer' THEN 1 ELSE 0 END) as employers
        FROM users
        WHERE created_at BETWEEN ? AND ?
        GROUP BY DATE(created_at)
        ORDER BY date ASC
    ", [$startDate . ' 00:00:00', $endDate . ' 23:59:59']);
}

// 2. USERS REPORT
elseif ($reportType === 'users') {
    // User statistics
    $reportData['userStats'] = fetchOne("
        SELECT 
            COUNT(*) as total_users,
            SUM(CASE WHEN user_type = 'seeker' THEN 1 ELSE 0 END) as seekers,
            SUM(CASE WHEN user_type = 'employer' THEN 1 ELSE 0 END) as employers,
            SUM(CASE WHEN user_type = 'admin' THEN 1 ELSE 0 END) as admins,
            SUM(CASE WHEN is_verified = 1 THEN 1 ELSE 0 END) as verified,
            SUM(CASE WHEN is_verified = 0 THEN 1 ELSE 0 END) as unverified,
            SUM(CASE WHEN last_login >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as active_week,
            SUM(CASE WHEN last_login >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as active_month,
            SUM(CASE WHEN created_at BETWEEN ? AND ? THEN 1 ELSE 0 END) as new_users
        FROM users
    ", [$startDate . ' 00:00:00', $endDate . ' 23:59:59']);

    // Users by date
    $usersByDate = fetchAll("
        SELECT 
            DATE(created_at) as date,
            COUNT(*) as total,
            SUM(CASE WHEN user_type = 'seeker' THEN 1 ELSE 0 END) as seekers,
            SUM(CASE WHEN user_type = 'employer' THEN 1 ELSE 0 END) as employers
        FROM users
        WHERE created_at BETWEEN ? AND ?
        GROUP BY DATE(created_at)
        ORDER BY date ASC
    ", [$startDate . ' 00:00:00', $endDate . ' 23:59:59']);

    // Fill chart data
    $current = strtotime($startDate);
    $end = strtotime($endDate);
    $dateMap = [];
    
    while ($current <= $end) {
        $date = date('Y-m-d', $current);
        $chartLabels[] = date('M d', $current);
        $dateMap[$date] = ['total' => 0, 'seekers' => 0, 'employers' => 0];
        $current = strtotime('+1 day', $current);
    }
    
    foreach ($usersByDate as $day) {
        if (isset($dateMap[$day['date']])) {
            $dateMap[$day['date']] = [
                'total' => (int)$day['total'],
                'seekers' => (int)$day['seekers'],
                'employers' => (int)$day['employers']
            ];
        }
    }
    
    $chartData['total'] = array_column($dateMap, 'total');
    $chartData['seekers'] = array_column($dateMap, 'seekers');
    $chartData['employers'] = array_column($dateMap, 'employers');

    // Top locations
    $reportData['topLocations'] = fetchAll("
        SELECT 
            COALESCE(s.location, 'Unknown') as location,
            COUNT(*) as count
        FROM seekers s
        WHERE s.created_at BETWEEN ? AND ?
        GROUP BY s.location
        ORDER BY count DESC
        LIMIT 10
    ", [$startDate . ' 00:00:00', $endDate . ' 23:59:59']);

    // Users by verification status for pie chart
    $secondaryChartLabels = ['Verified', 'Unverified'];
    $secondaryChartData = [
        $reportData['userStats']['verified'] ?? 0,
        $reportData['userStats']['unverified'] ?? 0
    ];
}

// 3. JOBS REPORT
elseif ($reportType === 'jobs') {
    // Jobs statistics
    $reportData['jobStats'] = fetchOne("
        SELECT 
            COUNT(*) as total_jobs,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
            SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft,
            SUM(CASE WHEN status = 'paused' THEN 1 ELSE 0 END) as paused,
            SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) as closed,
            SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) as expired,
            SUM(CASE WHEN is_featured = 1 THEN 1 ELSE 0 END) as featured,
            SUM(CASE WHEN is_urgent = 1 THEN 1 ELSE 0 END) as urgent,
            AVG(total_applications) as avg_applications,
            SUM(total_applications) as total_applications,
            SUM(total_views) as total_views,
            ROUND(AVG(total_views), 2) as avg_views
        FROM jobs
        WHERE created_at BETWEEN ? AND ?
    ", [$startDate . ' 00:00:00', $endDate . ' 23:59:59']);

    // Jobs by status for pie chart
    $secondaryChartLabels = ['Active', 'Draft', 'Paused', 'Closed', 'Expired'];
    $secondaryChartData = [
        $reportData['jobStats']['active'] ?? 0,
        $reportData['jobStats']['draft'] ?? 0,
        $reportData['jobStats']['paused'] ?? 0,
        $reportData['jobStats']['closed'] ?? 0,
        $reportData['jobStats']['expired'] ?? 0
    ];

    // Jobs by date
    $jobsByDate = fetchAll("
        SELECT 
            DATE(created_at) as date,
            COUNT(*) as count
        FROM jobs
        WHERE created_at BETWEEN ? AND ?
        GROUP BY DATE(created_at)
        ORDER BY date ASC
    ", [$startDate . ' 00:00:00', $endDate . ' 23:59:59']);

    // Fill chart data
    $current = strtotime($startDate);
    $end = strtotime($endDate);
    $dateMap = [];
    
    while ($current <= $end) {
        $date = date('Y-m-d', $current);
        $chartLabels[] = date('M d', $current);
        $dateMap[$date] = 0;
        $current = strtotime('+1 day', $current);
    }
    
    foreach ($jobsByDate as $day) {
        if (isset($dateMap[$day['date']])) {
            $dateMap[$day['date']] = (int)$day['count'];
        }
    }
    
    $chartData = array_values($dateMap);

    // Top employers by job count
    $reportData['topEmployers'] = fetchAll("
        SELECT 
            e.id,
            e.company_name,
            e.company_logo,
            COUNT(j.id) as job_count,
            SUM(j.total_applications) as total_applications,
            AVG(j.total_applications) as avg_applications_per_job
        FROM employers e
        LEFT JOIN jobs j ON j.employer_id = e.id AND j.created_at BETWEEN ? AND ?
        GROUP BY e.id
        HAVING job_count > 0
        ORDER BY job_count DESC
        LIMIT 10
    ", [$startDate . ' 00:00:00', $endDate . ' 23:59:59']);

    // Jobs by category
    $reportData['jobsByCategory'] = fetchAll("
        SELECT 
            jc.name,
            COUNT(j.id) as job_count,
            SUM(j.total_applications) as applications,
            AVG(j.total_views) as avg_views
        FROM job_categories jc
        LEFT JOIN jobs j ON j.category_id = jc.id AND j.created_at BETWEEN ? AND ?
        GROUP BY jc.id
        HAVING job_count > 0
        ORDER BY job_count DESC
    ", [$startDate . ' 00:00:00', $endDate . ' 23:59:59']);

    // Jobs by location type
    $reportData['jobsByLocation'] = fetchAll("
        SELECT 
            location_type,
            COUNT(*) as count
        FROM jobs
        WHERE created_at BETWEEN ? AND ?
        GROUP BY location_type
        ORDER BY count DESC
    ", [$startDate . ' 00:00:00', $endDate . ' 23:59:59']);
}

// 4. APPLICATIONS REPORT
elseif ($reportType === 'applications') {
    // Applications statistics
    $reportData['appStats'] = fetchOne("
        SELECT 
            COUNT(*) as total_applications,
            SUM(CASE WHEN application_status = 'applied' THEN 1 ELSE 0 END) as applied,
            SUM(CASE WHEN application_status = 'reviewed' THEN 1 ELSE 0 END) as reviewed,
            SUM(CASE WHEN application_status = 'shortlisted' THEN 1 ELSE 0 END) as shortlisted,
            SUM(CASE WHEN application_status = 'interview' THEN 1 ELSE 0 END) as interview,
            SUM(CASE WHEN application_status = 'offered' THEN 1 ELSE 0 END) as offered,
            SUM(CASE WHEN application_status = 'hired' THEN 1 ELSE 0 END) as hired,
            SUM(CASE WHEN application_status = 'rejected' THEN 1 ELSE 0 END) as rejected,
            ROUND(AVG(match_score), 2) as avg_match_score,
            SUM(CASE WHEN match_score >= 80 THEN 1 ELSE 0 END) as high_match,
            SUM(CASE WHEN match_score >= 60 AND match_score < 80 THEN 1 ELSE 0 END) as medium_match,
            SUM(CASE WHEN match_score < 60 THEN 1 ELSE 0 END) as low_match
        FROM applications
        WHERE applied_at BETWEEN ? AND ?
    ", [$startDate . ' 00:00:00', $endDate . ' 23:59:59']);

    // Applications by status for pie chart
    $secondaryChartLabels = ['Applied', 'Reviewed', 'Shortlisted', 'Interview', 'Offered', 'Hired', 'Rejected'];
    $secondaryChartData = [
        $reportData['appStats']['applied'] ?? 0,
        $reportData['appStats']['reviewed'] ?? 0,
        $reportData['appStats']['shortlisted'] ?? 0,
        $reportData['appStats']['interview'] ?? 0,
        $reportData['appStats']['offered'] ?? 0,
        $reportData['appStats']['hired'] ?? 0,
        $reportData['appStats']['rejected'] ?? 0
    ];

    // Applications by date
    $appsByDate = fetchAll("
        SELECT 
            DATE(applied_at) as date,
            COUNT(*) as count
        FROM applications
        WHERE applied_at BETWEEN ? AND ?
        GROUP BY DATE(applied_at)
        ORDER BY date ASC
    ", [$startDate . ' 00:00:00', $endDate . ' 23:59:59']);

    // Fill chart data
    $current = strtotime($startDate);
    $end = strtotime($endDate);
    $dateMap = [];
    
    while ($current <= $end) {
        $date = date('Y-m-d', $current);
        $chartLabels[] = date('M d', $current);
        $dateMap[$date] = 0;
        $current = strtotime('+1 day', $current);
    }
    
    foreach ($appsByDate as $day) {
        if (isset($dateMap[$day['date']])) {
            $dateMap[$day['date']] = (int)$day['count'];
        }
    }
    
    $chartData = array_values($dateMap);

    // Top jobs by applications
    $reportData['topJobs'] = fetchAll("
        SELECT 
            j.id,
            j.title,
            j.slug,
            e.company_name,
            COUNT(a.id) as application_count,
            SUM(CASE WHEN a.application_status = 'hired' THEN 1 ELSE 0 END) as hires,
            ROUND(AVG(a.match_score), 2) as avg_match
        FROM jobs j
        INNER JOIN employers e ON j.employer_id = e.id
        LEFT JOIN applications a ON a.job_id = j.id AND a.applied_at BETWEEN ? AND ?
        GROUP BY j.id
        HAVING application_count > 0
        ORDER BY application_count DESC
        LIMIT 10
    ", [$startDate . ' 00:00:00', $endDate . ' 23:59:59']);

    // Applications by category
    $reportData['appsByCategory'] = fetchAll("
        SELECT 
            jc.name,
            COUNT(a.id) as application_count,
            COUNT(DISTINCT a.seeker_id) as unique_seekers,
            COUNT(DISTINCT j.employer_id) as employers,
            SUM(CASE WHEN a.application_status = 'hired' THEN 1 ELSE 0 END) as hires
        FROM job_categories jc
        LEFT JOIN jobs j ON j.category_id = jc.id
        LEFT JOIN applications a ON a.job_id = j.id AND a.applied_at BETWEEN ? AND ?
        GROUP BY jc.id
        HAVING application_count > 0
        ORDER BY application_count DESC
    ", [$startDate . ' 00:00:00', $endDate . ' 23:59:59']);
}

// 5. FINANCIAL REPORT
elseif ($reportType === 'financial') {
    // Financial statistics (based on job postings, featured listings, etc.)
    // Note: This assumes you have pricing data - adjust as needed
    $reportData['financialStats'] = fetchOne("
        SELECT 
            COUNT(*) as total_jobs,
            SUM(CASE WHEN is_featured = 1 THEN 1 ELSE 0 END) as featured_jobs,
            SUM(CASE WHEN is_urgent = 1 THEN 1 ELSE 0 END) as urgent_jobs,
            COUNT(*) * 0 as estimated_revenue -- Replace with actual pricing logic
        FROM jobs
        WHERE created_at BETWEEN ? AND ?
    ", [$startDate . ' 00:00:00', $endDate . ' 23:59:59']);

    // For demo purposes, calculate estimated revenue
    $featuredPrice = 49.99;
    $urgentPrice = 29.99;
    $regularPrice = 0; // Free
    
    $featuredCount = $reportData['financialStats']['featured_jobs'] ?? 0;
    $urgentCount = $reportData['financialStats']['urgent_jobs'] ?? 0;
    $regularCount = ($reportData['financialStats']['total_jobs'] ?? 0) - $featuredCount - $urgentCount;
    
    $reportData['revenueBreakdown'] = [
        'featured' => $featuredCount * $featuredPrice,
        'urgent' => $urgentCount * $urgentPrice,
        'regular' => $regularCount * $regularPrice,
        'total' => ($featuredCount * $featuredPrice) + ($urgentCount * $urgentPrice)
    ];

    $secondaryChartLabels = ['Featured Jobs', 'Urgent Jobs', 'Regular Jobs'];
    $secondaryChartData = [$featuredCount, $urgentCount, $regularCount];
}

// 6. ENGAGEMENT REPORT
elseif ($reportType === 'engagement') {
    // User engagement metrics
    $reportData['engagement'] = fetchOne("
        SELECT 
            (SELECT COUNT(*) FROM users WHERE last_login BETWEEN ? AND ?) as active_users,
            (SELECT COUNT(*) FROM users WHERE created_at BETWEEN ? AND ?) as new_users,
            (SELECT COUNT(DISTINCT seeker_id) FROM applications WHERE applied_at BETWEEN ? AND ?) as applying_seekers,
            (SELECT COUNT(DISTINCT employer_id) FROM jobs WHERE created_at BETWEEN ? AND ?) as posting_employers,
            (SELECT AVG(application_count) FROM (
                SELECT seeker_id, COUNT(*) as application_count 
                FROM applications 
                WHERE applied_at BETWEEN ? AND ? 
                GROUP BY seeker_id
            ) t) as avg_applications_per_seeker,
            (SELECT AVG(job_count) FROM (
                SELECT employer_id, COUNT(*) as job_count 
                FROM jobs 
                WHERE created_at BETWEEN ? AND ? 
                GROUP BY employer_id
            ) t) as avg_jobs_per_employer
    ", array_fill(0, 21, $startDate . ' 00:00:00'));

    // Daily active users
    $dailyActive = fetchAll("
        SELECT 
            DATE(last_login) as date,
            COUNT(*) as count
        FROM users
        WHERE last_login BETWEEN ? AND ?
        GROUP BY DATE(last_login)
        ORDER BY date ASC
    ", [$startDate . ' 00:00:00', $endDate . ' 23:59:59']);

    // Fill chart data
    $current = strtotime($startDate);
    $end = strtotime($endDate);
    $dateMap = [];
    
    while ($current <= $end) {
        $date = date('Y-m-d', $current);
        $chartLabels[] = date('M d', $current);
        $dateMap[$date] = 0;
        $current = strtotime('+1 day', $current);
    }
    
    foreach ($dailyActive as $day) {
        if (isset($dateMap[$day['date']])) {
            $dateMap[$day['date']] = (int)$day['count'];
        }
    }
    
    $chartData = array_values($dateMap);

    // Peak hours
    $reportData['peakHours'] = fetchAll("
        SELECT 
            HOUR(last_login) as hour,
            COUNT(*) as count
        FROM users
        WHERE last_login BETWEEN ? AND ?
        GROUP BY HOUR(last_login)
        ORDER BY hour ASC
    ", [$startDate . ' 00:00:00', $endDate . ' 23:59:59']);
}

// 7. SYSTEM HEALTH REPORT
elseif ($reportType === 'system') {
    // System statistics
    $reportData['system'] = [
        'total_users' => fetchOne("SELECT COUNT(*) as count FROM users")['count'] ?? 0,
        'total_jobs' => fetchOne("SELECT COUNT(*) as count FROM jobs")['count'] ?? 0,
        'total_applications' => fetchOne("SELECT COUNT(*) as count FROM applications")['count'] ?? 0,
        'total_employers' => fetchOne("SELECT COUNT(*) as count FROM employers")['count'] ?? 0,
        'total_seekers' => fetchOne("SELECT COUNT(*) as count FROM seekers")['count'] ?? 0,
        'pending_emails' => fetchOne("SELECT COUNT(*) as count FROM email_queue WHERE status = 'pending'")['count'] ?? 0,
        'failed_emails' => fetchOne("SELECT COUNT(*) as count FROM email_queue WHERE status = 'failed'")['count'] ?? 0,
        'expired_jobs' => fetchOne("SELECT COUNT(*) as count FROM jobs WHERE status = 'expired'")['count'] ?? 0,
        'unverified_users' => fetchOne("SELECT COUNT(*) as count FROM users WHERE is_verified = 0")['count'] ?? 0,
        'database_size' => '~' . round(fetchOne("SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) as size FROM information_schema.tables WHERE table_schema = ?", [DB_NAME])['size'] ?? 0, 2) . ' MB'
    ];

    // Recent errors from logs (simplified - you'd need actual error logging)
    $reportData['recentErrors'] = [
        ['time' => date('Y-m-d H:i:s', strtotime('-5 minutes')), 'message' => 'Sample error - implement actual error logging'],
        ['time' => date('Y-m-d H:i:s', strtotime('-30 minutes')), 'message' => 'Another sample error']
    ];
}

// 8. CUSTOM REPORT (can combine multiple aspects)
else {
    // Default to overview
    header('Location: reports.php?type=overview&range=' . $dateRange);
    exit();
}

// ===== FETCH FILTER OPTIONS =====
$categories = fetchAll("SELECT id, name FROM job_categories WHERE is_active = 1 ORDER BY name");
$employers = fetchAll("SELECT e.id, e.company_name FROM employers e ORDER BY e.company_name");

// Avatar URL for admin
$avatarUrl = 'https://ui-avatars.com/api/?name=' . urlencode('Admin') . '&background=4f46e5&color=fff&size=128&bold=true';

// Format report title
$reportTitles = [
    'overview' => 'Overview Report',
    'users' => 'User Analytics Report',
    'jobs' => 'Job Postings Report',
    'applications' => 'Applications Report',
    'financial' => 'Financial Report',
    'engagement' => 'User Engagement Report',
    'system' => 'System Health Report'
];
$pageTitle = $reportTitles[$reportType] ?? 'Reports Dashboard';
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> - TransNow Admin</title>
    
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Ccircle cx='50' cy='50' r='45' fill='%234f46e5'/%3E%3Cpath d='M30 40 L70 40 L65 70 L35 70 Z' fill='white'/%3E%3Ccircle cx='50' cy='30' r='8' fill='white'/%3E%3C/svg%3E">
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    
    <!-- AOS Animation -->
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    
    <!-- DataTables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.dataTables.min.css">
    
    <!-- Date Range Picker -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.css">
    
    <style>
        * {
            font-family: 'Inter', sans-serif;
        }
        
        /* Custom Animations */
        @keyframes float {
            0%, 100% { transform: translateY(0) rotate(0deg); }
            25% { transform: translateY(-10px) rotate(2deg); }
            75% { transform: translateY(10px) rotate(-2deg); }
        }
        
        @keyframes pulse-glow {
            0%, 100% { box-shadow: 0 0 0 0 rgba(79, 70, 229, 0.5); }
            50% { box-shadow: 0 0 20px 5px rgba(79, 70, 229, 0.3); }
        }
        
        @keyframes slideIn {
            from { transform: translateX(20px); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        
        .animate-float {
            animation: float 6s ease-in-out infinite;
        }
        
        .animate-pulse-glow {
            animation: pulse-glow 2s ease-in-out infinite;
        }
        
        .animate-slideIn {
            animation: slideIn 0.3s ease-out forwards;
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
            width: 8px;
            height: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: #f1f1f1;
        }
        
        ::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, #4f46e5, #7c3aed);
            border-radius: 4px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(135deg, #7c3aed, #db2777);
        }
        
        /* Chart Container */
        .chart-container {
            position: relative;
            height: 300px;
            width: 100%;
        }
        
        /* Report Card */
        .report-card {
            @apply bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6;
            transition: all 0.3s ease;
        }
        
        .report-card:hover {
            @apply shadow-2xl;
        }
        
        /* Stat Value */
        .stat-value {
            font-size: 2.5rem;
            font-weight: 700;
            line-height: 1.2;
        }
        
        /* Export Button */
        .export-btn {
            @apply px-4 py-2 bg-gray-100 dark:bg-gray-700 rounded-lg text-sm font-medium hover:bg-gray-200 dark:hover:bg-gray-600 transition flex items-center space-x-2;
        }
        
        /* Filter Bar */
        .filter-bar {
            @apply bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-4 mb-6;
        }
        
        /* DataTables Customization */
        .dataTables_wrapper .dataTables_length,
        .dataTables_wrapper .dataTables_filter,
        .dataTables_wrapper .dataTables_info,
        .dataTables_wrapper .dataTables_paginate {
            @apply text-sm mb-4;
        }
        
        .dataTables_wrapper .dataTables_paginate .paginate_button {
            @apply px-3 py-2 mx-1 rounded-lg border border-gray-300 dark:border-gray-600 hover:bg-indigo-50 dark:hover:bg-gray-700;
        }
        
        .dataTables_wrapper .dataTables_paginate .paginate_button.current {
            @apply bg-indigo-600 text-white border-indigo-600;
        }
        
        /* Print Styles */
        @media print {
            .no-print {
                display: none !important;
            }
            body {
                background: white;
            }
            .report-card {
                box-shadow: none;
                border: 1px solid #ddd;
            }
        }
    </style>
</head>
<body class="bg-gray-50 dark:bg-gray-900 text-gray-900 dark:text-white transition-colors duration-300">

<!-- Flash Message -->
<?php echo displayFlashMessage(); ?>

<!-- Dark Mode Toggle -->
<button id="darkModeToggle" 
        class="fixed top-24 right-4 z-40 w-12 h-12 bg-white dark:bg-gray-800 text-gray-700 dark:text-white rounded-full flex items-center justify-center hover:scale-110 transition-all duration-300 shadow-xl border border-gray-200 dark:border-gray-700 no-print">
    <i class="fas fa-moon text-xl" id="darkModeIcon"></i>
</button>

<!-- Main Layout -->
<div class="flex h-screen overflow-hidden">
    <!-- Sidebar -->
    <aside class="hidden lg:flex lg:flex-shrink-0 no-print">
        <div class="flex flex-col w-72 bg-white dark:bg-gray-800 border-r border-gray-200 dark:border-gray-700 overflow-y-auto">
            <!-- Sidebar Header -->
            <div class="p-6">
                <a href="/index.php" class="flex items-center space-x-3">
                    <div class="w-10 h-10 bg-gradient-to-r from-indigo-600 to-purple-600 rounded-lg flex items-center justify-center">
                        <i class="fas fa-globe-asia text-white text-lg"></i>
                    </div>
                    <span class="text-xl font-bold">
                        <span class="gradient-text">TransNow</span>
                    </span>
                    <span class="text-xs bg-red-500 text-white px-2 py-1 rounded-full">Admin</span>
                </a>
            </div>
            
            <!-- Admin Profile Summary -->
            <div class="px-6 py-4 border-t border-b border-gray-200 dark:border-gray-700">
                <div class="flex items-center space-x-4">
                    <div class="relative">
                        <img src="<?php echo htmlspecialchars($avatarUrl); ?>" 
                             alt="Admin" 
                             class="w-16 h-16 rounded-xl object-cover border-2 border-indigo-200 dark:border-indigo-800">
                        <div class="absolute -bottom-1 -right-1 w-5 h-5 bg-green-500 border-2 border-white dark:border-gray-800 rounded-full animate-pulse"></div>
                    </div>
                    <div class="flex-1 min-w-0">
                        <h3 class="font-bold text-lg truncate">Administrator</h3>
                        <p class="text-sm text-gray-600 dark:text-gray-400 truncate">
                            <?php echo htmlspecialchars($adminData['email'] ?? 'admin@transnow.com'); ?>
                        </p>
                        <div class="flex items-center mt-1">
                            <div class="w-2 h-2 bg-green-500 rounded-full mr-2"></div>
                            <span class="text-xs text-gray-500">Online</span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Navigation -->
            <nav class="flex-1 px-4 py-6 space-y-1">
                <!-- Dashboard -->
                <a href="dashboard.php" class="flex items-center space-x-3 px-4 py-3 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                    <i class="fas fa-tachometer-alt w-5"></i>
                    <span>Dashboard</span>
                </a>
               
                <!-- Jobs Management -->
                <a href="jobs.php" class="flex items-center space-x-3 px-4 py-3 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                    <i class="fas fa-briefcase w-5"></i>
                    <span>Jobs</span>
                </a>
                
                <!-- Applications -->
                <a href="applications.php" class="flex items-center space-x-3 px-4 py-3 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                    <i class="fas fa-file-alt w-5"></i>
                    <span>Applications</span>
                </a>
                
                <!-- Categories -->
                <a href="categories.php" class="flex items-center space-x-3 px-4 py-3 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                    <i class="fas fa-tags w-5"></i>
                    <span>Categories</span>
                </a>
              
                <!-- Reports (Active) -->
                <a href="reports.php" class="flex items-center space-x-3 px-4 py-3 bg-indigo-50 dark:bg-indigo-900/20 text-indigo-600 dark:text-indigo-400 rounded-xl font-medium">
                    <i class="fas fa-chart-bar w-5"></i>
                    <span>Reports</span>
                </a>
                
                <!-- Settings -->
                <a href="settings.php" class="flex items-center space-x-3 px-4 py-3 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                    <i class="fas fa-cog w-5"></i>
                    <span>Settings</span>
                </a>
                
                <!-- Email Queue -->
                <a href="email-queue.php" class="flex items-center space-x-3 px-4 py-3 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                    <i class="fas fa-envelope w-5"></i>
                    <span>Email Queue</span>
                </a>
                
                <!-- Divider -->
                <div class="border-t border-gray-200 dark:border-gray-700 my-4"></div>
                
                <!-- Help & Support -->
                <a href="/help-center.php" class="flex items-center space-x-3 px-4 py-3 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition">
                    <i class="fas fa-question-circle w-5"></i>
                    <span>Help Center</span>
                </a>
                
                <!-- Logout -->
                <a href="/logout.php" class="flex items-center space-x-3 px-4 py-3 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-xl transition">
                    <i class="fas fa-sign-out-alt w-5"></i>
                    <span>Logout</span>
                </a>
            </nav>
            
            <!-- Sidebar Footer -->
            <div class="p-4 border-t border-gray-200 dark:border-gray-700">
                <div class="bg-gradient-to-r from-indigo-500 to-purple-600 rounded-xl p-4 text-white">
                    <p class="text-sm opacity-90 mb-2">Report Period</p>
                    <p class="text-xs"><?php echo date('M d, Y', strtotime($startDate)); ?> - <?php echo date('M d, Y', strtotime($endDate)); ?></p>
                </div>
            </div>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="flex-1 overflow-y-auto bg-gray-50 dark:bg-gray-900">
        <!-- Top Navigation -->
        <header class="sticky top-0 z-30 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 no-print">
            <div class="px-6 py-4">
                <div class="flex items-center justify-between">
                    <!-- Mobile Menu Toggle -->
                    <button id="mobileSidebarToggle" class="lg:hidden w-10 h-10 bg-gray-100 dark:bg-gray-700 rounded-lg flex items-center justify-center hover:bg-gray-200 dark:hover:bg-gray-600 transition">
                        <i class="fas fa-bars"></i>
                    </button>
                    
                    <!-- Page Title -->
                    <h1 class="text-2xl font-bold hidden md:block">
                        <span class="gradient-text"><?php echo $pageTitle; ?></span>
                    </h1>
                    
                    <!-- Export Actions -->
                    <div class="flex items-center space-x-3">
                        <button onclick="window.print()" class="export-btn">
                            <i class="fas fa-print"></i>
                            <span class="hidden sm:inline">Print</span>
                        </button>
                        <button onclick="exportToCSV()" class="export-btn">
                            <i class="fas fa-file-csv"></i>
                            <span class="hidden sm:inline">Export CSV</span>
                        </button>
                        <button onclick="exportToPDF()" class="export-btn">
                            <i class="fas fa-file-pdf"></i>
                            <span class="hidden sm:inline">Export PDF</span>
                        </button>
                    </div>
                </div>
            </div>
        </header>

        <!-- Dashboard Content -->
        <div class="p-6">
            <!-- Report Type Navigation -->
            <div class="filter-bar no-print">
                <div class="flex flex-wrap items-center gap-2">
                    <a href="?type=overview&range=30" class="px-4 py-2 rounded-lg text-sm font-medium transition <?php echo $reportType === 'overview' ? 'bg-indigo-600 text-white' : 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600'; ?>">
                        <i class="fas fa-chart-pie mr-2"></i>Overview
                    </a>
                    <a href="?type=users&range=30" class="px-4 py-2 rounded-lg text-sm font-medium transition <?php echo $reportType === 'users' ? 'bg-indigo-600 text-white' : 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600'; ?>">
                        <i class="fas fa-users mr-2"></i>Users
                    </a>
                    <a href="?type=jobs&range=30" class="px-4 py-2 rounded-lg text-sm font-medium transition <?php echo $reportType === 'jobs' ? 'bg-indigo-600 text-white' : 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600'; ?>">
                        <i class="fas fa-briefcase mr-2"></i>Jobs
                    </a>
                    <a href="?type=applications&range=30" class="px-4 py-2 rounded-lg text-sm font-medium transition <?php echo $reportType === 'applications' ? 'bg-indigo-600 text-white' : 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600'; ?>">
                        <i class="fas fa-file-alt mr-2"></i>Applications
                    </a>
                    <a href="?type=financial&range=30" class="px-4 py-2 rounded-lg text-sm font-medium transition <?php echo $reportType === 'financial' ? 'bg-indigo-600 text-white' : 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600'; ?>">
                        <i class="fas fa-dollar-sign mr-2"></i>Financial
                    </a>
                    <a href="?type=engagement&range=30" class="px-4 py-2 rounded-lg text-sm font-medium transition <?php echo $reportType === 'engagement' ? 'bg-indigo-600 text-white' : 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600'; ?>">
                        <i class="fas fa-rocket mr-2"></i>Engagement
                    </a>
                    <a href="?type=system&range=30" class="px-4 py-2 rounded-lg text-sm font-medium transition <?php echo $reportType === 'system' ? 'bg-indigo-600 text-white' : 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600'; ?>">
                        <i class="fas fa-heartbeat mr-2"></i>System
                    </a>
                </div>
            </div>

            <!-- Filter Bar -->
            <div class="filter-bar no-print">
                <form method="GET" class="flex flex-wrap items-end gap-4">
                    <input type="hidden" name="type" value="<?php echo htmlspecialchars($reportType); ?>">
                    
                    <!-- Date Range -->
                    <div class="flex-1 min-w-[200px]">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Date Range</label>
                        <select name="range" class="w-full px-4 py-2 bg-gray-100 dark:bg-gray-700 rounded-lg border-0 focus:ring-2 focus:ring-indigo-500">
                            <option value="7" <?php echo $dateRange == '7' ? 'selected' : ''; ?>>Last 7 days</option>
                            <option value="30" <?php echo $dateRange == '30' ? 'selected' : ''; ?>>Last 30 days</option>
                            <option value="90" <?php echo $dateRange == '90' ? 'selected' : ''; ?>>Last 90 days</option>
                            <option value="365" <?php echo $dateRange == '365' ? 'selected' : ''; ?>>Last 12 months</option>
                            <option value="custom" <?php echo $dateRange == 'custom' ? 'selected' : ''; ?>>Custom Range</option>
                        </select>
                    </div>
                    
                    <?php if ($dateRange == 'custom'): ?>
                    <!-- Custom Start Date -->
                    <div class="flex-1 min-w-[150px]">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Start Date</label>
                        <input type="date" name="start_date" value="<?php echo htmlspecialchars($startDate); ?>" class="w-full px-4 py-2 bg-gray-100 dark:bg-gray-700 rounded-lg border-0 focus:ring-2 focus:ring-indigo-500">
                    </div>
                    
                    <!-- Custom End Date -->
                    <div class="flex-1 min-w-[150px]">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">End Date</label>
                        <input type="date" name="end_date" value="<?php echo htmlspecialchars($endDate); ?>" class="w-full px-4 py-2 bg-gray-100 dark:bg-gray-700 rounded-lg border-0 focus:ring-2 focus:ring-indigo-500">
                    </div>
                    <?php endif; ?>
                    
                    <!-- Category Filter (for jobs/applications) -->
                    <?php if (in_array($reportType, ['jobs', 'applications']) && !empty($categories)): ?>
                    <div class="flex-1 min-w-[150px]">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Category</label>
                        <select name="category" class="w-full px-4 py-2 bg-gray-100 dark:bg-gray-700 rounded-lg border-0 focus:ring-2 focus:ring-indigo-500">
                            <option value="all">All Categories</option>
                            <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo $cat['id']; ?>" <?php echo $category == $cat['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cat['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Employer Filter (for jobs) -->
                    <?php if ($reportType === 'jobs' && !empty($employers)): ?>
                    <div class="flex-1 min-w-[150px]">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Employer</label>
                        <select name="employer_id" class="w-full px-4 py-2 bg-gray-100 dark:bg-gray-700 rounded-lg border-0 focus:ring-2 focus:ring-indigo-500">
                            <option value="all">All Employers</option>
                            <?php foreach ($employers as $emp): ?>
                            <option value="<?php echo $emp['id']; ?>" <?php echo $employerId == $emp['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($emp['company_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Apply Filters Button -->
                    <div>
                        <button type="submit" class="px-6 py-2 bg-gradient-to-r from-indigo-600 to-purple-600 text-white rounded-lg font-medium hover:shadow-lg transition-all">
                            <i class="fas fa-filter mr-2"></i>Apply Filters
                        </button>
                    </div>
                </form>
            </div>

            <!-- Report Content -->
            <div class="space-y-6">
                <?php if ($reportType === 'overview'): ?>
                    <!-- OVERVIEW REPORT -->
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                        <!-- New Users -->
                        <div class="report-card" data-aos="fade-up">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-sm text-gray-500">New Users</p>
                                    <p class="stat-value gradient-text"><?php echo number_format($reportData['metrics']['new_users'] ?? 0); ?></p>
                                </div>
                                <div class="w-12 h-12 bg-indigo-100 dark:bg-indigo-900/20 rounded-xl flex items-center justify-center">
                                    <i class="fas fa-users text-indigo-600 text-xl"></i>
                                </div>
                            </div>
                            <p class="text-sm text-gray-500 mt-2">
                                <?php echo number_format($reportData['metrics']['new_seekers'] ?? 0); ?> Seekers • 
                                <?php echo number_format($reportData['metrics']['new_employers'] ?? 0); ?> Employers
                            </p>
                        </div>
                        
                        <!-- New Jobs -->
                        <div class="report-card" data-aos="fade-up" data-aos-delay="50">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-sm text-gray-500">New Jobs</p>
                                    <p class="stat-value gradient-text"><?php echo number_format($reportData['metrics']['new_jobs'] ?? 0); ?></p>
                                </div>
                                <div class="w-12 h-12 bg-purple-100 dark:bg-purple-900/20 rounded-xl flex items-center justify-center">
                                    <i class="fas fa-briefcase text-purple-600 text-xl"></i>
                                </div>
                            </div>
                            <p class="text-sm text-gray-500 mt-2">
                                <?php echo number_format($reportData['metrics']['active_jobs'] ?? 0); ?> Active Jobs
                            </p>
                        </div>
                        
                        <!-- New Applications -->
                        <div class="report-card" data-aos="fade-up" data-aos-delay="100">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-sm text-gray-500">New Applications</p>
                                    <p class="stat-value gradient-text"><?php echo number_format($reportData['metrics']['new_applications'] ?? 0); ?></p>
                                </div>
                                <div class="w-12 h-12 bg-green-100 dark:bg-green-900/20 rounded-xl flex items-center justify-center">
                                    <i class="fas fa-file-alt text-green-600 text-xl"></i>
                                </div>
                            </div>
                            <p class="text-sm text-gray-500 mt-2">
                                <?php echo number_format($reportData['metrics']['hires'] ?? 0); ?> Hires
                            </p>
                        </div>
                        
                        <!-- Interviews -->
                        <div class="report-card" data-aos="fade-up" data-aos-delay="150">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-sm text-gray-500">Interviews</p>
                                    <p class="stat-value gradient-text"><?php echo number_format($reportData['metrics']['interviews_scheduled'] ?? 0); ?></p>
                                </div>
                                <div class="w-12 h-12 bg-yellow-100 dark:bg-yellow-900/20 rounded-xl flex items-center justify-center">
                                    <i class="fas fa-calendar-check text-yellow-600 text-xl"></i>
                                </div>
                            </div>
                            <p class="text-sm text-gray-500 mt-2">
                                Avg Match: <?php echo $reportData['metrics']['avg_match_score'] ?? 0; ?>%
                            </p>
                        </div>
                    </div>
                    
                    <!-- Activity Chart -->
                    <div class="report-card" data-aos="fade-up">
                        <div class="flex items-center justify-between mb-6">
                            <h2 class="text-xl font-bold">Daily Activity</h2>
                            <div class="flex space-x-4">
                                <span class="flex items-center text-sm">
                                    <span class="w-3 h-3 bg-indigo-600 rounded-full mr-2"></span> Users
                                </span>
                                <span class="flex items-center text-sm">
                                    <span class="w-3 h-3 bg-purple-600 rounded-full mr-2"></span> Jobs
                                </span>
                                <span class="flex items-center text-sm">
                                    <span class="w-3 h-3 bg-green-600 rounded-full mr-2"></span> Applications
                                </span>
                            </div>
                        </div>
                        <div class="chart-container">
                            <canvas id="activityChart"></canvas>
                        </div>
                    </div>
                    
                    <!-- Top Categories & User Growth -->
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <!-- Top Categories -->
                        <div class="report-card" data-aos="fade-up">
                            <h2 class="text-xl font-bold mb-6">Top Categories</h2>
                            <?php if (empty($reportData['topCategories'])): ?>
                            <p class="text-gray-500 text-center py-8">No data available</p>
                            <?php else: ?>
                            <div class="space-y-4">
                                <?php foreach ($reportData['topCategories'] as $cat): ?>
                                <div>
                                    <div class="flex items-center justify-between text-sm mb-1">
                                        <span class="font-medium"><?php echo htmlspecialchars($cat['name']); ?></span>
                                        <span class="text-gray-500"><?php echo $cat['application_count']; ?> apps</span>
                                    </div>
                                    <div class="w-full h-2 bg-gray-200 dark:bg-gray-700 rounded-full overflow-hidden">
                                        <div class="bg-gradient-to-r from-indigo-600 to-purple-600 h-full rounded-full" style="width: <?php echo $cat['job_count'] > 0 ? min(100, ($cat['application_count'] / max($reportData['topCategories'][0]['application_count'], 1)) * 100) : 0; ?>%"></div>
                                    </div>
                                    <div class="flex text-xs text-gray-500 mt-1">
                                        <span class="mr-3"><?php echo $cat['job_count']; ?> jobs</span>
                                        <span><?php echo $cat['hire_count']; ?> hires</span>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- User Growth -->
                        <div class="report-card" data-aos="fade-up">
                            <h2 class="text-xl font-bold mb-6">User Growth</h2>
                            <?php if (empty($reportData['userGrowth'])): ?>
                            <p class="text-gray-500 text-center py-8">No data available</p>
                            <?php else: ?>
                            <div class="space-y-3">
                                <?php foreach (array_slice($reportData['userGrowth'], -10) as $day): ?>
                                <div class="flex items-center justify-between">
                                    <span class="text-sm"><?php echo date('M d', strtotime($day['date'])); ?></span>
                                    <div class="flex-1 mx-4">
                                        <div class="flex h-2 space-x-1">
                                            <div class="bg-indigo-600 h-2 rounded-l-full" style="width: <?php echo ($day['seekers'] / max($day['seekers'] + $day['employers'], 1)) * 100; ?>%"></div>
                                            <div class="bg-purple-600 h-2 rounded-r-full" style="width: <?php echo ($day['employers'] / max($day['seekers'] + $day['employers'], 1)) * 100; ?>%"></div>
                                        </div>
                                    </div>
                                    <span class="text-sm font-medium">+<?php echo $day['seekers'] + $day['employers']; ?></span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                <?php elseif ($reportType === 'users'): ?>
                    <!-- USERS REPORT -->
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
                        <!-- Total Users -->
                        <div class="report-card" data-aos="fade-up">
                            <p class="text-sm text-gray-500">Total Users</p>
                            <p class="stat-value gradient-text"><?php echo number_format($reportData['userStats']['total_users'] ?? 0); ?></p>
                        </div>
                        
                        <!-- Seekers -->
                        <div class="report-card" data-aos="fade-up" data-aos-delay="50">
                            <p class="text-sm text-gray-500">Seekers</p>
                            <p class="stat-value gradient-text"><?php echo number_format($reportData['userStats']['seekers'] ?? 0); ?></p>
                        </div>
                        
                        <!-- Employers -->
                        <div class="report-card" data-aos="fade-up" data-aos-delay="100">
                            <p class="text-sm text-gray-500">Employers</p>
                            <p class="stat-value gradient-text"><?php echo number_format($reportData['userStats']['employers'] ?? 0); ?></p>
                        </div>
                        
                        <!-- New Users (Selected Period) -->
                        <div class="report-card" data-aos="fade-up" data-aos-delay="150">
                            <p class="text-sm text-gray-500">New Users (Period)</p>
                            <p class="stat-value gradient-text"><?php echo number_format($reportData['userStats']['new_users'] ?? 0); ?></p>
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                        <!-- User Growth Chart -->
                        <div class="lg:col-span-2 report-card" data-aos="fade-up">
                            <div class="flex items-center justify-between mb-6">
                                <h2 class="text-xl font-bold">User Registration Trend</h2>
                                <div class="flex space-x-4">
                                    <span class="flex items-center text-sm">
                                        <span class="w-3 h-3 bg-indigo-600 rounded-full mr-2"></span> Seekers
                                    </span>
                                    <span class="flex items-center text-sm">
                                        <span class="w-3 h-3 bg-purple-600 rounded-full mr-2"></span> Employers
                                    </span>
                                </div>
                            </div>
                            <div class="chart-container">
                                <canvas id="usersChart"></canvas>
                            </div>
                        </div>
                        
                        <!-- Verification Status -->
                        <div class="report-card" data-aos="fade-up">
                            <h2 class="text-xl font-bold mb-6">Verification Status</h2>
                            <div class="chart-container" style="height: 250px;">
                                <canvas id="verificationChart"></canvas>
                            </div>
                            <div class="mt-4 text-center">
                                <p class="text-sm">
                                    <span class="text-green-600 font-bold"><?php echo number_format($reportData['userStats']['verified'] ?? 0); ?></span> Verified • 
                                    <span class="text-yellow-600 font-bold"><?php echo number_format($reportData['userStats']['unverified'] ?? 0); ?></span> Unverified
                                </p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Top Locations -->
                    <?php if (!empty($reportData['topLocations'])): ?>
                    <div class="report-card" data-aos="fade-up">
                        <h2 class="text-xl font-bold mb-6">Top Seeker Locations</h2>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <?php foreach ($reportData['topLocations'] as $loc): ?>
                            <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-700 rounded-lg">
                                <span><?php echo htmlspecialchars($loc['location'] ?: 'Unknown'); ?></span>
                                <span class="font-semibold"><?php echo number_format($loc['count']); ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                <?php elseif ($reportType === 'jobs'): ?>
                    <!-- JOBS REPORT -->
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
                        <!-- Total Jobs -->
                        <div class="report-card" data-aos="fade-up">
                            <p class="text-sm text-gray-500">Total Jobs</p>
                            <p class="stat-value gradient-text"><?php echo number_format($reportData['jobStats']['total_jobs'] ?? 0); ?></p>
                        </div>
                        
                        <!-- Active Jobs -->
                        <div class="report-card" data-aos="fade-up" data-aos-delay="50">
                            <p class="text-sm text-gray-500">Active</p>
                            <p class="stat-value gradient-text"><?php echo number_format($reportData['jobStats']['active'] ?? 0); ?></p>
                        </div>
                        
                        <!-- Featured Jobs -->
                        <div class="report-card" data-aos="fade-up" data-aos-delay="100">
                            <p class="text-sm text-gray-500">Featured</p>
                            <p class="stat-value gradient-text"><?php echo number_format($reportData['jobStats']['featured'] ?? 0); ?></p>
                        </div>
                        
                        <!-- Urgent Jobs -->
                        <div class="report-card" data-aos="fade-up" data-aos-delay="150">
                            <p class="text-sm text-gray-500">Urgent</p>
                            <p class="stat-value gradient-text"><?php echo number_format($reportData['jobStats']['urgent'] ?? 0); ?></p>
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                        <!-- Jobs Trend -->
                        <div class="lg:col-span-2 report-card" data-aos="fade-up">
                            <h2 class="text-xl font-bold mb-6">Jobs Posted Over Time</h2>
                            <div class="chart-container">
                                <canvas id="jobsChart"></canvas>
                            </div>
                        </div>
                        
                        <!-- Jobs by Status -->
                        <div class="report-card" data-aos="fade-up">
                            <h2 class="text-xl font-bold mb-6">Jobs by Status</h2>
                            <div class="chart-container" style="height: 250px;">
                                <canvas id="jobsStatusChart"></canvas>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Top Employers -->
                    <?php if (!empty($reportData['topEmployers'])): ?>
                    <div class="report-card" data-aos="fade-up">
                        <h2 class="text-xl font-bold mb-6">Top Employers by Job Postings</h2>
                        <div class="overflow-x-auto">
                            <table class="min-w-full">
                                <thead>
                                    <tr class="border-b border-gray-200 dark:border-gray-700">
                                        <th class="text-left py-3 px-4">Company</th>
                                        <th class="text-left py-3 px-4">Jobs Posted</th>
                                        <th class="text-left py-3 px-4">Total Applications</th>
                                        <th class="text-left py-3 px-4">Avg Apps/Job</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($reportData['topEmployers'] as $emp): ?>
                                    <tr class="border-b border-gray-200 dark:border-gray-700">
                                        <td class="py-3 px-4"><?php echo htmlspecialchars($emp['company_name']); ?></td>
                                        <td class="py-3 px-4 font-semibold"><?php echo $emp['job_count']; ?></td>
                                        <td class="py-3 px-4"><?php echo $emp['total_applications']; ?></td>
                                        <td class="py-3 px-4"><?php echo round($emp['avg_applications_per_job'], 1); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Jobs by Category & Location Type -->
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <?php if (!empty($reportData['jobsByCategory'])): ?>
                        <div class="report-card" data-aos="fade-up">
                            <h2 class="text-xl font-bold mb-6">Jobs by Category</h2>
                            <div class="space-y-3">
                                <?php foreach ($reportData['jobsByCategory'] as $cat): ?>
                                <div class="flex items-center justify-between">
                                    <span class="text-sm"><?php echo htmlspecialchars($cat['name']); ?></span>
                                    <div class="flex items-center space-x-4">
                                        <span class="text-sm font-semibold"><?php echo $cat['job_count']; ?> jobs</span>
                                        <span class="text-xs text-gray-500"><?php echo $cat['applications']; ?> apps</span>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($reportData['jobsByLocation'])): ?>
                        <div class="report-card" data-aos="fade-up">
                            <h2 class="text-xl font-bold mb-6">Jobs by Location Type</h2>
                            <div class="space-y-3">
                                <?php foreach ($reportData['jobsByLocation'] as $loc): ?>
                                <div class="flex items-center justify-between">
                                    <span class="text-sm capitalize"><?php echo htmlspecialchars($loc['location_type']); ?></span>
                                    <span class="text-sm font-semibold"><?php echo $loc['count']; ?> jobs</span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                <?php elseif ($reportType === 'applications'): ?>
                    <!-- APPLICATIONS REPORT -->
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
                        <!-- Total Apps -->
                        <div class="report-card" data-aos="fade-up">
                            <p class="text-sm text-gray-500">Total Apps</p>
                            <p class="stat-value gradient-text"><?php echo number_format($reportData['appStats']['total_applications'] ?? 0); ?></p>
                        </div>
                        
                        <!-- Hired -->
                        <div class="report-card" data-aos="fade-up" data-aos-delay="50">
                            <p class="text-sm text-gray-500">Hired</p>
                            <p class="stat-value gradient-text"><?php echo number_format($reportData['appStats']['hired'] ?? 0); ?></p>
                        </div>
                        
                        <!-- Interview -->
                        <div class="report-card" data-aos="fade-up" data-aos-delay="100">
                            <p class="text-sm text-gray-500">Interview</p>
                            <p class="stat-value gradient-text"><?php echo number_format($reportData['appStats']['interview'] ?? 0); ?></p>
                        </div>
                        
                        <!-- Avg Match -->
                        <div class="report-card" data-aos="fade-up" data-aos-delay="150">
                            <p class="text-sm text-gray-500">Avg Match Score</p>
                            <p class="stat-value gradient-text"><?php echo $reportData['appStats']['avg_match_score'] ?? 0; ?>%</p>
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                        <!-- Apps Trend -->
                        <div class="lg:col-span-2 report-card" data-aos="fade-up">
                            <h2 class="text-xl font-bold mb-6">Applications Over Time</h2>
                            <div class="chart-container">
                                <canvas id="applicationsChart"></canvas>
                            </div>
                        </div>
                        
                        <!-- Apps by Status -->
                        <div class="report-card" data-aos="fade-up">
                            <h2 class="text-xl font-bold mb-6">Applications by Status</h2>
                            <div class="chart-container" style="height: 250px;">
                                <canvas id="appsStatusChart"></canvas>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Top Jobs by Applications -->
                    <?php if (!empty($reportData['topJobs'])): ?>
                    <div class="report-card" data-aos="fade-up">
                        <h2 class="text-xl font-bold mb-6">Most Applied Jobs</h2>
                        <div class="overflow-x-auto">
                            <table class="min-w-full">
                                <thead>
                                    <tr class="border-b border-gray-200 dark:border-gray-700">
                                        <th class="text-left py-3 px-4">Job Title</th>
                                        <th class="text-left py-3 px-4">Company</th>
                                        <th class="text-left py-3 px-4">Applications</th>
                                        <th class="text-left py-3 px-4">Hires</th>
                                        <th class="text-left py-3 px-4">Avg Match</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($reportData['topJobs'] as $job): ?>
                                    <tr class="border-b border-gray-200 dark:border-gray-700">
                                        <td class="py-3 px-4">
                                            <a href="job-details.php?id=<?php echo $job['id']; ?>" class="text-indigo-600 hover:underline">
                                                <?php echo htmlspecialchars($job['title']); ?>
                                            </a>
                                        </td>
                                        <td class="py-3 px-4"><?php echo htmlspecialchars($job['company_name']); ?></td>
                                        <td class="py-3 px-4 font-semibold"><?php echo $job['application_count']; ?></td>
                                        <td class="py-3 px-4"><?php echo $job['hires']; ?></td>
                                        <td class="py-3 px-4"><?php echo $job['avg_match']; ?>%</td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Applications by Category -->
                    <?php if (!empty($reportData['appsByCategory'])): ?>
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <div class="report-card" data-aos="fade-up">
                            <h2 class="text-xl font-bold mb-6">Applications by Category</h2>
                            <div class="space-y-3">
                                <?php foreach ($reportData['appsByCategory'] as $cat): ?>
                                <div class="flex items-center justify-between">
                                    <span class="text-sm"><?php echo htmlspecialchars($cat['name']); ?></span>
                                    <div class="flex items-center space-x-4">
                                        <span class="text-sm font-semibold"><?php echo $cat['application_count']; ?> apps</span>
                                        <span class="text-xs text-gray-500"><?php echo $cat['hires']; ?> hires</span>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <!-- Match Score Distribution -->
                        <div class="report-card" data-aos="fade-up">
                            <h2 class="text-xl font-bold mb-6">Match Score Distribution</h2>
                            <div class="space-y-4">
                                <div>
                                    <div class="flex items-center justify-between text-sm mb-1">
                                        <span>High Match (80%+)</span>
                                        <span class="font-semibold"><?php echo $reportData['appStats']['high_match'] ?? 0; ?></span>
                                    </div>
                                    <div class="w-full h-2 bg-gray-200 rounded-full overflow-hidden">
                                        <div class="bg-green-500 h-full" style="width: <?php echo ($reportData['appStats']['high_match'] / max($reportData['appStats']['total_applications'], 1)) * 100; ?>%"></div>
                                    </div>
                                </div>
                                <div>
                                    <div class="flex items-center justify-between text-sm mb-1">
                                        <span>Medium Match (60-79%)</span>
                                        <span class="font-semibold"><?php echo $reportData['appStats']['medium_match'] ?? 0; ?></span>
                                    </div>
                                    <div class="w-full h-2 bg-gray-200 rounded-full overflow-hidden">
                                        <div class="bg-yellow-500 h-full" style="width: <?php echo ($reportData['appStats']['medium_match'] / max($reportData['appStats']['total_applications'], 1)) * 100; ?>%"></div>
                                    </div>
                                </div>
                                <div>
                                    <div class="flex items-center justify-between text-sm mb-1">
                                        <span>Low Match (under 60%)</span>
                                        <span class="font-semibold"><?php echo $reportData['appStats']['low_match'] ?? 0; ?></span>
                                    </div>
                                    <div class="w-full h-2 bg-gray-200 rounded-full overflow-hidden">
                                        <div class="bg-red-500 h-full" style="width: <?php echo ($reportData['appStats']['low_match'] / max($reportData['appStats']['total_applications'], 1)) * 100; ?>%"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                <?php elseif ($reportType === 'financial'): ?>
                    <!-- FINANCIAL REPORT -->
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <!-- Total Revenue -->
                        <div class="report-card" data-aos="fade-up">
                            <p class="text-sm text-gray-500">Estimated Revenue</p>
                            <p class="stat-value gradient-text">$<?php echo number_format($reportData['revenueBreakdown']['total'] ?? 0, 2); ?></p>
                        </div>
                        
                        <!-- Featured Revenue -->
                        <div class="report-card" data-aos="fade-up" data-aos-delay="50">
                            <p class="text-sm text-gray-500">Featured Jobs</p>
                            <p class="stat-value gradient-text">$<?php echo number_format($reportData['revenueBreakdown']['featured'] ?? 0, 2); ?></p>
                        </div>
                        
                        <!-- Urgent Revenue -->
                        <div class="report-card" data-aos="fade-up" data-aos-delay="100">
                            <p class="text-sm text-gray-500">Urgent Jobs</p>
                            <p class="stat-value gradient-text">$<?php echo number_format($reportData['revenueBreakdown']['urgent'] ?? 0, 2); ?></p>
                        </div>
                    </div>
                    
                    <!-- Revenue Breakdown -->
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <div class="report-card" data-aos="fade-up">
                            <h2 class="text-xl font-bold mb-6">Revenue by Job Type</h2>
                            <div class="chart-container" style="height: 300px;">
                                <canvas id="revenueChart"></canvas>
                            </div>
                        </div>
                        
                        <div class="report-card" data-aos="fade-up">
                            <h2 class="text-xl font-bold mb-6">Job Type Distribution</h2>
                            <div class="space-y-4">
                                <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-700 rounded-lg">
                                    <div>
                                        <span class="font-semibold">Featured Jobs</span>
                                        <p class="text-sm text-gray-500"><?php echo $reportData['financialStats']['featured_jobs'] ?? 0; ?> jobs</p>
                                    </div>
                                    <span class="text-xl font-bold">$<?php echo number_format($reportData['revenueBreakdown']['featured'] ?? 0, 2); ?></span>
                                </div>
                                <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-700 rounded-lg">
                                    <div>
                                        <span class="font-semibold">Urgent Jobs</span>
                                        <p class="text-sm text-gray-500"><?php echo $reportData['financialStats']['urgent_jobs'] ?? 0; ?> jobs</p>
                                    </div>
                                    <span class="text-xl font-bold">$<?php echo number_format($reportData['revenueBreakdown']['urgent'] ?? 0, 2); ?></span>
                                </div>
                                <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-700 rounded-lg">
                                    <div>
                                        <span class="font-semibold">Regular Jobs</span>
                                        <p class="text-sm text-gray-500"><?php echo ($reportData['financialStats']['total_jobs'] ?? 0) - ($reportData['financialStats']['featured_jobs'] ?? 0) - ($reportData['financialStats']['urgent_jobs'] ?? 0); ?> jobs</p>
                                    </div>
                                    <span class="text-xl font-bold">$0.00</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                <?php elseif ($reportType === 'engagement'): ?>
                    <!-- ENGAGEMENT REPORT -->
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
                        <!-- Active Users -->
                        <div class="report-card" data-aos="fade-up">
                            <p class="text-sm text-gray-500">Active Users</p>
                            <p class="stat-value gradient-text"><?php echo number_format($reportData['engagement']['active_users'] ?? 0); ?></p>
                        </div>
                        
                        <!-- Applying Seekers -->
                        <div class="report-card" data-aos="fade-up" data-aos-delay="50">
                            <p class="text-sm text-gray-500">Applying Seekers</p>
                            <p class="stat-value gradient-text"><?php echo number_format($reportData['engagement']['applying_seekers'] ?? 0); ?></p>
                        </div>
                        
                        <!-- Posting Employers -->
                        <div class="report-card" data-aos="fade-up" data-aos-delay="100">
                            <p class="text-sm text-gray-500">Posting Employers</p>
                            <p class="stat-value gradient-text"><?php echo number_format($reportData['engagement']['posting_employers'] ?? 0); ?></p>
                        </div>
                        
                        <!-- Avg Apps/Seeker -->
                        <div class="report-card" data-aos="fade-up" data-aos-delay="150">
                            <p class="text-sm text-gray-500">Avg Apps/Seeker</p>
                            <p class="stat-value gradient-text"><?php echo round($reportData['engagement']['avg_applications_per_seeker'] ?? 0, 1); ?></p>
                        </div>
                    </div>
                    
                    <!-- Daily Active Users -->
                    <div class="report-card" data-aos="fade-up">
                        <h2 class="text-xl font-bold mb-6">Daily Active Users</h2>
                        <div class="chart-container">
                            <canvas id="activeUsersChart"></canvas>
                        </div>
                    </div>
                    
                    <!-- Peak Hours -->
                    <?php if (!empty($reportData['peakHours'])): ?>
                    <div class="report-card" data-aos="fade-up">
                        <h2 class="text-xl font-bold mb-6">Peak Activity Hours</h2>
                        <div class="grid grid-cols-6 gap-2">
                            <?php 
                            $maxHour = max(array_column($reportData['peakHours'], 'count'));
                            foreach ($reportData['peakHours'] as $hour): 
                            ?>
                            <div class="text-center">
                                <div class="h-32 bg-gray-100 dark:bg-gray-700 rounded-lg relative mb-2">
                                    <div class="absolute bottom-0 left-0 right-0 bg-gradient-to-t from-indigo-600 to-purple-600 rounded-lg" 
                                         style="height: <?php echo ($hour['count'] / max($maxHour, 1)) * 100; ?>%"></div>
                                </div>
                                <span class="text-xs"><?php echo sprintf('%02d:00', $hour['hour']); ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                <?php elseif ($reportType === 'system'): ?>
                    <!-- SYSTEM HEALTH REPORT -->
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
                        <!-- Database Size -->
                        <div class="report-card" data-aos="fade-up">
                            <p class="text-sm text-gray-500">Database Size</p>
                            <p class="stat-value gradient-text"><?php echo $reportData['system']['database_size']; ?></p>
                        </div>
                        
                        <!-- Total Users -->
                        <div class="report-card" data-aos="fade-up" data-aos-delay="50">
                            <p class="text-sm text-gray-500">Total Users</p>
                            <p class="stat-value gradient-text"><?php echo number_format($reportData['system']['total_users']); ?></p>
                        </div>
                        
                        <!-- Pending Emails -->
                        <div class="report-card" data-aos="fade-up" data-aos-delay="100">
                            <p class="text-sm text-gray-500">Pending Emails</p>
                            <p class="stat-value gradient-text <?php echo $reportData['system']['pending_emails'] > 0 ? 'text-yellow-500' : ''; ?>">
                                <?php echo number_format($reportData['system']['pending_emails']); ?>
                            </p>
                        </div>
                        
                        <!-- Unverified Users -->
                        <div class="report-card" data-aos="fade-up" data-aos-delay="150">
                            <p class="text-sm text-gray-500">Unverified Users</p>
                            <p class="stat-value gradient-text"><?php echo number_format($reportData['system']['unverified_users']); ?></p>
                        </div>
                    </div>
                    
                    <!-- System Metrics -->
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <div class="report-card" data-aos="fade-up">
                            <h2 class="text-xl font-bold mb-6">System Metrics</h2>
                            <div class="space-y-4">
                                <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-700 rounded-lg">
                                    <span>Total Jobs</span>
                                    <span class="font-semibold"><?php echo number_format($reportData['system']['total_jobs']); ?></span>
                                </div>
                                <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-700 rounded-lg">
                                    <span>Total Applications</span>
                                    <span class="font-semibold"><?php echo number_format($reportData['system']['total_applications']); ?></span>
                                </div>
                                <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-700 rounded-lg">
                                    <span>Total Employers</span>
                                    <span class="font-semibold"><?php echo number_format($reportData['system']['total_employers']); ?></span>
                                </div>
                                <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-700 rounded-lg">
                                    <span>Total Seekers</span>
                                    <span class="font-semibold"><?php echo number_format($reportData['system']['total_seekers']); ?></span>
                                </div>
                                <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-700 rounded-lg">
                                    <span>Failed Emails</span>
                                    <span class="font-semibold <?php echo $reportData['system']['failed_emails'] > 0 ? 'text-red-500' : ''; ?>">
                                        <?php echo number_format($reportData['system']['failed_emails']); ?>
                                    </span>
                                </div>
                                <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-700 rounded-lg">
                                    <span>Expired Jobs</span>
                                    <span class="font-semibold"><?php echo number_format($reportData['system']['expired_jobs']); ?></span>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Recent Errors (if any) -->
                        <div class="report-card" data-aos="fade-up">
                            <h2 class="text-xl font-bold mb-6">System Logs</h2>
                            <?php if (empty($reportData['recentErrors'])): ?>
                            <p class="text-green-500 text-center py-8">
                                <i class="fas fa-check-circle text-4xl mb-3"></i><br>
                                No recent errors detected
                            </p>
                            <?php else: ?>
                            <div class="space-y-3">
                                <?php foreach ($reportData['recentErrors'] as $error): ?>
                                <div class="p-3 bg-red-50 dark:bg-red-900/20 rounded-lg">
                                    <p class="text-xs text-red-600 dark:text-red-400 mb-1"><?php echo $error['time']; ?></p>
                                    <p class="text-sm"><?php echo htmlspecialchars($error['message']); ?></p>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Quick Actions -->
                    <div class="report-card" data-aos="fade-up">
                        <h2 class="text-xl font-bold mb-6">System Actions</h2>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <button onclick="clearCache()" class="p-4 bg-gray-50 dark:bg-gray-700 rounded-xl hover:bg-gray-100 dark:hover:bg-gray-600 transition text-left">
                                <i class="fas fa-broom text-indigo-600 mb-2"></i>
                                <h3 class="font-semibold">Clear Cache</h3>
                                <p class="text-xs text-gray-500">Clear system cache and temporary data</p>
                            </button>
                            <button onclick="optimizeDatabase()" class="p-4 bg-gray-50 dark:bg-gray-700 rounded-xl hover:bg-gray-100 dark:hover:bg-gray-600 transition text-left">
                                <i class="fas fa-database text-green-600 mb-2"></i>
                                <h3 class="font-semibold">Optimize Database</h3>
                                <p class="text-xs text-gray-500">Run database optimization</p>
                            </button>
                            <button onclick="sendTestEmail()" class="p-4 bg-gray-50 dark:bg-gray-700 rounded-xl hover:bg-gray-100 dark:hover:bg-gray-600 transition text-left">
                                <i class="fas fa-envelope text-purple-600 mb-2"></i>
                                <h3 class="font-semibold">Test Email</h3>
                                <p class="text-xs text-gray-500">Send test email to admin</p>
                            </button>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Report Footer -->
            <div class="mt-8 text-center text-sm text-gray-500 no-print">
                <p>Report generated on <?php echo date('F j, Y \a\t g:i A'); ?> | Data from <?php echo date('M j, Y', strtotime($startDate)); ?> to <?php echo date('M j, Y', strtotime($endDate)); ?></p>
            </div>
        </div>
    </main>
</div>

<!-- Mobile Sidebar (Hidden by default) -->
<div id="mobileSidebar" class="fixed inset-0 z-50 hidden lg:hidden no-print">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" id="mobileSidebarOverlay"></div>
    <div class="absolute left-0 top-0 bottom-0 w-72 bg-white dark:bg-gray-800 overflow-y-auto">
        <div class="p-6">
            <div class="flex items-center justify-between mb-6">
                <a href="/index.php" class="flex items-center space-x-3">
                    <div class="w-10 h-10 bg-gradient-to-r from-indigo-600 to-purple-600 rounded-lg flex items-center justify-center">
                        <i class="fas fa-globe-asia text-white text-lg"></i>
                    </div>
                    <span class="text-xl font-bold">
                        <span class="gradient-text">TransNow</span>
                    </span>
                </a>
                <button id="closeMobileSidebar" class="w-10 h-10 bg-gray-100 dark:bg-gray-700 rounded-lg flex items-center justify-center">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <div class="px-2 py-4 border-t border-gray-200 dark:border-gray-700">
                <div class="flex items-center space-x-3 mb-4">
                    <img src="<?php echo htmlspecialchars($avatarUrl); ?>" alt="Admin" class="w-12 h-12 rounded-lg object-cover border-2 border-indigo-200">
                    <div class="flex-1 min-w-0">
                        <p class="font-semibold truncate">Administrator</p>
                        <p class="text-sm text-gray-500 truncate"><?php echo htmlspecialchars($adminData['email'] ?? ''); ?></p>
                    </div>
                </div>
                
                <nav class="space-y-1">
                    <a href="dashboard.php" class="block px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">Dashboard</a>
                    <a href="users.php" class="block px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">Users</a>
                    <a href="jobs.php" class="block px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">Jobs</a>
                    <a href="applications.php" class="block px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">Applications</a>
                    <a href="categories.php" class="block px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">Categories</a>
                    <a href="job-types.php" class="block px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">Job Types</a>
                    <a href="reports.php" class="block px-4 py-2 bg-indigo-50 dark:bg-indigo-900/20 text-indigo-600 rounded-lg">Reports</a>
                    <a href="settings.php" class="block px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">Settings</a>
                    <a href="email-queue.php" class="block px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">Email Queue</a>
                    <a href="/logout.php" class="block px-4 py-2 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg">Logout</a>
                </nav>
            </div>
        </div>
    </div>
</div>

<!-- Scripts -->
<script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
<script src="https://cdn.jsdelivr.net/momentjs/latest/moment.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.min.js"></script>

<script>
// Initialize AOS
AOS.init({
    duration: 800,
    once: true,
    offset: 50,
    easing: 'ease-out-cubic'
});

// Dark Mode Toggle
const darkModeToggle = document.getElementById('darkModeToggle');
const darkModeIcon = document.getElementById('darkModeIcon');

function initDarkMode() {
    if (localStorage.theme === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
        document.documentElement.classList.add('dark');
        darkModeIcon?.classList.remove('fa-moon');
        darkModeIcon?.classList.add('fa-sun');
    } else {
        document.documentElement.classList.remove('dark');
        darkModeIcon?.classList.remove('fa-sun');
        darkModeIcon?.classList.add('fa-moon');
    }
}
initDarkMode();

if (darkModeToggle) {
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
}

// Mobile Sidebar
const mobileSidebarToggle = document.getElementById('mobileSidebarToggle');
const mobileSidebar = document.getElementById('mobileSidebar');
const mobileSidebarOverlay = document.getElementById('mobileSidebarOverlay');
const closeMobileSidebar = document.getElementById('closeMobileSidebar');

if (mobileSidebarToggle && mobileSidebar) {
    mobileSidebarToggle.addEventListener('click', () => {
        mobileSidebar.classList.remove('hidden');
    });
}

function closeMobileSidebarFunc() {
    mobileSidebar?.classList.add('hidden');
}

if (mobileSidebarOverlay) {
    mobileSidebarOverlay.addEventListener('click', closeMobileSidebarFunc);
}
if (closeMobileSidebar) {
    closeMobileSidebar.addEventListener('click', closeMobileSidebarFunc);
}

// Handle custom date range display
document.querySelector('select[name="range"]')?.addEventListener('change', function() {
    if (this.value === 'custom') {
        // Reload with custom range enabled
        window.location.href = '?type=<?php echo $reportType; ?>&range=custom&start_date=<?php echo date('Y-m-d', strtotime('-30 days')); ?>&end_date=<?php echo date('Y-m-d'); ?>';
    } else {
        // Reload with selected range
        window.location.href = '?type=<?php echo $reportType; ?>&range=' + this.value;
    }
});

// Initialize Charts based on report type
<?php if ($reportType === 'overview' && !empty($chartData)): ?>
    // Activity Chart
    new Chart(document.getElementById('activityChart'), {
        type: 'line',
        data: {
            labels: <?php echo json_encode($chartLabels); ?>,
            datasets: [
                {
                    label: 'Users',
                    data: <?php echo json_encode($chartData['users'] ?? []); ?>,
                    borderColor: '#4f46e5',
                    backgroundColor: 'rgba(79, 70, 229, 0.1)',
                    borderWidth: 2,
                    tension: 0.4,
                    fill: true
                },
                {
                    label: 'Jobs',
                    data: <?php echo json_encode($chartData['jobs'] ?? []); ?>,
                    borderColor: '#7c3aed',
                    backgroundColor: 'rgba(124, 58, 237, 0.1)',
                    borderWidth: 2,
                    tension: 0.4,
                    fill: true
                },
                {
                    label: 'Applications',
                    data: <?php echo json_encode($chartData['applications'] ?? []); ?>,
                    borderColor: '#10b981',
                    backgroundColor: 'rgba(16, 185, 129, 0.1)',
                    borderWidth: 2,
                    tension: 0.4,
                    fill: true
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
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
<?php endif; ?>

<?php if ($reportType === 'users'): ?>
    // Users Chart
    new Chart(document.getElementById('usersChart'), {
        type: 'line',
        data: {
            labels: <?php echo json_encode($chartLabels); ?>,
            datasets: [
                {
                    label: 'Seekers',
                    data: <?php echo json_encode($chartData['seekers'] ?? []); ?>,
                    borderColor: '#4f46e5',
                    backgroundColor: 'rgba(79, 70, 229, 0.1)',
                    borderWidth: 2,
                    tension: 0.4,
                    fill: true
                },
                {
                    label: 'Employers',
                    data: <?php echo json_encode($chartData['employers'] ?? []); ?>,
                    borderColor: '#7c3aed',
                    backgroundColor: 'rgba(124, 58, 237, 0.1)',
                    borderWidth: 2,
                    tension: 0.4,
                    fill: true
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
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

    // Verification Status Chart
    new Chart(document.getElementById('verificationChart'), {
        type: 'doughnut',
        data: {
            labels: <?php echo json_encode($secondaryChartLabels); ?>,
            datasets: [{
                data: <?php echo json_encode($secondaryChartData); ?>,
                backgroundColor: ['#10b981', '#f59e0b'],
                borderWidth: 0
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom'
                }
            },
            cutout: '70%'
        }
    });
<?php endif; ?>

<?php if ($reportType === 'jobs'): ?>
    // Jobs Chart
    new Chart(document.getElementById('jobsChart'), {
        type: 'line',
        data: {
            labels: <?php echo json_encode($chartLabels); ?>,
            datasets: [{
                label: 'Jobs Posted',
                data: <?php echo json_encode($chartData); ?>,
                borderColor: '#4f46e5',
                backgroundColor: 'rgba(79, 70, 229, 0.1)',
                borderWidth: 2,
                tension: 0.4,
                fill: true
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
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

    // Jobs Status Chart
    new Chart(document.getElementById('jobsStatusChart'), {
        type: 'doughnut',
        data: {
            labels: <?php echo json_encode($secondaryChartLabels); ?>,
            datasets: [{
                data: <?php echo json_encode($secondaryChartData); ?>,
                backgroundColor: ['#10b981', '#9ca3af', '#f59e0b', '#ef4444', '#6b7280'],
                borderWidth: 0
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom'
                }
            },
            cutout: '70%'
        }
    });
<?php endif; ?>

<?php if ($reportType === 'applications'): ?>
    // Applications Chart
    new Chart(document.getElementById('applicationsChart'), {
        type: 'line',
        data: {
            labels: <?php echo json_encode($chartLabels); ?>,
            datasets: [{
                label: 'Applications',
                data: <?php echo json_encode($chartData); ?>,
                borderColor: '#4f46e5',
                backgroundColor: 'rgba(79, 70, 229, 0.1)',
                borderWidth: 2,
                tension: 0.4,
                fill: true
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
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

    // Applications Status Chart
    new Chart(document.getElementById('appsStatusChart'), {
        type: 'doughnut',
        data: {
            labels: <?php echo json_encode($secondaryChartLabels); ?>,
            datasets: [{
                data: <?php echo json_encode($secondaryChartData); ?>,
                backgroundColor: ['#3b82f6', '#8b5cf6', '#6366f1', '#f59e0b', '#10b981', '#059669', '#ef4444'],
                borderWidth: 0
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom'
                }
            },
            cutout: '70%'
        }
    });
<?php endif; ?>

<?php if ($reportType === 'financial'): ?>
    // Revenue Chart
    new Chart(document.getElementById('revenueChart'), {
        type: 'doughnut',
        data: {
            labels: <?php echo json_encode($secondaryChartLabels); ?>,
            datasets: [{
                data: <?php echo json_encode($secondaryChartData); ?>,
                backgroundColor: ['#4f46e5', '#7c3aed', '#9ca3af'],
                borderWidth: 0
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom'
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            let label = context.label || '';
                            let value = context.raw || 0;
                            return label + ': ' + value + ' jobs';
                        }
                    }
                }
            },
            cutout: '70%'
        }
    });
<?php endif; ?>

<?php if ($reportType === 'engagement'): ?>
    // Active Users Chart
    new Chart(document.getElementById('activeUsersChart'), {
        type: 'line',
        data: {
            labels: <?php echo json_encode($chartLabels); ?>,
            datasets: [{
                label: 'Active Users',
                data: <?php echo json_encode($chartData); ?>,
                borderColor: '#4f46e5',
                backgroundColor: 'rgba(79, 70, 229, 0.1)',
                borderWidth: 2,
                tension: 0.4,
                fill: true
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
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
<?php endif; ?>

// Export functions
function exportToCSV() {
    // In a real implementation, you'd generate CSV from the current report data
    alert('CSV export functionality would be implemented here. This would generate a CSV file with the current report data.');
}

function exportToPDF() {
    // In a real implementation, you'd generate PDF
    alert('PDF export functionality would be implemented here. This would generate a PDF report.');
}

// System action functions
function clearCache() {
    if (confirm('Are you sure you want to clear the system cache?')) {
        alert('Cache cleared successfully! (Demo only)');
    }
}

function optimizeDatabase() {
    if (confirm('Are you sure you want to optimize the database? This may take a few moments.')) {
        alert('Database optimization started! (Demo only)');
    }
}

function sendTestEmail() {
    alert('Test email sent to admin! (Demo only)');
}

// Auto-refresh for live data (optional)
let autoRefresh = false;
setInterval(() => {
    if (autoRefresh) {
        location.reload();
    }
}, 300000); // 5 minutes

// Keyboard shortcuts
document.addEventListener('keydown', (e) => {
    // Escape to close mobile sidebar
    if (e.key === 'Escape') {
        mobileSidebar?.classList.add('hidden');
    }
    // Ctrl/Cmd + P to print
    if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
        e.preventDefault();
        window.print();
    }
});

// Initialize DataTables if tables exist
$(document).ready(function() {
    $('.datatable').DataTable({
        responsive: true,
        pageLength: 25,
        order: [[0, 'desc']]
    });
});
</script>
</body>
</html>