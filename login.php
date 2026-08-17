<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}
require_once 'db.php';


// 2. FORM SUBMISSION PROCESSOR
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = isset($_POST['email']) ? trim($_POST['email']) : '';
    $password = isset($_POST['password']) ? trim($_POST['password']) : '';

    if (empty($email) || empty($password)) {
        $error_msg = "Please fill in all fields.";
    } else {
        try {
            // Fetch user record including 'role' column
            $stmt = $conn->prepare("SELECT id, full_name, email, password, role FROM users WHERE email = :email");
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($password, $user['password'])) {
                session_regenerate_id(true);

                // Store user details in session
                $_SESSION['user_id']    = $user['id'];
                $_SESSION['user_name']  = $user['full_name'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['role']       = strtolower($user['role'] ?? 'student');

                // Smart Redirect based on role
                if ($_SESSION['role'] === 'admin') {
                    header("Location: admin.php");
                } else {
                    header("Location: dashboard.php");
                }
                exit();
            } else {
                $error_msg = "Invalid email or password.";
            }
        } catch (PDOException $e) {
            $error_msg = "A system error occurred. Please try again later.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Student Login | Johnny Fingers</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="assets/theme.css">
  <link rel="stylesheet" href="styles.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    body {
      background-color: #0b0f19;
      color: #ffffff;
      font-family: system-ui, -apple-system, sans-serif;
      display: flex;
      justify-content: center;
      align-items: center;
      min-height: 100vh;
      margin: 0;
    }
    .login-card {
      background: #111827;
      border: 1px solid #1f2937;
      border-radius: 12px;
      padding: 2.5rem;
      width: 100%;
      max-width: 400px;
      box-shadow: 0 10px 25px rgba(0, 0, 0, 0.5);
    }
    .alert-error {
      background: rgba(239, 68, 68, 0.1);
      border: 1px solid #ef4444;
      color: #fca5a5;
      padding: 0.75rem 1rem;
      border-radius: 8px;
      margin-bottom: 1.5rem;
      font-size: 0.9rem;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .form-group {
      margin-bottom: 1.25rem;
    }
    .form-group label {
      display: block;
      margin-bottom: 0.5rem;
      font-size: 0.875rem;
      color: #9ca3af;
    }
    .form-control {
      width: 100%;
      padding: 0.75rem 1rem;
      background: #1f2937;
      border: 1px solid #374151;
      border-radius: 8px;
      color: #ffffff;
      font-size: 1rem;
      box-sizing: border-box;
      outline: none;
      transition: border-color 0.2s;
    }
    .form-control:focus {
      border-color: var(--jf-primary);
    }
    .btn-submit {
      width: 100%;
      padding: 0.85rem;
      background: var(--jf-primary);
      color: white;
      border: none;
      border-radius: 8px;
      font-weight: 600;
      font-size: 1rem;
      cursor: pointer;
      transition: background 0.2s;
      margin-top: 0.5rem;
    }
    .btn-submit:hover {
      background: var(--jf-primary-600);
    }
    .footer-links {
      text-align: center;
      margin-top: 1.5rem;
      font-size: 0.875rem;
      color: #9ca3af;
    }
    .footer-links a {
      color: #3b82f6;
      text-decoration: none;
    }
  </style>
</head>
<body>

  <div class="login-card">
    <!-- Dynamic Header Logo -->
    <div style="text-align: center; margin-bottom: 1.5rem;">
      <a href="Home.php" style="display: inline-flex; align-items: center; gap: 8px; text-decoration: none;">
        <img src="images/logo.png" alt="Johnny Fingers Logo" style="height: 40px; width: auto;" onerror="this.style.display='none'">
      </a>
      <h2 style="margin-top: 1rem; margin-bottom: 0.25rem; font-size: 1.5rem;">Welcome Back</h2>
      <p style="color: #9ca3af; font-size: 0.875rem; margin: 0;">Sign in to access your learning portal</p>
    </div>

    <!-- Interactive Dynamic Error Banner -->
    <?php if (!empty($error_msg)): ?>
      <div class="alert-error">
        <i class="fa-solid fa-circle-exclamation"></i>
        <span><?php echo htmlspecialchars($error_msg); ?></span>
      </div>
    <?php endif; ?>

    <!-- Login Form -->
    <form action="login.php" method="POST">
      <div class="form-group">
        <label for="email">Email Address</label>
        <input type="email" id="email" name="email" class="form-control" placeholder="name@example.com" value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" required>
      </div>

      <div class="form-group">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" class="form-control" placeholder="••••••••" required>
      </div>

      <button type="submit" class="btn-submit">
        Sign In <i class="fa-solid fa-arrow-right" style="margin-left: 6px;"></i>
      </button>
    </form>

    <div class="footer-links">
      Don't have an account? <a href="register.php">Enroll Now</a>
    </div>
  </div>

</body>
</html>