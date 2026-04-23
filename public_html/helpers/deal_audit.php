<?php

if (!function_exists('deal_audit_table_exists')) {
    function deal_audit_table_exists(PDO $db): bool
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        try {
            $stmt = $db->prepare("
                SELECT COUNT(*)
                FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'deal_change_audit_log'
            ");
            $stmt->execute();
            $cache = ((int)$stmt->fetchColumn() > 0);
        } catch (Throwable $e) {
            $cache = false;
        }
        return $cache;
    }
}

if (!function_exists('fetch_deal_row_for_audit')) {
    function fetch_deal_row_for_audit(PDO $db, int $dealId): ?array
    {
        if ($dealId <= 0) {
            return null;
        }
        try {
            $stmt = $db->prepare("SELECT * FROM deals WHERE id = ?");
            $stmt->execute([$dealId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $row : null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('compute_deal_changed_fields')) {
    function compute_deal_changed_fields(?array $before, ?array $after): array
    {
        $before = is_array($before) ? $before : [];
        $after = is_array($after) ? $after : [];
        $keys = array_values(array_unique(array_merge(array_keys($before), array_keys($after))));
        sort($keys);
        $changed = [];
        foreach ($keys as $key) {
            $old = $before[$key] ?? null;
            $new = $after[$key] ?? null;
            if (json_encode($old) !== json_encode($new)) {
                $changed[] = (string)$key;
            }
        }
        return $changed;
    }
}

if (!function_exists('log_deal_change_audit')) {
    function log_deal_change_audit(PDO $db, int $dealId, string $actionType, ?array $beforeRow, ?array $afterRow, array $meta = []): void
    {
        if ($dealId <= 0 || !deal_audit_table_exists($db)) {
            return;
        }

        $action = strtolower(trim($actionType));
        if (!in_array($action, ['insert', 'update', 'delete'], true)) {
            $action = 'update';
        }

        $changedFields = compute_deal_changed_fields($beforeRow, $afterRow);
        $metaPayload = $meta;
        if (!isset($metaPayload['request_uri'])) {
            $metaPayload['request_uri'] = $_SERVER['REQUEST_URI'] ?? null;
        }
        if (!isset($metaPayload['request_method'])) {
            $metaPayload['request_method'] = $_SERVER['REQUEST_METHOD'] ?? null;
        }
        if (!isset($metaPayload['source_ip'])) {
            $metaPayload['source_ip'] = $_SERVER['REMOTE_ADDR'] ?? null;
        }

        $insert = $db->prepare("
            INSERT INTO deal_change_audit_log
                (deal_id, action_type, changed_fields, before_data, after_data, change_meta, user_id, request_uri, request_method, source_ip, created_at)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $insert->execute([
            $dealId,
            $action,
            json_encode($changedFields, JSON_UNESCAPED_SLASHES),
            $beforeRow !== null ? json_encode($beforeRow, JSON_UNESCAPED_SLASHES) : null,
            $afterRow !== null ? json_encode($afterRow, JSON_UNESCAPED_SLASHES) : null,
            !empty($metaPayload) ? json_encode($metaPayload, JSON_UNESCAPED_SLASHES) : null,
            isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null,
            (string)($metaPayload['request_uri'] ?? ''),
            (string)($metaPayload['request_method'] ?? ''),
            (string)($metaPayload['source_ip'] ?? ''),
        ]);
    }
}

