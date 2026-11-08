<?php
// admin/help-center.php
// Independent Help Center Page for Admins

// Initialize session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in as admin
require_once '../includes/auth.php';
if (!isLoggedIn() || $_SESSION['user_type'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

// Include database connection
require_once '../config/database.php';

// Handle search functionality
$searchQuery = isset($_GET['search']) ? trim($_GET['search']) : '';
$activeCategory = isset($_GET['category']) ? $_GET['category'] : 'all';

// Get system information
$systemInfo = [];
try {
    // Get database info
    $stmt = $pdo->query("SELECT VERSION() as version");
    $systemInfo['mysql_version'] = $stmt->fetch()['version'];
    
    // Get PHP version
    $systemInfo['php_version'] = phpversion();
    
    // Get server software
    $systemInfo['server_software'] = $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown';
    
    // Get upload max size
    $systemInfo['upload_max_size'] = ini_get('upload_max_filesize');
    
    // Get memory limit
    $systemInfo['memory_limit'] = ini_get('memory_limit');
    
    // Count total users
    $stmt = $pdo->query("SELECT COUNT(*) FROM users");
    $systemInfo['total_users'] = $stmt->fetchColumn();
    
    // Count total jobs
    $stmt = $pdo->query("SELECT COUNT(*) FROM jobs");
    $systemInfo['total_jobs'] = $stmt->fetchColumn();
    
    // Count total applications
    $stmt = $pdo->query("SELECT COUNT(*) FROM applications");
    $systemInfo['total_applications'] = $stmt->fetchColumn();
    
} catch (PDOException $e) {
    error_log("Help Center DB Error: " . $e->getMessage());
}

// Help topics and documentation
$helpTopics = [
    'getting-started' => [
        'title' => 'Getting Started',
        'icon' => '🚀',
        'articles' => [
            'welcome' => [
                'title' => 'Welcome to the Admin Dashboard',
                'content' => 'The admin dashboard is your central command center for managing the job portal. Here you can monitor system activity, manage users, jobs, and configure system settings. This guide will help you navigate through all the features available to you as an administrator.',
                'steps' => [
                    'Log in using your admin credentials',
                    'Navigate through the sidebar menu to access different sections',
                    'Monitor the dashboard widgets for real-time statistics',
                    'Use the quick actions for common administrative tasks'
                ]
            ],
            'navigation' => [
                'title' => 'Understanding the Navigation Menu',
                'content' => 'The admin panel features a comprehensive navigation system to help you manage every aspect of the job portal efficiently.',
                'menu_items' => [
                    'Dashboard' => 'Overview of system statistics and recent activities',
                    'Users' => 'Manage all user accounts (job seekers, employers, admins)',
                    'Jobs' => 'Review and moderate job postings',
                    'Applications' => 'Monitor all job applications',
                    'Categories' => 'Manage job categories and types',
                    'Job Types' => 'Configure different job types',
                    'Reports' => 'Generate and view system reports',
                    'Settings' => 'Configure system-wide settings',
                    'Email Queue' => 'Monitor email delivery system',
                    'Interviews' => 'Track scheduled interviews'
                ]
            ]
        ]
    ],
    'user-management' => [
        'title' => 'User Management',
        'icon' => '👥',
        'articles' => [
            'user-types' => [
                'title' => 'User Types and Permissions',
                'content' => 'The system supports three user types with different permissions:',
                'user_types' => [
                    'Job Seekers' => 'Can browse jobs, apply, save jobs, manage profile, and track applications',
                    'Employers' => 'Can post jobs, manage applications, search candidates, and manage company profile',
                    'Admins' => 'Full system access including user management, content moderation, and system configuration'
                ]
            ],
            'managing-users' => [
                'title' => 'Managing User Accounts',
                'content' => 'As an admin, you can view, edit, suspend, or delete user accounts. You can also verify employer accounts and reset user passwords.',
                'actions' => [
                    'View all users with filtering options',
                    'Edit user profiles and account details',
                    'Verify/unverify employer accounts',
                    'Suspend or activate user accounts',
                    'Reset user passwords securely',
                    'View user activity logs'
                ]
            ],
            'bulk-actions' => [
                'title' => 'Bulk User Operations',
                'content' => 'Perform actions on multiple users simultaneously:',
                'bulk_options' => [
                    'Export user list to CSV/Excel',
                    'Send bulk email notifications',
                    'Update user status in bulk',
                    'Delete multiple user accounts'
                ]
            ]
        ]
    ],
    'job-management' => [
        'title' => 'Job Management',
        'icon' => '💼',
        'articles' => [
            'moderating-jobs' => [
                'title' => 'Job Posting Moderation',
                'content' => 'Review and moderate job postings to ensure they meet platform guidelines. You can approve, reject, or flag suspicious job postings.',
                'moderation_actions' => [
                    'Review job details and requirements',
                    'Check for appropriate content and format',
                    'Verify employer legitimacy',
                    'Approve or reject job postings',
                    'Flag jobs for review',
                    'Edit job details if necessary'
                ]
            ],
            'job-categories' => [
                'title' => 'Managing Job Categories',
                'content' => 'Organize jobs by creating and managing categories and job types. This helps users find relevant positions more easily.',
                'category_management' => [
                    'Create new job categories',
                    'Edit existing categories',
                    'Set category display order',
                    'Activate/deactivate categories',
                    'Manage job types within categories'
                ]
            ],
            'featured-jobs' => [
                'title' => 'Featured Jobs Management',
                'content' => 'Manage featured job listings and set expiration dates for featured status.',
                'featured_options' => [
                    'Mark jobs as featured',
                    'Set featured job duration',
                    'Manage featured job pricing',
                    'Track featured job performance'
                ]
            ]
        ]
    ],
    'application-tracking' => [
        'title' => 'Application Tracking',
        'icon' => '📋',
        'articles' => [
            'monitoring-applications' => [
                'title' => 'Monitoring Applications',
                'content' => 'Track all job applications across the platform. Monitor application status, review candidate profiles, and identify popular jobs.',
                'monitoring_features' => [
                    'View all applications with filters',
                    'Track application status changes',
                    'Monitor application volume by job',
                    'Review candidate qualifications',
                    'Export application data for analysis'
                ]
            ],
            'interview-management' => [
                'title' => 'Interview Management',
                'content' => 'Oversee the interview process and ensure smooth communication between employers and candidates.',
                'interview_features' => [
                    'View scheduled interviews',
                    'Monitor interview status',
                    'Track interview feedback',
                    'Resolve scheduling conflicts',
                    'Generate interview reports'
                ]
            ]
        ]
    ],
    'reports-analytics' => [
        'title' => 'Reports & Analytics',
        'icon' => '📊',
        'articles' => [
            'system-reports' => [
                'title' => 'Generating System Reports',
                'content' => 'Create and customize reports to analyze platform performance, user behavior, and job market trends.',
                'report_types' => [
                    'User registration and activity reports',
                    'Job posting and application statistics',
                    'Revenue and transaction reports (if applicable)',
                    'Platform usage analytics',
                    'Demographic reports'
                ]
            ],
            'exporting-data' => [
                'title' => 'Data Export Options',
                'content' => 'Export system data in various formats for external analysis or record-keeping.',
                'export_formats' => [
                    'CSV files for spreadsheet analysis',
                    'Excel worksheets with formatting',
                    'PDF reports for presentations',
                    'JSON for API integration',
                    'XML for data exchange'
                ]
            ]
        ]
    ],
    'system-settings' => [
        'title' => 'System Settings',
        'icon' => '⚙️',
        'articles' => [
            'general-settings' => [
                'title' => 'General Configuration',
                'content' => 'Configure system-wide settings that affect how the platform operates.',
                'config_options' => [
                    'Site name and branding',
                    'Contact information and support email',
                    'Timezone and date format',
                    'Language preferences',
                    'Registration settings and user verification'
                ]
            ],
            'email-settings' => [
                'title' => 'Email Configuration',
                'content' => 'Manage email templates and SMTP settings for system notifications.',
                'email_options' => [
                    'Configure SMTP server settings',
                    'Manage email templates',
                    'Set up automated notifications',
                    'Monitor email queue',
                    'Test email delivery'
                ]
            ],
            'security-settings' => [
                'title' => 'Security Configuration',
                'content' => 'Configure security settings to protect the platform and user data.',
                'security_options' => [
                    'Password policy configuration',
                    'Session management settings',
                    'Login attempt limits',
                    'Two-factor authentication setup',
                    'IP blocking and whitelisting'
                ]
            ]
        ]
    ],
    'troubleshooting' => [
        'title' => 'Troubleshooting',
        'icon' => '🔧',
        'articles' => [
            'common-issues' => [
                'title' => 'Common Issues and Solutions',
                'content' => 'Solutions for frequently encountered problems in the admin panel.',
                'issues' => [
                    'Login Issues' => 'Clear browser cache, check credentials, ensure account is active',
                    'Email Delivery Problems' => 'Check SMTP settings, verify email queue, check spam folder',
                    'Upload Failures' => 'Verify file permissions, check upload size limits, ensure disk space',
                    'Slow Performance' => 'Clear system cache, optimize database, check server resources'
                ]
            ],
            'error-messages' => [
                'title' => 'Understanding Error Messages',
                'content' => 'Common error messages and their meanings:',
                'errors' => [
                    'Database connection failed' => 'Check database credentials and server status',
                    'Permission denied' => 'Verify file/folder permissions and user roles',
                    'Session expired' => 'Session timeout reached, log in again',
                    'CSRF token mismatch' => 'Form security validation failed, refresh and try again'
                ]
            ],
            'debugging' => [
                'title' => 'Debugging Techniques',
                'content' => 'Methods to identify and resolve system issues:',
                'debug_methods' => [
                    'Enable error logging',
                    'Check system error logs',
                    'Use browser developer tools',
                    'Test with different browsers',
                    'Monitor database queries'
                ]
            ]
        ]
    ]
];

// Filter topics based on search query
if (!empty($searchQuery)) {
    $filteredTopics = [];
    foreach ($helpTopics as $topicKey => $topic) {
        $matchingArticles = [];
        foreach ($topic['articles'] as $articleKey => $article) {
            // Search in title and content
            if (stripos($article['title'], $searchQuery) !== false || 
                stripos($article['content'], $searchQuery) !== false) {
                $matchingArticles[$articleKey] = $article;
            }
        }
        if (!empty($matchingArticles)) {
            $filteredTopics[$topicKey] = $topic;
            $filteredTopics[$topicKey]['articles'] = $matchingArticles;
        }
    }
    if (!empty($filteredTopics)) {
        $helpTopics = $filteredTopics;
    }
}

// Get current article if specified
$currentTopic = isset($_GET['topic']) ? $_GET['topic'] : '';
$currentArticle = isset($_GET['article']) ? $_GET['article'] : '';
$showArticle = $currentTopic && $currentArticle && 
                isset($helpTopics[$currentTopic]['articles'][$currentArticle]);

// Page title
$pageTitle = "Admin Help Center";

// Include header
include '../includes/header.php';
?>

<style>
:root {
    --primary-color: #2563eb;
    --primary-dark: #1d4ed8;
    --secondary-color: #64748b;
    --success-color: #10b981;
    --danger-color: #ef4444;
    --warning-color: #f59e0b;
    --info-color: #3b82f6;
    --dark-bg: #1e293b;
    --light-bg: #f8fafc;
    --border-color: #e2e8f0;
    --text-primary: #0f172a;
    --text-secondary: #475569;
    --text-muted: #64748b;
    --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
    --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1);
    --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1);
}

