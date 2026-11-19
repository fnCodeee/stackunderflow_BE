<?php

/**
 * controllers/UserController.php
 * ─────────────────────────────────────────────────────────────────────────────
 * GET /api/users/:id — public person detail for the profile page.
 * Added 2026-11-10 (fullstack connect): the frontend's getPerson() calls
 * this path; the backend previously only had GET /api/profiles/me.
 * "me" resolves to the logged-in user (requires a token).
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

require_once BASE_PATH . '/models/UserModel.php';
require_once BASE_PATH . '/models/ProfileModel.php';
require_once BASE_PATH . '/models/UserSkillModel.php';
require_once BASE_PATH . '/models/ProfileSocialMediaModel.php';

class UserController
{
    public static function getUser(array $params = []): void
    {
        try {
            $id = $params['id'] ?? '';
            if ($id === 'me') {
                $id = $GLOBALS['user']['id'] ?? '';
                if ($id === '') {
                    Response::json(null, 401, 'Unauthorized', false);
                }
            }
            if ($id === '') {
                Response::json(null, 404, 'User not found', false);
            }

            $user = UserModel::findById($id);
            if (!$user) {
                Response::json(null, 404, 'User not found', false);
            }

            $profile = ProfileModel::findByUserId($id);
            $skills = UserSkillModel::getByUserId($id);
            $socials = ProfileSocialMediaModel::getByUserId($id);

            Response::json([
                'id' => $user['id'],
                'aliases' => [$user['id']],
                'name' => ($profile['full_name'] ?? '') !== '' ? $profile['full_name'] : $user['username'],
                'bio' => $profile['bio'] ?? '',
                'profileDetail' => $profile['profile_detail'] ?? null,
                'email' => $user['email'] ?? '',
                'username' => $user['username'] ?? '',
                'avatarUrl' => $profile['photo_profile_url'] ?? null,
                'createdAt' => $user['created_at'] ?? null,
                'socials' => array_map(static function (array $s): array {
                    return [
                        'label' => self::compactUrl((string) ($s['url'] ?? '')),
                        'icon' => strtolower((string) ($s['platform'] ?? '')),
                        'href' => $s['url'] ?? '',
                    ];
                }, $socials),
                'skills' => array_map(static function (array $s): array {
                    return [
                        'id' => $s['id'] ?? $s['skill_id'] ?? '',
                        'label' => $s['skill_name'] ?? '',
                    ];
                }, $skills),
            ], 200, 'User found', true);
        } catch (Throwable $e) {
            error_log('[USER ERROR] ' . $e->getMessage());
            Response::json(null, 500, 'Terjadi kesalahan pada server saat mengambil data pengguna', false);
        }
    }

    /**
     * GET /api/users/:id/activity: per-day collaboration activity for
     * the profile heatmap. Counts posts, visible comments, projects
     * created and projects joined (other people's) over the last
     * 365 days, merged into one date => count map plus per-source
     * totals. Soft-deleted and AI-removed content never counts.
     */
    /** "https://www.github.com/user/" -> "github.com/user" for rail labels. */
    private static function compactUrl(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            return $url;
        }
        $host = preg_replace('/^www\./', '', (string) $parts['host']);
        $path = isset($parts['path']) ? rtrim((string) $parts['path'], '/') : '';
        return $host . $path;
    }

    public static function getUserActivity(array $params = []): void
    {
        try {
            $id = $params['id'] ?? '';
            if ($id === 'me') {
                $id = $GLOBALS['user']['id'] ?? '';
                if ($id === '') {
                    Response::json(null, 401, 'Unauthorized', false);
                }
            }
            if ($id === '' || !UserModel::findById($id)) {
                Response::json(null, 404, 'User not found', false);
            }

            $since = date('Y-m-d', strtotime('-364 days'));
            $pdo = getDB();
            $sources = [
                'posts' => "SELECT DATE(created_at) AS d, COUNT(*) AS c FROM public_posts WHERE user_id = :uid AND deleted_at IS NULL AND created_at >= :since GROUP BY DATE(created_at)",
                'comments' => "SELECT DATE(created_at) AS d, COUNT(*) AS c FROM comments WHERE user_id = :uid AND deleted_at IS NULL AND moderation_state = 'visible' AND created_at >= :since GROUP BY DATE(created_at)",
                'projectsCreated' => "SELECT DATE(created_at) AS d, COUNT(*) AS c FROM collaborations WHERE user_id = :uid AND deleted_at IS NULL AND created_at >= :since GROUP BY DATE(created_at)",
                'projectsJoined' => "SELECT DATE(uc.created_at) AS d, COUNT(*) AS c FROM user_collaboration uc JOIN collaborations c ON c.id = uc.collaboration_id WHERE uc.user_id = :uid AND c.user_id <> :uidOther AND c.deleted_at IS NULL AND uc.created_at >= :since GROUP BY DATE(uc.created_at)",
            ];
            $days = [];
            $totals = ['posts' => 0, 'comments' => 0, 'projectsCreated' => 0, 'projectsJoined' => 0];
            foreach ($sources as $key => $sql) {
                $stmt = $pdo->prepare($sql);
                $stmt->bindValue(':uid', $id);
                if ($key === 'projectsJoined') {
                    $stmt->bindValue(':uidOther', $id);
                }
                $stmt->bindValue(':since', $since);
                $stmt->execute();
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $count = (int) $row['c'];
                    $totals[$key] += $count;
                    $days[$row['d']] = ($days[$row['d']] ?? 0) + $count;
                }
            }
            ksort($days);
            $totals['total'] = array_sum($totals);
            Response::json([
                'days' => $days,
                'totals' => $totals,
                'since' => $since,
            ], 200, 'User activity', true);
        } catch (Throwable $e) {
            error_log('[USER ERROR] ' . $e->getMessage());
            Response::json(null, 500, 'Terjadi kesalahan pada server saat mengambil aktivitas pengguna', false);
        }
    }
}
