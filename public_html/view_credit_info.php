<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
include 'auth.php';
require_once __DIR__ . '/includes/theme_head.php';
include 'db.php';
require_once __DIR__ . '/helpers/session_utils.php';
require_once __DIR__ . '/helpers/usage_data.php';

$deal_id = filter_input(INPUT_GET, 'deal_id', FILTER_VALIDATE_INT)
    ?? filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT)
    ?? filter_input(INPUT_POST, 'deal_id', FILTER_VALIDATE_INT)
    ?? filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if (!$deal_id) { die("Missing deal ID."); }

$roles = load_session_roles();
$isAdmin = in_array('Admin', $roles, true);
$adminAlertCount = $isAdmin ? get_admin_alert_count($db) : 0;
if (!$isAdmin && !in_array('General Manager', $roles, true) && !in_array('Finance Manager', $roles, true)) {
    die("Access denied.");
}

// Fetch theme from organization
$stmt = $db->prepare("SELECT organization FROM deals WHERE id = ?");
$stmt->execute([$deal_id]);
$org_id = $stmt->fetchColumn();

if (!$org_id) {
    http_response_code(404);
    die("Deal not found.");
}

// Org-level access check: ensure this deal belongs to an org the user can access.
if (!$isAdmin) {
    $accessibleOrgs = get_accessible_organizations();
    if (!in_array((int)$org_id, $accessibleOrgs, true)) {
        http_response_code(403);
        die("Access denied.");
    }
}

$theme = ['logo' => '', 'color' => '#0a6280'];
if ($org_id) {
    $stmt = $db->prepare("SELECT logo_url, theme_variant FROM organizations WHERE id = ?");
    $stmt->execute([$org_id]);
    $org = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($org) {
        $theme['logo'] = $org['logo_url'] ?? '';
        $theme['color'] = $org['theme_variant'] === 'jlr' ? '#4a4a4a' : (in_array($org['theme_variant'], ['land_rover', 'jaguar'], true) ? '#1A1A1A' : '#0a6280');
    }
}

// Fetch application data
$stmt = $db->prepare("SELECT * FROM applications WHERE deal_id = ?");
$stmt->execute([$deal_id]);
$app = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$app) { echo "<p style='color:red;'>No application found for this deal.</p>"; exit; }
$piiClearedAtRaw = trim((string)($app['pii_cleared_at'] ?? ''));
$piiCleared = $piiClearedAtRaw !== '';
$piiClearedLabel = 'N/A';
if ($piiCleared) {
  $ts = strtotime($piiClearedAtRaw);
  if ($ts !== false) {
    $piiClearedLabel = date('F j, Y g:i A', $ts);
  } else {
    $piiClearedLabel = $piiClearedAtRaw;
  }
}

$usage_data = dealerfai_decode_usage_data($app['usage_data'] ?? null);

$annualKm = $usage_data['annual_km'] ?? '';
$drivingType = $usage_data['driving_type'] ?? '';
$gravelExposure = $usage_data['gravel_exposure'] ?? '';
$roadConditions = $usage_data['road_conditions'] ?? '';
$overnightParking = $usage_data['overnight_parking'] ?? '';
$regularUsers = $usage_data['regular_users'] ?? [];
$foodDrink = $usage_data['food_drink'] ?? '';
$appearancePriority = $usage_data['appearance_priority'] ?? '';
$ownershipLength = $usage_data['ownership_length'] ?? '';
$lifeProtection = $usage_data['life_protection'] ?? '';
$disabilityProtection = $usage_data['disability_protection'] ?? '';
$criticalIllness = $usage_data['critical_illness'] ?? '';
$jobLoss = $usage_data['job_loss'] ?? '';
$highwayDrivingLabel = 'N/A';
if ($drivingType !== '') {
  $highwayDrivingLabel = in_array($drivingType, ['highway', 'mix'], true) ? 'Yes' : 'No';
}

