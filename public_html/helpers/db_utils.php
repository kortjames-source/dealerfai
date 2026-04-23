<?php
declare(strict_types=1);

/**
 * Check if a specific column exists in a table within the current database.
 */
function column_exists(PDO $db, string $table, string $column): bool
{
    static $cache = [];
    $key = strtolower($table . '.' . $column);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?"
    );
    $stmt->execute([$table, $column]);
    $cache[$key] = ((int)$stmt->fetchColumn() > 0);
    return $cache[$key];
}

function table_column_exists(PDO $db, string $table, string $column): bool
{
    return column_exists($db, $table, $column);
}

function get_table_columns(PDO $db, string $table): array
{
    static $cache = [];
    $tableKey = strtolower($table);
    if (array_key_exists($tableKey, $cache)) {
        return $cache[$tableKey];
    }

    try {
        $stmt = $db->prepare(
            "SELECT COLUMN_NAME
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?"
        );
        $stmt->execute([$table]);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $columns = [];
        foreach ($rows as $column) {
            $columns[] = (string)$column;
        }
        $cache[$tableKey] = $columns;
    } catch (Throwable $e) {
        $cache[$tableKey] = [];
    }

    return $cache[$tableKey];
}

function select_existing_columns(PDO $db, string $table, array $preferredColumns): array
{
    $available = get_table_columns($db, $table);
    if (empty($available)) {
        return [];
    }

    $availableLookup = [];
    foreach ($available as $col) {
        $availableLookup[strtolower($col)] = $col;
    }

    $selected = [];
    foreach ($preferredColumns as $col) {
        $key = strtolower((string)$col);
        if (isset($availableLookup[$key])) {
            $selected[] = $availableLookup[$key];
        }
    }

    return $selected;
}

if (!function_exists('organization_column_exists')) {
    function organization_column_exists(PDO $db, string $column): bool
    {
        return table_column_exists($db, 'organizations', $column);
    }
}
