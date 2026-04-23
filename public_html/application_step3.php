<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/security_headers.php';
require_once __DIR__ . '/includes/theme_head.php';
include 'db.php';
require_once __DIR__ . '/helpers/organization_question_config.php';
require_once __DIR__ . '/helpers/custom_credit_questions.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/helpers/theme.php';

$deal_id = $_SESSION['deal_id'] ?? null;
if (!$deal_id) { die('Invalid session.'); }

// Prevent edits once a credit app is locked for this deal.
$lockStmt = $db->prepare("SELECT credit_app_locked FROM deals WHERE id = ?");
$lockStmt->execute([$deal_id]);
if ($lockStmt->fetchColumn()) {
  die('This credit application is locked. Please contact a manager to unlock it.');
}

// Preserve Step 3 responses if this page is posted back (e.g. validation scenario)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST)) {
  if (!dealerfai_csrf_validate($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    die('Invalid request.');
  }
  $_SESSION['step3'] = $_POST;
}

// Fetch Step 1 and Step 2 data
$step1 = $_SESSION['step1'] ?? [];
$step2 = $_SESSION['step2'] ?? [];
$answers = $_SESSION['step3'] ?? [];
$has_cosigner = $step1['has_cosigner'] ?? 'No';

// Pull province, vehicle_colour, in_service_date from the deal
$stmt = $db->prepare("SELECT organization, province, vehicle_colour, in_service_date, deal_type FROM deals WHERE id = ?");
$stmt->execute([$deal_id]);
$deal = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$deal) { die('Deal not found.'); }

$_SESSION['organization'] = $deal['organization'];
$_SESSION['province'] = $deal['province'];
$_SESSION['vehicle_colour'] = $deal['vehicle_colour'];
$_SESSION['in_service_date'] = $deal['in_service_date'];
$creditQuestionConfig = get_org_credit_app_question_config($db, (int)$deal['organization']);
$showQuestion = fn(string $id): bool => credit_app_question_is_visible($creditQuestionConfig, 'step3', $id);
$customQuestions = get_org_custom_credit_questions($db, (int)$deal['organization'], 'step3');

// Theme settings
$theme = dealerfai_get_theme_palette(null);
$orgStmt = $db->prepare("SELECT logo_url, theme_variant FROM organizations WHERE id = ?");
$orgStmt->execute([$deal['organization']]);
if ($org = $orgStmt->fetch(PDO::FETCH_ASSOC)) {
  $theme = dealerfai_get_theme_palette($org['theme_variant'] ?? null, ['logo' => $org['logo_url'] ?? '']);
}
$deal_type = strtolower(trim((string)($deal['deal_type'] ?? '')));
$show_loan_protections = in_array($deal_type, ['finance', 'lease'], true);
$showLoanProtectionQuestions = $show_loan_protections && (
  $showQuestion('life_protection') ||
  $showQuestion('disability_protection') ||
  $showQuestion('critical_illness') ||
  $showQuestion('job_loss')
);
$showRegularUsers = $showQuestion('regular_users');
$hasCustomQuestions = !empty($customQuestions);
$optionCatalog = get_credit_app_answer_option_catalog();
$customAnswers = $_SESSION['custom_step3'] ?? [];
$csrfToken = dealerfai_csrf_get_token();
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Step 3 - Vehicle Usage</title>
  <?php include __DIR__ . "/includes/head_favicon.php"; ?>
