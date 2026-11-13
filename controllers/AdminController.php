<?php

/**
 * controllers/AdminController.php
 * ─────────────────────────────────────────────────────────────────────────────
 * ADMIN PANEL API (spec-admin.md §7–§8)
 *
 * Fase 0/1: overview — angka ringkasan untuk halaman Overview.
 * Endpoint aksi, antrean, dan banding menyusul per fase spec.
 * Semua route di controller ini dijaga AdminMiddleware.
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

require_once BASE_PATH . '/utils/Response.php';

class AdminController
{
    /** GET /api/admin/overview */
    public static function overview(array $params = []): void
    {
        try {
            $pdo = getDB();
            $count = static function (string $sql) use ($pdo): int {
                return (int) $pdo->query($sql)->fetchColumn();
            };

            $data = [
                'users_total' => $count("SELECT COUNT(*) FROM users WHERE username != 'underflow-admin'"),
                'users_new_today' => $count("SELECT COUNT(*) FROM users WHERE username != 'underflow-admin' AND DATE(created_at) = CURDATE()"),
                'users_new_7d' => $count("SELECT COUNT(*) FROM users WHERE username != 'underflow-admin' AND created_at >= NOW() - INTERVAL 7 DAY"),
                'users_suspended' => $count("SELECT COUNT(*) FROM users WHERE account_status = 'suspended'"),
                'collabs_total' => $count("SELECT COUNT(*) FROM collaborations WHERE deleted_at IS NULL"),
                'collabs_active' => $count("SELECT COUNT(*) FROM collaborations WHERE deleted_at IS NULL AND status != 'completed'"),
                'posts_total' => $count("SELECT COUNT(*) FROM public_posts WHERE deleted_at IS NULL"),
                'posts_new_7d' => $count("SELECT COUNT(*) FROM public_posts WHERE deleted_at IS NULL AND created_at >= NOW() - INTERVAL 7 DAY"),
                'reports_open' => $count("SELECT COUNT(*) FROM reports WHERE status = 'open'"),
                'action_requests_pending' => $count("SELECT COUNT(*) FROM action_requests WHERE status = 'pending'"),
                'appeals_pending' => $count("SELECT COUNT(*) FROM appeals WHERE status = 'pending'"),
            ];

            Response::json($data, 200, 'OK', true);
        } catch (Throwable $e) {
            error_log('[ADMIN OVERVIEW ERROR] ' . $e->getMessage());
            Response::json(null, 500, 'Internal Server error: ' . $e->getMessage(), false);
        }
    }
}
