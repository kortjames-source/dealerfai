<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/security_headers.php';
require_once __DIR__ . '/includes/theme_head.php';
require_once __DIR__ . '/includes/csrf.php';
include 'db.php';
require_once __DIR__ . '/helpers/organization_question_config.php';
require_once __DIR__ . '/helpers/custom_credit_questions.php';
require_once __DIR__ . '/helpers/pii_crypto.php';
require_once __DIR__ . '/helpers/theme.php';

$deal_id = $_SESSION['deal_id'] ?? null;
if (!$deal_id) { die('Invalid session.'); }

// Theme and logo setup
$stmt = $db->prepare("SELECT organization, vehicle_make, vehicle_model, credit_app_locked, co_app_required, co_app_name FROM deals WHERE id = ?");
$stmt->execute([$deal_id]);
$deal = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$deal) { die('Deal not found.'); }
if (!empty($deal['credit_app_locked'])) { die('This credit application is locked. Please contact a manager to unlock it.'); }

$org_id = $deal['organization'];
$vehicle_make = $deal['vehicle_make'] ?? '';
$vehicle_model = $deal['vehicle_model'] ?? '';
$coRequired = !empty($deal['co_app_required']);
$creditQuestionConfig = get_org_credit_app_question_config($db, (int)$org_id);
$showQuestion = function (string $id) use ($creditQuestionConfig, $coRequired): bool {
  if ($coRequired && ($id === 'has_cosigner' || substr($id, 0, 3) === 'co_')) {
    return true;
  }
  return credit_app_question_is_visible($creditQuestionConfig, 'step1', $id);
};
$housingOptions = ['Rent', 'Own (with mortgage)', 'Own Free and Clear', 'Live with Relatives'];
$customQuestions = get_org_custom_credit_questions($db, (int)$org_id, 'step1');

// Save to session for later steps
$_SESSION['vehicle_make'] = $vehicle_make;
$_SESSION['vehicle_model'] = $vehicle_model;

$theme = dealerfai_get_theme_palette(null);
if ($org_id) {
  $orgStmt = $db->prepare("SELECT logo_url, theme_variant FROM organizations WHERE id = ?");
  $orgStmt->execute([$org_id]);
  $org = $orgStmt->fetch(PDO::FETCH_ASSOC);
  if ($org) {
    $theme = dealerfai_get_theme_palette($org['theme_variant'] ?? null, ['logo' => $org['logo_url'] ?? '']);
  }
}

