<?php
include 'auth.php';
require_once __DIR__ . '/includes/theme_head.php';
include 'db.php';
require_once __DIR__ . '/helpers/session_utils.php';
require_once __DIR__ . '/includes/csrf.php';

$roles = load_session_roles();
if (!in_array('Admin', $roles, true)) {
    http_response_code(403);
    echo 'Unauthorized';
    exit;
}
$adminAlertCount = get_admin_alert_count($db);
$csrfToken = dealerfai_csrf_get_token();

$message = '';
$error = '';

function build_variant_key(int $productId): string
{
    return (string)$productId;
}

$orgRows = $db->query("SELECT id, name, org_kind, parent_org_id, logic_type FROM organizations ORDER BY name ASC")
    ->fetchAll(PDO::FETCH_ASSOC);
$stores = [];
$groups = [];
foreach ($orgRows as $org) {
    if (($org['org_kind'] ?? 'store') === 'group') {
        $groups[] = $org;
    } else {
        $stores[] = $org;
    }
}

$selectedOrgId = (int)($_POST['org_id'] ?? ($_GET['org_id'] ?? 0));
if ($selectedOrgId === 0 && !empty($stores)) {
    $selectedOrgId = (int)$stores[0]['id'];
}

$selectedOrg = null;
foreach ($orgRows as $org) {
    if ((int)$org['id'] === $selectedOrgId) {
        $selectedOrg = $org;
        break;
    }
}
if (!$selectedOrg && !empty($stores)) {
    $selectedOrg = $stores[0];
    $selectedOrgId = (int)$selectedOrg['id'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_store_products') {
    if (!dealerfai_csrf_validate($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        $error = 'Invalid request token.';
    } else {
    $selectedOrgId = (int)($_POST['org_id'] ?? 0);
    $selectedOrgKind = $_POST['org_kind'] ?? '';
    $selected = $_POST['product_enabled'] ?? [];
    if ($selectedOrgId <= 0) {
        $error = 'Select a valid organization first.';
    } else {
        $variants = $db->query("SELECT id, code, provider FROM products WHERE is_active = 1 ORDER BY code ASC, provider ASC, name ASC")
            ->fetchAll(PDO::FETCH_ASSOC);
        $stmt = $db->prepare("
            INSERT INTO product_availability (product_id, product_code, provider, scope_type, scope_value, is_enabled)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE is_enabled = VALUES(is_enabled)
        ");
        $scopeType = $selectedOrgKind === 'group' ? 'org' : 'store';
        foreach ($variants as $variant) {
            $productId = (int)($variant['id'] ?? 0);
            $code = (string)($variant['code'] ?? '');
            $provider = trim((string)($variant['provider'] ?? ''));
            if ($code === '' || $productId <= 0) {
                continue;
            }
            $key = build_variant_key($productId);
            $isEnabled = array_key_exists($key, $selected) ? 1 : 0;
            $stmt->execute([$productId, $code, $provider, $scopeType, (string)$selectedOrgId, $isEnabled]);
        }
        $message = $selectedOrgKind === 'group' ? 'Group availability saved.' : 'Store availability saved.';
    }
    }
}

$selectedOrgKind = $selectedOrg['org_kind'] ?? 'store';
$storeId = null;
$groupId = null;
if ($selectedOrgKind === 'group') {
    $storeId = null;
    $groupId = null;
} else {
    $storeId = (int)$selectedOrgId;
    $groupId = $selectedOrg['parent_org_id'] ?? null;
}
$logicType = $selectedOrg['logic_type'] ?? '';

$products = [];
if ($selectedOrgId > 0) {
    $stmt = $db->prepare("
        SELECT p.code,
               p.id,
               p.name,
               p.provider,
               COALESCE(
                   av_store_variant.is_enabled, av_store_provider.is_enabled, av_store_any.is_enabled,
                   av_org_variant.is_enabled, av_org_provider.is_enabled, av_org_any.is_enabled,
                   av_group_variant.is_enabled, av_group_provider.is_enabled, av_group_any.is_enabled,
                   av_vertical_variant.is_enabled, av_vertical_provider.is_enabled, av_vertical_any.is_enabled,
                   av_global_variant.is_enabled, av_global_provider.is_enabled, av_global_any.is_enabled,
                   1
               ) AS availability_enabled,
               av_store_variant.is_enabled AS store_override_variant,
               av_store_provider.is_enabled AS store_override_provider,
               av_store_any.is_enabled AS store_override_any,
               av_org_variant.is_enabled AS org_override_variant,
               av_org_provider.is_enabled AS org_override_provider,
               av_org_any.is_enabled AS org_override_any
        FROM products p
        LEFT JOIN product_availability av_store_variant
          ON av_store_variant.product_id = p.id
         AND av_store_variant.scope_type = 'store'
         AND av_store_variant.scope_value = ?
        LEFT JOIN product_availability av_store_provider
          ON av_store_provider.product_id IS NULL
         AND av_store_provider.product_code = p.code
         AND av_store_provider.provider = p.provider
         AND av_store_provider.scope_type = 'store'
         AND av_store_provider.scope_value = ?
        LEFT JOIN product_availability av_store_any
          ON av_store_any.product_id IS NULL
         AND av_store_any.product_code = p.code
         AND av_store_any.provider = ''
         AND av_store_any.scope_type = 'store'
         AND av_store_any.scope_value = ?
        LEFT JOIN product_availability av_org_variant
          ON av_org_variant.product_id = p.id
         AND av_org_variant.scope_type = 'org'
         AND av_org_variant.scope_value = ?
        LEFT JOIN product_availability av_org_provider
          ON av_org_provider.product_id IS NULL
         AND av_org_provider.product_code = p.code
         AND av_org_provider.provider = p.provider
         AND av_org_provider.scope_type = 'org'
         AND av_org_provider.scope_value = ?
        LEFT JOIN product_availability av_org_any
          ON av_org_any.product_id IS NULL
         AND av_org_any.product_code = p.code
         AND av_org_any.provider = ''
         AND av_org_any.scope_type = 'org'
         AND av_org_any.scope_value = ?
        LEFT JOIN product_availability av_group_variant
          ON av_group_variant.product_id = p.id
         AND av_group_variant.scope_type = 'org'
         AND av_group_variant.scope_value = ?
        LEFT JOIN product_availability av_group_provider
          ON av_group_provider.product_id IS NULL
         AND av_group_provider.product_code = p.code
         AND av_group_provider.provider = p.provider
         AND av_group_provider.scope_type = 'org'
         AND av_group_provider.scope_value = ?
        LEFT JOIN product_availability av_group_any
          ON av_group_any.product_id IS NULL
         AND av_group_any.product_code = p.code
         AND av_group_any.provider = ''
         AND av_group_any.scope_type = 'org'
         AND av_group_any.scope_value = ?
        LEFT JOIN product_availability av_vertical_variant
          ON av_vertical_variant.product_id = p.id
         AND av_vertical_variant.scope_type = 'vertical'
         AND av_vertical_variant.scope_value = ?
        LEFT JOIN product_availability av_vertical_provider
          ON av_vertical_provider.product_id IS NULL
         AND av_vertical_provider.product_code = p.code
         AND av_vertical_provider.provider = p.provider
         AND av_vertical_provider.scope_type = 'vertical'
         AND av_vertical_provider.scope_value = ?
        LEFT JOIN product_availability av_vertical_any
          ON av_vertical_any.product_id IS NULL
         AND av_vertical_any.product_code = p.code
         AND av_vertical_any.provider = ''
         AND av_vertical_any.scope_type = 'vertical'
         AND av_vertical_any.scope_value = ?
        LEFT JOIN product_availability av_global_variant
          ON av_global_variant.product_id = p.id
         AND av_global_variant.scope_type = 'global'
         AND av_global_variant.scope_value = ''
        LEFT JOIN product_availability av_global_provider
          ON av_global_provider.product_id IS NULL
         AND av_global_provider.product_code = p.code
         AND av_global_provider.provider = p.provider
         AND av_global_provider.scope_type = 'global'
         AND av_global_provider.scope_value = ''
        LEFT JOIN product_availability av_global_any
          ON av_global_any.product_id IS NULL
         AND av_global_any.product_code = p.code
         AND av_global_any.provider = ''
         AND av_global_any.scope_type = 'global'
         AND av_global_any.scope_value = ''
        WHERE p.is_active = 1
        ORDER BY p.name ASC, p.provider ASC
    ");
    $stmt->execute([
        $storeId === null ? '' : (string)$storeId,
        $storeId === null ? '' : (string)$storeId,
        $storeId === null ? '' : (string)$storeId,
        $selectedOrgId === null ? '' : (string)$selectedOrgId,
        $selectedOrgId === null ? '' : (string)$selectedOrgId,
        $selectedOrgId === null ? '' : (string)$selectedOrgId,
        $groupId === null ? '' : (string)$groupId,
        $groupId === null ? '' : (string)$groupId,
        $groupId === null ? '' : (string)$groupId,
        (string)$logicType,
        (string)$logicType,
        (string)$logicType,
    ]);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html>
<head>
  <?php include __DIR__ . "/includes/head_favicon.php"; ?>
<meta charset="UTF-8">
  <title>Admin - Store Product Availability</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php dealerfai_theme_head(); ?>
  <style nonce="<?= dealerfai_csp_nonce() ?>">
    body { font-family: Arial, sans-serif; background: #f4f6f8; margin: 0; padding: 0; }
    header { background: #0066cc; color: white; padding: 20px; text-align: center; }
    nav { background: #0066cc; padding: 12px; text-align: center; }
    nav a { color: white; margin: 0 20px; text-decoration: none; font-weight: bold; }
    .container { max-width: 1100px; margin: 20px auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
    h2 { margin-top: 0; }
    .row { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
    .row label { font-weight: bold; }
    select, input[type="text"] { padding: 8px; border-radius: 4px; border: 1px solid #ccc; }
    table { width: 100%; border-collapse: collapse; margin-top: 16px; }
    th, td { padding: 10px; border-bottom: 1px solid #ddd; vertical-align: top; text-align: left; }
    th { background: #f1f4f8; }
    .btn { background: #0066cc; color: white; padding: 8px 14px; border: none; border-radius: 4px; cursor: pointer; }
    .btn:hover { background: #094c63; }
    .btn.secondary { background: #e6ebf2; color: #1b2c40; border: 1px solid #c7d0d8; }
    .muted { color: #667085; font-size: 0.9rem; }
    .success { color: green; margin-top: 10px; }
    .error { color: #c0392b; margin-top: 10px; }
    .tag { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 12px; background: #eef2f6; color: #2b3a4a; }
    .tag.store { background: #e7f7ed; color: #1f7a3d; }
    .tag.disabled { background: #fdecea; color: #b42318; border: 1px solid #f1b4b0; }
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
    <h2>Store Product Availability</h2>
    <p class="muted">Select a store or group and choose which products are enabled. Group changes apply to all stores unless a store-level override exists.</p>

    <?php if ($message): ?>
      <p class="success">✅ <?= htmlspecialchars($message) ?></p>
    <?php endif; ?>
    <?php if ($error): ?>
      <p class="error">⚠️ <?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <form method="get" class="row" class="mt-12">
      <label for="org_id">Organization</label>
      <select id="org_id" name="org_id">
        <?php if (!empty($groups)): ?>
          <optgroup label="Groups">
            <?php foreach ($groups as $group): ?>
              <option value="<?= (int)$group['id'] ?>" <?= (int)$group['id'] === $selectedOrgId ? 'selected' : '' ?>>
                <?= htmlspecialchars($group['name']) ?>
              </option>
            <?php endforeach; ?>
          </optgroup>
        <?php endif; ?>
        <optgroup label="Stores">
          <?php foreach ($stores as $store): ?>
            <option value="<?= (int)$store['id'] ?>" <?= (int)$store['id'] === $selectedOrgId ? 'selected' : '' ?>>
              <?= htmlspecialchars($store['name']) ?>
            </option>
          <?php endforeach; ?>
        </optgroup>
      </select>
      <button type="submit" class="btn">Load</button>
      <input type="text" id="productFilter" placeholder="Filter products...">
      <button type="button" class="btn secondary" id="selectAll">Select all</button>
      <button type="button" class="btn secondary" id="selectNone">Select none</button>
    </form>

    <form method="post" class="mt-16">
      <input type="hidden" name="action" value="save_store_products">
      <input type="hidden" name="org_id" value="<?= (int)$selectedOrgId ?>">
      <input type="hidden" name="org_kind" value="<?= htmlspecialchars($selectedOrgKind) ?>">

      <table id="productsTable">
        <thead>
          <tr>
            <th class="w-8">Enabled</th>
            <th style="width:28%; cursor:pointer;" data-sort="product">Product</th>
            <th class="w-18-pointer" data-sort="provider">Provider</th>
            <th class="w-20">Status</th>
            <th class="w-22">Code</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($products as $product): ?>
            <?php
              $enabled = (int)($product['availability_enabled'] ?? 1) === 1;
              $storeOverrideVariant = $product['store_override_variant'];
              $storeOverrideProvider = $product['store_override_provider'];
              $storeOverrideAny = $product['store_override_any'];
              $orgOverrideVariant = $product['org_override_variant'];
              $orgOverrideProvider = $product['org_override_provider'];
              $orgOverrideAny = $product['org_override_any'];
              if ($selectedOrgKind === 'group') {
                  if ($orgOverrideVariant !== null) {
                      $statusLabel = (int)$orgOverrideVariant === 1 ? 'Enabled for group (variant)' : 'Disabled for group (variant)';
                      $statusClass = 'tag store';
                  } elseif ($orgOverrideProvider !== null) {
                      $statusLabel = (int)$orgOverrideProvider === 1 ? 'Enabled for group (provider)' : 'Disabled for group (provider)';
                      $statusClass = 'tag store';
                  } elseif ($orgOverrideAny !== null) {
                      $statusLabel = (int)$orgOverrideAny === 1 ? 'Enabled for group (all providers)' : 'Disabled for group (all providers)';
                      $statusClass = 'tag store';
                  } else {
                      $statusLabel = 'Inherited';
                      $statusClass = 'tag';
                  }
              } else {
                  if ($storeOverrideVariant !== null) {
                      $statusLabel = (int)$storeOverrideVariant === 1 ? 'Enabled for store (variant)' : 'Disabled for store (variant)';
                      $statusClass = 'tag store';
                  } elseif ($storeOverrideProvider !== null) {
                      $statusLabel = (int)$storeOverrideProvider === 1 ? 'Enabled for store (provider)' : 'Disabled for store (provider)';
                      $statusClass = 'tag store';
                  } elseif ($storeOverrideAny !== null) {
                      $statusLabel = (int)$storeOverrideAny === 1 ? 'Enabled for store (all providers)' : 'Disabled for store (all providers)';
                      $statusClass = 'tag store';
                  } else {
                      $statusLabel = 'Inherited';
                      $statusClass = 'tag';
                  }
              }
              if (!$enabled) {
                  $statusClass .= ' disabled';
              }
              $provider = trim((string)($product['provider'] ?? ''));
              $key = build_variant_key((int)$product['id']);
            ?>
            <tr>
              <td>
                <input type="checkbox" name="product_enabled[<?= htmlspecialchars($key) ?>]" value="1" <?= $enabled ? 'checked' : '' ?>>
              </td>
              <td><?= htmlspecialchars($product['name'] ?? $product['code']) ?></td>
              <td><?= htmlspecialchars($provider !== '' ? $provider : '—') ?></td>
              <td><span class="<?= $statusClass ?>"><?= htmlspecialchars($statusLabel) ?></span></td>
              <td><code><?= htmlspecialchars($product['code']) ?></code></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <div class="mt-16">
        <button type="submit" class="btn">Save Availability</button>
      </div>
    </form>
  </div>

  <script nonce="<?= dealerfai_csp_nonce() ?>">
    (function () {
      const csrfToken = <?= json_encode($csrfToken) ?>;
      document.querySelectorAll('form').forEach((form) => {
        const method = (form.getAttribute('method') || 'get').toLowerCase();
        if (method !== 'post') return;
        if (form.querySelector('input[name="csrf_token"]')) return;
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'csrf_token';
        input.value = csrfToken;
        form.appendChild(input);
      });
    })();

    const filterInput = document.getElementById('productFilter');
    const table = document.getElementById('productsTable');
    const rows = Array.from(table.querySelectorAll('tbody tr'));
    const headerCells = table.querySelectorAll('thead th[data-sort]');
    const sortState = { column: null, direction: 1 };

    filterInput.addEventListener('input', () => {
      const query = filterInput.value.toLowerCase();
      rows.forEach(row => {
        const text = row.innerText.toLowerCase();
        row.style.display = text.includes(query) ? '' : 'none';
      });
    });

    headerCells.forEach((th) => {
      th.addEventListener('click', () => {
        const key = th.getAttribute('data-sort');
        const columnIndex = key === 'product' ? 1 : 2;
        if (sortState.column === key) {
          sortState.direction *= -1;
        } else {
          sortState.column = key;
          sortState.direction = 1;
        }
        const sorted = rows.slice().sort((a, b) => {
          const aText = (a.children[columnIndex]?.innerText || '').trim().toLowerCase();
          const bText = (b.children[columnIndex]?.innerText || '').trim().toLowerCase();
          if (aText < bText) return -1 * sortState.direction;
          if (aText > bText) return 1 * sortState.direction;
          return 0;
        });
        const tbody = table.querySelector('tbody');
        sorted.forEach(row => tbody.appendChild(row));
      });
    });

    document.getElementById('selectAll').addEventListener('click', () => {
      rows.forEach(row => {
        const checkbox = row.querySelector('input[type="checkbox"]');
        if (checkbox) {
          checkbox.checked = true;
        }
      });
    });

    document.getElementById('selectNone').addEventListener('click', () => {
      rows.forEach(row => {
        const checkbox = row.querySelector('input[type="checkbox"]');
        if (checkbox) {
          checkbox.checked = false;
        }
      });
    });
  </script>
</body>
</html>
