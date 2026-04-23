<?php

require_once __DIR__ . '/helpers/db_utils.php';

if (!function_exists('decode_customer_context_payload')) {
    function decode_customer_context_payload(string $raw): array
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return [];
        }
        $first = $trimmed[0] ?? '';
        if ($first === '{' || $first === '[') {
            $decoded = json_decode($trimmed, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return ['freeform' => $trimmed];
    }
}

function extract_customer_context_hints(string $raw): array
{
    $ctx = decode_customer_context_payload($raw);
    if (empty($ctx)) {
        return [];
    }

    $hay = strtolower(implode(' ', array_map(fn($v) => is_scalar($v) ? (string)$v : '', $ctx)));

    $hints = [];
    if ($hay !== '') {
        if (preg_match('/\b(kid|kids|child|children|toddler|baby|car seat|carseat)\b/', $hay)) {
            $hints['kids'] = true;
        }
        if (preg_match('/\b(dog|dogs|puppy|pet|pets|cat|cats)\b/', $hay)) {
            $hints['pets'] = true;
        }
        if (preg_match('/\b(commute|commuting|daily|every day|highway)\b/', $hay)) {
            $hints['commute'] = true;
        }
        if (preg_match('/\b(outside|outdoor|street parking|park outside|driveway)\b/', $hay)) {
            $hints['outdoor'] = true;
        }
        if (preg_match('/\b(gravel|dirt road|backroad|back road)\b/', $hay)) {
            $hints['gravel'] = true;
        }
        if (preg_match('/\b(winter|snow|ice|salt)\b/', $hay)) {
            $hints['winter'] = true;
        }
    }

    return $hints;
}

function build_accessory_personalized_reason(array $accessory, array $matchedDetails, array $matchedTags, array $contextHints, string $profileSummary = ''): string
{
    $filtered = filter_accessory_reason_inputs($accessory, $matchedDetails, $matchedTags, $contextHints);
    $matchedDetails = $filtered['matched_details'];
    $matchedTags = $filtered['matched_tags'];
    $contextHints = $filtered['context_hints'];

    $parts = [];

    // Prefer human-friendly details when available.
    $matchedDetails = array_values(array_filter(array_map('trim', array_map('strval', $matchedDetails))));
    foreach (array_slice($matchedDetails, 0, 2) as $detail) {
        $parts[] = $detail;
    }

    // Fallback to rule labels only when no detail text is available.
    if (empty($parts) && !empty($matchedTags)) {
        $matchedTags = array_values(array_filter(array_map(static fn($x) => trim((string)$x), $matchedTags), static fn($x) => $x !== ''));
        foreach (array_slice($matchedTags, 0, 2) as $tag) {
            $parts[] = str_replace('_', ' ', $tag);
        }
    }

    if (empty($parts)) {
        $parts = build_accessory_contextual_reason_parts($accessory, $contextHints, $profileSummary);
    }

    if (empty($parts)) {
        $genericParts = [];
        $profileHaystack = strtolower($profileSummary);
        if (str_contains($profileHaystack, '7+ years') || str_contains($profileHaystack, '5-6 years') || str_contains($profileHaystack, 'long-term')) {
            $genericParts[] = 'fits your longer-term ownership plans';
        }
        if (str_contains($profileHaystack, 'appearance is a high priority') || str_contains($profileHaystack, 'appearance is somewhat important')) {
            $genericParts[] = 'supports how much you care about keeping the vehicle looking its best';
        }
        if (!empty($genericParts)) {
            $parts = $genericParts;
        }
    }

    if (empty($parts)) {
        return '';
    }

    $label = 'Why it fits: ' . implode('; ', array_slice($parts, 0, 2)) . '.';
    return strlen($label) > 240 ? (substr($label, 0, 237) . '...') : $label;
}

