<?php

/**
 * models/AdminModel.php
 * ─────────────────────────────────────────────────────────────────────────────
 * QUERY MONITORING ADMIN (spec-admin.md §7)
 *
 * Semua query di sini read-only dan hanya dipakai endpoint
 * /api/admin/* (dijaga AdminMiddleware). Berbeda dari model publik,
 * query admin SENGAJA melihat konten berstatus moderasi 'removed'
 * juga — memonitor artinya melihat semuanya.
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

class AdminModel
{
    private static function like(string $q): string
    {
        return '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
    }

    /** Daftar user untuk tabel Users (search + filter + pagination). */
    public static function listUsers(string $q, string $status, string $role, int $page, int $limit): array
    {
        $pdo = getDB();
        $where = ["u.username != 'underflow-admin'"];
        $args = [];
        if ($q !== '') {
            $where[] = "(u.username LIKE ? OR u.email LIKE ?)";
            $args[] = self::like($q);
            $args[] = self::like($q);
        }
        if ($status === 'active' || $status === 'suspended') {
            $where[] = "u.account_status = ?";
            $args[] = $status;
        }
        if (in_array($role, ['user', 'monitor', 'super_admin'], true)) {
            $where[] = "u.role = ?";
            $args[] = $role;
        }
        $whereSql = implode(' AND ', $where);

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM users u WHERE $whereSql");
        $countStmt->execute($args);
        $total = (int) $countStmt->fetchColumn();

        $offset = ($page - 1) * $limit;
        $stmt = $pdo->prepare(
            "SELECT u.id, u.username, u.email, u.google_id, u.role, u.account_status,
                    u.suspended_reason, u.suspended_at, u.created_at,
                    p.photo_profile_url,
                    (SELECT COUNT(*) FROM public_posts pp WHERE pp.user_id = u.id AND pp.deleted_at IS NULL) AS post_count,
                    (SELECT COUNT(*) FROM collaborations c WHERE c.user_id = u.id AND c.deleted_at IS NULL) AS collabs_owned,
                    (SELECT COUNT(*) FROM user_collaboration uc WHERE uc.user_id = u.id) AS collabs_joined,
                    (SELECT COUNT(*) FROM moderation_actions ma WHERE ma.target_owner_id = u.id AND ma.strike = 1 AND ma.reverted_at IS NULL) AS strike_count
             FROM users u
             LEFT JOIN profiles p ON p.user_id = u.id
             WHERE $whereSql
             ORDER BY u.created_at DESC
             LIMIT $limit OFFSET $offset"
        );
        $stmt->execute($args);
        $items = array_map([self::class, 'userRow'], $stmt->fetchAll());
        return ['items' => $items, 'total' => $total];
    }

    private static function userRow(array $r): array
    {
        return [
            'id' => $r['id'],
            'username' => $r['username'],
            'email' => $r['email'],
            'provider' => !empty($r['google_id']) ? 'google' : 'password',
            'photo_profile_url' => $r['photo_profile_url'] ?? null,
            'role' => $r['role'],
            'account_status' => $r['account_status'],
            'suspended_reason' => $r['suspended_reason'] ?? null,
            'suspended_at' => $r['suspended_at'] ?? null,
            'created_at' => $r['created_at'],
            'post_count' => (int) ($r['post_count'] ?? 0),
            'collab_count' => (int) ($r['collabs_owned'] ?? 0) + (int) ($r['collabs_joined'] ?? 0),
            'strike_count' => (int) ($r['strike_count'] ?? 0),
        ];
    }

    /** Detail satu user: profil, skill, konten, riwayat moderasi. */
    public static function userDetail(string $id): ?array
    {
        $pdo = getDB();
        $stmt = $pdo->prepare(
            "SELECT u.id, u.username, u.email, u.google_id, u.role, u.account_status,
                    u.suspended_reason, u.suspended_at, u.created_at,
                    p.photo_profile_url, p.full_name, p.bio,
                    (SELECT COUNT(*) FROM public_posts pp WHERE pp.user_id = u.id AND pp.deleted_at IS NULL) AS post_count,
                    (SELECT COUNT(*) FROM collaborations c WHERE c.user_id = u.id AND c.deleted_at IS NULL) AS collabs_owned,
                    (SELECT COUNT(*) FROM user_collaboration uc WHERE uc.user_id = u.id) AS collabs_joined,
                    (SELECT COUNT(*) FROM moderation_actions ma WHERE ma.target_owner_id = u.id AND ma.strike = 1 AND ma.reverted_at IS NULL) AS strike_count
             FROM users u
             LEFT JOIN profiles p ON p.user_id = u.id
             WHERE u.id = ?
             LIMIT 1"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $user = self::userRow($row);
        $user['full_name'] = $row['full_name'] ?? '';
        $user['bio'] = $row['bio'] ?? '';

        $stmt = $pdo->prepare(
            "SELECT s.skill_name FROM user_skill us JOIN skills s ON s.id = us.skill_id WHERE us.user_id = ? ORDER BY s.skill_name"
        );
        $stmt->execute([$id]);
        $skills = array_map(static fn ($r) => $r['skill_name'], $stmt->fetchAll());

        $stmt = $pdo->prepare(
            "SELECT id, description, view_count, moderation_state, created_at
             FROM public_posts WHERE user_id = ? AND deleted_at IS NULL
             ORDER BY created_at DESC LIMIT 10"
        );
        $stmt->execute([$id]);
        $posts = $stmt->fetchAll();

        $stmt = $pdo->prepare(
            "SELECT id, title, status, moderation_state, created_at, 'owner' AS relation
             FROM collaborations WHERE user_id = ? AND deleted_at IS NULL
             UNION ALL
             SELECT c.id, c.title, c.status, c.moderation_state, c.created_at, 'member' AS relation
             FROM user_collaboration uc JOIN collaborations c ON c.id = uc.collaboration_id
             WHERE uc.user_id = ? AND c.deleted_at IS NULL AND c.user_id != ?
             ORDER BY created_at DESC LIMIT 10"
        );
        $stmt->execute([$id, $id, $id]);
        $collabs = $stmt->fetchAll();

        $stmt = $pdo->prepare(
            "SELECT ma.id, ma.action_type, ma.target_type, ma.target_id, ma.reason,
                    ma.strike, ma.reverted_at, ma.created_at, actor.username AS actor_name
             FROM moderation_actions ma
             JOIN users actor ON actor.id = ma.actor_id
             WHERE ma.target_owner_id = ?
             ORDER BY ma.created_at DESC LIMIT 20"
        );
        $stmt->execute([$id]);
        $history = $stmt->fetchAll();

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM reports r WHERE r.status = 'open' AND (
                (r.target_type = 'user' AND r.target_id = ?)
                OR (r.target_type = 'post' AND r.target_id IN (SELECT id FROM public_posts WHERE user_id = ?))
                OR (r.target_type = 'collab' AND r.target_id IN (SELECT id FROM collaborations WHERE user_id = ?))
             )"
        );
        $stmt->execute([$id, $id, $id]);
        $reportsOpen = (int) $stmt->fetchColumn();

        return [
            'user' => $user,
            'skills' => $skills,
            'posts' => $posts,
            'collabs' => $collabs,
            'moderation_history' => $history,
            'reports_open_against' => $reportsOpen,
        ];
    }

    /** Daftar konten per jenis untuk halaman Content (termasuk removed). */
    public static function listContent(string $type, string $state, string $q, int $page, int $limit): array
    {
        $pdo = getDB();
        $offset = ($page - 1) * $limit;
        $stateSql = '';
        $args = [];
        if ($state === 'visible' || $state === 'removed') {
            $stateSql = " AND moderation_state = ?";
            $args[] = $state;
        }
        $qSql = '';
        if ($q !== '') {
            $like = self::like($q);
            if ($type === 'posts') {
                $qSql = " AND (pp.description LIKE ? OR u.username LIKE ?)";
            } elseif ($type === 'comments') {
                $qSql = " AND (cm.content LIKE ? OR u.username LIKE ?)";
            } else {
                $qSql = " AND (c.title LIKE ? OR u.username LIKE ?)";
            }
            $args[] = $like;
            $args[] = $like;
        }

        if ($type === 'posts') {
            $from = "FROM public_posts pp JOIN users u ON u.id = pp.user_id LEFT JOIN profiles p ON p.user_id = u.id WHERE pp.deleted_at IS NULL";
            $countStmt = $pdo->prepare("SELECT COUNT(*) $from $stateSql $qSql");
            $countStmt->execute($args);
            $total = (int) $countStmt->fetchColumn();
            $stmt = $pdo->prepare(
                "SELECT pp.id, pp.description, pp.view_count, pp.moderation_state, pp.removed_at, pp.removal_reason, pp.created_at,
                        u.id AS owner_id, u.username AS owner_name, p.photo_profile_url AS owner_photo,
                        (SELECT COUNT(*) FROM comments cm WHERE cm.post_id = pp.id AND cm.deleted_at IS NULL) AS comment_count,
                        (SELECT COUNT(*) FROM reports r WHERE r.target_type = 'post' AND r.target_id = pp.id AND r.status = 'open') AS report_count
                 $from $stateSql $qSql
                 ORDER BY pp.created_at DESC LIMIT $limit OFFSET $offset"
            );
            $stmt->execute($args);
            $items = $stmt->fetchAll();
        } elseif ($type === 'comments') {
            $from = "FROM comments cm JOIN users u ON u.id = cm.user_id LEFT JOIN profiles p ON p.user_id = u.id WHERE cm.deleted_at IS NULL";
            $countStmt = $pdo->prepare("SELECT COUNT(*) $from $stateSql $qSql");
            $countStmt->execute($args);
            $total = (int) $countStmt->fetchColumn();
            $stmt = $pdo->prepare(
                "SELECT cm.id, cm.content, cm.post_id, cm.moderation_state, cm.removed_at, cm.removal_reason, cm.created_at,
                        u.id AS owner_id, u.username AS owner_name, p.photo_profile_url AS owner_photo,
                        (SELECT COUNT(*) FROM reports r WHERE r.target_type = 'comment' AND r.target_id = cm.id AND r.status = 'open') AS report_count
                 $from $stateSql $qSql
                 ORDER BY cm.created_at DESC LIMIT $limit OFFSET $offset"
            );
            $stmt->execute($args);
            $items = $stmt->fetchAll();
        } else {
            $from = "FROM collaborations c JOIN users u ON u.id = c.user_id LEFT JOIN profiles p ON p.user_id = u.id WHERE c.deleted_at IS NULL";
            $countStmt = $pdo->prepare("SELECT COUNT(*) $from $stateSql $qSql");
            $countStmt->execute($args);
            $total = (int) $countStmt->fetchColumn();
            $stmt = $pdo->prepare(
                "SELECT c.id, c.title, c.description, c.status, c.required_member, c.due_date, c.moderation_state, c.removed_at, c.removal_reason, c.created_at,
                        u.id AS owner_id, u.username AS owner_name, p.photo_profile_url AS owner_photo,
                        (SELECT COUNT(*) FROM user_collaboration uc WHERE uc.collaboration_id = c.id) AS member_count,
                        (SELECT COUNT(*) FROM reports r WHERE r.target_type = 'collab' AND r.target_id = c.id AND r.status = 'open') AS report_count
                 $from $stateSql $qSql
                 ORDER BY c.created_at DESC LIMIT $limit OFFSET $offset"
            );
            $stmt->execute($args);
            $items = $stmt->fetchAll();
        }
        return ['items' => $items, 'total' => $total];
    }

    /** Aktivitas moderasi terakhir untuk Overview. */
    public static function recentActivity(int $limit = 10): array
    {
        $pdo = getDB();
        $stmt = $pdo->prepare(
            "SELECT ma.id, ma.action_type, ma.target_type, ma.target_id, ma.reason, ma.strike, ma.created_at,
                    actor.username AS actor_name, owner.username AS owner_name
             FROM moderation_actions ma
             JOIN users actor ON actor.id = ma.actor_id
             LEFT JOIN users owner ON owner.id = ma.target_owner_id
             ORDER BY ma.created_at DESC LIMIT $limit"
        );
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
