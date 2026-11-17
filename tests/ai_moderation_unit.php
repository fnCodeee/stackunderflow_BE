<?php

/**
 * tests/ai_moderation_unit.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Unit test logika murni AI Moderator: pemetaan skor -> keputusan,
 * penggabungan skor dan parser skor model.
 * Tidak menyentuh database dan tidak memanggil API.
 *
 * Jalankan: php tests/ai_moderation_unit.php
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/config/env.php';
require_once BASE_PATH . '/models/AiModerationService.php';

$cfg = aiModerationConfig();
$failures = 0;

function check(string $name, bool $ok): void
{
    global $failures;
    echo ($ok ? 'PASS' : 'FAIL') . "  $name\n";
    if (!$ok) {
        $failures++;
    }
}

function scores(int $judol = 0, int $seksual = 0, int $kekerasan = 0, int $miras = 0, int $berbahaya = 0, int $kata_kasar = 0, string $alasan = ''): array
{
    return ['judol' => $judol, 'seksual' => $seksual, 'kekerasan' => $kekerasan, 'miras' => $miras, 'berbahaya' => $berbahaya, 'kata_kasar' => $kata_kasar, 'alasan' => $alasan];
}

// decide(): ambang remove 70, abu-abu 40.
check('decide 85 -> remove', AiModerationService::decide(scores(judol: 85), $cfg) === 'remove');
check('decide 70 persis -> remove', AiModerationService::decide(scores(miras: 70), $cfg) === 'remove');
check('decide 69 -> flag', AiModerationService::decide(scores(judol: 69), $cfg) === 'flag');
check('decide 40 persis -> flag', AiModerationService::decide(scores(berbahaya: 40), $cfg) === 'flag');
check('decide 39 -> clean', AiModerationService::decide(scores(seksual: 39), $cfg) === 'clean');
check('decide semua 0 -> clean', AiModerationService::decide(scores(), $cfg) === 'clean');

// mergeScores(): maksimum per kategori, alasan dari set tertinggi.
$merged = AiModerationService::mergeScores(scores(judol: 30, alasan: 'pertama'), scores(judol: 90, miras: 20, alasan: 'kedua'));
check('merge ambil maksimum', $merged['judol'] === 90 && $merged['miras'] === 20);
check('merge alasan dari set tertinggi', $merged['alasan'] === 'kedua');
check('merge satu set kosong', AiModerationService::mergeScores()['judol'] === 0);

// topCategory()
check('topCategory miras', AiModerationService::topCategory(scores(judol: 10, miras: 77)) === 'miras');

// kata_kasar (kebijakan v3, keputusan user 2026-11-17):
// tidak ada tingkat ringan, semua makian dihapus; harfiah lolos.
check('kata_kasar 50 -> remove', AiModerationService::decide(scores(kata_kasar: 50), $cfg) === 'remove');
check('kata_kasar 40 persis -> remove', AiModerationService::decide(scores(kata_kasar: 40), $cfg) === 'remove');
check('kata_kasar 39 -> clean', AiModerationService::decide(scores(kata_kasar: 39), $cfg) === 'clean');
check('kata_kasar 85 -> remove', AiModerationService::decide(scores(kata_kasar: 85), $cfg) === 'remove');
check('kategori lain 50 tetap flag', AiModerationService::decide(scores(miras: 50), $cfg) === 'flag');

// OpenRouterClient::parseScores()
$parsed = OpenRouterClient::parseScores('{"judol": 95, "seksual": 0, "kekerasan": 0, "miras": 0, "berbahaya": 0, "kata_kasar": 0, "alasan": "Iklan judi slot."}');
check('parse JSON bersih', $parsed['judol'] === 95 && $parsed['kata_kasar'] === 0 && $parsed['alasan'] === 'Iklan judi slot.');
$wrapped = OpenRouterClient::parseScores("Berikut hasilnya:\n{\"judol\": 10, \"seksual\": 0, \"kekerasan\": 0, \"miras\": 0, \"berbahaya\": 0, \"kata_kasar\": 0, \"alasan\": \"Aman.\"}\nSemoga membantu.");
check('parse JSON terbungkus teks', $wrapped['judol'] === 10);
$clamped = OpenRouterClient::parseScores('{"judol": 140, "seksual": -5, "kekerasan": 0, "miras": 0, "berbahaya": 0, "kata_kasar": 0, "alasan": "x"}');
check('parse clamp 0-100', $clamped['judol'] === 100 && $clamped['seksual'] === 0);
try {
    OpenRouterClient::parseScores('{"judol": 10}');
    check('parse kunci hilang -> exception', false);
} catch (RuntimeException) {
    check('parse kunci hilang -> exception', true);
}
try {
    OpenRouterClient::parseScores('bukan json sama sekali');
    check('parse sampah -> exception', false);
} catch (RuntimeException) {
    check('parse sampah -> exception', true);
}

echo $failures === 0 ? "\nSEMUA LULUS\n" : "\n$failures GAGAL\n";
exit($failures === 0 ? 0 : 1);
