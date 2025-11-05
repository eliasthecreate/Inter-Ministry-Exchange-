<?php
// database.php - Secure version
$db_host = 'localhost';
$db_name = 'ministry exchange';
$db_user = 'root';
$db_pass = '';

// Simple security enhancements
try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name", $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
    ]);
} catch(PDOException $e) {
    // Don't show detailed errors to users
    error_log("Database error: " . $e->getMessage());
    die("System temporarily unavailable. Please try again later.");
}

// Simple function to prevent SQL injection
function safe_query($pdo, $sql, $params = []) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}
?>