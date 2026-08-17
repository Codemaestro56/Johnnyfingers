<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once 'db.php';
require_once 'courses_config.php';

// ---- Admin guard ----
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: dashboard.php");
    exit();
}

// Every admin session needs a CSRF token to render into forms.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$CSRF_TOKEN = $_SESSION['csrf_token'];

$flash = '';

/**
 * Verify the posted CSRF token against the session token.
 * Every state-changing action must call this before touching the DB.
 */
function verify_csrf(): bool {
    return isset($_POST['csrf_token'])
        && isset($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
}

// ---------------------------------------------------------------------
// Action handlers — each takes the PDO connection and returns a flash
// message. Keeping them as standalone functions makes the dispatch
// block below trivial to read and to extend.
// ---------------------------------------------------------------------

function action_approve_enrollment(PDO $conn): string {
    if (empty($_POST['enrollment_id'])) {
        return "Missing enrollment id.";
    }
    $eid = (int)$_POST['enrollment_id'];
    $upd = $conn->prepare("UPDATE enrollments SET payment_status = 'completed' WHERE id = :id");
    $upd->execute([':id' => $eid]);
    return "Enrollment #$eid approved and marked completed.";
}

function action_change_role(PDO $conn): string {
    if (empty($_POST['user_id']) || empty($_POST['new_role'])) {
        return "Missing user id or role.";
    }
    $uid = (int)$_POST['user_id'];
    $new = $_POST['new_role'] === 'admin' ? 'admin' : 'student';
    $upd = $conn->prepare("UPDATE users SET role = :role WHERE id = :id");
    $upd->execute([':role' => $new, ':id' => $uid]);
    return "User #$uid role updated to $new.";
}

function action_reset_password(PDO $conn): string {
    if (empty($_POST['user_id'])) {
        return "Missing user id.";
    }
    $uid = (int)$_POST['user_id'];
    $new_plain = 'changeme123';
    $hash = password_hash($new_plain, PASSWORD_DEFAULT);
    $upd = $conn->prepare("UPDATE users SET password = :pw WHERE id = :id");
    $upd->execute([':pw' => $hash, ':id' => $uid]);
    return "Password for user #$uid reset to a temporary value. Advise them to change it on next login.";
}

function action_save_lesson(PDO $conn): string {
    $course_id  = trim($_POST['course_id'] ?? '');
    $lesson_id  = trim($_POST['lesson_id'] ?? '');
    $title      = trim($_POST['title'] ?? '');
    $duration   = trim($_POST['duration'] ?? '');
    $video_url  = trim($_POST['video_url'] ?? '');

    if (!$course_id || !$lesson_id || !$title || !$duration || !$video_url) {
        return "Please fill in all lesson details correctly.";
    }

    $stmt = $conn->prepare("INSERT INTO lessons (course_id, lesson_id, title, duration, video_url)
                            VALUES (:course_id, :lesson_id, :title, :duration, :video_url)
                            ON DUPLICATE KEY UPDATE title = :title_up, duration = :duration_up, video_url = :video_url_up");
    $stmt->execute([
        ':course_id'     => $course_id,
        ':lesson_id'     => $lesson_id,
        ':title'         => $title,
        ':duration'      => $duration,
        ':video_url'     => $video_url,
        ':title_up'      => $title,
        ':duration_up'   => $duration,
        ':video_url_up'  => $video_url,
    ]);
    return "Lesson dynamic record saved successfully for " . htmlspecialchars($course_id) . "!";
}

function action_upload_handout(PDO $conn): string {
    if (empty($_POST['course_code'])) {
        return "Please select a course.";
    }

    if (!isset($_FILES['handout_file']) || $_FILES['handout_file']['error'] !== UPLOAD_ERR_OK) {
        return "No file uploaded or upload error.";
    }

    $course = trim($_POST['course_code']);
    $type   = trim($_POST['handout_type'] ?? 'handout');
    $desc   = trim($_POST['description'] ?? '');

    $file = $_FILES['handout_file'];
    $maxSize = 10 * 1024 * 1024; // 10MB
    $allowedExt = ['pdf','png','jpg','jpeg','svg','zip','doc','docx','ppt','pptx'];

    if ($file['size'] > $maxSize) {
        return "File too large (max 10MB).";
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) {
        return "Invalid file type. Allowed: " . implode(', ', $allowedExt) . ".";
    }

    $baseDir = __DIR__ . '/assets/handouts';
    if (!is_dir($baseDir)) @mkdir($baseDir, 0755, true);

    $courseDir = $baseDir . '/' . preg_replace('/[^a-z0-9_\-]/i', '_', $course);
    if (!is_dir($courseDir)) @mkdir($courseDir, 0755, true);

    $safeName = preg_replace('/[^a-z0-9_\-\.]/i', '_', basename($file['name']));
    $targetName = time() . '_' . $safeName;
    $targetPath = $courseDir . '/' . $targetName;

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        return "Failed to move uploaded file.";
    }

    $meta = [
        'original' => $file['name'],
        'uploaded_at' => date('c'),
        'type' => $type,
        'description' => $desc,
    ];
    @file_put_contents($targetPath . '.meta.json', json_encode($meta));

    return "Uploaded {$file['name']} successfully.";
}

function action_delete_handout(PDO $conn): string {
    $rel = trim($_POST['file_path'] ?? '');
    if ($rel === '') return "Missing file path.";

    $full = realpath(__DIR__ . '/' . $rel);
    $allowedBase = realpath(__DIR__ . '/assets/handouts');
    if ($full === false || $allowedBase === false || strpos($full, $allowedBase) !== 0) {
        return "Invalid file path.";
    }

    if (is_file($full)) {
        @unlink($full);
        @unlink($full . '.meta.json');
        return "File deleted.";
    }

    return "File not found.";
}

function action_toggle_course_status(PDO $conn): void {
    $course_id      = (int)($_POST['course_id'] ?? 0);
    $current_status = (int)($_POST['current_status'] ?? 0);
    $new_status     = $current_status === 1 ? 0 : 1;

    $stmt = $conn->prepare("UPDATE courses SET is_active = ? WHERE id = ?");
    $stmt->execute([$new_status, $course_id]);

    header("Location: admin.php?msg=course_status_updated");
    exit;
}

function action_update_course(PDO $conn): void {
    $course_id = (int)($_POST['course_id'] ?? 0);
    $title     = trim($_POST['title'] ?? '');
    $track     = trim($_POST['track'] ?? '');
    $price     = (float)($_POST['price'] ?? 0);

    if ($course_id <= 0 || $title === '' || $track === '' || $price < 0) {
        header("Location: admin.php?msg=course_update_invalid");
        exit;
    }

    $stmt = $conn->prepare("UPDATE courses SET title = ?, track = ?, price = ? WHERE id = ?");
    $stmt->execute([$title, $track, $price, $course_id]);

    header("Location: admin.php?msg=course_updated");
    exit;
}

// ---------------------------------------------------------------------
// POST dispatch — single entry point, single CSRF check, no duplicate
// "is this a POST" branches.
// ---------------------------------------------------------------------

// Actions that redirect immediately (toggle/update) instead of falling
// through to a rendered flash message.
$REDIRECTING_ACTIONS = ['toggle_course_status', 'update_course'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if (!verify_csrf()) {
        $flash = "Security check failed — please refresh the page and try again.";
    } else {
        switch ($action) {
            case 'approve_enrollment':
                $flash = action_approve_enrollment($conn);
                break;
            case 'change_role':
                $flash = action_change_role($conn);
                break;
            case 'reset_password':
                $flash = action_reset_password($conn);
                break;
            case 'save_lesson':
                $flash = action_save_lesson($conn);
                break;
            case 'upload_handout':
                $flash = action_upload_handout($conn);
                break;
            case 'delete_handout':
                $flash = action_delete_handout($conn);
                break;
            case 'toggle_course_status':
                action_toggle_course_status($conn); // exits internally
                break;
            case 'update_course':
                action_update_course($conn); // exits internally
                break;
        }
    }
}

// ---------------------------------------------------------------------
// Data loading — each query runs exactly once.
// ---------------------------------------------------------------------

try {
    $total_revenue = $conn->query(
        "SELECT COALESCE(SUM(amount_paid),0) AS total FROM enrollments WHERE payment_status = 'completed'"
    )->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

    $total_students = $conn->query(
        "SELECT COUNT(*) AS c FROM users"
    )->fetch(PDO::FETCH_ASSOC)['c'] ?? 0;

    $active_enrollments = $conn->query(
        "SELECT COUNT(*) AS c FROM enrollments WHERE payment_status = 'completed'"
    )->fetch(PDO::FETCH_ASSOC)['c'] ?? 0;

    // Courses, re-keyed into $COURSES for template compatibility.
    // Load ALL courses (active and inactive) for full admin management.
    $db_courses = $conn->query(
        "SELECT id, course_code, title, track, price, is_active FROM courses ORDER BY id ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    $COURSES = [];
    foreach ($db_courses as $c) {
        $COURSES[$c['course_code']] = [
            'id'        => (int)$c['id'],
            'db_id'     => (int)$c['id'],
            'title'     => $c['title'],
            'track'     => $c['track'] ?? 'Appliance Repair',
            'price'     => (float)$c['price'],
            'is_active' => (int)$c['is_active'],
            'lessons'   => 10, // Fallback lesson count; replace with a real COUNT(*) on lessons if available.
        ];
    }

    // Aggregated lesson progress: user_id => course_id => completed_count
    $progress_raw = $conn->query("
        SELECT user_id, course_id, COUNT(*) AS completed_count
        FROM lesson_progress
        WHERE completed = 1
        GROUP BY user_id, course_id
    ")->fetchAll(PDO::FETCH_ASSOC);

    $student_progress = [];
    foreach ($progress_raw as $pr) {
        $student_progress[$pr['user_id']][$pr['course_id']] = (int)$pr['completed_count'];
    }

    $transactions = $conn->query("
        SELECT e.id AS enroll_id, e.user_id, e.course_id, e.amount_paid, e.payment_status, e.created_at,
               u.full_name, u.email
        FROM enrollments e
        LEFT JOIN users u ON e.user_id = u.id
        ORDER BY e.created_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $students = $conn->query("
        SELECT id, full_name, email, role, created_at FROM users ORDER BY created_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Build users-by-id lookup once, used by the certificates panel below
    // instead of running a query per enrollment row (was an N+1 query).
    $users_by_id = [];
    foreach ($students as $s) {
        $users_by_id[$s['id']] = $s;
    }

    // Completed enrollments, used both for the KPI count and the
    // certificates panel — computed once here.
    $completed_enrollments = $conn->query("
        SELECT id, user_id, course_id, created_at
        FROM enrollments
        WHERE payment_status = 'completed'
        ORDER BY created_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $certificates = [];
    foreach ($completed_enrollments as $en) {
        $uid = $en['user_id'];
        $cid = $en['course_id'];

        $lessons_total = 0;
        $course_title  = $cid;
        foreach ($COURSES as $cc) {
            if ($cc['db_id'] == $cid) {
                $lessons_total = $cc['lessons'];
                $course_title  = $cc['title'];
                break;
            }
        }

        $done    = $student_progress[$uid][$cid] ?? 0;
        $percent = $lessons_total > 0 ? (int)round(($done / $lessons_total) * 100) : 0;

        $certificates[] = [
            'enroll_id'    => (int)$en['id'],
            'student_name' => $users_by_id[$uid]['full_name'] ?? '—',
            'course_title' => $course_title,
            'percent'      => $percent,
            'cert_id'      => $percent === 100 ? 'CERT-' . (int)$en['id'] : '',
            'issue_date'   => $percent === 100 ? $en['created_at'] : '',
        ];
    }

    // Fixes the previously-undefined $cert_count used in the KPI card.
    $cert_count = count(array_filter($certificates, fn($c) => $c['percent'] === 100));

    // Load training schedules (if table exists)
    try {
        $training_schedules = $conn->query("SELECT ts.id, ts.user_id, ts.course_id, ts.scheduled_date, ts.notes, u.full_name, u.email
            FROM training_schedule ts
            LEFT JOIN users u ON ts.user_id = u.id
            ORDER BY ts.scheduled_date DESC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $training_schedules = [];
    }

} catch (PDOException $e) {
    die('DB Error: ' . htmlspecialchars($e->getMessage()));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Dashboard | Johnnyfingers Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #0b0f19;
            --card: #1e293b;
            --card-subtle: #0f172a;
            --muted: #94a3b8;
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --success: #10b981;
            --warn: #f59e0b;
            --danger: #ef4444;
            --border: #334155;
            --text: #f8fafc;
        }
        * { box-sizing: border-box; font-family: 'Plus Jakarta Sans', system-ui, Arial, sans-serif; margin: 0; padding: 0; }
        body { background: var(--bg); color: var(--text); padding: 32px 20px; min-height: 100vh; }
        .wrap { max-width: 1240px; margin: 0 auto; }

        .topbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 16px; }
        .topbar h1 { font-size: 1.75rem; font-weight: 800; letter-spacing: -0.02em; }

        .kpi { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 16px; margin-bottom: 28px; }
        .card { background: var(--card); border: 1px solid var(--border); padding: 20px; border-radius: 16px; position: relative; overflow: hidden; box-shadow: 0 10px 25px rgba(0,0,0,0.3); }
        .kpi .card-header { display: flex; justify-content: space-between; align-items: center; color: var(--muted); font-weight: 700; font-size: 0.9rem; }
        .kpi .value { font-size: 1.6rem; font-weight: 800; margin-top: 8px; color: var(--text); }
        .kpi .sparkline { margin-top: 12px; height: 35px; width: 100%; }

        .analytics-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-bottom: 28px; }
        @media (max-width: 900px) { .analytics-grid { grid-template-columns: 1fr; } }
        .chart-card { background: var(--card); border: 1px solid var(--border); border-radius: 16px; padding: 20px; }
        .chart-card h3 { font-size: 1.1rem; font-weight: 700; margin-bottom: 16px; display: flex; align-items: center; gap: 10px; }

        .tabs { display: flex; gap: 10px; margin: 20px 0; border-bottom: 1px solid var(--border); padding-bottom: 12px; overflow-x: auto; }
        .tab { background: transparent; border: 1px solid transparent; padding: 10px 18px; border-radius: 10px; cursor: pointer; color: var(--muted); font-weight: 700; transition: all 0.2s ease; white-space: nowrap; }
        .tab:hover { color: var(--text); background: rgba(255,255,255,0.03); }
        .tab.active { background: linear-gradient(135deg, var(--primary), var(--primary-hover)); color: #fff; border-color: rgba(255,255,255,0.1); box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3); }

        .panel { display: none; margin-top: 16px; }
        .panel.active { display: block; }

        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { padding: 14px 12px; text-align: left; border-bottom: 1px solid var(--border); font-size: 0.92rem; }
        th { color: var(--muted); font-weight: 700; background: rgba(15, 23, 42, 0.6); }
        tr:hover { background: rgba(255,255,255,0.02); }

        .badge { display: inline-block; padding: 4px 10px; border-radius: 999px; font-weight: 700; font-size: 0.78rem; }
        .badge.success { background: rgba(16,185,129,0.15); color: var(--success); border: 1px solid rgba(16,185,129,0.3); }
        .badge.pending { background: rgba(245,158,11,0.15); color: var(--warn); border: 1px solid rgba(245,158,11,0.3); }
        .badge.failed { background: rgba(239,68,68,0.15); color: var(--danger); border: 1px solid rgba(239,68,68,0.3); }

        .btn { background: var(--primary); color: #fff; padding: 10px 16px; border-radius: 10px; border: none; cursor: pointer; font-weight: 700; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; transition: background 0.2s; font-size: 0.9rem; }
        .btn:hover { background: var(--primary-hover); }
        .btn.ghost { background: transparent; border: 1px solid var(--border); color: var(--muted); }
        .btn.ghost:hover { background: rgba(255,255,255,0.05); color: var(--text); }

        .search { margin: 12px 0; display: flex; gap: 10px; }
        .search input, select, .form-control { flex: 1; padding: 12px 14px; border-radius: 10px; border: 1px solid var(--border); background: var(--card-subtle); color: var(--text); font-size: 0.95rem; }
        .search input:focus, select:focus, .form-control:focus { outline: none; border-color: var(--primary); }

        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 16px; }

        #courses .grid { display: grid !important; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)) !important; gap: 16px !important; align-items: start; }
        #courses .course-card { display: flex; flex-direction: column; justify-content: space-between; min-height: 120px; box-sizing: border-box; }
        #courses .course-card > div:first-child { margin-bottom: 8px; }
        #courses .course-card .actions a.btn, #courses .course-card .actions a.btn.ghost { white-space: nowrap; }
        .course-card { padding: 18px; border-radius: 14px; background: var(--card-subtle); border: 1px solid var(--border); }

        .admin-form-container { max-width: 650px; margin: 10px 0; background: var(--card); border: 1px solid var(--border); border-radius: 16px; padding: 30px; box-shadow: 0 10px 30px rgba(0,0,0,0.3); }
        .form-group { margin-bottom: 18px; }
        .form-group label { display: block; font-size: 0.88rem; font-weight: 700; margin-bottom: 8px; color: var(--text); }
        .alert { background: rgba(16, 185, 129, 0.15); color: var(--success); padding: 14px; border-radius: 10px; margin-bottom: 20px; font-weight: 600; font-size: 0.95rem; border: 1px solid rgba(16, 185, 129, 0.3); }

        /* ---- Mobile responsiveness ---- */
        @media (max-width: 768px) {
            body { padding: 16px 12px; }
            .topbar h1, .brand-logo img { height: 36px; }
            .topbar > div[style*="justify-content:flex-end"] { width: 100%; justify-content: flex-start; }
            .topbar > div[style*="justify-content:flex-end"] .btn { flex: 1 1 auto; justify-content: center; padding: 10px 12px; font-size: 0.85rem; }

            .kpi .value { font-size: 1.3rem; }

            /* Tables: below 768px, each row becomes its own card, and each
               cell becomes a label+value line using the data-label attribute
               set on every <td>. No horizontal scrolling, no squeezed
               columns, no overlap. */
            table, thead, tbody, tr, td, th { display: block; }
            thead { position: absolute; top: -9999px; left: -9999px; } /* keep for screen readers, hide visually */

            table { border: none; margin-top: 16px; }
            tbody tr {
                background: var(--card-subtle);
                border: 1px solid var(--border);
                border-radius: 12px;
                margin-bottom: 14px;
                padding: 6px 14px;
            }
            tbody tr:hover { background: var(--card-subtle); }
            td {
                display: flex;
                justify-content: space-between;
                align-items: center;
                gap: 12px;
                text-align: right;
                border-bottom: 1px solid rgba(255,255,255,0.06);
                padding: 10px 0;
                white-space: normal;
            }
            td:last-child { border-bottom: none; }
            td[colspan] { display: block; text-align: center; }
            td::before {
                content: attr(data-label);
                font-weight: 700;
                color: var(--muted);
                text-align: left;
                margin-right: auto;
                white-space: nowrap;
            }
            td.actions {
                flex-direction: column;
                align-items: stretch;
                gap: 8px;
            }
            td.actions::before { margin-bottom: 4px; }
            td.actions .btn, td.actions a.btn, td.actions form { width: 100%; }
            td.actions form button { width: 100%; }

            .search { flex-direction: column; }
            .search input, .search button { width: 100%; }

            .admin-form-container { padding: 18px; }

            /* Full-screen modals give inputs room instead of a cramped
               fixed-width box on a small viewport. */
            #progressModal, #editCourseModal { padding: 12px; }
            #progressModal > div, #editCourseModal > div { max-width: 100%; max-height: 90vh; overflow-y: auto; }
        }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="topbar">
            <div>
                <a href="Home.php" class="brand-logo" style="display: inline-flex; align-items: center; text-decoration: none;">
      <img src="images/logo.png" alt="Johnny Fingers Logo" style="height: 45px; width: auto; object-fit: contain;">
    </a>

            </div>
            <div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap; justify-content:flex-end;">
                <a href="dashboard.php" class="btn ghost"><i class="fa-solid fa-graduation-cap"></i> Student Site</a>
                <a href="admin_quiz.php" class="btn ghost"><i class="fa-solid fa-list-check"></i> Quizzes</a>
                <a href="logout.php" class="btn"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
            </div>
        </div>

        <?php if (!empty($flash)): ?>
            <div class="alert"><i class="fa-solid fa-circle-check"></i> <?php echo htmlspecialchars($flash); ?></div>
        <?php endif; ?>

        <div class="kpi">
            <div class="card">
                <div class="card-header">
                    <span><i class="fa-solid fa-wallet"></i> Total Revenue</span>
                    <span style="color:var(--success); font-size:0.8rem;"><i class="fa-solid fa-arrow-trend-up"></i> +12.5%</span>
                </div>
                <div class="value"><?php echo CURRENCY_SYMBOL . number_format((float)$total_revenue); ?></div>
                <svg class="sparkline" viewBox="0 0 100 30" preserveAspectRatio="none">
                    <path d="M0,25 Q25,5 50,18 T100,2" fill="none" stroke="var(--success)" stroke-width="2.5" />
                </svg>
            </div>
            <div class="card">
                <div class="card-header">
                    <span><i class="fa-solid fa-users"></i> Total Students</span>
                    <span style="color:var(--primary); font-size:0.8rem;"><i class="fa-solid fa-user-plus"></i> Dynamic</span>
                </div>
                <div class="value"><?php echo (int)$total_students; ?></div>
                <svg class="sparkline" viewBox="0 0 100 30" preserveAspectRatio="none">
                    <path d="M0,20 Q30,28 60,10 T100,5" fill="none" stroke="var(--primary)" stroke-width="2.5" />
                </svg>
            </div>
            <div class="card">
                <div class="card-header">
                    <span><i class="fa-solid fa-book-bookmark"></i> Active Enrollments</span>
                    <span style="color:var(--warn); font-size:0.8rem;"><i class="fa-solid fa-chart-simple"></i> Active</span>
                </div>
                <div class="value"><?php echo (int)$active_enrollments; ?></div>
                <svg class="sparkline" viewBox="0 0 100 30" preserveAspectRatio="none">
                    <path d="M0,15 Q35,2 70,22 T100,8" fill="none" stroke="var(--warn)" stroke-width="2.5" />
                </svg>
            </div>
            <div class="card">
                <div class="card-header">
                    <span><i class="fa-solid fa-certificate"></i> Certificates Issued</span>
                    <span style="color:var(--success); font-size:0.8rem;"><i class="fa-solid fa-award"></i> Verified</span>
                </div>
                <div class="value"><?php echo (int)$cert_count; ?></div>
                <svg class="sparkline" viewBox="0 0 100 30" preserveAspectRatio="none">
                    <path d="M0,28 Q40,12 70,18 T100,2" fill="none" stroke="var(--success)" stroke-width="2.5" />
                </svg>
            </div>
        </div>

        <div class="analytics-grid">
            <div class="chart-card">
                <h3><i class="fa-solid fa-chart-area" style="color:var(--primary);"></i> Revenue &amp; Enrollment Growth</h3>
                <svg viewBox="0 0 500 150" style="width:100%; height:160px; overflow:visible;">
                    <defs>
                        <linearGradient id="chartGrad" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stop-color="#2563eb" stop-opacity="0.4"/>
                            <stop offset="100%" stop-color="#2563eb" stop-opacity="0.0"/>
                        </linearGradient>
                    </defs>
                    <path d="M0,130 C100,100 150,40 250,70 C350,100 400,20 500,40 L500,150 L0,150 Z" fill="url(#chartGrad)" />
                    <path d="M0,130 C100,100 150,40 250,70 C350,100 400,20 500,40" fill="none" stroke="var(--primary)" stroke-width="3" />
                    <circle cx="250" cy="70" r="5" fill="var(--primary)" />
                    <circle cx="500" cy="40" r="5" fill="var(--success)" />
                </svg>
            </div>
            <div class="chart-card">
                <h3><i class="fa-solid fa-chart-pie" style="color:var(--success);"></i> Enrollment Ratio</h3>
                <div style="display:flex; justify-content:center; align-items:center; height:150px;">
                    <svg viewBox="0 0 36 36" style="width:120px; height:120px;">
                        <path d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" fill="none" stroke="var(--border)" stroke-width="3.8"/>
                        <path d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" fill="none" stroke="var(--primary)" stroke-width="3.8" stroke-dasharray="75, 100"/>
                        <path d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831" fill="none" stroke="var(--success)" stroke-width="3.8" stroke-dasharray="25, 100"/>
                    </svg>
                </div>
            </div>
        </div>

        <div class="tabs" role="tablist">
            <button class="tab active" data-tab="logs"><i class="fa-solid fa-cart-shopping"></i> Enrollment &amp; Payments</button>
            <button class="tab" data-tab="students"><i class="fa-solid fa-user-graduate"></i> Students</button>
            <button class="tab" data-tab="courses"><i class="fa-solid fa-book-open"></i> Courses</button>
            <button class="tab" data-tab="lesson-manager"><i class="fa-solid fa-sliders"></i> Lesson Manager</button>
            <button class="tab" data-tab="certs"><i class="fa-solid fa-certificate"></i> Certificates</button>
            <button class="tab" data-tab="handouts"><i class="fa-solid fa-file-lines"></i> Handouts</button>
            <button class="tab" data-tab="schedules"><i class="fa-solid fa-calendar-days"></i> Schedules</button>
        </div>

        <div id="logs" class="panel active">
            <div class="search">
                <input id="searchLogs" placeholder="Search by student email or reference...">
                <button class="btn ghost" onclick="document.getElementById('searchLogs').value=''; filterLogs();">Clear</button>
            </div>
            <table>
                <thead>
                    <tr><th>Student Name</th><th>Email</th><th>Course Enrolled</th><th>Amount Paid</th><th>Paystack Ref</th><th>Status</th><th>Date</th><th>Actions</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($transactions as $t): ?>
                        <tr>
                            <td data-label="Student Name"><?php echo htmlspecialchars($t['full_name'] ?? '—'); ?></td>
                            <td data-label="Email"><?php echo htmlspecialchars($t['email'] ?? '—'); ?></td>
                            <td data-label="Course Enrolled"><?php
                                    $title = $t['course_id'];
                                    foreach ($COURSES as $code => $c) if ($c['db_id'] == $t['course_id']) { $title = $c['title']; break; }
                                    echo htmlspecialchars($title);
                            ?></td>
                            <td data-label="Amount Paid"><?php echo CURRENCY_SYMBOL . number_format((float)$t['amount_paid']); ?></td>
                            <td data-label="Paystack Ref">#<?php echo htmlspecialchars($t['enroll_id']); ?></td>
                            <td data-label="Status"><?php $s = $t['payment_status'];
                                        if ($s === 'completed') echo '<span class="badge success">Completed</span>';
                                        elseif ($s === 'pending') echo '<span class="badge pending">Pending</span>';
                                        else echo '<span class="badge failed">'.htmlspecialchars($s).'</span>';
                            ?></td>
                            <td data-label="Date"><?php echo htmlspecialchars($t['created_at']); ?></td>
                            <td class="actions" data-label="Actions">
                                <a class="btn ghost" href="receipt.php?enroll_id=<?php echo (int)$t['enroll_id']; ?>">View Receipt</a>
                                <?php if ($t['payment_status'] !== 'completed'): ?>
                                    <form method="POST" style="display:inline">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($CSRF_TOKEN); ?>">
                                        <input type="hidden" name="action" value="approve_enrollment">
                                        <input type="hidden" name="enrollment_id" value="<?php echo (int)$t['enroll_id']; ?>">
                                        <button class="btn" type="submit">Approve</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div id="students" class="panel">
            <div class="search">
                <input id="searchStudents" type="text" placeholder="Search students by email or name...">
            </div>

            <table id="studentsTable">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Full Name</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Joined</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($students)): ?>
                        <?php foreach ($students as $s): ?>
                            <?php
                                $uid = (int)$s['id'];
                                $user_courses_progress = [];
                                foreach ($COURSES as $code => $c) {
                                    $db_id  = $c['db_id'];
                                    $done   = $student_progress[$uid][$db_id] ?? 0;
                                    $total  = (int)$c['lessons'];
                                    $percent = $total > 0 ? min(100, round(($done / $total) * 100)) : 0;

                                    $user_courses_progress[] = [
                                        'title'   => $c['title'],
                                        'done'    => $done,
                                        'total'   => $total,
                                        'percent' => $percent,
                                    ];
                                }
                                $json_progress = htmlspecialchars(json_encode($user_courses_progress), ENT_QUOTES, 'UTF-8');
                            ?>
                            <tr>
                                <td data-label="ID"><?php echo $uid; ?></td>
                                <td data-label="Full Name"><strong><?php echo htmlspecialchars($s['full_name'] ?? 'N/A'); ?></strong></td>
                                <td data-label="Email"><?php echo htmlspecialchars($s['email'] ?? ''); ?></td>
                                <td data-label="Role">
                                    <span class="badge <?php echo ($s['role'] === 'admin') ? 'success' : 'pending'; ?>">
                                        <?php echo htmlspecialchars(ucfirst($s['role'])); ?>
                                    </span>
                                </td>
                                <td data-label="Joined">
                                    <?php
                                        echo !empty($s['created_at'])
                                            ? htmlspecialchars(date('M j, Y', strtotime($s['created_at'])))
                                            : '—';
                                    ?>
                                </td>
                                <td class="actions" data-label="Actions">
                                    <form method="POST" style="display:inline-block;">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($CSRF_TOKEN); ?>">
                                        <input type="hidden" name="action" value="change_role">
                                        <input type="hidden" name="user_id" value="<?php echo $uid; ?>">
                                        <select name="new_role" onchange="this.form.submit()" style="padding:6px 10px; width:auto; display:inline-block; font-size:0.85rem;">
                                            <option value="student" <?php echo ($s['role'] === 'student') ? 'selected' : ''; ?>>Student</option>
                                            <option value="admin" <?php echo ($s['role'] === 'admin') ? 'selected' : ''; ?>>Admin</option>
                                        </select>
                                    </form>

                                    <form method="POST" style="display:inline-block; margin-left:4px;" onsubmit="return confirm('Are you sure you want to reset the password for <?php echo htmlspecialchars($s['full_name'], ENT_QUOTES); ?>?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($CSRF_TOKEN); ?>">
                                        <input type="hidden" name="action" value="reset_password">
                                        <input type="hidden" name="user_id" value="<?php echo $uid; ?>">
                                        <button class="btn ghost" type="submit" style="padding:6px 10px; font-size:0.85rem;">
                                            <i class="fa-solid fa-key"></i> Reset
                                        </button>
                                    </form>

                                    <button type="button" class="btn ghost" style="padding:6px 10px; font-size:0.85rem;"
                                            onclick="showProgressModal('<?php echo htmlspecialchars($s['full_name'], ENT_QUOTES); ?>', <?php echo $json_progress; ?>)">
                                        <i class="fa-solid fa-chart-line"></i> Progress
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" style="text-align:center; color:var(--muted); padding:20px;">No registered students found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div id="courses" class="panel">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                <h3>Course Management</h3>
            </div>

            <table id="coursesTable">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Course Title</th>
                        <th>Track</th>
                        <th>Price</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($COURSES)): ?>
                        <?php foreach ($COURSES as $code => $c): ?>
                            <?php
                                $c_id      = (int)($c['db_id'] ?? $c['id']);
                                $is_active = (int)($c['is_active'] ?? 1);
                                $track     = $c['track'] ?? 'General';
                                $price     = number_format((float)($c['price'] ?? 0), 2);

                                $course_json = htmlspecialchars(json_encode([
                                    'id'    => $c_id,
                                    'title' => $c['title'],
                                    'track' => $track,
                                    'price' => $c['price'] ?? 0,
                                ]), ENT_QUOTES, 'UTF-8');
                            ?>
                            <tr>
                                <td data-label="ID"><?php echo $c_id; ?></td>
                                <td data-label="Course Title"><strong><?php echo htmlspecialchars($c['title']); ?></strong></td>
                                <td data-label="Track"><span class="badge pending"><?php echo htmlspecialchars($track); ?></span></td>
                                <td data-label="Price">&#8358;<?php echo $price; ?></td>
                                <td data-label="Status">
                                    <span class="badge <?php echo $is_active ? 'success' : 'danger'; ?>">
                                        <?php echo $is_active ? 'Active' : 'Inactive'; ?>
                                    </span>
                                </td>
                                <td class="actions" data-label="Actions">
                                    <form method="POST" style="display:inline-block;">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($CSRF_TOKEN); ?>">
                                        <input type="hidden" name="action" value="toggle_course_status">
                                        <input type="hidden" name="course_id" value="<?php echo $c_id; ?>">
                                        <input type="hidden" name="current_status" value="<?php echo $is_active; ?>">
                                        <button type="submit" class="btn ghost" style="padding:6px 10px; font-size:0.85rem;">
                                            <i class="fa-solid <?php echo $is_active ? 'fa-eye-slash' : 'fa-eye'; ?>"></i>
                                            <?php echo $is_active ? 'Deactivate' : 'Activate'; ?>
                                        </button>
                                    </form>

                                    <button type="button" class="btn ghost" style="padding:6px 10px; font-size:0.85rem;"
                                            onclick="openEditCourseModal(<?php echo $course_json; ?>)">
                                        <i class="fa-solid fa-pen-to-square"></i> Edit
                                    </button>

                                    <a class="btn ghost" style="padding:6px 10px; font-size:0.85rem;" href="admin_quiz.php?course_code=<?php echo urlencode($code); ?>">
                                        <i class="fa-solid fa-list-check"></i> Quiz
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" style="text-align:center; padding:20px;">No courses found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div id="lesson-manager" class="panel">
            <div class="admin-form-container">
                <h2 style="font-size:1.4rem; font-weight:800; margin-bottom:6px;"><i class="fa-solid fa-screwdriver-wrench" style="color:var(--primary); margin-right:8px;"></i>Admin Lesson Manager</h2>
                <p style="color:var(--muted); font-size:0.9rem; margin-bottom:24px;">Add or update training course lessons directly in the database.</p>

                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($CSRF_TOKEN); ?>">
                    <input type="hidden" name="action" value="save_lesson">
                    <div class="form-group">
                        <label>Select Training Track</label>
                        <select name="course_id" required>
                            <?php foreach ($COURSES as $code => $course): ?>
                                <option value="<?php echo htmlspecialchars($code); ?>"><?php echo htmlspecialchars($course['title']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Lesson ID Code (e.g., wm-07)</label>
                        <input type="text" name="lesson_id" class="form-control" placeholder="wm-07" required>
                    </div>

                    <div class="form-group">
                        <label>Lesson Title</label>
                        <input type="text" name="title" class="form-control" placeholder="Day 7: Advanced Troubleshooting" required>
                    </div>

                    <div class="form-group">
                        <label>Duration (e.g., 35 mins)</label>
                        <input type="text" name="duration" class="form-control" placeholder="35 mins" required>
                    </div>

                    <div class="form-group">
                        <label>Video Embed Link / URL</label>
                        <input type="url" name="video_url" class="form-control" placeholder="https://www.youtube.com/embed/..." required>
                    </div>

                    <button type="submit" class="btn" style="width:100%; justify-content:center; padding:14px;"><i class="fa-solid fa-floppy-disk"></i> Save Lesson to Database</button>
                </form>
            </div>
        </div>

        <div id="certs" class="panel">
            <table>
                <thead><tr><th>Student Name</th><th>Course Title</th><th>Completion %</th><th>Certificate ID</th><th>Issue Date</th><th>Actions</th></tr></thead>
                <tbody>
                <?php if (!empty($certificates)): ?>
                    <?php foreach ($certificates as $cert): ?>
                        <tr>
                            <td data-label="Student Name"><?php echo htmlspecialchars($cert['student_name']); ?></td>
                            <td data-label="Course Title"><?php echo htmlspecialchars($cert['course_title']); ?></td>
                            <td data-label="Completion %"><?php echo $cert['percent']; ?>%</td>
                            <td data-label="Certificate ID"><?php echo htmlspecialchars($cert['cert_id']); ?></td>
                            <td data-label="Issue Date"><?php echo htmlspecialchars($cert['issue_date']); ?></td>
                            <td data-label="Actions">
                                <?php if ($cert['percent'] === 100): ?>
                                    <a class="btn" href="certificate_pdf.php?enroll_id=<?php echo (int)$cert['enroll_id']; ?>">Download</a>
                                    <a class="btn ghost" href="certificate_pdf.php?enroll_id=<?php echo (int)$cert['enroll_id']; ?>">Re-issue</a>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6" style="text-align:center; color:var(--muted); padding:20px;">No completed enrollments yet.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div id="handouts" class="panel">
            <div class="admin-form-container">
                <h2 style="font-size:1.4rem; font-weight:800; margin-bottom:6px;"><i class="fa-solid fa-file-lines" style="color:var(--primary); margin-right:8px;"></i>Module Handouts &amp; Schematics</h2>
                <p style="color:var(--muted); font-size:0.9rem; margin-bottom:12px;">Upload PDFs, images, schematics or archives and attach them to a course.</p>

                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($CSRF_TOKEN); ?>">
                    <input type="hidden" name="action" value="upload_handout">

                    <div class="form-group">
                        <label>Select Course</label>
                        <select name="course_code" required>
                            <?php foreach ($COURSES as $code => $course): ?>
                                <option value="<?php echo htmlspecialchars($code); ?>"><?php echo htmlspecialchars($course['title']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Type</label>
                        <select name="handout_type">
                            <option value="handout">Handout</option>
                            <option value="schematic">Schematic</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Description (optional)</label>
                        <input type="text" name="description" class="form-control" placeholder="Short description">
                    </div>

                    <div class="form-group">
                        <label>File</label>
                        <input type="file" name="handout_file" accept=".pdf,.png,.jpg,.jpeg,.svg,.zip,.doc,.docx,.ppt,.pptx" required>
                    </div>

                    <button type="submit" class="btn" style="width:100%; justify-content:center; padding:14px;"><i class="fa-solid fa-upload"></i> Upload File</button>
                </form>
            </div>

            <div class="card" style="margin-top:18px;">
                <h3 style="margin-bottom:12px;">Existing Handouts &amp; Schematics</h3>
                <?php
                    $handouts = [];
                    $base = __DIR__ . '/assets/handouts';
                    if (is_dir($base)) {
                        foreach (glob($base . '/*', GLOB_ONLYDIR) as $dir) {
                            $courseCode = basename($dir);
                            foreach (glob($dir . '/*') as $f) {
                                if (!is_file($f)) continue;
                                if (preg_match('/\.meta\.json$/', $f)) continue;
                                $rel = substr($f, strlen(__DIR__) + 1);
                                $meta = null;
                                if (file_exists($f . '.meta.json')) {
                                    $meta = @json_decode(file_get_contents($f . '.meta.json'), true);
                                }
                                $handouts[] = [
                                    'course' => $courseCode,
                                    'path' => $rel,
                                    'name' => basename($f),
                                    'size' => filesize($f),
                                    'meta' => $meta,
                                    'uploaded_at' => date('Y-m-d H:i', filemtime($f)),
                                ];
                            }
                        }
                    }
                ?>

                <?php if (empty($handouts)): ?>
                    <div style="color:var(--muted); padding:14px;">No handouts uploaded yet.</div>
                <?php else: ?>
                    <table>
                        <thead><tr><th>Course</th><th>File</th><th>Type</th><th>Uploaded</th><th>Size</th><th>Actions</th></tr></thead>
                        <tbody>
                        <?php foreach ($handouts as $h): ?>
                            <tr>
                                <td data-label="Course"><?php echo htmlspecialchars($h['course']); ?></td>
                                <td data-label="File"><a class="btn ghost" href="<?php echo htmlspecialchars($h['path']); ?>" target="_blank"><?php echo htmlspecialchars($h['name']); ?></a></td>
                                <td data-label="Type"><?php echo htmlspecialchars($h['meta']['type'] ?? '—'); ?></td>
                                <td data-label="Uploaded"><?php echo htmlspecialchars($h['uploaded_at']); ?></td>
                                <td data-label="Size"><?php echo round($h['size'] / 1024, 2); ?> KB</td>
                                <td class="actions" data-label="Actions">
                                    <form method="POST" style="display:inline-block;">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($CSRF_TOKEN); ?>">
                                        <input type="hidden" name="action" value="delete_handout">
                                        <input type="hidden" name="file_path" value="<?php echo htmlspecialchars($h['path']); ?>">
                                        <button class="btn ghost" type="submit" onclick="return confirm('Delete this file?');">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <div id="schedules" class="panel">
            <h3 style="margin-bottom:12px;">Scheduled Physical Trainings</h3>
            <?php if (empty($training_schedules)): ?>
                <div style="color:var(--muted); padding:14px;">No scheduled trainings yet.</div>
            <?php else: ?>
                <table>
                    <thead><tr><th>Student</th><th>Email</th><th>Course</th><th>Scheduled Date</th><th>Notes</th></tr></thead>
                    <tbody>
                    <?php foreach ($training_schedules as $s): ?>
                        <?php
                            $course_title = $s['course_id'];
                            foreach ($COURSES as $code => $c) if ($c['db_id'] == $s['course_id']) { $course_title = $c['title']; break; }
                        ?>
                        <tr>
                            <td data-label="Student"><?php echo htmlspecialchars($s['full_name'] ?? '—'); ?></td>
                            <td data-label="Email"><?php echo htmlspecialchars($s['email'] ?? ''); ?></td>
                            <td data-label="Course"><?php echo htmlspecialchars($course_title); ?></td>
                            <td data-label="Scheduled Date"><?php echo htmlspecialchars($s['scheduled_date']); ?></td>
                            <td data-label="Notes"><?php echo htmlspecialchars($s['notes'] ?? ''); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <script>
        document.querySelectorAll('.tab').forEach(btn => btn.addEventListener('click', e => {
            document.querySelectorAll('.tab').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.panel').forEach(p => p.classList.remove('active'));
            btn.classList.add('active');
            document.getElementById(btn.dataset.tab).classList.add('active');
        }));

        function filterLogs() {
            const q = (document.getElementById('searchLogs').value || '').toLowerCase();
            document.querySelectorAll('#logs tbody tr').forEach(r => {
                r.style.display = (r.innerText.toLowerCase().indexOf(q) !== -1) ? '' : 'none';
            });
        }
        document.getElementById('searchLogs')?.addEventListener('input', filterLogs);

        document.getElementById('searchStudents')?.addEventListener('input', function() {
            const q = this.value.toLowerCase();
            document.querySelectorAll('#studentsTable tbody tr').forEach(r => {
                r.style.display = (r.innerText.toLowerCase().indexOf(q) !== -1) ? '' : 'none';
            });
        });
    </script>

    <div id="progressModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.7); z-index:9999; justify-content:center; align-items:center;">
        <div style="background:var(--card); border:1px solid var(--border); width:100%; max-width:550px; border-radius:16px; padding:24px; box-shadow:0 20px 40px rgba(0,0,0,0.5); position:relative;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                <h3 id="modalStudentName" style="font-size:1.2rem; font-weight:800; color:var(--text);">Student Progress</h3>
                <button type="button" onclick="closeProgressModal()" style="background:none; border:none; color:var(--muted); font-size:1.2rem; cursor:pointer;">&times;</button>
            </div>

            <div id="modalProgressList" style="display:flex; flex-direction:column; gap:16px;"></div>

            <div style="margin-top:24px; text-align:right;">
                <button type="button" class="btn ghost" onclick="closeProgressModal()">Close</button>
            </div>
        </div>
    </div>

    <script>
        function showProgressModal(studentName, progressData) {
            document.getElementById('modalStudentName').innerText = studentName + "'s Course Progress";
            const container = document.getElementById('modalProgressList');
            container.innerHTML = '';

            progressData.forEach(item => {
                const div = document.createElement('div');
                div.style.background = 'var(--card-subtle)';
                div.style.padding = '14px';
                div.style.borderRadius = '10px';
                div.style.border = '1px solid var(--border)';

                div.innerHTML = `
                    <div style="display:flex; justify-content:space-between; margin-bottom:6px; font-size:0.9rem;">
                        <strong>${item.title}</strong>
                        <span style="color:var(--primary); font-weight:700;">${item.done} / ${item.total} Lessons (${item.percent}%)</span>
                    </div>
                    <div style="background:var(--border); height:8px; border-radius:4px; overflow:hidden;">
                        <div style="background:var(--primary); height:100%; width:${item.percent}%; transition:width 0.3s ease;"></div>
                    </div>
                `;
                container.appendChild(div);
            });

            document.getElementById('progressModal').style.display = 'flex';
        }

        function closeProgressModal() {
            document.getElementById('progressModal').style.display = 'none';
        }

        window.addEventListener('click', function(e) {
            const modal = document.getElementById('progressModal');
            if (e.target === modal) closeProgressModal();
        });
    </script>

    <div id="editCourseModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.7); z-index:9999; justify-content:center; align-items:center;">
        <div style="background:var(--card); border:1px solid var(--border); width:100%; max-width:480px; border-radius:16px; padding:24px; box-shadow:0 20px 40px rgba(0,0,0,0.5);">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                <h3 style="font-size:1.2rem; font-weight:800;">Edit Course Details</h3>
                <button type="button" onclick="closeEditCourseModal()" style="background:none; border:none; color:var(--muted); font-size:1.2rem; cursor:pointer;">&times;</button>
            </div>

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($CSRF_TOKEN); ?>">
                <input type="hidden" name="action" value="update_course">
                <input type="hidden" id="edit_course_id" name="course_id" value="">

                <div style="margin-bottom:14px;">
                    <label style="display:block; font-size:0.85rem; margin-bottom:6px; font-weight:600;">Course Title</label>
                    <input type="text" id="edit_course_title" name="title" required style="width:100%; padding:10px; border-radius:8px; border:1px solid var(--border); background:var(--background); color:var(--text);">
                </div>

                <div style="margin-bottom:14px;">
                    <label style="display:block; font-size:0.85rem; margin-bottom:6px; font-weight:600;">Track / Category</label>
                    <input type="text" id="edit_course_track" name="track" required placeholder="e.g. Frontend, Backend, Design" style="width:100%; padding:10px; border-radius:8px; border:1px solid var(--border); background:var(--background); color:var(--text);">
                </div>

                <div style="margin-bottom:20px;">
                    <label style="display:block; font-size:0.85rem; margin-bottom:6px; font-weight:600;">Price (&#8358;)</label>
                    <input type="number" step="0.01" id="edit_course_price" name="price" required style="width:100%; padding:10px; border-radius:8px; border:1px solid var(--border); background:var(--background); color:var(--text);">
                </div>

                <div style="display:flex; justify-content:flex-end; gap:10px;">
                    <button type="button" class="btn ghost" onclick="closeEditCourseModal()">Cancel</button>
                    <button type="submit" class="btn primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openEditCourseModal(course) {
            document.getElementById('edit_course_id').value = course.id;
            document.getElementById('edit_course_title').value = course.title;
            document.getElementById('edit_course_track').value = course.track;
            document.getElementById('edit_course_price').value = course.price;
            document.getElementById('editCourseModal').style.display = 'flex';
        }

        function closeEditCourseModal() {
            document.getElementById('editCourseModal').style.display = 'none';
        }

        window.addEventListener('click', function(e) {
            const courseModal = document.getElementById('editCourseModal');
            if (e.target === courseModal) closeEditCourseModal();
        });
    </script>
</body>
</html>