<?php
include 'auth.php';
require_once __DIR__ . '/includes/theme_head.php';
include 'db.php';
require_once __DIR__ . '/helpers/session_utils.php';
require_once __DIR__ . '/helpers/db_utils.php';
require_once __DIR__ . '/includes/csrf.php';

$roles = load_session_roles();
if (!in_array('Admin', $roles, true)) {
    http_response_code(403);
    echo 'Unauthorized';
    exit;
}
$adminAlertCount = get_admin_alert_count($db);
$csrfToken = dealerfai_csrf_get_token();

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
$hasCoverageKms = function_exists('column_exists') && column_exists($db, 'product_vehicle_pricing_overrides', 'coverage_kms');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !dealerfai_csrf_validate($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    $error = 'Invalid request token.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!$productId || !$product) {
        $error = 'Select a valid product variant first.';
    } elseif ($action === 'add_eligibility') {
        $makeIds = $_POST['make_ids'] ?? [];
        $modelIds = $_POST['model_ids'] ?? [];
        $trimIds = $_POST['trim_ids'] ?? [];
        $isEligible = isset($_POST['is_eligible']) ? (int)$_POST['is_eligible'] : 0;
        $vehicleCondition = strtolower(trim((string)($_POST['vehicle_condition'] ?? 'any')));
        $vehicleCondition = in_array($vehicleCondition, ['any', 'new', 'used'], true) ? $vehicleCondition : 'any';
        $maxKms = isset($_POST['max_kms']) ? (int)$_POST['max_kms'] : 0;
        $maxKms = $maxKms > 0 ? $maxKms : 0;
        $maxAgeYears = isset($_POST['max_age_years']) ? (int)$_POST['max_age_years'] : 0;
        $maxAgeYears = $maxAgeYears > 0 ? $maxAgeYears : 0;

        $makeIds = array_values(array_filter(array_map('intval', is_array($makeIds) ? $makeIds : [$makeIds]), fn($v) => $v > 0));
        $modelIds = array_values(array_filter(array_map('intval', is_array($modelIds) ? $modelIds : [$modelIds]), fn($v) => $v > 0));
        $trimIds = array_values(array_filter(array_map('intval', is_array($trimIds) ? $trimIds : [$trimIds]), fn($v) => $v > 0));

        if (empty($makeIds) && empty($modelIds) && empty($trimIds)) {
            $error = 'Select at least one make, model, or trim.';
        } else {
            $stmt = $db->prepare("
                INSERT INTO product_vehicle_eligibility
                    (product_id, make_id, model_id, trim_id, is_eligible, vehicle_condition, max_kms, max_age_years)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    is_eligible = VALUES(is_eligible),
                    vehicle_condition = VALUES(vehicle_condition),
                    max_kms = VALUES(max_kms),
                    max_age_years = VALUES(max_age_years)
            ");
            $inserted = 0;

            if (!empty($trimIds)) {
                $placeholders = implode(',', array_fill(0, count($trimIds), '?'));
                $lookup = $db->prepare("
                    SELECT vt.id AS trim_id, vm.id AS model_id, vm.make_id
                    FROM vehicle_trims vt
                    JOIN vehicle_models vm ON vm.id = vt.model_id
                    WHERE vt.id IN ({$placeholders})
                ");
                $lookup->execute($trimIds);
                while ($row = $lookup->fetch(PDO::FETCH_ASSOC)) {
                    $stmt->execute([
                        $productId,
                        (int)$row['make_id'],
                        (int)$row['model_id'],
                        (int)$row['trim_id'],
                        $isEligible,
                        $vehicleCondition,
                        $maxKms,
                        $maxAgeYears,
                    ]);
                    $inserted++;
                }
            } elseif (!empty($modelIds)) {
                $placeholders = implode(',', array_fill(0, count($modelIds), '?'));
                $lookup = $db->prepare("
                    SELECT id AS model_id, make_id
                    FROM vehicle_models
                    WHERE id IN ({$placeholders})
                ");
                $lookup->execute($modelIds);
                while ($row = $lookup->fetch(PDO::FETCH_ASSOC)) {
                    $stmt->execute([
                        $productId,
                        (int)$row['make_id'],
                        (int)$row['model_id'],
                        0,
                        $isEligible,
                        $vehicleCondition,
                        $maxKms,
                        $maxAgeYears,
                    ]);
                    $inserted++;
                }
            } else {
                foreach ($makeIds as $makeId) {
                    $stmt->execute([
                        $productId,
                        (int)$makeId,
                        0,
                        0,
                        $isEligible,
                        $vehicleCondition,
                        $maxKms,
                        $maxAgeYears,
                    ]);
                    $inserted++;
                }
            }

            $message = $inserted > 1 ? 'Eligibility rules saved.' : 'Eligibility rule saved.';
            header('Location: admin_product_vehicle_rules.php?product_id=' . (int)$productId . '&saved=1');
            exit;
        }
    } elseif ($action === 'delete_eligibility') {
        $ruleId = (int)($_POST['rule_id'] ?? 0);
        if ($ruleId > 0) {
            $stmt = $db->prepare("DELETE FROM product_vehicle_eligibility WHERE id = ? AND product_id = ?");
            $stmt->execute([$ruleId, $productId]);
            $message = 'Eligibility rule removed.';
            header('Location: admin_product_vehicle_rules.php?product_id=' . (int)$productId . '&saved=1');
            exit;
        }
    } elseif ($action === 'update_eligibility') {
        $ruleId = (int)($_POST['rule_id'] ?? 0);
        $isEligible = isset($_POST['is_eligible']) ? (int)$_POST['is_eligible'] : 0;
        $vehicleCondition = strtolower(trim((string)($_POST['vehicle_condition'] ?? 'any')));
        $vehicleCondition = in_array($vehicleCondition, ['any', 'new', 'used'], true) ? $vehicleCondition : 'any';
        $maxKms = isset($_POST['max_kms']) ? (int)$_POST['max_kms'] : 0;
        $maxKms = $maxKms > 0 ? $maxKms : 0;
        $maxAgeYears = isset($_POST['max_age_years']) ? (int)$_POST['max_age_years'] : 0;
        $maxAgeYears = $maxAgeYears > 0 ? $maxAgeYears : 0;
        if ($ruleId > 0) {
            $stmt = $db->prepare("
                UPDATE product_vehicle_eligibility
                SET is_eligible = ?, vehicle_condition = ?, max_kms = ?, max_age_years = ?
                WHERE id = ? AND product_id = ?
            ");
            $stmt->execute([$isEligible, $vehicleCondition, $maxKms, $maxAgeYears, $ruleId, $productId]);
            $message = 'Eligibility rule updated.';
            header('Location: admin_product_vehicle_rules.php?product_id=' . (int)$productId . '&saved=1');
            exit;
        }
    } elseif ($action === 'add_pricing_override') {
        $makeIds = $_POST['make_ids'] ?? [];
        $modelIds = $_POST['model_ids'] ?? [];
        $trimIds = $_POST['trim_ids'] ?? [];
        $price = trim((string)($_POST['price'] ?? ''));
        $cost = trim((string)($_POST['cost'] ?? ''));
        $coverageTerm = trim((string)($_POST['coverage_term'] ?? ''));
        $coverageTermValue = $coverageTerm !== '' ? (int)$coverageTerm : 0;
        $coverageKmsValue = $hasCoverageKms ? (int)($_POST['coverage_kms'] ?? 0) : 0;
        $coverageKmsValue = $coverageKmsValue > 0 ? $coverageKmsValue : null;
        $termLabel = trim((string)($_POST['term_label'] ?? ''));
        $maxKms = isset($_POST['max_kms']) ? (int)$_POST['max_kms'] : 0;
        $maxKms = $maxKms > 0 ? $maxKms : null;
        $maxAgeYears = isset($_POST['max_age_years']) ? (int)$_POST['max_age_years'] : 0;
        $maxAgeYears = $maxAgeYears > 0 ? $maxAgeYears : null;
        $scopeType = trim((string)($_POST['scope_type'] ?? 'global'));
        $scopeValue = trim((string)($_POST['scope_value'] ?? ''));
        if ($scopeType === 'global') {
            $scopeValue = '';
        }
        $makeIds = array_values(array_filter(array_map('intval', is_array($makeIds) ? $makeIds : [$makeIds]), fn($v) => $v > 0));
        $modelIds = array_values(array_filter(array_map('intval', is_array($modelIds) ? $modelIds : [$modelIds]), fn($v) => $v > 0));
        $trimIds = array_values(array_filter(array_map('intval', is_array($trimIds) ? $trimIds : [$trimIds]), fn($v) => $v > 0));

        if (empty($makeIds) && empty($modelIds) && empty($trimIds)) {
            $error = 'Select at least one make, model, or trim for pricing overrides.';
        } elseif (!in_array($scopeType, ['global', 'org', 'store'], true)) {
            $error = 'Invalid scope for pricing overrides.';
        } elseif ($scopeType !== 'global' && $scopeValue === '') {
            $error = 'Scope value is required for org/store pricing overrides.';
        } else {
            if ($hasCoverageKms) {
                $stmt = $db->prepare("
                    INSERT INTO product_vehicle_pricing_overrides
                        (product_id, vehicle_make_id, vehicle_model_id, vehicle_trim_id, scope_type, scope_value, price, cost, coverage_term, coverage_kms, term_label, max_kms, max_age_years)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        price = VALUES(price),
                        cost = VALUES(cost),
                        coverage_term = VALUES(coverage_term),
                        coverage_kms = VALUES(coverage_kms),
                        term_label = VALUES(term_label),
                        max_kms = VALUES(max_kms),
                        max_age_years = VALUES(max_age_years)
                ");
            } else {
                $stmt = $db->prepare("
                    INSERT INTO product_vehicle_pricing_overrides
                        (product_id, vehicle_make_id, vehicle_model_id, vehicle_trim_id, scope_type, scope_value, price, cost, coverage_term, term_label, max_kms, max_age_years)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        price = VALUES(price),
                        cost = VALUES(cost),
                        coverage_term = VALUES(coverage_term),
                        term_label = VALUES(term_label),
                        max_kms = VALUES(max_kms),
                        max_age_years = VALUES(max_age_years)
                ");
            }
            $inserted = 0;

            if (!empty($trimIds)) {
                $placeholders = implode(',', array_fill(0, count($trimIds), '?'));
                $lookup = $db->prepare("
                    SELECT vt.id AS trim_id, vm.id AS model_id, vm.make_id
                    FROM vehicle_trims vt
                    JOIN vehicle_models vm ON vm.id = vt.model_id
                    WHERE vt.id IN ({$placeholders})
                ");
                $lookup->execute($trimIds);
                while ($row = $lookup->fetch(PDO::FETCH_ASSOC)) {
                    $params = [
                        $productId,
                        (int)$row['make_id'],
                        (int)$row['model_id'],
                        (int)$row['trim_id'],
                        $scopeType,
                        $scopeValue,
                        $price !== '' ? $price : null,
                        $cost !== '' ? $cost : null,
                        $coverageTermValue,
                    ];
                    if ($hasCoverageKms) {
                        $params[] = $coverageKmsValue;
                    }
                    $params[] = $termLabel !== '' ? $termLabel : null;
                    $params[] = $maxKms;
                    $params[] = $maxAgeYears;
                    $stmt->execute($params);
                    $inserted++;
                }
            } elseif (!empty($modelIds)) {
                $placeholders = implode(',', array_fill(0, count($modelIds), '?'));
                $lookup = $db->prepare("
                    SELECT id AS model_id, make_id
                    FROM vehicle_models
                    WHERE id IN ({$placeholders})
                ");
                $lookup->execute($modelIds);
                while ($row = $lookup->fetch(PDO::FETCH_ASSOC)) {
                    $params = [
                        $productId,
                        (int)$row['make_id'],
                        (int)$row['model_id'],
                        0,
                        $scopeType,
                        $scopeValue,
                        $price !== '' ? $price : null,
                        $cost !== '' ? $cost : null,
                        $coverageTermValue,
                    ];
                    if ($hasCoverageKms) {
                        $params[] = $coverageKmsValue;
                    }
                    $params[] = $termLabel !== '' ? $termLabel : null;
                    $params[] = $maxKms;
                    $params[] = $maxAgeYears;
                    $stmt->execute($params);
                    $inserted++;
                }
            } else {
                foreach ($makeIds as $makeId) {
                    $params = [
                        $productId,
                        (int)$makeId,
                        0,
                        0,
                        $scopeType,
                        $scopeValue,
                        $price !== '' ? $price : null,
                        $cost !== '' ? $cost : null,
                        $coverageTermValue,
                    ];
                    if ($hasCoverageKms) {
                        $params[] = $coverageKmsValue;
                    }
                    $params[] = $termLabel !== '' ? $termLabel : null;
                    $params[] = $maxKms;
                    $params[] = $maxAgeYears;
                    $stmt->execute($params);
                    $inserted++;
                }
            }

            $message = $inserted > 1 ? 'Pricing overrides saved.' : 'Pricing override saved.';
            header('Location: admin_product_vehicle_rules.php?product_id=' . (int)$productId . '&saved=1');
            exit;
        }
    } elseif ($action === 'delete_pricing_override') {
        $overrideId = (int)($_POST['override_id'] ?? 0);
        if ($overrideId > 0) {
            $stmt = $db->prepare("DELETE FROM product_vehicle_pricing_overrides WHERE id = ? AND product_id = ?");
            $stmt->execute([$overrideId, $productId]);
            $message = 'Pricing override removed.';
            header('Location: admin_product_vehicle_rules.php?product_id=' . (int)$productId . '&saved=1');
            exit;
        }
    } elseif ($action === 'update_pricing_override') {
        $overrideId = (int)($_POST['override_id'] ?? 0);
        $price = trim((string)($_POST['price'] ?? ''));
        $cost = trim((string)($_POST['cost'] ?? ''));
        $coverageTerm = trim((string)($_POST['coverage_term'] ?? ''));
        $coverageTermValue = $coverageTerm !== '' ? (int)$coverageTerm : 0;
        $coverageKmsValue = $hasCoverageKms ? (int)($_POST['coverage_kms'] ?? 0) : 0;
        $coverageKmsValue = $coverageKmsValue > 0 ? $coverageKmsValue : null;
        $termLabel = trim((string)($_POST['term_label'] ?? ''));
        $maxKms = isset($_POST['max_kms']) ? (int)$_POST['max_kms'] : 0;
        $maxKms = $maxKms > 0 ? $maxKms : null;
        $maxAgeYears = isset($_POST['max_age_years']) ? (int)$_POST['max_age_years'] : 0;
        $maxAgeYears = $maxAgeYears > 0 ? $maxAgeYears : null;
        if ($overrideId > 0) {
            if ($hasCoverageKms) {
                $stmt = $db->prepare("
                    UPDATE product_vehicle_pricing_overrides
                    SET price = ?, cost = ?, coverage_term = ?, coverage_kms = ?, term_label = ?, max_kms = ?, max_age_years = ?
                    WHERE id = ? AND product_id = ?
                ");
                $stmt->execute([
                    $price !== '' ? $price : null,
                    $cost !== '' ? $cost : null,
                    $coverageTermValue > 0 ? $coverageTermValue : null,
                    $coverageKmsValue,
                    $termLabel !== '' ? $termLabel : null,
                    $maxKms,
                    $maxAgeYears,
                    $overrideId,
                    $productId,
                ]);
            } else {
                $stmt = $db->prepare("
                    UPDATE product_vehicle_pricing_overrides
                    SET price = ?, cost = ?, coverage_term = ?, term_label = ?, max_kms = ?, max_age_years = ?
                    WHERE id = ? AND product_id = ?
                ");
                $stmt->execute([
                    $price !== '' ? $price : null,
                    $cost !== '' ? $cost : null,
                    $coverageTermValue > 0 ? $coverageTermValue : null,
                    $termLabel !== '' ? $termLabel : null,
                    $maxKms,
                    $maxAgeYears,
                    $overrideId,
                    $productId,
                ]);
            }
            $message = 'Pricing override updated.';
            header('Location: admin_product_vehicle_rules.php?product_id=' . (int)$productId . '&saved=1');
            exit;
        }
    } elseif ($action === 'set_term_default') {
        $scopeType = trim((string)($_POST['scope_type'] ?? 'global'));
        $scopeValue = trim((string)($_POST['scope_value'] ?? ''));
        $coverageTerm = (int)($_POST['coverage_term'] ?? 0);
        if ($scopeType === 'global') {
            $scopeValue = '';
        }
        if (!in_array($scopeType, ['global', 'org', 'store'], true)) {
            $error = 'Invalid scope type.';
        } elseif ($coverageTerm <= 0) {
            $error = 'Coverage term is required.';
        } elseif ($scopeType !== 'global' && $scopeValue === '') {
            $error = 'Scope value is required for this scope.';
        } else {
            $stmt = $db->prepare("
                INSERT INTO product_term_defaults (product_id, scope_type, scope_value, coverage_term)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE coverage_term = VALUES(coverage_term)
            ");
            $stmt->execute([$productId, $scopeType, $scopeValue, $coverageTerm]);
            $message = 'Default term saved.';
            header('Location: admin_product_vehicle_rules.php?product_id=' . (int)$productId . '&saved=1');
            exit;
        }
    } elseif ($action === 'delete_term_default') {
        $defaultId = (int)($_POST['default_id'] ?? 0);
        if ($defaultId > 0) {
            $stmt = $db->prepare("DELETE FROM product_term_defaults WHERE id = ? AND product_id = ?");
            $stmt->execute([$defaultId, $productId]);
            $message = 'Default term removed.';
            header('Location: admin_product_vehicle_rules.php?product_id=' . (int)$productId . '&saved=1');
            exit;
        }
    }
}

$saved = isset($_GET['saved']);
if ($saved && $message === '') {
    $message = 'Changes saved.';
}

$makes = $db->query("SELECT id, make_name FROM vehicle_makes ORDER BY make_name ASC")->fetchAll(PDO::FETCH_ASSOC);

$eligibilityRows = [];
$pricingRows = [];
$termDefaults = [];
if ($productId > 0) {
    $eligStmt = $db->prepare("
        SELECT pve.id, pve.make_id, pve.model_id, pve.trim_id, pve.is_eligible,
               pve.vehicle_condition, pve.max_kms, pve.max_age_years,
               vmk.make_name, vmd.model_name, vtr.trim_name
        FROM product_vehicle_eligibility pve
        JOIN vehicle_makes vmk ON vmk.id = pve.make_id
        LEFT JOIN vehicle_models vmd ON vmd.id = pve.model_id
        LEFT JOIN vehicle_trims vtr ON vtr.id = pve.trim_id
        WHERE pve.product_id = ?
        ORDER BY vmk.make_name ASC, vmd.model_name ASC, vtr.trim_name ASC
    ");
    $eligStmt->execute([$productId]);
    $eligibilityRows = $eligStmt->fetchAll(PDO::FETCH_ASSOC);

    $pricingStmt = $db->prepare("
        SELECT pvo.id, pvo.vehicle_make_id, pvo.vehicle_model_id, pvo.vehicle_trim_id, pvo.price, pvo.cost, pvo.coverage_term,
               " . ($hasCoverageKms ? "pvo.coverage_kms" : "NULL AS coverage_kms") . ",
               pvo.scope_type, pvo.scope_value, pvo.term_label, pvo.max_kms, pvo.max_age_years,
               vmk.make_name, vmd.model_name, vtr.trim_name
        FROM product_vehicle_pricing_overrides pvo
        JOIN vehicle_makes vmk ON vmk.id = pvo.vehicle_make_id
        LEFT JOIN vehicle_models vmd ON vmd.id = pvo.vehicle_model_id
        LEFT JOIN vehicle_trims vtr ON vtr.id = pvo.vehicle_trim_id
        WHERE pvo.product_id = ?
        ORDER BY vmk.make_name ASC, vmd.model_name ASC, vtr.trim_name ASC, pvo.coverage_term ASC
    ");
    $pricingStmt->execute([$productId]);
    $pricingRows = $pricingStmt->fetchAll(PDO::FETCH_ASSOC);

    $termStmt = $db->prepare("
        SELECT id, scope_type, scope_value, coverage_term
        FROM product_term_defaults
        WHERE product_id = ?
        ORDER BY scope_type ASC, scope_value ASC
    ");
    $termStmt->execute([$productId]);
    $termDefaults = $termStmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html>
<head>
  <?php include __DIR__ . "/includes/head_favicon.php"; ?>
  <meta charset="UTF-8">
  <title>Admin - Product Vehicle Rules</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php dealerfai_theme_head(); ?>
  <style nonce="<?= dealerfai_csp_nonce() ?>">
    body { font-family: Arial, sans-serif; background: #f4f6f8; margin: 0; padding: 0; }
    header { background: #0a2e36; color: white; padding: 20px; text-align: center; position: relative; }
    nav { background: #0a2e36; padding: 12px; text-align: center; }
    nav a { color: white; margin: 0 20px; text-decoration: none; font-weight: bold; }
    .container { max-width: 1100px; margin: 20px auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
    h2 { margin-top: 0; }
    table { width: 100%; border-collapse: collapse; margin-top: 16px; }
    th, td { padding: 10px; border-bottom: 1px solid #ddd; vertical-align: top; text-align: left; }
    th { background: #f1f4f8; }
    input[type="text"], input[type="number"], select { padding: 6px; border-radius: 4px; border: 1px solid #ccc; width: 100%; }
    .btn { background: #0a6280; color: white; padding: 8px 14px; border: none; border-radius: 4px; cursor: pointer; }
    .btn:hover { background: #094c63; }
    .muted { color: #667085; font-size: 0.9rem; margin-top: 4px; }
    .success { color: green; margin-top: 10px; }
    .error { color: #c0392b; margin-top: 10px; }
    .row { display: flex; gap: 12px; flex-wrap: wrap; }
    .row > div { flex: 1 1 200px; }
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
    <h2>Product Vehicle Rules</h2>
    <p class="muted">Manage eligibility and pricing overrides by make, model, and trim for a single product variant.</p>
    <p class="muted">If any eligible rules exist for a product, only matching vehicles are eligible. Otherwise, rules act as exclusions.</p>

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

      <h3>Eligibility Rules</h3>
      <form method="post">
        <input type="hidden" name="action" value="add_eligibility">
        <input type="hidden" name="product_id" value="<?= (int)$productId ?>">
        <div class="row">
          <div>
            <label>Make</label>
            <select name="make_ids[]" id="elig_make" multiple size="6" required>
              <?php foreach ($makes as $make): ?>
                <option value="<?= (int)$make['id'] ?>"><?= htmlspecialchars($make['make_name']) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="muted">Hold Ctrl/Cmd to select multiple.</div>
          </div>
          <div>
            <label>Model (optional)</label>
            <select name="model_ids[]" id="elig_model" multiple size="6">
              <option value="">Select model</option>
            </select>
            <div class="muted">Loads from selected makes.</div>
          </div>
          <div>
            <label>Trim (optional)</label>
            <select name="trim_ids[]" id="elig_trim" multiple size="6">
              <option value="">Select trim</option>
            </select>
            <div class="muted">Loads from selected models.</div>
          </div>
          <div class="max-w-160">
            <label>Eligible?</label>
            <select name="is_eligible">
              <option value="1">Yes</option>
              <option value="0">No</option>
            </select>
          </div>
          <div style="max-width:180px;">
            <label>Vehicle Condition</label>
            <select name="vehicle_condition">
              <option value="any">Any</option>
              <option value="new">New only</option>
              <option value="used">Used only</option>
            </select>
          </div>
          <div class="max-w-160">
            <label>Max KMs</label>
            <input type="number" name="max_kms" min="0" placeholder="e.g. 100000">
            <div class="muted">Leave blank for no limit.</div>
          </div>
          <div class="max-w-160">
            <label>Max Age (years)</label>
            <input type="number" name="max_age_years" min="0" placeholder="e.g. 5">
            <div class="muted">Uses in-service date or model year.</div>
          </div>
          <div class="flex-end-160">
            <button type="submit" class="btn">Save Rule</button>
          </div>
        </div>
      </form>

      <table>
        <thead>
          <tr>
            <th>Make</th>
            <th>Model</th>
            <th>Trim</th>
            <th>Condition</th>
            <th>Max KMs</th>
            <th>Max Age</th>
            <th>Eligible</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($eligibilityRows)): ?>
            <tr><td colspan="8" class="muted">No eligibility rules set yet.</td></tr>
          <?php else: ?>
            <?php foreach ($eligibilityRows as $row): ?>
              <?php $eligFormId = 'elig-update-' . (int)$row['id']; ?>
              <tr>
                <td><?= htmlspecialchars($row['make_name'] ?? '') ?></td>
                <td><?= htmlspecialchars($row['model_name'] ?? '—') ?></td>
                <td><?= htmlspecialchars($row['trim_name'] ?? '—') ?></td>
                <td>
                    <?php $cond = strtolower((string)($row['vehicle_condition'] ?? 'any')); ?>
                    <select name="vehicle_condition" form="<?= htmlspecialchars($eligFormId) ?>">
                      <option value="any" <?= $cond === 'any' ? 'selected' : '' ?>>Any</option>
                      <option value="new" <?= $cond === 'new' ? 'selected' : '' ?>>New</option>
                      <option value="used" <?= $cond === 'used' ? 'selected' : '' ?>>Used</option>
                    </select>
                </td>
                <td>
                    <input type="number" name="max_kms" min="0" placeholder="—" value="<?= htmlspecialchars((string)($row['max_kms'] ?? '')) ?>" form="<?= htmlspecialchars($eligFormId) ?>">
                </td>
                <td>
                    <input type="number" name="max_age_years" min="0" placeholder="—" value="<?= htmlspecialchars((string)($row['max_age_years'] ?? '')) ?>" form="<?= htmlspecialchars($eligFormId) ?>">
                </td>
                <td>
                    <select name="is_eligible" form="<?= htmlspecialchars($eligFormId) ?>">
                      <option value="1" <?= (int)($row['is_eligible'] ?? 0) === 1 ? 'selected' : '' ?>>Yes</option>
                      <option value="0" <?= (int)($row['is_eligible'] ?? 0) === 0 ? 'selected' : '' ?>>No</option>
                    </select>
                </td>
                <td>
                  <form method="post" id="<?= htmlspecialchars($eligFormId) ?>" class="d-inline">
                    <input type="hidden" name="action" value="update_eligibility">
                    <input type="hidden" name="product_id" value="<?= (int)$productId ?>">
                    <input type="hidden" name="rule_id" value="<?= (int)$row['id'] ?>">
                    <button type="submit" class="btn">Save</button>
                  </form>
                  <form method="post" class="d-inline">
                    <input type="hidden" name="action" value="delete_eligibility">
                    <input type="hidden" name="product_id" value="<?= (int)$productId ?>">
                    <input type="hidden" name="rule_id" value="<?= (int)$row['id'] ?>">
                    <button type="submit" class="btn" class="bg-danger" onclick="return confirm('Remove this eligibility rule?');">Delete</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>

      <h3 class="mt-24">Default Term</h3>
      <p class="muted">Choose which term should be pre-selected when multiple term options are available.</p>
      <form method="post">
        <input type="hidden" name="action" value="set_term_default">
        <input type="hidden" name="product_id" value="<?= (int)$productId ?>">
        <div class="row">
          <div>
            <label>Scope</label>
            <select name="scope_type" id="term_scope_type">
              <option value="global">Global</option>
              <option value="org">Organization</option>
              <option value="store">Store</option>
            </select>
          </div>
          <div>
            <label>Scope Value</label>
            <select name="scope_value" id="term_scope_value">
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
            <label>Coverage Term (months)</label>
            <input type="number" name="coverage_term" min="1" placeholder="e.g. 36">
          </div>
          <div class="flex-end">
            <button type="submit" class="btn">Save Default</button>
          </div>
        </div>
      </form>

      <table>
        <thead>
          <tr>
            <th>Scope</th>
            <th>Value</th>
            <th>Coverage Term</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($termDefaults)): ?>
            <tr><td colspan="4" class="muted">No default terms set yet.</td></tr>
          <?php else: ?>
            <?php foreach ($termDefaults as $row): ?>
              <?php
                $scopeValue = (string)($row['scope_value'] ?? '');
                $scopeLabel = $scopeValue !== '' ? $scopeValue : '—';
                foreach ($orgRows as $orgRow) {
                    if ((string)$orgRow['id'] === $scopeValue) {
                        $scopeLabel = $orgRow['name'] . ' (#' . $scopeValue . ')';
                        break;
                    }
                }
              ?>
              <tr>
                <td><?= htmlspecialchars($row['scope_type'] ?? '') ?></td>
                <td><?= htmlspecialchars($scopeLabel) ?></td>
                <td><?= (int)($row['coverage_term'] ?? 0) ?> months</td>
                <td>
                  <form method="post" class="d-inline">
                    <input type="hidden" name="action" value="delete_term_default">
                    <input type="hidden" name="product_id" value="<?= (int)$productId ?>">
                    <input type="hidden" name="default_id" value="<?= (int)$row['id'] ?>">
                    <button type="submit" class="btn" class="bg-danger" onclick="return confirm('Remove this default term?');">Delete</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>

      <h3 class="mt-30">Pricing Overrides</h3>
      <form method="post">
        <input type="hidden" name="action" value="add_pricing_override">
        <input type="hidden" name="product_id" value="<?= (int)$productId ?>">
        <div class="row">
          <div>
            <label>Make</label>
            <select name="make_ids[]" id="price_make" multiple size="6" required>
              <?php foreach ($makes as $make): ?>
                <option value="<?= (int)$make['id'] ?>"><?= htmlspecialchars($make['make_name']) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="muted">Hold Ctrl/Cmd to select multiple.</div>
          </div>
          <div>
            <label>Model (optional)</label>
            <select name="model_ids[]" id="price_model" multiple size="6">
              <option value="">Select model</option>
            </select>
            <div class="muted">Loads from selected makes.</div>
          </div>
          <div>
            <label>Trim (optional)</label>
            <select name="trim_ids[]" id="price_trim" multiple size="6">
              <option value="">Select trim</option>
            </select>
            <div class="muted">Loads from selected models.</div>
          </div>
          <div>
            <label>Price</label>
            <input type="number" step="0.01" name="price" placeholder="e.g. 1495">
          </div>
          <div>
            <label>Cost</label>
            <input type="number" step="0.01" name="cost" placeholder="e.g. 800">
          </div>
          <div>
            <label>Scope</label>
            <select name="scope_type" id="pricing_scope_type">
              <option value="global">Global</option>
              <option value="org">Organization</option>
              <option value="store">Store</option>
            </select>
          </div>
          <div>
            <label>Scope Value</label>
            <select name="scope_value" id="pricing_scope_value">
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
            <label>Coverage Term (months)</label>
            <input type="number" name="coverage_term" placeholder="e.g. 60">
          </div>
          <?php if ($hasCoverageKms): ?>
          <div>
            <label>Coverage KMs (optional)</label>
            <input type="number" name="coverage_kms" placeholder="e.g. 80000">
          </div>
          <?php endif; ?>
          <div>
            <label>Term Label (optional)</label>
            <input type="text" name="term_label" placeholder="e.g. 60 Months (from in-service)">
          </div>
          <div>
            <label>Max KMs (optional)</label>
            <input type="number" name="max_kms" placeholder="e.g. 80000">
          </div>
          <div>
            <label>Max Age (years, optional)</label>
            <input type="number" name="max_age_years" placeholder="e.g. 4">
          </div>
          <div class="flex-end-160">
            <button type="submit" class="btn">Save Override</button>
          </div>
        </div>
      </form>

      <table>
        <thead>
          <tr>
            <th>Make</th>
            <th>Model</th>
            <th>Trim</th>
            <th>Scope</th>
            <th>Label</th>
            <th>Price</th>
            <th>Cost</th>
            <th>Term</th>
            <?php if ($hasCoverageKms): ?><th>Coverage KMs</th><?php endif; ?>
            <th>Max KMs</th>
            <th>Max Age (Years)</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($pricingRows)): ?>
            <tr><td colspan="<?= $hasCoverageKms ? 12 : 11 ?>" class="muted">No pricing overrides set yet.</td></tr>
          <?php else: ?>
            <?php foreach ($pricingRows as $row): ?>
              <?php
                $scopeValue = (string)($row['scope_value'] ?? '');
                $scopeLabel = $scopeValue !== '' ? $scopeValue : '—';
                foreach ($orgRows as $orgRow) {
                    if ((string)$orgRow['id'] === $scopeValue) {
                        $scopeLabel = $orgRow['name'] . ' (#' . $scopeValue . ')';
                        break;
                    }
                }
              ?>
              <?php $priceFormId = 'price-update-' . (int)$row['id']; ?>
              <tr>
                <td><?= htmlspecialchars($row['make_name'] ?? '') ?></td>
                <td><?= htmlspecialchars($row['model_name'] ?? '—') ?></td>
                <td><?= htmlspecialchars($row['trim_name'] ?? '—') ?></td>
                <td><?= htmlspecialchars(($row['scope_type'] ?? '') . ($scopeLabel !== '—' ? ' — ' . $scopeLabel : '')) ?></td>
                <td>
                    <input type="text" name="term_label" value="<?= htmlspecialchars((string)($row['term_label'] ?? '')) ?>" placeholder="—" form="<?= htmlspecialchars($priceFormId) ?>">
                </td>
                <td>
                    <input type="number" step="0.01" name="price" value="<?= htmlspecialchars((string)($row['price'] ?? '')) ?>" placeholder="—" form="<?= htmlspecialchars($priceFormId) ?>">
                </td>
                <td>
                    <input type="number" step="0.01" name="cost" value="<?= htmlspecialchars((string)($row['cost'] ?? '')) ?>" placeholder="—" form="<?= htmlspecialchars($priceFormId) ?>">
                </td>
                <td>
                    <input type="number" name="coverage_term" value="<?= htmlspecialchars((string)($row['coverage_term'] ?? '')) ?>" placeholder="—" form="<?= htmlspecialchars($priceFormId) ?>">
                </td>
                <?php if ($hasCoverageKms): ?>
                <td>
                    <input type="number" name="coverage_kms" value="<?= htmlspecialchars((string)($row['coverage_kms'] ?? '')) ?>" placeholder="—" form="<?= htmlspecialchars($priceFormId) ?>">
                </td>
                <?php endif; ?>
                <td>
                    <input type="number" name="max_kms" value="<?= htmlspecialchars((string)($row['max_kms'] ?? '')) ?>" placeholder="—" form="<?= htmlspecialchars($priceFormId) ?>">
                </td>
                <td>
                    <input type="number" name="max_age_years" value="<?= htmlspecialchars((string)($row['max_age_years'] ?? '')) ?>" placeholder="—" form="<?= htmlspecialchars($priceFormId) ?>">
                </td>
                <td>
                  <form method="post" id="<?= htmlspecialchars($priceFormId) ?>" class="d-inline">
                    <input type="hidden" name="action" value="update_pricing_override">
                    <input type="hidden" name="product_id" value="<?= (int)$productId ?>">
                    <input type="hidden" name="override_id" value="<?= (int)$row['id'] ?>">
                    <button type="submit" class="btn">Save</button>
                  </form>
                  <form method="post" class="d-inline">
                    <input type="hidden" name="action" value="delete_pricing_override">
                    <input type="hidden" name="product_id" value="<?= (int)$productId ?>">
                    <input type="hidden" name="override_id" value="<?= (int)$row['id'] ?>">
                    <button type="submit" class="btn" class="bg-danger" onclick="return confirm('Remove this pricing override?');">Delete</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    <?php endif; ?>
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

    function getSelectedValues(selectEl) {
      if (!selectEl) return [];
      return Array.from(selectEl.selectedOptions || []).map(opt => opt.value).filter(Boolean);
    }

    function buildOption(value, label) {
      const opt = document.createElement('option');
      opt.value = value;
      opt.textContent = label;
      return opt;
    }

    function loadModels(makeSelectId, modelSelectId, trimSelectId) {
      const makeSelect = document.getElementById(makeSelectId);
      const modelSelect = document.getElementById(modelSelectId);
      const trimSelect = document.getElementById(trimSelectId);
      if (!makeSelect || !modelSelect || !trimSelect) {
        return;
      }
      const makeIds = getSelectedValues(makeSelect);
      modelSelect.innerHTML = '';
      trimSelect.innerHTML = '';
      if (makeIds.length === 0) {
        modelSelect.appendChild(buildOption('', 'Select model'));
        trimSelect.appendChild(buildOption('', 'Select trim'));
        return;
      }
      const requests = makeIds.map(id =>
        fetch(`api/vehicle_models.php?make_id=${encodeURIComponent(id)}`)
          .then(resp => resp.json())
          .then(data => data.models || [])
          .catch(() => [])
      );
      Promise.all(requests).then(results => {
        const seen = new Map();
        results.flat().forEach(model => {
          if (!seen.has(model.id)) {
            seen.set(model.id, model.name);
          }
        });
        const entries = Array.from(seen.entries()).sort((a, b) => a[1].localeCompare(b[1]));
        modelSelect.appendChild(buildOption('', 'Select model'));
        entries.forEach(([id, name]) => {
          modelSelect.appendChild(buildOption(id, name));
        });
        trimSelect.appendChild(buildOption('', 'Select trim'));
      });
    }

    function loadTrims(modelSelectId, trimSelectId) {
      const modelSelect = document.getElementById(modelSelectId);
      const trimSelect = document.getElementById(trimSelectId);
      if (!modelSelect || !trimSelect) {
        return;
      }
      const modelIds = getSelectedValues(modelSelect);
      trimSelect.innerHTML = '';
      if (modelIds.length === 0) {
        trimSelect.appendChild(buildOption('', 'Select trim'));
        return;
      }
      const requests = modelIds.map(id =>
        fetch(`api/vehicle_trims.php?model_id=${encodeURIComponent(id)}`)
          .then(resp => resp.json())
          .then(data => data.trims || [])
          .catch(() => [])
      );
      Promise.all(requests).then(results => {
        const seen = new Map();
        results.flat().forEach(trim => {
          if (!seen.has(trim.id)) {
            seen.set(trim.id, trim.name);
          }
        });
        const entries = Array.from(seen.entries()).sort((a, b) => a[1].localeCompare(b[1]));
        trimSelect.appendChild(buildOption('', 'Select trim'));
        entries.forEach(([id, name]) => {
          trimSelect.appendChild(buildOption(id, name));
        });
      });
    }

    const eligMake = document.getElementById('elig_make');
    const eligModel = document.getElementById('elig_model');
    const eligTrim = document.getElementById('elig_trim');
    if (eligMake && eligModel && eligTrim) {
      eligMake.addEventListener('change', () => loadModels('elig_make', 'elig_model', 'elig_trim'));
      eligModel.addEventListener('change', () => loadTrims('elig_model', 'elig_trim'));
    }

    const priceMake = document.getElementById('price_make');
    const priceModel = document.getElementById('price_model');
    const priceTrim = document.getElementById('price_trim');
    if (priceMake && priceModel && priceTrim) {
      priceMake.addEventListener('change', () => loadModels('price_make', 'price_model', 'price_trim'));
      priceModel.addEventListener('change', () => loadTrims('price_model', 'price_trim'));
    }

    const termScopeType = document.getElementById('term_scope_type');
    const termScopeValue = document.getElementById('term_scope_value');
    if (termScopeType && termScopeValue) {
      const syncTermScope = () => {
        if (termScopeType.value === 'global') {
          termScopeValue.value = '';
          termScopeValue.setAttribute('disabled', 'disabled');
        } else {
          termScopeValue.removeAttribute('disabled');
        }
      };
      termScopeType.addEventListener('change', syncTermScope);
      syncTermScope();
    }

    const pricingScopeType = document.getElementById('pricing_scope_type');
    const pricingScopeValue = document.getElementById('pricing_scope_value');
    if (pricingScopeType && pricingScopeValue) {
      const syncPricingScope = () => {
        if (pricingScopeType.value === 'global') {
          pricingScopeValue.value = '';
          pricingScopeValue.setAttribute('disabled', 'disabled');
        } else {
          pricingScopeValue.removeAttribute('disabled');
        }
      };
      pricingScopeType.addEventListener('change', syncPricingScope);
      syncPricingScope();
    }
  </script>
</body>
</html>
