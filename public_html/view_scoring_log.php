<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
include 'auth.php';
require_once __DIR__ . '/includes/theme_head.php';
include 'db.php';
require_once __DIR__ . '/helpers/session_utils.php';

$roles = load_session_roles();
if (!in_array('Admin', $roles, true)) {
  header('Location: dashboard.php');
  exit;
}

$org = $_SESSION['organization'] ?? null;
$adminAlertCount = 0;
try {
  $alertStmt = $db->query("SELECT COUNT(*) FROM admin_error_alerts WHERE is_resolved = 0");
  $adminAlertCount = (int)$alertStmt->fetchColumn();
} catch (PDOException $e) {
  $adminAlertCount = 0;
}

$theme = ['logo' => '', 'color' => '#0066cc'];
if ($org) {
  $stmt = $db->prepare("SELECT logo_url, theme_variant FROM organizations WHERE id = ?");
  $stmt->execute([$org]);
  $orgData = $stmt->fetch(PDO::FETCH_ASSOC);
  if ($orgData) {
    $theme['logo'] = $orgData['logo_url'] ?? '';
    $theme['color'] = $orgData['theme_variant'] === 'jlr' ? '#4a4a4a' : (in_array($orgData['theme_variant'], ['land_rover', 'jaguar'], true) ? '#1A1A1A' : '#0066cc');
  }
}

$logDir = __DIR__ . '/logs';
$logPath = $logDir . '/scoring.log';
$logExists = is_file($logPath);
$logReadable = $logExists && is_readable($logPath);
$logContents = '';
$logLineCount = 0;

if ($logReadable) {
  $contents = file_get_contents($logPath);
  if ($contents !== false) {
    $logContents = $contents;
    $logLineCount = substr_count($logContents, "\n");
  }
}

$raw = isset($_GET['raw']) && $_GET['raw'] === '1';
if ($raw) {
  header('Content-Type: text/plain; charset=utf-8');
  if (!$logReadable) {
    echo "Scoring log not found or not readable.";
    exit;
  }
  header('Content-Disposition: inline; filename=\"scoring.log\"');
  echo $logContents;
  exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php include __DIR__ . "/includes/head_favicon.php"; ?>
<meta charset="UTF-8">
  <title>Scoring Log File</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php dealerfai_theme_head($theme ?? null); ?>
  <style nonce="<?= dealerfai_csp_nonce() ?>">
    body {
      margin: 0;
      font-family: Arial, sans-serif;
      background: #f4f6f8;
      color: #111;
    }
    header {
      background: <?= htmlspecialchars($theme['color']) ?>;
      color: white;
      padding: 20px;
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
      background: <?= htmlspecialchars($theme['color']) ?>;
      padding: 12px;
      text-align: center;
    }
    nav a {
      color: white;
      margin: 0 20px;
      text-decoration: none;
      font-weight: bold;
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
    main {
      max-width: 900px;
      margin: 0 auto;
      padding: 30px 40px 50px;
    }
    .card {
      background: #fff;
      border-radius: 8px;
      box-shadow: 0 2px 10px rgba(0,0,0,0.1);
      padding: 24px;
    }
    .meta {
      display: flex;
      flex-wrap: wrap;
      gap: 12px;
      margin-bottom: 18px;
      color: #334;
      font-size: 14px;
    }
    .status-pill {
      display: inline-block;
      background: #e6ebf2;
      border: 1px solid #c7d0d8;
      border-radius: 999px;
      padding: 4px 10px;
      font-weight: bold;
      color: #1b2c40;
    }
    .button {
      display: inline-block;
      background: #0066cc;
      color: white;
      padding: 10px 16px;
      border-radius: 6px;
      text-decoration: none;
      font-size: 14px;
      margin-right: 8px;
    }
    .button.secondary {
      background: #e6ebf2;
      color: #1b2c40;
      border: 1px solid #c7d0d8;
    }
    pre {
      background: #0f1720;
      color: #e6edf3;
      padding: 16px;
      border-radius: 8px;
      overflow: auto;
      font-size: 13px;
      line-height: 1.5;
      max-height: 520px;
      white-space: pre-wrap;
    }
  </style>
</head>
<body>
  <header>
    <?php if (!empty($theme['logo'])): ?>
      <img src="<?= htmlspecialchars($theme['logo']) ?>" alt="Dealer Logo" style="max-height:60px;">
    <?php else: ?>
      <h1>DealerFAI Admin</h1>
    <?php endif; ?>
    <div class="logout">
      <a href="admin_error_alerts.php" class="nav-link-white">Alerts<?php if ($adminAlertCount > 0): ?> <span class="badge"><?= $adminAlertCount ?></span><?php endif; ?></a>
      <a href="logout.php" class="nav-link-white">Log Out</a>
    </div>
  </header>
  <nav>
    <a href="dashboard.php">Dashboard</a>
    <a href="view_deals.php">View Deals</a>
    <a href="create_deal.php">Create Deal</a>
    <a href="admin_error_alerts.php">Admin Alerts<?php if ($adminAlertCount > 0): ?> <span class="badge"><?= $adminAlertCount ?></span><?php endif; ?></a>
    <a href="admin_tools.php">Admin Tools</a>
  </nav>
  <main>
    <div class="card">
      <h2>Scoring Log File</h2>
      <div class="meta">
        <span class="status-pill"><?= $logReadable ? 'Available' : 'Unavailable' ?></span>
        <span>Path: <?= htmlspecialchars($logPath) ?></span>
        <?php if ($logReadable): ?>
          <span>Lines: <?= $logLineCount ?></span>
        <?php endif; ?>
      </div>
      <?php if ($logReadable && trim($logContents) !== ''): ?>
        <div style="margin-bottom: 16px;">
          <a class="button" href="view_scoring_log.php?raw=1">View Raw</a>
          <a class="button secondary" href="admin_scoring_log.php">View Scoring Log</a>
        </div>
        <pre><?= htmlspecialchars($logContents) ?></pre>
      <?php elseif ($logReadable): ?>
        <p>The scoring log file exists but is empty.</p>
        <div class="mt-14">
          <a class="button" href="admin_scoring_log.php">View Scoring Log</a>
        </div>
      <?php else: ?>
        <p>The scoring log file has not been created yet. It is generated the first time a deal is scored.</p>
        <p>Run a scoring action (or open the Scoring Log page) and try again.</p>
        <div class="mt-14">
          <a class="button" href="admin_scoring_log.php">View Scoring Log</a>
        </div>
      <?php endif; ?>
    </div>
  </main>
</body>
</html>
