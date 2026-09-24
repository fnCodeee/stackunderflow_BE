<?php

/**
 * routes/Router.php
 * ─────────────────────────────────────────────────────────────────────────────
 * CUSTOM HTTP ROUTER  –  Sederhana, tanpa framework
 *
 * Fitur yang didukung:
 *  ✓  GET, POST, PUT, DELETE
 *  ✓  Path parameters   → /api/posts/:postId  → $params['postId']
 *  ✓  Static vs dynamic → /api/collab/inbox dievaluasi SEBELUM /api/collab/:id
 *  ✓  Route not found   → 404 JSON
 *  ✓  Method not allowed → 405 JSON
 *  ✓  Middleware chain  → array callable, dijalankan berurutan
 *
 * Cara penggunaan di routes/api.php:
 *
 *   $router->get('/', function() {
 *       Response::success("Server Berhasil dijalankan!");
 *   });
 *
 *   $router->get('/api/posts/:postId', $authMiddleware, function(array $params) {
 *       $postId = $params['postId'];
 *       // ...
 *   });
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * CATATAN ARSITEKTUR — Routing Priority
 *
 * Dari audit (Section G, Risk #8):
 *   Route /api/collab/inbox vs /api/collab/:id
 *   → Jika dynamic route dievaluasi lebih dulu, "inbox" akan dianggap sebagai :id
 *
 * Solusi yang diimplementasikan di sini:
 *   Route yang tidak mengandung ':' (static) selalu dievaluasi lebih dulu
 *   daripada route yang mengandung ':' (dynamic), TERLEPAS dari urutan pendaftaran.
 *   (Sama seperti perilaku Express Router)
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

class Router
{
    /**
     * Tabel route yang terdaftar.
     * Format: [['method' => 'GET', 'pattern' => '/api/posts/:id', 'handlers' => [...]], ...]
     *
     * @var array<int, array{method: string, pattern: string, handlers: callable[]}>
     */
    private array $routes = [];

    // ── Shortcut method registration ─────────────────────────────────────────

    /** Daftarkan route GET */
    public function get(string $pattern, callable ...$handlers): void
    {
        $this->addRoute('GET', $pattern, $handlers);
    }

    /** Daftarkan route POST */
    public function post(string $pattern, callable ...$handlers): void
    {
        $this->addRoute('POST', $pattern, $handlers);
    }

    /** Daftarkan route PUT */
    public function put(string $pattern, callable ...$handlers): void
    {
        $this->addRoute('PUT', $pattern, $handlers);
    }

    /** Daftarkan route DELETE */
    public function delete(string $pattern, callable ...$handlers): void
    {
        $this->addRoute('DELETE', $pattern, $handlers);
    }

    // ── Internal route registration ───────────────────────────────────────────

    /**
     * Tambahkan route ke tabel routes.
     *
     * @param  string     $method    HTTP method (GET, POST, PUT, DELETE)
     * @param  string     $pattern   URL pattern, misal '/api/posts/:postId'
     * @param  callable[] $handlers  Array callable: [middleware1, middleware2, ..., handlerUtama]
     */
    private function addRoute(string $method, string $pattern, array $handlers): void
    {
        $this->routes[] = [
            'method'   => strtoupper($method),
            'pattern'  => $pattern,
            'handlers' => $handlers,
        ];
    }

    // ── Dispatch ──────────────────────────────────────────────────────────────

    /**
     * Proses request yang masuk:
     *  1. Ambil method dan URI dari $_SERVER
     *  2. Cari route yang cocok (static dulu, baru dynamic)
     *  3. Ekstrak path params dari URL
     *  4. Jalankan handler chain (middleware + final handler)
     *  5. Jika tidak ada route yang cocok → 404 atau 405
     */
    public function dispatch(): void
    {
        $requestMethod = strtoupper($_SERVER['REQUEST_METHOD']);
        $requestUri    = $this->parseUri();

        // Pisahkan routes menjadi static (tanpa ':') dan dynamic (dengan ':')
        // Static routes mendapat prioritas lebih tinggi
        $staticRoutes  = array_filter($this->routes, fn($r) => !str_contains($r['pattern'], ':'));
        $dynamicRoutes = array_filter($this->routes, fn($r) => str_contains($r['pattern'], ':'));

        // Urutan evaluasi: static dulu, baru dynamic
        $orderedRoutes = array_merge(array_values($staticRoutes), array_values($dynamicRoutes));

        $matchedPatterns = []; // Kumpulkan pattern yang match (meski method beda) untuk 405

        foreach ($orderedRoutes as $route) {
            $params = $this->matchRoute($route['pattern'], $requestUri);

            if ($params === null) {
                // URI tidak cocok dengan pattern ini, lanjut ke route berikutnya
                continue;
            }

            // URI cocok — catat pattern ini untuk deteksi 405
            $matchedPatterns[] = $route['pattern'];

            if ($route['method'] !== $requestMethod) {
                // URI cocok tapi method berbeda → lanjut cari route lain
                continue;
            }

            // ✓ URI dan method cocok — jalankan handler chain
            $this->runHandlers($route['handlers'], $params);
            return;
        }

        // Tidak ada route yang cocok
        require_once BASE_PATH . '/utils/Response.php';

        if (!empty($matchedPatterns)) {
            // URI ditemukan tapi method tidak ada → 405 Method Not Allowed
            Response::error('Method tidak diizinkan untuk endpoint ini', 405);
        } else {
            // URI tidak ditemukan sama sekali → 404 Not Found
            Response::error('Route tidak ditemukan', 404);
        }
    }

    // ── URI Parsing ───────────────────────────────────────────────────────────

    /**
     * Ambil path URI bersih tanpa query string.
     *
     * Contoh: '/api/posts?page=1' → '/api/posts'
     *
     * @return string  Path URI yang sudah di-trim
     */
    private function parseUri(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';

        // Hapus query string (bagian setelah '?')
        $pos = strpos($uri, '?');
        if ($pos !== false) {
            $uri = substr($uri, 0, $pos);
        }

        // Normalisasi: hilangkan trailing slash kecuali root '/'
        $uri = rtrim($uri, '/') ?: '/';

        return $uri;
    }

    // ── Route Matching ────────────────────────────────────────────────────────

    /**
     * Cocokkan pattern route dengan URI aktual.
     *
     * Contoh:
     *   pattern: '/api/posts/:postId'
     *   uri:     '/api/posts/abc-123'
     *   return:  ['postId' => 'abc-123']
     *
     *   pattern: '/api/posts'
     *   uri:     '/api/posts'
     *   return:  []  (cocok, tapi tidak ada params)
     *
     *   pattern: '/api/posts/:postId'
     *   uri:     '/api/profiles/x'
     *   return:  null  (tidak cocok)
     *
     * @param  string       $pattern  Pattern route, misal '/api/posts/:postId'
     * @param  string       $uri      URI aktual dari request
     * @return array<string,string>|null  Array params jika cocok, null jika tidak
     */
    private function matchRoute(string $pattern, string $uri): ?array
    {
        // Konversi pattern ke regex:
        //   :paramName → named capture group (?P<paramName>[^/]+)
        // Escape karakter regex lain terlebih dahulu
        $regexPattern = preg_replace_callback(
            '/:([a-zA-Z_][a-zA-Z0-9_]*)/',
            fn($matches) => '(?P<' . $matches[1] . '>[^/]+)',
            preg_quote($pattern, '#')
        );

        // Setelah preg_quote, ':' tidak akan ada lagi di luar named group
        // tapi kita perlu un-quote hasil replacement named group
        // Karena preg_quote sudah dipanggil dulu, kita lakukan pendekatan berbeda:
        $regexPattern = $this->buildRegex($pattern);

        $matched = preg_match($regexPattern, $uri, $matches);

        if (!$matched) {
            return null;
        }

        // Ekstrak hanya named captures (parameter dengan nama)
        $params = [];
        foreach ($matches as $key => $value) {
            if (is_string($key)) {
                $params[$key] = $value;
            }
        }

        return $params;
    }

    /**
     * Bangun regex pattern dari URL pattern string.
     *
     * Proses:
     *  1. Split pattern berdasarkan '/'
     *  2. Untuk setiap segmen:
     *     - Jika diawali ':' → ubah jadi named capture group
     *     - Jika tidak → escape karakter regex
     *  3. Gabungkan kembali dan bungkus dengan delimiter dan anchor
     *
     * @param  string  $pattern  URL pattern, misal '/api/posts/:postId/comments'
     * @return string  Regex pattern siap pakai untuk preg_match
     */
    private function buildRegex(string $pattern): string
    {
        $segments = explode('/', $pattern);
        $regexSegments = [];

        foreach ($segments as $segment) {
            if (str_starts_with($segment, ':')) {
                // Ubah :paramName menjadi named capture group
                $paramName       = substr($segment, 1);
                $regexSegments[] = '(?P<' . $paramName . '>[^/]+)';
            } else {
                // Escape karakter spesial regex di segmen static
                $regexSegments[] = preg_quote($segment, '#');
            }
        }

        // Gabungkan dan bungkus dengan anchor (^...$) agar exact match
        return '#^' . implode('/', $regexSegments) . '$#';
    }

    // ── Handler Execution ─────────────────────────────────────────────────────

    /**
     * Jalankan chain handler: [middleware1, middleware2, ..., finalHandler]
     *
     * Setiap handler menerima argument $params (path parameters).
     *
     * Untuk Phase 1, tidak ada middleware yang digunakan.
     * Untuk Phase 2+, middleware akan dipanggil berurutan sebelum final handler.
     *
     * @param  callable[]               $handlers  Chain handler yang akan dijalankan
     * @param  array<string,string>     $params    Path parameters yang diekstrak dari URL
     */
    private function runHandlers(array $handlers, array $params): void
    {
        // Untuk Phase 1: langsung panggil semua handler secara berurutan.
        // Setiap handler bisa berupa middleware atau final handler.
        // Middleware biasanya tidak memanggil Response::json (tidak exit),
        // sementara final handler memanggil Response::json (exit).
        foreach ($handlers as $handler) {
            call_user_func($handler, $params);
        }
    }
}
