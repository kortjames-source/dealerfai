<?php
/**
 * Automated Deployment Script for DealerFAI
 * Triggered by GitHub Webhook
 */

// --- CONFIGURATION ---
$secret_key = 'dealer_deploy_2024'; // You should change this to something unique!
$repo_dir = '/home/qacgw532/dealerfai'; // Path confirmed by user
$branch = 'main';
$log_file = '../secure/logs/deploy.log'; // Path relative to this file
// ---------------------

// 1. Authentication
if (!isset($_GET['key']) || $_GET['key'] !== $secret_key) {
    header('HTTP/1.0 403 Forbidden');
    echo 'Access Denied';
    exit;
}

// 2. Deployment Logic
echo "Starting deployment...\n";

// Change to the project directory
chdir($repo_dir);

// Run git pull
// Note: The server's PHP user must have permissions to run git and access the SSH keys
$output = shell_exec("git pull origin $branch 2>&1");

// 3. Logging
$timestamp = date('Y-m-d H:i:s');
$log_entry = "[$timestamp] Branch: $branch\n$output\n" . str_repeat('-', 40) . "\n";
file_put_contents($log_file, $log_entry, FILE_APPEND);

// 4. Output
echo "Deployment Complete:\n";
echo $output;
