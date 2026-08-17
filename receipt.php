<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id   = $_SESSION['user_id'];
$enroll_id = isset($_GET['enroll_id']) ? (int)$_GET['enroll_id'] : 0;

if (!$enroll_id) {
    die("Invalid receipt request.");
}

// Only ever pull data the logged-in user actually owns — never trust
// course/amount/name values passed in the URL.
try {
    $is_admin = isset($_SESSION['role']) && $_SESSION['role'] === 'admin';

    if ($is_admin) {
        // Admin view: match on enrollment ID only
        $stmt = $conn->prepare("
            SELECT e.id, e.payment_status, e.amount_paid, e.payment_reference, e.created_at,
                   c.course_code, c.title AS course_title,
                   u.full_name, u.email
            FROM enrollments e
            JOIN courses c ON c.id = e.course_id
            JOIN users u ON u.id = e.user_id
            WHERE e.id = :eid
            LIMIT 1
        ");
        $stmt->execute([':eid' => $enroll_id]);
    } else {
        // Student view: enforce ownership using e.user_id
        $stmt = $conn->prepare("
            SELECT e.id, e.payment_status, e.amount_paid, e.payment_reference, e.created_at,
                   c.course_code, c.title AS course_title,
                   u.full_name, u.email
            FROM enrollments e
            JOIN courses c ON c.id = e.course_id
            JOIN users u ON u.id = e.user_id
            WHERE e.id = :eid AND e.user_id = :uid
            LIMIT 1
        ");
        $stmt->execute([':eid' => $enroll_id, ':uid' => $user_id]);
    }

    $r = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Database Error: " . htmlspecialchars($e->getMessage()));
}

if (!$r) {
    http_response_code(404);
    die("Receipt not found.");
}
if (!defined('CURRENCY_SYMBOL')) {
    define('CURRENCY_SYMBOL', '₦');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Receipt — Johnnyfingers Academy</title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Oswald:wght@500;600;700&family=Work+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

  <style>
    :root {
      --bg-dark: #0f172a; --card-bg: #1e293b; --card-border: rgba(255,255,255,0.1);
      --primary-orange: #f97316; --text-main: #f8fafc; --text-muted: #94a3b8; --text-subtle: #cbd5e1;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { background: var(--bg-dark); color: var(--text-main); font-family: 'Work Sans', sans-serif; min-height: 100vh; }
    .display { font-family: 'Oswald', sans-serif; text-transform: uppercase; letter-spacing: 0.5px; }
    .mono { font-family: 'IBM Plex Mono', monospace; }
    .site-header { background: rgba(15,23,42,0.9); border-bottom: 1px solid var(--card-border); padding: 16px 0; }
    .container { width: 100%; max-width: 1200px; margin: 0 auto; padding: 0 20px; }
    .nav-row { display: flex; align-items: center; justify-content: space-between; }
    .receipt-wrapper { max-width: 680px; margin: 40px auto; padding: 0 15px; }
    .receipt-card { background: var(--card-bg); border: 1px solid var(--card-border); border-radius: 16px; padding: 40px; box-shadow: 0 20px 40px rgba(0,0,0,0.4); }
    .status-badge {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 6px 14px; border-radius: 20px; font-size: 13px; font-weight: 600;
    }
    .status-completed { background: rgba(34,197,94,0.15); border: 1px solid rgba(34,197,94,0.3); color: #4ade80; }
    .status-pending { background: rgba(245,158,11,0.15); border: 1px solid rgba(245,158,11,0.3); color: #fbbf24; }
    .status-failed { background: rgba(239,68,68,0.15); border: 1px solid rgba(239,68,68,0.3); color: #f87171; }
    .invoice-table { width: 100%; border-collapse: collapse; margin: 25px 0; }
    .invoice-table th, .invoice-table td { padding: 12px 0; border-bottom: 1px solid var(--card-border); font-size: 14px; }
    .invoice-table th { text-align: left; color: var(--text-muted); font-weight: 500; text-transform: uppercase; font-size: 12px; }
    .action-row { display: flex; gap: 15px; margin-top: 30px; flex-wrap: wrap; }
    .btn { display: inline-flex; align-items: center; gap: 8px; padding: 12px 24px; border-radius: 8px; font-weight: 600; font-size: 15px; border: none; text-decoration: none; flex: 1; justify-content: center; min-width: 160px; }
    .btn-primary { background: var(--primary-orange); color: #fff; }
    .btn-secondary { background: rgba(255,255,255,0.1); color: #fff; border: 1px solid var(--card-border); }

    @media print {
      body { background: #fff !important; color: #000 !important; }
      .site-header, .action-row { display: none !important; }
      .receipt-wrapper { margin: 0; max-width: 100%; padding: 0; }
      .receipt-card { background: #fff !important; color: #000 !important; border: 1px solid #ccc !important; box-shadow: none !important; }
    }
    @media (max-width: 600px) {
      .receipt-card { padding: 24px; }
      .receipt-wrapper { margin: 20px auto; }
      div[style*="grid-template-columns: 1fr 1fr"] { grid-template-columns: 1fr !important; text-align: left !important; }
    }
  </style>
</head>
<body>

<header class="site-header">
  <div class="container nav-row">
    <a href="Home.php" class="brand-logo" style="display:inline-flex;align-items:center;text-decoration:none;">
      <img src="images/logo.png" alt="Johnny Fingers Logo" style="height:40px;width:auto;object-fit:contain;">
    </a>
    <a href="dashboard.php" style="color:var(--text-muted);font-size:14px;text-decoration:none;">
      <i class="fa-solid fa-arrow-left"></i> Back to Dashboard
    </a>
  </div>
</header>

<div class="receipt-wrapper">
  <div class="receipt-card">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:30px;flex-wrap:wrap;gap:10px;">
      <div>
        <h2 class="display" style="font-size:1.8rem;margin-bottom:4px;">OFFICIAL RECEIPT</h2>
        <span class="mono" style="color:var(--text-muted);font-size:13px;">
          Date: <?php echo htmlspecialchars(date('j M Y', strtotime($r['created_at']))); ?>
        </span>
      </div>
      <?php
        $statusClass = [
          'completed' => 'status-completed',
          'pending'   => 'status-pending',
          'failed'    => 'status-failed',
        ][$r['payment_status']] ?? 'status-pending';
        $statusLabel = [
          'completed' => 'PAYMENT SUCCESSFUL',
          'pending'   => 'PAYMENT PENDING',
          'failed'    => 'PAYMENT FAILED',
        ][$r['payment_status']] ?? strtoupper($r['payment_status']);
      ?>
      <div class="status-badge <?php echo $statusClass; ?>">
        <i class="fa-solid fa-circle-check"></i> <?php echo $statusLabel; ?>
      </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;padding:20px;background:rgba(15,23,42,0.5);border-radius:10px;margin-bottom:25px;">
      <div>
        <span style="font-size:11px;color:var(--text-muted);text-transform:uppercase;">Billed To</span>
        <strong style="display:block;font-size:15px;margin-top:4px;"><?php echo htmlspecialchars($r['full_name']); ?></strong>
        <span style="font-size:13px;color:var(--text-subtle);"><?php echo htmlspecialchars($r['email']); ?></span>
      </div>
      <div style="text-align:right;">
        <span style="font-size:11px;color:var(--text-muted);text-transform:uppercase;">Issued By</span>
        <strong style="display:block;font-size:15px;margin-top:4px;">Johnnyfingers Academy</strong>
        <span style="font-size:13px;color:var(--text-subtle);">Appliance Technical Training</span>
      </div>
    </div>

    <table class="invoice-table">
      <thead><tr><th>Description</th><th>Code</th><th style="text-align:right;">Amount</th></tr></thead>
      <tbody>
        <tr>
          <td>
            <strong style="display:block;"><?php echo htmlspecialchars($r['course_title']); ?></strong>
            <span style="font-size:12px;color:var(--text-muted);">Lifetime Portal Access & Certification</span>
          </td>
          <td class="mono"><?php echo htmlspecialchars($r['course_code']); ?></td>
          <td style="text-align:right;font-weight:700;color:var(--primary-orange);">
            <?php echo CURRENCY_SYMBOL . number_format((float)$r['amount_paid'], 2); ?>
          </td>
        </tr>
      </tbody>
    </table>

    <div style="margin-top:20px;padding-top:15px;border-top:2px dashed var(--card-border);">
      <div style="display:flex;justify-content:space-between;font-size:13px;">
        <span style="color:var(--text-muted);">Transaction Reference:</span>
        <span class="mono" style="color:#38bdf8;"><?php echo htmlspecialchars($r['payment_reference'] ?? '—'); ?></span>
      </div>
    </div>
  </div>

  <div class="action-row">
    <button onclick="window.print()" class="btn btn-secondary">
      <i class="fa-solid fa-print"></i> Print / Save PDF
    </button>
    <a href="dashboard.php" class="btn btn-primary">
      <i class="fa-solid fa-arrow-left"></i> Back to Dashboard
    </a>
  </div>
</div>

</body>
</html>
