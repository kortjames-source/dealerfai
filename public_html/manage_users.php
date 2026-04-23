<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/security_headers.php';
require_once __DIR__ . '/includes/theme_head.php';
require_once __DIR__ . '/includes/csrf.php';
include 'db.php';
require_once __DIR__ . '/helpers/session_utils.php';

// Check roles
$roles = load_session_roles();
$org_id = $_SESSION['organization'] ?? null;
$is_admin = in_array('Admin', $roles);
$is_manager = in_array('General Manager', $roles) || in_array('Sales Manager', $roles);

// Restrict access if no valid role
if (!$is_admin && !$is_manager) {
  echo "<p style='color:red;'>Access denied.</p>";
  exit;
}
$adminAlertCount = $is_admin ? get_admin_alert_count($db) : 0;

// Load organizations
$org_stmt = $is_admin
  ? $db->query("SELECT * FROM organizations ORDER BY name ASC")
  : $db->prepare("SELECT * FROM organizations WHERE id = ?");
$orgs = $is_admin ? $org_stmt->fetchAll(PDO::FETCH_ASSOC) : (function () use ($org_stmt, $org_id) {
  $org_stmt->execute([$org_id]);
  return $org_stmt->fetchAll(PDO::FETCH_ASSOC);
})();

// Load users grouped by organization
$user_stmt = $is_admin
  ? $db->query("SELECT * FROM users ORDER BY organization, email")
  : (function () use ($db, $org_id) {
      $stmt = $db->prepare("SELECT * FROM users WHERE organization = ? ORDER BY organization, email");
      $stmt->execute([$org_id]);
      return $stmt;
    })();
$users_by_org = [];
while ($user = $user_stmt->fetch(PDO::FETCH_ASSOC)) {
  $users_by_org[$user['organization']][] = $user;
}
$csrfToken = dealerfai_csrf_get_token();
?>
<!DOCTYPE html>
<html>
<head>
  <?php include __DIR__ . "/includes/head_favicon.php"; ?>
<meta charset="UTF-8">
  <title>Manage Users & Organizations</title>
  <?php dealerfai_theme_head(); ?>
  <style nonce="<?= dealerfai_csp_nonce() ?>">
    body { font-family: Arial, sans-serif; background: #f4f6f8; margin: 0; padding: 0; }
    header { background: #0a2e36; color: white; padding: 20px; text-align: center; position: relative; }
    nav {
      background: #004d61;
      display: flex;
      justify-content: center;
      gap: 20px;
      padding: 10px;
    }
    nav a {
      color: white;
      text-decoration: none;
      font-weight: bold;
    }
    nav a:hover { text-decoration: underline; }

    .container { max-width: 900px; margin: 30px auto; background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
    details { margin-bottom: 20px; border: 1px solid #ddd; border-radius: 5px; padding: 10px; }
    summary { font-weight: bold; font-size: 18px; cursor: pointer; }
    table { width: 100%; margin-top: 10px; border-collapse: collapse; }
    th, td { padding: 8px; border-bottom: 1px solid #ddd; text-align: left; }
    .btn { background: #0a6280; color: white; padding: 6px 10px; text-decoration: none; border-radius: 4px; font-size: 13px; display: inline-block; }
    .btn:hover { background: #094c63; }
    .top-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
  </style>
</head>
<body>
<header>
  <h1>DealerFAI – Manage Users</h1>
  <div class="logout">
    <?php if ($is_admin): ?>
      <a href="admin_error_alerts.php" class="nav-link-white">Alerts<?php if ($adminAlertCount > 0): ?> <span style="display:inline-block; min-width:18px; padding:2px 8px; border-radius:999px; background:#d7263d; color:#fff; font-size:12px; font-weight:bold; text-align:center; margin-left:6px;"><?= $adminAlertCount ?></span><?php endif; ?></a>
    <?php endif; ?>
    <a href="logout.php" class="nav-link-white">Log Out</a>
  </div>
</header>

<nav>
  <a href="dashboard.php">Dashboard</a>
  <a href="view_deals.php">Deals</a>
  <a href="manage_users.php">Users</a>
  <a href="admin_organizations.php">Organizations</a>
</nav>

<div class="container">
  <div class="top-bar">
    <h2>Organizations & Users</h2>
    <?php if ($is_admin): ?>
      <a href="admin_organizations.php" class="btn">➕ Create New Organization</a>
    <?php endif; ?>
  </div>

  <?php foreach ($orgs as $org): ?>
    <details>
      <summary><?= htmlspecialchars($org['name']) ?></summary>

      <?php if ($is_admin || $org['id'] == $org_id): ?>
        <a href="user_create.php?org=<?= $org['id'] ?>" class="btn" class="mb-10">➕ Add User</a>
      <?php endif; ?>

      <table>
        <thead>
<tr><th>Email</th><th>Roles</th><th>MFA</th><th>Actions</th></tr>
        </thead>
        <tbody>
          <?php foreach ($users_by_org[$org['id']] ?? [] as $user): ?>
            <tr>
  <td><?= htmlspecialchars($user['email'] ?? '') ?></td>
  <td>
    <?php
      $user_roles = json_decode($user['role'] ?? '[]', true);
      echo $user_roles && is_array($user_roles) ? htmlspecialchars(implode(', ', $user_roles)) : '—';
    ?>
  </td>
  <td>
    <?php
      $mfa_mandatory = (int)($user['mfa_mandatory'] ?? 0);
      $mfa_enabled  = (int)($user['mfa_enabled'] ?? 0);
      $has_secret = !empty($user['mfa_secret_ciphertext']) || !empty($user['mfa_secret']);
      if ($mfa_mandatory && $mfa_enabled) echo "✅ Enabled (Required)";
      elseif ($mfa_mandatory && $has_secret) echo "⚠️ Required (Enroll/Verify)";
      elseif ($mfa_mandatory) echo "⚠️ Required (Pending)";
      elseif ($mfa_enabled) echo "✅ Enabled";
      else echo "—";
    ?>
  </td>
  <td>
    <?php if ($is_admin || $org['id'] == $org_id): ?>
      <a href="edit_user.php?id=<?= $user['id'] ?>" class="btn">✏️ Edit</a>
      <?php if ($is_admin): ?>
        <form method="post" action="update_mfa.php" class="d-inline">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
          <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
          <button name="action" value="require" class="btn">🔒 Require</button>
          <button name="action" value="reset" class="btn">♻️ Reset</button>
          <button name="action" value="disable" class="btn">🚫 Disable</button>
        </form>
      <?php endif; ?>
    <?php endif; ?>
  </td>
</tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </details>
  <?php endforeach; ?>
</div>
</body>
</html>
