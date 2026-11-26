<?php

/**
 * models/PendingRegistrationModel.php
 * ─────────────────────────────────────────────────────────────────────────────
 * PENDAFTARAN PENDING (skema verifikasi keras, 2026-11-16)
 *
 * Pendaftaran TIDAK lagi membuat baris di tabel users. Data menunggu
 * di tabel pending_registrations sampai link verifikasi di email
 * diklik; klik itulah yang membuat akun beneran (users + profiles).
 * Konsekuensinya:
 * - Email tidak terkirim -> pendaftaran batal, baris pending dihapus.
 * - Link tidak diklik dalam 24 jam -> pendaftaran hangus, username
 *   dan email bebas dipakai lagi.
 * Password disimpan sebagai hash bcrypt (sama seperti di users);
 * token hanya disimpan hash SHA-256-nya (pola PasswordResetModel).
 * Semua perbandingan waktu memakai string tanggal PHP (satu jam).
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

class PendingRegistrationModel
{
    private const TTL_SECONDS = 86400; // 24 jam

    private static function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100 (v4)
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    public static function findByUsername(string $username): ?array
    {
        $stmt = getDB()->prepare('SELECT * FROM pending_registrations WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function findByEmail(string $email): ?array
    {
        $stmt = getDB()->prepare('SELECT * FROM pending_registrations WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function findById(string $id): ?array
    {
        $stmt = getDB()->prepare('SELECT * FROM pending_registrations WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Baris pending yang token mentahnya cocok dan belum kedaluwarsa. */
    public static function findValidByToken(string $token): ?array
    {
        $stmt = getDB()->prepare(
            'SELECT * FROM pending_registrations WHERE token_hash = ? AND expires_at > ? LIMIT 1'
        );
        $stmt->execute([hash('sha256', $token), date('Y-m-d H:i:s')]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function isExpired(array $row): bool
    {
        return (string) ($row['expires_at'] ?? '') <= date('Y-m-d H:i:s');
    }

    /**
     * Buat baris pending baru.
     * Mengembalikan [baris, tokenMentah] — token mentah hanya boleh
     * hidup di email user, tidak pernah disimpan.
     */
    public static function create(string $username, string $email, string $passwordHash): array
    {
        $id = self::uuid();
        $token = bin2hex(random_bytes(32));
        $stmt = getDB()->prepare(
            'INSERT INTO pending_registrations (id, username, email, password_hash, token_hash, expires_at, terms_accepted_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id,
            $username,
            $email,
            $passwordHash,
            hash('sha256', $token),
            date('Y-m-d H:i:s', time() + self::TTL_SECONDS),
            date('Y-m-d H:i:s'),
        ]);
        return [self::findById($id), $token];
    }

    /**
     * Perbarui baris pending (daftar ulang dengan identitas yang
     * sama): hash password diganti dengan yang baru diketik, token
     * dirotasi, masa berlaku dimulai lagi dari awal.
     * Mengembalikan token mentah baru.
     */
    public static function refresh(array $row, string $passwordHash): string
    {
        $token = bin2hex(random_bytes(32));
        $stmt = getDB()->prepare(
            'UPDATE pending_registrations SET password_hash = ?, token_hash = ?, expires_at = ?, terms_accepted_at = ? WHERE id = ?'
        );
        $stmt->execute([
            $passwordHash,
            hash('sha256', $token),
            date('Y-m-d H:i:s', time() + self::TTL_SECONDS),
            date('Y-m-d H:i:s'),
            $row['id'],
        ]);
        return $token;
    }

    /** Rotasi token saja (kirim ulang); masa berlaku dimulai lagi. */
    public static function rotateToken(array $row): string
    {
        $token = bin2hex(random_bytes(32));
        $stmt = getDB()->prepare(
            'UPDATE pending_registrations SET token_hash = ?, expires_at = ? WHERE id = ?'
        );
        $stmt->execute([
            hash('sha256', $token),
            date('Y-m-d H:i:s', time() + self::TTL_SECONDS),
            $row['id'],
        ]);
        return $token;
    }

    public static function deleteById(string $id): void
    {
        $stmt = getDB()->prepare('DELETE FROM pending_registrations WHERE id = ?');
        $stmt->execute([$id]);
    }
}
