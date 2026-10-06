<?php

/**
 * middlewares/OptionalAuthMiddleware.php
 * ─────────────────────────────────────────────────────────────────────────────
 * OPTIONAL AUTHENTICATION MIDDLEWARE
 *
 * Memeriksa header `Authorization: Bearer <token>` jika tersedia.
 * Jika token ada dan valid -> simpan decoded user ke $GLOBALS['user'].
 * Jika token tidak ada / format salah / invalid / expired -> $GLOBALS['user'] = null
 * dan eksekusi tetap dilanjutkan (tidak mengembalikan 401/403).
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

require_once BASE_PATH . '/utils/JWT.php';

class OptionalAuthMiddleware
{
    /**
     * Handler middleware optional authentication.
     *
     * @param  array<string, string> $params Path parameters dari Router
     * @return void
     */
    public static function handle(array $params = []): void
    {
        $authHeader = self::getAuthorizationHeader();

        if ($authHeader === null || trim($authHeader) === '') {
            $GLOBALS['user'] = null;
            return;
        }

        $parts = explode(' ', trim($authHeader), 2);
        if (count($parts) !== 2 || strtolower($parts[0]) !== 'bearer' || trim($parts[1]) === '') {
            $GLOBALS['user'] = null;
            return;
        }

        $token = trim($parts[1]);

        try {
            $decoded = JWT::decode($token);
            $GLOBALS['user'] = $decoded;
        } catch (Throwable $e) {
            $GLOBALS['user'] = null;
        }
    }

    /**
     * Mengambil nilai header Authorization dari berbagai environment.
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
