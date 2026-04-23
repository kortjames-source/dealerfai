<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/csrf.php';
include 'auth.php';
require_once __DIR__ . '/includes/theme_head.php';
include 'db.php';
require_once __DIR__ . '/helpers/session_utils.php';
require_once __DIR__ . '/helpers/customer_types.php';

$roles = load_session_roles();
$isAdmin = in_array('Admin', $roles, true);
$adminAlertCount = $isAdmin ? get_admin_alert_count($db) : 0;
$accessible_orgs = get_accessible_organizations();

// Theme settings
$org = get_effective_organization();
$user_id = $_SESSION['user_id'];
$theme = ['logo' => '', 'color' => '#0066cc'];

if ($isAdmin && !empty($_SESSION['deal_draft']['organization'])) {
  $org = (int)$_SESSION['deal_draft']['organization'];
}
if ($isAdmin && !$org && !empty($accessible_orgs)) {
  $org = (int)$accessible_orgs[0];
}

if ($org) {
  $stmt = $db->prepare("SELECT logo_url, theme_variant FROM organizations WHERE id = ?");
  $stmt->execute([$org]);
  $orgData = $stmt->fetch(PDO::FETCH_ASSOC);
  if ($orgData) {
    $theme['logo'] = $orgData['logo_url'] ?? '';
    $theme['color'] = $orgData['theme_variant'] === 'jlr' ? '#4a4a4a' : (in_array($orgData['theme_variant'], ['land_rover', 'jaguar'], true) ? '#1A1A1A' : '#0066cc');
  }
}

// Load accessible organizations for admin selection
$orgOptions = [];
if ($isAdmin) {
  $orgStmt = $db->query("SELECT id, name FROM organizations ORDER BY name ASC");
  $orgOptions = $orgStmt->fetchAll(PDO::FETCH_ASSOC);
} elseif (!empty($accessible_orgs)) {
  $placeholders = implode(',', array_fill(0, count($accessible_orgs), '?'));
  $orgStmt = $db->prepare("SELECT id, name FROM organizations WHERE id IN ($placeholders) ORDER BY name");
  $orgStmt->execute($accessible_orgs);
  $orgOptions = $orgStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch role-based users
$orgClause = $isAdmin ? '' : ' AND organization = ?';
$orgParams = $isAdmin ? [] : [$org];

$fin = $db->prepare('SELECT id, full_name FROM users WHERE JSON_CONTAINS(role, \'"Finance Manager"\')' . $orgClause . ' ORDER BY full_name');
$fin->execute($orgParams);
$financeManagers = $fin->fetchAll(PDO::FETCH_ASSOC);

$sm = $db->prepare('SELECT id, full_name FROM users WHERE JSON_CONTAINS(role, \'"Sales Manager"\')' . $orgClause . ' ORDER BY full_name');
$sm->execute($orgParams);
$salesManagers = $sm->fetchAll(PDO::FETCH_ASSOC);

$sa = $db->prepare('SELECT id, full_name FROM users WHERE JSON_CONTAINS(role, \'"Salesperson"\')' . $orgClause . ' ORDER BY full_name');
$sa->execute($orgParams);
$salesAdvisors = $sa->fetchAll(PDO::FETCH_ASSOC);

// Handle Step 1 form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!dealerfai_csrf_validate($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    die('Invalid request.');
  }

  $action = $_POST['action'] ?? '';
  $selectedOrg = $org;

  if ($isAdmin) {
    $requestedOrg = (int)($_POST['organization'] ?? 0);
    if ($requestedOrg > 0) {
      $selectedOrg = $requestedOrg;
    }
  }

  $customerType = normalize_customer_type($_POST['customer_type'] ?? 'personal');
  $businessId = (int)($_POST['business_id'] ?? 0);
  $businessName = trim((string)($_POST['business_name'] ?? ''));
  $coAppRequired = !empty($_POST['co_app_required']) ? 1 : 0;
  $coAppName = trim((string)($_POST['co_app_name'] ?? ''));
  if (!$coAppRequired) {
    $coAppName = '';
  }

  $_SESSION['deal_draft'] = [
    'deal_number'         => $_POST['deal_number'],
    'customer_number'     => $_POST['customer_number'],
    'customer_name'       => $_POST['customer_name'],
    'customer_phone'      => $_POST['customer_phone'],
    'customer_email'      => $_POST['customer_email'],
    'co_app_required'     => $coAppRequired,
    'co_app_name'         => $coAppName,
    'province'            => $_POST['province'],
    'sales_advisor_id'    => $_POST['sales_advisor_id'] ?: null,
    'finance_manager_id'  => $_POST['finance_manager_id'] ?: null,
    'sales_manager_id'    => $_POST['sales_manager_id'] ?: null,
    'organization'        => $selectedOrg,
    'created_by'          => $user_id,
    'customer_type'       => $customerType,
    // Business buyer context (only used when customer_type != personal).
    'business_id'         => $businessId > 0 ? $businessId : null,
    'business_name'       => $businessName,
    'draft_csrf_token'    => bin2hex(random_bytes(32)),
  ];
  if ($action === 'switch_org') {
    header("Location: create_deal");
  } else {
    header("Location: create_deal_step2");
  }
  exit;
}

