<?php
declare(strict_types=1);

require_once __DIR__ . '/db_utils.php';

function table_exists(PDO $db, string $table): bool
{
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?"
    );
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() > 0;
}

function default_billing_rates_for_plan(string $plan): array
{
    $plan = strtolower(trim($plan));
    $rates = [
        'plan_code' => $plan ?: 'core',
        'deal_rate' => 30.0,
        'accessory_rate' => 0.0,
        'service_rate' => 0.0,
    ];
    if ($plan === 'plus') {
        $rates['accessory_rate'] = 5.0;
    } elseif ($plan === 'premium') {
        $rates['accessory_rate'] = 5.0;
        $rates['service_rate'] = 4.0;
    }
    return $rates;
}

function get_plan_rates(PDO $db, string $planCode): array
{
    if (!table_exists($db, 'billing_plans')) {
        return default_billing_rates_for_plan($planCode);
    }
    try {
        $stmt = $db->prepare("SELECT code, deal_rate, accessory_rate, service_rate FROM billing_plans WHERE code = ? LIMIT 1");
        $stmt->execute([$planCode]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return default_billing_rates_for_plan($planCode);
        }
        return [
            'plan_code' => $row['code'] ?? $planCode,
            'deal_rate' => (float) ($row['deal_rate'] ?? 0),
            'accessory_rate' => (float) ($row['accessory_rate'] ?? 0),
            'service_rate' => (float) ($row['service_rate'] ?? 0),
        ];
    } catch (PDOException $e) {
        return default_billing_rates_for_plan($planCode);
    }
}

