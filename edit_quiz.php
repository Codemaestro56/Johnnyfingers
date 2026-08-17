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

$quiz_id = isset($_GET['quiz_id']) ? (int)$_GET['quiz_id'] : (isset($_POST['quiz_id']) ? (int)$_POST['quiz_id'] : 0);
$errors  = [];
$saved   = isset($_GET['saved']);

// ---------------------------------------------------------------------
// Save handler
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $errors[] = "Security check failed — please refresh the page and try again.";
    } else {
        $course_code     = trim($_POST['course_code'] ?? '');
        $title           = trim($_POST['title'] ?? '');
        $passing_percent = (int)($_POST['passing_percent'] ?? 70);
        $questions_in    = $_POST['questions'] ?? [];

        if ($course_code === '') $errors[] = "Please choose a course.";
        if ($title === '') $errors[] = "Please enter a quiz title.";
        if ($passing_percent < 1 || $passing_percent > 100) $errors[] = "Passing percent must be between 1 and 100.";

        if ($course_code !== '') {
            $chk = $conn->prepare("SELECT 1 FROM courses WHERE course_code = :code");
            $chk->execute([':code' => $course_code]);
            if (!$chk->fetchColumn()) {
                $errors[] = "Selected course does not exist.";
            }
        }

        $clean_questions = [];
        foreach ($questions_in as $q) {
            $qtext = trim($q['text'] ?? '');
            $opts  = array_values(array_filter(array_map('trim', $q['options'] ?? []), fn($o) => $o !== ''));
            $ans   = isset($q['answer']) ? (int)$q['answer'] : -1;

            if ($qtext === '' || count($opts) < 2) {
                continue; 
            }
            if ($ans < 0 || $ans >= count($opts)) {
                $ans = 0;
            }
            $clean_questions[] = ['text' => $qtext, 'options' => $opts, 'answer' => $ans];
        }
        if (empty($clean_questions)) {
            $errors[] = "Add at least one complete question (question text + 2 or more options).";
        }

        if (empty($errors)) {
            try {
                $conn->beginTransaction();

                if ($quiz_id > 0) {
                    $stmt = $conn->prepare("UPDATE quizzes SET course_code = :code, title = :title, passing_percent = :pp WHERE id = :id");
                    $stmt->execute([':code' => $course_code, ':title' => $title, ':pp' => $passing_percent, ':id' => $quiz_id]);

                    $del = $conn->prepare("DELETE FROM quiz_questions WHERE quiz_id = :id");
                    $del->execute([':id' => $quiz_id]);
                } else {
                    $stmt = $conn->prepare("INSERT INTO quizzes (course_code, title, passing_percent) VALUES (:code, :title, :pp)");
                    $stmt->execute([':code' => $course_code, ':title' => $title, ':pp' => $passing_percent]);
                    $quiz_id = (int)$conn->lastInsertId();
                }

                $ins = $conn->prepare("INSERT INTO quiz_questions (quiz_id, question, options_json, answer_index) VALUES (:qid, :q, :opts, :ans)");
                foreach ($clean_questions as $q) {
                    $ins->execute([
                        ':qid'  => $quiz_id,
                        ':q'    => $q['text'],
                        ':opts' => json_encode($q['options']),
                        ':ans'  => $q['answer'],
                    ]);
                }

                $conn->commit();
                header('Location: admin_quiz.php?saved=1');
                exit();
            } catch (PDOException $e) {
                $conn->rollBack();
                $errors[] = "Database Error: " . $e->getMessage();
            }
        }
    }
}

