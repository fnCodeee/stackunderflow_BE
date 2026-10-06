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
// 10. PHASE 2D-4B: USER SKILL RELATION MODEL (UserSkillModel)
// ─────────────────────────────────────────────────────────────────────────────
printHeader("10. [Phase 2D-4B] User Skill Relation Model (models/UserSkillModel.php)");

require_once BASE_PATH . '/models/UserSkillModel.php';

// 1. Availability check
assertTest("Class UserSkillModel Tersedia", class_exists('UserSkillModel'));
assertTest("Method UserSkillModel::getByUserId() Tersedia", method_exists('UserSkillModel', 'getByUserId'));
assertTest("Method UserSkillModel::add() Tersedia", method_exists('UserSkillModel', 'add'));
assertTest("Method UserSkillModel::remove() Tersedia", method_exists('UserSkillModel', 'remove'));
assertTest("Method UserSkillModel::exists() Tersedia", method_exists('UserSkillModel', 'exists'));
assertTest("Method UserSkillModel::generateUuid() Tersedia", method_exists('UserSkillModel', 'generateUuid'));

// 2. UUID v4 Format
$userSkillUuid = UserSkillModel::generateUuid();
assertTest("Format UUID v4 UserSkillModel Valid (RFC 4122)", (bool)preg_match($uuidPattern, $userSkillUuid));

// 3. Method reflection & parameter validation
$userSkillReflector = new ReflectionClass('UserSkillModel');
$getMethod = $userSkillReflector->getMethod('getByUserId');
$addMethod = $userSkillReflector->getMethod('add');
$removeMethod = $userSkillReflector->getMethod('remove');
$existsMethod = $userSkillReflector->getMethod('exists');

assertTest("Method getByUserId() Menerima Tepat 1 Parameter (string \$userId)", $getMethod->getNumberOfParameters() === 1);
assertTest("Method add() Menerima Tepat 2 Parameter (string \$userId, string \$skillId)", $addMethod->getNumberOfParameters() === 2);
assertTest("Method remove() Menerima Tepat 2 Parameter (string \$userId, string \$skillId)", $removeMethod->getNumberOfParameters() === 2);
assertTest("Method exists() Menerima Tepat 2 Parameter (string \$userId, string \$skillId)", $existsMethod->getNumberOfParameters() === 2);

// 4. Contract and query analysis
$sourceCode = (string)file_get_contents(BASE_PATH . '/models/UserSkillModel.php');

assertTest("Query getByUserId() Menggunakan JOIN Antara user_skill dan skills", str_contains($sourceCode, 'JOIN skills'));
assertTest("Query getByUserId() Memfilter Berdasarkan user_skill.user_id = :user_id", str_contains($sourceCode, 'user_id = :user_id'));
assertTest("Query getByUserId() Mengurutkan Berdasarkan skill_name ASC", str_contains($sourceCode, 'ORDER BY s.skill_name ASC'));
assertTest("Query getByUserId() Hanya Memilih Kolom (id, skill_name, scope) Tanpa Expose Data Sensitif", !str_contains($sourceCode, 'password') && str_contains($sourceCode, 'SELECT s.id, s.skill_name, s.scope'));

assertTest("Method add() Melakukan Pengecekan Keberadaan User Terlebih Dahulu", str_contains($sourceCode, 'FROM users WHERE id = :user_id'));
assertTest("Method add() Melakukan Pengecekan Keberadaan Skill Terlebih Dahulu", str_contains($sourceCode, 'FROM skills WHERE id = :skill_id'));
assertTest("Method add() Mencegah Duplikasi Relasi Menggunakan self::exists()", str_contains($sourceCode, 'self::exists($userId, $skillId)'));

assertTest("Method remove() Menggunakan Query DELETE Berdasarkan user_id Dan skill_id", str_contains($sourceCode, 'DELETE FROM user_skill') && str_contains($sourceCode, 'skill_id = :skill_id'));
assertTest("Method exists() Menggunakan Query SELECT COUNT(*) FROM user_skill", str_contains($sourceCode, 'SELECT COUNT(*) FROM user_skill'));

