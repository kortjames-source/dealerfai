<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/security_headers.php';
require_once __DIR__ . '/includes/theme_head.php';
include 'db.php';
include_once 'scoring_engine.php';
include_once 'protection_helpers.php';
require_once __DIR__ . '/accessory_helpers.php';
require_once __DIR__ . '/helpers/db_utils.php';
require_once __DIR__ . '/helpers/insurance_quote.php';
require_once __DIR__ . '/helpers/deal_access.php';
require_once __DIR__ . '/helpers/usage_data.php';
require_once __DIR__ . '/helpers/recommendation_packages.php';
require_once __DIR__ . '/helpers/theme.php';

if (!function_exists('get_default_package_menu_labels')) {
    function get_default_package_menu_labels(): array {
        return [
            'All-In Coverage',
            'Balanced Protection',
            'Essential Safeguards',
            'Minimal Start',
        ];
    }
}

$hasProductLuxuryTaxColumn = function_exists('column_exists') ? column_exists($db, 'products', 'contributes_to_luxury_tax') : false;
$hasProductGstTaxableColumn = function_exists('column_exists') ? column_exists($db, 'products', 'gst_taxable') : false;
$hasProductPstTaxableColumn = function_exists('column_exists') ? column_exists($db, 'products', 'pst_taxable') : false;

if (!function_exists('parse_package_menu_labels')) {
    function parse_package_menu_labels(?string $raw): array {
        if ($raw === null) {
            return [];
        }
        $lines = preg_split('/\r?\n/', (string)$raw);
        $labels = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }
            $labels[] = $trimmed;
            if (count($labels) >= 4) {
                break;
            }
        }
        return $labels;
    }
}

if (!function_exists('finalize_package_menu_labels')) {
    function finalize_package_menu_labels(array $custom, int $required, array $defaults): array {
        if ($required <= 0) {
            return [];
        }
        $labels = array_values($custom);
        if (empty($labels)) {
            $labels = $defaults;
        }
        $idx = 0;
        while (count($labels) < $required && !empty($defaults)) {
            $labels[] = $defaults[$idx % count($defaults)];
            $idx++;
        }
        return array_slice($labels, 0, $required);
    }
}


$deal_id = $_GET['deal_id'] ?? null;
if (!$deal_id) die("Missing deal ID.");

$snapshot_id = isset($_GET['snapshot_id']) ? (int)$_GET['snapshot_id'] : null;
$preScored = !empty($_GET['prescored']);
$forceRescore = !empty($_GET['rescore']) || !empty($_GET['force_rescore']);
$snapshotMode = false;
$snapshotSelected = [];
$snapshotRecommended = [];
$snapshotSubmittedAt = null;

if ($snapshot_id) {
    $snapStmt = $db->prepare("SELECT selected_protections, all_recommendations, submitted_at FROM protection_audit_log WHERE id = ? AND deal_id = ?");
    $snapStmt->execute([$snapshot_id, $deal_id]);
    $snapshot = $snapStmt->fetch(PDO::FETCH_ASSOC);
    if ($snapshot) {
        $snapshotMode = true;
        $snapshotSelected = json_decode($snapshot['selected_protections'] ?? '[]', true) ?: [];
        $snapshotRecommended = json_decode($snapshot['all_recommendations'] ?? '[]', true) ?: [];
        $snapshotSubmittedAt = $snapshot['submitted_at'] ?? null;
    }
}

// Generate AI + Scoring-Based Recommendations
if (!$snapshotMode) {
    if ($preScored) {
        if (recommendations_need_refresh($db, (int)$deal_id)) {
            $db->prepare("DELETE FROM product_recommendations WHERE deal_id = ?")->execute([$deal_id]);
            score_and_store_recommendations($db, $deal_id);
        }
    } elseif ($forceRescore) {
        $db->prepare("DELETE FROM product_recommendations WHERE deal_id = ?")->execute([$deal_id]);
        score_and_store_recommendations($db, $deal_id);
    } else {
        $existingStmt = $db->prepare("SELECT COUNT(*) FROM product_recommendations WHERE deal_id = ?");
        $existingStmt->execute([(int)$deal_id]);
        $hasRecommendations = (int)$existingStmt->fetchColumn() > 0;
        if (!$hasRecommendations) {
            score_and_store_recommendations($db, $deal_id);
        }
    }
}

// Fetch Deal Info
$dealColumns = select_existing_columns($db, 'deals', [
    'id', 'organization', 'salesperson_id', 'customer_name', 'deal_type', 'province',
    'sale_price', 'term', 'interest_rate', 'payment_frequency',
    'down_payment', 'trade_value', 'lien_amount', 'documentation_fee', 'ppsa_fee',
    'residual', 'msrp', 'included_protections',
    'vehicle_make', 'vehicle_make_id', 'vehicle_model', 'vehicle_condition', 'vehicle_year'
]);
$dealSelect = !empty($dealColumns) ? implode(', ', $dealColumns) : '*';
$stmt = $db->prepare("SELECT {$dealSelect} FROM deals WHERE id = ?");
$stmt->execute([$deal_id]);
$deal = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$deal) die("Deal not found.");
if (!dealerfai_session_can_access_deal($deal)) {
    http_response_code(403);
    die("Access denied.");
}
$isCashDeal = strcasecmp((string)($deal['deal_type'] ?? ''), 'Cash') === 0;
$showCapWithGap = function_exists('is_cap_with_gap_enabled')
    ? is_cap_with_gap_enabled($db, (int)($deal['organization'] ?? 0))
    : true;

$includedProtections = function_exists('parse_included_protections')
    ? parse_included_protections($deal['included_protections'] ?? null)
    : [];
$includedSummary = function_exists('summarize_included_protections')
    ? summarize_included_protections($includedProtections)
    : ['total' => 0, 'codes' => [], 'names' => []];
$includedTotal = (float)($includedSummary['total'] ?? 0);
$includedNames = $includedSummary['names'] ?? [];
$includedCodeLookup = [];
foreach (($includedSummary['codes'] ?? []) as $code) {
    $key = strtolower(trim((string)$code));
    if ($key !== '') {
        $includedCodeLookup[$key] = true;
    }
}

$isLeaseDeal = strcasecmp((string)($deal['deal_type'] ?? ''), 'Lease') === 0;
$isFinanceDeal = strcasecmp((string)($deal['deal_type'] ?? ''), 'Finance') === 0;

// Accessories are selected either during deal creation or on the Accessories page prior to recommendations.
// For amount-financed calculations, only accessories with pay_method that roll into the deal should be included.
$accessoryTotalsByMethod = [
    'upfront' => 0.0,
    'finance' => 0.0,
    'cap_cost' => 0.0,
];
try {
    $accSumStmt = $db->prepare("
        SELECT pay_method, COALESCE(SUM(sale_price), 0) AS total
        FROM deal_accessories
        WHERE deal_id = ?
        GROUP BY pay_method
    ");
    $accSumStmt->execute([$deal_id]);
    while ($row = $accSumStmt->fetch(PDO::FETCH_ASSOC)) {
        $method = strtolower(trim((string)($row['pay_method'] ?? '')));
        if ($method === '' || !array_key_exists($method, $accessoryTotalsByMethod)) {
            continue;
        }
        $accessoryTotalsByMethod[$method] = (float)($row['total'] ?? 0);
    }
} catch (PDOException $e) {
    // Table may not exist in older deployments; ignore.
}
$accessoryTotalAll = array_sum($accessoryTotalsByMethod);
$accessoryTotalRolledIn = $isCashDeal
    ? $accessoryTotalAll
    : ($isLeaseDeal
        ? (float)$accessoryTotalsByMethod['cap_cost']
        : ($isFinanceDeal ? (float)$accessoryTotalsByMethod['finance'] : 0.0));

// Province tax rates for accurate add-on payment estimates (protections can be GST/PST exempt).
$province = (string)($deal['province'] ?? 'ON');
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
$gstRate = (float)($tax['gst'] ?? 0.0);
$pstRate = (float)($tax['pst'] ?? 0.0);
$hstRate = (float)($tax['hst'] ?? 0.0);
$leaseCapPercent = null;
$financeCapPercent = null;
$capPercent = null;
$msrpValue = (float)($deal['msrp'] ?? 0);
$leaseCapEnabled = false;
$leaseCapLimit = 0.0;
$baseCapCost = 0.0;
$baseFinanceAmount = 0.0;

// Fetch Theme / Branding
$theme = dealerfai_get_theme_palette(null);
$packageLabelColumn = organization_column_exists($db, 'package_menu_labels');
$hasLeaseCapPercentColumn = organization_column_exists($db, 'lease_msrp_cap_percent');
$hasFinanceCapPercentColumn = organization_column_exists($db, 'finance_msrp_cap_percent');
$selectFields = 'logo_url, theme_variant'
    . ($packageLabelColumn ? ', package_menu_labels' : '')
    . ($hasLeaseCapPercentColumn ? ', lease_msrp_cap_percent' : '')
    . ($hasFinanceCapPercentColumn ? ', finance_msrp_cap_percent' : '');
$orgStmt = $db->prepare("SELECT " . $selectFields . " FROM organizations WHERE id = ?");
$orgStmt->execute([$deal['organization']]);
$rawPackageMenuLabels = '';
$themeVariant = '';
if ($org = $orgStmt->fetch(PDO::FETCH_ASSOC)) {
    $themeVariant = $org['theme_variant'] ?? '';
    $theme = dealerfai_get_theme_palette($themeVariant, ['logo' => $org['logo_url'] ?? '']);
    if ($packageLabelColumn) {
        $rawPackageMenuLabels = $org['package_menu_labels'] ?? '';
    }
}
$leaseCapPercent = is_array($org ?? null) ? ($org['lease_msrp_cap_percent'] ?? null) : null;
$financeCapPercent = is_array($org ?? null) ? ($org['finance_msrp_cap_percent'] ?? null) : null;
$capPercent = $isLeaseDeal ? $leaseCapPercent : ($isFinanceDeal ? $financeCapPercent : null);
$capPercent = ($capPercent !== null && $capPercent !== '' && is_numeric($capPercent)) ? (float)$capPercent : null;
$leaseCapEnabled = ($isLeaseDeal || $isFinanceDeal) && $capPercent !== null && $capPercent > 0 && $msrpValue > 0;
$leaseCapLimit = $leaseCapEnabled ? ($msrpValue * ($capPercent / 100)) : 0.0;
if ($leaseCapEnabled && $leaseCapLimit > 0) {
    $baseCapCost = (float)($deal['sale_price'] ?? 0)
        + (float)($deal['documentation_fee'] ?? 0)
        + $includedTotal
        + $accessoryTotalRolledIn
        + (float)($deal['ppsa_fee'] ?? 0)
        - (float)($deal['down_payment'] ?? 0)
        - (float)($deal['trade_value'] ?? 0)
        + (float)($deal['lien_amount'] ?? 0);
    $baseCapCost = max(0, $baseCapCost);
    $baseFinanceAmount = $baseCapCost;
}
$customPackageMenuLabels = parse_package_menu_labels($rawPackageMenuLabels);

if (!function_exists('normalize_hex_color')) {
    function normalize_hex_color(string $value): string {
        $hex = trim($value);
        if ($hex === '') {
            $hex = '0a6280';
        }
        if ($hex[0] === '#') {
            $hex = substr($hex, 1);
        }
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
            $hex = '0a6280';
        }
        return '#' . strtolower($hex);
    }
}

function summarize_coverage_text(?string $text): string {
    if ($text === null) {
        return '';
    }
    $clean = trim(preg_replace('/\s+/', ' ', strip_tags($text)));
    if ($clean === '') {
        return '';
    }
    $parts = preg_split('/(?<=[.!?])\s+/', $clean, 2);
    return $parts[0] ?? $clean;
}

function build_vehicle_label(array $deal): string {
    $year = trim((string)($deal['vehicle_year'] ?? ''));
    $make = trim((string)($deal['vehicle_make'] ?? ''));
    $model = trim((string)($deal['vehicle_model'] ?? ''));
    $label = trim("{$year} {$make} {$model}");
    return trim(preg_replace('/\s+/', ' ', $label));
}

