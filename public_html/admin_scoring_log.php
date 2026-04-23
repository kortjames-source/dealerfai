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
    .filter-bar {
      display:flex;
      flex-wrap:wrap;
      gap:12px;
      justify-content:center;
      margin-bottom:20px;
      align-items:center;
    }
    .filter-bar select,
    .filter-bar input {
      padding:8px 10px;
      border-radius:4px;
      border:1px solid #ccc;
    }
    .filter-bar button {
      padding:8px 14px;
      border:none;
      border-radius:4px;
      background:#0066cc;
      color:white;
      font-weight:bold;
      cursor:pointer;
    }
    .context-note {
      text-align:center;
      color:#1f3d5e;
      font-weight:600;
      margin-bottom:20px;
    }
    .deal-card {
      background:white;
      border-radius:10px;
      padding:18px;
      margin-bottom:24px;
      box-shadow:0 2px 12px rgba(0,0,0,0.1);
    }
    .deal-card h3 {
      margin:0 0 4px;
      color:#0066cc;
    }
    .product-row {
      border-top:1px solid #e1e8f1;
      padding:12px 0;
    }
    .product-row:first-of-type {
      border-top:none;
    }
    .product-name {
      font-weight:600;
      margin:0;
    }
    .product-meta {
      font-size:0.9rem;
      color:#555;
      margin:2px 0;
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
    <div style="position:absolute; top:24px; right:32px; display:flex; gap:12px; align-items:center;">
      <a href="admin_error_alerts.php" style="color:#fff; text-decoration:none;">Alerts<?php if ($adminAlertCount > 0): ?> <span style="display:inline-block; min-width:18px; padding:2px 8px; border-radius:999px; background:#d7263d; color:#fff; font-size:12px; font-weight:bold; text-align:center; margin-left:6px;"><?= $adminAlertCount ?></span><?php endif; ?></a>
      <a href="logout.php" style="color:#fff; text-decoration:none;">Log Out</a>
    </div>
  </header>

  <nav>
    <a href="dashboard.php">Dashboard</a>
    <a href="view_deals.php">View Deals</a>
    <a href="create_deal.php">Create Deal</a>
    <a href="admin_tools.php">Admin Tools</a>
  </nav>

  <main>
    <h2>Recommendation Scoring Log</h2>
    <?php if ($orgContextId && ($orgNames[$orgContextId] ?? '')): ?>
      <p class="context-note">Currently working within <?= htmlspecialchars($orgNames[$orgContextId]) ?>.</p>
    <?php endif; ?>
    <form method="get" class="filter-bar">
      <label>
        Organization:
        <select name="org">
          <option value="">All</option>
          <?php foreach ($orgNames as $id => $label): ?>
            <option value="<?= $id ?>" <?= ($selectedOrg === $id) ? 'selected' : '' ?>>
              <?= htmlspecialchars($label) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>
        Deal ID:
        <input type="number" name="deal_id" value="<?= htmlspecialchars($_GET['deal_id'] ?? '') ?>" min="1" placeholder="123">
      </label>
      <button type="submit">Apply filters</button>
    </form>
    <?php if (empty($grouped)): ?>
      <p>No scoring records found for this filter.</p>
    <?php else: ?>
      <?php foreach ($ord as $dealId): ?>
        <?php $deal = $grouped[$dealId]; ?>
        <?php
          $displayScoredAt = '';
          if (!empty($deal['latest_scored_at'])) {
            try {
              $displayScoredAt = (new DateTime($deal['latest_scored_at']))->format('d-m-Y H:i');
            } catch (Exception $e) {
              $displayScoredAt = $deal['latest_scored_at'];
            }
          }
        ?>
        <details class="deal-card">
          <summary>
            <strong>Deal <?= htmlspecialchars($deal['deal_number']) ?></strong>
            <span class="product-meta" class="ml-10">
              <?= htmlspecialchars($deal['customer_name']) ?> · <?= htmlspecialchars($deal['organization_name']) ?>
              <?php if ($displayScoredAt !== ''): ?>
                · <?= htmlspecialchars($displayScoredAt) ?>
              <?php endif; ?>
            </span>
          </summary>
          <?php foreach ($deal['products'] as $product): ?>
            <div class="product-row">
              <p class="product-name">
                <?= htmlspecialchars($product['product_name']) ?> — Score <?= htmlspecialchars($product['match_score']) ?>
              </p>
              <?php if ($product['reason']): ?>
                <p class="product-meta"><?= htmlspecialchars($product['reason']) ?></p>
              <?php endif; ?>
              <?php if (!empty($product['scoring_details'])): ?>
                <p class="product-meta"><?= htmlspecialchars($product['scoring_details']) ?></p>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </details>
      <?php endforeach; ?>
    <?php endif; ?>
  </main>
</body>
</html>