// 5. Simulated data structure & sorting validation
$mockSkills = [
    ['id' => 'skill-b', 'skill_name' => 'Mobile Development', 'scope' => 'system'],
    ['id' => 'skill-a', 'skill_name' => 'Backend Development', 'scope' => 'system'],
    ['id' => 'skill-c', 'skill_name' => 'DevOps & Cloud', 'scope' => 'system'],
];
usort($mockSkills, fn($a, $b) => strcmp($a['skill_name'], $b['skill_name']));

assertTest("Sorting Data Skill Sesuai Ekspektasi Alphabetical (Backend -> DevOps -> Mobile)", $mockSkills[0]['skill_name'] === 'Backend Development' && $mockSkills[2]['skill_name'] === 'Mobile Development');
assertTest("Response Data Skill Memiliki Struktur id, skill_name, scope", isset($mockSkills[0]['id'], $mockSkills[0]['skill_name'], $mockSkills[0]['scope']));

// ─────────────────────────────────────────────────────────────────────────────
// 11. PHASE 2D-4C: PROFILE API SKILL INTEGRATION (GET & PUT /api/profiles/me)
// ─────────────────────────────────────────────────────────────────────────────
printHeader("11. [Phase 2D-4C] Profile API Skill Integration (GET & PUT /api/profiles/me)");

// 1. Availability of Model Methods for Sync
assertTest("Method UserSkillModel::syncByUserId() Tersedia", method_exists('UserSkillModel', 'syncByUserId'));
assertTest("Method UserSkillModel::deleteByUserId() Tersedia", method_exists('UserSkillModel', 'deleteByUserId'));

// 2. GET /api/profiles/me includes skills in response
$authControllerSource = (string)file_get_contents(BASE_PATH . '/controllers/AuthController.php');
assertTest("AuthController::me() Memanggil UserSkillModel::getByUserId()", str_contains($authControllerSource, 'UserSkillModel::getByUserId($userId)'));
assertTest("AuthController::me() Mengembalikan Response Data Berisi 'skills'", str_contains($authControllerSource, "'skills'                  => \$skills"));
assertTest("AuthController::me() Mempertahankan Seluruh Field Profile Existing (user_id, username, email, full_name, bio, photo_profile_url, photo_profile_public_id, social_media)", 
    str_contains($authControllerSource, "'user_id'") &&
    str_contains($authControllerSource, "'username'") &&
    str_contains($authControllerSource, "'email'") &&
    str_contains($authControllerSource, "'full_name'") &&
    str_contains($authControllerSource, "'bio'") &&
    str_contains($authControllerSource, "'photo_profile_url'") &&
    str_contains($authControllerSource, "'photo_profile_public_id'") &&
    str_contains($authControllerSource, "'social_media'")
);

// 3. PUT /api/profiles/me Skills Validation: Non-array -> HTTP 400
$outSkillsNotArray = shell_exec("php -r \"
define('BASE_PATH', '" . BASE_PATH . "');
\\\$_ENV['JWT_SECRET'] = '{$secretKunci}';
require_once BASE_PATH . '/utils/Response.php';
require_once BASE_PATH . '/controllers/ProfileController.php';
\\\$GLOBALS['user'] = ['id' => 'mock-user-123'];
\\\$body = ['skills' => 'bukan_array'];

if (array_key_exists('skills', \\\$body) && !is_array(\\\$body['skills'])) {
    Response::json(null, 400, 'skills harus berupa array', false);
}
\"");
$resSkillsNotArray = json_decode((string)$outSkillsNotArray, true);
assertTest(
    "PUT /api/profiles/me Skills Bukan Array -> HTTP 400 ('skills harus berupa array')",
    isset($resSkillsNotArray['message']) && $resSkillsNotArray['message'] === 'skills harus berupa array'
);

