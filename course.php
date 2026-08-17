<?php
require_once 'db.php';

$course_code = $_GET['code'] ?? '';

try {
    $stmt = $conn->prepare("SELECT course_code, title, track, summary, price, is_active FROM courses WHERE course_code = :code LIMIT 1");
    $stmt->execute([':code' => $course_code]);
    $course = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Database Error: " . htmlspecialchars($e->getMessage()));
}

if (!$course) {
    http_response_code(404);
    die("Course track not found.");
}

if (!defined('CURRENCY_SYMBOL')) {
    define('CURRENCY_SYMBOL', '£');
}

// Syllabus/curriculum content isn't stored in the DB — it's presentation-only,
// so it stays here as static content, keyed by the same course_code the DB uses.
$SYLLABUS = [
    'wash_repair' => [
        'hero_image' => 'https://images.unsplash.com/photo-1626806787461-102c1bfaaea1?auto=format&fit=crop&w=1600&q=80',
        'lead' => 'From direct-drive inverter motors and door interlock safety circuits to main PCB triac soldering and mechanical drum bearing replacements.',
        'phases' => [
            ['title' => 'Phase 1: Online Video Theory & Fault Logic', 'tag' => 'Days 1–4', 'topics' => [
                'Water inlet solenoids, mechanical & analog pressure switches, drain pump diagnostics',
                'Universal vs. BLDC direct-drive motors, inverter board IPM diagnostics',
                'PTC door locks, heating element resistance testing, anti-flood float switches',
                'Main control PCB triacs, manufacturer error codes, harness continuity',
            ]],
            ['title' => 'Phase 2: Workshop Practical Drills', 'tag' => 'Days 5–6', 'topics' => [
                'Outer tub splitting, bearing extraction, seal fitting',
                'Live fault injection & repair on running machines',
                'Board soldering, door gasket & hose replacement',
            ]],
            ['title' => 'Phase 3: Examination & Certification', 'tag' => 'Day 7', 'topics' => [
                'Written Exam (40%): Schematics, water logic, error codes',
                'Practical Exam (60%): Diagnose & repair 2 live faulty machines within 45 mins',
            ]],
        ],
    ],
    'dryer_repair' => [
        'hero_image' => 'https://images.unsplash.com/photo-1545173168-9f1947eebb7f?auto=format&fit=crop&w=1600&q=80',
        'lead' => 'Master vented, condenser, and heat-pump dryer systems — dual heating elements, moisture sensor logic, and drive motors.',
        'phases' => [
            ['title' => 'Phase 1: Online Video Theory & Fault Logic', 'tag' => 'Days 1–4', 'topics' => [
                'Heat pump vs vented vs condenser mechanics, airflow dynamics',
                'Dual-element heating assemblies, high-limit & cycling thermostats, gas ignition systems',
                'Drive motor windings, belt routing, moisture sensor bars',
                'Control board relay diagnostics, error codes, voltage/resistance testing',
            ]],
            ['title' => 'Phase 2: Workshop Practical Drills', 'tag' => 'Days 5–6', 'topics' => [
                'Full teardown & rebuild, belt & roller replacement',
                'Live fault tracing on heating elements & thermal cutoffs',
            ]],
            ['title' => 'Phase 3: Examination & Certification', 'tag' => 'Day 7', 'topics' => [
                'Written Exam (40%): Thermal safety standards, schematics, error codes',
                'Practical Exam (60%): Diagnose & fix 2 live faulty dryers within 45 mins',
            ]],
        ],
    ],
    'cooker_repair' => [
        'hero_image' => 'https://images.unsplash.com/photo-1556911220-e15b29be8c8f?auto=format&fit=crop&w=1600&q=80',
        'lead' => 'Master ceramic hobs, induction inverter modules, and fan-forced oven systems, plus high-voltage electrical safety.',
        'phases' => [
            ['title' => 'Phase 1: Online Video Theory & High-Voltage Logic', 'tag' => 'Days 1–4', 'topics' => [
                'Mains supply configurations, isolation testing, earth continuity',
                'Bake/broil/fan-forced element testing, thermostats vs NTC sensors',
                'Ceramic & induction hob diagnostics, inverter boards, pan sensors',
                'Digital timers, control boards, touch-control troubleshooting',
            ]],
            ['title' => 'Phase 2: Workshop Practical Drills', 'tag' => 'Days 5–6', 'topics' => [
                'Element & fan motor replacement, oven temperature calibration',
                'Live induction/ceramic hob troubleshooting under load',
            ]],
            ['title' => 'Phase 3: Examination & Certification', 'tag' => 'Day 7', 'topics' => [
                'Written Exam (40%): Earth continuity, wire sizing, isolation protocols',
                'Practical Exam (60%): Isolate & fix live induction hob & oven faults within 45 mins',
            ]],
        ],
    ],
];

