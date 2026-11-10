<?php

/**
 * models/NotificationModel.php
 * ─────────────────────────────────────────────────────────────────────────────
 * NOTIFICATION MODEL (Data Access Layer)
 *
 * Bertanggung jawab terhadap operasi database untuk tabel `notifications`
 * menggunakan PDO Prepared Statements (positional `?` — native prepares
 * aktif, jadi named parameter tidak boleh dipakai ulang dalam satu query).
 *
 * Aturan bisnis:
 *  - Satu akun tidak pernah mendapat notifikasi dari dirinya sendiri
 *    (like/komen di post sendiri tidak menghasilkan notifikasi).
 *  - Satu like = maksimal satu notifikasi per actor+post (dedupe); saat
 *    like dibatalkan, notifikasinya dihapus. Begitu juga komen: hapus
 *    komen = hapus notifikasinya.
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

require_once BASE_PATH . '/config/database.php';

class NotificationModel
{
    /** Panjang maksimal body notifikasi (judul post / isi komen dipotong). */
    private const BODY_LIMIT = 120;

    /**
     * Potong teks ke limit; tambah "..." hanya kalau benar-benar dipotong.
     */
    private static function truncate(string $text, int $limit = self::BODY_LIMIT): string
    {
        if (mb_strlen($text) <= $limit) {
            return $text;
        }
        return mb_substr($text, 0, $limit - 3) . '...';
    }

    /**
     * Ambil satu baris notifikasi berdasarkan id (untuk dikembalikan
     * setelah insert).
     */
    private static function findById(string $id): ?array
    {
        $pdo = getDB();
        $stmt = $pdo->prepare('SELECT * FROM notifications WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Buat notifikasi like untuk pemilik post. Mengembalikan null kalau
     * actor == pemilik (tidak ada notifikasi untuk diri sendiri). Kalau
     * notifikasi like dari actor ini untuk post ini sudah ada, kembalikan
     * baris itu (tidak ada duplikat).
     *
     * @param  string $postId  UUID post yang di-like
     * @param  string $ownerId UUID pemilik post (penerima notifikasi)
     * @param  string $actorId UUID user yang me-like
     * @return array<string, mixed>|null Baris notifikasi baru / yang sudah ada, atau null
     */
    public static function createLikeNotification(string $postId, string $ownerId, string $actorId): ?array
    {
        if ($actorId === $ownerId) {
            return null;
        }

        $pdo = getDB();

        // Dedupe: satu like = satu notifikasi per actor+post.
        $stmt = $pdo->prepare("SELECT * FROM notifications WHERE actor_id = ? AND target_id = ? AND type = 'like' LIMIT 1");
        $stmt->execute([$actorId, $postId]);
        $existing = $stmt->fetch();
        if ($existing) {
            return $existing;
        }

        $stmt = $pdo->prepare('SELECT username FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$actorId]);
        $actorName = (string)($stmt->fetchColumn() ?: 'Someone');

        $stmt = $pdo->prepare('SELECT title FROM public_posts WHERE id = ? LIMIT 1');
        $stmt->execute([$postId]);
        $postTitle = (string)($stmt->fetchColumn() ?: '');

        $id = self::generateUuid();
        $stmt = $pdo->prepare("
            INSERT INTO notifications (id, user_id, actor_id, type, title, body, target_type, target_id)
            VALUES (?, ?, ?, 'like', ?, ?, 'post', ?)
        ");
        $stmt->execute([
            $id,
            $ownerId,
            $actorId,
            $actorName . ' liked your post',
            self::truncate($postTitle),
            $postId,
        ]);

        return self::findById($id);
    }

    /**
     * Hapus notifikasi like milik actor untuk sebuah post (dipanggil saat
     * like dibatalkan). Tidak ada baris yang cocok = tidak ada efek.
     */
    public static function deleteLikeNotification(string $postId, string $actorId): void
    {
        $pdo = getDB();
        $stmt = $pdo->prepare("DELETE FROM notifications WHERE actor_id = ? AND target_id = ? AND type = 'like'");
        $stmt->execute([$actorId, $postId]);
    }

    /**
     * Buat notifikasi komen untuk pemilik post. Mengembalikan null kalau
     * actor == pemilik. target_id adalah id KOMEN (bukan post), supaya
     * penghapusan komen bisa menghapus tepat notifikasi itu.
     *
     * @param  string $postId    UUID post yang dikomentari
     * @param  string $commentId UUID komen yang baru dibuat
     * @param  string $ownerId   UUID pemilik post (penerima notifikasi)
     * @param  string $actorId   UUID user yang berkomentar
     * @param  string $content   Isi komen (dipakai sebagai body, dipotong)
     * @return array<string, mixed>|null Baris notifikasi baru, atau null
     */
    public static function createCommentNotification(string $postId, string $commentId, string $ownerId, string $actorId, string $content): ?array
    {
        if ($actorId === $ownerId) {
            return null;
        }

        $pdo = getDB();

        $stmt = $pdo->prepare('SELECT username FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$actorId]);
        $actorName = (string)($stmt->fetchColumn() ?: 'Someone');

        $id = self::generateUuid();
        $stmt = $pdo->prepare("
            INSERT INTO notifications (id, user_id, actor_id, type, title, body, target_type, target_id)
            VALUES (?, ?, ?, 'comment', ?, ?, 'comment', ?)
        ");
        $stmt->execute([
            $id,
            $ownerId,
            $actorId,
            $actorName . ' commented on your post',
            self::truncate($content),
            $commentId,
        ]);

        return self::findById($id);
    }

    /**
     * Hapus notifikasi komen berdasarkan id komennya (dipanggil saat
     * komen dihapus). Tidak ada baris yang cocok = tidak ada efek.
     */
    public static function deleteCommentNotification(string $commentId): void
    {
        $pdo = getDB();
        $stmt = $pdo->prepare("DELETE FROM notifications WHERE target_id = ? AND type = 'comment'");
        $stmt->execute([$commentId]);
    }

    /**
     * Ambil notifikasi milik user, terbaru dulu, beserta nama + foto
     * actor dan id + judul post yang dituju (untuk type 'like' postId =
     * target_id; untuk type 'comment' postId diambil dari tabel comments).
     *
     * @param  string $userId UUID penerima notifikasi
     * @param  int    $limit  Maksimal baris (default 50)
     * @return array<int, array<string, mixed>> Baris notifikasi
     */
    public static function getForUser(string $userId, int $limit = 50): array
    {
        $pdo = getDB();
        $stmt = $pdo->prepare("
            SELECT
                n.id, n.type, n.title, n.body, n.is_read, n.created_at,
                n.actor_id, n.target_id,
                a.username AS actor_username,
                pr.photo_profile_url AS actor_photo,
                CASE WHEN n.type = 'like' THEN n.target_id ELSE c.post_id END AS post_id,
                p.title AS post_title
            FROM notifications n
            LEFT JOIN users a ON a.id = n.actor_id
            LEFT JOIN profiles pr ON pr.user_id = n.actor_id
            LEFT JOIN comments c ON c.id = n.target_id AND n.type = 'comment'
            LEFT JOIN public_posts p ON p.id = CASE WHEN n.type = 'like' THEN n.target_id ELSE c.post_id END
            WHERE n.user_id = ?
            ORDER BY n.created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$userId, $limit]);
        return $stmt->fetchAll();
    }

    /**
     * Tandai satu notifikasi sebagai dibaca. Hanya berhasil kalau
     * notifikasi itu milik user tersebut.
     *
     * @return bool True kalau ada baris yang berubah
     */
    public static function markRead(string $id, string $userId): bool
    {
        $pdo = getDB();
        $stmt = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Tandai semua notifikasi user yang belum dibaca sebagai dibaca.
     *
     * @return int Jumlah baris yang berubah
     */
    public static function markAllRead(string $userId): int
    {
        $pdo = getDB();
        $stmt = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0');
        $stmt->execute([$userId]);
        return $stmt->rowCount();
    }

    /**
     * Helper: Generate UUID v4 standar (RFC 4122).
     *
     * @return string
     */
    public static function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // versi 4
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // variant RFC 4122
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
