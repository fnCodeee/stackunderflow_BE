<?php

/**
 * controllers/CircleController.php
 * =============================================================================
 * CIRCLE CONTROLLER (Phase 1: fondasi Circle & Zone)
 *
 * Keputusan desain 2026-11-18 (Raka + Faiz):
 *  - Circle TIDAK dibuat otomatis saat collaboration dibuat. Owner
 *    memilih di form pembuatan collaboration (group_mode: circle /
 *    external / none), atau membuat Circle belakangan lewat endpoint
 *    POST /api/collab/:id/circle di controller ini.
 *  - Satu collaboration memiliki paling banyak satu Circle (1:1).
 *  - Setiap Circle memiliki dua Zone bawaan: announcements (hanya
 *    owner yang bisa mengirim) dan general (semua anggota bisa).
 *  - Akses Circle hanya untuk owner dan accepted member
 *    (baris user_collaboration; owner selalu terdaftar di sana).
 *  - Permission zone dikirim ke frontend sebagai `memberCanPost`
 *    yang sudah dihitung untuk user yang sedang request.
 *  - Identitas rute memakai circle.id, bukan collaboration.id.
 *  - Status collaboration dikirim sebagai key mentah (mis. "open");
 *    label tampilan diterjemahkan oleh frontend.
 *
 * Endpoint messaging belum ada di Phase 1 (roadmap BE: fondasi,
 * messaging, reactions/pin/attachments, resources, realtime).
 * =============================================================================
 */

declare(strict_types=1);

require_once BASE_PATH . '/models/CollabModel.php';
require_once BASE_PATH . '/models/CircleModel.php';
require_once BASE_PATH . '/models/CircleZoneModel.php';
require_once BASE_PATH . '/utils/Response.php';

class CircleController
{
    /**
     * POST /api/collab/:id/circle
     * Owner membuat Circle untuk collaboration miliknya (jalur
     * "belum dulu" di form pembuatan, atau mengganti link eksternal
     * menjadi Circle). Body opsional: { title } — default judul
     * collaboration. Circle yang sudah ada dibalas 409.
     *
     * @param  array<string, string> $params
     */
    public static function createForCollab(array $params = []): void
    {
        try {
            $userId = $GLOBALS['user']['id'] ?? null;
            if (!$userId) {
                Response::json(null, 401, 'Unauthorized', false);
            }

            $collabId = $params['id'] ?? '';
            $collab = $collabId !== '' ? CollabModel::findById($collabId) : null;
            if ($collab === null) {
                Response::json(null, 404, 'Collaboration not found', false);
            }

            if (($collab['user_id'] ?? null) !== (string) $userId) {
                Response::json(null, 403, 'Only the project owner can create its Circle', false);
            }

            if (CircleModel::findByCollaborationId($collabId) !== null) {
                Response::json(null, 409, 'Circle already exists for this collaboration', false);
            }

            $body = $GLOBALS['body'] ?? [];
            $title = trim((string) ($body['title'] ?? ''));
            if ($title === '') {
                $title = (string) $collab['title'];
            }
            if (strlen($title) > 255) {
                Response::json(null, 400, 'Circle title is too long', false);
            }

            $pdo = getDB();
            $pdo->beginTransaction();
            try {
                $circle = CircleModel::create([
                    'collaboration_id' => $collabId,
                    'title' => $title,
                    'photo_profile_url' => null,
                ], $pdo);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }

            Response::json(
                self::detailPayload($circle, $collab, true),
                200,
                'Circle created successfully',
                true
            );
        } catch (Throwable $e) {
            error_log('[CIRCLE ERROR] ' . $e->getMessage());
            Response::json(null, 500, 'Terjadi kesalahan pada server saat membuat circle', false);
        }
    }

    /**
     * GET /api/circles
     * Daftar Circle yang bisa diakses user (owner / accepted member)
     * dan memang sudah dibuat.
     *
     * @param  array<string, string> $params
     */
    public static function listCircles(array $params = []): void
    {
        try {
            $userId = $GLOBALS['user']['id'] ?? null;
            if (!$userId) {
                Response::json(null, 401, 'Unauthorized', false);
            }

            $result = [];
            foreach (CircleModel::listForUser((string) $userId) as $row) {
                $members = CollabModel::acceptedMembers((string) $row['collaboration_id']);
                $result[] = [
                    'id' => $row['id'],
                    'projectId' => $row['collaboration_id'],
                    'title' => $row['title'],
                    'status' => $row['status'],
                    'isOwner' => ($row['user_id'] ?? null) === (string) $userId,
                    'memberNames' => array_map(
                        static fn (array $m): string => (string) ($m['username'] ?? ''),
                        $members
                    ),
                    'memberCount' => count($members),
                ];
            }

            Response::json($result, 200, 'Circles retrieved successfully', true);
        } catch (Throwable $e) {
            error_log('[CIRCLE ERROR] ' . $e->getMessage());
            Response::json(null, 500, 'Terjadi kesalahan pada server saat mengambil circles', false);
        }
    }

    /**
     * GET /api/circles/:circleId
     * Detail Circle beserta daftar Zone dan Member.
     *
     * @param  array<string, string> $params
     */
    public static function getCircle(array $params = []): void
    {
        try {
            $userId = $GLOBALS['user']['id'] ?? null;
            if (!$userId) {
                Response::json(null, 401, 'Unauthorized', false);
            }

            $access = self::resolveAccess((string) ($params['circleId'] ?? ''), (string) $userId);

            Response::json(
                self::detailPayload($access['circle'], $access['collab'], $access['isOwner']),
                200,
                'Circle retrieved successfully',
                true
            );
        } catch (Throwable $e) {
            error_log('[CIRCLE ERROR] ' . $e->getMessage());
            Response::json(null, 500, 'Terjadi kesalahan pada server saat mengambil circle', false);
        }
    }

