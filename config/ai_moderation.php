<?php

/**
 * config/ai_moderation.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Konfigurasi AI Moderator (spec-ai-moderator.md).
 *
 * Prompt kebijakan di file ini adalah aset konsistensi utama: teksnya
 * identik dengan prompt yang lulus bake-off model 2026-11-17
 * (Nemotron VL free dan Qwen 3.8 Omni Flash sama-sama 20/20).
 * Setiap perubahan prompt WAJIB menaikkan 'prompt_version'.
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

function aiModerationConfig(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $config = [
        'enabled' => (($_ENV['AI_MOD_ENABLED'] ?? '0') === '1'),
        'api_key' => (string) ($_ENV['OPENROUTER_API_KEY'] ?? ''),
        'endpoint' => 'https://openrouter.ai/api/v1/chat/completions',
        // Rantai model: free utama -> berbayar -> free cadangan.
        'models' => [
            'nvidia/nemotron-3.5-super-vl:free',
            'qwen/qwen3.8-omni-flash',
            'inclusionai/ling-3.2-flash:free',
        ],
        'primary_model' => 'nvidia/nemotron-3.5-super-vl:free',
        'escalation_model' => 'qwen/qwen3.8-omni-flash',
        // Batas lunak verdict model free per hari (jaga kuota free
        // OpenRouter 50/hari untuk akun di bawah $10 kredit).
        'free_daily_soft_cap' => 45,
        'threshold_remove' => 70,
        'threshold_grey' => 40,
        'max_tokens' => 1500,
        'timeout_seconds' => 60,
        'max_images_per_target' => 5,
        'max_attempts' => 5,
        'prompt_version' => 'v1',
        'prompt' => <<<'PROMPT'
Kamu adalah moderator konten untuk platform kolaborasi mahasiswa Indonesia bernama Underflow. Nilai gambar yang diberikan dengan skor 0-100 untuk lima kategori pelanggaran:

1. judol: promosi judi online. Penanda khas: mesin slot, kasino, kartu remi untuk taruhan, teks seperti "bonus new member", "slot gacor", "maxwin", "depo pulsa", "betting", "togel", nomor WhatsApp untuk pendaftaran situs judi, uang tunai dipamerkan sebagai iming-iming menang judi.
2. seksual: konten seksual eksplisit atau pornografi. Pakaian renang di pantai, pakaian olahraga, dan foto liburan biasa BUKAN pelanggaran seksual.
3. kekerasan: kekerasan nyata yang grafis (darah, luka, orang disakiti). Olahraga bela diri resmi, poster film, dan screenshot video game adalah konteks fiksi atau olahraga, BUKAN pelanggaran.
4. miras: PROMOSI minuman beralkohol: iklan, poster brand, foto produk yang diglamourkan untuk menjual. Alkohol yang muncul insidental dalam foto sosial (misalnya teman bersulang saat makan bersama) BUKAN pelanggaran.
5. berbahaya: senjata api atau senjata tajam dipamerkan sebagai produk atau kebanggaan, narkoba, atau aktivitas berbahaya yang diglamourkan. Pisau dapur saat memasak dan alat kerja normal BUKAN pelanggaran.

Balas HANYA dengan JSON valid tanpa teks lain, format persis:
{"judol": 0, "seksual": 0, "kekerasan": 0, "miras": 0, "berbahaya": 0, "alasan": "satu kalimat Bahasa Indonesia"}
PROMPT,
        // KeywordGate v1 (keputusan Jev 2026-11-17): teks hanya dikirim
        // ke model bila daftar ini atau pola kontak spam kena. Kena gate
        // BUKAN vonis, hanya pemicu penilaian model. Daftar ini sengaja
        // gampang ditambah; naikkan versi di komentar ini saat berubah
        // (leksikon v1, 2026-11-17).
        'keywords' => [
            'slot gacor', 'gacor', 'maxwin', 'judol', 'judi online',
            'situs judi', 'bandar judi', 'agen judi', 'togel',
            'depo pulsa', 'deposit pulsa', 'bonus new member',
            'rtp slot', 'pragmatic play', 'mahjong ways', 'scatter hitam',
            'zeus slot', 'slot olympus', 'parlay', 'mix parlay', 'sbobet',
            'casino online', 'kasino online', 'link alternatif',
            'jamin wd', 'wd cepat', 'garansi kekalahan', 'akun pro vip',
            'server thailand', 'slot88', 'slot777', 'raja slot',
            'dewa slot', 'agen slot', 'taruhan bola', 'judi bola',
            'freebet', 'freespin', 'jackpot progresif', 'chip gratis',
        ],
    ];

    return $config;
}
