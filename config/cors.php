<?php

/**
 * config/cors.php
 * ─────────────────────────────────────────────────────────────────────────────
 * CORS HANDLER  –  Cross-Origin Resource Sharing
 *
 * Mengikuti behavior existing Express:
 *   origin: "*"
 *   methods: GET, POST, PUT, DELETE, OPTIONS
 *
 * Cara kerja:
 *  1. Kirim header CORS ke semua response
 *  2. Jika method adalah OPTIONS (preflight request dari browser),
 *     langsung balas 200 OK dan hentikan eksekusi lebih lanjut.
 *     Ini penting agar browser mengizinkan request cross-origin berikutnya.
 *
 * CATATAN PENGEMBANGAN:
 *  Untuk production, ganti Access-Control-Allow-Origin dari "*"
 *  menjadi domain frontend spesifik (misal: "https://astel.example.com")
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

// ── Header CORS wajib untuk setiap response ───────────────────────────────────
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Max-Age: 86400'); // cache preflight 24 jam

// ── Handle OPTIONS preflight ──────────────────────────────────────────────────
// Browser mengirim request OPTIONS sebelum request "berbahaya" (POST, PUT, DELETE)
// untuk memeriksa apakah server mengizinkan cross-origin request.
// Kita balas 200 OK langsung dan berhenti — tidak perlu lanjut ke router.
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}