<?php dealerfai_theme_head($theme ?? null); ?>
  <style nonce="<?= dealerfai_csp_nonce() ?>">
    body { font-family: Arial, sans-serif; background: <?= htmlspecialchars($theme['page_background']) ?>; margin: 0; padding: 0; }
    header {
      background: <?= htmlspecialchars($theme['header_background']) ?>;
      padding: 20px; text-align: center; color: <?= htmlspecialchars($theme['header_text']) ?>;
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
      background: #e0e0e0; border-radius: 4px; height: 16px;
      margin-bottom: 20px; overflow: hidden;
    }
    .progress-fill {
      background: <?= $theme['color'] ?>;
      height: 100%; width: 100%;
      text-align: center; color: white; font-size: 12px;
      line-height: 16px;
    }
    .btn-back {
      background: #ccc;
      color: #333;
      margin-right: 10px;
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
    <div class="progress-fill">Step 3 of 3</div>
  </div>

  <h2>Vehicle Usage & Protection Needs</h2>

  <form method="post" action="submit_usage.php">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

	    <?php if ($showQuestion('annual_km')): ?>
	      <label>About how many kilometres do you drive per year?</label>
	      <select name="annual_km" required>
	        <option value="" disabled <?= ($answers['annual_km'] ?? '') === '' ? 'selected' : '' ?>>Please select an option</option>
	        <?php foreach (($optionCatalog['annual_km']['options'] ?? []) as $opt): ?>
	          <?php $val = (string)($opt['value'] ?? ''); $lbl = (string)($opt['label'] ?? $val); ?>
	          <?php if ($val === '') continue; ?>
	          <option value="<?= htmlspecialchars($val) ?>" <?= ($answers['annual_km'] ?? '') === $val ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
	        <?php endforeach; ?>
	      </select>
	    <?php endif; ?>

	    <?php if ($showQuestion('driving_type')): ?>
	      <label>What best describes your driving?</label>
	      <select name="driving_type" required>
	        <option value="" disabled <?= ($answers['driving_type'] ?? '') === '' ? 'selected' : '' ?>>Please select an option</option>
	        <?php foreach (($optionCatalog['driving_type']['options'] ?? []) as $opt): ?>
	          <?php $val = (string)($opt['value'] ?? ''); $lbl = (string)($opt['label'] ?? $val); ?>
	          <?php if ($val === '') continue; ?>
	          <option value="<?= htmlspecialchars($val) ?>" <?= ($answers['driving_type'] ?? '') === $val ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
	        <?php endforeach; ?>
	      </select>
	    <?php endif; ?>

	    <?php if ($showQuestion('gravel_exposure')): ?>
	      <label>How often do you drive on gravel roads or unpaved surfaces?</label>
	      <select name="gravel_exposure" required>
	        <option value="" disabled <?= ($answers['gravel_exposure'] ?? '') === '' ? 'selected' : '' ?>>Please select an option</option>
	        <?php foreach (($optionCatalog['gravel_exposure']['options'] ?? []) as $opt): ?>
	          <?php $val = (string)($opt['value'] ?? ''); $lbl = (string)($opt['label'] ?? $val); ?>
	          <?php if ($val === '') continue; ?>
	          <option value="<?= htmlspecialchars($val) ?>" <?= ($answers['gravel_exposure'] ?? '') === $val ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
	        <?php endforeach; ?>
	      </select>
	    <?php endif; ?>

	    <?php if ($showQuestion('road_conditions')): ?>
	      <label>How would you describe the condition of the roads you drive on most often?</label>
	      <select name="road_conditions" required>
	        <option value="" disabled <?= ($answers['road_conditions'] ?? '') === '' ? 'selected' : '' ?>>Please select an option</option>
	        <?php foreach (($optionCatalog['road_conditions']['options'] ?? []) as $opt): ?>
	          <?php $val = (string)($opt['value'] ?? ''); $lbl = (string)($opt['label'] ?? $val); ?>
	          <?php if ($val === '') continue; ?>
	          <option value="<?= htmlspecialchars($val) ?>" <?= ($answers['road_conditions'] ?? '') === $val ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
	        <?php endforeach; ?>
	      </select>
	    <?php endif; ?>

	    <?php if ($showQuestion('overnight_parking')): ?>
	      <label>Where is your vehicle usually parked overnight?</label>
	      <select name="overnight_parking" required>
	        <option value="" disabled <?= ($answers['overnight_parking'] ?? '') === '' ? 'selected' : '' ?>>Please select an option</option>
	        <?php foreach (($optionCatalog['overnight_parking']['options'] ?? []) as $opt): ?>
	          <?php $val = (string)($opt['value'] ?? ''); $lbl = (string)($opt['label'] ?? $val); ?>
	          <?php if ($val === '') continue; ?>
	          <option value="<?= htmlspecialchars($val) ?>" <?= ($answers['overnight_parking'] ?? '') === $val ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
	        <?php endforeach; ?>
	      </select>
	    <?php endif; ?>

	    <?php if ($showRegularUsers): ?>
	      <label>Who regularly uses this vehicle? (Select all that apply)</label>
	      <div class="mt-8">
	        <?php
	          $regularUsers = $answers['regular_users'] ?? [];
	          if (!is_array($regularUsers)) {
	            $regularUsers = [$regularUsers];
	          }
	        ?>
	        <?php foreach (($optionCatalog['regular_users']['options'] ?? []) as $opt): ?>
	          <?php $val = (string)($opt['value'] ?? ''); $lbl = (string)($opt['label'] ?? $val); ?>
	          <?php if ($val === '') continue; ?>
	          <label class="fw-normal">
	            <input type="checkbox" name="regular_users[]" value="<?= htmlspecialchars($val) ?>" <?= in_array($val, $regularUsers ?? [], true) ? 'checked' : '' ?>>
	            <?= htmlspecialchars($lbl) ?>
	          </label>
	        <?php endforeach; ?>
	      </div>
	    <?php endif; ?>

	    <?php if ($showQuestion('food_drink')): ?>
	      <label>Do you often eat or drink in your vehicle?</label>
	      <select name="food_drink" required>
	        <option value="" disabled <?= ($answers['food_drink'] ?? '') === '' ? 'selected' : '' ?>>Please select an option</option>
	        <?php foreach (($optionCatalog['food_drink']['options'] ?? []) as $opt): ?>
	          <?php $val = (string)($opt['value'] ?? ''); $lbl = (string)($opt['label'] ?? $val); ?>
	          <?php if ($val === '') continue; ?>
	          <option value="<?= htmlspecialchars($val) ?>" <?= ($answers['food_drink'] ?? '') === $val ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
	        <?php endforeach; ?>
	      </select>
	    <?php endif; ?>

	    <?php if ($showQuestion('appearance_priority')): ?>
	      <label>How important is it to you to keep your vehicle looking like new?</label>
	      <select name="appearance_priority" required>
	        <option value="" disabled <?= ($answers['appearance_priority'] ?? '') === '' ? 'selected' : '' ?>>Please select an option</option>
	        <?php foreach (($optionCatalog['appearance_priority']['options'] ?? []) as $opt): ?>
	          <?php $val = (string)($opt['value'] ?? ''); $lbl = (string)($opt['label'] ?? $val); ?>
	          <?php if ($val === '') continue; ?>
	          <option value="<?= htmlspecialchars($val) ?>" <?= ($answers['appearance_priority'] ?? '') === $val ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
	        <?php endforeach; ?>
	      </select>
	    <?php endif; ?>

	    <?php if ($showQuestion('ownership_length')): ?>
	      <label>How long do you expect to keep this vehicle?</label>
	      <select name="ownership_length" required>
	        <option value="" disabled <?= ($answers['ownership_length'] ?? '') === '' ? 'selected' : '' ?>>Please select an option</option>
	        <?php foreach (($optionCatalog['ownership_length']['options'] ?? []) as $opt): ?>
	          <?php $val = (string)($opt['value'] ?? ''); $lbl = (string)($opt['label'] ?? $val); ?>
	          <?php if ($val === '') continue; ?>
	          <option value="<?= htmlspecialchars($val) ?>" <?= ($answers['ownership_length'] ?? '') === $val ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
	        <?php endforeach; ?>
	      </select>
	    <?php endif; ?>

    <?php if ($hasCustomQuestions): ?>
      <h3 class="mt-24">Additional Questions</h3>
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
    <?php endif; ?>

    <?php if ($showLoanProtectionQuestions): ?>
      <h3 class="mt-24">Loan &amp; Income Protections</h3>

	      <?php if ($showQuestion('life_protection')): ?>
	        <label>If you were to pass away, would you like the loan to be paid off in full so the vehicle becomes a clear asset for your estate?</label>
	        <select name="life_protection" required>
	          <option value="" disabled <?= ($answers['life_protection'] ?? '') === '' ? 'selected' : '' ?>>Please select an option</option>
	          <?php foreach (($optionCatalog['life_protection']['options'] ?? []) as $opt): ?>
	            <?php $val = (string)($opt['value'] ?? ''); $lbl = (string)($opt['label'] ?? $val); ?>
	            <?php if ($val === '') continue; ?>
	            <option value="<?= htmlspecialchars($val) ?>" <?= ($answers['life_protection'] ?? '') === $val ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
	          <?php endforeach; ?>
	        </select>
	      <?php endif; ?>

	      <?php if ($showQuestion('disability_protection')): ?>
	        <label>If you were sick or hurt and unable to work, would you like your vehicle payments to be paid on your behalf?</label>
	        <select name="disability_protection" required>
	          <option value="" disabled <?= ($answers['disability_protection'] ?? '') === '' ? 'selected' : '' ?>>Please select an option</option>
	          <?php foreach (($optionCatalog['disability_protection']['options'] ?? []) as $opt): ?>
	            <?php $val = (string)($opt['value'] ?? ''); $lbl = (string)($opt['label'] ?? $val); ?>
	            <?php if ($val === '') continue; ?>
	            <option value="<?= htmlspecialchars($val) ?>" <?= ($answers['disability_protection'] ?? '') === $val ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
	          <?php endforeach; ?>
	        </select>
	      <?php endif; ?>

	      <?php if ($showQuestion('critical_illness')): ?>
	        <label>If you were diagnosed with a major illness such as cancer, heart attack, stroke, or another covered condition, would you like the loan to be paid out in full?</label>
	        <select name="critical_illness" required>
	          <option value="" disabled <?= ($answers['critical_illness'] ?? '') === '' ? 'selected' : '' ?>>Please select an option</option>
	          <?php foreach (($optionCatalog['critical_illness']['options'] ?? []) as $opt): ?>
	            <?php $val = (string)($opt['value'] ?? ''); $lbl = (string)($opt['label'] ?? $val); ?>
	            <?php if ($val === '') continue; ?>
	            <option value="<?= htmlspecialchars($val) ?>" <?= ($answers['critical_illness'] ?? '') === $val ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
	          <?php endforeach; ?>
	        </select>
	      <?php endif; ?>

	      <?php if ($showQuestion('job_loss')): ?>
	        <label>If you were laid off from your job, would you like your vehicle payments to be covered while you search for a new job?</label>
	        <select name="job_loss" required>
	          <option value="" disabled <?= ($answers['job_loss'] ?? '') === '' ? 'selected' : '' ?>>Please select an option</option>
	          <?php foreach (($optionCatalog['job_loss']['options'] ?? []) as $opt): ?>
	            <?php $val = (string)($opt['value'] ?? ''); $lbl = (string)($opt['label'] ?? $val); ?>
	            <?php if ($val === '') continue; ?>
	            <option value="<?= htmlspecialchars($val) ?>" <?= ($answers['job_loss'] ?? '') === $val ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
	          <?php endforeach; ?>
	        </select>
	      <?php endif; ?>
    <?php endif; ?>

    <button type="button" class="btn btn-back" onclick="window.location.href='application_step2.php'">← Back</button>
    <button type="submit" class="btn" id="submit-button">Submit Application</button>
  </form>
</div>
<script nonce="<?= dealerfai_csp_nonce() ?>">
  const form = document.querySelector('form');
  const submitBtn = document.getElementById('submit-button');
  const storageKey = 'usage-form-data';
  const userCheckboxes = form.querySelectorAll('input[name="regular_users[]"]');

  function saveFormState() {
    const formData = new FormData(form);
    const data = {};
    for (const [key, value] of formData.entries()) {
      if (data[key] === undefined) {
        data[key] = value;
      } else if (Array.isArray(data[key])) {
        data[key].push(value);
      } else {
        data[key] = [data[key], value];
      }
    }
    // Avoid persistent storage of customer-data on disk; sessionStorage clears when the tab closes.
    sessionStorage.setItem(storageKey, JSON.stringify(data));
  }

  function restoreFormState() {
    try {
      const saved = sessionStorage.getItem(storageKey);
      if (!saved) return;
      const data = JSON.parse(saved);
      Object.keys(data).forEach(name => {
        const field = form.elements[name];
        if (!field) return;
        const value = data[name];
        if (field instanceof RadioNodeList) {
          const values = Array.isArray(value) ? value : [value];
          for (const option of field) {
            if (option.type === 'checkbox') {
              option.checked = values.includes(option.value);
            } else if (option.type === 'radio') {
              option.checked = option.value === value;
            }
          }
          return;
        }
        if (field.type === 'checkbox') {
          field.checked = !!value;
          return;
        }
        if (field.type === 'select-multiple' && Array.isArray(value)) {
          for (const option of field.options) {
            option.selected = value.includes(option.value);
          }
          return;
        }
        field.value = value;
      });
    } catch (err) {
      console.warn('Failed to restore form state', err);
    }
  }

  function hasRegularUserSelection() {
    if (!userCheckboxes.length) return true;
    return Array.from(userCheckboxes).some(input => input.checked);
  }

  window.addEventListener('load', restoreFormState);
  form.addEventListener('change', saveFormState);
  form.addEventListener('input', saveFormState);

  form.addEventListener('submit', function (e) {
    if (!hasRegularUserSelection()) {
      e.preventDefault();
      alert('Please select at least one option for regular vehicle users.');
      return;
    }
    submitBtn.disabled = true;
    submitBtn.textContent = 'Submitting...';
    sessionStorage.removeItem(storageKey);
  });
</script>

</body>
</html>
