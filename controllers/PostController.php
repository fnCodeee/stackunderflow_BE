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
}

