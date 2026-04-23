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
  $orgNames[(int)$org['id']] = $org['name'];
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
    /* Specific page overrides */
    img.logo-preview { max-height: 24px; max-width: 100px; opacity: 0.8; }
    .btn-sm { padding: 0.4rem 0.8rem; font-size: 0.75rem; }
  </style>
</head>
<body class="dashboard-wrapper">
  <!-- Sidebar -->
  <aside class="sidebar">
    <div class="sidebar-header" style="padding: 1.5rem;">
      <img src="dealerfai_logo_white.png" alt="DealerFAI" style="height: 35px; width: auto;">
    </div>
    <nav class="sidebar-nav" style="background: transparent; padding: 1.5rem 1rem;">
      <a href="dashboard" class="sidebar-link">
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
        <a href="admin_organizations" class="sidebar-link active">
          <i class="fa-solid fa-building"></i> Organizations
        </a>
        <a href="admin_tools" class="sidebar-link">
          <i class="fa-solid fa-wrench"></i> Admin Tools
        </a>
      <?php endif; ?>
    </nav>
    <div style="padding: 1.5rem; border-top: 1px solid rgba(255,255,255,0.1);">
      <p style="font-size: 0.75rem; color: rgba(255,255,255,0.4); margin: 0;">DealerFAI v2.0</p>
    </div>
  </aside>

  <!-- Main Content -->
  <div class="main-container">
    <header class="top-bar">
      <div class="breadcrumb" style="font-weight: 600; color: #64748b;">
        DealerFAI <i class="fa-solid fa-chevron-right" style="font-size: 0.75rem; margin: 0 0.5rem; opacity: 0.5;"></i> Organizations
      </div>
      <div style="display: flex; align-items: center; gap: 1.5rem;">
        <a href="admin_error_alerts" style="position: relative; color: #64748b;">
          <i class="fa-solid fa-bell" style="font-size: 1.25rem;"></i>
          <?php if ($adminAlertCount > 0): ?>
            <span style="position: absolute; top: -5px; right: -5px; background: #ef4444; color: white; font-size: 10px; padding: 2px 5px; border-radius: 10px; font-weight: 700;"><?= $adminAlertCount ?></span>
          <?php endif; ?>
        </a>
        <div style="display: flex; align-items: center; gap: 0.75rem;">
          <div style="text-align: right;">
            <div style="font-size: 0.875rem; font-weight: 700; color: #0f172a;"><?= htmlspecialchars($_SESSION['full_name'] ?? 'User') ?></div>
            <a href="logout" style="font-size: 0.75rem; color: #64748b; text-decoration: none;">Log Out</a>
          </div>
          <div style="width: 40px; height: 40px; background: var(--brand-color); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700;">
            <?= strtoupper(substr($_SESSION['full_name'] ?? 'U', 0, 1)) ?>
          </div>
        </div>
      </div>
    </header>

    <main class="page-content">
      <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 2rem;">
        <div>
          <h1 class="text-gradient" style="margin-bottom: 0.5rem; display: inline-block;">Organizations</h1>
          <p class="text-muted">Manage business entities, dealer groups, and store-level branding.</p>
        </div>
        <a href="admin_organization_edit.php" class="btn btn-primary"><i class="fa-solid fa-plus" style="margin-right: 0.5rem;"></i> New Organization</a>
      </div>

      <?php if (isset($_GET['added'])): ?>
        <div style="background: #ecfdf5; color: #065f46; padding: 1rem; border-radius: var(--radius-md); margin-bottom: 1.5rem; border: 1px solid #a7f3d0;">
          <i class="fa-solid fa-check-circle" style="margin-right: 0.5rem;"></i> Organization added successfully.
        </div>
      <?php endif; ?>
      <?php if (isset($_GET['updated'])): ?>
        <div style="background: #f0f9ff; color: #0369a1; padding: 1rem; border-radius: var(--radius-md); margin-bottom: 1.5rem; border: 1px solid #bae6fd;">
          <i class="fa-solid fa-info-circle" style="margin-right: 0.5rem;"></i> Organization updated successfully.
        </div>
      <?php endif; ?>

      <div class="data-table-card">
        <table>
          <thead>
            <tr style="background: #f1f5f9;">
              <th style="background: transparent; color: #475569; text-align: left;">Name</th>
              <th style="background: transparent; color: #475569; text-align: left;">Type</th>
              <th style="background: transparent; color: #475569; text-align: left;">Parent</th>
              <th style="background: transparent; color: #475569; text-align: center;">Logo</th>
              <th style="background: transparent; color: #475569; text-align: center;">Logic</th>
              <th style="background: transparent; color: #475569; text-align: center;">Theme</th>
              <th style="background: transparent; color: #475569; text-align: center;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($orgs as $org): ?>
              <tr>
                <td style="font-weight: 600;"><?= htmlspecialchars($org['name']) ?></td>
                <td><span style="font-size: 0.75rem; background: #e2e8f0; padding: 0.2rem 0.5rem; border-radius: 4px; text-transform: uppercase; font-weight: 700;"><?= htmlspecialchars($org['org_kind'] ?? 'store') ?></span></td>
                <td><span style="color: #64748b;"><?= htmlspecialchars($orgNames[(int)$org['parent_org_id']] ?? '—') ?></span></td>
                <td style="text-align: center;">
                  <?php if (!empty($org['logo_url'])): ?>
                    <img src="<?= $org['logo_url'] ?>" class="logo-preview">
                  <?php else: ?>
                    <span style="color: #cbd5e1; font-size: 0.75rem;">None</span>
                  <?php endif; ?>
                </td>
                <td style="text-align: center;"><span style="font-size: 0.75rem; color: #64748b;"><?= htmlspecialchars($org['logic_type']) ?></span></td>
                <td style="text-align: center;"><span style="font-size: 0.75rem; font-weight: 600; color: var(--brand-color);"><?= htmlspecialchars($org['theme_variant']) ?></span></td>
                <td style="text-align: center;">
                  <a href="admin_organization_edit.php?id=<?= (int)$org['id'] ?>" class="btn btn-outline btn-sm">Edit</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </main>

    <footer style="background: white; border-top: 1px solid #e2e8f0; color: #64748b; padding: 1.5rem; text-align: center; position: static;">
      &copy; <?= date("Y") ?> DealerFAI. All rights reserved.
    </footer>
  </div>
</body>
</body>
</html>
