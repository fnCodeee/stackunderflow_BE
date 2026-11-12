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

            // 5. Owner adalah anggota pertama (accepted): collab baru
            //    mulai dengan accepted_member_count = 1.
            self::insertMember($pdo, $id, $userId);

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
            'type' => $type,
            'due_date' => $dueDate,
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
        $stmt = $pdo->prepare("SELECT * FROM collaborations WHERE id = :id AND deleted_at IS NULL LIMIT 1");
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

        // 5. Hitung jumlah anggota resmi (owner sudah terhitung sejak create)
        $acceptedMemberCount = self::countAcceptedMembers($id, $pdo);
        $requiredMember = (int) $collab['required_member'];

        return [
            'id' => $collab['id'],
            'user_id' => $collab['user_id'],
            'title' => $collab['title'],
            'description' => $collab['description'],
            'required_member' => (int) $collab['required_member'],
            'accepted_member_count' => $acceptedMemberCount,
            'available_slots' => max(0, $requiredMember - $acceptedMemberCount),
            'status' => $collab['status'],
            'type' => $collab['type'] ?? 'coursework',
            'due_date' => $collab['due_date'],
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
        WHERE c.deleted_at IS NULL
        ORDER BY c.created_at DESC
    ";

        $collabs = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        return self::hydrateList($collabs);
    }

    /**
     * Melengkapi baris collaboration mentah (hasil SELECT utama + owner)
     * dengan skills, jumlah anggota, dan shape response list.
     * Dipakai bersama oleh getAll() dan getPage() supaya keduanya
     * mengembalikan item yang identik.
     *
     * @param  array<int, array<string, mixed>> $collabs Baris mentah
     * @return array<int, array<string, mixed>>
     */
    private static function hydrateList(array $collabs): array
    {
        if (empty($collabs)) {
            return [];
        }

        $pdo = getDB();
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
     * Satu halaman list collaboration untuk GET /api/collab (audit P2-12).
     * Mendukung pencarian (LIKE case-insensitive di title/description —
     * collation MySQL default sudah case-insensitive) dan sort:
     * newest = created_at DESC (default), oldest = created_at ASC,
     * due = due_date ASC dengan null terakhir, tiebreak created_at DESC.
     *
     * Page/limit diasumsikan sudah divalidasi (clamp) oleh controller.
     *
     * @return array{data: array<int, array<string, mixed>>, pagination: array{page: int, limit: int, total: int, total_pages: int}}
     */
    public static function getPage(string $q, string $sort, int $page, int $limit): array
    {
        $pdo = getDB();

        $where = 'WHERE c.deleted_at IS NULL';
        $params = [];

        $q = trim($q);
        if ($q !== '') {
            // Escape karakter wildcard LIKE supaya query user diperlakukan harfiah.
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);
            $where .= ' AND (c.title LIKE ? OR c.description LIKE ?)';
            $like = '%' . $escaped . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $orderBy = match ($sort) {
            'oldest' => 'ORDER BY c.created_at ASC',
            'due' => 'ORDER BY c.due_date IS NULL ASC, c.due_date ASC, c.created_at DESC',
            default => 'ORDER BY c.created_at DESC',
        };

        // Total baris (untuk pagination meta) — WHERE sama, tanpa LIMIT.
        $countStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM collaborations c
            $where
        ");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
        $totalPages = $total === 0 ? 0 : (int) ceil($total / $limit);

        $offset = ($page - 1) * $limit;
        $listStmt = $pdo->prepare("
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
            $where
            $orderBy
            LIMIT ? OFFSET ?
        ");
        // LIMIT/OFFSET ikut di-execute sebagai int (pola yang sama dengan
        // NotificationModel::getForUser) — native prepares PDO mengikatnya
        // sebagai integer.
        $listStmt->execute(array_merge($params, [$limit, $offset]));
        $collabs = $listStmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'data' => self::hydrateList($collabs),
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'total_pages' => $totalPages,
            ],
        ];
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
            WHERE c.deleted_at IS NULL AND (c.user_id = ? OR uc.user_id = ? OR ca.id IS NOT NULL)
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

        // Daftar anggota diterima per collab (untuk Team di halaman
        // detail) — satu query untuk semua collab, dikelompokkan per
        // collaboration_id, urut waktu gabung.
        $memberListStmt = $pdo->prepare("
            SELECT uc.collaboration_id, u.id, u.username, pr.photo_profile_url
            FROM user_collaboration uc
            JOIN users u ON u.id = uc.user_id
            LEFT JOIN profiles pr ON pr.user_id = u.id
            WHERE uc.collaboration_id IN ($placeholders)
            ORDER BY uc.created_at ASC
        ");
        $memberListStmt->execute($collabIds);
        $membersMap = [];
        foreach ($memberListStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $membersMap[$row['collaboration_id']][] = [
                'id' => $row['id'],
                'username' => $row['username'] ?? '',
                'photo_profile_url' => $row['photo_profile_url'] ?? ProfileModel::DEFAULT_PHOTO_URL,
            ];
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
                'members' => $membersMap[$cid] ?? [],
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
        WHERE c.id = :id AND c.deleted_at IS NULL
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
            'members' => self::acceptedMembers($id, $pdo),
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
            WHERE c.user_id = :user_id AND c.deleted_at IS NULL
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
     * Soft delete postingan kolaborasi berdasarkan ID: set deleted_at = NOW().
     * Row tetap ada
     * di database (bisa di-restore / diaudit); relasi (collab_media,
     * collab_skills, circle, zone, applications, user_collaboration)
     * TIDAK dihapus.
     *
     * @param  string $id UUID collaboration
     * @return bool True jika berhasil dihapus
     */
    /**
     * Status aplikasi di DB vs API.
     *
     * Kolom `collaboration_applications.status` adalah ENUM
     * ('pending','accepted','rejected') - tidak ada nilai 'declined'.
     * API mengekspos penolakan sebagai 'declined', jadi mapping ini
     * dipakai di semua payload aplikasi yang keluar.
     *
     * @param  string $dbStatus Nilai status apa adanya dari database
     * @return string Status yang diekspos ke API
     */
    public static function mapApplicationStatus(string $dbStatus): string
    {
        return $dbStatus === 'rejected' ? 'declined' : $dbStatus;
    }

    /**
     * Jumlah anggota diterima (accepted) sebuah kolaborasi.
     *
     * Satu row di `user_collaboration` = satu anggota diterima; pelamar
     * pending hanya ada di `collaboration_applications` dan tidak pernah
     * masuk tabel ini, jadi COUNT(*) di sini sudah menghitung accepted
     * saja. Kolom `status` di user_collaboration adalah progres tugas
     * member (not-started/pending/complete/revise), BUKAN flag
     * penerimaan - tidak ada filter status yang benar untuk dipakai.
     *
     * @param  string   $collabId UUID collaboration
     * @param  PDO|null $pdo      Koneksi (dipakai saat di dalam transaksi)
     * @return int Jumlah anggota diterima
     */
    /**
     * Daftar anggota diterima (baris user_collaboration) beserta
     * profil singkatnya, urut waktu gabung. Dipakai payload detail
     * supaya Team menampilkan orang beneran, bukan cuma hitungan.
     *
     * @param  string   $collabId UUID collaboration
     * @param  PDO|null $pdo      Koneksi (dipakai saat di dalam transaksi)
     * @return array<int, array{id: string, username: string, photo_profile_url: string}>
     */
    public static function acceptedMembers(string $collabId, ?PDO $pdo = null): array
    {
        $pdo ??= getDB();
        $stmt = $pdo->prepare("
            SELECT u.id, u.username, pr.photo_profile_url
            FROM user_collaboration uc
            JOIN users u ON u.id = uc.user_id
            LEFT JOIN profiles pr ON pr.user_id = u.id
            WHERE uc.collaboration_id = :id
            ORDER BY uc.created_at ASC
        ");
        $stmt->execute([':id' => $collabId]);
        $members = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $members[] = [
                'id' => $row['id'],
                'username' => $row['username'] ?? '',
                'photo_profile_url' => $row['photo_profile_url'] ?? ProfileModel::DEFAULT_PHOTO_URL,
            ];
        }
        return $members;
    }

    public static function countAcceptedMembers(string $collabId, ?PDO $pdo = null): int
    {
        $pdo ??= getDB();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM user_collaboration WHERE collaboration_id = :id");
        $stmt->execute([':id' => $collabId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Apakah user sudah menjadi anggota diterima di collab ini.
     *
     * @param  string $collabId UUID collaboration
     * @param  string $userId   UUID user
     * @return bool True jika ada row di user_collaboration
     */
    public static function isMember(string $collabId, string $userId): bool
    {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT 1 FROM user_collaboration WHERE collaboration_id = :collab_id AND user_id = :user_id LIMIT 1");
        $stmt->execute([':collab_id' => $collabId, ':user_id' => $userId]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Kunci row collab untuk penulisan yang mengubah kursi (apply/accept).
     * Wajib dipanggil di dalam transaksi; row lock ini membuat
     * pengecekan "penuh" di semua request untuk collab yang sama
     * berjalan berurutan sehingga kursi tidak bisa overbooked.
     *
     * @param  PDO    $pdo Koneksi transaksi
     * @param  string $id  UUID collaboration
     * @return array<string, mixed>|null Row collab atau null jika tidak ada / soft-deleted
     */
    public static function lockCollabForMembership(PDO $pdo, string $id): ?array
    {
        $stmt = $pdo->prepare("SELECT id, user_id, required_member FROM collaborations WHERE id = :id AND deleted_at IS NULL LIMIT 1 FOR UPDATE");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Masukkan anggota diterima. Dipakai saat create (owner) dan accept.
     * Status member default 'not-started' (progres tugas, bukan penerimaan).
     *
     * @param  PDO    $pdo      Koneksi transaksi
     * @param  string $collabId UUID collaboration
     * @param  string $userId   UUID user
     * @return void
     */
    public static function insertMember(PDO $pdo, string $collabId, string $userId): void
    {
        $stmt = $pdo->prepare(
            "INSERT INTO user_collaboration (id, user_id, collaboration_id)
             VALUES (:id, :user_id, :collaboration_id)"
        );
        $stmt->execute([
            ':id' => self::generateUuid(),
            ':user_id' => $userId,
            ':collaboration_id' => $collabId,
        ]);
    }

    /**
     * Bentuk payload API untuk satu row collaboration_applications.
     *
     * @param  array<string, mixed> $row Row apa adanya dari database
     * @return array<string, mixed> Row dengan status versi API
     */
    private static function presentApplication(array $row): array
    {
        return [
            'id' => $row['id'],
            'collaboration_id' => $row['collab_id'],
            'user_id' => $row['user_id'],
            'status' => self::mapApplicationStatus((string) $row['status']),
            'message' => $row['message'],
            'created_at' => $row['created_at'],
        ];
    }

    /**
     * Satu aplikasi user di satu collab (UNIQUE user_id+collab_id,
     * jadi maksimal satu row per pasangan).
     *
     * @param  string $collabId UUID collaboration
     * @param  string $userId   UUID user
     * @return array<string, mixed>|null Aplikasi atau null jika belum pernah apply
     */
    public static function findApplication(string $collabId, string $userId): ?array
    {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT id, collab_id, user_id, message, status, created_at FROM collaboration_applications WHERE collab_id = :collab_id AND user_id = :user_id LIMIT 1");
        $stmt->execute([':collab_id' => $collabId, ':user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        return self::presentApplication($row);
    }

    /**
     * Satu aplikasi berdasarkan id-nya di dalam satu collab.
     *
     * @param  string $collabId      UUID collaboration
     * @param  string $applicationId UUID application
     * @return array<string, mixed>|null Aplikasi atau null jika tidak ada
     */
    public static function findApplicationById(string $collabId, string $applicationId): ?array
    {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT id, collab_id, user_id, message, status, created_at FROM collaboration_applications WHERE id = :id AND collab_id = :collab_id LIMIT 1");
        $stmt->execute([':id' => $applicationId, ':collab_id' => $collabId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        return self::presentApplication($row);
    }

    /**
     * Buat aplikasi baru berstatus pending.
     *
     * @param  string      $collabId UUID collaboration
     * @param  string      $userId   UUID pelamar
     * @param  string|null $message  Pesan lamaran (opsional)
     * @return array<string, mixed> Aplikasi yang baru dibuat
     */
    public static function createApplication(string $collabId, string $userId, ?string $message): array
    {
        $pdo = getDB();
        $id = self::generateUuid();
        $stmt = $pdo->prepare("INSERT INTO collaboration_applications (id, user_id, collab_id, message, status) VALUES (:id, :user_id, :collab_id, :message, 'pending')");
        $stmt->execute([':id' => $id, ':user_id' => $userId, ':collab_id' => $collabId, ':message' => $message]);
        $created = self::findApplicationById($collabId, $id);
        if ($created !== null) {
            return $created;
        }
        return [
            'id' => $id,
            'collaboration_id' => $collabId,
            'user_id' => $userId,
            'status' => 'pending',
            'message' => $message,
            'created_at' => null,
        ];
    }

    /**
     * Apply ulang setelah ditolak: baris lama (UNIQUE user_id+collab_id)
     * tidak bisa diduplikasi, jadi statusnya dikembalikan ke pending.
     *
     * @param  string      $collabId UUID collaboration
     * @param  string      $userId   UUID pelamar
     * @param  string|null $message  Pesan lamaran baru (opsional, null = pertahankan yang lama)
     * @return array<string, mixed> Aplikasi setelah dibuka ulang
     */
    public static function reopenApplication(string $collabId, string $userId, ?string $message): array
    {
        $pdo = getDB();
        $stmt = $pdo->prepare("UPDATE collaboration_applications SET status = 'pending', message = COALESCE(:message, message) WHERE collab_id = :collab_id AND user_id = :user_id");
        $stmt->execute([':message' => $message, ':collab_id' => $collabId, ':user_id' => $userId]);
        $reopened = self::findApplication($collabId, $userId);
        if ($reopened !== null) {
            return $reopened;
        }
        return [
            'id' => '',
            'collaboration_id' => $collabId,
            'user_id' => $userId,
            'status' => 'pending',
            'message' => $message,
            'created_at' => null,
        ];
    }

    /**
     * Daftar aplikasi satu collab untuk owner. Urutan: pending dulu
     * (paling lama menunggu paling atas), lalu yang sudah diputuskan.
     *
     * @param  string $collabId UUID collaboration
     * @return array<int, array<string, mixed>>
     */
    public static function listApplications(string $collabId): array
    {
        $pdo = getDB();
        $stmt = $pdo->prepare(
            "SELECT ca.id, ca.user_id, u.username, pr.photo_profile_url, ca.message, ca.status, ca.created_at
             FROM collaboration_applications ca
             JOIN users u ON u.id = ca.user_id
             LEFT JOIN profiles pr ON pr.user_id = ca.user_id
             WHERE ca.collab_id = :collab_id
             ORDER BY (ca.status = 'pending') DESC, ca.created_at ASC"
        );
        $stmt->execute([':collab_id' => $collabId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'id' => $row['id'],
                'user_id' => $row['user_id'],
                'username' => $row['username'] ?? '',
                'photo_profile_url' => $row['photo_profile_url'] ?? ProfileModel::DEFAULT_PHOTO_URL,
                'message' => $row['message'],
                'status' => self::mapApplicationStatus((string) $row['status']),
                'created_at' => $row['created_at'],
            ];
        }
        return $result;
    }

    /**
     * Ubah aplikasi pending menjadi accepted. Kondisi status='pending'
     * di WHERE membuat flip ini atomik: kalau row sudah diputuskan
     * duluan oleh request lain, tidak ada baris yang berubah.
     *
     * @param  PDO    $pdo           Koneksi transaksi
     * @param  string $collabId      UUID collaboration
     * @param  string $applicationId UUID application
     * @return bool True jika satu baris berhasil diubah
     */
    public static function acceptApplicationRow(PDO $pdo, string $collabId, string $applicationId): bool
    {
        $stmt = $pdo->prepare("UPDATE collaboration_applications SET status = 'accepted' WHERE id = :id AND collab_id = :collab_id AND status = 'pending'");
        $stmt->execute([':id' => $applicationId, ':collab_id' => $collabId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Ubah aplikasi pending menjadi rejected (diekspos sebagai 'declined').
     * Sama atomiknya dengan acceptApplicationRow.
     *
     * @param  string $collabId      UUID collaboration
     * @param  string $applicationId UUID application
     * @return bool True jika satu baris berhasil diubah
     */
    public static function declineApplicationRow(string $collabId, string $applicationId): bool
    {
        $pdo = getDB();
        $stmt = $pdo->prepare("UPDATE collaboration_applications SET status = 'rejected' WHERE id = :id AND collab_id = :collab_id AND status = 'pending'");
        $stmt->execute([':id' => $applicationId, ':collab_id' => $collabId]);
        return $stmt->rowCount() > 0;
    }


    public static function delete(string $id): bool
    {
        $pdo = getDB();
        $stmt = $pdo->prepare("UPDATE collaborations SET deleted_at = NOW() WHERE id = :id AND deleted_at IS NULL");
        return $stmt->execute([':id' => $id]);
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
