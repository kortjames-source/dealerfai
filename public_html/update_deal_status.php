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
$status = trim((string)($_POST['status'] ?? ''));
$delivered_date = trim((string)($_POST['delivered_date'] ?? ''));

if ($deal_id <= 0) {
  die('Invalid deal.');
}

if (!in_array($status, ['booked', 'cancelled'], true)) {
  die('Invalid status.');
}

$stmt = $db->prepare("SELECT id, organization, salesperson_id FROM deals WHERE id = ?");
$stmt->execute([$deal_id]);
$deal = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$deal) {
  die('Deal not found.');
}

$roles = load_session_roles();
$isAdmin = in_array('Admin', $roles, true);
$isManager = in_array('General Manager', $roles, true)
  || in_array('Finance Manager', $roles, true)
  || in_array('Sales Manager', $roles, true);

$allowedOrg = false;
if ($isAdmin) {
  $allowedOrg = true;
} else {
  $orgContext = get_effective_organization();
  if ($orgContext && (string)$deal['organization'] === (string)$orgContext) {
    $allowedOrg = true;
  }
  if (!$allowedOrg) {
    $accessibleOrgs = get_accessible_organizations();
    $orgId = (int)$deal['organization'];
    foreach ($accessibleOrgs as $org) {
      if ((int)$org === $orgId) {
        $allowedOrg = true;
        break;
      }
    }
  }
}

if (!$allowedOrg) {
  die('Access denied.');
}

if (!$isAdmin && !$isManager) {
  $userId = (int)($_SESSION['user_id'] ?? 0);
  if ($userId <= 0 || (int)$deal['salesperson_id'] !== $userId) {
    die('Access denied.');
  }
}

if ($status === 'booked') {
  $date = DateTime::createFromFormat('Y-m-d', $delivered_date);
  $validDate = $date && $date->format('Y-m-d') === $delivered_date;
  if (!$validDate) {
    die('Invalid delivery date.');
  }
  $beforeDeal = fetch_deal_row_for_audit($db, $deal_id);
  $update = $db->prepare("UPDATE deals SET deal_status = 'booked', delivered_date = ?, cancelled_date = NULL WHERE id = ?");
  $update->execute([$delivered_date, $deal_id]);
  $afterDeal = fetch_deal_row_for_audit($db, $deal_id);
  log_deal_change_audit($db, $deal_id, 'update', $beforeDeal, $afterDeal, ['context' => 'update_deal_status', 'status' => 'booked']);
} else {
  $beforeDeal = fetch_deal_row_for_audit($db, $deal_id);
  $update = $db->prepare("UPDATE deals SET deal_status = 'cancelled', cancelled_date = CURDATE(), delivered_date = NULL WHERE id = ?");
  $update->execute([$deal_id]);
  $afterDeal = fetch_deal_row_for_audit($db, $deal_id);
  log_deal_change_audit($db, $deal_id, 'update', $beforeDeal, $afterDeal, ['context' => 'update_deal_status', 'status' => 'cancelled']);
}

header('Location: view_deals.php');
exit;
?>