function generate_recommendation_intro(array $deal, string $profileSummary, array $voicePrompts, bool $aiReasoningEnabled = true): string {
    $customerName = trim((string)($deal['customer_name'] ?? ''));
    $vehicleLabel = build_vehicle_label($deal);

    $fallbackName = $customerName !== '' ? "{$customerName}, " : '';
    $fallbackVehicle = $vehicleLabel !== '' ? "your {$vehicleLabel}" : 'your vehicle';
    $fallbackSummary = $profileSummary !== '' ? " Based on {$profileSummary}," : '';
    $fallback = "{$fallbackName}your protection plan for {$fallbackVehicle} was built around your driving habits, parking environment, and ownership timeline to help protect your vehicle and financing from unexpected road and environmental risks.{$fallbackSummary}";

    if (!$aiReasoningEnabled) {
        return $fallback;
    }

    global $apiKey;
    $apiKey = $apiKey
        ?? $_ENV['GEMINI_API_KEY']
        ?? getenv('GEMINI_API_KEY');
    if (empty($apiKey)) {
        return $fallback;
    }

    $voiceBlock = '';
    $orgVoice = trim((string)($voicePrompts['org_voice'] ?? ''));
    $storeVoice = trim((string)($voicePrompts['store_voice'] ?? ''));
    if ($orgVoice !== '') {
        $voiceBlock .= "Organization voice guidelines (baseline):\n{$orgVoice}\n";
    }
    if ($storeVoice !== '') {
        $voiceBlock .= "Store voice guidelines (primary, lean toward this):\n{$storeVoice}\n";
    }

    $profileLine = $profileSummary !== '' ? $profileSummary : '[not provided]';
    $vehicleLine = $vehicleLabel !== '' ? $vehicleLabel : '[not provided]';
    $customerLine = $customerName !== '' ? $customerName : '[not provided]';

    $prompt = "Write 1–2 sentences introducing the personalized protection plan.\n"
        . "Must include the customer name and vehicle label verbatim.\n"
        . "Customer name: {$customerLine}\n"
        . "Vehicle label: {$vehicleLine}\n"
        . "Customer profile summary: {$profileLine}\n"
        . ($voiceBlock !== '' ? ($voiceBlock . "\n") : '')
        . "Rules:\n"
        . "- No greetings or pleasantries.\n"
        . "- Do not use phrases like \"May I suggest\" or \"Given your driving profile\".\n"
        . "- Do not repeat product descriptions.\n"
        . "- Convey that the plan aligns with their driving habits, parking environment, and ownership period.\n"
        . "- If you mention the profile, only cite details from the profile summary above.\n";

    if (function_exists('scoring_request_context_line')) {
        scoring_debug("🧭 Gemini intro request context: " . scoring_request_context_line([
            'deal_id' => $deal['id'] ?? null,
            'model' => 'gemini-1.5-flash',
            'purpose' => 'intro',
        ]));
    }

    $ch = curl_init("https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=" . urlencode($apiKey));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json"
        ],
        CURLOPT_POSTFIELDS => json_encode([
            "contents" => [
                ["parts" => [["text" => $prompt]]]
            ],
            "systemInstruction" => [
                "parts" => [["text" => "You are a helpful vehicle protection advisor."]]
            ],
            "generationConfig" => [
                "maxOutputTokens" => 120,
                "temperature" => 0.3
            ]
        ])
    ]);

    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        $curlErr = curl_error($ch);
        if (function_exists('scoring_debug')) {
            scoring_debug("❌ Gemini intro CURL error: " . $curlErr);
        }
        curl_close($ch);
        return $fallback;
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (function_exists('scoring_debug')) {
        scoring_debug("🔍 Gemini intro response (HTTP $httpCode): " . $response);
    }

    $data = json_decode($response, true);
    $reply = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
    $cleanReply = trim((string)$reply);
    if ($cleanReply !== '') {
        $cleanReply = preg_replace(
            '/^\s*(may i suggest|given your driving profile|based on your driving profile|with your driving profile|considering your driving profile|given your profile|based on your profile|considering your profile)[^a-z0-9]*/i',
            '',
            $cleanReply
        );
        return $cleanReply;
    }
    return $fallback;
}

function is_luxury_vehicle_make(?string $make): bool {
    if ($make === null) {
        return false;
    }
    $normalized = strtolower(trim($make));
    if ($normalized === '') {
        return false;
    }
    static $luxuryMakes = [
        'land rover',
        'range rover',
        'mercedes',
        'bmw',
        'jaguar',
        'lexus',
        'audi',
        'porsche', 
        'cadillac',
        'infiniti',
    ];
    return in_array($normalized, $luxuryMakes, true);
}

function first_variant_key(array $variants): ?string {
    foreach ($variants as $key => $_) {
        return $key;
    }
    return null;
}

function pick_default_variant_key(array $variants, bool $preferIntermediate = false): ?string {
    if (empty($variants)) {
        return null;
    }
    if ($preferIntermediate) {
        foreach ($variants as $key => $variant) {
            $label = $variant['label'] ?? '';
            if ($label !== '' && stripos($label, 'intermediate') !== false) {
                return $key;
            }
        }
    }
    return first_variant_key($variants);
}

