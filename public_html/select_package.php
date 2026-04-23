<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/security_headers.php';
require_once __DIR__ . '/includes/theme_head.php';
require_once __DIR__ . '/includes/csrf.php';
include 'db.php';
require_once __DIR__ . '/helpers/deal_audit.php';

if (!isset($_GET['deal'])) {
    echo "Missing deal ID.";
    exit;
}
$deal_id = intval($_GET['deal']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!dealerfai_csrf_validate($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        die('Invalid request.');
    }

    $selection = $_POST['package'];

    if ($selection === 'pick_own') {
        header("Location: select_individual_products.php?deal=$deal_id");
        exit;
    } else {
        $beforeDeal = fetch_deal_row_for_audit($db, $deal_id);
        $stmt = $db->prepare("UPDATE deals SET package_selected = ? WHERE id = ?");
        $stmt->execute([$selection, $deal_id]);
        $afterDeal = fetch_deal_row_for_audit($db, $deal_id);
        log_deal_change_audit($db, $deal_id, 'update', $beforeDeal, $afterDeal, ['context' => 'select_package']);
        header("Location: thank_you.php?deal=$deal_id");
        exit;
    }
}
$csrfToken = dealerfai_csrf_get_token();
?>
<!DOCTYPE html>
<html>
<head>
  <?php include __DIR__ . "/includes/head_favicon.php"; ?>
<meta charset="UTF-8">
  <title>Select Protection Package</title>
  <?php dealerfai_theme_head(); ?>
  <style nonce="<?= dealerfai_csp_nonce() ?>">
    body { font-family: Arial; max-width: 800px; margin: auto; background: #f5f5f5; padding: 30px; }
    h2 { text-align: center; }
    form { background: white; padding: 25px; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
    .option { border: 1px solid #ccc; padding: 15px; margin-bottom: 20px; border-radius: 6px; background: #fafafa; }
    button { padding: 12px 20px; font-size: 16px; background: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer; }
    input[type="submit"] { margin-top: 15px; }
  </style>
</head>
<body>
  <h2>Select Your Protection Package</h2>
  <form method="post">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <div class="option">
      <label>
        <input type="radio" name="package" value="fully_protected" required>
        <strong>Fully Protected Package</strong><br>
        Includes all recommended coverages for maximum peace of mind.
      </label>
    </div>

    <div class="option">
      <label>
        <input type="radio" name="package" value="essential">
        <strong>Essential Coverage Package</strong><br>
        Key protections such as Warranty, Tire & Rim, and Interior.
      </label>
    </div>

    <div class="option">
      <label>
        <input type="radio" name="package" value="none">
        <strong>No Protection</strong><br>
        I decline additional coverage.
      </label>
    </div>

    <div class="option">
      <label>
        <input type="radio" name="package" value="pick_own">
        <strong>Pick My Own Protection</strong><br>
        Select individual coverage options that matter most to you.
      </label>
    </div>

    <input type="submit" value="Continue">
  </form>
</body>
</html>