$labelMap = [
  'annual_km' => [
    'under_15k' => 'Under 15,000 km',
    '15k_20k' => '15,000–20,000 km',
    '20k_25k' => '20,000–25,000 km',
    '25k_plus' => '25,000+ km'
  ],
  'driving_type' => [
    'city' => 'Mostly city',
    'mix' => 'Mix of city & highway',
    'highway' => 'Mostly highway'
  ],
  'gravel_exposure' => [
    'rarely' => 'Rarely / Never',
    'sometimes' => 'Sometimes',
    'frequently' => 'Frequently'
  ],
  'road_conditions' => [
    'smooth' => 'Mostly smooth, well-maintained roads',
    'some' => 'Some potholes, construction zones, or road debris',
    'frequent' => 'Frequent potholes, rough pavement, or construction-related debris'
  ],
  'overnight_parking' => [
    'garage' => 'Garage',
    'driveway' => 'Driveway',
    'street' => 'Street',
    'condo' => 'Apartment / condo lot'
  ],
  'food_drink' => [
    'rarely' => 'Rarely',
    'sometimes' => 'Sometimes',
    'often' => 'Often'
  ],
  'appearance_priority' => [
    'not_important' => 'Not important',
    'somewhat_important' => 'Somewhat important',
    'very_important' => 'Very important'
  ],
  'ownership_length' => [
    'less_3' => 'Less than 3 years',
    '3_4' => '3–4 years',
    '5_6' => '5–6 years',
    '7_plus' => '7+ years'
  ],
  'yes_no' => [
    'yes' => 'Yes',
    'no' => 'No'
  ],
  'regular_users' => [
    'just_me' => 'Just me',
    'multiple_drivers' => 'Multiple drivers',
    'kids' => 'Kids',
    'pets' => 'Pets'
  ]
];

$formatValue = function ($value, array $map) {
  if ($value === '' || $value === null) {
    return 'N/A';
  }
  return $map[$value] ?? $value;
};

$regularUsersLabel = 'N/A';
if (is_array($regularUsers) && $regularUsers) {
  $labels = [];
  foreach ($regularUsers as $entry) {
    $labels[] = $labelMap['regular_users'][$entry] ?? $entry;
  }
  $regularUsersLabel = implode(', ', array_unique($labels));
}
?>
<!DOCTYPE html>
<html>
<head>
  <?php include __DIR__ . "/includes/head_favicon.php"; ?>
