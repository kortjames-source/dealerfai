<?php
include 'auth.php';
require_once __DIR__ . '/includes/theme_head.php';
include 'db.php';
require_once __DIR__ . '/helpers/session_utils.php';
require_once __DIR__ . '/helpers/billing_helpers.php';

$roles = load_session_roles();
if (!in_array('Admin', $roles, true)) {
  http_response_code(403);
  echo 'Unauthorized';
  exit;
}
$adminAlertCount = get_admin_alert_count($db);

$invoiceId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($invoiceId <= 0) {
  http_response_code(404);
  echo 'Invoice not found';
  exit;
}

$stmt = $db->prepare("
  SELECT billing_invoices.*,
         organizations.name AS organization_name,
         organizations.logo_url,
         organizations.theme_variant
  FROM billing_invoices
  JOIN organizations ON organizations.id = billing_invoices.organization_id
  WHERE billing_invoices.id = ?
  LIMIT 1
");
$stmt->execute([$invoiceId]);
$invoice = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$invoice) {
  http_response_code(404);
  echo 'Invoice not found';
  exit;
}

$themeColor = $invoice['theme_variant'] === 'jlr' ? '#4a4a4a' : (in_array($invoice['theme_variant'], ['land_rover', 'jaguar'], true) ? '#1A1A1A' : '#0a6280');
$periodStart = $invoice['period_start'] . ' 00:00:00';
$periodEnd = $invoice['period_end'] . ' 23:59:59';

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
  ORDER BY usage_events.submitted_at ASC
");
$usageStmt->execute([$invoice['organization_id'], $periodStart, $periodEnd]);
$usageRows = $usageStmt->fetchAll(PDO::FETCH_ASSOC);

$hasEventType = column_exists($db, 'usage_events', 'event_type');
$hasUnitPrice = column_exists($db, 'usage_events', 'unit_price');
$hasTotalPrice = column_exists($db, 'usage_events', 'total_price');
$hasQuantity = column_exists($db, 'usage_events', 'quantity');

