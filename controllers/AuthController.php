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
require_once BASE_PATH . '/models/AppealModel.php';
require_once BASE_PATH . '/models/ProfileModel.php';
require_once BASE_PATH . '/models/ProfileSocialMediaModel.php';
require_once BASE_PATH . '/models/UserSkillModel.php';
require_once BASE_PATH . '/utils/Response.php';
require_once BASE_PATH . '/utils/JWT.php';
require_once BASE_PATH . '/utils/RateLimiter.php';
require_once BASE_PATH . '/models/TokenRevocation.php';

class AuthController
{
    /**
     * Hash bcrypt dari string acak yang tidak dipakai siapa pun:
     * diverifikasi saat username tidak ditemukan supaya waktu respons
     * login mirip dan tidak membocorkan akun mana yang terdaftar.
     */
    private const DUMMY_PASSWORD_HASH = '$2y$10$5ETVqiue8DnWlDJiB7TS9.hcamoN.XjwoEjNeeCZjeogxVt2JDiPm';
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
            // Rate limit pendaftaran per IP: 10 percobaan/jam (audit P0).
            if (!RateLimiter::hit('register-ip:' . RateLimiter::clientIp(), 10, 3600)) {
                Response::json(null, 429, 'Terlalu banyak percobaan. Coba lagi nanti.', false);
            }
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
            error_log('[SERVER ERROR] ' . $e->getMessage());
            Response::json(null, 500, 'Terjadi kesalahan pada server. Silakan coba lagi.', false);
        }
    }

    /**
     * Handler untuk POST /api/auth/google
     * Login / daftar via Google ID token (Google Identity Services).
     * Alur: frontend kirim `id_token` dari GIS -> backend verifikasi ke
     * Google tokeninfo (cek aud == GOOGLE_CLIENT_ID, iss Google, belum
     * expired, email terverifikasi) -> cari user by google_id, lalu by
     * email (tautkan google_id), lalu buat akun baru -> balas JWT yang
     * sama seperti login biasa. Akun Google baru dapat password acak
     * (bcrypt) yang tidak diketahui siapa pun, jadi hanya bisa login
     * via Google.
     *
     * @param  array<string, string> $params Path parameters dari Router
     * @return void
     */
    public static function google(array $params = []): void
    {
        try {
            // Rate limit sign-in Google per IP: 30 percobaan/15 menit.
            if (!RateLimiter::hit('google-ip:' . RateLimiter::clientIp(), 30, 900)) {
                Response::json(null, 429, 'Terlalu banyak percobaan. Coba lagi nanti.', false);
            }
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

            $idToken = trim((string)($body['id_token'] ?? ''));
            if ($idToken === '') {
                Response::json(null, 400, 'Google ID token wajib diisi!', false);
            }

            $clientId = trim((string)(getenv('GOOGLE_CLIENT_ID') ?: ''));
            if ($clientId === '') {
                Response::json(null, 500, 'Google sign-in belum dikonfigurasi di server!', false);
            }

            // 1. Verifikasi ID token ke Google (tokeninfo).
            $ch = curl_init('https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($idToken));
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_FAILONERROR => false,
            ]);
            $rawInfo = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($rawInfo === false || $httpCode !== 200) {
                Response::json(null, 401, 'Google ID token tidak valid!', false);
            }
            $info = json_decode((string)$rawInfo, true);
            if (!is_array($info)) {
                Response::json(null, 401, 'Google ID token tidak valid!', false);
            }

            // 2. Cek claim: audience harus client ID kita, issuer Google,
            //    belum expired, email terverifikasi.
            $aud = (string)($info['aud'] ?? '');
            $iss = (string)($info['iss'] ?? '');
            $exp = (int)($info['exp'] ?? 0);
            $emailVerified = (string)($info['email_verified'] ?? '') === 'true';
            $googleId = (string)($info['sub'] ?? '');
            $email = trim((string)($info['email'] ?? ''));
            if ($aud !== $clientId
                || ($iss !== 'accounts.google.com' && $iss !== 'https://accounts.google.com')
                || $exp <= time()
                || !$emailVerified
                || $googleId === ''
                || $email === ''
            ) {
                Response::json(null, 401, 'Google ID token tidak valid!', false);
            }

            $name = trim((string)($info['name'] ?? ''));
            $picture = trim((string)($info['picture'] ?? ''));

            // 3. Cari user: by google_id dulu, lalu by email (tautkan).
            $user = UserModel::findByGoogleId($googleId);
            if ($user === null) {
                $user = UserModel::findByEmail($email);
                if ($user !== null) {
                    UserModel::linkGoogleId((string)$user['id'], $googleId);
                    $user = UserModel::findById((string)$user['id']);
                }
            }

            // 4. Belum ada akun: buat user + profile default (mirip register).
            if ($user === null) {
                // Username dari prefix email, dibuat unik kalau bentrok.
                $base = strtolower((string)preg_replace('/[^a-z0-9._-]/i', '', (string)(explode('@', $email)[0] ?? 'user')));
                if ($base === '') {
                    $base = 'user';
                }
                $username = $base;
                $suffix = 1;
                while (UserModel::findByUsername($username) !== null) {
                    $suffix++;
                    $username = $base . $suffix;
                }

                $randomPassword = password_hash(random_bytes(32), PASSWORD_BCRYPT);
                $pdo = getDB();
                $pdo->beginTransaction();
                try {
                    $user = UserModel::create([
                        'username'  => $username,
                        'email'     => $email,
                        'password'  => $randomPassword,
                        'google_id' => $googleId,
                    ]);
                    ProfileModel::create([
                        'user_id'                 => $user['id'],
                        'full_name'               => $name,
                        'bio'                     => '',
                        'photo_profile_url'       => $picture !== '' ? $picture : ProfileModel::DEFAULT_PHOTO_URL,
                        'photo_profile_public_id' => '',
                    ]);
                    $pdo->commit();
                } catch (Throwable $dbError) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $dbError;
                }
            }

            // 5. Akun nonaktif tidak boleh login.
            if (isset($user['is_active']) && !(bool)$user['is_active']) {
                Response::json(null, 403, 'Akun ini tidak aktif!', false);
            }

            // 6. Balas JWT yang sama seperti login biasa.
            // Akun disuspend tidak diterbitkan token (spec-admin §4.4).
            if (($user['account_status'] ?? 'active') === 'suspended') {
                Response::json([
                    'suspended' => true,
                    'reason' => $user['suspended_reason'] ?? null,
                    'suspended_at' => $user['suspended_at'] ?? null,
                    'appeal' => AppealModel::suspendAppealInfo((string) $user['id']),
                ], 403, 'Akun kamu sedang disuspend.', false);
            }

            $token = JWT::encode([
                'id' => $user['id'],
            ]);
            $responseData = [
                'id'       => $user['id'],
                'username' => $user['username'],
                'role'     => $user['role'] ?? 'user',
                'token'    => $token,
                'email'    => $user['email'] ?? $email,
            ];
            Response::json($responseData, 200, 'Yeayy.. Login Google Berhasil!', true);
        } catch (Throwable $e) {
            error_log('[SERVER ERROR] ' . $e->getMessage());
            Response::json(null, 500, 'Terjadi kesalahan pada server. Silakan coba lagi.', false);
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

            // 2. Rate limit login (audit P0): bucket per IP+username
            // (10 kegagalan/15 menit) dan per IP (30/15 menit) dicek
            // sebelum menyentuh verifikasi password.
            $clientIp = RateLimiter::clientIp();
            $loginUserBucket = 'login-u:' . hash('sha256', $clientIp . '|' . strtolower($username));
            $loginIpBucket = 'login-ip:' . $clientIp;
            if (RateLimiter::blocked($loginUserBucket) || RateLimiter::blocked($loginIpBucket)) {
                Response::json(null, 429, 'Terlalu banyak percobaan login. Coba lagi dalam beberapa menit.', false);
            }

            // 3. Cari user + verifikasi password. Kegagalan SELALU satu
            // respons generik 401: membedakan "pengguna tidak ditemukan"
            // dari "password salah" membocorkan username yang terdaftar
            // (enumerasi akun). Hash dummy menjaga timing tetap mirip
            // saat user tidak ada.
            $user = UserModel::findByUsername($username);
            $hashToCheck = $user !== null ? (string) $user['password'] : self::DUMMY_PASSWORD_HASH;
            $isMatch = password_verify($password, $hashToCheck);
            if ($user === null || !$isMatch) {
                $userAllowed = RateLimiter::hit($loginUserBucket, 10, 900);
                $ipAllowed = RateLimiter::hit($loginIpBucket, 30, 900);
                if (!$userAllowed || !$ipAllowed) {
                    Response::json(null, 429, 'Terlalu banyak percobaan login. Coba lagi dalam beberapa menit.', false);
                }
                Response::json(null, 401, 'Username atau password salah!', false);
            }
            RateLimiter::clear($loginUserBucket);

            // 3b. Akun disuspend: jangan terbitkan token. Client
            // menampilkan layar pemberitahuan suspend + banding
            // (spec-admin §4.4).
            if (($user['account_status'] ?? 'active') === 'suspended') {
                Response::json([
                    'suspended' => true,
                    'reason' => $user['suspended_reason'] ?? null,
                    'suspended_at' => $user['suspended_at'] ?? null,
                    'appeal' => AppealModel::suspendAppealInfo((string) $user['id']),
                ], 403, 'Akun kamu sedang disuspend.', false);
            }

            // 4. Buat JWT token menggunakan utility JWT Phase 2A (HS256)
            $token = JWT::encode([
                'id' => $user['id'],
            ]);

            // 5. Response sukses tanpa membocorkan password / password hash
            $responseData = [
                'id'       => $user['id'],
                'username' => $user['username'],
                'role'     => $user['role'] ?? 'user',
                'token'    => $token,
            ];

            if (isset($user['email']) && $user['email'] !== null) {
                $responseData['email'] = $user['email'];
            }

            Response::json($responseData, 200, 'Yeayy.. Login Berhasil!', true);
        } catch (Throwable $e) {
            error_log('[SERVER ERROR] ' . $e->getMessage());
            Response::json(null, 500, 'Terjadi kesalahan pada server. Silakan coba lagi.', false);
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

            // 4. Ambil data skills dari tabel user_skill & skills
            $skills = UserSkillModel::getByUserId($userId);

            // 5. Gabungkan data users + profiles + social_media + skills (snake_case)
            $responseData = [
                'user_id'                 => $user['id'],
                'username'                => $user['username'],
                'role'                    => $user['role'] ?? 'user',
                'account_status'          => $user['account_status'] ?? 'active',
                'email'                   => $user['email'] ?? null,
                'full_name'               => $profile['full_name'] ?? '',
                'bio'                     => $profile['bio'] ?? '',
                'photo_profile_url'       => $profile['photo_profile_url'] ?? ProfileModel::DEFAULT_PHOTO_URL,
                'photo_profile_public_id' => $profile['photo_profile_public_id'] ?? '',
                'social_media'            => $socialMedia,
                'skills'                  => $skills,
            ];

            Response::json($responseData, 200, 'Berhasil Mendapatkan Data!', true);
        } catch (Throwable $e) {
            error_log('[SERVER ERROR] ' . $e->getMessage());
            Response::json(null, 500, 'Terjadi kesalahan pada server. Silakan coba lagi.', false);
        }
    }

    /**
     * Handler untuk PUT /api/auth/change-password (audit P0, 2026-11-14).
     * Wajib login; password saat ini harus benar; password baru
     * minimal 8 karakter (aturan yang sama dengan register).
     */
    public static function changePassword(array $params = []): void
    {
        try {
            $userId = $GLOBALS['user']['id'] ?? null;
            if (!$userId) {
                Response::json(null, 401, 'Unauthorized', false);
            }

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

            $currentPassword = (string) ($body['current_password'] ?? '');
            $newPassword = (string) ($body['new_password'] ?? '');
            if ($currentPassword === '' || $newPassword === '') {
                Response::json(null, 400, 'Mohon isi semua kolom!', false);
            }
            if (mb_strlen($newPassword) < 8) {
                Response::json(null, 400, 'Password baru minimal 8 karakter.', false);
            }

            $user = UserModel::findById((string) $userId);
            if ($user === null) {
                Response::json(null, 404, 'User tidak ditemukan!', false);
            }
            if (!password_verify($currentPassword, (string) $user['password'])) {
                Response::json(null, 400, 'Password saat ini salah.', false);
            }

            $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
            $stmt = getDB()->prepare('UPDATE users SET password = ? WHERE id = ?');
            $stmt->execute([$newHash, (string) $userId]);

            Response::json(null, 200, 'Password berhasil diubah.', true);
        } catch (Throwable $e) {
            error_log('[AUTH CHANGE PASSWORD ERROR] ' . $e->getMessage());
            Response::json(null, 500, 'Terjadi kesalahan pada server.', false);
        }
    }

    /**
     * Handler untuk POST /api/auth/logout (audit P0, 2026-11-14).
     * Mencabut token aktif supaya tidak bisa dipakai lagi, bukan
     * sekadar membuangnya di client.
     */
    public static function logout(array $params = []): void
    {
        try {
            $token = (string) ($GLOBALS['auth_token'] ?? '');
            if ($token !== '') {
                TokenRevocation::revoke($token);
            }
            Response::json(null, 200, 'Berhasil logout.', true);
        } catch (Throwable $e) {
            error_log('[AUTH LOGOUT ERROR] ' . $e->getMessage());
            Response::json(null, 500, 'Terjadi kesalahan pada server.', false);
        }
    }

    /**
     * Handler untuk POST /api/auth/refresh (audit P0, 2026-11-14).
     * Menukar token aktif dengan token baru; token lama dicabut.
     */
    public static function refresh(array $params = []): void
    {
        try {
            $userId = $GLOBALS['user']['id'] ?? null;
            if (!$userId) {
                Response::json(null, 401, 'Unauthorized', false);
            }
            $oldToken = (string) ($GLOBALS['auth_token'] ?? '');
            if ($oldToken !== '') {
                TokenRevocation::revoke($oldToken);
            }
            $token = JWT::encode(['id' => $userId]);
            Response::json(['token' => $token], 200, 'Token diperbarui.', true);
        } catch (Throwable $e) {
            error_log('[AUTH REFRESH ERROR] ' . $e->getMessage());
            Response::json(null, 500, 'Terjadi kesalahan pada server.', false);
        }
    }
}
