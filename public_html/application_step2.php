<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/security_headers.php';
require_once __DIR__ . '/includes/theme_head.php';
require_once __DIR__ . '/includes/csrf.php';
include 'db.php';
include $_SERVER['DOCUMENT_ROOT'] . '/../secure/config.php';
require_once __DIR__ . '/helpers/organization_question_config.php';
require_once __DIR__ . '/helpers/custom_credit_questions.php';
require_once __DIR__ . '/helpers/pii_crypto.php';
require_once __DIR__ . '/helpers/theme.php';

$deal_id = $_SESSION['deal_id'] ?? null;
if (!$deal_id) die('Invalid session.');

// Prevent edits once a credit app is locked for this deal.
$lockStmt = $db->prepare("SELECT credit_app_locked, co_app_required FROM deals WHERE id = ?");
$lockStmt->execute([$deal_id]);
$lockRow = $lockStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$coRequired = !empty($lockRow['co_app_required']);
if (!empty($lockRow['credit_app_locked'])) {
    die('This credit application is locked. Please contact a manager to unlock it.');
}

// Step 1 data from session
$step1 = $_SESSION['step1'] ?? [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!dealerfai_csrf_validate($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        die('Invalid request.');
    }

    $step2 = array_map(fn($v) => is_string($v) ? trim($v) : $v, $_POST);
    // Backward/forward compatibility: support "secondary_*" aliases for other-income fields.
    if (!array_key_exists('other_income', $step2) && array_key_exists('secondary_income', $step2)) {
        $step2['other_income'] = $step2['secondary_income'];
    }
    if (!array_key_exists('other_income_source', $step2) && array_key_exists('secondary_income_source', $step2)) {
        $step2['other_income_source'] = $step2['secondary_income_source'];
    }
    if (!array_key_exists('co_other_income', $step2) && array_key_exists('co_secondary_income', $step2)) {
        $step2['co_other_income'] = $step2['co_secondary_income'];
    }
    if (!array_key_exists('co_other_income_source', $step2) && array_key_exists('co_secondary_income_source', $step2)) {
        $step2['co_other_income_source'] = $step2['co_secondary_income_source'];
    }
    $_SESSION['step2'] = $step2;
    $_SESSION['custom_step2'] = $_POST['custom'] ?? [];
    $extra_answers_json = json_encode([
        'step1' => $_SESSION['custom_step1'] ?? [],
        'step2' => $_SESSION['custom_step2'] ?? []
    ]);

    $has_cosigner = $step1['has_cosigner'] ?? 'No';
    if ($coRequired) {
        $has_cosigner = 'Yes';
    }
    $is_co = ($has_cosigner === 'Yes') ? 1 : 0;

    $toDecimal = fn($val) => ($val === null || $val === '' ? null : round((float)$val, 2));

    $employmentLength    = $toDecimal($step2['employment_length'] ?? null);
    $grossIncome         = $toDecimal($step2['income'] ?? null);
    $otherIncome         = $toDecimal($step2['other_income'] ?? null);
    $previousLength      = $toDecimal($step2['prev_length'] ?? null);

    // Set expiration
    $submitted_at = date('Y-m-d H:i:s');
    $expires_at = date('Y-m-d H:i:s', strtotime('+60 days'));

    // Check if record exists
    $check = $db->prepare("SELECT deal_id FROM credit_applications WHERE deal_id = ?");
    $check->execute([$deal_id]);
    $exists = $check->fetchColumn();

    $supportsPiiV2 = dealerfai_credit_applications_supports_pii_v2($db);
    $piiCiphertext = null;
    $piiNonce = null;
    $piiTag = null;
    if ($supportsPiiV2) {
        // v2: store employment-related PII updates in AEAD-encrypted blob columns.
        [$piiCiphertext, $piiNonce, $piiTag] = dealerfai_encrypt_pii_v2([
            'employer' => $step2['employer'] ?? null,
            'work_address' => $step2['work_address'] ?? null,
            'other_income_source' => $step2['other_income_source'] ?? null,
            'prev_employer' => $step2['prev_employer'] ?? null,
            'prev_phone' => $step2['prev_phone'] ?? null,
            'prev_address' => $step2['prev_address'] ?? null,
        ]);
    }

    try {
        if ($exists) {
            if ($supportsPiiV2) {
                $stmt = $db->prepare("UPDATE credit_applications SET
                is_co_applicant = ?, 
                encryption_version = 2,
                employer_name = NULL, employer_address = NULL, employment_length = ?, 
                gross_annual_income = ?, other_income = ?, other_income_source = NULL, 
                previous_employer_name = NULL, previous_employer_phone = NULL, previous_employer_address = NULL, previous_employment_time = ?, 
                extra_answers_json = ?, submitted_at = ?, expires_at = ?,
                pii_ciphertext = ?, pii_nonce = ?, pii_tag = ?
                WHERE deal_id = ?");
            $params = [
                $is_co,
                $employmentLength,
                $grossIncome,
                $otherIncome,
                $previousLength,
                $extra_answers_json,
                $submitted_at,
                $expires_at,
                $piiCiphertext,
                $piiNonce,
                $piiTag,
                $deal_id
            ];
                $stmt->execute($params);
            } else {
                // Legacy fallback (for environments where the migration hasn't been applied yet).
                include_once __DIR__ . '/../secure/config.php';
                $aes_key_bin = hex2bin(AES_KEY);
                $aes_iv = substr($aes_key_bin, 0, 16);
                $enc = fn($val) => ($val === null || $val === '') ? null : openssl_encrypt($val, 'aes-256-cbc', $aes_key_bin, OPENSSL_RAW_DATA, $aes_iv);

                $stmt = $db->prepare("UPDATE credit_applications SET
                is_co_applicant = ?, 
                employer_name = ?, employer_address = ?, employment_length = ?, 
                gross_annual_income = ?, other_income = ?, other_income_source = ?, 
                previous_employer_name = ?, previous_employer_phone = ?, previous_employer_address = ?, previous_employment_time = ?, 
                extra_answers_json = ?, submitted_at = ?, expires_at = ? 
                WHERE deal_id = ?");
            $params = [
                $is_co,
                $enc($step2['employer'] ?? ''),
                $enc($step2['work_address'] ?? ''),
                $employmentLength,
                $grossIncome,
                $otherIncome,
                ($step2['other_income_source'] ?? null),
                $enc($step2['prev_employer'] ?? ''),
                $enc($step2['prev_phone'] ?? ''),
                $enc($step2['prev_address'] ?? ''),
                $previousLength,
                $extra_answers_json,
                $submitted_at,
                $expires_at,
                $deal_id
            ];
                $stmt->execute($params);
            }
        } else {
            if ($supportsPiiV2) {
                $stmt = $db->prepare("INSERT INTO credit_applications (
                deal_id, is_co_applicant, encryption_version,
                employer_name, employer_address, employment_length, 
                gross_annual_income, other_income, other_income_source, 
                previous_employer_name, previous_employer_phone, previous_employer_address, previous_employment_time, 
                extra_answers_json, pii_ciphertext, pii_nonce, pii_tag, submitted_at, expires_at
            ) VALUES (?, ?, 2, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $params = [
                $deal_id,
                $is_co,
                null,
                null,
                $employmentLength,
                $grossIncome,
                $otherIncome,
                null, // other_income_source now stored in PII blob
                null,
                null,
                null,
                $previousLength,
                $extra_answers_json,
                $piiCiphertext,
                $piiNonce,
                $piiTag,
                $submitted_at,
                $expires_at
            ];
                $stmt->execute($params);
            } else {
                include_once __DIR__ . '/../secure/config.php';
                $aes_key_bin = hex2bin(AES_KEY);
                $aes_iv = substr($aes_key_bin, 0, 16);
                $enc = fn($val) => ($val === null || $val === '') ? null : openssl_encrypt($val, 'aes-256-cbc', $aes_key_bin, OPENSSL_RAW_DATA, $aes_iv);

                $stmt = $db->prepare("INSERT INTO credit_applications (
                deal_id, is_co_applicant, 
                employer_name, employer_address, employment_length, 
                gross_annual_income, other_income, other_income_source, 
                previous_employer_name, previous_employer_phone, previous_employer_address, previous_employment_time, 
                extra_answers_json, submitted_at, expires_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $params = [
                $deal_id,
                $is_co,
                $enc($step2['employer'] ?? ''),
                $enc($step2['work_address'] ?? ''),
                $employmentLength,
                $grossIncome,
                $otherIncome,
                ($step2['other_income_source'] ?? null),
                $enc($step2['prev_employer'] ?? ''),
                $enc($step2['prev_phone'] ?? ''),
                $enc($step2['prev_address'] ?? ''),
                $previousLength,
                $extra_answers_json,
                $submitted_at,
                $expires_at
            ];
                $stmt->execute($params);
            }
        }
    } catch (Throwable $e) {
        $postKeys = array_keys($step2);
        error_log('application_step2 save failed: deal_id=' . (string)$deal_id
            . ' supportsPiiV2=' . ($supportsPiiV2 ? '1' : '0')
            . ' exists=' . ($exists ? '1' : '0')
            . ' post_keys=' . json_encode($postKeys)
            . ' error=' . $e->getMessage());
        http_response_code(500);
        die('Unable to save Step 2 right now.');
    }

    header("Location: application_step3.php");
    exit;
}

$values = $_SESSION['step2'] ?? [];
$customAnswers = $_SESSION['custom_step2'] ?? [];

// Theme and vehicle info for display
$stmt = $db->prepare("SELECT organization, vehicle_make, vehicle_model, co_app_required FROM deals WHERE id = ?");
$stmt->execute([$deal_id]);
$deal = $stmt->fetch(PDO::FETCH_ASSOC);

$vehicle_make = $deal['vehicle_make'] ?? '';
$vehicle_model = $deal['vehicle_model'] ?? '';
$org_id = (int)($deal['organization'] ?? 0);
$coRequired = $coRequired || !empty($deal['co_app_required']);
$creditQuestionConfig = get_org_credit_app_question_config($db, $org_id);
$showQuestion = function (string $id) use ($creditQuestionConfig, $coRequired): bool {
    if ($coRequired && substr($id, 0, 3) === 'co_') {
        return true;
    }
    return credit_app_question_is_visible($creditQuestionConfig, 'step2', $id);
};
$customQuestions = get_org_custom_credit_questions($db, $org_id, 'step2');

$theme = dealerfai_get_theme_palette(null);
if (!empty($deal['organization'])) {
    $orgStmt = $db->prepare("SELECT logo_url, theme_variant FROM organizations WHERE id = ?");
    $orgStmt->execute([$deal['organization']]);
    if ($org = $orgStmt->fetch(PDO::FETCH_ASSOC)) {
        $theme = dealerfai_get_theme_palette($org['theme_variant'] ?? null, ['logo' => $org['logo_url'] ?? '']);
    }
}

$primaryPrevHasValues = false;
foreach (['prev_employer','prev_phone','prev_address','prev_length'] as $key) {
    if (!empty(trim($values[$key] ?? ''))) {
        $primaryPrevHasValues = true;
        break;
    }
}

$coPrevHasValues = false;
foreach (['co_prev_employer','co_prev_phone','co_prev_address','co_prev_length'] as $key) {
    if (!empty(trim($values[$key] ?? ''))) {
        $coPrevHasValues = true;
        break;
    }
}
$showPrimaryPreviousBlock = $showQuestion('employment_length') && (
    $showQuestion('prev_employer') ||
    $showQuestion('prev_phone') ||
    $showQuestion('prev_address') ||
    $showQuestion('prev_length')
);
$showCoPreviousBlock = $showQuestion('co_employment_length') && (
    $showQuestion('co_prev_employer') ||
    $showQuestion('co_prev_phone') ||
    $showQuestion('co_prev_address') ||
    $showQuestion('co_prev_length')
);
$has_cosigner = $step1['has_cosigner'] ?? 'No';
if ($coRequired) {
    $has_cosigner = 'Yes';
}
$showCoSection = $has_cosigner === 'Yes' && (
    $showQuestion('co_employer') ||
    $showQuestion('co_work_address') ||
    $showQuestion('co_position') ||
    $showQuestion('co_employment_length') ||
    $showQuestion('co_income') ||
    $showQuestion('co_other_income') ||
    $showQuestion('co_other_income_source') ||
    $showQuestion('co_prev_employer') ||
    $showQuestion('co_prev_phone') ||
    $showQuestion('co_prev_address') ||
    $showQuestion('co_prev_length')
);
$hasCustomQuestions = !empty($customQuestions);
$coRequiredAttr = $coRequired ? 'required' : '';
?>
<!DOCTYPE html>
<html>
<head>
  <?php include __DIR__ . "/includes/head_favicon.php"; ?>
<meta charset="UTF-8">
  <title>Step 2 - Employment & Income</title>
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
      height: 100%; width: 66%;
      text-align: center; color: white; font-size: 12px;
      line-height: 16px;
    }
    .section {
      margin-top: 30px;
      padding: 20px;
      background: #f9f9f9;
      border: 1px solid #ccc;
      border-radius: 8px;
    }
    .btn-row { display: flex; gap: 10px; margin-top: 20px; }
    .btn-secondary {
      background: #ccc;
      color: #333;
    }
    .previous-block {
      display: none;
      margin-top: 20px;
      padding: 15px;
      background: #fff;
      border: 1px dashed #ccc;
      border-radius: 6px;
    }
    .previous-block.active {
      display: block;
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
    <div class="progress-fill">Step 2 of 3</div>
  </div>

  <h2>Employment & Income Details</h2>
  <p><strong>Vehicle:</strong> <?= htmlspecialchars(trim($vehicle_make . ' ' . $vehicle_model)) ?></p>

  <form method="post" action="application_step2.php">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(dealerfai_csrf_get_token()) ?>">
    <div class="section">
      <h3>Primary Applicant Employment</h3>
      <?php if ($showQuestion('employer')): ?>
        <label>Employer</label>
        <input type="text" name="employer" value="<?= htmlspecialchars($values['employer'] ?? '') ?>">
      <?php endif; ?>

      <?php if ($showQuestion('work_address')): ?>
        <label>Work Address</label>
        <input type="text" name="work_address" value="<?= htmlspecialchars($values['work_address'] ?? '') ?>">
      <?php endif; ?>

      <?php if ($showQuestion('position')): ?>
        <label>Position/Title</label>
        <input type="text" name="position" value="<?= htmlspecialchars($values['position'] ?? '') ?>">
      <?php endif; ?>

      <?php if ($showQuestion('employment_length')): ?>
        <label>Employment Length (years)</label>
        <input type="number" name="employment_length" min="0" step="0.1" value="<?= htmlspecialchars($values['employment_length'] ?? '') ?>">
      <?php endif; ?>

      <?php if ($showQuestion('income')): ?>
        <label>Annual Income ($)</label>
        <input type="number" name="income" min="0" step="0.01" value="<?= htmlspecialchars($values['income'] ?? '') ?>">
      <?php endif; ?>

      <?php if ($showQuestion('other_income')): ?>
        <label>Other Income ($)</label>
        <input type="number" name="other_income" min="0" step="0.01" value="<?= htmlspecialchars($values['other_income'] ?? '') ?>">
      <?php endif; ?>

      <?php if ($showQuestion('other_income_source')): ?>
        <label>Other Income Source</label>
        <input type="text" name="other_income_source" value="<?= htmlspecialchars($values['other_income_source'] ?? '') ?>">
      <?php endif; ?>

      <?php if ($showPrimaryPreviousBlock): ?>
        <div id="previous-employment" class="previous-block">
          <?php if ($showQuestion('prev_employer')): ?>
            <label>Previous Employer</label>
            <input type="text" name="prev_employer" value="<?= htmlspecialchars($values['prev_employer'] ?? '') ?>">
          <?php endif; ?>

          <?php if ($showQuestion('prev_phone')): ?>
            <label>Previous Employer Phone</label>
            <input type="text" name="prev_phone" value="<?= htmlspecialchars($values['prev_phone'] ?? '') ?>">
          <?php endif; ?>

          <?php if ($showQuestion('prev_address')): ?>
            <label>Previous Employer Address</label>
            <input type="text" name="prev_address" value="<?= htmlspecialchars($values['prev_address'] ?? '') ?>">
          <?php endif; ?>

          <?php if ($showQuestion('prev_length')): ?>
            <label>Time at Previous Employer (years)</label>
            <input type="number" name="prev_length" min="0" step="0.1" value="<?= htmlspecialchars($values['prev_length'] ?? '') ?>">
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>

    <?php if ($showCoSection): ?>
      <div class="section">
        <h3>Co-Applicant Employment (Optional)</h3>
        <?php if ($showQuestion('co_employer')): ?>
          <label>Employer</label>
          <input type="text" name="co_employer" value="<?= htmlspecialchars($values['co_employer'] ?? '') ?>" <?= $coRequiredAttr ?>>
        <?php endif; ?>

        <?php if ($showQuestion('co_work_address')): ?>
          <label>Work Address</label>
          <input type="text" name="co_work_address" value="<?= htmlspecialchars($values['co_work_address'] ?? '') ?>" <?= $coRequiredAttr ?>>
        <?php endif; ?>

        <?php if ($showQuestion('co_position')): ?>
          <label>Position/Title</label>
          <input type="text" name="co_position" value="<?= htmlspecialchars($values['co_position'] ?? '') ?>" <?= $coRequiredAttr ?>>
        <?php endif; ?>

        <?php if ($showQuestion('co_employment_length')): ?>
          <label>Employment Length (years)</label>
          <input type="number" name="co_employment_length" min="0" step="0.1" value="<?= htmlspecialchars($values['co_employment_length'] ?? '') ?>" <?= $coRequiredAttr ?>>
        <?php endif; ?>

        <?php if ($showQuestion('co_income')): ?>
          <label>Annual Income ($)</label>
          <input type="number" name="co_income" min="0" step="0.01" value="<?= htmlspecialchars($values['co_income'] ?? '') ?>" <?= $coRequiredAttr ?>>
        <?php endif; ?>

        <?php if ($showQuestion('co_other_income')): ?>
          <label>Other Income ($)</label>
          <input type="number" name="co_other_income" min="0" step="0.01" value="<?= htmlspecialchars($values['co_other_income'] ?? '') ?>" <?= $coRequiredAttr ?>>
        <?php endif; ?>

        <?php if ($showQuestion('co_other_income_source')): ?>
          <label>Other Income Source</label>
          <input type="text" name="co_other_income_source" value="<?= htmlspecialchars($values['co_other_income_source'] ?? '') ?>" <?= $coRequiredAttr ?>>
        <?php endif; ?>

        <?php if ($showCoPreviousBlock): ?>
          <div id="co-previous-employment" class="previous-block">
          <?php if ($showQuestion('co_prev_employer')): ?>
            <label>Previous Employer</label>
            <input type="text" name="co_prev_employer" value="<?= htmlspecialchars($values['co_prev_employer'] ?? '') ?>" <?= $coRequiredAttr ?>>
          <?php endif; ?>

          <?php if ($showQuestion('co_prev_phone')): ?>
            <label>Previous Employer Phone</label>
            <input type="text" name="co_prev_phone" value="<?= htmlspecialchars($values['co_prev_phone'] ?? '') ?>" <?= $coRequiredAttr ?>>
          <?php endif; ?>

          <?php if ($showQuestion('co_prev_address')): ?>
            <label>Previous Employer Address</label>
            <input type="text" name="co_prev_address" value="<?= htmlspecialchars($values['co_prev_address'] ?? '') ?>" <?= $coRequiredAttr ?>>
          <?php endif; ?>

          <?php if ($showQuestion('co_prev_length')): ?>
            <label>Time at Previous Employer (years)</label>
            <input type="number" name="co_prev_length" min="0" step="0.1" value="<?= htmlspecialchars($values['co_prev_length'] ?? '') ?>" <?= $coRequiredAttr ?>>
          <?php endif; ?>
        </div>
      <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if ($hasCustomQuestions): ?>
      <div class="section">
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

    <div class="btn-row">
      <button type="button" class="btn-secondary" onclick="window.location.href='application_step1.php'">← Back</button>
      <button type="submit">Continue to Step 3</button>
    </div>
  </form>
</div>

<script nonce="<?= dealerfai_csp_nonce() ?>">
(function () {
  const showPreviousIfNeeded = (lengthInput, block, hasValues) => {
    if (!block || !lengthInput) return;
    const toggle = () => {
      const val = parseFloat(lengthInput.value);
      if (hasValues || (!Number.isNaN(val) && val < 2)) {
        block.classList.add('active');
      } else {
        block.classList.remove('active');
      }
    };
    toggle();
    ['input', 'change'].forEach(evt => lengthInput.addEventListener(evt, toggle));
  };

  const primaryBlock = document.getElementById('previous-employment');
  const primaryLengthInput = document.querySelector('input[name=\"employment_length\"]');
  showPreviousIfNeeded(primaryLengthInput, primaryBlock, <?= $primaryPrevHasValues ? 'true' : 'false' ?>);

  const coBlock = document.getElementById('co-previous-employment');
  const coLengthInput = document.querySelector('input[name=\"co_employment_length\"]');
  if (coBlock && coLengthInput) {
    showPreviousIfNeeded(coLengthInput, coBlock, <?= $coPrevHasValues ? 'true' : 'false' ?>);
  }
})();
</script>

</body>
</html>
