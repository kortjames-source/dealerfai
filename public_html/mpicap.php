<?php
declare(strict_types=1);

// Publicly accessible page (no login required)
require_once __DIR__ . '/includes/session_bootstrap.php';
include 'db.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/helpers/session_utils.php';
require_once __DIR__ . '/helpers/theme.php';
require_once __DIR__ . '/includes/theme_head.php';
require_once __DIR__ . '/includes/security_headers.php';

$nonce = dealerfai_csp_nonce();

// Check if user is logged in
$isLoggedIn = isset($_SESSION['user_id']);
$navRoles = $isLoggedIn ? load_session_roles() : [];
$isAdmin = in_array('Admin', $navRoles, true);
$userName = $isLoggedIn ? (string)($_SESSION['full_name'] ?? 'Finance Manager') : '';

$org_id = $isLoggedIn ? get_effective_organization() : (isset($_GET['org']) ? (int)$_GET['org'] : 0);

$theme = dealerfai_get_theme_palette(null, ['color' => '#0066cc']);
$orgName = 'DealerFAI';
$orgLogo = '';

if ($org_id && isset($db) && ($db instanceof PDO)) {
    try {
        $stmt = $db->prepare("SELECT name, logo_url, theme_variant FROM organizations WHERE id = ?");
        $stmt->execute([$org_id]);
        $org = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($org) {
            $orgName = $org['name'] ?? 'DealerFAI';
            $orgLogo = $org['logo_url'] ?? '';
            $theme = dealerfai_get_theme_palette($org['theme_variant'] ?? null, ['logo' => $orgLogo]);
        }
    } catch (Throwable $e) {
        // Fall back to default theme
    }
}

// Check for optional deal_id parameter to prefill deal details
$dealId = isset($_GET['deal_id']) ? (int)$_GET['deal_id'] : null;
$prefillClient = 'Valued Client';
$prefillVehicle = '2026 Land Rover Defender 110 S P300';
$prefillSalePrice = 85000;
$prefillTerm = 60;
$prefillRate = 7.99;

