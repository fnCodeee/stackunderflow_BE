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
