<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/security_headers.php';
require_once __DIR__ . '/includes/theme_head.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/helpers/rate_limit.php';
require_once __DIR__ . '/helpers/security_logger.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!dealerfai_csrf_validate($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        die('Invalid request.');
    }

    include 'db.php';
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    $ip = dealerfai_rate_limit_client_ip();
    $emailKey = strtolower($email);
    $ipBucket = 'login:ip:' . $ip;
    $emailBucket = 'login:email:' . hash('sha256', $emailKey);
    $ipCheck = dealerfai_rate_limit_check_and_increment($ipBucket, 25, 900);
    $emailCheck = dealerfai_rate_limit_check_and_increment($emailBucket, 10, 900);
    if (!$ipCheck['allowed'] || !$emailCheck['allowed']) {
        $error = "Too many login attempts. Please wait a few minutes and try again.";
        dealerfai_security_log($db, 'login_rate_limited', ['email' => $email]);
    } else {

        $stmt = $db->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password'])) {
            // Prevent session fixation after successful authentication.
            session_regenerate_id(true);
            dealerfai_rate_limit_reset($ipBucket);
            dealerfai_rate_limit_reset($emailBucket);

            $lastSet = new DateTime($user['password_last_set']);
            $now = new DateTime();
            $interval = $lastSet->diff($now);
            $monthsOld = ($interval->y * 12) + $interval->m;

if ($monthsOld >= 6) {
    $_SESSION['temp_user_id'] = $user['id'];
    header("Location: force_password_reset");
    exit;
}

// Check if within 7 days of expiry
$daysOld = $interval->days;
if ($daysOld >= 173) { // 180 - 7
    $_SESSION['password_expiry_warning'] = 180 - $daysOld;
}

        // If MFA is enabled or mandatory, complete MFA before creating the logged-in session.
        $mfaEnabled = !empty($user['mfa_enabled']);
        $mfaMandatory = !empty($user['mfa_mandatory']);
        if ($mfaEnabled || $mfaMandatory) {
            $_SESSION['mfa_pending_user_id'] = (int)$user['id'];
            dealerfai_security_log($db, 'login_success', [
                'user_id' => (int)$user['id'],
                'email'   => $email,
                'details' => ['mfa_pending' => true],
            ]);
            header("Location: mfa");
            exit;
        }

        $_SESSION['user_id'] = $user['id'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['email'] = $user['email'];
        // Store current org
        $_SESSION['organization'] = $user['organization'];

        // Load accessible orgs (default to current org only)
        $accessible_orgs = [$user['organization']];

        // Check for child orgs (if this is a parent organization)
        $org_stmt = $db->prepare("SELECT id FROM organizations WHERE parent_org_id = ?");
        $org_stmt->execute([$user['organization']]);
        $child_orgs = $org_stmt->fetchAll(PDO::FETCH_COLUMN);

        // Merge into accessible_orgs
        if (!empty($child_orgs)) {
            $accessible_orgs = array_merge($accessible_orgs, $child_orgs);
        }

        $_SESSION['accessible_orgs'] = $accessible_orgs;
        {
            $roles = json_decode($user['role'], true);
            if (!is_array($roles)) {
                $roles = [];
            }
            $_SESSION['roles'] = $roles;
        }
            dealerfai_security_log($db, 'login_success', [
                'user_id' => (int)$user['id'],
                'email'   => $email,
            ]);
            header("Location: dashboard");
            exit;
        } else {
            $error = "Invalid login credentials.";
            dealerfai_security_log($db, 'login_failure', ['email' => $email]);
        }
    }
}
$csrfToken = dealerfai_csrf_get_token();
$timeoutNotice = (isset($_GET['reason']) && $_GET['reason'] === 'timeout');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>DealerFAI Login</title>
  <?php include __DIR__ . "/includes/head_favicon.php"; ?>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php dealerfai_theme_head(); ?>
  <style nonce="<?= dealerfai_csp_nonce() ?>">
    main {
      max-width: 400px;
      margin: 40px auto;
      background: white;
      padding: 30px;
      border-radius: 8px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    }
    form {
      display: flex;
      flex-direction: column;
    }
  </style>
</head>
<body class="bg-ai">
  <div class="full-page-center">
    <header class="clean-header">
      <a href="index.php">
        <img src="dealerfai_logo_blue.png" alt="DealerFAI Logo" style="height: 100px;">
      </a>
    </header>

    <main class="glass" style="margin-top: 0; width: 100%; max-width: 420px;">
      <h2 class="text-gradient" style="margin-bottom: 1.5rem;">Welcome Back</h2>
      <p class="text-muted text-center" style="margin-bottom: 2rem; margin-top: -1rem;">Login to your AI-powered portal</p>
      
      <?php if (!empty($error)) echo "<div class='alert alert-danger'>" . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . "</div>"; ?>
      <?php if ($timeoutNotice): ?>
        <div class="alert alert-warning">Your session has expired. Please log in again.</div>
      <?php endif; ?>
      
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        
        <div class="form-group">
          <label for="email">Work Email</label>
          <input type="email" name="email" id="email" placeholder="name@company.com" required autofocus>
        </div>

        <div class="form-group">
          <label for="password">Password</label>
          <input type="password" name="password" id="password" placeholder="••••••••" required>
        </div>

        <div class="forgot-link" style="text-align: right; margin-top: 0.5rem;">
          <a href="reset_password.php" class="text-muted" style="font-size: 0.85rem;">Forgot password?</a>
        </div>

        <button type="submit" style="width: 100%; margin-top: 2rem;">Sign In to DealerFAI</button>
      </form>
    </main>

    <footer style="background: transparent; color: rgba(255,255,255,0.7); border: none; margin-top: 2rem; padding: 1rem;">
      &copy; <?php echo date("Y"); ?> DealerFAI. All rights reserved.
    </footer>
  </div>
</body>
</html>
