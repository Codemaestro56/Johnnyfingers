<?php
// Simple mail helper: tries PHPMailer if available, falls back to PHP mail().
// Configure SMTP via environment variables or define constants: SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASS, SMTP_FROM, SMTP_FROM_NAME

function send_mail($toEmail, $toName, $subject, $htmlBody, $textBody = '') {
    // prefer a plain-text alternative
    if (empty($textBody)) {
        $textBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlBody));
    }

    // attempt to use PHPMailer if installed via Composer
    if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
        try {
            // try to load via Composer autoload if not already
            if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer', false) && file_exists(__DIR__ . '/../vendor/autoload.php')) {
                require_once __DIR__ . '/../vendor/autoload.php';
            }
$mail = new PHPMailer\PHPMailer\PHPMailer(true);

            // Configure SMTP if available
            $smtpHost = defined('SMTP_HOST') ? SMTP_HOST : getenv('SMTP_HOST');
            $smtpUser = defined('SMTP_USER') ? SMTP_USER : getenv('SMTP_USER');
            $smtpPass = defined('SMTP_PASS') ? SMTP_PASS : getenv('SMTP_PASS');
            $smtpPort = defined('SMTP_PORT') ? SMTP_PORT : getenv('SMTP_PORT');

            if ($smtpHost && $smtpUser && $smtpPass) {
                $mail->isSMTP();
                $mail->Host = $smtpHost;
                $mail->SMTPAuth = true;
                $mail->Username = $smtpUser;
                $mail->Password = $smtpPass;
                $mail->SMTPSecure = 'tls';
                $mail->Port = $smtpPort ?: 587;
            }

            $from = defined('SMTP_FROM') ? SMTP_FROM : getenv('SMTP_FROM');
            $fromName = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : getenv('SMTP_FROM_NAME');
            if (!$from) { $from = 'no-reply@localhost'; }
            if (!$fromName) { $fromName = 'Johnnyfingers'; }

            $mail->setFrom($from, $fromName);
            $mail->addAddress($toEmail, $toName);
            $mail->Subject = $subject;
            $mail->isHTML(true);
            $mail->Body = $htmlBody;
            $mail->AltBody = $textBody;

            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log('PHPMailer send failed: ' . $e->getMessage());
            // fallthrough to mail() fallback
        }
    }

    // Fallback to PHP mail() with simple headers
    $from = defined('SMTP_FROM') ? SMTP_FROM : getenv('SMTP_FROM');
    if (!$from) $from = 'no-reply@localhost';

    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type: text/html; charset=UTF-8" . "\r\n";
    $headers .= "From: " . $from . "\r\n";

    $ok = @mail($toEmail, $subject, $htmlBody, $headers);
    if (!$ok) {
        error_log('mail() failed to send to ' . $toEmail . ' subject=' . $subject);
    }
    return $ok;
}

?>
