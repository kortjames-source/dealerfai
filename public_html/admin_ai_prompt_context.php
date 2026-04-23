<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/security_headers.php';
require_once __DIR__ . '/includes/theme_head.php';
include 'db.php';
require_once __DIR__ . '/helpers/session_utils.php';
require_once __DIR__ . '/helpers/usage_data.php';
require_once __DIR__ . '/scoring_engine.php';

$roles = load_session_roles();
if (!in_array('Admin', $roles, true)) {
  echo "<p style='color:red;'>Access denied. Admins only.</p>";
  exit;
}

$adminAlertCount = get_admin_alert_count($db);

$orgRows = $db->query("SELECT id, name FROM organizations ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$orgOptions = [0 => 'Global'];
foreach ($orgRows as $row) {
  $orgOptions[(int)$row['id']] = $row['name'];
}

$orgId = isset($_GET['org_id']) ? (int)$_GET['org_id'] : 0;
if ($orgId <= 0) {
  $contextOrg = get_admin_organization_context();
  if ($contextOrg !== null) {
    $orgId = (int)$contextOrg;
  }
}
if ($orgId <= 0 && count($orgOptions) === 1) {
  $orgId = (int)array_key_first($orgOptions);
}

$dealId = isset($_GET['deal_id']) ? (int)$_GET['deal_id'] : 0;
$productCode = trim((string)($_GET['product_code'] ?? ''));

$errors = [];
$warnings = [];
$deal = null;
$application = null;

if ($dealId > 0) {
  $dealStmt = $db->prepare("SELECT * FROM deals WHERE id = ?");
  $dealStmt->execute([$dealId]);
  $deal = $dealStmt->fetch(PDO::FETCH_ASSOC) ?: null;
  if (!$deal) {
    $errors[] = 'Deal not found.';
  } elseif ($orgId > 0 && (int)($deal['organization'] ?? 0) !== $orgId) {
    $errors[] = 'Deal does not belong to the selected organization.';
  } else {
    $appStmt = $db->prepare("SELECT * FROM applications WHERE deal_id = ?");
    $appStmt->execute([$dealId]);
    $application = $appStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$application) {
      $warnings[] = 'No credit application is attached to this deal.';
    }
  }
}

$contextOrgId = $orgId > 0 ? $orgId : (int)($deal['organization'] ?? 0);
$voicePrompts = load_org_voice_prompts($db, $contextOrgId ?: null);

$profileSummary = '';
$dealContext = ['deal_context' => '', 'financial_summary' => ''];

if ($application) {
  $profileSummary = dealerfai_build_customer_profile_summary($application);
}
if ($deal) {
  $dealContext = build_deal_context($deal);
}

$products = [];
$selectedProduct = null;
$matchedDetails = [];
$promptPreview = '';
$defaultFacts = '';
$customFacts = '';
$promptEligible = false;
$promptStatus = '';
$generatedCopy = '';
$generatedSource = '';
$usageData = [];
$applicationFieldRows = [];
$dealFieldRows = [];

if ($application && !empty($application['usage_data'])) {
  $usageData = dealerfai_decode_usage_data((string)$application['usage_data']);
}

if ($application) {
  $applicationFieldRows = [
    ['ownership_length', $usageData['ownership_length'] ?? null],
    ['annual_km', $usageData['annual_km'] ?? null],
    ['driving_type', $usageData['driving_type'] ?? null],
    ['gravel_exposure', $usageData['gravel_exposure'] ?? null],
    ['road_conditions', $usageData['road_conditions'] ?? null],
    ['overnight_parking', $usageData['overnight_parking'] ?? null],
    ['regular_users', !empty($usageData['regular_users']) && is_array($usageData['regular_users']) ? implode(', ', $usageData['regular_users']) : null],
    ['food_drink', $usageData['food_drink'] ?? null],
    ['appearance_priority', $usageData['appearance_priority'] ?? null],
    ['life_protection', $usageData['life_protection'] ?? null],
    ['disability_protection', $usageData['disability_protection'] ?? null],
    ['critical_illness', $usageData['critical_illness'] ?? null],
    ['job_loss', $usageData['job_loss'] ?? null],
    ['province', $application['province'] ?? null],
    ['postal_code', $application['postal_code'] ?? null],
    ['housing', $application['housing'] ?? null],
    ['vehicle_make', $application['vehicle_make'] ?? null],
  ];
}

