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
    /* Specific page overrides */
    details { margin-bottom: 1.5rem; background: white; border-radius: var(--radius-lg); border: 1px solid #e2e8f0; overflow: hidden; box-shadow: var(--shadow-sm); }
    summary { padding: 1.25rem 1.5rem; font-weight: 700; cursor: pointer; background: #f8fafc; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; gap: 0.5rem; }
    summary::-webkit-details-marker { display: none; }
    summary::before { content: "\f078"; font-family: "Font Awesome 6 Free"; font-weight: 900; font-size: 0.75rem; transition: transform 0.2s; }
    details[open] summary::before { transform: rotate(180deg); }
    .details-content { padding: 1.5rem; }
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
      
      <?php if ($is_admin): ?>
        <div style="margin-top: 2rem; padding: 0 1rem; font-size: 0.75rem; color: rgba(255,255,255,0.4); text-transform: uppercase; letter-spacing: 0.05em; font-weight: 700;">Admin</div>
        <a href="admin_scoring_log" class="sidebar-link">
          <i class="fa-solid fa-list-check"></i> Scoring Log
        </a>
        <a href="manage_users" class="sidebar-link active">
          <i class="fa-solid fa-users"></i> Users
        </a>
        <a href="admin_organizations" class="sidebar-link">
          <i class="fa-solid fa-building"></i> Organizations
        </a>
      <?php endif; ?>

      <?php if (in_array('General Manager', $roles) || $is_admin): ?>
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
        DealerFAI <i class="fa-solid fa-chevron-right" style="font-size: 0.75rem; margin: 0 0.5rem; opacity: 0.5;"></i> User Management
      </div>
      <div style="display: flex; align-items: center; gap: 1.5rem;">
        <?php if ($is_admin): ?>
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
          <h1 class="text-gradient" style="margin-bottom: 0.5rem; display: inline-block;">Organizations & Users</h1>
          <p class="text-muted">Manage access levels and multi-factor authentication for your team.</p>
        </div>
        <?php if ($is_admin): ?>
          <a href="admin_organizations" class="btn btn-primary"><i class="fa-solid fa-plus" style="margin-right: 0.5rem;"></i> New Organization</a>
        <?php endif; ?>
      </div>

      <?php foreach ($orgs as $org): ?>
        <details>
          <summary><?= htmlspecialchars($org['name']) ?></summary>
          <div class="details-content">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
              <h4 style="margin: 0; color: #64748b; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.05em;">Team Members</h4>
              <?php if ($is_admin || $org['id'] == $org_id): ?>
                <a href="user_create.php?org=<?= $org['id'] ?>" class="btn btn-sm">➕ Add User</a>
              <?php endif; ?>
            </div>

            <div class="data-table-card">
              <table style="margin-top: 0; box-shadow: none;">
                <thead>
                  <tr style="background: #f1f5f9;">
                    <th style="background: transparent; color: #475569; text-align: left;">Email</th>
                    <th style="background: transparent; color: #475569; text-align: left;">Roles</th>
                    <th style="background: transparent; color: #475569; text-align: left;">MFA Status</th>
                    <th style="background: transparent; color: #475569; text-align: center;">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($users_by_org[$org['id']] ?? [] as $user): ?>
                    <tr>
                      <td style="font-weight: 600;"><?= htmlspecialchars($user['email'] ?? '') ?></td>
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
                          if ($mfa_mandatory && $mfa_enabled) echo "<span style='color: #10b981; font-weight: 600;'>✅ Enabled</span>";
                          elseif ($mfa_mandatory && $has_secret) echo "<span style='color: #f59e0b; font-weight: 600;'>⚠️ Required</span>";
                          elseif ($mfa_mandatory) echo "<span style='color: #ef4444; font-weight: 600;'>⚠️ Pending</span>";
                          elseif ($mfa_enabled) echo "<span style='color: #10b981;'>✅ Enabled</span>";
                          else echo "<span style='color: #94a3b8;'>Not Set</span>";
                        ?>
                      </td>
                      <td>
                        <div style="display: flex; gap: 0.5rem; justify-content: center;">
                          <?php if ($is_admin || $org['id'] == $org_id): ?>
                            <a href="edit_user.php?id=<?= $user['id'] ?>" class="btn btn-outline btn-sm">Edit</a>
                            <?php if ($is_admin): ?>
                              <form method="post" action="update_mfa.php" style="display: flex; gap: 0.25rem; margin: 0;">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                <button name="action" value="require" class="btn btn-secondary btn-sm" title="Require MFA">Require</button>
                                <button name="action" value="reset" class="btn btn-secondary btn-sm" title="Reset MFA">Reset</button>
                                <button name="action" value="disable" class="btn btn-secondary btn-sm" title="Disable MFA">Disable</button>
                              </form>
                            <?php endif; ?>
                          <?php endif; ?>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </details>
      <?php endforeach; ?>
    </main>

    <footer style="background: white; border-top: 1px solid #e2e8f0; color: #64748b; padding: 1.5rem; text-align: center; position: static;">
      &copy; <?= date("Y") ?> DealerFAI. All rights reserved.
    </footer>
  </div>
</body>
</html>