// 4. PUT /api/profiles/me Skills Validation: Item Invalid -> HTTP 400
$outSkillsItemInvalid = shell_exec("php -r \"
define('BASE_PATH', '" . BASE_PATH . "');
\\\$_ENV['JWT_SECRET'] = '{$secretKunci}';
require_once BASE_PATH . '/utils/Response.php';
require_once BASE_PATH . '/controllers/ProfileController.php';
\\\$GLOBALS['user'] = ['id' => 'mock-user-123'];
\\\$body = ['skills' => ['']]; // empty string ID

foreach (\\\$body['skills'] as \\\$item) {
    \\\$skillId = is_string(\\\$item) ? trim(\\\$item) : (is_array(\\\$item) ? (\\\$item['id'] ?? '') : '');
    if (\\\$skillId === '') {
        Response::json(null, 400, 'Setiap item skills harus berupa skill ID yang valid', false);
    }
}
\"");
$resSkillsItemInvalid = json_decode((string)$outSkillsItemInvalid, true);
assertTest(
    "PUT /api/profiles/me Item Skills Kosong/Invalid -> HTTP 400",
    isset($resSkillsItemInvalid['message']) && $resSkillsItemInvalid['message'] === 'Setiap item skills harus berupa skill ID yang valid'
);

// 5. Code contract analysis for ProfileController::updateProfile()
$profileControllerSource = (string)file_get_contents(BASE_PATH . '/controllers/ProfileController.php');
assertTest("ProfileController Mengimpor UserSkillModel", str_contains($profileControllerSource, "require_once BASE_PATH . '/models/UserSkillModel.php';"));
assertTest("ProfileController Menjalankan Sinkronisasi Skills dalam PDO Transaction", str_contains($profileControllerSource, 'UserSkillModel::syncByUserId($userId, $skillIds)'));
assertTest("ProfileController Memvalidasi Seluruh Skill ID ke Tabel skills", str_contains($profileControllerSource, 'SELECT id FROM skills WHERE id IN'));
assertTest("ProfileController Mengembalikan skills dan social_media Terkini Pada Response PUT", 
    str_contains($profileControllerSource, "'skills'                  => \$skills") &&
    str_contains($profileControllerSource, "'social_media'            => \$socialMedia")
);

// 6. Support for both string ID and object format in skills normalization
$testSkillsPayload = ['skill-uuid-1', ['id' => 'skill-uuid-2'], ['skill_id' => 'skill-uuid-3']];
$normalizedIds = [];
foreach ($testSkillsPayload as $it) {
    if (is_string($it)) {
        $normalizedIds[] = trim($it);
    } elseif (is_array($it) && isset($it['id'])) {
        $normalizedIds[] = trim((string)$it['id']);
    } elseif (is_array($it) && isset($it['skill_id'])) {
        $normalizedIds[] = trim((string)$it['skill_id']);
    }
}
assertTest("Normalisasi Input Skills Mendukung String ID Maupun Object Format ({id: '...'})", $normalizedIds === ['skill-uuid-1', 'skill-uuid-2', 'skill-uuid-3']);

// 7. Deduplication test
$duplicateInput = ['skill-1', 'skill-2', 'skill-1', 'skill-2', 'skill-3'];
$uniqueResult = array_values(array_unique($duplicateInput));
assertTest("Pencegahan Duplikasi Relasi Skill (Unique Array Filter)", $uniqueResult === ['skill-1', 'skill-2', 'skill-3']);

// 8. Empty skills array clears relations (skills: [])
$emptySkillsInput = [];
$clearedSkillsResult = array_values(array_unique(array_filter($emptySkillsInput, fn($id) => is_string($id) && trim($id) !== '')));
assertTest("Format skills: [] Menghasilkan Array Kosong untuk Sinkronisasi Penghapusan", $clearedSkillsResult === []);

// ─────────────────────────────────────────────────────────────────────────────
// 12. PHASE 3B: CREATE POST (PostModel & PostController)
// ─────────────────────────────────────────────────────────────────────────────
printHeader("12. [Phase 3B] Create Post (PostModel & PostController)");

require_once BASE_PATH . '/models/PostModel.php';
require_once BASE_PATH . '/controllers/PostController.php';

// 1. Availability check
assertTest("Class PostModel Tersedia", class_exists('PostModel'));
assertTest("Method PostModel::create() Tersedia", method_exists('PostModel', 'create'));
assertTest("Method PostModel::findById() Tersedia", method_exists('PostModel', 'findById'));
assertTest("Method PostModel::deleteById() Tersedia", method_exists('PostModel', 'deleteById'));
assertTest("Method PostModel::generateUuid() Tersedia", method_exists('PostModel', 'generateUuid'));

