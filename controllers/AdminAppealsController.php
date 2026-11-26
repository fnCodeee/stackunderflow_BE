<?php

/**
 * controllers/AdminAppealsController.php
 * ─────────────────────────────────────────────────────────────────────────────
 * ANTREAN BANDING ADMIN (spec-admin.md §4.5 + §8, Fase 3)
 *
 * - GET  /api/admin/appeals                antrean (pending dulu) +
 *                                          riwayat, dengan konteks
 *                                          peninjauan lengkap
 * - POST /api/admin/appeals/:id/decide     putusan Super Admin;
 *                                          eksekusi pembalikan lewat
 *                                          ModerationService::
 *                                          applyAppealDecision
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

require_once BASE_PATH . '/models/AppealModel.php';
require_once BASE_PATH . '/utils/Response.php';

class AdminAppealsController
{
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

    /** GET /api/admin/appeals */
    public static function index(array $params = []): void
    {
        try {
            $rows = [];
            foreach (AppealModel::listAdmin() as $a) {
                $info = ModerationService::targetInfo((string) $a['target_type'], (string) $a['target_id']);
                $rows[] = [
                    'id' => $a['id'],
                    'user_id' => $a['user_id'],
                    'appellant_name' => $a['appellant_name'],
                    'moderation_action_id' => $a['moderation_action_id'],
                    'message' => $a['message'],
                    'status' => $a['status'],
                    'decision_note' => $a['decision_note'],
                    'decided_by' => $a['decided_by'],
                    'decided_at' => $a['decided_at'],
                    'created_at' => $a['created_at'],
                    'action' => [
                        'action_type' => $a['action_type'],
                        'target_type' => $a['target_type'],
                        'target_id' => $a['target_id'],
                        'label' => $info['label'] ?? null,
                        'reason' => $a['action_reason'],
                        'actor_id' => $a['actor_id'],
                        'requested_by' => $a['requested_by'],
                        'created_at' => $a['action_created_at'],
                    ],
                    'appellant_strikes' => (int) $a['appellant_strikes'],
                    'appellant_history' => (int) $a['appellant_history'],
                ];
            }
            Response::json(['appeals' => $rows], 200, 'OK', true);
        } catch (Throwable $e) {
            error_log('[ADMIN APPEALS LIST ERROR] ' . $e->getMessage());
            Response::json(null, 500, 'Terjadi kesalahan pada server.', false);
        }
    }

    /** POST /api/admin/appeals/:id/decide — Super Admin only (route guard). */
    public static function decide(array $params = []): void
    {
        try {
            $actor = self::actor();
            $id = (string) ($params['id'] ?? '');
            $body = self::body();
            $decision = (string) ($body['decision'] ?? '');
            $note = trim((string) ($body['note'] ?? ''));
            if (!in_array($decision, ['granted', 'denied'], true)) {
                Response::json(null, 400, 'Keputusan tidak dikenal.', false);
            }
            if ($decision === 'denied' && mb_strlen($note) < 3) {
                Response::json(null, 400, 'Penolakan banding wajib menyertakan catatan keputusan.', false);
            }
            $appeal = AppealModel::findById($id);
            if ($appeal === null) {
                Response::json(null, 404, 'Banding tidak ditemukan.', false);
            }
            if ($appeal['status'] !== 'pending') {
                Response::json(null, 409, 'Banding ini sudah diputuskan.', false);
            }
            $original = ModerationService::actionById((string) $appeal['moderation_action_id']);
            if ($original === null) {
                Response::json(null, 500, 'Aksi asal banding tidak ditemukan.', false);
            }
            ModerationService::applyAppealDecision($appeal, $original, $decision, $actor, $note);
            AppealModel::decide($id, $decision, (string) $actor['id'], $note !== '' ? $note : null);
            Response::json(['appeal' => AppealModel::findById($id)], 200, 'Keputusan banding tersimpan.', true);
        } catch (Throwable $e) {
            error_log('[ADMIN APPEALS DECIDE ERROR] ' . $e->getMessage());
            Response::json(null, 500, 'Terjadi kesalahan pada server.', false);
        }
    }
}
