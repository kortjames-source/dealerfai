<?php
include 'auth.php';
require_once __DIR__ . '/includes/theme_head.php';
include 'db.php';
require_once __DIR__ . '/helpers/session_utils.php';
require_once __DIR__ . '/helpers/db_utils.php';

$roles = load_session_roles();
if (!in_array('Admin', $roles, true)) {
  header('Location: dashboard.php');
  exit;
}

$org = (string)($_SESSION['organization'] ?? '');
$name = (string)($_SESSION['full_name'] ?? '');
$adminAlertCount = 0;
try {
  $alertStmt = $db->query("SELECT COUNT(*) FROM admin_error_alerts WHERE is_resolved = 0");
  $adminAlertCount = (int)$alertStmt->fetchColumn();
} catch (PDOException $e) {
  $adminAlertCount = 0;
}

$aiQueueDateField = strtolower(trim((string)($_GET['queue_date_field'] ?? 'updated')));
$aiQueueDateField = $aiQueueDateField === 'created' ? 'created_at' : 'updated_at';
$aiQueueRange = strtolower(trim((string)($_GET['queue_range'] ?? 'today')));
if (!in_array($aiQueueRange, ['today', 'current_month', 'last_month'], true)) {
  $aiQueueRange = 'today';
}
$aiQueueStartAt = null;
$aiQueueEndAt = null;
$aiQueueFilterError = '';
$aiQueueDateLabel = $aiQueueDateField === 'created_at' ? 'created' : 'updated';

try {
  $now = new DateTimeImmutable('now');
  if ($aiQueueRange === 'today') {
    $todayStart = $now->setTime(0, 0, 0);
    $aiQueueStartAt = $todayStart->format('Y-m-d H:i:s');
    $aiQueueEndAt = $now->format('Y-m-d H:i:s');
  } elseif ($aiQueueRange === 'current_month') {
    $monthStart = $now->modify('first day of this month')->setTime(0, 0, 0);
    $aiQueueStartAt = $monthStart->format('Y-m-d H:i:s');
    $aiQueueEndAt = $now->format('Y-m-d H:i:s');
  } elseif ($aiQueueRange === 'last_month') {
    $monthStart = $now->modify('first day of this month')->setTime(0, 0, 0);
    $lastMonthStart = $monthStart->modify('-1 month');
    $aiQueueStartAt = $lastMonthStart->format('Y-m-d H:i:s');
    $aiQueueEndAt = $monthStart->format('Y-m-d H:i:s');
  }
} catch (Throwable $e) {
  $aiQueueFilterError = 'Unable to parse queue date filters.';
  $aiQueueStartAt = null;
  $aiQueueEndAt = null;
}

$aiQueueFilterLabel = 'Today (' . $aiQueueDateLabel . ')';
if ($aiQueueRange === 'current_month') {
  $aiQueueFilterLabel = 'Current month (' . $aiQueueDateLabel . ')';
} elseif ($aiQueueRange === 'last_month') {
  $aiQueueFilterLabel = 'Last month (' . $aiQueueDateLabel . ')';
}