if (!function_exists('rgba_from_hex')) {
    function rgba_from_hex(string $color, float $alpha = 1.0): string {
        $normalized = normalize_hex_color($color);
        $hex = ltrim($normalized, '#');
        $rgb = [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
        $alpha = max(0, min(1, $alpha));
        return sprintf('rgba(%d, %d, %d, %.2f)', $rgb[0], $rgb[1], $rgb[2], $alpha);
    }
}

$brandColor = normalize_hex_color($theme['color'] ?? '#0a6280');
$productPanelBackground = rgba_from_hex($brandColor, 0.08);
$productBorderColor = rgba_from_hex($brandColor, 0.18);
$paymentHighlightBackground = rgba_from_hex($brandColor, 0.12);
$paymentBorderColor = rgba_from_hex($brandColor, 0.35);

// Fetch Recommendations
$rStmt = $db->prepare("
    SELECT
      deal_id,
      product_code,
      product_name,
      sale_price,
      description,
      score,
      ai_explanation
    FROM product_recommendations
    WHERE deal_id = ?
");
$rStmt->execute([$deal_id]);
$recs = $rStmt->fetchAll(PDO::FETCH_ASSOC);

// Final safety filter: if a product is not viable for this deal, do not show it
// even if it was previously scored/saved (e.g., pre-scored mode or snapshots).
$loanAmountEstimate = function_exists('estimate_amount_financed')
    ? estimate_amount_financed($deal, (float)($includedTotal ?? 0.0) + (float)($accessoryTotalRolledIn ?? 0.0))
    : null;
$salePriceValue = (float)($deal['sale_price'] ?? 0.0);
$recs = array_values(array_filter($recs, function ($row) use ($loanAmountEstimate, $salePriceValue) {
    $codeKey = strtolower(trim((string)($row['product_code'] ?? '')));
    if ($codeKey === 'gapprotection') {
        return $loanAmountEstimate === null || $loanAmountEstimate < 120000;
    }
    if ($codeKey === 'assetprotection') {
        return $salePriceValue <= 125000;
    }
    return true;
}));
$vehicleCondition = strtolower(trim((string)($deal['vehicle_condition'] ?? '')));
if ($vehicleCondition === 'new') {
    $recs = array_values(array_filter($recs, static function (array $row): bool {
        $codeKey = strtolower(trim((string)($row['product_code'] ?? '')));
        if (!in_array($codeKey, ['extwarranty', 'warranty'], true)) {
            return true;
        }
        $name = strtolower(trim((string)($row['product_name'] ?? '')));
        $provider = strtolower(trim((string)($row['provider'] ?? '')));
        return !str_contains($name, 'cpo') && !str_contains($provider, 'cpo');
    }));
}
$snapshotRecommendedLookup = [];
if ($snapshotMode && !empty($snapshotRecommended)) {
    foreach ($snapshotRecommended as $code) {
        $snapshotRecommendedLookup[(string)$code] = true;
    }
    $recs = array_values(array_filter($recs, function ($row) use ($snapshotRecommendedLookup) {
        $code = $row['product_code'] ?? '';
        return $code !== '' && isset($snapshotRecommendedLookup[$code]);
    }));
}
if (!empty($includedCodeLookup)) {
    $recs = array_values(array_filter($recs, function ($row) use ($includedCodeLookup) {
        $codeKey = strtolower(trim((string)($row['product_code'] ?? '')));
        return $codeKey === '' || !isset($includedCodeLookup[$codeKey]);
    }));
}
$requiresQuoteMap = [];
$codesForQuoteLookup = array_values(array_filter(array_unique(array_map(fn($row) => $row['product_code'] ?? '', $recs))));
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
$vehicleMakeForVariantSelection = trim((string)($deal['vehicle_make'] ?? ''));
if ($vehicleMakeForVariantSelection === '' && !empty($deal['vehicle_make_id'])) {
    $makeStmt = $db->prepare("SELECT make_name FROM vehicle_makes WHERE id = ?");
    $makeStmt->execute([$deal['vehicle_make_id']]);
    $vehicleMakeForVariantSelection = trim((string)($makeStmt->fetchColumn() ?: ''));
}
$preferredWarrantyProvider = resolve_warranty_provider_preference($vehicleMakeForVariantSelection);

	$productVariantByCode = [];
	if (!empty($codesForQuoteLookup)) {
	    $placeholders = implode(',', array_fill(0, count($codesForQuoteLookup), '?'));
	    $orgIdForCap = isset($deal['organization']) ? (int)$deal['organization'] : 0;
	    $taxFields = '';
	    if ($hasProductLuxuryTaxColumn) {
	        $taxFields .= 'p.contributes_to_luxury_tax, ';
	    }
	    if ($hasProductGstTaxableColumn) {
	        $taxFields .= 'p.gst_taxable, ';
	    }
	    if ($hasProductPstTaxableColumn) {
	        $taxFields .= 'p.pst_taxable, ';
	    }
	    if ($orgIdForCap > 0) {
	        $productStmt = $db->prepare("
	            SELECT p.id, p.code, p.name, p.category, p.provider, p.default_description, p.default_term, p.requires_quote, p.requires_selection, p.option_set_id" . ($taxFields !== '' ? (",\n\t                   " . rtrim($taxFields, ", \t\n\r\0\x0B")) : '') . "
	                   , COALESCE(o.lease_cap_exempt_override, g.lease_cap_exempt_override, p.lease_cap_exempt) AS lease_cap_exempt
	                   , COALESCE(o.finance_cap_exempt_override, g.finance_cap_exempt_override, p.finance_cap_exempt) AS finance_cap_exempt
	            FROM products p
	            LEFT JOIN product_organization_overrides o
	              ON o.product_id = p.id AND o.organization_id = ?
	            LEFT JOIN product_organization_overrides g
	              ON g.product_id = p.id AND g.organization_id = 0
	            WHERE p.code IN ($placeholders)
	            ORDER BY p.name ASC, p.provider ASC
	        ");
        $productStmt->execute(array_merge([$orgIdForCap], $codesForQuoteLookup));
	    } else {
	        $productStmt = $db->prepare("
	            SELECT id, code, name, category, provider, default_description, default_term, requires_quote, requires_selection, option_set_id
	                   " . ($hasProductLuxuryTaxColumn ? ", contributes_to_luxury_tax" : "") . "
	                   " . ($hasProductGstTaxableColumn ? ", gst_taxable" : "") . "
	                   " . ($hasProductPstTaxableColumn ? ", pst_taxable" : "") . ",
	                   lease_cap_exempt, finance_cap_exempt
	            FROM products
	            WHERE code IN ($placeholders)
	            ORDER BY name ASC, provider ASC
	        ");
        $productStmt->execute($codesForQuoteLookup);
    }
    $productRows = $productStmt->fetchAll(PDO::FETCH_ASSOC);
    $productRowsByCode = [];
    foreach ($productRows as $row) {
        $code = (string)($row['code'] ?? '');
        if ($code === '') {
            continue;
        }
        $productRowsByCode[$code][] = $row;
    }
	    foreach ($recs as $rec) {
	        $code = (string)($rec['product_code'] ?? '');
	        if ($code === '' || empty($productRowsByCode[$code])) {
	            continue;
	        }
        $matches = $productRowsByCode[$code];
        $recName = (string)($rec['product_name'] ?? '');
        $picked = null;
        if ($recName !== '') {
            foreach ($matches as $candidate) {
                if (($candidate['name'] ?? '') === $recName) {
                    $picked = $candidate;
                    break;
                }
            }
        }
        if ($picked === null && $recName !== '') {
            $recNameLower = strtolower(trim($recName));
            foreach ($matches as $candidate) {
                if (strtolower(trim((string)($candidate['name'] ?? ''))) === $recNameLower) {
                    $picked = $candidate;
                    break;
                }
            }
        }
        if (
            $picked === null
            && $preferredWarrantyProvider !== null
            && in_array(strtolower($code), ['extwarranty', 'warranty'], true)
        ) {
            foreach ($matches as $candidate) {
                if (strcasecmp((string)($candidate['provider'] ?? ''), $preferredWarrantyProvider) === 0) {
                    $picked = $candidate;
                    break;
                }
            }
        }
        if ($picked === null && count($matches) === 1) {
            $picked = $matches[0];
        } elseif ($picked === null) {
            $picked = $matches[0];
        }
	        if ($picked) {
	            $productVariantByCode[$code] = $picked;
                $requiresQuoteMap[$code] = !empty($picked['requires_quote']);
	        }
	    }
	}

// Optional: if an insurer premium API is configured for a product provider,
// attempt to quote and replace "Request a Customized Quote" with a real price.
if (!empty($productVariantByCode) && !empty($recs) && !empty($requiresQuoteMap)) {
    $includedTotalForQuote = (float)($includedTotal ?? 0.0);
    $accessoryTotalRolledInForQuote = (float)($accessoryTotalRolledIn ?? 0.0);
    foreach ($recs as $idx => $rec) {
        $code = (string)($rec['product_code'] ?? '');
        if ($code === '' || empty($requiresQuoteMap[$code])) {
            continue;
        }
        $variant = $productVariantByCode[$code] ?? null;
        if (!is_array($variant)) {
            continue;
        }
        $category = strtolower(trim((string)($variant['category'] ?? '')));
        if (!in_array($category, ['insurance', 'warranty'], true)) {
            continue;
        }
        if (!empty($variant['requires_selection'])) {
            // Avoid quoting the wrong plan/term. Next step is to quote via API after selection.
            continue;
        }
        try {
            $quote = insurance_quote_try_quote($db, $deal, $variant, $includedTotalForQuote, $accessoryTotalRolledInForQuote);
            if (!empty($quote['ok']) && isset($quote['premium']) && is_numeric($quote['premium'])) {
                $recs[$idx]['sale_price'] = (float)$quote['premium'];
                // Override the "requires quote" behavior when we have a quote.
                $requiresQuoteMap[$code] = false;
            }
        } catch (Throwable $e) {
            // Do not block recommendations page rendering if quote fails.
        }
    }
}

  // Identify products whose pricing rules use loan amount bands (amount financed).
  // These should be dynamically repriced client-side as selections change.
  $loanBasedProductIdLookup = [];
  if (!empty($productVariantByCode) && function_exists('column_exists') && column_exists($db, 'product_deal_pricing_rules', 'product_id')) {
      $ids = [];
      foreach ($productVariantByCode as $row) {
          $pid = isset($row['id']) ? (int)$row['id'] : 0;
          if ($pid > 0) {
              $ids[$pid] = true;
          }
      }
      $idList = array_keys($ids);
      if (!empty($idList)) {
          $placeholders = implode(',', array_fill(0, count($idList), '?'));
          try {
              $stmt = $db->prepare("
                  SELECT DISTINCT product_id
                  FROM product_deal_pricing_rules
                  WHERE product_id IN ($placeholders)
                    AND (
                      (min_loan_amount IS NOT NULL AND min_loan_amount <> '')
                      OR (max_loan_amount IS NOT NULL AND max_loan_amount <> '')
                    )
              ");
              $stmt->execute($idList);
              while ($pid = $stmt->fetchColumn()) {
                  $pid = (int)$pid;
                  if ($pid > 0) {
                      $loanBasedProductIdLookup[$pid] = true;
                  }
              }
          } catch (PDOException $e) {
              $loanBasedProductIdLookup = [];
          }
      }
  }
	
		// Fetch application data (includes usage_data for computed warranty/ownership logic).
		$appColumns = select_existing_columns($db, 'applications', [
            'id', 'deal_id', 'selected_protections', 'usage_data', 'annual_km',
            'ownership_length', 'province', 'housing', 'postal_code',
            'vehicle_make', 'vehicle_model'
        ]);
        $appSelect = !empty($appColumns) ? implode(', ', $appColumns) : '*';
		$appStmt = $db->prepare("SELECT {$appSelect} FROM applications WHERE deal_id = ? LIMIT 1");
		$appStmt->execute([$deal_id]);
	$appData = $appStmt->fetch(PDO::FETCH_ASSOC);
$prevSelected = $appData && $appData['selected_protections']
    ? json_decode($appData['selected_protections'], true)
    : [];
if ($snapshotMode) {
    $prevSelected = $snapshotSelected;
}
$selectedTermMap = parse_term_selections($prevSelected);
	$vehicleMake = $appData['vehicle_make'] ?? ($deal['vehicle_make'] ?? '');
if ($vehicleMake === '' && !empty($deal['vehicle_make_id'])) {
    $makeStmt = $db->prepare("SELECT make_name FROM vehicle_makes WHERE id = ?");
    $makeStmt->execute([$deal['vehicle_make_id']]);
    $vehicleMake = $makeStmt->fetchColumn() ?: $vehicleMake;
}
	$voicePrompts = load_org_voice_prompts($db, $deal['organization'] ?? null);
	$profileSummary = $appData ? dealerfai_build_customer_profile_summary($appData) : '';
    $orgIdForReasoning = isset($deal['organization']) ? (int)$deal['organization'] : 0;
    $aiReasoningEnabled = function_exists('is_ai_reasoning_enabled')
        ? is_ai_reasoning_enabled($db, $orgIdForReasoning)
        : true;
    $aiReasoningAsyncEnabled = $aiReasoningEnabled
        && !$snapshotMode
        && function_exists('scoring_ai_async_enabled')
        && scoring_ai_async_enabled($db);
	$introText = generate_recommendation_intro($deal, $profileSummary, $voicePrompts, $aiReasoningEnabled);
$isLuxuryVehicle = is_luxury_vehicle_make($vehicleMake);
	$preferXpelIntermediateDefault = (float)($deal['sale_price'] ?? 0) >= 80000;

	// Signals available for warranty-aware term selection (extended warranty/CPO).
	$ruleSignals = [];
	if ($appData && function_exists('build_action_rule_signal_context')) {
	    try {
	        $ruleSignals = build_action_rule_signal_context($db, $deal, $appData, (float)$includedTotal);
	    } catch (Throwable $e) {
	        $ruleSignals = [];
	    }
	}

	if (!function_exists('pick_warranty_aware_term_variant_key')) {
	    function pick_warranty_aware_term_variant_key(array $variants, array $ruleSignals): ?string
	    {
	        if (empty($variants)) {
	            return null;
	        }
	        $options = [];
	        foreach ($variants as $k => $v) {
	            $k = (string)$k;
	            $months = null;
	            if (is_array($v) && isset($v['term']) && is_numeric($v['term'])) {
	                $months = (int)$v['term'];
	            } else {
	                $parts = array_map('intval', explode('_', $k));
	                $months = $parts[0] ?? 0;
	            }
	            if ($months > 0) {
	                $options[] = ['key' => $k, 'months' => $months];
	            }
	        }
	        usort($options, fn($a, $b) => ($a['months'] <=> $b['months']) ?: strcmp($a['key'], $b['key']));
	        if (empty($options)) {
	            return null;
	        }

	        $ownership = isset($ruleSignals['computed.ownership_months_estimate']) ? (int)$ruleSignals['computed.ownership_months_estimate'] : null;
	        $gap = $ruleSignals['computed.factory_warranty_gap_months_estimate'] ?? null;
	        $gap = is_numeric($gap) ? (int)$gap : null;
	        $available = !empty($ruleSignals['computed.factory_warranty_available']);

	        // If no warranty data, fall back to existing defaults.
	        if (!$available || $gap === null) {
	            return null;
	        }

	        // "7+ years": bias to the max term available.
	        if ($ownership !== null && $ownership >= 120) {
	            $max = $options[count($options) - 1] ?? null;
	            return $max ? (string)$max['key'] : null;
	        }

	        // If there is no expected gap, prefer the smallest term.
	        if ($gap <= 0) {
	            $min = $options[0] ?? null;
	            return $min ? (string)$min['key'] : null;
	        }

	        // Choose the smallest term that covers the gap; otherwise use max.
	        foreach ($options as $opt) {
	            if (($opt['months'] ?? 0) >= $gap) {
	                return (string)$opt['key'];
	            }
	        }
	        $max = $options[count($options) - 1] ?? null;
	        return $max ? (string)$max['key'] : null;
	    }
	}

// Simple payment estimate
function calculate_payment($deal_type, $price, $term, $rate) {
    if ($deal_type === 'Cash') return null;
    if ($deal_type === 'Lease') {
        return calculate_lease_payment($price, 0, $term, $rate);
    }
    $i = ($rate/100)/12;
    return $i>0
      ? ($price*$i)/(1-pow(1+$i,-$term))
      : ($price/$term);
}

function calculate_lease_payment($cap_cost, $residual_value, $term, $interest_rate): float {
    $cap_cost = (float)$cap_cost;
    $residual_value = (float)$residual_value;
    $term = (int)$term;
    if ($cap_cost <= 0 || $term <= 0) {
        return 0.0;
    }
    $money_factor = ((float)$interest_rate / 100) / 24;
    $depreciation = ($cap_cost - $residual_value) / $term;
    $finance_charge = ($cap_cost + $residual_value) * $money_factor;
    return max(0.0, $depreciation + $finance_charge);
}

function calculate_display_amount(string $dealType, float $price, $term, $rate): float {
    if (strcasecmp($dealType, 'Cash') === 0) {
        return max(0, $price);
    }
    $payment = calculate_payment($dealType, $price, (int)$term, (float)$rate);
    return max(0, $payment ?? 0);
}

function get_payment_frequency_info(string $frequency): array {
    $normalized = strtolower(trim($frequency));
    $normalized = str_replace([' ', '_'], '-', $normalized);
    if ($normalized === 'semi-monthly') {
        return ['ratio' => 12 / 24, 'suffix' => ' semi-monthly'];
    }
    if ($normalized === 'bi-weekly') {
        return ['ratio' => 12 / 26, 'suffix' => ' bi-weekly'];
    }
    if ($normalized === 'weekly') {
        return ['ratio' => 12 / 52, 'suffix' => ' per week'];
    }
    // Default to monthly
    return ['ratio' => 1.0, 'suffix' => '/month'];
}

function format_price(float $value): string {
    return '$' . number_format($value, 2);
}


function get_default_variant_price(array $variants): ?float {
    if (empty($variants)) {
        return null;
    }
    $first = reset($variants);
    return isset($first['price']) ? (float)$first['price'] : null;
}

function extract_coverage_term(array $product): ?int {
    if (!empty($product['coverage_term'])) {
        return (int)$product['coverage_term'];
    }
    if (!empty($product['term'])) {
        return (int)$product['term'];
    }
    $name = $product['product_name'] ?? '';
    if ($name && preg_match('/(\d+)\s*(?:-|\s)?\s*month/i', $name, $matches)) {
        return (int)$matches[1];
    }
    $description = strip_tags($product['description'] ?? '');
    if ($description && preg_match('/(\d+)\s*months?/i', $description, $matches)) {
        return (int)$matches[1];
    }
    return null;
}

function clean_reason_text(?string $value): string {
    if (empty($value)) {
        return '';
    }
    $clean = trim(preg_replace('/\s+/', ' ', strip_tags($value)));
    $clean = preg_replace('/^(hi|hello|hey)(?:\s+there)?[!,.:\-]\s*/i', '', $clean);
    return trim($clean);
}

function strip_standard_package_sentence(string $text): string {
    if ($text === '') {
        return '';
    }
    $sentences = preg_split('/(?<=[.!?])\s+/', trim($text));
    if (empty($sentences)) {
        return '';
    }
    $filtered = [];
    foreach ($sentences as $sentence) {
        if (stripos($sentence, 'standard package') !== false) {
            continue;
        }
        $filtered[] = $sentence;
    }
    return trim(implode(' ', $filtered));
}

function normalize_xpel_label(string $label): string {
    $clean = trim($label);
    if ($clean === '') {
        return '';
    }
    $parts = array_map('trim', explode('-', $clean));
    if (count($parts) >= 2) {
        return end($parts) ?: $clean;
    }
    return $clean;
}

function xpel_variant_value(string $label): string {
    $normalizedLabel = strtolower(normalize_xpel_label($label));
    return match ($normalizedLabel) {
        'intro' => 'xpel_intro',
        'basic' => 'xpel_basic',
        'intermediate' => 'xpel_intermediate',
        'premium' => 'xpel_premium',
        'premium plus' => 'xpel_premium_plus',
        'full vehicle' => 'xpel_full_vehicle',
        'full vehicle wrap' => 'xpel_full_vehicle_wrap',
        default => $normalizedLabel !== '' ? ('xpel_' . preg_replace('/[^a-z0-9]+/', '_', $normalizedLabel)) : 'xpel_package',
    };
}

function has_xpel_variant_selection(array $selected): bool {
    foreach ($selected as $code) {
        if (is_string($code) && str_starts_with($code, 'xpel_')) {
            return true;
        }
    }
    return false;
}

function resolve_xpel_package_detail(string $label, ?string $summary, ?string $description): string {
    $normalizedLabel = strtolower(normalize_xpel_label($label));
    $map = [
        'intro' => 'Covers the leading edge of the hood and fenders and backs of the painted mirrors.',
        'basic' => 'Covers the leading edge of the hood and fenders, painted front bumper, and backs of the painted mirrors.',
        'intermediate' => 'Covers the entire hood, painted front bumper, and backs of the painted mirrors.',
        'premium' => 'Covers the entire hood and fenders, painted front bumper, A-pillar, roof line, and backs of painted mirrors.',
        'full vehicle' => 'Covers all painted surfaces of your vehicle, protecting the factory paint from stone chips, nicks, and scratches.',
        'full vehicle wrap' => 'Covers all painted surfaces of your vehicle, protecting the factory paint from stone chips, nicks, and scratches.',
    ];
    if ($normalizedLabel !== '' && isset($map[$normalizedLabel])) {
        return $map[$normalizedLabel];
    }
    $detail = trim(strip_standard_package_sentence($summary ?? ''));
    if ($detail === '') {
        $detail = trim(strip_standard_package_sentence($description ?? ''));
    }
    if ($detail !== '') {
        return $detail;
    }
    return $normalizedLabel !== '' ? ('Coverage level: ' . ucfirst($normalizedLabel)) : '';
}

function get_display_product_name(string $code, string $fallback): string {
    $code = strtolower(trim($code));
    return match ($code) {
        'xpel', 'filmprotection' => 'XPEL Paint Protection Film',
        default => $fallback,
    };
}

// Split by score.
// Store-level rule option: when CAP-with-GAP is disabled and both GAP/CAP exist,
// keep both visible but demote the lower-scored one from the Top 5 section/menu.
$deferFromTopCode = null;
if (!$showCapWithGap) {
    $gapScore = null;
    $capScore = null;
    foreach ($recs as $row) {
        $codeKey = strtolower(trim((string)($row['product_code'] ?? '')));
        if ($codeKey === 'gapprotection' && is_numeric($row['score'] ?? null)) {
            $gapScore = (float)$row['score'];
        } elseif ($codeKey === 'assetprotection' && is_numeric($row['score'] ?? null)) {
            $capScore = (float)$row['score'];
        }
    }
    if ($gapScore !== null && $capScore !== null) {
        if ($gapScore > $capScore) {
            $deferFromTopCode = 'assetprotection';
        } elseif ($capScore > $gapScore) {
            $deferFromTopCode = 'gapprotection';
        }
    }
}
$ranked = [];
$others = [];
$deferredPair = [];
foreach ($recs as $r) {
    $codeKey = strtolower(trim((string)($r['product_code'] ?? '')));
    if ($deferFromTopCode !== null && $codeKey === $deferFromTopCode) {
        $deferredPair[] = $r;
        continue;
    }
    $score = $r['score'] ?? 0;
    if ($score > 0) $ranked[] = $r;
    else $others[] = $r;
}
usort($ranked, fn($a,$b) => ($b['score']??0) <=> ($a['score']??0));
$top = array_slice($ranked, 0, 5);
$bottom = array_merge($deferredPair, array_slice($ranked, 5), $others);

$downPayment = max(0, (float)($deal['down_payment'] ?? 0));
$baseSalePrice = (float)($deal['sale_price'] ?? 0) + $includedTotal + (float)($accessoryTotalRolledIn ?? 0.0);
$loanAmount = max(0, $baseSalePrice - $downPayment);
if (($deal['deal_type'] ?? '') === 'Lease') {
    $capCost = (float)($deal['sale_price'] ?? 0)
        + (float)($deal['documentation_fee'] ?? 0)
        + $includedTotal
        + (float)($accessoryTotalRolledIn ?? 0.0)
        + (float)($deal['ppsa_fee'] ?? 0);
    $capCost -= $downPayment + (float)($deal['trade_value'] ?? 0);
    $capCost += (float)($deal['lien_amount'] ?? 0);
    $capCost = max(0, $capCost);
    if ($leaseCapEnabled && $leaseCapLimit > 0 && $capCost > $leaseCapLimit) {
        $capCost = $leaseCapLimit;
    }
    $vehiclePayment = calculate_lease_payment(
        $capCost,
        (float)($deal['residual'] ?? 0),
        (int)($deal['term'] ?? 0),
        (float)($deal['interest_rate'] ?? 0)
    );
} else {
    $vehiclePayment = $isCashDeal
        ? $baseSalePrice
        : calculate_payment(
            $deal['deal_type'],
            $loanAmount,
            (int)($deal['term'] ?? 0),
            (float)($deal['interest_rate'] ?? 0)
        );
}
$paymentFrequency = $deal['payment_frequency'] ?? 'Monthly';
$frequencyInfo = $isCashDeal
    ? ['ratio' => 1.0, 'suffix' => '']
    : get_payment_frequency_info($paymentFrequency);
$frequencyRatio = $frequencyInfo['ratio'];
$frequencySuffix = $frequencyInfo['suffix'];
$vehiclePaymentDisplay = $vehiclePayment
    ? format_price($vehiclePayment * $frequencyRatio) . $frequencySuffix
    : ($isCashDeal
        ? 'Working with your dealer to confirm the total price.'
        : 'Working with your dealer to confirm the monthly payment.');
$summaryTitle = $isCashDeal ? 'Your current total' : 'Your current payment';
$summaryLabelCurrent = $isCashDeal ? 'Current total' : 'Current payment';
$summaryLabelUpdated = $isCashDeal ? 'Updated total' : 'Updated payment';
$summaryNoteDefault = $isCashDeal
    ? 'Add a coverage to see your updated total with protections.'
    : 'Add a coverage to see your updated payment with protections.';
$summaryDetailNote = $isCashDeal
    ? 'This figure reflects your vehicle price plus any protections already included in the deal; selecting or deselecting coverage will update it so you can see the full amount in one place.'
    : 'This figure reflects your vehicle payment plus any protections already included in the deal; selecting or deselecting coverage will update it so you can see the full financed amount in one place.';

$topProductCodes = [];
$topProductNames = [];
$topPaymentMap = [];
foreach ($top as $productItem) {
    $productCode = $productItem['product_code'] ?? '';
    if (!$productCode) {
        continue;
    }
    $topProductCodes[] = $productCode;
    $effectivePrice = $productItem['sale_price'] ?? 0;
    $variant = $productVariantByCode[$productCode] ?? null;
    $leaseCapExempt = !empty($variant['lease_cap_exempt']);
    $financeCapExempt = !empty($variant['finance_cap_exempt']);
    $normalizedCode = strtolower(trim($productCode));
    $orgId = isset($deal['organization']) ? (int)$deal['organization'] : null;
    $termSet = [];
    if (isset($productVariantByCode[$productCode])) {
        $termSet = load_product_term_variants($db, $productVariantByCode[$productCode], $deal, $orgId);
    }
    $variants = in_array($normalizedCode, ['tire_rim', 'tirerim'], true)
        ? []
        : (!empty($termSet) ? $termSet : load_product_package_variants($db, $productCode, $orgId, $deal['vehicle_condition'] ?? null, $deal));
    $selectedVariantLabel = '';
    $selectedVariantPrice = null;
	    if (!empty($variants)) {
	        $preferIntermediate = $normalizedCode === 'xpel' && ($isLuxuryVehicle || $preferXpelIntermediateDefault);
	        $selectedVariantKey = null;
	        if (!empty($termSet) && isset($productVariantByCode[$productCode])) {
	            $defaultTerm = resolve_default_term_for_product($db, (int)$productVariantByCode[$productCode]['id'], $orgId);
	            if ($defaultTerm) {
	                $selectedVariantKey = (string)$defaultTerm;
	            }
	        }
	        // Warranty-aware default: if we have warranty gap signals and this is extended warranty,
	        // pick the term that best matches the customer's expected ownership vs factory warranty remaining.
	        if (!empty($termSet) && in_array($normalizedCode, ['extwarranty', 'warranty'], true)) {
	            $wKey = pick_warranty_aware_term_variant_key($variants, $ruleSignals);
	            if ($wKey !== null && isset($variants[$wKey])) {
	                $selectedVariantKey = $wKey;
	            }
	        }
	        if (!$selectedVariantKey) {
	            $selectedVariantKey = pick_default_variant_key($variants, $preferIntermediate);
	        }
	        if ($selectedVariantKey !== null && isset($variants[$selectedVariantKey])) {
	            $variant = $variants[$selectedVariantKey];
            $variantLabel = $variant['label'] ?? '';
            $baseName = $productItem['product_name'] ?? $productCode;
            $selectedVariantLabel = !empty($termSet)
                ? $baseName . ' (' . $variantLabel . ')'
                : $variantLabel;
            if (isset($variant['price'])) {
                $selectedVariantPrice = (float)$variant['price'];
            }
        }
    }
    if ($selectedVariantPrice !== null) {
        $effectivePrice = $selectedVariantPrice;
    }
    $topProductNames[$productCode] = $selectedVariantLabel ?: ($productItem['product_name'] ?? $productCode);
    $paymentCandidate = calculate_display_amount(
        $deal['deal_type'],
        (float)$effectivePrice,
        $deal['term'],
        $deal['interest_rate']
    );
    $topPaymentMap[$productCode] = $paymentCandidate;
}

$menuEligibleCodes = [];
$menuSeen = [];
foreach ($topProductCodes as $code) {
    $code = (string)$code;
    if ($code === '' || isset($menuSeen[$code])) {
        continue;
    }
    $menuSeen[$code] = true;
    $menuEligibleCodes[] = $code;
}
$menuProductCodes = array_slice($menuEligibleCodes, 0, min(5, count($menuEligibleCodes)));
$packageMenuConfig = [];
if (!empty($menuProductCodes)) {
    $packageCount = min(4, max(3, count($menuProductCodes)));
    $packageCount = min($packageCount, count($menuProductCodes));
    $packageCount = max(1, $packageCount);
    $packageSets = [];
    for ($i = 0; $i < $packageCount; $i++) {
        $limit = max(1, count($menuProductCodes) - $i);
        $codes = array_slice($menuProductCodes, 0, $limit);
        $totalPayment = array_sum(array_map(fn($code) => $topPaymentMap[$code] ?? 0, $codes));
        $packageSets[] = ['codes' => $codes, 'payment' => $totalPayment];
    }
    $resolvedLabels = finalize_package_menu_labels($customPackageMenuLabels, count($packageSets), get_default_package_menu_labels());
    foreach ($packageSets as $idx => $set) {
        $packageMenuConfig[] = [
            'label' => $resolvedLabels[$idx] ?? ('Package ' . ($idx + 1)),
            'codes' => $set['codes'],
            'payment' => $set['payment'],
        ];
    }
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Recommended Protection Products</title>
  <?php include __DIR__ . "/includes/head_favicon.php"; ?>
<?php dealerfai_theme_head($theme ?? null); ?>
  <style nonce="<?= dealerfai_csp_nonce() ?>">
    body { font-family: Arial, sans-serif; background:<?= htmlspecialchars($theme['page_background']) ?>; margin:0; padding:0; }
    :root {
      --brand-color: <?= htmlspecialchars($brandColor) ?>;
      --product-bg: <?= htmlspecialchars($productPanelBackground) ?>;
      --payment-bg: <?= htmlspecialchars($paymentHighlightBackground) ?>;
      --product-border: <?= htmlspecialchars($productBorderColor) ?>;
      --payment-border: <?= htmlspecialchars($paymentBorderColor) ?>;
    }
    header {
      background: <?= htmlspecialchars($theme['header_background']) ?>;
      padding:20px; text-align:center; color:<?= htmlspecialchars($theme['header_text']) ?>;
    }
    header img {
      max-width: 480px;
      max-height: 180px;
      height: auto;
      display: block;
      margin: 0 auto 10px;
    }
    main.recommendation-layout {
      max-width: 1200px;
      margin: 30px auto 60px;
      padding: 0 20px;
      display: flex;
      gap: 24px;
      align-items: flex-start;
      flex-wrap: wrap;
    }
    .card {
      background:#fff;
      padding:30px;
      border-radius:10px;
      box-shadow:0 2px 10px rgba(0,0,0,0.12);
      border:1px solid #e6ecf2;
    }
    .primary-panel {
      flex: 2 1 620px;
      min-width: 320px;
    }
    .sidebar-panel {
      flex: 1 1 260px;
      min-width: 260px;
      align-self: flex-start;
      position: sticky;
      top: 20px;
    }
    .plan-copy {
      margin-bottom: 18px;
      line-height: 1.5;
      color: #1f3d5e;
      display: flex;
      justify-content: center;
    }
    .plan-copy .intro-text {
      font-size: 1.05rem;
      max-width: 780px;
      margin: 0;
    }
    .plan-fit-label,
    .product-fit-label {
      margin: 16px 0 6px;
      font-size: 0.9rem;
      font-weight: 600;
      color: #2e4c71;
    }
    .product-fit-label {
      margin-top: 12px;
      margin-bottom: 4px;
    }
    .product-description,
    .reason {
      margin: 0;
      font-size: 0.95rem;
      color: #1f3d5e;
      line-height: 1.4;
    }
    h2, h3 { margin-top:0; color:#333; }
    .product {
      background: var(--product-bg);
      border-radius: 14px;
      padding: 18px 20px;
      margin-bottom: 20px;
      border: 1px solid var(--product-border);
      box-shadow: 0 6px 18px rgba(15, 23, 42, 0.08);
      transition: transform 0.2s ease, border-color 0.2s ease;
    }
    .product:hover {
      transform: translateY(-2px);
      border-color: var(--brand-color);
    }
    .product:last-child {
      margin-bottom: 0;
    }
    .product-title { font-weight:bold; font-size:1.1em; display:flex; align-items:center; gap:6px; }
    .product-header {
      display:flex;
      justify-content:space-between;
      align-items:center;
      gap:12px;
    }
    .payment-change {
      font-weight:600;
      color: var(--brand-color);
      text-align:right;
      min-width:140px;
      position:relative;
      cursor:default;
      background: var(--payment-bg);
      border: 1px solid var(--payment-border);
      border-radius: 10px;
      padding: 10px 12px;
      display: flex;
      flex-direction: column;
      align-items: flex-end;
      gap: 4px;
      box-shadow: 0 6px 18px rgba(15, 23, 42, 0.08);
    }
    .payment-change-amount { display:block; }
    .price-info {
      font-weight:normal;
      font-size:0.85rem;
      color:#182f4b;
      opacity:0;
      transition: opacity 0.2s ease;
      display:inline-block;
      margin-top:2px;
    }
    .payment-change:hover .price-info {
      opacity:1;
    }
    .package-select-wrapper {
      margin-top: 10px;
    }
    .package-select {
      width: 100%;
      max-width: 360px;
      padding: 9px 12px;
      border-radius: 6px;
      border: 1px solid #d2d9e2;
      background: #fff;
      font-size: 0.95rem;
      color: #162f4b;
    }
    .package-detail {
      font-size: 0.85rem;
      color: #5c6370;
      margin: 6px 0 0;
    }
    .package-detail:empty {
      display: none;
    }
    .small { font-size:0.9em; color:#555; margin-top:5px; }
    .coverage-term { font-size:0.9em; color:#666; margin-top:4px; display:block; }
    .summary-card {
      background:#fff;
      padding:20px;
      border-radius:10px;
      box-shadow:0 2px 12px rgba(0,0,0,0.14);
      border:1px solid #e0e7f1;
    }
    .summary-card h3 { margin-top:0; margin-bottom:10px; }
    .summary-row {
      display:flex;
      justify-content:space-between;
      margin:12px 0;
      line-height:1.2;
      font-weight:600;
    }
    .summary-label { color:#555; font-weight:500; }
    .summary-note { font-size:0.85rem; color:#5c6370; margin:0; }
    .back-button {
      display:inline-block; background:#ccc; color:#333;
      padding:10px 16px; font-size:14px; border-radius:4px;
      text-decoration:none; margin-top:20px;
    }
    .btn {
      background: var(--brand-color);
      color:white; padding:12px 20px; border:none;
      border-radius:4px; font-size:16px; cursor:pointer;
      margin-top:20px;
    }
    .btn-decline {
      background: #e6ebf2;
      color: #1b2c40;
      border: 1px solid #c7d0d8;
      margin-left: 12px;
    }
    body.snapshot-mode .snapshot-lock {
      pointer-events: none;
    }
    body.snapshot-mode .snapshot-hide {
      display: none;
    }
    body.snapshot-mode .snapshot-note {
      background: #fff6dc;
      border: 1px solid #f6c343;
      color: #3b2d00;
      padding: 12px 16px;
      border-radius: 12px;
      margin: 20px auto 0;
      max-width: 1100px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
      font-weight: 600;
    }
    body.snapshot-mode .snapshot-note button {
      background: #1b2c40;
      color: #fff;
      border: none;
      border-radius: 6px;
      padding: 8px 14px;
      font-weight: 600;
      cursor: pointer;
    }
    @media (max-width: 960px) {
      main.recommendation-layout {
        flex-direction:column;
      }
      .sidebar-panel {
        width:100%;
        position: static;
      }
    }
    .package-menu {
      margin-bottom: 20px;
    }
    .package-menu-grid {
      display: grid;
      gap: 16px;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    }
    .package-card {
      background: #fff;
      border: 1px solid rgba(15, 23, 42, 0.08);
      border-radius: 14px;
      padding: 18px;
      min-height: 220px;
      display: flex;
      flex-direction: column;
      gap: 8px;
      text-align: left;
      cursor: pointer;
      transition: transform 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease;
      font: inherit;
    }
    .package-card:hover,
    .package-card.is-selected {
      transform: translateY(-2px);
      border-color: var(--brand-color);
      box-shadow: 0 12px 26px rgba(15, 23, 42, 0.18);
    }
    .package-card:focus-visible {
      outline: 2px solid rgba(255, 255, 255, 0.75);
      outline-offset: 2px;
    }
    .package-card .package-label {
      font-weight: 600;
      color: #1a2443;
    }
    .package-card .package-payment {
      font-weight: 600;
      color: var(--brand-color);
    }
    .package-card .package-payment-frequency {
      font-weight: 400;
      font-size: 0.85rem;
      margin-left: 4px;
    }
    .package-product-list {
      margin: 0;
      padding-left: 18px;
      font-size: 0.95rem;
      color: #24324e;
      line-height: 1.4;
    }
    .package-note {
      font-size: 0.85rem;
      color: #5c6370;
    }
    .package-menu-note {
      margin-top: 12px;
      font-size: 0.9rem;
      color: #3b4c6b;
    }
    .package-customize-link {
      color: var(--brand-color);
      font-weight: 600;
    }
  </style>
</head>
<body class="<?= $snapshotMode ? 'snapshot-mode' : '' ?>">
<header>
  <?php if ($theme['logo']): ?>
    <img src="<?= htmlspecialchars($theme['logo']) ?>" alt="Dealer Logo">
  <?php else: ?>
    <h1>DealerFAI Application</h1>
  <?php endif; ?>
</header>

<?php if ($snapshotMode): ?>
  <div class="snapshot-note">
    <span>Snapshot view<?= $snapshotSubmittedAt ? ' from ' . htmlspecialchars(date('F j, Y, g:i a', strtotime($snapshotSubmittedAt))) : '' ?>. Printing will match the client-facing layout.</span>
    <button type="button" onclick="window.print()">Print</button>
  </div>
<?php endif; ?>

<main class="recommendation-layout<?= $snapshotMode ? ' snapshot-lock' : '' ?>">
  <aside class="sidebar-panel">
    <div class="summary-card">
      <h3><?= htmlspecialchars($summaryTitle) ?></h3>
      <div class="summary-row">
        <span class="summary-label" id="current-payment-label"><?= htmlspecialchars($summaryLabelCurrent) ?></span>
        <span id="current-payment"
          data-base-payment="<?= htmlspecialchars(number_format($vehiclePayment ?? 0, 2, '.', '')) ?>"
          data-has-base="<?= $vehiclePayment ? '1' : '0' ?>"
          data-frequency-ratio="<?= htmlspecialchars(sprintf('%.6f', $frequencyRatio)) ?>"
          data-frequency-suffix="<?= htmlspecialchars($frequencySuffix) ?>"
          data-lease-cap-enabled="<?= $leaseCapEnabled && $leaseCapLimit > 0 ? '1' : '0' ?>"
          data-lease-cap-limit="<?= htmlspecialchars(number_format($leaseCapLimit, 2, '.', '')) ?>"
          data-base-cap-cost="<?= htmlspecialchars(number_format($baseCapCost, 2, '.', '')) ?>"
          data-base-finance-amount="<?= htmlspecialchars(number_format($baseFinanceAmount, 2, '.', '')) ?>"
          data-cap-deal-type="<?= $isLeaseDeal ? 'lease' : ($isFinanceDeal ? 'finance' : '') ?>"
          data-lease-residual="<?= htmlspecialchars(number_format((float)($deal['residual'] ?? 0), 2, '.', '')) ?>"
          data-lease-term="<?= htmlspecialchars((string)($deal['term'] ?? '0')) ?>"
          data-lease-rate="<?= htmlspecialchars(number_format((float)($deal['interest_rate'] ?? 0), 4, '.', '')) ?>">
          <?= htmlspecialchars($vehiclePaymentDisplay) ?>
        </span>
      </div>
      <p class="summary-note" class="mt-8">
        <?= htmlspecialchars($summaryDetailNote) ?>
      </p>
      <p id="payment-note" class="summary-note"><?= htmlspecialchars($summaryNoteDefault) ?></p>
      <p class="plan-fit-label" class="mt-12">Included protections</p>
      <p id="included-protections" class="plan-fit"
         data-included='<?= htmlspecialchars(json_encode($includedNames), ENT_QUOTES) ?>'>
        <?= htmlspecialchars(!empty($includedNames) ? implode(', ', $includedNames) : 'None selected yet.') ?>
      </p>
    </div>
  </aside>
  <div class="card primary-panel">
    <h2>Your Personalized Protection Plan</h2>

    <div class="plan-copy">
      <p class="intro-text"><?= htmlspecialchars($introText) ?></p>
    </div>


    <?php if (empty($top)): ?>
      <p>No personalized recommendations available.</p>
    <?php else: ?>
      <?php if (!empty($packageMenuConfig)): ?>
        <section class="package-menu" aria-label="Recommended coverage packages">
          <h3>Coverage menus</h3>
          <div class="package-menu-grid">
          <?php foreach ($packageMenuConfig as $index => $package): ?>
            <button type="button"
              class="package-card"
              data-package-codes="<?= htmlspecialchars(json_encode($package['codes']), ENT_QUOTES) ?>"
              data-package-index="<?= $index ?>"
              aria-pressed="false">
              <span class="package-label"><?= htmlspecialchars($package['label']) ?></span>
              <span class="package-payment">
                <?= htmlspecialchars(format_price($package['payment'] * $frequencyRatio)) ?>
                <?php if (!$isCashDeal): ?>
                  <span class="package-payment-frequency"><?= htmlspecialchars($frequencySuffix) ?></span>
                <?php endif; ?>
              </span>
              <ul class="package-product-list">
                <?php foreach ($package['codes'] as $code): ?>
                  <li><?= htmlspecialchars($topProductNames[$code] ?? $code) ?></li>
                <?php endforeach; ?>
              </ul>
              <span class="package-note">
                <?= htmlspecialchars(sprintf('Includes %d protections', count($package['codes']))) ?>
              </span>
            </button>
          <?php endforeach; ?>
          </div>
          <p class="package-menu-note">
            Still want to fine tune your protections?
            <a href="#custom-builder" class="package-customize-link">Build your own coverage</a>.
          </p>
        </section>
      <?php endif; ?>
      <form method="post" action="<?= $snapshotMode ? '#' : 'submit_protections.php' ?>" id="custom-builder"<?= $snapshotMode ? ' onsubmit="return false;"' : '' ?>>
      <input type="hidden" name="deal_id" value="<?= htmlspecialchars($deal_id) ?>">
      <h3>Fully customizable protections</h3>

	    <?php foreach ($top as $r):
	    $code = $r['product_code'] ?? '';
	    if (!$code) continue;
	    $normalizedCode = strtolower(trim($code));
      $basePrice = $r['sale_price'] ?? 0;
      $paymentValue = calculate_display_amount($deal['deal_type'], (float)$basePrice, $deal['term'], $deal['interest_rate']);
      $termMonths = extract_coverage_term($r);
      $reasonText = clean_reason_text($r['ai_explanation'] ?? $r['description'] ?? '');
      $termSelectionKey = strtolower(trim($code));
	        $checked = (in_array($code, $prevSelected) || isset($selectedTermMap[$termSelectionKey]) || ($normalizedCode === 'xpel' && has_xpel_variant_selection($prevSelected)))
	          ? ' checked'
	          : '';
      $orgId = isset($deal['organization']) ? (int)$deal['organization'] : null;
      $termSet = [];
      if (isset($productVariantByCode[$code])) {
        $termSet = load_product_term_variants($db, $productVariantByCode[$code], $deal, $orgId);
      }
      $hasTermSet = !empty($termSet);
      $packageSet = $hasTermSet
        ? $termSet
        : load_product_package_variants($db, $code, $orgId, $deal['vehicle_condition'] ?? null, $deal);
      $usePackageSet = $packageSet && ($hasTermSet || !in_array($normalizedCode, ['extwarranty', 'tire_rim', 'tirerim'], true));
      $selectedPackageId = null;
      $selectedPackage = [];
      if ($usePackageSet) {
	        if ($hasTermSet) {
	          $selectedTermKey = $selectedTermMap[$termSelectionKey]['key'] ?? null;
	          $selectedTermMonths = $selectedTermMap[$termSelectionKey]['months'] ?? null;
	          if (!$selectedTermKey && isset($productVariantByCode[$code])) {
	            $defaultMonths = resolve_default_term_for_product($db, (int)$productVariantByCode[$code]['id'], $orgId);
	            if ($defaultMonths) {
	              $selectedTermKey = pick_term_variant_key_for_months($termSet, (int)$defaultMonths, true);
	            }
	          }
	          if (!$selectedTermKey) {
	            // Default to matching the deal term. If the exact term isn't offered,
	            // choose the closest available term at or below (e.g., 72/84 -> 60).
	            $selectedTermMonths = (int)($deal['term'] ?? 0);
	            $normalizedCodeForTerm = strtolower(trim((string)$code));
	            $salePriceForTerm = (float)($deal['sale_price'] ?? 0);
	            if (function_exists('normalize_cap_coverage_term') && in_array($normalizedCodeForTerm, ['assetprotection', 'cap'], true)) {
	              $selectedTermMonths = normalize_cap_coverage_term($salePriceForTerm, $selectedTermMonths);
	            }
	          }
	          if (!$selectedTermKey && $selectedTermMonths) {
	            $availableMonths = [];
	            foreach ($termSet as $k => $row) {
	              $m = is_array($row) && isset($row['term']) && is_numeric($row['term'])
	                ? (int)$row['term']
	                : (parse_term_variant_key_months((string)$k) ?? 0);
	              if ($m > 0) $availableMonths[] = $m;
	            }
	            $availableMonths = array_values(array_unique($availableMonths));
	            sort($availableMonths);
	            $chosen = null;
	            foreach ($availableMonths as $m) {
	              if ($m <= (int)$selectedTermMonths) {
	                $chosen = $m;
	              } else {
	                break;
	              }
	            }
	            if ($chosen === null && !empty($availableMonths)) {
	              $chosen = $availableMonths[0];
	            }
	            if ($chosen !== null) {
	              $selectedTermKey = pick_term_variant_key_for_months($termSet, (int)$chosen, true);
	            }
	          }
	          if ($selectedTermKey) {
	            $selectedPackageId = (string)$selectedTermKey;
	          }
	        }
        if (!$selectedPackageId && $hasTermSet) {
          $preferredMonths = preferred_term_months_for_product_code((string)$code);
          if ($preferredMonths) {
            $preferredKey = pick_term_variant_key_for_months($termSet, (int)$preferredMonths, true);
            if ($preferredKey) {
              $selectedPackageId = (string)$preferredKey;
            }
          }
        }
        foreach ($packageSet as $pkgKey => $pkg) {
          $optProductId = isset($pkg['product_id']) ? (int)$pkg['product_id'] : 0;
          $pkgValue = $normalizedCode === 'xpel'
            ? xpel_variant_value($pkg['label'] ?? $pkgKey)
            : (($hasTermSet || $optProductId <= 0) ? $pkgKey : ('variant_' . $termSelectionKey . '_' . $optProductId));
          if (in_array($pkgValue, $prevSelected, true)) {
            $selectedPackageId = $pkgKey;
            break;
          }
        }
        if (
          !$selectedPackageId
          && in_array($normalizedCode, ['ceramiccoating', 'ceramic_coating'], true)
          && in_array(strtolower(trim((string)$themeVariant)), ['jlr', 'land_rover', 'jaguar'], true)
        ) {
          $fusionClassicKey = pick_package_variant_key_for_label_contains($packageSet, 'fusion classic');
          if ($fusionClassicKey) {
            $selectedPackageId = (string)$fusionClassicKey;
          }
        }
        if (!$selectedPackageId) {
          $preferredMonths = preferred_term_months_for_product_code((string)$code);
          if ($preferredMonths) {
            $preferredPackageKey = pick_package_variant_key_for_months($packageSet, (int)$preferredMonths);
            if ($preferredPackageKey) {
              $selectedPackageId = (string)$preferredPackageKey;
            }
          }
        }
        if (!$selectedPackageId) {
          $preferIntermediate = $normalizedCode === 'xpel' && ($isLuxuryVehicle || $preferXpelIntermediateDefault);
          $selectedPackageId = pick_default_variant_key($packageSet, $preferIntermediate);
        }
        if ($selectedPackageId && isset($packageSet[$selectedPackageId])) {
          $selectedPackage = $packageSet[$selectedPackageId];
          $selectedPrice = (float)($selectedPackage['price'] ?? 0);
          $selectedPayment = calculate_display_amount($deal['deal_type'], (float)$selectedPrice, $deal['term'], $deal['interest_rate']);
          $paymentValue = $selectedPayment;
	          if (!empty($selectedPackage['term'])) {
	            $termMonths = (int)$selectedPackage['term'];
	          }
        }
      } else {
        $packageSet = [];
      }
      $baseProductName = $r['product_name'] ?? '';
      $packageLabel = $selectedPackage['label'] ?? '';
      $defaultProductName = get_display_product_name($code, $baseProductName);
	      $displayProductName = $hasTermSet
	        ? ($packageLabel ? $defaultProductName . ' (' . $packageLabel . ')' : $defaultProductName)
	        : ($packageLabel ?: $defaultProductName);
	      $checkboxName = $displayProductName;
	      $variantRow = $productVariantByCode[$code] ?? [];
	      $leaseCapExempt = !empty($variantRow['lease_cap_exempt']);
	      $financeCapExempt = !empty($variantRow['finance_cap_exempt']);
	      $gstTaxable = !array_key_exists('gst_taxable', $variantRow) || !empty($variantRow['gst_taxable']);
	      $pstTaxable = !array_key_exists('pst_taxable', $variantRow) || !empty($variantRow['pst_taxable']);
	      $packageSelectHtml = '';
      if ($packageSet) {
        $pkgSelectId = 'package_select_' . htmlspecialchars($code);
        $packageSelectName = ($normalizedCode === 'xpel')
          ? 'xpel_package'
          : ($hasTermSet
            ? 'term_option[' . htmlspecialchars($code) . ']'
            : 'variant_option[' . htmlspecialchars($code) . ']');
        $selectNameAttr = $packageSelectName ? ' name="' . $packageSelectName . '"' : '';
      $packageSelectHtml = '<div class="package-select-wrapper"><label for="' . $pkgSelectId . '">Choose coverage level:</label><select id="' . $pkgSelectId . '" class="package-select"' . $selectNameAttr . '>';
        foreach ($packageSet as $pkgKey => $pkg) {
          $label = $pkg['label'] ?? $pkgKey;
          $optProductId = isset($pkg['product_id']) ? (int)$pkg['product_id'] : 0;
          $pkgValue = $normalizedCode === 'xpel'
            ? xpel_variant_value($label)
            : (($hasTermSet || $optProductId <= 0) ? $pkgKey : ('variant_' . $termSelectionKey . '_' . $optProductId));
          $fullLabel = $hasTermSet ? ($defaultProductName . ' (' . $label . ')') : $label;
          $rawPrice = (float)($pkg['price'] ?? 0);
          $price = number_format($rawPrice, 2, '.', '');
          $displayText = htmlspecialchars($rawPrice > 0 ? ($label . ' – ' . format_price($rawPrice)) : $label);
          $monthlyValue = $isCashDeal
            ? number_format($rawPrice, 2, '.', '')
            : number_format(max(0, calculate_payment($deal['deal_type'], $rawPrice, $deal['term'], $deal['interest_rate']) ?? 0), 2, '.', '');
          $selectedAttr = ($pkgKey === $selectedPackageId) ? ' selected' : '';
            $detailText = in_array($normalizedCode, ['xpel', 'filmprotection'], true)
              ? resolve_xpel_package_detail($label, $pkg['summary'] ?? '', $pkg['description'] ?? '')
              : ($pkg['summary'] ?? $pkg['description'] ?? '');
            $detailValue = htmlspecialchars($detailText);
          $extraAttrs = '';
          if ($optProductId > 0) {
            $extraAttrs .= " data-product-id=\"" . htmlspecialchars((string)$optProductId) . "\"";
          }
          if (array_key_exists('requires_quote', $pkg)) {
            $optRequiresQuote = !empty($pkg['requires_quote']) ? '1' : '0';
            $extraAttrs .= " data-requires-quote=\"{$optRequiresQuote}\"";
          }
          $packageSelectHtml .= "<option value=\"" . htmlspecialchars($pkgValue) . "\" data-price=\"{$price}\" data-monthly=\"{$monthlyValue}\" data-label=\"" . htmlspecialchars($fullLabel) . "\" data-detail=\"{$detailValue}\"{$extraAttrs}{$selectedAttr}>{$displayText}</option>";
        }
        $packageSelectHtml .= '</select>';
          $packageDetailText = in_array($normalizedCode, ['xpel', 'filmprotection'], true)
            ? resolve_xpel_package_detail($packageLabel ?: $displayProductName, $selectedPackage['summary'] ?? '', $selectedPackage['description'] ?? '')
            : ($selectedPackage['summary'] ?? $selectedPackage['description'] ?? '');
          $packageSelectHtml .= '<p class="package-detail" data-package-detail>' . htmlspecialchars($packageDetailText) . '</p>';
        $packageSelectHtml .= '</div>';
      }
      $descriptionSource = $selectedPackage['description'] ?? $r['description'] ?? $r['ai_explanation'] ?? '';
      if (in_array($normalizedCode, ['xpel', 'filmprotection'], true)) {
        $descriptionSource = strip_standard_package_sentence($descriptionSource);
      }
      $descriptionText = clean_reason_text($descriptionSource);
      $frequencyAmount = $paymentValue * $frequencyRatio;
      $frequencyDisplay = format_price($frequencyAmount);
    ?>
	      <div class="product">
	        <div class="product-header">
	          <label class="product-title">
	            <input class="protection-checkbox" type="checkbox" name="selected[]" value="<?= htmlspecialchars($code) ?>" <?= $checked ?>
	              data-payment="<?= number_format($paymentValue, 2, '.', '') ?>"
	              data-price="<?= number_format((float)($selectedPackage['price'] ?? $basePrice ?? 0), 2, '.', '') ?>"
		              data-lease-cap-exempt="<?= $leaseCapExempt ? '1' : '0' ?>"
		              data-finance-cap-exempt="<?= $financeCapExempt ? '1' : '0' ?>"
		              data-gst-taxable="<?= $gstTaxable ? '1' : '0' ?>"
		              data-pst-taxable="<?= $pstTaxable ? '1' : '0' ?>"
		              data-product-id="<?= htmlspecialchars((string)((int)($variantRow['id'] ?? 0))) ?>"
		              data-loan-based="<?= !empty($loanBasedProductIdLookup[(int)($variantRow['id'] ?? 0)]) ? '1' : '0' ?>"
		              data-requires-quote="<?= ($requiresQuoteMap[$code] ? '1' : '0') ?>"
		              data-name-base="<?= htmlspecialchars($defaultProductName) ?>"
		              data-name="<?= htmlspecialchars($checkboxName) ?>">
            <span class="product-title-text"><?= htmlspecialchars($displayProductName) ?></span>
          </label>
          <div class="payment-change">
            <?php
              $quoteRequired = ($requiresQuoteMap[$code] ?? false);
              $displayPrice = (float)($selectedPackage['price'] ?? $basePrice ?? 0);
              if ($displayPrice <= 0) {
                  $quoteRequired = true;
              }
      $paymentText = $quoteRequired
        ? 'Request a Customized Quote'
        : ($isCashDeal
          ? 'Add to total ' . format_price((float)$displayPrice)
          : 'Include in your financing ' . $frequencyDisplay . $frequencySuffix);
            ?>
            <span class="payment-change-amount"><?= htmlspecialchars($paymentText) ?></span>
            <?php
              $tooltipPrice = isset($selectedPackage['price']) ? (float)$selectedPackage['price'] : (($r['sale_price'] ?? 0));
              $priceTooltip = $tooltipPrice > 0 ? format_price($tooltipPrice) : '';
            ?>
            <?php if ($priceTooltip !== ''): ?>
              <span class="price-info">Protection cost – <?= htmlspecialchars($priceTooltip) ?></span>
            <?php else: ?>
              <span class="price-info" class="d-none"></span>
            <?php endif; ?>
          </div>
        </div>
	        <?= $packageSelectHtml ?>
	        <?php if ($termMonths && !in_array($normalizedCode, ['assetprotection', 'cap'], true)): ?>
	          <?php $selectedKms = isset($selectedPackage['coverage_kms']) && is_numeric($selectedPackage['coverage_kms']) ? (int)$selectedPackage['coverage_kms'] : null; ?>
	          <span class="coverage-term">
	            Term: <?= $termMonths ?> <?= $termMonths === 1 ? 'month' : 'months' ?> coverage<?= $selectedKms ? ' / ' . number_format($selectedKms) . ' km' : '' ?>
	          </span>
	        <?php endif; ?>
        <p class="product-fit-label">What it is</p>
        <p class="product-description"><?= htmlspecialchars($descriptionText ?: 'Coverage tailored for your profile.') ?></p>
        <p class="product-fit-label">Why it is recommended for you</p>
        <p class="reason" data-product-code="<?= htmlspecialchars($code) ?>"><?= htmlspecialchars($reasonText ?: 'This protection supports your driving and ownership needs.') ?></p>
      </div>
      <?php endforeach; ?>

      <h3>Additional Coverage Options</h3>
	    <?php foreach ($bottom as $r):
	    $code = $r['product_code'] ?? '';
	    if (!$code) continue;
	    $normalizedCode = strtolower(trim($code));
        $basePrice = $r['sale_price'] ?? 0;
        $paymentValue = calculate_display_amount($deal['deal_type'], (float)$basePrice, $deal['term'], $deal['interest_rate']);
        $termMonths = extract_coverage_term($r);
        $reasonText = clean_reason_text($r['ai_explanation'] ?? $r['description'] ?? '');
        $termSelectionKey = strtolower(trim($code));
        $checked = (in_array($code, $prevSelected) || isset($selectedTermMap[$termSelectionKey]) || ($normalizedCode === 'xpel' && has_xpel_variant_selection($prevSelected)))
          ? ' checked'
          : '';
        $orgId = isset($deal['organization']) ? (int)$deal['organization'] : null;
        $termSet = [];
        if (isset($productVariantByCode[$code])) {
          $termSet = load_product_term_variants($db, $productVariantByCode[$code], $deal, $orgId);
        }
        $hasTermSet = !empty($termSet);
        $packageSet = $hasTermSet
          ? $termSet
          : load_product_package_variants($db, $code, $orgId, $deal['vehicle_condition'] ?? null, $deal);
        $usePackageSet = $packageSet && ($hasTermSet || $normalizedCode !== 'extwarranty');
        $selectedPackageId = null;
        $selectedPackage = [];
        $baseProductName = $r['product_name'] ?? '';
        $packageLabel = '';
        $checkboxName = $baseProductName;
        $displayProductName = get_display_product_name($code, $baseProductName);
        $defaultProductName = $displayProductName;
        $packageSelectHtml = '';
	        if ($usePackageSet) {
	          if ($hasTermSet) {
	            $selectedTermKey = $selectedTermMap[$termSelectionKey]['key'] ?? null;
	            if (!$selectedTermKey && isset($productVariantByCode[$code])) {
	              $defaultMonths = resolve_default_term_for_product($db, (int)$productVariantByCode[$code]['id'], $orgId);
	              if ($defaultMonths) {
	                $selectedTermKey = pick_term_variant_key_for_months($termSet, (int)$defaultMonths, true);
	              }
	            }
	            if ($selectedTermKey) {
	              $selectedPackageId = (string)$selectedTermKey;
	            }
	          }
          if (!$selectedPackageId && $hasTermSet) {
            $preferredMonths = preferred_term_months_for_product_code((string)$code);
            if ($preferredMonths) {
              $preferredKey = pick_term_variant_key_for_months($termSet, (int)$preferredMonths, true);
              if ($preferredKey) {
                $selectedPackageId = (string)$preferredKey;
              }
            }
          }
          foreach ($packageSet as $pkgKey => $pkg) {
            $optProductId = isset($pkg['product_id']) ? (int)$pkg['product_id'] : 0;
            $pkgValue = $normalizedCode === 'xpel'
              ? xpel_variant_value($pkg['label'] ?? $pkgKey)
              : (($hasTermSet || $optProductId <= 0) ? $pkgKey : ('variant_' . $termSelectionKey . '_' . $optProductId));
            if (in_array($pkgValue, $prevSelected, true)) {
              $selectedPackageId = $pkgKey;
              break;
            }
          }
          if (
            !$selectedPackageId
            && in_array($normalizedCode, ['ceramiccoating', 'ceramic_coating'], true)
            && in_array(strtolower(trim((string)$themeVariant)), ['jlr', 'land_rover', 'jaguar'], true)
          ) {
            $fusionClassicKey = pick_package_variant_key_for_label_contains($packageSet, 'fusion classic');
            if ($fusionClassicKey) {
              $selectedPackageId = (string)$fusionClassicKey;
            }
          }
          if (!$selectedPackageId) {
            $preferredMonths = preferred_term_months_for_product_code((string)$code);
            if ($preferredMonths) {
              $preferredPackageKey = pick_package_variant_key_for_months($packageSet, (int)$preferredMonths);
              if ($preferredPackageKey) {
                $selectedPackageId = (string)$preferredPackageKey;
              }
            }
          }
          if (!$selectedPackageId) {
            $preferIntermediate = $normalizedCode === 'xpel' && ($isLuxuryVehicle || $preferXpelIntermediateDefault);
            $selectedPackageId = pick_default_variant_key($packageSet, $preferIntermediate);
          }
          if ($selectedPackageId && isset($packageSet[$selectedPackageId])) {
            $selectedPackage = $packageSet[$selectedPackageId];
            $selectedPrice = (float)($selectedPackage['price'] ?? 0);
            $selectedPayment = calculate_display_amount($deal['deal_type'], (float)$selectedPrice, $deal['term'], $deal['interest_rate']);
            $paymentValue = $selectedPayment;
          }
	          if (!empty($selectedPackage['term'])) {
	            $termMonths = (int)$selectedPackage['term'];
	          }
          $packageLabel = $selectedPackage['label'] ?? '';
          $checkboxName = $hasTermSet
            ? ($packageLabel ? $displayProductName . ' (' . $packageLabel . ')' : $displayProductName)
            : ($packageLabel ? "$baseProductName ({$packageLabel})" : $baseProductName);
          $pkgSelectId = 'package_select_' . htmlspecialchars($code . '_bottom');
          $packageSelectName = ($normalizedCode === 'xpel')
            ? 'xpel_package'
            : ($hasTermSet
              ? 'term_option[' . htmlspecialchars($code) . ']'
              : 'variant_option[' . htmlspecialchars($code) . ']');
          $selectNameAttr = $packageSelectName ? ' name="' . $packageSelectName . '"' : '';
          $packageSelectHtml = '<div class="package-select-wrapper"><label for="' . $pkgSelectId . '">Choose coverage level:</label><select id="' . $pkgSelectId . '" class="package-select"' . $selectNameAttr . '>';
          foreach ($packageSet as $pkgKey => $pkg) {
            $label = $pkg['label'] ?? $pkgKey;
            $optProductId = isset($pkg['product_id']) ? (int)$pkg['product_id'] : 0;
            $pkgValue = $normalizedCode === 'xpel'
              ? xpel_variant_value($label)
              : (($hasTermSet || $optProductId <= 0) ? $pkgKey : ('variant_' . $termSelectionKey . '_' . $optProductId));
            $fullLabel = $hasTermSet ? ($displayProductName . ' (' . $label . ')') : $label;
            $rawPrice = (float)($pkg['price'] ?? 0);
            $price = number_format($rawPrice, 2, '.', '');
            $displayText = htmlspecialchars($rawPrice > 0 ? ($label . ' – ' . format_price($rawPrice)) : $label);
            $monthlyValue = $isCashDeal
              ? number_format($rawPrice, 2, '.', '')
              : number_format(max(0, calculate_payment($deal['deal_type'], $rawPrice, $deal['term'], $deal['interest_rate']) ?? 0), 2, '.', '');
            $selectedAttr = ($pkgKey === $selectedPackageId) ? ' selected' : '';
          $detailText = in_array($normalizedCode, ['xpel', 'filmprotection'], true)
            ? resolve_xpel_package_detail($label, $pkg['summary'] ?? '', $pkg['description'] ?? '')
            : ($pkg['summary'] ?? $pkg['description'] ?? '');
          $detailValue = htmlspecialchars($detailText);
            $extraAttrs = '';
            if ($optProductId > 0) {
              $extraAttrs .= " data-product-id=\"" . htmlspecialchars((string)$optProductId) . "\"";
            }
            if (array_key_exists('requires_quote', $pkg)) {
              $optRequiresQuote = !empty($pkg['requires_quote']) ? '1' : '0';
              $extraAttrs .= " data-requires-quote=\"{$optRequiresQuote}\"";
            }
            $packageSelectHtml .= "<option value=\"" . htmlspecialchars($pkgValue) . "\" data-price=\"{$price}\" data-monthly=\"{$monthlyValue}\" data-label=\"" . htmlspecialchars($fullLabel) . "\" data-detail=\"{$detailValue}\"{$extraAttrs}{$selectedAttr}>{$displayText}</option>";
          }
          $packageSelectHtml .= '</select>';
          $packageDetailText = in_array($normalizedCode, ['xpel', 'filmprotection'], true)
            ? resolve_xpel_package_detail($packageLabel ?: $defaultProductName, $selectedPackage['summary'] ?? '', $selectedPackage['description'] ?? '')
            : ($selectedPackage['summary'] ?? $selectedPackage['description'] ?? '');
          $packageSelectHtml .= '<p class="package-detail" data-package-detail>' . htmlspecialchars($packageDetailText) . '</p>';
          $packageSelectHtml .= '</div>';
        } else {
          $packageSet = [];
        }
        $frequencyAmount = $paymentValue * $frequencyRatio;
        $frequencyDisplay = format_price($frequencyAmount);
        $quoteRequired = ($requiresQuoteMap[$code] ?? false);
        $displayPrice = (float)($selectedPackage['price'] ?? $basePrice ?? 0);
        if ($displayPrice <= 0) {
            $quoteRequired = true;
        }
	        $paymentText = $quoteRequired
	            ? 'Request a Customized Quote'
	            : ($isCashDeal
	              ? 'Add to total ' . format_price((float)$displayPrice)
	              : 'Include in your financing ' . $frequencyDisplay . $frequencySuffix);
		        $variantRow = $productVariantByCode[$code] ?? [];
		        $leaseCapExempt = !empty($variantRow['lease_cap_exempt']);
		        $financeCapExempt = !empty($variantRow['finance_cap_exempt']);
		        $gstTaxable = !array_key_exists('gst_taxable', $variantRow) || !empty($variantRow['gst_taxable']);
		        $pstTaxable = !array_key_exists('pst_taxable', $variantRow) || !empty($variantRow['pst_taxable']);
		        $descriptionSource = $selectedPackage['description'] ?? $r['description'] ?? $r['ai_explanation'] ?? '';
	        if (in_array($normalizedCode, ['xpel', 'filmprotection'], true)) {
	          $descriptionSource = strip_standard_package_sentence($descriptionSource);
	        }
        $descriptionText = clean_reason_text($descriptionSource);
      ?>
      <div class="product">
        <div class="product-header">
	          <label class="product-title">
	            <input class="protection-checkbox" type="checkbox" name="selected[]" value="<?= htmlspecialchars($code) ?>" <?= $checked ?>
	              data-payment="<?= number_format($paymentValue, 2, '.', '') ?>"
	              data-price="<?= number_format((float)$displayPrice, 2, '.', '') ?>"
		              data-lease-cap-exempt="<?= $leaseCapExempt ? '1' : '0' ?>"
		              data-finance-cap-exempt="<?= $financeCapExempt ? '1' : '0' ?>"
		              data-gst-taxable="<?= $gstTaxable ? '1' : '0' ?>"
		              data-pst-taxable="<?= $pstTaxable ? '1' : '0' ?>"
		              data-product-id="<?= htmlspecialchars((string)((int)($variantRow['id'] ?? 0))) ?>"
		              data-loan-based="<?= !empty($loanBasedProductIdLookup[(int)($variantRow['id'] ?? 0)]) ? '1' : '0' ?>"
		              data-requires-quote="<?= ($requiresQuoteMap[$code] ? '1' : '0') ?>"
		              data-name-base="<?= htmlspecialchars($displayProductName) ?>"
		              data-name="<?= htmlspecialchars($checkboxName) ?>">
            <span class="product-title-text"><?= htmlspecialchars($displayProductName) ?></span>
          </label>
          <div class="payment-change">
            <span class="payment-change-amount"><?= htmlspecialchars($paymentText) ?></span>
            <?php
              $tooltipPrice = isset($selectedPackage['price']) ? (float)$selectedPackage['price'] : (($r['sale_price'] ?? 0));
              $priceTooltip = $tooltipPrice > 0 ? format_price($tooltipPrice) : '';
            ?>
            <?php if ($priceTooltip !== ''): ?>
              <span class="price-info">Protection cost – <?= htmlspecialchars($priceTooltip) ?></span>
            <?php else: ?>
              <span class="price-info" class="d-none"></span>
            <?php endif; ?>
          </div>
        </div>
        <?= $packageSelectHtml ?>
        <?php if ($termMonths && !in_array($normalizedCode, ['assetprotection', 'cap'], true)): ?>
          <span class="coverage-term">Term: <?= $termMonths ?> <?= $termMonths === 1 ? 'month' : 'months' ?> coverage</span>
        <?php endif; ?>
        <p class="product-fit-label">What it is</p>
        <p class="product-description"><?= htmlspecialchars($descriptionText ?: 'Coverage tailored for your profile.') ?></p>
        <p class="product-fit-label">Why it is recommended for you</p>
        <p class="reason" data-product-code="<?= htmlspecialchars($code) ?>"><?= htmlspecialchars($reasonText ?: 'This protection supports your driving and ownership needs.') ?></p>
      </div>
      <?php endforeach; ?>

      <?php foreach ($recs as $r): ?>
      <?php
        $productCode = $r['product_code'] ?? '';
        $variant = $productCode !== '' ? ($productVariantByCode[$productCode] ?? null) : null;
        $leaseCapExempt = !empty($variant['lease_cap_exempt']);
        $financeCapExempt = !empty($variant['finance_cap_exempt']);
      ?>
        <input type="hidden" name="recommendations[]" value="<?= htmlspecialchars($r['product_code']) ?>">
      <?php endforeach; ?>

      <button type="submit" class="btn">Submit Selections</button>
      <?php if (!$snapshotMode): ?>
        <button type="submit" class="btn btn-decline" name="decline_all" value="1">Decline All Protections</button>
      <?php endif; ?>
    </form>
    <?php endif; ?>

    <?php if (!empty($others)): ?>
      <details class="mt-20">
        <summary>Products Not Recommended (Click to Expand)</summary>
        <ul>
        <?php foreach ($others as $r): ?>
          <li><?= htmlspecialchars($r['product_name']) ?> – Not shown due to mismatch with your needs</li>
        <?php endforeach; ?>
        </ul>
      </details>
    <?php endif; ?>

    <a href="<?= $isCashDeal ? 'cash_application_step2.php' : ('application_step3.php?deal_id=' . urlencode($deal_id)) ?>" class="back-button">← Back</a>
  </div>

</main>

	<script nonce="<?= dealerfai_csp_nonce() ?>">
	  (function(){
	    const isCashDeal = <?= json_encode($isCashDeal) ?>;
	    const dealId = <?= json_encode($deal_id) ?>;
	    const aiReasonPollingEnabled = <?= json_encode($aiReasoningAsyncEnabled) ?>;
	    const gstRate = <?= json_encode($gstRate) ?>;
	    const pstRate = <?= json_encode($pstRate) ?>;
	    const hstRate = <?= json_encode($hstRate) ?>;
	    const summaryLabelCurrent = <?= json_encode($summaryLabelCurrent) ?>;
	    const summaryLabelUpdated = <?= json_encode($summaryLabelUpdated) ?>;
	    const summaryNoteDefault = <?= json_encode($summaryNoteDefault) ?>;
    const baseEl = document.getElementById('current-payment');
    const labelEl = document.getElementById('current-payment-label');
    const noteEl = document.getElementById('payment-note');
    const includedEl = document.getElementById('included-protections');
    let includedBase = [];
    if (includedEl?.dataset?.included) {
      try {
        const parsed = JSON.parse(includedEl.dataset.included);
        if (Array.isArray(parsed)) {
          includedBase = parsed.filter(Boolean);
        }
      } catch (error) {
        includedBase = [];
      }
    }
    const includedDefault = includedBase.length
      ? includedBase.join(', ')
      : (includedEl?.textContent ?? '');
    const checkboxes = document.querySelectorAll('.protection-checkbox');
    const baseAmount = parseFloat(baseEl?.dataset?.basePayment ?? '0') || 0;
    const hasBase = baseEl?.dataset?.hasBase === '1';
    const frequencyRatio = parseFloat(baseEl?.dataset?.frequencyRatio ?? '1') || 1;
    const frequencySuffix = baseEl?.dataset?.frequencySuffix ?? '/month';
    const leaseCapEnabled = baseEl?.dataset?.leaseCapEnabled === '1';
    const leaseCapLimit = parseFloat(baseEl?.dataset?.leaseCapLimit ?? '0') || 0;
    const baseCapCost = parseFloat(baseEl?.dataset?.baseCapCost ?? '0') || 0;
    const baseFinanceAmount = parseFloat(baseEl?.dataset?.baseFinanceAmount ?? '0') || 0;
    const capDealType = baseEl?.dataset?.capDealType ?? '';
	    const leaseResidual = parseFloat(baseEl?.dataset?.leaseResidual ?? '0') || 0;
	    const leaseTerm = parseInt(baseEl?.dataset?.leaseTerm ?? '0', 10) || 0;
	    const leaseRate = parseFloat(baseEl?.dataset?.leaseRate ?? '0') || 0;
	    const packageCards = document.querySelectorAll('.package-card');
	    let suppressReprice = false;
	    let isRepricing = false;
	    let repriceTimer = null;

    function formatCurrency(value) {
      return `$${value.toFixed(2)}`;
    }

    function calculateLeasePayment(capCost, residualValue, term, interestRate) {
      if (!Number.isFinite(capCost) || !Number.isFinite(residualValue) || !Number.isFinite(term) || term <= 0) {
        return 0;
      }
      const moneyFactor = (interestRate / 100) / 24;
      const depreciation = (capCost - residualValue) / term;
      const financeCharge = (capCost + residualValue) * moneyFactor;
      return Math.max(0, depreciation + financeCharge);
    }

	    function calculateFinancePayment(amount, term, interestRate) {
	      if (!Number.isFinite(amount) || !Number.isFinite(term) || term <= 0) {
	        return 0;
	      }
      const monthlyRate = (interestRate / 100) / 12;
      if (monthlyRate > 0) {
        return (amount * monthlyRate) / (1 - Math.pow(1 + monthlyRate, -term));
      }
	      return amount / term;
	    }

	    function calculateItemTax(priceValue, gstTaxable, pstTaxable) {
	      const price = Number.isFinite(priceValue) ? priceValue : 0;
	      if (price <= 0) return 0;
	      if (hstRate > 0) {
	        // HST provinces: treat GST-taxable as HST-taxable (one combined tax).
	        return (gstTaxable ? price * hstRate : 0);
	      }
	      return (gstTaxable ? price * gstRate : 0) + (pstTaxable ? price * pstRate : 0);
	    }

	    function updateTotals() {
	      let protectionTotal = 0;
	      let protectionPriceTotal = 0;
	      let protectionExemptTotal = 0;
      let capExcess = 0;
      const selectedProducts = [];
	      checkboxes.forEach(cb => {
	        if (!cb.checked) return;
	        const priceValue = parseFloat(cb.dataset.price || '0');
	        const gstTaxable = (cb.dataset.gstTaxable ?? '1') === '1';
	        const pstTaxable = (cb.dataset.pstTaxable ?? '1') === '1';
	        const itemTax = calculateItemTax(priceValue, gstTaxable, pstTaxable);

	        // Cash deals: show total impact including tax.
	        if (isCashDeal) {
	          protectionTotal += (Number.isFinite(priceValue) ? priceValue : 0) + itemTax;
	        } else {
	          protectionTotal += parseFloat(cb.dataset.payment || '0');
	        }

	        // Finance/lease cap math uses price totals; include tax as part of financed cap.
	        const taxedPriceValue = (Number.isFinite(priceValue) ? priceValue : 0) + itemTax;
	        if (capDealType === 'lease') {
	          if (cb.dataset.leaseCapExempt === '1') {
	            protectionExemptTotal += taxedPriceValue;
	          } else {
	            protectionPriceTotal += taxedPriceValue;
	          }
	        } else if (capDealType === 'finance') {
	          if (cb.dataset.financeCapExempt === '1') {
	            protectionExemptTotal += taxedPriceValue;
	          } else {
	            protectionPriceTotal += taxedPriceValue;
	          }
	        } else {
	          protectionPriceTotal += taxedPriceValue;
	        }
	        if (cb.dataset.name) {
	          selectedProducts.push(cb.dataset.name);
	        }
	      });
      const combinedSelections = [
        ...includedBase,
        ...selectedProducts.filter(name => !includedBase.includes(name))
      ];

      if (baseEl) {
        if (hasBase) {
          if (leaseCapEnabled && leaseCapLimit > 0 && capDealType === 'lease') {
            const rawCapCost = baseCapCost + protectionPriceTotal;
            const cappedCapCost = Math.min(rawCapCost, leaseCapLimit) + protectionExemptTotal;
            capExcess = Math.max(0, rawCapCost - leaseCapLimit);
            const payment = calculateLeasePayment(cappedCapCost, leaseResidual, leaseTerm, leaseRate);
            baseEl.textContent = `${formatCurrency(payment * frequencyRatio)}${frequencySuffix}`;
            if (noteEl && capExcess > 0) {
              noteEl.textContent = `Lease cap cost limited to ${formatCurrency(leaseCapLimit)} (${formatCurrency(capExcess)} moved to cash down).`;
            }
          } else if (leaseCapEnabled && leaseCapLimit > 0 && capDealType === 'finance') {
            const rawFinanceAmount = baseFinanceAmount + protectionPriceTotal;
            const cappedFinanceAmount = Math.min(rawFinanceAmount, leaseCapLimit) + protectionExemptTotal;
            capExcess = Math.max(0, rawFinanceAmount - leaseCapLimit);
            const payment = calculateFinancePayment(cappedFinanceAmount, leaseTerm, leaseRate);
            baseEl.textContent = `${formatCurrency(payment * frequencyRatio)}${frequencySuffix}`;
            if (noteEl && capExcess > 0) {
              noteEl.textContent = `Finance cap limited to ${formatCurrency(leaseCapLimit)} (${formatCurrency(capExcess)} moved to cash down).`;
            }
          } else {
            const total = baseAmount + protectionTotal;
            baseEl.textContent = isCashDeal
              ? formatCurrency(total)
              : `${formatCurrency(total * frequencyRatio)}${frequencySuffix}`;
          }
        } else {
          baseEl.textContent = isCashDeal
            ? 'Awaiting total price details'
            : 'Awaiting vehicle payment details';
        }
      }

      if (labelEl) {
        labelEl.textContent = selectedProducts.length ? summaryLabelUpdated : summaryLabelCurrent;
      }

      if (noteEl) {
        if (leaseCapEnabled && leaseCapLimit > 0 && selectedProducts.length) {
          noteEl.textContent = capExcess > 0 ? noteEl.textContent : '';
        } else {
          noteEl.textContent = selectedProducts.length ? '' : summaryNoteDefault;
        }
      }

	      if (includedEl) {
	        includedEl.textContent = combinedSelections.length
	          ? combinedSelections.join(', ')
	          : (includedDefault || 'None selected yet.');
	      }

	      if (!suppressReprice) {
	        scheduleReprice();
	      }
	    }

	    function scheduleReprice() {
	      if (isCashDeal) return;
	      if (repriceTimer) {
	        clearTimeout(repriceTimer);
	      }
	      repriceTimer = setTimeout(() => {
	        repriceLoanBased().catch(() => {});
	      }, 200);
	    }

    function collectSelectedItems() {
      const selected = [];
      let totalTaxed = 0;
	      checkboxes.forEach(cb => {
	        if (!cb.checked) return;
	        const productId = parseInt(cb.dataset.productId ?? '0', 10) || 0;
	        const priceValue = parseFloat(cb.dataset.price || '0');
	        const gstTaxable = (cb.dataset.gstTaxable ?? '1') === '1';
	        const pstTaxable = (cb.dataset.pstTaxable ?? '1') === '1';
	        const itemTax = calculateItemTax(priceValue, gstTaxable, pstTaxable);
	        const taxed = (Number.isFinite(priceValue) ? priceValue : 0) + itemTax;
	        totalTaxed += taxed;
	        selected.push({
	          code: cb.value,
	          product_id: productId,
	          taxed_price: taxed,
	        });
	      });
      return { selected, totalTaxed };
    }

    async function quoteProductForSelection(checkbox, option) {
      if (!checkbox) return;
      const requiresQuote = checkbox.dataset.requiresQuote === '1';
      if (!requiresQuote) return;

      const productId = parseInt(checkbox.dataset.productId ?? '0', 10) || 0;
      if (!productId) return;

	      const coverageTerm = (() => {
	        const raw = option?.value ?? '';
	        if (String(raw).startsWith('variant_')) return null;
	        const first = String(raw).split(/[_-]/)[0] ?? '';
	        const term = parseInt(first, 10);
	        return Number.isFinite(term) && term > 0 ? term : null;
	      })();
      const sig = `${productId}|${coverageTerm ?? ''}|${checkbox.dataset.name ?? ''}`;
      if (checkbox.dataset.quoteSig === sig) {
        return;
      }
      checkbox.dataset.quoteSig = sig;

      const productEl = checkbox.closest('.product');
      const paymentChangeEl = productEl?.querySelector('.payment-change-amount');
      if (paymentChangeEl) {
        paymentChangeEl.textContent = 'Fetching quote...';
      }

      const payload = {
        deal_id: dealId,
        product_id: productId,
        coverage_term_months: coverageTerm,
        selected_label: option?.dataset?.label ?? null,
        selected_value: option?.value ?? null,
      };

      try {
        const resp = await fetch('api/quote_product.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload),
        });
        if (!resp.ok) return;
        const data = await resp.json();
        if (!data || !data.ok) return;

        const premium = Number(data.premium) || 0;
        if (premium <= 0) return;

        // Apply quoted premium.
        checkbox.dataset.price = premium.toFixed(2);
        let payment = premium;
        if (!isCashDeal) {
          if (capDealType === 'lease') {
            payment = calculateLeasePayment(premium, 0, leaseTerm, leaseRate);
          } else {
            payment = calculateFinancePayment(premium, leaseTerm, leaseRate);
          }
        }
        checkbox.dataset.payment = payment.toFixed(2);
        checkbox.dataset.requiresQuote = '0';

        const priceInfo = productEl?.querySelector('.price-info');
        if (priceInfo) {
          priceInfo.textContent = `Protection cost – ${formatCurrency(premium)}`;
          priceInfo.style.display = '';
        }
        if (paymentChangeEl) {
          paymentChangeEl.textContent = isCashDeal
            ? `Add to total ${formatCurrency(premium)}`
            : `Include in your financing ${formatCurrency(payment)}${frequencySuffix}`;
        }

        updateTotals();
      } catch (error) {
        // ignore
      } finally {
        // If we didn't clear requiresQuote, allow future re-tries for the same selection.
        if (checkbox.dataset.requiresQuote === '1') {
          delete checkbox.dataset.quoteSig;
        }
      }
    }

    async function repriceLoanBased() {
      if (isRepricing) return;
      const targets = Array.from(checkboxes).filter(cb => (cb.dataset.loanBased ?? '0') === '1');
      if (!targets.length) return;

	      isRepricing = true;
	      try {
	        const { selected, totalTaxed } = collectSelectedItems();
	        const payload = {
	          deal_id: dealId,
	          selected_items: selected,
	          selected_taxed_total: totalTaxed,
	          targets: targets.map(cb => ({
	            code: cb.value,
	            product_id: parseInt(cb.dataset.productId ?? '0', 10) || 0,
	          })),
	        };

	        const resp = await fetch('api/reprice_loan_based_products.php', {
	          method: 'POST',
	          headers: { 'Content-Type': 'application/json' },
	          body: JSON.stringify(payload),
	        });
	        if (!resp.ok) return;
	        const data = await resp.json();
	        if (!data || !data.ok || !data.products) return;

	        suppressReprice = true;
	        try {
	          targets.forEach(cb => {
	            const code = cb.value;
	            const productData = data.products?.[code];
	            if (!productData || !productData.terms) return;

	            const productEl = cb.closest('.product');
	            if (!productEl) return;
	            const select = productEl.querySelector('.package-select');

	            if (select) {
	              Array.from(select.options).forEach(opt => {
	                const termKey = String(opt.value ?? '').trim();
	                const term = productData.terms?.[termKey];
	                if (!term) return;
	                const p = Number(term.price) || 0;
	                const m = Number(term.payment) || 0;
	                const baseLabel = (opt.textContent || '').split(' – ')[0] || opt.textContent || termKey;
	                opt.dataset.price = p.toFixed(2);
	                opt.dataset.monthly = m.toFixed(2);
	                opt.textContent = p > 0 ? `${baseLabel} – ${formatCurrency(p)}` : baseLabel;
	              });
	              handlePackageSelection(select);
	              return;
	            }

	            // No select: choose the first returned term and apply it to the checkbox.
	            const keys = Object.keys(productData.terms).sort((a, b) => parseInt(a, 10) - parseInt(b, 10));
	            if (!keys.length) return;
	            const first = productData.terms[keys[0]];
	            const p = Number(first.price) || 0;
	            const m = Number(first.payment) || 0;
	            cb.dataset.price = p.toFixed(2);
	            cb.dataset.payment = m.toFixed(2);

	            const priceInfo = productEl.querySelector('.price-info');
	            if (priceInfo) {
	              if (p > 0) {
	                priceInfo.textContent = `Protection cost – ${formatCurrency(p)}`;
	                priceInfo.style.display = '';
	              } else {
	                priceInfo.textContent = '';
	                priceInfo.style.display = 'none';
	              }
	            }
	            const paymentChangeEl = productEl.querySelector('.payment-change-amount');
	            if (paymentChangeEl) {
	              const isQuote = (cb.dataset.requiresQuote === '1') || p <= 0;
	              paymentChangeEl.textContent = isQuote
	                ? 'Request a Customized Quote'
	                : (isCashDeal
	                  ? `Add to total ${formatCurrency(p)}`
	                  : `Include in your financing ${formatCurrency(m)}${frequencySuffix}`);
	            }
	          });

	          updateTotals();
	        } finally {
	          suppressReprice = false;
	        }
	      } finally {
	        isRepricing = false;
	      }
	    }

	    function parsePackageCodes(card) {
	      const raw = card?.dataset?.packageCodes ?? '[]';
	      try {
	        const parsed = JSON.parse(raw);
        return Array.isArray(parsed) ? parsed : [];
      } catch (error) {
        return [];
      }
    }

    function applyPackageSelection(codes) {
      checkboxes.forEach(cb => {
        cb.checked = codes.includes(cb.value);
      });
      updateTotals();
    }

    function setActivePackage(card) {
      packageCards.forEach(c => {
        c.classList.remove('is-selected');
        c.setAttribute('aria-pressed', 'false');
      });
      if (card) {
        card.classList.add('is-selected');
        card.setAttribute('aria-pressed', 'true');
      }
    }

    function activatePackageCard(card) {
      if (!card) {
        return;
      }
      const codes = parsePackageCodes(card);
      applyPackageSelection(codes);
      setActivePackage(card);
    }

    const packageSelects = document.querySelectorAll('.package-select');
    packageCards.forEach(card => {
      card.addEventListener('click', () => activatePackageCard(card));
      card.addEventListener('keydown', event => {
        if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault();
          card.click();
        }
      });
    });
    function handlePackageSelection(select) {
      const product = select.closest('.product');
      if (!product) return;
      const checkbox = product.querySelector('.protection-checkbox');
      if (!checkbox) return;
      const option = select.selectedOptions[0];
      const price = parseFloat(option?.dataset?.price ?? '0');
      const monthly = parseFloat(option?.dataset?.monthly ?? option?.dataset?.price ?? '0');
      const normalizedPrice = Number.isFinite(price) ? price : 0;
      const normalizedMonthly = Number.isFinite(monthly) ? monthly : 0;
      checkbox.dataset.payment = (isCashDeal ? normalizedPrice : normalizedMonthly).toFixed(2);
      checkbox.dataset.price = normalizedPrice.toFixed(2);
      if (option?.dataset?.productId) {
        const pid = parseInt(option.dataset.productId, 10);
        if (Number.isFinite(pid) && pid > 0) {
          checkbox.dataset.productId = String(pid);
        }
      }
      if (option?.dataset?.requiresQuote) {
        checkbox.dataset.requiresQuote = option.dataset.requiresQuote === '1' ? '1' : '0';
      }
      const titleTextEl = product.querySelector('.product-title-text');
      const baseName = checkbox.dataset.nameBase ?? '';
      const fallbackName = baseName || checkbox.dataset.name || checkbox.value || '';
      const label = option?.dataset?.label ?? '';
      const resolvedName = label || fallbackName;
      checkbox.dataset.name = resolvedName;
      if (titleTextEl) {
        titleTextEl.textContent = resolvedName;
      }
      const priceInfo = product.querySelector('.price-info');
      if (priceInfo) {
        if (normalizedPrice > 0) {
          priceInfo.textContent = `Protection cost – $${normalizedPrice.toFixed(2)}`;
          priceInfo.style.display = '';
        } else {
          priceInfo.textContent = '';
          priceInfo.style.display = 'none';
        }
      }
      const paymentChangeEl = product.querySelector('.payment-change-amount');
      const requiresQuote = checkbox.dataset.requiresQuote === '1';
      if (paymentChangeEl) {
        const isQuote = requiresQuote || normalizedPrice <= 0;
        const paymentText = isQuote
          ? 'Request a Customized Quote'
          : (isCashDeal
            ? `Add to total ${formatCurrency(normalizedPrice)}`
            : `Include in your financing ${formatCurrency(normalizedMonthly)}${frequencySuffix}`);
        paymentChangeEl.textContent = paymentText;
      }
      const detailNode = product.querySelector('[data-package-detail]');
      if (detailNode) {
        detailNode.textContent = option?.dataset?.detail ?? '';
        detailNode.style.display = detailNode.textContent ? '' : 'none';
      }
      updateTotals();

      // If the selected variant requires a quote, try to fetch it now.
      if (checkbox.dataset.requiresQuote === '1') {
        quoteProductForSelection(checkbox, option);
      }
    }
    packageSelects.forEach(select => {
      select.addEventListener('change', () => handlePackageSelection(select));
      handlePackageSelection(select);
    });

    checkboxes.forEach(cb => cb.addEventListener('change', updateTotals));
    if (packageCards.length) {
      activatePackageCard(packageCards[0]);
    } else {
      updateTotals();
    }

    if (aiReasonPollingEnabled) {
      let aiPollAttempts = 0;
      const maxAiPollAttempts = 12;

      const applyAiReasons = (reasons) => {
        if (!reasons || typeof reasons !== 'object') {
          return;
        }
        document.querySelectorAll('.reason[data-product-code]').forEach((node) => {
          const code = node.getAttribute('data-product-code') || '';
          const nextReason = typeof reasons[code] === 'string' ? reasons[code].trim() : '';
          if (nextReason !== '') {
            node.textContent = nextReason;
          }
        });
      };

      const pollAiReasons = async () => {
        aiPollAttempts += 1;
        try {
          const resp = await fetch(`api/recommendation_reasons_status.php?deal_id=${encodeURIComponent(dealId)}`, { cache: 'no-store' });
          if (!resp.ok) {
            throw new Error('Failed to load AI reasons');
          }
          const data = await resp.json();
          if (!data || !data.ok) {
            throw new Error('Invalid AI reason response');
          }

          applyAiReasons(data.reasons || {});
          if (data.ready || aiPollAttempts >= maxAiPollAttempts) {
            return;
          }
        } catch (error) {
          if (aiPollAttempts >= maxAiPollAttempts) {
            return;
          }
        }

        window.setTimeout(pollAiReasons, 3000);
      };

      window.setTimeout(pollAiReasons, 2500);
    }
  })();
</script>

</body>
</html>
