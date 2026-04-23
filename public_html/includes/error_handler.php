<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

function dealerfai_trim_error_message(string $message, int $limit = 5000): string
{
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($message) > $limit) {
            return mb_substr($message, 0, $limit);
        }
        return $message;
    }

    if (strlen($message) > $limit) {
        return substr($message, 0, $limit);
    }

    return $message;
}

function dealerfai_log_admin_alert(PDO $db, string $level, string $message, ?string $file, ?int $line): void
{
    static $logging = false;
    if ($logging) {
        return;
    }

    $logging = true;
    try {
        $message = dealerfai_trim_error_message($message, 5000);

        $url = $_SERVER['REQUEST_URI'] ?? null;
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;

        $sessionActive = session_status() === PHP_SESSION_ACTIVE;
        $userId = $sessionActive ? ($_SESSION['user_id'] ?? null) : null;
        $orgId = $sessionActive ? ($_SESSION['organization'] ?? null) : null;
        if (is_string($orgId)) {
            $orgId = ctype_digit($orgId) ? (int)$orgId : null;
        }

        $hash = sha1($level . '|' . $message . '|' . ($file ?? '') . '|' . ($line ?? 0));

        $stmt = $db->prepare(
            "INSERT INTO admin_error_alerts
" .
            "  (error_hash, level, message, file, line, url, user_id, org_id, occurrences, first_seen, last_seen, last_ip)
" .
            "VALUES
" .
            "  (:hash, :level, :message, :file, :line, :url, :user_id, :org_id, 1, NOW(), NOW(), :ip)
" .
            "ON DUPLICATE KEY UPDATE
" .
            "  occurrences = occurrences + 1,
" .
            "  last_seen = NOW(),
" .
            "  url = VALUES(url),
" .
            "  user_id = VALUES(user_id),
" .
            "  org_id = VALUES(org_id),
" .
            "  last_ip = VALUES(last_ip),
" .
            "  is_resolved = 0,
" .
            "  resolved_at = NULL"
        );
        $stmt->execute([
            ':hash' => $hash,
            ':level' => $level,
            ':message' => $message,
            ':file' => $file,
            ':line' => $line,
            ':url' => $url,
            ':user_id' => $userId,
            ':org_id' => $orgId,
            ':ip' => $ip,
        ]);
    } catch (Throwable $e) {
        error_log('Admin alert log failed: ' . $e->getMessage());
    } finally {
        $logging = false;
    }
}

function dealerfai_register_error_handlers(PDO $db): void
{
    set_error_handler(function ($severity, $message, $file, $line) use ($db) {
        if (!(error_reporting() & $severity)) {
            return false;
        }

        $map = [
            E_ERROR => 'E_ERROR',
            E_WARNING => 'E_WARNING',
            E_PARSE => 'E_PARSE',
            E_NOTICE => 'E_NOTICE',
            E_CORE_ERROR => 'E_CORE_ERROR',
            E_CORE_WARNING => 'E_CORE_WARNING',
            E_COMPILE_ERROR => 'E_COMPILE_ERROR',
            E_COMPILE_WARNING => 'E_COMPILE_WARNING',
            E_USER_ERROR => 'E_USER_ERROR',
            E_USER_WARNING => 'E_USER_WARNING',
            E_USER_NOTICE => 'E_USER_NOTICE',
            E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
            E_DEPRECATED => 'E_DEPRECATED',
            E_USER_DEPRECATED => 'E_USER_DEPRECATED',
        ];
        if (defined('E_STRICT')) {
            $map[E_STRICT] = 'E_STRICT';
        }
        $level = $map[$severity] ?? 'E_UNKNOWN';

        dealerfai_log_admin_alert($db, $level, (string)$message, $file, (int)$line);

        return false;
    });

    set_exception_handler(function (Throwable $e) use ($db) {
        dealerfai_log_admin_alert(
            $db,
            'EXCEPTION',
            $e->getMessage(),
            $e->getFile(),
            (int)$e->getLine()
        );
    });

    register_shutdown_function(function () use ($db) {
        $err = error_get_last();
        if (!$err) {
            return;
        }

        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
        if (!in_array($err['type'], $fatalTypes, true)) {
            return;
        }

        dealerfai_log_admin_alert(
            $db,
            'FATAL',
            $err['message'],
            $err['file'] ?? null,
            isset($err['line']) ? (int)$err['line'] : null
        );
    });
}
