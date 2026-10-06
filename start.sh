aizath@asusx1404za:~/FICT-PROJECT/Y2S1/stack-underflow/backend-php$ php tests/unit_test.php
====================================================================
           PENGUJIAN UNIT & REGRESI BACKEND PHP NATIVE              
====================================================================

=== 1. [Regresi Phase 1] Environment Loader (config/env.php) ===
  ✔ [PASS] Variabel APP_ENV berhasil dimuat
  ✔ [PASS] Variabel DB_HOST berhasil dimuat
  ✔ [PASS] Variabel DB_NAME berhasil dimuat
  ✔ [PASS] Variabel APP_PORT berhasil dimuat

=== 2. [Regresi Phase 1] Response Helper (utils/Response.php) ===
  ✔ [PASS] Class Response berhasil di-load
  ✔ [PASS] Method Response::json() tersedia
  ✔ [PASS] Method Response::success() tersedia
  ✔ [PASS] Method Response::error() tersedia

=== 3. [Regresi Phase 1] Router (routes/Router.php) ===
  ✔ [PASS] Ekstraksi Path Parameter tunggal (:postId)
  ✔ [PASS] Ekstraksi Path Parameter di tengah URL (:userId)
  ✔ [PASS] Ekstraksi Path Parameter nested complex
  ✔ [PASS] Penolakan URL yang tidak cocok (Mismatch URL)
  ✔ [PASS] Prioritas Route Statis (/api/collab/inbox) di atas Dinamis (/api/collab/:id)

=== 4. [Regresi Phase 2A] JWT Utility (utils/JWT.php) ===
  ✔ [PASS] JWT Encode & Decode Payload Valid
  ✔ [PASS] Deteksi Pemalsuan Signature (Tampered Token Protection)
  ✔ [PASS] Penolakan Token dengan Secret Key Tidak Cocok
  ✔ [PASS] Deteksi Token Kedaluwarsa (Token Expiration Validation)

=== 5. [Regresi Phase 2A] Auth Middleware (middlewares/AuthMiddleware.php) ===
  ✔ [PASS] Header Authorization Kosong -> HTTP 403 ('Token Tidak Tersedia!')
  ✔ [PASS] Format Header Bukan Bearer -> HTTP 403 ('Token Tidak Tersedia')
  ✔ [PASS] Bearer Token Expired -> HTTP 401 ('Token telah kedaluwarsa')
  ✔ [PASS] Bearer Token Valid -> Lolos Middleware & Injeksi $GLOBALS['user']

=== 6. [Regresi Phase 2B] User & Profile Model + Register ===
  ✔ [PASS] Password Hashing Native PHP BCRYPT
  ✔ [PASS] Password Hash Berbeda dengan Plaintext
  ✔ [PASS] Format UUID v4 UserModel Valid (RFC 4122)
  ✔ [PASS] Format UUID v4 ProfileModel Valid (RFC 4122)
  ✔ [PASS] Class ProfileModel Tersedia
  ✔ [PASS] Method ProfileModel::create() Tersedia
  ✔ [PASS] Method ProfileModel::findByUserId() Tersedia
  ✔ [PASS] Method ProfileModel::updateByUserId() Tersedia
  ✔ [PASS] Konstanta DEFAULT_PHOTO_URL Cloudinary Valid
  ✔ [PASS] Route POST /api/auth/register Terdaftar di Router

=== 7. [Regresi Phase 2C] Login Logic & JWT Authentication ===
  ✔ [PASS] Route POST /api/auth/login Terdaftar di Router

=== 8. [Regresi Phase 2D-1 & 2D-2] Profiles Me Endpoints ===
  ✔ [PASS] Route GET /api/profiles/me Terdaftar
  ✔ [PASS] Route PUT /api/profiles/me Terdaftar
  ✔ [PASS] Method ProfileController::updateProfile() Tersedia

=== 9. [Phase 2D-3B] Profile Social Media Model & Integration ===
  ✔ [PASS] Class ProfileSocialMediaModel Tersedia
  ✔ [PASS] Method ProfileSocialMediaModel::getByUserId() Tersedia
  ✔ [PASS] Method ProfileSocialMediaModel::syncByUserId() Tersedia
  ✔ [PASS] Method ProfileSocialMediaModel::deleteByUserId() Tersedia
  ✔ [PASS] Format UUID v4 ProfileSocialMediaModel Valid (RFC 4122)
  ✔ [PASS] PUT /api/profiles/me Social Media Bukan Array -> HTTP 400 ('social_media harus berupa array')
  ✔ [PASS] PUT /api/profiles/me Item Social Media Tanpa URL -> HTTP 400

====================================================================
                     REKAPITULASI HASIL PENGUJIAN                   
====================================================================
  Total Skenario Uji : 42
  Berhasil (Passed)  : 42
  Gagal    (Failed)  : 0
  Tingkat Keberhasilan: 100%

✔ SELURUH PENGUJIAN UNIT & REGRESI BERHASIL 100%!
====================================================================
faizath@asusx1404za:~/FICT-PROJECT/Y2S1/stack-underflow/backend-php$ 

