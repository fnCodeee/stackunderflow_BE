<?php

/**
 * utils/Response.php
 * ─────────────────────────────────────────────────────────────────────────────
 * RESPONSE HELPER  –  Format JSON response yang konsisten
 *
 * Memastikan semua response API memiliki struktur yang sama:
 *
 *   {
 *     "success": true|false,
 *     "message": "...",
 *     "data": ...
 *   }
 *
 * Ini sesuai dengan kontrak API existing Express yang wajib dipertahankan
 * agar frontend tidak perlu diubah (lihat audit: Section F - API Compatibility).
 *
 * Cara penggunaan:
 *
 *   // Response sukses dengan data
 *   Response::json(['name' => 'Faiza'], 200, 'Berhasil!');
 *
 *   // Response error
 *   Response::error('Route tidak ditemukan', 404);
 *
 *   // Response sukses tanpa data
 *   Response::success('Server berjalan!');
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

class Response
{
    /**
     * Kirim JSON response dengan struktur standar API.
     *
     * Method ini langsung mengirim response dan menghentikan eksekusi (exit).
     * Setelah dipanggil, tidak ada kode yang akan dijalankan lagi.
     *
     * @param  mixed   $data        Data yang dikembalikan ke client (bisa array, null, string)
     * @param  int     $statusCode  HTTP status code (200, 201, 400, 401, 403, 404, 500)
     * @param  string  $message     Pesan yang menjelaskan hasil operasi
     * @param  bool    $success     Status sukses atau gagal
     * @return never                Selalu exit setelah mengirim response
     */
    public static function json(
        mixed  $data       = null,
        int    $statusCode = 200,
        string $message    = '',
        bool   $success    = true
    ): never {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');

        echo json_encode([
            'success' => $success,
            'message' => $message,
            'data'    => $data,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        exit();
    }

    /**
     * Shortcut: response sukses (success=true, status=200).
     *
     * @param  mixed   $data       Data yang dikembalikan
     * @param  string  $message    Pesan sukses
     * @param  int     $statusCode HTTP status code (default 200)
     * @return never
     */
    public static function success(
        mixed  $data       = null,
        string $message    = 'Berhasil',
        int    $statusCode = 200
    ): never {
        self::json($data, $statusCode, $message, true);
    }

    /**
     * Shortcut: response error (success=false).
     *
     * @param  string  $message    Pesan error
     * @param  int     $statusCode HTTP status code (default 500)
     * @param  mixed   $data       Data tambahan (opsional, biasanya null)
     * @return never
     */
    public static function error(
        string $message    = 'Terjadi kesalahan',
        int    $statusCode = 500,
        mixed  $data       = null
    ): never {
        self::json($data, $statusCode, $message, false);
    }
}
