<?php
include 'auth.php';
require_once __DIR__ . '/includes/theme_head.php';
include 'db.php';
require_once __DIR__ . '/helpers/session_utils.php';
require_once __DIR__ . '/includes/csrf.php';

$user_id = $_GET['id'] ?? null;
if (!$user_id) { die('Missing user ID.'); }

$session_roles = load_session_roles();
$is_admin = in_array('Admin', $session_roles, true);
$is_gm = in_array('General Manager', $session_roles, true);
$is_manager = $is_admin || $is_gm;
$adminAlertCount = $is_admin ? get_admin_alert_count($db) : 0;
$org_id = $_SESSION['organization'] ?? null;
if (!$is_manager) {
  http_response_code(403);
  die('Access denied.');
}

$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) { die('User not found.'); }
if (!$is_admin && (int)$user['organization'] !== (int)$org_id) {
  http_response_code(403);
  die('Access denied.');
}

$user_roles = json_decode($user['role'] ?? '[]', true);
$csrfToken = dealerfai_csrf_get_token();

$orgs = $db->query("SELECT id, name FROM organizations ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!dealerfai_csrf_validate($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    die('Invalid request.');
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user']) && $is_manager) {
  if (!$is_admin && in_array('Admin', $user_roles, true)) {
    http_response_code(403);
    die('Access denied.');
  }
  $del = $db->prepare("DELETE FROM users WHERE id = ?");
  $del->execute([$user_id]);
  header("Location: manage_users.php");
  exit;
}

// Handle update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['delete_user'])) {
  $roles_posted = $_POST['role'] ?? [];
  if (!is_array($roles_posted)) { $roles_posted = []; }
  if (!$is_admin) {
    $roles_posted = array_values(array_filter($roles_posted, static fn($r) => $r !== 'Admin'));
  }

  $encoded_roles = json_encode(array_values($roles_posted));

  $full_name = $_POST['full_name'];
  $email = $_POST['email'];
  $organization = $is_admin ? $_POST['organization'] : $org_id;
  $new_password = $_POST['password'];

  if (!empty($new_password)) {
    if (
      strlen($new_password) < 8 ||
      !preg_match('/[A-Za-z]/', $new_password) ||
      !preg_match('/[0-9]/', $new_password) ||
      !preg_match('/[\W]/', $new_password)
    ) {
      die("❌ Password must be at least 8 characters long and include at least one letter, one number, and one special character.");
    }

    $update = $db->prepare("UPDATE users SET full_name = ?, email = ?, organization = ?, role = ?, password = ? WHERE id = ?");
    $update->execute([
      $full_name,
      $email,
      $organization,
      $encoded_roles,
      password_hash($new_password, PASSWORD_DEFAULT),
      $user_id
    ]);
  } else {
    $update = $db->prepare("UPDATE users SET full_name = ?, email = ?, organization = ?, role = ? WHERE id = ?");
    $update->execute([
      $full_name,
      $email,
      $organization,
      $encoded_roles,
      $user_id
    ]);
  }

  header("Location: manage_users.php");
  exit;
}
?>

<!DOCTYPE html>
<html>
<head>
  <?php include __DIR__ . "/includes/head_favicon.php"; ?>
<meta charset="UTF-8">
  <title>Edit User - DealerFAI</title>
  <?php dealerfai_theme_head(); ?>
  <style nonce="<?= dealerfai_csp_nonce() ?>">
    body {
      font-family: Arial, sans-serif;
      background: #f4f6f8;
      padding: 40px;
    }
    .card {
      max-width: 700px;
      margin: auto;
      background: white;
      padding: 30px;
      border-radius: 8px;
      box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    }
    h2 {
      color: #0a6280;
      margin-top: 0;
    }
    label {
      display: block;
      margin-top: 15px;
      font-weight: bold;
    }
    input, select {
      width: 100%;
      padding: 10px;
      margin-top: 5px;
      border-radius: 4px;
      border: 1px solid #ccc;
    }
    .checkbox-group {
      margin-top: 10px;
    }
    .checkbox-group label {
      display: block;
      font-weight: normal;
    }
    button {
      background: #0a6280;
      color: white;
      border: none;
      padding: 12px 20px;
      margin-top: 20px;
      font-size: 16px;
      border-radius: 4px;
      cursor: pointer;
    }
    button:hover {
      background: #094c63;
    }
    .delete-btn {
      background: #c82333;
      margin-top: 30px;
    }
  </style>
  <script nonce="<?= dealerfai_csp_nonce() ?>">
    function validateForm() {
      const pwd = document.querySelector('input[name="password"]').value;
      if (pwd && (
        pwd.length < 8 ||
        !/[A-Za-z]/.test(pwd) ||
        !/[0-9]/.test(pwd) ||
        !/[\W]/.test(pwd)
      )) {
        alert("Password must be at least 8 characters and include a letter, number, and special character.");
        return false;
      }
      return true;
    }
  </script>
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
    <h2>Edit User</h2>
    <form method="post" onsubmit="return validateForm();">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
      <label>Full Name</label>
      <input type="text" name="full_name" value="<?= htmlspecialchars($user['full_name']) ?>" required>

      <label>Email</label>
      <input type="email" name="email" value="<?= htmlspecialchars($user['email']) ?>" required>

      <label>New Password (leave blank to keep current)</label>
      <input type="password" name="password" placeholder="Min 8 chars, letter, number, symbol">

      <?php if ($is_admin): ?>
        <label>Organization</label>
        <select name="organization" required>
          <?php foreach ($orgs as $org): ?>
            <option value="<?= $org['id'] ?>" <?= ($org['id'] == $user['organization']) ? 'selected' : '' ?>>
              <?= htmlspecialchars($org['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      <?php else: ?>
        <input type="hidden" name="organization" value="<?= htmlspecialchars((string)$org_id) ?>">
        <p><strong>Organization:</strong> <?= htmlspecialchars((string)$org_id) ?></p>
      <?php endif; ?>

      <label>Role(s)</label>
      <div class="checkbox-group">
        <label><input type="checkbox" name="role[]" value="Salesperson" <?= in_array("Salesperson", $user_roles) ? "checked" : "" ?>> Salesperson</label>
        <label><input type="checkbox" name="role[]" value="Finance Manager" <?= in_array("Finance Manager", $user_roles) ? "checked" : "" ?>> Finance Manager</label>
        <label><input type="checkbox" name="role[]" value="Sales Manager" <?= in_array("Sales Manager", $user_roles) ? "checked" : "" ?>> Sales Manager</label>
        <label><input type="checkbox" name="role[]" value="General Manager" <?= in_array("General Manager", $user_roles) ? "checked" : "" ?>> General Manager</label>
        <?php if ($is_admin): ?>
          <label><input type="checkbox" name="role[]" value="Admin" <?= in_array("Admin", $user_roles) ? "checked" : "" ?>> Admin</label>
        <?php endif; ?>
      </div>

      <button type="submit">💾 Save Changes</button>
    </form>

    <?php if ($is_admin || $is_gm): ?>
    <form method="post" onsubmit="return confirm('Are you sure you want to delete this user? This cannot be undone.');">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
      <input type="hidden" name="delete_user" value="1">
      <button type="submit" class="delete-btn">🗑️ Delete User</button>
    </form>
    <?php endif; ?>
  </div>
</body>
</html>