$usageSummary = [];
if ($hasEventType) {
  $amountExpr = $hasTotalPrice ? 'COALESCE(total_price, 0)' : ($hasUnitPrice ? 'COALESCE(unit_price, 0)' : '0');
  $qtyExpr = $hasQuantity ? 'COALESCE(quantity, 1)' : '1';
  $summaryStmt = $db->prepare("
    SELECT COALESCE(event_type, 'deal_submission') AS event_type,
           SUM($qtyExpr) AS total_qty,
           SUM($amountExpr) AS total_amount
    FROM usage_events
    WHERE organization_id = ?
      AND submitted_at BETWEEN ? AND ?
    GROUP BY COALESCE(event_type, 'deal_submission')
  ");
  $summaryStmt->execute([$invoice['organization_id'], $periodStart, $periodEnd]);
  $usageSummary = $summaryStmt->fetchAll(PDO::FETCH_ASSOC);
}

$eventLabels = [
  'deal_submission' => 'Deal Submissions',
  'accessory_presentation' => 'Accessories Presentation',
  'service_ro_sent' => 'Service RO Sent',
];
$lineItems = [];
$totalDue = 0.0;
  $summaryCounts = [];
if (!empty($usageSummary)) {
  foreach ($usageSummary as $row) {
    $eventType = $row['event_type'] ?? 'deal_submission';
    $qty = (int)($row['total_qty'] ?? 0);
    if ($qty <= 0) {
      continue;
    }
    $amount = (float)($row['total_amount'] ?? 0);
    $rate = $qty > 0 ? round($amount / $qty, 2) : 0.0;
    $lineItems[] = [
      'label' => $eventLabels[$eventType] ?? $eventType,
      'qty' => $qty,
      'rate' => $rate,
      'amount' => $amount,
    ];
    $summaryCounts[$eventLabels[$eventType] ?? $eventType] = $qty;
    $totalDue += $amount;
  }
} else {
  $dealQty = isset($invoice['total_deals']) ? (int)$invoice['total_deals'] : 0;
  $dealRate = isset($invoice['rate_per_deal']) ? (float)$invoice['rate_per_deal'] : 0.0;
  if ($dealQty > 0) {
    $amount = round($dealQty * $dealRate, 2);
    $lineItems[] = ['label' => 'Deal Submissions', 'qty' => $dealQty, 'rate' => $dealRate, 'amount' => $amount];
    $summaryCounts['Deal Submissions'] = $dealQty;
    $totalDue += $amount;
  }
  if (isset($invoice['total_accessory_presentations'])) {
    $accQty = (int)($invoice['total_accessory_presentations'] ?? 0);
    $accRate = (float)($invoice['rate_per_accessory'] ?? 0);
    if ($accQty > 0) {
      $amount = round($accQty * $accRate, 2);
      $lineItems[] = ['label' => 'Accessories Presentation', 'qty' => $accQty, 'rate' => $accRate, 'amount' => $amount];
      $summaryCounts['Accessories Presentation'] = $accQty;
      $totalDue += $amount;
    }
  }
  if (isset($invoice['total_service_ros'])) {
    $svcQty = (int)($invoice['total_service_ros'] ?? 0);
    $svcRate = (float)($invoice['rate_per_service'] ?? 0);
    if ($svcQty > 0) {
      $amount = round($svcQty * $svcRate, 2);
      $lineItems[] = ['label' => 'Service RO Sent', 'qty' => $svcQty, 'rate' => $svcRate, 'amount' => $amount];
      $summaryCounts['Service RO Sent'] = $svcQty;
      $totalDue += $amount;
    }
  }
}
if ($totalDue <= 0) {
  $totalDue = (float)($invoice['subtotal'] ?? 0);
}

$invoiceNumber = $invoice['invoice_number'] ?: ('#' . $invoice['id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php include __DIR__ . "/includes/head_favicon.php"; ?>
<meta charset="UTF-8">
  <title>Invoice <?= htmlspecialchars($invoiceNumber) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php dealerfai_theme_head(); ?>
  <style nonce="<?= dealerfai_csp_nonce() ?>">
    body {
      margin: 0;
      font-family: "Segoe UI", sans-serif;
      background-color: #f4f6f8;
      color: #111111;
    }
    .invoice {
      max-width: 920px;
      margin: 40px auto;
      background: white;
      padding: 40px;
      border-radius: 12px;
      box-shadow: 0 12px 30px rgba(0,0,0,0.08);
    }
    .header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      border-bottom: 2px solid <?= htmlspecialchars($themeColor) ?>;
      padding-bottom: 20px;
      margin-bottom: 24px;
      gap: 16px;
    }
    .brand h1 {
      margin: 0;
      color: <?= htmlspecialchars($themeColor) ?>;
      font-size: 28px;
    }
    .brand img {
      max-height: 60px;
      max-width: 240px;
      display: block;
    }
    .meta {
      text-align: right;
      font-size: 14px;
    }
    .meta strong {
      display: block;
      font-size: 16px;
    }
    .admin-controls {
      margin-top: 8px;
      font-size: 12px;
    }
    .admin-controls a {
      color: <?= htmlspecialchars($themeColor) ?>;
      text-decoration: none;
      font-weight: 600;
    }
    .admin-controls a:hover {
      text-decoration: underline;
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
    .billto {
      margin-bottom: 20px;
      font-size: 15px;
    }
    .summary {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      gap: 12px;
      margin: 20px 0;
    }
    .summary .card {
      background: #f6f8fb;
      padding: 14px;
      border-radius: 10px;
    }
    table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 12px;
    }
    th, td {
      padding: 12px;
      border-bottom: 1px solid #e5e9ee;
      text-align: left;
      font-size: 14px;
    }
    th {
      background: <?= htmlspecialchars($themeColor) ?>;
      color: white;
    }
    .totals {
      margin-top: 20px;
      text-align: right;
      font-size: 16px;
    }
    .actions {
      text-align: right;
      margin-top: 20px;
    }
    .actions button {
      padding: 10px 16px;
      border-radius: 6px;
      border: none;
      cursor: pointer;
      background: <?= htmlspecialchars($themeColor) ?>;
      color: white;
      font-weight: 600;
    }
    @media print {
      body {
        background: white;
      }
      .invoice {
        box-shadow: none;
        margin: 0;
        border-radius: 0;
      }
      .actions {
        display: none;
      }
      .admin-controls {
        display: none;
      }
    }
  </style>
</head>
<body>
  <div class="invoice">
    <div class="header">
      <div class="brand">
        <?php if (!empty($invoice['logo_url'])): ?>
          <img src="<?= htmlspecialchars($invoice['logo_url']) ?>" alt="Logo">
        <?php else: ?>
          <h1>DealerFAI</h1>
        <?php endif; ?>
        <div>Invoice <?= htmlspecialchars($invoiceNumber) ?></div>
      </div>
      <div class="meta">
        <strong><?= htmlspecialchars($invoice['organization_name']) ?></strong>
        <div>Period: <?= htmlspecialchars($invoice['period_start']) ?> to <?= htmlspecialchars($invoice['period_end']) ?></div>
        <div>Status: <?= htmlspecialchars($invoice['status']) ?></div>
        <div>Created: <?= htmlspecialchars($invoice['created_at']) ?></div>
        <div class="admin-controls">
          <a href="admin_error_alerts.php">Alerts<?php if ($adminAlertCount > 0): ?> <span class="badge"><?= $adminAlertCount ?></span><?php endif; ?></a>
          <span> | </span>
          <a href="logout.php">Log Out</a>
        </div>
      </div>
    </div>

    <div class="billto">
      <strong>Bill To:</strong><br>
      <?= htmlspecialchars($invoice['organization_name']) ?>
    </div>

    <div class="summary">
      <div class="card">
        <div>Total Due</div>
        <strong>$<?= number_format((float)$totalDue, 2) ?></strong>
      </div>
      <?php foreach ($summaryCounts as $label => $count): ?>
        <div class="card">
          <div><?= htmlspecialchars($label) ?></div>
          <strong><?= (int)$count ?></strong>
        </div>
      <?php endforeach; ?>
    </div>

    <h3>Line Items</h3>
    <?php if (!empty($lineItems)): ?>
      <table>
        <thead>
          <tr>
            <th>Description</th>
            <th>Qty</th>
            <th>Rate</th>
            <th>Amount</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($lineItems as $item): ?>
            <tr>
              <td><?= htmlspecialchars($item['label']) ?></td>
              <td><?= (int)$item['qty'] ?></td>
              <td>$<?= number_format((float)$item['rate'], 2) ?></td>
              <td>$<?= number_format((float)$item['amount'], 2) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?>
      <p>No line items found for this period.</p>
    <?php endif; ?>

    <h3>Usage Details</h3>
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
              <td><?= htmlspecialchars($eventLabel) ?></td>
              <td><?= isset($row['unit_price']) ? ('$' . number_format((float)$row['unit_price'], 2)) : '—' ?></td>
              <td><?= isset($row['total_price']) ? ('$' . number_format((float)$row['total_price'], 2)) : '—' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?>
      <p>No usage events found for this period.</p>
    <?php endif; ?>

    <div class="totals">
      <div><strong>Total Due: $<?= number_format((float)$totalDue, 2) ?></strong></div>
    </div>

    <div class="actions">
      <button onclick="window.print()">Print Invoice</button>
    </div>
  </div>
</body>
</html>
