<?php
declare(strict_types=1);

/**
 * Security event logger.
 *
 * Logs authentication and sensitive admin actions to the `security_log` table.
 * All functions are guarded with function_exists() so this file can be safely
 * included multiple times.
 *
 * Event types used throughout the app:
 *   login_success        – User passed password check and (if required) MFA.
 *   login_failure        – Wrong password submitted.
 *   login_rate_limited   – Login blocked by rate limiter.
 *   mfa_success          – Correct TOTP code entered.
 *   mfa_failure          – Wrong TOTP code entered.
 *   mfa_reset            – Admin reset another user's MFA enrollment.
 *   mfa_required         – Admin forced MFA on a user.
 *   mfa_disabled         – Admin disabled MFA for a user.
 *   password_reset_sent  – Temporary password email was dispatched.
 *   password_changed     – User changed their own password (forced or voluntary).
 *   session_timeout      – Idle session was expired server-side.
 *   logout               – User explicitly logged out.
 */

if (!function_exists('dealerfai_security_log_table_exists')) {
    function dealerfai_security_log_table_exists(PDO $db): bool
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        try {
            $stmt = $db->prepare("
                SELECT COUNT(*)
                FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'security_log'
            ");
            $stmt->execute();
            $cache = ((int)$stmt->fetchColumn() > 0);
        } catch (Throwable $e) {
            $cache = false;
        }
        return $cache;
    }
}

if (!function_exists('dealerfai_security_log')) {
    /**
     * Write a security event to the log.
     *
     * @param PDO    $db         Active database connection.
     * @param string $eventType  One of the event-type constants above.
     * @param array  $context    Optional extra data:
     *                           - user_id       (int)    Resolved user ID.
     *                           - email         (string) Email address involved.
     *                           - actor_user_id (int)    Admin performing the action.
     *                           - details       (array)  Free-form key/value pairs for extra context.
     */
    function dealerfai_security_log(PDO $db, string $eventType, array $context = []): void
    {
        if (!dealerfai_security_log_table_exists($db)) {
            return;
        }

        try {
            $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
            if ($ip === '' || strlen($ip) > 64) {
                $ip = 'unknown';
            }

            $userAgent = isset($_SERVER['HTTP_USER_AGENT'])
                ? mb_substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 512)
                : null;

            $userId       = isset($context['user_id'])       ? (int)$context['user_id']       : null;
            $email        = isset($context['email'])         ? mb_substr((string)$context['email'], 0, 255) : null;
            $actorUserId  = isset($context['actor_user_id']) ? (int)$context['actor_user_id']  : null;
            $details      = isset($context['details']) && is_array($context['details'])
                            ? json_encode($context['details'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                            : null;

            $stmt = $db->prepare("
                INSERT INTO security_log
                    (event_type, user_id, email, ip_address, user_agent, actor_user_id, details, created_at)
                VALUES
                    (?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $eventType,
                $userId,
                $email,
                $ip,
                $userAgent,
                $actorUserId,
                $details,
            ]);
        } catch (Throwable $e) {
            // Never let logging failures break the main request.
            error_log('security_log write failed: ' . $e->getMessage());
        }
    }
}
