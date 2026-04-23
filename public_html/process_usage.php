<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/security_headers.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/helpers/usage_data.php';
include 'db.php';

header('Content-Type: application/json');
$rawBody = file_get_contents('php://input');
if ($rawBody) {
    $jsonData = json_decode($rawBody, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($jsonData)) {
        $_POST = $jsonData;
    }
}

$csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
$csrfBody = $_POST['csrf_token'] ?? null;
if (!dealerfai_csrf_validate(is_string($csrfHeader) && $csrfHeader !== '' ? $csrfHeader : (is_string($csrfBody) ? $csrfBody : null))) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid request.']);
    exit;
}

$deal_id = $_SESSION['deal_id'] ?? null;
if (!$deal_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid session.']);
    exit;
}

// Stop duplicate submissions once the credit app is locked.
$lockStmt = $db->prepare("SELECT credit_app_locked FROM deals WHERE id = ?");
$lockStmt->execute([$deal_id]);
if ($lockStmt->fetchColumn()) {
    http_response_code(409);
    echo json_encode(['success' => false, 'error' => 'This credit application is locked.']);
    exit;
}

// Step 3 data
$regularUsers = $_POST['regular_users'] ?? [];
if (!is_array($regularUsers)) {
  $regularUsers = [$regularUsers];
}

$usage_data = [
  'annual_km'            => $_POST['annual_km']            ?? '',
  'driving_type'         => $_POST['driving_type']         ?? '',
  'gravel_exposure'      => $_POST['gravel_exposure']      ?? '',
  'road_conditions'      => $_POST['road_conditions']      ?? '',
  'overnight_parking'    => $_POST['overnight_parking']    ?? '',
  'regular_users'        => $regularUsers,
  'food_drink'           => $_POST['food_drink']           ?? '',
  'appearance_priority'  => $_POST['appearance_priority']  ?? '',
  'ownership_length'     => $_POST['ownership_length']     ?? '',
  'life_protection'      => $_POST['life_protection']      ?? '',
  'disability_protection'=> $_POST['disability_protection']?? '',
  'critical_illness'     => $_POST['critical_illness']     ?? '',
  'job_loss'             => $_POST['job_loss']             ?? '',
];
$usage_data = dealerfai_normalize_usage_data($usage_data);
$drivingType = strtolower(trim((string)($usage_data['driving_type'] ?? '')));
$highway = in_array($drivingType, ['mix', 'highway'], true) ? 'Yes' : 'No';

// Step 1 & 2
$step1 = $_SESSION['step1'] ?? [];
$step2 = $_SESSION['step2'] ?? [];
$customStep1 = $_SESSION['custom_step1'] ?? [];
$customStep2 = $_SESSION['custom_step2'] ?? [];
$customStep3 = $_SESSION['custom_step3'] ?? [];
if (!is_array($customStep1)) {
  $customStep1 = [];
}
if (!is_array($customStep2)) {
  $customStep2 = [];
}
if (!is_array($customStep3)) {
  $customStep3 = [];
}
$customAnswers = [
  'step1' => $customStep1,
  'step2' => $customStep2,
  'step3' => $customStep3,
];
$usage_data['custom_answers'] = $customStep3;
$usage_json = json_encode($usage_data);
$extra_answers_json = json_encode($customAnswers);

$toInt = function ($value) {
  if ($value === '' || $value === null) return null;
  return (int)$value;
};
$toDecimal = function ($value) {
  if ($value === '' || $value === null) return null;
  return round((float)$value, 2);
};

$years_at_address      = $toInt($step1['years_at_address'] ?? null);
$monthly_payment       = $toDecimal($step1['monthly_payment'] ?? null);
$co_years_at_address   = $toInt($step1['co_years_at_address'] ?? null);
$co_monthly_payment    = $toDecimal($step1['co_monthly_payment'] ?? null);

