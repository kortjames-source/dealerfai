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

// Vehicle sale price rule: luxury vehicles over $75,000 are restricted to a max 5-year (60 mo) term
$isOver75k = ($prefillSalePrice > 75000);
$initialMaxYears = $isOver75k ? 5 : 7;
$initialMaxTermMonths = $isOver75k ? 60 : 84;
$initialMpiPayout = (int)(round(($prefillSalePrice * 0.61176) / 1000) * 1000);
$initialCapTopUp = (int)($prefillSalePrice - $initialMpiPayout);
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

    /* Deductible Strategy & Comparison Section */
    .strategy-card {
      background: #ffffff;
      border-radius: var(--radius-lg);
      border: 1px solid var(--border-color);
      padding: 2rem;
      box-shadow: var(--shadow-sm);
      margin-bottom: 2.5rem;
    }

    .strategy-header {
      display: flex;
      flex-wrap: wrap;
      justify-content: space-between;
      align-items: center;
      gap: 1rem;
      border-bottom: 1px solid #e2e8f0;
      padding-bottom: 1.25rem;
      margin-bottom: 1.5rem;
    }

    .strategy-table {
      width: 100%;
      border-collapse: collapse;
      margin-bottom: 1.5rem;
      font-size: 0.9rem;
    }

    .strategy-table th {
      text-align: left;
      padding: 0.85rem 1rem;
      background: #f8fafc;
      font-weight: 700;
      color: #334155;
      border-bottom: 2px solid #cbd5e1;
      font-size: 0.8rem;
      text-transform: uppercase;
      letter-spacing: 0.025em;
    }

    .strategy-table td {
      padding: 0.85rem 1rem;
      border-bottom: 1px solid #e2e8f0;
      color: #1e293b;
      vertical-align: middle;
    }

    .strategy-table tr:hover td {
      background: #f8fafc;
    }

    .badge-win {
      display: inline-flex;
      align-items: center;
      gap: 0.35rem;
      background: #ecfdf5;
      color: #047857;
      border: 1px solid #a7f3d0;
      padding: 0.25rem 0.6rem;
      border-radius: 9999px;
      font-size: 0.8rem;
      font-weight: 700;
    }

    .badge-mpi-warn {
      display: inline-flex;
      align-items: center;
      gap: 0.35rem;
      background: #fef2f2;
      color: #b91c1c;
      border: 1px solid #fecaca;
      padding: 0.25rem 0.6rem;
      border-radius: 9999px;
      font-size: 0.8rem;
      font-weight: 600;
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
                MPI Manitoba Public Insurance & Companion Asset Protection (CAP) Analysis
              </div>
            </div>
            <div id="disp-dsr-badge" class="dsr-pill">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
              <span id="disp-dsr-text">Level 0 • Base Rate (0% Discount)</span>
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
                      <td>Deductible ($200 Option Buy-Down Fee)</td>
                      <td><input type="number" class="form-control-sm mpi-input" data-col="2026" id="mpi-26-deductible" value="238"></td>
                      <td><input type="number" class="form-control-sm mpi-input" data-col="2025" id="mpi-25-deductible" value="117"></td>
                    </tr>
                    <tr>
                      <td>Third Party Liability</td>
                      <td><input type="number" class="form-control-sm mpi-input" data-col="2026" id="mpi-26-tpl" value="11"></td>
                      <td><input type="number" class="form-control-sm mpi-input" data-col="2025" id="mpi-25-tpl" value="10"></td>
                    </tr>
                    <tr>
                      <td>Loss of Use - Passenger Vehicle (Rental Car)</td>
                      <td><input type="number" class="form-control-sm mpi-input" data-col="2026" id="mpi-26-lossuse" value="143"></td>
                      <td><input type="number" class="form-control-sm mpi-input" data-col="2025" id="mpi-25-lossuse" value="136"></td>
                    </tr>
                    <tr>
                      <td>New/Leased Vehicle Protection (2-Year Max)</td>
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
              <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 0.5rem;">
                <h4 style="margin: 0; font-size: 0.95rem; font-weight: 700; color: #1e293b;">
                  Companion Asset Protection (CAP) Term Prices ($)
                </h4>
                <div id="disp-luxury-rule-badge" class="badge-tag" style="background: <?= $isOver75k ? '#fef3c7' : '#ecfdf5' ?>; color: <?= $isOver75k ? '#92400e' : '#047857' ?>; border: 1px solid <?= $isOver75k ? '#fde68a' : '#a7f3d0' ?>; font-size: 0.75rem; padding: 0.2rem 0.5rem; border-radius: 4px;">
                  <?= $isOver75k ? '⚠️ Over $75,000 Rule: Max 5-Year Term (60 Mo)' : 'Standard Rule: Up to 7-Year Term Allowed' ?>
                </div>
              </div>
              <p style="font-size: 0.8rem; color: #64748b; margin-bottom: 1rem;" id="disp-luxury-rule-note">
                <?= $isOver75k 
                  ? 'Vehicles with a sale price over $75,000 are restricted to a maximum of 5 years (60 months) coverage. Terms beyond 60 months are disabled.' 
                  : 'Enter prices only for the terms you wish to offer. Terms left blank will automatically be hidden on the customer view.' ?>
              </p>
              <div class="grid-3col" style="grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));">
                <div class="form-group">
                  <label for="cap-price-36">36 Months (3 Years)</label>
                  <input type="number" id="cap-price-36" class="form-control-sm cap-input" placeholder="Optional" data-term="36">
                </div>
                <div class="form-group">
                  <label for="cap-price-48">48 Months (4 Years)</label>
                  <input type="number" id="cap-price-48" class="form-control-sm cap-input" placeholder="Optional" data-term="48">
                </div>
                <div class="form-group">
                  <label for="cap-price-60">60 Months (5 Years)</label>
                  <input type="number" id="cap-price-60" class="form-control-sm cap-input" placeholder="e.g. 2219" value="2219" data-term="60">
                </div>
                <div class="form-group" id="group-cap-72" style="<?= $isOver75k ? 'opacity: 0.45;' : '' ?>">
                  <label for="cap-price-72" id="lbl-cap-price-72">72 Months <?= $isOver75k ? '(N/A >$75k)' : '(6 Years)' ?></label>
                  <input type="number" id="cap-price-72" class="form-control-sm cap-input" placeholder="Optional" data-term="72" <?= $isOver75k ? 'disabled' : '' ?>>
                </div>
                <div class="form-group" id="group-cap-84" style="<?= $isOver75k ? 'opacity: 0.45;' : '' ?>">
                  <label for="cap-price-84" id="lbl-cap-price-84">84 Months <?= $isOver75k ? '(N/A >$75k)' : '(7 Years)' ?></label>
                  <input type="number" id="cap-price-84" class="form-control-sm cap-input" placeholder="e.g. 2617" value="2617" data-term="84" <?= $isOver75k ? 'disabled' : '' ?>>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- ========================================================================= -->
        <!-- SECTION 1: BUILD VALUE FIRST — WHY CAP ASSET PROTECTION IS ESSENTIAL -->
        <!-- ========================================================================= -->
        <div class="replacement-showcase-card">
          <div class="showcase-header">
            <span class="badge-tag">Why Companion Asset Protection (CAP) Is Essential</span>
            <h2>When a Vehicle Is Written Off, You Have to Buy Another Car.</h2>
            <p>
              In the event of a total loss (collision, fire, theft, flood, or hail), <strong>MPI only settles for depreciated Actual Cash Value (ACV)</strong>. 
              Companion Asset Protection (CAP) provides the crucial <strong>Replacement Value Top-Up</strong> directly towards purchasing your replacement vehicle—so you get back into the same vehicle class without thousands of dollars out of pocket.
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
              <div class="pillar-title" id="disp-pillar-years-title">Guaranteed Active for Up to <?= $initialMaxYears ?> Years</div>
              <p class="pillar-desc" id="disp-pillar-years-desc">
                Covers New or Pre-Owned vehicles for up to <?= $initialMaxYears ?> years<?= $isOver75k ? ' (up to 5 years for vehicles over $75,000)' : '' ?>. Remains active regardless of your driving experience, claims, or losses.
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
                Provides up to $500 deductible coverage on a total loss, and $250 on partial loss claims (repairable damage including body shop repairs &amp; windshield glass replacements).
              </p>
            </div>
          </div>

          <!-- Total Loss Scenario Side-by-Side -->
          <div class="scenario-box">
            <h4 style="margin: 0 0 0.5rem 0; font-size: 1.15rem; font-weight: 700; color: #ffffff;">
              Total Loss Write-Off Reality: What Happens Without vs. With CAP?
            </h4>
            <p style="margin: 0 0 1rem 0; font-size: 0.85rem; color: rgba(255, 255, 255, 0.75);">
              Example based on a <span id="disp-scenario-veh-price">$<?= number_format($prefillSalePrice) ?></span> vehicle written off in Year 3 with an outstanding balance or replacement need:
            </p>

            <div class="scenario-grid">
              <div class="scenario-column mpi">
                <div class="scenario-title" style="color: #fca5a5;">
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                  <span>MPI Base Alone (Without CAP)</span>
                </div>
                <div class="scenario-row">
                  <span>MPI Payout (Depreciated ACV)</span>
                  <strong id="disp-scen-mpi-payout">~$<?= number_format($initialMpiPayout) ?></strong>
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
                  <span id="disp-scen-mpi-loss">-$<?= number_format($initialCapTopUp) ?> Deprec. Shortfall</span>
                </div>
              </div>

              <div class="scenario-column cap">
                <div class="scenario-title" style="color: #6ee7b7;">
                  <svg width="18" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><polyline points="9 11 12 14 22 4"/></svg>
                  <span>With Companion Asset Protection (CAP)</span>
                </div>
                <div class="scenario-row">
                  <span>MPI Base Payout</span>
                  <strong id="disp-scen-cap-mpi-payout">~$<?= number_format($initialMpiPayout) ?></strong>
                </div>
                <div class="scenario-row">
                  <span>CAP Replacement Credit Top-Up</span>
                  <span style="color: #6ee7b7; font-weight: 700;" id="disp-scen-cap-topup">+$<?= number_format($initialCapTopUp) ?> Direct Credit</span>
                </div>
                <div class="scenario-row">
                  <span>Total Buying Power for Next Car</span>
                  <strong style="color: #6ee7b7;" id="disp-scen-total-power">$<?= number_format($prefillSalePrice) ?> (100% Value)</strong>
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
        <!-- SECTION 2: PRICING & FINANCIAL VALUE — EXECUTIVE COMPARISON -->
        <!-- ========================================================================= -->
        <div class="comparison-grid">
          <!-- Card 1: MPI Add-On Costs (New Car + Loss of Use) -->
          <div class="hero-card">
            <div class="hero-card-tag" style="color: var(--mpi-blue);">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
              <span>MPI Add-Ons (New Car + Rental)</span>
            </div>
            <div class="hero-amount" id="disp-mpi-addons-amount" style="color: var(--mpi-blue);">
              $44<span class="period">.58/mo</span>
            </div>
            <div class="hero-card-subtext">
              MPI New Vehicle Protection: <strong id="disp-mpi-newveh-sub">$392/yr</strong><br>
              MPI Loss of Use (Rental Car): <strong id="disp-mpi-lossuse-sub">$143/yr</strong><br>
              Combined MPI Add-On Cost: <strong id="disp-mpi-addons-annual">$535/yr</strong>
            </div>
            <div class="increase-badge" style="background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1;">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
              <span>Expires after 1–2 Years • $0 Deductible Reimbursed</span>
            </div>
          </div>

          <!-- Card 2: Companion Asset Protection (CAP) -->
          <div class="hero-card highlight">
            <div class="hero-card-tag" style="color: var(--cap-green);">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>
              <span>Companion Asset Protection (CAP)</span>
            </div>
            <div class="hero-amount" id="disp-cap-amount" style="color: var(--cap-green);">
              $44<span class="period">.90/mo</span>
            </div>
            <div class="hero-card-subtext">
              <strong id="disp-cap-term-label">60-Month (5-Year)</strong> Replacement Protection<br>
              Includes: <strong>Up to $60,000 Top-Up + 30 Days Rental</strong><br>
              Daily Cost: Just <strong id="disp-cap-per-day">$1.47/day</strong> for peace of mind.
            </div>
            <div class="fixed-badge">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
              <span id="disp-card-cap-badge">Up to <?= $initialMaxYears ?> Years Locked • Reimburses Deductible</span>
            </div>
          </div>

          <!-- Card 3: Deductible Strategy ($500 MPI Deductible + CAP) -->
          <div class="hero-card combined">
            <div class="hero-card-tag" style="color: var(--brand-color);">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
              <span>Smart Deductible Strategy</span>
            </div>
            <div class="hero-amount" id="disp-net-cap-amount" style="color: var(--brand-color);">
              $25<span class="period">.07/mo net</span>
            </div>
            <div class="hero-card-subtext">
              Select <strong>$500 MPI Deductible</strong> & save <strong id="disp-ded-savings-sub">$238/yr ($19.83/mo)</strong>.<br>
              In a Total Loss: CAP pays $500 &rarr; <strong>$0 Out of Pocket</strong>!<br>
              In a Partial Loss (repairs &amp; windshields): CAP pays $250 &rarr; <strong>Only $250 Out of Pocket</strong>!
            </div>
            <div class="fixed-badge" style="background: #e0f2fe; color: #0369a1;">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
              <span id="disp-strategy-badge-text">Save $238/yr on MPI + $0 Deductible on Write-off</span>
            </div>
          </div>
        </div>

        <!-- ========================================================================= -->
        <!-- SECTION: SMART DEDUCTIBLE COMPARISON ($500 DEDUCTIBLE + CAP) -->
        <!-- ========================================================================= -->
        <div class="strategy-card">
          <div class="strategy-header">
            <div>
              <h3 style="margin: 0; font-size: 1.35rem; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 0.5rem;">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="var(--brand-color)" stroke-width="2.5"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                The Smart Deductible Strategy: $500 MPI Deductible with CAP
              </h3>
              <p style="margin: 0.35rem 0 0 0; color: #64748b; font-size: 0.875rem;">
                Why pay MPI extra every year for a $200 deductible when Companion Asset Protection (CAP) covers your deductible for you?
              </p>
            </div>
            <div style="text-align: right;">
              <div style="font-size: 0.75rem; text-transform: uppercase; color: #64748b; font-weight: 700;">Annual MPI Savings by Choosing $500 Deductible</div>
              <div style="font-size: 1.5rem; font-weight: 800; color: #059669;" id="disp-ded-savings-headline">Save $238 / year</div>
            </div>
          </div>

          <div style="overflow-x: auto;">
            <table class="strategy-table">
              <thead>
                <tr>
                  <th style="width: 28%;">Protection Feature / Scenario</th>
                  <th style="width: 24%; color: var(--mpi-blue);">MPI with $200 Deductible Buy-Down</th>
                  <th style="width: 28%; color: var(--cap-green);">MPI $500 Deductible + Companion Asset Protection (CAP)</th>
                  <th style="width: 20%;">Your Advantage</th>
                </tr>
              </thead>
              <tbody>
                <tr>
                  <td><strong>Annual MPI Deductible Fee</strong></td>
                  <td>
                    <span style="font-weight: 700; color: #b91c1c;" id="table-mpi-ded-fee">+$238.00 / year</span><br>
                    <span style="font-size: 0.8rem; color: #64748b;" id="table-mpi-ded-period">(+$19.83 / month)</span>
                  </td>
                  <td>
                    <span style="font-weight: 700; color: #059669;">$0.00 Extra Fee</span><br>
                    <span style="font-size: 0.8rem; color: #64748b;">(Standard $500 deductible)</span>
                  </td>
                  <td>
                    <span class="badge-win" id="table-ded-savings-pill">Save $238 / year on MPI</span>
                  </td>
                </tr>
                <tr>
                  <td><strong>Total Loss (Vehicle Written Off)</strong><br><span style="font-size: 0.8rem; color: #64748b;">Collision, Fire, Theft, Hail, or Flood</span></td>
                  <td>
                    <span style="font-weight: 700; color: #0f172a;">Client Pays $200</span><br>
                    <span style="font-size: 0.8rem; color: #64748b;">Out-of-pocket deductible</span>
                  </td>
                  <td>
                    <span style="font-weight: 700; color: #059669;">Client Pays $0.00</span><br>
                    <span style="font-size: 0.8rem; color: #059669;">CAP reimburses up to $500 deductible</span>
                  </td>
                  <td>
                    <span class="badge-win">CAP Saves You $200!</span>
                  </td>
                </tr>
                <tr>
                  <td>
                    <strong>Partial Loss (Repairable Claims)</strong><br>
                    <span style="font-size: 0.8rem; color: #64748b;">Collisions, body shop repairs &amp; <strong>windshield replacements</strong></span>
                  </td>
                  <td>
                    <span style="font-weight: 700; color: #0f172a;">Client Pays $200</span><br>
                    <span style="font-size: 0.8rem; color: #64748b;">Out-of-pocket deductible</span>
                  </td>
                  <td>
                    <span style="font-weight: 700; color: #0f172a;">Client Pays $250</span><br>
                    <span style="font-size: 0.8rem; color: #059669;">$500 MPI minus $250 CAP reimbursement (includes windshield claims)</span>
                  </td>
                  <td>
                    <span style="font-size: 0.85rem; color: #475569;"><strong>Only $50 difference</strong>, while saving <strong id="table-ded-savings-sub">$238</strong> every single year!</span>
                  </td>
                </tr>
                <tr>
                  <td><strong>Replacement Value Top-Up</strong><br><span style="font-size: 0.8rem; color: #64748b;">Credit towards your replacement car</span></td>
                  <td>
                    <span style="font-weight: 700; color: #b91c1c;">$0.00 from MPI</span><br>
                    <span style="font-size: 0.8rem; color: #64748b;">MPI pays depreciated ACV only</span>
                  </td>
                  <td>
                    <span style="font-weight: 700; color: #059669;">Up to $60,000 Saved</span><br>
                    <span style="font-size: 0.8rem; color: #64748b;">Replaces same like, kind, model & trim</span>
                  </td>
                  <td>
                    <span class="badge-win">Full Equity Top-Up</span>
                  </td>
                </tr>
                <tr>
                  <td><strong>Rental Car Protection</strong></td>
                  <td>
                    <span style="font-size: 0.85rem; color: #64748b;">MPI Basic limits (or +$143/yr extra)</span>
                  </td>
                  <td>
                    <span style="font-weight: 700; color: #059669;">30 Full Days Included</span><br>
                    <span style="font-size: 0.8rem; color: #64748b;">Keeps you on the road</span>
                  </td>
                  <td>
                    <span class="badge-win">30 Days Rental Free</span>
                  </td>
                </tr>
                <tr>
                  <td><strong>Coverage Duration</strong></td>
                  <td>
                    <span style="font-size: 0.85rem; color: #64748b;">Subject to annual MPI rate increases</span>
                  </td>
                  <td>
                    <span style="font-weight: 700; color: #059669;" id="disp-table-cap-duration">Up to <?= $initialMaxYears ?> Years Guaranteed</span><br>
                    <span style="font-size: 0.8rem; color: #64748b;" id="disp-table-cap-duration-sub"><?= $isOver75k ? 'Max term for vehicles over $75k' : '100% Locked-in Rate' ?></span>
                  </td>
                  <td>
                    <span class="badge-win" id="disp-table-cap-extra-years"><?= max(1, $initialMaxYears - 2) ?> Extra Years of Protection</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="callout-box" style="border-left-color: #059669; background: #ecfdf5;">
            <strong style="color: #065f46;">The Financial Bottom Line:</strong>
            <span style="color: #064e3b;" id="disp-strategy-summary">
              If you pay MPI for a $200 deductible, you are paying <strong>$238.00 every year</strong>. 
              By simply keeping MPI's standard $500 deductible and choosing Companion Asset Protection (CAP), you save that <strong>$238.00/year ($19.83/month)</strong>. 
              In the event of a total loss, CAP reimburses your entire $500 deductible—leaving you with <strong>$0 out of pocket</strong>. 
              Even in a partial loss (such as a body shop repair or windshield claim), you only pay $250 out of pocket (a mere $50 difference from $200), which is paid for many times over by your annual MPI premium savings!
            </span>
          </div>
        </div>

        <!-- ========================================================================= -->
        <!-- SECTION: MPI NEW CAR + RENTAL vs COMPANION ASSET PROTECTION (CAP) -->
        <!-- ========================================================================= -->
        <div class="strategy-card">
          <div class="strategy-header">
            <div>
              <h3 style="margin: 0; font-size: 1.35rem; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 0.5rem;">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="var(--mpi-blue)" stroke-width="2.5"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                Comparing MPI Add-On Coverage vs. Companion Asset Protection (CAP)
              </h3>
              <p style="margin: 0.35rem 0 0 0; color: #64748b; font-size: 0.875rem;">
                Evaluating what MPI charges for its optional 2-year New Vehicle Protection and Loss of Use vs. <span id="disp-addon-cap-term-desc">Up to <?= $initialMaxYears ?>-Year</span> Companion Asset Protection (CAP).
              </p>
            </div>
            <div style="text-align: right;">
              <div style="font-size: 0.75rem; text-transform: uppercase; color: #64748b; font-weight: 700;">Cost Comparison</div>
              <div style="font-size: 1.35rem; font-weight: 800; color: #0f172a;" id="disp-addon-vs-cap-headline">Virtually Identical Cost</div>
            </div>
          </div>

          <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.5rem; margin-bottom: 1.5rem;">
            <!-- Box 1: MPI Add-Ons Cost -->
            <div style="background: #f8fafc; border: 1px solid #cbd5e1; border-radius: var(--radius-md); padding: 1.25rem;">
              <div style="font-size: 0.75rem; font-weight: 700; color: var(--mpi-blue); text-transform: uppercase;">MPI Optional Add-Ons</div>
              <div style="font-size: 1.75rem; font-weight: 800; color: #0f172a; margin-top: 0.25rem;" id="disp-box-mpi-addons-period">$44.58 / mo</div>
              <div style="font-size: 0.85rem; color: #64748b; margin-top: 0.25rem;" id="disp-box-mpi-addons-annual">$535.00 / year ($20.58 bi-weekly)</div>
              <ul style="margin: 0.75rem 0 0 0; padding-left: 1.2rem; font-size: 0.85rem; color: #475569; line-height: 1.6;">
                <li>New/Leased Vehicle Protection: <strong id="disp-box-mpi-newveh">$392/yr</strong></li>
                <li>Loss of Use (Rental Car): <strong id="disp-box-mpi-lossuse">$143/yr</strong></li>
                <li><span style="color: #dc2626; font-weight: 600;">Coverage terminates after 24 months</span></li>
                <li>Settles depreciated ACV; no replacement credit</li>
                <li>$0 deductible reimbursement</li>
              </ul>
            </div>

            <!-- Box 2: CAP Protection Cost -->
            <div style="background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: var(--radius-md); padding: 1.25rem;">
              <div style="font-size: 0.75rem; font-weight: 700; color: var(--cap-green); text-transform: uppercase;">Companion Asset Protection (CAP)</div>
              <div style="font-size: 1.75rem; font-weight: 800; color: var(--cap-green); margin-top: 0.25rem;" id="disp-box-cap-period">$44.90 / mo</div>
              <div style="font-size: 0.85rem; color: #047857; margin-top: 0.25rem;" id="disp-box-cap-annual">$2,219 financed ($20.72 bi-weekly)</div>
              <ul style="margin: 0.75rem 0 0 0; padding-left: 1.2rem; font-size: 0.85rem; color: #065f46; line-height: 1.6;">
                <li id="disp-box-cap-years-bullet"><strong>Up to <?= $initialMaxYears ?> Years (<?= $initialMaxTermMonths ?> Months)</strong> Guaranteed Coverage</li>
                <li><strong>Up to $60,000 Replacement Value Credit</strong> to buy next car</li>
                <li><strong>30-Day Rental Vehicle</strong> included</li>
                <li><strong>Up to $500 Deductible Reimbursed</strong> ($250 on repairs &amp; windshields)</li>
                <li>100% Rate Lock Guarantee (No annual rate hikes)</li>
              </ul>
            </div>

            <!-- Box 3: Total MPI Optionals vs CAP -->
            <div style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: var(--radius-md); padding: 1.25rem;">
              <div style="font-size: 0.75rem; font-weight: 700; color: var(--brand-color); text-transform: uppercase;">All MPI Options (Incl. $200 Ded)</div>
              <div style="font-size: 1.75rem; font-weight: 800; color: var(--brand-color); margin-top: 0.25rem;" id="disp-box-mpi-all-period">$64.42 / mo</div>
              <div style="font-size: 0.85rem; color: #1e40af; margin-top: 0.25rem;" id="disp-box-mpi-all-annual">$773.00 / year ($29.73 bi-weekly)</div>
              <ul style="margin: 0.75rem 0 0 0; padding-left: 1.2rem; font-size: 0.85rem; color: #1e3a8a; line-height: 1.6;">
                <li>$200 Deductible Buy-Down: <strong id="disp-box-mpi-ded">$238/yr</strong></li>
                <li>New Vehicle Protection: <strong id="disp-box-mpi-newveh2">$392/yr</strong></li>
                <li>Loss of Use Rental: <strong id="disp-box-mpi-lossuse2">$143/yr</strong></li>
                <li><strong>By switching to $500 MPI + CAP:</strong> You save <strong id="disp-box-net-savings" style="color: #059669;">$19.52 / month</strong> while gaining <span id="disp-box-all-years">up to <?= $initialMaxYears ?> years</span> of full coverage!</li>
              </ul>
            </div>
          </div>
        </div>

        <!-- ========================================================================= -->
        <!-- SECTION 3: CAP AVAILABLE TERMS & PAYMENT OPTIONS -->
        <!-- ========================================================================= -->
        <div class="cap-terms-container" id="cap-terms-section">
          <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
            <div>
              <h3 style="margin: 0; font-size: 1.25rem; font-weight: 700; color: #0f172a;">
                Choose Your Companion Asset Protection (CAP) Term
              </h3>
              <p style="margin: 0.25rem 0 0 0; color: #64748b; font-size: 0.85rem;" id="disp-terms-subtext">
                <?= $isOver75k 
                  ? 'Vehicles over $75,000 qualify for terms up to 60 months (5 years). Select your preferred term below:' 
                  : 'Select your preferred coverage term below (up to 84 months / 7 years) to update the monthly and bi-weekly payment comparison.' ?>
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

        <!-- ========================================================================= -->
        <!-- REFERENCE SECTION: MPI 2025 vs 2026 RATE INCREASE REFERENCE -->
        <!-- ========================================================================= -->
        <div class="rate-increase-card" style="margin-top: 2rem;">
          <div class="rate-increase-header">
            <div>
              <h3>
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2.5"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
                Reference: MPI Total Rate Increase (2025 vs. 2026)
              </h3>
              <p style="margin: 0.35rem 0 0 0; color: #64748b; font-size: 0.875rem;">
                Official comparison based on Public Utilities Board (PUB) approved Manitoba Public Insurance rates.
              </p>
            </div>
            <div style="text-align: right;">
              <div style="font-size: 0.75rem; text-transform: uppercase; color: #64748b; font-weight: 700;">MPI Year-over-Year Increase</div>
              <div style="font-size: 1.5rem; font-weight: 800; color: #dc2626;" id="disp-increase-headline">+$582/year (+15.1%)</div>
            </div>
          </div>

          <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 1.5rem; margin-bottom: 1.5rem;">
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: var(--radius-md); padding: 1.25rem;">
              <div style="font-size: 0.75rem; font-weight: 700; color: #64748b; text-transform: uppercase;">2025 Total MPI Cost</div>
              <div style="font-size: 1.5rem; font-weight: 800; color: #0f172a; margin-top: 0.25rem;" id="disp-2025-cost">$3,855 / yr</div>
              <div style="font-size: 0.85rem; color: #64748b; margin-top: 0.25rem;" id="disp-2025-period-cost">$321.25 / month ($148.27 bi-weekly)</div>
            </div>
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: var(--radius-md); padding: 1.25rem;">
              <div style="font-size: 0.75rem; font-weight: 700; color: #64748b; text-transform: uppercase;">2026 Total MPI Cost</div>
              <div style="font-size: 1.5rem; font-weight: 800; color: #0f172a; margin-top: 0.25rem;" id="disp-2026-cost">$4,437 / yr</div>
              <div style="font-size: 0.85rem; color: #64748b; margin-top: 0.25rem;" id="disp-2026-period-cost">$369.75 / month ($170.65 bi-weekly)</div>
            </div>
            <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: var(--radius-md); padding: 1.25rem;">
              <div style="font-size: 0.75rem; font-weight: 700; color: #dc2626; text-transform: uppercase;">MPI Increase Amount</div>
              <div style="font-size: 1.5rem; font-weight: 800; color: #dc2626; margin-top: 0.25rem;" id="disp-increase-cost">+$582 / yr</div>
              <div style="font-size: 0.85rem; color: #991b1b; margin-top: 0.25rem;" id="disp-increase-period-cost">+$48.50 / month (+$22.38 bi-weekly)</div>
            </div>
          </div>

          <div class="callout-box">
            <strong>Key Takeaway:</strong>
            While basic MPI rates increased by <strong>+15.1%</strong> across Manitoba, Companion Asset Protection (CAP) provides a <strong>100% Rate Lock Guarantee</strong> for your entire term (<span id="disp-ratelock-years">up to <?= $initialMaxYears ?> years</span>). By choosing the $500 MPI deductible and pairing it with CAP, you mitigate rate increases and protect yourself against depreciation.
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
      // Driver Safety Rating (DSR) Scale: Level 0 to +20 (Level 0 Base default)
      const DSR_SCALE = [
        { level: 0,  discount: 0,  driverFee: 55, isBase: true },
        { level: 1,  discount: 5,  driverFee: 50 },
        { level: 2,  discount: 11, driverFee: 45 },
        { level: 3,  discount: 15, driverFee: 45 },
        { level: 4,  discount: 20, driverFee: 40 },
        { level: 5,  discount: 22, driverFee: 40 },
        { level: 6,  discount: 26, driverFee: 40 },
        { level: 7,  discount: 29, driverFee: 40 },
        { level: 8,  discount: 30, driverFee: 40 },
        { level: 9,  discount: 33, driverFee: 35 },
        { level: 10, discount: 35, driverFee: 30 },
        { level: 11, discount: 37, driverFee: 30 },
        { level: 12, discount: 40, driverFee: 30 },
        { level: 13, discount: 41, driverFee: 30 },
        { level: 14, discount: 43, driverFee: 30 },
        { level: 15, discount: 47, driverFee: 25 },
        { level: 16, discount: 49, driverFee: 25 },
        { level: 17, discount: 50, driverFee: 25 },
        { level: 18, discount: 52, driverFee: 25 },
        { level: 19, discount: 53, driverFee: 25 },
        { level: 20, discount: 53, driverFee: 25, isNew: true },
      ];

      // Populate DSR Select dropdown (Level 0 Base selected by default)
      const dsrSelect = document.getElementById('inp-dsr-level');
      if (dsrSelect) {
        DSR_SCALE.forEach(item => {
          const opt = document.createElement('option');
          opt.value = item.level;
          const labelPrefix = item.level > 0 ? `+${item.level}` : '0 (Base)';
          const tag = item.isNew ? ' [NEW 2026]' : '';
          opt.textContent = `Level ${labelPrefix} — ${item.discount}% Vehicle Discount (Driver Fee $${item.driverFee})${tag}`;
          if (item.level === 0) opt.selected = true;
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
          if (dsrSelect) dsrSelect.value = "0";
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

        // DSR Badge update (Base 0 default)
        const dsrLvl = parseInt(document.getElementById('inp-dsr-level').value, 10) || 0;
        const dsrObj = DSR_SCALE.find(d => d.level === dsrLvl) || { level: 0, discount: 0, driverFee: 55 };
        const dsrSign = dsrObj.level > 0 ? `+${dsrObj.level}` : '0';
        const dsrDiscText = dsrObj.level > 0 ? `${dsrObj.discount}% Safe Driver Vehicle Discount` : 'Base Rate (0% Discount)';
        document.getElementById('disp-dsr-text').textContent = `Level ${dsrSign} • ${dsrDiscText}`;
        const inlineDsr = document.getElementById('disp-dsr-inline');
        if (inlineDsr) inlineDsr.textContent = dsrSign;

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

        // Frequency divisor & suffix
        const freqSuffix = paymentFrequency === 'biweekly' ? '/bi-wk' : '/mo';
        const periodDivisor = paymentFrequency === 'biweekly' ? 26 : 12;

        // MPI Add-Ons (New Vehicle Protection + Loss of Use)
        const mpiAddonsAnnual26 = newveh26 + loss26;
        const mpiAddonsPeriod26 = mpiAddonsAnnual26 / periodDivisor;

        // Deductible Savings (Difference between $200 buy-down fee and standard $500 deductible)
        const dedSavingsAnnual26 = ded26; // e.g. $238
        const dedSavingsPeriod26 = dedSavingsAnnual26 / periodDivisor;

        // Total MPI Optionals ($200 Deductible buy-down + New Vehicle + Loss of Use)
        const mpiAllOptionalsAnnual26 = mpiAddonsAnnual26 + dedSavingsAnnual26;
        const mpiAllOptionalsPeriod26 = mpiAllOptionalsAnnual26 / periodDivisor;

        // Update Card 1: MPI Add-On Protection (New Car + Loss of Use)
        const elAddonsAmt = document.getElementById('disp-mpi-addons-amount');
        if (elAddonsAmt) elAddonsAmt.innerHTML = `${fmtDec(mpiAddonsPeriod26)}<span class="period">${freqSuffix}</span>`;

        const elNewvehSub = document.getElementById('disp-mpi-newveh-sub');
        if (elNewvehSub) elNewvehSub.textContent = `${fmt(newveh26)}/yr (${fmtDec(newveh26 / periodDivisor)}${freqSuffix})`;

        const elLossSub = document.getElementById('disp-mpi-lossuse-sub');
        if (elLossSub) elLossSub.textContent = `${fmt(loss26)}/yr (${fmtDec(loss26 / periodDivisor)}${freqSuffix})`;

        const elAddonsAnnual = document.getElementById('disp-mpi-addons-annual');
        if (elAddonsAnnual) elAddonsAnnual.textContent = `${fmt(mpiAddonsAnnual26)}/yr`;

        // Vehicle Sale Price and Over $75,000 Luxury Rule
        const vehPrice = parseFloat(document.getElementById('inp-veh-price').value) || 0;
        const isLuxuryOrOver75k = vehPrice > 75000;
        const maxAllowedYears = isLuxuryOrOver75k ? 5 : 7;
        const maxAllowedTermMonths = isLuxuryOrOver75k ? 60 : 84;
        const maxYearsText = `Up to ${maxAllowedYears} Years`;

        // Update Manager Drawer CAP Inputs & Badges for Luxury Rule
        const inp72 = document.getElementById('cap-price-72');
        const inp84 = document.getElementById('cap-price-84');
        const grp72 = document.getElementById('group-cap-72');
        const grp84 = document.getElementById('group-cap-84');
        const lbl72 = document.getElementById('lbl-cap-price-72');
        const lbl84 = document.getElementById('lbl-cap-price-84');
        const luxuryBadge = document.getElementById('disp-luxury-rule-badge');
        const luxuryNote = document.getElementById('disp-luxury-rule-note');

        if (isLuxuryOrOver75k) {
          if (inp72) inp72.disabled = true;
          if (inp84) inp84.disabled = true;
          if (grp72) grp72.style.opacity = '0.45';
          if (grp84) grp84.style.opacity = '0.45';
          if (lbl72) lbl72.textContent = '72 Months (N/A >$75k)';
          if (lbl84) lbl84.textContent = '84 Months (N/A >$75k)';
          if (luxuryBadge) {
            luxuryBadge.textContent = '⚠️ Over $75,000 Rule: Max 5-Year Term (60 Mo)';
            luxuryBadge.style.background = '#fef3c7';
            luxuryBadge.style.color = '#92400e';
            luxuryBadge.style.borderColor = '#fde68a';
          }
          if (luxuryNote) {
            luxuryNote.textContent = 'Vehicles with a sale price over $75,000 are restricted to a maximum of 5 years (60 months) coverage. Terms beyond 60 months are disabled.';
          }
        } else {
          if (inp72) inp72.disabled = false;
          if (inp84) inp84.disabled = false;
          if (grp72) grp72.style.opacity = '1';
          if (grp84) grp84.style.opacity = '1';
          if (lbl72) lbl72.textContent = '72 Months (6 Years)';
          if (lbl84) lbl84.textContent = '84 Months (7 Years)';
          if (luxuryBadge) {
            luxuryBadge.textContent = 'Standard Rule: Up to 7-Year Term Allowed';
            luxuryBadge.style.background = '#ecfdf5';
            luxuryBadge.style.color = '#047857';
            luxuryBadge.style.borderColor = '#a7f3d0';
          }
          if (luxuryNote) {
            luxuryNote.textContent = 'Enter prices only for the terms you wish to offer. Terms left blank will automatically be hidden on the customer view.';
          }
        }

        // Update Scenario Vehicle Price & Exact Matching Math
        const elScenVehPrice = document.getElementById('disp-scenario-veh-price');
        if (elScenVehPrice) elScenVehPrice.textContent = fmt(vehPrice);

        const scenVehPrice = vehPrice > 0 ? vehPrice : 85000;
        // Standard Year 3 depreciation: vehicle depreciates to ~61% of value
        const scenMpiPayout = Math.round((scenVehPrice * 0.61176) / 1000) * 1000;
        const scenCapTopUp = scenVehPrice - scenMpiPayout;

        const elScenMpiPayout = document.getElementById('disp-scen-mpi-payout');
        if (elScenMpiPayout) elScenMpiPayout.textContent = `~${fmt(scenMpiPayout)}`;

        const elScenMpiLoss = document.getElementById('disp-scen-mpi-loss');
        if (elScenMpiLoss) elScenMpiLoss.textContent = `-${fmt(scenCapTopUp)} Deprec. Shortfall`;

        const elScenCapMpi = document.getElementById('disp-scen-cap-mpi-payout');
        if (elScenCapMpi) elScenCapMpi.textContent = `~${fmt(scenMpiPayout)}`;

        const elScenCapTopup = document.getElementById('disp-scen-cap-topup');
        if (elScenCapTopup) elScenCapTopup.textContent = `+${fmt(scenCapTopUp)} Direct Credit`;

        const elScenTotalPower = document.getElementById('disp-scen-total-power');
        if (elScenTotalPower) elScenTotalPower.textContent = `${fmt(scenVehPrice)} (100% Value)`;

        // Update Pillar 2
        const elPillarYearsTitle = document.getElementById('disp-pillar-years-title');
        if (elPillarYearsTitle) elPillarYearsTitle.textContent = `Guaranteed Active for ${maxYearsText}`;

        const elPillarYearsDesc = document.getElementById('disp-pillar-years-desc');
        if (elPillarYearsDesc) {
          elPillarYearsDesc.textContent = isLuxuryOrOver75k
            ? `Covers New or Pre-Owned vehicles for up to 5 years (vehicles over $75,000 qualify for up to 5-year coverage). Remains active regardless of your driving experience, claims, or losses.`
            : `Covers New or Pre-Owned vehicles for up to 7 years. Remains active regardless of your driving experience, claims, or losses.`;
        }

        // Update Card 2 Badge
        const elCardCapBadge = document.getElementById('disp-card-cap-badge');
        if (elCardCapBadge) elCardCapBadge.textContent = `${maxYearsText} Locked • Reimburses Deductible`;

        // Update Strategy Table Duration Row
        const elTableDuration = document.getElementById('disp-table-cap-duration');
        if (elTableDuration) elTableDuration.textContent = `${maxYearsText} Guaranteed`;

        const elTableDurationSub = document.getElementById('disp-table-cap-duration-sub');
        if (elTableDurationSub) elTableDurationSub.textContent = isLuxuryOrOver75k ? 'Max term for vehicles over $75,000' : '100% Locked-in Rate';

        const elTableExtraYears = document.getElementById('disp-table-cap-extra-years');
        if (elTableExtraYears) {
          const extraYears = Math.max(1, maxAllowedYears - 2);
          elTableExtraYears.textContent = `${extraYears} Extra Year${extraYears > 1 ? 's' : ''} of Protection`;
        }

        // Update Add-On Comparison Section Dynamic Texts
        const elAddonDesc = document.getElementById('disp-addon-cap-term-desc');
        if (elAddonDesc) elAddonDesc.textContent = `Up to ${maxAllowedYears}-Year`;

        const elBoxYearsBullet = document.getElementById('disp-box-cap-years-bullet');
        if (elBoxYearsBullet) {
          elBoxYearsBullet.innerHTML = isLuxuryOrOver75k
            ? `<strong>Up to 5 Years (60 Months)</strong> Guaranteed Coverage`
            : `<strong>Up to 7 Years (84 Months)</strong> Guaranteed Coverage`;
        }

        const elBoxAllYears = document.getElementById('disp-box-all-years');
        if (elBoxAllYears) elBoxAllYears.textContent = `up to ${maxAllowedYears} years`;

        const elTermsSubtext = document.getElementById('disp-terms-subtext');
        if (elTermsSubtext) {
          elTermsSubtext.textContent = isLuxuryOrOver75k
            ? 'Vehicles over $75,000 qualify for terms up to 60 months (5 years). Select your preferred term below:'
            : 'Select your preferred coverage term below (up to 84 months / 7 years) to update the monthly and bi-weekly payment comparison.';
        }

        const elRateLockYears = document.getElementById('disp-ratelock-years');
        if (elRateLockYears) elRateLockYears.textContent = `up to ${maxAllowedYears} years`;

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
          if (!item.el) return;
          // Luxury rule: vehicles over $75,000 capped at 60 months (5 years)
          if (isLuxuryOrOver75k && item.term > 60) return;

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

        // Ensure selected term is valid and within luxury rule
        if (activeCapOptions.length > 0) {
          const hasSelected = activeCapOptions.some(o => o.term === selectedCapTerm);
          if (!hasSelected || (isLuxuryOrOver75k && selectedCapTerm > 60)) {
            // Pick 60 if available, else first option
            const defaultOpt = activeCapOptions.find(o => o.term === 60) || activeCapOptions[0];
            selectedCapTerm = defaultOpt.term;
          }
        } else {
          selectedCapTerm = 0;
        }

        // Render CAP Term Cards
        const termsContainer = document.getElementById('cap-terms-cards');
        if (termsContainer) {
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

            if (isLuxuryOrOver75k) {
              const luxuryInfo = document.createElement('div');
              luxuryInfo.style.cssText = 'grid-column: 1 / -1; background: #fffbeb; border: 1px solid #fde68a; border-radius: var(--radius-md); padding: 0.75rem 1rem; font-size: 0.85rem; color: #92400e; display: flex; align-items: center; gap: 0.6rem; margin-top: 0.5rem;';
              luxuryInfo.innerHTML = `
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" style="flex-shrink: 0;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <span><strong>Luxury Vehicle Policy (${fmt(vehPrice)} Sale Price):</strong> Underwriter guidelines cap vehicles with an original purchase price over $75,000 to a maximum term of 5 Years (60 Months).</span>
              `;
              termsContainer.appendChild(luxuryInfo);
            }
          }
        }

        // Selected CAP item
        const currentCap = activeCapOptions.find(o => o.term === selectedCapTerm);
        let capPmt = 0;
        let capPerDay = 0;
        let capYears = 5;
        let capTermLabel = '60-Month (5-Year)';

        if (currentCap) {
          capPmt = currentCap.payment;
          capPerDay = currentCap.perDay;
          capYears = currentCap.term / 12;
          capTermLabel = `${currentCap.term}-Month (${capYears}-Year)`;
        }

        // Update Card 2: Companion Asset Protection (CAP)
        const elCapAmt = document.getElementById('disp-cap-amount');
        if (elCapAmt) elCapAmt.innerHTML = `${fmtDec(capPmt)}<span class="period">${freqSuffix}</span>`;
        const elCapTermLabel = document.getElementById('disp-cap-term-label');
        if (elCapTermLabel) elCapTermLabel.textContent = capTermLabel;
        const elCapPerDay = document.getElementById('disp-cap-per-day');
        if (elCapPerDay) elCapPerDay.textContent = `${fmtDec(capPerDay)}/day`;

        // Update Card 3: Deductible Strategy ($500 MPI Deductible + CAP)
        const netCapPmt = Math.max(0, capPmt - dedSavingsPeriod26);
        const elNetCap = document.getElementById('disp-net-cap-amount');
        if (elNetCap) elNetCap.innerHTML = `${fmtDec(netCapPmt)}<span class="period">${freqSuffix} net</span>`;
        const elDedSavingsSub = document.getElementById('disp-ded-savings-sub');
        if (elDedSavingsSub) elDedSavingsSub.textContent = `${fmt(dedSavingsAnnual26)}/yr (${fmtDec(dedSavingsPeriod26)}${freqSuffix})`;
        const elStratBadge = document.getElementById('disp-strategy-badge-text');
        if (elStratBadge) elStratBadge.textContent = `Save ${fmt(dedSavingsAnnual26)}/yr on MPI + $0 Deductible on Write-off`;

        // Update Deductible Strategy Section
        const elDedHead = document.getElementById('disp-ded-savings-headline');
        if (elDedHead) elDedHead.textContent = `Save ${fmt(dedSavingsAnnual26)} / year`;
        const elTableDedFee = document.getElementById('table-mpi-ded-fee');
        if (elTableDedFee) elTableDedFee.textContent = `+${fmt(dedSavingsAnnual26)} / year`;
        const elTableDedPeriod = document.getElementById('table-mpi-ded-period');
        if (elTableDedPeriod) elTableDedPeriod.textContent = `(+${fmtDec(dedSavingsPeriod26)} ${freqSuffix})`;
        const elTableDedPill = document.getElementById('table-ded-savings-pill');
        if (elTableDedPill) elTableDedPill.textContent = `Save ${fmt(dedSavingsAnnual26)} / year on MPI`;
        const elTableDedSub = document.getElementById('table-ded-savings-sub');
        if (elTableDedSub) elTableDedSub.textContent = fmt(dedSavingsAnnual26);

        const elStratSummary = document.getElementById('disp-strategy-summary');
        if (elStratSummary) {
          elStratSummary.innerHTML = `
            If you pay MPI for a $200 deductible, you are paying <strong>${fmt(dedSavingsAnnual26)} extra every single year</strong> (${fmtDec(dedSavingsPeriod26)}${freqSuffix}). 
            By simply keeping MPI's standard $500 deductible and choosing Companion Asset Protection (CAP), you save that <strong>${fmt(dedSavingsAnnual26)}/year</strong> on your insurance. 
            In the event of a total loss, CAP reimburses your entire $500 deductible—leaving you with <strong>$0 out of pocket</strong> (which is $200 cheaper than paying MPI for a $200 deductible). 
            Even in a partial loss (such as a body shop repair or windshield claim), you only pay $250 out of pocket (a modest $50 difference from $200), which is paid for many times over by your <strong>${fmt(dedSavingsAnnual26)}</strong> annual premium savings!
          `;
        }

        // Update Add-Ons Comparison Section
        const elAddonHead = document.getElementById('disp-addon-vs-cap-headline');
        if (elAddonHead) {
          const diffMpiVsCap = Math.abs(mpiAddonsPeriod26 - capPmt);
          if (diffMpiVsCap < 2) {
            elAddonHead.textContent = 'Virtually Identical Cost';
          } else if (mpiAddonsPeriod26 > capPmt) {
            elAddonHead.textContent = `CAP Is ${fmtDec(mpiAddonsPeriod26 - capPmt)}/mo Cheaper!`;
          } else {
            elAddonHead.textContent = `Only ${fmtDec(capPmt - mpiAddonsPeriod26)}/mo Difference`;
          }
        }

        const elBoxMpiPeriod = document.getElementById('disp-box-mpi-addons-period');
        if (elBoxMpiPeriod) elBoxMpiPeriod.textContent = `${fmtDec(mpiAddonsPeriod26)} ${freqSuffix}`;
        const elBoxMpiAnnual = document.getElementById('disp-box-mpi-addons-annual');
        if (elBoxMpiAnnual) elBoxMpiAnnual.textContent = `${fmt(mpiAddonsAnnual26)} / year (${fmtDec(mpiAddonsPeriod26)} ${freqSuffix})`;
        const elBoxMpiNew = document.getElementById('disp-box-mpi-newveh');
        if (elBoxMpiNew) elBoxMpiNew.textContent = `${fmt(newveh26)}/yr`;
        const elBoxMpiLoss = document.getElementById('disp-box-mpi-lossuse');
        if (elBoxMpiLoss) elBoxMpiLoss.textContent = `${fmt(loss26)}/yr`;

        const elBoxCapPeriod = document.getElementById('disp-box-cap-period');
        if (elBoxCapPeriod) elBoxCapPeriod.textContent = `${fmtDec(capPmt)} ${freqSuffix}`;
        const elBoxCapAnnual = document.getElementById('disp-box-cap-annual');
        if (elBoxCapAnnual) elBoxCapAnnual.textContent = currentCap ? `${fmt(currentCap.price)} financed (${fmtDec(capPmt)} ${freqSuffix})` : '$0';

        const elBoxAllPeriod = document.getElementById('disp-box-mpi-all-period');
        if (elBoxAllPeriod) elBoxAllPeriod.textContent = `${fmtDec(mpiAllOptionalsPeriod26)} ${freqSuffix}`;
        const elBoxAllAnnual = document.getElementById('disp-box-mpi-all-annual');
        if (elBoxAllAnnual) elBoxAllAnnual.textContent = `${fmt(mpiAllOptionalsAnnual26)} / year (${fmtDec(mpiAllOptionalsPeriod26)} ${freqSuffix})`;
        const elBoxMpiDed = document.getElementById('disp-box-mpi-ded');
        if (elBoxMpiDed) elBoxMpiDed.textContent = `${fmt(ded26)}/yr`;
        const elBoxMpiNew2 = document.getElementById('disp-box-mpi-newveh2');
        if (elBoxMpiNew2) elBoxMpiNew2.textContent = `${fmt(newveh26)}/yr`;
        const elBoxMpiLoss2 = document.getElementById('disp-box-mpi-lossuse2');
        if (elBoxMpiLoss2) elBoxMpiLoss2.textContent = `${fmt(loss26)}/yr`;

        const elBoxNetSav = document.getElementById('disp-box-net-savings');
        if (elBoxNetSav) {
          const netDiff = mpiAllOptionalsPeriod26 - capPmt;
          elBoxNetSav.textContent = `${fmtDec(Math.abs(netDiff))} ${freqSuffix} ${netDiff >= 0 ? 'less' : 'more'}`;
        }

        // Update Rate Increase Reference Section
        const diffTotal = total26 - total25;
        const pctIncrease = total25 > 0 ? ((diffTotal / total25) * 100) : 0;
        const elIncHead = document.getElementById('disp-increase-headline');
        if (elIncHead) elIncHead.textContent = `+${fmt(diffTotal)}/year (+${pctIncrease.toFixed(1)}%)`;
        const el25Cost = document.getElementById('disp-2025-cost');
        if (el25Cost) el25Cost.textContent = `${fmt(total25)} / yr`;
        const el26Cost = document.getElementById('disp-2026-cost');
        if (el26Cost) el26Cost.textContent = `${fmt(total26)} / yr`;
        const elIncCost = document.getElementById('disp-increase-cost');
        if (elIncCost) elIncCost.textContent = `+${fmt(diffTotal)} / yr`;

        const el25PerCost = document.getElementById('disp-2025-period-cost');
        if (el25PerCost) el25PerCost.textContent = `${fmtDec(total25 / 12)} / month (${fmtDec(total25 / 26)} bi-weekly)`;
        const el26PerCost = document.getElementById('disp-2026-period-cost');
        if (el26PerCost) el26PerCost.textContent = `${fmtDec(total26 / 12)} / month (${fmtDec(total26 / 26)} bi-weekly)`;
        const elIncPerCost = document.getElementById('disp-increase-period-cost');
        if (elIncPerCost) elIncPerCost.textContent = `+${fmtDec(diffTotal / 12)} / month (+${fmtDec(diffTotal / 26)} bi-weekly)`;
      }

      // Initial run
      recalculate();
    });
  </script>
</body>
</html>
