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
            if ($requiredMemberInt < 1) {
                Response::json(null, 400, 'Seluruh Kolom wajib diisi.', false);
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
                Response::json(null, 400, $placeholders . ' not found', false);
            }

            // 5. Simpan ke database melalui CollabModel dalam transaksi atomik (collab + media + skills + circle)
            $result = CollabModel::create([
                'user_id'          => $userId,
                'title'            => $title,
                'description'      => $description,
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
     * Mengambil semua postingan kolaborasi beserta info pemilik, media, skills, dan circle.
     *
     * @param  array<string, string> $params Path parameters dari Router
     * @return void
     */
    public static function showCollab(array $params = []): void
    {
        try {
            $userId = $GLOBALS['user']['id'] ?? null;

            if (!$userId) {
                Response::json(null, 401, 'Unauthorized', false);
            }

            $collabs = CollabModel::getAll();
            Response::json($collabs, 200, 'Get All Posts Collaboration!', true);
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
            if($userId !== $targetUserId) {
                Response::json(null, 404, 'Bukan Punya elu', false);
            }


            if ($targetUserId === '') {
                Response::json(null, 404, 'Collaboration Post not found!', false);
            }

            $collabs = CollabModel::getByUserId($targetUserId);
            if (empty($collabs)) {
                Response::json(null, 404, 'Collaboration Post not found!', false);
            }

            $username = $collabs[0]['userId']['username'] ?? '';
            Response::json($collabs, 200, 'Your Collaboration Post, ' . $username, true);
        } catch (Throwable $e) {
            error_log('[COLLAB ERROR] ' . $e->getMessage());
            Response::json(null, 500, 'Terjadi kesalahan pada server saat mengambil kolaborasi pengguna', false);
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
            $requiredMember = $body['required_member'] ?? $body['requiredMember'] ?? null;
            $mediaUrls      = $body['media_urls'] ?? $body['mediaUrls'] ?? null;
            $mediaPublicIds = $body['media_public_ids'] ?? $body['mediaPublicIds'] ?? null;
            $skillsNeeded   = $body['skills_needed'] ?? $body['skillsNeeded'] ?? null;

            // Validasi field media (jika ada) mengikuti Express baseline
            // Jika disediakan, mediaUrls dan mediaPublicIds harus konsisten dan tidak kosong
            if (is_array($mediaPublicIds) && count($mediaPublicIds) > 0) {
                if (count($mediaUrls) !== count($mediaPublicIds) || count($mediaUrls) === 0) {
                    Response::json(null, 400, 'Jika mediaPublicIds disediakan, mediaUrls dan mediaPublicIds harus sama dan tidak kosong', false);
                }
            }

            // CEK OWNERSHIP SEBELUM UPDATE (mirip Express: findById lalu compare user_id)
            $pdo = getDB();
            $checkStmt = $pdo->prepare("SELECT user_id, title FROM collaborations WHERE id = :id LIMIT 1");
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
            if ($requiredMember !== null && $requiredMember !== '') {
                if (!is_numeric($requiredMember) || intval($requiredMember) < 1) {
                    Response::json(null, 400, 'requiredMember harus angka minimal 1', false);
                }
                $updateData['required_member'] = (int)$requiredMember;
            }

            // Update media jika disediakan (mediaUrls + mediaPublicIds)
            // Jika mediaUrls dan mediaPublicIds dikirim, jalankan update dengan logika SAME COUNT
            if (is_array($mediaUrls) && is_array($mediaPublicIds) && count($mediaPublicIds) === count($mediaUrls) && count($mediaUrls) > 0) {
                // Hapus media lama dan insert media yang baru
                // UPDATE: DELETE + INSERT (SETAVIS)
                $pdo->beginTransaction();
                try {
                    // Hapus media lama
                    $delStmt = $pdo->prepare("DELETE FROM collab_media WHERE collab_id = :collab_id");
                    $delStmt->execute([':collab_id' => $collabId]);

                    // Insert media baru
                    if (count($mediaUrls) > 0) {
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
                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    Response::json(null, 500, 'Gagal memperbarui media: ' . $e->getMessage(), false);
                }
            }

            // Update skills jika disediakan
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

                $pdo->beginTransaction();
                try {
                    // Hapus skills lama
                    $delSkillStmt = $pdo->prepare("DELETE FROM collab_skills WHERE collab_id = :collab_id");
                    $delSkillStmt->execute([':collab_id' => $collabId]);

                    // Insert skills baru
                    $insSkillStmt = $pdo->prepare("INSERT INTO collab_skills (id, collab_id, skill_id) VALUES (:id, :collab_id, :skill_id)");
                    foreach ($normalizedSkillIds as $skillId) {
                        $ssId = CollabModel::generateUuid();
                        $insSkillStmt->execute([
                            ':id'        => $ssId,
                            ':collab_id' => $collabId,
                            ':skill_id'  => $skillId,
                        ]);
                    }
                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    Response::json(null, 500, 'Gagal memperbarui skills: ' . $e->getMessage(), false);
                }
            }

            // Update title di tabel collaborations jika title diberikan
            // Jika title diupdate, update juga circle.title agar konsisten
            if (isset($updateData['title']) && $updateData['title'] !== $existing['title']) {
                $pdo->beginTransaction();
                try {
                    $collabStmt = $pdo->prepare("UPDATE collaborations SET title = :title WHERE id = :id");
                    $collabStmt->execute([':title' => $updateData['title'], ':id' => $collabId]);

                    // Otomatis update title circle
                    $circleStmt = $pdo->prepare("UPDATE circle SET title = :title WHERE collaboration_id = :collab_id");
                    $circleStmt->execute([':title' => $updateData['title'], ':collab_id' => $collabId]);

                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    Response::json(null, 500, 'Gagal memperbarui collaborations atau circle: ' . $e->getMessage(), false);
                }
            }

            // Jalankan UPDATE utama
            if (!empty($updateData)) {
                $setClause = [];
                foreach (array_keys($updateData) as $key) {
                    $setClause[] = "$key = :$key";
                }
                $setClauseStr = implode(', ', $setClause);
                $updateStmt = $pdo->prepare("UPDATE collaborations SET $setClauseStr WHERE id = :id");
                $updateStmt->execute(array_merge($updateData, [':id' => $collabId]));
            }

            // Ambil data terbaru
            $updated = CollabModel::findById($collabId);
            if ($updated) {
                Response::json($updated, 200, 'Postingan kolaborasi berhasil diperbarui!', true);
            } else {
                Response::json(null, 404, 'Postingan kolaborasi tidak ditemukan!', false);
            }
        } catch (Throwable $e) {
            Response::json(null, 500, $e->getMessage(), false);
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
            $ownerId = $collab['ownerId']['id'] ?? $collab['user_id'] ?? null;

            if ((string)$ownerId !== (string)$userId) {
                Response::json(null, 403, 'Bukan hak lu buat edit postingan ini!', false);
            }

            // Hapus kolaborasi dari database (CASCADE akan membersihkan child tables secara atomik)
            CollabModel::delete($collabId);

            Response::json($collab, 200, 'Postingan kolaborasi berhasil dihapus!', true);
        } catch (Throwable $e) {
            error_log('[COLLAB DELETE ERROR] ' . $e->getMessage());
            Response::json(null, 500, $e->getMessage(), false);
        }
    }
}
