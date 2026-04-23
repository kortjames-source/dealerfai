<?php
declare(strict_types=1);

function dealerfai_rate_limit_client_ip(): string
{
    $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($ip === '' || strlen($ip) > 64) {
        return 'unknown';
    }
    return $ip;
}

function dealerfai_rate_limit_path_for_bucket(string $bucket): string
{
    $safeBucket = hash('sha256', $bucket);
    $dir = __DIR__ . '/../../secure/logs/rate_limits';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    return $dir . '/' . $safeBucket . '.json';
}

function dealerfai_rate_limit_check_and_increment(string $bucket, int $limit, int $windowSeconds): array
{
    if ($limit <= 0 || $windowSeconds <= 0) {
        return ['allowed' => true, 'retry_after' => 0, 'remaining' => 0];
    }

    $path = dealerfai_rate_limit_path_for_bucket($bucket);
    $now = time();
    $state = ['count' => 0, 'window_start' => $now];

    $fp = @fopen($path, 'c+');
    if ($fp === false) {
        return ['allowed' => true, 'retry_after' => 0, 'remaining' => max(0, $limit - 1)];
    }

    try {
        if (!flock($fp, LOCK_EX)) {
            return ['allowed' => true, 'retry_after' => 0, 'remaining' => max(0, $limit - 1)];
        }

        $raw = stream_get_contents($fp);
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $state['count'] = (int)($decoded['count'] ?? 0);
                $state['window_start'] = (int)($decoded['window_start'] ?? $now);
            }
        }

        if (($now - $state['window_start']) >= $windowSeconds) {
            $state = ['count' => 0, 'window_start' => $now];
        }

        if ($state['count'] >= $limit) {
            $retryAfter = max(1, $windowSeconds - ($now - $state['window_start']));
            return ['allowed' => false, 'retry_after' => $retryAfter, 'remaining' => 0];
        }

        $state['count']++;

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($state));
        fflush($fp);

        return [
            'allowed' => true,
            'retry_after' => 0,
            'remaining' => max(0, $limit - $state['count']),
        ];
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

function dealerfai_rate_limit_reset(string $bucket): void
{
    $path = dealerfai_rate_limit_path_for_bucket($bucket);
    if (file_exists($path)) {
        @unlink($path);
    }
}
