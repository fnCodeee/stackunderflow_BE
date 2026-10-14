<?php

/**
 * models/LikeModel.php
 * ─────────────────────────────────────────────────────────────────────────────
 * LIKE MODEL (Data Access Layer)
 *
 * Bertanggung jawab terhadap operasi database untuk tabel `like_posts`
 * menggunakan PDO Prepared Statements.
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

require_once BASE_PATH . '/config/database.php';

class LikeModel
{
    /**
     * Mencari like berdasarkan post_id dan user_id.
     *
     * @param  string $postId UUID post
     * @param  string $userId UUID user
     * @return array<string, mixed>|null Record like atau null jika tidak ditemukan
     */
    public static function findLike(string $postId, string $userId): ?array
    {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT * FROM like_posts WHERE post_id = :post_id AND user_id = :user_id LIMIT 1");
        $stmt->execute([
            ':post_id' => $postId,
            ':user_id' => $userId,
        ]);
        $like = $stmt->fetch();
        return $like ?: null;
    }

    /**
     * Membuat like baru untuk post oleh user.
     * Menggunakan UUID v4 dan prepared statement untuk menghindari race condition
     * dan memanfaatkan UNIQUE KEY (user_id, post_id).
     *
     * @param  string $postId UUID post
     * @param  string $userId UUID user
     * @param  string|null $id Optional UUID jika sudah ditentukan
     * @return bool True jika berhasil dibuat
     */
    public static function createLike(string $postId, string $userId, ?string $id = null): bool
    {
        $pdo = getDB();
        $id = $id ?? self::generateUuid();

        $stmt = $pdo->prepare("
            INSERT INTO like_posts (id, post_id, user_id)
            VALUES (:id, :post_id, :user_id)
        ");

        return $stmt->execute([
            ':id'      => $id,
            ':post_id' => $postId,
            ':user_id' => $userId,
        ]);
    }

    /**
     * Menghapus like spesifik milik user pada post tertentu.
     *
     * @param  string $postId UUID post
     * @param  string $userId UUID user
     * @return bool True jika query berhasil dieksekusi
     */
    public static function deleteLike(string $postId, string $userId): bool
    {
        $pdo = getDB();
        $stmt = $pdo->prepare("DELETE FROM like_posts WHERE post_id = :post_id AND user_id = :user_id");
        return $stmt->execute([
            ':post_id' => $postId,
            ':user_id' => $userId,
        ]);
    }

    /**
     * Menghitung total like pada sebuah post.
     *
     * @param  string $postId UUID post
     * @return int
     */
    public static function countByPostId(string $postId): int
    {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM like_posts WHERE post_id = :post_id");
        $stmt->execute([':post_id' => $postId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Mengecek apakah user sudah like post tertentu.
     *
     * @param  string $postId UUID post
     * @param  string $userId UUID user
     * @return bool
     */
    public static function isLikedBy(string $postId, string $userId): bool
    {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT 1 FROM like_posts WHERE post_id = :post_id AND user_id = :user_id LIMIT 1");
        $stmt->execute([
            ':post_id' => $postId,
            ':user_id' => $userId,
        ]);
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
