<?php
$config = [];
$localConfigPath = __DIR__ . '/local_config.php';
if (file_exists($localConfigPath)) {
    $localConfig = require $localConfigPath;
    if (is_array($localConfig)) {
        $config = $localConfig;
    }
}

// AES key resolution (local config -> env -> aes_key.txt)
$aes_key = $config['aes_key'] ?? ($_ENV['AES_KEY'] ?? getenv('AES_KEY'));
$keyFile = __DIR__ . '/aes_key.txt';

if (empty($aes_key) && file_exists($keyFile)) {
    $aes_key = trim(file_get_contents($keyFile));
}

if (empty($aes_key)) {
    die('AES key not configured. Set AES_KEY env var, secure/local_config.php, or secure/aes_key.txt.');
}

if (!defined('AES_KEY')) {
    define('AES_KEY', $aes_key);
}

return $config;
