<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/session_bootstrap.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../scoring_engine.php';
require_once __DIR__ . '/../protection_helpers.php';
require_once __DIR__ . '/../helpers/db_utils.php';
require_once __DIR__ . '/../helpers/deal_access.php';

function json_out(array $payload): void
{
    echo json_encode($payload);
    exit;
}

function resolve_scope_candidates(PDO $db, ?int $organizationId): array
{
    $orgKind = null;
    $parentId = null;
    if ($organizationId) {
        try {
            $orgStmt = $db->prepare("SELECT org_kind, parent_org_id FROM organizations WHERE id = ?");
            $orgStmt->execute([$organizationId]);
            $orgRow = $orgStmt->fetch(PDO::FETCH_ASSOC);
            if ($orgRow) {
                $orgKind = $orgRow['org_kind'] ?? null;
                $parentId = $orgRow['parent_org_id'] ?? null;
            }
        } catch (PDOException $e) {
            $orgKind = null;
        }
    }

    $storeId = ($organizationId && ($orgKind ?? 'store') !== 'group') ? (string)$organizationId : '';
    $orgScopeId = $organizationId ? (string)$organizationId : '';
    $groupId = ($orgKind === 'store' && $parentId) ? (string)$parentId : '';

    $candidates = [];
    if ($storeId !== '') $candidates[] = ['store', $storeId];
    if ($orgScopeId !== '') $candidates[] = ['org', $orgScopeId];
    if ($groupId !== '') $candidates[] = ['org', $groupId];
    $candidates[] = ['global', ''];
    return $candidates;
}

function build_term_label(int $months): string
{
    if ($months <= 0) return '';
    if ($months % 12 === 0) {
        $years = (int)($months / 12);
        return $years === 1 ? '1 Year' : ($years . ' Years');
    }
    return $months . ' Months';
}

function calculate_lease_payment(float $cap_cost, float $residual_value, int $term, float $interest_rate): float
{
    if ($cap_cost <= 0 || $term <= 0) return 0.0;
    $money_factor = ($interest_rate / 100) / 24;
    $depreciation = ($cap_cost - $residual_value) / $term;
    $finance_charge = ($cap_cost + $residual_value) * $money_factor;
    return max(0.0, $depreciation + $finance_charge);
}

function calculate_payment(string $deal_type, float $price, int $term, float $rate): ?float
{
    if (strcasecmp($deal_type, 'Cash') === 0) return null;
    if (strcasecmp($deal_type, 'Lease') === 0) {
        // Match recommendations.php: treat add-ons like $0 residual for payment impact.
        return calculate_lease_payment($price, 0.0, $term, $rate);
    }
    $i = ($rate / 100) / 12;
    if ($term <= 0) return 0.0;
    return $i > 0
        ? (($price * $i) / (1 - pow(1 + $i, -$term)))
        : ($price / $term);
}

function calculate_display_amount(string $dealType, float $price, int $term, float $rate): float
{
    if (strcasecmp($dealType, 'Cash') === 0) return max(0.0, $price);
    $payment = calculate_payment($dealType, $price, $term, $rate);
    return max(0.0, (float)($payment ?? 0.0));
}

$raw = file_get_contents('php://input');
$input = json_decode($raw ?: '[]', true);
if (!is_array($input)) {
    json_out(['ok' => false, 'error' => 'invalid_json']);
}

