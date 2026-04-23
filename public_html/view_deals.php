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

$theme = dealerfai_get_theme_palette(null, ['color' => '#0066cc']);

if ($org_id) {
  $stmt = $db->prepare("SELECT logo_url, theme_variant FROM organizations WHERE id = ?");
  $stmt->execute([$org_id]);
  $org = $stmt->fetch(PDO::FETCH_ASSOC);
  if ($org) {
    $theme = dealerfai_get_theme_palette($org['theme_variant'] ?? null, ['logo' => $org['logo_url'] ?? '']);
  }
}

$orgFilterId = isset($_GET['org']) && $_GET['org'] !== '' ? (int)$_GET['org'] : null;
$searchQuery = trim((string)($_GET['q'] ?? ''));
$monthFilter = trim((string)($_GET['month'] ?? ''));
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
  $sql .= " ORDER BY {$orderBy} DESC LIMIT " . (int)$maxRowsPerSection;
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
  $year = trim((string)($deal['vehicle_year'] ?? ''));
  $make = trim((string)($deal['vehicle_make_name'] ?? ''));
  $model = trim((string)($deal['vehicle_model_name'] ?? ''));
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
        <td>$<?= number_format((float)$deal['sale_price'], 2) ?></td>
        <td><?= htmlspecialchars(format_deal_date($deal[$dateField] ?? null)) ?></td>
        <td><a class="btn-link" href="view_deal?id=<?= (int)$deal['id'] ?>">Details</a></td>
        <?php if ($showActions): ?>
          <td>
            <div class="actions-cell">
              <button type="button" class="btn-link btn-delivered btn-delivered-trigger" data-deal-id="<?= (int)$deal['id'] ?>">Delivered</button>
              <form action="update_deal_status" method="POST" class="inline-form" onsubmit="return confirm('Cancel this deal?');">
                <input type="hidden" name="deal_id" value="<?= (int)$deal['id'] ?>">
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
    /* Specific page overrides */
    table th { background: var(--brand-color); }
    .btn-delivered { background-color: #10b981; }
    .btn-cancel { background-color: #ef4444; }
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
      <a href="view_deals" class="sidebar-link active">
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
      <?php endif; ?>

      <?php if (in_array('General Manager', $roles) || $isAdmin): ?>
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
        DealerFAI <i class="fa-solid fa-chevron-right" style="font-size: 0.75rem; margin: 0 0.5rem; opacity: 0.5;"></i> Deals
      </div>
      <div style="display: flex; align-items: center; gap: 1.5rem;">
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
          <h1 class="text-gradient" style="margin-bottom: 0.5rem; display: inline-block;">Deals Inventory</h1>
          <p class="text-muted">Manage and track your customer deals across the platform.</p>
        </div>
        <a href="create_deal" class="btn btn-primary"><i class="fa-solid fa-plus" style="margin-right: 0.5rem;"></i> New Deal</a>
      </div>

      <?php if ($contextOrgName): ?>
        <p class="context-note">Now showing <?= htmlspecialchars($contextOrgName) ?> deals.</p>
      <?php endif; ?>

      <div class="glass" style="padding: 1.5rem; margin-bottom: 2.5rem; border: 1px solid var(--border-color);">
        <form class="filters" method="get" action="view_deals" style="display: flex; gap: 1rem; align-items: center; justify-content: flex-start; margin: 0;">
          <?php if ($isAdmin && !empty($organizationOptions)): ?>
            <select name="org" class="form-control" style="min-width: 200px; margin: 0;">
              <option value="">All Stores</option>
              <?php foreach ($organizationOptions as $orgOption): ?>
                <option value="<?= (int)$orgOption['id'] ?>" <?= ($orgFilterId === (int)$orgOption['id']) ? 'selected' : '' ?>>
                  <?= htmlspecialchars($orgOption['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          <?php endif; ?>
          <div style="flex: 1; position: relative;">
            <i class="fa-solid fa-search" style="position: absolute; left: 1rem; top: 50%; transform: translateY(-50%); color: #94a3b8;"></i>
            <input type="text" name="q" class="form-control" placeholder="Search deal #, phone, email, or customer" value="<?= htmlspecialchars($searchQuery) ?>" style="padding-left: 2.5rem; margin: 0;">
          </div>
          <input type="month" name="month" class="form-control" value="<?= htmlspecialchars($monthFilter) ?>" style="max-width: 200px; margin: 0;">
          <button type="submit" class="btn">Filter</button>
          <a href="view_deals" class="btn btn-secondary">Clear</a>
        </form>
      </div>

      <div class="data-table-card">
        <div class="data-table-header">
          <h3 style="margin: 0;">Active Deals</h3>
        </div>
        <div style="overflow-x: auto;">
          <?php render_deal_table($activeDeals, $showOrgColumn, 'Created Date', 'created_at', false, $_SESSION['csrf_token']); ?>
        </div>
      </div>

      <div class="data-table-card" style="margin-top: 2rem;">
        <details style="box-shadow: none; border-radius: 0; padding: 0;">
          <summary style="padding: 1.5rem; border-bottom: 1px solid #e2e8f0; list-style: none; display: flex; align-items: center; gap: 0.5rem;">
            <i class="fa-solid fa-chevron-down" style="font-size: 0.75rem;"></i>
            <h3 style="margin: 0; display: inline-block;">Booked Deals (<?= count($bookedDeals) ?>) · <?= htmlspecialchars($monthLabel) ?></h3>
          </summary>
          <div style="overflow-x: auto;">
            <?php render_deal_table($bookedDeals, $showOrgColumn, 'Delivered Date', 'delivered_date', false, $_SESSION['csrf_token']); ?>
          </div>
        </details>
      </div>

      <div class="data-table-card" style="margin-top: 2rem;">
        <details style="box-shadow: none; border-radius: 0; padding: 0;">
          <summary style="padding: 1.5rem; border-bottom: 1px solid #e2e8f0; list-style: none; display: flex; align-items: center; gap: 0.5rem;">
            <i class="fa-solid fa-chevron-down" style="font-size: 0.75rem;"></i>
            <h3 style="margin: 0; display: inline-block;">Cancelled Deals (<?= count($cancelledDeals) ?>) · <?= htmlspecialchars($monthLabel) ?></h3>
          </summary>
          <div style="overflow-x: auto;">
            <?php render_deal_table($cancelledDeals, $showOrgColumn, 'Cancelled Date', 'cancelled_date', false, $_SESSION['csrf_token']); ?>
          </div>
        </details>
      </div>
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
