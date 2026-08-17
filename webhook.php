<?php
require_once 'db.php';
require_once 'config.php'; // loads .env via Dotenv, populates $_ENV
require_once 'vendor/autoload.php';

// Using $_ENV rather than getenv() — some XAMPP php.ini setups disable
// putenv(), which getenv() depends on, while $_ENV stays populated fine.
\Stripe\Stripe::setApiKey($_ENV['STRIPE_SECRET_KEY']);

$payload    = @file_get_contents('php://input');
$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
$endpoint_secret = $_ENV['STRIPE_WEBHOOK_SECRET'];

try {
    $event = \Stripe\Webhook::constructEvent($payload, $sig_header, $endpoint_secret);
} catch (\UnexpectedValueException $e) {
    http_response_code(400);
    exit();
} catch (\Stripe\Exception\SignatureVerificationException $e) {
    // Signature didn't match — reject. This is what stops anyone from
    // faking a "payment succeeded" call directly against this endpoint.
    http_response_code(400);
    exit();
}

if ($event->type === 'payment_intent.succeeded') {
    $intent      = $event->data->object;
    $user_id     = (int) ($intent->metadata->user_id ?? 0);
    $course_id   = (int) ($intent->metadata->course_id ?? 0);
    $amount_paid = $intent->amount_received / 100; // pence -> pounds

    if ($user_id && $course_id) {
        try {
            $check = $conn->prepare("SELECT id FROM enrollments WHERE user_id = :user_id AND course_id = :course_id");
            $check->execute([':user_id' => $user_id, ':course_id' => $course_id]);
            $existing = $check->fetch();

            if ($existing) {
                $stmt = $conn->prepare("
                    UPDATE enrollments
                    SET payment_status = 'completed', amount_paid = :amount, payment_reference = :ref
                    WHERE user_id = :user_id AND course_id = :course_id
                ");
                $stmt->execute([
                    ':amount'    => $amount_paid,
                    ':ref'       => $intent->id,
                    ':user_id'   => $user_id,
                    ':course_id' => $course_id,
                ]);
                $enrollmentId = $existing['id'];
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO enrollments (user_id, course_id, payment_status, amount_paid, payment_reference)
                    VALUES (:user_id, :course_id, 'completed', :amount, :ref)
                ");
                $stmt->execute([
                    ':user_id'   => $user_id,
                    ':course_id' => $course_id,
                    ':amount'    => $amount_paid,
                    ':ref'       => $intent->id,
                ]);
                $enrollmentId = $conn->lastInsertId();
            }

            // Best-effort receipt email, same as before
            try {
                if (file_exists(__DIR__ . '/lib/send_mail.php')) {
                    require_once __DIR__ . '/lib/send_mail.php';
                    $userStmt = $conn->prepare("SELECT full_name, email FROM users WHERE id = :id");
                    $userStmt->execute([':id' => $user_id]);
                    $u = $userStmt->fetch(PDO::FETCH_ASSOC);

                    $courseStmt = $conn->prepare("SELECT title FROM courses WHERE id = :id");
                    $courseStmt->execute([':id' => $course_id]);
                    $c = $courseStmt->fetch(PDO::FETCH_ASSOC);

                    $receiptUrl = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'example.com') . "/receipt.php?enroll_id=" . $enrollmentId;

                    $subject = 'Payment received — ' . ($c['title'] ?? '');
                    $body  = "<p>Hi " . htmlspecialchars($u['full_name'] ?? '') . ",</p>";
                    $body .= "<p>We have received your payment for <strong>" . htmlspecialchars($c['title'] ?? '') . "</strong>. You can download your receipt <a href=\"" . htmlspecialchars($receiptUrl) . "\">here</a>.</p>";

                    @send_mail($u['email'] ?? '', $u['full_name'] ?? '', $subject, $body);
                }
            } catch (Exception $ex) {
                // non-fatal
            }

        } catch (PDOException $e) {
            http_response_code(500);
            error_log('Stripe webhook DB error: ' . $e->getMessage());
            exit();
        }
    }
} elseif ($event->type === 'payment_intent.payment_failed') {
    // Optional: log or mark a pending row as failed if you pre-create enrollments.
    error_log('Stripe payment failed: ' . $event->data->object->id);
}

http_response_code(200);
echo json_encode(['received' => true]);