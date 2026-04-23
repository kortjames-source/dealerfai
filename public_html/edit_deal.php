<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/csrf.php';
include 'auth.php';
require_once __DIR__ . '/includes/theme_head.php';
include 'db.php';
require_once __DIR__ . '/helpers/session_utils.php';
require_once __DIR__ . '/helpers/deal_audit.php';
include_once __DIR__ . '/protection_helpers.php';
require_once __DIR__ . '/accessory_helpers.php';
require_once __DIR__ . '/helpers/customer_types.php';

function load_accessory_fitment_map_local_edit(PDO $db, array $accessoryIds): array {
    $ids = array_values(array_filter(array_map('intval', $accessoryIds), static function ($id) {
        return $id > 0;
    }));
    if (empty($ids)) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("SELECT * FROM accessory_fitment WHERE accessory_id IN ($placeholders)");
    $stmt->execute($ids);
    $map = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $aid = (int)($row['accessory_id'] ?? 0);
        if ($aid > 0) {
            $map[$aid][] = $row;
        }
    }
    return $map;
}

$dealId = $_GET['id'] ?? null;
if (!$dealId) die("Missing deal ID");

// Fetch the deal
$stmt = $db->prepare("SELECT * FROM deals WHERE id = ?");
$stmt->execute([$dealId]);
$deal = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$deal) die("Deal not found");

