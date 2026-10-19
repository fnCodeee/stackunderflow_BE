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

        $id             = $data['id'] ?? self::generateUuid();
        $userId         = $data['user_id'];
        $title          = $data['title'];
        $description    = $data['description'];
        $requiredMember = (int)($data['required_member'] ?? 1);
        $status         = $data['status'] ?? 'open';
        $mediaUrls      = $data['media_urls'] ?? [];
        $mediaPublicIds = $data['media_public_ids'] ?? [];
        $skillsNeeded   = $data['skills_needed'] ?? [];
        $circlePhoto    = $data['circle_photo_profile_url'] ?? null;

        // Mulai transaksi atomik
        $pdo->beginTransaction();

        try {
            // 1. Insert ke tabel collaborations
            $collabStmt = $pdo->prepare(
                "INSERT INTO collaborations (id, user_id, title, description, required_member, status)
                 VALUES (:id, :user_id, :title, :description, :required_member, :status)"
            );

            $collabStmt->execute([
                ':id'              => $id,
                ':user_id'         => $userId,
                ':title'           => $title,
                ':description'     => $description,
                ':required_member' => $requiredMember,
                ':status'          => $status,
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
                        ':id'              => $mediaId,
                        ':collab_id'       => $id,
                        ':media_url'       => $mediaUrl,
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
                        $sId = trim((string)($item['id'] ?? $item['skill_id'] ?? ''));
                    } else {
                        $sId = '';
                    }

                    if ($sId !== '') {
                        $normalizedSkillIds[] = $sId;
                    }
                }

                $uniqueSkillIds = array_values(array_unique($normalizedSkillIds));

                foreach ($uniqueSkillIds as $skillId) {
                    $collabSkillId = self::generateUuid();
                    $skillStmt->execute([
                        ':id'        => $collabSkillId,
                        ':collab_id' => $id,
                        ':skill_id'  => $skillId,
                    ]);
                }
            }

            // 4. Auto-create Circle yang terhubung dengan collaboration ini
            CircleModel::create([
                'collaboration_id'  => $id,
                'title'             => $title,
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
            'id'               => $id,
            'user_id'          => $userId,
            'title'            => $title,
            'description'      => $description,
            'required_member'  => $requiredMember,
            'status'           => $status,
            'media_urls'       => $mediaUrls,
            'media_public_ids' => $mediaPublicIds,
            'skills_needed'    => $skillsNeeded,
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
            'id'               => $collab['id'],
            'user_id'          => $collab['user_id'],
            'title'            => $collab['title'],
            'description'      => $collab['description'],
            'required_member'  => (int)$collab['required_member'],
            'status'           => $collab['status'],
            'media_urls'       => $mediaUrls,
            'media_public_ids' => $mediaPublicIds,
            'skills_needed'    => $skills,
            'circle'           => $circle,
            'created_at'       => $collab['created_at'],
            'updated_at'       => $collab['updated_at'],
        ];
    }

    /**
     * Menghapus collaboration berdasarkan ID (berguna untuk testing/cleanup).
     * Foreign key CASCADE akan menghapus media, skills, dan circle terkait secara otomatis.
     *
     * @param  string $id
     * @return bool
     */
    public static function deleteById(string $id): bool
    {
        $pdo = getDB();
        $stmt = $pdo->prepare("DELETE FROM collaborations WHERE id = :id");
        return $stmt->execute([':id' => $id]);
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
