<?php

/**
 * scripts/ai_moderator_worker.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Worker AI Moderator, model OneShotCron (keputusan Jev 2026-11-17):
 * proses maksimal N job pending lalu keluar. Dipicu tiap menit oleh
 * Windows Task Scheduler (dev) atau cron (produksi). Sekali jalan =
 * tidak ada proses menggantung yang harus diawasi; crash hanya
 * membunuh satu run.
 *
 * Pemakaian:
 *   php scripts/ai_moderator_worker.php             -> maks 10 job
 *   php scripts/ai_moderator_worker.php --limit=25  -> maks 25 job
 *
 * Berhenti bersih bila AI_MOD_ENABLED bukan '1' di .env.
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/config/env.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/models/AiModerationService.php';

$cfg = aiModerationConfig();
if (!$cfg['enabled']) {
    echo "AI_MOD_ENABLED tidak aktif di .env — worker berhenti.\n";
    exit(0);
}
if ($cfg['api_key'] === '') {
    echo "OPENROUTER_API_KEY kosong di .env — worker berhenti.\n";
    exit(1);
}

$limit = 10;
foreach (($argv ?? []) as $arg) {
    if (str_starts_with($arg, '--limit=')) {
        $limit = max(1, min(100, (int) substr($arg, 8)));
    }
}

$stats = AiModerationService::runBatch($limit);
echo sprintf(
    "AI Moderator worker selesai: claimed=%d done=%d retried=%d failed=%d\n",
    $stats['claimed'],
    $stats['done'],
    $stats['retried'],
    $stats['failed']
);
