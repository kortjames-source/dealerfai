<?php
include 'auth.php';
require_once __DIR__ . '/includes/theme_head.php';
include 'db.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/helpers/session_utils.php';

$roles = load_session_roles();
$isAdmin = in_array('Admin', $roles, true);
if (!$isAdmin) {
    http_response_code(403);
    echo 'Unauthorized';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resolve_id'])) {
    if (!dealerfai_csrf_validate($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        die('Invalid request.');
    }

    $resolveId = (int)$_POST['resolve_id'];
    if ($resolveId > 0) {
        $resolveStmt = $db->prepare('UPDATE admin_error_alerts SET is_resolved = 1, resolved_at = NOW() WHERE id = ?');
        $resolveStmt->execute([$resolveId]);
    }
    header('Location: admin_error_alerts.php');
    exit;
}

$org_id = get_effective_organization();
$theme = ['logo' => '', 'color' => '#0066cc'];
if ($org_id) {
    $stmt = $db->prepare('SELECT logo_url, theme_variant FROM organizations WHERE id = ?');
    $stmt->execute([$org_id]);
    $org = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($org) {
        $theme['logo'] = $org['logo_url'] ?? '';
        $theme['color'] = $org['theme_variant'] === 'jlr' ? '#4a4a4a' : (in_array($org['theme_variant'], ['land_rover', 'jaguar'], true) ? '#1A1A1A' : '#0066cc');
    }
}

$loadError = null;
$alerts = [];
try {
    $alertsStmt = $db->query('SELECT id, error_hash, level, message, file, line, url, user_id, org_id, occurrences, first_seen, last_seen, last_ip, is_resolved, resolved_at FROM admin_error_alerts ORDER BY is_resolved ASC, last_seen DESC');
    $alerts = $alertsStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $loadError = 'Alerts table is not available yet. Run the migration to create admin_error_alerts.';
    $alerts = [];
}

function clamp_text(string $text, int $limit = 220): string
{
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($text) > $limit) {
            return mb_substr($text, 0, $limit - 3) . '...';
        }
        return $text;
    }

    if (strlen($text) > $limit) {
        return substr($text, 0, $limit - 3) . '...';
    }

    return $text;
}

