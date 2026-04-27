<?php
include 'auth.php';
require_once __DIR__ . '/includes/theme_head.php';
include 'db.php';
require_once __DIR__ . '/helpers/session_utils.php';
require_once __DIR__ . '/includes/csrf.php';
$org_id = $_GET['org'] ?? null;
$roles = load_session_roles();
$is_admin = in_array('Admin', $roles, true);
$is_gm = in_array('General Manager', $roles, true);
$is_manager = $is_admin || $is_gm;
$adminAlertCount = $is_admin ? get_admin_alert_count($db) : 0;
$user_org = $_SESSION['organization'];
if (!$is_manager) {
  http_response_code(403);
  die('Access denied.');
}

$errors = [];
$csrfToken = dealerfai_csrf_get_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!dealerfai_csrf_validate($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    die('Invalid request.');
  }
  $password = $_POST['password'];

  // Password rules
  if (
    strlen($password) < 8 ||
    !preg_match('/[A-Za-z]/', $password) ||
    !preg_match('/\d/', $password) ||
    !preg_match('/[^A-Za-z0-9]/', $password)
  ) {
    $errors[] = "Password must be at least 8 characters and include a letter, number, and special character.";
  }

  if (empty($errors)) {
    $org = $is_admin ? ($_POST['organization'] ?? $user_org) : $user_org;
    $postedRoles = $_POST['role'] ?? [];
    if (!is_array($postedRoles)) {
      $postedRoles = [];
    }
    if (!$is_admin) {
      $postedRoles = array_values(array_filter($postedRoles, static fn($r) => $r !== 'Admin'));
    }

    $stmt = $db->prepare("INSERT INTO users 
      (full_name, email, password, organization, role, created_at, password_last_set) 
      VALUES (?, ?, ?, ?, ?, NOW(), NOW())");

    $stmt->execute([
      $_POST['full_name'],
      $_POST['email'],
      password_hash($password, PASSWORD_DEFAULT),
      $org,
      json_encode($postedRoles)
    ]);

    header('Location: manage_users.php');
    exit;
  }
}

$orgs = [];
if ($is_admin) {
  $orgstmt = $db->query("SELECT id, name FROM organizations ORDER BY name");
  $orgs = $orgstmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html>
<head>
  <?php include __DIR__ . "/includes/head_favicon.php"; ?>
<meta charset="UTF-8">
  <title>Create User - DealerFAI</title>
  <?php dealerfai_theme_head(); ?>
  <style nonce="<?= dealerfai_csp_nonce() ?>">
    body { font-family: Arial, sans-serif; background: #f4f6f8; padding: 40px; }
    .card {
      max-width: 700px; margin: auto; background: white;
      padding: 30px; border-radius: 8px;
      box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    }
    h2 { color: #0066cc; margin-top: 0; }
    label { display: block; margin-top: 15px; font-weight: bold; }
    input, select {
      width: 100%; padding: 10px; margin-top: 5px;
      border-radius: 4px; border: 1px solid #ccc;
    }
    .checkbox-group { margin-top: 10px; }
    .checkbox-group label { display: block; font-weight: normal; }
    button {
      background: #0066cc; color: white;
      border: none; padding: 12px 20px; margin-top: 20px;
      font-size: 16px; border-radius: 4px; cursor: pointer;
    }
    button:hover { background: #094c63; }
    .error { color: red; margin-top: 10px; }
  </style>
</head>
<body>
  <div class="card-header-bar">
    <div class="fw-bold">DealerFAI Admin</div>
    <div class="logout">
      <?php if ($is_admin): ?>
        <a href="admin_error_alerts.php" class="nav-link-white">Alerts<?php if ($adminAlertCount > 0): ?> <span style="display:inline-block; min-width:18px; padding:2px 8px; border-radius:999px; background:#d7263d; color:#fff; font-size:12px; font-weight:bold; text-align:center; margin-left:6px;"><?= $adminAlertCount ?></span><?php endif; ?></a>
      <?php endif; ?>
      <a href="logout.php" class="nav-link-white">Log Out</a>
    </div>
  </div>
  <div class="card">
    <h2>Create New User</h2>

    <?php if (!empty($errors)): ?>
      <div class="error"><?= implode('<br>', $errors) ?></div>
    <?php endif; ?>

    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
      <label>Full Name</label>
      <input type="text" name="full_name" required>

      <label>Email</label>
      <input type="email" name="email" required>

      <label>Password</label>
      <input type="password" name="password" required>
      <small>Password must be at least 8 characters and include a letter, number, and special character.</small>

      <?php if ($is_admin): ?>
        <label>Organization</label>
        <select name="organization">
          <?php foreach ($orgs as $org): ?>
            <option value="<?= $org['id'] ?>" <?= ($org_id == $org['id']) ? 'selected' : '' ?>>
              <?= htmlspecialchars($org['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      <?php else: ?>
        <input type="hidden" name="organization" value="<?= htmlspecialchars($user_org) ?>">
        <p><strong>Organization:</strong> <?= htmlspecialchars($user_org) ?></p>
      <?php endif; ?>

      <label>Role(s)</label>
      <div class="checkbox-group">
        <label><input type="checkbox" name="role[]" value="Salesperson"> Salesperson</label>
        <label><input type="checkbox" name="role[]" value="Finance Manager"> Finance Manager</label>
        <label><input type="checkbox" name="role[]" value="Sales Manager"> Sales Manager</label>
        <label><input type="checkbox" name="role[]" value="General Manager"> General Manager</label>
        <?php if ($is_admin): ?>
          <label><input type="checkbox" name="role[]" value="Admin"> Admin</label>
        <?php endif; ?>
      </div>

      <button type="submit">Create User</button>
    </form>
  </div>
</body>
</html>
