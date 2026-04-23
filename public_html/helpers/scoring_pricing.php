<?php
require_once __DIR__ . '/db_utils.php';
function choose_pricing_override(array $rows, int $term): ?array {
    if (empty($rows)) {
        return null;
    }
    $fallback = null;
    foreach ($rows as $row) {
        $coverageTerm = isset($row['coverage_term']) ? (int)$row['coverage_term'] : null;
        if ($coverageTerm && $term > 0 && $coverageTerm === $term) {
            return $row;
        }
        if ($coverageTerm === 0 || $coverageTerm === null) {
            if ($fallback === null) {
                $fallback = $row;
            }
        }
    }
    return $fallback ?? $rows[0];
}

function choose_pricing_override_with_specificity(array $rows, int $term): ?array {
    if (empty($rows)) {
        return null;
    }
    $maxSpec = null;
    $candidates = [];
    foreach ($rows as $row) {
        $spec = isset($row['specificity']) ? (int)$row['specificity'] : 0;
        if ($maxSpec === null || $spec > $maxSpec) {
            $maxSpec = $spec;
            $candidates = [$row];
        } elseif ($spec === $maxSpec) {
            $candidates[] = $row;
        }
    }
    return choose_pricing_override($candidates, $term);
}

function resolve_pricing_scope_candidates(PDO $db, ?int $organizationId): array {
    static $scopeCache = [];
    $cacheKey = $organizationId ? (string)(int)$organizationId : '0';
    if (array_key_exists($cacheKey, $scopeCache)) {
        return $scopeCache[$cacheKey];
    }

    $orgKind = null;
    $parentId = null;
    if ($organizationId) {
        $meta = get_organization_meta($db, $organizationId);
        $orgKind = $meta['org_kind'] ?? null;
        $parentId = $meta['parent_org_id'] ?? null;
    }
    $storeId = ($organizationId && ($orgKind ?? 'store') !== 'group') ? (string)$organizationId : '';
    $orgScopeId = $organizationId ? (string)$organizationId : '';
    $groupId = ($orgKind === 'store' && $parentId) ? (string)$parentId : '';
    $candidates = [];
    if ($storeId !== '') {
        $candidates[] = ['store', $storeId];
    }
    if ($orgScopeId !== '') {
        $candidates[] = ['org', $orgScopeId];
    }
    if ($groupId !== '') {
        $candidates[] = ['org', $groupId];
    }
    $candidates[] = ['global', ''];
    $scopeCache[$cacheKey] = $candidates;
    return $candidates;
}

