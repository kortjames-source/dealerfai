<?php
require_once __DIR__ . '/public_html/db.php';

try {
    // Check if primary_color exists
    $stmt = $db->query("SHOW COLUMNS FROM organizations LIKE 'primary_color'");
    $exists = $stmt->fetch();

    if (!$exists) {
        $db->exec("ALTER TABLE organizations ADD COLUMN primary_color VARCHAR(20) DEFAULT NULL AFTER logo_url");
        echo "✅ Added 'primary_color' column to organizations table.\n";
    } else {
        echo "ℹ️ 'primary_color' column already exists.\n";
    }
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
