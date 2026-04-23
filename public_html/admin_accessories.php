<?php
include 'auth.php';
require_once __DIR__ . '/includes/theme_head.php';
include 'db.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/helpers/session_utils.php';
require_once __DIR__ . '/helpers/db_utils.php';

$roles = load_session_roles();
if (!in_array('Admin', $roles, true) && !in_array('General Manager', $roles, true)) {
    http_response_code(403);
    echo 'Unauthorized';
    exit;
}
$adminAlertCount = get_admin_alert_count($db);

$message = '';
$error = '';

// Post/Redirect/Get: store success messages in session so refresh doesn't re-submit POST.
if (!empty($_SESSION['flash_success'])) {
    $message = (string)$_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

$orgRows = [];
try {
    $orgRows = $db->query("SELECT id, name, org_kind FROM organizations ORDER BY name ASC")
        ->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $orgRows = [];
}
$orgLookup = [];
$groupOptions = [];
$storeOptions = [];
foreach ($orgRows as $org) {
    $orgId = (int)($org['id'] ?? 0);
    $orgLookup[$orgId] = $org['name'] ?? ('Org #' . $orgId);
    if (($org['org_kind'] ?? 'store') === 'group') {
        $groupOptions[] = $org;
    } else {
        $storeOptions[] = $org;
    }
}

$vehicleMakes = [];
try {
    $vehicleMakes = $db->query("SELECT id, make_name FROM vehicle_makes ORDER BY make_name ASC")
        ->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $vehicleMakes = [];
}
$vehicleMakeNameById = [];
foreach ($vehicleMakes as $make) {
    $vehicleMakeNameById[(int)($make['id'] ?? 0)] = (string)($make['make_name'] ?? '');
}

$vehicleModelNameById = [];
try {
    $modelRows = $db->query("SELECT id, model_name FROM vehicle_models ORDER BY model_name ASC")
        ->fetchAll(PDO::FETCH_ASSOC);
    foreach ($modelRows as $row) {
        $vehicleModelNameById[(int)($row['id'] ?? 0)] = (string)($row['model_name'] ?? '');
    }
} catch (PDOException $e) {
    $vehicleModelNameById = [];
}

$vehicleTrimNameById = [];
try {
    $trimRows = $db->query("SELECT id, trim_name FROM vehicle_trims ORDER BY trim_name ASC")
        ->fetchAll(PDO::FETCH_ASSOC);
    foreach ($trimRows as $row) {
        $vehicleTrimNameById[(int)($row['id'] ?? 0)] = (string)($row['trim_name'] ?? '');
    }
} catch (PDOException $e) {
    $vehicleTrimNameById = [];
}

function accessory_upload_photo(array $file): ?string
{
    if (empty($file['tmp_name'])) {
        return null;
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        return null;
    }
    $size = isset($file['size']) ? (int)$file['size'] : 0;
    if ($size <= 0 || $size > (5 * 1024 * 1024)) {
        return null;
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($file['tmp_name']);
    $allowedMimes = [
        'image/jpeg' => '.jpg',
        'image/png' => '.png',
        'image/webp' => '.webp',
        'image/gif' => '.gif',
    ];
    if (!isset($allowedMimes[$mime])) {
        return null;
    }
    $uploadDir = __DIR__ . '/uploads/accessories/';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0755, true);
    }
    $ext = $allowedMimes[$mime];
    $filename = uniqid('accessory_', true) . $ext;
    $target = $uploadDir . $filename;
    if (!move_uploaded_file($file['tmp_name'], $target)) {
        return null;
    }
    return 'uploads/accessories/' . $filename;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!dealerfai_csrf_validate($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        die('Invalid request.');
    }

    $action = $_POST['action'] ?? '';
    if ($action === 'add_accessory') {
        $code = trim($_POST['code'] ?? '');
        $provider = trim($_POST['provider'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $basePrice = $_POST['base_price'] ?? null;
        $cost = $_POST['cost'] ?? null;
        $residualizable = !empty($_POST['residualizable']) ? 1 : 0;
        $residualAdd = $_POST['residual_msrp_add'] ?? 0;
        // For accessories, luxury tax base should always include accessories when applicable.
        $contributesLuxury = 1;
        $scopeType = $_POST['scope_type'] ?? 'global';
        $scopeValue = $_POST['scope_value'] ?? null;
        $active = !empty($_POST['active']) ? 1 : 0;
        $sortOrder = $_POST['sort_order'] ?? 0;
        // Optional initial fitment (all blank => applies to any vehicle in scope).
        $fitMakeId = (int)($_POST['fit_make_id'] ?? 0);
        $fitModelIdsRaw = $_POST['fit_model_ids'] ?? ($_POST['fit_model_id'] ?? []);
        $fitTrimIdsRaw = $_POST['fit_trim_ids'] ?? ($_POST['fit_trim_id'] ?? []);
        if (!is_array($fitModelIdsRaw)) {
            $fitModelIdsRaw = $fitModelIdsRaw !== '' ? [$fitModelIdsRaw] : [];
        }
        if (!is_array($fitTrimIdsRaw)) {
            $fitTrimIdsRaw = $fitTrimIdsRaw !== '' ? [$fitTrimIdsRaw] : [];
        }
        $fitModelIds = array_values(array_unique(array_filter(array_map(static fn($v) => (int)$v, $fitModelIdsRaw), static fn($v) => $v > 0)));
        $fitTrimIds = array_values(array_unique(array_filter(array_map(static fn($v) => (int)$v, $fitTrimIdsRaw), static fn($v) => $v > 0)));
        $fitMinYearRaw = $_POST['fit_min_year'] ?? '';
        $fitMaxYearRaw = $_POST['fit_max_year'] ?? '';
        $fitMinYear = $fitMinYearRaw !== '' ? (int)$fitMinYearRaw : null;
        $fitMaxYear = $fitMaxYearRaw !== '' ? (int)$fitMaxYearRaw : null;
        if ($fitMinYear !== null && $fitMaxYear !== null && $fitMinYear > $fitMaxYear) {
            $tmp = $fitMinYear;
            $fitMinYear = $fitMaxYear;
            $fitMaxYear = $tmp;
        }

        if ($code === '' || $name === '') {
            $error = 'Code and name are required.';
        } elseif (!in_array($scopeType, ['global', 'org', 'store'], true)) {
            $error = 'Invalid scope type.';
        } elseif ($scopeType !== 'global' && (int)$scopeValue <= 0) {
            $error = 'Scope value is required for org/store.';
        } elseif (!empty($fitTrimIds) && empty($fitModelIds)) {
            $error = 'Initial fitment: trim requires at least one model.';
        } elseif (!empty($fitModelIds) && $fitMakeId <= 0) {
            $error = 'Initial fitment: model requires a make.';
        } else {
            try {
                $db->beginTransaction();

                $scopeValueNormalized = $scopeType === 'global' ? null : (int)$scopeValue;

                $fitRows = [];
                $fitBrand = '';
                if ($fitMakeId > 0) {
                    $makeSql = "SELECT make_name FROM vehicle_makes WHERE id = ?";
                    if (column_exists($db, 'vehicle_makes', 'active')) {
                        $makeSql .= " AND active = 1";
                    }
                    $makeStmt = $db->prepare($makeSql);
                    $makeStmt->execute([$fitMakeId]);
                    $fitBrand = (string)$makeStmt->fetchColumn();
                    if ($fitBrand === '') {
                        throw new RuntimeException('Initial fitment: invalid make selected.');
                    }
                }

                $modelById = [];
                if (!empty($fitModelIds)) {
                    $modelPlaceholders = implode(',', array_fill(0, count($fitModelIds), '?'));
                    $modelSql = "SELECT id, model_name FROM vehicle_models WHERE make_id = ? AND id IN ($modelPlaceholders)";
                    if (column_exists($db, 'vehicle_models', 'active')) {
                        $modelSql .= " AND active = 1";
                    }
                    $modelStmt = $db->prepare($modelSql);
                    $modelStmt->execute(array_merge([$fitMakeId], $fitModelIds));
                    while ($row = $modelStmt->fetch(PDO::FETCH_ASSOC)) {
                        $modelById[(int)$row['id']] = (string)$row['model_name'];
                    }
                    if (count($modelById) !== count($fitModelIds)) {
                        throw new RuntimeException('Initial fitment: one or more selected models are invalid for this make.');
                    }
                }

                $trimModelById = [];
                if (!empty($fitTrimIds)) {
                    $trimPlaceholders = implode(',', array_fill(0, count($fitTrimIds), '?'));
                    $modelPlaceholders = implode(',', array_fill(0, count($fitModelIds), '?'));
                    $trimSql = "SELECT id, model_id FROM vehicle_trims WHERE model_id IN ($modelPlaceholders) AND id IN ($trimPlaceholders)";
                    if (column_exists($db, 'vehicle_trims', 'active')) {
                        $trimSql .= " AND active = 1";
                    }
                    $trimStmt = $db->prepare($trimSql);
                    $trimStmt->execute(array_merge($fitModelIds, $fitTrimIds));
                    while ($row = $trimStmt->fetch(PDO::FETCH_ASSOC)) {
                        $trimModelById[(int)$row['id']] = (int)$row['model_id'];
                    }
                    if (count($trimModelById) !== count($fitTrimIds)) {
                        throw new RuntimeException('Initial fitment: one or more selected trims are invalid for selected model(s).');
                    }
                }

                if ($fitMakeId > 0) {
                    if (empty($fitModelIds)) {
                        $fitRows[] = [null, null, ''];
                    } elseif (!empty($fitTrimIds)) {
                        foreach ($fitTrimIds as $fitTrimId) {
                            $fitTrimId = (int)$fitTrimId;
                            $modelIdForTrim = $trimModelById[$fitTrimId] ?? 0;
                            if ($modelIdForTrim <= 0) {
                                continue;
                            }
                            $fitRows[] = [$modelIdForTrim, $fitTrimId, $modelById[$modelIdForTrim] ?? ''];
                        }
                    } else {
                        foreach ($fitModelIds as $fitModelId) {
                            $fitRows[] = [(int)$fitModelId, null, $modelById[(int)$fitModelId] ?? ''];
                        }
                    }
                } elseif ($fitMinYear !== null || $fitMaxYear !== null) {
                    // Allow year-only fitment across all makes/models.
                    $fitRows[] = [null, null, ''];
                }

                $hasFitmentConstraint = !empty($fitRows);

                // Guardrail: prevent duplicate variants for same code/provider/scope + fitment tuple.
                $existingVariantStmt = $db->prepare("
                    SELECT a.id
                    FROM accessories a
                    LEFT JOIN accessory_fitment af ON af.accessory_id = a.id
                    WHERE a.code = ?
                      AND COALESCE(a.provider, '') = COALESCE(?, '')
                      AND a.scope_type = ?
                      AND ((a.scope_value IS NULL AND ? IS NULL) OR a.scope_value = ?)
                      AND ((af.make_id IS NULL AND ? IS NULL) OR af.make_id = ?)
                      AND ((af.model_id IS NULL AND ? IS NULL) OR af.model_id = ?)
                      AND ((af.trim_id IS NULL AND ? IS NULL) OR af.trim_id = ?)
                    LIMIT 1
                ");
                $duplicateRows = !empty($fitRows) ? $fitRows : [[null, null, '']];
                foreach ($duplicateRows as $duplicateRow) {
                    [$duplicateModelId, $duplicateTrimId] = $duplicateRow;
                    $makeIdForCheck = $fitMakeId > 0 ? $fitMakeId : null;
                    $existingVariantStmt->execute([
                        $code,
                        $provider,
                        $scopeType,
                        $scopeValueNormalized,
                        $scopeValueNormalized,
                        $makeIdForCheck,
                        $makeIdForCheck,
                        $duplicateModelId,
                        $duplicateModelId,
                        $duplicateTrimId,
                        $duplicateTrimId,
                    ]);
                    $existingVariantId = (int)($existingVariantStmt->fetchColumn() ?: 0);
                    if ($existingVariantId > 0) {
                        throw new RuntimeException('An accessory with the same code/provider/scope and fitment already exists. Edit that row instead.');
                    }
                }

                $photoPath = accessory_upload_photo($_FILES['photo'] ?? []);
                $stmt = $db->prepare("INSERT INTO accessories
                    (code, provider, name, description, category, photo_url, base_price, cost, residualizable, residual_msrp_add,
                     contributes_to_luxury_tax, scope_type, scope_value, active, sort_order)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $code,
                    $provider,
                    $name,
                    $description !== '' ? $description : null,
                    $category !== '' ? $category : null,
                    $photoPath,
                    $basePrice !== '' ? $basePrice : null,
                    $cost !== '' ? $cost : null,
                    $residualizable,
                    $residualAdd !== '' ? $residualAdd : 0,
                    $contributesLuxury,
                    $scopeType,
                    $scopeValueNormalized,
                    $active,
                    (int)$sortOrder,
                ]);

                $newAccessoryId = (int)$db->lastInsertId();

                if ($hasFitmentConstraint) {
                    // If make/model aren't selected, leave brand/model blank. The matcher treats this as "any".
                    $fitStmt = $db->prepare("INSERT INTO accessory_fitment
                        (accessory_id, make_id, model_id, trim_id, brand, model, min_year, max_year)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    foreach ($fitRows as $fitRow) {
                        [$fitModelIdValue, $fitTrimIdValue, $fitModelName] = $fitRow;
                        $fitStmt->execute([
                            $newAccessoryId,
                            $fitMakeId > 0 ? $fitMakeId : null,
                            $fitModelIdValue,
                            $fitTrimIdValue,
                            $fitBrand,
                            $fitModelName,
                            $fitMinYear,
                            $fitMaxYear,
                        ]);
                    }
                }

                $db->commit();
                $message = 'Accessory added.';
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                if ($e instanceof RuntimeException) {
                    $error = $e->getMessage();
                } elseif ($e instanceof PDOException && (int)($e->errorInfo[1] ?? 0) === 1062) {
                    $error = 'Duplicate key constraint blocked this insert. Run the accessory uniqueness migration (drop uniq_accessory_scope_provider) and retry.';
                } else {
                    $error = 'Failed to add accessory.';
                }
            }
        }
    } elseif ($action === 'update_accessory') {
        $accessoryId = (int)($_POST['accessory_id'] ?? 0);
        $provider = trim($_POST['provider'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $basePrice = $_POST['base_price'] ?? null;
        $cost = $_POST['cost'] ?? null;
        $residualizable = !empty($_POST['residualizable']) ? 1 : 0;
        $residualAdd = $_POST['residual_msrp_add'] ?? 0;
        // For accessories, luxury tax base should always include accessories when applicable.
        $contributesLuxury = 1;
        $scopeType = $_POST['scope_type'] ?? 'global';
        $scopeValue = $_POST['scope_value'] ?? null;
        $active = !empty($_POST['active']) ? 1 : 0;
        $sortOrder = $_POST['sort_order'] ?? 0;
        $removePhoto = !empty($_POST['remove_photo']);

        if ($accessoryId <= 0 || $name === '') {
            $error = 'Accessory name is required.';
        } elseif (!in_array($scopeType, ['global', 'org', 'store'], true)) {
            $error = 'Invalid scope type.';
        } elseif ($scopeType !== 'global' && (int)$scopeValue <= 0) {
            $error = 'Scope value is required for org/store.';
        } else {
            $photoPath = null;
            if ($removePhoto) {
                $photoPath = null;
            }
            $uploaded = accessory_upload_photo($_FILES['photo'] ?? []);
            if ($uploaded) {
                $photoPath = $uploaded;
            }

            $sql = "UPDATE accessories SET
                        provider = ?,
                        name = ?,
                        description = ?,
                        category = ?,
                        base_price = ?,
                        cost = ?,
                        residualizable = ?,
                        residual_msrp_add = ?,
                        contributes_to_luxury_tax = ?,
                        scope_type = ?,
                        scope_value = ?,
                        active = ?,
                        sort_order = ?";
            $params = [
                $provider,
                $name,
                $description !== '' ? $description : null,
                $category !== '' ? $category : null,
                $basePrice !== '' ? $basePrice : null,
                $cost !== '' ? $cost : null,
                $residualizable,
                $residualAdd !== '' ? $residualAdd : 0,
                $contributesLuxury,
                $scopeType,
                $scopeType === 'global' ? null : (int)$scopeValue,
                $active,
                (int)$sortOrder,
            ];
            if ($photoPath !== null || $removePhoto) {
                $sql .= ", photo_url = ?";
                $params[] = $photoPath;
            }
            $sql .= " WHERE id = ?";
            $params[] = $accessoryId;
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $message = 'Accessory updated.';
        }
    } elseif ($action === 'deactivate_accessory') {
        $accessoryId = (int)($_POST['accessory_id'] ?? 0);
        if ($accessoryId > 0) {
            $stmt = $db->prepare("UPDATE accessories SET active = 0 WHERE id = ?");
            $stmt->execute([$accessoryId]);
            $message = 'Accessory deactivated.';
        }
    } elseif ($action === 'delete_accessory') {
        $accessoryId = (int)($_POST['accessory_id'] ?? 0);
        if ($accessoryId > 0) {
            $check = $db->prepare("SELECT COUNT(*) FROM deal_accessories WHERE accessory_id = ?");
            $check->execute([$accessoryId]);
            $inUse = (int)$check->fetchColumn() > 0;
            if ($inUse) {
                $stmt = $db->prepare("UPDATE accessories SET active = 0 WHERE id = ?");
                $stmt->execute([$accessoryId]);
                $message = 'Accessory is referenced in deals, so it was disabled instead.';
            } else {
                $db->prepare("DELETE FROM accessory_fitment WHERE accessory_id = ?")->execute([$accessoryId]);
                $db->prepare("DELETE FROM accessories WHERE id = ?")->execute([$accessoryId]);
                $message = 'Accessory deleted.';
            }
        }
    } elseif ($action === 'add_fitment') {
        $accessoryId = (int)($_POST['accessory_id'] ?? 0);
        $makeId = (int)($_POST['make_id'] ?? 0);
        $modelIdsRaw = $_POST['model_ids'] ?? ($_POST['model_id'] ?? []);
        $trimIdsRaw = $_POST['trim_ids'] ?? ($_POST['trim_id'] ?? []);
        // Fitment should be based on ids from our vehicle tables.
        // We still store brand/model strings for readability and as a fallback when deal ids are missing.
        $brand = trim($_POST['brand'] ?? '');
        $minYear = $_POST['min_year'] ?? null;
        $maxYear = $_POST['max_year'] ?? null;
        $variant = trim($_POST['model_variant'] ?? '');
        $bodyStyle = trim($_POST['body_style'] ?? '');

        if (!is_array($modelIdsRaw)) {
            $modelIdsRaw = $modelIdsRaw !== '' ? [$modelIdsRaw] : [];
        }
        if (!is_array($trimIdsRaw)) {
            $trimIdsRaw = $trimIdsRaw !== '' ? [$trimIdsRaw] : [];
        }
        $modelIds = array_values(array_unique(array_filter(array_map(static fn($v) => (int)$v, $modelIdsRaw), static fn($v) => $v > 0)));
        $trimIds = array_values(array_unique(array_filter(array_map(static fn($v) => (int)$v, $trimIdsRaw), static fn($v) => $v > 0)));

        $minYear = $minYear !== '' ? (int)$minYear : null;
        $maxYear = $maxYear !== '' ? (int)$maxYear : null;
        if ($minYear !== null && $maxYear !== null && $minYear > $maxYear) {
            $tmp = $minYear;
            $minYear = $maxYear;
            $maxYear = $tmp;
        }

        if ($accessoryId <= 0) {
            $error = 'Accessory is required for fitment.';
        } elseif ($makeId <= 0) {
            $error = 'Make is required for fitment.';
	        } else {
	            // Always resolve brand from make_id (keeps data consistent).
	            $makeSql = "SELECT make_name FROM vehicle_makes WHERE id = ?";
	            if (column_exists($db, 'vehicle_makes', 'active')) {
	                $makeSql .= " AND active = 1";
	            }
	            $makeStmt = $db->prepare($makeSql);
	            $makeStmt->execute([$makeId]);
	            $brand = (string)$makeStmt->fetchColumn();

            if ($brand === '') {
                $error = 'Invalid make selected.';
            }
        }

        if (empty($error)) {
            $modelById = [];
            if (!empty($modelIds)) {
                $modelPlaceholders = implode(',', array_fill(0, count($modelIds), '?'));
                $modelSql = "SELECT id, model_name FROM vehicle_models WHERE make_id = ? AND id IN ($modelPlaceholders)";
                if (column_exists($db, 'vehicle_models', 'active')) {
                    $modelSql .= " AND active = 1";
                }
                $modelStmt = $db->prepare($modelSql);
                $modelStmt->execute(array_merge([$makeId], $modelIds));
                while ($row = $modelStmt->fetch(PDO::FETCH_ASSOC)) {
                    $modelById[(int)$row['id']] = (string)$row['model_name'];
                }
                if (count($modelById) !== count($modelIds)) {
                    $error = 'One or more selected models are invalid for this make.';
                }
            }
            $trimById = [];
            $trimModelById = [];
            if (empty($error) && !empty($trimIds)) {
                if (empty($modelIds)) {
                    $error = 'Trim requires at least one selected model.';
                } else {
                    $trimPlaceholders = implode(',', array_fill(0, count($trimIds), '?'));
                    $modelPlaceholders = implode(',', array_fill(0, count($modelIds), '?'));
                    $trimSql = "SELECT id, model_id FROM vehicle_trims WHERE model_id IN ($modelPlaceholders) AND id IN ($trimPlaceholders)";
                    if (column_exists($db, 'vehicle_trims', 'active')) {
                        $trimSql .= " AND active = 1";
                    }
                    $trimStmt = $db->prepare($trimSql);
                    $trimStmt->execute(array_merge($modelIds, $trimIds));
                    while ($row = $trimStmt->fetch(PDO::FETCH_ASSOC)) {
                        $trimId = (int)$row['id'];
                        $trimById[$trimId] = true;
                        $trimModelById[$trimId] = (int)$row['model_id'];
                    }
                    if (count($trimById) !== count($trimIds)) {
                        $error = 'One or more selected trims are invalid for the selected model(s).';
                    }
                }
            }
        }

        if (empty($error)) {
            $rows = [];
            if (empty($modelIds)) {
                // Make-level fitment (all models/trims under this make).
                $rows[] = [null, null, ''];
            } elseif (!empty($trimIds)) {
                foreach ($trimIds as $trimId) {
                    $trimId = (int)$trimId;
                    $trimModelId = $trimModelById[$trimId] ?? 0;
                    if ($trimModelId <= 0) {
                        continue;
                    }
                    $rows[] = [$trimModelId, $trimId, $modelById[$trimModelId] ?? ''];
                }
            } else {
                // Model-level fitment for each selected model (all trims for each model).
                foreach ($modelIds as $modelId) {
                    $rows[] = [(int)$modelId, null, $modelById[(int)$modelId] ?? ''];
                }
            }

            $added = 0;
            $skipped = 0;
            $existsStmt = $db->prepare("SELECT id FROM accessory_fitment
                WHERE accessory_id = ?
                  AND make_id = ?
                  AND ((model_id IS NULL AND ? IS NULL) OR model_id = ?)
                  AND ((trim_id IS NULL AND ? IS NULL) OR trim_id = ?)
                  AND ((min_year IS NULL AND ? IS NULL) OR min_year = ?)
                  AND ((max_year IS NULL AND ? IS NULL) OR max_year = ?)
                  AND ((model_variant IS NULL AND ? IS NULL) OR model_variant = ?)
                  AND ((body_style IS NULL AND ? IS NULL) OR body_style = ?)
                LIMIT 1");
            $stmt = $db->prepare("INSERT INTO accessory_fitment
                (accessory_id, make_id, model_id, trim_id, brand, model, min_year, max_year, model_variant, body_style)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

            $variantValue = $variant !== '' ? $variant : null;
            $bodyStyleValue = $bodyStyle !== '' ? $bodyStyle : null;
            foreach ($rows as $row) {
                [$modelIdValue, $trimIdValue, $modelName] = $row;
                $existsStmt->execute([
                    $accessoryId,
                    $makeId,
                    $modelIdValue,
                    $modelIdValue,
                    $trimIdValue,
                    $trimIdValue,
                    $minYear,
                    $minYear,
                    $maxYear,
                    $maxYear,
                    $variantValue,
                    $variantValue,
                    $bodyStyleValue,
                    $bodyStyleValue,
                ]);
                if ($existsStmt->fetchColumn()) {
                    $skipped++;
                    continue;
                }

                $stmt->execute([
                    $accessoryId,
                    $makeId,
                    $modelIdValue,
                    $trimIdValue,
                    $brand,
                    $modelName,
                    $minYear,
                    $maxYear,
                    $variantValue,
                    $bodyStyleValue,
                ]);
                $added++;
            }

            if ($added > 0 && $skipped > 0) {
                $message = "Fitment added ({$added} new, {$skipped} duplicates skipped).";
            } elseif ($added > 0) {
                $message = $added === 1 ? 'Fitment added.' : "Fitments added ({$added}).";
            } else {
                $error = 'No new fitment rows were added (all selected rows already exist).';
            }
        }
    } elseif ($action === 'update_fitment') {
        $fitmentId = (int)($_POST['fitment_id'] ?? 0);
        $makeId = isset($_POST['make_id']) && $_POST['make_id'] !== '' ? (int)$_POST['make_id'] : 0;
        $modelId = isset($_POST['model_id']) && $_POST['model_id'] !== '' ? (int)$_POST['model_id'] : 0;
        $trimId = isset($_POST['trim_id']) && $_POST['trim_id'] !== '' ? (int)$_POST['trim_id'] : 0;
        $minYear = $_POST['min_year'] ?? null;
        $maxYear = $_POST['max_year'] ?? null;
        $variant = trim($_POST['model_variant'] ?? '');
        $bodyStyle = trim($_POST['body_style'] ?? '');

        $minYear = $minYear !== '' ? (int)$minYear : null;
        $maxYear = $maxYear !== '' ? (int)$maxYear : null;
        if ($minYear !== null && $maxYear !== null && $minYear > $maxYear) {
            $tmp = $minYear;
            $minYear = $maxYear;
            $maxYear = $tmp;
        }

        if ($fitmentId <= 0) {
            $error = 'Fitment is required.';
        } else {
            $fitRow = null;
            try {
                $stmt = $db->prepare("SELECT * FROM accessory_fitment WHERE id = ?");
                $stmt->execute([$fitmentId]);
                $fitRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            } catch (PDOException $e) {
                $fitRow = null;
            }
            if (!$fitRow) {
                $error = 'Fitment not found.';
            }
        }

        if (empty($error)) {
            // Dependency rules: model requires a make; trim requires a model.
            if ($trimId > 0 && $modelId <= 0) {
                $error = 'Trim requires a model.';
            } elseif ($modelId > 0 && $makeId <= 0) {
                $error = 'Model requires a make.';
            }
        }

        $brand = '';
        $model = '';
        if (empty($error)) {
            // Allow "All makes" (make_id null) for broad fitment.
            if ($makeId > 0) {
                $makeSql = "SELECT make_name FROM vehicle_makes WHERE id = ?";
                if (function_exists('column_exists') && column_exists($db, 'vehicle_makes', 'active')) {
                    $makeSql .= " AND active = 1";
                }
                $makeStmt = $db->prepare($makeSql);
                $makeStmt->execute([$makeId]);
                $brand = (string)$makeStmt->fetchColumn();
                if ($brand === '') {
                    $error = 'Invalid make selected.';
                }
            } else {
                // Clearing make should also clear model/trim.
                $modelId = 0;
                $trimId = 0;
            }
        }

        if (empty($error) && $makeId > 0) {
            if ($modelId > 0) {
                $modelSql = "SELECT model_name FROM vehicle_models WHERE id = ? AND make_id = ?";
                if (function_exists('column_exists') && column_exists($db, 'vehicle_models', 'active')) {
                    $modelSql .= " AND active = 1";
                }
                $modelStmt = $db->prepare($modelSql);
                $modelStmt->execute([$modelId, $makeId]);
                $model = (string)$modelStmt->fetchColumn();
                if ($model === '') {
                    $error = 'Invalid model selected for this make.';
                }
            } else {
                $model = '';
                $modelId = 0;
                $trimId = 0;
            }
        }

        if (empty($error) && $trimId > 0) {
            $trimSql = "SELECT id FROM vehicle_trims WHERE id = ? AND model_id = ?";
            if (function_exists('column_exists') && column_exists($db, 'vehicle_trims', 'active')) {
                $trimSql .= " AND active = 1";
            }
            $trimStmt = $db->prepare($trimSql);
            $trimStmt->execute([$trimId, $modelId]);
            $ok = (int)$trimStmt->fetchColumn();
            if ($ok <= 0) {
                $error = 'Invalid trim selected for this model.';
            }
        }

        if (empty($error)) {
            $stmt = $db->prepare("UPDATE accessory_fitment
                SET make_id = ?,
                    model_id = ?,
                    trim_id = ?,
                    brand = ?,
                    model = ?,
                    min_year = ?,
                    max_year = ?,
                    model_variant = ?,
                    body_style = ?
                WHERE id = ?");
            $stmt->execute([
                $makeId > 0 ? $makeId : null,
                $modelId > 0 ? $modelId : null,
                $trimId > 0 ? $trimId : null,
                $brand,
                $model,
                $minYear,
                $maxYear,
                $variant !== '' ? $variant : null,
                $bodyStyle !== '' ? $bodyStyle : null,
                $fitmentId,
            ]);
            $message = 'Fitment updated.';
        }
    } elseif ($action === 'delete_fitment') {
        $fitmentId = (int)($_POST['fitment_id'] ?? 0);
        if ($fitmentId > 0) {
            $stmt = $db->prepare("DELETE FROM accessory_fitment WHERE id = ?");
            $stmt->execute([$fitmentId]);
            $message = 'Fitment removed.';
        }
    }
}

// If we successfully processed a POST, redirect to avoid accidental duplicate actions on refresh.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $message !== '' && $error === '') {
    $_SESSION['flash_success'] = $message;
    $path = strtok($_SERVER['REQUEST_URI'], '?');
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    header('Location: ' . $path . ($qs !== '' ? '?' . $qs : ''));
    exit;
}

$csrfToken = dealerfai_csrf_get_token();

$categoryValues = [];
try {
    $categoryRows = $db->query("SELECT DISTINCT category FROM accessories WHERE category IS NOT NULL AND TRIM(category) <> '' ORDER BY category ASC")
        ->fetchAll(PDO::FETCH_COLUMN);
    foreach ($categoryRows as $value) {
        $categoryValues[] = $value;
    }
} catch (PDOException $e) {
    $categoryValues = [];
}

$codeValues = [];
try {
    $codeRows = $db->query("SELECT DISTINCT code FROM accessories WHERE code IS NOT NULL AND TRIM(code) <> '' ORDER BY code ASC")
        ->fetchAll(PDO::FETCH_COLUMN);
    foreach ($codeRows as $value) {
        $codeValues[] = $value;
    }
} catch (PDOException $e) {
    $codeValues = [];
}

$providerValues = [];
try {
    $providerRows = $db->query("SELECT DISTINCT provider FROM accessories WHERE provider IS NOT NULL AND TRIM(provider) <> '' ORDER BY provider ASC")
        ->fetchAll(PDO::FETCH_COLUMN);
    foreach ($providerRows as $value) {
        $providerValues[] = $value;
    }
} catch (PDOException $e) {
    $providerValues = [];
}

// Default suggestions for fast entry. Users can always type a new value; it will become selectable later
// once saved in the DB.
$defaultAccessoryCodes = [
    'WINTER_WHEEL_PKG',
    'DASH_CAM',
    'CLICK_N_GO_HOOK_PKG',
    'ROOF_CROSS_BARS',
    'SPLASH_GUARDS',
    'MUD_GUARDS',
    'LOADSPACE_RETENTION_NET',
    'MUD_FLAPS_CLASSIC',
    'SIDE_STEPS_DEPLOYABLE',
    'SIDE_STEPS_FIXED',
    'ROOF_ACCESS_LADDER',
    'SIDE_CARRIER_CASE',
];

$defaultAccessoryCategories = [
    'Wheels & Tires',
    'Safety & Security',
    'Interior',
    'Exterior',
    'Roof & Cargo',
    'Utility',
];

$codeSuggestions = array_values(array_unique(array_filter(array_merge($defaultAccessoryCodes, $codeValues), static function ($v) {
    return trim((string)$v) !== '';
})));
natcasesort($codeSuggestions);

$categorySuggestions = array_values(array_unique(array_filter(array_merge($defaultAccessoryCategories, $categoryValues), static function ($v) {
    return trim((string)$v) !== '';
})));
natcasesort($categorySuggestions);

	$filters = [];
	$params = [];
	$search = trim($_GET['search'] ?? '');
	$scopeFilter = $_GET['scope'] ?? '';
	$activeFilter = $_GET['active'] ?? '';
	$categoryFilter = $_GET['category'] ?? '';
	$makeFilterId = isset($_GET['make_id']) ? (int)$_GET['make_id'] : 0;
	$modelFilterId = isset($_GET['model_id']) ? (int)$_GET['model_id'] : 0;
	$trimFilterId = isset($_GET['trim_id']) ? (int)$_GET['trim_id'] : 0;

	$query = "SELECT DISTINCT a.* FROM accessories a";
	if ($makeFilterId > 0 || $modelFilterId > 0 || $trimFilterId > 0) {
	    $query .= " LEFT JOIN accessory_fitment af ON af.accessory_id = a.id";
	}
	if ($search !== '') {
	    $filters[] = "(a.name LIKE ? OR a.code LIKE ? OR a.provider LIKE ?)";
	    $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
}
if (in_array($scopeFilter, ['global', 'org', 'store'], true)) {
    $filters[] = "a.scope_type = ?";
    $params[] = $scopeFilter;
}
if ($activeFilter === '1' || $activeFilter === '0') {
    $filters[] = "a.active = ?";
    $params[] = (int)$activeFilter;
}
	if ($categoryFilter !== '') {
	    $filters[] = "a.category = ?";
	    $params[] = $categoryFilter;
	}
	if ($makeFilterId > 0) {
	    $filters[] = "af.make_id = ?";
	    $params[] = $makeFilterId;
	}
	if ($modelFilterId > 0) {
	    // Include make-only fitment rows (model_id IS NULL) when filtering by a specific model.
	    // Model filter implies a make is selected in the UI.
	    $filters[] = "(af.model_id = ? OR af.model_id IS NULL)";
	    $params[] = $modelFilterId;
	}
	if ($trimFilterId > 0) {
	    // Include model-level or make-level fitment rows when filtering by a specific trim.
	    $filters[] = "(af.trim_id = ? OR af.trim_id IS NULL)";
	    $params[] = $trimFilterId;
	}
	if (!empty($filters)) {
	    $query .= " WHERE " . implode(' AND ', $filters);
	}
		// Admins shouldn't need to manage a manual sort order; keep ordering stable and predictable.
		$query .= " ORDER BY a.name ASC, a.provider ASC, a.code ASC";

$accessories = [];
try {
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $accessories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $accessories = [];
}

$fitmentMap = [];
if (!empty($accessories)) {
    $ids = array_column($accessories, 'id');

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $fitStmt = $db->prepare("SELECT * FROM accessory_fitment WHERE accessory_id IN ($placeholders) ORDER BY brand ASC, model ASC, min_year ASC");
    $fitStmt->execute($ids);
    while ($row = $fitStmt->fetch(PDO::FETCH_ASSOC)) {
        $fitmentMap[$row['accessory_id']][] = $row;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
  <?php include __DIR__ . "/includes/head_favicon.php"; ?>
<meta charset="UTF-8">
  <title>Admin - Accessories</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php dealerfai_theme_head(); ?>
  <style nonce="<?= dealerfai_csp_nonce() ?>">
    body { font-family: Arial, sans-serif; background: #f4f6f8; margin: 0; padding: 0; }
    header { background: #0a2e36; color: white; padding: 20px; text-align: center; position: relative; }
    nav { background: #0a2e36; padding: 12px; text-align: center; }
    nav a { color: white; margin: 0 20px; text-decoration: none; font-weight: bold; }
    .container { max-width: 1200px; margin: 20px auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
    h2 { margin-top: 0; }
    table { width: 100%; border-collapse: collapse; margin-top: 16px; }
    th, td { padding: 10px; border-bottom: 1px solid #ddd; vertical-align: top; text-align: left; }
    th { background: #f1f4f8; }
    input[type="text"], select, input[type="number"], textarea { padding: 6px; border-radius: 4px; border: 1px solid #ccc; width: 100%; }
    textarea { min-height: 70px; }
    .btn { background: #0a6280; color: white; padding: 8px 14px; border: none; border-radius: 4px; cursor: pointer; }
	    .btn:hover { background: #094c63; }
	    .muted { color: #667085; font-size: 0.9rem; margin-top: 4px; }
	    .quick-picks { margin-top: 6px; display: flex; flex-wrap: wrap; gap: 6px; }
	    .quick-pick-btn {
	      border: 1px solid #c7d0d8;
	      background: #f8fafc;
	      color: #1b2c40;
	      border-radius: 999px;
	      padding: 2px 10px;
	      font-size: 12px;
	      line-height: 1.4;
	      cursor: pointer;
	    }
	    .quick-pick-btn:hover { background: #eaf1f7; }
	    .quick-pick-btn.is-active { border-color: #0a6280; background: #e1eff5; font-weight: 600; }
	    .image-preview { display: block; margin-top: 8px; max-width: 220px; width: 100%; height: auto; border-radius: 10px; border: 1px solid #e2e8f0; background: #f1f5f9; }
	    .image-preview.hidden { display: none; }
	    .success { color: green; margin-top: 10px; }
	    .error { color: #c0392b; margin-top: 10px; }
	    details summary { cursor: pointer; font-weight: bold; }
	    .form-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; }
	    .form-grid .full { grid-column: 1 / -1; }
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
    <h2>Accessories</h2>
    <p class="muted">Manage accessory catalog and fitment. Accessory scoring now uses question-based action rules.</p>
    <p class="muted"><a href="admin_scoring_action_rules.php?prefill_target_type=accessory">Accessory Action Rules</a> manages question-based accessory scoring and exclusions.</p>

    <?php if ($message): ?>
      <p class="success">✅ <?= htmlspecialchars($message) ?></p>
    <?php endif; ?>
    <?php if ($error): ?>
      <p class="error">⚠️ <?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <datalist id="accessory-code-suggestions">
      <?php foreach ($codeSuggestions as $codeOpt): ?>
        <option value="<?= htmlspecialchars((string)$codeOpt) ?>"></option>
      <?php endforeach; ?>
    </datalist>
    <datalist id="accessory-provider-suggestions">
      <?php foreach ($providerValues as $provOpt): ?>
        <option value="<?= htmlspecialchars((string)$provOpt) ?>"></option>
      <?php endforeach; ?>
    </datalist>
    <datalist id="accessory-category-suggestions">
      <?php foreach ($categorySuggestions as $catOpt): ?>
        <option value="<?= htmlspecialchars((string)$catOpt) ?>"></option>
      <?php endforeach; ?>
    </datalist>

	    <h3 class="mb-6">Search & Filters</h3>
	    <form method="get" class="mt-12">
	      <div class="form-grid">
	        <div>
	          <label>Search</label>
	          <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Name or code">
	        </div>
	        <div>
	          <label>Scope</label>
	          <select name="scope">
	            <option value="">All</option>
	            <option value="global" <?= $scopeFilter === 'global' ? 'selected' : '' ?>>Global</option>
	            <option value="org" <?= $scopeFilter === 'org' ? 'selected' : '' ?>>Org</option>
	            <option value="store" <?= $scopeFilter === 'store' ? 'selected' : '' ?>>Store</option>
	          </select>
	        </div>
	        <div>
	          <label>Active</label>
	          <select name="active">
	            <option value="">All</option>
	            <option value="1" <?= $activeFilter === '1' ? 'selected' : '' ?>>Active</option>
	            <option value="0" <?= $activeFilter === '0' ? 'selected' : '' ?>>Inactive</option>
	          </select>
	        </div>
	        <div>
	          <label>Category</label>
	          <select name="category">
	            <option value="">All</option>
	            <?php foreach ($categoryValues as $cat): ?>
	              <option value="<?= htmlspecialchars($cat) ?>" <?= $categoryFilter === $cat ? 'selected' : '' ?>><?= htmlspecialchars($cat) ?></option>
	            <?php endforeach; ?>
	          </select>
	        </div>
	        <div>
	          <label>Make</label>
	          <select name="make_id" class="filter-make">
	            <option value="">All</option>
	            <?php foreach ($vehicleMakes as $make): ?>
	              <option value="<?= (int)$make['id'] ?>" <?= $makeFilterId === (int)$make['id'] ? 'selected' : '' ?>><?= htmlspecialchars($make['make_name']) ?></option>
	            <?php endforeach; ?>
	          </select>
	        </div>
	        <div>
	          <label>Model</label>
	          <select name="model_id" class="filter-model" disabled>
	            <option value="">All</option>
	          </select>
	          <input type="hidden" class="filter-model-selected" value="<?= (int)$modelFilterId ?>">
	        </div>
	        <div>
	          <label>Trim</label>
	          <select name="trim_id" class="filter-trim" disabled>
	            <option value="">All</option>
	          </select>
	          <input type="hidden" class="filter-trim-selected" value="<?= (int)$trimFilterId ?>">
	        </div>
	      </div>
	      <button type="submit" class="btn" class="mt-12">Apply</button>
	    </form>

    <h3 class="mb-6-mt-20">Add Accessory</h3>
	    <form method="post" enctype="multipart/form-data" class="mt-12">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
	      <input type="hidden" name="action" value="add_accessory">
	      <div class="form-grid">
	        <div>
	          <label>Code</label>
	          <input type="text" name="code" list="accessory-code-suggestions" class="js-quick-pick-input" data-quick-list="accessory-code-suggestions" required placeholder="e.g., DASH_CAM">
	          <div class="quick-picks js-quick-picks"></div>
	        </div>
	        <div>
	          <label>Provider (optional)</label>
	          <input type="text" name="provider" list="accessory-provider-suggestions" class="js-quick-pick-input" data-quick-list="accessory-provider-suggestions" placeholder="e.g., JLR (blank = generic)">
	          <div class="quick-picks js-quick-picks"></div>
	        </div>
	        <div>
	          <label>Name</label>
	          <input type="text" name="name" required>
	        </div>
	        <div>
	          <label>Category</label>
	          <input type="text" name="category" list="accessory-category-suggestions" class="js-quick-pick-input" data-quick-list="accessory-category-suggestions" placeholder="e.g., Safety & Security">
	          <div class="quick-picks js-quick-picks"></div>
	        </div>
	        <div>
	          <label>Fitment Make (optional)</label>
	          <select name="fit_make_id" class="new-fitment-make">
	            <option value="">All makes</option>
	            <?php foreach ($vehicleMakes as $make): ?>
	              <option value="<?= (int)$make['id'] ?>"><?= htmlspecialchars($make['make_name']) ?></option>
	            <?php endforeach; ?>
	          </select>
	          <div class="muted">Leave blank to allow any make.</div>
	        </div>
	        <div>
	          <label>Fitment Model (optional)</label>
	          <select name="fit_model_ids[]" class="new-fitment-model-multi" multiple size="6" disabled>
	            <option value="">All models</option>
	          </select>
	          <div class="muted">Click to toggle one or more models. Leave empty for all models under the selected make.</div>
	        </div>
	        <div>
	          <label>Fitment Trim (optional)</label>
	          <select name="fit_trim_ids[]" class="new-fitment-trim-multi" multiple size="8" disabled>
	            <option value="">All trims</option>
	          </select>
	          <div class="muted">Click to toggle one or more trims. Leave empty for all trims under selected model(s).</div>
	        </div>
	        <div>
	          <label>Fitment Min Year (optional)</label>
	          <input type="number" name="fit_min_year" placeholder="e.g., 2021">
	        </div>
	        <div>
	          <label>Fitment Max Year (optional)</label>
	          <input type="number" name="fit_max_year" placeholder="e.g., 2026">
	        </div>
        <div>
          <label>Initial Fitment Selection</label>
          <div class="muted">
            <span class="new-fitment-model-count">0</span> model(s), <span class="new-fitment-trim-count">0</span> trim(s)
          </div>
        </div>
	        <div class="full">
	          <label>Description</label>
	          <textarea name="description"></textarea>
	        </div>
		        <div>
		          <label>Photo</label>
		          <input type="file" name="photo" accept="image/*">
		          <img class="image-preview hidden js-photo-preview" alt="Selected photo preview">
		        </div>
        <div>
          <label>Base Price</label>
          <input type="number" step="0.01" name="base_price">
        </div>
        <div>
          <label>Cost</label>
          <input type="number" step="0.01" name="cost">
        </div>
        <div>
          <label>Residualizable</label>
          <input type="checkbox" name="residualizable" value="1">
        </div>
	        <div>
	          <label>Residual MSRP Add</label>
	          <input type="number" step="0.01" name="residual_msrp_add">
	        </div>
	        <div>
	          <label>Scope</label>
	          <select name="scope_type">
	            <option value="global">Global</option>
            <option value="org">Org</option>
            <option value="store">Store</option>
          </select>
        </div>
        <div>
          <label>Scope Value</label>
          <select name="scope_value">
            <option value="">Select org/store</option>
            <optgroup label="Organizations">
              <?php foreach ($groupOptions as $org): ?>
                <option value="<?= (int)$org['id'] ?>"><?= htmlspecialchars($org['name']) ?></option>
              <?php endforeach; ?>
            </optgroup>
            <optgroup label="Stores">
              <?php foreach ($storeOptions as $org): ?>
                <option value="<?= (int)$org['id'] ?>"><?= htmlspecialchars($org['name']) ?></option>
              <?php endforeach; ?>
            </optgroup>
          </select>
        </div>
	        <div>
	          <label>Active</label>
	          <input type="checkbox" name="active" value="1" checked>
	        </div>
	      </div>
	      <button type="submit" class="btn" class="mt-12">Add</button>
	    </form>

    <h3 class="mb-6-mt-20">Accessory List</h3>
    <table>
      <thead>
        <tr>
          <th class="w-16">Code</th>
          <th class="w-18">Name</th>
          <th class="w-12">Scope</th>
          <th class="w-12">Price</th>
          <th style="width:42%;">Details</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($accessories as $accessory): ?>
          <?php
            $scopeLabel = ucfirst($accessory['scope_type'] ?? 'global');
            if (!empty($accessory['scope_value'])) {
                $scopeLabel .= ' - ' . ($orgLookup[(int)$accessory['scope_value']] ?? ('ID ' . (int)$accessory['scope_value']));
            }
            $fitments = $fitmentMap[$accessory['id']] ?? [];
          ?>
          <tr>
            <td>
              <?= htmlspecialchars($accessory['code']) ?>
              <?php if (!empty($accessory['provider'])): ?>
                <div class="muted"><?= htmlspecialchars($accessory['provider']) ?></div>
              <?php endif; ?>
            </td>
            <td><?= htmlspecialchars($accessory['name']) ?></td>
            <td><?= htmlspecialchars($scopeLabel) ?></td>
            <td>$<?= number_format((float)($accessory['base_price'] ?? 0), 2) ?></td>
            <td>
              <details>
                <summary>Manage</summary>
	                <div class="muted" class="mt-8">
	                  <?= $accessory['active'] ? 'Active' : 'Inactive' ?>
	                  <?php if (!empty($accessory['provider'])): ?> • Provider <?= htmlspecialchars($accessory['provider']) ?><?php endif; ?>
	                </div>
	                <form method="post" enctype="multipart/form-data" class="mt-12">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
	                  <input type="hidden" name="action" value="update_accessory">
	                  <input type="hidden" name="accessory_id" value="<?= (int)$accessory['id'] ?>">
                  <div class="form-grid">
                    <div>
                      <label>Provider (optional)</label>
                      <input type="text" name="provider" list="accessory-provider-suggestions" class="js-quick-pick-input" data-quick-list="accessory-provider-suggestions" value="<?= htmlspecialchars($accessory['provider'] ?? '') ?>">
                      <div class="quick-picks js-quick-picks"></div>
                    </div>
                    <div>
                      <label>Name</label>
                      <input type="text" name="name" value="<?= htmlspecialchars($accessory['name']) ?>" required>
                    </div>
                    <div>
                      <label>Category</label>
                      <input type="text" name="category" list="accessory-category-suggestions" class="js-quick-pick-input" data-quick-list="accessory-category-suggestions" value="<?= htmlspecialchars($accessory['category'] ?? '') ?>">
                      <div class="quick-picks js-quick-picks"></div>
                    </div>
                    <div>
                      <label>Base Price</label>
                      <input type="number" step="0.01" name="base_price" value="<?= htmlspecialchars((string)($accessory['base_price'] ?? '')) ?>">
                    </div>
                    <div>
                      <label>Cost</label>
                      <input type="number" step="0.01" name="cost" value="<?= htmlspecialchars((string)($accessory['cost'] ?? '')) ?>">
                    </div>
                    <div>
                      <label>Residualizable</label>
                      <input type="checkbox" name="residualizable" value="1" <?= !empty($accessory['residualizable']) ? 'checked' : '' ?>>
                    </div>
	                    <div>
	                      <label>Residual MSRP Add</label>
	                      <input type="number" step="0.01" name="residual_msrp_add" value="<?= htmlspecialchars((string)($accessory['residual_msrp_add'] ?? '')) ?>">
	                    </div>
	                    <div>
	                      <label>Scope</label>
	                      <select name="scope_type">
	                        <option value="global" <?= ($accessory['scope_type'] ?? '') === 'global' ? 'selected' : '' ?>>Global</option>
                        <option value="org" <?= ($accessory['scope_type'] ?? '') === 'org' ? 'selected' : '' ?>>Org</option>
                        <option value="store" <?= ($accessory['scope_type'] ?? '') === 'store' ? 'selected' : '' ?>>Store</option>
                      </select>
                    </div>
                    <div>
                      <label>Scope Value</label>
                      <select name="scope_value">
                        <option value="">Select org/store</option>
                        <optgroup label="Organizations">
                          <?php foreach ($groupOptions as $org): ?>
                            <option value="<?= (int)$org['id'] ?>" <?= (int)($accessory['scope_value'] ?? 0) === (int)$org['id'] ? 'selected' : '' ?>><?= htmlspecialchars($org['name']) ?></option>
                          <?php endforeach; ?>
                        </optgroup>
                        <optgroup label="Stores">
                          <?php foreach ($storeOptions as $org): ?>
                            <option value="<?= (int)$org['id'] ?>" <?= (int)($accessory['scope_value'] ?? 0) === (int)$org['id'] ? 'selected' : '' ?>><?= htmlspecialchars($org['name']) ?></option>
                          <?php endforeach; ?>
                        </optgroup>
                      </select>
                    </div>
	                    <div>
	                      <label>Active</label>
	                      <input type="checkbox" name="active" value="1" <?= !empty($accessory['active']) ? 'checked' : '' ?>>
	                    </div>
	                    <div class="full">
	                      <label>Description</label>
	                      <textarea name="description"><?= htmlspecialchars($accessory['description'] ?? '') ?></textarea>
	                    </div>
	                    <div>
	                      <label>Photo</label>
	                      <input type="file" name="photo" accept="image/*">
	                      <?php if (!empty($accessory['photo_url'])): ?>
	                        <img src="<?= htmlspecialchars($accessory['photo_url']) ?>" class="image-preview" alt="Current photo">
	                        <div class="muted">Current: <?= htmlspecialchars($accessory['photo_url']) ?></div>
	                        <label style="display:flex; align-items:center; gap:6px; margin-top:6px;">
	                          <input type="checkbox" name="remove_photo" value="1"> Remove photo
	                        </label>
	                      <?php endif; ?>
	                      <img class="image-preview hidden js-photo-preview" alt="Selected photo preview">
	                    </div>
                  </div>
                  <button type="submit" class="btn" class="mt-12">Save</button>
                </form>

                <form method="post" style="margin-top:8px; display:flex; gap:8px;">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                  <input type="hidden" name="accessory_id" value="<?= (int)$accessory['id'] ?>">
                  <button type="submit" name="action" value="deactivate_accessory" class="btn">Deactivate</button>
                  <button type="submit" name="action" value="delete_accessory" class="btn" onclick="return confirm('Delete this accessory?')">Delete</button>
                </form>

                <div class="mt-16">
	                  <strong>Fitment</strong>
			                  <?php if (!empty($fitments)): ?>
			                    <?php foreach ($fitments as $fit): ?>
			                      <?php
			                        $fitMakeId = (int)($fit['make_id'] ?? 0);
			                        $fitModelId = (int)($fit['model_id'] ?? 0);
			                        $fitTrimId = (int)($fit['trim_id'] ?? 0);
			                        $fitMakeLabel = $fitMakeId > 0 ? trim((string)($vehicleMakeNameById[$fitMakeId] ?? '')) : '';
			                        if ($fitMakeLabel === '') {
			                            $fitMakeLabel = trim((string)($fit['brand'] ?? ''));
			                        }
			                        if ($fitMakeLabel === '') {
			                            $fitMakeLabel = 'All makes';
			                        }
			                        $fitModelLabel = $fitModelId > 0 ? trim((string)($vehicleModelNameById[$fitModelId] ?? '')) : '';
			                        if ($fitModelLabel === '') {
			                            $fitModelLabel = trim((string)($fit['model'] ?? ''));
			                        }
			                        if ($fitModelLabel === '') {
			                            $fitModelLabel = 'All models';
			                        }
			                        $fitTrimLabel = $fitTrimId > 0 ? trim((string)($vehicleTrimNameById[$fitTrimId] ?? '')) : '';
			                        if ($fitTrimLabel === '' && $fitTrimId > 0) {
			                            $fitTrimLabel = 'Trim #' . $fitTrimId;
			                        } elseif ($fitTrimLabel === '') {
			                            $fitTrimLabel = 'All trims';
			                        }
			                        $fitMinYearValue = isset($fit['min_year']) && $fit['min_year'] !== null ? (int)$fit['min_year'] : null;
			                        $fitMaxYearValue = isset($fit['max_year']) && $fit['max_year'] !== null ? (int)$fit['max_year'] : null;
			                        if ($fitMinYearValue !== null || $fitMaxYearValue !== null) {
			                            $yearFrom = $fitMinYearValue !== null ? (string)$fitMinYearValue : 'Any';
			                            $yearTo = $fitMaxYearValue !== null ? (string)$fitMaxYearValue : 'Any';
			                            $fitYearLabel = $yearFrom . ' - ' . $yearTo;
			                        } else {
			                            $fitYearLabel = 'All years';
			                        }
			                      ?>
			                      <div class="muted" class="mt-6">
			                        <?= htmlspecialchars($fitMakeLabel) ?> / <?= htmlspecialchars($fitModelLabel) ?> / <?= htmlspecialchars($fitTrimLabel) ?> / <?= htmlspecialchars($fitYearLabel) ?>
			                        <?php if (!empty($fit['model_variant'])): ?> • <?= htmlspecialchars($fit['model_variant']) ?><?php endif; ?>
			                        <?php if (!empty($fit['body_style'])): ?> • <?= htmlspecialchars($fit['body_style']) ?><?php endif; ?>
			                        <form method="post" class="d-inline">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
		                          <input type="hidden" name="action" value="delete_fitment">
	                          <input type="hidden" name="fitment_id" value="<?= (int)$fit['id'] ?>">
	                          <button type="submit" class="btn" style="padding:2px 8px; font-size:12px;">Remove</button>
	                        </form>
	                        <details class="fitment-edit-details" class="mt-6">
	                          <summary>Edit</summary>
	                          <form method="post" class="mt-8">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
	                            <input type="hidden" name="action" value="update_fitment">
	                            <input type="hidden" name="fitment_id" value="<?= (int)$fit['id'] ?>">
	                            <div class="form-grid">
	                              <div>
	                                <label>Make</label>
	                                <select name="make_id" class="fitment-make">
	                                  <option value="">All makes</option>
	                                  <?php foreach ($vehicleMakes as $make): ?>
	                                    <option value="<?= (int)$make['id'] ?>" <?= (int)($fit['make_id'] ?? 0) === (int)$make['id'] ? 'selected' : '' ?>>
	                                      <?= htmlspecialchars($make['make_name']) ?>
	                                    </option>
	                                  <?php endforeach; ?>
	                                </select>
	                              </div>
	                              <div>
	                                <label>Model (optional)</label>
	                                <select name="model_id" class="fitment-model" disabled data-selected="<?= (int)($fit['model_id'] ?? 0) ?>">
	                                  <option value="">All models</option>
	                                </select>
	                              </div>
	                              <div>
	                                <label>Trim (optional)</label>
	                                <select name="trim_id" class="fitment-trim" disabled data-selected="<?= (int)($fit['trim_id'] ?? 0) ?>">
	                                  <option value="">All trims</option>
	                                </select>
	                              </div>
	                              <div>
	                                <label>Min Year</label>
	                                <input type="number" name="min_year" value="<?= htmlspecialchars((string)($fit['min_year'] ?? '')) ?>">
	                              </div>
	                              <div>
	                                <label>Max Year</label>
	                                <input type="number" name="max_year" value="<?= htmlspecialchars((string)($fit['max_year'] ?? '')) ?>">
	                              </div>
	                              <div>
	                                <label>Model Variant</label>
	                                <input type="text" name="model_variant" value="<?= htmlspecialchars((string)($fit['model_variant'] ?? '')) ?>">
	                              </div>
	                              <div>
	                                <label>Body Style</label>
	                                <input type="text" name="body_style" value="<?= htmlspecialchars((string)($fit['body_style'] ?? '')) ?>">
	                              </div>
	                            </div>
	                            <button type="submit" class="btn" class="mt-8">Save Fitment</button>
	                          </form>
	                        </details>
	                      </div>
	                    <?php endforeach; ?>
	                  <?php else: ?>
	                    <div class="muted">No fitment rules yet.</div>
	                  <?php endif; ?>

                  <form method="post" class="mt-8">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="add_fitment">
                    <input type="hidden" name="accessory_id" value="<?= (int)$accessory['id'] ?>">
                    <div class="form-grid">
	                      <div>
	                        <label>Make</label>
	                        <select name="make_id" class="fitment-make-multi" required>
	                          <option value="">Select make</option>
	                          <?php foreach ($vehicleMakes as $make): ?>
	                            <option value="<?= (int)$make['id'] ?>"><?= htmlspecialchars($make['make_name']) ?></option>
	                          <?php endforeach; ?>
	                        </select>
	                      </div>
	                      <div>
	                        <label>Model (optional)</label>
	                        <select name="model_ids[]" class="fitment-model-multi" multiple size="6" disabled>
	                          <option value="">All models</option>
	                        </select>
	                        <div class="muted">Use Cmd/Ctrl+click to pick one or more models. Leave empty for all models.</div>
	                      </div>
	                      <div>
	                        <label>Trim (optional)</label>
	                        <div class="fitment-trim-multi fitment-trim-list" style="max-height:180px; overflow:auto; border:1px solid #ddd; border-radius:6px; padding:8px;">
	                          <div class="muted">Select a make and model to load trims.</div>
	                        </div>
	                        <div class="muted">Check one or more trims across selected model(s). Leave unchecked for all trims under selected models.</div>
	                      </div>
                      <div>
                        <label>Min Year</label>
                        <input type="number" name="min_year">
                      </div>
                      <div>
                        <label>Max Year</label>
                        <input type="number" name="max_year">
                      </div>
                      <div>
                        <label>Variant</label>
                        <input type="text" name="model_variant">
                      </div>
	                      <div>
	                        <label>Body Style</label>
	                        <input type="text" name="body_style">
	                      </div>
	                    </div>
                    <div class="muted fitment-selection-summary" class="mt-6">
                      Selection: <span class="fitment-model-count">0</span> model(s), <span class="fitment-trim-count">0</span> trim(s)
                    </div>
                    <button type="submit" class="btn" class="mt-8">Add Fitment</button>
                  </form>
                </div>

              </details>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
	    </table>
	  </div>
	<script nonce="<?= dealerfai_csp_nonce() ?>">
	  async function populateModels(makeId, modelSelect, selectedModelId, emptyLabel) {
	    const label = emptyLabel || 'All';
	    modelSelect.innerHTML = `<option value="">${label}</option>`;
	    modelSelect.disabled = true;
	    if (!makeId) {
	      modelSelect.disabled = false;
	      return;
	    }
	    try {
	      const resp = await fetch(`api/vehicle_models.php?make_id=${encodeURIComponent(makeId)}`);
	      const data = await resp.json();
	      if (Array.isArray(data.models)) {
	        data.models.forEach(model => {
	          const opt = document.createElement('option');
	          opt.value = model.id;
	          opt.textContent = model.name;
	          if (selectedModelId && String(model.id) === String(selectedModelId)) {
	            opt.selected = true;
	          }
	          modelSelect.appendChild(opt);
	        });
	      }
	    } finally {
	      modelSelect.disabled = false;
	    }
	  }

	  async function populateTrims(modelId, trimSelect, selectedTrimId, emptyLabel) {
	    const label = emptyLabel || 'All';
	    trimSelect.innerHTML = `<option value="">${label}</option>`;
	    trimSelect.disabled = true;
	    if (!modelId) {
	      trimSelect.disabled = false;
	      return;
	    }
	    try {
	      const resp = await fetch(`api/vehicle_trims.php?model_id=${encodeURIComponent(modelId)}`);
	      const data = await resp.json();
	      if (Array.isArray(data.trims)) {
	        data.trims.forEach(trim => {
	          const opt = document.createElement('option');
	          opt.value = trim.id;
	          opt.textContent = trim.name;
	          if (selectedTrimId && String(trim.id) === String(selectedTrimId)) {
	            opt.selected = true;
	          }
	          trimSelect.appendChild(opt);
	        });
	      }
	    } finally {
	      trimSelect.disabled = false;
	    }
	  }

		  document.querySelectorAll('.fitment-make').forEach(makeSelect => {
		    makeSelect.addEventListener('change', async () => {
		      const form = makeSelect.closest('form');
		      const modelSelect = form?.querySelector('.fitment-model');
		      const trimSelect = form?.querySelector('.fitment-trim');
		      if (!modelSelect) return;
	      modelSelect.innerHTML = '<option value="">All models</option>';
	      modelSelect.disabled = true;
	      if (trimSelect) {
	        trimSelect.innerHTML = '<option value="">Select trim (optional)</option>';
	        trimSelect.disabled = true;
		      }
		      const makeId = makeSelect.value;
		      if (!makeId) return;
		      try {
		        await populateModels(makeId, modelSelect, null, 'All models');
		      } catch (err) {
		        modelSelect.disabled = false;
		      }
		    });
		  });
		  document.querySelectorAll('.fitment-model').forEach(modelSelect => {
		    modelSelect.addEventListener('change', async () => {
		      const form = modelSelect.closest('form');
		      const trimSelect = form?.querySelector('.fitment-trim');
		      if (!trimSelect) return;
		      trimSelect.innerHTML = '<option value="">Select trim (optional)</option>';
		      trimSelect.disabled = true;
		      const modelId = modelSelect.value;
		      if (!modelId) {
		        // All models selected -> keep trims disabled.
		        return;
		      }
		      try {
		        await populateTrims(modelId, trimSelect, null, 'Select trim (optional)');
		      } catch (err) {
		        trimSelect.disabled = false;
		      }
		    });
		  });

		  // Multi-select add-fitment form: supports selecting multiple models and trims across those models.
		  (function initAddFitmentMultiSelect() {
		    const makeSelects = document.querySelectorAll('.fitment-make-multi');
		    if (!makeSelects.length) return;

		    const selectedValues = (selectEl) => {
		      if (!selectEl) return [];
		      return Array.from(selectEl.selectedOptions || [])
		        .map(opt => parseInt(opt.value || '0', 10))
		        .filter(v => v > 0);
		    };
		    const selectedTrimValues = (trimContainer) => {
		      if (!trimContainer) return [];
		      return Array.from(trimContainer.querySelectorAll('input[name="trim_ids[]"]:checked'))
		        .map(input => parseInt(input.value || '0', 10))
		        .filter(v => v > 0);
		    };
		    const updateSelectionSummary = (form) => {
		      if (!form) return;
		      const modelSelect = form.querySelector('.fitment-model-multi');
		      const trimContainer = form.querySelector('.fitment-trim-multi');
		      const modelCountEl = form.querySelector('.fitment-model-count');
		      const trimCountEl = form.querySelector('.fitment-trim-count');
		      if (!modelCountEl || !trimCountEl) return;
		      const modelCount = selectedValues(modelSelect).length;
		      const trimCount = selectedTrimValues(trimContainer).length;
		      modelCountEl.textContent = String(modelCount);
		      trimCountEl.textContent = String(trimCount);
		    };

		    const populateModelsMulti = async (makeId, modelSelect) => {
		      modelSelect.innerHTML = '';
		      modelSelect.disabled = true;
		      if (!makeId) {
		        modelSelect.disabled = false;
		        return;
		      }
		      try {
		        const resp = await fetch(`api/vehicle_models.php?make_id=${encodeURIComponent(makeId)}`);
		        const data = await resp.json();
		        if (Array.isArray(data.models)) {
		          data.models.forEach(model => {
		            const opt = document.createElement('option');
		            opt.value = model.id;
		            opt.textContent = model.name;
		            modelSelect.appendChild(opt);
		          });
		        }
		      } finally {
		        modelSelect.disabled = false;
		      }
		    };

		    const populateTrimsMulti = async (modelIds, modelSelect, trimContainer) => {
		      const selectedTrimIds = selectedTrimValues(trimContainer);
		      trimContainer.innerHTML = '';
		      if (!modelIds.length) {
		        trimContainer.innerHTML = '<div class="muted">Select at least one model to load trims.</div>';
		        return;
		      }
		      try {
		        const modelLabelById = new Map();
		        const optionNodes = Array.from(modelSelect?.options || []);
		        optionNodes.forEach(opt => {
		          const id = parseInt(opt.value || '0', 10);
		          if (id > 0) modelLabelById.set(id, (opt.textContent || '').trim());
		        });

		        const trimGroups = await Promise.all(
		          modelIds.map(async modelId => {
		            const resp = await fetch(`api/vehicle_trims.php?model_id=${encodeURIComponent(modelId)}`);
		            const data = await resp.json();
		            return {
		              modelId,
		              trims: Array.isArray(data.trims) ? data.trims : [],
		            };
		          })
		        );
		        const includeModelPrefix = modelIds.length > 1;
		        let renderedCount = 0;
		        trimGroups.forEach(group => {
		          const modelName = modelLabelById.get(group.modelId) || '';
		          group.trims.forEach(trim => {
		            const label = document.createElement('label');
		            label.style.display = 'block';
		            label.style.marginBottom = '4px';

		            const checkbox = document.createElement('input');
		            checkbox.type = 'checkbox';
		            checkbox.name = 'trim_ids[]';
		            checkbox.value = String(trim.id);
		            checkbox.style.marginRight = '6px';
		            if (selectedTrimIds.includes(parseInt(trim.id || '0', 10))) {
		              checkbox.checked = true;
		            }

		            const text = document.createElement('span');
		            text.textContent = includeModelPrefix && modelName ? `${modelName} - ${trim.name}` : trim.name;

		            label.appendChild(checkbox);
		            label.appendChild(text);
		            trimContainer.appendChild(label);
		            renderedCount++;
		          });
		        });
		        if (!renderedCount) {
		          trimContainer.innerHTML = '<div class="muted">No trims found for selected model(s).</div>';
		        }
		      } finally {
		        updateSelectionSummary(trimContainer.closest('form'));
		      }
		    };

		    makeSelects.forEach(makeSelect => {
		      makeSelect.addEventListener('change', async () => {
		        const form = makeSelect.closest('form');
		        const modelSelect = form?.querySelector('.fitment-model-multi');
		        const trimContainer = form?.querySelector('.fitment-trim-multi');
		        if (!modelSelect || !trimContainer) return;

		        modelSelect.innerHTML = '';
		        trimContainer.innerHTML = '<div class="muted">Select at least one model to load trims.</div>';
		        updateSelectionSummary(form);

		        const makeId = makeSelect.value;
		        if (!makeId) {
		          modelSelect.disabled = true;
		          trimContainer.innerHTML = '<div class="muted">Select a make and model to load trims.</div>';
		          updateSelectionSummary(form);
		          return;
		        }
		        await populateModelsMulti(makeId, modelSelect);
		        updateSelectionSummary(form);
		      });
		    });

		    document.querySelectorAll('.fitment-model-multi').forEach(modelSelect => {
		      modelSelect.addEventListener('change', async () => {
		        const form = modelSelect.closest('form');
		        const trimContainer = form?.querySelector('.fitment-trim-multi');
		        if (!trimContainer) return;

		        trimContainer.innerHTML = '<div class="muted">Loading trims...</div>';
		        const selectedModels = selectedValues(modelSelect);
		        if (!selectedModels.length) {
		          trimContainer.innerHTML = '<div class="muted">Select at least one model to load trims.</div>';
		          updateSelectionSummary(form);
		          return;
		        }
		        await populateTrimsMulti(selectedModels, modelSelect, trimContainer);
		        updateSelectionSummary(form);
		      });
		    });
		    document.querySelectorAll('.fitment-trim-multi').forEach(trimContainer => {
		      trimContainer.addEventListener('change', () => {
		        updateSelectionSummary(trimContainer.closest('form'));
		      });
		      updateSelectionSummary(trimContainer.closest('form'));
		    });
		  })();

		  // Add Accessory initial fitment dropdowns.
		  (function initNewAccessoryFitment() {
		    const makeSelect = document.querySelector('.new-fitment-make');
		    const modelSelect = document.querySelector('.new-fitment-model-multi');
		    const trimSelect = document.querySelector('.new-fitment-trim-multi');
		    const modelCountEl = document.querySelector('.new-fitment-model-count');
		    const trimCountEl = document.querySelector('.new-fitment-trim-count');
		    if (!makeSelect || !modelSelect || !trimSelect || !modelCountEl || !trimCountEl) return;

		    const selectedModelIds = () => Array.from(modelSelect.selectedOptions || [])
		      .map(opt => parseInt(opt.value || '0', 10))
		      .filter(v => v > 0);
		    const selectedTrimIds = () => Array.from(trimSelect.selectedOptions || [])
		      .map(opt => parseInt(opt.value || '0', 10))
		      .filter(v => v > 0);
		    const updateCounts = () => {
		      modelCountEl.textContent = String(selectedModelIds().length);
		      trimCountEl.textContent = String(selectedTrimIds().length);
		    };
		    const enableClickToggleMultiSelect = (selectEl) => {
		      selectEl.addEventListener('mousedown', (event) => {
		        const option = event.target;
		        if (!(option instanceof HTMLOptionElement)) return;
		        event.preventDefault();
		        option.selected = !option.selected;
		        selectEl.focus();
		        selectEl.dispatchEvent(new Event('change', { bubbles: true }));
		      });
		    };
		    enableClickToggleMultiSelect(modelSelect);
		    enableClickToggleMultiSelect(trimSelect);

		    const populateModelsMulti = async (makeId) => {
		      modelSelect.innerHTML = '';
		      modelSelect.disabled = true;
		      if (!makeId) {
		        modelSelect.disabled = false;
		        return;
		      }
		      try {
		        const resp = await fetch(`api/vehicle_models.php?make_id=${encodeURIComponent(makeId)}`);
		        const data = await resp.json();
		        if (Array.isArray(data.models)) {
		          data.models.forEach(model => {
		            const opt = document.createElement('option');
		            opt.value = model.id;
		            opt.textContent = model.name;
		            modelSelect.appendChild(opt);
		          });
		        }
		      } finally {
		        modelSelect.disabled = false;
		      }
		    };

		    const populateTrimsMulti = async () => {
		      const modelIds = selectedModelIds();
		      const previouslySelected = selectedTrimIds();
		      trimSelect.innerHTML = '';
		      trimSelect.disabled = true;
		      if (!modelIds.length) {
		        trimSelect.innerHTML = '<option value="">All trims</option>';
		        trimSelect.disabled = false;
		        updateCounts();
		        return;
		      }
		      try {
		        const modelLabelById = new Map();
		        Array.from(modelSelect.options || []).forEach(opt => {
		          const id = parseInt(opt.value || '0', 10);
		          if (id > 0) modelLabelById.set(id, (opt.textContent || '').trim());
		        });
		        const trimGroups = await Promise.all(
		          modelIds.map(async modelId => {
		            const resp = await fetch(`api/vehicle_trims.php?model_id=${encodeURIComponent(modelId)}`);
		            const data = await resp.json();
		            return {
		              modelId,
		              trims: Array.isArray(data.trims) ? data.trims : [],
		            };
		          })
		        );
		        const showModelPrefix = modelIds.length > 1;
		        let renderedCount = 0;
		        trimGroups.forEach(group => {
		          const modelName = modelLabelById.get(group.modelId) || '';
		          group.trims.forEach(trim => {
		            const opt = document.createElement('option');
		            opt.value = trim.id;
		            opt.textContent = showModelPrefix && modelName ? `${modelName} - ${trim.name}` : trim.name;
		            if (previouslySelected.includes(parseInt(trim.id || '0', 10))) {
		              opt.selected = true;
		            }
		            trimSelect.appendChild(opt);
		            renderedCount++;
		          });
		        });
		        if (!renderedCount) {
		          trimSelect.innerHTML = '<option value="">No trims found for selected model(s)</option>';
		        }
		      } finally {
		        trimSelect.disabled = false;
		        updateCounts();
		      }
		    };

		    makeSelect.addEventListener('change', async () => {
		      modelSelect.innerHTML = '';
		      trimSelect.innerHTML = '<option value="">All trims</option>';
		      trimSelect.disabled = true;
		      updateCounts();
		      const makeId = makeSelect.value;
		      if (!makeId) {
		        modelSelect.disabled = true;
		        trimSelect.innerHTML = '<option value="">All trims</option>';
		        updateCounts();
		        return;
		      }
		      await populateModelsMulti(makeId);
		      updateCounts();
		    });

		    modelSelect.addEventListener('change', async () => {
		      trimSelect.innerHTML = '<option value="">Loading trims...</option>';
		      await populateTrimsMulti();
		    });

		    trimSelect.addEventListener('change', () => {
		      updateCounts();
		    });
		    updateCounts();
		  })();

		  // Wire up the filter form's Make -> Model -> Trim dropdowns (preserves selected query params on reload).
		  (function initFilterDropdowns() {
		    const makeSelect = document.querySelector('.filter-make');
		    const modelSelect = document.querySelector('.filter-model');
	    const trimSelect = document.querySelector('.filter-trim');
	    if (!makeSelect || !modelSelect || !trimSelect) return;

	    const selectedModelId = document.querySelector('.filter-model-selected')?.value || '';
	    const selectedTrimId = document.querySelector('.filter-trim-selected')?.value || '';

		    async function refreshModels() {
		      const makeId = makeSelect.value;
		      // Clear trim when make changes.
		      trimSelect.innerHTML = '<option value="">All</option>';
		      trimSelect.disabled = true;
		      await populateModels(makeId, modelSelect, selectedModelId, 'All');
		      // If we have a selected model, load trims right away.
		      if (modelSelect.value) {
		        await populateTrims(modelSelect.value, trimSelect, selectedTrimId, 'All');
		      } else {
		        trimSelect.disabled = false;
		      }
		    }

	    makeSelect.addEventListener('change', async () => {
	      // Changing make invalidates stored model/trim.
	      document.querySelector('.filter-model-selected')?.setAttribute('value', '');
	      document.querySelector('.filter-trim-selected')?.setAttribute('value', '');
	      await refreshModels();
	    });

		    modelSelect.addEventListener('change', async () => {
		      trimSelect.innerHTML = '<option value="">All</option>';
		      trimSelect.disabled = true;
		      if (!modelSelect.value) {
		        trimSelect.disabled = false;
		        return;
		      }
		      await populateTrims(modelSelect.value, trimSelect, '', 'All');
		    });

	    // Initial page load.
	    // Enable selects immediately, then populate based on current make/model selections.
	    modelSelect.disabled = false;
	    trimSelect.disabled = false;
	    refreshModels().catch(() => {
	      modelSelect.disabled = false;
	      trimSelect.disabled = false;
	    });
	  })();

	  // Live preview for uploaded accessory photos (Add + Manage forms).
	  (function initPhotoPreviews() {
	    function setPreview(img, file) {
	      if (!img) return;
	      if (!file) {
	        img.src = '';
	        img.classList.add('hidden');
	        return;
	      }
	      const url = URL.createObjectURL(file);
	      img.src = url;
	      img.classList.remove('hidden');
	      img.onload = () => URL.revokeObjectURL(url);
	    }

	    document.querySelectorAll('input[type="file"][name="photo"]').forEach(input => {
	      const container = input.parentElement;
	      const preview = container ? container.querySelector('.js-photo-preview') : null;
	      input.addEventListener('change', () => {
	        const file = input.files && input.files[0] ? input.files[0] : null;
	        setPreview(preview, file);
	      });

	      const form = input.closest('form');
	      const remove = form ? form.querySelector('input[type="checkbox"][name="remove_photo"]') : null;
	      if (remove && preview) {
	        remove.addEventListener('change', () => {
	          if (remove.checked) {
	            setPreview(preview, null);
	            input.value = '';
	          }
	        });
	      }
	    });
	  })();

	  // Populate "Edit fitment" dropdowns on demand (when the details section is opened).
	  (function initFitmentEditDetails() {
	    document.querySelectorAll('.fitment-edit-details').forEach(details => {
	      details.addEventListener('toggle', async () => {
	        if (!details.open) return;
	        if (details.dataset.initialized === '1') return;
	        details.dataset.initialized = '1';

	        const form = details.querySelector('form');
	        if (!form) return;
	        const makeSelect = form.querySelector('.fitment-make');
	        const modelSelect = form.querySelector('.fitment-model');
	        const trimSelect = form.querySelector('.fitment-trim');
	        if (!makeSelect || !modelSelect || !trimSelect) return;

	        const makeId = makeSelect.value;
	        const selectedModelId = modelSelect.dataset.selected || '';
	        const selectedTrimId = trimSelect.dataset.selected || '';

	        if (!makeId) {
	          modelSelect.disabled = true;
	          trimSelect.disabled = true;
	          return;
	        }

	        try {
	          await populateModels(makeId, modelSelect, selectedModelId, 'All models');
	          if (modelSelect.value) {
	            await populateTrims(modelSelect.value, trimSelect, selectedTrimId, 'All trims');
	          } else {
	            trimSelect.innerHTML = '<option value="">All trims</option>';
	            trimSelect.disabled = true;
	          }
	        } catch (e) {
	          modelSelect.disabled = false;
	          trimSelect.disabled = false;
	        }
	      });
	    });
	  })();

	  (function initQuickPicks() {
	    function buildQuickPicks(input) {
	      if (!input) return;
	      const listId = input.dataset.quickList || input.getAttribute('list');
	      if (!listId) return;
	      const list = document.getElementById(listId);
	      if (!list) return;
	      const container = input.parentElement ? input.parentElement.querySelector('.js-quick-picks') : null;
	      if (!container) return;

	      const values = Array.from(list.querySelectorAll('option'))
	        .map((opt) => (opt.value || '').trim())
	        .filter(Boolean);
	      const seen = new Set();
	      const unique = values.filter((value) => {
	        const key = value.toLowerCase();
	        if (seen.has(key)) return false;
	        seen.add(key);
	        return true;
	      }).slice(0, 12);
	      if (!unique.length) return;

	      container.innerHTML = '';
	      const syncActive = () => {
	        const current = (input.value || '').trim().toLowerCase();
	        container.querySelectorAll('button').forEach((btn) => {
	          btn.classList.toggle('is-active', (btn.dataset.value || '').toLowerCase() === current && current !== '');
	        });
	      };

	      unique.forEach((value) => {
	        const btn = document.createElement('button');
	        btn.type = 'button';
	        btn.className = 'quick-pick-btn';
	        btn.dataset.value = value;
	        btn.textContent = value;
	        btn.addEventListener('click', () => {
	          input.value = value;
	          input.dispatchEvent(new Event('input', { bubbles: true }));
	          input.dispatchEvent(new Event('change', { bubbles: true }));
	          syncActive();
	        });
	        container.appendChild(btn);
	      });

	      input.addEventListener('input', syncActive);
	      input.addEventListener('change', syncActive);
	      syncActive();
	    }

	    document.querySelectorAll('.js-quick-pick-input').forEach(buildQuickPicks);
	  })();

	</script>
	</body>
	</html>
