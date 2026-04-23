<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/security_headers.php';
require_once __DIR__ . '/includes/theme_head.php';
require_once __DIR__ . '/includes/csrf.php';
include 'db.php';
require_once __DIR__ . '/helpers/session_utils.php';
require_once __DIR__ . '/helpers/organization_question_config.php';
require_once __DIR__ . '/helpers/billing_helpers.php';

if (!function_exists('get_default_package_menu_labels')) {
    function get_default_package_menu_labels(): array {
        return [
            'All-In Coverage',
            'Balanced Protection',
            'Essential Safeguards',
            'Minimal Start',
        ];
    }
}

$roles = load_session_roles();
if (!in_array('Admin', $roles, true)) {
  echo "<p style='color:red;'>Access denied. Admins only.</p>";
  exit;
}
$adminAlertCount = get_admin_alert_count($db);
$csrfToken = dealerfai_csrf_get_token();

$hasPackageLabelsColumn = organization_column_exists($db, 'package_menu_labels');
$hasOrgVoiceColumn = organization_column_exists($db, 'org_voice_prompt');
$hasStoreVoiceColumn = organization_column_exists($db, 'store_voice_prompt');
$hasCreditQuestionsColumn = organization_column_exists($db, 'credit_app_question_config');
$hasPreferredProviderColumn = organization_column_exists($db, 'preferred_provider');
$hasAccessoriesToggleColumn = organization_column_exists($db, 'accessories_enabled');
$hasAiReasoningToggleColumn = organization_column_exists($db, 'ai_reasoning_enabled');
$hasCapWithGapColumn = organization_column_exists($db, 'show_cap_with_gap');
$hasServiceToggleColumn = organization_column_exists($db, 'service_enabled');
$hasPlanTierColumn = organization_column_exists($db, 'plan_tier');
$hasDealRateOverrideColumn = organization_column_exists($db, 'deal_rate_override');
$hasAccessoryRateOverrideColumn = organization_column_exists($db, 'accessory_rate_override');
$hasServiceRateOverrideColumn = organization_column_exists($db, 'service_rate_override');
$hasLeaseCapPercentColumn = organization_column_exists($db, 'lease_msrp_cap_percent');
$hasFinanceCapPercentColumn = organization_column_exists($db, 'finance_msrp_cap_percent');
$creditQuestionCatalog = get_credit_app_question_catalog();

$billingPlans = [];
if (table_exists($db, 'billing_plans')) {
  try {
    $billingPlans = $db->query("SELECT code, name FROM billing_plans WHERE is_active = 1 ORDER BY sort_order ASC, name ASC")
      ->fetchAll(PDO::FETCH_ASSOC);
  } catch (PDOException $e) {
    $billingPlans = [];
  }
}
if (empty($billingPlans)) {
  $billingPlans = [
    ['code' => 'core', 'name' => 'Core'],
    ['code' => 'plus', 'name' => 'Plus'],
    ['code' => 'premium', 'name' => 'Premium'],
  ];
}

$orgId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$org = null;
$errors = [];