    /**
     * GET /api/circles/:circleId/zones
     *
     * @param  array<string, string> $params
     */
    public static function listZones(array $params = []): void
    {
        try {
            $userId = $GLOBALS['user']['id'] ?? null;
            if (!$userId) {
                Response::json(null, 401, 'Unauthorized', false);
            }

            $access = self::resolveAccess((string) ($params['circleId'] ?? ''), (string) $userId);

            $zones = array_map(
                static fn (array $z): array => self::presentZone($z, $access['isOwner']),
                CircleZoneModel::listByCircleId((string) $access['circle']['id'])
            );

            Response::json($zones, 200, 'Zones retrieved successfully', true);
        } catch (Throwable $e) {
            error_log('[CIRCLE ERROR] ' . $e->getMessage());
            Response::json(null, 500, 'Terjadi kesalahan pada server saat mengambil zones', false);
        }
    }

    /**
     * GET /api/circles/:circleId/zones/:zoneId
     *
     * @param  array<string, string> $params
     */
    public static function getZone(array $params = []): void
    {
        try {
            $userId = $GLOBALS['user']['id'] ?? null;
            if (!$userId) {
                Response::json(null, 401, 'Unauthorized', false);
            }

            $access = self::resolveAccess((string) ($params['circleId'] ?? ''), (string) $userId);

            $zone = CircleZoneModel::findById((string) ($params['zoneId'] ?? ''));
            if ($zone === null || ($zone['circle_id'] ?? null) !== (string) $access['circle']['id']) {
                Response::json(null, 404, 'Zone not found', false);
            }

            $payload = self::presentZone($zone, $access['isOwner']);
            $payload['circleId'] = (string) $access['circle']['id'];

            Response::json($payload, 200, 'Zone retrieved successfully', true);
        } catch (Throwable $e) {
            error_log('[CIRCLE ERROR] ' . $e->getMessage());
            Response::json(null, 500, 'Terjadi kesalahan pada server saat mengambil zone', false);
        }
    }

    /**
     * Mengambil circle + collaboration dan memastikan user berhak
     * mengaksesnya (owner atau accepted member). Mengirim response
     * error (404/403) dan menghentikan eksekusi bila tidak berhak.
     *
     * @return array{circle: array<string, mixed>, collab: array<string, mixed>, isOwner: bool}
     */
    private static function resolveAccess(string $circleId, string $userId): array
    {
        $circle = $circleId !== '' ? CircleModel::findById($circleId) : null;
        if ($circle === null) {
            Response::json(null, 404, 'Circle not found', false);
        }

        $collab = CollabModel::findById((string) $circle['collaboration_id']);
        if ($collab === null) {
            Response::json(null, 404, 'Circle not found', false);
        }

        $isOwner = ($collab['user_id'] ?? null) === $userId;
        if (!$isOwner && !CollabModel::isMember((string) $circle['collaboration_id'], $userId)) {
            Response::json(null, 403, 'You are not a member of this circle', false);
        }

        return ['circle' => $circle, 'collab' => $collab, 'isOwner' => $isOwner];
    }

    /**
     * Payload detail Circle: zones (dengan memberCanPost untuk user
     * yang request) dan members.
     *
     * @param  array<string, mixed> $circle
     * @param  array<string, mixed> $collab
     * @return array<string, mixed>
     */
    private static function detailPayload(array $circle, array $collab, bool $isOwner): array
    {
        $ownerId = (string) ($collab['user_id'] ?? '');

        $zones = array_map(
            static fn (array $z): array => self::presentZone($z, $isOwner),
            CircleZoneModel::listByCircleId((string) $circle['id'])
        );

        $members = array_map(
            static fn (array $m): array => [
                'userId' => $m['id'],
                'name' => $m['username'] ?? '',
                'avatarUrl' => $m['photo_profile_url'] ?? null,
                'isOwner' => ($m['id'] ?? null) === $ownerId,
            ],
            CollabModel::acceptedMembers((string) $circle['collaboration_id'])
        );

        return [
            'id' => $circle['id'],
            'projectId' => $circle['collaboration_id'],
            'title' => $circle['title'],
            'photoUrl' => $circle['photo_profile_url'] ?? null,
            'status' => $collab['status'] ?? 'open',
            'zones' => $zones,
            'members' => $members,
        ];
    }

    /**
     * Bentuk zone untuk frontend. `memberCanPost` dihitung untuk
     * user yang sedang request: zone dengan allow_public_message = 0
     * (announcements) hanya bisa diisi owner.
     *
     * @param  array<string, mixed> $zone
     * @return array<string, mixed>
     */
    private static function presentZone(array $zone, bool $isOwner): array
    {
        $publicCanPost = (int) ($zone['allow_public_message'] ?? 0) === 1;

        return [
            'id' => $zone['id'],
            'title' => $zone['title'],
            'memberCanPost' => $publicCanPost || $isOwner,
            // Diisi endpoint messaging pada Phase 2 (id pesan terakhir
            // yang dibaca user); Phase 1 belum ada pesan sama sekali.
            'lastRead' => null,
        ];
    }
}