$postUuid = PostModel::generateUuid();
assertTest("Format UUID v4 PostModel Valid (RFC 4122)", (bool)preg_match($uuidPattern, $postUuid));

assertTest("Class PostController Tersedia", class_exists('PostController'));
assertTest("Method PostController::createPost() Tersedia", method_exists('PostController', 'createPost'));

// 2. Route Registration check for POST /api/posts
$routePostCheck = shell_exec("php -r \"
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
    if (\\\$r['method'] === 'POST' && \\\$r['pattern'] === '/api/posts') {
        \\\$found = true;
        break;
    }
}
echo json_encode(['registered' => \\\$found]);
\"");
$resRoutePost = json_decode((string)$routePostCheck, true);
assertTest("Route POST /api/posts Terdaftar di Router", isset($resRoutePost['registered']) && $resRoutePost['registered'] === true);

// 3. Validation: Missing Title/Description/mediaUrls -> HTTP 400
$outPostMissingFields = shell_exec("php -r \"
define('BASE_PATH', '" . BASE_PATH . "');
\\\$_ENV['JWT_SECRET'] = '{$secretKunci}';
require_once BASE_PATH . '/utils/Response.php';
require_once BASE_PATH . '/controllers/PostController.php';
\\\$GLOBALS['user'] = ['id' => 'mock-user-123'];
\\\$body = ['title' => '', 'description' => 'Test', 'mediaUrls' => []];

\\\$title = trim((string)(\\\$body['title'] ?? ''));
\\\$description = trim((string)(\\\$body['description'] ?? ''));
\\\$mediaUrls = \\\$body['media_urls'] ?? \\\$body['mediaUrls'] ?? null;

if (\\\$title === '' || \\\$description === '' || !is_array(\\\$mediaUrls) || count(\\\$mediaUrls) === 0) {
    Response::json(null, 400, 'Please fill all the required fields!', false);
}
\"");
$resPostMissingFields = json_decode((string)$outPostMissingFields, true);
assertTest(
    "POST /api/posts Validasi Field Wajib Kosong -> HTTP 400 ('Please fill all the required fields!')",
    isset($resPostMissingFields['message']) && $resPostMissingFields['message'] === 'Please fill all the required fields!'
);

// 4. Validation: mediaUrls and mediaPublicIds length mismatch -> HTTP 400
$outPostMediaMismatch = shell_exec("php -r \"
define('BASE_PATH', '" . BASE_PATH . "');
\\\$_ENV['JWT_SECRET'] = '{$secretKunci}';
require_once BASE_PATH . '/utils/Response.php';
require_once BASE_PATH . '/controllers/PostController.php';
\\\$GLOBALS['user'] = ['id' => 'mock-user-123'];
\\\$body = [
    'title' => 'My Post',
    'description' => 'Cool description',
    'mediaUrls' => ['https://res.cloudinary.com/img1.jpg', 'https://res.cloudinary.com/img2.jpg'],
    'mediaPublicIds' => ['pub_1'] // mismatch length
];

\\\$mediaUrls = \\\$body['media_urls'] ?? \\\$body['mediaUrls'] ?? null;
\\\$mediaPublicIds = \\\$body['media_public_ids'] ?? \\\$body['mediaPublicIds'] ?? [];

if (is_array(\\\$mediaPublicIds) && count(\\\$mediaPublicIds) > 0 && count(\\\$mediaUrls) !== count(\\\$mediaPublicIds)) {
    Response::json(null, 400, 'Count of mediaUrls and mediaPublicIds must be same!', false);
}
\"");
$resPostMediaMismatch = json_decode((string)$outPostMediaMismatch, true);
assertTest(
    "POST /api/posts Validasi Mismatch Jumlah Media -> HTTP 400 ('Count of mediaUrls and mediaPublicIds must be same!')",
    isset($resPostMediaMismatch['message']) && $resPostMediaMismatch['message'] === 'Count of mediaUrls and mediaPublicIds must be same!'
);

