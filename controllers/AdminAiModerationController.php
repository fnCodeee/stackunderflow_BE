<?php

/**
 * controllers/AdminAiModerationController.php
 * ─────────────────────────────────────────────────────────────────────────────
 * PANEL AI MODERATOR (spec-ai-moderator.md §4.6)
 *
 * - GET  /api/admin/ai-moderation/overview        ringkasan antrian + verdict
 * - GET  /api/admin/ai-moderation/verdicts        log verdict (paginasi)
 * - GET  /api/admin/ai-moderation/flags           daftar flag abu-abu terbuka
 * - POST /api/admin/ai-moderation/jobs/:id/retry  antrekan ulang job gagal (Super)
 * - POST /api/admin/ai-moderation/flags/:id/resolve  putuskan flag (Super)
 *
 * Membaca boleh Monitor + Super Admin (AdminMiddleware). Eksekusi
 * (retry, resolve) hanya Super Admin (requireSuper), sejalan panel
 * admin yang ada. Penghapusan dari flag tetap lewat
 * ModerationService::execute — tidak ada jalur kedua.
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

require_once BASE_PATH . '/models/ModerationService.php';
require_once BASE_PATH . '/models/AiModerationService.php';
require_once BASE_PATH . '/utils/Response.php';

class AdminAiModerationController
{
    private const DECISIONS = ['remove', 'flag', 'clean', 'gone', 'error'];
    private const REMOVE_ACTIONS = [
        'post' => 'remove_post',
        'comment' => 'remove_comment',
        'collab' => 'remove_collab',
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

    private static function pageParams(): array
    {
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $limit = max(1, min(50, (int) ($_GET['limit'] ?? 20)));
        return [$page, $limit];
    }

    private static function envelope(array $items, int $total, int $page, int $limit): array
    {
        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'total_pages' => max(1, (int) ceil($total / $limit)),
        ];
    }

    /** Perkaya baris verdict dengan label target + pemiliknya. */
    private static function enrich(array $row): array
    {
        $info = ModerationService::targetInfo((string) $row['target_type'], (string) $row['target_id']);
        $ownerUsername = null;
        if ($info !== null && !empty($info['owner_id'])) {
            $stmt = getDB()->prepare("SELECT username FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$info['owner_id']]);
            $ownerUsername = $stmt->fetchColumn() ?: null;
        }
        $scores = isset($row['scores']) ? json_decode((string) $row['scores'], true) : null;
        return [
            'id' => $row['id'],
            'job_id' => $row['job_id'],
            'target_type' => $row['target_type'],
            'target_id' => $row['target_id'],
            'target_label' => $info['label'] ?? $row['target_id'],
            'target_state' => $info['state'] ?? null,
            'owner_username' => $ownerUsername,
            'model' => $row['model'],
            'prompt_version' => $row['prompt_version'],
            'scores' => is_array($scores) ? $scores : null,
            'decision' => $row['decision'],
            'reason' => $row['reason'],
            'moderation_action_id' => $row['moderation_action_id'],
            'flag_state' => $row['flag_state'] ?? 'open',
            'latency_ms' => $row['latency_ms'] !== null ? (int) $row['latency_ms'] : null,
            'created_at' => $row['created_at'],
        ];
    }

    /** GET /api/admin/ai-moderation/overview */
    public static function overview(array $params = []): void
    {
        try {
            $pdo = getDB();
            $jobs = ['pending' => 0, 'processing' => 0, 'done' => 0, 'failed' => 0];
            foreach ($pdo->query("SELECT status, COUNT(*) AS c FROM ai_moderation_jobs GROUP BY status")->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $jobs[$row['status']] = (int) $row['c'];
            }
            $verdictsToday = array_fill_keys(self::DECISIONS, 0);
            foreach ($pdo->query("SELECT decision, COUNT(*) AS c FROM ai_moderation_verdicts WHERE created_at >= CURDATE() GROUP BY decision")->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $verdictsToday[$row['decision']] = (int) $row['c'];
            }
            $flagsOpen = (int) $pdo->query(
                "SELECT COUNT(*) FROM ai_moderation_verdicts WHERE decision = 'flag' AND flag_state = 'open'"
            )->fetchColumn();

            // Estimasi biaya hari ini dari usage yang tercatat di verdict.
            $costToday = 0.0;
            $callsToday = 0;
            foreach ($pdo->query("SELECT usage_json FROM ai_moderation_verdicts WHERE created_at >= CURDATE() AND usage_json IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN) as $usageJson) {
                $entries = json_decode((string) $usageJson, true);
                if (!is_array($entries)) {
                    continue;
                }
                foreach ($entries as $entry) {
                    $usage = is_array($entry) && isset($entry['usage']) && is_array($entry['usage']) ? $entry['usage'] : $entry;
                    if (is_array($usage)) {
                        $callsToday++;
                        $costToday += (float) ($usage['cost'] ?? 0);
                    }
                }
            }

            $failedJobs = $pdo->query(
                "SELECT id, target_type, target_id, attempts, last_error, created_at
                 FROM ai_moderation_jobs WHERE status = 'failed' ORDER BY created_at DESC LIMIT 10"
            )->fetchAll(PDO::FETCH_ASSOC);

            Response::json([
                'enabled' => aiModerationConfig()['enabled'],
                'jobs' => $jobs,
                'verdicts_today' => $verdictsToday,
                'flags_open' => $flagsOpen,
                'model_calls_today' => $callsToday,
                'estimated_cost_today_usd' => round($costToday, 6),
                'failed_jobs' => $failedJobs,
            ], 200, 'OK', true);
        } catch (Throwable $e) {
            error_log('[ADMIN AI OVERVIEW ERROR] ' . $e->getMessage());
            Response::json(null, 500, 'Terjadi kesalahan pada server. Silakan coba lagi.', false);
        }
    }

    /** GET /api/admin/ai-moderation/verdicts?decision=&target_type=&page=&limit= */
    public static function verdicts(array $params = []): void
    {
        try {
            [$page, $limit] = self::pageParams();
            $decision = trim((string) ($_GET['decision'] ?? ''));
            $targetType = trim((string) ($_GET['target_type'] ?? ''));
            $where = [];
            $args = [];
            if (in_array($decision, self::DECISIONS, true)) {
                $where[] = 'decision = ?';
                $args[] = $decision;
            }
            if (in_array($targetType, ['post', 'comment', 'collab'], true)) {
                $where[] = 'target_type = ?';
                $args[] = $targetType;
            }
            $whereSql = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';
            $pdo = getDB();
            $count = $pdo->prepare("SELECT COUNT(*) FROM ai_moderation_verdicts $whereSql");
            $count->execute($args);
            $total = (int) $count->fetchColumn();
            $stmt = $pdo->prepare(
                "SELECT * FROM ai_moderation_verdicts $whereSql ORDER BY created_at DESC LIMIT $limit OFFSET " . (($page - 1) * $limit)
            );
            $stmt->execute($args);
            $items = array_map(static fn ($row) => self::enrich($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
            Response::json(self::envelope($items, $total, $page, $limit), 200, 'OK', true);
        } catch (Throwable $e) {
            error_log('[ADMIN AI VERDICTS ERROR] ' . $e->getMessage());
            Response::json(null, 500, 'Terjadi kesalahan pada server. Silakan coba lagi.', false);
        }
    }

    /** GET /api/admin/ai-moderation/flags — flag terbuka pada konten yang masih tampil. */
    public static function flags(array $params = []): void
    {
        try {
            $stmt = getDB()->query(
                "SELECT * FROM ai_moderation_verdicts
                 WHERE decision = 'flag' AND flag_state = 'open'
                 ORDER BY created_at DESC LIMIT 50"
            );
            $items = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $enriched = self::enrich($row);
                if ($enriched['target_state'] === 'visible') {
                    $items[] = $enriched;
                }
            }
            Response::json(['items' => $items, 'total' => count($items)], 200, 'OK', true);
        } catch (Throwable $e) {
            error_log('[ADMIN AI FLAGS ERROR] ' . $e->getMessage());
            Response::json(null, 500, 'Terjadi kesalahan pada server. Silakan coba lagi.', false);
        }
    }

    /** POST /api/admin/ai-moderation/jobs/:id/retry (Super Admin) */
    public static function retryJob(array $params = []): void
    {
        try {
            $id = (string) ($params['id'] ?? '');
            $pdo = getDB();
            $stmt = $pdo->prepare("SELECT status FROM ai_moderation_jobs WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $status = $stmt->fetchColumn();
            if ($status === false) {
                Response::json(null, 404, 'Job tidak ditemukan.', false);
            }
            if ($status !== 'failed') {
                Response::json(null, 409, 'Hanya job gagal yang bisa di-retry.', false);
            }
            $pdo->prepare(
                "UPDATE ai_moderation_jobs SET status = 'pending', attempts = 0, next_attempt_at = NULL, last_error = NULL, processed_at = NULL WHERE id = ?"
            )->execute([$id]);
            Response::json(['id' => $id, 'status' => 'pending'], 200, 'Job diantrekan ulang.', true);
        } catch (Throwable $e) {
            error_log('[ADMIN AI RETRY ERROR] ' . $e->getMessage());
            Response::json(null, 500, 'Terjadi kesalahan pada server. Silakan coba lagi.', false);
        }
    }

    /** POST /api/admin/ai-moderation/flags/:id/resolve {outcome: removed|cleared} (Super Admin) */
    public static function resolveFlag(array $params = []): void
    {
        try {
            $id = (string) ($params['id'] ?? '');
            $outcome = (string) (self::body()['outcome'] ?? '');
            if (!in_array($outcome, ['removed', 'cleared'], true)) {
                Response::json(null, 400, 'Outcome tidak dikenal.', false);
            }
            $pdo = getDB();
            $stmt = $pdo->prepare("SELECT * FROM ai_moderation_verdicts WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $verdict = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$verdict) {
                Response::json(null, 404, 'Flag tidak ditemukan.', false);
            }
            if ($verdict['decision'] !== 'flag' || ($verdict['flag_state'] ?? 'open') !== 'open') {
                Response::json(null, 409, 'Flag ini sudah diputuskan.', false);
            }

            $actor = self::actor();
            $actionId = $verdict['moderation_action_id'];
            if ($outcome === 'removed') {
                $scores = json_decode((string) ($verdict['scores'] ?? ''), true) ?: [];
                $reason = 'Keputusan manual atas flag AI Moderator: '
                    . AiModerationService::topCategory($scores) . ' — ' . (string) ($verdict['reason'] ?? '');
                try {
                    $result = ModerationService::execute(
                        [
                            'action_type' => self::REMOVE_ACTIONS[$verdict['target_type']],
                            'target_type' => $verdict['target_type'],
                            'target_id' => $verdict['target_id'],
                            'reason' => $reason,
                            'with_strike' => true,
                        ],
                        $actor
                    );
                    $actionId = $result['action_id'] ?? null;
                } catch (InvalidArgumentException $e) {
                    Response::json(null, 409, $e->getMessage(), false);
                }
            }

            $pdo->prepare(
                "UPDATE ai_moderation_verdicts
                 SET flag_state = ?, flag_resolved_by = ?, flag_resolved_at = NOW(), moderation_action_id = ?
                 WHERE id = ?"
            )->execute([$outcome, (string) ($actor['id'] ?? ''), $actionId, $id]);
            Response::json(['id' => $id, 'flag_state' => $outcome, 'moderation_action_id' => $actionId], 200, 'Flag diputuskan.', true);
        } catch (Throwable $e) {
            error_log('[ADMIN AI RESOLVE ERROR] ' . $e->getMessage());
            Response::json(null, 500, 'Terjadi kesalahan pada server. Silakan coba lagi.', false);
        }
    }
}
