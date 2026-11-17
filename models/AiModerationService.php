<?php

/**
 * models/AiModerationService.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Mesin AI Moderator (spec-ai-moderator.md).
 *
 * Alur: enqueue() dipanggil handler pembuatan konten -> worker
 * (scripts/ai_moderator_worker.php) mengklaim job -> gambar dinilai
 * model vision lewat rantai fallback, teks lewat KeywordGate ->
 * pelanggaran terbukti (>= 70) dieksekusi lewat
 * ModerationService::execute, jalur yang sama dengan admin manusia.
 * Zona abu-abu (40-69) tidak dihapus: dicatat sebagai flag untuk
 * keputusan manusia. Setiap penilaian berakhir sebagai satu baris
 * ai_moderation_verdicts.
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

require_once BASE_PATH . '/config/ai_moderation.php';
require_once BASE_PATH . '/models/OpenRouterClient.php';
require_once BASE_PATH . '/models/ModerationService.php';

class AiModerationService
{
    public const AI_USER_ID = '00000000-0000-4000-8000-0000000000a1';
    public const CATEGORIES = ['judol', 'seksual', 'kekerasan', 'miras', 'berbahaya'];

    private const TARGET_TABLES = [
        'post' => 'public_posts',
        'comment' => 'comments',
        'collab' => 'collaborations',
    ];

    private const REMOVE_ACTIONS = [
        'post' => 'remove_post',
        'comment' => 'remove_comment',
        'collab' => 'remove_collab',
    ];

    // ── Enqueue ─────────────────────────────────────────────────────

    /**
     * Daftarkan satu target untuk diperiksa AI. Tidak pernah melempar
     * error: kegagalan enqueue hanya dicatat ke log, pembuatan konten
     * tidak boleh ikut gagal (aturan user: tanpa eksekusi setengah).
     */
    public static function enqueue(string $targetType, string $targetId, string $trigger = 'new_content', ?string $reportId = null): void
    {
        try {
            $cfg = aiModerationConfig();
            if (!$cfg['enabled'] || !isset(self::TARGET_TABLES[$targetType])) {
                return;
            }
            $pdo = getDB();
            $priority = $trigger === 'report' ? 10 : 0;

            // Job aktif untuk target yang sama dilebur: satu penilaian
            // membaca keadaan konten terbaru saat diproses, jadi job
            // kedua tidak menambah informasi, hanya antrean.
            $stmt = $pdo->prepare(
                "SELECT id, priority FROM ai_moderation_jobs
                 WHERE target_type = ? AND target_id = ? AND status IN ('pending', 'processing')
                 ORDER BY created_at ASC LIMIT 1"
            );
            $stmt->execute([$targetType, $targetId]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                if ($priority > (int) $existing['priority'] || $reportId !== null) {
                    $pdo->prepare(
                        "UPDATE ai_moderation_jobs
                         SET priority = GREATEST(priority, ?),
                             report_id = COALESCE(report_id, ?),
                             trigger_source = IF(? = 'report', 'report', trigger_source)
                         WHERE id = ?"
                    )->execute([$priority, $reportId, $trigger, $existing['id']]);
                }
                return;
            }

            $pdo->prepare(
                "INSERT INTO ai_moderation_jobs (id, target_type, target_id, trigger_source, report_id, priority)
                 VALUES (?, ?, ?, ?, ?, ?)"
            )->execute([ModerationService::uuid(), $targetType, $targetId, $trigger, $reportId, $priority]);
        } catch (Throwable $e) {
            error_log('[AiModeration] enqueue gagal: ' . $e->getMessage());
        }
    }

    // ── Logika keputusan murni (seam unit test) ─────────────────────

    public static function decide(array $scores, array $cfg): string
    {
        $max = 0;
        foreach (self::CATEGORIES as $category) {
            $max = max($max, (int) ($scores[$category] ?? 0));
        }
        if ($max >= (int) $cfg['threshold_remove']) {
            return 'remove';
        }
        if ($max >= (int) $cfg['threshold_grey']) {
            return 'flag';
        }
        return 'clean';
    }

    /** Gabung beberapa set skor: per kategori ambil maksimum. */
    public static function mergeScores(array ...$sets): array
    {
        $merged = array_fill_keys(self::CATEGORIES, 0);
        $merged['alasan'] = '';
        $bestMax = -1;
        foreach ($sets as $set) {
            $localMax = 0;
            foreach (self::CATEGORIES as $category) {
                $value = (int) ($set[$category] ?? 0);
                if ($value > $merged[$category]) {
                    $merged[$category] = $value;
                }
                $localMax = max($localMax, $value);
            }
            if ($localMax > $bestMax) {
                $bestMax = $localMax;
                $merged['alasan'] = (string) ($set['alasan'] ?? '');
            }
        }
        return $merged;
    }

    public static function topCategory(array $scores): string
    {
        $top = self::CATEGORIES[0];
        $max = -1;
        foreach (self::CATEGORIES as $category) {
            if ((int) ($scores[$category] ?? 0) > $max) {
                $max = (int) $scores[$category];
                $top = $category;
            }
        }
        return $top;
    }

    /**
     * KeywordGate: true bila teks layak dikirim ke model. Kena gate
     * bukan vonis; model yang memutuskan.
     */
    public static function keywordGateHit(string $text, array $cfg): bool
    {
        $haystack = mb_strtolower($text);
        foreach ($cfg['keywords'] as $keyword) {
            if (str_contains($haystack, $keyword)) {
                return true;
            }
        }
        foreach (['#wa\.me/#i', '#chat\.whatsapp\.com#i', '#\b08\d{8,11}\b#'] as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }
        return false;
    }

    // ── Worker ──────────────────────────────────────────────────────

    /** Proses maksimal $limit job pending. Dipanggil worker one-shot. */
    public static function runBatch(int $limit = 10): array
    {
        $cfg = aiModerationConfig();
        $stats = ['claimed' => 0, 'done' => 0, 'retried' => 0, 'failed' => 0];
        for ($i = 0; $i < $limit; $i++) {
            $job = self::claimNext();
            if ($job === null) {
                break;
            }
            $stats['claimed']++;
            try {
                self::processJob($job, $cfg);
                getDB()->prepare(
                    "UPDATE ai_moderation_jobs SET status = 'done', processed_at = NOW(), last_error = NULL WHERE id = ?"
                )->execute([$job['id']]);
                $stats['done']++;
            } catch (Throwable $e) {
                $attempts = (int) $job['attempts'] + 1;
                if ($attempts >= (int) $cfg['max_attempts']) {
                    getDB()->prepare(
                        "UPDATE ai_moderation_jobs SET status = 'failed', processed_at = NOW(), last_error = ? WHERE id = ?"
                    )->execute([mb_substr($e->getMessage(), 0, 1000), $job['id']]);
                    self::recordVerdict($job, null, $cfg, null, 'error', 'Semua percobaan gagal: ' . $e->getMessage(), null, [], 0);
                    $stats['failed']++;
                } else {
                    $backoffMinutes = [1, 5, 15, 60, 240][$attempts - 1] ?? 240;
                    getDB()->prepare(
                        "UPDATE ai_moderation_jobs
                         SET status = 'pending', next_attempt_at = DATE_ADD(NOW(), INTERVAL ? MINUTE), last_error = ?
                         WHERE id = ?"
                    )->execute([$backoffMinutes, mb_substr($e->getMessage(), 0, 1000), $job['id']]);
                    $stats['retried']++;
                }
            }
        }
        return $stats;
    }

    private static function claimNext(): ?array
    {
        $pdo = getDB();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->query(
                "SELECT * FROM ai_moderation_jobs
                 WHERE status = 'pending' AND (next_attempt_at IS NULL OR next_attempt_at <= NOW())
                 ORDER BY priority DESC, created_at ASC LIMIT 1 FOR UPDATE"
            );
            $job = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$job) {
                $pdo->commit();
                return null;
            }
            $pdo->prepare("UPDATE ai_moderation_jobs SET status = 'processing', attempts = attempts + 1 WHERE id = ?")
                ->execute([$job['id']]);
            $pdo->commit();
            return $job;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    // ── Penilaian satu job ──────────────────────────────────────────

    private static function processJob(array $job, array $cfg): void
    {
        $started = microtime(true);
        $type = (string) $job['target_type'];
        $targetId = (string) $job['target_id'];

        $target = self::loadTarget($type, $targetId);
        if ($target === null) {
            self::recordVerdict($job, null, $cfg, null, 'gone', 'Target tidak ditemukan.', null, [], self::elapsed($started));
            return;
        }
        if ($target['deleted_at'] !== null || $target['moderation_state'] !== 'visible') {
            self::recordVerdict($job, null, $cfg, null, 'gone', 'Konten sudah tidak tampil saat diperiksa.', null, [], self::elapsed($started));
            return;
        }

        $scoreSets = [];
        $setModels = [];
        $usages = [];

        foreach (self::imageUrls($type, $targetId, $cfg) as $url) {
            $bytes = self::fetchImageBytes($url);
            if ($bytes === null) {
                continue;
            }
            $hash = hash('sha256', $bytes);
            $cached = self::cachedScores($hash);
            if ($cached !== null) {
                $scoreSets[] = $cached['scores'];
                $setModels[] = $cached['model'];
                continue;
            }
            $result = self::judgeWithChain($cfg, $bytes, null);
            $scoreSets[] = $result['scores'];
            $setModels[] = $result['model'];
            $usages[] = ['model' => $result['model'], 'usage' => $result['usage']];
            self::storeCachedScores($hash, $result['scores'], $result['model']);
        }

        $text = trim((string) $target['text']);
        $gateHit = $text !== '' && self::keywordGateHit($text, $cfg);
        if ($gateHit) {
            $result = self::judgeWithChain($cfg, null, $text);
            $scoreSets[] = $result['scores'];
            $setModels[] = $result['model'];
            $usages[] = ['model' => $result['model'], 'usage' => $result['usage']];
        }

        if ($scoreSets === []) {
            self::recordVerdict($job, null, $cfg, null, 'clean', 'Tidak ada gambar; teks tidak memicu keyword gate.', null, [], self::elapsed($started));
            return;
        }

        $scores = self::mergeScores(...$scoreSets);
        $decision = self::decide($scores, $cfg);
        $decidingModel = self::decidingModel($scoreSets, $setModels, $scores);

        // Eskalasi zona abu-abu: satu penilaian ulang oleh model
        // eskalasi sebelum menyerahkan ke manusia sebagai flag.
        if ($decision === 'flag' && $decidingModel !== $cfg['escalation_model']) {
            $escalated = self::escalate($cfg, $type, $targetId, $gateHit ? $text : null);
            if ($escalated !== null) {
                $scoreSets[] = $escalated['scores'];
                $setModels[] = $escalated['model'];
                $usages[] = ['model' => $escalated['model'], 'usage' => $escalated['usage']];
                $scores = self::mergeScores(...$scoreSets);
                $decision = self::decide($scores, $cfg);
                $decidingModel = self::decidingModel($scoreSets, $setModels, $scores);
            }
        }

        $actionId = null;
        if ($decision === 'remove') {
            $reason = 'AI Moderator: ' . self::topCategory($scores) . ' — ' . (string) ($scores['alasan'] ?? '');
            try {
                $result = ModerationService::execute(
                    [
                        'action_type' => self::REMOVE_ACTIONS[$type],
                        'target_type' => $type,
                        'target_id' => $targetId,
                        'reason' => $reason,
                        'with_strike' => true,
                    ],
                    ['id' => self::AI_USER_ID]
                );
                $actionId = $result['action_id'] ?? null;
            } catch (InvalidArgumentException $e) {
                // Balapan dengan admin atau pemilik: status konten
                // berubah duluan. Bukan kegagalan, tidak perlu retry.
                self::recordVerdict($job, $decidingModel, $cfg, $scores, 'gone', 'Eksekusi dilewati: ' . $e->getMessage(), null, $usages, self::elapsed($started));
                return;
            }
        }

        self::recordVerdict($job, $decidingModel, $cfg, $scores, $decision, (string) ($scores['alasan'] ?? ''), $actionId, $usages, self::elapsed($started));
    }

    /** Model pemilik set skor dengan kategori tertinggi. */
    private static function decidingModel(array $scoreSets, array $setModels, array $merged): string
    {
        $top = self::topCategory($merged);
        $topValue = (int) ($merged[$top] ?? 0);
        foreach ($scoreSets as $i => $set) {
            if ((int) ($set[$top] ?? 0) === $topValue && isset($setModels[$i])) {
                return (string) $setModels[$i];
            }
        }
        return (string) ($setModels[0] ?? '');
    }

    /** Penilaian ulang zona abu-abu langsung ke model eskalasi. */
    private static function escalate(array $cfg, string $type, string $targetId, ?string $gatedText): ?array
    {
        try {
            $sets = [];
            $usage = [];
            foreach (self::imageUrls($type, $targetId, $cfg) as $url) {
                $bytes = self::fetchImageBytes($url);
                if ($bytes === null) {
                    continue;
                }
                $result = OpenRouterClient::judge($cfg, (string) $cfg['escalation_model'], $bytes, null);
                $sets[] = $result['scores'];
                $usage[] = $result['usage'];
            }
            if ($gatedText !== null && $gatedText !== '') {
                $result = OpenRouterClient::judge($cfg, (string) $cfg['escalation_model'], null, $gatedText);
                $sets[] = $result['scores'];
                $usage[] = $result['usage'];
            }
            if ($sets === []) {
                return null;
            }
            return ['scores' => self::mergeScores(...$sets), 'model' => (string) $cfg['escalation_model'], 'usage' => $usage];
        } catch (Throwable $e) {
            error_log('[AiModeration] eskalasi gagal: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Nilai satu sumber lewat rantai model. Bila kuota lunak model
     * free hari ini habis, rantai mulai dari model berbayar.
     */
    private static function judgeWithChain(array $cfg, ?string $imageBytes, ?string $text): array
    {
        $models = $cfg['models'];
        if (self::freeVerdictsToday($cfg) >= (int) $cfg['free_daily_soft_cap']) {
            $models = array_values(array_filter($models, static fn ($m) => $m !== $cfg['primary_model']));
        }
        $last = null;
        foreach ($models as $model) {
            try {
                $result = OpenRouterClient::judge($cfg, (string) $model, $imageBytes, $text);
                return [
                    'scores' => $result['scores'],
                    'usage' => $result['usage'],
                    'model' => (string) $model,
                    'latency_ms' => $result['latency_ms'],
                ];
            } catch (Throwable $e) {
                $last = $e;
                error_log("[AiModeration] model $model gagal: " . $e->getMessage());
            }
        }
        throw new RuntimeException('Semua model gagal' . ($last !== null ? ': ' . $last->getMessage() : ''));
    }

    /**
     * Perkiraan pemakaian model free hari ini dari verdict yang
     * tercatat. Bukan penghitung panggilan mentah, tapi cukup untuk
     * rem kuota pada volume saat ini.
     */
    private static function freeVerdictsToday(array $cfg): int
    {
        $stmt = getDB()->prepare(
            "SELECT COUNT(*) FROM ai_moderation_verdicts WHERE model = ? AND created_at >= CURDATE()"
        );
        $stmt->execute([$cfg['primary_model']]);
        return (int) $stmt->fetchColumn();
    }

    // ── Sumber konten ───────────────────────────────────────────────

    private static function loadTarget(string $type, string $targetId): ?array
    {
        $table = self::TARGET_TABLES[$type] ?? null;
        if ($table === null) {
            return null;
        }
        $textExpr = match ($type) {
            'post' => 'description',
            'comment' => 'content',
            'collab' => "CONCAT(title, '\n', description)",
        };
        $stmt = getDB()->prepare(
            "SELECT moderation_state, deleted_at, user_id, $textExpr AS text_content FROM $table WHERE id = ? LIMIT 1"
        );
        $stmt->execute([$targetId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        return [
            'moderation_state' => $row['moderation_state'],
            'deleted_at' => $row['deleted_at'],
            'owner_id' => $row['user_id'],
            'text' => (string) $row['text_content'],
        ];
    }

    private static function imageUrls(string $type, string $targetId, array $cfg): array
    {
        if ($type === 'comment') {
            return [];
        }
        $table = $type === 'post' ? 'media_posts' : 'collab_media';
        $fk = $type === 'post' ? 'post_id' : 'collab_id';
        $stmt = getDB()->prepare(
            "SELECT media_url FROM $table WHERE $fk = ? ORDER BY created_at ASC LIMIT " . (int) $cfg['max_images_per_target']
        );
        $stmt->execute([$targetId]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Ambil byte gambar, diutamakan versi kecilnya (transformasi
     * Cloudinary bila URL-nya dari sana), lalu dinormalkan ke JPEG
     * sisi terpanjang 768 piksel lewat GD bila tersedia.
     */
    private static function fetchImageBytes(string $url): ?string
    {
        $fetchUrl = $url;
        if (str_contains($url, 'res.cloudinary.com') && str_contains($url, '/upload/')) {
            $fetchUrl = str_replace('/upload/', '/upload/w_768,c_limit,q_auto,f_jpg/', $url);
        }
        $ch = curl_init($fetchUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => 30,
        ]);
        $bytes = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($bytes === false || $code !== 200 || $bytes === '') {
            return null;
        }
        return self::normalizeImage((string) $bytes) ?? (string) $bytes;
    }

    private static function normalizeImage(string $bytes): ?string
    {
        if (!function_exists('imagecreatefromstring')) {
            return null;
        }
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            return null;
        }
        $width = imagesx($image);
        $height = imagesy($image);
        $longest = max($width, $height);
        if ($longest > 768) {
            $scale = 768 / $longest;
            $resized = imagescale($image, (int) round($width * $scale), (int) round($height * $scale));
            if ($resized !== false) {
                imagedestroy($image);
                $image = $resized;
            }
        }
        ob_start();
        $ok = imagejpeg($image, null, 85);
        $jpeg = ob_get_clean();
        imagedestroy($image);
        return $ok && is_string($jpeg) && $jpeg !== '' ? $jpeg : null;
    }

    // ── Cache hash + verdict ────────────────────────────────────────

    private static function cachedScores(string $hash): ?array
    {
        $stmt = getDB()->prepare("SELECT scores, model FROM ai_image_hashes WHERE sha256 = ? LIMIT 1");
        $stmt->execute([$hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $scores = json_decode((string) $row['scores'], true);
        return is_array($scores) ? ['scores' => $scores, 'model' => (string) $row['model']] : null;
    }

    private static function storeCachedScores(string $hash, array $scores, string $model): void
    {
        getDB()->prepare(
            "INSERT IGNORE INTO ai_image_hashes (sha256, scores, model) VALUES (?, ?, ?)"
        )->execute([$hash, json_encode($scores), $model]);
    }

    private static function recordVerdict(array $job, ?string $model, array $cfg, ?array $scores, string $decision, string $reason, ?string $actionId, array $usages, int $latencyMs): void
    {
        getDB()->prepare(
            "INSERT INTO ai_moderation_verdicts
             (id, job_id, target_type, target_id, model, prompt_version, scores, decision, reason, moderation_action_id, latency_ms, usage_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            ModerationService::uuid(),
            $job['id'] ?? null,
            $job['target_type'],
            $job['target_id'],
            $model,
            (string) $cfg['prompt_version'],
            $scores !== null ? json_encode($scores) : null,
            $decision,
            mb_substr($reason, 0, 2000),
            $actionId,
            $latencyMs,
            $usages !== [] ? json_encode($usages) : null,
        ]);
    }

    private static function elapsed(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}
