<?php
include 'auth.php';
require_once __DIR__ . '/includes/theme_head.php';
include 'db.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/helpers/session_utils.php';
require_once __DIR__ . '/helpers/billing_helpers.php';

$roles = load_session_roles();
if (!in_array('Admin', $roles, true)) {
  http_response_code(403);
  echo 'Unauthorized';
  exit;
}
$adminAlertCount = get_admin_alert_count($db);

$orgContextId = get_admin_organization_context();
$org_id = get_effective_organization();

$theme = ['logo' => '', 'color' => '#0066cc'];
if ($org_id) {
  $stmt = $db->prepare("SELECT logo_url, theme_variant FROM organizations WHERE id = ?");
  $stmt->execute([$org_id]);
  $org = $stmt->fetch(PDO::FETCH_ASSOC);
  if ($org) {
    $theme['logo'] = $org['logo_url'] ?? '';
    $theme['color'] = $org['theme_variant'] === 'jlr' ? '#4a4a4a' : (in_array($org['theme_variant'], ['land_rover', 'jaguar'], true) ? '#1A1A1A' : '#0066cc');
  }
}

function fetch_usage_summary(PDO $db, int $organizationId, string $startTs, string $endTs): array
{
  $hasEventType = column_exists($db, 'usage_events', 'event_type');
  $hasUnitPrice = column_exists($db, 'usage_events', 'unit_price');
  $hasTotalPrice = column_exists($db, 'usage_events', 'total_price');
  $hasQuantity = column_exists($db, 'usage_events', 'quantity');

  if (!$hasEventType) {
    $stmt = $db->prepare("
      SELECT 'deal_submission' AS event_type,
             COUNT(*) AS total_qty,
             0 AS total_amount
      FROM usage_events
      WHERE organization_id = ?
        AND submitted_at BETWEEN ? AND ?
    ");
    $stmt->execute([$organizationId, $startTs, $endTs]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total_qty' => 0, 'total_amount' => 0];
    return [
      'deal_submission' => [
        'qty' => (int)($row['total_qty'] ?? 0),
        'amount' => (float)($row['total_amount'] ?? 0),
        'rate' => 0.0,
      ],
    ];
  }

  $amountExpr = $hasTotalPrice ? 'COALESCE(total_price, 0)' : ($hasUnitPrice ? 'COALESCE(unit_price, 0)' : '0');
  $qtyExpr = $hasQuantity ? 'COALESCE(quantity, 1)' : '1';
  $stmt = $db->prepare("
    SELECT COALESCE(event_type, 'deal_submission') AS event_type,
           SUM($qtyExpr) AS total_qty,
           SUM($amountExpr) AS total_amount
    FROM usage_events
    WHERE organization_id = ?
      AND submitted_at BETWEEN ? AND ?
    GROUP BY COALESCE(event_type, 'deal_submission')
  ");
  $stmt->execute([$organizationId, $startTs, $endTs]);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
  $summary = [];
  foreach ($rows as $row) {
    $qty = (int)($row['total_qty'] ?? 0);
    $amount = (float)($row['total_amount'] ?? 0);
    $summary[$row['event_type'] ?? 'deal_submission'] = [
      'qty' => $qty,
      'amount' => $amount,
      'rate' => $qty > 0 ? round($amount / $qty, 2) : 0.0,
    ];
  }
  return $summary;
}

$orgRows = $db->query("SELECT id, name FROM organizations ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$orgNames = [];
foreach ($orgRows as $row) {
  $orgNames[(int)$row['id']] = $row['name'];
}

$orgParam = isset($_GET['org']) && $_GET['org'] !== '' ? (int)$_GET['org'] : null;
$selectedOrgId = $orgContextId ?: ($orgParam ?: (count($orgNames) === 1 ? (int)array_key_first($orgNames) : null));
if ($selectedOrgId && !array_key_exists($selectedOrgId, $orgNames)) {
  $selectedOrgId = null;
}

$defaultPeriod = date('Y-m', strtotime('first day of last month'));
$periodParam = $_GET['period'] ?? $defaultPeriod;
$period = preg_match('/^\d{4}-\d{2}$/', $periodParam) ? $periodParam : $defaultPeriod;

$flash = '';
$ratePerDeal = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!dealerfai_csrf_validate($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    die('Invalid request.');
  }

  $postOrg = isset($_POST['org']) ? (int)$_POST['org'] : null;
  $postPeriod = $_POST['period'] ?? $defaultPeriod;
  if ($postOrg && array_key_exists($postOrg, $orgNames)) {
    $selectedOrgId = $postOrg;
  }
  if (preg_match('/^\d{4}-\d{2}$/', $postPeriod)) {
    $period = $postPeriod;
  }

  $action = $_POST['action'] ?? '';
  if ($action === 'generate' && $selectedOrgId) {
    $periodStart = $period . '-01';
    $periodEnd = date('Y-m-t', strtotime($periodStart));
    $startTs = $periodStart . ' 00:00:00';
    $endTs = $periodEnd . ' 23:59:59';

    $existingStmt = $db->prepare("
      SELECT id FROM billing_invoices
      WHERE organization_id = ? AND period_start = ? AND period_end = ?
      LIMIT 1
    ");
    $existingStmt->execute([$selectedOrgId, $periodStart, $periodEnd]);
    $existingId = $existingStmt->fetchColumn();

    if ($existingId) {
      $flash = 'Invoice already exists for that period.';
    } else {
      $summary = fetch_usage_summary($db, $selectedOrgId, $startTs, $endTs);
      $rates = resolve_org_billing_rates($db, $selectedOrgId);

      $dealSummary = $summary['deal_submission'] ?? ['qty' => 0, 'amount' => 0.0, 'rate' => $rates['deal_rate']];
      $accessorySummary = $summary['accessory_presentation'] ?? ['qty' => 0, 'amount' => 0.0, 'rate' => $rates['accessory_rate']];
      $serviceSummary = $summary['service_ro_sent'] ?? ['qty' => 0, 'amount' => 0.0, 'rate' => $rates['service_rate']];

      if ($dealSummary['amount'] <= 0 && $dealSummary['qty'] > 0) {
        $dealSummary['amount'] = round($dealSummary['qty'] * $rates['deal_rate'], 2);
      }
      if ($accessorySummary['amount'] <= 0 && $accessorySummary['qty'] > 0) {
        $accessorySummary['amount'] = round($accessorySummary['qty'] * $rates['accessory_rate'], 2);
      }
      if ($serviceSummary['amount'] <= 0 && $serviceSummary['qty'] > 0) {
        $serviceSummary['amount'] = round($serviceSummary['qty'] * $rates['service_rate'], 2);
      }

      $subtotal = round(
        (float)$dealSummary['amount'] +
        (float)$accessorySummary['amount'] +
        (float)$serviceSummary['amount'],
        2
      );

      $columns = ['organization_id', 'period_start', 'period_end', 'subtotal', 'created_at', 'status'];
      $values = [$selectedOrgId, $periodStart, $periodEnd, $subtotal, date('Y-m-d H:i:s'), 'draft'];

      if (column_exists($db, 'billing_invoices', 'rate_per_deal')) {
        $columns[] = 'rate_per_deal';
        $values[] = $dealSummary['qty'] > 0 ? (float)$dealSummary['rate'] : (float)$rates['deal_rate'];
      }
      if (column_exists($db, 'billing_invoices', 'total_deals')) {
        $columns[] = 'total_deals';
        $values[] = (int)$dealSummary['qty'];
      }
      if (column_exists($db, 'billing_invoices', 'rate_per_accessory')) {
        $columns[] = 'rate_per_accessory';
        $values[] = $accessorySummary['qty'] > 0 ? (float)$accessorySummary['rate'] : (float)$rates['accessory_rate'];
      }
      if (column_exists($db, 'billing_invoices', 'total_accessory_presentations')) {
        $columns[] = 'total_accessory_presentations';
        $values[] = (int)$accessorySummary['qty'];
      }
      if (column_exists($db, 'billing_invoices', 'rate_per_service')) {
        $columns[] = 'rate_per_service';
        $values[] = $serviceSummary['qty'] > 0 ? (float)$serviceSummary['rate'] : (float)$rates['service_rate'];
      }
      if (column_exists($db, 'billing_invoices', 'total_service_ros')) {
        $columns[] = 'total_service_ros';
        $values[] = (int)$serviceSummary['qty'];
      }

      $placeholders = implode(', ', array_fill(0, count($columns), '?'));
      $insert = $db->prepare("
        INSERT INTO billing_invoices
          (" . implode(', ', $columns) . ")
        VALUES
          (" . $placeholders . ")
      ");
      $insert->execute($values);
      $invoiceId = (int)$db->lastInsertId();
      if ($invoiceId > 0) {
        $invoiceNumber = sprintf('INV-%d-%s-%d', $selectedOrgId, date('Ym', strtotime($periodStart)), $invoiceId);
        $update = $db->prepare("UPDATE billing_invoices SET invoice_number = ? WHERE id = ?");
        $update->execute([$invoiceNumber, $invoiceId]);
      }
      $flash = 'Invoice created.';
    }
  }
}

$periodStart = $period . '-01';
$periodEnd = date('Y-m-t', strtotime($periodStart));
$startTs = $periodStart . ' 00:00:00';
$endTs = $periodEnd . ' 23:59:59';

$usageRows = [];
$usageCount = 0;
$usageSummary = [];
$currentRates = null;
if ($selectedOrgId) {
  $currentRates = resolve_org_billing_rates($db, $selectedOrgId);
  $usageFields = [
    'usage_events.submitted_at',
    'usage_events.submission_type',
    'usage_events.deal_id',
    'deals.deal_number',
    'deals.customer_name'
  ];
  if (column_exists($db, 'usage_events', 'event_type')) {
    $usageFields[] = 'usage_events.event_type';
  }
  if (column_exists($db, 'usage_events', 'unit_price')) {
    $usageFields[] = 'usage_events.unit_price';
  }
  if (column_exists($db, 'usage_events', 'total_price')) {
    $usageFields[] = 'usage_events.total_price';
  }
  $usageStmt = $db->prepare("
    SELECT " . implode(', ', $usageFields) . "
    FROM usage_events
    JOIN deals ON deals.id = usage_events.deal_id
    WHERE usage_events.organization_id = ?
      AND usage_events.submitted_at BETWEEN ? AND ?
    ORDER BY usage_events.submitted_at DESC
  ");
  $usageStmt->execute([$selectedOrgId, $startTs, $endTs]);
  $usageRows = $usageStmt->fetchAll(PDO::FETCH_ASSOC);
  $usageCount = count($usageRows);
  $usageSummary = fetch_usage_summary($db, $selectedOrgId, $startTs, $endTs);
}

$invoiceRows = [];
$csrfToken = dealerfai_csrf_get_token();
$hasInvoiceAccessory = false;
$hasInvoiceService = false;
if (!empty($orgNames)) {
  $hasInvoiceAccessory = column_exists($db, 'billing_invoices', 'total_accessory_presentations');
  $hasInvoiceService = column_exists($db, 'billing_invoices', 'total_service_ros');
  $invoiceQuery = "
    SELECT billing_invoices.*,
           organizations.name AS organization_name
    FROM billing_invoices
    LEFT JOIN organizations ON organizations.id = billing_invoices.organization_id
  ";
  $params = [];
  if ($selectedOrgId) {
    $invoiceQuery .= " WHERE billing_invoices.organization_id = ?";
    $params[] = $selectedOrgId;
  }
  $invoiceQuery .= " ORDER BY billing_invoices.period_start DESC, billing_invoices.organization_id ASC";
  $invoiceStmt = $db->prepare($invoiceQuery);
  $invoiceStmt->execute($params);
  $invoiceRows = $invoiceStmt->fetchAll(PDO::FETCH_ASSOC);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php include __DIR__ . "/includes/head_favicon.php"; ?>
<meta charset="UTF-8">
  <title>Admin Billing - DealerFAI</title>
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
    nav a:hover {
      text-decoration: underline;
    }
    main {
      padding: 30px 40px;
    }
    h2 {
      color: <?= htmlspecialchars($theme['color']) ?>;
      text-align: center;
      margin-bottom: 20px;
    }
    .panel {
      background: white;
      padding: 24px;
      border-radius: 10px;
      box-shadow: 0 1px 6px rgba(0,0,0,0.1);
      margin-bottom: 24px;
    }
    .filters {
      display: flex;
      flex-wrap: wrap;
      gap: 12px;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 16px;
    }
    .filters select,
    .filters input {
      padding: 10px 12px;
      border-radius: 6px;
      border: 1px solid #c7d0d8;
      min-width: 200px;
    }
    .filters button {
      padding: 10px 16px;
      border-radius: 6px;
      border: none;
      cursor: pointer;
      font-weight: 600;
      background-color: <?= htmlspecialchars($theme['color']) ?>;
      color: white;
    }
    .filters button.secondary {
      background: #e5e9ee;
      color: #203040;
    }
    .flash {
      padding: 12px 16px;
      border-radius: 8px;
      background: #e5f3ff;
      color: #114a7a;
      margin-bottom: 16px;
    }
    table {
      width: 100%;
      border-collapse: collapse;
      background: white;
      margin-top: 10px;
    }
    th, td {
      padding: 12px;
      border-bottom: 1px solid #ddd;
      text-align: left;
      font-size: 14px;
    }
    th {
      background-color: <?= htmlspecialchars($theme['color']) ?>;
      color: white;
    }
    .muted {
      color: #6a7785;
      font-size: 14px;
    }
    .pill {
      display: inline-block;
      padding: 4px 10px;
      border-radius: 999px;
      background: #eef3f7;
      font-size: 12px;
      text-transform: uppercase;
      letter-spacing: 0.04em;
    }
  </style>
</head>
<body>
  <header class="pos-relative">
    <?php if (!empty($theme['logo'])): ?>
      <img src="<?= htmlspecialchars($theme['logo']) ?>" alt="Dealer Logo" style="max-height:60px;">
    <?php else: ?>
      <h1>DealerFAI Admin</h1>
    <?php endif; ?>
    <div class="logout">
      <a href="admin_error_alerts.php" class="nav-link-white">Alerts<?php if ($adminAlertCount > 0): ?> <span style="display:inline-block; min-width:18px; padding:2px 8px; border-radius:999px; background:#d7263d; color:#fff; font-size:12px; font-weight:bold; text-align:center; margin-left:6px;"><?= $adminAlertCount ?></span><?php endif; ?></a>
      <a href="logout.php" class="nav-link-white">Log Out</a>
    </div>
  </header>
  <nav>
    <a href="dashboard.php">Dashboard</a>
    <a href="view_deals.php">View Deals</a>
    <a href="create_deal.php">Create Deal</a>
    <a href="admin_tools.php">Admin Tools</a>
  </nav>

  <main>
    <h2>Billing</h2>

    <?php if ($flash): ?>
      <div class="flash"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>

    <div class="panel">
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <div class="filters">
          <div>
            <label class="muted" for="org">Organization</label><br>
            <select id="org" name="org" required>
              <option value="">Select organization</option>
              <?php foreach ($orgNames as $id => $name): ?>
                <option value="<?= (int)$id ?>" <?= $selectedOrgId === (int)$id ? 'selected' : '' ?>><?= htmlspecialchars($name) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="muted" for="period">Billing Month</label><br>
            <input id="period" name="period" type="month" value="<?= htmlspecialchars($period) ?>" required>
          </div>
          <div class="flex-end">
            <button type="submit" name="action" value="view" class="secondary">Update View</button>
            <button type="submit" name="action" value="generate">Generate Invoice</button>
          </div>
        </div>
      </form>
      <?php if ($currentRates): ?>
        <div class="muted">
          Plan rates: Deals $<?= number_format((float)$currentRates['deal_rate'], 2) ?>,
          Accessories $<?= number_format((float)$currentRates['accessory_rate'], 2) ?>,
          Service $<?= number_format((float)$currentRates['service_rate'], 2) ?>.
        </div>
      <?php endif; ?>
    </div>

    <div class="panel">
      <h3>Usage for <?= htmlspecialchars($period) ?></h3>
      <?php if ($selectedOrgId): ?>
        <div class="muted"><?= $usageCount ?> usage events for <?= htmlspecialchars($orgNames[$selectedOrgId] ?? 'Organization') ?>.</div>
        <?php if (!empty($usageSummary)): ?>
          <div class="muted">
            Deals: <?= (int)($usageSummary['deal_submission']['qty'] ?? 0) ?>,
            Accessories: <?= (int)($usageSummary['accessory_presentation']['qty'] ?? 0) ?>,
            Service: <?= (int)($usageSummary['service_ro_sent']['qty'] ?? 0) ?>.
          </div>
        <?php endif; ?>
        <?php if (!empty($usageRows)): ?>
          <table>
            <thead>
              <tr>
                <th>Submitted</th>
                <th>Deal</th>
                <th>Customer</th>
                <th>Event</th>
                <th>Rate</th>
                <th>Total</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($usageRows as $row): ?>
                <tr>
                  <td><?= htmlspecialchars($row['submitted_at']) ?></td>
                  <td>#<?= htmlspecialchars($row['deal_number'] ?? $row['deal_id']) ?></td>
                  <td><?= htmlspecialchars($row['customer_name'] ?? '') ?></td>
                  <?php $eventLabel = $row['event_type'] ?? $row['submission_type'] ?? 'deal_submission'; ?>
                  <td><span class="pill"><?= htmlspecialchars($eventLabel) ?></span></td>
                  <td><?= isset($row['unit_price']) ? ('$' . number_format((float)$row['unit_price'], 2)) : '—' ?></td>
                  <td><?= isset($row['total_price']) ? ('$' . number_format((float)$row['total_price'], 2)) : '—' ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php else: ?>
          <p class="muted">No usage events found for this period.</p>
        <?php endif; ?>
      <?php else: ?>
        <p class="muted">Select an organization to view usage.</p>
      <?php endif; ?>
    </div>

    <div class="panel">
      <h3>Invoice History</h3>
      <?php if (!empty($invoiceRows)): ?>
        <table>
          <thead>
            <tr>
              <th>Invoice</th>
              <th>Organization</th>
              <th>Period</th>
              <th>Total Deals</th>
              <?php if ($hasInvoiceAccessory): ?>
                <th>Accessories</th>
              <?php endif; ?>
              <?php if ($hasInvoiceService): ?>
                <th>Service</th>
              <?php endif; ?>
              <th>Subtotal</th>
              <th>Status</th>
              <th>View</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($invoiceRows as $row): ?>
              <tr>
                <td><?= htmlspecialchars($row['invoice_number'] ?: ('#' . $row['id'])) ?></td>
                <td><?= htmlspecialchars($row['organization_name'] ?? '') ?></td>
                <td><?= htmlspecialchars($row['period_start']) ?> to <?= htmlspecialchars($row['period_end']) ?></td>
                <td><?= (int)$row['total_deals'] ?></td>
                <?php if ($hasInvoiceAccessory): ?>
                  <td><?= (int)($row['total_accessory_presentations'] ?? 0) ?></td>
                <?php endif; ?>
                <?php if ($hasInvoiceService): ?>
                  <td><?= (int)($row['total_service_ros'] ?? 0) ?></td>
                <?php endif; ?>
                <td>$<?= number_format((float)$row['subtotal'], 2) ?></td>
                <td><?= htmlspecialchars($row['status']) ?></td>
                <td><a href="admin_invoice.php?id=<?= (int)$row['id'] ?>">Open</a></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php else: ?>
        <p class="muted">No invoices found.</p>
      <?php endif; ?>
    </div>
  </main>
</body>
</html>