.help-container {
    max-width: 1400px;
    margin: 2rem auto;
    padding: 0 1.5rem;
}

.help-header {
    background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
    color: white;
    padding: 3rem 2rem;
    border-radius: 1rem;
    margin-bottom: 2rem;
    box-shadow: var(--shadow-lg);
}

.help-header h1 {
    font-size: 2.5rem;
    font-weight: 700;
    margin-bottom: 1rem;
}

.help-header p {
    font-size: 1.1rem;
    opacity: 0.95;
    max-width: 600px;
}

.search-box {
    max-width: 600px;
    margin-top: 2rem;
}

.search-box form {
    display: flex;
    gap: 0.5rem;
}

.search-box input {
    flex: 1;
    padding: 1rem 1.5rem;
    border: none;
    border-radius: 0.5rem;
    font-size: 1rem;
    box-shadow: var(--shadow-md);
}

.search-box button {
    padding: 1rem 2rem;
    background: white;
    color: var(--primary-color);
    border: none;
    border-radius: 0.5rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: var(--shadow-md);
}

.search-box button:hover {
    background: var(--light-bg);
    transform: translateY(-2px);
}

.help-grid {
    display: grid;
    grid-template-columns: 280px 1fr;
    gap: 2rem;
}

.help-sidebar {
    background: white;
    border-radius: 1rem;
    padding: 1.5rem;
    box-shadow: var(--shadow-md);
    height: fit-content;
    position: sticky;
    top: 2rem;
}

