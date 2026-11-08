<?php
// notifications.php - Standalone notifications page for TransNow
// This file contains all logic embedded - no external dependencies

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database configuration
define('DB_HOST', 'sqlXXX.stormerhost.com'); // Replace with your actual host
define('DB_NAME', 'if0_40778153_jobwall');
define('DB_USER', 'if0_40778153'); // Replace with your username
define('DB_PASS', 'your_password'); // Replace with your password
define('DB_CHARSET', 'utf8mb4');

// Create database connection with error handling
try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// ============================================
// Notification Functions
// ============================================

/**
 * Get user notifications with pagination
 */
function getUserNotifications($pdo, $userId, $limit = 20, $offset = 0) {
    $sql = "SELECT n.*, nt.name as template_name, nt.content as template_content 
            FROM notifications n
            LEFT JOIN notification_templates nt ON n.template_id = nt.id
            WHERE n.user_id = ?
            ORDER BY n.created_at DESC
            LIMIT ? OFFSET ?";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId, $limit, $offset]);
    return $stmt->fetchAll();
}

/**
 * Get unread notification count
 */
function getUnreadCount($pdo, $userId) {
    $sql = "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND (status != 'read' OR status IS NULL)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId]);
    return $stmt->fetchColumn();
}

/**
 * Mark notification as read
 */
function markAsRead($pdo, $notificationId, $userId) {
    $sql = "UPDATE notifications SET status = 'read', read_at = NOW() WHERE id = ? AND user_id = ?";
    $stmt = $pdo->prepare($sql);
    return $stmt->execute([$notificationId, $userId]);
}

/**
 * Mark all notifications as read
 */
function markAllAsRead($pdo, $userId) {
    $sql = "UPDATE notifications SET status = 'read', read_at = NOW() WHERE user_id = ? AND (status != 'read' OR status IS NULL)";
    $stmt = $pdo->prepare($sql);
    return $stmt->execute([$userId]);
}

/**
 * Delete a notification
 */
function deleteNotification($pdo, $notificationId, $userId) {
    $sql = "DELETE FROM notifications WHERE id = ? AND user_id = ?";
    $stmt = $pdo->prepare($sql);
    return $stmt->execute([$notificationId, $userId]);
}

/**
 * Create a new notification
 */
function createNotification($pdo, $userId, $type, $subject, $message, $channel = 'inapp', $templateId = null, $metadata = null) {
    $sql = "INSERT INTO notifications (user_id, notification_type, template_id, subject, message, metadata, channel, status, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW())";
    $stmt = $pdo->prepare($sql);
    return $stmt->execute([$userId, $type, $templateId, $subject, $message, $metadata, $channel]);
}

/**
 * Get notification by ID
 */
function getNotificationById($pdo, $notificationId, $userId) {
    $sql = "SELECT n.*, nt.name as template_name, nt.content as template_content 
            FROM notifications n
            LEFT JOIN notification_templates nt ON n.template_id = nt.id
            WHERE n.id = ? AND n.user_id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$notificationId, $userId]);
    return $stmt->fetch();
}

/**
 * Format notification time
 */
function formatNotificationTime($timestamp) {
    $now = time();
    $notificationTime = strtotime($timestamp);
    $diff = $now - $notificationTime;
    
    if ($diff < 60) {
        return 'Just now';
    } elseif ($diff < 3600) {
        $minutes = floor($diff / 60);
        return $minutes . ' minute' . ($minutes > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 604800) {
        $days = floor($diff / 86400);
        return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    } else {
        return date('M j, Y', $notificationTime);
    }
}

/**
 * Get notification icon based on type
 */
function getNotificationIcon($type) {
    $icons = [
        'job_match' => 'fas fa-briefcase',
        'application_update' => 'fas fa-paper-plane',
        'interview' => 'fas fa-calendar-check',
        'message' => 'fas fa-envelope',
        'system' => 'fas fa-bell',
        'job_alert' => 'fas fa-bell',
        'profile_view' => 'fas fa-eye',
        'application_received' => 'fas fa-file-alt',
        'candidate_saved' => 'fas fa-star',
        'job_expiring' => 'fas fa-clock',
        'default' => 'fas fa-bell'
    ];
    
    return isset($icons[$type]) ? $icons[$type] : $icons['default'];
}

/**
 * Get notification color based on type
 */
function getNotificationColor($type) {
    $colors = [
        'job_match' => 'primary',
        'application_update' => 'info',
        'interview' => 'success',
        'message' => 'warning',
        'system' => 'secondary',
        'job_alert' => 'primary',
        'profile_view' => 'info',
        'application_received' => 'success',
        'candidate_saved' => 'warning',
        'job_expiring' => 'danger',
        'default' => 'secondary'
    ];
    
    return isset($colors[$type]) ? $colors[$type] : $colors['default'];
}

// ============================================
// Handle Actions
// ============================================

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?redirect=' . urlencode($_SERVER['PHP_SELF']));
    exit();
}

