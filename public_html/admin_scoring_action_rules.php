<?php
include 'auth.php';
require_once __DIR__ . '/includes/theme_head.php';
include 'db.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/helpers/session_utils.php';
require_once __DIR__ . '/helpers/db_utils.php';
require_once __DIR__ . '/helpers/organization_question_config.php';
require_once __DIR__ . '/helpers/usage_data.php';
require_once __DIR__ . '/scoring_engine.php';

$roles = load_session_roles();
if (!in_array('Admin', $roles, true)) {
    http_response_code(403);
    echo 'Unauthorized';
    exit;
}

$adminAlertCount = get_admin_alert_count($db);

function table_exists(PDO $db, string $table): bool
{
    try {
        $stmt = $db->prepare("
            SELECT COUNT(*)
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
        ");
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (PDOException $e) {
        return false;
    }
}

function normalize_code(string $raw): string
{
    $raw = trim($raw);
    $raw = preg_replace('/\s+/', '_', $raw);
    $raw = preg_replace('/[^a-zA-Z0-9_\\-]/', '', $raw);
    return $raw ?? '';
}

function normalize_scope(string $raw): string
{
    $raw = strtolower(trim($raw));
    return in_array($raw, ['global', 'org', 'store'], true) ? $raw : 'global';
}

function normalize_target_type(string $raw): string
{
    $raw = strtolower(trim($raw));
    return in_array($raw, ['product', 'accessory'], true) ? $raw : 'product';
}

function normalize_action_type(string $raw): string
{
    $raw = strtolower(trim($raw));
    return in_array($raw, ['score', 'exclude'], true) ? $raw : 'score';
}

function normalize_operator(string $raw): string
{
    $raw = strtolower(trim($raw));
    $allowed = ['equals', 'not_equals', 'gt', 'gte', 'lt', 'lte', 'between', 'in', 'contains', 'exists'];
    return in_array($raw, $allowed, true) ? $raw : 'equals';
}

function operator_options(): array
{
    return [
        'equals' => 'is exactly',
        'not_equals' => 'is not',
        'gt' => 'is greater than',
        'gte' => 'is at least',
        'lt' => 'is less than',
        'lte' => 'is at most',
        'between' => 'is between',
        'in' => 'is one of',
        'contains' => 'contains',
        'exists' => 'has a value',
    ];
}

function operator_label(string $raw): string
{
    $op = normalize_operator($raw);
    $options = operator_options();
    return $options[$op] ?? $op;
}

function build_signal_definitions(): array
{
    // Minimal curated list for the UI. Signals still can be typed manually.
    // The engine evaluates any signal_key that exists in the runtime context.
    return [
        // Deal (Create Deal / deals table)
        ['deal.customer_type', 'Deal customer type (personal/business)'],
        ['deal.term', 'Deal term (months)'],
        ['deal.sale_price', 'Deal sale price'],
        ['deal.msrp', 'Deal MSRP'],
        ['deal.residual', 'Deal residual'],
        ['deal.down_payment', 'Deal down payment'],
        ['deal.payment_frequency', 'Deal payment frequency'],
        ['deal.trade_value', 'Deal trade value'],
        ['deal.lien_amount', 'Deal lien amount'],
        ['deal.documentation_fee', 'Deal documentation fee'],
        ['deal.ppsa_fee', 'Deal PPSA fee'],
        ['deal.interest_rate', 'Deal interest rate'],
        ['deal.province', 'Deal province'],
        ['deal.deal_type', 'Deal type'],
        ['deal.vin', 'Vehicle VIN'],
        ['deal.vehicle_make', 'Vehicle make'],
        ['deal.vehicle_model', 'Vehicle model'],
        ['deal.vehicle_year', 'Vehicle year'],
        ['deal.vehicle_condition', 'Vehicle condition'],
        ['deal.vehicle_kms', 'Vehicle kms'],
        ['deal.in_service_date', 'Vehicle in-service date'],
        ['deal.vehicle_colour', 'Vehicle colour'],

        // Credit app (usage_data)
        ['usage.ownership_length', 'App: ownership_length'],
        ['usage.annual_km', 'App: annual_km'],
        ['usage.driving_type', 'App: driving_type'],
        ['usage.gravel_exposure', 'App: gravel_exposure'],
        ['usage.road_conditions', 'App: road_conditions'],
        ['usage.overnight_parking', 'App: overnight_parking'],
        ['usage.regular_users', 'App: regular_users'],
        ['usage.food_drink', 'App: food_drink'],
        ['usage.appearance_priority', 'App: appearance_priority'],
        ['usage.life_protection', 'App: life_protection'],
        ['usage.disability_protection', 'App: disability_protection'],
        ['usage.critical_illness', 'App: critical_illness'],
        ['usage.job_loss', 'App: job_loss'],

        // App columns (applications table)
        ['app.full_name', 'App: full_name'],
        ['app.email', 'App: email'],
        ['app.phone', 'App: phone'],
        ['app.address', 'App: address'],
        ['app.city', 'App: city'],
        ['app.province', 'App column: province'],
        ['app.postal_code', 'App column: postal_code'],
        ['app.housing', 'App column: housing'],
        ['app.years_at_address', 'App: years_at_address'],
        ['app.monthly_payment', 'App: monthly_payment'],
        ['app.has_cosigner', 'App: has_cosigner'],
        ['app.co_full_name', 'App: co_full_name'],
        ['app.co_email', 'App: co_email'],
        ['app.co_phone', 'App: co_phone'],
        ['app.co_address', 'App: co_address'],
        ['app.co_city', 'App: co_city'],
        ['app.co_province', 'App: co_province'],
        ['app.co_postal_code', 'App: co_postal_code'],
        ['app.co_housing', 'App: co_housing'],
        ['app.co_years_at_address', 'App: co_years_at_address'],
        ['app.co_monthly_payment', 'App: co_monthly_payment'],

        ['app.employer', 'App: employer'],
        ['app.work_address', 'App: work_address'],
        ['app.position', 'App: position'],
        ['app.employment_length', 'App: employment_length'],
        ['app.prev_employer', 'App: prev_employer'],
        ['app.prev_phone', 'App: prev_phone'],
        ['app.prev_address', 'App: prev_address'],
        ['app.prev_length', 'App: prev_length'],
        ['app.income', 'App: income'],
        ['app.other_income', 'App: other_income'],
        ['app.other_income_source', 'App: other_income_source'],

        ['app.co_employer', 'App: co_employer'],
        ['app.co_work_address', 'App: co_work_address'],
        ['app.co_position', 'App: co_position'],
        ['app.co_employment_length', 'App: co_employment_length'],
        ['app.co_prev_employer', 'App: co_prev_employer'],
        ['app.co_prev_phone', 'App: co_prev_phone'],
        ['app.co_prev_address', 'App: co_prev_address'],
        ['app.co_prev_length', 'App: co_prev_length'],
        ['app.co_income', 'App: co_income'],
        ['app.co_other_income', 'App: co_other_income'],
        ['app.co_other_income_source', 'App: co_other_income_source'],

        // Computed
        ['computed.negative_equity', 'Computed: negative equity (lien - trade)'],
        ['computed.amount_financed_estimate', 'Computed: amount financed estimate'],
        ['computed.down_payment_pct', 'Computed: down payment percent (down / amount_financed)'],
        ['computed.ltv_pct', 'Computed: LTV percent ((amount_financed / sale_price) * 100)'],
        ['computed.factory_warranty_available', 'Computed: factory warranty data available (1/0)'],
        ['computed.factory_warranty_remaining_comprehensive_months', 'Computed: factory warranty remaining (comprehensive, months)'],
        ['computed.factory_warranty_remaining_powertrain_months', 'Computed: factory warranty remaining (powertrain, months)'],
        ['computed.factory_warranty_remaining_comprehensive_kms', 'Computed: factory warranty remaining (comprehensive, kms)'],
        ['computed.factory_warranty_remaining_powertrain_kms', 'Computed: factory warranty remaining (powertrain, kms)'],
        ['computed.factory_warranty_remaining_comprehensive_months_by_km', 'Computed: factory warranty remaining (comprehensive, months by kms)'],
        ['computed.factory_warranty_gap_months_estimate', 'Computed: ownership - remaining comprehensive warranty (months)'],
        ['computed.factory_warranty_projected_end_kms', 'Computed: projected odometer at planned ownership end (kms)'],
        ['computed.factory_warranty_gap_kms_estimate', 'Computed: projected end kms - factory comprehensive km limit'],
    ];
}

function fetch_custom_question_signal_definitions(PDO $db, int $contextOrgId): array
{
    try {
        $stmt = $db->prepare("
            SELECT organization_id, label, field_key, field_type, options_json
            FROM credit_app_custom_questions
            WHERE is_active = 1
              AND organization_id IN (0, ?)
            ORDER BY organization_id ASC, sort_order ASC, id ASC
        ");
        $stmt->execute([$contextOrgId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        return [];
    }

    $defs = [];
    foreach ($rows as $r) {
        $fieldKey = trim((string)($r['field_key'] ?? ''));
        if ($fieldKey === '') continue;
        $label = trim((string)($r['label'] ?? $fieldKey));
        $orgId = (int)($r['organization_id'] ?? 0);
        $suffix = $orgId > 0 ? (' (Org #' . $orgId . ')') : ' (Global)';
        $defs[] = [
            'key' => 'usage.custom.' . $fieldKey,
            'label' => 'Custom: ' . $label . $suffix,
            'field_type' => (string)($r['field_type'] ?? ''),
            'options' => (string)($r['options_json'] ?? ''),
        ];
    }
    return $defs;
}

function build_rule_signal_context(PDO $db, array $deal, array $application, float $includedTotal = 0.0): array
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

    // Deal signals
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
    $signals['deal.vehicle_condition'] = strtolower(trim((string)($deal['vehicle_condition'] ?? '')));
    $signals['deal.vehicle_colour'] = strtolower(trim((string)($deal['vehicle_colour'] ?? '')));
    $signals['deal.vehicle_year'] = $getInt($deal['vehicle_year'] ?? null);
    $signals['deal.vehicle_kms'] = $getInt($deal['vehicle_kms'] ?? null);
    $signals['deal.in_service_date'] = trim((string)($deal['in_service_date'] ?? ''));
    $signals['deal.vin'] = strtoupper(trim((string)($deal['vin'] ?? '')));

    // usage_data signals (decoded)
    $usage = [];
    if (!empty($application['usage_data'])) {
        $usage = dealerfai_decode_usage_data((string)$application['usage_data']);
    }
    foreach ($usage as $k => $v) {
        $key = 'usage.' . (string)$k;
        // Keep scalars/arrays as-is; evaluator will normalize.
        $signals[$key] = $v;
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

    // Computed
    $lien = $signals['deal.lien_amount'];
    $trade = $signals['deal.trade_value'];
    if ($lien !== null && $trade !== null) {
        $signals['computed.negative_equity'] = $lien - $trade;
    } else {
        $signals['computed.negative_equity'] = null;
    }

    // amount financed estimate: reuse existing helper if available.
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
    if ($down !== null && $amountFinanced !== null && $amountFinanced > 0.0) {
        $signals['computed.down_payment_pct'] = $down / $amountFinanced;
    } else {
        $signals['computed.down_payment_pct'] = null;
    }
    $sale = $signals['deal.sale_price'] ?? null;
    if ($sale !== null && $sale > 0.0 && $amountFinanced !== null) {
        $signals['computed.ltv_pct'] = ($amountFinanced / $sale) * 100.0;
    } else {
        $signals['computed.ltv_pct'] = null;
    }

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

    // Warranty-derived signals (optional; requires vehicle_warranties data to exist)
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

function rule_value_list($v): array
{
    if ($v === null) return [];
    if (is_array($v)) {
        return array_values(array_filter(array_map(fn($x) => strtolower(trim((string)$x)), $v), fn($x) => $x !== ''));
    }
    $s = trim((string)$v);
    if ($s === '') return [];
    if (strpos($s, ',') !== false) {
        $parts = array_map('trim', explode(',', $s));
        $parts = array_filter($parts, fn($x) => $x !== '');
        return array_map(fn($x) => strtolower($x), $parts);
    }
    return [strtolower($s)];
}

function evaluate_action_rule(array $rule, array $signals): bool
{
    $key = (string)($rule['signal_key'] ?? '');
    if ($key === '') return false;
    $op = normalize_operator((string)($rule['operator'] ?? 'equals'));

    $val = $signals[$key] ?? null;
    if ($op === 'exists') {
        return !($val === null || $val === '' || (is_array($val) && empty($val)));
    }

    $v1 = $rule['value1'] ?? null;
    $v2 = $rule['value2'] ?? null;

    $isNumericVal = is_numeric($val);
    $numVal = $isNumericVal ? (float)$val : null;
    $num1 = is_numeric($v1) ? (float)$v1 : null;
    $num2 = is_numeric($v2) ? (float)$v2 : null;

    if (in_array($op, ['gt', 'gte', 'lt', 'lte', 'between'], true)) {
        if ($numVal === null) return false;
        if ($op === 'between') {
            if ($num1 === null || $num2 === null) return false;
            return $numVal >= min($num1, $num2) && $numVal <= max($num1, $num2);
        }
        if ($num1 === null) return false;
        return match ($op) {
            'gt' => $numVal > $num1,
            'gte' => $numVal >= $num1,
            'lt' => $numVal < $num1,
            'lte' => $numVal <= $num1,
            default => false,
        };
    }

    $hay = rule_value_list($val);
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
        $needles = rule_value_list($v1);
        if (empty($needles)) return false;
        foreach ($needles as $n) {
            if (in_array($n, $hay, true)) return true;
        }
        return false;
    }

    return false;
}

$hasRulesTable = table_exists($db, 'scoring_action_rules');

$message = '';
$error = '';
$editId = (int)($_GET['edit_id'] ?? 0);
$editRule = null;

// Scope selection
$orgRows = $db->query("SELECT id, name, org_kind, parent_org_id FROM organizations ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$orgOptions = [0 => ['name' => 'Global', 'org_kind' => 'global', 'parent_org_id' => null]];
foreach ($orgRows as $row) {
    $orgOptions[(int)$row['id']] = $row;
}

$scopeType = normalize_scope((string)($_GET['scope_type'] ?? ($_POST['scope_type'] ?? 'global')));
$scopeValue = (string)($_GET['scope_value'] ?? ($_POST['scope_value'] ?? ''));
if ($scopeType === 'global') {
    $scopeValue = '';
}

// Single view: By Input (Signals)
$viewMode = 'inputs';
$contextOrgId = isset($_GET['context_org_id']) ? (int)$_GET['context_org_id'] : 0;
$contextOrgId = $contextOrgId > 0 ? $contextOrgId : 0;
$contextMode = strtolower(trim((string)($_GET['context_mode'] ?? 'effective')));
if (!in_array($contextMode, ['effective', 'exact', 'all'], true)) {
    $contextMode = 'effective';
}

// Value presets: to avoid "typing and guessing" in rule creation.
$valuePresets = [
    // Deal (common enums)
    'deal.vehicle_condition' => ['new', 'used', 'any'],
    'deal.deal_type' => ['cash', 'finance', 'lease'],
];

// Pull built-in answer options (usage_data / credit app step 3) as presets for rule creation.
// Adding a new option in get_credit_app_answer_option_catalog() will show up in:
// - application_step3.php
// - admin_scoring_action_rules.php (quick-pick + datalist)
$builtInOptions = get_credit_app_answer_option_catalog();
foreach ($builtInOptions as $fieldKey => $meta) {
    $fieldKey = trim((string)$fieldKey);
    if ($fieldKey === '') continue;
    $signalKey = 'usage.' . $fieldKey;
    $opts = $meta['options'] ?? [];
    if (!is_array($opts) || empty($opts)) continue;
    $valuePresets[$signalKey] = [];
    foreach ($opts as $o) {
        $val = is_array($o) ? (string)($o['value'] ?? '') : (string)$o;
        $lbl = is_array($o) ? (string)($o['label'] ?? $val) : (string)$o;
        $val = trim($val);
        if ($val === '') continue;
        $valuePresets[$signalKey][] = ['value' => $val, 'label' => $lbl !== '' ? $lbl : $val];
    }
}

	$showEmptySignals = isset($_GET['show_empty']) ? (int)$_GET['show_empty'] : 1;
	$showEmptySignals = $showEmptySignals ? 1 : 0;
	$focusSignalKey = trim((string)($_GET['focus_signal_key'] ?? ($_POST['focus_signal_key'] ?? '')));

	function signal_key_to_html_id(string $key): string
	{
	    $id = preg_replace('/[^A-Za-z0-9_-]+/', '_', $key);
	    if ($id === null || $id === '') $id = 'signal';
	    return 'sig_' . $id;
	}

// For store/org convenience: if a store is selected, scope_type defaults to store
$selectedOrgId = isset($_GET['org_id']) ? (int)$_GET['org_id'] : (int)($_POST['org_id'] ?? 0);
$selectedOrgId = $selectedOrgId > 0 ? $selectedOrgId : 0;

if ($selectedOrgId > 0 && ($scopeType === 'global' && $scopeValue === '')) {
    $scopeType = 'store';
    $scopeValue = (string)$selectedOrgId;
}

	if ($_SERVER['REQUEST_METHOD'] === 'POST' && $hasRulesTable) {
	    if (!dealerfai_csrf_validate($_POST['csrf_token'] ?? null)) {
	        http_response_code(403);
	        die('Invalid request.');
	    }

	    $action = (string)($_POST['action'] ?? '');
	    if ($action === 'add_rule') {
        $scopeType = normalize_scope((string)($_POST['scope_type'] ?? 'global'));
        $scopeValue = (string)($_POST['scope_value'] ?? '');
        if ($scopeType === 'global') $scopeValue = '';

        $targetType = normalize_target_type((string)($_POST['target_type'] ?? 'product'));
        $targetCode = normalize_code((string)($_POST['target_code'] ?? ''));
        $actionType = normalize_action_type((string)($_POST['action_type'] ?? 'score'));
        $scoreDelta = (int)($_POST['score_delta'] ?? 0);
        $signalKey = trim((string)($_POST['signal_key'] ?? ''));
        $operator = normalize_operator((string)($_POST['operator'] ?? 'equals'));
        $value1 = trim((string)($_POST['value1'] ?? ''));
        $value2 = trim((string)($_POST['value2'] ?? ''));
        $label = trim((string)($_POST['label'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));
        $enabled = !empty($_POST['is_enabled']) ? 1 : 0;

        if ($targetCode === '' || $signalKey === '') {
            $error = 'Rule requires target code and signal key.';
        } else {
            try {
                $stmt = $db->prepare("
                    INSERT INTO scoring_action_rules
                      (is_enabled, scope_type, scope_value, target_type, target_code, action_type, score_delta,
                       signal_key, operator, value1, value2, label, notes)
                    VALUES
                      (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
	                $stmt->execute([
                    $enabled,
                    $scopeType,
                    $scopeValue,
                    $targetType,
                    $targetCode,
                    $actionType,
                    $actionType === 'exclude' ? 0 : $scoreDelta,
                    $signalKey,
                    $operator,
                    $value1 === '' ? null : $value1,
                    $value2 === '' ? null : $value2,
                    $label === '' ? null : $label,
                    $notes === '' ? null : $notes,
	                ]);
	                $message = 'Rule added.';
	                $redirParams = $_GET;
	                $redirParams['focus_signal_key'] = $signalKey;
	                $location = 'admin_scoring_action_rules.php?' . http_build_query($redirParams) . '#' . signal_key_to_html_id($signalKey);
	                header('Location: ' . $location);
	                exit;
	            } catch (PDOException $e) {
	                $error = 'Unable to add rule.';
	            }
	        }
	    } elseif ($action === 'update_rule') {
        $id = (int)($_POST['id'] ?? 0);
        $scopeType = normalize_scope((string)($_POST['scope_type'] ?? 'global'));
        $scopeValue = (string)($_POST['scope_value'] ?? '');
        if ($scopeType === 'global') $scopeValue = '';

        $targetType = normalize_target_type((string)($_POST['target_type'] ?? 'product'));
        $targetCode = normalize_code((string)($_POST['target_code'] ?? ''));
        $actionType = normalize_action_type((string)($_POST['action_type'] ?? 'score'));
        $scoreDelta = (int)($_POST['score_delta'] ?? 0);
        $signalKey = trim((string)($_POST['signal_key'] ?? ''));
        $operator = normalize_operator((string)($_POST['operator'] ?? 'equals'));
        $value1 = trim((string)($_POST['value1'] ?? ''));
        $value2 = trim((string)($_POST['value2'] ?? ''));
        $label = trim((string)($_POST['label'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));
        $enabled = !empty($_POST['is_enabled']) ? 1 : 0;

        if ($id <= 0) {
            $error = 'Missing rule id.';
        } elseif ($targetCode === '' || $signalKey === '') {
            $error = 'Rule requires target code and signal key.';
        } elseif ($scopeType !== 'global' && trim($scopeValue) === '') {
            $error = 'Scope value is required for store/org scoped rules.';
        } else {
            try {
                $stmt = $db->prepare("
                    UPDATE scoring_action_rules
                    SET is_enabled = ?,
                        scope_type = ?,
                        scope_value = ?,
                        target_type = ?,
                        target_code = ?,
                        action_type = ?,
                        score_delta = ?,
                        signal_key = ?,
                        operator = ?,
                        value1 = ?,
                        value2 = ?,
                        label = ?,
                        notes = ?
                    WHERE id = ?
                    LIMIT 1
                ");
	                $stmt->execute([
                    $enabled,
                    $scopeType,
                    $scopeValue,
                    $targetType,
                    $targetCode,
                    $actionType,
                    $actionType === 'exclude' ? 0 : $scoreDelta,
                    $signalKey,
                    $operator,
                    $value1 === '' ? null : $value1,
                    $value2 === '' ? null : $value2,
                    $label === '' ? null : $label,
                    $notes === '' ? null : $notes,
	                    $id,
	                ]);
	                $message = 'Rule updated.';
	                $editId = 0;
	                $editRule = null;
	                $redirParams = $_GET;
	                unset($redirParams['edit_id']);
	                $redirParams['focus_signal_key'] = $signalKey;
	                $location = 'admin_scoring_action_rules.php?' . http_build_query($redirParams) . '#' . signal_key_to_html_id($signalKey);
	                header('Location: ' . $location);
	                exit;
	            } catch (PDOException $e) {
	                $error = 'Unable to update rule.';
	            }
	        }
	    } elseif ($action === 'delete_rule') {
	        $id = (int)($_POST['id'] ?? 0);
	        if ($id > 0) {
	            try {
	                $db->prepare("DELETE FROM scoring_action_rules WHERE id = ?")->execute([$id]);
	                $message = 'Rule deleted.';
	                $redirParams = $_GET;
	                unset($redirParams['edit_id']);
	                $focus = trim((string)($_POST['focus_signal_key'] ?? $focusSignalKey));
	                if ($focus !== '') {
	                    $redirParams['focus_signal_key'] = $focus;
	                    $location = 'admin_scoring_action_rules.php?' . http_build_query($redirParams) . '#' . signal_key_to_html_id($focus);
	                } else {
	                    $location = 'admin_scoring_action_rules.php?' . http_build_query($redirParams);
	                }
	                header('Location: ' . $location);
	                exit;
	            } catch (PDOException $e) {
	                $error = 'Unable to delete rule.';
	            }
	        }
	    }
	}

$editId = $editId > 0 ? $editId : 0;
if ($hasRulesTable && $editId > 0) {
    try {
        $stmt = $db->prepare("SELECT * FROM scoring_action_rules WHERE id = ? LIMIT 1");
        $stmt->execute([$editId]);
        $editRule = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$editRule) {
            $editId = 0;
        }
    } catch (PDOException $e) {
        $editId = 0;
        $editRule = null;
    }
}

$products = [];
try {
    $products = $db->query("SELECT DISTINCT code, MIN(name) AS name FROM products WHERE is_active = 1 GROUP BY code ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $products = [];
}

$accessories = [];
try {
    $accessories = $db->query("SELECT DISTINCT code, MIN(name) AS name FROM accessories WHERE active = 1 GROUP BY code ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $accessories = [];
}

$rules = [];
if ($hasRulesTable) {
    try {
        $stmt = $db->prepare("
            SELECT *
            FROM scoring_action_rules
            WHERE (scope_type = 'global' AND scope_value = '')
               OR (scope_type <> 'global' AND scope_value <> '')
            ORDER BY updated_at DESC, id DESC
        ");
        $stmt->execute();
        $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $rules = [];
    }
}

$signalDefs = build_signal_definitions();
$customSignalDefs = fetch_custom_question_signal_definitions($db, $contextOrgId);
if (!empty($customSignalDefs)) {
    foreach ($customSignalDefs as $d) {
        $signalDefs[] = [$d['key'], $d['label']];
        $ft = strtolower(trim((string)($d['field_type'] ?? '')));
        $optsRaw = (string)($d['options'] ?? '');
        $opts = [];
        if ($optsRaw !== '') {
            $decoded = json_decode($optsRaw, true);
            if (is_array($decoded)) {
                $opts = array_values(array_filter(array_map(fn($x) => trim((string)$x), $decoded), fn($x) => $x !== ''));
            }
        }
        if ($ft === 'checkbox') {
            $valuePresets[$d['key']] = ['1', '0'];
        } elseif ($ft === 'select' || $ft === 'multiselect') {
            if (!empty($opts)) {
                $valuePresets[$d['key']] = $opts;
            }
        }
    }
}

// Prefill add form from query string (useful for "by input" workflow).
$prefill = [
    'is_enabled' => isset($_GET['prefill_is_enabled']) ? (int)$_GET['prefill_is_enabled'] : null,
    'scope_type' => isset($_GET['prefill_scope_type']) ? normalize_scope((string)$_GET['prefill_scope_type']) : null,
    'scope_value' => isset($_GET['prefill_scope_value']) ? (string)$_GET['prefill_scope_value'] : null,
    'target_type' => isset($_GET['prefill_target_type']) ? normalize_target_type((string)$_GET['prefill_target_type']) : null,
    'target_code' => isset($_GET['prefill_target_code']) ? normalize_code((string)$_GET['prefill_target_code']) : null,
    'action_type' => isset($_GET['prefill_action_type']) ? normalize_action_type((string)$_GET['prefill_action_type']) : null,
    'score_delta' => isset($_GET['prefill_score_delta']) && is_numeric((string)$_GET['prefill_score_delta']) ? (int)$_GET['prefill_score_delta'] : null,
    'signal_key' => isset($_GET['prefill_signal_key']) ? trim((string)$_GET['prefill_signal_key']) : null,
    'operator' => isset($_GET['prefill_operator']) ? normalize_operator((string)$_GET['prefill_operator']) : null,
    'value1' => isset($_GET['prefill_value1']) ? trim((string)$_GET['prefill_value1']) : null,
    'value2' => isset($_GET['prefill_value2']) ? trim((string)$_GET['prefill_value2']) : null,
    'label' => isset($_GET['prefill_label']) ? trim((string)$_GET['prefill_label']) : null,
    'notes' => isset($_GET['prefill_notes']) ? trim((string)$_GET['prefill_notes']) : null,
];
if (!empty($prefill['scope_type']) && $prefill['scope_type'] === 'global') {
    $prefill['scope_value'] = '';
}

// Apply context scope filter for display (does not affect editing by id).
$applicableScopes = null;
if ($contextMode !== 'all') {
    $applicableScopes = [['global', '']];
    if ($contextOrgId > 0) {
        $orgMeta = get_organization_meta($db, $contextOrgId);
        $orgKind = $orgMeta['org_kind'] ?? 'store';
        $parentId = !empty($orgMeta['parent_org_id']) ? (int)$orgMeta['parent_org_id'] : 0;
        $selfScope = [$orgKind === 'store' ? 'store' : 'org', (string)$contextOrgId];
        if ($contextMode === 'exact') {
            $applicableScopes = [$selfScope];
        } else {
            $applicableScopes[] = $selfScope;
            if ($orgKind === 'store' && $parentId > 0) {
                $applicableScopes[] = ['org', (string)$parentId];
            }
        }
    }
}

$displayRules = $rules;
if (is_array($applicableScopes)) {
    $displayRules = [];
    foreach ($rules as $r) {
        $st = (string)($r['scope_type'] ?? 'global');
        $sv = (string)($r['scope_value'] ?? '');
        foreach ($applicableScopes as [$t, $v]) {
            if ($st === $t && $sv === $v) {
                $displayRules[] = $r;
                break;
            }
        }
    }
}

// Build signal labels (curated + discovered).
$signalLabel = [];
foreach ($signalDefs as [$k, $lbl]) {
    $signalLabel[(string)$k] = (string)$lbl;
}
foreach ($displayRules as $r) {
    $k = (string)($r['signal_key'] ?? '');
    if ($k !== '' && !isset($signalLabel[$k])) {
        $signalLabel[$k] = $k;
    }
}
ksort($signalLabel);

// Group by signal for the "inputs" view.
$rulesBySignal = [];
foreach ($displayRules as $r) {
    $k = (string)($r['signal_key'] ?? '');
    if ($k === '') continue;
    $rulesBySignal[$k] = $rulesBySignal[$k] ?? [];
    $rulesBySignal[$k][] = $r;
}

// Workflow-ordered inputs (signals).
$customKeys = [];
foreach ($customSignalDefs as $d) {
    $k = (string)($d['key'] ?? '');
    if ($k !== '') $customKeys[$k] = true;
}
foreach (array_keys($signalLabel) as $k) {
    if (str_starts_with($k, 'usage.custom.') && !isset($customKeys[$k])) {
        $customKeys[$k] = true;
    }
}
$customKeys = array_keys($customKeys);
sort($customKeys);

$workflowSections = [
    [
        'title' => 'Create Deal - Step 1 of 3 (Customer/Store)',
        'keys' => [
            'deal.customer_type',
            'deal.province',
        ],
    ],
    [
        'title' => 'Create Deal - Step 2 of 3 (Vehicle Information)',
        'keys' => [
            'deal.vin',
            'deal.vehicle_condition',
            'deal.vehicle_make',
            'deal.vehicle_model',
            'deal.vehicle_year',
            'deal.vehicle_kms',
            'deal.in_service_date',
            'deal.vehicle_colour',
        ],
    ],
    [
        'title' => 'Create Deal - Step 3 of 3 (Deal Type & Financials)',
        'keys' => [
            'deal.deal_type',
            'deal.sale_price',
            'deal.documentation_fee',
            'deal.term',
            'deal.interest_rate',
            'deal.ppsa_fee',
            'deal.msrp',
            'deal.residual',
            'deal.down_payment',
            'deal.payment_frequency',
            'deal.trade_value',
            'deal.lien_amount',
        ],
    ],
    [
        'title' => 'Credit App - Step 1 of 3 (Customer Info)',
        'keys' => [
            'app.full_name',
            'app.email',
            'app.phone',
            'app.address',
            'app.city',
            'app.province',
            'app.postal_code',
            'app.housing',
            'app.years_at_address',
            'app.monthly_payment',
            'app.has_cosigner',
            'app.co_full_name',
            'app.co_email',
            'app.co_phone',
            'app.co_address',
            'app.co_city',
            'app.co_province',
            'app.co_postal_code',
            'app.co_housing',
            'app.co_years_at_address',
            'app.co_monthly_payment',
        ],
    ],
    [
        'title' => 'Credit App - Step 2 of 3 (Employment & Income)',
        'keys' => [
            'app.employer',
            'app.work_address',
            'app.position',
            'app.employment_length',
            'app.prev_employer',
            'app.prev_phone',
            'app.prev_address',
            'app.prev_length',
            'app.income',
            'app.other_income',
            'app.other_income_source',
            'app.co_employer',
            'app.co_work_address',
            'app.co_position',
            'app.co_employment_length',
            'app.co_prev_employer',
            'app.co_prev_phone',
            'app.co_prev_address',
            'app.co_prev_length',
            'app.co_income',
            'app.co_other_income',
            'app.co_other_income_source',
        ],
    ],
    [
        'title' => 'Credit App - Step 3 of 3 (Vehicle Usage & Protection Needs)',
        'keys' => [
            'usage.annual_km',
            'usage.driving_type',
            'usage.gravel_exposure',
            'usage.road_conditions',
            'usage.overnight_parking',
            'usage.regular_users',
            'usage.food_drink',
            'usage.appearance_priority',
            'usage.ownership_length',
            'usage.life_protection',
            'usage.disability_protection',
            'usage.critical_illness',
            'usage.job_loss',
        ],
    ],
    [
        'title' => 'Credit App - Additional Questions (Custom)',
        'keys' => $customKeys,
    ],
    [
        'title' => 'Computed (Derived Inputs)',
        'keys' => [
            'computed.amount_financed_estimate',
            'computed.negative_equity',
            'computed.down_payment_pct',
            'computed.ltv_pct',
        ],
    ],
];

$catalogKeySet = [];
foreach ($workflowSections as $sec) {
    foreach (($sec['keys'] ?? []) as $k) {
        $k = (string)$k;
        if ($k === '') continue;
        $catalogKeySet[$k] = true;
    }
}
$otherKeys = [];
$redundantLegacyUsageSignals = [
    'computed.no_highway_or_gravel',
];
$redundantLegacyUsageSignals = array_fill_keys($redundantLegacyUsageSignals, true);
foreach (array_keys($signalLabel) as $k) {
    if (isset($redundantLegacyUsageSignals[$k])) {
        continue;
    }
    if (!isset($catalogKeySet[$k])) {
        $otherKeys[] = $k;
    }
}
sort($otherKeys);
if (!empty($otherKeys)) {
    $workflowSections[] = [
        'title' => 'Other Signals (Advanced)',
        'keys' => $otherKeys,
    ];
}

// Preview/test
$dealId = (int)($_GET['deal_id'] ?? 0);
$preview = ['signals' => [], 'matches' => []];
if ($dealId > 0) {
    $dealStmt = $db->prepare("SELECT * FROM deals WHERE id = ?");
    $dealStmt->execute([$dealId]);
    $deal = $dealStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    $appStmt = $db->prepare("SELECT * FROM applications WHERE deal_id = ?");
    $appStmt->execute([$dealId]);
    $app = $appStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    if ($deal) {
        $includedTotal = 0.0;
        try {
            if (function_exists('parse_included_protections') && function_exists('summarize_included_protections')) {
                $sum = summarize_included_protections(parse_included_protections($deal['included_protections'] ?? null));
                $includedTotal = (float)($sum['total'] ?? 0.0);
            }
        } catch (Throwable $e) {
            $includedTotal = 0.0;
        }

        $signals = build_rule_signal_context($db, $deal, $app, $includedTotal);
        $preview['signals'] = $signals;

        // Determine applicable scopes for this deal.
        $orgId = !empty($deal['organization']) ? (int)$deal['organization'] : 0;
        $orgMeta = $orgId > 0 ? get_organization_meta($db, $orgId) : [];
        $orgKind = $orgMeta['org_kind'] ?? 'store';
        $parentId = !empty($orgMeta['parent_org_id']) ? (int)$orgMeta['parent_org_id'] : 0;

        $applicableScopes = [
            ['global', ''],
        ];
        if ($orgId > 0) {
            $applicableScopes[] = [$orgKind === 'store' ? 'store' : 'org', (string)$orgId];
        }
        if ($orgKind === 'store' && $parentId > 0) {
            $applicableScopes[] = ['org', (string)$parentId];
        }

        $matches = [];
        foreach ($rules as $r) {
            $st = (string)($r['scope_type'] ?? 'global');
            $sv = (string)($r['scope_value'] ?? '');
            $okScope = false;
            foreach ($applicableScopes as [$t, $v]) {
                if ($st === $t && $sv === $v) {
                    $okScope = true;
                    break;
                }
            }
            if (!$okScope) continue;
            if (empty($r['is_enabled'])) continue;
            if (evaluate_action_rule($r, $signals)) {
                $matches[] = $r;
            }
        }
	        $preview['matches'] = $matches;
	    }
	}

$csrfToken = dealerfai_csrf_get_token();

?>
<!DOCTYPE html>
<html>
<head>
  <?php include __DIR__ . "/includes/head_favicon.php"; ?>
  <meta charset="UTF-8">
  <title>Admin - Action Rules</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php dealerfai_theme_head(); ?>
  <style nonce="<?= dealerfai_csp_nonce() ?>">
    body { font-family: Arial, sans-serif; background: #f4f6f8; margin: 0; padding: 0; }
    header { background: #0066cc; color: white; padding: 20px; text-align: center; position: relative; }
    nav { background: #0066cc; padding: 12px; text-align: center; }
    nav a { color: white; margin: 0 20px; text-decoration: none; font-weight: bold; }
    .container { max-width: 1200px; margin: 20px auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
    h2 { margin-top: 0; }
    .muted { color: #667085; font-size: 0.9rem; margin-top: 4px; }
    .success { color: green; margin-top: 10px; }
    .error { color: #b42318; margin-top: 10px; }
    table { width: 100%; border-collapse: collapse; margin-top: 12px; }
    th, td { padding: 10px; border-bottom: 1px solid #ddd; vertical-align: top; text-align: left; }
    th { background: #f1f4f8; }
    input[type="text"], input[type="number"], select, textarea { padding: 8px; border-radius: 4px; border: 1px solid #ccc; font-family: inherit; }
	    .btn { background: #0066cc; color: white; padding: 8px 14px; border: none; border-radius: 4px; cursor: pointer; }
	    .btn:hover { background: #094c63; }
	    .btn.danger { background: #c0392b; }
	    .btn.secondary { background: #e6ebf2; color: #1b2c40; border: 1px solid #c7d0d8; }
	    .btn.secondary:hover { background: #d7dee8; }
	    .row { display:flex; gap:12px; flex-wrap: wrap; align-items: flex-end; }
    .quick-picks { margin-top: 6px; display: flex; flex-wrap: wrap; gap: 6px; }
    .quick-pick-btn {
      border: 1px solid #c7d0d8;
      background: #f8fafc;
      color: #1b2c40;
      border-radius: 999px;
      padding: 2px 10px;
      font-size: 12px;
      line-height: 1.4;
      cursor: pointer;
    }
    .quick-pick-btn:hover { background: #eaf1f7; }
    .quick-pick-btn.is-active { border-color: #0066cc; background: #e1eff5; font-weight: 600; }
    .card { border: 1px solid #e7edf3; border-radius: 8px; padding: 14px; margin-top: 14px; }
    code { background: #f2f4f7; padding: 2px 6px; border-radius: 4px; }
    .mini { font-size: 12px; }
  </style>
</head>
<body>
  <header>
    <h1>DealerFAI Admin</h1>
    <div class="logout">
      <a href="admin_error_alerts.php" class="nav-link-white">Alerts<?php if ($adminAlertCount > 0): ?> <span style="display:inline-block; min-width:18px; padding:2px 8px; border-radius:999px; background:#d7263d; color:#fff; font-size:12px; font-weight:bold; text-align:center; margin-left:6px;"><?= $adminAlertCount ?></span><?php endif; ?></a>
      <a href="logout.php" class="nav-link-white">Log Out</a>
    </div>
  </header>
	  <nav>
	    <a href="dashboard.php">Dashboard</a>
	    <a href="view_deals.php">View Deals</a>
	    <a href="create_deal.php">Create Deal</a>
	    <a href="admin_tools.php">Admin Tools</a>
	  </nav>

	  <div class="container">
	    <h2>Action Rules (Direct)</h2>
	    <p class="muted">These rules directly score/exclude products and accessories based on deal/application inputs. Scores stack; exclude wins.</p>

	    <div class="card">
	      <h3 class="mt-0">View</h3>
	      <p class="muted mini" class="mt-6">Rules are shown grouped by input signal. Click an input to expand and see the rules under it.</p>
	      <form method="get" class="row" class="mt-10">
	        <div>
	          <label class="muted mini" class="d-block">Context org/store</label>
	          <select name="context_org_id">
	            <option value="0" <?= $contextOrgId === 0 ? 'selected' : '' ?>>Global only</option>
	            <?php foreach ($orgRows as $o): ?>
	              <?php
	                $oid = (int)($o['id'] ?? 0);
	                if ($oid <= 0) continue;
	                $ok = (string)($o['org_kind'] ?? '');
	                $label = (string)($o['name'] ?? ('Org ' . $oid));
	                $suffix = $ok !== '' ? (' [' . $ok . ']') : '';
	              ?>
	              <option value="<?= $oid ?>" <?= $contextOrgId === $oid ? 'selected' : '' ?>><?= htmlspecialchars($label . $suffix . ' #' . $oid) ?></option>
	            <?php endforeach; ?>
	          </select>
	        </div>
	        <div>
	          <label class="muted mini" class="d-block">Context mode</label>
	          <select name="context_mode">
	            <option value="effective" <?= $contextMode === 'effective' ? 'selected' : '' ?>>Effective (global + org/store + parent)</option>
	            <option value="exact" <?= $contextMode === 'exact' ? 'selected' : '' ?>>Exact only</option>
	            <option value="all" <?= $contextMode === 'all' ? 'selected' : '' ?>>All rules (no filter)</option>
	          </select>
	        </div>
	        <div>
	          <label class="muted mini" class="d-block">Show empty inputs</label>
	          <select name="show_empty">
	            <option value="1" <?= $showEmptySignals ? 'selected' : '' ?>>Yes</option>
	            <option value="0" <?= !$showEmptySignals ? 'selected' : '' ?>>No</option>
	          </select>
	        </div>
	        <button class="btn" type="submit">Apply</button>
	      </form>
	      <p class="muted mini" class="mt-8">Tip: use “Effective” when you want to see what a specific store would actually get (global + group + store).</p>
	    </div>
    <?php if (!$hasRulesTable): ?>
      <p class="error">Missing table <code>scoring_action_rules</code>. Ask an admin to apply the latest database schema updates.</p>
    <?php endif; ?>
    <?php if ($message): ?><p class="success"><?= htmlspecialchars($message) ?></p><?php endif; ?>
    <?php if ($error): ?><p class="error"><?= htmlspecialchars($error) ?></p><?php endif; ?>

		    <?php if ($editRule): ?>
		      <?php
		        $cancelParams = $_GET;
		        unset($cancelParams['edit_id']);
		        $cancelLink = 'admin_scoring_action_rules.php?' . http_build_query($cancelParams);
		      ?>
		      <div class="card">
		        <p class="m-0">
		          <strong>Editing Rule #<?= (int)($editRule['id'] ?? 0) ?></strong>
		          <span class="muted mini">(editing is inline under its input signal below)</span>
		          <a class="btn secondary" class="ml-10" href="<?= htmlspecialchars($cancelLink) ?>">Cancel</a>
		        </p>
		      </div>
		    <?php endif; ?>

		    <div class="card" id="rule_form" <?= $editRule ? 'class="d-none"' : '' ?>>
		      <h3 class="mt-0"><?= $editRule ? 'Edit Rule #' . (int)($editRule['id'] ?? 0) : 'Add Rule' ?></h3>
		      <?php if ($editRule): ?>
		        <p class="muted mini" class="mt-6">
		          Editing a rule updates it in-place. To stop editing, use
		          <a href="admin_scoring_action_rules.php" class="muted">Cancel</a>.
	        </p>
	      <?php endif; ?>
	      <form method="post" class="row js-target-rule-form js-rule-form">
	        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
	        <input type="hidden" name="action" value="<?= $editRule ? 'update_rule' : 'add_rule' ?>">
	        <?php if ($editRule): ?><input type="hidden" name="id" value="<?= (int)($editRule['id'] ?? 0) ?>"><?php endif; ?>
	        <div>
	          <label class="muted mini" class="d-block">Enabled</label>
	          <select name="is_enabled" <?= $hasRulesTable ? '' : 'disabled' ?>>
	            <?php $enabledSel = $editRule ? (int)($editRule['is_enabled'] ?? 1) : (int)($prefill['is_enabled'] ?? 1); ?>
	            <option value="1" <?= $enabledSel === 1 ? 'selected' : '' ?>>Yes</option>
	            <option value="0" <?= $enabledSel === 0 ? 'selected' : '' ?>>No</option>
	          </select>
	        </div>
	        <div>
	          <label class="muted mini" class="d-block">Scope type</label>
	          <select name="scope_type" <?= $hasRulesTable ? '' : 'disabled' ?>>
	            <?php $scopeTypeSel = $editRule ? (string)($editRule['scope_type'] ?? 'global') : (string)($prefill['scope_type'] ?? $scopeType); ?>
	            <option value="global" <?= $scopeTypeSel === 'global' ? 'selected' : '' ?>>Global</option>
	            <option value="org" <?= $scopeTypeSel === 'org' ? 'selected' : '' ?>>Org (group)</option>
	            <option value="store" <?= $scopeTypeSel === 'store' ? 'selected' : '' ?>>Store</option>
	          </select>
	        </div>
	        <div>
	          <label class="muted mini" class="d-block">Scope value</label>
	          <?php $scopeValueSel = $editRule ? (string)($editRule['scope_value'] ?? '') : (string)($prefill['scope_value'] ?? $scopeValue); ?>
	          <select name="scope_value" <?= $hasRulesTable ? '' : 'disabled' ?>>
	            <option value="" <?= $scopeValueSel === '' ? 'selected' : '' ?>>(blank for global)</option>
	            <?php foreach ($orgRows as $o): ?>
	              <?php
	                $oid = (int)($o['id'] ?? 0);
	                if ($oid <= 0) continue;
	                $ok = (string)($o['org_kind'] ?? '');
	                $label = (string)($o['name'] ?? ('Org ' . $oid));
	                $suffix = $ok !== '' ? (' [' . $ok . ']') : '';
	                $val = (string)$oid;
	              ?>
	              <option value="<?= htmlspecialchars($val) ?>" <?= $scopeValueSel === $val ? 'selected' : '' ?>><?= htmlspecialchars($label . $suffix . ' #' . $oid) ?></option>
	            <?php endforeach; ?>
	          </select>
	        </div>
	        <div>
	          <label class="muted mini" class="d-block">Target type</label>
	          <select name="target_type" class="js-target-type" <?= $hasRulesTable ? '' : 'disabled' ?>>
	            <?php $targetTypeSel = $editRule ? (string)($editRule['target_type'] ?? 'product') : (string)($prefill['target_type'] ?? 'product'); ?>
	            <option value="product" <?= $targetTypeSel === 'product' ? 'selected' : '' ?>>Product</option>
	            <option value="accessory" <?= $targetTypeSel === 'accessory' ? 'selected' : '' ?>>Accessory</option>
	          </select>
	        </div>
	        <div>
	          <label class="muted mini" class="d-block">Target code</label>
	          <?php $targetCodeSel = $editRule ? (string)($editRule['target_code'] ?? '') : (string)($prefill['target_code'] ?? ''); ?>
	          <input list="<?= $targetTypeSel === 'accessory' ? 'target_codes_accessory' : 'target_codes_product' ?>" class="js-target-code" type="text" name="target_code" placeholder="e.g. creditLife" value="<?= htmlspecialchars($targetCodeSel) ?>" <?= $hasRulesTable ? '' : 'disabled' ?> required>
	          <div class="quick-picks js-target-code-quick"></div>
	          <datalist id="target_codes_product">
	            <?php foreach ($products as $p): ?>
	              <option value="<?= htmlspecialchars((string)($p['code'] ?? '')) ?>"><?= htmlspecialchars((string)($p['name'] ?? '')) ?></option>
	            <?php endforeach; ?>
	          </datalist>
	          <datalist id="target_codes_accessory">
	            <?php foreach ($accessories as $a): ?>
	              <option value="<?= htmlspecialchars((string)($a['code'] ?? '')) ?>"><?= htmlspecialchars((string)($a['name'] ?? '')) ?></option>
	            <?php endforeach; ?>
	          </datalist>
        </div>
	        <div>
	          <label class="muted mini" class="d-block">Action</label>
	          <select name="action_type" <?= $hasRulesTable ? '' : 'disabled' ?>>
	            <?php $actionTypeSel = $editRule ? (string)($editRule['action_type'] ?? 'score') : (string)($prefill['action_type'] ?? 'score'); ?>
	            <option value="score" <?= $actionTypeSel === 'score' ? 'selected' : '' ?>>Score</option>
	            <option value="exclude" <?= $actionTypeSel === 'exclude' ? 'selected' : '' ?>>Exclude</option>
	          </select>
	        </div>
	        <div>
	          <label class="muted mini" class="d-block">Score delta</label>
	          <?php $deltaSel = $editRule ? (int)($editRule['score_delta'] ?? 0) : (int)($prefill['score_delta'] ?? 0); ?>
	          <input type="number" name="score_delta" value="<?= htmlspecialchars((string)$deltaSel) ?>" class="w-110px" <?= $hasRulesTable ? '' : 'disabled' ?>>
	        </div>
	        <div style="min-width:260px;">
	          <label class="muted mini" class="d-block">Signal</label>
	          <?php $signalSel = $editRule ? (string)($editRule['signal_key'] ?? '') : (string)($prefill['signal_key'] ?? ''); ?>
	          <input list="signals" id="signal_key" class="js-signal-key" type="text" name="signal_key" placeholder="e.g. deal.term" value="<?= htmlspecialchars($signalSel) ?>" <?= $hasRulesTable ? '' : 'disabled' ?> required>
	          <datalist id="signals">
	            <?php foreach ($signalDefs as [$key, $label]): ?>
	              <option value="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($label) ?></option>
	            <?php endforeach; ?>
	          </datalist>
	        </div>
	        <div>
	          <label class="muted mini" class="d-block">Operator</label>
	          <select name="operator" class="js-operator" <?= $hasRulesTable ? '' : 'disabled' ?>>
	            <?php $opSel = $editRule ? (string)($editRule['operator'] ?? 'equals') : (string)($prefill['operator'] ?? 'equals'); ?>
	            <?php $opSel = $opSel !== '' ? $opSel : 'equals'; ?>
	            <?php foreach (operator_options() as $opValue => $opText): ?>
	              <option value="<?= htmlspecialchars($opValue) ?>" <?= $opSel === $opValue ? 'selected' : '' ?>><?= htmlspecialchars($opText) ?></option>
	            <?php endforeach; ?>
	          </select>
	        </div>
	        <div>
	          <label class="muted mini" class="d-block">Value 1</label>
	          <?php $value1Sel = $editRule ? (string)($editRule['value1'] ?? '') : (string)($prefill['value1'] ?? ''); ?>
	          <input type="text" id="value1" class="js-value1" name="value1" placeholder="e.g. 72 or no" value="<?= htmlspecialchars($value1Sel) ?>" list="value1_presets_add" <?= $hasRulesTable ? '' : 'disabled' ?>>
	          <datalist id="value1_presets_add" class="js-value1-presets"></datalist>
	          <div class="js-value1-quick muted mini" class="mt-6"></div>
	        </div>
	        <div>
	          <label class="muted mini" class="d-block">Value 2</label>
	          <?php $value2Sel = $editRule ? (string)($editRule['value2'] ?? '') : (string)($prefill['value2'] ?? ''); ?>
	          <input type="text" class="js-value2" name="value2" placeholder="for between" value="<?= htmlspecialchars($value2Sel) ?>" list="value2_presets_add" <?= $hasRulesTable ? '' : 'disabled' ?>>
	          <datalist id="value2_presets_add" class="js-value2-presets"></datalist>
	          <div class="js-value2-quick muted mini" class="mt-6"></div>
	        </div>
	        <div style="min-width:220px;">
	          <label class="muted mini" class="d-block">Label (optional)</label>
	          <?php $labelSel = $editRule ? (string)($editRule['label'] ?? '') : (string)($prefill['label'] ?? ''); ?>
	          <input type="text" name="label" placeholder="e.g. loan_term_long" value="<?= htmlspecialchars($labelSel) ?>" <?= $hasRulesTable ? '' : 'disabled' ?>>
		        </div>
		        <div style="flex:1; min-width:220px;">
		          <label class="muted mini" class="d-block">Notes / AI hint (optional)</label>
		          <?php $notesSel = $editRule ? (string)($editRule['notes'] ?? '') : (string)($prefill['notes'] ?? ''); ?>
		          <input type="text" name="notes" placeholder="optional: why this rule exists / how to explain it" value="<?= htmlspecialchars($notesSel) ?>" class="w-100" <?= $hasRulesTable ? '' : 'disabled' ?>>
		          <div class="muted mini" class="mt-6">If this rule matches, notes may guide AI explanations. Do not add coverage facts here.</div>
		        </div>
		        <button class="btn" type="submit" <?= $hasRulesTable ? '' : 'disabled' ?>><?= $editRule ? 'Save' : 'Add' ?></button>
		        <?php if ($editRule): ?>
		          <a class="btn secondary" href="admin_scoring_action_rules.php">Cancel</a>
		        <?php endif; ?>
	      </form>
	      <p class="muted mini" class="mt-8">Tip: <code>computed.down_payment_pct</code> is a ratio (e.g., <code>0.25</code> = 25%), while <code>computed.ltv_pct</code> is a percent (e.g., <code>150</code> = 150% LTV).</p>
	    </div>

	    <script nonce="<?= dealerfai_csp_nonce() ?>">
	      function runWhenDomReady(fn) {
	        if (document.readyState === 'loading') {
	          document.addEventListener('DOMContentLoaded', fn);
	          return;
	        }
	        fn();
	      }

	      runWhenDomReady(function () {
	        const valuePresets = <?= json_encode($valuePresets, JSON_UNESCAPED_SLASHES) ?>;

	        function clearChildren(el) {
	          while (el && el.firstChild) el.removeChild(el.firstChild);
	        }

	        function parseCsv(value) {
	          return (value || '')
	            .split(',')
	            .map((s) => s.trim())
	            .filter(Boolean);
	        }

	        function normalizePresetValue(entry) {
	          if (typeof entry === 'string') {
	            return { value: entry, label: entry };
	          }
	          if (entry && typeof entry === 'object') {
	            const value = entry.value || '';
	            const label = entry.label || value;
	            return { value, label };
	          }
	          return { value: '', label: '' };
	        }

	        function presetsForSignalKey(rawKey) {
	          const key = (rawKey || '').trim();
	          if (key === '') return [];
	          if (Array.isArray(valuePresets[key])) return valuePresets[key];
	          if (!key.includes('.') && Array.isArray(valuePresets[`usage.${key}`])) return valuePresets[`usage.${key}`];
	          if (!key.includes('.') && Array.isArray(valuePresets[`app.${key}`])) return valuePresets[`app.${key}`];
	          return [];
	        }

	        function bindQuickPick(form) {
	          if (!form) return;
	          const signalEl = form.querySelector('.js-signal-key');
	          const value1El = form.querySelector('.js-value1');
	          const value1List = form.querySelector('.js-value1-presets');
	          const quick = form.querySelector('.js-value1-quick');
	          const value2El = form.querySelector('.js-value2');
	          const value2List = form.querySelector('.js-value2-presets');
	          const quick2 = form.querySelector('.js-value2-quick');
	          const opSel = form.querySelector('.js-operator');
	          if (!signalEl || !value1El || !value1List || !quick) return;

	          function setValue1(val) {
	            const op = (opSel && opSel.value) ? opSel.value : 'equals';
	            if (op !== 'in') {
	              value1El.value = val;
	              return;
	            }
	            const parts = parseCsv(value1El.value);
	            if (parts.includes(val)) {
	              value1El.value = parts.filter((p) => p !== val).join(', ');
	              return;
	            }
	            parts.push(val);
	            value1El.value = parts.join(', ');
	          }

	          function renderPresets() {
	            const key = (signalEl.value || '').trim();
	            const presets = presetsForSignalKey(key);
	            clearChildren(value1List);
	            clearChildren(quick);
	            if (value2List) clearChildren(value2List);
	            if (quick2) clearChildren(quick2);
	            if (!presets.length) return;

	            const selected = parseCsv(value1El.value);
	            const op = (opSel && opSel.value) ? opSel.value : 'equals';

	            for (const raw of presets) {
	              const preset = normalizePresetValue(raw);
	              if (!preset.value) continue;
	              const opt = document.createElement('option');
	              opt.value = preset.value;
	              value1List.appendChild(opt);
	              if (value2List) {
	                const opt2 = document.createElement('option');
	                opt2.value = preset.value;
	                value2List.appendChild(opt2);
	              }
	            }

	            const help = document.createElement('div');
	            help.className = 'muted mini';
	            help.textContent = op === 'in'
	              ? 'Quick pick: click multiple to add/remove.'
	              : 'Quick pick: click to set value.';
	            quick.appendChild(help);

	            for (const raw of presets) {
	              const preset = normalizePresetValue(raw);
	              if (!preset.value) continue;
	              const btn = document.createElement('button');
	              btn.type = 'button';
	              btn.className = 'btn secondary';
	              btn.style.padding = '6px 10px';
	              btn.style.margin = '6px 6px 0 0';
	              btn.textContent = preset.label || preset.value;
	              btn.title = preset.value;
	              const isSelected = selected.includes(preset.value);
	              if (op === 'in' && isSelected) {
	                btn.style.borderColor = '#1A1A1A';
	                btn.style.fontWeight = '600';
	              }
	              btn.addEventListener('click', () => {
	                setValue1(preset.value);
	                renderPresets();
	              });
	              quick.appendChild(btn);
	            }

	            if (value2El && quick2 && value2List) {
	              const help2 = document.createElement('div');
	              help2.className = 'muted mini';
	              help2.textContent = op === 'between'
	                ? 'Quick pick: click to set upper bound.'
	                : 'Quick pick: optional second value.';
	              quick2.appendChild(help2);
	              for (const raw of presets) {
	                const preset = normalizePresetValue(raw);
	                if (!preset.value) continue;
	                const btn2 = document.createElement('button');
	                btn2.type = 'button';
	                btn2.className = 'btn secondary';
	                btn2.style.padding = '6px 10px';
	                btn2.style.margin = '6px 6px 0 0';
	                btn2.textContent = preset.label || preset.value;
	                btn2.title = preset.value;
	                if ((value2El.value || '').trim() === preset.value) {
	                  btn2.style.borderColor = '#1A1A1A';
	                  btn2.style.fontWeight = '600';
	                }
	                btn2.addEventListener('click', () => {
	                  value2El.value = preset.value;
	                  value2El.dispatchEvent(new Event('input', { bubbles: true }));
	                  value2El.dispatchEvent(new Event('change', { bubbles: true }));
	                  renderPresets();
	                });
	                quick2.appendChild(btn2);
	              }
	            }
	          }

	          signalEl.addEventListener('change', renderPresets);
	          signalEl.addEventListener('input', renderPresets);
	          value1El.addEventListener('input', renderPresets);
	          if (value2El) value2El.addEventListener('input', renderPresets);
	          if (opSel) {
	            opSel.addEventListener('change', renderPresets);
	          }
	          renderPresets();
	        }

	        document.querySelectorAll('.js-rule-form').forEach(bindQuickPick);
	      })();

	      runWhenDomReady(function () {
	        const targetCodeOptions = {
	          product: <?= json_encode(array_values(array_filter(array_map(static function ($row) {
	              return trim((string)($row['code'] ?? ''));
	          }, $products), static function ($v) {
	              return $v !== '';
	          })), JSON_UNESCAPED_SLASHES) ?>,
	          accessory: <?= json_encode(array_values(array_filter(array_map(static function ($row) {
	              return trim((string)($row['code'] ?? ''));
	          }, $accessories), static function ($v) {
	              return $v !== '';
	          })), JSON_UNESCAPED_SLASHES) ?>,
	        };

	        function renderTargetCodeQuickPicks(form) {
	          if (!form) return;
	          const targetType = form.querySelector('.js-target-type');
	          const targetCode = form.querySelector('.js-target-code');
	          const quick = form.querySelector('.js-target-code-quick');
	          if (!targetType || !targetCode || !quick) return;

	          const type = targetType.value === 'accessory' ? 'accessory' : 'product';
	          const options = Array.isArray(targetCodeOptions[type]) ? targetCodeOptions[type] : [];
	          const seen = new Set();
	          const unique = options.filter((value) => {
	            const key = (value || '').toLowerCase();
	            if (!key || seen.has(key)) return false;
	            seen.add(key);
	            return true;
	          }).slice(0, 12);

	          quick.innerHTML = '';
	          if (!unique.length) return;
	          const current = (targetCode.value || '').trim().toLowerCase();

	          unique.forEach((value) => {
	            const btn = document.createElement('button');
	            btn.type = 'button';
	            btn.className = 'quick-pick-btn';
	            btn.dataset.value = value;
	            btn.textContent = value;
	            if (current !== '' && current === value.toLowerCase()) {
	              btn.classList.add('is-active');
	            }
	            btn.addEventListener('click', () => {
	              targetCode.value = value;
	              targetCode.dispatchEvent(new Event('input', { bubbles: true }));
	              targetCode.dispatchEvent(new Event('change', { bubbles: true }));
	              renderTargetCodeQuickPicks(form);
	            });
	            quick.appendChild(btn);
	          });
	        }

	        function syncTargetCodeList(form) {
	          if (!form) return;
	          const targetType = form.querySelector('.js-target-type');
	          const targetCode = form.querySelector('.js-target-code');
	          if (!targetType || !targetCode) return;
	          targetCode.setAttribute(
	            'list',
	            targetType.value === 'accessory' ? 'target_codes_accessory' : 'target_codes_product'
	          );
	          renderTargetCodeQuickPicks(form);
	        }

	        document.querySelectorAll('.js-target-rule-form').forEach((form) => {
	          syncTargetCodeList(form);
	          const targetType = form.querySelector('.js-target-type');
	          const targetCode = form.querySelector('.js-target-code');
	          if (targetType) {
	            targetType.addEventListener('change', () => syncTargetCodeList(form));
	          }
	          if (targetCode) {
	            targetCode.addEventListener('input', () => renderTargetCodeQuickPicks(form));
	            targetCode.addEventListener('change', () => renderTargetCodeQuickPicks(form));
	          }
	        });
	      })();
	    </script>

			    <div class="card">
			      <h3 class="mt-0">Inputs (Signals)</h3>
			      <?php if (empty($signalLabel)): ?>
			        <p class="muted">No signals found.</p>
			      <?php else: ?>
			        <p class="muted mini">Showing <?= count($displayRules) ?> rules grouped by input signal. Click an input to expand and see rules under it.</p>
			        <?php
			          $editRuleId = $editRule ? (int)($editRule['id'] ?? 0) : 0;
			          $editRuleSignalKey = $editRule ? (string)($editRule['signal_key'] ?? '') : '';
			        ?>
			        <?php foreach ($workflowSections as $section): ?>
			          <?php
			            $title = (string)($section['title'] ?? '');
			            $keys = $section['keys'] ?? [];
		            if (!is_array($keys)) $keys = [];
		          ?>
		          <?php if ($title !== ''): ?>
		            <h4 style="margin:16px 0 6px 0;"><?= htmlspecialchars($title) ?></h4>
		          <?php endif; ?>
		          <?php foreach ($keys as $sigKey): ?>
		            <?php
		              $sigKey = (string)$sigKey;
		              if ($sigKey === '') continue;
		              $sigLbl = $signalLabel[$sigKey] ?? $sigKey;
		              $sigRules = $rulesBySignal[$sigKey] ?? [];
		              $count = count($sigRules);
		              if (!$showEmptySignals && $count === 0) {
		                  continue;
		              }
		              $addParams = $_GET;
			              $addParams['prefill_signal_key'] = $sigKey;
			              if (!isset($addParams['prefill_operator'])) $addParams['prefill_operator'] = 'equals';
			              $addLink = 'admin_scoring_action_rules.php?' . http_build_query($addParams) . '#rule_form';
			            ?>
			            <?php
			              $sigHtmlId = signal_key_to_html_id($sigKey);
			              $detailsOpen = ($editRuleId > 0 && $editRuleSignalKey !== '' && $editRuleSignalKey === $sigKey);
			              if (!$detailsOpen && $focusSignalKey !== '' && $focusSignalKey === $sigKey) {
			                  $detailsOpen = true;
			              }
			            ?>
			            <details class="card" id="<?= htmlspecialchars($sigHtmlId) ?>" class="mt-10" <?= $detailsOpen ? 'open' : '' ?>>
			              <summary class="cursor-pointer">
			                <strong><?= htmlspecialchars($sigLbl) ?></strong>
			                <code><?= htmlspecialchars($sigKey) ?></code>
			                <span class="muted mini">(<?= $count ?> rules)</span>
		              </summary>
		              <div class="mt-10">
		                <div class="row" class="mb-10">
		                  <a class="btn secondary" href="<?= htmlspecialchars($addLink) ?>">Add Rule Under This Input</a>
		                </div>
		                <?php if (empty($sigRules)): ?>
		                  <p class="muted mini">No rules for this input in the current context filter.</p>
		                <?php else: ?>
		                  <?php
		                    usort($sigRules, function ($a, $b) {
		                        $sa = (string)($a['scope_type'] ?? '');
		                        $sb = (string)($b['scope_type'] ?? '');
		                        if ($sa !== $sb) return strcmp($sa, $sb);
		                        $va = (string)($a['scope_value'] ?? '');
		                        $vb = (string)($b['scope_value'] ?? '');
		                        if ($va !== $vb) return strcmp($va, $vb);
		                        $ta = (string)($a['target_type'] ?? '');
		                        $tb = (string)($b['target_type'] ?? '');
		                        if ($ta !== $tb) return strcmp($ta, $tb);
		                        $ca = (string)($a['target_code'] ?? '');
		                        $cb = (string)($b['target_code'] ?? '');
		                        return strcmp($ca, $cb);
		                    });
		                  ?>
			                  <table>
			                    <thead>
			                      <tr>
			                        <th class="w-6">ID</th>
		                        <th style="width:7%;">On</th>
		                        <th class="w-12">Scope</th>
		                        <th class="w-14">Target</th>
		                        <th class="w-10">Action</th>
		                        <th class="w-10">Delta</th>
		                        <th class="w-20">Condition</th>
		                        <th>Label/Notes</th>
		                        <th class="w-12">Actions</th>
		                      </tr>
			                    </thead>
			                    <tbody>
			                      <?php foreach ($sigRules as $r): ?>
			                        <?php $rid = (int)($r['id'] ?? 0); ?>
			                        <tr>
			                          <td id="rule_<?= $rid ?>"><?= $rid ?></td>
			                          <td><?= !empty($r['is_enabled']) ? 'Yes' : 'No' ?></td>
			                          <td><?= htmlspecialchars((string)($r['scope_type'] ?? '')) ?><?= ($r['scope_value'] ?? '') !== '' ? ':' . htmlspecialchars((string)$r['scope_value']) : '' ?></td>
			                          <td><?= htmlspecialchars((string)($r['target_type'] ?? '')) ?>:<code><?= htmlspecialchars((string)($r['target_code'] ?? '')) ?></code></td>
		                          <td><?= htmlspecialchars((string)($r['action_type'] ?? '')) ?></td>
		                          <td><?= (int)($r['score_delta'] ?? 0) ?></td>
		                          <td>
		                            <code><?= htmlspecialchars((string)($r['signal_key'] ?? '')) ?></code>
		                            <?= htmlspecialchars(operator_label((string)($r['operator'] ?? ''))) ?>
		                            <?= htmlspecialchars((string)($r['value1'] ?? '')) ?>
		                            <?php if (!empty($r['value2'])): ?> .. <?= htmlspecialchars((string)$r['value2']) ?><?php endif; ?>
		                          </td>
		                          <td>
		                            <?php if (!empty($r['label'])): ?><code><?= htmlspecialchars((string)$r['label']) ?></code><br><?php endif; ?>
		                            <span class="muted mini"><?= htmlspecialchars((string)($r['notes'] ?? '')) ?></span>
			                          </td>
			                          <td>
			                            <?php
			                              $editParams = $_GET;
			                              $editParams['edit_id'] = $rid;
			                              $editParams['focus_signal_key'] = $sigKey;
			                              $editLink = 'admin_scoring_action_rules.php?' . http_build_query($editParams) . '#rule_' . $rid;
			                            ?>
			                            <a class="btn secondary" href="<?= htmlspecialchars($editLink) ?>">Edit</a>
			                            <?php
			                              $deleteParams = $_GET;
			                              $deleteParams['focus_signal_key'] = $sigKey;
			                              $deleteAction = 'admin_scoring_action_rules.php?' . http_build_query($deleteParams) . '#' . $sigHtmlId;
			                            ?>
			                            <form method="post" action="<?= htmlspecialchars($deleteAction) ?>" class="d-inline" onsubmit="return confirm('Delete this rule? This action cannot be undone.');">
			                              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
			                              <input type="hidden" name="action" value="delete_rule">
			                              <input type="hidden" name="id" value="<?= $rid ?>">
			                              <input type="hidden" name="focus_signal_key" value="<?= htmlspecialchars($sigKey) ?>">
			                              <button class="btn danger" type="submit">Delete</button>
			                            </form>
			                          </td>
			                        </tr>
			                        <?php if ($editRuleId > 0 && $rid === $editRuleId): ?>
			                          <?php
			                            $cancelParams = $_GET;
			                            unset($cancelParams['edit_id']);
			                            $cancelLinkInline = 'admin_scoring_action_rules.php?' . http_build_query($cancelParams) . '#rule_' . $rid;

			                            $enabledSelInline = (int)($editRule['is_enabled'] ?? 1);
			                            $scopeTypeSelInline = (string)($editRule['scope_type'] ?? 'global');
			                            $scopeValueSelInline = (string)($editRule['scope_value'] ?? '');
			                            $targetTypeSelInline = (string)($editRule['target_type'] ?? 'product');
			                            $targetCodeSelInline = (string)($editRule['target_code'] ?? '');
			                            $actionTypeSelInline = (string)($editRule['action_type'] ?? 'score');
			                            $deltaSelInline = (int)($editRule['score_delta'] ?? 0);
			                            $signalSelInline = (string)($editRule['signal_key'] ?? '');
			                            $opSelInline = (string)($editRule['operator'] ?? 'equals');
			                            $value1SelInline = (string)($editRule['value1'] ?? '');
			                            $value2SelInline = (string)($editRule['value2'] ?? '');
			                            $labelSelInline = (string)($editRule['label'] ?? '');
			                            $notesSelInline = (string)($editRule['notes'] ?? '');
			                          ?>
			                          <tr style="background:#fffbe6;">
			                            <td colspan="9">
			                              <?php
			                                $saveParams = $_GET;
			                                $saveParams['focus_signal_key'] = $sigKey;
			                                $saveAction = 'admin_scoring_action_rules.php?' . http_build_query($saveParams) . '#rule_' . $rid;
			                              ?>
			                              <form method="post" action="<?= htmlspecialchars($saveAction) ?>" class="row js-target-rule-form js-rule-form" style="margin:10px 0;">
			                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
			                                <input type="hidden" name="action" value="update_rule">
			                                <input type="hidden" name="id" value="<?= $rid ?>">

			                                <div>
			                                  <label class="muted mini" class="d-block">Enabled</label>
			                                  <select name="is_enabled">
			                                    <option value="1" <?= $enabledSelInline === 1 ? 'selected' : '' ?>>Yes</option>
			                                    <option value="0" <?= $enabledSelInline === 0 ? 'selected' : '' ?>>No</option>
			                                  </select>
			                                </div>

			                                <div>
			                                  <label class="muted mini" class="d-block">Scope type</label>
			                                  <select name="scope_type">
			                                    <option value="global" <?= $scopeTypeSelInline === 'global' ? 'selected' : '' ?>>Global</option>
			                                    <option value="org" <?= $scopeTypeSelInline === 'org' ? 'selected' : '' ?>>Org (group)</option>
			                                    <option value="store" <?= $scopeTypeSelInline === 'store' ? 'selected' : '' ?>>Store</option>
			                                  </select>
			                                </div>

			                                <div>
			                                  <label class="muted mini" class="d-block">Scope value</label>
			                                  <select name="scope_value">
			                                    <option value="" <?= $scopeValueSelInline === '' ? 'selected' : '' ?>>(blank for global)</option>
			                                    <?php foreach ($orgRows as $o): ?>
			                                      <?php
			                                        $oid = (int)($o['id'] ?? 0);
			                                        if ($oid <= 0) continue;
			                                        $ok = (string)($o['org_kind'] ?? '');
			                                        $label = (string)($o['name'] ?? ('Org ' . $oid));
			                                        $suffix = $ok !== '' ? (' [' . $ok . ']') : '';
			                                        $val = (string)$oid;
			                                      ?>
			                                      <option value="<?= htmlspecialchars($val) ?>" <?= $scopeValueSelInline === $val ? 'selected' : '' ?>><?= htmlspecialchars($label . $suffix . ' #' . $oid) ?></option>
			                                    <?php endforeach; ?>
			                                  </select>
			                                </div>

			                                <div>
			                                  <label class="muted mini" class="d-block">Target type</label>
			                                  <select name="target_type" class="js-target-type">
			                                    <option value="product" <?= $targetTypeSelInline === 'product' ? 'selected' : '' ?>>Product</option>
			                                    <option value="accessory" <?= $targetTypeSelInline === 'accessory' ? 'selected' : '' ?>>Accessory</option>
			                                  </select>
			                                </div>

			                                <div>
			                                  <label class="muted mini" class="d-block">Target code</label>
			                                  <input list="<?= $targetTypeSelInline === 'accessory' ? 'target_codes_accessory' : 'target_codes_product' ?>" class="js-target-code" type="text" name="target_code" value="<?= htmlspecialchars($targetCodeSelInline) ?>" required>
			                                  <div class="quick-picks js-target-code-quick"></div>
			                                </div>

			                                <div>
			                                  <label class="muted mini" class="d-block">Action</label>
			                                  <select name="action_type">
			                                    <option value="score" <?= $actionTypeSelInline === 'score' ? 'selected' : '' ?>>Score</option>
			                                    <option value="exclude" <?= $actionTypeSelInline === 'exclude' ? 'selected' : '' ?>>Exclude</option>
			                                  </select>
			                                </div>

			                                <div>
			                                  <label class="muted mini" class="d-block">Score delta</label>
			                                  <input type="number" name="score_delta" value="<?= htmlspecialchars((string)$deltaSelInline) ?>" class="w-110px">
			                                </div>

			                                <div style="min-width:240px;">
			                                  <label class="muted mini" class="d-block">Signal</label>
			                                  <input list="signals" class="js-signal-key" type="text" name="signal_key" value="<?= htmlspecialchars($signalSelInline) ?>" required>
			                                </div>

			                                <div>
			                                  <label class="muted mini" class="d-block">Operator</label>
			                                  <select name="operator" class="js-operator">
			                                    <?php foreach (operator_options() as $opValue => $opText): ?>
			                                      <option value="<?= htmlspecialchars($opValue) ?>" <?= $opSelInline === $opValue ? 'selected' : '' ?>><?= htmlspecialchars($opText) ?></option>
			                                    <?php endforeach; ?>
			                                  </select>
			                                </div>

			                                <div>
			                                  <label class="muted mini" class="d-block">Value 1</label>
			                                  <input type="text" class="js-value1" name="value1" value="<?= htmlspecialchars($value1SelInline) ?>" list="value1_presets_<?= (int)$rid ?>">
			                                  <datalist id="value1_presets_<?= (int)$rid ?>" class="js-value1-presets"></datalist>
			                                  <div class="js-value1-quick muted mini" class="mt-6"></div>
			                                </div>

			                                <div>
			                                  <label class="muted mini" class="d-block">Value 2</label>
			                                  <input type="text" class="js-value2" name="value2" value="<?= htmlspecialchars($value2SelInline) ?>" list="value2_presets_<?= (int)$rid ?>">
			                                  <datalist id="value2_presets_<?= (int)$rid ?>" class="js-value2-presets"></datalist>
			                                  <div class="js-value2-quick muted mini" class="mt-6"></div>
			                                </div>

			                                <div>
			                                  <label class="muted mini" class="d-block">Label</label>
			                                  <input type="text" name="label" value="<?= htmlspecialchars($labelSelInline) ?>">
			                                </div>

			                                <div style="flex:1; min-width:240px;">
			                                  <label class="muted mini" class="d-block">Notes / AI hint</label>
			                                  <input type="text" name="notes" value="<?= htmlspecialchars($notesSelInline) ?>" class="w-100">
			                                </div>

			                                <button class="btn" type="submit">Save</button>
			                                <a class="btn secondary" href="<?= htmlspecialchars($cancelLinkInline) ?>">Cancel</a>
			                              </form>
			                            </td>
			                          </tr>
			                        <?php endif; ?>
			                      <?php endforeach; ?>
			                    </tbody>
			                  </table>
			                <?php endif; ?>
		              </div>
		            </details>
		          <?php endforeach; ?>
		        <?php endforeach; ?>
		      <?php endif; ?>
		    </div>

    <div class="card">
      <h3 class="mt-0">Test Against Deal</h3>
      <form method="get" class="row">
        <div>
          <label class="muted mini" class="d-block">Deal ID</label>
          <input type="number" name="deal_id" value="<?= $dealId > 0 ? $dealId : '' ?>" style="width:140px;">
        </div>
        <button class="btn" type="submit">Preview</button>
      </form>
	      <?php if ($dealId > 0): ?>
	        <?php if (empty($preview['signals'])): ?>
	          <p class="error">Deal not found or missing data.</p>
	        <?php else: ?>
	          <details class="mt-10">
	            <summary class="muted" class="cursor-pointer">Signals for this deal (what rules can reference)</summary>
	            <?php
	              $signalRows = [];
	              foreach (($preview['signals'] ?? []) as $k => $v) {
	                  if ($v === null || $v === '') continue;
	                  if (is_array($v) && empty($v)) continue;
	                  $signalRows[] = [$k, $v];
	              }
	              usort($signalRows, fn($a, $b) => strcmp((string)$a[0], (string)$b[0]));
	            ?>
	            <?php if (empty($signalRows)): ?>
	              <p class="muted mini" class="mt-8">No populated signals found for this deal.</p>
	            <?php else: ?>
	              <table>
	                <thead>
	                  <tr>
	                    <th style="width:35%;">Signal key</th>
	                    <th>Value</th>
	                  </tr>
	                </thead>
	                <tbody>
	                  <?php foreach ($signalRows as [$k, $v]): ?>
	                    <tr>
	                      <td><code><?= htmlspecialchars((string)$k) ?></code></td>
	                      <td>
	                        <?php
	                          if (is_array($v)) {
	                              $vv = json_encode($v, JSON_UNESCAPED_SLASHES);
	                              echo '<code>' . htmlspecialchars((string)$vv) . '</code>';
	                          } elseif (is_bool($v)) {
	                              echo htmlspecialchars($v ? 'true' : 'false');
	                          } else {
	                              echo htmlspecialchars((string)$v);
	                          }
	                        ?>
	                      </td>
	                    </tr>
	                  <?php endforeach; ?>
	                </tbody>
	              </table>
	            <?php endif; ?>
	            <p class="muted mini" class="mt-8">Credit app answers come from <code>applications.usage_data</code> and appear as <code>usage.&lt;key&gt;</code>.</p>
	          </details>

	          <p class="muted mini">Matched rules: <?= count($preview['matches']) ?></p>
	          <?php if (!empty($preview['matches'])): ?>
	            <table>
	              <thead>
	                <tr>
                  <th>ID</th>
                  <th>Scope</th>
                  <th>Target</th>
                  <th>Action</th>
                  <th>Delta</th>
                  <th>Condition</th>
                  <th>Label/Notes</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($preview['matches'] as $m): ?>
                  <tr>
                    <td><?= (int)($m['id'] ?? 0) ?></td>
                    <td><?= htmlspecialchars((string)($m['scope_type'] ?? '')) ?><?= ($m['scope_value'] ?? '') !== '' ? ':' . htmlspecialchars((string)$m['scope_value']) : '' ?></td>
                    <td><?= htmlspecialchars((string)($m['target_type'] ?? '')) ?>:<code><?= htmlspecialchars((string)($m['target_code'] ?? '')) ?></code></td>
                    <td><?= htmlspecialchars((string)($m['action_type'] ?? '')) ?></td>
                    <td><?= (int)($m['score_delta'] ?? 0) ?></td>
                    <td><code><?= htmlspecialchars((string)($m['signal_key'] ?? '')) ?></code> <?= htmlspecialchars(operator_label((string)($m['operator'] ?? ''))) ?> <?= htmlspecialchars((string)($m['value1'] ?? '')) ?><?= !empty($m['value2']) ? ' .. ' . htmlspecialchars((string)$m['value2']) : '' ?></td>
                    <td><?php if (!empty($m['label'])): ?><code><?= htmlspecialchars((string)$m['label']) ?></code><br><?php endif; ?><span class="muted mini"><?= htmlspecialchars((string)($m['notes'] ?? '')) ?></span></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>
