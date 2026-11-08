<?php
// message.php
session_start();
require_once 'config/database.php';
require_once 'config/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Get user type to determine permissions
$user_id = $_SESSION['user_id'];
$user_type = $_SESSION['user_type'] ?? '';

// Initialize variables
$success_message = '';
$error_message = '';
$candidates = [];

// Fetch candidates/job seekers from database
try {
    // Using the correct table name 'seekers' instead of 'candidates'
    $stmt = $pdo->query("
        SELECT s.id, s.first_name, s.last_name, u.email, s.phone, s.whatsapp_number 
        FROM seekers s 
        JOIN users u ON s.user_id = u.id 
        WHERE u.user_type = 'seeker' 
        ORDER BY s.first_name ASC
    ");
    $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching candidates: " . $e->getMessage());
    $error_message = "Error loading candidates. Please try again later.";
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $recipient_type = $_POST['recipient_type'] ?? '';
    $selected_candidates = $_POST['selected_candidates'] ?? [];
    $message_type = $_POST['message_type'] ?? '';
    $subject = $_POST['subject'] ?? '';
    $message = $_POST['message'] ?? '';
    
    // Validate inputs
    if (empty($selected_candidates) && $recipient_type === 'selected') {
        $error_message = "Please select at least one candidate.";
    } elseif (empty($message)) {
        $error_message = "Please enter a message.";
    } elseif (empty($message_type)) {
        $error_message = "Please select a message type.";
    } else {
        // Process based on message type
        if ($message_type === 'email') {
            $result = sendEmailMessages($selected_candidates, $subject, $message, $recipient_type);
        } elseif ($message_type === 'whatsapp') {
            $result = sendWhatsAppMessages($selected_candidates, $message, $recipient_type);
        } elseif ($message_type === 'both') {
            $email_result = sendEmailMessages($selected_candidates, $subject, $message, $recipient_type);
            $whatsapp_result = sendWhatsAppMessages($selected_candidates, $message, $recipient_type);
            $result = [
                'success' => $email_result['success'] && $whatsapp_result['success'],
                'message' => "Email: " . $email_result['message'] . "<br>WhatsApp: " . $whatsapp_result['message']
            ];
        }
        
        if (isset($result) && $result['success']) {
            $success_message = $result['message'];
        } else {
            $error_message = $result['message'] ?? "Failed to send messages.";
        }
    }
}

/**
 * Send email messages to candidates
 */
function sendEmailMessages($candidate_ids, $subject, $message, $recipient_type) {
    global $pdo;
    
    try {
        // Get candidate emails
        if ($recipient_type === 'all') {
            $stmt = $pdo->query("
                SELECT u.email, s.first_name, s.last_name 
                FROM seekers s 
                JOIN users u ON s.user_id = u.id 
                WHERE u.email IS NOT NULL AND u.email != ''
            ");
        } else {
            $placeholders = implode(',', array_fill(0, count($candidate_ids), '?'));
            $stmt = $pdo->prepare("
                SELECT u.email, s.first_name, s.last_name 
                FROM seekers s 
                JOIN users u ON s.user_id = u.id 
                WHERE s.id IN ($placeholders) 
                AND u.email IS NOT NULL AND u.email != ''
            ");
            $stmt->execute($candidate_ids);
        }
        
        $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($recipients)) {
            return ['success' => false, 'message' => 'No valid email addresses found.'];
        }
        
        // Send emails
        $sent_count = 0;
        $failed_count = 0;
        
        foreach ($recipients as $recipient) {
            $to = $recipient['email'];
            $headers = "From: noreply@jobwll.gt.tc\r\n";
            $headers .= "Reply-To: hr@jobwll.gt.tc\r\n";
            $headers .= "MIME-Version: 1.0\r\n";
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
            
            // Personalize message
            $personalized_message = str_replace(
                ['{first_name}', '{last_name}'],
                [$recipient['first_name'] ?? '', $recipient['last_name'] ?? ''],
                $message
            );
            
            // For local development/testing, log instead of sending
            if (mail($to, $subject, $personalized_message, $headers)) {
                $sent_count++;
                
                // Log the message
                logMessage($recipient['email'], 'email', $subject, $personalized_message);
            } else {
                $failed_count++;
                error_log("Failed to send email to: " . $recipient['email']);
            }
        }
        
        return [
            'success' => true,
            'message' => "Emails sent successfully to $sent_count recipients." . ($failed_count > 0 ? " Failed: $failed_count" : "")
        ];
        
    } catch (Exception $e) {
        error_log("Email error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Email error: ' . $e->getMessage()];
    }
}

/**
 * Send WhatsApp messages to candidates
 */
function sendWhatsAppMessages($candidate_ids, $message, $recipient_type) {
    global $pdo;
    
    try {
        // Get candidate WhatsApp numbers
        if ($recipient_type === 'all') {
            $stmt = $pdo->query("
                SELECT s.id, s.first_name, s.last_name, s.whatsapp_number 
                FROM seekers s 
                WHERE s.whatsapp_number IS NOT NULL AND s.whatsapp_number != ''
            ");
        } else {
            $placeholders = implode(',', array_fill(0, count($candidate_ids), '?'));
            $stmt = $pdo->prepare("
                SELECT s.id, s.first_name, s.last_name, s.whatsapp_number 
                FROM seekers s 
                WHERE s.id IN ($placeholders) 
                AND s.whatsapp_number IS NOT NULL AND s.whatsapp_number != ''
            ");
            $stmt->execute($candidate_ids);
        }
        
        $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($recipients)) {
            return ['success' => false, 'message' => 'No valid WhatsApp numbers found.'];
        }
        
        // For now, just log WhatsApp messages (you'll need to integrate with actual WhatsApp API)
        $sent_count = 0;
        $failed_count = 0;
        
        foreach ($recipients as $recipient) {
            // Personalize message
            $personalized_message = str_replace(
                ['{first_name}', '{last_name}'],
                [$recipient['first_name'] ?? '', $recipient['last_name'] ?? ''],
                $message
            );
            
            // Log the message (for now)
            logMessage($recipient['whatsapp_number'], 'whatsapp', '', $personalized_message);
            $sent_count++;
            
            // Rate limiting - wait a bit between messages
            usleep(500000); // 0.5 seconds
        }
        
        return [
            'success' => true,
            'message' => "WhatsApp messages logged successfully for $sent_count recipients. (Integration pending)"
        ];
        
    } catch (Exception $e) {
        error_log("WhatsApp error: " . $e->getMessage());
        return ['success' => false, 'message' => 'WhatsApp error: ' . $e->getMessage()];
    }
}

/**
 * Log messages to database
 */
function logMessage($recipient, $type, $subject, $message) {
    global $pdo;
    
    try {
        // Check if message_logs table exists
        $stmt = $pdo->prepare("
            INSERT INTO message_logs (recipient, message_type, subject, message, sent_at, sent_by) 
            VALUES (?, ?, ?, ?, NOW(), ?)
        ");
        $stmt->execute([$recipient, $type, $subject, $message, $_SESSION['user_id'] ?? null]);
    } catch (PDOException $e) {
        // If table doesn't exist, create it
        if ($e->getCode() == '42S02') { // Table not found
            createMessageLogsTable();
            // Try again
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO message_logs (recipient, message_type, subject, message, sent_at, sent_by) 
                    VALUES (?, ?, ?, ?, NOW(), ?)
                ");
                $stmt->execute([$recipient, $type, $subject, $message, $_SESSION['user_id'] ?? null]);
            } catch (PDOException $e2) {
                error_log("Failed to log message: " . $e2->getMessage());
            }
        } else {
            error_log("Failed to log message: " . $e->getMessage());
        }
    }
}

/**
 * Create message_logs table if it doesn't exist
 */
function createMessageLogsTable() {
    global $pdo;
    
    $sql = "
        CREATE TABLE IF NOT EXISTS message_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            recipient VARCHAR(255) NOT NULL,
            message_type ENUM('email', 'whatsapp') NOT NULL,
            subject VARCHAR(255),
            message TEXT NOT NULL,
            sent_at DATETIME NOT NULL,
            sent_by INT,
            status ENUM('sent', 'failed') DEFAULT 'sent',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_recipient (recipient),
            INDEX idx_sent_at (sent_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    
    try {
        $pdo->exec($sql);
    } catch (PDOException $e) {
        error_log("Failed to create message_logs table: " . $e->getMessage());
    }
}
?>