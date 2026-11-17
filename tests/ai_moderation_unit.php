<?php

/**
 * tests/ai_moderation_unit.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Unit test logika murni AI Moderator: pemetaan skor -> keputusan,
 * penggabungan skor, KeywordGate, dan parser skor model.
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

function scores(int $judol = 0, int $seksual = 0, int $kekerasan = 0, int $miras = 0, int $berbahaya = 0, string $alasan = ''): array
{
    return ['judol' => $judol, 'seksual' => $seksual, 'kekerasan' => $kekerasan, 'miras' => $miras, 'berbahaya' => $berbahaya, 'alasan' => $alasan];
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

// keywordGateHit()
check('gate kena: slot gacor', AiModerationService::keywordGateHit('PROMO slot gacor hari ini, maxwin terus', $cfg));
check('gate kena: nomor WA', AiModerationService::keywordGateHit('daftar lewat wa 081234567890 ya', $cfg));
check('gate kena: link wa.me', AiModerationService::keywordGateHit('klik wa.me/6281234567890', $cfg));
check('gate lolos: slot waktu', !AiModerationService::keywordGateHit('Besok kita bahas slot waktu presentasi kelompok', $cfg));
check('gate lolos: teks kuliah biasa', !AiModerationService::keywordGateHit('Belajar React bareng di perpustakaan, bawa laptop', $cfg));

// OpenRouterClient::parseScores()
$parsed = OpenRouterClient::parseScores('{"judol": 95, "seksual": 0, "kekerasan": 0, "miras": 0, "berbahaya": 0, "alasan": "Iklan judi slot."}');
check('parse JSON bersih', $parsed['judol'] === 95 && $parsed['alasan'] === 'Iklan judi slot.');
$wrapped = OpenRouterClient::parseScores("Berikut hasilnya:\n{\"judol\": 10, \"seksual\": 0, \"kekerasan\": 0, \"miras\": 0, \"berbahaya\": 0, \"alasan\": \"Aman.\"}\nSemoga membantu.");
check('parse JSON terbungkus teks', $wrapped['judol'] === 10);
$clamped = OpenRouterClient::parseScores('{"judol": 140, "seksual": -5, "kekerasan": 0, "miras": 0, "berbahaya": 0, "alasan": "x"}');
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