if ($dealId && $dealId > 0 && isset($db) && ($db instanceof PDO)) {
    try {
        $stmt = $db->prepare("SELECT * FROM deals WHERE id = ? LIMIT 1");
        $stmt->execute([$dealId]);
        $deal = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($deal) {
            $nameParts = array_filter([$deal['customer_first_name'] ?? '', $deal['customer_last_name'] ?? '']);
            if (!empty($nameParts)) {
                $prefillClient = implode(' ', $nameParts);
            } elseif (!empty($deal['customer_name'])) {
                $prefillClient = (string)$deal['customer_name'];
            }
            $vehYear = trim((string)($deal['vehicle_year'] ?? ''));
            $vehMake = trim((string)($deal['vehicle_make_name'] ?? ''));
            $vehModel = trim((string)($deal['vehicle_model_name'] ?? ''));
            $vehString = trim("$vehYear $vehMake $vehModel");
            if ($vehString !== '') {
                $prefillVehicle = $vehString;
            }
            if (!empty($deal['sale_price']) && (float)$deal['sale_price'] > 0) {
                $prefillSalePrice = (float)$deal['sale_price'];
            }
            if (!empty($deal['term']) && (int)$deal['term'] > 0) {
                $prefillTerm = (int)$deal['term'];
            }
            if (isset($deal['interest_rate']) && is_numeric($deal['interest_rate'])) {
                $prefillRate = (float)$deal['interest_rate'];
            }
        }
    } catch (Throwable $e) {
        // Fall back to defaults
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php include __DIR__ . "/includes/head_favicon.php"; ?>
  <meta charset="UTF-8">
  <title>MPI vs CAP Insurance Comparison - <?= htmlspecialchars($orgName) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php dealerfai_theme_head($theme ?? null); ?>
  <style nonce="<?= $nonce ?>">
    /* MPI vs CAP Comparison Styles */
    :root {
      --mpi-blue: #004b87;
      --mpi-light-blue: #e6f0fa;
      --cap-green: #059669;
      --cap-green-light: #ecfdf5;
      --alert-red: #dc2626;
      --alert-red-light: #fef2f2;
      --card-border: #e2e8f0;
    }

    body.standalone-view {
      background-color: #f8fafc;
      margin: 0;
    }

    .standalone-header {
      background: #ffffff;
      border-bottom: 1px solid #e2e8f0;
      padding: 0.85rem 2rem;
      display: flex;
      justify-content: space-between;
      align-items: center;
      position: sticky;
      top: 0;
      z-index: 900;
    }

    .standalone-logo-area {
      display: flex;
      align-items: center;
      gap: 1rem;
    }

    .standalone-logo-area img {
      height: 34px;
      width: auto;
    }

    .mpi-cap-container {
      max-width: 1400px;
      margin: 0 auto;
      padding: 1.5rem 2rem 4rem;
    }

    .top-action-bar {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      justify-content: space-between;
      gap: 1rem;
      background: #ffffff;
      padding: 1rem 1.5rem;
      border-radius: var(--radius-lg);
      border: 1px solid var(--border-color);
      box-shadow: var(--shadow-sm);
      margin-bottom: 2rem;
    }

    .client-badge-bar {
      display: flex;
      align-items: center;
      gap: 1.25rem;
      flex-wrap: wrap;
    }

    .client-title {
      font-size: 1.25rem;
      font-weight: 700;
      color: #0f172a;
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }

    .client-subtitle {
      font-size: 0.875rem;
      color: #64748b;
    }

    .dsr-pill {
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      background: #ecfdf5;
      color: #047857;
      border: 1px solid #a7f3d0;
      padding: 0.4rem 0.85rem;
      border-radius: 9999px;
      font-size: 0.85rem;
      font-weight: 700;
    }

    .freq-toggle-group {
      display: inline-flex;
      background: #f1f5f9;
      padding: 4px;
      border-radius: 9999px;
      border: 1px solid #cbd5e1;
    }

    .freq-btn {
      border: none;
      background: transparent;
      padding: 0.45rem 1.15rem;
      font-size: 0.875rem;
      font-weight: 600;
      color: #475569;
      border-radius: 9999px;
      cursor: pointer;
      transition: all 0.2s ease;
    }

    .freq-btn.active {
      background: var(--brand-color);
      color: #ffffff;
      box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    }

    .action-btn {
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      padding: 0.5rem 1rem;
      border-radius: var(--radius-md);
      font-size: 0.875rem;
      font-weight: 600;
      cursor: pointer;
      text-decoration: none;
      transition: all 0.2s ease;
      border: 1px solid var(--border-color);
      background: #ffffff;
      color: #334155;
    }

    .action-btn:hover {
      background: #f8fafc;
      border-color: #cbd5e1;
    }

    .action-btn.primary {
      background: var(--brand-color);
      color: #ffffff;
      border-color: var(--brand-color);
    }

    .action-btn.primary:hover {
      opacity: 0.92;
    }

    /* Manager Input Drawer / Accordion */
    .manager-panel {
      background: #ffffff;
      border-radius: var(--radius-lg);
      border: 1px solid var(--border-color);
      box-shadow: var(--shadow-sm);
      margin-bottom: 2.5rem;
      overflow: hidden;
      transition: all 0.3s ease;
    }

    .manager-panel-header {
      padding: 1rem 1.5rem;
      background: #f8fafc;
      border-bottom: 1px solid var(--border-color);
      display: flex;
      justify-content: space-between;
      align-items: center;
      cursor: pointer;
    }

    .manager-panel-header h3 {
      margin: 0;
      font-size: 1rem;
      font-weight: 700;
      color: #1e293b;
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }

    .manager-panel-body {
      padding: 1.5rem;
      display: block;
    }

    .manager-panel.collapsed .manager-panel-body {
      display: none;
    }

    .grid-2col {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
      gap: 1.5rem;
      margin-bottom: 1.5rem;
    }

    .grid-3col {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
      gap: 1.25rem;
      margin-bottom: 1.5rem;
    }

    .form-group {
      margin-bottom: 0.75rem;
    }

    .form-group label {
      display: block;
      margin-bottom: 0.3rem;
      font-size: 0.8rem;
      font-weight: 600;
      color: #475569;
      text-transform: uppercase;
      letter-spacing: 0.025em;
    }

    .form-control-sm {
      width: 100%;
      padding: 0.5rem 0.75rem;
      font-size: 0.875rem;
      border: 1px solid #cbd5e1;
      border-radius: var(--radius-md);
      background-color: #ffffff;
      color: #0f172a;
      box-sizing: border-box;
    }

    .form-control-sm:focus {
      outline: none;
      border-color: var(--brand-color);
      box-shadow: 0 0 0 3px rgba(0, 102, 204, 0.15);
    }

    /* Comparison Hero Cards */
    .comparison-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
      gap: 1.5rem;
      margin-bottom: 2.5rem;
    }

    .hero-card {
      background: #ffffff;
      border-radius: var(--radius-lg);
      border: 1px solid var(--border-color);
      padding: 1.75rem;
      box-shadow: var(--shadow-sm);
      display: flex;
      flex-direction: column;
      position: relative;
      overflow: hidden;
    }

    .hero-card.highlight {
      border-color: #10b981;
      background: linear-gradient(180deg, #ffffff 0%, #f0fdf4 100%);
      box-shadow: 0 10px 25px -5px rgba(16, 185, 129, 0.15);
    }

    .hero-card.combined {
      border-color: var(--brand-color);
      background: linear-gradient(180deg, #ffffff 0%, rgba(0, 102, 204, 0.04) 100%);
      box-shadow: 0 10px 25px -5px rgba(0, 102, 204, 0.15);
    }

    .hero-card-tag {
      font-size: 0.75rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      margin-bottom: 0.75rem;
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }

    .hero-amount {
      font-size: 2.75rem;
      font-weight: 800;
      color: #0f172a;
      line-height: 1;
      margin-bottom: 0.5rem;
    }

    .hero-amount span.period {
      font-size: 1rem;
      font-weight: 500;
      color: #64748b;
      margin-left: 0.25rem;
    }

    .hero-card-subtext {
      font-size: 0.875rem;
      color: #64748b;
      margin-bottom: 1.25rem;
      line-height: 1.4;
    }

    .increase-badge {
      display: inline-flex;
      align-items: center;
      gap: 0.35rem;
      background: #fee2e2;
      color: #b91c1c;
      font-size: 0.8rem;
      font-weight: 700;
      padding: 0.3rem 0.65rem;
      border-radius: 9999px;
      margin-top: auto;
    }

    .fixed-badge {
      display: inline-flex;
      align-items: center;
      gap: 0.35rem;
      background: #dcfce7;
      color: #15803d;
      font-size: 0.8rem;
      font-weight: 700;
      padding: 0.3rem 0.65rem;
      border-radius: 9999px;
      margin-top: auto;
    }

    /* Rate Increase Deep-Dive Card */
    .rate-increase-card {
      background: #ffffff;
      border-radius: var(--radius-lg);
      border: 1px solid var(--border-color);
      padding: 2rem;
      box-shadow: var(--shadow-sm);
      margin-bottom: 2.5rem;
    }

    .rate-increase-header {
      display: flex;
      flex-wrap: wrap;
      justify-content: space-between;
      align-items: center;
      gap: 1rem;
      border-bottom: 1px solid #e2e8f0;
      padding-bottom: 1.25rem;
      margin-bottom: 1.5rem;
    }

    .rate-increase-header h3 {
      margin: 0;
      font-size: 1.35rem;
      font-weight: 700;
      color: #0f172a;
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }

    .rates-comparison-table {
      width: 100%;
      border-collapse: collapse;
      margin-bottom: 1.5rem;
      font-size: 0.9rem;
    }

    .rates-comparison-table th {
      text-align: left;
      padding: 0.75rem 1rem;
      background: #f8fafc;
      font-weight: 700;
      color: #475569;
      border-bottom: 2px solid #e2e8f0;
      font-size: 0.8rem;
      text-transform: uppercase;
      letter-spacing: 0.025em;
    }

    .rates-comparison-table td {
      padding: 0.75rem 1rem;
      border-bottom: 1px solid #f1f5f9;
      color: #1e293b;
    }

    .rates-comparison-table tr.subtotal {
      background: #f8fafc;
      font-weight: 700;
    }

    .rates-comparison-table tr.total-row {
      background: #f1f5f9;
      font-weight: 800;
      font-size: 1rem;
    }

    .rates-comparison-table tr.total-row td {
      border-top: 2px solid #cbd5e1;
      border-bottom: 2px solid #cbd5e1;
    }

    .callout-box {
      background: #f8fafc;
      border-left: 4px solid var(--brand-color);
      padding: 1.25rem 1.5rem;
      border-radius: 0 var(--radius-md) var(--radius-md) 0;
      font-size: 0.9rem;
      color: #334155;
      line-height: 1.6;
    }

    /* Replacement Value Showcase */
    .replacement-showcase-card {
      background: linear-gradient(135deg, #064e3b 0%, #0f172a 100%);
      color: #ffffff;
      border-radius: var(--radius-lg);
      padding: 2.5rem;
      margin-bottom: 2.5rem;
      box-shadow: var(--shadow-lg);
      position: relative;
      overflow: hidden;
    }

    .showcase-header {
      max-width: 850px;
      margin-bottom: 2rem;
    }

    .showcase-header .badge-tag {
      display: inline-block;
      background: #10b981;
      color: #ffffff;
      font-weight: 700;
      font-size: 0.75rem;
      padding: 0.3rem 0.75rem;
      border-radius: 9999px;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      margin-bottom: 0.75rem;
    }

    .showcase-header h2 {
      text-align: left;
      color: #ffffff;
      font-size: 2rem;
      font-weight: 800;
      margin-bottom: 0.75rem;
      letter-spacing: -0.025em;
    }

    .showcase-header p {
      font-size: 1.05rem;
      color: rgba(255, 255, 255, 0.85);
      line-height: 1.6;
      margin: 0;
    }

    .pillars-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
      gap: 1.5rem;
      margin-bottom: 2.5rem;
    }

    .pillar-item {
      background: rgba(255, 255, 255, 0.08);
      border: 1px solid rgba(255, 255, 255, 0.15);
      backdrop-filter: blur(8px);
      padding: 1.5rem;
      border-radius: var(--radius-md);
      transition: transform 0.2s ease, background 0.2s ease;
    }

    .pillar-item:hover {
      background: rgba(255, 255, 255, 0.12);
      transform: translateY(-3px);
    }

    .pillar-icon {
      width: 44px;
      height: 44px;
      border-radius: 10px;
      background: rgba(16, 185, 129, 0.25);
      color: #34d399;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.25rem;
      margin-bottom: 1rem;
    }

    .pillar-title {
      font-size: 1.1rem;
      font-weight: 700;
      color: #ffffff;
      margin-bottom: 0.5rem;
    }

    .pillar-desc {
      font-size: 0.875rem;
      color: rgba(255, 255, 255, 0.8);
      line-height: 1.5;
      margin: 0;
    }

    /* Scenario Comparison: MPI Alone vs CAP */
    .scenario-box {
      background: rgba(15, 23, 42, 0.7);
      border: 1px solid rgba(255, 255, 255, 0.2);
      border-radius: var(--radius-md);
      padding: 1.75rem;
    }

    .scenario-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
      gap: 1.5rem;
      margin-top: 1rem;
    }

    .scenario-column {
      padding: 1.5rem;
      border-radius: var(--radius-md);
    }

    .scenario-column.mpi {
      background: rgba(220, 38, 38, 0.1);
      border: 1px solid rgba(239, 68, 68, 0.3);
    }

    .scenario-column.cap {
      background: rgba(16, 185, 129, 0.15);
      border: 1px solid rgba(16, 185, 129, 0.4);
    }

    .scenario-title {
      font-size: 1.05rem;
      font-weight: 700;
      margin-bottom: 1rem;
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }

    .scenario-row {
      display: flex;
      justify-content: space-between;
      padding: 0.4rem 0;
      border-bottom: 1px solid rgba(255, 255, 255, 0.1);
      font-size: 0.875rem;
    }

    .scenario-row.highlight {
      font-weight: 800;
      font-size: 1rem;
      padding-top: 0.75rem;
      border-bottom: none;
    }

    /* CAP Term Options Grid */
    .cap-terms-container {
      background: #ffffff;
      border-radius: var(--radius-lg);
      border: 1px solid var(--border-color);
      padding: 2rem;
      box-shadow: var(--shadow-sm);
      margin-bottom: 2.5rem;
    }

    .cap-terms-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
      gap: 1.25rem;
      margin-top: 1.25rem;
    }

    .cap-term-card {
      border: 2px solid #e2e8f0;
      border-radius: var(--radius-md);
      padding: 1.25rem;
      cursor: pointer;
      transition: all 0.2s ease;
      background: #ffffff;
      position: relative;
    }

    .cap-term-card:hover {
      border-color: #94a3b8;
      box-shadow: var(--shadow-sm);
    }

    .cap-term-card.selected {
      border-color: var(--brand-color);
      background: rgba(0, 102, 204, 0.03);
      box-shadow: 0 4px 12px rgba(0, 102, 204, 0.15);
    }

    .cap-term-badge {
      position: absolute;
      top: 10px;
      right: 10px;
      font-size: 0.7rem;
      font-weight: 700;
      background: #dbeafe;
      color: #1e40af;
      padding: 2px 6px;
      border-radius: 4px;
    }

    /* Presentation Mode: Hides Sidebar & Navigation */
    body.presentation-mode .sidebar,
    body.presentation-mode .top-bar,
    body.presentation-mode .standalone-header,
    body.presentation-mode footer,
    body.presentation-mode .manager-panel {
      display: none !important;
    }

    body.presentation-mode .main-container {
      margin: 0;
      padding: 0;
      width: 100%;
    }

    body.presentation-mode .mpi-cap-container {
      max-width: 1300px;
      padding-top: 2rem;
    }

    /* Print Stylesheet */
    @media print {
      .sidebar,
      .top-bar,
      .standalone-header,
      footer,
      .manager-panel,
      .top-action-bar .freq-toggle-group,
      .action-btn,
      .presentation-toggle {
        display: none !important;
      }

      body {
        background: #ffffff !important;
        color: #000000 !important;
      }

      .main-container,
      .mpi-cap-container {
        padding: 0 !important;
        margin: 0 !important;
        max-width: 100% !important;
      }

      .hero-card,
      .rate-increase-card,
      .cap-terms-container {
        box-shadow: none !important;
        border: 1px solid #cbd5e1 !important;
        page-break-inside: avoid;
      }

      .replacement-showcase-card {
        background: #064e3b !important;
        color: #ffffff !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
        page-break-inside: avoid;
      }
    }
  </style>
</head>
<body class="<?= $isLoggedIn ? 'dashboard-wrapper' : 'standalone-view' ?>">

  <?php if ($isLoggedIn): ?>
    <!-- Sidebar for logged in users -->
    <aside class="sidebar">
      <div class="sidebar-header" style="padding: 1.5rem;">
        <img src="dealerfai_logo_white.png" alt="DealerFAI" style="height: 35px; width: auto;">
      </div>
      <nav class="sidebar-nav" style="background: transparent; padding: 1.5rem 1rem;">
        <a href="dashboard" class="sidebar-link">
          <i class="fa-solid fa-gauge"></i> Dashboard
        </a>
        <a href="view_deals" class="sidebar-link">
          <i class="fa-solid fa-file-invoice-dollar"></i> View Deals
        </a>
        <a href="create_deal" class="sidebar-link">
          <i class="fa-solid fa-plus-circle"></i> Create Deal
        </a>
        <a href="mpicap" class="sidebar-link active">
          <i class="fa-solid fa-shield-halved"></i> MPI vs CAP Tool
        </a>

        <?php if ($isAdmin): ?>
          <div style="margin-top: 2rem; padding: 0 1rem; font-size: 0.75rem; color: rgba(255,255,255,0.4); text-transform: uppercase; letter-spacing: 0.05em; font-weight: 700;">Admin</div>
          <a href="admin_scoring_log" class="sidebar-link">
            <i class="fa-solid fa-list-check"></i> Scoring Log
          </a>
          <a href="manage_users" class="sidebar-link">
            <i class="fa-solid fa-users"></i> Users
          </a>
          <a href="admin_organizations" class="sidebar-link">
            <i class="fa-solid fa-building"></i> Organizations
          </a>
        <?php endif; ?>

        <?php if (in_array('General Manager', $navRoles) || $isAdmin): ?>
          <a href="admin_tools" class="sidebar-link">
            <i class="fa-solid fa-wrench"></i> Admin Tools
          </a>
        <?php endif; ?>
      </nav>
      <div style="padding: 1.5rem; border-top: 1px solid rgba(255,255,255,0.1);">
        <p style="font-size: 0.75rem; color: rgba(255,255,255,0.4); margin: 0;">DealerFAI v2.0</p>
      </div>
    </aside>
  <?php else: ?>
    <!-- Standalone Clean Header for Public Customers / Direct Links -->
    <header class="standalone-header">
      <div class="standalone-logo-area">
        <img src="dealerfai_logo_modern.png" alt="DealerFAI">
        <span style="font-weight: 700; color: #0f172a; font-size: 1.1rem; border-left: 2px solid #cbd5e1; padding-left: 0.75rem;">
          MPI vs CAP Insurance Estimator
        </span>
      </div>
      <div>
        <a href="login" class="action-btn" style="font-size: 0.8rem;">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
          Staff Login
        </a>
      </div>
    </header>
  <?php endif; ?>

  <!-- Main Content -->
  <div class="main-container">
    <?php if ($isLoggedIn): ?>
      <header class="top-bar">
        <div class="breadcrumb" style="font-weight: 600; color: #64748b;">
          <a href="dashboard" style="color: inherit; text-decoration: none;">DealerFAI</a> 
          <i class="fa-solid fa-chevron-right" style="font-size: 0.75rem; margin: 0 0.5rem; opacity: 0.5;"></i> MPI vs CAP Insurance
        </div>
        <div style="display: flex; align-items: center; gap: 1rem;">
          <a href="dashboard" class="action-btn" style="font-size: 0.8rem;">
            <i class="fa-solid fa-gauge" style="font-size: 0.85rem;"></i> Dashboard
          </a>
          <div style="text-align: right;">
            <div style="font-size: 0.875rem; font-weight: 700; color: #0f172a;"><?= htmlspecialchars($userName) ?></div>
            <a href="logout" style="font-size: 0.75rem; color: #64748b; text-decoration: none;">Log Out</a>
          </div>
          <div style="width: 36px; height: 36px; background: var(--brand-color); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.85rem;">
            <?= strtoupper(substr($userName, 0, 1)) ?>
          </div>
        </div>
      </header>
    <?php endif; ?>

    <main class="page-content" style="padding: 0;">
      <div class="mpi-cap-container">

        <!-- Top Action Bar with Client Info & Controls -->
        <div class="top-action-bar">
          <div class="client-badge-bar">
            <div>
              <div class="client-title">
                <span id="disp-client-name"><?= htmlspecialchars($prefillClient) ?></span>
                <span style="font-weight: 400; color: #94a3b8;">—</span>
                <span id="disp-vehicle-name" style="font-weight: 600; color: var(--brand-color);"><?= htmlspecialchars($prefillVehicle) ?></span>
              </div>
              <div class="client-subtitle">
                MPI Manitoba Public Insurance & Dealership CAP Asset Protection Analysis
              </div>
            </div>
            <div id="disp-dsr-badge" class="dsr-pill">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
              <span id="disp-dsr-text">Level +15 • 47% Vehicle Discount</span>
            </div>
          </div>

          <div style="display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap;">
            <!-- Monthly / Bi-Weekly Selector -->
            <div class="freq-toggle-group">
              <button type="button" class="freq-btn active" id="btn-monthly">Monthly</button>
              <button type="button" class="freq-btn" id="btn-biweekly">Bi-Weekly</button>
            </div>

            <!-- Customer Presentation Toggle -->
            <button type="button" class="action-btn presentation-toggle" id="btn-toggle-presentation" title="Toggle Clean Customer Mode">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
              <span>Present to Client</span>
            </button>

            <!-- Print Quote Button -->
            <button type="button" class="action-btn" id="btn-print" title="Print or save PDF takeaway">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
              <span>Print Quote</span>
            </button>
          </div>
        </div>

        <!-- Manager Inputs Panel (Collapsible) -->
        <div class="manager-panel <?= $isLoggedIn ? '' : 'collapsed' ?>" id="manager-input-panel">
          <div class="manager-panel-header" id="manager-panel-toggle">
            <h3>
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
              Finance Setup & Rates Configuration
            </h3>
            <span style="font-size: 0.85rem; color: #64748b; font-weight: 600;" id="panel-toggle-indicator">
              <?= $isLoggedIn ? 'Click to Collapse ▲' : 'Click to Edit Setup ▼' ?>
            </span>
          </div>

          <div class="manager-panel-body">
            <!-- Client & Deal Information -->
            <div class="grid-3col">
              <div class="form-group">
                <label for="inp-client-name">Client Name</label>
                <input type="text" id="inp-client-name" class="form-control-sm" value="<?= htmlspecialchars($prefillClient) ?>">
              </div>
              <div class="form-group">
                <label for="inp-vehicle-name">Vehicle Year / Make / Model</label>
                <input type="text" id="inp-vehicle-name" class="form-control-sm" value="<?= htmlspecialchars($prefillVehicle) ?>">
              </div>
              <div class="form-group">
                <label for="inp-dsr-level">Driver Safety Rating (DSR)</label>
                <select id="inp-dsr-level" class="form-control-sm">
                  <!-- Populated via JavaScript: Level 0 to +20 -->
                </select>
              </div>
            </div>

            <div class="grid-3col">
              <div class="form-group">
                <label for="inp-loan-term">Financing Term (Months)</label>
                <input type="number" id="inp-loan-term" class="form-control-sm" value="<?= (int)$prefillTerm ?>" step="12" min="12" max="96">
              </div>
              <div class="form-group">
                <label for="inp-interest-rate">Financing APR (%)</label>
                <input type="number" id="inp-interest-rate" class="form-control-sm" value="<?= (float)$prefillRate ?>" step="0.01" min="0" max="29.99">
              </div>
              <div class="form-group">
                <label for="inp-veh-price">Vehicle Price ($)</label>
                <input type="number" id="inp-veh-price" class="form-control-sm" value="<?= (float)$prefillSalePrice ?>" step="500">
              </div>
            </div>

            <!-- MPI Itemized Lines Input (2025 vs 2026) -->
            <div style="margin-top: 1.25rem; border-top: 1px solid #e2e8f0; padding-top: 1.25rem;">
              <h4 style="margin: 0 0 0.75rem 0; font-size: 0.95rem; font-weight: 700; color: #1e293b; display: flex; align-items: center; justify-content: space-between;">
                <span>MPI Calculator Line Items</span>
                <button type="button" id="btn-load-sample" class="action-btn" style="padding: 0.25rem 0.65rem; font-size: 0.75rem;">Reset to Defender Sample</button>
              </h4>

              <div style="overflow-x: auto;">
                <table class="rates-comparison-table" style="margin-bottom: 0.5rem;">
                  <thead>
                    <tr>
                      <th style="width: 44%;">Line Item</th>
                      <th style="width: 28%;">2026 Rates (Effective Apr 01, 2026)</th>
                      <th style="width: 28%;">2025 Rates (Effective Jul 01, 2025)</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr>
                      <td>Basic Insurance Premium</td>
                      <td><input type="number" class="form-control-sm mpi-input" data-col="2026" id="mpi-26-basic" value="3294"></td>
                      <td><input type="number" class="form-control-sm mpi-input" data-col="2025" id="mpi-25-basic" value="2824"></td>
                    </tr>
                    <tr>
                      <td>Deductible</td>
                      <td><input type="number" class="form-control-sm mpi-input" data-col="2026" id="mpi-26-deductible" value="238"></td>
                      <td><input type="number" class="form-control-sm mpi-input" data-col="2025" id="mpi-25-deductible" value="117"></td>
                    </tr>
                    <tr>
                      <td>Third Party Liability</td>
                      <td><input type="number" class="form-control-sm mpi-input" data-col="2026" id="mpi-26-tpl" value="11"></td>
                      <td><input type="number" class="form-control-sm mpi-input" data-col="2025" id="mpi-25-tpl" value="10"></td>
                    </tr>
                    <tr>
                      <td>Loss of Use - Passenger Vehicle</td>
                      <td><input type="number" class="form-control-sm mpi-input" data-col="2026" id="mpi-26-lossuse" value="143"></td>
                      <td><input type="number" class="form-control-sm mpi-input" data-col="2025" id="mpi-25-lossuse" value="136"></td>
                    </tr>
                    <tr>
                      <td>New/Leased Vehicle Protection</td>
                      <td><input type="number" class="form-control-sm mpi-input" data-col="2026" id="mpi-26-newveh" value="392"></td>
                      <td><input type="number" class="form-control-sm mpi-input" data-col="2025" id="mpi-25-newveh" value="412"></td>
                    </tr>
                    <tr>
                      <td>Maximum Insured Value</td>
                      <td><input type="number" class="form-control-sm mpi-input" data-col="2026" id="mpi-26-maxval" value="101"></td>
                      <td><input type="number" class="form-control-sm mpi-input" data-col="2025" id="mpi-25-maxval" value="101"></td>
                    </tr>
                    <tr>
                      <td>Interest and Administration Fee</td>
                      <td><input type="number" class="form-control-sm mpi-input" data-col="2026" id="mpi-26-admin" value="132"></td>
                      <td><input type="number" class="form-control-sm mpi-input" data-col="2025" id="mpi-25-admin" value="129"></td>
                    </tr>
                    <tr class="subtotal">
                      <td>Total Insurance Cost</td>
                      <td><strong id="sum-26-ins">$4,311</strong></td>
                      <td><strong id="sum-25-ins">$3,729</strong></td>
                    </tr>
                    <tr>
                      <td>Registration Charge</td>
                      <td><input type="number" class="form-control-sm mpi-input" data-col="2026" id="mpi-26-reg" value="119"></td>
                      <td><input type="number" class="form-control-sm mpi-input" data-col="2025" id="mpi-25-reg" value="119"></td>
                    </tr>
                    <tr>
                      <td>Plate Use Charge</td>
                      <td><input type="number" class="form-control-sm mpi-input" data-col="2026" id="mpi-26-plate" value="7"></td>
                      <td><input type="number" class="form-control-sm mpi-input" data-col="2025" id="mpi-25-plate" value="7"></td>
                    </tr>
                    <tr class="subtotal">
                      <td>Total Registration Cost</td>
                      <td><strong id="sum-26-reg">$126</strong></td>
                      <td><strong id="sum-25-reg">$126</strong></td>
                    </tr>
                    <tr class="total-row">
                      <td>TOTAL ESTIMATE (Annual)</td>
                      <td><strong id="sum-26-total" style="color: #0f172a;">$4,437</strong></td>
                      <td><strong id="sum-25-total" style="color: #0f172a;">$3,855</strong></td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>

            <!-- CAP Term Pricing Setup -->
            <div style="margin-top: 1.5rem; border-top: 1px solid #e2e8f0; padding-top: 1.25rem;">
              <h4 style="margin: 0 0 0.5rem 0; font-size: 0.95rem; font-weight: 700; color: #1e293b;">
                Dealership CAP Insurance Term Prices ($)
              </h4>
              <p style="font-size: 0.8rem; color: #64748b; margin-bottom: 1rem;">
                Enter prices only for the terms you wish to offer. Terms left blank will automatically be hidden on the customer view.
              </p>
              <div class="grid-3col" style="grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));">
                <div class="form-group">
                  <label for="cap-price-36">36 Months</label>
                  <input type="number" id="cap-price-36" class="form-control-sm cap-input" placeholder="Optional" data-term="36">
                </div>
                <div class="form-group">
                  <label for="cap-price-48">48 Months</label>
                  <input type="number" id="cap-price-48" class="form-control-sm cap-input" placeholder="Optional" data-term="48">
                </div>
                <div class="form-group">
                  <label for="cap-price-60">60 Months (Default)</label>
                  <input type="number" id="cap-price-60" class="form-control-sm cap-input" placeholder="e.g. 2219" value="2219" data-term="60">
                </div>
                <div class="form-group">
                  <label for="cap-price-72">72 Months</label>
                  <input type="number" id="cap-price-72" class="form-control-sm cap-input" placeholder="Optional" data-term="72">
                </div>
                <div class="form-group">
                  <label for="cap-price-84">84 Months (7 Years)</label>
                  <input type="number" id="cap-price-84" class="form-control-sm cap-input" placeholder="e.g. 2617" value="2617" data-term="84">
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- ========================================================================= -->
        <!-- CUSTOMER VIEW: EXECUTIVE COMPARISON HERO CARDS -->
        <!-- ========================================================================= -->
        <div class="comparison-grid">
          <!-- Card 1: MPI Base Alone -->
          <div class="hero-card">
            <div class="hero-card-tag" style="color: var(--mpi-blue);">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
              <span>MPI Base Insurance Alone</span>
            </div>
            <div class="hero-amount" id="disp-mpi-amount">
              $370<span class="period">/mo</span>
            </div>
            <div class="hero-card-subtext">
              Annual Estimate: <strong id="disp-mpi-annual">$4,437</strong><br>
              Covers basic road liability & depreciated cash value only.
            </div>
            <div class="increase-badge" id="disp-mpi-increase-pill">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
              <span id="disp-increase-pill-text">+15.1% (+48.50/mo) from 2025</span>
            </div>
          </div>

          <!-- Card 2: CAP Asset Protection Alone -->
          <div class="hero-card highlight">
            <div class="hero-card-tag" style="color: var(--cap-green);">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>
              <span>Dealership CAP Insurance</span>
            </div>
            <div class="hero-amount" id="disp-cap-amount" style="color: var(--cap-green);">
              $44<span class="period">.90/mo</span>
            </div>
            <div class="hero-card-subtext">
              <strong id="disp-cap-term-label">60-Month (5-Year)</strong> Replacement Protection<br>
              Breakdown: Just <strong id="disp-cap-per-day">$1.47/day</strong> for peace of mind.
            </div>
            <div class="fixed-badge">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
              <span>100% Rate Lock Guarantee (No Yearly Hikes)</span>
            </div>
          </div>

          <!-- Card 3: Combined Complete Protection -->
          <div class="hero-card combined">
            <div class="hero-card-tag" style="color: var(--brand-color);">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
              <span>Complete Combined Protection</span>
            </div>
            <div class="hero-amount" id="disp-combined-amount" style="color: var(--brand-color);">
              $414<span class="period">.90/mo</span>
            </div>
            <div class="hero-card-subtext">
              MPI Basic + CAP Full Replacement Value Top-Up.<br>
              Zero surprise depreciation loss in a total write-off.
            </div>
            <div class="fixed-badge" style="background: #e0f2fe; color: #0369a1;">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
              <span>Full Replacement Top-Up + $500 Deductible Paid</span>
            </div>
          </div>
        </div>

        <!-- ========================================================================= -->
        <!-- SECTION: MPI 2025 vs 2026 RATE INCREASE ANALYSIS -->
        <!-- ========================================================================= -->
        <div class="rate-increase-card">
          <div class="rate-increase-header">
            <div>
              <h3>
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2.5"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
                MPI Insurance Rate Increase: 2025 vs. 2026
              </h3>
              <p style="margin: 0.35rem 0 0 0; color: #64748b; font-size: 0.875rem;">
                Official comparison based on Public Utilities Board (PUB) approved Manitoba Public Insurance rates.
              </p>
            </div>
            <div style="text-align: right;">
              <div style="font-size: 0.75rem; text-transform: uppercase; color: #64748b; font-weight: 700;">Year-over-Year Increase</div>
              <div style="font-size: 1.5rem; font-weight: 800; color: #dc2626;" id="disp-increase-headline">+$582/year (+15.1%)</div>
            </div>
          </div>

          <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 1.5rem; margin-bottom: 1.5rem;">
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: var(--radius-md); padding: 1.25rem;">
              <div style="font-size: 0.75rem; font-weight: 700; color: #64748b; text-transform: uppercase;">2025 MPI Cost</div>
              <div style="font-size: 1.5rem; font-weight: 800; color: #0f172a; margin-top: 0.25rem;" id="disp-2025-cost">$3,855 / yr</div>
              <div style="font-size: 0.85rem; color: #64748b; margin-top: 0.25rem;" id="disp-2025-period-cost">$321.25 / month ($148.27 bi-weekly)</div>
            </div>
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: var(--radius-md); padding: 1.25rem;">
              <div style="font-size: 0.75rem; font-weight: 700; color: #64748b; text-transform: uppercase;">2026 MPI Cost</div>
              <div style="font-size: 1.5rem; font-weight: 800; color: #0f172a; margin-top: 0.25rem;" id="disp-2026-cost">$4,437 / yr</div>
              <div style="font-size: 0.85rem; color: #64748b; margin-top: 0.25rem;" id="disp-2026-period-cost">$369.75 / month ($170.65 bi-weekly)</div>
            </div>
            <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: var(--radius-md); padding: 1.25rem;">
              <div style="font-size: 0.75rem; font-weight: 700; color: #dc2626; text-transform: uppercase;">Your Increase Amount</div>
              <div style="font-size: 1.5rem; font-weight: 800; color: #dc2626; margin-top: 0.25rem;" id="disp-increase-cost">+$582 / yr</div>
              <div style="font-size: 0.85rem; color: #991b1b; margin-top: 0.25rem;" id="disp-increase-period-cost">+$48.50 / month (+$22.38 bi-weekly)</div>
            </div>
          </div>

          <div class="callout-box">
            <strong>Key Insight for Vehicle Owners:</strong>
            MPI Basic insurance rates are subject to annual adjustments to match inflation, repair technologies, and vehicle claims. Even with safe driving discounts (like <strong>DSR Level <span id="disp-dsr-inline">+15</span></strong>), your base insurance rose by <strong id="disp-increase-inline">$48.50/month</strong> from 2025 to 2026. Furthermore, MPI's New Vehicle Protection costs <strong>$392–$412 per year</strong> and automatically terminates after just 2 years.
            <br><br>
            <strong>In contrast:</strong> Dealership CAP Insurance guarantees a <strong>100% locked-in rate for up to 7 years</strong>. It never increases, protecting your budget while delivering vastly superior replacement coverage.
          </div>
        </div>

        <!-- ========================================================================= -->
        <!-- SECTION: REPLACEMENT VALUE TOP-UP SHOWCASE (BROCHURE HIGHLIGHTS) -->
        <!-- ========================================================================= -->
        <div class="replacement-showcase-card">
          <div class="showcase-header">
            <span class="badge-tag">Why CAP Insurance Is Essential</span>
            <h2>When a Vehicle Is Written Off, You Have to Buy Another Car.</h2>
            <p>
              In the event of a total loss (collision, fire, theft, flood, or hail), <strong>MPI only settles for depreciated Actual Cash Value (ACV)</strong>. 
              CAP Insurance provides the crucial <strong>Replacement Value Top-Up</strong> directly towards purchasing your replacement vehicle—so you get back into the same vehicle class without thousands of dollars out of pocket.
            </p>
          </div>

          <!-- 4 Pillars Grid -->
          <div class="pillars-grid">
            <div class="pillar-item">
              <div class="pillar-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="8" r="7"/><polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"/></svg>
              </div>
              <div class="pillar-title">Up to $60,000 Saved</div>
              <p class="pillar-desc">
                Protects you against rapid vehicle depreciation, saving you up to $60,000 to replace like or kind, model, year, and trim level.
              </p>
            </div>

            <div class="pillar-item">
              <div class="pillar-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
              </div>
              <div class="pillar-title">Guaranteed Active for 7 Years</div>
              <p class="pillar-desc">
                Covers New or Pre-Owned vehicles for up to 7 years. Remains active regardless of your driving experience, claims, or losses.
              </p>
            </div>

            <div class="pillar-item">
              <div class="pillar-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
              </div>
              <div class="pillar-title">30-Day Rental Vehicle</div>
              <p class="pillar-desc">
                Includes full rental car benefits for up to 30 days while your replacement vehicle is arranged, so you're never stranded.
              </p>
            </div>

            <div class="pillar-item">
              <div class="pillar-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
              </div>
              <div class="pillar-title">Deductible Reimbursement</div>
              <p class="pillar-desc">
                Provides up to $500 deductible coverage on total loss (or $250 on partial loss). Includes GAP benefit if replacement credit is under $5,000.
              </p>
            </div>
          </div>

          <!-- Total Loss Scenario Side-by-Side -->
          <div class="scenario-box">
            <h4 style="margin: 0 0 0.5rem 0; font-size: 1.15rem; font-weight: 700; color: #ffffff;">
              Total Loss Write-Off Reality: What Happens Without vs. With CAP?
            </h4>
            <p style="margin: 0 0 1rem 0; font-size: 0.85rem; color: rgba(255, 255, 255, 0.75);">
              Example based on a $85,000 vehicle written off in Year 3 with an outstanding balance or replacement need:
            </p>

            <div class="scenario-grid">
              <div class="scenario-column mpi">
                <div class="scenario-title" style="color: #fca5a5;">
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                  <span>MPI Base Alone (Without CAP)</span>
                </div>
                <div class="scenario-row">
                  <span>MPI Payout</span>
                  <strong>Depreciated Value (~$52,000)</strong>
                </div>
                <div class="scenario-row">
                  <span>Deductible Paid by Client</span>
                  <span style="color: #fca5a5;">-$500 out-of-pocket</span>
                </div>
                <div class="scenario-row">
                  <span>Next Vehicle Top-Up</span>
                  <span style="color: #fca5a5;">$0.00 from MPI</span>
                </div>
                <div class="scenario-row">
                  <span>Rental Car Beyond MPI Basic</span>
                  <span style="color: #fca5a5;">Client Pays</span>
                </div>
                <div class="scenario-row highlight" style="color: #fca5a5;">
                  <span>Out-of-Pocket To Replace:</span>
                  <span>$25,000+ Deprec. Loss</span>
                </div>
              </div>

              <div class="scenario-column cap">
                <div class="scenario-title" style="color: #6ee7b7;">
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><polyline points="9 11 12 14 22 4"/></svg>
                  <span>With Dealership CAP Insurance</span>
                </div>
                <div class="scenario-row">
                  <span>MPI Base Payout</span>
                  <strong>~$52,000 Depreciated Value</strong>
                </div>
                <div class="scenario-row">
                  <span>CAP Replacement Credit Top-Up</span>
                  <span style="color: #6ee7b7;">+$25,000+ Direct Credit</span>
                </div>
                <div class="scenario-row">
                  <span>Deductible Reimbursement</span>
                  <span style="color: #6ee7b7;">+$500 Paid Back</span>
                </div>
                <div class="scenario-row">
                  <span>Rental Vehicle Coverage</span>
                  <span style="color: #6ee7b7;">30 Days Included</span>
                </div>
                <div class="scenario-row highlight" style="color: #6ee7b7;">
                  <span>Out-of-Pocket To Replace:</span>
                  <span>$0.00 (Like / Kind Replaced)</span>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- ========================================================================= -->
        <!-- SECTION: CAP AVAILABLE TERMS & PAYMENT OPTIONS -->
        <!-- ========================================================================= -->
        <div class="cap-terms-container" id="cap-terms-section">
          <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
            <div>
              <h3 style="margin: 0; font-size: 1.25rem; font-weight: 700; color: #0f172a;">
                Choose Your CAP Protection Term
              </h3>
              <p style="margin: 0.25rem 0 0 0; color: #64748b; font-size: 0.85rem;">
                Select your preferred coverage term below to update the monthly and bi-weekly payment comparison.
              </p>
            </div>
            <div style="font-size: 0.85rem; color: #64748b; background: #f8fafc; padding: 0.4rem 0.75rem; border-radius: var(--radius-md); border: 1px solid #e2e8f0;">
              Financed at <strong id="disp-apr-badge"><?= (float)$prefillRate ?>% APR</strong> over <strong id="disp-loan-term-badge"><?= (int)$prefillTerm ?> months</strong>
            </div>
          </div>

          <div class="cap-terms-grid" id="cap-terms-cards">
            <!-- Dynamic Cards generated by JavaScript based on populated terms -->
          </div>
        </div>

      </div>
    </main>

    <footer style="background: white; border-top: 1px solid #e2e8f0; color: #64748b; padding: 1.5rem; text-align: center; position: static;">
      &copy; <?= date("Y") ?> <?= htmlspecialchars($orgName) ?>. Powered by DealerFAI. All rights reserved.
    </footer>
  </div>

  <script nonce="<?= $nonce ?>">
    document.addEventListener('DOMContentLoaded', () => {
      // Driver Safety Rating (DSR) Scale: Level 0 to +20
      const DSR_SCALE = [
        { level: 20, discount: 53, driverFee: 25, isNew: true },
        { level: 19, discount: 53, driverFee: 25 },
        { level: 18, discount: 52, driverFee: 25 },
        { level: 17, discount: 50, driverFee: 25 },
        { level: 16, discount: 49, driverFee: 25 },
        { level: 15, discount: 47, driverFee: 25 },
        { level: 14, discount: 43, driverFee: 30 },
        { level: 13, discount: 41, driverFee: 30 },
        { level: 12, discount: 40, driverFee: 30 },
        { level: 11, discount: 37, driverFee: 30 },
        { level: 10, discount: 35, driverFee: 30 },
        { level: 9,  discount: 33, driverFee: 35 },
        { level: 8,  discount: 30, driverFee: 40 },
        { level: 7,  discount: 29, driverFee: 40 },
        { level: 6,  discount: 26, driverFee: 40 },
        { level: 5,  discount: 22, driverFee: 40 },
        { level: 4,  discount: 20, driverFee: 40 },
        { level: 3,  discount: 15, driverFee: 45 },
        { level: 2,  discount: 11, driverFee: 45 },
        { level: 1,  discount: 5,  driverFee: 50 },
        { level: 0,  discount: 0,  driverFee: 55, isBase: true },
      ];

      // Populate DSR Select dropdown
      const dsrSelect = document.getElementById('inp-dsr-level');
      if (dsrSelect) {
        DSR_SCALE.forEach(item => {
          const opt = document.createElement('option');
          opt.value = item.level;
          const labelPrefix = item.level > 0 ? `+${item.level}` : '0 (Base)';
          const tag = item.isNew ? ' [NEW 2026]' : '';
          opt.textContent = `Level ${labelPrefix} — ${item.discount}% Vehicle Discount (Driver Fee $${item.driverFee})${tag}`;
          if (item.level === 15) opt.selected = true;
          dsrSelect.appendChild(opt);
        });
      }

      // State
      let paymentFrequency = 'monthly'; // 'monthly' | 'biweekly'
      let selectedCapTerm = 60; // default term

      // DOM Elements
      const btnMonthly = document.getElementById('btn-monthly');
      const btnBiweekly = document.getElementById('btn-biweekly');
      const btnTogglePres = document.getElementById('btn-toggle-presentation');
      const btnPrint = document.getElementById('btn-print');
      const managerPanel = document.getElementById('manager-input-panel');
      const managerToggle = document.getElementById('manager-panel-toggle');
      const toggleIndicator = document.getElementById('panel-toggle-indicator');

      // Presentation mode toggle
      if (btnTogglePres) {
        btnTogglePres.addEventListener('click', () => {
          document.body.classList.toggle('presentation-mode');
          const isPres = document.body.classList.contains('presentation-mode');
          btnTogglePres.innerHTML = isPres
            ? `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg> <span>Exit Presentation</span>`
            : `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg> <span>Present to Client</span>`;
        });
      }

      // Print handler
      if (btnPrint) {
        btnPrint.addEventListener('click', () => {
          window.print();
        });
      }

      // Manager panel collapse/expand
      if (managerToggle && managerPanel) {
        managerToggle.addEventListener('click', () => {
          managerPanel.classList.toggle('collapsed');
          toggleIndicator.textContent = managerPanel.classList.contains('collapsed')
            ? 'Click to Edit Setup ▼'
            : 'Click to Collapse ▲';
        });
      }

      // Frequency switchers
      if (btnMonthly && btnBiweekly) {
        btnMonthly.addEventListener('click', () => {
          paymentFrequency = 'monthly';
          btnMonthly.classList.add('active');
          btnBiweekly.classList.remove('active');
          recalculate();
        });

        btnBiweekly.addEventListener('click', () => {
          paymentFrequency = 'biweekly';
          btnBiweekly.classList.add('active');
          btnMonthly.classList.remove('active');
          recalculate();
        });
      }

      // Reset to Defender sample data
      const btnSample = document.getElementById('btn-load-sample');
      if (btnSample) {
        btnSample.addEventListener('click', () => {
          document.getElementById('mpi-26-basic').value = 3294;
          document.getElementById('mpi-26-deductible').value = 238;
          document.getElementById('mpi-26-tpl').value = 11;
          document.getElementById('mpi-26-lossuse').value = 143;
          document.getElementById('mpi-26-newveh').value = 392;
          document.getElementById('mpi-26-maxval').value = 101;
          document.getElementById('mpi-26-admin').value = 132;
          document.getElementById('mpi-26-reg').value = 119;
          document.getElementById('mpi-26-plate').value = 7;

          document.getElementById('mpi-25-basic').value = 2824;
          document.getElementById('mpi-25-deductible').value = 117;
          document.getElementById('mpi-25-tpl').value = 10;
          document.getElementById('mpi-25-lossuse').value = 136;
          document.getElementById('mpi-25-newveh').value = 412;
          document.getElementById('mpi-25-maxval').value = 101;
          document.getElementById('mpi-25-admin').value = 129;
          document.getElementById('mpi-25-reg').value = 119;
          document.getElementById('mpi-25-plate').value = 7;

          document.getElementById('cap-price-60').value = 2219;
          document.getElementById('cap-price-84').value = 2617;
          recalculate();
        });
      }

      // Recalculate on any input change
      const allInputs = document.querySelectorAll('.mpi-input, .cap-input, #inp-loan-term, #inp-interest-rate, #inp-client-name, #inp-vehicle-name, #inp-dsr-level, #inp-veh-price');
      allInputs.forEach(input => {
        input.addEventListener('input', recalculate);
        input.addEventListener('change', recalculate);
      });

      // Format currency
      function fmt(val) {
        return '$' + Math.round(val).toLocaleString();
      }

      function fmtDec(val) {
        return '$' + (Number(val).toFixed(2)).replace(/\B(?=(\d{3})+(?!\d))/g, ",");
      }

      // Calculate Loan Payment (Amortization)
      function calcAmortization(principal, annualRatePct, termMonths, freq) {
        if (principal <= 0 || termMonths <= 0) return 0;
        const p = Number(principal);
        const apr = Number(annualRatePct);

        if (apr <= 0) {
          // 0% interest
          const monthlyPmt = p / termMonths;
          return freq === 'biweekly' ? (monthlyPmt * 12) / 26 : monthlyPmt;
        }

        const rMonthly = (apr / 100) / 12;
        const nMonths = termMonths;
        const pmtMonthly = p * (rMonthly * Math.pow(1 + rMonthly, nMonths)) / (Math.pow(1 + rMonthly, nMonths) - 1);

        if (freq === 'biweekly') {
          return (pmtMonthly * 12) / 26;
        }
        return pmtMonthly;
      }

      function recalculate() {
        // Update client & vehicle text
        const clientName = document.getElementById('inp-client-name').value.trim() || 'Valued Client';
        const vehName = document.getElementById('inp-vehicle-name').value.trim() || 'Vehicle';
        document.getElementById('disp-client-name').textContent = clientName;
        document.getElementById('disp-vehicle-name').textContent = vehName;

        // DSR Badge update
        const dsrLvl = parseInt(document.getElementById('inp-dsr-level').value, 10) || 0;
        const dsrObj = DSR_SCALE.find(d => d.level === dsrLvl) || { level: 0, discount: 0, driverFee: 55 };
        const dsrSign = dsrObj.level > 0 ? `+${dsrObj.level}` : '0';
        document.getElementById('disp-dsr-text').textContent = `Level ${dsrSign} • ${dsrObj.discount}% Safe Driver Vehicle Discount`;
        document.getElementById('disp-dsr-inline').textContent = dsrSign;

        // Terms and Financing Rate
        const loanTerm = parseInt(document.getElementById('inp-loan-term').value, 10) || 60;
        const interestRate = parseFloat(document.getElementById('inp-interest-rate').value) || 0;
        document.getElementById('disp-loan-term-badge').textContent = `${loanTerm} months`;
        document.getElementById('disp-apr-badge').textContent = `${interestRate.toFixed(2)}% APR`;

        // Sum MPI 2026 Lines
        const basic26 = parseFloat(document.getElementById('mpi-26-basic').value) || 0;
        const ded26 = parseFloat(document.getElementById('mpi-26-deductible').value) || 0;
        const tpl26 = parseFloat(document.getElementById('mpi-26-tpl').value) || 0;
        const loss26 = parseFloat(document.getElementById('mpi-26-lossuse').value) || 0;
        const newveh26 = parseFloat(document.getElementById('mpi-26-newveh').value) || 0;
        const maxval26 = parseFloat(document.getElementById('mpi-26-maxval').value) || 0;
        const admin26 = parseFloat(document.getElementById('mpi-26-admin').value) || 0;
        const reg26 = parseFloat(document.getElementById('mpi-26-reg').value) || 0;
        const plate26 = parseFloat(document.getElementById('mpi-26-plate').value) || 0;

        const sumIns26 = basic26 + ded26 + tpl26 + loss26 + newveh26 + maxval26 + admin26;
        const sumReg26 = reg26 + plate26;
        const total26 = sumIns26 + sumReg26;

        document.getElementById('sum-26-ins').textContent = fmt(sumIns26);
        document.getElementById('sum-26-reg').textContent = fmt(sumReg26);
        document.getElementById('sum-26-total').textContent = fmt(total26);

        // Sum MPI 2025 Lines
        const basic25 = parseFloat(document.getElementById('mpi-25-basic').value) || 0;
        const ded25 = parseFloat(document.getElementById('mpi-25-deductible').value) || 0;
        const tpl25 = parseFloat(document.getElementById('mpi-25-tpl').value) || 0;
        const loss25 = parseFloat(document.getElementById('mpi-25-lossuse').value) || 0;
        const newveh25 = parseFloat(document.getElementById('mpi-25-newveh').value) || 0;
        const maxval25 = parseFloat(document.getElementById('mpi-25-maxval').value) || 0;
        const admin25 = parseFloat(document.getElementById('mpi-25-admin').value) || 0;
        const reg25 = parseFloat(document.getElementById('mpi-25-reg').value) || 0;
        const plate25 = parseFloat(document.getElementById('mpi-25-plate').value) || 0;

        const sumIns25 = basic25 + ded25 + tpl25 + loss25 + newveh25 + maxval25 + admin25;
        const sumReg25 = reg25 + plate25;
        const total25 = sumIns25 + sumReg25;

        document.getElementById('sum-25-ins').textContent = fmt(sumIns25);
        document.getElementById('sum-25-reg').textContent = fmt(sumReg25);
        document.getElementById('sum-25-total').textContent = fmt(total25);

        // Increases
        const diffTotal = total26 - total25;
        const pctIncrease = total25 > 0 ? ((diffTotal / total25) * 100) : 0;

        // Payment periods for MPI
        const mpiPeriod26 = paymentFrequency === 'biweekly' ? (total26 / 26) : (total26 / 12);
        const mpiPeriod25 = paymentFrequency === 'biweekly' ? (total25 / 26) : (total25 / 12);
        const diffPeriod = mpiPeriod26 - mpiPeriod25;

        const freqSuffix = paymentFrequency === 'biweekly' ? '/bi-wk' : '/mo';

        // Update MPI Hero Card
        document.getElementById('disp-mpi-amount').innerHTML = `${fmt(mpiPeriod26)}<span class="period">${freqSuffix}</span>`;
        document.getElementById('disp-mpi-annual').textContent = fmt(total26);
        document.getElementById('disp-increase-pill-text').textContent = `+${pctIncrease.toFixed(1)}% (+${fmtDec(diffPeriod)}${freqSuffix}) from 2025`;

        // Update Rate Increase Section
        document.getElementById('disp-increase-headline').textContent = `+${fmt(diffTotal)}/year (+${pctIncrease.toFixed(1)}%)`;
        document.getElementById('disp-2025-cost').textContent = `${fmt(total25)} / yr`;
        document.getElementById('disp-2026-cost').textContent = `${fmt(total26)} / yr`;
        document.getElementById('disp-increase-cost').textContent = `+${fmt(diffTotal)} / yr`;

        document.getElementById('disp-2025-period-cost').textContent = `${fmtDec(total25 / 12)} / month (${fmtDec(total25 / 26)} bi-weekly)`;
        document.getElementById('disp-2026-period-cost').textContent = `${fmtDec(total26 / 12)} / month (${fmtDec(total26 / 26)} bi-weekly)`;
        document.getElementById('disp-increase-period-cost').textContent = `+${fmtDec(diffTotal / 12)} / month (+${fmtDec(diffTotal / 26)} bi-weekly)`;
        document.getElementById('disp-increase-inline').textContent = `${fmtDec(diffTotal / 12)}/month`;

        // Process CAP Term Prices
        const capInputs = [
          { term: 36, el: document.getElementById('cap-price-36') },
          { term: 48, el: document.getElementById('cap-price-48') },
          { term: 60, el: document.getElementById('cap-price-60') },
          { term: 72, el: document.getElementById('cap-price-72') },
          { term: 84, el: document.getElementById('cap-price-84') },
        ];

        const activeCapOptions = [];
        capInputs.forEach(item => {
          const val = parseFloat(item.el.value);
          if (!isNaN(val) && val > 0) {
            const pmt = calcAmortization(val, interestRate, loanTerm, paymentFrequency);
            const pmtMonthly = calcAmortization(val, interestRate, loanTerm, 'monthly');
            const pmtBiweekly = calcAmortization(val, interestRate, loanTerm, 'biweekly');
            activeCapOptions.push({
              term: item.term,
              price: val,
              payment: pmt,
              paymentMonthly: pmtMonthly,
              paymentBiweekly: pmtBiweekly,
              perDay: pmtMonthly / 30.41,
            });
          }
        });

        // Ensure selected term is valid
        if (activeCapOptions.length > 0) {
          const hasSelected = activeCapOptions.some(o => o.term === selectedCapTerm);
          if (!hasSelected) {
            selectedCapTerm = activeCapOptions[0].term;
          }
        } else {
          selectedCapTerm = 0;
        }

        // Render CAP Term Cards
        const termsContainer = document.getElementById('cap-terms-cards');
        termsContainer.innerHTML = '';

        if (activeCapOptions.length === 0) {
          termsContainer.innerHTML = '<div style="color: #64748b; font-style: italic; padding: 1rem 0;">No CAP prices entered. Enter a price in the setup panel above to view customer terms.</div>';
        } else {
          activeCapOptions.forEach(opt => {
            const isSel = opt.term === selectedCapTerm;
            const card = document.createElement('div');
            card.className = `cap-term-card ${isSel ? 'selected' : ''}`;
            const years = opt.term / 12;
            const termLabel = `${opt.term} Months (${years} Year${years > 1 ? 's' : ''})`;

            card.innerHTML = `
              <div class="cap-term-badge">${isSel ? 'SELECTED' : 'OFFERED'}</div>
              <div style="font-weight: 700; color: #0f172a; font-size: 1.05rem; margin-bottom: 0.25rem;">${termLabel}</div>
              <div style="font-size: 0.8rem; color: #64748b; margin-bottom: 0.75rem;">Retail Price: ${fmt(opt.price)}</div>
              <div style="font-size: 1.5rem; font-weight: 800; color: var(--cap-green); margin-bottom: 0.25rem;">
                ${fmtDec(opt.payment)}<span style="font-size: 0.85rem; font-weight: 600; color: #64748b;">${freqSuffix}</span>
              </div>
              <div style="font-size: 0.75rem; color: #64748b;">Just ${fmtDec(opt.perDay)}/day</div>
            `;

            card.addEventListener('click', () => {
              selectedCapTerm = opt.term;
              recalculate();
            });

            termsContainer.appendChild(card);
          });
        }

        // Update Selected CAP Card & Combined Card
        const currentCap = activeCapOptions.find(o => o.term === selectedCapTerm);
        if (currentCap) {
          const capPmt = currentCap.payment;
          const combinedPmt = mpiPeriod26 + capPmt;
          const capYears = currentCap.term / 12;

          document.getElementById('disp-cap-amount').innerHTML = `${fmtDec(capPmt)}<span class="period">${freqSuffix}</span>`;
          document.getElementById('disp-cap-term-label').textContent = `${currentCap.term}-Month (${capYears}-Year)`;
          document.getElementById('disp-cap-per-day').textContent = `${fmtDec(currentCap.perDay)}/day`;

          document.getElementById('disp-combined-amount').innerHTML = `${fmtDec(combinedPmt)}<span class="period">${freqSuffix}</span>`;
        } else {
          document.getElementById('disp-cap-amount').innerHTML = `$0<span class="period">${freqSuffix}</span>`;
          document.getElementById('disp-cap-term-label').textContent = `No term selected`;
          document.getElementById('disp-cap-per-day').textContent = `$0/day`;
          document.getElementById('disp-combined-amount').innerHTML = `${fmtDec(mpiPeriod26)}<span class="period">${freqSuffix}</span>`;
        }
      }

      // Initial run
      recalculate();
    });
  </script>
</body>
</html>
