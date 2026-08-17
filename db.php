<?php
// C:\xampp\htdocs\johnyfinger\db.php

// 1. Pull dynamic values from Render's cloud settings, or default to XAMPP values locally
$host    = getenv('DB_HOST') ?: 'localhost';
$db      = getenv('DB_NAME') ?: 'johnnyfingers_db';
$user    = getenv('DB_USER') ?: 'root';
$pass    = getenv('DB_PASS') ?: ''; 
$port    = getenv('DB_PORT') ?: '3306'; // Support custom cloud database ports
$charset = 'utf8mb4';

// 2. Format the connection string with the host and port dynamically
$dsn = "mysql:host=$host;port=$port;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    // Keeps $conn exactly the same so all your other project files continue to work
    $conn = new PDO($dsn, $user, $pass, $options); 
} catch (\PDOException $e) {
    die("Database Connection Error: " . $e->getMessage());
}
?>