$businesses = [];
$csrfToken = dealerfai_csrf_get_token();
$hasBusinessesTable = false;
try {
  $tbl = $db->prepare("
    SELECT COUNT(*)
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'businesses'
  ");
  $tbl->execute();
  $hasBusinessesTable = (int)$tbl->fetchColumn() > 0;
} catch (PDOException $e) {
  $hasBusinessesTable = false;
}

if ($hasBusinessesTable && $org) {
  try {
    $bizStmt = $db->prepare("SELECT id, name FROM businesses WHERE organization_id = ? ORDER BY name ASC");
    $bizStmt->execute([(int)$org]);
    $businesses = $bizStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (PDOException $e) {
    $businesses = [];
  }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <?php include __DIR__ . "/includes/head_favicon.php"; ?>
<meta charset="UTF-8">
  <title>Create Deal - Step 1</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php dealerfai_theme_head($theme ?? null); ?>
  <style nonce="<?= dealerfai_csp_nonce() ?>">
    body { margin: 0; font-family: "Segoe UI", sans-serif; background: #f4f6f8; color: #111111; }
    header {
      background-color: <?= htmlspecialchars($theme['color']) ?>;
      color: white;
      padding: 30px 40px;
      text-align: center;
      position: relative;
    }
    header img {
      max-width: 480px;
      max-height: 180px;
      height: auto;
      display: block;
      margin: 0 auto 10px;
    }
    nav {
      background-color: <?= htmlspecialchars($theme['color']) ?>;
      padding: 12px;
      text-align: center;
    }
    nav a {
      color: white;
      margin: 0 20px;
      text-decoration: none;
      font-weight: bold;
    }
    nav a:hover { text-decoration: underline; }
    main { padding: 30px 40px; }
    .card {
      background: white;
      padding: 30px;
      max-width: 700px;
      margin: 30px auto;
      border-radius: 10px;
      box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    }
    label {
      display: block;
      margin-top: 15px;
      font-weight: bold;
    }
    input, select {
      width: 100%;
      padding: 10px;
      margin-top: 5px;
      border: 1px solid #ccc;
      border-radius: 4px;
    }
    .btn {
      background: <?= htmlspecialchars($theme['color']) ?>;
      color: white;
      padding: 12px 20px;
      border: none;
      border-radius: 4px;
      margin-top: 20px;
      cursor: pointer;
      font-size: 16px;
    }
    .btn:hover { opacity: 0.9; }
    footer {
      background-color: <?= htmlspecialchars($theme['color']) ?>;
      color: white;
      text-align: center;
      padding: 16px;
      font-size: 14px;
      margin-top: 60px;
    }
    .logout {
      position: absolute;
      right: 20px;
      top: 20px;
      display: flex;
      gap: 12px;
      align-items: center;
    }
    .logout a {
      color: #ccc;
      font-size: 14px;
      text-decoration: none;
    }
    .logout a:hover { color: white; }
    .badge {
      display: inline-block;
      min-width: 18px;
      padding: 2px 8px;
      border-radius: 999px;
      background: #d7263d;
      color: #fff;
      font-size: 12px;
      font-weight: bold;
      text-align: center;
      margin-left: 6px;
    }
  </style>
</head>
<body class="dashboard-wrapper">
  <!-- Sidebar -->
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
      <a href="create_deal" class="sidebar-link active">
        <i class="fa-solid fa-plus-circle"></i> Create Deal
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
        <a href="admin_tools" class="sidebar-link">
          <i class="fa-solid fa-wrench"></i> Admin Tools
        </a>
      <?php endif; ?>
    </nav>
    <div style="padding: 1.5rem; border-top: 1px solid rgba(255,255,255,0.1);">
      <p style="font-size: 0.75rem; color: rgba(255,255,255,0.4); margin: 0;">DealerFAI v2.0</p>
    </div>
  </aside>

  <!-- Main Content -->
  <div class="main-container">
    <header class="top-bar">
      <div class="breadcrumb" style="font-weight: 600; color: #64748b;">
        DealerFAI <i class="fa-solid fa-chevron-right" style="font-size: 0.75rem; margin: 0 0.5rem; opacity: 0.5;"></i> Create Deal
      </div>
      <div style="display: flex; align-items: center; gap: 1.5rem;">
        <?php if ($isAdmin): ?>
          <a href="admin_error_alerts" style="position: relative; color: #64748b;">
            <i class="fa-solid fa-bell" style="font-size: 1.25rem;"></i>
            <?php if ($adminAlertCount > 0): ?>
              <span style="position: absolute; top: -5px; right: -5px; background: #ef4444; color: white; font-size: 10px; padding: 2px 5px; border-radius: 10px; font-weight: 700;"><?= $adminAlertCount ?></span>
            <?php endif; ?>
          </a>
        <?php endif; ?>
        <div style="display: flex; align-items: center; gap: 0.75rem;">
          <div style="text-align: right;">
            <div style="font-size: 0.875rem; font-weight: 700; color: #0f172a;"><?= htmlspecialchars($_SESSION['full_name'] ?? 'User') ?></div>
            <a href="logout" style="font-size: 0.75rem; color: #64748b; text-decoration: none;">Log Out</a>
          </div>
          <div style="width: 40px; height: 40px; background: var(--brand-color); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700;">
            <?= strtoupper(substr($_SESSION['full_name'] ?? 'U', 0, 1)) ?>
          </div>
        </div>
      </div>
    </header>

    <main class="page-content" style="max-width: 800px;">
      <div style="margin-bottom: 2rem;">
        <h1 class="text-gradient" style="margin-bottom: 0.5rem; display: inline-block;">Create New Deal</h1>
        <p class="text-muted">Start by entering the primary customer and deal information.</p>
      </div>

      <div style="display: flex; gap: 0.5rem; margin-bottom: 2rem;">
        <div style="width: 12px; height: 12px; border-radius: 50%; background: var(--brand-color);"></div>
        <div style="width: 12px; height: 12px; border-radius: 50%; background: #e2e8f0;"></div>
        <div style="width: 12px; height: 12px; border-radius: 50%; background: #e2e8f0;"></div>
      </div>

      <form method="post" id="create-deal-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <div class="card" style="margin: 0; max-width: 100%;">
          <h3 style="margin-top: 0; margin-bottom: 1.5rem; font-size: 1.125rem; font-weight: 800; color: #0f172a; border-bottom: 1px solid #f1f5f9; padding-bottom: 0.75rem;">Step 1: Customer Details</h3>

          <?php if ($isAdmin && !empty($orgOptions)): ?>
            <div style="margin-bottom: 1.5rem;">
              <label for="organization" style="margin-top: 0;">Store Location</label>
              <select name="organization" id="organization" onchange="switchOrganization()" required>
                <?php foreach ($orgOptions as $orgOption): ?>
                  <option value="<?= $orgOption['id'] ?>" <?= ($orgOption['id'] == $org) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($orgOption['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          <?php endif; ?>

          <input type="hidden" name="action" id="form_action" value="">

          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
            <div style="margin-bottom: 1rem;">
              <label for="deal_number">Deal Number</label>
              <input type="text" name="deal_number" id="deal_number" value="<?= htmlspecialchars($_SESSION['deal_draft']['deal_number'] ?? '') ?>" required>
            </div>
            <div style="margin-bottom: 1rem;">
              <label for="customer_number">Customer Number (Optional)</label>
              <input type="text" name="customer_number" id="customer_number" value="<?= htmlspecialchars($_SESSION['deal_draft']['customer_number'] ?? '') ?>">
            </div>
          </div>

          <div style="margin-bottom: 1.5rem;">
            <label for="customer_name" id="customer_name_label">Customer Name</label>
            <input type="text" id="customer_name" name="customer_name" value="<?= htmlspecialchars($_SESSION['deal_draft']['customer_name'] ?? '') ?>" required>
          </div>

          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
            <div style="margin-bottom: 1rem;">
              <label for="customer_phone">Phone Number</label>
              <input type="tel" name="customer_phone" id="customer_phone" value="<?= htmlspecialchars($_SESSION['deal_draft']['customer_phone'] ?? '') ?>">
            </div>
            <div style="margin-bottom: 1rem;">
              <label for="customer_email">Email Address</label>
              <input type="email" name="customer_email" id="customer_email" value="<?= htmlspecialchars($_SESSION['deal_draft']['customer_email'] ?? '') ?>">
            </div>
          </div>

          <div style="margin-bottom: 1.5rem;">
            <label for="customer_type">Customer Type</label>
            <?php $selectedCustomerType = normalize_customer_type($_SESSION['deal_draft']['customer_type'] ?? 'personal'); ?>
            <select name="customer_type" id="customer_type" onchange="toggleCustomerTypeUi()" required>
              <option value="personal" <?= $selectedCustomerType === 'personal' ? 'selected' : '' ?>>Personal</option>
              <option value="professional" <?= $selectedCustomerType === 'professional' ? 'selected' : '' ?>>Professional (Business Buyer)</option>
              <option value="commercial" <?= $selectedCustomerType === 'commercial' ? 'selected' : '' ?>>Commercial (Business)</option>
            </select>
          </div>

          <div id="business_block" style="display:none; margin-bottom: 1.5rem; padding: 1.25rem; border-radius: var(--radius-md); background: #f8fafc; border: 1px solid #e2e8f0;">
            <div style="font-weight: 700; color: #1e293b; margin-bottom: 1rem; font-size: 0.875rem;">Business Details</div>

            <?php if ($hasBusinessesTable): ?>
              <div style="margin-bottom: 1rem;">
                <label for="business_id">Select Existing Business</label>
                <select name="business_id" id="business_id" onchange="toggleBusinessNameRequired()">
                  <option value="">-- New Business --</option>
                  <?php foreach ($businesses as $biz): ?>
                    <option value="<?= (int)$biz['id'] ?>" <?= (int)($_SESSION['deal_draft']['business_id'] ?? 0) === (int)$biz['id'] ? 'selected' : '' ?>>
                      <?= htmlspecialchars((string)$biz['name']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            <?php endif; ?>

            <div style="margin-bottom: 0;">
              <label for="business_name">Business Name</label>
              <input type="text" name="business_name" id="business_name" value="<?= htmlspecialchars($_SESSION['deal_draft']['business_name'] ?? '') ?>">
            </div>
          </div>

          <div style="margin-bottom: 1.5rem;">
            <label style="display: flex; align-items: center; gap: 0.75rem; cursor: pointer; user-select: none;">
              <input type="checkbox" id="co_app_required" name="co_app_required" value="1" <?= !empty($_SESSION['deal_draft']['co_app_required']) ? 'checked' : '' ?> onchange="toggleCoSignerUi()" style="width: auto; margin-top: 0;">
              <span>Include a Co-Signer on this deal</span>
            </label>
          </div>

          <div id="co_app_name_wrap" style="display: none; margin-bottom: 1.5rem;">
            <label for="co_app_name">Co-Signer Name</label>
            <input type="text" id="co_app_name" name="co_app_name" value="<?= htmlspecialchars($_SESSION['deal_draft']['co_app_name'] ?? '') ?>">
          </div>
          
          <div style="margin-bottom: 1.5rem;">
            <label for="province">Province / Territory</label>
            <select name="province" id="province" required>
              <option value="">-- Select Province --</option>
              <?php
                $provinces = ['AB','BC','MB','NB','NL','NS','NT','NU','ON','PE','QC','SK','YT'];
                foreach ($provinces as $code):
                  $selected = ($_SESSION['deal_draft']['province'] ?? '') === $code ? 'selected' : '';
                  echo "<option value='$code' $selected>$code</option>";
                endforeach;
              ?>
            </select>
          </div>

          <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.5rem; margin-top: 2rem; border-top: 1px solid #f1f5f9; padding-top: 1.5rem;">
            <div>
              <label for="finance_manager_id">Finance Manager</label>
              <select name="finance_manager_id" id="finance_manager_id">
                <option value="">-- Not Assigned --</option>
                <?php foreach ($financeManagers as $fm): ?>
                  <option value="<?= $fm['id'] ?>" <?= ($fm['id'] == ($_SESSION['deal_draft']['finance_manager_id'] ?? '')) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($fm['full_name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label for="sales_manager_id">Sales Manager</label>
              <select name="sales_manager_id" id="sales_manager_id">
                <option value="">-- Not Assigned --</option>
                <?php foreach ($salesManagers as $sm): ?>
                  <option value="<?= $sm['id'] ?>" <?= ($sm['id'] == ($_SESSION['deal_draft']['sales_manager_id'] ?? '')) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($sm['full_name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label for="sales_advisor_id">Sales Advisor</label>
              <select name="sales_advisor_id" id="sales_advisor_id">
                <option value="">-- Not Assigned --</option>
                <?php foreach ($salesAdvisors as $sa): ?>
                  <option value="<?= $sa['id'] ?>" <?= ($sa['id'] == ($_SESSION['deal_draft']['sales_advisor_id'] ?? '')) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($sa['full_name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div style="margin-top: 2.5rem; text-align: right;">
            <button type="submit" class="btn btn-primary" style="min-width: 200px;">Save & Next Step <i class="fa-solid fa-arrow-right" style="margin-left: 0.5rem;"></i></button>
          </div>
        </div>
      </form>
    </main>

    <footer style="background: white; border-top: 1px solid #e2e8f0; color: #64748b; padding: 1.5rem; text-align: center; position: static;">
      &copy; <?= date("Y") ?> DealerFAI. All rights reserved.
    </footer>
  </div>

  <script nonce="<?= dealerfai_csp_nonce() ?>">
    function switchOrganization() {
      const action = document.getElementById('form_action');
      const form = document.getElementById('create-deal-form');
      if (action) { action.value = 'switch_org'; }
      if (form) {
        form.noValidate = true;
        form.submit();
      }
    }

    function toggleCustomerTypeUi() {
      const type = document.getElementById('customer_type')?.value || 'personal';
      const block = document.getElementById('business_block');
      const nameLabel = document.getElementById('customer_name_label');
      const customerName = document.getElementById('customer_name');
      if (block) {
        block.style.display = (type === 'personal') ? 'none' : 'block';
      }
      if (nameLabel) {
        nameLabel.textContent = (type === 'personal') ? 'Customer Name' : 'Primary Contact Name';
      }
      if (customerName) {
        customerName.placeholder = (type === 'personal') ? '' : 'Primary contact at the business';
      }
      toggleBusinessNameRequired();
    }

    function toggleBusinessNameRequired() {
      const type = document.getElementById('customer_type')?.value || 'personal';
      const bizName = document.getElementById('business_name');
      const bizId = document.getElementById('business_id');
      if (!bizName) return;
      const hasExisting = bizId && bizId.value;
      if (type !== 'personal' && !hasExisting) {
        bizName.required = true;
      } else {
        bizName.required = false;
      }
    }

    function toggleCoSignerUi() {
      const requiredCheckbox = document.getElementById('co_app_required');
      const wrap = document.getElementById('co_app_name_wrap');
      const nameInput = document.getElementById('co_app_name');
      const enabled = !!(requiredCheckbox && requiredCheckbox.checked);
      if (wrap) {
        wrap.style.display = enabled ? 'block' : 'none';
      }
      if (nameInput) {
        nameInput.required = enabled;
        if (!enabled) { nameInput.value = ''; }
      }
    }

    document.addEventListener('DOMContentLoaded', function () {
      toggleCustomerTypeUi();
      toggleCoSignerUi();
    });
  </script>
</body>
</html>

