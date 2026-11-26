<?php

/**
 * middlewares/AdminMiddleware.php
 * ─────────────────────────────────────────────────────────────────────────────
 * ADMIN AUTHORIZATION MIDDLEWARE (spec-admin.md §2)
 *
 * Berdiri di atas AuthMiddleware: token harus valid dulu (dan akun
 * tidak sedang disuspend — AuthMiddleware menempelkan `role` hasil
 * lookup DB ke $GLOBALS['user']), lalu role harus admin-tier.
 *
 *  - handle()       : monitor atau super_admin
 *  - requireSuper() : khusus super_admin (eksekusi + keputusan)
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

require_once BASE_PATH . '/middlewares/AuthMiddleware.php';
require_once BASE_PATH . '/utils/Response.php';

class AdminMiddleware
{
    public static function handle(array $params = []): void
    {
        AuthMiddleware::handle($params);

        $role = (string) ($GLOBALS['user']['role'] ?? 'user');
        if ($role !== 'monitor' && $role !== 'super_admin') {
            Response::json(null, 403, 'Akses admin ditolak.', false);
        }
    }

    public static function requireSuper(array $params = []): void
    {
        self::handle($params);

        $role = (string) ($GLOBALS['user']['role'] ?? 'user');
        if ($role !== 'super_admin') {
            Response::json(null, 403, 'Aksi ini hanya untuk Super Admin.', false);
        }
    }
}
