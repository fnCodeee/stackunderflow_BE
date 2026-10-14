<?php

/**
 * models/CommentModel.php
 * ─────────────────────────────────────────────────────────────────────────────
 * COMMENT MODEL (Data Access Layer)
 *
 * Bertanggung jawab terhadap operasi database untuk tabel `comments`
 * menggunakan PDO Prepared Statements.
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/models/ProfileModel.php';

class CommentModel
{
    /**
     * Membuat komentar baru pada postingan.
     *
     * @param  string $postId  UUID post
     * @param  string $userId  UUID user
     * @param  string $content Konten komentar
     * @param  string|null $id Optional UUID jika sudah ditentukan
     * @return array<string, mixed> Data komentar lengkap dengan relasi user
     */
    public static function create(string $postId, string $userId, string $content, ?string $id = null): array
    {
        $pdo = getDB();
        $id = $id ?? self::generateUuid();

        $stmt = $pdo->prepare("
            INSERT INTO comments (id, user_id, post_id, content)
            VALUES (:id, :user_id, :post_id, :content)
        ");

        $stmt->execute([
            ':id'      => $id,
            ':user_id' => $userId,
            ':post_id' => $postId,
            ':content' => $content,
        ]);

        return self::findDetailById($id) ?? [
            '_id'        => $id,
            'id'         => $id,
            'userId'     => [
                '_id'               => $userId,
                'id'                => $userId,
                'username'          => '',
                'photo_profile_url' => ProfileModel::DEFAULT_PHOTO_URL,
            ],
            'postId'     => $postId,
            'post_id'    => $postId,
            'content'    => $content,
            'createdAt'  => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
            'updatedAt'  => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Mengambil detail komentar berdasarkan ID lengkap dengan username dan photo profile.
     *
     * @param  string $commentId UUID komentar
     * @return array<string, mixed>|null
     */
    public static function findDetailById(string $commentId): ?array
    {
        $pdo = getDB();
        $sql = "
            SELECT 
                c.id,
                c.user_id,
                c.post_id,
                c.content,
                c.created_at,
                c.updated_at,
                u.username,
                pr.photo_profile_url
            FROM comments c
            LEFT JOIN users u ON c.user_id = u.id
            LEFT JOIN profiles pr ON c.user_id = pr.user_id
            WHERE c.id = :id
            LIMIT 1
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':id' => $commentId]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        $photoUrl = $row['photo_profile_url'] ?? ProfileModel::DEFAULT_PHOTO_URL;

        return [
            '_id'        => $row['id'],
            'id'         => $row['id'],
            'userId'     => [
                '_id'               => $row['user_id'],
                'id'                => $row['user_id'],
                'username'          => $row['username'] ?? '',
                'photo_profile_url' => $photoUrl,
            ],
            'user'       => [
                'id'                => $row['user_id'],
                'username'          => $row['username'] ?? '',
                'photo_profile_url' => $photoUrl,
            ],
            'postId'     => $row['post_id'],
            'post_id'    => $row['post_id'],
            'content'    => $row['content'],
            'createdAt'  => $row['created_at'],
            'created_at' => $row['created_at'],
            'updatedAt'  => $row['updated_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    /**
     * Mencari raw komentar berdasarkan ID (tanpa join, untuk pengecekan ownership / testing).
     *
     * @param  string $commentId UUID komentar
     * @return array<string, mixed>|null
     */
    public static function findById(string $commentId): ?array
    {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT * FROM comments WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $commentId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Mengambil seluruh komentar pada sebuah postingan, diurutkan DESC (terbaru lebih dulu).
     *
     * @param  string $postId UUID post
     * @return array<int, array<string, mixed>>
     */
    public static function getByPostId(string $postId): array
    {
        $pdo = getDB();
        $sql = "
            SELECT 
                c.id,
                c.user_id,
                c.post_id,
                c.content,
                c.created_at,
                c.updated_at,
                u.username,
                pr.photo_profile_url
            FROM comments c
            LEFT JOIN users u ON c.user_id = u.id
            LEFT JOIN profiles pr ON c.user_id = pr.user_id
            WHERE c.post_id = :post_id
            ORDER BY c.created_at DESC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':post_id' => $postId]);
        $rows = $stmt->fetchAll();

        $comments = [];
        foreach ($rows as $row) {
            $photoUrl = $row['photo_profile_url'] ?? ProfileModel::DEFAULT_PHOTO_URL;
            $comments[] = [
                '_id'        => $row['id'],
                'id'         => $row['id'],
                'userId'     => [
                    '_id'               => $row['user_id'],
                    'id'                => $row['user_id'],
                    'username'          => $row['username'] ?? '',
                    'photo_profile_url' => $photoUrl,
                ],
                'user'       => [
                    'id'                => $row['user_id'],
                    'username'          => $row['username'] ?? '',
                    'photo_profile_url' => $photoUrl,
                ],
                'postId'     => $row['post_id'],
                'post_id'    => $row['post_id'],
                'content'    => $row['content'],
                'createdAt'  => $row['created_at'],
                'created_at' => $row['created_at'],
                'updatedAt'  => $row['updated_at'],
                'updated_at' => $row['updated_at'],
            ];
        }

        return $comments;
    }

    /**
     * Menghapus komentar berdasarkan ID.
     *
     * @param  string $commentId UUID komentar
     * @return bool
     */
    public static function delete(string $commentId): bool
    {
        $pdo = getDB();
        $stmt = $pdo->prepare("DELETE FROM comments WHERE id = :id");
        return $stmt->execute([':id' => $commentId]);
    }

    /**
     * Menghitung total komentar pada sebuah post.
     *
     * @param  string $postId UUID post
     * @return int
     */
    public static function countByPostId(string $postId): int
    {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM comments WHERE post_id = :post_id");
        $stmt->execute([':post_id' => $postId]);
        return (int)$stmt->fetchColumn();
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
