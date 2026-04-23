<?php
require_once __DIR__ . '/includes/session_bootstrap.php';

// Generate and store the CSRF token in the session
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); // A unique token
}

include 'auth.php';
require_once __DIR__ . '/includes/theme_head.php';
include 'db.php';
require_once __DIR__ . '/helpers/session_utils.php';
require_once __DIR__ . '/helpers/db_utils.php';
require_once __DIR__ . '/helpers/deal_audit.php';
require_once __DIR__ . '/helpers/usage_data.php';
require_once __DIR__ . '/helpers/theme.php';
include_once 'protection_helpers.php';
require_once __DIR__ . '/helpers/organization_question_config.php';

// Load organization theme color and logo
$org = $_SESSION['organization'];
$theme = dealerfai_get_theme_palette(null, ['color' => '#0066cc']);

if ($org) {
  $hasLeaseCapPercentColumn = organization_column_exists($db, 'lease_msrp_cap_percent');
  $hasFinanceCapPercentColumn = organization_column_exists($db, 'finance_msrp_cap_percent');
  $selectFields = 'logo_url, theme_variant'
      . ($hasLeaseCapPercentColumn ? ', lease_msrp_cap_percent' : '')
      . ($hasFinanceCapPercentColumn ? ', finance_msrp_cap_percent' : '');
  $stmt = $db->prepare("SELECT {$selectFields} FROM organizations WHERE id = ?");
  $stmt->execute([$org]);
  $orgData = $stmt->fetch(PDO::FETCH_ASSOC);
  if ($orgData) {
    $theme = dealerfai_get_theme_palette($orgData['theme_variant'] ?? null, ['logo' => $orgData['logo_url'] ?? '']);
  }
}

// Helper to render full names for assigned users
function getUserName($db, $userId) {
  if (!$userId) return '—';
  $stmt = $db->prepare("SELECT full_name FROM users WHERE id = ?");
  $stmt->execute([$userId]);
  return $stmt->fetchColumn() ?: '—';
}
/**
 * Calculate the estimated payment using only total_to_finance, interest_rate, and term (in months).
 */
function calculate_payment($total_to_finance, $interest_rate, $term) {
    $total_to_finance = (float)$total_to_finance;
    $term = (int)$term;
    if ($total_to_finance <= 0 || $term <= 0) {
        return 0.0;
    }

    // Convert annual interest rate to monthly interest rate
    $monthly_interest_rate = ((float)$interest_rate / 100) / 12;

    // Apply the amortization formula to calculate the payment
    if ($monthly_interest_rate > 0) {
        $denominator = 1 - pow(1 + $monthly_interest_rate, -$term);
        if ($denominator == 0.0) {
            return 0.0;
        }
        $payment = ($total_to_finance * $monthly_interest_rate) / $denominator;
    } else {
        // If there's no interest, simply divide the total amount to finance by the number of payments
        $payment = $total_to_finance / $term;
    }

    // Round the result to two decimal places
    return round($payment, 2);
}

function calculate_gross($price) {
    return round($price * 0.5, 2); // Assuming 50% gross profit margin
}

function calculate_lease_payment($cap_cost, $residual_value, $term, $interest_rate, $tax_rate = 0.0) {
    $cap_cost = (float)$cap_cost;
    $residual_value = (float)$residual_value;
    $term = (int)$term;
    if ($cap_cost <= 0 || $term <= 0) {
        return 0.0;
    }
    $money_factor = ((float)$interest_rate / 100) / 24;
    $depreciation = ($cap_cost - $residual_value) / $term;
    $finance_charge = ($cap_cost + $residual_value) * $money_factor;
    $base_payment = $depreciation + $finance_charge;
    $total_payment = $base_payment * (1 + (float)$tax_rate);
    return round($total_payment, 2);
}

function calculate_addon_payment($deal_type, $price, $term, $interest_rate) {
    $price = (float)$price;
    if ($price <= 0) {
        return 0.0;
    }
    if ($deal_type === 'Lease') {
        return calculate_lease_payment($price, 0, $term, $interest_rate, 0.0);
    }
    if ($deal_type === 'Cash') {
        return 0.0;
    }
    return calculate_payment($price, $interest_rate, $term);
}

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

function normalize_vehicle_id($value): ?int {
    if ($value === null || $value === '') {
        return null;
    }
    $int = (int)$value;
    return $int > 0 ? $int : null;
}

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

function resolve_default_term_for_product(PDO $db, int $productId, ?int $organizationId): ?int {
    if ($productId <= 0 || !$organizationId) {
        return null;
    }
    try {
        $checkStmt = $db->query("SELECT 1 FROM product_term_defaults LIMIT 1");
        if ($checkStmt === false) {
            return null;
        }
    } catch (PDOException $e) {
        return null;
    }
    $orgKind = null;
    $parentId = null;
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
            return (int)$term;
        }
    }
    return null;
}

function resolve_pricing_scope_candidates(PDO $db, ?int $organizationId): array {
    if (!$organizationId) {
        return [['global', '']];
    }
    $orgKind = null;
    $parentId = null;
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

function apply_vehicle_pricing_override(PDO $db, array $product, ?int $vehicleMakeId, ?int $vehicleModelId, ?int $vehicleTrimId, int $term, array $deal, ?int $organizationId = null): array {
    $productId = isset($product['id']) ? (int)$product['id'] : 0;
    if (!$vehicleMakeId || $productId <= 0) {
        return $product;
    }

    $vehicleModelId = normalize_vehicle_id($vehicleModelId);
    $vehicleTrimId = normalize_vehicle_id($vehicleTrimId);

    try {
        $hasScopeType = column_exists($db, 'product_vehicle_pricing_overrides', 'scope_type');
        $hasScopeValue = column_exists($db, 'product_vehicle_pricing_overrides', 'scope_value');
        $hasMaxKms = column_exists($db, 'product_vehicle_pricing_overrides', 'max_kms');
        $hasMaxAge = column_exists($db, 'product_vehicle_pricing_overrides', 'max_age_years');
        $selectCols = "price, cost, coverage_term, created_at";
        $selectCols .= $hasScopeType ? ", scope_type" : ", 'global' AS scope_type";
        $selectCols .= $hasScopeValue ? ", scope_value" : ", '' AS scope_value";
        $selectCols .= $hasMaxKms ? ", max_kms" : ", NULL AS max_kms";
        $selectCols .= $hasMaxAge ? ", max_age_years" : ", NULL AS max_age_years";
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
                $product['price'] = (float)$override['price'];
            }
            if ($override['cost'] !== null) {
                $product['cost'] = (float)$override['cost'];
            }
            if (!empty($override['coverage_term'])) {
                $product['coverage_term'] = (int)$override['coverage_term'];
            }
            return $product;
        }
    } catch (PDOException $e) {
        // Fall back to legacy pricing overrides if table is missing.
    }

    $stmt = $db->prepare("
        SELECT price, cost, coverage_term, created_at
        FROM product_pricing_overrides
        WHERE product_code = ?
          AND vehicle_make_id = ?
        ORDER BY created_at DESC
    ");
    $stmt->execute([$product['code'] ?? null, $vehicleMakeId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $override = choose_pricing_override($rows, $term);
    if ($override) {
        if ($override['price'] !== null) {
            $product['price'] = (float)$override['price'];
        }
        if ($override['cost'] !== null) {
            $product['cost'] = (float)$override['cost'];
        }
        if (!empty($override['coverage_term'])) {
            $product['coverage_term'] = (int)$override['coverage_term'];
        }
    }
    return $product;
}

// CSRF token validation on POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token']) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die('Invalid CSRF token');
    }
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$deal_id = $_GET['id'] ?? null;
if (!$deal_id) { echo "<p style='color:red;'>No deal ID provided.</p>"; exit; }

$stmt = $db->prepare("SELECT * FROM deals WHERE id = ?");
$stmt->execute([$deal_id]);
$deal = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$deal) { echo "<p style='color:red;'>Deal not found.</p>"; exit; }

$creditAppLocked = !empty($deal['credit_app_locked']);
$isLeaseDeal = strcasecmp((string)($deal['deal_type'] ?? ''), 'Lease') === 0;
$isFinanceDeal = strcasecmp((string)($deal['deal_type'] ?? ''), 'Finance') === 0;

$isNewVehicle = false;
$vehicleCondition = $deal['vehicle_condition'] ?? 'Used';
$isNewVehicle = strtolower((string)$vehicleCondition) === 'new';

$vehicle_make_name = '';
if (!empty($deal['vehicle_make_id'])) {
    $makeStmt = $db->prepare("SELECT make_name FROM vehicle_makes WHERE id = ?");
    $makeStmt->execute([$deal['vehicle_make_id']]);
    $vehicle_make_name = $makeStmt->fetchColumn() ?: '';
}
if (!$vehicle_make_name && !empty($deal['vehicle_make'])) {
    $vehicle_make_name = $deal['vehicle_make'];
}

$preferredWarrantyProvider = null;
if ($vehicle_make_name) {
    $makeKey = strtolower(trim($vehicle_make_name));
    if ($makeKey === 'jaguar') {
        $preferredWarrantyProvider = 'Jaguar';
    } elseif ($makeKey === 'land rover' || $makeKey === 'landrover') {
        $preferredWarrantyProvider = 'Land Rover';
    }
}

$user_id = $_SESSION['user_id'];
$roles = load_session_roles();
$isAdmin = in_array('Admin', $roles, true);
$adminAlertCount = $isAdmin ? get_admin_alert_count($db) : 0;
$isManager = in_array('General Manager', $roles, true) || in_array('Finance Manager', $roles, true);
$effectiveOrg = get_effective_organization();
$accessibleOrgs = get_accessible_organizations();

$allowedOrg = false;
if ($isAdmin) {
    $allowedOrg = true;
} else {
    if ($effectiveOrg && (string)$deal['organization'] === (string)$effectiveOrg) {
        $allowedOrg = true;
    } else {
        $dealOrgId = (int)$deal['organization'];
        foreach ($accessibleOrgs as $orgId) {
            if ((int)$orgId === $dealOrgId) {
                $allowedOrg = true;
                break;
            }
        }
    }
}

if (!$allowedOrg || (!$isAdmin && !$isManager && (int)$deal['salesperson_id'] !== (int)$user_id)) {
    echo "<p style='color:red;'>You do not have access to this deal.</p>"; exit;
}