$hasBusinessesTable = false;
$businesses = [];
$dealBusinessName = '';
try {
    $tbl = $db->prepare("
        SELECT COUNT(*)
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'businesses'
    ");
    $tbl->execute();
    $hasBusinessesTable = (int)$tbl->fetchColumn() > 0;
} catch (PDOException $e) {
    $hasBusinessesTable = false;
}

if ($hasBusinessesTable) {
    try {
        $orgIdForBiz = isset($deal['organization']) ? (int)$deal['organization'] : 0;
        if ($orgIdForBiz > 0) {
            $bizStmt = $db->prepare("SELECT id, name FROM businesses WHERE organization_id = ? ORDER BY name ASC");
            $bizStmt->execute([$orgIdForBiz]);
            $businesses = $bizStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
        $bizId = isset($deal['business_id']) ? (int)$deal['business_id'] : 0;
        if ($bizId > 0) {
            $nameStmt = $db->prepare("SELECT name FROM businesses WHERE id = ? LIMIT 1");
            $nameStmt->execute([$bizId]);
            $dealBusinessName = (string)($nameStmt->fetchColumn() ?: '');
        }
    } catch (PDOException $e) {
        $businesses = [];
        $dealBusinessName = '';
    }
}

$formError = '';
$productCatalog = [];
try {
    $catalogStmt = $db->query("SELECT code, name, default_price FROM products WHERE is_active = 1 ORDER BY name ASC");
    foreach ($catalogStmt as $row) {
        $code = trim((string)($row['code'] ?? ''));
        if ($code === '') {
            continue;
        }
        if (isset($productCatalog[$code])) {
            continue;
        }
        $productCatalog[$code] = [
            'name' => $row['name'] ?? $code,
            'default_price' => isset($row['default_price']) ? (float)$row['default_price'] : null,
        ];
    }
} catch (PDOException $e) {
    error_log("Unable to load product catalog: " . $e->getMessage());
}
$includedProtections = function_exists('parse_included_protections')
    ? parse_included_protections($deal['included_protections'] ?? null)
    : [];
$includedByCode = [];
foreach ($includedProtections as $item) {
    if (!is_array($item)) {
        continue;
    }
    $code = trim((string)($item['code'] ?? ''));
    if ($code === '') {
        continue;
    }
    $includedByCode[$code] = $item;
}
$includeIncluded = !empty($includedByCode);
$includedSelections = array_keys($includedByCode);
$includedPrices = [];
foreach ($includedByCode as $code => $item) {
    $includedPrices[$code] = isset($item['price']) ? (string)$item['price'] : '';
}
$accessoryCatalog = [];
$includedAccessorySelections = [];
$includedAccessoryPrices = [];
$dealAccessoryById = [];
try {
    $includedAccessoryStmt = $db->prepare("SELECT * FROM deal_accessories WHERE deal_id = ? ORDER BY included ASC, id ASC");
    $includedAccessoryStmt->execute([$dealId]);
    while ($row = $includedAccessoryStmt->fetch(PDO::FETCH_ASSOC)) {
        $accId = (int)($row['accessory_id'] ?? 0);
        if ($accId <= 0) {
            continue;
        }
        if (!in_array($accId, $includedAccessorySelections, true)) {
            $includedAccessorySelections[] = $accId;
        }
        if (!isset($includedAccessoryPrices[$accId])) {
            $includedAccessoryPrices[$accId] = isset($row['sale_price']) ? (string)$row['sale_price'] : '';
        }
        if (!isset($dealAccessoryById[$accId])) {
            $dealAccessoryById[$accId] = $row;
        }
    }
} catch (PDOException $e) {
    error_log("Unable to load included accessories for edit: " . $e->getMessage());
}
$includeIncludedAccessories = !empty($includedAccessorySelections);

// Role check
$userId = $_SESSION['user_id'];
$userRoles = load_session_roles();
$isAdmin = in_array('Admin', $userRoles, true);
$adminAlertCount = $isAdmin ? get_admin_alert_count($db) : 0;

$allowed = (
    $deal['created_by'] == $userId ||
    in_array('Finance Manager', $userRoles) ||
    in_array('Sales Manager', $userRoles) ||
    in_array('General Manager', $userRoles) ||
    in_array('Admin', $userRoles)
);

if (!$allowed) {
    die("You do not have permission to edit this deal.");
}

// Load theme
$org = $_SESSION['organization'];
$theme = ['logo' => '', 'color' => '#0066cc'];
if ($org) {
    $orgStmt = $db->prepare("SELECT logo_url, theme_variant FROM organizations WHERE id = ?");
    $orgStmt->execute([$org]);
    $orgData = $orgStmt->fetch(PDO::FETCH_ASSOC);
    if ($orgData) {
        $theme['logo'] = $orgData['logo_url'] ?? '';
        $theme['color'] = $orgData['theme_variant'] === 'jlr' ? '#4a4a4a' : (in_array($orgData['theme_variant'], ['land_rover', 'jaguar'], true) ? '#1A1A1A' : '#0066cc');
    }
}

// Fetch roles
$orgClause = $isAdmin ? '' : ' AND organization = ?';
$orgParams = $isAdmin ? [] : [$org];

$users = [
    'sales_advisors' => $db->prepare('SELECT id, full_name FROM users WHERE JSON_CONTAINS(role, \'"Salesperson"\')' . $orgClause . ' ORDER BY full_name'),
    'sales_managers' => $db->prepare('SELECT id, full_name FROM users WHERE JSON_CONTAINS(role, \'"Sales Manager"\')' . $orgClause . ' ORDER BY full_name'),
    'finance_managers' => $db->prepare('SELECT id, full_name FROM users WHERE JSON_CONTAINS(role, \'"Finance Manager"\')' . $orgClause . ' ORDER BY full_name'),
];
foreach ($users as $key => $stmt) {
    $stmt->execute($orgParams);
    $$key = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Load vehicle makes and colours for dropdowns
$makeStmt = $db->query("SELECT id, make_name FROM vehicle_makes ORDER BY make_name ASC");
$vehicleMakes = $makeStmt->fetchAll(PDO::FETCH_ASSOC);
$vehicleMakeLookup = [];
foreach ($vehicleMakes as $make) {
    $vehicleMakeLookup[$make['id']] = $make['make_name'];
}
$selectedMakeId = $deal['vehicle_make_id'] ?? null;
if (!$selectedMakeId && !empty($deal['vehicle_make'])) {
    foreach ($vehicleMakeLookup as $id => $name) {
        if (strcasecmp($name, $deal['vehicle_make']) === 0) {
            $selectedMakeId = $id;
            break;
        }
    }
}
if ($selectedMakeId) {
    $deal['vehicle_make_id'] = $selectedMakeId;
}

$orgIdForAccessories = isset($deal['organization']) ? (int)$deal['organization'] : 0;
if ($orgIdForAccessories > 0) {
    try {
        $accessories = fetch_accessories_for_org($db, $orgIdForAccessories);
        $accessoryIds = array_values(array_filter(array_map(static fn($row) => (int)($row['id'] ?? 0), $accessories)));
        $fitmentMap = load_accessory_fitment_map_local_edit($db, $accessoryIds);
        $vehicleMake = trim((string)($deal['vehicle_make'] ?? ''));
        if ($vehicleMake === '' && !empty($deal['vehicle_make_id']) && isset($vehicleMakeLookup[(int)$deal['vehicle_make_id']])) {
            $vehicleMake = (string)$vehicleMakeLookup[(int)$deal['vehicle_make_id']];
        }
        $vehicleModel = trim((string)($deal['vehicle_model'] ?? ''));
        $vehicleYear = !empty($deal['vehicle_year']) ? (int)$deal['vehicle_year'] : null;
        $vehicleMakeId = !empty($deal['vehicle_make_id']) ? (int)$deal['vehicle_make_id'] : null;
        $vehicleModelId = !empty($deal['vehicle_model_id']) ? (int)$deal['vehicle_model_id'] : null;
        $vehicleTrimId = !empty($deal['vehicle_trim_id']) ? (int)$deal['vehicle_trim_id'] : null;

        foreach ($accessories as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $fitments = $fitmentMap[$id] ?? [];
            if (!accessory_matches_fitment($fitments, $vehicleMake, $vehicleModel, $vehicleYear, $vehicleMakeId, $vehicleModelId, $vehicleTrimId)) {
                continue;
            }
            $provider = trim((string)($row['provider'] ?? ''));
            $displayName = $row['name'] ?? ('Accessory ' . $id);
            if ($provider !== '') {
                $displayName .= ' (' . $provider . ')';
            }
            $accessoryCatalog[$id] = [
                'code' => $row['code'] ?? '',
                'name' => $displayName,
                'base_price' => isset($row['base_price']) ? (float)$row['base_price'] : null,
                'cost' => isset($row['cost']) ? (float)$row['cost'] : null,
                'residualizable' => !empty($row['residualizable']) ? 1 : 0,
                'residual_msrp_add' => isset($row['residual_msrp_add']) ? (float)$row['residual_msrp_add'] : 0,
                'contributes_to_luxury_tax' => 1,
            ];
        }
    } catch (PDOException $e) {
        error_log("Unable to load accessory catalog for edit: " . $e->getMessage());
    }
}

foreach ($dealAccessoryById as $id => $row) {
    if (!isset($accessoryCatalog[$id])) {
        $accessoryCatalog[$id] = $row;
    } else {
        if (!isset($accessoryCatalog[$id]['cost']) && isset($row['cost'])) {
            $accessoryCatalog[$id]['cost'] = (float)$row['cost'];
        }
    }
}

$colourStmt = $db->query("SELECT colour_name FROM vehicle_colours ORDER BY colour_name ASC");
$vehicleColours = $colourStmt->fetchAll(PDO::FETCH_COLUMN);
if (!empty($deal['vehicle_colour']) && !in_array($deal['vehicle_colour'], $vehicleColours, true)) {
    array_unshift($vehicleColours, $deal['vehicle_colour']);
}

$orgOptions = [];
if ($isAdmin) {
    $orgStmt = $db->query("SELECT id, name FROM organizations ORDER BY name ASC");
    $orgOptions = $orgStmt->fetchAll(PDO::FETCH_ASSOC);
}

$provinceOptions = [
    'MB' => 'Manitoba',
    'ON' => 'Ontario',
    'QC' => 'Quebec',
    'BC' => 'British Columbia',
    'AB' => 'Alberta',
    'SK' => 'Saskatchewan',
    'NS' => 'Nova Scotia',
    'NB' => 'New Brunswick',
    'PE' => 'Prince Edward Island',
    'NL' => 'Newfoundland and Labrador',
    'NT' => 'Northwest Territories',
    'YT' => 'Yukon',
    'NU' => 'Nunavut'
];
if (!empty($deal['province']) && !isset($provinceOptions[$deal['province']])) {
    $provinceOptions[$deal['province']] = $deal['province'];
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!dealerfai_csrf_validate($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        die('Invalid request.');
    }

    $asString = function($key) {
        if (!isset($_POST[$key])) {
            return null;
        }
        $val = trim((string)$_POST[$key]);
        return $val === '' ? null : $val;
    };
    $asNullableDecimal = function($key) {
        $val = isset($_POST[$key]) ? trim((string)$_POST[$key]) : '';
        return $val === '' ? null : (float)$val;
    };
    $asNullableInt = function($key) {
        $val = isset($_POST[$key]) ? trim((string)$_POST[$key]) : '';
        return $val === '' ? null : (int)$val;
    };

    $vehicleMakeId = $asNullableInt('vehicle_make_id');
    $vehicleMakeName = $vehicleMakeId && isset($vehicleMakeLookup[$vehicleMakeId]) ? $vehicleMakeLookup[$vehicleMakeId] : null;
    $vin = $asString('vin');
    if ($vin !== null) {
        $vin = strtoupper(preg_replace('/\\s+/', '', $vin));
        if ($vin === '') {
            $vin = null;
        }
    }
    $vehicleModel = $asString('vehicle_model');
    $vehicleYear = $asNullableInt('vehicle_year');
    $vehicleCondition = $asString('vehicle_condition') ?? 'Used';
    $vehicleKms = $asNullableInt('vehicle_kms');
    $inServiceDate = $asString('in_service_date');
    $vehicleColour = $asString('vehicle_colour');
    $salesAdvisorId = $asNullableInt('sales_advisor_id');
    $financeManagerId = $asNullableInt('finance_manager_id');
    $salesManagerId = $asNullableInt('sales_manager_id');
    $dealType = $asString('deal_type');
    $salePrice = $asNullableDecimal('sale_price');
    $documentationFee = $asNullableDecimal('documentation_fee');
    if ($documentationFee === null) {
        $documentationFee = 0;
    }
    $term = $asNullableInt('term');
    $interestRate = $asNullableDecimal('interest_rate');
    $msrp = $asNullableDecimal('msrp');
    $residualPercent = $asNullableDecimal('residual_percent');
    $residual = $asNullableDecimal('residual');
    if ($residual === null && $msrp !== null && $residualPercent !== null) {
        $residual = $msrp * ($residualPercent / 100);
    }
    $ppsaFee = $asNullableDecimal('ppsa_fee');
    if ($ppsaFee === null) {
        $ppsaFee = 0;
    }
    $downPayment = $asNullableDecimal('down_payment');
    $paymentFrequency = $asString('payment_frequency');
    $province = $asString('province');
    if ($province !== null) {
        $province = strtoupper($province);
    }
    $tradeInfo = $asString('trade_info');
    $tradeValue = $asNullableDecimal('trade_value');
    $lienAmount = $asNullableDecimal('lien_amount');
    $coAppRequired = !empty($_POST['co_app_required']) ? 1 : 0;
    $coAppName = trim((string)($_POST['co_app_name'] ?? ''));
    if (!$coAppRequired) {
        $coAppName = null;
    } elseif ($coAppName === '' && !$formError) {
        $formError = 'Co-signer name is required when Add Co-Signer is selected.';
    }
    $includeIncluded = !empty($_POST['include_included_protections']);
    $includedSelections = $_POST['included_protections'] ?? [];
    $includedPrices = $_POST['included_protection_prices'] ?? [];
    $includedSelections = is_array($includedSelections) ? $includedSelections : [];
    $includedPrices = is_array($includedPrices) ? $includedPrices : [];
    $includedProtections = [];
    if ($includeIncluded) {
        foreach ($includedSelections as $code) {
            $code = trim((string)$code);
            if ($code === '') {
                continue;
            }
            $priceRaw = $includedPrices[$code] ?? '';
            if ($priceRaw === '' || !is_numeric($priceRaw)) {
                $defaultPrice = $productCatalog[$code]['default_price'] ?? null;
                if ($defaultPrice !== null) {
                    $priceRaw = $defaultPrice;
                } else {
                    $formError = 'Please enter a sale price for each included protection.';
                    break;
                }
            }
            $includedProtections[] = [
                'code' => $code,
                'name' => $productCatalog[$code]['name'] ?? $code,
                'price' => (float)$priceRaw,
            ];
        }
    }
    $includedJson = !empty($includedProtections) ? json_encode($includedProtections) : null;
    $includeIncludedAccessories = !empty($_POST['include_included_accessories']);
    $includedAccessorySelections = $_POST['included_accessories'] ?? [];
    $includedAccessoryPrices = $_POST['included_accessory_prices'] ?? [];
    $includedAccessorySelections = is_array($includedAccessorySelections) ? $includedAccessorySelections : [];
    $includedAccessoryPrices = is_array($includedAccessoryPrices) ? $includedAccessoryPrices : [];
    $selectedAccessories = [];
    if ($includeIncludedAccessories) {
        foreach ($includedAccessorySelections as $id) {
            $id = (int)$id;
            if ($id <= 0 || !isset($accessoryCatalog[$id])) {
                continue;
            }
            $priceRaw = $includedAccessoryPrices[$id] ?? '';
            if ($priceRaw === '' || !is_numeric($priceRaw)) {
                $defaultPrice = $accessoryCatalog[$id]['base_price'] ?? null;
                if ($defaultPrice !== null) {
                    $priceRaw = $defaultPrice;
                } else {
                    $formError = 'Please enter a sale price for each included accessory.';
                    break;
                }
            }
            $existingAccessory = $dealAccessoryById[$id] ?? null;
            $defaultPayMethod = 'upfront';
            if ($dealType === 'Finance') {
                $defaultPayMethod = 'finance';
            } elseif ($dealType === 'Lease') {
                $defaultPayMethod = !empty($accessoryCatalog[$id]['residualizable']) ? 'cap_cost' : 'upfront';
            }
            $payMethod = strtolower((string)($existingAccessory['pay_method'] ?? $defaultPayMethod));
            if (!in_array($payMethod, ['upfront', 'finance', 'cap_cost'], true)) {
                $payMethod = $defaultPayMethod;
            }
            $selectedAccessories[] = [
                'id' => $id,
                'code' => $accessoryCatalog[$id]['code'] ?? '',
                'name' => $accessoryCatalog[$id]['name'] ?? ('Accessory ' . $id),
                'price' => (float)$priceRaw,
                'base_price' => isset($accessoryCatalog[$id]['base_price']) ? (float)$accessoryCatalog[$id]['base_price'] : (isset($existingAccessory['base_price']) ? (float)$existingAccessory['base_price'] : 0),
                'cost' => isset($existingAccessory['cost']) ? $existingAccessory['cost'] : ($accessoryCatalog[$id]['cost'] ?? null),
                'pay_method' => $payMethod,
                'included' => isset($existingAccessory['included']) ? (int)!empty($existingAccessory['included']) : 1,
                'residualizable' => isset($existingAccessory['residualizable']) ? (int)!empty($existingAccessory['residualizable']) : (int)($accessoryCatalog[$id]['residualizable'] ?? 0),
                'residual_msrp_add' => isset($existingAccessory['residual_msrp_add']) ? (float)$existingAccessory['residual_msrp_add'] : (float)($accessoryCatalog[$id]['residual_msrp_add'] ?? 0),
                'contributes_to_luxury_tax' => isset($existingAccessory['contributes_to_luxury_tax']) ? (int)!empty($existingAccessory['contributes_to_luxury_tax']) : (int)($accessoryCatalog[$id]['contributes_to_luxury_tax'] ?? 1),
            ];
        }
    }

    $organizationId = $deal['organization'];
    if ($isAdmin && isset($_POST['organization'])) {
        $organizationId = $asNullableInt('organization');
    }

    $customerType = normalize_customer_type($_POST['customer_type'] ?? ($deal['customer_type'] ?? 'personal'));
    $businessId = null;
    if ($customerType !== 'personal') {
        $candidate = (int)($_POST['business_id'] ?? 0);
        if ($candidate > 0) {
            $businessId = $candidate;
        } else {
            $bizName = trim((string)($_POST['business_name'] ?? ''));
            if ($bizName !== '' && $hasBusinessesTable && is_numeric($organizationId) && (int)$organizationId > 0) {
                try {
                    $ins = $db->prepare("INSERT INTO businesses (organization_id, name, created_by) VALUES (?, ?, ?)");
                    $ins->execute([(int)$organizationId, $bizName, (int)($_SESSION['user_id'] ?? 0)]);
                    $businessId = (int)$db->lastInsertId();
                } catch (PDOException $e) {
                    error_log("Failed creating business from edit_deal: " . $e->getMessage());
                    $businessId = null;
                }
            }
        }
    }

    if ($formError) {
        $includeIncluded = !empty($_POST['include_included_protections']);
        $includeIncludedAccessories = !empty($_POST['include_included_accessories']);
    }

    if (!$formError && in_array($dealType, ['Finance', 'Lease'], true)) {
        if (empty($term) || $term <= 0 || $interestRate === null) {
            $formError = 'Term and interest rate are required for finance or lease deals.';
        }
    }

    $update = $db->prepare("UPDATE deals SET
        deal_number = ?, customer_number = ?, customer_name = ?, customer_phone = ?, customer_email = ?,
        customer_type = ?, business_id = ?,
        vehicle_make_id = ?, vin = ?, vehicle_make = ?, vehicle_model = ?, vehicle_year = ?, vehicle_condition = ?, vehicle_kms = ?, in_service_date = ?, vehicle_colour = ?,
        sales_advisor_id = ?, finance_manager_id = ?, sales_manager_id = ?,
        deal_type = ?, sale_price = ?, term = ?, interest_rate = ?, msrp = ?, residual = ?, documentation_fee = ?, ppsa_fee = ?,
        down_payment = ?, payment_frequency = ?, province = ?,
        trade_info = ?, trade_value = ?, lien_amount = ?, co_app_required = ?, co_app_name = ?, included_protections = ?, organization = ?
        WHERE id = ?
    ");
    if (!$formError) {
        $beforeDeal = fetch_deal_row_for_audit($db, (int)$dealId);
        $update->execute([
        $asString('deal_number'),
        $asString('customer_number'),
        $asString('customer_name'),
        $asString('customer_phone'),
        $asString('customer_email'),
        $customerType,
        $businessId,
        $vehicleMakeId,
        $vin,
        $vehicleMakeName,
        $vehicleModel,
        $vehicleYear,
        $vehicleCondition,
        $vehicleKms,
        $inServiceDate,
        $vehicleColour,
        $salesAdvisorId,
        $financeManagerId,
        $salesManagerId,
        $dealType,
        $salePrice,
        $term,
        $interestRate,
        $msrp,
        $residual,
        $documentationFee,
        $ppsaFee,
        $downPayment,
        $paymentFrequency,
        $province,
        $tradeInfo,
        $tradeValue,
        $lienAmount,
        $coAppRequired,
        $coAppName,
        $includedJson,
        $organizationId,
        $dealId
        ]);
        $deleteDealAccessories = $db->prepare("DELETE FROM deal_accessories WHERE deal_id = ?");
        $deleteDealAccessories->execute([$dealId]);
        if (!empty($selectedAccessories)) {
            $insertAccessory = $db->prepare("
                INSERT INTO deal_accessories
                  (deal_id, accessory_id, accessory_code, accessory_name, base_price, sale_price, cost, pay_method, included,
                   residualizable, residual_msrp_add, contributes_to_luxury_tax)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            foreach ($selectedAccessories as $accessory) {
                $accId = (int)($accessory['id'] ?? 0);
                if ($accId <= 0) {
                    continue;
                }
                $insertAccessory->execute([
                    $dealId,
                    $accId,
                    $accessory['code'] ?? null,
                    $accessory['name'] ?? ('Accessory ' . $accId),
                    $accessory['base_price'] ?? 0,
                    $accessory['price'] ?? 0,
                    $accessory['cost'] ?? null,
                    $accessory['pay_method'] ?? 'upfront',
                    !empty($accessory['included']) ? 1 : 0,
                    !empty($accessory['residualizable']) ? 1 : 0,
                    $accessory['residual_msrp_add'] ?? 0,
                    !empty($accessory['contributes_to_luxury_tax']) ? 1 : 0,
                ]);
            }
        }
        $afterDeal = fetch_deal_row_for_audit($db, (int)$dealId);
        log_deal_change_audit($db, (int)$dealId, 'update', $beforeDeal, $afterDeal, ['context' => 'edit_deal']);

        header("Location: view_deal.php?id=$dealId");
        exit;
    }
}
$csrfToken = dealerfai_csrf_get_token();
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <?php include __DIR__ . "/includes/head_favicon.php"; ?>
<meta charset="UTF-8">
  <title>Edit Deal</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php dealerfai_theme_head($theme ?? null); ?>
  <style nonce="<?= dealerfai_csp_nonce() ?>">
    body { margin: 0; font-family: "Segoe UI", sans-serif; background: #f4f6f8; color: #111111; }
    header { background-color: <?= htmlspecialchars($theme['color']) ?>; color: white; padding: 30px 40px; text-align: center; position: relative; }
    header img {
      max-width: 480px;
      max-height: 180px;
      height: auto;
      display: block;
      margin: 0 auto 10px;
    }
    nav { background-color: <?= htmlspecialchars($theme['color']) ?>; padding: 12px; text-align: center; }
    nav a {
      color: white; margin: 0 20px; text-decoration: none; font-weight: bold;
    }
    nav a:hover { text-decoration: underline; }
    main { padding: 30px 40px; }
    .card {
      background: white; padding: 30px; max-width: 700px; margin: 30px auto;
      border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    }
    label { display: block; margin-top: 15px; font-weight: bold; }
    input, select {
      width: 100%; padding: 10px; margin-top: 5px;
      border: 1px solid #ccc; border-radius: 4px;
    }
    .btn {
      background: <?= htmlspecialchars($theme['color']) ?>;
      color: white; padding: 12px 20px; border: none;
      border-radius: 4px; margin-top: 20px; font-size: 16px; cursor: pointer;
    }
    .btn:hover { opacity: 0.9; }
    .logout { position: absolute; right: 20px; top: 20px; display: flex; gap: 12px; align-items: center; }
    .logout a { color: #ccc; font-size: 14px; text-decoration: none; }
    .logout a:hover { color: white; }
    .badge { display: inline-block; min-width: 18px; padding: 2px 8px; border-radius: 999px; background: #d7263d; color: #fff; font-size: 12px; font-weight: bold; text-align: center; margin-left: 6px; }
    .section { margin-top: 20px; padding-top: 10px; border-top: 1px solid #eee; }
    .error { background: #f8d7da; color: #721c24; padding: 10px 12px; border-radius: 6px; margin-bottom: 15px; }
    .included-row { display: grid; grid-template-columns: 20px 1fr 160px; gap: 12px; align-items: center; margin-top: 10px; }
    .included-row label { margin: 0; font-weight: 600; }
    .included-row input[type="checkbox"] { margin: 0; justify-self: center; }
    .included-price { width: 160px; }
    .included-hint { color: #555; font-size: 13px; margin: 8px 0 0; }
    .finance-only { display: none; }
    .lease-only { display: none; }
  </style>
</head>
<body>

<header>
  <?php if (!empty($theme['logo'])): ?>
    <img src="<?= htmlspecialchars($theme['logo']) ?>" alt="Dealer Logo">
  <?php else: ?>
    <h1>DealerFAI</h1>
  <?php endif; ?>
  <div class="logout">
    <?php if ($isAdmin): ?>
      <a href="admin_error_alerts.php">Alerts<?php if ($adminAlertCount > 0): ?> <span class="badge"><?= $adminAlertCount ?></span><?php endif; ?></a>
    <?php endif; ?>
    <a href="logout.php">Log Out</a>
  </div>
</header>

<nav>
  <a href="dashboard.php">Dashboard</a>
  <a href="view_deals.php">View Deals</a>
  <a href="create_deal.php">Create Deal</a>
  <?php if (in_array('General Manager', $userRoles) || in_array('Admin', $userRoles)): ?>
    <a href="admin_tools.php">Admin Tools</a>
  <?php endif; ?>
</nav>

<main>
  <form method="post">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <div class="card">
      <h2>Edit Deal</h2>
      <?php if (!empty($formError)): ?>
        <div class="error"><?= htmlspecialchars($formError) ?></div>
      <?php endif; ?>

      <?php if ($isAdmin && !empty($orgOptions)): ?>
        <label>Store (Admin Only)</label>
        <select name="organization">
          <option value="0">Global / Unassigned</option>
          <?php foreach ($orgOptions as $opt): ?>
            <option value="<?= $opt['id'] ?>" <?= ($opt['id'] == $deal['organization']) ? 'selected' : '' ?>>
              <?= htmlspecialchars($opt['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      <?php endif; ?>

      <label>Deal Number</label>
      <input type="text" name="deal_number" value="<?= htmlspecialchars($deal['deal_number'] ?? '') ?>" required>

      <label>Customer Number</label>
      <input type="text" name="customer_number" value="<?= htmlspecialchars($deal['customer_number'] ?? '') ?>">

      <label>Customer Name</label>
      <input type="text" name="customer_name" value="<?= htmlspecialchars($deal['customer_name'] ?? '') ?>" required>

      <label>Phone Number</label>
      <input type="text" name="customer_phone" value="<?= htmlspecialchars($deal['customer_phone'] ?? '') ?>">

      <label>Email</label>
      <input type="email" name="customer_email" value="<?= htmlspecialchars($deal['customer_email'] ?? '') ?>">

      <?php $selectedCustomerType = normalize_customer_type($deal['customer_type'] ?? 'personal'); ?>
      <label>Customer Type</label>
      <select name="customer_type" id="customer_type" onchange="toggleCustomerTypeUi()" required>
        <option value="personal" <?= $selectedCustomerType === 'personal' ? 'selected' : '' ?>>Personal</option>
        <option value="professional" <?= $selectedCustomerType === 'professional' ? 'selected' : '' ?>>Professional (Business Buyer)</option>
        <option value="commercial" <?= $selectedCustomerType === 'commercial' ? 'selected' : '' ?>>Commercial (Business)</option>
      </select>

      <div id="business_block" style="display:none; margin-top: 10px; padding: 12px; border: 1px solid #e5e7eb; border-radius: 8px; background: #f8fafc;">
        <div style="font-weight: 700; margin-bottom: 6px;">Business Buyer</div>

        <?php if ($hasBusinessesTable): ?>
          <label for="business_id">Select Existing Business (optional)</label>
          <select name="business_id" id="business_id" onchange="toggleBusinessNameRequired()">
            <option value="">-- New Business --</option>
            <?php foreach ($businesses as $biz): ?>
              <option value="<?= (int)$biz['id'] ?>" <?= (int)($deal['business_id'] ?? 0) === (int)$biz['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars((string)$biz['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        <?php endif; ?>

        <label for="business_name">Business Name</label>
        <input type="text" name="business_name" id="business_name" value="<?= htmlspecialchars($dealBusinessName) ?>">
      </div>

      <label>
        <input type="checkbox" id="co_app_required" name="co_app_required" value="1" <?= !empty($deal['co_app_required']) ? 'checked' : '' ?> onchange="toggleCoSignerUi()">
        Add Co-Signer
      </label>
      <div id="co_app_name_wrap" class="d-none">
        <label for="co_app_name">Co-Signer Name</label>
        <input type="text" id="co_app_name" name="co_app_name" value="<?= htmlspecialchars((string)($deal['co_app_name'] ?? '')) ?>">
      </div>

      <label>VIN (optional)</label>
      <div class="flex-gap-10">
        <input type="text" name="vin" id="vin" maxlength="32" value="<?= htmlspecialchars($deal['vin'] ?? '') ?>" style="flex:1;">
        <button type="button" class="btn" id="vin_decode_btn" class="mt-0">Decode VIN</button>
      </div>
      <div id="vin_decode_status" style="margin-top:6px; font-size:13px; color:#555;"></div>

      <label>Vehicle Make</label>
      <select name="vehicle_make_id" required>
        <option value="">-- Select Make --</option>
        <?php foreach ($vehicleMakes as $make): ?>
          <option value="<?= $make['id'] ?>" <?= ((int)($deal['vehicle_make_id'] ?? 0) === (int)$make['id']) ? 'selected' : '' ?>>
            <?= htmlspecialchars($make['make_name']) ?>
          </option>
        <?php endforeach; ?>
      </select>

      <label>Model</label>
      <input type="text" name="vehicle_model" value="<?= htmlspecialchars($deal['vehicle_model'] ?? '') ?>">

      <label>Year</label>
      <input type="number" name="vehicle_year" value="<?= htmlspecialchars($deal['vehicle_year'] ?? '') ?>">

      <label>Condition</label>
      <select name="vehicle_condition" required>
        <option value="New" <?= (($deal['vehicle_condition'] ?? 'Used') === 'New') ? 'selected' : '' ?>>New</option>
        <option value="Used" <?= (($deal['vehicle_condition'] ?? 'Used') === 'Used') ? 'selected' : '' ?>>Used</option>
      </select>

      <label>Kilometres</label>
      <input type="number" name="vehicle_kms" value="<?= htmlspecialchars($deal['vehicle_kms'] ?? '') ?>">

      <label>In-Service Date</label>
      <input type="date" name="in_service_date" value="<?= htmlspecialchars($deal['in_service_date'] ?? '') ?>">

      <label>Vehicle Colour</label>
      <select name="vehicle_colour" required>
        <option value="">-- Select Colour --</option>
        <?php foreach ($vehicleColours as $colour): ?>
          <option value="<?= htmlspecialchars($colour) ?>" <?= ($colour === ($deal['vehicle_colour'] ?? '')) ? 'selected' : '' ?>>
            <?= htmlspecialchars($colour) ?>
          </option>
        <?php endforeach; ?>
      </select>

      <label>Sales Advisor</label>
      <select name="sales_advisor_id">
        <option value="">-- None --</option>
        <?php foreach ($sales_advisors as $sa): ?>
          <option value="<?= $sa['id'] ?>" <?= ($deal['sales_advisor_id'] == $sa['id']) ? 'selected' : '' ?>>
            <?= htmlspecialchars($sa['full_name']) ?>
          </option>
        <?php endforeach; ?>
      </select>

      <label>Finance Manager</label>
      <select name="finance_manager_id">
        <option value="">-- None --</option>
        <?php foreach ($finance_managers as $fm): ?>
          <option value="<?= $fm['id'] ?>" <?= ($deal['finance_manager_id'] == $fm['id']) ? 'selected' : '' ?>>
            <?= htmlspecialchars($fm['full_name']) ?>
          </option>
        <?php endforeach; ?>
      </select>

      <label>Sales Manager</label>
      <select name="sales_manager_id">
        <option value="">-- None --</option>
        <?php foreach ($sales_managers as $sm): ?>
          <option value="<?= $sm['id'] ?>" <?= ($deal['sales_manager_id'] == $sm['id']) ? 'selected' : '' ?>>
            <?= htmlspecialchars($sm['full_name']) ?>
          </option>
        <?php endforeach; ?>
      </select>

      <label>Deal Type</label>
      <select name="deal_type" id="deal_type">
        <option value="Cash" <?= ($deal['deal_type'] == 'Cash') ? 'selected' : '' ?>>Cash</option>
        <option value="Finance" <?= ($deal['deal_type'] == 'Finance') ? 'selected' : '' ?>>Finance</option>
        <option value="Lease" <?= ($deal['deal_type'] == 'Lease') ? 'selected' : '' ?>>Lease</option>
      </select>

      <label>Sale Price</label>
      <input type="number" name="sale_price" step="0.01" value="<?= htmlspecialchars($deal['sale_price'] ?? '') ?>">

      <label>Documentation</label>
      <input type="number" name="documentation_fee" step="0.01" min="0" value="<?= htmlspecialchars($deal['documentation_fee'] ?? '0.00') ?>">

      <div class="section">
        <h3>Included Protections</h3>
        <label>
          <input type="checkbox" id="include_included_protections" name="include_included_protections" value="1"<?= $includeIncluded ? ' checked' : '' ?>>
          Add included protections
        </label>
        <div id="included-protections-panel" class="d-none">
          <p class="included-hint">Select protections already included in the deal and enter the sold price.</p>
          <?php if (empty($productCatalog)): ?>
            <p class="included-hint">No products available.</p>
          <?php else: ?>
            <?php foreach ($productCatalog as $code => $product): ?>
              <div class="included-row">
                <input type="checkbox" class="included-checkbox" name="included_protections[]" value="<?= htmlspecialchars($code) ?>"<?= in_array($code, $includedSelections, true) ? ' checked' : '' ?>>
                <label><?= htmlspecialchars($product['name']) ?></label>
                <input class="included-price" type="number" step="0.01" min="0"
                  name="included_protection_prices[<?= htmlspecialchars($code) ?>]"
                  value="<?= htmlspecialchars((string)($includedPrices[$code] ?? '')) ?>"
                  placeholder="<?= $product['default_price'] !== null ? htmlspecialchars(number_format($product['default_price'], 2)) : '' ?>">
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <div class="section">
        <h3>Accessories on Deal</h3>
        <label>
          <input type="checkbox" id="include_included_accessories" name="include_included_accessories" value="1"<?= $includeIncludedAccessories ? ' checked' : '' ?>>
          Edit accessories on this deal
        </label>
        <div id="included-accessories-panel" class="d-none">
          <p class="included-hint">Checked items stay on the deal. Uncheck to remove. Update sold prices as needed.</p>
          <?php if (empty($accessoryCatalog)): ?>
            <p class="included-hint">No accessories available.</p>
          <?php else: ?>
            <?php foreach ($accessoryCatalog as $id => $accessory): ?>
              <div class="included-row">
                <input type="checkbox" class="included-checkbox"
                  name="included_accessories[]"
                  value="<?= (int)$id ?>"
                  <?= in_array((string)$id, array_map('strval', $includedAccessorySelections), true) ? ' checked' : '' ?>>
                <label><?= htmlspecialchars($accessory['name']) ?></label>
                <input class="included-price" type="number" step="0.01" min="0"
                  name="included_accessory_prices[<?= (int)$id ?>]"
                  value="<?= htmlspecialchars((string)($includedAccessoryPrices[$id] ?? '')) ?>"
                  placeholder="<?= $accessory['base_price'] !== null ? htmlspecialchars(number_format($accessory['base_price'], 2)) : '' ?>">
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <div class="finance-only">
        <label>Term (Months)</label>
        <input type="number" name="term" id="term" min="1" value="<?= htmlspecialchars($deal['term'] ?? '') ?>">
      </div>

      <div class="finance-only">
        <label>Interest Rate (%)</label>
        <input type="number" name="interest_rate" id="interest_rate" step="0.01" min="0" value="<?= htmlspecialchars($deal['interest_rate'] ?? '') ?>">
      </div>

      <div class="lease-only">
        <label>MSRP (Lease Only)</label>
        <input type="number" name="msrp" id="msrp" step="0.01" min="0" value="<?= htmlspecialchars($deal['msrp'] ?? '') ?>">
      </div>

      <div class="finance-only">
        <label>PPSA (Finance/Lease only)</label>
        <input type="number" name="ppsa_fee" step="0.01" min="0" value="<?= htmlspecialchars($deal['ppsa_fee'] ?? '0.00') ?>">
      </div>

      <div class="lease-only">
        <label>Residual (if lease)</label>
        <input type="number" name="residual" step="0.01" value="<?= htmlspecialchars($deal['residual'] ?? '') ?>">
      </div>

      <?php
        $residualPercentValue = '';
        if (!empty($deal['msrp']) && !empty($deal['residual'])) {
          $residualPercentValue = number_format(((float)$deal['residual'] / (float)$deal['msrp']) * 100, 2, '.', '');
        }
      ?>
      <div class="lease-only">
        <label>Residual % (Lease Only)</label>
        <input type="number" name="residual_percent" id="residual_percent" step="0.01" min="0" max="100"
          value="<?= htmlspecialchars((string)$residualPercentValue) ?>">
      </div>

      <div class="finance-only">
        <label>Down Payment</label>
        <input type="number" name="down_payment" step="0.01" value="<?= htmlspecialchars($deal['down_payment'] ?? '') ?>">
      </div>

      <div class="finance-only">
        <label>Payment Frequency</label>
        <select name="payment_frequency">
          <option value="Monthly" <?= ($deal['payment_frequency'] == 'Monthly') ? 'selected' : '' ?>>Monthly</option>
          <option value="Semi-Monthly" <?= ($deal['payment_frequency'] == 'Semi-Monthly') ? 'selected' : '' ?>>Semi-Monthly</option>
          <option value="Bi-Weekly" <?= ($deal['payment_frequency'] == 'Bi-Weekly') ? 'selected' : '' ?>>Bi-Weekly</option>
          <option value="Weekly" <?= ($deal['payment_frequency'] == 'Weekly') ? 'selected' : '' ?>>Weekly</option>
        </select>
      </div>

      <label>Province</label>
      <select name="province" required>
        <option value="">-- Select Province --</option>
        <?php foreach ($provinceOptions as $code => $label): ?>
          <option value="<?= htmlspecialchars($code) ?>" <?= ($code === ($deal['province'] ?? '')) ? 'selected' : '' ?>>
            <?= htmlspecialchars($label) ?>
          </option>
        <?php endforeach; ?>
      </select>

      <label>Trade Info</label>
      <input type="text" name="trade_info" value="<?= htmlspecialchars($deal['trade_info'] ?? '') ?>">

      <label>Trade Value</label>
      <input type="number" name="trade_value" step="0.01" value="<?= htmlspecialchars($deal['trade_value'] ?? '') ?>">

      <label>Lien Amount</label>
      <input type="number" name="lien_amount" step="0.01" value="<?= htmlspecialchars($deal['lien_amount'] ?? '') ?>">

      <button type="submit" class="btn">Save Changes</button>
    </div>
  </form>
</main>

<script nonce="<?= dealerfai_csp_nonce() ?>">
  function toggleIncludedProtections() {
    const toggle = document.getElementById('include_included_protections');
    const panel = document.getElementById('included-protections-panel');
    if (!toggle || !panel) return;
    panel.style.display = toggle.checked ? 'block' : 'none';
  }
  function toggleIncludedAccessories() {
    const toggle = document.getElementById('include_included_accessories');
    const panel = document.getElementById('included-accessories-panel');
    if (!toggle || !panel) return;
    panel.style.display = toggle.checked ? 'block' : 'none';
  }
  function toggleFinanceRequired() {
    const dealType = document.getElementById('deal_type');
    const term = document.getElementById('term');
    const rate = document.getElementById('interest_rate');
    const msrp = document.getElementById('msrp');
    const residual = document.getElementById('residual');
    if (!dealType || !term || !rate) return;
    const requiresFinanceFields = dealType.value === 'Finance' || dealType.value === 'Lease';
    term.required = requiresFinanceFields;
    rate.required = requiresFinanceFields;
    if (msrp) msrp.required = dealType.value === 'Lease';
    if (residual) residual.required = dealType.value === 'Lease';
    document.querySelectorAll('.finance-only').forEach(el => {
      el.style.display = requiresFinanceFields ? 'block' : 'none';
    });
    document.querySelectorAll('.lease-only').forEach(el => {
      el.style.display = dealType.value === 'Lease' ? 'block' : 'none';
    });
  }
  function syncResidualFields(source) {
    const msrp = document.getElementById('msrp');
    const residual = document.getElementById('residual');
    const residualPercent = document.getElementById('residual_percent');
    if (!msrp || !residual || !residualPercent) return;
    const msrpVal = parseFloat(msrp.value);
    if (!Number.isFinite(msrpVal) || msrpVal <= 0) return;
    if (source === 'percent') {
      const percentVal = parseFloat(residualPercent.value);
      if (!Number.isFinite(percentVal)) return;
      residual.value = (msrpVal * (percentVal / 100)).toFixed(2);
    } else if (source === 'amount') {
      const residualVal = parseFloat(residual.value);
      if (!Number.isFinite(residualVal)) return;
      residualPercent.value = ((residualVal / msrpVal) * 100).toFixed(2);
    } else if (source === 'msrp') {
      if (residualPercent.value !== '') {
        syncResidualFields('percent');
      } else if (residual.value !== '') {
        syncResidualFields('amount');
      }
    }
  }
  function bindIncludedRows() {
    document.querySelectorAll('.included-row').forEach(row => {
      const checkbox = row.querySelector('.included-checkbox');
      const priceInput = row.querySelector('.included-price');
      if (!checkbox || !priceInput) return;
      const sync = () => {
        priceInput.disabled = !checkbox.checked;
        priceInput.required = checkbox.checked;
      };
      checkbox.addEventListener('change', sync);
      sync();
    });
  }
  window.addEventListener('DOMContentLoaded', () => {
    toggleIncludedProtections();
    toggleIncludedAccessories();
    toggleFinanceRequired();
    bindIncludedRows();
    const msrp = document.getElementById('msrp');
    const residual = document.getElementById('residual');
    const residualPercent = document.getElementById('residual_percent');
    if (msrp) msrp.addEventListener('input', () => syncResidualFields('msrp'));
    if (residual) residual.addEventListener('input', () => syncResidualFields('amount'));
    if (residualPercent) residualPercent.addEventListener('input', () => syncResidualFields('percent'));
    const toggle = document.getElementById('include_included_protections');
    if (toggle) {
      toggle.addEventListener('change', toggleIncludedProtections);
    }
    const accessoryToggle = document.getElementById('include_included_accessories');
    if (accessoryToggle) {
      accessoryToggle.addEventListener('change', toggleIncludedAccessories);
    }
    const dealType = document.getElementById('deal_type');
    if (dealType) {
      dealType.addEventListener('change', toggleFinanceRequired);
    }
  });

  const vinInput = document.getElementById('vin');
  const vinDecodeBtn = document.getElementById('vin_decode_btn');
  const vinStatus = document.getElementById('vin_decode_status');
  const makeSelect = document.querySelector('select[name="vehicle_make_id"]');
  const modelInput = document.querySelector('input[name="vehicle_model"]');
  const yearInput = document.querySelector('input[name="vehicle_year"]');

  function normalizeVin(value) {
    return (value || '').toUpperCase().replace(/\s+/g, '');
  }

  function setStatus(message, isError = false) {
    if (!vinStatus) return;
    vinStatus.textContent = message || '';
    vinStatus.style.color = isError ? '#b00020' : '#555';
  }

  function findSelectOptionByText(selectEl, text) {
    const target = (text || '').trim().toLowerCase();
    if (!selectEl || !target) return null;
    const options = Array.from(selectEl.options);
    return options.find(opt => opt.textContent.trim().toLowerCase() === target) || null;
  }

  async function decodeVinAndPopulate({ force = false } = {}) {
    if (!vinInput) return;
    const vin = normalizeVin(vinInput.value);
    vinInput.value = vin;
    if (!vin) {
      setStatus('');
      return;
    }
    if (!force && vin.length !== 17) {
      setStatus('Enter a full 17-character VIN to decode.', true);
      return;
    }
    setStatus('Decoding VIN...');
    try {
      const resp = await fetch(`https://vpic.nhtsa.dot.gov/api/vehicles/DecodeVinValuesExtended/${encodeURIComponent(vin)}?format=json`);
      const data = await resp.json();
      const result = Array.isArray(data.Results) ? data.Results[0] : null;
      if (!result) {
        setStatus('No VIN data returned.', true);
        return;
      }
      const decodedYear = (result.ModelYear || '').trim();
      const decodedMake = (result.Make || '').trim();
      const decodedModel = (result.Model || '').trim();

      if (decodedYear && yearInput) {
        yearInput.value = decodedYear;
      }
      if (decodedMake && makeSelect) {
        const makeOpt = findSelectOptionByText(makeSelect, decodedMake);
        if (makeOpt) {
          makeSelect.value = makeOpt.value;
        }
      }
      if (decodedModel && modelInput) {
        modelInput.value = decodedModel;
      }

      setStatus('VIN decoded. Please review the populated fields.');
    } catch (err) {
      setStatus('Unable to decode VIN right now.', true);
    }
  }

  if (vinDecodeBtn) {
    vinDecodeBtn.addEventListener('click', () => decodeVinAndPopulate({ force: true }));
  }
  if (vinInput) {
    vinInput.addEventListener('blur', () => decodeVinAndPopulate({ force: false }));
  }

  function toggleCustomerTypeUi() {
    const type = document.getElementById('customer_type')?.value || 'personal';
    const block = document.getElementById('business_block');
    if (block) {
      block.style.display = (type === 'personal') ? 'none' : 'block';
    }
    toggleBusinessNameRequired();
  }

  function toggleBusinessNameRequired() {
    const type = document.getElementById('customer_type')?.value || 'personal';
    const bizName = document.getElementById('business_name');
    const bizId = document.getElementById('business_id');
    if (!bizName) return;
    const hasExisting = bizId && bizId.value;
    bizName.required = (type !== 'personal' && !hasExisting);
  }

  function toggleCoSignerUi() {
    const requiredCheckbox = document.getElementById('co_app_required');
    const wrap = document.getElementById('co_app_name_wrap');
    const nameInput = document.getElementById('co_app_name');
    const enabled = !!(requiredCheckbox && requiredCheckbox.checked);
    if (wrap) {
      wrap.style.display = enabled ? 'block' : 'none';
    }
    if (nameInput) {
      nameInput.required = enabled;
      if (!enabled) {
        nameInput.value = '';
      }
    }
  }

  document.addEventListener('DOMContentLoaded', function () {
    toggleCustomerTypeUi();
    toggleCoSignerUi();
  });
</script>

</body>
</html>
