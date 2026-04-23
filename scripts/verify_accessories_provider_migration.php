<?php
declare(strict_types=1);

// Verifies that the accessories provider schema changes are present.

$cfgPath = __DIR__ . '/../secure/local_config.php';
if (!file_exists($cfgPath)) {
    fwrite(STDERR, "missing secure/local_config.php\n");
    exit(2);
}
$cfg = require $cfgPath;
$dbCfg = $cfg['db'] ?? [];

$dbName = (string)($dbCfg['name'] ?? '');
$user = (string)($dbCfg['user'] ?? '');
$pass = (string)($dbCfg['pass'] ?? '');

try {
    $pdo = new PDO(
        'mysql:unix_socket=/tmp/mysql.sock;dbname=' . $dbName . ';charset=utf8mb4',
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Throwable $e) {
    fwrite(STDERR, "db connect failed\n");
    exit(3);
}

$hasProvider = (int)$pdo->query(
    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'accessories'
       AND COLUMN_NAME = 'provider'"
)->fetchColumn();

echo "provider column: " . ($hasProvider ? "yes" : "no") . "\n";

$idxRows = $pdo->query(
    "SELECT INDEX_NAME, SEQ_IN_INDEX, COLUMN_NAME
     FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'accessories'
       AND INDEX_NAME IN ('uniq_accessory_scope_provider','uniq_accessory_scope')
     ORDER BY INDEX_NAME, SEQ_IN_INDEX"
)->fetchAll(PDO::FETCH_ASSOC);

foreach ($idxRows as $r) {
    echo $r['INDEX_NAME'] . ' ' . $r['SEQ_IN_INDEX'] . ' ' . $r['COLUMN_NAME'] . "\n";
}
