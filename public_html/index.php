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
<body>
  <header>
    <img src="dealerfai_logo.png" alt="DealerFAI Logo">
    <h1>DealerFAI</h1>
    <p>Smarter Vehicle Deal Management & Product Protection Platform</p>
  </header>
  <nav>
    <a href="login.php">Login</a>
    <a href="mailto:support@dealerfai.com">Contact Support</a>
  </nav>
  <section>
    <h2>Smarter Tools for Every Dealership</h2>
    <p>DealerFAI is your intelligent portal for creating, managing, and optimizing deals and protection product recommendations with precision.</p>
    <a class="cta-button" href="login.php">Start Now</a>

    <div class="features">
      <div class="feature">
        <h3>Deal Creation</h3>
        <p>Capture key deal details including vehicle info, financials, and assignments with ease.</p>
      </div>
      <div class="feature">
        <h3>AI Product Logic</h3>
        <p>Serve your customers better with smart protection suggestions backed by logic and region-specific data.</p>
      </div>
      <div class="feature">
        <h3>Analytics Dashboard</h3>
        <p>Review performance, gross profit, and take rates by store, user, or product line.</p>
      </div>
      <div class="feature">
        <h3>Secure Application</h3>
        <p>Send application links or complete forms at the desk – all encrypted and assigned to a deal.</p>
      </div>
    </div>
  </section>
  <footer>
    &copy; <?php echo date("Y"); ?> DealerFAI. All rights reserved.
  </footer>
</body>
</html>