$aiQueueEnabled = false;
$aiQueueCounts = [
  'pending' => 0,
  'processing' => 0,
  'done' => 0,
  'failed' => 0,
];
$aiQueueFailures = [];
try {
  if (table_column_exists($db, 'recommendation_ai_jobs', 'status')) {
    $aiQueueEnabled = true;
    $queueDateFilterSupported = table_column_exists($db, 'recommendation_ai_jobs', $aiQueueDateField);
    $queueWhere = [];
    $queueParams = [];
    if ($queueDateFilterSupported) {
      if ($aiQueueStartAt !== null) {
        $queueWhere[] = $aiQueueDateField . ' >= ?';
        $queueParams[] = $aiQueueStartAt;
      }
      if ($aiQueueEndAt !== null) {
        $queueWhere[] = $aiQueueDateField . ' < ?';
        $queueParams[] = $aiQueueEndAt;
      }
    }

    $countSql = "
      SELECT status, COUNT(*) AS cnt
      FROM recommendation_ai_jobs
    ";
    if (!empty($queueWhere)) {
      $countSql .= ' WHERE ' . implode(' AND ', $queueWhere);
    }
    $countSql .= ' GROUP BY status';
    $countStmt = $db->prepare($countSql);
    $countStmt->execute($queueParams);
    foreach ($countStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $status = strtolower((string)($row['status'] ?? ''));
      if (array_key_exists($status, $aiQueueCounts)) {
        $aiQueueCounts[$status] = (int)($row['cnt'] ?? 0);
      }
    }

    $failWhere = ["status = 'failed'"];
    if (!empty($queueWhere)) {
      $failWhere = array_merge($failWhere, $queueWhere);
    }
    $failSql = "
      SELECT id, deal_id, product_code, attempts, error_message, updated_at
      FROM recommendation_ai_jobs
      WHERE " . implode(' AND ', $failWhere) . "
      ORDER BY updated_at DESC, id DESC
      LIMIT 8
    ";
    $failStmt = $db->prepare($failSql);
    $failStmt->execute($queueParams);
    $aiQueueFailures = $failStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
} catch (PDOException $e) {
  $aiQueueEnabled = false;
}
$theme = ['logo' => '', 'color' => '#0a2e36'];
if ($org) {
  $stmt = $db->prepare("SELECT logo_url, theme_variant FROM organizations WHERE id = ?");
  $stmt->execute([$org]);
  $orgData = $stmt->fetch(PDO::FETCH_ASSOC);
  if ($orgData) {
    $theme['logo'] = $orgData['logo_url'] ?? '';
    $theme['color'] = $orgData['theme_variant'] === 'jlr' ? '#4a4a4a' : (in_array($orgData['theme_variant'], ['land_rover', 'jaguar'], true) ? '#1A1A1A' : '#0a6280');
  }
}
?>
<!DOCTYPE html>
<html>
<head>
  <?php include __DIR__ . "/includes/head_favicon.php"; ?>
<meta charset="UTF-8">
  <title>Admin Tools - DealerFAI</title>
  <?php dealerfai_theme_head($theme ?? null); ?>
  <style nonce="<?= dealerfai_csp_nonce() ?>">
    body {
      font-family: Arial, sans-serif;
      background: #f4f6f8;
      margin: 0;
      padding: 0;
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
    .container {
      max-width: 700px;
      margin: auto;
      padding: 40px;
    }
    .card {
      background: white;
      padding: 30px;
      border-radius: 8px;
      box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    }
    h2 {
      margin-top: 0;
      color: #0a6280;
    }
    .section {
      margin-top: 28px;
      padding-top: 18px;
      border-top: 1px solid #e7edf3;
    }
    .section:first-of-type {
      margin-top: 18px;
      padding-top: 0;
      border-top: 0;
    }
    .section-title {
      margin: 0 0 12px 0;
      color: #1b2c40;
      font-size: 16px;
      letter-spacing: 0.02em;
      text-transform: uppercase;
    }
    .button-group {
      display: flex;
      flex-wrap: wrap;
      gap: 12px 14px;
    }
    .button {
      display: inline-block;
      background: #0a6280;
      color: white;
      padding: 12px 20px;
      border-radius: 6px;
      text-decoration: none;
      font-size: 16px;
      margin-top: 20px;
      margin-right: 15px;
    }
    .button:hover {
      background: #084c63;
    }
    .button.secondary {
      background: #e6ebf2;
      color: #1b2c40;
      border: 1px solid #c7d0d8;
    }
    .section-note {
      margin: 10px 0 0 0;
      color: #6b7280;
      font-size: 13px;
    }
    .queue-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
      gap: 10px;
      margin-top: 10px;
      margin-bottom: 14px;
    }
    .queue-tile {
      border: 1px solid #d9e1e8;
      border-radius: 8px;
      padding: 10px 12px;
      background: #f8fafc;
    }
    .queue-label {
      font-size: 12px;
      color: #596579;
      text-transform: uppercase;
      letter-spacing: 0.04em;
    }
    .queue-value {
      font-size: 20px;
      font-weight: 700;
      color: #13253a;
      margin-top: 4px;
    }
    .queue-fails {
      margin: 0;
      padding-left: 18px;
      color: #21334a;
      font-size: 13px;
    }
    .queue-fails li {
      margin-bottom: 6px;
    }
    .queue-empty {
      margin: 0;
      color: #5f6f82;
      font-size: 13px;
    }
    .queue-filter-form {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      margin-bottom: 10px;
      align-items: flex-end;
    }
    .queue-filter-control {
      display: flex;
      flex-direction: column;
      gap: 4px;
      min-width: 140px;
    }
    .queue-filter-control label {
      color: #5b6677;
      font-size: 12px;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.02em;
    }
    .queue-filter-control select,
    .queue-filter-control input[type="date"] {
      border: 1px solid #cdd6df;
      border-radius: 6px;
      font-size: 13px;
      padding: 7px 8px;
      color: #1f2f44;
      background: #fff;
    }
    .queue-filter-actions {
      display: flex;
      gap: 8px;
      align-items: center;
      margin-left: auto;
    }
    .queue-filter-btn {
      border: 1px solid #0a6280;
      background: #0a6280;
      color: #fff;
      border-radius: 6px;
      padding: 8px 12px;
      font-size: 13px;
      text-decoration: none;
      cursor: pointer;
    }
    .queue-filter-btn.secondary {
      border-color: #c7d0d8;
      background: #f3f6f9;
      color: #334255;
    }
    .queue-filter-error {
      margin: 6px 0 0 0;
      color: #b42318;
      font-size: 13px;
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
  <div class="container">
    <div class="card">
      <h2>Welcome, <?= htmlspecialchars($name) ?> (<?= htmlspecialchars($org) ?>)</h2>
      <p>You are viewing the DealerFAI Admin Tools panel. Use the sections below to manage scoring, products, organizations, users, and system settings.</p>

      <div class="section">
        <div class="section-title">Scoring & AI</div>
	        <div class="button-group">
	          <a class="button" href="admin_scoring_log.php">Scoring Log</a>
	          <a class="button" href="view_scoring_log.php">Scoring Log File</a>
		          <a class="button" href="admin_scoring_action_rules.php?prefill_target_type=accessory">Accessory Action Rules</a>
		          <a class="button" href="admin_scoring_action_rules.php">Action Rules (Direct)</a>
		          <a class="button" href="admin_product_overrides.php">Product Approved Facts</a>
		          <a class="button" href="admin_ai_prompt_context.php">AI Prompt Data</a>
		        </div>
          <p class="section-note">Legacy Scoring Rules is being phased out and will be removed soon. Use Action Rules (Direct).</p>
	      </div>

      <div class="section">
        <div class="section-title">Products & Catalog</div>
        <div class="button-group">
          <a class="button" href="admin_products.php">All Products</a>
          <a class="button" href="admin_accessories.php">Accessories</a>
          <a class="button" href="admin_vehicles.php">Vehicles</a>
          <a class="button" href="admin_store_products.php">Store Products</a>
          <a class="button" href="admin_org_products.php">Organization Products</a>
          <a class="button" href="admin_credit_app_questions.php">Credit App Questions</a>
        </div>
      </div>

      <div class="section">
        <div class="section-title">Organizations & Users</div>
        <div class="button-group">
          <a class="button" href="admin_organizations.php">Organizations</a>
          <a class="button" href="manage_users.php">Manage Users</a>
          <a class="button secondary" href="user_create.php">Create User</a>
          <a class="button secondary" href="user_list.php">User List</a>
        </div>
      </div>

      <div class="section">
        <div class="section-title">Billing & Reporting</div>
        <div class="button-group">
          <a class="button" href="admin_billing.php">Billing</a>
          <a class="button secondary" href="reporting_admin.php">Reporting</a>
        </div>
      </div>

      <div class="section">
        <div class="section-title">System & Alerts</div>
        <div class="button-group">
          <a class="button" href="admin_error_alerts.php">Admin Alerts<?php if ($adminAlertCount > 0): ?> <span class="badge"><?= $adminAlertCount ?></span><?php endif; ?></a>
          <a class="button secondary" href="security_log.php">🔐 Security Log</a>
        </div>
        <div class="mt-14">
          <h3 style="margin:0 0 8px 0; color:#1b2c40; font-size:15px;">AI Explanation Queue Health</h3>
          <?php if ($aiQueueEnabled): ?>
            <form class="queue-filter-form" method="get" action="admin_tools.php">
              <div class="queue-filter-control">
                <label for="queue_date_field">Date Field</label>
                <select id="queue_date_field" name="queue_date_field">
                  <option value="updated" <?= $aiQueueDateField === 'updated_at' ? 'selected' : '' ?>>Updated</option>
                  <option value="created" <?= $aiQueueDateField === 'created_at' ? 'selected' : '' ?>>Created</option>
                </select>
              </div>
              <div class="queue-filter-control">
                <label for="queue_range">Range</label>
                <select id="queue_range" name="queue_range">
                  <option value="today" <?= $aiQueueRange === 'today' ? 'selected' : '' ?>>Today</option>
                  <option value="current_month" <?= $aiQueueRange === 'current_month' ? 'selected' : '' ?>>Current Month</option>
                  <option value="last_month" <?= $aiQueueRange === 'last_month' ? 'selected' : '' ?>>Last Month</option>
                </select>
              </div>
              <div class="queue-filter-actions">
                <button type="submit" class="queue-filter-btn">Apply</button>
                <a class="queue-filter-btn secondary" href="admin_tools.php">Reset</a>
              </div>
            </form>
            <p class="section-note" class="mt-0">Showing: <?= htmlspecialchars($aiQueueFilterLabel) ?></p>
            <?php if ($aiQueueFilterError !== ''): ?>
              <p class="queue-filter-error"><?= htmlspecialchars($aiQueueFilterError) ?></p>
            <?php endif; ?>
            <div class="queue-grid">
              <div class="queue-tile">
                <div class="queue-label">Pending</div>
                <div class="queue-value"><?= (int)$aiQueueCounts['pending'] ?></div>
              </div>
              <div class="queue-tile">
                <div class="queue-label">Processing</div>
                <div class="queue-value"><?= (int)$aiQueueCounts['processing'] ?></div>
              </div>
              <div class="queue-tile">
                <div class="queue-label">Done</div>
                <div class="queue-value"><?= (int)$aiQueueCounts['done'] ?></div>
              </div>
              <div class="queue-tile">
                <div class="queue-label">Failed</div>
                <div class="queue-value"><?= (int)$aiQueueCounts['failed'] ?></div>
              </div>
            </div>
            <?php if (!empty($aiQueueFailures)): ?>
              <p class="section-note" style="margin-bottom:8px;">Most recent failed jobs:</p>
              <ul class="queue-fails">
                <?php foreach ($aiQueueFailures as $failure): ?>
                  <li>
                    #<?= (int)($failure['id'] ?? 0) ?>
                    deal <?= (int)($failure['deal_id'] ?? 0) ?>
                    / <?= htmlspecialchars((string)($failure['product_code'] ?? 'unknown')) ?>
                    (attempts: <?= (int)($failure['attempts'] ?? 0) ?>)
                    <br>
                    <span style="color:#6a7788;"><?= htmlspecialchars((string)($failure['error_message'] ?? 'Unknown error')) ?></span>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          <?php else: ?>
            <p class="queue-empty">AI queue table not available in this environment.</p>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</body>
</html>
