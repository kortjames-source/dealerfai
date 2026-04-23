<?php

function dealerfai_crypto_key_bin(): string
{
    // secure/config.php defines AES_KEY.
    if (!defined('AES_KEY')) {
        include_once __DIR__ . '/../../secure/config.php';
    }

    $key = (string)(defined('AES_KEY') ? AES_KEY : '');
    if ($key === '') {
        throw new RuntimeException('AES_KEY not configured.');
    }

    // Most installs store AES_KEY as a hex string. Fall back to raw.
    $isHex = (strlen($key) % 2 === 0) && ctype_xdigit($key);
    if ($isHex) {
        $bin = hex2bin($key);
        if ($bin !== false) {
            return $bin;
        }
    }
    return $key;
}

function dealerfai_encrypt_pii_v2(array $payload): array
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('PII JSON encode failed.');
    }

    $keyBin = dealerfai_crypto_key_bin();
    $nonce = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($json, 'aes-256-gcm', $keyBin, OPENSSL_RAW_DATA, $nonce, $tag);
    if ($ciphertext === false || $tag === '') {
        throw new RuntimeException('PII encrypt failed.');
    }

    return [$ciphertext, $nonce, $tag];
}

function dealerfai_credit_applications_supports_pii_v2(PDO $db): bool
{
    static $supported = null;
    if (is_bool($supported)) {
        return $supported;
    }

    try {
        $stmt = $db->prepare("
            SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'credit_applications'
              AND COLUMN_NAME IN ('pii_ciphertext','pii_nonce','pii_tag','encryption_version')
        ");
        $stmt->execute();
        $cols = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $cols = array_map('strtolower', array_filter(array_map('strval', $cols)));
        $need = ['pii_ciphertext','pii_nonce','pii_tag','encryption_version'];
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