// Sticky form setup
$values = $_SESSION['step1'] ?? [];
if ($coRequired && empty($values['co_full_name'])) {
  $prefilledCoName = trim((string)($deal['co_app_name'] ?? ''));
  if ($prefilledCoName !== '') {
    $values['co_full_name'] = $prefilledCoName;
  }
}
$customAnswers = $_SESSION['custom_step1'] ?? [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!dealerfai_csrf_validate($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    die('Invalid request.');
  }

  include_once __DIR__ . '/../secure/config.php';

  $data = $_POST;
  if ($coRequired) {
    $data['has_cosigner'] = 'Yes';
  }
  $_SESSION['step1'] = $data;
  $_SESSION['custom_step1'] = $_POST['custom'] ?? [];
  $extra_answers_json = json_encode([
    'step1' => $_SESSION['custom_step1'] ?? []
  ]);

  $isCoApplicant = ($data['has_cosigner'] ?? '') === 'Yes';
  $asNullableDecimal = function ($val) {
    if ($val === null) {
      return null;
    }
    $val = trim((string)$val);
    return $val === '' ? null : $val;
  };

  $supportsPiiV2 = dealerfai_credit_applications_supports_pii_v2($db);
  $piiCiphertext = null;
  $piiNonce = null;
  $piiTag = null;
  if ($supportsPiiV2) {
    // v2: store all PII in a single AEAD-encrypted blob (AES-256-GCM).
    [$piiCiphertext, $piiNonce, $piiTag] = dealerfai_encrypt_pii_v2([
      'full_name' => $data['full_name'] ?? null,
      'email' => $data['email'] ?? null,
      'phone' => $data['phone'] ?? null,
      'address' => $data['address'] ?? null,
      'city' => $data['city'] ?? null,
      'postal_code' => $data['postal_code'] ?? null,
    ]);
  } else {
    // Legacy fallback (for environments where the migration hasn't been applied yet).
    include_once __DIR__ . '/../secure/config.php';
    $aes_key_bin = hex2bin(AES_KEY);
    $aes_iv = substr($aes_key_bin, 0, 16);
    $encrypt = fn($val) => ($val === null || $val === '') ? null : openssl_encrypt($val, 'aes-256-cbc', $aes_key_bin, OPENSSL_RAW_DATA, $aes_iv);

    $stmt = $db->prepare("INSERT INTO credit_applications (
        deal_id, is_co_applicant, first_name, last_name, email, phone, dob, address, city, province, postal_code,
        time_at_address, housing_status, monthly_housing_cost, previous_address, sin_encrypted,
        employer_name, employer_address, employment_length, gross_annual_income,
        other_income, other_income_source, previous_employer_name, previous_employer_phone,
        previous_employer_address, previous_employment_time,
        extra_answers_json, submitted_at, expires_at
    ) VALUES (
        ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
        ?, ?, ?, ?, ?, ?, ?, ?, ?,
        ?, ?, ?, ?, ?, ?,
        ?, NOW(), DATE_ADD(NOW(), INTERVAL 60 DAY)
    )");

    $stmt->execute([
        $deal_id,
        $isCoApplicant ? 1 : 0,
        $encrypt($data['full_name'] ?? null),
        null, // last_name
        $encrypt($data['email'] ?? null),
        $encrypt($data['phone'] ?? null),
        null, // dob
        $encrypt($data['address'] ?? null),
        $encrypt($data['city'] ?? null),
        $data['province'] ?? null,
        $encrypt($data['postal_code'] ?? null),
        $data['years_at_address'] ?? null,
        $data['housing'] ?? null,
        $asNullableDecimal($data['monthly_payment'] ?? null),
        null, // previous_address
        null, // SIN
        null, // employer_name
        null, // employer_address
        null, // employment_length
        null, // gross_annual_income
        null, // other_income
        null, // other_income_source
        null, // previous_employer_name
        null, // previous_employer_phone
        null, // previous_employer_address
        null, // previous_employment_time
        $extra_answers_json
    ]);

    header("Location: application_step2.php");
    exit;
  }

$stmt = $db->prepare("INSERT INTO credit_applications (
    deal_id, is_co_applicant, encryption_version, first_name, last_name, email, phone, dob, address, city, province, postal_code,
    time_at_address, housing_status, monthly_housing_cost, previous_address, sin_encrypted,
    employer_name, employer_address, employment_length, gross_annual_income,
    other_income, other_income_source, previous_employer_name, previous_employer_phone,
    previous_employer_address, previous_employment_time,
    extra_answers_json, pii_ciphertext, pii_nonce, pii_tag, submitted_at, expires_at
) VALUES (
    ?, ?, 2, ?, ?, ?, ?, ?, ?, ?, ?,
    ?, ?, ?, ?, ?, ?, ?, ?, ?,
    ?, ?, ?, ?, ?, ?,
    ?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 60 DAY)
)");

$stmt->execute([
    $deal_id,
    $isCoApplicant ? 1 : 0,
    null, // legacy first_name
    null, // last_name
    null, // legacy email
    null, // legacy phone
    null, // dob
    null, // legacy address
    null, // legacy city
    $data['province'] ?? null,
    null, // legacy postal_code
    $data['years_at_address'] ?? null,
    $data['housing'] ?? null,
    $asNullableDecimal($data['monthly_payment'] ?? null),
    null, // previous_address
    null, // SIN
    null, // employer_name
    null, // employer_address
    null, // employment_length
    null, // gross_annual_income
    null, // other_income
    null, // other_income_source
    null, // previous_employer_name
    null, // previous_employer_phone
    null, // previous_employer_address
    null, // previous_employment_time
    $extra_answers_json,
    $piiCiphertext,
    $piiNonce,
    $piiTag
]);

  header("Location: application_step2.php");
  exit;
}
$showHousing = $showQuestion('housing');
$showYearsAtAddress = $showQuestion('years_at_address');
$showMonthlyPayment = $showQuestion('monthly_payment');
$showCosignerQuestion = $showQuestion('has_cosigner');
$showCoSection = $showCosignerQuestion && (
  $showQuestion('co_full_name') ||
  $showQuestion('co_email') ||
  $showQuestion('co_phone') ||
  $showQuestion('co_address') ||
  $showQuestion('co_city') ||
  $showQuestion('co_province') ||
  $showQuestion('co_postal_code') ||
  $showQuestion('co_housing') ||
  $showQuestion('co_years_at_address') ||
  $showQuestion('co_monthly_payment')
);
$showCoSection = $showCoSection || $coRequired;
$hasCustomQuestions = !empty($customQuestions);
$coRequiredAttr = $coRequired ? 'required' : '';
?>
<!DOCTYPE html>
<html>
<head>
  <?php include __DIR__ . "/includes/head_favicon.php"; ?>
