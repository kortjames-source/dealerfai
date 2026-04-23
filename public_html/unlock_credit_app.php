<?php
include 'auth.php';
include 'db.php';
require_once __DIR__ . '/helpers/session_utils.php';

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

$stmt = $db->prepare("SELECT id, organization FROM deals WHERE id = ?");
$stmt->execute([$deal_id]);
$deal = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$deal) {
  die('Deal not found.');
}

$roles = load_session_roles();
$isAdmin = in_array('Admin', $roles, true);
$isManager = in_array('General Manager', $roles, true) || in_array('Finance Manager', $roles, true);

if (!$isAdmin && !$isManager) {
  die('Access denied.');
}

$allowedOrg = false;
if ($isAdmin) {
  $allowedOrg = true;
} else {
  $orgContext = get_effective_organization();
  if ($orgContext && (string)$deal['organization'] === (string)$orgContext) {
    $allowedOrg = true;
  } else {
    $accessibleOrgs = get_accessible_organizations();
    $dealOrgId = (int)$deal['organization'];
    foreach ($accessibleOrgs as $orgId) {
      if ((int)$orgId === $dealOrgId) {
        $allowedOrg = true;
        break;
      }
    }
  }
}

if (!$allowedOrg) {
  die('Access denied.');
}

$unlock = $db->prepare("
  UPDATE deals
  SET credit_app_locked = 0,
      credit_app_locked_at = NULL,
      credit_app_locked_by = NULL
  WHERE id = ?
");
$unlock->execute([$deal_id]);

header('Location: view_deal.php?id=' . urlencode((string)$deal_id));
exit;
?>
