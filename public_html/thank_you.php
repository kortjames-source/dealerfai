<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/security_headers.php';
require_once __DIR__ . '/includes/theme_head.php';
include 'db.php';
require_once __DIR__ . '/helpers/theme.php';

$deal_id = $_GET['deal_id'] ?? null;
$theme = dealerfai_get_theme_palette(null);
$logo = '';
$themeColor = $theme['color'];

if ($deal_id) {
  $stmt = $db->prepare("SELECT organization FROM deals WHERE id = ?");
  $stmt->execute([$deal_id]);
  $deal = $stmt->fetch(PDO::FETCH_ASSOC);

  if ($deal) {
    $orgstmt = $db->prepare("SELECT logo_url, theme_variant FROM organizations WHERE id = ?");
    $orgstmt->execute([$deal['organization']]);
    $org = $orgstmt->fetch(PDO::FETCH_ASSOC);
    $theme = dealerfai_get_theme_palette($org['theme_variant'] ?? null, ['logo' => $org['logo_url'] ?? '']);
    $logo = $theme['logo'];
    $themeColor = $theme['color'];
  }
}
?>
<!DOCTYPE html>
<html>
<head>
  <?php include __DIR__ . "/includes/head_favicon.php"; ?>
<meta charset="UTF-8">
  <title>Thank You - DealerFAI</title>
  <?php dealerfai_theme_head($theme ?? null); ?>
  <style nonce="<?= dealerfai_csp_nonce() ?>">
    body {
      font-family: Arial, sans-serif;
      background: <?= htmlspecialchars($theme['page_background']) ?>;
      padding: 50px;
      text-align: center;
    }
    .card {
      background: white;
      max-width: 600px;
      margin: auto;
      padding: 40px;
      border-radius: 10px;
      box-shadow: 0 2px 12px rgba(0,0,0,0.1);
    }
    h1 {
      color: <?= $themeColor ?>;
    }
    p {
      font-size: 18px;
      margin-top: 20px;
      color: #333;
    }
    .logo {
      max-height: 60px;
      margin-bottom: 20px;
    }
  </style>
</head>
<body>
  <div class="card">
    <?php if ($logo): ?>
      <img src="<?= htmlspecialchars($logo) ?>" class="logo" alt="Logo">
    <?php endif; ?>
    <h1>Thank You!</h1>
    <p>Your application has been submitted successfully.</p>
    <p>A team member will reach out shortly to finalize your purchase details.</p>
  </div>
</body>
</html>
