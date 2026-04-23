<?php
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/csrf.php';
include 'auth.php';
require_once __DIR__ . '/includes/theme_head.php';
include 'db.php';
require_once __DIR__ . '/accessory_helpers.php';
require_once __DIR__ . '/helpers/customer_types.php';
require_once __DIR__ . '/helpers/deal_audit.php';
require_once __DIR__ . '/helpers/db_utils.php';
require_once __DIR__ . '/helpers/session_utils.php';

$roles = load_session_roles();
$isAdmin = in_array('Admin', $roles, true);
$adminAlertCount = $isAdmin ? get_admin_alert_count($db) : 0;

$org = $_SESSION['deal_draft']['organization'] ?? get_effective_organization();
$theme = ['logo' => '', 'color' => '#0a2e36'];

if ($org) {
  $stmt = $db->prepare("SELECT logo_url, theme_variant FROM organizations WHERE id = ?");
  $stmt->execute([$org]);
  $orgData = $stmt->fetch(PDO::FETCH_ASSOC);
  if ($orgData) {
    $theme['logo'] = $orgData['logo_url'] ?? '';
    $theme['color'] = $orgData['theme_variant'] === 'jlr' ? '#4a4a4a' : (in_array($orgData['theme_variant'], ['land_rover', 'jaguar'], true) ? '#1A1A1A' : '#0a6280');
  }
}

if (!isset($_SESSION['deal_draft'])) {
  header("Location: create_deal");
  exit;
}

