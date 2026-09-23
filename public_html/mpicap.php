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
$prefillVehYear = 2026;
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
            if (!empty($deal['vehicle_year']) && (int)$deal['vehicle_year'] >= 1980) {
                $prefillVehYear = (int)$deal['vehicle_year'];
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

// Fallback year extraction from vehicle name string if deal year was not set
if ($prefillVehYear === 2026 && preg_match('/\b(19\d\d|20\d\d)\b/', $prefillVehicle, $m)) {
    $prefillVehYear = (int)$m[1];
}

// Vehicle sale price rule: luxury vehicles over $75,000 are restricted to a max 5-year (60 mo) term
$isOver75k = ($prefillSalePrice > 75000);
$initialMaxYears = $isOver75k ? 5 : 7;
$initialMaxTermMonths = $isOver75k ? 60 : 84;
// Default Claim Timing Scenario: Year 4 (~52% ACV retained / 48% depreciation loss)
$initialScenarioYear = 4;
$initialMpiPayout = (int)(round(($prefillSalePrice * 0.52) / 1000) * 1000);
$initialCapTopUp = (int)($prefillSalePrice - $initialMpiPayout);

// MPI New Vehicle Protection eligibility based on model year:
// 2+ years old (<= 2024): Ineligible ($0 from MPI / ACV only)
// 1 year old (2025): 1 year max (12 months)
// Brand New (2026+): up to 2 years (24 months)
$isVehIneligibleMpiNew = ($prefillVehYear <= 2024);
$isVehOneYearMpiNew = ($prefillVehYear === 2025);
$initialMpiNewCoverageYears = $isVehIneligibleMpiNew ? 0 : ($isVehOneYearMpiNew ? 1 : 2);
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
      border-color: #059669;
      background: linear-gradient(180deg, #ffffff 0%, rgba(16, 185, 129, 0.04) 100%);
      box-shadow: 0 10px 25px -5px rgba(16, 185, 129, 0.15);
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

    /* Rate Lock Closer Banner */
    .rate-lock-banner {
      background: linear-gradient(135deg, #ecfdf5 0%, #f0fdf4 100%);
      border: 1.5px solid #a7f3d0;
      border-radius: var(--radius-lg);
      padding: 1.75rem 2rem;
      margin-top: 2rem;
      margin-bottom: 2.5rem;
      box-shadow: var(--shadow-sm);
    }

    .rate-lock-inner {
      display: flex;
      align-items: flex-start;
      gap: 1.25rem;
    }

    .rate-lock-icon {
      width: 48px;
      height: 48px;
      border-radius: 12px;
      background: #059669;
      color: #ffffff;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
    }

    .rate-lock-pills {
      display: flex;
      flex-wrap: wrap;
      gap: 0.75rem;
      margin-top: 1.25rem;
      padding-top: 1rem;
      border-top: 1px solid #bbf7d0;
    }

    .rate-lock-pill {
      display: inline-flex;
      align-items: center;
      gap: 0.4rem;
      font-size: 0.8rem;
      font-weight: 700;
      color: #065f46;
      background: #ffffff;
      padding: 0.35rem 0.75rem;
      border-radius: 9999px;
      border: 1px solid #86efac;
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

    .scenario-year-btn {
      background: transparent;
      color: rgba(255, 255, 255, 0.85);
      border: none;
      cursor: pointer;
      padding: 4px 11px;
      font-size: 0.75rem;
      font-weight: 700;
      border-radius: 9999px;
      transition: all 0.2s ease;
    }
    .scenario-year-btn:hover {
      background: rgba(255, 255, 255, 0.2);
      color: #ffffff;
    }
    .scenario-year-btn.active {
      background: #059669;
      color: #ffffff;
      box-shadow: 0 1px 3px rgba(0, 0, 0, 0.3);
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

    .cap-term-badge.selected {
      background: var(--brand-color);
      color: #ffffff;
    }

    .cap-term-badge.match {
      background: #ecfdf5;
      color: #047857;
      border: 1px solid #a7f3d0;
    }

    .cap-relation-pill {
      font-size: 0.72rem;
      font-weight: 600;
      border-radius: 4px;
      padding: 3px 6px;
      display: inline-block;
      line-height: 1.2;
    }

    .cap-relation-pill.partial {
      color: #b45309;
      background: #fffbeb;
      border: 1px solid #fef3c7;
    }

    .cap-relation-pill.match {
      color: #047857;
      background: #ecfdf5;
      border: 1px solid #a7f3d0;
    }

    .cap-relation-pill.extended {
      color: #1d4ed8;
      background: #eff6ff;
      border: 1px solid #bfdbfe;
    }

    /* Timeline & Financing vs Protection Explainer */
    .timeline-container {
      background: #ffffff;
      border: 1px solid #e2e8f0;
      border-radius: var(--radius-md);
      padding: 1.5rem;
      margin-top: 1.5rem;
      box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    }

    .timeline-title {
      font-size: 1.05rem;
      font-weight: 700;
      color: #0f172a;
      display: flex;
      align-items: center;
      gap: 0.5rem;
      margin-bottom: 0.4rem;
    }

    .timeline-subtitle {
      font-size: 0.85rem;
      color: #64748b;
      margin-bottom: 1.25rem;
    }

    .timeline-track-wrap {
      display: flex;
      flex-direction: column;
      gap: 0.85rem;
      margin-bottom: 1.5rem;
    }

    .timeline-row {
      display: flex;
      flex-direction: column;
      gap: 0.35rem;
    }

    .timeline-label-bar {
      display: flex;
      justify-content: space-between;
      font-size: 0.8rem;
      font-weight: 700;
      color: #334155;
    }

    .timeline-track {
      height: 32px;
      background: #f1f5f9;
      border-radius: 6px;
      overflow: hidden;
      display: flex;
      position: relative;
      border: 1px solid #e2e8f0;
    }

    .timeline-fill-loan {
      background: #2563eb;
      color: #ffffff;
      height: 100%;
      display: flex;
      align-items: center;
      padding: 0 0.85rem;
      font-size: 0.78rem;
      font-weight: 700;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      width: 100%;
    }

    .timeline-fill-cap {
      background: #059669;
      color: #ffffff;
      height: 100%;
      display: flex;
      align-items: center;
      padding: 0 0.85rem;
      font-size: 0.78rem;
      font-weight: 700;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      transition: width 0.3s ease;
    }

    .timeline-fill-remaining {
      background: #f8fafc;
      color: #64748b;
      height: 100%;
      display: flex;
      align-items: center;
      padding: 0 0.75rem;
      font-size: 0.75rem;
      font-weight: 600;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      border-left: 1px dashed #cbd5e1;
    }

    .timeline-cards-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
      gap: 1.25rem;
    }

    .timeline-card-box {
      background: #f8fafc;
      border-radius: var(--radius-md);
      padding: 1.25rem;
      border: 1px solid #e2e8f0;
    }

    /* Deductible Selector Styles */
    .deductible-selector-container {
      background: #f8fafc;
      border: 1px solid #cbd5e1;
      border-radius: var(--radius-md);
      padding: 1rem 1.25rem;
      margin-bottom: 1.5rem;
    }
    .deductible-pills-wrap {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
      gap: 0.65rem;
    }
    .ded-pill-btn {
      background: #ffffff;
      border: 1.5px solid #cbd5e1;
      border-radius: var(--radius-md);
      padding: 0.65rem 0.85rem;
      text-align: left;
      cursor: pointer;
      transition: all 0.2s ease;
      font-family: inherit;
      display: flex;
      flex-direction: column;
      gap: 0.15rem;
    }
    .ded-pill-btn:hover {
      border-color: var(--brand-color);
      background: #f8fafc;
      transform: translateY(-1px);
    }
    .ded-pill-btn.active {
      border-color: var(--brand-color);
      background: #eff6ff;
      box-shadow: 0 0 0 1px var(--brand-color);
    }
    .ded-pill-top {
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    .ded-pill-val {
      font-weight: 800;
      font-size: 1.05rem;
      color: #0f172a;
    }
    .ded-pill-btn.active .ded-pill-val {
      color: var(--brand-color);
    }
    .ded-pill-badge {
      font-size: 0.65rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      padding: 2px 5px;
      border-radius: 4px;
      background: #e2e8f0;
      color: #475569;
    }
    .ded-pill-btn.active .ded-pill-badge {
      background: #dbeafe;
      color: #1e40af;
    }
    .ded-pill-fee {
      font-size: 0.82rem;
      font-weight: 700;
      color: #0f172a;
      margin-top: 0.15rem;
    }
    .ded-pill-btn.active .ded-pill-fee {
      color: var(--brand-color);
    }
    .ded-pill-mo {
      font-size: 0.74rem;
      color: #64748b;
      font-weight: 600;
    }
    .ded-pill-btn.active .ded-pill-mo {
      color: #1e40af;
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
      .loan-freq-wrapper,
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
      .rate-lock-banner,
      .strategy-card,
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
            <div id="disp-top-ded-badge" class="dsr-pill" style="background: #e0f2fe; color: #0369a1; border-color: #bae6fd; cursor: pointer;" title="Click to view Deductible Strategy">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
              <span id="disp-top-ded-text">MPI Deductible: $200 (+$19.83/mo)</span>
            </div>
          </div>

          <div style="display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap;">
            <!-- Monthly / Bi-Weekly Selector (for Loan / Lease / CAP) -->
            <div class="loan-freq-wrapper" style="display: flex; align-items: center; gap: 0.45rem;">
              <span style="font-size: 0.78rem; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.04em;">Loan/Lease:</span>
              <div class="freq-toggle-group">
                <button type="button" class="freq-btn active" id="btn-monthly">Monthly</button>
                <button type="button" class="freq-btn" id="btn-biweekly">Bi-Weekly</button>
              </div>
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
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 1rem; margin-bottom: 1rem;">
              <div class="form-group">
                <label for="inp-client-name">Client Name</label>
                <input type="text" id="inp-client-name" class="form-control-sm" value="<?= htmlspecialchars($prefillClient) ?>">
              </div>
              <div class="form-group">
                <label for="inp-vehicle-year">Vehicle Model Year</label>
                <select id="inp-vehicle-year" class="form-control-sm" style="font-weight: 600;">
                  <?php
                    $availableYears = [2027, 2026, 2025, 2024, 2023, 2022, 2021, 2020, 2019, 2018, 2017, 2016, 2015];
                    foreach ($availableYears as $yr):
                      $yrLabel = (string)$yr;
                      if ($yr >= 2026) $yrLabel .= " (New - Up to 2 Yrs MPI)";
                      elseif ($yr === 2025) $yrLabel .= " (1 Yr Old - Max 1 Yr MPI)";
                      else $yrLabel .= " (Pre-Owned - Ineligible for MPI)";
                  ?>
                    <option value="<?= $yr ?>" <?= ($prefillVehYear === $yr) ? 'selected' : '' ?>><?= $yrLabel ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group">
                <label for="inp-vehicle-name">Vehicle Description / Make / Model</label>
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

            <!-- MPI Optional Coverage Calculator Input Table -->
            <div style="margin-top: 1.5rem; border-top: 1px solid #e2e8f0; padding-top: 1.25rem;">
              <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 0.5rem;">
                <h4 style="margin: 0; font-size: 0.95rem; font-weight: 700; color: #1e293b;">
                  Manitoba Public Insurance (MPI) Quote Inputs
                </h4>
                <div style="display: flex; gap: 0.5rem; align-items: center;">
                  <span id="storage-saved-badge" style="display: none; font-size: 0.75rem; color: #059669; background: #ecfdf5; border: 1px solid #a7f3d0; padding: 0.2rem 0.5rem; border-radius: 4px; align-items: center; gap: 0.25rem;">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                    Saved Locally
                  </span>
                  <button type="button" id="btn-load-sample" class="btn btn-secondary btn-sm" style="font-size: 0.75rem; padding: 0.25rem 0.5rem;">
                    Reset to Defender Sample
                  </button>
                </div>
              </div>
              <p style="font-size: 0.8rem; color: #64748b; margin-bottom: 0.75rem;">
                Enter the exact figures pulled from the MPI online calculator for this vehicle and driver rating.
              </p>

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
                    <tr style="background: #f1f5f9;">
                      <td colspan="3" style="padding: 0.5rem 0.75rem; font-weight: 700; font-size: 0.8rem; color: #334155; text-transform: uppercase; letter-spacing: 0.04em;">
                        MPI Deductible Buy-Down Fees (from MPI Calculator)
                      </td>
                    </tr>
                    <tr>
                      <td style="padding-left: 1.25rem;">↳ <strong>$1,000 Deductible</strong> (Standard Base Rate)</td>
                      <td><span style="color: #64748b; font-weight: 600; font-size: 0.85rem;">$0.00 (Included in Basic)</span></td>
                      <td><span style="color: #64748b; font-weight: 600; font-size: 0.85rem;">$0.00 (Included in Basic)</span></td>
                    </tr>
                    <tr>
                      <td style="padding-left: 1.25rem;">↳ <strong>$750 Deductible</strong> Buy-Down Fee</td>
                      <td><input type="number" class="form-control-sm mpi-input" data-col="2026" id="mpi-26-ded-750" value="60" placeholder="e.g. 60"></td>
                      <td><input type="number" class="form-control-sm mpi-input" data-col="2025" id="mpi-25-ded-750" value="30" placeholder="e.g. 30"></td>
                    </tr>
                    <tr>
                      <td style="padding-left: 1.25rem;">↳ <strong>$500 Deductible</strong> Buy-Down Fee</td>
                      <td><input type="number" class="form-control-sm mpi-input" data-col="2026" id="mpi-26-ded-500" value="125" placeholder="e.g. 125"></td>
                      <td><input type="number" class="form-control-sm mpi-input" data-col="2025" id="mpi-25-ded-500" value="62" placeholder="e.g. 62"></td>
                    </tr>
                    <tr>
                      <td style="padding-left: 1.25rem;">↳ <strong>$300 Deductible</strong> Buy-Down Fee</td>
                      <td><input type="number" class="form-control-sm mpi-input" data-col="2026" id="mpi-26-ded-300" value="185" placeholder="e.g. 185"></td>
                      <td><input type="number" class="form-control-sm mpi-input" data-col="2025" id="mpi-25-ded-300" value="92" placeholder="e.g. 92"></td>
                    </tr>
                    <tr>
                      <td style="padding-left: 1.25rem;">↳ <strong>$200 Deductible</strong> Buy-Down Fee (Default Quote)</td>
                      <td><input type="number" class="form-control-sm mpi-input" data-col="2026" id="mpi-26-ded-200" value="238" placeholder="e.g. 238"></td>
                      <td><input type="number" class="form-control-sm mpi-input" data-col="2025" id="mpi-25-ded-200" value="117" placeholder="e.g. 117"></td>
                    </tr>
                    <input type="hidden" id="mpi-26-deductible" value="238">
                    <input type="hidden" id="mpi-25-deductible" value="117">
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
                    <tr id="row-mpi-newveh">
                      <td>
                        <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 4px;">
                          <span id="lbl-mpi-newveh-title">New/Leased Vehicle Protection</span>
                          <span id="disp-mpi-newveh-status-badge" style="font-size: 0.72rem; padding: 2px 6px; border-radius: 4px; font-weight: 700;"></span>
                        </div>
                      </td>
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
              <div class="pillar-title" id="disp-pillar-saved-title">Up to $60,000 Equity Protected</div>
              <p class="pillar-desc" id="disp-pillar-saved-desc">
                Protects you against rapid vehicle depreciation, covering up to $60,000 to replace like, kind, and model without out-of-pocket shortfall.
              </p>
            </div>

            <div class="pillar-item">
              <div class="pillar-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
              </div>
              <div class="pillar-title" id="disp-pillar-years-title">Guaranteed Coverage for Up to <?= $initialMaxYears ?> Years</div>
              <p class="pillar-desc" id="disp-pillar-years-desc">
                Covers New or Pre-Owned vehicles for up to <?= $initialMaxYears ?> years. Your protection is locked in and remains fully in effect regardless of claims or driving record.
              </p>
            </div>

            <div class="pillar-item">
              <div class="pillar-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
              </div>
              <div class="pillar-title">30-Day Rental Vehicle</div>
              <p class="pillar-desc">
                Basic MPI includes $0 rental coverage (requires purchasing optional Loss of Use). CAP includes up to 30 days of rental benefits while your replacement is arranged.
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
            <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 0.75rem; margin-bottom: 0.75rem;">
              <div>
                <h4 style="margin: 0 0 0.25rem 0; font-size: 1.15rem; font-weight: 700; color: #ffffff;">
                  Total Loss Write-Off Reality: What Happens Without vs. With CAP?
                </h4>
                <p style="margin: 0; font-size: 0.85rem; color: rgba(255, 255, 255, 0.85);" id="disp-scenario-lead-text">
                  Example based on your <strong id="disp-scenario-veh-name"><?= htmlspecialchars($prefillVehicle) ?></strong> (<strong id="disp-scenario-veh-price">$<?= number_format($prefillSalePrice) ?></strong>) written off in <strong id="disp-scenario-year-label">Year 4 (Month 48)</strong>:
                </p>
              </div>
              <div style="display: flex; align-items: center; gap: 0.5rem; background: rgba(0, 0, 0, 0.25); padding: 4px 6px; border-radius: 9999px; border: 1px solid rgba(255, 255, 255, 0.15);">
                <span style="font-size: 0.72rem; color: rgba(255, 255, 255, 0.7); text-transform: uppercase; font-weight: 700; padding-left: 6px;">Claim Timing:</span>
                <div style="display: flex; gap: 4px;" id="scenario-year-pills">
                  <button type="button" class="scenario-year-btn" data-year="3">Year 3 (~61%)</button>
                  <button type="button" class="scenario-year-btn active" data-year="4">Year 4 (~52%)</button>
                  <button type="button" class="scenario-year-btn" data-year="5">★ Year 5 (~44%)</button>
                </div>
              </div>
            </div>

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
                  <span>Rental Car Coverage</span>
                  <span style="color: #fca5a5;">$0 (Not in Basic • Client Pays)</span>
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
                  <span style="color: #6ee7b7;">30 Days Included ($0 Extra)</span>
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
        <!-- SECTION 2: CHOOSE YOUR PROTECTION TERM & PAYMENT -->
        <!-- ========================================================================= -->
        <div class="cap-terms-container" id="cap-terms-section">
          <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
            <div>
              <h3 style="margin: 0; font-size: 1.25rem; font-weight: 700; color: #0f172a;">
                Choose Your Companion Asset Protection (CAP) Term
              </h3>
              <p style="margin: 0.25rem 0 0 0; color: #64748b; font-size: 0.85rem;" id="disp-terms-subtext">
                Select your preferred coverage term below to update the monthly and bi-weekly payment comparison.
              </p>
            </div>
            <div style="font-size: 0.85rem; color: #334155; background: #f8fafc; padding: 0.5rem 0.85rem; border-radius: var(--radius-md); border: 1px solid #cbd5e1; display: flex; align-items: center; gap: 0.5rem;">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
              <span>Vehicle Loan: <strong id="disp-loan-term-badge"><?= (int)$prefillTerm ?> Months</strong> @ <strong id="disp-apr-badge"><?= (float)$prefillRate ?>% APR</strong></span>
            </div>
          </div>

          <div class="cap-terms-grid" id="cap-terms-cards">
            <!-- Dynamic Cards generated by JavaScript based on populated terms -->
          </div>

          <!-- Interactive Loan Term vs Protection Coverage Visual Breakdown -->
          <div id="cap-timeline-explainer"></div>
        </div>

        <!-- ========================================================================= -->
        <!-- SECTION 3: PRICING & FINANCIAL VALUE — EXECUTIVE COMPARISON -->
        <!-- ========================================================================= -->
        <div class="comparison-grid">
          <!-- Card 1: MPI Add-On Costs (New Car + Loss of Use) -->
          <div class="hero-card">
            <div class="hero-card-tag" style="color: var(--mpi-blue);" id="disp-mpi-addons-tag">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
              <span id="disp-mpi-addons-tag-text"><?= $isVehIneligibleMpiNew ? 'MPI Add-Ons (Rental Only • New Car Ineligible)' : 'MPI Add-Ons (New Car + Rental)' ?></span>
            </div>
            <div class="hero-amount" id="disp-mpi-addons-amount" style="color: var(--mpi-blue);">
              <?= $isVehIneligibleMpiNew ? '$11<span class="period">.92/mo</span>' : '$44<span class="period">.58/mo</span>' ?>
            </div>
            <div class="hero-card-subtext">
              MPI New Vehicle Protection: <strong id="disp-mpi-newveh-sub"><?= $isVehIneligibleMpiNew ? 'Ineligible ($0.00 from MPI)' : ($isVehOneYearMpiNew ? '$392/yr ($32.67/mo) — Max 1 Yr' : '$392/yr ($32.67/mo)') ?></strong><br>
              MPI Loss of Use (Rental Car): <strong id="disp-mpi-lossuse-sub">$143/yr ($11.92/mo)</strong><br>
              Combined MPI Add-On Cost: <strong id="disp-mpi-addons-annual"><?= $isVehIneligibleMpiNew ? '$143/yr (Rental Only)' : '$535/yr (Billed Monthly by MPI)' ?></strong>
            </div>
            <div class="increase-badge" style="background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1;">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
              <span id="disp-mpi-addons-badge"><?= $isVehIneligibleMpiNew ? '⚠️ MPI Ineligible (2+ Yrs Old) • ACV Only' : ($isVehOneYearMpiNew ? 'Billed Monthly by MPI • Max 1 Year (Expires after 12 Mo)' : 'Billed Monthly by MPI • Expires after 2 Years') ?></span>
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
              <span id="disp-card-cap-topup-text">Includes: <strong>Up to $60,000 Top-Up + 30 Days Rental</strong></span><br>
              Daily Cost: Just <strong id="disp-cap-per-day">$1.47/day</strong> for peace of mind.<br>
              <span id="disp-cap-loan-sub" style="font-size: 0.8rem; color: #475569;">Financed over your <strong><?= (int)$prefillTerm ?>-month</strong> vehicle loan.</span>
            </div>
            <div class="fixed-badge">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
              <span id="disp-card-cap-badge"><?= $isVehIneligibleMpiNew ? "Up to {$initialMaxYears} Years Locked • Pre-Owned Covered" : "Up to {$initialMaxYears} Years Locked • Reimburses Deductible" ?></span>
            </div>
          </div>

          <!-- Card 3: Deductible Savings ($500 MPI Deductible + CAP) -->
          <div class="hero-card combined">
            <div class="hero-card-tag" style="color: #059669;">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
              <span id="disp-card-strat-tag">Smart Deductible Savings</span>
            </div>
            <div class="hero-amount" id="disp-net-cap-amount" style="color: #059669;">
              Save $113<span class="period">/yr</span>
            </div>
            <div id="disp-strat-period-line" style="font-size: 0.88rem; font-weight: 700; color: #047857; margin-top: -0.25rem; margin-bottom: 0.75rem;">
              Save $9.42/mo on your MPI bill
            </div>
            <div class="hero-card-subtext" id="disp-card-strat-subtext">
              Choose <strong>$500 MPI Deductible</strong> &amp; pocket <strong>$113/yr ($9.42/mo)</strong> in MPI savings.<br>
              In a Total Loss: CAP pays $500 &rarr; <strong>$0 Out of Pocket</strong> ($200 saved)!<br>
              In a Partial Loss (repairs &amp; windshields): CAP pays $250 &rarr; <strong>Only $250 Out of Pocket</strong>!
            </div>
            <div class="fixed-badge" style="background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0;">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
              <span id="disp-strategy-badge-text">Save $113/yr on MPI + $0 Deductible on Write-off</span>
            </div>
          </div>
        </div>

        <!-- ========================================================================= -->
        <!-- SECTION: SMART DEDUCTIBLE COMPARISON ($500 DEDUCTIBLE + CAP) -->
        <!-- ========================================================================= -->
        <div class="strategy-card" id="smart-deductible-section">
          <div class="strategy-header">
            <div>
              <h3 style="margin: 0; font-size: 1.35rem; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 0.5rem;" id="disp-ded-strategy-title">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="var(--brand-color)" stroke-width="2.5"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                The Smart Deductible Strategy: $500 MPI Deductible with CAP
              </h3>
              <p style="margin: 0.35rem 0 0 0; color: #64748b; font-size: 0.875rem;" id="disp-ded-strategy-sub">
                Why pay MPI extra every year for a $200 deductible when Companion Asset Protection (CAP) covers your deductible for you?
              </p>
            </div>
            <div style="text-align: right;">
              <div style="font-size: 0.75rem; text-transform: uppercase; color: #64748b; font-weight: 700;" id="disp-ded-savings-label">Annual MPI Savings by Choosing $500 Buy-Down</div>
              <div style="font-size: 1.5rem; font-weight: 800; color: #059669;" id="disp-ded-savings-headline">Save $113 / year</div>
            </div>
          </div>

          <!-- Interactive Deductible Selector Bar -->
          <div class="deductible-selector-container">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 0.75rem;">
              <div style="font-size: 0.85rem; font-weight: 700; color: #1e293b; text-transform: uppercase; letter-spacing: 0.04em; display: flex; align-items: center; gap: 0.4rem;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--brand-color)" stroke-width="2.5"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                <span>Select MPI Deductible Option To Compare:</span>
              </div>
              <div id="disp-active-ded-badge" style="font-size: 0.78rem; font-weight: 700; color: #0369a1; background: #e0f2fe; padding: 0.25rem 0.65rem; border-radius: 4px; border: 1px solid #bae6fd;">
                Comparing: $200 Deductible (+$238/yr • +$19.83/mo)
              </div>
            </div>
            <div class="deductible-pills-wrap" id="deductible-pills-group">
              <button type="button" class="ded-pill-btn" data-ded="1000">
                <div class="ded-pill-top">
                  <span class="ded-pill-val">$1,000</span>
                  <span class="ded-pill-badge">Base Rate</span>
                </div>
                <div class="ded-pill-fee">$0 / yr</div>
                <div class="ded-pill-mo">$0.00/mo</div>
              </button>
              <button type="button" class="ded-pill-btn" data-ded="750">
                <div class="ded-pill-top">
                  <span class="ded-pill-val">$750</span>
                  <span class="ded-pill-badge">Buy-Down</span>
                </div>
                <div class="ded-pill-fee" id="pill-fee-750">+$60/yr</div>
                <div class="ded-pill-mo" id="pill-mo-750">+$5.00/mo</div>
              </button>
              <button type="button" class="ded-pill-btn" data-ded="500">
                <div class="ded-pill-top">
                  <span class="ded-pill-val">$500</span>
                  <span class="ded-pill-badge">Buy-Down</span>
                </div>
                <div class="ded-pill-fee" id="pill-fee-500">+$125/yr</div>
                <div class="ded-pill-mo" id="pill-mo-500">+$10.42/mo</div>
              </button>
              <button type="button" class="ded-pill-btn" data-ded="300">
                <div class="ded-pill-top">
                  <span class="ded-pill-val">$300</span>
                  <span class="ded-pill-badge">Buy-Down</span>
                </div>
                <div class="ded-pill-fee" id="pill-fee-300">+$185/yr</div>
                <div class="ded-pill-mo" id="pill-mo-300">+$15.42/mo</div>
              </button>
              <button type="button" class="ded-pill-btn active" data-ded="200">
                <div class="ded-pill-top">
                  <span class="ded-pill-val">$200</span>
                  <span class="ded-pill-badge">Selected Quote</span>
                </div>
                <div class="ded-pill-fee" id="pill-fee-200">+$238/yr</div>
                <div class="ded-pill-mo" id="pill-mo-200">+$19.83/mo</div>
              </button>
            </div>
          </div>

          <div style="overflow-x: auto;">
            <table class="strategy-table">
              <thead>
                <tr>
                  <th style="width: 28%;">Protection Feature / Scenario</th>
                  <th style="width: 24%; color: var(--mpi-blue);" id="th-mpi-ded-name">MPI with $200 Deductible Buy-Down</th>
                  <th style="width: 28%; color: var(--cap-green);" id="th-cap-col-name">MPI $500 Deductible + Companion Asset Protection (CAP)</th>
                  <th style="width: 20%;">Your Advantage</th>
                </tr>
              </thead>
              <tbody>
                <tr>
                  <td><strong>Annual MPI Deductible Fee</strong></td>
                  <td>
                    <span style="font-weight: 700; color: #b91c1c;" id="table-mpi-ded-fee">+$238.00 / year</span><br>
                    <span style="font-size: 0.8rem; color: #64748b;" id="table-mpi-ded-period">(+$19.83 / month on MPI)</span>
                  </td>
                  <td>
                    <span style="font-weight: 700; color: #059669;" id="table-cap-ded-fee">+$125.00 / year</span><br>
                    <span style="font-size: 0.8rem; color: #64748b;" id="table-cap-ded-fee-sub">($500 buy-down vs $1,000 base)</span>
                  </td>
                  <td>
                    <span class="badge-win" id="table-ded-savings-pill">Save $113 / year on MPI</span>
                  </td>
                </tr>
                <tr>
                  <td><strong>Total Loss (Vehicle Written Off)</strong><br><span style="font-size: 0.8rem; color: #64748b;">Collision, Fire, Theft, Hail, or Flood</span></td>
                  <td>
                    <span style="font-weight: 700; color: #0f172a;" id="table-mpi-ded-loss-oop">Client Pays $200</span><br>
                    <span style="font-size: 0.8rem; color: #64748b;">Out-of-pocket deductible</span>
                  </td>
                  <td>
                    <span style="font-weight: 700; color: #059669;" id="table-cap-ded-loss-oop">Client Pays $0.00</span><br>
                    <span style="font-size: 0.8rem; color: #059669;">CAP reimburses up to $500 deductible</span>
                  </td>
                  <td>
                    <span class="badge-win" id="table-cap-ded-loss-win">CAP Saves You $200!</span>
                  </td>
                </tr>
                <tr>
                  <td>
                    <strong>Partial Loss (Repairable Claims)</strong><br>
                    <span style="font-size: 0.8rem; color: #64748b;">Collisions, body shop repairs &amp; <strong>windshield replacements</strong></span>
                  </td>
                  <td>
                    <span style="font-weight: 700; color: #0f172a;" id="table-mpi-ded-part-oop">Client Pays $200</span><br>
                    <span style="font-size: 0.8rem; color: #64748b;">Out-of-pocket deductible</span>
                  </td>
                  <td>
                    <span style="font-weight: 700; color: #0f172a;" id="table-cap-ded-part-oop">Client Pays $250</span><br>
                    <span style="font-size: 0.8rem; color: #059669;" id="table-cap-ded-part-subdesc">$500 MPI minus $250 CAP reimbursement (includes windshield claims)</span>
                  </td>
                  <td>
                    <span style="font-size: 0.85rem; color: #475569;" id="table-cap-ded-part-win"><strong>Only $50 difference</strong>, while saving <strong id="table-ded-savings-sub">$238</strong> every single year!</span>
                  </td>
                </tr>
                <tr>
                  <td><strong>Replacement Value Top-Up</strong><br><span style="font-size: 0.8rem; color: #64748b;">Credit towards your replacement car</span></td>
                  <td>
                    <span style="font-weight: 700; color: #b91c1c;" id="table-mpi-repl-val"><?= $isVehIneligibleMpiNew ? '$0.00 from MPI (Ineligible)' : ($isVehOneYearMpiNew ? 'Limited to 1 Year Only' : '$0.00 after 2 Years') ?></span><br>
                    <span style="font-size: 0.8rem; color: #64748b;" id="table-mpi-repl-sub"><?= $isVehIneligibleMpiNew ? 'MPI offers $0 replacement value on 2024 & older' : ($isVehOneYearMpiNew ? 'Terminates after 12 months; drops to depreciated ACV' : 'MPI pays depreciated ACV only') ?></span>
                  </td>
                  <td>
                    <span style="font-weight: 700; color: #059669;" id="table-cap-repl-val">Up to $60,000 Saved</span><br>
                    <span style="font-size: 0.8rem; color: #64748b;" id="table-cap-repl-sub">Protects New &amp; Pre-Owned vehicles up to <?= $initialMaxYears ?> years</span>
                  </td>
                  <td>
                    <span class="badge-win" id="table-cap-repl-badge">Full Equity Top-Up</span>
                  </td>
                </tr>
                <tr>
                  <td>
                    <strong>Rental Car Coverage</strong><br>
                    <span style="font-size: 0.8rem; color: #64748b;">Transportation while vehicle is repaired or replaced</span>
                  </td>
                  <td>
                    <span style="font-weight: 700; color: #b91c1c;">$0 (Not in Basic MPI)</span><br>
                    <span style="font-size: 0.8rem; color: #64748b;">Requires purchasing optional $143/yr Loss of Use; otherwise $0</span>
                  </td>
                  <td>
                    <span style="font-weight: 700; color: #059669;">30 Full Days Included</span><br>
                    <span style="font-size: 0.8rem; color: #64748b;">Keeps you on the road ($0 out of pocket)</span>
                  </td>
                  <td>
                    <span class="badge-win">30 Days Included ($0 Extra)</span>
                  </td>
                </tr>
                <tr>
                  <td>
                    <strong>Depreciation Protection Term</strong><br>
                    <span style="font-size: 0.8rem; color: #64748b;">How long your vehicle is protected against total loss depreciation</span>
                  </td>
                  <td>
                    <span style="font-weight: 700; color: #b91c1c;" id="disp-table-mpi-duration"><?= $isVehIneligibleMpiNew ? '0 Years (Ineligible)' : ($isVehOneYearMpiNew ? 'Max 1 Year Only' : 'Max 2 Years Only') ?></span><br>
                    <span style="font-size: 0.8rem; color: #64748b;" id="disp-table-mpi-duration-sub"><?= $isVehIneligibleMpiNew ? 'MPI offers zero replacement protection on pre-owned vehicles' : ($isVehOneYearMpiNew ? 'MPI New Vehicle Protection expires after 12 months' : 'MPI New Vehicle Protection expires after 24 months') ?></span>
                  </td>
                  <td>
                    <span style="font-weight: 700; color: #059669;" id="disp-table-cap-duration">Up to <?= $initialMaxYears ?> Years Guaranteed</span><br>
                    <span style="font-size: 0.8rem; color: #64748b;" id="disp-table-cap-duration-sub"><?= $isOver75k ? 'Covers full term up to 60 months' : 'Covers full loan term up to 84 months' ?></span>
                  </td>
                  <td>
                    <span class="badge-win" id="disp-table-cap-extra-years"><?= $isVehIneligibleMpiNew ? "Full {$initialMaxYears} Extra Years vs $0 MPI" : ($isVehOneYearMpiNew ? max(1, $initialMaxYears - 1) . " Extra Years of Coverage" : max(1, $initialMaxYears - 2) . " Extra Years of Coverage") ?></span>
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

          <div style="margin-top: 1rem; font-size: 0.78rem; color: #64748b; line-height: 1.5; display: flex; align-items: flex-start; gap: 0.45rem;">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="2" style="flex-shrink: 0; margin-top: 2px;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
            <span>
              <strong>Autopac Insurance Advisory:</strong> Vehicle registration, basic insurance, and optional deductible buy-downs are provided exclusively through Manitoba Public Insurance (MPI) and licensed Autopac brokers. Premium estimates and savings illustrated are based on standard published rate schedules. Please consult your licensed insurance broker to confirm individual coverage, discounts, and deductible selection.
            </span>
          </div>
        </div>

        <!-- ========================================================================= -->
        <!-- SECTION 5: 100% RATE LOCK GUARANTEE CLOSER -->
        <!-- ========================================================================= -->
        <div class="rate-lock-banner">
          <div class="rate-lock-inner">
            <div class="rate-lock-icon">
              <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            </div>
            <div style="flex: 1;">
              <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem;">
                <h3 style="margin: 0; font-size: 1.25rem; font-weight: 800; color: #064e3b; display: flex; align-items: center; gap: 0.5rem;">
                  100% Rate Lock Guarantee vs. Rising MPI Premiums &amp; Merit Penalties
                </h3>
                <span id="disp-increase-headline" style="font-size: 0.85rem; font-weight: 800; color: #dc2626; background: #fee2e2; padding: 0.25rem 0.65rem; border-radius: 9999px; border: 1px solid #fca5a5;">
                  +15.1% Approved MPI Rate Increase
                </span>
              </div>
              <p style="margin: 0.5rem 0 0 0; color: #047857; font-size: 0.9rem; line-height: 1.55;">
                While annual basic MPI Autopac premiums fluctuate with approved Public Utilities Board (PUB) general rate hikes, your MPI bill can also jump unpredictably if an accident or speeding ticket reduces your Driver Safety Rating (DSR) merits—costing you hundreds in lost vehicle discounts and driver license surcharges year after year.
                <br><br>
                In contrast, Companion Asset Protection (CAP) provides an <strong>unconditional 100% Rate Lock Guarantee for your entire term (<span id="disp-ratelock-years">up to <?= $initialMaxYears ?> years</span>)</strong>. Your rate is financed into your vehicle payment at 0% change—<strong>claims, collisions, or traffic tickets will NEVER increase your rate or reduce your coverage</strong>.
              </p>

              <!-- Comparison Mini-Grid: MPI Merit Fluctuation vs CAP Fixed Rate Lock -->
              <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 0.85rem; margin-top: 1rem;">
                <div style="background: rgba(220, 38, 38, 0.06); border: 1px solid rgba(220, 38, 38, 0.2); border-radius: var(--radius-md); padding: 0.85rem 1rem;">
                  <div style="font-weight: 700; font-size: 0.85rem; color: #991b1b; display: flex; align-items: center; gap: 0.4rem; margin-bottom: 0.35rem;">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    MPI Premiums &amp; Merit Penalties Fluctuate
                  </div>
                  <ul style="margin: 0; padding-left: 1.15rem; font-size: 0.8rem; color: #7f1d1d; line-height: 1.45;">
                    <li>Annual PUB general rate increases (up to +15.1% approved)</li>
                    <li>At-fault collisions drop you <strong>5 merit levels</strong></li>
                    <li>Speeding tickets &amp; moving violations drop merits by <strong>2+ levels</strong></li>
                    <li>Merit drops slash vehicle discounts &amp; add driver license surcharges</li>
                  </ul>
                </div>

                <div style="background: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.25); border-radius: var(--radius-md); padding: 0.85rem 1rem;">
                  <div style="font-weight: 700; font-size: 0.85rem; color: #065f46; display: flex; align-items: center; gap: 0.4rem; margin-bottom: 0.35rem;">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                    CAP 100% Rate Lock Protection
                  </div>
                  <ul style="margin: 0; padding-left: 1.15rem; font-size: 0.8rem; color: #047857; line-height: 1.45;">
                    <li><strong>100% fixed payment</strong> locked for your full term (up to 7 years)</li>
                    <li>Accidents &amp; total loss claims <strong>never</strong> increase your CAP rate</li>
                    <li>Speeding tickets or merit drops <strong>never</strong> alter your coverage</li>
                    <li>Financed into vehicle payments with zero surprise annual bills</li>
                  </ul>
                </div>
              </div>
            </div>
          </div>
          <div class="rate-lock-pills">
            <span class="rate-lock-pill"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> 100% Rate Lock Guarantee</span>
            <span class="rate-lock-pill"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Accidents &amp; Tickets Never Increase Rate</span>
            <span class="rate-lock-pill"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Up to $60,000 Equity Protected</span>
            <span class="rate-lock-pill"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> $500 Write-Off Deductible Reimbursed</span>
            <span class="rate-lock-pill"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> 30-Day Rental Vehicle Included</span>
          </div>
        </div>

        <!-- ========================================================================= -->
        <!-- SECTION: INSURANCE ADVISORY & BROKER DISCLOSURE -->
        <!-- ========================================================================= -->
        <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: var(--radius-lg); padding: 1.25rem 1.5rem; margin-top: 2rem; margin-bottom: 0.5rem;">
          <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#475569" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            <h4 style="margin: 0; font-size: 0.85rem; font-weight: 700; color: #334155; text-transform: uppercase; letter-spacing: 0.05em;">
              Insurance Advisory &amp; Broker Disclosure
            </h4>
          </div>
          <p style="margin: 0; font-size: 0.8rem; color: #64748b; line-height: 1.6;">
            Companion Asset Protection (CAP) is an optional vehicle asset protection and debt relief warranty product offered through the dealership and underwritten separately. 
            Manitoba Public Insurance (MPI) Autopac rates, deductible buy-downs, Driver Safety Rating (DSR) discounts, and potential premium savings illustrated on this presentation are provided for educational and comparison purposes only, based on current publicly available MPI rating schedules. 
            Individual vehicle insurance requirements, discounts, and premiums may vary based on your driving history, territory, vehicle classification, and coverage selections. 
            Customers are advised to speak directly with their licensed Manitoba Autopac insurance broker or an authorized MPI representative to review their individual policy details, verify exact premiums, and bind their vehicle insurance coverage.
          </p>
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
      let selectedDeductible = 200; // default deductible tier: 1000, 750, 500, 300, 200
      let selectedScenarioYear = 4; // default scenario claim timing: 3, 4, 5
      const DEPRECIATION_RATES = {
        3: { acvPct: 0.61, label: 'Year 3 (Month 36)' },
        4: { acvPct: 0.52, label: 'Year 4 (Month 48)' },
        5: { acvPct: 0.44, label: 'Year 5 (Month 60)' }
      };

      // DOM Elements
      const btnMonthly = document.getElementById('btn-monthly');
      const btnBiweekly = document.getElementById('btn-biweekly');
      const btnTogglePres = document.getElementById('btn-toggle-presentation');
      const btnPrint = document.getElementById('btn-print');
      const managerPanel = document.getElementById('manager-input-panel');
      const managerToggle = document.getElementById('manager-panel-toggle');
      const toggleIndicator = document.getElementById('panel-toggle-indicator');

      // =========================================================================
      // Local Storage Persistence (Isolated per computer / deal)
      // =========================================================================
      const urlParams = new URLSearchParams(window.location.search);
      const dealIdParam = urlParams.get('deal_id');
      const STORAGE_KEY = dealIdParam 
        ? `dealerfai_mpicap_state_deal_${dealIdParam}` 
        : 'dealerfai_mpicap_state';

      const PERSISTENT_INPUT_IDS = [
        'inp-client-name',
        'inp-vehicle-year',
        'inp-vehicle-name',
        'inp-dsr-level',
        'inp-loan-term',
        'inp-interest-rate',
        'inp-veh-price',
        'mpi-26-basic',
        'mpi-25-basic',
        'mpi-26-ded-750',
        'mpi-25-ded-750',
        'mpi-26-ded-500',
        'mpi-25-ded-500',
        'mpi-26-ded-300',
        'mpi-25-ded-300',
        'mpi-26-ded-200',
        'mpi-25-ded-200',
        'mpi-26-tpl',
        'mpi-25-tpl',
        'mpi-26-lossuse',
        'mpi-25-lossuse',
        'mpi-26-newveh',
        'mpi-25-newveh',
        'mpi-26-maxval',
        'mpi-25-maxval',
        'mpi-26-admin',
        'mpi-25-admin',
        'mpi-26-reg',
        'mpi-25-reg',
        'mpi-26-plate',
        'mpi-25-plate',
        'cap-price-36',
        'cap-price-48',
        'cap-price-60',
        'cap-price-72',
        'cap-price-84'
      ];

      function saveState() {
        try {
          const state = {
            version: 1,
            timestamp: Date.now(),
            inputs: {},
            paymentFrequency,
            selectedCapTerm,
            selectedDeductible,
            selectedScenarioYear
          };
          PERSISTENT_INPUT_IDS.forEach(id => {
            const el = document.getElementById(id);
            if (el) {
              state.inputs[id] = el.value;
            }
          });
          localStorage.setItem(STORAGE_KEY, JSON.stringify(state));

          const savedBadge = document.getElementById('storage-saved-badge');
          if (savedBadge) savedBadge.style.display = 'inline-flex';
        } catch (err) {
          // Ignore private mode or disabled localStorage
        }
      }

      function loadState() {
        try {
          const raw = localStorage.getItem(STORAGE_KEY);
          if (!raw) return false;
          const state = JSON.parse(raw);
          if (!state) return false;

          if (state.inputs && typeof state.inputs === 'object') {
            Object.keys(state.inputs).forEach(id => {
              const el = document.getElementById(id);
              if (el && state.inputs[id] !== undefined && state.inputs[id] !== null) {
                el.value = state.inputs[id];
              }
            });
          }

          if (state.paymentFrequency === 'biweekly' || state.paymentFrequency === 'monthly') {
            paymentFrequency = state.paymentFrequency;
            if (btnMonthly && btnBiweekly) {
              btnMonthly.classList.toggle('active', paymentFrequency === 'monthly');
              btnBiweekly.classList.toggle('active', paymentFrequency === 'biweekly');
            }
          }

          if (typeof state.selectedCapTerm === 'number' && state.selectedCapTerm > 0) {
            selectedCapTerm = state.selectedCapTerm;
          }

          if (typeof state.selectedDeductible === 'number' && state.selectedDeductible > 0) {
            selectedDeductible = state.selectedDeductible;
          }

          if (typeof state.selectedScenarioYear === 'number' && [3, 4, 5].includes(state.selectedScenarioYear)) {
            selectedScenarioYear = state.selectedScenarioYear;
            document.querySelectorAll('.scenario-year-btn').forEach(b => {
              b.classList.toggle('active', parseInt(b.dataset.year, 10) === selectedScenarioYear);
            });
          }

          const savedBadge = document.getElementById('storage-saved-badge');
          if (savedBadge) savedBadge.style.display = 'inline-flex';

          return true;
        } catch (err) {
          return false;
        }
      }

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

      // Deductible selector pills
      document.querySelectorAll('.ded-pill-btn').forEach(btn => {
        btn.addEventListener('click', () => {
          selectedDeductible = parseInt(btn.dataset.ded, 10) || 200;
          recalculate();
        });
      });

      // Scenario year selector pills
      document.querySelectorAll('.scenario-year-btn').forEach(btn => {
        btn.addEventListener('click', () => {
          selectedScenarioYear = parseInt(btn.dataset.year, 10) || 4;
          document.querySelectorAll('.scenario-year-btn').forEach(b => {
            b.classList.toggle('active', parseInt(b.dataset.year, 10) === selectedScenarioYear);
          });
          recalculate();
        });
      });

      // Top deductible badge click handler
      const topDedBadge = document.getElementById('disp-top-ded-badge');
      if (topDedBadge) {
        topDedBadge.addEventListener('click', () => {
          const dedSec = document.getElementById('smart-deductible-section');
          if (dedSec) dedSec.scrollIntoView({ behavior: 'smooth' });
        });
      }

      // Reset to Defender sample data
      const btnSample = document.getElementById('btn-load-sample');
      if (btnSample) {
        btnSample.addEventListener('click', () => {
          const vehYrEl = document.getElementById('inp-vehicle-year');
          if (vehYrEl) vehYrEl.value = "2026";
          const vehNameEl = document.getElementById('inp-vehicle-name');
          if (vehNameEl) vehNameEl.value = "2026 Land Rover Defender 110 S P300";
          const vehPriceEl = document.getElementById('inp-veh-price');
          if (vehPriceEl) vehPriceEl.value = 85000;

          document.getElementById('mpi-26-basic').value = 3294;
          document.getElementById('mpi-26-ded-750').value = 60;
          document.getElementById('mpi-26-ded-500').value = 125;
          document.getElementById('mpi-26-ded-300').value = 185;
          document.getElementById('mpi-26-ded-200').value = 238;
          document.getElementById('mpi-26-tpl').value = 11;
          document.getElementById('mpi-26-lossuse').value = 143;
          document.getElementById('mpi-26-newveh').value = 392;
          document.getElementById('mpi-26-maxval').value = 101;
          document.getElementById('mpi-26-admin').value = 132;
          document.getElementById('mpi-26-reg').value = 119;
          document.getElementById('mpi-26-plate').value = 7;

          document.getElementById('mpi-25-basic').value = 2824;
          document.getElementById('mpi-25-ded-750').value = 30;
          document.getElementById('mpi-25-ded-500').value = 62;
          document.getElementById('mpi-25-ded-300').value = 92;
          document.getElementById('mpi-25-ded-200').value = 117;
          document.getElementById('mpi-25-tpl').value = 10;
          document.getElementById('mpi-25-lossuse').value = 136;
          document.getElementById('mpi-25-newveh').value = 412;
          document.getElementById('mpi-25-maxval').value = 101;
          document.getElementById('mpi-25-admin').value = 129;
          document.getElementById('mpi-25-reg').value = 119;
          document.getElementById('mpi-25-plate').value = 7;

          const c36 = document.getElementById('cap-price-36'); if (c36) c36.value = '';
          const c48 = document.getElementById('cap-price-48'); if (c48) c48.value = '';
          document.getElementById('cap-price-60').value = 2219;
          const c72 = document.getElementById('cap-price-72'); if (c72) c72.value = '';
          document.getElementById('cap-price-84').value = 2617;
          if (dsrSelect) dsrSelect.value = "0";
          selectedDeductible = 200;
          selectedScenarioYear = 4;
          document.querySelectorAll('.scenario-year-btn').forEach(b => {
            b.classList.toggle('active', parseInt(b.dataset.year, 10) === 4);
          });
          try {
            localStorage.removeItem(STORAGE_KEY);
          } catch (e) {}
          recalculate();
        });
      }

      // Two-way synchronization between Model Year and Vehicle Name
      const vehYearSelect = document.getElementById('inp-vehicle-year');
      const vehNameInput = document.getElementById('inp-vehicle-name');

      if (vehYearSelect && vehNameInput) {
        // When year dropdown is changed, update leading year in vehicle name if present
        vehYearSelect.addEventListener('change', () => {
          const selectedYr = vehYearSelect.value;
          const currentName = vehNameInput.value.trim();
          const yearMatch = currentName.match(/^(\d{4})\b(.*)/);
          if (yearMatch) {
            vehNameInput.value = `${selectedYr}${yearMatch[2]}`;
          } else if (currentName) {
            vehNameInput.value = `${selectedYr} ${currentName}`;
          }
          recalculate();
        });

        // When vehicle name is typed into, detect year and sync dropdown
        vehNameInput.addEventListener('input', () => {
          const currentName = vehNameInput.value.trim();
          const yearMatch = currentName.match(/\b(20[12]\d)\b/);
          if (yearMatch) {
            const detectedYear = yearMatch[1];
            const optionExists = Array.from(vehYearSelect.options).some(opt => opt.value === detectedYear);
            if (optionExists && vehYearSelect.value !== detectedYear) {
              vehYearSelect.value = detectedYear;
            }
          }
          recalculate();
        });
      }

      // Recalculate on any input change
      const allInputs = document.querySelectorAll('.mpi-input, .cap-input, #inp-loan-term, #inp-interest-rate, #inp-client-name, #inp-vehicle-name, #inp-vehicle-year, #inp-dsr-level, #inp-veh-price');
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

        // Deductible Rates by Tier ($1000 base = $0)
        const dedRates26 = {
          1000: 0,
          750: parseFloat(document.getElementById('mpi-26-ded-750')?.value) || 0,
          500: parseFloat(document.getElementById('mpi-26-ded-500')?.value) || 0,
          300: parseFloat(document.getElementById('mpi-26-ded-300')?.value) || 0,
          200: parseFloat(document.getElementById('mpi-26-ded-200')?.value) || 0,
        };

        const dedRates25 = {
          1000: 0,
          750: parseFloat(document.getElementById('mpi-25-ded-750')?.value) || 0,
          500: parseFloat(document.getElementById('mpi-25-ded-500')?.value) || 0,
          300: parseFloat(document.getElementById('mpi-25-ded-300')?.value) || 0,
          200: parseFloat(document.getElementById('mpi-25-ded-200')?.value) || 0,
        };

        const curDedFee26 = dedRates26[selectedDeductible] ?? 0;
        const curDedFee25 = dedRates25[selectedDeductible] ?? 0;
        const ded26 = curDedFee26;
        const ded25 = curDedFee25;

        // Keep hidden inputs synced
        const hiddenDed26 = document.getElementById('mpi-26-deductible');
        if (hiddenDed26) hiddenDed26.value = ded26;
        const hiddenDed25 = document.getElementById('mpi-25-deductible');
        if (hiddenDed25) hiddenDed25.value = ded25;

        // Update deductible pills amounts & active state
        const pillFees = {
          750: { fee: dedRates26[750], elFee: document.getElementById('pill-fee-750'), elMo: document.getElementById('pill-mo-750') },
          500: { fee: dedRates26[500], elFee: document.getElementById('pill-fee-500'), elMo: document.getElementById('pill-mo-500') },
          300: { fee: dedRates26[300], elFee: document.getElementById('pill-fee-300'), elMo: document.getElementById('pill-mo-300') },
          200: { fee: dedRates26[200], elFee: document.getElementById('pill-fee-200'), elMo: document.getElementById('pill-mo-200') },
        };

        Object.keys(pillFees).forEach(tier => {
          const p = pillFees[tier];
          if (p.elFee) p.elFee.textContent = p.fee > 0 ? `+${fmt(p.fee)}/yr` : '$0/yr';
          if (p.elMo) p.elMo.textContent = p.fee > 0 ? `+${fmtDec(p.fee / 12)}/mo` : '$0.00/mo';
        });

        document.querySelectorAll('.ded-pill-btn').forEach(btn => {
          const bDed = parseInt(btn.getAttribute('data-ded'), 10);
          if (bDed === selectedDeductible) {
            btn.classList.add('active');
          } else {
            btn.classList.remove('active');
          }
        });

        // Update Top Bar & Active Deductible Badges
        const elTopDedText = document.getElementById('disp-top-ded-text');
        const elActiveDedBadge = document.getElementById('disp-active-ded-badge');
        const dedMoText = curDedFee26 > 0 ? `(+${fmtDec(curDedFee26 / 12)}/mo)` : '($0.00/mo)';
        const dedYrText = curDedFee26 > 0 ? `+${fmt(curDedFee26)}/yr` : '$0/yr (Included)';

        if (elTopDedText) {
          elTopDedText.textContent = selectedDeductible === 1000
            ? `MPI Base Deductible: $1,000 ($0.00/mo)`
            : `MPI Deductible: $${selectedDeductible} (${dedMoText.replace(/[()]/g, '')})`;
        }

        if (elActiveDedBadge) {
          elActiveDedBadge.textContent = selectedDeductible === 1000
            ? `Comparing: $1,000 Base Deductible ($0 Extra Fee • Included in Basic)`
            : `Comparing: $${selectedDeductible} Deductible (${dedYrText} • ${dedMoText.replace(/[()]/g, '')})`;
        }

        // Vehicle Model Year and MPI New Vehicle Protection Underwriting Rules:
        // - 2024 or older: Ineligible ($0.00 from MPI / ACV only)
        // - 2025: 1 year max (12 months)
        // - 2026+: up to 2 years (24 months)
        const vehYearVal = document.getElementById('inp-vehicle-year')?.value;
        const vehYear = parseInt(vehYearVal, 10) || 2026;
        const isVehIneligibleMpiNew = (vehYear <= 2024);
        const isVehOneYearMpiNew = (vehYear === 2025);
        const isVehBrandNew = (vehYear >= 2026);

        const inpMpiNew26 = document.getElementById('mpi-26-newveh');
        const inpMpiNew25 = document.getElementById('mpi-25-newveh');
        const badgeMpiNewStatus = document.getElementById('disp-mpi-newveh-status-badge');

        let rawNewveh26 = parseFloat(inpMpiNew26?.value) || 0;
        let rawNewveh25 = parseFloat(inpMpiNew25?.value) || 0;

        if (inpMpiNew26 && !inpMpiNew26.disabled && rawNewveh26 > 0) inpMpiNew26.dataset.activeVal = rawNewveh26;
        if (inpMpiNew25 && !inpMpiNew25.disabled && rawNewveh25 > 0) inpMpiNew25.dataset.activeVal = rawNewveh25;

        let effectiveNewveh26 = rawNewveh26;
        let effectiveNewveh25 = rawNewveh25;

        if (isVehIneligibleMpiNew) {
          if (inpMpiNew26) {
            inpMpiNew26.disabled = true;
            inpMpiNew26.value = 0;
          }
          if (inpMpiNew25) {
            inpMpiNew25.disabled = true;
            inpMpiNew25.value = 0;
          }
          effectiveNewveh26 = 0;
          effectiveNewveh25 = 0;
          if (badgeMpiNewStatus) {
            badgeMpiNewStatus.textContent = 'Ineligible on 2024 & Older ($0.00)';
            badgeMpiNewStatus.style.background = '#fef2f2';
            badgeMpiNewStatus.style.color = '#dc2626';
            badgeMpiNewStatus.style.border = '1px solid #fecaca';
          }
        } else if (isVehOneYearMpiNew) {
          if (inpMpiNew26) {
            inpMpiNew26.disabled = false;
            if (inpMpiNew26.value == 0 && inpMpiNew26.dataset.activeVal) {
              inpMpiNew26.value = inpMpiNew26.dataset.activeVal;
            } else if (inpMpiNew26.value == 0) {
              inpMpiNew26.value = 392;
            }
            effectiveNewveh26 = parseFloat(inpMpiNew26.value) || 0;
          }
          if (inpMpiNew25) {
            inpMpiNew25.disabled = true;
            inpMpiNew25.value = 0;
          }
          effectiveNewveh25 = 0;
          if (badgeMpiNewStatus) {
            badgeMpiNewStatus.textContent = 'Max 1 Year (12 Mo) on 2025 Models';
            badgeMpiNewStatus.style.background = '#fffbeb';
            badgeMpiNewStatus.style.color = '#b45309';
            badgeMpiNewStatus.style.border = '1px solid #fde68a';
          }
        } else {
          if (inpMpiNew26) {
            inpMpiNew26.disabled = false;
            if (inpMpiNew26.value == 0 && inpMpiNew26.dataset.activeVal) {
              inpMpiNew26.value = inpMpiNew26.dataset.activeVal;
            } else if (inpMpiNew26.value == 0) {
              inpMpiNew26.value = 392;
            }
            effectiveNewveh26 = parseFloat(inpMpiNew26.value) || 0;
          }
          if (inpMpiNew25) {
            inpMpiNew25.disabled = false;
            if (inpMpiNew25.value == 0 && inpMpiNew25.dataset.activeVal) {
              inpMpiNew25.value = inpMpiNew25.dataset.activeVal;
            } else if (inpMpiNew25.value == 0) {
              inpMpiNew25.value = 412;
            }
            effectiveNewveh25 = parseFloat(inpMpiNew25.value) || 0;
          }
          if (badgeMpiNewStatus) {
            badgeMpiNewStatus.textContent = 'Up to 2 Years (24 Mo) for New Vehicles';
            badgeMpiNewStatus.style.background = '#eff6ff';
            badgeMpiNewStatus.style.color = '#1d4ed8';
            badgeMpiNewStatus.style.border = '1px solid #bfdbfe';
          }
        }

        // Sum MPI 2026 Lines
        const basic26 = parseFloat(document.getElementById('mpi-26-basic').value) || 0;
        const tpl26 = parseFloat(document.getElementById('mpi-26-tpl').value) || 0;
        const loss26 = parseFloat(document.getElementById('mpi-26-lossuse').value) || 0;
        const newveh26 = effectiveNewveh26;
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
        const tpl25 = parseFloat(document.getElementById('mpi-25-tpl').value) || 0;
        const loss25 = parseFloat(document.getElementById('mpi-25-lossuse').value) || 0;
        const newveh25 = effectiveNewveh25;
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

        // Payment frequency parameters for Loan / Lease / CAP financing
        const loanFreqSuffix = paymentFrequency === 'biweekly' ? '/bi-wk' : '/mo';
        const loanPeriodDivisor = paymentFrequency === 'biweekly' ? 26 : 12;

        // MPI amounts are strictly monthly (MPI only offers annual or monthly pre-authorized payments)
        const mpiMonthlyDivisor = 12;
        const mpiAddonsAnnual26 = newveh26 + loss26;
        const mpiAddonsMonthly26 = mpiAddonsAnnual26 / mpiMonthlyDivisor;

        // Deductible Savings on MPI ($238/yr = $19.83/mo savings on insurance bill)
        const dedSavingsAnnual26 = ded26; // e.g. $238
        const dedSavingsMonthly26 = dedSavingsAnnual26 / mpiMonthlyDivisor;
        // Bi-weekly equivalent deductible savings credit towards loan budget
        const dedSavingsLoanPeriod26 = dedSavingsAnnual26 / loanPeriodDivisor;

        // Total MPI Optionals ($200 Deductible buy-down + New Vehicle + Loss of Use)
        const mpiAllOptionalsAnnual26 = mpiAddonsAnnual26 + dedSavingsAnnual26;
        const mpiAllOptionalsMonthly26 = mpiAllOptionalsAnnual26 / mpiMonthlyDivisor;

        // Update Card 1: MPI Add-On Protection (New Car + Loss of Use) — ALWAYS Monthly
        const elAddonsTagText = document.getElementById('disp-mpi-addons-tag-text');
        if (elAddonsTagText) {
          elAddonsTagText.textContent = isVehIneligibleMpiNew
            ? 'MPI Add-Ons (Rental Only • New Car Ineligible)'
            : 'MPI Add-Ons (New Car + Rental)';
        }

        const elAddonsAmt = document.getElementById('disp-mpi-addons-amount');
        if (elAddonsAmt) elAddonsAmt.innerHTML = `${fmtDec(mpiAddonsMonthly26)}<span class="period">/mo</span>`;

        const elNewvehSub = document.getElementById('disp-mpi-newveh-sub');
        if (elNewvehSub) {
          if (isVehIneligibleMpiNew) {
            elNewvehSub.innerHTML = '<span style="color: #dc2626;">Ineligible ($0.00 from MPI on 2024 & older)</span>';
          } else if (isVehOneYearMpiNew) {
            elNewvehSub.textContent = `${fmt(newveh26)}/yr (${fmtDec(newveh26 / 12)}/mo) — Max 1 Yr`;
          } else {
            elNewvehSub.textContent = `${fmt(newveh26)}/yr (${fmtDec(newveh26 / 12)}/mo)`;
          }
        }

        const elLossSub = document.getElementById('disp-mpi-lossuse-sub');
        if (elLossSub) elLossSub.textContent = `${fmt(loss26)}/yr (${fmtDec(loss26 / 12)}/mo)`;

        const elAddonsAnnual = document.getElementById('disp-mpi-addons-annual');
        if (elAddonsAnnual) {
          elAddonsAnnual.textContent = isVehIneligibleMpiNew
            ? `${fmt(loss26)}/yr (Rental Car Only)`
            : `${fmt(mpiAddonsAnnual26)}/yr (Billed Monthly by MPI)`;
        }

        const elAddonsBadge = document.getElementById('disp-mpi-addons-badge');
        if (elAddonsBadge) {
          if (isVehIneligibleMpiNew) {
            elAddonsBadge.textContent = '⚠️ MPI Ineligible (2+ Yrs Old) • ACV Only (No Replacement Value)';
          } else if (isVehOneYearMpiNew) {
            elAddonsBadge.textContent = '⚠️ Billed Monthly by MPI • Max 1 Year (Expires after 12 Mo)';
          } else {
            elAddonsBadge.textContent = 'Billed Monthly by MPI • Expires after 2 Years';
          }
        }

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

        // Update Scenario Vehicle Name, Price & Exact Matching Math
        const depInfo = DEPRECIATION_RATES[selectedScenarioYear] || DEPRECIATION_RATES[4];
        const scenVehPrice = vehPrice > 0 ? vehPrice : 85000;
        const scenMpiPayout = Math.round((scenVehPrice * depInfo.acvPct) / 1000) * 1000;
        const scenCapTopUp = scenVehPrice - scenMpiPayout;

        const elScenVehName = document.getElementById('disp-scenario-veh-name');
        if (elScenVehName) elScenVehName.textContent = vehName;

        const elScenVehPrice = document.getElementById('disp-scenario-veh-price');
        if (elScenVehPrice) elScenVehPrice.textContent = fmt(scenVehPrice);

        const elScenYearLabel = document.getElementById('disp-scenario-year-label');
        if (elScenYearLabel) elScenYearLabel.textContent = depInfo.label;

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
        if (elPillarYearsTitle) elPillarYearsTitle.textContent = `Guaranteed Coverage for ${maxYearsText}`;

        const elPillarYearsDesc = document.getElementById('disp-pillar-years-desc');
        if (elPillarYearsDesc) {
          elPillarYearsDesc.textContent = `Covers New or Pre-Owned vehicles for up to ${maxAllowedYears} years. Your protection is locked in and remains fully in effect regardless of claims or driving record.`;
        }

        // Update Card 2 Badge
        const elCardCapBadge = document.getElementById('disp-card-cap-badge');
        if (elCardCapBadge) {
          elCardCapBadge.textContent = isVehIneligibleMpiNew
            ? `Up to ${maxAllowedYears} Years Locked • Pre-Owned Covered • Reimburses Deductible`
            : `${maxYearsText} Locked • Reimburses Deductible`;
        }

        // Update Strategy Table Duration & Replacement Rows
        const elTableDuration = document.getElementById('disp-table-cap-duration');
        if (elTableDuration) elTableDuration.textContent = `${maxYearsText} Guaranteed`;

        const elTableDurationSub = document.getElementById('disp-table-cap-duration-sub');
        if (elTableDurationSub) elTableDurationSub.textContent = isLuxuryOrOver75k ? 'Covers full term up to 60 months' : 'Covers full loan term up to 84 months';

        const elTableMpiDuration = document.getElementById('disp-table-mpi-duration');
        if (elTableMpiDuration) {
          elTableMpiDuration.textContent = isVehIneligibleMpiNew
            ? '0 Years (Ineligible)'
            : (isVehOneYearMpiNew
              ? 'Max 1 Year Only'
              : 'Max 2 Years Only');
        }

        const elTableMpiDurationSub = document.getElementById('disp-table-mpi-duration-sub');
        if (elTableMpiDurationSub) {
          elTableMpiDurationSub.textContent = isVehIneligibleMpiNew
            ? 'MPI offers zero replacement protection on pre-owned vehicles'
            : (isVehOneYearMpiNew
              ? 'MPI New Vehicle Protection expires after 12 months'
              : 'MPI New Vehicle Protection expires after 24 months');
        }

        const elTableMpiReplVal = document.getElementById('table-mpi-repl-val');
        if (elTableMpiReplVal) {
          elTableMpiReplVal.textContent = isVehIneligibleMpiNew
            ? '$0.00 from MPI (Ineligible)'
            : (isVehOneYearMpiNew ? 'Limited to 1 Year Only' : '$0.00 after 2 Years');
        }

        const elTableMpiReplSub = document.getElementById('table-mpi-repl-sub');
        if (elTableMpiReplSub) {
          elTableMpiReplSub.textContent = isVehIneligibleMpiNew
            ? 'MPI offers $0 replacement value on 2024 & older'
            : (isVehOneYearMpiNew
              ? 'Terminates after 12 months; drops to depreciated ACV'
              : 'MPI pays depreciated ACV only');
        }

        const elTableCapReplSub = document.getElementById('table-cap-repl-sub');
        if (elTableCapReplSub) {
          elTableCapReplSub.textContent = `Protects New & Pre-Owned vehicles up to ${maxAllowedYears} years`;
        }

        const elTableExtraYears = document.getElementById('disp-table-cap-extra-years');
        if (elTableExtraYears) {
          if (isVehIneligibleMpiNew) {
            elTableExtraYears.textContent = `Full ${maxAllowedYears} Extra Years vs $0 MPI`;
          } else if (isVehOneYearMpiNew) {
            const extraYears = Math.max(1, maxAllowedYears - 1);
            elTableExtraYears.textContent = `${extraYears} Extra Year${extraYears > 1 ? 's' : ''} of Coverage`;
          } else {
            const extraYears = Math.max(1, maxAllowedYears - 2);
            elTableExtraYears.textContent = `${extraYears} Extra Year${extraYears > 1 ? 's' : ''} of Coverage`;
          }
        }

        // Update Add-On Comparison Section Dynamic Texts
        const elAddonDesc = document.getElementById('disp-addon-cap-term-desc');
        if (elAddonDesc) elAddonDesc.textContent = `Up to ${maxAllowedYears}-Year`;

        const elAddonLead = document.getElementById('disp-addon-comparison-lead');
        if (elAddonLead) {
          if (isVehIneligibleMpiNew) {
            elAddonLead.innerHTML = `Evaluating MPI's coverage limitations on pre-owned vehicles (ineligible for New Vehicle Protection) vs. <span id="disp-addon-cap-term-desc">Up to ${maxAllowedYears}-Year</span> Companion Asset Protection (CAP).`;
          } else if (isVehOneYearMpiNew) {
            elAddonLead.innerHTML = `Evaluating what MPI charges for its optional 1-year New Vehicle Protection and Loss of Use vs. <span id="disp-addon-cap-term-desc">Up to ${maxAllowedYears}-Year</span> Companion Asset Protection (CAP).`;
          } else {
            elAddonLead.innerHTML = `Evaluating what MPI charges for its optional 2-year New Vehicle Protection and Loss of Use vs. <span id="disp-addon-cap-term-desc">Up to ${maxAllowedYears}-Year</span> Companion Asset Protection (CAP).`;
          }
        }

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
          elTermsSubtext.textContent = 'Select your preferred coverage term below to update the monthly and bi-weekly payment comparison.';
        }

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

              let badgeText = 'OFFERED';
              let badgeClass = 'cap-term-badge';
              if (isSel) {
                badgeText = 'SELECTED';
                badgeClass = 'cap-term-badge selected';
              } else if (opt.term === loanTerm) {
                badgeText = 'MATCHES LOAN';
                badgeClass = 'cap-term-badge match';
              }

              let relationPill = '';
              if (opt.term < loanTerm) {
                relationPill = `<div class="cap-relation-pill partial">🛡️ Covers Years 1–${years} of ${loanTerm}-Mo Loan</div>`;
              } else if (opt.term === loanTerm) {
                relationPill = `<div class="cap-relation-pill match">✓ Full ${loanTerm}-Mo Loan Match</div>`;
              } else {
                relationPill = `<div class="cap-relation-pill extended">★ Extends Beyond Loan Payoff</div>`;
              }

              card.innerHTML = `
                <div class="${badgeClass}">${badgeText}</div>
                <div style="font-size: 0.72rem; font-weight: 700; text-transform: uppercase; color: var(--cap-green); letter-spacing: 0.05em; margin-bottom: 0.2rem;">Protection Window</div>
                <div style="font-weight: 800; color: #0f172a; font-size: 1.15rem; margin-bottom: 0.35rem;">${termLabel}</div>
                ${relationPill}
                <div style="margin-top: 0.75rem; padding-top: 0.75rem; border-top: 1px solid #f1f5f9;">
                  <div style="font-size: 0.72rem; font-weight: 700; text-transform: uppercase; color: #64748b; letter-spacing: 0.04em;">Loan Payment Impact</div>
                  <div style="font-size: 1.5rem; font-weight: 800; color: var(--cap-green); margin: 0.2rem 0;">
                    +${fmtDec(opt.payment)}<span style="font-size: 0.85rem; font-weight: 600; color: #64748b;">${loanFreqSuffix}</span>
                  </div>
                  <div style="font-size: 0.78rem; color: #475569; font-weight: 600;">For all ${loanTerm} months of vehicle loan</div>
                  <div style="font-size: 0.75rem; color: #047857; margin-top: 0.25rem; font-weight: 600;">${paymentFrequency === 'biweekly' ? `Equivalent to ${fmtDec(opt.paymentMonthly)}/mo` : `Just ${fmtDec(opt.paymentBiweekly)} bi-weekly`} • ${fmtDec(opt.perDay)}/day</div>
                </div>
              `;

              card.addEventListener('click', () => {
                selectedCapTerm = opt.term;
                recalculate();
              });

              termsContainer.appendChild(card);
            });
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
        if (elCapAmt) elCapAmt.innerHTML = `${fmtDec(capPmt)}<span class="period">${loanFreqSuffix}</span>`;
        const elCapTermLabel = document.getElementById('disp-cap-term-label');
        if (elCapTermLabel) elCapTermLabel.textContent = capTermLabel;
        const elCapPerDay = document.getElementById('disp-cap-per-day');
        if (elCapPerDay) elCapPerDay.textContent = `${fmtDec(capPerDay)}/day`;

        const elCapLoanSub = document.getElementById('disp-cap-loan-sub');
        if (elCapLoanSub) {
          const freqDesc = paymentFrequency === 'biweekly' ? 'Bi-Weekly payments' : 'Monthly payments';
          if (selectedCapTerm < loanTerm) {
            elCapLoanSub.innerHTML = `Protects Years 1–${capYears} • Financed over full <strong>${loanTerm}-month</strong> loan (${freqDesc})`;
          } else if (selectedCapTerm === loanTerm) {
            elCapLoanSub.innerHTML = `✓ <strong>100% Match</strong> with your <strong>${loanTerm}-month</strong> loan term (${freqDesc})`;
          } else {
            elCapLoanSub.innerHTML = `Covers ${capYears} Years • Financed over <strong>${loanTerm}-month</strong> loan (${freqDesc})`;
          }
        }

        // Render Timeline & Financing vs Protection Explainer
        const timelineContainer = document.getElementById('cap-timeline-explainer');
        if (timelineContainer && currentCap) {
          const isTermShorter = currentCap.term < loanTerm;
          const isTermEqual = currentCap.term === loanTerm;
          const isTermLonger = currentCap.term > loanTerm;
          
          const capPct = Math.min(100, Math.round((currentCap.term / loanTerm) * 100));
          const remPct = Math.max(0, 100 - capPct);

          let explanationBadge = '';
          let explanationHtml = '';
          let activeWindowLabel = '';

          if (isTermShorter) {
            explanationBadge = `<span style="background: #fef3c7; color: #92400e; font-weight: 700; padding: 3px 8px; border-radius: 4px; font-size: 0.75rem;">Years 1–${capYears} Protection • ${loanTerm}-Mo Financing</span>`;
            explanationHtml = `
              <strong>How your financing &amp; coverage work together:</strong><br>
              Your Companion Asset Protection is financed directly into your vehicle loan, adding just <strong>+${fmtDec(capPmt)}${loanFreqSuffix}</strong> (a modest <strong>${fmtDec(capPerDay)}/day</strong>) across all <strong>${loanTerm} months of your loan</strong> with zero out-of-pocket cost today.<br><br>
              <strong>Why this is a smart financial strategy:</strong><br>
              Vehicles suffer their steepest market depreciation during the first 5 years (Months 1–60). Having 60-Month CAP gives you 100% Replacement Value Top-Up and deductible protection during your highest-risk ownership window, while your ${loanTerm}-month financing keeps the monthly payment ultra-affordable. By Month 60, your remaining loan balance has significantly dropped, naturally closing the equity gap.
            `;
            activeWindowLabel = `Active Months 1–${currentCap.term} (First ${capYears} Years of Ownership)`;
          } else if (isTermEqual) {
            explanationBadge = `<span style="background: #dcfce7; color: #166534; font-weight: 700; padding: 3px 8px; border-radius: 4px; font-size: 0.75rem;">✓ 100% Loan Term Match</span>`;
            explanationHtml = `
              <strong>Complete Loan Coverage Match:</strong><br>
              Your Companion Asset Protection term matches your <strong>${loanTerm}-month vehicle loan</strong> 100%. 
              For <strong>+${fmtDec(capPmt)}${loanFreqSuffix}</strong> (<strong>${fmtDec(capPerDay)}/day</strong>), you have complete replacement value and deductible protection from the day you drive off the lot until your final loan payment is made!
            `;
            activeWindowLabel = `Active for 100% of Loan Term (All ${loanTerm} Months)`;
          } else {
            explanationBadge = `<span style="background: #dbeafe; color: #1e40af; font-weight: 700; padding: 3px 8px; border-radius: 4px; font-size: 0.75rem;">Extends Past Loan Payoff</span>`;
            explanationHtml = `
              <strong>Extended Protection Beyond Loan Payoff:</strong><br>
              Your CAP coverage protects your vehicle for <strong>${currentCap.term} months (${capYears} years)</strong>—remaining active for an extra <strong>${currentCap.term - loanTerm} months</strong> even after your <strong>${loanTerm}-month vehicle loan</strong> is paid in full!
            `;
            activeWindowLabel = `Active for ${currentCap.term} Months (${currentCap.term - loanTerm} mo past loan payoff)`;
          }

          timelineContainer.innerHTML = `
            <div class="timeline-container">
              <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.75rem; margin-bottom: 0.5rem;">
                <div class="timeline-title">
                  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--brand-color)" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                  <span>Coverage Window vs. Loan Payment Breakdown</span>
                </div>
                <div>${explanationBadge}</div>
              </div>
              <div class="timeline-subtitle">
                Visualizing your <strong>${loanTerm}-month vehicle loan financing</strong> alongside your <strong>${currentCap.term}-month (${capYears}-year) CAP protection window</strong>:
              </div>

              <div class="timeline-track-wrap">
                <div class="timeline-row">
                  <div class="timeline-label-bar">
                    <span>Vehicle Loan Term (${loanTerm} Months)</span>
                    <span style="color: #2563eb;">Payment Impact: +${fmtDec(capPmt)}${loanFreqSuffix} (Months 1–${loanTerm})</span>
                  </div>
                  <div class="timeline-track">
                    <div class="timeline-fill-loan">
                      <span>💳 Full ${loanTerm}-Month Vehicle Loan (+${fmtDec(capPmt)}${loanFreqSuffix} on every payment)</span>
                    </div>
                  </div>
                </div>

                <div class="timeline-row">
                  <div class="timeline-label-bar">
                    <span>Companion Asset Protection (${currentCap.term} Months / ${capYears} Years)</span>
                    <span style="color: #059669;">${activeWindowLabel}</span>
                  </div>
                  <div class="timeline-track">
                    <div class="timeline-fill-cap" style="width: ${capPct}%;">
                      <span>🛡️ ACTIVE PROTECTION (Months 1 to ${currentCap.term})</span>
                    </div>
                    ${remPct > 0 ? `
                      <div class="timeline-fill-remaining" style="width: ${remPct}%;">
                        <span>Months ${currentCap.term + 1}–${loanTerm} (Loan continues)</span>
                      </div>
                    ` : ''}
                  </div>
                </div>
              </div>

              <div class="timeline-cards-grid">
                <div class="timeline-card-box">
                  <div style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; color: #2563eb; letter-spacing: 0.04em;">
                    💳 Your ${paymentFrequency === 'biweekly' ? 'Bi-Weekly' : 'Monthly'} Loan Payment
                  </div>
                  <div style="font-size: 1.6rem; font-weight: 800; color: #0f172a; margin: 0.25rem 0;">
                    +${fmtDec(capPmt)} <span style="font-size: 0.9rem; font-weight: 600; color: #64748b;">${loanFreqSuffix}</span>
                  </div>
                  <ul style="margin: 0.5rem 0 0 0; padding-left: 1.2rem; font-size: 0.85rem; color: #334155; line-height: 1.55;">
                    <li><strong>Applied to all ${loanTerm} months of your loan:</strong> Financed with your vehicle so your payment stays predictable without any out-of-pocket payment today.</li>
                    <li><strong>Alternate Frequency:</strong> ${paymentFrequency === 'biweekly' ? `Equivalent to <strong>${fmtDec(currentCap.paymentMonthly)} / mo</strong>` : `Just <strong>${fmtDec(currentCap.paymentBiweekly)} bi-weekly</strong>`} (<strong>${fmtDec(capPerDay)}/day</strong>).</li>
                    <li><strong>Locked Rate:</strong> Financed at <strong>${interestRate.toFixed(2)}% APR</strong> with zero separate insurance bills.</li>
                  </ul>
                </div>

                <div class="timeline-card-box" style="background: #ecfdf5; border-color: #a7f3d0;">
                  <div style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; color: #047857; letter-spacing: 0.04em;">
                    🛡️ Your Active Protection Window
                  </div>
                  <div style="font-size: 1.6rem; font-weight: 800; color: #065f46; margin: 0.25rem 0;">
                    ${currentCap.term} Months <span style="font-size: 0.9rem; font-weight: 600; color: #047857;">(${capYears} Years)</span>
                  </div>
                  <div style="font-size: 0.85rem; color: #064e3b; line-height: 1.55; margin-top: 0.5rem;">
                    ${explanationHtml}
                  </div>
                </div>
              </div>
            </div>
          `;
        }

        // Pull all entered tier fees from Manager Setup
        const fee1000 = 0;
        const fee750 = dedRates26[750];
        const fee500 = dedRates26[500];
        const fee300 = dedRates26[300];
        const fee200 = dedRates26[200];

        // Update Card 3: Deductible Savings
        const elNetCap = document.getElementById('disp-net-cap-amount');
        const elStratPeriodLine = document.getElementById('disp-strat-period-line');
        const elCardStratTag = document.getElementById('disp-card-strat-tag');
        const elCardStratSubtext = document.getElementById('disp-card-strat-subtext');
        const elStratBadge = document.getElementById('disp-strategy-badge-text');

        if (selectedDeductible === 200 || selectedDeductible === 300) {
          const dedSavAnnual = Math.max(0, curDedFee26 - fee500);
          const dedSavLoanPeriod = dedSavAnnual / loanPeriodDivisor;
          const netCapPmt = Math.max(0, capPmt - dedSavLoanPeriod);

          if (elCardStratTag) elCardStratTag.textContent = 'Smart Deductible Savings';
          if (elNetCap) elNetCap.innerHTML = `Save ${fmt(dedSavAnnual)}<span class="period">/yr</span>`;
          if (elStratPeriodLine) {
            elStratPeriodLine.textContent = `Save ${fmtDec(dedSavLoanPeriod)}${loanFreqSuffix} on your MPI bill`;
          }
          if (elCardStratSubtext) {
            elCardStratSubtext.innerHTML = `
              Choose <strong>$500 MPI Deductible</strong> &amp; pocket <strong>${fmt(dedSavAnnual)}/yr (${fmtDec(dedSavLoanPeriod)}${loanFreqSuffix})</strong> in MPI savings.<br>
              In a Total Loss: CAP pays $500 &rarr; <strong>$0 Out of Pocket</strong> ($${selectedDeductible} saved vs MPI)!<br>
              In a Partial Loss (repairs &amp; windshields): CAP pays $250 &rarr; <strong>Only $250 Out of Pocket</strong>!<br>
              <span style="display: inline-block; margin-top: 0.35rem; color: #047857; font-weight: 600;">
                💡 Budget tip: Deductible savings offsets your CAP loan cost down to just <strong>${fmtDec(netCapPmt)}${loanFreqSuffix}</strong>!
              </span>
            `;
          }
          if (elStratBadge) {
            elStratBadge.textContent = `Save ${fmt(dedSavAnnual)}/yr on MPI + $0 Deductible on Write-off`;
          }
        } else if (selectedDeductible === 500) {
          if (elCardStratTag) elCardStratTag.textContent = 'Zero-Deductible Total Loss';
          if (elNetCap) elNetCap.innerHTML = `Save $500<span class="period">on write-off</span>`;
          if (elStratPeriodLine) {
            elStratPeriodLine.textContent = `$0.00 Out of Pocket on Total Loss`;
          }
          if (elCardStratSubtext) {
            elCardStratSubtext.innerHTML = `
              Keep your <strong>$500 MPI Deductible</strong> (${fmt(fee500)}/yr) without paying for expensive $200 buy-downs:<br>
              In a Total Loss: CAP pays $500 &rarr; <strong>$0 Out of Pocket</strong> ($500 saved)!<br>
              In a Partial Loss (repairs &amp; windshields): CAP pays $250 &rarr; <strong>Cuts deductible in half to $250</strong>!
            `;
          }
          if (elStratBadge) {
            elStratBadge.textContent = `CAP Pays $500 Deductible on Write-Off + $250 on Repairs`;
          }
        } else if (selectedDeductible === 750) {
          if (elCardStratTag) elCardStratTag.textContent = 'Deductible Protection ($750)';
          if (elNetCap) elNetCap.innerHTML = `Save $500<span class="period">on write-off</span>`;
          if (elStratPeriodLine) {
            elStratPeriodLine.textContent = `Pay only $250 Out of Pocket on Write-Off`;
          }
          if (elCardStratSubtext) {
            elCardStratSubtext.innerHTML = `
              Keep your <strong>$750 MPI Deductible</strong> (${fmt(fee750)}/yr) with CAP:<br>
              In a Total Loss: CAP pays $500 &rarr; <strong>Pay only $250 out of pocket</strong> ($500 saved)!<br>
              In a Partial Loss (repairs &amp; windshields): CAP pays $250 &rarr; <strong>Pay only $500 out of pocket</strong> ($250 saved)!
            `;
          }
          if (elStratBadge) {
            elStratBadge.textContent = `CAP Reimburses $500 on Total Loss & $250 on Repairs`;
          }
        } else { // 1000
          if (elCardStratTag) elCardStratTag.textContent = 'Base Deductible Protection';
          if (elNetCap) elNetCap.innerHTML = `Save $500<span class="period">on write-off</span>`;
          if (elStratPeriodLine) {
            elStratPeriodLine.textContent = `Zero Buy-Down Fees • Reimburses $500 on Write-Off / $250 on Repairs`;
          }
          if (elCardStratSubtext) {
            elCardStratSubtext.innerHTML = `
              Keep MPI's <strong>$1,000 Base Deductible</strong> ($0 buy-down fees) with CAP:<br>
              In a Total Loss (Write-Off Only): CAP pays $500 &rarr; <strong>Slashes deductible by 50% to $500</strong>!<br>
              In a Partial Loss (repairs &amp; windshields): CAP pays $250 &rarr; <strong>Reduces deductible to $750</strong> ($250 reimbursed)!
            `;
          }
          if (elStratBadge) {
            elStratBadge.textContent = `Zero MPI Fees • Reimburses $500 on Write-Off & $250 on Repairs`;
          }
        }

        // Update Deductible Strategy Section Header & Titles
        const elStratTitle = document.getElementById('disp-ded-strategy-title');
        if (elStratTitle) {
          if (selectedDeductible === 200 || selectedDeductible === 300) {
            elStratTitle.innerHTML = `
              <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="var(--brand-color)" stroke-width="2.5"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
              The Smart Deductible Strategy: $500 MPI Deductible with CAP
            `;
          } else if (selectedDeductible === 500) {
            elStratTitle.innerHTML = `
              <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="var(--brand-color)" stroke-width="2.5"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
              Zero-Deductible Total Loss Strategy: $500 MPI Deductible with CAP
            `;
          } else if (selectedDeductible === 750) {
            elStratTitle.innerHTML = `
              <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="var(--brand-color)" stroke-width="2.5"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
              Deductible Protection: $750 MPI Deductible with CAP
            `;
          } else {
            elStratTitle.innerHTML = `
              <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="var(--brand-color)" stroke-width="2.5"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
              Deductible Protection on Base Rate: MPI $1,000 Base with CAP
            `;
          }
        }

        const elStratSub = document.getElementById('disp-ded-strategy-sub');
        if (elStratSub) {
          if (selectedDeductible === 200 || selectedDeductible === 300) {
            elStratSub.textContent = `Why pay MPI extra every year for a $${selectedDeductible} deductible? Choosing the $500 buy-down saves you money on Autopac, while CAP eliminates your deductible on a write-off!`;
          } else if (selectedDeductible === 500) {
            elStratSub.textContent = `You chose MPI's $500 deductible buy-down. With CAP, your write-off deductible drops to $0.00, and repair/windshield deductibles are cut in half to $250!`;
          } else if (selectedDeductible === 750) {
            elStratSub.textContent = `With MPI's $750 deductible buy-down, CAP reimburses $500 on write-offs (reducing your cost to $250) and $250 on repairs/windshields!`;
          } else {
            elStratSub.textContent = `Avoid expensive MPI buy-down fees entirely: keep the $1,000 base rate ($0 extra fee) and let CAP reimburse your deductible!`;
          }
        }

        const elDedSavingsLabel = document.getElementById('disp-ded-savings-label');
        const elDedHead = document.getElementById('disp-ded-savings-headline');
        if (selectedDeductible === 200 || selectedDeductible === 300) {
          const savFrom500 = Math.max(0, curDedFee26 - fee500);
          if (elDedSavingsLabel) elDedSavingsLabel.textContent = 'Annual MPI Savings by Choosing $500 Buy-Down';
          if (elDedHead) elDedHead.textContent = `Save ${fmt(savFrom500)} / year (${fmtDec(savFrom500 / 12)}/mo)`;
        } else if (selectedDeductible === 500) {
          if (elDedSavingsLabel) elDedSavingsLabel.textContent = 'Total Loss Deductible with CAP';
          if (elDedHead) elDedHead.textContent = '$0.00 Out of Pocket on Total Loss';
        } else {
          if (elDedSavingsLabel) elDedSavingsLabel.textContent = 'CAP Deductible Reimbursement';
          if (elDedHead) elDedHead.textContent = '$500 Write-Off / $250 Repair Benefit';
        }

        const elThDedName = document.getElementById('th-mpi-ded-name');
        if (elThDedName) {
          elThDedName.textContent = selectedDeductible === 1000
            ? `MPI with $1,000 Base Deductible Alone`
            : `MPI with $${selectedDeductible} Deductible Buy-Down`;
        }

        const elThCapCol = document.getElementById('th-cap-col-name');
        if (elThCapCol) {
          if (selectedDeductible === 200 || selectedDeductible === 300 || selectedDeductible === 500) {
            elThCapCol.textContent = `MPI $500 Deductible + Companion Asset Protection (CAP)`;
          } else if (selectedDeductible === 750) {
            elThCapCol.textContent = `MPI $750 Deductible + Companion Asset Protection (CAP)`;
          } else {
            elThCapCol.textContent = `MPI $1,000 Base Deductible + Companion Asset Protection (CAP)`;
          }
        }

        // Table Row 1: Annual MPI Deductible Fee
        const elTableDedFee = document.getElementById('table-mpi-ded-fee');
        if (elTableDedFee) elTableDedFee.textContent = curDedFee26 > 0 ? `+${fmt(curDedFee26)} / year` : `$0.00 / year`;

        const elTableDedPeriod = document.getElementById('table-mpi-ded-period');
        if (elTableDedPeriod) {
          elTableDedPeriod.textContent = curDedFee26 > 0
            ? `(+${fmtDec(curDedFee26 / 12)} / month on MPI)`
            : `(Included in Basic Autopac)`;
        }

        const elTableCapDedFee = document.getElementById('table-cap-ded-fee');
        const elTableCapDedSub = document.getElementById('table-cap-ded-fee-sub');
        if (selectedDeductible === 200 || selectedDeductible === 300 || selectedDeductible === 500) {
          if (elTableCapDedFee) elTableCapDedFee.textContent = `+${fmt(fee500)} / year`;
          if (elTableCapDedSub) elTableCapDedSub.textContent = `(+${fmtDec(fee500 / 12)} / month on MPI • $500 buy-down)`;
        } else if (selectedDeductible === 750) {
          if (elTableCapDedFee) elTableCapDedFee.textContent = `+${fmt(fee750)} / year`;
          if (elTableCapDedSub) elTableCapDedSub.textContent = `(+${fmtDec(fee750 / 12)} / month on MPI • $750 buy-down)`;
        } else {
          if (elTableCapDedFee) elTableCapDedFee.textContent = `$0.00 Extra Fee`;
          if (elTableCapDedSub) elTableCapDedSub.textContent = `(Included in Basic Autopac)`;
        }

        const elTableDedPill = document.getElementById('table-ded-savings-pill');
        if (elTableDedPill) {
          if (selectedDeductible === 200 || selectedDeductible === 300) {
            const sav = Math.max(0, curDedFee26 - fee500);
            elTableDedPill.textContent = `Save ${fmt(sav)} / year on MPI`;
          } else if (selectedDeductible === 500) {
            elTableDedPill.textContent = `$0 Deductible on Write-Off`;
          } else if (selectedDeductible === 750) {
            elTableDedPill.textContent = `CAP Reimburses $500`;
          } else {
            elTableDedPill.textContent = `Zero Buy-Down Fee • Reimburses $500 on Write-Off / $250 on Repairs`;
          }
        }

        // Table Row 2: Total Loss (Vehicle Written Off)
        const elTableMpiLossOop = document.getElementById('table-mpi-ded-loss-oop');
        if (elTableMpiLossOop) elTableMpiLossOop.textContent = `Client Pays $${selectedDeductible.toLocaleString()}`;

        const elTableCapLossOop = document.getElementById('table-cap-ded-loss-oop');
        const elTableCapLossWin = document.getElementById('table-cap-ded-loss-win');

        if (selectedDeductible === 200 || selectedDeductible === 300 || selectedDeductible === 500) {
          if (elTableCapLossOop) elTableCapLossOop.textContent = `Client Pays $0.00`;
          if (elTableCapLossWin) elTableCapLossWin.textContent = `CAP Saves You $${selectedDeductible.toLocaleString()}!`;
        } else if (selectedDeductible === 750) {
          if (elTableCapLossOop) elTableCapLossOop.textContent = `Client Pays $250`;
          if (elTableCapLossWin) elTableCapLossWin.textContent = `CAP Saves You $500!`;
        } else { // 1000
          if (elTableCapLossOop) elTableCapLossOop.textContent = `Client Pays $500`;
          if (elTableCapLossWin) elTableCapLossWin.textContent = `CAP Saves You $500!`;
        }

        // Table Row 3: Partial Loss (Repairable Claims & Windshields)
        const elTableMpiPartOop = document.getElementById('table-mpi-ded-part-oop');
        if (elTableMpiPartOop) elTableMpiPartOop.textContent = `Client Pays $${selectedDeductible.toLocaleString()}`;

        const elTableCapPartOop = document.getElementById('table-cap-ded-part-oop');
        const elTableCapPartSub = document.getElementById('table-cap-ded-part-subdesc');
        const elTableCapPartWin = document.getElementById('table-cap-ded-part-win');

        if (selectedDeductible === 200) {
          const sav200 = Math.max(0, fee200 - fee500);
          if (elTableCapPartOop) elTableCapPartOop.textContent = `Client Pays $250`;
          if (elTableCapPartSub) elTableCapPartSub.textContent = `$500 MPI minus $250 CAP reimbursement (includes windshield claims)`;
          if (elTableCapPartWin) elTableCapPartWin.innerHTML = `<strong>Only $50 difference</strong>, while saving <strong>${fmt(sav200)}/yr</strong> on MPI!`;
        } else if (selectedDeductible === 300) {
          const sav300 = Math.max(0, fee300 - fee500);
          if (elTableCapPartOop) elTableCapPartOop.textContent = `Client Pays $250`;
          if (elTableCapPartSub) elTableCapPartSub.textContent = `$500 MPI minus $250 CAP reimbursement (includes windshield claims)`;
          if (elTableCapPartWin) elTableCapPartWin.innerHTML = `<strong>CAP is $50 cheaper out-of-pocket</strong> ($250 vs $300), AND you save <strong>${fmt(sav300)}/yr</strong> on MPI!`;
        } else if (selectedDeductible === 500) {
          if (elTableCapPartOop) elTableCapPartOop.textContent = `Client Pays $250`;
          if (elTableCapPartSub) elTableCapPartSub.textContent = `$500 MPI minus $250 CAP reimbursement (includes windshield claims)`;
          if (elTableCapPartWin) elTableCapPartWin.innerHTML = `<strong>Cuts deductible in half</strong> ($250 saved on every repair/windshield claim)`;
        } else if (selectedDeductible === 750) {
          if (elTableCapPartOop) elTableCapPartOop.textContent = `Client Pays $500`;
          if (elTableCapPartSub) elTableCapPartSub.textContent = `$750 MPI minus $250 CAP reimbursement (includes windshield claims)`;
          if (elTableCapPartWin) elTableCapPartWin.innerHTML = `<strong>Saves $250 out-of-pocket</strong> on every partial loss or windshield claim`;
        } else { // 1000
          if (elTableCapPartOop) elTableCapPartOop.textContent = `Client Pays $750`;
          if (elTableCapPartSub) elTableCapPartSub.textContent = `$1,000 base MPI minus $250 CAP reimbursement (includes windshield claims)`;
          if (elTableCapPartWin) elTableCapPartWin.innerHTML = `<strong>Saves $250 out-of-pocket</strong> on repairs & windshields without paying MPI fees!`;
        }

        // Summary Callout Box Narrative
        const elStratSummary = document.getElementById('disp-strategy-summary');
        if (elStratSummary) {
          if (selectedDeductible === 200) {
            const sav200 = Math.max(0, fee200 - fee500);
            elStratSummary.innerHTML = `
              If you pay MPI for a $200 deductible, you are paying <strong>${fmt(fee200)} extra every single year</strong> (${fmtDec(fee200 / 12)}/month) to buy down from MPI's $1,000 base deductible. 
              By choosing MPI's $500 deductible buy-down (${fmt(fee500)}/yr) and adding Companion Asset Protection (CAP), you save <strong>${fmt(sav200)}/year (${fmtDec(sav200 / 12)}/mo)</strong> on your insurance bill. 
              In the event of a total loss write-off, CAP reimburses your entire $500 deductible—leaving you with <strong>$0 out of pocket</strong> (which is $200 cheaper than paying MPI for a $200 deductible). 
              Even on a partial loss or windshield claim, you only pay $250 out of pocket (a tiny $50 difference from $200), which is paid for many times over by your <strong>${fmt(sav200)}</strong> annual premium savings! 
              <em>(Alternatively, you can choose MPI's $1,000 base deductible to pocket the full ${fmt(fee200)}/yr while CAP reimburses $500 on a write-off).</em>
            `;
          } else if (selectedDeductible === 300) {
            const sav300 = Math.max(0, fee300 - fee500);
            elStratSummary.innerHTML = `
              If you pay MPI for a $300 deductible, you are paying <strong>${fmt(fee300)} extra every year</strong> (${fmtDec(fee300 / 12)}/month) to buy down from MPI's $1,000 base deductible. 
              By choosing MPI's $500 deductible buy-down (${fmt(fee500)}/yr) with Companion Asset Protection (CAP), you save <strong>${fmt(sav300)}/year (${fmtDec(sav300 / 12)}/mo)</strong> on insurance. 
              On a total loss, CAP reimburses your entire $500 deductible—leaving you with <strong>$0 out of pocket</strong> (saving $300). 
              On a partial loss or windshield claim, you pay only $250 out of pocket—which is actually <strong>$50 cheaper</strong> than MPI's $300 deductible, on top of saving <strong>${fmt(sav300)}/year</strong> in premiums!
            `;
          } else if (selectedDeductible === 500) {
            elStratSummary.innerHTML = `
              With MPI's $500 deductible buy-down (${fmt(fee500)}/yr), you normally face a $500 bill on any write-off or repair. 
              With Companion Asset Protection (CAP), a total loss triggers a full <strong>$500 deductible reimbursement—reducing your out-of-pocket to $0.00</strong> (saving you $500). 
              On partial loss claims (including body shop repairs and windshield replacements), CAP reimburses $250, cutting your out-of-pocket deductible in half to just <strong>$250</strong>! 
              <em>(Tip: You can also choose MPI's Base $1,000 deductible with CAP to pocket ${fmt(fee500)}/year in insurance savings while CAP pays $500 on total loss).</em>
            `;
          } else if (selectedDeductible === 750) {
            elStratSummary.innerHTML = `
              With MPI's $750 deductible buy-down (${fmt(fee750)}/yr), an accident claim costs you $750. 
              Companion Asset Protection (CAP) reimburses <strong>$500 on a total loss</strong> (bringing your cost down to just $250), and reimburses <strong>$250 on partial losses and windshield replacements</strong> (reducing your out-of-pocket to $500)!
            `;
          } else { // 1000
            elStratSummary.innerHTML = `
              MPI's standard base deductible is $1,000 (included in basic Autopac at $0 extra fee). By keeping the $1,000 base deductible, you pay <strong>$0 in buy-down fees to MPI</strong>. 
              Adding Companion Asset Protection (CAP) provides built-in deductible protection: CAP reimburses <strong>$500 on a total loss</strong> (slashing your out-of-pocket to $500) and reimburses <strong>$250 on partial losses and windshield replacements</strong>!
            `;
          }
        }
        // Update Rate Lock Closer Banner
        const diffTotal = total26 - total25;
        const pctIncrease = total25 > 0 ? ((diffTotal / total25) * 100) : 0;
        const elIncHead = document.getElementById('disp-increase-headline');
        if (elIncHead) {
          elIncHead.textContent = pctIncrease > 0 
            ? `+${pctIncrease.toFixed(1)}% Approved MPI Rate Increase` 
            : 'Approved PUB Rate Increases';
        }

        const elRateLockYears = document.getElementById('disp-ratelock-years');
        if (elRateLockYears) {
          elRateLockYears.textContent = `up to ${maxAllowedYears} years`;
        }

        // Persist current state to localStorage (local to this computer/device)
        saveState();
      }

      // Restore user's previous inputs from localStorage if available
      loadState();

      // Initial run
      recalculate();
    });
  </script>
</body>
</html>
