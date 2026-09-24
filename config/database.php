<?php

/**
 * config/database.php
 * ─────────────────────────────────────────────────────────────────────────────
 * DATABASE CONNECTION  –  PDO MySQL/MariaDB
 *
 * Cara penggunaan di Controller / Route handler:
 *
 *   $pdo = getDB();
 *   $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id");
 *   $stmt->execute([':id' => $userId]);
 *   $user = $stmt->fetch();
 *
 * Kenapa PDO?
 *  - Mendukung prepared statements → aman dari SQL injection
 *  - Compatible MySQL & MariaDB
 *  - Error mode EXCEPTION → error database selalu bisa di-catch
 *  - Standar PHP Native tanpa library eksternal
 *
 * Kenapa bukan singleton class yang kompleks?
 *  - Phase 1 hanya butuh koneksi yang bekerja
 *  - Mudah diubah/diperluas di phase berikutnya
 *  - Tidak menambahkan abstraksi yang belum diperlukan
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

/**
 * Membuat dan mengembalikan koneksi PDO ke database MySQL.
 *
 * Koneksi dibuat sekali dan di-cache di static variable
 * agar tidak membuka koneksi baru di setiap pemanggilan.
 *
 * @return PDO  Instance PDO yang siap digunakan
 * @throws RuntimeException jika koneksi database gagal
 */
function getDB(): PDO
{
    // Static variable: dibuat sekali, digunakan kembali pada pemanggilan berikutnya
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    // Baca konfigurasi dari environment variable
    $host     = $_ENV['DB_HOST']     ?? '127.0.0.1';
    $port     = $_ENV['DB_PORT']     ?? '3306';
    $dbName   = $_ENV['DB_NAME']     ?? 'db_stack_underflow';
    $user     = $_ENV['DB_USER']     ?? 'root';
    $password = $_ENV['DB_PASSWORD'] ?? '';

    // DSN (Data Source Name) — string koneksi PDO
    $dsn = "mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4";

    // Opsi PDO yang direkomendasikan:
    $options = [
        // Lempar PDOException saat ada error → bisa di-catch dengan try/catch
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,

        // Return data sebagai associative array (bukan object) secara default
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,

        // Matikan emulasi prepared statement → gunakan prepared statement native MySQL
        // Ini lebih aman dan efisien
        PDO::ATTR_EMULATE_PREPARES   => false,

        // Charset koneksi — pastikan konsisten dengan charset database (utf8mb4)
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
    ];

    try {
        $pdo = new PDO($dsn, $user, $password, $options);
    } catch (PDOException $e) {
        // JANGAN expose detail koneksi ke client!
        // Log error di server, kirim pesan generic ke client.
        error_log('[DB ERROR] Koneksi database gagal: ' . $e->getMessage());

        // Kirim response JSON error dan hentikan eksekusi
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Koneksi database gagal. Cek konfigurasi server.',
            'data'    => null,
        ]);
        exit();
    }

    return $pdo;
}
