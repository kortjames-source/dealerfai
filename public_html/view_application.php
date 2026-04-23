<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
include 'auth.php';
require_once __DIR__ . '/includes/theme_head.php';
include 'db.php';

$deal_id = isset($_GET['deal']) ? intval($_GET['deal']) : 0;
if (!$deal_id) {
    echo "Invalid deal ID.";
    exit;
}

// Only allow Finance Manager or General Manager
$roles = $_SESSION['roles'] ?? [];
if (!in_array('Finance Manager', $roles) && !in_array('General Manager', $roles)) {
    echo "Access Denied – You do not have permission to view this page.";
    exit;
}

// Get application
$appStmt = $db->prepare("SELECT * FROM applications WHERE deal_id = ?");
$appStmt->execute([$deal_id]);
$app = $appStmt->fetch(PDO::FETCH_ASSOC);

if (!$app) {
    echo "No application found for this deal.";
    exit;
}
$piiClearedAtRaw = trim((string)($app['pii_cleared_at'] ?? ''));
$piiCleared = $piiClearedAtRaw !== '';
$piiClearedLabel = $piiClearedAtRaw;
if ($piiCleared) {
    $ts = strtotime($piiClearedAtRaw);
    if ($ts !== false) {
        $piiClearedLabel = date('F j, Y g:i A', $ts);
    }
}
$usageData = json_decode((string)($app['usage_data'] ?? '{}'), true);
if (!is_array($usageData)) {
    $usageData = [];
}
?>
<!DOCTYPE html>
<html>
<head>
  <?php include __DIR__ . "/includes/head_favicon.php"; ?>
<meta charset="UTF-8">
  <title>View Application</title>
  <?php dealerfai_theme_head(); ?>
  <style nonce="<?= dealerfai_csp_nonce() ?>">
    body { font-family: Arial, sans-serif; max-width: 900px; margin: auto; background: #f2f2f2; padding: 30px; }
    h2 { text-align: center; }
    .section { background: white; margin-top: 30px; padding: 20px; border-radius: 6px; box-shadow: 0 0 8px rgba(0,0,0,0.1); }
    .section h3 { border-bottom: 1px solid #ccc; padding-bottom: 10px; margin-bottom: 20px; }
    .field { margin-bottom: 15px; }
    .field label { display: block; font-weight: bold; margin-bottom: 5px; }
    .field div { background: #f9f9f9; padding: 10px; border: 1px solid #ccc; border-radius: 4px; }
    .notice { background:#fff7dd; border:1px solid #f1c40f; color:#6f5500; padding:12px; border-radius:8px; margin:18px 0; }
    pre { white-space: pre-wrap; word-break: break-word; background:#f9f9f9; border:1px solid #ccc; border-radius:4px; padding:10px; }
  </style>
</head>
<body>
  <h2>Customer Credit Application</h2>
  <?php if ($piiCleared): ?>
    <div class="notice">
      Personal application data from credit application steps 1-2 was cleared on <strong><?= htmlspecialchars($piiClearedLabel) ?></strong>.
    </div>
  <?php endif; ?>

  <?php if (!$piiCleared): ?>
    <div class="section">
      <h3>Personal Information</h3>
      <?php
      $fields = [
          'full_name' => 'Full Legal Name',
          'email' => 'Email',
          'phone' => 'Phone Number',
          'address' => 'Address',
          'city' => 'City',
          'province' => 'Province',
          'postal_code' => 'Postal Code',
          'housing' => 'Housing Status',
          'monthly_payment' => 'Monthly Housing Payment'
      ];
      foreach ($fields as $key => $label): ?>
        <div class="field">
          <label><?= $label ?></label>
          <div><?= htmlspecialchars((string)($app[$key] ?? '')) ?></div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="section">
      <h3>Employment & Income</h3>
      <?php
      $work = [
          'employer' => 'Employer',
          'work_address' => 'Work Address',
          'position' => 'Position',
          'employment_length' => 'Employment Length',
          'prev_employer' => 'Previous Employer',
          'prev_phone' => 'Previous Employer Phone',
          'prev_address' => 'Previous Employer Address',
          'income' => 'Annual Income',
          'other_income' => 'Other Income'
      ];
      foreach ($work as $key => $label): ?>
        <div class="field">
          <label><?= $label ?></label>
          <div><?= htmlspecialchars((string)($app[$key] ?? '')) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="section">
    <h3>Vehicle Usage</h3>
    <pre><?= htmlspecialchars((string)json_encode($usageData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
  </div>
</body>
</html>
