<?php
declare(strict_types=1);

/**
 * Automated Deployment Script for DealerFAI
 * Triggered by GitHub Webhook or manual browser visit
 */

// --- CONFIGURATION ---
$secret_key = 'dealer_deploy_2024';
$branch = 'main';
$log_dir = __DIR__ . '/../secure/logs';
if (!is_dir($log_dir)) {
    @mkdir($log_dir, 0750, true);
}
$log_file = $log_dir . '/deploy.log';

// 1. Authentication
if (!isset($_GET['key']) || $_GET['key'] !== $secret_key) {
    header('HTTP/1.0 403 Forbidden');
    echo 'Access Denied';
    exit;
}

header('Content-Type: text/plain');
$log = "=== DealerFAI Deployment: " . date('Y-m-d H:i:s') . " ===\n";

$candidateDirs = [
    '/home/qacgw532/public_html',
    '/home/qacgw532/dealerfai',
    dirname(__DIR__),
    __DIR__,
];

$foundAny = false;

foreach ($candidateDirs as $dir) {
    if (is_dir($dir . '/.git')) {
        $foundAny = true;
        $log .= "--- Target directory: $dir ---\n";
        chdir($dir);
        
        $log .= "[git status before pull]\n";
        $log .= shell_exec("git status 2>&1") . "\n";
        
        $log .= "[git pull origin $branch]\n";
        $log .= shell_exec("git pull origin $branch 2>&1") . "\n";
        
        $log .= "[git status after pull]\n";
        $log .= shell_exec("git status 2>&1") . "\n";
        
        // If repo is in /home/qacgw532/dealerfai, sync public_html to /home/qacgw532/public_html
        if ($dir === '/home/qacgw532/dealerfai' && is_dir('/home/qacgw532/public_html')) {
            $log .= "[sync dealerfai/public_html to /home/qacgw532/public_html]\n";
            $syncOut = shell_exec("cp -ru /home/qacgw532/dealerfai/public_html/* /home/qacgw532/public_html/ 2>&1; cp -f /home/qacgw532/dealerfai/public_html/.htaccess /home/qacgw532/public_html/.htaccess 2>&1");
            $log .= ($syncOut ? $syncOut : "Files synced successfully.\n");
        }
        
        // If repo is in /home/qacgw532/public_html and contains nested public_html
        if ($dir === '/home/qacgw532/public_html' && is_dir('/home/qacgw532/public_html/public_html')) {
            $log .= "[sync nested public_html files to web root]\n";
            $syncOut = shell_exec("cp -ru /home/qacgw532/public_html/public_html/* /home/qacgw532/public_html/ 2>&1; cp -f /home/qacgw532/public_html/public_html/.htaccess /home/qacgw532/public_html/.htaccess 2>&1");
            $log .= ($syncOut ? $syncOut : "Nested files synced successfully.\n");
        }
    }
}

if (!$foundAny) {
    $log .= "No .git repo found in candidate directories:\n";
    foreach ($candidateDirs as $d) {
        $exists = is_dir($d) ? 'YES' : 'NO';
        $gitExists = is_dir($d . '/.git') ? 'YES' : 'NO';
        $log .= " - $d (dir exists: $exists, .git exists: $gitExists)\n";
    }
}

$log .= "=== Deployment Complete ===\n\n";

@file_put_contents($log_file, $log, FILE_APPEND);
echo $log;
