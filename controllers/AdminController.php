<?php

/**
 * controllers/AdminController.php
 * ─────────────────────────────────────────────────────────────────────────────
 * ADMIN PANEL API (spec-admin.md §7–§8)
 *
 * Fase 1: monitoring read-only — overview, users (+detail), content.
 * Semua route di controller ini dijaga AdminMiddleware (monitor /
 * super_admin). Endpoint aksi menyusul di Fase 2.
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

require_once BASE_PATH . '/models/AdminModel.php';
require_once BASE_PATH . '/models/UserModel.php';
require_once BASE_PATH . '/utils/Response.php';

class AdminController
{
    private static function pageParams(): array
    {
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $limit = (int) ($_GET['limit'] ?? 20);
        $limit = max(1, min(50, $limit));
        return [$page, $limit];
    }

    private static function envelope(array $result, int $page, int $limit): array
    {
        return [
            'items' => $result['items'],
            'total' => $result['total'],
            'page' => $page,
            'limit' => $limit,
            'total_pages' => max(1, (int) ceil($result['total'] / $limit)),
        ];
    }

    /** GET /api/admin/overview */
    public static function overview(array $params = []): void
    {
        try {
            $pdo = getDB();
            $count = static function (string $sql) use ($pdo): int {
                return (int) $pdo->query($sql)->fetchColumn();
            };

            $viewer = null;
            $viewerId = (string) ($GLOBALS['user']['id'] ?? '');
            if ($viewerId !== '') {
                $me = UserModel::findById($viewerId);
                if ($me !== null) {
                    $viewer = [
                        'id' => $me['id'],
                        'username' => $me['username'],
                        'role' => $me['role'] ?? 'user',
                    ];
                }
            }

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
                'recent_activity' => AdminModel::recentActivity(10),
                'viewer' => $viewer,
            ];

            Response::json($data, 200, 'OK', true);
        } catch (Throwable $e) {
            error_log('[ADMIN OVERVIEW ERROR] ' . $e->getMessage());
            Response::json(null, 500, 'Internal Server error: ' . $e->getMessage(), false);
        }
    }

    /** GET /api/admin/users?q=&status=&role=&page=&limit= */
    public static function users(array $params = []): void
    {
        try {
            [$page, $limit] = self::pageParams();
            $result = AdminModel::listUsers(
                trim((string) ($_GET['q'] ?? '')),
                trim((string) ($_GET['status'] ?? 'all')),
                trim((string) ($_GET['role'] ?? 'all')),
                $page,
                $limit
            );
            Response::json(self::envelope($result, $page, $limit), 200, 'OK', true);
        } catch (Throwable $e) {
            error_log('[ADMIN USERS ERROR] ' . $e->getMessage());
            Response::json(null, 500, 'Internal Server error: ' . $e->getMessage(), false);
        }
    }

    /** GET /api/admin/users/:id */
    public static function userDetail(array $params = []): void
    {
        try {
            $detail = AdminModel::userDetail((string) ($params['id'] ?? ''));
            if ($detail === null) {
                Response::json(null, 404, 'User tidak ditemukan.', false);
            }
            Response::json($detail, 200, 'OK', true);
        } catch (Throwable $e) {
            error_log('[ADMIN USER DETAIL ERROR] ' . $e->getMessage());
            Response::json(null, 500, 'Internal Server error: ' . $e->getMessage(), false);
        }
    }

    /** GET /api/admin/content/:type?state=&q=&page=&limit= */
    public static function content(array $params = []): void
    {
        try {
            $type = (string) ($params['type'] ?? '');
            if (!in_array($type, ['posts', 'comments', 'collabs'], true)) {
                Response::json(null, 404, 'Jenis konten tidak dikenal.', false);
            }
            [$page, $limit] = self::pageParams();
            $result = AdminModel::listContent(
                $type,
                trim((string) ($_GET['state'] ?? 'all')),
                trim((string) ($_GET['q'] ?? '')),
                $page,
                $limit
            );
            Response::json(self::envelope($result, $page, $limit), 200, 'OK', true);
        } catch (Throwable $e) {
            error_log('[ADMIN CONTENT ERROR] ' . $e->getMessage());
            Response::json(null, 500, 'Internal Server error: ' . $e->getMessage(), false);
        }
    }
}