// ---------------------------------------------------------------------
// Load data for the form
// ---------------------------------------------------------------------
$courses_stmt = $conn->query("SELECT course_code, title FROM courses ORDER BY title ASC");
$all_courses  = $courses_stmt->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form_course_code = $_POST['course_code'] ?? '';
    $form_title       = $_POST['title'] ?? '';
    $form_passing     = (int)($_POST['passing_percent'] ?? 70);
    $form_questions   = [];
    foreach (($_POST['questions'] ?? []) as $q) {
        $form_questions[] = [
            'text'    => $q['text'] ?? '',
            'options' => array_values($q['options'] ?? []),
            'answer'  => (int)($q['answer'] ?? 0),
        ];
    }
} elseif ($quiz_id > 0) {
    $qstmt = $conn->prepare("SELECT * FROM quizzes WHERE id = :id");
    $qstmt->execute([':id' => $quiz_id]);
    $quiz = $qstmt->fetch(PDO::FETCH_ASSOC);
    if (!$quiz) {
        die("Quiz not found.");
    }
    $form_course_code = $quiz['course_code'];
    $form_title       = $quiz['title'];
    $form_passing     = (int)$quiz['passing_percent'];

    $qq = $conn->prepare("SELECT question, options_json, answer_index FROM quiz_questions WHERE quiz_id = :id ORDER BY id ASC");
    $qq->execute([':id' => $quiz_id]);
    $form_questions = [];
    foreach ($qq->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $form_questions[] = [
            'text'    => $row['question'],
            'options' => json_decode($row['options_json'], true) ?: [],
            'answer'  => (int)$row['answer_index'],
        ];
    }
} else {
    $form_course_code = trim($_GET['course_code'] ?? '') ?: ($all_courses[0]['course_code'] ?? '');
    $form_title       = '';
    $form_passing     = 70;
    $form_questions   = [
        ['text' => '', 'options' => ['', '', '', ''], 'answer' => 0],
    ];
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo $quiz_id ? 'Edit Quiz' : 'New Quiz'; ?> — Admin</title>
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
      margin-bottom: 24px;
    }

    .question-block {
      background-color: rgba(255, 255, 255, 0.02);
      border: 1px solid var(--jf-border);
      border-radius: 12px;
      padding: 20px;
      margin-bottom: 20px;
      position: relative;
    }

    .option-row {
      display: flex;
      align-items: center;
      gap: 12px;
      margin-bottom: 10px;
    }

    .option-row input[type="text"] {
      flex: 1;
    }

    .form-control, .form-select {
      background-color: rgba(0, 0, 0, 0.2);
      border: 1px solid var(--jf-border);
      color: var(--jf-text);
    }

    .form-control:focus, .form-select:focus {
      background-color: rgba(0, 0, 0, 0.3);
      border-color: var(--jf-primary);
      color: var(--jf-text);
      box-shadow: 0 0 0 0.25rem rgba(2, 132, 199, 0.25);
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

    .form-label {
      color: var(--jf-muted);
      font-size: 0.9rem;
    }

    @media (max-width: 600px) {
      .container { padding: 0 16px; }
      .card-jf { padding: 16px; }
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
      <a class="btn btn-outline-light btn-sm" href="admin_quiz.php">
        <i class="fa-solid fa-arrow-left me-1"></i> Back to Quizzes
      </a>
    </div>
  </nav>

  <div class="container py-2">
    <div class="d-flex justify-content-between align-items-center mb-4">
      <h1 class="h3 mb-0"><?php echo $quiz_id ? 'Edit Quiz' : 'New Quiz'; ?></h1>
    </div>

    <?php if (!empty($errors)): ?>
      <div class="alert alert-danger border-0 text-white bg-danger bg-gradient">
        <ul class="mb-0">
          <?php foreach ($errors as $err): ?>
            <li><?php echo htmlspecialchars($err); ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <form method="POST" action="edit_quiz.php<?php echo $quiz_id ? '?quiz_id=' . (int)$quiz_id : ''; ?>" id="quizForm">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($CSRF_TOKEN); ?>">
      <?php if ($quiz_id): ?>
        <input type="hidden" name="quiz_id" value="<?php echo (int)$quiz_id; ?>">
      <?php endif; ?>

      <!-- Quiz Details Card -->
      <div class="card card-jf">
        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label">Course</label>
            <select name="course_code" class="form-select" required>
              <?php foreach ($all_courses as $c): ?>
                <option value="<?php echo htmlspecialchars($c['course_code']); ?>" <?php echo $c['course_code'] === $form_course_code ? 'selected' : ''; ?>>
                  <?php echo htmlspecialchars($c['title']); ?> (<?php echo htmlspecialchars($c['course_code']); ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-5">
            <label class="form-label">Quiz Title</label>
            <input type="text" name="title" class="form-control" value="<?php echo htmlspecialchars($form_title); ?>" required>
          </div>
          <div class="col-md-3">
            <label class="form-label">Passing %</label>
            <input type="number" name="passing_percent" class="form-control" min="1" max="100" value="<?php echo (int)$form_passing; ?>" required>
          </div>
        </div>
      </div>

      <!-- Questions List -->
      <h2 class="h5 mb-3">Questions</h2>
      <div id="questionsContainer">
        <?php foreach ($form_questions as $qi => $q): ?>
          <div class="question-block" data-index="<?php echo $qi; ?>">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <label class="form-label fw-bold text-white mb-0 q-number">Question <?php echo $qi + 1; ?></label>
              <button type="button" class="btn btn-sm btn-outline-danger remove-question">
                <i class="fa-solid fa-trash me-1"></i> Delete Question
              </button>
            </div>
            
            <input type="text" name="questions[<?php echo $qi; ?>][text]" class="form-control mb-3" placeholder="Enter question text..." value="<?php echo htmlspecialchars($q['text']); ?>" required>

            <label class="form-label d-block mb-2">Options (Select the correct answer)</label>
            <div class="options-list">
              <?php foreach ($q['options'] as $oi => $opt): ?>
                <div class="option-row">
                  <input type="radio" name="questions[<?php echo $qi; ?>][answer]" value="<?php echo $oi; ?>" <?php echo ((int)$q['answer'] === $oi) ? 'checked' : ''; ?> required>
                  <input type="text" name="questions[<?php echo $qi; ?>][options][]" class="form-control form-control-sm" placeholder="Option <?php echo $oi + 1; ?>" value="<?php echo htmlspecialchars($opt); ?>">
                </div>
              <?php endforeach; ?>
            </div>
            <button type="button" class="btn btn-sm btn-outline-light add-option mt-2">
              <i class="fa-solid fa-plus me-1"></i> Add Option
            </button>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="d-flex justify-content-between align-items-center mt-4 mb-5">
        <button type="button" id="addQuestion" class="btn btn-outline-light">
          <i class="fa-solid fa-plus me-1"></i> Add Question
        </button>
        <button type="submit" class="btn btn-jf-primary px-4 py-2">
          <i class="fa-solid fa-floppy-disk me-1"></i> Save Quiz
        </button>
      </div>
    </form>
  </div>

  <!-- Hidden template for adding new questions -->
  <template id="questionTemplate">
    <div class="question-block" data-index="__INDEX__">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <label class="form-label fw-bold text-white mb-0 q-number">Question __NUM__</label>
        <button type="button" class="btn btn-sm btn-outline-danger remove-question">
          <i class="fa-solid fa-trash me-1"></i> Delete Question
        </button>
      </div>
      <input type="text" name="questions[__INDEX__][text]" class="form-control mb-3" placeholder="Enter question text..." required>
      <label class="form-label d-block mb-2">Options (Select the correct answer)</label>
      <div class="options-list">
        <div class="option-row">
          <input type="radio" name="questions[__INDEX__][answer]" value="0" checked required>
          <input type="text" name="questions[__INDEX__][options][]" class="form-control form-control-sm" placeholder="Option 1">
        </div>
        <div class="option-row">
          <input type="radio" name="questions[__INDEX__][answer]" value="1" required>
          <input type="text" name="questions[__INDEX__][options][]" class="form-control form-control-sm" placeholder="Option 2">
        </div>
      </div>
      <button type="button" class="btn btn-sm btn-outline-light add-option mt-2">
        <i class="fa-solid fa-plus me-1"></i> Add Option
      </button>
    </div>
  </template>

  <script>
    let questionCounter = <?php echo count($form_questions); ?>;

    function renumberQuestions() {
      document.querySelectorAll('.question-block').forEach((block, i) => {
        block.querySelector('.q-number').textContent = 'Question ' + (i + 1);
      });
    }

    document.getElementById('addQuestion').addEventListener('click', () => {
      const tpl = document.getElementById('questionTemplate').innerHTML
        .replaceAll('__INDEX__', questionCounter)
        .replaceAll('__NUM__', questionCounter + 1);
      const wrapper = document.createElement('div');
      wrapper.innerHTML = tpl.trim();
      document.getElementById('questionsContainer').appendChild(wrapper.firstElementChild);
      questionCounter++;
      renumberQuestions();
    });

    document.getElementById('questionsContainer').addEventListener('click', (e) => {
      // Remove question handler
      if (e.target.closest('.remove-question')) {
        const blocks = document.querySelectorAll('.question-block');
        if (blocks.length <= 1) {
          alert('A quiz must have at least one question.');
          return;
        }
        e.target.closest('.question-block').remove();
        renumberQuestions();
      }

      // Add option handler
      if (e.target.closest('.add-option')) {
        const block = e.target.closest('.question-block');
        const list = block.querySelector('.options-list');
        const index = block.dataset.index;
        const optionCount = list.querySelectorAll('.option-row').length;
        const row = document.createElement('div');
        row.className = 'option-row';
        row.innerHTML = `
          <input type="radio" name="questions[${index}][answer]" value="${optionCount}">
          <input type="text" name="questions[${index}][options][]" class="form-control form-control-sm" placeholder="Option ${optionCount + 1}">
        `;
        list.appendChild(row);
      }
    });
  </script>
</body>
</html>