$employment_length     = $toInt($step2['employment_length'] ?? null);
$prev_length           = $toInt($step2['prev_length'] ?? null);
$income                = $toDecimal($step2['income'] ?? null);
$other_income          = $toDecimal($step2['other_income'] ?? ($step2['secondary_income'] ?? null));
$other_income_source   = $step2['other_income_source'] ?? ($step2['secondary_income_source'] ?? '');

$co_employment_length  = $toInt($step2['co_employment_length'] ?? null);
$co_prev_length        = $toInt($step2['co_prev_length'] ?? null);
$co_income             = $toDecimal($step2['co_income'] ?? null);
$co_other_income       = $toDecimal($step2['co_other_income'] ?? ($step2['co_secondary_income'] ?? null));
$co_other_income_source = $step2['co_other_income_source'] ?? ($step2['co_secondary_income_source'] ?? '');

// Fetch vehicle make and model from deal
$vehicleStmt = $db->prepare("SELECT vehicle_make, vehicle_model FROM deals WHERE id = ?");
$vehicleStmt->execute([$deal_id]);
$vehicle = $vehicleStmt->fetch(PDO::FETCH_ASSOC);
$vehicle_make = $vehicle['vehicle_make'] ?? '';
$vehicle_model = $vehicle['vehicle_model'] ?? '';

// Determine if a record exists
$check = $db->prepare("SELECT id FROM applications WHERE deal_id = ?");
$check->execute([$deal_id]);
$app_exists = $check->fetchColumn();

