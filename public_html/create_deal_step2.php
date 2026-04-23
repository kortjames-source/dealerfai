<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/csrf.php';
include 'auth.php';
require_once __DIR__ . '/includes/theme_head.php';
include 'db.php';
require_once __DIR__ . '/helpers/session_utils.php';

$roles = load_session_roles();
$isAdmin = in_array('Admin', $roles, true);
$adminAlertCount = $isAdmin ? get_admin_alert_count($db) : 0;

$org = $_SESSION['deal_draft']['organization'] ?? get_effective_organization();
$theme = ['logo' => '', 'color' => '#0a2e36'];

if ($org) {
  $stmt = $db->prepare("SELECT logo_url, theme_variant FROM organizations WHERE id = ?");
  $stmt->execute([$org]);
  $orgData = $stmt->fetch(PDO::FETCH_ASSOC);
  if ($orgData) {
    $theme['logo'] = $orgData['logo_url'] ?? '';
    $theme['color'] = $orgData['theme_variant'] === 'jlr' ? '#4a4a4a' : (in_array($orgData['theme_variant'], ['land_rover', 'jaguar'], true) ? '#1A1A1A' : '#0a6280');
  }
}

if (!isset($_SESSION['deal_draft'])) {
  header("Location: create_deal");
  exit;
}

if (empty($_SESSION['deal_draft']['draft_csrf_token']) || !is_string($_SESSION['deal_draft']['draft_csrf_token'])) {
  $_SESSION['deal_draft']['draft_csrf_token'] = bin2hex(random_bytes(32));
}

// Load vehicle makes (new format: id and name)
$makeStmt = $db->query("SELECT id, make_name FROM vehicle_makes ORDER BY make_name ASC");
$vehicleMakes = $makeStmt->fetchAll(PDO::FETCH_ASSOC);


// Load vehicle colours
$colourStmt = $db->query("SELECT colour_name FROM vehicle_colours ORDER BY colour_name ASC");
$vehicleColours = $colourStmt->fetchAll(PDO::FETCH_COLUMN);

// Year range
$currentYear = date('Y');
$years = range($currentYear + 1, 2000);

// Save vehicle info to session
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $submittedToken = $_POST['csrf_token'] ?? null;
  $expectedToken = $_SESSION['deal_draft']['draft_csrf_token'] ?? null;
  $isDraftTokenValid = is_string($submittedToken)
    && is_string($expectedToken)
    && $expectedToken !== ''
    && hash_equals($expectedToken, $submittedToken);
  if (!$isDraftTokenValid) {
    http_response_code(403);
    die('Invalid request.');
  }

  $makeSelection = $_POST['vehicle_make_id'] ?? '';
  $modelSelection = $_POST['vehicle_model_id'] ?? '';
  $trimSelection = $_POST['vehicle_trim_id'] ?? '';
  $customMake = trim($_POST['custom_make'] ?? '');
  $customModel = trim($_POST['custom_model'] ?? '');
  $customTrim = trim($_POST['custom_trim'] ?? '');

  $makeId = ($makeSelection === 'custom' || trim((string)$makeSelection) === '') ? null : (int)$makeSelection;
  $_SESSION['deal_draft']['vehicle_make_id'] = $makeId;
  $_SESSION['deal_draft']['vehicle_make'] = '';
  if ($makeSelection === 'custom') {
    $_SESSION['deal_draft']['vehicle_make'] = $customMake;
  } elseif ($makeId !== null) {
    $makeStmt = $db->prepare("SELECT make_name FROM vehicle_makes WHERE id = ?");
    $makeStmt->execute([$makeId]);
    $_SESSION['deal_draft']['vehicle_make'] = (string)$makeStmt->fetchColumn();
  }

  $modelId = ($modelSelection === 'custom' || trim((string)$modelSelection) === '') ? null : (int)$modelSelection;
  $trimId = ($trimSelection === 'custom' || trim((string)$trimSelection) === '') ? null : (int)$trimSelection;
  $_SESSION['deal_draft']['vehicle_model_id'] = $modelId;
  $_SESSION['deal_draft']['vehicle_trim_id'] = $trimId;

  $modelName = '';
  $trimName = '';
  if ($modelSelection === 'custom') {
    $modelName = $customModel;
  } elseif ($modelId !== null) {
    $modelStmt = $db->prepare("SELECT model_name FROM vehicle_models WHERE id = ?");
    $modelStmt->execute([$modelId]);
    $modelName = (string)$modelStmt->fetchColumn();
  }
  if ($trimSelection === 'custom') {
    $trimName = $customTrim;
  } elseif ($trimId !== null) {
    $trimStmt = $db->prepare("SELECT trim_name FROM vehicle_trims WHERE id = ?");
    $trimStmt->execute([$trimId]);
    $trimName = (string)$trimStmt->fetchColumn();
  }
  $vehicleModelText = trim($modelName . ' ' . $trimName);
  $_SESSION['deal_draft']['vehicle_model'] = $vehicleModelText !== '' ? $vehicleModelText : ($modelName ?: '');
  $_SESSION['deal_draft']['vehicle_year']       = $_POST['vehicle_year'] ?? '';
  $_SESSION['deal_draft']['vehicle_condition']  = $_POST['vehicle_condition'] ?? 'New';
  $_SESSION['deal_draft']['vehicle_kms']        = $_POST['vehicle_kms'] ?? '';
  $_SESSION['deal_draft']['in_service_date']    = $_POST['in_service_date'] ?? '';
  $vinRaw = trim($_POST['vin'] ?? '');
  $vinNormalized = strtoupper(preg_replace('/\s+/', '', $vinRaw));
  $_SESSION['deal_draft']['vin']                = $vinNormalized;
  $_SESSION['deal_draft']['vehicle_colour']     = $_POST['vehicle_colour'] ?? '';

  header("Location: create_deal_step3");
  exit;
}

