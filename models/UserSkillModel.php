<?php

/**
 * models/UserSkillModel.php
 * ─────────────────────────────────────────────────────────────────────────────
 * USER SKILL MODEL (Data Access Layer)
 *
 * Bertanggung jawab terhadap relasi Many-to-Many antara User dan Skill
 * pada tabel `user_skill` menggunakan PDO Prepared Statements.
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

require_once BASE_PATH . '/config/database.php';

class UserSkillModel
{
    /**
     * Mengambil seluruh skill yang dimiliki oleh user tertentu.
     * Menggabungkan tabel user_skill dan skills dengan JOIN.
     * Diurutkan secara alfabetis berdasarkan skill_name ASC.
     *
     * @param  string $userId UUID user
     * @return array<int, array{id: string, skill_name: string, scope: string}>
     */
    public static function getByUserId(string $userId): array
    {
        $pdo = getDB();
        $stmt = $pdo->prepare(
            "SELECT s.id, s.skill_name, s.scope
             FROM user_skill us
             JOIN skills s ON us.skill_id = s.id
             WHERE us.user_id = :user_id
             ORDER BY s.skill_name ASC"
        );
        $stmt->execute([':user_id' => $userId]);
        $rows = $stmt->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    /**
     * Mengecek apakah relasi user dan skill sudah ada di tabel user_skill.
     *
     * @param  string $userId
     * @param  string $skillId
     * @return bool
     */
    public static function exists(string $userId, string $skillId): bool
    {
        $pdo = getDB();
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM user_skill 
             WHERE user_id = :user_id AND skill_id = :skill_id"
        );
        $stmt->execute([
            ':user_id'  => $userId,
            ':skill_id' => $skillId,
        ]);

        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Menambahkan relasi skill ke user.
     * Melakukan validasi keberadaan user dan skill terlebih dahulu,
     * serta mencegah duplikasi relasi (user_id, skill_id).
     *
     * @param  string $userId
     * @param  string $skillId
     * @return bool True jika berhasil di-insert, false jika user/skill tidak valid atau duplikat
     */
    public static function add(string $userId, string $skillId): bool
    {
        $pdo = getDB();

        // 1. Validasi keberadaan user
        $userStmt = $pdo->prepare("SELECT 1 FROM users WHERE id = :user_id LIMIT 1");
        $userStmt->execute([':user_id' => $userId]);
        if (!$userStmt->fetch()) {
            return false;
        }

        // 2. Validasi keberadaan skill
        $skillStmt = $pdo->prepare("SELECT 1 FROM skills WHERE id = :skill_id LIMIT 1");
        $skillStmt->execute([':skill_id' => $skillId]);
        if (!$skillStmt->fetch()) {
            return false;
        }

        // 3. Cek duplikasi relasi
        if (self::exists($userId, $skillId)) {
            return false;
        }

        // 4. Insert ke user_skill
        $id = self::generateUuid();
        $insertStmt = $pdo->prepare(
            "INSERT INTO user_skill (id, skill_id, user_id) 
             VALUES (:id, :skill_id, :user_id)"
        );

        return $insertStmt->execute([
            ':id'       => $id,
            ':skill_id' => $skillId,
            ':user_id'  => $userId,
        ]);
    }

    /**
     * Menghapus relasi skill dari user.
     * Hanya menghapus record dari user_skill tanpa menghapus master data di tabel skills.
     *
     * @param  string $userId
     * @param  string $skillId
     * @return bool
     */
    public static function remove(string $userId, string $skillId): bool
    {
        $pdo = getDB();
        $stmt = $pdo->prepare(
            "DELETE FROM user_skill 
             WHERE user_id = :user_id AND skill_id = :skill_id"
        );

        return $stmt->execute([
            ':user_id'  => $userId,
            ':skill_id' => $skillId,
        ]);
    }

    /**
     * Sinkronisasi relasi skill milik user:
     * Menghapus relasi skill lama milik user, lalu menambahkan list skill baru.
     * Jika array kosong ([]), seluruh relasi skill user terhapus.
     *
     * @param  string $userId UUID user
     * @param  array<int, string> $skillIds Daftar ID skill
     * @return array<int, array{id: string, skill_name: string, scope: string}> Data skill terbaru milik user
     */
    public static function syncByUserId(string $userId, array $skillIds): array
    {
        $pdo = getDB();

        // 1. Hapus relasi lama
        $deleteStmt = $pdo->prepare("DELETE FROM user_skill WHERE user_id = :user_id");
        $deleteStmt->execute([':user_id' => $userId]);

        // 2. Filter unique skillIds untuk mencegah duplikasi
        $uniqueSkillIds = array_values(array_unique(array_filter($skillIds, fn($id) => is_string($id) && trim($id) !== '')));

        if (!empty($uniqueSkillIds)) {
            $insertStmt = $pdo->prepare(
                "INSERT INTO user_skill (id, skill_id, user_id) 
                 VALUES (:id, :skill_id, :user_id)"
            );

            foreach ($uniqueSkillIds as $skillId) {
                $id = self::generateUuid();
                $insertStmt->execute([
                    ':id'       => $id,
                    ':skill_id' => $skillId,
                    ':user_id'  => $userId,
                ]);
            }
        }

        return self::getByUserId($userId);
    }

    /**
     * Menghapus seluruh relasi skill milik user.
     *
     * @param  string $userId
     * @return bool
     */
    public static function deleteByUserId(string $userId): bool
    {
        $pdo = getDB();
        $stmt = $pdo->prepare("DELETE FROM user_skill WHERE user_id = :user_id");
        return $stmt->execute([':user_id' => $userId]);
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

