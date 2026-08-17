<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once 'db.php';
require_once 'config.php'; // loads .env via Dotenv, populates $_ENV
require_once 'vendor/autoload.php'; // composer require stripe/stripe-php

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not logged in']);
    exit();
}

$user_id     = $_SESSION['user_id'];
$course_code = $_GET['course'] ?? ($_POST['course'] ?? null);

if (!$course_code) {
    http_response_code(400);
    echo json_encode(['error' => 'No course provided']);
    exit();
}

// Load course from DB — never trust a price sent from the client
try {
    $stmt = $conn->prepare("SELECT id, title, price FROM courses WHERE course_code = :code LIMIT 1");
    $stmt->execute([':code' => $course_code]);
    $course = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$course) {
        http_response_code(404);
        echo json_encode(['error' => 'Course not found']);
        exit();
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
    exit();
}

// Stripe secret key comes from the environment, never hardcoded.
// Using $_ENV rather than getenv() — some XAMPP php.ini setups disable
// putenv(), which getenv() depends on, while $_ENV stays populated fine.
\Stripe\Stripe::setApiKey($_ENV['STRIPE_SECRET_KEY']);

// Stripe wants the amount in the smallest currency unit — pence for GBP
$amountInPence = (int) round(((float)$course['price']) * 100);

try {
    $intent = \Stripe\PaymentIntent::create([
        'amount'   => $amountInPence,
        'currency' => 'gbp',
        // automatic_payment_methods lets Stripe decide which methods to
        // show (card, Apple Pay, etc.) and it applies SCA/3DS automatically
        // for any card that requires it — no extra config needed for UK compliance.
        'automatic_payment_methods' => ['enabled' => true],
        'metadata' => [
            'user_id'     => (string) $user_id,
            'course_id'   => (string) $course['id'],
            'course_code' => $course_code,
        ],
    ]);
} catch (\Stripe\Exception\ApiErrorException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit();
}

echo json_encode([
    'clientSecret' => $intent->client_secret,
    'course_title' => $course['title'],
    'amount'       => $course['price'],
]);