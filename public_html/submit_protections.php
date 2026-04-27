<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
include 'db.php';
include_once 'protection_helpers.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$deal_id = (int)($_POST['deal_id'] ?? 0);
if ($deal_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid deal ID']);
    exit;
}

// Fetch deal for organization and lock status
$deal_stmt = $db->prepare("SELECT id, organization, credit_app_locked, included_protections FROM deals WHERE id = ?");
$deal_stmt->execute([$deal_id]);
$deal = $deal_stmt->fetch(PDO::FETCH_ASSOC);

if (!$deal) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Deal not found']);
    exit;
}

// Get recommendations and selected items
$selected = $_POST['selected'] ?? [];
$recommendations = $_POST['recommendations'] ?? [];
$xpel_package = $_POST['xpel_package'] ?? null;
$term_options = $_POST['term_option'] ?? [];
$variant_options = $_POST['variant_option'] ?? [];
$aiNarrative = $_POST['ai_narrative'] ?? '';

if (!is_array($selected)) $selected = [];
if (!is_array($recommendations)) $recommendations = [];
if (!is_array($term_options)) $term_options = [];
if (!is_array($variant_options)) $variant_options = [];

// Fetch previous selections for audit
$prev_stmt = $db->prepare("SELECT selected_protections FROM applications WHERE deal_id = ?");
$prev_stmt->execute([$deal_id]);
$prev_json = $prev_stmt->fetchColumn();
$previousSelected = json_decode($prev_json ?: '[]', true) ?: [];

// Prepare usage data update
$usage_stmt = $db->prepare("SELECT usage_data FROM applications WHERE deal_id = ?");
$usage_stmt->execute([$deal_id]);
$usage_json = $usage_stmt->fetchColumn();
$usage = json_decode($usage_json ?: '[]', true) ?: [];

// Add XPEL package if selected
if ($xpel_package) {
    $usage['xpel_package'] = $xpel_package;
}
// Add terms and variants
$usage['term_options'] = $term_options;
$usage['variant_options'] = $variant_options;

$usage_json = json_encode($usage);

// Update application
$app_exists = false;
$check = $db->prepare("SELECT id FROM applications WHERE deal_id = ?");
$check->execute([$deal_id]);
if ($check->fetch()) {
    $app_exists = true;
    $update = $db->prepare("UPDATE applications SET selected_protections = ?, usage_data = ? WHERE deal_id = ?");
    $update->execute([json_encode($selected), $usage_json, $deal_id]);
} else {
    // Should not happen in normal flow as Step 3 creates it, but handle just in case
    $insert = $db->prepare("INSERT INTO applications (deal_id, selected_protections, usage_data, started_at, submitted_at) VALUES (?, ?, ?, NOW(), NOW())");
    $insert->execute([$deal_id, json_encode($selected), $usage_json]);
}

// Store individual product recommendations and explanations
if (!empty($recommendations)) {
    // Clear old recommendations first
    $clear = $db->prepare("DELETE FROM product_recommendations WHERE deal_id = ?");
    $clear->execute([$deal_id]);

    $ins = $db->prepare("INSERT INTO product_recommendations (deal_id, product_code, product_name, score, ai_explanation, created_at) VALUES (:deal_id, :product_code, :product_name, :score, :ai_explanation, NOW())");
    foreach ($recommendations as $rec) {
        $ins->execute([
            'deal_id' => $deal_id,
            'product_code' => $rec['product_code'] ?? '',
            'product_name' => $rec['product_name'] ?? '',
            'score' => (int)($rec['score'] ?? 0),
            'ai_explanation' => $rec['ai_explanation'] ?? ''
        ]);
    }
}

// Insert audit log
$added = array_values(array_diff($selected, $previousSelected));
$removed = array_values(array_diff($previousSelected, $selected));
$changeMeta = json_encode([
    'selected_before' => $previousSelected,
    'selected_after' => $selected,
    'added' => $added,
    'removed' => $removed
]);
$changeType = $app_exists ? 'resubmit' : 'initial_submit';

$auditData = [
    'deal_id' => $deal_id,
    'selected_protections' => json_encode($selected),
    'all_recommendations' => json_encode($recommendations),
    'user_id' => $_SESSION['user_id'] ?? null,
    'change_type' => $changeType,
    'change_meta' => $changeMeta,
    'ai_narrative' => $aiNarrative
];

$audit = $db->prepare("
    INSERT INTO protection_audit_log (
        deal_id, selected_protections, all_recommendations, user_id, change_type, change_meta, ai_narrative, submitted_at
    ) VALUES (
        :deal_id, :selected_protections, :all_recommendations, :user_id, :change_type, :change_meta, :ai_narrative, NOW()
    )
");
$audit->execute($auditData);

// Lock the credit app after the first completed selection.
$lockUserId = $_SESSION['user_id'] ?? null;
$lockStmt = $db->prepare("
    UPDATE deals
    SET credit_app_locked = 1,
        credit_app_locked_at = NOW(),
        credit_app_locked_by = ?
    WHERE id = ?
      AND (credit_app_locked = 0 OR credit_app_locked IS NULL)
");
$lockStmt->execute([$lockUserId, $deal_id]);

echo json_encode(['success' => true]);
