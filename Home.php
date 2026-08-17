<?php
require_once 'db.php';

// Same per-course presentation extras used on courses.php/course.php,
// keyed by the real course_code values in the database.
$COURSE_EXTRAS = [
    'wash_repair' => [
        'icon' => 'fa-soap',
        'image' => 'https://images.unsplash.com/photo-1626806787461-102c1bfaaea1?auto=format&fit=crop&w=800&q=80',
        'tagline' => 'Direct-drive motors, PCB logic & water hydraulics',
        'points' => [
            '4 Days: Inverter drive schematics & code reading',
            '2 Days: Bearing replacement & solenoid valve testing',
            '1 Day: Practical assessment & digital certificate',
        ],
    ],
    'dryer_repair' => [
        'icon' => 'fa-wind',
        'image' => 'https://images.unsplash.com/photo-1545173168-9f1947eebb7f?auto=format&fit=crop&w=800&q=80',
        'tagline' => 'Thermal cutoffs, heat pumps & belt drives',
        'points' => [
            '4 Days: Heating coil continuity & thermostat logic',
            '2 Days: Airflow obstruction & drum roller alignment',
            '1 Day: Diagnostic assessment & certification',
        ],
    ],
    'cooker_repair' => [
        'icon' => 'fa-fire-burner',
        'image' => 'https://images.unsplash.com/photo-1556911220-e15b29be8c8f?auto=format&fit=crop&w=800&q=80',
        'tagline' => 'Ceramic hobs, induction modules & oven elements',
        'points' => [
            '4 Days: High-voltage safety, timers & switches',
            '2 Days: Fan motor replacement & thermostat calibration',
            '1 Day: Practical examination & credentialing',
        ],
    ],
];

