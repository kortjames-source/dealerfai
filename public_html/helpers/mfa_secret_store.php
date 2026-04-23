<?php

require_once __DIR__ . '/pii_crypto.php';

function dealerfai_users_supports_mfa_v2(PDO $db): bool
{
    static $supported = null;
    if (is_bool($supported)) {
        return $supported;
    }

    try {
        $stmt = $db->prepare("
            SELECT COLUMN_NAME
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'users'
              AND COLUMN_NAME IN ('mfa_secret_ciphertext','mfa_secret_nonce','mfa_secret_tag','mfa_confirmed_at')
        ");
        $stmt->execute();
        $cols = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $cols = array_map('strtolower', array_filter(array_map('strval', $cols)));
        $need = ['mfa_secret_ciphertext','mfa_secret_nonce','mfa_secret_tag','mfa_confirmed_at'];
        foreach ($need as $n) {
            if (!in_array($n, $cols, true)) {
                $supported = false;
                return $supported;
            }
        }
        $supported = true;
        return $supported;
    } catch (Throwable $e) {
        $supported = false;
        return $supported;
    }
}

function dealerfai_mfa_encrypt_secret(string $secretBase32): array
{
    $keyBin = dealerfai_crypto_key_bin();
    $nonce = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($secretBase32, 'aes-256-gcm', $keyBin, OPENSSL_RAW_DATA, $nonce, $tag);
    if ($ciphertext === false || $tag === '') {
        throw new RuntimeException('MFA secret encrypt failed.');
    }
    return [$ciphertext, $nonce, $tag];
}

function dealerfai_mfa_decrypt_secret(?string $ciphertext, ?string $nonce, ?string $tag): ?string
{
    if ($ciphertext === null || $nonce === null || $tag === null) {
        return null;
    }
    if ($ciphertext === '' || $nonce === '' || $tag === '') {
        return null;
    }
    $keyBin = dealerfai_crypto_key_bin();
    $plain = openssl_decrypt($ciphertext, 'aes-256-gcm', $keyBin, OPENSSL_RAW_DATA, $nonce, $tag);
    if ($plain === false) {
        return null;
    }
    return (string)$plain;
}

function dealerfai_mfa_generate_secret(int $length = 32): string
{
    // Base32 alphabet, length 32 is common for TOTP secrets.
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $key = '';
    $max = strlen($chars) - 1;
    for ($i = 0; $i < $length; $i++) {
        $key .= $chars[random_int(0, $max)];
    }
    return $key;
}

function dealerfai_mfa_get_or_create_secret(PDO $db, int $userId): string
{
    $supportsV2 = dealerfai_users_supports_mfa_v2($db);
    $stmt = $db->prepare($supportsV2
        ? "SELECT mfa_secret_ciphertext, mfa_secret_nonce, mfa_secret_tag, mfa_secret FROM users WHERE id = ?"
        : "SELECT mfa_secret FROM users WHERE id = ?"
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    // Prefer v2 encrypted columns.
    if ($supportsV2) {
        $secret = dealerfai_mfa_decrypt_secret(
            $row['mfa_secret_ciphertext'] ?? null,
            $row['mfa_secret_nonce'] ?? null,
            $row['mfa_secret_tag'] ?? null
        );
        if (is_string($secret) && $secret !== '') {
            return $secret;
        }
    }

    // Legacy fallback: plain Base32 in mfa_secret (if present).
    $legacy = isset($row['mfa_secret']) ? trim((string)$row['mfa_secret']) : '';
    if ($legacy !== '') {
        return $legacy;
    }

    $secret = dealerfai_mfa_generate_secret(32);
    if ($supportsV2) {
        [$ct, $nonce, $tag] = dealerfai_mfa_encrypt_secret($secret);

        $up = $db->prepare("UPDATE users SET mfa_secret_ciphertext = ?, mfa_secret_nonce = ?, mfa_secret_tag = ?, mfa_enabled = 0, mfa_confirmed_at = NULL WHERE id = ?");
        $up->execute([$ct, $nonce, $tag, $userId]);
    } else {
        // Legacy mode: store the Base32 secret directly.
        $up = $db->prepare("UPDATE users SET mfa_secret = ?, mfa_enabled = 0 WHERE id = ?");
        $up->execute([$secret, $userId]);
    }

    return $secret;
}
