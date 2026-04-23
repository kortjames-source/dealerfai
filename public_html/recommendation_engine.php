<?php

declare(strict_types=1);

include_once 'protection_helpers.php';
require_once __DIR__ . '/helpers/usage_data.php';

function generate_recommendations(PDO $db, int $deal_id): void
{
    $stmt = $db->prepare("SELECT * FROM deals WHERE id = ?");
    $stmt->execute([$deal_id]);
    $deal = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$deal) {
        return;
    }

    $appStmt = $db->prepare("SELECT usage_data, vehicle_make AS app_make, vehicle_model AS app_model FROM applications WHERE deal_id = ?");
    $appStmt->execute([$deal_id]);
    $appRow = $appStmt->fetch(PDO::FETCH_ASSOC);
    if (!$appRow) {
        return;
    }

    $usage = dealerfai_decode_usage_data((string)($appRow['usage_data'] ?? ''));
    if (empty($usage)) {
        return;
    }

    $recommendations = [];
    $scoringLog = [];

    $vehicleModel = $deal['vehicle_model'] ?: ($appRow['app_model'] ?? '');
    $vehicleMake = '';

    if (!empty($deal['vehicle_make_id'])) {
        $makeStmt = $db->prepare("SELECT make_name FROM vehicle_makes WHERE id = ?");
        $makeStmt->execute([$deal['vehicle_make_id']]);
        $vehicleMake = $makeStmt->fetchColumn() ?: '';

    }

    $sale_price = (float)($deal['sale_price'] ?? 0);
    $term = (int)($deal['term'] ?? 0);
    $type = (string)($deal['deal_type'] ?? '');
    $trade_value = (float)($deal['trade_value'] ?? 0);
    $lien = (float)($deal['lien_amount'] ?? 0);
    $down = (float)($deal['down_payment'] ?? 0);
    $loan = max(0.0, $sale_price - $down);
    $province = strtoupper((string)($deal['province'] ?? ''));
    $postal = strtoupper(preg_replace('/\s+/', '', (string)($deal['customer_postal'] ?? '')));
    $isNorthernON = ($province === 'ON' && preg_match('/^P0[L-M]/', $postal));

    $ownership = strtolower(trim((string)($usage['ownership_length'] ?? '')));
    $drivingType = strtolower(trim((string)($usage['driving_type'] ?? '')));
    $gravelExposure = strtolower(trim((string)($usage['gravel_exposure'] ?? '')));
    $roadConditions = strtolower(trim((string)($usage['road_conditions'] ?? '')));
    $parking = strtolower(trim((string)($usage['overnight_parking'] ?? '')));

    $longTerm = in_array($ownership, ['5_6', '7_plus'], true);

    // Extended Warranty
    $score = ($ownership !== 'less_3' && $ownership !== '') ? 1 : 0;
    if ($score > 0) {
        $recommendations[] = [
            'product_code' => 'warranty',
            'product_name' => 'Extended Warranty',
            'sale_price' => 0.00,
            'description' => 'Most manufacturer warranties expire after 3-4 years or 80,000 km. Based on your ownership period, extended warranty coverage is recommended.',
            'match_score' => $score,
        ];
    }
    $scoringLog[] = ['product_code' => 'warranty', 'match_score' => $score];

    // GAP and CAP
    $recommend_gap = false;
    $recommend_cap = false;
    if ($type === 'Finance') {
        if ($loan < 120000 && $lien > $trade_value && $down == 0) {
            $recommend_gap = true;
        } elseif ($type !== 'Cash' && $sale_price <= 120000 && $term > 24) {
            $recommend_cap = true;
        }
    } elseif ($type === 'Lease' && $sale_price <= 120000 && $term > 24) {
        $recommend_cap = true;
    }

    if ($recommend_gap) {
        $price = $loan < 40000 ? 3000 : ($loan <= 80000 ? 3500 : 4000);
        $recommendations[] = [
            'product_code' => 'gap',
            'product_name' => 'GAP Insurance',
            'sale_price' => $price,
            'description' => 'Protects you if your vehicle is written off and insurance does not cover the full loan amount.',
            'match_score' => 2,
        ];
        $scoringLog[] = ['product_code' => 'gap', 'match_score' => 2];
    } elseif ($recommend_cap) {
        $coverageTerm = function_exists('normalize_cap_coverage_term')
            ? normalize_cap_coverage_term((float)$sale_price, (int)$term)
            : min($term, 60);
        $cost = getCapCost($sale_price, $coverageTerm);
        if ($cost !== null) {
            $price = max($cost * 2, $cost + 1500);
            $recommendations[] = [
                'product_code' => 'cap',
                'product_name' => "Companion Asset Protection (CAP) - {$coverageTerm}-Month Coverage",
                'sale_price' => $price,
                'description' => 'Includes GAP or Replacement Benefit (whichever is higher), Replacement Vehicle Bonus, deductible coverage, and more.',
                'match_score' => 2,
            ];
            $scoringLog[] = ['product_code' => 'cap', 'match_score' => 2];
        }
    } else {
        $scoringLog[] = ['product_code' => 'gap', 'match_score' => 0];
        $scoringLog[] = ['product_code' => 'cap', 'match_score' => 0];
    }

    // Tire & Rim
    $score = 0;
    if (in_array($roadConditions, ['some', 'frequent'], true)) {
        $score++;
    }
    if (in_array($drivingType, ['highway', 'mix'], true)) {
        $score++;
    }
    if (in_array($gravelExposure, ['sometimes', 'frequently'], true)) {
        $score++;
    }
    if (in_array((string)($usage['annual_km'] ?? ''), ['15k_20k', '20k_25k', '25k_plus'], true)) {
        $score++;
    }
    if (in_array($province, ['MB', 'SK'], true) || $isNorthernON) {
        $score++;
    }
    $scoringLog[] = ['product_code' => 'tire_rim', 'match_score' => $score];

    if ($score > 0) {
        $price = 0.0;
        try {
            $priceStmt = $db->prepare("
                SELECT COALESCE(default_price, 0)
                FROM products
                WHERE LOWER(REPLACE(code, '_', '')) = 'tirerim'
                  AND is_active = 1
                ORDER BY id ASC
                LIMIT 1
            ");
            $priceStmt->execute();
            $basePrice = $priceStmt->fetchColumn();
            if ($basePrice !== false && $basePrice !== null && $basePrice !== '') {
                $price = (float)$basePrice;
            }
        } catch (Throwable $e) {
            // Keep legacy engine resilient; default to 0 if product lookup fails.
            $price = 0.0;
        }
        $recommendations[] = [
            'product_code' => 'tire_rim',
            'product_name' => 'Tire & Rim Protection',
            'sale_price' => $price,
            'description' => 'Covers damage from potholes and debris. Based on CAA Manitoba, average repair is $962.',
            'match_score' => $score,
        ];
    }

    // Ceramic / Paint Shield
    $score = 0;
    if (in_array($parking, ['driveway', 'street', 'condo'], true)) {
        $score++;
    }
    if ($longTerm) {
        $score++;
    }
    $scoringLog[] = ['product_code' => 'ceramic', 'match_score' => $score];

    if ($score > 0) {
        $recommendations[] = [
            'product_code' => ($sale_price > 50000 ? 'ceramic' : 'paint_shield'),
            'product_name' => ($sale_price > 50000 ? 'Ceramic Coating' : 'Paint Shield'),
            'sale_price' => ($sale_price > 50000 ? 1195 : 930),
            'description' => 'Protects paint from fading, oxidation, and damage. Ideal for outdoor vehicles.',
            'match_score' => $score,
        ];
    }

    // XPEL
    $score = 0;
    if (in_array($drivingType, ['highway', 'mix'], true)) {
        $score++;
    }
    if (in_array($gravelExposure, ['sometimes', 'frequently'], true)) {
        $score++;
    }
    if ($longTerm) {
        $score++;
    }
    $scoringLog[] = ['product_code' => 'xpel', 'match_score' => $score];

    if ($score > 0) {
        $recommendations[] = [
            'product_code' => 'xpel',
            'product_name' => 'XPEL Paint Protection Film',
            'sale_price' => 0,
            'description' => 'Protects against rock chips and road debris. 10-year warranty. Select your coverage level.',
            'match_score' => $score,
        ];
    }

    // Interior Protection
    $score = ($ownership === '' || $ownership === 'less_3') ? 0 : 1;
    $scoringLog[] = ['product_code' => 'interior', 'match_score' => $score];
    if ($score > 0) {
        $recommendations[] = [
            'product_code' => 'interior',
            'product_name' => 'Interior Protection',
            'sale_price' => 710,
            'description' => 'Covers rips, spills and damage inside your vehicle. Best for long-term use.',
            'match_score' => $score,
        ];
    }

    usort($recommendations, static fn($a, $b) => $b['match_score'] <=> $a['match_score']);
    $db->prepare("DELETE FROM product_recommendations WHERE deal_id = ?")->execute([$deal_id]);
    $insert = $db->prepare("INSERT INTO product_recommendations (deal_id, product_code, product_name, sale_price, description) VALUES (?, ?, ?, ?, ?)");
    foreach ($recommendations as $r) {
        $insert->execute([$deal_id, $r['product_code'], $r['product_name'], $r['sale_price'], $r['description']]);
    }

    $logStmt = $db->prepare("INSERT INTO recommendation_scoring_log (deal_id, product_code, score, created_at) VALUES (?, ?, ?, NOW())");
    foreach ($scoringLog as $log) {
        $logStmt->execute([$deal_id, $log['product_code'], $log['match_score']]);
    }
}