<meta charset="UTF-8">
  <title>DealerFAI - Credit Info</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php dealerfai_theme_head($theme ?? null); ?>
  <style nonce="<?= dealerfai_csp_nonce() ?>">
    body { font-family: "Segoe UI", sans-serif; background: #f4f6f8; margin: 0; padding: 0; color: #111111; }
    header { background-color: <?= htmlspecialchars($theme['color']) ?>; color: white; padding: 30px 40px; text-align: center; position: relative; }
    header img {
      max-width: 480px;
      max-height: 180px;
      height: auto;
      display: block;
      margin: 0 auto 10px;
    }
    header h1 { margin: 0; font-size: 28px; }
    nav { background-color: <?= htmlspecialchars($theme['color']) ?>; padding: 12px; text-align: center; }
    nav a { color: white; margin: 0 20px; text-decoration: none; font-weight: bold; }
    nav a:hover { text-decoration: underline; }
    main { padding: 30px 40px; }
    .card {
      max-width: 800px; margin: 20px auto; background: white;
      padding: 30px; border-radius: 8px;
      box-shadow: 0 2px 10px rgba(0,0,0,0.08);
    }
    h2 { margin-top: 0; color: #333; }
    p { margin: 10px 0; }
    label { font-weight: bold; }
    .section { margin-top: 30px; }
    .btn {
      background: <?= htmlspecialchars($theme['color']) ?>;
      color: white;
      padding: 12px 20px;
      border: none;
      border-radius: 4px;
      cursor: pointer;
      font-size: 16px;
      text-decoration: none;
      display: inline-block;
      margin-top: 15px;
    }
    .btn:hover { opacity: 0.9; }
    .notice {
      margin: 14px 0 20px;
      padding: 12px 14px;
      border-radius: 8px;
      border: 1px solid #f1c40f;
      background: #fff7dd;
      color: #6f5500;
    }
  </style>
</head>
<body>

<header>
  <?php if (!empty($theme['logo'])): ?>
    <img src="<?= htmlspecialchars($theme['logo']) ?>" alt="Dealer Logo">
  <?php else: ?>
    <h1>DealerFAI - Credit Info</h1>
  <?php endif; ?>
</header>

<nav>
  <a href="dashboard.php">Dashboard</a>
  <a href="view_deals.php">View Deals</a>
  <a href="create_deal.php">Create Deal</a>
  <div style="float:right; margin-right:20px; display:flex; gap:12px; align-items:center;">
    <?php if ($isAdmin): ?>
      <a href="admin_error_alerts.php">Alerts<?php if ($adminAlertCount > 0): ?> <span style="display:inline-block; min-width:18px; padding:2px 8px; border-radius:999px; background:#d7263d; color:#fff; font-size:12px; font-weight:bold; text-align:center; margin-left:6px;"><?= $adminAlertCount ?></span><?php endif; ?></a>
    <?php endif; ?>
    <a href="logout.php">Logout</a>
  </div>
</nav>

<main>
<div class="card">
  <?php if ($piiCleared): ?>
    <div class="notice">
      Personal application data from credit application steps 1-2 was cleared on <strong><?= htmlspecialchars($piiClearedLabel) ?></strong>.
    </div>
  <?php else: ?>
    <h2>Primary Applicant</h2>
    <p><label>Name:</label> <?= htmlspecialchars($app['full_name']) ?></p>
    <p><label>Email:</label> <?= htmlspecialchars($app['email']) ?></p>
    <p><label>Phone:</label> <?= htmlspecialchars($app['phone']) ?></p>
    <p><label>Address:</label> <?= htmlspecialchars($app['address']) ?>, <?= htmlspecialchars($app['city']) ?>, <?= htmlspecialchars($app['province']) ?> <?= htmlspecialchars($app['postal_code']) ?></p>
    <p><label>Housing:</label> <?= htmlspecialchars($app['housing']) ?></p>
    <p><label>Years at Address:</label> <?= htmlspecialchars($app['years_at_address']) ?></p>
    <p><label>Monthly Payment:</label> <?= htmlspecialchars($app['monthly_payment']) ?></p>

    <div class="section">
      <h2>Primary Employment</h2>
      <p><label>Employer:</label> <?= htmlspecialchars($app['employer']) ?></p>
      <p><label>Work Address:</label> <?= htmlspecialchars($app['work_address']) ?></p>
      <p><label>Position:</label> <?= htmlspecialchars($app['position']) ?></p>
      <p><label>Employment Length:</label> <?= htmlspecialchars($app['employment_length']) ?> years</p>
      <?php if (!empty($app['prev_employer'])): ?>
        <p><label>Previous Employer:</label> <?= htmlspecialchars($app['prev_employer']) ?></p>
        <p><label>Previous Phone:</label> <?= htmlspecialchars($app['prev_phone']) ?></p>
        <p><label>Previous Address:</label> <?= htmlspecialchars($app['prev_address']) ?></p>
        <p><label>Time There:</label> <?= htmlspecialchars($app['prev_length']) ?> years</p>
      <?php endif; ?>
      <p><label>Annual Income:</label> $<?= number_format((float)($app['income'] ?? 0), 2) ?></p>
      <p><label>Other Income:</label> $<?= number_format((float)($app['other_income'] ?? 0), 2) ?></p>
      <p><label>Other Source:</label> <?= htmlspecialchars($app['other_income_source']) ?></p>
    </div>

    <?php if ($app['has_cosigner'] === 'Yes'): ?>
      <div class="section">
        <h2>Co-Applicant</h2>
        <p><label>Name:</label> <?= htmlspecialchars($app['co_full_name']) ?></p>
        <p><label>Email:</label> <?= htmlspecialchars($app['co_email']) ?></p>
        <p><label>Phone:</label> <?= htmlspecialchars($app['co_phone']) ?></p>
        <p><label>Address:</label> <?= htmlspecialchars($app['co_address']) ?>, <?= htmlspecialchars($app['co_city']) ?>, <?= htmlspecialchars($app['co_province']) ?> <?= htmlspecialchars($app['co_postal_code']) ?></p>
        <p><label>Housing:</label> <?= htmlspecialchars($app['co_housing']) ?></p>
        <p><label>Years at Address:</label> <?= htmlspecialchars($app['co_years_at_address']) ?></p>
        <p><label>Monthly Payment:</label> <?= htmlspecialchars($app['co_monthly_payment']) ?></p>

        <div class="section">
          <h3>Co-Applicant Employment</h3>
          <p><label>Employer:</label> <?= htmlspecialchars($app['co_employer']) ?></p>
          <p><label>Work Address:</label> <?= htmlspecialchars($app['co_work_address']) ?></p>
          <p><label>Position:</label> <?= htmlspecialchars($app['co_position']) ?></p>
          <p><label>Employment Length:</label> <?= htmlspecialchars($app['co_employment_length']) ?> years</p>
          <?php if (!empty($app['co_prev_employer'])): ?>
            <p><label>Previous Employer:</label> <?= htmlspecialchars($app['co_prev_employer']) ?></p>
            <p><label>Previous Phone:</label> <?= htmlspecialchars($app['co_prev_phone']) ?></p>
            <p><label>Previous Address:</label> <?= htmlspecialchars($app['co_prev_address']) ?></p>
            <p><label>Time There:</label> <?= htmlspecialchars($app['co_prev_length']) ?> years</p>
          <?php endif; ?>
          <p><label>Annual Income:</label> $<?= number_format((float)($app['co_income'] ?? 0), 2) ?></p>
          <p><label>Other Income:</label> $<?= number_format((float)($app['co_other_income'] ?? 0), 2) ?></p>
          <p><label>Other Source:</label> <?= htmlspecialchars($app['co_other_income_source']) ?></p>
        </div>
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <div class="section">
    <h2>Vehicle Usage</h2>
    <p><label>Highway Driving:</label> <?= htmlspecialchars($highwayDrivingLabel) ?></p>
    <p><label>Annual Distance:</label> <?= htmlspecialchars($formatValue($annualKm, $labelMap['annual_km'])) ?></p>
    <p><label>Driving Type:</label> <?= htmlspecialchars($formatValue($drivingType, $labelMap['driving_type'])) ?></p>
    <p><label>Gravel Road Exposure:</label> <?= htmlspecialchars($formatValue($gravelExposure, $labelMap['gravel_exposure'])) ?></p>
    <p><label>Road Conditions:</label> <?= htmlspecialchars($formatValue($roadConditions, $labelMap['road_conditions'])) ?></p>
    <p><label>Overnight Parking:</label> <?= htmlspecialchars($formatValue($overnightParking, $labelMap['overnight_parking'])) ?></p>
    <p><label>Regular Vehicle Users:</label> <?= htmlspecialchars($regularUsersLabel) ?></p>
    <p><label>Food & Drink:</label> <?= htmlspecialchars($formatValue($foodDrink, $labelMap['food_drink'])) ?></p>
    <p><label>Appearance Priority:</label> <?= htmlspecialchars($formatValue($appearancePriority, $labelMap['appearance_priority'])) ?></p>
    <p><label>Planned Ownership Length:</label> <?= htmlspecialchars($formatValue($ownershipLength, $labelMap['ownership_length'])) ?></p>
    <p><label>Life Protection:</label> <?= htmlspecialchars($formatValue($lifeProtection, $labelMap['yes_no'])) ?></p>
    <p><label>Disability / Sickness & Injury:</label> <?= htmlspecialchars($formatValue($disabilityProtection, $labelMap['yes_no'])) ?></p>
    <p><label>Critical Illness:</label> <?= htmlspecialchars($formatValue($criticalIllness, $labelMap['yes_no'])) ?></p>
    <p><label>Job Loss:</label> <?= htmlspecialchars($formatValue($jobLoss, $labelMap['yes_no'])) ?></p>
  </div>
</div>

<div style="text-align:center; margin-top: 30px;">
  <a class="btn" href="view_deal.php?id=<?= urlencode($deal_id) ?>">← Back to Deal</a>
</div>
</main>

</body>
</html>
