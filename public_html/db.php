<?php
// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't show on screen
ini_set('log_errors', 1);
$logDir = __DIR__ . '/../secure/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0750, true);
}
ini_set('error_log', $logDir . '/php-error.log'); // Log file path

require_once __DIR__ . '/includes/error_handler.php';

$localConfigPath = __DIR__ . '/../secure/local_config.php';
$localConfig = [];
if (file_exists($localConfigPath)) {
    $loaded = require $localConfigPath;
    if (is_array($loaded)) {
        $localConfig = $loaded;
    }
}

$dbConfig = $localConfig['db'] ?? [];
$host = $dbConfig['host'] ?? ($_ENV['DB_HOST'] ?? getenv('DB_HOST') ?? '127.0.0.1');
$port = $dbConfig['port'] ?? ($_ENV['DB_PORT'] ?? getenv('DB_PORT') ?? null);
$socket = $dbConfig['socket'] ?? ($_ENV['DB_SOCKET'] ?? getenv('DB_SOCKET') ?? null);
$dbname = $dbConfig['name'] ?? ($_ENV['DB_NAME'] ?? getenv('DB_NAME') ?? '');
$username = $dbConfig['user'] ?? ($_ENV['DB_USER'] ?? getenv('DB_USER') ?? '');
$password = $dbConfig['pass'] ?? ($_ENV['DB_PASS'] ?? getenv('DB_PASS') ?? '');

try {
    if (is_string($socket) && $socket !== '') {
        $dsn = "mysql:unix_socket=$socket;dbname=$dbname;charset=utf8mb4";
    } else {
        $dsn = "mysql:host=$host;dbname=$dbname;charset=utf8mb4";
        if ($port !== null && $port !== '') {
            $dsn .= ";port=$port";
        }
    }

    $db = new PDO($dsn, $username, $password);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    dealerfai_register_error_handlers($db);
} catch (PDOException $e) {
    error_log("❌ DB Connection failed: " . $e->getMessage());
    die('Database error. Please try again later.');
}
?>
