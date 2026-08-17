<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once 'db.php';
require_once 'courses_config.php';

// 1. Auth Guard
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id     = $_SESSION['user_id'];
$full_name   = $_SESSION['full_name'] ?? $_SESSION['user_name'] ?? 'Student';
$course_code = $_GET['course'] ?? 'wash_repair';

// Validate course existence against database
try {
    $course_stmt = $conn->prepare(
        "SELECT id, course_code, title, price, is_active FROM courses WHERE course_code = :code LIMIT 1"
    );
    $course_stmt->execute([':code' => $course_code]);
    $db_course = $course_stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}

if (!$db_course) {
    die("Course track not found.");
}

$course = [
    'db_id'   => (int)$db_course['id'],
    'title'   => $db_course['title'],
    'lessons' => 10,
];

if (isset($COURSES[$course_code])) {
    $extra = $COURSES[$course_code];
    if (isset($extra['lessons'])) $course['lessons'] = (int)$extra['lessons'];
    if (isset($extra['videos']))  $course['videos']  = $extra['videos'];
    if (isset($extra['image']))   $course['image']   = $extra['image'];
}

// 2. Access Control Guard
try {
    $stmt = $conn->prepare(
        "SELECT id FROM enrollments 
        WHERE user_id = :user_id AND course_id = :course_id AND payment_status = 'completed' LIMIT 1"
    );
    $stmt->execute([
        ':user_id'   => $user_id,
        ':course_id' => $course['db_id']
    ]);

    $enrollment = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$enrollment) {
        header("Location: checkout.php?course=" . urlencode($course_code));
        exit();
    }
    $enroll_id = (int)$enrollment['id'];
} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}

// Build lesson list — pulled from the real `lessons` table your admin's
// Lesson Manager writes to (previously this generated fake placeholder
// lessons instead of showing what the admin actually entered).
// Grouped by course_sections when available; falls back to one implicit
// "Course Content" section for courses that haven't been split into
// modules yet.
$defaultThumb = $course['image'] ?? 'https://images.unsplash.com/photo-1581092160607-ee22621dd758?auto=format&fit=crop&w=600&q=80';

