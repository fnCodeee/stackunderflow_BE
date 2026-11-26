<?php

declare(strict_types=1);

require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/utils/Response.php';

/**
 * controllers/SearchController.php
 * ─────────────────────────────────────────────────────────────────────────────
 * PENCARIAN GLOBAL (audit P2 #21)
 *
 * Satu endpoint di balik auth untuk dropdown pencarian di
 * TopBar: tiga seksi (orang, proyek, skill sistem), maksimal
 * 5 baris per seksi, berbasis LIKE. Konten yang terhapus
 * (soft delete) atau disembunyikan moderasi tidak ikut.
 * ─────────────────────────────────────────────────────────────────────────────
 */
class SearchController
{
    /** Handler untuk GET /api/search?q= */
    public static function index(array $params): void
    {
        $userId = $GLOBALS['user']['id'] ?? null;
        if (!$userId) {
            Response::json(null, 401, 'Unauthorized', false, null, 'unauthorized');
            return;
        }

        $q = trim((string) ($_GET['q'] ?? ''));
        $empty = ['users' => [], 'projects' => [], 'skills' => []];
        if (mb_strlen($q) < 2) {
            Response::json($empty, 200, 'Success search', true);
            return;
        }

        try {
            $pdo = getDB();
            $like = '%' . $q . '%';

            $userStmt = $pdo->prepare(
                "SELECT u.id, u.username, p.full_name, p.photo_profile_url AS photo
                 FROM users u
                 LEFT JOIN profiles p ON p.user_id = u.id
                 WHERE u.account_status = 'active'
                   AND (u.username LIKE ? OR p.full_name LIKE ?)
                 ORDER BY u.username ASC
                 LIMIT 5"
            );
            $userStmt->execute([$like, $like]);
            $users = $userStmt->fetchAll(PDO::FETCH_ASSOC);

            $projectStmt = $pdo->prepare(
                "SELECT c.id, c.title, c.status, c.type, c.required_member,
                        u.username AS owner_username,
                        (SELECT COUNT(*) FROM user_collaboration uc
                          WHERE uc.collaboration_id = c.id) AS member_count
                 FROM collaborations c
                 JOIN users u ON u.id = c.user_id
                 WHERE c.deleted_at IS NULL
                   AND c.moderation_state = 'visible'
                   AND c.title LIKE ?
                 ORDER BY c.created_at DESC
                 LIMIT 5"
            );
            $projectStmt->execute([$like]);
            $projects = array_map(static function (array $row): array {
                $row['required_member'] = (int) $row['required_member'];
                $row['member_count'] = (int) $row['member_count'];
                return $row;
            }, $projectStmt->fetchAll(PDO::FETCH_ASSOC));

            $skillStmt = $pdo->prepare(
                "SELECT id, skill_name
                 FROM skills
                 WHERE scope = 'system' AND created_by IS NULL
                   AND skill_name LIKE ?
                 ORDER BY skill_name ASC
                 LIMIT 5"
            );
            $skillStmt->execute([$like]);
            $skills = $skillStmt->fetchAll(PDO::FETCH_ASSOC);

            Response::json([
                'users' => $users,
                'projects' => $projects,
                'skills' => $skills,
            ], 200, 'Success search', true);
        } catch (Throwable $e) {
            error_log('[SEARCH] ' . $e->getMessage());
            Response::json(null, 500, 'Terjadi kesalahan pada server. Silakan coba lagi.', false);
        }
    }
}
