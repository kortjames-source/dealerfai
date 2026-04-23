<?php
// Run a .sql file using the project's PDO connection (db.php).
// Usage: php tools/run_sql_file.php path/to/file.sql

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script must be run from the CLI.\n");
    exit(2);
}

$path = $argv[1] ?? '';
if ($path === '') {
    fwrite(STDERR, "Missing SQL file path.\n");
    exit(2);
}

$fullPath = realpath($path);
if ($fullPath === false || !is_file($fullPath)) {
    fwrite(STDERR, "SQL file not found: {$path}\n");
    exit(2);
}

require __DIR__ . '/../public_html/db.php';
if (!isset($db) || !($db instanceof PDO)) {
    fwrite(STDERR, "db.php did not provide a PDO instance in \$db.\n");
    exit(2);
}

$sql = file_get_contents($fullPath);
if ($sql === false) {
    fwrite(STDERR, "Failed to read SQL file: {$fullPath}\n");
    exit(2);
}

// Very small statement splitter: good enough for our migrations (no DELIMITER, no procedures).
function split_sql_statements(string $sql): array
{
    $out = [];
    $buf = '';
    $inSingle = false;
    $inDouble = false;
    $inLineComment = false;
    $inBlockComment = false;
    $len = strlen($sql);

    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];
        $next = ($i + 1 < $len) ? $sql[$i + 1] : '';

        if ($inLineComment) {
            if ($ch === "\n") {
                $inLineComment = false;
                $buf .= $ch;
            }
            continue;
        }
        if ($inBlockComment) {
            if ($ch === '*' && $next === '/') {
                $inBlockComment = false;
                $i++;
            }
            continue;
        }

        if (!$inSingle && !$inDouble) {
            if ($ch === '-' && $next === '-') {
                $inLineComment = true;
                $i++;
                continue;
            }
            if ($ch === '#') {
                $inLineComment = true;
                continue;
            }
            if ($ch === '/' && $next === '*') {
                $inBlockComment = true;
                $i++;
                continue;
            }
        }

        if ($ch === "'" && !$inDouble) {
            $escaped = ($i > 0 && $sql[$i - 1] === '\\');
            if (!$escaped) {
                $inSingle = !$inSingle;
            }
        } elseif ($ch === '"' && !$inSingle) {
            $escaped = ($i > 0 && $sql[$i - 1] === '\\');
            if (!$escaped) {
                $inDouble = !$inDouble;
            }
        }

        if ($ch === ';' && !$inSingle && !$inDouble) {
            $stmt = trim($buf);
            if ($stmt !== '') {
                $out[] = $stmt;
            }
            $buf = '';
            continue;
        }

        $buf .= $ch;
    }

    $stmt = trim($buf);
    if ($stmt !== '') {
        $out[] = $stmt;
    }
    return $out;
}

$stmts = split_sql_statements($sql);
if (empty($stmts)) {
    fwrite(STDERR, "No SQL statements found in {$fullPath}\n");
    exit(2);
}

$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {
    foreach ($stmts as $idx => $stmt) {
        $db->exec($stmt);
        fwrite(STDOUT, "OK " . ($idx + 1) . "/" . count($stmts) . "\n");
    }
} catch (Throwable $e) {
    fwrite(STDERR, "FAILED: " . $e->getMessage() . "\n");
    exit(1);
}