$SECTIONS = [];
try {
    $lstmt = $conn->prepare("
        SELECT l.id, l.title, l.duration, l.video_url,
               cs.id AS section_id, cs.title AS section_title
        FROM lessons l
        LEFT JOIN course_sections cs ON cs.id = l.section_id
        WHERE l.course_id = :code
        ORDER BY COALESCE(cs.sort_order, 0) ASC, COALESCE(l.sort_order, l.id) ASC
    ");
    $lstmt->execute([':code' => $course_code]);
    $lesson_rows = $lstmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // course_sections/section_id may not exist yet if the migration hasn't
    // run — fall back to a flat, unsectioned read of the lessons table.
    $lstmt = $conn->prepare("SELECT id, title, duration, video_url FROM lessons WHERE course_id = :code ORDER BY id ASC");
    $lstmt->execute([':code' => $course_code]);
    $lesson_rows = array_map(function ($r) {
        $r['section_id'] = null; $r['section_title'] = null; return $r;
    }, $lstmt->fetchAll(PDO::FETCH_ASSOC));
}

foreach ($lesson_rows as $r) {
    $sec_key   = $r['section_id'] ?? 'default';
    $sec_title = $r['section_title'] ?? 'Course Content';
    if (!isset($SECTIONS[$sec_key])) {
        $SECTIONS[$sec_key] = ['title' => $sec_title, 'lessons' => []];
    }
    $SECTIONS[$sec_key]['lessons'][] = [
        'id'          => (int)$r['id'],
        'title'       => $r['title'],
        'duration'    => $r['duration'],
        'video_url'   => $r['video_url'],
        'thumb'       => $defaultThumb,
        'description' => "Part of {$course['title']}. Practical demonstration and walkthrough.",
    ];
}

// Backward-compat: if the admin hasn't added real lessons for this course
// yet, fall back to the old synthetic placeholders so the player never
// renders completely empty.
if (empty($SECTIONS)) {
    $lessonCount = isset($course['lessons']) ? (int)$course['lessons'] : 0;
    $placeholder_lessons = [];
    for ($i = 1; $i <= $lessonCount; $i++) {
        $video_url = $course['videos'][$i-1] ?? 'https://www.youtube.com/embed/ScMzIvxBSi4';
        $placeholder_lessons[] = [
            'id'          => $i,
            'title'       => "Module $i: " . ($course['title']) . " — Part $i",
            'duration'    => '12:00',
            'video_url'   => $video_url,
            'thumb'       => $defaultThumb,
            'description' => "Lesson $i of {$course['title']}. Practical demonstration and walkthrough.",
        ];
    }
    $SECTIONS = ['default' => ['title' => 'Course Content', 'lessons' => $placeholder_lessons]];
}

// Flatten back into $LESSONS in section order — every existing piece of
// JS below (switchLesson, markComplete's "enable next", quiz gating via
// count($LESSONS)) keeps working unchanged against this flat list.
$LESSONS = [];
foreach ($SECTIONS as $sec) {
    foreach ($sec['lessons'] as $lesson) {
        $LESSONS[] = $lesson;
    }
}


// Fetch completed lessons
$completed_lessons = [];
try {
    $stmt = $conn->prepare("SELECT lesson_id FROM lesson_progress WHERE user_id = :user_id AND course_id = :course_id AND completed = 1");
    $stmt->execute([
        ':user_id'   => $user_id,
        ':course_id' => $course['db_id']
    ]);
    $completed_lessons = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
} catch (PDOException $e) {
    $completed_lessons = [];
}
$completed_lessons = array_map('intval', $completed_lessons);

// Check latest quiz attempt
$quiz_passed = false;
$quiz_score  = null;
try {
    $q = $conn->prepare("SELECT passed, score FROM quiz_attempts WHERE user_id = :uid AND course_id = :cid ORDER BY created_at DESC LIMIT 1");
    $q->execute([':uid' => $user_id, ':cid' => $course['db_id']]);
    $qa = $q->fetch(PDO::FETCH_ASSOC);
    if ($qa) {
        $quiz_passed = (bool)$qa['passed'];
        $quiz_score  = intval($qa['score']);
    }
} catch (Exception $e) {
    // Table may not exist yet
}

// Load quiz config
$quiz_config = null;
try {
    $qstmt = $conn->prepare("SELECT id, title, passing_percent FROM quizzes WHERE course_code = :code ORDER BY created_at DESC LIMIT 1");
    $qstmt->execute([':code' => $course_code]);
    $quiz_row = $qstmt->fetch(PDO::FETCH_ASSOC);

    if ($quiz_row) {
        $qq = $conn->prepare("SELECT question, options_json FROM quiz_questions WHERE quiz_id = :qid ORDER BY id ASC");
        $qq->execute([':qid' => $quiz_row['id']]);

        $questions = [];
        foreach ($qq->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $questions[] = [
                'q'       => $row['question'],
                'options' => json_decode($row['options_json'], true) ?: [],
            ];
        }

        if (!empty($questions)) {
            $quiz_config = [
                'id'              => (int)$quiz_row['id'],
                'title'           => $quiz_row['title'],
                'passing_percent' => (int)$quiz_row['passing_percent'],
                'questions'       => $questions,
            ];
        }
    }
} catch (PDOException $e) {
    $quiz_config = null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo htmlspecialchars($course['title']); ?> | Student Hub</title>

  <!-- Google Fonts & Icons -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

  <!-- Bootstrap CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="styles.css">

  <style>
    :root {
  /* Dark-Tech Theme Tokens */
  --bg-dark: #0b0f19;
  --bg-light: #0b0f19; /* Standardized to dark background */
  --card-bg: #151c2c;
  --card-border: #1e293b;
  --border: #1e293b;
  --border-hover: #334155;
  
  /* Primary & Accent Glows */
  --primary: #3b82f6;
  --primary-soft: rgba(59, 130, 246, 0.12);
  --primary-hover: #2563eb;
  
  /* Status Colors */
  --accent-green: #10b981;
  --accent-green-soft: rgba(16, 185, 129, 0.12);
  --accent-warning: #f59e0b;
  --accent-warning-soft: rgba(245, 158, 11, 0.12);
  
  /* Typography */
  --text-main: #f8fafc;
  --text-muted: #94a3b8;
  
  /* Elevation Shadows */
  --shadow-sm: 0 1px 3px 0 rgba(0, 0, 0, 0.3);
  --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.4);
  --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.5);
  
  /* Radii */
  --radius-sm: 8px;
  --radius-md: 12px;
  --radius-lg: 16px;
}
    

    * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Plus Jakarta Sans', sans-serif; }

    body {
     background-color: var(--bg-dark);
      color: var(--text-main);
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      -webkit-font-smoothing: antialiased;
    }

    /* Navbar */
    .navbar {
      background: rgba(10, 41, 63, 0.95);
      backdrop-filter: blur(12px);
      border-bottom: 1px solid var(--border);
      padding: 14px 24px;
      position: sticky;
      top: 0;
      z-index: 100;
      box-shadow: var(--shadow-sm);
    }

    .brand-logo {
      font-size: 1.15rem;
      font-weight: 800;
      color: var(--text-main);
      text-decoration: none;
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .back-btn {
      color: var(--text-muted);
      text-decoration: none;
      font-size: 0.875rem;
      font-weight: 600;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      transition: color 0.2s;
    }
    .back-btn:hover { color: var(--primary); }

    /* Progress Header Section */
    .progress-container {
      max-width: 1350px;
      width: 100%;
      margin: 20px auto 0;
      padding: 0 20px;
    }

    .progress-card {
      background: var(--card-bg);
      border: 1px solid var(--border);
      border-radius: var(--radius-lg);
      padding: 16px 20px;
      box-shadow: var(--shadow-sm);
    }

    .progress-info {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 12px;
      margin-bottom: 8px;
      color: var(--text-muted);
      font-weight: 700;
      font-size: 0.875rem;
    }

    .progress-bar-bg {
      background: #f1f5f9;
      border-radius: 999px;
      height: 10px;
      overflow: hidden;
    }

    .progress-bar-fill {
      height: 100%;
      background: linear-gradient(90deg, var(--primary), var(--accent-green));
      border-radius: 999px;
      transition: width 0.4s ease;
    }

    /* Main Grid Layout */
    .player-container {
      display: grid;
      grid-template-columns: 1fr 380px;
      gap: 24px;
      max-width: 1350px;
      width: 100%;
      margin: 20px auto 40px;
      padding: 0 20px;
      flex: 1;
    }

    /* Video Player Box */
    .video-wrapper {
      position: relative;
      padding-bottom: 56.25%; /* 16:9 Aspect Ratio */
      height: 0;
      background: #000;
      border-radius: var(--radius-lg);
      overflow: hidden;
      border: 1px solid var(--border);
      box-shadow: var(--shadow-md);
    }

    .video-wrapper iframe {
      position: absolute;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      border: 0;
    }

    .video-details {
      margin-top: 20px;
      background: var(--card-bg);
      border: 1px solid var(--border);
      border-radius: var(--radius-lg);
      padding: 24px;
      box-shadow: var(--shadow-sm);
    }

    .course-badge {
      display: inline-block;
      background: var(--primary-soft);
      color: var(--primary);
      border: 1px solid rgba(37, 99, 235, 0.15);
      font-size: 0.75rem;
      font-weight: 800;
      padding: 4px 12px;
      border-radius: 20px;
      margin-bottom: 12px;
      text-transform: uppercase;
      letter-spacing: 0.025em;
    }

    .lesson-title {
      font-size: 1.35rem;
      font-weight: 800;
      color: var(--text-main);
      margin-bottom: 12px;
      line-height: 1.3;
    }

    .lesson-description {
      color: var(--text-muted);
      line-height: 1.6;
      font-size: 0.95rem;
      margin-bottom: 24px;
    }

    /* Action & Status Row */
    .action-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
      padding-bottom: 24px;
      margin-bottom: 24px;
      border-bottom: 1px solid var(--border);
      flex-wrap: wrap;
    }

    .btn-complete {
      padding: 10px 20px;
      border-radius: 10px;
      background: var(--accent-green);
      border: none;
      color: #ffffff;
      font-weight: 700;
      font-size: 0.9rem;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      transition: background 0.2s, transform 0.1s;
      box-shadow: var(--shadow-sm);
      text-decoration: none;
    }

    .btn-complete:hover {
      background: #047857;
      color: #ffffff;
    }

    .btn-complete:disabled {
      background: #e2e8f0;
      color: #94a3b8;
      cursor: not-allowed;
      box-shadow: none;
    }

    .status-badge {
      font-size: 0.85rem;
      font-weight: 700;
      padding: 6px 14px;
      border-radius: 20px;
      background: #15d7b4;
      color: var(--text-muted);
      display: inline-flex;
      align-items: center;
      gap: 6px;
    }

    .status-badge.completed {
      background: var(--accent-green-soft);
      color: var(--accent-green);
    }

    /* Completion Banner Card */
    .completion-banner {
      margin: 20px 0 24px;
      padding: 16px 20px;
      border-radius: var(--radius-md);
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
      flex-wrap: wrap;
    }

    .completion-banner.passed {
      background: var(--accent-green-soft);
      border: 1px solid #d1fae5;
      color: #065f46;
    }

    .completion-banner.pending {
      background: var(--accent-warning-soft);
      border: 1px solid #ffedd5;
      color: #7c2d12;
    }

    /* Resources Grid */
    .resources-title {
      font-size: 1rem;
      font-weight: 700;
      margin-bottom: 14px;
      display: flex;
      align-items: center;
      gap: 8px;
      color: var(--text-main);
    }

    .resources-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 12px;
    }

    .resource-card {
      background: #0e0f10;
      border: 1px solid var(--border);
      padding: 12px 14px;
      border-radius: var(--radius-md);
      display: flex;
      align-items: center;
      gap: 12px;
      text-decoration: none;
      color: var(--text-main);
      transition: all 0.2s ease;
    }

    .resource-card:hover {
      border-color: var(--primary);
      background: #1a1717;
      transform: translateY(-2px);
      box-shadow: var(--shadow-sm);
    }

    .resource-icon {
      font-size: 1.25rem;
      color: var(--primary);
    }

    /* Sidebar Playlist */
    .playlist-card {
      background: var(--card-bg);
      border: 1px solid var(--border);
      border-radius: var(--radius-lg);
      padding: 20px;
      height: fit-content;
      position: sticky;
      top: 90px;
      box-shadow: var(--shadow-sm);
    }

    .playlist-header {
      font-size: 1.05rem;
      font-weight: 800;
      margin-bottom: 16px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding-bottom: 12px;
      border-bottom: 1px solid var(--border);
    }

    .playlist-items {
      display: flex;
      flex-direction: column;
      gap: 10px;
    }

    .section-block { border: 1px solid var(--border); border-radius: var(--radius-md); overflow: hidden; }
    .section-header {
      display: flex; justify-content: space-between; align-items: center; width: 100%;
      padding: 12px 14px; background: #050505; border: none; cursor: pointer;
      font-weight: 700; font-size: 0.9rem; color: var(--text-main);
    }
    .section-header i { transition: transform 0.2s ease; color: var(--text-muted); }
    .section-block.open .section-header i { transform: rotate(180deg); }
    .section-body { max-height: 0; overflow: hidden; transition: max-height 0.25s ease; padding: 0 8px; }
    .section-block.open .section-body { max-height: 2000px; padding: 8px; }
    .section-body .playlist-item:last-child { margin-bottom: 0; }

    .playlist-item {
      display: flex;
      gap: 12px;
      padding: 10px;
      border-radius: var(--radius-md);
      background: #1e1f21;
      border: 1px solid var(--border);
      cursor: pointer;
      transition: all 0.2s ease;
    }

    .playlist-item:hover {
      border-color: var(--border-hover);
      background: #4f4c4c;
      box-shadow: var(--shadow-sm);
    }

    .playlist-item.active {
      border-color: var(--primary);
      background: var(--primary-soft);
    }

    .playlist-item.disabled {
      opacity: 0.6;
      cursor: not-allowed;
      background: #f1f5f9;
      border-color: transparent;
    }

    .item-thumb-wrapper {
      position: relative;
      width: 80px;
      height: 54px;
      border-radius: var(--radius-sm);
      overflow: hidden;
      flex-shrink: 0;
      background: #000;
    }

    .item-thumb {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }

    .item-details {
      display: flex;
      flex-direction: column;
      justify-content: center;
      flex: 1;
    }

    .item-title {
      font-size: 0.825rem;
      font-weight: 700;
      line-height: 1.35;
      margin-bottom: 4px;
      color: var(--text-main);
      display: -webkit-box;
      -webkit-line-clamp: 2;
      -webkit-box-orient: vertical;
      overflow: hidden;
    }

    .playlist-item.active .item-title {
      color: var(--primary);
    }

    .item-duration {
      font-size: 0.725rem;
      color: var(--text-muted);
      display: flex;
      align-items: center;
      gap: 4px;
    }

    /* Modal Backdrop & Dialog */
    .quiz-modal-backdrop {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(15, 23, 42, 0.6);
      backdrop-filter: blur(4px);
      align-items: center;
      justify-content: center;
      z-index: 2000;
      padding: 16px;
    }

    .quiz-modal-content {
      background: #ffffff;
      width: 100%;
      max-width: 720px;
      border-radius: var(--radius-lg);
      padding: 24px;
      max-height: 85vh;
      display: flex;
      flex-direction: column;
      box-shadow: var(--shadow-lg);
      border: 1px solid var(--border);
    }

    .quiz-modal-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 16px;
      padding-bottom: 12px;
      border-bottom: 1px solid var(--border);
    }

    .quiz-modal-body {
      overflow-y: auto;
      padding-right: 6px;
      flex: 1;
    }

    .quiz-question-card {
      background: #f8fafc;
      border: 1px solid var(--border);
      border-radius: var(--radius-md);
      padding: 16px;
      margin-bottom: 16px;
    }

    .quiz-option-label {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 8px 12px;
      background: #ffffff;
      border: 1px solid var(--border);
      border-radius: var(--radius-sm);
      margin-top: 8px;
      cursor: pointer;
      transition: all 0.15s;
    }

    .quiz-option-label:hover {
      border-color: var(--primary);
      background: var(--primary-soft);
    }

    .quiz-modal-footer {
      display: flex;
      gap: 12px;
      margin-top: 16px;
      padding-top: 12px;
      border-top: 1px solid var(--border);
      justify-content: flex-end;
    }

    .btn-secondary-custom {
      padding: 8px 16px;
      border-radius: 8px;
      background: #f1f5f9;
      color: var(--text-main);
      border: 1px solid var(--border);
      font-weight: 600;
      font-size: 0.875rem;
      cursor: pointer;
    }

    .btn-secondary-custom:hover {
      background: #e2e8f0;
    }

    /* Responsive Design */
    @media (max-width: 1024px) {
      .player-container {
        grid-template-columns: 1fr;
      }

      .playlist-card {
        position: static;
      }
    }

    @media (max-width: 640px) {
      .navbar {
        padding: 12px 16px;
      }

      .progress-container, .player-container {
        padding: 0 12px;
      }

      .video-details {
        padding: 18px;
      }

      .lesson-title {
        font-size: 1.15rem;
      }

      .resources-grid {
        grid-template-columns: 1fr;
      }

      .action-row {
        flex-direction: column;
        align-items: stretch;
      }

      .btn-complete, .status-badge {
        justify-content: center;
        width: 100%;
      }

      .completion-banner {
        flex-direction: column;
        align-items: stretch;
      }
    }
  </style>