function build_accessory_contextual_reason_parts(array $accessory, array $contextHints, string $profileSummary): array
{
    $haystack = strtolower(trim(implode(' ', array_filter([
        (string)($accessory['code'] ?? ''),
        (string)($accessory['name'] ?? ''),
        (string)($accessory['category'] ?? ''),
        (string)($accessory['description'] ?? ''),
    ]))));
    $profileHaystack = strtolower($profileSummary);
    $parts = [];

    $isWinter = preg_match('/winter|snow|ice|wheel|tire/', $haystack) === 1;
    $isExteriorProtection = preg_match('/film|paint|ceramic|shield|mud|flap|body|exterior/', $haystack) === 1;
    $isInteriorProtection = preg_match('/interior|seat|liner|cargo|mat|fabric|leather|vinyl/', $haystack) === 1;
    $isGlass = preg_match('/glass|windshield/', $haystack) === 1;
    $isTint = preg_match('/tint|uv|sun/', $haystack) === 1;
    $isRust = preg_match('/rust|undercoat|corrosion|armour/', $haystack) === 1;

    if ($isWinter && (!empty($contextHints['winter']) || !empty($contextHints['commute']) || str_contains($profileHaystack, 'highway'))) {
        $parts[] = 'matches the winter driving conditions this vehicle will see';
    }
    if (($isExteriorProtection || $isGlass) && (!empty($contextHints['gravel']) || str_contains($profileHaystack, 'gravel roads') || str_contains($profileHaystack, 'rough roads'))) {
        $parts[] = 'makes sense for the gravel and rough-road driving in your profile';
    }
    if (($isRust || $isWinter) && (!empty($contextHints['winter']) || str_contains($profileHaystack, '7+ years') || str_contains($profileHaystack, '5-6 years'))) {
        $parts[] = 'helps protect the vehicle over the longer ownership window you described';
    }
    if (($isExteriorProtection || $isTint) && (str_contains($profileHaystack, 'appearance is a high priority') || str_contains($profileHaystack, 'appearance is somewhat important'))) {
        $parts[] = 'aligns with your focus on keeping the vehicle looking clean and well-kept';
    }
    if ($isInteriorProtection && (!empty($contextHints['kids']) || !empty($contextHints['pets']) || str_contains($profileHaystack, 'kids or pets'))) {
        $parts[] = 'fits how the cabin will be used by your family and pets';
    }
    if ($isInteriorProtection && (str_contains($profileHaystack, 'eats or drinks in the vehicle') || str_contains($profileHaystack, 'food'))) {
        $parts[] = 'adds useful protection for day-to-day interior wear and spills';
    }
    if ($isTint && (!empty($contextHints['kids']) || !empty($contextHints['pets']) || str_contains($profileHaystack, 'kids or pets'))) {
        $parts[] = 'adds comfort for passengers who ride with you regularly';
    }
    if ($isGlass && (!empty($contextHints['commute']) || str_contains($profileHaystack, 'highways'))) {
        $parts[] = 'supports the kind of daily highway visibility and chip protection you may value';
    }

    return array_values(array_unique(array_filter(array_map(static fn($x) => trim((string)$x), $parts), static fn($x) => $x !== '')));
}

function filter_accessory_reason_inputs(array $accessory, array $matchedDetails, array $matchedTags, array $contextHints): array
{
    $filteredTags = [];
    foreach ($matchedTags as $tag) {
        $tagKey = strtolower(trim((string)$tag));
        if ($tagKey === '') {
            continue;
        }
        $filteredTags[] = $tagKey;
    }
    $filteredTags = array_values(array_unique($filteredTags));

    $filteredDetails = [];
    foreach ($matchedDetails as $detail) {
        $detailText = trim((string)$detail);
        if ($detailText === '') {
            continue;
        }
        $filteredDetails[] = $detailText;
    }
    $filteredDetails = array_values(array_unique($filteredDetails));

    return [
        'matched_details' => $filteredDetails,
        'matched_tags' => $filteredTags,
        'context_hints' => is_array($contextHints) ? $contextHints : [],
    ];
}

