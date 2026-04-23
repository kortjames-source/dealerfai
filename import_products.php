#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/public_html/db.php';

function usage(): void
{
    fwrite(STDERR, "Usage: php import_products.php /path/to/products.csv\n");
    exit(1);
}

$csvPath = $argv[1] ?? null;
if (!$csvPath) {
    usage();
}

if (!is_file($csvPath) || !is_readable($csvPath)) {
    fwrite(STDERR, "Cannot read CSV at '$csvPath'.\n");
    exit(1);
}

$handle = fopen($csvPath, 'rb');
if ($handle === false) {
    fwrite(STDERR, "Failed to open '$csvPath'.\n");
    exit(1);
}

$headers = fgetcsv($handle);
if ($headers === false) {
    fwrite(STDERR, "CSV appears empty.\n");
    exit(1);
}

 $headers = array_map(fn($h) => strtolower(trim($h)), $headers);
 $idIndexes = [];
 foreach ($headers as $index => $header) {
     if ($header === 'id') {
         $idIndexes[] = $index;
     }
 }
 if (!empty($idIndexes)) {
     foreach ($idIndexes as $index) {
         unset($headers[$index]);
     }
     $headers = array_values($headers);
 }

$insert = $db->prepare(
    <<<SQL
INSERT INTO products (
    code, name, category, provider, default_description, default_reason,
    default_price, default_cost, default_term, requires_quote, requires_selection,
    option_set_id, allowed_deal_types, scoring_tags, default_term_range,
    eligible_verticals, product_image_url, product_video_url, is_active
) VALUES (
    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1
) ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    category = VALUES(category),
    provider = VALUES(provider),
    default_description = VALUES(default_description),
    default_reason = VALUES(default_reason),
    default_price = VALUES(default_price),
    default_cost = VALUES(default_cost),
    default_term = VALUES(default_term),
    requires_quote = VALUES(requires_quote),
    requires_selection = VALUES(requires_selection),
    option_set_id = VALUES(option_set_id),
    allowed_deal_types = VALUES(allowed_deal_types),
    scoring_tags = VALUES(scoring_tags),
    default_term_range = VALUES(default_term_range),
    eligible_verticals = VALUES(eligible_verticals),
    product_image_url = VALUES(product_image_url),
    product_video_url = VALUES(product_video_url),
    is_active = VALUES(is_active)
SQL
);

$total = 0;
$updated = 0;
$skipped = 0;
$errors = 0;

while (($row = fgetcsv($handle)) !== false) {
    $total++;
    $rowCount = count($row);
    $headerCount = count($headers);
    if (!empty($idIndexes)) {
        foreach ($idIndexes as $index) {
            if (array_key_exists($index, $row)) {
                unset($row[$index]);
            }
        }
        $row = array_values($row);
    }
    $rowCount = count($row);
    if ($rowCount < $headerCount) {
        while (count($row) < $headerCount) {
            $row[] = null;
        }
    } elseif ($rowCount > $headerCount) {
        $row = array_slice($row, 0, $headerCount);
    }

    $record = array_combine($headers, $row) ?: [];
    if (isset($record['id'])) {
        unset($record['id']);
    }
    $code = trim($record['code'] ?? '');
    $name = trim($record['name'] ?? '');
    if ($code === '' || $name === '') {
        $skipped++;
        continue;
    }

    $category = trim($record['category'] ?? 'protection');
    $provider = trim($record['provider'] ?? '');
    $provider = $provider === '' ? '' : $provider;
    $defaultDescription = trim($record['default_description'] ?? '');
    $defaultReason = trim($record['default_reason'] ?? '');
    $allowed = trim($record['allowed_deal_types'] ?? 'cash,finance,lease');

    $defaultPrice = is_numeric($record['default_price'] ?? null) ? (float)$record['default_price'] : 0.0;
    $defaultCost = is_numeric($record['default_cost'] ?? null) ? (float)$record['default_cost'] : 0.0;
    $defaultTerm = is_numeric($record['default_term'] ?? null) ? (int)$record['default_term'] : null;
    $defaultTermRange = trim($record['default_term_range'] ?? '');
    $eligibleVerticals = trim($record['eligible_verticals'] ?? '');
    $providerUrl = trim($record['product_image_url'] ?? '');
    $videoUrl = trim($record['product_video_url'] ?? '');

    $requiresQuote = (int) filter_var($record['requires_quote'] ?? '0', FILTER_VALIDATE_BOOLEAN);
    $requiresSelection = (int) filter_var($record['requires_selection'] ?? '0', FILTER_VALIDATE_BOOLEAN);
    $optionSetId = is_numeric($record['option_set_id'] ?? null) ? (int)$record['option_set_id'] : null;
    $scoringTags = trim($record['scoring_tags'] ?? '');

    try {
        $insert->execute([
            $code,
            $name,
            $category,
            $provider,
            $defaultDescription ?: null,
            $defaultReason ?: null,
            $defaultPrice,
            $defaultCost,
            $defaultTerm,
            $requiresQuote,
            $requiresSelection,
            $optionSetId,
            $allowed ?: 'cash,finance,lease',
            $scoringTags ?: null,
            $defaultTermRange ?: null,
            $eligibleVerticals ?: null,
            $providerUrl ?: null,
            $videoUrl ?: null,
        ]);
        $updated++;
    } catch (PDOException $e) {
        fwrite(STDERR, "Failed to save '$code': " . $e->getMessage() . "\n");
        $errors++;
    }
}

fclose($handle);

fwrite(STDOUT, "Processed $total rows: $updated upserted, $skipped skipped, $errors errors.\n");

fwrite(STDOUT, "Run `mysqldump --no-create-info --tables products > refreshed_products.sql` and replace the INSERT block in dealerfai.sql if you want the SQL dump to match the new catalog.\n");
