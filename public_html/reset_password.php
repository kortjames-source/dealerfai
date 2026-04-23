<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/security_headers.php';
require_once __DIR__ . '/includes/theme_head.php';
require_once __DIR__ . '/includes/csrf.php';
include 'db.php';
require_once __DIR__ . '/helpers/db_utils.php';
require_once __DIR__ . '/helpers/rate_limit.php';
require_once __DIR__ . '/helpers/security_logger.php';
require_once __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

$localConfigPath = __DIR__ . '/../secure/local_config.php';
$localConfig = [];
if (file_exists($localConfigPath)) {
    $loaded = require $localConfigPath;
    if (is_array($loaded)) {
        $localConfig = $loaded;
    }
}
$smtpConfig = $localConfig['smtp'] ?? [];

$error = '';
$success = '';
$hasPasswordUpdated = column_exists($db, 'users', 'password_updated_at');

function generate_temp_password(int $length = 12): string
{
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789!@#$%&*?';
    $password = '';
    $max = strlen($chars) - 1;
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, $max)];
    }
    return $password;
}

function send_temp_password_email(string $recipient, string $password): bool
{
    global $smtpConfig;
    $smtpHost = $smtpConfig['host'] ?? ($_ENV['SMTP_HOST'] ?? getenv('SMTP_HOST') ?? '');
    $smtpUser = $smtpConfig['user'] ?? ($_ENV['SMTP_USER'] ?? getenv('SMTP_USER') ?? '');
    $smtpPass = $smtpConfig['pass'] ?? ($_ENV['SMTP_PASS'] ?? getenv('SMTP_PASS') ?? '');
    $smtpPort = (int)($smtpConfig['port'] ?? ($_ENV['SMTP_PORT'] ?? getenv('SMTP_PORT') ?? 587));
    $smtpSecure = $smtpConfig['secure'] ?? ($_ENV['SMTP_SECURE'] ?? getenv('SMTP_SECURE') ?? PHPMailer::ENCRYPTION_STARTTLS);
    $fromEmail = $smtpConfig['from_email'] ?? $smtpUser;
    $fromName = $smtpConfig['from_name'] ?? 'DealerFAI';

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = $smtpHost;
        $mail->SMTPAuth = true;
        $mail->Username = $smtpUser;
        $mail->Password = $smtpPass;
        $mail->SMTPSecure = $smtpSecure;
        $mail->Port = $smtpPort;

        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($recipient);
        $mail->isHTML(true);
        $mail->Subject = 'DealerFAI Temporary Password';
        $mail->Body = sprintf(
            '<p>Hello,</p><p>A temporary password has been requested for your DealerFAI account. Use the temporary password below to log in, then update it immediately:</p><p><strong>%s</strong></p><p>If you did not request this, please contact your administrator.</p><p>DealerFAI Team</p>',
            htmlspecialchars($password, ENT_QUOTES, 'UTF-8')
        );

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log(
            'Failed to send reset email to ' . $recipient . ': ' .
            $mail->ErrorInfo . ' / ' . $e->getMessage()
        );
        return false;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!dealerfai_csrf_validate($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        die('Invalid request.');
    }

    $email = trim($_POST['email'] ?? '');
    $ip = dealerfai_rate_limit_client_ip();
    $ipBucket = 'password_reset:ip:' . $ip;
    $emailBucket = 'password_reset:email:' . hash('sha256', strtolower($email));

    $ipCheck = dealerfai_rate_limit_check_and_increment($ipBucket, 12, 900);
    $emailCheck = dealerfai_rate_limit_check_and_increment($emailBucket, 5, 3600);
    if (!$ipCheck['allowed'] || !$emailCheck['allowed']) {
        $error = 'Too many reset attempts. Please wait and try again.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $selectFields = 'id, password, password_last_set';
        if ($hasPasswordUpdated) {
            $selectFields .= ', password_updated_at';
        }
        $stmt = $db->prepare('SELECT ' . $selectFields . ' FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        $emailSent = true;

        if ($user) {
            $oldPassword = (string)($user['password'] ?? '');
            $oldPasswordLastSet = $user['password_last_set'] ?? null;
            $oldPasswordUpdatedAt = $hasPasswordUpdated ? ($user['password_updated_at'] ?? null) : null;

            $tempPassword = generate_temp_password();
            $hashed = password_hash($tempPassword, PASSWORD_DEFAULT);
            $setPieces = [
                'password = ?',
                'password_last_set = NOW()',
            ];
            if ($hasPasswordUpdated) {
                $setPieces[] = 'password_updated_at = NOW()';
            }
            $updateSql = 'UPDATE users SET ' . implode(', ', $setPieces) . ' WHERE id = ?';
            $updateStmt = $db->prepare($updateSql);
            $updateStmt->execute([$hashed, $user['id']]);

            $emailSent = send_temp_password_email($email, $tempPassword);
            if (!$emailSent) {
                $restorePieces = [
                    'password = ?',
                    'password_last_set = ?',
                ];
                $restoreParams = [
                    $oldPassword,
                    $oldPasswordLastSet,
                ];
                if ($hasPasswordUpdated) {
                    $restorePieces[] = 'password_updated_at = ?';
                    $restoreParams[] = $oldPasswordUpdatedAt;
                }
                $restoreParams[] = $user['id'];
                $restoreSql = 'UPDATE users SET ' . implode(', ', $restorePieces) . ' WHERE id = ?';
                try {
                    $restoreStmt = $db->prepare($restoreSql);
                    $restoreStmt->execute($restoreParams);
                } catch (Throwable $restoreErr) {
                    error_log('Failed to restore password after email send failure for user_id ' . (int)$user['id'] . ': ' . $restoreErr->getMessage());
                }
            } else {
                dealerfai_security_log($db, 'password_reset_sent', [
                    'user_id' => (int)$user['id'],
                    'email'   => $email,
                ]);
            }
        }

        if ($emailSent) {
            $success = 'If that email exists in our system, a temporary password has been emailed to you.';
        } else {
            $error = 'We could not send the temporary password email right now; please try again later.';
        }
    }
}
$csrfToken = dealerfai_csrf_get_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php include __DIR__ . "/includes/head_favicon.php"; ?>
<meta charset="UTF-8">
  <title>Reset Password - DealerFAI</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php dealerfai_theme_head(); ?>
  <style nonce="<?= dealerfai_csp_nonce() ?>">
    body {
      margin: 0;
      font-family: "Segoe UI", sans-serif;
      background-color: #f4f6f8;
    }
    header {
      background-color: #0066cc;
      color: white;
      padding: 30px 40px;
      text-align: center;
    }
    header img {
      max-width: 480px;
      max-height: 180px;
      height: auto;
      display: block;
      margin: 0 auto 10px;
    }
    main {
      max-width: 420px;
      margin: 40px auto;
      background: white;
      padding: 30px;
      border-radius: 8px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    }
    h2 {
      text-align: center;
      color: #0066cc;
    }
    form {
      display: flex;
      flex-direction: column;
    }
    label {
      margin-top: 15px;
      font-weight: bold;
    }
    input[type="email"],
    input[type="password"] {
      padding: 10px;
      font-size: 16px;
      margin-top: 5px;
      border-radius: 4px;
      border: 1px solid #ccc;
    }
    button {
      margin-top: 25px;
      padding: 12px;
      background-color: #0066cc;
      color: white;
      border: none;
      border-radius: 6px;
      font-size: 16px;
      cursor: pointer;
    }
    button:hover {
      background-color: #094c63;
    }
    .message {
      text-align: center;
      margin-top: 20px;
      font-weight: bold;
    }
    .error { color: red; }
    .success { color: green; }
    .login-link {
      text-align: center;
      margin-top: 20px;
    }
    .login-link a {
      color: #0066cc;
      text-decoration: none;
      font-weight: bold;
    }
    .login-link a:hover {
      text-decoration: underline;
    }
    footer {
      background-color: #0066cc;
      color: white;
      text-align: center;
      padding: 16px;
      font-size: 14px;
      margin-top: 60px;
    }
  </style>
</head>
<body class="bg-ai">
  <div class="full-page-center">
    <header class="clean-header">
      <a href="index.php">
        <img src="dealerfai_logo_blue.png" alt="DealerFAI Logo" style="height: 120px;">
      </a>
    </header>

    <main class="glass" style="margin-top: 0; width: 100%; max-width: 480px;">
      <h2 class="text-gradient" style="margin-bottom: 1rem;">Reset Password</h2>
      <p class="text-muted text-center" style="margin-bottom: 2rem;">Enter your email to receive a temporary password.</p>

      <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
      <?php endif; ?>

      <?php if (!$success): ?>
        <form method="post">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
          <div class="form-group">
            <label for="email">Email Address</label>
            <input type="email" name="email" id="email" placeholder="you@example.com" required autofocus>
          </div>
          <button type="submit" style="width: 100%; margin-top: 2rem;">Send Temporary Password</button>
        </form>
      <?php endif; ?>

      <div class="text-center mt-4">
        <a href="login.php" class="text-muted" style="font-size: 0.9rem;">Back to Login</a>
      </div>
    </main>

    <footer style="background: transparent; color: rgba(255,255,255,0.7); border: none; margin-top: 2rem; padding: 1rem;">
      &copy; <?php echo date("Y"); ?> DealerFAI. All rights reserved.
    </footer>
  </div>
</body>
</html>