$orgOptions = $db->query("SELECT id, name FROM organizations ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$orgNames = [];
foreach ($orgOptions as $option) {
  $orgNames[(int)$option['id']] = $option['name'];
}

if ($orgId > 0) {
  $stmt = $db->prepare("SELECT * FROM organizations WHERE id = ?");
  $stmt->execute([$orgId]);
  $org = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$org) {
    $errors[] = "Organization not found.";
    $orgId = 0;
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!dealerfai_csrf_validate($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    $errors[] = "Invalid request token.";
  } else {
  $name = trim($_POST['name'] ?? '');
  $logic_type = $_POST['logic_type'] ?? 'luxury';
  $theme_variant = $_POST['theme_variant'] ?? 'default';
  $org_kind = $_POST['org_kind'] ?? 'store';
  if (!in_array($org_kind, ['store', 'group'], true)) {
    $org_kind = 'store';
  }
  $parent_org_id = isset($_POST['parent_org_id']) && $_POST['parent_org_id'] !== '' ? (int)$_POST['parent_org_id'] : null;
  $package_labels_input = $hasPackageLabelsColumn ? trim($_POST['package_menu_labels'] ?? '') : null;
  $org_voice_input = $hasOrgVoiceColumn ? trim($_POST['org_voice_prompt'] ?? '') : null;
  $store_voice_input = $hasStoreVoiceColumn ? trim($_POST['store_voice_prompt'] ?? '') : null;
  $credit_questions_input = $hasCreditQuestionsColumn ? ($_POST['credit_app_questions'] ?? []) : null;
  $preferred_provider_input = $hasPreferredProviderColumn ? trim($_POST['preferred_provider'] ?? '') : null;
  $accessories_toggle_input = $hasAccessoriesToggleColumn ? ($_POST['accessories_enabled'] ?? 'inherit') : null;
  $ai_reasoning_toggle_input = $hasAiReasoningToggleColumn ? ($_POST['ai_reasoning_enabled'] ?? 'inherit') : null;
  $cap_with_gap_input = $hasCapWithGapColumn ? ($_POST['show_cap_with_gap'] ?? 'inherit') : null;
  $service_toggle_input = $hasServiceToggleColumn ? ($_POST['service_enabled'] ?? 'inherit') : null;
  $plan_tier_input = $hasPlanTierColumn ? trim($_POST['plan_tier'] ?? 'core') : null;
  $deal_rate_override_input = $hasDealRateOverrideColumn ? trim($_POST['deal_rate_override'] ?? '') : null;
  $accessory_rate_override_input = $hasAccessoryRateOverrideColumn ? trim($_POST['accessory_rate_override'] ?? '') : null;
  $service_rate_override_input = $hasServiceRateOverrideColumn ? trim($_POST['service_rate_override'] ?? '') : null;
  $lease_cap_percent_input = $hasLeaseCapPercentColumn ? trim($_POST['lease_msrp_cap_percent'] ?? '') : null;
  $finance_cap_percent_input = $hasFinanceCapPercentColumn ? trim($_POST['finance_msrp_cap_percent'] ?? '') : null;
  $remove_logo = !empty($_POST['remove_logo']);

  if ($name === '') {
    $errors[] = "Organization name is required.";
  }

  if ($org_kind === 'group') {
    $parent_org_id = null;
  } elseif ($parent_org_id !== null) {
    if ($parent_org_id === $orgId || !array_key_exists($parent_org_id, $orgNames)) {
      $parent_org_id = null;
    }
  }

  $logo_path = $org['logo_url'] ?? null;
  if ($remove_logo) {
    $logo_path = null;
  }
  if (!empty($_FILES['logo_file']['tmp_name'])) {
    $fileTmp = $_FILES['logo_file']['tmp_name'];
    $fileSize = isset($_FILES['logo_file']['size']) ? (int)$_FILES['logo_file']['size'] : 0;
    if (!is_uploaded_file($fileTmp) || $fileSize <= 0 || $fileSize > (5 * 1024 * 1024)) {
      $errors[] = "Logo must be a valid image up to 5MB.";
    } else {
      $finfo = new finfo(FILEINFO_MIME_TYPE);
      $mime = (string)$finfo->file($fileTmp);
      $allowedMimes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
      ];
      if (!isset($allowedMimes[$mime])) {
        $errors[] = "Unsupported logo format.";
      } else {
        $upload_dir = 'uploads/logos/';
        if (!file_exists($upload_dir)) {
          mkdir($upload_dir, 0755, true);
        }
        $filename = uniqid('logo_') . '.' . $allowedMimes[$mime];
        if (!move_uploaded_file($fileTmp, $upload_dir . $filename)) {
          $errors[] = "Failed to upload logo.";
        } else {
          $logo_path = $upload_dir . $filename;
        }
      }
    }
  }

  if (empty($errors)) {
    if ($org_kind === 'group') {
      $store_voice_input = null;
    }
    $credit_questions_json = null;
    if ($hasCreditQuestionsColumn) {
      $credit_questions_config = build_credit_app_question_config_from_post(
        $creditQuestionCatalog,
        is_array($credit_questions_input) ? $credit_questions_input : []
      );
      $credit_questions_json = credit_app_question_config_is_default($credit_questions_config)
        ? null
        : json_encode($credit_questions_config);
    }
    if ($orgId > 0) {
      $updates = [
        'name = ?',
        'logo_url = ?',
        'logic_type = ?',
        'theme_variant = ?',
        'parent_org_id = ?',
        'org_kind = ?'
      ];
      $values = [$name, $logo_path, $logic_type, $theme_variant, $parent_org_id, $org_kind];

      if ($hasPackageLabelsColumn) {
        $updates[] = 'package_menu_labels = ?';
        $values[] = ($package_labels_input !== '' ? $package_labels_input : null);
      }
      if ($hasOrgVoiceColumn) {
        $updates[] = 'org_voice_prompt = ?';
        $values[] = ($org_voice_input !== '' ? $org_voice_input : null);
      }
      if ($hasStoreVoiceColumn) {
        $updates[] = 'store_voice_prompt = ?';
        $values[] = ($org_kind === 'store' && $store_voice_input !== '' ? $store_voice_input : null);
      }
      if ($hasCreditQuestionsColumn) {
        $updates[] = 'credit_app_question_config = ?';
        $values[] = $credit_questions_json;
      }
    if ($hasPreferredProviderColumn) {
      $updates[] = 'preferred_provider = ?';
      $values[] = ($preferred_provider_input !== '' ? $preferred_provider_input : null);
    }
      if ($hasAccessoriesToggleColumn) {
        $updates[] = 'accessories_enabled = ?';
        if ($accessories_toggle_input === 'enabled') {
          $values[] = 1;
        } elseif ($accessories_toggle_input === 'disabled') {
          $values[] = 0;
        } else {
          $values[] = null;
        }
      }
      if ($hasAiReasoningToggleColumn) {
        $updates[] = 'ai_reasoning_enabled = ?';
        if ($ai_reasoning_toggle_input === 'enabled') {
          $values[] = 1;
        } elseif ($ai_reasoning_toggle_input === 'disabled') {
          $values[] = 0;
        } else {
          $values[] = null;
        }
      }
      if ($hasCapWithGapColumn) {
        $updates[] = 'show_cap_with_gap = ?';
        if ($cap_with_gap_input === 'enabled') {
          $values[] = 1;
        } elseif ($cap_with_gap_input === 'disabled') {
          $values[] = 0;
        } else {
          $values[] = null;
        }
      }
      if ($hasServiceToggleColumn) {
        $updates[] = 'service_enabled = ?';
        if ($service_toggle_input === 'enabled') {
          $values[] = 1;
        } elseif ($service_toggle_input === 'disabled') {
          $values[] = 0;
        } else {
          $values[] = null;
        }
      }
      if ($hasPlanTierColumn) {
        $updates[] = 'plan_tier = ?';
        $values[] = $plan_tier_input !== '' ? $plan_tier_input : 'core';
      }
      if ($hasDealRateOverrideColumn) {
        $updates[] = 'deal_rate_override = ?';
        $values[] = ($deal_rate_override_input !== '' && is_numeric($deal_rate_override_input)) ? (float)$deal_rate_override_input : null;
      }
      if ($hasAccessoryRateOverrideColumn) {
        $updates[] = 'accessory_rate_override = ?';
        $values[] = ($accessory_rate_override_input !== '' && is_numeric($accessory_rate_override_input)) ? (float)$accessory_rate_override_input : null;
      }
      if ($hasServiceRateOverrideColumn) {
        $updates[] = 'service_rate_override = ?';
        $values[] = ($service_rate_override_input !== '' && is_numeric($service_rate_override_input)) ? (float)$service_rate_override_input : null;
      }
      if ($hasLeaseCapPercentColumn) {
        $updates[] = 'lease_msrp_cap_percent = ?';
        $values[] = ($lease_cap_percent_input !== '' && is_numeric($lease_cap_percent_input)) ? (float)$lease_cap_percent_input : null;
      }
      if ($hasFinanceCapPercentColumn) {
        $updates[] = 'finance_msrp_cap_percent = ?';
        $values[] = ($finance_cap_percent_input !== '' && is_numeric($finance_cap_percent_input)) ? (float)$finance_cap_percent_input : null;
      }

      $values[] = $orgId;
      $stmt = $db->prepare("UPDATE organizations SET " . implode(', ', $updates) . " WHERE id = ?");
      $stmt->execute($values);
      header("Location: admin_organizations.php?updated=1");
      exit;
    }

    $columns = ['name', 'logo_url', 'logic_type', 'theme_variant', 'parent_org_id', 'org_kind'];
    $values = [$name, $logo_path, $logic_type, $theme_variant, $parent_org_id, $org_kind];
    if ($hasPackageLabelsColumn) {
      $columns[] = 'package_menu_labels';
      $values[] = ($package_labels_input !== '' ? $package_labels_input : null);
    }
    if ($hasOrgVoiceColumn) {
      $columns[] = 'org_voice_prompt';
      $values[] = ($org_voice_input !== '' ? $org_voice_input : null);
    }
    if ($hasStoreVoiceColumn) {
      $columns[] = 'store_voice_prompt';
      $values[] = ($org_kind === 'store' && $store_voice_input !== '' ? $store_voice_input : null);
    }
    if ($hasCreditQuestionsColumn) {
      $columns[] = 'credit_app_question_config';
      $values[] = $credit_questions_json;
    }
    if ($hasPreferredProviderColumn) {
      $columns[] = 'preferred_provider';
      $values[] = ($preferred_provider_input !== '' ? $preferred_provider_input : null);
    }
    if ($hasAccessoriesToggleColumn) {
      $columns[] = 'accessories_enabled';
      if ($accessories_toggle_input === 'enabled') {
        $values[] = 1;
      } elseif ($accessories_toggle_input === 'disabled') {
        $values[] = 0;
      } else {
        $values[] = null;
      }
    }
    if ($hasAiReasoningToggleColumn) {
      $columns[] = 'ai_reasoning_enabled';
      if ($ai_reasoning_toggle_input === 'enabled') {
        $values[] = 1;
      } elseif ($ai_reasoning_toggle_input === 'disabled') {
        $values[] = 0;
      } else {
        $values[] = null;
      }
    }
    if ($hasCapWithGapColumn) {
      $columns[] = 'show_cap_with_gap';
      if ($cap_with_gap_input === 'enabled') {
        $values[] = 1;
      } elseif ($cap_with_gap_input === 'disabled') {
        $values[] = 0;
      } else {
        $values[] = null;
      }
    }
    if ($hasServiceToggleColumn) {
      $columns[] = 'service_enabled';
      if ($service_toggle_input === 'enabled') {
        $values[] = 1;
      } elseif ($service_toggle_input === 'disabled') {
        $values[] = 0;
      } else {
        $values[] = null;
      }
    }
    if ($hasPlanTierColumn) {
      $columns[] = 'plan_tier';
      $values[] = $plan_tier_input !== '' ? $plan_tier_input : 'core';
    }
    if ($hasDealRateOverrideColumn) {
      $columns[] = 'deal_rate_override';
      $values[] = ($deal_rate_override_input !== '' && is_numeric($deal_rate_override_input)) ? (float)$deal_rate_override_input : null;
    }
    if ($hasAccessoryRateOverrideColumn) {
      $columns[] = 'accessory_rate_override';
      $values[] = ($accessory_rate_override_input !== '' && is_numeric($accessory_rate_override_input)) ? (float)$accessory_rate_override_input : null;
    }
    if ($hasServiceRateOverrideColumn) {
      $columns[] = 'service_rate_override';
      $values[] = ($service_rate_override_input !== '' && is_numeric($service_rate_override_input)) ? (float)$service_rate_override_input : null;
    }
    if ($hasLeaseCapPercentColumn) {
      $columns[] = 'lease_msrp_cap_percent';
      $values[] = ($lease_cap_percent_input !== '' && is_numeric($lease_cap_percent_input)) ? (float)$lease_cap_percent_input : null;
    }
    if ($hasFinanceCapPercentColumn) {
      $columns[] = 'finance_msrp_cap_percent';
      $values[] = ($finance_cap_percent_input !== '' && is_numeric($finance_cap_percent_input)) ? (float)$finance_cap_percent_input : null;
    }
    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
    $stmt = $db->prepare("INSERT INTO organizations (" . implode(', ', $columns) . ") VALUES (" . $placeholders . ")");
    $stmt->execute($values);
    header("Location: admin_organizations.php?added=1");
    exit;
  }
  }
}

