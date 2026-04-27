<?php
include 'auth.php';
include 'db.php';
require_once __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;

$localConfigPath = __DIR__ . '/../secure/local_config.php';
$localConfig = [];
if (file_exists($localConfigPath)) {
    $loaded = require $localConfigPath;
    if (is_array($loaded)) {
        $localConfig = $loaded;
    }
}
$smtpConfig = $localConfig['smtp'] ?? [];

$deal_id = $_POST['deal_id'] ?? null;
if (!$deal_id) {
    die('Missing deal ID');
}

// Stop updates once the credit app is locked, EXCEPT if this is the first time submitting protections.
$lockStmt = $db->prepare("SELECT credit_app_locked FROM deals WHERE id = ?");
$lockStmt->execute([$deal_id]);
if ($lockStmt->fetchColumn()) {
    // Check if protections have already been submitted (audit log entry exists)
    $auditCheck = $db->prepare("SELECT COUNT(*) FROM protection_audit_log WHERE deal_id = ?");
    $auditCheck->execute([$deal_id]);
    if ($auditCheck->fetchColumn() > 0) {
        die('This credit application is locked.');
    }
}

// Collect submitted selections
$declineAll = isset($_POST['decline_all']) && $_POST['decline_all'] === '1';
$selected = $_POST['selected_protections'] ?? $_POST['selected'] ?? [];
$recommendations = $_POST['recommendations'] ?? [];
$xpel_package = $_POST['xpel_package'] ?? null;
$term_options = $_POST['term_option'] ?? [];
$variant_options = $_POST['variant_option'] ?? [];
$aiNarrative = $_POST['ai_narrative'] ?? '';

if (!is_array($selected)) $selected = [];
if (!is_array($recommendations)) $recommendations = [];

if ($xpel_package) {
    $xpel_package = strtolower(trim((string)$xpel_package));
    $legacyXpelMap = [
        'xpel_standard' => 'xpel_basic',
        'xpel_full_wrap' => 'xpel_full_vehicle_wrap',
    ];
    if (isset($legacyXpelMap[$xpel_package])) {
        $xpel_package = $legacyXpelMap[$xpel_package];
    }
}

if ($declineAll) {
    $selected = [];
}

if (!is_array($term_options)) {
    $term_options = [];
}

if (!is_array($variant_options)) {
    $variant_options = [];
}

// Apply term selections
if (!empty($term_options)) {
    $selectedLookup = array_map(fn($entry) => is_string($entry) ? strtolower(trim($entry)) : '', $selected);
    $selected = array_values(array_filter($selected, function ($entry) {
        return !(is_string($entry) && str_starts_with($entry, 'term_'));
    }));
    foreach ($term_options as $code => $months) {
        $code = strtolower(trim((string)$code));
        $raw = trim((string)$months);
        $term = 0;
        $kms = 0;
        if ($raw !== '') {
            // New format: "24_40000" (months_kms). Backward compatible with plain "24".
            if (preg_match('/^(\\d+)(?:[_-](\\d+))?$/', $raw, $m)) {
                $term = (int)($m[1] ?? 0);
                $kms = isset($m[2]) ? (int)$m[2] : 0;
            }
        }
        if ($code === '' || $term <= 0) {
            continue;
        }
        if (!in_array($code, $selectedLookup, true)) {
            continue;
        }
        $selected[] = $kms > 0
            ? ('term_' . $code . '_' . $term . '_' . $kms)
            : ('term_' . $code . '_' . $term);
    }
}

// Apply variant selections (option-set products like warranties).
if (!empty($variant_options)) {
    $selectedLookup = array_map(fn($entry) => is_string($entry) ? strtolower(trim($entry)) : '', $selected);
    foreach ($variant_options as $code => $variantValue) {
        $code = strtolower(trim((string)$code));
        $variantValue = strtolower(trim((string)$variantValue));
        if ($code === '' || $variantValue === '') {
            continue;
        }
        if (!in_array($code, $selectedLookup, true)) {
            continue;
        }
        // Expected: variant_<code>_<productId>
        if (!preg_match('/^variant_' . preg_quote($code, '/') . '_\\d+$/', $variantValue)) {
            continue;
        }
        // Remove any previous variant selection for this code.
        $selected = array_values(array_filter($selected, function ($entry) use ($code) {
            return !(is_string($entry) && str_starts_with(strtolower($entry), 'variant_' . $code . '_'));
        }));
        $selected[] = $variantValue;
    }
}

// Add XPEL selection if present
if ($xpel_package && in_array($xpel_package, [
    'xpel_intro',
    'xpel_basic',
    'xpel_intermediate',
    'xpel_premium',
    'xpel_premium_plus',
    'xpel_full_vehicle',
    'xpel_full_vehicle_wrap',
    'xpel_standard',
    'xpel_full_wrap',
], true)) {
    $selected = array_filter($selected, fn($s) => !str_starts_with($s, 'xpel_')); // Remove any accidental duplicates
    $selected[] = $xpel_package;
}

