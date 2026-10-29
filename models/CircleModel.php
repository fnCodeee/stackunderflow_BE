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