$formName = $org['name'] ?? '';
$formLogo = $org['logo_url'] ?? '';
$formLogic = $org['logic_type'] ?? 'luxury';
$formTheme = $org['theme_variant'] ?? 'default';
$formParent = $org['parent_org_id'] ?? null;
$formKind = $org['org_kind'] ?? 'store';
$formPackageLabels = $org['package_menu_labels'] ?? '';
$formOrgVoice = $org['org_voice_prompt'] ?? '';
$formStoreVoice = $org['store_voice_prompt'] ?? '';
$formPreferredProvider = $org['preferred_provider'] ?? '';
$formCreditQuestions = $org['credit_app_question_config'] ?? '';
$formAccessoriesEnabled = $org['accessories_enabled'] ?? null;
$formAiReasoningEnabled = $org['ai_reasoning_enabled'] ?? null;
$formCapWithGap = $org['show_cap_with_gap'] ?? null;
$formServiceEnabled = $org['service_enabled'] ?? null;
$formPlanTier = $org['plan_tier'] ?? 'core';
$formDealRateOverride = $org['deal_rate_override'] ?? null;
$formAccessoryRateOverride = $org['accessory_rate_override'] ?? null;
$formServiceRateOverride = $org['service_rate_override'] ?? null;
$formLeaseCapPercent = $org['lease_msrp_cap_percent'] ?? null;
$formFinanceCapPercent = $org['finance_msrp_cap_percent'] ?? null;
$defaultLabels = implode("\n", get_default_package_menu_labels());
$creditQuestionConfig = normalize_credit_app_question_config(
  json_decode($formCreditQuestions ?: '', true)
);
$creditQuestionStepLabels = [
  'step1' => 'Step 1 - Personal Info',
  'step2' => 'Step 2 - Employment & Income',
  'step3' => 'Step 3 - Vehicle Usage',
];
$isCreditQuestionVisible = function (string $step, string $id) use ($creditQuestionConfig): bool {
  return credit_app_question_is_visible($creditQuestionConfig, $step, $id);
};
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>DealerFAI - Admin: Organization</title>
  <?php include __DIR__ . "/includes/head_favicon.php"; ?>
