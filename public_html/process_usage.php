<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
include 'db.php';
require_once __DIR__ . '/helpers/usage_data.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$deal_id = (int)($_POST['deal_id'] ?? 0);
if ($deal_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid deal ID']);
    exit;
}

// Fetch deal for vehicle info and organization
$deal_stmt = $db->prepare("SELECT id, vehicle_year, vehicle_make, vehicle_model, organization FROM deals WHERE id = ?");
$deal_stmt->execute([$deal_id]);
$deal = $deal_stmt->fetch(PDO::FETCH_ASSOC);

if (!$deal) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Deal not found']);
    exit;
}

// Combine all steps from session
$step1 = $_SESSION['application_step1'][$deal_id] ?? [];
$step2 = $_SESSION['application_step2'][$deal_id] ?? [];
$step3 = $_POST; // Step 3 comes from the POST request

if (empty($step1) || empty($step2)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing application data in session. Please restart the process.']);
    exit;
}

// Process usage data
$usage = [
    'ownership_length' => $step3['ownership_length'] ?? '',
    'annual_km' => $step3['annual_km'] ?? '',
    'driving_type' => $step3['driving_type'] ?? '',
    'gravel_exposure' => $step3['gravel_exposure'] ?? '',
    'road_conditions' => $step3['road_conditions'] ?? '',
    'overnight_parking' => $step3['overnight_parking'] ?? '',
    'regular_users' => $step3['regular_users'] ?? [],
    'food_drink' => $step3['food_drink'] ?? '',
    'appearance_priority' => $step3['appearance_priority'] ?? '',
    'custom_answers' => $step3['custom_answers'] ?? []
];

$usage_json = json_encode($usage);
$highway = in_array($usage['driving_type'], ['highway', 'mix']) ? 'yes' : 'no';
$extra_answers_json = json_encode($step3['extra_answers'] ?? []);

// Numeric conversion helpers
$years_at_address = isset($step1['years_at_address']) && $step1['years_at_address'] !== '' ? (int)$step1['years_at_address'] : null;
$monthly_payment = isset($step1['monthly_payment']) && $step1['monthly_payment'] !== '' ? (float)$step1['monthly_payment'] : null;
$co_years_at_address = isset($step1['co_years_at_address']) && $step1['co_years_at_address'] !== '' ? (int)$step1['co_years_at_address'] : null;
$co_monthly_payment = isset($step1['co_monthly_payment']) && $step1['co_monthly_payment'] !== '' ? (float)$step1['co_monthly_payment'] : null;

$employment_length = isset($step2['employment_length']) && $step2['employment_length'] !== '' ? (int)$step2['employment_length'] : null;
$prev_length = isset($step2['prev_length']) && $step2['prev_length'] !== '' ? (int)$step2['prev_length'] : null;
$income = isset($step2['income']) && $step2['income'] !== '' ? (float)$step2['income'] : null;
$other_income = isset($step2['other_income']) && $step2['other_income'] !== '' ? (float)$step2['other_income'] : null;
$other_income_source = $step2['other_income_source'] ?? '';

$co_employment_length = isset($step2['co_employment_length']) && $step2['co_employment_length'] !== '' ? (int)$step2['co_employment_length'] : null;
$co_prev_length = isset($step2['co_prev_length']) && $step2['co_prev_length'] !== '' ? (int)$step2['co_prev_length'] : null;
$co_income = isset($step2['co_income']) && $step2['co_income'] !== '' ? (float)$step2['co_income'] : null;
$co_other_income = isset($step2['co_other_income']) && $step2['co_other_income'] !== '' ? (float)$step2['co_other_income'] : null;
$co_other_income_source = $step2['co_other_income_source'] ?? '';

// Build the data array for named parameters
$data = [
    'deal_id' => $deal_id,
    'full_name' => $step1['full_name'] ?? '',
    'email' => $step1['email'] ?? '',
    'phone' => $step1['phone'] ?? '',
    'address' => $step1['address'] ?? '',
    'city' => $step1['city'] ?? '',
    'province' => $step1['province'] ?? '',
    'postal_code' => $step1['postal_code'] ?? '',
    'housing' => $step1['housing'] ?? '',
    'years_at_address' => $years_at_address,
    'monthly_payment' => $monthly_payment,
    'has_cosigner' => $step1['has_cosigner'] ?? '',
    'co_full_name' => $step1['co_full_name'] ?? '',
    'co_email' => $step1['co_email'] ?? '',
    'co_phone' => $step1['co_phone'] ?? '',
    'co_address' => $step1['co_address'] ?? '',
    'co_city' => $step1['co_city'] ?? '',
    'co_province' => $step1['co_province'] ?? '',
    'co_postal_code' => $step1['co_postal_code'] ?? '',
    'co_housing' => $step1['co_housing'] ?? '',
    'co_years_at_address' => $co_years_at_address,
    'co_monthly_payment' => $co_monthly_payment,
    'employer' => $step2['employer'] ?? '',
    'work_address' => $step2['work_address'] ?? '',
    'position' => $step2['position'] ?? '',
    'employment_length' => $employment_length,
    'prev_employer' => $step2['prev_employer'] ?? '',
    'prev_phone' => $step2['prev_phone'] ?? '',
    'prev_address' => $step2['prev_address'] ?? '',
    'prev_length' => $prev_length,
    'income' => $income,
    'other_income' => $other_income,
    'other_income_source' => $other_income_source,
    'co_employer' => $step2['co_employer'] ?? '',
    'co_work_address' => $step2['co_work_address'] ?? '',
    'co_position' => $step2['co_position'] ?? '',
    'co_employment_length' => $co_employment_length,
    'co_prev_employer' => $step2['co_prev_employer'] ?? '',
    'co_prev_phone' => $step2['co_prev_phone'] ?? '',
    'co_prev_address' => $step2['co_prev_address'] ?? '',
    'co_prev_length' => $co_prev_length,
    'co_income' => $co_income,
    'co_other_income' => $co_other_income,
    'co_other_income_source' => $co_other_income_source,
    'vehicle_make' => $deal['vehicle_make'] ?? '',
    'vehicle_model' => $deal['vehicle_model'] ?? '',
    'usage_data' => $usage_json,
    'highway_driving' => $highway,
    'extra_answers_json' => $extra_answers_json
];

