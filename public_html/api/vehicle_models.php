<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/session_bootstrap.php';
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
include_once __DIR__ . '/../db.php';

$makeId = isset($_GET['make_id']) ? (int)$_GET['make_id'] : 0;
$modelYear = isset($_GET['model_year']) ? (int)$_GET['model_year'] : 0;
if ($makeId <= 0) {
    echo json_encode(['models' => []]);
    exit;
}

if ($modelYear > 0) {
    $stmt = $db->prepare("SELECT id, model_name FROM vehicle_models WHERE make_id = ? AND model_year = ? AND active = 1 ORDER BY sort_order ASC, model_name ASC");
    $stmt->execute([$makeId, $modelYear]);
} else {
    $stmt = $db->prepare("SELECT id, model_name FROM vehicle_models WHERE make_id = ? AND active = 1 ORDER BY sort_order ASC, model_name ASC");
    $stmt->execute([$makeId]);
}
$models = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $models[] = ['id' => (int)$row['id'], 'name' => $row['model_name']];
}

echo json_encode(['models' => $models]);
