<?php

/**
 * utils/JWT.php
 * ─────────────────────────────────────────────────────────────────────────────
 * NATIVE PHP JWT UTILITY (HS256)
 *
 * Implementasi JSON Web Token murni tanpa external library/framework.
 * Menggunakan algoritma HMAC-SHA256 (HS256).
 *
 * Format Token:
 *   header.payload.signature
 *
 * Fitur:
 *  - Encode payload menjadi JWT string
 *  - Decode & verifikasi signature
 *  - Verifikasi masa berlaku (exp claim)
 *  - Mengambil JWT_SECRET dari environment ($_ENV / getenv)
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

class JWT
{
    /**
     * Default masa berlaku token: 3 hari (sesuai baseline Express).
     */
    public const DEFAULT_EXPIRY = 259200; // 3 * 24 * 60 * 60 detik

    /**
     * Mengambil JWT secret dari environment variable.
     *
     * @return string
     * @throws RuntimeException jika secret belum dikonfigurasi
     */
    public static function getSecret(): string
    {
        $secret = $_ENV['JWT_SECRET'] ?? getenv('JWT_SECRET') ?? $_SERVER['JWT_SECRET'] ?? '';
        $secret = trim((string)$secret);

        if ($secret === '') {
            throw new RuntimeException('Konfigurasi JWT_SECRET belum diatur atau kosong di file .env');
        }

        return $secret;
    }

    /**
     * Generate token JWT (HS256).
     *
     * @param  array<string, mixed> $payload           Data yang akan disimpan dalam token
     * @param  int|null             $expiresInSeconds  Masa berlaku dalam detik (default: 3 hari)
     * @param  string|null          $secret            Custom secret (opsional, default dari .env)
     * @return string                                  Token JWT terformat header.payload.signature
     */
    public static function encode(array $payload, ?int $expiresInSeconds = self::DEFAULT_EXPIRY, ?string $secret = null): string
    {
        $signingKey = $secret ?? self::getSecret();

        // 1. Siapkan Header
        $header = [
            'typ' => 'JWT',
            'alg' => 'HS256',
        ];

        // 2. Tambahkan standard claims ke payload jika ditentukan
        $now = time();
        if (!isset($payload['iat'])) {
            $payload['iat'] = $now;
        }

        if ($expiresInSeconds !== null && !isset($payload['exp'])) {
            $payload['exp'] = $now + $expiresInSeconds;
        }

        // 3. Base64Url encode header & payload
        $headerEncoded  = self::base64UrlEncode((string)json_encode($header, JSON_UNESCAPED_SLASHES));
        $payloadEncoded = self::base64UrlEncode((string)json_encode($payload, JSON_UNESCAPED_SLASHES));

        // 4. Buat Signature dengan HMAC-SHA256
        $signature = hash_hmac('sha256', "{$headerEncoded}.{$payloadEncoded}", $signingKey, true);
        $signatureEncoded = self::base64UrlEncode($signature);

        return "{$headerEncoded}.{$payloadEncoded}.{$signatureEncoded}";
    }

    /**
     * Decode dan validasi token JWT.
     *
     * @param  string      $token   Token JWT
     * @param  string|null $secret  Custom secret (opsional, default dari .env)
     * @return array<string, mixed> Payload yang sudah di-decode
     * @throws Exception            Jika format salah, signature invalid, atau token expired
     */
    public static function decode(string $token, ?string $secret = null): array
    {
        $signingKey = $secret ?? self::getSecret();

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new Exception('Format token tidak valid');
        }

        [$headerEncoded, $payloadEncoded, $signatureEncoded] = $parts;

        // 1. Decode Header
        $headerJson = self::base64UrlDecode($headerEncoded);
        $header = json_decode($headerJson, true);
        if (!is_array($header) || ($header['alg'] ?? '') !== 'HS256') {
            throw new Exception('Algoritma token tidak didukung');
        }

        // 2. Verifikasi Signature
        $expectedSignature = hash_hmac('sha256', "{$headerEncoded}.{$payloadEncoded}", $signingKey, true);
        $actualSignature   = self::base64UrlDecode($signatureEncoded);

        // Gunakan hash_equals untuk mitigasi timing attack
        if (!hash_equals($expectedSignature, $actualSignature)) {
            throw new Exception('Signature token tidak valid');
        }

        // 3. Decode Payload
        $payloadJson = self::base64UrlDecode($payloadEncoded);
        $payload = json_decode($payloadJson, true);
        if (!is_array($payload)) {
            throw new Exception('Payload token tidak valid');
        }

        // 4. Verifikasi Expiration (exp)
        if (isset($payload['exp']) && is_numeric($payload['exp'])) {
            if (time() >= (int)$payload['exp']) {
                throw new Exception('Token telah kedaluwarsa');
            }
        }

        return $payload;
    }

    /**
     * Helper: Base64Url Encode (RFC 7515).
     */
    public static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Helper: Base64Url Decode (RFC 7515).
     */
    public static function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder !== 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        return (string)base64_decode(strtr($data, '-_', '+/'));
    }
}
