<?php
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script must be run from CLI.\n");
    exit(2);
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../scoring_engine.php';
require_once __DIR__ . '/../helpers/scoring_ai_queue.php';

if (!scoring_ai_async_enabled($db)) {
    fwrite(STDOUT, "AI async queue not enabled.\n");
    exit(0);
}

$limit = isset($argv[1]) ? max(1, min(100, (int)$argv[1])) : 20;

$recoverStale = $db->prepare("
    UPDATE recommendation_ai_jobs
    SET status = 'pending',
        locked_at = NULL,
        available_at = NOW(),
        error_message = 'Recovered stale processing job',
        updated_at = NOW()
    WHERE status = 'processing'
      AND locked_at IS NOT NULL
      AND locked_at < DATE_SUB(NOW(), INTERVAL 2 MINUTE)
");
$recoverStale->execute();

$select = $db->prepare("
    SELECT id, deal_id, product_code, product_payload, voice_prompt_payload, tags_payload, attempts
    FROM recommendation_ai_jobs
    WHERE status = 'pending'
      AND available_at <= NOW()
    ORDER BY id ASC
    LIMIT {$limit}
");
$select->execute();
$jobs = $select->fetchAll(PDO::FETCH_ASSOC) ?: [];

if (empty($jobs)) {
    fwrite(STDOUT, "No pending AI jobs.\n");
    exit(0);
}

$claim = $db->prepare("
    UPDATE recommendation_ai_jobs
    SET status = 'processing',
        locked_at = NOW(),
        attempts = attempts + 1,
        updated_at = NOW()
    WHERE id = ?
      AND status = 'pending'
");

$markDone = $db->prepare("
    UPDATE recommendation_ai_jobs
    SET status = 'done',
        locked_at = NULL,
        error_message = NULL,
        updated_at = NOW()
    WHERE id = ?
");

$markFailed = $db->prepare("
    UPDATE recommendation_ai_jobs
    SET status = 'failed',
        locked_at = NULL,
        error_message = ?,
        updated_at = NOW()
    WHERE id = ?
");

$retry = $db->prepare("
    UPDATE recommendation_ai_jobs
    SET status = 'pending',
        locked_at = NULL,
        available_at = DATE_ADD(NOW(), INTERVAL ? SECOND),
        error_message = ?,
        updated_at = NOW()
    WHERE id = ?
");

$updateRecommendation = $db->prepare("
    UPDATE product_recommendations
    SET ai_explanation = ?
    WHERE deal_id = ?
      AND product_code = ?
");

$processed = 0;
$failed = 0;

foreach ($jobs as $job) {
    $jobId = (int)$job['id'];
    $claimed = $claim->execute([$jobId]);
    if (!$claimed || $claim->rowCount() <= 0) {
        continue;
    }

    try {
        $product = json_decode((string)$job['product_payload'], true, 512, JSON_THROW_ON_ERROR);
        $voicePrompts = json_decode((string)$job['voice_prompt_payload'], true, 512, JSON_THROW_ON_ERROR);
        $ai = generate_ai_explanation(
            is_array($product) ? $product : [],
            is_array($voicePrompts) ? $voicePrompts : [],
            $db
        );
        $ai = trim((string)$ai);
        if ($ai !== '') {
            $updateRecommendation->execute([
                $ai,
                (int)$job['deal_id'],
                (string)$job['product_code'],
            ]);
        }
        $markDone->execute([$jobId]);
        $processed++;
    } catch (Throwable $e) {
        $message = substr($e->getMessage(), 0, 500);
        $attempts = (int)($job['attempts'] ?? 0) + 1;
        if ($attempts >= 3) {
            $markFailed->execute([$message, $jobId]);
            $failed++;
        } else {
            $backoffSeconds = 30 * $attempts;
            $retry->execute([$backoffSeconds, $message, $jobId]);
        }
    }
}

fwrite(STDOUT, "Processed: {$processed}, Failed: {$failed}\n");
