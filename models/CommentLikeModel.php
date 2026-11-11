<?php

/**
 * models/CommentLikeModel.php
 * ─────────────────────────────────────────────────────────────────────────────
 * COMMENT LIKE MODEL (Data Access Layer)
 *
 * Bertanggung jawab terhadap operasi database untuk tabel `comment_likes`
 * menggunakan PDO Prepared Statements. Modelled exactly on LikeModel
 * (one row per (user, comment), UNIQUE(user_id, comment_id)).
 * Positional `?` placeholders only — PDO MySQL native prepares forbid
 * reusing a named parameter.
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

require_once BASE_PATH . '/config/database.php';

class CommentLikeModel
{
    /**
     * Mencari like berdasarkan comment_id dan user_id.
     *
     * @param  string $commentId UUID comment
     * @param  string $userId UUID user
     * @return array<string, mixed>|null Record like atau null jika tidak ditemukan
     */
    public static function findLike(string $commentId, string $userId): ?array
    {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT * FROM comment_likes WHERE comment_id = ? AND user_id = ? LIMIT 1");
        $stmt->execute([$commentId, $userId]);
        $like = $stmt->fetch();
        return $like ?: null;
    }

    /**
     * Membuat like baru untuk comment oleh user.
     * Menggunakan UUID v4 dan memanfaatkan UNIQUE KEY (user_id, comment_id).
     *
     * @param  string $commentId UUID comment
     * @param  string $userId UUID user
     * @param  string|null $id Optional UUID jika sudah ditentukan
     * @return bool True jika berhasil dibuat
     */
    public static function createLike(string $commentId, string $userId, ?string $id = null): bool
    {
        $pdo = getDB();
        $id = $id ?? self::generateUuid();

        $stmt = $pdo->prepare("
            INSERT INTO comment_likes (id, comment_id, user_id)
            VALUES (?, ?, ?)
        ");

        return $stmt->execute([$id, $commentId, $userId]);
    }

    /**
     * Menghapus like spesifik milik user pada comment tertentu.
     *
     * @param  string $commentId UUID comment
     * @param  string $userId UUID user
     * @return bool True jika query berhasil dieksekusi
     */
    public static function deleteLike(string $commentId, string $userId): bool
    {
        $pdo = getDB();
        $stmt = $pdo->prepare("DELETE FROM comment_likes WHERE comment_id = ? AND user_id = ?");
        return $stmt->execute([$commentId, $userId]);
    }

    /**
     * Menghitung total like pada sebuah comment.
     *
     * @param  string $commentId UUID comment
     * @return int
     */
    public static function countByCommentId(string $commentId): int
    {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM comment_likes WHERE comment_id = ?");
        $stmt->execute([$commentId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Mengecek apakah user sudah like comment tertentu.
     *
     * @param  string $commentId UUID comment
     * @param  string $userId UUID user
     * @return bool
     */
    public static function isLikedBy(string $commentId, string $userId): bool
    {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT 1 FROM comment_likes WHERE comment_id = ? AND user_id = ? LIMIT 1");
        $stmt->execute([$commentId, $userId]);
        return (bool)$stmt->fetchColumn();
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
