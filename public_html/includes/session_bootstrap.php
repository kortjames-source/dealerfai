<?php
// Centralized session hardening. Include this instead of calling session_start() directly.
// Safe to require multiple times; it only starts the session if needed.

if (session_status() === PHP_SESSION_ACTIVE) {
    return;
}

// Prefer cookies only and reject uninitialized session IDs.
ini_set('session.use_only_cookies', '1');
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');

// Detect HTTPS (direct or behind a TLS-terminating proxy).
$https = false;
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    $https = true;
} elseif (!empty($_SERVER['SERVER_PORT']) && (string)$_SERVER['SERVER_PORT'] === '443') {
    $https = true;
} elseif (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
    $https = true;
}
ini_set('session.cookie_secure', $https ? '1' : '0');

// SameSite mitigates CSRF in modern browsers. Lax is a safe default for most apps.
$cookieParams = session_get_cookie_params();
session_set_cookie_params([
    'lifetime' => (int)($cookieParams['lifetime'] ?? 0),
    'path' => (string)($cookieParams['path'] ?? '/'),
    'domain' => (string)($cookieParams['domain'] ?? ''),
    'secure' => $https,
    'httponly' => true,
    'samesite' => 'Lax',
]);

session_start();

// Enforce idle session timeout (60 minutes).
// Only applies to authenticated sessions to avoid affecting public pages.
if (isset($_SESSION['user_id'])) {
    $idleLimit = 3600; // 60 minutes
    if (isset($_SESSION['last_activity']) && (time() - (int)$_SESSION['last_activity']) > $idleLimit) {
        // Session has expired — destroy it and redirect to login.
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }
        session_destroy();
        header('Location: login.php?reason=timeout');
        exit;
    }
    $_SESSION['last_activity'] = time();
}

