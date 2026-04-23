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
$adminAlertCount = get_admin_alert_count($db);

function parse_reason_override_text(?string $raw): string
{
    if ($raw === null || $raw === '') {
        return '';
    }
    $decoded = json_decode($raw, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        if (is_string($decoded)) {
            return trim($decoded);
        }
        if (is_array($decoded)) {
            if (isset($decoded['text']) && is_string($decoded['text'])) {
                return trim($decoded['text']);
            }
            if (isset($decoded['reason']) && is_string($decoded['reason'])) {
                return trim($decoded['reason']);
            }
        }
    }
    return trim($raw);
}

function encode_reason_override_text(string $text): ?string
{
    $trimmed = trim($text);
    if ($trimmed === '') {
        return null;
    }
    return json_encode(['text' => $trimmed], JSON_UNESCAPED_UNICODE);
}

$accessibleOrgs = get_accessible_organizations();
if (empty($accessibleOrgs)) {
    $orgRows = $db->query("SELECT id FROM organizations")->fetchAll(PDO::FETCH_COLUMN);
    $accessibleOrgs = array_map('intval', $orgRows);
}

$orgContextId = get_admin_organization_context();
$selectedOrgParamRaw = $_GET['org'] ?? null;
$isGlobalView = false;
if ($selectedOrgParamRaw === 'global') {
    $selectedOrg = 0;
    $isGlobalView = true;
} else {
    $selectedOrgParam = isset($_GET['org']) ? (int)$_GET['org'] : 0;
    if ($orgContextId) {
        $selectedOrg = $orgContextId;
    } elseif ($selectedOrgParam && in_array($selectedOrgParam, $accessibleOrgs, true)) {
        $selectedOrg = $selectedOrgParam;
    } else {
        $selectedOrg = $accessibleOrgs[0] ?? 0;
    }
}

