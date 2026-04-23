<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/session_bootstrap.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../protection_helpers.php';
require_once __DIR__ . '/../helpers/db_utils.php';
require_once __DIR__ . '/../helpers/deal_access.php';
require_once __DIR__ . '/../helpers/insurance_quote.php';

function json_out(array $payload): void
{
    echo json_encode($payload);
    exit;
}

$raw = file_get_contents('php://input');
$input = json_decode($raw ?: '[]', true);
if (!is_array($input)) {
    json_out(['ok' => false, 'error' => 'invalid_json']);
}

$dealId = (int)($input['deal_id'] ?? 0);
$productId = (int)($input['product_id'] ?? 0);
$coverageTermMonths = isset($input['coverage_term_months']) ? (int)$input['coverage_term_months'] : null;
$selectedLabel = isset($input['selected_label']) ? (string)$input['selected_label'] : null;
$selectedValue = isset($input['selected_value']) ? (string)$input['selected_value'] : null;

if ($dealId <= 0) {
    json_out(['ok' => false, 'error' => 'missing_deal_id']);
}
if ($productId <= 0) {
    json_out(['ok' => false, 'error' => 'missing_product_id']);
}

$stmt = $db->prepare("SELECT * FROM deals WHERE id = ?");
$stmt->execute([$dealId]);
$deal = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$deal) {
    json_out(['ok' => false, 'error' => 'deal_not_found']);
}
if (!dealerfai_session_can_access_deal($deal)) {
    json_out(['ok' => false, 'error' => 'access_denied']);
}

// Load the exact product variant the user selected (important for warranty option sets).
$pStmt = $db->prepare("
    SELECT id, code, name, category, provider, requires_quote, default_term
    FROM products
    WHERE id = ?
    LIMIT 1
");
$pStmt->execute([$productId]);
$product = $pStmt->fetch(PDO::FETCH_ASSOC);
if (!$product) {
    json_out(['ok' => false, 'error' => 'product_not_found']);
}

// Included protections total (affects amount financed for insurance quotes).
$includedTotal = 0.0;
if (function_exists('parse_included_protections') && function_exists('summarize_included_protections')) {
    $items = parse_included_protections($deal['included_protections'] ?? null);
    $summary = summarize_included_protections($items);
    $includedTotal = (float)($summary['total'] ?? 0.0);
}

// Mirror recommendations.php accessory behavior: include only accessories that roll into the deal.
$dealTypeRaw = (string)($deal['deal_type'] ?? '');
$isCashDeal = strcasecmp($dealTypeRaw, 'Cash') === 0;
$isLeaseDeal = strcasecmp($dealTypeRaw, 'Lease') === 0;
$isFinanceDeal = strcasecmp($dealTypeRaw, 'Finance') === 0;
$accessoryTotalRolledIn = 0.0;
try {
    $accStmt = $db->prepare("
        SELECT pay_method, COALESCE(SUM(sale_price), 0) AS total
        FROM deal_accessories
        WHERE deal_id = ?
        GROUP BY pay_method
    ");
    $accStmt->execute([$dealId]);
    $byMethod = ['upfront' => 0.0, 'finance' => 0.0, 'cap_cost' => 0.0];
    while ($row = $accStmt->fetch(PDO::FETCH_ASSOC)) {
        $method = strtolower(trim((string)($row['pay_method'] ?? '')));
        if ($method === '' || !array_key_exists($method, $byMethod)) {
            continue;
        }
        $byMethod[$method] = (float)($row['total'] ?? 0.0);
    }
    $totalAll = array_sum($byMethod);
    $accessoryTotalRolledIn = $isCashDeal
        ? $totalAll
        : ($isLeaseDeal ? (float)$byMethod['cap_cost'] : ($isFinanceDeal ? (float)$byMethod['finance'] : 0.0));
} catch (PDOException $e) {
    $accessoryTotalRolledIn = 0.0;
}

// Allow the frontend to pass an explicit coverage term (common for term-based warranties).
if ($coverageTermMonths !== null && $coverageTermMonths > 0) {
    $product['coverage_term'] = $coverageTermMonths;
}

// Helpful for debugging/partner payloads.
if ($selectedLabel !== null) {
    $product['selected_label'] = $selectedLabel;
}
if ($selectedValue !== null) {
    $product['selected_value'] = $selectedValue;
}

try {
    $quote = insurance_quote_try_quote($db, $deal, $product, $includedTotal, $accessoryTotalRolledIn);
} catch (Throwable $e) {
    json_out(['ok' => false, 'error' => 'quote_exception']);
}

if (empty($quote['ok'])) {
    json_out([
        'ok' => false,
        'error' => $quote['error'] ?? 'quote_failed',
        'missing' => $quote['missing'] ?? null,
    ]);
}

json_out([
    'ok' => true,
    'deal_id' => $dealId,
    'product_id' => $productId,
    'product_code' => (string)($product['code'] ?? ''),
    'premium' => (float)($quote['premium'] ?? 0.0),
    'currency' => (string)($quote['currency'] ?? 'CAD'),
]);
