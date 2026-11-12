<?php

/**
 * controllers/CollabController.php
 * ─────────────────────────────────────────────────────────────────────────────
 * COLLABORATION CONTROLLER
 *
 * Menangani operasi kolaborasi pengguna:
 *  - createCollab() / addCollab() -> Phase 4C (POST /api/collab)
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

require_once BASE_PATH . '/models/CollabModel.php';
require_once BASE_PATH . '/models/InboxModel.php';
require_once BASE_PATH . '/models/SkillModel.php';
require_once BASE_PATH . '/utils/Response.php';

class CollabController
{
    /**
     * Handler untuk POST /api/collab
     * Membuat postingan kolaborasi baru beserta media, skills, dan otomatis membuat satu circle.
     *
     * @param  array<string, string> $params Path parameters dari Router
     * @return void
     */
    public static function createCollab(array $params = []): void
    {
        try {
            $userId = $GLOBALS['user']['id'] ?? null;

            if (!$userId) {
                Response::json(null, 401, 'Unauthorized', false);
            }

            // 1. Ambil request body
            $body = $GLOBALS['body'] ?? [];
            if (empty($body)) {
                $raw = file_get_contents('php://input');
                if (!empty($raw)) {
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded)) {
                        $body = $decoded;
                    }
                }
            }

            $title          = trim((string)($body['title'] ?? ''));
            $description    = trim((string)($body['description'] ?? ''));
            $type           = $body['type'] ?? null;
            $dueDate        = $body['due_date'] ?? $body['dueDate'] ?? null;
            $requiredMember = $body['required_member'] ?? $body['requiredMember'] ?? null;
            $mediaUrls      = $body['media_urls'] ?? $body['mediaUrls'] ?? [];
            $mediaPublicIds = $body['media_public_ids'] ?? $body['mediaPublicIds'] ?? [];
            $skillsNeeded   = $body['skills_needed'] ?? $body['skillsNeeded'] ?? null;

            // 2. Validasi field wajib sesuai baseline Express (kecuali communicationUrl yang sudah deprecated)
            if (
                $title === '' ||
                $description === '' ||
                $requiredMember === null ||
                $requiredMember === '' ||
                $skillsNeeded === null ||
                !is_array($skillsNeeded) ||
                count($skillsNeeded) === 0
            ) {
                Response::json(null, 400, 'Seluruh Kolom wajib diisi.', false);
            }

            $requiredMemberInt = (int)$requiredMember;
            if ($requiredMemberInt < 1 || $requiredMemberInt > 10) {
                Response::json(null, 400, 'requiredMember harus angka 1-10', false);
            }

            // Validasi due_date: tidak boleh di masa lalu (bandingkan tanggal saja)
            if ($dueDate !== null && $dueDate !== '') {
                $dueTimestamp = strtotime((string)$dueDate);
                if ($dueTimestamp === false) {
                    Response::json(null, 400, 'Format due_date tidak valid', false);
                }
                if (date('Y-m-d', $dueTimestamp) < date('Y-m-d')) {
                    Response::json(null, 400, 'due_date tidak boleh di masa lalu', false);
                }
            }

            // 3. Validasi konsistensi jumlah mediaUrls dan mediaPublicIds jika mediaPublicIds dikirim
            if (is_array($mediaPublicIds) && count($mediaPublicIds) > 0 && count($mediaUrls) !== count($mediaPublicIds)) {
                Response::json(null, 400, 'Count of mediaUrls and mediaPublicIds must be same!', false);
            }

            // 4. Validasi format dan keberadaan skills di tabel master skills
            $normalizedSkillIds = [];
            foreach ($skillsNeeded as $item) {
                if (is_string($item)) {
                    $sId = trim($item);
                } elseif (is_array($item)) {
                    $sId = trim((string)($item['id'] ?? $item['skill_id'] ?? ''));
                } else {
                    $sId = '';
                }

                if ($sId !== '') {
                    $normalizedSkillIds[] = $sId;
                }
            }

            if (empty($normalizedSkillIds)) {
                Response::json(null, 400, 'Seluruh Kolom wajib diisi.', false);
            }

            $uniqueSkillIds = array_values(array_unique($normalizedSkillIds));
            $pdo = getDB();
            $placeholders = implode(',', array_fill(0, count($uniqueSkillIds), '?'));
            $checkSkillStmt = $pdo->prepare("SELECT id FROM skills WHERE id IN ($placeholders)");
            $checkSkillStmt->execute($uniqueSkillIds);
            $foundSkillIds = $checkSkillStmt->fetchAll(PDO::FETCH_COLUMN);

            if (count($foundSkillIds) !== count($uniqueSkillIds)) {
                Response::json(null, 400, 'not found', false);
            }

            // 4b. Validasi kepemilikan skill: system boleh semua user, private hanya pemilik
            $skillValidationError = SkillModel::validateUsageForUser($uniqueSkillIds, $userId);
            if ($skillValidationError !== null) {
                Response::json(null, 400, $skillValidationError, false);
            }

            // 5. Simpan ke database melalui CollabModel dalam transaksi atomik (collab + media + skills + circle)
            $result = CollabModel::create([
                'user_id'          => $userId,
                'title'            => $title,
                'description'      => $description,
                'type'             => $type,
                'due_date'         => $dueDate,
                'required_member'  => $requiredMemberInt,
                'media_urls'       => is_array($mediaUrls) ? array_values($mediaUrls) : [],
                'media_public_ids' => is_array($mediaPublicIds) ? array_values($mediaPublicIds) : [],
                'skills_needed'    => $uniqueSkillIds,
            ]);

            Response::json($result, 200, 'Collaboration created successfully', true);
        } catch (InvalidArgumentException $e) {
            Response::json(null, 400, $e->getMessage(), false);
        } catch (Throwable $e) {
            error_log('[COLLAB ERROR] ' . $e->getMessage());
            Response::json(null, 500, 'Terjadi kesalahan pada server saat membuat kolaborasi', false);
        }
    }

    /**
     * Alias method addCollab sesuai nama method di Express controller
     *
     * @param  array<string, string> $params
     * @return void
     */
    public static function addCollab(array $params = []): void
    {
        self::createCollab($params);
    }

    /**
     * Handler untuk GET /api/collab
     * List postingan kolaborasi dengan pagination + search + sort (audit P2-12).
     *
     * Query params:
     *  - page  (default 1): halaman yang diminta.
     *  - limit (default 12): item per halaman.
     *  - q     (default ''): cari di title/description (LIKE case-insensitive).
     *  - sort  (default newest): newest | oldest | due.
     *
     * Validasi page/limit memakai CLAMP, bukan 400: nilai di luar rentang
     * dijepit (page < 1 → 1, limit < 1 → 1, limit > 50 → 50; bukan angka →
     * default). List endpoint bersifat read-only — parameter jelek tidak
     * boleh membuat seluruh halaman gagal. Sort di luar daftar → newest.
     * Response mempertahankan envelope {success, message, data} dan
     * menambah `pagination: {page, limit, total, total_pages}`.
     *
     * @param  array<string, string> $params Path parameters dari Router
     * @return void
     */
    public static function showCollab(array $params = []): void
    {
        try {
            // Public listing: readable without a token (OptionalAuth).
            $pageRaw = $_GET['page'] ?? null;
            $limitRaw = $_GET['limit'] ?? null;
            $page = $pageRaw !== null && filter_var($pageRaw, FILTER_VALIDATE_INT) !== false
                ? max(1, (int) $pageRaw)
                : 1;
            $limit = $limitRaw !== null && filter_var($limitRaw, FILTER_VALIDATE_INT) !== false
                ? min(50, max(1, (int) $limitRaw))
                : 12;

            $q = trim((string) ($_GET['q'] ?? ''));

            $sort = (string) ($_GET['sort'] ?? 'newest');
            if (!in_array($sort, ['newest', 'oldest', 'due'], true)) {
                $sort = 'newest';
            }

            $pageResult = CollabModel::getPage($q, $sort, $page, $limit);

            // my_application per item untuk user login (null kalau
            // tidak login / belum pernah apply) — kartu di daftar
            // menampilkan Pending / Joined, bukan tombol Apply lagi.
            $myUserId = $GLOBALS['user']['id'] ?? null;
            if ($myUserId !== null && $pageResult['data'] !== []) {
                $statusMap = CollabModel::applicationStatusesForUser(
                    (string) $myUserId,
                    array_column($pageResult['data'], 'id')
                );
                foreach ($pageResult['data'] as &$item) {
                    $item['my_application'] = isset($statusMap[$item['id']])
                        ? ['status' => $statusMap[$item['id']]]
                        : null;
                }
                unset($item);
            }

            Response::json(
                $pageResult['data'],
                200,
                'Get All Posts Collaboration!',
                true,
                $pageResult['pagination']
            );
        } catch (Throwable $e) {
            error_log('[COLLAB ERROR] ' . $e->getMessage());
            Response::json(null, 500, 'Terjadi kesalahan pada server saat mengambil data kolaborasi', false);
        }
    }

    /**
     * Handler untuk GET /api/collab/:id
     * Mengambil detail postingan kolaborasi berdasarkan ID.
     *
     * @param  array<string, string> $params Path parameters dari Router (memuat 'id')
     * @return void
     */
    public static function getCollabDetail(array $params = []): void
    {
        try {
            // Public detail: readable without a token (OptionalAuth).
            $collabId = $params['id'] ?? '';
            if ($collabId === '') {
                Response::json(null, 404, 'Collaboration not found', false);
            }

            $collab = CollabModel::findDetailById($collabId);
            if (!$collab) {
                Response::json(null, 404, 'Collaboration not found', false);
            }

            // my_application: status aplikasi user login di collab ini
            // (null kalau tidak login / belum pernah apply). Status DB
            // 'rejected' sudah dipetakan ke 'declined' oleh model.
            $myUserId = $GLOBALS['user']['id'] ?? null;
            $collab['my_application'] = null;
            if ($myUserId !== null) {
                $myApp = CollabModel::findApplication($collabId, (string) $myUserId);
                if ($myApp !== null) {
                    $collab['my_application'] = ['status' => $myApp['status']];
                }
            }

            Response::json($collab, 200, 'Detail collaboration', true);
        } catch (Throwable $e) {
            error_log('[COLLAB ERROR] ' . $e->getMessage());
            Response::json(null, 500, 'Terjadi kesalahan pada server saat mengambil detail kolaborasi', false);
        }
    }

    /**
     * Handler untuk GET /api/profiles/:userId/collab
     * Mengambil semua postingan kolaborasi milik user tertentu.
     *
     * @param  array<string, string> $params Path parameters dari Router (memuat 'userId')
     * @return void
     */
    public static function yourCollabPost(array $params = []): void
    {
        try {
            $userId = $GLOBALS['user']['id'] ?? null;

            if (!$userId) {
                Response::json(null, 401, 'Unauthorized', false);
            }

            $targetUserId = $params['userId'] ?? '';
            if ($targetUserId === '') {
                Response::json(null, 404, 'Collaboration Post not found!', false);
            }

            // Daftar kolaborasi user mana pun boleh dilihat (bukan hanya milik sendiri);
            // user tanpa kolaborasi mendapat list kosong, bukan 404.
            $collabs = CollabModel::getByUserId($targetUserId);
            if (empty($collabs)) {
                Response::json([], 200, 'No Collaboration Post found', true);
            }

            $username = $collabs[0]['userId']['username'] ?? '';
            Response::json($collabs, 200, 'Your Collaboration Post, ' . $username, true);
        } catch (Throwable $e) {
            error_log('[COLLAB ERROR] ' . $e->getMessage());
            Response::json(null, 500, 'Terjadi kesalahan pada server saat mengambil kolaborasi pengguna', false);
        }
    }

    /**
     * Handler untuk GET /api/my-collaborations
     * Mengambil collaboration yang dimiliki user, yang user ikuti sebagai
     * anggota, atau yang user ajukan (pending) — beserta myStatus.
     *
     * @param  array<string, string> $params Path parameters dari Router
     * @return void
     */
    public static function getMyCollaborations(array $params = []): void
    {
        try {
            $userId = $GLOBALS['user']['id'] ?? null;

            if (!$userId) {
                Response::json(null, 401, 'Unauthorized', false);
            }

            $collabs = CollabModel::getByMemberId($userId);
            Response::json($collabs, 200, 'Your Collaborations!', true);
        } catch (Throwable $e) {
            error_log('[COLLAB ERROR] ' . $e->getMessage());
            Response::json(null, 500, 'Terjadi kesalahan pada server saat mengambil kolaborasi saya', false);
        }
    }

    /**
     * Handler untuk PUT /api/collab/:id
     * Memperbarui postingan kolaborasi yang dibuat oleh user.
     *
     * Mengikuti kontrak Express (updateCollab):
     * - Auth -> ownership check -> update optional fields
     * - 404 jika tidak ditemukan
     * - 403 jika bukan owner
     * - communicationUrl tidak lagi digunakan
     * - Update valid untuk title, description, requiredMember, mediaUrls, mediaPublicIds, skillsNeeded
     *
     * @param array<string, string> $params Path parameters dari Router (memuat 'id')
     * @return void
     */
    public static function updateCollab(array $params = []): void
    {
        try {
            $userId = $GLOBALS['user']['id'] ?? null;

            if (!$userId) {
                Response::json(null, 401, 'Unauthorized', false);
            }

            $collabId = $params['id'] ?? '';

            if ($collabId === '') {
                Response::json(null, 400, 'Collaboration ID diperlukan', false);
            }

            // Ambil request body
            $body = $GLOBALS['body'] ?? [];
            if (empty($body)) {
                $raw = file_get_contents('php://input');
                if (!empty($raw)) {
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded)) {
                        $body = $decoded;
                    }
                }
            }

            $title          = trim((string)($body['title'] ?? ''));
            $description    = trim((string)($body['description'] ?? ''));
            $status         = trim((string)($body['status'] ?? ''));
            $requiredMember = $body['required_member'] ?? $body['requiredMember'] ?? null;
            $mediaUrls      = $body['media_urls'] ?? $body['mediaUrls'] ?? null;
            $mediaPublicIds = $body['media_public_ids'] ?? $body['mediaPublicIds'] ?? null;
            $skillsNeeded   = $body['skills_needed'] ?? $body['skillsNeeded'] ?? null;
            $dueDate        = $body['due_date'] ?? $body['dueDate'] ?? null;

            // Validasi field media (jika ada) mengikuti Express baseline
            // Jika disediakan, mediaUrls dan mediaPublicIds harus konsisten dan tidak kosong
            if (is_array($mediaPublicIds) && count($mediaPublicIds) > 0) {
                if (!is_array($mediaUrls) || count($mediaUrls) !== count($mediaPublicIds) || count($mediaUrls) === 0) {
                    Response::json(null, 400, 'Jika mediaPublicIds disediakan, mediaUrls dan mediaPublicIds harus sama dan tidak kosong', false);
                }
            }

            // CEK OWNERSHIP SEBELUM UPDATE (mirip Express: findById lalu compare user_id)
            $pdo = getDB();
            $checkStmt = $pdo->prepare("SELECT user_id, title FROM collaborations WHERE id = :id AND deleted_at IS NULL LIMIT 1");
            $checkStmt->execute([':id' => $collabId]);
            $existing = $checkStmt->fetch();

            if (!$existing) {
                Response::json(null, 404, 'Postingan kolaborasi tidak ditemukan!', false);
            }

            if ($existing['user_id'] !== $userId) {
                Response::json(null, 403, 'Bukan hak lu buat edit postingan ini!', false);
            }

            // Memilih field yang akan diupdate berdasarkan Express baseline (hanya jika diberikan, tidak undefined)
            $updateData = [];

            if ($title !== '' && $title !== null) {         // tinggal lowercase minimal terumum
                $updateData['title'] = $title;
            }
            if ($description !== '' && $description !== null) {
                $updateData['description'] = $description;
            }
            // Validasi status: hanya nilai ENUM collaborations.status yang diterima
            // (open / in-progress / completed — migration 20261110). Nilai lain
            // ditolak 400 SEBELUM ada tulisan apa pun, supaya tidak ada partial update.
            if ($status !== '' && $status !== null) {
                if (!in_array($status, ['open', 'in-progress', 'completed'], true)) {
                    Response::json(null, 400, 'Status tidak valid. Status harus open, in-progress, atau completed', false);
                }
                $updateData['status'] = $status;
            }
            if ($requiredMember !== null && $requiredMember !== '') {
                if (!is_numeric($requiredMember) || intval($requiredMember) < 1 || intval($requiredMember) > 10) {
                    Response::json(null, 400, 'requiredMember harus angka 1-10', false);
                }
                $updateData['required_member'] = (int)$requiredMember;
            }
            // due_date: kunci DIKIRIM dengan nilai kosong/null berarti
            // "hapus tanggal" (form edit bisa mengosongkannya); kunci
            // yang tidak dikirim sama sekali berarti "jangan diubah".
            $clearDueDate = false;
            if (array_key_exists('due_date', $body) || array_key_exists('dueDate', $body)) {
                if ($dueDate === null || trim((string)$dueDate) === '') {
                    $clearDueDate = true;
                } else {
                    $dueTimestamp = strtotime((string)$dueDate);
                    if ($dueTimestamp === false) {
                        Response::json(null, 400, 'Format due_date tidak valid', false);
                    }
                    if (date('Y-m-d', $dueTimestamp) < date('Y-m-d')) {
                        Response::json(null, 400, 'due_date tidak boleh di masa lalu', false);
                    }
                    $updateData['due_date'] = date('Y-m-d', $dueTimestamp);
                }
            }

            // Validasi skills jika disediakan — tanpa menulis apa pun dulu.
            $normalizedSkillIds = null;
            if (is_array($skillsNeeded) && count($skillsNeeded) > 0) {
                $normalizedSkillIds = [];
                foreach ($skillsNeeded as $skill) {
                    if (is_string($skill)) {
                        $skillId = trim($skill);
                    } elseif (is_array($skill) && isset($skill['id'])) {
                        $skillId = trim((string)$skill['id']);
                    } else {
                        $skillId = '';
                    }
                    if ($skillId !== '') {
                        $normalizedSkillIds[] = $skillId;
                    }
                }
                $normalizedSkillIds = array_values(array_unique($normalizedSkillIds));
                if (empty($normalizedSkillIds)) {
                    Response::json(null, 400, 'Terdapat skill ID yang tidak valid atau tidak ditemukan', false);
                }

                $placeholders = implode(',', array_fill(0, count($normalizedSkillIds), '?'));
                $checkSkillStmt = $pdo->prepare("SELECT id FROM skills WHERE id IN ($placeholders)");
                $checkSkillStmt->execute($normalizedSkillIds);
                $foundSkillIds = $checkSkillStmt->fetchAll(PDO::FETCH_COLUMN);

                if (count($foundSkillIds) !== count($normalizedSkillIds)) {
                    Response::json(null, 400, 'Terdapat skill ID yang tidak valid atau tidak ditemukan', false);
                }

                // Validasi kepemilikan skill: system boleh semua user, private hanya pemilik
                $skillValidationError = SkillModel::validateUsageForUser($normalizedSkillIds, $userId);
                if ($skillValidationError !== null) {
                    Response::json(null, 400, $skillValidationError, false);
                }
            }

            // Media hanya diupdate kalau mediaUrls + mediaPublicIds dikirim
            // berpasangan (jumlah sama). Pasangan kosong = hapus semua foto
            // (form edit menghapus foto terakhir).
            $hasMediaUpdate = is_array($mediaUrls)
                && is_array($mediaPublicIds)
                && count($mediaPublicIds) === count($mediaUrls);

            if (empty($updateData) && !$clearDueDate && $normalizedSkillIds === null && !$hasMediaUpdate) {
                Response::json(null, 400, 'Tidak ada data yang diperbarui', false);
            }

            // SATU transaksi untuk seluruh update (media + skills + circle + collaborations):
            // gagal di satu bagian = rollback semuanya, tidak ada partial update.
            $pdo->beginTransaction();
            try {
                // Update media (DELETE + INSERT)
                if ($hasMediaUpdate) {
                    $delStmt = $pdo->prepare("DELETE FROM collab_media WHERE collab_id = :collab_id");
                    $delStmt->execute([':collab_id' => $collabId]);

                    $insStmt = $pdo->prepare("INSERT INTO collab_media (id, collab_id, media_url, media_public_id) VALUES (:id, :collab_id, :media_url, :media_public_id)");
                    foreach ($mediaUrls as $index => $mediaUrl) {
                        $mediaId = CollabModel::generateUuid();
                        $mediaPublicId = $mediaPublicIds[$index] ?? null;
                        $insStmt->execute([
                            ':id'              => $mediaId,
                            ':collab_id'       => $collabId,
                            ':media_url'       => $mediaUrl,
                            ':media_public_id' => $mediaPublicId,
                        ]);
                    }
                }

                // Update skills (DELETE + INSERT)
                if ($normalizedSkillIds !== null) {
                    $delSkillStmt = $pdo->prepare("DELETE FROM collab_skills WHERE collab_id = :collab_id");
                    $delSkillStmt->execute([':collab_id' => $collabId]);

                    $insSkillStmt = $pdo->prepare("INSERT INTO collab_skills (id, collab_id, skill_id) VALUES (:id, :collab_id, :skill_id)");
                    foreach ($normalizedSkillIds as $skillId) {
                        $ssId = CollabModel::generateUuid();
                        $insSkillStmt->execute([
                            ':id'        => $ssId,
                            ':collab_id' => $collabId,
                            ':skill_id'  => $skillId,
                        ]);
                    }
                }

                // UPDATE utama tabel collaborations — title ditulis SEKALI di sini.
                if (!empty($updateData)) {
                    $setClause = [];
                    foreach (array_keys($updateData) as $key) {
                        $setClause[] = "$key = :$key";
                    }
                    $setClauseStr = implode(', ', $setClause);
                    $updateStmt = $pdo->prepare("UPDATE collaborations SET $setClauseStr WHERE id = :id");
                    $updateStmt->execute(array_merge($updateData, [':id' => $collabId]));

                    // Title circle ikut diupdate agar konsisten dengan title collab.
                    if (isset($updateData['title'])) {
                        $circleStmt = $pdo->prepare("UPDATE circle SET title = :title WHERE collaboration_id = :collab_id");
                        $circleStmt->execute([':title' => $updateData['title'], ':collab_id' => $collabId]);
                    }
                }

                if ($clearDueDate) {
                    $clearDueStmt = $pdo->prepare("UPDATE collaborations SET due_date = NULL WHERE id = :id");
                    $clearDueStmt->execute([':id' => $collabId]);
                }

                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('[COLLAB UPDATE ERROR] ' . $e->getMessage());
                Response::json(null, 500, 'Gagal memperbarui kolaborasi', false);
            }

            // Ambil data terbaru
            $updated = CollabModel::findById($collabId);
            if ($updated) {
                Response::json($updated, 200, 'Postingan kolaborasi berhasil diperbarui!', true);
            } else {
                Response::json(null, 404, 'Postingan kolaborasi tidak ditemukan!', false);
            }
        } catch (Throwable $e) {
            error_log('[COLLAB ERROR] ' . $e->getMessage());
            Response::json(null, 500, 'Terjadi kesalahan pada server saat memperbarui kolaborasi', false);
        }
    }
    
    /**
     * Handler untuk DELETE /api/collab/:id
     * Menghapus postingan kolaborasi milik user beserta relasi database terkait.
     *
     * Kontrak Express (deleteCollab):
     * - Auth required -> $GLOBALS['user']['id'] (401 jika unauthorized)
     * - Parameter id -> $params['id'] (404 jika kosong)
     * - Cek keberadaan postingan -> 404 "Collaboration Post not found!"
     * - Cek kepemilikan -> 403 "Bukan hak lu buat edit postingan ini!"
     * - Hapus data melalui CollabModel::delete($collabId)
     * - Response 200 "Postingan kolaborasi berhasil dihapus!" dengan data postingan sebelum dihapus
     *
     * @param  array<string, string> $params Path parameters dari Router (memuat 'id')
     * @return void
     */
    /**
     * Handler untuk POST /api/collab/:id/apply
     * Mengajukan lamaran bergabung ke kolaborasi (status pending).
     *
     * - 401 jika tidak login, 404 jika collab tidak ada / soft-deleted
     * - 403 jika pelamar adalah owner
     * - 409 'Already applied' jika sudah ada aplikasi pending/accepted,
     *   atau user sudah menjadi anggota
     * - 409 'Collaboration is full' jika accepted == required_member
     * - 201 {application} jika berhasil; apply ulang setelah ditolak
     *   membuka lagi aplikasi lama (UNIQUE user_id+collab_id).
     * Row collab dikunci di dalam transaksi supaya pengecekan kursi
     * antar request yang bersamaan tidak bisa overbooked.
     *
     * @param  array<string, string> $params Path parameters dari Router (memuat 'id')
     * @return void
     */
    public static function applyToCollab(array $params = []): void
    {
        $pdo = getDB();
        try {
            $userId = $GLOBALS['user']['id'] ?? null;

            if (!$userId) {
                Response::json(null, 401, 'Unauthorized', false);
            }

            $collabId = $params['id'] ?? '';
            if ($collabId === '') {
                Response::json(null, 404, 'Collaboration not found', false);
            }

            // Ambil request body (pesan lamaran opsional)
            $body = $GLOBALS['body'] ?? [];
            if (empty($body)) {
                $raw = file_get_contents('php://input');
                if (!empty($raw)) {
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded)) {
                        $body = $decoded;
                    }
                }
            }
            $message = trim((string)($body['message'] ?? ''));
            $message = $message === '' ? null : $message;

            $pdo->beginTransaction();
            try {
                $locked = CollabModel::lockCollabForMembership($pdo, $collabId);
                if (!$locked) {
                    $pdo->rollBack();
                    Response::json(null, 404, 'Collaboration not found', false);
                }

                if ((string)$locked['user_id'] === (string)$userId) {
                    $pdo->rollBack();
                    Response::json(null, 403, 'You cannot apply to your own project', false);
                }

                $existing = CollabModel::findApplication($collabId, (string)$userId);
                if ($existing !== null && in_array($existing['status'], ['pending', 'accepted'], true)) {
                    $pdo->rollBack();
                    Response::json(null, 409, 'Already applied', false);
                }

                if (CollabModel::isMember($collabId, (string)$userId)) {
                    $pdo->rollBack();
                    Response::json(null, 409, 'Already a member', false);
                }

                $acceptedCount = CollabModel::countAcceptedMembers($collabId, $pdo);
                if ($acceptedCount >= (int)$locked['required_member']) {
                    $pdo->rollBack();
                    Response::json(null, 409, 'Collaboration is full', false);
                }

                $application = $existing !== null
                    ? CollabModel::reopenApplication($collabId, (string)$userId, $message)
                    : CollabModel::createApplication($collabId, (string)$userId, $message);

                $pdo->commit();

                // Inbox: pastikan percakapan aplikasi ada (dibuat
                // sekali per aplikasi) dan pesan lamaran menjadi
                // pesan di thread-nya. Best-effort — apply tidak
                // boleh gagal gara-gara inbox.
                try {
                    InboxModel::ensureForApplication($application, (string)$locked['user_id'], (string)$userId, $message);
                } catch (Throwable $inboxError) {
                    error_log('[INBOX APPLY HOOK] ' . $inboxError->getMessage());
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }

            Response::json(['application' => $application], 201, 'Application submitted', true);
        } catch (PDOException $e) {
            // Balapan dua apply bersamaan: UNIQUE user_id+collab_id.
            if ((string)$e->getCode() === '23000') {
                Response::json(null, 409, 'Already applied', false);
            }
            error_log('[COLLAB APPLY ERROR] ' . $e->getMessage());
            Response::json(null, 500, 'Terjadi kesalahan pada server saat mengajukan lamaran', false);
        } catch (Throwable $e) {
            error_log('[COLLAB APPLY ERROR] ' . $e->getMessage());
            Response::json(null, 500, 'Terjadi kesalahan pada server saat mengajukan lamaran', false);
        }
    }

    /**
     * Handler untuk GET /api/collab/:id/applications
     * Daftar aplikasi satu collab, hanya untuk owner (403 jika bukan).
     * Urutan: pending dulu, lalu yang sudah diputuskan.
     *
     * @param  array<string, string> $params Path parameters dari Router (memuat 'id')
     * @return void
     */
    public static function listApplications(array $params = []): void
    {
        try {
            $userId = $GLOBALS['user']['id'] ?? null;

            if (!$userId) {
                Response::json(null, 401, 'Unauthorized', false);
            }

            $collabId = $params['id'] ?? '';
            if ($collabId === '') {
                Response::json(null, 404, 'Collaboration not found', false);
            }

            $collab = CollabModel::findDetailById($collabId);
            if (!$collab) {
                Response::json(null, 404, 'Collaboration not found', false);
            }

            $ownerId = $collab['owner']['id'] ?? null;
            if ((string)$ownerId !== (string)$userId) {
                Response::json(null, 403, 'Only the owner can view applications', false);
            }

            $applications = CollabModel::listApplications($collabId);
            Response::json($applications, 200, 'Applications', true);
        } catch (Throwable $e) {
            error_log('[COLLAB ERROR] ' . $e->getMessage());
            Response::json(null, 500, 'Terjadi kesalahan pada server saat mengambil daftar lamaran', false);
        }
    }

    /**
     * Handler untuk PUT /api/collab/:id/applications/:applicationId/accept
     * Owner menerima lamaran: aplikasi -> accepted DAN pelamar masuk
     * user_collaboration (anggota diterima) dalam satu transaksi.
     *
     * - 404 jika collab / aplikasi tidak ada
     * - 403 jika bukan owner
     * - 409 jika aplikasi sudah diputuskan, atau collab sudah penuh
     *
     * @param  array<string, string> $params Path parameters dari Router (memuat 'id', 'applicationId')
     * @return void
     */
    public static function acceptApplication(array $params = []): void
    {
        $pdo = getDB();
        try {
            $userId = $GLOBALS['user']['id'] ?? null;

            if (!$userId) {
                Response::json(null, 401, 'Unauthorized', false);
            }

            $collabId = $params['id'] ?? '';
            $applicationId = $params['applicationId'] ?? '';
            if ($collabId === '' || $applicationId === '') {
                Response::json(null, 404, 'Application not found', false);
            }

            $pdo->beginTransaction();
            try {
                $locked = CollabModel::lockCollabForMembership($pdo, $collabId);
                if (!$locked) {
                    $pdo->rollBack();
                    Response::json(null, 404, 'Collaboration not found', false);
                }

                if ((string)$locked['user_id'] !== (string)$userId) {
                    $pdo->rollBack();
                    Response::json(null, 403, 'Only the owner can review applications', false);
                }

                $application = CollabModel::findApplicationById($collabId, $applicationId);
                if (!$application) {
                    $pdo->rollBack();
                    Response::json(null, 404, 'Application not found', false);
                }

                if ($application['status'] !== 'pending') {
                    $pdo->rollBack();
                    Response::json(null, 409, 'Application already decided', false);
                }

                $acceptedCount = CollabModel::countAcceptedMembers($collabId, $pdo);
                if ($acceptedCount >= (int)$locked['required_member']) {
                    $pdo->rollBack();
                    Response::json(null, 409, 'Collaboration is full', false);
                }

                if (!CollabModel::acceptApplicationRow($pdo, $collabId, $applicationId)) {
                    $pdo->rollBack();
                    Response::json(null, 409, 'Application already decided', false);
                }

                CollabModel::insertMember($pdo, $collabId, (string)$application['user_id']);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }

            $fresh = CollabModel::findApplicationById($collabId, $applicationId);
            $acceptedCount = CollabModel::countAcceptedMembers($collabId);
            $availableSlots = max(0, (int)$locked['required_member'] - $acceptedCount);
            self::publishApplicationDecisionTo($applicationId, isset($application['user_id']) ? (string)$application['user_id'] : null);
            Response::json([
                'application' => $fresh,
                'accepted_member_count' => $acceptedCount,
                'available_slots' => $availableSlots,
            ], 200, 'Application accepted', true);
        } catch (PDOException $e) {
            if ((string)$e->getCode() === '23000') {
                Response::json(null, 409, 'Already a member', false);
            }
            error_log('[COLLAB ACCEPT ERROR] ' . $e->getMessage());
            Response::json(null, 500, 'Terjadi kesalahan pada server saat menerima lamaran', false);
        } catch (Throwable $e) {
            error_log('[COLLAB ACCEPT ERROR] ' . $e->getMessage());
            Response::json(null, 500, 'Terjadi kesalahan pada server saat menerima lamaran', false);
        }
    }

    /**
     * Handler untuk PUT /api/collab/:id/applications/:applicationId/decline
     * Owner menolak lamaran: aplikasi -> rejected (diekspos 'declined').
     * Pelamar boleh apply ulang sesudahnya (apply membuka lagi barisnya).
     *
     * - 404 jika collab / aplikasi tidak ada
     * - 403 jika bukan owner
     * - 409 jika aplikasi sudah diputuskan
     *
     * @param  array<string, string> $params Path parameters dari Router (memuat 'id', 'applicationId')
     * @return void
     */
    public static function declineApplication(array $params = []): void
    {
        try {
            $userId = $GLOBALS['user']['id'] ?? null;

            if (!$userId) {
                Response::json(null, 401, 'Unauthorized', false);
            }

            $collabId = $params['id'] ?? '';
            $applicationId = $params['applicationId'] ?? '';
            if ($collabId === '' || $applicationId === '') {
                Response::json(null, 404, 'Application not found', false);
            }

            $collab = CollabModel::findDetailById($collabId);
            if (!$collab) {
                Response::json(null, 404, 'Collaboration not found', false);
            }

            $ownerId = $collab['owner']['id'] ?? null;
            if ((string)$ownerId !== (string)$userId) {
                Response::json(null, 403, 'Only the owner can review applications', false);
            }

            $application = CollabModel::findApplicationById($collabId, $applicationId);
            if (!$application) {
                Response::json(null, 404, 'Application not found', false);
            }

            if ($application['status'] !== 'pending') {
                Response::json(null, 409, 'Application already decided', false);
            }

            if (!CollabModel::declineApplicationRow($collabId, $applicationId)) {
                Response::json(null, 409, 'Application already decided', false);
            }

            $fresh = CollabModel::findApplicationById($collabId, $applicationId);
            self::publishApplicationDecisionTo($applicationId, isset($application['user_id']) ? (string)$application['user_id'] : null);
            Response::json(['application' => $fresh], 200, 'Application declined', true);
        } catch (Throwable $e) {
            error_log('[COLLAB DECLINE ERROR] ' . $e->getMessage());
            Response::json(null, 500, 'Terjadi kesalahan pada server saat menolak lamaran', false);
        }
    }


    public static function deleteCollab(array $params = []): void
    {
        try {
            $userId = $GLOBALS['user']['id'] ?? null;

            if (!$userId) {
                Response::json(null, 401, 'Unauthorized', false);
            }

            $collabId = $params['id'] ?? '';
            if ($collabId === '') {
                Response::json(null, 404, 'Collaboration Post not found!', false);
            }

            // Cari kolaborasi terlebih dahulu untuk memeriksa keberadaan dan kepemilikan
            $collab = CollabModel::findDetailById($collabId);

            if (!$collab) {
                Response::json(null, 404, 'Collaboration Post not found!', false);
            }

            // Ambil ID owner dari ownerId object atau user_id
            $ownerId = $collab['owner']['id'] ?? $collab['user_id'] ?? null;

            if ((string)$ownerId !== (string)$userId) {
                Response::json(null, 403, 'Bukan hak lu buat hapus collab ini!', false);
            }

            // Hapus hanya boleh selama belum ada yang gabung: baris
            // user_collaboration isinya owner saja (count <= 1). Kalau
            // sudah ada anggota lain yang diterima, tolak.
            $memberStmt = getDB()->prepare("SELECT COUNT(*) FROM user_collaboration WHERE collaboration_id = :id");
            $memberStmt->execute([':id' => $collabId]);
            if ((int)$memberStmt->fetchColumn() > 1) {
                Response::json(null, 409, 'Kolaborasi sudah memiliki anggota, tidak dapat dihapus!', false);
            }

            // Soft delete kolaborasi (deleted_at = NOW()). Child tables
            // (collab_media, collab_skills, circle, applications, user_collaboration)
            // TIDAK dihapus — tidak ada CASCADE di sini.
            CollabModel::delete($collabId);

            Response::json($collab, 200, 'Postingan kolaborasi berhasil dihapus!', true);
        } catch (Throwable $e) {
            error_log('[COLLAB DELETE ERROR] ' . $e->getMessage());
            Response::json(null, 500, $e->getMessage(), false);
        }
    }

    /**
     * Beritahu satu user lewat realtime bahwa status sebuah aplikasi
     * berubah (kartu aplikasi di inbox-nya ikut berubah). Best-effort:
     * kegagalan publish tidak pernah menggagalkan aksi utamanya.
     */
    private static function publishApplicationDecisionTo(string $applicationId, ?string $recipientId): void
    {
        try {
            if ($recipientId === null || $recipientId === '') {
                return;
            }
            $conversationId = InboxModel::findByApplication(getDB(), $applicationId);
            if ($conversationId !== null) {
                Realtime::publishInboxUpdate($recipientId, $conversationId);
            }
        } catch (Throwable $e) {
            error_log('[INBOX DECISION PUBLISH] ' . $e->getMessage());
        }
    }

    /**
     * Handler untuk DELETE /api/collab/:id/apply
     * Pelamar menarik lamarannya sendiri (hanya saat masih pending):
     * status menjadi 'withdrawn' dan pemilik project menerima event
     * realtime supaya kartu aplikasi di inbox-nya ikut berubah.
     */
    public static function withdrawApplication(array $params = []): void
    {
        try {
            $userId = $GLOBALS['user']['id'] ?? null;

            if (!$userId) {
                Response::json(null, 401, 'Unauthorized', false);
            }

            $collabId = $params['id'] ?? '';
            if ($collabId === '') {
                Response::json(null, 404, 'Collaboration not found', false);
            }

            $application = CollabModel::findApplication($collabId, (string)$userId);
            if ($application === null) {
                Response::json(null, 404, 'Application not found', false);
            }
            if (($application['status'] ?? '') !== 'pending') {
                Response::json(null, 409, 'Application already decided', false);
            }

            CollabModel::withdrawApplicationRow($collabId, (string)$userId);

            // Beritahu pemilik project: kartu aplikasi berubah status.
            if (isset($application['id'])) {
                $ownerStmt = getDB()->prepare("SELECT user_id FROM collaborations WHERE id = :id LIMIT 1");
                $ownerStmt->execute([':id' => $collabId]);
                $ownerId = $ownerStmt->fetchColumn();
                if ($ownerId !== false) {
                    self::publishApplicationDecisionTo((string)$application['id'], (string)$ownerId);
                }
            }

            Response::json(
                ['application' => CollabModel::findApplication($collabId, (string)$userId)],
                200,
                'Application withdrawn',
                true
            );
        } catch (Throwable $e) {
            error_log('[COLLAB WITHDRAW ERROR] ' . $e->getMessage());
            Response::json(null, 500, $e->getMessage(), false);
        }
    }
}
