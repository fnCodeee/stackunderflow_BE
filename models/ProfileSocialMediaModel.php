<?php

/**
 * models/ProfileSocialMediaModel.php
 * ─────────────────────────────────────────────────────────────────────────────
 * PROFILE SOCIAL MEDIA MODEL (Data Access Layer)
 *
 * Bertanggung jawab terhadap operasi query ke tabel `profile_social_medias`
 * menggunakan PDO Prepared Statements.
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

require_once BASE_PATH . '/config/database.php';

class ProfileSocialMediaModel
{
    /**
     * Mengambil daftar social media milik user.
     *
     * @param  string $userId UUID user
     * @return array<int, array{id: string, platform: string, url: string}>
     */
    public static function getByUserId(string $userId): array
    {
        $pdo = getDB();
        $stmt = $pdo->prepare(
            "SELECT id, platform, url 
             FROM profile_social_medias 
             WHERE user_id = :user_id"
        );
        $stmt->execute([':user_id' => $userId]);
        $rows = $stmt->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    /**
     * Sinkronisasi social media milik user:
     * Menghapus social media lama, lalu memasukkan list item baru.
     * Jika array kosong ([]), seluruh data social media user terhapus.
     *
     * @param  string $userId UUID user
     * @param  array<int, array{platform: string, url: string}> $items
     * @return array<int, array{id: string, platform: string, url: string}> Data social media terbaru
     */
    public static function syncByUserId(string $userId, array $items): array
    {
        $pdo = getDB();

        // 1. Hapus social media lama
        $deleteStmt = $pdo->prepare("DELETE FROM profile_social_medias WHERE user_id = :user_id");
        $deleteStmt->execute([':user_id' => $userId]);

        // 2. Insert item baru jika ada
        if (!empty($items)) {
            $insertStmt = $pdo->prepare(
                "INSERT INTO profile_social_medias (id, user_id, platform, url) 
                 VALUES (:id, :user_id, :platform, :url)"
            );

            foreach ($items as $item) {
                $id = self::generateUuid();
                $insertStmt->execute([
                    ':id'       => $id,
                    ':user_id'  => $userId,
                    ':platform' => trim((string)$item['platform']),
                    ':url'      => trim((string)$item['url']),
                ]);
            }
        }

        return self::getByUserId($userId);
    }

    /**
     * Menghapus seluruh social media user (berguna untuk testing/cleanup).
     *
     * @param  string $userId
     * @return bool
     */
    public static function deleteByUserId(string $userId): bool
    {
        $pdo = getDB();
        $stmt = $pdo->prepare("DELETE FROM profile_social_medias WHERE user_id = :user_id");
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
