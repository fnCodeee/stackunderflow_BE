<?php

/**
 * models/PostModel.php
 * ─────────────────────────────────────────────────────────────────────────────
 * POST MODEL (Data Access Layer)
 *
 * Bertanggung jawab terhadap operasi database untuk tabel `public_posts`
 * dan tabel relasi `media_posts` menggunakan PDO Prepared Statements.
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

require_once BASE_PATH . '/config/database.php';

class PostModel
{
    /**
     * Membuat record post baru beserta media_posts di dalam 1 transaksi database.
     *
     * @param  array{
     *     user_id: string,
     *     title: string,
     *     description: string,
     *     portfolio_type?: string,
     *     external_url?: ?string,
     *     media_urls: array<int, string>,
     *     media_public_ids?: array<int, ?string>,
     *     id?: string
     * } $data
     * @return array<string, mixed> Data post yang baru dibuat
     */
    public static function create(array $data): array
    {
        $pdo = getDB();

        $id = $data['id'] ?? self::generateUuid();
        $userId = $data['user_id'];
        $title = $data['title'];
        $description = $data['description'];
        $portfolioType = $data['portfolio_type'] ?? 'link';
        $externalUrl = $data['external_url'] ?? null;
        $mediaUrls = $data['media_urls'] ?? [];
        $mediaPublicIds = $data['media_public_ids'] ?? [];

        // Jalankan operasi dalam PDO Transaction
        $pdo->beginTransaction();

        try {
            // 1. Insert ke tabel public_posts
            $stmt = $pdo->prepare(
                "INSERT INTO public_posts (id, user_id, title, description, portfolio_type, external_url, view_count)
                 VALUES (:id, :user_id, :title, :description, :portfolio_type, :external_url, 0)"
            );

            $stmt->execute([
                ':id'             => $id,
                ':user_id'        => $userId,
                ':title'          => $title,
                ':description'    => $description,
                ':portfolio_type' => $portfolioType,
                ':external_url'   => $externalUrl,
            ]);

            // 2. Insert media ke tabel media_posts
            if (!empty($mediaUrls)) {
                $mediaStmt = $pdo->prepare(
                    "INSERT INTO media_posts (id, post_id, media_url, media_public_id)
                     VALUES (:id, :post_id, :media_url, :media_public_id)"
                );

                foreach ($mediaUrls as $index => $mediaUrl) {
                    $mediaId = self::generateUuid();
                    $publicId = $mediaPublicIds[$index] ?? null;

                    $mediaStmt->execute([
                        ':id'              => $mediaId,
                        ':post_id'         => $id,
                        ':media_url'       => $mediaUrl,
                        ':media_public_id' => $publicId,
                    ]);
                }
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $created = self::findById($id);

        if ($created !== null) {
            return $created;
        }

        return [
            'id'               => $id,
            'user_id'          => $userId,
            'title'            => $title,
            'description'      => $description,
            'portfolio_type'   => $portfolioType,
            'external_url'     => $externalUrl,
            'media_urls'       => $mediaUrls,
            'media_public_ids' => $mediaPublicIds,
            'view_count'       => 0,
        ];
    }

    /**
     * Mencari post berdasarkan ID beserta relasi media_posts.
     *
     * @param  string $id UUID post
     * @return array<string, mixed>|null Associative array post atau null jika tidak ditemukan
     */
    public static function findById(string $id): ?array
    {
        $pdo = getDB();

        // 1. Ambil data utama post
        $stmt = $pdo->prepare("SELECT * FROM public_posts WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $post = $stmt->fetch();

        if (!$post) {
            return null;
        }

        // 2. Ambil media yang berelasi
        $mediaStmt = $pdo->prepare("SELECT media_url, media_public_id FROM media_posts WHERE post_id = :post_id ORDER BY created_at ASC");
        $mediaStmt->execute([':post_id' => $id]);
        $mediaRows = $mediaStmt->fetchAll();

        $mediaUrls = [];
        $mediaPublicIds = [];

        foreach ($mediaRows as $m) {
            $mediaUrls[] = $m['media_url'];
            if ($m['media_public_id'] !== null) {
                $mediaPublicIds[] = $m['media_public_id'];
            }
        }

        $post['media_urls'] = $mediaUrls;
        $post['media_public_ids'] = $mediaPublicIds;

        return $post;
    }

    /**
     * Menghapus post berdasarkan ID (berguna untuk testing/cleanup).
     *
     * @param  string $id
     * @return bool
     */
    public static function deleteById(string $id): bool
    {
        $pdo = getDB();
        $stmt = $pdo->prepare("DELETE FROM public_posts WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    /**
     * Mengambil semua post untuk showcase feed beserta relasi user, media, jumlah like, jumlah comment, dan status isLiked.
     * Diurutkan dari yang terbaru (created_at DESC).
     *
     * @param  string|null $currentUserId ID user yang sedang login (opsional) untuk mengecek is_liked
     * @return array<int, array<string, mixed>>
     */
    public static function getAll(?string $currentUserId = null): array
    {
        $pdo = getDB();

        // 1. Ambil seluruh data post digabung dengan users dan profiles
        $sql = "
            SELECT 
                p.id,
                p.user_id,
                p.title,
                p.description,
                p.portfolio_type,
                p.external_url,
                p.view_count,
                p.created_at,
                p.updated_at,
                u.username,
                pr.photo_profile_url
            FROM public_posts p
            LEFT JOIN users u ON p.user_id = u.id
            LEFT JOIN profiles pr ON p.user_id = pr.user_id
            ORDER BY p.created_at DESC
        ";

        $stmt = $pdo->query($sql);
        $posts = $stmt->fetchAll();

        if (empty($posts)) {
            return [];
        }

        // 2. Ambil seluruh media untuk posts ini
        $postIds = array_column($posts, 'id');
        $placeholders = implode(',', array_fill(0, count($postIds), '?'));

        $mediaStmt = $pdo->prepare("
            SELECT post_id, media_url, media_public_id 
            FROM media_posts 
            WHERE post_id IN ($placeholders) 
            ORDER BY created_at ASC
        ");
        $mediaStmt->execute($postIds);
        $mediaRows = $mediaStmt->fetchAll();

        $mediaMap = [];
        foreach ($mediaRows as $m) {
            $postId = $m['post_id'];
            if (!isset($mediaMap[$postId])) {
                $mediaMap[$postId] = ['urls' => [], 'public_ids' => []];
            }
            $mediaMap[$postId]['urls'][] = $m['media_url'];
            if ($m['media_public_id'] !== null) {
                $mediaMap[$postId]['public_ids'][] = $m['media_public_id'];
            }
        }

        // 3. Ambil total like_count per post
        $likeStmt = $pdo->prepare("
            SELECT post_id, COUNT(*) as count 
            FROM like_posts 
            WHERE post_id IN ($placeholders) 
            GROUP BY post_id
        ");
        $likeStmt->execute($postIds);
        $likeCountMap = $likeStmt->fetchAll(PDO::FETCH_KEY_PAIR);

        // 4. Ambil total comment_count per post
        $commentStmt = $pdo->prepare("
            SELECT post_id, COUNT(*) as count 
            FROM comments 
            WHERE post_id IN ($placeholders) 
            GROUP BY post_id
        ");
        $commentStmt->execute($postIds);
        $commentCountMap = $commentStmt->fetchAll(PDO::FETCH_KEY_PAIR);

        // 5. Cek is_liked untuk user yang sedang login jika ada
        $userLikedMap = [];
        if ($currentUserId !== null && $currentUserId !== '') {
            $userLikeStmt = $pdo->prepare("
                SELECT post_id 
                FROM like_posts 
                WHERE user_id = ? AND post_id IN ($placeholders)
            ");
            $userLikeStmt->execute(array_merge([$currentUserId], $postIds));
            $userLikedIds = $userLikeStmt->fetchAll(PDO::FETCH_COLUMN);
            $userLikedMap = array_flip($userLikedIds);
        }

        // 6. Susun response array
        $result = [];
        foreach ($posts as $p) {
            $postId = $p['id'];
            $result[] = [
                'id'               => $p['id'],
                'user_id'          => [
                    'id'                => $p['user_id'],
                    'username'          => $p['username'] ?? '',
                    'photo_profile_url' => $p['photo_profile_url'] ?? ProfileModel::DEFAULT_PHOTO_URL,
                ],
                'title'            => $p['title'],
                'description'      => $p['description'],
                'portfolio_type'   => $p['portfolio_type'],
                'external_url'     => $p['external_url'],
                'media_urls'       => $mediaMap[$postId]['urls'] ?? [],
                'media_public_ids' => $mediaMap[$postId]['public_ids'] ?? [],
                'view_count'       => (int)($p['view_count'] ?? 0),
                'like_count'       => (int)($likeCountMap[$postId] ?? 0),
                'comment_count'    => (int)($commentCountMap[$postId] ?? 0),
                'is_liked'         => isset($userLikedMap[$postId]),
                'created_at'       => $p['created_at'],
                'updated_at'       => $p['updated_at'],
            ];
        }

        return $result;
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
