<?php

/**
 * models/ModerationService.php
 * ─────────────────────────────────────────────────────────────────────────────
 * EKSEKUTOR MODERASI (spec-admin.md §4 + §8)
 *
 * Satu-satunya jalur eksekusi aksi moderasi: aksi langsung Super
 * Admin dan approval pengajuan Monitor sama-sama lewat execute().
 * Urutan selalu: ubah status target -> tulis audit
 * (moderation_actions) -> tutup report terkait -> kirim
 * pemberitahuan inbox satu arah untuk penghapusan konten.
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

require_once BASE_PATH . '/models/InboxModel.php';
require_once BASE_PATH . '/utils/Realtime.php';

class ModerationService
{
    public const SYSTEM_USER_ID = '00000000-0000-4000-8000-0000000000ad';

    private const CONTENT_TABLES = [
        'post' => 'public_posts',
        'comment' => 'comments',
        'collab' => 'collaborations',
    ];

    public const REMOVE_ACTIONS = [
        'remove_post' => 'post',
        'remove_comment' => 'comment',
        'remove_collab' => 'collab',
    ];

    /** Jenis aksi yang bisa dibanding (spec §4.4). */
    public const APPEALABLE = ['remove_post', 'remove_comment', 'remove_collab', 'suspend_user'];

    /** Satu baris moderation_actions berdasarkan id. */
    public static function actionById(string $id): ?array
    {
        $stmt = getDB()->prepare("SELECT * FROM moderation_actions WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function uuid(): string
    {
        return InboxModel::generateUuid();
    }

    /** Info target untuk validasi + label. Null kalau tidak ditemukan. */
    public static function targetInfo(string $targetType, string $targetId): ?array
    {
        $pdo = getDB();
        if ($targetType === 'user') {
            $stmt = $pdo->prepare("SELECT id, username, role, account_status FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$targetId]);
            $row = $stmt->fetch();
            if (!$row) {
                return null;
            }
            return [
                'owner_id' => $row['id'],
                'label' => '@' . $row['username'],
                'role' => $row['role'],
                'account_status' => $row['account_status'],
                'state' => null,
                'owner_deleted' => false,
            ];
        }
        if ($targetType === 'report') {
            $stmt = $pdo->prepare("SELECT id, target_type, target_id, status FROM reports WHERE id = ? LIMIT 1");
            $stmt->execute([$targetId]);
            $row = $stmt->fetch();
            if (!$row) {
                return null;
            }
            $inner = self::targetInfo((string) $row['target_type'], (string) $row['target_id']);
            return [
                'owner_id' => $inner['owner_id'] ?? null,
                'label' => 'Report pada ' . ($inner['label'] ?? $row['target_type']),
                'state' => $row['status'],
                'owner_deleted' => false,
            ];
        }
        $table = self::CONTENT_TABLES[$targetType] ?? null;
        if ($table === null) {
            return null;
        }
        $textCol = $targetType === 'collab' ? 'title' : ($targetType === 'post' ? 'description' : 'content');
        $stmt = $pdo->prepare("SELECT user_id, $textCol AS text, moderation_state, deleted_at FROM $table WHERE id = ? LIMIT 1");
        $stmt->execute([$targetId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $text = trim((string) $row['text']);
        return [
            'owner_id' => $row['user_id'],
            'label' => mb_strlen($text) > 80 ? mb_substr($text, 0, 80) . '...' : $text,
            'state' => $row['moderation_state'],
            'owner_deleted' => $row['deleted_at'] !== null,
        ];
    }

    /** Jumlah strike aktif seorang user (derived, spec §5). */
    public static function strikeCount(string $userId): int
    {
        $stmt = getDB()->prepare(
            "SELECT COUNT(*) FROM moderation_actions WHERE target_owner_id = ? AND strike = 1 AND reverted_at IS NULL"
        );
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Validasi pengajuan (dipakai sebelum action_requests dibuat).
     * Mengembalikan pesan error siap tampil, atau null kalau sah.
     */
    public static function proposalError(string $actionType, string $targetType, string $targetId, string $actorId): ?string
    {
        if ($actionType === 'dismiss_report') {
            if ($targetType !== 'report') {
                return 'Target dismiss harus berupa report.';
            }
            $stmt = getDB()->prepare("SELECT status FROM reports WHERE id = ? LIMIT 1");
            $stmt->execute([$targetId]);
            $status = $stmt->fetchColumn();
            if ($status === false) {
                return 'Report tidak ditemukan.';
            }
            if ($status !== 'open') {
                return 'Report ini sudah ditutup.';
            }
            return null;
        }
        $info = self::targetInfo($targetType, $targetId);
        if ($info === null) {
            return 'Target tidak ditemukan.';
        }
        if (isset(self::REMOVE_ACTIONS[$actionType])) {
            if (self::REMOVE_ACTIONS[$actionType] !== $targetType) {
                return 'Jenis aksi tidak cocok dengan target.';
            }
            if ($info['owner_deleted']) {
                return 'Konten ini sudah dihapus oleh pemiliknya.';
            }
            if ($info['state'] !== 'visible') {
                return 'Konten ini sudah di-remove.';
            }
            return null;
        }
        if ($actionType === 'suspend_user') {
            if ($targetType !== 'user') {
                return 'Target suspend harus berupa user.';
            }
            if (($info['role'] ?? '') === 'super_admin') {
                return 'Super Admin tidak bisa di-suspend.';
            }
            if ($targetId === $actorId) {
                return 'Tidak bisa men-suspend akun sendiri.';
            }
            if (($info['account_status'] ?? '') === 'suspended') {
                return 'Akun ini sudah di-suspend.';
            }
            return null;
        }
        return 'Jenis aksi tidak dikenal.';
    }

    /**
     * Eksekusi satu aksi moderasi.
     *
     * @param array $spec action_type, target_type, target_id, reason, with_strike
     * @param array $actor baris user pelaku (id, username, role)
     * @throws InvalidArgumentException pesan siap tampil saat guard gagal
     * @return array{action_id: string, owner_id: ?string}
     */
    public static function execute(array $spec, array $actor, ?string $requestedBy = null, ?string $requestId = null): array
    {
        $pdo = getDB();
        $actionType = (string) ($spec['action_type'] ?? '');
        $targetType = (string) ($spec['target_type'] ?? '');
        $targetId = (string) ($spec['target_id'] ?? '');
        $reason = trim((string) ($spec['reason'] ?? ''));
        $withStrike = !empty($spec['with_strike']);
        $actorId = (string) $actor['id'];

        if ($actionType === 'dismiss_report') {
            return self::executeDismiss($pdo, $targetId, $reason, $actorId, $requestedBy, $requestId);
        }

        $info = self::targetInfo($targetType, $targetId);
        if ($info === null) {
            throw new InvalidArgumentException('Target tidak ditemukan.');
        }
        $ownerId = $info['owner_id'];

        // ── Hapus konten ──────────────────────────────────────────────
        if (isset(self::REMOVE_ACTIONS[$actionType])) {
            if (self::REMOVE_ACTIONS[$actionType] !== $targetType) {
                throw new InvalidArgumentException('Jenis aksi tidak cocok dengan target.');
            }
            if ($info['owner_deleted']) {
                throw new InvalidArgumentException('Konten ini sudah dihapus oleh pemiliknya.');
            }
            if ($info['state'] !== 'visible') {
                throw new InvalidArgumentException('Konten ini sudah di-remove.');
            }
            $table = self::CONTENT_TABLES[$targetType];
            $pdo->prepare(
                "UPDATE $table SET moderation_state = 'removed', removed_by = ?, removed_at = NOW(), removal_reason = ? WHERE id = ?"
            )->execute([$actorId, $reason, $targetId]);

            $actionId = self::writeAction($pdo, [
                'action_type' => $actionType,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'target_owner_id' => $ownerId,
                'actor_id' => $actorId,
                'requested_by' => $requestedBy,
                'request_id' => $requestId,
                'reason' => $reason,
                'strike' => $withStrike ? 1 : 0,
            ]);

            $pdo->prepare(
                "UPDATE reports SET status = 'actioned', resolved_by = ?, resolved_at = NOW() WHERE target_type = ? AND target_id = ? AND status = 'open'"
            )->execute([$actorId, $targetType, $targetId]);

            $strikeNo = $withStrike ? self::strikeCount((string) $ownerId) : null;
            self::sendRemovalNotice((string) $ownerId, $actionId, $targetType, (string) $info['label'], $reason, $strikeNo);
            return ['action_id' => $actionId, 'owner_id' => $ownerId];
        }

        // ── Pulihkan konten ───────────────────────────────────────────
        if ($actionType === 'restore_content') {
            $table = self::CONTENT_TABLES[$targetType] ?? null;
            if ($table === null) {
                throw new InvalidArgumentException('Restore hanya untuk konten.');
            }
            if ($info['owner_deleted']) {
                throw new InvalidArgumentException('Konten ini sudah dihapus oleh pemiliknya.');
            }
            if ($info['state'] !== 'removed') {
                throw new InvalidArgumentException('Konten ini tidak sedang di-remove.');
            }
            $pdo->prepare(
                "UPDATE $table SET moderation_state = 'visible', removed_by = NULL, removed_at = NULL, removal_reason = NULL WHERE id = ?"
            )->execute([$targetId]);
            self::markReverted($pdo, $targetType, $targetId, ['remove_post', 'remove_comment', 'remove_collab'], $actorId);
            $actionId = self::writeAction($pdo, [
                'action_type' => 'restore_content',
                'target_type' => $targetType,
                'target_id' => $targetId,
                'target_owner_id' => $ownerId,
                'actor_id' => $actorId,
                'requested_by' => $requestedBy,
                'request_id' => $requestId,
                'reason' => $reason !== '' ? $reason : 'Konten dipulihkan oleh admin.',
                'strike' => 0,
            ]);
            return ['action_id' => $actionId, 'owner_id' => $ownerId];
        }

        // ── Suspend akun ──────────────────────────────────────────────
        if ($actionType === 'suspend_user') {
            if ($targetType !== 'user') {
                throw new InvalidArgumentException('Target suspend harus berupa user.');
            }
            if (($info['role'] ?? '') === 'super_admin') {
                throw new InvalidArgumentException('Super Admin tidak bisa di-suspend.');
            }
            if ($targetId === $actorId) {
                throw new InvalidArgumentException('Tidak bisa men-suspend akun sendiri.');
            }
            if (($info['account_status'] ?? '') === 'suspended') {
                throw new InvalidArgumentException('Akun ini sudah di-suspend.');
            }
            $pdo->prepare(
                "UPDATE users SET account_status = 'suspended', suspended_reason = ?, suspended_at = NOW(), suspended_by = ? WHERE id = ?"
            )->execute([$reason, $actorId, $targetId]);
            $actionId = self::writeAction($pdo, [
                'action_type' => 'suspend_user',
                'target_type' => 'user',
                'target_id' => $targetId,
                'target_owner_id' => $ownerId,
                'actor_id' => $actorId,
                'requested_by' => $requestedBy,
                'request_id' => $requestId,
                'reason' => $reason,
                'strike' => 0,
            ]);
            $pdo->prepare(
                "UPDATE reports SET status = 'actioned', resolved_by = ?, resolved_at = NOW() WHERE target_type = 'user' AND target_id = ? AND status = 'open'"
            )->execute([$actorId, $targetId]);
            return ['action_id' => $actionId, 'owner_id' => $ownerId];
        }

        // ── Unsuspend akun ────────────────────────────────────────────
        if ($actionType === 'unsuspend_user') {
            if ($targetType !== 'user') {
                throw new InvalidArgumentException('Target unsuspend harus berupa user.');
            }
            if (($info['account_status'] ?? '') !== 'suspended') {
                throw new InvalidArgumentException('Akun ini tidak sedang di-suspend.');
            }
            $pdo->prepare(
                "UPDATE users SET account_status = 'active', suspended_reason = NULL, suspended_at = NULL, suspended_by = NULL WHERE id = ?"
            )->execute([$targetId]);
            self::markReverted($pdo, 'user', $targetId, ['suspend_user'], $actorId);
            $actionId = self::writeAction($pdo, [
                'action_type' => 'unsuspend_user',
                'target_type' => 'user',
                'target_id' => $targetId,
                'target_owner_id' => $ownerId,
                'actor_id' => $actorId,
                'requested_by' => $requestedBy,
                'request_id' => $requestId,
                'reason' => $reason !== '' ? $reason : 'Akun diaktifkan kembali oleh admin.',
                'strike' => 0,
            ]);
            return ['action_id' => $actionId, 'owner_id' => $ownerId];
        }

        throw new InvalidArgumentException('Jenis aksi tidak dikenal.');
    }

    private static function executeDismiss(PDO $pdo, string $reportId, string $reason, string $actorId, ?string $requestedBy, ?string $requestId): array
    {
        $stmt = $pdo->prepare("SELECT target_type, target_id, status FROM reports WHERE id = ? LIMIT 1");
        $stmt->execute([$reportId]);
        $report = $stmt->fetch();
        if (!$report) {
            throw new InvalidArgumentException('Report tidak ditemukan.');
        }
        if ($report['status'] !== 'open') {
            throw new InvalidArgumentException('Report ini sudah ditutup.');
        }
        $pdo->prepare(
            "UPDATE reports SET status = 'dismissed', resolved_by = ?, resolved_at = NOW() WHERE id = ?"
        )->execute([$actorId, $reportId]);
        $inner = self::targetInfo((string) $report['target_type'], (string) $report['target_id']);
        $actionId = self::writeAction($pdo, [
            'action_type' => 'dismiss_report',
            'target_type' => 'report',
            'target_id' => $reportId,
            'target_owner_id' => $inner['owner_id'] ?? null,
            'actor_id' => $actorId,
            'requested_by' => $requestedBy,
            'request_id' => $requestId,
            'reason' => $reason !== '' ? $reason : 'Report ditutup: tidak terbukti melanggar.',
            'strike' => 0,
        ]);
        return ['action_id' => $actionId, 'owner_id' => $inner['owner_id'] ?? null];
    }

    /**
     * Putusan banding (spec §4.5). granted membalikkan aksi asal lewat
     * jalur yang sama seperti restore/unsuspend manual dan menulis
     * audit grant_appeal; denied menulis audit deny_appeal. Keduanya
     * mengirim pesan hasil ke thread sistem user.
     */
    public static function applyAppealDecision(array $appeal, array $original, string $decision, array $decider, string $note): void
    {
        $pdo = getDB();
        $deciderId = (string) $decider['id'];
        $ownerId = (string) $appeal['user_id'];
        $type = (string) $original['action_type'];
        $targetType = (string) $original['target_type'];
        $targetId = (string) $original['target_id'];
        $kindLabel = ['post' => 'post', 'comment' => 'comment', 'collab' => 'collaboration'][$targetType] ?? 'content';

        if ($decision === 'granted') {
            if (isset(self::REMOVE_ACTIONS[$type])) {
                $table = self::CONTENT_TABLES[$targetType];
                $pdo->prepare(
                    "UPDATE $table SET moderation_state = 'visible', removed_by = NULL, removed_at = NULL, removal_reason = NULL WHERE id = ?"
                )->execute([$targetId]);
            } elseif ($type === 'suspend_user') {
                $pdo->prepare(
                    "UPDATE users SET account_status = 'active', suspended_reason = NULL, suspended_at = NULL, suspended_by = NULL WHERE id = ?"
                )->execute([$targetId]);
            }
            self::markRevertedById($pdo, (string) $original['id'], $deciderId);
            self::writeAction($pdo, [
                'action_type' => 'grant_appeal',
                'target_type' => $targetType,
                'target_id' => $targetId,
                'target_owner_id' => $ownerId,
                'actor_id' => $deciderId,
                'requested_by' => null,
                'request_id' => null,
                'reason' => $note !== '' ? $note : 'Banding diterima.',
                'strike' => 0,
            ]);
            $lines = $type === 'suspend_user'
                ? ['Your appeal was accepted. Your account has been reactivated and you can log in again.']
                : ["Your appeal was accepted. Your $kindLabel has been restored."];
            if ($note !== '') {
                $lines[] = '';
                $lines[] = 'Note from the moderation team: ' . $note;
            }
            self::sendSystemNotice($ownerId, implode("\n", $lines));
            return;
        }

        self::writeAction($pdo, [
            'action_type' => 'deny_appeal',
            'target_type' => $targetType,
            'target_id' => $targetId,
            'target_owner_id' => $ownerId,
            'actor_id' => $deciderId,
            'requested_by' => null,
            'request_id' => null,
            'reason' => $note,
            'strike' => 0,
        ]);
        $lines = ['Your appeal was reviewed and denied.'];
        $lines[] = 'Decision note: ' . $note;
        if ($type === 'suspend_user') {
            $lines[] = '';
            $lines[] = 'Your account remains suspended.';
        }
        self::sendSystemNotice($ownerId, implode("\n", $lines));
    }

    private static function markRevertedById(PDO $pdo, string $actionId, string $actorId): void
    {
        $pdo->prepare("UPDATE moderation_actions SET reverted_at = NOW(), reverted_by = ? WHERE id = ? AND reverted_at IS NULL")
            ->execute([$actorId, $actionId]);
    }

    private static function markReverted(PDO $pdo, string $targetType, string $targetId, array $actionTypes, string $actorId): void
    {
        $in = implode(',', array_fill(0, count($actionTypes), '?'));
        $stmt = $pdo->prepare(
            "SELECT id FROM moderation_actions WHERE target_type = ? AND target_id = ? AND action_type IN ($in) AND reverted_at IS NULL ORDER BY created_at DESC LIMIT 1"
        );
        $stmt->execute(array_merge([$targetType, $targetId], $actionTypes));
        $origId = $stmt->fetchColumn();
        if ($origId !== false) {
            $pdo->prepare("UPDATE moderation_actions SET reverted_at = NOW(), reverted_by = ? WHERE id = ?")
                ->execute([$actorId, $origId]);
        }
    }

    private static function writeAction(PDO $pdo, array $row): string
    {
        $id = self::uuid();
        $stmt = $pdo->prepare(
            "INSERT INTO moderation_actions
                (id, action_type, target_type, target_id, target_owner_id, actor_id, requested_by, request_id, reason, strike)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $id,
            $row['action_type'],
            $row['target_type'],
            $row['target_id'],
            $row['target_owner_id'],
            $row['actor_id'],
            $row['requested_by'],
            $row['request_id'],
            $row['reason'],
            $row['strike'],
        ]);
        return $id;
    }

    /** Thread sistem user: satu per user, dibuat saat notice pertama. */
    public static function ensureSystemThread(PDO $pdo, string $userId): string
    {
        $stmt = $pdo->prepare(
            "SELECT c.id FROM dm_conversations c
             JOIN dm_participants p ON p.conversation_id = c.id AND p.user_id = ?
             WHERE c.kind = 'system' LIMIT 1"
        );
        $stmt->execute([$userId]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (string) $id;
        }
        $id = self::uuid();
        $pdo->prepare("INSERT INTO dm_conversations (id, application_id, kind) VALUES (?, NULL, 'system')")
            ->execute([$id]);
        $pStmt = $pdo->prepare("INSERT INTO dm_participants (id, conversation_id, user_id) VALUES (?, ?, ?)");
        $pStmt->execute([self::uuid(), $id, self::SYSTEM_USER_ID]);
        $pStmt->execute([self::uuid(), $id, $userId]);
        return $id;
    }

    private static function sendRemovalNotice(string $ownerId, string $actionId, string $targetType, string $label, string $reason, ?int $strikeNo): void
    {
        $kindLabel = ['post' => 'post', 'comment' => 'comment', 'collab' => 'collaboration'][$targetType] ?? 'content';
        $lines = [];
        $lines[] = "Your $kindLabel was removed by the Underflow moderation team.";
        $lines[] = '';
        $lines[] = '"' . $label . '"';
        $lines[] = 'Reason: ' . $reason;
        if ($strikeNo !== null) {
            $lines[] = '';
            $lines[] = "This counts as strike $strikeNo on your account. Repeated violations may lead to a suspension.";
        }
        self::insertSystemMessage($ownerId, implode("\n", $lines), $actionId);
    }

    /** Pesan sistem satu arah ke user (hasil banding, dsb.). */
    public static function sendSystemNotice(string $ownerId, string $body): void
    {
        self::insertSystemMessage($ownerId, $body, null);
    }

    private static function insertSystemMessage(string $ownerId, string $body, ?string $actionId): void
    {
        try {
            $pdo = getDB();
            $convId = self::ensureSystemThread($pdo, $ownerId);

            $id = self::uuid();
            $pdo->prepare(
                "INSERT INTO dm_messages (id, conversation_id, sender_id, body, moderation_action_id) VALUES (?, ?, ?, ?, ?)"
            )->execute([$id, $convId, self::SYSTEM_USER_ID, $body, $actionId]);
            $pdo->prepare("UPDATE dm_conversations SET updated_at = CURRENT_TIMESTAMP WHERE id = ?")
                ->execute([$convId]);

            $stmt = $pdo->prepare("SELECT id, sender_id, body, is_read, created_at, moderation_action_id FROM dm_messages WHERE id = ?");
            $stmt->execute([$id]);
            $message = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($message) {
                Realtime::publishInboxMessage($ownerId, $convId, [
                    'id' => $message['id'],
                    'sender_id' => $message['sender_id'],
                    'body' => $message['body'],
                    'is_read' => (bool) $message['is_read'],
                    'created_at' => $message['created_at'],
                    'moderation_action_id' => $message['moderation_action_id'],
                ]);
            }
        } catch (Throwable $e) {
            // Notice gagal tidak boleh menggagalkan aksi moderasinya.
            error_log('[MODERATION NOTICE ERROR] ' . $e->getMessage());
        }
    }
}