$syllabus = $SYLLABUS[$course_code] ?? [
    'hero_image' => 'https://images.unsplash.com/photo-1581092160607-ee22621dd758?auto=format&fit=crop&w=1600&q=80',
    'lead' => $course['summary'] ?? '',
    'phases' => [],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo htmlspecialchars($course['title']); ?> — Johnnyfingers Academy</title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Oswald:wght@500;600;700&family=Work+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="assets/theme.css">
  <link rel="stylesheet" href="styles.css">

  <style>
    .course-hero {
      padding: 100px 0 60px;
      background: linear-gradient(180deg, rgba(15, 23, 42, 0.9) 0%, rgba(15, 23, 42, 1) 100%),
                  url('<?php echo htmlspecialchars($syllabus['hero_image']); ?>') center/cover no-repeat;
      border-bottom: 1px solid rgba(255, 255, 255, 0.08);
    }
    .curriculum-timeline { display: flex; flex-direction: column; gap: 24px; margin-top: 30px; }
    .day-block {
      background: rgba(30, 41, 59, 0.5);
      border: 1px solid rgba(255, 255, 255, 0.08);
      border-radius: 12px;
      padding: 24px;
    }
    .day-header {
      display: flex; align-items: center; justify-content: space-between;
      margin-bottom: 16px; border-bottom: 1px solid rgba(255, 255, 255, 0.05); padding-bottom: 12px;
      flex-wrap: wrap; gap: 10px;
    }
    .day-tag {
      background: rgba(249, 115, 22, 0.15); color: #f97316;
      border: 1px solid rgba(249, 115, 22, 0.3);
      padding: 4px 12px; border-radius: 20px; font-size: 13px; white-space: nowrap;
    }
    .topic-list { list-style: none; padding: 0; margin: 0; display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 12px; }
    .topic-list li { color: #cbd5e1; font-size: 14px; line-height: 1.6; display: flex; align-items: flex-start; gap: 10px; }
    .topic-list li i { color: #38bdf8; margin-top: 4px; }
    .sticky-pricing {
      position: sticky; top: 100px;
      background: rgba(30, 41, 59, 0.85);
      border: 1px solid rgba(249, 115, 22, 0.3);
      border-radius: 16px; padding: 30px;
    }
    .layout-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 40px; margin-top: 40px; }

    @media (max-width: 900px) {
      .layout-grid { grid-template-columns: 1fr; }
      .sticky-pricing { position: static; }
    }
    @media (max-width: 600px) {
      .course-hero { padding: 80px 0 40px; }
      .course-hero h1 { font-size: 2rem !important; }
      .day-block { padding: 16px; }
    }
  </style>
</head>
<body>

<header class="site-header">
  <div class="container nav-row">
    <a href="Home.php" class="brand-logo" style="display: inline-flex; align-items: center; text-decoration: none;">
      <img src="images/logo.png" alt="Johnny Fingers Logo" style="height: 45px; width: auto; object-fit: contain;">
    </a>
    <nav class="main-nav">
      <ul>
        <li><a href="courses.php">Courses</a></li>
        <li><a href="login.html">Student Login</a></li>
      </ul>
    </nav>
    <div class="nav-actions">
      <a href="register.php" class="nav-cta mono">Enroll Now <i class="fa-solid fa-arrow-right"></i></a>
      <button class="hamburger" id="menuToggle" aria-label="Menu"><i class="fa-solid fa-bars"></i></button>
    </div>
  </div>
  <div class="mobile-menu mono" id="mobileMenu">
    <a href="Home.php">Home</a>
    <a href="courses.php">Courses</a>
    <a href="login.php">Student Login</a>
    <a href="register.php" style="color: #f97316;">Enroll Now</a>
  </div>
</header>

<section class="course-hero">
  <div class="container">
    <span class="eyebrow-pill mono"><i class="fa-solid fa-screwdriver-wrench"></i> Track <?php echo htmlspecialchars(strtoupper($course_code)); ?></span>
    <h1 class="display" style="font-size: 2.8rem; margin-top: 10px;"><?php echo htmlspecialchars($course['title']); ?></h1>
    <p class="lead" style="max-width: 750px;"><?php echo htmlspecialchars($syllabus['lead']); ?></p>
  </div>
</section>

<section class="section">
  <div class="container">
    <div class="layout-grid">
      <div>
        <span class="section-eyebrow">Comprehensive Syllabus</span>
        <h2>7-Day Training Curriculum</h2>

        <div class="curriculum-timeline">
          <?php foreach ($syllabus['phases'] as $phase): ?>
            <div class="day-block">
              <div class="day-header">
                <h3 style="margin: 0; color: #fff;"><?php echo htmlspecialchars($phase['title']); ?></h3>
                <span class="day-tag mono"><?php echo htmlspecialchars($phase['tag']); ?></span>
              </div>
              <ul class="topic-list">
                <?php foreach ($phase['topics'] as $topic): ?>
                  <li><i class="fa-solid fa-caret-right"></i> <?php echo htmlspecialchars($topic); ?></li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div>
        <div class="sticky-pricing">
          <span class="mono" style="color: #94a3b8; font-size: 13px;">INTENSIVE TRACK</span>
          <h3 class="display" style="font-size: 2rem; margin: 5px 0;"><?php echo htmlspecialchars($course['title']); ?></h3>
          <div style="font-size: 2.5rem; color: #f97316; font-weight: 700; margin: 15px 0;" class="display">
            <?php echo CURRENCY_SYMBOL . number_format((float)$course['price'], 2); ?>
          </div>

          <ul style="list-style: none; padding: 0; margin: 0 0 25px; line-height: 2; color: #cbd5e1; font-size: 14px;">
            <li><i class="fa-solid fa-check" style="color: #f97316; margin-right: 8px;"></i> 4 Days Online Access</li>
            <li><i class="fa-solid fa-check" style="color: #f97316; margin-right: 8px;"></i> 2 Days Hands-On Workshop</li>
            <li><i class="fa-solid fa-check" style="color: #f97316; margin-right: 8px;"></i> Official Digital Diploma</li>
          </ul>

          <?php if ((int)$course['is_active'] === 1): ?>
            <a href="register.html" class="btn btn-primary" style="width: 100%; text-align: center; justify-content: center;">
              Enroll in Track <i class="fa-solid fa-arrow-right"></i>
            </a>
          <?php else: ?>
            <div class="btn btn-secondary" style="width: 100%; text-align: center; justify-content: center; opacity: 0.6;">
              Currently Unavailable
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</section>

<footer class="site-footer">
  <div class="container">
    <div class="footer-bottom">© <?php echo date('Y'); ?> Johnnyfingers Training Academy. All rights reserved.</div>
  </div>
</footer>

<script>
  const menuToggle = document.getElementById('menuToggle');
  const mobileMenu = document.getElementById('mobileMenu');
  menuToggle?.addEventListener('click', () => mobileMenu.classList.toggle('open'));
</script>

</body>
</html>
