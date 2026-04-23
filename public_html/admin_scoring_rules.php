<?php
include 'auth.php';
require_once __DIR__ . '/includes/theme_head.php';
include 'db.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/helpers/session_utils.php';
require_once __DIR__ . '/helpers/db_utils.php';

$roles = load_session_roles();
if (!in_array('Admin', $roles, true)) {
    http_response_code(403);
    echo 'Unauthorized';
    exit;
}
$adminAlertCount = get_admin_alert_count($db);
$hasGlobalMentions = column_exists($db, 'product_scoring_tags', 'mention_detail');
$hasOrgMentions = column_exists($db, 'organization_scoring_models', 'mention_detail');

function normalize_mention_text(?string $raw): ?string
{
    if ($raw === null) {
        return null;
    }
    $trimmed = trim($raw);
    return $trimmed === '' ? null : $trimmed;
}

$accessibleOrgs = get_accessible_organizations();
if (empty($accessibleOrgs)) {
    $orgRows = $db->query("SELECT id FROM organizations")->fetchAll(PDO::FETCH_COLUMN);
    $accessibleOrgs = array_map('intval', $orgRows);
}

$orgContextId = get_admin_organization_context();
$selectedOrgParamRaw = $_GET['org'] ?? null;
$isGlobalView = false;
if ($selectedOrgParamRaw === 'global') {
    $selectedOrg = 0;
    $isGlobalView = true;
} else {
    $selectedOrgParam = isset($_GET['org']) ? (int)$_GET['org'] : 0;
    if ($orgContextId) {
        $selectedOrg = $orgContextId;
    } elseif ($selectedOrgParam && in_array($selectedOrgParam, $accessibleOrgs, true)) {
        $selectedOrg = $selectedOrgParam;
    } else {
        $selectedOrg = $accessibleOrgs[0] ?? 0;
    }
}

$filterProductCode = trim((string)($_GET['filter_product_code'] ?? ''));
$filterTagCode = trim((string)($_GET['filter_tag_code'] ?? ''));

