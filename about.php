<?php
/**
 * about.php - TransNow Ghana: Bridging Chinese Employers & Ghanaian Talent
 * Version: 3.1 - Professional Blue & Green Theme
 * 
 * Color Scheme:
 * Primary: Deep Blue #1E3A8A
 * Secondary: Light Blue #3B82F6  
 * Accent (CTA): Green #10B981
 * Background: Soft Gray #F3F4F6
 * Text: Dark Gray #111827
 * 
 * TransNow specializes in facilitating communication and collaboration between
 * Chinese companies operating in Ghana and local Ghanaian professionals.
 * 
 * @author TransNow
 * @version 3.1
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/functions.php';

$pdo = getDB();

// Key statistics about China-Ghana cooperation
$chineseCompanies = 86; // Number of Chinese companies partnered with TransNow
$ghanaianStudentsInChina = 4600; // Source: 2021-2022 academic year 
$chineseInvestment = 10000; // Jobs created through Chinese investment 
$bilingualJobs = 342; // Active jobs requiring Chinese/English skills

// Fetch testimonials from bilingual professionals
$testimonials = fetchAll("
    SELECT author_name, author_title, author_company, content, rating, created_at
    FROM testimonials 
    WHERE is_active = 1 
    AND (content LIKE '%Chinese%' OR content LIKE '%Mandarin%' OR author_title LIKE '%bilingual%')
    ORDER BY created_at DESC 
    LIMIT 3
");

// If no bilingual testimonials exist, use default ones
if (empty($testimonials)) {
    $testimonials = [
        [
            'author_name' => 'Michael Asare',
            'author_title' => 'Bilingual Administrative Assistant',
            'author_company' => 'Chinese Construction Ltd',
            'content' => 'Learning Mandarin through TransNow\'s recommended resources helped me land a job with a Chinese company in Tema. The cultural training was invaluable.',
            'rating' => 5,
            'created_at' => '2025-01-15'
        ],
        [
            'author_name' => 'Ama Serwaa',
            'author_title' => 'Translator & Liaison Officer',
            'author_company' => 'Sino-Ghana Manufacturing',
            'content' => 'As a graduate of University of Ghana with Chinese language training, TransNow connected me with the perfect role where I use my Mandarin skills daily.',
            'rating' => 5,
            'created_at' => '2024-12-10'
        ],
        [
            'author_name' => 'Kwabena Osei',
            'author_title' => 'Sales Representative',
            'author_company' => 'Infinix Ghana',
            'content' => 'Working with Chinese colleagues requires understanding cultural nuances. TransNow prepared me for this and matched me with a company that values cross-cultural communication.',
            'rating' => 4,
            'created_at' => '2024-11-20'
        ]
    ];
}

// Fetch partner companies (Chinese firms operating in Ghana)
$partnerCompanies = [
    ['name' => 'Infinix', 'logo' => null, 'industry' => 'Technology'],
    ['name' => 'Hunan Isunte Group', 'logo' => null, 'industry' => 'Agriculture & Manufacturing'],
    ['name' => 'Anhui Feiying', 'logo' => null, 'industry' => 'Automotive'],
    ['name' => 'Sino-Hydro', 'logo' => null, 'industry' => 'Construction'],
    ['name' => 'China Railway', 'logo' => null, 'industry' => 'Infrastructure'],
    ['name' => 'Bank of China', 'logo' => null, 'industry' => 'Finance'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>About TransNow Ghana | Bridging Chinese Employers & Ghanaian Talent</title>
    <meta name="description" content="TransNow Ghana facilitates communication and collaboration between Chinese companies and Ghanaian professionals. Specializing in bilingual recruitment, cultural training, and cross-border career opportunities.">
    <meta name="keywords" content="China-Ghana jobs, Chinese companies Ghana, bilingual jobs Ghana, Mandarin speaking jobs, Ghana-China cooperation, Chinese employers Ghana">
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <!-- AOS Animation -->
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    
    <style>
        * { font-family: 'Inter', sans-serif; }
        
        /* Color Scheme Variables */
        :root {
            --primary: #1E3A8A;
            --primary-dark: #152C6B;
            --secondary: #3B82F6;
            --secondary-light: #60A5FA;
            --accent: #10B981;
            --accent-dark: #059669;
            --background: #F3F4F6;
            --text-dark: #111827;
            --text-gray: #4B5563;
            --text-light: #6B7280;
            --white: #FFFFFF;
            --border: #E5E7EB;
        }
        
        /* Professional Blue Gradient */
        .brand-gradient {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 50%, var(--secondary-light) 100%);
        }
        
        .gradient-text {
            background: linear-gradient(135deg, var(--primary), var(--secondary), var(--secondary-light));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }
        
        .stat-card {
            transition: all 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .bridge-card {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border: 1px solid var(--border);
        }
        
        .bridge-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            border-color: var(--primary);
        }
        
        .dark .bridge-card {
            border-color: #374151;
        }
        
        .dark .bridge-card:hover {
            border-color: var(--secondary);
        }
        
        .partner-card {
            transition: all 0.3s ease;
        }
        
        .partner-card:hover {
            transform: translateY(-3px);
        }
        
        /* Primary Button (Blue) */
        .btn-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
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
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary) 100%);
        }
        
        /* Accent Button (Green) */
        .btn-accent {
            background: var(--accent);
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
            background: var(--accent-dark);
        }
        
        /* Outline Button */
        .btn-outline {
            border: 2px solid var(--primary);
            color: var(--primary);
            font-weight: 600;
            padding: 0.75rem 1.5rem;
            border-radius: 0.75rem;
            transition: all 0.3s;
            background: transparent;
        }
        
        .btn-outline:hover {
            background: var(--primary);
            color: white;
            transform: translateY(-2px);
        }
        
        .nav-link {
            position: relative;
        }
        
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
        
        .nav-link:hover::after {
            width: 100%;
        }
        
        .primary-bg {
            background-color: var(--primary);
        }
        
        .secondary-bg {
            background-color: var(--secondary);
        }
        
        .accent-bg {
            background-color: var(--accent);
        }
        
        ::-webkit-scrollbar { width: 10px; }
        ::-webkit-scrollbar-track { background: var(--background); border-radius: 8px; }
        ::-webkit-scrollbar-thumb { background: linear-gradient(135deg, var(--primary), var(--secondary)); border-radius: 8px; }
        
        .dark ::-webkit-scrollbar-track { background: #1F2937; }
        
        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-15px); }
        }
        
        .animate-float { animation: float 6s ease-in-out infinite; }
        
        /* Flag decoration */
        .flag-decoration {
            background: linear-gradient(90deg, #CC0000 0%, #CC0000 33%, #FDB813 33%, #FDB813 66%, var(--primary) 66%, var(--primary) 100%);
            height: 4px;
        }
    </style>
</head>
<body class="bg-[#F3F4F6] dark:bg-gray-900 transition-colors duration-300">

<!-- Navigation -->
<nav class="fixed w-full z-50 bg-white/95 dark:bg-gray-900/95 backdrop-blur-md shadow-sm transition-all duration-300" id="navbar">
    <div class="container mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between items-center py-4">
            <a href="/" class="flex items-center space-x-3 group">
                <div class="relative">
                    <div class="w-10 h-10 brand-gradient rounded-xl flex items-center justify-center transform group-hover:rotate-12 transition-all duration-300">
                        <i class="fas fa-handshake text-white text-xl"></i>
                    </div>
                </div>
                <span class="text-2xl font-bold gradient-text">TransNow Ghana</span>
            </a>
            
            <div class="hidden md:flex items-center space-x-8">
                <a href="/" class="nav-link text-[#111827] dark:text-gray-200 font-medium hover:text-[#1E3A8A] transition">Home</a>
                <a href="/jobs.php" class="nav-link text-[#111827] dark:text-gray-200 font-medium hover:text-[#1E3A8A] transition">Find Jobs</a>
                <a href="/testimonials.php" class="nav-link text-[#111827] dark:text-gray-200 font-medium hover:text-[#1E3A8A] transition">Success Stories</a>
                <a href="/blog.php" class="nav-link text-[#111827] dark:text-gray-200 font-medium hover:text-[#1E3A8A] transition">Career Advice</a>
                <a href="/about.php" class="nav-link text-[#1E3A8A] dark:text-[#3B82F6] font-medium border-b-2 border-[#1E3A8A]">About</a>
            </div>
            
            <div class="flex items-center space-x-4">
                <a href="/login.php" class="hidden sm:inline-flex items-center space-x-2 px-5 py-2.5 border border-[#1E3A8A] text-[#1E3A8A] rounded-xl font-medium hover:bg-[#1E3A8A] hover:text-white transition">
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
            <a href="/testimonials.php" class="block py-2 px-4 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800">Success Stories</a>
            <a href="/blog.php" class="block py-2 px-4 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800">Career Advice</a>
            <a href="/about.php" class="block py-2 px-4 rounded-lg bg-blue-50 dark:bg-blue-900/30 text-[#1E3A8A]">About</a>
            <a href="/login.php" class="block py-2 px-4 text-[#1E3A8A] border border-[#1E3A8A] rounded-lg">Employer Portal</a>
        </div>
    </div>
</nav>

<!-- Decorative China-Ghana flag bar -->
<div class="fixed top-0 left-0 w-full h-1 z-50 flag-decoration"></div>

<!-- Scroll Progress Bar -->
<div class="fixed top-0 left-0 w-full h-1 bg-gray-200 dark:bg-gray-700 z-50">
    <div id="scrollProgress" class="h-full brand-gradient w-0 transition-all duration-300"></div>
</div>

<!-- ========== HERO SECTION ========== -->
<section class="relative pt-32 pb-20 brand-gradient overflow-hidden">
    <div class="absolute inset-0 opacity-10">
        <i class="fas fa-handshake absolute text-9xl top-20 left-10 text-white"></i>
        <i class="fas fa-language absolute text-9xl bottom-10 right-10 text-white"></i>
        <i class="fas fa-globe absolute text-8xl top-40 right-[20%] text-white"></i>
        <i class="fas fa-briefcase absolute text-7xl bottom-32 left-[15%] text-white"></i>
    </div>
    
    <div class="container mx-auto px-4 relative z-10">
        <div class="max-w-4xl mx-auto text-center" data-aos="fade-up">
            <div class="inline-flex items-center space-x-3 bg-white/20 backdrop-blur-sm rounded-full px-5 py-2.5 mb-6">
                <i class="fas fa-handshake text-white"></i>
                <span class="text-sm font-semibold text-white">🇨🇳 🇬🇭 Bridging China & Ghana</span>
            </div>
            <h1 class="text-4xl md:text-5xl lg:text-6xl font-extrabold text-white mb-6">
                Connecting Chinese Employers with Ghana's Finest Talent
            </h1>
            <p class="text-xl text-white/90 max-w-2xl mx-auto">
                TransNow Ghana is the premier platform facilitating communication, cultural understanding, and career opportunities between Chinese companies and Ghanaian professionals.
            </p>
        </div>
    </div>
    
    <div class="relative -mb-1 mt-16">
        <svg class="w-full h-16 text-[#F3F4F6] dark:text-gray-900 fill-current" preserveAspectRatio="none" viewBox="0 0 1200 120">
            <path d="M321.39 56.44c58-10.79 114.16-30.13 172-41.86 82.39-16.72 168.19-17.73 250.45-.39C823.78 31 906.67 72 985.66 92.83c70.05 18.48 146.53 26.09 214.34 3V0H0v27.35a600.21 600.21 0 00321.39 29.09z"></path>
        </svg>
    </div>
</section>

<!-- ========== KEY STATISTICS ========== -->
<section class="py-16 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700">
    <div class="container mx-auto px-4">
        <div class="text-center mb-10" data-aos="fade-up">
            <h2 class="text-2xl md:text-3xl font-bold mb-2">
                <span class="gradient-text">The China-Ghana Partnership in Numbers</span>
            </h2>
            <p class="text-[#4B5563] dark:text-gray-400">China remains Africa's largest trading partner for 13 consecutive years</p>
        </div>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-8">
            <div class="text-center stat-card" data-aos="fade-up">
                <div class="text-4xl font-bold gradient-text mb-2"><?php echo $ghanaianStudentsInChina; ?>+</div>
                <div class="text-[#4B5563] dark:text-gray-400">Ghanaians Studying in China</div>
                <div class="text-xs text-[#6B7280] mt-1">2nd highest in Africa</div>
            </div>
            <div class="text-center stat-card" data-aos="fade-up" data-aos-delay="100">
                <div class="text-4xl font-bold gradient-text mb-2">10,000+</div>
                <div class="text-[#4B5563] dark:text-gray-400">Jobs Created Through Chinese Investment</div>
                <div class="text-xs text-[#6B7280] mt-1">In mining & agriculture sectors</div>
            </div>
            <div class="text-center stat-card" data-aos="fade-up" data-aos-delay="200">
                <div class="text-4xl font-bold gradient-text mb-2">10,000+</div>
                <div class="text-[#4B5563] dark:text-gray-400">Ghanaians Learning Chinese</div>
                <div class="text-xs text-[#6B7280] mt-1">Across all levels</div>
            </div>
            <div class="text-center stat-card" data-aos="fade-up" data-aos-delay="300">
                <div class="text-4xl font-bold gradient-text mb-2">86</div>
                <div class="text-[#4B5563] dark:text-gray-400">Chinese Companies Partnered</div>
                <div class="text-xs text-[#6B7280] mt-1">Across Ghana's 16 regions</div>
            </div>
        </div>
    </div>
</section>

<!-- ========== OUR MISSION - BRIDGING THE GAP ========== -->
<section class="py-20 bg-[#F3F4F6] dark:bg-gray-900">
    <div class="container mx-auto px-4">
        <div class="grid lg:grid-cols-2 gap-12 items-center">
            <div data-aos="fade-right">
                <div class="inline-flex items-center space-x-2 bg-blue-100 dark:bg-blue-900/30 rounded-full px-4 py-2 mb-6">
                    <i class="fas fa-bridge text-[#1E3A8A]"></i>
                    <span class="text-sm font-semibold text-[#1E3A8A] dark:text-blue-300">Our Mission</span>
                </div>
                <h2 class="text-3xl md:text-4xl font-bold mb-6">
                    <span class="gradient-text">Bridging Cultures, Building Careers</span>
                </h2>
                <p class="text-lg text-[#4B5563] dark:text-gray-300 mb-6 leading-relaxed">
                    Ghana and China share a growing economic partnership. With over <strong class="text-[#1E3A8A]">4,600 Ghanaian students</strong> currently studying in China and <strong class="text-[#1E3A8A]">10,000+ Ghanaians learning Chinese</strong>, the demand for cross-cultural communication has never been greater.
                </p>
                <p class="text-[#4B5563] dark:text-gray-400 mb-8 leading-relaxed">
                    TransNow Ghana was founded to bridge this gap. We specialize in connecting Chinese companies operating in Ghana with qualified Ghanaian professionals who understand both business cultures. Whether you're a Chinese employer seeking local talent or a Ghanaian professional with Mandarin skills, we're your trusted partner.
                </p>
                
                <div class="flex flex-wrap gap-4">
                    <div class="flex items-center space-x-3">
                        <div class="w-12 h-12 bg-[#1E3A8A] rounded-full flex items-center justify-center text-white">
                            <i class="fas fa-language"></i>
                        </div>
                        <div>
                            <p class="font-semibold text-[#111827] dark:text-white">Bilingual Recruitment</p>
                            <p class="text-sm text-[#6B7280]">Mandarin/English specialists</p>
                        </div>
                    </div>
                    <div class="flex items-center space-x-3">
                        <div class="w-12 h-12 bg-[#10B981] rounded-full flex items-center justify-center text-white">
                            <i class="fas fa-handshake"></i>
                        </div>
                        <div>
                            <p class="font-semibold text-[#111827] dark:text-white">Cultural Training</p>
                            <p class="text-sm text-[#6B7280]">For both sides</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="relative" data-aos="fade-left">
                <div class="bg-gradient-to-br from-[#1E3A8A]/10 to-[#3B82F6]/10 rounded-3xl p-1">
                    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl overflow-hidden border border-gray-200 dark:border-gray-700">
                        <img src="https://images.unsplash.com/photo-1522071820081-009f0129c71c?w=600&h=400&fit=crop" 
                             alt="Chinese and Ghanaian professionals collaborating" 
                             class="w-full h-64 object-cover">
                        <div class="p-6">
                            <div class="flex items-center space-x-2 mb-3">
                                <i class="fas fa-quote-left text-[#1E3A8A] text-2xl"></i>
                                <p class="text-[#111827] dark:text-white italic">"It is better to travel ten thousand miles than to read ten thousand books."</p>
                            </div>
                            <p class="text-sm text-[#6B7280]">— Chinese Ambassador Lu Kun, encouraging Ghanaian students to study in China</p>
                        </div>
                    </div>
                </div>
                
                <!-- Floating element -->
                <div class="absolute -top-5 -right-5 bg-white dark:bg-gray-800 rounded-xl shadow-lg p-3 animate-float border border-gray-200 dark:border-gray-700">
                    <div class="flex items-center space-x-2">
                        <div class="flex space-x-1">
                            <span class="text-xl">🇨🇳</span>
                            <span class="text-xl">🇬🇭</span>
                        </div>
                        <div>
                            <div class="font-bold text-lg text-[#111827] dark:text-white">86%</div>
                            <div class="text-xs text-[#6B7280]">Cross-cultural success rate</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ========== HOW WE BRIDGE THE GAP ========== -->
<section class="py-20 bg-white dark:bg-gray-800">
    <div class="container mx-auto px-4">
        <div class="text-center mb-12" data-aos="fade-up">
            <span class="text-[#1E3A8A] font-semibold text-sm uppercase tracking-wider">Our Services</span>
            <h2 class="text-3xl md:text-4xl font-bold mt-3 mb-4">
                <span class="gradient-text">How We Bridge the Communication Gap</span>
            </h2>
            <p class="text-[#6B7280] dark:text-gray-400 max-w-2xl mx-auto">
                Specialized services designed to facilitate smooth collaboration between Chinese employers and Ghanaian talent
            </p>
        </div>
        
        <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-6">
            <!-- Service 1: Bilingual Job Postings -->
            <div class="bridge-card bg-[#F9FAFB] dark:bg-gray-900 rounded-xl p-6 hover:shadow-lg transition" data-aos="fade-up">
                <div class="w-14 h-14 bg-[#1E3A8A] rounded-xl flex items-center justify-center mb-4">
                    <i class="fas fa-language text-white text-2xl"></i>
                </div>
                <h3 class="font-bold text-xl mb-2 text-[#111827] dark:text-white">Bilingual Job Postings</h3>
                <p class="text-[#6B7280] dark:text-gray-400 mb-3">
                    All job listings are presented in both English and Chinese, ensuring clear communication of requirements, expectations, and benefits.
                </p>
                <div class="flex items-center text-sm text-[#10B981]">
                    <i class="fas fa-check-circle mr-2"></i>
                    <span>English & Chinese job descriptions</span>
                </div>
            </div>
            
            <!-- Service 2: Cultural Training -->
            <div class="bridge-card bg-[#F9FAFB] dark:bg-gray-900 rounded-xl p-6 hover:shadow-lg transition" data-aos="fade-up" data-aos-delay="100">
                <div class="w-14 h-14 bg-[#3B82F6] rounded-xl flex items-center justify-center mb-4">
                    <i class="fas fa-chalkboard-user text-white text-2xl"></i>
                </div>
                <h3 class="font-bold text-xl mb-2 text-[#111827] dark:text-white">Cross-Cultural Training</h3>
                <p class="text-[#6B7280] dark:text-gray-400 mb-3">
                    Preparing Ghanaian professionals for Chinese workplace culture and helping Chinese employers understand Ghanaian business etiquette.
                </p>
                <div class="flex items-center text-sm text-[#10B981]">
                    <i class="fas fa-check-circle mr-2"></i>
                    <span>Workplace culture & communication workshops</span>
                </div>
            </div>
            
            <!-- Service 3: Translation Support -->
            <div class="bridge-card bg-[#F9FAFB] dark:bg-gray-900 rounded-xl p-6 hover:shadow-lg transition" data-aos="fade-up" data-aos-delay="200">
                <div class="w-14 h-14 brand-gradient rounded-xl flex items-center justify-center mb-4">
                    <i class="fas fa-file-alt text-white text-2xl"></i>
                </div>
                <h3 class="font-bold text-xl mb-2 text-[#111827] dark:text-white">Document Translation</h3>
                <p class="text-[#6B7280] dark:text-gray-400 mb-3">
                    Professional translation of CVs, cover letters, contracts, and company documents between English and Chinese.
                </p>
                <div class="flex items-center text-sm text-[#10B981]">
                    <i class="fas fa-check-circle mr-2"></i>
                    <span>Certified translation services</span>
                </div>
            </div>
            
            <!-- Service 4: Interview Preparation -->
            <div class="bridge-card bg-[#F9FAFB] dark:bg-gray-900 rounded-xl p-6 hover:shadow-lg transition" data-aos="fade-up" data-aos-delay="300">
                <div class="w-14 h-14 bg-[#1E3A8A] rounded-xl flex items-center justify-center mb-4">
                    <i class="fas fa-microphone-alt text-white text-2xl"></i>
                </div>
                <h3 class="font-bold text-xl mb-2 text-[#111827] dark:text-white">Interview Coaching</h3>
                <p class="text-[#6B7280] dark:text-gray-400 mb-3">
                    Specialized interview preparation for roles with Chinese companies, including common questions and cultural expectations.
                </p>
                <div class="flex items-center text-sm text-[#10B981]">
                    <i class="fas fa-check-circle mr-2"></i>
                    <span>Mock interviews & feedback sessions</span>
                </div>
            </div>
            
            <!-- Service 5: Employer Verification -->
            <div class="bridge-card bg-[#F9FAFB] dark:bg-gray-900 rounded-xl p-6 hover:shadow-lg transition" data-aos="fade-up" data-aos-delay="400">
                <div class="w-14 h-14 bg-[#3B82F6] rounded-xl flex items-center justify-center mb-4">
                    <i class="fas fa-shield-alt text-white text-2xl"></i>
                </div>
                <h3 class="font-bold text-xl mb-2 text-[#111827] dark:text-white">Verified Chinese Employers</h3>
                <p class="text-[#6B7280] dark:text-gray-400 mb-3">
                    All Chinese companies on our platform are vetted to ensure legitimate operations and fair employment practices in Ghana.
                </p>
                <div class="flex items-center text-sm text-[#10B981]">
                    <i class="fas fa-check-circle mr-2"></i>
                    <span>Background checks & registration verification</span>
                </div>
            </div>
            
            <!-- Service 6: Ongoing Support -->
            <div class="bridge-card bg-[#F9FAFB] dark:bg-gray-900 rounded-xl p-6 hover:shadow-lg transition" data-aos="fade-up" data-aos-delay="500">
                <div class="w-14 h-14 bg-[#10B981] rounded-xl flex items-center justify-center mb-4">
                    <i class="fas fa-headset text-white text-2xl"></i>
                </div>
                <h3 class="font-bold text-xl mb-2 text-[#111827] dark:text-white">Post-Placement Support</h3>
                <p class="text-[#6B7280] dark:text-gray-400 mb-3">
                    Continuous support after job placement to ensure smooth integration and address any cross-cultural challenges.
                </p>
                <div class="flex items-center text-sm text-[#10B981]">
                    <i class="fas fa-check-circle mr-2"></i>
                    <span>Follow-up & mediation services</span>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ========== PARTNERS & COLLABORATIONS ========== -->
<section class="py-20 bg-[#F3F4F6] dark:bg-gray-900">
    <div class="container mx-auto px-4">
        <div class="text-center mb-12" data-aos="fade-up">
            <span class="text-[#1E3A8A] font-semibold text-sm uppercase tracking-wider">Our Partners</span>
            <h2 class="text-3xl md:text-4xl font-bold mt-3 mb-4">
                <span class="gradient-text">Trusted by Leading China-Ghana Enterprises</span>
            </h2>
            <p class="text-[#6B7280] dark:text-gray-400 max-w-2xl mx-auto">
                We collaborate with reputable Chinese companies and institutions operating in Ghana
            </p>
        </div>
        
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
            <?php foreach ($partnerCompanies as $partner): ?>
            <div class="partner-card bg-white dark:bg-gray-800 rounded-xl p-4 text-center border border-gray-200 dark:border-gray-700">
                <div class="w-16 h-16 mx-auto bg-gray-100 dark:bg-gray-700 rounded-full flex items-center justify-center mb-2">
                    <i class="fas fa-building text-2xl text-[#1E3A8A]"></i>
                </div>
                <p class="font-semibold text-sm text-[#111827] dark:text-white"><?php echo $partner['name']; ?></p>
                <p class="text-xs text-[#6B7280]"><?php echo $partner['industry']; ?></p>
            </div>
            <?php endforeach; ?>
        </div>
        
        <div class="mt-8 text-center">
            <p class="text-sm text-[#6B7280]">
                Plus <strong class="text-[#1E3A8A]">80+</strong> other Chinese companies across manufacturing, construction, technology, and trade sectors
            </p>
        </div>
        
        <!-- New partnership announcement -->
        <div class="mt-8 bg-gradient-to-r from-blue-50 to-green-50 dark:from-blue-900/20 dark:to-green-900/20 rounded-xl p-4 border border-gray-200 dark:border-gray-700">
            <div class="flex items-center justify-center gap-3 flex-wrap">
                <span class="text-2xl">🎉</span>
                <p class="text-sm text-[#4B5563] dark:text-gray-300">
                    <strong class="text-[#1E3A8A]">New Partnership:</strong> TransNow Ghana is proud to collaborate with the newly launched 
                    <strong>Sichuan–Chongqing Ghana Chamber of Commerce</strong>, connecting Ghanaian talent with opportunities from two of China's most dynamic economic regions.
                </p>
            </div>
        </div>
    </div>
</section>

<!-- ========== SUCCESS STORIES (BILINGUAL PROFESSIONALS) ========== -->
<section class="py-20 bg-white dark:bg-gray-800">
    <div class="container mx-auto px-4">
        <div class="text-center mb-12" data-aos="fade-up">
            <span class="text-[#1E3A8A] font-semibold text-sm uppercase tracking-wider">Success Stories</span>
            <h2 class="text-3xl md:text-4xl font-bold mt-3 mb-4">
                <span class="gradient-text">Ghanaians Thriving in China-Ghana Workplaces</span>
            </h2>
            <p class="text-[#6B7280] dark:text-gray-400 max-w-2xl mx-auto">
                Real stories from professionals who found success through TransNow's China-Ghana connection
            </p>
        </div>
        
        <div class="grid md:grid-cols-3 gap-6">
            <?php foreach ($testimonials as $index => $story): ?>
            <div class="bg-[#F9FAFB] dark:bg-gray-900 rounded-xl p-6 shadow-sm border border-gray-200 dark:border-gray-700 hover:shadow-md transition transform hover:-translate-y-1" data-aos="fade-up" data-aos-delay="<?php echo $index * 100; ?>">
                <div class="flex items-center space-x-3 mb-4">
                    <div class="w-12 h-12 brand-gradient rounded-full flex items-center justify-center text-white font-bold text-lg flex-shrink-0">
                        <?php echo strtoupper(substr($story['author_name'], 0, 1)); ?>
                    </div>
                    <div>
                        <h4 class="font-bold text-[#111827] dark:text-white"><?php echo htmlspecialchars($story['author_name']); ?></h4>
                        <p class="text-xs text-[#6B7280]"><?php echo htmlspecialchars($story['author_title'] ?? 'Professional'); ?></p>
                        <?php if ($story['author_company']): ?>
                        <p class="text-xs text-[#1E3A8A]">at <?php echo htmlspecialchars($story['author_company']); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="flex mb-3 text-[#F59E0B]">
                    <?php for($i = 1; $i <= 5; $i++): ?>
                        <i class="fas fa-star<?php echo $i <= ($story['rating'] ?? 5) ? '' : '-o'; ?> text-sm"></i>
                    <?php endfor; ?>
                </div>
                <p class="text-[#4B5563] dark:text-gray-300 text-sm italic leading-relaxed">"<?php echo htmlspecialchars(substr($story['content'], 0, 200)) . (strlen($story['content']) > 200 ? '...' : ''); ?>"</p>
            </div>
            <?php endforeach; ?>
        </div>
        
        <div class="text-center mt-10">
            <a href="/testimonials.php" class="inline-flex items-center space-x-2 text-[#1E3A8A] font-semibold hover:space-x-3 transition">
                <span>Read More Success Stories</span>
                <i class="fas fa-arrow-right"></i>
            </a>
        </div>
    </div>
</section>

<!-- ========== CHINA-GHANA COOPERATION HIGHLIGHTS ========== -->
<section class="py-20 bg-[#F3F4F6] dark:bg-gray-900">
    <div class="container mx-auto px-4">
        <div class="grid lg:grid-cols-2 gap-12 items-center">
            <div data-aos="fade-right">
                <div class="inline-flex items-center space-x-2 bg-blue-100 dark:bg-blue-900/30 rounded-full px-4 py-2 mb-6">
                    <i class="fas fa-chart-line text-[#1E3A8A]"></i>
                    <span class="text-sm font-semibold text-[#1E3A8A] dark:text-blue-300">Growing Partnership</span>
                </div>
                <h2 class="text-3xl md:text-4xl font-bold mb-6">
                    <span class="gradient-text">A Future of Shared Prosperity</span>
                </h2>
                <p class="text-lg text-[#4B5563] dark:text-gray-300 mb-4 leading-relaxed">
                    The partnership between Ghana and China continues to strengthen. Recent initiatives include:
                </p>
                <ul class="space-y-3 mb-6">
                    <li class="flex items-start gap-3">
                        <i class="fas fa-check-circle text-[#10B981] mt-1"></i>
                        <span class="text-[#4B5563] dark:text-gray-300"><strong>10,000+ jobs</strong> to be created in Ghana's mining communities through Chinese investment in Ramie plant cultivation and processing</span>
                    </li>
                    <li class="flex items-start gap-3">
                        <i class="fas fa-check-circle text-[#10B981] mt-1"></i>
                        <span class="text-[#4B5563] dark:text-gray-300"><strong>Joint manufacturing plant</strong> established between Anhui Feiying (China) and Majesty Group (Ghana) to develop Ghana's national car brand</span>
                    </li>
                    <li class="flex items-start gap-3">
                        <i class="fas fa-check-circle text-[#10B981] mt-1"></i>
                        <span class="text-[#4B5563] dark:text-gray-300"><strong>500 Chinese government scholarships</strong> offered to Ghanaian students annually</span>
                    </li>
                    <li class="flex items-start gap-3">
                        <i class="fas fa-check-circle text-[#10B981] mt-1"></i>
                        <span class="text-[#4B5563] dark:text-gray-300"><strong>Sichuan-Chongqing Ghana Chamber of Commerce</strong> launched to boost trade, investment, and industrial collaboration</span>
                    </li>
                </ul>
                <p class="text-[#4B5563] dark:text-gray-400 leading-relaxed">
                    As Chinese Ambassador Tong Defa stated at the Sichuan-Chongqing Ghana Chamber launch: 
                    <strong class="text-[#1E3A8A]">"China remains committed to supporting Ghana's industrial growth, infrastructure expansion, and digital transformation"</strong>.
                </p>
            </div>
            
            <div data-aos="fade-left">
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl overflow-hidden border border-gray-200 dark:border-gray-700">
                    <div class="brand-gradient p-6 text-white text-center">
                        <h3 class="text-2xl font-bold mb-2">China-Africa Cooperation</h3>
                        <p class="text-white/90">Forum on China-Africa Cooperation (FOCAC)</p>
                    </div>
                    <div class="p-6">
                        <div class="space-y-4">
                            <div class="flex justify-between items-center border-b border-gray-200 dark:border-gray-700 pb-3">
                                <span class="font-medium">China's trading partner rank in Africa</span>
                                <span class="font-bold text-[#1E3A8A]">#1 for 13 years</span>
                            </div>
                            <div class="flex justify-between items-center border-b border-gray-200 dark:border-gray-700 pb-3">
                                <span class="font-medium">Ghanaian students in China</span>
                                <span class="font-bold text-[#1E3A8A]">4,600+ (2nd in Africa)</span>
                            </div>
                            <div class="flex justify-between items-center border-b border-gray-200 dark:border-gray-700 pb-3">
                                <span class="font-medium">Chinese language learners in Ghana</span>
                                <span class="font-bold text-[#1E3A8A]">10,000+</span>
                            </div>
                            <div class="flex justify-between items-center border-b border-gray-200 dark:border-gray-700 pb-3">
                                <span class="font-medium">Chinese government scholarships (annual)</span>
                                <span class="font-bold text-[#1E3A8A]">500</span>
                            </div>
                            <div class="flex justify-between items-center pb-2">
                                <span class="font-medium">New jobs from Chinese investment</span>
                                <span class="font-bold text-[#10B981]">10,000+</span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Confucius Institute reference -->
                <div class="mt-6 bg-gradient-to-r from-[#1E3A8A] to-[#3B82F6] rounded-xl p-5 text-white">
                    <div class="flex items-center gap-3">
                        <i class="fas fa-university text-3xl"></i>
                        <div>
                            <p class="font-semibold">Confucius Institute at University of Ghana (CIUG)</p>
                            <p class="text-sm text-white/80 mt-1">A key partner in promoting Chinese language and cultural exchange in Ghana</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ========== CTA SECTION ========== -->
<section class="py-20 brand-gradient relative overflow-hidden">
    <div class="absolute inset-0 opacity-10">
        <i class="fas fa-handshake absolute text-9xl top-10 left-10 text-white"></i>
        <i class="fas fa-language absolute text-9xl bottom-10 right-10 text-white"></i>
    </div>
    <div class="container mx-auto px-4 text-center relative z-10">
        <div class="flex justify-center gap-4 mb-6">
            <span class="text-5xl">🇨🇳</span>
            <span class="text-3xl text-white">+</span>
            <span class="text-5xl">🇬🇭</span>
        </div>
        <h2 class="text-3xl md:text-4xl font-bold text-white mb-4">Ready to Bridge the Gap?</h2>
        <p class="text-white/90 text-lg mb-8 max-w-2xl mx-auto">
            Whether you're a Chinese company seeking local talent or a Ghanaian professional ready to work with Chinese employers, TransNow is your bridge to success.
        </p>
        <div class="flex flex-wrap justify-center gap-4">
            <a href="/jobs.php" class="bg-white text-[#1E3A8A] px-8 py-4 rounded-xl font-semibold hover:shadow-xl transition transform hover:-translate-y-1">
                Find China-Ghana Jobs <i class="fas fa-arrow-right ml-2"></i>
            </a>
            <a href="/contact.php" class="border-2 border-white text-white px-8 py-4 rounded-xl font-semibold hover:bg-white/10 transition transform hover:-translate-y-1">
                Partner With Us <i class="fas fa-handshake ml-2"></i>
            </a>
        </div>
        <p class="text-white/80 text-sm mt-6">
            For Chinese employers: Post bilingual job listings and find qualified Ghanaian talent
        </p>
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
                        <a href="tel:+2330241334520" class="hover:text-white transition">+233 24 133 4520</a>
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

<!-- Scripts -->
<script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>

<script>
    // Initialize AOS
    AOS.init({
        duration: 1000,
        once: false,
        mirror: true
    });
    
    // Mobile menu toggle
    const mobileBtn = document.getElementById('mobileMenuBtn');
    const mobileMenu = document.getElementById('mobileMenu');
    if (mobileBtn) {
        mobileBtn.addEventListener('click', () => {
            mobileMenu.classList.toggle('hidden');
            const icon = mobileBtn.querySelector('i');
            icon.classList.toggle('fa-bars');
            icon.classList.toggle('fa-times');
        });
    }
    
    // Dark mode detection
    if (localStorage.getItem('theme') === 'dark' || (!localStorage.getItem('theme') && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
        document.documentElement.classList.add('dark');
    }
    
    // Scroll progress bar
    window.addEventListener('scroll', () => {
        const winScroll = document.documentElement.scrollTop;
        const height = document.documentElement.scrollHeight - document.documentElement.clientHeight;
        const scrolled = (winScroll / height) * 100;
        const progressBar = document.getElementById('scrollProgress');
        if (progressBar) {
            progressBar.style.width = scrolled + '%';
        }
        
        const navbar = document.getElementById('navbar');
        if (navbar) {
            if (window.scrollY > 50) {
                navbar.classList.add('shadow-lg');
            } else {
                navbar.classList.remove('shadow-lg');
            }
        }
    });
    
    // Counter animation
    const counters = document.querySelectorAll('.counter');
    const observerOptions = { threshold: 0.5, rootMargin: "0px" };
    
    const counterObserver = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const counter = entry.target;
                const target = parseInt(counter.getAttribute('data-target'));
                let count = 0;
                const increment = target / 60;
                
                const updateCounter = () => {
                    count += increment;
                    if (count < target) {
                        counter.innerText = Math.ceil(count);
                        requestAnimationFrame(updateCounter);
                    } else {
                        counter.innerText = target;
                    }
                };
                updateCounter();
                counterObserver.unobserve(counter);
            }
        });
    }, observerOptions);
    
    counters.forEach(counter => counterObserver.observe(counter));
</script>
</body>
</html>