<?php dealerfai_theme_head(); ?>
  <style nonce="<?= dealerfai_csp_nonce() ?>">
    body { font-family: Arial, sans-serif; background: #f4f6f8; margin: 0; padding: 0; }
    header { background: #0a2e36; color: white; padding: 20px; text-align: center; position: relative; }
    nav { background: #0a2e36; padding: 12px; text-align: center; }
    nav a { color: white; margin: 0 20px; text-decoration: none; font-weight: bold; }
    .container { max-width: 820px; margin: 20px auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
    h2 { margin-top: 0; }
    label { display: block; margin-top: 12px; font-weight: bold; }
    input[type="text"], input[type="file"], select, textarea {
      width: 100%; padding: 8px; margin-top: 5px; border: 1px solid #ccc; border-radius: 4px;
    }
    textarea { resize: vertical; }
    .btn {
      background: #0a6280; color: white; padding: 10px 18px; border: none;
      border-radius: 4px; margin-top: 20px; cursor: pointer; font-size: 16px;
      text-decoration: none; display: inline-block;
    }
    .btn:hover { background: #094c63; }
    .btn.secondary { background: #6c757d; }
    .btn.secondary:hover { background: #5b646c; }
    .danger { background: #6a1a1a; }
    .danger:hover { background: #4f1313; }
    .note { color: #5a6a7a; margin-top: 6px; font-size: 0.95rem; }
    .error { color: #b00020; margin-top: 10px; }
    img.logo-preview { max-height: 50px; max-width: 160px; display: block; margin-top: 10px; }
    .actions { margin-top: 20px; }
    .question-step { margin-top: 16px; padding: 12px; background: #f8fafc; border: 1px solid #dfe7ee; border-radius: 6px; }
    .question-toggle { display: block; margin-top: 8px; font-weight: normal; }
  </style>
</head>
<body>
<header>
  <h1>DealerFAI Admin</h1>
  <div class="logout">
    <a href="admin_error_alerts.php" class="nav-link-white">Alerts<?php if ($adminAlertCount > 0): ?> <span style="display:inline-block; min-width:18px; padding:2px 8px; border-radius:999px; background:#d7263d; color:#fff; font-size:12px; font-weight:bold; text-align:center; margin-left:6px;"><?= $adminAlertCount ?></span><?php endif; ?></a>
    <a href="logout.php" class="nav-link-white">Log Out</a>
  </div>
</header>
<nav>
  <a href="dashboard.php">Dashboard</a>
  <a href="view_deals.php">View Deals</a>
  <a href="create_deal.php">Create Deal</a>
  <a href="admin_tools.php">Admin Tools</a>
</nav>

<div class="container">
  <h2><?= $orgId > 0 ? 'Edit Organization' : 'Add Organization' ?></h2>
  <a class="btn" href="admin_organizations.php">← Back to organizations</a>
  <?php if ($orgId > 0): ?>
    <a class="btn secondary" href="admin_credit_app_questions.php?org_id=<?= (int)$orgId ?>">Manage Credit App Questions</a>
  <?php endif; ?>

  <?php foreach ($errors as $error): ?>
    <p class="error"><?= htmlspecialchars($error) ?></p>
  <?php endforeach; ?>

  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
    <label>Organization Name</label>
    <input type="text" name="name" required value="<?= htmlspecialchars($formName) ?>">

    <label>Organization Type</label>
    <select name="org_kind">
      <option value="store" <?= $formKind === 'store' ? 'selected' : '' ?>>Store</option>
      <option value="group" <?= $formKind === 'group' ? 'selected' : '' ?>>Group</option>
    </select>
    <p class="note">Groups represent parent organizations (e.g., Birchwood).</p>

    <label>Parent Organization (group)</label>
    <select name="parent_org_id" <?= $formKind === 'group' ? 'disabled' : '' ?>>
      <option value="">None</option>
      <?php foreach ($orgOptions as $option): ?>
        <?php if ((int)$option['id'] === $orgId): ?>
          <?php continue; ?>
        <?php endif; ?>
        <option value="<?= (int)$option['id'] ?>" <?= ((int)$option['id'] === (int)$formParent) ? 'selected' : '' ?>>
          <?= htmlspecialchars($option['name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <p class="note">Assign a store to a parent group. Groups cannot have parents.</p>

    <label>Upload Logo</label>
    <input type="file" name="logo_file" accept="image/*">
    <img id="logo-file-preview" src="" class="logo-preview" alt="Selected logo preview" class="d-none">
    <?php if ($formLogo): ?>
      <img src="<?= htmlspecialchars($formLogo) ?>" class="logo-preview" alt="Current logo">
      <label><input type="checkbox" name="remove_logo" value="1"> Remove logo</label>
    <?php endif; ?>

    <label>Logic Type</label>
    <select name="logic_type">
      <option value="luxury" <?= $formLogic === 'luxury' ? 'selected' : '' ?>>Luxury</option>
      <option value="domestic" <?= $formLogic === 'domestic' ? 'selected' : '' ?>>Domestic</option>
      <option value="import" <?= $formLogic === 'import' ? 'selected' : '' ?>>Import</option>
    </select>

    <?php if ($hasAccessoriesToggleColumn): ?>
      <label>Accessories Presentation</label>
      <select name="accessories_enabled">
        <option value="inherit" <?= $formAccessoriesEnabled === null ? 'selected' : '' ?>>Inherit (Default On)</option>
        <option value="enabled" <?= $formAccessoriesEnabled === 1 || $formAccessoriesEnabled === '1' ? 'selected' : '' ?>>Enabled</option>
        <option value="disabled" <?= $formAccessoriesEnabled === 0 || $formAccessoriesEnabled === '0' ? 'selected' : '' ?>>Disabled</option>
      </select>
      <p class="note">Stores inherit from their parent group when set to Inherit.</p>
    <?php endif; ?>

    <?php if ($hasAiReasoningToggleColumn): ?>
      <label>AI Reasoning</label>
      <select name="ai_reasoning_enabled">
        <option value="inherit" <?= $formAiReasoningEnabled === null ? 'selected' : '' ?>>Inherit (Default On)</option>
        <option value="enabled" <?= $formAiReasoningEnabled === 1 || $formAiReasoningEnabled === '1' ? 'selected' : '' ?>>Enabled</option>
        <option value="disabled" <?= $formAiReasoningEnabled === 0 || $formAiReasoningEnabled === '0' ? 'selected' : '' ?>>Disabled</option>
      </select>
      <p class="note">When disabled, recommendations use preset/default reasons and skip AI-generated explanation copy. Stores inherit from their parent group when set to Inherit.</p>
    <?php endif; ?>

    <?php if ($hasCapWithGapColumn): ?>
      <label>CAP When GAP Recommended</label>
      <select name="show_cap_with_gap">
        <option value="inherit" <?= $formCapWithGap === null ? 'selected' : '' ?>>Inherit (Default On)</option>
        <option value="enabled" <?= $formCapWithGap === 1 || $formCapWithGap === '1' ? 'selected' : '' ?>>Show Both GAP and CAP</option>
        <option value="disabled" <?= $formCapWithGap === 0 || $formCapWithGap === '0' ? 'selected' : '' ?>>Show Only Higher-Scored GAP/CAP in Top 5</option>
      </select>
      <p class="note">When disabled and both GAP/CAP are recommended, the lower-scored one is moved out of Top 5 and still shown under Additional Coverage Options. Stores inherit from parent groups when set to Inherit.</p>
    <?php endif; ?>

    <?php if ($hasServiceToggleColumn): ?>
      <label>Service Component</label>
      <select name="service_enabled">
        <option value="inherit" <?= $formServiceEnabled === null ? 'selected' : '' ?>>Inherit (Default Off)</option>
        <option value="enabled" <?= $formServiceEnabled === 1 || $formServiceEnabled === '1' ? 'selected' : '' ?>>Enabled</option>
        <option value="disabled" <?= $formServiceEnabled === 0 || $formServiceEnabled === '0' ? 'selected' : '' ?>>Disabled</option>
      </select>
      <p class="note">Service usage is only billed when enabled. Stores inherit from their parent group when set to Inherit.</p>
    <?php endif; ?>

    <?php if ($hasPlanTierColumn): ?>
      <label>Billing Plan</label>
      <select name="plan_tier">
        <?php foreach ($billingPlans as $plan): ?>
          <?php $planCode = strtolower(trim((string)($plan['code'] ?? 'core'))); ?>
          <option value="<?= htmlspecialchars($planCode) ?>" <?= $formPlanTier === $planCode ? 'selected' : '' ?>>
            <?= htmlspecialchars($plan['name'] ?? ucfirst($planCode)) ?>
          </option>
        <?php endforeach; ?>
      </select>
    <?php endif; ?>

    <?php if ($hasDealRateOverrideColumn || $hasAccessoryRateOverrideColumn || $hasServiceRateOverrideColumn): ?>
      <label>Billing Rate Overrides (optional)</label>
      <div class="muted">Leave blank to use the plan defaults.</div>
      <?php if ($hasDealRateOverrideColumn): ?>
        <input type="text" name="deal_rate_override" placeholder="Deal rate override (e.g., 30.00)" value="<?= htmlspecialchars($formDealRateOverride !== null ? (string)$formDealRateOverride : '') ?>">
      <?php endif; ?>
      <?php if ($hasAccessoryRateOverrideColumn): ?>
        <input type="text" name="accessory_rate_override" placeholder="Accessories rate override (e.g., 5.00)" value="<?= htmlspecialchars($formAccessoryRateOverride !== null ? (string)$formAccessoryRateOverride : '') ?>">
      <?php endif; ?>
      <?php if ($hasServiceRateOverrideColumn): ?>
        <input type="text" name="service_rate_override" placeholder="Service rate override (e.g., 4.00)" value="<?= htmlspecialchars($formServiceRateOverride !== null ? (string)$formServiceRateOverride : '') ?>">
      <?php endif; ?>
    <?php endif; ?>

    <?php if ($hasLeaseCapPercentColumn || $hasFinanceCapPercentColumn): ?>
      <label>MSRP Financing Caps (optional)</label>
      <div class="muted">Set a percent of MSRP that can be financed. Leave blank for no cap.</div>
      <?php if ($hasLeaseCapPercentColumn): ?>
        <input type="number" step="0.01" min="0" name="lease_msrp_cap_percent" placeholder="Lease cap % of MSRP (e.g., 110)" value="<?= htmlspecialchars($formLeaseCapPercent !== null ? (string)$formLeaseCapPercent : '') ?>">
      <?php endif; ?>
      <?php if ($hasFinanceCapPercentColumn): ?>
        <input type="number" step="0.01" min="0" name="finance_msrp_cap_percent" placeholder="Finance cap % of MSRP (e.g., 110)" value="<?= htmlspecialchars($formFinanceCapPercent !== null ? (string)$formFinanceCapPercent : '') ?>">
      <?php endif; ?>
    <?php endif; ?>

    <label>Theme Variant</label>
    <select name="theme_variant">
      <option value="default" <?= $formTheme === 'default' ? 'selected' : '' ?>>DealerFAI (default)</option>
      <option value="bmw_black" <?= $formTheme === 'bmw_black' ? 'selected' : '' ?>>BMW Black</option>
      <option value="audi_red" <?= $formTheme === 'audi_red' ? 'selected' : '' ?>>Audi Red</option>
      <option value="mercedes_silver" <?= $formTheme === 'mercedes_silver' ? 'selected' : '' ?>>Mercedes Silver</option>
      <option value="jlr" <?= $formTheme === 'jlr' ? 'selected' : '' ?>>JLR Theme</option>
      <option value="landrover_green" <?= $formTheme === 'landrover_green' ? 'selected' : '' ?>>Land Rover Green</option>
      <option value="toyota_grey" <?= $formTheme === 'toyota_grey' ? 'selected' : '' ?>>Toyota Grey</option>
      <option value="ford_blue" <?= $formTheme === 'ford_blue' ? 'selected' : '' ?>>Ford Blue</option>
      <option value="honda_red" <?= $formTheme === 'honda_red' ? 'selected' : '' ?>>Honda Red</option>
    </select>

    <?php if ($hasPackageLabelsColumn): ?>
      <label>Package labels (one per line, max 4)</label>
      <textarea name="package_menu_labels" rows="4" placeholder="<?= htmlspecialchars($defaultLabels, ENT_QUOTES) ?>"><?= htmlspecialchars($formPackageLabels) ?></textarea>
    <?php endif; ?>

    <?php if ($hasOrgVoiceColumn): ?>
      <label>Organization voice (brand-level tone)</label>
      <textarea name="org_voice_prompt" rows="4" placeholder="Add overall brand voice guidelines..."><?= htmlspecialchars($formOrgVoice) ?></textarea>
    <?php endif; ?>

    <?php if ($hasStoreVoiceColumn && $formKind === 'store'): ?>
      <label>Store voice (location-specific tone)</label>
      <textarea name="store_voice_prompt" rows="4" placeholder="Add store-specific voice guidance..."><?= htmlspecialchars($formStoreVoice) ?></textarea>
    <?php endif; ?>

    <?php if ($hasPreferredProviderColumn): ?>
      <label>Preferred Provider (optional)</label>
      <input type="text" name="preferred_provider" placeholder="e.g., SAL, DealerFAI" value="<?= htmlspecialchars($formPreferredProvider) ?>">
      <p class="note">Used to pick a provider-specific product when multiple variants share the same code.</p>
    <?php endif; ?>

    <?php if ($hasCreditQuestionsColumn): ?>
      <label>Credit application questions</label>
      <p class="note">Uncheck any items you want hidden for this store. Checked = visible.</p>
      <?php foreach ($creditQuestionCatalog as $step => $questions): ?>
        <div class="question-step">
          <strong><?= htmlspecialchars($creditQuestionStepLabels[$step] ?? strtoupper($step)) ?></strong>
          <?php foreach ($questions as $question): ?>
            <?php $isVisible = $isCreditQuestionVisible($step, $question['id']); ?>
            <label class="question-toggle">
              <input type="checkbox"
                     name="credit_app_questions[<?= htmlspecialchars($step) ?>][<?= htmlspecialchars($question['id']) ?>]"
                     value="1"
                     <?= $isVisible ? 'checked' : '' ?>>
              <?= htmlspecialchars($question['label']) ?>
            </label>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>

    <div class="actions">
      <button type="submit" class="btn"><?= $orgId > 0 ? 'Save changes' : 'Create organization' ?></button>
    </div>
  </form>
</div>
<script nonce="<?= dealerfai_csp_nonce() ?>">
  (function initLogoPreview() {
    const input = document.querySelector('input[type="file"][name="logo_file"]');
    const preview = document.getElementById('logo-file-preview');
    if (!input || !preview) return;

    function clearPreview() {
      preview.src = '';
      preview.style.display = 'none';
    }

    input.addEventListener('change', () => {
      const file = input.files && input.files[0] ? input.files[0] : null;
      if (!file) {
        clearPreview();
        return;
      }
      const url = URL.createObjectURL(file);
      preview.src = url;
      preview.style.display = '';
      preview.onload = () => URL.revokeObjectURL(url);
    });

    const remove = document.querySelector('input[type="checkbox"][name="remove_logo"]');
    if (remove) {
      remove.addEventListener('change', () => {
        if (remove.checked) {
          clearPreview();
          input.value = '';
        }
      });
    }
  })();
</script>
</body>
</html>
