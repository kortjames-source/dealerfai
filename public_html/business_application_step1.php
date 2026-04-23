<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/security_headers.php';
require_once __DIR__ . '/includes/theme_head.php';
require_once __DIR__ . '/includes/csrf.php';
include 'db.php';
require_once __DIR__ . '/helpers/customer_types.php';
require_once __DIR__ . '/helpers/deal_audit.php';
require_once __DIR__ . '/helpers/theme.php';

$deal_id = $_SESSION['deal_id'] ?? null;
if (!$deal_id) {
    die('Invalid session.');
}

// Prevent edits once a credit app is locked for this deal.
$dealStmt = $db->prepare("SELECT id, organization, customer_type, business_id, credit_app_locked, co_app_required FROM deals WHERE id = ?");
$dealStmt->execute([$deal_id]);
$deal = $dealStmt->fetch(PDO::FETCH_ASSOC);
if (!$deal) {
    die('Deal not found.');
}
if (!empty($deal['credit_app_locked'])) {
    die('This credit application is locked. Please contact a manager to unlock it.');
}

$customerType = normalize_customer_type($deal['customer_type'] ?? 'personal');
$coRequired = !empty($deal['co_app_required']);
if ($customerType === 'personal') {
    header('Location: application_step1.php');
    exit;
}

// Theme settings
$theme = dealerfai_get_theme_palette(null);
$orgId = (int)($deal['organization'] ?? 0);
if ($orgId) {
    $orgStmt = $db->prepare("SELECT logo_url, theme_variant FROM organizations WHERE id = ?");
    $orgStmt->execute([$orgId]);
    if ($org = $orgStmt->fetch(PDO::FETCH_ASSOC)) {
        $theme = dealerfai_get_theme_palette($org['theme_variant'] ?? null, ['logo' => $org['logo_url'] ?? '']);
    }
}

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

if (!$hasBusinessesTable) {
    die("Business buyers are not enabled on this database yet. Database schema update required.");
}

