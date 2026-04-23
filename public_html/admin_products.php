<?php
include 'auth.php';
require_once __DIR__ . '/includes/theme_head.php';
include 'db.php';
require_once __DIR__ . '/helpers/session_utils.php';
require_once __DIR__ . '/helpers/db_utils.php';
require_once __DIR__ . '/includes/csrf.php';

$roles = load_session_roles();
if (!in_array('Admin', $roles, true)) {
    http_response_code(403);
    echo 'Unauthorized';
    exit;
}
$adminAlertCount = get_admin_alert_count($db);
$csrfToken = dealerfai_csrf_get_token();

$message = '';
$error = '';
$hasProductLuxuryTaxColumn = function_exists('column_exists') ? column_exists($db, 'products', 'contributes_to_luxury_tax') : false;
$hasProductGstTaxableColumn = function_exists('column_exists') ? column_exists($db, 'products', 'gst_taxable') : false;
$hasProductPstTaxableColumn = function_exists('column_exists') ? column_exists($db, 'products', 'pst_taxable') : false;
$hasProductTaxFlags = $hasProductLuxuryTaxColumn && $hasProductGstTaxableColumn && $hasProductPstTaxableColumn;
$hasProductAllowedCustomerTypesColumn = function_exists('column_exists') ? column_exists($db, 'products', 'allowed_customer_types') : false;
$hasProductRequiresQuoteColumn = function_exists('column_exists') ? column_exists($db, 'products', 'requires_quote') : false;
$hasProductDefaultPriceColumn = function_exists('column_exists') ? column_exists($db, 'products', 'default_price') : false;
$hasProductDefaultCostColumn = function_exists('column_exists') ? column_exists($db, 'products', 'default_cost') : false;
$hasProductDefaultTermColumn = function_exists('column_exists') ? column_exists($db, 'products', 'default_term') : false;
$hasProductRequiresSelectionColumn = function_exists('column_exists') ? column_exists($db, 'products', 'requires_selection') : false;
$hasProductOptionSetIdColumn = function_exists('column_exists') ? column_exists($db, 'products', 'option_set_id') : false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!dealerfai_csrf_validate($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        $error = 'Invalid request token.';
    } else {
    $action = $_POST['action'] ?? '';
    if ($action === 'add_product') {
        $code = trim($_POST['code'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $provider = trim($_POST['provider'] ?? '');
        $provider = $provider === '' ? '' : $provider;
        $allowedDealTypes = $_POST['allowed_deal_types'] ?? '';
        $allowedCustomerTypes = $_POST['allowed_customer_types'] ?? '';
        $eligibleVerticals = $_POST['eligible_verticals'] ?? '';
        if (is_array($allowedDealTypes)) {
            $allowedDealTypes = implode(', ', array_filter(array_map('trim', $allowedDealTypes)));
        } else {
            $allowedDealTypes = trim($allowedDealTypes);
        }
        if (is_array($allowedCustomerTypes)) {
            $allowedCustomerTypes = implode(', ', array_filter(array_map('trim', $allowedCustomerTypes)));
        } else {
            $allowedCustomerTypes = trim($allowedCustomerTypes);
        }
        if (is_array($eligibleVerticals)) {
            $eligibleVerticals = implode(', ', array_filter(array_map('trim', $eligibleVerticals)));
        } else {
            $eligibleVerticals = trim($eligibleVerticals);
        }
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $contributesLuxury = $hasProductLuxuryTaxColumn && isset($_POST['contributes_to_luxury_tax']) ? 1 : 0;
        $gstTaxable = $hasProductGstTaxableColumn && isset($_POST['gst_taxable']) ? 1 : 0;
        $pstTaxable = $hasProductPstTaxableColumn && isset($_POST['pst_taxable']) ? 1 : 0;
        $requiresQuote = $hasProductRequiresQuoteColumn && isset($_POST['requires_quote']) ? 1 : 0;
        $requiresSelection = $hasProductRequiresSelectionColumn && isset($_POST['requires_selection']) ? 1 : 0;
        $optionSetId = $hasProductOptionSetIdColumn ? (int)($_POST['option_set_id'] ?? 0) : 0;
        $optionSetId = $optionSetId > 0 ? $optionSetId : null;
        $defaultPriceRaw = $hasProductDefaultPriceColumn ? trim((string)($_POST['default_price'] ?? '')) : '';
        $defaultCostRaw = $hasProductDefaultCostColumn ? trim((string)($_POST['default_cost'] ?? '')) : '';
        $defaultTermRaw = $hasProductDefaultTermColumn ? trim((string)($_POST['default_term'] ?? '')) : '';
        $defaultPrice = ($defaultPriceRaw !== '' && is_numeric($defaultPriceRaw)) ? (float)$defaultPriceRaw : null;
        $defaultCost = ($defaultCostRaw !== '' && is_numeric($defaultCostRaw)) ? (float)$defaultCostRaw : null;
        $defaultTerm = ($defaultTermRaw !== '' && is_numeric($defaultTermRaw)) ? (int)$defaultTermRaw : null;
        if ($code === '' || $name === '') {
            $error = 'Code and name are required.';
        } else {
            $columns = ['code', 'name', 'category', 'provider', 'allowed_deal_types', 'eligible_verticals', 'is_active'];
            $values = [$code, $name, $category ?: null, $provider, $allowedDealTypes ?: null, $eligibleVerticals ?: null, $isActive];
            if ($hasProductRequiresQuoteColumn) {
                $columns[] = 'requires_quote';
                $values[] = $requiresQuote;
            }
            if ($hasProductDefaultPriceColumn) {
                $columns[] = 'default_price';
                $values[] = $defaultPrice;
            }
            if ($hasProductDefaultCostColumn) {
                $columns[] = 'default_cost';
                $values[] = $defaultCost;
            }
            if ($hasProductDefaultTermColumn) {
                $columns[] = 'default_term';
                $values[] = $defaultTerm;
            }
            if ($hasProductRequiresSelectionColumn) {
                $columns[] = 'requires_selection';
                $values[] = $requiresSelection;
            }
            if ($hasProductOptionSetIdColumn) {
                $columns[] = 'option_set_id';
                $values[] = $optionSetId;
            }
            if ($hasProductAllowedCustomerTypesColumn) {
                $columns[] = 'allowed_customer_types';
                $values[] = $allowedCustomerTypes ?: null;
            }
            if ($hasProductLuxuryTaxColumn) {
                $columns[] = 'contributes_to_luxury_tax';
                $values[] = $contributesLuxury;
            }
            if ($hasProductGstTaxableColumn) {
                $columns[] = 'gst_taxable';
                $values[] = $gstTaxable;
            }
            if ($hasProductPstTaxableColumn) {
                $columns[] = 'pst_taxable';
                $values[] = $pstTaxable;
            }
            $placeholders = implode(', ', array_fill(0, count($columns), '?'));
            $sql = "INSERT INTO products (" . implode(', ', $columns) . ") VALUES (" . $placeholders . ")";
            $stmt = $db->prepare($sql);
            $stmt->execute($values);
            $message = 'Product added.';
        }
    } elseif ($action === 'update_product') {
        $productId = (int)($_POST['product_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $provider = trim($_POST['provider'] ?? '');
        $provider = $provider === '' ? '' : $provider;
        $allowedDealTypes = $_POST['allowed_deal_types'] ?? '';
        $allowedCustomerTypes = $_POST['allowed_customer_types'] ?? '';
        $eligibleVerticals = $_POST['eligible_verticals'] ?? '';
        if (is_array($allowedDealTypes)) {
            $allowedDealTypes = implode(', ', array_filter(array_map('trim', $allowedDealTypes)));
        } else {
            $allowedDealTypes = trim($allowedDealTypes);
        }
        if (is_array($allowedCustomerTypes)) {
            $allowedCustomerTypes = implode(', ', array_filter(array_map('trim', $allowedCustomerTypes)));
        } else {
            $allowedCustomerTypes = trim($allowedCustomerTypes);
        }
        if (is_array($eligibleVerticals)) {
            $eligibleVerticals = implode(', ', array_filter(array_map('trim', $eligibleVerticals)));
        } else {
            $eligibleVerticals = trim($eligibleVerticals);
        }
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $contributesLuxury = $hasProductLuxuryTaxColumn && isset($_POST['contributes_to_luxury_tax']) ? 1 : 0;
        $gstTaxable = $hasProductGstTaxableColumn && isset($_POST['gst_taxable']) ? 1 : 0;
        $pstTaxable = $hasProductPstTaxableColumn && isset($_POST['pst_taxable']) ? 1 : 0;
        $requiresQuote = $hasProductRequiresQuoteColumn && isset($_POST['requires_quote']) ? 1 : 0;
        $requiresSelection = $hasProductRequiresSelectionColumn && isset($_POST['requires_selection']) ? 1 : 0;
        $optionSetId = $hasProductOptionSetIdColumn ? (int)($_POST['option_set_id'] ?? 0) : 0;
        $optionSetId = $optionSetId > 0 ? $optionSetId : null;
        $defaultPriceRaw = $hasProductDefaultPriceColumn ? trim((string)($_POST['default_price'] ?? '')) : '';
        $defaultCostRaw = $hasProductDefaultCostColumn ? trim((string)($_POST['default_cost'] ?? '')) : '';
        $defaultTermRaw = $hasProductDefaultTermColumn ? trim((string)($_POST['default_term'] ?? '')) : '';
        $defaultPrice = ($defaultPriceRaw !== '' && is_numeric($defaultPriceRaw)) ? (float)$defaultPriceRaw : null;
        $defaultCost = ($defaultCostRaw !== '' && is_numeric($defaultCostRaw)) ? (float)$defaultCostRaw : null;
        $defaultTerm = ($defaultTermRaw !== '' && is_numeric($defaultTermRaw)) ? (int)$defaultTermRaw : null;
        if ($productId > 0 && $name !== '') {
            $sets = [
                'name = ?',
                'category = ?',
                'provider = ?',
                'allowed_deal_types = ?',
                'eligible_verticals = ?',
                'is_active = ?',
            ];
            $params = [
                $name,
                $category ?: null,
                $provider,
                $allowedDealTypes ?: null,
                $eligibleVerticals ?: null,
                $isActive,
            ];
            if ($hasProductRequiresQuoteColumn) {
                $sets[] = 'requires_quote = ?';
                $params[] = $requiresQuote;
            }
            if ($hasProductDefaultPriceColumn) {
                $sets[] = 'default_price = ?';
                $params[] = $defaultPrice;
            }
            if ($hasProductDefaultCostColumn) {
                $sets[] = 'default_cost = ?';
                $params[] = $defaultCost;
            }
            if ($hasProductDefaultTermColumn) {
                $sets[] = 'default_term = ?';
                $params[] = $defaultTerm;
            }
            if ($hasProductRequiresSelectionColumn) {
                $sets[] = 'requires_selection = ?';
                $params[] = $requiresSelection;
            }
            if ($hasProductOptionSetIdColumn) {
                $sets[] = 'option_set_id = ?';
                $params[] = $optionSetId;
            }
            if ($hasProductAllowedCustomerTypesColumn) {
                $sets[] = 'allowed_customer_types = ?';
                $params[] = $allowedCustomerTypes ?: null;
            }
            if ($hasProductLuxuryTaxColumn) {
                $sets[] = 'contributes_to_luxury_tax = ?';
                $params[] = $contributesLuxury;
            }
            if ($hasProductGstTaxableColumn) {
                $sets[] = 'gst_taxable = ?';
                $params[] = $gstTaxable;
            }
            if ($hasProductPstTaxableColumn) {
                $sets[] = 'pst_taxable = ?';
                $params[] = $pstTaxable;
            }
            $params[] = $productId;
            $stmt = $db->prepare("UPDATE products SET " . implode(', ', $sets) . " WHERE id = ?");
            $stmt->execute($params);
            $message = 'Product updated.';
        } else {
            $error = 'Product name is required.';
        }
    } elseif ($action === 'deactivate_product') {
        $productId = (int)($_POST['product_id'] ?? 0);
        if ($productId > 0) {
            $stmt = $db->prepare("UPDATE products SET is_active = 0 WHERE id = ?");
            $stmt->execute([$productId]);
            $message = 'Product deactivated.';
        }
    } elseif ($action === 'delete_product') {
        $productId = (int)($_POST['product_id'] ?? 0);
        if ($productId > 0) {
            $variantStmt = $db->prepare("SELECT code, provider FROM products WHERE id = ?");
            $variantStmt->execute([$productId]);
            $variant = $variantStmt->fetch(PDO::FETCH_ASSOC);
            if ($variant) {
                $productCode = (string)($variant['code'] ?? '');
                $provider = trim((string)($variant['provider'] ?? ''));
                try {
                    $db->beginTransaction();
                    $deleteOverrides = $db->prepare("DELETE FROM product_organization_overrides WHERE product_id = ?");
                    $deleteOverrides->execute([$productId]);
                    $deleteAvailabilityById = $db->prepare("
                        DELETE FROM product_availability
                        WHERE product_id = ?
                    ");
                    $deleteAvailabilityById->execute([$productId]);
                    $deleteAvailabilityByCode = $db->prepare("
                        DELETE FROM product_availability
                        WHERE product_id IS NULL AND product_code = ? AND provider = ?
                    ");
                    $deleteAvailabilityByCode->execute([$productCode, $provider]);
                    $deleteProduct = $db->prepare("DELETE FROM products WHERE id = ?");
                    $deleteProduct->execute([$productId]);
                    $db->commit();
                    $message = 'Product deleted.';
                } catch (PDOException $e) {
                    $db->rollBack();
                    $error = 'Failed to delete product.';
                }
            }
        }
    } elseif ($action === 'add_availability') {
        $productVariant = trim($_POST['product_variant'] ?? '');
        $productCode = trim($_POST['product_code'] ?? '');
        $provider = trim($_POST['provider'] ?? '');
        $vehicleCondition = strtolower(trim((string)($_POST['vehicle_condition'] ?? 'any')));
        if (!in_array($vehicleCondition, ['any', 'new', 'used'], true)) {
            $vehicleCondition = 'any';
        }
        if ($productVariant !== '') {
            $parts = explode('||', $productVariant, 2);
            $productCode = trim($parts[0] ?? '');
            $provider = trim($parts[1] ?? '');
        }
        if (isset($_POST['all_providers'])) {
            $provider = '';
        }
        $scopeType = $_POST['scope_type'] ?? '';
        $isEnabled = isset($_POST['is_enabled']) ? 1 : 0;
        $scopeValue = '';
        if ($scopeType === 'global') {
            $scopeValue = '';
        } elseif ($scopeType === 'vertical') {
            $scopeValue = trim($_POST['vertical_value'] ?? '');
        } elseif ($scopeType === 'org') {
            $scopeValue = (string)(int)($_POST['org_value'] ?? 0);
        } elseif ($scopeType === 'store') {
            $scopeValue = (string)(int)($_POST['store_value'] ?? 0);
        }
        if ($productCode === '' || $scopeType === '') {
            $error = 'Product code and scope are required.';
        } elseif ($scopeType !== 'global' && $scopeValue === '') {
            $error = 'Scope value is required for this scope.';
        } else {
            // Avoid duplicate rows for code/provider rules (product_id IS NULL).
            $deleteStmt = $db->prepare("
                DELETE FROM product_availability
                WHERE product_id IS NULL
                  AND product_code = ?
                  AND provider = ?
                  AND scope_type = ?
                  AND scope_value = ?
            ");
            $deleteStmt->execute([$productCode, $provider, $scopeType, $scopeValue]);
            $stmt = $db->prepare("
                INSERT INTO product_availability (product_code, provider, scope_type, scope_value, vehicle_condition, is_enabled)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$productCode, $provider, $scopeType, $scopeValue, $vehicleCondition, $isEnabled]);
            header('Location: admin_products.php?saved=1');
            exit;
        }
    } elseif ($action === 'update_availability') {
        $productCode = trim($_POST['product_code'] ?? '');
        $provider = trim($_POST['provider'] ?? '');
        $scopeType = $_POST['scope_type'] ?? '';
        $scopeValue = trim($_POST['scope_value'] ?? '');
        $isEnabled = isset($_POST['is_enabled']) ? 1 : 0;
        $vehicleCondition = strtolower(trim((string)($_POST['vehicle_condition'] ?? 'any')));
        if (!in_array($vehicleCondition, ['any', 'new', 'used'], true)) {
            $vehicleCondition = 'any';
        }
        if ($productCode !== '' && $scopeType !== '') {
            $stmt = $db->prepare("
                UPDATE product_availability
                SET is_enabled = ?, vehicle_condition = ?
                WHERE product_code = ? AND provider = ? AND scope_type = ? AND scope_value = ?
            ");
            $stmt->execute([$isEnabled, $vehicleCondition, $productCode, $provider, $scopeType, $scopeValue]);
            header('Location: admin_products.php?saved=1');
            exit;
        }
    } elseif ($action === 'delete_availability') {
        $productCode = trim($_POST['product_code'] ?? '');
        $provider = trim($_POST['provider'] ?? '');
        $scopeType = $_POST['scope_type'] ?? '';
        $scopeValue = trim($_POST['scope_value'] ?? '');
        if ($productCode !== '' && $scopeType !== '') {
            $stmt = $db->prepare("
                DELETE FROM product_availability
                WHERE product_code = ? AND provider = ? AND scope_type = ? AND scope_value = ?
            ");
            $stmt->execute([$productCode, $provider, $scopeType, $scopeValue]);
            header('Location: admin_products.php?saved=1');
            exit;
        }
    } elseif (in_array($action, ['add_cap_exempt_override', 'update_cap_exempt_override'], true)) {
        $productId = (int)($_POST['product_id'] ?? 0);
        $orgId = (int)($_POST['org_id'] ?? 0);
        $leaseValRaw = trim((string)($_POST['lease_cap_exempt_override'] ?? ''));
        $financeValRaw = trim((string)($_POST['finance_cap_exempt_override'] ?? ''));
        $leaseVal = $leaseValRaw === '' ? null : (int)$leaseValRaw;
        $financeVal = $financeValRaw === '' ? null : (int)$financeValRaw;
        if ($productId > 0 && $orgId > 0) {
            $stmt = $db->prepare("
                INSERT INTO product_organization_overrides
                    (product_id, organization_id, lease_cap_exempt_override, finance_cap_exempt_override)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    lease_cap_exempt_override = VALUES(lease_cap_exempt_override),
                    finance_cap_exempt_override = VALUES(finance_cap_exempt_override)
            ");
            $stmt->execute([$productId, $orgId, $leaseVal, $financeVal]);
            header('Location: admin_products.php?saved=1');
            exit;
        } else {
            $error = 'Select a product variant and organization for cap exempt overrides.';
        }
    } elseif ($action === 'delete_cap_exempt_override') {
        $productId = (int)($_POST['product_id'] ?? 0);
        $orgId = (int)($_POST['org_id'] ?? 0);
        if ($productId > 0 && $orgId > 0) {
            $stmt = $db->prepare("
                UPDATE product_organization_overrides
                SET lease_cap_exempt_override = NULL, finance_cap_exempt_override = NULL
                WHERE product_id = ? AND organization_id = ?
            ");
            $stmt->execute([$productId, $orgId]);
            header('Location: admin_products.php?saved=1');
            exit;
        }
    }
    }
}

$verticalValues = [];
$verticalRows = $db->query("SELECT eligible_verticals FROM products WHERE eligible_verticals IS NOT NULL AND TRIM(eligible_verticals) <> ''")->fetchAll(PDO::FETCH_COLUMN);
foreach ($verticalRows as $value) {
    $parts = array_filter(array_map('trim', explode(',', (string)$value)));
    foreach ($parts as $part) {
        $verticalValues[$part] = true;
    }
}
$defaultVerticals = ['Powersport', 'RV'];
foreach ($defaultVerticals as $vertical) {
    $verticalValues[$vertical] = true;
}
ksort($verticalValues);

$dealTypeValues = [];
$dealTypeRows = $db->query("SELECT allowed_deal_types FROM products WHERE allowed_deal_types IS NOT NULL AND TRIM(allowed_deal_types) <> ''")->fetchAll(PDO::FETCH_COLUMN);
foreach ($dealTypeRows as $value) {
    $parts = array_filter(array_map('trim', explode(',', (string)$value)));
    foreach ($parts as $part) {
        $dealTypeValues[$part] = true;
    }
}
ksort($dealTypeValues);

$categoryValues = [];
$categoryRows = $db->query("SELECT category FROM products WHERE category IS NOT NULL AND TRIM(category) <> ''")->fetchAll(PDO::FETCH_COLUMN);
foreach ($categoryRows as $value) {
    $categoryValues[trim((string)$value)] = true;
}
ksort($categoryValues);

function parse_csv_values($value) {
    if ($value === null) {
        return [];
    }
    $parts = array_filter(array_map('trim', explode(',', (string)$value)));
    return array_values(array_unique($parts));
}

$productSelect = "id, code, name, category, provider, allowed_deal_types, eligible_verticals, is_active";
if ($hasProductAllowedCustomerTypesColumn) {
    $productSelect .= ", allowed_customer_types";
}
if ($hasProductRequiresQuoteColumn) {
    $productSelect .= ", requires_quote";
}
if ($hasProductDefaultPriceColumn) {
    $productSelect .= ", default_price";
}
if ($hasProductDefaultCostColumn) {
    $productSelect .= ", default_cost";
}
if ($hasProductDefaultTermColumn) {
    $productSelect .= ", default_term";
}
if ($hasProductRequiresSelectionColumn) {
    $productSelect .= ", requires_selection";
}
if ($hasProductOptionSetIdColumn) {
    $productSelect .= ", option_set_id";
}
if ($hasProductLuxuryTaxColumn) {
    $productSelect .= ", contributes_to_luxury_tax";
}
if ($hasProductGstTaxableColumn) {
    $productSelect .= ", gst_taxable";
}
if ($hasProductPstTaxableColumn) {
    $productSelect .= ", pst_taxable";
}
$productRows = [];
try {
    $productRows = $db->query("SELECT {$productSelect} FROM products ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $productRows = [];
    $error = $error !== '' ? $error : 'Unable to load products. If you recently added product tax columns, run the migration on the database.';
}

$productsByCode = [];
$productNameByCodeProvider = [];
foreach ($productRows as $row) {
    $code = $row['code'] ?? '';
    if ($code === '') {
        continue;
    }
    if (!isset($productsByCode[$code])) {
        $productsByCode[$code] = [
            'code' => $code,
            'name' => $row['name'] ?? $code,
            'providers' => [],
            'variants' => [],
        ];
    }
    if (!empty($row['provider'])) {
        $productsByCode[$code]['providers'][$row['provider']] = true;
    }
    $productsByCode[$code]['variants'][] = $row;
    $providerKey = trim((string)($row['provider'] ?? ''));
    $productNameByCodeProvider[$code][$providerKey] = $row['name'] ?? $code;
}

$availabilityRows = $db->query("
    SELECT product_code, provider, scope_type, scope_value, vehicle_condition, is_enabled
    FROM product_availability
    ORDER BY product_code ASC, provider ASC, scope_type ASC, scope_value ASC
")->fetchAll(PDO::FETCH_ASSOC);
$availabilityMap = [];
foreach ($availabilityRows as $row) {
    $availabilityMap[$row['product_code']][] = $row;
}
$orgNameMap = [];
$orgRows = $db->query("SELECT id, name, org_kind FROM organizations ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
foreach ($orgRows as $orgRow) {
    $orgNameMap[(string)$orgRow['id']] = $orgRow['name'] ?? '';
}
$capOverrideRows = $db->query("
    SELECT po.product_id, po.organization_id, po.lease_cap_exempt_override, po.finance_cap_exempt_override,
           p.code, p.name, p.provider,
           o.name AS org_name, o.org_kind
    FROM product_organization_overrides po
    JOIN products p ON p.id = po.product_id
    JOIN organizations o ON o.id = po.organization_id
    WHERE po.lease_cap_exempt_override IS NOT NULL
       OR po.finance_cap_exempt_override IS NOT NULL
    ORDER BY o.name ASC, p.name ASC, p.provider ASC
")->fetchAll(PDO::FETCH_ASSOC);
$productCodes = array_keys($productsByCode);
sort($productCodes);
$providerValues = [];
foreach ($productsByCode as $product) {
    foreach (array_keys($product['providers']) as $providerValue) {
        if ($providerValue !== '') {
            $providerValues[$providerValue] = true;
        }
    }
}
$providerValues = array_keys($providerValues);
sort($providerValues);
$saved = isset($_GET['saved']);
if ($saved && $message === '') {
    $message = 'Changes saved.';
}
?>
<!DOCTYPE html>
<html>
<head>
  <?php include __DIR__ . "/includes/head_favicon.php"; ?>
<meta charset="UTF-8">
  <title>Admin - All Products</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php dealerfai_theme_head(); ?>
  <style nonce="<?= dealerfai_csp_nonce() ?>">
    body { font-family: Arial, sans-serif; background: #f4f6f8; margin: 0; padding: 0; }
    header { background: #0066cc; color: white; padding: 20px; text-align: center; }
    nav { background: #0066cc; padding: 12px; text-align: center; }
    nav a { color: white; margin: 0 20px; text-decoration: none; font-weight: bold; }
    .container { max-width: 1200px; margin: 20px auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
    h2 { margin-top: 0; }
    table { width: 100%; border-collapse: collapse; margin-top: 16px; }
    th, td { padding: 10px; border-bottom: 1px solid #ddd; vertical-align: top; text-align: left; }
    th { background: #f1f4f8; }
    input[type="text"], select, input[type="number"] { padding: 6px; border-radius: 4px; border: 1px solid #ccc; }
    .btn { background: #0066cc; color: white; padding: 8px 14px; border: none; border-radius: 4px; cursor: pointer; }
    .btn:hover { background: #094c63; }
    .muted { color: #667085; font-size: 0.9rem; margin-top: 4px; }
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
    .success { color: green; margin-top: 10px; }
    .error { color: #c0392b; margin-top: 10px; }
    details summary { cursor: pointer; font-weight: bold; }
  </style>
</head>
<body>
  <header class="pos-relative">
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
    <h2>All Products</h2>
    <p class="muted">Manage products and control where they are available. If no availability rule exists, products are enabled by default.</p>

    <?php if ($message): ?>
      <p class="success">✅ <?= htmlspecialchars($message) ?></p>
    <?php endif; ?>
    <?php if ($error): ?>
      <p class="error">⚠️ <?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

	    <h3 class="mb-6">Add Product</h3>
	    <?php if (!$hasProductTaxFlags): ?>
	      <p class="muted">Note: Product tax flags (Luxury/GST/PST) are not available until your database schema is updated.</p>
	    <?php endif; ?>
      <?php if ($hasProductRequiresQuoteColumn): ?>
        <p class="muted">Tip: For API-quoted products, enable <strong>Quote/API</strong> and leave default price as 0.00 (or blank) so stale pricing is not shown when no quote is available.</p>
      <?php endif; ?>
	    <form method="post" class="mt-12">
	      <input type="hidden" name="action" value="add_product">
	      <table>
	        <thead>
	          <tr>
	            <th class="w-14">Code</th>
	            <th class="w-18">Name</th>
	            <th class="w-14">Category</th>
	            <th class="w-14">Provider</th>
              <?php if ($hasProductDefaultPriceColumn): ?>
                <th class="w-10">Price</th>
              <?php endif; ?>
              <?php if ($hasProductDefaultTermColumn): ?>
                <th class="w-10">Term</th>
              <?php endif; ?>
              <?php if ($hasProductRequiresQuoteColumn): ?>
                <th class="w-6">Quote/API</th>
              <?php endif; ?>
              <?php if ($hasProductRequiresSelectionColumn): ?>
                <th class="w-6">Select</th>
              <?php endif; ?>
              <?php if ($hasProductOptionSetIdColumn): ?>
                <th class="w-8">Option Set</th>
              <?php endif; ?>
	            <th class="w-18">Allowed Deal Types</th>
              <?php if ($hasProductAllowedCustomerTypesColumn): ?>
                <th class="w-18">Allowed Customer Types</th>
              <?php endif; ?>
	            <th class="w-18">Eligible Verticals</th>
	            <?php if ($hasProductTaxFlags): ?>
	              <th class="w-4">Luxury</th>
	              <th class="w-4">GST</th>
	              <th class="w-4">PST</th>
	            <?php endif; ?>
	            <th class="w-4">Active</th>
	            <th class="w-10">Action</th>
	          </tr>
	        </thead>
        <tbody>
          <tr>
            <td><input type="text" name="code" required></td>
            <td><input type="text" name="name" required></td>
            <td>
              <select name="category">
                <option value="">Select category</option>
                <?php foreach (array_keys($categoryValues) as $categoryOption): ?>
                  <option value="<?= htmlspecialchars($categoryOption) ?>"><?= htmlspecialchars($categoryOption) ?></option>
                <?php endforeach; ?>
              </select>
            </td>
            <td>
              <input type="text" name="provider" list="provider_list" class="js-quick-pick-input" data-quick-list="provider_list">
              <div class="quick-picks js-quick-picks"></div>
            </td>
            <?php if ($hasProductDefaultPriceColumn): ?>
              <td><input type="number" name="default_price" step="0.01" min="0" placeholder="0.00" class="w-110px"></td>
            <?php endif; ?>
            <?php if ($hasProductDefaultTermColumn): ?>
              <td><input type="number" name="default_term" min="0" placeholder="Months" class="w-90px"></td>
            <?php endif; ?>
            <?php if ($hasProductRequiresQuoteColumn): ?>
              <td class="text-center"><input type="checkbox" name="requires_quote" value="1"></td>
            <?php endif; ?>
            <?php if ($hasProductRequiresSelectionColumn): ?>
              <td class="text-center"><input type="checkbox" name="requires_selection" value="1"></td>
            <?php endif; ?>
            <?php if ($hasProductOptionSetIdColumn): ?>
              <td><input type="number" name="option_set_id" min="0" placeholder="(id)" class="w-90px"></td>
            <?php endif; ?>
            <td>
              <select name="allowed_deal_types[]" multiple size="3">
                <?php foreach (array_keys($dealTypeValues) as $dealType): ?>
                  <option value="<?= htmlspecialchars($dealType) ?>"><?= htmlspecialchars($dealType) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="muted">Hold Ctrl/Cmd to select multiple.</div>
            </td>
            <?php if ($hasProductAllowedCustomerTypesColumn): ?>
              <td>
                <?php $customerTypeOptions = ['personal' => 'Personal', 'professional' => 'Professional', 'commercial' => 'Commercial']; ?>
                <select name="allowed_customer_types[]" multiple size="3">
                  <?php foreach ($customerTypeOptions as $key => $label): ?>
                    <option value="<?= htmlspecialchars($key) ?>" selected><?= htmlspecialchars($label) ?></option>
                  <?php endforeach; ?>
                </select>
                <div class="muted">Hold Ctrl/Cmd to select multiple.</div>
              </td>
            <?php endif; ?>
	            <td>
	              <select name="eligible_verticals[]" multiple size="3">
	                <?php foreach (array_keys($verticalValues) as $vertical): ?>
	                  <option value="<?= htmlspecialchars($vertical) ?>"><?= htmlspecialchars($vertical) ?></option>
	                <?php endforeach; ?>
	              </select>
	              <div class="muted">Hold Ctrl/Cmd to select multiple.</div>
	            </td>
	            <?php if ($hasProductTaxFlags): ?>
	              <td><input type="checkbox" name="contributes_to_luxury_tax" value="1"></td>
	              <td><input type="checkbox" name="gst_taxable" value="1" checked></td>
	              <td><input type="checkbox" name="pst_taxable" value="1" checked></td>
	            <?php endif; ?>
	            <td><input type="checkbox" name="is_active" value="1" checked></td>
	            <td><button type="submit" class="btn">Add</button></td>
	          </tr>
	        </tbody>
	      </table>
	    </form>

    <h3 class="mb-6-mt-20">Availability Rules (Exceptions)</h3>
    <p class="muted">Rules are only needed when you want to override the default. For example: disable a product for one store or enable a provider only for a group.</p>
    <div class="info-box">
    <form method="post">
      <input type="hidden" name="action" value="add_availability">
      <table>
        <thead>
          <tr>
            <th class="w-20">Product Code</th>
            <th class="w-20">Provider</th>
            <th class="w-20">Scope</th>
            <th class="w-18">Target</th>
            <th class="w-12">Condition</th>
            <th class="w-6">Enabled</th>
            <th class="w-4">Action</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>
              <select name="product_code" required>
                <option value="">Select product code</option>
                <?php foreach ($productCodes as $code): ?>
                  <option value="<?= htmlspecialchars($code) ?>"><?= htmlspecialchars($code) ?></option>
                <?php endforeach; ?>
              </select>
            </td>
            <td>
              <input type="text" name="provider" id="availability_provider" list="provider_list" placeholder="All providers">
              <datalist id="provider_list">
                <?php foreach ($providerValues as $providerValue): ?>
                  <option value="<?= htmlspecialchars($providerValue) ?>"></option>
                <?php endforeach; ?>
              </datalist>
              <div class="quick-picks js-quick-picks"></div>
              <div class="muted" class="mt-6">
                <label><input type="checkbox" name="all_providers" value="1" id="all_providers"> Apply to all providers</label>
              </div>
            </td>
            <td>
              <select name="scope_type" id="availability_scope" required>
                <option value="global">Global</option>
                <option value="vertical">Vertical</option>
                <option value="org">Organization</option>
                <option value="store">Store</option>
              </select>
            </td>
            <td>
              <input type="text" name="scope_value" id="availability_scope_value" placeholder="Org/Store ID or Vertical label">
            </td>
            <td>
              <select name="vehicle_condition">
                <option value="any" selected>Any</option>
                <option value="new">New</option>
                <option value="used">Used</option>
              </select>
            </td>
            <td><input type="checkbox" name="is_enabled" value="1" checked></td>
            <td><button type="submit" class="btn">Save</button></td>
          </tr>
        </tbody>
      </table>
    </form>
    <div class="muted" class="mt-8">
      Tips: Leave Provider blank for all. Global needs no target. Org/Store targets use the organization ID.
    </div>
    </div>

    <h4 style="margin-top:18px;">Existing Rules</h4>
    <div class="muted" class="mb-6">Grouped by product code to keep things readable.</div>
    <?php if (empty($availabilityMap)): ?>
      <div class="muted">No availability rules yet.</div>
    <?php else: ?>
      <details style="margin-bottom:12px; background:#f8fafc; border:1px solid #e5e7eb; border-radius:8px; padding:10px;">
        <summary class="cursor-pointer-bold">Show availability rules by protection</summary>
        <?php foreach ($availabilityMap as $code => $rows): ?>
          <?php
            $productName = $productsByCode[$code]['name'] ?? $code;
          ?>
          <details style="margin:10px 0; background:#fff; border:1px solid #e5e7eb; border-radius:8px; padding:10px;">
            <summary class="cursor-pointer-bold">
              <?= htmlspecialchars($productName) ?> — <?= htmlspecialchars($code) ?> (<?= count($rows) ?> rule<?= count($rows) === 1 ? '' : 's' ?>)
            </summary>
            <table class="mt-10">
      <thead>
        <tr>
          <th style="width:25%;">Product</th>
          <th class="w-20">Provider</th>
          <th style="width:15%;">Scope</th>
          <th class="w-20">Target</th>
          <th class="w-10">Condition</th>
          <th class="w-10">Enabled</th>
          <th class="w-10">Action</th>
        </tr>
      </thead>
      <tbody>
          <?php foreach ($rows as $row): ?>
            <?php
              $ruleProvider = trim((string)($row['provider'] ?? ''));
              $scopeValue = (string)($row['scope_value'] ?? '');
              $scopeType = (string)($row['scope_type'] ?? '');
              $scopeLabel = $scopeValue !== '' ? $scopeValue : '—';
              if (in_array($scopeType, ['org', 'store'], true) && $scopeValue !== '') {
                  $orgName = $orgNameMap[$scopeValue] ?? '';
                  if ($orgName !== '') {
                      $scopeLabel = $orgName . ' (#' . $scopeValue . ')';
                  }
              }
              $ruleProductName = $productNameByCodeProvider[$code][$ruleProvider]
                  ?? $productNameByCodeProvider[$code]['']
                  ?? $productName
                  ?? $code;
              $isEnabled = (int)($row['is_enabled'] ?? 0) === 1;
              $ruleCondition = strtolower(trim((string)($row['vehicle_condition'] ?? 'any')));
              if (!in_array($ruleCondition, ['any', 'new', 'used'], true)) {
                  $ruleCondition = 'any';
              }
            ?>
            <tr>
              <td><?= htmlspecialchars($ruleProductName) ?></td>
              <td><?= htmlspecialchars($ruleProvider !== '' ? $ruleProvider : 'All providers') ?></td>
              <td><?= htmlspecialchars($scopeType) ?></td>
              <td><?= htmlspecialchars($scopeLabel) ?></td>
              <td>
                <form method="post" class="d-inline">
                  <input type="hidden" name="action" value="update_availability">
                  <input type="hidden" name="product_code" value="<?= htmlspecialchars($row['product_code'] ?? '') ?>">
                  <input type="hidden" name="provider" value="<?= htmlspecialchars($ruleProvider) ?>">
                  <input type="hidden" name="scope_type" value="<?= htmlspecialchars($scopeType) ?>">
                  <input type="hidden" name="scope_value" value="<?= htmlspecialchars($scopeValue) ?>">
                  <select name="vehicle_condition">
                    <option value="any" <?= $ruleCondition === 'any' ? 'selected' : '' ?>>Any</option>
                    <option value="new" <?= $ruleCondition === 'new' ? 'selected' : '' ?>>New</option>
                    <option value="used" <?= $ruleCondition === 'used' ? 'selected' : '' ?>>Used</option>
                  </select>
              </td>
              <td>
                  <input type="checkbox" name="is_enabled" value="1" <?= $isEnabled ? 'checked' : '' ?>>
                  <button type="submit" class="btn" style="margin-left:6px;">Save</button>
                </form>
              </td>
              <td>
                <form method="post" class="d-inline">
                  <input type="hidden" name="action" value="delete_availability">
                  <input type="hidden" name="product_code" value="<?= htmlspecialchars($row['product_code'] ?? '') ?>">
                  <input type="hidden" name="provider" value="<?= htmlspecialchars($ruleProvider) ?>">
                  <input type="hidden" name="scope_type" value="<?= htmlspecialchars($scopeType) ?>">
                  <input type="hidden" name="scope_value" value="<?= htmlspecialchars($scopeValue) ?>">
                  <button type="submit" class="btn" class="bg-danger" onclick="return confirm('Delete this availability rule?');">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
    </table>
          </details>
        <?php endforeach; ?>
      </details>
    <?php endif; ?>

    <h3 style="margin-bottom:6px; margin-top:26px;">Cap Exempt Overrides (Org/Store)</h3>
    <p class="muted">Override lease/finance cap exemptions for specific stores or groups without changing the global product.</p>
    <div class="info-box">
      <form method="post">
        <input type="hidden" name="action" value="add_cap_exempt_override">
        <table>
          <thead>
            <tr>
              <th style="width:32%;">Product Variant</th>
              <th class="w-26">Organization</th>
              <th class="w-18">Lease Cap Exempt</th>
              <th class="w-18">Finance Cap Exempt</th>
              <th class="w-6">Action</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td>
                <select name="product_id" required>
                  <option value="">Select product variant</option>
                  <?php foreach ($productRows as $variant): ?>
                    <?php
                      $labelParts = [trim((string)($variant['name'] ?? '')), trim((string)($variant['provider'] ?? ''))];
                      $labelParts = array_values(array_filter($labelParts, fn($v) => $v !== ''));
                      $label = implode(' — ', $labelParts);
                      $label = $label !== '' ? $label : ($variant['code'] ?? 'Product');
                      $label .= $variant['code'] ? ' (' . $variant['code'] . ')' : '';
                    ?>
                    <option value="<?= (int)$variant['id'] ?>"><?= htmlspecialchars($label) ?></option>
                  <?php endforeach; ?>
                </select>
              </td>
              <td>
                <select name="org_id" required>
                  <option value="">Select organization</option>
                  <?php foreach ($orgRows as $orgRow): ?>
                    <option value="<?= (int)$orgRow['id'] ?>">
                      <?= htmlspecialchars($orgRow['name'] ?? '') ?> <?= ($orgRow['org_kind'] ?? 'store') === 'group' ? '(Group)' : '(Store)' ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </td>
              <td>
                <select name="lease_cap_exempt_override">
                  <option value="">Default</option>
                  <option value="1">Exempt</option>
                  <option value="0">Not Exempt</option>
                </select>
              </td>
              <td>
                <select name="finance_cap_exempt_override">
                  <option value="">Default</option>
                  <option value="1">Exempt</option>
                  <option value="0">Not Exempt</option>
                </select>
              </td>
              <td><button type="submit" class="btn">Save</button></td>
            </tr>
          </tbody>
        </table>
      </form>
    </div>

    <details style="margin-top:18px; background:#f8fafc; border:1px solid #e5e7eb; border-radius:8px; padding:10px;">
      <summary class="cursor-pointer-bold">Existing Cap Overrides</summary>
      <?php if (empty($capOverrideRows)): ?>
        <div class="muted" class="mt-8">No cap overrides set yet.</div>
      <?php else: ?>
        <table class="mt-10">
          <thead>
            <tr>
              <th class="w-26">Organization</th>
              <th class="w-30">Product Variant</th>
              <th class="w-18">Lease Cap Exempt</th>
              <th class="w-18">Finance Cap Exempt</th>
              <th class="w-8">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($capOverrideRows as $row): ?>
              <?php
                $capFormId = 'cap-override-' . (int)$row['product_id'] . '-' . (int)$row['organization_id'];
                $orgLabel = trim((string)($row['org_name'] ?? ''));
                if ($orgLabel !== '') {
                    $orgLabel .= ' (#' . (int)$row['organization_id'] . ')';
                    if (($row['org_kind'] ?? 'store') === 'group') {
                        $orgLabel .= ' (Group)';
                    }
                } else {
                    $orgLabel = 'Org #' . (int)$row['organization_id'];
                }
                $variantLabelParts = [trim((string)($row['name'] ?? '')), trim((string)($row['provider'] ?? ''))];
                $variantLabelParts = array_values(array_filter($variantLabelParts, fn($v) => $v !== ''));
                $variantLabel = implode(' — ', $variantLabelParts);
                $variantLabel = $variantLabel !== '' ? $variantLabel : ($row['code'] ?? 'Product');
                $variantLabel .= $row['code'] ? ' (' . $row['code'] . ')' : '';
                $leaseVal = $row['lease_cap_exempt_override'];
                $financeVal = $row['finance_cap_exempt_override'];
              ?>
              <tr>
                <td><?= htmlspecialchars($orgLabel) ?></td>
                <td><?= htmlspecialchars($variantLabel) ?></td>
                <td>
                  <select name="lease_cap_exempt_override" form="<?= htmlspecialchars($capFormId) ?>">
                    <option value="" <?= $leaseVal === null ? 'selected' : '' ?>>Default</option>
                    <option value="1" <?= $leaseVal !== null && (int)$leaseVal === 1 ? 'selected' : '' ?>>Exempt</option>
                    <option value="0" <?= $leaseVal !== null && (int)$leaseVal === 0 ? 'selected' : '' ?>>Not Exempt</option>
                  </select>
                </td>
                <td>
                  <select name="finance_cap_exempt_override" form="<?= htmlspecialchars($capFormId) ?>">
                    <option value="" <?= $financeVal === null ? 'selected' : '' ?>>Default</option>
                    <option value="1" <?= $financeVal !== null && (int)$financeVal === 1 ? 'selected' : '' ?>>Exempt</option>
                    <option value="0" <?= $financeVal !== null && (int)$financeVal === 0 ? 'selected' : '' ?>>Not Exempt</option>
                  </select>
                </td>
                <td>
                  <form method="post" id="<?= htmlspecialchars($capFormId) ?>" class="d-inline">
                    <input type="hidden" name="action" value="update_cap_exempt_override">
                    <input type="hidden" name="product_id" value="<?= (int)$row['product_id'] ?>">
                    <input type="hidden" name="org_id" value="<?= (int)$row['organization_id'] ?>">
                    <button type="submit" class="btn">Save</button>
                  </form>
                  <form method="post" class="d-inline">
                    <input type="hidden" name="action" value="delete_cap_exempt_override">
                    <input type="hidden" name="product_id" value="<?= (int)$row['product_id'] ?>">
                    <input type="hidden" name="org_id" value="<?= (int)$row['organization_id'] ?>">
                    <button type="submit" class="btn" class="bg-danger" onclick="return confirm('Clear cap overrides for this org?');">Clear</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </details>

    <h3 class="mb-6-mt-20">Products</h3>
    <table>
      <thead>
        <tr>
          <th class="w-16">Code</th>
          <th class="w-22">Name</th>
          <th class="w-16">Providers</th>
          <th style="width:46%;">Details</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($productsByCode as $product): ?>
          <?php
            $providers = array_keys($product['providers']);
            sort($providers);
          ?>
          <tr>
            <td><?= htmlspecialchars($product['code']) ?></td>
            <td><?= htmlspecialchars($product['name']) ?></td>
            <td><?= htmlspecialchars(implode(', ', $providers)) ?></td>
            <td>
              <details>
                <summary>Variants & Availability</summary>
                <div class="muted" class="mt-8">
                  <?= isset($availabilityMap[$product['code']]) ? 'Availability rules: ' . count($availabilityMap[$product['code']]) : 'No availability rules yet.' ?>
                </div>
                <table>
	                  <thead>
	                    <tr>
	                      <th>Variant Name</th>
	                      <th>Provider</th>
	                      <th>Category</th>
                        <?php if ($hasProductDefaultPriceColumn): ?>
                          <th>Price</th>
                        <?php endif; ?>
                        <?php if ($hasProductDefaultCostColumn): ?>
                          <th>Cost</th>
                        <?php endif; ?>
                        <?php if ($hasProductDefaultTermColumn): ?>
                          <th>Term</th>
                        <?php endif; ?>
                        <?php if ($hasProductRequiresQuoteColumn): ?>
                          <th>Quote/API</th>
                        <?php endif; ?>
                        <?php if ($hasProductRequiresSelectionColumn): ?>
                          <th>Select</th>
                        <?php endif; ?>
                        <?php if ($hasProductOptionSetIdColumn): ?>
                          <th>Option Set</th>
                        <?php endif; ?>
	                      <th>Allowed Deal Types</th>
                        <?php if ($hasProductAllowedCustomerTypesColumn): ?>
                          <th>Allowed Customer Types</th>
                        <?php endif; ?>
	                      <th>Eligible Verticals</th>
	                      <?php if ($hasProductTaxFlags): ?>
	                        <th>Luxury</th>
	                        <th>GST</th>
	                        <th>PST</th>
	                      <?php endif; ?>
	                      <th>Active</th>
	                      <th>Action</th>
	                    </tr>
	                  </thead>
                  <tbody>
                    <?php foreach ($product['variants'] as $variant): ?>
                      <?php $formId = 'update-product-' . (int)$variant['id']; ?>
                      <?php
                        $variantDealTypes = parse_csv_values($variant['allowed_deal_types'] ?? '');
                        $variantCustomerTypes = $hasProductAllowedCustomerTypesColumn ? parse_csv_values($variant['allowed_customer_types'] ?? '') : [];
                        $variantVerticals = parse_csv_values($variant['eligible_verticals'] ?? '');
                      ?>
                      <tr>
                        <td>
                          <form id="<?= htmlspecialchars($formId) ?>" method="post" class="d-none">
                            <input type="hidden" name="action" value="update_product">
                            <input type="hidden" name="product_id" value="<?= (int)$variant['id'] ?>">
                          </form>
                          <input type="text" name="name" form="<?= htmlspecialchars($formId) ?>" value="<?= htmlspecialchars($variant['name'] ?? '') ?>" required>
                        </td>
                        <td>
                          <input type="text" name="provider" form="<?= htmlspecialchars($formId) ?>" value="<?= htmlspecialchars($variant['provider'] ?? '') ?>" list="provider_list" class="js-quick-pick-input" data-quick-list="provider_list">
                          <div class="quick-picks js-quick-picks"></div>
                        </td>
                        <td>
                          <select name="category" form="<?= htmlspecialchars($formId) ?>">
                            <option value="">Select category</option>
                            <?php foreach (array_keys($categoryValues) as $categoryOption): ?>
                              <option value="<?= htmlspecialchars($categoryOption) ?>" <?= ($variant['category'] ?? '') === $categoryOption ? 'selected' : '' ?>>
                                <?= htmlspecialchars($categoryOption) ?>
                              </option>
                            <?php endforeach; ?>
                          </select>
                        </td>
                        <?php if ($hasProductDefaultPriceColumn): ?>
                          <?php
                            $priceVal = ($variant['default_price'] ?? null);
                            $priceVal = ($priceVal === null || $priceVal === '') ? '' : number_format((float)$priceVal, 2, '.', '');
                          ?>
                          <td><input type="number" name="default_price" form="<?= htmlspecialchars($formId) ?>" step="0.01" min="0" value="<?= htmlspecialchars($priceVal) ?>" class="w-110px"></td>
                        <?php endif; ?>
                        <?php if ($hasProductDefaultCostColumn): ?>
                          <?php
                            $costVal = ($variant['default_cost'] ?? null);
                            $costVal = ($costVal === null || $costVal === '') ? '' : number_format((float)$costVal, 2, '.', '');
                          ?>
                          <td><input type="number" name="default_cost" form="<?= htmlspecialchars($formId) ?>" step="0.01" min="0" value="<?= htmlspecialchars($costVal) ?>" class="w-110px"></td>
                        <?php endif; ?>
                        <?php if ($hasProductDefaultTermColumn): ?>
                          <?php
                            $termVal = ($variant['default_term'] ?? null);
                            $termVal = ($termVal === null || $termVal === '') ? '' : (string)(int)$termVal;
                          ?>
                          <td><input type="number" name="default_term" form="<?= htmlspecialchars($formId) ?>" min="0" value="<?= htmlspecialchars($termVal) ?>" class="w-90px"></td>
                        <?php endif; ?>
                        <?php if ($hasProductRequiresQuoteColumn): ?>
                          <td class="text-center"><input type="checkbox" name="requires_quote" form="<?= htmlspecialchars($formId) ?>" value="1" <?= !empty($variant['requires_quote'] ?? null) ? 'checked' : '' ?>></td>
                        <?php endif; ?>
                        <?php if ($hasProductRequiresSelectionColumn): ?>
                          <td class="text-center"><input type="checkbox" name="requires_selection" form="<?= htmlspecialchars($formId) ?>" value="1" <?= !empty($variant['requires_selection'] ?? null) ? 'checked' : '' ?>></td>
                        <?php endif; ?>
                        <?php if ($hasProductOptionSetIdColumn): ?>
                          <?php
                            $optSetVal = ($variant['option_set_id'] ?? null);
                            $optSetVal = ($optSetVal === null || $optSetVal === '') ? '' : (string)(int)$optSetVal;
                          ?>
                          <td><input type="number" name="option_set_id" form="<?= htmlspecialchars($formId) ?>" min="0" value="<?= htmlspecialchars($optSetVal) ?>" class="w-90px"></td>
                        <?php endif; ?>
                        <td>
                          <select name="allowed_deal_types[]" form="<?= htmlspecialchars($formId) ?>" multiple size="3">
                            <?php foreach (array_keys($dealTypeValues) as $dealType): ?>
                              <option value="<?= htmlspecialchars($dealType) ?>" <?= in_array($dealType, $variantDealTypes, true) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($dealType) ?>
                              </option>
                            <?php endforeach; ?>
                          </select>
                        </td>
                        <?php if ($hasProductAllowedCustomerTypesColumn): ?>
                          <td>
                            <?php $customerTypeOptions = ['personal' => 'Personal', 'professional' => 'Professional', 'commercial' => 'Commercial']; ?>
                            <select name="allowed_customer_types[]" form="<?= htmlspecialchars($formId) ?>" multiple size="3">
                              <?php foreach ($customerTypeOptions as $key => $label): ?>
                                <option value="<?= htmlspecialchars($key) ?>" <?= in_array($key, $variantCustomerTypes, true) ? 'selected' : '' ?>>
                                  <?= htmlspecialchars($label) ?>
                                </option>
                              <?php endforeach; ?>
                            </select>
                          </td>
                        <?php endif; ?>
	                        <td>
	                          <select name="eligible_verticals[]" form="<?= htmlspecialchars($formId) ?>" multiple size="3">
	                            <?php foreach (array_keys($verticalValues) as $vertical): ?>
	                              <option value="<?= htmlspecialchars($vertical) ?>" <?= in_array($vertical, $variantVerticals, true) ? 'selected' : '' ?>>
	                                <?= htmlspecialchars($vertical) ?>
	                              </option>
	                            <?php endforeach; ?>
	                          </select>
	                        </td>
	                        <?php if ($hasProductTaxFlags): ?>
	                          <td><input type="checkbox" name="contributes_to_luxury_tax" form="<?= htmlspecialchars($formId) ?>" value="1" <?= !empty($variant['contributes_to_luxury_tax'] ?? null) ? 'checked' : '' ?>></td>
	                          <td><input type="checkbox" name="gst_taxable" form="<?= htmlspecialchars($formId) ?>" value="1" <?= (int)($variant['gst_taxable'] ?? 1) === 1 ? 'checked' : '' ?>></td>
	                          <td><input type="checkbox" name="pst_taxable" form="<?= htmlspecialchars($formId) ?>" value="1" <?= (int)($variant['pst_taxable'] ?? 1) === 1 ? 'checked' : '' ?>></td>
	                        <?php endif; ?>
	                        <td><input type="checkbox" name="is_active" form="<?= htmlspecialchars($formId) ?>" value="1" <?= (int)($variant['is_active'] ?? 0) === 1 ? 'checked' : '' ?>></td>
	                        <td>
	                          <a class="btn" href="admin_product_vehicle_rules.php?product_id=<?= (int)$variant['id'] ?>" style="margin-right:6px; display:inline-block;">Vehicle Rules</a>
	                          <a class="btn" href="admin_product_deal_pricing_rules.php?product_id=<?= (int)$variant['id'] ?>" style="margin-right:6px; display:inline-block;">Deal Pricing</a>
                          <button type="submit" class="btn" form="<?= htmlspecialchars($formId) ?>">Save</button>
                          <form method="post" class="d-inline">
                            <input type="hidden" name="action" value="deactivate_product">
                            <input type="hidden" name="product_id" value="<?= (int)$variant['id'] ?>">
                            <button type="submit" class="btn" class="bg-danger" onclick="return confirm('Deactivate this product variant?');">Deactivate</button>
                          </form>
                          <form method="post" class="d-inline">
                            <input type="hidden" name="action" value="delete_product">
                            <input type="hidden" name="product_id" value="<?= (int)$variant['id'] ?>">
                            <button type="submit" class="btn" style="background:#8b1e2d;" onclick="return confirm('Delete this product variant? This cannot be undone.');">Delete</button>
                          </form>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </details>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <script nonce="<?= dealerfai_csp_nonce() ?>">
    (function () {
      const csrfToken = <?= json_encode($csrfToken) ?>;
      document.querySelectorAll('form').forEach((form) => {
        const method = (form.getAttribute('method') || 'get').toLowerCase();
        if (method !== 'post') return;
        if (form.querySelector('input[name="csrf_token"]')) return;
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'csrf_token';
        input.value = csrfToken;
        form.appendChild(input);
      });
    })();

    const providerCheckbox = document.getElementById('all_providers');
    const providerInput = document.getElementById('availability_provider');
    if (providerCheckbox && providerInput) {
      const syncProviderState = () => {
        if (providerCheckbox.checked) {
          providerInput.value = '';
          providerInput.setAttribute('disabled', 'disabled');
        } else {
          providerInput.removeAttribute('disabled');
        }
      };
      providerCheckbox.addEventListener('change', syncProviderState);
      syncProviderState();
    }

    (function () {
      function buildQuickPicks(input) {
        if (!input) return;
        const listId = input.dataset.quickList || input.getAttribute('list');
        if (!listId) return;
        const list = document.getElementById(listId);
        if (!list) return;
        const container = input.parentElement ? input.parentElement.querySelector('.js-quick-picks') : null;
        if (!container) return;

        const values = Array.from(list.querySelectorAll('option'))
          .map((opt) => (opt.value || '').trim())
          .filter(Boolean);
        const seen = new Set();
        const unique = values.filter((value) => {
          const key = value.toLowerCase();
          if (seen.has(key)) return false;
          seen.add(key);
          return true;
        }).slice(0, 10);
        if (!unique.length) return;

        container.innerHTML = '';
        const syncActive = () => {
          const current = (input.value || '').trim().toLowerCase();
          container.querySelectorAll('button').forEach((btn) => {
            btn.classList.toggle('is-active', (btn.dataset.value || '').toLowerCase() === current && current !== '');
          });
        };

        unique.forEach((value) => {
          const btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'quick-pick-btn';
          btn.dataset.value = value;
          btn.textContent = value;
          btn.addEventListener('click', () => {
            input.value = value;
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
            syncActive();
          });
          container.appendChild(btn);
        });

        input.addEventListener('input', syncActive);
        input.addEventListener('change', syncActive);
        syncActive();
      }

      document.querySelectorAll('.js-quick-pick-input').forEach(buildQuickPicks);
      buildQuickPicks(document.getElementById('availability_provider'));
    })();
  </script>
</body>
</html>
