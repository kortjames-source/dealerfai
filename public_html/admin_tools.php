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
?>
<!DOCTYPE html>
<html>
<head>
  <?php include __DIR__ . "/includes/head_favicon.php"; ?>
<meta charset="UTF-8">
  <title>Admin Tools - DealerFAI</title>
  <?php dealerfai_theme_head($theme ?? null); ?>
  <style nonce="<?= dealerfai_csp_nonce() ?>">
    /* Specific page overrides */
    .section { margin-top: 2.5rem; padding-top: 1.5rem; border-top: 1px solid #e2e8f0; }
    .section:first-of-type { margin-top: 1rem; padding-top: 0; border-top: 0; }
    .section-title { margin-bottom: 1.5rem; color: #64748b; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; }
    .tool-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 1rem; }
    .tool-btn { background: white; border: 1px solid #e2e8f0; padding: 1.25rem; border-radius: var(--radius-md); text-decoration: none; color: #0f172a; font-weight: 600; transition: all 0.2s; box-shadow: var(--shadow-sm); display: flex; align-items: center; gap: 0.75rem; }
    .tool-btn:hover { border-color: var(--brand-color); transform: translateY(-2px); box-shadow: var(--shadow-md); color: var(--brand-color); }
    .tool-btn i { font-size: 1.1rem; color: #94a3b8; }
    .tool-btn:hover i { color: var(--brand-color); }
    .queue-tile { background: white; border: 1px solid #e2e8f0; padding: 1rem; border-radius: var(--radius-md); box-shadow: var(--shadow-sm); }
    .queue-label { font-size: 0.7rem; color: #64748b; text-transform: uppercase; font-weight: 700; letter-spacing: 0.025em; }
    .queue-value { font-size: 1.5rem; font-weight: 700; color: #0f172a; margin-top: 0.25rem; }
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
      
      <?php if (in_array('Admin', $roles, true)): ?>
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
        <a href="admin_tools" class="sidebar-link active">
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
        DealerFAI <i class="fa-solid fa-chevron-right" style="font-size: 0.75rem; margin: 0 0.5rem; opacity: 0.5;"></i> Admin Tools
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
      <div style="margin-bottom: 2.5rem;">
        <h1 class="text-gradient" style="margin-bottom: 0.5rem; display: inline-block;">Administrative Tools</h1>
        <p class="text-muted">Manage system-wide configurations, product catalogs, and AI logic.</p>
      </div>

      <div class="section">
        <div class="section-title">Scoring & AI Engine</div>
        <div class="tool-grid">
          <a class="tool-btn" href="admin_scoring_log"><i class="fa-solid fa-list-check"></i> Scoring Log</a>
          <a class="tool-btn" href="view_scoring_log"><i class="fa-solid fa-file-code"></i> Scoring Log File</a>
          <a class="tool-btn" href="admin_scoring_action_rules?prefill_target_type=accessory"><i class="fa-solid fa-gears"></i> Accessory Action Rules</a>
          <a class="tool-btn" href="admin_scoring_action_rules"><i class="fa-solid fa-bolt"></i> Action Rules (Direct)</a>
          <a class="tool-btn" href="admin_product_overrides"><i class="fa-solid fa-check-double"></i> Product Approved Facts</a>
          <a class="tool-btn" href="admin_ai_prompt_context"><i class="fa-solid fa-brain"></i> AI Prompt Data</a>
        </div>
      </div>

      <div class="section">
        <div class="section-title">Products & Catalog</div>
        <div class="tool-grid">
          <a class="tool-btn" href="admin_products"><i class="fa-solid fa-boxes-stacked"></i> All Products</a>
          <a class="tool-btn" href="admin_accessories"><i class="fa-solid fa-tags"></i> Accessories</a>
          <a class="tool-btn" href="admin_vehicles"><i class="fa-solid fa-car"></i> Vehicles</a>
          <a class="tool-btn" href="admin_store_products"><i class="fa-solid fa-store"></i> Store Products</a>
          <a class="tool-btn" href="admin_org_products"><i class="fa-solid fa-building"></i> Org Products</a>
          <a class="tool-btn" href="admin_credit_app_questions"><i class="fa-solid fa-question-circle"></i> Credit App Questions</a>
        </div>
      </div>

      <div class="section">
        <div class="section-title">System & Alerts</div>
        <div class="tool-grid">
          <a class="tool-btn" href="admin_error_alerts"><i class="fa-solid fa-bell"></i> System Alerts</a>
          <a class="tool-btn" href="security_log"><i class="fa-solid fa-shield-halved"></i> Security Log</a>
          <a class="tool-btn" href="admin_billing"><i class="fa-solid fa-credit-card"></i> Billing</a>
          <a class="tool-btn" href="reporting_admin"><i class="fa-solid fa-chart-line"></i> Reporting</a>
        </div>
      </div>

      <div class="section" style="margin-bottom: 2rem;">
        <div class="section-title">AI Explanation Queue Health</div>
        <div class="glass" style="padding: 1.5rem; border: 1px solid var(--border-color);">
          <?php if ($aiQueueEnabled): ?>
            <form class="filters" method="get" action="admin_tools.php" style="display: flex; gap: 1rem; align-items: center; margin-bottom: 1.5rem;">
              <select name="queue_date_field" class="form-control" style="max-width: 150px; margin: 0;">
                <option value="updated" <?= $aiQueueDateField === 'updated_at' ? 'selected' : '' ?>>Updated At</option>
                <option value="created" <?= $aiQueueDateField === 'created_at' ? 'selected' : '' ?>>Created At</option>
              </select>
              <select name="queue_range" class="form-control" style="max-width: 180px; margin: 0;">
                <option value="today" <?= $aiQueueRange === 'today' ? 'selected' : '' ?>>Today</option>
                <option value="current_month" <?= $aiQueueRange === 'current_month' ? 'selected' : '' ?>>Current Month</option>
                <option value="last_month" <?= $aiQueueRange === 'last_month' ? 'selected' : '' ?>>Last Month</option>
              </select>
              <button type="submit" class="btn">Apply</button>
              <a href="admin_tools.php" class="btn btn-secondary">Reset</a>
            </form>

            <div class="metric-grid" style="margin-bottom: 1.5rem;">
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
                <div class="queue-value" style="color: #10b981;"><?= (int)$aiQueueCounts['done'] ?></div>
              </div>
              <div class="queue-tile">
                <div class="queue-label">Failed</div>
                <div class="queue-value" style="color: #ef4444;"><?= (int)$aiQueueCounts['failed'] ?></div>
              </div>
            </div>

            <?php if (!empty($aiQueueFailures)): ?>
              <h4 style="font-size: 0.875rem; margin-bottom: 1rem;">Recent Failures</h4>
              <div class="data-table-card">
                <table>
                  <thead>
                    <tr style="background: #f1f5f9;">
                      <th style="background: transparent; color: #475569; text-align: left;">Job ID</th>
                      <th style="background: transparent; color: #475569; text-align: left;">Deal ID</th>
                      <th style="background: transparent; color: #475569; text-align: left;">Product</th>
                      <th style="background: transparent; color: #475569; text-align: left;">Error</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($aiQueueFailures as $failure): ?>
                      <tr>
                        <td>#<?= (int)$failure['id'] ?></td>
                        <td><?= (int)$failure['deal_id'] ?></td>
                        <td><?= htmlspecialchars((string)$failure['product_code']) ?></td>
                        <td style="color: #ef4444; font-size: 0.8rem;"><?= htmlspecialchars((string)$failure['error_message']) ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          <?php else: ?>
            <p class="text-muted">AI queue monitoring is not available in this environment.</p>
          <?php endif; ?>
        </div>
      </div>
    </main>

    <footer style="background: white; border-top: 1px solid #e2e8f0; color: #64748b; padding: 1.5rem; text-align: center; position: static;">
      &copy; <?= date("Y") ?> DealerFAI. All rights reserved.
    </footer>
  </div>
</body>
</html>
