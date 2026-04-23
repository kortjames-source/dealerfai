<?php
declare(strict_types=1);

// One-time migration:
// - Reads tag-based scoring models (products + accessories)
// - Translates known tags into direct "signal predicates"
// - Inserts equivalent rows into scoring_action_rules (stacking score deltas; exclude wins)
//
// Usage:
//   php tools/migrate_tag_models_to_action_rules.php
//   php tools/migrate_tag_models_to_action_rules.php --dry-run
//
// Notes:
// - This is best-effort. Any tag without a known predicate mapping is reported for manual handling.
// - Existing action rules are left untouched; origin_hash is used to keep inserts idempotent.

// Avoid including public_html/db.php here because it hard-dies on connection failure and
// registers web-specific error handlers. Use a direct PDO connection for CLI migration.
$cfgPath = __DIR__ . '/../secure/local_config.php';
if (!file_exists($cfgPath)) {
    fwrite(STDERR, "Missing secure/local_config.php\n");
    exit(2);
}
$cfg = require $cfgPath;
$dbCfg = $cfg['db'] ?? [];
$host = (string)($dbCfg['host'] ?? '127.0.0.1');
$name = (string)($dbCfg['name'] ?? '');
$user = (string)($dbCfg['user'] ?? '');
$pass = (string)($dbCfg['pass'] ?? '');
if ($name === '' || $user === '') {
    fwrite(STDERR, "Missing DB credentials in secure/local_config.php\n");
    exit(2);
}
try {
    $db = new PDO("mysql:host=$host;dbname=$name;charset=utf8mb4", $user, $pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    fwrite(STDERR, "DB connection failed: " . $e->getMessage() . "\n");
    exit(2);
}

function column_exists(PDO $db, string $table, string $column): bool
{
    try {
        $stmt = $db->prepare("
            SELECT COUNT(*)
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
        ");
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (PDOException $e) {
        return false;
    }
}

function cli_has_flag(string $flag): bool
{
    global $argv;
    return in_array($flag, $argv, true);
}

function table_exists(PDO $db, string $table): bool
{
    try {
        $stmt = $db->prepare("
            SELECT COUNT(*)
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
        ");
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (PDOException $e) {
        return false;
    }
}

function ensure_origin_hash_support(PDO $db): void
{
    try {
        if (!column_exists($db, 'scoring_action_rules', 'origin_hash')) {
            $db->exec("ALTER TABLE scoring_action_rules ADD COLUMN origin_hash VARCHAR(64) NULL");
        }
    } catch (Throwable $e) {
        // ignore
    }
    try {
        // Unique index name is stable; try add if missing.
        $idx = $db->query("SHOW INDEX FROM scoring_action_rules WHERE Key_name = 'uq_origin_hash'")->fetchAll(PDO::FETCH_ASSOC);
        if (empty($idx)) {
            $db->exec("ALTER TABLE scoring_action_rules ADD UNIQUE KEY uq_origin_hash (origin_hash)");
        }
    } catch (Throwable $e) {
        // ignore
    }
}

function normalize_code(string $s): string
{
    return strtolower(trim($s));
}

/**
 * Returns tag => list of predicates.
 * Each predicate is a single condition; multiple predicates represent OR (create multiple rules).
 */
function build_tag_predicate_map(PDO $db): array
{
    $map = [];

    $add = function (string $tag, string $signalKey, string $op, ?string $v1 = null, ?string $v2 = null) use (&$map) {
        $tag = normalize_code($tag);
        if ($tag === '') return;
        $map[$tag] = $map[$tag] ?? [];
        $map[$tag][] = [
            'signal_key' => $signalKey,
            'operator' => $op,
            'value1' => $v1,
            'value2' => $v2,
        ];
    };

    // 1) DB tag generation rules (usage_data/app columns -> tags)
    if (table_exists($db, 'scoring_tag_generation_rules')) {
        try {
            $rows = $db->query("
                SELECT source, field_key, operator, match_value, tag_code
                FROM scoring_tag_generation_rules
                WHERE is_enabled = 1
            ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $r) {
                $tag = (string)($r['tag_code'] ?? '');
                $src = strtolower(trim((string)($r['source'] ?? 'usage')));
                $field = trim((string)($r['field_key'] ?? ''));
                $op = strtolower(trim((string)($r['operator'] ?? 'equals')));
                $val = (string)($r['match_value'] ?? '');
                if ($tag === '' || $field === '') continue;
                $signal = ($src === 'app' ? 'app.' : 'usage.') . $field;
                if (!in_array($op, ['equals', 'contains', 'in'], true)) {
                    $op = 'equals';
                }
                $add($tag, $signal, $op, $val !== '' ? $val : null, null);
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    // 2) Hardcoded credit-app tag extraction (best-effort equivalents)
    // Ownership
    $add('long_term_ownership', 'usage.ownership_length', 'gte', '5');
    $add('long_term_ownership', 'usage.ownership_length', 'in', '5_6,7_plus');
    $add('medium_term_ownership', 'usage.ownership_length', 'between', '3', '4');
    $add('medium_term_ownership', 'usage.ownership_length', 'in', '3_4');
    $add('short_term_ownership', 'usage.ownership_length', 'lte', '2');
    $add('short_term_ownership', 'usage.ownership_length', 'in', 'less_3');

    // Annual KM
    $add('low_mileage', 'usage.annual_km', 'lt', '15000');
    $add('low_mileage', 'usage.annual_km', 'equals', 'under_15k');
    $add('high_mileage', 'usage.annual_km', 'gte', '20000');
    $add('high_mileage', 'usage.annual_km', 'in', '20k_25k,25k_plus');

    // Driving type
    $add('urban_driving', 'usage.driving_type', 'in', 'city,mix');
    $add('urban_driving', 'usage.overnight_parking', 'equals', 'street');
    $add('highway_driving', 'usage.driving_type', 'in', 'highway,mix');

    // Gravel / roads
    $add('gravel_road_driving', 'usage.gravel_exposure', 'in', 'sometimes,frequently');
    $add('rural_driving', 'usage.gravel_exposure', 'equals', 'frequently');
    $add('poor_roads', 'usage.road_conditions', 'in', 'some,frequent');
    $add('road_hazard_region', 'usage.road_conditions', 'equals', 'frequent');

    // Parking / household
    $add('garage_parked', 'usage.overnight_parking', 'equals', 'garage');
    $add('outdoor_parking', 'usage.overnight_parking', 'in', 'driveway,street');
    $add('condo_or_apartment', 'usage.overnight_parking', 'equals', 'condo');
    $add('condo_or_apartment', 'app.housing', 'contains', 'rent');

    // Regular users
    $add('multiple_drivers', 'usage.regular_users', 'contains', 'multiple_drivers');
    $add('pets_or_kids', 'usage.regular_users', 'in', 'kids,pets');

    // Food/drink and appearance
    $add('food_or_drink_in_vehicle', 'usage.food_drink', 'in', 'sometimes,often');
    $add('price_sensitive', 'usage.appearance_priority', 'equals', 'not_important');
    $add('customer_appearance_focus', 'usage.appearance_priority', 'equals', 'very_important');

    // Protection interest
    $add('has_dependents', 'usage.life_protection', 'equals', 'yes');
    $add('no_life_insurance', 'usage.life_protection', 'equals', 'no');
    $add('no_disability_insurance', 'usage.disability_protection', 'equals', 'yes');
    $add('physically_demanding_job', 'usage.disability_protection', 'equals', 'yes');
    $add('no_disability', 'usage.disability_protection', 'equals', 'no');
    $add('no_critical_illness_coverage', 'usage.critical_illness', 'equals', 'yes');
    $add('family_health_history', 'usage.critical_illness', 'equals', 'yes');
    $add('no_critical_illness', 'usage.critical_illness', 'equals', 'no');
    $add('job_insecurity', 'usage.job_loss', 'equals', 'yes');
    $add('no_job_loss', 'usage.job_loss', 'equals', 'no');

    // 3) Deal-derived tags (append_deal_tags)
    $add('new_vehicle', 'deal.vehicle_condition', 'equals', 'new');
    $add('used_vehicle', 'deal.vehicle_condition', 'equals', 'used');
    $add('loan_term_long', 'deal.term', 'gte', '72');
    $add('high_vehicle_value', 'deal.sale_price', 'gte', '60000');

    // Luxury/premium makes (from build_profile_detail_map)
    $lux = 'land rover,range rover,mercedes,bmw,lexus,audi,porsche,cadillac,infiniti';
    $add('luxury_or_premium_make', 'deal.vehicle_make', 'in', $lux);

    // Dark paint colours
    $add('dark_paint_colour', 'deal.vehicle_colour', 'in', 'black,grey,dark grey,charcoal,blue,dark blue,midnight,red,dark red,green,dark green,brown,purple');

    // EV owner (best-effort)
    $add('ev_owner', 'deal.vehicle_make', 'in', 'tesla,rivian,lucid,polestar,fisker');
    $add('ev_owner', 'deal.vehicle_model', 'in', 'mach-e,lightning,leaf,bolt,ioniq 5,ioniq 6,id.4,e-tron,taycan,ev6,ev9,bz4x,solterra,ariya');
    $add('ev_owner', 'deal.vehicle_model', 'contains', ' ev');
    $add('ev_owner', 'deal.vehicle_model', 'contains', 'electric');

    // High theft models (best-effort: substring match)
    foreach (['range rover', 'sport', 'defender', 'wrangler', 'grand cherokee', 'cr-v', 'civic', 'f-150', 'ram 1500', 'highlander', 'rx 350', 'rx 450'] as $m) {
        $add('vehicle_high_theft_model', 'deal.vehicle_model', 'contains', $m);
    }

    // Region tags (from extract_tags; best-effort)
    $add('road_hazard_region', 'app.province', 'in', 'MB,SK');
    $add('winter_region', 'app.province', 'in', 'MB,SK');
    $add('road_hazard_region', 'deal.province', 'in', 'MB,SK');
    $add('winter_region', 'deal.province', 'in', 'MB,SK');
    $add('road_hazard_region', 'app.postal_code', 'contains', 'P0L');
    $add('road_hazard_region', 'app.postal_code', 'contains', 'P0M');
    $add('winter_region', 'app.postal_code', 'contains', 'P0L');
    $add('winter_region', 'app.postal_code', 'contains', 'P0M');

    $add('vehicle_stolen_risk_area', 'app.province', 'in', 'ON,QC');
    $add('vehicle_stolen_risk_area', 'deal.province', 'in', 'ON,QC');

    // Composite tags that were previously derived from multiple answers.
    $add('no_highway_or_gravel', 'computed.no_highway_or_gravel', 'equals', '1');

    return $map;
}

function org_scope_for_id(PDO $db, int $orgId): array
{
    try {
        $stmt = $db->prepare("SELECT org_kind FROM organizations WHERE id = ?");
        $stmt->execute([$orgId]);
        $kind = (string)($stmt->fetchColumn() ?? '');
        $kind = strtolower(trim($kind));
        if ($kind === 'group') {
            return ['org', (string)$orgId];
        }
        return ['store', (string)$orgId];
    } catch (Throwable $e) {
        return ['store', (string)$orgId];
    }
}

function insert_action_rule(PDO $db, array $row, bool $dryRun, array &$counts): void
{
    $counts['attempted']++;
    if ($dryRun) {
        $counts['dry_run']++;
        return;
    }
    $stmt = $db->prepare("
        INSERT INTO scoring_action_rules
          (is_enabled, scope_type, scope_value, target_type, target_code, action_type, score_delta,
           signal_key, operator, value1, value2, label, notes, origin_hash)
        VALUES
          (:on, :st, :sv, :tt, :tc, :at, :sd,
           :sk, :op, :v1, :v2, :lb, :nt, :oh)
        ON DUPLICATE KEY UPDATE id = id
    ");
    $ok = $stmt->execute([
        ':on' => (int)($row['is_enabled'] ?? 1),
        ':st' => (string)$row['scope_type'],
        ':sv' => (string)$row['scope_value'],
        ':tt' => (string)$row['target_type'],
        ':tc' => (string)$row['target_code'],
        ':at' => (string)$row['action_type'],
        ':sd' => (int)($row['score_delta'] ?? 0),
        ':sk' => (string)$row['signal_key'],
        ':op' => (string)$row['operator'],
        ':v1' => $row['value1'],
        ':v2' => $row['value2'],
        ':lb' => $row['label'],
        ':nt' => $row['notes'],
        ':oh' => (string)$row['origin_hash'],
    ]);
    if ($ok) {
        $counts['inserted']++;
    }
}

function make_origin_hash(array $parts): string
{
    return sha1(json_encode($parts, JSON_UNESCAPED_SLASHES));
}

$dryRun = cli_has_flag('--dry-run');

if (!table_exists($db, 'scoring_action_rules')) {
    fwrite(STDERR, "Missing table scoring_action_rules\n");
    exit(2);
}

ensure_origin_hash_support($db);

$tagMap = build_tag_predicate_map($db);

$counts = [
    'attempted' => 0,
    'inserted' => 0,
    'dry_run' => 0,
    'unmapped_rows' => 0,
];
$unmapped = [];

// Product rules: global
if (table_exists($db, 'product_scoring_tags')) {
    $rows = $db->query("SELECT product_code, tag_code, score, exclude_if_matched FROM product_scoring_tags")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $r) {
        $product = normalize_code((string)($r['product_code'] ?? ''));
        $tag = normalize_code((string)($r['tag_code'] ?? ''));
        if ($product === '' || $tag === '') continue;
        $preds = $tagMap[$tag] ?? [];
        if (empty($preds)) {
            $counts['unmapped_rows']++;
            $unmapped[] = ['table' => 'product_scoring_tags', 'scope' => 'global', 'target' => $product, 'tag' => $tag];
            continue;
        }
        foreach ($preds as $p) {
            $exclude = !empty($r['exclude_if_matched']);
            $score = (int)($r['score'] ?? 0);
            $notes = 'migrated_from=product_scoring_tags';
            $row = [
                'is_enabled' => 1,
                'scope_type' => 'global',
                'scope_value' => '',
                'target_type' => 'product',
                'target_code' => $product,
                'action_type' => $exclude ? 'exclude' : 'score',
                'score_delta' => $exclude ? 0 : $score,
                'signal_key' => (string)$p['signal_key'],
                'operator' => (string)$p['operator'],
                'value1' => $p['value1'],
                'value2' => $p['value2'],
                'label' => $tag,
                'notes' => substr($notes, 0, 255),
            ];
            $row['origin_hash'] = make_origin_hash($row);
            insert_action_rule($db, $row, $dryRun, $counts);
        }
    }
}

// Product rules: org/store scoped
if (table_exists($db, 'organization_scoring_models')) {
    $rows = $db->query("SELECT organization_id, product_code, tag_code, score, exclude_if_matched FROM organization_scoring_models")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $r) {
        $orgId = (int)($r['organization_id'] ?? 0);
        $product = normalize_code((string)($r['product_code'] ?? ''));
        $tag = normalize_code((string)($r['tag_code'] ?? ''));
        if ($orgId <= 0 || $product === '' || $tag === '') continue;
        $preds = $tagMap[$tag] ?? [];
        if (empty($preds)) {
            $counts['unmapped_rows']++;
            $unmapped[] = ['table' => 'organization_scoring_models', 'scope' => (string)$orgId, 'target' => $product, 'tag' => $tag];
            continue;
        }
        [$scopeType, $scopeValue] = org_scope_for_id($db, $orgId);
        foreach ($preds as $p) {
            $exclude = !empty($r['exclude_if_matched']);
            $score = (int)($r['score'] ?? 0);
            $notes = 'migrated_from=organization_scoring_models; org_id=' . $orgId;
            $row = [
                'is_enabled' => 1,
                'scope_type' => $scopeType,
                'scope_value' => $scopeValue,
                'target_type' => 'product',
                'target_code' => $product,
                'action_type' => $exclude ? 'exclude' : 'score',
                'score_delta' => $exclude ? 0 : $score,
                'signal_key' => (string)$p['signal_key'],
                'operator' => (string)$p['operator'],
                'value1' => $p['value1'],
                'value2' => $p['value2'],
                'label' => $tag,
                'notes' => substr($notes, 0, 255),
            ];
            $row['origin_hash'] = make_origin_hash($row);
            insert_action_rule($db, $row, $dryRun, $counts);
        }
    }
}

// Accessories: global
if (table_exists($db, 'accessory_scoring_tags')) {
    $rows = $db->query("SELECT accessory_code, tag_code, score, exclude_if_matched FROM accessory_scoring_tags")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $r) {
        $acc = normalize_code((string)($r['accessory_code'] ?? ''));
        $tag = normalize_code((string)($r['tag_code'] ?? ''));
        if ($acc === '' || $tag === '') continue;
        $preds = $tagMap[$tag] ?? [];
        if (empty($preds)) {
            $counts['unmapped_rows']++;
            $unmapped[] = ['table' => 'accessory_scoring_tags', 'scope' => 'global', 'target' => $acc, 'tag' => $tag];
            continue;
        }
        foreach ($preds as $p) {
            $exclude = !empty($r['exclude_if_matched']);
            $score = (int)($r['score'] ?? 0);
            $row = [
                'is_enabled' => 1,
                'scope_type' => 'global',
                'scope_value' => '',
                'target_type' => 'accessory',
                'target_code' => $acc,
                'action_type' => $exclude ? 'exclude' : 'score',
                'score_delta' => $exclude ? 0 : $score,
                'signal_key' => (string)$p['signal_key'],
                'operator' => (string)$p['operator'],
                'value1' => $p['value1'],
                'value2' => $p['value2'],
                'label' => $tag,
                'notes' => substr('migrated_from=accessory_scoring_tags', 0, 255),
            ];
            $row['origin_hash'] = make_origin_hash($row);
            insert_action_rule($db, $row, $dryRun, $counts);
        }
    }
}

// Accessories: org/store scoped
if (table_exists($db, 'organization_accessory_scoring_models')) {
    $rows = $db->query("SELECT organization_id, accessory_code, tag_code, score, exclude_if_matched FROM organization_accessory_scoring_models")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $r) {
        $orgId = (int)($r['organization_id'] ?? 0);
        $acc = normalize_code((string)($r['accessory_code'] ?? ''));
        $tag = normalize_code((string)($r['tag_code'] ?? ''));
        if ($orgId <= 0 || $acc === '' || $tag === '') continue;
        $preds = $tagMap[$tag] ?? [];
        if (empty($preds)) {
            $counts['unmapped_rows']++;
            $unmapped[] = ['table' => 'organization_accessory_scoring_models', 'scope' => (string)$orgId, 'target' => $acc, 'tag' => $tag];
            continue;
        }
        [$scopeType, $scopeValue] = org_scope_for_id($db, $orgId);
        foreach ($preds as $p) {
            $exclude = !empty($r['exclude_if_matched']);
            $score = (int)($r['score'] ?? 0);
            $row = [
                'is_enabled' => 1,
                'scope_type' => $scopeType,
                'scope_value' => $scopeValue,
                'target_type' => 'accessory',
                'target_code' => $acc,
                'action_type' => $exclude ? 'exclude' : 'score',
                'score_delta' => $exclude ? 0 : $score,
                'signal_key' => (string)$p['signal_key'],
                'operator' => (string)$p['operator'],
                'value1' => $p['value1'],
                'value2' => $p['value2'],
                'label' => $tag,
                'notes' => substr('migrated_from=organization_accessory_scoring_models; org_id=' . $orgId, 0, 255),
            ];
            $row['origin_hash'] = make_origin_hash($row);
            insert_action_rule($db, $row, $dryRun, $counts);
        }
    }
}

// Report
echo "dry_run=" . ($dryRun ? 'yes' : 'no') . PHP_EOL;
echo "attempted_inserts={$counts['attempted']}" . PHP_EOL;
echo "inserted={$counts['inserted']}" . PHP_EOL;
echo "unmapped_rows={$counts['unmapped_rows']}" . PHP_EOL;

if (!empty($unmapped)) {
    $outPath = __DIR__ . '/../tools/migrate_tag_models_to_action_rules_unmapped.json';
    file_put_contents($outPath, json_encode($unmapped, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo "unmapped_written=" . $outPath . PHP_EOL;
}
