<?php

/**
 * config/ai_moderation.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Konfigurasi AI Moderator (spec-ai-moderator.md).
 *
 * Prompt kebijakan di file ini adalah aset konsistensi utama.
 * v1 = teks yang lulus bake-off model 2026-11-17 (Nemotron VL free
 * dan Qwen 3.8 Omni Flash sama-sama 20/20). v2 (2026-11-17 sore,
 * perintah user): kategori kata_kasar ditambah, dan SEMUA teks
 * dinilai model karena rencana user memang semua konten dicek,
 * bukan gambar saja. v3 (2026-11-17): kata_kasar tidak kenal
 * tingkat ringan, semua makian = pelanggaran berat (perintah
 * user). Setiap perubahan prompt WAJIB menaikkan
 * 'prompt_version'.
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
        // Provider kedua (2026-11-17): xKiro, agregator kompatibel
        // OpenAI. Model yang terdaftar di 'model_providers' dipanggil
        // lewat xKiro, sisanya lewat OpenRouter seperti sebelumnya.
        'xkiro_api_key' => (string) ($_ENV['XKIRO_API_KEY'] ?? ''),
        'xkiro_endpoint' => 'https://api.xkiro.com/v1/chat/completions',
        'model_providers' => [
            'mistralai/mistral-medium-3.5' => 'xkiro',
        ],
        // Rantai model: utama xKiro (free, jatah token harian) ->
        // free OpenRouter -> berbayar -> free cadangan OpenRouter.
        // Primary pindah dari Nemotron ke Mistral Medium 3.5 setelah
        // model itu lolos bake-off 20 gambar dan probe teks
        // 2026-11-17 dengan hasil setara Nemotron (perintah user).
        'models' => [
            'mistralai/mistral-medium-3.5',
            'nvidia/nemotron-3.5-super-vl:free',
            'qwen/qwen3.8-omni-flash',
            'inclusionai/ling-3.2-flash:free',
        ],
        'primary_model' => 'mistralai/mistral-medium-3.5',
        // Eskalasi zona abu-abu sekarang Nemotron (free OpenRouter).
        // Qwen berbayar menjadi eskalasi cadangan saat rem kuota
        // free OpenRouter aktif, sekaligus fallback rantai.
        'escalation_model' => 'nvidia/nemotron-3.5-super-vl:free',
        'escalation_fallback_model' => 'qwen/qwen3.8-omni-flash',
        // Model free yang memakan kuota free OpenRouter (50/hari
        // untuk akun di bawah $10 kredit). Rem di bawah menjaga
        // kuota ini, bukan lagi primary.
        'openrouter_free_models' => [
            'nvidia/nemotron-3.5-super-vl:free',
            'inclusionai/ling-3.2-flash:free',
        ],
        // Batas lunak verdict yang diputuskan model free OpenRouter
        // per hari. Mencapai angka ini menyisihkan model free
        // OpenRouter dari rantai dan memindahkan eskalasi ke Qwen.
        'free_daily_soft_cap' => 45,
        'threshold_remove' => 70,
        'threshold_grey' => 40,
        // Kata kasar tidak kenal tingkat ringan (user 2026-11-17):
        // skor kata_kasar mencapai angka ini selalu berujung hapus,
        // walau model menilainya di pita abu-abu.
        'profanity_remove_at' => 40,
        'max_tokens' => 1500,
        'timeout_seconds' => 60,
        'max_images_per_target' => 5,
        'max_attempts' => 5,
        'prompt_version' => 'v3',
        'prompt' => <<<'PROMPT'
Kamu adalah moderator konten untuk platform kolaborasi mahasiswa Indonesia bernama Underflow. Nilai konten yang diberikan (gambar, teks, atau keduanya) dengan skor 0-100 untuk enam kategori pelanggaran:

1. judol: promosi judi online. Penanda khas: mesin slot, kasino, kartu remi untuk taruhan, teks seperti "bonus new member", "slot gacor", "maxwin", "depo pulsa", "betting", "togel", nomor WhatsApp untuk pendaftaran situs judi, uang tunai dipamerkan sebagai iming-iming menang judi.
2. seksual: konten seksual eksplisit atau pornografi. Pakaian renang di pantai, pakaian olahraga, dan foto liburan biasa BUKAN pelanggaran seksual.
3. kekerasan: kekerasan nyata yang grafis (darah, luka, orang disakiti). Olahraga bela diri resmi, poster film, dan screenshot video game adalah konteks fiksi atau olahraga, BUKAN pelanggaran.
4. miras: PROMOSI minuman beralkohol: iklan, poster brand, foto produk yang diglamourkan untuk menjual. Alkohol yang muncul insidental dalam foto sosial (misalnya teman bersulang saat makan bersama) BUKAN pelanggaran.
5. berbahaya: senjata api atau senjata tajam dipamerkan sebagai produk atau kebanggaan, narkoba, atau aktivitas berbahaya yang diglamourkan. Pisau dapur saat memasak dan alat kerja normal BUKAN pelanggaran.

6. kata_kasar: kata kasar, makian, atau pelecehan verbal. Di platform ini kata kasar TIDAK mengenal tingkat ringan: setiap kata yang memang dipakai sebagai makian adalah pelanggaran berat, beri skor 70-100, baik berdiri sendiri sebagai seluruh isi, ditujukan ke orang atau kelompok tertentu, apalagi disertai ancaman. Pemakaian harfiah BUKAN makian dan BUKAN pelanggaran: kata "anjing" untuk hewan peliharaan, "babi" untuk hewan atau makanan, dan konteks serupa beri skor 0-20 untuk kategori ini.

Balas HANYA dengan JSON valid tanpa teks lain, format persis:
{"judol": 0, "seksual": 0, "kekerasan": 0, "miras": 0, "berbahaya": 0, "kata_kasar": 0, "alasan": "satu kalimat Bahasa Indonesia"}
PROMPT,
        // Semua teks selalu dikirim ke model (keputusan user
        // 2026-11-17): tidak ada lagi gerbang kata kunci. Leksikon
        // judol yang dulu dipakai KeywordGate dihapus bersama
        // gerbangnya; riwayatnya tersimpan di git.

    ];

    return $config;
}
