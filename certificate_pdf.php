<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once 'lib/fpdf.php';
require_once 'db.php';

// Previously this file had NO auth check at all — any enroll_id in the URL
// would generate and serve that student's certificate to anyone, logged in
// or not. Now it requires login, and only the enrolled student or an admin
// (for the admin panel's Download/Re-issue actions) may generate it.
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$enroll_id = isset($_GET['enroll_id']) ? (int)$_GET['enroll_id'] : 0;
if (!$enroll_id) { die('Invalid enrollment.'); }

$stmt = $conn->prepare("
    SELECT e.id, e.user_id, e.course_id, e.created_at,
           u.full_name
    FROM enrollments e
    LEFT JOIN users u ON u.id = e.user_id
    WHERE e.id = :id
");
$stmt->execute([':id' => $enroll_id]);
$en = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$en) { die('Not found'); }

$is_owner = (int)$en['user_id'] === (int)$_SESSION['user_id'];
$is_admin = strtolower($_SESSION['role'] ?? '') === 'admin';
if (!$is_owner && !$is_admin) {
    http_response_code(403);
    die('You do not have permission to view this certificate.');
}

$course_db = (int)$en['course_id'];

// Course title + lesson count, from the real courses/lessons tables
// instead of the dead courses_config.php $COURSES array.
$c_stmt = $conn->prepare("SELECT course_code, title FROM courses WHERE id = :id");
$c_stmt->execute([':id' => $course_db]);
$course_row = $c_stmt->fetch(PDO::FETCH_ASSOC);
if (!$course_row) { die('Course not found.'); }
$course_title = $course_row['title'];
$course_code  = $course_row['course_code'];

$lc_stmt = $conn->prepare("SELECT COUNT(*) FROM lessons WHERE course_id = :code");
$lc_stmt->execute([':code' => $course_code]);
$lessons_required = (int)$lc_stmt->fetchColumn();

$lp = $conn->prepare("SELECT COUNT(*) AS c FROM lesson_progress WHERE user_id = :uid AND course_id = :cid AND completed = 1");
$lp->execute([':uid' => $en['user_id'], ':cid' => $course_db]);
$done = (int)$lp->fetch(PDO::FETCH_ASSOC)['c'];
$percent = $lessons_required > 0 ? round(($done / $lessons_required) * 100) : 0;

if ($percent < 100) {
    die('Certificate not available until course is 100% complete.');
}

// Require passing the course quiz before issuing certificate (if quiz exists)
try {
    $q = $conn->prepare("SELECT passed FROM quiz_attempts WHERE user_id = :uid AND course_id = :cid ORDER BY created_at DESC LIMIT 1");
    $q->execute([':uid' => $en['user_id'], ':cid' => $course_db]);
    $qa = $q->fetch(PDO::FETCH_ASSOC);
    if (!$qa || !(int)$qa['passed']) {
        die('Certificate locked — please complete and pass the final course quiz before downloading your certificate.');
    }
} catch (Exception $e) {
    die('Certificate locked — quiz verification failed. Please contact support.');
}

// Issue (or re-fetch) a stable verification ID. Re-downloading the same
// certificate should never mint a new ID — that would break any link a
// student already shared, and orphan old verification records.
try {
    $conn->exec("CREATE TABLE IF NOT EXISTS certificates (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        verify_id VARCHAR(20) NOT NULL UNIQUE,
        user_id INT UNSIGNED NOT NULL,
        course_id INT UNSIGNED NOT NULL,
        issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");

    $find = $conn->prepare("SELECT verify_id, issued_at FROM certificates WHERE user_id = :uid AND course_id = :cid LIMIT 1");
    $find->execute([':uid' => $en['user_id'], ':cid' => $course_db]);
    $existing = $find->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $verify_id = $existing['verify_id'];
        $issued_at = $existing['issued_at'];
    } else {
        $verify_id = strtoupper(substr(bin2hex(random_bytes(6)), 0, 10));
        $ins = $conn->prepare("INSERT INTO certificates (verify_id, user_id, course_id) VALUES (:vid, :uid, :cid)");
        $ins->execute([':vid' => $verify_id, ':uid' => $en['user_id'], ':cid' => $course_db]);
        $issued_at = date('Y-m-d H:i:s');
    }
} catch (PDOException $e) {
    // Fall back to the old non-verifiable ID scheme rather than blocking
    // certificate download entirely if this table can't be created/read.
    $verify_id = 'CERT-' . $enroll_id;
    $issued_at = $en['created_at'];
}

$student_name = $en['full_name'] ?? 'Student';
$issue_date   = date('F j, Y', strtotime($issued_at));

$pdf = new FPDF('L', 'mm', 'A4');
$pdf->AddPage();

// Hybrid rendering: if a designed background template exists, use it and
// just overlay dynamic text (Option B from our earlier design discussion —
// far more polished with near-zero extra effort). Falls back to the
// original hand-drawn layout if no template has been uploaded yet, so this
// never breaks certificate delivery while a design is still in progress.
$template_path = __DIR__ . '/assets/certificate_template.png';

if (file_exists($template_path)) {
    $pdf->Image($template_path, 0, 0, 297, 210);

    $pdf->SetFont('Helvetica', 'B', 28);
    $pdf->SetTextColor(30, 30, 30);
    $pdf->SetXY(0, 90);
    $pdf->Cell(297, 14, $student_name, 0, 1, 'C');

    $pdf->SetFont('Helvetica', '', 16);
    $pdf->SetXY(0, 115);
    $pdf->Cell(297, 10, 'has successfully completed', 0, 1, 'C');

    $pdf->SetFont('Helvetica', 'B', 20);
    $pdf->SetXY(0, 128);
    $pdf->Cell(297, 12, $course_title, 0, 1, 'C');

    $pdf->SetFont('Helvetica', '', 11);
    $pdf->SetXY(0, 150);
    $pdf->Cell(297, 8, 'Issued ' . $issue_date, 0, 1, 'C');

    $pdf->SetFont('Helvetica', '', 9);
    $pdf->SetTextColor(120, 120, 120);
    $pdf->SetXY(0, 195);
    $pdf->Cell(297, 6, 'Verification ID: ' . $verify_id, 0, 1, 'C');
} else {
    // Fallback: original hand-drawn layout, unchanged in look.
    $pdf->SetFont('Helvetica', '', 20);
    $pdf->Cell(0, 10, 'Certificate of Completion', 0, 1, 'C');
    $pdf->Ln(8);
    $pdf->SetFont('Helvetica', '', 12);
    $pdf->Cell(0, 8, 'This certifies that ', 0, 1, 'C');
    $pdf->SetFont('Helvetica', 'B', 16);
    $pdf->Cell(0, 10, $student_name, 0, 1, 'C');
    $pdf->SetFont('Helvetica', '', 12);
    $pdf->MultiCell(0, 8, "has successfully completed the course:\n" . $course_title, 0, 'C');
    $pdf->Ln(12);
    $pdf->Cell(0, 8, 'Certificate ID: ' . $verify_id, 0, 1, 'C');
    $pdf->Cell(0, 8, 'Issue Date: ' . $issue_date, 0, 1, 'C');
    $pdf->Ln(12);
    $pdf->Cell(0, 8, 'Congratulations.', 0, 1, 'C');
}

$pdf->Output('I', 'certificate-' . $verify_id . '.pdf');