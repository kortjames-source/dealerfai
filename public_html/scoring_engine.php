<?php
require_once __DIR__ . '/vendor/autoload.php';
include_once __DIR__ . '/protection_helpers.php';
require_once __DIR__ . '/accessory_helpers.php';
require_once __DIR__ . '/helpers/db_utils.php';
require_once __DIR__ . '/helpers/customer_types.php';
require_once __DIR__ . '/helpers/usage_data.php';
require_once __DIR__ . '/helpers/scoring_ai_queue.php';
require_once __DIR__ . '/helpers/ai_worker_kick.php';
require_once __DIR__ . '/helpers/scoring_pricing.php';

$apiKey = $_ENV['GEMINI_API_KEY'] ?? getenv('GEMINI_API_KEY');
$secureKeyPath = __DIR__ . '/../secure/api_keys.php';
if (file_exists($secureKeyPath)) {
    include_once $secureKeyPath;
    if (defined('GEMINI_API_KEY') && GEMINI_API_KEY) {
        $apiKey = GEMINI_API_KEY;
    }
}

function scoring_debug(string $message): void {
    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0750, true);
    }
    $logPath = $logDir . '/scoring.log';
    $timestamp = date('Y-m-d H:i:s');
    $line = '[' . $timestamp . '] ' . $message . PHP_EOL;
    $result = @file_put_contents($logPath, $line, FILE_APPEND | LOCK_EX);
    if ($result === false) {
        error_log('Scoring log write failed: ' . $logPath);
    }
}

