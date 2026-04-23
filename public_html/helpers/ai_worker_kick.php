<?php
declare(strict_types=1);

require_once __DIR__ . '/scoring_ai_queue.php';

function dealerfai_kick_ai_worker(PDO $db, int $limit = 20): bool
{
    static $alreadyTriggered = false;
    if ($alreadyTriggered) {
        return false;
    }
    $alreadyTriggered = true;

    if (!function_exists('scoring_ai_async_enabled') || !scoring_ai_async_enabled($db)) {
        return false;
    }

    try {
        $pendingReady = (int)$db->query("
            SELECT COUNT(*)
            FROM recommendation_ai_jobs
            WHERE status = 'pending'
              AND available_at <= NOW()
        ")->fetchColumn();
        if ($pendingReady <= 0) {
            return false;
        }
    } catch (Throwable $e) {
        return false;
    }

    $throttleFile = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'dealerfai_ai_worker.trigger';
    $now = time();
    $cooldownSeconds = 8;
    $lastTriggered = @filemtime($throttleFile);
    if (is_int($lastTriggered) && $lastTriggered > 0 && ($now - $lastTriggered) < $cooldownSeconds) {
        return false;
    }
    @touch($throttleFile);

    $scriptPath = realpath(__DIR__ . '/../scripts/process_recommendation_ai_jobs.php');
    if ($scriptPath === false || $scriptPath === '') {
        return false;
    }

    $safeLimit = max(1, min(100, $limit));
    $phpBinary = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : 'php';
    $command = escapeshellarg($phpBinary)
        . ' '
        . escapeshellarg($scriptPath)
        . ' '
        . (int)$safeLimit
        . ' > /dev/null 2>&1 &';

    if (function_exists('exec')) {
        @exec($command);
        return true;
    }
    if (function_exists('shell_exec')) {
        @shell_exec($command);
        return true;
    }
    if (function_exists('system')) {
        @system($command);
        return true;
    }

    return false;
}

