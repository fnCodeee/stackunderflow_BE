<?php

/**
 * controllers/ReportController.php
 * ─────────────────────────────────────────────────────────────────────────────
 * POST /api/reports — user melaporkan post / komentar / collab / user
 * (spec-admin.md §4.3). Menggantikan report sesi-saja yang lama:
 * report sekarang masuk database dan antrean moderasi admin.
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

require_once BASE_PATH . '/models/ReportModel.php';
require_once BASE_PATH . '/models/PostModel.php';
require_once BASE_PATH . '/models/CommentModel.php';
require_once BASE_PATH . '/models/CollabModel.php';
require_once BASE_PATH . '/models/UserModel.php';
require_once BASE_PATH . '/utils/Response.php';

class ReportController
{
    public static function create(array $params = []): void
    {
        try {
            $reporterId = (string) ($GLOBALS['user']['id'] ?? '');
            if ($reporterId === '') {
                Response::json(null, 401, 'Unauthorized', false);
            }

            $body = $GLOBALS['body'] ?? [];
            if (empty($body)) {
                $raw = file_get_contents('php://input');
                if (!empty($raw)) {
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded)) {
                        $body = $decoded;
                    }
                }
            }

            $targetType = trim((string) ($body['target_type'] ?? ''));
            $targetId = trim((string) ($body['target_id'] ?? ''));
            $reason = trim((string) ($body['reason'] ?? ''));

            if (!in_array($targetType, ['post', 'comment', 'collab', 'user'], true) || $targetId === '' || $reason === '') {
                Response::json(null, 400, 'Laporan tidak lengkap.', false);
            }

            // Target harus benar-benar ada.
            $ownerId = null;
            if ($targetType === 'post') {
                $row = PostModel::findById($targetId);
                $ownerId = $row['user_id'] ?? null;
            } elseif ($targetType === 'comment') {
                $row = CommentModel::findById($targetId);
                $ownerId = $row['user_id'] ?? null;
            } elseif ($targetType === 'collab') {
                $row = CollabModel::findById($targetId);
                $ownerId = $row['user_id'] ?? null;
            } else {
                $row = UserModel::findById($targetId);
                $ownerId = $row['id'] ?? null;
            }
            if ($row === null || $row === []) {
                Response::json(null, 404, 'Target laporan tidak ditemukan.', false);
            }
            if ($ownerId !== null && (string) $ownerId === $reporterId) {
                Response::json(null, 400, 'Kamu tidak bisa melaporkan milikmu sendiri.', false);
            }

            // Satu report open per user per target.
            $existing = ReportModel::findOpenByReporterAndTarget($reporterId, $targetType, $targetId);
            if ($existing !== null) {
                Response::json(['report' => $existing], 200, 'Kamu sudah melaporkan ini.', true);
            }

            $report = ReportModel::create($reporterId, $targetType, $targetId, $reason);
            Response::json(['report' => $report], 201, 'Laporan terkirim. Terima kasih!', true);
        } catch (Throwable $e) {
            error_log('[REPORT ERROR] ' . $e->getMessage());
            error_log('[SERVER ERROR] ' . $e->getMessage());
            Response::json(null, 500, 'Terjadi kesalahan pada server. Silakan coba lagi.', false);
        }
    }

    /** DELETE /api/reports/:id — pelapor menarik laporannya (Undo). */
    public static function withdraw(array $params = []): void
    {
        try {
            $reporterId = (string) ($GLOBALS['user']['id'] ?? '');
            $id = (string) ($params['id'] ?? '');
            if ($reporterId === '' || $id === '') {
                Response::json(null, 400, 'Permintaan tidak valid.', false);
            }
            $ok = ReportModel::deleteOpenById($id, $reporterId);
            if (!$ok) {
                Response::json(null, 404, 'Laporan tidak ditemukan atau sudah diproses.', false);
            }
            Response::json(null, 200, 'Laporan ditarik.', true);
        } catch (Throwable $e) {
            error_log('[REPORT WITHDRAW ERROR] ' . $e->getMessage());
            error_log('[SERVER ERROR] ' . $e->getMessage());
            Response::json(null, 500, 'Terjadi kesalahan pada server. Silakan coba lagi.', false);
        }
    }
}