<meta charset="UTF-8">
  <title>Step 1 - Personal Info</title>
  <?php dealerfai_theme_head($theme ?? null); ?>
  <style nonce="<?= dealerfai_csp_nonce() ?>">
    body { font-family: Arial, sans-serif; background: <?= htmlspecialchars($theme['page_background']) ?>; margin: 0; padding: 0; }
    header {
      background: <?= htmlspecialchars($theme['header_background']) ?>;
      padding: 20px;
      text-align: center;
      color: <?= htmlspecialchars($theme['header_text']) ?>;
    }
    header img {
      max-width: 480px;
      max-height: 180px;
      height: auto;
      display: block;
      margin: 0 auto 10px;
    }
    .card {
      max-width: 700px; margin: 30px auto; background: white;
      padding: 30px; border-radius: 8px;
      box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    }
    h2 { margin-top: 0; color: #333; }
    label { display: block; margin-top: 15px; font-weight: bold; }
    input, select, textarea {
      width: 100%; padding: 10px; margin-top: 5px;
      border-radius: 4px; border: 1px solid #ccc;
    }
    button {
      background: <?= $theme['color'] ?>; color: white;
      border: none; padding: 12px 20px; margin-top: 20px;
      font-size: 16px; border-radius: 4px; cursor: pointer;
    }
    button:hover { opacity: 0.9; }
    .progress-bar {
      background: #e0e0e0; border-radius: 4px; height: 16px; margin-bottom: 20px;
      overflow: hidden;
    }
    .progress-fill {
      background: <?= $theme['color'] ?>;
      height: 100%; width: 33%;
      text-align: center; color: white; font-size: 12px;
      line-height: 16px;
    }
    .co-section {
      margin-top: 30px;
      padding: 20px;
      background: #f9f9f9;
      border: 1px solid #ccc;
      border-radius: 8px;
    }
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
  <div class="progress-bar">
    <div class="progress-fill">Step 1 of 3</div>
  </div>

  <h2>Primary Applicant Information</h2>
  <form method="post" action="application_step1.php">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(dealerfai_csrf_get_token()) ?>">

    <!-- Vehicle Info (Read-only display + hidden fields) -->
    <label>Vehicle:</label>
    <input type="text" value="<?= htmlspecialchars($vehicle_make . ' ' . $vehicle_model) ?>" readonly>
    <input type="hidden" name="vehicle_make" value="<?= htmlspecialchars($vehicle_make) ?>">
    <input type="hidden" name="vehicle_model" value="<?= htmlspecialchars($vehicle_model) ?>">

    <?php if ($showQuestion('full_name')): ?>
      <label>Full Name:</label>
      <input type="text" name="full_name" value="<?= htmlspecialchars($values['full_name'] ?? '') ?>" required>
    <?php endif; ?>

    <?php if ($showQuestion('email')): ?>
      <label>Email:</label>
      <input type="email" name="email" value="<?= htmlspecialchars($values['email'] ?? '') ?>" required>
    <?php endif; ?>

    <?php if ($showQuestion('phone')): ?>
      <label>Phone:</label>
      <input type="tel" name="phone" value="<?= htmlspecialchars($values['phone'] ?? '') ?>" required>
    <?php endif; ?>

    <?php if ($showQuestion('address')): ?>
      <label>Address:</label>
      <input type="text" name="address" value="<?= htmlspecialchars($values['address'] ?? '') ?>" required>
    <?php endif; ?>

    <?php if ($showQuestion('city')): ?>
      <label>City:</label>
      <input type="text" name="city" value="<?= htmlspecialchars($values['city'] ?? '') ?>" required>
    <?php endif; ?>

    <?php if ($showQuestion('province')): ?>
      <label>Province:</label>
      <input type="text" name="province" value="<?= htmlspecialchars($values['province'] ?? '') ?>" required>
    <?php endif; ?>

    <?php if ($showQuestion('postal_code')): ?>
      <label>Postal Code:</label>
      <input type="text" name="postal_code" value="<?= htmlspecialchars($values['postal_code'] ?? '') ?>" required>
    <?php endif; ?>

    <?php if ($showHousing): ?>
      <label>Rent or Own:</label>
      <select name="housing" id="housing">
        <?php foreach ($housingOptions as $opt): ?>
          <option <?= ($values['housing'] ?? '') === $opt ? 'selected' : '' ?>><?= $opt ?></option>
        <?php endforeach; ?>
      </select>
    <?php endif; ?>

    <?php if ($showYearsAtAddress): ?>
      <label>Time at Address (years):</label>
      <input type="number" name="years_at_address" min="0" value="<?= htmlspecialchars($values['years_at_address'] ?? '') ?>">
    <?php endif; ?>

    <?php if ($showMonthlyPayment): ?>
      <div id="monthlyHousingDiv">
        <label>Mortgage/Rent Payment:</label>
        <input type="number" name="monthly_payment" min="0" value="<?= htmlspecialchars($values['monthly_payment'] ?? '') ?>">
      </div>
    <?php endif; ?>

    <?php if ($showCosignerQuestion): ?>
      <label>Would you like to add a co-signer?</label>
      <select name="has_cosigner" id="has_cosigner" <?= $coRequired ? 'disabled' : '' ?>>
        <option value="No" <?= ($values['has_cosigner'] ?? '') === 'No' ? 'selected' : '' ?>>No</option>
        <option value="Yes" <?= ($values['has_cosigner'] ?? '') === 'Yes' || $coRequired ? 'selected' : '' ?>>Yes</option>
      </select>
      <?php if ($coRequired): ?>
        <input type="hidden" name="has_cosigner" value="Yes">
      <?php endif; ?>
    <?php endif; ?>

    <?php if ($showCoSection): ?>
      <div id="cosigner_section" class="co-section" class="d-none">
        <h3>Co-Applicant Information</h3>

        <?php if ($showQuestion('co_full_name')): ?>
          <label>Full Name:</label>
          <input type="text" name="co_full_name" value="<?= htmlspecialchars($values['co_full_name'] ?? '') ?>" <?= $coRequiredAttr ?>>
        <?php endif; ?>

        <?php if ($showQuestion('co_email')): ?>
          <label>Email:</label>
          <input type="email" name="co_email" value="<?= htmlspecialchars($values['co_email'] ?? '') ?>" <?= $coRequiredAttr ?>>
        <?php endif; ?>

        <?php if ($showQuestion('co_phone')): ?>
          <label>Phone:</label>
          <input type="tel" name="co_phone" value="<?= htmlspecialchars($values['co_phone'] ?? '') ?>" <?= $coRequiredAttr ?>>
        <?php endif; ?>

        <?php if ($showQuestion('co_address')): ?>
          <label>Address:</label>
          <input type="text" name="co_address" value="<?= htmlspecialchars($values['co_address'] ?? '') ?>" <?= $coRequiredAttr ?>>
        <?php endif; ?>

        <?php if ($showQuestion('co_city')): ?>
          <label>City:</label>
          <input type="text" name="co_city" value="<?= htmlspecialchars($values['co_city'] ?? '') ?>" <?= $coRequiredAttr ?>>
        <?php endif; ?>

        <?php if ($showQuestion('co_province')): ?>
          <label>Province:</label>
          <input type="text" name="co_province" value="<?= htmlspecialchars($values['co_province'] ?? '') ?>" <?= $coRequiredAttr ?>>
        <?php endif; ?>

        <?php if ($showQuestion('co_postal_code')): ?>
          <label>Postal Code:</label>
          <input type="text" name="co_postal_code" value="<?= htmlspecialchars($values['co_postal_code'] ?? '') ?>" <?= $coRequiredAttr ?>>
        <?php endif; ?>

        <?php if ($showQuestion('co_housing')): ?>
          <label>Rent or Own:</label>
          <select name="co_housing" id="co_housing" <?= $coRequiredAttr ?>>
            <?php foreach ($housingOptions as $opt): ?>
              <option <?= ($values['co_housing'] ?? '') === $opt ? 'selected' : '' ?>><?= $opt ?></option>
            <?php endforeach; ?>
          </select>
        <?php endif; ?>

        <?php if ($showQuestion('co_years_at_address')): ?>
          <label>Time at Address (co-applicant):</label>
          <input type="number" name="co_years_at_address" min="0" value="<?= htmlspecialchars($values['co_years_at_address'] ?? '') ?>" <?= $coRequiredAttr ?>>
        <?php endif; ?>

        <?php if ($showQuestion('co_monthly_payment')): ?>
          <div id="coMonthlyDiv">
            <label>Mortgage/Rent Payment:</label>
            <input type="number" name="co_monthly_payment" min="0" value="<?= htmlspecialchars($values['co_monthly_payment'] ?? '') ?>" <?= $coRequiredAttr ?>>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if ($hasCustomQuestions): ?>
      <div class="co-section" class="mt-20">
        <h3>Additional Questions</h3>
        <?php foreach ($customQuestions as $question): ?>
          <?php
            $fieldKey = $question['field_key'];
            $fieldType = $question['field_type'];
            $value = $customAnswers[$fieldKey] ?? '';
            $required = $question['is_required'] ? 'required' : '';
          ?>
          <label><?= htmlspecialchars($question['label']) ?></label>
          <?php if ($fieldType === 'textarea'): ?>
            <textarea name="custom[<?= htmlspecialchars($fieldKey) ?>]" <?= $required ?> rows="3"><?= htmlspecialchars(is_array($value) ? '' : $value) ?></textarea>
          <?php elseif ($fieldType === 'select'): ?>
            <select name="custom[<?= htmlspecialchars($fieldKey) ?>]" <?= $required ?>>
              <option value="">Select an option</option>
              <?php foreach ($question['options'] as $opt): ?>
                <option value="<?= htmlspecialchars($opt) ?>" <?= ($value ?? '') === $opt ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
              <?php endforeach; ?>
            </select>
          <?php elseif ($fieldType === 'multiselect'): ?>
            <?php $valueList = is_array($value) ? $value : [$value]; ?>
            <select name="custom[<?= htmlspecialchars($fieldKey) ?>][]" multiple <?= $required ?>>
              <?php foreach ($question['options'] as $opt): ?>
                <option value="<?= htmlspecialchars($opt) ?>" <?= in_array($opt, $valueList, true) ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
              <?php endforeach; ?>
            </select>
          <?php elseif ($fieldType === 'checkbox'): ?>
            <label class="fw-normal">
              <input type="checkbox" name="custom[<?= htmlspecialchars($fieldKey) ?>]" value="1" <?= !empty($value) ? 'checked' : '' ?> <?= $required ?>>
              Yes
            </label>
          <?php elseif ($fieldType === 'date'): ?>
            <input type="date" name="custom[<?= htmlspecialchars($fieldKey) ?>]" value="<?= htmlspecialchars(is_array($value) ? '' : $value) ?>" <?= $required ?>>
          <?php elseif ($fieldType === 'number'): ?>
            <input type="number" name="custom[<?= htmlspecialchars($fieldKey) ?>]" step="0.01" value="<?= htmlspecialchars(is_array($value) ? '' : $value) ?>" <?= $required ?>>
          <?php else: ?>
            <input type="text" name="custom[<?= htmlspecialchars($fieldKey) ?>]" value="<?= htmlspecialchars(is_array($value) ? '' : $value) ?>" <?= $required ?>>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <button type="submit">Next</button>
  </form>
</div>

<script nonce="<?= dealerfai_csp_nonce() ?>">
  document.addEventListener('DOMContentLoaded', function () {
    const housing = document.getElementById('housing');
    const monthlyDiv = document.getElementById('monthlyHousingDiv');
    const cosignerSelect = document.getElementById('has_cosigner');
    const cosignerSection = document.getElementById('cosigner_section');
    const coHousing = document.getElementById('co_housing');
    const coMonthly = document.getElementById('coMonthlyDiv');

    function updateVisibility() {
      if (housing && monthlyDiv) {
        monthlyDiv.style.display = (housing.value === 'Own Free and Clear' || housing.value === 'Live with Relatives') ? 'none' : 'block';
      }
      if (cosignerSelect && cosignerSection) {
        cosignerSection.style.display = (cosignerSelect.value === 'Yes') ? 'block' : 'none';
      }
      if (coHousing && coMonthly) {
        coMonthly.style.display = (coHousing.value === 'Own Free and Clear' || coHousing.value === 'Live with Relatives') ? 'none' : 'block';
      }
    }

    if (housing) housing.addEventListener('change', updateVisibility);
    if (cosignerSelect) cosignerSelect.addEventListener('change', updateVisibility);
    if (coHousing) coHousing.addEventListener('change', updateVisibility);
    updateVisibility();
  });
</script>

</body>
</html>