function resolve_accessory_scope(PDO $db, int $organizationId): array
{
    $orgKind = 'store';
    $parentOrgId = null;
    if ($organizationId > 0) {
        try {
            $stmt = $db->prepare("SELECT org_kind, parent_org_id FROM organizations WHERE id = ?");
            $stmt->execute([$organizationId]);
            if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $orgKind = $row['org_kind'] ?? $orgKind;
                $parentOrgId = $row['parent_org_id'] ?? null;
            }
        } catch (PDOException $e) {
            $orgKind = 'store';
        }
    }
    $storeId = null;
    $orgId = null;
    if ($organizationId > 0) {
        if ($orgKind === 'group') {
            $orgId = $organizationId;
        } else {
            $storeId = $organizationId;
            if ($parentOrgId) {
                $orgId = (int)$parentOrgId;
            }
        }
    }
    return [
        'org_kind' => $orgKind,
        'store_id' => $storeId,
        'org_id' => $orgId,
    ];
}

function accessory_scope_priority(string $scopeType): int
{
    if ($scopeType === 'store') {
        return 3;
    }
    if ($scopeType === 'org') {
        return 2;
    }
    return 1;
}

function dedupe_accessories_by_code(array $rows): array
{
    $byCode = [];
    foreach ($rows as $row) {
        $code = $row['code'] ?? '';
        if ($code === '') {
            continue;
        }
        // Preserve distinct accessory variants (name/price/photo/category) while still allowing
        // higher-scope rows (store > org > global) to override the same variant.
        $provider = $row['provider'] ?? '';
        $key = strtolower(trim($code)) . '|' .
            strtolower(trim((string)$provider)) . '|' .
            strtolower(trim((string)($row['name'] ?? ''))) . '|' .
            strtolower(trim((string)($row['category'] ?? ''))) . '|' .
            strtolower(trim((string)($row['photo_url'] ?? ''))) . '|' .
            (string)round((float)($row['base_price'] ?? 0), 2);
        if (!isset($byCode[$key])) {
            $byCode[$key] = $row;
            continue;
        }
        $current = $byCode[$key];
        $currentPriority = accessory_scope_priority($current['scope_type'] ?? 'global');
        $candidatePriority = accessory_scope_priority($row['scope_type'] ?? 'global');
        if ($candidatePriority > $currentPriority) {
            $byCode[$key] = $row;
        }
    }
    return array_values($byCode);
}

function fetch_accessories_for_org(PDO $db, int $organizationId): array
{
    $scope = resolve_accessory_scope($db, $organizationId);
    $storeId = $scope['store_id'];
    $orgId = $scope['org_id'];

    $sql = "SELECT
                id,
                code,
                provider,
                name,
                category,
                description,
                base_price,
                photo_url,
                active,
                scope_type,
                scope_value
            FROM accessories
            WHERE active = 1";
    $params = [];
    $scopeParts = ["scope_type = 'global'"];

    if ($orgId !== null) {
        $scopeParts[] = "(scope_type = 'org' AND scope_value = ?)";
        $params[] = $orgId;
    }
    if ($storeId !== null) {
        $scopeParts[] = "(scope_type = 'store' AND scope_value = ?)";
        $params[] = $storeId;
    }
    if (!empty($scopeParts)) {
        $sql .= " AND (" . implode(' OR ', $scopeParts) . ")";
    }
    // Admins shouldn't need to manage a manual sort order; keep ordering stable and predictable.
    $sql .= " ORDER BY name ASC, provider ASC, code ASC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return dedupe_accessories_by_code($rows);
}

