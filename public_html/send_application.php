<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/csrf.php';
require 'auth.php';
require 'db.php';
require 'vendor/autoload.php';
require_once __DIR__ . '/helpers/theme.php';

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

function redirect_back(string $fallback): void
{
    $target = $fallback;
    $posted = trim((string)($_POST['return_url'] ?? ''));
    if ($posted !== '') {
        $parts = parse_url($posted);
        $path = (string)($parts['path'] ?? '');
        $query = (string)($parts['query'] ?? '');
        if ($path !== '' && str_starts_with($path, '/')) {
            $path = ltrim($path, '/');
        }
        if ($path === 'view_deal.php') {
            $target = $path . ($query !== '' ? ('?' . $query) : '');
        }
    }
    header('Location: ' . $target);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method not allowed.';
    exit;
}

if (!dealerfai_csrf_validate($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    echo 'Invalid request.';
    exit;
}

$dealId = (int)($_POST['deal_id'] ?? 0);
if ($dealId <= 0) {
    $_SESSION['flash_error'] = 'Missing deal id.';
    redirect_back('view_deal.php');
}

$stmt = $db->prepare(
    "SELECT d.id, d.customer_email, d.customer_name, d.secure_token, d.deal_number, d.organization,
            o.name AS organization_name, o.logo_url, o.theme_variant
       FROM deals d
  LEFT JOIN organizations o ON o.id = d.organization
      WHERE d.id = ?"
);
$stmt->execute([$dealId]);
$deal = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$deal) {
    $_SESSION['flash_error'] = 'Deal not found.';
    redirect_back('view_deal.php');
}

$recipientEmail = trim((string)($_POST['confirmed_email'] ?? ''));
if ($recipientEmail === '') {
    $recipientEmail = trim((string)($deal['customer_email'] ?? ''));
}

if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
    $_SESSION['flash_error'] = 'Please enter a valid customer email address.';
    redirect_back('view_deal.php?id=' . urlencode((string)$dealId));
}

$token = trim((string)($deal['secure_token'] ?? ''));
if ($token === '') {
    $_SESSION['flash_error'] = 'Missing secure application link for this deal.';
    redirect_back('view_deal.php?id=' . urlencode((string)$dealId));
}

$localConfigPath = __DIR__ . '/../secure/local_config.php';
$localConfig = [];
if (file_exists($localConfigPath)) {
    $loaded = require $localConfigPath;
    if (is_array($loaded)) {
        $localConfig = $loaded;
    }
}
$smtpConfig = $localConfig['smtp'] ?? [];

$smtpHost = $smtpConfig['host'] ?? ($_ENV['SMTP_HOST'] ?? getenv('SMTP_HOST') ?? '');
$smtpUser = $smtpConfig['user'] ?? ($_ENV['SMTP_USER'] ?? getenv('SMTP_USER') ?? '');
$smtpPass = $smtpConfig['pass'] ?? ($_ENV['SMTP_PASS'] ?? getenv('SMTP_PASS') ?? '');
$smtpPort = (int)($smtpConfig['port'] ?? ($_ENV['SMTP_PORT'] ?? getenv('SMTP_PORT') ?? 587));
$smtpSecure = $smtpConfig['secure'] ?? ($_ENV['SMTP_SECURE'] ?? getenv('SMTP_SECURE') ?? PHPMailer::ENCRYPTION_STARTTLS);
$fromEmail = $smtpConfig['from_email'] ?? $smtpUser;
$fromName = $smtpConfig['from_name'] ?? 'DealerFAI';

$customerName = trim((string)($deal['customer_name'] ?? 'Customer'));
if ($customerName === '') {
    $customerName = 'Customer';
}

$themeVariant = trim((string)($deal['theme_variant'] ?? ''));
$brandTheme = dealerfai_get_theme_palette($themeVariant);
$brandColor = $brandTheme['color'];

