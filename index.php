<?php
/**
 * index.php - TransNow Ghana Home Page
 * Version: 1.0
 *
 * Landing page for the TransNow Ghana job portal. Follows the same conventions
 * as the rest of the project:
 *   - config/database.php   -> PDO connection (getDB())
 *   - config/functions.php  -> session handling + helpers (fetchOne, fetchAll,
 *                              isLoggedIn, getUserType)
 *   - Tailwind CSS + Font Awesome + Inter + AOS, same brand colours and the
 *     same navigation / footer as jobs.php and testimonials.php
 *
 * Links go to existing pages: jobs.php, job-detail.php, testimonials.php,
 * blog.php, about.php, login.php, register.php, logout.php, privacy.php,
 * terms.php and the seekers/, employer/ and admin/ dashboards.
 *
 * Requires PHP 8.1 or newer (written to run cleanly on PHP 8.3 - 8.5).
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/functions.php';

// -----------------------------------------------------------------------------
// Small view helpers (prefixed so they never clash with other pages)
// -----------------------------------------------------------------------------
if (!function_exists('esc')) {
    /** Escape any value for safe HTML output (null-safe). */
    function esc($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

function home_salary($min, $max, $currency, $type): string
{
    $min = (float)$min;
    $max = (float)$max;
    if ($min <= 0 && $max <= 0) {
        return 'Salary not disclosed';
    }

    $currency = (string)($currency ?: 'GHS');
    $symbols  = ['GHS' => '₵', 'USD' => '$'];
    $symbol   = $symbols[$currency] ?? ($currency . ' ');

    $periods = ['Monthly' => 'month', 'Yearly' => 'year', 'Hourly' => 'hour', 'Daily' => 'day', 'Weekly' => 'week'];
    $period  = $periods[(string)$type] ?? 'month';

    if ($min > 0 && $max > 0 && $min != $max) {
        return $symbol . number_format($min) . ' - ' . $symbol . number_format($max) . ' / ' . $period;
    }
    if ($min > 0 && $max <= 0) {
        return $symbol . number_format($min) . ' / ' . $period;
    }
    if ($max > 0 && $min <= 0) {
        return 'Up to ' . $symbol . number_format($max) . ' / ' . $period;
    }
    return $symbol . number_format($max > 0 ? $max : $min) . ' / ' . $period;
}

function home_time_ago($datetime): string
{
    $ts = $datetime ? strtotime((string)$datetime) : false;
    if (!$ts) {
        return 'Recently';
    }
    $diff = time() - $ts;
    if ($diff < 60)      return 'Just now';
    if ($diff < 3600)    return (int)floor($diff / 60) . ' min ago';
    if ($diff < 86400)   { $n = (int)floor($diff / 3600);   return $n . ' hour' . ($n > 1 ? 's' : '') . ' ago'; }
    if ($diff < 604800)  { $n = (int)floor($diff / 86400);  return $n . ' day'  . ($n > 1 ? 's' : '') . ' ago'; }
    if ($diff < 2592000) { $n = (int)floor($diff / 604800); return $n . ' week' . ($n > 1 ? 's' : '') . ' ago'; }
    return date('M j, Y', $ts);
}

function home_excerpt($text, int $limit = 120): string
{
    $text = trim((string)preg_replace('/\s+/', ' ', strip_tags((string)$text)));
    if (function_exists('mb_strimwidth')) {
        return mb_strimwidth($text, 0, $limit, '…', 'UTF-8');
    }
    return strlen($text) > $limit ? substr($text, 0, $limit - 3) . '...' : $text;
}

function home_initial($name): string
{
    $name = trim((string)$name);
    if ($name === '') {
        return '?';
    }
    $first = function_exists('mb_substr') ? mb_substr($name, 0, 1, 'UTF-8') : substr($name, 0, 1);
    return function_exists('mb_strtoupper') ? mb_strtoupper($first, 'UTF-8') : strtoupper($first);
}

function home_stars($rating): string
{
    $rating = max(0, min(5, (float)$rating));
    $html = '';
    for ($i = 1; $i <= 5; $i++) {
        if ($i <= $rating) {
            $html .= '<i class="fas fa-star text-[#F59E0B]"></i>';
        } elseif ($i - 0.5 <= $rating) {
            $html .= '<i class="fas fa-star-half-alt text-[#F59E0B]"></i>';
        } else {
            $html .= '<i class="far fa-star text-[#F59E0B]"></i>';
        }
    }
    return $html;
}

function home_category_icon($category): string
{
    $icons = [
        'Translation'      => 'fa-language',
        'Sales'            => 'fa-chart-line',
        'Education'        => 'fa-graduation-cap',
        'Administration'   => 'fa-clipboard-list',
        'Cooking'          => 'fa-utensils',
        'Driving'          => 'fa-car',
        'Maintenance'      => 'fa-screwdriver-wrench',
        'Construction'     => 'fa-helmet-safety',
        'Warehouse'        => 'fa-warehouse',
        'Security'         => 'fa-shield-halved',
        'Office Clerk'     => 'fa-folder-open',
        'Secretary'        => 'fa-calendar-check',
        'Customer Service' => 'fa-headset',
        'IT & Technology'  => 'fa-laptop-code',
        'Healthcare'       => 'fa-heart-pulse',
    ];
    return $icons[(string)$category] ?? 'fa-briefcase';
}

// -----------------------------------------------------------------------------
// Session / user state (session is started by config/functions.php)
// -----------------------------------------------------------------------------
$isLoggedIn = isLoggedIn();
$userType   = (string)(getUserType() ?? '');

$dashboardLinks = [
    'seeker'   => '/seekers/dashboard.php',
    'employer' => '/employer/dashboard.php',
    'admin'    => '/admin/dashboard.php',
];
$dashboardUrl = $dashboardLinks[$userType] ?? '/login.php';
$postJobUrl   = ($isLoggedIn && $userType === 'employer') ? '/employer/post-job.php' : '/register.php';

// -----------------------------------------------------------------------------
// Data (every query is wrapped by fetchOne/fetchAll, so a missing table or an
// empty result never breaks the page)
// -----------------------------------------------------------------------------
$jobStats        = fetchOne("SELECT COUNT(*) AS total_jobs, COUNT(DISTINCT job_category) AS total_categories FROM jobs WHERE status = 'Active'") ?: [];
$totalJobs       = (int)($jobStats['total_jobs'] ?? 0);
$totalCategories = (int)($jobStats['total_categories'] ?? 0);

$latestJobs = fetchAll(
    "SELECT id, title, overview, description, job_category, employment_type, location_type, city,
            salary_min, salary_max, salary_currency, salary_type, is_urgent, is_featured, created_at
     FROM jobs
     WHERE status = 'Active'
     ORDER BY is_featured DESC, created_at DESC
     LIMIT 6"
);

$categories = fetchAll(
    "SELECT job_category, COUNT(*) AS cnt
     FROM jobs
     WHERE status = 'Active' AND job_category IS NOT NULL AND job_category <> '' AND job_category <> 'Other'
     GROUP BY job_category
     ORDER BY cnt DESC, job_category ASC
     LIMIT 8"
);

$storyStats   = fetchOne("SELECT COUNT(*) AS cnt, COALESCE(AVG(rating), 0) AS avg_rating FROM testimonials WHERE is_active = 1") ?: [];
$totalStories = (int)($storyStats['cnt'] ?? 0);
$avgRating    = (float)($storyStats['avg_rating'] ?? 0);

$testimonials = fetchAll(
    "SELECT author_name, author_title, author_company, content, rating
     FROM testimonials
     WHERE is_active = 1
     ORDER BY rating DESC, created_at DESC
     LIMIT 3"
);

// -----------------------------------------------------------------------------
// SEO
// -----------------------------------------------------------------------------
$scheme = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')) ? 'https' : 'http';
$host    = preg_replace('/[^a-z0-9.\-:]/i', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
$baseUrl = $host !== '' ? $scheme . '://' . $host : '';

$metaDescription = ($totalJobs > 0 ? number_format($totalJobs) . '+ ' : '')
    . 'job opportunities across Ghana. TransNow Ghana bridges Chinese employers and Ghanaian talent '
    . 'through communication, culture and career opportunities.';

$jsonLd = null;
if ($baseUrl !== '') {
    $jsonLd = [
        '@context'        => 'https://schema.org',
        '@type'           => 'WebSite',
        'name'            => 'TransNow Ghana',
        'url'             => $baseUrl . '/',
        'potentialAction' => [
            '@type'       => 'SearchAction',
            'target'      => $baseUrl . '/jobs.php?search={search_term_string}',
            'query-input' => 'required name=search_term_string',
        ],
    ];
}

$popularSearches = ['Remote', 'Full-time', 'Translation', 'Sales', 'IT'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TransNow Ghana | Find Jobs in Ghana &amp; Connect with Chinese Employers</title>
    <meta name="description" content="<?= esc($metaDescription) ?>">
    <meta name="keywords" content="jobs in Ghana, TransNow Ghana, China-Ghana jobs, bilingual jobs Ghana, Chinese companies Ghana, career opportunities Ghana">
    <?php if ($baseUrl !== ''): ?>
    <link rel="canonical" href="<?= esc($baseUrl) ?>/">
    <meta property="og:url" content="<?= esc($baseUrl) ?>/">
    <?php endif; ?>

    <!-- Open Graph -->
    <meta property="og:type" content="website">
    <meta property="og:title" content="TransNow Ghana | Find Jobs in Ghana">
    <meta property="og:description" content="<?= esc($metaDescription) ?>">
    <meta property="og:site_name" content="TransNow Ghana">

    <!-- Icons / manifest (files live in the project root) -->
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <link rel="manifest" href="/site.webmanifest">

    <!-- Tailwind CSS (class-based dark mode so the toggle works) -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { darkMode: 'class' };</script>
    <script>
        // Apply saved theme before first paint to avoid a flash
        (function () {
            try {
                var t = localStorage.getItem('theme');
                if (t === 'dark' || (!t && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                    document.documentElement.classList.add('dark');
                }
            } catch (e) {}
        })();
    </script>

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">

    <!-- AOS Animation -->
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">

    <?php if ($jsonLd): ?>
    <script type="application/ld+json"><?= json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
    <?php endif; ?>

    <style>
        * { font-family: 'Inter', sans-serif; }

        :root {
            --primary: #1E3A8A;
            --primary-dark: #152C6B;
            --secondary: #3B82F6;
            --secondary-light: #60A5FA;
            --accent: #10B981;
            --accent-dark: #059669;
            --background: #F3F4F6;
        }

        .brand-gradient {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 50%, var(--secondary-light) 100%);
        }

        .gradient-text {
            background: linear-gradient(135deg, var(--primary), var(--secondary), var(--secondary-light));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: #fff;
            font-weight: 600;
            padding: 0.75rem 1.5rem;
            border-radius: 0.75rem;
            transition: all 0.3s;
            box-shadow: 0 4px 6px -1px rgba(30, 58, 138, 0.2);
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(30, 58, 138, 0.3);
        }

        .btn-accent {
            background: var(--accent);
            color: #fff;
            font-weight: 600;
            padding: 0.75rem 1.5rem;
            border-radius: 0.75rem;
            transition: all 0.3s;
        }
        .btn-accent:hover { transform: translateY(-2px); background: var(--accent-dark); }

        .nav-link { position: relative; }
        .nav-link::after {
            content: '';
            position: absolute;
            bottom: -4px;
            left: 0;
            width: 0;
            height: 2px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            transition: width 0.3s;
        }
        .nav-link:hover::after { width: 100%; }

        .job-card, .category-card, .story-card {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border: 1px solid #E5E7EB;
        }
        .job-card:hover, .category-card:hover, .story-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 20px 25px -12px rgba(0, 0, 0, 0.15);
            border-color: var(--secondary);
        }
        .dark .job-card, .dark .category-card, .dark .story-card { border-color: #374151; }

        .badge-urgent   { background: linear-gradient(135deg, #FEE2E2, #FECACA); color: #DC2626; font-size: .7rem; padding: .25rem .75rem; border-radius: 9999px; font-weight: 700; }
        .badge-featured { background: linear-gradient(135deg, #FEF3C7, #FDE68A); color: #D97706; font-size: .7rem; padding: .25rem .75rem; border-radius: 9999px; font-weight: 700; }
        .badge-remote   { background: linear-gradient(135deg, #D1FAE5, #A7F3D0); color: #065F46; font-size: .7rem; padding: .25rem .75rem; border-radius: 9999px; font-weight: 600; }
        .badge-category { background: linear-gradient(135deg, #E0E7FF, #C7D2FE); color: #3730A3; font-size: .7rem; padding: .25rem .75rem; border-radius: 9999px; font-weight: 600; }

        .line-clamp-2 { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }

        @keyframes float { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-15px); } }
        .animate-float { animation: float 6s ease-in-out infinite; }

        /* Visible keyboard focus */
        a:focus-visible, button:focus-visible { outline: 2px solid var(--secondary); outline-offset: 2px; }

        /* Respect reduced-motion preferences */
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
                scroll-behavior: auto !important;
            }
        }

        ::-webkit-scrollbar { width: 10px; }
        ::-webkit-scrollbar-track { background: var(--background); border-radius: 8px; }
        ::-webkit-scrollbar-thumb { background: linear-gradient(135deg, var(--primary), var(--secondary)); border-radius: 8px; }
        .dark ::-webkit-scrollbar-track { background: #374151; }
    </style>
</head>
<body class="bg-[#F3F4F6] dark:bg-gray-900 text-[#111827] dark:text-gray-100 transition-colors duration-300">

<!-- ========== NAVIGATION ========== -->
<nav class="fixed w-full z-50 bg-white/95 dark:bg-gray-900/95 backdrop-blur-md shadow-sm transition-all duration-300" id="navbar">
    <div class="container mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between items-center py-4">
            <a href="/" class="flex items-center space-x-3 group">
                <div class="w-10 h-10 brand-gradient rounded-xl flex items-center justify-center transform group-hover:rotate-12 transition-all duration-300">
                    <i class="fas fa-handshake text-white text-xl"></i>
                </div>
                <span class="text-2xl font-bold gradient-text">TransNow Ghana</span>
            </a>

            <div class="hidden md:flex items-center space-x-8">
                <a href="/" class="nav-link text-[#1E3A8A] dark:text-[#60A5FA] font-medium border-b-2 border-[#1E3A8A]">Home</a>
                <a href="/jobs.php" class="nav-link text-[#111827] dark:text-gray-200 font-medium hover:text-[#1E3A8A] transition">Find Jobs</a>
                <a href="/testimonials.php" class="nav-link text-[#111827] dark:text-gray-200 font-medium hover:text-[#1E3A8A] transition">Success Stories</a>
                <a href="/blog.php" class="nav-link text-[#111827] dark:text-gray-200 font-medium hover:text-[#1E3A8A] transition">Career Advice</a>
                <a href="/about.php" class="nav-link text-[#111827] dark:text-gray-200 font-medium hover:text-[#1E3A8A] transition">About</a>
            </div>

            <div class="flex items-center space-x-3">
                <button id="darkModeToggle" type="button" aria-label="Toggle dark mode" class="p-2 rounded-lg bg-gray-100 dark:bg-gray-800 hover:scale-110 transition">
                    <i class="fas fa-moon text-gray-600 dark:text-gray-300" id="darkModeIcon"></i>
                </button>
                <?php if ($isLoggedIn): ?>
                    <a href="<?= esc($dashboardUrl) ?>" class="hidden sm:inline-flex items-center space-x-2 px-5 py-2.5 border border-[#1E3A8A] text-[#1E3A8A] dark:text-[#60A5FA] dark:border-[#60A5FA] rounded-xl font-medium hover:bg-[#1E3A8A] hover:text-white transition">
                        <i class="fas fa-gauge"></i><span>Dashboard</span>
                    </a>
                    <a href="/logout.php" class="hidden sm:inline-flex btn-primary items-center space-x-2 !py-2.5 !px-5">
                        <i class="fas fa-right-from-bracket"></i><span>Logout</span>
                    </a>
                <?php else: ?>
                    <a href="/login.php" class="hidden sm:inline-flex items-center space-x-2 px-5 py-2.5 border border-[#1E3A8A] text-[#1E3A8A] dark:text-[#60A5FA] dark:border-[#60A5FA] rounded-xl font-medium hover:bg-[#1E3A8A] hover:text-white transition">
                        <i class="fas fa-right-to-bracket"></i><span>Login</span>
                    </a>
                    <a href="/register.php" class="hidden sm:inline-flex btn-primary items-center space-x-2 !py-2.5 !px-5">
                        <i class="fas fa-user-plus"></i><span>Register</span>
                    </a>
                <?php endif; ?>
                <button id="mobileMenuBtn" type="button" aria-label="Open menu" class="md:hidden p-2 rounded-lg bg-gray-100 dark:bg-gray-800">
                    <i class="fas fa-bars text-gray-600 dark:text-gray-300 text-xl"></i>
                </button>
            </div>
        </div>

        <div id="mobileMenu" class="md:hidden hidden pb-4 space-y-3">
            <a href="/" class="block py-2 px-4 rounded-lg bg-blue-50 dark:bg-blue-900/30 text-[#1E3A8A] dark:text-[#60A5FA]">Home</a>
            <a href="/jobs.php" class="block py-2 px-4 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800">Find Jobs</a>
            <a href="/testimonials.php" class="block py-2 px-4 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800">Success Stories</a>
            <a href="/blog.php" class="block py-2 px-4 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800">Career Advice</a>
            <a href="/about.php" class="block py-2 px-4 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800">About</a>
            <?php if ($isLoggedIn): ?>
                <a href="<?= esc($dashboardUrl) ?>" class="block py-2 px-4 text-[#1E3A8A] dark:text-[#60A5FA] border border-[#1E3A8A] dark:border-[#60A5FA] rounded-lg">Dashboard</a>
                <a href="/logout.php" class="block py-2 px-4 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800">Logout</a>
            <?php else: ?>
                <a href="/login.php" class="block py-2 px-4 text-[#1E3A8A] dark:text-[#60A5FA] border border-[#1E3A8A] dark:border-[#60A5FA] rounded-lg">Login</a>
                <a href="/register.php" class="block py-2 px-4 rounded-lg brand-gradient text-white text-center font-semibold">Register</a>
            <?php endif; ?>
        </div>
    </div>
</nav>

<!-- Scroll Progress Bar -->
<div class="fixed top-0 left-0 w-full h-1 bg-gray-200 dark:bg-gray-700 z-[60]">
    <div id="scrollProgress" class="h-full brand-gradient w-0 transition-all duration-300"></div>
</div>

<!-- ========== HERO ========== -->
<section class="relative pt-32 pb-24 brand-gradient overflow-hidden">
    <div class="absolute inset-0 opacity-10 pointer-events-none">
        <i class="fas fa-briefcase absolute text-9xl top-20 left-10 text-white animate-float"></i>
        <i class="fas fa-handshake absolute text-8xl bottom-10 right-10 text-white animate-float" style="animation-delay: 2s;"></i>
        <i class="fas fa-language absolute text-7xl top-40 right-[15%] text-white animate-float" style="animation-delay: 4s;"></i>
    </div>

    <div class="container mx-auto px-4 relative z-10">
        <div class="max-w-4xl mx-auto text-center" data-aos="fade-up">
            <div class="inline-flex items-center space-x-2 bg-white/20 backdrop-blur-sm rounded-full px-5 py-2.5 mb-6">
                <i class="fas fa-earth-africa text-[#FDB813]"></i>
                <span class="text-sm font-semibold text-white">Bridging China &amp; Ghana</span>
            </div>
            <h1 class="text-4xl md:text-5xl lg:text-6xl font-extrabold text-white mb-6 leading-tight">
                Find Your Next Career in Ghana
            </h1>
            <p class="text-lg md:text-xl text-white/90 max-w-2xl mx-auto mb-10">
                TransNow connects Chinese employers and Ghanaian talent through communication, culture, and career opportunities.
            </p>

            <!-- Search -->
            <form method="GET" action="/jobs.php" class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl p-2 flex flex-col md:flex-row gap-2 text-left focus-within:ring-2 focus-within:ring-white/70">
                <div class="flex-1 relative group">
                    <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 group-hover:text-blue-500 transition"></i>
                    <input type="text" name="search" placeholder="Job title, skills, or keywords..." aria-label="Job title, skills, or keywords"
                           class="w-full pl-12 pr-4 py-4 bg-transparent border-none focus:outline-none text-gray-900 dark:text-white placeholder-gray-400 rounded-xl">
                </div>
                <div class="flex-1 relative group">
                    <i class="fas fa-map-marker-alt absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 group-hover:text-blue-500 transition"></i>
                    <input type="text" name="location" placeholder="City, region, or remote..." aria-label="City, region, or remote"
                           class="w-full pl-12 pr-4 py-4 bg-transparent border-none focus:outline-none text-gray-900 dark:text-white placeholder-gray-400 rounded-xl">
                </div>
                <button type="submit" class="btn-accent flex items-center justify-center space-x-2 whitespace-nowrap px-8">
                    <i class="fas fa-search"></i><span>Search Jobs</span>
                </button>
            </form>

            <div class="mt-6 flex flex-wrap justify-center gap-2">
                <span class="text-white/70 text-sm self-center">Popular:</span>
                <?php foreach ($popularSearches as $term): ?>
                    <a href="/jobs.php?search=<?= urlencode($term) ?>" class="text-white/80 hover:text-white text-sm bg-white/10 hover:bg-white/20 px-3 py-1 rounded-full transition">
                        <?= esc($term) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="absolute bottom-0 left-0 w-full -mb-1">
        <svg class="w-full h-16 text-[#F3F4F6] dark:text-gray-900 fill-current" preserveAspectRatio="none" viewBox="0 0 1200 120" aria-hidden="true">
            <path d="M321.39 56.44c58-10.79 114.16-30.13 172-41.86 82.39-16.72 168.19-17.73 250.45-.39C823.78 31 906.67 72 985.66 92.83c70.05 18.48 146.53 26.09 214.34 3V0H0v27.35a600.21 600.21 0 00321.39 29.09z"></path>
        </svg>
    </div>
</section>

<!-- ========== STATS ========== -->
<section class="py-14 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700">
    <div class="container mx-auto px-4">
        <div class="grid grid-cols-2 md:grid-cols-4 gap-8">
            <div class="text-center" data-aos="fade-up">
                <div class="text-4xl font-bold gradient-text mb-2">
                    <span class="counter" data-target="<?= $totalJobs ?>"><?= $totalJobs ?></span>+
                </div>
                <div class="text-[#4B5563] dark:text-gray-400">Active Jobs</div>
            </div>
            <div class="text-center" data-aos="fade-up" data-aos-delay="100">
                <div class="text-4xl font-bold gradient-text mb-2">
                    <span class="counter" data-target="<?= $totalCategories ?>"><?= $totalCategories ?></span>
                </div>
                <div class="text-[#4B5563] dark:text-gray-400">Job Categories</div>
            </div>
            <?php if ($totalStories > 0): ?>
            <div class="text-center" data-aos="fade-up" data-aos-delay="200">
                <div class="text-4xl font-bold gradient-text mb-2">
                    <span class="counter" data-target="<?= $totalStories ?>"><?= $totalStories ?></span>+
                </div>
                <div class="text-[#4B5563] dark:text-gray-400">Success Stories</div>
            </div>
            <div class="text-center" data-aos="fade-up" data-aos-delay="300">
                <div class="text-4xl font-bold gradient-text mb-2"><?= number_format($avgRating, 1) ?>/5</div>
                <div class="text-[#4B5563] dark:text-gray-400">Average Rating</div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php if (!empty($categories)): ?>
<!-- ========== BROWSE BY CATEGORY ========== -->
<section class="py-16 bg-[#F3F4F6] dark:bg-gray-900">
    <div class="container mx-auto px-4">
        <div class="text-center max-w-2xl mx-auto mb-12" data-aos="fade-up">
            <h2 class="text-3xl md:text-4xl font-bold mb-3"><span class="gradient-text">Browse by Category</span></h2>
            <p class="text-[#6B7280] dark:text-gray-400">Explore opportunities in the fields that match your skills.</p>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 md:gap-6">
            <?php foreach ($categories as $i => $cat): ?>
            <a href="/jobs.php?category=<?= urlencode((string)$cat['job_category']) ?>"
               class="category-card bg-white dark:bg-gray-800 rounded-xl p-5 text-center block"
               data-aos="fade-up" data-aos-delay="<?= min($i * 50, 300) ?>">
                <div class="w-14 h-14 brand-gradient rounded-xl flex items-center justify-center mx-auto mb-3 shadow-md">
                    <i class="fas <?= esc(home_category_icon($cat['job_category'])) ?> text-white text-xl"></i>
                </div>
                <h3 class="font-semibold text-[#111827] dark:text-white"><?= esc($cat['job_category']) ?></h3>
                <p class="text-sm text-[#6B7280] dark:text-gray-400 mt-1"><?= (int)$cat['cnt'] ?> open <?= (int)$cat['cnt'] === 1 ? 'job' : 'jobs' ?></p>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ========== LATEST JOBS ========== -->
<section class="py-16 bg-white dark:bg-gray-800 border-y border-gray-200 dark:border-gray-700">
    <div class="container mx-auto px-4">
        <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-4 mb-10" data-aos="fade-up">
            <div>
                <h2 class="text-3xl md:text-4xl font-bold mb-2"><span class="gradient-text">Latest Opportunities</span></h2>
                <p class="text-[#6B7280] dark:text-gray-400">Fresh openings from employers across Ghana.</p>
            </div>
            <a href="/jobs.php" class="inline-flex items-center text-[#1E3A8A] dark:text-[#60A5FA] font-semibold hover:underline">
                View all jobs <i class="fas fa-arrow-right ml-2"></i>
            </a>
        </div>

        <?php if (empty($latestJobs)): ?>
            <div class="text-center py-14 bg-[#F3F4F6] dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-700">
                <i class="fas fa-briefcase text-5xl text-gray-400 mb-4"></i>
                <h3 class="text-xl font-semibold mb-2">No open positions right now</h3>
                <p class="text-[#6B7280] dark:text-gray-400 mb-6">New jobs are added regularly. Check back soon or create a profile to get started.</p>
                <a href="/register.php" class="btn-primary inline-flex items-center"><i class="fas fa-user-plus mr-2"></i> Create Your Profile</a>
            </div>
        <?php else: ?>
            <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php foreach ($latestJobs as $i => $job):
                    $locationType = (string)($job['location_type'] ?? '');
                    $summary = home_excerpt(($job['overview'] ?? '') !== '' ? $job['overview'] : ($job['description'] ?? ''), 110);
                    $place = !empty($job['city']) ? $job['city'] . ', Ghana' : 'Ghana';
                ?>
                <article class="job-card bg-white dark:bg-gray-800 rounded-xl overflow-hidden flex flex-col" data-aos="fade-up" data-aos-delay="<?= min($i * 50, 250) ?>">
                    <div class="h-1.5 brand-gradient"></div>
                    <div class="p-5 flex flex-col flex-1">
                        <div class="flex flex-wrap gap-2 mb-3">
                            <?php if (!empty($job['is_urgent'])): ?><span class="badge-urgent"><i class="fas fa-bolt mr-1"></i>Urgent</span><?php endif; ?>
                            <?php if (!empty($job['is_featured'])): ?><span class="badge-featured"><i class="fas fa-star mr-1"></i>Featured</span><?php endif; ?>
                            <?php if ($locationType === 'Remote'): ?><span class="badge-remote"><i class="fas fa-house-laptop mr-1"></i>Remote</span>
                            <?php elseif ($locationType === 'Hybrid'): ?><span class="badge-remote"><i class="fas fa-rotate mr-1"></i>Hybrid</span><?php endif; ?>
                            <?php if (!empty($job['job_category'])): ?><span class="badge-category"><i class="fas fa-tag mr-1"></i><?= esc($job['job_category']) ?></span><?php endif; ?>
                        </div>

                        <h3 class="font-bold text-lg mb-2">
                            <a href="/job-detail.php?id=<?= (int)$job['id'] ?>" class="hover:text-[#2563EB] transition"><?= esc($job['title']) ?></a>
                        </h3>

                        <?php if ($summary !== ''): ?>
                        <p class="text-sm text-[#4B5563] dark:text-gray-300 mb-4 line-clamp-2"><?= esc($summary) ?></p>
                        <?php endif; ?>

                        <div class="grid grid-cols-2 gap-2 text-sm text-[#4B5563] dark:text-gray-400 mb-4">
                            <div class="flex items-center"><i class="fas fa-briefcase w-5 text-blue-500"></i><span><?= esc($job['employment_type'] ?? '') ?></span></div>
                            <div class="flex items-center"><i class="fas fa-map-marker-alt w-5 text-blue-500"></i><span><?= esc($place) ?></span></div>
                        </div>

                        <div class="mt-auto flex items-center justify-between pt-4 border-t border-gray-100 dark:border-gray-700">
                            <div class="text-green-600 dark:text-green-400 font-semibold text-sm">
                                <i class="fas fa-money-bill-wave mr-1"></i><?= esc(home_salary($job['salary_min'] ?? 0, $job['salary_max'] ?? 0, $job['salary_currency'] ?? '', $job['salary_type'] ?? '')) ?>
                            </div>
                            <span class="text-xs text-gray-500 dark:text-gray-400"><i class="far fa-clock mr-1"></i><?= esc(home_time_ago($job['created_at'] ?? null)) ?></span>
                        </div>
                    </div>
                </article>
                <?php endforeach; ?>
            </div>

            <div class="text-center mt-10">
                <a href="/jobs.php" class="btn-primary inline-flex items-center space-x-2 px-8 py-4">
                    <span>Browse All <?= $totalJobs > 0 ? number_format($totalJobs) . ' ' : '' ?>Jobs</span><i class="fas fa-arrow-right"></i>
                </a>
            </div>
        <?php endif; ?>
    </div>
</section>

<!-- ========== HOW IT WORKS ========== -->
<section class="py-16 bg-[#F3F4F6] dark:bg-gray-900">
    <div class="container mx-auto px-4">
        <div class="text-center max-w-2xl mx-auto mb-12" data-aos="fade-up">
            <h2 class="text-3xl md:text-4xl font-bold mb-3"><span class="gradient-text">How TransNow Works</span></h2>
            <p class="text-[#6B7280] dark:text-gray-400">Three simple steps from sign-up to your next role.</p>
        </div>

        <div class="grid md:grid-cols-3 gap-8 max-w-5xl mx-auto">
            <?php
            $steps = [
                ['fa-user-plus',        'Create Your Profile', 'Register and build your profile so employers can see your skills, experience and language abilities.'],
                ['fa-magnifying-glass', 'Find &amp; Apply',    'Search and filter jobs by category, location and salary, save the ones you like, and apply in a few clicks.'],
                ['fa-handshake',        'Interview &amp; Get Hired', 'Track your applications, manage interviews and message employers directly from your dashboard.'],
            ];
            foreach ($steps as $n => [$icon, $title, $text]): ?>
            <div class="text-center bg-white dark:bg-gray-800 rounded-2xl p-8 border border-gray-200 dark:border-gray-700" data-aos="fade-up" data-aos-delay="<?= $n * 100 ?>">
                <div class="relative w-16 h-16 brand-gradient rounded-2xl flex items-center justify-center mx-auto mb-5 shadow-lg">
                    <i class="fas <?= $icon ?> text-white text-2xl"></i>
                    <span class="absolute -top-2 -right-2 w-7 h-7 rounded-full bg-[#10B981] text-white text-sm font-bold flex items-center justify-center"><?= $n + 1 ?></span>
                </div>
                <h3 class="text-xl font-bold mb-2"><?= $title ?></h3>
                <p class="text-[#6B7280] dark:text-gray-400"><?= $text ?></p>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<?php if (!empty($testimonials)): ?>
<!-- ========== SUCCESS STORIES ========== -->
<section class="py-16 bg-white dark:bg-gray-800 border-y border-gray-200 dark:border-gray-700">
    <div class="container mx-auto px-4">
        <div class="text-center max-w-2xl mx-auto mb-12" data-aos="fade-up">
            <h2 class="text-3xl md:text-4xl font-bold mb-3"><span class="gradient-text">Success Stories</span></h2>
            <p class="text-[#6B7280] dark:text-gray-400">Real people who found their next role through TransNow.</p>
        </div>

        <div class="grid md:grid-cols-3 gap-6">
            <?php foreach ($testimonials as $i => $t): ?>
            <div class="story-card bg-white dark:bg-gray-800 rounded-xl p-6 flex flex-col" data-aos="fade-up" data-aos-delay="<?= $i * 100 ?>">
                <div class="flex mb-3"><?= home_stars($t['rating'] ?? 5) ?></div>
                <p class="text-[#4B5563] dark:text-gray-300 leading-relaxed mb-5 flex-1">&ldquo;<?= esc(home_excerpt($t['content'] ?? '', 220)) ?>&rdquo;</p>
                <div class="flex items-center pt-4 border-t border-gray-100 dark:border-gray-700">
                    <div class="w-12 h-12 brand-gradient rounded-full flex items-center justify-center text-white font-bold text-lg flex-shrink-0 shadow-md">
                        <?= esc(home_initial($t['author_name'] ?? '')) ?>
                    </div>
                    <div class="ml-3">
                        <h4 class="font-bold"><?= esc($t['author_name'] ?? '') ?></h4>
                        <p class="text-xs text-[#6B7280] dark:text-gray-400"><?= esc($t['author_title'] ?: 'Job Seeker') ?></p>
                        <?php if (!empty($t['author_company'])): ?>
                        <p class="text-xs text-[#1E3A8A] dark:text-[#60A5FA] font-medium">at <?= esc($t['author_company']) ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="text-center mt-10">
            <a href="/testimonials.php" class="inline-flex items-center text-[#1E3A8A] dark:text-[#60A5FA] font-semibold hover:underline">
                Read more success stories <i class="fas fa-arrow-right ml-2"></i>
            </a>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ========== JOB SEEKERS & EMPLOYERS ========== -->
<section class="py-16 bg-[#F3F4F6] dark:bg-gray-900">
    <div class="container mx-auto px-4">
        <div class="grid md:grid-cols-2 gap-8 max-w-5xl mx-auto">
            <div class="rounded-2xl p-8 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700" data-aos="fade-right">
                <div class="w-14 h-14 brand-gradient rounded-xl flex items-center justify-center mb-4 shadow-md"><i class="fas fa-user-tie text-white text-xl"></i></div>
                <h3 class="text-2xl font-bold mb-2">Looking for work?</h3>
                <p class="text-[#6B7280] dark:text-gray-400 mb-6">Find roles that value your skills, including positions with Chinese companies operating in Ghana.</p>
                <div class="flex flex-wrap gap-3">
                    <a href="/jobs.php" class="btn-primary inline-flex items-center"><i class="fas fa-search mr-2"></i> Find Jobs</a>
                    <?php if (!$isLoggedIn): ?>
                    <a href="/register.php" class="inline-flex items-center px-6 py-3 rounded-xl border border-[#1E3A8A] text-[#1E3A8A] dark:text-[#60A5FA] dark:border-[#60A5FA] font-semibold hover:bg-[#1E3A8A] hover:text-white transition">Create Profile</a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="rounded-2xl p-8 brand-gradient text-white" data-aos="fade-left">
                <div class="w-14 h-14 bg-white/20 rounded-xl flex items-center justify-center mb-4"><i class="fas fa-building text-white text-xl"></i></div>
                <h3 class="text-2xl font-bold mb-2">Hiring in Ghana?</h3>
                <p class="text-white/90 mb-6">Post openings, review applications and connect with qualified Ghanaian candidates.</p>
                <a href="<?= esc($postJobUrl) ?>" class="btn-accent inline-flex items-center"><i class="fas fa-bullhorn mr-2"></i> Post a Job</a>
            </div>
        </div>
    </div>
</section>

<!-- ========== CAREER ADVICE ========== -->
<section class="py-14 bg-white dark:bg-gray-800 border-t border-gray-200 dark:border-gray-700">
    <div class="container mx-auto px-4">
        <div class="max-w-3xl mx-auto text-center" data-aos="zoom-in">
            <div class="w-20 h-20 bg-[#10B981]/10 rounded-full flex items-center justify-center mx-auto mb-6">
                <i class="fas fa-lightbulb text-3xl text-[#10B981]"></i>
            </div>
            <h2 class="text-3xl md:text-4xl font-bold mb-4"><span class="gradient-text">Career Advice</span></h2>
            <p class="text-[#6B7280] dark:text-gray-400 text-lg mb-6">Practical guidance to help you prepare for your next role.</p>
            <a href="/blog.php" class="btn-accent inline-flex items-center space-x-2 px-8 py-4 text-lg"><i class="fas fa-book-open"></i><span>Read the Blog</span></a>
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
                        <a href="https://wa.me/8619157688742" target="_blank" rel="noopener" class="hover:text-white transition">+86 191 5768 8742 (WhatsApp)</a>
                    </div>
                    <div class="flex items-center space-x-3 text-gray-400">
                        <i class="fab fa-weixin text-[#07C160] w-5"></i>
                        <a href="https://u.wechat.com/EPqmYQJJDuNvJgHqgtMy00E?s=3" target="_blank" rel="noopener" class="hover:text-white transition">WeChat: TransNow Ghana</a>
                    </div>
                </div>

                <!-- Affiliated Organization -->
                <div class="mb-4 p-3 bg-gray-800/50 rounded-lg border border-gray-700">
                    <p class="text-xs text-gray-400 mb-1">Affiliated Organization</p>
                    <a href="https://ci-ug.com" target="_blank" rel="noopener" class="flex items-center space-x-2 hover:text-white transition">
                        <i class="fas fa-university text-[#3B82F6]"></i>
                        <span class="font-medium text-gray-300">Confucius Institute at University of Ghana (CI-UG)</span>
                    </a>
                </div>

                <div class="flex space-x-4">
                    <a href="#" aria-label="Facebook" class="w-10 h-10 bg-gray-800 rounded-full flex items-center justify-center hover:bg-[#1E3A8A] transition"><i class="fab fa-facebook-f"></i></a>
                    <a href="#" aria-label="Twitter" class="w-10 h-10 bg-gray-800 rounded-full flex items-center justify-center hover:bg-[#1E3A8A] transition"><i class="fab fa-twitter"></i></a>
                    <a href="#" aria-label="LinkedIn" class="w-10 h-10 bg-gray-800 rounded-full flex items-center justify-center hover:bg-[#1E3A8A] transition"><i class="fab fa-linkedin-in"></i></a>
                    <a href="https://u.wechat.com/EPqmYQJJDuNvJgHqgtMy00E?s=3" target="_blank" rel="noopener" aria-label="WeChat" class="w-10 h-10 bg-gray-800 rounded-full flex items-center justify-center hover:bg-[#1E3A8A] transition"><i class="fab fa-weixin"></i></a>
                </div>
            </div>

            <div>
                <h4 class="font-semibold text-lg mb-4">Company</h4>
                <ul class="space-y-2 text-gray-400">
                    <li><a href="/about.php" class="hover:text-white transition">About Us</a></li>
                    <li><a href="/privacy.php" class="hover:text-white transition">Privacy Policy</a></li>
                    <li><a href="/terms.php" class="hover:text-white transition">Terms of Service</a></li>
                    <li><a href="https://ci-ug.com" target="_blank" rel="noopener" class="hover:text-white transition">CI-UG (Partner)</a></li>
                </ul>
            </div>

            <div>
                <h4 class="font-semibold text-lg mb-4">Connect With Us</h4>
                <ul class="space-y-2 text-gray-400">
                    <li><a href="https://wa.me/8619157688742" target="_blank" rel="noopener" class="hover:text-white transition flex items-center space-x-2">
                        <i class="fab fa-whatsapp text-[#25D366]"></i><span>WhatsApp</span>
                    </a></li>
                    <li><a href="https://u.wechat.com/EPqmYQJJDuNvJgHqgtMy00E?s=3" target="_blank" rel="noopener" class="hover:text-white transition flex items-center space-x-2">
                        <i class="fab fa-weixin text-[#07C160]"></i><span>WeChat</span>
                    </a></li>
                    <li><a href="tel:+8619157688742" class="hover:text-white transition flex items-center space-x-2">
                        <i class="fas fa-phone-alt text-[#10B981]"></i><span>Call Us</span>
                    </a></li>
                </ul>
            </div>
        </div>

        <div class="border-t border-gray-800 mt-12 pt-8 text-center text-gray-400 text-sm">
            <p>&copy; <?= date('Y') ?> TransNow Ghana. Bridging China &amp; Ghana <i class="fas fa-heart text-[#10B981]"></i> for a shared future.</p>
            <p class="mt-2 text-xs text-gray-500">Developed by <a href="https://nerd-spring.com" target="_blank" rel="noopener" class="hover:text-white transition">NerdSpring</a></p>
        </div>
    </div>
</footer>

<!-- ========== SCRIPTS ========== -->
<script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
<script>
    AOS.init({ duration: 800, once: true, offset: 50, disable: window.matchMedia('(prefers-reduced-motion: reduce)').matches });

    // Dark mode toggle (preference is shared with jobs.php via localStorage 'theme')
    (function () {
        var root = document.documentElement;
        var toggle = document.getElementById('darkModeToggle');
        var icon = document.getElementById('darkModeIcon');
        function syncIcon() {
            if (!icon) return;
            var dark = root.classList.contains('dark');
            icon.classList.toggle('fa-sun', dark);
            icon.classList.toggle('fa-moon', !dark);
        }
        syncIcon();
        if (toggle) {
            toggle.addEventListener('click', function () {
                root.classList.toggle('dark');
                try { localStorage.setItem('theme', root.classList.contains('dark') ? 'dark' : 'light'); } catch (e) {}
                syncIcon();
            });
        }
    })();

    // Mobile menu
    (function () {
        var btn = document.getElementById('mobileMenuBtn');
        var menu = document.getElementById('mobileMenu');
        if (!btn || !menu) return;
        btn.addEventListener('click', function () {
            menu.classList.toggle('hidden');
            var icon = btn.querySelector('i');
            if (icon) { icon.classList.toggle('fa-bars'); icon.classList.toggle('fa-times'); }
        });
    })();

    // Scroll progress + navbar shadow
    window.addEventListener('scroll', function () {
        var doc = document.documentElement;
        var height = doc.scrollHeight - doc.clientHeight;
        var bar = document.getElementById('scrollProgress');
        if (bar && height > 0) bar.style.width = ((doc.scrollTop / height) * 100) + '%';
        var nav = document.getElementById('navbar');
        if (nav) nav.classList.toggle('shadow-lg', window.scrollY > 50);
    });

    // Counter animation
    (function () {
        var counters = document.querySelectorAll('.counter');
        if (!('IntersectionObserver' in window)) return;
        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) return;
                var el = entry.target;
                var target = parseInt(el.getAttribute('data-target'), 10) || 0;
                var count = 0;
                var step = target / 50;
                (function tick() {
                    count += step;
                    if (count < target) { el.textContent = Math.floor(count); requestAnimationFrame(tick); }
                    else { el.textContent = target; }
                })();
                observer.unobserve(el);
            });
        }, { threshold: 0.5 });
        counters.forEach(function (c) { observer.observe(c); });
    })();
</script>
</body>
</html>
