<?php

include 'auth.php';
include 'db.php';
require_once __DIR__ . '/helpers/session_utils.php';
require_once __DIR__ . '/helpers/deal_audit.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['deal_id'])) {
  $deal_id = (int)$_POST['deal_id'];

  if (empty($_POST['csrf_token']) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    die('Invalid CSRF token.');
  }

  // Get deal info for permission check
  $stmt = $db->prepare("SELECT organization FROM deals WHERE id = ?");
  $stmt->execute([$deal_id]);
  $deal = $stmt->fetch(PDO::FETCH_ASSOC);
  $beforeDeal = fetch_deal_row_for_audit($db, $deal_id);

  if (!$deal) {
    die("Deal not found.");
  }

  $roles = load_session_roles();
  $isAdmin = in_array('Admin', $roles, true);

  if (!$isAdmin) {
    die("Access denied.");
  }

  $tables = [
    'applications',
    'application_usage_data',
    'credit_applications',
    'deal_email_logs',
    'deal_accessories',
    'accessory_recommendations',
    'product_recommendations',
    'product_selections',
    'protection_audit_log',
    'recommendation_event_log',
    'recommendation_scoring_log',
    'selected_products'
  ];

  foreach ($tables as $table) {
    try {
      $stmt = $db->prepare("DELETE FROM {$table} WHERE deal_id = ?");
      $stmt->execute([$deal_id]);
    } catch (PDOException $e) {
      error_log("Failed to delete deal data from {$table}: " . $e->getMessage());
    }
  }

  $del = $db->prepare("DELETE FROM deals WHERE id = ?");
  $del->execute([$deal_id]);
  log_deal_change_audit($db, $deal_id, 'delete', $beforeDeal, null, ['context' => 'delete_deal']);

  header("Location: view_deals.php?deleted=1");
  exit;
}
?>
