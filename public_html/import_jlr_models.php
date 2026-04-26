<?php
include 'db.php';

$sqlFile = 'add_jlr_models_2020_2026.sql';

if (!file_exists($sqlFile)) {
    die("Error: SQL file $sqlFile not found.\n");
}

$sql = file_get_contents($sqlFile);

try {
    $db->exec($sql);
    echo "✅ Jaguar and Land Rover models (2020-2026) imported successfully.\n";
} catch (PDOException $e) {
    echo "❌ Error importing models: " . $e->getMessage() . "\n";
}
?>
