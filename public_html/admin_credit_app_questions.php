<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/security_headers.php';
require_once __DIR__ . '/includes/theme_head.php';
require_once __DIR__ . '/includes/csrf.php';
include 'db.php';
require_once __DIR__ . '/helpers/session_utils.php';
require_once __DIR__ . '/helpers/organization_question_config.php';
require_once __DIR__ . '/helpers/credit_question_tag_map.php';

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

$editId = isset($_GET['edit_id']) ? (int)$_GET['edit_id'] : 0;
$errors = [];
$success = '';
$allowedSteps = ['step1', 'step2', 'step3'];
$allowedTypes = ['text', 'number', 'date', 'select', 'multiselect', 'checkbox', 'textarea'];
$creditQuestionCatalog = get_credit_app_question_catalog();
$creditQuestionConfig = get_org_credit_app_question_config($db, $orgId);
$tagMap = get_credit_question_tag_map();

$catalogFieldKeys = [];
foreach ($creditQuestionCatalog as $step => $questions) {
  foreach ($questions as $question) {
    $catalogFieldKeys[] = $question['id'];
  }
}
$catalogFieldKeys = array_values(array_unique($catalogFieldKeys));
sort($catalogFieldKeys);

$existingFieldKeys = [];
$existingStmt = $db->prepare("SELECT DISTINCT field_key FROM credit_app_custom_questions WHERE organization_id IN (0, ?) ORDER BY field_key ASC");
$existingStmt->execute([$orgId]);
$existingFieldKeys = $existingStmt->fetchAll(PDO::FETCH_COLUMN);
$allowedFieldKeys = array_values(array_unique(array_merge($catalogFieldKeys, $existingFieldKeys)));
sort($allowedFieldKeys);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!dealerfai_csrf_validate($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    die('Invalid request.');
  }

  $action = $_POST['action'] ?? '';
  $postOrgId = isset($_POST['org_id']) ? (int)$_POST['org_id'] : $orgId;
  if ($postOrgId <= 0 || !array_key_exists($postOrgId, $orgOptions)) {
    $errors[] = 'Please select a valid organization.';
  }

  if ($action === 'delete') {
    $deleteId = isset($_POST['question_id']) ? (int)$_POST['question_id'] : 0;
    if ($deleteId > 0 && empty($errors)) {
      $stmt = $db->prepare("DELETE FROM credit_app_custom_questions WHERE id = ? AND organization_id = ?");
      $stmt->execute([$deleteId, $postOrgId]);
      header("Location: admin_credit_app_questions.php?org_id=" . urlencode((string)$postOrgId) . "&deleted=1");
      exit;
    }
  } elseif ($action === 'save') {
    $step = $_POST['step'] ?? 'step1';
    $label = trim($_POST['label'] ?? '');
    $fieldKey = trim($_POST['field_key'] ?? '');
    $fieldType = $_POST['field_type'] ?? 'text';
    $optionsRaw = trim($_POST['options'] ?? '');
    $isRequired = !empty($_POST['is_required']) ? 1 : 0;
    $isActive = !empty($_POST['is_active']) ? 1 : 0;
    $sortOrder = (int)($_POST['sort_order'] ?? 0);
    $questionId = isset($_POST['question_id']) ? (int)$_POST['question_id'] : 0;

    if (!in_array($step, $allowedSteps, true)) {
      $errors[] = 'Invalid step selected.';
    }
    if ($label === '') {
      $errors[] = 'Question label is required.';
    }
    if ($fieldKey === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $fieldKey)) {
      $errors[] = 'Field key must use letters, numbers, or underscores only.';
    } elseif (!in_array($fieldKey, $allowedFieldKeys, true)) {
      $errors[] = 'Field key must be selected from the existing list.';
    }
    if (!in_array($fieldType, $allowedTypes, true)) {
      $errors[] = 'Invalid field type selected.';
    }

    $options = [];
    if (in_array($fieldType, ['select', 'multiselect'], true)) {
      $lines = preg_split('/\r\n|\r|\n/', $optionsRaw);
      foreach ($lines as $line) {
        $line = trim($line);
        if ($line !== '') {
          $options[] = $line;
        }
      }
      if (empty($options)) {
        $errors[] = 'Options are required for select fields.';
      }
    }
    $optionsJson = empty($options) ? null : json_encode($options);

    if (empty($errors)) {
      if ($questionId > 0) {
        $stmt = $db->prepare("
          UPDATE credit_app_custom_questions
          SET step = ?, label = ?, field_key = ?, field_type = ?, options_json = ?, is_required = ?, is_active = ?, sort_order = ?
          WHERE id = ? AND organization_id = ?
        ");
        $stmt->execute([
          $step,
          $label,
          $fieldKey,
          $fieldType,
          $optionsJson,
          $isRequired,
          $isActive,
          $sortOrder,
          $questionId,
          $postOrgId
        ]);
        header("Location: admin_credit_app_questions.php?org_id=" . urlencode((string)$postOrgId) . "&updated=1");
        exit;
      }
      $stmt = $db->prepare("
        INSERT INTO credit_app_custom_questions
          (organization_id, step, label, field_key, field_type, options_json, is_required, is_active, sort_order, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
      ");
      $stmt->execute([
        $postOrgId,
        $step,
        $label,
        $fieldKey,
        $fieldType,
        $optionsJson,
        $isRequired,
        $isActive,
        $sortOrder
      ]);
      header("Location: admin_credit_app_questions.php?org_id=" . urlencode((string)$postOrgId) . "&added=1");
      exit;
    }
  }
}
$csrfToken = dealerfai_csrf_get_token();

$questions = [];
$editQuestion = null;
if ($orgId > 0) {
  $stmt = $db->prepare("
    SELECT *
    FROM credit_app_custom_questions
    WHERE organization_id = ?
    ORDER BY step ASC, sort_order ASC, id ASC
  ");
  $stmt->execute([$orgId]);
  $questions = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

if ($editId > 0 && $orgId > 0) {
  $stmt = $db->prepare("
    SELECT *
    FROM credit_app_custom_questions
    WHERE id = ? AND organization_id = ?
  ");
  $stmt->execute([$editId, $orgId]);
  $editQuestion = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$formStep = $editQuestion['step'] ?? 'step1';
$formLabel = $editQuestion['label'] ?? '';
$formFieldKey = $editQuestion['field_key'] ?? '';
$formFieldType = $editQuestion['field_type'] ?? 'text';
$formOptions = '';
if (!empty($editQuestion['options_json'])) {
  $decoded = json_decode($editQuestion['options_json'], true);
  if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
    $formOptions = implode("\n", $decoded);
  }
}
$formIsRequired = !empty($editQuestion['is_required']);
$formIsActive = array_key_exists('is_active', (array)$editQuestion) ? !empty($editQuestion['is_active']) : true;
$formSortOrder = (int)($editQuestion['sort_order'] ?? 0);

$builtInQuestions = [];
foreach ($creditQuestionCatalog as $step => $questionsInStep) {
  foreach ($questionsInStep as $question) {
    $visible = credit_app_question_is_visible($creditQuestionConfig, $step, $question['id']);
    $builtInQuestions[] = [
      'step' => $step,
      'label' => $question['label'],
      'field_key' => $question['id'],
      'is_active' => $visible,
      'source' => 'Built-in',
    ];
  }
}

$activeCustomQuestions = [];
foreach ($questions as $question) {
  $activeCustomQuestions[] = [
    'step' => $question['step'],
    'label' => $question['label'],
    'field_key' => $question['field_key'],
    'is_active' => !empty($question['is_active']),
    'source' => 'Custom',
  ];
}

$currentQuestions = array_merge($builtInQuestions, $activeCustomQuestions);
usort($currentQuestions, function (array $a, array $b): int {
  if ($a['step'] === $b['step']) {
    return strcmp($a['label'], $b['label']);
  }
  return strcmp($a['step'], $b['step']);
});
?>
<!DOCTYPE html>
<html>
<head>
  <?php include __DIR__ . "/includes/head_favicon.php"; ?>
<meta charset="UTF-8">
  <title>Admin - Credit App Questions</title>
  <?php dealerfai_theme_head(); ?>
  <style nonce="<?= dealerfai_csp_nonce() ?>">
    body { font-family: Arial, sans-serif; background: #f4f6f8; margin: 0; padding: 0; }
    header { background: #0a2e36; color: white; padding: 20px; text-align: center; position: relative; }
    nav { background: #0a2e36; padding: 12px; text-align: center; }
    nav a { color: white; margin: 0 20px; text-decoration: none; font-weight: bold; }
    .container { max-width: 1100px; margin: 20px auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
    label { display: block; margin-top: 12px; font-weight: bold; }
    input[type="text"], input[type="number"], select, textarea {
      width: 100%; padding: 8px; margin-top: 5px; border: 1px solid #ccc; border-radius: 4px;
    }
    textarea { resize: vertical; }
    .btn { background: #0a6280; color: white; padding: 10px 18px; border: none; border-radius: 4px; margin-top: 12px; cursor: pointer; font-size: 16px; text-decoration: none; display: inline-block; }
    .btn.secondary { background: #6c757d; }
    .btn.danger { background: #6a1a1a; }
    .btn:hover { background: #094c63; }
    .btn.secondary:hover { background: #5b646c; }
    .btn.danger:hover { background: #4f1313; }
    .note { color: #5a6a7a; margin-top: 6px; font-size: 0.95rem; }
    .error { color: #b00020; margin-top: 10px; }
    table { width: 100%; border-collapse: collapse; margin-top: 20px; }
    th, td { text-align: left; padding: 10px; border-bottom: 1px solid #e0e0e0; }
    th { background: #f5f7fa; }
    .row-actions { display: flex; gap: 8px; }
    .top-row { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; }
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
  <div class="top-row">
    <h2>Credit App Questions</h2>
    <a class="btn secondary" href="admin_tools.php"><- Back to admin tools</a>
  </div>

  <?php foreach ($errors as $error): ?>
    <p class="error"><?= htmlspecialchars($error) ?></p>
  <?php endforeach; ?>

  <form method="get" class="mt-10">
    <label>Select organization</label>
    <select name="org_id" onchange="this.form.submit()">
      <option value="">Select an organization</option>
      <?php foreach ($orgOptions as $id => $name): ?>
        <option value="<?= (int)$id ?>" <?= $orgId === (int)$id ? 'selected' : '' ?>><?= htmlspecialchars($name) ?></option>
      <?php endforeach; ?>
    </select>
  </form>

  <?php if ($orgId >= 0 && array_key_exists($orgId, $orgOptions)): ?>
    <h3 class="mt-24">Current Questions (Built-in + Custom)</h3>
    <?php if (empty($currentQuestions)): ?>
      <p class="note">No questions found for this organization.</p>
    <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>Step</th>
            <th>Label</th>
            <th>Field Key</th>
            <th>Triggers</th>
            <th>Active</th>
            <th>Source</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($currentQuestions as $question): ?>
            <?php
              $fieldKey = $question['field_key'] ?? '';
              $tags = $tagMap[$fieldKey] ?? [];
              $triggerText = empty($tags) ? '—' : implode(', ', $tags);
            ?>
            <tr>
              <td><?= htmlspecialchars(strtoupper($question['step'])) ?></td>
              <td><?= htmlspecialchars($question['label']) ?></td>
              <td><?= htmlspecialchars($fieldKey) ?></td>
              <td><?= htmlspecialchars($triggerText) ?></td>
              <td><?= !empty($question['is_active']) ? 'Yes' : 'No' ?></td>
              <td><?= htmlspecialchars($question['source']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <h3 class="mt-24"><?= $editQuestion ? 'Edit Question' : 'Add Question' ?></h3>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="org_id" value="<?= (int)$orgId ?>">
      <?php if ($editQuestion): ?>
        <input type="hidden" name="question_id" value="<?= (int)$editQuestion['id'] ?>">
      <?php endif; ?>

      <label>Step</label>
      <select name="step">
        <?php foreach ($allowedSteps as $step): ?>
          <option value="<?= $step ?>" <?= $formStep === $step ? 'selected' : '' ?>><?= strtoupper($step) ?></option>
        <?php endforeach; ?>
      </select>

      <label>Question Label</label>
      <input type="text" name="label" value="<?= htmlspecialchars($formLabel) ?>" required>

      <label>Field Key (choose from list)</label>
      <input type="text" name="field_key" list="field-key-list" value="<?= htmlspecialchars($formFieldKey) ?>" required>
      <datalist id="field-key-list">
        <?php foreach ($allowedFieldKeys as $fieldKey): ?>
          <option value="<?= htmlspecialchars($fieldKey) ?>"></option>
        <?php endforeach; ?>
      </datalist>
      <p class="note">Pick an existing key to avoid creating new scoring tags.</p>

      <label>Field Type</label>
      <select name="field_type" id="field-type-select" onchange="toggleOptions()">
        <?php foreach ($allowedTypes as $type): ?>
          <option value="<?= $type ?>" <?= $formFieldType === $type ? 'selected' : '' ?>><?= ucfirst($type) ?></option>
        <?php endforeach; ?>
      </select>

      <div id="options-block">
        <label>Options (one per line)</label>
        <textarea name="options" rows="4"><?= htmlspecialchars($formOptions) ?></textarea>
      </div>

      <label><input type="checkbox" name="is_required" value="1" <?= $formIsRequired ? 'checked' : '' ?>> Required</label>
      <label><input type="checkbox" name="is_active" value="1" <?= $formIsActive ? 'checked' : '' ?>> Active</label>

      <label>Sort Order</label>
      <input type="number" name="sort_order" value="<?= (int)$formSortOrder ?>">

      <button type="submit" class="btn"><?= $editQuestion ? 'Save Changes' : 'Add Question' ?></button>
      <?php if ($editQuestion): ?>
        <a class="btn secondary" href="admin_credit_app_questions.php?org_id=<?= (int)$orgId ?>">Cancel</a>
      <?php endif; ?>
    </form>

    <h3 class="mt-30">Custom Questions (Manage)</h3>
    <?php if (empty($questions)): ?>
      <p class="note">No custom questions yet.</p>
    <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>Step</th>
            <th>Label</th>
            <th>Field Key</th>
            <th>Type</th>
            <th>Required</th>
            <th>Active</th>
            <th>Order</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($questions as $question): ?>
            <tr>
              <td><?= htmlspecialchars(strtoupper($question['step'])) ?></td>
              <td><?= htmlspecialchars($question['label']) ?></td>
              <td><?= htmlspecialchars($question['field_key']) ?></td>
              <td><?= htmlspecialchars($question['field_type']) ?></td>
              <td><?= !empty($question['is_required']) ? 'Yes' : 'No' ?></td>
              <td><?= !empty($question['is_active']) ? 'Yes' : 'No' ?></td>
              <td><?= (int)$question['sort_order'] ?></td>
              <td>
                <div class="row-actions">
                  <a class="btn secondary" href="admin_credit_app_questions.php?org_id=<?= (int)$orgId ?>&edit_id=<?= (int)$question['id'] ?>">Edit</a>
                  <form method="post" onsubmit="return confirm('Delete this question?');">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="org_id" value="<?= (int)$orgId ?>">
                    <input type="hidden" name="question_id" value="<?= (int)$question['id'] ?>">
                    <button type="submit" class="btn danger">Delete</button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  <?php endif; ?>
</div>

<script nonce="<?= dealerfai_csp_nonce() ?>">
  function toggleOptions() {
    const type = document.getElementById('field-type-select').value;
    const optionsBlock = document.getElementById('options-block');
    if (!optionsBlock) return;
    if (type === 'select' || type === 'multiselect') {
      optionsBlock.style.display = 'block';
    } else {
      optionsBlock.style.display = 'none';
    }
  }
  toggleOptions();
</script>
</body>
</html>
