<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once 'db.php';

if (strtolower($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: login.php');
    exit();
}

// Only accept POST — this endpoint changes data.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: admin_courses.php');
    exit();
}

// Same CSRF check every other admin write action uses (see admin.php).
// admin_courses.php now sends this token in a hidden field.
if (empty($_POST['csrf_token']) || empty($_SESSION['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    http_response_code(403);
    die('Security check failed — please refresh admin_courses.php and try again.');
}

// Whitelist the redirect target instead of trusting POST — a raw
// user-supplied redirect is an open-redirect vector.
$ALLOWED_REDIRECTS = ['admin_courses.php'];
$redirect = $_POST['redirect'] ?? 'admin_courses.php';
if (!in_array($redirect, $ALLOWED_REDIRECTS, true)) {
    $redirect = 'admin_courses.php';
}

$code   = trim($_POST['course_code'] ?? '');
$title  = trim($_POST['title'] ?? '');
$active = isset($_POST['active']) ? 1 : 0;

// Price must actually be numeric — course_meta.price_override is a
// DECIMAL(10,2) column (see schema.sql), so reject anything that
// wouldn't cast cleanly.
$price_raw = trim($_POST['price'] ?? '');
$price = null;
if ($price_raw !== '') {
    if (!is_numeric($price_raw) || (float)$price_raw < 0) {
        header('Location: ' . $redirect . '?error=invalid_price');
        exit();
    }
    $price = round((float)$price_raw, 2);
}

if (!$code) {
    header('Location: ' . $redirect);
    exit();
}

// Table is defined in schema.sql — no inline CREATE TABLE here, so
// there's only ever one definition of its column types to keep in sync.
$stmt = $conn->prepare("
    INSERT INTO course_meta (course_code, title_override, price_override, active)
    VALUES (:code, :title, :price, :active)
    ON DUPLICATE KEY UPDATE
        title_override = VALUES(title_override),
        price_override = VALUES(price_override),
        active = VALUES(active)
");
$stmt->execute([
    ':code'   => $code,
    ':title'  => $title !== '' ? $title : null,
    ':price'  => $price,
    ':active' => $active,
]);

header('Location: ' . $redirect . '?saved=1');
exit();