$orgOptions = [];
if (!empty($accessibleOrgs)) {
    $placeholders = implode(',', array_fill(0, count($accessibleOrgs), '?'));
    $stmt = $db->prepare("SELECT id, name FROM organizations WHERE id IN ($placeholders) ORDER BY name ASC");
    $stmt->execute($accessibleOrgs);
    $orgOptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_overrides') {
    if (!dealerfai_csrf_validate($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        die('Invalid request.');
    }

    $selectedOrg = (int)($_POST['org_id'] ?? $selectedOrg);
    $customNames = $_POST['custom_name'] ?? [];
    $customDescriptions = $_POST['custom_description'] ?? [];
    $reasonOverrides = $_POST['reason_override'] ?? [];

    $productIds = array_unique(array_filter(array_merge(
        array_keys($customNames),
        array_keys($customDescriptions),
        array_keys($reasonOverrides)
    ), static function ($value): bool {
        return $value !== '';
    }));

    $selectStmt = $db->prepare("SELECT id FROM product_organization_overrides WHERE product_id = ? AND organization_id = ?");
    $updateStmt = $db->prepare("UPDATE product_organization_overrides SET custom_name = ?, custom_description = ?, reason_override = ? WHERE id = ?");
    $insertStmt = $db->prepare("INSERT INTO product_organization_overrides (product_id, organization_id, custom_name, custom_description, reason_override) VALUES (?, ?, ?, ?, ?)");
    $deleteStmt = $db->prepare("DELETE FROM product_organization_overrides WHERE id = ?");

    foreach ($productIds as $productIdRaw) {
        $productId = (int)$productIdRaw;
        if ($productId <= 0) {
            continue;
        }
        $customName = trim((string)($customNames[$productIdRaw] ?? ''));
        $customDescription = trim((string)($customDescriptions[$productIdRaw] ?? ''));
        $reasonOverrideText = trim((string)($reasonOverrides[$productIdRaw] ?? ''));
        $encodedReason = encode_reason_override_text($reasonOverrideText);

        $selectStmt->execute([$productId, $selectedOrg]);
        $existingId = $selectStmt->fetchColumn();

        if ($customName === '' && $customDescription === '' && $encodedReason === null) {
            if ($existingId) {
                $deleteStmt->execute([$existingId]);
            }
            continue;
        }

        if ($existingId) {
            $updateStmt->execute([$customName !== '' ? $customName : null, $customDescription !== '' ? $customDescription : null, $encodedReason, $existingId]);
        } else {
            $insertStmt->execute([$productId, $selectedOrg, $customName !== '' ? $customName : null, $customDescription !== '' ? $customDescription : null, $encodedReason]);
        }
    }

    $message = 'Approved facts updated.';
}
$csrfToken = dealerfai_csrf_get_token();

$products = [];
if ($selectedOrg || $isGlobalView) {
    if ($isGlobalView) {
        $stmt = $db->prepare("
            SELECT p.id,
                   p.code,
                   p.name,
                   p.provider,
                   p.default_description,
                   p.default_reason,
                   o.custom_name, o.custom_description, o.reason_override
            FROM products p
            LEFT JOIN product_organization_overrides o
              ON o.product_id = p.id AND o.organization_id = 0
            WHERE p.is_active = 1
            ORDER BY p.name ASC, p.provider ASC
        ");
        $stmt->execute();
    } else {
        $stmt = $db->prepare("
            SELECT p.id,
                   p.code,
                   p.name,
                   p.provider,
                   p.default_description,
                   p.default_reason,
                   o.custom_name, o.custom_description, o.reason_override,
                   g.custom_name AS global_custom_name,
                   g.custom_description AS global_custom_description,
                   g.reason_override AS global_reason_override
            FROM products p
            LEFT JOIN product_organization_overrides o
              ON o.product_id = p.id AND o.organization_id = ?
            LEFT JOIN product_organization_overrides g
              ON g.product_id = p.id AND g.organization_id = 0
            WHERE p.is_active = 1
            ORDER BY p.name ASC, p.provider ASC
        ");
        $stmt->execute([$selectedOrg]);
    }
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html>
<head>
  <?php include __DIR__ . "/includes/head_favicon.php"; ?>
<meta charset="UTF-8">
  <title>Admin - Product Approved Facts</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php dealerfai_theme_head(); ?>
  <style nonce="<?= dealerfai_csp_nonce() ?>">
    body { font-family: Arial, sans-serif; background: #f4f6f8; margin: 0; padding: 0; }
    header { background: #0a2e36; color: white; padding: 20px; text-align: center; }
    nav { background: #0a2e36; padding: 12px; text-align: center; }
    nav a { color: white; margin: 0 20px; text-decoration: none; font-weight: bold; }
    .container { max-width: 1100px; margin: 20px auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
    h2 { margin-top: 0; }
    .success { color: green; margin-top: 10px; }
    .org-picker { display:flex; gap:12px; align-items:center; margin-bottom:20px; }
    .org-picker select { padding: 8px; border-radius: 4px; border: 1px solid #ccc; }
    .org-picker button { padding: 8px 14px; border: none; border-radius: 4px; background: #0a6280; color: white; cursor: pointer; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 10px; border-bottom: 1px solid #ddd; vertical-align: top; text-align: left; }
    th { background: #f1f4f8; }
    textarea, input[type="text"] { width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-family: inherit; }
    textarea { min-height: 70px; }
    .muted { color: #667085; font-size: 0.9rem; margin-top: 4px; }
    .btn { background: #0a6280; color: white; padding: 10px 18px; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; }
    .btn:hover { background: #094c63; }
  </style>
</head>
<body>
  <header class="pos-relative">
    <h1>DealerFAI Admin</h1>
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

  <div class="container">
    <h2>Approved Product Facts</h2>
    <p class="muted">Override the default product name and approved facts used by AI. Overrides are saved per product variant (code + provider). Global overrides apply unless a store-level override exists.</p>
    <p class="muted">Override AI reasoning with a set message. Leave blank to use the default reasoning.</p>

    <form method="get" class="org-picker">
      <label for="org">Organization</label>
      <select id="org" name="org">
        <option value="global" <?= $isGlobalView ? 'selected' : '' ?>>Global</option>
        <?php foreach ($orgOptions as $org): ?>
          <option value="<?= (int)$org['id'] ?>" <?= ((int)$org['id'] === (int)$selectedOrg) ? 'selected' : '' ?>>
            <?= htmlspecialchars($org['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <button type="submit">Load</button>
    </form>

    <?php if ($message): ?>
      <p class="success">✅ <?= htmlspecialchars($message) ?></p>
    <?php endif; ?>

    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="action" value="save_overrides">
      <input type="hidden" name="org_id" value="<?= (int)$selectedOrg ?>">
      <table id="approvedFactsTable">
        <thead>
          <tr>
            <th class="w-18-pointer" data-sort="product">Product</th>
            <th class="w-10-pointer" data-sort="code">Code</th>
            <th class="w-10-pointer" data-sort="provider">Provider</th>
            <th class="w-20">Default Approved Facts</th>
            <th class="w-20">Custom Approved Facts</th>
            <th class="w-16">Custom Reason</th>
            <th class="w-6">Custom Name</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($products as $product): ?>
            <?php
              $reasonText = parse_reason_override_text($product['reason_override'] ?? null);
              $globalReasonText = parse_reason_override_text($product['global_reason_override'] ?? null);
              $defaultFacts = trim((string)($product['default_description'] ?? $product['default_reason'] ?? ''));
              if (!$isGlobalView) {
                $defaultFacts = trim((string)($product['global_custom_description'] ?? ''));
                if ($defaultFacts === '') {
                  $defaultFacts = $globalReasonText !== ''
                    ? $globalReasonText
                    : trim((string)($product['default_description'] ?? $product['default_reason'] ?? ''));
                }
              }
              $providerLabel = trim((string)($product['provider'] ?? ''));
            ?>
            <tr>
              <td>
                <strong><?= htmlspecialchars($product['name']) ?></strong><br>
                <span class="muted">Variant ID: <?= (int)$product['id'] ?></span>
              </td>
              <td><?= htmlspecialchars($product['code']) ?></td>
              <td><?= htmlspecialchars($providerLabel !== '' ? $providerLabel : '—') ?></td>
              <td>
                <textarea readonly><?= htmlspecialchars($defaultFacts) ?></textarea>
                <div class="muted"><?= $isGlobalView ? 'Shown when no global facts are set.' : 'Shown when no store override is set.' ?></div>
              </td>
              <td>
                <textarea name="custom_description[<?= (int)$product['id'] ?>]"><?= htmlspecialchars($product['custom_description'] ?? '') ?></textarea>
                <div class="muted">Approved facts for AI copy.</div>
              </td>
              <td>
                <textarea name="reason_override[<?= (int)$product['id'] ?>]"><?= htmlspecialchars($reasonText) ?></textarea>
                <div class="muted">Override AI reasoning with a set message. Leave blank to use the default reasoning.</div>
              </td>
              <td>
                <input type="text" name="custom_name[<?= (int)$product['id'] ?>]" value="<?= htmlspecialchars($product['custom_name'] ?? '') ?>">
                <?php if (!$isGlobalView && !empty($product['global_custom_name'])): ?>
                  <div class="muted">Global name: <?= htmlspecialchars($product['global_custom_name']) ?></div>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <button type="submit" class="btn" class="mt-20">Save Approved Facts</button>
    </form>
  </div>
  <script nonce="<?= dealerfai_csp_nonce() ?>">
    const approvedTable = document.getElementById('approvedFactsTable');
    const approvedRows = Array.from(approvedTable.querySelectorAll('tbody tr'));
    const approvedHeaders = approvedTable.querySelectorAll('thead th[data-sort]');
    const approvedSortState = { column: null, direction: 1 };

    approvedHeaders.forEach((th) => {
      th.addEventListener('click', () => {
        const key = th.getAttribute('data-sort');
        const columnIndex = key === 'product' ? 0 : (key === 'code' ? 1 : 2);
        if (approvedSortState.column === key) {
          approvedSortState.direction *= -1;
        } else {
          approvedSortState.column = key;
          approvedSortState.direction = 1;
        }
        const sorted = approvedRows.slice().sort((a, b) => {
          const aText = (a.children[columnIndex]?.innerText || '').trim().toLowerCase();
          const bText = (b.children[columnIndex]?.innerText || '').trim().toLowerCase();
          if (aText < bText) return -1 * approvedSortState.direction;
          if (aText > bText) return 1 * approvedSortState.direction;
          return 0;
        });
        const tbody = approvedTable.querySelector('tbody');
        sorted.forEach(row => tbody.appendChild(row));
      });
    });
  </script>
</body>
</html>
