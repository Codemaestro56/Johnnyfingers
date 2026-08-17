<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once 'db.php';
// Load static course config (if present) to keep lesson counts consistent
require_once 'courses_config.php';
$STATIC_COURSES = isset($COURSES) ? $COURSES : [];

// ---- Auth Guard ----
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id   = $_SESSION['user_id'];
$full_name = $_SESSION['full_name'] ?? $_SESSION['user_name'] ?? 'Student';
$email     = $_SESSION['email'] ?? '';

// Check for payment success URL parameter
$payment_success = isset($_GET['payment']) && $_GET['payment'] === 'success';

// ---- Load ALL courses from the database ----
// Load all courses (active & inactive) so enrolled students can still see their courses
// even if the course is later deactivated by the admin.
try {
    $courses_stmt = $conn->query("
        SELECT id, course_code, title, track, price, is_active
        FROM courses
        ORDER BY id ASC
    ");
    $all_courses = $courses_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Database Error: " . htmlspecialchars($e->getMessage()));
}

// Re-key into $COURSES for template compatibility
$COURSES = [];
$COURSE_IMAGES = [];

foreach ($all_courses as $c) {
  $code = $c['course_code'];
  // Determine lesson count: prefer static config, fall back to DB default
  $lessons = 10;
  if (isset($STATIC_COURSES[$code]) && isset($STATIC_COURSES[$code]['lessons'])) {
    $lessons = (int)$STATIC_COURSES[$code]['lessons'];
  } else {
    // Try to match by static entry 'db_id' values
    foreach ($STATIC_COURSES as $sk => $sv) {
      if (isset($sv['db_id']) && ($sv['db_id'] === $c['course_code'] || (string)$sv['db_id'] === (string)$c['id'])) {
        if (isset($sv['lessons'])) { $lessons = (int)$sv['lessons']; break; }
      }
    }
  }

  $COURSES[$code] = [
    'id'        => (int)$c['id'],
    'db_id'     => (int)$c['id'],
    'title'     => $c['title'],
    'track'     => $c['track'] ?? 'Appliance Repair',
    'price'     => (float)$c['price'],
    'is_active' => (int)$c['is_active'],
    'lessons'   => $lessons,
  ];

    // Assign stock images based on course code (simple mapping)
    // You can expand this or pull images from the database if available
    if (stripos($code, 'wash') !== false) {
        $COURSE_IMAGES[$code] = 'https://images.unsplash.com/photo-1626806787461-102c1bfaaea1?auto=format&fit=crop&w=800&q=80';
    } elseif (stripos($code, 'ref') !== false || stripos($code, 'cool') !== false) {
        $COURSE_IMAGES[$code] = 'https://images.unsplash.com/photo-1584622650111-993a426fbf0a?auto=format&fit=crop&w=800&q=80';
    } elseif (stripos($code, 'hvac') !== false || stripos($code, 'elec') !== false) {
        $COURSE_IMAGES[$code] = 'https://images.unsplash.com/photo-1621905251189-08b45d6a269e?auto=format&fit=crop&w=800&q=80';
    } elseif (stripos($code, 'microwave') !== false || stripos($code, 'mw') !== false || stripos($code, 'kitchen') !== false) {
        $COURSE_IMAGES[$code] = 'https://images.unsplash.com/photo-1556911220-e15b29be8c8f?auto=format&fit=crop&w=800&q=80';
    } else {
        $COURSE_IMAGES[$code] = 'https://images.unsplash.com/photo-1621905251189-08b45d6a269e?auto=format&fit=crop&w=800&q=80';
    }
}

// ---- Fetch user's completed enrollments ----
try {
    $enrolled_stmt = $conn->prepare("
        SELECT course_id
        FROM enrollments
        WHERE user_id = :user_id AND payment_status = 'completed'
    ");
    $enrolled_stmt->execute([':user_id' => $user_id]);
    $user_enrollments = $enrolled_stmt->fetchAll(PDO::FETCH_ASSOC);

    $enrolled_course_ids = [];
    foreach ($user_enrollments as $e) {
        $enrolled_course_ids[] = (int)$e['course_id'];
    }
} catch (PDOException $e) {
    $enrolled_course_ids = [];
}

// Helper: Get course code from course ID
function get_course_code_by_id($course_id, $courses_array) {
    foreach ($courses_array as $code => $course) {
      if ((int)$course['db_id'] === (int)$course_id) {
        return $code;
      }
    }
    return null;
}

// Helper: Get lesson progress
function get_lesson_progress($conn, $user_id, $course_id) {
    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*) as completed
            FROM lesson_progress
            WHERE user_id = :user_id AND course_id = :course_id AND completed = 1
        ");
        $stmt->execute([':user_id' => $user_id, ':course_id' => $course_id]);
        return (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}

// Helper: Get enrollment ID
function get_enrollment_id($conn, $user_id, $course_id) {
    try {
        $stmt = $conn->prepare("
            SELECT id
            FROM enrollments
            WHERE user_id = :user_id AND course_id = :course_id
            ORDER BY created_at DESC LIMIT 1
        ");
        $stmt->execute([':user_id' => $user_id, ':course_id' => $course_id]);
        return $stmt->fetchColumn() ?: null;
    } catch (PDOException $e) {
        return null;
    }
}

// Define currency symbol — adjust or load from config if needed
if (!defined('CURRENCY_SYMBOL')) {
  define('CURRENCY_SYMBOL', '£');
}
// Ensure CSRF token for schedule form
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Handle schedule POST from students
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'schedule_training') {
  $resp_msg = '';
  if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    $resp_msg = 'Security token mismatch.';
  } else {
    $course_id = (int)($_POST['course_id'] ?? 0);
    $date = trim($_POST['schedule_date'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    if ($course_id <= 0 || $date === '') {
      $resp_msg = 'Please select a date.';
    } else {
      try {
        // Create table if it doesn't exist
        $conn->exec("CREATE TABLE IF NOT EXISTS training_schedule (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            course_id INT NOT NULL,
            scheduled_date DATE NOT NULL,
            notes TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY ux_user_course (user_id, course_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        // Upsert schedule for this user/course
        $stmt = $conn->prepare("INSERT INTO training_schedule (user_id, course_id, scheduled_date, notes)
            VALUES (:uid, :cid, :sdate, :notes)
            ON DUPLICATE KEY UPDATE scheduled_date = :sdate_up, notes = :notes_up");
        $stmt->execute([
          ':uid' => $user_id,
          ':cid' => $course_id,
          ':sdate' => $date,
          ':notes' => $notes,
          ':sdate_up' => $date,
          ':notes_up' => $notes,
        ]);

        $resp_msg = 'Scheduled training date saved.';
      } catch (PDOException $e) {
        $resp_msg = 'DB error: ' . $e->getMessage();
      }
    }
  }
  // Redirect to avoid form re-submission
  header('Location: dashboard.php?schedulem=' . urlencode($resp_msg));
  exit();
}

// Load current user's schedules
$user_schedules = [];
try {
  $stmt = $conn->prepare("SELECT course_id, scheduled_date, notes FROM training_schedule WHERE user_id = :uid");
  $stmt->execute([':uid' => $user_id]);
  while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $user_schedules[(int)$r['course_id']] = $r;
  }
} catch (PDOException $e) {
  // ignore if table doesn't exist yet
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Student Dashboard | Johnnyfingers</title>

  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="assets/theme.css">

  <style>
    :root {
      --bg-dark: #0b0f19;
      --card-bg: #151c2c;
      --border: #232d42;
      --primary: #3b82f6;
      --primary-hover: #2563eb;
      --accent-green: #10b981;
      --accent-amber: #f59e0b;
      --text-main: #f8fafc;
      --text-muted: #94a3b8;
    }

    * { 
      margin: 0; 
      padding: 0; 
      box-sizing: border-box; 
      font-family: 'Plus Jakarta Sans', sans-serif; 
    }

    body {
      background-color: var(--bg-dark);
      color: var(--text-main);
      min-height: 100vh;
      padding-bottom: 60px;
      position: relative;
      background-attachment: fixed;
      background-image: 
        linear-gradient(180deg, rgba(11, 15, 25, 0.88) 0%, rgba(11, 15, 25, 0.96) 100%),
        radial-gradient(at 10% 10%, rgba(59, 130, 246, 0.15) 0px, transparent 50%),
        radial-gradient(at 90% 90%, rgba(16, 185, 129, 0.10) 0px, transparent 50%),
        url('https://images.unsplash.com/photo-1581092160607-ee22621dd758?auto=format&fit=crop&w=1920&q=80');
      background-size: cover;
      background-position: center;
      background-repeat: no-repeat;
    }

    .navbar {
      background: rgba(21, 28, 44, 0.85);
      backdrop-filter: blur(12px);
      -webkit-backdrop-filter: blur(12px);
      border-bottom: 1px solid var(--border);
      padding: 18px 40px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      position: sticky;
      top: 0;
      z-index: 100;
    }

    .brand-logo {
      font-size: 1.3rem;
      font-weight: 800;
      color: #fff;
      text-decoration: none;
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .user-profile {
      display: flex;
      align-items: center;
      gap: 15px;
    }

    .avatar {
      width: 42px;
      height: 42px;
      border-radius: 50%;
      background: linear-gradient(135deg, var(--primary), var(--accent-green));
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 800;
      color: #fff;
      box-shadow: 0 4px 12px rgba(59, 130, 246, 0.25);
    }

    .logout-btn {
      color: var(--text-muted);
      text-decoration: none;
      font-size: 0.85rem;
      font-weight: 600;
      transition: color 0.2s;
    }
    .logout-btn:hover { color: #ef4444; }

    .container {
      max-width: 1100px;
      margin: 40px auto;
      padding: 0 20px;
    }

    .welcome-header {
      margin-bottom: 35px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 20px;
    }

    .welcome-title {
      font-size: 2rem;
      font-weight: 800;
      margin-bottom: 8px;
      letter-spacing: -0.5px;
    }

    .welcome-subtitle {
      color: var(--text-muted);
      font-size: 1rem;
    }

    .alert-success {
      background: rgba(16, 185, 129, 0.12);
      border: 1px solid rgba(16, 185, 129, 0.3);
      color: #34d399;
      padding: 16px 20px;
      border-radius: 16px;
      margin-bottom: 30px;
      display: flex;
      align-items: center;
      gap: 14px;
      animation: fadeIn 0.4s ease-out;
    }

    .section-title {
      font-size: 1.35rem;
      font-weight: 700;
      margin-bottom: 22px;
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .courses-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
      gap: 25px;
      margin-bottom: 50px;
    }

    .course-card {
      background: var(--card-bg);
      border: 1px solid var(--border);
      border-radius: 24px;
      overflow: hidden;
      display: flex;
      flex-direction: column;
      box-shadow: 0 20px 40px rgba(0, 0, 0, 0.25);
      transition: transform 0.25s cubic-bezier(0.4, 0, 0.2, 1), border-color 0.25s;
    }

    .course-card:hover {
      transform: translateY(-4px);
      border-color: rgba(59, 130, 246, 0.5);
    }

    .course-banner-wrapper {
      position: relative;
      width: 100%;
      height: 190px;
      overflow: hidden;
    }

    .course-banner {
      width: 100%;
      height: 100%;
      object-fit: cover;
      transition: transform 0.3s ease;
    }

    .course-card:hover .course-banner {
      transform: scale(1.05);
    }

    .course-overlay-badge {
      position: absolute;
      top: 15px;
      right: 15px;
      background: rgba(11, 15, 25, 0.75);
      backdrop-filter: blur(8px);
      padding: 6px 14px;
      border-radius: 20px;
      font-size: 0.75rem;
      font-weight: 700;
      color: #fff;
      border: 1px solid rgba(255, 255, 255, 0.1);
    }

    .course-body {
      padding: 24px;
      display: flex;
      flex-direction: column;
      flex: 1;
    }

    .course-tag {
      align-self: flex-start;
      background: rgba(59, 130, 246, 0.12);
      color: var(--primary);
      border: 1px solid rgba(59, 130, 246, 0.25);
      font-size: 0.75rem;
      font-weight: 800;
      padding: 5px 12px;
      border-radius: 20px;
      margin-bottom: 12px;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .course-tag-price {
      background: rgba(16, 185, 129, 0.12);
      color: var(--accent-green);
      border-color: rgba(16, 185, 129, 0.25);
    }

    .course-title {
      font-size: 1.15rem;
      font-weight: 700;
      margin-bottom: 12px;
      line-height: 1.4;
    }

    .progress-container {
      margin-top: auto;
      padding-top: 15px;
    }

    .progress-bar-bg {
      background: #0f172a;
      height: 8px;
      border-radius: 10px;
      overflow: hidden;
      margin-bottom: 8px;
      border: 1px solid rgba(255, 255, 255, 0.05);
    }

    .progress-fill {
      background: linear-gradient(90deg, #2563eb, #10b981);
      height: 100%;
      width: 0%;
      border-radius: 10px;
    }

    .progress-text {
      font-size: 0.8rem;
      color: var(--text-muted);
      display: flex;
      justify-content: space-between;
      font-weight: 600;
    }

    .btn-action {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 10px;
      width: 100%;
      padding: 14px;
      margin-top: 18px;
      border-radius: 14px;
      font-weight: 800;
      font-size: 0.95rem;
      text-decoration: none;
      transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .btn-enrolled {
      background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
      color: #fff;
      box-shadow: 0 10px 20px rgba(37, 99, 235, 0.3);
    }

    .btn-enrolled:hover {
      transform: translateY(-2px);
      box-shadow: 0 15px 25px rgba(37, 99, 235, 0.4);
      color: #fff;
    }

    .btn-checkout {
      background: #0f172a;
      border: 1px solid var(--border);
      color: var(--text-main);
    }

    .btn-checkout:hover {
      border-color: var(--primary);
      color: var(--primary);
      background: rgba(37, 99, 235, 0.08);
    }

    .empty-state {
      background: var(--card-bg);
      border: 1px dashed var(--border);
      border-radius: 24px;
      padding: 50px 20px;
      text-align: center;
      margin-bottom: 40px;
    }

    .empty-icon {
      font-size: 2.5rem;
      color: var(--text-muted);
      margin-bottom: 15px;
    }

    .action-buttons {
      margin-top: 12px;
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
    }

    .action-buttons a {
      padding: 8px 12px;
      border-radius: 8px;
      text-decoration: none;
      font-weight: 700;
      font-size: 0.8rem;
      flex: 1;
      text-align: center;
      min-width: 140px;
    }

    .receipt-btn {
      background: #0f172a;
      color: #fff;
      border: 1px solid rgba(255, 255, 255, 0.05);
    }

    .receipt-btn:hover {
      border-color: var(--primary);
      background: rgba(37, 99, 235, 0.1);
    }

    .pdf-btn {
      background: #2563eb;
      color: #fff;
    }

    .pdf-btn:hover {
      background: #1d4ed8;
      color: #fff;
    }

    .cert-btn {
      background: #10b981;
      color: #fff;
    }

    .cert-btn:hover {
      background: #059669;
      color: #fff;
    }

    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(-10px); }
      to { opacity: 1; transform: translateY(0); }
    }

    @media (max-width: 992px) {
      .container { margin: 30px auto; }
      .welcome-title { font-size: 1.75rem; }
      .courses-grid { grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px; }
    }

    @media (max-width: 600px) {
      body { background-attachment: scroll; }
      .navbar { padding: 14px 18px; }
      .brand-logo { font-size: 1.15rem; }
      .user-profile { gap: 10px; }
      .avatar { width: 36px; height: 36px; font-size: 0.9rem; }
      .container { padding: 0 16px; margin: 25px auto; }
      .welcome-header { margin-bottom: 25px; }
      .welcome-title { font-size: 1.5rem; }
      .welcome-subtitle { font-size: 0.9rem; }
      .section-title { font-size: 1.2rem; margin-bottom: 16px; }
      .courses-grid { grid-template-columns: 1fr; gap: 20px; }
      .course-card { border-radius: 20px; }
      .course-banner-wrapper { height: 170px; }
      .course-body { padding: 20px; }
      .action-buttons a { min-width: 100%; }
    }
  </style>
</head>
<body>

  <!-- Top Navigation -->
  <nav class="navbar">
    <a href="Home.php" class="brand-logo" style="display: inline-flex; align-items: center; text-decoration: none;">
      <img src="images/logo.png" alt="Johnny Fingers Logo" style="height: 45px; width: auto; object-fit: contain;">
    </a>
    
    <div class="user-profile">
      <div class="avatar">
        <?php echo htmlspecialchars(strtoupper(substr($full_name, 0, 1))); ?>
      </div>
      <div>
        <div style="font-weight: 700; font-size: 0.9rem;"><?php echo htmlspecialchars($full_name); ?></div>
        <a href="logout.php" class="logout-btn"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
      </div>
    </div>
  </nav>

  <div class="container">

    <!-- Payment Success Alert -->
    <?php if ($payment_success): ?>
      <div class="alert-success">
        <i class="fa-solid fa-circle-check fa-lg"></i>
        <div>
          <strong style="font-size: 1rem;">Payment Successful! 🎉</strong>
          <div style="font-size: 0.88rem; margin-top: 2px;">Your enrollment has been activated. Select your course below to start learning immediately.</div>
        </div>
      </div>
    <?php endif; ?>

    <?php if (!empty($_GET['schedulem'])): ?>
      <div class="alert-success" style="background:rgba(37,99,235,0.08); border-color:rgba(37,99,235,0.12); color:var(--primary);">
        <i class="fa-solid fa-calendar-check"></i>
        <div><?php echo htmlspecialchars($_GET['schedulem']); ?></div>
      </div>
    <?php endif; ?>

    <!-- Welcome Header -->
    <div class="welcome-header">
      <div>
        <h1 class="welcome-title">Welcome, <?php echo htmlspecialchars(explode(' ', $full_name)[0]); ?>! 👋</h1>
        <p class="welcome-subtitle">Access your appliance repair tracks and monitor your learning progress.</p>
      </div>
    </div>

    <!-- Section 1: Enrolled Courses -->
    <h2 class="section-title">
      <i class="fa-solid fa-graduation-cap" style="color: var(--primary);"></i> My Active Enrollments
    </h2>

    <div class="courses-grid">
      <?php 
        $active_courses_count = 0;
        foreach ($COURSES as $code => $course): 
          if (!in_array((int)$course['db_id'], $enrolled_course_ids, true)) {
              continue;
          }
          $active_courses_count++;
          $thumb = $COURSE_IMAGES[$code] ?? 'https://images.unsplash.com/photo-1621905251189-08b45d6a269e?auto=format&fit=crop&w=800&q=80';
          $completed_count = get_lesson_progress($conn, $user_id, $course['db_id']);
          $pct = $course['lessons'] > 0 ? round(($completed_count / $course['lessons']) * 100) : 0;
          $enroll_id = get_enrollment_id($conn, $user_id, $course['db_id']);
      ?>
        <div class="course-card">
          <div class="course-banner-wrapper">
            <img src="<?php echo htmlspecialchars($thumb); ?>" alt="Course Thumbnail" class="course-banner" loading="lazy">
            <span class="course-overlay-badge"><i class="fa-solid fa-circle-play" style="color: var(--accent-green);"></i> Active</span>
          </div>
          <div class="course-body">
            <span class="course-tag">Enrolled Track</span>
            <h3 class="course-title"><?php echo htmlspecialchars($course['title']); ?></h3>
            
            <div class="progress-container">
              <div class="progress-bar-bg">
                <div class="progress-fill" style="width: <?php echo $pct; ?>%;"></div>
              </div>
              <div class="progress-text">
                <span>Progress</span>
                <span><?php echo $completed_count; ?> / <?php echo $course['lessons']; ?> Lessons</span>
              </div>
            </div>

            <a href="player.php?course=<?php echo urlencode($code); ?>" class="btn-action btn-enrolled">
              <i class="fa-solid fa-play"></i> Continue Learning
            </a>

            <div class="action-buttons">
              <?php if ($enroll_id): ?>
                <a href="receipt.php?enroll_id=<?php echo (int)$enroll_id; ?>" class="receipt-btn">View Receipt</a>
                <a href="receipt_pdf.php?enroll_id=<?php echo (int)$enroll_id; ?>" class="pdf-btn">Download Receipt</a>
              <?php endif; ?>
              <?php if ($pct === 100 && $enroll_id): ?>
                <a href="certificate_pdf.php?enroll_id=<?php echo (int)$enroll_id; ?>" class="cert-btn">Download Certificate</a>
              <?php endif; ?>
            </div>

            <div style="margin-top:14px; border-top:1px dashed rgba(255,255,255,0.03); padding-top:12px;">
              <strong style="display:block; margin-bottom:8px;">Schedule Physical Training</strong>
              <?php $sch = $user_schedules[$course['db_id']] ?? null; ?>
              <?php if ($sch): ?>
                <div style="color:var(--text-muted); margin-bottom:8px;">Your scheduled date: <strong style="color:var(--primary);"><?php echo htmlspecialchars($sch['scheduled_date']); ?></strong></div>
              <?php endif; ?>

              <form method="POST" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="action" value="schedule_training">
                <input type="hidden" name="course_id" value="<?php echo (int)$course['db_id']; ?>">
                <input type="date" name="schedule_date" value="<?php echo $sch['scheduled_date'] ?? ''; ?>" required style="padding:8px 10px; border-radius:8px; border:1px solid var(--border); background:var(--card-bg); color:var(--text-main);">
                <input type="text" name="notes" placeholder="Notes (optional)" value="<?php echo htmlspecialchars($sch['notes'] ?? ''); ?>" style="padding:8px 10px; border-radius:8px; border:1px solid var(--border); background:var(--card-bg); color:var(--text-main); min-width:180px;">
                <button class="btn-action btn-enrolled" type="submit" style="padding:10px 14px; min-width:160px;">Save Date</button>
              </form>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    

    <?php if ($active_courses_count === 0): ?>
      <div class="empty-state">
        <i class="fa-solid fa-book-open empty-icon"></i>
        <h3 style="margin-bottom: 8px;">No Enrolled Courses Yet</h3>
        <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 20px;">You haven't enrolled in any appliance repair training track yet.</p>
      </div>
    <?php endif; ?>

    <!-- Section 2: Explore Other Tracks (Only active, non-enrolled courses) -->
    <h2 class="section-title" style="margin-top: 30px;">
      <i class="fa-solid fa-compass" style="color: var(--accent-amber);"></i> Available Specialty Tracks
    </h2>

    <div class="courses-grid">
      <?php 
        foreach ($COURSES as $code => $course): 
          // Skip if already enrolled
          if (in_array((int)$course['db_id'], $enrolled_course_ids, true)) {
              continue;
          }
          // Skip if course is inactive (only show active courses for purchase)
          if ((int)$course['is_active'] !== 1) {
              continue;
          }
          $thumb = $COURSE_IMAGES[$code] ?? 'https://images.unsplash.com/photo-1621905251189-08b45d6a269e?auto=format&fit=crop&w=800&q=80';
      ?>
        <div class="course-card">
          <div class="course-banner-wrapper">
            <img src="<?php echo htmlspecialchars($thumb); ?>" alt="Course Thumbnail" class="course-banner" loading="lazy">
          </div>
          <div class="course-body">
            <span class="course-tag course-tag-price">
              <?php echo CURRENCY_SYMBOL . number_format($course['price'], 2); ?>
            </span>
            <h3 class="course-title"><?php echo htmlspecialchars($course['title']); ?></h3>
            <p style="color: var(--text-muted); font-size: 0.85rem; margin-bottom: 15px;">
              <i class="fa-solid fa-play-circle" style="color: var(--primary);"></i> <?php echo $course['lessons']; ?> Comprehensive Video Lessons
            </p>

            <a href="checkout.php?course=<?php echo urlencode($code); ?>" class="btn-action btn-checkout">
              <i class="fa-solid fa-bolt"></i> Enroll in Track
            </a>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

  </div>

</body>
</html>