if ($app_exists) {
    // UPDATE existing
    $stmt = $db->prepare("
        UPDATE applications SET
            full_name = ?, email = ?, phone = ?, address = ?, city = ?, province = ?, postal_code = ?,
            housing = ?, years_at_address = ?, monthly_payment = ?,
            has_cosigner = ?, co_full_name = ?, co_email = ?, co_phone = ?, co_address = ?, co_city = ?, co_province = ?, co_postal_code = ?, co_housing = ?, co_years_at_address = ?, co_monthly_payment = ?,
            employer = ?, work_address = ?, position = ?, employment_length = ?, prev_employer = ?, prev_phone = ?, prev_address = ?, prev_length = ?, income = ?, other_income = ?, other_income_source = ?,
            co_employer = ?, co_work_address = ?, co_position = ?, co_employment_length = ?, co_prev_employer = ?, co_prev_phone = ?, co_prev_address = ?, co_prev_length = ?, co_income = ?, co_other_income = ?, co_other_income_source = ?,
            vehicle_make = ?, vehicle_model = ?,
            usage_data = ?, highway_driving = ?, extra_answers_json = ?, submitted_at = NOW()
        WHERE deal_id = ?
    ");
    $success = $stmt->execute([
        $step1['full_name'] ?? '', $step1['email'] ?? '', $step1['phone'] ?? '', $step1['address'] ?? '', $step1['city'] ?? '', $step1['province'] ?? '', $step1['postal_code'] ?? '',
        $step1['housing'] ?? '', $years_at_address, $monthly_payment,
        $step1['has_cosigner'] ?? '', $step1['co_full_name'] ?? '', $step1['co_email'] ?? '', $step1['co_phone'] ?? '', $step1['co_address'] ?? '', $step1['co_city'] ?? '', $step1['co_province'] ?? '', $step1['co_postal_code'] ?? '', $step1['co_housing'] ?? '', $co_years_at_address, $co_monthly_payment,
        $step2['employer'] ?? '', $step2['work_address'] ?? '', $step2['position'] ?? '', $employment_length, $step2['prev_employer'] ?? '', $step2['prev_phone'] ?? '', $step2['prev_address'] ?? '', $prev_length, $income, $other_income, $other_income_source,
        $step2['co_employer'] ?? '', $step2['co_work_address'] ?? '', $step2['co_position'] ?? '', $co_employment_length, $step2['co_prev_employer'] ?? '', $step2['co_prev_phone'] ?? '', $step2['co_prev_address'] ?? '', $co_prev_length, $co_income, $co_other_income, $co_other_income_source,
        $vehicle_make, $vehicle_model,
        $usage_json, $highway, $extra_answers_json, $deal_id
    ]);
    if (!$success) {
        error_log("process_usage: failed to update application for deal_id $deal_id");
    }
} else {
    // INSERT new
    $stmt = $db->prepare("
        INSERT INTO applications (
            deal_id, full_name, email, phone, address, city, province, postal_code,
            housing, years_at_address, monthly_payment,
            has_cosigner, co_full_name, co_email, co_phone, co_address, co_city, co_province, co_postal_code, co_housing, co_years_at_address, co_monthly_payment,
            employer, work_address, position, employment_length, prev_employer, prev_phone, prev_address, prev_length, income, other_income, other_income_source,
            co_employer, co_work_address, co_position, co_employment_length, co_prev_employer, co_prev_phone, co_prev_address, co_prev_length, co_income, co_other_income, co_other_income_source,
            vehicle_make, vehicle_model,
            usage_data, highway_driving, extra_answers_json, started_at, submitted_at
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, 
            ?, ?, ?, 
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 
            ?, ?, 
            ?, ?, ?, ?, ?
        )
    ");
    $stmt->execute([
        $deal_id,
        $step1['full_name'] ?? '', $step1['email'] ?? '', $step1['phone'] ?? '', $step1['address'] ?? '', $step1['city'] ?? '', $step1['province'] ?? '', $step1['postal_code'] ?? '',
        $step1['housing'] ?? '', $years_at_address, $monthly_payment,
        $step1['has_cosigner'] ?? '', $step1['co_full_name'] ?? '', $step1['co_email'] ?? '', $step1['co_phone'] ?? '', $step1['co_address'] ?? '', $step1['co_city'] ?? '', $step1['co_province'] ?? '', $step1['co_postal_code'] ?? '', $step1['co_housing'] ?? '', $co_years_at_address, $co_monthly_payment,
        $step2['employer'] ?? '', $step2['work_address'] ?? '', $step2['position'] ?? '', $employment_length, $step2['prev_employer'] ?? '', $step2['prev_phone'] ?? '', $step2['prev_address'] ?? '', $prev_length, $income, $other_income, $other_income_source,
        $step2['co_employer'] ?? '', $step2['co_work_address'] ?? '', $step2['co_position'] ?? '', $co_employment_length, $step2['co_prev_employer'] ?? '', $step2['co_prev_phone'] ?? '', $step2['co_prev_address'] ?? '', $co_prev_length, $co_income, $co_other_income, $co_other_income_source,
        $vehicle_make, $vehicle_model,
        $usage_json, $highway, $extra_answers_json,
        date('Y-m-d H:i:s'), date('Y-m-d H:i:s')
    ]);
}

require_once __DIR__ . '/helpers/billing_helpers.php';

// Record a billable usage event once per deal submission.
$usageDealStmt = $db->prepare("SELECT organization, deal_type FROM deals WHERE id = ?");
$usageDealStmt->execute([$deal_id]);
$usageDeal = $usageDealStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$usageOrgId = $usageDeal['organization'] ?? null;
$submissionType = strtolower((string)($usageDeal['deal_type'] ?? ''));
if (!in_array($submissionType, ['cash', 'finance', 'lease'], true)) {
    $submissionType = 'finance';
}
if (is_string($usageOrgId) && ctype_digit($usageOrgId)) {
    $usageOrgId = (int)$usageOrgId;
}
if (is_int($usageOrgId) && $usageOrgId > 0) {
    $usageUserId = $_SESSION['user_id'] ?? null;
    try {
        log_usage_event(
            $db,
            $usageOrgId,
            $deal_id,
            'deal_submission',
            $usageUserId ? (int)$usageUserId : null,
            $submissionType
        );
    } catch (Throwable $e) {
        error_log("log_usage_event failed: " . $e->getMessage());
    }
}

unset($_SESSION['step1'], $_SESSION['step2'], $_SESSION['custom_step1'], $_SESSION['custom_step2'], $_SESSION['custom_step3']);
echo json_encode([
    'success' => true,
    'redirect' => "accessories.php?deal_id=" . urlencode($deal_id)
]);
exit;
