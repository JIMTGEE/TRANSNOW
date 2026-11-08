<?php
// companies.php
// Page to display all companies and their details

// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Try different possible paths for the database configuration
$possible_paths = [
    __DIR__ . '/database.php',
    __DIR__ . '/config/database.php',
    __DIR__ . '/../config/database.php',
    'config/database.php',
    'database.php'
];

$db_loaded = false;
foreach ($possible_paths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $db_loaded = true;
        break;
    }
}

if (!$db_loaded) {
    die("Error: Could not find database configuration file. Please check the file path.");
}

// Check if getDB function exists
if (!function_exists('getDB')) {
    die("Error: getDB() function not found. Please check your database.php file.");
}

// Pagination settings
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 6;
$offset = ($page - 1) * $perPage;

try {
    // Get database connection
    $pdo = getDB();
    
    // Fetch companies with pagination
    // Get total count
    $countStmt = $pdo->query("SELECT COUNT(*) FROM companies");
    $totalCompanies = $countStmt->fetchColumn();
    $totalPages = ceil($totalCompanies / $perPage);

    // Fetch companies for current page
    $stmt = $pdo->prepare("SELECT * FROM companies ORDER BY id DESC LIMIT :limit OFFSET :offset");
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $companies = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Helper function to get company initial for avatar placeholder
    function getCompanyInitial($name) {
        return strtoupper(substr($name, 0, 1));
    }

    // Helper function to generate a consistent color based on company name
    function getCompanyColor($name) {
        $colors = ['#1E3A8A', '#3B82F6', '#10B981', '#F59E0B', '#EF4444', '#8B5CF6', '#EC4899', '#06B6D4'];
        $index = crc32($name) % count($colors);
        return $colors[$index];
    }
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Companies - TransNow</title>
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #1E3A8A;
            --secondary: #3B82F6;
            --background: #F3F4F6;
            --text: #111827;
            --accent: #10B981;
            --accent-hover: #059669;
            --white: #FFFFFF;
            --border: #E5E7EB;
            --text-light: #6B7280;
            --card-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --card-hover-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.15), 0 8px 10px -6px rgba(0, 0, 0, 0.1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: var(--background);
            color: var(--text);
            line-height: 1.6;
            min-height: 100vh;
        }

        /* Navigation */
        .navbar {
            background: linear-gradient(135deg, var(--primary) 0%, #1e40af 100%);
            color: var(--white);
            padding: 1rem 2rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .navbar .container {
            max-width: 1280px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .navbar .logo {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--white);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .navbar .logo i {
            color: var(--accent);
        }

        .navbar .back-link {
            color: var(--white);
            text-decoration: none;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            transition: background-color 0.3s;
        }

        .navbar .back-link:hover {
            background-color: rgba(255, 255, 255, 0.15);
        }

        /* Main Content */
        .main-content {
            max-width: 1280px;
            margin: 0 auto;
            padding: 2rem 1.5rem;
        }

        /* Page Header */
        .page-header {
            text-align: center;
            margin-bottom: 3rem;
            padding: 2rem 0;
        }

        .page-header .icon-circle {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            border-radius: 50%;
            margin-bottom: 1.5rem;
            box-shadow: 0 8px 20px rgba(30, 58, 138, 0.3);
        }

        .page-header .icon-circle i {
            font-size: 2.2rem;
            color: var(--white);
        }

        .page-header h1 {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 0.5rem;
        }

        .page-header p {
            font-size: 1.1rem;
            color: var(--text-light);
            max-width: 600px;
            margin: 0 auto;
        }

        /* Stats Bar */
        .stats-bar {
            display: flex;
            justify-content: center;
            margin-bottom: 2.5rem;
        }

        .stat-badge {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 50px;
            padding: 0.6rem 1.5rem;
            font-weight: 600;
            color: var(--primary);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            box-shadow: var(--card-shadow);
        }

        .stat-badge i {
            color: var(--accent);
        }

        /* Companies Grid */
        .companies-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
            gap: 1.8rem;
            margin-bottom: 2.5rem;
        }

        /* Company Card */
        .company-card {
            background: var(--white);
            border-radius: 16px;
            box-shadow: var(--card-shadow);
            overflow: hidden;
            transition: all 0.35s cubic-bezier(0.4, 0, 0.2, 1);
            border: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            position: relative;
        }

        .company-card:hover {
            transform: translateY(-6px);
            box-shadow: var(--card-hover-shadow);
            border-color: var(--secondary);
        }

        .company-card .card-header {
            padding: 1.8rem 1.8rem 0;
            display: flex;
            align-items: flex-start;
            gap: 1.2rem;
        }

        .company-avatar {
            width: 68px;
            height: 68px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--white);
            flex-shrink: 0;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            position: relative;
            overflow: hidden;
        }

        .company-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .company-avatar .logo-placeholder {
            font-size: 1.8rem;
            font-weight: 700;
        }

        .company-info {
            flex: 1;
            min-width: 0;
        }

        .company-info h2 {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 0.3rem;
            line-height: 1.3;
            word-break: break-word;
        }

        .company-info .company-badge {
            display: inline-block;
            background: #EEF2FF;
            color: var(--primary);
            font-size: 0.8rem;
            font-weight: 600;
            padding: 0.2rem 0.7rem;
            border-radius: 20px;
            margin-bottom: 0.5rem;
        }

        .company-card .card-body {
            padding: 1.2rem 1.8rem;
            flex: 1;
        }

        .detail-row {
            display: flex;
            align-items: flex-start;
            gap: 0.7rem;
            margin-bottom: 0.8rem;
            font-size: 0.9rem;
            color: var(--text-light);
        }

        .detail-row i {
            width: 18px;
            color: var(--secondary);
            margin-top: 0.15rem;
            font-size: 0.9rem;
        }

        .detail-row a {
            color: var(--secondary);
            text-decoration: none;
            transition: color 0.2s;
            word-break: break-all;
        }

        .detail-row a:hover {
            color: var(--primary);
            text-decoration: underline;
        }

        .company-description {
            margin-top: 0.8rem;
            font-size: 0.9rem;
            color: var(--text-light);
            line-height: 1.5;
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .company-card .card-footer {
            padding: 1rem 1.8rem 1.5rem;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 0.8rem;
        }

        .company-date {
            font-size: 0.8rem;
            color: var(--text-light);
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }

        .btn-view-company {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.55rem 1.2rem;
            background: var(--accent);
            color: var(--white);
            border: none;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.25s;
            white-space: nowrap;
        }

        .btn-view-company:hover {
            background: var(--accent-hover);
            transform: scale(1.03);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.4);
        }

        /* No Results */
        .no-results {
            text-align: center;
            padding: 4rem 2rem;
            background: var(--white);
            border-radius: 16px;
            box-shadow: var(--card-shadow);
            grid-column: 1 / -1;
        }

        .no-results .empty-icon {
            font-size: 4rem;
            color: var(--border);
            margin-bottom: 1rem;
        }

        .no-results h3 {
            font-size: 1.3rem;
            color: var(--text);
            margin-bottom: 0.5rem;
        }

        .no-results p {
            color: var(--text-light);
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.5rem;
            flex-wrap: wrap;
            padding: 1rem 0;
        }

        .pagination a,
        .pagination span {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 40px;
            height: 40px;
            padding: 0.4rem 0.8rem;
            border-radius: 10px;
            font-weight: 600;
            font-size: 0.9rem;
            text-decoration: none;
            transition: all 0.2s;
        }

        .pagination a {
            background: var(--white);
            color: var(--text);
            border: 1px solid var(--border);
        }

        .pagination a:hover {
            background: #EEF2FF;
            border-color: var(--secondary);
            color: var(--primary);
        }

        .pagination .active {
            background: var(--primary);
            color: var(--white);
            border-color: var(--primary);
            box-shadow: 0 2px 8px rgba(30, 58, 138, 0.3);
        }

        .pagination .disabled {
            background: #F9FAFB;
            color: #D1D5DB;
            border-color: var(--border);
            cursor: not-allowed;
            pointer-events: none;
        }

        /* Footer */
        .main-footer {
            text-align: center;
            padding: 2rem 1.5rem;
            color: var(--text-light);
            font-size: 0.9rem;
            border-top: 1px solid var(--border);
            margin-top: 2rem;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .companies-grid {
                grid-template-columns: 1fr;
                gap: 1.2rem;
            }

            .page-header h1 {
                font-size: 1.8rem;
            }

            .page-header .icon-circle {
                width: 60px;
                height: 60px;
            }

            .page-header .icon-circle i {
                font-size: 1.6rem;
            }

            .company-card .card-header {
                padding: 1.2rem 1.2rem 0;
                gap: 1rem;
            }

            .company-card .card-body {
                padding: 1rem 1.2rem;
            }

            .company-card .card-footer {
                padding: 1rem 1.2rem 1.2rem;
                flex-direction: column;
                align-items: stretch;
                text-align: center;
            }

            .company-avatar {
                width: 55px;
                height: 55px;
                font-size: 1.4rem;
                border-radius: 12px;
            }
        }

        @media (max-width: 480px) {
            .navbar {
                padding: 0.8rem 1rem;
            }

            .navbar .logo {
                font-size: 1.2rem;
            }

            .main-content {
                padding: 1rem 0.8rem;
            }

            .page-header {
                margin-bottom: 1.5rem;
                padding: 1.5rem 0;
            }

            .companies-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar">
        <div class="container">
            <a href="jobs.php" class="logo">
                <i class="fas fa-bolt"></i> TransNow
            </a>
            <a href="dashboard.php" class="back-link">
                <i class="fas fa-arrow-left"></i> Back to Jobs
            </a>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Page Header -->
        <div class="page-header">
            <div class="icon-circle">
                <i class="fas fa-building"></i>
            </div>
            <h1>All Companies</h1>
            <p>Discover companies hiring on TransNow. View their profiles and find your next opportunity.</p>
        </div>

        <!-- Stats Bar -->
        <div class="stats-bar">
            <div class="stat-badge">
                <i class="fas fa-building"></i>
                <span><?php echo $totalCompanies; ?> <?php echo $totalCompanies === 1 ? 'Company' : 'Companies'; ?> Listed</span>
            </div>
        </div>

        <?php if (empty($companies)): ?>
            <!-- No Companies Found -->
            <div class="companies-grid">
                <div class="no-results">
                    <div class="empty-icon">
                        <i class="fas fa-building"></i>
                    </div>
                    <h3>No Companies Found</h3>
                    <p>There are currently no companies listed. Check back later for new employers.</p>
                </div>
            </div>
        <?php else: ?>
            <!-- Companies Grid -->
            <div class="companies-grid">
                <?php foreach ($companies as $company): ?>
                    <div class="company-card">
                        <div class="card-header">
                            <div class="company-avatar" style="background-color: <?php echo getCompanyColor($company['name']); ?>;">
                                <?php if (!empty($company['logo'])): ?>
                                    <img src="<?php echo htmlspecialchars($company['logo'], ENT_QUOTES, 'UTF-8'); ?>" 
                                         alt="<?php echo htmlspecialchars($company['name'], ENT_QUOTES, 'UTF-8'); ?> logo"
                                         onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                    <span class="logo-placeholder" style="display:none;"><?php echo getCompanyInitial($company['name']); ?></span>
                                <?php else: ?>
                                    <span class="logo-placeholder"><?php echo getCompanyInitial($company['name']); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="company-info">
                                <h2><?php echo htmlspecialchars($company['name'], ENT_QUOTES, 'UTF-8'); ?></h2>
                                <?php
                                // Count jobs for this company
                                try {
                                    $jobCountStmt = $pdo->prepare("SELECT COUNT(*) FROM jobs WHERE company_id = :company_id AND status = 'Active'");
                                    $jobCountStmt->execute([':company_id' => $company['id']]);
                                    $activeJobs = $jobCountStmt->fetchColumn();
                                } catch (Exception $e) {
                                    $activeJobs = 0;
                                }
                                ?>
                                <span class="company-badge">
                                    <i class="fas fa-briefcase"></i> 
                                    <?php echo $activeJobs; ?> Active <?php echo $activeJobs === 1 ? 'Job' : 'Jobs'; ?>
                                </span>
                            </div>
                        </div>

                        <div class="card-body">
                            <?php if (!empty($company['address'])): ?>
                                <div class="detail-row">
                                    <i class="fas fa-map-marker-alt"></i>
                                    <span><?php echo htmlspecialchars($company['address'], ENT_QUOTES, 'UTF-8'); ?></span>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($company['email'])): ?>
                                <div class="detail-row">
                                    <i class="fas fa-envelope"></i>
                                    <a href="mailto:<?php echo htmlspecialchars($company['email'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php echo htmlspecialchars($company['email'], ENT_QUOTES, 'UTF-8'); ?>
                                    </a>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($company['phone'])): ?>
                                <div class="detail-row">
                                    <i class="fas fa-phone-alt"></i>
                                    <a href="tel:<?php echo htmlspecialchars($company['phone'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php echo htmlspecialchars($company['phone'], ENT_QUOTES, 'UTF-8'); ?>
                                    </a>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($company['website'])): ?>
                                <div class="detail-row">
                                    <i class="fas fa-globe"></i>
                                    <a href="<?php echo htmlspecialchars($company['website'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer">
                                        <?php echo htmlspecialchars($company['website'], ENT_QUOTES, 'UTF-8'); ?>
                                    </a>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($company['description'])): ?>
                                <p class="company-description">
                                    <?php echo htmlspecialchars($company['description'], ENT_QUOTES, 'UTF-8'); ?>
                                </p>
                            <?php endif; ?>
                        </div>

                        <div class="card-footer">
                            <span class="company-date">
                                <i class="far fa-calendar-alt"></i>
                                Joined <?php echo date('M Y', strtotime($company['created_at'])); ?>
                            </span>
                            <a href="company-jobs.php?id=<?php echo $company['id']; ?>" class="btn-view-company">
                                View Jobs <i class="fas fa-arrow-right"></i>
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?php echo $page - 1; ?>" aria-label="Previous page">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                    <?php else: ?>
                        <span class="disabled" aria-disabled="true">
                            <i class="fas fa-chevron-left"></i>
                        </span>
                    <?php endif; ?>

                    <?php
                    // Show limited page numbers with ellipsis
                    $maxVisible = 5;
                    $start = max(1, $page - floor($maxVisible / 2));
                    $end = min($totalPages, $start + $maxVisible - 1);
                    
                    if ($end - $start + 1 < $maxVisible) {
                        $start = max(1, $end - $maxVisible + 1);
                    }

                    if ($start > 1): ?>
                        <a href="?page=1">1</a>
                        <?php if ($start > 2): ?>
                            <span class="disabled">...</span>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php for ($i = $start; $i <= $end; $i++): ?>
                        <?php if ($i == $page): ?>
                            <span class="active" aria-current="page"><?php echo $i; ?></span>
                        <?php else: ?>
                            <a href="?page=<?php echo $i; ?>"><?php echo $i; ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <?php if ($end < $totalPages): ?>
                        <?php if ($end < $totalPages - 1): ?>
                            <span class="disabled">...</span>
                        <?php endif; ?>
                        <a href="?page=<?php echo $totalPages; ?>"><?php echo $totalPages; ?></a>
                    <?php endif; ?>

                    <?php if ($page < $totalPages): ?>
                        <a href="?page=<?php echo $page + 1; ?>" aria-label="Next page">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php else: ?>
                        <span class="disabled" aria-disabled="true">
                            <i class="fas fa-chevron-right"></i>
                        </span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </main>

    <!-- Footer -->
    <footer class="main-footer">
        <p>&copy; <?php echo date('Y'); ?> TransNow. All rights reserved.</p>
    </footer>
</body>
</html>