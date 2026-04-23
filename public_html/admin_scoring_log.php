<?php
include 'auth.php';
require_once __DIR__ . '/includes/theme_head.php';
include 'db.php';
require_once __DIR__ . '/helpers/session_utils.php';

$roles = load_session_roles();
$isAdmin = in_array('Admin', $roles, true);
if (!in_array('Admin', $roles, true)) {
  http_response_code(403);
  echo 'Unauthorized';
  exit;
}
$adminAlertCount = get_admin_alert_count($db);

$orgContextId = get_admin_organization_context();
$org_id = get_effective_organization();
$accessible_orgs = get_accessible_organizations();
$normalizeOrgIds = static function (array $orgs): array {
  $normalized = [];
  foreach ($orgs as $org) {
    if ($org === null || $org === '') {
      continue;
    }
    if (is_string($org) && ctype_digit($org)) {
      $org = (int)$org;
    }
    if (is_int($org)) {
      $normalized[] = $org;
    }
  }
  return array_values($normalized);
};
$accessible_orgs = $normalizeOrgIds($accessible_orgs);

$orgNames = [];
if ($isAdmin) {
  $orgStmt = $db->query("SELECT id, name FROM organizations ORDER BY name ASC");
  while ($row = $orgStmt->fetch(PDO::FETCH_ASSOC)) {
    $orgNames[(int)$row['id']] = $row['name'];
  }
  $accessible_orgs = array_keys($orgNames);
} elseif (!empty($accessible_orgs)) {
  $orgStmt = $db->prepare("SELECT id, name FROM organizations WHERE id IN (" . implode(',', array_fill(0, count($accessible_orgs), '?')) . ")");
  $orgStmt->execute($accessible_orgs);
  while ($row = $orgStmt->fetch(PDO::FETCH_ASSOC)) {
    $orgNames[(int)$row['id']] = $row['name'];
  }
} elseif ($org_id) {
  $orgStmt = $db->prepare("SELECT id, name FROM organizations WHERE id = ?");
  $orgStmt->execute([$org_id]);
  if ($row = $orgStmt->fetch(PDO::FETCH_ASSOC)) {
    $orgNames[(int)$row['id']] = $row['name'];
  }
}

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

$selectedOrgParam = isset($_GET['org']) ? (int)$_GET['org'] : null;
if ($orgContextId) {
  $selectedOrg = $orgContextId;
} elseif ($selectedOrgParam && ($isAdmin ? array_key_exists($selectedOrgParam, $orgNames) : in_array($selectedOrgParam, $accessible_orgs, true))) {
  $selectedOrg = $selectedOrgParam;
} else {
  $selectedOrg = null;
}

$target_orgs = $selectedOrg ? [$selectedOrg] : (!empty($accessible_orgs) ? $accessible_orgs : ($org_id ? [$org_id] : []));
$target_orgs = $normalizeOrgIds($target_orgs);
if (empty($target_orgs)) {
  $target_orgs = [$org_id ?: 0];
}

$dealFilter = isset($_GET['deal_id']) ? (int)$_GET['deal_id'] : null;

$params = [];
$whereClauses = [];

if (!empty($target_orgs)) {
  $placeholders = implode(',', array_fill(0, count($target_orgs), '?'));
  $whereClauses[] = "deals.organization IN ($placeholders)";
  $params = $target_orgs;
}

if ($dealFilter) {
  $whereClauses[] = "deals.id = ?";
  $params[] = $dealFilter;
}
$whereSql = !empty($whereClauses) ? implode(' AND ', $whereClauses) : '1=1';

