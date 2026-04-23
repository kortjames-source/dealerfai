<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/csrf.php';
include 'db.php';
require_once __DIR__ . '/helpers/customer_types.php';

$deal = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!dealerfai_csrf_validate($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        die('Invalid request.');
    }
    $deal_id = (int)($_POST['deal_id'] ?? 0);
    if ($deal_id <= 0) {
        die('Invalid or missing data.');
    }
    $stmt = $db->prepare("SELECT * FROM deals WHERE id = ?");
    $stmt->execute([$deal_id]);
    $deal = $stmt->fetch(PDO::FETCH_ASSOC);
} else {
    // Backward-compatible path for external/public links.
    $token = trim((string)($_GET['token'] ?? ''));
    if ($token === '') {
        http_response_code(405);
        die('Method not allowed.');
    }
    $stmt = $db->prepare("SELECT * FROM deals WHERE secure_token = ? LIMIT 1");
    $stmt->execute([$token]);
    $deal = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$deal) {
    die('Deal not found.');
}

if (!empty($deal['credit_app_locked'])) {
    die('This credit application is locked. Please contact a manager to unlock it.');
}

if (($deal['deal_type'] ?? '') === 'Cash') {
    $token = $deal['secure_token'] ?? '';
    if ($token === '') {
        die('Missing secure token for cash application.');
    }
    header("Location: cash_application_step1.php?token=" . urlencode($token));
    exit;
}

$_SESSION['deal_id']        = $deal['id'];
$_SESSION['vehicle_make']   = $deal['vehicle_make'];
$_SESSION['vehicle_model']  = $deal['vehicle_model'];

// Redirect to the application form (step 1)
$customerType = normalize_customer_type($deal['customer_type'] ?? 'personal');
if ($customerType !== 'personal') {
    header("Location: business_application_step1.php");
    exit;
}
header("Location: application_step1.php");
exit;
?>
