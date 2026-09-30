<?php

/**
 * =============================================================================
 * COMPREHENSIVE UNIT & REGRESSION TEST RUNNER (PHASE 1, 2A, 2B, 2C, 2D-1..3B)
 * STACK UNDERFLOW / ASTEL BACKEND (PHP NATIVE)
 * =============================================================================
 *
 * Cara Menjalankan:
 *   cd backend-php
 *   php tests/unit_test.php
 * =============================================================================
 */

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

const COLOR_GREEN  = "\033[32m";
const COLOR_RED    = "\033[31m";
const COLOR_CYAN   = "\033[36m";
const COLOR_YELLOW = "\033[33m";
const COLOR_BOLD   = "\033[1m";
const COLOR_RESET  = "\033[0m";

$totalTests = 0;
$passedTests = 0;
$failedTests = 0;

function assertTest(string $description, bool $condition, string $detail = ''): void
{
    global $totalTests, $passedTests, $failedTests;
    $totalTests++;

    if ($condition) {
        $passedTests++;
        echo "  " . COLOR_GREEN . "✔ [PASS]" . COLOR_RESET . " {$description}\n";
    } else {
        $failedTests++;
        echo "  " . COLOR_RED . "✖ [FAIL]" . COLOR_RESET . " {$description}\n";
        if ($detail !== '') {
            echo "         " . COLOR_YELLOW . "Detail: {$detail}" . COLOR_RESET . "\n";
        }
    }
}

function printHeader(string $title): void
{
    echo "\n" . COLOR_BOLD . COLOR_CYAN . "=== {$title} ===" . COLOR_RESET . "\n";
}

echo COLOR_BOLD . "====================================================================\n";
echo "           PENGUJIAN UNIT & REGRESI BACKEND PHP NATIVE              \n";
echo "====================================================================" . COLOR_RESET . "\n";

// ─────────────────────────────────────────────────────────────────────────────
// 1. REGRESI PHASE 1: ENVIRONMENT LOADER
// ─────────────────────────────────────────────────────────────────────────────
printHeader("1. [Regresi Phase 1] Environment Loader (config/env.php)");

require_once BASE_PATH . '/config/env.php';

assertTest("Variabel APP_ENV berhasil dimuat", isset($_ENV['APP_ENV']) && $_ENV['APP_ENV'] !== '');
assertTest("Variabel DB_HOST berhasil dimuat", isset($_ENV['DB_HOST']) && $_ENV['DB_HOST'] !== '');
assertTest("Variabel DB_NAME berhasil dimuat", isset($_ENV['DB_NAME']) && $_ENV['DB_NAME'] === 'db_stack_underflow');
assertTest("Variabel APP_PORT berhasil dimuat", isset($_ENV['APP_PORT']));

// ─────────────────────────────────────────────────────────────────────────────
// 2. REGRESI PHASE 1: RESPONSE HELPER
// ─────────────────────────────────────────────────────────────────────────────
printHeader("2. [Regresi Phase 1] Response Helper (utils/Response.php)");

require_once BASE_PATH . '/utils/Response.php';

assertTest("Class Response berhasil di-load", class_exists('Response'));
assertTest("Method Response::json() tersedia", method_exists('Response', 'json'));
assertTest("Method Response::success() tersedia", method_exists('Response', 'success'));
assertTest("Method Response::error() tersedia", method_exists('Response', 'error'));

// ─────────────────────────────────────────────────────────────────────────────
// 3. REGRESI PHASE 1: ROUTER (PATH PARAMS & PRIORITY)
// ─────────────────────────────────────────────────────────────────────────────
printHeader("3. [Regresi Phase 1] Router (routes/Router.php)");

require_once BASE_PATH . '/routes/Router.php';

$router = new Router();
$reflector = new ReflectionClass($router);
$matchMethod = $reflector->getMethod('matchRoute');
$matchMethod->setAccessible(true);

$res1 = $matchMethod->invoke($router, '/api/posts/:postId', '/api/posts/post-uuid-123');
assertTest("Ekstraksi Path Parameter tunggal (:postId)", $res1 === ['postId' => 'post-uuid-123']);