$organizationName = trim((string)($deal['organization_name'] ?? 'your dealership'));
$logoUrl = trim((string)($deal['logo_url'] ?? ''));
$dealNumber = trim((string)($deal['deal_number'] ?? ''));

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'dealerfai.com';
$baseUrl = $scheme . '://' . $host;
$link = $baseUrl . '/credit_app_landing.php?token=' . urlencode($token);

$subject = $organizationName . ': complete your vehicle credit application';

$logoBlock = '';
if ($logoUrl !== '') {
    $logoBlock = '<img src="' . htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') . '" alt="' . htmlspecialchars($organizationName, ENT_QUOTES, 'UTF-8') . '" style="max-height:60px;max-width:240px;display:block;margin:0 auto 18px;">';
}

$dealLine = $dealNumber !== ''
    ? '<p style="margin:0 0 18px;color:#374151;">Deal reference: <strong>#' . htmlspecialchars($dealNumber, ENT_QUOTES, 'UTF-8') . '</strong></p>'
    : '';

$mailBody = '
  <div style="background:#f3f5f7;padding:28px 0;font-family:Segoe UI,Arial,sans-serif;color:#111827;">
    <div style="max-width:640px;margin:0 auto;background:#ffffff;border-radius:14px;padding:28px 30px;border:1px solid #e5e7eb;">
      ' . $logoBlock . '
      <h2 style="margin:0 0 14px;font-size:24px;line-height:1.3;color:#111827;">Your application is ready</h2>
      <p style="margin:0 0 14px;color:#374151;">Hi ' . htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8') . ',</p>
      <p style="margin:0 0 14px;color:#374151;">Thank you for choosing ' . htmlspecialchars($organizationName, ENT_QUOTES, 'UTF-8') . '. Please use the secure link below to complete your credit application.</p>
      ' . $dealLine . '
      <p style="margin:24px 0;">
        <a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;background:' . htmlspecialchars($brandColor, ENT_QUOTES, 'UTF-8') . ';color:#ffffff;text-decoration:none;font-weight:600;padding:12px 20px;border-radius:8px;">Start Application</a>
      </p>
      <p style="margin:0 0 10px;color:#6b7280;font-size:14px;">If the button does not open, copy and paste this link into your browser:</p>
      <p style="word-break:break-all;margin:0 0 18px;color:#374151;font-size:14px;">' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '</p>
      <p style="margin:0;color:#374151;">If you have any questions, just reply to this email and our team will help.</p>
      <p style="margin:16px 0 0;color:#374151;">Thank you,<br>' . htmlspecialchars($organizationName, ENT_QUOTES, 'UTF-8') . '</p>
    </div>
  </div>';

$mailAltBody = "Hi {$customerName},\n\n"
    . "Thank you for choosing {$organizationName}. Please complete your credit application using this secure link:\n"
    . "{$link}\n\n"
    . ($dealNumber !== '' ? "Deal reference: #{$dealNumber}\n\n" : '')
    . "If you have any questions, reply to this email and our team will help.\n\n"
    . "Thank you,\n{$organizationName}";

$mail = new PHPMailer(true);

try {
    $mail->isSMTP();
    $mail->Host = $smtpHost;
    $mail->SMTPAuth = true;
    $mail->Username = $smtpUser;
    $mail->Password = $smtpPass;
    $mail->SMTPSecure = $smtpSecure;
    $mail->Port = $smtpPort;

    $mail->setFrom($fromEmail, $fromName);
    $mail->addAddress($recipientEmail, $customerName);
    $mail->isHTML(true);
    $mail->Subject = $subject;
    $mail->Body = $mailBody;
    $mail->AltBody = $mailAltBody;

    $mail->send();

    $_SESSION['flash_success'] = 'Application invite sent to ' . $recipientEmail . '.';
} catch (Exception $e) {
    $_SESSION['flash_error'] = 'Could not send email: ' . $mail->ErrorInfo;
}

redirect_back('view_deal.php?id=' . urlencode((string)$dealId));