// Make sure application record exists
$check = $db->prepare("SELECT selected_protections, all_recommendations FROM applications WHERE deal_id = ?");
$check->execute([$deal_id]);
$app_exists = $check->fetch(PDO::FETCH_ASSOC);
$previousSelected = [];
if (!empty($app_exists['selected_protections'])) {
    $previousSelected = json_decode($app_exists['selected_protections'], true);
    if (!is_array($previousSelected)) {
        $previousSelected = [];
    }
}

if ($app_exists) {
    $stmt = $db->prepare("UPDATE applications SET selected_protections = ?, all_recommendations = ? WHERE deal_id = ?");
    $stmt->execute([
        json_encode($selected),
        json_encode($recommendations),
        $deal_id
    ]);
} else {
    $stmt = $db->prepare("INSERT INTO applications (deal_id, selected_protections, all_recommendations, payment_info, submitted_at)
                          VALUES (?, ?, ?, ?, NOW())");
    $stmt->execute([
        $deal_id,
        json_encode($selected),
        json_encode($recommendations),
        json_encode([]) // placeholder for payment_info
    ]);
}

// Insert audit log
$added = array_values(array_diff($selected, $previousSelected));
$removed = array_values(array_diff($previousSelected, $selected));
$changeMeta = json_encode([
    'selected_before' => $previousSelected,
    'selected_after' => $selected,
    'added' => $added,
    'removed' => $removed
]);
$changeType = $app_exists ? 'resubmit' : 'initial_submit';
$audit = $db->prepare("INSERT INTO protection_audit_log (deal_id, selected_protections, all_recommendations, user_id, change_type, change_meta, ai_narrative, submitted_at)
                       VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
$audit->execute([
    $deal_id,
    json_encode($selected),
    json_encode($recommendations),
    $_SESSION['user_id'] ?? null,
    $changeType,
    $changeMeta,
    $aiNarrative
]);

// Lock the credit app after the first completed selection.
$lockUserId = $_SESSION['user_id'] ?? null;
$lockStmt = $db->prepare("
    UPDATE deals
    SET credit_app_locked = 1,
        credit_app_locked_at = NOW(),
        credit_app_locked_by = ?
    WHERE id = ?
      AND (credit_app_locked = 0 OR credit_app_locked IS NULL)
");
$lockStmt->execute([$lockUserId, $deal_id]);

// Notify Finance Manager(s)
$dealStmt = $db->prepare("SELECT * FROM deals WHERE id = ?");
$dealStmt->execute([$deal_id]);
$dealData = $dealStmt->fetch(PDO::FETCH_ASSOC);

if ($dealData) {
    $org_id = $dealData['organization'] ?? null;
    $deal_type = $dealData['deal_type'] ?? 'Unknown';

    if ($org_id) {
        $user_stmt = $db->prepare("SELECT email FROM users WHERE organization = ? AND JSON_CONTAINS(role, '\"Finance Manager\"')");
        $user_stmt->execute([$org_id]);
        $manager_emails = $user_stmt->fetchAll(PDO::FETCH_COLUMN);

        $subject = "DealerFAI - Protection Selections Completed";
        $message = "Customer has submitted their protection choices for Deal #$deal_id.\n\n";
        $message .= ($deal_type === 'Cash')
            ? "This is a cash deal. Product selections have been submitted."
            : "This is a finance/lease deal. Application and product selections have been submitted.";

        foreach ($manager_emails as $email) {
            try {
                $smtpHost = $smtpConfig['host'] ?? ($_ENV['SMTP_HOST'] ?? getenv('SMTP_HOST') ?? '');
                $smtpUser = $smtpConfig['user'] ?? ($_ENV['SMTP_USER'] ?? getenv('SMTP_USER') ?? '');
                $smtpPass = $smtpConfig['pass'] ?? ($_ENV['SMTP_PASS'] ?? getenv('SMTP_PASS') ?? '');
                $smtpPort = (int)($smtpConfig['port'] ?? ($_ENV['SMTP_PORT'] ?? getenv('SMTP_PORT') ?? 587));
                $smtpSecure = $smtpConfig['secure'] ?? ($_ENV['SMTP_SECURE'] ?? getenv('SMTP_SECURE') ?? PHPMailer::ENCRYPTION_STARTTLS);
                $fromEmail = $smtpConfig['from_email'] ?? $smtpUser;
                $fromName = $smtpConfig['from_name'] ?? 'DealerFAI';

                $mail = new PHPMailer(true);
                $mail->isSMTP();
                $mail->Host = $smtpHost;
                $mail->SMTPAuth = true;
                $mail->Username = $smtpUser;
                $mail->Password = $smtpPass;
                $mail->SMTPSecure = $smtpSecure;
                $mail->Port = $smtpPort;
                $mail->setFrom($fromEmail, $fromName);
                $mail->addAddress($email);
                $mail->Subject = $subject;
                $mail->Body = $message;
                $mail->send();
            } catch (\Throwable $e) {
                error_log('Failed to send protection submission email to ' . $email . ': ' . $e->getMessage());
            }
        }
    }
}

// Clear wizard data after successful submission
unset($_SESSION['step1'], $_SESSION['step2'], $_SESSION['step3']);

// Redirect to thank you
header("Location: thank_you.php?deal_id=" . urlencode($deal_id));
exit;
?>
