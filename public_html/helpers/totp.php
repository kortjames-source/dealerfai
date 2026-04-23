<?php

// Minimal RFC 6238 TOTP verifier (HMAC-SHA1, 30s, 6 digits).

function dealerfai_base32_decode(string $b32): string
{
    $b32 = strtoupper(trim($b32));
    $b32 = preg_replace('/[^A-Z2-7]/', '', $b32);
    if ($b32 === '') {
        return '';
    }

    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $buffer = 0;
    $bitsLeft = 0;
    $out = '';

    $len = strlen($b32);
    for ($i = 0; $i < $len; $i++) {
        $ch = $b32[$i];
        $val = strpos($alphabet, $ch);
        if ($val === false) {
            continue;
        }
        $buffer = ($buffer << 5) | $val;
        $bitsLeft += 5;
        while ($bitsLeft >= 8) {
            $bitsLeft -= 8;
            $out .= chr(($buffer >> $bitsLeft) & 0xFF);
        }
    }

    return $out;
}

function dealerfai_totp_at(string $secretBase32, int $timestamp, int $digits = 6, int $period = 30): string
{
    $key = dealerfai_base32_decode($secretBase32);
    if ($key === '') {
        return '';
    }

    $counter = intdiv($timestamp, $period);
    // 8-byte big-endian counter
    $bin = pack('N2', ($counter >> 32) & 0xFFFFFFFF, $counter & 0xFFFFFFFF);

    $hash = hash_hmac('sha1', $bin, $key, true);
    $offset = ord($hash[19]) & 0x0F;
    $part = substr($hash, $offset, 4);
    $val = unpack('N', $part)[1] & 0x7FFFFFFF;
    $mod = 10 ** $digits;
    $code = (string)($val % $mod);
    return str_pad($code, $digits, '0', STR_PAD_LEFT);
}

function dealerfai_totp_verify(string $secretBase32, string $code, int $window = 1, int $digits = 6, int $period = 30): bool
{
    $code = preg_replace('/\\D/', '', (string)$code);
    if ($code === null) {
        return false;
    }
    if (strlen($code) !== $digits) {
        return false;
    }

    $now = time();
    for ($i = -$window; $i <= $window; $i++) {
        $expected = dealerfai_totp_at($secretBase32, $now + ($i * $period), $digits, $period);
        if ($expected !== '' && hash_equals($expected, $code)) {
            return true;
        }
    }
    return false;
}

