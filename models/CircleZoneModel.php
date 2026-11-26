<?php

/**
 * models/CircleZoneModel.php
 * =============================================================================
 * CIRCLE ZONE MODEL (Data Access Layer)
 *
 * Bertanggung jawab terhadap operasi database untuk tabel `zone`
 * (satu Circle memiliki banyak Zone; struktur Collaboration ->
 * Circle -> Zone -> Zone Messages).
 *
 * Setiap Circle memiliki tepat dua zone bawaan yang dibuat bersamaan
 * dengan Circle-nya (lihat CircleModel::create):
 *  - announcements: allow_public_message = 0 (semua anggota membaca,
 *    hanya owner yang bisa mengirim).
 *  - general: allow_public_message = 1 (semua anggota membaca dan
 *    mengirim).
 * Permission efektif per user dihitung di CircleController sebagai
 * `memberCanPost` (owner selalu boleh; member mengikuti
 * allow_public_message).
 * =============================================================================
 */

declare(strict_types=1);

require_once BASE_PATH . '/config/database.php';

class CircleZoneModel
{
    /**
     * Membuat dua zone bawaan untuk circle yang baru dibuat.
     * Dipanggil di dalam transaksi pembuatan circle.
     */
    public static function createDefaults(PDO $pdo, string $circleId): void
    {
        $stmt = $pdo->prepare(
            "INSERT INTO zone (id, circle_id, title, allow_public_message)
             VALUES (:id, :circle_id, :title, :allow_public_message)"
        );

        $defaults = [
            ['title' => 'announcements', 'allow_public_message' => 0],
            ['title' => 'general', 'allow_public_message' => 1],
        ];

        foreach ($defaults as $zone) {
            $stmt->execute([
                ':id' => self::generateUuid(),
                ':circle_id' => $circleId,
                ':title' => $zone['title'],
                ':allow_public_message' => $zone['allow_public_message'],
            ]);
        }
    }

    /**
     * Daftar zone dalam satu circle. announcements selalu pertama
     * (zone bawaan hanya dua; urutan sisanya mengikuti waktu buat).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listByCircleId(string $circleId, ?PDO $pdo = null): array
    {
        $db = $pdo ?? getDB();
        $stmt = $db->prepare(
            "SELECT * FROM zone
             WHERE circle_id = :circle_id
             ORDER BY (title = 'announcements') DESC, timestamp ASC"
        );
        $stmt->execute([':circle_id' => $circleId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Satu zone berdasarkan ID.
     *
     * @return array<string, mixed>|null
     */
    public static function findById(string $id, ?PDO $pdo = null): ?array
    {
        $db = $pdo ?? getDB();
        $stmt = $db->prepare("SELECT * FROM zone WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Membuat satu zone baru di circle (di luar dua zone bawaan).
     * Mengembalikan baris zone yang baru dibuat.
     *
     * @return array<string, mixed>
     */
    public static function createZone(string $circleId, string $title, bool $allowPublicMessage, ?PDO $pdo = null): array
    {
        $db = $pdo ?? getDB();
        $id = self::generateUuid();
        $stmt = $db->prepare(
            "INSERT INTO zone (id, circle_id, title, allow_public_message)
             VALUES (:id, :circle_id, :title, :allow_public_message)"
        );
        $stmt->execute([
            ':id' => $id,
            ':circle_id' => $circleId,
            ':title' => $title,
            ':allow_public_message' => $allowPublicMessage ? 1 : 0,
        ]);
        $created = self::findById($id, $db);
        return $created ?? [
            'id' => $id,
            'circle_id' => $circleId,
            'title' => $title,
            'allow_public_message' => $allowPublicMessage ? 1 : 0,
        ];
    }

    /**
     * Memperbarui zone secara fleksibel (title / allow_public_message).
     *
     * @param  array<string, mixed> $data
     */
    public static function updateZone(string $id, array $data, ?PDO $pdo = null): bool
    {
        $db = $pdo ?? getDB();
        $fields = [];
        $params = [':id' => $id];

        if (array_key_exists('title', $data)) {
            $fields[] = "title = :title";
            $params[':title'] = $data['title'];
        }
        if (array_key_exists('allow_public_message', $data)) {
            $fields[] = "allow_public_message = :allow_public_message";
            $params[':allow_public_message'] = $data['allow_public_message'];
        }
        if (empty($fields)) {
            return true;
        }

        $stmt = $db->prepare("UPDATE zone SET " . implode(', ', $fields) . " WHERE id = :id");
        return $stmt->execute($params);
    }

    /** Jumlah zone di satu circle (penjaga zone terakhir). */
    public static function countByCircleId(string $circleId, ?PDO $pdo = null): int
    {
        $db = $pdo ?? getDB();
        $stmt = $db->prepare("SELECT COUNT(*) FROM zone WHERE circle_id = :circle_id");
        $stmt->execute([':circle_id' => $circleId]);
        return (int) $stmt->fetchColumn();
    }

    /** Menghapus satu zone berdasarkan ID. */
    public static function deleteById(string $id, ?PDO $pdo = null): bool
    {
        $db = $pdo ?? getDB();
        $stmt = $db->prepare("DELETE FROM zone WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    /** Menghapus semua zone di satu circle. */
    public static function deleteByCircleId(string $circleId, ?PDO $pdo = null): bool
    {
        $db = $pdo ?? getDB();
        $stmt = $db->prepare("DELETE FROM zone WHERE circle_id = :circle_id");
        return $stmt->execute([':circle_id' => $circleId]);
    }

    /**
     * Helper: Generate UUID v4 standar (RFC 4122).
     */
    public static function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // versi 4
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // variant RFC 4122
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
