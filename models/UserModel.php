<?php

/**
 * models/UserModel.php
 * ─────────────────────────────────────────────────────────────────────────────
 * USER MODEL (Data Access Layer)
 *
 * Bertanggung jawab terhadap operasi query ke tabel `users` menggunakan PDO.
 * Menggunakan Prepared Statements untuk mencegah SQL Injection.
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

require_once BASE_PATH . '/config/database.php';

class UserModel
{
    /**
     * Mencari user berdasarkan username.
     *
     * @param  string $username
     * @return array<string, mixed>|null Associative array user atau null jika tidak ditemukan
     */
    public static function findByUsername(string $username): ?array
    {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = :username LIMIT 1");
        $stmt->execute([':username' => $username]);
        $user = $stmt->fetch();

        return $user ?: null;
    }

    public static function findByEmail(string $email): ?array
    {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = :email LIMIT 1");
        $stmt->execute([":email" => $email]);
        $user = $stmt->fetch();

        return $user ?: null;
    }

    /**
     * Mencari user berdasarkan ID.
     *
     * @param  string $id UUID user
     * @return array<string, mixed>|null Associative array user atau null jika tidak ditemukan
     */
    public static function findById(string $id): ?array
    {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $user = $stmt->fetch();

        return $user ?: null;
    }

    /**
     * Membuat record user baru di database.
     *
     * @param  array{username: string, password: string, id?: string, is_active?: bool|int} $data
     * @return array<string, mixed> Record user yang baru dibuat
     */
    public static function create(array $data): array
    {
        $pdo = getDB();
    
        $id = $data['id'] ?? self::generateUuid();
        $username = $data['username'];
        $password = $data['password'];
        $email = $data['email'];
        $isActive = isset($data['is_active']) ? (int)(bool)$data['is_active'] : 1;

        $stmt = $pdo->prepare(
            "INSERT INTO users (id, username, email, password, is_active) 
             VALUES (:id, :username, :email, :password, :is_active)"
        );

        $stmt->execute([
            ':id'        => $id,
            ':username'  => $username,
            ':email'     => $email,
            ':password'  => $password,
            ':is_active' => $isActive,
        ]);

        $created = self::findById($id);

        if ($created) {
            return $created;
        }

        return [
            'id'        => $id,
            'username'  => $username,
            'email'     => $email,
            'is_active' => (bool)$isActive,
        ];
    }

    /**
     * Menghapus user berdasarkan username (berguna untuk testing/cleanup).
     *
     * @param  string $username
     * @return bool
     */
    public static function deleteByUsername(string $username): bool
    {
        $pdo = getDB();
        $stmt = $pdo->prepare("DELETE FROM users WHERE username = :username");
        return $stmt->execute([':username' => $username]);
    }

    /**
     * Helper: Generate UUID v4 standar (RFC 4122).
     *
     * @return string UUID v4 terformat (misal: 123e4567-e89b-12d3-a456-426614174000)
     */
    public static function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100 (v4)
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
