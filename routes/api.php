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
require_once BASE_PATH . '/controllers/CollabController.php';
require_once BASE_PATH . '/controllers/SkillController.php';
require_once BASE_PATH . '/controllers/UserController.php';
require_once BASE_PATH . '/controllers/UploadController.php';
require_once BASE_PATH . '/controllers/NotificationController.php';
require_once BASE_PATH . '/controllers/InboxController.php';

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

// Google sign-in (ID token dari Google Identity Services)
$router->post('/api/auth/google', [AuthController::class, 'google']);

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

// Phase 3D-1b: Record View (auth required — dipanggil saat halaman detail post dibuka)
$router->post('/api/posts/:postId/view', [AuthMiddleware::class, 'handle'], [PostController::class, 'recordView']);

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

// Like / Unlike Comment (auth required) — persisted like post likes.
$router->post('/api/comments/:commentId/like', [AuthMiddleware::class, 'handle'], [PostController::class, 'likeComment']);
$router->delete('/api/comments/:commentId/unlike', [AuthMiddleware::class, 'handle'], [PostController::class, 'unlikeComment']);

// ── NOTIFICATIONS MODULE ──────────────────────────────────────────────────────
// GET /api/notifications: daftar notifikasi milik user login (auth required)
$router->get('/api/notifications', [AuthMiddleware::class, 'handle'], [NotificationController::class, 'listNotifications']);

// PUT /api/notifications/:id/read: tandai satu notifikasi dibaca (auth required)
$router->put('/api/notifications/:id/read', [AuthMiddleware::class, 'handle'], [NotificationController::class, 'markRead']);

// POST /api/notifications/read-all: tandai semua notifikasi dibaca (auth required)
$router->post('/api/notifications/read-all', [AuthMiddleware::class, 'handle'], [NotificationController::class, 'markAllRead']);

// ── INBOX / DM MODULE ─────────────────────────────────────────────────────────
// Inbox persisten di atas tabel dm_* (lihat InboxController / InboxModel).
$router->get('/api/inbox/summary', [AuthMiddleware::class, 'handle'], [InboxController::class, 'summary']);
$router->get('/api/inbox/conversations', [AuthMiddleware::class, 'handle'], [InboxController::class, 'listConversations']);
$router->post('/api/inbox/conversations', [AuthMiddleware::class, 'handle'], [InboxController::class, 'createConversation']);
$router->get('/api/inbox/conversations/:id/messages', [AuthMiddleware::class, 'handle'], [InboxController::class, 'listMessages']);
$router->post('/api/inbox/conversations/:id/messages', [AuthMiddleware::class, 'handle'], [InboxController::class, 'sendMessage']);
$router->post('/api/inbox/conversations/:id/read', [AuthMiddleware::class, 'handle'], [InboxController::class, 'markRead']);
$router->put('/api/inbox/conversations/:id/mute', [AuthMiddleware::class, 'handle'], [InboxController::class, 'toggleMute']);
$router->put('/api/inbox/conversations/:id/pin', [AuthMiddleware::class, 'handle'], [InboxController::class, 'togglePin']);
$router->post('/api/inbox/conversations/:id/clear', [AuthMiddleware::class, 'handle'], [InboxController::class, 'clearConversation']);
$router->post('/api/inbox/conversations/:id/restore', [AuthMiddleware::class, 'handle'], [InboxController::class, 'restoreConversation']);
$router->delete('/api/inbox/conversations/:id', [AuthMiddleware::class, 'handle'], [InboxController::class, 'hideConversation']);

// ── UPLOAD ──────────────────────────────────────────────────────────────────
// Upload gambar ke Cloudinary (auth required) — dipakai PostModal.
$router->post('/api/upload', [AuthMiddleware::class, 'handle'], [UploadController::class, 'uploadImage']);
// Tanda tangan untuk upload langsung ke Cloudinary (auth required) - file tidak lewat server.
$router->post('/api/upload/signature', [AuthMiddleware::class, 'handle'], [UploadController::class, 'signature']);

// ── COLLABORATIONS MODULE ─────────────────────────────────────────────────────
// Phase 4C: Create Collaboration + Auto-create Circle
$router->post('/api/collab', [AuthMiddleware::class, 'handle'], [CollabController::class, 'createCollab']);

// Phase 4D: Read Collaborations — PUBLIC (OptionalAuth) since 2026-11-10:
// the frontend pages are server components and cannot send the
// localStorage token; browsing collabs is public, like the feed.
$router->get('/api/collab', [OptionalAuthMiddleware::class, 'handle'], [CollabController::class, 'showCollab']);
$router->get('/api/collab/:id', [OptionalAuthMiddleware::class, 'handle'], [CollabController::class, 'getCollabDetail']);
$router->get('/api/profiles/:userId/collab', [AuthMiddleware::class, 'handle'], [CollabController::class, 'yourCollabPost']);

// My Collaborations board (added 2026-11-10): the current user's
// collabs with myStatus.
$router->get('/api/my-collaborations', [AuthMiddleware::class, 'handle'], [CollabController::class, 'getMyCollaborations']);

// Phase 4E: Update Collaboration (auth required — sesuai Express baseline)
$router->put('/api/collab/:id', [AuthMiddleware::class, 'handle'], [CollabController::class, 'updateCollab']);

// Phase 4F: Delete Collaboration (auth required — sesuai Express baseline)
$router->delete('/api/collab/:id', [AuthMiddleware::class, 'handle'], [CollabController::class, 'deleteCollab']);

// Batch 2: apply + membership (audit P1-3, P1-5)
$router->post('/api/collab/:id/apply', [AuthMiddleware::class, 'handle'], [CollabController::class, 'applyToCollab']);
$router->get('/api/collab/:id/applications', [AuthMiddleware::class, 'handle'], [CollabController::class, 'listApplications']);
$router->put('/api/collab/:id/applications/:applicationId/accept', [AuthMiddleware::class, 'handle'], [CollabController::class, 'acceptApplication']);
$router->put('/api/collab/:id/applications/:applicationId/decline', [AuthMiddleware::class, 'handle'], [CollabController::class, 'declineApplication']);
$router->delete('/api/collab/:id/apply', [AuthMiddleware::class, 'handle'], [CollabController::class, 'withdrawApplication']);

// ── SKILLS MODULE ─────────────────────────────────────────────────────────────
// GET /api/skills: skill system + private skill milik user yang sedang login
$router->get('/api/skills', [AuthMiddleware::class, 'handle'], [SkillController::class, 'getSkills']);

// POST /api/skills: buat private skill (auth required)
$router->post('/api/skills', [AuthMiddleware::class, 'handle'], [SkillController::class, 'createSkill']);

// DELETE /api/skills/:id: hapus private skill milik user login (auth required)
$router->delete('/api/skills/:id', [AuthMiddleware::class, 'handle'], [SkillController::class, 'deleteSkill']);

// ── USERS MODULE ──────────────────────────────────────────────────────────────
// GET /api/users/:id: public person detail for the profile page
// (added 2026-11-10; "me" resolves to the logged-in user).
$router->get('/api/users/:id', [OptionalAuthMiddleware::class, 'handle'], [UserController::class, 'getUser']);

