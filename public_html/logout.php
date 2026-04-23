<?php
require_once __DIR__ . '/includes/session_bootstrap.php';

// Clear session data server-side.
$_SESSION = [];

// Expire the session cookie on the client side.
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
header('Location: login.php');
exit;
