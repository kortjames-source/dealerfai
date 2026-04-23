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
$theme = ['logo' => '', 'color' => '#0066cc'];

if ($org) {
  $stmt = $db->prepare("SELECT logo_url, theme_variant FROM organizations WHERE id = ?");
  $stmt->execute([$org]);
  $orgData = $stmt->fetch(PDO::FETCH_ASSOC);
  if ($orgData) {
    $theme['logo'] = $orgData['logo_url'] ?? '';
    $theme['color'] = $orgData['theme_variant'] === 'jlr' ? '#4a4a4a' : (in_array($orgData['theme_variant'], ['land_rover', 'jaguar'], true) ? '#1A1A1A' : '#0066cc');
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
    /* Specific page overrides */
    .form-section { background: white; padding: 2rem; border-radius: var(--radius-lg); border: 1px solid #e2e8f0; box-shadow: var(--shadow-sm); }
    .form-group { margin-bottom: 1.5rem; }
    .form-group label { display: block; font-size: 0.875rem; font-weight: 700; color: #475569; margin-bottom: 0.5rem; text-transform: uppercase; letter-spacing: 0.025em; }
    .form-control { width: 100%; padding: 0.75rem 1rem; border: 1px solid #cbd5e1; border-radius: var(--radius-md); font-size: 1rem; transition: border-color 0.2s; }
    .form-control:focus { border-color: var(--brand-color); outline: none; box-shadow: 0 0 0 3px rgba(0, 102, 204, 0.1); }
    .step-indicator { display: flex; gap: 0.5rem; margin-bottom: 2rem; }
    .step-dot { flex: 1; height: 4px; background: #e2e8f0; border-radius: 2px; }
    .step-dot.active { background: var(--brand-color); }
    .d-none { display: none; }
    .flex-group { display: flex; gap: 0.75rem; align-items: flex-end; }
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
        <h1 class="text-gradient" style="margin-bottom: 0.5rem; display: inline-block;">Vehicle Information</h1>
        <p class="text-muted">Enter the details for the vehicle associated with this deal.</p>
      </div>

      <div class="step-indicator">
        <div class="step-dot active"></div>
        <div class="step-dot active"></div>
        <div class="step-dot"></div>
      </div>

      <form method="post" class="form-section">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        
        <div class="form-group">
          <label for="vin">VIN (Vehicle Identification Number)</label>
          <div class="flex-group">
            <input type="text" name="vin" id="vin" class="form-control" maxlength="32" value="<?= htmlspecialchars($data['vin'] ?? '') ?>" placeholder="17-character VIN">
            <button type="button" class="btn btn-outline" id="vin_decode_btn" style="white-space: nowrap;">Decode VIN</button>
          </div>
          <div id="vin_decode_status" style="margin-top: 0.5rem; font-size: 0.75rem; color: #64748b;"></div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
          <div class="form-group">
            <label for="vehicle_condition">Condition</label>
            <select name="vehicle_condition" id="vehicle_condition" class="form-control" required>
              <option value="New" <?= ($conditionDefault === 'New') ? 'selected' : '' ?>>New Vehicle</option>
              <option value="Used" <?= ($conditionDefault === 'Used') ? 'selected' : '' ?>>Used Vehicle</option>
            </select>
          </div>

          <div class="form-group">
            <label for="vehicle_year">Model Year</label>
            <select name="vehicle_year" id="vehicle_year" class="form-control" required>
              <option value="">-- Select Year --</option>
              <?php foreach ($years as $year): ?>
                <option value="<?= $year ?>" <?= ($year == ($data['vehicle_year'] ?? '')) ? 'selected' : '' ?>><?= $year ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="form-group">
          <label for="vehicle_make_id">Make</label>
          <select name="vehicle_make_id" id="vehicle_make_id" class="form-control" required>
            <option value="">-- Select Make --</option>
            <?php foreach ($vehicleMakes as $make): ?>
              <option value="<?= $make['id'] ?>" <?= ($make['id'] == ($data['vehicle_make_id'] ?? '')) ? 'selected' : '' ?>><?= htmlspecialchars($make['make_name']) ?></option>
            <?php endforeach; ?>
            <option value="custom" <?= ($makeIdDefault === '' && $customMakeDefault !== '') ? 'selected' : '' ?>>Custom...</option>
          </select>
          <input type="text" name="custom_make" id="custom_make" class="form-control d-none" style="margin-top: 0.75rem;" placeholder="Enter custom make" value="<?= htmlspecialchars($customMakeDefault) ?>">
        </div>

        <div class="form-group">
          <label for="vehicle_model_id">Model</label>
          <select name="vehicle_model_id" id="vehicle_model_id" class="form-control" required disabled>
            <option value="">-- Select Model --</option>
          </select>
          <input type="text" name="custom_model" id="custom_model" class="form-control d-none" style="margin-top: 0.75rem;" placeholder="Enter custom model" value="<?= htmlspecialchars($customModelDefault) ?>">
        </div>

        <div class="form-group">
          <label for="vehicle_trim_id">Trim (Optional)</label>
          <select name="vehicle_trim_id" id="vehicle_trim_id" class="form-control" disabled>
            <option value="">-- Select Trim --</option>
          </select>
          <input type="text" name="custom_trim" id="custom_trim" class="form-control d-none" style="margin-top: 0.75rem;" placeholder="Enter custom trim" value="<?= htmlspecialchars($customTrimDefault) ?>">
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
          <div class="form-group">
            <label for="vehicle_kms">Odometer (KM)</label>
            <input type="number" name="vehicle_kms" id="vehicle_kms" class="form-control" value="<?= htmlspecialchars($data['vehicle_kms'] ?? '') ?>" min="0" placeholder="0">
          </div>

          <div class="form-group">
            <label for="in_service_date">In-Service Date</label>
            <input type="date" name="in_service_date" id="in_service_date" class="form-control" value="<?= htmlspecialchars($data['in_service_date'] ?? '') ?>">
          </div>
        </div>

        <div class="form-group">
          <label for="vehicle_colour">Exterior Colour</label>
          <select name="vehicle_colour" id="vehicle_colour" class="form-control" required>
            <option value="">-- Select Colour --</option>
            <?php foreach ($vehicleColours as $colour): ?>
              <option value="<?= htmlspecialchars($colour) ?>" <?= ($colour == ($data['vehicle_colour'] ?? '')) ? 'selected' : '' ?>><?= htmlspecialchars($colour) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div style="margin-top: 2rem; display: flex; justify-content: space-between;">
          <a href="create_deal" class="btn btn-secondary" style="padding: 1rem 2rem;"><i class="fa-solid fa-arrow-left" style="margin-right: 0.75rem;"></i> Back</a>
          <button type="submit" class="btn btn-primary" style="padding: 1rem 2.5rem; font-weight: 700;">Continue to Step 3 <i class="fa-solid fa-arrow-right" style="margin-left: 0.75rem;"></i></button>
        </div>
      </form>
    </main>

    <footer style="background: white; border-top: 1px solid #e2e8f0; color: #64748b; padding: 1.5rem; text-align: center; position: static;">
      &copy; <?= date("Y") ?> DealerFAI. All rights reserved.
    </footer>
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
