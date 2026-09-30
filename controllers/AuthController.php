<?php

/**
 * controllers/AuthController.php
 * ─────────────────────────────────────────────────────────────────────────────
 * AUTHENTICATION CONTROLLER
 *
 * Menangani alur autentikasi pengguna:
 *  - register()  -> Phase 2B (User + Profile Initialization)
 *  - login()     -> Phase 2C
 *  - me()        -> Phase 2D-1 & 2D-3B (Get My Profile + Social Media)
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

require_once BASE_PATH . '/models/UserModel.php';
require_once BASE_PATH . '/models/ProfileModel.php';
require_once BASE_PATH . '/models/ProfileSocialMediaModel.php';
require_once BASE_PATH . '/utils/Response.php';
require_once BASE_PATH . '/utils/JWT.php';

class AuthController
{
    /**
     * Handler untuk POST /api/auth/register
     * Membuat akun user baru beserta inisialisasi default profile dalam 1 transaksi PDO.
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
            $email    = trim((string)($body['email'] ?? ''));
            $password = (string)($body['password'] ?? '');

            // 1. Validasi field wajib
            if ($username === '' || $password === '') {
                Response::json(null, 400, 'Mohon isi semua kolom!', false);
            }

            // 2. Validasi duplikasi username
            $existingUser = UserModel::findByUsername($username);
            if ($existingUser !== null) {
                Response::json(null, 400, 'Username tersebut telah terdaftar!', false);
            }

            // Validasi duplikasi email jika email dikirim
            if ($email !== '') {
                $existingEmail = UserModel::findByEmail($email);
                if ($existingEmail !== null) {
                    Response::json(null, 400, 'Email tersebut telah terdaftar!', false);
                }
            }

            // 3. Hashing password secara aman dengan PASSWORD_BCRYPT
            $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

            // 4. Jalankan INSERT users dan profiles dalam satu Database Transaction
            $pdo = getDB();
            $pdo->beginTransaction();

            try {
                // A. Buat user
                $newUser = UserModel::create([
                    'username' => $username,
                    'email'    => $email,
                    'password' => $hashedPassword,
                ]);

                // B. Buat default profile untuk user yang baru dibuat
                ProfileModel::create([
                    'user_id'                 => $newUser['id'],
                    'full_name'               => '',
                    'bio'                     => '',
                    'photo_profile_url'       => ProfileModel::DEFAULT_PHOTO_URL,
                    'photo_profile_public_id' => '',
                ]);

                $pdo->commit();
            } catch (Throwable $dbError) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $dbError;
            }

            // 5. Format response data (mengembalikan data user saja sesuai baseline Express)
            $responseData = [
                'id'         => $newUser['id'],
                'username'   => $newUser['username'],
                'is_active'  => (bool)($newUser['is_active'] ?? true),
                'created_at' => $newUser['created_at'] ?? null,
            ];

            if ($email !== '') {
                $responseData['email'] = $newUser['email'] ?? $email;
            }

            Response::json($responseData, 200, 'Akun Berhasil didaftarkan!!', true);
        } catch (Throwable $e) {
            Response::json(null, 500, $e->getMessage(), false);
        }
    }

    /**
     * Handler untuk POST /api/auth/login
     *
     * @param  array<string, string> $params Path parameters dari Router
     * @return void
     */
    public static function login(array $params = []): void
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
            $password = (string)($body['password'] ?? '');

            // 1. Validasi field wajib
            if ($username === '' || $password === '') {
                Response::json(null, 400, 'Mohon Isi Seluruh Kolom!', false);
            }

            // 2. Cari user berdasarkan username di database
            $user = UserModel::findByUsername($username);
            if ($user === null) {
                Response::json(null, 404, 'Pengguna Tidak Ditemukan!', false);
            }

            // 3. Verifikasi password dengan password_verify() (mencegah plaintext compare)
            $isMatch = password_verify($password, (string)$user['password']);
            if (!$isMatch) {
                Response::json(null, 400, 'Password Salah', false);
            }

            // 4. Buat JWT token menggunakan utility JWT Phase 2A (HS256)
            $token = JWT::encode([
                'id' => $user['id'],
            ]);

            // 5. Response sukses tanpa membocorkan password / password hash
            $responseData = [
                'id'       => $user['id'],
                'username' => $user['username'],
                'token'    => $token,
            ];

            if (isset($user['email']) && $user['email'] !== null) {
                $responseData['email'] = $user['email'];
            }

            Response::json($responseData, 200, 'Yeayy.. Login Berhasil!', true);
        } catch (Throwable $e) {
            Response::json(null, 500, 'Internal Server error: ' . $e->getMessage(), false);
        }
    }

    /**
     * Handler untuk GET /api/profiles/me (Phase 2D-1 & 2D-3B)
     * Mengambil profil pengguna yang sedang login beserta social media berdasarkan JWT ID.
     *
     * @param  array<string, string> $params Path parameters dari Router
     * @return void
     */
    public static function me(array $params = []): void
    {
        try {
            $userId = $GLOBALS['user']['id'] ?? null;

            if (!$userId) {
                Response::json(null, 401, 'Unauthorized', false);
            }

            // 1. Ambil data user dari tabel users
            $user = UserModel::findById($userId);
            if ($user === null) {
                Response::json(null, 404, 'User tidak ditemukan!', false);
            }

            // 2. Ambil data profil dari tabel profiles
            $profile = ProfileModel::findByUserId($userId);

            // 3. Ambil data social media dari tabel profile_social_medias
            $socialMedia = ProfileSocialMediaModel::getByUserId($userId);

            // 4. Gabungkan data users + profiles + social_media (snake_case)
            $responseData = [
                'user_id'                 => $user['id'],
                'username'                => $user['username'],
                'email'                   => $user['email'] ?? null,
                'full_name'               => $profile['full_name'] ?? '',
                'bio'                     => $profile['bio'] ?? '',
                'photo_profile_url'       => $profile['photo_profile_url'] ?? ProfileModel::DEFAULT_PHOTO_URL,
                'photo_profile_public_id' => $profile['photo_profile_public_id'] ?? '',
                'social_media'            => $socialMedia,
            ];

            Response::json($responseData, 200, 'Berhasil Mendapatkan Data!', true);
        } catch (Throwable $e) {
            Response::json(null, 500, $e->getMessage(), false);
        }
    }
}
