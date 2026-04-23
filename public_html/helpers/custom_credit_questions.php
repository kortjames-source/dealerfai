<?php
function get_org_custom_credit_questions(PDO $db, int $orgId, string $step): array
{
    if ($orgId <= 0) {
        return [];
    }
    $stmt = $db->prepare("
        SELECT id, step, label, field_key, field_type, options_json, is_required, sort_order
        FROM credit_app_custom_questions
        WHERE organization_id = ?
          AND step = ?
          AND is_active = 1
        ORDER BY sort_order ASC, id ASC
    ");
    $stmt->execute([$orgId, $step]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$row) {
        $options = [];
        if (!empty($row['options_json'])) {
            $decoded = json_decode($row['options_json'], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $options = $decoded;
            }
        }
        $row['options'] = $options;
        $row['is_required'] = (int)($row['is_required'] ?? 0) === 1;
    }
    unset($row);
    return $rows;
}

function normalize_custom_answer_value($value, string $fieldType)
{
    if ($fieldType === 'multiselect') {
        if (!is_array($value)) {
            return $value === null ? [] : [$value];
        }
        return array_values($value);
    }
    if ($fieldType === 'checkbox') {
        return $value ? '1' : '';
    }
    if (is_array($value)) {
        return $value;
    }
    return is_string($value) ? trim($value) : $value;
}
