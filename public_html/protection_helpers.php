<?php

if (!function_exists('getCapCost')) {
    function normalize_cap_coverage_term(float $sale_price, int $requestedTerm): int {
        $requestedTerm = (int)$requestedTerm;
        if ($requestedTerm <= 0) {
            return 60;
        }

        // Business rule: once vehicle is over $75,000, CAP max term is 60 months.
        $termCap = $sale_price >= 75000 ? 60 : 84;
        $requestedTerm = min($requestedTerm, $termCap);

        // Normalize to the closest offered bracket term at or below requested.
        if ($requestedTerm >= 84) return 84;
        if ($requestedTerm >= 72) return 72;
        if ($requestedTerm >= 60) return 60;
        if ($requestedTerm >= 48) return 48;
        return 36;
    }

    function getCapCost($sale_price, $term) {
        $capCosts = [
            120000   => [60=>3155,48=>2651,36=>2331],
            100000   => [60=>2635,48=>2268,36=>1996],
             80000   => [60=>2219,48=>1942,36=>1710],
             60000   => [84=>2617,72=>2109,60=>1799,48=>1604,36=>1421],
             50000   => [84=>2400,72=>1983,60=>1702,48=>1502,36=>1348],
             40000   => [84=>1943,72=>1607,60=>1408,48=>1266,36=>1135],
            'default'=> [84=>1706,72=>1457,60=>1219,48=>1102,36=>992],
        ];

        $bracket = 'default';
        foreach ([120000,100000,80000,60000,50000,40000] as $b) {
            if ($sale_price >= $b) { $bracket = $b; break; }
        }

        $term = normalize_cap_coverage_term((float)$sale_price, (int)$term);
        return $capCosts[$bracket][$term] ?? null;
    }
}

if (!function_exists('parse_included_protections')) {
    function parse_included_protections($raw): array {
        if ($raw === null || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $items = [];
        foreach ($decoded as $entry) {
            if (is_string($entry)) {
                $code = trim($entry);
                if ($code === '') {
                    continue;
                }
                $items[] = [
                    'code' => $code,
                    'name' => $code,
                    'price' => 0.0,
                ];
                continue;
            }
            if (!is_array($entry)) {
                continue;
            }
            $code = trim((string)($entry['code'] ?? $entry['product_code'] ?? ''));
            $name = trim((string)($entry['name'] ?? $entry['product_name'] ?? ''));
            $priceRaw = $entry['price'] ?? $entry['sale_price'] ?? null;
            $price = $priceRaw === null || $priceRaw === '' ? 0.0 : (float)$priceRaw;
            if ($code === '' && $name === '') {
                continue;
            }
            if ($code === '') {
                $code = $name;
            }
            $items[] = [
                'code' => $code,
                'name' => $name !== '' ? $name : $code,
                'price' => $price,
            ];
        }
        return $items;
    }
}

if (!function_exists('summarize_included_protections')) {
    function summarize_included_protections(array $items): array {
        $total = 0.0;
        $codes = [];
        $names = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $code = trim((string)($item['code'] ?? ''));
            $name = trim((string)($item['name'] ?? $code));
            $price = isset($item['price']) ? (float)$item['price'] : 0.0;
            if ($code === '' && $name === '') {
                continue;
            }
            if ($code === '') {
                $code = $name;
            }
            if ($name === '') {
                $name = $code;
            }
            $codes[] = $code;
            $names[] = $name;
            $total += $price;
        }
        return [
            'total' => $total,
            'codes' => array_values(array_unique($codes)),
            'names' => array_values(array_unique($names)),
        ];
    }
}

if (!function_exists('is_cap_with_gap_enabled')) {
    function is_cap_with_gap_enabled(PDO $db, int $organizationId): bool {
        if ($organizationId <= 0) {
            return true;
        }
        if (!function_exists('column_exists') || !column_exists($db, 'organizations', 'show_cap_with_gap')) {
            return true;
        }
        try {
            $stmt = $db->prepare("SELECT show_cap_with_gap, org_kind, parent_org_id FROM organizations WHERE id = ?");
            $stmt->execute([$organizationId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return true;
            }
            if ($row['show_cap_with_gap'] !== null) {
                return (int)$row['show_cap_with_gap'] === 1;
            }
            if (($row['org_kind'] ?? 'store') === 'store' && !empty($row['parent_org_id'])) {
                $parentStmt = $db->prepare("SELECT show_cap_with_gap FROM organizations WHERE id = ?");
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
}

if (!function_exists('is_ai_reasoning_enabled')) {
    function is_ai_reasoning_enabled(PDO $db, int $organizationId): bool {
        if ($organizationId <= 0) {
            return true;
        }
        if (!function_exists('column_exists') || !column_exists($db, 'organizations', 'ai_reasoning_enabled')) {
            return true;
        }
        try {
            $stmt = $db->prepare("SELECT ai_reasoning_enabled, org_kind, parent_org_id FROM organizations WHERE id = ?");
            $stmt->execute([$organizationId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return true;
            }
            if ($row['ai_reasoning_enabled'] !== null) {
                return (int)$row['ai_reasoning_enabled'] === 1;
            }
            if (($row['org_kind'] ?? 'store') === 'store' && !empty($row['parent_org_id'])) {
                $parentStmt = $db->prepare("SELECT ai_reasoning_enabled FROM organizations WHERE id = ?");
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
}

if (!function_exists('apply_gap_cap_recommendation_rule')) {
    function apply_gap_cap_recommendation_rule(array $codes, bool $showCapWithGap): array {
        if ($showCapWithGap) {
            return $codes;
        }
        $hasGap = false;
        foreach ($codes as $code) {
            if (strcasecmp(trim((string)$code), 'gapProtection') === 0) {
                $hasGap = true;
                break;
            }
        }
        if (!$hasGap) {
            return $codes;
        }
        return array_values(array_filter($codes, static function ($code): bool {
            return strcasecmp(trim((string)$code), 'assetProtection') !== 0;
        }));
    }
}

// Future: add getExtendedWarrantyCost(), getInteriorProtectionRate(), etc.

?>
