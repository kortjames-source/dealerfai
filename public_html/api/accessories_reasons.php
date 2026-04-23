<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
include_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../accessory_helpers.php';
require_once __DIR__ . '/../scoring_engine.php';
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

$appStmt = $db->prepare("SELECT * FROM applications WHERE deal_id = ?");
$appStmt->execute([$dealId]);
$application = $appStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$selectedAccessoryIds = [];
$includedAccessoryIds = [];
try {
    $selectedStmt = $db->prepare("SELECT accessory_id, included FROM deal_accessories WHERE deal_id = ?");
    $selectedStmt->execute([$dealId]);
    while ($selectedRow = $selectedStmt->fetch(PDO::FETCH_ASSOC)) {
        $selectedId = (int)($selectedRow['accessory_id'] ?? 0);
        if ($selectedId <= 0) {
            continue;
        }
        if (!empty($selectedRow['included'])) {
            $includedAccessoryIds[$selectedId] = true;
            continue;
        }
        $selectedAccessoryIds[$selectedId] = true;
    }
} catch (Throwable $e) {
    $selectedAccessoryIds = [];
    $includedAccessoryIds = [];
}

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
if (!is_accessories_enabled($db, $organizationId)) {
    echo json_encode(['success' => true, 'reasons' => []]);
    exit;
}

$accessories = fetch_accessories_for_org($db, $organizationId);
if (empty($accessories)) {
    echo json_encode(['success' => true, 'reasons' => []]);
    exit;
}

$fitmentMap = [];
$ids = array_column($accessories, 'id');
if (!empty($ids)) {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $fitStmt = $db->prepare("SELECT * FROM accessory_fitment WHERE accessory_id IN ($placeholders)");
    $fitStmt->execute($ids);
    while ($row = $fitStmt->fetch(PDO::FETCH_ASSOC)) {
        $fitmentMap[(int)$row['accessory_id']][] = $row;
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
$profileSummary = function_exists('dealerfai_build_customer_profile_summary') ? (string)dealerfai_build_customer_profile_summary($application) : '';
$reasons = [];

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
        : ['score_delta' => 0, 'excluded' => false, 'matched' => [], 'hints' => []];
    $isSelectedAccessory = !empty($selectedAccessoryIds[$accessoryId]);
    $isIncludedAccessory = !empty($includedAccessoryIds[$accessoryId]);
    if ($isIncludedAccessory) {
        continue;
    }
    $matchedTags = array_values(array_filter(array_map(
        static fn($v) => trim((string)$v),
        (array)($actionRuleResult['matched'] ?? [])
    ), static fn($v) => $v !== ''));
    $matchedDetails = array_values(array_filter(array_map(
        static fn($v) => trim((string)$v),
        (array)($actionRuleResult['hints'] ?? [])
    ), static fn($v) => $v !== ''));

    $filteredInputs = filter_accessory_reason_inputs($accessory, $matchedDetails, $matchedTags, $contextHints);
    $filteredDetails = $filteredInputs['matched_details'] ?? [];
    $filteredTags = $filteredInputs['matched_tags'] ?? [];
    $filteredHints = $filteredInputs['context_hints'] ?? [];

    $fallbackReason = build_accessory_personalized_reason($accessory, $filteredDetails, $filteredTags, $filteredHints, $profileSummary);
    $reason = $fallbackReason;
    // Generate the same style of writeup for every visible accessory card,
    // not just recommended or already-selected accessories.
    $shouldCallAi = true;

    if ($shouldCallAi && function_exists('generate_ai_explanation')) {
        $description = trim((string)($accessory['description'] ?? ''));
        $category = trim((string)($accessory['category'] ?? ''));
        $price = isset($accessory['base_price']) ? (float)$accessory['base_price'] : 0.0;
        $facts = [];
        if ($description !== '') {
            $facts[] = $description;
        }
        if ($category !== '') {
            $facts[] = "Category: {$category}.";
        }
        if ($price > 0) {
            $facts[] = "Base accessory price: $" . number_format($price, 2) . " before taxes and fees.";
        }

        $productPayload = [
            'code' => 'accessory_' . ($code !== '' ? $code : (string)$accessoryId),
            'name' => (string)($accessory['name'] ?? 'Accessory'),
            'default_description' => $description,
            'default_reason' => $fallbackReason,
            'approved_facts_default' => implode("\n", $facts),
            'approved_facts_custom' => '',
        ];
        $dealInfo = [
            'deal_id' => $dealId,
            'profile_summary' => $profileSummary,
            'matched_details' => $filteredDetails,
            'rule_hints' => $filteredTags,
            'customer_context_raw' => $customerContextRaw,
        ];

        $aiReason = trim((string)generate_ai_explanation($productPayload, $dealInfo));
        if ($aiReason !== '') {
            $reason = $aiReason;
        }
    }

    $reasons[(string)$accessoryId] = $reason !== '' ? $reason : '';
}

echo json_encode([
    'success' => true,
    'reasons' => $reasons,
]);
