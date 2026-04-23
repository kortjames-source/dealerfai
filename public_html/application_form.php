<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/csrf.php';
include 'db.php';

$token = $_GET['token'] ?? '';
if (!$token) {
  die('Missing token.');
}

$stmt = $db->prepare("SELECT id, credit_app_locked FROM deals WHERE secure_token = ?");
$stmt->execute([$token]);
$deal = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$deal) {
  die('Invalid or expired link.');
}

if (!empty($deal['credit_app_locked'])) {
  die('This credit application is locked. Please contact the dealership to unlock it.');
}

$deal_id = $deal['id'];

// Check if application exists
$app_stmt = $db->prepare("SELECT id, started_at FROM applications WHERE deal_id = ?");
$app_stmt->execute([$deal_id]);
$app = $app_stmt->fetch(PDO::FETCH_ASSOC);

if (!$app) {
  // Create a new blank application and log start
  $insert = $db->prepare("INSERT INTO applications (deal_id, started_at) VALUES (?, NOW())");
  $insert->execute([$deal_id]);
} elseif (!$app['started_at']) {
  // Log first visit timestamp if not already set
  $update = $db->prepare("UPDATE applications SET started_at = NOW() WHERE deal_id = ?");
  $update->execute([$deal_id]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!dealerfai_csrf_validate($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    die('Invalid request.');
  }

  // Here we simulate form submission. Replace with full form saving logic.
  $complete = $db->prepare("UPDATE applications SET submitted_at = NOW() WHERE deal_id = ?");
  $complete->execute([$deal_id]);
  echo "<p>✅ Application submitted successfully.</p>";
  exit;
}
$csrfToken = dealerfai_csrf_get_token();
?>
<!DOCTYPE html>
<html>
<head>
  <?php include __DIR__ . "/includes/head_favicon.php"; ?>
<meta charset="UTF-8">
  <title>Start Application</title>
</head>
<body>
  <h2>Customer Application</h2>
  <p>Deal ID: <?= $deal_id ?></p>

  <form method="post">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <!-- Your form fields go here -->
    <p>Example form in progress... (replace with your real fields)</p>
    <button type="submit">Submit Application</button>
  </form>
</body>
</html>
