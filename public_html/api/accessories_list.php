<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
include_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../accessory_helpers.php';
require_once __DIR__ . '/../scoring_engine.php';
require_once __DIR__ . '/../helpers/ai_worker_kick.php';
require_once __DIR__ . '/../helpers/deal_access.php';

header('Content-Type: application/json');

$dealId = isset($_GET['deal_id']) ? (int)$_GET['deal_id'] : (int)($_POST['deal_id'] ?? 0);
if ($dealId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing deal_id.']);
    exit;
}

$dealStmt = $db->prepare("SELECT * FROM deals WHERE id = ?");
$dealStmt->execute([$dealId]);
$deal = $dealStmt->fetch(PDO::FETCH_ASSOC);
if (!$deal) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Deal not found.']);
    exit;
}
if (!dealerfai_session_can_access_deal($deal)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied.']);
    exit;
}

try {
    dealerfai_kick_ai_worker($db, 20);
} catch (Throwable $e) {
    // Best-effort only; never block accessory browsing.
}

$appStmt = $db->prepare("SELECT * FROM applications WHERE deal_id = ?");
$appStmt->execute([$dealId]);
$application = $appStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$customerContextRaw = '';
$contextHints = [];
try {
    if (function_exists('column_exists') && column_exists($db, 'deals', 'customer_context')) {
        $customerContextRaw = (string)($deal['customer_context'] ?? '');
    }
} catch (Throwable $e) {
    $customerContextRaw = '';
}
if ($customerContextRaw !== '') {
    $contextHints = extract_customer_context_hints($customerContextRaw);
}

$organizationId = !empty($deal['organization']) ? (int)$deal['organization'] : 0;
$accessoriesEnabled = is_accessories_enabled($db, $organizationId);
if (!$accessoriesEnabled) {
    echo json_encode([
        'success' => true,
        'accessories' => [],
        'included_accessories' => [],
        'selected_accessories' => [],
    ]);
    exit;
}
$accessories = fetch_accessories_for_org($db, $organizationId);

$dealAccessories = [];
$includedNames = [];
$selectedNames = [];
$accStmt = $db->prepare("SELECT * FROM deal_accessories WHERE deal_id = ?");
$accStmt->execute([$dealId]);
while ($row = $accStmt->fetch(PDO::FETCH_ASSOC)) {
    $dealAccessories[(int)($row['accessory_id'] ?? 0)] = $row;
    if (!empty($row['included'])) {
        $includedNames[] = $row['accessory_name'] ?? '';
    } else {
        $selectedNames[] = $row['accessory_name'] ?? '';
    }
}

