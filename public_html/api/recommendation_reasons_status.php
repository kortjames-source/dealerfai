<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
require_once __DIR__ . '/../helpers/deal_access.php';
require_once __DIR__ . '/../helpers/scoring_ai_queue.php';

header('Content-Type: application/json');

include __DIR__ . '/../db.php';

$dealId = filter_input(INPUT_GET, 'deal_id', FILTER_VALIDATE_INT);
if (!$dealId) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing deal id']);
    exit;
}

try {
    $dealStmt = $db->prepare("SELECT id, organization, salesperson_id FROM deals WHERE id = ?");
    $dealStmt->execute([$dealId]);
    $deal = $dealStmt->fetch(PDO::FETCH_ASSOC);
    if (!$deal) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'deal not found']);
        exit;
    }
    if (!dealerfai_session_can_access_deal($deal)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'access denied']);
        exit;
    }

    $reasonsStmt = $db->prepare("
        SELECT product_code, ai_explanation
        FROM product_recommendations
        WHERE deal_id = ?
    ");
    $reasonsStmt->execute([$dealId]);

    $reasons = [];
    $missingCount = 0;
    foreach ($reasonsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $code = trim((string)($row['product_code'] ?? ''));
        if ($code === '') {
            continue;
        }
        $reason = trim((string)($row['ai_explanation'] ?? ''));
        if ($reason === '') {
            $missingCount++;
            continue;
        }
        $reasons[$code] = $reason;
    }

    $pendingJobs = 0;
    if (function_exists('scoring_ai_async_enabled') && scoring_ai_async_enabled($db)) {
        $jobsStmt = $db->prepare("
            SELECT COUNT(*)
            FROM recommendation_ai_jobs
            WHERE deal_id = ?
              AND status IN ('pending', 'processing')
        ");
        $jobsStmt->execute([$dealId]);
        $pendingJobs = (int)$jobsStmt->fetchColumn();
    }

    echo json_encode([
        'ok' => true,
        'ready' => $pendingJobs === 0 && $missingCount === 0,
        'pending_jobs' => $pendingJobs,
        'missing_reasons' => $missingCount,
        'reasons' => $reasons,
    ]);
} catch (Throwable $e) {
    error_log('recommendation_reasons_status error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server error']);
}
