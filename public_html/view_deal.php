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
    $capPercent = ($capPercent !== null && $capPercent !== '' && is_numeric($capPercent)) ? (float)$capPercent : null;
    $msrpValue = (float)($deal['msrp'] ?? 0);
    if ($capPercent !== null && $capPercent > 0 && $msrpValue > 0) {
        $leaseCapEnabled = true;
        $leaseCapLimit = $msrpValue * ($capPercent / 100);
        $capPercentDisplay = $capPercent;
    }
}
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
    body { font-family: "Segoe UI", sans-serif; background: <?= htmlspecialchars($theme['page_background']) ?>; margin: 0; padding: 0; color: #111111; }
    header { background-color: <?= htmlspecialchars($theme['header_background']) ?>; color: <?= htmlspecialchars($theme['header_text']) ?>; padding: 30px 40px; text-align: center; position: relative; }
    header img {
      max-width: 480px;
      max-height: 180px;
      height: auto;
      display: block;
      margin: 0 auto 10px;
    }
    nav { background-color: <?= htmlspecialchars($theme['nav_background']) ?>; padding: 12px; text-align: center; }
    nav a { color: <?= htmlspecialchars($theme['nav_text']) ?>; margin: 0 20px; text-decoration: none; font-weight: bold; }
    nav a:hover { text-decoration: underline; }
    main { padding: 30px 40px; }
    .card { background: #fff; border-radius: 8px; padding: 25px; margin: 20px auto; box-shadow: 0 2px 10px rgba(0,0,0,0.05); max-width: 800px; }
    .card h3 { margin-top: 0; border-bottom: 1px solid #eee; padding-bottom: 10px; }
    label { display: block; margin-top: 15px; font-weight: bold; }
    input, select { width: 100%; padding: 10px; margin-top: 5px; border-radius: 4px; border: 1px solid #ccc; box-sizing: border-box; }
    .btn { background: <?= htmlspecialchars($theme['color']) ?>; color: white; padding: 12px 20px; border: none; border-radius: 4px; margin-top: 20px; cursor: pointer; font-size: 16px; }
    .btn:hover { opacity: 0.9; }
    .btn-delivered { background-color: #1f6f7a; }
    .btn-cancel { background-color: #c82333; }
    .status-actions { text-align: center; }
    .status-actions-buttons { display: flex; flex-wrap: wrap; gap: 12px; justify-content: center; margin-top: 10px; }
    .inline-form { margin: 0; }
    dialog#deliver-dialog { border: none; border-radius: 12px; padding: 0; width: min(420px, 92vw); box-shadow: 0 18px 40px rgba(0, 0, 0, 0.2); }
    dialog#deliver-dialog::backdrop { background: rgba(0, 0, 0, 0.35); }
    .dialog-header { background: <?= htmlspecialchars($theme['color']) ?>; color: white; padding: 16px 20px; font-weight: 700; }
    .dialog-body { padding: 18px 20px; display: grid; gap: 12px; }
    .dialog-body label { font-weight: 600; color: #203040; }
    .dialog-body input[type="date"] { padding: 10px; border-radius: 6px; border: 1px solid #c7d0d8; width: 100%; }
    .dialog-actions { padding: 14px 20px 20px; display: flex; justify-content: flex-end; gap: 10px; background: #f4f6f8; }
    .finance-only { display: none; }
    .residual-field { display: none; }
    .trade-field { display: none; }
    footer { background-color: <?= htmlspecialchars($theme['color']) ?>; color: white; text-align: center; padding: 16px; font-size: 14px; margin-top: 60px; }
    .logout { position: absolute; right: 20px; top: 20px; display: flex; gap: 12px; align-items: center; }
    .logout a { color: #ccc; font-size: 14px; text-decoration: none; }
    .logout a:hover { color: white; }
    .badge { display: inline-block; min-width: 18px; padding: 2px 8px; border-radius: 999px; background: #d7263d; color: #fff; font-size: 12px; font-weight: bold; text-align: center; margin-left: 6px; }
    .payment-highlight {
        font-size: 24px; /* Reduced size */
        font-weight: bold;
        color: #28a745;
        background: #d4f9d4;
        padding: 12px 18px; /* Slightly reduced padding */
        border-radius: 12px;
        display: inline-block;
        margin-top: 10px;
    }
    .muted { color: #667085; font-size: 0.9rem; }
    .payment-updated {
        font-size: 14px;
        color: green;
        margin-top: 5px;
        margin-left: 10px;
        display: none;
    }
    .alert {
      max-width: 900px;
      margin: 16px auto;
      padding: 12px 14px;
      border-radius: 8px;
      border: 1px solid transparent;
      font-weight: 600;
    }
    .alert-success {
      color: #0f5132;
      background: #d1e7dd;
      border-color: #badbcc;
    }
    .alert-error {
      color: #842029;
      background: #f8d7da;
      border-color: #f5c2c7;
    }
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
</head>

<body>

<header>
  <?php if (!empty($theme['logo'])): ?>
    <img src="<?= htmlspecialchars($theme['logo']) ?>" alt="Dealer Logo" style="max-height:60px; margin:auto; display:block;">
  <?php else: ?>
    <h1>DealerFAI</h1>
  <?php endif; ?>
  <div class="logout">
    <?php if ($isAdmin): ?>
      <a href="admin_error_alerts.php">Alerts<?php if ($adminAlertCount > 0): ?> <span class="badge"><?= $adminAlertCount ?></span><?php endif; ?></a>
    <?php endif; ?>
    <a href="logout.php">Log Out</a>
  </div>
</header>

<nav>
  <a href="dashboard.php">Dashboard</a>
  <a href="view_deals.php">View Deals</a>
  <a href="create_deal.php">Create Deal</a>
  <?php if (in_array('General Manager', $roles) || in_array('Admin', $roles)): ?>
    <a href="admin_tools.php">Admin Tools</a>
  <?php endif; ?>
</nav>

<main>
<?php if ($flashSuccess !== ''): ?>
  <div class="alert alert-success"><?= htmlspecialchars($flashSuccess) ?></div>
<?php endif; ?>
<?php if ($flashError !== ''): ?>
  <div class="alert alert-error"><?= htmlspecialchars($flashError) ?></div>
<?php endif; ?>

<form method="post" id="csrf-holder">
  <input type="hidden" id="csrf_token" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">  <!-- CSRF Token -->
</form>
<!-- Deal Details Card -->
<div class="card">
  <h3>Deal Information</h3>
  <p><strong>Deal Number:</strong> <?= htmlspecialchars((string)($deal['deal_number'] ?? '')) ?></p>
  <p><strong>Customer Name:</strong> <?= htmlspecialchars((string)($deal['customer_name'] ?? '')) ?></p>
  <p><strong>Customer Email:</strong> <?= htmlspecialchars((string)($deal['customer_email'] ?? '')) ?></p>
  <p><strong>Phone:</strong> <?= htmlspecialchars((string)($deal['customer_phone'] ?? '')) ?></p>
  <?php
    $customerType = 'personal';
    if (function_exists('column_exists') && column_exists($db, 'deals', 'customer_type')) {
      $rawType = strtolower(trim((string)($deal['customer_type'] ?? 'personal')));
      $customerType = in_array($rawType, ['personal', 'professional', 'commercial'], true) ? $rawType : 'personal';
    }
    $customerTypeLabel = $customerType === 'professional'
      ? 'Professional (Business Buyer)'
      : ($customerType === 'commercial' ? 'Commercial (Business)' : 'Personal');

    $businessName = '';
    $businessId = (int)($deal['business_id'] ?? 0);
    if ($businessId > 0) {
      try {
        $tbl = $db->prepare("
          SELECT COUNT(*)
          FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'businesses'
        ");
        $tbl->execute();
        $hasBiz = (int)$tbl->fetchColumn() > 0;
        if ($hasBiz) {
          $bizStmt = $db->prepare("SELECT name FROM businesses WHERE id = ? LIMIT 1");
          $bizStmt->execute([$businessId]);
          $businessName = (string)($bizStmt->fetchColumn() ?: '');
        }
      } catch (PDOException $e) {
        $businessName = '';
      }
    }
  ?>
  <p><strong>Customer Type:</strong> <?= htmlspecialchars($customerTypeLabel) ?></p>
  <?php if ($customerType !== 'personal'): ?>
    <p><strong>Business:</strong> <?= htmlspecialchars($businessName !== '' ? $businessName : ('#' . (string)$businessId)) ?></p>
  <?php endif; ?>
  <p><strong>Co-Applicant Required:</strong> <?= !empty($deal['co_app_required']) ? 'Yes' : 'No' ?></p>
  <?php if (!empty($deal['co_app_name'])): ?>
    <p><strong>Co-Signer Name:</strong> <?= htmlspecialchars((string)$deal['co_app_name']) ?></p>
  <?php endif; ?>
</div>

<!-- Vehicle Details Card -->
<div class="card">
  <h3>Vehicle Details</h3>
<?php
  $vehicleSummary = trim(
    (($deal['vehicle_year'] ?? '') !== '' ? ($deal['vehicle_year'] . ' ') : '') .
    ($vehicle_make_name ? $vehicle_make_name . ' ' : '') .
    ($deal['vehicle_model'] ?? '')
  );
  $vehicleColour = $deal['vehicle_colour'] ?? '';
  $vehicleKms = $deal['vehicle_kms'] ?? null;
  $inServiceDate = $deal['in_service_date'] ?? '';
  $vin = $deal['vin'] ?? '';
  $vehicleCondition = $vehicleCondition ?: 'Used';
?>
<p><strong>Year / Make / Model:</strong> <?= htmlspecialchars($vehicleSummary ?: '—') ?></p>
  <p><strong>Condition:</strong> <?= htmlspecialchars($vehicleCondition ?: '—') ?></p>
  <p><strong>Colour:</strong> <?= htmlspecialchars($vehicleColour ?: '—') ?></p>
  <p><strong>Kilometres:</strong> <?= $vehicleKms !== null ? number_format($vehicleKms) . ' km' : '—' ?></p>
  <p><strong>In-Service Date:</strong> <?= htmlspecialchars($inServiceDate ?: '—') ?></p>
  <p><strong>VIN:</strong> <?= htmlspecialchars($vin ?: '—') ?></p>
</div>

<!-- Team Assignments Card -->
<div class="card">
  <h3>Assigned Team</h3>
  <p><strong>Sales Advisor:</strong> <?= getUserName($db, $deal['sales_advisor_id']) ?></p>
  <p><strong>Finance Manager:</strong> <?= getUserName($db, $deal['finance_manager_id']) ?></p>
  <p><strong>Sales Manager:</strong> <?= getUserName($db, $deal['sales_manager_id']) ?></p>
</div>

<!-- Customer Notes / Turnover Card -->
<div class="card">
  <h3>Customer Notes (Turnover)</h3>
  <p class="muted">Capture usage and preferences to help AI tie value to recommendations. Do not include sensitive personal data.</p>

  <?php if (!$hasCustomerContext): ?>
    <p style="color:#b00020;">
      Customer notes are not enabled for this database (missing <code>deals.customer_context</code>).
      Ask an admin to apply the latest database schema updates.
    </p>
  <?php else: ?>
    <form action="update_customer_context.php" method="POST">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
      <input type="hidden" name="deal_id" value="<?= (int)($deal['id'] ?? 0) ?>">

      <label>Household (kids, pets, car seats, etc.)</label>
      <textarea name="ctx_household" rows="2" class="w-100"><?= htmlspecialchars((string)($customerContext['household'] ?? '')) ?></textarea>

      <label>Commute (distance, route type, highway/city, daily use)</label>
      <textarea name="ctx_commute" rows="2" class="w-100"><?= htmlspecialchars((string)($customerContext['commute'] ?? '')) ?></textarea>

      <label>Usage (parking outside, gravel, road trips, work truck, etc.)</label>
      <textarea name="ctx_usage" rows="2" class="w-100"><?= htmlspecialchars((string)($customerContext['usage'] ?? '')) ?></textarea>

      <label>Pain Points (glare, stains, chips, curb rash, cracked glass, etc.)</label>
      <textarea name="ctx_pain_points" rows="2" class="w-100"><?= htmlspecialchars((string)($customerContext['pain_points'] ?? '')) ?></textarea>

      <label>Must-Haves (what matters most)</label>
      <textarea name="ctx_must_haves" rows="2" class="w-100"><?= htmlspecialchars((string)($customerContext['must_haves'] ?? '')) ?></textarea>

      <label>Objections (price, skepticism, already have coverage, etc.)</label>
      <textarea name="ctx_objections" rows="2" class="w-100"><?= htmlspecialchars((string)($customerContext['objections'] ?? '')) ?></textarea>

      <label>Budget Sensitivity (optional)</label>
      <textarea name="ctx_budget" rows="1" class="w-100"><?= htmlspecialchars((string)($customerContext['budget'] ?? '')) ?></textarea>

      <label>Additional Notes (optional)</label>
      <textarea name="ctx_freeform" rows="3" class="w-100"><?= htmlspecialchars((string)($customerContext['freeform'] ?? $customerContext['notes'] ?? '')) ?></textarea>

      <button type="submit" class="btn" class="mt-10">Save Notes</button>
    </form>
  <?php endif; ?>
</div>

  <input type="hidden" id="deal_type" name="deal_type" value="<?= htmlspecialchars((string)($deal['deal_type'] ?? '')) ?>">
  <input type="hidden" id="sale_price" name="sale_price" value="<?= htmlspecialchars((string)($deal['sale_price'] ?? '')) ?>">
  <input type="hidden" id="documentation_fee" name="documentation_fee" value="<?= htmlspecialchars((string)($deal['documentation_fee'] ?? '0')) ?>">
  <input type="hidden" id="ppsa_fee" name="ppsa_fee" value="<?= htmlspecialchars((string)($deal['ppsa_fee'] ?? '0')) ?>">
  <input type="hidden" id="term" name="term" value="<?= htmlspecialchars((string)($deal['term'] ?? '')) ?>">
  <input type="hidden" id="interest_rate" name="interest_rate" value="<?= htmlspecialchars((string)($deal['interest_rate'] ?? '')) ?>">
  <input type="hidden" id="residual" name="residual" value="<?= htmlspecialchars((string)($deal['residual'] ?? '')) ?>">
  <input type="hidden" id="msrp" name="msrp" value="<?= htmlspecialchars((string)($deal['msrp'] ?? '')) ?>">

    <div class="card finance-only">
    <h3>Payment Setup</h3>

    <label>Down Payment</label>
    <input type="number" step="0.01" id="down_payment" name="down_payment" value="<?= $deal['down_payment'] ?>" oninput="calculatePayment()">

    <label>Payment Frequency</label>
    <select id="payment_frequency" name="payment_frequency" onchange="calculatePayment()">
      <option value="Monthly" <?= $deal['payment_frequency'] === 'Monthly' ? 'selected' : '' ?>>Monthly</option>
      <option value="Semi-Monthly" <?= $deal['payment_frequency'] === 'Semi-Monthly' ? 'selected' : '' ?>>Semi-Monthly</option>
      <option value="Bi-Weekly" <?= $deal['payment_frequency'] === 'Bi-Weekly' ? 'selected' : '' ?>>Bi-Weekly</option>
      <option value="Weekly" <?= $deal['payment_frequency'] === 'Weekly' ? 'selected' : '' ?>>Weekly</option>
    </select>

    <label>Province</label>
    <select id="province" name="province" onchange="calculatePayment()">
      <option value="MB" <?= $deal['province'] === 'MB' ? 'selected' : '' ?>>Manitoba</option>
      <option value="ON" <?= $deal['province'] === 'ON' ? 'selected' : '' ?>>Ontario</option>
      <option value="QC" <?= $deal['province'] === 'QC' ? 'selected' : '' ?>>Quebec</option>
      <option value="NS" <?= $deal['province'] === 'NS' ? 'selected' : '' ?>>Nova Scotia</option>
      <option value="NB" <?= $deal['province'] === 'NB' ? 'selected' : '' ?>>New Brunswick</option>
      <option value="BC" <?= $deal['province'] === 'BC' ? 'selected' : '' ?>>British Columbia</option>
      <option value="PE" <?= $deal['province'] === 'PE' ? 'selected' : '' ?>>PEI</option>
      <option value="SK" <?= $deal['province'] === 'SK' ? 'selected' : '' ?>>Saskatchewan</option>
      <option value="AB" <?= $deal['province'] === 'AB' ? 'selected' : '' ?>>Alberta</option>
      <option value="NL" <?= $deal['province'] === 'NL' ? 'selected' : '' ?>>Newfoundland</option>
      <option value="NT" <?= $deal['province'] === 'NT' ? 'selected' : '' ?>>NWT</option>
      <option value="YT" <?= $deal['province'] === 'YT' ? 'selected' : '' ?>>Yukon</option>
      <option value="NU" <?= $deal['province'] === 'NU' ? 'selected' : '' ?>>Nunavut</option>
    </select>

    <label style="cursor:pointer; color:#007bff; text-decoration:underline;" onclick="toggleTax()">Override Tax Rate</label>
    <input type="checkbox" id="override_tax" class="d-none">
    <input type="number" step="0.01" id="custom_tax_rate" name="custom_tax_rate" placeholder="Enter Tax %" disabled>
    <!-- Payment display moved to Financial Summary -->
  </div>

<?php if ($deal['trade_value'] > 0 || $deal['lien_amount'] > 0 || !empty($deal['trade_info'])): ?>
  <div class="card">
    <h3>Trade-In Details</h3>
    <?php if (!empty($deal['trade_info'])): ?>
      <p><strong>Trade Info:</strong> <?= htmlspecialchars($deal['trade_info']) ?></p>
    <?php endif; ?>
    <p><strong>Trade Value:</strong> $<?= number_format($deal['trade_value'], 2) ?></p>
    <p><strong>Lien Amount:</strong> $<?= number_format($deal['lien_amount'], 2) ?></p>
  </div>
<?php endif; ?>
  
    <div class="card">
    <h3>Financial Summary</h3>
    <p><strong>Deal Type:</strong> <?= htmlspecialchars($deal['deal_type']) ?></p>
    <?php if ($deal['deal_type'] !== 'Cash'): ?>
      <p><strong>Term:</strong> <?= $deal['term'] ?> months</p>
      <p><strong>Interest Rate:</strong> <?= $deal['interest_rate'] ?>%</p>
    <?php endif; ?>

    <?php
      // Province and tax rates
      $province = $_POST['province'] ?? $deal['province'];
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
      $documentation_fee = isset($deal['documentation_fee']) ? (float)$deal['documentation_fee'] : 0;
      $ppsa_fee = isset($deal['ppsa_fee']) ? (float)$deal['ppsa_fee'] : 0;
      $ppsa_total = $deal['deal_type'] !== 'Cash' ? $ppsa_fee : 0;

      // Step 1: Tax bases (sale + documentation + taxable items).
      // Protections can be GST/PST exempt depending on finance configuration.
      $gst_base = $deal['sale_price'] + $documentation_fee + $accessories_total + $protections_gst_taxable_total;
      $pst_base = $deal['sale_price'] + $documentation_fee + $accessories_total + $protections_pst_taxable_total;
      $hst_base = $deal['sale_price'] + $documentation_fee + $accessories_total + $protections_gst_taxable_total;

      $gst_taxable_subtotal = max(0, $gst_base - $deal['trade_value']);
      $pst_taxable_subtotal = max(0, $pst_base - $deal['trade_value']);
      $hst_taxable_subtotal = max(0, $hst_base - $deal['trade_value']);
      $flt_override_amount = $deal['flt_override_amount'] ?? null;
      // Federal luxury tax base: vehicle sale price + qualifying add-ons.
      $flt_base = $deal['sale_price'] + $accessories_luxury_total + $protections_luxury_total;
      $flt_calc = 0;
      if ($isNewVehicle) {
        if ($flt_base > 100000 && $flt_base < 200000) {
          $flt_calc = ($flt_base - 100000) * 0.20;
        } elseif ($flt_base >= 200000) {
          $flt_calc = $flt_base * 0.10;
        }
      }
      $flt_amt = $flt_override_amount !== null ? (float)$flt_override_amount : $flt_calc;
      // Step 2: Calculate taxes on taxable subtotals (after trade), including tax on FLT when applicable.
      $gst_amt = ($gst_taxable_subtotal + $flt_amt) * $gst;
      $pst_amt = ($pst_taxable_subtotal + $flt_amt) * $pst;
	      $hst_amt = ($hst_taxable_subtotal + $flt_amt) * $hst;
	      $tax_total = $gst_amt + $pst_amt + $hst_amt + $flt_amt;

	      // Step 3: Total with taxes.
	      // Keep an overall subtotal for the total (includes non-taxable items), while tax bases are per-tax.
	      $subtotal_before_taxes = $deal['sale_price'] + $documentation_fee + $protections_total + $accessories_total;
	      $taxable_subtotal = max(0, $subtotal_before_taxes - $deal['trade_value']);
	      $total_with_taxes = $taxable_subtotal + $tax_total + $ppsa_total;
      // Step 4: Total to finance (total with taxes - down + lien; trade already applied)
      $total_to_finance = $total_with_taxes - $deal['down_payment'] + $deal['lien_amount'] - $accessories_upfront_total;
      // Step 5: Calculate estimated payment using the amortization formula
      $payment = 0;
      $finance_cap_excess = 0.0;
      if ($deal['deal_type'] === 'Lease') {
        $cap_cost = $deal['sale_price'] + $documentation_fee + $protections_cap_eligible_total + $accessories_cap_cost_total + $ppsa_total;
        $cap_cost -= $deal['down_payment'] + $deal['trade_value'];
        $cap_cost += $deal['lien_amount'];
        $cap_cost = max(0, $cap_cost);
        $cap_cost_raw = $cap_cost;
        $cap_cost_excess = 0.0;
        if ($leaseCapEnabled && $leaseCapLimit > 0) {
          if ($cap_cost > $leaseCapLimit) {
            $cap_cost_excess = $cap_cost - $leaseCapLimit;
            $cap_cost = $leaseCapLimit;
          }
        }
        $cap_cost += $protections_exempt_total;
        $residual_value = (float)($deal['residual'] ?? 0);
        if ($accessories_residual_msrp_add > 0 && !empty($deal['msrp'])) {
          $residual_percent = $deal['residual'] && $deal['msrp'] ? ((float)$deal['residual'] / (float)$deal['msrp']) : 0;
          $residual_value += $accessories_residual_msrp_add * $residual_percent;
        }
        $tax_rate = $gst + $pst + $hst;
        $payment = calculate_lease_payment($cap_cost, $residual_value, $deal['term'], $deal['interest_rate'], $tax_rate);
      } elseif ($deal['deal_type'] !== 'Cash') {
        $finance_amount = $total_to_finance;
        if ($leaseCapEnabled && $leaseCapLimit > 0) {
          $finance_base = $deal['sale_price'] + $documentation_fee + $protections_cap_eligible_total + $accessories_finance_total + $ppsa_total;
          $finance_base -= $deal['down_payment'] + $deal['trade_value'];
          $finance_base += $deal['lien_amount'];
          $finance_base = max(0, $finance_base);
          if ($finance_base > $leaseCapLimit) {
            $finance_cap_excess = $finance_base - $leaseCapLimit;
            $finance_base = $leaseCapLimit;
          }
          $finance_amount = max(0, $total_to_finance - $finance_cap_excess);
        }
        $payment = calculate_payment($finance_amount, $deal['interest_rate'], $deal['term']);
      }
      $payment_frequency = $deal['payment_frequency'] ?? 'Monthly';
      $frequency_ratio = 1.0;
      if ($payment_frequency === 'Semi-Monthly') {
        $frequency_ratio = 12 / 24;
      } elseif ($payment_frequency === 'Bi-Weekly') {
        $frequency_ratio = 12 / 26;
      } elseif ($payment_frequency === 'Weekly') {
        $frequency_ratio = 12 / 52;
      }
      $payment *= $frequency_ratio;
      $payment_display = number_format($payment, 2);
      $total_to_finance_display = $total_to_finance;
      if ($deal['deal_type'] === 'Finance' && $leaseCapEnabled && $finance_cap_excess > 0) {
        $total_to_finance_display = max(0, $total_to_finance - $finance_cap_excess);
      }
      $residual_percent_display = null;
      if (!empty($deal['msrp']) && !empty($deal['residual'])) {
        $residual_percent_display = round(((float)$deal['residual'] / (float)$deal['msrp']) * 100, 2);
      }
      $residual_adjustment_total = 0.0;
      if (!empty($deal['msrp']) && $accessories_residual_msrp_add > 0 && !empty($deal['residual'])) {
        $residual_percent = (float)$deal['residual'] / (float)$deal['msrp'];
        $residual_adjustment_total = $accessories_residual_msrp_add * $residual_percent;
      }
      $adjusted_residual_value = (float)($deal['residual'] ?? 0) + $residual_adjustment_total;
    ?>

    <p><strong>Sale Price:</strong> $<?= number_format($deal['sale_price'], 2) ?></p>
    <?php if ($deal['deal_type'] === 'Lease'): ?>
      <p><strong>MSRP:</strong> $<?= number_format((float)($deal['msrp'] ?? 0), 2) ?></p>
      <?php if ($leaseCapEnabled && $leaseCapLimit > 0): ?>
        <p class="muted">Lease cap cost limit: $<?= number_format($leaseCapLimit, 2) ?> (<?= number_format($capPercentDisplay ?? 0, 2) ?>% of MSRP)</p>
        <?php if (!empty($cap_cost_excess) && $cap_cost_excess > 0): ?>
          <p class="muted">Cap cost overage moved to cash down: $<?= number_format($cap_cost_excess, 2) ?></p>
        <?php endif; ?>
      <?php endif; ?>
      <p><strong>Residual (Base):</strong> <span id="residual_base_display">$<?= number_format((float)($deal['residual'] ?? 0), 2) ?></span>
        <?php if ($residual_percent_display !== null): ?>
          (<?= number_format($residual_percent_display, 2) ?>%)
        <?php endif; ?>
      </p>
      <?php if ($residual_adjustment_total > 0): ?>
        <p><strong>Residual (Adjusted):</strong> <span id="residual_adjusted_display">$<?= number_format($adjusted_residual_value, 2) ?></span></p>
        <p class="muted" id="residual_adjustment_note">
          Includes $<?= number_format($residual_adjustment_total, 2) ?> residual adjustment —
          <?= htmlspecialchars(implode(', ', array_map(fn($row) => $row['name'], $accessory_residual_adjustments))) ?>
        </p>
      <?php endif; ?>
    <?php endif; ?>
    <?php if ($deal['deal_type'] === 'Finance' && $leaseCapEnabled && $leaseCapLimit > 0): ?>
      <p class="muted">Finance cap limit: $<?= number_format($leaseCapLimit, 2) ?> (<?= number_format($capPercentDisplay ?? 0, 2) ?>% of MSRP)</p>
      <?php if (!empty($finance_cap_excess) && $finance_cap_excess > 0): ?>
        <p class="muted">Finance overage moved to cash down: $<?= number_format($finance_cap_excess, 2) ?></p>
      <?php endif; ?>
    <?php endif; ?>
    <p><strong>Documentation:</strong> $<?= number_format($documentation_fee, 2) ?></p>
    <?php if (!empty($included_display) && $included_total > 0): ?>
      <p><strong>Included Protections:</strong> $<?= number_format($included_total, 2) ?></p>
    <?php endif; ?>
    <?php if (!empty($selected) && $selected_protections_total > 0): ?>
      <p><strong>Selected Protections:</strong> $<?= number_format($selected_protections_total, 2) ?></p>
    <?php endif; ?>
    <?php if (!empty($included_accessories) && $accessories_total > 0): ?>
      <p><strong>Included Accessories:</strong> $<?= number_format(array_sum(array_column($included_accessories, 'price')), 2) ?></p>
    <?php endif; ?>
    <?php if (!empty($selected_accessories)): ?>
      <?php
        $selectedAccessoriesTotal = array_sum(array_column($selected_accessories, 'price'));
      ?>
      <?php if ($selectedAccessoriesTotal > 0): ?>
        <p><strong>Selected Accessories:</strong> $<?= number_format($selectedAccessoriesTotal, 2) ?></p>
      <?php endif; ?>
    <?php endif; ?>
    <?php
    // Display GAP if it's selected
    if (in_array('gapProtection', $selected, true) && isset($productMap['gapProtection'])) {
        echo "<p><strong>GAP Protection:</strong> \$" . number_format($productMap['gapProtection']['price'], 2) . "</p>";
    }
    // Display CAP if it's selected
    if (in_array('assetProtection', $selected, true) && isset($productMap['assetProtection'])) {
        echo "<p><strong>CAP Protection:</strong> \$" . number_format($productMap['assetProtection']['price'], 2) . "</p>";
    }
    // Display Xpel if it's selected
    $xpelSelections = array_values(array_filter($selected, fn($code) => str_starts_with($code, 'xpel_')));
    if (!empty($xpelSelections)) {
        $xpelCode = $xpelSelections[0];
        if (isset($productMap[$xpelCode])) {
            $xpelName = $productMap[$xpelCode]['name'] ?? 'XPEL Protection';
            echo "<p><strong>" . htmlspecialchars($xpelName) . ":</strong> \$" . number_format($productMap[$xpelCode]['price'], 2) . "</p>";
        }
    } elseif (in_array('filmProtection', $selected, true) && isset($productMap['filmProtection'])) {
        echo "<p><strong>XPEL Protection:</strong> \$" . number_format($productMap['filmProtection']['price'], 2) . "</p>";
    }
    ?>
    <p><strong>Subtotal (before taxes):</strong> $<?= number_format($subtotal_before_taxes, 2) ?></p>
    <?php if ($deal['trade_value'] > 0): ?>
      <p><strong>Trade Value:</strong> $<?= number_format($deal['trade_value'], 2) ?></p>
      <p><strong>Taxable Subtotal:</strong> $<?= number_format($taxable_subtotal, 2) ?></p>
    <?php endif; ?>

    <?php if ($gst_amt): ?><p><strong>GST (<?= $gst * 100 ?>%):</strong> $<?= number_format($gst_amt, 2) ?></p><?php endif; ?>
    <?php if ($pst_amt): ?><p><strong>PST (<?= $pst * 100 ?>%):</strong> $<?= number_format($pst_amt, 2) ?></p><?php endif; ?>
    <?php if ($hst_amt): ?><p><strong>HST (<?= $hst * 100 ?>%):</strong> $<?= number_format($hst_amt, 2) ?></p><?php endif; ?>
    <?php if ($flt_amt > 0): ?>
      <p><strong>Canadian FLT<?= $flt_override_amount !== null ? ' (override)' : '' ?>:</strong> $<?= number_format($flt_amt, 2) ?></p>
    <?php endif; ?>
    <?php if ($deal['deal_type'] !== 'Cash'): ?>
      <p><strong>PPSA:</strong> $<?= number_format($ppsa_total, 2) ?></p>
    <?php endif; ?>
    <?php if ($isAdmin && $flt_amt > 0): ?>
      <form action="update_flt_override.php" method="POST" class="mt-12">
        <input type="hidden" name="deal_id" value="<?= (int)$deal['id'] ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
        <label for="flt_override_amount">FLT Override (leave blank for calculated)</label>
        <input type="number" step="0.01" min="0" id="flt_override_amount" name="flt_override_amount" value="<?= $flt_override_amount !== null ? htmlspecialchars(number_format((float)$flt_override_amount, 2, '.', '')) : '' ?>" oninput="calculatePayment()">
        <button type="submit" class="btn">Save FLT Override</button>
      </form>
    <?php endif; ?>

    <p><strong>Total (with taxes):</strong> $<?= number_format($total_with_taxes, 2) ?></p>

    <?php if ($deal['deal_type'] !== 'Cash'): ?>
      <p><strong>Down Payment:</strong> $<?= number_format($deal['down_payment'], 2) ?></p>
      <?php if ($deal['lien_amount'] > 0): ?>
        <p><strong>Lien Amount:</strong> $<?= number_format($deal['lien_amount'], 2) ?></p>
      <?php endif; ?>
    <?php endif; ?>
    <p><strong><?= $deal['deal_type'] === 'Cash' ? 'Total' : 'Total to Finance' ?>:</strong>
      $<?= number_format($deal['deal_type'] === 'Cash' ? $total_with_taxes : $total_to_finance_display, 2) ?>
    </p>
    <?php if ($deal['deal_type'] !== 'Cash'): ?>
      <!-- Estimated Payment moved here -->
      <p><strong>Estimated Payment:</strong>
        <span id="payment_display" class="payment-highlight"><?= $deal['deal_type'] !== 'Cash' ? ('$' . $payment_display) : '' ?></span>
        <span class="payment-frequency-label">
          <?= ($deal['payment_frequency'] === 'Monthly') ? 'per month' : (($deal['payment_frequency'] === 'Semi-Monthly') ? 'semi-monthly' : (($deal['payment_frequency'] === 'Bi-Weekly') ? 'bi-weekly' : 'per week')) ?>
        </span>
      </p>
      <p class="muted" id="lease-cap-note" class="d-none"></p>
      <div id="payment_updated" class="payment-updated" class="d-none">Payment Updated!</div>
    <?php endif; ?>
    </div>

<?php
// Application Status
$app_check = $db->prepare("SELECT started_at, submitted_at FROM applications WHERE deal_id = ?");
$app_check->execute([$deal['id']]);
$app_data = $app_check->fetch(PDO::FETCH_ASSOC);

$status = "Not Started";
if ($app_data) {
  if ($app_data['submitted_at']) { $status = "✅ Submitted"; }
  elseif ($app_data['started_at']) { $status = "🕓 In Progress"; }
}
$lockStatus = $creditAppLocked ? "🔒 Locked" : "Unlocked";
?>
<!-- Application Status Section -->
<div class="card">
  <h3>Application Status</h3>
  <p><strong>Status:</strong> <?= $status ?></p>
  <p><strong>Credit App Lock:</strong> <?= $lockStatus ?></p>
</div>
<?php if (!empty($included_display)): ?>
<div class="card">
  <h3>✅ Included Protection Products</h3>
  <ul>
    <?php foreach ($included_display as $item):
      $price = (float)$item['price'];
      $capLeaseExempt = false;
      $capFinanceExempt = false;
      $itemCode = $item['code'] ?? '';
      if ($itemCode !== '' && isset($productMap[$itemCode])) {
        $capLeaseExempt = !empty($productMap[$itemCode]['lease_cap_exempt']);
        $capFinanceExempt = !empty($productMap[$itemCode]['finance_cap_exempt']);
      }
      $product_payment = (!empty($deal['term']))
        ? calculate_addon_payment($deal['deal_type'], $price, $deal['term'], $deal['interest_rate'])
        : null;
    ?>
      <li>
        <strong><?= htmlspecialchars($item['name']) ?></strong> — $<?= number_format($price, 2) ?>
        <input type="hidden" class="protection-price<?= !empty($item['is_physical']) ? ' physical-protection-price' : '' ?>" value="<?= $price ?>"
          data-lease-cap-exempt="<?= $capLeaseExempt ? '1' : '0' ?>"
          data-finance-cap-exempt="<?= $capFinanceExempt ? '1' : '0' ?>">
        <?php if ($product_payment): ?>
          <br><span style="color:#666;">Est. Payment: $<?= number_format($product_payment, 2) ?>/mo</span>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>
<?php if (!empty($unique_selected)): ?>
<div class="card">
  <h3>✅ Selected Protection Products</h3>
  <ul>
    <?php foreach ($unique_selected as $code):
      $p = $productMap[$code] ?? null;
      if (!$p) continue;
      $is_physical = $isPhysicalProtection($code, $p['name'] ?? '');

      $product_payment = (!empty($deal['term']))
        ? calculate_addon_payment($deal['deal_type'], $p['price'], $deal['term'], $deal['interest_rate'])
        : null;
      $gross = calculate_gross($p['price']);
    ?>
      <li>
        <strong><?= $p['name'] ?></strong> — $<?= number_format($p['price'], 2) ?>
        <input type="hidden" class="protection-price<?= $is_physical ? ' physical-protection-price' : '' ?>" value="<?= $p['price'] ?>"
          data-lease-cap-exempt="<?= !empty($p['lease_cap_exempt']) ? '1' : '0' ?>"
          data-finance-cap-exempt="<?= !empty($p['finance_cap_exempt']) ? '1' : '0' ?>">
        <?php if ($product_payment): ?>
          <br><span style="color:#666;">Est. Payment: $<?= number_format($product_payment, 2) ?>/mo</span>
        <?php endif; ?>
        <br><span style="color:#999;">Gross Profit: $<?= number_format($gross, 2) ?></span>

        
      </li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<?php if (!empty($included_accessories)): ?>
<div class="card">
  <h3>✅ Included Accessories</h3>
  <ul>
    <?php foreach ($included_accessories as $item):
      $price = (float)$item['price'];
      $payMethod = $item['pay_method'] ?? 'upfront';
      $class = 'accessory-price accessory-' . $payMethod . '-price';
      if (!empty($item['contributes_to_luxury_tax'])) {
        $class .= ' accessory-luxury-price';
      }
      $residualAdd = 0.0;
      if ($payMethod === 'cap_cost') {
        foreach ($accessory_residual_adjustments as $adj) {
          if ($adj['name'] === $item['name']) {
            $residualAdd = (float)$adj['amount'];
            break;
          }
        }
      }
    ?>
      <li>
        <strong><?= htmlspecialchars($item['name']) ?></strong> — $<?= number_format($price, 2) ?>
        <input type="hidden" class="<?= htmlspecialchars($class) ?>" value="<?= $price ?>"<?= $residualAdd > 0 ? ' data-residual-add="' . htmlspecialchars((string)$residualAdd) . '" data-name="' . htmlspecialchars($item['name']) . '"' : '' ?>>
      </li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<?php if (!empty($selected_accessories)): ?>
<div class="card">
  <h3>✅ Selected Accessories</h3>
  <ul>
    <?php foreach ($selected_accessories as $item):
      $price = (float)$item['price'];
      $payMethod = $item['pay_method'] ?? 'upfront';
      $class = 'accessory-price accessory-' . $payMethod . '-price';
      if (!empty($item['contributes_to_luxury_tax'])) {
        $class .= ' accessory-luxury-price';
      }
      $residualAdd = 0.0;
      if ($payMethod === 'cap_cost') {
        foreach ($accessory_residual_adjustments as $adj) {
          if ($adj['name'] === $item['name']) {
            $residualAdd = (float)$adj['amount'];
            break;
          }
        }
      }
    ?>
      <li>
        <strong><?= htmlspecialchars($item['name']) ?></strong> — $<?= number_format($price, 2) ?>
        <input type="hidden" class="<?= htmlspecialchars($class) ?>" value="<?= $price ?>"<?= $residualAdd > 0 ? ' data-residual-add="' . htmlspecialchars((string)$residualAdd) . '" data-name="' . htmlspecialchars($item['name']) . '"' : '' ?>>
      </li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<?php
// Only show declined recommendations if the application has been submitted
$declined = array_values(array_diff($unique_recommended, $unique_selected));
if (!empty($declined)) {
    usort($declined, static function (string $a, string $b) use ($recommendedRank, $productMap): int {
        $rankA = $recommendedRank[$a] ?? PHP_INT_MAX;
        $rankB = $recommendedRank[$b] ?? PHP_INT_MAX;
        if ($rankA !== $rankB) {
            return $rankA <=> $rankB;
        }
        $nameA = (string)($productMap[$a]['name'] ?? $a);
        $nameB = (string)($productMap[$b]['name'] ?? $b);
        return strcasecmp($nameA, $nameB);
    });
}
if (!empty($declined) && !empty($app_data['submitted_at'])):
?>
<div class="card">
  <h3>⚠️ Declined Recommendations</h3>
  <ul>
    <?php foreach ($declined as $code):
      $p = $productMap[$code] ?? null;
      if (!$p) continue;
      $product_payment = (!empty($deal['term']))
        ? calculate_addon_payment($deal['deal_type'], $p['price'], $deal['term'], $deal['interest_rate'])
        : null;
      $gross = calculate_gross($p['price']);
    ?>
      <li>
        <strong><?= $p['name'] ?></strong> — $<?= number_format($p['price'], 2) ?>
        <?php if ($isAdmin || $isManager): ?>
          <form action="add_declined_protection.php" method="POST" style="display:inline-block; margin-left:10px;">
            <input type="hidden" name="deal_id" value="<?= (int)$deal_id ?>">
            <input type="hidden" name="product_code" value="<?= htmlspecialchars($code) ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
            <button type="submit" class="btn" style="padding:6px 10px;">Add to Deal</button>
          </form>
        <?php endif; ?>
        <input type="hidden" class="protection-price" value="<?= $p['price'] ?>"
          data-lease-cap-exempt="<?= !empty($p['lease_cap_exempt']) ? '1' : '0' ?>"
          data-finance-cap-exempt="<?= !empty($p['finance_cap_exempt']) ? '1' : '0' ?>">
        <?php if ($product_payment): ?>
          <br><span style="color:#666;">Est. Payment: $<?= number_format($product_payment, 2) ?>/mo</span>
        <?php endif; ?>
        <?php $reason = $p['reason'] ?: "This protection may be helpful based on your needs."; ?>
        <br><em style="display:block; margin-top:5px; color:#444;"><?= htmlspecialchars($reason) ?></em>
      </li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<?php
// Audit Log
$audit_stmt = $db->prepare("
  SELECT
    pal.id,
    pal.submitted_at,
    pal.selected_protections,
    pal.all_recommendations,
    pal.user_id,
    pal.change_type,
    pal.change_note,
    u.full_name AS user_name
  FROM protection_audit_log pal
  LEFT JOIN users u ON pal.user_id = u.id
  WHERE pal.deal_id = ?
  ORDER BY pal.submitted_at DESC
");
$audit_stmt->execute([$deal_id]);
$audit_entries = $audit_stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<?php
function generate_short_code(int $length = 8): string {
  $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
  $max = strlen($chars) - 1;
  $code = '';
  for ($i = 0; $i < $length; $i++) {
    $code .= $chars[random_int(0, $max)];
  }
  return $code;
}

$creditLinkCode = null;
try {
  $linkStmt = $db->prepare("SELECT code FROM credit_app_links WHERE deal_id = ? ORDER BY id DESC LIMIT 1");
  $linkStmt->execute([$deal_id]);
  $creditLinkCode = $linkStmt->fetchColumn() ?: null;
  if (!$creditLinkCode) {
    $insertLink = $db->prepare("INSERT INTO credit_app_links (deal_id, code) VALUES (?, ?)");
    $attempts = 0;
    while ($attempts < 5 && !$creditLinkCode) {
      $candidate = generate_short_code();
      try {
        $insertLink->execute([$deal_id, $candidate]);
        $creditLinkCode = $candidate;
      } catch (PDOException $e) {
        $creditLinkCode = null;
      }
      $attempts++;
    }
  }
} catch (PDOException $e) {
  $creditLinkCode = null;
}

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? '';
$baseUrl = $host !== '' ? $scheme . '://' . $host : '';
$creditToken = $deal['secure_token'] ?? '';
$creditLink = $baseUrl !== '' && $creditToken !== '' ? $baseUrl . '/credit_app_landing.php?token=' . urlencode($creditToken) : '';
$creditShortLink = $baseUrl !== '' && $creditLinkCode ? $baseUrl . '/credit_app_landing.php?code=' . urlencode($creditLinkCode) : '';
?>
<?php if (!empty($audit_entries)): ?>
<div class="card">
  <details>
    <summary><strong>📋 Protection Selection Audit Log</strong></summary>
    <ul class="mt-15">
      <?php foreach ($audit_entries as $entry): ?>
      <li class="mb-10">
        <?php
          $snapshotUrl = 'recommendations.php?deal_id=' . urlencode($deal_id) . '&snapshot_id=' . urlencode($entry['id']);
        ?>
        <strong><a href="<?= htmlspecialchars($snapshotUrl) ?>" target="_blank" rel="noopener noreferrer">
          <?= date('F j, Y, g:i a', strtotime($entry['submitted_at'])) ?>
        </a></strong><br>
        Selected: 
        <?php
          $sel = json_decode($entry['selected_protections'], true);
          echo $sel ? implode(', ', array_map('ucwords', str_replace('_', ' ', $sel))) : 'None';
        ?><br>
        <?php if (!empty($entry['user_name'])): ?>
          By: <?= htmlspecialchars($entry['user_name']) ?><br>
        <?php endif; ?>
        <?php if (!empty($entry['change_type'])): ?>
          Change: <?= htmlspecialchars($entry['change_type']) ?><?= !empty($entry['change_note']) ? ' - ' . htmlspecialchars($entry['change_note']) : '' ?><br>
        <?php endif; ?>
        Recommended:
        <?php
          $rec = json_decode($entry['all_recommendations'], true);
          echo $rec ? implode(', ', array_map('ucwords', str_replace('_', ' ', $rec))) : 'None';
        ?>
      </li>
      <?php endforeach; ?>
    </ul>
  </details>
</div>
<?php endif; ?>

<?php
$deal_change_entries = [];
if ($isAdmin && deal_audit_table_exists($db)) {
  $dealAuditStmt = $db->prepare("
    SELECT dcal.id,
           dcal.action_type,
           dcal.changed_fields,
           dcal.change_meta,
           dcal.user_id,
           dcal.created_at,
           u.full_name AS user_name
    FROM deal_change_audit_log dcal
    LEFT JOIN users u ON u.id = dcal.user_id
    WHERE dcal.deal_id = ?
    ORDER BY dcal.created_at DESC, dcal.id DESC
    LIMIT 300
  ");
  $dealAuditStmt->execute([$deal_id]);
  $deal_change_entries = $dealAuditStmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<?php if ($isAdmin && deal_audit_table_exists($db)): ?>
<div class="card">
  <details>
    <summary><strong>Deal Change Audit Log (Admin)</strong></summary>
    <?php if (empty($deal_change_entries)): ?>
      <p class="mt-12">No deal changes logged yet.</p>
    <?php else: ?>
      <ul class="mt-15">
        <?php foreach ($deal_change_entries as $entry): ?>
          <?php
            $changedFields = json_decode((string)($entry['changed_fields'] ?? '[]'), true);
            if (!is_array($changedFields)) {
              $changedFields = [];
            }
            $changeMeta = json_decode((string)($entry['change_meta'] ?? '{}'), true);
            if (!is_array($changeMeta)) {
              $changeMeta = [];
            }
            $context = trim((string)($changeMeta['context'] ?? ''));
          ?>
          <li class="mb-10">
            <strong><?= date('F j, Y, g:i a', strtotime($entry['created_at'])) ?></strong><br>
            Action: <?= htmlspecialchars((string)($entry['action_type'] ?? 'update')) ?><br>
            <?php if (!empty($entry['user_name'])): ?>
              By: <?= htmlspecialchars((string)$entry['user_name']) ?><br>
            <?php elseif (!empty($entry['user_id'])): ?>
              By user ID: <?= (int)$entry['user_id'] ?><br>
            <?php endif; ?>
            <?php if ($context !== ''): ?>
              Source: <?= htmlspecialchars($context) ?><br>
            <?php endif; ?>
            <?php if (!empty($changedFields)): ?>
              Fields: <?= htmlspecialchars(implode(', ', array_map('strval', $changedFields))) ?>
            <?php else: ?>
              Fields: (not captured)
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </details>
</div>
<?php endif; ?>

<div class="card" class="text-center">
  <?php
    $launchDisabled = $creditAppLocked ? 'disabled' : '';
    $isCashDeal = ($deal['deal_type'] ?? '') === 'Cash';
    if ($creditAppLocked) {
      $launchLabel = 'Credit Application Locked';
    } else {
      $launchLabel = $isCashDeal ? 'Launch Cash Application' : 'Launch Credit Application';
    }
  ?>
  <button type="button" class="btn" style="background-color: <?= htmlspecialchars($theme['color']) ?>; color: white; margin-left:10px;" id="launch_credit_button" <?= $launchDisabled ?>>
    <?= $launchLabel ?>
  </button>
  <?php
    $emailDisabled = ($creditAppLocked || empty($deal['customer_email'])) ? 'disabled' : '';
  ?>
  <button type="button" class="btn" class="ml-10" id="email_customer_button" <?= $emailDisabled ?>>
    Email Customer
  </button>
  <form action="send_application.php" method="POST" id="send_application_form" class="d-none">
    <input type="hidden" name="deal_id" value="<?= (int)$deal['id'] ?>">
    <input type="hidden" name="confirmed_email" id="confirmed_email" value="">
    <input type="hidden" name="return_url" value="<?= htmlspecialchars('view_deal.php?id=' . urlencode((string)$deal_id)) ?>">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
  </form>
  <?php if ($creditShortLink !== '' || $creditLink !== ''): ?>
    <button type="button" class="btn" class="ml-10" id="copy_credit_link">Copy Credit App Link</button>
  <?php endif; ?>
  <?php if ($creditAppLocked && (in_array('Admin', $roles, true) || in_array('General Manager', $roles, true) || in_array('Finance Manager', $roles, true))): ?>
    <form action="unlock_credit_app.php" method="POST" style="display:inline-block; margin-left:10px;" onsubmit="return confirm('Unlock this credit application?');">
      <input type="hidden" name="deal_id" value="<?= (int)$deal_id ?>">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
      <button type="submit" class="btn">🔓 Unlock Credit App</button>
    </form>
  <?php endif; ?>
<?php if (in_array('Admin', $roles) || in_array('General Manager', $roles) || in_array('Finance Manager', $roles)): ?>
  <a href="view_credit_info.php?id=<?= urlencode($deal_id) ?>" class="btn" class="ml-10">
    🔐 View Credit Info
  </a>
<?php endif; ?>
</div>

<script nonce="<?= dealerfai_csp_nonce() ?>">
// When the Launch Credit Application button is clicked
document.getElementById('launch_credit_button').addEventListener('click', function(e) {
    e.preventDefault(); // Prevent any default behavior

    // Open a new tab and redirect to the credit application landing page
    const url = <?= json_encode($creditShortLink !== '' ? $creditShortLink : $creditLink) ?>;
    if (!url) {
        alert("Credit application link unavailable.");
        return;
    }
    window.open(url, '_blank');  // Open the landing page in a new tab
});
document.getElementById('email_customer_button')?.addEventListener('click', function(e) {
  e.preventDefault();
  const currentEmail = <?= json_encode((string)($deal['customer_email'] ?? '')) ?>;
  if (!currentEmail) {
    alert('No customer email is saved on this deal.');
    return;
  }
  const emailPrompt = window.prompt('Confirm customer email before sending:', currentEmail);
  if (emailPrompt === null) {
    return;
  }
  const email = emailPrompt.trim();
  const valid = /^[^\\s@]+@[^\\s@]+\\.[^\\s@]+$/.test(email);
  if (!valid) {
    alert('Please enter a valid email address.');
    return;
  }
  if (!window.confirm('Send the application invite to ' + email + '?')) {
    return;
  }
  const emailInput = document.getElementById('confirmed_email');
  const form = document.getElementById('send_application_form');
  if (!emailInput || !form) {
    alert('Unable to send right now. Please refresh and try again.');
    return;
  }
  emailInput.value = email;
  form.submit();
});
<?php if ($creditShortLink !== '' || $creditLink !== ''): ?>
document.getElementById('copy_credit_link')?.addEventListener('click', function() {
  const link = <?= json_encode($creditShortLink !== '' ? $creditShortLink : $creditLink) ?>;
  navigator.clipboard.writeText(link).then(() => alert('Credit app link copied.')).catch(() => alert(link));
});
<?php endif; ?>
</script>

<?php
$canEditDeal = (
  $deal['created_by'] == $_SESSION['user_id'] ||
  in_array('Finance Manager', $roles) ||
  in_array('Sales Manager', $roles) ||
  in_array('General Manager', $roles) ||
  in_array('Admin', $roles)
);
$canDeleteDeal = in_array('Admin', $roles, true);
?>
<?php if ($canEditDeal || $canDeleteDeal): ?>
  <div style="text-align:center; margin-top:20px;">
    <?php if ($canEditDeal): ?>
      <a href="edit_deal.php?id=<?= urlencode($deal_id) ?>" class="btn" style="margin-right:10px;">✏️ Edit Deal</a>
    <?php endif; ?>
    <?php if ($canDeleteDeal): ?>
      <form action="delete_deal.php" method="POST" onsubmit="return confirmDelete();" class="d-inline-block">
        <input type="hidden" name="deal_id" value="<?= htmlspecialchars($deal_id ?? '') ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
        <button type="submit" class="btn" style="background:#c82333;">🗑️ Delete Deal</button>
      </form>
    <?php endif; ?>
  </div>
<?php endif; ?>
<?php
$dealStatus = strtolower(trim((string)($deal['deal_status'] ?? '')));
$showStatusActions = !$isAdmin && $dealStatus !== 'booked' && $dealStatus !== 'cancelled';
?>
<?php if ($showStatusActions): ?>
  <div class="card status-actions">
    <h3>Update Deal Status</h3>
    <div class="status-actions-buttons">
      <button type="button" class="btn btn-delivered" id="deliver-open">Delivered</button>
      <form action="update_deal_status.php" method="POST" class="inline-form" onsubmit="return confirm('Cancel this deal?');">
        <input type="hidden" name="deal_id" value="<?= (int)$deal_id ?>">
        <input type="hidden" name="status" value="cancelled">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
        <button type="submit" class="btn btn-cancel">Cancel</button>
      </form>
    </div>
  </div>

  <dialog id="deliver-dialog">
    <div class="dialog-header">Mark Deal as Delivered</div>
    <form action="update_deal_status.php" method="POST" id="deliver-form">
      <div class="dialog-body">
        <input type="hidden" name="deal_id" value="<?= (int)$deal_id ?>">
        <input type="hidden" name="status" value="booked">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
        <label for="deliver-date">Delivery Date</label>
        <input type="date" name="delivered_date" id="deliver-date" required>
      </div>
      <div class="dialog-actions">
        <button type="button" class="btn" id="deliver-cancel">Close</button>
        <button type="submit" class="btn btn-delivered">Confirm Delivered</button>
      </div>
    </form>
  </dialog>

  <script nonce="<?= dealerfai_csp_nonce() ?>">
    const deliverDialog = document.getElementById('deliver-dialog');
    const deliverOpen = document.getElementById('deliver-open');
    const deliverDate = document.getElementById('deliver-date');
    const deliverCancel = document.getElementById('deliver-cancel');

    if (deliverDialog && deliverOpen && deliverDate && deliverCancel) {
      deliverOpen.addEventListener('click', () => {
        deliverDate.value = new Date().toISOString().slice(0, 10);
        if (typeof deliverDialog.showModal === 'function') {
          deliverDialog.showModal();
        } else {
          deliverDialog.setAttribute('open', 'open');
        }
      });

      deliverCancel.addEventListener('click', () => {
        if (typeof deliverDialog.close === 'function') {
          deliverDialog.close();
        } else {
          deliverDialog.removeAttribute('open');
        }
      });
    }
  </script>
<?php endif; ?>
<script nonce="<?= dealerfai_csp_nonce() ?>">
function confirmDelete() {
  if (!confirm('Are you sure you want to permanently delete this deal? This cannot be undone.')) {
    return false;
  }
  if (!confirm('Please confirm again: Delete this deal and all related records?')) {
    return false;
  }
  return true;
}
</script>

</body>
<!-- No extra JS for Launch Credit Application; form submits directly to application_start.php in new tab -->
