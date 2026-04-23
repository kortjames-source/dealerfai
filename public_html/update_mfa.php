<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/security_headers.php';
require_once __DIR__ . '/includes/csrf.php';
include 'auth.php';
include 'db.php';
require_once __DIR__ . '/helpers/mfa_secret_store.php';
require_once __DIR__ . '/helpers/security_logger.php';

if (!in_array('Admin', $_SESSION['roles'] ?? [], true)) {
    die('Access denied.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Method not allowed.');
}

$csrf = $_POST['csrf_token'] ?? null;
if (!dealerfai_csrf_validate(is_string($csrf) ? $csrf : null)) {
    http_response_code(403);
    die('Invalid CSRF token.');
}

$userId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
$action = isset($_POST['action']) ? (string)$_POST['action'] : '';
if ($userId <= 0 || $action === '') {
    die('Missing required parameters.');
}

if ($action === 'require') {
    $stmt = $db->prepare("UPDATE users SET mfa_mandatory = 1 WHERE id = ?");
    $stmt->execute([$userId]);
    dealerfai_security_log($db, 'mfa_required', [
        'user_id'       => $userId,
        'actor_user_id' => (int)($_SESSION['user_id'] ?? 0),
    ]);
} elseif ($action === 'reset') {
    // Force re-enrollment on next login.
    if (dealerfai_users_supports_mfa_v2($db)) {
        $stmt = $db->prepare("
            UPDATE users
            SET
              mfa_mandatory = 1,
              mfa_enabled = 0,
              mfa_secret = NULL,
              mfa_secret_ciphertext = NULL,
              mfa_secret_nonce = NULL,
              mfa_secret_tag = NULL,
              mfa_confirmed_at = NULL
            WHERE id = ?
        ");
        $stmt->execute([$userId]);
    } else {
        $stmt = $db->prepare("UPDATE users SET mfa_mandatory = 1, mfa_enabled = 0, mfa_secret = NULL WHERE id = ?");
        $stmt->execute([$userId]);
    }
    dealerfai_security_log($db, 'mfa_reset', [
        'user_id'       => $userId,
        'actor_user_id' => (int)($_SESSION['user_id'] ?? 0),
    ]);
} elseif ($action === 'disable') {
    if (dealerfai_users_supports_mfa_v2($db)) {
        $stmt = $db->prepare("
            UPDATE users
            SET
              mfa_mandatory = 0,
              mfa_enabled = 0,
              mfa_secret = NULL,
              mfa_secret_ciphertext = NULL,
              mfa_secret_nonce = NULL,
              mfa_secret_tag = NULL,
              mfa_confirmed_at = NULL
            WHERE id = ?
        ");
        $stmt->execute([$userId]);
    } else {
        $stmt = $db->prepare("UPDATE users SET mfa_mandatory = 0, mfa_enabled = 0, mfa_secret = NULL WHERE id = ?");
        $stmt->execute([$userId]);
    }
    dealerfai_security_log($db, 'mfa_disabled', [
        'user_id'       => $userId,
        'actor_user_id' => (int)($_SESSION['user_id'] ?? 0),
    ]);
} else {
    die('Invalid action.');
}

header('Location: manage_users.php');
exit;
