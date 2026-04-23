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
$product_code = trim((string)($_POST['product_code'] ?? ''));

if ($deal_id <= 0 || $product_code === '') {
  die('Invalid request.');
}

$dealStmt = $db->prepare("SELECT id, organization FROM deals WHERE id = ?");
$dealStmt->execute([$deal_id]);
$deal = $dealStmt->fetch(PDO::FETCH_ASSOC);
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

$appStmt = $db->prepare("SELECT selected_protections, all_recommendations FROM applications WHERE deal_id = ?");
$appStmt->execute([$deal_id]);
$app = $appStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$selected = $app['selected_protections'] ? json_decode($app['selected_protections'], true) : [];
if (!is_array($selected)) {
  $selected = [];
}

$previousSelected = $selected;

if (!in_array($product_code, $selected, true)) {
  $selected[] = $product_code;
}

if (!empty($app)) {
  $update = $db->prepare("UPDATE applications SET selected_protections = ? WHERE deal_id = ?");
  $update->execute([json_encode($selected), $deal_id]);
} else {
  $insert = $db->prepare("INSERT INTO applications (deal_id, selected_protections, all_recommendations, submitted_at) VALUES (?, ?, ?, NOW())");
  $insert->execute([$deal_id, json_encode($selected), '[]']);
}

$allRecommendations = $app['all_recommendations'] ?? '[]';

$added = array_values(array_diff($selected, $previousSelected));
$removed = array_values(array_diff($previousSelected, $selected));
$changeMeta = json_encode([
  'selected_before' => $previousSelected,
  'selected_after' => $selected,
  'added' => $added,
  'removed' => $removed
]);
$audit = $db->prepare("INSERT INTO protection_audit_log (deal_id, selected_protections, all_recommendations, user_id, change_type, change_note, change_meta, submitted_at)
                       VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
$audit->execute([
  $deal_id,
  json_encode($selected),
  $allRecommendations,
  $_SESSION['user_id'] ?? null,
  'manager_add',
  $product_code,
  $changeMeta
]);

header('Location: view_deal.php?id=' . urlencode((string)$deal_id));
exit;
?>
