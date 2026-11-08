<?php
/**
 * terms.php - TransNow Ghana Terms of Service
 * Version: 1.0 - Professional Blue & Green Theme
 * 
 * @author TransNow
 * @version 1.0
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/functions.php';

$pdo = getDB();

$currentYear = date('Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Terms of Service | TransNow Ghana</title>
    <meta name="description" content="Read the Terms of Service for TransNow Ghana. Learn about our policies, user agreements, and terms for job seekers and employers.">
    <meta name="keywords" content="terms of service, TransNow terms, Ghana job portal terms, user agreement">
    
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
            color: white;
            font-weight: 600;
            padding: 0.75rem 1.5rem;
            border-radius: 0.75rem;
            transition: all 0.3s;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(30, 58, 138, 0.3);
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
        
        .terms-card {
            border: 1px solid #E5E7EB;
            transition: all 0.3s ease;
        }
        
        .terms-card:hover {
            border-color: var(--primary);
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }
        
        ::-webkit-scrollbar { width: 10px; }
        ::-webkit-scrollbar-track { background: var(--background); border-radius: 8px; }
        ::-webkit-scrollbar-thumb { background: linear-gradient(135deg, var(--primary), var(--secondary)); border-radius: 8px; }
        
        .dark ::-webkit-scrollbar-track { background: #1F2937; }
    </style>
</head>
<body class="bg-[#F3F4F6] dark:bg-gray-900 transition-colors duration-300">

<!-- Navigation -->
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
                <a href="/" class="nav-link text-[#111827] dark:text-gray-200 font-medium hover:text-[#1E3A8A] transition">Home</a>
                <a href="/jobs.php" class="nav-link text-[#111827] dark:text-gray-200 font-medium hover:text-[#1E3A8A] transition">Find Jobs</a>
                <a href="/testimonials.php" class="nav-link text-[#111827] dark:text-gray-200 font-medium hover:text-[#1E3A8A] transition">Success Stories</a>
                <a href="/blog.php" class="nav-link text-[#111827] dark:text-gray-200 font-medium hover:text-[#1E3A8A] transition">Career Advice</a>
                <a href="/about.php" class="nav-link text-[#111827] dark:text-gray-200 font-medium hover:text-[#1E3A8A] transition">About</a>
                <a href="/contact.php" class="nav-link text-[#111827] dark:text-gray-200 font-medium hover:text-[#1E3A8A] transition">Contact</a>
            </div>
            
            <div class="flex items-center space-x-4">
                <a href="/login.php" class="hidden sm:inline-flex items-center space-x-2 px-5 py-2.5 border border-[#1E3A8A] text-[#1E3A8A] rounded-xl font-medium hover:bg-[#1E3A8A] hover:text-white transition">
                    <i class="fas fa-user-shield"></i>
                    <span>Employer Portal</span>
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
            <a href="/about.php" class="block py-2 px-4 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800">About</a>
            <a href="/contact.php" class="block py-2 px-4 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800">Contact</a>
            <a href="/login.php" class="block py-2 px-4 text-[#1E3A8A] border border-[#1E3A8A] rounded-lg">Employer Portal</a>
        </div>
    </div>
</nav>

<!-- Scroll Progress Bar -->
<div class="fixed top-0 left-0 w-full h-1 bg-gray-200 dark:bg-gray-700 z-50">
    <div id="scrollProgress" class="h-full brand-gradient w-0 transition-all duration-300"></div>
</div>

<!-- ========== HERO SECTION ========== -->
<section class="relative pt-32 pb-20 brand-gradient overflow-hidden">
    <div class="absolute inset-0 opacity-10">
        <i class="fas fa-file-contract absolute text-9xl top-20 left-10 text-white"></i>
        <i class="fas fa-gavel absolute text-8xl bottom-10 right-10 text-white"></i>
        <i class="fas fa-scale-balanced absolute text-7xl top-40 right-[20%] text-white"></i>
    </div>
    
    <div class="container mx-auto px-4 relative z-10">
        <div class="max-w-4xl mx-auto text-center" data-aos="fade-up">
            <div class="inline-flex items-center space-x-2 bg-white/20 backdrop-blur-sm rounded-full px-5 py-2.5 mb-6">
                <i class="fas fa-file-contract text-white"></i>
                <span class="text-sm font-semibold text-white">Legal Agreement</span>
            </div>
            <h1 class="text-4xl md:text-5xl lg:text-6xl font-extrabold text-white mb-6">
                Terms of Service
            </h1>
            <p class="text-xl text-white/90 max-w-2xl mx-auto">
                Please read these terms carefully before using the TransNow Ghana platform.
            </p>
            <p class="text-white/70 text-sm mt-4">Last Updated: January 1, <?php echo $currentYear; ?></p>
        </div>
    </div>
    
    <div class="relative -mb-1 mt-16">
        <svg class="w-full h-16 text-[#F3F4F6] dark:text-gray-900 fill-current" preserveAspectRatio="none" viewBox="0 0 1200 120">
            <path d="M321.39 56.44c58-10.79 114.16-30.13 172-41.86 82.39-16.72 168.19-17.73 250.45-.39C823.78 31 906.67 72 985.66 92.83c70.05 18.48 146.53 26.09 214.34 3V0H0v27.35a600.21 600.21 0 00321.39 29.09z"></path>
        </svg>
    </div>
</section>

<!-- ========== TERMS CONTENT ========== -->
<section class="py-16 bg-white dark:bg-gray-800">
    <div class="container mx-auto px-4">
        <div class="max-w-4xl mx-auto">
            
            <!-- Last Updated Notice -->
            <div class="bg-[#F3F4F6] dark:bg-gray-900 rounded-xl p-4 mb-8 text-center">
                <p class="text-sm text-[#6B7280] dark:text-gray-400">
                    <i class="fas fa-info-circle text-[#1E3A8A] mr-2"></i>
                    These Terms of Service constitute a legally binding agreement between you and TransNow Ghana.
                </p>
            </div>
            
            <!-- Table of Contents -->
            <div class="terms-card bg-[#F9FAFB] dark:bg-gray-900 rounded-xl p-6 mb-8" data-aos="fade-up">
                <h2 class="text-xl font-bold text-[#111827] dark:text-white mb-4 flex items-center">
                    <i class="fas fa-list-ul text-[#1E3A8A] mr-3"></i>
                    Table of Contents
                </h2>
                <div class="grid md:grid-cols-2 gap-3 text-sm">
                    <a href="#section1" class="text-[#4B5563] dark:text-gray-400 hover:text-[#1E3A8A] transition">1. Acceptance of Terms</a>
                    <a href="#section2" class="text-[#4B5563] dark:text-gray-400 hover:text-[#1E3A8A] transition">2. Eligibility</a>
                    <a href="#section3" class="text-[#4B5563] dark:text-gray-400 hover:text-[#1E3A8A] transition">3. User Accounts</a>
                    <a href="#section4" class="text-[#4B5563] dark:text-gray-400 hover:text-[#1E3A8A] transition">4. Job Postings & Applications</a>
                    <a href="#section5" class="text-[#4B5563] dark:text-gray-400 hover:text-[#1E3A8A] transition">5. Content & Conduct</a>
                    <a href="#section6" class="text-[#4B5563] dark:text-gray-400 hover:text-[#1E3A8A] transition">6. Intellectual Property</a>
                    <a href="#section7" class="text-[#4B5563] dark:text-gray-400 hover:text-[#1E3A8A] transition">7. Privacy & Data</a>
                    <a href="#section8" class="text-[#4B5563] dark:text-gray-400 hover:text-[#1E3A8A] transition">8. Limitation of Liability</a>
                    <a href="#section9" class="text-[#4B5563] dark:text-gray-400 hover:text-[#1E3A8A] transition">9. Termination</a>
                    <a href="#section10" class="text-[#4B5563] dark:text-gray-400 hover:text-[#1E3A8A] transition">10. Governing Law</a>
                </div>
            </div>
            
            <!-- Section 1 -->
            <div id="section1" class="terms-card bg-white dark:bg-gray-800 rounded-xl p-6 mb-6 border border-gray-200 dark:border-gray-700" data-aos="fade-up">
                <h2 class="text-2xl font-bold text-[#111827] dark:text-white mb-4 flex items-center">
                    <span class="w-8 h-8 brand-gradient rounded-full flex items-center justify-center text-white text-sm font-bold mr-3">1</span>
                    Acceptance of Terms
                </h2>
                <p class="text-[#4B5563] dark:text-gray-300 mb-4 leading-relaxed">
                    By accessing or using the TransNow Ghana website (trnasnow.com), mobile application, or any of our services, you agree to be bound by these Terms of Service and our Privacy Policy. If you do not agree to these terms, please do not use our platform.
                </p>
                <p class="text-[#4B5563] dark:text-gray-300 leading-relaxed">
                    We reserve the right to modify these terms at any time. Changes become effective immediately upon posting. Your continued use of the platform constitutes acceptance of any modified terms.
                </p>
            </div>
            
            <!-- Section 2 -->
            <div id="section2" class="terms-card bg-white dark:bg-gray-800 rounded-xl p-6 mb-6 border border-gray-200 dark:border-gray-700" data-aos="fade-up">
                <h2 class="text-2xl font-bold text-[#111827] dark:text-white mb-4 flex items-center">
                    <span class="w-8 h-8 brand-gradient rounded-full flex items-center justify-center text-white text-sm font-bold mr-3">2</span>
                    Eligibility
                </h2>
                <p class="text-[#4B5563] dark:text-gray-300 mb-4 leading-relaxed">
                    To use TransNow Ghana, you must:
                </p>
                <ul class="list-disc list-inside text-[#4B5563] dark:text-gray-300 space-y-2 ml-4">
                    <li>Be at least 18 years of age or the age of majority in Ghana</li>
                    <li>Have the legal capacity to enter into binding contracts</li>
                    <li>Provide accurate and complete registration information</li>
                    <li>Not be restricted from using our services under Ghanaian law</li>
                </ul>
                <p class="text-[#4B5563] dark:text-gray-300 mt-4 leading-relaxed">
                    For employers posting jobs, you must be a legitimate business entity or authorized representative of a business operating in or hiring for positions in Ghana.
                </p>
            </div>
            
            <!-- Section 3 -->
            <div id="section3" class="terms-card bg-white dark:bg-gray-800 rounded-xl p-6 mb-6 border border-gray-200 dark:border-gray-700" data-aos="fade-up">
                <h2 class="text-2xl font-bold text-[#111827] dark:text-white mb-4 flex items-center">
                    <span class="w-8 h-8 brand-gradient rounded-full flex items-center justify-center text-white text-sm font-bold mr-3">3</span>
                    User Accounts
                </h2>
                <p class="text-[#4B5563] dark:text-gray-300 mb-4 leading-relaxed">
                    You are responsible for maintaining the confidentiality of your account credentials and for all activities that occur under your account. You agree to:
                </p>
                <ul class="list-disc list-inside text-[#4B5563] dark:text-gray-300 space-y-2 ml-4">
                    <li>Provide accurate, current, and complete information</li>
                    <li>Update your information promptly when changes occur</li>
                    <li>Notify us immediately of any unauthorized account access</li>
                    <li>Not share your account with any third party</li>
                </ul>
                <p class="text-[#4B5563] dark:text-gray-300 mt-4 leading-relaxed">
                    TransNow Ghana reserves the right to suspend or terminate accounts that violate these terms or pose a security risk.
                </p>
            </div>
            
            <!-- Section 4 -->
            <div id="section4" class="terms-card bg-white dark:bg-gray-800 rounded-xl p-6 mb-6 border border-gray-200 dark:border-gray-700" data-aos="fade-up">
                <h2 class="text-2xl font-bold text-[#111827] dark:text-white mb-4 flex items-center">
                    <span class="w-8 h-8 brand-gradient rounded-full flex items-center justify-center text-white text-sm font-bold mr-3">4</span>
                    Job Postings & Applications
                </h2>
                <p class="text-[#4B5563] dark:text-gray-300 mb-4 leading-relaxed">
                    <strong class="font-semibold">For Employers:</strong> You are solely responsible for the accuracy, legality, and content of your job postings. You agree not to post jobs that:
                </p>
                <ul class="list-disc list-inside text-[#4B5563] dark:text-gray-300 space-y-2 ml-4 mb-4">
                    <li>Discriminate based on race, gender, religion, age, disability, or other protected characteristics</li>
                    <li>Misrepresent the position, company, or compensation</li>
                    <li>Require upfront fees or payments from applicants</li>
                    <li>Promote illegal activities or violate Ghanaian labor laws</li>
                </ul>
                <p class="text-[#4B5563] dark:text-gray-300 mb-4 leading-relaxed">
                    <strong class="font-semibold">For Job Seekers:</strong> You agree to provide truthful information in your applications and to communicate professionally with employers. You may not submit fraudulent applications or misrepresent your qualifications.
                </p>
                <p class="text-[#4B5563] dark:text-gray-300 leading-relaxed">
                    TransNow Ghana is a platform connecting employers and job seekers. We do not guarantee employment or hire any users directly.
                </p>
            </div>
            
            <!-- Section 5 -->
            <div id="section5" class="terms-card bg-white dark:bg-gray-800 rounded-xl p-6 mb-6 border border-gray-200 dark:border-gray-700" data-aos="fade-up">
                <h2 class="text-2xl font-bold text-[#111827] dark:text-white mb-4 flex items-center">
                    <span class="w-8 h-8 brand-gradient rounded-full flex items-center justify-center text-white text-sm font-bold mr-3">5</span>
                    Content & Conduct
                </h2>
                <p class="text-[#4B5563] dark:text-gray-300 mb-4 leading-relaxed">
                    You agree not to post, transmit, or share any content that:
                </p>
                <ul class="list-disc list-inside text-[#4B5563] dark:text-gray-300 space-y-2 ml-4">
                    <li>Is unlawful, harmful, threatening, abusive, harassing, or defamatory</li>
                    <li>Contains viruses, malware, or harmful code</li>
                    <li>Infringes on intellectual property rights</li>
                    <li>Contains spam, unsolicited advertising, or chain letters</li>
                    <li>Impersonates any person or entity</li>
                </ul>
                <p class="text-[#4B5563] dark:text-gray-300 mt-4 leading-relaxed">
                    We reserve the right to remove any content that violates these terms without prior notice.
                </p>
            </div>
            
            <!-- Section 6 -->
            <div id="section6" class="terms-card bg-white dark:bg-gray-800 rounded-xl p-6 mb-6 border border-gray-200 dark:border-gray-700" data-aos="fade-up">
                <h2 class="text-2xl font-bold text-[#111827] dark:text-white mb-4 flex items-center">
                    <span class="w-8 h-8 brand-gradient rounded-full flex items-center justify-center text-white text-sm font-bold mr-3">6</span>
                    Intellectual Property
                </h2>
                <p class="text-[#4B5563] dark:text-gray-300 mb-4 leading-relaxed">
                    All content on TransNow Ghana, including logos, designs, text, graphics, software, and code, is the property of TransNow Ghana or its licensors and is protected by Ghanaian and international copyright laws.
                </p>
                <p class="text-[#4B5563] dark:text-gray-300 leading-relaxed">
                    You may not reproduce, distribute, modify, create derivative works of, or publicly display any content from our platform without our prior written consent.
                </p>
            </div>
            
            <!-- Section 7 -->
            <div id="section7" class="terms-card bg-white dark:bg-gray-800 rounded-xl p-6 mb-6 border border-gray-200 dark:border-gray-700" data-aos="fade-up">
                <h2 class="text-2xl font-bold text-[#111827] dark:text-white mb-4 flex items-center">
                    <span class="w-8 h-8 brand-gradient rounded-full flex items-center justify-center text-white text-sm font-bold mr-3">7</span>
                    Privacy & Data
                </h2>
                <p class="text-[#4B5563] dark:text-gray-300 mb-4 leading-relaxed">
                    Your privacy is important to us. Our collection and use of personal information is governed by our <a href="/privacy.php" class="text-[#1E3A8A] hover:underline">Privacy Policy</a>, which is incorporated into these terms.
                </p>
                <p class="text-[#4B5563] dark:text-gray-300 leading-relaxed">
                    By using our platform, you consent to the collection and use of your information as described in our Privacy Policy.
                </p>
            </div>
            
            <!-- Section 8 -->
            <div id="section8" class="terms-card bg-white dark:bg-gray-800 rounded-xl p-6 mb-6 border border-gray-200 dark:border-gray-700" data-aos="fade-up">
                <h2 class="text-2xl font-bold text-[#111827] dark:text-white mb-4 flex items-center">
                    <span class="w-8 h-8 brand-gradient rounded-full flex items-center justify-center text-white text-sm font-bold mr-3">8</span>
                    Limitation of Liability
                </h2>
                <p class="text-[#4B5563] dark:text-gray-300 mb-4 leading-relaxed">
                    To the maximum extent permitted by law, TransNow Ghana and its affiliates, officers, employees, and agents shall not be liable for any indirect, incidental, special, consequential, or punitive damages arising from your use of our platform.
                </p>
                <p class="text-[#4B5563] dark:text-gray-300 leading-relaxed">
                    We do not guarantee that our platform will be uninterrupted, error-free, or secure. We are not responsible for any interactions between users, including hiring decisions or employment relationships.
                </p>
            </div>
            
            <!-- Section 9 -->
            <div id="section9" class="terms-card bg-white dark:bg-gray-800 rounded-xl p-6 mb-6 border border-gray-200 dark:border-gray-700" data-aos="fade-up">
                <h2 class="text-2xl font-bold text-[#111827] dark:text-white mb-4 flex items-center">
                    <span class="w-8 h-8 brand-gradient rounded-full flex items-center justify-center text-white text-sm font-bold mr-3">9</span>
                    Termination
                </h2>
                <p class="text-[#4B5563] dark:text-gray-300 mb-4 leading-relaxed">
                    We may terminate or suspend your account immediately, without prior notice, for conduct that violates these terms or is harmful to other users or our platform.
                </p>
                <p class="text-[#4B5563] dark:text-gray-300 leading-relaxed">
                    Upon termination, your right to use the platform will cease immediately. Sections that by their nature should survive termination shall survive.
                </p>
            </div>
            
            <!-- Section 10 -->
            <div id="section10" class="terms-card bg-white dark:bg-gray-800 rounded-xl p-6 mb-6 border border-gray-200 dark:border-gray-700" data-aos="fade-up">
                <h2 class="text-2xl font-bold text-[#111827] dark:text-white mb-4 flex items-center">
                    <span class="w-8 h-8 brand-gradient rounded-full flex items-center justify-center text-white text-sm font-bold mr-3">10</span>
                    Governing Law
                </h2>
                <p class="text-[#4B5563] dark:text-gray-300 mb-4 leading-relaxed">
                    These Terms of Service shall be governed by and construed in accordance with the laws of the Republic of Ghana, without regard to conflict of law principles.
                </p>
                <p class="text-[#4B5563] dark:text-gray-300 leading-relaxed">
                    Any disputes arising from these terms or your use of the platform shall be resolved exclusively in the courts of Ghana, located in Accra.
                </p>
            </div>
            
            <!-- Contact Information -->
            <div class="bg-[#F3F4F6] dark:bg-gray-900 rounded-xl p-6 mt-8 text-center" data-aos="fade-up">
                <h3 class="font-bold text-lg text-[#111827] dark:text-white mb-3">Questions About These Terms?</h3>
                <p class="text-[#4B5563] dark:text-gray-400 mb-4">
                    If you have any questions about these Terms of Service, please contact us:
                </p>
                <div class="flex flex-wrap justify-center gap-4">
                    <a href="tel:+8619157688742" class="text-[#1E3A8A] hover:underline flex items-center">
                        <i class="fas fa-phone-alt mr-2"></i> +86 191 5768 8742
                    </a>
                    <a href="https://wa.me/8619157688742" target="_blank" class="text-[#1E3A8A] hover:underline flex items-center">
                        <i class="fab fa-whatsapp mr-2"></i> WhatsApp
                    </a>
                    <a href="https://u.wechat.com/EPqmYQJJDuNvJgHqgtMy00E?s=3" target="_blank" class="text-[#1E3A8A] hover:underline flex items-center">
                        <i class="fab fa-weixin mr-2"></i> WeChat
                    </a>
                </div>
            </div>
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
                
                <div class="mb-4 space-y-2">
                    <div class="flex items-center space-x-3 text-gray-400">
                        <i class="fas fa-phone-alt text-[#10B981] w-5"></i>
                        <a href="tel:+8619157688742" class="hover:text-white transition">+86 191 5768 8742</a>
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
                    <li><a href="/contact.php" class="hover:text-white transition">Contact</a></li>
                    <li><a href="/privacy.php" class="hover:text-white transition">Privacy Policy</a></li>
                    <li><a href="/terms.php" class="hover:text-white transition text-white">Terms of Service</a></li>
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
    AOS.init({ duration: 800, once: true });
    
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
        if (progressBar) progressBar.style.width = scrolled + '%';
        
        const navbar = document.getElementById('navbar');
        if (navbar) {
            if (window.scrollY > 50) navbar.classList.add('shadow-lg');
            else navbar.classList.remove('shadow-lg');
        }
    });
</script>
</body>
</html>