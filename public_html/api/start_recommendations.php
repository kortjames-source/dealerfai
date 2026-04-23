<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
require_once __DIR__ . '/../includes/csrf.php';
include_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../scoring_engine.php';
require_once __DIR__ . '/../helpers/ai_worker_kick.php';
require_once __DIR__ . '/../helpers/deal_access.php';

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

$rawBody = file_get_contents('php://input');
if ($rawBody) {
    $jsonData = json_decode($rawBody, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($jsonData)) {
        $_POST = array_merge($_POST, $jsonData);
    }
}

$dealId = (int)($_POST['deal_id'] ?? $_GET['deal_id'] ?? 0);
$forceRefresh = !empty($_POST['force']);
if ($dealId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing deal_id.']);
    exit;
}
$csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
$csrfBody = $_POST['csrf_token'] ?? null;
if (!dealerfai_csrf_validate(is_string($csrfHeader) && $csrfHeader !== '' ? $csrfHeader : (is_string($csrfBody) ? $csrfBody : null))) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid request.']);
    exit;
}

$dealStmt = $db->prepare("SELECT id, organization, salesperson_id FROM deals WHERE id = ?");
$dealStmt->execute([$dealId]);
$deal = $dealStmt->fetch(PDO::FETCH_ASSOC);
if (!$deal) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Deal not found.']);
    exit;
}
if (!dealerfai_session_can_access_deal($deal)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied.']);
    exit;
}

try {
    if ($forceRefresh || recommendations_need_refresh($db, $dealId)) {
        $db->prepare("DELETE FROM product_recommendations WHERE deal_id = ?")->execute([$dealId]);
        score_and_store_recommendations($db, $dealId);
    }
    dealerfai_kick_ai_worker($db, 20);
    echo json_encode(['success' => true, 'ready' => true]);
} catch (Throwable $e) {
    error_log("start_recommendations error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to build recommendations.']);
}