function resolve_default_term_for_product(PDO $db, int $productId, ?int $organizationId): ?int {
    static $termCache = [];
    static $termStmt = null;

    if ($productId <= 0) {
        return null;
    }
    if (!column_exists($db, 'product_term_defaults', 'product_id')) {
        return null;
    }

    $cacheKey = $productId . '|' . (string)($organizationId ?? 0);
    if (array_key_exists($cacheKey, $termCache)) {
        return $termCache[$cacheKey];
    }

    if ($termStmt === null) {
        $termStmt = $db->prepare("
            SELECT coverage_term
            FROM product_term_defaults
            WHERE product_id = ?
              AND scope_type = ?
              AND scope_value = ?
            LIMIT 1
        ");
    }

    $candidates = resolve_pricing_scope_candidates($db, $organizationId);
    foreach ($candidates as [$scopeType, $scopeValue]) {
        $termStmt->execute([$productId, $scopeType, $scopeValue]);
        $term = $termStmt->fetchColumn();
        if ($term !== false && $term !== null) {
            $termCache[$cacheKey] = (int)$term;
            return $termCache[$cacheKey];
        }
    }
    $termCache[$cacheKey] = null;
    return null;
}

function apply_pricing_override(PDO $db, array $product, ?int $vehicleMakeId, ?int $vehicleModelId, ?int $vehicleTrimId, int $term, array $deal, ?int $organizationId = null): array {
    if (!$vehicleMakeId || empty($product['code'])) {
        return $product;
    }

    $vehicleModelId = normalize_vehicle_id($vehicleModelId);
    $vehicleTrimId = normalize_vehicle_id($vehicleTrimId);

    $productId = isset($product['id']) ? (int)$product['id'] : 0;
    if ($productId > 0) {
        try {
            $hasScopeType = column_exists($db, 'product_vehicle_pricing_overrides', 'scope_type');
            $hasScopeValue = column_exists($db, 'product_vehicle_pricing_overrides', 'scope_value');
            $selectCols = "price, cost, coverage_term, created_at";
            $selectCols .= $hasScopeType ? ", scope_type" : ", 'global' AS scope_type";
            $selectCols .= $hasScopeValue ? ", scope_value" : ", '' AS scope_value";
            $selectCols .= column_exists($db, 'product_vehicle_pricing_overrides', 'max_kms') ? ", max_kms" : ", NULL AS max_kms";
            $selectCols .= column_exists($db, 'product_vehicle_pricing_overrides', 'max_age_years') ? ", max_age_years" : ", NULL AS max_age_years";
            $stmt = $db->prepare("
                SELECT {$selectCols},
                    CASE
                    WHEN vehicle_trim_id IS NOT NULL AND vehicle_trim_id <> 0 AND vehicle_trim_id = ? THEN 3
                    WHEN vehicle_model_id IS NOT NULL AND vehicle_model_id <> 0 AND vehicle_model_id = ? THEN 2
                    ELSE 1
                END AS specificity
            FROM product_vehicle_pricing_overrides
            WHERE product_id = ?
              AND vehicle_make_id = ?
              AND (vehicle_model_id IS NULL OR vehicle_model_id = 0 OR vehicle_model_id = ?)
              AND (vehicle_trim_id IS NULL OR vehicle_trim_id = 0 OR vehicle_trim_id = ?)
                ORDER BY specificity DESC, created_at DESC
            ");
            $stmt->execute([
                $vehicleTrimId,
                $vehicleModelId,
                $productId,
                $vehicleMakeId,
                $vehicleModelId,
                $vehicleTrimId,
            ]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $vehicleKms = isset($deal['vehicle_kms']) ? (int)$deal['vehicle_kms'] : null;
            $vehicleKms = $vehicleKms && $vehicleKms > 0 ? $vehicleKms : null;
            $vehicleYear = isset($deal['vehicle_year']) ? (int)$deal['vehicle_year'] : null;
            $vehicleYear = $vehicleYear && $vehicleYear > 0 ? $vehicleYear : null;
            $vehicleAgeYears = resolve_vehicle_age_years($deal['in_service_date'] ?? null, $vehicleYear);
            $rows = array_values(array_filter($rows, function ($row) use ($vehicleKms, $vehicleAgeYears) {
                $maxKms = isset($row['max_kms']) ? (int)$row['max_kms'] : 0;
                if ($maxKms > 0 && ($vehicleKms === null || $vehicleKms > $maxKms)) {
                    return false;
                }
                $maxAge = isset($row['max_age_years']) ? (int)$row['max_age_years'] : 0;
                if ($maxAge > 0 && ($vehicleAgeYears === null || $vehicleAgeYears > $maxAge)) {
                    return false;
                }
                return true;
            }));
            if (empty($rows)) {
                return $product;
            }
            $candidates = resolve_pricing_scope_candidates($db, $organizationId);
            $scopeRank = [];
            foreach ($candidates as $idx => $entry) {
                $scopeRank[$entry[0] . '|' . $entry[1]] = $idx;
            }
            $filtered = [];
            $minRank = null;
            foreach ($rows as $row) {
                $key = ($row['scope_type'] ?? 'global') . '|' . ($row['scope_value'] ?? '');
                if (!array_key_exists($key, $scopeRank)) {
                    continue;
                }
                $rank = $scopeRank[$key];
                if ($minRank === null || $rank < $minRank) {
                    $minRank = $rank;
                    $filtered = [$row];
                } elseif ($rank === $minRank) {
                    $filtered[] = $row;
                }
            }
            $override = choose_pricing_override_with_specificity($filtered, $term);
            if ($override) {
                if ($override['price'] !== null) {
                    $product['sale_price'] = (float)$override['price'];
                }
                if ($override['cost'] !== null) {
                    $product['default_cost'] = (float)$override['cost'];
                }
                if (!empty($override['coverage_term'])) {
                    $product['coverage_term'] = (int)$override['coverage_term'];
                }
                return $product;
            }
        } catch (PDOException $e) {
            // No legacy fallback; pricing remains table-driven via current product records.
            return $product;
        }
    }
    return $product;
}

function estimate_amount_financed(array $deal, float $includedTotal = 0.0): ?float {
    $sale = isset($deal['sale_price']) ? (float)$deal['sale_price'] : 0.0;
    if ($sale <= 0) {
        return null;
    }
    $down = isset($deal['down_payment']) ? (float)$deal['down_payment'] : 0.0;
    $trade = isset($deal['trade_value']) ? (float)$deal['trade_value'] : 0.0;
    $lien = isset($deal['lien_amount']) ? (float)$deal['lien_amount'] : 0.0;
    $doc = isset($deal['documentation_fee']) ? (float)$deal['documentation_fee'] : 0.0;
    $ppsa = isset($deal['ppsa_fee']) ? (float)$deal['ppsa_fee'] : 0.0;

    // Conservative estimate used for pricing rules (not a contract calculation).
    $amount = $sale + $doc + $ppsa + $includedTotal - $down - $trade + $lien;
    if ($amount < 0) {
        $amount = 0;
    }
    return $amount;
}

function deal_pricing_rule_matches(?float $value, ?float $min, ?float $max): bool {
    if ($min === null && $max === null) {
        return true;
    }
    if ($value === null) {
        return false;
    }
    if ($min !== null && $value < $min) {
        return false;
    }
    if ($max !== null && $value > $max) {
        return false;
    }
    return true;
}

function choose_best_deal_pricing_rule(array $rows): ?array {
    $best = null;
    $bestSpec = -1;
    $bestWidth = null;
    $bestCreated = null;

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $spec = 0;
        $width = 0.0;

        $dealType = strtolower(trim((string)($row['deal_type'] ?? '')));
        if ($dealType !== '' && $dealType !== 'any') {
            $spec += 3;
        }

        $pairs = [
            ['min_vehicle_price', 'max_vehicle_price'],
            ['min_loan_amount', 'max_loan_amount'],
            ['min_term', 'max_term'],
        ];
        foreach ($pairs as [$minKey, $maxKey]) {
            $minVal = $row[$minKey] ?? null;
            $maxVal = $row[$maxKey] ?? null;
            if ($minVal !== null && $minVal !== '') {
                $spec += 1;
            }
            if ($maxVal !== null && $maxVal !== '') {
                $spec += 1;
            }
            if ($minVal !== null && $minVal !== '' && $maxVal !== null && $maxVal !== '') {
                $minF = (float)$minVal;
                $maxF = (float)$maxVal;
                if ($maxF >= $minF) {
                    $width += ($maxF - $minF);
                } else {
                    $width += 1e12;
                }
            } elseif (($minVal !== null && $minVal !== '') || ($maxVal !== null && $maxVal !== '')) {
                $width += 1e9;
            }
        }

        $created = (string)($row['created_at'] ?? '');
        if ($best === null) {
            $best = $row;
            $bestSpec = $spec;
            $bestWidth = $width;
            $bestCreated = $created;
            continue;
        }

        if ($spec > $bestSpec) {
            $best = $row;
            $bestSpec = $spec;
            $bestWidth = $width;
            $bestCreated = $created;
            continue;
        }
        if ($spec === $bestSpec) {
            if ($bestWidth === null || $width < $bestWidth) {
                $best = $row;
                $bestWidth = $width;
                $bestCreated = $created;
                continue;
            }
            if ($bestWidth !== null && abs($width - $bestWidth) < 0.000001) {
                if ($created !== '' && ($bestCreated === null || $bestCreated === '' || strcmp($created, $bestCreated) > 0)) {
                    $best = $row;
                    $bestCreated = $created;
                }
            }
        }
    }

    return $best;
}

function apply_deal_pricing_rules(PDO $db, array $product, int $term, array $deal, ?int $organizationId = null, float $includedTotal = 0.0): array {
    $productId = isset($product['id']) ? (int)$product['id'] : 0;
    if ($productId <= 0) {
        return $product;
    }
    if (!column_exists($db, 'product_deal_pricing_rules', 'product_id')) {
        return $product;
    }

    $dealType = strtolower(trim((string)($deal['deal_type'] ?? '')));
    $vehiclePrice = isset($deal['sale_price']) ? (float)$deal['sale_price'] : null;
    $loanAmount = estimate_amount_financed($deal, $includedTotal);
    $termVal = $term > 0 ? (float)$term : null;

    $scopeCandidates = resolve_pricing_scope_candidates($db, $organizationId);
    $conds = [];
    $params = [$productId];
    foreach ($scopeCandidates as [$scopeType, $scopeValue]) {
        $conds[] = "(scope_type = ? AND scope_value = ?)";
        $params[] = $scopeType;
        $params[] = $scopeValue;
    }
    if (empty($conds)) {
        return $product;
    }

    try {
        $stmt = $db->prepare("
            SELECT
                deal_type,
                min_vehicle_price,
                max_vehicle_price,
                min_loan_amount,
                max_loan_amount,
                min_term,
                max_term,
                override_price,
                override_cost,
                override_term,
                created_at
            FROM product_deal_pricing_rules
            WHERE product_id = ?
              AND (" . implode(' OR ', $conds) . ")
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return $product;
    }
    if (empty($rows)) {
        return $product;
    }

    $matched = [];
    foreach ($rows as $row) {
        $rowDealType = strtolower(trim((string)($row['deal_type'] ?? '')));
        if ($rowDealType !== '' && $rowDealType !== 'any' && $dealType !== $rowDealType) {
            continue;
        }

        $minVehicleF = (($row['min_vehicle_price'] ?? null) === null || ($row['min_vehicle_price'] ?? '') === '') ? null : (float)$row['min_vehicle_price'];
        $maxVehicleF = (($row['max_vehicle_price'] ?? null) === null || ($row['max_vehicle_price'] ?? '') === '') ? null : (float)$row['max_vehicle_price'];
        $minLoanF = (($row['min_loan_amount'] ?? null) === null || ($row['min_loan_amount'] ?? '') === '') ? null : (float)$row['min_loan_amount'];
        $maxLoanF = (($row['max_loan_amount'] ?? null) === null || ($row['max_loan_amount'] ?? '') === '') ? null : (float)$row['max_loan_amount'];
        $minTermF = (($row['min_term'] ?? null) === null || ($row['min_term'] ?? '') === '') ? null : (float)$row['min_term'];
        $maxTermF = (($row['max_term'] ?? null) === null || ($row['max_term'] ?? '') === '') ? null : (float)$row['max_term'];

        if (!deal_pricing_rule_matches($vehiclePrice, $minVehicleF, $maxVehicleF)) {
            continue;
        }
        if (!deal_pricing_rule_matches($loanAmount, $minLoanF, $maxLoanF)) {
            continue;
        }
        if (!deal_pricing_rule_matches($termVal, $minTermF, $maxTermF)) {
            continue;
        }
        $matched[] = $row;
    }

    if (empty($matched)) {
        return $product;
    }

    $best = choose_best_deal_pricing_rule($matched);
    if (!$best) {
        return $product;
    }

    if (array_key_exists('override_price', $best) && $best['override_price'] !== null && $best['override_price'] !== '') {
        $product['sale_price'] = (float)$best['override_price'];
    }
    if (array_key_exists('override_cost', $best) && $best['override_cost'] !== null && $best['override_cost'] !== '') {
        $product['default_cost'] = (float)$best['override_cost'];
    }
    if (!empty($best['override_term'])) {
        $product['coverage_term'] = (int)$best['override_term'];
    }

    return $product;
}
