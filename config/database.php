<?php
// config/database.php
// Database connection configuration using PDO

// Define database credentials for your existing database
define('DB_HOST', 'localhost');
define('DB_NAME', 'trnasnow_db');  // Your existing database
define('DB_USER', 'trnasnow_wise');  // Your existing user
define('DB_PASS', 'Kaokbnia.4040');  // Your existing password
define('DB_CHARSET', 'utf8mb4');

// PDO options for secure connection
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::ATTR_PERSISTENT => true,
];

try {
    // Establish PDO connection
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET,
        DB_USER,
        DB_PASS,
        $options
    );
    
    // Test the connection
    $pdo->query("SELECT 1");
    
} catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    die("Database connection failed. Please try again later.");
}

// Function to get the PDO instance
function getDB() {
    global $pdo;
    return $pdo;
}
?>