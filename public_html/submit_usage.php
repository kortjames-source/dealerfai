<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/security_headers.php';
require_once __DIR__ . '/includes/theme_head.php';
require_once __DIR__ . '/includes/csrf.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: application_step3.php");
    exit;
}

if (!dealerfai_csrf_validate($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    die('Invalid request.');
}

$_SESSION['step3'] = $_POST;
$_SESSION['custom_step3'] = $_POST['custom'] ?? [];

$deal_id = $_SESSION['deal_id'] ?? null;
if (!$deal_id) {
    die('Invalid session.');
}

include 'db.php';
require_once __DIR__ . '/helpers/theme.php';

$stmt = $db->prepare("SELECT organization FROM deals WHERE id = ?");
$stmt->execute([$deal_id]);
$org_id = $stmt->fetchColumn();

$theme = dealerfai_get_theme_palette(null);
if ($org_id) {
    $orgStmt = $db->prepare("SELECT logo_url, theme_variant FROM organizations WHERE id = ?");
    $orgStmt->execute([$org_id]);
    $org = $orgStmt->fetch(PDO::FETCH_ASSOC);
    if ($org) {
        $theme = dealerfai_get_theme_palette($org['theme_variant'] ?? null, ['logo' => $org['logo_url'] ?? '']);
    }
}

$payloadJson = json_encode($_POST, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
if ($payloadJson === false) {
    $payloadJson = '{}';
}
$dealParam = urlencode($deal_id);
?>
<!DOCTYPE html>
<html>
<head>
  <?php include __DIR__ . "/includes/head_favicon.php"; ?>
<meta charset="UTF-8">
  <title>Submitting Application</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php dealerfai_theme_head($theme ?? null); ?>
  <style nonce="<?= dealerfai_csp_nonce() ?>">
    body { font-family: "Segoe UI", sans-serif; background: <?= htmlspecialchars($theme['page_background']) ?>; margin: 0; padding: 0; color: #111111; display:flex; align-items:center; justify-content:center; min-height:100vh; }
    .card { background:#fff; padding:40px; border-radius:12px; box-shadow:0 10px 30px rgba(0,0,0,0.08); text-align:center; max-width:480px; width:90%; }
    .spinner {
      width:70px; height:70px; border:6px solid #e0e0e0; border-top-color: <?= htmlspecialchars($theme['color']) ?>;
      border-radius:50%; margin:0 auto 25px; animation:spin 1s linear infinite;
    }
    @keyframes spin { from {transform: rotate(0deg);} to {transform: rotate(360deg);} }
    h1 { margin-bottom:10px; }
    p { margin:0; color:#555; }
    .logo { max-width:100%; max-height:60px; height:auto; object-fit:contain; margin:0 auto 20px; display:block; }
    .error { color:#c82333; margin-top:20px; display:none; }
    .retry { margin-top:15px; display:none; }
    .retry button { background:#c82333; color:white; border:none; padding:10px 20px; border-radius:6px; cursor:pointer; }
  </style>
</head>
<body>
  <div class="card">
<?php if (!empty($theme['logo'])): ?>
      <img src="<?= htmlspecialchars($theme['logo']) ?>" alt="Dealer Logo" class="logo">
<?php endif; ?>
    <div class="spinner"></div>
    <h1>Submitting Your Details</h1>
    <p>We’re saving your answers and building your personalized protection plan. This can take up to a minute.</p>
    <p style="margin-top:15px; font-size:14px;">Please do not refresh or close this tab.</p>
    <p class="error" id="error-text"></p>
    <div class="retry" id="retry">
      <button onclick="startSubmission()">Try Again</button>
    </div>
  </div>

  <script nonce="<?= dealerfai_csp_nonce() ?>">
    const payload = <?= $payloadJson ?>;
    const redirectFallback = "recommendations_loading.php?deal_id=<?= $dealParam ?>";

    async function startSubmission() {
      document.getElementById('error-text').style.display = 'none';
      document.getElementById('retry').style.display = 'none';
      try {
        const response = await fetch('process_usage.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': payload && payload.csrf_token ? String(payload.csrf_token) : ''
          },
          body: JSON.stringify(payload),
          cache: 'no-store'
        });
        if (!response.ok) {
          throw new Error('Network response was not ok');
        }
        const data = await response.json();
        if (data && data.redirect) {
          window.location.href = data.redirect;
        } else {
          throw new Error(data.error || 'Unknown error');
        }
      } catch (err) {
        const errorText = document.getElementById('error-text');
        errorText.textContent = "We ran into a problem submitting your application. Please check your connection and try again.";
        errorText.style.display = 'block';
        document.getElementById('retry').style.display = 'block';
      }
    }

    document.addEventListener('DOMContentLoaded', startSubmission);
  </script>
</body>
</html>