$data = $_SESSION['deal_draft'];
$csrfToken = $data['draft_csrf_token'];
$modelIdDefault = $data['vehicle_model_id'] ?? '';
$trimIdDefault = $data['vehicle_trim_id'] ?? '';
$makeIdDefault = $data['vehicle_make_id'] ?? '';
$customMakeDefault = $data['vehicle_make'] ?? '';
$customModelDefault = $data['vehicle_model'] ?? '';
$customTrimDefault = '';
$conditionDefault = $data['vehicle_condition'] ?? 'New';
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <?php include __DIR__ . "/includes/head_favicon.php"; ?>
<meta charset="UTF-8">
  <title>Step 2 – Vehicle Info</title>
  <?php dealerfai_theme_head($theme ?? null); ?>
  <style nonce="<?= dealerfai_csp_nonce() ?>">
    body { font-family: "Segoe UI", sans-serif; background: #f4f6f8; padding: 0; margin: 0; }
    .container {
      max-width: 700px; margin: 40px auto; padding: 30px; background: white;
      border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    }
    h2 { margin-top: 0; }
    label { display: block; margin-top: 15px; font-weight: bold; }
    input, select {
      width: 100%; padding: 10px; margin-top: 5px;
      border: 1px solid #ccc; border-radius: 4px;
    }
    .btn {
      background: <?= htmlspecialchars($theme['color']) ?>;
      color: white; padding: 12px 20px; border: none;
      border-radius: 4px; margin-top: 20px; font-size: 16px;
      cursor: pointer;
    }
    .btn:hover { opacity: 0.9; }
    .btn-back { background: #999; margin-right: 10px; }
  </style>
</head>
<body>

<header style="background-color: <?= htmlspecialchars($theme['color']) ?>; color:white; padding:30px 40px; text-align:center; position:relative;">
  <?php if (!empty($theme['logo'])): ?>
    <img src="<?= htmlspecialchars($theme['logo']) ?>" alt="Dealer Logo" style="max-height:60px;">
  <?php else: ?>
    <h1>DealerFAI</h1>
  <?php endif; ?>
  <div style="position:absolute; right:20px; top:20px; display:flex; gap:12px; align-items:center;">
    <?php if ($isAdmin): ?>
      <a href="admin_error_alerts.php" style="color:#fff; font-size:14px; text-decoration:none; font-weight:bold;">Alerts<?php if ($adminAlertCount > 0): ?> <span style="display:inline-block; min-width:18px; padding:2px 8px; border-radius:999px; background:#d7263d; color:#fff; font-size:12px; font-weight:bold; text-align:center; margin-left:6px;"><?= $adminAlertCount ?></span><?php endif; ?></a>
    <?php endif; ?>
    <a href="logout.php" style="color:#ccc; font-size:14px; text-decoration:none;">Log Out</a>
  </div>
</header>

<nav style="background-color: <?= htmlspecialchars($theme['color']) ?>; padding:12px; text-align:center;">
  <a href="dashboard" class="nav-link-white">Dashboard</a>
  <a href="view_deals" class="nav-link-white">View Deals</a>
  <a href="create_deal" class="nav-link-white">Create Deal</a>
  <?php if (in_array('General Manager', $roles, true) || $isAdmin): ?>
    <a href="admin_tools" class="nav-link-white">Admin Tools</a>
  <?php endif; ?>
</nav>

<div class="container">
  <h2>Step 2 of 3: Vehicle Information</h2>

  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <label for="vin">VIN (optional)</label>
    <div class="flex-gap-10">
      <input type="text" name="vin" id="vin" maxlength="32"
             value="<?= htmlspecialchars($data['vin'] ?? '') ?>" placeholder="17-character VIN" style="flex:1;">
      <button type="button" class="btn" id="vin_decode_btn" class="mt-0">Decode VIN</button>
    </div>
    <div id="vin_decode_status" style="margin-top:6px; font-size:13px; color:#555;"></div>

    <label for="vehicle_condition">Vehicle Condition</label>
    <select name="vehicle_condition" id="vehicle_condition" required>
      <option value="New" <?= ($conditionDefault === 'New') ? 'selected' : '' ?>>New</option>
      <option value="Used" <?= ($conditionDefault === 'Used') ? 'selected' : '' ?>>Used</option>
    </select>

    <label for="vehicle_year">Model Year</label>
    <select name="vehicle_year" id="vehicle_year" required>
      <option value="">-- Select Year --</option>
      <?php foreach ($years as $year): ?>
        <option value="<?= $year ?>"
          <?= ($year == ($data['vehicle_year'] ?? '')) ? 'selected' : '' ?>>
          <?= $year ?>
        </option>
      <?php endforeach; ?>
    </select>

    <label for="vehicle_make_id">Vehicle Make</label>
    <select name="vehicle_make_id" id="vehicle_make_id" required>
      <option value="">-- Select Make --</option>
      <?php foreach ($vehicleMakes as $make): ?>
        <option value="<?= $make['id'] ?>"
          <?= ($make['id'] == ($data['vehicle_make_id'] ?? '')) ? 'selected' : '' ?>>
          <?= htmlspecialchars($make['make_name']) ?>
        </option>
      <?php endforeach; ?>
      <option value="custom" <?= ($makeIdDefault === '' && $customMakeDefault !== '') ? 'selected' : '' ?>>Custom...</option>
    </select>
    <input type="text" name="custom_make" id="custom_make" placeholder="Enter custom make"
           value="<?= htmlspecialchars($customMakeDefault) ?>" class="d-none">

    <label for="vehicle_model_id">Vehicle Model</label>
    <select name="vehicle_model_id" id="vehicle_model_id" required disabled>
      <option value="">-- Select Model --</option>
    </select>
    <input type="text" name="custom_model" id="custom_model" placeholder="Enter custom model"
           value="<?= htmlspecialchars($customModelDefault) ?>" class="d-none">

    <label for="vehicle_trim_id">Vehicle Trim</label>
    <select name="vehicle_trim_id" id="vehicle_trim_id" disabled>
      <option value="">-- Select Trim (Optional) --</option>
    </select>
    <input type="text" name="custom_trim" id="custom_trim" placeholder="Enter custom trim"
           value="<?= htmlspecialchars($customTrimDefault) ?>" class="d-none">

    <label for="vehicle_kms">Odometer (km)</label>
    <input type="number" name="vehicle_kms" id="vehicle_kms"
           value="<?= htmlspecialchars($data['vehicle_kms'] ?? '') ?>" min="0">

    <label for="in_service_date">In-Service Date</label>
    <input type="date" name="in_service_date" id="in_service_date"
           value="<?= htmlspecialchars($data['in_service_date'] ?? '') ?>">

    <label for="vehicle_colour">Vehicle Colour</label>
    <select name="vehicle_colour" id="vehicle_colour" required>
      <option value="">-- Select Colour --</option>
      <?php foreach ($vehicleColours as $colour): ?>
        <option value="<?= htmlspecialchars($colour) ?>"
          <?= ($colour == ($data['vehicle_colour'] ?? '')) ? 'selected' : '' ?>>
          <?= htmlspecialchars($colour) ?>
        </option>
      <?php endforeach; ?>
    </select>

    <div class="mt-30">
      <a href="create_deal" class="btn btn-back">← Back</a>
      <button type="submit" class="btn">Next Step →</button>
    </div>
  </form>
</div>

<script nonce="<?= dealerfai_csp_nonce() ?>">
  const makeSelect = document.getElementById('vehicle_make_id');
  const yearSelect = document.getElementById('vehicle_year');
  const modelSelect = document.getElementById('vehicle_model_id');
  const trimSelect = document.getElementById('vehicle_trim_id');
  const customMakeInput = document.getElementById('custom_make');
  const customModelInput = document.getElementById('custom_model');
  const customTrimInput = document.getElementById('custom_trim');
  const vinInput = document.getElementById('vin');
  const vinDecodeBtn = document.getElementById('vin_decode_btn');
  const vinStatus = document.getElementById('vin_decode_status');
  const defaultModelId = <?= json_encode($modelIdDefault) ?>;
  const defaultTrimId = <?= json_encode($trimIdDefault) ?>;

  async function loadModels(makeId, modelYear, selectedId) {
    modelSelect.innerHTML = '<option value=\"\">-- Select Model --</option>';
    modelSelect.disabled = true;
    trimSelect.innerHTML = '<option value=\"\">-- Select Trim (Optional) --</option>';
    trimSelect.disabled = true;
    if (!makeId || !modelYear) return;
    const resp = await fetch(`api/vehicle_models.php?make_id=${encodeURIComponent(makeId)}&model_year=${encodeURIComponent(modelYear)}`);
    const data = await resp.json();
    if (Array.isArray(data.models)) {
      data.models.forEach(model => {
        const opt = document.createElement('option');
        opt.value = model.id;
        opt.textContent = model.name;
        if (String(model.id) === String(selectedId)) opt.selected = true;
        modelSelect.appendChild(opt);
      });
    }
    const customOpt = document.createElement('option');
    customOpt.value = 'custom';
    customOpt.textContent = 'Custom...';
    modelSelect.appendChild(customOpt);
    modelSelect.disabled = false;
  }

  async function loadTrims(modelId, selectedId) {
    trimSelect.innerHTML = '<option value=\"\">-- Select Trim (Optional) --</option>';
    trimSelect.disabled = true;
    if (!modelId) return;
    const resp = await fetch(`api/vehicle_trims.php?model_id=${encodeURIComponent(modelId)}`);
    const data = await resp.json();
    if (Array.isArray(data.trims)) {
      data.trims.forEach(trim => {
        const opt = document.createElement('option');
        opt.value = trim.id;
        opt.textContent = trim.name;
        if (String(trim.id) === String(selectedId)) opt.selected = true;
        trimSelect.appendChild(opt);
      });
    }
    const customOpt = document.createElement('option');
    customOpt.value = 'custom';
    customOpt.textContent = 'Custom...';
    trimSelect.appendChild(customOpt);
    trimSelect.disabled = false;
  }

  makeSelect.addEventListener('change', () => {
    if (makeSelect.value === 'custom') {
      customMakeInput.style.display = 'block';
      modelSelect.innerHTML = '<option value=\"custom\">Custom...</option>';
      modelSelect.value = 'custom';
      modelSelect.disabled = true;
      customModelInput.style.display = 'block';
      trimSelect.innerHTML = '<option value=\"custom\">Custom...</option>';
      trimSelect.value = 'custom';
      trimSelect.disabled = true;
      customTrimInput.style.display = 'block';
      return;
    }
    customMakeInput.style.display = 'none';
    customModelInput.style.display = 'none';
    customTrimInput.style.display = 'none';
    loadModels(makeSelect.value, yearSelect.value, null);
  });
  yearSelect.addEventListener('change', () => {
    if (makeSelect.value && makeSelect.value !== 'custom') {
      loadModels(makeSelect.value, yearSelect.value, null);
    }
  });
  modelSelect.addEventListener('change', () => {
    if (modelSelect.value === 'custom') {
      customModelInput.style.display = 'block';
      trimSelect.innerHTML = '<option value=\"custom\">Custom...</option>';
      trimSelect.value = 'custom';
      trimSelect.disabled = true;
      customTrimInput.style.display = 'block';
      return;
    }
    customModelInput.style.display = 'none';
    customTrimInput.style.display = 'none';
    trimSelect.disabled = false;
    loadTrims(modelSelect.value, null);
  });
  trimSelect.addEventListener('change', () => {
    if (trimSelect.value === 'custom') {
      customTrimInput.style.display = 'block';
    } else {
      customTrimInput.style.display = 'none';
    }
  });

  if (makeSelect.value) {
    if (makeSelect.value === 'custom') {
      customMakeInput.style.display = 'block';
      customModelInput.style.display = 'block';
      customTrimInput.style.display = 'block';
    } else {
      loadModels(makeSelect.value, yearSelect.value, defaultModelId).then(() => {
        if (defaultModelId) {
          loadTrims(defaultModelId, defaultTrimId);
        }
      });
    }
  }

  function normalizeVin(value) {
    return (value || '').toUpperCase().replace(/\s+/g, '');
  }

  function setStatus(message, isError = false) {
    if (!vinStatus) return;
    vinStatus.textContent = message || '';
    vinStatus.style.color = isError ? '#b00020' : '#555';
  }

  function findSelectOptionByText(selectEl, text) {
    const target = (text || '').trim().toLowerCase();
    if (!target) return null;
    const options = Array.from(selectEl.options);
    return options.find(opt => opt.textContent.trim().toLowerCase() === target) || null;
  }

  async function decodeVinAndPopulate({ force = false } = {}) {
    if (!vinInput) return;
    const vin = normalizeVin(vinInput.value);
    vinInput.value = vin;
    if (!vin) {
      setStatus('');
      return;
    }
    if (!force && vin.length !== 17) {
      setStatus('Enter a full 17-character VIN to decode.', true);
      return;
    }
    setStatus('Decoding VIN...');
    try {
      const resp = await fetch(`https://vpic.nhtsa.dot.gov/api/vehicles/DecodeVinValuesExtended/${encodeURIComponent(vin)}?format=json`);
      const data = await resp.json();
      const result = Array.isArray(data.Results) ? data.Results[0] : null;
      if (!result) {
        setStatus('No VIN data returned.', true);
        return;
      }
      const decodedYear = (result.ModelYear || '').trim();
      const decodedMake = (result.Make || '').trim();
      const decodedModel = (result.Model || '').trim();
      const decodedTrim = (result.Trim || result.Series || '').trim();

      if (decodedYear && yearSelect && yearSelect.querySelector(`option[value="${decodedYear}"]`)) {
        yearSelect.value = decodedYear;
      }

      if (decodedMake) {
        const makeOpt = findSelectOptionByText(makeSelect, decodedMake);
        if (makeOpt && makeOpt.value !== 'custom') {
          makeSelect.value = makeOpt.value;
          makeSelect.dispatchEvent(new Event('change'));
          if (decodedModel) {
            await loadModels(makeSelect.value, yearSelect.value, null);
            const modelOpt = findSelectOptionByText(modelSelect, decodedModel);
            if (modelOpt && modelOpt.value !== 'custom') {
              modelSelect.value = modelOpt.value;
              modelSelect.dispatchEvent(new Event('change'));
              if (decodedTrim) {
                await loadTrims(modelSelect.value, null);
                const trimOpt = findSelectOptionByText(trimSelect, decodedTrim);
                if (trimOpt && trimOpt.value !== 'custom') {
                  trimSelect.value = trimOpt.value;
                  trimSelect.dispatchEvent(new Event('change'));
                } else {
                  trimSelect.value = 'custom';
                  trimSelect.dispatchEvent(new Event('change'));
                  customTrimInput.value = decodedTrim;
                }
              }
            } else {
              modelSelect.value = 'custom';
              modelSelect.dispatchEvent(new Event('change'));
              customModelInput.value = decodedModel;
            }
          }
        } else {
          makeSelect.value = 'custom';
          makeSelect.dispatchEvent(new Event('change'));
          customMakeInput.value = decodedMake;
          if (decodedModel) {
            customModelInput.value = decodedModel;
          }
          if (decodedTrim) {
            customTrimInput.value = decodedTrim;
          }
        }
      }

      setStatus('VIN decoded. Please review the populated fields.');
    } catch (err) {
      setStatus('Unable to decode VIN right now.', true);
    }
  }

  if (vinDecodeBtn) {
    vinDecodeBtn.addEventListener('click', () => decodeVinAndPopulate({ force: true }));
  }
  if (vinInput) {
    vinInput.addEventListener('blur', () => decodeVinAndPopulate({ force: false }));
  }
</script>
</body>
</html>
