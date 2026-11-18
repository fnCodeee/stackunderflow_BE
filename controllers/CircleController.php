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
 * Phase 2 (messaging): POST/GET pesan per zone di controller ini
 * juga. Pesan tersimpan disiarkan live lewat utils/Realtime
 * (event zone-message) ke semua anggota. Reactions/pin/attachments
 * tetap belum ada (Phase 3).
 * =============================================================================
 */

declare(strict_types=1);

require_once BASE_PATH . '/models/CollabModel.php';
require_once BASE_PATH . '/models/CircleModel.php';
require_once BASE_PATH . '/models/CircleZoneModel.php';
require_once BASE_PATH . '/models/ZoneMessageModel.php';
require_once BASE_PATH . '/utils/Realtime.php';
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
                    'photoProfileUrl' => $row['photo_profile_url'] ?? null,
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
     * GET /api/circles/:circleId/zones/:zoneId/messages
     * Riwayat pesan satu zone, urutan kronologis (tertua -> terbaru).
     * Query: limit (default 50, maks 100) dan kursor before_ts +
     * before_id (pesan tertua yang sudah dimiliki klien) untuk
     * memuat pesan yang lebih lama. Response data:
     * { messages: [...], hasMore: bool }.
     *
     * @param  array<string, string> $params
     */
    public static function listMessages(array $params = []): void
    {
        try {
            $userId = $GLOBALS['user']['id'] ?? null;
            if (!$userId) {
                Response::json(null, 401, 'Unauthorized', false);
            }

            $access = self::resolveAccess((string) ($params['circleId'] ?? ''), (string) $userId);
            $zone = self::resolveZone($access, (string) ($params['zoneId'] ?? ''));

            $limit = max(1, min(100, (int) ($_GET['limit'] ?? 50)));
            $beforeTs = trim((string) ($_GET['before_ts'] ?? ''));
            $beforeId = trim((string) ($_GET['before_id'] ?? ''));
            $before = ($beforeTs !== '' && $beforeId !== '')
                ? ['timestamp' => $beforeTs, 'id' => $beforeId]
                : null;

            // Ambil satu baris ekstra untuk tahu masih ada pesan
            // yang lebih lama di luar halaman ini.
            $rows = ZoneMessageModel::listByZone((string) $zone['id'], $limit + 1, $before);
            $hasMore = count($rows) > $limit;
            if ($hasMore) {
                array_pop($rows);
            }

            $messages = array_map(
                static fn (array $r): array => self::presentMessage($r),
                array_reverse($rows)
            );

            Response::json(
                ['messages' => $messages, 'hasMore' => $hasMore],
                200,
                'Messages retrieved successfully',
                true
            );
        } catch (Throwable $e) {
            error_log('[CIRCLE ERROR] ' . $e->getMessage());
            Response::json(null, 500, 'Terjadi kesalahan pada server saat mengambil pesan', false);
        }
    }

    /**
     * POST /api/circles/:circleId/zones/:zoneId/messages
     * Kirim pesan teks ke satu zone. Body: { content }.
     * Aturan kirim mengikuti memberCanPost zone itu untuk user yang
     * request: announcements menolak anggota biasa (403), general
     * menerima semua anggota. Pesan yang tersimpan langsung
     * disiarkan ke semua anggota lewat server realtime.
     *
     * @param  array<string, string> $params
     */
    public static function sendMessage(array $params = []): void
    {
        try {
            $userId = $GLOBALS['user']['id'] ?? null;
            if (!$userId) {
                Response::json(null, 401, 'Unauthorized', false);
            }

            $access = self::resolveAccess((string) ($params['circleId'] ?? ''), (string) $userId);
            $zone = self::resolveZone($access, (string) ($params['zoneId'] ?? ''));

            $canPost = (int) ($zone['allow_public_message'] ?? 0) === 1 || $access['isOwner'];
            if (!$canPost) {
                Response::json(null, 403, 'Only the project owner can post in this zone', false);
            }

            $body = $GLOBALS['body'] ?? [];
            $content = trim((string) ($body['content'] ?? ''));
            if ($content === '') {
                Response::json(null, 400, 'Message cannot be empty', false);
            }
            if (mb_strlen($content) > 2000) {
                Response::json(null, 400, 'Message is too long (max 2000 characters)', false);
            }

            $row = ZoneMessageModel::create((string) $zone['id'], (string) $userId, $content);
            $message = self::presentMessage($row);

            // Siaran live ke semua anggota circle (pengirim termasuk,
            // untuk tab/perangkat lain miliknya). Best-effort:
            // Realtime::publish tidak pernah throw.
            foreach (CollabModel::acceptedMembers((string) $access['circle']['collaboration_id']) as $member) {
                Realtime::publishZoneMessage(
                    (string) $member['id'],
                    (string) $access['circle']['id'],
                    (string) $zone['id'],
                    $message
                );
            }

            Response::json($message, 201, 'Message sent successfully', true);
        } catch (Throwable $e) {
            error_log('[CIRCLE ERROR] ' . $e->getMessage());
            Response::json(null, 500, 'Terjadi kesalahan pada server saat mengirim pesan', false);
        }
    }

    /**
     * POST /api/circles/:circleId/zones
     * Owner menambah zone baru. Body: { title, allowPublicMessage? }.
     * allowPublicMessage default true (semua anggota boleh posting);
     * false membuat zone hanya bisa diisi owner (pola announcements).
     *
     * @param  array<string, string> $params
     */
    public static function createZone(array $params = []): void
    {
        try {
            $userId = $GLOBALS['user']['id'] ?? null;
            if (!$userId) {
                Response::json(null, 401, 'Unauthorized', false);
            }

            $access = self::resolveAccess((string) ($params['circleId'] ?? ''), (string) $userId);
            if (!$access['isOwner']) {
                Response::json(null, 403, 'Only the project owner can add zones', false);
            }

            $body = $GLOBALS['body'] ?? [];
            $title = trim((string) ($body['title'] ?? ''));
            if ($title === '') {
                Response::json(null, 400, 'Zone title is required', false);
            }
            if (mb_strlen($title) > 100) {
                Response::json(null, 400, 'Zone title is too long (max 100 characters)', false);
            }
            $allowPublic = array_key_exists('allowPublicMessage', $body)
                ? (bool) $body['allowPublicMessage']
                : true;

            $zone = CircleZoneModel::createZone(
                (string) $access['circle']['id'],
                $title,
                $allowPublic
            );

            Response::json(self::presentZone($zone, true), 201, 'Zone created successfully', true);
        } catch (Throwable $e) {
            error_log('[CIRCLE ERROR] ' . $e->getMessage());
            Response::json(null, 500, 'Terjadi kesalahan pada server saat membuat zone', false);
        }
    }

    /**
     * PUT /api/circles/:circleId/zones/:zoneId
     * Owner mengubah zone. Body: { title?, allowPublicMessage? }.
     *
     * @param  array<string, string> $params
     */
    public static function updateZone(array $params = []): void
    {
        try {
            $userId = $GLOBALS['user']['id'] ?? null;
            if (!$userId) {
                Response::json(null, 401, 'Unauthorized', false);
            }

            $access = self::resolveAccess((string) ($params['circleId'] ?? ''), (string) $userId);
            if (!$access['isOwner']) {
                Response::json(null, 403, 'Only the project owner can edit zones', false);
            }
            $zone = self::resolveZone($access, (string) ($params['zoneId'] ?? ''));

            $body = $GLOBALS['body'] ?? [];
            $data = [];
            if (array_key_exists('title', $body)) {
                $title = trim((string) $body['title']);
                if ($title === '') {
                    Response::json(null, 400, 'Zone title is required', false);
                }
                if (mb_strlen($title) > 100) {
                    Response::json(null, 400, 'Zone title is too long (max 100 characters)', false);
                }
                $data['title'] = $title;
            }
            if (array_key_exists('allowPublicMessage', $body)) {
                $data['allow_public_message'] = $body['allowPublicMessage'] ? 1 : 0;
            }
            if ($data !== []) {
                CircleZoneModel::updateZone((string) $zone['id'], $data);
            }

            $fresh = CircleZoneModel::findById((string) $zone['id']) ?? $zone;
            Response::json(self::presentZone($fresh, true), 200, 'Zone updated successfully', true);
        } catch (Throwable $e) {
            error_log('[CIRCLE ERROR] ' . $e->getMessage());
            Response::json(null, 500, 'Terjadi kesalahan pada server saat mengubah zone', false);
        }
    }

    /**
     * DELETE /api/circles/:circleId/zones/:zoneId
     * Owner menghapus zone beserta semua pesannya. Zone terakhir di
     * circle tidak boleh dihapus (circle harus punya minimal 1 zone).
     *
     * @param  array<string, string> $params
     */
    public static function deleteZone(array $params = []): void
    {
        try {
            $userId = $GLOBALS['user']['id'] ?? null;
            if (!$userId) {
                Response::json(null, 401, 'Unauthorized', false);
            }

            $access = self::resolveAccess((string) ($params['circleId'] ?? ''), (string) $userId);
            if (!$access['isOwner']) {
                Response::json(null, 403, 'Only the project owner can delete zones', false);
            }
            $zone = self::resolveZone($access, (string) ($params['zoneId'] ?? ''));

            if (CircleZoneModel::countByCircleId((string) $access['circle']['id']) <= 1) {
                Response::json(null, 400, 'A circle must keep at least one zone', false);
            }

            $pdo = getDB();
            $pdo->beginTransaction();
            try {
                ZoneMessageModel::deleteByZoneId((string) $zone['id'], $pdo);
                CircleZoneModel::deleteById((string) $zone['id'], $pdo);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }

            Response::json(null, 200, 'Zone deleted successfully', true);
        } catch (Throwable $e) {
            error_log('[CIRCLE ERROR] ' . $e->getMessage());
            Response::json(null, 500, 'Terjadi kesalahan pada server saat menghapus zone', false);
        }
    }

    /**
     * PUT /api/circles/:circleId
     * Owner mengubah circle. Body: { title?, photoUrl? }.
     * photoUrl null mengosongkan foto; string harus URL https.
     * Mengembalikan detail circle terbaru.
     *
     * @param  array<string, string> $params
     */
    public static function updateCircle(array $params = []): void
    {
        try {
            $userId = $GLOBALS['user']['id'] ?? null;
            if (!$userId) {
                Response::json(null, 401, 'Unauthorized', false);
            }

            $access = self::resolveAccess((string) ($params['circleId'] ?? ''), (string) $userId);
            if (!$access['isOwner']) {
                Response::json(null, 403, 'Only the project owner can edit this circle', false);
            }

            $body = $GLOBALS['body'] ?? [];
            $data = [];
            if (array_key_exists('title', $body)) {
                $title = trim((string) $body['title']);
                if ($title === '') {
                    Response::json(null, 400, 'Circle title is required', false);
                }
                if (mb_strlen($title) > 255) {
                    Response::json(null, 400, 'Circle title is too long', false);
                }
                $data['title'] = $title;
            }
            if (array_key_exists('photoUrl', $body)) {
                $photoUrl = $body['photoUrl'];
                if ($photoUrl !== null) {
                    $photoUrl = trim((string) $photoUrl);
                    if ($photoUrl === '' || !str_starts_with($photoUrl, 'https://')) {
                        Response::json(null, 400, 'Invalid photo URL', false);
                    }
                }
                $data['photo_profile_url'] = $photoUrl;
            }
            if ($data !== []) {
                CircleModel::updateById((string) $access['circle']['id'], $data);
            }

            $fresh = CircleModel::findById((string) $access['circle']['id']) ?? $access['circle'];
            Response::json(
                self::detailPayload($fresh, $access['collab'], true),
                200,
                'Circle updated successfully',
                true
            );
        } catch (Throwable $e) {
            error_log('[CIRCLE ERROR] ' . $e->getMessage());
            Response::json(null, 500, 'Terjadi kesalahan pada server saat mengubah circle', false);
        }
    }

    /**
     * DELETE /api/circles/:circleId
     * Owner menghapus circle: semua pesan, zone, lalu circle-nya.
     * Collaboration-nya sendiri tidak ikut terhapus; owner kembali
     * bisa memilih ruang grup lain dari detail collaboration.
     *
     * @param  array<string, string> $params
     */
    public static function deleteCircle(array $params = []): void
    {
        try {
            $userId = $GLOBALS['user']['id'] ?? null;
            if (!$userId) {
                Response::json(null, 401, 'Unauthorized', false);
            }

            $access = self::resolveAccess((string) ($params['circleId'] ?? ''), (string) $userId);
            if (!$access['isOwner']) {
                Response::json(null, 403, 'Only the project owner can delete this circle', false);
            }

            $pdo = getDB();
            $pdo->beginTransaction();
            try {
                ZoneMessageModel::deleteByCircleId((string) $access['circle']['id'], $pdo);
                CircleZoneModel::deleteByCircleId((string) $access['circle']['id'], $pdo);
                CircleModel::deleteById((string) $access['circle']['id'], $pdo);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }

            Response::json(null, 200, 'Circle deleted successfully', true);
        } catch (Throwable $e) {
            error_log('[CIRCLE ERROR] ' . $e->getMessage());
            Response::json(null, 500, 'Terjadi kesalahan pada server saat menghapus circle', false);
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
     * Mengambil satu zone dan memastikan zone itu milik circle dari
     * hasil resolveAccess. Mengirim 404 dan berhenti bila tidak.
     *
     * @param  array{circle: array<string, mixed>, collab: array<string, mixed>, isOwner: bool} $access
     * @return array<string, mixed>
     */
    private static function resolveZone(array $access, string $zoneId): array
    {
        $zone = $zoneId !== '' ? CircleZoneModel::findById($zoneId) : null;
        if ($zone === null || ($zone['circle_id'] ?? null) !== (string) $access['circle']['id']) {
            Response::json(null, 404, 'Zone not found', false);
        }

        return $zone;
    }

    /**
     * Bentuk pesan zone untuk frontend + payload realtime.
     *
     * @param  array<string, mixed> $row Baris zone_messages + username + photo_profile_url
     * @return array<string, mixed>
     */
    private static function presentMessage(array $row): array
    {
        return [
            'id' => $row['id'],
            'zoneId' => $row['zone_id'],
            'authorId' => $row['user_id'],
            'authorName' => $row['username'] ?? '',
            'authorAvatarUrl' => $row['photo_profile_url'] ?? null,
            'content' => $row['message'],
            'createdAt' => $row['timestamp'],
        ];
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
            'allowPublicMessage' => $publicCanPost,
            // Diisi endpoint messaging pada Phase 2 (id pesan terakhir
            // yang dibaca user); Phase 1 belum ada pesan sama sekali.
            'lastRead' => null,
        ];
    }
}