try {
    $stmt = $conn->query("SELECT course_code, title, price, is_active FROM courses WHERE is_active = 1 ORDER BY id ASC");
    $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $courses = []; // homepage should still render even if the DB is briefly unavailable
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
  <title>Johnnyfingers — Professional Appliance Repair Academy</title>

  <!-- External Fonts & Icons -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Oswald:wght@500;600;700&family=Work+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

  <!-- Bootstrap CSS (integrity hash removed — the original was malformed and would
       have caused browsers enforcing SRI to block the stylesheet entirely) -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <!-- Shared theme -->
  <link rel="stylesheet" href="assets/theme.css">
  <!-- Custom Stylesheet -->
  <link rel="stylesheet" href="styles.css">

  <style>
    .testimonial-card {
      background: rgba(30, 41, 59, 0.6);
      border: 1px solid rgba(255, 255, 255, 0.08);
      border-radius: 16px;
      padding: 30px;
      backdrop-filter: blur(10px);
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      transition: transform 0.3s ease, border-color 0.3s ease;
    }
    .testimonial-card:hover {
      transform: translateY(-5px);
      border-color: rgba(249, 115, 22, 0.4);
    }
    .testimonial-user {
      display: flex;
      align-items: center;
      gap: 14px;
      margin-top: 20px;
    }
    .testimonial-avatar {
      width: 48px;
      height: 48px;
      border-radius: 50%;
      object-fit: cover;
      border: 2px solid #f97316;
    }
    .stars {
      color: #f59e0b;
      margin-bottom: 12px;
      font-size: 14px;
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
        <li><a href="login.php">Student Login</a></li>
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

<!-- Hero Section with Technical Diagnostic Carousel -->
<section class="hero" id="home">
  <div class="hero-carousel">
    <div class="carousel-slide active" style="background-image: url('https://images.unsplash.com/photo-1581092160607-ee22621dd758?auto=format&fit=crop&w=1600&q=80');"></div>
    <div class="carousel-slide" style="background-image: url('https://images.unsplash.com/photo-1626806787461-102c1bfaaea1?auto=format&fit=crop&w=1600&q=80');"></div>
    <div class="carousel-slide" style="background-image: url('https://images.unsplash.com/photo-1581092335397-9583fe92d232?auto=format&fit=crop&w=1600&q=80');"></div>
    <div class="carousel-slide" style="background-image: url('https://images.unsplash.com/photo-1556911220-e15b29be8c8f?auto=format&fit=crop&w=1600&q=80');"></div>
  </div>

  <div class="hero-overlay"></div>

  <svg class="hero-grid" viewBox="0 0 100 100" preserveAspectRatio="none">
    <defs>
      <pattern id="jf-grid" width="6" height="6" patternUnits="userSpaceOnUse">
        <path d="M 6 0 L 0 0 0 6" fill="none" stroke="#F3F0E8" stroke-width="0.15"></path>
      </pattern>
    </defs>
    <rect width="100" height="100" fill="url(#jf-grid)"></rect>
  </svg>

  <div class="container">
    <div class="hero-content">
      <span class="eyebrow-pill mono"><i class="fa-solid fa-microchip"></i> Master Field Engineering</span>
      <h1 class="display">7-Day Technical <span class="accent">Appliance Repair</span></h1>
      <p class="lead">From PCB circuit diagnostics to drum bearing replacements. Master washing machines, dryers, and electric hobs through guided video modules and hands-on bench testing.</p>

      <div class="hero-actions">
        <a href="#courses" class="btn btn-primary">View Specialized Tracks <i class="fa-solid fa-chevron-right"></i></a>
        <a href="#how" class="btn btn-outline">Course Blueprint</a>
      </div>

      <div class="stat-row mono">
        <div><span class="num display">4 Days</span>Online Theory & Video</div>
        <div><span class="num display">2 Days</span>Workshop Diagnostics</div>
        <div><span class="num display">1 Day</span>Exam & Certification</div>
      </div>
    </div>
  </div>

  <div class="carousel-dots" id="carouselDots">
    <span class="dot active" data-slide="0"></span>
    <span class="dot" data-slide="1"></span>
    <span class="dot" data-slide="2"></span>
    <span class="dot" data-slide="3"></span>
  </div>
</section>

<!-- Feature Matrix Strip -->
<section class="feature-strip">
  <div class="container">
    <div class="feature-item">
      <i class="fa-solid fa-laptop-code"></i>
      <div><strong>Interactive Theory</strong><span>Schematics & fault logic</span></div>
    </div>
    <div class="feature-item">
      <i class="fa-solid fa-screwdriver-wrench"></i>
      <div><strong>Bench Practicals</strong><span>Live fault injection drills</span></div>
    </div>
    <div class="feature-item">
      <i class="fa-solid fa-gauge-high"></i>
      <div><strong>Component Testing</strong><span>Multimeters & load testing</span></div>
    </div>
    <div class="feature-item">
      <i class="fa-solid fa-certificate"></i>
      <div><strong>Industry Credential</strong><span>Instant digital verification</span></div>
    </div>
  </div>
</section>

<!-- Specialized Training Tracks Section — now pulled live from the database
     instead of 3 hardcoded cards with stale WM-01/DR-02/EC-03 codes and prices -->
<section class="section" id="courses">
  <div class="container">
    <div class="section-head">
      <span class="section-eyebrow">Professional Curricula</span>
      <h2>Specialized Field Repair Tracks</h2>
      <p>Enroll in a focused 7-day technical course or take all three to build your own field repair service.</p>
    </div>

    <div class="courses-wrap">
      <div class="grid-3">
        <?php foreach ($courses as $c):
          $code   = $c['course_code'];
          $extras = $COURSE_EXTRAS[$code] ?? [
              'icon' => 'fa-screwdriver-wrench',
              'image' => 'https://images.unsplash.com/photo-1581092160607-ee22621dd758?auto=format&fit=crop&w=800&q=80',
              'tagline' => '',
              'points' => [],
          ];
        ?>
          <div class="course-card">
            <img class="course-img" src="<?php echo htmlspecialchars($extras['image']); ?>" alt="<?php echo htmlspecialchars($c['title']); ?>">
            <div class="course-top">
              <span class="course-icon"><i class="fa-solid <?php echo htmlspecialchars($extras['icon']); ?>"></i></span>
              <span class="course-code mono"><?php echo htmlspecialchars(strtoupper($code)); ?></span>
            </div>
            <h3><?php echo htmlspecialchars($c['title']); ?></h3>
            <p class="tagline"><?php echo htmlspecialchars($extras['tagline']); ?></p>
            <ul class="course-points">
              <?php foreach ($extras['points'] as $point): ?>
                <li><i class="fa-solid fa-circle-check"></i> <?php echo htmlspecialchars($point); ?></li>
              <?php endforeach; ?>
            </ul>
            <div class="course-meta"><span>7 Days Total</span><span>Hybrid Training</span></div>
            <div class="course-footer">
              <span class="course-price display"><?php echo CURRENCY_SYMBOL . number_format((float)$c['price'], 2); ?></span>
              <a href="course.php?code=<?php echo urlencode($code); ?>" class="course-enrol">View Track <i class="fa-solid fa-chevron-right"></i></a>
            </div>
          </div>
        <?php endforeach; ?>

        <?php if (empty($courses)): ?>
          <p style="color: var(--text-mute);">Training tracks are being updated — please check back shortly.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>

<!-- Course Structure Section -->
<section class="section how-section" id="how">
  <div class="container">
    <div class="section-head">
      <span class="section-eyebrow blue">Course Structure</span>
      <h2>How the 7 Days Work</h2>
    </div>
    <div class="steps-grid">
      <div class="step">
        <div class="step-num display">01–04</div>
        <h3>Days 1–4: Online Video Modules</h3>
        <p>Learn schematic reading, component resistance testing, and error-code diagnostics with high-definition video walkthroughs.</p>
      </div>
      <div class="step">
        <div class="step-num display">05–06</div>
        <h3>Days 5–6: Hands-On Workshop</h3>
        <p>Practice live fault troubleshooting on real machines under expert supervision at our technical workshop facility.</p>
      </div>
      <div class="step">
        <div class="step-num display">07</div>
        <h3>Day 7: Assessment & Exam</h3>
        <p>Demonstrate your troubleshooting speed and safety compliance in both written and practical examinations.</p>
      </div>
      <div class="step">
        <div class="step-num display"><i class="fa-solid fa-award"></i></div>
        <h3>Official Certification</h3>
        <p>Instantly generate and download your verifiable diploma to present to employers or field clients.</p>
      </div>
    </div>
  </div>
</section>

<!-- Student Testimonials Section -->
<section class="section" id="testimonials" style="background: rgba(15, 23, 42, 0.6); border-top: 1px solid rgba(255, 255, 255, 0.05); border-bottom: 1px solid rgba(255, 255, 255, 0.05);">
  <div class="container">
    <div class="section-head">
      <span class="section-eyebrow">Verified Graduate Reviews</span>
      <h2>What Our Students Say</h2>
      <p>Over 450+ field technicians have launched their repair careers through our intensive 7-day program.</p>
    </div>

    <div class="grid-3" style="margin-top: 40px;">

      <div class="testimonial-card">
        <div>
          <div class="stars">
            <i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i>
          </div>
          <p style="color: #cbd5e1; font-size: 15px; line-height: 1.6;">"The PCB diagnostic lessons in Days 1 to 4 completely demystified control board failures for me. By Day 6 in the Ikeja workshop, I repaired my first front-load washer independently."</p>
        </div>
        <div class="testimonial-user">
          <img src="https://images.unsplash.com/photo-1534528741775-53994a69daeb?auto=format&fit=crop&w=200&q=80" alt="Student Graduate" class="testimonial-avatar">
          <div>
            <strong style="display: block; color: #fff; font-size: 15px;">Emeka O.</strong>
            <span style="color: #94a3b8; font-size: 13px;">Washing Machine Specialist</span>
          </div>
        </div>
      </div>

      <div class="testimonial-card">
        <div>
          <div class="stars">
            <i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i>
          </div>
          <p style="color: #cbd5e1; font-size: 15px; line-height: 1.6;">"The instructor-led hands-on bench drills are second to none. Learning how to properly test thermal cutoffs and heat exchangers saved me months of trial and error."</p>
        </div>
        <div class="testimonial-user">
          <img src="https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?auto=format&fit=crop&w=200&q=80" alt="Student Graduate" class="testimonial-avatar">
          <div>
            <strong style="display: block; color: #fff; font-size: 15px;">Tunde A.</strong>
            <span style="color: #94a3b8; font-size: 13px;">Dryer & Heat Pump Tech</span>
          </div>
        </div>
      </div>

      <div class="testimonial-card">
        <div>
          <div class="stars">
            <i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i>
          </div>
          <p style="color: #cbd5e1; font-size: 15px; line-height: 1.6;">"I was able to start taking local service calls within two weeks of earning my certificate. The practical knowledge given on induction cookers alone paid back my course fee."</p>
        </div>
        <div class="testimonial-user">
          <img src="https://images.unsplash.com/photo-1573496359142-b8d87734a5a2?auto=format&fit=crop&w=200&q=80" alt="Student Graduate" class="testimonial-avatar">
          <div>
            <strong style="display: block; color: #fff; font-size: 15px;">Blessing N.</strong>
            <span style="color: #94a3b8; font-size: 13px;">Independent Repair Engineer</span>
          </div>
        </div>
      </div>

    </div>
  </div>
</section>

<!-- Call to Action Banner -->
<section class="cta-band">
  <div class="container">
    <div>
      <h2 class="display">Ready to master appliance repair?</h2>
      <p>Reserve your workshop bench slot for our upcoming Lagos training cohort.</p>
    </div>
    <a href="register.php" class="btn">Enroll in Next Cohort <i class="fa-solid fa-arrow-right"></i></a>
  </div>
</section>

<!-- Site Footer -->
<footer class="site-footer" id="contact">
  <div class="container">
    <div class="footer-grid">
      <div>
        <p>Intensive 7-day appliance repair academy. Combining online video theory, physical workshop drills, and digital accreditation.</p>
      </div>
      <div>
        <h4>Courses</h4>
        <ul>
          <?php foreach ($courses as $c): ?>
            <li><a href="course.php?code=<?php echo urlencode($c['course_code']); ?>"><?php echo htmlspecialchars($c['title']); ?></a></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <div>
        <h4>Quick Links</h4>
        <ul>
         <li><a href="Home.php">Home</a></li>
          <li><a href="courses.php">Course Catalog</a></li>
          <li><a href="#testimonials">Graduate Reviews</a></li>
        </ul>
      </div>
      <div>
        <h4>Contact</h4>
        <ul>
          <li><a href="tel:+447424305454"><i class="fa-solid fa-phone"></i>+44 7424 305454</a></li>
          <li><a href="mailto:learn@johnnyfingers.ng"><i class="fa-solid fa-envelope"></i> learn@johnnyfingers.ng</a></li>
          <li><i class="fa-solid fa-location-dot"></i> 171 Nuthall Rd, Nottingham NG8 6DJ, United Kingdom</li>
        </ul>
      </div>
    </div>
  </div>
  <div class="footer-bottom">© <?php echo date('Y'); ?> Johnnyfingers Training Academy. All rights reserved.</div>
</footer>

<script>
  const menuToggle = document.getElementById('menuToggle');
  const mobileMenu = document.getElementById('mobileMenu');
  menuToggle.addEventListener('click', () => {
    mobileMenu.classList.toggle('open');
  });
  mobileMenu.querySelectorAll('a').forEach(a => {
    a.addEventListener('click', () => mobileMenu.classList.remove('open'));
  });

  const slides = document.querySelectorAll('.carousel-slide');
  const dots = document.querySelectorAll('.dot');
  let currentSlide = 0;
  let slideInterval;

  function showSlide(index) {
    slides.forEach((slide, i) => {
      slide.classList.toggle('active', i === index);
      dots[i].classList.toggle('active', i === index);
    });
    currentSlide = index;
  }

  function nextSlide() {
    const nextIndex = (currentSlide + 1) % slides.length;
    showSlide(nextIndex);
  }

  function startCarousel() {
    slideInterval = setInterval(nextSlide, 4500);
  }

  dots.forEach(dot => {
    dot.addEventListener('click', (e) => {
      clearInterval(slideInterval);
      const slideIndex = parseInt(e.target.dataset.slide);
      showSlide(slideIndex);
      startCarousel();
    });
  });

  startCarousel();
</script>

</body>
</html>