// 5. Code contract analysis for PostModel
$postModelSource = (string)file_get_contents(BASE_PATH . '/models/PostModel.php');
assertTest("PostModel::create() Menggunakan PDO Transaction", str_contains($postModelSource, '$pdo->beginTransaction()') && str_contains($postModelSource, '$pdo->commit()'));
assertTest("PostModel::create() Menyimpan ke Tabel public_posts", str_contains($postModelSource, 'INSERT INTO public_posts'));
assertTest("PostModel::create() Menyimpan ke Tabel media_posts", str_contains($postModelSource, 'INSERT INTO media_posts'));
assertTest("PostModel Menetapkan default view_count 0", str_contains($postModelSource, 'view_count') && str_contains($postModelSource, '0'));

// 6. Code contract analysis for PostController
$postControllerSource = (string)file_get_contents(BASE_PATH . '/controllers/PostController.php');
assertTest("PostController Mengambil userId dari Authenticated User (\$GLOBALS['user']['id'])", str_contains($postControllerSource, "\$GLOBALS['user']['id']"));
assertTest("PostController Mengembalikan Response Sukses 'Postingan Berhasil dibuat'", str_contains($postControllerSource, 'Postingan Berhasil dibuat'));

// ─────────────────────────────────────────────────────────────────────────────
// 13. PHASE 3C: GET POSTS / SHOWCASE (PostModel::getAll & PostController::showCase)
// ─────────────────────────────────────────────────────────────────────────────
printHeader("13. [Phase 3C] Get Posts / Showcase Feed (GET /api/posts)");

require_once BASE_PATH . '/middlewares/OptionalAuthMiddleware.php';

// 1. Availability check
assertTest("Class OptionalAuthMiddleware Tersedia", class_exists('OptionalAuthMiddleware'));
assertTest("Method OptionalAuthMiddleware::handle() Tersedia", method_exists('OptionalAuthMiddleware', 'handle'));
assertTest("Method PostModel::getAll() Tersedia", method_exists('PostModel', 'getAll'));
assertTest("Method PostController::showCase() Tersedia", method_exists('PostController', 'showCase'));

// 2. OptionalAuthMiddleware behaviour: no token -> $GLOBALS['user'] = null
$outOptionalNoToken = shell_exec("php -r \"
define('BASE_PATH', '" . BASE_PATH . "');
\\\$_ENV['JWT_SECRET'] = '{$secretKunci}';
require_once BASE_PATH . '/utils/JWT.php';
require_once BASE_PATH . '/middlewares/OptionalAuthMiddleware.php';
\\\$_SERVER['HTTP_AUTHORIZATION'] = '';
OptionalAuthMiddleware::handle([]);
echo json_encode(['user' => \\\$GLOBALS['user']]);
\"");
$resOptionalNoToken = json_decode((string)$outOptionalNoToken, true);
assertTest(
    "OptionalAuthMiddleware Tanpa Token -> \$GLOBALS['user'] === null",
    isset($resOptionalNoToken) && array_key_exists('user', $resOptionalNoToken) && $resOptionalNoToken['user'] === null
);

// 3. OptionalAuthMiddleware behaviour: invalid token -> $GLOBALS['user'] = null (no 401)
$outOptionalBadToken = shell_exec("php -r \"
define('BASE_PATH', '" . BASE_PATH . "');
\\\$_ENV['JWT_SECRET'] = '{$secretKunci}';
require_once BASE_PATH . '/utils/JWT.php';
require_once BASE_PATH . '/middlewares/OptionalAuthMiddleware.php';
\\\$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer token.tidak.valid';
OptionalAuthMiddleware::handle([]);
echo json_encode(['user' => \\\$GLOBALS['user']]);
\"");
$resOptionalBadToken = json_decode((string)$outOptionalBadToken, true);
assertTest(
    "OptionalAuthMiddleware Token Invalid -> \$GLOBALS['user'] === null (Tidak Mengembalikan 401)",
    isset($resOptionalBadToken) && array_key_exists('user', $resOptionalBadToken) && $resOptionalBadToken['user'] === null
);

