<?php
include 'auth.php';
require_once __DIR__ . '/includes/theme_head.php';
include 'db.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/helpers/session_utils.php';
require_once __DIR__ . '/helpers/theme.php';

$expiry_warning = null;
if (isset($_SESSION['password_expiry_warning'])) {
  $expiry_warning = $_SESSION['password_expiry_warning'];
  unset($_SESSION['password_expiry_warning']); // Only show once
}

$navRoles = load_session_roles();
$isAdmin = in_array('Admin', $navRoles, true);
$adminAlertCount = 0;
if ($isAdmin) {
  try {
    $alertStmt = $db->query("SELECT COUNT(*) FROM admin_error_alerts WHERE is_resolved = 0");
    $adminAlertCount = (int) $alertStmt->fetchColumn();
  } catch (PDOException $e) {
    $adminAlertCount = 0;
  }
}
$accessible_orgs = get_accessible_organizations();
$orgContextId = get_admin_organization_context();

if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['context_org'])) {
  if (!dealerfai_csrf_validate($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    die('Invalid request.');
  }

  $selectedOrg = (int) $_POST['context_org'];
  set_admin_organization_context($selectedOrg > 0 ? $selectedOrg : null);
  header('Location: dashboard');
  exit;
}
$csrfToken = dealerfai_csrf_get_token();

$org_id = get_effective_organization();
$theme = dealerfai_get_theme_palette(null, ['color' => '#0a2e36']);

if ($org_id) {
  $stmt = $db->prepare("SELECT logo_url, theme_variant FROM organizations WHERE id = ?");
  $stmt->execute([$org_id]);
  $org = $stmt->fetch(PDO::FETCH_ASSOC);
  if ($org) {
    $theme = dealerfai_get_theme_palette($org['theme_variant'] ?? null, ['logo' => $org['logo_url'] ?? '']);
  }
}

$orgNames = [];
$orgNameLookup = [];
if (!empty($accessible_orgs)) {
  $placeholders = implode(',', array_fill(0, count($accessible_orgs), '?'));
  $stmt = $db->prepare("SELECT id, name FROM organizations WHERE id IN ($placeholders) ORDER BY name");
  $stmt->execute($accessible_orgs);
  $orgNames = $stmt->fetchAll(PDO::FETCH_ASSOC);
  foreach ($orgNames as $orgOption) {
    $orgNameLookup[(int) $orgOption['id']] = $orgOption['name'];
  }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <?php include __DIR__ . "/includes/head_favicon.php"; ?>
  <meta charset="UTF-8">
  <title>DealerFAI Dashboard</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php dealerfai_theme_head($theme ?? null); ?>
  <style nonce="<?= dealerfai_csp_nonce() ?>">
    body {
      margin: 0;
      font-family: "Segoe UI", sans-serif;
      background-color:
        <?= htmlspecialchars($theme['page_background']) ?>
      ;
      color: #111111;
    }

    header {
      background-color:
        <?= htmlspecialchars($theme['header_background']) ?>
      ;
      color:
        <?= htmlspecialchars($theme['header_text']) ?>
      ;
      padding: 30px 40px;
      text-align: center;
      position: relative;
    }

    header img {
      max-width: 480px;
      max-height: 180px;
      height: auto;
      display: block;
      margin: 0 auto 10px;
    }

    nav {
      background-color:
        <?= htmlspecialchars($theme['nav_background']) ?>
      ;
      padding: 12px;
      text-align: center;
    }

    nav a {
      color:
        <?= htmlspecialchars($theme['nav_text']) ?>
      ;
      margin: 0 20px;
      text-decoration: none;
      font-weight: bold;
    }

    nav a:hover {
      text-decoration: underline;
    }

    main {
      padding: 40px;
      text-align: center;
    }

    .card-container {
      display: flex;
      flex-wrap: wrap;
      justify-content: center;
      gap: 25px;
    }

    .card {
      background: white;
      padding: 25px;
      width: 260px;
      border-radius: 10px;
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    }

    .card h3 {
      color:
        <?= htmlspecialchars($theme['color']) ?>
      ;
      margin-bottom: 10px;
    }

    .card p {
      font-size: 14px;
    }

    footer {
      background-color:
        <?= htmlspecialchars($theme['color']) ?>
      ;
      color: white;
      text-align: center;
      padding: 16px;
      font-size: 14px;
      position: fixed;
      width: 100%;
      bottom: 0;
    }

    .logout {
      position: absolute;
      right: 20px;
      top: 20px;
      display: flex;
      gap: 12px;
      align-items: center;
    }

    .logout a {
      color: #ccc;
      font-size: 14px;
      text-decoration: none;
    }

    .logout a:hover {
      color: white;
    }

    .admin-header-links {
      display: flex;
      justify-content: center;
      gap: 12px;
      margin-top: 8px;
      padding: 8px 0;
    }

    .admin-header-links a {
      background: #093748;
      color: white;
      padding: 10px 16px;
      border-radius: 4px;
      font-size: 14px;
      text-decoration: none;
      font-weight: bold;
      border: 1px solid rgba(255, 255, 255, 0.4);
    }

    .admin-header-links a:hover {
      background: #074355;
      border-color: #0a6280;
    }

    .badge {
      display: inline-block;
      min-width: 18px;
      padding: 2px 8px;
      border-radius: 999px;
      background: #d7263d;
      color: #fff;
      font-size: 12px;
      font-weight: bold;
      text-align: center;
      margin-left: 6px;
    }

    .org-switcher {
      margin: 20px auto;
      text-align: center;
      display: flex;
      justify-content: center;
      align-items: center;
      gap: 12px;
      flex-wrap: wrap;
    }

    .org-switcher select {
      padding: 8px 12px;
      border-radius: 4px;
      border: 1px solid #ccc;
      min-width: 220px;
    }

    .org-switcher button {
      padding: 9px 16px;
      border: none;
      border-radius: 4px;
      background-color: #1A1A1A;
      color: white;
      font-weight: bold;
      cursor: pointer;
    }

    .org-switcher button:hover {
      opacity: 0.9;
    }

    .context-note {
      text-align: center;
      margin-top: 8px;
      color: #1f3d5e;
      font-weight: 600;
    }
  </style>
</head>

<body>
  <header>
    <?php if (!empty($theme['logo'])): ?>
      <img src="<?= htmlspecialchars($theme['logo']) ?>" alt="Dealer Logo">
    <?php else: ?>
      <h1>DealerFAI</h1>
    <?php endif; ?>
    <div class="logout">
      <?php if ($isAdmin): ?>
        <a href="admin_error_alerts">Alerts<?php if ($adminAlertCount > 0): ?> <span
              class="badge"><?= $adminAlertCount ?></span><?php endif; ?></a>
      <?php endif; ?>
      <a href="logout">Log Out</a>
    </div>
  </header>

  <nav>
    <a href="dashboard">Dashboard</a>
    <a href="view_deals">View Deals</a>
    <a href="create_deal">Create Deal</a>
    <?php if ($isAdmin): ?>
      <a href="admin_scoring_log">Scoring Log</a>
    <?php endif; ?>
    <?php if (in_array('General Manager', $navRoles) || in_array('Admin', $navRoles)): ?>
      <a href="admin_tools">Admin Tools</a>
    <?php endif; ?>
  </nav>
  <?php if ($isAdmin): ?>
    <form method="post" class="org-switcher">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
      <label for="context_org">Switch organization:</label>
      <select id="context_org" name="context_org">
        <option value="0" <?= $orgContextId ? '' : 'selected' ?>>All organizations (admin)</option>
        <?php foreach ($orgNames as $orgOption): ?>
          <option value="<?= $orgOption['id'] ?>" <?= ($orgContextId === (int) $orgOption['id']) ? 'selected' : '' ?>>
            <?= htmlspecialchars($orgOption['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <button type="submit">Switch</button>
    </form>
    <?php if ($orgContextId && isset($orgNameLookup[$orgContextId])): ?>
      <p class="context-note">You are currently working within <?= htmlspecialchars($orgNameLookup[$orgContextId]) ?>.</p>
    <?php endif; ?>
    <div class="admin-header-links">
      <a href="manage_users">Users</a>
      <a href="admin_organizations">Organizations</a>
      <a href="admin_error_alerts">Alerts<?php if ($adminAlertCount > 0): ?> <span
            class="badge"><?= $adminAlertCount ?></span><?php endif; ?></a>
      <a href="admin_tools">Admin Tools</a>
    </div>
  <?php endif; ?>

  <?php if ($expiry_warning): ?>
    <div
      style="background: #fff3cd; border: 1px solid #ffeeba; color: #856404; padding: 15px; border-radius: 5px; max-width: 800px; margin: 20px auto;">
      <strong>Notice:</strong> Your password will expire in <?= $expiry_warning ?>
      day<?= $expiry_warning == 1 ? '' : 's' ?>. Please update it soon.
    </div>
  <?php endif; ?>

  <main>
    <h2>Dashboard Overview</h2>
    <div class="card-container">
      <div class="card">
        <h3>New Deals</h3>
        <p>Quick access to deals created this week.</p>
      </div>
      <div class="card">
        <h3>Application Submissions</h3>
        <p>View and track submitted credit applications.</p>
      </div>
      <div class="card">
        <h3>Product Insights</h3>
        <p>Review sales and take rates of protection products.</p>
      </div>
      <div class="card">
        <h3>Team Performance</h3>
        <p>Analyze performance by store, role, or individual.</p>
      </div>
    </div>
  </main>

  <footer>
    &copy; <?= date("Y") ?> DealerFAI. All rights reserved.
  </footer>
</body>

</html>