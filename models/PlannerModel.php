<?php

/**
 * models/PlannerModel.php
 * ─────────────────────────────────────────────────────────────────────────────
 * PLANNER MODEL (Data Access Layer) — Tasks per collaboration.
 *
 * Planner menempel ke collaboration (keputusan user 2026-11-23),
 * kedudukannya sama dengan Circle tapi berdiri sendiri: collab
 * tanpa Circle tetap bisa punya planner. Tabel:
 *   - collab_planner_sections : pengelompokan task di dalam planner
 *   - collab_planner_roles    : role tambahan (Fase 1 cuma 'pm')
 *   - collab_tasks            : task per hari + relasi follower
 *
 * Relasi follower: task B mengikuti task A (follows_task_id = A).
 * Menggeser A sejauh N hari menggeser seluruh rantai follower-nya
 * sejauh N hari juga (jarak antar task tidak berubah). gap_days
 * menyimpan jarak B dari A; dihitung ulang saat B digeser manual.
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

require_once BASE_PATH . '/config/database.php';

class PlannerModel
{
    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return implode('-', [
            substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4),
            substr($hex, 16, 4), substr($hex, 20, 12),
        ]);
    }

    // ── Planner on/off ────────────────────────────────────────────────

    public static function setEnabled(string $collabId, bool $enabled): void
    {
        $db = getDB();
        $stmt = $db->prepare("UPDATE collaborations SET planner_enabled = :v WHERE id = :id");
        $stmt->execute([':v' => $enabled ? 1 : 0, ':id' => $collabId]);
    }

    /**
     * Status planner dibaca langsung dari tabel collaborations:
     * CollabModel::findById mengembalikan array kurasi yang tidak
     * memuat kolom planner_enabled.
     */
    public static function isEnabled(string $collabId): bool
    {
        $db = getDB();
        $stmt = $db->prepare("SELECT planner_enabled FROM collaborations WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $collabId]);
        return (int) $stmt->fetchColumn() === 1;
    }

    // ── Anggota + role ────────────────────────────────────────────────

    /**
     * Owner + accepted member, lengkap dengan nama dan foto profil,
     * owner selalu baris pertama.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function members(string $collabId, string $ownerId): array
    {
        $db = getDB();
        $stmt = $db->prepare("
            SELECT u.id, u.username, pr.full_name, pr.photo_profile_url, m.ord, jr.job_role
            FROM (
                SELECT :owner AS uid, 0 AS ord
                UNION ALL
                SELECT user_id AS uid, 1 AS ord FROM user_collaboration WHERE collaboration_id = :cid
            ) m
            JOIN users u ON u.id = m.uid
            LEFT JOIN profiles pr ON pr.user_id = u.id
            LEFT JOIN collab_planner_job_roles jr ON jr.collab_id = :cid2 AND jr.user_id = u.id
            ORDER BY m.ord ASC, u.username ASC
        ");
        $stmt->execute([':owner' => $ownerId, ':cid' => $collabId, ':cid2' => $collabId]);
        return $stmt->fetchAll();
    }

    /** @return array<string, string> user_id => role ('pm') */
    public static function rolesMap(string $collabId): array
    {
        $db = getDB();
        $stmt = $db->prepare("SELECT user_id, role FROM collab_planner_roles WHERE collab_id = :cid");
        $stmt->execute([':cid' => $collabId]);
        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[(string) $row['user_id']] = (string) $row['role'];
        }
        return $map;
    }

    /** $role 'pm' mengangkat, null menurunkan kembali jadi member biasa. */
    public static function setRole(string $collabId, string $userId, ?string $role): void
    {
        $db = getDB();
        if ($role === null) {
            $stmt = $db->prepare("DELETE FROM collab_planner_roles WHERE collab_id = :cid AND user_id = :uid");
            $stmt->execute([':cid' => $collabId, ':uid' => $userId]);
            return;
        }
        $stmt = $db->prepare("
            INSERT INTO collab_planner_roles (collab_id, user_id, role, timestamp)
            VALUES (:cid, :uid, :role, NOW())
            ON DUPLICATE KEY UPDATE role = VALUES(role)
        ");
        $stmt->execute([':cid' => $collabId, ':uid' => $userId, ':role' => $role]);
    }

    /** @return array<string, string> user_id => job_role */
    public static function jobRolesMap(string $collabId): array
    {
        $db = getDB();
        $stmt = $db->prepare("SELECT user_id, job_role FROM collab_planner_job_roles WHERE collab_id = :cid");
        $stmt->execute([':cid' => $collabId]);
        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[(string) $row['user_id']] = (string) $row['job_role'];
        }
        return $map;
    }

    /** $jobRole null atau '' menghapus job role anggota itu. */
    public static function setJobRole(string $collabId, string $userId, ?string $jobRole): void
    {
        $db = getDB();
        if ($jobRole === null || trim($jobRole) === '') {
            $stmt = $db->prepare("DELETE FROM collab_planner_job_roles WHERE collab_id = :cid AND user_id = :uid");
            $stmt->execute([':cid' => $collabId, ':uid' => $userId]);
            return;
        }
        $stmt = $db->prepare("
            INSERT INTO collab_planner_job_roles (collab_id, user_id, job_role, timestamp)
            VALUES (:cid, :uid, :role, NOW())
            ON DUPLICATE KEY UPDATE job_role = VALUES(job_role), timestamp = NOW()
        ");
        $stmt->execute([':cid' => $collabId, ':uid' => $userId, ':role' => trim($jobRole)]);
    }

    // ── Sections ──────────────────────────────────────────────────────

    /** @return array<int, array<string, mixed>> */
    public static function listSections(string $collabId): array
    {
        $db = getDB();
        $stmt = $db->prepare("SELECT * FROM collab_planner_sections WHERE collab_id = :cid ORDER BY position ASC, timestamp ASC");
        $stmt->execute([':cid' => $collabId]);
        return $stmt->fetchAll();
    }

    public static function findSection(string $sectionId): ?array
    {
        $db = getDB();
        $stmt = $db->prepare("SELECT * FROM collab_planner_sections WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $sectionId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function createSection(string $collabId, string $title): array
    {
        $db = getDB();
        $stmt = $db->prepare("SELECT COALESCE(MAX(position), -1) + 1 FROM collab_planner_sections WHERE collab_id = :cid");
        $stmt->execute([':cid' => $collabId]);
        $position = (int) $stmt->fetchColumn();

        $id = self::uuid();
        $stmt = $db->prepare("INSERT INTO collab_planner_sections (id, collab_id, title, position, timestamp) VALUES (:id, :cid, :title, :pos, NOW())");
        $stmt->execute([':id' => $id, ':cid' => $collabId, ':title' => $title, ':pos' => $position]);
        return self::findSection($id) ?? ['id' => $id, 'collab_id' => $collabId, 'title' => $title, 'position' => $position];
    }

    public static function renameSection(string $sectionId, string $title): void
    {
        $db = getDB();
        $stmt = $db->prepare("UPDATE collab_planner_sections SET title = :title WHERE id = :id");
        $stmt->execute([':title' => $title, ':id' => $sectionId]);
    }

    /** Section dihapus permanen; task di dalamnya jadi tanpa section (FK SET NULL). */
    public static function deleteSection(string $sectionId): void
    {
        $db = getDB();
        $stmt = $db->prepare("DELETE FROM collab_planner_sections WHERE id = :id");
        $stmt->execute([':id' => $sectionId]);
    }

    // ── Tasks ─────────────────────────────────────────────────────────

    /** @return array<int, array<string, mixed>> task + info assignee/creator/leader */
    public static function listTasks(string $collabId): array
    {
        $db = getDB();
        $stmt = $db->prepare("
            SELECT t.*,
                   au.username AS assignee_username, ap.full_name AS assignee_name, ap.photo_profile_url AS assignee_photo,
                   cu.username AS creator_username,
                   lt.title AS follows_title
            FROM collab_tasks t
            LEFT JOIN users au ON au.id = t.assignee_id
            LEFT JOIN profiles ap ON ap.user_id = t.assignee_id
            LEFT JOIN users cu ON cu.id = t.created_by
            LEFT JOIN collab_tasks lt ON lt.id = t.follows_task_id
            WHERE t.collab_id = :cid AND t.deleted_at IS NULL
            ORDER BY (t.done_at IS NOT NULL) ASC, t.due_date IS NULL ASC, t.due_date ASC, t.position ASC, t.timestamp ASC
        ");
        $stmt->execute([':cid' => $collabId]);
        return $stmt->fetchAll();
    }

    public static function findTask(string $taskId): ?array
    {
        $db = getDB();
        $stmt = $db->prepare("
            SELECT t.*,
                   au.username AS assignee_username, ap.full_name AS assignee_name, ap.photo_profile_url AS assignee_photo,
                   cu.username AS creator_username,
                   lt.title AS follows_title
            FROM collab_tasks t
            LEFT JOIN users au ON au.id = t.assignee_id
            LEFT JOIN profiles ap ON ap.user_id = t.assignee_id
            LEFT JOIN users cu ON cu.id = t.created_by
            LEFT JOIN collab_tasks lt ON lt.id = t.follows_task_id
            WHERE t.id = :id AND t.deleted_at IS NULL
            LIMIT 1
        ");
        $stmt->execute([':id' => $taskId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * @param array{collab_id: string, section_id: ?string, title: string, assignee_id: ?string,
     *              created_by: string, due_date: ?string, start_date: ?string, icon: ?string,
     *              blocks_json: ?string, pencilled: int, follows_task_id: ?string, gap_days: ?int} $data
     */
    public static function createTask(array $data): array
    {
        $db = getDB();
        $id = self::uuid();
        $stmt = $db->prepare("
            INSERT INTO collab_tasks (id, collab_id, section_id, title, assignee_id, created_by, due_date, start_date, icon, blocks_json, pencilled, follows_task_id, gap_days, position, timestamp)
            VALUES (:id, :cid, :sid, :title, :assignee, :creator, :due, :start, :icon, :blocks, :pencilled, :follows, :gap, 0, NOW())
        ");
        $stmt->execute([
            ':id' => $id,
            ':cid' => $data['collab_id'],
            ':sid' => $data['section_id'],
            ':title' => $data['title'],
            ':assignee' => $data['assignee_id'],
            ':creator' => $data['created_by'],
            ':due' => $data['due_date'],
            ':start' => $data['start_date'] ?? null,
            ':icon' => $data['icon'] ?? null,
            ':blocks' => $data['blocks_json'] ?? null,
            ':pencilled' => $data['pencilled'] ?? 0,
            ':follows' => $data['follows_task_id'],
            ':gap' => $data['gap_days'],
        ]);
        return self::findTask($id) ?? ['id' => $id];
    }

    /** Update kolom-kolom biasa (bukan due_date — itu lewat jalur cascade). */
    public static function updateFields(string $taskId, array $fields): void
    {
        if ($fields === []) {
            return;
        }
        $allowed = ['title', 'section_id', 'assignee_id', 'follows_task_id', 'gap_days', 'done_at', 'done_by', 'start_date', 'icon', 'blocks_json', 'pencilled'];
        $sets = [];
        $params = [':id' => $taskId];
        foreach ($fields as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                continue;
            }
            $sets[] = "`{$key}` = :{$key}";
            $params[":{$key}"] = $value;
        }
        if ($sets === []) {
            return;
        }
        $sets[] = 'updated_at = NOW()';
        $db = getDB();
        $stmt = $db->prepare("UPDATE collab_tasks SET " . implode(', ', $sets) . " WHERE id = :id");
        $stmt->execute($params);
    }

    public static function setDueDate(string $taskId, ?string $dueDate): void
    {
        $db = getDB();
        $stmt = $db->prepare("UPDATE collab_tasks SET due_date = :due, updated_at = NOW() WHERE id = :id");
        $stmt->execute([':due' => $dueDate, ':id' => $taskId]);
    }

    public static function setStartDate(string $taskId, ?string $startDate): void
    {
        $db = getDB();
        $stmt = $db->prepare("UPDATE collab_tasks SET start_date = :start, updated_at = NOW() WHERE id = :id");
        $stmt->execute([':start' => $startDate, ':id' => $taskId]);
    }

    public static function setGapDays(string $taskId, ?int $gapDays): void
    {
        $db = getDB();
        $stmt = $db->prepare("UPDATE collab_tasks SET gap_days = :gap WHERE id = :id");
        $stmt->execute([':gap' => $gapDays, ':id' => $taskId]);
    }

    /** Tandai selesai / batal selesai. Waktu pakai NOW() dari MySQL (WIB). */
    public static function setDone(string $taskId, string $userId, bool $done): void
    {
        $db = getDB();
        if ($done) {
            $stmt = $db->prepare("UPDATE collab_tasks SET done_at = NOW(), done_by = :uid, updated_at = NOW() WHERE id = :id");
            $stmt->execute([':uid' => $userId, ':id' => $taskId]);
        } else {
            $stmt = $db->prepare("UPDATE collab_tasks SET done_at = NULL, done_by = NULL, updated_at = NOW() WHERE id = :id");
            $stmt->execute([':id' => $taskId]);
        }
    }

    public static function softDelete(string $taskId): void
    {
        $db = getDB();
        $stmt = $db->prepare("UPDATE collab_tasks SET deleted_at = NOW() WHERE id = :id");
        $stmt->execute([':id' => $taskId]);
    }

    // ── Relasi follower ───────────────────────────────────────────────

    /** @return array<int, array<string, mixed>> follower langsung dari satu task */
    public static function followersOf(string $taskId): array
    {
        $db = getDB();
        $stmt = $db->prepare("SELECT * FROM collab_tasks WHERE follows_task_id = :id AND deleted_at IS NULL");
        $stmt->execute([':id' => $taskId]);
        return $stmt->fetchAll();
    }

    /**
     * Geser satu task sejauh $delta hari lalu turunkan geseran yang
     * sama ke semua follower-nya (rekursif, anti-loop pakai visited).
     * Task tanpa tanggal tidak ikut bergeser.
     *
     * @param array<string, bool> $visited
     * @return array<int, string> id semua task yang ikut bergeser (di luar task asal)
     */
    public static function shiftWithFollowers(string $taskId, int $delta, array &$visited = []): array
    {
        if ($delta === 0 || isset($visited[$taskId])) {
            return [];
        }
        $visited[$taskId] = true;
        $shifted = [];
        foreach (self::followersOf($taskId) as $follower) {
            $fid = (string) $follower['id'];
            if (($follower['due_date'] ?? null) !== null) {
                $db = getDB();
                $stmt = $db->prepare("UPDATE collab_tasks SET due_date = DATE_ADD(due_date, INTERVAL :delta DAY), start_date = DATE_ADD(start_date, INTERVAL :delta2 DAY), updated_at = NOW() WHERE id = :id");
                $stmt->execute([':delta' => $delta, ':delta2' => $delta, ':id' => $fid]);
                $shifted[] = $fid;
            }
            $shifted = array_merge($shifted, self::shiftWithFollowers($fid, $delta, $visited));
        }
        return $shifted;
    }

    /** Jarak hari task dari task yang diikuti; null bila salah satu belum bertanggal. */
    public static function computeGap(?string $taskDue, ?string $leaderDue): ?int
    {
        if ($taskDue === null || $leaderDue === null) {
            return null;
        }
        $a = new DateTimeImmutable($taskDue);
        $b = new DateTimeImmutable($leaderDue);
        return (int) $b->diff($a)->format('%r%a');
    }

    /** True bila menjadikan $leaderId sebagai leader dari $taskId membentuk lingkaran. */
    public static function wouldCycle(string $taskId, string $leaderId): bool
    {
        $db = getDB();
        $seen = [];
        $cursor = $leaderId;
        while ($cursor !== null && $cursor !== '') {
            if ($cursor === $taskId || isset($seen[$cursor])) {
                return true;
            }
            $seen[$cursor] = true;
            $stmt = $db->prepare("SELECT follows_task_id FROM collab_tasks WHERE id = :id AND deleted_at IS NULL");
            $stmt->execute([':id' => $cursor]);
            $next = $stmt->fetchColumn();
            $cursor = $next === false ? null : $next;
        }
        return false;
    }

    // ── Halaman Tasks global ──────────────────────────────────────────

    /** @return array<int, array<string, mixed>> task yang di-assign ke user, lintas collab */
    public static function myTasks(string $userId): array
    {
        $db = getDB();
        $stmt = $db->prepare("
            SELECT t.*,
                   c.title AS collab_title,
                   au.username AS assignee_username, ap.full_name AS assignee_name, ap.photo_profile_url AS assignee_photo,
                   cu.username AS creator_username,
                   lt.title AS follows_title
            FROM collab_tasks t
            JOIN collaborations c ON c.id = t.collab_id AND c.deleted_at IS NULL
            LEFT JOIN users au ON au.id = t.assignee_id
            LEFT JOIN profiles ap ON ap.user_id = t.assignee_id
            LEFT JOIN users cu ON cu.id = t.created_by
            LEFT JOIN collab_tasks lt ON lt.id = t.follows_task_id
            WHERE t.assignee_id = :uid AND t.deleted_at IS NULL
            ORDER BY (t.done_at IS NOT NULL) ASC, t.due_date IS NULL ASC, t.due_date ASC, t.timestamp ASC
        ");
        $stmt->execute([':uid' => $userId]);
        return $stmt->fetchAll();
    }

    /**
     * Collab yang user ikuti (owner atau accepted member) dengan
     * planner aktif, beserta jumlah task terbukanya. Menggerakkan
     * submenu Tasks di sidebar untuk team board.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function myPlanners(string $userId): array
    {
        $db = getDB();
        $stmt = $db->prepare("
            SELECT c.id, c.title,
                   (SELECT COUNT(*) FROM collab_tasks t
                     WHERE t.collab_id = c.id AND t.deleted_at IS NULL AND t.done_at IS NULL) AS open_count
            FROM collaborations c
            WHERE c.planner_enabled = 1 AND c.deleted_at IS NULL
              AND (c.user_id = :uid
                   OR EXISTS (SELECT 1 FROM user_collaboration uc
                              WHERE uc.collaboration_id = c.id AND uc.user_id = :uid2))
            ORDER BY c.title ASC
        ");
        $stmt->execute([':uid' => $userId, ':uid2' => $userId]);
        return $stmt->fetchAll();
    }
}
