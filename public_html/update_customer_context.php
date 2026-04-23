<?php
require_once __DIR__ . '/includes/session_bootstrap.php';

include 'auth.php';
include 'db.php';
require_once __DIR__ . '/helpers/session_utils.php';
require_once __DIR__ . '/helpers/db_utils.php';
require_once __DIR__ . '/helpers/deal_audit.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method Not Allowed';
    exit;
}

if (empty($_POST['csrf_token']) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token'])) {
    http_response_code(403);
    echo 'Invalid CSRF token';
    exit;
}

$dealId = isset($_POST['deal_id']) ? (int)$_POST['deal_id'] : 0;
if ($dealId <= 0) {
    http_response_code(400);
    echo 'Missing deal ID';
    exit;
}

if (!column_exists($db, 'deals', 'customer_context')) {
    http_response_code(500);
    echo 'Customer notes are not enabled (missing deals.customer_context). Database schema update required.';
    exit;
}

$stmt = $db->prepare("SELECT * FROM deals WHERE id = ?");
$stmt->execute([$dealId]);
$deal = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$deal) {
    http_response_code(404);
    echo 'Deal not found';
    exit;
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$roles = load_session_roles();
$isAdmin = in_array('Admin', $roles, true);
$isManager = in_array('General Manager', $roles, true) || in_array('Finance Manager', $roles, true) || in_array('Sales Manager', $roles, true);

$effectiveOrg = get_effective_organization();
$accessibleOrgs = get_accessible_organizations();
$allowedOrg = false;
if ($isAdmin) {
    $allowedOrg = true;
} else {
    if ($effectiveOrg && (string)($deal['organization'] ?? '') === (string)$effectiveOrg) {
        $allowedOrg = true;
    } else {
        $dealOrgId = (int)($deal['organization'] ?? 0);
        foreach ($accessibleOrgs as $orgId) {
            if ((int)$orgId === $dealOrgId) {
                $allowedOrg = true;
                break;
            }
        }
    }
}

if (!$allowedOrg || (!$isAdmin && !$isManager && (int)($deal['salesperson_id'] ?? 0) !== $userId)) {
    http_response_code(403);
    echo 'You do not have access to this deal.';
    exit;
}

$trimMax = function (string $value, int $maxLen): string {
    $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');
    if ($value === '') {
        return '';
    }
    if (strlen($value) > $maxLen) {
        return substr($value, 0, $maxLen);
    }
    return $value;
};

$ctx = [
    'household' => $trimMax((string)($_POST['ctx_household'] ?? ''), 500),
    'commute' => $trimMax((string)($_POST['ctx_commute'] ?? ''), 500),
    'usage' => $trimMax((string)($_POST['ctx_usage'] ?? ''), 500),
    'pain_points' => $trimMax((string)($_POST['ctx_pain_points'] ?? ''), 500),
    'must_haves' => $trimMax((string)($_POST['ctx_must_haves'] ?? ''), 500),
    'objections' => $trimMax((string)($_POST['ctx_objections'] ?? ''), 500),
    'budget' => $trimMax((string)($_POST['ctx_budget'] ?? ''), 250),
    'freeform' => trim((string)($_POST['ctx_freeform'] ?? '')),
];

// Keep freeform spacing intact (but cap size).
$ctx['freeform'] = trim((string)$ctx['freeform']);
if ($ctx['freeform'] !== '' && strlen($ctx['freeform']) > 2500) {
    $ctx['freeform'] = substr($ctx['freeform'], 0, 2500);
}

// Drop empty keys to keep storage clean.
$ctx = array_filter($ctx, fn($v) => is_string($v) && trim($v) !== '');
$payload = json_encode($ctx, JSON_UNESCAPED_SLASHES);
if ($payload === false) {
    http_response_code(500);
    echo 'Unable to save notes.';
    exit;
}

$beforeDeal = fetch_deal_row_for_audit($db, $dealId);
$update = $db->prepare("UPDATE deals SET customer_context = ? WHERE id = ?");
$update->execute([$payload, $dealId]);
$afterDeal = fetch_deal_row_for_audit($db, $dealId);
log_deal_change_audit($db, $dealId, 'update', $beforeDeal, $afterDeal, ['context' => 'update_customer_context']);

header('Location: view_deal.php?id=' . urlencode((string)$dealId));
exit;