$dealId = (int)($input['deal_id'] ?? 0);
if ($dealId <= 0) {
    json_out(['ok' => false, 'error' => 'missing_deal_id']);
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

$baseLoan = function_exists('estimate_amount_financed')
    ? estimate_amount_financed($deal, $includedTotal + $accessoryTotalRolledIn)
    : null;

$selectedItems = $input['selected_items'] ?? [];
$selectedTaxedByCode = [];
$totalSelectedTaxed = 0.0;
if (is_array($selectedItems)) {
    foreach ($selectedItems as $item) {
        if (!is_array($item)) continue;
        $code = (string)($item['code'] ?? '');
        $taxed = isset($item['taxed_price']) && is_numeric($item['taxed_price']) ? (float)$item['taxed_price'] : 0.0;
        if ($code === '') continue;
        $selectedTaxedByCode[$code] = ($selectedTaxedByCode[$code] ?? 0.0) + $taxed;
        $totalSelectedTaxed += $taxed;
    }
}

$targets = $input['targets'] ?? [];
if (!is_array($targets) || empty($targets)) {
    json_out(['ok' => true, 'products' => []]);
}

$organizationId = isset($deal['organization']) ? (int)$deal['organization'] : null;
$candidates = resolve_scope_candidates($db, $organizationId);
$scopeRank = [];
foreach ($candidates as $idx => $entry) {
    $scopeRank[$entry[0] . '|' . $entry[1]] = $idx;
}

$dealType = strtolower(trim((string)($deal['deal_type'] ?? '')));
$vehiclePrice = isset($deal['sale_price']) ? (float)$deal['sale_price'] : null;
$vehiclePrice = ($vehiclePrice !== null && $vehiclePrice > 0) ? $vehiclePrice : null;
$dealTerm = (int)($deal['term'] ?? 0);
$dealRate = (float)($deal['interest_rate'] ?? 0);

$out = [];
foreach ($targets as $t) {
    if (!is_array($t)) continue;
    $code = (string)($t['code'] ?? '');
    $productId = (int)($t['product_id'] ?? 0);
    if ($code === '' || $productId <= 0) continue;

    if (!column_exists($db, 'product_deal_pricing_rules', 'product_id')) {
        continue;
    }

    $loanAmount = $baseLoan === null ? null : ((float)$baseLoan + $totalSelectedTaxed - (float)($selectedTaxedByCode[$code] ?? 0.0));

    $conds = [];
    $params = [$productId];
    foreach ($candidates as [$scopeType, $scopeValue]) {
        $conds[] = "(scope_type = ? AND scope_value = ?)";
        $params[] = $scopeType;
        $params[] = $scopeValue;
    }
    if (empty($conds)) continue;

    try {
        $stmt = $db->prepare("
            SELECT *
            FROM product_deal_pricing_rules
            WHERE product_id = ?
              AND (" . implode(' OR ', $conds) . ")
            ORDER BY created_at DESC
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $rows = [];
    }
    if (empty($rows)) continue;

    $matchedRows = [];
    foreach ($rows as $row) {
        if (!is_array($row)) continue;

        $rowDealType = strtolower(trim((string)($row['deal_type'] ?? '')));
        if ($rowDealType !== '' && $rowDealType !== 'any' && $dealType !== '' && $rowDealType !== $dealType) {
            continue;
        }
        $key = ($row['scope_type'] ?? 'global') . '|' . ($row['scope_value'] ?? '');
        if (!array_key_exists($key, $scopeRank)) {
            continue;
        }

        $minVehicle = (($row['min_vehicle_price'] ?? null) === null || ($row['min_vehicle_price'] ?? '') === '') ? null : (float)$row['min_vehicle_price'];
        $maxVehicle = (($row['max_vehicle_price'] ?? null) === null || ($row['max_vehicle_price'] ?? '') === '') ? null : (float)$row['max_vehicle_price'];
        $minLoan = (($row['min_loan_amount'] ?? null) === null || ($row['min_loan_amount'] ?? '') === '') ? null : (float)$row['min_loan_amount'];
        $maxLoan = (($row['max_loan_amount'] ?? null) === null || ($row['max_loan_amount'] ?? '') === '') ? null : (float)$row['max_loan_amount'];

        if (function_exists('deal_pricing_rule_matches')) {
            if (!deal_pricing_rule_matches($vehiclePrice, $minVehicle, $maxVehicle)) continue;
            if (!deal_pricing_rule_matches($loanAmount, $minLoan, $maxLoan)) continue;
        }

        $matchedRows[] = $row;
    }
    if (empty($matchedRows)) continue;

    // Use the most specific scope (store/org/global).
    $minRank = null;
    $scopeFiltered = [];
    foreach ($matchedRows as $row) {
        $key = ($row['scope_type'] ?? 'global') . '|' . ($row['scope_value'] ?? '');
        $rank = $scopeRank[$key];
        if ($minRank === null || $rank < $minRank) {
            $minRank = $rank;
            $scopeFiltered = [$row];
        } elseif ($rank === $minRank) {
            $scopeFiltered[] = $row;
        }
    }

    $rowsByCoverage = [];
    foreach ($scopeFiltered as $row) {
        $coverage = isset($row['coverage_term']) ? (int)$row['coverage_term'] : 0;
        if ($coverage <= 0) continue;
        $rowsByCoverage[$coverage][] = $row;
    }
    if (empty($rowsByCoverage)) continue;

    ksort($rowsByCoverage);
    $terms = [];
    foreach ($rowsByCoverage as $coverage => $bucket) {
        $best = function_exists('choose_best_deal_pricing_rule')
            ? choose_best_deal_pricing_rule($bucket)
            : (reset($bucket) ?: null);
        if (!$best) continue;

        $price = isset($best['price']) && is_numeric($best['price']) ? (float)$best['price'] : 0.0;
        $payment = calculate_display_amount((string)($deal['deal_type'] ?? ''), $price, $dealTerm, $dealRate);
        $terms[(string)$coverage] = [
            'label' => build_term_label((int)$coverage),
            'price' => $price,
            'payment' => $payment,
        ];
    }
    if (!empty($terms)) {
        $out[$code] = [
            'terms' => $terms,
        ];
    }
}

json_out(['ok' => true, 'products' => $out]);