.sidebar-section {
    margin-bottom: 2rem;
}

.sidebar-section h3 {
    font-size: 0.9rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-muted);
    margin-bottom: 1rem;
}

.sidebar-nav {
    list-style: none;
    padding: 0;
}

.sidebar-nav li {
    margin-bottom: 0.5rem;
}

.sidebar-nav a {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.75rem 1rem;
    color: var(--text-secondary);
    text-decoration: none;
    border-radius: 0.5rem;
    transition: all 0.2s ease;
}

.sidebar-nav a:hover {
    background: var(--light-bg);
    color: var(--primary-color);
}

.sidebar-nav a.active {
    background: var(--primary-color);
    color: white;
}

.sidebar-nav .icon {
    font-size: 1.25rem;
}

.help-content {
    background: white;
    border-radius: 1rem;
    padding: 2rem;
    box-shadow: var(--shadow-md);
    min-height: 600px;
}

.help-categories {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 1.5rem;
    margin-bottom: 2rem;
}

.category-card {
    background: var(--light-bg);
    border-radius: 0.75rem;
    padding: 1.5rem;
    cursor: pointer;
    transition: all 0.3s ease;
    border: 1px solid var(--border-color);
}

.category-card:hover {
    transform: translateY(-4px);
    box-shadow: var(--shadow-lg);
    border-color: var(--primary-color);
}