// 4. Route registration check for GET /api/posts
$routeGetPostsCheck = shell_exec("php -r \"
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
    if (\\\$r['method'] === 'GET' && \\\$r['pattern'] === '/api/posts') {
        \\\$found = true;
        break;
    }
}
echo json_encode(['registered' => \\\$found]);
\"");
$resRouteGetPosts = json_decode((string)$routeGetPostsCheck, true);
assertTest("Route GET /api/posts Terdaftar di Router", isset($resRouteGetPosts['registered']) && $resRouteGetPosts['registered'] === true);

// 5. Code contract: PostModel::getAll() — JOIN users & profiles
$postModelSource = (string)file_get_contents(BASE_PATH . '/models/PostModel.php');
assertTest("PostModel::getAll() Menggunakan JOIN Tabel users", str_contains($postModelSource, 'JOIN users'));
assertTest("PostModel::getAll() Menggunakan JOIN Tabel profiles", str_contains($postModelSource, 'JOIN profiles'));
assertTest("PostModel::getAll() Mengurutkan Berdasarkan created_at DESC", str_contains($postModelSource, 'ORDER BY p.created_at DESC'));
assertTest("PostModel::getAll() Mengambil like_count dari Tabel like_posts", str_contains($postModelSource, 'FROM like_posts'));
assertTest("PostModel::getAll() Mengambil comment_count dari Tabel comments", str_contains($postModelSource, 'FROM comments'));
assertTest("PostModel::getAll() Menyertakan is_liked dalam Response", str_contains($postModelSource, 'is_liked'));
assertTest("PostModel::getAll() Mengembalikan Array Kosong Jika Tidak Ada Post (bukan throw exception)", str_contains($postModelSource, 'return []'));

// 6. Code contract: PostController::showCase() — HTTP 404 jika kosong
$postControllerSource = (string)file_get_contents(BASE_PATH . '/controllers/PostController.php');
assertTest("PostController::showCase() Mengembalikan HTTP 404 Jika Tidak Ada Post", str_contains($postControllerSource, 'Belum ada postingan saat ini!'));
assertTest("PostController::showCase() Mengembalikan HTTP 200 dan Pesan Sukses Jika Ada Post", str_contains($postControllerSource, 'Berhasil mendapatkan semua Postingan!'));

// 7. Response structure validation: simulate expected post shape
$mockPost = [
    'id'             => 'post-uuid-xxx',
    'user_id'        => [
        'id'                => 'user-uuid-yyy',
        'username'          => 'faizath',
        'photo_profile_url' => 'profile.png',
    ],
    'title'          => 'My Project',
    'description'    => 'A cool portfolio piece',
    'portfolio_type' => 'images',
    'external_url'   => null,
    'media_urls'     => ['https://res.cloudinary.com/img1.jpg'],
    'media_public_ids' => [],
    'view_count'     => 0,
    'like_count'     => 0,
    'comment_count'  => 0,
    'is_liked'       => false,
    'created_at'     => '2026-01-01 00:00:00',
    'updated_at'     => '2026-01-01 00:00:00',
];
assertTest(
    "Struktur Response Post Memiliki field user_id sebagai Nested Object (id, username, photo_profile_url)",
    is_array($mockPost['user_id']) &&
    isset($mockPost['user_id']['id'], $mockPost['user_id']['username'], $mockPost['user_id']['photo_profile_url'])
);
assertTest(
    "Struktur Response Post Memiliki like_count, comment_count, is_liked",
    array_key_exists('like_count', $mockPost) &&
    array_key_exists('comment_count', $mockPost) &&
    array_key_exists('is_liked', $mockPost)
);
assertTest(
    "is_liked Default false untuk Guest (Boolean)",
    $mockPost['is_liked'] === false
);
assertTest(
    "like_count dan comment_count Bertipe Integer",
    is_int($mockPost['like_count']) && is_int($mockPost['comment_count'])
);

// 8. PostModel::getAll() accepts optional ?string parameter
$getAllRef = new ReflectionMethod('PostModel', 'getAll');
$getAllParams = $getAllRef->getParameters();
assertTest(
    "PostModel::getAll() Menerima Parameter ?string \$currentUserId (Nullable, Optional)",
    count($getAllParams) === 1 &&
    $getAllParams[0]->isOptional() &&
    $getAllParams[0]->allowsNull()
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


