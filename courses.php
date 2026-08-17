<?php
require_once 'db.php';

// Static per-course extras that only exist on the frontend (icon, image, highlights)
// keyed by the real course_code values in the database.
$COURSE_EXTRAS = [
    'wash_repair' => [
        'icon' => 'fa-soap',
        'images' => [
            'https://images.unsplash.com/photo-1626806787461-102c1bfaaea1?auto=format&fit=crop&w=800&q=80',
            'https://images.unsplash.com/photo-1582735689369-4fe89db7114c?auto=format&fit=crop&w=800&q=80',
        ],
        'highlights' => ['7 Comprehensive Video Modules', 'Circuit & PCB Diagnostics', 'Practical Hands-on Workshop'],
    ],
    'dryer_repair' => [
        'icon' => 'fa-wind',
        'images' => [
            'https://images.unsplash.com/photo-1545173168-9f1947eebb7f?auto=format&fit=crop&w=800&q=80',
            'https://images.unsplash.com/photo-1517646287270-a5a9ca602e5c?auto=format&fit=crop&w=800&q=80',
        ],
        'highlights' => ['Thermal Sensor Testing', 'Airflow & Vent Clearing', 'Drive Belt Replacements'],
    ],
    'cooker_repair' => [
        'icon' => 'fa-fire-burner',
        'images' => [
            'https://images.unsplash.com/photo-1556911220-e15b29be8c8f?auto=format&fit=crop&w=800&q=80',
            'https://images.unsplash.com/photo-1585659722983-3a675dabf23d?auto=format&fit=crop&w=800&q=80',
        ],
        'highlights' => ['Ceramic Hob Wiring Guide', 'Oven Element Replacement', 'Thermostat Calibration'],
    ],
];

try {
    $stmt = $conn->query("SELECT course_code, title, summary, price, is_active FROM courses WHERE is_active = 1 ORDER BY id ASC");
    $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Database Error: " . htmlspecialchars($e->getMessage()));
}

if (!defined('CURRENCY_SYMBOL')) {
    define('CURRENCY_SYMBOL', '£');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Your Courses — Johnnyfingers</title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Oswald:wght@500;600;700&family=Work+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="assets/theme.css">
  <link rel="stylesheet" href="styles.css">

  <style>
    .directory-section {
      position: relative;
      padding: 60px 0 100px;
      min-height: calc(100vh - 120px);
      background: linear-gradient(135deg, rgba(15, 23, 42, 0.95) 0%, rgba(10, 15, 29, 0.92) 100%);
    }
    .directory-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
      gap: 32px;
      margin-top: 40px;
    }
    .course-card {
      background: rgba(30, 41, 59, 0.7);
      border: 1px solid rgba(255, 255, 255, 0.1);
      border-radius: 20px;
      overflow: hidden;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      transition: all 0.3s ease;
      text-decoration: none;
      color: inherit;
      box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
    }
    .course-card:hover {
      transform: translateY(-8px);
      border-color: var(--orange, #f97316);
      box-shadow: 0 20px 40px rgba(249, 115, 22, 0.2);
    }
    .card-img-wrapper { position: relative; width: 100%; height: 180px; overflow: hidden; }
    .card-img-wrapper img { width: 100%; height: 100%; object-fit: cover; }
    .card-img-overlay {
      position: absolute; inset: 0;
      background: linear-gradient(to top, rgba(30, 41, 59, 1) 0%, rgba(30, 41, 59, 0.2) 70%);
    }
    .course-card-icon {
      position: absolute; top: 15px; right: 15px;
      background: rgba(15, 23, 42, 0.8);
      width: 48px; height: 48px; border-radius: 12px;
      display: flex; align-items: center; justify-content: center;
      font-size: 20px; color: var(--orange, #f97316);
      border: 1px solid rgba(255, 255, 255, 0.15);
    }
    .card-body { padding: 24px 28px 28px; display: flex; flex-direction: column; flex-grow: 1; justify-content: space-between; }
    .course-card h2 { font-size: 22px; margin: 8px 0; color: #fff; }
    .course-card p { color: var(--text-mute, #94a3b8); font-size: 14px; line-height: 1.6; margin-bottom: 16px; }
    .price-tag { color: var(--orange, #f97316); font-weight: 700; font-size: 15px; margin-bottom: 14px; }
    .preview-list { list-style: none; padding: 0; margin: 0 0 16px; }
    .preview-list li { font-size: 13px; color: #e2e8f0; margin-bottom: 6px; display: flex; align-items: center; gap: 8px; }
    .preview-list i { color: #2ecc71; font-size: 11px; }

    @media (max-width: 600px) {
      .directory-section { padding: 40px 0 60px; }
      .directory-grid { grid-template-columns: 1fr; gap: 20px; }
    }
  </style>
</head>
<body>

<header class="site-header">
  <div class="container nav-row">
    <a href="Home.php" class="brand-logo" style="display: inline-flex; align-items: center; text-decoration: none;">
      <img src="images/logo.png" alt="Johnny Fingers Logo" style="height: 45px; width: auto; object-fit: contain;">
    </a>
  </div>
</header>

<main class="directory-section">
  <div class="container">
    <div style="text-align: center; max-width: 600px; margin: 0 auto;">
      <span class="eyebrow-pill mono"><i class="fa-solid fa-graduation-cap"></i> Course Portal</span>
      <h1 class="display" style="font-size: 38px; margin: 14px 0; color: #fff;">Select Your Training Track</h1>
      <p style="color: var(--text-mute); font-size: 15px;">Click any track to view the full curriculum and pricing.</p>
    </div>

    <div class="directory-grid">
      <?php foreach ($courses as $c):
        $code   = $c['course_code'];
        $extras = $COURSE_EXTRAS[$code] ?? ['icon' => 'fa-screwdriver-wrench', 'images' => [], 'highlights' => []];
        $img    = $extras['images'][0] ?? 'https://images.unsplash.com/photo-1581092160607-ee22621dd758?auto=format&fit=crop&w=800&q=80';
      ?>
        <a href="course.php?code=<?php echo urlencode($code); ?>" class="course-card">
          <div class="card-img-wrapper">
            <div class="course-card-icon"><i class="fa-solid <?php echo htmlspecialchars($extras['icon']); ?>"></i></div>
            <img src="<?php echo htmlspecialchars($img); ?>" alt="<?php echo htmlspecialchars($c['title']); ?>">
            <div class="card-img-overlay"></div>
          </div>
          <div class="card-body">
            <div>
              <h2 class="display"><?php echo htmlspecialchars($c['title']); ?></h2>
              <p><?php echo htmlspecialchars($c['summary'] ?? ''); ?></p>
              <div class="price-tag"><?php echo CURRENCY_SYMBOL . number_format((float)$c['price'], 2); ?></div>
              <ul class="preview-list">
                <?php foreach ($extras['highlights'] as $h): ?>
                  <li><i class="fa-solid fa-check"></i> <?php echo htmlspecialchars($h); ?></li>
                <?php endforeach; ?>
              </ul>
            </div>
            <div class="btn btn-secondary" style="width: 100%; justify-content: center;">
              View Track <i class="fa-solid fa-arrow-right"></i>
            </div>
          </div>
        </a>
      <?php endforeach; ?>

      <?php if (empty($courses)): ?>
        <p style="color: var(--text-mute);">No training tracks are available right now — please check back soon.</p>
      <?php endif; ?>
    </div>
  </div>
</main>

</body>
</html>