.category-icon {
    font-size: 2.5rem;
    margin-bottom: 1rem;
}

.category-title {
    font-size: 1.25rem;
    font-weight: 600;
    color: var(--text-primary);
    margin-bottom: 0.5rem;
}

.category-count {
    color: var(--text-muted);
    font-size: 0.9rem;
}

.articles-list {
    list-style: none;
    padding: 0;
}

.article-item {
    padding: 1rem;
    border-bottom: 1px solid var(--border-color);
    cursor: pointer;
    transition: all 0.2s ease;
}

.article-item:hover {
    background: var(--light-bg);
    padding-left: 1.5rem;
}

.article-item:last-child {
    border-bottom: none;
}

.article-title {
    font-weight: 600;
    color: var(--text-primary);
    margin-bottom: 0.25rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.article-title .icon {
    color: var(--primary-color);
}

.article-preview {
    font-size: 0.9rem;
    color: var(--text-muted);
    line-height: 1.5;
}

.article-content {
    max-width: 800px;
    margin: 0 auto;
}

.article-header {
    margin-bottom: 2rem;
    padding-bottom: 1rem;
    border-bottom: 2px solid var(--border-color);
}

.article-header h2 {
    font-size: 2rem;
    font-weight: 700;
    color: var(--text-primary);
    margin-bottom: 0.5rem;
}

.article-meta {
    color: var(--text-muted);
    font-size: 0.9rem;
}

.back-button {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 1rem;
    background: var(--light-bg);
    color: var(--text-secondary);
    text-decoration: none;
    border-radius: 0.5rem;
    margin-bottom: 1.5rem;
    transition: all 0.2s ease;
}

.back-button:hover {
    background: var(--border-color);
    color: var(--text-primary);
}

.quick-actions {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 1rem;
    margin: 2rem 0;
}

.quick-action-btn {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 1rem;
    background: var(--light-bg);
    border: 1px solid var(--border-color);
    border-radius: 0.5rem;
    color: var(--text-secondary);
    text-decoration: none;
    transition: all 0.2s ease;
}

.quick-action-btn:hover {
    background: var(--primary-color);
    color: white;
    border-color: var(--primary-color);
}

.system-info {
    background: var(--light-bg);
    border-radius: 0.75rem;
    padding: 1.5rem;
    margin: 2rem 0;
}

.info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 1.5rem;
}

.info-item {
    text-align: center;
}

.info-label {
    font-size: 0.9rem;
    color: var(--text-muted);
    margin-bottom: 0.5rem;
}

.info-value {
    font-size: 1.5rem;
    font-weight: 600;
    color: var(--text-primary);
}

.info-value.small {
    font-size: 1rem;
}

.faq-section {
    margin: 2rem 0;
}

.faq-item {
    border: 1px solid var(--border-color);
    border-radius: 0.5rem;
    margin-bottom: 1rem;
    overflow: hidden;
}

