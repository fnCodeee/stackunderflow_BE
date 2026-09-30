<?php

/**
 * models/ProfileModel.php
 * ─────────────────────────────────────────────────────────────────────────────
 * PROFILE MODEL (Data Access Layer)
 *
 * Bertanggung jawab terhadap operasi query ke tabel `profiles` menggunakan PDO.
 * Menggunakan Prepared Statements untuk integritas dan keamanan database.
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

require_once BASE_PATH . '/config/database.php';

class ProfileModel
{
    /**
     * Default photo profile URL dari baseline Express / Cloudinary.
     */
    public const DEFAULT_PHOTO_URL = 'https://res.cloudinary.com/df9jelnu7/image/upload/v1782362663/profile_zngu0l.png';

    /**
     * Membuat record profile default untuk user yang baru terdaftar.
     *
     * @param  array{
     *     user_id: string,
     *     full_name?: string,
     *     bio?: string|null,
     *     photo_profile_url?: string|null,
     *     photo_profile_public_id?: string|null,
     *     id?: string
     * } $data
     * @return array<string, mixed> Record profile yang baru dibuat
     */
    public static function create(array $data): array
    {
        $pdo = getDB();

        $id = $data['id'] ?? self::generateUuid();
        $userId = $data['user_id'];
        $fullName = $data['full_name'] ?? '';
        $bio = $data['bio'] ?? '';
        $photoProfileUrl = $data['photo_profile_url'] ?? self::DEFAULT_PHOTO_URL;
        $photoProfilePublicId = $data['photo_profile_public_id'] ?? '';

        $stmt = $pdo->prepare(
            "INSERT INTO profiles (id, user_id, full_name, bio, photo_profile_url, photo_profile_public_id) 
             VALUES (:id, :user_id, :full_name, :bio, :photo_profile_url, :photo_profile_public_id)"
        );

        $stmt->execute([
            ':id' => $id,
            ':user_id' => $userId,
            ':full_name' => $fullName,
            ':bio' => $bio,
            ':photo_profile_url' => $photoProfileUrl,
            ':photo_profile_public_id' => $photoProfilePublicId,
        ]);

        $created = self::findByUserId($userId);

        if ($created) {
            return $created;
        }

        return [
            'id' => $id,
            'user_id' => $userId,
            'full_name' => $fullName,
            'bio' => $bio,
            'photo_profile_url' => $photoProfileUrl,
            'photo_profile_public_id' => $photoProfilePublicId,
        ];
    }

    /**
     * Mencari profil berdasarkan user_id.
     *
     * @param  string $userId UUID user
     * @return array<string, mixed>|null Associative array profile atau null jika tidak ditemukan
     */
    public static function findByUserId(string $userId): ?array
    {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT * FROM profiles WHERE user_id = :user_id LIMIT 1");
        $stmt->execute([':user_id' => $userId]);
        $profile = $stmt->fetch();

        return $profile ?: null;
    }

    /**
     * Update data profil berdasarkan user_id (Partial Update).
     *
     * @param  string $userId UUID user
     * @param  array<string, mixed> $data Data field yang ingin diupdate
     * @return array<string, mixed>|null Record profil terbaru setelah update
     */
    public static function updateByUserId(string $userId, array $data): ?array
    {
        $allowedFields = ['full_name', 'bio', 'photo_profile_url', 'photo_profile_public_id'];
        $setClauses = [];
        $params = [':user_id' => $userId];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $setClauses[] = "{$field} = :{$field}";
                $params[":{$field}"] = $data[$field];
            }
        }

        if (empty($setClauses)) {
            return self::findByUserId($userId);
        }

        $pdo = getDB();
        $sql = "UPDATE profiles SET " . implode(', ', $setClauses) . " WHERE user_id = :user_id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return self::findByUserId($userId);
    }

    /**
     * Menghapus profil berdasarkan user_id (berguna untuk testing/cleanup).
     *
     * @param  string $userId
     * @return bool
     */
    public static function deleteByUserId(string $userId): bool
    {
        $pdo = getDB();
        $stmt = $pdo->prepare("DELETE FROM profiles WHERE user_id = :user_id");
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
    public static function touch(string $userId): void
    {
        $pdo = getDB();
        $stmt = $pdo->prepare("UPDATE profiles SET updated_at = CURRENT_TIMESTAMP WHERE user_id = :user_id");
        $stmt->execute([':user_id' => $userId]);
    }
}
