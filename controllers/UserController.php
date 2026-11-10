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
                'email' => $user['email'] ?? '',
                'socials' => array_map(static function (array $s): array {
                    return [
                        'label' => $s['platform'] ?? '',
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
}