$userId = $_SESSION['user_id'];
$userType = $_SESSION['user_type'] ?? 'seeker';
$message = '';
$messageType = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'mark_read':
                if (isset($_POST['notification_id'])) {
                    if (markAsRead($pdo, $_POST['notification_id'], $userId)) {
                        $message = 'Notification marked as read.';
                        $messageType = 'success';
                    }
                }
                break;
                
            case 'mark_all_read':
                if (markAllAsRead($pdo, $userId)) {
                    $message = 'All notifications marked as read.';
                    $messageType = 'success';
                }
                break;
                
            case 'delete':
                if (isset($_POST['notification_id'])) {
                    if (deleteNotification($pdo, $_POST['notification_id'], $userId)) {
                        $message = 'Notification deleted successfully.';
                        $messageType = 'success';
                    }
                }
                break;
                
            case 'delete_all':
                $sql = "DELETE FROM notifications WHERE user_id = ?";
                $stmt = $pdo->prepare($sql);
                if ($stmt->execute([$userId])) {
                    $message = 'All notifications deleted successfully.';
                    $messageType = 'success';
                }
                break;
        }
    } catch (Exception $e) {
        $message = 'Error processing request: ' . $e->getMessage();
        $messageType = 'danger';
    }
}

// Handle AJAX requests
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    
    if ($_GET['ajax'] === 'get_count') {
        echo json_encode(['unread_count' => getUnreadCount($pdo, $userId)]);
        exit();
    }
    
    if ($_GET['ajax'] === 'mark_read' && isset($_GET['id'])) {
        $result = markAsRead($pdo, $_GET['id'], $userId);
        echo json_encode(['success' => $result]);
        exit();
    }
}

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Get total count for pagination
$totalSql = "SELECT COUNT(*) FROM notifications WHERE user_id = ?";
$totalStmt = $pdo->prepare($totalSql);
$totalStmt->execute([$userId]);
$totalNotifications = $totalStmt->fetchColumn();
$totalPages = ceil($totalNotifications / $limit);