function accessory_matches_fitment(array $fitments, ?string $make, ?string $model, ?int $year, ?int $makeId = null, ?int $modelId = null, ?int $trimId = null): bool
{
    if (empty($fitments)) {
        return true;
    }
    $make = strtolower(trim((string)$make));
    $model = strtolower(trim((string)$model));
    foreach ($fitments as $fitment) {
        $fitMakeId = isset($fitment['make_id']) ? (int)$fitment['make_id'] : null;
        $fitModelId = isset($fitment['model_id']) ? (int)$fitment['model_id'] : null;
        $fitTrimId = isset($fitment['trim_id']) ? (int)$fitment['trim_id'] : null;
        $fitMake = strtolower(trim((string)($fitment['brand'] ?? '')));
        $fitModel = strtolower(trim((string)($fitment['model'] ?? '')));

        if ($fitTrimId) {
            // Trim-specific fitments should fail closed when the deal does not have a trim id.
            if (!$trimId || $fitTrimId !== (int)$trimId) {
                continue;
            }
        }

        if ($fitModelId) {
            if ($modelId) {
                if ($fitModelId !== (int)$modelId) {
                    continue;
                }
            } elseif ($fitModel !== '' && $model !== '') {
                if ($fitModel !== $model) {
                    continue;
                }
            } else {
                // Model-specific fitments should not degrade to make-only matching.
                continue;
            }
        }

        if ($fitMakeId) {
            if ($makeId) {
                if ($fitMakeId !== (int)$makeId) {
                    continue;
                }
            } elseif ($fitMake !== '' && $make !== '') {
                if ($fitMake !== $make) {
                    continue;
                }
            } else {
                continue;
            }
        }

        if ($fitMake !== '') {
            if ($make === '' || $fitMake !== $make) {
                continue;
            }
        }

        if ($fitModel !== '') {
            if ($model === '' || $fitModel !== $model) {
                continue;
            }
        }
        if ($year !== null) {
            $minYear = isset($fitment['min_year']) && $fitment['min_year'] !== null ? (int)$fitment['min_year'] : null;
            $maxYear = isset($fitment['max_year']) && $fitment['max_year'] !== null ? (int)$fitment['max_year'] : null;
            if ($minYear !== null && $year < $minYear) {
                continue;
            }
            if ($maxYear !== null && $year > $maxYear) {
                continue;
            }
        }
        return true;
    }
    return false;
}

function calculate_accessory_payment(string $dealType, float $price, ?int $term, ?float $rate): float
{
    if (strcasecmp($dealType, 'Cash') === 0) {
        return $price;
    }
    if ($term === null || $term <= 0) {
        return 0.0;
    }
    $rate = $rate ?? 0.0;
    if (strcasecmp($dealType, 'Lease') === 0) {
        $moneyFactor = ($rate / 100) / 24;
        $depreciation = $term > 0 ? ($price / $term) : 0.0;
        $financeCharge = ($price) * $moneyFactor;
        return max(0.0, $depreciation + $financeCharge);
    }
    $i = ($rate / 100) / 12;
    if ($i > 0) {
        return ($price * $i) / (1 - pow(1 + $i, -$term));
    }
    return $term > 0 ? ($price / $term) : 0.0;
}

function is_accessories_enabled(PDO $db, int $organizationId): bool
{
    if (!column_exists($db, 'organizations', 'accessories_enabled')) {
        return true;
    }
    if ($organizationId <= 0) {
        return true;
    }
    try {
        $stmt = $db->prepare("SELECT accessories_enabled, org_kind, parent_org_id FROM organizations WHERE id = ?");
        $stmt->execute([$organizationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return true;
        }
        if ($row['accessories_enabled'] !== null) {
            return (int)$row['accessories_enabled'] === 1;
        }
        if (($row['org_kind'] ?? 'store') === 'store' && !empty($row['parent_org_id'])) {
            $parentStmt = $db->prepare("SELECT accessories_enabled FROM organizations WHERE id = ?");
            $parentStmt->execute([(int)$row['parent_org_id']]);
            $parentValue = $parentStmt->fetchColumn();
            if ($parentValue !== false && $parentValue !== null) {
                return (int)$parentValue === 1;
            }
        }
    } catch (PDOException $e) {
        return true;
    }
    return true;
}

?>