$query = "
  SELECT deals.id AS deal_id,
         deals.deal_number,
         deals.customer_name,
         COALESCE(organizations.name, deals.organization) AS organization_name,
         recommendation_scoring_log.product_code,
         recommendation_scoring_log.score AS match_score,
         COALESCE(product_recommendations.product_name, recommendation_scoring_log.product_name, recommendation_scoring_log.product_code) AS product_name,
         COALESCE(product_recommendations.ai_explanation, product_recommendations.description, '') AS reason,
         recommendation_scoring_log.reasoning AS scoring_details,
         recommendation_scoring_log.created_at AS scored_at
  FROM recommendation_scoring_log
  JOIN deals ON deals.id = recommendation_scoring_log.deal_id
  LEFT JOIN organizations ON organizations.id = deals.organization
  LEFT JOIN product_recommendations ON product_recommendations.deal_id = deals.id AND product_recommendations.product_code = recommendation_scoring_log.product_code
  WHERE " . $whereSql . "
  ORDER BY deals.created_at DESC, recommendation_scoring_log.product_code ASC
";

$stmt = $db->prepare($query);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$grouped = [];
$ord = [];
foreach ($rows as $row) {
  $dealId = $row['deal_id'];
  if (!isset($grouped[$dealId])) {
    $grouped[$dealId] = [
      'deal_number' => $row['deal_number'],
      'customer_name' => $row['customer_name'],
      'organization_name' => $row['organization_name'],
      'products' => [],
      'latest_scored_at' => $row['scored_at'] ?? null
    ];
    $ord[] = $dealId;
  }
  if (!empty($row['scored_at'])) {
    $current = $grouped[$dealId]['latest_scored_at'];
    if ($current === null || $row['scored_at'] > $current) {
      $grouped[$dealId]['latest_scored_at'] = $row['scored_at'];
    }
  }
  $grouped[$dealId]['products'][] = [
    'product_name' => $row['product_name'],
    'match_score' => $row['match_score'],
    'reason' => $row['reason'],
    'scoring_details' => $row['scoring_details']
  ];
}

