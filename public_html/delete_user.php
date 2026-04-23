<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/csrf.php';
include 'auth.php';
include 'db.php';
require_once __DIR__ . '/helpers/session_utils.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  die('Method not allowed.');
}
if (!dealerfai_csrf_validate($_POST['csrf_token'] ?? null)) {
  http_response_code(403);
  die('Invalid request.');
}
$roles = load_session_roles();
if (!in_array('Admin', $roles, true)) {
  header("Location: dashboard.php");
  exit;
}

$id = (int)($_POST['id'] ?? 0);
$currentUserId = (int)($_SESSION['user_id'] ?? 0);
if ($id <= 0 || $id === $currentUserId) {
  header("Location: manage_users.php");
  exit;
}
$stmt = $db->prepare("DELETE FROM users WHERE id = ?");
$stmt->execute([$id]);

header("Location: manage_users.php");
exit;
?>
