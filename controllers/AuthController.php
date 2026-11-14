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
require_once BASE_PATH . '/models/PasswordResetModel.php';
require_once BASE_PATH . '/models/EmailVerificationModel.php';
require_once BASE_PATH . '/utils/SmtpMailer.php';

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

            $responseData['email_verified'] = false;

            // Verifikasi email (mode lunak, 2026-11-14): akun baru
            // yang mendaftar dengan email menerima link verifikasi.
            // Kegagalan kirim TIDAK menggagalkan pendaftaran — user
            // bisa minta kirim ulang dari banner di aplikasi.
            if ($email !== '') {
                try {
                    $verifyToken = EmailVerificationModel::create((string) $newUser['id']);
                    self::sendVerificationEmail($email, (string) $username, $verifyToken);
                } catch (Throwable $mailError) {
                    error_log('[REGISTER VERIFY EMAIL] ' . $mailError->getMessage());
                }
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

            // 5b. Email Google sudah terbukti milik user oleh
            // Google, jadi akun ini dianggap terverifikasi.
            if (empty($user['email_verified_at'])) {
                $stmt = getDB()->prepare('UPDATE users SET email_verified_at = ? WHERE id = ?');
                $stmt->execute([date('Y-m-d H:i:s'), (string) $user['id']]);
                $user['email_verified_at'] = date('Y-m-d H:i:s');
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
                'email_verified' => true,
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
            $responseData['email_verified'] = !empty($user['email_verified_at']);

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
                'email_verified'          => !empty($user['email_verified_at']),
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

    /**
     * Handler untuk POST /api/auth/forgot-password (2026-11-14).
     *
     * Respons SELALU generik 200, apa pun status emailnya: membedakan
     * "email terdaftar" dari "tidak terdaftar" membocorkan daftar
     * akun (enumerasi). Rate limit per IP (10/jam) dan per email
     * (5/jam) dihitung untuk SEMUA permintaan supaya limitnya sendiri
     * tidak menjadi pembeda; saat limit terlewati, pengiriman diam-diam
     * dilewati tapi respons tetap sama.
     */
    public static function forgotPassword(array $params = []): void
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
            $email = strtolower(trim((string) ($body['email'] ?? '')));

            $ipAllowed = RateLimiter::hit('forgot-ip:' . RateLimiter::clientIp(), 10, 3600);
            $emailAllowed = RateLimiter::hit('forgot-e:' . hash('sha256', $email), 5, 3600);

            if ($email !== '' && $ipAllowed && $emailAllowed) {
                $user = UserModel::findByEmail($email);
                if ($user !== null) {
                    $token = PasswordResetModel::create((string) $user['id']);
                    $base = trim((string) ($_ENV['FRONTEND_URL'] ?? ''));
                    if ($base === '') {
                        $base = 'http://localhost:3000';
                    }
                    $link = rtrim($base, '/') . '/reset-password?token=' . $token;
                    $username = (string) ($user['username'] ?? '');
                    $text = "Halo @{$username},

"
                        . "Kami menerima permintaan untuk mengatur ulang password akun Underflow kamu.
"
                        . "Klik link berikut dalam 30 menit ke depan:

{$link}

"
                        . "Kalau kamu tidak merasa meminta ini, abaikan saja email ini — password kamu tidak berubah.
";
                    $html = '<p>Halo @' . htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . ',</p>'
                        . '<p>Kami menerima permintaan untuk mengatur ulang password akun Underflow kamu. '
                        . 'Klik tombol berikut dalam 30 menit ke depan:</p>'
                        . '<p><a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '" '
                        . 'style="display:inline-block;background:#1e5128;color:#ffffff;padding:10px 18px;border-radius:9999px;text-decoration:none;font-weight:600">Atur ulang password</a></p>'
                        . '<p>Kalau tombol tidak bekerja, salin link ini ke browser:<br>'
                        . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '</p>'
                        . '<p>Kalau kamu tidak merasa meminta ini, abaikan saja email ini — password kamu tidak berubah.</p>';
                    SmtpMailer::send($email, 'Atur ulang password Underflow kamu', $text, $html);
                }
            }

            Response::json(null, 200, 'Jika email itu terdaftar, link reset sudah kami kirim. Link berlaku 30 menit.', true);
        } catch (Throwable $e) {
            error_log('[AUTH FORGOT PASSWORD ERROR] ' . $e->getMessage());
            Response::json(null, 500, 'Terjadi kesalahan pada server.', false);
        }
    }

    /**
     * Handler untuk POST /api/auth/reset-password (2026-11-14).
     * Menukar token reset yang masih berlaku dengan password baru.
     * Semua sesi lama dicabut lewat users.password_changed_at yang
     * dicek AuthMiddleware.
     */
    public static function resetPassword(array $params = []): void
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
            $token = trim((string) ($body['token'] ?? ''));
            $newPassword = (string) ($body['new_password'] ?? '');
            if ($token === '' || $newPassword === '') {
                Response::json(null, 400, 'Mohon isi semua kolom!', false);
            }
            if (mb_strlen($newPassword) < 8) {
                Response::json(null, 400, 'Password baru minimal 8 karakter.', false);
            }

            $row = PasswordResetModel::findValid($token);
            if ($row === null) {
                Response::json(null, 400, 'Link reset tidak valid atau sudah kedaluwarsa.', false);
            }

            $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
            $stmt = getDB()->prepare('UPDATE users SET password = ?, password_changed_at = ? WHERE id = ?');
            $stmt->execute([$newHash, time(), (string) $row['user_id']]);
            PasswordResetModel::consume($row);

            Response::json(null, 200, 'Password berhasil direset. Silakan login dengan password baru.', true);
        } catch (Throwable $e) {
            error_log('[AUTH RESET PASSWORD ERROR] ' . $e->getMessage());
            Response::json(null, 500, 'Terjadi kesalahan pada server.', false);
        }
    }

    /**
     * Susun + kirim email verifikasi (dipakai register & resend).
     * Mengembalikan hasil kirim dari SmtpMailer.
     */
    private static function sendVerificationEmail(string $email, string $username, string $token): bool
    {
        $base = trim((string) ($_ENV['FRONTEND_URL'] ?? ''));
        if ($base === '') {
            $base = 'http://localhost:3000';
        }
        $link = rtrim($base, '/') . '/verify-email?token=' . $token;
        $text = "Halo @{$username},

"
            . "Selamat datang di Underflow! Klik link berikut untuk memverifikasi email kamu:

{$link}

"
            . "Link berlaku 24 jam. Kalau kamu tidak merasa mendaftar, abaikan saja email ini.
";
        $html = '<p>Halo @' . htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . ',</p>'
            . '<p>Selamat datang di Underflow! Klik tombol berikut untuk memverifikasi email kamu:</p>'
            . '<p><a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '" '
            . 'style="display:inline-block;background:#1e5128;color:#ffffff;padding:10px 18px;border-radius:9999px;text-decoration:none;font-weight:600">Verifikasi email</a></p>'
            . '<p>Kalau tombol tidak bekerja, salin link ini ke browser:<br>'
            . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p>Link berlaku 24 jam. Kalau kamu tidak merasa mendaftar, abaikan saja email ini.</p>';
        return SmtpMailer::send($email, 'Verifikasi email Underflow kamu', $text, $html);
    }

    /**
     * Handler untuk POST /api/auth/verify-email (2026-11-14).
     * Menukar token dari link email menjadi status terverifikasi.
     */
    public static function verifyEmail(array $params = []): void
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
            $token = trim((string) ($body['token'] ?? ''));
            $row = $token !== '' ? EmailVerificationModel::findValid($token) : null;
            if ($row === null) {
                Response::json(null, 400, 'Link verifikasi tidak valid atau sudah kedaluwarsa.', false);
            }

            $stmt = getDB()->prepare('UPDATE users SET email_verified_at = ? WHERE id = ?');
            $stmt->execute([date('Y-m-d H:i:s'), (string) $row['user_id']]);
            EmailVerificationModel::consume($row);

            Response::json(null, 200, 'Email berhasil diverifikasi. Terima kasih!', true);
        } catch (Throwable $e) {
            error_log('[AUTH VERIFY EMAIL ERROR] ' . $e->getMessage());
            Response::json(null, 500, 'Terjadi kesalahan pada server.', false);
        }
    }

    /**
     * Handler untuk POST /api/auth/resend-verification (2026-11-14).
     * Wajib login. Maksimal 3 kirim ulang per jam per akun.
     */
    public static function resendVerification(array $params = []): void
    {
        try {
            $user = UserModel::findById((string) ($GLOBALS['user']['id'] ?? ''));
            if ($user === null) {
                Response::json(null, 404, 'User tidak ditemukan!', false);
            }
            $email = trim((string) ($user['email'] ?? ''));
            if ($email === '') {
                Response::json(null, 400, 'Akun ini tidak punya alamat email.', false);
            }
            if (!empty($user['email_verified_at'])) {
                Response::json(null, 400, 'Email kamu sudah terverifikasi.', false);
            }
            if (!RateLimiter::hit('verify-resend:' . (string) $user['id'], 3, 3600)) {
                Response::json(null, 429, 'Terlalu banyak percobaan. Coba lagi nanti.', false);
            }

            $token = EmailVerificationModel::create((string) $user['id']);
            if (!self::sendVerificationEmail($email, (string) $user['username'], $token)) {
                Response::json(null, 502, 'Gagal mengirim email. Coba lagi nanti.', false);
            }

            Response::json(null, 200, 'Email verifikasi sudah dikirim ulang.', true);
        } catch (Throwable $e) {
            error_log('[AUTH RESEND VERIFICATION ERROR] ' . $e->getMessage());
            Response::json(null, 500, 'Terjadi kesalahan pada server.', false);
        }
    }
}
