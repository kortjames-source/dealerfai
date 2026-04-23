<?php

function dealerfai_csrf_get_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        throw new RuntimeException('CSRF requires an active session.');
    }

    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token']) || strlen($_SESSION['csrf_token']) < 32) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function dealerfai_csrf_validate(?string $token): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return false;
    }
    if (!is_string($token) || $token === '') {
        return false;
    }
    $expected = $_SESSION['csrf_token'] ?? '';
    if (!is_string($expected) || $expected === '') {
        return false;
    }
    return hash_equals($expected, $token);
}

