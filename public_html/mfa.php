<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/security_headers.php';
require_once __DIR__ . '/includes/theme_head.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/helpers/totp.php';
require_once __DIR__ . '/helpers/mfa_secret_store.php';
require_once __DIR__ . '/helpers/security_logger.php';
include 'db.php';

// User can arrive here either:
// - during login as a pending user (mfa_pending_user_id), or
// - while logged in and enabling MFA.
$pendingId = isset($_SESSION['mfa_pending_user_id']) ? (int)$_SESSION['mfa_pending_user_id'] : 0;
$loggedInId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$userId = $pendingId ?: $loggedInId;
if ($userId <= 0) {
    header('Location: login');
    exit;
}

$stmt = $db->prepare("SELECT id, full_name, email, role, organization, mfa_enabled, mfa_mandatory FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    session_destroy();
    header('Location: login');
    exit;
}

$email = (string)($user['email'] ?? '');
$issuer = 'DealerFAI';
$secret = dealerfai_mfa_get_or_create_secret($db, $userId);
$otpauth = 'otpauth://totp/' . rawurlencode($issuer . ':' . $email)
    . '?secret=' . rawurlencode($secret)
    . '&issuer=' . rawurlencode($issuer)
    . '&algorithm=SHA1&digits=6&period=30';
$isEnrollment = empty($user['mfa_enabled']);

$error = '';

// Basic brute-force throttling per session.
if (!isset($_SESSION['mfa_attempts']) || !is_array($_SESSION['mfa_attempts'])) {
    $_SESSION['mfa_attempts'] = ['count' => 0, 'since' => time()];
}
$attempts = $_SESSION['mfa_attempts'];
if (!is_int($attempts['count'] ?? null) || !is_int($attempts['since'] ?? null)) {
    $attempts = ['count' => 0, 'since' => time()];
}
if ((time() - $attempts['since']) > 600) {
    $attempts = ['count' => 0, 'since' => time()];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!dealerfai_csrf_validate($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        die('Invalid request.');
    }

    if (($attempts['count'] ?? 0) >= 10) {
        // Force re-auth on too many failures.
        unset($_SESSION['mfa_pending_user_id']);
        $_SESSION['mfa_attempts'] = ['count' => 0, 'since' => time()];
        session_regenerate_id(true);
        header('Location: login');
        exit;
    }

    $code = (string)($_POST['code'] ?? '');
    if (!dealerfai_totp_verify($secret, $code, 1, 6, 30)) {
        $attempts['count'] = (int)($attempts['count'] ?? 0) + 1;
        $_SESSION['mfa_attempts'] = $attempts;
        $error = 'Invalid code. Please try again.';
        dealerfai_security_log($db, 'mfa_failure', [
            'user_id' => $userId,
            'email'   => $email,
            'details' => ['attempts' => $attempts['count']],
        ]);
    } else {
        // Mark enabled.
        $up = $db->prepare("UPDATE users SET mfa_enabled = 1, mfa_confirmed_at = NOW() WHERE id = ?");
        $up->execute([$userId]);

        // If this was part of login, complete the login now.
        if ($pendingId) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['organization'] = $user['organization'];

            // Load accessible orgs (default to current org only).
            $accessible_orgs = [$user['organization']];
            $org_stmt = $db->prepare("SELECT id FROM organizations WHERE parent_org_id = ?");
            $org_stmt->execute([$user['organization']]);
            $child_orgs = $org_stmt->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($child_orgs)) {
                $accessible_orgs = array_merge($accessible_orgs, $child_orgs);
            }
            $_SESSION['accessible_orgs'] = $accessible_orgs;

            $roles = json_decode((string)($user['role'] ?? '[]'), true);
            $_SESSION['roles'] = is_array($roles) ? $roles : [];

            unset($_SESSION['mfa_pending_user_id']);
        }

        dealerfai_security_log($db, 'mfa_success', [
            'user_id' => $userId,
            'email'   => $email,
        ]);
        $_SESSION['mfa_attempts'] = ['count' => 0, 'since' => time()];
        header('Location: dashboard');
        exit;
    }
}

$csrf = dealerfai_csrf_get_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>MFA Verification</title>
  <?php include __DIR__ . "/includes/head_favicon.php"; ?>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php dealerfai_theme_head(); ?>
  <style nonce="<?= dealerfai_csp_nonce() ?>">
    body { margin:0; font-family: "Segoe UI", sans-serif; background:#f4f6f8; }
    header { background:#0a2e36; color:#fff; padding:24px; text-align:center; }
    main { max-width:520px; margin:30px auto; background:#fff; padding:26px; border-radius:10px; box-shadow:0 2px 10px rgba(0,0,0,0.08); }
    .help { color:#444; font-size:14px; line-height:1.4; }
    .secret { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace; letter-spacing:1px; padding:10px 12px; background:#f7f7f7; border:1px solid #e2e2e2; border-radius:8px; display:inline-block; }
    .qr { margin-top:12px; display:flex; justify-content:center; }
    .qr img { width:240px; height:240px; border:1px solid #e2e2e2; border-radius:8px; background:#fff; }
    label { display:block; margin-top:16px; font-weight:700; }
    input[type="text"] { width:100%; padding:12px 10px; font-size:18px; border:1px solid #ccc; border-radius:8px; }
    button { margin-top:18px; padding:12px 16px; background:#0a6280; color:#fff; border:none; border-radius:8px; font-size:16px; cursor:pointer; }
    button:hover { background:#094c63; }
    .error { color:#b00020; margin-top:12px; font-weight:600; }
    .link { margin-top:12px; font-size:14px; }
    .link a { color:#0a6280; }
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
      <h2 class="text-gradient" style="margin-bottom: 1rem;">MFA Verification</h2>
      <p class="text-muted text-center" style="margin-bottom: 2rem;">Secure your account with two-factor authentication.</p>

      <?php if ($isEnrollment): ?>
        <details class="mt-4 mb-4" style="background: rgba(255,255,255,0.4); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 1rem;" open>
          <summary style="cursor:pointer; font-weight:700; color: var(--brand-color);">First-Time Setup</summary>
          <div style="padding-top: 1rem;">
            <p class="help text-muted" style="font-size: 0.85rem; margin-bottom: 1rem;">Scan this QR code or enter the key manually in your authenticator app (Google Authenticator, Authy, etc).</p>
            <div class="qr" style="display: flex; justify-content: center; margin-bottom: 1.5rem;">
              <img src="https://api.qrserver.com/v1/create-qr-code/?size=240x240&data=<?= urlencode($otpauth) ?>" alt="MFA QR Code" style="border-radius: 8px; border: 1px solid var(--border-color); width: 180px; height: 180px;">
            </div>
            <div class="text-center">
              <label style="margin-top: 0; margin-bottom: 0.5rem;">Setup Key</label>
              <div class="secret" style="background: white; border: 1px solid var(--border-color); padding: 0.5rem 1rem; border-radius: 4px; font-family: monospace; font-size: 0.9rem;"><?= htmlspecialchars($secret) ?></div>
            </div>
          </div>
        </details>
      <?php endif; ?>

      <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="post" autocomplete="one-time-code" style="margin-top: 1rem;">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <div class="form-group">
          <label for="code">Enter 6-Digit Code</label>
          <input id="code" name="code" type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" placeholder="000000" style="text-align: center; font-size: 2rem; letter-spacing: 0.5rem; font-weight: 700;" required autofocus>
        </div>
        <button type="submit" style="width: 100%; margin-top: 2rem;">Verify & Continue</button>
      </form>

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
