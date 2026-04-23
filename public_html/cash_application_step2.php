<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/security_headers.php';
require_once __DIR__ . '/includes/theme_head.php';
require_once __DIR__ . '/includes/csrf.php';
include 'db.php';
include_once __DIR__ . '/scoring_engine.php';
require_once __DIR__ . '/helpers/organization_question_config.php';
require_once __DIR__ . '/helpers/custom_credit_questions.php';
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/helpers/theme.php';

use PHPMailer\PHPMailer\PHPMailer;

$localConfigPath = __DIR__ . '/../secure/local_config.php';
$localConfig = [];
if (file_exists($localConfigPath)) {
  $loaded = require $localConfigPath;
  if (is_array($loaded)) {
    $localConfig = $loaded;
  }
}
$smtpConfig = $localConfig['smtp'] ?? [];

$deal_id = $_SESSION['cash_deal_id'] ?? null;
if (!$deal_id) { die("Missing deal session."); }

$step1 = $_SESSION['cash_step1'] ?? [];

// Get organization and deal
$dealstmt = $db->prepare("SELECT * FROM deals WHERE id = ?");
$dealstmt->execute([$deal_id]);
$deal = $dealstmt->fetch(PDO::FETCH_ASSOC);

$orgstmt = $db->prepare("SELECT * FROM organizations WHERE id = ?");
$orgstmt->execute([$deal['organization']]);
$org = $orgstmt->fetch(PDO::FETCH_ASSOC);
$theme = dealerfai_get_theme_palette($org['theme_variant'] ?? null, ['logo' => $org['logo_url'] ?? '']);
$logo = $theme['logo'];
$themeColor = $theme['color'];

