<?php

/**
 * controllers/SkillController.php
 * ─────────────────────────────────────────────────────────────────────────────
 * SKILL CONTROLLER
 *
 * Menangani operasi skill pengguna:
 *  - getSkills()    -> GET    /api/skills        (skill system + private milik user login)
 *  - createSkill()  -> POST   /api/skills        (buat private skill)
 *  - deleteSkill()  -> DELETE /api/skills/:id     (hapus private skill milik user login)
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

require_once BASE_PATH . '/models/SkillModel.php';
require_once BASE_PATH . '/utils/Response.php';

class SkillController
{
    /**
     * Handler untuk GET /api/skills
     * Mengambil semua skill system dan skill private milik user yang sedang login.
     * Skill private milik user lain tidak ditampilkan.
     *
     * @param  array<string, string> $params Path parameters dari Router
     * @return void
     */
    public static function getSkills(array $params = []): void
    {
        try {
            $userId = $GLOBALS['user']['id'] ?? null;

            if (!$userId) {
                Response::json(null, 401, 'Unauthorized', false);
            }

            $skills = SkillModel::getVisibleForUser($userId);

            Response::json($skills, 200, 'Berhasil mendapatkan daftar skill!', true);
        } catch (Throwable $e) {
            error_log('[SKILL ERROR] ' . $e->getMessage());
            Response::json(null, 500, 'Terjadi kesalahan pada server saat mengambil daftar skill', false);
        }
    }

    /**
     * Handler untuk POST /api/skills
     * Membuat private skill untuk user yang sedang login.
     *
     * Validasi:
     *  - skill_name: required, string, maksimal 100 karakter.
     *  - Cegah duplikasi nama skill private milik user yang sama.
     *  - scope & created_by ditentukan backend, bukan dari request.
     *
     * @param  array<string, string> $params Path parameters dari Router
     * @return void
     */
    public static function createSkill(array $params = []): void
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

            $skillNameRaw = $body['skill_name'] ?? null;

            // 2. Validasi: skill_name required, string, maksimal 100 karakter
            if (!is_string($skillNameRaw) || trim($skillNameRaw) === '') {
                Response::json(null, 400, 'skill_name wajib diisi', false);
            }

            $skillName = trim($skillNameRaw);

            if (strlen($skillName) > 100) {
                Response::json(null, 400, 'skill_name maksimal 100 karakter', false);
            }

            // 3. Buat skill private (model menangani pencegahan duplikasi user)
            try {
                $skill = SkillModel::createPrivate($skillName, $userId);
            } catch (InvalidArgumentException $e) {
                Response::json(null, 409, $e->getMessage(), false);
            }

            Response::json($skill, 200, 'Skill berhasil dibuat!', true);
        } catch (Throwable $e) {
            error_log('[SKILL ERROR] ' . $e->getMessage());
            Response::json(null, 500, 'Terjadi kesalahan pada server saat membuat skill', false);
        }
    }

    /**
     * Handler untuk DELETE /api/skills/:id
     * Menghapus private skill milik user yang sedang login.
     *
     * Aturan:
     *  - Skill system tidak boleh dihapus.
     *  - User tidak boleh menghapus private skill milik user lain.
     *  - Jika skill masih direferensikan di collab_skills, tidak bisa dihapus
     *    (409) — penanganan kegagalan constraint database.
     *
     * @param  array<string, string> $params Path parameters dari Router (memuat 'id')
     * @return void
     */
    public static function deleteSkill(array $params = []): void
    {
        try {
            $userId = $GLOBALS['user']['id'] ?? null;

            if (!$userId) {
                Response::json(null, 401, 'Unauthorized', false);
            }

            $skillId = $params['id'] ?? '';

            if ($skillId === '') {
                Response::json(null, 400, 'Skill ID diperlukan', false);
            }

            // 1. Cek keberadaan skill
            $skill = SkillModel::findById($skillId);

            if (!$skill) {
                Response::json(null, 404, 'Skill tidak ditemukan!', false);
            }

            // 2. Skill system tidak boleh dihapus
            if ($skill['scope'] === 'system') {
                Response::json(null, 403, 'Skill system tidak dapat dihapus', false);
            }

            // 3. Ownership check: hanya pemilik yang boleh menghapus
            $createdBy = $skill['created_by'];
            if ($createdBy !== $userId) {
                Response::json(null, 403, 'Bukan skill milikmu!', false);
            }

            // 4. Periksa relasi foreign key: skill tidak boleh masih dipakai di collab_skills
            $collabRefs = SkillModel::countCollabReferences($skillId);

            if ($collabRefs > 0) {
                Response::json(null, 409, 'Skill masih digunakan pada ' . $collabRefs . ' kolaborasi dan tidak dapat dihapus', false);
            }

            // 5. Hapus skill (FK user_skill cascade akan membersihkan relasi otomatis)
            try {
                SkillModel::deletePrivate($skillId);
            } catch (PDOException $e) {
                // Tangani kegagalan akibat constraint database
                error_log('[SKILL DELETE ERROR] ' . $e->getMessage());
                Response::json(null, 409, 'Gagal menghapus skill karena relasi database belum bersih', false);
            }

            Response::json($skill, 200, 'Skill berhasil dihapus!', true);
        } catch (Throwable $e) {
            error_log('[SKILL ERROR] ' . $e->getMessage());
            Response::json(null, 500, 'Terjadi kesalahan pada server saat menghapus skill', false);
        }
    }
}
