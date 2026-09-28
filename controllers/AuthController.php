<?php

/**
 * controllers/AuthController.php
 * ─────────────────────────────────────────────────────────────────────────────
 * AUTHENTICATION CONTROLLER
 *
 * Menangani alur registrasi pengguna (Phase 2B).
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

require_once BASE_PATH . '/models/UserModel.php';
require_once BASE_PATH . '/utils/Response.php';

class AuthController
{
    /**
     * Handler untuk POST /api/auth/register
     *
     * @param  array<string, string> $params Path parameters dari Router
     * @return void
     */
    public static function register(array $params = []): void
    {
        try {
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

            $username = trim((string)($body['username'] ?? ''));
            $email = trim((string)($body['email'] ?? ''));
            $password = (string)($body['password'] ?? '');

            // 1. Validasi field wajib
            if ($username === '' || $password === '' || $email === '') {
                Response::json(null, 400, 'Mohon isi semua kolom!', false);
            }

            // 2. Validasi duplikasi username
            $existingUser = UserModel::findByUsername($username);
            if ($existingUser !== null) {
                Response::json(null, 400, 'Username tersebut telah terdaftar!', false);
            }
            // Validasi duplikasi email
            $existingEmail = UserModel::findByEmail($email);
            if($existingEmail !== null) {
                Response::json(null, 400, 'Email tersebut telah terdaftar!', false);
            }

            // 3. Hashing password secara aman dengan PASSWORD_BCRYPT
            $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

            // 4. Insert user ke database via UserModel
            $newUser = UserModel::create([
                'username' => $username,
                'email' => $email,
                'password' => $hashedPassword,
            ]);

            // 5. Format response data (menjaga kompatibilitas frontend & menyembunyikan password)
            $responseData = [
                'id'        => $newUser['id'],
                'username'  => $newUser['username'],
                'is_active' => (bool)($newUser['is_active'] ?? true),
                'email' => $newUser['email'],
                'created_at' => $newUser['created_at'] ?? null,
                // 'timestamp' => $newUser['timestamp'] ?? date('Y-m-d H:i:s'),
            ];

            Response::json($responseData, 200, 'Akun Berhasil didaftarkan!!', true);
        } catch (Throwable $e) {
            Response::json(null, 500, $e->getMessage(), false);
        }
    }
}