foreach ($grouped as &$deal) {
  usort($deal['products'], function (array $a, array $b): int {
    return (int)$b['match_score'] <=> (int)$a['match_score'];
  });
}
unset($deal);

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php include __DIR__ . "/includes/head_favicon.php"; ?>
<meta charset="UTF-8">
  <title>Admin Scoring Log</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php dealerfai_theme_head($theme ?? null); ?>
  <style nonce="<?= dealerfai_csp_nonce() ?>">
    /* Specific page overrides */
    .deal-card { background: white; border-radius: var(--radius-lg); padding: 0; margin-bottom: 1.5rem; border: 1px solid #e2e8f0; overflow: hidden; box-shadow: var(--shadow-sm); }
    .deal-card summary { padding: 1.25rem 1.5rem; cursor: pointer; display: flex; align-items: center; justify-content: space-between; background: #f8fafc; border-bottom: 1px solid #e2e8f0; }
    .deal-card summary::-webkit-details-marker { display: none; }
    .product-list { padding: 1rem 1.5rem; }
    .product-item { padding: 1rem 0; border-bottom: 1px solid #f1f5f9; }
    .product-item:last-child { border-bottom: none; }
    .score-badge { display: inline-block; padding: 0.25rem 0.6rem; border-radius: 999px; font-size: 0.75rem; font-weight: 700; background: #f1f5f9; color: #475569; }
    .score-high { background: #dcfce7; color: #166534; }
    .score-med { background: #fef9c3; color: #854d0e; }
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
        <a href="admin_scoring_log" class="sidebar-link active">
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
        DealerFAI <i class="fa-solid fa-chevron-right" style="font-size: 0.75rem; margin: 0 0.5rem; opacity: 0.5;"></i> Scoring Log
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
        <h1 class="text-gradient" style="margin-bottom: 0.5rem; display: inline-block;">Scoring Log</h1>
        <p class="text-muted">Audit the logic behind product recommendations and matching scores.</p>
      </div>

      <div class="glass" style="padding: 1.5rem; margin-bottom: 2rem;">
        <form method="get" style="display: flex; gap: 1rem; align-items: flex-end; flex-wrap: wrap;">
          <div style="display: flex; flex-direction: column; gap: 0.5rem;">
            <label style="font-size: 0.75rem; font-weight: 700; color: #64748b; text-transform: uppercase;">Organization</label>
            <select name="org" class="form-control" style="margin: 0; min-width: 220px;">
              <option value="">All Organizations</option>
              <?php foreach ($orgNames as $id => $label): ?>
                <option value="<?= $id ?>" <?= ($selectedOrg === $id) ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div style="display: flex; flex-direction: column; gap: 0.5rem;">
            <label style="font-size: 0.75rem; font-weight: 700; color: #64748b; text-transform: uppercase;">Deal ID</label>
            <input type="number" name="deal_id" class="form-control" style="margin: 0; max-width: 120px;" value="<?= htmlspecialchars($_GET['deal_id'] ?? '') ?>" placeholder="123">
          </div>
          <button type="submit" class="btn btn-primary">Apply Filters</button>
          <?php if ($selectedOrg || $dealFilter): ?>
            <a href="admin_scoring_log" class="btn btn-secondary">Clear</a>
          <?php endif; ?>
        </form>
      </div>

      <?php if (empty($grouped)): ?>
        <div style="text-align: center; padding: 4rem 2rem; background: white; border-radius: var(--radius-lg); border: 1px dashed #cbd5e1;">
          <i class="fa-solid fa-magnifying-glass" style="font-size: 3rem; color: #e2e8f0; margin-bottom: 1.5rem;"></i>
          <h3 style="color: #64748b;">No scoring records found</h3>
          <p class="text-muted">Try adjusting your filters to see more results.</p>
        </div>
      <?php else: ?>
        <?php foreach ($ord as $dealId): ?>
          <?php $deal = $grouped[$dealId]; ?>
          <details class="deal-card">
            <summary>
              <div style="display: flex; align-items: center; gap: 1rem;">
                <div style="background: #f1f5f9; width: 40px; height: 40px; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: #64748b; font-weight: 700;">#<?= htmlspecialchars($deal['deal_number']) ?></div>
                <div>
                  <div style="font-weight: 700; color: #0f172a;"><?= htmlspecialchars($deal['customer_name']) ?></div>
                  <div style="font-size: 0.75rem; color: #64748b;"><?= htmlspecialchars($deal['organization_name']) ?></div>
                </div>
              </div>
              <div style="text-align: right; font-size: 0.75rem; color: #94a3b8;">
                Scored: <?= $deal['latest_scored_at'] ? (new DateTime($deal['latest_scored_at']))->format('M j, Y g:ia') : 'N/A' ?>
                <i class="fa-solid fa-chevron-down" style="margin-left: 0.5rem; font-size: 0.7rem;"></i>
              </div>
            </summary>
            <div class="product-list">
              <?php foreach ($deal['products'] as $product): ?>
                <div class="product-item">
                  <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 0.5rem;">
                    <div style="font-weight: 600; color: #1e293b;"><?= htmlspecialchars($product['product_name']) ?></div>
                    <div class="score-badge <?= $product['match_score'] >= 80 ? 'score-high' : ($product['match_score'] >= 50 ? 'score-med' : '') ?>">
                      Score: <?= htmlspecialchars($product['match_score']) ?>
                    </div>
                  </div>
                  <?php if ($product['reason']): ?>
                    <div style="font-size: 0.875rem; color: #475569; margin-bottom: 0.25rem;"><?= htmlspecialchars($product['reason']) ?></div>
                  <?php endif; ?>
                  <?php if (!empty($product['scoring_details'])): ?>
                    <div style="font-family: monospace; font-size: 0.75rem; color: #64748b; background: #f8fafc; padding: 0.5rem; border-radius: 4px; border-left: 3px solid #e2e8f0; margin-top: 0.5rem;">
                      <?= htmlspecialchars($product['scoring_details']) ?>
                    </div>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          </details>
        <?php endforeach; ?>
      <?php endif; ?>
    </main>

    <footer style="background: white; border-top: 1px solid #e2e8f0; color: #64748b; padding: 1.5rem; text-align: center; position: static;">
      &copy; <?= date("Y") ?> DealerFAI. All rights reserved.
    </footer>
  </div>
</body>
</html>
</body>
</html>
