<?php
include 'auth.php';
require_once __DIR__ . '/includes/theme_head.php';
include 'db.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/helpers/session_utils.php';
require_once __DIR__ . '/helpers/db_utils.php';

$roles = load_session_roles();
if (!in_array('Admin', $roles, true)) {
    http_response_code(403);
    echo 'Unauthorized';
    exit;
}
$adminAlertCount = get_admin_alert_count($db);

$productId = (int)($_GET['product_id'] ?? ($_POST['product_id'] ?? 0));
$message = '';
$error = '';

$product = null;
if ($productId > 0) {
    $stmt = $db->prepare("SELECT id, code, name, provider FROM products WHERE id = ?");
    $stmt->execute([$productId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
}

$orgRows = $db->query("SELECT id, name, org_kind FROM organizations ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

$tableReady = column_exists($db, 'product_deal_pricing_rules', 'product_id');

function as_nullable_decimal(string $raw): ?string {
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    // Let PDO/MySQL validate numeric; normalize commas/spaces a bit.
    $raw = str_replace([',', ' '], ['', ''], $raw);
    return $raw;
}

function as_nullable_int(string $raw): ?int {
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    $v = (int)$raw;
    return $v > 0 ? $v : null;
}

function normalize_scope_value(string $scopeType, string $scopeValue): string {
    if ($scopeType === 'global') {
        return '';
    }
    return trim($scopeValue);
}

function normalize_deal_type(?string $raw): string {
    $raw = strtolower(trim((string)$raw));
    if ($raw === '' || $raw === 'any') {
        return '';
    }
    return in_array($raw, ['cash', 'finance', 'lease'], true) ? $raw : '';
}

function validate_min_max(?float $min, ?float $max, string $label, string &$error): bool {
    if ($min !== null && $max !== null && $min > $max) {
        $error = "{$label}: min cannot be greater than max.";
        return false;
    }
    return true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!dealerfai_csrf_validate($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        die('Invalid request.');
    }

    $action = $_POST['action'] ?? '';
    if (!$productId || !$product) {
        $error = 'Select a valid product variant first.';
    } elseif (!$tableReady) {
        $error = 'Pricing rules table is not installed yet. Database schema update required.';
    } elseif ($action === 'add_rule' || $action === 'update_rule') {
        $ruleId = (int)($_POST['rule_id'] ?? 0);
        $scopeType = trim((string)($_POST['scope_type'] ?? 'global'));
        $scopeValue = normalize_scope_value($scopeType, (string)($_POST['scope_value'] ?? ''));
        $dealType = normalize_deal_type($_POST['deal_type'] ?? '');

        if (!in_array($scopeType, ['global', 'org', 'store'], true)) {
            $error = 'Invalid scope.';
        } elseif ($scopeType !== 'global' && $scopeValue === '') {
            $error = 'Scope value is required for org/store.';
        } else {
            $minVehicle = as_nullable_decimal((string)($_POST['min_vehicle_price'] ?? ''));
            $maxVehicle = as_nullable_decimal((string)($_POST['max_vehicle_price'] ?? ''));
            $minLoan = as_nullable_decimal((string)($_POST['min_loan_amount'] ?? ''));
            $maxLoan = as_nullable_decimal((string)($_POST['max_loan_amount'] ?? ''));
            $minTerm = as_nullable_int((string)($_POST['min_term'] ?? ''));
            $maxTerm = as_nullable_int((string)($_POST['max_term'] ?? ''));
            $price = as_nullable_decimal((string)($_POST['price'] ?? ''));
            $cost = as_nullable_decimal((string)($_POST['cost'] ?? ''));
            $coverageTerm = as_nullable_int((string)($_POST['coverage_term'] ?? ''));
            $label = trim((string)($_POST['label'] ?? ''));
            $label = $label !== '' ? $label : null;

            if ($price === null && $cost === null && $coverageTerm === null) {
                $error = 'Provide at least a Price or Cost (or Coverage Term).';
            } else {
                $minVehicleF = $minVehicle !== null ? (float)$minVehicle : null;
                $maxVehicleF = $maxVehicle !== null ? (float)$maxVehicle : null;
                $minLoanF = $minLoan !== null ? (float)$minLoan : null;
                $maxLoanF = $maxLoan !== null ? (float)$maxLoan : null;
                if (
                    validate_min_max($minVehicleF, $maxVehicleF, 'Vehicle price', $error)
                    && validate_min_max($minLoanF, $maxLoanF, 'Loan amount', $error)
                    && validate_min_max($minTerm !== null ? (float)$minTerm : null, $maxTerm !== null ? (float)$maxTerm : null, 'Term', $error)
                ) {
                    if ($action === 'add_rule') {
                        $stmt = $db->prepare("
                            INSERT INTO product_deal_pricing_rules
                                (product_id, scope_type, scope_value, deal_type,
                                 min_vehicle_price, max_vehicle_price, min_loan_amount, max_loan_amount,
                                 min_term, max_term, price, cost, coverage_term, label)
                            VALUES
                                (?, ?, ?, ?,
                                 ?, ?, ?, ?,
                                 ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $productId,
                            $scopeType,
                            $scopeValue,
                            $dealType,
                            $minVehicle,
                            $maxVehicle,
                            $minLoan,
                            $maxLoan,
                            $minTerm,
                            $maxTerm,
                            $price,
                            $cost,
                            $coverageTerm,
                            $label,
                        ]);
                        header('Location: admin_product_deal_pricing_rules.php?product_id=' . (int)$productId . '&saved=1');
                        exit;
                    } else {
                        if ($ruleId <= 0) {
                            $error = 'Missing rule id.';
                        } else {
                            $stmt = $db->prepare("
                                UPDATE product_deal_pricing_rules
                                SET scope_type = ?, scope_value = ?, deal_type = ?,
                                    min_vehicle_price = ?, max_vehicle_price = ?,
                                    min_loan_amount = ?, max_loan_amount = ?,
                                    min_term = ?, max_term = ?,
                                    price = ?, cost = ?, coverage_term = ?, label = ?
                                WHERE id = ? AND product_id = ?
                            ");
                            $stmt->execute([
                                $scopeType,
                                $scopeValue,
                                $dealType,
                                $minVehicle,
                                $maxVehicle,
                                $minLoan,
                                $maxLoan,
                                $minTerm,
                                $maxTerm,
                                $price,
                                $cost,
                                $coverageTerm,
                                $label,
                                $ruleId,
                                $productId,
                            ]);
                            header('Location: admin_product_deal_pricing_rules.php?product_id=' . (int)$productId . '&saved=1');
                            exit;
                        }
                    }
                }
            }
        }
    } elseif ($action === 'delete_rule') {
        $ruleId = (int)($_POST['rule_id'] ?? 0);
        if ($ruleId > 0 && $tableReady) {
            $stmt = $db->prepare("DELETE FROM product_deal_pricing_rules WHERE id = ? AND product_id = ?");
            $stmt->execute([$ruleId, $productId]);
            header('Location: admin_product_deal_pricing_rules.php?product_id=' . (int)$productId . '&saved=1');
            exit;
        }
    }
}
$csrfToken = dealerfai_csrf_get_token();

$saved = isset($_GET['saved']);
if ($saved && $message === '') {
    $message = 'Changes saved.';
}

$rules = [];
if ($productId > 0 && $tableReady) {
    $stmt = $db->prepare("
        SELECT *
        FROM product_deal_pricing_rules
        WHERE product_id = ?
        ORDER BY scope_type ASC, scope_value ASC, created_at DESC
    ");
    $stmt->execute([$productId]);
    $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html>
<head>
  <?php include __DIR__ . "/includes/head_favicon.php"; ?>
  <meta charset="UTF-8">
  <title>Admin - Product Deal Pricing Rules</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php dealerfai_theme_head(); ?>
  <style nonce="<?= dealerfai_csp_nonce() ?>">
    body { font-family: Arial, sans-serif; background: #f4f6f8; margin: 0; padding: 0; }
    header { background: #0a2e36; color: white; padding: 20px; text-align: center; position: relative; }
    nav { background: #0a2e36; padding: 12px; text-align: center; }
    nav a { color: white; margin: 0 20px; text-decoration: none; font-weight: bold; }
    .container { max-width: 1300px; margin: 20px auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
    table { width: 100%; border-collapse: collapse; margin-top: 16px; }
    th, td { padding: 10px; border-bottom: 1px solid #ddd; vertical-align: top; text-align: left; }
    th { background: #f1f4f8; }
    input[type="text"], input[type="number"], select { padding: 6px; border-radius: 4px; border: 1px solid #ccc; width: 100%; }
    .btn { background: #0a6280; color: white; padding: 8px 14px; border: none; border-radius: 4px; cursor: pointer; }
    .btn:hover { background: #094c63; }
    .success { color: green; margin-top: 10px; }
    .error { color: #c0392b; margin-top: 10px; }
    .muted { color: #667085; font-size: 0.9rem; margin-top: 4px; }
    .row { display: flex; gap: 12px; flex-wrap: wrap; }
    .row > div { flex: 1 1 180px; }
  </style>
</head>
<body>
  <header>
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
    <h2>Product Deal Pricing Rules</h2>
    <p class="muted">Set price/cost by vehicle price, estimated amount financed, and/or term. These rules do not depend on make/model/trim.</p>
    <p class="muted">Loan estimate used: `sale_price + doc_fee + ppsa_fee + included_protections_total - down_payment - trade_value + lien_amount`.</p>

    <?php if ($message): ?>
      <p class="success">✅ <?= htmlspecialchars($message) ?></p>
    <?php endif; ?>
    <?php if ($error): ?>
      <p class="error">⚠️ <?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <?php if (!$product): ?>
      <p class="error">Select a product variant from <a href="admin_products.php">All Products</a>.</p>
    <?php else: ?>
      <p><strong>Variant:</strong> <?= htmlspecialchars($product['name'] ?? '') ?> (<?= htmlspecialchars($product['code'] ?? '') ?><?= !empty($product['provider']) ? ' — ' . htmlspecialchars($product['provider']) : '' ?>)</p>
      <p><a href="admin_products.php">← Back to All Products</a></p>

      <?php if (!$tableReady): ?>
        <p class="error">Pricing rules table missing. Ask an admin to apply the latest database schema updates.</p>
      <?php else: ?>
        <h3>Add Rule</h3>
        <form method="post">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="action" value="add_rule">
          <input type="hidden" name="product_id" value="<?= (int)$productId ?>">
          <div class="row">
            <div>
              <label>Scope</label>
              <select name="scope_type">
                <option value="global">Global</option>
                <option value="org">Organization</option>
                <option value="store">Store</option>
              </select>
            </div>
            <div>
              <label>Scope Value</label>
              <select name="scope_value">
                <option value="">Select organization</option>
                <?php foreach ($orgRows as $orgRow): ?>
                  <option value="<?= (int)$orgRow['id'] ?>">
                    <?= htmlspecialchars($orgRow['name'] ?? '') ?> <?= ($orgRow['org_kind'] ?? 'store') === 'group' ? '(Group)' : '(Store)' ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <div class="muted">Not required for global.</div>
            </div>
            <div>
              <label>Deal Type</label>
              <select name="deal_type">
                <option value="any">Any</option>
                <option value="cash">Cash</option>
                <option value="finance">Finance</option>
                <option value="lease">Lease</option>
              </select>
            </div>
            <div>
              <label>Min Term (months)</label>
              <input type="number" name="min_term" min="1" placeholder="e.g. 24">
            </div>
            <div>
              <label>Max Term (months)</label>
              <input type="number" name="max_term" min="1" placeholder="e.g. 84">
            </div>
            <div>
              <label>Min Vehicle Price</label>
              <input type="number" step="0.01" name="min_vehicle_price" placeholder="e.g. 30000">
            </div>
            <div>
              <label>Max Vehicle Price</label>
              <input type="number" step="0.01" name="max_vehicle_price" placeholder="e.g. 90000">
            </div>
            <div>
              <label>Min Loan Amount</label>
              <input type="number" step="0.01" name="min_loan_amount" placeholder="e.g. 20000">
            </div>
            <div>
              <label>Max Loan Amount</label>
              <input type="number" step="0.01" name="max_loan_amount" placeholder="e.g. 70000">
            </div>
            <div>
              <label>Price</label>
              <input type="number" step="0.01" name="price" placeholder="e.g. 1899">
            </div>
            <div>
              <label>Cost</label>
              <input type="number" step="0.01" name="cost" placeholder="e.g. 900">
            </div>
            <div>
              <label>Coverage Term (optional)</label>
              <input type="number" name="coverage_term" placeholder="e.g. 60">
            </div>
            <div>
              <label>Label (optional)</label>
              <input type="text" name="label" placeholder="e.g. GAP 40k-80k loan">
            </div>
            <div class="flex-end-160">
              <button type="submit" class="btn">Save Rule</button>
            </div>
          </div>
        </form>

        <h3 class="mt-24">Existing Rules</h3>
        <table>
          <thead>
            <tr>
              <th>Scope</th>
              <th>Deal Type</th>
              <th>Term</th>
              <th>Vehicle Price</th>
              <th>Loan Amount</th>
              <th>Label</th>
              <th>Price</th>
              <th>Cost</th>
              <th>Coverage Term</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($rules)): ?>
              <tr><td colspan="10" class="muted">No deal pricing rules set yet.</td></tr>
            <?php else: ?>
              <?php foreach ($rules as $row): ?>
                <?php
                  $scopeValue = (string)($row['scope_value'] ?? '');
                  $scopeLabel = $scopeValue !== '' ? $scopeValue : '—';
                  foreach ($orgRows as $orgRow) {
                      if ((string)$orgRow['id'] === $scopeValue) {
                          $scopeLabel = $orgRow['name'] . ' (#' . $scopeValue . ')';
                          break;
                      }
                  }
                  $formId = 'rule-update-' . (int)$row['id'];
                  $dealTypeLabel = ($row['deal_type'] ?? '') !== '' ? (string)$row['deal_type'] : 'any';
                  $termText = (($row['min_term'] ?? null) !== null ? (int)$row['min_term'] : '—') . ' to ' . (($row['max_term'] ?? null) !== null ? (int)$row['max_term'] : '—');
                  $vehText = (($row['min_vehicle_price'] ?? null) !== null ? (float)$row['min_vehicle_price'] : '—') . ' to ' . (($row['max_vehicle_price'] ?? null) !== null ? (float)$row['max_vehicle_price'] : '—');
                  $loanText = (($row['min_loan_amount'] ?? null) !== null ? (float)$row['min_loan_amount'] : '—') . ' to ' . (($row['max_loan_amount'] ?? null) !== null ? (float)$row['max_loan_amount'] : '—');
                ?>
                <tr>
                  <td>
                    <select name="scope_type" form="<?= htmlspecialchars($formId) ?>">
                      <?php foreach (['global', 'org', 'store'] as $opt): ?>
                        <option value="<?= htmlspecialchars($opt) ?>" <?= ($row['scope_type'] ?? 'global') === $opt ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <div class="muted"><?= htmlspecialchars($scopeLabel) ?></div>
                    <select name="scope_value" form="<?= htmlspecialchars($formId) ?>">
                      <option value="">Select organization</option>
                      <?php foreach ($orgRows as $orgRow): ?>
                        <option value="<?= (int)$orgRow['id'] ?>" <?= (string)$orgRow['id'] === (string)($row['scope_value'] ?? '') ? 'selected' : '' ?>>
                          <?= htmlspecialchars($orgRow['name'] ?? '') ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </td>
                  <td>
                    <select name="deal_type" form="<?= htmlspecialchars($formId) ?>">
                      <option value="any" <?= $dealTypeLabel === 'any' ? 'selected' : '' ?>>Any</option>
                      <option value="cash" <?= $dealTypeLabel === 'cash' ? 'selected' : '' ?>>Cash</option>
                      <option value="finance" <?= $dealTypeLabel === 'finance' ? 'selected' : '' ?>>Finance</option>
                      <option value="lease" <?= $dealTypeLabel === 'lease' ? 'selected' : '' ?>>Lease</option>
                    </select>
                  </td>
                  <td>
                    <input type="number" name="min_term" value="<?= htmlspecialchars((string)($row['min_term'] ?? '')) ?>" placeholder="—" form="<?= htmlspecialchars($formId) ?>">
                    <input type="number" name="max_term" value="<?= htmlspecialchars((string)($row['max_term'] ?? '')) ?>" placeholder="—" form="<?= htmlspecialchars($formId) ?>" class="mt-6">
                    <div class="muted"><?= htmlspecialchars($termText) ?></div>
                  </td>
                  <td>
                    <input type="number" step="0.01" name="min_vehicle_price" value="<?= htmlspecialchars((string)($row['min_vehicle_price'] ?? '')) ?>" placeholder="—" form="<?= htmlspecialchars($formId) ?>">
                    <input type="number" step="0.01" name="max_vehicle_price" value="<?= htmlspecialchars((string)($row['max_vehicle_price'] ?? '')) ?>" placeholder="—" form="<?= htmlspecialchars($formId) ?>" class="mt-6">
                    <div class="muted"><?= htmlspecialchars($vehText) ?></div>
                  </td>
                  <td>
                    <input type="number" step="0.01" name="min_loan_amount" value="<?= htmlspecialchars((string)($row['min_loan_amount'] ?? '')) ?>" placeholder="—" form="<?= htmlspecialchars($formId) ?>">
                    <input type="number" step="0.01" name="max_loan_amount" value="<?= htmlspecialchars((string)($row['max_loan_amount'] ?? '')) ?>" placeholder="—" form="<?= htmlspecialchars($formId) ?>" class="mt-6">
                    <div class="muted"><?= htmlspecialchars($loanText) ?></div>
                  </td>
                  <td>
                    <input type="text" name="label" value="<?= htmlspecialchars((string)($row['label'] ?? '')) ?>" placeholder="—" form="<?= htmlspecialchars($formId) ?>">
                  </td>
                  <td>
                    <input type="number" step="0.01" name="price" value="<?= htmlspecialchars((string)($row['price'] ?? '')) ?>" placeholder="—" form="<?= htmlspecialchars($formId) ?>">
                  </td>
                  <td>
                    <input type="number" step="0.01" name="cost" value="<?= htmlspecialchars((string)($row['cost'] ?? '')) ?>" placeholder="—" form="<?= htmlspecialchars($formId) ?>">
                  </td>
                  <td>
                    <input type="number" name="coverage_term" value="<?= htmlspecialchars((string)($row['coverage_term'] ?? '')) ?>" placeholder="—" form="<?= htmlspecialchars($formId) ?>">
                  </td>
                  <td>
                    <form method="post" id="<?= htmlspecialchars($formId) ?>" class="d-inline">
                      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                      <input type="hidden" name="action" value="update_rule">
                      <input type="hidden" name="product_id" value="<?= (int)$productId ?>">
                      <input type="hidden" name="rule_id" value="<?= (int)$row['id'] ?>">
                      <button type="submit" class="btn">Save</button>
                    </form>
                    <form method="post" class="d-inline">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                      <input type="hidden" name="action" value="delete_rule">
                      <input type="hidden" name="product_id" value="<?= (int)$productId ?>">
                      <input type="hidden" name="rule_id" value="<?= (int)$row['id'] ?>">
                      <button type="submit" class="btn" class="bg-danger" onclick="return confirm('Remove this deal pricing rule?');">Delete</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</body>
</html>
