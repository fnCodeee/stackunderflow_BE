<?php

/**
 * models/CircleModel.php
 * ─────────────────────────────────────────────────────────────────────────────
 * CIRCLE MODEL (Data Access Layer)
 *
 * Bertanggung jawab terhadap operasi database untuk tabel `circle`.
 * Mereferensikan collaboration_id dengan relasi 1-to-1 (unique constraint).
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/models/CircleZoneModel.php';

class CircleModel
{
    /**
     * Membuat record circle baru.
     *
     * @param  array{
     *     collaboration_id: string,
     *     title: string,
     *     photo_profile_url?: ?string,
     *     id?: string
     * } $data
     * @param  PDO|null $pdo Instance PDO yang sedang bertransaksi (opsional)
     * @return array<string, mixed> Data circle yang baru dibuat
     */
    public static function create(array $data, ?PDO $pdo = null): array
    {
        $db = $pdo ?? getDB();

        $id = $data['id'] ?? self::generateUuid();
        $collabId = $data['collaboration_id'];
        $title = $data['title'];
        $photoProfileUrl = $data['photo_profile_url'] ?? null;

        $stmt = $db->prepare(
            "INSERT INTO circle (id, collaboration_id, title, photo_profile_url, timestamp)
             VALUES (:id, :collaboration_id, :title, :photo_profile_url, NOW())"
        );

        $stmt->execute([
            ':id'                => $id,
            ':collaboration_id'  => $collabId,
            ':title'             => $title,
            ':photo_profile_url' => $photoProfileUrl,
        ]);

        // Setiap circle lahir dengan dua zone bawaan (announcements
        // dan general), di transaksi yang sama dengan circle-nya.
        CircleZoneModel::createDefaults($db, $id);

        $created = self::findByCollaborationId($collabId, $db);
        if ($created !== null) {
            return $created;
        }

        return [
            'id'                => $id,
            'collaboration_id'  => $collabId,
            'title'             => $title,
            'photo_profile_url' => $photoProfileUrl,
            'timestamp'         => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Mencari circle berdasarkan collaboration_id.
     *
     * @param  string $collabId
     * @param  PDO|null $pdo
     * @return array<string, mixed>|null
     */
    public static function findByCollaborationId(string $collabId, ?PDO $pdo = null): ?array
    {
        $db = $pdo ?? getDB();
        $stmt = $db->prepare("SELECT * FROM circle WHERE collaboration_id = :collaboration_id LIMIT 1");
        $stmt->execute([':collaboration_id' => $collabId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Mencari circle berdasarkan ID.
     *
     * @param  string $id
     * @param  PDO|null $pdo
     * @return array<string, mixed>|null
     */
    public static function findById(string $id, ?PDO $pdo = null): ?array
    {
        $db = $pdo ?? getDB();
        $stmt = $db->prepare("SELECT * FROM circle WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Memperbarui title circle berdasarkan collaboration_id.
     * Digunakan untuk sinkronisasi judul circle saat judul collaboration di-update.
     *
     * @param  string $collabId
     * @param  string $title
     * @param  PDO|null $pdo
     * @return bool
     */
    public static function updateTitleByCollaborationId(string $collabId, string $title, ?PDO $pdo = null): bool
    {
        $db = $pdo ?? getDB();
        $stmt = $db->prepare("UPDATE circle SET title = :title WHERE collaboration_id = :collaboration_id");
        return $stmt->execute([
            ':title'            => $title,
            ':collaboration_id' => $collabId,
        ]);
    }

    /**
     * Memperbarui data circle secara fleksibel berdasarkan collaboration_id.
     *
     * @param  string $collabId
     * @param  array<string, mixed> $data
     * @param  PDO|null $pdo
     * @return bool
     */
    public static function updateByCollaborationId(string $collabId, array $data, ?PDO $pdo = null): bool
    {
        $db = $pdo ?? getDB();
        $fields = [];
        $params = [':collaboration_id' => $collabId];

        if (array_key_exists('title', $data)) {
            $fields[] = "title = :title";
            $params[':title'] = $data['title'];
        }
        if (array_key_exists('photo_profile_url', $data)) {
            $fields[] = "photo_profile_url = :photo_profile_url";
            $params[':photo_profile_url'] = $data['photo_profile_url'];
        }

        if (empty($fields)) {
            return true;
        }

        $sql = "UPDATE circle SET " . implode(', ', $fields) . " WHERE collaboration_id = :collaboration_id";
        $stmt = $db->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * Menghapus circle berdasarkan collaboration_id.
     *
     * @param  string $collabId
     * @param  PDO|null $pdo
     * @return bool
     */
    public static function deleteByCollaborationId(string $collabId, ?PDO $pdo = null): bool
    {
        $db = $pdo ?? getDB();
        $stmt = $db->prepare("DELETE FROM circle WHERE collaboration_id = :collaboration_id");
        return $stmt->execute([':collaboration_id' => $collabId]);
    }

    /**
     * Memperbarui data circle secara fleksibel berdasarkan id circle
     * (title / photo_profile_url). Dipakai endpoint settings circle.
     *
     * @param  array<string, mixed> $data
     */
    public static function updateById(string $id, array $data, ?PDO $pdo = null): bool
    {
        $db = $pdo ?? getDB();
        $fields = [];
        $params = [':id' => $id];

        if (array_key_exists('title', $data)) {
            $fields[] = "title = :title";
            $params[':title'] = $data['title'];
        }
        if (array_key_exists('photo_profile_url', $data)) {
            $fields[] = "photo_profile_url = :photo_profile_url";
            $params[':photo_profile_url'] = $data['photo_profile_url'];
        }
        if (empty($fields)) {
            return true;
        }

        $stmt = $db->prepare("UPDATE circle SET " . implode(', ', $fields) . " WHERE id = :id");
        return $stmt->execute($params);
    }

    /** Menghapus circle berdasarkan id circle. */
    public static function deleteById(string $id, ?PDO $pdo = null): bool
    {
        $db = $pdo ?? getDB();
        $stmt = $db->prepare("DELETE FROM circle WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    /**
     * Daftar circle yang bisa diakses user: circle dari collaboration
     * yang ia miliki atau yang ia ikuti sebagai accepted member
     * (baris user_collaboration). Dipakai GET /api/circles.
     *
     * @param  string $userId
     * @return array<int, array<string, mixed>>
     */
    public static function listForUser(string $userId): array
    {
        $db = getDB();
        $stmt = $db->prepare(
            "SELECT ci.id, ci.collaboration_id, ci.title, ci.photo_profile_url, ci.timestamp,
                    c.status, c.user_id
             FROM circle ci
             JOIN collaborations c ON c.id = ci.collaboration_id
             WHERE c.deleted_at IS NULL
               AND (c.user_id = :owner_id
                    OR EXISTS (SELECT 1 FROM user_collaboration uc
                               WHERE uc.collaboration_id = c.id AND uc.user_id = :member_id))
             ORDER BY ci.timestamp DESC"
        );
        $stmt->execute([':owner_id' => $userId, ':member_id' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
