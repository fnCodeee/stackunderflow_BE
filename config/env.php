<?php

/**
 * config/env.php
 * ─────────────────────────────────────────────────────────────────────────────
 * ENV LOADER  –  Parser .env sederhana tanpa library eksternal
 *
 * Cara kerja:
 *  - Baca file .env baris per baris
 *  - Skip baris kosong dan komentar (#)
 *  - Parse format KEY=VALUE
 *  - Daftarkan ke $_ENV, $_SERVER, dan putenv()
 *  - Sehingga bisa diakses dengan: $_ENV['DB_HOST'] atau getenv('DB_HOST')
 *
 * Dibuat sederhana agar mudah dipahami; tidak menggunakan vlucas/phpdotenv.
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

/**
 * Memuat file .env ke dalam environment PHP.
 *
 * @param  string $path  Path absolut ke file .env
 * @return void
 * @throws RuntimeException jika file .env tidak ditemukan
 */
function loadEnv(string $path): void
{
    if (!file_exists($path)) {
        throw new RuntimeException(
            "File .env tidak ditemukan di: {$path}\n" .
            "Salin .env.example menjadi .env dan isi kredensial yang diperlukan."
        );
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $line) {
        $line = trim($line);

        // Skip komentar dan baris kosong
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        // Hanya proses baris yang mengandung '='
        if (!str_contains($line, '=')) {
            continue;
        }

        // Pisahkan KEY dan VALUE pada '=' pertama
        // Gunakan limit 2 agar nilai yang mengandung '=' tidak terpotong
        [$key, $value] = explode('=', $line, 2);

        $key   = trim($key);
        $value = trim($value);

        // Hapus tanda kutip di awal dan akhir value (jika ada)
        // Contoh: APP_NAME="My App" → My App
        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        // Daftarkan ke tiga tempat agar bisa diakses dengan cara apapun
        if (!array_key_exists($key, $_ENV)) {
            $_ENV[$key]    = $value;
            $_SERVER[$key] = $value;
            putenv("{$key}={$value}");
        }
    }
}

// ── Panggil loader dengan path ke .env di root project ───────────────────────
loadEnv(BASE_PATH . '/.env');
