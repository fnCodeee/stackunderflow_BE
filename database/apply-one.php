<?php

declare(strict_types=1);

/**
 * database/apply-one.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Terapkan SATU file migrasi (dan catat di schema_migrations).
 * Dipakai saat migrate.php tidak boleh jalan penuh karena ada file
 * lama yang pending tapi sudah diterapkan manual (belum ditandai).
 *
 * Cara pakai (dari root repo backend):
 *   php database/apply-one.php 20261126_planner_wiring.sql
 * ─────────────────────────────────────────────────────────────────────────────
 */

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/config/database.php';

$name = $argv[1] ?? '';
if ($name === '' || str_contains($name, '/') || str_contains($name, '\\') || !str_ends_with($name, '.sql')) {
    echo "pakai: php database/apply-one.php <nama-file.sql>" . PHP_EOL;
    exit(1);
}
$file = __DIR__ . '/migrations/' . $name;
if (!is_file($file)) {
    echo "file tidak ada: $name" . PHP_EOL;
    exit(1);
}
$pdo = getDB();
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS schema_migrations (
        filename VARCHAR(255) PRIMARY KEY,
        applied_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
$done = $pdo->prepare("SELECT COUNT(*) FROM schema_migrations WHERE filename = ?");
$done->execute([$name]);
if ((int) $done->fetchColumn() > 0) {
    echo "sudah applied: $name" . PHP_EOL;
    exit(0);
}
$sql = file_get_contents($file);
if ($sql === false || trim($sql) === '') {
    echo "gagal membaca file: $name" . PHP_EOL;
    exit(1);
}
$pdo->exec($sql);
$record = $pdo->prepare("INSERT INTO schema_migrations (filename, applied_at) VALUES (?, ?)");
$record->execute([$name, date('Y-m-d H:i:s')]);
echo "applied: $name" . PHP_EOL;
