<?php
// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'csit6_prelim'); // Change this to your actual database name
define('DB_USER', 'root'); // Change to your database username
define('DB_PASS', ''); // Change to your database password

// Create connection
function getDBConnection() {
    try {
        $pdo = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]
        );
        return $pdo;
    } catch(PDOException $e) {
        // For development - show error details
        die("Connection failed: " . $e->getMessage());
        // For production - log error and show generic message
        // error_log("Database Connection Error: " . $e->getMessage());
        // die("Database connection error. Please contact administrator.");
    }
}
?>