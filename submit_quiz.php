<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
header('Content-Type: application/json');
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!$data || empty($data['course_id']) || !isset($data['answers'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid payload']);
    exit();
}

$user_id   = $_SESSION['user_id'];
$course_db = (int)$data['course_id'];
$answers   = $data['answers'];

// Confirm the student is actually enrolled & paid for this course before
// grading anything — previously any authenticated user could submit a
// score for an arbitrary course_id.
try {
    $enr = $conn->prepare("SELECT id FROM enrollments WHERE user_id = :uid AND course_id = :cid AND payment_status = 'completed' LIMIT 1");
    $enr->execute([':uid' => $user_id, ':cid' => $course_db]);
    if (!$enr->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Not enrolled in this course']);
        exit();
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit();
}

// Resolve course_code, then the quiz + correct answers for it — the same
// `quizzes` / `quiz_questions` tables the admin panel writes to.
try {
    $c = $conn->prepare("SELECT course_code FROM courses WHERE id = :id");
    $c->execute([':id' => $course_db]);
    $course_code = $c->fetchColumn();
} catch (PDOException $e) {
    $course_code = null;
}

if (!$course_code) {
    echo json_encode(['success' => false, 'message' => 'Unknown course']);
    exit();
}

try {
    $qstmt = $conn->prepare("SELECT id, passing_percent FROM quizzes WHERE course_code = :code ORDER BY created_at DESC LIMIT 1");
    $qstmt->execute([':code' => $course_code]);
    $quiz_row = $qstmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $quiz_row = null;
}

if (!$quiz_row) {
    echo json_encode(['success' => false, 'message' => 'No quiz configured for this course']);
    exit();
}

$qq = $conn->prepare("SELECT answer_index FROM quiz_questions WHERE quiz_id = :qid ORDER BY id ASC");
$qq->execute([':qid' => $quiz_row['id']]);
$correct_answers = $qq->fetchAll(PDO::FETCH_COLUMN, 0);

$total   = count($correct_answers);
$correct = 0;
for ($i = 0; $i < $total; $i++) {
    $expected = (int)$correct_answers[$i];
    $given    = isset($answers[$i]) ? (int)$answers[$i] : null;
    if ($given === $expected) $correct++;
}

$score_percent = $total > 0 ? (int)round(($correct / $total) * 100) : 0;
$passing = (int)$quiz_row['passing_percent'];
$passed  = $score_percent >= $passing ? 1 : 0;

// quiz_attempts already exists per schema.sql (course_id INT UNSIGNED, FK'd
// to courses.id) — no need to re-create it here with a different shape.
try {
    $ins = $conn->prepare("INSERT INTO quiz_attempts (user_id, course_id, score, passed) VALUES (:uid, :cid, :score, :passed)");
    $ins->execute([':uid' => $user_id, ':cid' => $course_db, ':score' => $score_percent, ':passed' => $passed]);
} catch (PDOException $e) {
    error_log('Quiz save failed: ' . $e->getMessage());
}

echo json_encode(['success' => true, 'passed' => (bool)$passed, 'score' => $score_percent, 'correct' => $correct, 'total' => $total]);
exit();