</head>
<body>

  <!-- Top Navigation -->
  <nav class="navbar">
    <div class="container-fluid d-flex justify-content-between align-items-center p-0">
      <a href="Home.html" class="brand-logo">
        <img src="images/logo.png" alt="Logo" style="height: 40px; width: auto; object-fit: contain;">
      </a>
      <a href="dashboard.php" class="back-btn">
        <i class="fa-solid fa-arrow-left"></i>
        <span>Back to Dashboard</span>
      </a>
    </div>
  </nav>

  <?php
    $total_lessons   = count($LESSONS);
    $completed_count = count($completed_lessons);
    $pct             = $total_lessons > 0 ? round(($completed_count / $total_lessons) * 100) : 0;
  ?>

  <!-- Course Progress Bar -->
  <div class="progress-container">
    <div class="progress-card">
      <div class="progress-info">
        <div>Course Progress</div>
        <div id="courseProgressText"><?php echo $completed_count; ?> / <?php echo $total_lessons; ?> Lessons — <?php echo $pct; ?>%</div>
      </div>
      <div class="progress-bar-bg">
        <div id="courseProgressFill" class="progress-bar-fill" style="width: <?php echo $pct; ?>%;"></div>
      </div>
    </div>
  </div>

  <!-- Player Layout -->
  <div class="player-container">
    
    <!-- Main Content -->
    <div>
      <div class="video-wrapper">
        <iframe id="mainVideoPlayer" src="<?php echo $LESSONS[0]['video_url']; ?>" allowfullscreen></iframe>
      </div>

      <div class="video-details">
        <span class="course-badge"><?php echo htmlspecialchars($course['title']); ?></span>
        <h1 class="lesson-title" id="lessonTitle"><?php echo htmlspecialchars($LESSONS[0]['title']); ?></h1>
        <p class="lesson-description" id="lessonDesc"><?php echo htmlspecialchars($LESSONS[0]['description']); ?></p>

        <!-- Mark Complete & Status Row -->
        <div class="action-row">
          <button id="markCompleteBtn" class="btn-complete" onclick="markComplete()">
            <i class="fa-solid fa-circle-check"></i> Mark Lesson Complete
          </button>
          <div id="completeStatus" class="status-badge">
            <i class="fa-regular fa-clock"></i> Not completed
          </div>
        </div>

        <!-- Quiz / Certificate Completion Banner -->
        <?php if ($pct >= 100): ?>
          <?php if ($quiz_passed): ?>
            <div class="completion-banner passed">
              <div>
                <strong><i class="fa-solid fa-award me-1"></i> Course Passed!</strong> 
                You passed the course quiz (<?php echo htmlspecialchars($quiz_score ?? 'N/A'); ?>%). You can now download your official certificate.
              </div>
              <a class="btn-complete" href="certificate_pdf.php?enroll_id=<?php echo $enroll_id; ?>" style="background:var(--primary);">
                <i class="fa-solid fa-download"></i> Download Certificate
              </a>
            </div>
          <?php else: ?>
            <div class="completion-banner pending">
              <div>
                <strong><i class="fa-solid fa-circle-info me-1"></i> Course Complete</strong> 
                Pass the final quiz to unlock and download your certificate.
              </div>
              <?php if ($quiz_config): ?>
                <button id="takeQuizBtn" class="btn-complete" style="background:var(--accent-warning);" onclick="openQuiz()">
                  <i class="fa-solid fa-pen-to-square"></i> Take Course Quiz
                </button>
              <?php else: ?>
                <div style="color:var(--text-muted); font-weight:600;">No quiz configured for this course track yet.</div>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        <?php endif; ?>

        <!-- Module Resources -->
        <h3 class="resources-title">
          <i class="fa-solid fa-folder-open" style="color: var(--primary);"></i> Module Handouts & Schematics
        </h3>
        <div class="resources-grid">
          <a href="#" class="resource-card" onclick="alert('Downloading Wiring Diagram (PDF)...'); return false;">
            <i class="fa-solid fa-file-pdf resource-icon"></i>
            <div>
              <div style="font-weight: 700; font-size: 0.85rem;">Wiring Schematic.pdf</div>
              <div style="font-size: 0.75rem; color: var(--text-muted);">PDF Document • 2.4 MB</div>
            </div>
          </a>
          <a href="#" class="resource-card" onclick="alert('Downloading Diagnostic Cheat-Sheet...'); return false;">
            <i class="fa-solid fa-file-lines resource-icon"></i>
            <div>
              <div style="font-weight: 700; font-size: 0.85rem;">Error Codes Matrix</div>
              <div style="font-size: 0.75rem; color: var(--text-muted);">Cheatsheet • 1.1 MB</div>
            </div>
          </a>
        </div>
      </div>
    </div>

    <!-- Right Sidebar Playlist -->
    <div class="playlist-card">
      <div class="playlist-header">
        <span>Course Modules</span>
        <span style="font-size: 0.8rem; color: var(--text-muted); font-weight: 600;"><?php echo count($LESSONS); ?> Lessons</span>
      </div>

      <div class="playlist-items">
        <?php $flatIndex = 0; foreach ($SECTIONS as $sec): ?>
          <div class="section-block <?php echo $flatIndex === 0 ? 'open' : ''; ?>">
            <button type="button" class="section-header" onclick="this.parentElement.classList.toggle('open')">
              <span><?php echo htmlspecialchars($sec['title']); ?></span>
              <i class="fa-solid fa-chevron-down"></i>
            </button>
            <div class="section-body">
              <?php foreach ($sec['lessons'] as $lesson):
                $index = $flatIndex;
                $prevLessonId = $index === 0 ? null : $LESSONS[$index-1]['id'];
                $is_enabled = ($index === 0) || in_array($prevLessonId, $completed_lessons, true);
              ?>
                <div class="playlist-item <?php echo $index === 0 ? 'active' : ''; ?> <?php echo $is_enabled ? '' : 'disabled'; ?>"
                     data-lesson-id="<?php echo $lesson['id']; ?>"
                     data-index="<?php echo $index; ?>"
                     <?php if ($is_enabled): ?>
                     onclick="switchLesson(<?php echo htmlspecialchars(json_encode($lesson)); ?>, this)"
                     <?php else: ?>
                     onclick="alert('Please complete the previous lesson before accessing this module.');"
                     <?php endif; ?>>
                  <div class="item-thumb-wrapper">
                    <img src="<?php echo htmlspecialchars($lesson['thumb']); ?>" alt="Thumbnail" class="item-thumb">
                  </div>
                  <div class="item-details">
                    <h4 class="item-title"><?php echo htmlspecialchars($lesson['title']); ?></h4>
                    <span class="item-duration"><i class="fa-regular fa-clock"></i> <?php echo $lesson['duration']; ?></span>
                  </div>
                </div>
              <?php $flatIndex++; endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

  </div>

  <!-- Quiz Modal -->
  <div id="quizModal" class="quiz-modal-backdrop">
    <div class="quiz-modal-content">
      <div class="quiz-modal-header">
        <h3 id="quizTitle" style="font-size: 1.2rem; font-weight: 800; margin:0;">Course Final Quiz</h3>
        <button onclick="closeQuiz()" class="btn-close" aria-label="Close"></button>
      </div>
      <div id="quizContent" class="quiz-modal-body"></div>
      <div class="quiz-modal-footer">
        <button onclick="closeQuiz()" class="btn-secondary-custom">Cancel</button>
        <button id="submitQuizBtn" onclick="submitQuiz()" class="btn-complete">Submit Answers</button>
      </div>
    </div>
  </div>

  <!-- JavaScript Interactions -->
  <script>
    var LESSONS_JS = <?php echo json_encode($LESSONS); ?>;
    
    function switchLesson(lesson, element) {
      document.getElementById('mainVideoPlayer').src = lesson.video_url;
      document.getElementById('lessonTitle').innerText = lesson.title;
      document.getElementById('lessonDesc').innerText = lesson.description;

      document.querySelectorAll('.playlist-item').forEach(item => item.classList.remove('active'));
      element.classList.add('active');

      window.scrollTo({ top: 0, behavior: 'smooth' });

      var lessonId = element.getAttribute('data-lesson-id') || lesson.id;
      updateCompleteStatus(lessonId);
    }

    function updateCompleteStatus(lessonId) {
      var completed = <?php echo json_encode(array_map('intval', $completed_lessons)); ?>;
      var statusEl = document.getElementById('completeStatus');
      var btn = document.getElementById('markCompleteBtn');
      
      if (completed.indexOf(parseInt(lessonId)) !== -1) {
        statusEl.innerHTML = '<i class="fa-solid fa-circle-check"></i> Completed';
        statusEl.classList.add('completed');
        btn.disabled = true;
      } else {
        statusEl.innerHTML = '<i class="fa-regular fa-clock"></i> Not completed';
        statusEl.classList.remove('completed');
        btn.disabled = false;
      }
      window.__currentLessonId = parseInt(lessonId);
    }

    async function markComplete() {
      var lessonId = window.__currentLessonId || document.querySelector('.playlist-item.active')?.getAttribute('data-lesson-id');
      if (!lessonId) return alert('No lesson selected');
      
      try {
        var res = await fetch('mark_lesson_complete.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ course_id: '<?php echo $course['db_id']; ?>', lesson_id: lessonId })
        });
        
        var data = await res.json();
        if (data.success) {
          var statusEl = document.getElementById('completeStatus');
          statusEl.innerHTML = '<i class="fa-solid fa-circle-check"></i> Completed';
          statusEl.classList.add('completed');
          
          document.getElementById('markCompleteBtn').disabled = true;
          
          var idx = parseInt(document.querySelector('.playlist-item.active').getAttribute('data-index'));
          var next = document.querySelector('.playlist-item[data-index="' + (idx+1) + '"]');
          if (next) {
            next.classList.remove('disabled');
            next.onclick = function(){ switchLesson(LESSONS_JS[idx+1], this); };
          }

          var completed = data.completed_count || <?php echo $completed_count; ?>;
          var total = <?php echo $total_lessons; ?>;
          var pct = total > 0 ? Math.round((completed / total) * 100) : 0;
          document.getElementById('courseProgressFill').style.width = pct + '%';
          document.getElementById('courseProgressText').innerText = completed + ' / ' + total + ' Lessons — ' + pct + '%';
        } else {
          alert('Failed to mark complete: ' + (data.message || 'unknown'));
        }
      } catch (e) {
        alert('Network error: ' + e.message);
      }
    }

    document.addEventListener('DOMContentLoaded', function(){
      var active = document.querySelector('.playlist-item.active');
      if (active) updateCompleteStatus(active.getAttribute('data-lesson-id'));
    });

    var QUIZ = <?php echo json_encode($quiz_config['questions'] ?? []); ?>;
    var COURSE_DB = '<?php echo $course['db_id']; ?>';
    var ENROLL_ID = <?php echo intval($enroll_id ?? 0); ?>;

    function openQuiz() {
      if (!QUIZ || QUIZ.length === 0) return alert('No quiz available for this course.');
      var container = document.getElementById('quizContent');
      container.innerHTML = '';
      QUIZ.forEach(function(q, idx){
        var div = document.createElement('div');
        div.className = 'quiz-question-card';
        var html = '<div style="font-weight:700; margin-bottom:8px;">' + (idx+1) + '. ' + q.q + '</div>';
        q.options.forEach(function(opt, oi){
          html += '<label class="quiz-option-label"><input type="radio" name="q-' + idx + '" value="' + oi + '" /> <span>' + opt + '</span></label>';
        });
        div.innerHTML = html;
        container.appendChild(div);
      });
      document.getElementById('quizModal').style.display = 'flex';
    }

    function closeQuiz() { 
      document.getElementById('quizModal').style.display = 'none'; 
    }

    async function submitQuiz() {
      var answers = {};
      for (var i = 0; i < QUIZ.length; i++) {
        var radios = document.getElementsByName('q-' + i);
        var val = null;
        for (var r=0; r<radios.length; r++) if (radios[r].checked) { val = radios[r].value; break; }
        answers[i] = val !== null ? parseInt(val) : null;
      }

      document.getElementById('submitQuizBtn').disabled = true;

      try {
        var res = await fetch('submit_quiz.php', {
          method: 'POST', 
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ course_id: COURSE_DB, answers: answers })
        });
        var data = await res.json();
        if (data.success) {
          if (data.passed) {
            alert('Congratulations — you passed the quiz! You can now download your certificate.');
            closeQuiz();
            setTimeout(function(){ window.location.href = 'certificate_pdf.php?enroll_id=' + ENROLL_ID; }, 800);
          } else {
            alert('You scored ' + (data.score || 0) + '%. Please review the material and try again.');
          }
        } else {
          alert('Failed to submit quiz: ' + (data.message || 'Unknown error'));
        }
      } catch (e) {
        alert('Network error: ' + e.message);
      } finally {
        document.getElementById('submitQuizBtn').disabled = false;
      }
    }
  </script>

</body>
</html>