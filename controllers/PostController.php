<?php

/**
 * controllers/PostController.php
 * ─────────────────────────────────────────────────────────────────────────────
 * POST CONTROLLER
 *
 * Menangani operasi post/feed pengguna:
 *  - createPost() -> Phase 3B (POST /api/posts)
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

require_once BASE_PATH . '/models/PostModel.php';
require_once BASE_PATH . '/models/LikeModel.php';
require_once BASE_PATH . '/models/CommentModel.php';
require_once BASE_PATH . '/utils/Response.php';

class PostController
{
    /**
     * Handler untuk POST /api/posts
     * Membuat postingan feed baru beserta daftar media yang diunggah.
     *
     * @param  array<string, string> $params Path parameters dari Router
     * @return void
     */
    public static function createPost(array $params = []): void
    {
        try {
            $userId = $GLOBALS['user']['id'] ?? null;

            if (!$userId) {
                Response::json(null, 401, 'Unauthorized', false);
            }

            // 1. Ambil request body
            $body = $GLOBALS['body'] ?? [];
            if (empty($body)) {
                $raw = file_get_contents('php://input');
                if (!empty($raw)) {
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded)) {
                        $body = $decoded;
                    }
                }
            }

            $title          = trim((string)($body['title'] ?? ''));
            $description    = trim((string)($body['description'] ?? ''));
            $portfolioType  = trim((string)($body['portfolio_type'] ?? $body['portfolioType'] ?? 'link'));
            $externalUrlRaw = $body['external_url'] ?? $body['externalUrl'] ?? null;
            $mediaUrls      = $body['media_urls'] ?? $body['mediaUrls'] ?? null;
            $mediaPublicIds = $body['media_public_ids'] ?? $body['mediaPublicIds'] ?? [];

            // 2. Validasi field wajib sesuai baseline Express
            if (
                $title === '' ||
                $description === '' ||
                !is_array($mediaUrls) ||
                count($mediaUrls) === 0
            ) {
                Response::json(null, 400, 'Please fill all the required fields!', false);
            }

            // 3. Validasi konsistensi jumlah mediaUrls dan mediaPublicIds jika mediaPublicIds dikirim
            if (is_array($mediaPublicIds) && count($mediaPublicIds) > 0 && count($mediaUrls) !== count($mediaPublicIds)) {
                Response::json(null, 400, 'Count of mediaUrls and mediaPublicIds must be same!', false);
            }

            $externalUrl = ($externalUrlRaw !== null && trim((string)$externalUrlRaw) !== '')
                ? trim((string)$externalUrlRaw)
                : null;

            // 4. Simpan post ke database melalui PostModel
            $result = PostModel::create([
                'user_id'          => $userId,
                'title'            => $title,
                'description'      => $description,
                'portfolio_type'   => in_array($portfolioType, ['link', 'images'], true) ? $portfolioType : 'link',
                'external_url'     => $externalUrl,
                'media_urls'       => array_values($mediaUrls),
                'media_public_ids' => is_array($mediaPublicIds) ? array_values($mediaPublicIds) : [],
            ]);

            Response::json($result, 200, 'Postingan Berhasil dibuat', true);
        } catch (Throwable $e) {
            Response::json(null, 500, $e->getMessage(), false);
        }
    }

    /**
     * Handler untuk GET /api/posts (Showcase / Feed)
     * Mengambil seluruh postingan feed terbaru beserta data pembuat, media, jumlah like, dan jumlah komentar.
     * Menggunakan OptionalAuthMiddleware (dapat diakses oleh publik/guest maupun user login).
     *
     * @param  array<string, string> $params Path parameters dari Router
     * @return void
     */
    public static function showCase(array $params = []): void
    {
        try {
            $userId = $GLOBALS['user']['id'] ?? null;

            $posts = PostModel::getAll($userId);

            if (empty($posts)) {
                Response::json([], 404, 'Belum ada postingan saat ini!', false);
            }

            Response::json($posts, 200, 'Berhasil mendapatkan semua Postingan!', true);
        } catch (Throwable $e) {
            Response::json(null, 500, $e->getMessage(), false);
        }
    }

    /**
     * Handler untuk GET /api/posts/:postId (Post Detail)
     * Mengambil detail postingan termasuk like_count, comment_count, is_liked, comments, owner.
     * Memerlukan AuthMiddleware (login wajib) — sesuai Express baseline.
     *
     * @param  array<string, string> $params Path parameters dari Router (postId)
     * @return void
     */
    public static function getPostDetail(array $params = []): void
    {
        try {
            $userId = $GLOBALS['user']['id'] ?? null;

            if (!$userId) {
                Response::json(null, 401, 'Unauthorized', false);
            }

            $postId = $params['postId'] ?? '';

            if ($postId === '') {
                Response::json(null, 400, 'Post ID diperlukan', false);
            }

            $post = PostModel::findDetailById($postId, $userId);

            if (!$post) {
                Response::json(null, 404, 'Post Not found!', false);
            }

            Response::json($post, 200, 'Post Detail added!', true);
        } catch (Throwable $e) {
            Response::json(null, 500, $e->getMessage(), false);
        }
    }

    /**
     * Handler untuk PUT /api/posts/:postId (Update Post)
     * Mengupdate postingan milik user sendiri.
     * Memerlukan AuthMiddleware (login wajib) — sesuai Express baseline.
     *
     * @param  array<string, string> $params Path parameters dari Router (postId)
     * @return void
     */
    public static function updatePost(array $params = []): void
    {
        try {
            $userId = $GLOBALS['user']['id'] ?? null;

            if (!$userId) {
                Response::json(null, 401, 'Unauthorized', false);
            }

            $postId = $params['postId'] ?? '';

            if ($postId === '') {
                Response::json(null, 400, 'Post ID diperlukan', false);
            }

            // Ambil request body
            $body = $GLOBALS['body'] ?? [];
            if (empty($body)) {
                $raw = file_get_contents('php://input');
                if (!empty($raw)) {
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded)) {
                        $body = $decoded;
                    }
                }
            }

            $title          = trim((string)($body['title'] ?? ''));
            $description    = trim((string)($body['description'] ?? ''));
            $portfolioType  = trim((string)($body['portfolio_type'] ?? $body['portfolioType'] ?? 'link'));
            $externalUrlRaw = $body['external_url'] ?? $body['externalUrl'] ?? null;
            $mediaUrls      = $body['media_urls'] ?? $body['mediaUrls'] ?? null;
            $mediaPublicIds = $body['media_public_ids'] ?? $body['mediaPublicIds'] ?? [];

            // Validasi field wajib sesuai Express baseline (title, description, externalUrl required)
            if (
                $title === '' ||
                $description === '' ||
                $externalUrlRaw === null || trim((string)$externalUrlRaw) === ''
            ) {
                Response::json(null, 400, 'Tiap kolom wajib di isi!', false);
            }

            // Validasi konsistensi media jika disediakan
            if (is_array($mediaUrls) && is_array($mediaPublicIds) && count($mediaPublicIds) > 0 && count($mediaUrls) !== count($mediaPublicIds)) {
                Response::json(null, 400, 'Count of mediaUrls and mediaPublicIds must be same!', false);
            }

            $externalUrl = trim((string)$externalUrlRaw);

            // 1. Cek ownership dulu (mirip Express: findById lalu compare userId)
            $pdo = getDB();
            $checkStmt = $pdo->prepare("SELECT user_id FROM public_posts WHERE id = :id LIMIT 1");
            $checkStmt->execute([':id' => $postId]);
            $existing = $checkStmt->fetch();

            if (!$existing) {
                Response::json(null, 404, 'Postingan tidak ditemukan!', false);
            }

            $user_id = $existing['user_id'];
            if ($user_id !== $userId) {
                Response::json(null, 403, 'Bukan hak lu buat edit postingan ini!', false);
            }

            // 2. Update via model
            $result = PostModel::update($postId, [
                'title'            => $title,
                'description'      => $description,
                'external_url'     => $externalUrl,
                'portfolio_type'   => in_array($portfolioType, ['link', 'images'], true) ? $portfolioType : 'link',
                'media_urls'       => is_array($mediaUrls) ? array_values($mediaUrls) : null,
                'media_public_ids' => is_array($mediaPublicIds) ? array_values($mediaPublicIds) : [],
            ]);

            if (!$result) {
                Response::json(null, 404, 'Postingan tidak ditemukan!', false);
            }

            Response::json($result, 200, 'Berhasil diupdate!', true);
        } catch (Throwable $e) {
            Response::json(null, 500, $e->getMessage(), false);
        }
    }

    /**
     * Handler untuk DELETE /api/posts/:postId (Delete Post)
     * Menghapus postingan milik user sendiri beserta data relasi.
     * Memerlukan AuthMiddleware (login wajib) — sesuai Express baseline.
     *
     * @param  array<string, string> $params Path parameters dari Router (postId)
     * @return void
     */
    public static function deletePost(array $params = []): void
    {
        try {
            $userId = $GLOBALS['user']['id'] ?? null;

            if (!$userId) {
                Response::json(null, 401, 'Unauthorized', false);
            }

            $postId = $params['postId'] ?? '';

            if ($postId === '') {
                Response::json(null, 400, 'Post ID diperlukan', false);
            }

            // 1. Cari post untuk verifikasi eksistensi dan data response
            $post = PostModel::findById($postId);

            if (!$post) {
                Response::json(null, 404, 'Postingan Tidak ditemukan!', false);
            }

            // 2. Ownership check: hanya owner yang berhak menghapus
            $user_id = $post['user_id'];
            if ($user_id !== $userId) {
                Response::json(
                    null,
                    403,
                    "You don't have permission to delete this post. You can only delete your own posts.",
                    false
                );
            }

            // 3. Hapus post beserta data terkait via model
            PostModel::delete($postId);

            Response::json($post, 200, 'Postingan Berhasil dihapus!', true);
        } catch (Throwable $e) {
            Response::json(null, 500, $e->getMessage(), false);
        }
    }

    /**
     * Handler untuk POST /api/posts/:postId/like
     * Menyukai postingan. Memerlukan AuthMiddleware (login wajib).
     *
     * @param  array<string, string> $params Path parameters dari Router (postId)
     * @return void
     */
    public static function likePost(array $params = []): void
    {
        try {
            $userId = $GLOBALS['user']['id'] ?? null;

            if (!$userId) {
                Response::json(null, 401, 'Unauthorized', false);
            }

            $postId = $params['postId'] ?? '';

            if ($postId === '') {
                Response::json(null, 400, 'Post ID diperlukan', false);
            }

            $post = PostModel::findById($postId);
            if (!$post) {
                Response::json(null, 404, 'Post not found', false);
            }

            $existingLike = LikeModel::findLike($postId, $userId);
            if ($existingLike) {
                Response::json(null, 400, 'Sudah like postingan ini', false);
            }

            LikeModel::createLike($postId, $userId);

            Response::json(null, 201, 'Post berhasil di-like', true);
        } catch (Throwable $e) {
            Response::json(null, 500, $e->getMessage(), false);
        }
    }

    /**
     * Handler untuk DELETE /api/posts/:postId/unlike
     * Menghapus like dari postingan. Memerlukan AuthMiddleware (login wajib).
     *
     * @param  array<string, string> $params Path parameters dari Router (postId)
     * @return void
     */
    public static function unlikePost(array $params = []): void
    {
        try {
            $userId = $GLOBALS['user']['id'] ?? null;

            if (!$userId) {
                Response::json(null, 401, 'Unauthorized', false);
            }

            $postId = $params['postId'] ?? '';

            if ($postId === '') {
                Response::json(null, 400, 'Post ID diperlukan', false);
            }

            $post = PostModel::findById($postId);
            if (!$post) {
                Response::json(null, 404, 'Post not found', false);
            }

            LikeModel::deleteLike($postId, $userId);

            Response::json(null, 200, 'Like berhasil dihapus', true);
        } catch (Throwable $e) {
            Response::json(null, 500, $e->getMessage(), false);
        }
    }

    /**
     * Handler untuk POST /api/posts/:postId/comments
     * Membuat komentar baru pada postingan. Memerlukan AuthMiddleware (login wajib).
     *
     * @param  array<string, string> $params Path parameters dari Router (postId)
     * @return void
     */
    public static function createComment(array $params = []): void
    {
        try {
            $userId = $GLOBALS['user']['id'] ?? null;

            if (!$userId) {
                Response::json(null, 401, 'Unauthorized', false);
            }

            $postId = $params['postId'] ?? '';

            if ($postId === '') {
                Response::json(null, 400, 'Post ID diperlukan', false);
            }

            $body = $GLOBALS['body'] ?? [];
            if (empty($body)) {
                $raw = file_get_contents('php://input');
                if (!empty($raw)) {
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded)) {
                        $body = $decoded;
                    }
                }
            }

            $content = trim((string)($body['content'] ?? ''));
            if ($content === '') {
                Response::json(null, 400, 'Content is required', false);
            }

            $post = PostModel::findById($postId);
            if (!$post) {
                Response::json(null, 404, 'Post not found', false);
            }

            $commentData = CommentModel::create($postId, $userId, $content);

            Response::json($commentData, 200, 'Success create comment!', true);
        } catch (Throwable $e) {
            Response::json(null, 500, $e->getMessage(), false);
        }
    }

    /**
     * Handler untuk GET /api/posts/:postId/comments
     * Mengambil daftar komentar untuk postingan tertentu. Memerlukan AuthMiddleware (login wajib).
     *
     * @param  array<string, string> $params Path parameters dari Router (postId)
     * @return void
     */
    public static function getComments(array $params = []): void
    {
        try {
            $userId = $GLOBALS['user']['id'] ?? null;

            if (!$userId) {
                Response::json(null, 401, 'Unauthorized', false);
            }

            $postId = $params['postId'] ?? '';

            if ($postId === '') {
                Response::json(null, 400, 'Post ID diperlukan', false);
            }

            $post = PostModel::findById($postId);
            if (!$post) {
                Response::json(null, 404, 'Post not found', false);
            }

            $comments = CommentModel::getByPostId($postId);

            Response::json($comments, 200, 'Success get comments', true);
        } catch (Throwable $e) {
            Response::json(null, 500, $e->getMessage(), false);
        }
    }

    /**
     * Handler untuk DELETE /api/delete/comments/:commentId
     * Menghapus komentar milik user sendiri. Memerlukan AuthMiddleware (login wajib).
     *
     * @param  array<string, string> $params Path parameters dari Router (commentId)
     * @return void
     */
    public static function deleteComment(array $params = []): void
    {
        try {
            $userId = $GLOBALS['user']['id'] ?? null;

            if (!$userId) {
                Response::json(null, 401, 'Unauthorized', false);
            }

            $commentId = $params['commentId'] ?? '';

            if ($commentId === '') {
                Response::json(null, 400, 'Comment ID diperlukan', false);
            }

            $comment = CommentModel::findById($commentId);
            if (!$comment) {
                Response::json(null, 404, 'Comment not found!', false);
            }

            if ($comment['user_id'] !== $userId) {
                Response::json(null, 403, 'Bukan komentar milikmu', false);
            }

            CommentModel::delete($commentId);

            Response::json($comment, 200, 'Comment berhasil dihapus!', true);
        } catch (Throwable $e) {
            Response::json(null, 500, $e->getMessage(), false);
        }
    }
}

