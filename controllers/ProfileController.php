<?php

/**
 * controllers/ProfileController.php
 * ─────────────────────────────────────────────────────────────────────────────
 * PROFILE CONTROLLER
 *
 * Menangani operasi profile pengguna:
 *  - updateProfile() -> Phase 2D-2 & 2D-3B (PUT /api/profiles/me)
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

require_once BASE_PATH . '/models/ProfileModel.php';
require_once BASE_PATH . '/models/ProfileSocialMediaModel.php';
require_once BASE_PATH . '/utils/Response.php';

class ProfileController
{
    /**
     * Handler untuk PUT /api/profiles/me
     * Memperbarui data personal profil dan sinkronisasi social media user yang sedang login (partial update).
     *
     * @param  array<string, string> $params Path parameters dari Router
     * @return void
     */
    public static function updateProfile(array $params = []): void
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

            // 2. Cari profil berdasarkan user_id dari token
            $existingProfile = ProfileModel::findByUserId($userId);
            if ($existingProfile === null) {
                Response::json(null, 404, 'Profile Tidak di temukan!', false);
            }

            // 3. Validasi tipe data personal profile (jika field dikirimkan)
            if (array_key_exists('full_name', $body) && !is_string($body['full_name'])) {
                Response::json(null, 400, 'full_name harus berupa string', false);
            }

            if (array_key_exists('bio', $body) && !is_string($body['bio']) && !is_null($body['bio'])) {
                Response::json(null, 400, 'bio harus berupa string', false);
            }

            if (array_key_exists('photo_profile_url', $body) && !is_string($body['photo_profile_url']) && !is_null($body['photo_profile_url'])) {
                Response::json(null, 400, 'photo_profile_url harus berupa string', false);
            }

            if (array_key_exists('photo_profile_public_id', $body) && !is_string($body['photo_profile_public_id']) && !is_null($body['photo_profile_public_id'])) {
                Response::json(null, 400, 'photo_profile_public_id harus berupa string', false);
            }

            // 4. Validasi social_media (jika field dikirimkan)
            if (array_key_exists('social_media', $body)) {
                if (!is_array($body['social_media'])) {
                    Response::json(null, 400, 'social_media harus berupa array', false);
                }

                foreach ($body['social_media'] as $item) {
                    if (!is_array($item)) {
                        Response::json(null, 400, 'Setiap item social_media harus berupa object', false);
                    }

                    if (
                        !isset($item['platform']) || !is_string($item['platform']) || trim($item['platform']) === '' ||
                        !isset($item['url']) || !is_string($item['url']) || trim($item['url']) === ''
                    ) {
                        Response::json(null, 400, 'Setiap item social_media wajib memiliki platform dan url bertipe string', false);
                    }
                }
            }

            // 5. Susun data update profile personal (hanya field yang dikirimkan)
            $updateData = [];

            if (array_key_exists('full_name', $body)) {
                $updateData['full_name'] = (string) $body['full_name'];
            }

            if (array_key_exists('bio', $body)) {
                $updateData['bio'] = $body['bio'] !== null ? (string) $body['bio'] : null;
            }

            if (array_key_exists('photo_profile_url', $body)) {
                $updateData['photo_profile_url'] = $body['photo_profile_url'] !== null ? (string) $body['photo_profile_url'] : null;
            }

            if (array_key_exists('photo_profile_public_id', $body)) {
                $updateData['photo_profile_public_id'] = $body['photo_profile_public_id'] !== null ? (string) $body['photo_profile_public_id'] : null;
            }

            // 6. Eksekusi update profile & sinkronisasi social media dalam satu PDO Transaction
            $pdo = getDB();
            $pdo->beginTransaction();

            try {
                if (!empty($updateData)) {
                    ProfileModel::updateByUserId($userId, $updateData);
                }

                if (array_key_exists('social_media', $body)) {
                    ProfileSocialMediaModel::syncByUserId($userId, $body['social_media']);
                }

                $pdo->commit();
            } catch (Throwable $dbError) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $dbError;
            }

            // 7. Ambil data terbaru setelah update
            $updatedProfile = ProfileModel::findByUserId($userId);
            $socialMedia = ProfileSocialMediaModel::getByUserId($userId);
            if (array_key_exists("social_media", $body)) {
                ProfileSocialMediaModel::syncByUserId($userId, $body['social_media']);
                ProfileModel::touch($userId);
            };

            // 8. Format response JSON (snake_case)
            $responseData = [
                'id' => $updatedProfile['id'],
                'user_id' => $updatedProfile['user_id'],
                'full_name' => $updatedProfile['full_name'] ?? '',
                'bio' => $updatedProfile['bio'] ?? '',
                'photo_profile_url' => $updatedProfile['photo_profile_url'] ?? ProfileModel::DEFAULT_PHOTO_URL,
                'photo_profile_public_id' => $updatedProfile['photo_profile_public_id'] ?? '',
                'social_media' => $socialMedia,
                'created_at' => $updatedProfile['created_at'] ?? null,
                'updated_at' => $updatedProfile['updated_at'] ?? null,
            ];

            Response::json($responseData, 200, 'Profile berhasil diperbarui!', true);
        } catch (Throwable $e) {
            Response::json(null, 500, $e->getMessage(), false);
        }
    }
}
