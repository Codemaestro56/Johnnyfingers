<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once 'db.php';

// 1. Auth Guard: Make sure student is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id     = $_SESSION['user_id'];
$reference   = $_GET['reference'] ?? null;
$course_code = $_GET['course'] ?? null;

// If no reference was passed in the URL, kill execution
if (!$reference || !$course_code) {
    die("Error: No transaction reference or course provided.");
}

// ---- Load the course from the database by course_code ----
// This ensures we use the actual course_id from the database
try {
    $course_stmt = $conn->prepare("
        SELECT id, title, price
        FROM courses
        WHERE course_code = :code
        LIMIT 1
    ");
    $course_stmt->execute([':code' => $course_code]);
    $course_data = $course_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$course_data) {
        die("Error: Course not found in database.");
    }

    $db_course_id = (int)$course_data['id'];
    $course_title = $course_data['title'];
    $course_price = (float)$course_data['price'];

} catch (PDOException $e) {
    die("Database Error: " . htmlspecialchars($e->getMessage()));
}

// 2. Paystack Secret Key (MUST be your Secret Key sk_test_..., NOT Public Key pk_test_...)
$paystack_secret_key = 'sk_test_ae1e3dc57ce0471b29aa64f200f39f35304f0535'; // Replace with your actual secret key

// 3. Verify payment directly with Paystack API
$url = "https://api.paystack.co/transaction/verify/" . rawurlencode($reference);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer " . trim($paystack_secret_key),
    "Cache-Control: no-cache"
]);

$response = curl_exec($ch);
$err      = curl_error($ch);
curl_close($ch);

if ($err) {
    die("cURL Error: " . htmlspecialchars($err));
}

$result = json_decode($response, true);

// 4. Process DB Enrollment on Successful Payment
if ($result && isset($result['status']) && $result['status'] === true) {
    if (isset($result['data']['status']) && $result['data']['status'] === 'success') {
        
        $amount_paid  = $result['data']['amount'] / 100; // Convert kobo to standard currency unit

        try {
            // Check if enrollment record already exists
            $check = $conn->prepare("SELECT id FROM enrollments WHERE user_id = :user_id AND course_id = :course_id");
            $check->execute([
                ':user_id'   => $user_id,
                ':course_id' => $db_course_id
            ]);

            if ($check->fetch()) {
                // Update existing record
                $stmt = $conn->prepare("UPDATE enrollments SET payment_status = 'completed', amount_paid = :amount WHERE user_id = :user_id AND course_id = :course_id");
                $stmt->execute([
                    ':amount'    => $amount_paid,
                    ':user_id'   => $user_id,
                    ':course_id' => $db_course_id
                ]);
            } else {
                // Insert new enrollment
                $stmt = $conn->prepare("INSERT INTO enrollments (user_id, course_id, payment_status, amount_paid) VALUES (:user_id, :course_id, 'completed', :amount)");
                $stmt->execute([
                    ':user_id'   => $user_id,
                    ':course_id' => $db_course_id,
                    ':amount'    => $amount_paid
                ]);
            }

            // Send receipt email (best-effort)
            try {
                if (file_exists(__DIR__ . '/lib/send_mail.php')) {
                    require_once __DIR__ . '/lib/send_mail.php';
                    $userStmt = $conn->prepare("SELECT full_name, email FROM users WHERE id = :id");
                    $userStmt->execute([':id' => $user_id]);
                    $u = $userStmt->fetch(PDO::FETCH_ASSOC);
                    
                    $enrollmentId = $conn->lastInsertId();
                    $receiptUrl = (isset($_SERVER['REQUEST_SCHEME']) ? $_SERVER['REQUEST_SCHEME'] : 'http') . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . "/receipt.php?enroll_id=" . $enrollmentId;
                    
                    $subject = 'Payment received — ' . $course_title;
                    $body = "<p>Hi " . htmlspecialchars($u['full_name'] ?? '') . ",</p>";
                    $body .= "<p>We have received your payment for <strong>" . htmlspecialchars($course_title) . "</strong>. You can download your receipt <a href=\"" . htmlspecialchars($receiptUrl) . "\">here</a>.</p>";
                    
                    @send_mail($u['email'] ?? '', $u['full_name'] ?? '', $subject, $body);
                }
            } catch (Exception $ex) {
                // non-fatal — email send failure doesn't stop enrollment
            }

            // Redirect to dashboard with success flag
            header("Location: dashboard.php?payment=success");
            exit();

        } catch (PDOException $e) {
            die("Database Error: " . htmlspecialchars($e->getMessage()));
        }

    } else {
        $payment_status = $result['data']['status'] ?? 'unknown';
        die("Payment verification failed. Paystack Status: " . htmlspecialchars($payment_status));
    }
} else {
    $msg = $result['message'] ?? 'Unable to verify transaction.';
    die("Verification error: " . htmlspecialchars($msg));
}
?>