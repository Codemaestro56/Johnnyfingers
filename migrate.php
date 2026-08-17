<?php
// Simple migration runner for schema.sql
// Usage: open in browser or run `php migrate.php` from project root.

$host = 'localhost';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;charset=$charset";
try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    die("DB connect failed: " . $e->getMessage());
}

$sqlFile = __DIR__ . '/schema.sql';
if (!file_exists($sqlFile)) {
    die("schema.sql not found at $sqlFile\n");
}

$sql = file_get_contents($sqlFile);
if ($sql === false) die("Failed to read schema.sql\n");

// Split statements by semicolon. This is a simple splitter and assumes
// the schema file does not use custom DELIMITER statements.
$statements = array_filter(array_map('trim', explode(';', $sql)));

echo "Running " . count($statements) . " statements...\n";
$errors = [];
foreach ($statements as $i => $stmt) {
    if ($stmt === '') continue;
    try {
        $pdo->exec($stmt);
    } catch (PDOException $e) {
        $errors[] = ['idx'=>$i, 'error'=>$e->getMessage(), 'sql'=>substr($stmt,0,200)];
    }
}

if (count($errors) === 0) {
    echo "Migration completed successfully.\n";
} else {
    echo "Migration completed with " . count($errors) . " errors:\n";
    foreach ($errors as $err) {
        echo "[{$err['idx']}] {$err['error']} -- SQL: {$err['sql']}\n";
    }
}

echo "Done.\n";

?>
