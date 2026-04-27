<?php
include 'db.php';
try {
    $db->exec("ALTER TABLE applications ADD COLUMN intro_text TEXT AFTER usage_data");
    echo "Success: Column 'intro_text' added to applications.\n";
} catch (Exception $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        echo "Info: Column 'intro_text' already exists.\n";
    } else {
        echo "Error: " . $e->getMessage() . "\n";
    }
}

try {
    $db->exec("ALTER TABLE protection_audit_log ADD COLUMN ai_narrative TEXT AFTER change_meta");
    echo "Success: Column 'ai_narrative' added to protection_audit_log.\n";
} catch (Exception $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        echo "Info: Column 'ai_narrative' already exists.\n";
    } else {
        echo "Error: " . $e->getMessage() . "\n";
    }
}
?>
