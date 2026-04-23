<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/security_headers.php';
require_once __DIR__ . '/includes/theme_head.php';
require_once __DIR__ . '/includes/csrf.php';
include 'db.php';
require_once __DIR__ . '/helpers/deal_access.php';
require_once __DIR__ . '/helpers/theme.php';

$deal_id = $_GET['deal_id'] ?? $_SESSION['deal_id'] ?? null;
if (!$deal_id) {
    die('Missing deal ID.');
}

$stmt = $db->prepare("SELECT id, organization, salesperson_id FROM deals WHERE id = ?");
$stmt->execute([$deal_id]);
$deal = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$deal || !dealerfai_session_can_access_deal($deal)) {
    http_response_code(403);
    die('Access denied.');
}
$org_id = (int)($deal['organization'] ?? 0);

$theme = dealerfai_get_theme_palette(null);
if ($org_id) {
    $orgStmt = $db->prepare("SELECT logo_url, theme_variant FROM organizations WHERE id = ?");
    $orgStmt->execute([$org_id]);
    $org = $orgStmt->fetch(PDO::FETCH_ASSOC);
    if ($org) {
        $theme = dealerfai_get_theme_palette($org['theme_variant'] ?? null, ['logo' => $org['logo_url'] ?? '']);
    }
}

$preScored = !empty($_GET['prescored']);
$redirectUrl = "recommendations.php?deal_id=" . urlencode($deal_id) . ($preScored ? '&prescored=1' : '');
$csrfToken = dealerfai_csrf_get_token();
?>
<!DOCTYPE html>
<html>
<head>
  <?php include __DIR__ . "/includes/head_favicon.php"; ?>
<meta charset="UTF-8">
  <title>Creating Your Plan</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php dealerfai_theme_head($theme ?? null); ?>
  <style nonce="<?= dealerfai_csp_nonce() ?>">
    body {
      font-family: "Segoe UI", Arial, sans-serif;
      background: <?= htmlspecialchars($theme['page_background']) ?>;
      margin: 0;
      padding: 0;
      color: #333;
      display: flex;
      align-items: center;
      justify-content: center;
      min-height: 100vh;
    }
    .card {
      background: #fff;
      padding: 40px;
      border-radius: 12px;
      box-shadow: 0 10px 40px rgba(0,0,0,0.08);
      text-align: center;
      max-width: 480px;
      width: 90%;
    }
    .spinner {
      width: 70px;
      height: 70px;
      border: 6px solid #e0e0e0;
      border-top-color: <?= htmlspecialchars($theme['color']) ?>;
      border-radius: 50%;
      animation: spin 1s linear infinite;
      margin: 0 auto 25px;
    }
    @keyframes spin {
      from { transform: rotate(0deg); }
      to { transform: rotate(360deg); }
    }
    h1 {
      margin-bottom: 10px;
      color: #111111;
    }
    p {
      margin: 0;
      color: #555;
      line-height: 1.5;
    }
    .hint {
      margin-top: 20px;
      font-size: 14px;
      color: #777;
    }
    .logo {
      max-width: 100%;
      max-height: 60px;
      height: auto;
      object-fit: contain;
      margin: 0 auto 20px;
      display: block;
    }
  </style>
  <script nonce="<?= dealerfai_csp_nonce() ?>">
    const redirectUrl = "<?= $redirectUrl ?>";
    const dealId = <?= json_encode($deal_id) ?>;
    const csrfToken = <?= json_encode($csrfToken) ?>;
    const fallbackRedirectDelayMs = 20000;
    let hasRedirected = false;
    let fallbackTimer = null;

    function redirectToRecommendations() {
      if (hasRedirected) {
        return;
      }
      hasRedirected = true;
      if (fallbackTimer) {
        clearTimeout(fallbackTimer);
      }
      window.location.href = redirectUrl;
    }

    async function pollRecommendations() {
      if (hasRedirected) {
        return;
      }
      try {
        const resp = await fetch(`check_recommendations.php?deal_id=${dealId}`, { cache: 'no-store' });
        if (!resp.ok) throw new Error('Network response was not ok');
        const data = await resp.json();
        if (data.ready) {
          redirectToRecommendations();
          return;
        }
      } catch (err) {
        console.warn('Polling error:', err);
      }
      setTimeout(pollRecommendations, 1500);
    }

    document.addEventListener('DOMContentLoaded', function () {
      fetch('api/start_recommendations.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': csrfToken
        },
        body: JSON.stringify({ deal_id: dealId, csrf_token: csrfToken }),
        cache: 'no-store'
      }).catch(() => {});
      pollRecommendations();
      setTimeout(function () {
        const hint = document.querySelector('.hint');
        if (hint) {
          hint.textContent = "Still working... if background loading does not finish shortly, we will open the recommendations page directly.";
        }
      }, 12000);
      fallbackTimer = setTimeout(function () {
        const hint = document.querySelector('.hint');
        if (hint) {
          hint.textContent = "Opening recommendations directly so the page can finish loading there.";
        }
        redirectToRecommendations();
      }, fallbackRedirectDelayMs);
    });
  </script>
</head>
<body>
  <div class="card">
    <?php if (!empty($theme['logo'])): ?>
      <img src="<?= htmlspecialchars($theme['logo']) ?>" alt="Dealer Logo" class="logo">
    <?php endif; ?>
    <div class="spinner"></div>
    <h1>Creating Your Personalized Plan</h1>
    <p>We’re analyzing your answers to build a protection plan tailored to your vehicle and driving habits. This can take up to a minute.</p>
    <p class="hint">Please leave this tab open; you’ll be redirected automatically.</p>
  </div>
</body>
</html>
