<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
header('Content-Type: application/json');
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
$course_id = $input['course_id'] ?? null;
$lesson_id = $input['lesson_id'] ?? null;
$user_id = $_SESSION['user_id'];

if (!$course_id || !$lesson_id) {
    echo json_encode(['success' => false, 'message' => 'Missing parameters']);
    exit();
}

try {
    // Use prepared statements and rely on UNIQUE(user_id, course_id, lesson_id)
    $stmt = $conn->prepare("INSERT INTO lesson_progress (user_id, course_id, lesson_id, completed, created_at)
        VALUES (:user_id, :course_id, :lesson_id, 1, NOW())
        ON DUPLICATE KEY UPDATE completed = 1, created_at = NOW()");
    $stmt->execute([
        ':user_id' => $user_id,
        ':course_id' => $course_id,
        ':lesson_id' => $lesson_id
    ]);

    // return updated completed count for this course
    $countStmt = $conn->prepare("SELECT COUNT(*) FROM lesson_progress WHERE user_id = :user_id AND course_id = :course_id AND completed = 1");
    $countStmt->execute([':user_id' => $user_id, ':course_id' => $course_id]);
    $completedCount = (int)$countStmt->fetchColumn();

    echo json_encode(['success' => true, 'completed_count' => $completedCount]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

?>