if ($deal) {
  $includedProtections = function_exists('parse_included_protections')
    ? parse_included_protections($deal['included_protections'] ?? null)
    : [];
  $includedSummary = function_exists('summarize_included_protections')
    ? summarize_included_protections($includedProtections)
    : ['total' => 0, 'names' => []];
  $includedText = '';
  if (!empty($includedSummary['names'])) {
    $includedText = implode(', ', $includedSummary['names']);
    if (!empty($includedSummary['total'])) {
      $includedText .= ' (' . format_currency((float)$includedSummary['total']) . ')';
    }
  }

  $dealFieldRows = [
    ['customer_name', $deal['customer_name'] ?? null],
    ['vehicle_year', $deal['vehicle_year'] ?? null],
    ['vehicle_make', $deal['vehicle_make'] ?? null],
    ['vehicle_model', $deal['vehicle_model'] ?? null],
    ['vehicle_condition', $deal['vehicle_condition'] ?? null],
    ['vehicle_colour', $deal['vehicle_colour'] ?? null],
    ['vehicle_kms', $deal['vehicle_kms'] ?? null],
    ['in_service_date', $deal['in_service_date'] ?? null],
    ['deal_type', $deal['deal_type'] ?? null],
    ['term', $deal['term'] ?? null],
    ['interest_rate', $deal['interest_rate'] ?? null],
    ['payment_frequency', $deal['payment_frequency'] ?? null],
    ['sale_price', $deal['sale_price'] ?? null],
    ['documentation_fee', $deal['documentation_fee'] ?? null],
    ['down_payment', $deal['down_payment'] ?? null],
    ['trade_info', $deal['trade_info'] ?? null],
    ['trade_value', $deal['trade_value'] ?? null],
    ['lien_amount', $deal['lien_amount'] ?? null],
    ['ppsa_fee', $deal['ppsa_fee'] ?? null],
    ['province', $deal['province'] ?? null],
    ['included_protections', $includedText],
  ];

  if (function_exists('column_exists') && column_exists($db, 'deals', 'customer_context')) {
    $dealFieldRows[] = ['customer_context', $deal['customer_context'] ?? null];
  }
}

  if ($deal && $application) {
  $vehicleInfo = resolve_vehicle_make_info($db, $deal, $application);
  $preferredProvider = null;
  $vehicleModelId = isset($deal['vehicle_model_id']) ? (int)$deal['vehicle_model_id'] : null;
  if ($vehicleModelId !== null && $vehicleModelId <= 0) {
    $vehicleModelId = null;
  }
  $vehicleTrimId = isset($deal['vehicle_trim_id']) ? (int)$deal['vehicle_trim_id'] : null;
  if ($vehicleTrimId !== null && $vehicleTrimId <= 0) {
    $vehicleTrimId = null;
  }
  $dealType = strtolower((string)($deal['deal_type'] ?? ''));
  $orgForProducts = (int)($deal['organization'] ?? 0);
  $products = fetch_products(
    $db,
    $dealType,
    $preferredProvider,
    $orgForProducts ?: null,
    $vehicleInfo['id'] ?? null,
    $vehicleModelId,
    $vehicleTrimId,
    $deal['vehicle_condition'] ?? null
  );

  if ($productCode === '' && !empty($products)) {
    $productCode = (string)($products[0]['code'] ?? '');
  }
  foreach ($products as $product) {
    if (strtolower((string)($product['code'] ?? '')) === strtolower($productCode)) {
      $selectedProduct = $product;
      break;
    }
  }

  if ($selectedProduct) {
    $includedTotal = 0.0;
    if (function_exists('parse_included_protections') && function_exists('summarize_included_protections')) {
      $includedItems = parse_included_protections($deal['included_protections'] ?? null);
      $includedSummary = summarize_included_protections($includedItems);
      $includedTotal = (float)($includedSummary['total'] ?? 0.0);
    }
    $actionRules = load_action_rules($db, $orgForProducts ?: null);
    $actionSignals = !empty($actionRules)
      ? build_action_rule_signal_context($db, $deal, $application, $includedTotal)
      : [];
    $productCodeKey = (string)($selectedProduct['code'] ?? '');
    $score = 0;
    $excludedByRule = false;
    $actionResult = !empty($actionRules) && !empty($actionSignals)
      ? apply_action_rules_to_target($actionRules, $actionSignals, 'product', $productCodeKey)
      : ['score_delta' => 0, 'excluded' => false, 'matched' => [], 'hints' => []];
    if (!empty($actionResult['excluded'])) {
      $excludedByRule = true;
      $matchedDetails[] = 'Excluded by action rule';
    } else {
      $score += (int)($actionResult['score_delta'] ?? 0);
      foreach ((array)($actionResult['hints'] ?? []) as $hint) {
        $hint = trim((string)$hint);
        if ($hint !== '' && !in_array($hint, $matchedDetails, true)) {
          $matchedDetails[] = $hint;
        }
      }
      foreach ((array)($actionResult['matched'] ?? []) as $match) {
        $match = trim((string)$match);
        if ($match !== '' && !in_array($match, $matchedDetails, true)) {
          $matchedDetails[] = $match;
        }
      }
    }
    $defaultFacts = trim((string)($selectedProduct['approved_facts_default']
      ?? $selectedProduct['default_description']
      ?? $selectedProduct['default_reason']
      ?? ''));
    $customFacts = trim((string)($selectedProduct['approved_facts_custom'] ?? ''));

    $promptEligible = !$excludedByRule && $score > 0;
    if ($promptEligible) {
      $promptStatus = 'Prompt sent (product scored and was recommended).';
      $promptPreview = build_ai_prompt_preview($selectedProduct, array_merge($voicePrompts, [
        'profile_summary' => $profileSummary,
        'matched_details' => array_slice($matchedDetails, 0, 5),
        'deal_context' => $dealContext['deal_context'] ?? '',
        'financial_summary' => $dealContext['financial_summary'] ?? '',
        'customer_context_raw' => (function_exists('column_exists') && column_exists($db, 'deals', 'customer_context')) ? (string)($deal['customer_context'] ?? '') : '',
      ]));
    } elseif ($excludedByRule) {
      $promptStatus = 'Prompt not sent (product excluded by a rule).';
    } else {
      $promptStatus = 'Prompt not sent (product score was not positive).';
    }

    if ($dealId > 0 && $productCodeKey !== '') {
      $recStmt = $db->prepare("
        SELECT ai_explanation, description
        FROM product_recommendations
        WHERE deal_id = ? AND product_code = ?
        ORDER BY id DESC
        LIMIT 1
      ");
      $recStmt->execute([$dealId, $productCodeKey]);
      $recRow = $recStmt->fetch(PDO::FETCH_ASSOC) ?: [];
      $generatedCopy = trim((string)($recRow['ai_explanation'] ?? ''));
      if ($generatedCopy !== '') {
        $generatedSource = 'AI explanation';
      } else {
        $generatedCopy = trim((string)($recRow['description'] ?? ''));
        if ($generatedCopy !== '') {
          $generatedSource = 'Fallback description';
        }
      }
    }
  }
}

function build_ai_prompt_preview(array $product, array $dealInfo): string {
  $defaultFacts = trim((string)($product['approved_facts_default'] ?? $product['default_description'] ?? $product['default_reason'] ?? ''));
  $customFacts = trim((string)($product['approved_facts_custom'] ?? ''));
  $fallbackReason = $product['default_reason']
    ?? $defaultFacts
    ?? "This protection may be helpful based on your needs.";

  $orgVoice = trim((string)($dealInfo['org_voice'] ?? ''));
  $storeVoice = trim((string)($dealInfo['store_voice'] ?? ''));
  $voiceBlock = '';
  if ($orgVoice !== '') {
    $voiceBlock .= "Organization voice guidelines (baseline):\n{$orgVoice}\n";
  }
  if ($storeVoice !== '') {
    $voiceBlock .= "Store voice guidelines (primary, lean toward this):\n{$storeVoice}\n";
  }

  $profileSummary = trim((string)($dealInfo['profile_summary'] ?? ''));
  $profileLine = $profileSummary !== ''
    ? "Customer profile summary (from their answers): {$profileSummary}.\n"
    : "Customer profile summary: [not provided].\n";

  $matchedDetails = $dealInfo['matched_details'] ?? [];
  if (!is_array($matchedDetails)) {
    $matchedDetails = [];
  }
  $matchedDetails = array_values(array_filter($matchedDetails, fn($detail) => $detail !== ''));
  $matchedLine = '';
  if (!empty($matchedDetails)) {
    $matchedLine = "Recommendation triggers from their answers: " . implode('; ', $matchedDetails) . ".\n";
  }

  $customerContextBlock = '';
  $customerContextRaw = trim((string)($dealInfo['customer_context_raw'] ?? ''));
  if ($customerContextRaw !== '' && function_exists('format_customer_context_for_prompt')) {
    $customerContextBlock = format_customer_context_for_prompt($customerContextRaw);
    if ($customerContextBlock !== '') {
      $customerContextBlock .= "\n";
    }
  }

  $dealContext = trim((string)($dealInfo['deal_context'] ?? ''));
  $financialSummary = trim((string)($dealInfo['financial_summary'] ?? ''));
  $dealContextLine = $dealContext !== ''
    ? "Deal context (customer + vehicle + terms): {$dealContext}.\n"
    : '';
  $financialLine = $financialSummary !== ''
    ? "Financial summary: {$financialSummary}.\n"
    : '';

  $prompt = $profileLine
    . $matchedLine
    . $customerContextBlock
    . $dealContextLine
    . $financialLine
    . "They are considering this protection product: {$product['name']}.\n"
    . "The customer already sees the product description and facts above, so do not repeat or paraphrase them.\n"
    . ($voiceBlock !== '' ? ($voiceBlock . "\n") : '')
    . "Default approved facts (baseline; always true unless overridden below):\n"
    . ($defaultFacts !== '' ? $defaultFacts : '[No default approved facts provided]') . "\n"
    . "Custom approved facts (store-specific; if any conflict with default, these override the default):\n"
    . ($customFacts !== '' ? $customFacts : '[No custom approved facts provided]') . "\n"
    . "Write 2-3 sentences explaining why this product might benefit them.\n"
    . "Rules:\n"
    . "- Follow the voice guidelines if provided; store voice is the priority.\n"
    . "- Use only the approved facts above. If default and custom conflict, the custom facts take priority.\n"
    . "- Do not mention any coverage details that are not explicitly listed.\n"
    . "- Start immediately with a concrete benefit; no introductions or generic lead-ins.\n"
    . "- Do not use phrases like \"May I suggest\" or \"Given your driving profile\".\n"
    . "- If relevant details are provided above, include at least one of them verbatim.\n"
    . "- Do not refer to the details above as \"notes\" or \"internal context\"; incorporate them naturally.\n"
    . "- Do not mention customer profile details that are not listed above.\n"
    . "- Do not restate the product description; focus on why it fits their answers.\n"
    . "- If no approved facts are provided, respond with exactly this sentence: {$fallbackReason}\n"
    . "- Avoid greetings, avoid invented features, and avoid repeated stock openers.";

  return $prompt;
}

function format_display_value($value): string {
  if (is_array($value)) {
    return json_encode($value, JSON_UNESCAPED_SLASHES) ?: '—';
  }
  if (is_bool($value)) {
    return $value ? 'true' : 'false';
  }
  $trimmed = trim((string)$value);
  return $trimmed !== '' ? $trimmed : '—';
}
?>
<!DOCTYPE html>
<html>
<head>
  <?php include __DIR__ . "/includes/head_favicon.php"; ?>
<meta charset="UTF-8">
  <title>Admin - AI Prompt Data</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php dealerfai_theme_head(); ?>
  <style nonce="<?= dealerfai_csp_nonce() ?>">
    body { font-family: Arial, sans-serif; background: #f4f6f8; margin: 0; padding: 0; }
    header { background: #0a6280; color: white; padding: 20px; text-align: center; position: relative; }
    nav { background: #0a6280; padding: 12px; text-align: center; }
    nav a { color: white; margin: 0 20px; text-decoration: none; font-weight: bold; }
    .container { max-width: 1200px; margin: 20px auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
    h2 { margin-top: 0; }
    .muted { color: #667085; font-size: 0.92rem; }
    .badge { display: inline-block; min-width: 18px; padding: 2px 8px; border-radius: 999px; background: #d7263d; color: #fff; font-size: 12px; font-weight: bold; text-align: center; margin-left: 6px; }
    .org-picker { display:flex; gap:12px; align-items:center; margin-bottom:20px; flex-wrap: wrap; }
    .org-picker label { font-weight: bold; }
    .org-picker select, .org-picker input { padding: 8px; border-radius: 4px; border: 1px solid #ccc; }
    .org-picker button { padding: 8px 14px; border: none; border-radius: 4px; background: #0a6280; color: white; cursor: pointer; }
    .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 14px; }
    .panel { border: 1px solid #e3e7ed; border-radius: 8px; padding: 14px; background: #fafbfc; }
    .panel h3 { margin-top: 0; }
    .pill { display: inline-block; padding: 4px 10px; border-radius: 999px; background: #e6ebf2; color: #1b2c40; font-size: 12px; font-weight: bold; margin: 4px 6px 0 0; }
    table { width: 100%; border-collapse: collapse; margin-top: 12px; }
    th, td { padding: 10px; border-bottom: 1px solid #ddd; vertical-align: top; text-align: left; }
    th { background: #f1f4f8; }
    textarea, pre { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; font-family: inherit; background: #fff; }
    pre { white-space: pre-wrap; word-break: break-word; }
    .alert { background: #fff3cd; color: #7a5b00; padding: 10px 12px; border-radius: 6px; margin-bottom: 10px; }
    .error { background: #fdecea; color: #7f1d1d; padding: 10px 12px; border-radius: 6px; margin-bottom: 10px; }
  </style>
</head>
<body>
  <header>
    <h1>DealerFAI Admin</h1>
    <div class="logout">
      <a href="admin_error_alerts.php" class="nav-link-white">Alerts<?php if ($adminAlertCount > 0): ?> <span class="badge"><?= $adminAlertCount ?></span><?php endif; ?></a>
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
    <h2>AI Prompt Data Transparency</h2>
    <p class="muted">Use this page to show exactly which fields, rule signals, and prompt inputs are used when generating AI explanations.</p>

    <?php foreach ($errors as $error): ?>
      <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endforeach; ?>
    <?php foreach ($warnings as $warning): ?>
      <div class="alert"><?= htmlspecialchars($warning) ?></div>
    <?php endforeach; ?>

    <form method="get" class="org-picker">
      <label for="org_id">Organization</label>
      <select id="org_id" name="org_id">
        <?php foreach ($orgOptions as $id => $name): ?>
          <option value="<?= (int)$id ?>" <?= ((int)$id === (int)$orgId) ? 'selected' : '' ?>>
            <?= htmlspecialchars($name) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <label for="deal_id">Deal ID</label>
      <input id="deal_id" name="deal_id" type="text" value="<?= $dealId > 0 ? htmlspecialchars((string)$dealId) : '' ?>" placeholder="Optional">
      <?php if (!empty($products)): ?>
        <label for="product_code">Product</label>
        <select id="product_code" name="product_code">
          <?php foreach ($products as $product): ?>
            <?php $code = (string)($product['code'] ?? ''); ?>
            <option value="<?= htmlspecialchars($code) ?>" <?= strtolower($code) === strtolower($productCode) ? 'selected' : '' ?>>
              <?= htmlspecialchars($product['name'] ?? $code) ?>
            </option>
          <?php endforeach; ?>
        </select>
      <?php endif; ?>
      <button type="submit">Load</button>
    </form>

    <div class="panel">
      <h3>Deal Fields Used in the Prompt</h3>
      <div class="muted">These deal fields are used to assemble the deal context and financial summary sent to the model.</div>
      <?php if (empty($dealFieldRows)): ?>
        <div class="muted" class="mt-8">Select a deal to see the exact deal fields used.</div>
      <?php else: ?>
        <table>
          <thead>
            <tr>
              <th class="w-28">Field</th>
              <th>Value</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($dealFieldRows as $row): ?>
              <tr>
                <td><strong><?= htmlspecialchars($row[0]) ?></strong></td>
                <td><?= htmlspecialchars(format_display_value($row[1])) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

    <div class="panel" class="mt-16">
      <h3>Application Fields Used for Profile + Context</h3>
      <div class="muted">These credit app fields directly influence the profile summary and recommendation context signals.</div>
      <?php if (!$application): ?>
        <div class="muted" class="mt-8">Select a deal with an application to see the exact fields used.</div>
      <?php else: ?>
        <?php if (!empty($usageData)): ?>
          <div class="mt-8"><strong>Usage data (answers JSON)</strong></div>
          <table>
            <thead>
              <tr>
                <th class="w-28">Key</th>
                <th>Value</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($usageData as $key => $value): ?>
                <tr>
                  <td><strong><?= htmlspecialchars((string)$key) ?></strong></td>
                  <td><?= htmlspecialchars(format_display_value($value)) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
        <div class="mt-12"><strong>Application fields (non-usage data)</strong></div>
        <table>
          <thead>
            <tr>
              <th class="w-28">Field</th>
              <th>Value</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($applicationFieldRows as $row): ?>
              <tr>
                <td><strong><?= htmlspecialchars($row[0]) ?></strong></td>
                <td><?= htmlspecialchars(format_display_value($row[1])) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

    <div class="panel">
      <h3>Prompt Inputs Sent to the Model</h3>
      <div class="grid">
        <div>
          <strong>Profile summary</strong>
          <div class="muted"><?= $profileSummary !== '' ? htmlspecialchars($profileSummary) : 'Not available (select a deal with an application).' ?></div>
        </div>
        <div>
          <strong>Deal context</strong>
          <div class="muted"><?= $dealContext['deal_context'] !== '' ? htmlspecialchars($dealContext['deal_context']) : 'Not available (select a deal).' ?></div>
        </div>
        <div>
          <strong>Financial summary</strong>
          <div class="muted"><?= $dealContext['financial_summary'] !== '' ? htmlspecialchars($dealContext['financial_summary']) : 'Not available (select a deal).' ?></div>
        </div>
      </div>
    </div>

    <div class="panel" class="mt-16">
      <h3>Voice Guidelines</h3>
      <div class="grid">
        <div>
          <strong>Organization voice</strong>
          <textarea readonly rows="4"><?= htmlspecialchars($voicePrompts['org_voice'] ?? '') ?></textarea>
        </div>
        <div>
          <strong>Store voice</strong>
          <textarea readonly rows="4"><?= htmlspecialchars($voicePrompts['store_voice'] ?? '') ?></textarea>
        </div>
      </div>
    </div>

    <div class="panel" class="mt-16">
      <h3>Product Facts + Prompt Preview</h3>
      <div class="muted">Select a deal and product to see the exact prompt assembled before it is sent to the model.</div>
      <?php if (!$selectedProduct): ?>
        <div class="muted" class="mt-8">Select a deal with an application to see product-specific details.</div>
      <?php else: ?>
        <div class="mt-10">
          <strong>Default approved facts</strong>
          <textarea readonly rows="4"><?= htmlspecialchars($defaultFacts !== '' ? $defaultFacts : '[No default approved facts provided]') ?></textarea>
        </div>
        <div class="mt-10">
          <strong>Custom approved facts</strong>
          <textarea readonly rows="4"><?= htmlspecialchars($customFacts !== '' ? $customFacts : '[No custom approved facts provided]') ?></textarea>
        </div>
        <div class="mt-10">
          <strong>Matched trigger statements</strong>
          <?php if (empty($matchedDetails)): ?>
            <div class="muted">No matched details for this product.</div>
          <?php else: ?>
            <div>
              <?php foreach ($matchedDetails as $detail): ?>
                <div class="muted">- <?= htmlspecialchars($detail) ?></div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
        <div class="mt-10">
          <strong>Prompt status</strong>
          <div class="muted"><?= htmlspecialchars($promptStatus !== '' ? $promptStatus : 'Prompt status unavailable.') ?></div>
        </div>
        <?php if ($promptEligible): ?>
          <div class="mt-10">
            <strong>Prompt preview</strong>
            <pre><?= htmlspecialchars($promptPreview) ?></pre>
          </div>
        <?php endif; ?>
        <div class="mt-10">
          <strong>Latest AI output</strong>
          <?php if ($generatedCopy === ''): ?>
            <div class="muted">No generated copy found for this deal/product yet.</div>
          <?php else: ?>
            <?php if ($generatedSource !== ''): ?>
              <div class="muted"><?= htmlspecialchars($generatedSource) ?></div>
            <?php endif; ?>
            <textarea readonly rows="4"><?= htmlspecialchars($generatedCopy) ?></textarea>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>
