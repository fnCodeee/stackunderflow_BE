<?php

declare(strict_types=1);

/**
 * database/migrate.php
 * ─────────────────────────────────────────────────────────────────────────────
 * MIGRATION RUNNER (audit P2 #26)
 *
 * Menjalankan file SQL di database/migrations/ yang belum
 * tercatat di tabel schema_migrations, urut nama file.
 *
 * Cara pakai (dari root repo backend):
 *   php database/migrate.php            terapkan yang pending
 *   php database/migrate.php status     daftar applied/pending
 *   php database/migrate.php --mark-all catat semua sebagai
 *                                       applied TANPA menjalankan
 *                                       (untuk database yang
 *                                       migrasinya sudah pernah
 *                                       diterapkan manual)
 *
 * File migrasi baru: simpan sebagai YYYYMMDD_nama.sql di
 * database/migrations/, lalu jalankan perintah pertama.
 * ─────────────────────────────────────────────────────────────────────────────
 */

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/config/database.php';

$pdo = getDB();
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS schema_migrations (
        filename VARCHAR(255) PRIMARY KEY,
        applied_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$files = glob(__DIR__ . '/migrations/*.sql') ?: [];
sort($files);
$applied = $pdo->query("SELECT filename FROM schema_migrations")
    ->fetchAll(PDO::FETCH_COLUMN);
$mode = $argv[1] ?? 'apply';

$record = $pdo->prepare(
    "INSERT INTO schema_migrations (filename, applied_at) VALUES (?, ?)"
);

foreach ($files as $file) {
    $name = basename($file);
    $done = in_array($name, $applied, true);

    if ($mode === 'status') {
        echo ($done ? '[applied] ' : '[pending] ') . $name . PHP_EOL;
        continue;
    }
    if ($done) {
        echo "skip (sudah applied): $name" . PHP_EOL;
        continue;
    }
    if ($mode === '--mark-all') {
        $record->execute([$name, date('Y-m-d H:i:s')]);
        echo "marked: $name" . PHP_EOL;
        continue;
    }
    $sql = file_get_contents($file);
    if ($sql === false || trim($sql) === '') {
        echo "gagal membaca file: $name" . PHP_EOL;
        exit(1);
    }
    $pdo->exec($sql);
    $record->execute([$name, date('Y-m-d H:i:s')]);
    echo "applied: $name" . PHP_EOL;
}

echo "Selesai." . PHP_EOL;
