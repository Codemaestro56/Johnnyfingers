<?php
// C:\xampp\htdocs\johnyfinger\db.php
$host = 'localhost';
$db   = 'johnnyfingers_db';
$user = 'root';
$pass = ''; 
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    // $conn is used consistently across all scripts
    $conn = new PDO($dsn, $user, $pass, $options); 
} catch (\PDOException $e) {
    die("Database Connection Error: " . $e->getMessage());
}
?>