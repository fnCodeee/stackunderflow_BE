<?php

/**
 * controllers/UploadController.php
 * ─────────────────────────────────────────────────────────────────────────────
 * UPLOAD CONTROLLER
 *
 * Menangani upload file gambar ke Cloudinary:
 *  - uploadImage() -> POST /api/upload (auth required)
 *
 * Endpoint ini HANYA mengunggah dan mengembalikan URL — tidak menyimpan
 * apa pun ke database. Pemanggil (mis. PostModal) yang memakai URL +
 * public_id hasil upload saat membuat post/konten.
 *
 * Upload memakai SIGNED REST API Cloudinary (tanpa SDK, plain curl):
 *  POST https://api.cloudinary.com/v1_1/<cloud_name>/image/upload
 *  dengan field multipart: file (CURLFile), api_key, timestamp,
 *  signature, folder. Signature = sha1(params_terurut + api_secret).
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

require_once BASE_PATH . '/utils/Response.php';

class UploadController
{
    /** MIME gambar yang diterima. */
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
    ];

    /** Ukuran maksimum file: 5 MB. */
    private const MAX_FILE_SIZE = 5 * 1024 * 1024;

    /** Folder Cloudinary tempat gambar disimpan. */
    private const CLOUDINARY_FOLDER = 'underflow';

    /**
     * Handler untuk POST /api/upload
     * Mengunggah SATU file gambar (field multipart `file`) ke Cloudinary
     * dan mengembalikan secure_url + public_id-nya.
     *
     * @param  array<string, string> $params Path parameters dari Router
     * @return void
     */
    public static function uploadImage(array $params = []): void
    {
        try {
            $userId = $GLOBALS['user']['id'] ?? null;

            if (!$userId) {
                Response::json(null, 401, 'Unauthorized', false);
            }

            // 1. Validasi file terunggah.
            if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
                Response::json(null, 400, 'Tidak ada file yang diunggah.', false);
            }

            $file = $_FILES['file'];

            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                Response::json(null, 400, 'Gagal mengunggah file. Coba lagi.', false);
            }

            $tmpPath = $file['tmp_name'] ?? '';
            if (!is_string($tmpPath) || $tmpPath === '' || !is_uploaded_file($tmpPath)) {
                Response::json(null, 400, 'File tidak valid.', false);
            }

            // 2. Validasi ukuran (maks 5 MB).
            $size = (int)($file['size'] ?? 0);
            if ($size <= 0 || $size > self::MAX_FILE_SIZE) {
                Response::json(null, 400, 'Ukuran gambar maksimal 5 MB.', false);
            }

            // 3. Validasi tipe file — deteksi dari isi file, bukan dari
            // nama/ekstensi yang dikirim client.
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = $finfo !== false ? finfo_file($finfo, $tmpPath) : false;
            if ($finfo !== false) {
                finfo_close($finfo);
            }

            if (!is_string($mime) || !in_array($mime, self::ALLOWED_MIME_TYPES, true)) {
                Response::json(null, 400, 'File harus berupa gambar (JPG, PNG, WebP, atau GIF).', false);
            }

            // 4. Konfigurasi Cloudinary dari .env.
            // Utama: CLOUDINARY_URL (cloudinary://<key>:<secret>@<cloud>).
            // Fallback: tiga var terpisah.
            $cloudName = '';
            $apiKey = '';
            $apiSecret = '';
            $cloudinaryUrl = trim((string)(getenv('CLOUDINARY_URL') ?: ''));
            if ($cloudinaryUrl !== '') {
                $parts = parse_url($cloudinaryUrl);
                if (
                    is_array($parts)
                    && isset($parts['host'], $parts['user'], $parts['pass'])
                    && $parts['host'] !== '' && $parts['user'] !== '' && $parts['pass'] !== ''
                ) {
                    $cloudName = $parts['host'];
                    $apiKey = urldecode($parts['user']);
                    $apiSecret = urldecode($parts['pass']);
                }
            }
            if ($cloudName === '' || $apiKey === '' || $apiSecret === '') {
                $cloudName = trim((string)(getenv('CLOUDINARY_CLOUD_NAME') ?: ''));
                $apiKey = trim((string)(getenv('CLOUDINARY_API_KEY') ?: ''));
                $apiSecret = trim((string)(getenv('CLOUDINARY_API_SECRET') ?: ''));
            }

            if ($cloudName === '' || $apiKey === '' || $apiSecret === '') {
                Response::json(null, 500, 'Upload gambar belum dikonfigurasi.', false);
            }

            // 5. Signed upload ke Cloudinary.
            // Param yang ditandatangani HARUS terurut alfabetis:
            // folder, timestamp.
            $timestamp = time();
            $paramsToSign = 'folder=' . self::CLOUDINARY_FOLDER . '&timestamp=' . $timestamp;
            $signature = sha1($paramsToSign . $apiSecret);

            $url = 'https://api.cloudinary.com/v1_1/' . $cloudName . '/image/upload';

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_POSTFIELDS, [
                'file'      => new CURLFile($tmpPath, $mime, $file['name'] ?? 'upload'),
                'api_key'   => $apiKey,
                'timestamp' => (string)$timestamp,
                'signature' => $signature,
                'folder'    => self::CLOUDINARY_FOLDER,
            ]);

            $raw = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($raw === false) {
                Response::json(null, 502, 'Gagal mengunggah gambar: ' . $curlError, false);
            }

            $decoded = json_decode((string)$raw, true);
            if (!is_array($decoded)) {
                Response::json(null, 502, 'Gagal mengunggah gambar: respons Cloudinary tidak valid.', false);
            }

            if ($httpCode !== 200 || isset($decoded['error'])) {
                $cloudMessage = is_array($decoded['error'] ?? null)
                    ? (string)($decoded['error']['message'] ?? 'Upload gagal.')
                    : 'Upload gagal.';
                Response::json(null, 502, 'Gagal mengunggah gambar: ' . $cloudMessage, false);
            }

            $secureUrl = (string)($decoded['secure_url'] ?? '');
            $publicId = (string)($decoded['public_id'] ?? '');

            if ($secureUrl === '' || $publicId === '') {
                Response::json(null, 502, 'Gagal mengunggah gambar: respons Cloudinary tidak lengkap.', false);
            }

            Response::json([
                'url'       => $secureUrl,
                'public_id' => $publicId,
            ], 200, 'Gambar berhasil diunggah', true);
        } catch (Throwable $e) {
            Response::json(null, 500, $e->getMessage(), false);
        }
    }
    /** Batas penerbitan tanda tangan per user per jam (anti penyalahgunaan). */
    private const SIGNATURE_LIMIT_PER_HOUR = 60;

    /**
     * Membaca konfigurasi Cloudinary dari .env.
     * Utama: CLOUDINARY_URL (cloudinary://<key>:<secret>@<cloud>);
     * fallback: tiga var terpisah. Logika yang sama seperti blok
     * konfigurasi di uploadImage() — diduplikasi sementara supaya
     * perubahan direct upload ini seminimal mungkin menyentuh kode lama;
     * blok lama hilang bersama uploadImage() saat fase pembersihan.
     *
     * @return array{cloud_name: string, api_key: string, api_secret: string}
     */
    private static function cloudinaryConfig(): array
    {
        $cloudName = '';
        $apiKey    = '';
        $apiSecret = '';

        $cloudinaryUrl = trim((string)(getenv('CLOUDINARY_URL') ?: ''));
        if ($cloudinaryUrl !== '') {
            $parts = parse_url($cloudinaryUrl);
            if (
                is_array($parts)
                && isset($parts['host'], $parts['user'], $parts['pass'])
                && $parts['host'] !== '' && $parts['user'] !== '' && $parts['pass'] !== ''
            ) {
                $cloudName = $parts['host'];
                $apiKey    = urldecode($parts['user']);
                $apiSecret = urldecode($parts['pass']);
            }
        }

        if ($cloudName === '' || $apiKey === '' || $apiSecret === '') {
            $cloudName = trim((string)(getenv('CLOUDINARY_CLOUD_NAME') ?: ''));
            $apiKey    = trim((string)(getenv('CLOUDINARY_API_KEY') ?: ''));
            $apiSecret = trim((string)(getenv('CLOUDINARY_API_SECRET') ?: ''));
        }

        return ['cloud_name' => $cloudName, 'api_key' => $apiKey, 'api_secret' => $apiSecret];
    }

    /**
     * Handler untuk POST /api/upload/signature
     *
     * Menerbitkan tanda tangan supaya frontend bisa mengunggah file
     * LANGSUNG ke Cloudinary tanpa file mampir ke server ini
     * (menghemat CPU/RAM/bandwidth server). File diunggah client ke
     * Cloudinary memakai api_key + timestamp + signature dari sini,
     * lalu client mengirim URL hasilnya saat membuat post/collab.
     *
     * Yang ditandatangani (terurut alfabetis, sesuai aturan Cloudinary):
     * folder, tags, timestamp. Tags berisi cap identitas pengupload
     * ("user_<id>") sehingga setiap aset yang masuk bisa dilacak
     * pemiliknya dan tidak bisa diganti oleh client.
     *
     * Setiap penerbitan dicatat di tabel upload_grants dan dibatasi
     * SIGNATURE_LIMIT_PER_HOUR per user per jam.
     *
     * @param  array<string, string> $params Path parameters dari Router
     * @return void
     */
    public static function signature(array $params = []): void
    {
        try {
            $userId = $GLOBALS['user']['id'] ?? null;

            if (!$userId) {
                Response::json(null, 401, 'Unauthorized', false);
            }

            $config = self::cloudinaryConfig();
            if ($config['cloud_name'] === '' || $config['api_key'] === '' || $config['api_secret'] === '') {
                Response::json(null, 500, 'Upload gambar belum dikonfigurasi.', false);
            }

            $pdo = getDB();

            // Batas pemakaian: hitung tanda tangan yang diterbitkan
            // untuk user ini dalam 1 jam terakhir.
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM upload_grants WHERE user_id = :uid AND created_at > (NOW() - INTERVAL 1 HOUR)'
            );
            $stmt->execute([':uid' => $userId]);
            if ((int)$stmt->fetchColumn() >= self::SIGNATURE_LIMIT_PER_HOUR) {
                Response::json(null, 429, 'Terlalu banyak permintaan upload. Coba lagi nanti.', false);
            }

            $timestamp = time();
            $tags      = 'user_' . $userId;
            $paramsToSign = 'folder=' . self::CLOUDINARY_FOLDER . '&tags=' . $tags . '&timestamp=' . $timestamp;
            $signature = sha1($paramsToSign . $config['api_secret']);

            $stmt = $pdo->prepare('INSERT INTO upload_grants (user_id) VALUES (:uid)');
            $stmt->execute([':uid' => $userId]);

            Response::json([
                'cloud_name' => $config['cloud_name'],
                'api_key'    => $config['api_key'],
                'timestamp'  => $timestamp,
                'signature'  => $signature,
                'folder'     => self::CLOUDINARY_FOLDER,
                'tags'       => $tags,
            ], 200, 'Tanda tangan upload diterbitkan', true);
        } catch (Throwable $e) {
            Response::json(null, 500, $e->getMessage(), false);
        }
    }

    /**
     * Validasi daftar media yang dikirim client saat membuat/mengubah
     * post atau collab. Semua URL harus berasal dari Cloudinary milik
     * sendiri dan folder underflow; public_id yang diisi (boleh kosong
     * untuk data lama) juga harus dari folder yang sama. Maksimal 5.
     * Dipanggil dari PostController dan CollabController.
     *
     * @param  array<int, mixed> $mediaUrls
     * @param  array<int, mixed> $mediaPublicIds
     * @return void  Response::json di dalamnya selalu exit.
     */
    public static function assertValidMedia(array $mediaUrls, array $mediaPublicIds = []): void
    {
        if (count($mediaUrls) > 5) {
            Response::json(null, 400, 'Maksimal 5 gambar.', false);
        }

        if (count($mediaUrls) === 0) {
            return;
        }

        $config = self::cloudinaryConfig();
        if ($config['cloud_name'] === '') {
            Response::json(null, 500, 'Upload gambar belum dikonfigurasi.', false);
        }

        $prefix = 'https://res.cloudinary.com/' . $config['cloud_name'] . '/image/upload/';
        foreach ($mediaUrls as $url) {
            if (!is_string($url) || !str_starts_with($url, $prefix)) {
                Response::json(null, 400, 'URL media tidak valid.', false);
            }
        }

        foreach ($mediaPublicIds as $publicId) {
            // Kosong/null dilewati: data lama memang ada yang belum
            // punya public_id. Yang terisi wajib dari folder sendiri.
            if (is_string($publicId) && $publicId !== '' && !str_starts_with($publicId, self::CLOUDINARY_FOLDER . '/')) {
                Response::json(null, 400, 'Media tidak valid.', false);
            }
        }
    }
}
