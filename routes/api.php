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

require_once BASE_PATH . '/middlewares/AuthMiddleware.php';
require_once BASE_PATH . '/middlewares/OptionalAuthMiddleware.php';
require_once BASE_PATH . '/controllers/AuthController.php';
require_once BASE_PATH . '/controllers/ProfileController.php';
require_once BASE_PATH . '/controllers/PostController.php';

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

// ── AUTH MODULE ───────────────────────────────────────────────────────────────
// Phase 2B: Register
$router->post('/api/auth/register', [AuthController::class, 'register']);

// Phase 2C: Login
$router->post('/api/auth/login', [AuthController::class, 'login']);

// ── PROFILES MODULE ───────────────────────────────────────────────────────────
// Phase 2D-1: Get My Profile
$router->get('/api/profiles/me', [AuthMiddleware::class, 'handle'], [AuthController::class, 'me']);

// Phase 2D-2: Update My Profile
$router->put('/api/profiles/me', [AuthMiddleware::class, 'handle'], [ProfileController::class, 'updateProfile']);

// ── FEEDS / POST MODULE ───────────────────────────────────────────────────────
// Phase 3B: Create Post
$router->post('/api/posts', [AuthMiddleware::class, 'handle'], [PostController::class, 'createPost']);

// Phase 3C: Get All Posts / Showcase Feed (optional auth — publik dapat mengakses)
$router->get('/api/posts', [OptionalAuthMiddleware::class, 'handle'], [PostController::class, 'showCase']);

// Phase 3D-1: Get Post Detail (auth required — sesuai Express baseline)
$router->get('/api/posts/:postId', [AuthMiddleware::class, 'handle'], [PostController::class, 'getPostDetail']);

// Phase 3D-2: Update Post (auth required — sesuai Express baseline)
$router->put('/api/posts/:postId', [AuthMiddleware::class, 'handle'], [PostController::class, 'updatePost']);

// Phase 3D-3: Delete Post (auth required — sesuai Express baseline)
$router->delete('/api/posts/:postId', [AuthMiddleware::class, 'handle'], [PostController::class, 'deletePost']);

// Phase 3D-4: Like / Unlike Post (auth required — sesuai Express baseline)
$router->post('/api/posts/:postId/like', [AuthMiddleware::class, 'handle'], [PostController::class, 'likePost']);
$router->delete('/api/posts/:postId/unlike', [AuthMiddleware::class, 'handle'], [PostController::class, 'unlikePost']);

// Phase 3D-5: Comments (auth required — sesuai Express baseline)
$router->post('/api/posts/:postId/comments', [AuthMiddleware::class, 'handle'], [PostController::class, 'createComment']);
$router->get('/api/posts/:postId/comments', [AuthMiddleware::class, 'handle'], [PostController::class, 'getComments']);
$router->delete('/api/delete/comments/:commentId', [AuthMiddleware::class, 'handle'], [PostController::class, 'deleteComment']);
$router->delete('/api/posts/:postId/comments/:commentId', [AuthMiddleware::class, 'handle'], [PostController::class, 'deleteComment']);

