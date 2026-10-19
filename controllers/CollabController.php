<?php

/**
 * controllers/CollabController.php
 * ─────────────────────────────────────────────────────────────────────────────
 * COLLABORATION CONTROLLER
 *
 * Menangani operasi kolaborasi pengguna:
 *  - createCollab() / addCollab() -> Phase 4C (POST /api/collab)
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

require_once BASE_PATH . '/models/CollabModel.php';
require_once BASE_PATH . '/utils/Response.php';

class CollabController
{
    /**
     * Handler untuk POST /api/collab
     * Membuat postingan kolaborasi baru beserta media, skills, dan otomatis membuat satu circle.
     *
     * @param  array<string, string> $params Path parameters dari Router
     * @return void
     */
    public static function createCollab(array $params = []): void
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
            $requiredMember = $body['required_member'] ?? $body['requiredMember'] ?? null;
            $mediaUrls      = $body['media_urls'] ?? $body['mediaUrls'] ?? [];
            $mediaPublicIds = $body['media_public_ids'] ?? $body['mediaPublicIds'] ?? [];
            $skillsNeeded   = $body['skills_needed'] ?? $body['skillsNeeded'] ?? null;

            // 2. Validasi field wajib sesuai baseline Express (kecuali communicationUrl yang sudah deprecated)
            if (
                $title === '' ||
                $description === '' ||
                $requiredMember === null ||
                $requiredMember === '' ||
                $skillsNeeded === null ||
                !is_array($skillsNeeded) ||
                count($skillsNeeded) === 0
            ) {
                Response::json(null, 400, 'Seluruh Kolom wajib diisi.', false);
            }

            $requiredMemberInt = (int)$requiredMember;
            if ($requiredMemberInt < 1) {
                Response::json(null, 400, 'Seluruh Kolom wajib diisi.', false);
            }

            // 3. Validasi konsistensi jumlah mediaUrls dan mediaPublicIds jika mediaPublicIds dikirim
            if (is_array($mediaPublicIds) && count($mediaPublicIds) > 0 && count($mediaUrls) !== count($mediaPublicIds)) {
                Response::json(null, 400, 'Count of mediaUrls and mediaPublicIds must be same!', false);
            }

            // 4. Simpan ke database melalui CollabModel dalam transaksi atomik (collab + media + skills + circle)
            $result = CollabModel::create([
                'user_id'          => $userId,
                'title'            => $title,
                'description'      => $description,
                'required_member'  => $requiredMemberInt,
                'media_urls'       => is_array($mediaUrls) ? array_values($mediaUrls) : [],
                'media_public_ids' => is_array($mediaPublicIds) ? array_values($mediaPublicIds) : [],
                'skills_needed'    => array_values($skillsNeeded),
            ]);

            Response::json($result, 200, 'Collaboration created successfully', true);
        } catch (Throwable $e) {
            error_log('[COLLAB ERROR] ' . $e->getMessage());
            Response::json(null, 500, 'Terjadi kesalahan pada server saat membuat kolaborasi', false);
        }
    }

    /**
     * Alias method addCollab sesuai nama method di Express controller
     *
     * @param  array<string, string> $params
     * @return void
     */
    public static function addCollab(array $params = []): void
    {
        self::createCollab($params);
    }
}
