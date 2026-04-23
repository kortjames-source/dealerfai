<?php
include 'auth.php';
require_once __DIR__ . '/includes/theme_head.php';
include 'db.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/helpers/session_utils.php';

$roles = load_session_roles();
if (!in_array('Admin', $roles, true)) {
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
$theme = ['logo' => '', 'color' => '#0a2e36'];
if ($org_id) {
    $stmt = $db->prepare('SELECT logo_url, theme_variant FROM organizations WHERE id = ?');
    $stmt->execute([$org_id]);
    $org = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($org) {
        $theme['logo'] = $org['logo_url'] ?? '';
        $theme['color'] = $org['theme_variant'] === 'jlr' ? '#4a4a4a' : (in_array($org['theme_variant'], ['land_rover', 'jaguar'], true) ? '#1A1A1A' : '#0a6280');
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
      background: #0a2e36;
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
      color: #0a6280;
      margin-bottom: 6px;
    }
    .resolve-button {
      padding: 6px 12px;
      border-radius: 4px;
      border: 1px solid #0a6280;
      background: #0a6280;
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
<body>
<header class="pos-relative">
  <?php if (!empty($theme['logo'])): ?>
    <img src="<?= htmlspecialchars($theme['logo']) ?>" alt="Dealer Logo">
  <?php else: ?>
    <h1>DealerFAI</h1>
  <?php endif; ?>
  <div class="logout">
    <a href="admin_error_alerts.php" class="nav-link-white">Alerts<?php if ($adminAlertCount > 0): ?> <span style="display:inline-block; min-width:18px; padding:2px 8px; border-radius:999px; background:#d7263d; color:#fff; font-size:12px; font-weight:bold; text-align:center; margin-left:6px;"><?= $adminAlertCount ?></span><?php endif; ?></a>
    <a href="logout.php" class="nav-link-white">Log Out</a>
  </div>
</header>
<nav>
  <a href="dashboard.php">Dashboard</a>
  <a href="admin_tools.php">Admin Tools</a>
  <a href="admin_error_alerts.php">Admin Alerts</a>
</nav>
<main>
  <div class="page-header">
    <h2>Admin Error Alerts</h2>
    <div class="summary">
      Open alerts: <span class="badge"><?= $openCount ?></span>
    </div>
  </div>

  <?php if ($loadError): ?>
    <div class="empty"><?= htmlspecialchars($loadError) ?></div>
  <?php elseif (empty($alerts)): ?>
    <div class="empty">No alerts recorded yet.</div>
  <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>Status</th>
          <th>Alert</th>
          <th>Occurrences</th>
          <th>Last Seen</th>
          <th>Context</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($alerts as $alert): ?>
          <?php $isResolved = (int)$alert['is_resolved'] === 1; ?>
          <tr class="<?= $isResolved ? 'resolved' : '' ?>">
            <td>
              <?php if ($isResolved): ?>
                <span class="status-pill status-resolved">Resolved</span>
                <?php if (!empty($alert['resolved_at'])): ?>
                  <div class="meta"><?= htmlspecialchars($alert['resolved_at']) ?></div>
                <?php endif; ?>
              <?php else: ?>
                <span class="status-pill status-open">Open</span>
              <?php endif; ?>
            </td>
            <td>
              <div class="message" title="<?= htmlspecialchars($alert['message']) ?>"><?= htmlspecialchars(clamp_text($alert['message'])) ?></div>
              <div class="meta">Level: <?= htmlspecialchars($alert['level']) ?></div>
              <?php if (!empty($alert['file'])): ?>
                <div class="meta">File: <?= htmlspecialchars($alert['file']) ?><?php if (!empty($alert['line'])): ?>:<?= (int)$alert['line'] ?><?php endif; ?></div>
              <?php endif; ?>
            </td>
            <td><?= (int)$alert['occurrences'] ?></td>
            <td>
              <div><?= htmlspecialchars($alert['last_seen']) ?></div>
              <div class="meta">First: <?= htmlspecialchars($alert['first_seen']) ?></div>
            </td>
            <td>
              <?php if (!empty($alert['url'])): ?>
                <div><a href="<?= htmlspecialchars($alert['url']) ?>" target="_blank" rel="noopener">View URL</a></div>
              <?php endif; ?>
              <div class="meta">User: <?= htmlspecialchars((string)($alert['user_id'] ?? '')) ?></div>
              <div class="meta">Org: <?= htmlspecialchars((string)($alert['org_id'] ?? '')) ?></div>
              <?php if (!empty($alert['last_ip'])): ?>
                <div class="meta">IP: <?= htmlspecialchars($alert['last_ip']) ?></div>
              <?php endif; ?>
            </td>
            <td>
              <?php if (!$isResolved): ?>
                <form method="post">
                  <input type="hidden" name="resolve_id" value="<?= (int)$alert['id'] ?>">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                  <button type="submit" class="resolve-button">Mark Resolved</button>
                </form>
              <?php else: ?>
                <span class="meta">Resolved</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</main>
</body>
</html>
