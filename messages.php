<?php
// messages.php - Comprehensive Messaging System for TransNow
// Updated to use PDO consistently with database.php

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config/database.php';
require_once 'includes/auth.php';
require_once 'config/functions.php';

// Check if user is logged in
if (!isLoggedIn()) {
    header('Location: login.php?redirect=messages.php');
    exit();
}

// Get PDO connection
$pdo = getDB();

$current_user_id = $_SESSION['user_id'];
$user_type = $_SESSION['user_type'];

// Initialize variables
$error = '';
$success = '';
$conversation_id = isset($_GET['conversation']) ? (int)$_GET['conversation'] : 0;
$action = isset($_GET['action']) ? $_GET['action'] : 'list';

// Handle sending new message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    $receiver_type = $_POST['receiver_type'] ?? '';
    $receiver_id = 0;
    $message = trim($_POST['message'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    
    // Validate message
    if (empty($message)) {
        $error = 'Message cannot be empty';
    } else {
        // Determine receiver ID based on type
        switch ($receiver_type) {
            case 'admin':
                // Get first admin user
                $stmt = $pdo->prepare("SELECT id FROM users WHERE user_type = 'admin' AND is_verified = 1 LIMIT 1");
                $stmt->execute();
                $admin_row = $stmt->fetch();
                if ($admin_row) {
                    $receiver_id = $admin_row['id'];
                } else {
                    $error = 'No admin available';
                }
                break;
                
            case 'employer':
                $company_name = $_POST['company_name'] ?? '';
                if (!empty($company_name)) {
                    $stmt = $pdo->prepare("SELECT u.id FROM users u 
                                          JOIN employers e ON u.id = e.user_id 
                                          WHERE u.user_type = 'employer' 
                                          AND u.is_verified = 1 
                                          AND e.company_name LIKE ? 
                                          LIMIT 1");
                    $stmt->execute(['%' . $company_name . '%']);
                    $employer_row = $stmt->fetch();
                    if ($employer_row) {
                        $receiver_id = $employer_row['id'];
                    } else {
                        $error = 'Employer not found';
                    }
                } else {
                    $error = 'Please enter company name';
                }
                break;
                
            case 'seeker':
                $seeker_identifier = $_POST['seeker_identifier'] ?? '';
                if (!empty($seeker_identifier)) {
                    // Check if it's numeric (ID) or email
                    if (is_numeric($seeker_identifier)) {
                        $stmt = $pdo->prepare("SELECT u.id FROM users u 
                                              JOIN seekers s ON u.id = s.user_id 
                                              WHERE u.user_type = 'seeker' 
                                              AND u.is_verified = 1 
                                              AND u.id = ? 
                                              AND u.id != ?
                                              LIMIT 1");
                        $stmt->execute([$seeker_identifier, $current_user_id]);
                    } else {
                        $stmt = $pdo->prepare("SELECT u.id FROM users u 
                                              JOIN seekers s ON u.id = s.user_id 
                                              WHERE u.user_type = 'seeker' 
                                              AND u.is_verified = 1 
                                              AND u.email = ?
                                              AND u.id != ?
                                              LIMIT 1");
                        $stmt->execute([$seeker_identifier, $current_user_id]);
                    }
                    $seeker_row = $stmt->fetch();
                    if ($seeker_row) {
                        $receiver_id = $seeker_row['id'];
                    } else {
                        $error = 'Seeker not found or trying to message yourself';
                    }
                } else {
                    $error = 'Please enter seeker ID or email';
                }
                break;
                
            case 'specific_application':
                $application_id = (int)($_POST['application_id'] ?? 0);
                if ($application_id > 0) {
                    // Get employer from application
                    $stmt = $pdo->prepare("SELECT e.user_id as employer_id 
                                         FROM applications a 
                                         JOIN jobs j ON a.job_id = j.id 
                                         JOIN employers e ON j.employer_id = e.id 
                                         WHERE a.id = ?");
                    $stmt->execute([$application_id]);
                    $app_row = $stmt->fetch();
                    if ($app_row) {
                        $receiver_id = $app_row['employer_id'];
                    } else {
                        $error = 'Application not found';
                    }
                } else {
                    $error = 'Invalid application ID';
                }
                break;
        }
        
        // Send message if no error and receiver found
        if (empty($error) && $receiver_id > 0) {
            // Check if receiver exists and is verified
            $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND is_verified = 1");
            $stmt->execute([$receiver_id]);
            
            if ($stmt->rowCount() > 0) {
                // Insert message
                $stmt = $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, subject, message, created_at) 
                                     VALUES (?, ?, ?, ?, NOW())");
                
                if ($stmt->execute([$current_user_id, $receiver_id, $subject, $message])) {
                    $message_id = $pdo->lastInsertId();
                    
                    // Create notification for receiver
                    $notification_msg = "You have received a new message";
                    $stmt = $pdo->prepare("INSERT INTO notifications (user_id, notification_type, message, channel, status, created_at) 
                                         VALUES (?, 'message', ?, 'inapp', 'pending', NOW())");
                    $stmt->execute([$receiver_id, $notification_msg]);
                    
                    $success = 'Message sent successfully!';
                    
                    // Redirect to conversation
                    header("Location: messages.php?conversation=$receiver_id&success=sent");
                    exit();
                } else {
                    $error = 'Failed to send message';
                }
            } else {
                $error = 'Invalid recipient';
            }
        }
    }
}

// Handle reply
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reply_message']) && $conversation_id > 0) {
    $reply_message = trim($_POST['reply_message'] ?? '');
    
    if (empty($reply_message)) {
        $error = 'Reply cannot be empty';
    } else {
        // Verify conversation partner exists and is valid
        $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND is_verified = 1");
        $stmt->execute([$conversation_id]);
        
        if ($stmt->rowCount() > 0) {
            // Insert reply
            $subject = "Re: " . ($_POST['original_subject'] ?? 'Conversation');
            $stmt = $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, subject, message, created_at) 
                                 VALUES (?, ?, ?, ?, NOW())");
            
            if ($stmt->execute([$current_user_id, $conversation_id, $subject, $reply_message])) {
                // Create notification
                $stmt = $pdo->prepare("INSERT INTO notifications (user_id, notification_type, message, channel, status, created_at) 
                                     VALUES (?, 'message', 'You have a new reply', 'inapp', 'pending', NOW())");
                $stmt->execute([$conversation_id]);
                
                $success = 'Reply sent successfully!';
            } else {
                $error = 'Failed to send reply';
            }
        } else {
            $error = 'Invalid conversation';
        }
    }
}

// Get user's conversations
$conversations_query = "SELECT 
    DISTINCT 
    CASE 
        WHEN m.sender_id = :user_id THEN m.receiver_id
        ELSE m.sender_id
    END as other_user_id,
    u.email as other_user_email,
    u.user_type as other_user_type,
    (SELECT MAX(created_at) FROM messages 
     WHERE (sender_id = :user_id AND receiver_id = other_user_id)
        OR (sender_id = other_user_id AND receiver_id = :user_id)) as last_message_time,
    (SELECT message FROM messages 
     WHERE (sender_id = :user_id AND receiver_id = other_user_id)
        OR (sender_id = other_user_id AND receiver_id = :user_id)
     ORDER BY created_at DESC LIMIT 1) as last_message,
    (SELECT COUNT(*) FROM messages 
     WHERE receiver_id = :user_id 
        AND sender_id = other_user_id 
        AND is_read = 0) as unread_count
FROM messages m
LEFT JOIN users u ON u.id = CASE 
    WHEN m.sender_id = :user_id THEN m.receiver_id
    ELSE m.sender_id
END
WHERE m.sender_id = :user_id OR m.receiver_id = :user_id
GROUP BY other_user_id
ORDER BY last_message_time DESC";

$stmt = $pdo->prepare($conversations_query);
$stmt->execute(['user_id' => $current_user_id]);
$conversations = $stmt->fetchAll();

// Get conversation messages if viewing a specific conversation
$messages = [];
$other_user = null;
if ($conversation_id > 0) {
    // Verify user can view this conversation
    $stmt = $pdo->prepare("SELECT * FROM messages 
                          WHERE (sender_id = :user_id AND receiver_id = :conv_id)
                             OR (sender_id = :conv_id AND receiver_id = :user_id)");
    $stmt->execute(['user_id' => $current_user_id, 'conv_id' => $conversation_id]);
    
    if ($stmt->rowCount() > 0) {
        // Get other user info
        $stmt = $pdo->prepare("SELECT u.id, u.email, u.user_type, 
                               COALESCE(e.company_name, CONCAT(s.first_name, ' ', s.last_name), 'User') as display_name
                               FROM users u
                               LEFT JOIN employers e ON u.id = e.user_id AND u.user_type = 'employer'
                               LEFT JOIN seekers s ON u.id = s.user_id AND u.user_type = 'seeker'
                               WHERE u.id = ?");
        $stmt->execute([$conversation_id]);
        $other_user = $stmt->fetch();
        
        // Get messages
        $stmt = $pdo->prepare("SELECT m.*, 
                              u_sender.email as sender_email,
                              u_sender.user_type as sender_user_type,
                              COALESCE(e_sender.company_name, CONCAT(s_sender.first_name, ' ', s_sender.last_name), u_sender.email) as sender_name
                              FROM messages m
                              JOIN users u_sender ON m.sender_id = u_sender.id
                              LEFT JOIN employers e_sender ON u_sender.id = e_sender.user_id AND u_sender.user_type = 'employer'
                              LEFT JOIN seekers s_sender ON u_sender.id = s_sender.user_id AND u_sender.user_type = 'seeker'
                              WHERE (m.sender_id = :user_id AND m.receiver_id = :conv_id)
                                 OR (m.sender_id = :conv_id AND m.receiver_id = :user_id)
                              ORDER BY m.created_at ASC");
        $stmt->execute(['user_id' => $current_user_id, 'conv_id' => $conversation_id]);
        $messages = $stmt->fetchAll();
        
        // Mark messages as read
        $stmt = $pdo->prepare("UPDATE messages SET is_read = 1, read_at = NOW() 
                              WHERE receiver_id = :user_id 
                              AND sender_id = :conv_id 
                              AND is_read = 0");
        $stmt->execute(['user_id' => $current_user_id, 'conv_id' => $conversation_id]);
    } else {
        $conversation_id = 0;
        $error = 'You cannot view this conversation';
    }
}

// Get user's applications (for employers to message about applications)
$applications_list = [];
if ($user_type === 'employer') {
    $stmt = $pdo->prepare("SELECT id FROM employers WHERE user_id = ?");
    $stmt->execute([$current_user_id]);
    $employer_id_row = $stmt->fetch();
    
    if ($employer_id_row) {
        $employer_id = $employer_id_row['id'];
        $stmt = $pdo->prepare("SELECT a.id, j.title, CONCAT(s.first_name, ' ', s.last_name) as seeker_name, 
                              u.id as seeker_user_id
                              FROM applications a
                              JOIN jobs j ON a.job_id = j.id
                              JOIN seekers s ON a.seeker_id = s.id
                              JOIN users u ON s.user_id = u.id
                              WHERE j.employer_id = ?
                              ORDER BY a.applied_at DESC
                              LIMIT 10");
        $stmt->execute([$employer_id]);
        $applications_list = $stmt->fetchAll();
    }
}

// Get user's saved candidates (for employers)
$saved_candidates = [];
if ($user_type === 'employer') {
    $stmt = $pdo->prepare("SELECT id FROM employers WHERE user_id = ?");
    $stmt->execute([$current_user_id]);
    $employer_id_row = $stmt->fetch();
    
    if ($employer_id_row) {
        $employer_id = $employer_id_row['id'];
        $stmt = $pdo->prepare("SELECT sc.id, CONCAT(s.first_name, ' ', s.last_name) as seeker_name, 
                              u.id as seeker_user_id, sc.notes
                              FROM saved_candidates sc
                              JOIN seekers s ON sc.seeker_id = s.id
                              JOIN users u ON s.user_id = u.id
                              WHERE sc.employer_id = ?
                              ORDER BY sc.created_at DESC");
        $stmt->execute([$employer_id]);
        $saved_candidates = $stmt->fetchAll();
    }
}

include 'includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="row">
        <!-- Sidebar - Conversations List -->
        <div class="col-md-4 col-lg-3">
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-comments"></i> Conversations</h5>
                    <button class="btn btn-light btn-sm" onclick="$('#newMessageModal').modal('show')">
                        <i class="fas fa-plus"></i> New
                    </button>
                </div>
                <div class="list-group list-group-flush" style="max-height: 600px; overflow-y: auto;">
                    <?php if (!empty($conversations)): ?>
                        <?php foreach ($conversations as $conv): ?>
                            <a href="messages.php?conversation=<?php echo $conv['other_user_id']; ?>" 
                               class="list-group-item list-group-item-action <?php echo ($conversation_id == $conv['other_user_id']) ? 'active' : ''; ?>">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <strong>
                                            <?php
                                            // Get display name
                                            $stmt = $pdo->prepare("SELECT 
                                                                  COALESCE(e.company_name, CONCAT(s.first_name, ' ', s.last_name), u.email) as display_name
                                                                  FROM users u
                                                                  LEFT JOIN employers e ON u.id = e.user_id AND u.user_type = 'employer'
                                                                  LEFT JOIN seekers s ON u.id = s.user_id AND u.user_type = 'seeker'
                                                                  WHERE u.id = ?");
                                            $stmt->execute([$conv['other_user_id']]);
                                            $name_row = $stmt->fetch();
                                            echo htmlspecialchars($name_row['display_name'] ?? $conv['other_user_email']);
                                            ?>
                                        </strong>
                                        <br>
                                        <small class="<?php echo ($conversation_id == $conv['other_user_id']) ? 'text-white' : 'text-muted'; ?>">
                                            <?php echo ucfirst($conv['other_user_type']); ?>
                                        </small>
                                    </div>
                                    <?php if (($conv['unread_count'] ?? 0) > 0 && $conversation_id != $conv['other_user_id']): ?>
                                        <span class="badge bg-danger rounded-pill"><?php echo $conv['unread_count']; ?></span>
                                    <?php endif; ?>
                                </div>
                                <small class="<?php echo ($conversation_id == $conv['other_user_id']) ? 'text-white-50' : 'text-muted'; ?>">
                                    <?php 
                                    $last_msg = isset($conv['last_message']) ? substr($conv['last_message'], 0, 50) : '';
                                    echo htmlspecialchars($last_msg) . (isset($conv['last_message']) && strlen($conv['last_message']) > 50 ? '...' : '');
                                    ?>
                                </small>
                                <br>
                                <small class="<?php echo ($conversation_id == $conv['other_user_id']) ? 'text-white-50' : 'text-muted'; ?>">
                                    <?php echo isset($conv['last_message_time']) ? date('M d, H:i', strtotime($conv['last_message_time'])) : ''; ?>
                                </small>
                            </a>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="list-group-item text-center text-muted">
                            <i class="fas fa-inbox fa-3x mb-3"></i>
                            <p>No conversations yet</p>
                            <button class="btn btn-primary btn-sm" onclick="$('#newMessageModal').modal('show')">
                                Start a conversation
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php if ($user_type === 'employer' && !empty($applications_list)): ?>
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0"><i class="fas fa-file-alt"></i> Recent Applications</h5>
                </div>
                <div class="list-group list-group-flush">
                    <?php foreach ($applications_list as $app): ?>
                        <a href="messages.php?action=new&application=<?php echo $app['id']; ?>" 
                           class="list-group-item list-group-item-action">
                            <strong><?php echo htmlspecialchars($app['title']); ?></strong>
                            <br>
                            <small>Applicant: <?php echo htmlspecialchars($app['seeker_name']); ?></small>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if ($user_type === 'employer' && !empty($saved_candidates)): ?>
            <div class="card shadow-sm">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0"><i class="fas fa-user-check"></i> Saved Candidates</h5>
                </div>
                <div class="list-group list-group-flush">
                    <?php foreach ($saved_candidates as $candidate): ?>
                        <a href="messages.php?action=new&candidate=<?php echo $candidate['seeker_user_id']; ?>" 
                           class="list-group-item list-group-item-action">
                            <strong><?php echo htmlspecialchars($candidate['seeker_name']); ?></strong>
                            <?php if (!empty($candidate['notes'])): ?>
                                <br>
                                <small class="text-muted"><?php echo htmlspecialchars(substr($candidate['notes'], 0, 30)); ?>...</small>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Main Content - Messages -->
        <div class="col-md-8 col-lg-9">
            <?php if ($conversation_id > 0 && $other_user): ?>
                <!-- Conversation View -->
                <div class="card shadow-sm">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <div>
                            <h5 class="mb-0">
                                <i class="fas fa-user-circle"></i> 
                                <?php echo htmlspecialchars($other_user['display_name'] ?? $other_user['email']); ?>
                                <small class="text-muted">(<?php echo ucfirst($other_user['user_type']); ?>)</small>
                            </h5>
                        </div>
                        <button class="btn btn-outline-primary btn-sm" onclick="window.location.href='messages.php'">
                            <i class="fas fa-arrow-left"></i> Back to conversations
                        </button>
                    </div>
                    
                    <div class="card-body" style="height: 500px; overflow-y: auto;" id="messageContainer">
                        <?php if (empty($messages)): ?>
                            <div class="text-center text-muted py-5">
                                <i class="fas fa-comment-dots fa-4x mb-3"></i>
                                <p>No messages yet. Start the conversation!</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($messages as $msg): ?>
                                <div class="mb-3 <?php echo ($msg['sender_id'] == $current_user_id) ? 'text-end' : 'text-start'; ?>">
                                    <div class="d-inline-block" style="max-width: 70%;">
                                        <div class="card <?php echo ($msg['sender_id'] == $current_user_id) ? 'bg-primary text-white' : 'bg-light'; ?>">
                                            <div class="card-body p-2 p-3">
                                                <?php if (!empty($msg['subject']) && $msg === reset($messages)): ?>
                                                    <strong>Subject: <?php echo htmlspecialchars($msg['subject']); ?></strong>
                                                    <hr class="my-1">
                                                <?php endif; ?>
                                                <p class="mb-1"><?php echo nl2br(htmlspecialchars($msg['message'])); ?></p>
                                                <small class="<?php echo ($msg['sender_id'] == $current_user_id) ? 'text-white-50' : 'text-muted'; ?>">
                                                    <?php echo date('M d, Y H:i', strtotime($msg['created_at'])); ?>
                                                    <?php if ($msg['sender_id'] != $current_user_id): ?>
                                                        - <?php echo htmlspecialchars($msg['sender_name']); ?>
                                                    <?php endif; ?>
                                                </small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    
                    <div class="card-footer bg-white">
                        <form method="POST" action="messages.php?conversation=<?php echo $conversation_id; ?>" onsubmit="return validateReply()">
                            <input type="hidden" name="original_subject" value="<?php echo isset($messages[0]['subject']) ? htmlspecialchars($messages[0]['subject']) : ''; ?>">
                            <div class="input-group">
                                <textarea class="form-control" name="reply_message" id="replyMessage" rows="2" placeholder="Type your reply..." required></textarea>
                                <button class="btn btn-primary" type="submit" name="reply_message">
                                    <i class="fas fa-paper-plane"></i> Send
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <script>
                // Scroll to bottom of message container
                document.getElementById('messageContainer').scrollTop = document.getElementById('messageContainer').scrollHeight;
                
                function validateReply() {
                    const reply = document.getElementById('replyMessage').value.trim();
                    if (reply === '') {
                        alert('Please enter a message');
                        return false;
                    }
                    return true;
                }
                </script>
                
            <?php elseif ($action === 'new' || isset($_GET['success']) || isset($_GET['application']) || isset($_GET['candidate'])): ?>
                <!-- New Message Form (Already handled by modal) -->
                <div class="card shadow-sm">
                    <div class="card-header bg-white">
                        <h5 class="mb-0"><i class="fas fa-edit"></i> Compose New Message</h5>
                    </div>
                    <div class="card-body">
                        <?php if (isset($_GET['success']) && $_GET['success'] === 'sent'): ?>
                            <div class="alert alert-success alert-dismissible fade show" role="alert">
                                Message sent successfully!
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>
                        
                        <p>Click the "New Message" button to compose a message.</p>
                        <button class="btn btn-primary" onclick="$('#newMessageModal').modal('show')">
                            <i class="fas fa-plus"></i> Compose New Message
                        </button>
                    </div>
                </div>
            <?php else: ?>
                <!-- Welcome Screen -->
                <div class="card shadow-sm text-center py-5">
                    <div class="card-body">
                        <i class="fas fa-envelope-open-text fa-5x text-muted mb-4"></i>
                        <h3>Welcome to your Messages</h3>
                        <p class="text-muted mb-4">Select a conversation from the sidebar or start a new one</p>
                        <button class="btn btn-primary btn-lg" onclick="$('#newMessageModal').modal('show')">
                            <i class="fas fa-plus-circle"></i> New Message
                        </button>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- New Message Modal -->
<div class="modal fade" id="newMessageModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-comment"></i> New Message</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="messages.php" onsubmit="return validateNewMessage()">
                <div class="modal-body">
                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?php echo $error; ?></div>
                    <?php endif; ?>
                    
                    <div class="mb-3">
                        <label class="form-label">Message Type</label>
                        <select class="form-select" name="receiver_type" id="receiverType" onchange="toggleReceiverFields()" required>
                            <option value="">Select recipient type</option>
                            <?php if ($user_type !== 'admin'): ?>
                                <option value="admin">Admin</option>
                            <?php endif; ?>
                            <option value="employer">Employer (by company name)</option>
                            <option value="seeker">Job Seeker (by ID or email)</option>
                            <?php if ($user_type === 'employer'): ?>
                                <option value="specific_application">About an Application</option>
                            <?php endif; ?>
                        </select>
                    </div>
                    
                    <!-- Admin field (no additional input needed) -->
                    
                    <!-- Employer field -->
                    <div class="mb-3 receiver-field" id="employerField" style="display: none;">
                        <label class="form-label">Company Name</label>
                        <input type="text" class="form-control" name="company_name" placeholder="Enter company name">
                        <small class="text-muted">We'll find the company and send your message</small>
                    </div>
                    
                    <!-- Seeker field -->
                    <div class="mb-3 receiver-field" id="seekerField" style="display: none;">
                        <label class="form-label">Seeker ID or Email</label>
                        <input type="text" class="form-control" name="seeker_identifier" placeholder="Enter seeker ID or email">
                        <small class="text-muted">You can message other job seekers if you have their ID or email</small>
                    </div>
                    
                    <!-- Application field -->
                    <div class="mb-3 receiver-field" id="applicationField" style="display: none;">
                        <label class="form-label">Select Application</label>
                        <select class="form-select" name="application_id">
                            <option value="">Choose an application</option>
                            <?php if (!empty($applications_list)): ?>
                                <?php foreach ($applications_list as $app): ?>
                                    <option value="<?php echo $app['id']; ?>" 
                                        <?php echo (isset($_GET['application']) && $_GET['application'] == $app['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($app['title']); ?> - <?php echo htmlspecialchars($app['seeker_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Subject (Optional)</label>
                        <input type="text" class="form-control" name="subject" placeholder="Enter subject">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Message</label>
                        <textarea class="form-control" name="message" id="messageText" rows="5" placeholder="Type your message here..." required></textarea>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="fas fa-shield-alt"></i> 
                        <strong>Privacy & Security:</strong> Your messages are private and only visible to you and the recipient. 
                        All users are verified before they can receive messages.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" name="send_message">
                        <i class="fas fa-paper-plane"></i> Send Message
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function toggleReceiverFields() {
    const type = document.getElementById('receiverType').value;
    
    // Hide all fields
    document.querySelectorAll('.receiver-field').forEach(field => {
        field.style.display = 'none';
        field.querySelectorAll('input, select').forEach(input => {
            input.required = false;
        });
    });
    
    // Show relevant field
    if (type === 'employer') {
        document.getElementById('employerField').style.display = 'block';
        document.getElementById('employerField').querySelector('input').required = true;
    } else if (type === 'seeker') {
        document.getElementById('seekerField').style.display = 'block';
        document.getElementById('seekerField').querySelector('input').required = true;
    } else if (type === 'specific_application') {
        document.getElementById('applicationField').style.display = 'block';
        document.getElementById('applicationField').querySelector('select').required = true;
    }
}

function validateNewMessage() {
    const type = document.getElementById('receiverType').value;
    if (!type) {
        alert('Please select a recipient type');
        return false;
    }
    
    const message = document.getElementById('messageText').value.trim();
    if (!message) {
        alert('Please enter a message');
        return false;
    }
    
    if (type === 'employer') {
        const company = document.querySelector('input[name="company_name"]').value.trim();
        if (!company) {
            alert('Please enter a company name');
            return false;
        }
    } else if (type === 'seeker') {
        const seeker = document.querySelector('input[name="seeker_identifier"]').value.trim();
        if (!seeker) {
            alert('Please enter seeker ID or email');
            return false;
        }
    } else if (type === 'specific_application') {
        const app = document.querySelector('select[name="application_id"]').value;
        if (!app) {
            alert('Please select an application');
            return false;
        }
    }
    
    return true;
}

// Auto-show modal if coming from application or candidate
<?php if (isset($_GET['application']) || isset($_GET['candidate'])): ?>
    document.addEventListener('DOMContentLoaded', function() {
        $('#newMessageModal').modal('show');
        
        <?php if (isset($_GET['application'])): ?>
        document.getElementById('receiverType').value = 'specific_application';
        toggleReceiverFields();
        <?php elseif (isset($_GET['candidate'])): ?>
        document.getElementById('receiverType').value = 'seeker';
        toggleReceiverFields();
        document.querySelector('input[name="seeker_identifier"]').value = '<?php echo (int)$_GET['candidate']; ?>';
        <?php endif; ?>
    });
<?php endif; ?>

// Auto-show modal on page load if no conversation and not on new action
<?php if ($conversation_id == 0 && $action !== 'new' && !isset($_GET['success'])): ?>
    // Don't auto-show, let user click the button
<?php endif; ?>
</script>

<style>
.list-group-item.active small {
    color: rgba(255,255,255,0.8) !important;
}
#messageContainer {
    scroll-behavior: smooth;
}
.card-header .btn-light {
    color: #333;
    border-color: #ddd;
}
.card-header .btn-light:hover {
    background-color: #f8f9fa;
    border-color: #c0c0c0;
}
</style>

<?php include 'includes/footer.php'; ?>