$businessId = isset($deal['business_id']) ? (int)$deal['business_id'] : 0;
$business = null;
if ($businessId > 0) {
    $bizStmt = $db->prepare("SELECT * FROM businesses WHERE id = ? LIMIT 1");
    $bizStmt->execute([$businessId]);
    $business = $bizStmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!dealerfai_csrf_validate($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        die('Invalid request.');
    }

    $addCosigner = trim((string)($_POST['add_cosigner'] ?? 'No'));
    if ($coRequired) {
        $addCosigner = 'Yes';
    }
    if ($addCosigner === 'Yes') {
        header('Location: application_step1.php');
        exit;
    }

    $legal_name = trim((string)($_POST['legal_name'] ?? ''));
    $operating_name = trim((string)($_POST['operating_name'] ?? ''));
    $address = trim((string)($_POST['address'] ?? ''));
    $city = trim((string)($_POST['city'] ?? ''));
    $province = trim((string)($_POST['province'] ?? ''));
    $postal_code = trim((string)($_POST['postal_code'] ?? ''));
    $yearsAt = trim((string)($_POST['years_at_address'] ?? ''));
    $previousAddress = trim((string)($_POST['previous_address'] ?? ''));
    $annualSalesRaw = trim((string)($_POST['annual_sales'] ?? ''));

    $yearsAtVal = null;
    if ($yearsAt !== '' && is_numeric($yearsAt)) {
        $yearsAtVal = (int)$yearsAt;
        if ($yearsAtVal < 0) $yearsAtVal = 0;
    }
    $annualSalesVal = null;
    if ($annualSalesRaw !== '' && is_numeric($annualSalesRaw)) {
        $annualSalesVal = round((float)$annualSalesRaw, 2);
        if ($annualSalesVal < 0) $annualSalesVal = 0;
    }

    if ($legal_name === '') {
        $error = 'Legal name is required.';
    } elseif ($operating_name === '') {
        $error = 'Operating name is required.';
    } elseif ($address === '' || $city === '' || $province === '' || $postal_code === '') {
        $error = 'Address, city, province, and postal code are required.';
    } elseif ($yearsAtVal === null) {
        $error = 'Years at address is required.';
    } elseif ($yearsAtVal < 2 && $previousAddress === '') {
        $error = 'Please enter your previous address (required when under 2 years at current address).';
    } elseif ($annualSalesVal === null) {
        $error = 'Annual sales is required.';
    } else {
        $previousAddressToStore = ($yearsAtVal !== null && $yearsAtVal < 2) ? ($previousAddress ?: null) : null;
        if ($businessId > 0) {
            $upd = $db->prepare("
                UPDATE businesses
                SET name = ?, legal_name = ?,
                    years_at_address = ?, previous_address = ?, annual_sales = ?,
                    address = ?, city = ?, province = ?, postal_code = ?
                WHERE id = ?
            ");
            $upd->execute([
                $operating_name,
                $legal_name,
                $yearsAtVal,
                $previousAddressToStore,
                $annualSalesVal,
                $address,
                $city,
                $province,
                $postal_code,
                $businessId
            ]);
        } else {
            $ins = $db->prepare("
                INSERT INTO businesses (
                    organization_id, name, legal_name,
                    years_at_address, previous_address, annual_sales,
                    address, city, province, postal_code,
                    created_by
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $createdBy = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
            $ins->execute([
                $orgId,
                $operating_name,
                $legal_name,
                $yearsAtVal,
                $previousAddressToStore,
                $annualSalesVal,
                $address,
                $city,
                $province,
                $postal_code,
                $createdBy
            ]);
            $businessId = (int)$db->lastInsertId();
            $beforeDeal = fetch_deal_row_for_audit($db, (int)$deal_id);
            $db->prepare("UPDATE deals SET business_id = ? WHERE id = ?")->execute([$businessId, $deal_id]);
            $afterDeal = fetch_deal_row_for_audit($db, (int)$deal_id);
            log_deal_change_audit($db, (int)$deal_id, 'update', $beforeDeal, $afterDeal, ['context' => 'business_application_step1']);
        }

        header('Location: application_step3.php');
        exit;
    }
}
$csrfToken = dealerfai_csrf_get_token();

$values = [
    'legal_name' => $business['legal_name'] ?? '',
    'operating_name' => $business['name'] ?? '',
    'address' => $business['address'] ?? '',
    'city' => $business['city'] ?? '',
    'province' => $business['province'] ?? '',
    'postal_code' => $business['postal_code'] ?? '',
    'years_at_address' => isset($business['years_at_address']) ? (string)$business['years_at_address'] : '',
    'previous_address' => $business['previous_address'] ?? '',
    'annual_sales' => isset($business['annual_sales']) ? (string)$business['annual_sales'] : '',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php include __DIR__ . "/includes/head_favicon.php"; ?>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Business Information</title>
  <?php dealerfai_theme_head($theme ?? null); ?>
  <style nonce="<?= dealerfai_csp_nonce() ?>">
    body { font-family: Arial, sans-serif; background: <?= htmlspecialchars($theme['page_background']) ?>; margin: 0; padding: 0; }
    header { background: <?= htmlspecialchars($theme['header_background']) ?>; padding: 20px; text-align: center; color: <?= htmlspecialchars($theme['header_text']) ?>; }
    header img { max-width: 480px; max-height: 180px; height: auto; display: block; margin: 0 auto 10px; }
    .card { max-width: 700px; margin: 30px auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
    h2 { margin-top: 0; color: #333; }
    label { display: block; margin-top: 15px; font-weight: bold; }
    input { width: 100%; padding: 10px; margin-top: 5px; border-radius: 4px; border: 1px solid #ccc; }
    button { background: <?= htmlspecialchars($theme['color']) ?>; color: white; border: none; padding: 12px 20px; margin-top: 20px; font-size: 16px; border-radius: 4px; cursor: pointer; }
    button:hover { opacity: 0.9; }
    .error { background: #fff3cd; border: 1px solid #ffeeba; color: #856404; padding: 12px; border-radius: 8px; margin: 0 0 12px; }
    .muted { color:#667085; font-size: 13px; }
    .row { display:grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    @media (max-width: 720px) { .row { grid-template-columns: 1fr; } }
  </style>
</head>
<body>
<header>
  <?php if (!empty($theme['logo'])): ?>
    <img src="<?= htmlspecialchars($theme['logo']) ?>" alt="Dealer Logo">
  <?php else: ?>
    <h1>DealerFAI Application</h1>
  <?php endif; ?>
</header>

<div class="card">
  <h2>Business Information</h2>
  <p class="muted">This is a <strong><?= htmlspecialchars(customer_type_label($customerType)) ?></strong> deal.</p>

  <?php if ($error): ?>
    <div class="error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="post">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <?php if ($coRequired): ?>
      <input type="hidden" name="add_cosigner" value="Yes">
      <p class="muted" class="mt-0">A co-signer is required for this deal and will be collected in the personal application flow.</p>
    <?php else: ?>
      <label>Do you have a co-signer?</label>
      <div class="mt-8">
        <label class="fw-normal"><input type="radio" name="add_cosigner" value="No" checked onchange="toggleCosignerUi()"> No</label>
        <label style="font-weight: normal; margin-left: 12px;"><input type="radio" name="add_cosigner" value="Yes" onchange="toggleCosignerUi()"> Yes (continue with the normal personal application)</label>
      </div>
    <?php endif; ?>

    <div id="business_fields">
      <label>Legal Name</label>
      <input type="text" name="legal_name" value="<?= htmlspecialchars($values['legal_name']) ?>" required data-biz-required="1">

      <label>Operating Name</label>
      <input type="text" name="operating_name" value="<?= htmlspecialchars($values['operating_name']) ?>" required data-biz-required="1">

      <label>Business Address</label>
      <input type="text" name="address" value="<?= htmlspecialchars($values['address']) ?>" required data-biz-required="1">

      <div class="row">
        <div>
          <label>City</label>
          <input type="text" name="city" value="<?= htmlspecialchars($values['city']) ?>" required data-biz-required="1">
        </div>
        <div>
          <label>Province</label>
          <input type="text" name="province" value="<?= htmlspecialchars($values['province']) ?>" required data-biz-required="1">
        </div>
      </div>

      <label>Postal Code</label>
      <input type="text" name="postal_code" value="<?= htmlspecialchars($values['postal_code']) ?>" required data-biz-required="1">

      <label>Years at Address</label>
      <input type="number" min="0" step="1" name="years_at_address" id="years_at_address" value="<?= htmlspecialchars($values['years_at_address']) ?>" required data-biz-required="1">

      <div id="previous_address_block" class="d-none">
        <label>Previous Address (required if under 2 years)</label>
        <input type="text" name="previous_address" id="previous_address" value="<?= htmlspecialchars($values['previous_address']) ?>">
      </div>

      <label>Annual Sales</label>
      <input type="number" min="0" step="0.01" name="annual_sales" value="<?= htmlspecialchars($values['annual_sales']) ?>" required data-biz-required="1">
    </div>

    <button type="submit">Continue →</button>
  </form>
</div>

<script nonce="<?= dealerfai_csp_nonce() ?>">
  const coRequired = <?= $coRequired ? 'true' : 'false' ?>;
  function toggleCosignerUi() {
    if (coRequired) {
      const block = document.getElementById('business_fields');
      if (block) block.style.display = 'none';
      document.querySelectorAll('[data-biz-required="1"]').forEach(el => {
        try { el.required = false; } catch (e) {}
      });
      return;
    }
    const radios = document.querySelectorAll('input[name=\"add_cosigner\"]');
    let val = 'No';
    radios.forEach(r => { if (r.checked) val = r.value; });
    const isCosigner = val === 'Yes';
    const block = document.getElementById('business_fields');
    if (block) {
      block.style.display = isCosigner ? 'none' : 'block';
    }
    document.querySelectorAll('[data-biz-required=\"1\"]').forEach(el => {
      try { el.required = !isCosigner; } catch (e) {}
    });
    togglePrev();
  }

  function togglePrev() {
    const yearsEl = document.getElementById('years_at_address');
    const prevBlock = document.getElementById('previous_address_block');
    const prevInput = document.getElementById('previous_address');
    if (!yearsEl || !prevBlock || !prevInput) return;
    const years = parseInt(yearsEl.value || '0', 10);
    const show = !isNaN(years) && years < 2;
    prevBlock.style.display = show ? 'block' : 'none';
    prevInput.required = show;
    if (!show) {
      prevInput.value = '';
    }
  }
  document.addEventListener('DOMContentLoaded', () => {
    toggleCosignerUi();
    togglePrev();
  });
  document.getElementById('years_at_address')?.addEventListener('input', togglePrev);
</script>
</body>
</html>