$openCount = 0;
foreach ($alerts as $alert) {
    if (!(int)$alert['is_resolved']) {
        $openCount++;
    }
}
$adminAlertCount = $openCount;
$csrfToken = dealerfai_csrf_get_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php include __DIR__ . "/includes/head_favicon.php"; ?>
<meta charset="UTF-8">
  <title>Admin Error Alerts</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php dealerfai_theme_head($theme ?? null); ?>
  <style nonce="<?= dealerfai_csp_nonce() ?>">
    body {
      margin: 0;
      font-family: "Segoe UI", sans-serif;
      background-color: #f4f6f8;
      color: #111111;
    }
    header {
      background-color: <?= htmlspecialchars($theme['color']) ?>;
      color: white;
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
      background-color: <?= htmlspecialchars($theme['color']) ?>;
      padding: 12px;
      text-align: center;
    }
    nav a {
      color: white;
      margin: 0 20px;
      text-decoration: none;
      font-weight: bold;
    }
    main {
      padding: 30px 40px;
    }
    .page-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
      gap: 12px;
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
    }
    .summary {
      font-size: 14px;
      color: #1f3d5e;
      font-weight: 600;
    }
    table {
      width: 100%;
      border-collapse: collapse;
      background: white;
      border-radius: 10px;
      overflow: hidden;
      box-shadow: 0 2px 12px rgba(0,0,0,0.08);
    }
    th, td {
      padding: 12px 14px;
      border-bottom: 1px solid #e2e6ea;
      vertical-align: top;
      text-align: left;
      font-size: 14px;
    }
    th {
      background: #0066cc;
      color: white;
      font-weight: 600;
    }
    tr.resolved {
      background: #f7f9fb;
      color: #6b7a86;
    }
    .meta {
      font-size: 12px;
      color: #5a6b7a;
      margin-top: 4px;
    }
    .message {
      font-weight: 600;
      color: #0066cc;
      margin-bottom: 6px;
    }
    .resolve-button {
      padding: 6px 12px;
      border-radius: 4px;
      border: 1px solid #0066cc;
      background: #0066cc;
      color: white;
      cursor: pointer;
      font-size: 12px;
      font-weight: 600;
    }
    .resolve-button:hover {
      background: #084c63;
    }
    .status-pill {
      display: inline-block;
      padding: 4px 10px;
      border-radius: 999px;
      font-size: 12px;
      font-weight: 600;
    }
    .status-open {
      background: #ffe9ed;
      color: #a50f27;
    }
    .status-resolved {
      background: #e6f4ea;
      color: #1b6b2f;
    }
    .empty {
      background: white;
      padding: 24px;
      border-radius: 8px;
      text-align: center;
      color: #5a6b7a;
      box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    }
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
        <a href="admin_organizations" class="sidebar-link">
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
        DealerFAI <i class="fa-solid fa-chevron-right" style="font-size: 0.75rem; margin: 0 0.5rem; opacity: 0.5;"></i> Error Alerts
      </div>
      <div style="display: flex; align-items: center; gap: 1.5rem;">
        <?php if ($isAdmin): ?>
          <a href="admin_error_alerts" style="position: relative; color: var(--brand-color);">
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
      <div style="margin-bottom: 2rem; display: flex; justify-content: space-between; align-items: flex-end;">
        <div>
          <h1 class="text-gradient" style="margin-bottom: 0.5rem; display: inline-block;">System Error Alerts</h1>
          <p class="text-muted">Monitor and resolve system errors across the application.</p>
        </div>
        <div class="summary" style="margin-bottom: 0.5rem;">
          Open alerts: <span class="badge" style="vertical-align: middle;"><?= $openCount ?></span>
        </div>
      </div>

      <?php if ($loadError): ?>
        <div class="glass" style="padding: 3rem; text-align: center;">
          <i class="fa-solid fa-triangle-exclamation" style="font-size: 3rem; color: #f59e0b; margin-bottom: 1rem;"></i>
          <p><?= htmlspecialchars($loadError) ?></p>
        </div>
      <?php elseif (empty($alerts)): ?>
        <div class="glass" style="padding: 3rem; text-align: center;">
          <i class="fa-solid fa-check-circle" style="font-size: 3rem; color: #10b981; margin-bottom: 1rem;"></i>
          <p>No alerts recorded yet. Everything looks good!</p>
        </div>
      <?php else: ?>
        <div class="data-table-card">
          <table style="width: 100%; border-collapse: collapse;">
            <thead>
              <tr style="background: #f8fafc; border-bottom: 1px solid #e2e8f0;">
                <th style="padding: 1rem 1.5rem; text-align: left; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b; background: transparent;">Status</th>
                <th style="padding: 1rem 1.5rem; text-align: left; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b; background: transparent;">Alert Details</th>
                <th style="padding: 1rem 1.5rem; text-align: center; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b; background: transparent;">Occurrences</th>
                <th style="padding: 1rem 1.5rem; text-align: left; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b; background: transparent;">Last Seen</th>
                <th style="padding: 1rem 1.5rem; text-align: left; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b; background: transparent;">Context</th>
                <th style="padding: 1rem 1.5rem; text-align: right; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b; background: transparent;">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($alerts as $alert): ?>
                <?php $isResolved = (int)$alert['is_resolved'] === 1; ?>
                <tr style="border-bottom: 1px solid #f1f5f9; <?= $isResolved ? 'opacity: 0.6;' : '' ?>">
                  <td style="padding: 1.25rem 1.5rem;">
                    <?php if ($isResolved): ?>
                      <span class="status-pill status-resolved">Resolved</span>
                      <?php if (!empty($alert['resolved_at'])): ?>
                        <div class="meta"><?= htmlspecialchars($alert['resolved_at']) ?></div>
                      <?php endif; ?>
                    <?php else: ?>
                      <span class="status-pill status-open">Open</span>
                    <?php endif; ?>
                  </td>
                  <td style="padding: 1.25rem 1.5rem;">
                    <div class="message" style="font-weight: 700; color: #0f172a; margin-bottom: 0.25rem;" title="<?= htmlspecialchars($alert['message']) ?>">
                      <?= htmlspecialchars(clamp_text($alert['message'])) ?>
                    </div>
                    <div class="meta">Level: <?= htmlspecialchars($alert['level']) ?></div>
                    <?php if (!empty($alert['file'])): ?>
                      <div class="meta" style="font-family: monospace; font-size: 0.7rem; color: #64748b; background: #f1f5f9; padding: 2px 4px; border-radius: 4px; display: inline-block; margin-top: 4px;">
                        <?= htmlspecialchars(basename($alert['file'])) ?><?php if (!empty($alert['line'])): ?>:<?= (int)$alert['line'] ?><?php endif; ?>
                      </div>
                    <?php endif; ?>
                  </td>
                  <td style="padding: 1.25rem 1.5rem; text-align: center;">
                    <span style="font-weight: 700; color: #0f172a;"><?= (int)$alert['occurrences'] ?></span>
                  </td>
                  <td style="padding: 1.25rem 1.5rem;">
                    <div style="font-size: 0.875rem; font-weight: 500;"><?= htmlspecialchars($alert['last_seen']) ?></div>
                    <div class="meta">First: <?= htmlspecialchars($alert['first_seen']) ?></div>
                  </td>
                  <td style="padding: 1.25rem 1.5rem;">
                    <?php if (!empty($alert['url'])): ?>
                      <div style="margin-bottom: 0.25rem;"><a href="<?= htmlspecialchars($alert['url']) ?>" target="_blank" rel="noopener" style="color: var(--brand-color); text-decoration: none; font-size: 0.875rem; font-weight: 600;">View URL <i class="fa-solid fa-external-link" style="font-size: 0.7rem;"></i></a></div>
                    <?php endif; ?>
                    <div class="meta">User: <?= htmlspecialchars((string)($alert['user_id'] ?? 'N/A')) ?></div>
                    <div class="meta">Org: <?= htmlspecialchars((string)($alert['org_id'] ?? 'N/A')) ?></div>
                  </td>
                  <td style="padding: 1.25rem 1.5rem; text-align: right;">
                    <?php if (!$isResolved): ?>
                      <form method="post" style="display: inline;">
                        <input type="hidden" name="resolve_id" value="<?= (int)$alert['id'] ?>">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <button type="submit" class="resolve-button" style="background: var(--brand-color); border-color: var(--brand-color);">Mark Resolved</button>
                      </form>
                    <?php else: ?>
                      <span class="meta" style="font-style: italic;">Resolved</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </main>

    <footer style="background: white; border-top: 1px solid #e2e8f0; color: #64748b; padding: 1.5rem; text-align: center; position: static;">
      &copy; <?= date("Y") ?> DealerFAI. All rights reserved.
    </footer>
  </div>
</body>

</html>