// Get notifications
$notifications = getUserNotifications($pdo, $userId, $limit, $offset);
$unreadCount = getUnreadCount($pdo, $userId);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications - TransNow</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #4361ee;
            --secondary-color: #3f37c9;
            --success-color: #4cc9f0;
            --danger-color: #f72585;
            --warning-color: #f8961e;
            --info-color: #4895ef;
            --dark-color: #1e1e2f;
            --light-color: #f8f9fa;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f5f7fb;
            color: #333;
        }
        
        .navbar {
            background: linear-gradient(135deg, var(--dark-color) 0%, #2a2a3a 100%);
            padding: 1rem 0;
            box-shadow: 0 2px 20px rgba(0,0,0,0.1);
        }
        
        .navbar-brand {
            font-weight: 700;
            font-size: 1.5rem;
            color: white !important;
        }
        
        .nav-link {
            color: rgba(255,255,255,0.8) !important;
            font-weight: 500;
            transition: all 0.3s;
        }
        
        .nav-link:hover {
            color: white !important;
            transform: translateY(-2px);
        }
        
        .nav-link.active {
            color: white !important;
            border-bottom: 2px solid var(--success-color);
        }
        
        .main-content {
            padding: 2rem 0;
            min-height: calc(100vh - 140px);
        }
        
        .page-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 2rem 0;
            margin-bottom: 2rem;
            border-radius: 0 0 2rem 2rem;
        }
        
        .page-header h1 {
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        
        .page-header p {
            opacity: 0.9;
            margin-bottom: 0;
        }
        
        .stats-card {
            background: white;
            border-radius: 1rem;
            padding: 1.5rem;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            transition: all 0.3s;
            border: none;
        }
        
        .stats-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }
        
        .stats-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin-bottom: 1rem;
        }
        
        .stats-icon.primary {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
        }
        
        .stats-icon.success {
            background: linear-gradient(135deg, var(--success-color), #3aa8d0);
            color: white;
        }
        
        .stats-icon.warning {
            background: linear-gradient(135deg, var(--warning-color), #e07c1a);
            color: white;
        }
        
        .stats-number {
            font-size: 2rem;
            font-weight: 700;
            color: var(--dark-color);
            margin-bottom: 0.25rem;
        }
        
        .stats-label {
            color: #6c757d;
            font-weight: 500;
            margin-bottom: 0;
        }
        
        .notification-card {
            background: white;
            border-radius: 1rem;
            padding: 1.25rem;
            margin-bottom: 1rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.03);
            transition: all 0.3s;
            border-left: 4px solid transparent;
            position: relative;
        }
        
        .notification-card.unread {
            border-left-color: var(--primary-color);
            background: linear-gradient(to right, rgba(67, 97, 238, 0.02), white);
        }
        
        .notification-card:hover {
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
            transform: translateX(5px);
        }
        
        .notification-icon {
            width: 45px;
            height: 45px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
        }
        
        .notification-icon.primary {
            background: rgba(67, 97, 238, 0.1);
            color: var(--primary-color);
        }
        
        .notification-icon.success {
            background: rgba(76, 201, 240, 0.1);
            color: var(--success-color);
        }
        
        .notification-icon.warning {
            background: rgba(248, 150, 30, 0.1);
            color: var(--warning-color);
        }
        
        .notification-icon.danger {
            background: rgba(247, 37, 133, 0.1);
            color: var(--danger-color);
        }
        
        .notification-icon.info {
            background: rgba(72, 149, 239, 0.1);
            color: var(--info-color);
        }
        
        .notification-title {
            font-weight: 600;
            color: var(--dark-color);
            margin-bottom: 0.25rem;
        }
        
        .notification-message {
            color: #6c757d;
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
        }
        
        .notification-time {
            font-size: 0.8rem;
            color: #adb5bd;
            display: flex;
            align-items: center;
        }
        
        .notification-time i {
            margin-right: 0.25rem;
            font-size: 0.7rem;
        }
        
        .notification-actions {
            position: absolute;
            top: 1.25rem;
            right: 1.25rem;
            opacity: 0;
            transition: opacity 0.3s;
        }
        
        .notification-card:hover .notification-actions {
            opacity: 1;
        }
        
        .notification-actions .btn {
            padding: 0.25rem 0.5rem;
            font-size: 0.8rem;
            border-radius: 0.5rem;
        }
        
        .filter-buttons {
            background: white;
            padding: 1rem;
            border-radius: 1rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.03);
        }
        
        .filter-buttons .btn {
            margin-right: 0.5rem;
            border-radius: 2rem;
            padding: 0.5rem 1.25rem;
            font-weight: 500;
        }
        
        .filter-buttons .btn.active {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }
        
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            background: white;
            border-radius: 1rem;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
        }
        
        .empty-state i {
            font-size: 4rem;
            color: #dee2e6;
            margin-bottom: 1.5rem;
        }
        
        .empty-state h3 {
            font-weight: 600;
            color: var(--dark-color);
            margin-bottom: 0.5rem;
        }
        
        .empty-state p {
            color: #6c757d;
            margin-bottom: 1.5rem;
        }
        
        .pagination {
            justify-content: center;
            margin-top: 2rem;
        }
        
        .page-link {
            border: none;
            padding: 0.75rem 1rem;
            margin: 0 0.25rem;
            border-radius: 0.5rem;
            color: var(--dark-color);
            font-weight: 500;
            transition: all 0.3s;
        }
        
        .page-link:hover {
            background: var(--primary-color);
            color: white;
            transform: translateY(-2px);
        }
        
        .page-item.active .page-link {
            background: var(--primary-color);
            color: white;
        }
        
        .footer {
            background: white;
            padding: 1.5rem 0;
            border-top: 1px solid rgba(0,0,0,0.05);
            margin-top: 2rem;
        }
        
        .footer-text {
            color: #6c757d;
            font-size: 0.9rem;
        }
        
        .alert {
            border-radius: 1rem;
            border: none;
            padding: 1rem 1.5rem;
        }
        
        .badge-notification {
            position: relative;
            top: -10px;
            right: -5px;
            padding: 0.25rem 0.5rem;
            font-size: 0.7rem;
            background: var(--danger-color);
            color: white;
            border-radius: 2rem;
        }
        
        @media (max-width: 768px) {
            .notification-actions {
                position: static;
                opacity: 1;
                margin-top: 1rem;
            }
            
            .filter-buttons .btn {
                margin-bottom: 0.5rem;
                width: 100%;
            }
            
            .notification-card {
                padding: 1rem;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation Bar -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand" href="index.php">
                <i class="fas fa-bolt me-2"></i>TransNow
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <?php if ($userType == 'seeker'): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="seekers/dashboard.php">
                                <i class="fas fa-tachometer-alt me-1"></i>Dashboard
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="seekers/jobs.php">
                                <i class="fas fa-search me-1"></i>Find Jobs
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="seekers/applications.php">
                                <i class="fas fa-file-alt me-1"></i>Applications
                            </a>
                        </li>
                    <?php elseif ($userType == 'employer'): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="employer/dashboard.php">
                                <i class="fas fa-tachometer-alt me-1"></i>Dashboard
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="employer/manage-jobs.php">
                                <i class="fas fa-briefcase me-1"></i>My Jobs
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="employer/applications.php">
                                <i class="fas fa-users me-1"></i>Applications
                            </a>
                        </li>
                    <?php elseif ($userType == 'admin'): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="admin/dashboard.php">
                                <i class="fas fa-tachometer-alt me-1"></i>Dashboard
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="admin/users.php">
                                <i class="fas fa-users me-1"></i>Users
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="admin/jobs.php">
                                <i class="fas fa-briefcase me-1"></i>Jobs
                            </a>
                        </li>
                    <?php endif; ?>
                    <li class="nav-item">
                        <a class="nav-link active" href="notifications.php">
                            <i class="fas fa-bell me-1"></i>Notifications
                            <?php if ($unreadCount > 0): ?>
                                <span class="badge badge-notification"><?php echo $unreadCount; ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                </ul>
                <ul class="navbar-nav">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown">
                            <i class="fas fa-user-circle me-1"></i>
                            <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Account'); ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li>
                                <a class="dropdown-item" href="<?php echo $userType; ?>/profile.php">
                                    <i class="fas fa-id-card me-2"></i>Profile
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item" href="settings.php">
                                    <i class="fas fa-cog me-2"></i>Settings
                                </a>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item" href="logout.php">
                                    <i class="fas fa-sign-out-alt me-2"></i>Logout
                                </a>
                            </li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Page Header -->
    <div class="page-header">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h1><i class="fas fa-bell me-3"></i>Notifications</h1>
                    <p>Stay updated with your latest activities and updates</p>
                </div>
                <div class="col-md-4 text-md-end">
                    <?php if (!empty($notifications)): ?>
                        <form method="POST" style="display: inline;" onsubmit="return confirm('Mark all notifications as read?');">
                            <input type="hidden" name="action" value="mark_all_read">
                            <button type="submit" class="btn btn-light me-2">
                                <i class="fas fa-check-double me-2"></i>Mark All Read
                            </button>
                        </form>
                        <form method="POST" style="display: inline;" onsubmit="return confirm('Delete all notifications? This action cannot be undone.');">
                            <input type="hidden" name="action" value="delete_all">
                            <button type="submit" class="btn btn-danger">
                                <i class="fas fa-trash-alt me-2"></i>Delete All
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="container">
            <!-- Alert Messages -->
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                    <?php if ($messageType == 'success'): ?>
                        <i class="fas fa-check-circle me-2"></i>
                    <?php elseif ($messageType == 'danger'): ?>
                        <i class="fas fa-exclamation-circle me-2"></i>
                    <?php else: ?>
                        <i class="fas fa-info-circle me-2"></i>
                    <?php endif; ?>
                    <?php echo htmlspecialchars($message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Statistics Cards -->
            <div class="row mb-4">
                <div class="col-md-4 mb-3">
                    <div class="stats-card">
                        <div class="stats-icon primary">
                            <i class="fas fa-bell"></i>
                        </div>
                        <div class="stats-number"><?php echo $totalNotifications; ?></div>
                        <p class="stats-label">Total Notifications</p>
                    </div>
                </div>
                <div class="col-md-4 mb-3">
                    <div class="stats-card">
                        <div class="stats-icon success">
                            <i class="fas fa-envelope-open"></i>
                        </div>
                        <div class="stats-number"><?php echo $totalNotifications - $unreadCount; ?></div>
                        <p class="stats-label">Read Notifications</p>
                    </div>
                </div>
                <div class="col-md-4 mb-3">
                    <div class="stats-card">
                        <div class="stats-icon warning">
                            <i class="fas fa-envelope"></i>
                        </div>
                        <div class="stats-number"><?php echo $unreadCount; ?></div>
                        <p class="stats-label">Unread Notifications</p>
                    </div>
                </div>
            </div>

            <!-- Filter Buttons (JavaScript-based filtering) -->
            <div class="filter-buttons">
                <button class="btn btn-outline-secondary active" data-filter="all">
                    <i class="fas fa-list me-2"></i>All
                </button>
                <button class="btn btn-outline-primary" data-filter="unread">
                    <i class="fas fa-envelope me-2"></i>Unread
                </button>
                <button class="btn btn-outline-success" data-filter="read">
                    <i class="fas fa-envelope-open me-2"></i>Read
                </button>
            </div>

            <!-- Notifications List -->
            <?php if (empty($notifications)): ?>
                <div class="empty-state">
                    <i class="fas fa-bell-slash"></i>
                    <h3>No Notifications</h3>
                    <p>You don't have any notifications at the moment.</p>
                    <?php if ($userType == 'seeker'): ?>
                        <a href="seekers/jobs.php" class="btn btn-primary">
                            <i class="fas fa-search me-2"></i>Browse Jobs
                        </a>
                    <?php elseif ($userType == 'employer'): ?>
                        <a href="employer/post-job.php" class="btn btn-primary">
                            <i class="fas fa-plus-circle me-2"></i>Post a Job
                        </a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="notifications-list">
                    <?php foreach ($notifications as $notification): 
                        $icon = getNotificationIcon($notification['notification_type']);
                        $color = getNotificationColor($notification['notification_type']);
                        $isUnread = !isset($notification['status']) || $notification['status'] != 'read';
                        $timeAgo = formatNotificationTime($notification['created_at']);
                        $metadata = $notification['metadata'] ? json_decode($notification['metadata'], true) : null;
                    ?>
                        <div class="notification-card <?php echo $isUnread ? 'unread' : ''; ?>" data-status="<?php echo $isUnread ? 'unread' : 'read'; ?>">
                            <div class="row align-items-center">
                                <div class="col-auto">
                                    <div class="notification-icon <?php echo $color; ?>">
                                        <i class="<?php echo $icon; ?>"></i>
                                    </div>
                                </div>
                                <div class="col">
                                    <div class="notification-title">
                                        <?php echo htmlspecialchars($notification['subject'] ?? 'Notification'); ?>
                                        <?php if ($isUnread): ?>
                                            <span class="badge bg-primary ms-2">New</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="notification-message">
                                        <?php echo htmlspecialchars($notification['message']); ?>
                                        <?php if ($metadata && isset($metadata['action_url'])): ?>
                                            <a href="<?php echo htmlspecialchars($metadata['action_url']); ?>" class="ms-2">
                                                View Details <i class="fas fa-arrow-right"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                    <div class="notification-time">
                                        <i class="far fa-clock"></i>
                                        <?php echo $timeAgo; ?>
                                        <?php if ($notification['channel'] != 'inapp'): ?>
                                            <span class="badge bg-light text-dark ms-2">
                                                <i class="fas fa-<?php echo $notification['channel']; ?> me-1"></i>
                                                <?php echo ucfirst($notification['channel']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="notification-actions">
                                    <?php if ($isUnread): ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="action" value="mark_read">
                                            <input type="hidden" name="notification_id" value="<?php echo $notification['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-primary" title="Mark as read">
                                                <i class="fas fa-check"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this notification?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="notification_id" value="<?php echo $notification['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <nav>
                        <ul class="pagination">
                            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $page - 1; ?>">
                                    <i class="fas fa-chevron-left"></i>
                                </a>
                            </li>
                            
                            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $i; ?>"><?php echo $i; ?></a>
                                </li>
                            <?php endfor; ?>
                            
                            <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $page + 1; ?>">
                                    <i class="fas fa-chevron-right"></i>
                                </a>
                            </li>
                        </ul>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <div class="row">
                <div class="col-md-6">
                    <p class="footer-text">
                        <i class="fas fa-copyright me-1"></i> <?php echo date('Y'); ?> TransNow. All rights reserved.
                    </p>
                </div>
                <div class="col-md-6 text-md-end">
                    <p class="footer-text">
                        <i class="fas fa-bell me-1"></i>
                        <span id="live-unread-count"><?php echo $unreadCount; ?></span> unread notifications
                    </p>
                </div>
            </div>
        </div>
    </footer>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Custom JavaScript -->
    <script>
        // Filter notifications
        document.addEventListener('DOMContentLoaded', function() {
            const filterButtons = document.querySelectorAll('[data-filter]');
            const notifications = document.querySelectorAll('.notification-card');
            
            filterButtons.forEach(button => {
                button.addEventListener('click', function() {
                    // Update active state
                    filterButtons.forEach(btn => btn.classList.remove('active'));
                    this.classList.add('active');
                    
                    const filter = this.dataset.filter;
                    
                    notifications.forEach(notification => {
                        if (filter === 'all') {
                            notification.style.display = 'block';
                        } else if (filter === 'unread' && notification.classList.contains('unread')) {
                            notification.style.display = 'block';
                        } else if (filter === 'read' && !notification.classList.contains('unread')) {
                            notification.style.display = 'block';
                        } else {
                            notification.style.display = 'none';
                        }
                    });
                });
            });
            
            // Auto-refresh unread count every 30 seconds
            function refreshUnreadCount() {
                fetch('notifications.php?ajax=get_count')
                    .then(response => response.json())
                    .then(data => {
                        const countElement = document.getElementById('live-unread-count');
                        if (countElement) {
                            countElement.textContent = data.unread_count;
                            
                            // Update badge in navbar
                            const badge = document.querySelector('.badge-notification');
                            if (badge) {
                                if (data.unread_count > 0) {
                                    badge.textContent = data.unread_count;
                                    badge.style.display = 'inline';
                                } else {
                                    badge.style.display = 'none';
                                }
                            }
                        }
                    })
                    .catch(error => console.error('Error fetching unread count:', error));
            }
            
            // Refresh every 30 seconds
            setInterval(refreshUnreadCount, 30000);
            
            // Mark as read on click (optional)
            notifications.forEach(notification => {
                notification.addEventListener('click', function(e) {
                    // Don't trigger if clicking on a button or form
                    if (e.target.tagName === 'BUTTON' || e.target.tagName === 'A' || e.target.closest('form')) {
                        return;
                    }
                    
                    if (this.classList.contains('unread')) {
                        const markReadBtn = this.querySelector('form button[type="submit"][title="Mark as read"]');
                        if (markReadBtn) {
                            markReadBtn.click();
                        }
                    }
                });
            });
        });
    </script>
    
    <style>
        /* Additional styles for interactive elements */
        .notification-card {
            cursor: pointer;
        }
        
        .notification-card.unread:hover {
            background: linear-gradient(to right, rgba(67, 97, 238, 0.05), white);
        }
        
        .notification-card .notification-actions form {
            pointer-events: auto;
        }
        
        .notification-card .notification-actions button {
            cursor: pointer;
        }
        
        /* Smooth transitions */
        .notification-card {
            transition: all 0.3s ease;
        }
        
        /* Loading animation for refresh */
        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.5; }
            100% { opacity: 1; }
        }
        
        .refreshing {
            animation: pulse 1s infinite;
        }
    </style>
</body>
</html>