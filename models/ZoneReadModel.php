<?php

require_once __DIR__ . '/../config/database.php';

/**
 * Read state for zone messages (Discord-style unread indicators):
 * one row per (user, zone) holding when the user last read the zone.
 * Unread = messages from other members newer than that moment.
 * Rows disappear with their zone/user via ON DELETE CASCADE.
 */
class ZoneReadModel
{
    /**
     * Unread counts for a set of zones.
     *
     * @param  string[] $zoneIds
     * @return array<string, int> zoneId => unread count (missing = 0)
     */
    public static function unreadCountsForZones(array $zoneIds, string $userId): array
    {
        if ($zoneIds === []) {
            return [];
        }
        $db = getDB();
        $params = [':reader' => $userId, ':author' => $userId];
        $placeholders = [];
        foreach (array_values($zoneIds) as $i => $zoneId) {
            $key = ':z' . $i;
            $placeholders[] = $key;
            $params[$key] = $zoneId;
        }
        $stmt = $db->prepare(
            'SELECT zm.zone_id, COUNT(*) AS unread
             FROM zone_messages zm
             LEFT JOIN zone_reads zr
               ON zr.zone_id = zm.zone_id AND zr.user_id = :reader
             WHERE zm.zone_id IN (' . implode(',', $placeholders) . ')
               AND zm.user_id <> :author
               AND (zr.last_read_at IS NULL OR zm.timestamp > zr.last_read_at)
             GROUP BY zm.zone_id'
        );
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(string) $row['zone_id']] = (int) $row['unread'];
        }
        return $out;
    }

    /**
     * Unread totals for a set of circles (rail badges).
     *
     * @param  string[] $circleIds
     * @return array<string, int> circleId => unread count (missing = 0)
     */
    public static function unreadCountsForCircles(array $circleIds, string $userId): array
    {
        if ($circleIds === []) {
            return [];
        }
        $db = getDB();
        $params = [':reader' => $userId, ':author' => $userId];
        $placeholders = [];
        foreach (array_values($circleIds) as $i => $circleId) {
            $key = ':c' . $i;
            $placeholders[] = $key;
            $params[$key] = $circleId;
        }
        $stmt = $db->prepare(
            'SELECT z.circle_id, COUNT(*) AS unread
             FROM zone_messages zm
             JOIN zone z ON z.id = zm.zone_id
             LEFT JOIN zone_reads zr
               ON zr.zone_id = zm.zone_id AND zr.user_id = :reader
             WHERE z.circle_id IN (' . implode(',', $placeholders) . ')
               AND zm.user_id <> :author
               AND (zr.last_read_at IS NULL OR zm.timestamp > zr.last_read_at)
             GROUP BY z.circle_id'
        );
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(string) $row['circle_id']] = (int) $row['unread'];
        }
        return $out;
    }

    /**
     * Last-read timestamps for a set of zones.
     *
     * @param  string[] $zoneIds
     * @return array<string, string|null> zoneId => last_read_at
     */
    public static function lastReadForZones(array $zoneIds, string $userId): array
    {
        if ($zoneIds === []) {
            return [];
        }
        $db = getDB();
        $params = [':reader' => $userId];
        $placeholders = [];
        foreach (array_values($zoneIds) as $i => $zoneId) {
            $key = ':z' . $i;
            $placeholders[] = $key;
            $params[$key] = $zoneId;
        }
        $stmt = $db->prepare(
            'SELECT zone_id, last_read_at FROM zone_reads
             WHERE user_id = :reader
               AND zone_id IN (' . implode(',', $placeholders) . ')'
        );
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(string) $row['zone_id']] = $row['last_read_at'] !== null
                ? (string) $row['last_read_at']
                : null;
        }
        return $out;
    }

    /**
     * Mark a zone read up to its newest message (or now when empty).
     */
    public static function markRead(string $zoneId, string $userId): void
    {
        $db = getDB();
        $stmt = $db->prepare(
            'INSERT INTO zone_reads (user_id, zone_id, last_read_at)
             VALUES (:uid, :zid,
                     COALESCE((SELECT MAX(timestamp) FROM zone_messages
                               WHERE zone_id = :zid_newest), NOW()))
             AS new_row
             ON DUPLICATE KEY UPDATE last_read_at = new_row.last_read_at'
        );
        $stmt->execute([
            ':uid' => $userId,
            ':zid' => $zoneId,
            ':zid_newest' => $zoneId,
        ]);
    }
}