.faq-question {
    padding: 1rem 1.5rem;
    background: var(--light-bg);
    font-weight: 600;
    color: var(--text-primary);
    cursor: pointer;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.faq-question:hover {
    background: var(--border-color);
}

.faq-answer {
    padding: 1.5rem;
    background: white;
    color: var(--text-secondary);
    line-height: 1.6;
    display: none;
}

.faq-item.open .faq-answer {
    display: block;
}

.faq-toggle {
    font-size: 1.25rem;
    transition: transform 0.3s ease;
}

.faq-item.open .faq-toggle {
    transform: rotate(45deg);
}

.contact-support {
    background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
    color: white;
    padding: 2rem;
    border-radius: 1rem;
    margin-top: 2rem;
    text-align: center;
}

.contact-support h3 {
    font-size: 1.5rem;
    margin-bottom: 1rem;
}

.contact-support p {
    margin-bottom: 1.5rem;
    opacity: 0.95;
}

.support-btn {
    display: inline-block;
    padding: 1rem 2rem;
    background: white;
    color: var(--primary-color);
    text-decoration: none;
    border-radius: 0.5rem;
    font-weight: 600;
    transition: all 0.3s ease;
}

.support-btn:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-lg);
}

@media (max-width: 768px) {
    .help-grid {
        grid-template-columns: 1fr;
    }
    
    .help-sidebar {
        position: static;
    }
    
    .help-header h1 {
        font-size: 2rem;
    }
}
</style>