$creditQuestionConfig = get_org_credit_app_question_config($db, (int)$deal['organization']);
$showQuestion = fn(string $id): bool => credit_app_question_is_visible($creditQuestionConfig, 'step3', $id);
$customQuestions = get_org_custom_credit_questions($db, (int)$deal['organization'], 'step3');
$hasCustomQuestions = !empty($customQuestions);
$answers = $_SESSION['cash_step2'] ?? [];
$customAnswers = $_SESSION['custom_cash_step2'] ?? [];
$dealType = strtolower(trim((string)($deal['deal_type'] ?? '')));
$showLoanProtectionQuestions = in_array($dealType, ['finance', 'lease'], true) && (
  $showQuestion('life_protection') ||
  $showQuestion('disability_protection') ||
  $showQuestion('critical_illness') ||
  $showQuestion('job_loss')
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!dealerfai_csrf_validate($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    die('Invalid request.');
  }

  $regularUsers = $_POST['regular_users'] ?? [];
  if (!is_array($regularUsers)) {
    $regularUsers = [$regularUsers];
  }
  $step2 = [
    'annual_km'            => $_POST['annual_km'] ?? '',
    'driving_type'         => $_POST['driving_type'] ?? '',
    'gravel_exposure'      => $_POST['gravel_exposure'] ?? '',
    'road_conditions'      => $_POST['road_conditions'] ?? '',
    'overnight_parking'    => $_POST['overnight_parking'] ?? '',
    'regular_users'        => $regularUsers,
    'food_drink'           => $_POST['food_drink'] ?? '',
    'appearance_priority'  => $_POST['appearance_priority'] ?? '',
    'ownership_length'     => $_POST['ownership_length'] ?? '',
    'life_protection'      => $_POST['life_protection'] ?? '',
    'disability_protection'=> $_POST['disability_protection'] ?? '',
    'critical_illness'     => $_POST['critical_illness'] ?? '',
    'job_loss'             => $_POST['job_loss'] ?? '',
    'vehicle_make'         => $deal['vehicle_make'] ?? '',
    'vehicle_model'        => $deal['vehicle_model'] ?? ''
  ];
  $_SESSION['cash_step2'] = $_POST;
  $_SESSION['custom_cash_step2'] = $_POST['custom'] ?? [];
  $extra_answers_json = json_encode([
    'cash_step1' => $step1,
    'cash_step2_custom' => $_SESSION['custom_cash_step2'] ?? []
  ]);

  $stmt = $db->prepare("INSERT INTO applications (deal_id, usage_data, extra_answers_json, submitted_at, vehicle_make, vehicle_model)
                        VALUES (?, ?, ?, NOW(), ?, ?)
                        ON DUPLICATE KEY UPDATE
                          usage_data = VALUES(usage_data),
                          extra_answers_json = VALUES(extra_answers_json),
                          submitted_at = VALUES(submitted_at),
                          vehicle_make = VALUES(vehicle_make),
                          vehicle_model = VALUES(vehicle_model)");
  $stmt->execute([
    $deal_id,
    json_encode($step2),
    $extra_answers_json,
    $deal['vehicle_make'] ?? '',
    $deal['vehicle_model'] ?? ''
  ]);

  // Email finance managers
  $org_id = $deal['organization'];
  $user_stmt = $db->prepare("SELECT email FROM users WHERE organization = ? AND role LIKE ?");
  $user_stmt->execute([$org_id, '%Finance Manager%']);
  $emails = $user_stmt->fetchAll(PDO::FETCH_COLUMN);

  foreach ($emails as $email) {
    $subject = "Product selections completed for Deal #" . $deal_id;
    $body = "The customer for Deal #" . $deal_id . " has completed product selections for a cash deal.";
    try {
      $smtpHost = $smtpConfig['host'] ?? ($_ENV['SMTP_HOST'] ?? getenv('SMTP_HOST') ?? '');
      $smtpUser = $smtpConfig['user'] ?? ($_ENV['SMTP_USER'] ?? getenv('SMTP_USER') ?? '');
      $smtpPass = $smtpConfig['pass'] ?? ($_ENV['SMTP_PASS'] ?? getenv('SMTP_PASS') ?? '');
      $smtpPort = (int)($smtpConfig['port'] ?? ($_ENV['SMTP_PORT'] ?? getenv('SMTP_PORT') ?? 587));
      $smtpSecure = $smtpConfig['secure'] ?? ($_ENV['SMTP_SECURE'] ?? getenv('SMTP_SECURE') ?? PHPMailer::ENCRYPTION_STARTTLS);
      $fromEmail = $smtpConfig['from_email'] ?? $smtpUser;
      $fromName = $smtpConfig['from_name'] ?? 'DealerFAI';

      $mail = new PHPMailer(true);
      $mail->isSMTP();
      $mail->Host = $smtpHost;
      $mail->SMTPAuth = true;
      $mail->Username = $smtpUser;
      $mail->Password = $smtpPass;
      $mail->SMTPSecure = $smtpSecure;
      $mail->Port = $smtpPort;
      $mail->setFrom($fromEmail, $fromName);
      $mail->addAddress($email);
      $mail->Subject = $subject;
      $mail->Body = $body;
      $mail->send();
    } catch (\Throwable $e) {
      error_log('Failed to send cash deal notification email to ' . $email . ': ' . $e->getMessage());
    }
  }

  score_and_store_recommendations($db, (int)$deal_id);
  header("Location: recommendations_loading.php?deal_id=" . urlencode((string)$deal_id) . "&prescored=1");
  exit;
}
$csrfToken = dealerfai_csrf_get_token();
?>
<!DOCTYPE html>
<html>
<head>
  <?php include __DIR__ . "/includes/head_favicon.php"; ?>
<meta charset="UTF-8">
  <title>Cash Application - Step 2</title>
  <?php dealerfai_theme_head($theme ?? null); ?>
  <style nonce="<?= dealerfai_csp_nonce() ?>">
    body { font-family: Arial, sans-serif; background: #f4f6f8; padding: 40px; }
    .card {
      background: white;
      padding: 30px;
      max-width: 700px;
      margin: auto;
      border-radius: 8px;
      box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    }
    h2 { color: <?= $themeColor ?>; margin-top: 10px; }
    label { display: block; margin-top: 20px; font-weight: bold; }
    select {
      width: 100%;
      padding: 10px;
      margin-top: 5px;
      border: 1px solid #ccc;
      border-radius: 4px;
    }
    button {
      background: <?= $themeColor ?>;
      color: white;
      padding: 12px 20px;
      margin-top: 30px;
      font-size: 16px;
      border: none;
      border-radius: 4px;
      cursor: pointer;
    }
    button:hover { background: #094c63; }
    .logo { max-height: 60px; margin-bottom: 20px; display: block; }
  </style>
</head>
<body>
  <div class="card">
    <?php if ($logo): ?>
      <img src="<?= htmlspecialchars($logo) ?>" alt="Dealer Logo" class="logo">
    <?php endif; ?>
    <h2>Vehicle Use & Protection</h2>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
      <?php if ($showQuestion('annual_km')): ?>
        <label>About how many kilometres do you drive per year?</label>
        <select name="annual_km" required>
          <option value="" disabled <?= ($answers['annual_km'] ?? '') === '' ? 'selected' : '' ?>>Please select an option</option>
          <option value="under_15k" <?= ($answers['annual_km'] ?? '') === 'under_15k' ? 'selected' : '' ?>>Under 15,000 km</option>
          <option value="15k_20k" <?= ($answers['annual_km'] ?? '') === '15k_20k' ? 'selected' : '' ?>>15,000-20,000 km</option>
          <option value="20k_25k" <?= ($answers['annual_km'] ?? '') === '20k_25k' ? 'selected' : '' ?>>20,000-25,000 km</option>
          <option value="25k_plus" <?= ($answers['annual_km'] ?? '') === '25k_plus' ? 'selected' : '' ?>>25,000+ km</option>
        </select>
      <?php endif; ?>

      <?php if ($showQuestion('driving_type')): ?>
        <label>What best describes your driving?</label>
        <select name="driving_type" required>
          <option value="" disabled <?= ($answers['driving_type'] ?? '') === '' ? 'selected' : '' ?>>Please select an option</option>
          <option value="city" <?= ($answers['driving_type'] ?? '') === 'city' ? 'selected' : '' ?>>Mostly city</option>
          <option value="mix" <?= ($answers['driving_type'] ?? '') === 'mix' ? 'selected' : '' ?>>Mix of city &amp; highway</option>
          <option value="highway" <?= ($answers['driving_type'] ?? '') === 'highway' ? 'selected' : '' ?>>Mostly highway</option>
        </select>
      <?php endif; ?>

      <?php if ($showQuestion('gravel_exposure')): ?>
        <label>How often do you drive on gravel roads or unpaved surfaces?</label>
        <select name="gravel_exposure" required>
          <option value="" disabled <?= ($answers['gravel_exposure'] ?? '') === '' ? 'selected' : '' ?>>Please select an option</option>
          <option value="rarely" <?= ($answers['gravel_exposure'] ?? '') === 'rarely' ? 'selected' : '' ?>>Rarely / Never</option>
          <option value="sometimes" <?= ($answers['gravel_exposure'] ?? '') === 'sometimes' ? 'selected' : '' ?>>Sometimes</option>
          <option value="frequently" <?= ($answers['gravel_exposure'] ?? '') === 'frequently' ? 'selected' : '' ?>>Frequently</option>
        </select>
      <?php endif; ?>

      <?php if ($showQuestion('road_conditions')): ?>
        <label>How would you describe the condition of the roads you drive on most often?</label>
        <select name="road_conditions" required>
          <option value="" disabled <?= ($answers['road_conditions'] ?? '') === '' ? 'selected' : '' ?>>Please select an option</option>
          <option value="smooth" <?= ($answers['road_conditions'] ?? '') === 'smooth' ? 'selected' : '' ?>>Mostly smooth, well-maintained roads</option>
          <option value="some" <?= ($answers['road_conditions'] ?? '') === 'some' ? 'selected' : '' ?>>Some potholes, construction zones, or road debris</option>
          <option value="frequent" <?= ($answers['road_conditions'] ?? '') === 'frequent' ? 'selected' : '' ?>>Frequent potholes, rough pavement, or construction-related debris</option>
        </select>
      <?php endif; ?>

      <?php if ($showQuestion('overnight_parking')): ?>
        <label>Where is your vehicle usually parked overnight?</label>
        <select name="overnight_parking" required>
          <option value="" disabled <?= ($answers['overnight_parking'] ?? '') === '' ? 'selected' : '' ?>>Please select an option</option>
          <option value="garage" <?= ($answers['overnight_parking'] ?? '') === 'garage' ? 'selected' : '' ?>>Garage</option>
          <option value="driveway" <?= ($answers['overnight_parking'] ?? '') === 'driveway' ? 'selected' : '' ?>>Driveway</option>
          <option value="street" <?= ($answers['overnight_parking'] ?? '') === 'street' ? 'selected' : '' ?>>Street</option>
          <option value="condo" <?= ($answers['overnight_parking'] ?? '') === 'condo' ? 'selected' : '' ?>>Apartment / condo lot</option>
        </select>
      <?php endif; ?>

      <?php if ($showQuestion('regular_users')): ?>
        <label>Who regularly uses this vehicle? (Select all that apply)</label>
        <div class="mt-8">
          <?php
            $regularUsers = $answers['regular_users'] ?? [];
            if (!is_array($regularUsers)) {
              $regularUsers = [$regularUsers];
            }
          ?>
          <label class="fw-normal"><input type="checkbox" name="regular_users[]" value="just_me" <?= in_array('just_me', $regularUsers, true) ? 'checked' : '' ?>> Just me</label>
          <label class="fw-normal"><input type="checkbox" name="regular_users[]" value="multiple_drivers" <?= in_array('multiple_drivers', $regularUsers, true) ? 'checked' : '' ?>> Multiple drivers</label>
          <label class="fw-normal"><input type="checkbox" name="regular_users[]" value="kids" <?= in_array('kids', $regularUsers, true) ? 'checked' : '' ?>> Kids</label>
          <label class="fw-normal"><input type="checkbox" name="regular_users[]" value="pets" <?= in_array('pets', $regularUsers, true) ? 'checked' : '' ?>> Pets</label>
        </div>
      <?php endif; ?>

      <?php if ($showQuestion('food_drink')): ?>
        <label>Do you often eat or drink in your vehicle?</label>
        <select name="food_drink" required>
          <option value="" disabled <?= ($answers['food_drink'] ?? '') === '' ? 'selected' : '' ?>>Please select an option</option>
          <option value="rarely" <?= ($answers['food_drink'] ?? '') === 'rarely' ? 'selected' : '' ?>>Rarely</option>
          <option value="sometimes" <?= ($answers['food_drink'] ?? '') === 'sometimes' ? 'selected' : '' ?>>Sometimes</option>
          <option value="often" <?= ($answers['food_drink'] ?? '') === 'often' ? 'selected' : '' ?>>Often</option>
        </select>
      <?php endif; ?>

      <?php if ($showQuestion('appearance_priority')): ?>
        <label>How important is it to you to keep your vehicle looking like new?</label>
        <select name="appearance_priority" required>
          <option value="" disabled <?= ($answers['appearance_priority'] ?? '') === '' ? 'selected' : '' ?>>Please select an option</option>
          <option value="not_important" <?= ($answers['appearance_priority'] ?? '') === 'not_important' ? 'selected' : '' ?>>Not important</option>
          <option value="somewhat_important" <?= ($answers['appearance_priority'] ?? '') === 'somewhat_important' ? 'selected' : '' ?>>Somewhat important</option>
          <option value="very_important" <?= ($answers['appearance_priority'] ?? '') === 'very_important' ? 'selected' : '' ?>>Very important</option>
        </select>
      <?php endif; ?>

      <?php if ($showQuestion('ownership_length')): ?>
        <label>How long do you expect to keep this vehicle?</label>
        <select name="ownership_length" required>
          <option value="" disabled <?= ($answers['ownership_length'] ?? '') === '' ? 'selected' : '' ?>>Please select an option</option>
          <option value="less_3" <?= ($answers['ownership_length'] ?? '') === 'less_3' ? 'selected' : '' ?>>Less than 3 years</option>
          <option value="3_4" <?= ($answers['ownership_length'] ?? '') === '3_4' ? 'selected' : '' ?>>3-4 years</option>
          <option value="5_6" <?= ($answers['ownership_length'] ?? '') === '5_6' ? 'selected' : '' ?>>5-6 years</option>
          <option value="7_plus" <?= ($answers['ownership_length'] ?? '') === '7_plus' ? 'selected' : '' ?>>7+ years</option>
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
            <option value="yes" <?= ($answers['life_protection'] ?? '') === 'yes' ? 'selected' : '' ?>>Yes</option>
            <option value="no" <?= ($answers['life_protection'] ?? '') === 'no' ? 'selected' : '' ?>>No</option>
          </select>
        <?php endif; ?>

        <?php if ($showQuestion('disability_protection')): ?>
          <label>If you were sick or hurt and unable to work, would you like your vehicle payments to be paid on your behalf?</label>
          <select name="disability_protection" required>
            <option value="" disabled <?= ($answers['disability_protection'] ?? '') === '' ? 'selected' : '' ?>>Please select an option</option>
            <option value="yes" <?= ($answers['disability_protection'] ?? '') === 'yes' ? 'selected' : '' ?>>Yes</option>
            <option value="no" <?= ($answers['disability_protection'] ?? '') === 'no' ? 'selected' : '' ?>>No</option>
          </select>
        <?php endif; ?>

        <?php if ($showQuestion('critical_illness')): ?>
          <label>If you were diagnosed with a major illness such as cancer, heart attack, stroke, or another covered condition, would you like the loan to be paid out in full?</label>
          <select name="critical_illness" required>
            <option value="" disabled <?= ($answers['critical_illness'] ?? '') === '' ? 'selected' : '' ?>>Please select an option</option>
            <option value="yes" <?= ($answers['critical_illness'] ?? '') === 'yes' ? 'selected' : '' ?>>Yes</option>
            <option value="no" <?= ($answers['critical_illness'] ?? '') === 'no' ? 'selected' : '' ?>>No</option>
          </select>
        <?php endif; ?>

        <?php if ($showQuestion('job_loss')): ?>
          <label>If you were laid off from your job, would you like your vehicle payments to be covered while you search for a new job?</label>
          <select name="job_loss" required>
            <option value="" disabled <?= ($answers['job_loss'] ?? '') === '' ? 'selected' : '' ?>>Please select an option</option>
            <option value="yes" <?= ($answers['job_loss'] ?? '') === 'yes' ? 'selected' : '' ?>>Yes</option>
            <option value="no" <?= ($answers['job_loss'] ?? '') === 'no' ? 'selected' : '' ?>>No</option>
          </select>
        <?php endif; ?>
      <?php endif; ?>

      <button type="submit">Submit</button>
    </form>
  </div>
  <script nonce="<?= dealerfai_csp_nonce() ?>">
    (function () {
      const form = document.querySelector('form');
      if (!form) return;
      const userCheckboxes = form.querySelectorAll('input[name="regular_users[]"]');
      function hasRegularUserSelection() {
        if (!userCheckboxes.length) return true;
        return Array.from(userCheckboxes).some(input => input.checked);
      }
      form.addEventListener('submit', function (e) {
        if (!hasRegularUserSelection()) {
          e.preventDefault();
          alert('Please select at least one option for regular vehicle users.');
        }
      });
    })();
  </script>
</body>
</html>