function resolve_org_billing_rates(PDO $db, int $organizationId): array
{
    $planCode = 'core';
    $overrides = [
        'deal_rate' => null,
        'accessory_rate' => null,
        'service_rate' => null,
    ];
    if ($organizationId > 0) {
        $columns = ['plan_tier', 'deal_rate_override', 'accessory_rate_override', 'service_rate_override'];
        $hasColumns = [];
        foreach ($columns as $column) {
            $hasColumns[$column] = column_exists($db, 'organizations', $column);
        }
        if (!empty(array_filter($hasColumns))) {
            $fields = [];
            foreach ($columns as $column) {
                if (!empty($hasColumns[$column])) {
                    $fields[] = $column;
                }
            }
            if (!empty($fields)) {
                try {
                    $stmt = $db->prepare("SELECT " . implode(', ', $fields) . " FROM organizations WHERE id = ?");
                    $stmt->execute([$organizationId]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
                    if (!empty($row['plan_tier'])) {
                        $planCode = (string) $row['plan_tier'];
                    }
                    if (array_key_exists('deal_rate_override', $row)) {
                        $overrides['deal_rate'] = $row['deal_rate_override'] !== null ? (float) $row['deal_rate_override'] : null;
                    }
                    if (array_key_exists('accessory_rate_override', $row)) {
                        $overrides['accessory_rate'] = $row['accessory_rate_override'] !== null ? (float) $row['accessory_rate_override'] : null;
                    }
                    if (array_key_exists('service_rate_override', $row)) {
                        $overrides['service_rate'] = $row['service_rate_override'] !== null ? (float) $row['service_rate_override'] : null;
                    }
                } catch (PDOException $e) {
                    $planCode = 'core';
                }
            }
        }
    }

    $planRates = get_plan_rates($db, $planCode);
    return [
        'plan_code' => $planRates['plan_code'] ?? $planCode,
        'deal_rate' => $overrides['deal_rate'] !== null ? $overrides['deal_rate'] : (float) ($planRates['deal_rate'] ?? 0),
        'accessory_rate' => $overrides['accessory_rate'] !== null ? $overrides['accessory_rate'] : (float) ($planRates['accessory_rate'] ?? 0),
        'service_rate' => $overrides['service_rate'] !== null ? $overrides['service_rate'] : (float) ($planRates['service_rate'] ?? 0),
    ];
}

function is_service_enabled(PDO $db, int $organizationId): bool
{
    if (!column_exists($db, 'organizations', 'service_enabled')) {
        return true;
    }
    if ($organizationId <= 0) {
        return true;
    }
    try {
        $stmt = $db->prepare("SELECT service_enabled, org_kind, parent_org_id FROM organizations WHERE id = ?");
        $stmt->execute([$organizationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return true;
        }
        if ($row['service_enabled'] !== null) {
            return (int) $row['service_enabled'] === 1;
        }
        if (($row['org_kind'] ?? 'store') === 'store' && !empty($row['parent_org_id'])) {
            $parentStmt = $db->prepare("SELECT service_enabled FROM organizations WHERE id = ?");
            $parentStmt->execute([(int) $row['parent_org_id']]);
            $parentValue = $parentStmt->fetchColumn();
            if ($parentValue !== false && $parentValue !== null) {
                return (int) $parentValue === 1;
            }
        }
    } catch (PDOException $e) {
        return true;
    }
    return true;
}

function log_usage_event(
    PDO $db,
    int $organizationId,
    int $dealId,
    string $eventType,
    ?int $userId = null,
    ?string $submissionType = null,
    int $quantity = 1
): bool {
    if ($organizationId <= 0 || $dealId <= 0) {
        return false;
    }

    $eventType = strtolower(trim($eventType));
    $quantity = max(1, (int) $quantity);

    $rates = resolve_org_billing_rates($db, $organizationId);
    $unitPrice = 0.0;
    if ($eventType === 'deal_submission') {
        $unitPrice = (float) ($rates['deal_rate'] ?? 0);
    } elseif ($eventType === 'accessory_presentation') {
        $unitPrice = (float) ($rates['accessory_rate'] ?? 0);
    } elseif ($eventType === 'service_ro_sent') {
        $unitPrice = (float) ($rates['service_rate'] ?? 0);
    }
    $totalPrice = round($unitPrice * $quantity, 2);

    $hasEventType = column_exists($db, 'usage_events', 'event_type');
    $hasUnitPrice = column_exists($db, 'usage_events', 'unit_price');
    $hasTotalPrice = column_exists($db, 'usage_events', 'total_price');
    $hasQuantity = column_exists($db, 'usage_events', 'quantity');

    if ($hasEventType) {
        $checkStmt = $db->prepare("SELECT id FROM usage_events WHERE organization_id = ? AND deal_id = ? AND event_type = ? LIMIT 1");
        $checkStmt->execute([$organizationId, $dealId, $eventType]);
        if ($checkStmt->fetchColumn()) {
            return false;
        }
    }

    $normalizedSubmissionType = null;
    if ($submissionType !== null) {
        $submissionCandidate = strtolower(trim($submissionType));
        if (in_array($submissionCandidate, ['cash', 'finance', 'lease'], true)) {
            $normalizedSubmissionType = $submissionCandidate;
        }
    }

    $columns = ['organization_id', 'deal_id', 'submitted_at', 'user_id'];
    $values = [$organizationId, $dealId, date('Y-m-d H:i:s'), $userId];
    if ($normalizedSubmissionType !== null) {
        $columns[] = 'submission_type';
        $values[] = $normalizedSubmissionType;
    }
    if ($hasEventType) {
        $columns[] = 'event_type';
        $values[] = $eventType;
    }
    if ($hasQuantity) {
        $columns[] = 'quantity';
        $values[] = $quantity;
    }
    if ($hasUnitPrice) {
        $columns[] = 'unit_price';
        $values[] = $unitPrice;
    }
    if ($hasTotalPrice) {
        $columns[] = 'total_price';
        $values[] = $totalPrice;
    }

    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
    $stmt = $db->prepare("INSERT INTO usage_events (" . implode(', ', $columns) . ") VALUES (" . $placeholders . ")");
    $stmt->execute($values);
    return true;
}
