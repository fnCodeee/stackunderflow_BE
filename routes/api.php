<?php

/**
 * routes/api.php
 * ─────────────────────────────────────────────────────────────────────────────
 * ROUTE REGISTRATION
 *
 * File ini mendaftarkan semua route ke instance $router yang sudah dibuat
 * di public/index.php.
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

require_once BASE_PATH . '/controllers/AuthController.php';

// ── Health Check: GET / ───────────────────────────────────────────────────────
$router->get('/', function (array $params): void {
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode('Server Berhasil dijalankan!', JSON_UNESCAPED_UNICODE);
    exit();
});

// ── Health Check: GET /api ────────────────────────────────────────────────────
$router->get('/api', function (array $params): void {
    $pdo = getDB();
    $stmt = $pdo->query('SELECT 1');
    $stmt->fetch();

    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode('hallo', JSON_UNESCAPED_UNICODE);
    exit();
});

// ── AUTH MODULE (Phase 2B) ───────────────────────────────────────────────────
$router->post('/api/auth/register', [AuthController::class, 'register']);
