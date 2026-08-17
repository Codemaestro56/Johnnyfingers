<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once 'config.php'; // loads .env via Dotenv, populates $_ENV
require_once 'vendor/autoload.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Using $_ENV rather than getenv() — some XAMPP php.ini setups disable
// putenv(), which getenv() depends on, while $_ENV stays populated fine.
\Stripe\Stripe::setApiKey($_ENV['STRIPE_SECRET_KEY']);

$clientSecret = $_GET['payment_intent_client_secret'] ?? null;
if (!$clientSecret) {
    header("Location: dashboard.php");
    exit();
}

// We only READ status here for a friendly message. The webhook (webhook.php)
// is what actually marks the enrollment complete — don't duplicate that
// logic here, or you risk double-crediting or racing the webhook.
$intent_id = $_GET['payment_intent'] ?? null;
$status = 'processing';
if ($intent_id) {
    try {
        $intent = \Stripe\PaymentIntent::retrieve($intent_id);
        $status = $intent->status; // succeeded | processing | requires_payment_method | ...
    } catch (\Stripe\Exception\ApiErrorException $e) {
        $status = 'unknown';
    }
}

$message = [
    'succeeded' => "Payment successful! Your enrollment is being finalized — it'll appear on your dashboard in a moment.",
    'processing' => "Your payment is still processing. We'll email you once it's confirmed.",
    'requires_payment_method' => "That payment didn't go through. Please try again.",
][$status] ?? "We're checking your payment status.";

header("Location: dashboard.php?payment=" . urlencode($status));
exit();