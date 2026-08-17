<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once 'lib/fpdf.php';
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id   = $_SESSION['user_id'];
$enroll_id = isset($_GET['enroll_id']) ? (int)$_GET['enroll_id'] : 0;
if (!$enroll_id) { die('Invalid enrollment.'); }

// Scope to the logged-in user AND join the real courses table — previously
// this pulled any enroll_id regardless of owner, and matched course titles
// against a static config array instead of the database.
$stmt = $conn->prepare("
    SELECT e.id, e.amount_paid, e.payment_status, e.created_at,
           c.title AS course_title,
           u.full_name, u.email
    FROM enrollments e
    JOIN courses c ON c.id = e.course_id
    JOIN users u   ON u.id = e.user_id
    WHERE e.id = :eid AND e.user_id = :uid
    LIMIT 1
");
$stmt->execute([':eid' => $enroll_id, ':uid' => $user_id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) { http_response_code(404); die('Receipt not found.'); }

if (!defined('CURRENCY_SYMBOL')) {
    define('CURRENCY_SYMBOL', '£'); // FPDF's core fonts don't render ₦; spell it out in the PDF
}

$pdf = new FPDF();
$pdf->AddPage();
$pdf->SetFont('Helvetica', '', 16);
$pdf->Cell(0, 10, "Johnnyfingers Academy", 0, 1);
$pdf->SetFont('Helvetica', '', 12);
$pdf->Ln();
$pdf->Cell(0, 8, "Receipt ID: " . $enroll_id, 0, 1);
$pdf->Cell(0, 8, "Student: " . ($row['full_name'] ?? '-') . ' (' . ($row['email'] ?? '') . ')', 0, 1);
$pdf->Cell(0, 8, "Course: " . $row['course_title'], 0, 1);
$pdf->Cell(0, 8, "Amount Paid: " . CURRENCY_SYMBOL . number_format((float)$row['amount_paid']), 0, 1);
$pdf->Cell(0, 8, "Status: " . $row['payment_status'], 0, 1);
$pdf->Cell(0, 8, "Date: " . $row['created_at'], 0, 1);

$pdf->Ln(8);
$pdf->Cell(0, 8, "Thank you for your payment.", 0, 1);

$pdf->Output('I', 'receipt-' . $enroll_id . '.pdf');
