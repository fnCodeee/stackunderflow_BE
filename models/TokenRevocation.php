<?php

/**
 * models/TokenRevocation.php
 * ─────────────────────────────────────────────────────────────────────────────
 * REVOKASI JWT (audit P0, 2026-11-14)
 *
 * JWT bersifat stateless, jadi logout/refresh butuh daftar cabut:
 * hash SHA-256 token disimpan di tabel `revoked_tokens` sampai masa
 * berlaku tokennya habis. AuthMiddleware menolak token yang hash-nya
 * ada di tabel ini. Baris kedaluwarsa dibersihkan secara oportunistik
 * setiap kali ada token baru yang dicabut.
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

require_once BASE_PATH . '/utils/JWT.php';

class TokenRevocation
{
    /** Cabut token sampai masa berlakunya (claim exp) habis. */
    public static function revoke(string $token): void
    {
        try {
            $payload = JWT::decode($token);
            $exp = isset($payload['exp']) && is_numeric($payload['exp']) ? (int) $payload['exp'] : 0;
            if ($exp <= time()) {
                return; // Sudah kedaluwarsa: tidak perlu dicabut.
            }
            $pdo = getDB();
            $stmt = $pdo->prepare(
                'INSERT IGNORE INTO revoked_tokens (token_hash, expires_at) VALUES (?, FROM_UNIXTIME(?))'
            );
            $stmt->execute([hash('sha256', $token), $exp]);
            // Pembersihan oportunistik baris yang sudah lewat.
            $pdo->query('DELETE FROM revoked_tokens WHERE expires_at <= NOW()');
        } catch (Throwable $e) {
            error_log('[TOKEN REVOCATION] revoke failed: ' . $e->getMessage());
        }
    }

    /** True bila token sudah dicabut (logout / sudah di-refresh). */
    public static function isRevoked(string $token): bool
    {
        try {
            $stmt = getDB()->prepare(
                'SELECT 1 FROM revoked_tokens WHERE token_hash = ? AND expires_at > NOW() LIMIT 1'
            );
            $stmt->execute([hash('sha256', $token)]);
            return $stmt->fetchColumn() !== false;
        } catch (Throwable $e) {
            error_log('[TOKEN REVOCATION] check failed: ' . $e->getMessage());
            return false;
        }
    }
}
