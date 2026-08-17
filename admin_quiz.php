<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once 'db.php';

if (strtolower($_SESSION['role'] ?? '') !== 'admin') {
  header('Location: login.php');
  exit();
}

if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$CSRF_TOKEN = $_SESSION['csrf_token'];

function verify_csrf(): bool {
  return isset($_POST['csrf_token'])
      && isset($_SESSION['csrf_token'])
      && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
}

$errors = [];
$success = isset($_GET['saved']) ? "Quiz saved successfully!" : "";

// Handle Quiz Deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_quiz') {
  if (!verify_csrf()) {
    $errors[] = "Security check failed — please try again.";
  } else {
    $delete_id = (int)($_POST['quiz_id'] ?? 0);
    if ($delete_id > 0) {
      try {
        $conn->beginTransaction();
        // Delete related questions first
        $del_q = $conn->prepare("DELETE FROM quiz_questions WHERE quiz_id = :id");
        $del_q->execute([':id' => $delete_id]);

        // Delete the quiz record
        $del = $conn->prepare("DELETE FROM quizzes WHERE id = :id");
        $del->execute([':id' => $delete_id]);

        $conn->commit();
        $success = "Quiz deleted successfully.";
      } catch (PDOException $e) {
        $conn->rollBack();
        $errors[] = "Database Error: " . $e->getMessage();
      }
    }
  }
}

// Fetch quizzes, optionally scoped to a single course via ?course_code=
$filter_code = trim($_GET['course_code'] ?? '');

$sql = "SELECT q.id, q.course_code, q.title, q.passing_percent, q.created_at,
  (SELECT COUNT(*) FROM quiz_questions qq WHERE qq.quiz_id = q.id) AS question_count
  FROM quizzes q";
$params = [];
if ($filter_code !== '') {
    $sql .= " WHERE q.course_code = :code";
    $params[':code'] = $filter_code;
}
$sql .= " ORDER BY q.created_at DESC";

$stmt = $conn->prepare($sql);
$ok = $stmt->execute($params);
$quizzes = $ok ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin — Quizzes</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="styles.css">
  <style>
    :root {
      --jf-bg: #0f172a;
      --jf-card: #1e293b;
      --jf-border: rgba(255, 255, 255, 0.1);
      --jf-text: #f8fafc;
      --jf-muted: #94a3b8;
      --jf-primary: #0284c7;
      --jf-primary-hover: #0369a1;
    }

    body {
      background-color: var(--jf-bg);
      color: var(--jf-text);
      font-family: system-ui, -apple-system, sans-serif;
    }

    .navbar-jf {
      background-color: var(--jf-card);
      border-bottom: 1px solid var(--jf-border);
      padding: 12px 0;
    }

    .navbar-brand img {
      height: 40px;
      width: auto;
    }

    .card-jf {
      background-color: var(--jf-card);
      border: 1px solid var(--jf-border);
      border-radius: 12px;
      padding: 24px;
    }

    .table-jf {
      color: var(--jf-text);
    }

    .table-jf th {
      color: var(--jf-muted);
      font-weight: 500;
      border-bottom: 1px solid var(--jf-border);
      background: transparent;
    }

    .table-jf td {
      border-bottom: 1px solid var(--jf-border);
      background: transparent;
      color: var(--jf-text);
    }

    .btn-jf-primary {
      background-color: var(--jf-primary);
      color: #fff;
      border: none;
      font-weight: 500;
    }

    .btn-jf-primary:hover {
      background-color: var(--jf-primary-hover);
      color: #fff;
    }

    /* Mobile Responsive Card Adjustments */
    @media (max-width: 767.98px) {
      .container { padding: 0 12px; }
      .card-jf { padding: 14px; }

      .table-responsive-stack table,
      .table-responsive-stack thead,
      .table-responsive-stack tbody,
      .table-responsive-stack th,
      .table-responsive-stack td,
      .table-responsive-stack tr {
        display: block;
      }

      .table-responsive-stack thead {
        display: none;
      }

      .table-responsive-stack tr {
        background-color: rgba(255, 255, 255, 0.02);
        border: 1px solid var(--jf-border);
        border-radius: 8px;
        padding: 12px;
        margin-bottom: 12px;
      }

      .table-responsive-stack td {
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        padding: 8px 0;
        text-align: right;
      }

      .table-responsive-stack td:last-child {
        border-bottom: none;
        padding-bottom: 0;
        margin-top: 6px;
      }

      .table-responsive-stack td::before {
        content: attr(data-label);
        font-weight: 600;
        color: var(--jf-muted);
        text-align: left;
        padding-right: 12px;
        font-size: 0.85rem;
      }

      .table-responsive-stack td.text-end {
        justify-content: flex-end;
      }

      .action-buttons {
        width: 100%;
        display: flex;
        justify-content: flex-end;
        gap: 8px;
      }
    }
  </style>
