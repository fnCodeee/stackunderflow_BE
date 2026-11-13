<?php

/**
 * controllers/AdminModerationController.php
 * ─────────────────────────────────────────────────────────────────────────────
 * AKSI MODERASI (spec-admin.md §8, Fase 2)
 *
 * - POST /api/admin/actions                  eksekusi langsung (Super Admin)
 * - POST /api/admin/action-requests          pengajuan (Monitor)
 * - GET  /api/admin/action-requests          antrean + riwayat
 * - POST /api/admin/action-requests/:id/decide  approve/reject (Super Admin)
 * - GET  /api/admin/reports                  antrean report per target
 * - POST /api/admin/reports/:id/dismiss      tutup report (Super Admin)
 *
 * Semua eksekusi lewat ModerationService::execute — tidak ada
 * jalur kedua.
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

require_once BASE_PATH . '/models/ModerationService.php';
require_once BASE_PATH . '/models/NotificationModel.php';
require_once BASE_PATH . '/utils/Realtime.php';
require_once BASE_PATH . '/utils/Response.php';

class AdminModerationController
{
    private const PROPOSABLE = [
        'remove_post' => 'post',
        'remove_comment' => 'comment',
        'remove_collab' => 'collab',
        'suspend_user' => 'user',
        'dismiss_report' => 'report',
    ];

    private const DIRECT = [
        'remove_post' => 'post',
        'remove_comment' => 'comment',
        'remove_collab' => 'collab',
        'suspend_user' => 'user',
        'unsuspend_user' => 'user',
    ];

    private const ACTION_LABEL = [
        'remove_post' => 'hapus post',
        'remove_comment' => 'hapus komentar',
        'remove_collab' => 'hapus collaboration',
        'suspend_user' => 'suspend akun',
        'dismiss_report' => 'tutup report',
    ];

    private static function body(): array
    {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw ?: '', true);
        return is_array($data) ? $data : [];
    }

    private static function actor(): array
    {
        return $GLOBALS['user'] ?? [];
    }

    private static function notify(string $recipientId, string $actorId, string $type, string $title, string $targetId): void
    {
        try {
            if ($recipientId === $actorId) {
                return;
            }
            $row = NotificationModel::createAdminNotification($recipientId, $actorId, $type, $title, $targetId);
            if ($row !== null) {
                $apiRow = NotificationModel::findApiRow($row['id']);
                if ($apiRow !== null) {
                    Realtime::publishNotification($recipientId, $apiRow);
                }
            }
        } catch (Throwable $e) {
            error_log('[ADMIN NOTIFY ERROR] ' . $e->getMessage());
        }
    }

    private static function notifySuperAdmins(string $actorId, string $title, string $targetId): void
    {
        $stmt = getDB()->prepare("SELECT id FROM users WHERE role = 'super_admin'");
        $stmt->execute();
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $superId) {
            self::notify((string) $superId, $actorId, 'admin_action_request', $title, $targetId);
        }
    }

    /** POST /api/admin/actions — Super Admin only (route guard). */
    public static function actions(array $params = []): void
    {
        try {
            $actor = self::actor();
            $body = self::body();
            $actionType = (string) ($body['action_type'] ?? '');
            $targetId = (string) ($body['target_id'] ?? '');
            $reason = trim((string) ($body['reason'] ?? ''));

            if ($actionType === 'restore_content') {
                $targetType = (string) ($body['target_type'] ?? '');
                if (!in_array($targetType, ['post', 'comment', 'collab'], true)) {
                    Response::json(null, 400, 'Restore hanya untuk konten.', false);
                }
            } elseif (isset(self::DIRECT[$actionType])) {
                $targetType = self::DIRECT[$actionType];
            } else {
                Response::json(null, 400, 'Jenis aksi tidak dikenal.', false);
            }
            if ($targetId === '') {
                Response::json(null, 400, 'Target wajib diisi.', false);
            }
            $needsReason = isset(ModerationService::REMOVE_ACTIONS[$actionType]) || $actionType === 'suspend_user';
            if ($needsReason && mb_strlen($reason) < 3) {
                Response::json(null, 400, 'Alasan wajib diisi.', false);
            }
            $withStrike = array_key_exists('with_strike', $body)
                ? (bool) $body['with_strike']
                : isset(ModerationService::REMOVE_ACTIONS[$actionType]);

            $result = ModerationService::execute([
                'action_type' => $actionType,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'reason' => $reason,
                'with_strike' => $withStrike,
            ], $actor);
            Response::json($result, 200, 'Aksi dieksekusi.', true);
        } catch (InvalidArgumentException $e) {
            Response::json(null, 400, $e->getMessage(), false);
        } catch (Throwable $e) {
            error_log('[ADMIN ACTIONS ERROR] ' . $e->getMessage());
            Response::json(null, 500, 'Internal Server error: ' . $e->getMessage(), false);
        }
    }

    /** POST /api/admin/action-requests — Monitor only. */
    public static function createRequest(array $params = []): void
    {
        try {
            $actor = self::actor();
            if (($actor['role'] ?? '') !== 'monitor') {
                Response::json(null, 403, 'Hanya Monitor yang membuat pengajuan; Super Admin mengeksekusi langsung.', false);
            }
            $body = self::body();
            $actionType = (string) ($body['action_type'] ?? '');
            $targetId = (string) ($body['target_id'] ?? '');
            $reason = trim((string) ($body['reason'] ?? ''));
            if (!isset(self::PROPOSABLE[$actionType])) {
                Response::json(null, 400, 'Jenis aksi tidak bisa diajukan.', false);
            }
            $targetType = self::PROPOSABLE[$actionType];
            if ($targetId === '' || mb_strlen($reason) < 3) {
                Response::json(null, 400, 'Target dan alasan wajib diisi.', false);
            }
            $error = ModerationService::proposalError($actionType, $targetType, $targetId, (string) $actor['id']);
            if ($error !== null) {
                Response::json(null, 400, $error, false);
            }
            $pdo = getDB();
            $dup = $pdo->prepare(
                "SELECT id FROM action_requests WHERE action_type = ? AND target_id = ? AND status = 'pending' LIMIT 1"
            );
            $dup->execute([$actionType, $targetId]);
            if ($dup->fetchColumn() !== false) {
                Response::json(null, 409, 'Sudah ada pengajuan pending untuk aksi ini.', false);
            }
            $withStrike = array_key_exists('with_strike', $body)
                ? (bool) $body['with_strike']
                : isset(ModerationService::REMOVE_ACTIONS[$actionType]);
            $id = ModerationService::uuid();
            $pdo->prepare(
                "INSERT INTO action_requests (id, requester_id, action_type, target_type, target_id, reason, with_strike)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            )->execute([$id, $actor['id'], $actionType, $targetType, $targetId, $reason, $withStrike ? 1 : 0]);

            $label = self::ACTION_LABEL[$actionType] ?? $actionType;
            self::notifySuperAdmins(
                (string) $actor['id'],
                ($actor['username'] ?? 'Monitor') . ' mengajukan ' . $label,
                $id
            );
            Response::json(['id' => $id], 201, 'Pengajuan terkirim.', true);
        } catch (Throwable $e) {
            error_log('[ADMIN CREATE REQUEST ERROR] ' . $e->getMessage());
            Response::json(null, 500, 'Internal Server error: ' . $e->getMessage(), false);
        }
    }

    /** GET /api/admin/action-requests?status= */
    public static function listRequests(array $params = []): void
    {
        try {
            $actor = self::actor();
            $isSuper = ($actor['role'] ?? '') === 'super_admin';
            $status = trim((string) ($_GET['status'] ?? ''));
            $where = [];
            $args = [];
            if (!$isSuper) {
                $where[] = 'ar.requester_id = ?';
                $args[] = $actor['id'];
            }
            if (in_array($status, ['pending', 'approved', 'rejected'], true)) {
                $where[] = 'ar.status = ?';
                $args[] = $status;
            }
            $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
            $stmt = getDB()->prepare(
                "SELECT ar.*, u.username AS requester_name, d.username AS decider_name
                 FROM action_requests ar
                 JOIN users u ON u.id = ar.requester_id
                 LEFT JOIN users d ON d.id = ar.decided_by
                 $whereSql
                 ORDER BY (ar.status = 'pending') DESC, ar.created_at DESC
                 LIMIT 100"
            );
            $stmt->execute($args);
            $items = [];
            foreach ($stmt->fetchAll() as $row) {
                $info = ModerationService::targetInfo((string) $row['target_type'], (string) $row['target_id']);
                $row['target_label'] = $info['label'] ?? (string) $row['target_id'];
                $row['with_strike'] = (bool) $row['with_strike'];
                $items[] = $row;
            }
            Response::json(['items' => $items], 200, 'OK', true);
        } catch (Throwable $e) {
            error_log('[ADMIN LIST REQUESTS ERROR] ' . $e->getMessage());
            Response::json(null, 500, 'Internal Server error: ' . $e->getMessage(), false);
        }
    }

    /** POST /api/admin/action-requests/:id/decide — Super Admin only. */
    public static function decide(array $params = []): void
    {
        try {
            $actor = self::actor();
            $id = (string) ($params['id'] ?? '');
            $body = self::body();
            $decision = (string) ($body['decision'] ?? '');
            $note = trim((string) ($body['note'] ?? ''));
            if (!in_array($decision, ['approved', 'rejected'], true)) {
                Response::json(null, 400, 'Keputusan tidak dikenal.', false);
            }
            $pdo = getDB();
            $stmt = $pdo->prepare("SELECT * FROM action_requests WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $request = $stmt->fetch();
            if (!$request) {
                Response::json(null, 404, 'Pengajuan tidak ditemukan.', false);
            }
            if ($request['status'] !== 'pending') {
                Response::json(null, 409, 'Pengajuan ini sudah diputuskan.', false);
            }
            if ($decision === 'rejected' && $note === '') {
                Response::json(null, 400, 'Catatan penolakan wajib diisi.', false);
            }

            $label = self::ACTION_LABEL[$request['action_type']] ?? $request['action_type'];
            if ($decision === 'rejected') {
                $pdo->prepare(
                    "UPDATE action_requests SET status = 'rejected', decided_by = ?, decided_at = NOW(), decision_note = ? WHERE id = ?"
                )->execute([$actor['id'], $note, $id]);
                self::notify(
                    (string) $request['requester_id'],
                    (string) $actor['id'],
                    'admin_request_decided',
                    'Pengajuan ' . $label . ' kamu ditolak',
                    $id
                );
                Response::json(['id' => $id, 'status' => 'rejected'], 200, 'Pengajuan ditolak.', true);
            }

            try {
                $result = ModerationService::execute([
                    'action_type' => $request['action_type'],
                    'target_type' => $request['target_type'],
                    'target_id' => $request['target_id'],
                    'reason' => $request['reason'],
                    'with_strike' => (bool) $request['with_strike'],
                ], $actor, (string) $request['requester_id'], $id);
            } catch (InvalidArgumentException $e) {
                Response::json(null, 409, 'Tidak bisa dieksekusi: ' . $e->getMessage(), false);
            }
            $pdo->prepare(
                "UPDATE action_requests SET status = 'approved', decided_by = ?, decided_at = NOW(), decision_note = ?, executed_at = NOW() WHERE id = ?"
            )->execute([$actor['id'], $note !== '' ? $note : null, $id]);
            self::notify(
                (string) $request['requester_id'],
                (string) $actor['id'],
                'admin_request_decided',
                'Pengajuan ' . $label . ' kamu disetujui dan sudah dieksekusi',
                $id
            );
            Response::json(['id' => $id, 'status' => 'approved', 'action_id' => $result['action_id']], 200, 'Pengajuan disetujui dan dieksekusi.', true);
        } catch (Throwable $e) {
            error_log('[ADMIN DECIDE ERROR] ' . $e->getMessage());
            Response::json(null, 500, 'Internal Server error: ' . $e->getMessage(), false);
        }
    }

    /** GET /api/admin/reports — antrean, dikelompokkan per target. */
    public static function reports(array $params = []): void
    {
        try {
            $pdo = getDB();
            $stmt = $pdo->prepare(
                "SELECT target_type, target_id, COUNT(*) AS open_count, MIN(created_at) AS first_at
                 FROM reports WHERE status = 'open'
                 GROUP BY target_type, target_id
                 ORDER BY open_count DESC, first_at ASC"
            );
            $stmt->execute();
            $groups = [];
            $detailStmt = $pdo->prepare(
                "SELECT r.id, r.reason, r.created_at, u.username AS reporter_name
                 FROM reports r JOIN users u ON u.id = r.reporter_id
                 WHERE r.target_type = ? AND r.target_id = ? AND r.status = 'open'
                 ORDER BY r.created_at ASC"
            );
            foreach ($stmt->fetchAll() as $g) {
                $info = ModerationService::targetInfo((string) $g['target_type'], (string) $g['target_id']);
                $detailStmt->execute([$g['target_type'], $g['target_id']]);
                $ownerName = null;
                if (($info['owner_id'] ?? null) !== null) {
                    $uStmt = $pdo->prepare("SELECT username FROM users WHERE id = ? LIMIT 1");
                    $uStmt->execute([$info['owner_id']]);
                    $ownerName = $uStmt->fetchColumn() ?: null;
                }
                $groups[] = [
                    'target_type' => $g['target_type'],
                    'target_id' => $g['target_id'],
                    'open_count' => (int) $g['open_count'],
                    'first_at' => $g['first_at'],
                    'target_label' => $info['label'] ?? (string) $g['target_id'],
                    'target_state' => $info['state'] ?? null,
                    'owner_name' => $ownerName,
                    'reports' => $detailStmt->fetchAll(),
                ];
            }
            Response::json(['groups' => $groups], 200, 'OK', true);
        } catch (Throwable $e) {
            error_log('[ADMIN REPORTS ERROR] ' . $e->getMessage());
            Response::json(null, 500, 'Internal Server error: ' . $e->getMessage(), false);
        }
    }

    /** POST /api/admin/reports/:id/dismiss — Super Admin only. */
    public static function dismissReport(array $params = []): void
    {
        try {
            $actor = self::actor();
            $body = self::body();
            $result = ModerationService::execute([
                'action_type' => 'dismiss_report',
                'target_type' => 'report',
                'target_id' => (string) ($params['id'] ?? ''),
                'reason' => trim((string) ($body['reason'] ?? '')),
                'with_strike' => false,
            ], $actor);
            Response::json($result, 200, 'Report ditutup.', true);
        } catch (InvalidArgumentException $e) {
            Response::json(null, 400, $e->getMessage(), false);
        } catch (Throwable $e) {
            error_log('[ADMIN DISMISS ERROR] ' . $e->getMessage());
            Response::json(null, 500, 'Internal Server error: ' . $e->getMessage(), false);
        }
    }
}