function scoring_request_context_line(array $extra = []): string {
    $normalize = function ($value): string {
        $text = preg_replace('/\s+/', ' ', (string)$value);
        if ($text === null) {
            $text = '';
        }
        return substr($text, 0, 200);
    };

    $parts = [];
    $parts[] = 'ip=' . $normalize($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $parts[] = 'ua=' . $normalize($_SERVER['HTTP_USER_AGENT'] ?? 'unknown');
    $parts[] = 'uri=' . $normalize($_SERVER['REQUEST_URI'] ?? 'unknown');

    if (session_status() === PHP_SESSION_ACTIVE) {
        $userId = $_SESSION['user_id'] ?? null;
        $orgId = $_SESSION['organization'] ?? null;
        if ($userId !== null) {
            $parts[] = 'user_id=' . $normalize($userId);
        }
        if ($orgId !== null) {
            $parts[] = 'org_id=' . $normalize($orgId);
        }
    }

    foreach ($extra as $key => $value) {
        if ($value === null || $value === '') {
            continue;
        }
        $parts[] = $normalize($key) . '=' . $normalize($value);
    }

    return implode(', ', $parts);
}

function score_and_store_recommendations(PDO $db, int $deal_id): void {
    if (function_exists('set_time_limit')) {
        @set_time_limit(120);
    }
    // Step 1: Fetch deal and application info
    $dealColumns = select_existing_columns($db, 'deals', [
        'id', 'organization', 'salesperson_id', 'customer_name', 'customer_type', 'deal_type',
        'province', 'sale_price', 'term', 'interest_rate', 'payment_frequency',
        'down_payment', 'trade_value', 'lien_amount', 'documentation_fee', 'ppsa_fee',
        'residual', 'msrp', 'included_protections', 'customer_context',
        'vehicle_make', 'vehicle_make_id', 'vehicle_model', 'vehicle_model_id', 'vehicle_trim_id',
        'vehicle_condition', 'vehicle_year', 'vehicle_kms', 'vehicle_colour', 'vin',
        'in_service_date', 'trade_info'
    ]);
    $dealSelect = !empty($dealColumns) ? implode(', ', $dealColumns) : '*';
    $dealStmt = $db->prepare("SELECT {$dealSelect} FROM deals WHERE id = ?");
    $dealStmt->execute([$deal_id]);
    $deal = $dealStmt->fetch(PDO::FETCH_ASSOC);
    if (!$deal) return;

    $appColumns = select_existing_columns($db, 'applications', [
        'id', 'deal_id', 'usage_data', 'annual_km', 'ownership_length',
        'province', 'housing', 'postal_code', 'vehicle_make', 'vehicle_model'
    ]);
    $appSelect = !empty($appColumns) ? implode(', ', $appColumns) : '*';
    $appStmt = $db->prepare("SELECT {$appSelect} FROM applications WHERE deal_id = ?");
    $appStmt->execute([$deal_id]);
    $application = $appStmt->fetch(PDO::FETCH_ASSOC);
    if (!$application) return;

    // Step 2: Resolve vehicle make for fitment/pricing lookups.
    $vehicleInfo = resolve_vehicle_make_info($db, $deal, $application);
    $preferredProvider = resolve_warranty_provider_preference($vehicleInfo['name'] ?? null);
    $vehicleMakeId = $vehicleInfo['id'];
    $vehicleModelId = isset($deal['vehicle_model_id']) ? (int)$deal['vehicle_model_id'] : null;
    if ($vehicleModelId !== null && $vehicleModelId <= 0) {
        $vehicleModelId = null;
    }
    $vehicleTrimId = isset($deal['vehicle_trim_id']) ? (int)$deal['vehicle_trim_id'] : null;
    if ($vehicleTrimId !== null && $vehicleTrimId <= 0) {
        $vehicleTrimId = null;
    }
    // Ensure organizationId is an integer (0 if missing) to prevent DB errors and visibility issues
    $organizationId = !empty($deal['organization']) ? (int)$deal['organization'] : 0;
    $dealOrganizationId = isset($deal['organization']) ? (int)$deal['organization'] : 0;
    $aiReasoningEnabled = function_exists('is_ai_reasoning_enabled')
        ? is_ai_reasoning_enabled($db, $organizationId)
        : true;

    // Step 4: Load products eligible for this deal
    $dealType = strtolower($deal['deal_type'] ?? '');
    $customerType = normalize_customer_type($deal['customer_type'] ?? 'personal');
	    $products = fetch_products(
	        $db,
	        $dealType,
	        $preferredProvider,
	        $organizationId,
	        $vehicleMakeId,
	        $vehicleModelId,
	        $vehicleTrimId,
	        $deal['vehicle_condition'] ?? null,
	        $customerType,
	        $deal
	    );
    scoring_debug("Scoring engine found " . count($products) . " products for deal {$deal_id} (deal type: {$dealType})");

    $includedProtections = function_exists('parse_included_protections')
        ? parse_included_protections($deal['included_protections'] ?? null)
        : [];
    $includedSummary = function_exists('summarize_included_protections')
        ? summarize_included_protections($includedProtections)
        : ['codes' => []];
    $includedTotal = function_exists('summarize_included_protections')
        ? (float)($includedSummary['total'] ?? 0.0)
        : 0.0;
    $includedCodeLookup = [];
    foreach (($includedSummary['codes'] ?? []) as $code) {
        $key = strtolower(trim((string)$code));
        if ($key !== '') {
            $includedCodeLookup[$key] = true;
        }
    }
    if (!empty($includedCodeLookup)) {
        $products = array_values(array_filter($products, function ($product) use ($includedCodeLookup) {
            $codeKey = strtolower(trim((string)($product['code'] ?? '')));
            return $codeKey === '' || !isset($includedCodeLookup[$codeKey]);
        }));
        scoring_debug("Filtered included protections from recommendations: " . count($products) . " products remain.");
    }

	    $voicePrompts = load_org_voice_prompts($db, $organizationId);
	    $actionRules = load_action_rules($db, $organizationId);
	    $actionSignals = !empty($actionRules)
	        ? build_action_rule_signal_context($db, $deal, $application, (float)$includedTotal)
	        : [];

	    // Step 5: Clear existing recommendations
	    $db->prepare("DELETE FROM product_recommendations WHERE deal_id = ?")->execute([$deal_id]);
	    $db->prepare("DELETE FROM recommendation_scoring_log WHERE deal_id = ?")->execute([$deal_id]);

    $vehicleConditionForRecommendations = strtolower(trim((string)($deal['vehicle_condition'] ?? '')));

    // Step 6: Score each product
    $profileSummary = dealerfai_build_customer_profile_summary($application);
    $dealContext = build_deal_context($deal);
    $customerContextRaw = '';
    try {
        if (function_exists('column_exists') && column_exists($db, 'deals', 'customer_context')) {
            $customerContextRaw = (string)($deal['customer_context'] ?? '');
        }
    } catch (\Throwable $e) {
        $customerContextRaw = '';
    }

    $logInsert = $db->prepare("
        INSERT INTO recommendation_scoring_log
          (deal_id, product_code, product_name, score, reasoning, organization_id, province, brand, created_at)
        VALUES
          (?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $recommendationInsert = $db->prepare("
        INSERT INTO product_recommendations
          (deal_id, product_code, product_name, sale_price, description, score, ai_explanation)
        VALUES
          (?, ?, ?, ?, ?, ?, ?)
    ");

	    foreach ($products as $product) {
	        scoring_debug("Scoring {$product['code']} via direct action rules");

        $productCodeKey = strtolower(trim((string)($product['code'] ?? '')));
        $productNameLower = strtolower(trim((string)($product['name'] ?? '')));
        $productProviderLower = strtolower(trim((string)($product['provider'] ?? '')));
        if (
            $vehicleConditionForRecommendations === 'new'
            && in_array($productCodeKey, ['extwarranty', 'warranty'], true)
            && (str_contains($productNameLower, 'cpo') || str_contains($productProviderLower, 'cpo'))
        ) {
            scoring_debug("Skipping {$product['code']} because CPO warranty variants are not eligible for new vehicles");
            continue;
        }

        $productId = isset($product['id']) ? (int)$product['id'] : 0;
        $defaultTerm = $productId > 0 ? resolve_default_term_for_product($db, $productId, $dealOrganizationId > 0 ? $dealOrganizationId : null) : null;
        $pricingTerm = $defaultTerm ?? (int)($deal['term'] ?? 0);
        $product = apply_pricing_override($db, $product, $vehicleMakeId, $vehicleModelId, $vehicleTrimId, $pricingTerm, $deal, $dealOrganizationId > 0 ? $dealOrganizationId : null);
        $product = apply_deal_pricing_rules(
            $db,
            $product,
            $pricingTerm,
            $deal,
            $dealOrganizationId > 0 ? $dealOrganizationId : null,
            $includedTotal
        );
	        $score = 0;
	        $excluded = false;
	        $matchedDetails = [];
	        $actionRuleResult = !empty($actionRules) && !empty($actionSignals)
	            ? apply_action_rules_to_target($actionRules, $actionSignals, 'product', (string)($product['code'] ?? ''))
	            : ['score_delta' => 0, 'excluded' => false, 'matched_count' => 0, 'matched' => []];
	        if (!empty($actionRuleResult['excluded'])) {
	            $excluded = true;
	        } elseif (!empty($actionRuleResult['score_delta'])) {
	            $score += (int)$actionRuleResult['score_delta'];
	        }

	        $logReasonParts = [];
	        if (!empty($actionRuleResult['matched_count'])) {
	            $delta = (int)($actionRuleResult['score_delta'] ?? 0);
	            $logReasonParts[] = 'Action rules: ' . ($delta >= 0 ? '+' : '') . $delta . ' (' . (int)$actionRuleResult['matched_count'] . ' matched)';
	            if (!empty($actionRuleResult['matched'])) {
	                $logReasonParts[] = 'Action matches: ' . implode(', ', array_slice((array)$actionRuleResult['matched'], 0, 5));
	            }
	        }
	        if (!empty($matchedDetails)) {
	            $logReasonParts[] = 'Details: ' . implode(', ', $matchedDetails);
	        }
	        if ($excluded) {
	            $explain = (!empty($actionRuleResult['excluded']) ? 'Excluded by action rule' : 'Excluded by rule');
	            array_unshift($logReasonParts, $explain);
	        } elseif ($score <= 0 && empty($logReasonParts)) {
	            $logReasonParts[] = 'Score 0';
	        }
	        $logReason = !empty($logReasonParts) ? implode(' | ', $logReasonParts) : null;
        $logScore = $excluded ? 0 : $score;

        try {
            if (!$logInsert->execute([
                $deal_id,
                $product['code'] ?? null,
                $product['name'] ?? null,
                $logScore,
                $logReason,
                $organizationId,
                $deal['province'] ?? null,
                $vehicleInfo['name'] ?? null
            ])) {
                $err = $logInsert->errorInfo();
                scoring_debug("❌ DB Insert Failed: " . implode(' ', $err));
            } else {
                scoring_debug("✅ DB Insert Success: {$product['code']} (Org: $organizationId)");
                if ($organizationId === 0) {
                    scoring_debug("   ⚠️ Note: Org 0 means this deal is Global/Unassigned. It may be hidden in Store views.");
                }
            }
        } catch (PDOException $e) {
            error_log("Unable to log scoring for {$product['code']}: " . $e->getMessage());
            scoring_debug("❌ DB Insert Error for {$product['code']}: " . $e->getMessage());
        }

        if ($excluded) {
            error_log("Skipping {$product['code']} due to exclusion rule");
            continue;
        }
        if ($score <= 0) {
            error_log("Skipping {$product['code']} with score {$score}");
            continue;
        }

        // Step 7: Optional AI explanation (async queue preferred).
        $ai_explanation = null;
        if ($aiReasoningEnabled) {
            $aiContext = array_merge($voicePrompts, [
                'profile_summary' => $profileSummary,
                'matched_details' => array_slice($matchedDetails, 0, 10),
                'rule_hints' => array_slice((array)($actionRuleResult['hints'] ?? []), 0, 10),
                'deal_context' => $dealContext['deal_context'],
                'financial_summary' => $dealContext['financial_summary'],
                'customer_context_raw' => $customerContextRaw,
                'deal_id' => $deal_id,
            ]);
            $queued = false;
            try {
                $queued = enqueue_ai_explanation_job($db, (int)$deal_id, $product, $aiContext, []);
            } catch (\Throwable $e) {
                $queued = false;
            }
            if (!$queued && scoring_ai_mode() !== 'off') {
	            try {
	                $ai_explanation = generate_ai_explanation($product, $aiContext, $db);
	            } catch (\Throwable $e) {
                    error_log("AI Explanation failed for {$product['code']}: " . $e->getMessage());
                    scoring_debug("⚠️ AI Explanation failed for {$product['code']}: " . $e->getMessage());
                    $ai_explanation = null;
                }
            }
        }
        // Step 8: Insert into recommendations table
        scoring_debug("Saving recommendation for {$product['code']}. Score: {$score}");
        $salePrice = $product['sale_price'] ?? $product['default_price'] ?? 0;
        $description = $product['description']
            ?? $product['default_description']
            ?? $product['default_reason']
            ?? $ai_explanation
            ?? '';
        $recommendationInsert->execute([
            $deal_id,
            $product['code'],
            $product['name'],
            $salePrice,
            $description,
            $score,
            $ai_explanation
        ]);
    }

    // Kick one background worker pass so queued AI text starts right away.
    if ($aiReasoningEnabled) {
        try {
            dealerfai_kick_ai_worker($db, 20);
        } catch (Throwable $e) {
            // Non-blocking optimization only.
        }
    }

    // Step 9: Score accessories (recommended add-ons) using action rules.
    // This is separate from product_recommendations and is keyed to deal_id.
    try {
        $db->prepare("DELETE FROM accessory_recommendations WHERE deal_id = ?")->execute([$deal_id]);
    } catch (PDOException $e) {
        // Table may not exist pre-migration; don't break product recommendations.
        return;
    }

    if (function_exists('is_accessories_enabled') && !is_accessories_enabled($db, $organizationId)) {
        return;
    }

    $contextHints = function_exists('extract_customer_context_hints')
        ? extract_customer_context_hints((string)$customerContextRaw)
        : [];

    $accessories = [];
    try {
        if (function_exists('fetch_accessories_for_org') && $organizationId > 0) {
            $accessories = fetch_accessories_for_org($db, $organizationId);
        }
    } catch (Throwable $e) {
        $accessories = [];
    }
    if (empty($accessories)) {
        return;
    }

    $accessoryIds = array_values(array_filter(array_map(static fn($row) => (int)($row['id'] ?? 0), $accessories)));
    $fitmentMap = [];
    if (!empty($accessoryIds)) {
        $placeholders = implode(',', array_fill(0, count($accessoryIds), '?'));
        try {
            $fitStmt = $db->prepare("
                SELECT accessory_id, make_id, model_id, trim_id, brand, model, min_year, max_year
                FROM accessory_fitment
                WHERE accessory_id IN ($placeholders)
            ");
            $fitStmt->execute($accessoryIds);
            while ($row = $fitStmt->fetch(PDO::FETCH_ASSOC)) {
                $aid = (int)($row['accessory_id'] ?? 0);
                if ($aid > 0) {
                    $fitmentMap[$aid][] = $row;
                }
            }
        } catch (PDOException $e) {
            $fitmentMap = [];
        }
    }

    $vehicleMake = trim((string)($deal['vehicle_make'] ?? $application['vehicle_make'] ?? ''));
    $vehicleModel = trim((string)($deal['vehicle_model'] ?? $application['vehicle_model'] ?? ''));
    $vehicleYear = isset($deal['vehicle_year']) && $deal['vehicle_year'] !== null && $deal['vehicle_year'] !== ''
        ? (int)$deal['vehicle_year']
        : null;
    $vehicleMakeId = isset($deal['vehicle_make_id']) ? (int)$deal['vehicle_make_id'] : 0;
    $vehicleModelId = isset($deal['vehicle_model_id']) ? (int)$deal['vehicle_model_id'] : 0;
    $vehicleTrimId = isset($deal['vehicle_trim_id']) ? (int)$deal['vehicle_trim_id'] : 0;
    $vehicleMakeId = $vehicleMakeId > 0 ? $vehicleMakeId : null;
    $vehicleModelId = $vehicleModelId > 0 ? $vehicleModelId : null;
    $vehicleTrimId = $vehicleTrimId > 0 ? $vehicleTrimId : null;
	    $insertAcc = $db->prepare("
	        INSERT INTO accessory_recommendations
	          (deal_id, accessory_id, accessory_code, provider, accessory_name, category, base_price, score, reasoning, created_at)
	        VALUES
          (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");

	    foreach ($accessories as $accessory) {
        $aid = (int)($accessory['id'] ?? 0);
        if ($aid <= 0) {
            continue;
        }
        $code = trim((string)($accessory['code'] ?? ''));
        if ($code === '') {
            continue;
        }

        // Fitment filter first (if any fitment rows exist, vehicle must match at least one).
        $fits = $fitmentMap[$aid] ?? [];
        if (function_exists('accessory_matches_fitment')) {
            $ok = accessory_matches_fitment($fits, $vehicleMake, $vehicleModel, $vehicleYear, $vehicleMakeId, $vehicleModelId, $vehicleTrimId);
            if (!$ok) {
                continue;
            }
        }

	        $excluded = false;
	        $score = 0;
	        $matchedTags = [];
	        $matchedDetails = [];

	        $actionRuleResult = !empty($actionRules) && !empty($actionSignals)
	            ? apply_action_rules_to_target($actionRules, $actionSignals, 'accessory', $code)
	            : ['score_delta' => 0, 'excluded' => false, 'matched_count' => 0, 'matched' => [], 'hints' => []];
	        if (!empty($actionRuleResult['excluded'])) {
	            $excluded = true;
	        } elseif (!empty($actionRuleResult['score_delta'])) {
	            $score += (int)$actionRuleResult['score_delta'];
	        }
	        $matchedTags = array_values(array_filter(array_map(
	            static fn($v) => trim((string)$v),
	            (array)($actionRuleResult['matched'] ?? [])
	        ), static fn($v) => $v !== ''));
	        $matchedDetails = array_values(array_filter(array_map(
	            static fn($v) => trim((string)$v),
	            (array)($actionRuleResult['hints'] ?? [])
	        ), static fn($v) => $v !== ''));

	        if ($excluded || $score <= 0) {
	            continue;
	        }

        $reason = function_exists('build_accessory_personalized_reason')
            ? build_accessory_personalized_reason($accessory, $matchedDetails, $matchedTags, $contextHints, $profileSummary)
            : '';

        $provider = trim((string)($accessory['provider'] ?? ''));
        $name = trim((string)($accessory['name'] ?? $code));
        if ($provider !== '') {
            $name .= ' (' . $provider . ')';
        }
        $category = trim((string)($accessory['category'] ?? ''));
        $category = $category !== '' ? $category : null;
        $basePrice = isset($accessory['base_price']) && $accessory['base_price'] !== '' ? $accessory['base_price'] : null;

        $insertAcc->execute([
            $deal_id,
            $aid,
            $code,
            $provider,
            $name,
            $category,
            $basePrice,
            $score,
            $reason !== '' ? $reason : null,
        ]);
    }
}

	function fetch_products(PDO $db, string $dealType, ?string $preferredProvider = null, ?int $organizationId = null, ?int $vehicleMakeId = null, ?int $vehicleModelId = null, ?int $vehicleTrimId = null, ?string $vehicleCondition = null, ?string $customerType = null, ?array $deal = null): array {
    $vehicleCondition = strtolower(trim((string)$vehicleCondition));
    if (!in_array($vehicleCondition, ['new', 'used'], true)) {
        $vehicleCondition = 'any';
    }
    $dealType = strtolower(trim($dealType));
    $customerType = normalize_customer_type($customerType ?? 'personal');
    $hasDealTypeAllowlist = function_exists('column_exists') ? column_exists($db, 'product_allowed_deal_types', 'deal_type') : false;
    $hasCustomerTypeAllowlist = function_exists('column_exists') ? column_exists($db, 'product_allowed_customer_types', 'customer_type') : false;
    if ($organizationId) {
        $orgMeta = get_organization_meta($db, $organizationId);
        $orgKind = $orgMeta['org_kind'] ?? 'store';
        $logicType = $orgMeta['logic_type'] ?? null;
        $orgPreferredProvider = trim((string)($orgMeta['preferred_provider'] ?? ''));
        if ($preferredProvider === null || trim($preferredProvider) === '') {
            $preferredProvider = $orgPreferredProvider !== '' ? $orgPreferredProvider : $preferredProvider;
        }
        $groupId = null;
        if ($orgKind === 'store' && !empty($orgMeta['parent_org_id'])) {
            $groupId = (int)$orgMeta['parent_org_id'];
        }
        $conditionParams = array_fill(0, 15, $vehicleCondition);
        $whereAllowedDealTypes = $hasDealTypeAllowlist
            ? " AND (
                    NOT EXISTS (
                        SELECT 1
                        FROM product_allowed_deal_types padt
                        WHERE padt.product_id = p.id
                    )
                    OR EXISTS (
                        SELECT 1
                        FROM product_allowed_deal_types padt
                        WHERE padt.product_id = p.id
                          AND padt.deal_type = ?
                    )
                )"
            : '';
        $needsDealTypeParam = $hasDealTypeAllowlist;
        $whereAllowedCustomerTypes = $hasCustomerTypeAllowlist
            ? " AND (
                    NOT EXISTS (
                        SELECT 1
                        FROM product_allowed_customer_types pact
                        WHERE pact.product_id = p.id
                    )
                    OR EXISTS (
                        SELECT 1
                        FROM product_allowed_customer_types pact
                        WHERE pact.product_id = p.id
                          AND pact.customer_type = ?
                    )
                )"
            : '';
        $needsCustomerTypeParam = $hasCustomerTypeAllowlist;

        $stmt = $db->prepare("
            SELECT
                   p.id,
                   p.code,
                   p.provider,
                   p.default_description,
                   p.default_reason,
                   p.default_price,
                   p.default_cost,
                   p.lease_cap_exempt,
                   p.finance_cap_exempt,
                   p.category,
                   p.requires_quote,
                   p.requires_selection,
                   p.option_set_id,
                   p.default_term_range,
                   p.eligible_verticals,
                   p.product_image_url,
                   p.product_video_url,
                   p.is_active,
                   COALESCE(o.custom_name, g.custom_name, p.name) AS name,
                   COALESCE(o.custom_description, g.custom_description, p.default_description) AS description,
                   COALESCE(o.custom_price, g.custom_price, p.default_price, 0) AS sale_price,
                   COALESCE(o.custom_cost, g.custom_cost, p.default_cost) AS default_cost,
                   COALESCE(o.reason_override, g.reason_override) AS reason_override,
                   COALESCE(g.custom_description, p.default_description, p.default_reason) AS approved_facts_default,
                   o.custom_description AS approved_facts_custom,
                   COALESCE(o.lease_cap_exempt_override, g.lease_cap_exempt_override, p.lease_cap_exempt) AS lease_cap_exempt,
                   COALESCE(o.finance_cap_exempt_override, g.finance_cap_exempt_override, p.finance_cap_exempt) AS finance_cap_exempt,
                   COALESCE(
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
                   ) AS availability_enabled
            FROM products p
            LEFT JOIN product_organization_overrides o
              ON o.product_id = p.id AND o.organization_id = ?
            LEFT JOIN product_organization_overrides g
              ON g.product_id = p.id AND g.organization_id = 0
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
            WHERE p.is_active = 1
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
              {$whereAllowedDealTypes}
              {$whereAllowedCustomerTypes}
        ");
        $stmt->execute(array_merge(
            $conditionParams,
            [
                $organizationId,
                (string)$organizationId,
                (string)$organizationId,
                (string)$organizationId,
                (string)$organizationId,
                (string)$organizationId,
                (string)$organizationId,
                $groupId === null ? '' : (string)$groupId,
                $groupId === null ? '' : (string)$groupId,
                $groupId === null ? '' : (string)$groupId,
                $logicType === null ? '' : $logicType,
                $logicType === null ? '' : $logicType,
                $logicType === null ? '' : $logicType,
            ],
            $conditionParams,
            $needsDealTypeParam ? [$dealType] : [],
            $needsCustomerTypeParam ? [$customerType] : []
        ));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $reasonOverride = parse_reason_override($row['reason_override'] ?? null);
            if ($reasonOverride !== null) {
                $row['default_reason'] = $reasonOverride;
            }
        }
        unset($row);
        $rows = filter_products_by_vehicle_eligibility($db, $rows, $vehicleMakeId, $vehicleModelId, $vehicleTrimId, is_array($deal) ? $deal : []);
        return unique_products_by_code($rows, $preferredProvider);
    }

    $whereAllowedDealTypesGlobal = $hasDealTypeAllowlist
        ? " AND (
                NOT EXISTS (
                    SELECT 1
                    FROM product_allowed_deal_types padt
                    WHERE padt.product_id = products.id
                )
                OR EXISTS (
                    SELECT 1
                    FROM product_allowed_deal_types padt
                    WHERE padt.product_id = products.id
                      AND padt.deal_type = ?
                )
            )"
        : '';
    $needsDealTypeGlobalParam = $hasDealTypeAllowlist;
    $whereAllowedCustomerTypesGlobal = $hasCustomerTypeAllowlist
        ? " AND (
                NOT EXISTS (
                    SELECT 1
                    FROM product_allowed_customer_types pact
                    WHERE pact.product_id = products.id
                )
                OR EXISTS (
                    SELECT 1
                    FROM product_allowed_customer_types pact
                    WHERE pact.product_id = products.id
                      AND pact.customer_type = ?
                )
            )"
        : '';
    $needsCustomerTypeGlobalParam = $hasCustomerTypeAllowlist;

    $stmt = $db->prepare("
        SELECT
               id,
               code,
               provider,
               name,
               default_description,
               default_reason,
               default_price,
               default_cost,
               lease_cap_exempt,
               finance_cap_exempt,
               category,
               requires_quote,
               requires_selection,
               option_set_id,
               default_term_range,
               eligible_verticals,
               product_image_url,
               product_video_url,
               is_active,
               COALESCE(default_price, 0) AS sale_price,
               COALESCE(
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
               ) AS availability_enabled
        FROM products
        LEFT JOIN product_availability av_global_variant
          ON av_global_variant.product_id = products.id
         AND av_global_variant.scope_type = 'global'
         AND av_global_variant.scope_value = ''
        LEFT JOIN product_availability av_global_provider
          ON av_global_provider.product_id IS NULL
         AND av_global_provider.product_code = products.code
         AND av_global_provider.provider = products.provider
         AND av_global_provider.scope_type = 'global'
         AND av_global_provider.scope_value = ''
        LEFT JOIN product_availability av_global_any
          ON av_global_any.product_id IS NULL
         AND av_global_any.product_code = products.code
         AND av_global_any.provider = ''
         AND av_global_any.scope_type = 'global'
         AND av_global_any.scope_value = ''
        WHERE is_active = 1
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
          {$whereAllowedDealTypesGlobal}
          {$whereAllowedCustomerTypesGlobal}
    ");
    $params = [
        $vehicleCondition,
        $vehicleCondition,
        $vehicleCondition,
        $vehicleCondition,
        $vehicleCondition,
        $vehicleCondition,
    ];
    if ($needsDealTypeGlobalParam) {
        $params[] = $dealType;
    }
    if ($needsCustomerTypeGlobalParam) {
        $params[] = $customerType;
    }
    $stmt->execute($params);
    $rows = filter_products_by_vehicle_eligibility($db, $stmt->fetchAll(PDO::FETCH_ASSOC), $vehicleMakeId, $vehicleModelId, $vehicleTrimId, is_array($deal) ? $deal : []);
    return unique_products_by_code($rows, $preferredProvider);
}

function get_organization_meta(PDO $db, int $organizationId): array
{
    static $metaCache = [];
    if ($organizationId <= 0) {
        return [];
    }
    if (array_key_exists($organizationId, $metaCache)) {
        return $metaCache[$organizationId];
    }

    $columns = ['org_kind', 'parent_org_id', 'logic_type'];
    if (organization_column_exists($db, 'preferred_provider')) {
        $columns[] = 'preferred_provider';
    }
    $select = implode(', ', $columns);
    try {
        $stmt = $db->prepare("SELECT {$select} FROM organizations WHERE id = ?");
        $stmt->execute([$organizationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $metaCache[$organizationId] = is_array($row) ? $row : [];
        return $metaCache[$organizationId];
    } catch (PDOException $e) {
        $metaCache[$organizationId] = [];
        return [];
    }
}

/**
 * Maintain a single entry per product code so customers don't see the same
 * protection repeated simply because multiple rows share the same code.
 */
function unique_products_by_code(array $products, ?string $preferredProvider = null): array {
    $seen = [];
    $deduplicated = [];
    $preferredProvider = $preferredProvider ? strtolower(trim($preferredProvider)) : '';
    foreach ($products as $product) {
        $code = $product['code'] ?? '';
        if ($code === '') {
            continue;
        }
        $codeKey = strtolower($code);
        if (!isset($seen[$codeKey])) {
            $seen[$codeKey] = $product;
            continue;
        }
        $current = $seen[$codeKey];
        $currentProvider = strtolower(trim($current['provider'] ?? ''));
        $candidateProvider = strtolower(trim($product['provider'] ?? ''));
        if ($preferredProvider !== '') {
            if ($candidateProvider === $preferredProvider && $currentProvider !== $preferredProvider) {
                $seen[$codeKey] = $product;
            }
        }
    }
    foreach ($seen as $entry) {
        $deduplicated[] = $entry;
    }
    return $deduplicated;
}

function normalize_vehicle_id($value): ?int {
    if ($value === null || $value === '') {
        return null;
    }
    $int = (int)$value;
    return $int > 0 ? $int : null;
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

function filter_products_by_vehicle_eligibility(PDO $db, array $products, ?int $vehicleMakeId, ?int $vehicleModelId, ?int $vehicleTrimId, array $deal = []): array {
    if (empty($products)) {
        return $products;
    }

    $productIds = [];
    foreach ($products as $product) {
        $pid = isset($product['id']) ? (int)$product['id'] : 0;
        if ($pid > 0) {
            $productIds[] = $pid;
        }
    }
    if (empty($productIds)) {
        return $products;
    }

    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    $rulesByProduct = [];
    $allowCounts = [];
    $hasVehicleCondition = column_exists($db, 'product_vehicle_eligibility', 'vehicle_condition');
    $hasMaxKms = column_exists($db, 'product_vehicle_eligibility', 'max_kms');
    $hasMaxAgeYears = column_exists($db, 'product_vehicle_eligibility', 'max_age_years');
    $selectCols = "product_id, make_id, model_id, trim_id, is_eligible";
    $selectCols .= $hasVehicleCondition ? ", vehicle_condition" : ", NULL AS vehicle_condition";
    $selectCols .= $hasMaxKms ? ", max_kms" : ", NULL AS max_kms";
    $selectCols .= $hasMaxAgeYears ? ", max_age_years" : ", NULL AS max_age_years";
    try {
        $stmt = $db->prepare("
            SELECT {$selectCols}
            FROM product_vehicle_eligibility
            WHERE product_id IN ({$placeholders})
        ");
        $stmt->execute($productIds);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $pid = (int)($row['product_id'] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            $rulesByProduct[$pid][] = $row;
            if ((int)($row['is_eligible'] ?? 0) === 1) {
                $allowCounts[$pid] = ($allowCounts[$pid] ?? 0) + 1;
            }
        }
    } catch (PDOException $e) {
        return $products;
    }

    if (empty($rulesByProduct)) {
        return $products;
    }

    $vehicleMakeId = normalize_vehicle_id($vehicleMakeId);
    $vehicleModelId = normalize_vehicle_id($vehicleModelId);
    $vehicleTrimId = normalize_vehicle_id($vehicleTrimId);

    $vehicleCondition = strtolower(trim((string)($deal['vehicle_condition'] ?? '')));
    $vehicleKms = isset($deal['vehicle_kms']) ? (int)$deal['vehicle_kms'] : null;
    if ($vehicleKms !== null && $vehicleKms <= 0) {
        $vehicleKms = null;
    }
    $vehicleYear = isset($deal['vehicle_year']) ? (int)$deal['vehicle_year'] : null;
    $vehicleYear = $vehicleYear > 0 ? $vehicleYear : null;
    $vehicleAgeYears = resolve_vehicle_age_years($deal['in_service_date'] ?? null, $vehicleYear);

    $filtered = [];
    foreach ($products as $product) {
        $pid = isset($product['id']) ? (int)$product['id'] : 0;
        if ($pid <= 0 || !isset($rulesByProduct[$pid])) {
            $filtered[] = $product;
            continue;
        }

        $allowlistExists = !empty($allowCounts[$pid]);

        if (!$vehicleMakeId) {
            if ($allowlistExists) {
                continue;
            }
            $filtered[] = $product;
            continue;
        }

        $bestRule = null;
        $bestSpec = -1;
        foreach ($rulesByProduct[$pid] as $rule) {
            $ruleMake = normalize_vehicle_id($rule['make_id'] ?? null);
            if (!$ruleMake || $ruleMake !== $vehicleMakeId) {
                continue;
            }
            $ruleModel = normalize_vehicle_id($rule['model_id'] ?? null);
            $ruleTrim = normalize_vehicle_id($rule['trim_id'] ?? null);

            if ($ruleModel && (!$vehicleModelId || $ruleModel !== $vehicleModelId)) {
                continue;
            }
            if ($ruleTrim && (!$vehicleTrimId || $ruleTrim !== $vehicleTrimId)) {
                continue;
            }

            $ruleCondition = strtolower(trim((string)($rule['vehicle_condition'] ?? 'any')));
            if ($ruleCondition === '') {
                $ruleCondition = 'any';
            }
            if ($ruleCondition !== 'any') {
                if ($vehicleCondition === '' || $vehicleCondition !== $ruleCondition) {
                    continue;
                }
            }
            $ruleMaxKms = isset($rule['max_kms']) ? (int)$rule['max_kms'] : 0;
            if ($ruleMaxKms > 0) {
                if ($vehicleKms === null || $vehicleKms > $ruleMaxKms) {
                    continue;
                }
            }
            $ruleMaxAge = isset($rule['max_age_years']) ? (int)$rule['max_age_years'] : 0;
            if ($ruleMaxAge > 0) {
                if ($vehicleAgeYears === null || $vehicleAgeYears > $ruleMaxAge) {
                    continue;
                }
            }

            $spec = $ruleTrim ? 3 : ($ruleModel ? 2 : 1);
            if ($spec > $bestSpec) {
                $bestSpec = $spec;
                $bestRule = $rule;
            }
        }

        if ($bestRule) {
            if ((int)($bestRule['is_eligible'] ?? 0) === 1) {
                $filtered[] = $product;
            }
            continue;
        }

        if ($allowlistExists) {
            continue;
        }

        $filtered[] = $product;
    }

    return $filtered;
}

function parse_reason_override(?string $raw): ?string {
    if ($raw === null || $raw === '') {
        return null;
    }
    $decoded = json_decode($raw, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        if (is_string($decoded)) {
            return trim($decoded);
        }
        if (is_array($decoded)) {
            if (isset($decoded['text']) && is_string($decoded['text'])) {
                return trim($decoded['text']);
            }
            if (isset($decoded['reason']) && is_string($decoded['reason'])) {
                return trim($decoded['reason']);
            }
        }
    }
    return trim($raw);
}

if (!function_exists('recommendations_need_refresh')) {
    function recommendations_need_refresh(PDO $db, int $dealId): bool
    {
        $checkStmt = $db->prepare("SELECT COUNT(*) FROM product_recommendations WHERE deal_id = ?");
        $checkStmt->execute([$dealId]);
        $total = (int)$checkStmt->fetchColumn();
        if ($total <= 0) {
            return true;
        }

        $hasReasonText = false;
        try {
            $reasonStmt = $db->prepare("
                SELECT COUNT(*)
                FROM product_recommendations
                WHERE deal_id = ?
                  AND (
                    TRIM(COALESCE(ai_explanation, '')) <> ''
                    OR TRIM(COALESCE(description, '')) <> ''
                  )
            ");
            $reasonStmt->execute([$dealId]);
            $hasReasonText = (int)$reasonStmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            $hasReasonText = true;
        }
        if (!$hasReasonText) {
            return true;
        }

        try {
            $dealStmt = $db->prepare("SELECT * FROM deals WHERE id = ?");
            $dealStmt->execute([$dealId]);
            $deal = $dealStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if (is_array($deal)) {
                $organizationId = isset($deal['organization']) ? (int)$deal['organization'] : 0;
                $dealType = strtolower(trim((string)($deal['deal_type'] ?? '')));
                $vehicleCondition = (string)($deal['vehicle_condition'] ?? '');
                $customerType = (string)($deal['customer_type'] ?? 'personal');
                $expectedProducts = fetch_products(
                    $db,
                    $dealType,
                    null,
                    $organizationId,
                    isset($deal['vehicle_make_id']) ? (int)$deal['vehicle_make_id'] : null,
                    isset($deal['vehicle_model_id']) ? (int)$deal['vehicle_model_id'] : null,
                    isset($deal['vehicle_trim_id']) ? (int)$deal['vehicle_trim_id'] : null,
                    $vehicleCondition,
                    $customerType,
                    $deal
                );

                $expectedCodes = [];
                foreach ($expectedProducts as $product) {
                    $code = strtolower(trim((string)($product['code'] ?? '')));
                    if ($code !== '') {
                        $expectedCodes[$code] = true;
                    }
                }

                if (!empty($expectedCodes)) {
                    $scoredStmt = $db->prepare("SELECT DISTINCT LOWER(TRIM(product_code)) FROM recommendation_scoring_log WHERE deal_id = ?");
                    $scoredStmt->execute([$dealId]);
                    $scoredCodes = [];
                    foreach ($scoredStmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $code) {
                        $code = strtolower(trim((string)$code));
                        if ($code !== '') {
                            $scoredCodes[$code] = true;
                        }
                    }

                    foreach (array_keys($expectedCodes) as $code) {
                        if (!isset($scoredCodes[$code])) {
                            return true;
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            // Keep existing recommendations if this drift check fails.
        }

        if (!scoring_ai_async_enabled($db)) {
            try {
                $lowQualityStmt = $db->prepare("
                    SELECT COUNT(*)
                    FROM product_recommendations pr
                    LEFT JOIN products p ON p.code = pr.product_code
                    WHERE pr.deal_id = ?
                      AND (
                        TRIM(COALESCE(pr.ai_explanation, '')) = ''
                        OR LOWER(TRIM(COALESCE(pr.ai_explanation, ''))) = LOWER(TRIM(COALESCE(pr.description, '')))
                        OR LOWER(TRIM(COALESCE(pr.ai_explanation, ''))) = LOWER(TRIM(COALESCE(p.default_description, '')))
                        OR LOWER(TRIM(COALESCE(pr.ai_explanation, ''))) = LOWER(TRIM(COALESCE(p.default_reason, '')))
                        OR LOWER(TRIM(COALESCE(pr.ai_explanation, ''))) LIKE 'this protection may be helpful based on your needs%'
                      )
                ");
                $lowQualityStmt->execute([$dealId]);
                $lowQualityCount = (int)$lowQualityStmt->fetchColumn();
                if ($lowQualityCount >= $total) {
                    return true;
                }
            } catch (Throwable $e) {
                // Keep existing recommendations if quality check fails.
            }
        }

        return false;
    }
}

// ---------- DB Action Rules (Direct Scoring/Exclusion) ----------
// This is an MVP "single predicate" rule engine:
// - Score deltas stack
// - Any matched "exclude" wins
// - Rules can be scoped to global, org(group), or store

function load_action_rules(PDO $db, ?int $organizationId): array
{
    try {
        if (!column_exists($db, 'scoring_action_rules', 'id')) {
            return [];
        }
    } catch (Throwable $e) {
        return [];
    }

    $scopes = [['global', '']];
    if ($organizationId) {
        $meta = get_organization_meta($db, (int)$organizationId);
        $kind = $meta['org_kind'] ?? 'store';
        $parentId = !empty($meta['parent_org_id']) ? (int)$meta['parent_org_id'] : 0;
        $scopes[] = [$kind === 'store' ? 'store' : 'org', (string)(int)$organizationId];
        if ($kind === 'store' && $parentId > 0) {
            $scopes[] = ['org', (string)$parentId];
        }
    }

    $conds = [];
    $params = [];
    foreach ($scopes as [$t, $v]) {
        $conds[] = "(scope_type = ? AND scope_value = ?)";
        $params[] = $t;
        $params[] = $v;
    }
    if (empty($conds)) {
        return [];
    }

    try {
        $stmt = $db->prepare("
            SELECT
                id,
                scope_type,
                scope_value,
                signal_key,
                operator,
                value1,
                value2,
                target_type,
                target_code,
                action_type,
                score_delta,
                notes,
                label
            FROM scoring_action_rules
            WHERE is_enabled = 1
              AND (" . implode(' OR ', $conds) . ")
            ORDER BY id ASC
        ");
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        return [];
    }
}

function normalize_rule_value_list($value): array
{
    if ($value === null) {
        return [];
    }
    if (is_array($value)) {
        $out = [];
        foreach ($value as $v) {
            $s = strtolower(trim((string)$v));
            if ($s !== '') {
                $out[] = $s;
            }
        }
        return $out;
    }
    $s = trim((string)$value);
    if ($s === '') {
        return [];
    }
    if (strpos($s, ',') !== false) {
        $parts = array_map('trim', explode(',', $s));
        $parts = array_filter($parts, fn($p) => $p !== '');
        return array_map(fn($p) => strtolower($p), $parts);
    }
    return [strtolower($s)];
}

function evaluate_action_rule_row(array $rule, array $signals): bool
{
    $key = (string)($rule['signal_key'] ?? '');
    if ($key === '') {
        return false;
    }
    $op = strtolower(trim((string)($rule['operator'] ?? 'equals')));
    $allowed = ['equals', 'not_equals', 'gt', 'gte', 'lt', 'lte', 'between', 'in', 'contains', 'exists'];
    if (!in_array($op, $allowed, true)) {
        $op = 'equals';
    }

    $val = $signals[$key] ?? null;
    if ($op === 'exists') {
        return !($val === null || $val === '' || (is_array($val) && empty($val)));
    }

    $v1 = $rule['value1'] ?? null;
    $v2 = $rule['value2'] ?? null;

    $numVal = is_numeric($val) ? (float)$val : null;
    $num1 = is_numeric($v1) ? (float)$v1 : null;
    $num2 = is_numeric($v2) ? (float)$v2 : null;

    if (in_array($op, ['gt', 'gte', 'lt', 'lte', 'between'], true)) {
        if ($numVal === null) {
            return false;
        }
        if ($op === 'between') {
            if ($num1 === null || $num2 === null) {
                return false;
            }
            return $numVal >= min($num1, $num2) && $numVal <= max($num1, $num2);
        }
        if ($num1 === null) {
            return false;
        }
        return match ($op) {
            'gt' => $numVal > $num1,
            'gte' => $numVal >= $num1,
            'lt' => $numVal < $num1,
            'lte' => $numVal <= $num1,
            default => false,
        };
    }

    $hay = normalize_rule_value_list($val);
    $needle = strtolower(trim((string)$v1));

    if ($op === 'equals') {
        if ($needle === '') return false;
        return in_array($needle, $hay, true);
    }
    if ($op === 'not_equals') {
        if ($needle === '') return false;
        return !in_array($needle, $hay, true);
    }
    if ($op === 'contains') {
        if ($needle === '') return false;
        foreach ($hay as $h) {
            if ($h === $needle || strpos($h, $needle) !== false) return true;
        }
        return false;
    }
    if ($op === 'in') {
        $needles = normalize_rule_value_list($v1);
        if (empty($needles)) return false;
        foreach ($needles as $n) {
            if (in_array($n, $hay, true)) return true;
        }
        return false;
    }
    return false;
}

function build_action_rule_signal_context(PDO $db, array $deal, array $application, float $includedTotal = 0.0): array
{
    $signals = [];
    $getNum = function ($v): ?float {
        if ($v === null || $v === '') return null;
        if (is_numeric($v)) return (float)$v;
        return null;
    };
    $getInt = function ($v): ?int {
        if ($v === null || $v === '') return null;
        if (is_numeric($v)) return (int)$v;
        return null;
    };

	    $signals['deal.term'] = $getInt($deal['term'] ?? null);
	    $signals['deal.sale_price'] = $getNum($deal['sale_price'] ?? null);
	    $signals['deal.down_payment'] = $getNum($deal['down_payment'] ?? null);
	    $signals['deal.trade_value'] = $getNum($deal['trade_value'] ?? null);
	    $signals['deal.lien_amount'] = $getNum($deal['lien_amount'] ?? null);
	    $signals['deal.documentation_fee'] = $getNum($deal['documentation_fee'] ?? null);
	    $signals['deal.ppsa_fee'] = $getNum($deal['ppsa_fee'] ?? null);
	    $signals['deal.interest_rate'] = $getNum($deal['interest_rate'] ?? null);
	    $signals['deal.msrp'] = $getNum($deal['msrp'] ?? null);
	    $signals['deal.residual'] = $getNum($deal['residual'] ?? null);
	    $signals['deal.payment_frequency'] = strtolower(trim((string)($deal['payment_frequency'] ?? '')));
	    $signals['deal.customer_type'] = strtolower(trim((string)($deal['customer_type'] ?? '')));
	    $signals['deal.province'] = strtoupper(trim((string)($deal['province'] ?? '')));
	    $signals['deal.deal_type'] = strtolower(trim((string)($deal['deal_type'] ?? '')));
	    $signals['deal.vehicle_make'] = strtolower(trim((string)($deal['vehicle_make'] ?? '')));
	    $signals['deal.vehicle_model'] = strtolower(trim((string)($deal['vehicle_model'] ?? '')));
	    $signals['deal.vehicle_year'] = $getInt($deal['vehicle_year'] ?? null);
	    $signals['deal.vehicle_condition'] = strtolower(trim((string)($deal['vehicle_condition'] ?? '')));
	    $signals['deal.vehicle_kms'] = $getInt($deal['vehicle_kms'] ?? null);
	    $signals['deal.in_service_date'] = trim((string)($deal['in_service_date'] ?? ''));
	    $signals['deal.vin'] = strtoupper(trim((string)($deal['vin'] ?? '')));
	    $signals['deal.vehicle_colour'] = strtolower(trim((string)($deal['vehicle_colour'] ?? '')));

	    $usage = [];
	    if (!empty($application['usage_data'])) {
	        $usage = dealerfai_decode_usage_data((string)$application['usage_data']);
    }
    $hasUsageData = !empty($usage);
	    foreach ($usage as $k => $v) {
	        $signals['usage.' . (string)$k] = $v;
	    }

	    // Flatten custom answers (stored as usage_data.custom_answers)
	    if (!empty($usage['custom_answers']) && is_array($usage['custom_answers'])) {
	        foreach ($usage['custom_answers'] as $k => $v) {
	            $k = trim((string)$k);
	            if ($k === '') continue;
	            $signals['usage.custom.' . $k] = $v;
	        }
	    }

	    // Expose application columns as app.<column> for direct rules (excluding usage_data blob).
	    foreach ($application as $k => $v) {
	        $k = (string)$k;
	        if ($k === '' || $k === 'usage_data') {
	            continue;
	        }
	        $sigKey = 'app.' . $k;
	        if (!array_key_exists($sigKey, $signals)) {
	            $signals[$sigKey] = $v;
	        }
	    }
    if ($hasUsageData) {
        if (array_key_exists('annual_km', $usage)) {
            $signals['app.annual_km'] = $usage['annual_km'];
        }
        if (array_key_exists('driving_type', $usage)) {
            $drivingType = strtolower(trim((string)$usage['driving_type']));
            $signals['app.highway_driving'] = in_array($drivingType, ['highway', 'mix'], true) ? 'yes' : 'no';
        }
        if (array_key_exists('gravel_exposure', $usage)) {
            $gravelExposure = strtolower(trim((string)$usage['gravel_exposure']));
            $signals['app.gravel_road_driving'] = in_array($gravelExposure, ['sometimes', 'frequently'], true) ? 'yes' : 'no';
        }
        if (array_key_exists('road_conditions', $usage)) {
            $roadConditions = strtolower(trim((string)$usage['road_conditions']));
            $signals['app.poor_road_conditions'] = in_array($roadConditions, ['some', 'frequent'], true) ? 'yes' : 'no';
        }
    }

	    $lien = $signals['deal.lien_amount'];
	    $trade = $signals['deal.trade_value'];
	    $signals['computed.negative_equity'] = ($lien !== null && $trade !== null) ? ($lien - $trade) : null;

    $amountFinanced = null;
    if (function_exists('estimate_amount_financed')) {
        $amountFinanced = estimate_amount_financed($deal, $includedTotal);
    }
    if ($amountFinanced === null) {
        $sale = $signals['deal.sale_price'] ?? null;
        $down = $signals['deal.down_payment'] ?? 0.0;
        $doc = $signals['deal.documentation_fee'] ?? 0.0;
        $ppsa = $signals['deal.ppsa_fee'] ?? 0.0;
        $lienF = $signals['deal.lien_amount'] ?? 0.0;
        $tradeF = $signals['deal.trade_value'] ?? 0.0;
        if ($sale !== null && $sale > 0) {
            $amountFinanced = $sale + $doc + $ppsa + $includedTotal - $down - $tradeF + $lienF;
            if ($amountFinanced < 0) $amountFinanced = 0;
        }
    }
    $signals['computed.amount_financed_estimate'] = $amountFinanced;

	    $down = $signals['deal.down_payment'] ?? null;
	    $signals['computed.down_payment_pct'] = ($down !== null && $amountFinanced !== null && $amountFinanced > 0.0) ? ($down / $amountFinanced) : null;
	    $sale = $signals['deal.sale_price'] ?? null;
	    $signals['computed.ltv_pct'] = ($sale !== null && $sale > 0.0 && $amountFinanced !== null)
	        ? (($amountFinanced / $sale) * 100.0)
	        : null;

	    // Ownership estimate (from bracketed answers)
	    $ownershipKey = strtolower(trim((string)($signals['usage.ownership_length'] ?? '')));
	    $ownershipMap = [
	        'less_3' => 36,
	        '3_4' => 48,
	        '5_6' => 72,
	        // Treat 7+ as "very long ownership" so rules can push max coverage.
	        '7_plus' => 120,
	    ];
	    $signals['computed.ownership_months_estimate'] = $ownershipMap[$ownershipKey] ?? null;

	    // Warranty-derived signals (optional; requires vehicle_warranties data)
	    $annualKm = function_exists('extract_annual_km_from_application') ? extract_annual_km_from_application($application) : null;
	    $wctx = function_exists('build_warranty_context') ? build_warranty_context($db, $deal, null, $annualKm) : ['available' => false];
	    $wAvailable = !empty($wctx['available']) ? 1 : 0;
	    $signals['computed.factory_warranty_available'] = $wAvailable;
	    $signals['computed.factory_warranty_remaining_comprehensive_months'] = $wAvailable ? (int)($wctx['remaining_comprehensive_months'] ?? 0) : null;
	    $signals['computed.factory_warranty_remaining_powertrain_months'] = $wAvailable ? (int)($wctx['remaining_powertrain_months'] ?? 0) : null;
	    $signals['computed.factory_warranty_remaining_comprehensive_kms'] = $wAvailable ? (int)($wctx['remaining_comprehensive_kms'] ?? 0) : null;
	    $signals['computed.factory_warranty_remaining_powertrain_kms'] = $wAvailable ? (int)($wctx['remaining_powertrain_kms'] ?? 0) : null;
	    $signals['computed.factory_warranty_remaining_comprehensive_months_by_km'] = $wAvailable
	        ? ($wctx['comprehensive_coverage']['months_remaining_by_km'] ?? null)
	        : null;
	    $ownMonths = $signals['computed.ownership_months_estimate'];
	    $remMonths = $signals['computed.factory_warranty_remaining_comprehensive_months'];
	    $signals['computed.factory_warranty_gap_months_estimate'] = ($ownMonths !== null && $remMonths !== null)
	        ? max(0, (int)$ownMonths - (int)$remMonths)
	        : null;
	    $factoryComprehensiveKmLimit = $wAvailable ? (int)($wctx['comprehensive_kms'] ?? 0) : null;
	    $currentOdometerKm = $wAvailable && isset($wctx['current_kms']) && $wctx['current_kms'] !== null
	        ? (int)$wctx['current_kms']
	        : null;
	    $projectedEndKm = null;
	    if ($wAvailable && $ownMonths !== null && $annualKm !== null && $currentOdometerKm !== null && $annualKm > 0) {
	        $projectedEndKm = (int)round((float)$currentOdometerKm + ((float)$annualKm * ((float)$ownMonths / 12.0)));
	    }
	    $signals['computed.factory_warranty_projected_end_kms'] = $projectedEndKm;
	    $signals['computed.factory_warranty_gap_kms_estimate'] = ($projectedEndKm !== null && $factoryComprehensiveKmLimit !== null)
	        ? ((int)$projectedEndKm - (int)$factoryComprehensiveKmLimit)
	        : null;

	    // Composite helpers (replace legacy tags)
	    // no_highway_or_gravel: true when neither highway nor gravel driving signals are present.
	    $drivingType = strtolower(trim((string)($signals['usage.driving_type'] ?? '')));
	    $gravelExposure = strtolower(trim((string)($signals['usage.gravel_exposure'] ?? '')));
	    $roadConditions = strtolower(trim((string)($signals['usage.road_conditions'] ?? '')));
	    $hasHighway = in_array($drivingType, ['highway', 'mix'], true);
	    $hasGravel = in_array($gravelExposure, ['sometimes', 'frequently'], true)
	        || in_array($roadConditions, ['some', 'frequent'], true)
	        ;
	    $signals['computed.no_highway_or_gravel'] = (!$hasHighway && !$hasGravel) ? 1 : 0;

	    return $signals;
	}

function apply_action_rules_to_target(array $rules, array $signals, string $targetType, string $targetCode): array
{
	    $targetType = strtolower(trim($targetType));
	    $targetCodeKey = strtolower(trim($targetCode));
	    $scoreDelta = 0;
	    $excluded = false;
    $matchedCount = 0;
    $matched = [];
    $hints = [];
	
	    // Prevent double-counting when multiple predicates represent the same concept (label).
	    // Migrated rules use label=<old_tag_code> and may have multiple OR-variants.
    $scoreByLabel = [];
    $matchedByLabel = [];
    $hintsByLabel = [];

	    foreach ($rules as $r) {
	        if (strtolower((string)($r['target_type'] ?? '')) !== $targetType) continue;
	        if (strtolower((string)($r['target_code'] ?? '')) !== $targetCodeKey) continue;
	        if (!evaluate_action_rule_row($r, $signals)) continue;

        $action = strtolower(trim((string)($r['action_type'] ?? 'score')));
        $rid = (string)($r['id'] ?? '');
        $label = strtolower(trim((string)($r['label'] ?? '')));
        $sig = trim((string)($r['signal_key'] ?? ''));
	        $op = trim((string)($r['operator'] ?? ''));
	        $v1 = trim((string)($r['value1'] ?? ''));
	        $v2 = trim((string)($r['value2'] ?? ''));
	        $desc = $label !== '' ? $label : ($sig !== '' ? ($sig . ' ' . $op) : 'rule');
	        if ($v1 !== '') $desc .= ' ' . $v1;
        if ($v2 !== '') $desc .= ' - ' . $v2;
        if ($rid !== '') $desc = '#' . $rid . ' ' . $desc;

        // Optional: notes can guide AI reasoning when this rule matches.
        $note = trim((string)($r['notes'] ?? ''));
        if ($note !== '') {
            // Avoid polluting AI prompts with migration/internal notes.
            $lower = strtolower($note);
            $isInternal = str_starts_with($lower, 'migrated_from=')
                || str_contains($lower, 'migrated_from=')
                || str_starts_with($lower, 'test_')
                || str_starts_with($lower, 'debug');
            if (!$isInternal) {
                if ($label !== '') {
                    if (!isset($hintsByLabel[$label])) {
                        $hintsByLabel[$label] = $note;
                    }
                } else {
                    $hints[] = $note;
                }
            }
        }

        if ($action === 'exclude') {
            $excluded = true;
            if ($label !== '') {
                $matchedByLabel[$label] = $matchedByLabel[$label] ?? ($desc . ' (exclude)');
	            } else {
	                $matched[] = $desc . ' (exclude)';
	            }
	        } elseif ($action === 'score') {
	            $d = (int)($r['score_delta'] ?? 0);
	            if ($label !== '') {
	                // Keep the "strongest" delta for this label (usually all equal).
	                if (!array_key_exists($label, $scoreByLabel) || abs($d) > abs((int)$scoreByLabel[$label])) {
	                    $scoreByLabel[$label] = $d;
	                    $matchedByLabel[$label] = $desc . ' (' . ($d >= 0 ? '+' : '') . $d . ')';
	                } elseif (!array_key_exists($label, $matchedByLabel)) {
	                    $matchedByLabel[$label] = $desc . ' (' . ($d >= 0 ? '+' : '') . $d . ')';
	                }
	            } else {
	                $scoreDelta += $d;
	                $matched[] = $desc . ' (' . ($d >= 0 ? '+' : '') . $d . ')';
	            }
	        }
	    }

	    if (!empty($scoreByLabel)) {
	        $scoreDelta += array_sum(array_map('intval', $scoreByLabel));
	    }
    if (!empty($matchedByLabel)) {
        foreach ($matchedByLabel as $m) {
            $matched[] = $m;
        }
    }
    if (!empty($hintsByLabel)) {
        foreach ($hintsByLabel as $h) {
            $hints[] = $h;
        }
    }
    $matchedCount = count($matched);

    // Keep hints short and stable.
    $hints = array_values(array_unique(array_filter(array_map(fn($x) => trim((string)$x), $hints), fn($x) => $x !== '')));
    if (count($hints) > 5) {
        $hints = array_slice($hints, 0, 5);
    }

    return ['score_delta' => $scoreDelta, 'excluded' => $excluded, 'matched_count' => $matchedCount, 'matched' => $matched, 'hints' => $hints];
}

function load_org_voice_prompts(PDO $db, ?int $organizationId): array {
    $result = ['org_voice' => '', 'store_voice' => ''];
    if (!$organizationId) {
        return $result;
    }
    $stmt = $db->prepare("SELECT id, parent_org_id, org_voice_prompt, store_voice_prompt, org_kind FROM organizations WHERE id = ?");
    $stmt->execute([$organizationId]);
    $org = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$org) {
        return $result;
    }
    $orgVoice = trim((string)($org['org_voice_prompt'] ?? ''));
    $storeVoice = trim((string)($org['store_voice_prompt'] ?? ''));
    $orgKind = $org['org_kind'] ?? 'store';
    $parentId = $org['parent_org_id'] ?? null;
    if ($orgKind === 'group') {
        $storeVoice = '';
    } elseif ($parentId) {
        $parentStmt = $db->prepare("SELECT org_voice_prompt FROM organizations WHERE id = ?");
        $parentStmt->execute([(int)$parentId]);
        $parentVoice = trim((string)($parentStmt->fetchColumn() ?? ''));
        if ($parentVoice !== '') {
            $orgVoice = $parentVoice;
        }
    }
    $result['org_voice'] = $orgVoice;
    $result['store_voice'] = $storeVoice;
    return $result;
}

function resolve_vehicle_make_info(PDO $db, array $deal, array $application): array {
    $makeName = '';
    $makeId = null;

    if (!empty($deal['vehicle_make_id'])) {
        $makeId = (int)$deal['vehicle_make_id'];
        $stmt = $db->prepare("SELECT make_name FROM vehicle_makes WHERE id = ?");
        $stmt->execute([$makeId]);
        $makeName = $stmt->fetchColumn() ?: '';
    }

    if ($makeName === '') {
        $makeName = $deal['vehicle_make'] ?? $application['vehicle_make'] ?? '';
    }

    $makeName = trim((string)$makeName);

    if ($makeId === null && $makeName !== '') {
        $stmt = $db->prepare("SELECT id FROM vehicle_makes WHERE make_name = ?");
        $stmt->execute([$makeName]);
        $foundId = $stmt->fetchColumn();
        if ($foundId !== false) {
            $makeId = (int)$foundId;
        }
    }

    return ['name' => $makeName, 'id' => $makeId];
}

if (!function_exists('resolve_warranty_provider_preference')) {
    function resolve_warranty_provider_preference(?string $makeName): ?string
    {
        $makeKey = strtolower(trim((string)$makeName));
        return match ($makeKey) {
            'jaguar' => 'Jaguar',
            'land rover', 'landrover' => 'Land Rover',
            default => null,
        };
    }
}

if (!function_exists('map_ownership_length_to_term')) {
    function map_ownership_length_to_term(?string $length): ?int {
        $value = strtolower(trim((string)$length));
        return match ($value) {
            'less_3' => 36,
            '3_4' => 48,
            '5_6' => 60,
            '7_plus' => 60,
            default => null,
        };
    }
}

function extract_annual_km_from_application(array $app): ?int {
    $usage = dealerfai_decode_usage_data($app['usage_data'] ?? null);
    $hasUsageData = !empty($usage);
    $annualKm = $usage['annual_km'] ?? (!$hasUsageData ? ($app['annual_km'] ?? null) : null);
    if ($annualKm === null || $annualKm === '') {
        return null;
    }
    if (is_numeric($annualKm)) {
        $km = (int)$annualKm;
        if ($km <= 0) {
            return null;
        }
        $rounded = (int)(ceil($km / 1000) * 1000);
        return max($km, $rounded);
    }
    $map = [
        'under_15k' => 15000,
        '15k_20k' => 20000,
        '20k_25k' => 25000,
        '25k_plus' => 30000,
    ];
    return $map[$annualKm] ?? null;
}

function fetch_vehicle_warranty(PDO $db, string $make): ?array {
    $normalized = strtolower(trim($make));
    if ($normalized === '') {
        return null;
    }
    try {
        $stmt = $db->prepare("
            SELECT comprehensive_months, comprehensive_kms, powertrain_months, powertrain_kms
            FROM vehicle_warranties
            WHERE LOWER(make) = ?
            LIMIT 1
        ");
        $stmt->execute([$normalized]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $ex) {
        return null;
    }
    if (!$row) {
        return null;
    }
    return [
        'comprehensive_months' => (int)$row['comprehensive_months'],
        'comprehensive_kms' => (int)$row['comprehensive_kms'],
        'powertrain_months' => (int)$row['powertrain_months'],
        'powertrain_kms' => (int)$row['powertrain_kms'],
    ];
}

function calculate_warranty_coverage(
    int $warrantyMonths,
    ?int $warrantyKm,
    ?string $inServiceDate,
    ?int $vehicleYear,
    ?string $vehicleCondition,
    ?int $currentOdometerKm,
    ?int $annualKm
): array {
    $start = null;
    $condition = strtolower(trim((string)$vehicleCondition));

    // If it's a new vehicle and we don't have an in-service date, treat it as "today"
    // so we don't accidentally age it using Jan 1 of the model year.
    if ((!$inServiceDate || trim((string)$inServiceDate) === '') && $condition === 'new') {
        $start = new DateTime();
    } elseif ($inServiceDate) {
        try {
            $start = new DateTime($inServiceDate);
        } catch (Exception $e) {
            $start = ($condition === 'new') ? new DateTime() : null;
        }
    }
    if (!$start && $vehicleYear) {
        $start = DateTime::createFromFormat('Y-m-d', $vehicleYear . '-01-01');
    }
    $vehicleAgeMonths = null;
    if ($start) {
        $now = new DateTime();
        if ($start > $now) {
            $vehicleAgeMonths = 0;
        } else {
            $diff = $start->diff($now);
            $vehicleAgeMonths = ($diff->y * 12) + $diff->m;
        }
    }
    if ($vehicleAgeMonths === null) {
        if ($condition === 'new') {
            $vehicleAgeMonths = 0;
        }
    }

    $remainingMonthsByTime = $vehicleAgeMonths !== null
        ? max(0, $warrantyMonths - $vehicleAgeMonths)
        : null;

    $remainingKm = null;
    $monthsRemainingByKm = null;
    if ($warrantyKm !== null && $currentOdometerKm !== null) {
        $remainingKm = max(0, $warrantyKm - $currentOdometerKm);
        if ($annualKm !== null && $annualKm > 0) {
            $monthsRemainingByKm = ($remainingKm / $annualKm) * 12;
        }
    }

    $effectiveRemainingMonths = $remainingMonthsByTime ?? 0;
    $warrantyEndReason = 'time';
    if ($monthsRemainingByKm !== null && $remainingMonthsByTime !== null && $monthsRemainingByKm < $remainingMonthsByTime) {
        $effectiveRemainingMonths = (int)floor($monthsRemainingByKm);
        $warrantyEndReason = 'kilometres';
    } elseif ($remainingMonthsByTime === null && $monthsRemainingByKm !== null) {
        $effectiveRemainingMonths = (int)floor($monthsRemainingByKm);
        $warrantyEndReason = 'kilometres';
    }

    $effectiveRemainingMonths = max(0, (int)round($effectiveRemainingMonths));

    $explanation = [];
    $explanation[] = "Factory warranty term: {$warrantyMonths} months";
    $explanation[] = $warrantyKm !== null
        ? "Factory km limit: " . number_format($warrantyKm) . " km"
        : "Factory km limit: Unlimited";
    if ($vehicleAgeMonths !== null) {
        $explanation[] = "Vehicle age: {$vehicleAgeMonths} months";
    }
    if ($currentOdometerKm !== null) {
        $explanation[] = "Current odometer: " . number_format($currentOdometerKm) . " km";
    }
    if ($annualKm !== null) {
        $explanation[] = "Customer driving: " . number_format($annualKm) . " km/year";
    }
    if ($warrantyEndReason === 'kilometres') {
        $explanation[] = "Warranty expected to end early due to kilometres driven";
    } else {
        $explanation[] = "Warranty expected to end based on time";
    }

    return [
        'vehicle_age_months' => $vehicleAgeMonths,
        'remaining_months_by_time' => $remainingMonthsByTime,
        'remaining_km' => $remainingKm,
        'months_remaining_by_km' => $monthsRemainingByKm !== null ? (int)round($monthsRemainingByKm) : null,
        'effective_remaining_months' => $effectiveRemainingMonths,
        'effective_warranty_end_reason' => $warrantyEndReason,
        'annual_km' => $annualKm,
        'explanation_lines' => $explanation,
    ];
}

function build_warranty_context(PDO $db, array $deal, ?string $makeName, ?int $annualKm): array {
    $make = trim((string)($makeName ?: ($deal['vehicle_make'] ?? '')));
    $warranty = fetch_vehicle_warranty($db, $make);
    if (!$warranty) {
        return ['available' => false];
    }
    $currentKms = isset($deal['vehicle_kms']) && $deal['vehicle_kms'] !== '' ? (int)$deal['vehicle_kms'] : null;
    $compCoverage = calculate_warranty_coverage(
        $warranty['comprehensive_months'],
        $warranty['comprehensive_kms'],
        $deal['in_service_date'] ?? null,
        isset($deal['vehicle_year']) ? (int)$deal['vehicle_year'] : null,
        $deal['vehicle_condition'] ?? null,
        $currentKms,
        $annualKm
    );
    $powerCoverage = calculate_warranty_coverage(
        $warranty['powertrain_months'],
        $warranty['powertrain_kms'],
        $deal['in_service_date'] ?? null,
        isset($deal['vehicle_year']) ? (int)$deal['vehicle_year'] : null,
        $deal['vehicle_condition'] ?? null,
        $currentKms,
        $annualKm
    );
    return [
        'available' => true,
        'make' => $make,
        'comprehensive_months' => $warranty['comprehensive_months'],
        'comprehensive_kms' => $warranty['comprehensive_kms'],
        'powertrain_months' => $warranty['powertrain_months'],
        'powertrain_kms' => $warranty['powertrain_kms'],
        'age_months' => $compCoverage['vehicle_age_months'] ?? null,
        'current_kms' => $currentKms,
        'remaining_comprehensive_months' => $compCoverage['effective_remaining_months'],
        'remaining_powertrain_months' => $powerCoverage['effective_remaining_months'],
        'remaining_comprehensive_kms' => $compCoverage['remaining_km'],
        'remaining_powertrain_kms' => $powerCoverage['remaining_km'],
        'comprehensive_coverage' => $compCoverage,
        'powertrain_coverage' => $powerCoverage,
    ];
}

function format_currency($value): string {
    return '$' . number_format((float)$value, 2);
}

function build_deal_context(array $deal): array {
    $customerName = trim((string)($deal['customer_name'] ?? ''));
    $vehicleYear = trim((string)($deal['vehicle_year'] ?? ''));
    $vehicleMake = trim((string)($deal['vehicle_make'] ?? ''));
    $vehicleModel = trim((string)($deal['vehicle_model'] ?? ''));
    $vehicleColour = trim((string)($deal['vehicle_colour'] ?? ''));
    $vehicleCondition = trim((string)($deal['vehicle_condition'] ?? ''));
    $vehicleKms = isset($deal['vehicle_kms']) && $deal['vehicle_kms'] !== '' ? number_format((float)$deal['vehicle_kms']) . ' km' : '';
    $inServiceDate = trim((string)($deal['in_service_date'] ?? ''));

    $vehicleLine = trim("{$vehicleYear} {$vehicleMake} {$vehicleModel}");
    $vehicleLine = trim(preg_replace('/\s+/', ' ', $vehicleLine));

    $dealType = trim((string)($deal['deal_type'] ?? ''));
    $term = isset($deal['term']) && $deal['term'] !== '' ? (int)$deal['term'] : null;
    $interestRate = isset($deal['interest_rate']) && $deal['interest_rate'] !== '' ? (float)$deal['interest_rate'] : null;
    $paymentFrequency = trim((string)($deal['payment_frequency'] ?? ''));

    $salePrice = (float)($deal['sale_price'] ?? 0);
    $documentationFee = (float)($deal['documentation_fee'] ?? 0);
    $downPayment = (float)($deal['down_payment'] ?? 0);
    $tradeValue = (float)($deal['trade_value'] ?? 0);
    $tradeInfo = trim((string)($deal['trade_info'] ?? ''));
    $lienAmount = (float)($deal['lien_amount'] ?? 0);
    $ppsaFee = (float)($deal['ppsa_fee'] ?? 0);
    $province = strtoupper(trim((string)($deal['province'] ?? '')));
    $includedProtections = function_exists('parse_included_protections')
        ? parse_included_protections($deal['included_protections'] ?? null)
        : [];
    $includedSummary = function_exists('summarize_included_protections')
        ? summarize_included_protections($includedProtections)
        : ['total' => 0, 'names' => []];
    $includedTotal = (float)($includedSummary['total'] ?? 0);
    $includedNames = $includedSummary['names'] ?? [];

    $dealParts = [];
    if ($customerName !== '') {
        $dealParts[] = "Customer: {$customerName}";
    }
    if ($vehicleLine !== '') {
        $dealParts[] = "Vehicle: {$vehicleLine}";
    }
    if ($vehicleCondition !== '') {
        $dealParts[] = "Condition: {$vehicleCondition}";
    }
    if ($vehicleColour !== '') {
        $dealParts[] = "Colour: {$vehicleColour}";
    }
    if ($vehicleKms !== '') {
        $dealParts[] = "Kilometres: {$vehicleKms}";
    }
    if ($inServiceDate !== '') {
        $dealParts[] = "In-service date: {$inServiceDate}";
    }
    if ($dealType !== '') {
        $dealParts[] = "Deal type: {$dealType}";
    }
    if ($term) {
        $dealParts[] = "Term: {$term} months";
    }
    if ($interestRate !== null) {
        $dealParts[] = "Rate: {$interestRate}%";
    }
    if ($paymentFrequency !== '') {
        $dealParts[] = "Payment frequency: {$paymentFrequency}";
    }
    if ($salePrice > 0) {
        $dealParts[] = "Sale price: " . format_currency($salePrice);
    }
    if ($documentationFee > 0) {
        $dealParts[] = "Documentation: " . format_currency($documentationFee);
    }
    if ($includedTotal > 0 && !empty($includedNames)) {
        $dealParts[] = "Included protections: " . implode(', ', $includedNames) . " (" . format_currency($includedTotal) . ")";
    } elseif (!empty($includedNames)) {
        $dealParts[] = "Included protections: " . implode(', ', $includedNames);
    }
    if ($tradeValue > 0 || $tradeInfo !== '') {
        $tradeLine = "Trade: " . ($tradeInfo !== '' ? $tradeInfo . ' ' : '') . "(" . format_currency($tradeValue) . ")";
        $dealParts[] = trim($tradeLine);
    }
    if ($lienAmount > 0) {
        $dealParts[] = "Trade lien: " . format_currency($lienAmount);
    }

    $dealContext = implode('; ', $dealParts);

    $taxRates = [
        'MB' => ['gst' => 0.05, 'pst' => 0.07],
        'SK' => ['gst' => 0.05, 'pst' => 0.06],
        'ON' => ['hst' => 0.13],
        'QC' => ['gst' => 0.05, 'pst' => 0.09975],
        'NS' => ['hst' => 0.15], 'NB' => ['hst' => 0.15], 'PE' => ['hst' => 0.15],
        'NL' => ['hst' => 0.15], 'BC' => ['gst' => 0.05, 'pst' => 0.07],
        'AB' => ['gst' => 0.05], 'NT' => ['gst' => 0.05], 'NU' => ['gst' => 0.05], 'YT' => ['gst' => 0.05],
    ];
    $tax = $taxRates[$province] ?? ['hst' => 0.13];
    $gst = $tax['gst'] ?? 0;
    $pst = $tax['pst'] ?? 0;
    $hst = $tax['hst'] ?? 0;

    $subtotal = $salePrice + $documentationFee + $includedTotal;
    $taxableSubtotal = max(0, $subtotal - $tradeValue);
    $gstAmt = $taxableSubtotal * $gst;
    $pstAmt = $taxableSubtotal * $pst;
    $hstAmt = $taxableSubtotal * $hst;
    $taxTotal = $gstAmt + $pstAmt + $hstAmt;
    $ppsaTotal = $dealType !== 'Cash' ? $ppsaFee : 0;
    $totalWithTaxes = $taxableSubtotal + $taxTotal + $ppsaTotal;
    $totalToFinance = $totalWithTaxes - $downPayment + $lienAmount;

    $financialParts = [];
    if ($documentationFee > 0) {
        $financialParts[] = "Documentation: " . format_currency($documentationFee);
    }
    $financialParts[] = "Subtotal: " . format_currency($subtotal);
    if ($includedTotal > 0 && !empty($includedNames)) {
        $financialParts[] = "Included protections: " . format_currency($includedTotal);
    }
    if ($tradeValue > 0) {
        $financialParts[] = "Trade value: " . format_currency($tradeValue);
        $financialParts[] = "Taxable subtotal: " . format_currency($taxableSubtotal);
    }
    if ($gstAmt > 0) {
        $financialParts[] = "GST: " . format_currency($gstAmt);
    }
    if ($pstAmt > 0) {
        $financialParts[] = "PST: " . format_currency($pstAmt);
    }
    if ($hstAmt > 0) {
        $financialParts[] = "HST: " . format_currency($hstAmt);
    }
    if ($ppsaTotal > 0) {
        $financialParts[] = "PPSA: " . format_currency($ppsaTotal);
    }
    $financialParts[] = "Total with taxes: " . format_currency($totalWithTaxes);
    $financialParts[] = "Down payment: " . format_currency($downPayment);
    if ($lienAmount > 0) {
        $financialParts[] = "Trade lien: " . format_currency($lienAmount);
    }
    $financialParts[] = "Total to finance: " . format_currency($totalToFinance);

    return [
        'deal_context' => $dealContext,
        'financial_summary' => implode('; ', $financialParts),
    ];
}

// ---------- AI EXPLANATION ----------

if (!function_exists('decode_customer_context_payload')) {
    function decode_customer_context_payload(string $raw): array {
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
        // Backward-compatible: treat any non-JSON content as freeform notes.
        return ['freeform' => $trimmed];
    }
}

function format_customer_context_for_prompt(string $raw): string {
    $ctx = decode_customer_context_payload($raw);
    if (empty($ctx)) {
        return '';
    }

    $cleanOneLine = function ($value, int $maxLen): string {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags((string)$value)) ?? '');
        if ($text === '') {
            return '';
        }
        if (strlen($text) > $maxLen) {
            $text = substr($text, 0, $maxLen);
        }
        return $text;
    };

    $lines = [];
    $fields = [
        'household' => 'Household',
        'commute' => 'Commute',
        'usage' => 'Usage',
        'pain_points' => 'Pain points',
        'must_haves' => 'Must-haves',
        'objections' => 'Objections',
        'budget' => 'Budget sensitivity',
    ];
    foreach ($fields as $key => $label) {
        if (!array_key_exists($key, $ctx)) {
            continue;
        }
        $value = $cleanOneLine($ctx[$key], 240);
        if ($value === '') {
            continue;
        }
        $lines[] = "- {$label}: {$value}";
    }

    if (array_key_exists('freeform', $ctx)) {
        $freeform = trim((string)$ctx['freeform']);
        if ($freeform !== '') {
            $freeform = preg_replace('/\r\n|\r|\n/', ' / ', $freeform) ?? $freeform;
            $freeform = $cleanOneLine($freeform, 600);
            if ($freeform !== '') {
                $lines[] = "- Notes: {$freeform}";
            }
        }
    }

    if (empty($lines)) {
        return '';
    }

    return "Customer context (sales notes; optional; use only if relevant; do not infer beyond these):\n" . implode("\n", $lines);
}

function rewrite_insider_terms_for_customer(string $text): string {
    $clean = trim($text);
    if ($clean === '') {
        return '';
    }

    $replacements = [
        '/\bLTV\b/i' => 'how much is being financed compared with the vehicle value',
        '/\bloan[\s-]*to[\s-]*value\b/i' => 'how much is being financed compared with the vehicle value',
        '/\bnegative equity\b/i' => 'remaining balance from the trade-in',
        '/\bscore(?:_delta)?\b/i' => 'fit factors',
        '/\btag(?:s)?\b/i' => 'fit details',
        '/\brule match(?:ed|es)?\b/i' => 'matched fit details',
    ];
    foreach ($replacements as $pattern => $replacement) {
        $clean = preg_replace($pattern, $replacement, $clean) ?? $clean;
    }

    $clean = preg_replace('/\s+/', ' ', $clean) ?? $clean;
    return trim($clean);
}

function prioritize_reason_inputs(array $matchedDetails, array $ruleHints, array $dealInfo): array {
    $normalizeList = function (array $items): array {
        $clean = array_values(array_filter(array_map(
            static fn($x) => trim((string)$x),
            $items
        ), static fn($x) => $x !== ''));
        return array_values(array_unique($clean));
    };

    $matched = $normalizeList($matchedDetails);
    $hints = $normalizeList($ruleHints);

    $priority = [];
    foreach (array_merge($matched, $hints) as $item) {
        if (!in_array($item, $priority, true)) {
            $priority[] = $item;
        }
        if (count($priority) >= 5) {
            break;
        }
    }

    $sanitize = function (array $items): array {
        $out = [];
        foreach ($items as $item) {
            $rewritten = rewrite_insider_terms_for_customer((string)$item);
            if ($rewritten !== '' && !in_array($rewritten, $out, true)) {
                $out[] = $rewritten;
            }
        }
        return $out;
    };

    $matched = $sanitize($matched);
    $hints = $sanitize($hints);
    $priority = $sanitize($priority);

    return [
        'matched_details' => array_slice($matched, 0, 5),
        'rule_hints' => array_slice($hints, 0, 5),
        'priority_reasons' => $priority,
    ];
}

function build_priority_reason_block(array $priorityReasons): string {
    $priorityReasons = array_values(array_filter(array_map(static fn($x) => trim((string)$x), $priorityReasons), static fn($x) => $x !== ''));
    if (empty($priorityReasons)) {
        return '';
    }
    $lines = [];
    foreach (array_slice($priorityReasons, 0, 3) as $idx => $reason) {
        $n = $idx + 1;
        $lines[] = "{$n}. {$reason}";
    }
    return "Reason priority (highest-priority fit first; if any conflict, trust the earlier item):\n" . implode("\n", $lines) . "\n";
}

function recommendation_text_repeats_description(string $reply, array $product, string $defaultFacts): bool {
    $reply = trim($reply);
    if ($reply === '') {
        return false;
    }
    $parts = preg_split('/(?<=[.!?])\s+/', $reply, 2);
    $firstSentence = trim((string)($parts[0] ?? $reply));
    if ($firstSentence === '') {
        return false;
    }

    $normalizedFirst = strtolower(trim(preg_replace('/\s+/', ' ', $firstSentence) ?? ''));
    if ($normalizedFirst === '') {
        return false;
    }

    $candidates = [
        (string)($product['description'] ?? ''),
        (string)($product['default_description'] ?? ''),
        (string)($product['default_reason'] ?? ''),
        (string)$defaultFacts,
    ];

    foreach ($candidates as $candidate) {
        $candidate = trim((string)$candidate);
        if ($candidate === '') {
            continue;
        }
        $normalizedCandidate = strtolower(trim(preg_replace('/\s+/', ' ', $candidate) ?? ''));
        if ($normalizedCandidate === '') {
            continue;
        }
        if (strlen($normalizedFirst) >= 36 && (str_contains($normalizedCandidate, $normalizedFirst) || str_contains($normalizedFirst, $normalizedCandidate))) {
            return true;
        }
        similar_text($normalizedFirst, $normalizedCandidate, $percent);
        if ($percent >= 68.0) {
            return true;
        }
    }

    return false;
}

function sanitize_recommendation_ai_reply(string $reply): string {
    $reply = trim($reply);
    if ($reply === '') {
        return '';
    }

    $patterns = [
        '/\s*If you have any further questions or would like to explore this option in more detail, please do let me know\.?\s*$/i',
        '/\s*If you have any questions or would like to explore this option in more detail, please do let me know\.?\s*$/i',
        '/\s*If you have any questions, please do let me know\.?\s*$/i',
        '/\s*Please do let me know\.?\s*$/i',
    ];

    $clean = preg_replace($patterns, '', $reply);
    if (!is_string($clean)) {
        return $reply;
    }

    return trim($clean, " \t\n\r\0\x0B,;");
}

function summarize_recommendation_opening(string $text, int $wordLimit = 12): string {
    $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
    if ($text === '') {
        return '';
    }

    $parts = preg_split('/(?<=[.!?])\s+/', $text, 2);
    $firstSentence = trim((string)($parts[0] ?? $text));
    if ($firstSentence === '') {
        return '';
    }

    $words = preg_split('/\s+/', $firstSentence) ?: [];
    $words = array_values(array_filter($words, static fn($word) => trim((string)$word) !== ''));
    if (empty($words)) {
        return '';
    }

    $snippet = implode(' ', array_slice($words, 0, $wordLimit));
    return trim($snippet, " \t\n\r\0\x0B,;:.!");
}

function recommendation_opening_signature(string $text, int $wordLimit = 4): string {
    $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
    if ($text === '') {
        return '';
    }

    $parts = preg_split('/(?<=[.!?])\s+/', $text, 2);
    $firstSentence = trim((string)($parts[0] ?? $text));
    if ($firstSentence === '') {
        return '';
    }

    $words = preg_split('/\s+/', strtolower($firstSentence)) ?: [];
    $words = array_values(array_filter(array_map(static function ($word) {
        $clean = preg_replace('/[^a-z0-9]+/i', '', (string)$word);
        return is_string($clean) ? $clean : '';
    }, $words), static fn($word) => $word !== ''));
    if (empty($words)) {
        return '';
    }

    return implode(' ', array_slice($words, 0, $wordLimit));
}

function fetch_prior_recommendation_openings(PDO $db, int $dealId, string $productCode, int $limit = 8): array {
    if ($dealId <= 0 || $productCode === '') {
        return [];
    }

    try {
        $stmt = $db->prepare("
            SELECT COALESCE(NULLIF(TRIM(ai_explanation), ''), NULLIF(TRIM(description), '')) AS text
            FROM product_recommendations
            WHERE deal_id = ?
              AND product_code <> ?
            ORDER BY score DESC, product_code ASC
            LIMIT {$limit}
        ");
        $stmt->execute([$dealId, $productCode]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        return [];
    }
}

function recommendation_opening_repeats_prior(string $text, array $priorRows): bool {
    $candidateSignature = recommendation_opening_signature($text);
    if ($candidateSignature === '') {
        return false;
    }

    foreach ($priorRows as $row) {
        $priorText = (string)($row['text'] ?? '');
        $priorSignature = recommendation_opening_signature($priorText);
        if ($priorSignature === '') {
            continue;
        }

        if ($candidateSignature === $priorSignature) {
            return true;
        }

        similar_text($candidateSignature, $priorSignature, $percent);
        if ($percent >= 85.0) {
            return true;
        }
    }

    return false;
}

function build_prior_recommendation_style_block(PDO $db, array $dealInfo, array $product): string {
    $dealId = isset($dealInfo['deal_id']) ? (int)$dealInfo['deal_id'] : 0;
    $productCode = trim((string)($product['code'] ?? ''));
    if ($dealId <= 0 || $productCode === '') {
        return '';
    }

    $rows = fetch_prior_recommendation_openings($db, $dealId, $productCode, 8);
    if (empty($rows)) {
        return '';
    }

    $openings = [];
    foreach ($rows as $row) {
        $opening = summarize_recommendation_opening((string)($row['text'] ?? ''));
        if ($opening === '') {
            continue;
        }
        $key = strtolower($opening);
        if (isset($openings[$key])) {
            continue;
        }
        $openings[$key] = $opening;
        if (count($openings) >= 5) {
            break;
        }
    }

    if (empty($openings)) {
        return '';
    }

    return "Openings/patterns already used in other recommendations for this deal (avoid reusing these exact starts or very similar sentence structure):\n- "
        . implode("\n- ", array_values($openings))
        . "\n";
}

function generate_ai_explanation($product, $dealInfo = [], ?PDO $db = null) {
    global $apiKey;
    $apiKey = $apiKey
        ?? $_ENV['GEMINI_API_KEY']
        ?? getenv('GEMINI_API_KEY');
    $defaultFacts = trim((string)($product['approved_facts_default'] ?? $product['default_description'] ?? $product['default_reason'] ?? ''));
    $fallbackReason = trim((string)(
        $product['default_reason']
        ?? $product['description']
        ?? $product['default_description']
        ?? $defaultFacts
    ));
    if (empty($apiKey)) {
        return $fallbackReason;
    }

    $customFacts = trim((string)($product['approved_facts_custom'] ?? ''));
    if ($defaultFacts === '' && $customFacts === '') {
        return $fallbackReason;
    }
    $orgVoice = trim((string)($dealInfo['org_voice'] ?? ''));
    $storeVoice = trim((string)($dealInfo['store_voice'] ?? ''));
    $voiceBlock = '';
    if ($orgVoice !== '') {
        $voiceBlock .= "Organization voice guidelines (baseline):\n{$orgVoice}\n";
    }
    if ($storeVoice !== '') {
        $voiceBlock .= "Store voice guidelines (primary, lean toward this):\n{$storeVoice}\n";
    }

    $profileSummary = trim((string)($dealInfo['profile_summary'] ?? ''));
    $profileLine = $profileSummary !== ''
        ? "Customer profile summary (from their answers): {$profileSummary}.\n"
        : "Customer profile summary: [not provided].\n";
    $matchedDetails = $dealInfo['matched_details'] ?? [];
    if (!is_array($matchedDetails)) {
        $matchedDetails = [];
    }

	    $ruleHints = $dealInfo['rule_hints'] ?? [];
	    if (!is_array($ruleHints)) {
	        $ruleHints = [];
	    }
	    $ruleHints = array_values(array_filter(array_map(fn($x) => trim((string)$x), $ruleHints), function ($x) {
            if ($x === '') {
                return false;
            }
            $lower = strtolower($x);
            // Avoid speculative "customer showed interest" phrasing when that input does not exist.
            if (str_contains($lower, 'interested in')) {
                return false;
            }
            return true;
        }));
    $prioritized = prioritize_reason_inputs($matchedDetails, $ruleHints, $dealInfo);
    $matchedDetails = $prioritized['matched_details'];
    $ruleHints = $prioritized['rule_hints'];
    $priorityReasonBlock = build_priority_reason_block($prioritized['priority_reasons']);
	    $matchedLine = '';
	    if (!empty($matchedDetails)) {
	        $matchedLine = "Recommendation triggers from their answers: " . implode('; ', $matchedDetails) . ".\n";
	    }
	    $ruleHintBlock = '';
	    if (!empty($ruleHints)) {
	        $ruleHintBlock = "Matched rule guidance (use to explain why it fits; do not treat as coverage facts):\n- "
	            . implode("\n- ", array_slice($ruleHints, 0, 5))
	            . "\n";
	    }

	    $customerContextBlock = '';
	    $customerContextRaw = trim((string)($dealInfo['customer_context_raw'] ?? ''));
	    if ($customerContextRaw !== '') {
	        $customerContextBlock = format_customer_context_for_prompt($customerContextRaw);
        if ($customerContextBlock !== '') {
            $customerContextBlock .= "\n";
        }
    }

    $dealContext = trim((string)($dealInfo['deal_context'] ?? ''));
    $financialSummary = trim((string)($dealInfo['financial_summary'] ?? ''));
    $dealContextLine = $dealContext !== ''
        ? "Deal context (customer + vehicle + terms): {$dealContext}.\n"
        : '';
    $financialLine = $financialSummary !== ''
        ? "Financial summary: {$financialSummary}.\n"
        : '';
    $priorStyleBlock = $db instanceof PDO
        ? build_prior_recommendation_style_block($db, $dealInfo, is_array($product) ? $product : [])
        : '';
    $priorOpeningRows = ($db instanceof PDO)
        ? fetch_prior_recommendation_openings(
            $db,
            isset($dealInfo['deal_id']) ? (int)$dealInfo['deal_id'] : 0,
            trim((string)($product['code'] ?? '')),
            8
        )
        : [];

    $basePrompt = $profileLine
	        . $matchedLine
	        . ($ruleHintBlock !== '' ? ($ruleHintBlock . "\n") : '')
            . ($priorityReasonBlock !== '' ? ($priorityReasonBlock . "\n") : '')
	        . $customerContextBlock
	        . $dealContextLine
	        . $financialLine
            . ($priorStyleBlock !== '' ? ($priorStyleBlock . "\n") : '')
	        . "They are considering this protection product: {$product['name']}.\n"
	        . "The customer already sees the product description and facts above, so do not repeat or paraphrase them.\n"
        . ($voiceBlock !== '' ? ($voiceBlock . "\n") : '')
        . "Default approved facts (baseline; always true unless overridden below):\n"
        . ($defaultFacts !== '' ? $defaultFacts : '[No default approved facts provided]') . "\n"
	        . "Custom approved facts (store-specific; if any conflict with default, these override the default):\n"
	        . ($customFacts !== '' ? $customFacts : '[No custom approved facts provided]') . "\n"
	        . "Write 2–3 sentences explaining why this product might benefit them.\n"
	        . "Safety rules:\n"
	        . "- Follow voice guidelines if provided; store voice has priority over organization voice.\n"
	        . "- Use only approved facts above; if custom and default conflict, use custom.\n"
	        . "- Do not invent, assume, or imply coverage details not listed in approved facts.\n"
	        . "- Use provided customer/profile/rule details only when they are explicitly present.\n"
	        . "- Keep wording customer-friendly and avoid internal terms like score, tag, or rule match.\n"
            . "- Vary the opening phrasing and sentence rhythm from other recommendations already written for this same deal.\n"
            . "- It is fine to reference the customer's situation early, but do not keep reusing the same opening pattern across products.\n"
            . "- If other recommendations already start with a phrase like \"Given your...\" or \"Considering your...\", choose a different opening for this product.";

    if (function_exists('scoring_request_context_line')) {
        scoring_debug("🧭 Gemini request context: " . scoring_request_context_line([
            'deal_id' => $dealInfo['deal_id'] ?? null,
            'product' => $product['code'] ?? null,
            'model' => 'gemini-1.5-flash',
        ]));
    }

    $attemptPrompts = [
        $basePrompt,
        $basePrompt . "\nRevision rule:\n- Rewrite with a distinctly different opening from the other recommendations for this deal.\n- You may still mention the customer's situation, but use a fresh sentence pattern and different lead-in.\n- Avoid reusing the same first 3-4 words already used elsewhere.\n",
    ];

    foreach ($attemptPrompts as $attemptIndex => $prompt) {
        $ch = curl_init("https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=" . urlencode($apiKey));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                "Content-Type: application/json"
            ],
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_POSTFIELDS => json_encode([
                "contents" => [
                    ["parts" => [["text" => $prompt]]]
                ],
                "systemInstruction" => [
                    "parts" => [["text" => "You are a helpful vehicle protection advisor."]]
                ],
                "generationConfig" => [
                    "maxOutputTokens" => 150,
                    "temperature" => 0.2
                ]
            ])
        ]);

        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            error_log("❌ CURL error: " . curl_error($ch));
            scoring_debug("❌ CURL error: " . curl_error($ch));
            curl_close($ch);
            return $fallbackReason;
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);
        scoring_debug("🔍 Gemini response (attempt " . ($attemptIndex + 1) . ", HTTP $httpCode): " . $response);

        $reply = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if (!$reply) {
            error_log("⚠️ Missing AI reply. Full response: " . print_r($data, true));
        }

        $cleanReply = sanitize_recommendation_ai_reply(trim((string)$reply));
        if ($cleanReply !== '' && recommendation_text_repeats_description((string)$cleanReply, $product, $defaultFacts)) {
            return $fallbackReason;
        }

        if ($cleanReply !== '' && recommendation_opening_repeats_prior($cleanReply, $priorOpeningRows) && $attemptIndex === 0) {
            continue;
        }

        return ($cleanReply !== '' ? $cleanReply : $reply)
            ?? $fallbackReason;
    }

    return $fallbackReason;
}
