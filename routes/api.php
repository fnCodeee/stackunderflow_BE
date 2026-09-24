<?php

/**
 * routes/api.php
 * ─────────────────────────────────────────────────────────────────────────────
 * ROUTE REGISTRATION  –  Phase 1 (Health Check Only)
 *
 * File ini mendaftarkan semua route ke instance $router yang sudah dibuat
 * di public/index.php.
 *
 * Phase 1 hanya mendaftarkan 2 endpoint health check:
 *   GET /      → "Server Berhasil dijalankan!"
 *   GET /api   → "hallo" (+ verifikasi koneksi DB)
 *
 * Phase 2+: Tambahkan route API bisnis di sini secara bertahap.
 *
 * Referensi urutan pendaftaran route (dari api.js Express — JANGAN ubah order ini):
 *   /api/collab/inbox harus didaftarkan SEBELUM /api/collab/:id
 *   Router sudah menangani ini secara otomatis (static before dynamic),
 *   tapi tetap pertahankan urutan pendaftaran yang benar sebagai best practice.
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

// $router sudah tersedia karena file ini di-require dari public/index.php
// setelah $router = new Router() dibuat.

// ── Health Check: GET / ───────────────────────────────────────────────────────
// Setara dengan: app.get("/", (req, res) => res.json("Server Berhasil dijalankan!"))
// di server.js Express
$router->get('/', function (array $params): void {
    // Response berupa string langsung (bukan objek), mengikuti behavior Express
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode('Server Berhasil dijalankan!', JSON_UNESCAPED_UNICODE);
    exit();
});

// ── Health Check: GET /api ────────────────────────────────────────────────────
// Setara dengan: router.get("/", (req, res) => res.json("hallo"))
// di api.js Express
//
// Ditambahkan: verifikasi koneksi database sebagai bukti bootstrap berhasil.
// Jika DB gagal connect, getDB() sudah handle error dan exit() sendiri.
$router->get('/api', function (array $params): void {
    // Verifikasi koneksi database
    // getDB() akan throw exception (dan exit) jika koneksi gagal
    $pdo = getDB();

    // Verifikasi tambahan: ping database dengan query ringan
    $stmt = $pdo->query('SELECT 1');
    $stmt->fetch();

    // DB OK → kirim response "hallo"
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode('hallo', JSON_UNESCAPED_UNICODE);
    exit();
});

// ─────────────────────────────────────────────────────────────────────────────
// PHASE 2+ — Route bisnis akan ditambahkan di sini
// Contoh struktur yang akan datang:
//
// require_once BASE_PATH . '/middlewares/AuthMiddleware.php';
// require_once BASE_PATH . '/controllers/AuthController.php';
//
// $router->post('/api/auth/register', [AuthController::class, 'register']);
// $router->post('/api/auth/login', [AuthController::class, 'login']);
// $router->get('/api/profiles/me', $authMiddleware, [AuthController::class, 'me']);
// ... dst sesuai urutan di api.js Express
// ─────────────────────────────────────────────────────────────────────────────
