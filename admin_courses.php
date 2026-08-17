<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once 'db.php';
require_once 'courses_config.php';

if (strtolower($_SESSION['role'] ?? '') !== 'admin') {
  header('Location: login.php'); exit();
}

// Same CSRF token pattern as admin.php — every admin form must carry one.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$CSRF_TOKEN = $_SESSION['csrf_token'];

// Load overrides from DB
$stmt = $conn->prepare("SELECT * FROM course_meta");
try { $stmt->execute(); $metaRows = $stmt->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e) { $metaRows = []; }
$meta = [];
foreach ($metaRows as $r) { $meta[$r['course_code']] = $r; }

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Admin — Courses</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="assets/theme.css">
  <style>.card-jf{padding:16px;margin-bottom:14px}</style>
</head>
<body style="background:var(--jf-bg);color:var(--jf-text);">
  <div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h1 class="h4">Course Tracks</h1>
      <a class="btn btn-jf-primary" href="admin_quiz.php">Manage Quizzes</a>
    </div>

    <?php foreach ($COURSES as $code => $c):
      $m = $meta[$code] ?? null;
      $title = $m['title_override'] ?? $c['title'] ?? ($c['name'] ?? $code);
      $price = $m['price_override'] ?? ($c['price_eur'] ?? '0');
      $active = isset($m['active']) ? (bool)$m['active'] : true;
    ?>
      <div class="card card-jf">
        <div class="row align-items-center">
          <div class="col-md-8">
            <h5 style="margin:0"><?php echo htmlspecialchars($title); ?> <small class="text-muted">(<?php echo htmlspecialchars($code); ?>)</small></h5>
            <div class="text-muted" style="font-size:13px"><?php echo htmlspecialchars($c['summary'] ?? $c['description'] ?? ''); ?></div>
          </div>
          <div class="col-md-4 text-end">
            <form method="POST" action="save_course_meta.php" class="d-inline-block">
              <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($CSRF_TOKEN); ?>">
              <input type="hidden" name="course_code" value="<?php echo htmlspecialchars($code); ?>">
              <input type="hidden" name="redirect" value="admin_courses.php">
              <div class="mb-2">
                <label class="form-label visually-hidden">Title</label>
                <input name="title" value="<?php echo htmlspecialchars($title); ?>" class="form-control form-control-sm" />
              </div>
              <div class="mb-2">
                <label class="form-label visually-hidden">Price</label>
                <input name="price" value="<?php echo htmlspecialchars($price); ?>" class="form-control form-control-sm" />
              </div>
              <div class="form-check form-switch mb-2">
                <input class="form-check-input" type="checkbox" name="active" id="active-<?php echo htmlspecialchars($code); ?>" <?php echo $active ? 'checked' : ''; ?> />
                <label class="form-check-label" for="active-<?php echo htmlspecialchars($code); ?>">Active</label>
              </div>
              <div>
                <button type="submit" class="btn btn-sm btn-jf-primary">Save</button>
              </div>
            </form>
          </div>
        </div>
      </div>
    <?php endforeach; ?>

  </div>
  
</body>
</html>