$draft = &$_SESSION['deal_draft'];
if (empty($draft['draft_csrf_token']) || !is_string($draft['draft_csrf_token'])) {
  $draft['draft_csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $draft['draft_csrf_token'];
$formError = '';
$productCatalog = [];
$includeIncluded = false;
$includedSelections = [];
$includedPrices = [];
$accessoryCatalog = [];
$includeIncludedAccessories = false;
$includedAccessorySelections = [];
$includedAccessoryPrices = [];

function load_org_meta(PDO $db, int $orgId): array {
  $columns = ['org_kind', 'parent_org_id', 'logic_type'];
  if (organization_column_exists($db, 'preferred_provider')) {
    $columns[] = 'preferred_provider';
  }
  $select = implode(', ', $columns);
  $stmt = $db->prepare("SELECT {$select} FROM organizations WHERE id = ?");
  $stmt->execute([$orgId]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  return is_array($row) ? $row : [];
}

function unique_products_by_code_local(array $products, ?string $preferredProvider = null): array {
  $seen = [];
  $deduplicated = [];
  $preferredProvider = $preferredProvider ? strtolower(trim($preferredProvider)) : '';
  $providerSensitiveCodes = ['extwarranty'];
  $brandProviders = ['jaguar', 'land rover', 'landrover'];
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
      continue;
    }
    if (!in_array($codeKey, $providerSensitiveCodes, true)) {
      continue;
    }
    if (in_array($currentProvider, $brandProviders, true) && !in_array($candidateProvider, $brandProviders, true)) {
      $seen[$codeKey] = $product;
    }
  }
  foreach ($seen as $entry) {
    $deduplicated[] = $entry;
  }
  return $deduplicated;
}

function load_accessory_fitment_map_local(PDO $db, array $accessoryIds): array {
  $ids = array_values(array_filter(array_map('intval', $accessoryIds), static function ($id) {
    return $id > 0;
  }));
  if (empty($ids)) {
    return [];
  }
  $placeholders = implode(',', array_fill(0, count($ids), '?'));
  $stmt = $db->prepare("
    SELECT accessory_id, make_id, model_id, trim_id, brand, model, min_year, max_year
    FROM accessory_fitment
    WHERE accessory_id IN ($placeholders)
  ");
  $stmt->execute($ids);
  $map = [];
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $aid = (int)($row['accessory_id'] ?? 0);
    if ($aid > 0) {
      $map[$aid][] = $row;
    }
  }
  return $map;
}

function load_products_for_included(PDO $db, int $orgId, string $customerType, ?string $vehicleCondition = null): array {
  $vehicleCondition = strtolower(trim((string)$vehicleCondition));
  if (!in_array($vehicleCondition, ['new', 'used'], true)) {
    $vehicleCondition = 'any';
  }
  $customerType = normalize_customer_type($customerType);
  $hasAllowedCustomerTypes = table_column_exists($db, 'products', 'allowed_customer_types');
  $hasCustomerTypeAllowlist = table_column_exists($db, 'product_allowed_customer_types', 'customer_type');
  if ($orgId <= 0) {
    $sql = "SELECT code, name, provider, default_price FROM products WHERE is_active = 1";
    if ($hasCustomerTypeAllowlist) {
      $sql .= " AND (
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
      )";
    } elseif ($hasAllowedCustomerTypes) {
      $sql .= " AND (allowed_customer_types IS NULL OR allowed_customer_types = '' OR FIND_IN_SET(?, REPLACE(LOWER(allowed_customer_types), ' ', '')))";
    }
    $sql .= " ORDER BY name ASC";
    $stmt = $db->prepare($sql);
    $params = ($hasCustomerTypeAllowlist || $hasAllowedCustomerTypes) ? [$customerType] : [];
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return unique_products_by_code_local($rows, null);
  }

  $orgMeta = load_org_meta($db, $orgId);
  $orgKind = $orgMeta['org_kind'] ?? 'store';
  $logicType = $orgMeta['logic_type'] ?? null;
  $preferredProvider = trim((string)($orgMeta['preferred_provider'] ?? ''));
  $groupId = null;
  if ($orgKind === 'store' && !empty($orgMeta['parent_org_id'])) {
    $groupId = (int)$orgMeta['parent_org_id'];
  }

  $conditionParams = array_fill(0, 15, $vehicleCondition);
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
    : ($hasAllowedCustomerTypes
    ? " AND (p.allowed_customer_types IS NULL OR p.allowed_customer_types = '' OR FIND_IN_SET(?, REPLACE(LOWER(p.allowed_customer_types), ' ', '')))"
    : "");
  $needsCustomerTypeParam = ($hasCustomerTypeAllowlist || $hasAllowedCustomerTypes);

  $stmt = $db->prepare("
    SELECT p.code,
           p.provider,
           COALESCE(o.custom_name, g.custom_name, p.name) AS name,
           COALESCE(o.custom_price, g.custom_price, p.default_price, 0) AS default_price,
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
      {$whereAllowedCustomerTypes}
    ORDER BY name ASC
  ");
  $stmtParams = array_merge(
    $conditionParams,
    [
      $orgId,
      (string)$orgId,
      (string)$orgId,
      (string)$orgId,
      (string)$orgId,
      (string)$orgId,
      (string)$orgId,
      $groupId === null ? '' : (string)$groupId,
      $groupId === null ? '' : (string)$groupId,
      $groupId === null ? '' : (string)$groupId,
      $logicType === null ? '' : $logicType,
      $logicType === null ? '' : $logicType,
      $logicType === null ? '' : $logicType,
    ],
    $conditionParams,
    $needsCustomerTypeParam ? [$customerType] : []
  );
  $stmt->execute($stmtParams);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
  $deduped = unique_products_by_code_local($rows, $preferredProvider !== '' ? $preferredProvider : null);
  return $deduped;
}
$orgId = (int)($draft['organization'] ?? 0);
$customerType = normalize_customer_type($draft['customer_type'] ?? 'personal');
try {
  $products = load_products_for_included($db, $orgId, $customerType, $draft['vehicle_condition'] ?? null);
  foreach ($products as $row) {
    $code = trim((string)($row['code'] ?? ''));
    if ($code === '') {
      continue;
    }
    if (isset($productCatalog[$code])) {
      continue;
    }
    $productCatalog[$code] = [
      'name' => $row['name'] ?? $code,
      'default_price' => isset($row['default_price']) ? (float)$row['default_price'] : null,
    ];
  }
} catch (PDOException $e) {
  error_log("Unable to load product catalog: " . $e->getMessage());
}

if ($orgId > 0) {
  try {
    $accessories = fetch_accessories_for_org($db, $orgId);
    $accessoryIds = array_values(array_filter(array_map(static fn($row) => (int)($row['id'] ?? 0), $accessories)));
    $fitmentMap = load_accessory_fitment_map_local($db, $accessoryIds);
    $vehicleMake = trim((string)($draft['vehicle_make'] ?? ''));
    $vehicleModel = trim((string)($draft['vehicle_model'] ?? ''));
    $vehicleYear = isset($draft['vehicle_year']) && $draft['vehicle_year'] !== '' ? (int)$draft['vehicle_year'] : null;
    $vehicleMakeId = !empty($draft['vehicle_make_id']) ? (int)$draft['vehicle_make_id'] : null;
    $vehicleModelId = !empty($draft['vehicle_model_id']) ? (int)$draft['vehicle_model_id'] : null;
    $vehicleTrimId = !empty($draft['vehicle_trim_id']) ? (int)$draft['vehicle_trim_id'] : null;
    foreach ($accessories as $row) {
      $id = (int)($row['id'] ?? 0);
      if ($id <= 0) {
        continue;
      }
      $fitments = $fitmentMap[$id] ?? [];
      if (!accessory_matches_fitment($fitments, $vehicleMake, $vehicleModel, $vehicleYear, $vehicleMakeId, $vehicleModelId, $vehicleTrimId)) {
        continue;
      }
      $provider = trim((string)($row['provider'] ?? ''));
      $displayName = $row['name'] ?? ('Accessory ' . $id);
      if ($provider !== '') {
        $displayName .= ' (' . $provider . ')';
      }
      $accessoryCatalog[$id] = [
        'code' => $row['code'] ?? '',
        'provider' => $provider,
        'name' => $displayName,
        'base_price' => isset($row['base_price']) ? (float)$row['base_price'] : null,
        'residualizable' => !empty($row['residualizable']) ? 1 : 0,
        'residual_msrp_add' => isset($row['residual_msrp_add']) ? (float)$row['residual_msrp_add'] : 0,
        'contributes_to_luxury_tax' => 1,
      ];
    }
  } catch (PDOException $e) {
    error_log("Unable to load accessory catalog: " . $e->getMessage());
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $submittedToken = $_POST['csrf_token'] ?? null;
  $expectedToken = $draft['draft_csrf_token'] ?? null;
  $isDraftTokenValid = is_string($submittedToken)
    && is_string($expectedToken)
    && $expectedToken !== ''
    && hash_equals($expectedToken, $submittedToken);
  if (!$isDraftTokenValid) {
    http_response_code(403);
    die('Invalid request.');
  }

  // Save final step values
  $draft['deal_type']         = $_POST['deal_type'];
  $draft['sale_price']        = $_POST['sale_price'];
  $documentationFee = $_POST['documentation_fee'] ?? '';
  $ppsaFee = $_POST['ppsa_fee'] ?? '';
  $draft['documentation_fee'] = $documentationFee === '' ? 0 : $documentationFee;
  $draft['ppsa_fee']          = $ppsaFee === '' ? 0 : $ppsaFee;
  $draft['term']              = $_POST['term'] ?: null;
  $draft['interest_rate']     = $_POST['interest_rate'] ?: null;
  $msrpInput = $_POST['msrp'] ?? '';
  $residualInput = $_POST['residual'] ?? '';
  $residualPercentInput = $_POST['residual_percent'] ?? '';
  $draft['msrp']              = $msrpInput === '' ? null : $msrpInput;
  if (($residualInput === '' || $residualInput === null) && $msrpInput !== '' && $residualPercentInput !== '' && is_numeric($residualPercentInput)) {
    $residualInput = (float)$msrpInput * ((float)$residualPercentInput / 100);
  }
  $draft['residual']          = $residualInput === '' ? null : $residualInput;
  $draft['down_payment']      = $_POST['down_payment'] ?: 0;
  $draft['payment_frequency'] = $_POST['payment_frequency'] ?? 'Monthly';
  $draft['trade_info']        = $_POST['trade_info'] ?? null;
  $draft['trade_value']       = $_POST['trade_value'] ?: 0;
  $draft['lien_amount']       = $_POST['lien_amount'] ?: 0;
  $includeIncluded = !empty($_POST['include_included_protections']);
  $includedSelections = $_POST['included_protections'] ?? [];
  $includedPrices = $_POST['included_protection_prices'] ?? [];
  $includedSelections = is_array($includedSelections) ? $includedSelections : [];
  $includedPrices = is_array($includedPrices) ? $includedPrices : [];
  $includedProtections = [];
  if ($includeIncluded) {
    foreach ($includedSelections as $code) {
      $code = trim((string)$code);
      if ($code === '') {
        continue;
      }
      $priceRaw = $includedPrices[$code] ?? '';
      if ($priceRaw === '' || !is_numeric($priceRaw)) {
        $defaultPrice = $productCatalog[$code]['default_price'] ?? null;
        if ($defaultPrice !== null) {
          $priceRaw = $defaultPrice;
        } else {
          $formError = 'Please enter a sale price for each included protection.';
          break;
        }
      }
      $includedProtections[] = [
        'code' => $code,
        'name' => $productCatalog[$code]['name'] ?? $code,
        'price' => (float)$priceRaw,
      ];
    }
  }
  if (!$formError) {
    $draft['included_protections'] = !empty($includedProtections)
      ? json_encode($includedProtections)
      : null;
  }

  $includeIncludedAccessories = !empty($_POST['include_included_accessories']);
  $includedAccessorySelections = $_POST['included_accessories'] ?? [];
  $includedAccessoryPrices = $_POST['included_accessory_prices'] ?? [];
  $includedAccessorySelections = is_array($includedAccessorySelections) ? $includedAccessorySelections : [];
  $includedAccessoryPrices = is_array($includedAccessoryPrices) ? $includedAccessoryPrices : [];
  $includedAccessories = [];
  if ($includeIncludedAccessories) {
    foreach ($includedAccessorySelections as $id) {
      $id = (int)$id;
      if ($id <= 0 || !isset($accessoryCatalog[$id])) {
        continue;
      }
      $priceRaw = $includedAccessoryPrices[$id] ?? '';
      if ($priceRaw === '' || !is_numeric($priceRaw)) {
        $defaultPrice = $accessoryCatalog[$id]['base_price'] ?? null;
        if ($defaultPrice !== null) {
          $priceRaw = $defaultPrice;
        } else {
          $formError = 'Please enter a sale price for each included accessory.';
          break;
        }
      }
      $includedAccessories[] = [
        'id' => $id,
        'code' => $accessoryCatalog[$id]['code'] ?? '',
        'name' => $accessoryCatalog[$id]['name'] ?? ('Accessory ' . $id),
        'price' => (float)$priceRaw,
        'residualizable' => (int)($accessoryCatalog[$id]['residualizable'] ?? 0),
        'residual_msrp_add' => (float)($accessoryCatalog[$id]['residual_msrp_add'] ?? 0),
        'contributes_to_luxury_tax' => 1,
      ];
    }
  }
  if (!$formError) {
    $draft['included_accessories'] = $includedAccessories;
  }

if (empty($draft['vehicle_make_id'])) {
    error_log("⚠️ vehicle_make_id is missing from session at Step 3. Value: " . var_export($draft['vehicle_make_id'], true));
} else {
    error_log("✅ vehicle_make_id is present at Step 3: " . $draft['vehicle_make_id']);
}

// Validate required fields before insert
if (!$formError && (empty($draft['deal_type']) || empty($draft['sale_price']) || empty($draft['province']))) {
  $formError = "Missing required fields: deal type, sale price, or province.";
}
if (!$formError && ($draft['deal_type'] ?? '') === 'Lease') {
  if (empty($draft['term']) || empty($draft['interest_rate'])) {
    $formError = "Term and interest rate are required for lease deals.";
  } elseif (empty($draft['msrp']) || empty($draft['residual'])) {
    $formError = "MSRP and residual value are required for lease deals.";
  }
}

// Insert into DB
if (!$formError) {
  $customerType = normalize_customer_type($draft['customer_type'] ?? 'personal');
  $businessId = null;
  $hasBusinessesTable = false;
  try {
    $tbl = $db->prepare("
      SELECT COUNT(*)
      FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'businesses'
    ");
    $tbl->execute();
    $hasBusinessesTable = (int)$tbl->fetchColumn() > 0;
  } catch (PDOException $e) {
    $hasBusinessesTable = false;
  }

  if ($customerType !== 'personal') {
    $candidate = (int)($draft['business_id'] ?? 0);
    if ($candidate > 0) {
      $businessId = $candidate;
    } elseif ($hasBusinessesTable) {
      $businessName = trim((string)($draft['business_name'] ?? ''));
      if ($businessName !== '') {
        try {
          $bizInsert = $db->prepare("
            INSERT INTO businesses (organization_id, name, created_by)
            VALUES (?, ?, ?)
          ");
          $bizInsert->execute([(int)($draft['organization'] ?? 0), $businessName, (int)($draft['created_by'] ?? 0)]);
          $businessId = (int)$db->lastInsertId();
        } catch (PDOException $e) {
          error_log("Failed to create business for deal draft: " . $e->getMessage());
          $businessId = null;
        }
      }
    }
  }

  $stmt = $db->prepare("INSERT INTO deals (
  deal_number, customer_number, customer_name, customer_phone, customer_email,
  sales_advisor_id, finance_manager_id, sales_manager_id,
  vehicle_make, vehicle_make_id, vin, vehicle_model_id, vehicle_trim_id, vehicle_model, vehicle_year, vehicle_condition, vehicle_kms, in_service_date, vehicle_colour,
  deal_type, sale_price, term, interest_rate, residual,
  documentation_fee, ppsa_fee,
  down_payment, payment_frequency, province,
  trade_info, trade_value, lien_amount, included_protections,
  organization, created_by, co_app_required, co_app_name, msrp, secure_token,
  customer_type, business_id,
  created_at
) VALUES (
  ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
  ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
  ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
  ?, ?, ?, ?, ?, ?,
  ?, ?, ?, ?,
  NOW()
)");
  $token = bin2hex(random_bytes(32));
  error_log("🔵 About to insert deal into database");
  $in_service_date = !empty($draft['in_service_date'])
    ? date('Y-m-d', strtotime($draft['in_service_date']))
    : null;
  $vehicleMakeId = !empty($draft['vehicle_make_id']) ? (int)$draft['vehicle_make_id'] : null;
  $vehicleModelId = !empty($draft['vehicle_model_id']) ? (int)$draft['vehicle_model_id'] : null;
  $vehicleTrimId = !empty($draft['vehicle_trim_id']) ? (int)$draft['vehicle_trim_id'] : null;
  $draft['co_app_required'] = !empty($draft['co_app_required']) ? 1 : 0;
  try {
    $stmt->execute([
      $draft['deal_number'],
      $draft['customer_number'],
      $draft['customer_name'],
      $draft['customer_phone'],
      $draft['customer_email'],
      $draft['sales_advisor_id'],
      $draft['finance_manager_id'],
      $draft['sales_manager_id'],
      $draft['vehicle_make'] ?? null,
      $vehicleMakeId,
      !empty($draft['vin']) ? $draft['vin'] : null,
      $vehicleModelId,
      $vehicleTrimId,
      $draft['vehicle_model'],
      $draft['vehicle_year'],
      $draft['vehicle_condition'] ?? 'Used',
      $draft['vehicle_kms'],
      $in_service_date,
      $draft['vehicle_colour'],
      $draft['deal_type'],
      $draft['sale_price'],
      $draft['term'],
      $draft['interest_rate'],
      $draft['residual'],
      $draft['documentation_fee'],
      $draft['ppsa_fee'],
      $draft['down_payment'],
      $draft['payment_frequency'],
      $draft['province'],
      $draft['trade_info'],
      $draft['trade_value'],
      $draft['lien_amount'],
      $draft['included_protections'],
      $draft['organization'],
      $draft['created_by'],
      $draft['co_app_required'],
      trim((string)($draft['co_app_name'] ?? '')) !== '' ? trim((string)$draft['co_app_name']) : null,
      $draft['msrp'],
      $token,
      $customerType,
      $businessId
    ]);
    error_log("✅ Insert successful, new deal ID: " . $db->lastInsertId());

    $id = $db->lastInsertId();
    $insertedDeal = fetch_deal_row_for_audit($db, (int)$id);
    log_deal_change_audit($db, (int)$id, 'insert', null, $insertedDeal, ['context' => 'create_deal_step3']);
    if (!empty($draft['included_accessories']) && is_array($draft['included_accessories'])) {
      $insertAccessory = $db->prepare("
        INSERT INTO deal_accessories
          (deal_id, accessory_id, accessory_code, accessory_name, base_price, sale_price, pay_method, included,
           residualizable, residual_msrp_add, contributes_to_luxury_tax)
        VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?)
      ");
      foreach ($draft['included_accessories'] as $accessory) {
        if (!is_array($accessory)) {
          continue;
        }
        $accId = (int)($accessory['id'] ?? 0);
        if ($accId <= 0) {
          continue;
        }
        $dealType = $draft['deal_type'] ?? '';
        $payMethod = 'upfront';
        if ($dealType === 'Finance') {
          $payMethod = 'finance';
        } elseif ($dealType === 'Lease') {
          $payMethod = !empty($accessory['residualizable']) ? 'cap_cost' : 'upfront';
        }
        $insertAccessory->execute([
          $id,
          $accId,
          $accessory['code'] ?? null,
          $accessory['name'] ?? ('Accessory ' . $accId),
          $accessory['price'] ?? 0,
          $accessory['price'] ?? 0,
          $payMethod,
          !empty($accessory['residualizable']) ? 1 : 0,
          $accessory['residual_msrp_add'] ?? 0,
          1,
        ]);
      }
    }
    unset($_SESSION['deal_draft']);
    header("Location: view_deal?id=$id&token=$token");
    exit;
  } catch (PDOException $e) {
    error_log("❌ Deal insert failed: " . $e->getMessage());
    $formError = 'Unable to save the deal. Please try again.';
  }
}
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <?php include __DIR__ . "/includes/head_favicon.php"; ?>
<meta charset="UTF-8">
  <title>Step 3 – Final Deal Terms</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php dealerfai_theme_head(); ?>
  <style nonce="<?= dealerfai_csp_nonce() ?>">
    body { margin: 0; font-family: "Segoe UI", sans-serif; background: #f4f6f8; color: #111111; }
    .container { max-width: 700px; margin: 40px auto; padding: 30px; background: #fff; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
    h2 { margin-top: 0; }
    label { display: block; margin-top: 15px; font-weight: bold; }
    input, select {
      width: 100%;
      padding: 10px;
      margin-top: 5px;
      border: 1px solid #ccc;
      border-radius: 4px;
    }
    .btn {
      background: #0a6280;
      color: white;
      padding: 12px 20px;
      border: none;
      border-radius: 4px;
      margin-top: 20px;
      font-size: 16px;
      cursor: pointer;
    }
    .btn:hover { opacity: 0.9; }
    .btn-back { background: #999; margin-right: 10px; }
    .section { margin-top: 30px; border-top: 1px solid #ddd; padding-top: 20px; }
    .error { background: #f8d7da; color: #721c24; padding: 10px 12px; border-radius: 6px; margin-bottom: 15px; }
    .included-row { display: grid; grid-template-columns: 20px 1fr 160px; gap: 12px; align-items: center; margin-top: 10px; }
    .included-row label { margin: 0; font-weight: 600; }
    .included-row input[type="checkbox"] { margin: 0; justify-self: center; }
    .included-price { width: 160px; }
    .included-hint { color: #555; font-size: 13px; margin: 8px 0 0; }
    .finance-only, .residual-only { display: none; }
    .nav-link-white { color: white; text-decoration: none; margin: 0 10px; font-size: 14px; }
  </style>
  <script nonce="<?= dealerfai_csp_nonce() ?>">
    function toggleFields() {
      const dealTypeEl = document.getElementById('deal_type');
      if (!dealTypeEl) return;
      const type = dealTypeEl.value;
      const financeEls = document.querySelectorAll('.finance-only');
      for (let i = 0; i < financeEls.length; i++) {
        if (type === 'Cash') financeEls[i].style.setProperty('display', 'none', 'important');
        else financeEls[i].style.setProperty('display', 'block', 'important');
      }
      const residualEl = document.querySelector('.residual-only');
      if (residualEl) {
        if (type === 'Lease') residualEl.style.setProperty('display', 'block', 'important');
        else residualEl.style.setProperty('display', 'none', 'important');
      }
      const msrp = document.getElementById('msrp');
      const residual = document.getElementById('residual');
      if (msrp) msrp.required = (type === 'Lease');
      if (residual) residual.required = (type === 'Lease');
    }
    function syncResidualFields(source) {
      const msrp = document.getElementById('msrp');
      const residual = document.getElementById('residual');
      const residualPercent = document.getElementById('residual_percent');
      if (!msrp || !residual || !residualPercent) return;
      const msrpVal = parseFloat(msrp.value);
      if (!Number.isFinite(msrpVal) || msrpVal <= 0) return;
      if (source === 'percent') {
        const percentVal = parseFloat(residualPercent.value);
        if (!Number.isFinite(percentVal)) return;
        residual.value = (msrpVal * (percentVal / 100)).toFixed(2);
      } else if (source === 'amount') {
        const residualVal = parseFloat(residual.value);
        if (!Number.isFinite(residualVal)) return;
        residualPercent.value = ((residualVal / msrpVal) * 100).toFixed(2);
      } else if (source === 'msrp') {
        if (residualPercent.value !== '') {
          syncResidualFields('percent');
        } else if (residual.value !== '') {
          syncResidualFields('amount');
        }
      }
    }
    function toggleIncludedProtections() {
      const toggle = document.getElementById('include_included_protections');
      const panel = document.getElementById('included-protections-panel');
      if (!toggle || !panel) return;
      panel.style.display = toggle.checked ? 'block' : 'none';
    }
    function toggleIncludedAccessories() {
      const toggle = document.getElementById('include_included_accessories');
      const panel = document.getElementById('included-accessories-panel');
      if (!toggle || !panel) return;
      panel.style.display = toggle.checked ? 'block' : 'none';
    }
    function bindIncludedRows() {
      const rows = document.querySelectorAll('.included-row');
      for (let i = 0; i < rows.length; i++) {
        const row = rows[i];
        const checkbox = row.querySelector('.included-checkbox');
        const priceInput = row.querySelector('.included-price');
        if (!checkbox || !priceInput) continue;
        const sync = () => {
          priceInput.disabled = !checkbox.checked;
          priceInput.required = checkbox.checked;
        };
        checkbox.addEventListener('change', sync);
        sync();
      }
    }
    window.addEventListener("DOMContentLoaded", () => {
      toggleFields();
      toggleIncludedProtections();
      toggleIncludedAccessories();
      bindIncludedRows();
      const dealTypeEl = document.getElementById('deal_type');
      if (dealTypeEl) dealTypeEl.addEventListener('change', toggleFields);
      const msrp = document.getElementById('msrp');
      const residual = document.getElementById('residual');
      const residualPercent = document.getElementById('residual_percent');
      if (msrp) msrp.addEventListener('input', () => syncResidualFields('msrp'));
      if (residual) residual.addEventListener('input', () => syncResidualFields('amount'));
      if (residualPercent) residualPercent.addEventListener('input', () => syncResidualFields('percent'));
      const toggle = document.getElementById('include_included_protections');
      if (toggle) {
        toggle.addEventListener('change', toggleIncludedProtections);
      }
      const accessoryToggle = document.getElementById('include_included_accessories');
      if (accessoryToggle) {
        accessoryToggle.addEventListener('change', toggleIncludedAccessories);
      }
    });
  </script>
</head>
<body>

<header style="background-color: <?= htmlspecialchars($theme['color']) ?>; color:white; padding:30px 40px; text-align:center; position:relative;">
  <?php if (!empty($theme['logo'])): ?>
    <img src="<?= htmlspecialchars($theme['logo']) ?>" alt="Dealer Logo" style="max-height:60px;">
  <?php else: ?>
    <h1>DealerFAI</h1>
  <?php endif; ?>
  <div style="position:absolute; right:20px; top:20px; display:flex; gap:12px; align-items:center;">
    <?php if ($isAdmin): ?>
      <a href="admin_error_alerts" style="color:#fff; font-size:14px; text-decoration:none; font-weight:bold;">Alerts<?php if ($adminAlertCount > 0): ?> <span style="display:inline-block; min-width:18px; padding:2px 8px; border-radius:999px; background:#d7263d; color:#fff; font-size:12px; font-weight:bold; text-align:center; margin-left:6px;"><?= $adminAlertCount ?></span><?php endif; ?></a>
    <?php endif; ?>
    <a href="logout" style="color:#ccc; font-size:14px; text-decoration:none;">Log Out</a>
  </div>
</header>

<nav style="background-color: <?= htmlspecialchars($theme['color']) ?>; padding:12px; text-align:center;">
  <a href="dashboard" class="nav-link-white">Dashboard</a>
  <a href="view_deals" class="nav-link-white">View Deals</a>
  <a href="create_deal" class="nav-link-white">Create Deal</a>
  <?php if (in_array('General Manager', $roles, true) || $isAdmin): ?>
    <a href="admin_tools" class="nav-link-white">Admin Tools</a>
  <?php endif; ?>
</nav>

<div class="container">
  <h2>Step 3 of 3: Deal Type & Financials</h2>
  <form method="post">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <?php if (!empty($formError)): ?>
      <div class="error"><?= htmlspecialchars($formError) ?></div>
    <?php endif; ?>

    <label for="deal_type">Deal Type</label>
    <select name="deal_type" id="deal_type" required>
      <option value="Cash">Cash</option>
      <option value="Finance">Finance</option>
      <option value="Lease">Lease</option>
    </select>

    <label for="sale_price">Sale Price</label>
    <input type="number" name="sale_price" id="sale_price" step="0.01" required>

    <label for="documentation_fee">Documentation</label>
    <input type="number" name="documentation_fee" id="documentation_fee" step="0.01" min="0">

    <div class="finance-only">
      <label for="term">Term (Months)</label>
      <input type="number" name="term" id="term" min="0">

      <label for="interest_rate">Interest Rate (%)</label>
      <input type="number" name="interest_rate" id="interest_rate" step="0.01">
    </div>

    <div class="finance-only">
      <label for="ppsa_fee">PPSA</label>
      <input type="number" name="ppsa_fee" id="ppsa_fee" step="0.01" min="0">
    </div>

    <div class="residual-only">
      <label for="msrp">MSRP (Lease Only)</label>
      <input type="number" name="msrp" id="msrp" step="0.01" min="0" value="<?= htmlspecialchars((string)($draft['msrp'] ?? '')) ?>">

      <label for="residual">Residual (Lease Only)</label>
      <input type="number" name="residual" id="residual" step="0.01" min="0" value="<?= htmlspecialchars((string)($draft['residual'] ?? '')) ?>">

      <?php
        $residualPercentValue = '';
        if (!empty($draft['msrp']) && !empty($draft['residual'])) {
          $residualPercentValue = number_format(((float)$draft['residual'] / (float)$draft['msrp']) * 100, 2, '.', '');
        }
      ?>
      <label for="residual_percent">Residual % (Lease Only)</label>
      <input type="number" name="residual_percent" id="residual_percent" step="0.01" min="0" max="100"
        value="<?= htmlspecialchars((string)$residualPercentValue) ?>">
    </div>

    <div class="finance-only">
      <label for="down_payment">Down Payment</label>
      <input type="number" name="down_payment" step="0.01">

      <label for="payment_frequency">Payment Frequency</label>
      <select name="payment_frequency">
        <option value="Monthly">Monthly</option>
        <option value="Semi-Monthly">Semi-Monthly</option>
        <option value="Bi-Weekly">Bi-Weekly</option>
        <option value="Weekly">Weekly</option>
      </select>

    </div>

    <div class="section">
      <h3>Included Protections</h3>
      <label>
        <input type="checkbox" id="include_included_protections" name="include_included_protections" value="1"<?= $includeIncluded ? ' checked' : '' ?>>
        Add included protections
      </label>
      <div id="included-protections-panel" style="display: none;">
        <p class="included-hint">Select protections already included in the deal and enter the sold price.</p>
        <?php if (empty($productCatalog)): ?>
          <p class="included-hint">No products available.</p>
        <?php else: ?>
          <?php foreach ($productCatalog as $code => $product): ?>
            <div class="included-row">
              <input type="checkbox" class="included-checkbox" name="included_protections[]" value="<?= htmlspecialchars($code) ?>"<?= in_array($code, $includedSelections, true) ? ' checked' : '' ?>>
              <label><?= htmlspecialchars($product['name']) ?></label>
              <input class="included-price" type="number" step="0.01" min="0"
                name="included_protection_prices[<?= htmlspecialchars($code) ?>]"
                value="<?= htmlspecialchars((string)($includedPrices[$code] ?? '')) ?>"
                placeholder="<?= $product['default_price'] !== null ? htmlspecialchars(number_format($product['default_price'], 2)) : '' ?>">
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <div class="section">
      <h3>Included Accessories</h3>
      <label>
        <input type="checkbox" id="include_included_accessories" name="include_included_accessories" value="1"<?= $includeIncludedAccessories ? ' checked' : '' ?>>
        Add included accessories
      </label>
      <div id="included-accessories-panel" style="display: none;">
        <p class="included-hint">Select accessories already included in the deal and enter the sold price.</p>
        <?php if (empty($accessoryCatalog)): ?>
          <p class="included-hint">No accessories available.</p>
        <?php else: ?>
          <?php foreach ($accessoryCatalog as $id => $accessory): ?>
            <div class="included-row">
              <input type="checkbox" class="included-checkbox"
                name="included_accessories[]"
                value="<?= (int)$id ?>"
                <?= in_array((string)$id, array_map('strval', $includedAccessorySelections), true) ? ' checked' : '' ?>>
              <label><?= htmlspecialchars($accessory['name']) ?></label>
              <input class="included-price" type="number" step="0.01" min="0"
                name="included_accessory_prices[<?= (int)$id ?>]"
                value="<?= htmlspecialchars((string)($includedAccessoryPrices[$id] ?? '')) ?>"
                placeholder="<?= $accessory['base_price'] !== null ? htmlspecialchars(number_format($accessory['base_price'], 2)) : '' ?>">
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <div class="section">
      <h3>Trade-In (Optional)</h3>
      <label for="trade_info">Trade Info</label>
      <input type="text" name="trade_info" id="trade_info">

      <label for="trade_value">Trade Value</label>
      <input type="number" name="trade_value" step="0.01">

      <label for="lien_amount">Lien Amount</label>
      <input type="number" name="lien_amount" step="0.01">
    </div>

    <div class="mt-30">
      <a href="create_deal_step2" class="btn btn-back">← Back</a>
      <button type="submit" class="btn">Create Deal</button>
    </div>
  </form>
</div>

</body>
</html>
