<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
require_once __DIR__ . '/../includes/csrf.php';
include_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../accessory_helpers.php';
require_once __DIR__ . '/../protection_helpers.php';
require_once __DIR__ . '/../helpers/organization_question_config.php';
require_once __DIR__ . '/../helpers/deal_access.php';

header('Content-Type: application/json');

$rawBody = file_get_contents('php://input');
if ($rawBody) {
    $jsonData = json_decode($rawBody, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($jsonData)) {
        $_POST = array_merge($_POST, $jsonData);
    }
}

$dealId = (int)($_POST['deal_id'] ?? 0);
$accessoryId = (int)($_POST['accessory_id'] ?? 0);
$selected = filter_var($_POST['selected'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
$selected = $selected === null ? true : $selected;
$payMethod = $_POST['pay_method'] ?? null;
$included = !empty($_POST['included']) ? 1 : 0;

if ($dealId <= 0 || $accessoryId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing deal_id or accessory_id.']);
    exit;
}
$csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
$csrfBody = $_POST['csrf_token'] ?? null;
if (!dealerfai_csrf_validate(is_string($csrfHeader) && $csrfHeader !== '' ? $csrfHeader : (is_string($csrfBody) ? $csrfBody : null))) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid request.']);
    exit;
}

$dealStmt = $db->prepare("
    SELECT deal_type, term, interest_rate, sale_price, documentation_fee, ppsa_fee, down_payment, trade_value,
           lien_amount, msrp, organization, included_protections
    FROM deals
    WHERE id = ?
");
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

$accStmt = $db->prepare("SELECT * FROM accessories WHERE id = ? AND active = 1");
$accStmt->execute([$accessoryId]);
$accessory = $accStmt->fetch(PDO::FETCH_ASSOC);
if (!$accessory) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Accessory not found.']);
    exit;
}

$provider = trim((string)($accessory['provider'] ?? ''));
$displayName = (string)($accessory['name'] ?? '');
if ($provider !== '' && $displayName !== '') {
    $displayName .= ' (' . $provider . ')';
}

$dealType = (string)($deal['deal_type'] ?? '');
$allowedPayMethods = ['upfront', 'finance', 'cap_cost'];
if (!in_array($payMethod, $allowedPayMethods, true)) {
    if (strcasecmp($dealType, 'Cash') === 0) {
        $payMethod = 'upfront';
    } elseif (strcasecmp($dealType, 'Lease') === 0) {
        $payMethod = !empty($accessory['residualizable']) ? 'cap_cost' : 'upfront';
    } else {
        $payMethod = 'finance';
    }
}

$leaseCapEnabled = false;
$leaseCapLimit = 0.0;
$isLeaseDeal = strcasecmp($dealType, 'Lease') === 0;
$isFinanceDeal = strcasecmp($dealType, 'Finance') === 0;
if ($isLeaseDeal || $isFinanceDeal) {
    $orgId = (int)($deal['organization'] ?? 0);
    if ($orgId > 0) {
        $hasLeaseCapPercentColumn = organization_column_exists($db, 'lease_msrp_cap_percent');
        $hasFinanceCapPercentColumn = organization_column_exists($db, 'finance_msrp_cap_percent');
        $selectFields = [];
        if ($hasLeaseCapPercentColumn) {
            $selectFields[] = 'lease_msrp_cap_percent';
        }
        if ($hasFinanceCapPercentColumn) {
            $selectFields[] = 'finance_msrp_cap_percent';
        }
        if (!empty($selectFields)) {
            $orgStmt = $db->prepare("SELECT " . implode(', ', $selectFields) . " FROM organizations WHERE id = ?");
            $orgStmt->execute([$orgId]);
            $orgRow = $orgStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $capPercent = $isLeaseDeal ? ($orgRow['lease_msrp_cap_percent'] ?? null) : ($orgRow['finance_msrp_cap_percent'] ?? null);
            $capPercent = ($capPercent !== null && $capPercent !== '' && is_numeric($capPercent)) ? (float)$capPercent : null;
            $msrpValue = (float)($deal['msrp'] ?? 0);
            if ($capPercent !== null && $capPercent > 0 && $msrpValue > 0) {
                $leaseCapEnabled = true;
                $leaseCapLimit = $msrpValue * ($capPercent / 100);
            }
        }
    }
}

// Enforce MSRP cap: if an accessory would push the deal over the cap, move it to upfront.
// (Cap exceptions are handled on the protections side via explicit "cap exempt" product flags.)
if ($leaseCapEnabled && $selected && (($isLeaseDeal && $payMethod === 'cap_cost') || ($isFinanceDeal && $payMethod === 'finance'))) {
    $includedProtections = function_exists('parse_included_protections')
        ? parse_included_protections($deal['included_protections'] ?? null)
        : [];
    $includedSummary = function_exists('summarize_included_protections')
        ? summarize_included_protections($includedProtections)
        : ['total' => 0];
    $includedTotal = (float)($includedSummary['total'] ?? 0);
    $baseCapCost = (float)($deal['sale_price'] ?? 0)
        + (float)($deal['documentation_fee'] ?? 0)
        + $includedTotal
        + (float)($deal['ppsa_fee'] ?? 0)
        - (float)($deal['down_payment'] ?? 0)
        - (float)($deal['trade_value'] ?? 0)
        + (float)($deal['lien_amount'] ?? 0);
    $baseCapCost = max(0, $baseCapCost);
    $capStmt = $db->prepare("
        SELECT COALESCE(SUM(sale_price), 0)
        FROM deal_accessories
        WHERE deal_id = ? AND pay_method = ? AND accessory_id <> ?
    ");
    $capMethod = $isLeaseDeal ? 'cap_cost' : 'finance';
    $capStmt->execute([$dealId, $capMethod, $accessoryId]);
    $capAccessoryTotal = (float)$capStmt->fetchColumn();
    $candidateCapCost = $baseCapCost + $capAccessoryTotal + (float)($accessory['base_price'] ?? 0);
    if ($candidateCapCost > $leaseCapLimit) {
        $payMethod = 'upfront';
    }
}

if ($selected) {
    $stmt = $db->prepare("INSERT INTO deal_accessories
        (deal_id, accessory_id, accessory_code, accessory_name, base_price, sale_price, cost, pay_method, included,
         residualizable, residual_msrp_add, contributes_to_luxury_tax)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
          sale_price = VALUES(sale_price),
          cost = VALUES(cost),
          pay_method = VALUES(pay_method),
          included = VALUES(included),
          residualizable = VALUES(residualizable),
          residual_msrp_add = VALUES(residual_msrp_add),
          contributes_to_luxury_tax = VALUES(contributes_to_luxury_tax)
    ");
    $stmt->execute([
        $dealId,
        $accessoryId,
        $accessory['code'] ?? null,
        $displayName,
        $accessory['base_price'] ?? null,
        $accessory['base_price'] ?? 0,
        $accessory['cost'] ?? null,
        $payMethod,
        $included,
        !empty($accessory['residualizable']) ? 1 : 0,
        $accessory['residual_msrp_add'] ?? 0,
        1,
    ]);
    echo json_encode(['success' => true, 'selected' => true, 'pay_method' => $payMethod]);
    exit;
}

$deleteStmt = $db->prepare("DELETE FROM deal_accessories WHERE deal_id = ? AND accessory_id = ? AND included = ?");
$deleteStmt->execute([$dealId, $accessoryId, $included]);

echo json_encode(['success' => true, 'selected' => false, 'pay_method' => $payMethod]);
