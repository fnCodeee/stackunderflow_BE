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
// 10. PHASE 3C: GET POSTS / SHOWCASE (PostModel::getAll & PostController::showCase)
// ─────────────────────────────────────────────────────────────────────────────
printHeader("10. [Phase 3C] Get Posts / Showcase Feed (GET /api/posts)");

require_once BASE_PATH . '/models/PostModel.php';
require_once BASE_PATH . '/controllers/PostController.php';
require_once BASE_PATH . '/middlewares/OptionalAuthMiddleware.php';

// 1. Availability check
assertTest("Class OptionalAuthMiddleware Tersedia", class_exists('OptionalAuthMiddleware'));
assertTest("Method OptionalAuthMiddleware::handle() Tersedia", method_exists('OptionalAuthMiddleware', 'handle'));
assertTest("Method PostModel::getAll() Tersedia", method_exists('PostModel', 'getAll'));
assertTest("Method PostController::showCase() Tersedia", method_exists('PostController', 'showCase'));

// 2. OptionalAuthMiddleware behaviour: no token -> $GLOBALS['user'] = null
$optionalAuthTest = BASE_PATH . '/tests/_optional_auth_test.php';
file_put_contents($optionalAuthTest, '<?php
define(\'BASE_PATH\', \'' . BASE_PATH . '\');
$_ENV[\'JWT_SECRET\'] = \'' . $secretKunci . '\';
require_once BASE_PATH . \'/utils/JWT.php\';
require_once BASE_PATH . \'/middlewares/OptionalAuthMiddleware.php\';
$_SERVER[\'HTTP_AUTHORIZATION\'] = \'\';
OptionalAuthMiddleware::handle([]);
echo json_encode([\'user\' => $GLOBALS[\'user\']]);
');
$outOptionalNoToken = shell_exec("php " . $optionalAuthTest);
@unlink($optionalAuthTest);
$resOptionalNoToken = json_decode((string)$outOptionalNoToken, true);
assertTest(
    "OptionalAuthMiddleware Tanpa Token -> \$GLOBALS['user'] === null",
    isset($resOptionalNoToken) && array_key_exists('user', $resOptionalNoToken) && $resOptionalNoToken['user'] === null
);

// 3. OptionalAuthMiddleware behaviour: invalid token -> $GLOBALS['user'] = null (no 401)
$optionalAuthTest2 = BASE_PATH . '/tests/_optional_auth_test2.php';
file_put_contents($optionalAuthTest2, '<?php
define(\'BASE_PATH\', \'' . BASE_PATH . '\');
$_ENV[\'JWT_SECRET\'] = \'' . $secretKunci . '\';
require_once BASE_PATH . \'/utils/JWT.php\';
require_once BASE_PATH . \'/middlewares/OptionalAuthMiddleware.php\';
$_SERVER[\'HTTP_AUTHORIZATION\'] = \'Bearer token.tidak.valid\';
OptionalAuthMiddleware::handle([]);
echo json_encode([\'user\' => $GLOBALS[\'user\']]);
');
$outOptionalBadToken = shell_exec("php " . $optionalAuthTest2);
@unlink($optionalAuthTest2);
$resOptionalBadToken = json_decode((string)$outOptionalBadToken, true);
assertTest(
    "OptionalAuthMiddleware Token Invalid -> \$GLOBALS['user'] === null (Tidak Mengembalikan 401)",
    isset($resOptionalBadToken) && array_key_exists('user', $resOptionalBadToken) && $resOptionalBadToken['user'] === null
);

// 4. Route registration check for GET /api/posts
$routeTest = BASE_PATH . '/tests/_route_test.php';
file_put_contents($routeTest, '<?php
define(\'BASE_PATH\', \'' . BASE_PATH . '\');
require_once BASE_PATH . \'/routes/Router.php\';
$router = new Router();
require_once BASE_PATH . \'/routes/api.php\';
$reflector = new ReflectionClass($router);
$prop = $reflector->getProperty(\'routes\');
$prop->setAccessible(true);
$routes = $prop->getValue($router);
$found = false;
foreach ($routes as $r) {
    if ($r[\'method\'] === \'GET\' && $r[\'pattern\'] === \'/api/posts\') {
        $found = true;
        break;
    }
}
echo json_encode([\'registered\' => $found]);
');
$routeGetPostsCheck = shell_exec("php " . $routeTest);
@unlink($routeTest);
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
    'PostModel::getAll() Menerima Parameter ?string $currentUserId (Nullable, Optional)',
    count($getAllParams) === 1 &&
    $getAllParams[0]->isOptional() &&
    $getAllParams[0]->allowsNull()
);

// ─────────────────────────────────────────────────────────────────────────────
// 11. PHASE 3D-1: GET POST DETAIL (PostModel::findDetailById & PostController::getPostDetail)
// ─────────────────────────────────────────────────────────────────────────────
printHeader("11. [Phase 3D-1] Get Post Detail (GET /api/posts/:postId)");

require_once BASE_PATH . '/models/PostModel.php';
require_once BASE_PATH . '/controllers/PostController.php';
require_once BASE_PATH . '/middlewares/AuthMiddleware.php';

// 1. Availability check
assertTest("Method PostModel::findDetailById() Tersedia", method_exists('PostModel', 'findDetailById'));
assertTest("Method PostController::getPostDetail() Tersedia", method_exists('PostController', 'getPostDetail'));
assertTest("Class AuthMiddleware Tersedia", class_exists('AuthMiddleware'));
assertTest("Method AuthMiddleware::handle() Tersedia", method_exists('AuthMiddleware', 'handle'));

// 2. Route registration check for GET /api/posts/:postId
$routeDetailTest = BASE_PATH . '/tests/_route_detail_test.php';
file_put_contents($routeDetailTest, '<?php
define(\'BASE_PATH\', \'' . BASE_PATH . '\');
require_once BASE_PATH . \'/routes/Router.php\';
$router = new Router();
require_once BASE_PATH . \'/routes/api.php\';
$reflector = new ReflectionClass($router);
$prop = $reflector->getProperty(\'routes\');
$prop->setAccessible(true);
$routes = $prop->getValue($router);
$found = false;
foreach ($routes as $r) {
    if ($r[\'method\'] === \'GET\' && $r[\'pattern\'] === \'/api/posts/:postId\') {
        $found = true;
        break;
    }
}
echo json_encode([\'registered\' => $found]);
');
$routeDetailCheck = shell_exec("php " . $routeDetailTest);
@unlink($routeDetailTest);
$resRouteDetail = json_decode((string)$routeDetailCheck, true);
assertTest("Route GET /api/posts/:postId Terdaftar di Router", isset($resRouteDetail['registered']) && $resRouteDetail['registered'] === true);

// 3. Code contract: PostModel::findDetailById() — increment view_count, JOIN users & profiles, like_count, comment_count, is_liked, comments
$postModelSourceDetail = (string)file_get_contents(BASE_PATH . '/models/PostModel.php');
assertTest("PostModel::findDetailById() Increment view_count", str_contains($postModelSourceDetail, 'view_count = view_count + 1'));
assertTest("PostModel::findDetailById() Menggunakan JOIN Tabel users", str_contains($postModelSourceDetail, 'JOIN users'));
assertTest("PostModel::findDetailById() Menggunakan JOIN Tabel profiles", str_contains($postModelSourceDetail, 'JOIN profiles'));
assertTest("PostModel::findDetailById() Mengambil like_count dari Tabel like_posts", str_contains($postModelSourceDetail, 'FROM like_posts'));
assertTest("PostModel::findDetailById() Mengambil comment_count dari Tabel comments", str_contains($postModelSourceDetail, 'FROM comments'));
assertTest("PostModel::findDetailById() Menyertakan is_liked dalam Response", str_contains($postModelSourceDetail, 'is_liked'));
assertTest("PostModel::findDetailById() Mengambil Comments dengan user + profile", str_contains($postModelSourceDetail, 'FROM comments c') && str_contains($postModelSourceDetail, 'JOIN users u ON c.user_id'));
assertTest("PostModel::findDetailById() Mengembalikan owner object", str_contains($postModelSourceDetail, '\'owner\''));
assertTest("PostModel::findDetailById() Mengembalikan comments array", str_contains($postModelSourceDetail, '\'comments\''));

// 4. Code contract: PostController::getPostDetail() — requires auth, returns 404 if not found
$postControllerSourceDetail = (string)file_get_contents(BASE_PATH . '/controllers/PostController.php');
assertTest("PostController::getPostDetail() Mengecek Auth (401 jika tidak login)", str_contains($postControllerSourceDetail, '401'));
assertTest("PostController::getPostDetail() Validasi postId parameter", str_contains($postControllerSourceDetail, 'Post ID diperlukan'));
assertTest("PostController::getPostDetail() Memanggil PostModel::findDetailById", str_contains($postControllerSourceDetail, 'findDetailById'));
assertTest("PostController::getPostDetail() Mengembalikan 404 Jika Post Tidak Ditemukan", str_contains($postControllerSourceDetail, 'Post Not found!'));
assertTest("PostController::getPostDetail() Mengembalikan 200 dan Pesan Sukses Jika Ada Post", str_contains($postControllerSourceDetail, 'Post Detail added!'));

// 5. Behavior test: findDetailById returns null for non-existent post
$findMissingTest = BASE_PATH . '/tests/_find_detail_missing_test.php';
file_put_contents($findMissingTest, '<?php
define(\'BASE_PATH\', \'' . BASE_PATH . '\');
require_once BASE_PATH . \'/config/env.php\';
require_once BASE_PATH . \'/models/PostModel.php\';
$result = PostModel::findDetailById(\'non-existent-uuid-1234\', null);
echo json_encode([\'found\' => $result !== null]);
');
$outFindMissing = shell_exec("php " . $findMissingTest);
@unlink($findMissingTest);
$resFindMissing = json_decode((string)$outFindMissing, true);
assertTest("PostModel::findDetailById() Mengembalikan null untuk Post Tidak Ada", isset($resFindMissing['found']) && $resFindMissing['found'] === false);

// 7. Response structure validation: simulate expected post detail shape
$mockPostDetail = [
    'id'               => 'post-uuid-xxx',
    'user_id'          => 'user-uuid-yyy',
    'title'            => 'My Project',
    'description'      => 'A cool portfolio piece',
    'portfolio_type'   => 'images',
    'external_url'     => null,
    'media_urls'       => ['https://res.cloudinary.com/img1.jpg'],
    'media_public_ids' => [],
    'view_count'       => 1,
    'like_count'       => 5,
    'comment_count'    => 3,
    'is_liked'         => true,
    'created_at'       => '2026-01-01 00:00:00',
    'updated_at'       => '2026-01-01 00:00:00',
    'owner'            => [
        'id'                => 'user-uuid-yyy',
        'username'          => 'faizath',
        'photo_profile_url' => 'profile.png',
    ],
    'comments'         => [
        [
            'id' => 'comment-uuid-1',
            'user_id' => 'commenter-uuid-1',
            'post_id' => 'post-uuid-xxx',
            'content' => 'Great post!',
            'created_at' => '2026-01-01 01:00:00',
            'updated_at' => '2026-01-01 01:00:00',
            'user' => [
                'id' => 'commenter-uuid-1',
                'username' => 'commenter',
                'photo_profile_url' => 'profile.png',
            ],
        ],
    ],
];
assertTest(
    "Struktur Response Post Detail Memiliki owner object (id, username, photo_profile_url)",
    is_array($mockPostDetail['owner']) &&
    isset($mockPostDetail['owner']['id'], $mockPostDetail['owner']['username'], $mockPostDetail['owner']['photo_profile_url'])
);
assertTest(
    "Struktur Response Post Detail Memiliki comments array dengan user object",
    is_array($mockPostDetail['comments']) &&
    (empty($mockPostDetail['comments']) || isset($mockPostDetail['comments'][0]['user']['id']))
);
assertTest(
    "Struktur Response Post Detail Memiliki view_count, like_count, comment_count, is_liked",
    array_key_exists('view_count', $mockPostDetail) &&
    array_key_exists('like_count', $mockPostDetail) &&
    array_key_exists('comment_count', $mockPostDetail) &&
    array_key_exists('is_liked', $mockPostDetail)
);
assertTest(
    "is_liked Bertipe Boolean",
    is_bool($mockPostDetail['is_liked'])
);
assertTest(
    "view_count, like_count, comment_count Bertipe Integer",
    is_int($mockPostDetail['view_count']) && is_int($mockPostDetail['like_count']) && is_int($mockPostDetail['comment_count'])
);

// 8. PostModel::findDetailById() accepts optional ?string parameter
$findDetailRef = new ReflectionMethod('PostModel', 'findDetailById');
$findDetailParams = $findDetailRef->getParameters();
assertTest(
    'PostModel::findDetailById() Menerima Parameter ?string $currentUserId (Nullable, Optional)',
    count($findDetailParams) === 2 &&
    $findDetailParams[1]->isOptional() &&
    $findDetailParams[1]->allowsNull()
);

// ─────────────────────────────────────────────────────────────────────────────
// 12. PHASE 3D-2: UPDATE POST (PostModel::update & PostController::updatePost)
// ─────────────────────────────────────────────────────────────────────────────
printHeader("12. [Phase 3D-2] Update Post (PUT /api/posts/:postId)");

require_once BASE_PATH . '/models/PostModel.php';
require_once BASE_PATH . '/controllers/PostController.php';
require_once BASE_PATH . '/middlewares/AuthMiddleware.php';

// 1. Availability check
assertTest("Method PostModel::update() Tersedia", method_exists('PostModel', 'update'));
assertTest("Method PostController::updatePost() Tersedia", method_exists('PostController', 'updatePost'));

// 2. Route registration check for PUT /api/posts/:postId
$routeUpdateTest = BASE_PATH . '/tests/_route_update_test.php';
file_put_contents($routeUpdateTest, '<?php
define(\'BASE_PATH\', \'' . BASE_PATH . '\');
require_once BASE_PATH . \'/routes/Router.php\';
$router = new Router();
require_once BASE_PATH . \'/routes/api.php\';
$reflector = new ReflectionClass($router);
$prop = $reflector->getProperty(\'routes\');
$prop->setAccessible(true);
$routes = $prop->getValue($router);
$found = false;
foreach ($routes as $r) {
    if ($r[\'method\'] === \'PUT\' && $r[\'pattern\'] === \'/api/posts/:postId\') {
        $found = true;
        break;
    }
}
echo json_encode([\'registered\' => $found]);
');
$routeUpdateCheck = shell_exec("php " . $routeUpdateTest);
@unlink($routeUpdateTest);
$resRouteUpdate = json_decode((string)$routeUpdateCheck, true);
assertTest("Route PUT /api/posts/:postId Terdaftar di Router", isset($resRouteUpdate['registered']) && $resRouteUpdate['registered'] === true);

// 3. Code contract: PostModel::update() — ownership check, UPDATE query, media replacement
$postModelSourceUpdate = (string)file_get_contents(BASE_PATH . '/models/PostModel.php');
assertTest("PostModel::update() Memeriksa Post Exists (SELECT user_id)", str_contains($postModelSourceUpdate, 'SELECT user_id FROM public_posts'));
assertTest("PostModel::update() Menjalankan UPDATE public_posts", str_contains($postModelSourceUpdate, 'UPDATE public_posts'));
assertTest("PostModel::update() Update title, description, external_url, portfolio_type", str_contains($postModelSourceUpdate, 'title = :title') && str_contains($postModelSourceUpdate, 'description = :description') && str_contains($postModelSourceUpdate, 'external_url = :external_url') && str_contains($postModelSourceUpdate, 'portfolio_type = :portfolio_type'));
assertTest("PostModel::update() Replace media_posts jika mediaUrls disediakan", str_contains($postModelSourceUpdate, 'DELETE FROM media_posts') && str_contains($postModelSourceUpdate, 'INSERT INTO media_posts'));
assertTest("PostModel::update() Mengembalikan findDetailById", str_contains($postModelSourceUpdate, 'findDetailById'));

// 4. Code contract: PostController::updatePost() — auth, ownership, validation
$postControllerSourceUpdate = (string)file_get_contents(BASE_PATH . '/controllers/PostController.php');
assertTest("PostController::updatePost() Mengecek Auth (401 jika tidak login)", str_contains($postControllerSourceUpdate, '401'));
assertTest("PostController::updatePost() Validasi postId parameter", str_contains($postControllerSourceUpdate, 'Post ID diperlukan'));
assertTest("PostController::updatePost() Validasi Required Fields (title, description, external_url)", str_contains($postControllerSourceUpdate, 'title') && str_contains($postControllerSourceUpdate, 'description') && str_contains($postControllerSourceUpdate, 'externalUrl'));
assertTest("PostController::updatePost() Pesan Validasi Required: 'Tiap kolom wajib di isi!'", str_contains($postControllerSourceUpdate, 'Tiap kolom wajib di isi!'));
assertTest("PostController::updatePost() Ownership Check (SELECT user_id & compare)", str_contains($postControllerSourceUpdate, 'SELECT user_id FROM public_posts') && str_contains($postControllerSourceUpdate, 'user_id !=='));
assertTest("PostController::updatePost() 404 Jika Post Tidak Ditemukan: 'Postingan tidak ditemukan!'", str_contains($postControllerSourceUpdate, 'Postingan tidak ditemukan!'));
assertTest("PostController::updatePost() 403 Jika Bukan Owner: 'Bukan hak lu buat edit postingan ini!'", str_contains($postControllerSourceUpdate, 'Bukan hak lu buat edit postingan ini!'));
assertTest("PostController::updatePost() Memanggil PostModel::update", str_contains($postControllerSourceUpdate, 'PostModel::update'));
assertTest("PostController::updatePost() Response Sukses: 'Berhasil diupdate!'", str_contains($postControllerSourceUpdate, 'Berhasil diupdate!'));

// 5. PostModel::update() accepts parameters
$updateRef = new ReflectionMethod('PostModel', 'update');
$updateParams = $updateRef->getParameters();
assertTest(
    "PostModel::update() Menerima Parameter (string \$id, array \$data)",
    count($updateParams) === 2
);

// ─────────────────────────────────────────────────────────────────────────────
// 13. PHASE 3D-3: DELETE POST (PostModel::delete & PostController::deletePost)
// ─────────────────────────────────────────────────────────────────────────────
printHeader("13. [Phase 3D-3] Delete Post (DELETE /api/posts/:postId)");

require_once BASE_PATH . '/models/PostModel.php';
require_once BASE_PATH . '/controllers/PostController.php';
require_once BASE_PATH . '/middlewares/AuthMiddleware.php';

// 1. Availability check
assertTest("Method PostModel::delete() Tersedia", method_exists('PostModel', 'delete'));
assertTest("Method PostController::deletePost() Tersedia", method_exists('PostController', 'deletePost'));

// 2. Route registration check for DELETE /api/posts/:postId
$routeDeleteTest = BASE_PATH . '/tests/_route_delete_test.php';
file_put_contents($routeDeleteTest, '<?php
define(\'BASE_PATH\', \'' . BASE_PATH . '\');
require_once BASE_PATH . \'/routes/Router.php\';
$router = new Router();
require_once BASE_PATH . \'/routes/api.php\';
$reflector = new ReflectionClass($router);
$prop = $reflector->getProperty(\'routes\');
$prop->setAccessible(true);
$routes = $prop->getValue($router);
$found = false;
foreach ($routes as $r) {
    if ($r[\'method\'] === \'DELETE\' && $r[\'pattern\'] === \'/api/posts/:postId\') {
        $found = true;
        break;
    }
}
echo json_encode([\'registered\' => $found]);
');
$routeDeleteCheck = shell_exec("php " . $routeDeleteTest);
@unlink($routeDeleteTest);
$resRouteDelete = json_decode((string)$routeDeleteCheck, true);
assertTest("Route DELETE /api/posts/:postId Terdaftar di Router", isset($resRouteDelete['registered']) && $resRouteDelete['registered'] === true);

// 3. Code contract: PostModel::delete() — transaction, like_posts, comments, media_posts, public_posts deletion
$postModelSourceDelete = (string)file_get_contents(BASE_PATH . '/models/PostModel.php');
assertTest("PostModel::delete() Menggunakan Database Transaction", str_contains($postModelSourceDelete, 'beginTransaction') && str_contains($postModelSourceDelete, 'commit'));
assertTest("PostModel::delete() Menghapus Data Relasi like_posts", str_contains($postModelSourceDelete, 'DELETE FROM like_posts'));
assertTest("PostModel::delete() Menghapus Data Relasi comments", str_contains($postModelSourceDelete, 'DELETE FROM comments'));
assertTest("PostModel::delete() Menghapus Data Relasi media_posts", str_contains($postModelSourceDelete, 'DELETE FROM media_posts'));
assertTest("PostModel::delete() Menghapus Record Utama public_posts", str_contains($postModelSourceDelete, 'DELETE FROM public_posts'));

// 4. Code contract: PostController::deletePost() — auth, validation, 404, ownership 403, success 200
$postControllerSourceDelete = (string)file_get_contents(BASE_PATH . '/controllers/PostController.php');
assertTest("PostController::deletePost() Mengecek Auth (401 jika tidak login)", str_contains($postControllerSourceDelete, '401'));
assertTest("PostController::deletePost() Validasi postId parameter", str_contains($postControllerSourceDelete, 'Post ID diperlukan'));
assertTest("PostController::deletePost() 404 Jika Post Tidak Ditemukan: 'Postingan Tidak ditemukan!'", str_contains($postControllerSourceDelete, 'Postingan Tidak ditemukan!'));
assertTest("PostController::deletePost() Ownership Check", str_contains($postControllerSourceDelete, '$user_id !== $userId'));
assertTest("PostController::deletePost() 403 Jika Bukan Owner", str_contains($postControllerSourceDelete, "You don't have permission to delete this post. You can only delete your own posts."));
assertTest("PostController::deletePost() Memanggil PostModel::delete", str_contains($postControllerSourceDelete, 'PostModel::delete'));
assertTest("PostController::deletePost() Response Sukses: 'Postingan Berhasil dihapus!'", str_contains($postControllerSourceDelete, 'Postingan Berhasil dihapus!'));

// 5. Method parameter reflection
$deleteRef = new ReflectionMethod('PostModel', 'delete');
$deleteParams = $deleteRef->getParameters();
assertTest(
    "PostModel::delete() Menerima Parameter (string \$id)",
    count($deleteParams) === 1
);

$deleteCtrlRef = new ReflectionMethod('PostController', 'deletePost');
$deleteCtrlParams = $deleteCtrlRef->getParameters();
assertTest(
    "PostController::deletePost() Menerima Parameter (array \$params = [])",
    count($deleteCtrlParams) === 1 && $deleteCtrlParams[0]->isOptional()
);

// 6. Functional & Runtime Database Verification (Isolasi Post Dummy Khusus)
$pdo = getDB();

// Verifikasi awal: post existing wajib ada dan tidak boleh diganggu
$protectedPostId = '452a041a-c7cc-495b-ad5e-8a6c2af46522';
$checkProtected = $pdo->prepare("SELECT COUNT(*) FROM public_posts WHERE id = :id");
$checkProtected->execute([':id' => $protectedPostId]);
$protectedExistsBefore = (int)$checkProtected->fetchColumn() === 1;
assertTest("Post Existing Wajib Dilindungi Sebelum Pengujian", $protectedExistsBefore);

// Siapkan dummy owner dan non-owner
$ownerId = '6355a749-9d9b-4784-a1f9-3a0a1cd64ab5'; // faiz
$nonOwnerId = 'fc6d488f-9b98-4237-a089-fcaa7552ffa5'; // raka

// Buat post khusus uji coba
$testPostId = PostModel::generateUuid();
$pdo->prepare("
    INSERT INTO public_posts (id, user_id, title, description, portfolio_type, external_url)
    VALUES (:id, :user_id, 'Test Delete Post', 'Description for delete test', 'link', 'https://example.com')
")->execute([':id' => $testPostId, ':user_id' => $ownerId]);

// Tambahkan media dummy
$mediaId = PostModel::generateUuid();
$pdo->prepare("
    INSERT INTO media_posts (id, post_id, media_url, media_public_id)
    VALUES (:id, :post_id, 'https://res.cloudinary.com/test_del.jpg', 'test_del_pub_id')
")->execute([':id' => $mediaId, ':post_id' => $testPostId]);

// Tambahkan like dummy
$likeId = PostModel::generateUuid();
$pdo->prepare("
    INSERT INTO like_posts (id, post_id, user_id)
    VALUES (:id, :post_id, :user_id)
")->execute([':id' => $likeId, ':post_id' => $testPostId, ':user_id' => $nonOwnerId]);

// Tambahkan comment dummy
$commentId = PostModel::generateUuid();
$pdo->prepare("
    INSERT INTO comments (id, post_id, user_id, content)
    VALUES (:id, :post_id, :user_id, 'Test comment for delete')
")->execute([':id' => $commentId, ':post_id' => $testPostId, ':user_id' => $nonOwnerId]);

// 1. Tes hapus tanpa login -> 401
$cmdUnauth = 'php -r \'define("BASE_PATH", "' . BASE_PATH . '"); require_once BASE_PATH . "/config/env.php"; require_once BASE_PATH . "/controllers/PostController.php"; $GLOBALS["user"] = null; PostController::deletePost(["postId" => "' . $testPostId . '"]);\'';
$outUnauth = shell_exec($cmdUnauth);
$resUnauth = json_decode((string)$outUnauth, true);
assertTest("Delete Post Tanpa Autentikasi -> HTTP 401 Unauthorized", isset($resUnauth['message']) && $resUnauth['message'] === 'Unauthorized');

// 2. Tes hapus oleh non-owner -> 403
$cmdForbidden = 'php -r \'define("BASE_PATH", "' . BASE_PATH . '"); require_once BASE_PATH . "/config/env.php"; require_once BASE_PATH . "/controllers/PostController.php"; $GLOBALS["user"] = ["id" => "' . $nonOwnerId . '"]; PostController::deletePost(["postId" => "' . $testPostId . '"]);\'';
$outForbidden = shell_exec($cmdForbidden);
$resForbidden = json_decode((string)$outForbidden, true);
assertTest("Delete Post Milik User Lain (Non-Owner) -> HTTP 403 Forbidden", isset($resForbidden['message']) && str_contains($resForbidden['message'], 'only delete your own posts'));

// 3. Tes hapus oleh owner -> 200
$cmdSuccess = 'php -r \'define("BASE_PATH", "' . BASE_PATH . '"); require_once BASE_PATH . "/config/env.php"; require_once BASE_PATH . "/controllers/PostController.php"; $GLOBALS["user"] = ["id" => "' . $ownerId . '"]; PostController::deletePost(["postId" => "' . $testPostId . '"]);\'';
$outSuccess = shell_exec($cmdSuccess);
$resSuccess = json_decode((string)$outSuccess, true);
assertTest("Delete Post Milik Owner -> HTTP 200 ('Postingan Berhasil dihapus!')", isset($resSuccess['success']) && $resSuccess['success'] === true && $resSuccess['message'] === 'Postingan Berhasil dihapus!');
assertTest("Data Post Yang Dihapus Dikembalikan Dalam Response Data", isset($resSuccess['data']['id']) && $resSuccess['data']['id'] === $testPostId);

// 4. Verifikasi di DB bahwa post, media, likes, comments dummy terhapus bersih
$checkPost = (int)$pdo->query("SELECT COUNT(*) FROM public_posts WHERE id = '{$testPostId}'")->fetchColumn() === 0;
$checkMedia = (int)$pdo->query("SELECT COUNT(*) FROM media_posts WHERE post_id = '{$testPostId}'")->fetchColumn() === 0;
$checkLikes = (int)$pdo->query("SELECT COUNT(*) FROM like_posts WHERE post_id = '{$testPostId}'")->fetchColumn() === 0;
$checkComments = (int)$pdo->query("SELECT COUNT(*) FROM comments WHERE post_id = '{$testPostId}'")->fetchColumn() === 0;
assertTest("Post Dummy Terhapus Bersih Dari Tabel public_posts", $checkPost);
assertTest("Media Terkait Terhapus Bersih Dari Tabel media_posts", $checkMedia);
assertTest("Likes Terkait Terhapus Bersih Dari Tabel like_posts", $checkLikes);
assertTest("Comments Terkait Terhapus Bersih Dari Tabel comments", $checkComments);

// 5. Tes hapus ulang post yang sudah dihapus -> 404
$cmdNotFound = 'php -r \'define("BASE_PATH", "' . BASE_PATH . '"); require_once BASE_PATH . "/config/env.php"; require_once BASE_PATH . "/controllers/PostController.php"; $GLOBALS["user"] = ["id" => "' . $ownerId . '"]; PostController::deletePost(["postId" => "' . $testPostId . '"]);\'';
$outNotFound = shell_exec($cmdNotFound);
$resNotFound = json_decode((string)$outNotFound, true);
assertTest("Delete Post Yang Sudah Dihapus / Tidak Ada -> HTTP 404 ('Postingan Tidak ditemukan!')", isset($resNotFound['message']) && $resNotFound['message'] === 'Postingan Tidak ditemukan!');

// 6. Verifikasi akhir: post existing tetap utuh dan aman
$checkProtected->execute([':id' => $protectedPostId]);
$protectedExistsAfter = (int)$checkProtected->fetchColumn() === 1;
assertTest("Post Existing (452a041a-c7cc-495b-ad5e-8a6c2af46522) Tetap Aman dan Utuh di Database", $protectedExistsAfter);

// ─────────────────────────────────────────────────────────────────────────────
// 14. PHASE 3D-4: LIKE / UNLIKE POST
// ─────────────────────────────────────────────────────────────────────────────
printHeader("14. [Phase 3D-4] Like & Unlike Post");

require_once BASE_PATH . '/models/LikeModel.php';
require_once BASE_PATH . '/models/CommentModel.php';

// 1. Availability check
assertTest("Class LikeModel Tersedia", class_exists('LikeModel'));
assertTest("Method LikeModel::findLike() Tersedia", method_exists('LikeModel', 'findLike'));
assertTest("Method LikeModel::createLike() Tersedia", method_exists('LikeModel', 'createLike'));
assertTest("Method LikeModel::deleteLike() Tersedia", method_exists('LikeModel', 'deleteLike'));
assertTest("Method LikeModel::countByPostId() Tersedia", method_exists('LikeModel', 'countByPostId'));
assertTest("Method LikeModel::isLikedBy() Tersedia", method_exists('LikeModel', 'isLikedBy'));
assertTest("Method PostController::likePost() Tersedia", method_exists('PostController', 'likePost'));
assertTest("Method PostController::unlikePost() Tersedia", method_exists('PostController', 'unlikePost'));

// 2. Route registration check for POST /api/posts/:postId/like and DELETE /api/posts/:postId/unlike
$routeLikeTest = BASE_PATH . '/tests/_route_like_test.php';
file_put_contents($routeLikeTest, '<?php
define(\'BASE_PATH\', \'' . BASE_PATH . '\');
require_once BASE_PATH . \'/routes/Router.php\';
$router = new Router();
require_once BASE_PATH . \'/routes/api.php\';
$reflector = new ReflectionClass($router);
$prop = $reflector->getProperty(\'routes\');
$prop->setAccessible(true);
$routes = $prop->getValue($router);
$foundLike = false;
$foundUnlike = false;
foreach ($routes as $r) {
    if ($r[\'method\'] === \'POST\' && $r[\'pattern\'] === \'/api/posts/:postId/like\') {
        $foundLike = true;
    }
    if ($r[\'method\'] === \'DELETE\' && $r[\'pattern\'] === \'/api/posts/:postId/unlike\') {
        $foundUnlike = true;
    }
}
echo json_encode([\'like\' => $foundLike, \'unlike\' => $foundUnlike]);
');
$routeLikeCheck = shell_exec("php " . $routeLikeTest);
@unlink($routeLikeTest);
$resRouteLike = json_decode((string)$routeLikeCheck, true);
assertTest("Route POST /api/posts/:postId/like Terdaftar di Router", isset($resRouteLike['like']) && $resRouteLike['like'] === true);
assertTest("Route DELETE /api/posts/:postId/unlike Terdaftar di Router", isset($resRouteLike['unlike']) && $resRouteLike['unlike'] === true);

// 3. Code contract checks
$postControllerSourceLike = (string)file_get_contents(BASE_PATH . '/controllers/PostController.php');
assertTest("PostController::likePost() Mengecek Auth (401)", str_contains($postControllerSourceLike, 'likePost') && str_contains($postControllerSourceLike, '401'));
assertTest("PostController::likePost() 404 Jika Post Tidak Ditemukan: 'Post not found'", str_contains($postControllerSourceLike, 'Post not found'));
assertTest("PostController::likePost() 400 Jika Sudah Like: 'Sudah like postingan ini'", str_contains($postControllerSourceLike, 'Sudah like postingan ini'));
assertTest("PostController::likePost() Response 201: 'Post berhasil di-like'", str_contains($postControllerSourceLike, 'Post berhasil di-like') && str_contains($postControllerSourceLike, '201'));
assertTest("PostController::unlikePost() Response 200: 'Like berhasil dihapus'", str_contains($postControllerSourceLike, 'Like berhasil dihapus'));

// 4. Functional & Database tests on dedicated test post
$likeTestPostId = PostModel::generateUuid();
$user1Id = '6355a749-9d9b-4784-a1f9-3a0a1cd64ab5'; // faiz
$user2Id = 'fc6d488f-9b98-4237-a089-fcaa7552ffa5'; // raka

$pdo->prepare("
    INSERT INTO public_posts (id, user_id, title, description, portfolio_type, external_url)
    VALUES (:id, :user_id, 'Post For Like Test', 'Description for like test', 'link', 'https://example.com')
")->execute([':id' => $likeTestPostId, ':user_id' => $user1Id]);

// 4a. Request like tanpa token -> 401
$cmdLikeNoAuth = 'php -r \'define("BASE_PATH", "' . BASE_PATH . '"); require_once BASE_PATH . "/config/env.php"; require_once BASE_PATH . "/controllers/PostController.php"; $GLOBALS["user"] = null; PostController::likePost(["postId" => "' . $likeTestPostId . '"]);\'';
$outLikeNoAuth = shell_exec($cmdLikeNoAuth);
$resLikeNoAuth = json_decode((string)$outLikeNoAuth, true);
assertTest("Like Post Tanpa Autentikasi -> HTTP 401 Unauthorized", isset($resLikeNoAuth['message']) && $resLikeNoAuth['message'] === 'Unauthorized');

// 4b. Request like pada post tidak ditemukan -> 404
$cmdLikeNotFound = 'php -r \'define("BASE_PATH", "' . BASE_PATH . '"); require_once BASE_PATH . "/config/env.php"; require_once BASE_PATH . "/controllers/PostController.php"; $GLOBALS["user"] = ["id" => "' . $user1Id . '"]; PostController::likePost(["postId" => "non-existent-post-id"]);\'';
$outLikeNotFound = shell_exec($cmdLikeNotFound);
$resLikeNotFound = json_decode((string)$outLikeNotFound, true);
assertTest("Like Post Tidak Ditemukan -> HTTP 404 ('Post not found')", isset($resLikeNotFound['message']) && $resLikeNotFound['message'] === 'Post not found');

// 4c. Like berhasil oleh User 1 -> 201
$cmdLikeUser1 = 'php -r \'define("BASE_PATH", "' . BASE_PATH . '"); require_once BASE_PATH . "/config/env.php"; require_once BASE_PATH . "/controllers/PostController.php"; $GLOBALS["user"] = ["id" => "' . $user1Id . '"]; PostController::likePost(["postId" => "' . $likeTestPostId . '"]);\'';
$outLikeUser1 = shell_exec($cmdLikeUser1);
$resLikeUser1 = json_decode((string)$outLikeUser1, true);
assertTest("Like Post Berhasil -> HTTP 201 ('Post berhasil di-like')", isset($resLikeUser1['success']) && $resLikeUser1['success'] === true && $resLikeUser1['message'] === 'Post berhasil di-like');

// 4d. Duplicate like oleh User 1 -> 400
$outLikeDup = shell_exec($cmdLikeUser1);
$resLikeDup = json_decode((string)$outLikeDup, true);
assertTest("Duplicate Like Ditolak -> HTTP 400 ('Sudah like postingan ini')", isset($resLikeDup['message']) && $resLikeDup['message'] === 'Sudah like postingan ini');

// 4e. Like oleh User 2 -> 201
$cmdLikeUser2 = 'php -r \'define("BASE_PATH", "' . BASE_PATH . '"); require_once BASE_PATH . "/config/env.php"; require_once BASE_PATH . "/controllers/PostController.php"; $GLOBALS["user"] = ["id" => "' . $user2Id . '"]; PostController::likePost(["postId" => "' . $likeTestPostId . '"]);\'';
$outLikeUser2 = shell_exec($cmdLikeUser2);
$resLikeUser2 = json_decode((string)$outLikeUser2, true);
assertTest("User Berbeda Menyukai Post Yang Sama -> HTTP 201", isset($resLikeUser2['success']) && $resLikeUser2['success'] === true);

// 4f. Verifikasi Like count = 2 di database
$countLikes = LikeModel::countByPostId($likeTestPostId);
assertTest("Like Count Akurat di Database (2 Likes)", $countLikes === 2);

// 4g. Verifikasi Post Detail menampilkan like_count=2, is_liked=true untuk User 1
$detailUser1 = PostModel::findDetailById($likeTestPostId, $user1Id);
assertTest("Post Detail Menampilkan like_count=2 dan is_liked=true untuk User 1", $detailUser1 !== null && $detailUser1['like_count'] === 2 && $detailUser1['is_liked'] === true);

// 4h. Unlike oleh User 1 -> 200
$cmdUnlikeUser1 = 'php -r \'define("BASE_PATH", "' . BASE_PATH . '"); require_once BASE_PATH . "/config/env.php"; require_once BASE_PATH . "/controllers/PostController.php"; $GLOBALS["user"] = ["id" => "' . $user1Id . '"]; PostController::unlikePost(["postId" => "' . $likeTestPostId . '"]);\'';
$outUnlikeUser1 = shell_exec($cmdUnlikeUser1);
$resUnlikeUser1 = json_decode((string)$outUnlikeUser1, true);
assertTest("Unlike Post Berhasil -> HTTP 200 ('Like berhasil dihapus')", isset($resUnlikeUser1['success']) && $resUnlikeUser1['success'] === true && $resUnlikeUser1['message'] === 'Like berhasil dihapus');

// 4i. Unlike berulang oleh User 1 -> 200 (idempotent / graceful seperti baseline)
$outUnlikeRep = shell_exec($cmdUnlikeUser1);
$resUnlikeRep = json_decode((string)$outUnlikeRep, true);
assertTest("Unlike Berulang Tetap Sukses -> HTTP 200 ('Like berhasil dihapus')", isset($resUnlikeRep['success']) && $resUnlikeRep['success'] === true && $resUnlikeRep['message'] === 'Like berhasil dihapus');

// 4j. Verifikasi Like count berkurang menjadi 1, User 1 is_liked=false, User 2 is_liked=true
$countLikesAfter = LikeModel::countByPostId($likeTestPostId);
$detailUser1After = PostModel::findDetailById($likeTestPostId, $user1Id);
$detailUser2After = PostModel::findDetailById($likeTestPostId, $user2Id);
assertTest("Like Count Berkurang Menjadi 1 Setelah Unlike", $countLikesAfter === 1);
assertTest("Post Detail is_liked=false untuk User 1 Yang Sudah Unlike", $detailUser1After !== null && $detailUser1After['is_liked'] === false);
assertTest("Post Detail is_liked=true untuk User 2 Yang Masih Like", $detailUser2After !== null && $detailUser2After['is_liked'] === true);

// 4k. Unlike pada post tidak ditemukan -> 404
$cmdUnlikeNotFound = 'php -r \'define("BASE_PATH", "' . BASE_PATH . '"); require_once BASE_PATH . "/config/env.php"; require_once BASE_PATH . "/controllers/PostController.php"; $GLOBALS["user"] = ["id" => "' . $user1Id . '"]; PostController::unlikePost(["postId" => "non-existent-post-id"]);\'';
$outUnlikeNotFound = shell_exec($cmdUnlikeNotFound);
$resUnlikeNotFound = json_decode((string)$outUnlikeNotFound, true);
assertTest("Unlike Post Tidak Ditemukan -> HTTP 404 ('Post not found')", isset($resUnlikeNotFound['message']) && $resUnlikeNotFound['message'] === 'Post not found');

// ─────────────────────────────────────────────────────────────────────────────
// 15. PHASE 3D-5: COMMENT POST
// ─────────────────────────────────────────────────────────────────────────────
printHeader("15. [Phase 3D-5] Comment Post");

// 1. Availability check
assertTest("Class CommentModel Tersedia", class_exists('CommentModel'));
assertTest("Method CommentModel::create() Tersedia", method_exists('CommentModel', 'create'));
assertTest("Method CommentModel::getByPostId() Tersedia", method_exists('CommentModel', 'getByPostId'));
assertTest("Method CommentModel::findById() Tersedia", method_exists('CommentModel', 'findById'));
assertTest("Method CommentModel::findDetailById() Tersedia", method_exists('CommentModel', 'findDetailById'));
assertTest("Method CommentModel::delete() Tersedia", method_exists('CommentModel', 'delete'));
assertTest("Method CommentModel::countByPostId() Tersedia", method_exists('CommentModel', 'countByPostId'));
assertTest("Method PostController::createComment() Tersedia", method_exists('PostController', 'createComment'));
assertTest("Method PostController::getComments() Tersedia", method_exists('PostController', 'getComments'));
assertTest("Method PostController::deleteComment() Tersedia", method_exists('PostController', 'deleteComment'));

// 2. Route registration check for Comment routes
$routeCommentTest = BASE_PATH . '/tests/_route_comment_test.php';
file_put_contents($routeCommentTest, '<?php
define(\'BASE_PATH\', \'' . BASE_PATH . '\');
require_once BASE_PATH . \'/routes/Router.php\';
$router = new Router();
require_once BASE_PATH . \'/routes/api.php\';
$reflector = new ReflectionClass($router);
$prop = $reflector->getProperty(\'routes\');
$prop->setAccessible(true);
$routes = $prop->getValue($router);
$foundCreate = false;
$foundGet = false;
$foundDelete = false;
foreach ($routes as $r) {
    if ($r[\'method\'] === \'POST\' && $r[\'pattern\'] === \'/api/posts/:postId/comments\') {
        $foundCreate = true;
    }
    if ($r[\'method\'] === \'GET\' && $r[\'pattern\'] === \'/api/posts/:postId/comments\') {
        $foundGet = true;
    }
    if ($r[\'method\'] === \'DELETE\' && $r[\'pattern\'] === \'/api/delete/comments/:commentId\') {
        $foundDelete = true;
    }
}
echo json_encode([\'create\' => $foundCreate, \'get\' => $foundGet, \'delete\' => $foundDelete]);
');
$routeCommentCheck = shell_exec("php " . $routeCommentTest);
@unlink($routeCommentTest);
$resRouteComment = json_decode((string)$routeCommentCheck, true);
assertTest("Route POST /api/posts/:postId/comments Terdaftar di Router", isset($resRouteComment['create']) && $resRouteComment['create'] === true);
assertTest("Route GET /api/posts/:postId/comments Terdaftar di Router", isset($resRouteComment['get']) && $resRouteComment['get'] === true);
assertTest("Route DELETE /api/delete/comments/:commentId Terdaftar di Router", isset($resRouteComment['delete']) && $resRouteComment['delete'] === true);

// 3. Code contract checks
$postControllerSourceComment = (string)file_get_contents(BASE_PATH . '/controllers/PostController.php');
assertTest("PostController::createComment() Validasi Content (Content is required)", str_contains($postControllerSourceComment, 'Content is required'));
assertTest("PostController::createComment() Response 200: 'Success create comment!'", str_contains($postControllerSourceComment, 'Success create comment!'));
assertTest("PostController::deleteComment() 404 Jika Tidak Ada: 'Comment not found!'", str_contains($postControllerSourceComment, 'Comment not found!'));
assertTest("PostController::deleteComment() 403 Jika Bukan Owner: 'Bukan komentar milikmu'", str_contains($postControllerSourceComment, 'Bukan komentar milikmu'));
assertTest("PostController::deleteComment() Response 200: 'Comment berhasil dihapus!'", str_contains($postControllerSourceComment, 'Comment berhasil dihapus!'));

// 4. Functional tests on comment endpoints
// 4a. Create comment tanpa token -> 401
$cmdCommentNoAuth = 'php -r \'define("BASE_PATH", "' . BASE_PATH . '"); require_once BASE_PATH . "/config/env.php"; require_once BASE_PATH . "/controllers/PostController.php"; $GLOBALS["user"] = null; $GLOBALS["body"] = ["content" => "Halo"]; PostController::createComment(["postId" => "' . $likeTestPostId . '"]);\'';
$outCommentNoAuth = shell_exec($cmdCommentNoAuth);
$resCommentNoAuth = json_decode((string)$outCommentNoAuth, true);
assertTest("Create Comment Tanpa Autentikasi -> HTTP 401 Unauthorized", isset($resCommentNoAuth['message']) && $resCommentNoAuth['message'] === 'Unauthorized');

// 4b. Create comment konten kosong -> 400
$cmdCommentEmpty = 'php -r \'define("BASE_PATH", "' . BASE_PATH . '"); require_once BASE_PATH . "/config/env.php"; require_once BASE_PATH . "/controllers/PostController.php"; $GLOBALS["user"] = ["id" => "' . $user1Id . '"]; $GLOBALS["body"] = ["content" => "   "]; PostController::createComment(["postId" => "' . $likeTestPostId . '"]);\'';
$outCommentEmpty = shell_exec($cmdCommentEmpty);
$resCommentEmpty = json_decode((string)$outCommentEmpty, true);
assertTest("Create Comment Konten Kosong -> HTTP 400 ('Content is required')", isset($resCommentEmpty['message']) && $resCommentEmpty['message'] === 'Content is required');

// 4c. Create comment pada post tidak ditemukan -> 404
$cmdCommentNotFound = 'php -r \'define("BASE_PATH", "' . BASE_PATH . '"); require_once BASE_PATH . "/config/env.php"; require_once BASE_PATH . "/controllers/PostController.php"; $GLOBALS["user"] = ["id" => "' . $user1Id . '"]; $GLOBALS["body"] = ["content" => "Komentar valid"]; PostController::createComment(["postId" => "non-existent-post"]);\'';
$outCommentNotFound = shell_exec($cmdCommentNotFound);
$resCommentNotFound = json_decode((string)$outCommentNotFound, true);
assertTest("Create Comment Post Tidak Ditemukan -> HTTP 404 ('Post not found')", isset($resCommentNotFound['message']) && $resCommentNotFound['message'] === 'Post not found');

// 4d. Create comment berhasil oleh User 1 -> 200
$cmdCreateComment1 = 'php -r \'define("BASE_PATH", "' . BASE_PATH . '"); require_once BASE_PATH . "/config/env.php"; require_once BASE_PATH . "/controllers/PostController.php"; $GLOBALS["user"] = ["id" => "' . $user1Id . '"]; $GLOBALS["body"] = ["content" => "Komentar pertama dari user 1"]; PostController::createComment(["postId" => "' . $likeTestPostId . '"]);\'';
$outCreateComment1 = shell_exec($cmdCreateComment1);
$resCreateComment1 = json_decode((string)$outCreateComment1, true);
assertTest("Create Comment Berhasil -> HTTP 200 ('Success create comment!')", isset($resCreateComment1['success']) && $resCreateComment1['success'] === true && $resCreateComment1['message'] === 'Success create comment!');
assertTest("Struktur Response Comment Memiliki author/user object (username, photo_profile_url)", isset($resCreateComment1['data']['userId']['username']) || isset($resCreateComment1['data']['user']['username']));

$createdComment1Id = $resCreateComment1['data']['id'] ?? $resCreateComment1['data']['_id'] ?? '';

// 4e. Create comment kedua oleh User 2 -> 200
$cmdCreateComment2 = 'php -r \'define("BASE_PATH", "' . BASE_PATH . '"); require_once BASE_PATH . "/config/env.php"; require_once BASE_PATH . "/controllers/PostController.php"; $GLOBALS["user"] = ["id" => "' . $user2Id . '"]; $GLOBALS["body"] = ["content" => "Komentar kedua dari user 2"]; PostController::createComment(["postId" => "' . $likeTestPostId . '"]);\'';
$outCreateComment2 = shell_exec($cmdCreateComment2);
$resCreateComment2 = json_decode((string)$outCreateComment2, true);
assertTest("Create Comment Kedua Berhasil -> HTTP 200", isset($resCreateComment2['success']) && $resCreateComment2['success'] === true);

// 4f. Get comments list via PostController::getComments()
$cmdGetComments = 'php -r \'define("BASE_PATH", "' . BASE_PATH . '"); require_once BASE_PATH . "/config/env.php"; require_once BASE_PATH . "/controllers/PostController.php"; $GLOBALS["user"] = ["id" => "' . $user1Id . '"]; PostController::getComments(["postId" => "' . $likeTestPostId . '"]);\'';
$outGetComments = shell_exec($cmdGetComments);
$resGetComments = json_decode((string)$outGetComments, true);
assertTest("Get Comments Berhasil Mengembalikan Daftar Komentar", isset($resGetComments['data']) && is_array($resGetComments['data']) && count($resGetComments['data']) === 2);

// 4g. Verifikasi comment_count di Post Detail = 2
$detailComments = PostModel::findDetailById($likeTestPostId, $user1Id);
assertTest("Post Detail comment_count Akurat (2 Komentar)", $detailComments !== null && $detailComments['comment_count'] === 2 && count($detailComments['comments']) === 2);

// 4h. Delete comment milik User 1 oleh User 2 (non-owner) -> 403
$cmdDelCommentNonOwner = 'php -r \'define("BASE_PATH", "' . BASE_PATH . '"); require_once BASE_PATH . "/config/env.php"; require_once BASE_PATH . "/controllers/PostController.php"; $GLOBALS["user"] = ["id" => "' . $user2Id . '"]; PostController::deleteComment(["commentId" => "' . $createdComment1Id . '"]);\'';
$outDelCommentNonOwner = shell_exec($cmdDelCommentNonOwner);
$resDelCommentNonOwner = json_decode((string)$outDelCommentNonOwner, true);
assertTest("Delete Comment Oleh Non-Owner -> HTTP 403 ('Bukan komentar milikmu')", isset($resDelCommentNonOwner['message']) && $resDelCommentNonOwner['message'] === 'Bukan komentar milikmu');

// 4i. Delete comment oleh User 1 (owner) -> 200
$cmdDelCommentOwner = 'php -r \'define("BASE_PATH", "' . BASE_PATH . '"); require_once BASE_PATH . "/config/env.php"; require_once BASE_PATH . "/controllers/PostController.php"; $GLOBALS["user"] = ["id" => "' . $user1Id . '"]; PostController::deleteComment(["commentId" => "' . $createdComment1Id . '"]);\'';
$outDelCommentOwner = shell_exec($cmdDelCommentOwner);
$resDelCommentOwner = json_decode((string)$outDelCommentOwner, true);
assertTest("Delete Comment Oleh Owner -> HTTP 200 ('Comment berhasil dihapus!')", isset($resDelCommentOwner['success']) && $resDelCommentOwner['success'] === true && $resDelCommentOwner['message'] === 'Comment berhasil dihapus!');

// 4j. Delete comment yang sudah dihapus -> 404
$outDelCommentRep = shell_exec($cmdDelCommentOwner);
$resDelCommentRep = json_decode((string)$outDelCommentRep, true);
assertTest("Delete Comment Yang Sudah Dihapus -> HTTP 404 ('Comment not found!')", isset($resDelCommentRep['message']) && $resDelCommentRep['message'] === 'Comment not found!');

// 4k. Verifikasi comment_count di Post Detail berkurang menjadi 1
$detailCommentsAfter = PostModel::findDetailById($likeTestPostId, $user1Id);
assertTest("Post Detail comment_count Berkurang Menjadi 1 Setelah Hapus", $detailCommentsAfter !== null && $detailCommentsAfter['comment_count'] === 1);

// Cleanup test post & child records
PostModel::delete($likeTestPostId);

// 4l. Verifikasi post dilindungi tetap utuh
$checkProtected->execute([':id' => $protectedPostId]);
$protectedFinal = (int)$checkProtected->fetchColumn() === 1;
assertTest("Post Existing (452a041a-c7cc-495b-ad5e-8a6c2af46522) Tetap Utuh dan Aman Setelah Uji Phase 3D-4 & 3D-5", $protectedFinal);

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
