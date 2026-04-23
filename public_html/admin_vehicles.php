<?php
include 'auth.php';
require_once __DIR__ . '/includes/theme_head.php';
include 'db.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/helpers/session_utils.php';

$roles = load_session_roles();
if (!in_array('Admin', $roles, true)) {
    http_response_code(403);
    echo 'Unauthorized';
    exit;
}
$adminAlertCount = get_admin_alert_count($db);

$message = '';
$error = '';
$currentYear = (int)date('Y');
$modelYears = range($currentYear + 1, 2000);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!dealerfai_csrf_validate($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        die('Invalid request.');
    }

    $action = $_POST['action'] ?? '';
    if ($action === 'add_make') {
        $name = trim($_POST['make_name'] ?? '');
        if ($name === '') {
            $error = 'Make name is required.';
        } else {
            $stmt = $db->prepare("INSERT INTO vehicle_makes (make_name) VALUES (?)");
            $stmt->execute([$name]);
            $message = 'Make added: ' . $name;
        }
    } elseif ($action === 'add_model') {
        $makeId = (int)($_POST['make_id'] ?? 0);
        $name = trim($_POST['model_name'] ?? '');
        $modelYear = (int)($_POST['model_year'] ?? 0);
        if ($makeId <= 0 || $name === '' || $modelYear <= 0) {
            $error = 'Make, model, and model year are required.';
        } else {
            $makeName = '';
            $makeLookup = $db->prepare("SELECT make_name FROM vehicle_makes WHERE id = ?");
            $makeLookup->execute([$makeId]);
            $makeName = trim((string)$makeLookup->fetchColumn());
            $stmt = $db->prepare("INSERT INTO vehicle_models (make_id, model_name, model_year) VALUES (?, ?, ?)");
            $stmt->execute([$makeId, $name, $modelYear]);
            $message = 'Model added: ' . $modelYear . ' ' . ($makeName !== '' ? ($makeName . ' ') : '') . $name;
        }
    } elseif ($action === 'add_trim') {
        $modelId = (int)($_POST['model_id'] ?? 0);
        $name = trim($_POST['trim_name'] ?? '');
        if ($modelId <= 0 || $name === '') {
            $error = 'Model and trim are required.';
        } else {
            $modelName = '';
            $modelLookup = $db->prepare("SELECT model_name, model_year FROM vehicle_models WHERE id = ?");
            $modelLookup->execute([$modelId]);
            $modelRow = $modelLookup->fetch(PDO::FETCH_ASSOC);
            if (is_array($modelRow)) {
                $modelName = trim((string)($modelRow['model_name'] ?? ''));
                $modelYear = (int)($modelRow['model_year'] ?? 0);
            }
            $stmt = $db->prepare("INSERT INTO vehicle_trims (model_id, trim_name) VALUES (?, ?)");
            $stmt->execute([$modelId, $name]);
            $prefix = '';
            if (!empty($modelYear)) {
                $prefix .= $modelYear . ' ';
            }
            if ($modelName !== '') {
                $prefix .= $modelName . ' ';
            }
            $message = 'Trim added: ' . trim($prefix . $name);
        }
    } elseif ($action === 'deactivate_make') {
        $id = (int)($_POST['make_id'] ?? 0);
        if ($id > 0) {
            $makeName = '';
            $makeLookup = $db->prepare("SELECT make_name FROM vehicle_makes WHERE id = ?");
            $makeLookup->execute([$id]);
            $makeName = trim((string)$makeLookup->fetchColumn());
            $db->prepare("UPDATE vehicle_makes SET make_name = make_name WHERE id = ?")->execute([$id]);
            $db->prepare("UPDATE vehicle_models SET active = 0 WHERE make_id = ?")->execute([$id]);
            $db->prepare("UPDATE vehicle_makes SET make_name = make_name WHERE id = ?")->execute([$id]);
            $message = 'Make deactivated (models set inactive): ' . ($makeName !== '' ? $makeName : ('ID ' . $id));
        }
    } elseif ($action === 'deactivate_model') {
        $id = (int)($_POST['model_id'] ?? 0);
        if ($id > 0) {
            $modelLabel = '';
            $modelLookup = $db->prepare("
                SELECT vm.model_name, vm.model_year, m.make_name
                FROM vehicle_models vm
                JOIN vehicle_makes m ON m.id = vm.make_id
                WHERE vm.id = ?
            ");
            $modelLookup->execute([$id]);
            $modelRow = $modelLookup->fetch(PDO::FETCH_ASSOC);
            if (is_array($modelRow)) {
                $modelLabel = trim(
                    ((int)($modelRow['model_year'] ?? 0) > 0 ? ((int)$modelRow['model_year'] . ' ') : '') .
                    trim((string)($modelRow['make_name'] ?? '')) . ' ' .
                    trim((string)($modelRow['model_name'] ?? ''))
                );
            }
            $db->prepare("UPDATE vehicle_models SET active = 0 WHERE id = ?")->execute([$id]);
            $db->prepare("UPDATE vehicle_trims SET active = 0 WHERE model_id = ?")->execute([$id]);
            $message = 'Model deactivated: ' . ($modelLabel !== '' ? $modelLabel : ('ID ' . $id));
        }
    } elseif ($action === 'update_model') {
        $id = (int)($_POST['model_id'] ?? 0);
        $name = trim($_POST['model_name'] ?? '');
        $modelYear = (int)($_POST['model_year'] ?? 0);
        if ($id > 0 && $name !== '' && $modelYear > 0) {
            $makeName = '';
            $makeLookup = $db->prepare("SELECT m.make_name FROM vehicle_models vm JOIN vehicle_makes m ON m.id = vm.make_id WHERE vm.id = ?");
            $makeLookup->execute([$id]);
            $makeName = trim((string)$makeLookup->fetchColumn());
            $stmt = $db->prepare("UPDATE vehicle_models SET model_name = ?, model_year = ? WHERE id = ?");
            $stmt->execute([$name, $modelYear, $id]);
            $message = 'Model updated: ' . $modelYear . ' ' . ($makeName !== '' ? ($makeName . ' ') : '') . $name;
        }
    } elseif ($action === 'delete_model') {
        $id = (int)($_POST['model_id'] ?? 0);
        if ($id > 0) {
            $modelLabel = '';
            $modelLookup = $db->prepare("
                SELECT vm.model_name, vm.model_year, m.make_name
                FROM vehicle_models vm
                JOIN vehicle_makes m ON m.id = vm.make_id
                WHERE vm.id = ?
            ");
            $modelLookup->execute([$id]);
            $modelRow = $modelLookup->fetch(PDO::FETCH_ASSOC);
            if (is_array($modelRow)) {
                $modelLabel = trim(
                    ((int)($modelRow['model_year'] ?? 0) > 0 ? ((int)$modelRow['model_year'] . ' ') : '') .
                    trim((string)($modelRow['make_name'] ?? '')) . ' ' .
                    trim((string)($modelRow['model_name'] ?? ''))
                );
            }
            $db->beginTransaction();
            try {
                $db->prepare("DELETE FROM vehicle_trims WHERE model_id = ?")->execute([$id]);
                $db->prepare("DELETE FROM vehicle_models WHERE id = ?")->execute([$id]);
                $db->commit();
                $message = 'Model deleted: ' . ($modelLabel !== '' ? $modelLabel : ('ID ' . $id));
            } catch (PDOException $e) {
                $db->rollBack();
                $error = 'Failed to delete model.';
            }
        }
    } elseif ($action === 'deactivate_trim') {
        $id = (int)($_POST['trim_id'] ?? 0);
        if ($id > 0) {
            $trimLabel = '';
            $trimLookup = $db->prepare("
                SELECT vt.trim_name, vm.model_name, vm.model_year, m.make_name
                FROM vehicle_trims vt
                JOIN vehicle_models vm ON vm.id = vt.model_id
                JOIN vehicle_makes m ON m.id = vm.make_id
                WHERE vt.id = ?
            ");
            $trimLookup->execute([$id]);
            $trimRow = $trimLookup->fetch(PDO::FETCH_ASSOC);
            if (is_array($trimRow)) {
                $trimLabel = trim(
                    ((int)($trimRow['model_year'] ?? 0) > 0 ? ((int)$trimRow['model_year'] . ' ') : '') .
                    trim((string)($trimRow['make_name'] ?? '')) . ' ' .
                    trim((string)($trimRow['model_name'] ?? '')) . ' ' .
                    trim((string)($trimRow['trim_name'] ?? ''))
                );
            }
            $db->prepare("UPDATE vehicle_trims SET active = 0 WHERE id = ?")->execute([$id]);
            $message = 'Trim deactivated: ' . ($trimLabel !== '' ? $trimLabel : ('ID ' . $id));
        }
    } elseif ($action === 'update_trim') {
        $id = (int)($_POST['trim_id'] ?? 0);
        $name = trim($_POST['trim_name'] ?? '');
        if ($id > 0 && $name !== '') {
            $prefix = '';
            $trimLookup = $db->prepare("
                SELECT vm.model_name, vm.model_year, m.make_name
                FROM vehicle_trims vt
                JOIN vehicle_models vm ON vm.id = vt.model_id
                JOIN vehicle_makes m ON m.id = vm.make_id
                WHERE vt.id = ?
            ");
            $trimLookup->execute([$id]);
            $trimRow = $trimLookup->fetch(PDO::FETCH_ASSOC);
            if (is_array($trimRow)) {
                $prefix = trim(
                    ((int)($trimRow['model_year'] ?? 0) > 0 ? ((int)$trimRow['model_year'] . ' ') : '') .
                    trim((string)($trimRow['make_name'] ?? '')) . ' ' .
                    trim((string)($trimRow['model_name'] ?? ''))
                );
            }
            $stmt = $db->prepare("UPDATE vehicle_trims SET trim_name = ? WHERE id = ?");
            $stmt->execute([$name, $id]);
            $message = 'Trim updated: ' . trim(($prefix !== '' ? ($prefix . ' ') : '') . $name);
        }
    } elseif ($action === 'delete_trim') {
        $id = (int)($_POST['trim_id'] ?? 0);
        if ($id > 0) {
            $trimLabel = '';
            $trimLookup = $db->prepare("
                SELECT vt.trim_name, vm.model_name, vm.model_year, m.make_name
                FROM vehicle_trims vt
                JOIN vehicle_models vm ON vm.id = vt.model_id
                JOIN vehicle_makes m ON m.id = vm.make_id
                WHERE vt.id = ?
            ");
            $trimLookup->execute([$id]);
            $trimRow = $trimLookup->fetch(PDO::FETCH_ASSOC);
            if (is_array($trimRow)) {
                $trimLabel = trim(
                    ((int)($trimRow['model_year'] ?? 0) > 0 ? ((int)$trimRow['model_year'] . ' ') : '') .
                    trim((string)($trimRow['make_name'] ?? '')) . ' ' .
                    trim((string)($trimRow['model_name'] ?? '')) . ' ' .
                    trim((string)($trimRow['trim_name'] ?? ''))
                );
            }
            $stmt = $db->prepare("DELETE FROM vehicle_trims WHERE id = ?");
            $stmt->execute([$id]);
            $message = 'Trim deleted: ' . ($trimLabel !== '' ? $trimLabel : ('ID ' . $id));
        }
    }

    if ($message !== '' || $error !== '') {
        $_SESSION['admin_vehicle_flash'] = ['message' => $message, 'error' => $error];
        header('Location: admin_vehicles.php');
        exit;
    }
}
$csrfToken = dealerfai_csrf_get_token();

if (isset($_SESSION['admin_vehicle_flash'])) {
    $flash = $_SESSION['admin_vehicle_flash'];
    unset($_SESSION['admin_vehicle_flash']);
    if (!empty($flash['message'])) {
        $message = $flash['message'];
    }
    if (!empty($flash['error'])) {
        $error = $flash['error'];
    }
}

$makes = $db->query("SELECT id, make_name FROM vehicle_makes ORDER BY make_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$models = $db->query("SELECT vm.id, vm.model_name, vm.model_year, vm.make_id, m.make_name FROM vehicle_models vm JOIN vehicle_makes m ON m.id = vm.make_id WHERE vm.active = 1 ORDER BY m.make_name ASC, vm.model_year DESC, vm.model_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$trims = $db->query("SELECT vt.id, vt.trim_name, vt.model_id, vm.model_name, m.make_name FROM vehicle_trims vt JOIN vehicle_models vm ON vm.id = vt.model_id JOIN vehicle_makes m ON m.id = vm.make_id WHERE vt.active = 1 ORDER BY m.make_name ASC, vm.model_name ASC, vt.trim_name ASC")->fetchAll(PDO::FETCH_ASSOC);

$modelsByMake = [];
foreach ($models as $model) {
    $makeId = (int)($model['make_id'] ?? 0);
    if ($makeId <= 0) {
        continue;
    }
    if (!isset($modelsByMake[$makeId])) {
        $modelsByMake[$makeId] = [];
    }
    $modelsByMake[$makeId][] = $model;
}

$trimsByModel = [];
foreach ($trims as $trim) {
    $modelId = (int)($trim['model_id'] ?? 0);
    if ($modelId <= 0) {
        continue;
    }
    if (!isset($trimsByModel[$modelId])) {
        $trimsByModel[$modelId] = [];
    }
    $trimsByModel[$modelId][] = $trim;
}
?>
<!DOCTYPE html>
<html>
<head>
  <?php include __DIR__ . "/includes/head_favicon.php"; ?>
  <meta charset="UTF-8">
  <title>Admin - Vehicles</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php dealerfai_theme_head(); ?>
  <style nonce="<?= dealerfai_csp_nonce() ?>">
    body { font-family: Arial, sans-serif; background: #f4f6f8; margin: 0; padding: 0; }
    header { background: #0066cc; color: white; padding: 20px; text-align: center; position: relative; }
    nav { background: #0066cc; padding: 12px; text-align: center; }
    nav a { color: white; margin: 0 20px; text-decoration: none; font-weight: bold; }
    .container { max-width: 1100px; margin: 20px auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
    h2 { margin-top: 0; }
    table { width: 100%; border-collapse: collapse; margin-top: 16px; }
    th, td { padding: 10px; border-bottom: 1px solid #ddd; vertical-align: top; text-align: left; }
    th { background: #f1f4f8; }
    input[type="text"], select { padding: 6px; border-radius: 4px; border: 1px solid #ccc; width: 100%; }
    .btn { background: #0066cc; color: white; padding: 8px 14px; border: none; border-radius: 4px; cursor: pointer; }
    .btn:hover { background: #094c63; }
    .success { color: green; margin-top: 10px; }
    .error { color: #c0392b; margin-top: 10px; }
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
    <h2>Vehicle Makes, Models, Trims</h2>

    <?php if ($message): ?><p class="success">✅ <?= htmlspecialchars($message) ?></p><?php endif; ?>
    <?php if ($error): ?><p class="error">⚠️ <?= htmlspecialchars($error) ?></p><?php endif; ?>

    <h3>Add Make</h3>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="action" value="add_make">
      <input type="text" name="make_name" placeholder="Make name" required>
      <button type="submit" class="btn" class="mt-8">Add Make</button>
    </form>

    <h3 class="mt-20">Add Model</h3>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="action" value="add_model">
      <select name="model_year" required>
        <option value="">Select model year</option>
        <?php foreach ($modelYears as $year): ?>
          <option value="<?= (int)$year ?>" <?= ((int)$year === 2026) ? 'selected' : '' ?>><?= (int)$year ?></option>
        <?php endforeach; ?>
      </select>
      <select name="make_id" required>
        <option value="">Select make</option>
        <?php foreach ($makes as $make): ?>
          <option value="<?= (int)$make['id'] ?>"><?= htmlspecialchars($make['make_name']) ?></option>
        <?php endforeach; ?>
      </select>
      <input type="text" name="model_name" placeholder="Model name" required class="mt-8">
      <button type="submit" class="btn" class="mt-8">Add Model</button>
    </form>

    <h3 class="mt-20">Add Trim</h3>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="action" value="add_trim">
      <select name="model_year" id="trim_model_year" required>
        <option value="">Select model year</option>
        <?php foreach ($modelYears as $year): ?>
          <option value="<?= (int)$year ?>" <?= ((int)$year === 2026) ? 'selected' : '' ?>><?= (int)$year ?></option>
        <?php endforeach; ?>
      </select>
      <select name="make_id" id="trim_make" required>
        <option value="">Select make</option>
        <?php foreach ($makes as $make): ?>
          <option value="<?= (int)$make['id'] ?>"><?= htmlspecialchars($make['make_name']) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="model_id" id="trim_model" required class="mt-8">
        <option value="">Select model</option>
      </select>
      <input type="text" name="trim_name" placeholder="Trim name" required class="mt-8">
      <button type="submit" class="btn" class="mt-8">Add Trim</button>
    </form>

    <h3 class="mt-30">Makes, Models, Trims</h3>
    <?php if (empty($makes)): ?>
      <p style="color:#667085;">No makes found in the database.</p>
    <?php else: ?>
      <?php foreach ($makes as $make): ?>
        <?php $makeId = (int)($make['id'] ?? 0); ?>
        <div style="border:1px solid #e4e7ec; border-radius:8px; padding:12px; margin-bottom:12px;">
          <details>
            <summary style="font-weight:bold; font-size:1.05rem; cursor:pointer;"><?= htmlspecialchars($make['make_name']) ?></summary>
            <?php $makeModels = $modelsByMake[$makeId] ?? []; ?>
            <?php if (empty($makeModels)): ?>
              <p class="muted" style="margin:8px 0 0;">No models yet.</p>
            <?php else: ?>
              <?php foreach ($makeModels as $model): ?>
                <?php $modelId = (int)($model['id'] ?? 0); ?>
                <div style="margin-top:10px; padding-left:12px; border-left:3px solid #e4e7ec;">
                  <details>
                    <summary style="cursor:pointer; font-weight:600;">
                      <?= htmlspecialchars((string)$model['model_year']) ?> <?= htmlspecialchars($model['model_name']) ?>
                    </summary>
                    <div style="margin-top:6px; display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                      <form method="post" style="display:flex; gap:6px; align-items:center;">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="update_model">
                        <input type="hidden" name="model_id" value="<?= (int)$model['id'] ?>">
                        <select name="model_year" style="max-width:130px;">
                          <?php foreach ($modelYears as $year): ?>
                            <option value="<?= (int)$year ?>" <?= ((int)$year === (int)$model['model_year']) ? 'selected' : '' ?>><?= (int)$year ?></option>
                          <?php endforeach; ?>
                        </select>
                        <input type="text" name="model_name" value="<?= htmlspecialchars($model['model_name']) ?>" style="max-width:220px;">
                        <button class="btn" type="submit" class="p-4-10">Save</button>
                      </form>
                      <form method="post" class="d-inline">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="deactivate_model">
                        <input type="hidden" name="model_id" value="<?= (int)$model['id'] ?>">
                        <button class="btn" type="submit" class="p-4-10">Deactivate</button>
                      </form>
                      <form method="post" class="d-inline">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="delete_model">
                        <input type="hidden" name="model_id" value="<?= (int)$model['id'] ?>">
                        <button class="btn" type="submit" class="p-4-10-danger" onclick="return confirm('Delete this model and all its trims?');">Delete</button>
                      </form>
                    </div>
                    <?php $modelTrims = $trimsByModel[$modelId] ?? []; ?>
                    <?php if (empty($modelTrims)): ?>
                      <p class="muted" style="margin:8px 0 0;">No trims yet.</p>
                    <?php else: ?>
                      <div class="mt-8">
                        <?php foreach ($modelTrims as $trim): ?>
                          <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; padding:6px 0; border-bottom:1px dashed #e4e7ec;">
                            <form method="post" style="display:flex; gap:6px; align-items:center; flex-wrap:wrap;">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                              <input type="hidden" name="action" value="update_trim">
                              <input type="hidden" name="trim_id" value="<?= (int)$trim['id'] ?>">
                              <input type="text" name="trim_name" value="<?= htmlspecialchars($trim['trim_name']) ?>" style="max-width:220px;">
                              <button class="btn" type="submit" class="p-4-10">Save</button>
                            </form>
                            <div style="display:flex; gap:6px;">
                              <form method="post" class="d-inline">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="action" value="deactivate_trim">
                                <input type="hidden" name="trim_id" value="<?= (int)$trim['id'] ?>">
                                <button class="btn" type="submit" class="p-4-10">Deactivate</button>
                              </form>
                              <form method="post" class="d-inline">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="action" value="delete_trim">
                                <input type="hidden" name="trim_id" value="<?= (int)$trim['id'] ?>">
                                <button class="btn" type="submit" class="p-4-10-danger" onclick="return confirm('Delete this trim?');">Delete</button>
                              </form>
                            </div>
                          </div>
                        <?php endforeach; ?>
                      </div>
                    <?php endif; ?>
                  </details>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </details>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
  <script nonce="<?= dealerfai_csp_nonce() ?>">
    const trimModelYear = document.getElementById('trim_model_year');
    const trimMake = document.getElementById('trim_make');
    const trimModel = document.getElementById('trim_model');
    if (trimModelYear && trimMake && trimModel) {
      const refreshTrimModels = () => {
        const makeId = trimMake.value || '';
        const modelYear = trimModelYear.value || '';
        trimModel.innerHTML = '<option value="">Select model</option>';
        if (!makeId || !modelYear) {
          return;
        }
        fetch(`api/vehicle_models.php?make_id=${encodeURIComponent(makeId)}&model_year=${encodeURIComponent(modelYear)}`)
          .then(resp => resp.json())
          .then(data => {
            (data.models || []).forEach(model => {
              const opt = document.createElement('option');
              opt.value = model.id;
              opt.textContent = model.name;
              trimModel.appendChild(opt);
            });
          })
          .catch(() => {});
      };
      trimModelYear.addEventListener('change', refreshTrimModels);
      trimMake.addEventListener('change', refreshTrimModels);
    }
  </script>
</body>
</html>
