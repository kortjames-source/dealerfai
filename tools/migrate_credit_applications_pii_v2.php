<?php
/**
 * One-off migration helper:
 * - Adds v2 encrypted blob columns (if missing)
 * - Migrates legacy AES-256-CBC (fixed IV) per-column PII into AES-256-GCM blob
 *
 * Usage:
 *   php tools/migrate_credit_applications_pii_v2.php
 *
 * Optional:
 *   DEALERFAI_MIGRATE_CONFIRM=YES            (required to run)
 *   DEALERFAI_MIGRATE_WIPE_LEGACY=YES       (NULL out legacy encrypted columns after v2 write)
 */

if ((string)(getenv('DEALERFAI_MIGRATE_CONFIRM') ?: '') !== 'YES') {
    fwrite(STDERR, "Refusing to run. Set DEALERFAI_MIGRATE_CONFIRM=YES\n");
    exit(2);
}

require_once __DIR__ . '/../public_html/db.php';
$config = require __DIR__ . '/../secure/config.php';

function dealerfai_crypto_key_bin(): string
{
    $key = (string)(defined('AES_KEY') ? AES_KEY : '');
    if ($key === '') {
        throw new RuntimeException('AES_KEY not configured.');
    }

    // Most installs store AES_KEY as hex. Fall back to raw if not hex.
    $isHex = (strlen($key) % 2 === 0) && ctype_xdigit($key);
    if ($isHex) {
        $bin = hex2bin($key);
        if ($bin !== false) {
            return $bin;
        }
    }
    return $key;
}

function dealerfai_legacy_decrypt(?string $ciphertext, string $keyBin): ?string
{
    if ($ciphertext === null || $ciphertext === '') {
        return null;
    }
    $iv = substr($keyBin, 0, 16);
    $plain = openssl_decrypt($ciphertext, 'aes-256-cbc', $keyBin, OPENSSL_RAW_DATA, $iv);
    if ($plain === false) {
        return null;
    }
    return $plain;
}

function dealerfai_encrypt_v2_gcm(string $plaintext, string $keyBin): array
{
    $nonce = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $keyBin, OPENSSL_RAW_DATA, $nonce, $tag);
    if ($ciphertext === false || $tag === '') {
        throw new RuntimeException('AES-256-GCM encrypt failed.');
    }
    return [$ciphertext, $nonce, $tag];
}

