<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once 'db.php';
require_once 'config.php';

// 1. Auth Guard: Ensure student is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id    = $_SESSION['user_id'];
$full_name  = $_SESSION['full_name'] ?? $_SESSION['user_name'] ?? 'Student';
$user_email = $_SESSION['user_email'] ?? $_SESSION['email'] ?? '';

// ---- Load all ACTIVE courses from the database ----
try {
    $courses_stmt = $conn->query("
        SELECT id, course_code, title, track, price, is_active
        FROM courses
        WHERE is_active = 1
        ORDER BY id ASC
    ");
    $db_courses = $courses_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Database Error: " . htmlspecialchars($e->getMessage()));
}

// Convert to associative array keyed by course_code
$COURSES = [];
foreach ($db_courses as $c) {
    $code = $c['course_code'];
    $COURSES[$code] = [
        'id'        => (int)$c['id'],
        'db_id'     => (int)$c['id'],
        'title'     => $c['title'],
        'track'     => $c['track'] ?? 'Appliance Repair',
        'price'     => (float)$c['price'],
        'is_active' => (int)$c['is_active'],
    ];
}

// If no courses, show error
if (empty($COURSES)) {
    die("No courses available at this time.");
}

// Get first course code as default
$default_course_code = array_key_first($COURSES);

// Currency constants — UK site, GBP
if (!defined('CURRENCY_SYMBOL')) {
    define('CURRENCY_SYMBOL', '£');
}
if (!defined('CURRENCY_CODE')) {
    define('CURRENCY_CODE', 'GBP');
}

// NOTE: the old POST handler here used to mark an enrollment 'completed'
// directly from a submitted form with zero payment verification — anyone
// could POST process_payment=1 and get a free course. That's gone now.
// Enrollment is only ever written by webhook.php, driven by a verified
// Stripe event. This page's only job is to render the checkout UI and
// hand off to create-payment-intent.php.
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Checkout | Johnnyfingers</title>

  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <script src="https://js.stripe.com/v3/"></script>

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

    * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Plus Jakarta Sans', sans-serif; }

    body {
      background-color: var(--bg-dark);
      color: var(--text-main);
      min-height: 100vh;
      padding: 40px 20px;
      background-image: 
        radial-gradient(at 10% 10%, rgba(59, 130, 246, 0.12) 0px, transparent 50%),
        radial-gradient(at 90% 90%, rgba(16, 185, 129, 0.08) 0px, transparent 50%);
    }

    .top-nav {
      max-width: 1100px;
      margin: 0 auto 30px auto;
      display: flex;
      justify-content: space-between;
      align-items: center;
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
    .security-badge {
      font-size: 0.85rem;
      color: var(--accent-green);
      background: rgba(16, 185, 129, 0.1);
      padding: 6px 14px;
      border-radius: 20px;
      border: 1px solid rgba(16, 185, 129, 0.2);
    }

    .checkout-container {
      max-width: 1100px;
      margin: 0 auto;
      display: grid;
      grid-template-columns: 1.2fr 0.8fr;
      gap: 30px;
      animation: fadeIn 0.5s ease-out;
    }

    .card {
      background: var(--card-bg);
      border: 1px solid var(--border);
      border-radius: 24px;
      padding: 30px;
      box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
    }

    .section-header {
      display: flex;
      align-items: center;
      gap: 12px;
      margin-bottom: 25px;
    }
    .step-number {
      background: var(--primary);
      color: #fff;
      width: 32px;
      height: 32px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 800;
      font-size: 0.9rem;
    }
    .section-title { font-size: 1.25rem; font-weight: 700; }

    .tracks-list { display: flex; flex-direction: column; gap: 16px; }

    .track-option {
      background: #0f172a;
      border: 2px solid var(--border);
      border-radius: 18px;
      padding: 16px;
      cursor: pointer;
      display: flex;
      align-items: center;
      gap: 18px;
      transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
      position: relative;
      overflow: hidden;
    }

    .track-option:hover {
      transform: translateY(-2px);
      border-color: rgba(59, 130, 246, 0.5);
    }

    .track-option.active {
      border-color: var(--primary);
      background: rgba(37, 99, 235, 0.08);
      box-shadow: 0 0 20px rgba(59, 130, 246, 0.15);
    }

    .track-info { flex: 1; }
    .track-title { font-size: 1rem; font-weight: 700; margin-bottom: 4px; }
    .track-meta { font-size: 0.8rem; color: var(--text-muted); display: flex; gap: 12px; }

    .track-price {
      font-size: 1.15rem;
      font-weight: 800;
      color: var(--accent-green);
      text-align: right;
    }

    .radio-dot {
      width: 20px;
      height: 20px;
      border-radius: 50%;
      border: 2px solid var(--border);
      display: flex;
      align-items: center;
      justify-content: center;
      transition: 0.2s;
    }

    .track-option.active .radio-dot {
      border-color: var(--primary);
      background: var(--primary);
    }

    .track-option.active .radio-dot::after {
      content: '';
      width: 8px;
      height: 8px;
      background: #fff;
      border-radius: 50%;
    }

    .summary-box {
      background: #0f172a;
      border: 1px solid var(--border);
      border-radius: 16px;
      padding: 20px;
      margin-bottom: 25px;
    }

    .summary-row {
      display: flex;
      justify-content: space-between;
      margin-bottom: 12px;
      font-size: 0.9rem;
      color: var(--text-muted);
    }

    .summary-total {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-top: 15px;
      padding-top: 15px;
      border-top: 1px dashed var(--border);
      font-size: 1.25rem;
      font-weight: 800;
      color: var(--text-main);
    }

    .input-group { margin-bottom: 16px; }
    .input-group label { display: block; font-size: 0.85rem; font-weight: 600; color: var(--text-muted); margin-bottom: 6px; }
    .input-field {
      width: 100%;
      padding: 14px 16px;
      border-radius: 12px;
      border: 1px solid var(--border);
      background: #0f172a;
      color: #fff;
      font-size: 0.95rem;
      transition: border-color 0.2s;
    }
    .input-field:focus { outline: none; border-color: var(--primary); }

    .btn-pay {
      width: 100%;
      padding: 16px;
      background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
      color: #fff;
      border: none;
      border-radius: 14px;
      font-weight: 800;
      font-size: 1rem;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 10px;
      box-shadow: 0 10px 25px rgba(37, 99, 235, 0.35);
      transition: transform 0.2s, box-shadow 0.2s;
    }

    .btn-pay:hover {
      transform: translateY(-2px);
      box-shadow: 0 15px 30px rgba(37, 99, 235, 0.45);
    }

    .guarantee-text {
      text-align: center;
      font-size: 0.8rem;
      color: var(--text-muted);
      margin-top: 15px;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
    }

    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(15px); }
      to { opacity: 1; transform: translateY(0); }
    }

    @media (max-width: 900px) {
      .checkout-container { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>

  <!-- Top Navigation -->
  <header class="top-nav">
    <a href="Home.php" class="brand-logo" style="display: inline-flex; align-items: center; text-decoration: none;">
      <img src="images/logo.png" alt="Johnny Fingers Logo" style="height: 45px; width: auto; object-fit: contain;">
    </a>
    <div class="security-badge">
      <i class="fa-solid fa-lock"></i> 256-Bit SSL Encrypted
    </div>
  </header>

  <div class="checkout-container">
    
    <!-- Step 1: Select Course Track -->
    <div class="card">
      <div class="section-header">
        <div class="step-number">1</div>
        <h2 class="section-title">Select Your Specialty Track</h2>
      </div>

      <div class="tracks-list">
        <?php $first = true; ?>
        <?php foreach ($COURSES as $code => $course): ?>
          <div class="track-option <?php echo $first ? 'active' : ''; ?>" 
               onclick="selectTrack('<?php echo htmlspecialchars($code); ?>', this)">
            <div class="track-info">
              <h3 class="track-title"><?php echo htmlspecialchars($course['title']); ?></h3>
              <div class="track-meta">
                <span><i class="fa-solid fa-play-circle"></i> 4 Lessons</span>
                <span><i class="fa-solid fa-certificate"></i> Certification</span>
              </div>
            </div>
            <div>
              <div class="track-price"><?php echo CURRENCY_SYMBOL . number_format($course['price'], 2); ?></div>
              <div class="radio-dot" style="margin-left: auto; margin-top: 6px;"></div>
            </div>
          </div>
          <?php $first = false; ?>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Step 2: Payment & Student Info -->
    <div class="card">
      <div class="section-header">
        <div class="step-number">2</div>
        <h2 class="section-title">Checkout Summary</h2>
      </div>

      <div class="summary-box">
        <div class="summary-row">
          <span>Enrolling Program:</span>
          <strong id="summaryCourseName" style="color: #fff;"><?php echo htmlspecialchars($COURSES[$default_course_code]['title']); ?></strong>
        </div>
        <div class="summary-row">
          <span>Track Code:</span>
          <strong id="summaryCourseCode" style="color: var(--primary);"><?php echo htmlspecialchars($default_course_code); ?></strong>
        </div>
        <div class="summary-row">
          <span>Access Level:</span>
          <span style="color: var(--accent-green);">Lifetime Access</span>
        </div>

        <div class="summary-total">
          <span>Total Amount:</span>
          <span id="summaryAmount" style="color: var(--accent-green);">
            <?php echo CURRENCY_SYMBOL . number_format($COURSES[$default_course_code]['price'], 2); ?>
          </span>
        </div>
      </div>

      <div class="input-group">
        <label>Full Name</label>
        <input type="text" id="fullName" class="input-field" value="<?php echo htmlspecialchars($full_name); ?>" required>
      </div>

      <div class="input-group">
        <label>Email Address</label>
        <input type="email" id="email" class="input-field" value="<?php echo htmlspecialchars($user_email); ?>" required>
      </div>

      <div class="input-group">
        <label>Phone Number</label>
        <input type="tel" id="phone" class="input-field" placeholder="+44 7911 123456" required>
      </div>

      <!-- Stripe Elements mounts the card/payment UI here -->
      <div class="input-group">
        <label>Payment Details</label>
        <div id="payment-element" style="padding:14px 16px;border-radius:12px;border:1px solid var(--border);background:#0f172a;"></div>
      </div>

      <div id="payment-message" style="color:#f87171;font-size:0.85rem;margin-bottom:12px;display:none;"></div>

      <button type="button" class="btn-pay" id="submit-btn" onclick="payWithStripe()">
        <i class="fa-solid fa-bolt"></i>
        <span id="btn-text">Complete Payment Now</span>
        <span id="btn-spinner" style="display:none;">Processing…</span>
      </button>

      <p class="guarantee-text">
        <i class="fa-solid fa-shield-halved"></i> 100% Secure Checkout powered by Stripe
      </p>
    </div>

  </div>

  <script>
    // Publishable key — safe to expose client-side by design.
    // The secret key lives only in create-payment-intent.php / webhook.php via env vars.
    const STRIPE_PUBLISHABLE_KEY = 'pk_test_51U54ziGkNKXVkYFoC3TyIfEZrcLdX7Rwlk8qwNoQtxZ5O1gQLpRbLBTBBOECyxtqfyatJvGRL4bLN9bb95aACrJI00sOdiviNK';
    const stripe = Stripe(STRIPE_PUBLISHABLE_KEY);

    const COURSES = <?php echo json_encode($COURSES); ?>;
    const CURRENCY_SYMBOL = "<?php echo CURRENCY_SYMBOL; ?>";

    let selectedCode = '<?php echo htmlspecialchars($default_course_code); ?>';
    let elements, clientSecret;

    function selectTrack(code, element) {
      if (!COURSES[code] || code === selectedCode) return;

      selectedCode = code;
      const track = COURSES[code];

      document.querySelectorAll('.track-option').forEach(el => el.classList.remove('active'));
      element.classList.add('active');

      document.getElementById('summaryCourseName').innerText = track.title;
      document.getElementById('summaryCourseCode').innerText = code;
      document.getElementById('summaryAmount').innerText = CURRENCY_SYMBOL + Number(track.price).toLocaleString();

      // Course changed -> price changed -> need a fresh PaymentIntent
      initializeStripeElement();
    }

    async function initializeStripeElement() {
      const res = await fetch('create-payment-intent.php?course=' + encodeURIComponent(selectedCode));
      const data = await res.json();

      const msgEl = document.getElementById('payment-message');
      if (data.error) {
        msgEl.textContent = data.error;
        msgEl.style.display = 'block';
        return;
      }
      msgEl.style.display = 'none';

      clientSecret = data.clientSecret;
      elements = stripe.elements({ clientSecret });
      const paymentElement = elements.create('payment'); // card UI + automatic 3DS/SCA handling
      document.getElementById('payment-element').innerHTML = '';
      paymentElement.mount('#payment-element');
    }

    async function payWithStripe() {
      const fullName = document.getElementById('fullName')?.value.trim();
      const email    = document.getElementById('email')?.value.trim();
      const phone    = document.getElementById('phone')?.value.trim();
      const msgEl    = document.getElementById('payment-message');

      if (!fullName || !email || !phone) {
        msgEl.textContent = 'Please fill out your Name, Email, and Phone number.';
        msgEl.style.display = 'block';
        return;
      }
      if (!elements) {
        msgEl.textContent = 'Payment form is still loading — please wait a moment and try again.';
        msgEl.style.display = 'block';
        return;
      }

      setLoading(true);

   const { error } = await stripe.confirmPayment({
  elements,
  confirmParams: {
    return_url: new URL('payment-return.php', window.location.href).href,
    receipt_email: email,
  },
});
      // Only reached on an immediate failure (e.g. declined card before
      // any redirect). On success the browser navigates to return_url,
      // and webhook.php performs the actual enrollment.
      if (error) {
        msgEl.textContent = error.message;
        msgEl.style.display = 'block';
        setLoading(false);
      }
    }

    function setLoading(isLoading) {
      document.getElementById('submit-btn').disabled = isLoading;
      document.getElementById('btn-text').style.display = isLoading ? 'none' : 'inline';
      document.getElementById('btn-spinner').style.display = isLoading ? 'inline' : 'none';
    }

    initializeStripeElement();
  </script>
</body>
</html>