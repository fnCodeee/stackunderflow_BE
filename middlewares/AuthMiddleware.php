<?php

/**
 * middlewares/AuthMiddleware.php
 * ─────────────────────────────────────────────────────────────────────────────
 * AUTHENTICATION MIDDLEWARE
 *
 * Memeriksa header `Authorization: Bearer <token>`.
 *
 * Alur:
 *  1. Ambil header Authorization dari request ($_SERVER atau getallheaders)
 *  2. Jika header tidak ada -> HTTP 403 "Token Tidak Tersedia!"
 *  3. Jika format bukan "Bearer <token>" -> HTTP 403 "Token Tidak Tersedia"
 *  4. Decode & validasi JWT via JWT::decode($token)
 *  5. Jika gagal (invalid / expired) -> HTTP 401 "<error message>"
 *  6. Jika berhasil -> simpan decoded user ke $GLOBALS['user'] dan lanjutkan eksekusi
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

require_once BASE_PATH . '/utils/JWT.php';
require_once BASE_PATH . '/utils/Response.php';

class AuthMiddleware
{
    /**
     * Handler utama middleware authentication.
     *
     * @param  array<string, string> $params Path parameters dari Router
     * @return void
     */
    public static function handle(array $params = []): void
    {
        $authHeader = self::getAuthorizationHeader();

        // 1. Validasi keberadaan header Authorization
        if ($authHeader === null || trim($authHeader) === '') {
            Response::json(null, 403, 'Token Tidak Tersedia!', false);
        }

        // 2. Validasi format Bearer token
        $parts = explode(' ', trim($authHeader), 2);
        if (count($parts) !== 2 || strtolower($parts[0]) !== 'bearer' || trim($parts[1]) === '') {
            Response::json(null, 403, 'Token Tidak Tersedia', false);
        }

        $token = trim($parts[1]);

        // 3. Verifikasi JWT
        try {
            $decoded = JWT::decode($token);
            $GLOBALS['user'] = $decoded;
        } catch (Throwable $e) {
            Response::json(null, 401, $e->getMessage(), false);
        }
    }

    /**
     * Mengambil nilai header Authorization dari berbagai kemungkinan environment (Apache, Nginx, PHP CLI).
     *
     * @return string|null
     */
    private static function getAuthorizationHeader(): ?string
    {
        if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
            return (string)$_SERVER['HTTP_AUTHORIZATION'];
        }

        if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            return (string)$_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }

        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (is_array($headers)) {
                // Header keys di HTTP bersifat case-insensitive
                foreach ($headers as $key => $value) {
                    if (strtolower((string)$key) === 'authorization') {
                        return (string)$value;
                    }
                }
            }
        }

        return null;
    }
}
