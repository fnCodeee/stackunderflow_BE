<?php

/**
 * models/AppealModel.php
 * ─────────────────────────────────────────────────────────────────────────────
 * BANDING (spec-admin.md §4.4 + §4.5)
 *
 * Satu banding per tindakan moderasi (UNIQUE moderation_action_id di
 * skema) — putusan bersifat final. Banding mengikat ke baris
 * moderation_actions asal, bukan ke kontennya, supaya riwayatnya utuh.
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

require_once BASE_PATH . '/models/ModerationService.php';

class AppealModel
{
    public static function create(string $userId, string $actionId, string $message): array
    {
        $id = ModerationService::uuid();
        getDB()->prepare(
            "INSERT INTO appeals (id, user_id, moderation_action_id, message) VALUES (?, ?, ?, ?)"
        )->execute([$id, $userId, $actionId, $message]);
        $row = self::findById($id);
        return $row ?? [];
    }

    public static function findById(string $id): ?array
    {
        $stmt = getDB()->prepare("SELECT * FROM appeals WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function findByActionId(string $actionId): ?array
    {
        $stmt = getDB()->prepare("SELECT * FROM appeals WHERE moderation_action_id = ? LIMIT 1");
        $stmt->execute([$actionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Banding milik user + ringkasan aksi yang dibanding. */
    public static function listForUser(string $userId): array
    {
        $stmt = getDB()->prepare(
            "SELECT a.*, m.action_type, m.target_type, m.target_id
             FROM appeals a
             JOIN moderation_actions m ON m.id = a.moderation_action_id
             WHERE a.user_id = ?
             ORDER BY a.created_at DESC"
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Aksi suspend aktif (belum dibatalkan) atas user ini, kalau ada. */
    public static function activeSuspendAction(string $userId): ?array
    {
        $stmt = getDB()->prepare(
            "SELECT * FROM moderation_actions
             WHERE action_type = 'suspend_user' AND target_id = ? AND reverted_at IS NULL
             ORDER BY created_at DESC LIMIT 1"
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Info banding atas suspend aktif — untuk payload login (§4.4). */
    public static function suspendAppealInfo(string $userId): ?array
    {
        $action = self::activeSuspendAction($userId);
        if ($action === null) {
            return null;
        }
        $appeal = self::findByActionId((string) $action['id']);
        if ($appeal === null) {
            return null;
        }
        return [
            'status' => $appeal['status'],
            'decision_note' => $appeal['decision_note'],
            'created_at' => $appeal['created_at'],
        ];
    }

    /** Antrean admin: pending dulu, lalu riwayat terbaru. */
    public static function listAdmin(): array
    {
        $stmt = getDB()->query(
            "SELECT a.*, u.username AS appellant_name,
                    m.action_type, m.target_type, m.target_id,
                    m.reason AS action_reason, m.actor_id, m.requested_by,
                    m.created_at AS action_created_at,
                    (SELECT COUNT(*) FROM moderation_actions s
                      WHERE s.target_owner_id = a.user_id AND s.strike = 1 AND s.reverted_at IS NULL)
                      AS appellant_strikes,
                    (SELECT COUNT(*) FROM moderation_actions h
                      WHERE h.target_owner_id = a.user_id)
                      AS appellant_history
             FROM appeals a
             JOIN users u ON u.id = a.user_id
             JOIN moderation_actions m ON m.id = a.moderation_action_id
             ORDER BY (a.status = 'pending') DESC, a.created_at DESC"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function decide(string $id, string $status, string $deciderId, ?string $note): void
    {
        getDB()->prepare(
            "UPDATE appeals SET status = ?, decided_by = ?, decided_at = NOW(), decision_note = ? WHERE id = ?"
        )->execute([$status, $deciderId, $note, $id]);
    }
}