$fitmentMap = [];
if (!empty($accessories)) {
    $ids = array_column($accessories, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $fitStmt = $db->prepare("SELECT * FROM accessory_fitment WHERE accessory_id IN ($placeholders)");
    $fitStmt->execute($ids);
    while ($row = $fitStmt->fetch(PDO::FETCH_ASSOC)) {
        $fitmentMap[$row['accessory_id']][] = $row;
    }
}

$vehicleMake = '';
$vehicleMakeId = !empty($deal['vehicle_make_id']) ? (int)$deal['vehicle_make_id'] : null;
$vehicleModelId = !empty($deal['vehicle_model_id']) ? (int)$deal['vehicle_model_id'] : null;
$vehicleTrimId = !empty($deal['vehicle_trim_id']) ? (int)$deal['vehicle_trim_id'] : null;
if (!empty($vehicleMakeId)) {
    $makeStmt = $db->prepare("SELECT make_name FROM vehicle_makes WHERE id = ?");
    $makeStmt->execute([$vehicleMakeId]);
    $vehicleMake = (string)$makeStmt->fetchColumn();
}
if ($vehicleMake === '') {
    $vehicleMake = (string)($deal['vehicle_make'] ?? ($application['vehicle_make'] ?? ''));
}
$vehicleModel = (string)($deal['vehicle_model'] ?? ($application['vehicle_model'] ?? ''));
$vehicleYear = !empty($deal['vehicle_year']) ? (int)$deal['vehicle_year'] : null;

$includedTotal = 0.0;
if (function_exists('parse_included_protections') && function_exists('summarize_included_protections')) {
    $includedItems = parse_included_protections($deal['included_protections'] ?? null);
    $includedSummary = summarize_included_protections($includedItems);
    $includedTotal = (float)($includedSummary['total'] ?? 0.0);
}
$actionRules = load_action_rules($db, $organizationId);
$actionSignals = !empty($actionRules)
    ? build_action_rule_signal_context($db, $deal, $application, $includedTotal)
    : [];
$dealType = (string)($deal['deal_type'] ?? '');
$term = !empty($deal['term']) ? (int)$deal['term'] : null;
$rate = isset($deal['interest_rate']) ? (float)$deal['interest_rate'] : null;

$results = [];
foreach ($accessories as $accessory) {
    $accessoryId = (int)($accessory['id'] ?? 0);
    if ($accessoryId <= 0) {
        continue;
    }
    $fitments = $fitmentMap[$accessoryId] ?? [];
    if (!accessory_matches_fitment($fitments, $vehicleMake, $vehicleModel, $vehicleYear, $vehicleMakeId, $vehicleModelId, $vehicleTrimId)) {
        continue;
    }
    $code = (string)($accessory['code'] ?? '');
    $actionRuleResult = !empty($actionRules) && !empty($actionSignals)
        ? apply_action_rules_to_target($actionRules, $actionSignals, 'accessory', $code)
        : ['score_delta' => 0, 'excluded' => false];
    $score = (int)($actionRuleResult['score_delta'] ?? 0);
    $excluded = !empty($actionRuleResult['excluded']);

    $selectedRow = $dealAccessories[$accessoryId] ?? null;
    $isIncluded = !empty($selectedRow['included']);
    $isSelected = $selectedRow !== null;
    $payMethod = $selectedRow['pay_method'] ?? null;

    // Included accessories are already part of the deal and should not be shown again for selection.
    if ($isIncluded) {
        continue;
    }

    $basePrice = isset($accessory['base_price']) ? (float)$accessory['base_price'] : 0.0;
    $paymentValue = calculate_accessory_payment($dealType, $basePrice, $term, $rate);
    if (strcasecmp($dealType, 'Cash') !== 0 && ($payMethod === 'upfront')) {
        $paymentValue = 0.0;
    }

    $recommended = !$excluded && $score > 0;
    $personalizedReason = '';

    $results[] = [
        'id' => $accessoryId,
        'code' => $code,
        'name' => $accessory['name'] ?? $code,
        'description' => $accessory['description'] ?? '',
        'personalized_reason' => $personalizedReason,
        'reason_pending' => true,
        'category' => $accessory['category'] ?? '',
        'photo_url' => $accessory['photo_url'] ?? '',
        'base_price' => $basePrice,
        'payment_value' => $paymentValue,
        'residualizable' => !empty($accessory['residualizable']),
        'residual_msrp_add' => isset($accessory['residual_msrp_add']) ? (float)$accessory['residual_msrp_add'] : 0.0,
        'contributes_to_luxury_tax' => true,
        'score' => $score,
        'recommended' => $recommended,
        'selected' => $isSelected,
        'included' => $isIncluded,
        'pay_method' => $payMethod,
    ];
}

// Keep ordering deterministic without relying on manual sort_order in admin.
// Priority: included/selected first (so they stay visible), then recommended/high score.
usort($results, static function (array $a, array $b): int {
    $aIncluded = !empty($a['included']) ? 1 : 0;
    $bIncluded = !empty($b['included']) ? 1 : 0;
    if ($aIncluded !== $bIncluded) {
        return $bIncluded <=> $aIncluded;
    }
    $aSelected = !empty($a['selected']) ? 1 : 0;
    $bSelected = !empty($b['selected']) ? 1 : 0;
    if ($aSelected !== $bSelected) {
        return $bSelected <=> $aSelected;
    }
    $aRec = !empty($a['recommended']) ? 1 : 0;
    $bRec = !empty($b['recommended']) ? 1 : 0;
    if ($aRec !== $bRec) {
        return $bRec <=> $aRec;
    }
    $aScore = (int)($a['score'] ?? 0);
    $bScore = (int)($b['score'] ?? 0);
    if ($aScore !== $bScore) {
        return $bScore <=> $aScore;
    }
    $aName = (string)($a['name'] ?? '');
    $bName = (string)($b['name'] ?? '');
    return strcasecmp($aName, $bName);
});

echo json_encode([
    'success' => true,
    'accessories' => $results,
    'included_accessories' => array_values(array_unique(array_filter($includedNames))),
    'selected_accessories' => array_values(array_unique(array_filter($selectedNames))),
]);
