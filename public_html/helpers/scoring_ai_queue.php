<?php
declare(strict_types=1);

require_once __DIR__ . '/db_utils.php';

function scoring_ai_mode(): string
{
    $mode = strtolower(trim((string)($_ENV['SCORING_AI_MODE'] ?? getenv('SCORING_AI_MODE') ?? 'async')));
    if (!in_array($mode, ['async', 'sync', 'off'], true)) {
        return 'async';
    }
    return $mode;
}

function scoring_ai_async_enabled(PDO $db): bool
{
    if (scoring_ai_mode() !== 'async') {
        return false;
    }
    return table_column_exists($db, 'recommendation_ai_jobs', 'status');
}

function enqueue_ai_explanation_job(PDO $db, int $dealId, array $product, array $voicePrompts, array $tags): bool
{
    if (!scoring_ai_async_enabled($db)) {
        return false;
    }

    $productCode = trim((string)($product['code'] ?? ''));
    if ($dealId <= 0 || $productCode === '') {
        return false;
    }

    $insert = $db->prepare("
        INSERT INTO recommendation_ai_jobs
          (deal_id, product_code, product_payload, voice_prompt_payload, tags_payload, status, attempts, available_at, created_at, updated_at)
        VALUES
          (?, ?, ?, ?, ?, 'pending', 0, NOW(), NOW(), NOW())
        ON DUPLICATE KEY UPDATE
          product_payload = VALUES(product_payload),
          voice_prompt_payload = VALUES(voice_prompt_payload),
          tags_payload = VALUES(tags_payload),
          status = 'pending',
          attempts = 0,
          available_at = NOW(),
          locked_at = NULL,
          error_message = NULL,
          updated_at = NOW()
    ");

    return $insert->execute([
        $dealId,
        $productCode,
        json_encode($product, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        json_encode($voicePrompts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        json_encode($tags, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
}
