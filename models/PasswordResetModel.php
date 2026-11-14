<?php

/**
 * models/PasswordResetModel.php
 * ─────────────────────────────────────────────────────────────────────────────
 * TOKEN RESET PASSWORD (2026-11-14)
 *
 * Token mentah (64 hex dari 32 byte acak) hanya ada di email user;
 * database menyimpan hash SHA-256-nya. Token berlaku 30 menit,
 * sekali pakai, dan membuat token baru menghanguskan token lama
 * milik user yang sama yang belum terpakai.
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

class PasswordResetModel
{
    private const TTL_SECONDS = 1800; // 30 menit

    /** Buat token baru untuk user; mengembalikan token mentahnya. */
    public static function create(string $userId): string
    {
        $pdo = getDB();
        $stmt = $pdo->prepare('DELETE FROM password_resets WHERE user_id = ? AND used_at IS NULL');
        $stmt->execute([$userId]);

        $token = bin2hex(random_bytes(32));
        $stmt = $pdo->prepare(
            'INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            hash('sha256', $token),
            date('Y-m-d H:i:s', time() + self::TTL_SECONDS),
        ]);
        return $token;
    }

    /** Baris token yang masih berlaku untuk token mentah ini, atau null. */
    public static function findValid(string $token): ?array
    {
        $stmt = getDB()->prepare(
            'SELECT * FROM password_resets WHERE token_hash = ? AND used_at IS NULL AND expires_at > ? LIMIT 1'
        );
        $stmt->execute([hash('sha256', $token), date('Y-m-d H:i:s')]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Tandai token sudah dipakai + hanguskan token lain milik user. */
    public static function consume(array $row): void
    {
        $pdo = getDB();
        $stmt = $pdo->prepare('UPDATE password_resets SET used_at = ? WHERE id = ?');
        $stmt->execute([date('Y-m-d H:i:s'), $row['id']]);
        $stmt = $pdo->prepare('DELETE FROM password_resets WHERE user_id = ? AND used_at IS NULL');
        $stmt->execute([$row['user_id']]);
    }
}
