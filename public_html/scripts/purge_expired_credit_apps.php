<?php
include_once $_SERVER['DOCUMENT_ROOT'] . '/../secure/config.php';
include_once $_SERVER['DOCUMENT_ROOT'] . '/db.php';

try {
    $db->beginTransaction();

    $hasColumn = static function (PDO $db, string $table, string $column): bool {
        $sql = "
            SELECT COUNT(*)
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    };

    $cutoffExpr = "DATE_SUB(NOW(), INTERVAL 60 DAY)";

    // Purge encrypted step-1/2 credit application records after 60 days.
    $deleteCreditSql = "
        DELETE FROM credit_applications
        WHERE (
            submitted_at IS NOT NULL
            AND submitted_at <= {$cutoffExpr}
        )
        OR (
            submitted_at IS NULL
            AND expires_at IS NOT NULL
            AND expires_at <= {$cutoffExpr}
        )
    ";
    $deleteCreditStmt = $db->prepare($deleteCreditSql);
    $deleteCreditStmt->execute();
    $deletedCreditRows = (int)$deleteCreditStmt->rowCount();

    // Clear step-1/2 PII from applications while preserving usage_data and selections.
    $piiColumns = [
        'full_name',
        'email',
        'phone',
        'address',
        'city',
        'province',
        'postal_code',
        'housing',
        'years_at_address',
        'monthly_payment',
        'has_cosigner',
        'co_full_name',
        'co_email',
        'co_phone',
        'co_address',
        'co_city',
        'co_province',
        'co_postal_code',
        'co_housing',
        'co_years_at_address',
        'co_monthly_payment',
        'employer',
        'work_address',
        'position',
        'employment_length',
        'prev_employer',
        'prev_phone',
        'prev_address',
        'prev_length',
        'income',
        'other_income',
        'other_income_source',
        'co_employer',
        'co_work_address',
        'co_position',
        'co_employment_length',
        'co_prev_employer',
        'co_prev_phone',
        'co_prev_address',
        'co_prev_length',
        'co_income',
        'co_other_income',
        'co_other_income_source',
        'extra_answers_json',
    ];

    $setClauses = [];
    foreach ($piiColumns as $col) {
        if ($hasColumn($db, 'applications', $col)) {
            $setClauses[] = "{$col} = NULL";
        }
    }
    $hasPiiClearedAt = $hasColumn($db, 'applications', 'pii_cleared_at');
    if ($hasPiiClearedAt) {
        $setClauses[] = "pii_cleared_at = COALESCE(pii_cleared_at, NOW())";
    }

    $clearedApplications = 0;
    if (!empty($setClauses)) {
        $clearSql = "
            UPDATE applications
            SET " . implode(",\n                ", $setClauses) . "
            WHERE COALESCE(submitted_at, started_at) <= {$cutoffExpr}
        ";
        $clearStmt = $db->prepare($clearSql);
        $clearStmt->execute();
        $clearedApplications = (int)$clearStmt->rowCount();
    }

    $db->commit();

    echo "✅ Credit PII purge completed at " . date('Y-m-d H:i:s')
        . " | credit_applications deleted: {$deletedCreditRows}"
        . " | applications scrubbed: {$clearedApplications}";
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log("❌ Purge failed: " . $e->getMessage());
    echo "❌ Error: " . $e->getMessage();
}
?>
