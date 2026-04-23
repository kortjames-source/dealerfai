<?php
include 'auth.php';
require_once __DIR__ . '/includes/theme_head.php';
include 'db.php';
require_once __DIR__ . '/helpers/session_utils.php';
require_once __DIR__ . '/helpers/theme.php';

if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Fetch organization theme and logo
$orgContextId = get_admin_organization_context();
$org_id = get_effective_organization();
$user_id = $_SESSION['user_id'] ?? null;
$roles = load_session_roles();
$isAdmin = in_array('Admin', $roles, true);
$adminAlertCount = $isAdmin ? get_admin_alert_count($db) : 0;

$theme = dealerfai_get_theme_palette(null, ['color' => '#0a2e36']);

if ($org_id) {
  $stmt = $db->prepare("SELECT logo_url, theme_variant FROM organizations WHERE id = ?");
  $stmt->execute([$org_id]);
  $org = $stmt->fetch(PDO::FETCH_ASSOC);
  if ($org) {
    $theme = dealerfai_get_theme_palette($org['theme_variant'] ?? null, ['logo' => $org['logo_url'] ?? '']);
  }
}

$orgFilterId = isset($_GET['org']) && $_GET['org'] !== '' ? (int) $_GET['org'] : null;
$searchQuery = trim((string) ($_GET['q'] ?? ''));
$monthFilter = trim((string) ($_GET['month'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}$/', $monthFilter)) {
  $monthFilter = date('Y-m');
}
$monthLabel = date('F Y', strtotime($monthFilter . '-01'));

$organizationOptions = [];
if ($isAdmin) {
  $orgListStmt = $db->query("SELECT id, name FROM organizations ORDER BY name ASC");
  $organizationOptions = $orgListStmt->fetchAll(PDO::FETCH_ASSOC);
}

$contextOrgName = '';
if ($orgFilterId) {
  $nameStmt = $db->prepare("SELECT name FROM organizations WHERE id = ?");
  $nameStmt->execute([$orgFilterId]);
  $contextOrgName = $nameStmt->fetchColumn() ?: '';
} elseif ($orgContextId) {
  $nameStmt = $db->prepare("SELECT name FROM organizations WHERE id = ?");
  $nameStmt->execute([$orgContextId]);
  $contextOrgName = $nameStmt->fetchColumn() ?: '';
}

// Fetch deals (scoped + server-side status/date filtering to avoid loading everything into PHP memory).
$baseWhere = [];
$baseParams = [];
$accessible_orgs = get_accessible_organizations();
$target_orgs = [];
if ($isAdmin) {
  if ($orgFilterId) {
    $baseWhere[] = "(deals.organization = ? OR organizations.id = ?)";
    $baseParams[] = $orgFilterId;
    $baseParams[] = $orgFilterId;
  }
} else {
  $target_orgs = $orgContextId ? [$orgContextId] : (!empty($accessible_orgs) ? $accessible_orgs : ($org_id ? [$org_id] : []));
  if (empty($target_orgs)) {
    $target_orgs = [$org_id ?: 0];
  }
  $inClause = implode(',', array_fill(0, count($target_orgs), '?'));
  $baseWhere[] = "deals.organization IN ($inClause)";
  $baseParams = array_merge($baseParams, $target_orgs);
}

if ($searchQuery !== '') {
  $baseWhere[] = "(deals.deal_number LIKE ? OR deals.customer_phone LIKE ? OR deals.customer_email LIKE ? OR deals.customer_name LIKE ?)";
  $like = '%' . $searchQuery . '%';
  $baseParams = array_merge($baseParams, [$like, $like, $like, $like]);
}
if (!$isAdmin && !in_array('General Manager', $roles, true) && !in_array('Finance Manager', $roles, true)) {
  $baseWhere[] = "deals.salesperson_id = ?";
  $baseParams[] = $user_id;
}

$showOrgColumn = $isAdmin ? true : (count(array_unique($target_orgs)) > 1);
$monthStart = $monthFilter . '-01';
$monthEnd = date('Y-m-d', strtotime($monthStart . ' +1 month'));
$maxRowsPerSection = 500;
$selectCols = "deals.id,
  deals.organization,
  deals.deal_number,
  deals.customer_name,
  deals.deal_type,
  deals.sale_price,
  deals.created_at,
  deals.delivered_date,
  deals.cancelled_date,
  deals.deal_status,
  deals.salesperson_id,
  deals.vehicle_year,
  COALESCE(vehicle_makes.make_name, deals.vehicle_make, '') AS vehicle_make_name,
  COALESCE(vehicle_models.model_name, deals.vehicle_model, '') AS vehicle_model_name,
  COALESCE(organizations.name, deals.organization) AS organization_name";

$runDealQuery = static function (array $extraWhere, array $extraParams, string $orderBy) use ($db, $selectCols, $baseWhere, $baseParams, $maxRowsPerSection): array {
  $where = array_merge($baseWhere, $extraWhere);
  $params = array_merge($baseParams, $extraParams);
  $sql = "SELECT {$selectCols}
    FROM deals
    LEFT JOIN organizations ON organizations.id = deals.organization
    LEFT JOIN vehicle_makes ON vehicle_makes.id = deals.vehicle_make_id
    LEFT JOIN vehicle_models ON vehicle_models.id = deals.vehicle_model_id";
  if (!empty($where)) {
    $sql .= " WHERE " . implode(' AND ', $where);
  }
  $sql .= " ORDER BY {$orderBy} DESC LIMIT " . (int) $maxRowsPerSection;
  $stmt = $db->prepare($sql);
  $stmt->execute($params);
  return $stmt->fetchAll(PDO::FETCH_ASSOC);
};

$activeDeals = $runDealQuery(
  ["(deals.deal_status IS NULL OR LOWER(TRIM(deals.deal_status)) NOT IN ('booked', 'cancelled'))"],
  [],
  'deals.created_at'
);
$bookedDeals = $runDealQuery(
  ["LOWER(TRIM(deals.deal_status)) = 'booked'", "deals.delivered_date >= ?", "deals.delivered_date < ?"],
  [$monthStart, $monthEnd],
  'deals.delivered_date'
);
$cancelledDeals = $runDealQuery(
  ["LOWER(TRIM(deals.deal_status)) = 'cancelled'", "deals.cancelled_date >= ?", "deals.cancelled_date < ?"],
  [$monthStart, $monthEnd],
  'deals.cancelled_date'
);

function format_deal_date(?string $dateValue): string
{
  if (!$dateValue || $dateValue === '0000-00-00') {
    return '—';
  }
  $timestamp = strtotime($dateValue);
  if (!$timestamp) {
    return '—';
  }
  return date('Y-m-d', $timestamp);
}

function build_vehicle_display(array $deal): string
{
  $year = trim((string) ($deal['vehicle_year'] ?? ''));
  $make = trim((string) ($deal['vehicle_make_name'] ?? ''));
  $model = trim((string) ($deal['vehicle_model_name'] ?? ''));
  $label = trim($year . ' ' . $make . ' ' . $model);
  return preg_replace('/\s+/', ' ', $label) ?: '—';
}

function render_deal_table(array $deals, bool $showOrgColumn, string $dateLabel, string $dateField, bool $showActions, string $csrfToken): void
{
  if (empty($deals)) {
    echo '<p>No deals found.</p>';
    return;
  }
  ?>
  <table>
    <tr>
      <th>Deal #</th>
      <th>Customer Name</th>
      <?php if ($showOrgColumn): ?>
        <th>Organization</th>
      <?php endif; ?>
      <th>Deal Type</th>
      <th>Vehicle</th>
      <th>Sale Price</th>
      <th><?= htmlspecialchars($dateLabel) ?></th>
      <th>View</th>
      <?php if ($showActions): ?>
        <th>Actions</th>
      <?php endif; ?>
    </tr>
    <?php foreach ($deals as $deal): ?>
      <tr>
        <td><?= htmlspecialchars($deal['deal_number'] ?? '') ?></td>
        <td><?= htmlspecialchars($deal['customer_name'] ?? '') ?></td>
        <?php if ($showOrgColumn): ?>
          <td><?= htmlspecialchars($deal['organization_name'] ?? '') ?></td>
        <?php endif; ?>
        <td><?= htmlspecialchars($deal['deal_type'] ?? '') ?></td>
        <td><?= htmlspecialchars(build_vehicle_display($deal)) ?></td>
        <td>$<?= number_format((float) $deal['sale_price'], 2) ?></td>
        <td><?= htmlspecialchars(format_deal_date($deal[$dateField] ?? null)) ?></td>
        <td><a class="btn-link" href="view_deal?id=<?= (int) $deal['id'] ?>">Details</a></td>
        <?php if ($showActions): ?>
          <td>
            <div class="actions-cell">
              <button type="button" class="btn-link btn-delivered btn-delivered-trigger"
                data-deal-id="<?= (int) $deal['id'] ?>">Delivered</button>
              <form action="update_deal_status" method="POST" class="inline-form"
                onsubmit="return confirm('Cancel this deal?');">
                <input type="hidden" name="deal_id" value="<?= (int) $deal['id'] ?>">
                <input type="hidden" name="status" value="cancelled">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <button type="submit" class="btn-link btn-cancel">Cancel</button>
              </form>
            </div>
          </td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
  </table>
  <?php
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <?php include __DIR__ . "/includes/head_favicon.php"; ?>
  <meta charset="UTF-8">
  <title>View Deals - DealerFAI</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php dealerfai_theme_head($theme ?? null); ?>
  <style nonce="<?= dealerfai_csp_nonce() ?>">
    body {
      margin: 0;
      font-family: "Segoe UI", sans-serif;
      background-color:
        <?= htmlspecialchars($theme['page_background']) ?>
      ;
      color: #111111;
    }

    header {
      background-color:
        <?= htmlspecialchars($theme['header_background']) ?>
      ;
      color:
        <?= htmlspecialchars($theme['header_text']) ?>
      ;
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
      background-color:
        <?= htmlspecialchars($theme['nav_background']) ?>
      ;
      padding: 12px;
      text-align: center;
    }

    nav a {
      color:
        <?= htmlspecialchars($theme['nav_text']) ?>
      ;
      margin: 0 20px;
      text-decoration: none;
      font-weight: bold;
    }

    nav a:hover {
      text-decoration: underline;
    }

    .context-note {
      text-align: center;
      color: #1f3d5e;
      font-weight: 600;
      margin-top: 10px;
    }

    .filters {
      display: flex;
      flex-wrap: wrap;
      gap: 12px;
      align-items: center;
      justify-content: center;
      margin: 10px 0 20px;
    }

    .filters input,
    .filters select {
      padding: 10px 12px;
      border-radius: 6px;
      border: 1px solid #c7d0d8;
      min-width: 200px;
    }

    .filters button,
    .filters a {
      padding: 10px 16px;
      border-radius: 6px;
      border: none;
      cursor: pointer;
      font-weight: 600;
      text-decoration: none;
    }

    .filters button {
      background-color:
        <?= htmlspecialchars($theme['color']) ?>
      ;
      color: white;
    }

    .filters a {
      background: #e5e9ee;
      color: #203040;
    }

    main {
      padding: 30px 40px;
    }

    h2 {
      color:
        <?= htmlspecialchars($theme['color']) ?>
      ;
      text-align: center;
      margin-bottom: 10px;
    }

    h3 {
      color:
        <?= htmlspecialchars($theme['color']) ?>
      ;
      margin-top: 30px;
    }

    table {
      width: 100%;
      border-collapse: collapse;
      background: white;
      margin-top: 20px;
      box-shadow: 0 1px 6px rgba(0, 0, 0, 0.1);
    }

    th,
    td {
      padding: 14px;
      border-bottom: 1px solid #ddd;
      text-align: center;
    }

    th {
      background-color:
        <?= htmlspecialchars($theme['color']) ?>
      ;
      color: white;
    }

    tr:hover {
      background-color: #f1f8fb;
    }

    .btn-link {
      background-color:
        <?= htmlspecialchars($theme['color']) ?>
      ;
      color: white;
      padding: 8px 12px;
      text-decoration: none;
      border-radius: 4px;
      font-weight: bold;
      border: none;
      cursor: pointer;
      display: inline-block;
    }

    .btn-link:hover {
      opacity: 0.9;
    }

    .btn-delivered {
      background-color: #1f6f7a;
    }

    .btn-cancel {
      background-color: #c82333;
    }

    .actions-cell {
      display: flex;
      flex-wrap: wrap;
      justify-content: center;
      gap: 8px;
    }

    .inline-form {
      margin: 0;
    }

    details {
      margin-top: 20px;
      background: white;
      border-radius: 8px;
      box-shadow: 0 1px 6px rgba(0, 0, 0, 0.08);
      padding: 10px 16px;
    }

    details>summary {
      cursor: pointer;
      font-weight: 700;
      color:
        <?= htmlspecialchars($theme['color']) ?>
      ;
      padding: 6px 0;
    }

    details[open] {
      padding-bottom: 20px;
    }

    dialog#deliver-dialog {
      border: none;
      border-radius: 12px;
      padding: 0;
      width: min(420px, 92vw);
      box-shadow: 0 18px 40px rgba(0, 0, 0, 0.2);
    }

    dialog#deliver-dialog::backdrop {
      background: rgba(0, 0, 0, 0.35);
    }

    .dialog-header {
      background:
        <?= htmlspecialchars($theme['color']) ?>
      ;
      color: white;
      padding: 16px 20px;
      font-weight: 700;
    }

    .dialog-body {
      padding: 18px 20px;
      display: grid;
      gap: 12px;
    }

    .dialog-body label {
      font-weight: 600;
      color: #203040;
    }

    .dialog-body input[type="date"] {
      padding: 10px;
      border-radius: 6px;
      border: 1px solid #c7d0d8;
      width: 100%;
    }

    .dialog-actions {
      padding: 14px 20px 20px;
      display: flex;
      justify-content: flex-end;
      gap: 10px;
      background: #f4f6f8;
    }

    footer {
      background-color:
        <?= htmlspecialchars($theme['color']) ?>
      ;
      color: white;
      text-align: center;
      padding: 16px;
      font-size: 14px;
      margin-top: 60px;
      position: relative;
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
  </style>
</head>

<body>
  <header>
    <?php if (!empty($theme['logo'])): ?>
      <img src="<?= htmlspecialchars($theme['logo']) ?>" alt="Dealer Logo"
        style="max-height:60px; margin:auto; display:block;">
    <?php else: ?>
      <h1>DealerFAI</h1>
    <?php endif; ?>
    <div class="logout">
      <?php if ($isAdmin): ?>
        <a href="admin_error_alerts">Alerts<?php if ($adminAlertCount > 0): ?> <span
              class="badge"><?= $adminAlertCount ?></span><?php endif; ?></a>
      <?php endif; ?>
      <a href="logout">Log Out</a>
    </div>
  </header>

  <nav>
    <a href="dashboard">Dashboard</a>
    <a href="view_deals">View Deals</a>
    <a href="create_deal">Create Deal</a>
    <?php if ($isAdmin): ?>
      <a href="admin_scoring_log">Scoring Log</a>
    <?php endif; ?>
    <?php if (in_array('General Manager', $roles, true) || $isAdmin): ?>
      <a href="admin_tools">Admin Tools</a>
    <?php endif; ?>
  </nav>

  <main>
    <h2>Your Deals</h2>
    <?php if ($contextOrgName): ?>
      <p class="context-note">Now showing <?= htmlspecialchars($contextOrgName) ?> deals.</p>
    <?php endif; ?>

    <form class="filters" method="get" action="view_deals">
      <?php if ($isAdmin && !empty($organizationOptions)): ?>
        <select name="org">
          <option value="">All Stores</option>
          <?php foreach ($organizationOptions as $orgOption): ?>
            <option value="<?= (int) $orgOption['id'] ?>" <?= ($orgFilterId === (int) $orgOption['id']) ? 'selected' : '' ?>>
              <?= htmlspecialchars($orgOption['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      <?php endif; ?>
      <input type="text" name="q" placeholder="Search deal #, phone, email, or customer"
        value="<?= htmlspecialchars($searchQuery) ?>">
      <input type="month" name="month" value="<?= htmlspecialchars($monthFilter) ?>">
      <button type="submit">Search</button>
      <a href="view_deals">Clear</a>
    </form>

    <h3>Active Deals</h3>
    <?php render_deal_table($activeDeals, $showOrgColumn, 'Created Date', 'created_at', false, $_SESSION['csrf_token']); ?>

    <details>
      <summary>Booked Deals (<?= count($bookedDeals) ?>) · <?= htmlspecialchars($monthLabel) ?></summary>
      <?php render_deal_table($bookedDeals, $showOrgColumn, 'Delivered Date', 'delivered_date', false, $_SESSION['csrf_token']); ?>
    </details>

    <details>
      <summary>Cancelled Deals (<?= count($cancelledDeals) ?>) · <?= htmlspecialchars($monthLabel) ?></summary>
      <?php render_deal_table($cancelledDeals, $showOrgColumn, 'Cancelled Date', 'cancelled_date', false, $_SESSION['csrf_token']); ?>
    </details>
  </main>

  <dialog id="deliver-dialog">
    <div class="dialog-header">Mark Deal as Delivered</div>
    <form action="update_deal_status" method="POST" id="deliver-form">
      <div class="dialog-body">
        <input type="hidden" name="deal_id" id="deliver-deal-id" value="">
        <input type="hidden" name="status" value="booked">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
        <label for="deliver-date">Delivery Date</label>
        <input type="date" name="delivered_date" id="deliver-date" required>
      </div>
      <div class="dialog-actions">
        <button type="button" class="btn-link" id="deliver-cancel">Close</button>
        <button type="submit" class="btn-link btn-delivered">Confirm Delivered</button>
      </div>
    </form>
  </dialog>

  <footer>
    &copy; <?= date("Y") ?> DealerFAI. All rights reserved.
  </footer>

  <script nonce="<?= dealerfai_csp_nonce() ?>">
    const deliverDialog = document.getElementById('deliver-dialog');
    const deliverDealId = document.getElementById('deliver-deal-id');
    const deliverDate = document.getElementById('deliver-date');
    const deliverCancel = document.getElementById('deliver-cancel');

    document.querySelectorAll('.btn-delivered-trigger').forEach(button => {
      button.addEventListener('click', () => {
        const dealId = button.getAttribute('data-deal-id');
        if (!dealId) {
          return;
        }
        deliverDealId.value = dealId;
        const today = new Date();
        deliverDate.value = today.toISOString().slice(0, 10);
        if (typeof deliverDialog.showModal === 'function') {
          deliverDialog.showModal();
        } else {
          deliverDialog.setAttribute('open', 'open');
        }
      });
    });

    deliverCancel.addEventListener('click', () => {
      if (typeof deliverDialog.close === 'function') {
        deliverDialog.close();
      } else {
        deliverDialog.removeAttribute('open');
      }
    });
  </script>
</body>

</html>