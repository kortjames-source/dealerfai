<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

echo "<h1>DealerFAI Model Population & Make Consolidation</h1>";

try {
    // 1. Run Make Consolidation
    echo "<h3>Step 1: Consolidating Duplicate Makes...</h3>";
    $mergeSqlPath = __DIR__ . '/merge_duplicate_makes.sql';
    if (!file_exists($mergeSqlPath)) {
        throw new Exception("Migration file not found: merge_duplicate_makes.sql");
    }
    
    $mergeSql = file_get_contents($mergeSqlPath);
    // Split by semicolon for multiple statements (simplistic, but works for our script)
    $statements = array_filter(array_map('trim', explode(';', $mergeSql)));
    
    $mergeCount = 0;
    foreach ($statements as $stmt) {
        if (empty($stmt)) continue;
        $db->exec($stmt);
        $mergeCount++;
    }
    echo "<p style='color:green;'>✅ Successfully executed make consolidation ($mergeCount statements).</p>";

    // 2. Run Model Population
    echo "<h3>Step 2: Importing New Vehicle Models...</h3>";
    $modelsSqlPath = __DIR__ . '/models_update_2020_2026.sql';
    if (!file_exists($modelsSqlPath)) {
        throw new Exception("Population file not found: models_update_2020_2026.sql");
    }
    
    // For the larger file, we read and execute in segments to avoid memory issues
    $handle = fopen($modelsSqlPath, 'r');
    if (!$handle) {
        throw new Exception("Could not open models population file.");
    }

    $buffer = '';
    $importCount = 0;
    while (($line = fgets($handle)) !== false) {
        $buffer .= $line;
        if (strpos(trim($line), ';') !== false && substr(trim($line), -1) === ';') {
            $db->exec($buffer);
            $buffer = '';
            $importCount++;
        }
    }
    fclose($handle);
    
    echo "<p style='color:green;'>✅ Successfully imported model population script ($importCount bulk inserts).</p>";
    echo "<hr>";
    echo "<h2>Import Complete!</h2>";
    echo "<p>You can now delete the following utility files from your server for security:</p>";
    echo "<ul>
            <li>import_models.php</li>
            <li>merge_duplicate_makes.sql</li>
            <li>models_update_2020_2026.sql</li>
          </ul>";

} catch (Throwable $e) {
    echo "<p style='color:red;'>❌ ERROR: " . htmlspecialchars($e->getMessage()) . "</p>";
}