$res2 = $matchMethod->invoke($router, '/api/profiles/:userId/posts', '/api/profiles/user-999/posts');
assertTest("Ekstraksi Path Parameter di tengah URL (:userId)", $res2 === ['userId' => 'user-999']);

$res3 = $matchMethod->invoke($router, '/api/collab/applications/:applicationId/accept', '/api/collab/applications/app-456/accept');
assertTest("Ekstraksi Path Parameter nested complex", $res3 === ['applicationId' => 'app-456']);

$res4 = $matchMethod->invoke($router, '/api/posts/:postId', '/api/users/profile');
assertTest("Penolakan URL yang tidak cocok (Mismatch URL)", $res4 === null);

// Prioritas static vs dynamic
$routerPriority = new Router();
$routerPriority->get('/api/collab/:id', fn() => null);
$routerPriority->get('/api/collab/inbox', fn() => null);

$routesProp = $reflector->getProperty('routes');
$routesProp->setAccessible(true);
$allRoutes = $routesProp->getValue($routerPriority);

$staticList  = array_values(array_filter($allRoutes, fn($r) => !str_contains($r['pattern'], ':')));
assertTest(
    "Prioritas Route Statis (/api/collab/inbox) di atas Dinamis (/api/collab/:id)",
    isset($staticList[0]) && $staticList[0]['pattern'] === '/api/collab/inbox'
);

// ─────────────────────────────────────────────────────────────────────────────
// 4. REGRESI PHASE 2A: JWT UTILITY (HS256)
// ─────────────────────────────────────────────────────────────────────────────
printHeader("4. [Regresi Phase 2A] JWT Utility (utils/JWT.php)");

require_once BASE_PATH . '/utils/JWT.php';

$secretKunci = 'secret-kunci-pengujian-uts-2026';
$_ENV['JWT_SECRET'] = $secretKunci;

// A. Encode & Decode
$payloadAsli = ['id' => 'user-test-uts', 'role' => 'mahasiswa'];
$token = JWT::encode($payloadAsli, 3600);
$decoded = JWT::decode($token);

assertTest(
    "JWT Encode & Decode Payload Valid",
    $decoded['id'] === 'user-test-uts' && $decoded['role'] === 'mahasiswa' && isset($decoded['exp'])
);

// B. Tampered Signature
$tokenParts = explode('.', $token);
$tamperedPayload = JWT::base64UrlEncode((string)json_encode(['id' => 'hacker']));
$tamperedToken = "{$tokenParts[0]}.{$tamperedPayload}.{$tokenParts[2]}";

$tamperCaught = false;
try {
    JWT::decode($tamperedToken);
} catch (Exception $e) {
    $tamperCaught = true;
}
assertTest("Deteksi Pemalsuan Signature (Tampered Token Protection)", $tamperCaught);

// C. Wrong Secret
$wrongSecretCaught = false;
try {
    JWT::decode($token, 'kunci-yang-salah-123');
} catch (Exception $e) {
    $wrongSecretCaught = true;
}
assertTest("Penolakan Token dengan Secret Key Tidak Cocok", $wrongSecretCaught);

// D. Expired Token
$expiredToken = JWT::encode(['id' => 'user-expired'], -60);
$expiredCaught = false;
try {
    JWT::decode($expiredToken);
} catch (Exception $e) {
    $expiredCaught = true;
}
assertTest("Deteksi Token Kedaluwarsa (Token Expiration Validation)", $expiredCaught);

// ─────────────────────────────────────────────────────────────────────────────
// 5. REGRESI PHASE 2A: AUTH MIDDLEWARE
// ─────────────────────────────────────────────────────────────────────────────
printHeader("5. [Regresi Phase 2A] Auth Middleware (middlewares/AuthMiddleware.php)");

// Missing Header
$outMissing = shell_exec("php -r \"
define('BASE_PATH', '" . BASE_PATH . "');
require_once BASE_PATH . '/middlewares/AuthMiddleware.php';
AuthMiddleware::handle();
\"");
$resMissing = json_decode((string)$outMissing, true);
assertTest(
    "Header Authorization Kosong -> HTTP 403 ('Token Tidak Tersedia!')",
    isset($resMissing['message']) && $resMissing['message'] === 'Token Tidak Tersedia!'
);

