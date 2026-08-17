<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = isset($_POST['full_name']) ? trim($_POST['full_name']) : '';
    $email     = isset($_POST['email']) ? trim($_POST['email']) : '';
    $phone     = isset($_POST['phone']) ? trim($_POST['phone']) : '';
    $password  = isset($_POST['password']) ? trim($_POST['password']) : '';

    if (empty($full_name) || empty($email) || empty($phone) || empty($password)) {
        header("Location: register.html?error=empty");
        exit();
    }

    try {
        // Check for duplicate email
        $check_stmt = $conn->prepare("SELECT id FROM users WHERE email = :email");
        $check_stmt->execute([':email' => $email]);

        if ($check_stmt->rowCount() > 0) {
            header("Location: register.html?error=exists");
            exit();
        }

        // Hash password securely
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        // Insert new user and explicitly set role to 'student' to avoid accidental admin defaults
        $insert_stmt = $conn->prepare("INSERT INTO users (full_name, email, phone, password, role) VALUES (:full_name, :email, :phone, :password, :role)");
        
        $success = $insert_stmt->execute([
            ':full_name' => $full_name,
            ':email'     => $email,
            ':phone'     => $phone,
            ':password'  => $hashed_password,
            ':role'      => 'student'
        ]);

        if ($success) {
            // send welcome email (best-effort)
            if (file_exists(__DIR__ . '/lib/send_mail.php')) {
                require_once __DIR__ . '/lib/send_mail.php';
                $subject = 'Welcome to Johnnyfingers — Your account is ready';
                $body = "<p>Hi " . htmlspecialchars($full_name) . ",</p><p>Thanks for registering at Johnnyfingers. You can now <a href=\"" . (dirname($_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']) ) . "/login.php\">log in</a> and start learning.</p>";
                @send_mail($email, $full_name, $subject, $body);
            }
            header("Location: login.html?registered=success");
            exit();
        } else {
            header("Location: register.html?error=failed");
            exit();
        }

    } catch (PDOException $e) {
        header("Location: register.html?error=failed");
        exit();
    }
} else {
    header("Location: register.html");
    exit();
}
?>