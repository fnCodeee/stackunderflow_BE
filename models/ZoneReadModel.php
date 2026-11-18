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
               AND zm.user_id <> :author AND zm.deleted_at IS NULL
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
               AND zm.user_id <> :author AND zm.deleted_at IS NULL
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

    /**
     * Does a message mention this user? A direct @username token,
     * or an @everyone token written by the collaboration owner
     * (the same certification presentMessage applies for display).
     */
    private static function mentionsUser(string $content, string $username, bool $authorIsOwner): bool
    {
        if (
            $authorIsOwner
            && preg_match('/(?:^|\s)@everyone(?=$|[\s.,!?;:])/', $content) === 1
        ) {
            return true;
        }
        if ($username === '') {
            return false;
        }
        return preg_match(
            '/(?:^|\s)@' . preg_quote($username, '/') . '(?=$|[\s.,!?;:])/',
            $content
        ) === 1;
    }

    private static function usernameOf(string $userId): string
    {
        $db = getDB();
        $stmt = $db->prepare('SELECT username FROM users WHERE id = :id');
        $stmt->execute([':id' => $userId]);
        $name = $stmt->fetchColumn();
        return $name !== false ? (string) $name : '';
    }

    /**
     * Mention stats for a set of zones inside ONE circle (single
     * owner): unread messages that mention the reader, plus the
     * earliest one's id so the UI can jump straight to it.
     *
     * @param  string[] $zoneIds
     * @return array<string, array{count: int, firstId: string|null}>
     */
    public static function mentionStatsForZones(array $zoneIds, string $userId, string $ownerId): array
    {
        if ($zoneIds === []) {
            return [];
        }
        $username = self::usernameOf($userId);
        $db = getDB();
        $params = [':reader' => $userId, ':author' => $userId];
        $placeholders = [];
        foreach (array_values($zoneIds) as $i => $zoneId) {
            $key = ':z' . $i;
            $placeholders[] = $key;
            $params[$key] = $zoneId;
        }
        $stmt = $db->prepare(
            'SELECT zm.id, zm.zone_id, zm.user_id, zm.message
             FROM zone_messages zm
             LEFT JOIN zone_reads zr
               ON zr.zone_id = zm.zone_id AND zr.user_id = :reader
             WHERE zm.zone_id IN (' . implode(',', $placeholders) . ')
               AND zm.user_id <> :author AND zm.deleted_at IS NULL
               AND (zr.last_read_at IS NULL OR zm.timestamp > zr.last_read_at)
             ORDER BY zm.timestamp ASC'
        );
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $isOwnerAuthor = (string) $row['user_id'] === $ownerId;
            if (!self::mentionsUser((string) $row['message'], $username, $isOwnerAuthor)) {
                continue;
            }
            $zoneId = (string) $row['zone_id'];
            if (!isset($out[$zoneId])) {
                $out[$zoneId] = ['count' => 0, 'firstId' => (string) $row['id']];
            }
            $out[$zoneId]['count']++;
        }
        return $out;
    }

    /**
     * Mention totals for a set of circles (rail indicator). The
     * owner differs per circle, so the scan joins through to each
     * collaboration for its owner.
     *
     * @param  string[] $circleIds
     * @return array<string, int> circleId => mention count (missing = 0)
     */
    public static function mentionCountsForCircles(array $circleIds, string $userId): array
    {
        if ($circleIds === []) {
            return [];
        }
        $username = self::usernameOf($userId);
        $db = getDB();
        $params = [':reader' => $userId, ':author' => $userId];
        $placeholders = [];
        foreach (array_values($circleIds) as $i => $circleId) {
            $key = ':c' . $i;
            $placeholders[] = $key;
            $params[$key] = $circleId;
        }
        $stmt = $db->prepare(
            'SELECT z.circle_id, zm.user_id, zm.message, co.user_id AS owner_id
             FROM zone_messages zm
             JOIN zone z ON z.id = zm.zone_id
             JOIN circle ci ON ci.id = z.circle_id
             JOIN collaborations co ON co.id = ci.collaboration_id
             LEFT JOIN zone_reads zr
               ON zr.zone_id = zm.zone_id AND zr.user_id = :reader
             WHERE z.circle_id IN (' . implode(',', $placeholders) . ')
               AND zm.user_id <> :author AND zm.deleted_at IS NULL
               AND (zr.last_read_at IS NULL OR zm.timestamp > zr.last_read_at)'
        );
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $isOwnerAuthor = (string) $row['user_id'] === (string) $row['owner_id'];
            if (!self::mentionsUser((string) $row['message'], $username, $isOwnerAuthor)) {
                continue;
            }
            $circleId = (string) $row['circle_id'];
            $out[$circleId] = ($out[$circleId] ?? 0) + 1;
        }
        return $out;
    }
}
