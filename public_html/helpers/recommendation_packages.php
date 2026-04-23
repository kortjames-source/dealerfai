<?php
require_once __DIR__ . '/db_utils.php';
function load_product_package_variants(PDO $db, string $code, ?int $organizationId = null, ?string $vehicleCondition = null, array $deal = []): array {
    static $cache = [];
    $vehicleCondition = strtolower(trim((string)$vehicleCondition));
    if (!in_array($vehicleCondition, ['new', 'used'], true)) {
        $vehicleCondition = 'any';
    }
    $key = strtolower(trim($code)) . '|' . (string)($organizationId ?? 0) . '|' . $vehicleCondition;
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    $optionSet = null;
    if ($key !== '') {
        $stmt = $db->prepare("
            SELECT option_set_id
            FROM products
            WHERE code = ?
              AND option_set_id IS NOT NULL
            ORDER BY COALESCE(default_price, 0) ASC
            LIMIT 1
        ");
        $stmt->execute([$code]);
        $optionSet = $stmt->fetchColumn();
    }
    $variants = [];
    $conditionParams = array_fill(0, 15, $vehicleCondition);
    if ($optionSet) {
        $orgKind = null;
        $parentId = null;
        $logicType = null;
        if ($organizationId) {
            try {
                $orgStmt = $db->prepare("SELECT org_kind, parent_org_id, logic_type FROM organizations WHERE id = ?");
                $orgStmt->execute([$organizationId]);
                $orgRow = $orgStmt->fetch(PDO::FETCH_ASSOC);
                if ($orgRow) {
                    $orgKind = $orgRow['org_kind'] ?? null;
                    $parentId = $orgRow['parent_org_id'] ?? null;
                    $logicType = $orgRow['logic_type'] ?? null;
                }
            } catch (PDOException $e) {
                $orgKind = null;
            }
        }
        $storeId = $organizationId ? (string)$organizationId : '';
        $orgScopeId = $organizationId ? (string)$organizationId : '';
        $groupId = ($orgKind === 'store' && $parentId) ? (string)$parentId : '';
        $verticalScope = $logicType ? (string)$logicType : '';

        if ($organizationId) {
            $setStmt = $db->prepare("
                SELECT p.id AS product_id,
                       p.code,
                       p.category,
                       p.provider,
                       p.requires_quote,
                       p.default_term,
                       p.name AS product_name,
                       p.default_price,
                       p.default_description
                FROM products p
                LEFT JOIN product_availability av_store_variant
                  ON av_store_variant.product_id = p.id
                 AND av_store_variant.scope_type = 'store'
                 AND av_store_variant.scope_value = ?
                LEFT JOIN product_availability av_store_provider
                  ON av_store_provider.product_id IS NULL
                 AND av_store_provider.product_code = p.code
                 AND av_store_provider.provider = p.provider
                 AND av_store_provider.scope_type = 'store'
                 AND av_store_provider.scope_value = ?
                LEFT JOIN product_availability av_store_any
                  ON av_store_any.product_id IS NULL
                 AND av_store_any.product_code = p.code
                 AND av_store_any.provider = ''
                 AND av_store_any.scope_type = 'store'
                 AND av_store_any.scope_value = ?
                LEFT JOIN product_availability av_org_variant
                  ON av_org_variant.product_id = p.id
                 AND av_org_variant.scope_type = 'org'
                 AND av_org_variant.scope_value = ?
                LEFT JOIN product_availability av_org_provider
                  ON av_org_provider.product_id IS NULL
                 AND av_org_provider.product_code = p.code
                 AND av_org_provider.provider = p.provider
                 AND av_org_provider.scope_type = 'org'
                 AND av_org_provider.scope_value = ?
                LEFT JOIN product_availability av_org_any
                  ON av_org_any.product_id IS NULL
                 AND av_org_any.product_code = p.code
                 AND av_org_any.provider = ''
                 AND av_org_any.scope_type = 'org'
                 AND av_org_any.scope_value = ?
                LEFT JOIN product_availability av_group_variant
                  ON av_group_variant.product_id = p.id
                 AND av_group_variant.scope_type = 'org'
                 AND av_group_variant.scope_value = ?
                LEFT JOIN product_availability av_group_provider
                  ON av_group_provider.product_id IS NULL
                 AND av_group_provider.product_code = p.code
                 AND av_group_provider.provider = p.provider
                 AND av_group_provider.scope_type = 'org'
                 AND av_group_provider.scope_value = ?
                LEFT JOIN product_availability av_group_any
                  ON av_group_any.product_id IS NULL
                 AND av_group_any.product_code = p.code
                 AND av_group_any.provider = ''
                 AND av_group_any.scope_type = 'org'
                 AND av_group_any.scope_value = ?
                LEFT JOIN product_availability av_vertical_variant
                  ON av_vertical_variant.product_id = p.id
                 AND av_vertical_variant.scope_type = 'vertical'
                 AND av_vertical_variant.scope_value = ?
                LEFT JOIN product_availability av_vertical_provider
                  ON av_vertical_provider.product_id IS NULL
                 AND av_vertical_provider.product_code = p.code
                 AND av_vertical_provider.provider = p.provider
                 AND av_vertical_provider.scope_type = 'vertical'
                 AND av_vertical_provider.scope_value = ?
                LEFT JOIN product_availability av_vertical_any
                  ON av_vertical_any.product_id IS NULL
                 AND av_vertical_any.product_code = p.code
                 AND av_vertical_any.provider = ''
                 AND av_vertical_any.scope_type = 'vertical'
                 AND av_vertical_any.scope_value = ?
                LEFT JOIN product_availability av_global_variant
                  ON av_global_variant.product_id = p.id
                 AND av_global_variant.scope_type = 'global'
                 AND av_global_variant.scope_value = ''
                LEFT JOIN product_availability av_global_provider
                  ON av_global_provider.product_id IS NULL
                 AND av_global_provider.product_code = p.code
                 AND av_global_provider.provider = p.provider
                 AND av_global_provider.scope_type = 'global'
                 AND av_global_provider.scope_value = ''
                LEFT JOIN product_availability av_global_any
                  ON av_global_any.product_id IS NULL
                 AND av_global_any.product_code = p.code
                 AND av_global_any.provider = ''
                 AND av_global_any.scope_type = 'global'
                 AND av_global_any.scope_value = ''
                WHERE p.option_set_id = ?
                  AND p.is_active = 1
                  AND COALESCE(
                          CASE
                              WHEN av_store_variant.id IS NULL THEN NULL
                              WHEN av_store_variant.vehicle_condition IN ('any', ?) THEN av_store_variant.is_enabled
                              ELSE 0
                          END,
                          CASE
                              WHEN av_store_provider.id IS NULL THEN NULL
                              WHEN av_store_provider.vehicle_condition IN ('any', ?) THEN av_store_provider.is_enabled
                              ELSE 0
                          END,
                          CASE
                              WHEN av_store_any.id IS NULL THEN NULL
                              WHEN av_store_any.vehicle_condition IN ('any', ?) THEN av_store_any.is_enabled
                              ELSE 0
                          END,
                          CASE
                              WHEN av_org_variant.id IS NULL THEN NULL
                              WHEN av_org_variant.vehicle_condition IN ('any', ?) THEN av_org_variant.is_enabled
                              ELSE 0
                          END,
                          CASE
                              WHEN av_org_provider.id IS NULL THEN NULL
                              WHEN av_org_provider.vehicle_condition IN ('any', ?) THEN av_org_provider.is_enabled
                              ELSE 0
                          END,
                          CASE
                              WHEN av_org_any.id IS NULL THEN NULL
                              WHEN av_org_any.vehicle_condition IN ('any', ?) THEN av_org_any.is_enabled
                              ELSE 0
                          END,
                          CASE
                              WHEN av_group_variant.id IS NULL THEN NULL
                              WHEN av_group_variant.vehicle_condition IN ('any', ?) THEN av_group_variant.is_enabled
                              ELSE 0
                          END,
                          CASE
                              WHEN av_group_provider.id IS NULL THEN NULL
                              WHEN av_group_provider.vehicle_condition IN ('any', ?) THEN av_group_provider.is_enabled
                              ELSE 0
                          END,
                          CASE
                              WHEN av_group_any.id IS NULL THEN NULL
                              WHEN av_group_any.vehicle_condition IN ('any', ?) THEN av_group_any.is_enabled
                              ELSE 0
                          END,
                          CASE
                              WHEN av_vertical_variant.id IS NULL THEN NULL
                              WHEN av_vertical_variant.vehicle_condition IN ('any', ?) THEN av_vertical_variant.is_enabled
                              ELSE 0
                          END,
                          CASE
                              WHEN av_vertical_provider.id IS NULL THEN NULL
                              WHEN av_vertical_provider.vehicle_condition IN ('any', ?) THEN av_vertical_provider.is_enabled
                              ELSE 0
                          END,
                          CASE
                              WHEN av_vertical_any.id IS NULL THEN NULL
                              WHEN av_vertical_any.vehicle_condition IN ('any', ?) THEN av_vertical_any.is_enabled
                              ELSE 0
                          END,
                          CASE
                              WHEN av_global_variant.id IS NULL THEN NULL
                              WHEN av_global_variant.vehicle_condition IN ('any', ?) THEN av_global_variant.is_enabled
                              ELSE 0
                          END,
                          CASE
                              WHEN av_global_provider.id IS NULL THEN NULL
                              WHEN av_global_provider.vehicle_condition IN ('any', ?) THEN av_global_provider.is_enabled
                              ELSE 0
                          END,
                          CASE
                              WHEN av_global_any.id IS NULL THEN NULL
                              WHEN av_global_any.vehicle_condition IN ('any', ?) THEN av_global_any.is_enabled
                              ELSE 0
                          END,
                          1
                      ) = 1
                ORDER BY COALESCE(p.default_price, 0) ASC
            ");
            $setStmt->execute(array_merge(
                [
                    $storeId,
                    $storeId,
                    $storeId,
                    $orgScopeId,
                    $orgScopeId,
                    $orgScopeId,
                    $groupId,
                    $groupId,
                    $groupId,
                    $verticalScope,
                    $verticalScope,
                    $verticalScope,
                    $optionSet,
                ],
                $conditionParams
            ));
        } else {
            $setStmt = $db->prepare("
                SELECT p.id AS product_id,
                       p.code,
                       p.category,
                       p.provider,
                       p.requires_quote,
                       p.default_term,
                       p.name AS product_name,
                       p.default_price,
                       p.default_description
                FROM products p
                LEFT JOIN product_availability av_global_variant
                  ON av_global_variant.product_id = p.id
                 AND av_global_variant.scope_type = 'global'
                 AND av_global_variant.scope_value = ''
                LEFT JOIN product_availability av_global_provider
                  ON av_global_provider.product_id IS NULL
                 AND av_global_provider.product_code = p.code
                 AND av_global_provider.provider = p.provider
                 AND av_global_provider.scope_type = 'global'
                 AND av_global_provider.scope_value = ''
                LEFT JOIN product_availability av_global_any
                  ON av_global_any.product_id IS NULL
                 AND av_global_any.product_code = p.code
                 AND av_global_any.provider = ''
                 AND av_global_any.scope_type = 'global'
                 AND av_global_any.scope_value = ''
                WHERE p.option_set_id = ?
                  AND p.is_active = 1
                  AND COALESCE(
                        CASE
                            WHEN av_global_variant.id IS NULL THEN NULL
                            WHEN av_global_variant.vehicle_condition IN ('any', ?) THEN av_global_variant.is_enabled
                            ELSE 0
                        END,
                        CASE
                            WHEN av_global_provider.id IS NULL THEN NULL
                            WHEN av_global_provider.vehicle_condition IN ('any', ?) THEN av_global_provider.is_enabled
                            ELSE 0
                        END,
                        CASE
                            WHEN av_global_any.id IS NULL THEN NULL
                            WHEN av_global_any.vehicle_condition IN ('any', ?) THEN av_global_any.is_enabled
                            ELSE 0
                        END,
                        1
                    ) = 1
                ORDER BY COALESCE(p.default_price, 0) ASC
            ");
            $setStmt->execute([
                $optionSet,
                $vehicleCondition,
                $vehicleCondition,
                $vehicleCondition,
            ]);
        }
        $variantRows = $setStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($variantRows) && function_exists('filter_products_by_vehicle_eligibility')) {
            $vehicleMakeId = isset($deal['vehicle_make_id']) ? (int)$deal['vehicle_make_id'] : null;
            $vehicleModelId = isset($deal['vehicle_model_id']) ? (int)$deal['vehicle_model_id'] : null;
            $vehicleTrimId = isset($deal['vehicle_trim_id']) ? (int)$deal['vehicle_trim_id'] : null;
            $eligibilityInput = [];
            foreach ($variantRows as $row) {
                $row['id'] = isset($row['product_id']) ? (int)$row['product_id'] : 0;
                $eligibilityInput[] = $row;
            }
            $eligibleRows = filter_products_by_vehicle_eligibility(
                $db,
                $eligibilityInput,
                $vehicleMakeId,
                $vehicleModelId,
                $vehicleTrimId,
                is_array($deal) ? $deal : []
            );
            $eligibleById = [];
            foreach ($eligibleRows as $eligibleRow) {
                $pid = isset($eligibleRow['id']) ? (int)$eligibleRow['id'] : 0;
                if ($pid > 0) {
                    $eligibleById[$pid] = true;
                }
            }
            if (!empty($eligibleById)) {
                $variantRows = array_values(array_filter($variantRows, static function (array $row) use ($eligibleById): bool {
                    $pid = isset($row['product_id']) ? (int)$row['product_id'] : 0;
                    return $pid > 0 && isset($eligibleById[$pid]);
                }));
            } else {
                $variantRows = [];
            }
        }
        foreach ($variantRows as $row) {
            $description = $row['default_description'] ?? '';
            $variants[] = [
                'product_id' => isset($row['product_id']) ? (int)$row['product_id'] : 0,
                'code' => (string)($row['code'] ?? $code),
                'category' => (string)($row['category'] ?? ''),
                'provider' => (string)($row['provider'] ?? ''),
                'requires_quote' => !empty($row['requires_quote']),
                'default_term' => isset($row['default_term']) && $row['default_term'] !== '' ? (int)$row['default_term'] : null,
                'term' => isset($row['default_term']) && $row['default_term'] !== '' ? (int)$row['default_term'] : null,
                'label' => $row['product_name'] ?? $code,
                'price' => isset($row['default_price']) && $row['default_price'] !== '' ? (float)$row['default_price'] : 0.0,
                'description' => $description,
                'summary' => summarize_coverage_text($description),
            ];
        }
        if (!empty($variants)) {
            $deduped = [];
            $seen = [];
            foreach ($variants as $variant) {
                $variantKey = ($variant['label'] ?? '') . '|' . ($variant['price'] ?? '') . '|' . ($variant['description'] ?? '');
                if ($variantKey !== '' && isset($seen[$variantKey])) {
                    continue;
                }
                $seen[$variantKey] = true;
                $deduped[] = $variant;
            }
            $variants = $deduped;
        }
    }
    if (empty($variants)) {
        if ($optionSet && $key === 'filmprotection') {
            $variants = [
                [
                    'label' => 'Standard Coverage',
                    'price' => 2048.00,
                    'description' => 'Front bumper, hood, mirrors and door cups protected.',
                    'summary' => summarize_coverage_text('Front bumper, hood, mirrors and door cups protected.'),
                ],
                [
                    'label' => 'Premium Coverage',
                    'price' => 2895.00,
                    'description' => 'Premium protects full hood, bumper, mirrors, and select sides.',
                    'summary' => summarize_coverage_text('Premium protects full hood, bumper, mirrors, and select sides.'),
                ],
                [
                    'label' => 'Premium Plus',
                    'price' => 3595.00,
                    'description' => 'Adds full door coverage and mirror extensions.',
                    'summary' => summarize_coverage_text('Adds full door coverage and mirror extensions.'),
                ],
                [
                    'label' => 'Full Vehicle Wrap',
                    'price' => 7000.00,
                    'description' => 'Complete coverage including roof, doors, and lower panels.',
                    'summary' => summarize_coverage_text('Complete coverage including roof, doors, and lower panels.'),
                ],
            ];
        } else {
            $cache[$key] = [];
            return [];
        }
    }
    $cache[$key] = $variants;
    return $variants;
}

function term_selection_key(string $code, int $months): string {
    return 'term_' . strtolower(trim($code)) . '_' . $months;
}

function parse_term_selections(array $selected): array {
    // Supports:
    // - term_<code>_<months>
    // - term_<code>_<months>_<kms>
    // where <code> may contain underscores.
    $map = [];
    foreach ($selected as $entry) {
        if (!is_string($entry)) continue;
        $s = strtolower(trim($entry));
        if ($s === '' || !str_starts_with($s, 'term_')) continue;

        if (!preg_match('/^term_(.+)_(\\d+)(?:_(\\d+))?$/', $s, $m)) {
            continue;
        }
        $code = strtolower(trim((string)($m[1] ?? '')));
        $months = (int)($m[2] ?? 0);
        $kms = isset($m[3]) ? (int)$m[3] : 0;
        if ($code === '' || $months <= 0) continue;

        $key = $kms > 0 ? ($months . '_' . $kms) : (string)$months;
        $map[$code] = [
            'key' => $key,
            'months' => $months,
            'kms' => $kms > 0 ? $kms : null,
        ];
    }
    return $map;
}

function build_term_label(int $months): string {
    if ($months <= 0) {
        return '';
    }
    if ($months % 12 === 0) {
        $years = (int)($months / 12);
        return $years === 1 ? '1 Year' : $years . ' Years';
    }
    return $months . ' Months';
}

function build_term_kms_label(int $months, ?int $kms): string {
    $base = build_term_label($months);
    if ($kms !== null && $kms > 0) {
        $base .= ' / ' . number_format($kms) . ' km';
    }
    return $base;
}

function parse_term_variant_key_months(string $key): ?int {
    $key = trim((string)$key);
    if ($key === '') return null;
    $parts = explode('_', $key);
    $months = isset($parts[0]) ? (int)$parts[0] : 0;
    return $months > 0 ? $months : null;
}

function parse_term_months_from_label(string $label): ?int {
    $label = trim($label);
    if ($label === '') return null;
    if (preg_match('/\b(\d{2,3})\s*(?:months?|mos?)\b/i', $label, $m)) {
        $months = (int)$m[1];
        return $months > 0 ? $months : null;
    }
    if (preg_match('/\b(\d)\s*(?:years?|yrs?)\b/i', $label, $m)) {
        $years = (int)$m[1];
        return $years > 0 ? ($years * 12) : null;
    }
    return null;
}

function extract_variant_term_months(array $variant, string $fallbackKey = ''): ?int {
    if (isset($variant['term']) && is_numeric($variant['term'])) {
        $months = (int)$variant['term'];
        if ($months > 0) return $months;
    }
    if (isset($variant['default_term']) && is_numeric($variant['default_term'])) {
        $months = (int)$variant['default_term'];
        if ($months > 0) return $months;
    }
    if ($fallbackKey !== '') {
        $fromKey = parse_term_variant_key_months($fallbackKey);
        if ($fromKey !== null) return $fromKey;
    }
    return parse_term_months_from_label((string)($variant['label'] ?? ''));
}

function preferred_term_months_for_product_code(string $code): ?int {
    $normalized = strtolower(trim($code));
    if (in_array($normalized, ['assetprotection', 'cap'], true)) {
        return 60;
    }
    return null;
}

function pick_package_variant_key_for_months(array $variants, int $months): ?string {
    if ($months <= 0 || empty($variants)) return null;
    foreach ($variants as $key => $variant) {
        $variantMonths = extract_variant_term_months((array)$variant, (string)$key);
        if ($variantMonths === $months) {
            return (string)$key;
        }
    }
    return null;
}

function pick_package_variant_key_for_label_contains(array $variants, string $needle): ?string {
    $needle = strtolower(trim($needle));
    if ($needle === '' || empty($variants)) return null;
    foreach ($variants as $key => $variant) {
        $label = strtolower(trim((string)($variant['label'] ?? '')));
        if ($label !== '' && str_contains($label, $needle)) {
            return (string)$key;
        }
    }
    return null;
}

function pick_term_variant_key_for_months(array $termSet, int $months, bool $preferMaxKms = true): ?string {
    if ($months <= 0 || empty($termSet)) return null;
    $candidates = [];
    foreach ($termSet as $k => $row) {
        $k = (string)$k;
        $term = is_array($row) && isset($row['term']) && is_numeric($row['term'])
            ? (int)$row['term']
            : (parse_term_variant_key_months($k) ?? 0);
        if ($term === $months) {
            $kms = null;
            if (is_array($row) && array_key_exists('coverage_kms', $row)) {
                $kms = is_numeric($row['coverage_kms']) ? (int)$row['coverage_kms'] : null;
            }
            $candidates[] = ['key' => $k, 'kms' => $kms];
        }
    }
    if (empty($candidates)) return null;
    if ($preferMaxKms) {
        usort($candidates, function ($a, $b) {
            $ka = (int)($a['kms'] ?? 0);
            $kb = (int)($b['kms'] ?? 0);
            if ($ka !== $kb) return $kb <=> $ka;
            return strcmp((string)$a['key'], (string)$b['key']);
        });
    }
    return (string)($candidates[0]['key'] ?? '');
}

function resolve_scope_candidates(PDO $db, ?int $organizationId): array {
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
    return $candidates;
}

if (!function_exists('resolve_default_term_for_product')) {
    function resolve_default_term_for_product(PDO $db, int $productId, ?int $organizationId): ?int {
        if ($productId <= 0) {
            return null;
        }
        static $cache = [];
        $cacheKey = $productId . '|' . (string)($organizationId ?? 0);
        if (array_key_exists($cacheKey, $cache)) {
            return $cache[$cacheKey];
        }
        if (!column_exists($db, 'product_term_defaults', 'product_id')) {
            $cache[$cacheKey] = null;
            return null;
        }
        $candidates = resolve_scope_candidates($db, $organizationId);
        foreach ($candidates as [$scopeType, $scopeValue]) {
            $stmt = $db->prepare("
                SELECT coverage_term
                FROM product_term_defaults
                WHERE product_id = ?
                  AND scope_type = ?
                  AND scope_value = ?
                LIMIT 1
            ");
            $stmt->execute([$productId, $scopeType, $scopeValue]);
            $term = $stmt->fetchColumn();
            if ($term !== false && $term !== null) {
                $cache[$cacheKey] = (int)$term;
                return (int)$term;
            }
        }
        $cache[$cacheKey] = null;
        return null;
    }
}

if (!function_exists('resolve_vehicle_age_years')) {
    function resolve_vehicle_age_years(?string $inServiceDate, ?int $vehicleYear): ?int {
        $inServiceDate = $inServiceDate ? trim($inServiceDate) : '';
        if ($inServiceDate !== '') {
            try {
                $serviceDate = new DateTime($inServiceDate);
                $now = new DateTime(date('Y-m-d'));
                if ($serviceDate <= $now) {
                    return (int)$serviceDate->diff($now)->y;
                }
            } catch (Exception $e) {
                // Fall back to model year.
            }
        }
        if ($vehicleYear !== null && $vehicleYear > 0) {
            $currentYear = (int)date('Y');
            $age = $currentYear - $vehicleYear;
            return $age >= 0 ? $age : null;
        }
        return null;
    }
}

function load_product_term_variants(PDO $db, array $product, array $deal, ?int $organizationId = null): array {
    $productId = (int)($product['id'] ?? 0);
    if ($productId <= 0) {
        return [];
    }
    $vehicleMakeId = isset($deal['vehicle_make_id']) ? (int)$deal['vehicle_make_id'] : 0;
    $vehicleModelId = isset($deal['vehicle_model_id']) ? (int)$deal['vehicle_model_id'] : 0;
    $vehicleTrimId = isset($deal['vehicle_trim_id']) ? (int)$deal['vehicle_trim_id'] : 0;
    $vehicleModelId = $vehicleModelId > 0 ? $vehicleModelId : 0;
    $vehicleTrimId = $vehicleTrimId > 0 ? $vehicleTrimId : 0;

	    // Prefer the existing make/model/trim term variants when they exist.
	    if ($vehicleMakeId > 0) {
	        try {
	            $hasScopeType = column_exists($db, 'product_vehicle_pricing_overrides', 'scope_type');
	            $hasScopeValue = column_exists($db, 'product_vehicle_pricing_overrides', 'scope_value');
	            $hasTermLabel = column_exists($db, 'product_vehicle_pricing_overrides', 'term_label');
	            $hasCoverageKms = column_exists($db, 'product_vehicle_pricing_overrides', 'coverage_kms');
	            $selectCols = "price, coverage_term";
	            $selectCols .= $hasCoverageKms ? ", coverage_kms" : ", NULL AS coverage_kms";
	            $selectCols .= $hasTermLabel ? ", term_label" : ", NULL AS term_label";
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
                END AS specificity,
                    created_at
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
        } catch (PDOException $e) {
            $rows = [];
        }

        if (!empty($rows)) {
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
        }

        if (!empty($rows)) {
            $candidates = resolve_scope_candidates($db, $organizationId);
            $scopeRank = [];
            foreach ($candidates as $idx => $entry) {
                $scopeRank[$entry[0] . '|' . $entry[1]] = $idx;
            }
            $filteredRows = [];
            $minRank = null;
            foreach ($rows as $row) {
                $key = ($row['scope_type'] ?? 'global') . '|' . ($row['scope_value'] ?? '');
                if (!array_key_exists($key, $scopeRank)) {
                    continue;
                }
                $rank = $scopeRank[$key];
                if ($minRank === null || $rank < $minRank) {
                    $minRank = $rank;
                    $filteredRows = [$row];
                } elseif ($rank === $minRank) {
                    $filteredRows[] = $row;
                }
            }
	            $byTerm = [];
	            foreach ($filteredRows as $row) {
	                $term = isset($row['coverage_term']) ? (int)$row['coverage_term'] : 0;
	                if ($term <= 0) {
	                    continue;
	                }
	                $kms = isset($row['coverage_kms']) ? (int)$row['coverage_kms'] : 0;
	                $key = $kms > 0 ? ($term . '_' . $kms) : (string)$term;
	                if (!isset($byTerm[$key])) {
	                    $byTerm[$key] = $row;
	                    continue;
	                }
	                $existingSpec = isset($byTerm[$key]['specificity']) ? (int)$byTerm[$key]['specificity'] : 0;
	                $spec = isset($row['specificity']) ? (int)$row['specificity'] : 0;
	                if ($spec > $existingSpec) {
	                    $byTerm[$key] = $row;
	                }
	            }
	            if (!empty($byTerm)) {
	                uksort($byTerm, function ($a, $b) {
	                    $pa = array_map('intval', explode('_', (string)$a));
	                    $pb = array_map('intval', explode('_', (string)$b));
	                    $ta = $pa[0] ?? 0;
	                    $tb = $pb[0] ?? 0;
	                    if ($ta !== $tb) return $ta <=> $tb;
	                    $ka = $pa[1] ?? 0;
	                    $kb = $pb[1] ?? 0;
	                    return $ka <=> $kb;
	                });
	                $variants = [];
	                foreach ($byTerm as $variantKey => $row) {
	                    $term = isset($row['coverage_term']) ? (int)$row['coverage_term'] : 0;
	                    $kms = isset($row['coverage_kms']) ? (int)$row['coverage_kms'] : 0;
	                    $kms = $kms > 0 ? $kms : null;
	                    $label = trim((string)($row['term_label'] ?? ''));
	                    if ($label === '') {
	                        $label = build_term_kms_label((int)$term, $kms);
	                    } elseif ($kms !== null && stripos($label, 'km') === false) {
	                        $label = trim($label) . ' / ' . number_format($kms) . ' km';
	                    }
	                    $price = isset($row['price']) ? (float)$row['price'] : 0.0;
	                    $variants[(string)$variantKey] = [
	                        'label' => $label ?: ($term . ' months'),
	                        'price' => $price,
	                        'description' => $product['default_description'] ?? ($product['description'] ?? ''),
	                        'summary' => summarize_coverage_text($product['default_description'] ?? ($product['description'] ?? '')),
	                        'term' => (int)$term,
	                        'coverage_kms' => $kms,
	                    ];
	                }
	                return $variants;
	            }
	        }
	    }

    // Fallback: deal-based term variants (vehicle price / loan amount bands).
    if (!column_exists($db, 'product_deal_pricing_rules', 'product_id')) {
        return [];
    }

	    $includedTotal = 0.0;
	    if (function_exists('parse_included_protections') && function_exists('summarize_included_protections')) {
	        $items = parse_included_protections($deal['included_protections'] ?? null);
	        $summary = summarize_included_protections($items);
	        $includedTotal = (float)($summary['total'] ?? 0.0);
	    }
	    $vehiclePrice = isset($deal['sale_price']) ? (float)$deal['sale_price'] : null;
	    $loanAmount = function_exists('estimate_amount_financed')
        ? estimate_amount_financed($deal, $includedTotal + (float)($accessoryTotalRolledIn ?? 0.0))
        : null;
	    $dealType = strtolower(trim((string)($deal['deal_type'] ?? '')));

    $candidates = resolve_scope_candidates($db, $organizationId);
    $conds = [];
    $params = [$productId];
    foreach ($candidates as [$scopeType, $scopeValue]) {
        $conds[] = "(scope_type = ? AND scope_value = ?)";
        $params[] = $scopeType;
        $params[] = $scopeValue;
    }
    if (empty($conds)) {
        return [];
    }

    try {
        $stmt = $db->prepare("
            SELECT
                scope_type,
                scope_value,
                deal_type,
                min_vehicle_price,
                max_vehicle_price,
                min_loan_amount,
                max_loan_amount,
                coverage_term,
                label,
                override_price,
                created_at
            FROM product_deal_pricing_rules
            WHERE product_id = ?
              AND (" . implode(' OR ', $conds) . ")
            ORDER BY created_at DESC
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
    if (empty($rows)) {
        return [];
    }

    $scopeRank = [];
    foreach ($candidates as $idx => $entry) {
        $scopeRank[$entry[0] . '|' . $entry[1]] = $idx;
    }

    $matchedRows = [];
    foreach ($rows as $row) {
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
            if (!deal_pricing_rule_matches($vehiclePrice, $minVehicle, $maxVehicle)) {
                continue;
            }
            if (!deal_pricing_rule_matches($loanAmount, $minLoan, $maxLoan)) {
                continue;
            }
        }

        $matchedRows[] = $row;
    }
    if (empty($matchedRows)) {
        return [];
    }

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
        if ($coverage <= 0) {
            continue;
        }
        $rowsByCoverage[$coverage][] = $row;
    }
    if (empty($rowsByCoverage)) {
        return [];
    }

    ksort($rowsByCoverage);
    $variants = [];
    foreach ($rowsByCoverage as $coverage => $bucket) {
        $best = function_exists('choose_best_deal_pricing_rule') ? choose_best_deal_pricing_rule($bucket) : (reset($bucket) ?: null);
        if (!$best) {
            continue;
        }
        $label = trim((string)($best['label'] ?? ''));
        if ($label === '') {
            $label = build_term_label((int)$coverage);
        }
        $price = isset($best['override_price']) ? (float)$best['override_price'] : 0.0;
        $variants[(string)$coverage] = [
            'label' => $label ?: ($coverage . ' months'),
            'price' => $price,
            'description' => $product['default_description'] ?? ($product['description'] ?? ''),
            'summary' => summarize_coverage_text($product['default_description'] ?? ($product['description'] ?? '')),
            'term' => (int)$coverage,
        ];
    }
    return $variants;
}
