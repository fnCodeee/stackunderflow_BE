<?php

/**
 * utils/Realtime.php
 * ─────────────────────────────────────────────────────────────────────────────
 * REALTIME PUBLISHER — mengirim event ke server WebSocket underflow-realtime
 * (POST /publish di 127.0.0.1:8080) supaya notifikasi + angka like/komen
 * sampai ke browser secara instan, tanpa menunggu polling 5 detik.
 *
 * Best-effort SEMATA: setiap publish dibungkus try/catch + timeout pendek
 * (connect 200ms, total 500ms). Kalau server realtime mati / tidak
 * terjangkau, like/komen tetap berhasil — pengiriman lalu dicover oleh
 * polling 5 detik di frontend. Fungsi-fungsi di sini TIDAK PERNAH throw.
 *
 * URL publish default http://127.0.0.1:8080/publish, bisa dioverride
 * lewat env REALTIME_URL (di .env).
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

class Realtime
{
    /** URL endpoint /publish server realtime. */
    private static function publishUrl(): string
    {
        $url = getenv('REALTIME_URL') ?: '';
        return $url !== '' ? $url : 'http://127.0.0.1:8080/publish';
    }

    /**
     * POST satu event ke server realtime. Tidak pernah throw.
     *
     * @param  array<string, mixed> $event Bentuk: ['type' => ..., 'userId'|'postId' => ..., 'payload' => [...]]
     * @return void
     */
    public static function publish(array $event): void
    {
        try {
            $ch = curl_init(self::publishUrl());
            if ($ch === false) {
                return;
            }
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($event),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT_MS => 200,
                CURLOPT_TIMEOUT_MS => 500,
            ]);
            curl_exec($ch);
            curl_close($ch);
        } catch (Throwable) {
            // Best-effort: publish gagal = polling yang meng-cover.
        }
    }

    /**
     * Push notifikasi baru ke semua socket milik penerima.
     *
     * @param  string $userId UUID penerima notifikasi (pemilik post)
     * @param  array<string, mixed> $apiRow Baris notifikasi bentuk API (NotificationModel::findApiRow)
     * @return void
     */
    public static function publishNotification(string $userId, array $apiRow): void
    {
        self::publish([
            'type' => 'notification',
            'userId' => $userId,
            'payload' => ['notification' => $apiRow],
        ]);
    }

    /**
     * Beritahu socket penerima bahwa satu notifikasi dihapus
     * (like dibatalkan / komen dihapus) — notifikasinya hilang live.
     *
     * @param  string $userId UUID penerima notifikasi
     * @param  string $notificationId UUID notifikasi yang dihapus
     * @return void
     */
    public static function publishNotificationRemoved(string $userId, string $notificationId): void
    {
        self::publish([
            'type' => 'notification-removed',
            'userId' => $userId,
            'payload' => ['notificationId' => $notificationId],
        ]);
    }

    /**
     * Broadcast angka like/komen terbaru sebuah post ke semua socket
     * yang terhubung — angka di feed naik live.
     *
     * @param  string $postId UUID post
     * @param  int    $likes Jumlah like saat ini
     * @param  int    $commentCount Jumlah komen saat ini (tanpa soft-deleted)
     * @return void
     */
    public static function publishPostStats(string $postId, int $likes, int $commentCount): void
    {
        self::publish([
            'type' => 'post-stats',
            'postId' => $postId,
            'payload' => ['likes' => $likes, 'commentCount' => $commentCount],
        ]);
    }
}
