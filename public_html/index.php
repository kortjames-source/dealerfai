<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/security_headers.php';
require_once __DIR__ . '/includes/theme_head.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>DealerFAI | Intelligent Deal & Protection Portal</title>
  <?php include __DIR__ . "/includes/head_favicon.php"; ?>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
  <?php dealerfai_theme_head(); ?>
  <style nonce="<?= dealerfai_csp_nonce() ?>">
    body {
      margin: 0;
      font-family: "Segoe UI", sans-serif;
      background-color: #f4f6f8;
      color: #111111;
    }
    header {
      background-color: #0a2e36;
      color: white;
      padding: 30px 40px;
      text-align: center;
    }
    header img {
      max-width: 480px;
      max-height: 180px;
      height: auto;
      display: block;
      margin: 0 auto 10px;
    }
    header h1 {
      margin: 0;
      font-size: 2.4em;
    }
    nav {
      text-align: center;
      background-color: #11424f;
      padding: 12px;
    }
    nav a {
      color: #fff;
      text-decoration: none;
      margin: 0 25px;
      font-weight: bold;
    }
    nav a:hover {
      text-decoration: underline;
    }
    section {
      padding: 60px 40px;
      text-align: center;
    }
    .features {
      display: flex;
      flex-wrap: wrap;
      justify-content: center;
      margin-top: 40px;
    }
    .feature {
      background: white;
      border-radius: 10px;
      padding: 25px;
      margin: 15px;
      width: 280px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    }
    .feature h3 {
      margin-top: 0;
      color: #0a6280;
    }
    footer {
      background-color: #0a2e36;
      color: white;
      text-align: center;
      padding: 18px;
      font-size: 14px;
    }
    .cta-button {
      display: inline-block;
      background-color: #0a6280;
      color: white;
      padding: 14px 28px;
      font-size: 16px;
      border-radius: 6px;
      text-decoration: none;
      margin-top: 25px;
    }
    .cta-button:hover {
      background-color: #094c63;
    }
  </style>
</head>
<body class="bg-ai">
  <nav class="glass" style="position: sticky; top: 0; z-index: 1000; border-radius: 0; border-top: none; border-left: none; border-right: none;">
    <div style="max-width: 1200px; margin: 0 auto; display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 2rem;">
      <a href="index.php">
        <img src="dealerfai_logo_blue.png" alt="DealerFAI Logo" style="height: 45px;">
      </a>
      <div>
        <a href="login.php" class="btn btn-secondary btn-sm">Sign In</a>
        <a href="mailto:admin@dealerfai.com" class="btn btn-sm" style="margin-left: 1rem;">Get Support</a>
      </div>
    </div>
  </nav>

  <main style="padding-top: 2rem;">
    <section class="hero-box glass text-center" style="padding: 4rem 2rem 6rem;">
      <div style="margin-bottom: 2rem;">
        <img src="dealerfai_logo_blue.png" alt="DealerFAI Logo" style="height: 120px;">
      </div>
      <h1 class="text-gradient" style="font-size: 3.5rem; margin-bottom: 1rem; line-height: 1.1;">Intelligent Deal Management</h1>
      <p class="text-muted" style="font-size: 1.25rem; max-width: 700px; margin: 0 auto 2.5rem;">
        Empower your dealership with AI-driven protection logic and precision deal structuring. 
        The modern standard for profit optimization and customer transparency.
      </p>
      <div class="flex justify-center gap-4">
        <a href="login.php" class="btn" style="padding: 1rem 2.5rem; font-size: 1.125rem;">Launch Portal</a>
        <a href="#features" class="btn btn-secondary" style="padding: 1rem 2.5rem; font-size: 1.125rem;">Explore Features</a>
      </div>
    </section>

    <section id="features" style="padding: 4rem 0; position: relative;">
      <h2 class="text-center" style="margin-bottom: 3rem; color: white; font-size: 2.5rem; text-shadow: 0 2px 10px rgba(0,0,0,0.3);">Engineered for Performance</h2>
      <div class="features" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 2rem;">
        <div class="feature glass card" style="margin: 0; text-align: left; padding: 2rem;">
          <h3 class="mt-0" style="display: flex; align-items: center; gap: 0.75rem;">
            <span>⚡️</span> Dynamic Deal Flow
          </h3>
          <p class="text-muted">Capture and structure deals with intuitive inputs, automated calculations, and multi-user collaboration.</p>
        </div>
        <div class="feature glass card" style="margin: 0; text-align: left; padding: 2rem;">
          <h3 class="mt-0" style="display: flex; align-items: center; gap: 0.75rem;">
            <span>🤖</span> AI Protection Logic
          </h3>
          <p class="text-muted">Proprietary algorithms suggest the best protection products based on regional data and vehicle profiles.</p>
        </div>
        <div class="feature glass card" style="margin: 0; text-align: left; padding: 2rem;">
          <h3 class="mt-0" style="display: flex; align-items: center; gap: 0.75rem;">
            <span>📊</span> Advanced Analytics
          </h3>
          <p class="text-muted">Real-time insights into gross profit, take rates, and agent performance across all your store locations.</p>
        </div>
        <div class="feature glass card" style="margin: 0; text-align: left; padding: 2rem;">
          <h3 class="mt-0" style="display: flex; align-items: center; gap: 0.75rem;">
            <span>🛡️</span> Secure Applications
          </h3>
          <p class="text-muted">Encryption-first approach to customer data and application links, ensuring compliance at every step.</p>
        </div>
      </div>
    </section>
  </main>

  <footer style="background: rgba(15, 23, 42, 0.9); backdrop-filter: blur(10px); color: white; padding: 4rem 2rem; text-align: center; margin-top: 4rem;">
    <img src="dealerfai_logo_blue.png" alt="DealerFAI Logo" style="height: 60px; opacity: 0.9;">
    <p style="opacity: 0.6; max-width: 500px; margin: 0 auto 2rem;">
      DealerFAI is a premium platform designed for the modern automotive industry. 
      Built with security and scalability at its core.
    </p>
    <div style="border-top: 1px solid rgba(255,255,255,0.1); padding-top: 2rem; font-size: 0.875rem; opacity: 0.5;">
      &copy; <?php echo date("Y"); ?> DealerFAI. All rights reserved.
    </div>
  </footer>
</body>
</html>