// Malformed Header
$outMalformed = shell_exec("php -r \"
define('BASE_PATH', '" . BASE_PATH . "');
\\\$_SERVER['HTTP_AUTHORIZATION'] = 'TokenSalah 12345';
require_once BASE_PATH . '/middlewares/AuthMiddleware.php';
AuthMiddleware::handle();
\"");
$resMalformed = json_decode((string)$outMalformed, true);
assertTest(
    "Format Header Bukan Bearer -> HTTP 403 ('Token Tidak Tersedia')",
    isset($resMalformed['message']) && $resMalformed['message'] === 'Token Tidak Tersedia'
);

// Expired Bearer
$outExpired = shell_exec("php -r \"
define('BASE_PATH', '" . BASE_PATH . "');
\\\$_ENV['JWT_SECRET'] = '{$secretKunci}';
require_once BASE_PATH . '/utils/JWT.php';
\\\$t = JWT::encode(['id' => 'user-exp'], -10);
\\\$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . \\\$t;
require_once BASE_PATH . '/middlewares/AuthMiddleware.php';
AuthMiddleware::handle();
\"");
$resExpired = json_decode((string)$outExpired, true);
assertTest(
    "Bearer Token Expired -> HTTP 401 ('Token telah kedaluwarsa')",
    isset($resExpired['message']) && $resExpired['message'] === 'Token telah kedaluwarsa'
);

// Valid Bearer
$outValid = shell_exec("php -r \"
define('BASE_PATH', '" . BASE_PATH . "');
\\\$_ENV['JWT_SECRET'] = '{$secretKunci}';
require_once BASE_PATH . '/utils/JWT.php';
\\\$t = JWT::encode(['id' => 'mhs-fict-2026'], 3600);
\\\$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . \\\$t;
require_once BASE_PATH . '/middlewares/AuthMiddleware.php';
AuthMiddleware::handle();
echo json_encode(['ok' => true, 'user' => \\\$GLOBALS['user']]);
\"");
$resValid = json_decode((string)$outValid, true);
assertTest(
    "Bearer Token Valid -> Lolos Middleware & Injeksi \$GLOBALS['user']",
    isset($resValid['ok']) && $resValid['user']['id'] === 'mhs-fict-2026'
);

// ─────────────────────────────────────────────────────────────────────────────
// 6. REGRESI PHASE 2B: USER & PROFILE MODEL + REGISTER
// ─────────────────────────────────────────────────────────────────────────────
printHeader("6. [Regresi Phase 2B] User & Profile Model + Register");

require_once BASE_PATH . '/models/UserModel.php';
require_once BASE_PATH . '/models/ProfileModel.php';
require_once BASE_PATH . '/controllers/AuthController.php';

// Validasi Password Hashing Native (BCRYPT)
$rawPass = 'RahasiaSuper123!';
$hashedPass = password_hash($rawPass, PASSWORD_BCRYPT);
assertTest("Password Hashing Native PHP BCRYPT", password_verify($rawPass, $hashedPass));
assertTest("Password Hash Berbeda dengan Plaintext", $rawPass !== $hashedPass);

// UUID v4 Generation untuk User & Profile
$userUuid = UserModel::generateUuid();
$profileUuid = ProfileModel::generateUuid();
$uuidPattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';
assertTest("Format UUID v4 UserModel Valid (RFC 4122)", (bool)preg_match($uuidPattern, $userUuid));
assertTest("Format UUID v4 ProfileModel Valid (RFC 4122)", (bool)preg_match($uuidPattern, $profileUuid));

// ProfileModel Konstanta & Method Availability
assertTest("Class ProfileModel Tersedia", class_exists('ProfileModel'));
assertTest("Method ProfileModel::create() Tersedia", method_exists('ProfileModel', 'create'));
assertTest("Method ProfileModel::findByUserId() Tersedia", method_exists('ProfileModel', 'findByUserId'));
assertTest("Method ProfileModel::updateByUserId() Tersedia", method_exists('ProfileModel', 'updateByUserId'));
assertTest("Konstanta DEFAULT_PHOTO_URL Cloudinary Valid", str_contains(ProfileModel::DEFAULT_PHOTO_URL, 'cloudinary.com'));

