<?php

declare(strict_types=1);

require_once __DIR__ . '/../public_html/helpers/usage_data.php';

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
    $pdo = new PDO("mysql:host=$host;dbname=$name;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    fwrite(STDERR, "DB connection failed: " . $e->getMessage() . "\n");
    exit(2);
}

$rows = $pdo->query("SELECT id, usage_data FROM applications")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$update = $pdo->prepare("UPDATE applications SET usage_data = ? WHERE id = ?");

$updated = 0;
$unchanged = 0;
$invalid = 0;

foreach ($rows as $row) {
    $id = (int)($row['id'] ?? 0);
    $raw = (string)($row['usage_data'] ?? '');
    if (trim($raw) === '') {
        $invalid++;
        continue;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        $invalid++;
        continue;
    }

    $normalized = dealerfai_normalize_usage_data($decoded);
    ksort($decoded);
    ksort($normalized);

    $origJson = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $newJson = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($newJson)) {
        $invalid++;
        continue;
    }

    if ($origJson === $newJson) {
        $unchanged++;
        continue;
    }

    $update->execute([$newJson, $id]);
    $updated++;
}

echo "applications_total=" . count($rows) . PHP_EOL;
echo "updated={$updated}" . PHP_EOL;
echo "unchanged={$unchanged}" . PHP_EOL;
echo "invalid={$invalid}" . PHP_EOL;
