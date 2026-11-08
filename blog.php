<?php
/**
 * Blog & Career Advice System - Complete All-in-One Solution
 * Version: 3.1 - Fixed & Optimized
 * 
 * @author TransNow
 * @version 3.1
 */

// =============================================================================
// Configuration & Error Handling
// =============================================================================
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/functions.php';

$pdo = getDB();

// =============================================================================
// FIX: Add missing is_featured column if it doesn't exist
// =============================================================================
try {
    // Check if is_featured column exists
    $checkColumn = $pdo->query("SHOW COLUMNS FROM blog_posts LIKE 'is_featured'");
    if ($checkColumn->rowCount() == 0) {
        // Add the missing column
        $pdo->exec("ALTER TABLE blog_posts ADD COLUMN is_featured TINYINT(1) DEFAULT 0 AFTER is_published");
        $pdo->exec("ALTER TABLE blog_posts ADD INDEX idx_featured (is_featured)");
        error_log("Added missing is_featured column to blog_posts table");
    }
} catch (PDOException $e) {
    error_log("Column check failed: " . $e->getMessage());
}

// =============================================================================
// Create All Required Tables (if not exist)
// =============================================================================

// Blog Posts Table with is_featured included
$pdo->exec("
CREATE TABLE IF NOT EXISTS `blog_posts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `excerpt` text,
  `content` longtext NOT NULL,
  `featured_image` varchar(500) DEFAULT NULL,
  `author_name` varchar(100) DEFAULT 'TransNow Team',
  `author_title` varchar(100) DEFAULT NULL,
  `author_image` varchar(255) DEFAULT NULL,
  `author_bio` text,
  `category` enum('Career Advice','Resume Tips','Interview Prep','Workplace Skills','Industry Insights','Job Search','News') DEFAULT 'Career Advice',
  `tags` varchar(255) DEFAULT NULL,
  `is_published` tinyint(1) DEFAULT 1,
  `is_featured` tinyint(1) DEFAULT 0,
  `views` int(11) DEFAULT 0,
  `read_time` int(3) DEFAULT 5,
  `meta_description` varchar(300) DEFAULT NULL,
  `meta_keywords` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `idx_published` (`is_published`),
  KEY `idx_category` (`category`),
  KEY `idx_featured` (`is_featured`),
  FULLTEXT KEY `idx_search` (`title`, `content`, `excerpt`, `tags`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

// Rest of your table creation remains the same...
$pdo->exec("
CREATE TABLE IF NOT EXISTS `blog_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `slug` varchar(100) NOT NULL,
  `description` text,
  `icon` varchar(50) DEFAULT 'fa-folder',
  `color` varchar(20) DEFAULT '#2563EB',
  `post_count` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

$pdo->exec("
CREATE TABLE IF NOT EXISTS `blog_comments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `post_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `author_name` varchar(100) NOT NULL,
  `author_email` varchar(100) NOT NULL,
  `author_website` varchar(255) DEFAULT NULL,
  `content` text NOT NULL,
  `is_approved` tinyint(1) DEFAULT 0,
  `parent_id` int(11) DEFAULT NULL,
  `likes` int(11) DEFAULT 0,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `post_id` (`post_id`),
  KEY `is_approved` (`is_approved`),
  KEY `parent_id` (`parent_id`),
  CONSTRAINT `blog_comments_ibfk_1` FOREIGN KEY (`post_id`) REFERENCES `blog_posts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

$pdo->exec("
CREATE TABLE IF NOT EXISTS `newsletter_subscribers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(100) NOT NULL,
  `name` varchar(100) DEFAULT NULL,
  `is_verified` tinyint(1) DEFAULT 0,
  `verification_token` varchar(64) DEFAULT NULL,
  `preferences` text,
  `ip_address` varchar(45) DEFAULT NULL,
  `subscribed_at` timestamp NULL DEFAULT current_timestamp(),
  `unsubscribed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

$pdo->exec("
CREATE TABLE IF NOT EXISTS `blog_likes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `post_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `ip_address` varchar(45) NOT NULL,
  `session_id` varchar(64) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_like` (`post_id`, `ip_address`, `session_id`),
  CONSTRAINT `blog_likes_ibfk_1` FOREIGN KEY (`post_id`) REFERENCES `blog_posts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

$pdo->exec("
CREATE TABLE IF NOT EXISTS `blog_tags` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `slug` varchar(50) NOT NULL,
  `post_count` int(11) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

// =============================================================================
// Insert Default Categories
// =============================================================================
$defaultCategories = [
    ['name' => 'Career Advice', 'slug' => 'career-advice', 'icon' => 'fa-briefcase', 'color' => '#2563EB'],
    ['name' => 'Resume Tips', 'slug' => 'resume-tips', 'icon' => 'fa-file-alt', 'color' => '#10B981'],
    ['name' => 'Interview Prep', 'slug' => 'interview-prep', 'icon' => 'fa-users', 'color' => '#F59E0B'],
    ['name' => 'Workplace Skills', 'slug' => 'workplace-skills', 'icon' => 'fa-chart-line', 'color' => '#8B5CF6'],
    ['name' => 'Industry Insights', 'slug' => 'industry-insights', 'icon' => 'fa-newspaper', 'color' => '#EF4444'],
    ['name' => 'Job Search', 'slug' => 'job-search', 'icon' => 'fa-search', 'color' => '#06B6D4'],
    ['name' => 'News', 'slug' => 'news', 'icon' => 'fa-bullhorn', 'color' => '#EC4899']
];

$stmt = $pdo->prepare("INSERT IGNORE INTO blog_categories (name, slug, icon, color) VALUES (?, ?, ?, ?)");
foreach ($defaultCategories as $cat) {
    $stmt->execute([$cat['name'], $cat['slug'], $cat['icon'], $cat['color']]);
}

// =============================================================================
// Insert Sample Blog Posts with Rich Content (Only if no posts exist)
// =============================================================================
$stmt = $pdo->query("SELECT COUNT(*) FROM blog_posts");
if ($stmt->fetchColumn() == 0) {
    $samplePosts = [
        [
            'title' => '5 Essential Communication Skills Every Ghanaian Job Seeker Must Master',
            'slug' => 'communication-skills-ghanaian-job-seeker',
            'excerpt' => 'Discover the key communication skills that employers in Ghana are looking for and how to develop them for career success.',
            'content' => '<h2>Master Communication for Career Success in Ghana</h2>
            
            <p>In Ghana\'s competitive job market, technical qualifications alone are no longer enough. Employers are increasingly prioritizing soft skills, with communication topping the list. Whether you\'re applying for a translator position at a Chinese firm or a sales role in Accra, how you communicate can make or break your chances.</p>
            
            <div class="bg-blue-50 dark:bg-blue-900/20 p-6 rounded-xl my-8 border-l-4 border-blue-500">
                <h3 class="text-xl font-bold mb-3">Key Takeaway</h3>
                <p>Effective communication in Ghana\'s multicultural workplace requires active listening, clarity, cultural awareness, and professional writing skills.</p>
            </div>
            
            <h3>1. Active Listening</h3>
            <p>Active listening means fully concentrating on what is being said rather than just passively hearing the message. In interviews, this translates to understanding questions fully before answering. On the job, it prevents costly misunderstandings, especially when dealing with international colleagues or clients.</p>
            
            <h3>2. Clarity and Conciseness</h3>
            <p>Get to the point. Whether writing a cover letter or speaking in a meeting, Ghanaian employers value professionals who can express ideas clearly without unnecessary jargon. This is particularly crucial for roles like Office Clerks and Administrative Assistants.</p>
            
            <h3>3. Professional Writing</h3>
            <p>Your emails and reports represent your personal brand. Always proofread. Use proper grammar. If you\'re applying via email, write a formal message in the body—don\'t just attach a blank CV. Many job postings on TransNow are managed by Chinese business owners; clear, simple English is often preferred over complex vocabulary.</p>
            
            <h3>4. Non-Verbal Communication</h3>
            <p>In Ghanaian culture, respect is paramount. Maintain appropriate eye contact, sit upright, and avoid crossing your arms defensively during interviews. A firm handshake and a genuine smile go a long way in establishing rapport.</p>
            
            <h3>5. Adaptability in Multilingual Settings</h3>
            <p>Ghana is a multilingual hub. While English is the official business language, the ability to switch between Twi, Ga, or Hausa with colleagues shows cultural fluency. Many employers value candidates who can bridge the gap between local staff and expatriate management.</p>
            
            <div class="bg-gray-100 dark:bg-gray-800 p-6 rounded-xl my-8">
                <h4 class="font-bold mb-3">Quick Tip</h4>
                <p>Practice active listening by summarizing what the speaker said before responding: "So what I\'m hearing is..." This simple technique shows respect and ensures understanding.</p>
            </div>',
            'featured_image' => 'https://images.pexels.com/photos/3184292/pexels-photo-3184292.jpeg?auto=compress&cs=tinysrgb&w=1200',
            'category' => 'Workplace Skills',
            'tags' => 'communication, soft skills, career advice, professional development',
            'is_featured' => 1,
            'read_time' => 6,
            'meta_description' => 'Master essential communication skills for career success in Ghana. Learn active listening, professional writing, and cross-cultural communication tips.',
            'meta_keywords' => 'communication skills, Ghana jobs, career advice, professional development'
        ],
        [
            'title' => 'The Ultimate Resume Guide for Ghanaian Youth (2026 Standards)',
            'slug' => 'resume-guide-ghana-youth-2026',
            'excerpt' => 'Learn how to craft a CV that stands out to employers in Ghana. Includes free templates and local tips.',
            'content' => '<h2>Create a Winning CV for the Ghanaian Job Market</h2>
            
            <p>Your CV is often the first impression you make on a potential employer. In Ghana\'s dynamic job market—especially in sectors like Sales, Translation, and Administration—your resume needs to be both professional and locally relevant. Here\'s how to write one that gets you calls for interviews.</p>
            
            <h3>1. Personal Details (Keep it Simple)</h3>
            <p>Include your full name, a professional email address (e.g., firstname.lastname@gmail.com), and an active phone number (preferably with WhatsApp). You do NOT need to include your date of birth, marital status, or religion unless specifically requested. A LinkedIn profile link is a plus.</p>
            
            <h3>2. Professional Summary</h3>
            <p>Replace the outdated "Career Objective" with a 3-4 line professional summary.</p>
            <div class="bg-red-50 dark:bg-red-900/20 p-4 rounded-lg my-4">
                <strong>❌ Bad:</strong> "Seeking a challenging position to utilize my skills."
            </div>
            <div class="bg-green-50 dark:bg-green-900/20 p-4 rounded-lg my-4">
                <strong>✅ Good:</strong> "Bilingual (English & Chinese) Administrative Professional with 2+ years of experience in office management and translation support for international firms in Tema. Proven ability to manage schedules, coordinate logistics, and facilitate cross-cultural communication."
            </div>
            
            <h3>3. Work Experience (Reverse Chronological)</h3>
            <p>List your most recent job first. Use bullet points that focus on <strong>achievements</strong>, not just duties.</p>
            
            <h3>4. Education & Certifications</h3>
            <p>List your highest degree first. If you have taken short courses—e.g., Excel, Digital Marketing, or Chinese Language at Confucius Institute—list them here. Employers value candidates who upskill.</p>
            
            <h3>5. Key Skills Section</h3>
            <p>Tailor this to the job. For Sales: "Customer Relationship Management, Market Research, Negotiation." For Translation: "Fluent Mandarin, Document Translation, Consecutive Interpretation." Always list <strong>Microsoft Office Suite</strong> proficiency; it\'s a baseline expectation for most office jobs in Ghana.</p>',
            'featured_image' => 'https://images.pexels.com/photos/5989927/pexels-photo-5989927.jpeg?auto=compress&cs=tinysrgb&w=1200',
            'category' => 'Resume Tips',
            'tags' => 'CV, resume, job application, Ghana, career tips',
            'is_featured' => 1,
            'read_time' => 8,
            'meta_description' => 'Complete guide to writing a professional CV for Ghanaian job seekers. Includes templates, examples, and local tips for 2026.',
            'meta_keywords' => 'CV writing, resume tips, Ghana jobs, job application'
        ],
        [
            'title' => 'Cooperation and Teamwork: The Secret to Thriving in Ghanaian-Chinese Workplaces',
            'slug' => 'cooperation-teamwork-ghana-china-workplaces',
            'excerpt' => 'Understanding cultural expectations and building strong working relationships with Chinese employers in Ghana.',
            'content' => '<h2>Building Successful Working Relationships Across Cultures</h2>
            
            <p>Many of the jobs listed on TransNow involve working for Chinese-owned enterprises in Ghana. While the technical skills required are similar to any other company, the cultural emphasis on cooperation and hierarchy is often more pronounced. Mastering this can lead to rapid promotion and job security.</p>
            
            <h3>Understanding "Guanxi" (Relationships)</h3>
            <p>In Chinese business culture, trust and long-term relationships are paramount. Being a reliable, cooperative team player is often valued more than individual brilliance. This means helping colleagues when asked, being punctual (extremely important), and maintaining a positive, can-do attitude.</p>
            
            <h3>Respect for Hierarchy</h3>
            <p>Always address supervisors and managers with appropriate titles (e.g., Mr. Li, Manager Chen). While the work environment might be informal, showing respect for seniority is crucial. Wait for the manager to finish speaking before responding in meetings.</p>
            
            <h3>Communication Nuances</h3>
            <p>Chinese managers may not always give direct negative feedback. "I\'ll think about it" or silence might mean "No." It is your responsibility to be proactive: clarify instructions, summarize tasks back to them to ensure alignment, and report problems early—not just when it\'s too late to fix them.</p>',
            'featured_image' => 'https://images.pexels.com/photos/3184418/pexels-photo-3184418.jpeg?auto=compress&cs=tinysrgb&w=1200',
            'category' => 'Workplace Skills',
            'tags' => 'cooperation, Chinese culture, teamwork, Ghana, cross-cultural',
            'is_featured' => 0,
            'read_time' => 7,
            'meta_description' => 'Learn how to build strong working relationships in Ghanaian-Chinese workplaces. Cultural tips for cooperation and career success.',
            'meta_keywords' => 'cross-cultural workplace, Chinese business culture, Ghana jobs, teamwork'
        ],
        [
            'title' => 'Ace Your Next Interview: Complete Preparation Guide',
            'slug' => 'interview-preparation-guide-ghana',
            'excerpt' => 'From research to follow-up, master every stage of the interview process with our comprehensive guide.',
            'content' => '<h2>Master the Art of Interviewing in Ghana</h2>
            
            <p>Getting an interview is a significant achievement. Now it\'s time to seal the deal. Here\'s how to prepare for interviews at Ghanaian companies, including those with international ownership.</p>
            
            <h3>1. Research the Company Deeply</h3>
            <p>Don\'t just skim the website. Check their social media, recent news, and understand their market position. If it\'s a Chinese firm, understanding their parent company and main business lines in Ghana is impressive.</p>
            
            <h3>2. Prepare Your STAR Stories</h3>
            <p>Behavioral questions are standard. Prepare 3-5 stories using the STAR method (Situation, Task, Action, Result). Examples: A time you solved a problem, worked in a team, or showed leadership.</p>
            
            <h3>3. Common Interview Questions in Ghana</h3>
            <ul>
                <li>Tell me about yourself.</li>
                <li>Why do you want to work here?</li>
                <li>What are your salary expectations?</li>
                <li>Where do you see yourself in 5 years?</li>
                <li>Why should we hire you?</li>
            </ul>
            
            <h3>4. Questions to Ask the Interviewer</h3>
            <ul>
                <li>What does a typical day look like in this role?</li>
                <li>What are the biggest challenges facing the team/department?</li>
                <li>What opportunities for growth exist within the company?</li>
            </ul>',
            'featured_image' => 'https://images.pexels.com/photos/5439381/pexels-photo-5439381.jpeg?auto=compress&cs=tinysrgb&w=1200',
            'category' => 'Interview Prep',
            'tags' => 'interview, preparation, career, job search',
            'is_featured' => 0,
            'read_time' => 5,
            'meta_description' => 'Comprehensive interview preparation guide for Ghanaian job seekers. Common questions, STAR method, and follow-up tips.',
            'meta_keywords' => 'interview tips, job interview, Ghana jobs, career advice'
        ],
        [
            'title' => 'Top 10 Highest Paying Jobs in Ghana (2026 Salary Guide)',
            'slug' => 'highest-paying-jobs-ghana-2026',
            'excerpt' => 'Discover the most lucrative career paths in Ghana with updated salary ranges and qualification requirements.',
            'content' => '<h2>Highest Paying Careers in Ghana Today</h2>
            
            <p>Understanding salary expectations is crucial for career planning. Here\'s an updated look at the highest paying jobs in Ghana for 2026, based on data from TransNow and industry sources.</p>
            
            <h3>1. Petroleum Engineers</h3>
            <p><strong>Salary Range:</strong> GHS 8,000 - 25,000+ monthly<br>
            <strong>Key Employers:</strong> Tullow Oil, Kosmos Energy, GNPC</p>
            
            <h3>2. IT Managers & Software Architects</h3>
            <p><strong>Salary Range:</strong> GHS 7,000 - 20,000 monthly<br>
            <strong>Key Skills:</strong> Cloud computing, cybersecurity, software development</p>
            
            <h3>3. Financial Managers & Accountants (ACCA/CPA)</h3>
            <p><strong>Salary Range:</strong> GHS 6,000 - 18,000 monthly<br>
            <strong>Key Employers:</strong> Banks, multinational corporations</p>
            
            <h3>4. Medical Doctors & Specialists</h3>
            <p><strong>Salary Range:</strong> GHS 5,000 - 15,000+ monthly<br>
            <strong>Note:</strong> Private practice can significantly increase earnings</p>
            
            <h3>5. Bilingual Professionals (Chinese/English)</h3>
            <p><strong>Salary Range:</strong> GHS 4,000 - 12,000 monthly<br>
            <strong>Demand:</strong> Extremely high due to Chinese investment in Ghana</p>',
            'featured_image' => 'https://images.pexels.com/photos/6694543/pexels-photo-6694543.jpeg?auto=compress&cs=tinysrgb&w=1200',
            'category' => 'Industry Insights',
            'tags' => 'salary guide, high paying jobs, Ghana careers, compensation',
            'is_featured' => 0,
            'read_time' => 9,
            'meta_description' => 'Complete salary guide for highest paying jobs in Ghana 2026. Updated salary ranges for petroleum, IT, finance, and medical careers.',
            'meta_keywords' => 'salary guide Ghana, high paying jobs, career advice, compensation'
        ],
        [
            'title' => 'Remote Work Opportunities: How to Find and Succeed in Online Jobs',
            'slug' => 'remote-work-opportunities-ghana',
            'excerpt' => 'The ultimate guide to finding legitimate remote jobs from Ghana and thriving in a virtual work environment.',
            'content' => '<h2>Work From Home: The New Frontier for Ghanaian Professionals</h2>
            
            <p>Remote work is no longer just a trend—it\'s a permanent shift in how business operates. For Ghanaian professionals, this opens doors to international opportunities without leaving the country.</p>
            
            <h3>Where to Find Legitimate Remote Jobs</h3>
            <ul>
                <li><strong>TransNow Remote:</strong> Filter for "Remote" or "Hybrid" positions</li>
                <li><strong>International Platforms:</strong> Upwork, Toptal, Turing</li>
                <li><strong>Company Career Pages:</strong> Many global companies hire remotely in Africa</li>
            </ul>
            
            <h3>Essential Skills for Remote Work Success</h3>
            <ul>
                <li>Self-discipline and time management</li>
                <li>Excellent written communication</li>
                <li>Proficiency with remote tools (Slack, Zoom, Trello)</li>
                <li>Reliable internet connection and backup plan</li>
            </ul>
            
            <h3>Avoiding Remote Job Scams</h3>
            <p>Be wary of jobs that require upfront payment, promise unrealistic salaries, or have vague job descriptions. Legitimate employers never ask for money.</p>',
            'featured_image' => 'https://images.pexels.com/photos/4050291/pexels-photo-4050291.jpeg?auto=compress&cs=tinysrgb&w=1200',
            'category' => 'Job Search',
            'tags' => 'remote work, work from home, online jobs, Ghana',
            'is_featured' => 0,
            'read_time' => 6,
            'meta_description' => 'Complete guide to finding legitimate remote jobs from Ghana. Tips for succeeding in virtual work environments and avoiding scams.',
            'meta_keywords' => 'remote jobs Ghana, work from home, online jobs, virtual work'
        ]
    ];
    
    $insertSQL = "INSERT INTO blog_posts (title, slug, excerpt, content, featured_image, category, tags, is_published, is_featured, read_time, meta_description, meta_keywords) 
                  VALUES (:title, :slug, :excerpt, :content, :featured_image, :category, :tags, 1, :is_featured, :read_time, :meta_description, :meta_keywords)";
    $stmt = $pdo->prepare($insertSQL);
    
    foreach ($samplePosts as $post) {
        try {
            $stmt->execute($post);
        } catch (Exception $e) {
            error_log("Sample post insert: " . $e->getMessage());
        }
    }
}

// =============================================================================
// Handle POST Requests
// =============================================================================

// Newsletter Subscription
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['newsletter_email'])) {
    $email = filter_var($_POST['newsletter_email'], FILTER_SANITIZE_EMAIL);
    $name = isset($_POST['newsletter_name']) ? htmlspecialchars($_POST['newsletter_name']) : '';
    $ip = $_SERVER['REMOTE_ADDR'];
    
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        try {
            $token = bin2hex(random_bytes(32));
            $stmt = $pdo->prepare("INSERT INTO newsletter_subscribers (email, name, verification_token, ip_address) VALUES (?, ?, ?, ?) 
                                   ON DUPLICATE KEY UPDATE subscribed_at = CURRENT_TIMESTAMP, unsubscribed_at = NULL");
            $stmt->execute([$email, $name, $token, $ip]);
            $_SESSION['newsletter_success'] = 'Thank you for subscribing! Please check your email to confirm.';
        } catch (Exception $e) {
            $_SESSION['newsletter_error'] = 'Subscription failed. Please try again.';
        }
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// =============================================================================
// Handle GET Parameters & Filters
// =============================================================================

// Search functionality
$searchTerm = isset($_GET['search']) && !empty($_GET['search']) ? '%' . $_GET['search'] . '%' : null;

// Category filter
$categoryFilter = isset($_GET['category']) ? $_GET['category'] : null;

// Tag filter  
$tagFilter = isset($_GET['tag']) ? $_GET['tag'] : null;

// Sort option
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'newest';

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 9;
$offset = ($page - 1) * $perPage;

// =============================================================================
// Build Dynamic Query
// =============================================================================

// Base query parts
$whereConditions = ["is_published = 1"];
$params = [];

// Add search condition
if ($searchTerm) {
    $whereConditions[] = "(title LIKE ? OR content LIKE ? OR excerpt LIKE ? OR tags LIKE ?)";
    $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
}

// Add category condition
if ($categoryFilter) {
    $whereConditions[] = "category = ?";
    $params[] = $categoryFilter;
}

// Add tag condition
if ($tagFilter) {
    $whereConditions[] = "FIND_IN_SET(?, REPLACE(tags, ', ', ','))";
    $params[] = $tagFilter;
}

$whereClause = implode(" AND ", $whereConditions);

// Build ORDER BY clause
switch ($sort) {
    case 'popular':
        $orderBy = "views DESC, created_at DESC";
        break;
    case 'oldest':
        $orderBy = "created_at ASC";
        break;
    case 'newest':
    default:
        $orderBy = "is_featured DESC, created_at DESC";
        break;
}

// Get total count for pagination
$countSql = "SELECT COUNT(*) FROM blog_posts WHERE $whereClause";
$stmt = $pdo->prepare($countSql);
$stmt->execute($params);
$totalPosts = $stmt->fetchColumn();
$totalPages = ceil($totalPosts / $perPage);

// Get posts for current page
$sql = "SELECT id, title, slug, excerpt, featured_image, category, tags, author_name, author_title, created_at, read_time, views, is_featured 
        FROM blog_posts 
        WHERE $whereClause 
        ORDER BY $orderBy 
        LIMIT $perPage OFFSET $offset";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$blogPosts = $stmt->fetchAll();

// Get featured post (only if not filtering)
$featuredPost = null;
if (!$categoryFilter && !$tagFilter && !$searchTerm && $page == 1) {
    $stmt = $pdo->query("
        SELECT id, title, slug, excerpt, featured_image, category, author_name, author_title, created_at, read_time, is_featured
        FROM blog_posts 
        WHERE is_published = 1 AND is_featured = 1 
        ORDER BY created_at DESC 
        LIMIT 1
    ");
    $featuredPost = $stmt->fetch();
}

// Get all categories with post counts
$categories = $pdo->query("
    SELECT category as name, COUNT(*) as count 
    FROM blog_posts 
    WHERE is_published = 1 
    GROUP BY category 
    ORDER BY count DESC
")->fetchAll();

// Get popular tags (optimized)
$tags = $pdo->query("
    SELECT DISTINCT TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(tags, ',', n.n), ',', -1)) as tag_name,
           COUNT(*) as count
    FROM blog_posts 
    CROSS JOIN (
        SELECT 1 as n UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5
    ) n
    WHERE is_published = 1 
    AND tags IS NOT NULL
    AND CHAR_LENGTH(tags) - CHAR_LENGTH(REPLACE(tags, ',', '')) >= n.n - 1
    GROUP BY tag_name
    HAVING tag_name != ''
    ORDER BY count DESC
    LIMIT 15
")->fetchAll();

// Get trending posts (most viewed in last 30 days)
$trendingPosts = $pdo->query("
    SELECT id, title, slug, featured_image, views, created_at
    FROM blog_posts 
    WHERE is_published = 1 
    AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
    ORDER BY views DESC, created_at DESC
    LIMIT 5
")->fetchAll();

// Helper functions
function formatDate($date) {
    return date('M d, Y', strtotime($date));
}

function getReadingTime($minutes) {
    if ($minutes < 1) return 'Less than 1 min read';
    if ($minutes == 1) return '1 min read';
    return $minutes . ' min read';
}

// Page title
$pageTitle = 'Career Advice & Blog';
if ($categoryFilter) {
    $pageTitle = htmlspecialchars($categoryFilter) . ' Articles';
} elseif ($tagFilter) {
    $pageTitle = 'Articles tagged: ' . htmlspecialchars($tagFilter);
} elseif (isset($_GET['search']) && !empty($_GET['search'])) {
    $pageTitle = 'Search Results: ' . htmlspecialchars($_GET['search']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> - TransNow Ghana | Career Resources</title>
    <meta name="description" content="Expert career advice, resume writing tips, interview preparation, and professional development guides for Ghanaian job seekers.">
    <meta name="keywords" content="career advice Ghana, resume tips, interview preparation, job search Ghana, professional development">
    
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <!-- AOS Animation -->
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    
    <!-- Custom Styles -->
    <style>
        * { font-family: 'Inter', sans-serif; }
        
        .brand-gradient {
            background: linear-gradient(135deg, #1E3A8A 0%, #2563EB 50%, #3B82F6 100%);
        }
        
        .gradient-text {
            background: linear-gradient(135deg, #1E3A8A, #2563EB, #3B82F6);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }
        
        .blog-card {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border: 1px solid #E5E7EB;
        }
        
        .blog-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            border-color: #3B82F6;
        }
        
        .category-badge {
            background: linear-gradient(135deg, #1E3A8A 0%, #2563EB 100%);
            color: white;
            font-size: 0.75rem;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #1E3A8A 0%, #2563EB 100%);
            color: white;
            font-weight: 600;
            padding: 0.75rem 1.5rem;
            border-radius: 0.75rem;
            transition: all 0.3s;
            box-shadow: 0 4px 6px -1px rgba(30, 58, 138, 0.2);
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(30, 58, 138, 0.3);
            background: linear-gradient(135deg, #1E3A8A 0%, #1D4ED8 100%);
        }
        
        .btn-accent {
            background: #10B981;
            color: white;
            font-weight: 600;
            padding: 0.75rem 1.5rem;
            border-radius: 0.75rem;
            transition: all 0.3s;
            box-shadow: 0 4px 6px -1px rgba(16, 185, 129, 0.2);
        }
        
        .btn-accent:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(16, 185, 129, 0.3);
            background: #059669;
        }
        
        .line-clamp-3 {
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        
        .line-clamp-2 {
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        
        ::-webkit-scrollbar { width: 10px; }
        ::-webkit-scrollbar-track { background: #F3F4F6; border-radius: 8px; }
        ::-webkit-scrollbar-thumb { background: linear-gradient(135deg, #1E3A8A, #3B82F6); border-radius: 8px; }
        
        @media (max-width: 768px) {
            .line-clamp-3 { -webkit-line-clamp: 2; }
        }
    </style>
</head>
<body class="bg-[#F3F4F6] dark:bg-gray-900 transition-colors duration-300">

<!-- Navigation -->
<nav class="fixed w-full z-50 bg-white/95 dark:bg-gray-900/95 backdrop-blur-md shadow-sm">
    <div class="container mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between items-center py-4">
            <a href="/" class="flex items-center space-x-3 group">
                <div class="w-10 h-10 brand-gradient rounded-xl flex items-center justify-center transform group-hover:rotate-12 transition-all duration-300">
                    <i class="fas fa-briefcase text-white text-xl"></i>
                </div>
                <span class="text-2xl font-bold gradient-text">TransNow Ghana</span>
            </a>
            
            <div class="hidden md:flex items-center space-x-8">
                <a href="/" class="nav-link text-[#111827] dark:text-gray-200 font-medium hover:text-[#2563EB] transition">Home</a>
                <a href="/jobs.php" class="nav-link text-[#111827] dark:text-gray-200 font-medium hover:text-[#2563EB] transition">Find Jobs</a>
                <a href="/blog.php" class="nav-link text-[#2563EB] dark:text-[#60A5FA] font-medium border-b-2 border-[#2563EB]">Career Advice</a>
                <a href="/testimonials.php" class="nav-link text-[#111827] dark:text-gray-200 font-medium hover:text-[#2563EB] transition">Success Stories</a>
                <a href="/about.php" class="nav-link text-[#111827] dark:text-gray-200 font-medium hover:text-[#2563EB] transition">About</a>
            </div>
            
            <div class="flex items-center space-x-4">
                <a href="/login.php" class="hidden sm:inline-flex items-center space-x-2 px-5 py-2.5 border border-[#2563EB] text-[#2563EB] rounded-xl font-medium hover:bg-[#2563EB] hover:text-white transition">
                    <i class="fas fa-user-shield"></i>
                    <span>Admin</span>
                </a>
                <button id="mobileMenuBtn" class="md:hidden p-2 rounded-lg bg-gray-100 dark:bg-gray-800">
                    <i class="fas fa-bars text-gray-600 dark:text-gray-300 text-xl"></i>
                </button>
            </div>
        </div>
        
        <div id="mobileMenu" class="md:hidden hidden pb-4 space-y-3">
            <a href="/" class="block py-2 px-4 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800">Home</a>
            <a href="/jobs.php" class="block py-2 px-4 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800">Find Jobs</a>
            <a href="/blog.php" class="block py-2 px-4 rounded-lg bg-blue-50 dark:bg-blue-900/30 text-[#2563EB]">Career Advice</a>
            <a href="/testimonials.php" class="block py-2 px-4 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800">Success Stories</a>
            <a href="/about.php" class="block py-2 px-4 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800">About</a>
        </div>
    </div>
</nav>

<!-- Page Header -->
<section class="pt-32 pb-16 brand-gradient">
    <div class="container mx-auto px-4">
        <div class="max-w-4xl mx-auto text-center" data-aos="fade-up">
            <div class="inline-flex items-center space-x-2 bg-white/20 backdrop-blur-sm rounded-full px-5 py-2.5 mb-6">
                <i class="fas fa-newspaper text-white"></i>
                <span class="text-sm font-semibold text-white">🇬🇭 <?php echo number_format($totalPosts); ?>+ Career Resources</span>
            </div>
            <h1 class="text-4xl md:text-5xl lg:text-6xl font-extrabold text-white mb-6 leading-tight">
                <?php echo $pageTitle; ?>
            </h1>
            <p class="text-xl text-white/90 max-w-2xl mx-auto">
                Expert guides on resumes, interviews, workplace skills, and job search strategies tailored for Ghana.
            </p>
            
            <!-- Search Bar -->
            <div class="max-w-2xl mx-auto mt-8">
                <form action="" method="GET" class="flex flex-col sm:flex-row gap-3">
                    <div class="flex-1 relative">
                        <i class="fas fa-search absolute left-4 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                        <input type="text" name="search" placeholder="Search articles..." value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>"
                               class="w-full pl-12 pr-4 py-3 rounded-xl border-none focus:outline-none focus:ring-2 focus:ring-white/50 bg-white/10 text-white placeholder-white/60">
                    </div>
                    <button type="submit" class="bg-white text-[#1E3A8A] px-6 py-3 rounded-xl font-semibold hover:shadow-xl transition">
                        Search
                    </button>
                </form>
            </div>
        </div>
    </div>
    <div class="relative -mb-1">
        <svg class="w-full h-16 text-[#F3F4F6] dark:text-gray-900 fill-current" preserveAspectRatio="none" viewBox="0 0 1200 120">
            <path d="M321.39 56.44c58-10.79 114.16-30.13 172-41.86 82.39-16.72 168.19-17.73 250.45-.39C823.78 31 906.67 72 985.66 92.83c70.05 18.48 146.53 26.09 214.34 3V0H0v27.35a600.21 600.21 0 00321.39 29.09z"></path>
        </svg>
    </div>
</section>

<!-- Active Filters Display -->
<?php if ($categoryFilter || $tagFilter || isset($_GET['search']) && !empty($_GET['search'])): ?>
<section class="py-4 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700">
    <div class="container mx-auto px-4">
        <div class="flex flex-wrap items-center gap-3">
            <span class="text-sm text-[#6B7280]"><i class="fas fa-filter mr-2"></i>Active filters:</span>
            <?php if ($categoryFilter): ?>
            <span class="bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 px-3 py-1 rounded-full text-sm flex items-center">
                Category: <?php echo htmlspecialchars($categoryFilter); ?>
                <a href="?<?php echo http_build_query(array_diff_key($_GET, ['category' => ''])); ?>" class="ml-2 hover:text-red-500"><i class="fas fa-times"></i></a>
            </span>
            <?php endif; ?>
            <?php if ($tagFilter): ?>
            <span class="bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300 px-3 py-1 rounded-full text-sm flex items-center">
                Tag: <?php echo htmlspecialchars($tagFilter); ?>
                <a href="?<?php echo http_build_query(array_diff_key($_GET, ['tag' => ''])); ?>" class="ml-2 hover:text-red-500"><i class="fas fa-times"></i></a>
            </span>
            <?php endif; ?>
            <?php if (isset($_GET['search']) && $_GET['search']): ?>
            <span class="bg-purple-100 dark:bg-purple-900/30 text-purple-700 dark:text-purple-300 px-3 py-1 rounded-full text-sm flex items-center">
                Search: "<?php echo htmlspecialchars($_GET['search']); ?>"
                <a href="blog.php" class="ml-2 hover:text-red-500"><i class="fas fa-times"></i></a>
            </span>
            <?php endif; ?>
            <a href="blog.php" class="text-[#2563EB] text-sm hover:underline ml-auto">Clear all filters</a>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- Main Content -->
<section class="py-16 bg-[#F3F4F6] dark:bg-gray-900">
    <div class="container mx-auto px-4">
        <div class="flex flex-col lg:flex-row gap-12">
            
            <!-- Main Blog Content -->
            <div class="lg:w-2/3">
                
                <!-- Featured Post (only when no filters and on page 1) -->
                <?php if ($featuredPost && $featuredPost['is_featured'] == 1): ?>
                <div class="mb-12" data-aos="fade-up">
                    <span class="inline-block mb-3 text-[#2563EB] font-semibold text-sm uppercase tracking-wider bg-[#DBEAFE] dark:bg-[#1E3A8A]/30 px-4 py-2 rounded-full">
                        <i class="fas fa-star mr-2"></i>Featured Article
                    </span>
                    <div class="bg-white dark:bg-gray-800 rounded-2xl overflow-hidden shadow-xl border border-gray-200 dark:border-gray-700">
                        <div class="grid md:grid-cols-2 gap-0">
                            <div class="h-64 md:h-full bg-cover bg-center" style="background-image: url('<?php echo htmlspecialchars($featuredPost['featured_image'] ?: 'https://images.pexels.com/photos/3184292/pexels-photo-3184292.jpeg?auto=compress&cs=tinysrgb&w=800'); ?>');">
                            </div>
                            <div class="p-8 flex flex-col justify-center">
                                <span class="category-badge w-fit mb-3"><?php echo htmlspecialchars($featuredPost['category']); ?></span>
                                <h2 class="text-2xl md:text-3xl font-bold mb-3 text-[#111827] dark:text-white hover:text-[#2563EB] transition">
                                    <a href="blog-post.php?slug=<?php echo urlencode($featuredPost['slug']); ?>"><?php echo htmlspecialchars($featuredPost['title']); ?></a>
                                </h2>
                                <p class="text-[#4B5563] dark:text-gray-400 mb-4 leading-relaxed"><?php echo htmlspecialchars($featuredPost['excerpt']); ?></p>
                                <div class="flex items-center justify-between flex-wrap gap-3">
                                    <div class="flex items-center space-x-3">
                                        <div class="w-10 h-10 brand-gradient rounded-full flex items-center justify-center text-white font-bold">
                                            <?php echo strtoupper(substr($featuredPost['author_name'], 0, 1)); ?>
                                        </div>
                                        <div>
                                            <p class="font-semibold text-[#111827] dark:text-white text-sm"><?php echo htmlspecialchars($featuredPost['author_name']); ?></p>
                                            <p class="text-xs text-[#6B7280]"><?php echo formatDate($featuredPost['created_at']); ?> · <?php echo getReadingTime($featuredPost['read_time']); ?></p>
                                        </div>
                                    </div>
                                    <a href="blog-post.php?slug=<?php echo urlencode($featuredPost['slug']); ?>" class="btn-primary text-sm py-2 px-4">
                                        Read More <i class="fas fa-arrow-right ml-1"></i>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Results Count & Sort -->
                <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
                    <h2 class="text-2xl font-bold text-[#111827] dark:text-white">
                        <?php if (empty($blogPosts)): ?>
                            No articles found
                        <?php else: ?>
                            <?php echo number_format($totalPosts); ?> Article<?php echo $totalPosts != 1 ? 's' : ''; ?>
                        <?php endif; ?>
                    </h2>
                    
                    <div class="relative">
                        <select onchange="window.location.href='?' + new URLSearchParams({...Object.fromEntries(new URLSearchParams(window.location.search)), sort: this.value}).toString()" 
                                class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-[#111827] dark:text-white cursor-pointer">
                            <option value="newest" <?php echo $sort == 'newest' ? 'selected' : ''; ?>>Newest First</option>
                            <option value="popular" <?php echo $sort == 'popular' ? 'selected' : ''; ?>>Most Popular</option>
                            <option value="oldest" <?php echo $sort == 'oldest' ? 'selected' : ''; ?>>Oldest First</option>
                        </select>
                    </div>
                </div>

                <!-- Blog Posts Grid -->
                <?php if (empty($blogPosts)): ?>
                    <div class="bg-white dark:bg-gray-800 rounded-2xl p-12 text-center border border-gray-200 dark:border-gray-700" data-aos="fade-up">
                        <i class="fas fa-search text-5xl text-gray-400 mb-4"></i>
                        <h3 class="text-xl font-semibold text-[#111827] dark:text-white mb-2">No articles found</h3>
                        <p class="text-[#6B7280] mb-6">Try adjusting your search or filter criteria.</p>
                        <a href="blog.php" class="btn-primary inline-flex items-center">
                            <i class="fas fa-arrow-left mr-2"></i> View All Articles
                        </a>
                    </div>
                <?php else: ?>
                    <div class="grid md:grid-cols-2 gap-6">
                        <?php foreach ($blogPosts as $index => $post): ?>
                        <article class="blog-card bg-white dark:bg-gray-800 rounded-xl overflow-hidden group" data-aos="fade-up" data-aos-delay="<?php echo min($index * 50, 200); ?>">
                            <a href="blog-post.php?slug=<?php echo urlencode($post['slug']); ?>" class="block">
                                <div class="h-48 bg-cover bg-center relative" style="background-image: url('<?php echo htmlspecialchars($post['featured_image'] ?: 'https://images.pexels.com/photos/3184292/pexels-photo-3184292.jpeg?auto=compress&cs=tinysrgb&w=800'); ?>');">
                                    <span class="category-badge absolute top-4 left-4"><?php echo htmlspecialchars($post['category']); ?></span>
                                    <?php if ($post['is_featured']): ?>
                                    <span class="absolute top-4 right-4 bg-yellow-500 text-white text-xs px-2 py-1 rounded-full">
                                        <i class="fas fa-star mr-1"></i>Featured
                                    </span>
                                    <?php endif; ?>
                                </div>
                                <div class="p-6">
                                    <div class="flex items-center text-sm text-[#6B7280] mb-3 space-x-4">
                                        <span><i class="far fa-calendar-alt mr-1"></i><?php echo formatDate($post['created_at']); ?></span>
                                        <span><i class="far fa-clock mr-1"></i><?php echo getReadingTime($post['read_time']); ?></span>
                                        <span><i class="far fa-eye mr-1"></i><?php echo number_format($post['views']); ?></span>
                                    </div>
                                    <h3 class="font-bold text-lg mb-3 text-[#111827] dark:text-white group-hover:text-[#2563EB] transition line-clamp-2">
                                        <?php echo htmlspecialchars($post['title']); ?>
                                    </h3>
                                    <p class="text-[#4B5563] dark:text-gray-400 mb-4 text-sm line-clamp-3"><?php echo htmlspecialchars($post['excerpt']); ?></p>
                                    
                                    <!-- Tags -->
                                    <?php if (!empty($post['tags'])): ?>
                                    <div class="flex flex-wrap gap-2 mb-4">
                                        <?php foreach (array_slice(explode(',', $post['tags']), 0, 3) as $tag): ?>
                                        <span class="text-xs bg-gray-100 dark:bg-gray-700 text-[#4B5563] dark:text-gray-300 px-2 py-1 rounded-full">
                                            <?php echo htmlspecialchars(trim($tag)); ?>
                                        </span>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <div class="flex items-center justify-between pt-4 border-t border-gray-100 dark:border-gray-700">
                                        <div class="flex items-center space-x-2">
                                            <div class="w-8 h-8 bg-[#DBEAFE] dark:bg-[#1E3A8A]/30 rounded-full flex items-center justify-center text-[#2563EB] font-bold text-xs">
                                                <?php echo strtoupper(substr($post['author_name'], 0, 1)); ?>
                                            </div>
                                            <span class="text-sm font-medium text-[#111827] dark:text-white"><?php echo htmlspecialchars($post['author_name']); ?></span>
                                        </div>
                                        <span class="text-[#2563EB] font-medium text-sm hover:text-[#1E3A8A] transition flex items-center">
                                            Read <i class="fas fa-arrow-right ml-1 group-hover:ml-2 transition-all"></i>
                                        </span>
                                    </div>
                                </div>
                            </a>
                        </article>
                        <?php endforeach; ?>
                    </div>
                    
                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                    <div class="flex justify-center mt-12">
                        <nav class="flex items-center space-x-2 flex-wrap gap-2">
                            <?php if ($page > 1): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="w-10 h-10 flex items-center justify-center rounded-lg bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 text-[#111827] dark:text-white hover:bg-[#2563EB] hover:text-white transition">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                            <?php endif; ?>
                            
                            <?php
                            $startPage = max(1, $page - 2);
                            $endPage = min($totalPages, $page + 2);
                            
                            if ($startPage > 1): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>" class="w-10 h-10 flex items-center justify-center rounded-lg bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 text-[#111827] dark:text-white hover:bg-[#2563EB] hover:text-white transition">1</a>
                            <?php if ($startPage > 2): ?>
                            <span class="px-2 text-gray-400">...</span>
                            <?php endif; ?>
                            <?php endif; ?>
                            
                            <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" 
                               class="w-10 h-10 flex items-center justify-center rounded-lg <?php echo $i == $page ? 'bg-[#2563EB] text-white' : 'bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 text-[#111827] dark:text-white hover:bg-[#2563EB] hover:text-white'; ?> transition">
                                <?php echo $i; ?>
                            </a>
                            <?php endfor; ?>
                            
                            <?php if ($endPage < $totalPages): ?>
                            <?php if ($endPage < $totalPages - 1): ?>
                            <span class="px-2 text-gray-400">...</span>
                            <?php endif; ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $totalPages])); ?>" class="w-10 h-10 flex items-center justify-center rounded-lg bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 text-[#111827] dark:text-white hover:bg-[#2563EB] hover:text-white transition"><?php echo $totalPages; ?></a>
                            <?php endif; ?>
                            
                            <?php if ($page < $totalPages): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="w-10 h-10 flex items-center justify-center rounded-lg bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 text-[#111827] dark:text-white hover:bg-[#2563EB] hover:text-white transition">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                            <?php endif; ?>
                        </nav>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            
            <!-- Sidebar -->
            <aside class="lg:w-1/3 space-y-8" data-aos="fade-left">
                
                <!-- Newsletter Widget -->
                <div class="bg-white dark:bg-gray-800 rounded-xl p-6 shadow-sm border border-gray-200 dark:border-gray-700 sticky top-24">
                    <h3 class="font-bold text-lg mb-4 text-[#111827] dark:text-white flex items-center">
                        <i class="fas fa-envelope text-[#2563EB] mr-2"></i> Career Newsletter
                    </h3>
                    <p class="text-sm text-[#6B7280] mb-4">Get weekly job search tips and career advice delivered to your inbox.</p>
                    
                    <?php if (isset($_SESSION['newsletter_success'])): ?>
                    <div class="bg-green-100 text-green-700 p-3 rounded-lg mb-4 text-sm">
                        <i class="fas fa-check-circle mr-2"></i><?php echo $_SESSION['newsletter_success']; unset($_SESSION['newsletter_success']); ?>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (isset($_SESSION['newsletter_error'])): ?>
                    <div class="bg-red-100 text-red-700 p-3 rounded-lg mb-4 text-sm">
                        <i class="fas fa-exclamation-circle mr-2"></i><?php echo $_SESSION['newsletter_error']; unset($_SESSION['newsletter_error']); ?>
                    </div>
                    <?php endif; ?>
                    
                    <form method="POST" class="space-y-3">
                        <input type="text" name="newsletter_name" placeholder="Your name" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-gray-50 dark:bg-gray-700 text-[#111827] dark:text-white focus:outline-none focus:ring-2 focus:ring-[#2563EB]">
                        <input type="email" name="newsletter_email" placeholder="Email address" required class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-gray-50 dark:bg-gray-700 text-[#111827] dark:text-white focus:outline-none focus:ring-2 focus:ring-[#2563EB]">
                        <button type="submit" class="btn-primary w-full text-sm py-2">
                            Subscribe <i class="fas fa-paper-plane ml-2"></i>
                        </button>
                    </form>
                    <p class="text-xs text-[#6B7280] mt-3">No spam, unsubscribe anytime.</p>
                </div>
                
                <!-- Categories Widget -->
                <div class="bg-white dark:bg-gray-800 rounded-xl p-6 shadow-sm border border-gray-200 dark:border-gray-700">
                    <h3 class="font-bold text-lg mb-4 text-[#111827] dark:text-white flex items-center">
                        <i class="fas fa-folder-open text-[#2563EB] mr-2"></i> Categories
                    </h3>
                    <?php if (empty($categories)): ?>
                        <p class="text-sm text-gray-500">No categories available.</p>
                    <?php else: ?>
                    <ul class="space-y-2">
                        <?php foreach ($categories as $cat): ?>
                        <li>
                            <a href="?category=<?php echo urlencode($cat['name']); ?>" class="flex items-center justify-between py-2 text-[#4B5563] dark:text-gray-300 hover:text-[#2563EB] transition group">
                                <span><i class="fas fa-folder mr-2 text-sm opacity-70"></i><?php echo htmlspecialchars($cat['name']); ?></span>
                                <span class="bg-gray-100 dark:bg-gray-700 text-xs font-medium px-2 py-1 rounded-full group-hover:bg-[#2563EB] group-hover:text-white transition"><?php echo number_format($cat['count']); ?></span>
                            </a>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </div>
                
                <!-- Trending Posts -->
                <?php if (!empty($trendingPosts)): ?>
                <div class="bg-white dark:bg-gray-800 rounded-xl p-6 shadow-sm border border-gray-200 dark:border-gray-700">
                    <h3 class="font-bold text-lg mb-4 text-[#111827] dark:text-white flex items-center">
                        <i class="fas fa-fire text-[#F59E0B] mr-2"></i> Trending Now
                    </h3>
                    <ul class="space-y-4">
                        <?php foreach ($trendingPosts as $trending): ?>
                        <li class="flex items-start space-x-3 group">
                            <div class="w-16 h-16 rounded-lg bg-cover bg-center flex-shrink-0" style="background-image: url('<?php echo htmlspecialchars($trending['featured_image'] ?: 'https://images.pexels.com/photos/3184292/pexels-photo-3184292.jpeg?auto=compress&cs=tinysrgb&w=800'); ?>');"></div>
                            <div>
                                <a href="blog-post.php?slug=<?php echo urlencode($trending['slug']); ?>" class="font-medium text-[#111827] dark:text-white hover:text-[#2563EB] transition text-sm line-clamp-2">
                                    <?php echo htmlspecialchars($trending['title']); ?>
                                </a>
                                <p class="text-xs text-[#6B7280] mt-1"><i class="far fa-eye mr-1"></i><?php echo number_format($trending['views']); ?> views</p>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
                
                <!-- Popular Tags -->
                <?php if (!empty($tags)): ?>
                <div class="bg-white dark:bg-gray-800 rounded-xl p-6 shadow-sm border border-gray-200 dark:border-gray-700">
                    <h3 class="font-bold text-lg mb-4 text-[#111827] dark:text-white flex items-center">
                        <i class="fas fa-tags text-[#2563EB] mr-2"></i> Popular Tags
                    </h3>
                    <div class="flex flex-wrap gap-2">
                        <?php foreach ($tags as $tag): ?>
                        <a href="?tag=<?php echo urlencode($tag['tag_name']); ?>" class="px-3 py-1 bg-gray-100 dark:bg-gray-700 text-[#4B5563] dark:text-gray-300 rounded-full text-sm hover:bg-[#2563EB] hover:text-white transition">
                            <?php echo htmlspecialchars($tag['tag_name']); ?>
                            <span class="text-xs ml-1 opacity-70">(<?php echo $tag['count']; ?>)</span>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Resume Guide CTA -->
                <div class="brand-gradient rounded-xl p-8 text-white shadow-xl">
                    <i class="fas fa-file-alt text-4xl mb-4 opacity-80"></i>
                    <h3 class="font-bold text-2xl mb-3">Free Resume Guide</h3>
                    <p class="text-white/90 mb-6 text-sm leading-relaxed">Download our comprehensive CV writing guide with templates that Ghanaian employers love.</p>
                    <a href="#" class="inline-flex items-center justify-center bg-white text-[#1E3A8A] font-semibold px-6 py-3 rounded-xl hover:shadow-lg transition transform hover:-translate-y-1 w-full">
                        <i class="fas fa-download mr-2"></i> Download PDF
                    </a>
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
                    <div class="w-10 h-10 brand-gradient rounded-xl flex items-center justify-center">
                        <i class="fas fa-handshake text-white"></i>
                    </div>
                    <span class="text-2xl font-bold gradient-text">TransNow Ghana</span>
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

<script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
<script>
    AOS.init({ duration: 1000, once: true });
    
    // Mobile menu
    const mobileBtn = document.getElementById('mobileMenuBtn');
    if (mobileBtn) {
        mobileBtn.addEventListener('click', function() {
            const menu = document.getElementById('mobileMenu');
            menu.classList.toggle('hidden');
            const icon = this.querySelector('i');
            icon.classList.toggle('fa-bars');
            icon.classList.toggle('fa-times');
        });
    }
    
    // Dark mode toggle (optional)
    if (localStorage.getItem('theme') === 'dark' || (!localStorage.getItem('theme') && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
        document.documentElement.classList.add('dark');
    }
</script>
</body>
</html>