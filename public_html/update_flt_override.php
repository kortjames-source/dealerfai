<?php
include 'auth.php';
include 'db.php';
require_once __DIR__ . '/helpers/session_utils.php';
require_once __DIR__ . '/helpers/deal_audit.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: view_deals.php');
  exit;
}

if (empty($_POST['csrf_token']) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
  die('Invalid CSRF token.');
}

$deal_id = (int)($_POST['deal_id'] ?? 0);
if ($deal_id <= 0) {
  die('Invalid deal.');
}

$roles = load_session_roles();
if (!in_array('Admin', $roles, true)) {
  die('Access denied.');
}

$rawOverride = trim((string)($_POST['flt_override_amount'] ?? ''));
$overrideValue = null;
if ($rawOverride !== '') {
  if (!is_numeric($rawOverride)) {
    die('Invalid FLT override.');
  }
  $overrideValue = round((float)$rawOverride, 2);
  if ($overrideValue < 0) {
    die('Invalid FLT override.');
  }
}

$beforeDeal = fetch_deal_row_for_audit($db, $deal_id);
$update = $db->prepare("UPDATE deals SET flt_override_amount = ? WHERE id = ?");
$update->execute([$overrideValue, $deal_id]);
$afterDeal = fetch_deal_row_for_audit($db, $deal_id);
log_deal_change_audit($db, $deal_id, 'update', $beforeDeal, $afterDeal, ['context' => 'update_flt_override']);

header('Location: view_deal.php?id=' . $deal_id);
exit;
?>