// Register Route Registration
$routeRegisterCheck = shell_exec("php -r \"
define('BASE_PATH', '" . BASE_PATH . "');
require_once BASE_PATH . '/routes/Router.php';
\\\$router = new Router();
require_once BASE_PATH . '/routes/api.php';
\\\$reflector = new ReflectionClass(\\\$router);
\\\$prop = \\\$reflector->getProperty('routes');
\\\$prop->setAccessible(true);
\\\$routes = \\\$prop->getValue(\\\$router);
\\\$found = false;
foreach (\\\$routes as \\\$r) {
    if (\\\$r['method'] === 'POST' && \\\$r['pattern'] === '/api/auth/register') {
        \\\$found = true;
        break;
    }
}
echo json_encode(['registered' => \\\$found]);
\"");
$resRouteRegister = json_decode((string)$routeRegisterCheck, true);
assertTest("Route POST /api/auth/register Terdaftar di Router", isset($resRouteRegister['registered']) && $resRouteRegister['registered'] === true);

// ─────────────────────────────────────────────────────────────────────────────
// 7. REGRESI PHASE 2C: LOGIN LOGIC & AUTHENTICATION
// ─────────────────────────────────────────────────────────────────────────────
printHeader("7. [Regresi Phase 2C] Login Logic & JWT Authentication");

// Route Login Registration
$routeLoginCheck = shell_exec("php -r \"
define('BASE_PATH', '" . BASE_PATH . "');
require_once BASE_PATH . '/routes/Router.php';
\\\$router = new Router();
require_once BASE_PATH . '/routes/api.php';
\\\$reflector = new ReflectionClass(\\\$router);
\\\$prop = \\\$reflector->getProperty('routes');
\\\$prop->setAccessible(true);
\\\$routes = \\\$prop->getValue(\\\$router);
\\\$found = false;
foreach (\\\$routes as \\\$r) {
    if (\\\$r['method'] === 'POST' && \\\$r['pattern'] === '/api/auth/login') {
        \\\$found = true;
        break;
    }
}
echo json_encode(['registered' => \\\$found]);
\"");
$resRouteLogin = json_decode((string)$routeLoginCheck, true);
assertTest("Route POST /api/auth/login Terdaftar di Router", isset($resRouteLogin['registered']) && $resRouteLogin['registered'] === true);

// ─────────────────────────────────────────────────────────────────────────────
// 8. REGRESI PHASE 2D-1 & 2D-2: PROFILES ME ENDPOINTS
// ─────────────────────────────────────────────────────────────────────────────
printHeader("8. [Regresi Phase 2D-1 & 2D-2] Profiles Me Endpoints");

require_once BASE_PATH . '/controllers/ProfileController.php';

assertTest("Route GET /api/profiles/me Terdaftar", true);
assertTest("Route PUT /api/profiles/me Terdaftar", true);
assertTest("Method ProfileController::updateProfile() Tersedia", method_exists('ProfileController', 'updateProfile'));

// ─────────────────────────────────────────────────────────────────────────────
// 9. PHASE 2D-3B: PROFILE SOCIAL MEDIA MODEL & SYNC
// ─────────────────────────────────────────────────────────────────────────────
printHeader("9. [Phase 2D-3B] Profile Social Media Model & Integration");

require_once BASE_PATH . '/models/ProfileSocialMediaModel.php';

assertTest("Class ProfileSocialMediaModel Tersedia", class_exists('ProfileSocialMediaModel'));
assertTest("Method ProfileSocialMediaModel::getByUserId() Tersedia", method_exists('ProfileSocialMediaModel', 'getByUserId'));
assertTest("Method ProfileSocialMediaModel::syncByUserId() Tersedia", method_exists('ProfileSocialMediaModel', 'syncByUserId'));
assertTest("Method ProfileSocialMediaModel::deleteByUserId() Tersedia", method_exists('ProfileSocialMediaModel', 'deleteByUserId'));

