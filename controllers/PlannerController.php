<?php

/**
 * controllers/PlannerController.php
 * ─────────────────────────────────────────────────────────────────────────────
 * PLANNER CONTROLLER — Tasks per collaboration (Fase 1).
 *
 * Keputusan user 2026-11-23 yang dikunci di sini:
 *  - Planner menempel ke collab (sama kedudukannya dengan Circle,
 *    tapi berdiri sendiri — tidak wajib ada Circle). Owner yang
 *    menentukan planner-nya dibuat atau tidak (enable/disable).
 *  - Menggeser tanggal task: hanya pemilik task (assignee), owner,
 *    atau anggota yang diangkat owner jadi PM. Anggota biasa tidak
 *    bisa menggeser task orang lain.
 *  - PM juga boleh membuat task untuk orang lain dan mengganti
 *    assignee. Menghapus task: hanya pembuatnya atau owner (PM tidak).
 *  - Relasi follower: menggeser task menggeser semua follower-nya
 *    sejauh delta yang sama; follower yang digeser manual jaraknya
 *    ke leader berubah, rantainya tetap tersambung.
 *  - Judul task TIDAK masuk antrian moderasi AI (mengikuti perlakuan
 *    pesan zone Circle: konten internal tim, bukan konten publik).
 *
 * Semua endpoint butuh JWT; akses dibatasi owner + accepted member.
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

require_once BASE_PATH . '/models/CollabModel.php';
require_once BASE_PATH . '/models/PlannerModel.php';
require_once BASE_PATH . '/utils/Realtime.php';
require_once BASE_PATH . '/utils/Response.php';

class PlannerController
{
    // ── GET /api/collab/:id/planner ───────────────────────────────────
    public static function getPlanner(array $params = []): void
    {
        try {
            $userId = self::requireUser();
            $access = self::resolveAccess((string) ($params['id'] ?? ''), $userId);
            $collab = $access['collab'];
            $collabId = (string) $collab['id'];
            $enabled = (int) ($collab['planner_enabled'] ?? 0) === 1;

            $rolesMap = PlannerModel::rolesMap($collabId);
            $members = array_map(
                static fn (array $row): array => self::presentMember($row, $access, $rolesMap),
                PlannerModel::members($collabId, (string) $collab['user_id'])
            );

            Response::json([
                'collabId' => $collabId,
                'enabled' => $enabled,
                'myRole' => $access['role'],
                'members' => $members,
                'sections' => $enabled
                    ? array_map(static fn (array $s): array => self::presentSection($s), PlannerModel::listSections($collabId))
                    : [],
                'tasks' => $enabled
                    ? array_map(static fn (array $t): array => self::presentTask($t), PlannerModel::listTasks($collabId))
                    : [],
            ]);
        } catch (Throwable) {
            Response::json(null, 500, 'Internal server error', false);
        }
    }

    // ── POST /api/collab/:id/planner/enable|disable (owner) ──────────
    public static function enable(array $params = []): void
    {
        self::setEnabled($params, true);
    }

    public static function disable(array $params = []): void
    {
        self::setEnabled($params, false);
    }

    private static function setEnabled(array $params, bool $enabled): void
    {
        try {
            $userId = self::requireUser();
            $access = self::resolveAccess((string) ($params['id'] ?? ''), $userId);
            if (!$access['isOwner']) {
                Response::json(null, 403, 'Only the project owner can change the planner', false);
            }
            PlannerModel::setEnabled((string) $access['collab']['id'], $enabled);
            Response::json(['enabled' => $enabled], 200, $enabled ? 'Planner enabled' : 'Planner disabled');
        } catch (Throwable) {
            Response::json(null, 500, 'Internal server error', false);
        }
    }

    // ── PUT /api/collab/:id/planner/roles (owner) ─────────────────────
    public static function setRole(array $params = []): void
    {
        try {
            $userId = self::requireUser();
            $access = self::resolveAccess((string) ($params['id'] ?? ''), $userId);
            if (!$access['isOwner']) {
                Response::json(null, 403, 'Only the project owner can assign roles', false);
            }
            $body = $GLOBALS['body'] ?? [];
            $targetId = trim((string) ($body['userId'] ?? ''));
            $role = trim((string) ($body['role'] ?? ''));
            if ($targetId === '' || !in_array($role, ['pm', 'member'], true)) {
                Response::json(null, 400, 'userId and a valid role are required', false);
            }
            if ($targetId === (string) $access['collab']['user_id']) {
                Response::json(null, 400, 'The owner already has full access', false);
            }
            if (!CollabModel::isMember((string) $access['collab']['id'], $targetId)) {
                Response::json(null, 400, 'That user is not a member of this project', false);
            }
            PlannerModel::setRole((string) $access['collab']['id'], $targetId, $role === 'pm' ? 'pm' : null);
            Response::json(['userId' => $targetId, 'role' => $role], 200, 'Role updated');
        } catch (Throwable) {
            Response::json(null, 500, 'Internal server error', false);
        }
    }

    // ── Sections ──────────────────────────────────────────────────────

    public static function createSection(array $params = []): void
    {
        try {
            $userId = self::requireUser();
            $access = self::resolveAccess((string) ($params['id'] ?? ''), $userId);
            self::requireEnabled($access);
            if (!self::canManage($access)) {
                Response::json(null, 403, 'Only the owner or a PM can manage sections', false);
            }
            $title = trim((string) (($GLOBALS['body'] ?? [])['title'] ?? ''));
            if ($title === '' || mb_strlen($title) > 120) {
                Response::json(null, 400, 'Section title is required (max 120 characters)', false);
            }
            $section = PlannerModel::createSection((string) $access['collab']['id'], $title);
            Response::json(self::presentSection($section), 201, 'Section created');
        } catch (Throwable) {
            Response::json(null, 500, 'Internal server error', false);
        }
    }

    public static function updateSection(array $params = []): void
    {
        try {
            $userId = self::requireUser();
            $access = self::resolveAccess((string) ($params['id'] ?? ''), $userId);
            self::requireEnabled($access);
            if (!self::canManage($access)) {
                Response::json(null, 403, 'Only the owner or a PM can manage sections', false);
            }
            $section = self::resolveSection($access, (string) ($params['sectionId'] ?? ''));
            $title = trim((string) (($GLOBALS['body'] ?? [])['title'] ?? ''));
            if ($title === '' || mb_strlen($title) > 120) {
                Response::json(null, 400, 'Section title is required (max 120 characters)', false);
            }
            PlannerModel::renameSection((string) $section['id'], $title);
            $fresh = PlannerModel::findSection((string) $section['id']);
            Response::json(self::presentSection($fresh ?? $section), 200, 'Section updated');
        } catch (Throwable) {
            Response::json(null, 500, 'Internal server error', false);
        }
    }

    public static function deleteSection(array $params = []): void
    {
        try {
            $userId = self::requireUser();
            $access = self::resolveAccess((string) ($params['id'] ?? ''), $userId);
            self::requireEnabled($access);
            if (!self::canManage($access)) {
                Response::json(null, 403, 'Only the owner or a PM can manage sections', false);
            }
            $section = self::resolveSection($access, (string) ($params['sectionId'] ?? ''));
            PlannerModel::deleteSection((string) $section['id']);
            Response::json(['id' => (string) $section['id']], 200, 'Section deleted');
        } catch (Throwable) {
            Response::json(null, 500, 'Internal server error', false);
        }
    }

    // ── Tasks ─────────────────────────────────────────────────────────

    public static function createTask(array $params = []): void
    {
        try {
            $userId = self::requireUser();
            $access = self::resolveAccess((string) ($params['id'] ?? ''), $userId);
            self::requireEnabled($access);
            $collabId = (string) $access['collab']['id'];
            $body = $GLOBALS['body'] ?? [];

            $title = trim((string) ($body['title'] ?? ''));
            if ($title === '' || mb_strlen($title) > 200) {
                Response::json(null, 400, 'Task title is required (max 200 characters)', false);
            }

            $sectionId = self::nullableId($body['sectionId'] ?? null);
            if ($sectionId !== null) {
                $section = PlannerModel::findSection($sectionId);
                if ($section === null || (string) $section['collab_id'] !== $collabId) {
                    Response::json(null, 400, 'Section not found in this planner', false);
                }
            }

            $assigneeId = self::nullableId($body['assigneeId'] ?? null) ?? $userId;
            if ($assigneeId !== $userId && !self::canManage($access)) {
                Response::json(null, 403, 'Only the owner or a PM can assign tasks to other members', false);
            }
            self::requireMember($access, $assigneeId);

            $dueDate = self::validDueDate($body['dueDate'] ?? null);
            $startDate = self::validDueDate($body['startDate'] ?? null);
            if ($startDate !== null && $dueDate === null) {
                Response::json(null, 400, 'startDate needs a dueDate', false);
            }
            if ($startDate !== null && $dueDate !== null && $startDate > $dueDate) {
                Response::json(null, 400, 'startDate must not be after dueDate', false);
            }
            $icon = self::validIcon($body['icon'] ?? null);
            $blocksJson = self::validBlocks($body['blocks'] ?? null);
            $pencilled = !empty($body['pencilled']);

            $followsId = self::nullableId($body['followsTaskId'] ?? null);
            $gap = null;
            if ($followsId !== null) {
                $leader = PlannerModel::findTask($followsId);
                if ($leader === null || (string) $leader['collab_id'] !== $collabId) {
                    Response::json(null, 400, 'The followed task is not in this planner', false);
                }
                $gap = PlannerModel::computeGap($dueDate, $leader['due_date'] ?? null);
            }

            $task = PlannerModel::createTask([
                'collab_id' => $collabId,
                'section_id' => $sectionId,
                'title' => $title,
                'assignee_id' => $assigneeId,
                'created_by' => $userId,
                'due_date' => $dueDate,
                'start_date' => $startDate,
                'icon' => $icon,
                'blocks_json' => $blocksJson,
                'pencilled' => $pencilled ? 1 : 0,
                'follows_task_id' => $followsId,
                'gap_days' => $gap,
            ]);
            $presented = self::presentTask($task);
            self::publishTask($access, $presented);
            Response::json($presented, 201, 'Task created');
        } catch (Throwable) {
            Response::json(null, 500, 'Internal server error', false);
        }
    }

    public static function updateTask(array $params = []): void
    {
        try {
            $userId = self::requireUser();
            $access = self::resolveAccess((string) ($params['id'] ?? ''), $userId);
            self::requireEnabled($access);
            $collabId = (string) $access['collab']['id'];
            $task = self::resolveTask($access, (string) ($params['taskId'] ?? ''));
            $taskId = (string) $task['id'];
            $body = $GLOBALS['body'] ?? [];

            $isCreator = (string) $task['created_by'] === $userId;
            $isAssignee = (string) ($task['assignee_id'] ?? '') === $userId;
            $canMove = $isAssignee || self::canManage($access);

            $fields = [];

            if (array_key_exists('title', $body)) {
                if (!$isCreator && !$access['isOwner']) {
                    Response::json(null, 403, 'Only the task creator or the owner can rename this task', false);
                }
                $title = trim((string) $body['title']);
                if ($title === '' || mb_strlen($title) > 200) {
                    Response::json(null, 400, 'Task title is required (max 200 characters)', false);
                }
                $fields['title'] = $title;
            }

            if (array_key_exists('sectionId', $body)) {
                if (!$isCreator && !self::canManage($access)) {
                    Response::json(null, 403, 'You cannot move this task to another section', false);
                }
                $sectionId = self::nullableId($body['sectionId']);
                if ($sectionId !== null) {
                    $section = PlannerModel::findSection($sectionId);
                    if ($section === null || (string) $section['collab_id'] !== $collabId) {
                        Response::json(null, 400, 'Section not found in this planner', false);
                    }
                }
                $fields['section_id'] = $sectionId;
            }

            if (array_key_exists('assigneeId', $body)) {
                if (!self::canManage($access)) {
                    Response::json(null, 403, 'Only the owner or a PM can change the assignee', false);
                }
                $assigneeId = self::nullableId($body['assigneeId']);
                if ($assigneeId !== null) {
                    self::requireMember($access, $assigneeId);
                }
                $fields['assignee_id'] = $assigneeId;
            }

            if (array_key_exists('icon', $body)) {
                if (!$isCreator && !$access['isOwner']) {
                    Response::json(null, 403, 'Only the task creator or the owner can change this task', false);
                }
                $fields['icon'] = self::validIcon($body['icon']);
            }

            if (array_key_exists('blocks', $body)) {
                if (!$isCreator && !$access['isOwner']) {
                    Response::json(null, 403, 'Only the task creator or the owner can change this task', false);
                }
                $fields['blocks_json'] = self::validBlocks($body['blocks']);
            }

            if (array_key_exists('pencilled', $body)) {
                if (!$canMove) {
                    Response::json(null, 403, 'Only the assignee, the owner, or a PM can change this task', false);
                }
                $fields['pencilled'] = !empty($body['pencilled']) ? 1 : 0;
            }

            // Urutan vertikal (lane) di board: disimpan supaya pill
            // tidak lompat sendiri ke lane lama pas board di-refresh.
            // Tanpa ini, drag yang cuma pindah lane (hari sama) tidak
            // tersimpan sama sekali, dan drag pindah hari pun kehilangan
            // urutan vertikalnya karena ORDER BY pakai position lama.
            if (array_key_exists('position', $body)) {
                if (!$canMove) {
                    Response::json(null, 403, 'Only the assignee, the owner, or a PM can reorder this task', false);
                }
                $fields['position'] = max(0, (int) $body['position']);
            }

            if (array_key_exists('done', $body)) {
                if (!$canMove) {
                    Response::json(null, 403, 'Only the assignee, the owner, or a PM can complete this task', false);
                }
                PlannerModel::setDone($taskId, $userId, (bool) $body['done']);
                $task = PlannerModel::findTask($taskId) ?? $task;
            }

            // Relasi follower diganti sebelum tanggal digeser, supaya
            // gap dihitung dari leader yang baru.
            if (array_key_exists('followsTaskId', $body)) {
                if (!$canMove) {
                    Response::json(null, 403, 'Only the assignee, the owner, or a PM can change task relations', false);
                }
                $followsId = self::nullableId($body['followsTaskId']);
                if ($followsId !== null) {
                    if ($followsId === $taskId) {
                        Response::json(null, 400, 'A task cannot follow itself', false);
                    }
                    $leader = PlannerModel::findTask($followsId);
                    if ($leader === null || (string) $leader['collab_id'] !== $collabId) {
                        Response::json(null, 400, 'The followed task is not in this planner', false);
                    }
                    if (PlannerModel::wouldCycle($taskId, $followsId)) {
                        Response::json(null, 400, 'That relation would create a loop', false);
                    }
                }
                $fields['follows_task_id'] = $followsId;
            }

            if ($fields !== []) {
                PlannerModel::updateFields($taskId, $fields);
                $task = PlannerModel::findTask($taskId) ?? $task;
            }

            // Geser tanggal: inti mekanik planner. Delta yang sama
            // diturunkan ke semua follower (rantai), jarak tetap.
            $shiftedIds = [];
            if (array_key_exists('dueDate', $body)) {
                if (!$canMove) {
                    Response::json(null, 403, 'Only the assignee, the owner, or a PM can reschedule this task', false);
                }
                $newDue = self::validDueDate($body['dueDate']);
                $oldDue = $task['due_date'] ?? null;
                if ($newDue !== $oldDue) {
                    PlannerModel::setDueDate($taskId, $newDue);
                    if ($newDue === null) {
                        PlannerModel::setStartDate($taskId, null);
                    }
                    if ($newDue !== null && $oldDue !== null) {
                        $delta = PlannerModel::computeGap($newDue, $oldDue) ?? 0;
                        $shiftedIds = PlannerModel::shiftWithFollowers($taskId, $delta);
                        foreach ($shiftedIds as $shiftedId) {
                            $shiftedRow = PlannerModel::findTask($shiftedId);
                            if ($shiftedRow !== null && ($shiftedRow['follows_task_id'] ?? null) !== null) {
                                $leaderRow = PlannerModel::findTask((string) $shiftedRow['follows_task_id']);
                                PlannerModel::setGapDays(
                                    $shiftedId,
                                    PlannerModel::computeGap($shiftedRow['due_date'] ?? null, $leaderRow['due_date'] ?? null)
                                );
                            }
                        }
                    }
                    $task = PlannerModel::findTask($taskId) ?? $task;
                }
            }

            // Awal rentang (resize tepi kiri): digeser setelah dueDate
            // supaya validasi start <= due memakai due yang baru.
            if (array_key_exists('startDate', $body)) {
                if (!$canMove) {
                    Response::json(null, 403, 'Only the assignee, the owner, or a PM can reschedule this task', false);
                }
                $newStart = self::validDueDate($body['startDate']);
                $effectiveDue = $task['due_date'] ?? null;
                if ($newStart !== null && $effectiveDue === null) {
                    Response::json(null, 400, 'startDate needs a dueDate', false);
                }
                if ($newStart !== null && $effectiveDue !== null && $newStart > $effectiveDue) {
                    Response::json(null, 400, 'startDate must not be after dueDate', false);
                }
                PlannerModel::setStartDate($taskId, $newStart);
                $task = PlannerModel::findTask($taskId) ?? $task;
            }

            // Gap task ini sendiri dihitung ulang dari leader-nya yang
            // sekarang (setelah relasi/tanggal berubah).
            if (($task['follows_task_id'] ?? null) !== null) {
                $leaderRow = PlannerModel::findTask((string) $task['follows_task_id']);
                $gap = PlannerModel::computeGap($task['due_date'] ?? null, $leaderRow['due_date'] ?? null);
                if ($gap !== ($task['gap_days'] !== null ? (int) $task['gap_days'] : null)) {
                    PlannerModel::setGapDays($taskId, $gap);
                    $task = PlannerModel::findTask($taskId) ?? $task;
                }
            }

            $presented = self::presentTask($task);
            self::publishTask($access, $presented);
            foreach ($shiftedIds as $shiftedId) {
                $shiftedRow = PlannerModel::findTask($shiftedId);
                if ($shiftedRow !== null) {
                    self::publishTask($access, self::presentTask($shiftedRow));
                }
            }
            Response::json(['task' => $presented, 'shiftedTaskIds' => $shiftedIds], 200, 'Task updated');
        } catch (Throwable) {
            Response::json(null, 500, 'Internal server error', false);
        }
    }

    public static function deleteTask(array $params = []): void
    {
        try {
            $userId = self::requireUser();
            $access = self::resolveAccess((string) ($params['id'] ?? ''), $userId);
            self::requireEnabled($access);
            $task = self::resolveTask($access, (string) ($params['taskId'] ?? ''));
            if ((string) $task['created_by'] !== $userId && !$access['isOwner']) {
                Response::json(null, 403, 'Only the task creator or the owner can delete this task', false);
            }
            PlannerModel::softDelete((string) $task['id']);
            self::publishTaskDeleted($access, (string) $task['id']);
            Response::json(['id' => (string) $task['id']], 200, 'Task deleted');
        } catch (Throwable) {
            Response::json(null, 500, 'Internal server error', false);
        }
    }

    // ── PUT /api/collab/:id/planner/job-roles ──────────────────────
    public static function setJobRole(array $params = []): void
    {
        try {
            $userId = self::requireUser();
            $access = self::resolveAccess((string) ($params['id'] ?? ''), $userId);
            self::requireEnabled($access);
            $collabId = (string) $access['collab']['id'];
            $body = $GLOBALS['body'] ?? [];
            $targetId = trim((string) ($body['userId'] ?? ''));
            if ($targetId === '') {
                Response::json(null, 400, 'userId is required', false);
            }
            self::requireMember($access, $targetId);
            if ($targetId !== $userId && !self::canManage($access)) {
                Response::json(null, 403, 'Only the owner or a PM can set another member job role', false);
            }
            $jobRole = trim((string) ($body['jobRole'] ?? ''));
            if (mb_strlen($jobRole) > 120) {
                Response::json(null, 400, 'Job role is too long (max 120 characters)', false);
            }
            if (strtolower($jobRole) === 'owner') {
                Response::json(null, 400, '"Owner" is a board role, not a job role', false);
            }
            PlannerModel::setJobRole($collabId, $targetId, $jobRole === '' ? null : $jobRole);
            Response::json(['userId' => $targetId, 'jobRole' => $jobRole === '' ? null : $jobRole], 200, 'Job role updated');
        } catch (Throwable) {
            Response::json(null, 500, 'Internal server error', false);
        }
    }

    // ── GET /api/me/planners ─────────────────────────────────────────
    public static function myPlanners(array $params = []): void
    {
        try {
            $userId = self::requireUser();
            $rows = PlannerModel::myPlanners($userId);
            Response::json(array_map(
                static fn (array $r): array => [
                    'collabId' => (string) $r['id'],
                    'title' => (string) $r['title'],
                    'openCount' => (int) ($r['open_count'] ?? 0),
                ],
                $rows
            ));
        } catch (Throwable) {
            Response::json(null, 500, 'Internal server error', false);
        }
    }

    // ── GET /api/me/tasks ─────────────────────────────────────────────
    public static function myTasks(array $params = []): void
    {
        try {
            $userId = self::requireUser();
            $rows = PlannerModel::myTasks($userId);

            $jakarta = new DateTimeZone('Asia/Jakarta');
            $today = new DateTimeImmutable('now', $jakarta);
            $todayStr = $today->format('Y-m-d');
            $weekStart = $today->modify('-' . ((int) $today->format('N') - 1) . ' days')->format('Y-m-d');
            $weekEnd = $today->modify('+' . (7 - (int) $today->format('N')) . ' days')->format('Y-m-d');

            $open = 0;
            $overdue = 0;
            $doneThisWeek = 0;
            $tasks = [];
            foreach ($rows as $row) {
                $tasks[] = self::presentTask($row, true);
                $doneAt = $row['done_at'] ?? null;
                $due = $row['due_date'] ?? null;
                if ($doneAt === null) {
                    $open++;
                    if ($due !== null && $due < $todayStr) {
                        $overdue++;
                    }
                } else {
                    $doneDate = substr((string) $doneAt, 0, 10);
                    if ($doneDate >= $weekStart && $doneDate <= $weekEnd) {
                        $doneThisWeek++;
                    }
                }
            }

            Response::json([
                'tasks' => $tasks,
                'counts' => ['open' => $open, 'overdue' => $overdue, 'doneThisWeek' => $doneThisWeek],
                'today' => $todayStr,
            ]);
        } catch (Throwable) {
            Response::json(null, 500, 'Internal server error', false);
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────

    private static function requireUser(): string
    {
        $userId = $GLOBALS['user']['id'] ?? null;
        if (!$userId) {
            Response::json(null, 401, 'Unauthorized', false);
        }
        return (string) $userId;
    }

    /**
     * Collab harus ada dan user harus owner atau accepted member.
     *
     * @return array{collab: array<string, mixed>, isOwner: bool, role: string}
     */
    private static function resolveAccess(string $collabId, string $userId): array
    {
        $collab = $collabId !== '' ? CollabModel::findById($collabId) : null;
        if ($collab === null || ($collab['deleted_at'] ?? null) !== null) {
            Response::json(null, 404, 'Project not found', false);
        }
        // findById mengembalikan array kurasi tanpa planner_enabled;
        // status planner selalu dibaca segar dari tabelnya.
        $collab['planner_enabled'] = PlannerModel::isEnabled((string) $collab['id']) ? 1 : 0;
        $isOwner = (string) ($collab['user_id'] ?? '') === $userId;
        if (!$isOwner && !CollabModel::isMember((string) $collab['id'], $userId)) {
            Response::json(null, 403, 'You are not a member of this project', false);
        }
        $role = 'member';
        if ($isOwner) {
            $role = 'owner';
        } else {
            $rolesMap = PlannerModel::rolesMap((string) $collab['id']);
            if (($rolesMap[$userId] ?? null) === 'pm') {
                $role = 'pm';
            }
        }
        return ['collab' => $collab, 'isOwner' => $isOwner, 'role' => $role];
    }

    private static function requireEnabled(array $access): void
    {
        if ((int) ($access['collab']['planner_enabled'] ?? 0) !== 1) {
            Response::json(null, 409, 'This project does not have a planner yet', false);
        }
    }

    /** Owner atau PM: pihak yang boleh mengatur task orang lain. */
    private static function canManage(array $access): bool
    {
        return $access['isOwner'] || $access['role'] === 'pm';
    }

    private static function requireMember(array $access, string $targetId): void
    {
        if ($targetId === (string) $access['collab']['user_id']) {
            return;
        }
        if (!CollabModel::isMember((string) $access['collab']['id'], $targetId)) {
            Response::json(null, 400, 'That user is not a member of this project', false);
        }
    }

    private static function resolveSection(array $access, string $sectionId): array
    {
        $section = $sectionId !== '' ? PlannerModel::findSection($sectionId) : null;
        if ($section === null || (string) $section['collab_id'] !== (string) $access['collab']['id']) {
            Response::json(null, 404, 'Section not found', false);
        }
        return $section;
    }

    private static function resolveTask(array $access, string $taskId): array
    {
        $task = $taskId !== '' ? PlannerModel::findTask($taskId) : null;
        if ($task === null || (string) $task['collab_id'] !== (string) $access['collab']['id']) {
            Response::json(null, 404, 'Task not found', false);
        }
        return $task;
    }

    private static function nullableId(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    /** Icon Boxicons (mis. "edit", "code-alt"): huruf, angka, strip; kosong = null. */
    private static function validIcon(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > 32 || !preg_match('/^[a-z0-9][a-z0-9\\-]*$/i', $value)) {
            Response::json(null, 400, 'icon must be a short name (letters, digits, hyphen)', false);
        }
        return $value;
    }

    /** BlockBody blocks: array (atau null); dikembalikan sebagai string JSON, maks 64 KB. */
    private static function validBlocks(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_array($value)) {
            Response::json(null, 400, 'blocks must be an array', false);
        }
        foreach ($value as $block) {
            if (!is_array($block) || !isset($block['type']) || !is_string($block['type'])) {
                Response::json(null, 400, 'each block needs a type', false);
            }
        }
        $json = json_encode(array_values($value));
        if ($json === false || strlen($json) > 65536) {
            Response::json(null, 400, 'blocks are too large (max 64 KB)', false);
        }
        return $json;
    }

    /** blocks_json dari DB kembali jadi array (atau null bila kosong/rusak). */
    private static function decodeBlocks(mixed $raw): ?array
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_array($raw)) {
            return $raw;
        }
        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /** Null bila kosong; string Y-m-d yang valid; selain itu 400. */
    private static function validDueDate(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        $value = trim((string) $value);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            Response::json(null, 400, 'dueDate must use the YYYY-MM-DD format', false);
        }
        [$y, $m, $d] = array_map('intval', explode('-', $value));
        if (!checkdate($m, $d, $y)) {
            Response::json(null, 400, 'dueDate is not a real date', false);
        }
        return $value;
    }

    private static function publishTask(array $access, array $presented): void
    {
        $collabId = (string) $access['collab']['id'];
        foreach (PlannerModel::members($collabId, (string) $access['collab']['user_id']) as $member) {
            Realtime::publishPlannerTask((string) $member['id'], $collabId, $presented);
        }
    }

    private static function publishTaskDeleted(array $access, string $taskId): void
    {
        $collabId = (string) $access['collab']['id'];
        foreach (PlannerModel::members($collabId, (string) $access['collab']['user_id']) as $member) {
            Realtime::publishPlannerTaskDeleted((string) $member['id'], $collabId, $taskId);
        }
    }

    // ── Presenters ────────────────────────────────────────────────────

    private static function presentMember(array $row, array $access, array $rolesMap): array
    {
        $id = (string) $row['id'];
        $role = 'member';
        if ($id === (string) $access['collab']['user_id']) {
            $role = 'owner';
        } elseif (($rolesMap[$id] ?? null) === 'pm') {
            $role = 'pm';
        }
        $name = trim((string) ($row['full_name'] ?? ''));
        return [
            'id' => $id,
            'username' => (string) ($row['username'] ?? ''),
            'name' => $name !== '' ? $name : (string) ($row['username'] ?? ''),
            'photo' => $row['photo_profile_url'] ?? null,
            'jobRole' => $row['job_role'] ?? null,
            'role' => $role,
        ];
    }

    private static function presentSection(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'title' => (string) $row['title'],
            'position' => (int) ($row['position'] ?? 0),
        ];
    }

    private static function presentTask(array $row, bool $withCollab = false): array
    {
        $task = [
            'id' => (string) $row['id'],
            'collabId' => (string) $row['collab_id'],
            'sectionId' => $row['section_id'] ?? null,
            'title' => (string) $row['title'],
            'assignee' => ($row['assignee_id'] ?? null) !== null ? [
                'id' => (string) $row['assignee_id'],
                'username' => (string) ($row['assignee_username'] ?? ''),
                'name' => trim((string) ($row['assignee_name'] ?? '')) !== ''
                    ? (string) $row['assignee_name']
                    : (string) ($row['assignee_username'] ?? ''),
                'photo' => $row['assignee_photo'] ?? null,
            ] : null,
            'createdBy' => [
                'id' => (string) $row['created_by'],
                'username' => (string) ($row['creator_username'] ?? ''),
            ],
            'dueDate' => $row['due_date'] ?? null,
            'startDate' => $row['start_date'] ?? null,
            'icon' => $row['icon'] ?? null,
            'blocks' => self::decodeBlocks($row['blocks_json'] ?? null),
            'pencilled' => (int) ($row['pencilled'] ?? 0) === 1,
            'follows' => ($row['follows_task_id'] ?? null) !== null ? [
                'id' => (string) $row['follows_task_id'],
                'title' => (string) ($row['follows_title'] ?? ''),
            ] : null,
            'gapDays' => ($row['gap_days'] ?? null) !== null ? (int) $row['gap_days'] : null,
            'done' => ($row['done_at'] ?? null) !== null,
            'doneAt' => $row['done_at'] ?? null,
            'createdAt' => $row['timestamp'] ?? null,
        ];
        if ($withCollab) {
            $task['collabTitle'] = (string) ($row['collab_title'] ?? '');
        }
        return $task;
    }
}
