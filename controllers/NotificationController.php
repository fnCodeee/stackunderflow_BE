<?php

/**
 * controllers/NotificationController.php
 * ─────────────────────────────────────────────────────────────────────────────
 * NOTIFICATION CONTROLLER
 *
 * Menangani notifikasi pengguna:
 *  - listNotifications() -> GET    /api/notifications            (daftar notifikasi milik user login)
 *  - markRead()           -> PUT    /api/notifications/:id/read   (tandai satu notifikasi dibaca)
 *  - markAllRead()        -> POST   /api/notifications/read-all   (tandai semua notifikasi dibaca)
 *
 * Router tidak punya method PATCH, jadi mark-read memakai PUT (sama
 * seperti update profil / post di route lain).
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

require_once BASE_PATH . '/models/NotificationModel.php';
require_once BASE_PATH . '/utils/Response.php';

class NotificationController
{
    /**
     * Handler untuk GET /api/notifications
     * Daftar notifikasi milik user login, terbaru dulu.
     * Memerlukan AuthMiddleware (login wajib).
     *
     * @return void
     */
    public static function listNotifications(): void
    {
        try {
            $userId = $GLOBALS['user']['id'] ?? null;

            if (!$userId) {
                Response::json(null, 401, 'Unauthorized', false);
            }

            $rows = NotificationModel::getForUser($userId);

            $data = array_map([NotificationModel::class, 'toApiRow'], $rows);

            Response::json($data, 200, 'Success get notifications', true);
        } catch (Throwable $e) {
            error_log('[SERVER ERROR] ' . $e->getMessage());
            Response::json(null, 500, 'Terjadi kesalahan pada server. Silakan coba lagi.', false);
        }
    }

    /**
     * Handler untuk PUT /api/notifications/:id/read
     * Tandai satu notifikasi sebagai dibaca. 404 kalau notifikasi tidak
     * ditemukan atau bukan milik user login.
     * Memerlukan AuthMiddleware (login wajib).
     *
     * @param  array<string, string> $params Path parameters dari Router (id)
     * @return void
     */
    public static function markRead(array $params = []): void
    {
        try {
            $userId = $GLOBALS['user']['id'] ?? null;

            if (!$userId) {
                Response::json(null, 401, 'Unauthorized', false);
            }

            $id = $params['id'] ?? '';

            if ($id === '') {
                Response::json(null, 400, 'Notification ID diperlukan', false);
            }

            $ok = NotificationModel::markRead($id, $userId);

            if (!$ok) {
                Response::json(null, 404, 'Notification not found', false);
            }

            Response::json(null, 200, 'Notification marked as read', true);
        } catch (Throwable $e) {
            error_log('[SERVER ERROR] ' . $e->getMessage());
            Response::json(null, 500, 'Terjadi kesalahan pada server. Silakan coba lagi.', false);
        }
    }

    /**
     * Handler untuk POST /api/notifications/read-all
     * Tandai semua notifikasi user login yang belum dibaca sebagai dibaca.
     * Memerlukan AuthMiddleware (login wajib).
     *
     * @return void
     */
    public static function markAllRead(): void
    {
        try {
            $userId = $GLOBALS['user']['id'] ?? null;

            if (!$userId) {
                Response::json(null, 401, 'Unauthorized', false);
            }

            $count = NotificationModel::markAllRead($userId);

            Response::json(['marked' => $count], 200, 'All notifications marked as read', true);
        } catch (Throwable $e) {
            error_log('[SERVER ERROR] ' . $e->getMessage());
            Response::json(null, 500, 'Terjadi kesalahan pada server. Silakan coba lagi.', false);
        }
    }
}
