<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/security_headers.php';
require_once __DIR__ . '/includes/theme_head.php';
include 'db.php';
require_once __DIR__ . '/helpers/session_utils.php';

// Ensure admin access only
$roles = load_session_roles();
$isAdmin = in_array('Admin', $roles, true);
if (!$isAdmin) {
  echo "<p style='color:red;'>Access denied. Admins only.</p>";
  exit;
}
$adminAlertCount = get_admin_alert_count($db);

// Fetch organizations
$orgs = $db->query("SELECT id, name, logo_url, logic_type, theme_variant, parent_org_id, org_kind FROM organizations ORDER BY name ASC")
  ->fetchAll(PDO::FETCH_ASSOC);
$orgNames = [];
foreach ($orgs as $org) {
  $orgNames[(int) $org['id']] = $org['name'];
}
?>
<!DOCTYPE html>
<html>

<head>
  <?php include __DIR__ . "/includes/head_favicon.php"; ?>
  <meta charset="UTF-8">
  <title>DealerFAI - Admin: Organizations</title>
  <?php dealerfai_theme_head(); ?>
  <style nonce="<?= dealerfai_csp_nonce() ?>">
    body {
      font-family: Arial, sans-serif;
      background: #f4f6f8;
      margin: 0;
      padding: 0;
    }

    header {
      background: #0066cc;
      color: white;
      padding: 20px;
      text-align: center;
      position: relative;
    }

    nav {
      background-color: #0066cc;
      padding: 12px;
      text-align: center;
    }

    nav a {
      color: white;
      margin: 0 20px;
      text-decoration: none;
    }

    nav a:hover {
      text-decoration: underline;
    }

    .container {
      max-width: 900px;
      margin: 20px auto;
      background: white;
      padding: 20px;
      border-radius: 8px;
      box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
    }

    h2 {
      margin-top: 0;
    }

    table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 20px;
    }

    th,
    td {
      padding: 10px;
      border-bottom: 1px solid #ddd;
      text-align: left;
    }

    .btn {
      background: #0066cc;
      color: white;
      padding: 10px 18px;
      border: none;
      border-radius: 4px;
      margin-top: 20px;
      cursor: pointer;
      font-size: 16px;
      text-decoration: none;
      display: inline-block;
    }

    .btn:hover {
      background: #094c63;
    }

    .success {
      color: green;
      margin-top: 10px;
    }

    img.logo-preview {
      max-height: 40px;
      max-width: 120px;
    }

    .actions a {
      margin-right: 8px;
    }
  </style>
</head>

<body>
  <header>
    <h1>DealerFAI Admin</h1>
    <div class="logout">
      <a href="admin_error_alerts.php" class="nav-link-white">Alerts<?php if ($adminAlertCount > 0): ?> <span
            style="display:inline-block; min-width:18px; padding:2px 8px; border-radius:999px; background:#d7263d; color:#fff; font-size:12px; font-weight:bold; text-align:center; margin-left:6px;"><?= $adminAlertCount ?></span><?php endif; ?></a>
      <a href="logout.php" class="nav-link-white">Log Out</a>
    </div>
  </header>

  <nav>
    <a href="dashboard.php">Dashboard</a>
    <a href="view_deals.php">View Deals</a>
    <a href="create_deal.php">Create Deal</a>
    <a href="admin_tools.php">Admin Tools</a>
  </nav>

  <div class="container">
    <h2>Organizations</h2>
    <?php if (isset($_GET['added'])): ?>
      <p class="success">✅ Organization added successfully.</p>
    <?php endif; ?>
    <?php if (isset($_GET['updated'])): ?>
      <p class="success">✅ Organization updated successfully.</p>
    <?php endif; ?>

    <a class="btn" href="admin_organization_edit.php">➕ Add Organization</a>

    <table>
      <thead>
        <tr>
          <th>Name</th>
          <th>Type</th>
          <th>Parent Organization</th>
          <th>Logo</th>
          <th>Logic</th>
          <th>Theme</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($orgs as $org): ?>
          <tr>
            <td><?= htmlspecialchars($org['name']) ?></td>
            <td><?= htmlspecialchars($org['org_kind'] ?? 'store') ?></td>
            <td><?= htmlspecialchars($orgNames[(int) $org['parent_org_id']] ?? '—') ?></td>
            <td>
              <?php if (!empty($org['logo_url'])): ?>
                <img src="<?= $org['logo_url'] ?>" class="logo-preview">
              <?php endif; ?>
            </td>
            <td><?= htmlspecialchars($org['logic_type']) ?></td>
            <td><?= htmlspecialchars($org['theme_variant']) ?></td>
            <td class="actions">
              <a class="btn" href="admin_organization_edit.php?id=<?= (int) $org['id'] ?>">Edit</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</body>

</html>