<div class="help-container">
    <!-- Header -->
    <div class="help-header">
        <h1>📚 Admin Help Center</h1>
        <p>Find documentation, guides, and answers to common questions about managing your job portal</p>
        
        <div class="search-box">
            <form method="GET" action="">
                <input type="text" name="search" placeholder="Search help articles..." 
                       value="<?php echo htmlspecialchars($searchQuery); ?>">
                <button type="submit">Search</button>
            </form>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="quick-actions">
        <a href="dashboard.php" class="quick-action-btn">
            <span>📊</span> Dashboard
        </a>
        <a href="users.php" class="quick-action-btn">
            <span>👥</span> Manage Users
        </a>
        <a href="jobs.php" class="quick-action-btn">
            <span>💼</span> Manage Jobs
        </a>
        <a href="settings.php" class="quick-action-btn">
            <span>⚙️</span> System Settings
        </a>
        <a href="reports.php" class="quick-action-btn">
            <span>📊</span> View Reports
        </a>
    </div>

    <!-- System Information -->
    <div class="system-info">
        <h3 style="margin-bottom: 1.5rem;">System Overview</h3>
        <div class="info-grid">
            <div class="info-item">
                <div class="info-label">Total Users</div>
                <div class="info-value"><?php echo number_format($systemInfo['total_users'] ?? 0); ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Total Jobs</div>
                <div class="info-value"><?php echo number_format($systemInfo['total_jobs'] ?? 0); ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Applications</div>
                <div class="info-value"><?php echo number_format($systemInfo['total_applications'] ?? 0); ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">PHP Version</div>
                <div class="info-value small"><?php echo htmlspecialchars($systemInfo['php_version'] ?? 'Unknown'); ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">MySQL Version</div>
                <div class="info-value small"><?php echo htmlspecialchars($systemInfo['mysql_version'] ?? 'Unknown'); ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Max Upload</div>
                <div class="info-value small"><?php echo htmlspecialchars($systemInfo['upload_max_size'] ?? 'Unknown'); ?></div>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="help-grid">
        <!-- Sidebar -->
        <div class="help-sidebar">
            <div class="sidebar-section">
                <h3>Categories</h3>
                <ul class="sidebar-nav">
                    <?php foreach ($helpTopics as $key => $topic): ?>
                    <li>
                        <a href="?category=<?php echo $key; ?>" 
                           class="<?php echo ($activeCategory === $key) ? 'active' : ''; ?>">
                            <span class="icon"><?php echo $topic['icon']; ?></span>
                            <?php echo htmlspecialchars($topic['title']); ?>
                            <span style="margin-left: auto; font-size: 0.8rem;">
                                <?php echo count($topic['articles']); ?>
                            </span>
                        </a>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            
            <div class="sidebar-section">
                <h3>Quick Links</h3>
                <ul class="sidebar-nav">
                    <li><a href="#faq"><span class="icon">❓</span> Frequently Asked Questions</a></li>
                    <li><a href="#contact"><span class="icon">📞</span> Contact Support</a></li>
                    <li><a href="#system"><span class="icon">ℹ️</span> System Status</a></li>
                </ul>
            </div>
        </div>

        <!-- Content Area -->
        <div class="help-content">
            <?php if (!empty($searchQuery)): ?>
                <div style="margin-bottom: 2rem;">
                    <h3>Search Results for "<?php echo htmlspecialchars($searchQuery); ?>"</h3>
                    <p>Found articles matching your search query.</p>
                </div>
            <?php endif; ?>

            <?php if ($showArticle): ?>
                <!-- Single Article View -->
                <?php 
                $article = $helpTopics[$currentTopic]['articles'][$currentArticle];
                $topic = $helpTopics[$currentTopic];
                ?>
                <a href="help-center.php?category=<?php echo $currentTopic; ?>" class="back-button">
                    <span>←</span> Back to <?php echo htmlspecialchars($topic['title']); ?>
                </a>
                
                <div class="article-content">
                    <div class="article-header">
                        <h2><?php echo htmlspecialchars($article['title']); ?></h2>
                        <div class="article-meta">
                            Category: <?php echo htmlspecialchars($topic['title']); ?>
                        </div>
                    </div>
                    
                    <div style="line-height: 1.8; color: var(--text-secondary);">
                        <p style="font-size: 1.1rem; margin-bottom: 2rem;">
                            <?php echo htmlspecialchars($article['content']); ?>
                        </p>
                        
                        <?php if (isset($article['steps'])): ?>
                            <h3 style="margin: 2rem 0 1rem;">Step-by-Step Guide:</h3>
                            <ol style="padding-left: 1.5rem;">
                                <?php foreach ($article['steps'] as $step): ?>
                                    <li style="margin-bottom: 0.75rem;"><?php echo htmlspecialchars($step); ?></li>
                                <?php endforeach; ?>
                            </ol>
                        <?php endif; ?>
                        
                        <?php if (isset($article['menu_items'])): ?>
                            <h3 style="margin: 2rem 0 1rem;">Navigation Items:</h3>
                            <div style="display: grid; gap: 1rem;">
                                <?php foreach ($article['menu_items'] as $item => $desc): ?>
                                    <div style="padding: 1rem; background: var(--light-bg); border-radius: 0.5rem;">
                                        <strong><?php echo htmlspecialchars($item); ?>:</strong>
                                        <span style="color: var(--text-muted); margin-left: 0.5rem;">
                                            <?php echo htmlspecialchars($desc); ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (isset($article['user_types'])): ?>
                            <h3 style="margin: 2rem 0 1rem;">User Types:</h3>
                            <div style="display: grid; gap: 1rem;">
                                <?php foreach ($article['user_types'] as $type => $desc): ?>
                                    <div style="padding: 1rem; background: var(--light-bg); border-radius: 0.5rem;">
                                        <strong><?php echo htmlspecialchars($type); ?>:</strong>
                                        <span style="color: var(--text-muted); margin-left: 0.5rem;">
                                            <?php echo htmlspecialchars($desc); ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (isset($article['actions'])): ?>
                            <h3 style="margin: 2rem 0 1rem;">Available Actions:</h3>
                            <ul style="padding-left: 1.5rem;">
                                <?php foreach ($article['actions'] as $action): ?>
                                    <li style="margin-bottom: 0.75rem;"><?php echo htmlspecialchars($action); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        
                        <?php if (isset($article['bulk_options'])): ?>
                            <h3 style="margin: 2rem 0 1rem;">Bulk Operations:</h3>
                            <ul style="padding-left: 1.5rem;">
                                <?php foreach ($article['bulk_options'] as $option): ?>
                                    <li style="margin-bottom: 0.75rem;"><?php echo htmlspecialchars($option); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        
                        <?php if (isset($article['moderation_actions'])): ?>
                            <h3 style="margin: 2rem 0 1rem;">Moderation Actions:</h3>
                            <ul style="padding-left: 1.5rem;">
                                <?php foreach ($article['moderation_actions'] as $action): ?>
                                    <li style="margin-bottom: 0.75rem;"><?php echo htmlspecialchars($action); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        
                        <?php if (isset($article['category_management'])): ?>
                            <h3 style="margin: 2rem 0 1rem;">Category Management:</h3>
                            <ul style="padding-left: 1.5rem;">
                                <?php foreach ($article['category_management'] as $action): ?>
                                    <li style="margin-bottom: 0.75rem;"><?php echo htmlspecialchars($action); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        
                        <?php if (isset($article['featured_options'])): ?>
                            <h3 style="margin: 2rem 0 1rem;">Featured Job Options:</h3>
                            <ul style="padding-left: 1.5rem;">
                                <?php foreach ($article['featured_options'] as $option): ?>
                                    <li style="margin-bottom: 0.75rem;"><?php echo htmlspecialchars($option); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        
                        <?php if (isset($article['monitoring_features'])): ?>
                            <h3 style="margin: 2rem 0 1rem;">Monitoring Features:</h3>
                            <ul style="padding-left: 1.5rem;">
                                <?php foreach ($article['monitoring_features'] as $feature): ?>
                                    <li style="margin-bottom: 0.75rem;"><?php echo htmlspecialchars($feature); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        
                        <?php if (isset($article['interview_features'])): ?>
                            <h3 style="margin: 2rem 0 1rem;">Interview Management Features:</h3>
                            <ul style="padding-left: 1.5rem;">
                                <?php foreach ($article['interview_features'] as $feature): ?>
                                    <li style="margin-bottom: 0.75rem;"><?php echo htmlspecialchars($feature); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        
                        <?php if (isset($article['report_types'])): ?>
                            <h3 style="margin: 2rem 0 1rem;">Available Report Types:</h3>
                            <ul style="padding-left: 1.5rem;">
                                <?php foreach ($article['report_types'] as $type): ?>
                                    <li style="margin-bottom: 0.75rem;"><?php echo htmlspecialchars($type); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        
                        <?php if (isset($article['export_formats'])): ?>
                            <h3 style="margin: 2rem 0 1rem;">Export Formats:</h3>
                            <ul style="padding-left: 1.5rem;">
                                <?php foreach ($article['export_formats'] as $format): ?>
                                    <li style="margin-bottom: 0.75rem;"><?php echo htmlspecialchars($format); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        
                        <?php if (isset($article['config_options'])): ?>
                            <h3 style="margin: 2rem 0 1rem;">Configuration Options:</h3>
                            <ul style="padding-left: 1.5rem;">
                                <?php foreach ($article['config_options'] as $option): ?>
                                    <li style="margin-bottom: 0.75rem;"><?php echo htmlspecialchars($option); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        
                        <?php if (isset($article['email_options'])): ?>
                            <h3 style="margin: 2rem 0 1rem;">Email Configuration Options:</h3>
                            <ul style="padding-left: 1.5rem;">
                                <?php foreach ($article['email_options'] as $option): ?>
                                    <li style="margin-bottom: 0.75rem;"><?php echo htmlspecialchars($option); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        
                        <?php if (isset($article['security_options'])): ?>
                            <h3 style="margin: 2rem 0 1rem;">Security Configuration:</h3>
                            <ul style="padding-left: 1.5rem;">
                                <?php foreach ($article['security_options'] as $option): ?>
                                    <li style="margin-bottom: 0.75rem;"><?php echo htmlspecialchars($option); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        
                        <?php if (isset($article['issues'])): ?>
                            <h3 style="margin: 2rem 0 1rem;">Common Issues and Solutions:</h3>
                            <div style="display: grid; gap: 1rem;">
                                <?php foreach ($article['issues'] as $issue => $solution): ?>
                                    <div style="padding: 1rem; background: var(--light-bg); border-radius: 0.5rem;">
                                        <strong style="color: var(--danger-color);"><?php echo htmlspecialchars($issue); ?>:</strong>
                                        <span style="color: var(--text-muted); margin-left: 0.5rem;">
                                            <?php echo htmlspecialchars($solution); ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (isset($article['errors'])): ?>
                            <h3 style="margin: 2rem 0 1rem;">Error Messages:</h3>
                            <div style="display: grid; gap: 1rem;">
                                <?php foreach ($article['errors'] as $error => $meaning): ?>
                                    <div style="padding: 1rem; background: var(--light-bg); border-radius: 0.5rem;">
                                        <strong style="color: var(--danger-color);"><?php echo htmlspecialchars($error); ?>:</strong>
                                        <span style="color: var(--text-muted); margin-left: 0.5rem;">
                                            <?php echo htmlspecialchars($meaning); ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (isset($article['debug_methods'])): ?>
                            <h3 style="margin: 2rem 0 1rem;">Debugging Methods:</h3>
                            <ul style="padding-left: 1.5rem;">
                                <?php foreach ($article['debug_methods'] as $method): ?>
                                    <li style="margin-bottom: 0.75rem;"><?php echo htmlspecialchars($method); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>

            <?php elseif ($activeCategory !== 'all' && isset($helpTopics[$activeCategory])): ?>
                <!-- Category View -->
                <h2 style="margin-bottom: 2rem;">
                    <?php echo $helpTopics[$activeCategory]['icon']; ?>
                    <?php echo htmlspecialchars($helpTopics[$activeCategory]['title']); ?>
                </h2>
                
                <div class="articles-list">
                    <?php foreach ($helpTopics[$activeCategory]['articles'] as $key => $article): ?>
                    <div class="article-item" onclick="window.location.href='?topic=<?php echo $activeCategory; ?>&article=<?php echo $key; ?>'">
                        <div class="article-title">
                            <span class="icon">📄</span>
                            <?php echo htmlspecialchars($article['title']); ?>
                        </div>
                        <div class="article-preview">
                            <?php echo htmlspecialchars(substr($article['content'], 0, 150)) . '...'; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

            <?php else: ?>
                <!-- All Categories View -->
                <div class="help-categories">
                    <?php foreach ($helpTopics as $key => $topic): ?>
                    <div class="category-card" onclick="window.location.href='?category=<?php echo $key; ?>'">
                        <div class="category-icon"><?php echo $topic['icon']; ?></div>
                        <div class="category-title"><?php echo htmlspecialchars($topic['title']); ?></div>
                        <div class="category-count"><?php echo count($topic['articles']); ?> articles</div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- FAQ Section -->
                <div id="faq" class="faq-section">
                    <h3 style="margin-bottom: 1.5rem;">Frequently Asked Questions</h3>
                    
                    <div class="faq-item">
                        <div class="faq-question" onclick="toggleFaq(this)">
                            <span>How do I reset a user's password?</span>
                            <span class="faq-toggle">+</span>
                        </div>
                        <div class="faq-answer">
                            To reset a user's password, go to Users section, find the user, click on "Edit" and select "Reset Password". 
                            The system will send a password reset link to the user's email address.
                        </div>
                    </div>
                    
                    <div class="faq-item">
                        <div class="faq-question" onclick="toggleFaq(this)">
                            <span>How can I generate system reports?</span>
                            <span class="faq-toggle">+</span>
                        </div>
                        <div class="faq-answer">
                            Navigate to the Reports section from the sidebar. You can generate various reports including user statistics, 
                            job posting analytics, and application trends. Select the report type, set your date range, and click "Generate".
                        </div>
                    </div>
                    
                    <div class="faq-item">
                        <div class="faq-question" onclick="toggleFaq(this)">
                            <span>What should I do if emails are not being sent?</span>
                            <span class="faq-toggle">+</span>
                        </div>
                        <div class="faq-answer">
                            First, check the Email Queue section to see if emails are stuck. Then verify your SMTP settings in Settings → Email Configuration. 
                            You can also test email delivery using the "Test Email" feature. Check server logs for any connection errors.
                        </div>
                    </div>
                    
                    <div class="faq-item">
                        <div class="faq-question" onclick="toggleFaq(this)">
                            <span>How do I manage job categories?</span>
                            <span class="faq-toggle">+</span>
                        </div>
                        <div class="faq-answer">
                            Go to Categories section in the admin panel. Here you can add, edit, or delete job categories. 
                            You can also set display order and activate/deactivate categories as needed. Changes will reflect immediately on the job posting forms.
                        </div>
                    </div>
                    
                    <div class="faq-item">
                        <div class="faq-question" onclick="toggleFaq(this)">
                            <span>How can I backup the system?</span>
                            <span class="faq-toggle">+</span>
                        </div>
                        <div class="faq-answer">
                            The system automatically performs daily backups. You can also manually create backups from Settings → Backup & Restore. 
                            Backups include database dumps and uploaded files. Store backups in a secure off-site location for disaster recovery.
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Contact Support -->
            <div id="contact" class="contact-support">
                <h3>Need More Help?</h3>
                <p>Our support team is available 24/7 to assist you with any questions or issues.</p>
                <a href="mailto:support@jobportal.com" class="support-btn">Contact Support</a>
            </div>
        </div>
    </div>
</div>

<script>
function toggleFaq(element) {
    const faqItem = element.closest('.faq-item');
    faqItem.classList.toggle('open');
    
    // Update toggle icon
    const toggle = element.querySelector('.faq-toggle');
    if (faqItem.classList.contains('open')) {
        toggle.textContent = '−';
    } else {
        toggle.textContent = '+';
    }
}

// Smooth scroll for anchor links
document.querySelectorAll('a[href^="#"]').forEach(anchor => {
    anchor.addEventListener('click', function (e) {
        e.preventDefault();
        const target = document.querySelector(this.getAttribute('href'));
        if (target) {
            target.scrollIntoView({
                behavior: 'smooth',
                block: 'start'
            });
        }
    });
});

// Initialize FAQ items if any are open by default
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.faq-item.open').forEach(item => {
        const toggle = item.querySelector('.faq-toggle');
        if (toggle) toggle.textContent = '−';
    });
});
</script>

<?php
// Include footer
include '../includes/footer.php';
?>