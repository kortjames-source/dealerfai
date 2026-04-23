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
    $adminAlertCount = (int)$alertStmt->fetchColumn();
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

  $selectedOrg = (int)$_POST['context_org'];
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
    $orgNameLookup[(int)$orgOption['id']] = $orgOption['name'];
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
      background-color: <?= htmlspecialchars($theme['page_background']) ?>;
      color: #111111;
    }
    header {
      background-color: <?= htmlspecialchars($theme['header_background']) ?>;
      color: <?= htmlspecialchars($theme['header_text']) ?>;
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
      background-color: <?= htmlspecialchars($theme['nav_background']) ?>;
      padding: 12px;
      text-align: center;
    }
    nav a {
      color: <?= htmlspecialchars($theme['nav_text']) ?>;
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
      box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    }
    .card h3 {
      color: <?= htmlspecialchars($theme['color']) ?>;
      margin-bottom: 10px;
    }
    .card p {
      font-size: 14px;
    }
    footer {
      background-color: <?= htmlspecialchars($theme['color']) ?>;
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
  <div class="dashboard-wrapper">
    <!-- Sidebar -->
    <aside class="sidebar">
      <div class="sidebar-header">
        <img src="dealerfai_logo_white.png" alt="DealerFAI" style="height: 40px; filter: brightness(0) invert(1);">
      </div>
      <nav class="sidebar-nav" style="background: transparent; padding: 1.5rem 1rem;">
        <a href="dashboard" class="sidebar-link active">
          <i class="fa-solid fa-gauge"></i> Dashboard
        </a>
        <a href="view_deals" class="sidebar-link">
          <i class="fa-solid fa-file-invoice-dollar"></i> View Deals
        </a>
        <a href="create_deal" class="sidebar-link">
          <i class="fa-solid fa-plus-circle"></i> Create Deal
        </a>
        
        <?php if ($isAdmin): ?>
          <div style="margin-top: 2rem; padding: 0 1rem; font-size: 0.75rem; color: rgba(255,255,255,0.4); text-transform: uppercase; letter-spacing: 0.05em; font-weight: 700;">Admin</div>
          <a href="admin_scoring_log" class="sidebar-link">
            <i class="fa-solid fa-list-check"></i> Scoring Log
          </a>
          <a href="manage_users" class="sidebar-link">
            <i class="fa-solid fa-users"></i> Users
          </a>
          <a href="admin_organizations" class="sidebar-link">
            <i class="fa-solid fa-building"></i> Organizations
          </a>
        <?php endif; ?>

        <?php if (in_array('General Manager', $navRoles) || in_array('Admin', $navRoles)): ?>
          <a href="admin_tools" class="sidebar-link">
            <i class="fa-solid fa-wrench"></i> Admin Tools
          </a>
        <?php endif; ?>
      </nav>
      <div style="padding: 1.5rem; border-top: 1px solid rgba(255,255,255,0.1);">
        <a href="logout" class="sidebar-link" style="margin-bottom: 0; color: #ef4444;">
          <i class="fa-solid fa-right-from-bracket"></i> Log Out
        </a>
      </div>
    </aside>

    <!-- Main Content -->
    <div class="main-container">
      <header class="top-bar">
        <div class="breadcrumb" style="font-weight: 600; color: #64748b;">
          DealerFAI <i class="fa-solid fa-chevron-right" style="font-size: 0.75rem; margin: 0 0.5rem; opacity: 0.5;"></i> Dashboard
        </div>
        <div style="display: flex; align-items: center; gap: 1.5rem;">
          <?php if ($isAdmin): ?>
            <a href="admin_error_alerts" style="position: relative; color: #64748b;">
              <i class="fa-solid fa-bell" style="font-size: 1.25rem;"></i>
              <?php if ($adminAlertCount > 0): ?>
                <span style="position: absolute; top: -5px; right: -5px; background: #ef4444; color: white; font-size: 10px; padding: 2px 5px; border-radius: 10px; font-weight: 700;"><?= $adminAlertCount ?></span>
              <?php endif; ?>
            </a>
          <?php endif; ?>
          <div style="display: flex; align-items: center; gap: 0.75rem;">
            <div style="text-align: right;">
              <div style="font-size: 0.875rem; font-weight: 700; color: #0f172a;"><?= htmlspecialchars($_SESSION['full_name'] ?? 'User') ?></div>
              <div style="font-size: 0.75rem; color: #64748b;"><?= htmlspecialchars($_SESSION['email'] ?? '') ?></div>
            </div>
            <div style="width: 40px; height: 40px; background: var(--brand-color); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700;">
              <?= strtoupper(substr($_SESSION['full_name'] ?? 'U', 0, 1)) ?>
            </div>
          </div>
        </div>
      </header>

      <main class="page-content">
        <?php if ($expiry_warning): ?>
          <div class="alert alert-warning" style="margin-bottom: 2rem;">
            <i class="fa-solid fa-triangle-exclamation"></i> <strong>Notice:</strong> Your password will expire in <?= $expiry_warning ?> days. Please update it soon.
          </div>
        <?php endif; ?>

        <?php if ($isAdmin): ?>
          <div class="glass" style="padding: 1.5rem; margin-bottom: 2.5rem; display: flex; justify-content: space-between; align-items: center; border: 1px solid var(--border-color);">
            <form method="post" style="display: flex; align-items: center; gap: 1rem; flex: 1;">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
              <label for="context_org" style="margin-top: 0; font-size: 0.875rem; color: #64748b;">Current Organization Context:</label>
              <select id="context_org" name="context_org" class="form-control" style="max-width: 300px; margin-top: 0;">
                <option value="0" <?= $orgContextId ? '' : 'selected' ?>>All organizations (admin)</option>
                <?php foreach ($orgNames as $orgOption): ?>
                  <option value="<?= $orgOption['id'] ?>" <?= ($orgContextId === (int)$orgOption['id']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($orgOption['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <button type="submit" class="btn btn-sm">Switch</button>
            </form>
          </div>
        <?php endif; ?>

        <div style="margin-bottom: 2rem;">
          <h1 class="text-gradient" style="margin-bottom: 0.5rem; display: inline-block;">Welcome back, <?= explode(' ', $_SESSION['full_name'] ?? 'User')[0] ?>!</h1>
          <p class="text-muted">Here is what's happening at <?= $orgContextId ? htmlspecialchars($orgNameLookup[$orgContextId]) : 'DealerFAI' ?> today.</p>
        </div>

        <div class="metric-grid">
          <div class="metric-card">
            <div class="label">New Deals</div>
            <div class="value">24</div>
            <div style="color: #10b981; font-size: 0.75rem; font-weight: 600; margin-top: 0.5rem;"><i class="fa-solid fa-arrow-up"></i> 12% from last week</div>
          </div>
          <div class="metric-card">
            <div class="label">Submissions</div>
            <div class="value">158</div>
            <div style="color: #10b981; font-size: 0.75rem; font-weight: 600; margin-top: 0.5rem;"><i class="fa-solid fa-arrow-up"></i> 8% from last week</div>
          </div>
          <div class="metric-card">
            <div class="label">Take Rate</div>
            <div class="value">64%</div>
            <div style="color: #ef4444; font-size: 0.75rem; font-weight: 600; margin-top: 0.5rem;"><i class="fa-solid fa-arrow-down"></i> 2% from last week</div>
          </div>
          <div class="metric-card">
            <div class="label">Gross Profit</div>
            <div class="value">$12,450</div>
            <div style="color: #10b981; font-size: 0.75rem; font-weight: 600; margin-top: 0.5rem;"><i class="fa-solid fa-arrow-up"></i> 5% from last week</div>
          </div>
        </div>

        <div class="data-table-card">
          <div class="data-table-header">
            <h3 style="margin: 0;">Quick Actions</h3>
          </div>
          <div style="padding: 2rem; display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.5rem;">
            <a href="create_deal" class="btn btn-outline" style="text-align: center; padding: 2rem 1rem;">
              <i class="fa-solid fa-plus-circle" style="font-size: 2rem; margin-bottom: 1rem; display: block;"></i>
              New Deal
            </a>
            <a href="view_deals" class="btn btn-outline" style="text-align: center; padding: 2rem 1rem;">
              <i class="fa-solid fa-search" style="font-size: 2rem; margin-bottom: 1rem; display: block;"></i>
              Search Deals
            </a>
            <a href="manage_users" class="btn btn-outline" style="text-align: center; padding: 2rem 1rem;">
              <i class="fa-solid fa-user-plus" style="font-size: 2rem; margin-bottom: 1rem; display: block;"></i>
              Invite Team
            </a>
            <a href="admin_tools" class="btn btn-outline" style="text-align: center; padding: 2rem 1rem;">
              <i class="fa-solid fa-chart-pie" style="font-size: 2rem; margin-bottom: 1rem; display: block;"></i>
              View Reports
            </a>
          </div>
        </div>
      </main>

      <footer style="background: white; border-top: 1px solid #e2e8f0; color: #64748b; padding: 1.5rem; text-align: center; position: static;">
        &copy; <?= date("Y") ?> DealerFAI. All rights reserved.
      </footer>
    </div>
  </div>
</body>
</html>
