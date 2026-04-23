<?php
// Adds the password_updated_at timestamp column so force_password_reset() can set it.
require_once __DIR__ . '/../db.php';

try {
    $db->exec('ALTER TABLE users ADD COLUMN password_updated_at DATETIME NULL AFTER password');
    echo "Column password_updated_at added to users table.\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        echo "Column password_updated_at already exists; no changes made.\n";
    } else {
        http_response_code(500);
        die('Failed to add column: ' . $e->getMessage());
    }
}