</head>
<body>

  <!-- Navigation Bar -->
  <nav class="navbar navbar-jf mb-4">
    <div class="container d-flex justify-content-between align-items-center">
      <a class="navbar-brand" href="admin.php">
        <img src="images/logo.png" alt="Logo">
      </a>
      <a class="btn btn-outline-light btn-sm" href="admin.php">
        <i class="fa-solid fa-gauge me-1"></i> Dashboard
      </a>
    </div>
  </nav>

  <div class="container py-2">
    <!-- Header Section -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
      <div>
        <h1 class="h3 mb-0">Quizzes</h1>
        <?php if ($filter_code !== ''): ?>
          <div class="small text-white-50 mt-1">
            Filtered to course: <strong><?php echo htmlspecialchars($filter_code); ?></strong>
            &middot; <a href="admin_quiz.php" class="text-white-50">clear filter</a>
          </div>
        <?php endif; ?>
      </div>
      <a class="btn btn-jf-primary" href="edit_quiz.php<?php echo $filter_code !== '' ? '?course_code=' . urlencode($filter_code) : ''; ?>">
        <i class="fa-solid fa-plus me-1"></i> New Quiz
      </a>
    </div>

    <!-- Alert Notifications -->
    <?php if (!empty($errors)): ?>
      <div class="alert alert-danger border-0 text-white bg-danger bg-gradient mb-4">
        <ul class="mb-0">
          <?php foreach ($errors as $err): ?>
            <li><?php echo htmlspecialchars($err); ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <?php if (!empty($success)): ?>
      <div class="alert alert-success border-0 text-white bg-success bg-gradient mb-4">
        <i class="fa-solid fa-check-circle me-1"></i> <?php echo htmlspecialchars($success); ?>
      </div>
    <?php endif; ?>

    <!-- Quizzes Card -->
    <div class="card card-jf">
      <div class="table-responsive-stack">
        <table class="table table-jf align-middle mb-0">
          <thead>
            <tr>
              <th>Course Code</th>
              <th>Title</th>
              <th>Passing %</th>
              <th>Questions</th>
              <th class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($quizzes)): ?>
              <tr>
                <td colspan="5" class="text-center py-4 text-muted">No quizzes found.</td>
              </tr>
            <?php else: foreach ($quizzes as $q): ?>
              <tr>
                <td data-label="Course Code" class="fw-semibold"><?php echo htmlspecialchars($q['course_code']); ?></td>
                <td data-label="Title"><?php echo htmlspecialchars($q['title']); ?></td>
                <td data-label="Passing %"><span class="badge bg-secondary"><?php echo (int)$q['passing_percent']; ?>%</span></td>
                <td data-label="Questions"><?php echo (int)$q['question_count']; ?></td>
                <td data-label="Actions" class="text-end">
                  <div class="action-buttons">
                    <a class="btn btn-sm btn-outline-light" href="edit_quiz.php?quiz_id=<?php echo $q['id']; ?>">
                      <i class="fa-solid fa-pen-to-square me-1"></i> Edit
                    </a>
                    
                    <form method="POST" action="admin_quiz.php<?php echo $filter_code !== '' ? '?course_code=' . urlencode($filter_code) : ''; ?>" onsubmit="return confirm('Are you sure you want to delete this quiz? This action cannot be undone.');">
                      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($CSRF_TOKEN); ?>">
                      <input type="hidden" name="action" value="delete_quiz">
                      <input type="hidden" name="quiz_id" value="<?php echo $q['id']; ?>">
                      <button type="submit" class="btn btn-sm btn-outline-danger">
                        <i class="fa-solid fa-trash me-1"></i> Delete
                      </button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</body>
</html>