<?php

/**
 * models/ZoneMessageModel.php
 * =============================================================================
 * ZONE MESSAGE MODEL (Data Access Layer) — Circle Phase 2 (messaging).
 *
 * Bertanggung jawab terhadap operasi database untuk tabel
 * `zone_messages` (struktur Collaboration -> Circle -> Zone ->
 * Zone Messages). Tabelnya sudah ada sejak skema awal:
 *  - id        varchar(36) uuid
 *  - zone_id   FK -> zone.id (cascade)
 *  - user_id   FK -> users.id (cascade)
 *  - message   TEXT
 *  - timestamp DATETIME default CURRENT_TIMESTAMP
 * Tidak ada kolom reply / lampiran / soft delete — itu Phase 3.
 *
 * Paginasi riwayat memakai kursor (timestamp, id): pesan diambil
 * menurun (terbaru dulu) mulai dari kursor, lalu controller
 * membaliknya menjadi urutan kronologis untuk frontend.
 * =============================================================================
 */

declare(strict_types=1);

require_once BASE_PATH . '/config/database.php';

class ZoneMessageModel
{
    /**
     * Simpan satu pesan, lalu kembalikan barisnya lengkap dengan
     * nama + foto penulis (bentuk siap presentasi).
     *
     * @return array<string, mixed>
     */
    public static function create(string $zoneId, string $userId, string $content, ?string $replyToId = null, ?PDO $pdo = null): array
    {
        $db = $pdo ?? getDB();
        $id = self::generateUuid();

        $stmt = $db->prepare(
            "INSERT INTO zone_messages (id, zone_id, user_id, message, reply_to_id)
             VALUES (:id, :zone_id, :user_id, :message, :reply_to_id)"
        );
        $stmt->execute([
            ':id' => $id,
            ':zone_id' => $zoneId,
            ':user_id' => $userId,
            ':message' => $content,
            ':reply_to_id' => $replyToId,
        ]);

        $row = self::findById($id, $db);
        if ($row === null) {
            throw new RuntimeException('Zone message vanished right after insert');
        }

        return $row;
    }

    /**
     * Satu pesan berdasarkan ID, dengan nama + foto penulis.
     *
     * @return array<string, mixed>|null
     */
    public static function findById(string $id, ?PDO $pdo = null): ?array
    {
        $db = $pdo ?? getDB();
        $stmt = $db->prepare(
            "SELECT zm.*, u.username, pr.photo_profile_url
             FROM zone_messages zm
             JOIN users u ON u.id = zm.user_id
             LEFT JOIN profiles pr ON pr.user_id = u.id
             WHERE zm.id = :id
             LIMIT 1"
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Beberapa pesan sekaligus berdasarkan ID (untuk melampirkan
     * kutipan reply ke daftar pesan), terindeks oleh ID pesan.
     *
     * @param  array<int, string> $ids
     * @return array<string, array<string, mixed>>
     */
    public static function findManyByIds(array $ids, ?PDO $pdo = null): array
    {
        if ($ids === []) {
            return [];
        }
        $db = $pdo ?? getDB();
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare(
            "SELECT zm.*, u.username, pr.photo_profile_url
             FROM zone_messages zm
             JOIN users u ON u.id = zm.user_id
             LEFT JOIN profiles pr ON pr.user_id = u.id
             WHERE zm.id IN ($placeholders)"
        );
        $stmt->execute(array_values($ids));
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(string) $row['id']] = $row;
        }
        return $out;
    }
    /**
     * Riwayat pesan satu zone, terbaru dulu.
     *
     * $before adalah kursor pesan tertua yang sudah dimiliki klien
     * (['timestamp' => string, 'id' => string]); null mengambil
     * halaman pertama (pesan-pesan terakhir).
     *
     * @param  array{timestamp: string, id: string}|null $before
     * @return array<int, array<string, mixed>>
     */
    public static function listByZone(string $zoneId, int $limit, ?array $before = null, ?PDO $pdo = null): array
    {
        $db = $pdo ?? getDB();

        $sql = "SELECT zm.*, u.username, pr.photo_profile_url
                FROM zone_messages zm
                JOIN users u ON u.id = zm.user_id
                LEFT JOIN profiles pr ON pr.user_id = u.id
                WHERE zm.zone_id = :zone_id";
        $bind = [':zone_id' => $zoneId];

        if ($before !== null) {
            // Dua placeholder terpisah untuk nilai timestamp yang sama:
            // PDO native prepares menolak nama placeholder yang muncul
            // dua kali dalam satu statement (HY093).
            $sql .= " AND (zm.timestamp < :before_ts
                        OR (zm.timestamp = :before_ts_eq AND zm.id < :before_id))";
            $bind[':before_ts'] = $before['timestamp'];
            $bind[':before_ts_eq'] = $before['timestamp'];
            $bind[':before_id'] = $before['id'];
        }

        $sql .= " ORDER BY zm.timestamp DESC, zm.id DESC LIMIT :limit";

        $stmt = $db->prepare($sql);
        foreach ($bind as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Helper: Generate UUID v4 standar (RFC 4122).
     */
    /** Menghapus semua pesan di satu zone (dipakai saat zone dihapus). */
    public static function deleteByZoneId(string $zoneId, ?PDO $pdo = null): bool
    {
        $db = $pdo ?? getDB();
        $stmt = $db->prepare("DELETE FROM zone_messages WHERE zone_id = :zone_id");
        return $stmt->execute([':zone_id' => $zoneId]);
    }

    /** Menghapus semua pesan di semua zone milik satu circle. */
    public static function deleteByCircleId(string $circleId, ?PDO $pdo = null): bool
    {
        $db = $pdo ?? getDB();
        $stmt = $db->prepare(
            "DELETE zm FROM zone_messages zm
             JOIN zone z ON z.id = zm.zone_id
             WHERE z.circle_id = :circle_id"
        );
        return $stmt->execute([':circle_id' => $circleId]);
    }

    public static function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // versi 4
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // variant RFC 4122
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
