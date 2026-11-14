<?php

/**
 * utils/RateLimiter.php
 * ─────────────────────────────────────────────────────────────────────────────
 * RATE LIMITER SEDERHANA (audit P0, 2026-11-14)
 *
 * Fixed-window counter per bucket yang disimpan di tabel `rate_limits`.
 * Dipakai untuk memperlambat brute force di endpoint sensitif:
 * login, register, Google sign-in, dan banding akun tersuspend.
 *
 * API:
 *   RateLimiter::blocked($bucket)          -> true bila bucket sedang diblokir
 *   RateLimiter::hit($bucket, $max, $win)  -> catat 1 percobaan; false bila
 *                                             percobaan ini melewati batas
 *   RateLimiter::clear($bucket)            -> reset bucket (mis. login sukses)
 *
 * Semua method fail-open: kalau tabel/DB bermasalah, request tetap
 * dilayani (error dicatat ke log) — rate limiter tidak boleh menjadi
 * penyebab API tumbang.
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

class RateLimiter
{
    /** IP klien langsung (di belakang proxy, REMOTE_ADDR = proxy). */
    public static function clientIp(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    }

    /** True bila bucket masih di dalam masa blokir. */
    public static function blocked(string $bucket): bool
    {
        try {
            $row = self::row($bucket);
            return $row !== null && (int) $row['blocked_until'] > time();
        } catch (Throwable $e) {
            error_log('[RATE LIMIT] blocked check failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Catat satu percobaan ke bucket. Mengembalikan false bila jumlah
     * percobaan dalam window melewati $maxAttempts (bucket diblokir
     * sampai window berakhir).
     */
    public static function hit(string $bucket, int $maxAttempts, int $windowSeconds): bool
    {
        try {
            $pdo = getDB();
            $now = time();
            $row = self::row($bucket);

            if ($row === null) {
                $stmt = $pdo->prepare(
                    'INSERT INTO rate_limits (bucket_key, attempts, window_start, blocked_until) VALUES (?, 1, ?, 0)'
                );
                $stmt->execute([$bucket, $now]);
                return true;
            }

            $windowStart = (int) $row['window_start'];
            if ($now - $windowStart >= $windowSeconds) {
                // Window lama sudah lewat: mulai window baru.
                $stmt = $pdo->prepare(
                    'UPDATE rate_limits SET attempts = 1, window_start = ?, blocked_until = 0 WHERE bucket_key = ?'
                );
                $stmt->execute([$now, $bucket]);
                return true;
            }

            $attempts = (int) $row['attempts'] + 1;
            $blockedUntil = $attempts > $maxAttempts ? $windowStart + $windowSeconds : (int) $row['blocked_until'];
            $stmt = $pdo->prepare(
                'UPDATE rate_limits SET attempts = ?, blocked_until = ? WHERE bucket_key = ?'
            );
            $stmt->execute([$attempts, $blockedUntil, $bucket]);
            return $attempts <= $maxAttempts;
        } catch (Throwable $e) {
            error_log('[RATE LIMIT] hit failed: ' . $e->getMessage());
            return true;
        }
    }

    /** Reset bucket (mis. setelah login berhasil). */
    public static function clear(string $bucket): void
    {
        try {
            $stmt = getDB()->prepare('DELETE FROM rate_limits WHERE bucket_key = ?');
            $stmt->execute([$bucket]);
        } catch (Throwable $e) {
            error_log('[RATE LIMIT] clear failed: ' . $e->getMessage());
        }
    }

    /** @return array<string, mixed>|null */
    private static function row(string $bucket): ?array
    {
        $stmt = getDB()->prepare(
            'SELECT bucket_key, attempts, window_start, blocked_until FROM rate_limits WHERE bucket_key = ? LIMIT 1'
        );
        $stmt->execute([$bucket]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