$orgOptions = [];
if (!empty($accessibleOrgs)) {
    $placeholders = implode(',', array_fill(0, count($accessibleOrgs), '?'));
    $stmt = $db->prepare("SELECT id, name FROM organizations WHERE id IN ($placeholders) ORDER BY name ASC");
    $stmt->execute($accessibleOrgs);
    $orgOptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
$orgNames = [];
foreach ($orgOptions as $orgOption) {
    $orgNames[(int)($orgOption['id'] ?? 0)] = (string)($orgOption['name'] ?? '');
}

$selectedOrgInfo = null;
if ($selectedOrg) {
    $orgInfoStmt = $db->prepare("SELECT id, name, org_kind, parent_org_id, logic_type FROM organizations WHERE id = ?");
    $orgInfoStmt->execute([$selectedOrg]);
    $selectedOrgInfo = $orgInfoStmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
$primaryOrgId = null;
$primaryOrgName = '';
if ($selectedOrgInfo) {
    if (($selectedOrgInfo['org_kind'] ?? '') === 'group') {
        $primaryOrgId = (int)$selectedOrgInfo['id'];
        $primaryOrgName = $selectedOrgInfo['name'] ?? '';
    } elseif (!empty($selectedOrgInfo['parent_org_id'])) {
        $primaryOrgId = (int)$selectedOrgInfo['parent_org_id'];
        $primaryOrgName = $orgNames[$primaryOrgId] ?? '';
    }
}

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!dealerfai_csrf_validate($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        die('Invalid request.');
    }

    $action = $_POST['action'] ?? '';
    $selectedOrg = (int)($_POST['org_id'] ?? $selectedOrg);

    if ($action === 'add_global_rule') {
        $productCode = trim($_POST['product_code'] ?? '');
        $tagCode = trim($_POST['tag_code'] ?? '');
        $score = (int)($_POST['score'] ?? 0);
        $exclude = isset($_POST['exclude_if_matched']) ? 1 : 0;
        $mentionDetail = $hasGlobalMentions ? normalize_mention_text($_POST['mention_detail'] ?? '') : null;
        if ($productCode !== '' && $tagCode !== '') {
            if ($hasGlobalMentions) {
                $updateStmt = $db->prepare("
                    UPDATE product_scoring_tags
                    SET score = ?, exclude_if_matched = ?, mention_detail = ?
                    WHERE product_code = ? AND tag_code = ?
                ");
                $updateStmt->execute([$score, $exclude, $mentionDetail, $productCode, $tagCode]);
            } else {
                $updateStmt = $db->prepare("
                    UPDATE product_scoring_tags
                    SET score = ?, exclude_if_matched = ?
                    WHERE product_code = ? AND tag_code = ?
                ");
                $updateStmt->execute([$score, $exclude, $productCode, $tagCode]);
            }
            if ($updateStmt->rowCount() === 0) {
                if ($hasGlobalMentions) {
                    $insertStmt = $db->prepare("
                        INSERT INTO product_scoring_tags (product_code, tag_code, score, exclude_if_matched, mention_detail)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $insertStmt->execute([$productCode, $tagCode, $score, $exclude, $mentionDetail]);
                } else {
                    $insertStmt = $db->prepare("
                        INSERT INTO product_scoring_tags (product_code, tag_code, score, exclude_if_matched)
                        VALUES (?, ?, ?, ?)
                    ");
                    $insertStmt->execute([$productCode, $tagCode, $score, $exclude]);
                }
                $message = 'Base rule added.';
            } else {
                $message = 'Base rule updated.';
            }
        }
    } elseif ($action === 'update_global_rule') {
        $productCode = trim($_POST['product_code'] ?? '');
        $tagCode = trim($_POST['tag_code'] ?? '');
        $score = (int)($_POST['score'] ?? 0);
        $exclude = isset($_POST['exclude_if_matched']) ? 1 : 0;
        $mentionDetail = $hasGlobalMentions ? normalize_mention_text($_POST['mention_detail'] ?? '') : null;
        if ($productCode !== '' && $tagCode !== '') {
            if ($hasGlobalMentions) {
                $stmt = $db->prepare("
                    UPDATE product_scoring_tags
                    SET score = ?, exclude_if_matched = ?, mention_detail = ?
                    WHERE product_code = ? AND tag_code = ?
                ");
                $stmt->execute([$score, $exclude, $mentionDetail, $productCode, $tagCode]);
            } else {
                $stmt = $db->prepare("
                    UPDATE product_scoring_tags
                    SET score = ?, exclude_if_matched = ?
                    WHERE product_code = ? AND tag_code = ?
                ");
                $stmt->execute([$score, $exclude, $productCode, $tagCode]);
            }
            $message = 'Base rule updated.';
        }
    } elseif ($action === 'delete_global_rule') {
        $productCode = trim($_POST['product_code'] ?? '');
        $tagCode = trim($_POST['tag_code'] ?? '');
        if ($productCode !== '' && $tagCode !== '') {
            $stmt = $db->prepare("DELETE FROM product_scoring_tags WHERE product_code = ? AND tag_code = ?");
            $stmt->execute([$productCode, $tagCode]);
            $message = 'Base rule removed.';
        }
    } elseif ($action === 'add_rule') {
        $productCode = trim($_POST['product_code'] ?? '');
        $tagCode = trim($_POST['tag_code'] ?? '');
        $score = (int)($_POST['score'] ?? 0);
        $exclude = isset($_POST['exclude_if_matched']) ? 1 : 0;
        $mentionDetail = $hasOrgMentions ? normalize_mention_text($_POST['mention_detail'] ?? '') : null;
        if ($productCode !== '' && $tagCode !== '' && $selectedOrg) {
            if ($hasOrgMentions) {
                $updateStmt = $db->prepare("
                    UPDATE organization_scoring_models
                    SET score = ?, exclude_if_matched = ?, mention_detail = ?
                    WHERE organization_id = ? AND product_code = ? AND tag_code = ?
                ");
                $updateStmt->execute([$score, $exclude, $mentionDetail, $selectedOrg, $productCode, $tagCode]);
            } else {
                $updateStmt = $db->prepare("
                    UPDATE organization_scoring_models
                    SET score = ?, exclude_if_matched = ?
                    WHERE organization_id = ? AND product_code = ? AND tag_code = ?
                ");
                $updateStmt->execute([$score, $exclude, $selectedOrg, $productCode, $tagCode]);
            }
            if ($updateStmt->rowCount() === 0) {
                if ($hasOrgMentions) {
                    $insertStmt = $db->prepare("
                        INSERT INTO organization_scoring_models (organization_id, product_code, tag_code, score, exclude_if_matched, mention_detail)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $insertStmt->execute([$selectedOrg, $productCode, $tagCode, $score, $exclude, $mentionDetail]);
                } else {
                    $insertStmt = $db->prepare("
                        INSERT INTO organization_scoring_models (organization_id, product_code, tag_code, score, exclude_if_matched)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $insertStmt->execute([$selectedOrg, $productCode, $tagCode, $score, $exclude]);
                }
                $message = 'Scoring rule added.';
            } else {
                $message = 'Scoring rule updated.';
            }
        }
    } elseif ($action === 'update_rule') {
        $productCode = trim($_POST['product_code'] ?? '');
        $tagCode = trim($_POST['tag_code'] ?? '');
        $score = (int)($_POST['score'] ?? 0);
        $exclude = isset($_POST['exclude_if_matched']) ? 1 : 0;
        $mentionDetail = $hasOrgMentions ? normalize_mention_text($_POST['mention_detail'] ?? '') : null;
        if ($productCode !== '' && $tagCode !== '' && $selectedOrg) {
            if ($hasOrgMentions) {
                $stmt = $db->prepare("
                    UPDATE organization_scoring_models
                    SET score = ?, exclude_if_matched = ?, mention_detail = ?
                    WHERE organization_id = ? AND product_code = ? AND tag_code = ?
                ");
                $stmt->execute([$score, $exclude, $mentionDetail, $selectedOrg, $productCode, $tagCode]);
            } else {
                $stmt = $db->prepare("
                    UPDATE organization_scoring_models
                    SET score = ?, exclude_if_matched = ?
                    WHERE organization_id = ? AND product_code = ? AND tag_code = ?
                ");
                $stmt->execute([$score, $exclude, $selectedOrg, $productCode, $tagCode]);
            }
            $message = 'Scoring rule updated.';
        }
    } elseif ($action === 'delete_rule') {
        $productCode = trim($_POST['product_code'] ?? '');
        $tagCode = trim($_POST['tag_code'] ?? '');
        if ($productCode !== '' && $tagCode !== '' && $selectedOrg) {
            $stmt = $db->prepare("
                DELETE FROM organization_scoring_models
                WHERE organization_id = ? AND product_code = ? AND tag_code = ?
            ");
            $stmt->execute([$selectedOrg, $productCode, $tagCode]);
            $message = 'Scoring rule removed.';
        }
    }
}

$products = $db->query("
    SELECT code, MIN(name) AS name
    FROM products
    WHERE is_active = 1
    GROUP BY code
    ORDER BY name ASC
")->fetchAll(PDO::FETCH_ASSOC);
if ($selectedOrg) {
    // Fetch products with organization-specific names
    $orgKind = $selectedOrgInfo['org_kind'] ?? 'store';
    $parentId = $selectedOrgInfo['parent_org_id'] ?? null;
    $logicType = $selectedOrgInfo['logic_type'] ?? '';
    $pOrgId = (string)$selectedOrg;
    $pGroupId = $parentId ? (string)$parentId : '';

    $stmt = $db->prepare("
        SELECT p.code, GROUP_CONCAT(DISTINCT COALESCE(poo.custom_name, p.name) ORDER BY COALESCE(poo.custom_name, p.name) SEPARATOR ' / ') as name
        FROM products p
        LEFT JOIN product_organization_overrides poo ON p.id = poo.product_id AND poo.organization_id = ?
        LEFT JOIN product_availability av_store_variant
          ON av_store_variant.product_id = p.id
         AND av_store_variant.scope_type = 'store'
         AND av_store_variant.scope_value = ?
        LEFT JOIN product_availability av_store_provider
          ON av_store_provider.product_id IS NULL
         AND av_store_provider.product_code = p.code
         AND av_store_provider.provider = p.provider
         AND av_store_provider.scope_type = 'store'
         AND av_store_provider.scope_value = ?
        LEFT JOIN product_availability av_store_any
          ON av_store_any.product_id IS NULL
         AND av_store_any.product_code = p.code
         AND av_store_any.provider = ''
         AND av_store_any.scope_type = 'store'
         AND av_store_any.scope_value = ?
        LEFT JOIN product_availability av_org_variant
          ON av_org_variant.product_id = p.id
         AND av_org_variant.scope_type = 'org'
         AND av_org_variant.scope_value = ?
        LEFT JOIN product_availability av_org_provider
          ON av_org_provider.product_id IS NULL
         AND av_org_provider.product_code = p.code
         AND av_org_provider.provider = p.provider
         AND av_org_provider.scope_type = 'org'
         AND av_org_provider.scope_value = ?
        LEFT JOIN product_availability av_org_any
          ON av_org_any.product_id IS NULL
         AND av_org_any.product_code = p.code
         AND av_org_any.provider = ''
         AND av_org_any.scope_type = 'org'
         AND av_org_any.scope_value = ?
        LEFT JOIN product_availability av_group_variant
          ON av_group_variant.product_id = p.id
         AND av_group_variant.scope_type = 'org'
         AND av_group_variant.scope_value = ?
        LEFT JOIN product_availability av_group_provider
          ON av_group_provider.product_id IS NULL
         AND av_group_provider.product_code = p.code
         AND av_group_provider.provider = p.provider
         AND av_group_provider.scope_type = 'org'
         AND av_group_provider.scope_value = ?
        LEFT JOIN product_availability av_group_any
          ON av_group_any.product_id IS NULL
         AND av_group_any.product_code = p.code
         AND av_group_any.provider = ''
         AND av_group_any.scope_type = 'org'
         AND av_group_any.scope_value = ?
        LEFT JOIN product_availability av_vertical_variant
          ON av_vertical_variant.product_id = p.id
         AND av_vertical_variant.scope_type = 'vertical'
         AND av_vertical_variant.scope_value = ?
        LEFT JOIN product_availability av_vertical_provider
          ON av_vertical_provider.product_id IS NULL
         AND av_vertical_provider.product_code = p.code
         AND av_vertical_provider.provider = p.provider
         AND av_vertical_provider.scope_type = 'vertical'
         AND av_vertical_provider.scope_value = ?
        LEFT JOIN product_availability av_vertical_any
          ON av_vertical_any.product_id IS NULL
         AND av_vertical_any.product_code = p.code
         AND av_vertical_any.provider = ''
         AND av_vertical_any.scope_type = 'vertical'
         AND av_vertical_any.scope_value = ?
        LEFT JOIN product_availability av_global_variant
          ON av_global_variant.product_id = p.id
         AND av_global_variant.scope_type = 'global'
         AND av_global_variant.scope_value = ''
        LEFT JOIN product_availability av_global_provider
          ON av_global_provider.product_id IS NULL
         AND av_global_provider.product_code = p.code
         AND av_global_provider.provider = p.provider
         AND av_global_provider.scope_type = 'global'
         AND av_global_provider.scope_value = ''
        LEFT JOIN product_availability av_global_any
          ON av_global_any.product_id IS NULL
         AND av_global_any.product_code = p.code
         AND av_global_any.provider = ''
         AND av_global_any.scope_type = 'global'
         AND av_global_any.scope_value = ''
        WHERE p.is_active = 1
          AND COALESCE(
                  av_store_variant.is_enabled, av_store_provider.is_enabled, av_store_any.is_enabled,
                  av_org_variant.is_enabled, av_org_provider.is_enabled, av_org_any.is_enabled,
                  av_group_variant.is_enabled, av_group_provider.is_enabled, av_group_any.is_enabled,
                  av_vertical_variant.is_enabled, av_vertical_provider.is_enabled, av_vertical_any.is_enabled,
                  av_global_variant.is_enabled, av_global_provider.is_enabled, av_global_any.is_enabled,
                  1
              ) = 1
        GROUP BY p.code
        ORDER BY name ASC
    ");
    $stmt->execute([
        $selectedOrg,
        $pOrgId,
        $pOrgId,
        $pOrgId,
        $pOrgId,
        $pOrgId,
        $pOrgId,
        $pGroupId,
        $pGroupId,
        $pGroupId,
        $logicType,
        $logicType,
        $logicType,
    ]);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $products = $db->query("
        SELECT code, GROUP_CONCAT(DISTINCT name ORDER BY name SEPARATOR ' / ') AS name
        FROM products
        WHERE is_active = 1
        GROUP BY code
        ORDER BY name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
}

$tagRows = [];
try {
    $tagRows = $db->query("SELECT tag_code, description FROM scoring_tag_reference ORDER BY tag_code ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $rawTags = $db->query("SELECT DISTINCT tag_code FROM product_scoring_tags ORDER BY tag_code ASC")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($rawTags as $t) {
        $tagRows[] = ['tag_code' => $t, 'description' => ''];
    }
}

$globalRules = [];
$globalSelect = "
    SELECT pst.product_code,
           pst.tag_code,
           MAX(pst.score) AS score,
           MAX(pst.exclude_if_matched) AS exclude_if_matched,
           " . ($hasGlobalMentions ? "MAX(pst.mention_detail) AS mention_detail," : "NULL AS mention_detail,") . "
           p.product_name
    FROM product_scoring_tags pst
    LEFT JOIN (
        SELECT code, GROUP_CONCAT(DISTINCT name ORDER BY name SEPARATOR ' / ') AS product_name
        FROM products
        WHERE is_active = 1
        GROUP BY code
    ) p ON p.code = pst.product_code
    WHERE 1=1
";
$globalParams = [];
if ($filterProductCode !== '') {
    $globalSelect .= " AND pst.product_code = ? ";
    $globalParams[] = $filterProductCode;
}
if ($filterTagCode !== '') {
    $globalSelect .= " AND pst.tag_code = ? ";
    $globalParams[] = $filterTagCode;
}
$globalSelect .= "
    GROUP BY pst.product_code, pst.tag_code, p.product_name
    ORDER BY pst.product_code ASC, pst.tag_code ASC
";
$globalStmt = null;
if (!empty($globalParams)) {
    $globalStmt = $db->prepare($globalSelect);
    $globalStmt->execute($globalParams);
} else {
    $globalStmt = $db->query($globalSelect);
}
if ($globalStmt) {
    $globalRules = $globalStmt->fetchAll(PDO::FETCH_ASSOC);
}

$globalRulesMap = [];
foreach ($globalRules as $rule) {
    $globalRulesMap[$rule['product_code']][$rule['tag_code']] = $rule;
}

$rules = [];
if ($selectedOrg) {
$sql = "
        SELECT osm.product_code,
               osm.tag_code,
               MAX(osm.score) AS score,
               MAX(osm.exclude_if_matched) AS exclude_if_matched,
               " . ($hasOrgMentions ? "MAX(osm.mention_detail) AS mention_detail," : "NULL AS mention_detail,") . "
               GROUP_CONCAT(DISTINCT COALESCE(poo.custom_name, p.name) SEPARATOR ' / ') AS product_name
        FROM organization_scoring_models osm
        LEFT JOIN products p ON p.code = osm.product_code
        LEFT JOIN product_organization_overrides poo 
             ON p.id = poo.product_id AND poo.organization_id = ?
        WHERE osm.organization_id = ?
";
$params = [$selectedOrg, $selectedOrg];
if ($filterProductCode !== '') {
    $sql .= " AND osm.product_code = ? ";
    $params[] = $filterProductCode;
}
if ($filterTagCode !== '') {
    $sql .= " AND osm.tag_code = ? ";
    $params[] = $filterTagCode;
}
$sql .= "
        GROUP BY osm.product_code, osm.tag_code
        ORDER BY osm.product_code ASC, osm.tag_code ASC
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$orgRulesMap = [];
foreach ($rules as $rule) {
    $orgRulesMap[$rule['product_code']][$rule['tag_code']] = $rule;
}

$primaryRules = [];
if ($primaryOrgId && $primaryOrgId !== (int)$selectedOrg) {
$sql = "
        SELECT osm.product_code,
               osm.tag_code,
               MAX(osm.score) AS score,
               MAX(osm.exclude_if_matched) AS exclude_if_matched,
               " . ($hasOrgMentions ? "MAX(osm.mention_detail) AS mention_detail," : "NULL AS mention_detail,") . "
               p.product_name
        FROM organization_scoring_models osm
        LEFT JOIN (
            SELECT code, GROUP_CONCAT(DISTINCT name ORDER BY name SEPARATOR ' / ') AS product_name
            FROM products
            WHERE is_active = 1
            GROUP BY code
        ) p ON p.code = osm.product_code
        WHERE osm.organization_id = ?
";
$params = [$primaryOrgId];
if ($filterProductCode !== '') {
    $sql .= " AND osm.product_code = ? ";
    $params[] = $filterProductCode;
}
if ($filterTagCode !== '') {
    $sql .= " AND osm.tag_code = ? ";
    $params[] = $filterTagCode;
}
$sql .= "
        GROUP BY osm.product_code, osm.tag_code, p.product_name
        ORDER BY osm.product_code ASC, osm.tag_code ASC
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $primaryRules = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$primaryRulesMap = [];
foreach ($primaryRules as $rule) {
    $primaryRulesMap[$rule['product_code']][$rule['tag_code']] = $rule;
}

$combinedRules = [];
if (!$isGlobalView && $selectedOrg) {
    $allKeys = [];
    $addKeys = function (array $map) use (&$allKeys): void {
        foreach ($map as $productCode => $tags) {
            foreach ($tags as $tagCode => $_rule) {
                $allKeys[$productCode][$tagCode] = true;
            }
        }
    };
    $addKeys($globalRulesMap);
    $addKeys($primaryRulesMap);
    $addKeys($orgRulesMap);

    foreach ($allKeys as $productCode => $tags) {
        foreach ($tags as $tagCode => $_) {
            $base = $globalRulesMap[$productCode][$tagCode] ?? null;
            $primary = $primaryRulesMap[$productCode][$tagCode] ?? null;
            $org = $orgRulesMap[$productCode][$tagCode] ?? null;
            $effective = $org ?? $primary ?? $base ?? null;
            $productName = $effective['product_name']
                ?? ($base['product_name'] ?? ($primary['product_name'] ?? ($org['product_name'] ?? $productCode)));
            $combinedRules[] = [
                'product_code' => $productCode,
                'tag_code' => $tagCode,
                'product_name' => $productName,
                'base_score' => $base['score'] ?? null,
                'base_exclude' => $base['exclude_if_matched'] ?? null,
                'base_mention' => $base['mention_detail'] ?? null,
                'inherited_mention' => $primary['mention_detail'] ?? ($base['mention_detail'] ?? null),
                'effective_score' => $effective['score'] ?? 0,
                'effective_exclude' => $effective['exclude_if_matched'] ?? 0,
                'effective_mention' => $effective['mention_detail'] ?? null,
                'org_score' => $org['score'] ?? null,
                'org_exclude' => $org['exclude_if_matched'] ?? null,
                'org_mention' => $org['mention_detail'] ?? null,
                'source' => $org ? 'Store' : ($primary ? 'Group' : 'Global'),
            ];
        }
    }
    usort($combinedRules, function (array $a, array $b): int {
        if ($a['product_code'] === $b['product_code']) {
            return $a['tag_code'] <=> $b['tag_code'];
        }
        return $a['product_code'] <=> $b['product_code'];
    });
}
$csrfToken = dealerfai_csrf_get_token();
?>
<!DOCTYPE html>
<html>
<head>
  <?php include __DIR__ . "/includes/head_favicon.php"; ?>
<meta charset="UTF-8">
  <title>Admin - Scoring Rules</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php dealerfai_theme_head(); ?>
  <style nonce="<?= dealerfai_csp_nonce() ?>">
    body { font-family: Arial, sans-serif; background: #f4f6f8; margin: 0; padding: 0; }
    header { background: #0a2e36; color: white; padding: 20px; text-align: center; }
    nav { background: #0a2e36; padding: 12px; text-align: center; }
    nav a { color: white; margin: 0 20px; text-decoration: none; font-weight: bold; }
    .container { max-width: 1100px; margin: 20px auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
    h2 { margin-top: 0; }
    .org-picker { display:flex; gap:12px; align-items:center; margin-bottom:20px; }
    .org-picker select { padding: 8px; border-radius: 4px; border: 1px solid #ccc; }
    .org-picker button { padding: 8px 14px; border: none; border-radius: 4px; background: #0a6280; color: white; cursor: pointer; }
    table { width: 100%; border-collapse: collapse; margin-top: 16px; }
    th, td { padding: 10px; border-bottom: 1px solid #ddd; vertical-align: middle; text-align: left; }
    th { background: #f1f4f8; }
    input[type="text"], select, input[type="number"], textarea { padding: 6px; border-radius: 4px; border: 1px solid #ccc; }
    textarea { width: 100%; min-height: 60px; font-family: inherit; }
    .btn { background: #0a6280; color: white; padding: 8px 14px; border: none; border-radius: 4px; cursor: pointer; }
    .btn:hover { background: #094c63; }
    .muted { color: #667085; font-size: 0.9rem; margin-top: 4px; }
    .success { color: green; margin-top: 10px; }
  </style>
</head>
<body>
  <header class="pos-relative">
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
    <h2>Scoring Rules</h2>
    <p class="muted">Define group-level primary rules and store-specific overrides for product recommendations.</p>
    <?php if (!$hasGlobalMentions || !$hasOrgMentions): ?>
      <p class="muted">Tag mention text requires a new <code>mention_detail</code> column on scoring tables. Add it to enable the UI fields.</p>
    <?php endif; ?>

    <form method="get" class="org-picker">
      <label for="org">Organization</label>
      <select id="org" name="org">
        <option value="global" <?= $isGlobalView ? 'selected' : '' ?>>Global</option>
        <?php foreach ($orgOptions as $org): ?>
          <option value="<?= (int)$org['id'] ?>" <?= ((int)$org['id'] === (int)$selectedOrg) ? 'selected' : '' ?>>
            <?= htmlspecialchars($org['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <button type="submit">Load</button>
    </form>

    <form method="get" style="margin-top: 10px; display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap;">
      <input type="hidden" name="org" value="<?= $isGlobalView ? 'global' : (int)$selectedOrg ?>">
      <div>
        <label for="filter_product_code" class="muted" class="d-block">Filter product</label>
        <select id="filter_product_code" name="filter_product_code">
          <option value="">All products</option>
          <?php foreach ($products as $product): ?>
            <?php $pcode = (string)($product['code'] ?? ''); ?>
            <option value="<?= htmlspecialchars($pcode) ?>" <?= ($filterProductCode !== '' && $filterProductCode === $pcode) ? 'selected' : '' ?>>
              <?= htmlspecialchars((string)($product['name'] ?? $pcode)) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="filter_tag_code" class="muted" class="d-block">Filter tag</label>
        <select id="filter_tag_code" name="filter_tag_code">
          <option value="">All tags</option>
          <?php foreach ($tagRows as $tag): ?>
            <?php $tcode = (string)($tag['tag_code'] ?? ''); ?>
            <option value="<?= htmlspecialchars($tcode) ?>" <?= ($filterTagCode !== '' && $filterTagCode === $tcode) ? 'selected' : '' ?>>
              <?= htmlspecialchars($tcode) . (!empty($tag['description']) ? ' (' . htmlspecialchars((string)$tag['description']) . ')' : '') ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit">Filter</button>
      <a class="btn" href="admin_scoring_rules.php?org=<?= $isGlobalView ? 'global' : (int)$selectedOrg ?>" style="text-decoration:none; background:#667085;">Clear</a>
    </form>

    <?php if ($message): ?>
      <p class="success">✅ <?= htmlspecialchars($message) ?></p>
    <?php endif; ?>

    <?php if ($isGlobalView): ?>
      <div class="mt-12">
        <h3 class="mb-6">Base Rules</h3>
        <p class="muted">These rules apply to all organizations unless overridden.</p>
        <form method="post" class="mt-12">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="action" value="add_global_rule">
          <table>
            <thead>
              <tr>
                <th class="w-22">Product</th>
                <th class="w-24">Tag</th>
                <th class="w-10">Score</th>
                <th class="w-10">Exclude</th>
                <th class="w-24">Mentions</th>
                <th class="w-10">Action</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td>
                  <select name="product_code" required>
                    <option value="">Select product</option>
                    <?php foreach ($products as $product): ?>
                      <?php $pcode = (string)($product['code'] ?? ''); ?>
                      <option value="<?= htmlspecialchars($pcode) ?>" <?= ($filterProductCode !== '' && $filterProductCode === $pcode) ? 'selected' : '' ?>>
                        <?= htmlspecialchars((string)($product['name'] ?? $pcode)) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </td>
                <td>
                  <select name="tag_code" required>
                    <option value="">Select tag...</option>
                    <?php foreach ($tagRows as $tag): ?>
                      <?php $tcode = (string)($tag['tag_code'] ?? ''); ?>
                      <option value="<?= htmlspecialchars($tcode) ?>" <?= ($filterTagCode !== '' && $filterTagCode === $tcode) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($tag['tag_code']) . (!empty($tag['description']) ? ' (' . htmlspecialchars($tag['description']) . ')' : '') ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </td>
                <td><input type="number" name="score" value="0"></td>
                <td><input type="checkbox" name="exclude_if_matched" value="1"></td>
                <td>
                  <textarea name="mention_detail" placeholder="One reason per line"></textarea>
                </td>
                <td><button type="submit" class="btn">Add Rule</button></td>
              </tr>
            </tbody>
          </table>
        </form>

        <?php if (empty($globalRules)): ?>
          <p class="muted">No base rules found.</p>
        <?php else: ?>
          <table>
            <thead>
              <tr>
                <th>Product</th>
                <th>Tag</th>
                <th>Score</th>
                <th>Exclude</th>
                <th>Mentions</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($globalRules as $rule): ?>
                <tr>
                  <td><?= htmlspecialchars($rule['product_name'] ?? $rule['product_code']) ?></td>
                  <td><?= htmlspecialchars($rule['tag_code']) ?></td>
                  <td>
                    <form method="post" class="d-inline">
                      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                      <input type="hidden" name="action" value="update_global_rule">
                      <input type="hidden" name="product_code" value="<?= htmlspecialchars($rule['product_code']) ?>">
                      <input type="hidden" name="tag_code" value="<?= htmlspecialchars($rule['tag_code']) ?>">
                      <input type="number" name="score" value="<?= (int)$rule['score'] ?>" style="width:80px;">
                  </td>
                  <td>
                      <input type="checkbox" name="exclude_if_matched" value="1" <?= ((int)$rule['exclude_if_matched'] === 1) ? 'checked' : '' ?>>
                  </td>
                  <td>
                      <textarea name="mention_detail" placeholder="One reason per line"><?= htmlspecialchars($rule['mention_detail'] ?? '') ?></textarea>
                  </td>
                  <td>
                      <button type="submit" class="btn">Save</button>
                    </form>
                    <form method="post" class="d-inline">
                      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                      <input type="hidden" name="action" value="delete_global_rule">
                      <input type="hidden" name="product_code" value="<?= htmlspecialchars($rule['product_code']) ?>">
                      <input type="hidden" name="tag_code" value="<?= htmlspecialchars($rule['tag_code']) ?>">
                      <button type="submit" class="btn" class="bg-danger">Delete</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <p class="muted" class="mt-12">Base rules are managed from the Global view.</p>
    <?php endif; ?>

    <?php if ($isGlobalView): ?>
      <p class="muted" class="mt-16">Select a store to manage organization-specific overrides.</p>
    <?php else: ?>
      <form method="post" class="mt-12">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="add_rule">
        <input type="hidden" name="org_id" value="<?= (int)$selectedOrg ?>">
        <table>
          <thead>
            <tr>
              <th class="w-20">Product</th>
              <th class="w-20">Tag</th>
              <th class="w-10">Score</th>
              <th class="w-10">Exclude</th>
              <th class="w-30">Mentions</th>
              <th class="w-10">Action</th>
            </tr>
          </thead>
          <tbody>
            <tr>
	              <td>
	                <select name="product_code" required>
	                  <option value="">Select product</option>
	                  <?php foreach ($products as $product): ?>
	                    <?php $pcode = (string)($product['code'] ?? ''); ?>
	                    <option value="<?= htmlspecialchars($pcode) ?>" <?= ($filterProductCode !== '' && $filterProductCode === $pcode) ? 'selected' : '' ?>>
	                      <?= htmlspecialchars((string)($product['name'] ?? $pcode)) ?>
	                    </option>
	                  <?php endforeach; ?>
	                </select>
	              </td>
	              <td>
	                <select name="tag_code" required>
	                  <option value="">Select tag...</option>
	                  <?php foreach ($tagRows as $tag): ?>
	                    <?php $tcode = (string)($tag['tag_code'] ?? ''); ?>
	                    <option value="<?= htmlspecialchars($tcode) ?>" <?= ($filterTagCode !== '' && $filterTagCode === $tcode) ? 'selected' : '' ?>>
	                      <?= htmlspecialchars($tag['tag_code']) . (!empty($tag['description']) ? ' (' . htmlspecialchars($tag['description']) . ')' : '') ?>
	                    </option>
	                  <?php endforeach; ?>
	                </select>
	              </td>
              <td><input type="number" name="score" value="0"></td>
              <td><input type="checkbox" name="exclude_if_matched" value="1"></td>
              <td>
                <textarea name="mention_detail" placeholder="One reason per line"></textarea>
              </td>
              <td><button type="submit" class="btn">Add Override</button></td>
            </tr>
          </tbody>
        </table>
      </form>

      <table>
        <thead>
          <tr>
            <th>Product</th>
            <th>Tag</th>
            <th>Global Score</th>
            <th>Inherited Mentions</th>
            <th>Effective Source</th>
            <th>Override Score</th>
            <th>Override Exclude</th>
            <th>Override Mentions</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($combinedRules as $rule): ?>
            <tr>
              <td><?= htmlspecialchars($rule['product_name'] ?? $rule['product_code']) ?></td>
              <td><?= htmlspecialchars($rule['tag_code']) ?></td>
              <td><?= $rule['base_score'] === null ? '—' : (int)$rule['base_score'] ?></td>
              <td><?= htmlspecialchars($rule['inherited_mention'] ?? '') ?></td>
              <td><?= htmlspecialchars($rule['source']) ?></td>
              <td>
                <form method="post" class="d-inline">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                  <input type="hidden" name="action" value="add_rule">
                  <input type="hidden" name="org_id" value="<?= (int)$selectedOrg ?>">
                  <input type="hidden" name="product_code" value="<?= htmlspecialchars($rule['product_code']) ?>">
                  <input type="hidden" name="tag_code" value="<?= htmlspecialchars($rule['tag_code']) ?>">
                  <input type="number" name="score" value="<?= (int)$rule['effective_score'] ?>" style="width:80px;">
              </td>
              <td>
                  <input type="checkbox" name="exclude_if_matched" value="1" <?= ((int)$rule['effective_exclude'] === 1) ? 'checked' : '' ?>>
              </td>
              <td>
                  <textarea name="mention_detail" placeholder="Leave blank to inherit"><?= htmlspecialchars($rule['org_mention'] ?? '') ?></textarea>
              </td>
              <td>
                  <button type="submit" class="btn">Save Override</button>
                </form>
                <form method="post" class="d-inline">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                  <input type="hidden" name="action" value="delete_rule">
                  <input type="hidden" name="org_id" value="<?= (int)$selectedOrg ?>">
                  <input type="hidden" name="product_code" value="<?= htmlspecialchars($rule['product_code']) ?>">
                  <input type="hidden" name="tag_code" value="<?= htmlspecialchars($rule['tag_code']) ?>">
                  <button type="submit" class="btn" class="bg-danger">Clear Override</button>
                </form>
                <div class="muted" style="margin-top:4px;">
                  <?= $rule['org_score'] === null ? 'Inherited' : 'Overridden' ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</body>
</html>
