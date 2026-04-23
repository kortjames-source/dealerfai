<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/security_headers.php';
require_once __DIR__ . '/includes/theme_head.php';
require_once __DIR__ . '/includes/csrf.php';
include 'db.php';
require_once __DIR__ . '/helpers/customer_types.php';
require_once __DIR__ . '/helpers/theme.php';

function base_url(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host;
}

$code = trim((string)($_GET['code'] ?? ''));
$token = trim((string)($_GET['token'] ?? ''));
$deal = null;

if ($code !== '') {
    $stmt = $db->prepare("SELECT d.* FROM credit_app_links l JOIN deals d ON d.id = l.deal_id WHERE l.code = ? LIMIT 1");
    $stmt->execute([$code]);
    $deal = $stmt->fetch(PDO::FETCH_ASSOC);
} elseif ($token !== '') {
    $stmt = $db->prepare("SELECT * FROM deals WHERE secure_token = ? LIMIT 1");
    $stmt->execute([$token]);
    $deal = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$deal) {
    http_response_code(404);
    echo 'Credit application link not found.';
    exit;
}

$dealId = (int)($deal['id'] ?? 0);
$isLocked = !empty($deal['credit_app_locked']);

$theme = dealerfai_get_theme_palette(null, ['color' => '#0066cc']);
$orgId = (int)($deal['organization'] ?? 0);
$accessoriesEnabled = true;
if ($orgId > 0) {
    $hasAccessoriesFlag = false;
    try {
        $colStmt = $db->prepare("
            SELECT COUNT(*)
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'organizations'
              AND COLUMN_NAME = 'accessories_enabled'
        ");
        $colStmt->execute();
        $hasAccessoriesFlag = (int)$colStmt->fetchColumn() > 0;
    } catch (PDOException $e) {
        $hasAccessoriesFlag = false;
    }

    $select = $hasAccessoriesFlag ? "logo_url, theme_variant, accessories_enabled" : "logo_url, theme_variant";
    $orgStmt = $db->prepare("SELECT {$select} FROM organizations WHERE id = ?");
    $orgStmt->execute([$orgId]);
    $orgData = $orgStmt->fetch(PDO::FETCH_ASSOC);
    if ($orgData) {
        $theme = dealerfai_get_theme_palette($orgData['theme_variant'] ?? null, ['logo' => $orgData['logo_url'] ?? '']);
        if ($hasAccessoriesFlag) {
            $accessoriesEnabled = !empty($orgData['accessories_enabled']);
        }
    }
}

if ($isLocked) {
    $organizationName = 'the dealership';
    if ($orgId > 0) {
        try {
            $nameStmt = $db->prepare("SELECT name FROM organizations WHERE id = ? LIMIT 1");
            $nameStmt->execute([$orgId]);
            $organizationName = (string)($nameStmt->fetchColumn() ?: $organizationName);
        } catch (PDOException $e) {
            $organizationName = 'the dealership';
        }
    }
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <title>Application Locked</title>
      <?php dealerfai_theme_head($theme ?? null); ?>
  <style nonce="<?= dealerfai_csp_nonce() ?>">
        body { margin: 0; font-family: "Segoe UI", sans-serif; background: <?= htmlspecialchars($theme['page_background']) ?>; color: #111; }
        header { background: <?= htmlspecialchars($theme['header_background']) ?>; color: <?= htmlspecialchars($theme['header_text']) ?>; padding: 24px 32px; text-align: center; }
        header img { max-height: 56px; }
        .container { max-width: 760px; margin: 30px auto 40px; background: #fff; padding: 32px; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,0.08); }
        h1 { margin-top: 0; }
        .notice { background: #fff4e5; border: 1px solid #f4d3a1; color: #7a4d00; padding: 14px; border-radius: 8px; margin-top: 18px; }
        .muted { color: #667085; }
      </style>
    </head>
    <body>
      <header>
        <?php if (!empty($theme['logo'])): ?>
          <img src="<?= htmlspecialchars($theme['logo']) ?>" alt="Dealer Logo">
        <?php else: ?>
          <h1 class="m-0">DealerFAI</h1>
        <?php endif; ?>
      </header>
      <div class="container">
        <h1>Application Not Available</h1>
        <p>Your credit application is currently locked and cannot be submitted again at this time.</p>
        <div class="notice">
          Please contact <?= htmlspecialchars($organizationName) ?> if you need help or if you were asked to continue your application.
        </div>
        <p class="muted" class="mt-20">If you received a new invitation link, please use the most recent link sent by your dealership.</p>
      </div>
    </body>
    </html>
    <?php
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!dealerfai_csrf_validate($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        die('Invalid request.');
    }

    $_SESSION['deal_id'] = $dealId;
    $_SESSION['vehicle_make'] = $deal['vehicle_make'] ?? '';
    $_SESSION['vehicle_model'] = $deal['vehicle_model'] ?? '';

    if (($deal['deal_type'] ?? '') === 'Cash') {
        $dealToken = $deal['secure_token'] ?? '';
        if ($dealToken === '') {
            echo 'Missing secure token for cash application.';
            exit;
        }
        header('Location: cash_application_step1.php?token=' . urlencode($dealToken));
        exit;
    }

    $customerType = normalize_customer_type($deal['customer_type'] ?? 'personal');
    if ($customerType !== 'personal') {
        header('Location: business_application_step1.php');
        exit;
    }
    header('Location: application_step1.php');
    exit;
}
$csrfToken = dealerfai_csrf_get_token();

$vehicleLabel = trim((string)($deal['vehicle_year'] ?? '') . ' ' . (string)($deal['vehicle_make'] ?? '') . ' ' . (string)($deal['vehicle_model'] ?? ''));
$vehicleLabel = trim(preg_replace('/\s+/', ' ', $vehicleLabel));
$baseUrl = base_url();
$fullName = trim((string)($deal['customer_name'] ?? ''));
$firstName = '';
if ($fullName !== '') {
    $parts = preg_split('/\s+/', $fullName);
    if (!empty($parts)) {
        $firstName = trim((string)$parts[0]);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Credit Application</title>
  <style nonce="<?= dealerfai_csp_nonce() ?>">
    body { margin: 0; font-family: "Segoe UI", sans-serif; background: <?= htmlspecialchars($theme['page_background']) ?>; color: #111; }
    header { background: <?= htmlspecialchars($theme['header_background']) ?>; color: <?= htmlspecialchars($theme['header_text']) ?>; padding: 24px 32px; text-align: center; }
    header img { max-height: 56px; }
    .container { max-width: 820px; margin: 24px auto 40px; background: #fff; padding: 32px; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,0.08); }
    h1 { margin-top: 0; }
    .steps { display: grid; gap: 16px; margin-top: 16px; }
    .step { background: #f8fafc; border: 1px solid #e5e7eb; padding: 16px; border-radius: 10px; }
    .btn { background: <?= htmlspecialchars($theme['color']) ?>; color: #fff; border: none; padding: 12px 20px; border-radius: 6px; font-size: 16px; cursor: pointer; }
    .btn:hover { opacity: 0.9; }
    .muted { color: #667085; }
  </style>
</head>
<body>
  <header>
    <?php if (!empty($theme['logo'])): ?>
      <img src="<?= htmlspecialchars($theme['logo']) ?>" alt="Dealer Logo">
    <?php else: ?>
      <h1 class="m-0">DealerFAI</h1>
    <?php endif; ?>
  </header>
  <div class="container">
    <h1 class="text-center">Your Application, Simplified</h1>
    <?php if ($firstName !== ''): ?>
      <p class="muted" style="margin:0 0 8px;">Welcome, <?= htmlspecialchars($firstName) ?></p>
    <?php else: ?>
      <p class="muted" style="margin:0 0 8px;">Welcome</p>
    <?php endif; ?>
    <p class="muted">This short process helps us confirm your details and tailor recommendations based on how you plan to use your vehicle.</p>
    <?php if ($vehicleLabel !== ''): ?>
      <p><strong>Vehicle:</strong> <?= htmlspecialchars($vehicleLabel) ?></p>
    <?php endif; ?>

    <h2 class="mt-24">What to expect</h2>
    <div class="steps">
      <div class="step">
        <strong>Step 1: Your Application</strong>
        <div class="muted">Securely confirm your contact and credit information so we can move forward smoothly.</div>
      </div>
      <div class="step">
        <strong>Step 2: Your Vehicle Usage</strong>
        <div class="muted">Tell us how you plan to use the vehicle so we can personalize what we show you next.</div>
      </div>
      <?php if ($accessoriesEnabled): ?>
        <div class="step">
          <strong>Step 3: Accessory Options</strong>
          <div class="muted">Explore accessories selected to complement your vehicle and how you’ll be using it.</div>
        </div>
        <div class="step">
          <strong>Step 4: Protection Options</strong>
          <div class="muted">Review protection options personalized to your ownership plans, driving habits, and environment.</div>
        </div>
      <?php else: ?>
        <div class="step">
          <strong>Step 3: Protection Options</strong>
          <div class="muted">Review protection options personalized to your ownership plans, driving habits, and environment.</div>
        </div>
      <?php endif; ?>
    </div>

    <p class="muted" class="mt-20">When you’re ready, you can begin below. The process only takes a few minutes, and you can complete it at your own pace.</p>

    <form method="post" style="margin-top:16px; text-align:center;">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
      <button type="submit" class="btn">Start Application</button>
    </form>

    <p class="muted" class="mt-16">Questions or need assistance? Your dealership team is always happy to help.</p>
  </div>
</body>
</html>
