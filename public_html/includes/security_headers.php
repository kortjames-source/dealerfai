<?php
// Centralized security headers for HTML/JSON endpoints.
// Must be included before any output is sent.

// Generate a per-request CSP nonce (once only — safe to include multiple times).
if (!isset($GLOBALS['csp_nonce'])) {
    $GLOBALS['csp_nonce'] = base64_encode(random_bytes(18));
}

if (!function_exists('dealerfai_csp_nonce')) {
    function dealerfai_csp_nonce(): string
    {
        return (string)($GLOBALS['csp_nonce'] ?? '');
    }
}

// Prevent caching of pages that may contain customer data.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: SAMEORIGIN');
header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=(), usb=()');

// CSP: script-src fully nonce-protected — no unsafe-inline for scripts.
// style-src retains unsafe-inline for ~142 remaining one-off inline style= attributes.
// Once those are converted to classes or CSS vars, unsafe-inline can be removed from style-src.
$nonce = dealerfai_csp_nonce();
header("Content-Security-Policy: default-src 'self'; img-src 'self' https: data:; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; script-src 'self' 'nonce-{$nonce}'; connect-src 'self' https://fonts.googleapis.com https://fonts.gstatic.com; font-src 'self' data: https://fonts.gstatic.com; base-uri 'self'; form-action 'self'; frame-ancestors 'self'; object-src 'none'");
