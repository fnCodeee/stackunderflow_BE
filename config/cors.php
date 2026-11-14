<?php

/**
 * config/cors.php
 * ─────────────────────────────────────────────────────────────────────────────
 * CORS HANDLER – Cross-Origin Resource Sharing (audit P0, 2026-11-14)
 *
 * Origin TIDAK lagi "*": hanya origin yang masuk allowlist yang
 * menerima header Access-Control-Allow-Origin. Allowlist dibaca dari
 * env CORS_ALLOWED_ORIGINS (daftar dipisah koma); bila env kosong,
 * dipakai default pengembangan lokal di bawah. Untuk production, set
 * CORS_ALLOWED_ORIGINS ke domain frontend yang sebenarnya.
 *
 * Cara kerja:
 *  1. Bila header Origin cocok dengan allowlist, balas origin itu
 *     persis (plus Vary: Origin).
 *  2. Request OPTIONS (preflight) dijawab 200 dan berhenti di sini.
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

$defaultOrigins = [
    'http://localhost:3000',
    'http://127.0.0.1:3000',
    'http://localhost:3100',
    'http://127.0.0.1:3100',
    'http://100.121.159.106:3000',
    'http://10.61.4.201:3000',
];

$rawOrigins = trim((string) ($_ENV['CORS_ALLOWED_ORIGINS'] ?? ''));
if ($rawOrigins === '') {
    $rawOrigins = trim((string) (getenv('CORS_ALLOWED_ORIGINS') ?: ''));
}
$allowedOrigins = $rawOrigins !== ''
    ? array_values(array_filter(array_map('trim', explode(',', $rawOrigins))))
    : $defaultOrigins;

$requestOrigin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
if ($requestOrigin !== '' && in_array($requestOrigin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $requestOrigin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Max-Age: 86400'); // cache preflight 24 jam

// ── Handle OPTIONS preflight ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}