function dealerfai_has_column(PDO $db, string $table, string $column): bool
{
    $stmt = $db->prepare("
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
        LIMIT 1
    ");
    $stmt->execute([$table, $column]);
    return (bool)$stmt->fetchColumn();
}

function dealerfai_add_columns(PDO $db): void
{
    $needs = [
        'encryption_version' => "ADD COLUMN `encryption_version` tinyint NOT NULL DEFAULT 1 AFTER `is_co_applicant`",
        'pii_ciphertext' => "ADD COLUMN `pii_ciphertext` longblob DEFAULT NULL AFTER `expires_at`",
        'pii_nonce' => "ADD COLUMN `pii_nonce` varbinary(12) DEFAULT NULL AFTER `pii_ciphertext`",
        'pii_tag' => "ADD COLUMN `pii_tag` varbinary(16) DEFAULT NULL AFTER `pii_nonce`",
        'pii_migrated_at' => "ADD COLUMN `pii_migrated_at` datetime DEFAULT NULL AFTER `pii_tag`",
    ];

    $clauses = [];
    foreach ($needs as $col => $ddl) {
        if (!dealerfai_has_column($db, 'credit_applications', $col)) {
            $clauses[] = $ddl;
        }
    }
    if (!$clauses) {
        return;
    }
    $sql = "ALTER TABLE `credit_applications`\n  " . implode(",\n  ", $clauses);
    $db->exec($sql);
}

$wipeLegacy = ((string)(getenv('DEALERFAI_MIGRATE_WIPE_LEGACY') ?: '') === 'YES');
$keyBin = dealerfai_crypto_key_bin();

dealerfai_add_columns($db);

// Pull unique keys missing v2. There can be duplicates per (deal_id, is_co_applicant).
$stmt = $db->query("
    SELECT
      deal_id,
      is_co_applicant
    FROM credit_applications
    WHERE pii_ciphertext IS NULL OR pii_nonce IS NULL OR pii_tag IS NULL OR encryption_version < 2
    GROUP BY deal_id, is_co_applicant
");
$keys = $stmt->fetchAll(PDO::FETCH_ASSOC);
fwrite(STDOUT, "Found " . count($keys) . " deal/is_co groups to migrate.\n");

// Fetch the "best" representative legacy row for a given key.
$fetchLegacy = $db->prepare("
    SELECT
      deal_id,
      is_co_applicant,
      first_name, last_name, email, phone, address, city, postal_code, previous_address,
      sin_encrypted,
      employer_name, employer_address,
      previous_employer_name, previous_employer_phone, previous_employer_address,
      other_income_source
    FROM credit_applications
    WHERE deal_id = ? AND is_co_applicant = ?
    ORDER BY encryption_version DESC, pii_migrated_at DESC, submitted_at DESC
    LIMIT 1
");

$update = $db->prepare("
    UPDATE credit_applications
    SET
      encryption_version = 2,
      pii_ciphertext = ?,
      pii_nonce = ?,
      pii_tag = ?,
      pii_migrated_at = NOW()
      " . ($wipeLegacy ? ",
      first_name = NULL,
      last_name = NULL,
      email = NULL,
      phone = NULL,
      address = NULL,
      city = NULL,
      postal_code = NULL,
      previous_address = NULL,
      sin_encrypted = NULL,
      employer_name = NULL,
      employer_address = NULL,
      previous_employer_name = NULL,
      previous_employer_phone = NULL,
      previous_employer_address = NULL
      , other_income_source = NULL
      " : "") . "
    WHERE deal_id = ? AND is_co_applicant = ?
");

$migrated = 0;
$failed = 0;

foreach ($keys as $keyRow) {
    $dealId = (int)$keyRow['deal_id'];
    $isCo = (int)($keyRow['is_co_applicant'] ?? 0);

    $fetchLegacy->execute([$dealId, $isCo]);
    $row = $fetchLegacy->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        continue;
    }

    $pii = [
        'first_name' => dealerfai_legacy_decrypt($row['first_name'] ?? null, $keyBin),
        'last_name' => dealerfai_legacy_decrypt($row['last_name'] ?? null, $keyBin),
        'email' => dealerfai_legacy_decrypt($row['email'] ?? null, $keyBin),
        'phone' => dealerfai_legacy_decrypt($row['phone'] ?? null, $keyBin),
        'address' => dealerfai_legacy_decrypt($row['address'] ?? null, $keyBin),
        'city' => dealerfai_legacy_decrypt($row['city'] ?? null, $keyBin),
        'postal_code' => dealerfai_legacy_decrypt($row['postal_code'] ?? null, $keyBin),
        'previous_address' => dealerfai_legacy_decrypt($row['previous_address'] ?? null, $keyBin),
        'sin' => dealerfai_legacy_decrypt($row['sin_encrypted'] ?? null, $keyBin),
        'employer_name' => dealerfai_legacy_decrypt($row['employer_name'] ?? null, $keyBin),
        'employer_address' => dealerfai_legacy_decrypt($row['employer_address'] ?? null, $keyBin),
        'previous_employer_name' => dealerfai_legacy_decrypt($row['previous_employer_name'] ?? null, $keyBin),
        'previous_employer_phone' => dealerfai_legacy_decrypt($row['previous_employer_phone'] ?? null, $keyBin),
        'previous_employer_address' => dealerfai_legacy_decrypt($row['previous_employer_address'] ?? null, $keyBin),
        // other_income_source was stored as varchar (plaintext). Keep it in the blob too.
        'other_income_source' => isset($row['other_income_source']) ? (string)$row['other_income_source'] : null,
    ];

    // If there is nothing to migrate, skip (but still allow version bump if desired later).
    $hasAny = false;
    foreach ($pii as $v) {
        if ($v !== null && $v !== '') {
            $hasAny = true;
            break;
        }
    }
    if (!$hasAny) {
        continue;
    }

    try {
        $json = json_encode($pii, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('JSON encode failed.');
        }
        [$ciphertext, $nonce, $tag] = dealerfai_encrypt_v2_gcm($json, $keyBin);
        $update->execute([$ciphertext, $nonce, $tag, $dealId, $isCo]);
        $migrated += max(1, (int)$update->rowCount());
    } catch (Throwable $e) {
        $failed++;
        fwrite(STDERR, "Failed deal_id={$dealId} is_co={$isCo}: " . $e->getMessage() . "\n");
    }
}

fwrite(STDOUT, "Migrated {$migrated} rows. Failed {$failed}.\n");
if ($wipeLegacy) {
    fwrite(STDOUT, "Legacy columns were wiped for migrated rows.\n");
}
