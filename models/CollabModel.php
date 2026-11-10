<?php

/**
 * models/CollabModel.php
 * ─────────────────────────────────────────────────────────────────────────────
 * COLLABORATION MODEL (Data Access Layer)
 *
 * Bertanggung jawab terhadap operasi database untuk tabel `collaborations`,
 * relasi `collab_media`, `collab_skills`, dan otomatisasi pembuatan `circle`.
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/models/CircleModel.php';
require_once BASE_PATH . '/models/ProfileModel.php';
require_once BASE_PATH . '/models/SkillModel.php';

class CollabModel
{
    /**
     * Membuat kolaborasi baru beserta media, skills, dan auto-create circle
     * dalam satu transaksi database PDO atomik.
     *
     * @param  array{
     *     user_id: string,
     *     title: string,
     *     description: string,
     *     required_member?: int,
     *     status?: string,
     *     media_urls?: array<int, string>,
     *     media_public_ids?: array<int, ?string>,
     *     skills_needed?: array<int, mixed>,
     *     circle_photo_profile_url?: ?string,
     *     id?: string
     * } $data
     * @return array<string, mixed> Data collaboration yang baru dibuat
     * @throws Throwable Jika salah satu operasi database gagal (otomatis rollback)
     */
    public static function create(array $data): array
    {
        $pdo = getDB();

        $id = $data['id'] ?? self::generateUuid();
        $userId = $data['user_id'];
        $title = $data['title'];
        $description = $data['description'];
        $requiredMember = (int) ($data['required_member'] ?? 1);
        $status = $data['status'] ?? 'open';
        $type = in_array($data['type'] ?? '', ['coursework', 'portfolio', 'product'], true)
            ? $data['type']
            : 'coursework';
        $dueDate = $data['due_date'] ?? null;
        if ($dueDate !== null && $dueDate !== '' && strtotime((string) $dueDate) === false) {
            $dueDate = null;
        }
        if ($dueDate === '') {
            $dueDate = null;
        }
        $mediaUrls = $data['media_urls'] ?? [];
        $mediaPublicIds = $data['media_public_ids'] ?? [];
        $skillsNeeded = $data['skills_needed'] ?? [];
        $circlePhoto = $data['circle_photo_profile_url'] ?? null;

        // Mulai transaksi atomik
        $pdo->beginTransaction();

        try {
            // 1. Insert ke tabel collaborations
            $collabStmt = $pdo->prepare(
                "INSERT INTO collaborations (id, user_id, title, description, type, due_date, required_member, status)
                 VALUES (:id, :user_id, :title, :description, :type, :due_date, :required_member, :status)"
            );

            $collabStmt->execute([
                ':id' => $id,
                ':user_id' => $userId,
                ':title' => $title,
                ':description' => $description,
                ':type' => $type,
                ':due_date' => $dueDate,
                ':required_member' => $requiredMember,
                ':status' => $status,
            ]);

            // 2. Insert media ke tabel collab_media jika ada
            if (!empty($mediaUrls)) {
                $mediaStmt = $pdo->prepare(
                    "INSERT INTO collab_media (id, collab_id, media_url, media_public_id)
                     VALUES (:id, :collab_id, :media_url, :media_public_id)"
                );

                foreach ($mediaUrls as $index => $mediaUrl) {
                    $mediaId = self::generateUuid();
                    $publicId = $mediaPublicIds[$index] ?? null;

                    $mediaStmt->execute([
                        ':id' => $mediaId,
                        ':collab_id' => $id,
                        ':media_url' => $mediaUrl,
                        ':media_public_id' => $publicId,
                    ]);
                }
            }

            // 3. Insert skills ke tabel collab_skills jika ada
            if (!empty($skillsNeeded)) {
                $skillStmt = $pdo->prepare(
                    "INSERT INTO collab_skills (id, collab_id, skill_id)
                     VALUES (:id, :collab_id, :skill_id)"
                );

                $normalizedSkillIds = [];
                foreach ($skillsNeeded as $item) {
                    if (is_string($item)) {
                        $sId = trim($item);
                    } elseif (is_array($item)) {
                        $sId = trim((string) ($item['id'] ?? $item['skill_id'] ?? ''));
                    } else {
                        $sId = '';
                    }

                    if ($sId !== '') {
                        $normalizedSkillIds[] = $sId;
                    }
                }

                $uniqueSkillIds = array_values(array_unique($normalizedSkillIds));

                if (!empty($uniqueSkillIds)) {
                    // Validasi foreign key: pastikan seluruh skill ID benar-benar terdaftar di tabel master skills
                    $placeholders = implode(',', array_fill(0, count($uniqueSkillIds), '?'));
                    $checkSkillStmt = $pdo->prepare("SELECT id FROM skills WHERE id IN ($placeholders)");
                    $checkSkillStmt->execute($uniqueSkillIds);
                    $foundSkillIds = $checkSkillStmt->fetchAll(PDO::FETCH_COLUMN);

                    if (count($foundSkillIds) !== count($uniqueSkillIds)) {
                        throw new InvalidArgumentException('Terdapat skill ID yang tidak valid atau tidak ditemukan');
                    }

                    // Validasi kepemilikan: skill system boleh semua user, skill private hanya pemilik
                    $skillValidationError = SkillModel::validateUsageForUser($uniqueSkillIds, $userId);
                    if ($skillValidationError !== null) {
                        throw new InvalidArgumentException($skillValidationError);
                    }

                    foreach ($uniqueSkillIds as $skillId) {
                        $collabSkillId = self::generateUuid();
                        $skillStmt->execute([
                            ':id' => $collabSkillId,
                            ':collab_id' => $id,
                            ':skill_id' => $skillId,
                        ]);
                    }
                }
            }

            // 4. Auto-create Circle yang terhubung dengan collaboration ini
            CircleModel::create([
                'collaboration_id' => $id,
                'title' => $title,
                'photo_profile_url' => $circlePhoto,
            ], $pdo);

            // Commit seluruh transaksi jika semua tahapan berhasil
            $pdo->commit();
        } catch (Throwable $e) {
            // Rollback transaksi jika terjadi kegagalan
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $created = self::findById($id);

        if ($created !== null) {
            return $created;
        }

        return [
            'id' => $id,
            'user_id' => $userId,
            'title' => $title,
            'description' => $description,
            'required_member' => $requiredMember,
            'status' => $status,
            'media_urls' => $mediaUrls,
            'media_public_ids' => $mediaPublicIds,
            'skills_needed' => $skillsNeeded,
        ];
    }

    /**
     * Mencari kolaborasi berdasarkan ID beserta relasi media, skills, dan circle.
     *
     * @param  string $id UUID collaboration
     * @return array<string, mixed>|null Associative array collaboration atau null jika tidak ditemukan
     */
    public static function findById(string $id): ?array
    {
        $pdo = getDB();

        // 1. Ambil data utama collaboration
        $stmt = $pdo->prepare("SELECT * FROM collaborations WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $collab = $stmt->fetch();

        if (!$collab) {
            return null;
        }

        // 2. Ambil media yang berelasi
        $mediaStmt = $pdo->prepare("SELECT media_url, media_public_id FROM collab_media WHERE collab_id = :collab_id ORDER BY created_at ASC");
        $mediaStmt->execute([':collab_id' => $id]);
        $mediaRows = $mediaStmt->fetchAll();

        $mediaUrls = [];
        $mediaPublicIds = [];
        foreach ($mediaRows as $m) {
            $mediaUrls[] = $m['media_url'];
            if ($m['media_public_id'] !== null) {
                $mediaPublicIds[] = $m['media_public_id'];
            }
        }

        // 3. Ambil skills yang berelasi
        $skillsStmt = $pdo->prepare(
            "SELECT s.id, s.skill_name, s.scope 
             FROM collab_skills cs
             JOIN skills s ON cs.skill_id = s.id
             WHERE cs.collab_id = :collab_id
             ORDER BY s.skill_name ASC"
        );
        $skillsStmt->execute([':collab_id' => $id]);
        $skills = $skillsStmt->fetchAll();

        // 4. Ambil circle yang berelasi
        $circle = CircleModel::findByCollaborationId($id, $pdo);

        return [
            'id' => $collab['id'],
            'user_id' => $collab['user_id'],
            'title' => $collab['title'],
            'description' => $collab['description'],
            'required_member' => (int) $collab['required_member'],
            'status' => $collab['status'],
            'media_urls' => $mediaUrls,
            'media_public_ids' => $mediaPublicIds,
            'skills_needed' => $skills,
            'circle' => $circle,
            'created_at' => $collab['created_at'],
            'updated_at' => $collab['updated_at'],
        ];
    }

    /**
     * Mengambil semua data collaboration untuk endpoint GET /api/collab (Showcase Collab)
     * Menggunakan query JOIN dan batch fetching untuk menghindari problem N+1.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getAll(): array
    {
        $pdo = getDB();

        // 1. Ambil data utama collaboration dan owner
        $sql = "
        SELECT
            c.id,
            c.user_id,
            c.title,
            c.description,
            c.type,
            c.due_date,
            c.required_member,
            c.status,
            c.created_at,
            u.username,
            pr.photo_profile_url
        FROM collaborations c
        LEFT JOIN users u
            ON c.user_id = u.id
        LEFT JOIN profiles pr
            ON c.user_id = pr.user_id
        ORDER BY c.created_at DESC
    ";

        $collabs = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        if (empty($collabs)) {
            return [];
        }

        $collabIds = array_column($collabs, 'id');
        $placeholders = implode(',', array_fill(0, count($collabIds), '?'));

        // 2. Ambil skill seluruh collaboration sekaligus
        $skillsStmt = $pdo->prepare("
        SELECT
             cs.collab_id,
            s.id,
            s.skill_name,
            s.scope
        FROM collab_skills cs
        JOIN skills s
            ON cs.skill_id = s.id
        WHERE cs.collab_id IN ($placeholders)
        ORDER BY s.skill_name ASC
    ");

        $skillsStmt->execute($collabIds);
        $skillRows = $skillsStmt->fetchAll(PDO::FETCH_ASSOC);

        $skillsMap = [];

        foreach ($skillRows as $skill) {
            $cid = $skill['collab_id'];

            $skillsMap[$cid][] = [
                'id' => $skill['id'],
                'skill_name' => $skill['skill_name'],
                'scope' => $skill['scope'],
            ];
        }

        // 3. Hitung jumlah anggota resmi seluruh collaboration
        $memberStmt = $pdo->prepare("
        SELECT
            collaboration_id,
            COUNT(*) AS accepted_member_count
        FROM user_collaboration
        WHERE collaboration_id IN ($placeholders)
        GROUP BY collaboration_id
    ");

        $memberStmt->execute($collabIds);
        $memberRows = $memberStmt->fetchAll(PDO::FETCH_ASSOC);

        $memberCountMap = [];

        foreach ($memberRows as $member) {
            $memberCountMap[$member['collaboration_id']] =
                (int) $member['accepted_member_count'];
        }

        // 4. Susun response tanpa field redundan
        $result = [];

        foreach ($collabs as $collab) {
            $cid = $collab['id'];

            $requiredMember = (int) $collab['required_member'];
            $acceptedMemberCount = $memberCountMap[$cid] ?? 0;

            $result[] = [
                'id' => $cid,
                'title' => $collab['title'],
                'description' => $collab['description'] ?? '',
                'type' => $collab['type'] ?? 'coursework',
                'due_date' => $collab['due_date'],
                'status' => $collab['status'],
                'required_member' => $requiredMember,
                'accepted_member_count' => $acceptedMemberCount,
                'available_slots' => max(
                    0,
                    $requiredMember - $acceptedMemberCount
                ),
                'skills_needed' => $skillsMap[$cid] ?? [],
                'owner' => [
                    'id' => $collab['user_id'],
                    'username' => $collab['username'] ?? '',
                    'photo_profile_url' =>
                        $collab['photo_profile_url']
                        ?? ProfileModel::DEFAULT_PHOTO_URL,
                ],
                'created_at' => $collab['created_at'] ?? null,
            ];
        }

        return $result;
    }

    /**
     * Mengambil collaboration yang dimiliki user, yang user ikuti sebagai
     * anggota, atau yang user ajukan (pending) — beserta myStatus untuk
     * board My Collaborations. Powers GET /api/my-collaborations.
     *
     * @param  string $userId UUID user yang sedang login
     * @return array<int, array<string, mixed>>
     */
    public static function getByMemberId(string $userId): array
    {
        $pdo = getDB();

        $sql = "
            SELECT
                c.id,
                c.user_id,
                c.title,
                c.description,
                c.type,
                c.due_date,
                c.required_member,
                c.status,
                c.created_at,
                CASE
                    WHEN uc.status IS NOT NULL THEN uc.status
                    WHEN ca.id IS NOT NULL THEN 'pending'
                    ELSE 'not-started'
                END AS my_status,
                u.username,
                pr.photo_profile_url
            FROM collaborations c
            LEFT JOIN user_collaboration uc
                ON uc.collaboration_id = c.id AND uc.user_id = ?
            LEFT JOIN collaboration_applications ca
                ON ca.collab_id = c.id AND ca.user_id = ? AND ca.status = 'pending'
            LEFT JOIN users u ON c.user_id = u.id
            LEFT JOIN profiles pr ON c.user_id = pr.user_id
            WHERE c.user_id = ? OR uc.user_id = ? OR ca.id IS NOT NULL
            ORDER BY c.created_at DESC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId, $userId, $userId, $userId]);
        $collabs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($collabs)) {
            return [];
        }

        $collabIds = array_column($collabs, 'id');
        $placeholders = implode(',', array_fill(0, count($collabIds), '?'));

        $skillsStmt = $pdo->prepare("
            SELECT
                cs.collab_id,
                s.id,
                s.skill_name,
                s.scope
            FROM collab_skills cs
            JOIN skills s ON cs.skill_id = s.id
            WHERE cs.collab_id IN ($placeholders)
            ORDER BY s.skill_name ASC
        ");
        $skillsStmt->execute($collabIds);
        $skillRows = $skillsStmt->fetchAll(PDO::FETCH_ASSOC);
        $skillsMap = [];
        foreach ($skillRows as $skill) {
            $skillsMap[$skill['collab_id']][] = [
                'id' => $skill['id'],
                'skill_name' => $skill['skill_name'],
                'scope' => $skill['scope'],
            ];
        }

        $memberStmt = $pdo->prepare("
            SELECT collaboration_id, COUNT(*) AS accepted_member_count
            FROM user_collaboration
            WHERE collaboration_id IN ($placeholders)
            GROUP BY collaboration_id
        ");
        $memberStmt->execute($collabIds);
        $memberRows = $memberStmt->fetchAll(PDO::FETCH_ASSOC);
        $memberCountMap = [];
        foreach ($memberRows as $member) {
            $memberCountMap[$member['collaboration_id']] = (int) $member['accepted_member_count'];
        }

        $result = [];
        foreach ($collabs as $collab) {
            $cid = $collab['id'];
            $requiredMember = (int) $collab['required_member'];
            $acceptedMemberCount = $memberCountMap[$cid] ?? 0;
            $result[] = [
                'id' => $cid,
                'title' => $collab['title'],
                'description' => $collab['description'] ?? '',
                'type' => $collab['type'] ?? 'coursework',
                'due_date' => $collab['due_date'],
                'status' => $collab['status'],
                'my_status' => $collab['my_status'],
                'required_member' => $requiredMember,
                'accepted_member_count' => $acceptedMemberCount,
                'available_slots' => max(0, $requiredMember - $acceptedMemberCount),
                'skills_needed' => $skillsMap[$cid] ?? [],
                'owner' => [
                    'id' => $collab['user_id'],
                    'username' => $collab['username'] ?? '',
                    'photo_profile_url' =>
                        $collab['photo_profile_url']
                        ?? ProfileModel::DEFAULT_PHOTO_URL,
                ],
                'created_at' => $collab['created_at'] ?? null,
            ];
        }

        return $result;
    }

    /**
     * Mengambil detail collaboration lengkap berdasarkan ID untuk endpoint GET /api/collab/:id
     *
     * @param  string $id UUID collaboration
     * @return array<string, mixed>|null
     */
    public static function findDetailById(string $id): ?array
    {
        $pdo = getDB();

        // 1. Ambil data utama collaboration dan owner
        $stmt = $pdo->prepare("
        SELECT
            c.id,
            c.user_id,
            c.title,
            c.description,
            c.type,
            c.due_date,
            c.required_member,
            c.status,
            c.created_at,
            c.updated_at,
            u.username,
            pr.photo_profile_url
        FROM collaborations c
        LEFT JOIN users u
            ON c.user_id = u.id
        LEFT JOIN profiles pr
            ON c.user_id = pr.user_id
        WHERE c.id = :id
        LIMIT 1
    ");

        $stmt->execute([':id' => $id]);
        $collab = $stmt->fetch(PDO::FETCH_ASSOC);

        // Collaboration tidak ditemukan
        if (!$collab) {
            return null;
        }

        // 2. Ambil media collaboration
        $mediaStmt = $pdo->prepare("
        SELECT
            media_url,
            media_public_id
        FROM collab_media
        WHERE collab_id = :id
        ORDER BY created_at ASC
    ");

        $mediaStmt->execute([':id' => $id]);
        $mediaRows = $mediaStmt->fetchAll(PDO::FETCH_ASSOC);

        $mediaUrls = [];
        $mediaPublicIds = [];

        foreach ($mediaRows as $media) {
            $mediaUrls[] = $media['media_url'];

            if ($media['media_public_id'] !== null) {
                $mediaPublicIds[] = $media['media_public_id'];
            }
        }

        // 3. Ambil skills yang dibutuhkan
        $skillsStmt = $pdo->prepare("
        SELECT
            s.id,
            s.skill_name,
            s.scope
        FROM collab_skills cs
        JOIN skills s
            ON cs.skill_id = s.id
        WHERE cs.collab_id = :id
        ORDER BY s.skill_name ASC
    ");

        $skillsStmt->execute([':id' => $id]);
        $skills = $skillsStmt->fetchAll(PDO::FETCH_ASSOC);

        // 4. Hitung jumlah anggota resmi
        $memberStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM user_collaboration
        WHERE collaboration_id = :id
    ");

        $memberStmt->execute([':id' => $id]);
        $acceptedMemberCount = (int) $memberStmt->fetchColumn();

        $requiredMember = (int) $collab['required_member'];

        // 5. Ambil circle terkait
        $circle = CircleModel::findByCollaborationId($id, $pdo);

        // 6. Susun payload detail
        return [
            'id' => $collab['id'],
            'title' => $collab['title'],
            'description' => $collab['description'],
            'type' => $collab['type'] ?? 'coursework',
            'due_date' => $collab['due_date'],
            'required_member' => $requiredMember,
            'accepted_member_count' => $acceptedMemberCount,
            'available_slots' => max(
                0,
                $requiredMember - $acceptedMemberCount
            ),
            'status' => $collab['status'],
            'media_urls' => $mediaUrls,
            'media_public_ids' => $mediaPublicIds,
            'skills_needed' => $skills,
            'circle' => $circle,
            'owner' => [
                'id' => $collab['user_id'],
                'username' => $collab['username'] ?? '',
                'photo_profile_url' =>
                    $collab['photo_profile_url']
                    ?? ProfileModel::DEFAULT_PHOTO_URL,
            ],
            'created_at' => $collab['created_at'],
            'updated_at' => $collab['updated_at'],
        ];
    }

    /**
     * Mengambil semua collaboration milik user tertentu untuk endpoint GET /api/profiles/:userId/collab
     *
     * @param  string $userId UUID user pemilik
     * @return array<int, array<string, mixed>>
     */
    public static function getByUserId(string $userId): array
    {
        $pdo = getDB();

        // 1. Ambil data user & profile
        $userStmt = $pdo->prepare("
            SELECT u.id, u.username, pr.photo_profile_url 
            FROM users u
            LEFT JOIN profiles pr ON u.id = pr.user_id
            WHERE u.id = :user_id
            LIMIT 1
        ");
        $userStmt->execute([':user_id' => $userId]);
        $user = $userStmt->fetch();

        if (!$user) {
            return [];
        }

        $photoProfile = $user['photo_profile_url'] ?? ProfileModel::DEFAULT_PHOTO_URL;

        // 2. Ambil semua collaboration milik userId ini
        $collabStmt = $pdo->prepare("
            SELECT 
                c.id,
                c.user_id,
                c.title,
                c.description,
                c.required_member,
                c.status,
                c.created_at,
                c.updated_at
            FROM collaborations c
            WHERE c.user_id = :user_id
            ORDER BY c.created_at DESC
        ");
        $collabStmt->execute([':user_id' => $userId]);
        $collabs = $collabStmt->fetchAll();

        if (empty($collabs)) {
            return [];
        }

        $collabIds = array_column($collabs, 'id');
        $placeholders = implode(',', array_fill(0, count($collabIds), '?'));

        // 3. Batch fetch media
        $mediaStmt = $pdo->prepare("
            SELECT collab_id, media_url, media_public_id 
            FROM collab_media 
            WHERE collab_id IN ($placeholders) 
            ORDER BY created_at ASC
        ");
        $mediaStmt->execute($collabIds);
        $mediaRows = $mediaStmt->fetchAll();

        $mediaMap = [];
        foreach ($mediaRows as $m) {
            $cid = $m['collab_id'];
            if (!isset($mediaMap[$cid])) {
                $mediaMap[$cid] = ['urls' => [], 'public_ids' => []];
            }
            $mediaMap[$cid]['urls'][] = $m['media_url'];
            if ($m['media_public_id'] !== null) {
                $mediaMap[$cid]['public_ids'][] = $m['media_public_id'];
            }
        }

        // 4. Batch fetch skills
        $skillsStmt = $pdo->prepare("
            SELECT cs.collab_id, s.id, s.skill_name, s.scope 
            FROM collab_skills cs
            JOIN skills s ON cs.skill_id = s.id
            WHERE cs.collab_id IN ($placeholders)
            ORDER BY s.skill_name ASC
        ");
        $skillsStmt->execute($collabIds);
        $skillRows = $skillsStmt->fetchAll();

        $skillsMap = [];
        foreach ($skillRows as $s) {
            $cid = $s['collab_id'];
            $skillsMap[$cid][] = [
                'id' => $s['id'],
                '_id' => $s['id'],
                'skill_name' => $s['skill_name'],
                'scope' => $s['scope'],
            ];
        }

        // 5. Batch fetch circle
        $circleStmt = $pdo->prepare("
            SELECT id, collaboration_id, title, photo_profile_url, timestamp 
            FROM circle 
            WHERE collaboration_id IN ($placeholders)
        ");
        $circleStmt->execute($collabIds);
        $circleRows = $circleStmt->fetchAll();

        $circleMap = [];
        foreach ($circleRows as $cir) {
            $circleMap[$cir['collaboration_id']] = [
                'id' => $cir['id'],
                '_id' => $cir['id'],
                'collaboration_id' => $cir['collaboration_id'],
                'title' => $cir['title'],
                'photo_profile_url' => $cir['photo_profile_url'],
                'timestamp' => $cir['timestamp'],
            ];
        }

        $result = [];
        foreach ($collabs as $c) {
            $cid = $c['id'];
            $mediaUrls = $mediaMap[$cid]['urls'] ?? [];
            $mediaPublicIds = $mediaMap[$cid]['public_ids'] ?? [];
            $skills = $skillsMap[$cid] ?? [];
            $circle = $circleMap[$cid] ?? null;

            $result[] = [
                'id' => $c['id'],
                '_id' => $c['id'],
                'userId' => [
                    'id' => $user['id'],
                    '_id' => $user['id'],
                    'username' => $user['username'],
                    'photo_profile_url' => $photoProfile,
                ],
                'user_id' => [
                    'id' => $user['id'],
                    '_id' => $user['id'],
                    'username' => $user['username'],
                    'photo_profile_url' => $photoProfile,
                ],
                'title' => $c['title'],
                'description' => $c['description'],
                'required_member' => (int) $c['required_member'],
                'requiredMember' => (int) $c['required_member'],
                'status' => $c['status'],
                'media_urls' => $mediaUrls,
                'mediaUrls' => $mediaUrls,
                'media_public_ids' => $mediaPublicIds,
                'mediaPublicIds' => $mediaPublicIds,
                'skills_needed' => $skills,
                'skillsNeeded' => $skills,
                'circle' => $circle,
                'created_at' => $c['created_at'],
                'updated_at' => $c['updated_at'],
                'createdAt' => $c['created_at'],
                'updatedAt' => $c['updated_at'],
            ];
        }

        return $result;
    }

    /**
     * Menghapus postingan kolaborasi berdasarkan ID.
     * Menggunakan transaction PDO. Foreign key CASCADE pada InnoDB secara otomatis
     * menghapus relasi pada tabel collab_media, collab_skills, circle, zone,
     * zone_messages, collaboration_applications, dan user_collaboration.
     *
     * @param  string $id UUID collaboration
     * @return bool True jika berhasil dihapus
     * @throws Throwable Jika operasi gagal (otomatis rollback)
     */
    public static function delete(string $id): bool
    {
        $pdo = getDB();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare("DELETE FROM collaborations WHERE id = :id");
            $result = $stmt->execute([':id' => $id]);

            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Alias method deleteById untuk kompatibilitas dan testing.
     *
     * @param  string $id
     * @return bool
     */
    public static function deleteById(string $id): bool
    {
        return self::delete($id);
    }

    /**
     * Helper: Generate UUID v4 standar (RFC 4122).
     *
     * @return string
     */
    public static function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // versi 4
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // variant RFC 4122
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
