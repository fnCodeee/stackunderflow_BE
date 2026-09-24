<?php

/**
 * public/index.php
 * ─────────────────────────────────────────────────────────────────────────────
 * FRONT CONTROLLER  –  Single Entry Point Aplikasi PHP Native
 *
 * Semua HTTP request diarahkan ke file ini melalui:
 *   php -S localhost:PORT -t public
 *
 * Flow eksekusi (urutan WAJIB, jangan diubah):
 *
 *   Request
 *     ↓
 *   [1] define BASE_PATH          → root folder `backend-php/`
 *     ↓
 *   [2] load config/env.php       → baca .env, isi $_ENV
 *     ↓
 *   [3] load config/cors.php      → kirim header CORS
 *                                   jika OPTIONS → exit() langsung
 *     ↓
 *   [4] load utils/Response.php   → class helper Response tersedia
 *     ↓
 *   [5] load config/database.php  → fungsi getDB() tersedia
 *     ↓
 *   [6] parse JSON body           → $GLOBALS['body'] = array dari php://input
 *     ↓
 *   [7] load routes/Router.php    → class Router tersedia
 *     ↓
 *   [8] new Router()              → buat instance router
 *     ↓
 *   [9] load routes/api.php       → daftarkan semua route ke $router
 *     ↓
 *  [10] $router->dispatch()       → cocokkan URI + method → jalankan handler
 *     ↓
 *   Response JSON ke client
 *
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

// ── [1] Konstanta Base Path ───────────────────────────────────────────────────
// BASE_PATH menunjuk ke folder root backend-php/ (satu level di atas public/)
// Digunakan oleh semua file lain sebagai anchor path agar tidak ada hard-coded path.
define('BASE_PATH', dirname(__DIR__));

// ── [2] Load environment variables ───────────────────────────────────────────
// Membaca .env dan mengisi $_ENV sehingga konfigurasi tersedia di seluruh aplikasi.
// Harus dipanggil pertama kali sebelum komponen lain yang butuh env var.
require_once BASE_PATH . '/config/env.php';

// ── [3] Handle CORS ───────────────────────────────────────────────────────────
// Mengirim header CORS ke setiap response.
// Jika request adalah OPTIONS (browser preflight), langsung balas 200 dan exit().
// Harus dipanggil sebelum output apapun ke client.
require_once BASE_PATH . '/config/cors.php';

// ── [4] Load Response helper ─────────────────────────────────────────────────
// Class Response::json(), Response::success(), Response::error()
// Tersedia untuk digunakan oleh route handler dan error handler.
require_once BASE_PATH . '/utils/Response.php';

// ── [5] Load database helper ──────────────────────────────────────────────────
// Fungsi getDB() tersedia — membuat koneksi PDO ke MySQL.
// Koneksi belum dibuat di sini; hanya fungsinya yang di-load.
// Koneksi baru dibuat saat pertama kali getDB() dipanggil oleh route handler.
require_once BASE_PATH . '/config/database.php';

// ── [6] Parse JSON request body ───────────────────────────────────────────────
//
// ALASAN PENTING:
// PHP Native TIDAK otomatis mem-parse `Content-Type: application/json`.
// $_POST hanya ter-populate untuk form-urlencoded dan multipart/form-data.
// Frontend mengirim JSON payload → wajib baca dari php://input secara manual.
//
// $GLOBALS['body'] tersedia sebagai associative array di semua handler.
// Untuk request tanpa body (GET, DELETE), $GLOBALS['body'] = [].
//
$GLOBALS['body'] = [];
$rawInput = file_get_contents('php://input');
if (!empty($rawInput)) {
    $decoded = json_decode($rawInput, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        $GLOBALS['body'] = $decoded;
    }
}

// ── [7] Load Router class ─────────────────────────────────────────────────────
require_once BASE_PATH . '/routes/Router.php';

// ── [8] Buat instance Router ──────────────────────────────────────────────────
$router = new Router();

// ── [9] Load route registration ───────────────────────────────────────────────
// routes/api.php mendaftarkan semua route ke $router.
// $router tersedia karena berada di scope yang sama (bukan di dalam function/class).
require_once BASE_PATH . '/routes/api.php';

// ── [10] Dispatch request ────────────────────────────────────────────────────
// Router memeriksa $_SERVER['REQUEST_METHOD'] dan $_SERVER['REQUEST_URI'],
// mencocokkan dengan tabel route yang sudah terdaftar,
// mengekstrak path params (misal :postId, :userId, :id),
// dan memanggil handler yang sesuai.
// Jika tidak ada route yang cocok → 404 JSON.
$router->dispatch();
