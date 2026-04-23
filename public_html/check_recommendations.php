<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/helpers/deal_access.php';
header('Content-Type: application/json');

include 'db.php';

$deal_id = filter_input(INPUT_GET, 'deal_id', FILTER_VALIDATE_INT);
if (!$deal_id) {
    echo json_encode(['ready' => false, 'error' => 'missing deal id']);
    exit;
}

try {
    $dealStmt = $db->prepare("SELECT id, organization, salesperson_id FROM deals WHERE id = ?");
    $dealStmt->execute([$deal_id]);
    $deal = $dealStmt->fetch(PDO::FETCH_ASSOC);
    if (!$deal) {
        echo json_encode(['ready' => false, 'error' => 'deal not found']);
        exit;
    }
    if (!dealerfai_session_can_access_deal($deal)) {
        http_response_code(403);
        echo json_encode(['ready' => false, 'error' => 'access denied']);
        exit;
    }

    $stmt = $db->prepare("SELECT COUNT(*) FROM product_recommendations WHERE deal_id = ?");
    $stmt->execute([$deal_id]);
    $count = (int)$stmt->fetchColumn();
    echo json_encode(['ready' => $count > 0]);
} catch (PDOException $e) {
    error_log("check_recommendations error: " . $e->getMessage());
    echo json_encode(['ready' => false]);
}
