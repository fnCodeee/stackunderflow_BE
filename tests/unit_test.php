<?php

/**
 * =============================================================================
 * UNIT TEST RUNNER — STACK UNDERFLOW / ASTEL BACKEND (PHP NATIVE)
 * Dibuat untuk Pengujian Mandiri & Dokumentasi Laporan Proyek UTS
 * =============================================================================
 *
 * Cara Menjalankan:
 *   cd backend-php
 *   php tests/unit_test.php
 * =============================================================================
 */

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

// Format output warna ANSI di terminal
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
echo "           PENGUJIAN UNIT TEST BACKEND PHP NATIVE (UTS)             \n";
echo "====================================================================" . COLOR_RESET . "\n";

// ─────────────────────────────────────────────────────────────────────────────
// 1. PENGUJIAN ENVIRONMENT LOADER
// ─────────────────────────────────────────────────────────────────────────────
printHeader("1. Pengujian Environment Loader (config/env.php)");

require_once BASE_PATH . '/config/env.php';

assertTest("Variabel APP_ENV berhasil dimuat", isset($_ENV['APP_ENV']) && $_ENV['APP_ENV'] !== '');
assertTest("Variabel DB_HOST berhasil dimuat", isset($_ENV['DB_HOST']) && $_ENV['DB_HOST'] !== '');
assertTest("Variabel DB_NAME berhasil dimuat", isset($_ENV['DB_NAME']) && $_ENV['DB_NAME'] === 'db_stack_underflow');
assertTest("Variabel APP_PORT berhasil dimuat", isset($_ENV['APP_PORT']));

// ─────────────────────────────────────────────────────────────────────────────
// 2. PENGUJIAN RESPONSE HELPER
// ─────────────────────────────────────────────────────────────────────────────
printHeader("2. Pengujian Response Helper (utils/Response.php)");

require_once BASE_PATH . '/utils/Response.php';

assertTest("Class Response berhasil di-load", class_exists('Response'));
assertTest("Method Response::json() tersedia", method_exists('Response', 'json'));
assertTest("Method Response::success() tersedia", method_exists('Response', 'success'));
assertTest("Method Response::error() tersedia", method_exists('Response', 'error'));

// ─────────────────────────────────────────────────────────────────────────────
// 3. PENGUJIAN ROUTER (PATH PARAMETERS & PRIORITY)
// ─────────────────────────────────────────────────────────────────────────────
printHeader("3. Pengujian Router (routes/Router.php)");

require_once BASE_PATH . '/routes/Router.php';

$router = new Router();
$reflector = new ReflectionClass($router);
$matchMethod = $reflector->getMethod('matchRoute');
$matchMethod->setAccessible(true);

// Test Dynamic Matching
$res1 = $matchMethod->invoke($router, '/api/posts/:postId', '/api/posts/post-uuid-123');
assertTest("Ekstraksi Path Parameter tunggal (:postId)", $res1 === ['postId' => 'post-uuid-123']);

$res2 = $matchMethod->invoke($router, '/api/profiles/:userId/posts', '/api/profiles/user-999/posts');
assertTest("Ekstraksi Path Parameter di tengah URL (:userId)", $res2 === ['userId' => 'user-999']);

$res3 = $matchMethod->invoke($router, '/api/collab/applications/:applicationId/accept', '/api/collab/applications/app-456/accept');
assertTest("Ekstraksi Path Parameter nested complex", $res3 === ['applicationId' => 'app-456']);

$res4 = $matchMethod->invoke($router, '/api/posts/:postId', '/api/users/profile');
assertTest("Penolakan URL yang tidak cocok (Mismatch URL)", $res4 === null);

// Test Static vs Dynamic Priority
$routerPriority = new Router();
$routerPriority->get('/api/collab/:id', fn() => null);
$routerPriority->get('/api/collab/inbox', fn() => null);

$routesProp = $reflector->getProperty('routes');
$routesProp->setAccessible(true);
$allRoutes = $routesProp->getValue($routerPriority);

$staticList  = array_values(array_filter($allRoutes, fn($r) => !str_contains($r['pattern'], ':')));
$dynamicList = array_values(array_filter($allRoutes, fn($r) => str_contains($r['pattern'], ':')));

assertTest(
    "Prioritas Route Statis (/api/collab/inbox) di atas Dinamis (/api/collab/:id)",
    isset($staticList[0]) && $staticList[0]['pattern'] === '/api/collab/inbox'
);

// ─────────────────────────────────────────────────────────────────────────────
// 4. PENGUJIAN JWT UTILITY (HS256)
// ─────────────────────────────────────────────────────────────────────────────
printHeader("4. Pengujian JWT Utility (utils/JWT.php)");

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
// 5. PENGUJIAN AUTH MIDDLEWARE
// ─────────────────────────────────────────────────────────────────────────────
printHeader("5. Pengujian Auth Middleware (middlewares/AuthMiddleware.php)");

// A. Missing Header -> 403
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

// B. Malformed Header -> 403
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

// C. Expired Bearer -> 401
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

// D. Valid Bearer -> Success Injection
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
// 6. PENGUJIAN INTEGRASI ROUTER + AUTH MIDDLEWARE
// ─────────────────────────────────────────────────────────────────────────────
printHeader("6. Pengujian Integrasi Router + AuthMiddleware Chain");

$outChain = shell_exec("php -r \"
define('BASE_PATH', '" . BASE_PATH . "');
\\\$_ENV['JWT_SECRET'] = '{$secretKunci}';
require_once BASE_PATH . '/utils/Response.php';
require_once BASE_PATH . '/utils/JWT.php';
require_once BASE_PATH . '/routes/Router.php';
require_once BASE_PATH . '/middlewares/AuthMiddleware.php';

\\\$router = new Router();
\\\$router->get('/api/protected/profile', [AuthMiddleware::class, 'handle'], function(\\\$params) {
    Response::json(['userId' => \\\$GLOBALS['user']['id']], 200, 'Berhasil akses protected route');
});

\\\$_SERVER['REQUEST_METHOD'] = 'GET';
\\\$_SERVER['REQUEST_URI'] = '/api/protected/profile';
\\\$token = JWT::encode(['id' => 'user-chain-pass'], 3600);
\\\$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . \\\$token;

\\\$router->dispatch();
\"");
$resChain = json_decode((string)$outChain, true);
assertTest(
    "Chaining Handler: Dispatch -> AuthMiddleware -> Controller Execution",
    isset($resChain['success']) && $resChain['success'] === true && $resChain['data']['userId'] === 'user-chain-pass'
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
    echo "\n" . COLOR_GREEN . COLOR_BOLD . "✔ SELURUH PENGUJIAN UNIT TEST BERHASIL 100%!" . COLOR_RESET . "\n";
    echo COLOR_BOLD . "====================================================================\n" . COLOR_RESET;
    exit(0);
} else {
    echo "\n" . COLOR_RED . COLOR_BOLD . "✖ TERDAPAT PENGUJIAN YANG GAGAL!" . COLOR_RESET . "\n";
    echo COLOR_BOLD . "====================================================================\n" . COLOR_RESET;
    exit(1);
}