// Determine if a record exists
$check = $db->prepare("SELECT id FROM applications WHERE deal_id = ?");
$check->execute([$deal_id]);
$app_exists = $check->fetchColumn();

if ($app_exists) {
    $sql = "UPDATE applications SET 
                full_name = :full_name, email = :email, phone = :phone, address = :address, city = :city, province = :province, postal_code = :postal_code,
                housing = :housing, years_at_address = :years_at_address, monthly_payment = :monthly_payment,
                has_cosigner = :has_cosigner, co_full_name = :co_full_name, co_email = :co_email, co_phone = :co_phone, co_address = :co_address, co_city = :co_city, co_province = :co_province, co_postal_code = :co_postal_code, co_housing = :co_housing, co_years_at_address = :co_years_at_address, co_monthly_payment = :co_monthly_payment,
                employer = :employer, work_address = :work_address, position = :position, employment_length = :employment_length, prev_employer = :prev_employer, prev_phone = :prev_phone, prev_address = :prev_address, prev_length = :prev_length, income = :income, other_income = :other_income, other_income_source = :other_income_source,
                co_employer = :co_employer, co_work_address = :co_work_address, co_position = :co_position, co_employment_length = :co_employment_length, co_prev_employer = :co_prev_employer, co_prev_phone = :co_prev_phone, co_prev_address = :co_prev_address, co_prev_length = :co_prev_length, co_income = :co_income, co_other_income = :co_other_income, co_other_income_source = :co_other_income_source,
                vehicle_make = :vehicle_make, vehicle_model = :vehicle_model,
                usage_data = :usage_data, highway_driving = :highway_driving, extra_answers_json = :extra_answers_json,
                submitted_at = NOW()
            WHERE deal_id = :deal_id";
} else {
    $sql = "INSERT INTO applications (
                deal_id, full_name, email, phone, address, city, province, postal_code,
                housing, years_at_address, monthly_payment,
                has_cosigner, co_full_name, co_email, co_phone, co_address, co_city, co_province, co_postal_code, co_housing, co_years_at_address, co_monthly_payment,
                employer, work_address, position, employment_length, prev_employer, prev_phone, prev_address, prev_length, income, other_income, other_income_source,
                co_employer, co_work_address, co_position, co_employment_length, co_prev_employer, co_prev_phone, co_prev_address, co_prev_length, co_income, co_other_income, co_other_income_source,
                vehicle_make, vehicle_model,
                usage_data, highway_driving, extra_answers_json,
                started_at, submitted_at
            ) VALUES (
                :deal_id, :full_name, :email, :phone, :address, :city, :province, :postal_code,
                :housing, :years_at_address, :monthly_payment,
                :has_cosigner, :co_full_name, :co_email, :co_phone, :co_address, :co_city, :co_province, :co_postal_code, :co_housing, :co_years_at_address, :co_monthly_payment,
                :employer, :work_address, :position, :employment_length, :prev_employer, :prev_phone, :prev_address, :prev_length, :income, :other_income, :other_income_source,
                :co_employer, :co_work_address, :co_position, :co_employment_length, :co_prev_employer, :co_prev_phone, :co_prev_address, :co_prev_length, :co_income, :co_other_income, :co_other_income_source,
                :vehicle_make, :vehicle_model,
                :usage_data, :highway_driving, :extra_answers_json,
                NOW(), NOW()
            )";
}

try {
    $stmt = $db->prepare($sql);
    $stmt->execute($data);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    exit;
}

// Lock the credit app after Step 3 submission.
$lockUserId = $_SESSION['user_id'] ?? null;
$lockStmt = $db->prepare("
    UPDATE deals 
    SET credit_app_locked = 1, 
        credit_app_locked_at = NOW(), 
        credit_app_locked_by = ? 
    WHERE id = ? 
      AND (credit_app_locked = 0 OR credit_app_locked IS NULL)
");
$lockStmt->execute([$lockUserId, $deal_id]);

// Clear session data for this deal
unset($_SESSION['application_step1'][$deal_id], $_SESSION['application_step2'][$deal_id]);

echo json_encode(['success' => true]);