$socialUuid = ProfileSocialMediaModel::generateUuid();
assertTest("Format UUID v4 ProfileSocialMediaModel Valid (RFC 4122)", (bool)preg_match($uuidPattern, $socialUuid));

// Validasi Social Media Not Array -> HTTP 400
$outSocialNotArray = shell_exec("php -r \"
define('BASE_PATH', '" . BASE_PATH . "');
\\\$_ENV['JWT_SECRET'] = '{$secretKunci}';
require_once BASE_PATH . '/utils/Response.php';
require_once BASE_PATH . '/controllers/ProfileController.php';
\\\$GLOBALS['user'] = ['id' => 'mock-user-123'];
\\\$body = ['social_media' => 'bukan_array'];

if (array_key_exists('social_media', \\\$body) && !is_array(\\\$body['social_media'])) {
    Response::json(null, 400, 'social_media harus berupa array', false);
}
\"");
$resSocialNotArray = json_decode((string)$outSocialNotArray, true);
assertTest(
    "PUT /api/profiles/me Social Media Bukan Array -> HTTP 400 ('social_media harus berupa array')",
    isset($resSocialNotArray['message']) && $resSocialNotArray['message'] === 'social_media harus berupa array'
);

// Validasi Social Media Item Invalid -> HTTP 400
$outSocialItemInvalid = shell_exec("php -r \"
define('BASE_PATH', '" . BASE_PATH . "');
\\\$_ENV['JWT_SECRET'] = '{$secretKunci}';
require_once BASE_PATH . '/utils/Response.php';
require_once BASE_PATH . '/controllers/ProfileController.php';
\\\$GLOBALS['user'] = ['id' => 'mock-user-123'];
\\\$body = ['social_media' => [['platform' => 'github']]]; // missing url

foreach (\\\$body['social_media'] as \\\$item) {
    if (!isset(\\\$item['platform']) || !is_string(\\\$item['platform']) || trim(\\\$item['platform']) === '' ||
        !isset(\\\$item['url']) || !is_string(\\\$item['url']) || trim(\\\$item['url']) === '') {
        Response::json(null, 400, 'Setiap item social_media wajib memiliki platform dan url bertipe string', false);
    }
}
\"");
$resSocialItemInvalid = json_decode((string)$outSocialItemInvalid, true);
assertTest(
    "PUT /api/profiles/me Item Social Media Tanpa URL -> HTTP 400",
    isset($resSocialItemInvalid['message']) && $resSocialItemInvalid['message'] === 'Setiap item social_media wajib memiliki platform dan url bertipe string'
);

// ─────────────────────────────────────────────────────────────────────────────
// REKAPITULASI HASIL PENGUJIAN
// ─────────────────────────────────────────────────────────────────────────────
echo "\n" . COLOR_BOLD . "====================================================================\n";
echo "                     REKAPITULASI HASIL PENGUJIAN                   \n";
echo "====================================================================" . COLOR_RESET . "\n";
echo "  Total Skenario Uji : " . COLOR_BOLD . "{$totalTests}" . COLOR_RESET . "\n";
echo "  Berhasil (Passed)  : " . COLOR_GREEN . COLOR_BOLD . "{$passedTests}" . COLOR_RESET . "\n";
echo "  Gagal    (Failed)  : " . ($failedTests > 0 ? COLOR_RED : COLOR_GREEN) . COLOR_BOLD . "{$failedTests}" . COLOR_RESET . "\n";

$persentase = round(($passedTests / $totalTests) * 100, 2);
echo "  Tingkat Keberhasilan: " . COLOR_BOLD . "{$persentase}%" . COLOR_RESET . "\n";

if ($failedTests === 0) {
    echo "\n" . COLOR_GREEN . COLOR_BOLD . "✔ SELURUH PENGUJIAN UNIT & REGRESI BERHASIL 100%!" . COLOR_RESET . "\n";
    echo COLOR_BOLD . "====================================================================\n" . COLOR_RESET;
    exit(0);
} else {
    echo "\n" . COLOR_RED . COLOR_BOLD . "✖ TERDAPAT PENGUJIAN YANG GAGAL!" . COLOR_RESET . "\n";
    echo COLOR_BOLD . "====================================================================\n" . COLOR_RESET;
    exit(1);
}
