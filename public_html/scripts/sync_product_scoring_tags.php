<?php
require_once __DIR__ . '/../db.php';

$selectProducts = $db->query("SELECT code, scoring_tags FROM products WHERE scoring_tags IS NOT NULL AND TRIM(scoring_tags) <> ''");
$products = $selectProducts->fetchAll(PDO::FETCH_ASSOC);

$existsStmt = $db->prepare("SELECT 1 FROM product_scoring_tags WHERE product_code = ? AND tag_code = ? LIMIT 1");
$insertStmt = $db->prepare("INSERT INTO product_scoring_tags (product_code, tag_code, score, exclude_if_matched) VALUES (?, ?, 0, 0)");

$inserted = 0;
$skipped = 0;

foreach ($products as $product) {
    $code = trim((string)($product['code'] ?? ''));
    if ($code === '') {
        continue;
    }
    $tagsRaw = (string)($product['scoring_tags'] ?? '');
    $tags = array_filter(array_map('trim', explode(',', $tagsRaw)));
    foreach ($tags as $tag) {
        $existsStmt->execute([$code, $tag]);
        if ($existsStmt->fetchColumn()) {
            $skipped++;
            continue;
        }
        $insertStmt->execute([$code, $tag]);
        $inserted++;
    }
}

echo "Inserted {$inserted} missing product_scoring_tags rows. Skipped {$skipped} existing rows.\n";
