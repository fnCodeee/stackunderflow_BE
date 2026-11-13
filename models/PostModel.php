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
                "INSERT INTO public_posts (id, user_id, description, portfolio_type, external_url, view_count)
                 VALUES (:id, :user_id, :description, :portfolio_type, :external_url, 0)"
            );

            $stmt->execute([
                ':id'             => $id,
                ':user_id'        => $userId,
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
            'description'      => $description,
            'portfolio_type'   => $portfolioType,
            'external_url'     => $externalUrl,
            'media_urls'       => $mediaUrls,
            'media_public_ids' => $mediaPublicIds,
            'view_count'       => 0,
        ];
    }

    /**
     * Mencari post berdasarkan ID beserta detail lengkap untuk endpoint detail.
     * Mirip findById tapi include like_count, comment_count, is_liked, dan comments.
     * Juga increment view_count.
     *
     * @param  string $id UUID post
     * @param  string|null $currentUserId ID user yang sedang login (opsional) untuk mengecek is_liked
     * @return array<string, mixed>|null Associative array post detail atau null jika tidak ditemukan
     */
    public static function findDetailById(string $id, ?string $currentUserId = null): ?array
    {
        $pdo = getDB();

        // 1. Catat view (satu akun dihitung sekali per post) lalu ambil
        // data utama post dengan user + profile. Tamu (tanpa user id)
        // tidak dihitung.
        if ($currentUserId !== null && $currentUserId !== '') {
            self::recordView($id, $currentUserId);
        }

        // 2. Ambil post yang sudah diupdate beserta user dan profile
        $sql = "
            SELECT 
                p.id,
                p.user_id,
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
            WHERE p.id = :id AND p.deleted_at IS NULL AND moderation_state = 'visible'
            LIMIT 1
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':id' => $id]);
        $post = $stmt->fetch();

        if (!$post) {
            return null;
        }

        // 3. Ambil media yang berelasi
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

        // 4. Ambil like_count
        $likeStmt = $pdo->prepare("SELECT COUNT(*) as count FROM like_posts WHERE post_id = :post_id");
        $likeStmt->execute([':post_id' => $id]);
        $likeCount = (int)($likeStmt->fetchColumn() ?? 0);

        // 5. Ambil comment_count
        $commentStmt = $pdo->prepare("SELECT COUNT(*) as count FROM comments WHERE post_id = :post_id AND deleted_at IS NULL AND moderation_state = 'visible'");
        $commentStmt->execute([':post_id' => $id]);
        $commentCount = (int)($commentStmt->fetchColumn() ?? 0);

        // 6. Cek is_liked untuk user yang sedang login jika ada
        $isLiked = false;
        if ($currentUserId !== null && $currentUserId !== '') {
            $userLikeStmt = $pdo->prepare("SELECT 1 FROM like_posts WHERE post_id = :post_id AND user_id = :user_id LIMIT 1");
            $userLikeStmt->execute([':post_id' => $id, ':user_id' => $currentUserId]);
            $isLiked = (bool)$userLikeStmt->fetchColumn();
        }

        // 7. Ambil comments dengan user + profile
        $commentsSql = "
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
            WHERE c.post_id = :post_id AND c.deleted_at IS NULL AND moderation_state = 'visible'
            ORDER BY c.created_at DESC
        ";
        $commentsStmt = $pdo->prepare($commentsSql);
        $commentsStmt->execute([':post_id' => $id]);
        $comments = $commentsStmt->fetchAll();

        $commentsWithProfile = [];
        foreach ($comments as $c) {
            $commentsWithProfile[] = [
                'id' => $c['id'],
                'user_id' => $c['user_id'],
                'post_id' => $c['post_id'],
                'content' => $c['content'],
                'created_at' => $c['created_at'],
                'updated_at' => $c['updated_at'],
                'user' => [
                    'id' => $c['user_id'],
                    'username' => $c['username'] ?? '',
                    'photo_profile_url' => $c['photo_profile_url'] ?? ProfileModel::DEFAULT_PHOTO_URL,
                ],
            ];
        }

        // 8. Susun response detail post (mirip Express: ownerId, likeCount, commentCount, isLiked, comments)
        return [
            'id' => $post['id'],
            'user_id' => $post['user_id'],
            'description' => $post['description'],
            'portfolio_type' => $post['portfolio_type'],
            'external_url' => $post['external_url'],
            'media_urls' => $mediaUrls,
            'media_public_ids' => $mediaPublicIds,
            'view_count' => (int)($post['view_count'] ?? 0),
            'like_count' => $likeCount,
            'comment_count' => $commentCount,
            'is_liked' => $isLiked,
            'created_at' => $post['created_at'],
            'updated_at' => $post['updated_at'],
            'owner' => [
                'id' => $post['user_id'],
                'username' => $post['username'] ?? '',
                'photo_profile_url' => $post['photo_profile_url'] ?? ProfileModel::DEFAULT_PHOTO_URL,
            ],
            'comments' => $commentsWithProfile,
        ];
    }

    /**
     * Mencatat satu view untuk sebuah post — satu akun dihitung maksimal
     * sekali per post. Baris (user_id, post_id) di post_views bersifat unik
     * (UNIQUE KEY uq_user_post_view); kalau sudah ada, view_count tidak
     * ditambah lagi. Dipakai endpoint POST /api/posts/:postId/view.
     *
     * @param  string $postId UUID post
     * @param  string $userId UUID user yang melihat
     * @return int|null view_count terbaru, atau null jika post tidak ditemukan / sudah dihapus
     */
    public static function recordView(string $postId, string $userId): ?int
    {
        $pdo = getDB();

        // 0. Post harus ada dan belum dihapus.
        $stmt = $pdo->prepare("SELECT 1 FROM public_posts WHERE id = :id AND deleted_at IS NULL");
        $stmt->execute([':id' => $postId]);
        if ($stmt->fetchColumn() === false) {
            return null;
        }

        // 1. Catat siapa yang melihat (unik per user+post — duplikat diabaikan).
        $stmt = $pdo->prepare("
            INSERT IGNORE INTO post_views (post_id, user_id)
            VALUES (:post_id, :user_id)
        ");
        $stmt->execute([':post_id' => $postId, ':user_id' => $userId]);
        $isNewView = $stmt->rowCount() === 1;

        // 2. Tambah view_count hanya kalau ini view pertama akun tersebut.
        if ($isNewView) {
            $stmt = $pdo->prepare("
                UPDATE public_posts
                SET view_count = view_count + 1
                WHERE id = :id
            ");
            $stmt->execute([':id' => $postId]);
        }

        // 3. Kembalikan view_count terbaru.
        $stmt = $pdo->prepare("SELECT view_count FROM public_posts WHERE id = :id");
        $stmt->execute([':id' => $postId]);
        $count = $stmt->fetchColumn();

        return $count === false ? null : (int)$count;
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
        $stmt = $pdo->prepare("SELECT * FROM public_posts WHERE id = :id AND deleted_at IS NULL LIMIT 1");
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
     * Hitung jumlah like + komen sebuah post saat ini — dipakai untuk
     * push post-stats ke realtime setelah like/unlike/komen.
     * Komen yang sudah soft-deleted tidak dihitung (deleted_at IS NULL),
     * sama seperti penghitungan di getAll.
     *
     * @param  string $postId UUID post
     * @return array{likes: int, commentCount: int}
     */
    public static function getCounts(string $postId): array
    {
        $pdo = getDB();

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM like_posts WHERE post_id = ?');
        $stmt->execute([$postId]);
        $likes = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM comments WHERE post_id = ? AND deleted_at IS NULL AND moderation_state = \'visible\'');
        $stmt->execute([$postId]);
        $commentCount = (int)$stmt->fetchColumn();

        return ['likes' => $likes, 'commentCount' => $commentCount];
    }

    /**
     * Soft delete post berdasarkan ID (berguna untuk testing/cleanup).
     *
     * @param  string $id
     * @return bool
     */
    public static function deleteById(string $id): bool
    {
        $pdo = getDB();
        $stmt = $pdo->prepare("UPDATE public_posts SET deleted_at = NOW() WHERE id = :id AND deleted_at IS NULL");
        return $stmt->execute([':id' => $id]);
    }

    /**
     * Soft delete post: set deleted_at = NOW(). Row tetap ada di database
     * (bisa di-restore / diaudit); likes, comments, dan media terkait
     * TIDAK dihapus.
     *
     * @param  string $id UUID post
     * @return bool True jika berhasil dihapus
     */
    public static function delete(string $id): bool
    {
        $pdo = getDB();
        $stmt = $pdo->prepare("UPDATE public_posts SET deleted_at = NOW() WHERE id = :id AND deleted_at IS NULL");
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
            WHERE p.deleted_at IS NULL AND moderation_state = 'visible'
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
            WHERE post_id IN ($placeholders) AND deleted_at IS NULL AND moderation_state = 'visible' 
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
     /**
          * Update post by ID (ownership check done by controller).
          *
          * @param  string $id UUID post
          * @param  array{
          *     description: string,
          *     external_url: string,
          *     portfolio_type?: string,
          *     media_urls?: array<int, string>,
          *     media_public_ids?: array<int, ?string>
          * } $data
          * @return array<string, mixed>|null Updated post detail atau null jika tidak ditemukan
          */
         public static function update(string $id, array $data): ?array
         {
             $pdo = getDB();

             // 1. Cek post exists
             $checkStmt = $pdo->prepare("SELECT user_id FROM public_posts WHERE id = :id AND deleted_at IS NULL LIMIT 1");
             $checkStmt->execute([':id' => $id]);
             $existing = $checkStmt->fetch();

             if (!$existing) {
                 return null; // post tidak ditemukan
             }

             $description = $data['description'];
             $externalUrl = $data['external_url'];
             $portfolioType = $data['portfolio_type'] ?? 'link';
             $mediaUrls = $data['media_urls'] ?? null;
             $mediaPublicIds = $data['media_public_ids'] ?? [];

             // 2. Update public_posts dalam transaction
             $pdo->beginTransaction();

             try {
                 $stmt = $pdo->prepare("
                     UPDATE public_posts 
                     SET description = :description,
                         external_url = :external_url,
                         portfolio_type = :portfolio_type,
                         updated_at = CURRENT_TIMESTAMP
                     WHERE id = :id AND deleted_at IS NULL
                 ");

                 $stmt->execute([
                     ':id'             => $id,
                     ':description'    => $description,
                     ':external_url'   => $externalUrl,
                     ':portfolio_type' => $portfolioType,
                 ]);

                 // 3. Update media jika mediaUrls disediakan
                 if (is_array($mediaUrls)) {
                     // Hapus media lama
                     $deleteMediaStmt = $pdo->prepare("DELETE FROM media_posts WHERE post_id = :post_id");
                     $deleteMediaStmt->execute([':post_id' => $id]);

                     // Insert media baru
                     if (!empty($mediaUrls)) {
                         $mediaStmt = $pdo->prepare("
                             INSERT INTO media_posts (id, post_id, media_url, media_public_id)
                             VALUES (:id, :post_id, :media_url, :media_public_id)
                         ");

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
                 }

                 $pdo->commit();
             } catch (Throwable $e) {
                 if ($pdo->inTransaction()) {
                     $pdo->rollBack();
                 }
                 throw $e;
             }

             // 3. Return updated post detail (ownerId will be passed by controller)
             return self::findDetailById($id, null);
         }

         /** Helper: Generate UUID v4 standar (RFC 4122).
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