$flashSuccess = '';
$flashError = '';
if (!empty($_SESSION['flash_success'])) {
    $flashSuccess = (string)$_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
if (!empty($_SESSION['flash_error'])) {
    $flashError = (string)$_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

$hasCustomerContext = false;
$customerContextRaw = '';
$customerContext = [];
try {
    $hasCustomerContext = function_exists('column_exists') && column_exists($db, 'deals', 'customer_context');
} catch (Throwable $e) {
    $hasCustomerContext = false;
}
if ($hasCustomerContext) {
    $customerContextRaw = trim((string)($deal['customer_context'] ?? ''));
    if ($customerContextRaw !== '') {
        $decoded = json_decode($customerContextRaw, true);
        if (is_array($decoded)) {
            $customerContext = $decoded;
        } else {
            // Backward-compatible: treat any non-JSON content as freeform notes.
            $customerContext = ['freeform' => $customerContextRaw];
        }
    }
}

// Fetch protection selections
$appStmt = $db->prepare("SELECT selected_protections, all_recommendations, usage_data FROM applications WHERE deal_id = ?");
$appStmt->execute([$deal_id]);
$app = $appStmt->fetch(PDO::FETCH_ASSOC);

$selected = $app && $app['selected_protections'] ? json_decode($app['selected_protections'], true) : [];
$recommended = $app && $app['all_recommendations'] ? json_decode($app['all_recommendations'], true) : [];
$usageData = $app ? dealerfai_decode_usage_data($app['usage_data'] ?? null) : [];
if (!is_array($usageData)) {
    $usageData = [];
}
$selected = is_array($selected) ? $selected : [];
$recommended = is_array($recommended) ? $recommended : [];

$codeAliases = [
    'warranty' => 'extWarranty',
    'extwarranty' => 'extWarranty',
    'gap' => 'gapProtection',
    'gapprotection' => 'gapProtection',
    'cap' => 'assetProtection',
    'assetprotection' => 'assetProtection',
    'ceramic' => 'ceramicCoating',
    'ceramiccoating' => 'ceramicCoating',
    'interior' => 'interiorProtection',
    'interiorprotection' => 'interiorProtection',
    'interior_protection' => 'interiorProtection',
    'tire_rim' => 'tireRim',
    'tirerim' => 'tireRim',
    'theft' => 'theftProtection',
    'film' => 'filmProtection',
    'xpel' => 'filmProtection',
    'rust' => 'rustModule',
    'windshield' => 'glassProtection',
    'windshield_protection' => 'glassProtection',
    'dent' => 'dentDing',
    'worryfree' => 'worryFree',
    'worryfreemaintenance' => 'worryFree',
    'xpel_standard' => 'xpel_basic',
    'xpel_full_wrap' => 'xpel_full_vehicle_wrap',
];

$normalizeCode = function ($code) use ($codeAliases) {
    $raw = trim((string) $code);
    if ($raw === '') {
        return null;
    }
    $key = strtolower($raw);
    return $codeAliases[$key] ?? $raw;
};

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

function is_luxury_vehicle_make(?string $make): bool {
    if ($make === null) {
        return false;
    }
    $normalized = strtolower(trim($make));
    if ($normalized === '') {
        return false;
    }
    $luxuryMakes = [
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

function load_xpel_variants(PDO $db): array {
    $optionSet = null;
    $stmt = $db->prepare("
        SELECT option_set_id
        FROM products
        WHERE code = 'filmProtection'
          AND option_set_id IS NOT NULL
        ORDER BY COALESCE(default_price, 0) ASC
        LIMIT 1
    ");
    $stmt->execute();
    $optionSet = $stmt->fetchColumn();
    if (!$optionSet) {
        return [];
    }
    $hasLuxury = function_exists('column_exists') ? column_exists($db, 'products', 'contributes_to_luxury_tax') : false;
    $hasGst = function_exists('column_exists') ? column_exists($db, 'products', 'gst_taxable') : false;
    $hasPst = function_exists('column_exists') ? column_exists($db, 'products', 'pst_taxable') : false;
    $extra = '';
    if ($hasLuxury) $extra .= ', contributes_to_luxury_tax';
    if ($hasGst) $extra .= ', gst_taxable';
    if ($hasPst) $extra .= ', pst_taxable';
    $setStmt = $db->prepare("
        SELECT id, name AS product_name, default_price, default_description{$extra}
        FROM products
        WHERE option_set_id = ?
          AND is_active = 1
        ORDER BY COALESCE(default_price, 0) ASC
    ");
    $setStmt->execute([$optionSet]);
    $variants = [];
    while ($row = $setStmt->fetch(PDO::FETCH_ASSOC)) {
        $label = $row['product_name'] ?? '';
        if ($label === '') {
            continue;
        }
        $code = xpel_variant_value($label);
        $variants[$code] = [
            'id' => (int)($row['id'] ?? 0),
            'code' => $code,
            'name' => $label,
            'price' => isset($row['default_price']) ? (float)$row['default_price'] : 0.0,
            'reason' => $row['default_description'] ?? '',
            'contributes_to_luxury_tax' => !empty($row['contributes_to_luxury_tax'] ?? null),
            'gst_taxable' => !array_key_exists('gst_taxable', $row) || !empty($row['gst_taxable']),
            'pst_taxable' => !array_key_exists('pst_taxable', $row) || !empty($row['pst_taxable']),
        ];
    }
    return $variants;
}

$selected = array_values(array_filter(array_map($normalizeCode, $selected)));
$recommended = array_values(array_filter(array_map($normalizeCode, $recommended)));

$hasXpelVariant = false;
foreach ($selected as $code) {
    if (str_starts_with($code, 'xpel_')) {
        $hasXpelVariant = true;
        break;
    }
}
if ($hasXpelVariant) {
    $selected = array_values(array_filter(
        $selected,
        fn($code) => $code !== 'filmProtection'
    ));
}

$included_raw = function_exists('parse_included_protections')
    ? parse_included_protections($deal['included_protections'] ?? null)
    : [];
$included_protections = [];
foreach ($included_raw as $item) {
    if (!is_array($item)) {
        continue;
    }
    $code = $normalizeCode($item['code'] ?? '');
    $name = trim((string)($item['name'] ?? ''));
    $price = isset($item['price']) ? (float)$item['price'] : 0.0;
    if (!$code && $name === '') {
        continue;
    }
    if (!$code) {
        $code = $name;
    }
    $included_protections[] = [
        'code' => $code,
        'name' => $name !== '' ? $name : $code,
        'price' => $price,
    ];
}

// Additional business rules (use canonical codes)
if ($deal['deal_type'] === 'Finance' && intval($deal['term']) >= 60 && !in_array('gapProtection', $recommended, true)) {
    $recommended[] = 'gapProtection';
}

if ($deal['deal_type'] === 'Finance' && intval($deal['term']) > 24 && !in_array('gapProtection', $recommended, true) && !in_array('assetProtection', $recommended, true)) {
    $recommended[] = 'assetProtection';
}

if (isset($deal['vehicle_usage']) && stripos($deal['vehicle_usage'], 'highway') !== false && !in_array('filmProtection', $recommended, true)) {
    $recommended[] = 'filmProtection';
}

$unique_recommended = array_values(array_unique($recommended));
$unique_selected = array_values(array_unique($selected));
$recommendedRank = [];
foreach ($unique_recommended as $index => $code) {
    $recommendedRank[$code] = $index;
}

$productMap = [];

try {
    $orgIdForCap = isset($deal['organization']) ? (int)$deal['organization'] : 0;
    $hasLuxury = function_exists('column_exists') ? column_exists($db, 'products', 'contributes_to_luxury_tax') : false;
    $hasGst = function_exists('column_exists') ? column_exists($db, 'products', 'gst_taxable') : false;
    $hasPst = function_exists('column_exists') ? column_exists($db, 'products', 'pst_taxable') : false;
    $taxFields = '';
    if ($hasLuxury) $taxFields .= ", p.contributes_to_luxury_tax";
    if ($hasGst) $taxFields .= ", p.gst_taxable";
    if ($hasPst) $taxFields .= ", p.pst_taxable";
    if ($orgIdForCap > 0) {
        $catalogStmt = $db->prepare("
            SELECT p.id, p.code, p.name, p.default_price, p.default_description, p.default_reason, p.provider,
                   1=1 {$taxFields},
                   COALESCE(o.lease_cap_exempt_override, g.lease_cap_exempt_override, p.lease_cap_exempt) AS lease_cap_exempt,
                   COALESCE(o.finance_cap_exempt_override, g.finance_cap_exempt_override, p.finance_cap_exempt) AS finance_cap_exempt
            FROM products p
            LEFT JOIN product_organization_overrides o
              ON o.product_id = p.id AND o.organization_id = ?
            LEFT JOIN product_organization_overrides g
              ON g.product_id = p.id AND g.organization_id = 0
        ");
        $catalogStmt->execute([$orgIdForCap]);
    } else {
        $cols = "id, code, name, default_price, default_description, default_reason, provider";
        if ($hasLuxury) $cols .= ", contributes_to_luxury_tax";
        if ($hasGst) $cols .= ", gst_taxable";
        if ($hasPst) $cols .= ", pst_taxable";
        $cols .= ", lease_cap_exempt, finance_cap_exempt";
        $catalogStmt = $db->query("SELECT {$cols} FROM products");
    }
    foreach ($catalogStmt as $row) {
	        $code = $normalizeCode($row['code'] ?? '');
	        if (!$code) continue;
        $price = $row['default_price'] ?? 0;
	        $entry = [
	            'id' => (int)($row['id'] ?? 0),
	            'code' => $row['code'] ?? $code,
	            'name' => $row['name'] ?? $code,
	            'price' => (float)$price,
            'reason' => $row['default_description'] ?? $row['default_reason'] ?? '',
            'lease_cap_exempt' => !empty($row['lease_cap_exempt']),
            'finance_cap_exempt' => !empty($row['finance_cap_exempt']),
            'contributes_to_luxury_tax' => !empty($row['contributes_to_luxury_tax'] ?? null),
            // Default to taxable to preserve legacy behavior if the column doesn't exist.
            'gst_taxable' => !array_key_exists('gst_taxable', $row) || !empty($row['gst_taxable']),
            'pst_taxable' => !array_key_exists('pst_taxable', $row) || !empty($row['pst_taxable']),
        ];
        if ($code === 'extWarranty' && $preferredWarrantyProvider) {
            $provider = strtolower(trim($row['provider'] ?? ''));
            if ($provider === strtolower($preferredWarrantyProvider)) {
                $productMap[$code] = $entry;
            } elseif (!isset($productMap[$code])) {
                $productMap[$code] = $entry;
            }
        } else {
            $productMap[$code] = $entry;
        }
    }
} catch (PDOException $e) {
    error_log("⚠️ Failed to load product catalogue: " . $e->getMessage());
}

try {
    $recStmt = $db->prepare("
        SELECT id, product_code, product_name, sale_price, description, ai_explanation, score
        FROM product_recommendations
        WHERE deal_id = ?
        ORDER BY
            CASE WHEN score IS NULL THEN 1 ELSE 0 END ASC,
            score DESC,
            id ASC
    ");
    $recStmt->execute([$deal_id]);
    $recRows = $recStmt->fetchAll(PDO::FETCH_ASSOC);
    $scoredOrder = 0;
    foreach ($recRows as $row) {
        $code = $normalizeCode($row['product_code'] ?? '');
        if (!$code) continue;
        if (!isset($recommendedRank[$code]) || $recommendedRank[$code] > $scoredOrder) {
            $recommendedRank[$code] = $scoredOrder;
        }
        $scoredOrder++;
        $productMap[$code] = [
            'id' => $productMap[$code]['id'] ?? 0,
            'code' => $row['product_code'] ?? ($productMap[$code]['code'] ?? $code),
            'name'  => $row['product_name'] ?? ($productMap[$code]['name'] ?? $code),
            'price' => isset($row['sale_price']) ? (float)$row['sale_price'] : ($productMap[$code]['price'] ?? 0),
            'reason'=> $row['ai_explanation']
                ?? $row['description']
                ?? ($productMap[$code]['reason'] ?? ''),
            'lease_cap_exempt' => $productMap[$code]['lease_cap_exempt'] ?? false,
            'finance_cap_exempt' => $productMap[$code]['finance_cap_exempt'] ?? false,
        ];
    }
} catch (PDOException $e) {
    error_log("⚠️ Failed to load deal recommendations: " . $e->getMessage());
}

try {
    $xpelVariants = load_xpel_variants($db);
    foreach ($xpelVariants as $code => $variant) {
        $productMap[$code] = $variant;
    }
    if (!empty($xpelVariants) && isset($productMap['filmProtection'])) {
        $preferIntermediate = is_luxury_vehicle_make($vehicle_make_name)
            || (float)($deal['sale_price'] ?? 0) >= 80000;
        $defaultKey = $preferIntermediate ? 'xpel_intermediate' : array_key_first($xpelVariants);
        if ($defaultKey && isset($xpelVariants[$defaultKey])) {
            $productMap['filmProtection'] = $xpelVariants[$defaultKey];
        }
    }
} catch (PDOException $e) {
    error_log("⚠️ Failed to load XPEL package variants: " . $e->getMessage());
}

$vehicle_make_id = isset($deal['vehicle_make_id']) ? (int)$deal['vehicle_make_id'] : null;
$vehicle_model_id = isset($deal['vehicle_model_id']) ? (int)$deal['vehicle_model_id'] : null;
$vehicle_trim_id = isset($deal['vehicle_trim_id']) ? (int)$deal['vehicle_trim_id'] : null;
$deal_term = isset($deal['term']) ? (int)$deal['term'] : 0;
if ($vehicle_make_id && !empty($productMap)) {
    foreach ($productMap as $code => $entry) {
        if (!is_array($entry)) {
            continue;
        }
        if (!isset($entry['code'])) {
            $entry['code'] = $code;
        }
        $defaultTerm = isset($entry['id']) ? resolve_default_term_for_product($db, (int)$entry['id'], isset($deal['organization']) ? (int)$deal['organization'] : null) : null;
        $pricingTerm = $defaultTerm ?? $deal_term;
        $productMap[$code] = apply_vehicle_pricing_override($db, $entry, $vehicle_make_id, $vehicle_model_id, $vehicle_trim_id, $pricingTerm, $deal, isset($deal['organization']) ? (int)$deal['organization'] : null);
    }
}

$legacyProductMap = [
    'tire_rim' => [
        'name' => 'Tire & Rim Protection',
        'price' => 1595,
        'reason' => "You reported driving on poor roads. As of 2024, CAA Manitoba noted the average pothole repair cost is $962, especially on luxury/performance tires."
    ],
    'ceramic' => [
        'name' => 'Ceramic Coating',
        'price' => 1195,
        'reason' => "You park outdoors and/or are keeping the vehicle 3+ years. Ceramic protects luxury paint from UV, salt, and more."
    ],
    'interior' => [
        'name' => 'Interior Protection',
        'price' => 995,
        'reason' => "You have pets or kids. Interior protection helps maintain the resale value of your vehicle’s luxury interior."
    ],
    'warranty' => [
        'name' => 'Extended Warranty',
        'price' => 2495,
        'reason' => "Your ownership period may outlast factory warranty. Extended Warranty covers unexpected luxury vehicle repairs."
    ],
    'theft' => [
        'name' => 'Theft Coverage',
        'price' => 895,
        'reason' => "Luxury vehicles are often targeted for theft. Theft coverage protects your investment."
    ],
    'gap' => [
        'name' => 'Guaranteed Asset Protection (GAP)',
        'price' => 695,
        'reason' => "Protect your investment in case the vehicle is totaled or stolen. GAP covers the difference between what you owe and the vehicle's actual value."
    ],
    'cap' => [
        'name' => 'Companion Asset Protection (CAP)',
        'price' => 1495,
        'reason' => "Protect your vehicle and assets with Companion Asset Protection. Ideal for longer-term finance deals."
    ],
    'xpel' => [
        'name' => 'XPEL Paint Protection Film',
        'price' => 2295,
        'reason' => "Highway driving exposes the vehicle to rock chips and debris. Paint Protection Film shields high-impact areas."
    ],
    'rust' => [
        'name' => 'Corrosion & Rust',
        'price' => 795,
        'reason' => "Road salt and harsh winters accelerate corrosion. Rust protection keeps the vehicle looking new."
    ],
    'dent' => [
        'name' => 'Dent & Ding',
        'price' => 895,
        'reason' => "Parking lots and tight spaces cause dents. Dent & Ding keeps your exterior spotless with paintless repairs."
    ],
    'windshield' => [
        'name' => 'Windshield Protection',
        'price' => 595,
        'reason' => "Highway debris can crack windshields. Windshield protection covers chip repairs and replacements."
    ],
    'tint' => [
        'name' => 'Window Tint',
        'price' => 395,
        'reason' => "UV rays can damage interiors. Window tint reduces glare, heat, and maintains privacy."
    ],
    'interior_protection' => [
        'name' => 'Interior Protection',
        'price' => 745,
        'reason' => "Stains, spills, and wear are common with pets or kids. Interior protection keeps the cabin like new."
    ],
    'worryFree' => [
        'name' => 'Worry-Free Maintenance',
        'price' => 1095,
        'reason' => "Pre-paid maintenance keeps your service costs predictable throughout ownership."
    ],
];

foreach ($legacyProductMap as $legacyCode => $details) {
    $code = $normalizeCode($legacyCode);
    if (!$code || isset($productMap[$code])) {
        continue;
    }
    $productMap[$code] = [
        'name' => $details['name'],
        'price' => $details['price'],
        'reason' => $details['reason'],
    ];
}

// Now compute protections_total AFTER productMap is available
$physical_protection_codes = [
    'filmProtection',
    'ceramicCoating',
    'interiorProtection',
    'tireRim',
    'rustModule',
    'dentDing',
    'glassProtection',
    'tint',
];
$insurance_codes = ['extWarranty', 'gapProtection', 'assetProtection', 'theftProtection'];
$physical_keywords = ['paint', 'xpel', 'ceramic', 'interior', 'body', 'foundation', 'rust', 'dent', 'windshield', 'glass', 'tint', 'tire', 'rim', 'film'];

$isPhysicalProtection = function ($code, $name) use ($physical_protection_codes, $insurance_codes, $physical_keywords): bool {
    if (in_array($code, $physical_protection_codes, true)) {
        return true;
    }
    if (in_array($code, $insurance_codes, true)) {
        return false;
    }
    $normalized = strtolower((string)$name);
    foreach ($physical_keywords as $keyword) {
        if ($normalized !== '' && strpos($normalized, $keyword) !== false) {
            return true;
        }
    }
    return false;
};

$included_total = 0;
$included_physical_total = 0;
$included_gst_taxable_total = 0.0;
$included_pst_taxable_total = 0.0;
$included_luxury_total = 0.0;
$included_display = [];
foreach ($included_protections as $item) {
    $code = $item['code'] ?? '';
    if ($code === '') {
        continue;
    }
    $name = $item['name'] ?? ($productMap[$code]['name'] ?? $code);
    $price = isset($item['price']) ? (float)$item['price'] : 0.0;
    $gstTaxable = $productMap[$code]['gst_taxable'] ?? true;
    $pstTaxable = $productMap[$code]['pst_taxable'] ?? true;
    $luxuryBase = !empty($productMap[$code]['contributes_to_luxury_tax']);
    $included_total += $price;
    if ($isPhysicalProtection($code, $name)) {
        $included_physical_total += $price;
    }
    if ($gstTaxable) {
        $included_gst_taxable_total += $price;
    }
    if ($pstTaxable) {
        $included_pst_taxable_total += $price;
    }
    if ($luxuryBase) {
        $included_luxury_total += $price;
    }
    $included_display[] = [
        'code' => $code,
        'name' => $name,
        'price' => $price,
        'is_physical' => $isPhysicalProtection($code, $name),
    ];
}

$selected_protections_total = 0;
$selected_protections_cap_eligible_total = 0.0;
$selected_protections_exempt_total = 0.0;
$selected_physical_total = 0;
$selected_gst_taxable_total = 0.0;
$selected_pst_taxable_total = 0.0;
$selected_luxury_total = 0.0;
foreach ($selected as $code) {
    if (!isset($productMap[$code])) {
        continue;
    }
    $price = (float)$productMap[$code]['price'];
    $gstTaxable = $productMap[$code]['gst_taxable'] ?? true;
    $pstTaxable = $productMap[$code]['pst_taxable'] ?? true;
    $luxuryBase = !empty($productMap[$code]['contributes_to_luxury_tax']);
    $selected_protections_total += $price;
    if ($gstTaxable) {
        $selected_gst_taxable_total += $price;
    }
    if ($pstTaxable) {
        $selected_pst_taxable_total += $price;
    }
    if ($luxuryBase) {
        $selected_luxury_total += $price;
    }
    if ($isLeaseDeal && !empty($productMap[$code]['lease_cap_exempt'])) {
        $selected_protections_exempt_total += $price;
    } elseif ($isFinanceDeal && !empty($productMap[$code]['finance_cap_exempt'])) {
        $selected_protections_exempt_total += $price;
    } else {
        $selected_protections_cap_eligible_total += $price;
    }
    if ($isPhysicalProtection($code, $productMap[$code]['name'] ?? '')) {
        $selected_physical_total += $price;
    }
}

$protections_total = $selected_protections_total + $included_total;
$protections_cap_eligible_total = $included_total + $selected_protections_cap_eligible_total;
$protections_exempt_total = $selected_protections_exempt_total;
$physical_protections_total = $selected_physical_total + $included_physical_total;
$protections_gst_taxable_total = $included_gst_taxable_total + $selected_gst_taxable_total;
$protections_pst_taxable_total = $included_pst_taxable_total + $selected_pst_taxable_total;
$protections_luxury_total = $included_luxury_total + $selected_luxury_total;

$included_accessories = [];
$selected_accessories = [];
$accessories_total = 0.0;
$accessories_upfront_total = 0.0;
$accessories_finance_total = 0.0;
$accessories_cap_cost_total = 0.0;
$accessories_luxury_total = 0.0;
$accessories_residual_msrp_add = 0.0;
$accessory_residual_adjustments = [];
try {
    $accStmt = $db->prepare("SELECT * FROM deal_accessories WHERE deal_id = ?");
    $accStmt->execute([$deal_id]);
    while ($row = $accStmt->fetch(PDO::FETCH_ASSOC)) {
        $price = isset($row['sale_price']) ? (float)$row['sale_price'] : 0.0;
        $isIncluded = !empty($row['included']);
        $payMethod = $row['pay_method'] ?? '';
        $name = $row['accessory_name'] ?? ($row['accessory_code'] ?? 'Accessory');
        $entry = [
            'name' => $name,
            'price' => $price,
            'pay_method' => $payMethod,
            // Accessories always contribute to the luxury tax base when luxury tax applies.
            'contributes_to_luxury_tax' => true,
        ];
        if ($isIncluded) {
            $included_accessories[] = $entry;
        } else {
            $selected_accessories[] = $entry;
        }
        $accessories_total += $price;
        if ($payMethod === 'upfront') {
            $accessories_upfront_total += $price;
        } elseif ($payMethod === 'cap_cost') {
            $accessories_cap_cost_total += $price;
        } else {
            $accessories_finance_total += $price;
        }
        $accessories_luxury_total += $price;
        if (!empty($row['residualizable']) && $payMethod === 'cap_cost') {
            $residualAdd = isset($row['residual_msrp_add']) ? (float)$row['residual_msrp_add'] : 0.0;
            if ($residualAdd > 0) {
                $accessories_residual_msrp_add += $residualAdd;
                $accessory_residual_adjustments[] = [
                    'name' => $name,
                    'amount' => $residualAdd,
                ];
            }
        }
    }
} catch (PDOException $e) {
    error_log("⚠️ Failed to load deal accessories: " . $e->getMessage());
}

// Load dealer theme + MSRP cap settings
$theme = dealerfai_get_theme_palette(null, ['color' => '#0066cc']);
$themeVariant = '';
$leaseCapEnabled = false;
$leaseCapLimit = 0.0;
$capPercentDisplay = null;
if (!empty($orgData)) {
    $themeVariant = $orgData['theme_variant'] ?? '';
    $theme = dealerfai_get_theme_palette($themeVariant, ['logo' => $orgData['logo_url'] ?? '']);
    $leaseCapPercent = $orgData['lease_msrp_cap_percent'] ?? null;
    $financeCapPercent = $orgData['finance_msrp_cap_percent'] ?? null;
    $capPercent = $isLeaseDeal ? $leaseCapPercent : ($isFinanceDeal ? $financeCapPercent : null);
$capPercentDisplay = $capPercent;
    }

    $creditAppLocked = !empty($deal['credit_app_locked']);
$isLeaseDeal = strcasecmp((string)($deal['deal_type'] ?? ''), 'Lease') === 0;
$isFinanceDeal = strcasecmp((string)($deal['deal_type'] ?? ''), 'Finance') === 0;

$isNewVehicle = false;
$vehicleCondition = $deal['vehicle_condition'] ?? 'Used';
$isNewVehicle = strtolower((string)$vehicleCondition) === 'new';

$vehicle_make_name = '';
if (!empty($deal['vehicle_make_id'])) {
    $makeStmt = $db->prepare("SELECT make_name FROM vehicle_makes WHERE id = ?");
    $makeStmt->execute([$deal['vehicle_make_id']]);
    $vehicle_make_name = $makeStmt->fetchColumn() ?: '';
}
if (!$vehicle_make_name && !empty($deal['vehicle_make'])) {
    $vehicle_make_name = $deal['vehicle_make'];
}

$preferredWarrantyProvider = null;
if ($vehicle_make_name) {
    $makeKey = strtolower(trim($vehicle_make_name));
    if ($makeKey === 'jaguar') {
        $preferredWarrantyProvider = 'Jaguar';
    } elseif ($makeKey === 'land rover' || $makeKey === 'landrover') {
        $preferredWarrantyProvider = 'Land Rover';
    }
}

$user_id = $_SESSION['user_id'];
$roles = load_session_roles();
$isAdmin = in_array('Admin', $roles, true);
$adminAlertCount = $isAdmin ? get_admin_alert_count($db) : 0;
$isManager = in_array('General Manager', $roles, true) || in_array('Finance Manager', $roles, true);
$effectiveOrg = get_effective_organization();
$accessibleOrgs = get_accessible_organizations();

$allowedOrg = false;
if ($isAdmin) {
    $allowedOrg = true;
} else {
    if ($effectiveOrg && (string)$deal['organization'] === (string)$effectiveOrg) {
        $allowedOrg = true;
    } else {
        $dealOrgId = (int)$deal['organization'];
        foreach ($accessibleOrgs as $orgId) {
            if ((int)$orgId === $dealOrgId) {
                $allowedOrg = true;
                break;
            }
        }
    }
}

if (!$allowedOrg || (!$isAdmin && !$isManager && (int)$deal['salesperson_id'] !== (int)$user_id)) {
    echo "<p style='color:red;'>You do not have access to this deal.</p>"; exit;
}

$flashSuccess = '';
$flashError = '';
if (!empty($_SESSION['flash_success'])) {
    $flashSuccess = (string)$_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
if (!empty($_SESSION['flash_error'])) {
    $flashError = (string)$_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

$hasCustomerContext = false;
$customerContextRaw = '';
$customerContext = [];
try {
    $hasCustomerContext = function_exists('column_exists') && column_exists($db, 'deals', 'customer_context');
} catch (Throwable $e) {
    $hasCustomerContext = false;
}
if ($hasCustomerContext) {
    $customerContextRaw = trim((string)($deal['customer_context'] ?? ''));
    if ($customerContextRaw !== '') {
        $decoded = json_decode($customerContextRaw, true);
        if (is_array($decoded)) {
            $customerContext = $decoded;
        } else {
            $customerContext = ['freeform' => $customerContextRaw];
        }
    }
}

// Fetch protection selections
$appStmt = $db->prepare("SELECT selected_protections, all_recommendations, usage_data FROM applications WHERE deal_id = ?");
$appStmt->execute([$deal_id]);
$app = $appStmt->fetch(PDO::FETCH_ASSOC);

$selected = $app && $app['selected_protections'] ? json_decode($app['selected_protections'], true) : [];
$recommended = $app && $app['all_recommendations'] ? json_decode($app['all_recommendations'], true) : [];
$usageData = $app ? dealerfai_decode_usage_data($app['usage_data'] ?? null) : [];
if (!is_array($usageData)) { $usageData = []; }
$selected = is_array($selected) ? $selected : [];
$recommended = is_array($recommended) ? $recommended : [];

$codeAliases = [
    'warranty' => 'extWarranty', 'extwarranty' => 'extWarranty',
    'gap' => 'gapProtection', 'gapprotection' => 'gapProtection',
    'cap' => 'assetProtection', 'assetprotection' => 'assetProtection',
    'ceramic' => 'ceramicCoating', 'ceramiccoating' => 'ceramicCoating',
    'interior' => 'interiorProtection', 'interiorprotection' => 'interiorProtection', 'interior_protection' => 'interiorProtection',
    'tire_rim' => 'tireRim', 'tirerim' => 'tireRim',
    'theft' => 'theftProtection', 'film' => 'filmProtection', 'xpel' => 'filmProtection',
    'rust' => 'rustModule', 'windshield' => 'glassProtection', 'windshield_protection' => 'glassProtection',
    'dent' => 'dentDing', 'worryfree' => 'worryFree', 'worryfreemaintenance' => 'worryFree',
    'xpel_standard' => 'xpel_basic', 'xpel_full_wrap' => 'xpel_full_vehicle_wrap',
];

$normalizeCode = function ($code) use ($codeAliases) {
    $raw = trim((string) $code);
    if ($raw === '') return null;
    $key = strtolower($raw);
    return $codeAliases[$key] ?? $raw;
};

$selected = array_values(array_filter(array_map($normalizeCode, $selected)));
$recommended = array_values(array_filter(array_map($normalizeCode, $recommended)));

// XPEL package logic
$hasXpelVariant = false;
foreach ($selected as $code) {
    if (str_starts_with($code, 'xpel_')) {
        $hasXpelVariant = true;
        break;
    }
}
if ($hasXpelVariant) {
    $selected = array_values(array_filter($selected, fn($code) => $code !== 'filmProtection'));
}

$included_raw = function_exists('parse_included_protections') ? parse_included_protections($deal['included_protections'] ?? null) : [];
$included_protections = [];
foreach ($included_raw as $item) {
    if (!is_array($item)) continue;
    $code = $normalizeCode($item['code'] ?? '');
    $name = trim((string)($item['name'] ?? ''));
    $price = isset($item['price']) ? (float)$item['price'] : 0.0;
    if (!$code && $name === '') continue;
    if (!$code) $code = $name;
    $included_protections[] = ['code' => $code, 'name' => $name !== '' ? $name : $code, 'price' => $price];
}

$productMap = [];
try {
    $orgIdForCap = isset($deal['organization']) ? (int)$deal['organization'] : 0;
    $hasLuxuryCol = function_exists('column_exists') ? column_exists($db, 'products', 'contributes_to_luxury_tax') : false;
    $hasGstCol = function_exists('column_exists') ? column_exists($db, 'products', 'gst_taxable') : false;
    $hasPstCol = function_exists('column_exists') ? column_exists($db, 'products', 'pst_taxable') : false;
    $taxFields = ($hasLuxuryCol ? ", p.contributes_to_luxury_tax" : "") . ($hasGstCol ? ", p.gst_taxable" : "") . ($hasPstCol ? ", p.pst_taxable" : "");
    
    $catalogStmt = $db->prepare("
        SELECT p.id, p.code, p.name, p.default_price, p.default_description, p.default_reason, p.provider,
               1=1 {$taxFields},
               COALESCE(o.lease_cap_exempt_override, g.lease_cap_exempt_override, p.lease_cap_exempt) AS lease_cap_exempt,
               COALESCE(o.finance_cap_exempt_override, g.finance_cap_exempt_override, p.finance_cap_exempt) AS finance_cap_exempt
        FROM products p
        LEFT JOIN product_organization_overrides o ON o.product_id = p.id AND o.organization_id = ?
        LEFT JOIN product_organization_overrides g ON g.product_id = p.id AND g.organization_id = 0
    ");
    $catalogStmt->execute([$orgIdForCap]);
    foreach ($catalogStmt as $row) {
        $code = $normalizeCode($row['code'] ?? '');
        if (!$code) continue;
        $productMap[$code] = [
            'id' => (int)($row['id'] ?? 0),
            'code' => $row['code'] ?? $code,
            'name' => $row['name'] ?? $code,
            'price' => (float)($row['default_price'] ?? 0),
            'reason' => $row['default_description'] ?? $row['default_reason'] ?? '',
            'lease_cap_exempt' => !empty($row['lease_cap_exempt']),
            'finance_cap_exempt' => !empty($row['finance_cap_exempt']),
            'contributes_to_luxury_tax' => !empty($row['contributes_to_luxury_tax'] ?? null),
            'gst_taxable' => !array_key_exists('gst_taxable', $row) || !empty($row['gst_taxable']),
            'pst_taxable' => !array_key_exists('pst_taxable', $row) || !empty($row['pst_taxable']),
        ];
    }
} catch (PDOException $e) {}

// Pricing overrides
$vehicle_make_id = isset($deal['vehicle_make_id']) ? (int)$deal['vehicle_make_id'] : null;
$vehicle_model_id = isset($deal['vehicle_model_id']) ? (int)$deal['vehicle_model_id'] : null;
$vehicle_trim_id = isset($deal['vehicle_trim_id']) ? (int)$deal['vehicle_trim_id'] : null;
$deal_term = isset($deal['term']) ? (int)$deal['term'] : 0;
if ($vehicle_make_id && !empty($productMap)) {
    foreach ($productMap as $code => $entry) {
        $defaultTerm = isset($entry['id']) ? resolve_default_term_for_product($db, (int)$entry['id'], (int)$deal['organization']) : null;
        $productMap[$code] = apply_vehicle_pricing_override($db, $entry, $vehicle_make_id, $vehicle_model_id, $vehicle_trim_id, $defaultTerm ?? $deal_term, $deal, (int)$deal['organization']);
    }
}

// Financial calculations
$physical_protection_codes = ['filmProtection','ceramicCoating','interiorProtection','tireRim','rustModule','dentDing','glassProtection','tint'];
$physical_keywords = ['paint', 'xpel', 'ceramic', 'interior', 'body', 'foundation', 'rust', 'dent', 'windshield', 'glass', 'tint', 'tire', 'rim', 'film'];
$isPhysical = function ($code, $name) use ($physical_protection_codes, $physical_keywords) {
    if (in_array($code, $physical_protection_codes, true)) return true;
    $normalized = strtolower((string)$name);
    foreach ($physical_keywords as $k) { if ($normalized !== '' && strpos($normalized, $k) !== false) return true; }
    return false;
};

$included_total = 0; $included_physical_total = 0; $included_gst_taxable_total = 0; $included_pst_taxable_total = 0; $included_luxury_total = 0;
$included_display = [];
foreach ($included_protections as $item) {
    $code = $item['code']; $price = $item['price'];
    $name = $item['name'];
    $gstT = $productMap[$code]['gst_taxable'] ?? true;
    $pstT = $productMap[$code]['pst_taxable'] ?? true;
    $lux = !empty($productMap[$code]['contributes_to_luxury_tax']);
    $included_total += $price;
    if ($isPhysical($code, $name)) $included_physical_total += $price;
    if ($gstT) $included_gst_taxable_total += $price;
    if ($pstT) $included_pst_taxable_total += $price;
    if ($lux) $included_luxury_total += $price;
    $included_display[] = ['code' => $code, 'name' => $name, 'price' => $price, 'is_physical' => $isPhysical($code, $name)];
}

$selected_total = 0; $selected_cap_eligible = 0; $selected_exempt = 0; $selected_physical = 0;
$selected_gst = 0; $selected_pst = 0; $selected_luxury = 0;
foreach ($unique_selected = array_values(array_unique($selected)) as $code) {
    if (!isset($productMap[$code])) continue;
    $price = (float)$productMap[$code]['price'];
    $selected_total += $price;
    if ($productMap[$code]['gst_taxable'] ?? true) $selected_gst += $price;
    if ($productMap[$code]['pst_taxable'] ?? true) $selected_pst += $price;
    if (!empty($productMap[$code]['contributes_to_luxury_tax'])) $selected_luxury += $price;
    if ($isLeaseDeal && !empty($productMap[$code]['lease_cap_exempt'])) $selected_exempt += $price;
    elseif ($isFinanceDeal && !empty($productMap[$code]['finance_cap_exempt'])) $selected_exempt += $price;
    else $selected_cap_eligible += $price;
    if ($isPhysical($code, $productMap[$code]['name'])) $selected_physical += $price;
}

$protections_total = $selected_total + $included_total;
$protections_cap_eligible_total = $included_total + $selected_cap_eligible;
$protections_gst_taxable_total = $included_gst_taxable_total + $selected_gst;
$protections_pst_taxable_total = $included_pst_taxable_total + $selected_pst;
$protections_luxury_total = $included_luxury_total + $selected_luxury;

$selected_accessories = []; $accessories_total = 0; $accessories_upfront = 0; $accessories_cap_cost = 0; $accessories_finance = 0;
$accessories_luxury = 0; $accessories_residual_msrp_add = 0; $accessory_residual_adjustments = [];
try {
    $accStmt = $db->prepare("SELECT * FROM deal_accessories WHERE deal_id = ?");
    $accStmt->execute([$deal_id]);
    while ($row = $accStmt->fetch(PDO::FETCH_ASSOC)) {
        $price = (float)($row['sale_price'] ?? 0);
        $name = $row['accessory_name'] ?? $row['accessory_code'] ?? 'Accessory';
        $payMethod = $row['pay_method'] ?? '';
        $entry = ['name' => $name, 'price' => $price, 'pay_method' => $payMethod];
        if (empty($row['included'])) $selected_accessories[] = $entry;
        $accessories_total += $price;
        if ($payMethod === 'upfront') $accessories_upfront += $price;
        elseif ($payMethod === 'cap_cost') $accessories_cap_cost += $price;
        else $accessories_finance += $price;
        $accessories_luxury += $price;
        if (!empty($row['residualizable']) && $payMethod === 'cap_cost') {
            $add = (float)($row['residual_msrp_add'] ?? 0);
            if ($add > 0) {
                $accessories_residual_msrp_add += $add;
                $accessory_residual_adjustments[] = ['name' => $name, 'amount' => $add];
            }
        }
    }
} catch (PDOException $e) {}

$province = $deal['province'] ?? 'ON';
$taxRates = [
    'MB' => ['gst' => 0.05, 'pst' => 0.07], 'SK' => ['gst' => 0.05, 'pst' => 0.06], 'ON' => ['hst' => 0.13],
    'QC' => ['gst' => 0.05, 'pst' => 0.09975], 'NS' => ['hst' => 0.15], 'NB' => ['hst' => 0.15],
    'PE' => ['hst' => 0.15], 'NL' => ['hst' => 0.15], 'BC' => ['gst' => 0.05, 'pst' => 0.07],
    'AB' => ['gst' => 0.05], 'NT' => ['gst' => 0.05], 'NU' => ['gst' => 0.05], 'YT' => ['gst' => 0.05],
];
$tax = $taxRates[$province] ?? ['hst' => 0.13];
$gst = $tax['gst'] ?? 0; $pst = $tax['pst'] ?? 0; $hst = $tax['hst'] ?? 0;
$doc_fee = (float)($deal['documentation_fee'] ?? 0);
$ppsa_fee = (float)($deal['ppsa_fee'] ?? 0);
$ppsa_total = ($deal['deal_type'] !== 'Cash') ? $ppsa_fee : 0;

$gst_base = max(0, $deal['sale_price'] + $doc_fee + $accessories_total + $protections_gst_taxable_total - $deal['trade_value']);
$pst_base = max(0, $deal['sale_price'] + $doc_fee + $accessories_total + $protections_pst_taxable_total - $deal['trade_value']);
$hst_base = max(0, $deal['sale_price'] + $doc_fee + $accessories_total + $protections_gst_taxable_total - $deal['trade_value']);

$flt_base = $deal['sale_price'] + $accessories_luxury + $protections_luxury_total;
$flt_amt = 0;
if ($isNewVehicle) {
    if ($flt_base > 100000 && $flt_base < 200000) $flt_amt = ($flt_base - 100000) * 0.20;
    elseif ($flt_base >= 200000) $flt_amt = $flt_base * 0.10;
}
if ($deal['flt_override_amount'] !== null) $flt_amt = (float)$deal['flt_override_amount'];

$gst_amt = ($gst_base + $flt_amt) * $gst;
$pst_amt = ($pst_base + $flt_amt) * $pst;
$hst_amt = ($hst_base + $flt_amt) * $hst;
$tax_total = $gst_amt + $pst_amt + $hst_amt + $flt_amt;

$subtotal_before_taxes = $deal['sale_price'] + $doc_fee + $protections_total + $accessories_total;
$taxable_subtotal = max(0, $subtotal_before_taxes - $deal['trade_value']);
$total_with_taxes = $taxable_subtotal + $tax_total + $ppsa_total;
$total_to_finance = $total_with_taxes - $deal['down_payment'] + $deal['lien_amount'] - $accessories_upfront;

$payment = 0;
$finance_cap_excess = 0;
if ($deal['deal_type'] === 'Lease') {
    $cap_cost = max(0, $deal['sale_price'] + $doc_fee + $protections_cap_eligible_total + $accessories_cap_cost + $ppsa_total - $deal['down_payment'] - $deal['trade_value'] + $deal['lien_amount']);
    if ($leaseCapEnabled && $leaseCapLimit > 0 && $cap_cost > $leaseCapLimit) {
        $cap_cost_excess = $cap_cost - $leaseCapLimit; $cap_cost = $leaseCapLimit;
    }
    $cap_cost += $selected_exempt;
    $residual_value = (float)($deal['residual'] ?? 0);
    if ($accessories_residual_msrp_add > 0 && !empty($deal['msrp'])) {
        $residual_value += $accessories_residual_msrp_add * ((float)$deal['residual'] / (float)$deal['msrp']);
    }
    $payment = calculate_lease_payment($cap_cost, $residual_value, $deal['term'], $deal['interest_rate'], $gst + $pst + $hst);
} elseif ($deal['deal_type'] !== 'Cash') {
    $fin_amt = $total_to_finance;
    if ($leaseCapEnabled && $leaseCapLimit > 0) {
        $fin_base = max(0, $deal['sale_price'] + $doc_fee + $protections_cap_eligible_total + $accessories_finance + $ppsa_total - $deal['down_payment'] - $deal['trade_value'] + $deal['lien_amount']);
        if ($fin_base > $leaseCapLimit) {
            $finance_cap_excess = $fin_base - $leaseCapLimit;
            $fin_amt = max(0, $total_to_finance - $finance_cap_excess);
        }
    }
    $payment = calculate_payment($fin_amt, $deal['interest_rate'], $deal['term']);
}

$payment_frequency = $deal['payment_frequency'] ?? 'Monthly';
$freq_ratio = match($payment_frequency) { 'Semi-Monthly' => 12/24, 'Bi-Weekly' => 12/26, 'Weekly' => 12/52, default => 1.0 };
$payment_display = number_format($payment * $freq_ratio, 2);

// Application Status
$app_check = $db->prepare("SELECT started_at, submitted_at FROM applications WHERE deal_id = ?");
$app_check->execute([$deal_id]);
$app_data = $app_check->fetch(PDO::FETCH_ASSOC);
$status = "Not Started";
if ($app_data) {
    if ($app_data['submitted_at']) $status = "✅ Submitted";
    elseif ($app_data['started_at']) $status = "🕓 In Progress";
}
$lockStatus = $creditAppLocked ? "🔒 Locked" : "Unlocked";

// UI Helpers
$customerType = strtolower(trim((string)($deal['customer_type'] ?? 'personal')));
$customerTypeLabel = match($customerType) { 'professional' => 'Professional', 'commercial' => 'Commercial', default => 'Personal' };
$businessName = '';
if ($bizId = (int)($deal['business_id'] ?? 0)) {
    $bizStmt = $db->prepare("SELECT name FROM businesses WHERE id = ?"); $bizStmt->execute([$bizId]); $businessName = $bizStmt->fetchColumn() ?: '';
}

$vehicleSummary = trim(($deal['vehicle_year'] ?? '') . ' ' . $vehicle_make_name . ' ' . ($deal['vehicle_model'] ?? ''));
$vehicleKms = $deal['vehicle_kms'] ?? null;
$vin = $deal['vin'] ?? '';

// Audit Log
$audit_stmt = $db->prepare("SELECT pal.*, u.full_name AS user_name FROM protection_audit_log pal LEFT JOIN users u ON pal.user_id = u.id WHERE pal.deal_id = ? ORDER BY pal.submitted_at DESC");
$audit_stmt->execute([$deal_id]); $audit_entries = $audit_stmt->fetchAll(PDO::FETCH_ASSOC);

// Links
function generate_short_code(int $l = 8) { $c = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789'; $s = ''; for($i=0;$i<$l;$i++) $s .= $c[random_int(0, strlen($c)-1)]; return $s; }
$creditLinkCode = null;
try {
    $linkStmt = $db->prepare("SELECT code FROM credit_app_links WHERE deal_id = ? ORDER BY id DESC LIMIT 1");
    $linkStmt->execute([$deal_id]); $creditLinkCode = $linkStmt->fetchColumn() ?: null;
    if (!$creditLinkCode) {
        $ins = $db->prepare("INSERT INTO credit_app_links (deal_id, code) VALUES (?, ?)");
        for($a=0;$a<5 && !$creditLinkCode;$a++) { $candidate = generate_short_code(); try { $ins->execute([$deal_id, $candidate]); $creditLinkCode = $candidate; } catch(PDOException $e) {} }
    }
} catch(PDOException $e) {}
$baseUrl = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '');
$creditLink = $baseUrl && ($tok = $deal['secure_token'] ?? '') ? "$baseUrl/credit_app_landing.php?token=" . urlencode($tok) : '';
$creditShortLink = $baseUrl && $creditLinkCode ? "$baseUrl/credit_app_landing.php?code=" . urlencode($creditLinkCode) : '';

$launchDisabled = $creditAppLocked ? 'disabled' : '';
$launchLabel = $creditAppLocked ? 'Credit Application Locked' : ($deal['deal_type'] === 'Cash' ? 'Launch Cash Application' : 'Launch Credit Application');
$emailDisabled = ($creditAppLocked || empty($deal['customer_email'])) ? 'disabled' : '';
$canEditDeal = ($isAdmin || (int)$deal['salesperson_id'] === (int)$user_id) && !$creditAppLocked;
$canDeleteDeal = $isAdmin;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php include __DIR__ . "/includes/head_favicon.php"; ?>
<meta charset="UTF-8">
  <title>DealerFAI - View Deal</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php dealerfai_theme_head($theme ?? null); ?>
  <style nonce="<?= dealerfai_csp_nonce() ?>">
    /* Essential layout overrides */
    .finance-only { display: none; }
    .residual-field { display: none; }
    .trade-field { display: none; }
    .badge { display: inline-block; min-width: 18px; padding: 2px 8px; border-radius: 999px; background: #d7263d; color: #fff; font-size: 12px; font-weight: bold; text-align: center; margin-left: 6px; }
    .payment-updated { font-size: 14px; color: green; margin-top: 5px; margin-left: 10px; display: none; }
    .alert { max-width: 900px; margin: 16px auto; padding: 12px 14px; border-radius: 8px; border: 1px solid transparent; font-weight: 600; }
    .alert-success { color: #0f5132; background: #d1e7dd; border-color: #badbcc; }
    .alert-error { color: #842029; background: #f8d7da; border-color: #f5c2c7; }
  </style>
  <script nonce="<?= dealerfai_csp_nonce() ?>">
    const isNewVehicle = <?= json_encode($isNewVehicle) ?>;
    const leaseCapEnabled = <?= json_encode($leaseCapEnabled && $leaseCapLimit > 0) ?>;
    const leaseCapLimit = <?= json_encode($leaseCapLimit) ?>;

    function updateFields() {
      const dealTypeEl = document.getElementById('deal_type');
      const type = dealTypeEl ? dealTypeEl.value : 'Cash';
      document.querySelectorAll('.finance-only').forEach(el => el.style.display = (type === 'Cash') ? 'none' : 'block');
      const residual = document.querySelector('.residual-field');
      if (residual) {
        residual.style.display = (type === 'Lease') ? 'block' : 'none';
      }
      const customTax = document.getElementById('custom_tax_rate');
      const overrideTax = document.getElementById('override_tax');
      if (customTax && overrideTax) {
        customTax.disabled = !overrideTax.checked;
      }
      calculatePayment();
      const paymentDiv = document.getElementById('payment_display');
      if (paymentDiv) {
          paymentDiv.parentElement.style.display = (type === 'Cash') ? 'none' : 'block';
      }
    }
function calculate_payment($total_to_finance, $interest_rate, $term) {
    // Convert annual interest rate to monthly interest rate
    $monthly_interest_rate = ($interest_rate / 100) / 12;

    // Calculate the number of payments (loan term in months)
    $num_payments = $term;

    // Apply the amortization formula to calculate the payment
    if ($monthly_interest_rate > 0) {
        $payment = ($total_to_finance * $monthly_interest_rate) / (1 - pow(1 + $monthly_interest_rate, -$num_payments));
    } else {
        // If there's no interest, simply divide the total amount to finance by the number of payments
        $payment = $total_to_finance / $num_payments;
    }

    // Round the result to two decimal places
    return round($payment, 2);
}

        // Protections totals from selected hidden inputs
        let protectionsTotal = 0;
        let protectionsCapEligibleTotal = 0;
        let protectionsExemptTotal = 0;
        document.querySelectorAll('.protection-price').forEach(p => {
            const price = parseFloat(p.value) || 0;
            protectionsTotal += price;
            const leaseExempt = p.dataset.leaseCapExempt === '1';
            const financeExempt = p.dataset.financeCapExempt === '1';
            if (dealType === 'Lease' && leaseExempt) {
                protectionsExemptTotal += price;
            } else if (dealType === 'Finance' && financeExempt) {
                protectionsExemptTotal += price;
            } else {
                protectionsCapEligibleTotal += price;
            }
        });
        let physicalProtectionsTotal = 0;
        document.querySelectorAll('.physical-protection-price').forEach(p => {
            physicalProtectionsTotal += parseFloat(p.value) || 0;
        });
        let accessoriesTotal = 0;
        document.querySelectorAll('.accessory-price').forEach(p => {
            accessoriesTotal += parseFloat(p.value) || 0;
        });
        let accessoriesUpfrontTotal = 0;
        document.querySelectorAll('.accessory-upfront-price').forEach(p => {
            accessoriesUpfrontTotal += parseFloat(p.value) || 0;
        });
        let accessoriesCapCostTotal = 0;
        document.querySelectorAll('.accessory-cap_cost-price').forEach(p => {
            accessoriesCapCostTotal += parseFloat(p.value) || 0;
        });
        let accessoriesFinanceTotal = 0;
        document.querySelectorAll('.accessory-finance-price').forEach(p => {
            accessoriesFinanceTotal += parseFloat(p.value) || 0;
        });
        let accessoriesLuxuryTotal = 0;
        document.querySelectorAll('.accessory-luxury-price').forEach(p => {
            accessoriesLuxuryTotal += parseFloat(p.value) || 0;
        });
        let residualAdjustments = 0;
        const residualAdjustmentNames = [];
        document.querySelectorAll('.accessory-cap_cost-price[data-residual-add]').forEach(p => {
            const add = parseFloat(p.dataset.residualAdd || '0') || 0;
            if (add > 0) {
                residualAdjustments += add;
                const name = p.dataset.name || '';
                if (name) {
                    residualAdjustmentNames.push(name);
                }
            }
        });
        const documentationInput = document.getElementById('documentation_fee');
        const ppsaInput = document.getElementById('ppsa_fee');
        const dealTypeInput = document.getElementById('deal_type');
        const documentation = documentationInput && documentationInput.value !== '' ? parseFloat(documentationInput.value) : 0;
        const ppsa = ppsaInput && ppsaInput.value !== '' ? parseFloat(ppsaInput.value) : 0;
        const dealType = dealTypeInput && dealTypeInput.value ? dealTypeInput.value : 'Cash';
        const residualInput = document.getElementById('residual');
        const leaseCapNote = document.getElementById('lease-cap-note');

        // Tax rates for provinces
        const taxRates = { ON: 0.13, QC: 0.14975, NS: 0.15, NB: 0.15, MB: 0.12, BC: 0.12, PE: 0.15, SK: 0.11, AB: 0.05, NL: 0.15, NT: 0.05, YT: 0.05, NU: 0.05 };
        const taxRate = override ? customRate / 100 : (taxRates[province] || 0.13);

        // Calculate subtotal before taxes (sale price + documentation + protection products + accessories)
        const subtotalBeforeTaxes = sale + documentation + protectionsTotal + accessoriesTotal;
        const taxableSubtotal = Math.max(0, subtotalBeforeTaxes - trade);

        const fltOverrideInput = document.getElementById('flt_override_amount');
        const fltOverrideValue = fltOverrideInput && fltOverrideInput.value !== '' ? parseFloat(fltOverrideInput.value) : null;
        const fltBase = sale + accessoriesLuxuryTotal;
        let fltAmt = 0;
        if (fltOverrideValue !== null && !isNaN(fltOverrideValue)) {
            fltAmt = fltOverrideValue;
        } else if (isNewVehicle) {
            if (fltBase > 100000 && fltBase < 200000) {
                fltAmt = (fltBase - 100000) * 0.20;
            } else if (fltBase >= 200000) {
                fltAmt = fltBase * 0.10;
            }
        }
        // GST/PST are charged on FLT when FLT applies.
        const gstAmt = (taxableSubtotal + fltAmt) * (taxRate / 2); // Assuming 50% split for GST/PST in this simplified UI calc
        const pstAmt = (taxableSubtotal + fltAmt) * (taxRate / 2); // Assuming 50% split for GST/PST in this simplified UI calc
        const totalWithTaxes = taxableSubtotal + gstAmt + pstAmt + fltAmt + (dealType === 'Cash' ? 0 : ppsa);

        // Calculate total to finance (trade already applied before taxes)
        const totalToFinance = totalWithTaxes - down + lien - accessoriesUpfrontTotal;

        // Calculate monthly payment
        let payment = 0;
        if (term > 0) {
            if (dealType === 'Lease') {
                const residualInput = document.getElementById('residual');
                const residualValueBase = residualInput && residualInput.value !== '' ? parseFloat(residualInput.value) : 0;
                const msrpInput = document.getElementById('msrp');
                const msrpValue = msrpInput && msrpInput.value !== '' ? parseFloat(msrpInput.value) : 0;
                let residualValue = residualValueBase;
                if (msrpValue > 0 && residualAdjustments > 0) {
                    const residualPercent = residualValueBase / msrpValue;
                    residualValue += residualAdjustments * residualPercent;
                }
                let capCost = Math.max(0, sale + documentation + protectionsCapEligibleTotal + accessoriesCapCostTotal + ppsa - down - trade + lien);
                const capCostRaw = capCost;
                if (leaseCapEnabled && leaseCapLimit > 0 && capCost > leaseCapLimit) {
                    capCost = leaseCapLimit;
                    if (leaseCapNote) {
                        const excess = capCostRaw - leaseCapLimit;
                        leaseCapNote.textContent = `Lease cap cost limit reached. $${excess.toFixed(2)} moved to cash down.`;
                        leaseCapNote.style.display = '';
                    }
                } else if (leaseCapNote) {
                    leaseCapNote.textContent = '';
                    leaseCapNote.style.display = 'none';
                }
                capCost += protectionsExemptTotal;
                const moneyFactor = (rate / 100) / 24;
                const depreciation = (capCost - residualValue) / term;
                const financeCharge = (capCost + residualValue) * moneyFactor;
                const basePayment = depreciation + financeCharge;
                payment = basePayment * (1 + taxRate);
            } else {
                const monthlyInterestRate = (rate / 100) / 12;
                let financeAmount = totalToFinance;
                if (leaseCapEnabled && leaseCapLimit > 0) {
                    let financeBase = Math.max(0, sale + documentation + protectionsCapEligibleTotal + accessoriesFinanceTotal + ppsa - down - trade + lien);
                    if (financeBase > leaseCapLimit) {
                        const excess = financeBase - leaseCapLimit;
                        financeAmount = Math.max(0, totalToFinance - excess);
                        if (leaseCapNote) {
                            leaseCapNote.textContent = `Finance cap limit reached. $${excess.toFixed(2)} moved to cash down.`;
                            leaseCapNote.style.display = '';
                        }
                    } else if (leaseCapNote) {
                        leaseCapNote.textContent = '';
                        leaseCapNote.style.display = 'none';
                    }
                }
                if (monthlyInterestRate > 0) {
                    payment = (financeAmount * monthlyInterestRate) / (1 - Math.pow(1 + monthlyInterestRate, -term));
                } else {
                    payment = financeAmount / term;
                }
            }
        }

        // Convert monthly payment to selected frequency
        let frequencyRatio = 1.0;
        if (freq === 'Semi-Monthly') {
            frequencyRatio = 12 / 24;
        } else if (freq === 'Bi-Weekly') {
            frequencyRatio = 12 / 26;
        } else if (freq === 'Weekly') {
            frequencyRatio = 12 / 52;
        }
        payment *= frequencyRatio;

        // Display frequency label (only show "per month", "bi-weekly", or "per week" ONCE)
        let freqLabel = '';
        if (freq === 'Monthly') freqLabel = 'per month';
        else if (freq === 'Semi-Monthly') freqLabel = 'semi-monthly';
        else if (freq === 'Bi-Weekly') freqLabel = 'bi-weekly';
        else if (freq === 'Weekly') freqLabel = 'per week';
        else freqLabel = 'per month';

        const paymentDisplay = document.getElementById('payment_display');
        const updatedBadge = document.getElementById('payment_updated');

        if (paymentDisplay) {
            if (!isNaN(payment)) {
                paymentDisplay.innerText = "$" + payment.toFixed(2);
                paymentDisplay.style.display = '';
                paymentDisplay.classList.add('payment-highlight');

                // Display the frequency label once
                let freqText = document.querySelector("#payment_display + .payment-frequency-label");
                if (!freqText) {
                    freqText = document.createElement("span");
                    freqText.className = "payment-frequency-label";
                    paymentDisplay.after(freqText);
                }
                freqText.textContent = " " + freqLabel;

                if (updatedBadge) {
                    updatedBadge.style.display = 'inline';
                    setTimeout(() => {
                        updatedBadge.style.display = 'none';
                    }, 5000);
                }
            }
        }

        const residualBaseDisplay = document.getElementById('residual_base_display');
        const residualAdjustedDisplay = document.getElementById('residual_adjusted_display');
        const residualNote = document.getElementById('residual_adjustment_note');
        if (residualAdjustedDisplay && residualBaseDisplay) {
            const baseResidual = parseFloat(residualInput && residualInput.value !== '' ? residualInput.value : '0') || 0;
            const msrpValue = parseFloat(document.getElementById('msrp')?.value || '0') || 0;
            if (msrpValue > 0 && residualAdjustments > 0) {
                const residualPercent = baseResidual / msrpValue;
                const adjustmentValue = residualAdjustments * residualPercent;
                residualAdjustedDisplay.textContent = \"$\" + (baseResidual + adjustmentValue).toFixed(2);
                if (residualNote) {
                    const list = residualAdjustmentNames.length ? residualAdjustmentNames.join(', ') : 'Accessories';
                    residualNote.textContent = `Includes $${adjustmentValue.toFixed(2)} residual adjustment — ${list}`;
                    residualNote.style.display = '';
                }
            } else {
                if (residualNote) {
                    residualNote.style.display = 'none';
                }
            }
        }
    }
    function toggleTax() { const cb = document.getElementById('override_tax'); if (cb) { cb.checked = !cb.checked; } updateFields(); }
    window.addEventListener('load', updateFields);
  </script>
  <style nonce="<?= dealerfai_csp_nonce() ?>">
    /* Specific page overrides */
    .detail-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 1.5rem; margin-bottom: 1.5rem; }
    .status-pill { display: inline-flex; align-items: center; padding: 0.25rem 0.75rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 700; background: #f1f5f9; color: #475569; }
    .status-pill.success { background: #ecfdf5; color: #059669; }
    .status-pill.warning { background: #fffbeb; color: #d97706; }
    .payment-highlight { font-size: 2rem; font-weight: 800; color: var(--brand-color); display: block; margin-bottom: 0.25rem; }
    .financial-row { display: flex; justify-content: space-between; padding: 0.75rem 0; border-bottom: 1px solid #f1f5f9; font-size: 0.875rem; }
    .financial-row:last-child { border-bottom: none; }
    .financial-label { color: #64748b; font-weight: 500; }
    .financial-value { color: #1e293b; font-weight: 700; }
    .section-header { display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1.25rem; color: #0f172a; border-bottom: 1px solid #f1f5f9; padding-bottom: 0.75rem; }
    .section-header i { color: var(--brand-color); }
    .audit-list { list-style: none; padding: 0; margin: 0; }
    .audit-item { padding: 1rem; border-left: 2px solid #e2e8f0; margin-left: 0.5rem; position: relative; }
    .audit-item::before { content: ''; position: absolute; left: -5px; top: 1.25rem; width: 8px; height: 8px; border-radius: 50%; background: #cbd5e1; }
    dialog#deliver-dialog { border: none; border-radius: var(--radius-lg); padding: 0; width: 400px; box-shadow: var(--shadow-xl); overflow: hidden; }
    dialog#deliver-dialog::backdrop { background: rgba(15, 23, 42, 0.5); backdrop-filter: blur(4px); }
  </style>
</head>
<body class="dashboard-wrapper">
  <!-- Sidebar -->
  <aside class="sidebar">
    <div class="sidebar-header" style="padding: 1.5rem;">
      <img src="dealerfai_logo_white.png" alt="DealerFAI" style="height: 35px; width: auto;">
    </div>
    <nav class="sidebar-nav" style="background: transparent; padding: 1.5rem 1rem;">
      <a href="dashboard" class="sidebar-link">
        <i class="fa-solid fa-gauge"></i> Dashboard
      </a>
      <a href="view_deals" class="sidebar-link active">
        <i class="fa-solid fa-file-invoice-dollar"></i> View Deals
      </a>
      <a href="create_deal" class="sidebar-link">
        <i class="fa-solid fa-plus-circle"></i> Create Deal
      </a>
      
      <?php if ($isAdmin): ?>
        <div style="margin-top: 2rem; padding: 0 1rem; font-size: 0.75rem; color: rgba(255,255,255,0.4); text-transform: uppercase; letter-spacing: 0.05em; font-weight: 700;">Admin</div>
        <a href="admin_scoring_log" class="sidebar-link">
          <i class="fa-solid fa-list-check"></i> Scoring Log
        </a>
        <a href="manage_users" class="sidebar-link">
          <i class="fa-solid fa-users"></i> Users
        </a>
        <a href="admin_organizations" class="sidebar-link">
          <i class="fa-solid fa-building"></i> Organizations
        </a>
        <a href="admin_tools" class="sidebar-link">
          <i class="fa-solid fa-wrench"></i> Admin Tools
        </a>
      <?php endif; ?>
    </nav>
    <div style="padding: 1.5rem; border-top: 1px solid rgba(255,255,255,0.1);">
      <p style="font-size: 0.75rem; color: rgba(255,255,255,0.4); margin: 0;">DealerFAI v2.0</p>
    </div>
  </aside>

  <!-- Main Content -->
  <div class="main-container">
    <header class="top-bar">
      <div class="breadcrumb" style="font-weight: 600; color: #64748b;">
        DealerFAI <i class="fa-solid fa-chevron-right" style="font-size: 0.75rem; margin: 0 0.5rem; opacity: 0.5;"></i> <a href="view_deals" style="text-decoration: none; color: inherit;">View Deals</a> <i class="fa-solid fa-chevron-right" style="font-size: 0.75rem; margin: 0 0.5rem; opacity: 0.5;"></i> Deal #<?= htmlspecialchars((string)($deal['deal_number'] ?? '')) ?>
      </div>
      <div style="display: flex; align-items: center; gap: 1.5rem;">
        <?php if ($isAdmin): ?>
          <a href="admin_error_alerts" style="position: relative; color: #64748b;">
            <i class="fa-solid fa-bell" style="font-size: 1.25rem;"></i>
            <?php if ($adminAlertCount > 0): ?>
              <span style="position: absolute; top: -5px; right: -5px; background: #ef4444; color: white; font-size: 10px; padding: 2px 5px; border-radius: 10px; font-weight: 700;"><?= $adminAlertCount ?></span>
            <?php endif; ?>
          </a>
        <?php endif; ?>
        <div style="display: flex; align-items: center; gap: 0.75rem;">
          <div style="text-align: right;">
            <div style="font-size: 0.875rem; font-weight: 700; color: #0f172a;"><?= htmlspecialchars($_SESSION['full_name'] ?? 'User') ?></div>
            <a href="logout" style="font-size: 0.75rem; color: #64748b; text-decoration: none;">Log Out</a>
          </div>
          <div style="width: 40px; height: 40px; background: var(--brand-color); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700;">
            <?= strtoupper(substr($_SESSION['full_name'] ?? 'U', 0, 1)) ?>
          </div>
        </div>
      </div>
    </header>

    <main class="page-content">
      <?php if ($flashSuccess !== ''): ?>
        <div class="alert alert-success" style="margin-bottom: 1.5rem;"><?= htmlspecialchars($flashSuccess) ?></div>
      <?php endif; ?>
      <?php if ($flashError !== ''): ?>
        <div class="alert alert-error" style="margin-bottom: 1.5rem;"><?= htmlspecialchars($flashError) ?></div>
      <?php endif; ?>

      <div style="margin-bottom: 2rem; display: flex; justify-content: space-between; align-items: flex-end;">
        <div>
          <h1 class="text-gradient" style="margin-bottom: 0.5rem; display: inline-block;">Deal Review</h1>
          <p class="text-muted">Viewing details for deal <strong>#<?= htmlspecialchars((string)($deal['deal_number'] ?? '')) ?></strong> (<?= htmlspecialchars($deal['customer_name'] ?? '') ?>)</p>
        </div>
        <div style="display: flex; gap: 0.75rem;">
          <?php if ($canEditDeal): ?>
            <a href="edit_deal?id=<?= urlencode($deal_id) ?>" class="btn btn-secondary"><i class="fa-solid fa-pen-to-square" style="margin-right: 0.5rem;"></i> Edit Deal</a>
          <?php endif; ?>
          <button type="button" class="btn btn-primary" id="launch_credit_button" <?= $launchDisabled ?>><i class="fa-solid fa-rocket" style="margin-right: 0.5rem;"></i> <?= $launchLabel ?></button>
        </div>
      </div>

      <div class="detail-grid">
        <!-- Customer & Vehicle Info -->
        <div style="display: flex; flex-direction: column; gap: 1.5rem;">
          <div class="card">
            <div class="section-header">
              <i class="fa-solid fa-user"></i>
              <h3 style="margin: 0; font-size: 1rem; font-weight: 800;">Customer Information</h3>
            </div>
            <div class="financial-row">
              <span class="financial-label">Full Name</span>
              <span class="financial-value"><?= htmlspecialchars((string)($deal['customer_name'] ?? '')) ?></span>
            </div>
            <div class="financial-row">
              <span class="financial-label">Email</span>
              <span class="financial-value"><?= htmlspecialchars((string)($deal['customer_email'] ?? '')) ?></span>
            </div>
            <div class="financial-row">
              <span class="financial-label">Phone</span>
              <span class="financial-value"><?= htmlspecialchars((string)($deal['customer_phone'] ?? '')) ?></span>
            </div>
            <div class="financial-row">
              <span class="financial-label">Customer Type</span>
              <span class="financial-value"><?= htmlspecialchars($customerTypeLabel) ?></span>
            </div>
            <?php if ($customerType !== 'personal'): ?>
              <div class="financial-row">
                <span class="financial-label">Business</span>
                <span class="financial-value"><?= htmlspecialchars($businessName !== '' ? $businessName : ('#' . (string)$businessId)) ?></span>
              </div>
            <?php endif; ?>
          </div>

          <div class="card">
            <div class="section-header">
              <i class="fa-solid fa-car"></i>
              <h3 style="margin: 0; font-size: 1rem; font-weight: 800;">Vehicle Information</h3>
            </div>
            <div class="financial-row">
              <span class="financial-label">Year / Make / Model</span>
              <span class="financial-value"><?= htmlspecialchars($vehicleSummary ?: '—') ?></span>
            </div>
            <div class="financial-row">
              <span class="financial-label">Condition</span>
              <span class="financial-value"><?= htmlspecialchars($vehicleCondition ?: '—') ?></span>
            </div>
            <div class="financial-row">
              <span class="financial-label">Kilometres</span>
              <span class="financial-value"><?= $vehicleKms !== null ? number_format($vehicleKms) . ' km' : '—' ?></span>
            </div>
            <div class="financial-row">
              <span class="financial-label">VIN</span>
              <span class="financial-value" style="font-family: monospace; font-size: 0.75rem;"><?= htmlspecialchars($vin ?: '—') ?></span>
            </div>
          </div>
        </div>

        <!-- Financial Summary -->
        <div style="display: flex; flex-direction: column; gap: 1.5rem;">
          <div class="card" style="background: #f8fafc; border: 1px solid var(--brand-color);">
            <div class="section-header">
              <i class="fa-solid fa-money-bill-trend-up"></i>
              <h3 style="margin: 0; font-size: 1rem; font-weight: 800;">Financial Summary</h3>
            </div>
            <div style="text-align: center; padding: 1rem 0; border-bottom: 1px solid #e2e8f0; margin-bottom: 1rem;">
              <span class="financial-label" style="text-transform: uppercase; letter-spacing: 0.05em; font-size: 0.75rem; font-weight: 700;">Estimated Payment (<?= htmlspecialchars($payment_frequency) ?>)</span>
              <span id="payment_display" class="payment-highlight"><?= $deal['deal_type'] !== 'Cash' ? ('$' . $payment_display) : '$' . number_format($total_with_taxes, 2) ?></span>
              <span class="status-pill success"><?= htmlspecialchars($deal['deal_type']) ?></span>
            </div>
            <div class="financial-row">
              <span class="financial-label">Sale Price</span>
              <span class="financial-value">$<?= number_format($deal['sale_price'], 2) ?></span>
            </div>
            <?php if ($deal['trade_value'] > 0): ?>
              <div class="financial-row">
                <span class="financial-label">Trade-In Allowance</span>
                <span class="financial-value" style="color: #ef4444;">- $<?= number_format($deal['trade_value'], 2) ?></span>
              </div>
            <?php endif; ?>
            <div class="financial-row">
              <span class="financial-label">Protection Products</span>
              <span class="financial-value">$<?= number_format($protections_total, 2) ?></span>
            </div>
            <div class="financial-row">
              <span class="financial-label">Accessories</span>
              <span class="financial-value">$<?= number_format($accessories_total, 2) ?></span>
            </div>
            <div class="financial-row" style="margin-top: 0.5rem; padding-top: 1rem; border-top: 2px solid #e2e8f0;">
              <span class="financial-label" style="font-weight: 800; color: #0f172a;">Total with Taxes</span>
              <span class="financial-value" style="font-size: 1rem;">$<?= number_format($total_with_taxes, 2) ?></span>
            </div>
          </div>

          <div class="card">
            <div class="section-header">
              <i class="fa-solid fa-shield-halved"></i>
              <h3 style="margin: 0; font-size: 1rem; font-weight: 800;">Application Status</h3>
            </div>
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
              <span class="financial-label">Submission Status</span>
              <span class="status-pill <?= $app_data && $app_data['submitted_at'] ? 'success' : 'warning' ?>"><?= $status ?></span>
            </div>
            <div style="display: flex; justify-content: space-between; align-items: center;">
              <span class="financial-label">Lock Status</span>
              <span class="status-pill"><?= $lockStatus ?></span>
            </div>
            <div style="margin-top: 1.5rem; display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
              <button type="button" class="btn btn-secondary" style="font-size: 0.75rem;" id="email_customer_button" <?= $emailDisabled ?>><i class="fa-solid fa-envelope" style="margin-right: 0.5rem;"></i> Email Invite</button>
              <button type="button" class="btn btn-secondary" style="font-size: 0.75rem;" id="copy_credit_link"><i class="fa-solid fa-copy" style="margin-right: 0.5rem;"></i> Copy Link</button>
            </div>
          </div>
        </div>
      </div>

      <div class="detail-grid" style="grid-template-columns: 1fr 1fr;">
        <!-- Protection & Accessories List -->
        <div class="card">
          <div class="section-header">
            <i class="fa-solid fa-list-check"></i>
            <h3 style="margin: 0; font-size: 1rem; font-weight: 800;">Selected Items</h3>
          </div>
          <?php if (empty($unique_selected) && empty($selected_accessories) && empty($included_display)): ?>
            <p class="text-muted" style="text-align: center; padding: 2rem 0;">No protection products or accessories selected.</p>
          <?php else: ?>
            <?php foreach ($included_display as $item): ?>
              <div class="financial-row">
                <span class="financial-label"><i class="fa-solid fa-circle-check" style="color: #22c55e; margin-right: 0.5rem;"></i> <?= htmlspecialchars($item['name']) ?> <small>(Included)</small></span>
                <span class="financial-value">$<?= number_format((float)$item['price'], 2) ?></span>
              </div>
            <?php endforeach; ?>
            <?php foreach ($unique_selected as $code): ?>
              <?php $p = $productMap[$code] ?? null; if (!$p) continue; ?>
              <div class="financial-row">
                <span class="financial-label"><i class="fa-solid fa-circle-check" style="color: #22c55e; margin-right: 0.5rem;"></i> <?= htmlspecialchars($p['name']) ?></span>
                <span class="financial-value">$<?= number_format($p['price'], 2) ?></span>
              </div>
            <?php endforeach; ?>
            <?php foreach ($selected_accessories as $item): ?>
              <div class="financial-row">
                <span class="financial-label"><i class="fa-solid fa-plus-circle" style="color: var(--brand-color); margin-right: 0.5rem;"></i> <?= htmlspecialchars($item['name']) ?></span>
                <span class="financial-value">$<?= number_format((float)$item['price'], 2) ?></span>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

        <!-- Audit Log -->
        <div class="card">
          <div class="section-header">
            <i class="fa-solid fa-clock-rotate-left"></i>
            <h3 style="margin: 0; font-size: 1rem; font-weight: 800;">Audit History</h3>
          </div>
          <div style="max-height: 400px; overflow-y: auto;">
            <?php if (empty($audit_entries)): ?>
              <p class="text-muted" style="text-align: center; padding: 2rem 0;">No audit records available.</p>
            <?php else: ?>
              <div class="audit-list">
                <?php foreach ($audit_entries as $entry): ?>
                  <div class="audit-item">
                    <div style="font-weight: 700; color: #1e293b; font-size: 0.875rem;"><?= date('M j, Y, g:i a', strtotime($entry['submitted_at'])) ?></div>
                    <div style="color: #64748b; font-size: 0.75rem; margin-top: 0.25rem;">
                      By: <?= htmlspecialchars($entry['user_name'] ?? 'System') ?> · 
                      Type: <?= htmlspecialchars($entry['change_type'] ?? 'Selection') ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <?php if ($isAdmin && $canDeleteDeal): ?>
        <div style="margin-top: 2rem; padding: 1.5rem; border: 1px solid #fee2e2; background: #fffafb; border-radius: var(--radius-lg); display: flex; justify-content: space-between; align-items: center;">
          <div>
            <h4 style="margin: 0; color: #991b1b; font-weight: 700;">Danger Zone</h4>
            <p style="margin: 0; font-size: 0.875rem; color: #b91c1c;">Permanently delete this deal and all associated application data.</p>
          </div>
          <form action="delete_deal" method="POST" onsubmit="return confirm('ARE YOU SURE? This cannot be undone.');">
            <input type="hidden" name="deal_id" value="<?= htmlspecialchars($deal_id ?? '') ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
            <button type="submit" class="btn" style="background: #dc2626; color: white; font-weight: 700;"><i class="fa-solid fa-trash-can" style="margin-right: 0.5rem;"></i> Delete Deal</button>
          </form>
        </div>
      <?php endif; ?>
    </main>

    <footer style="background: white; border-top: 1px solid #e2e8f0; color: #64748b; padding: 1.5rem; text-align: center; position: static;">
      &copy; <?= date("Y") ?> DealerFAI. All rights reserved.
    </footer>
  </div>

  <form action="send_application" method="POST" id="send_application_form" class="d-none">
    <input type="hidden" name="deal_id" value="<?= (int)$deal['id'] ?>">
    <input type="hidden" name="confirmed_email" id="confirmed_email" value="">
    <input type="hidden" name="return_url" value="<?= htmlspecialchars('view_deal?id=' . urlencode((string)$deal_id)) ?>">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
  </form>

  <?php
    $dealStatus = strtolower(trim((string)($deal['deal_status'] ?? '')));
    $showStatusActions = !$isAdmin && $dealStatus !== 'booked' && $dealStatus !== 'cancelled';
  ?>
  <?php if ($showStatusActions): ?>
    <dialog id="deliver-dialog">
      <div style="background: var(--brand-color); color: white; padding: 1.25rem; font-weight: 700; font-size: 1.1rem;">Mark Deal as Delivered</div>
      <form action="update_deal_status.php" method="POST" id="deliver-form">
        <div style="padding: 1.5rem;">
          <input type="hidden" name="deal_id" value="<?= (int)$deal_id ?>">
          <input type="hidden" name="status" value="booked">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
          <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #475569;">Delivery Date</label>
          <input type="date" name="delivered_date" id="deliver-date" required style="width: 100%; padding: 0.75rem; border: 1px solid #e2e8f0; border-radius: var(--radius-md);">
        </div>
        <div style="padding: 1rem 1.5rem; background: #f8fafc; display: flex; justify-content: flex-end; gap: 0.75rem; border-top: 1px solid #e2e8f0;">
          <button type="button" class="btn btn-secondary" id="deliver-cancel">Close</button>
          <button type="submit" class="btn btn-primary">Confirm Delivered</button>
        </div>
      </form>
    </dialog>
  <?php endif; ?>

  <script nonce="<?= dealerfai_csp_nonce() ?>">
    // Launch credit application
    document.getElementById('launch_credit_button').addEventListener('click', function(e) {
      e.preventDefault();
      const url = <?= json_encode($creditShortLink !== '' ? $creditShortLink : $creditLink) ?>;
      if (!url) { alert("Credit application link unavailable."); return; }
      window.open(url, '_blank');
    });

    // Email customer invite
    document.getElementById('email_customer_button')?.addEventListener('click', function(e) {
      e.preventDefault();
      const currentEmail = <?= json_encode((string)($deal['customer_email'] ?? '')) ?>;
      if (!currentEmail) { alert('No customer email is saved on this deal.'); return; }
      const emailPrompt = window.prompt('Confirm customer email before sending:', currentEmail);
      if (emailPrompt === null) return;
      const email = emailPrompt.trim();
      if (!/^[^\\s@]+@[^\\s@]+\\.[^\\s@]+$/.test(email)) { alert('Please enter a valid email address.'); return; }
      if (!window.confirm('Send the application invite to ' + email + '?')) return;
      document.getElementById('confirmed_email').value = email;
      document.getElementById('send_application_form').submit();
    });

    // Copy link to clipboard
    document.getElementById('copy_credit_link')?.addEventListener('click', function() {
      const link = <?= json_encode($creditShortLink !== '' ? $creditShortLink : $creditLink) ?>;
      navigator.clipboard.writeText(link).then(() => alert('Credit app link copied.')).catch(() => alert(link));
    });

    // Delivery dialog handling
    const deliverDialog = document.getElementById('deliver-dialog');
    const deliverOpen = document.getElementById('deliver-open');
    const deliverCancel = document.getElementById('deliver-cancel');
    const deliverDate = document.getElementById('deliver-date');

    if (deliverDialog && deliverOpen && deliverCancel && deliverDate) {
      deliverOpen.addEventListener('click', () => {
        deliverDate.value = new Date().toISOString().slice(0, 10);
        deliverDialog.showModal();
      });
      deliverCancel.addEventListener('click', () => {
        deliverDialog.close();
      });
    }

    function confirmDelete() {
      return confirm('Are you sure? This will permanently delete the deal.');
    }
  </script>
</body>
</html>
