<?php
// includes/header.php
// Common header template for all pages
// Includes navigation bar, conditional links based on user type, and basic HTML structure

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Require functions for utilities
require_once __DIR__ . '/../config/functions.php';

// Get current user type if logged in
$userType = getUserType();
$isLoggedIn = isLoggedIn();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Jobwall - Job Portal for Chinese Students and Others</title>
    <!-- Bootstrap CSS for responsive design -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Custom CSS -->
    <link href="/assets/css/style.css" rel="stylesheet"> <!-- Assume assets folder for CSS/JS -->
    <!-- Font Awesome for icons -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body>

<!-- Navigation Bar -->
<nav class="navbar navbar-expand-lg navbar-light bg-light">
    <div class="container-fluid">
        <a class="navbar-brand" href="/">Jobwall</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav me-auto">
                <li class="nav-item">
                    <a class="nav-link" href="/jobs.php">Browse Jobs</a>
                </li>
                <?php if ($isLoggedIn && $userType === 'seeker'): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="/seekers/dashboard.php">Dashboard</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="/seekers/profile.php">Profile</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="/seekers/applications.php">My Applications</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="/seekers/saved-jobs.php">Saved Jobs</a>
                    </li>
                <?php elseif ($isLoggedIn && $userType === 'employer'): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="/employer/dashboard.php">Dashboard</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="/employer/profile.php">Company Profile</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="/employer/post-job.php">Post Job</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="/employer/manage-jobs.php">Manage Jobs</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="/employer/applications.php">Applications</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="/employer/candidates.php">Candidates</a>
                    </li>
                <?php elseif ($isLoggedIn && $userType === 'admin'): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="/admin/dashboard.php">Admin Dashboard</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="/admin/users.php">Manage Users</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="/admin/jobs.php">Manage Jobs</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="/admin/categories.php">Categories</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="/admin/settings.php">Settings</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="/admin/reports.php">Reports</a>
                    </li>
                <?php endif; ?>
            </ul>
            <ul class="navbar-nav">
                <?php if ($isLoggedIn): ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fas fa-user"></i> Account
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                            <li><a class="dropdown-item" href="/profile.php">Profile</a></li>
                            <li><a class="dropdown-item" href="/settings.php">Settings</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="/logout.php">Logout</a></li>
                        </ul>
                    </li>
                <?php else: ?>
                    <li class="nav-item">
                        <a class="nav-link" href="/login.php">Login</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="/register.php">Register</a>
                    </li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>

<!-- Flash Messages -->
<div class="container mt-3">
    <?php echo displayFlashMessage(); ?>
</div>

<!-- Main Content Wrapper -->
<div class="container my-4">