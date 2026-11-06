<?php

/**
 * models/SkillModel.php
 * ─────────────────────────────────────────────────────────────────────────────
 * SKILL MODEL (Data Access Layer)
 *
 * Bertanggung jawab terhadap operasi database untuk tabel `skills`
 * (skill system & skill private milik user) menggunakan PDO Prepared
 * Statements.
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

require_once BASE_PATH . '/config/database.php';

class SkillModel
{
    /**
     * Mengambil daftar skill untuk user yang sedang login:
     *  - Semua skill system (scope='system', created_by=NULL)
     *  - Skill private milik user sendiri (scope='private', created_by=$userId)
     *
     * Skill private milik user lain TIDAK ditampilkan.
     *
     * @param  string $userId UUID user yang sedang login
     * @return array<int, array<string, mixed>>
     */
    public static function getVisibleForUser(string $userId): array
    {
        $pdo = getDB();

        $stmt = $pdo->prepare(
            "SELECT id, skill_name, scope, created_by
             FROM skills
             WHERE scope = 'system' AND created_by IS NULL
                OR (scope = 'private' AND created_by = :user_id)
             ORDER BY scope DESC, skill_name ASC"
        );
        $stmt->execute([':user_id' => $userId]);

        $rows = $stmt->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    /**
     * Membuat skill private milik user.
     *
     * Aturan:
     *  - scope dan created_by ditentukan backend (bukan dari request).
     *  - Nama skill boleh sama dengan skill system atau milik user lain.
     *  - Nama skill TIDAK boleh duplikat untuk user yang sama (scope='private',
     *    created_by = user tersebut).
     *
     * @param  string $skillName Nama skill (sudah divalidasi di controller)
     * @param  string $userId    UUID user yang sedang login
     * @return array<string, mixed> Data skill yang baru dibuat
     * @throws InvalidArgumentException Jika nama skill private user sudah ada
     * @throws Throwable               Jika insert gagal (rollback otomatis)
     */
    public static function createPrivate(string $skillName, string $userId): array
    {
        $pdo = getDB();

        // Cegah duplikasi nama skill private milik user yang sama
        $dupStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM skills
             WHERE skill_name = :skill_name
               AND scope = 'private'
               AND created_by = :user_id"
        );
        $dupStmt->execute([
            ':skill_name' => $skillName,
            ':user_id'    => $userId,
        ]);

        if ((int)$dupStmt->fetchColumn() > 0) {
            throw new InvalidArgumentException('Skill dengan nama tersebut sudah dimiliki');
        }

        $id = self::generateUuid();

        $stmt = $pdo->prepare(
            "INSERT INTO skills (id, skill_name, scope, created_by)
             VALUES (:id, :skill_name, 'private', :created_by)"
        );
        $stmt->execute([
            ':id'         => $id,
            ':skill_name' => $skillName,
            ':created_by' => $userId,
        ]);

        $created = self::findById($id);

        if ($created !== null) {
            return $created;
        }

        return [
            'id'         => $id,
            'skill_name' => $skillName,
            'scope'      => 'private',
            'created_by' => $userId,
        ];
    }

    /**
     * Mencari skill berdasarkan ID.
     *
     * @param  string $id UUID skill
     * @return array<string, mixed>|null
     */
    public static function findById(string $id): ?array
    {
        $pdo = getDB();

        $stmt = $pdo->prepare("SELECT id, skill_name, scope, created_by FROM skills WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $skill = $stmt->fetch();

        return $skill ?: null;
    }

    /**
     * Menghapus skill private milik user.
     *
     * Prasyarat (divalidasi di controller):
     *  - Skill harus ada (404 jika tidak).
     *  - Skill system tidak boleh dihapus (403).
     *  - Hanya pemilik yang bisa menghapus (403).
     *  - Jika skill masih dipakai di collab_skills, tidak boleh dihapus (409).
     *
     * Tabel user_skill menggunakan FK ON DELETE CASCADE sehingga relasi
     * user-skill otomatis bersihin saat skill dihapus.
     *
     * @param  string $id UUID skill
     * @return bool
     * @throws Throwable Jika operasi gagal
     */
    public static function deletePrivate(string $id): bool
    {
        $pdo = getDB();

        $stmt = $pdo->prepare("DELETE FROM skills WHERE id = :id");
        $stmt->execute([':id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Mengecek apakah skill masih digunakan oleh relasi collab_skills.
     *
     * @param  string $skillId
     * @return int Jumlah record di collab_skills yang mereferensikan skill ini
     */
    public static function countCollabReferences(string $skillId): int
    {
        $pdo = getDB();

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM collab_skills WHERE skill_id = :skill_id"
        );
        $stmt->execute([':skill_id' => $skillId]);

        return (int)$stmt->fetchColumn();
    }

    /**
     * Memvalidasi bahwa daftar skill ID boleh digunakan oleh user tertentu.
     *
     * Aturan:
     *  - Skill system: bisa digunakan semua user.
     *  - Skill private: hanya bisa digunakan oleh pemiliknya (created_by = user).
     *  - Skill ID tidak ada di tabel skills: ditolak.
     *
     * Dipanggil sebelum insert/update ke tabel collab_skills.
     *
     * @param  array<int, string> $skillIds Daftar skill ID (sudah unique & non-empty)
     * @param  string             $userId   UUID user yang membuat/mengupdate collab
     * @return string|null Pesan error jika ada skill yang tidak valid, null jika semua valid
     */
    public static function validateUsageForUser(array $skillIds, string $userId): ?string
    {
        $pdo = getDB();

        $placeholders = implode(',', array_fill(0, count($skillIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT id, scope, created_by FROM skills WHERE id IN ($placeholders)"
        );
        $stmt->execute($skillIds);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($rows) !== count($skillIds)) {
            return 'Terdapat skill ID yang tidak valid atau tidak ditemukan';
        }

        foreach ($rows as $row) {
            if ($row['scope'] === 'private') {
                if (($row['created_by'] ?? null) !== $userId) {
                    return 'Private skill hanya dapat digunakan oleh pemiliknya';
                }
            }
            // skill system: boleh dipakai semua user
        }

        return null;
    }

    /**
     * Helper: Generate UUID v4 standar (RFC 4122).
     *
     * @return string
     */
    public static function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // versi 4
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // variant RFC 4122
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
