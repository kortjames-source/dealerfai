<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/security_headers.php';
require_once __DIR__ . '/includes/theme_head.php';
require_once __DIR__ . '/includes/csrf.php';
include 'db.php';
require_once __DIR__ . '/helpers/db_utils.php';
require_once __DIR__ . '/helpers/security_logger.php';

$sessionUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$tempUserId = isset($_SESSION['temp_user_id']) ? (int)$_SESSION['temp_user_id'] : 0;
$user_id = $sessionUserId > 0 ? $sessionUserId : $tempUserId;
if ($user_id <= 0) {
  header('Location: login.php');
  exit;
}

// Check if form is submitted
$error = '';
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!dealerfai_csrf_validate($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    die('Invalid request.');
  }

  $new_password = $_POST['new_password'];
  $confirm_password = $_POST['confirm_password'];

  // Validate password
  if ($new_password !== $confirm_password) {
    $error = "Passwords do not match.";
  } elseif (!preg_match('/^(?=.*[A-Za-z])(?=.*\d)(?=.*[\W_]).{8,}$/', $new_password)) {
    $error = "Password must be at least 8 characters and include a letter, number, and special character.";
  } else {
    $hashed = password_hash($new_password, PASSWORD_DEFAULT);
    $hasPasswordUpdated = column_exists($db, 'users', 'password_updated_at');
    $setPieces = [
      'password = ?',
      'password_last_set = NOW()',
    ];
    if ($hasPasswordUpdated) {
      $setPieces[] = 'password_updated_at = NOW()';
    }
    $updateSql = 'UPDATE users SET ' . implode(', ', $setPieces) . ' WHERE id = ?';
    $stmt = $db->prepare($updateSql);
    $stmt->execute([$hashed, $user_id]);
    $success = "Password updated successfully!";
    dealerfai_security_log($db, 'password_changed', [
        'user_id' => $user_id,
        'details' => ['reason' => 'forced_reset'],
    ]);
    unset($_SESSION['temp_user_id']);

    // Complete login when this reset was triggered from an expired-password login.
    if ($sessionUserId <= 0) {
      $userStmt = $db->prepare("SELECT id, full_name, email, role, organization FROM users WHERE id = ? LIMIT 1");
      $userStmt->execute([$user_id]);
      $user = $userStmt->fetch(PDO::FETCH_ASSOC);
      if (!$user) {
        session_destroy();
        header('Location: login.php');
        exit;
      }

      session_regenerate_id(true);
      $_SESSION['user_id'] = (int)$user['id'];
      $_SESSION['full_name'] = $user['full_name'];
      $_SESSION['email'] = $user['email'];
      $_SESSION['organization'] = $user['organization'];

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
    }

    header("Location: dashboard.php");
    exit;
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
      font-family: "Segoe UI", sans-serif;
      background-color: #f4f6f8;
      margin: 0;
    }
    header {
      background-color: #0a2e36;
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
      max-width: 500px;
      margin: 40px auto;
      background: white;
      padding: 30px;
      border-radius: 8px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    }
    h2 {
      text-align: center;
      color: #0a6280;
    }
    label {
      display: block;
      margin-top: 15px;
      font-weight: bold;
    }
    input[type="password"] {
      width: 100%;
      padding: 10px;
      margin-top: 5px;
      font-size: 16px;
      border-radius: 4px;
      border: 1px solid #ccc;
    }
    button {
      margin-top: 25px;
      padding: 12px;
      background-color: #0a6280;
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
    footer {
      background-color: #0a2e36;
      color: white;
      text-align: center;
      padding: 16px;
      font-size: 14px;
      margin-top: 60px;
    }
  </style>
</head>
<body>

<header>
  <img src="dealerfai_logo.png" alt="DealerFAI Logo">
  <h1>DealerFAI</h1>
</header>

<main>
  <h2>Reset Your Password</h2>
  <?php if ($error): ?>
    <div class="message error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
  <?php elseif ($success): ?>
    <div class="message success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <label for="new_password">New Password</label>
    <input type="password" name="new_password" id="new_password" required pattern="^(?=.*[A-Za-z])(?=.*\d)(?=.*[\W_]).{8,}$" title="At least 8 characters, including a letter, number, and special character.">

    <label for="confirm_password">Confirm Password</label>
    <input type="password" name="confirm_password" id="confirm_password" required>

    <button type="submit">Update Password</button>
  </form>
</main>

<footer>
  &copy; <?= date("Y") ?> DealerFAI. All rights reserved.
</footer>

</body>
</html>
