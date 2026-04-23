<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/session_bootstrap.php';
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
include_once __DIR__ . '/../db.php';

$modelId = isset($_GET['model_id']) ? (int)$_GET['model_id'] : 0;
if ($modelId <= 0) {
    echo json_encode(['trims' => []]);
    exit;
}

$stmt = $db->prepare("SELECT id, trim_name FROM vehicle_trims WHERE model_id = ? AND active = 1 ORDER BY sort_order ASC, trim_name ASC");
$stmt->execute([$modelId]);
$trims = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $trims[] = ['id' => (int)$row['id'], 'name' => $row['trim_name']];
}

echo json_encode(['trims' => $trims]);
