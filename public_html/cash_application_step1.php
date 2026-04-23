<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/security_headers.php';
require_once __DIR__ . '/includes/theme_head.php';
require_once __DIR__ . '/includes/csrf.php';
include 'db.php';
require_once __DIR__ . '/helpers/theme.php';

$token = $_GET['token'] ?? '';
if (!$token) { die('Missing token.'); }

$stmt = $db->prepare("SELECT id, organization, vehicle_make, vehicle_model FROM deals WHERE secure_token = ?");
$stmt->execute([$token]);
$deal = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$deal) { die('Invalid or expired link.'); }

$_SESSION['cash_deal_id'] = $deal['id'];
$_SESSION['cash_vehicle_make'] = $deal['vehicle_make'];
$_SESSION['cash_vehicle_model'] = $deal['vehicle_model'];

// Load organization info
$orgstmt = $db->prepare("SELECT logo_url, theme_variant FROM organizations WHERE id = ?");
$orgstmt->execute([$deal['organization']]);
$org = $orgstmt->fetch(PDO::FETCH_ASSOC);
$theme = dealerfai_get_theme_palette($org['theme_variant'] ?? null, ['logo' => $org['logo_url'] ?? '']);
$logo = $theme['logo'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!dealerfai_csrf_validate($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        die('Invalid request.');
    }

  $_SESSION['cash_step1'] = $_POST;
  header("Location: cash_application_step2.php");
  exit;
}
$csrfToken = dealerfai_csrf_get_token();

$themeColor = $theme['color'];
?>
<!DOCTYPE html>
<html>
<head>
  <?php include __DIR__ . "/includes/head_favicon.php"; ?>
<meta charset="UTF-8">
  <title>Cash Application - Step 1</title>
  <?php dealerfai_theme_head($theme ?? null); ?>
  <style nonce="<?= dealerfai_csp_nonce() ?>">
    body { font-family: Arial, sans-serif; background: <?= htmlspecialchars($theme['page_background']) ?>; padding: 40px; }
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
    select, input[type="number"], input[readonly] {
      width: 100%;
      padding: 10px;
      margin-top: 5px;
      border: 1px solid #ccc;
      border-radius: 4px;
      background-color: #f9f9f9;
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
    button:hover {
      background: #094c63;
    }
    .conditional-section { display: none; margin-top: 20px; }
    .logo { max-height: 60px; margin-bottom: 20px; display: block; }
  </style>
</head>
<body>
  <div class="card">
    <?php if ($logo): ?>
      <img src="<?= htmlspecialchars($logo) ?>" alt="Dealer Logo" class="logo">
    <?php endif; ?>
    <h2>Cash Purchase Information</h2>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

      <label>Vehicle Make</label>
      <input type="text" name="vehicle_make" value="<?= htmlspecialchars($deal['vehicle_make']) ?>" readonly>

      <label>Vehicle Model</label>
      <input type="text" name="vehicle_model" value="<?= htmlspecialchars($deal['vehicle_model']) ?>" readonly>

      <label>1. How are you paying for the vehicle?</label>
      <select name="payment_method" required>
        <option value="">Select an option</option>
        <option value="Cash">Paying full amount in cash</option>
        <option value="Elsewhere">Financing elsewhere</option>
      </select>

      <label>2. Would you like us to review options for better financing rates?</label>
      <select name="open_to_better_rates" id="rate_select" required>
        <option value="">Select an option</option>
        <option value="Yes">Yes</option>
        <option value="No">No</option>
      </select>

      <div id="financing_details" class="conditional-section">
        <label>3. What type of external financing?</label>
        <select name="external_financing_type">
          <option value="">Select type</option>
          <option value="Loan">Loan</option>
          <option value="Line of Credit">Line of Credit</option>
        </select>

        <label>4. What interest rate?</label>
        <input type="number" step="0.01" name="external_interest_rate" placeholder="e.g. 6.9">

        <label>5. Loan Term (months)</label>
        <input type="number" name="external_loan_term" placeholder="e.g. 60">
      </div>

      <button type="submit">Continue to Vehicle Questions</button>
    </form>
  </div>

  <script nonce="<?= dealerfai_csp_nonce() ?>">
    document.getElementById('rate_select').addEventListener('change', function() {
      const show = this.value === 'Yes';
      document.getElementById('financing_details').style.display = show ? 'block' : 'none';
    });
  </script>
</body>
</html>
