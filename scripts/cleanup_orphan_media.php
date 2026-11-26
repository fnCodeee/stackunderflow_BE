<?php

/**
 * scripts/cleanup_orphan_media.php
 * =========================================================
 * PEMBERSIH FILE YATIM CLOUDINARY
 *
 * File yatim = gambar yang sudah terunggah ke Cloudinary tapi
 * tidak dirujuk oleh data mana pun di database (post batal,
 * tab ditutup, pembuatan post gagal, sisa penyalahgunaan).
 * Dibiarkan, file seperti ini memakan kuota Cloudinary pelan-
 * pelan selamanya.
 *
 * Cara kerja:
 *   1. Ambil daftar aset di folder "underflow" dari Cloudinary.
 *   2. Ambil daftar public_id yang dirujuk database
 *      (media_posts, collab_media, profiles) — kolom public_id
 *      langsung, plus ekstrak dari URL untuk baris lama yang
 *      public_id-nya masih kosong.
 *   3. Aset yang tidak dirujuk DAN umurnya melewati batas
 *      (default 24 jam, supaya foto yang baru diunggah dan
 *      post-nya masih disiapkan tidak ikut terhapus) = yatim.
 *   4. Mode default hanya MELAPORKAN (dry-run). Penghapusan
 *      sungguhan hanya bila diberi --execute.
 *
 * Pemakaian:
 *   php scripts/cleanup_orphan_media.php                  -> laporan saja
 *   php scripts/cleanup_orphan_media.php --execute        -> hapus yatim > 24 jam
 *   php scripts/cleanup_orphan_media.php --hours=0        -> tanpa batas umur
 *   php scripts/cleanup_orphan_media.php --only=<id>      -> batasi ke satu aset
 *
 * Dijalankan berkala oleh service docker "media-cleaner"
 * (lihat docker-compose.yml), bisa juga manual dari CLI.
 * =========================================================
 */

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/config/env.php';
require_once BASE_PATH . '/config/database.php';

$execute = in_array('--execute', $argv ?? [], true);
$hours   = 24;
$only    = null;
foreach (($argv ?? []) as $arg) {
    if (str_starts_with($arg, '--hours=')) {
        $hours = max(0, (int)substr($arg, 8));
    }
    if (str_starts_with($arg, '--only=')) {
        $only = substr($arg, 7);
    }
}

/** Konfigurasi Cloudinary dari .env (pola sama seperti UploadController). */
function cloudinaryConfig(): array
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

/** Satu panggilan Admin API Cloudinary. Mengembalikan [http_code, array|null]. */
function cloudinaryApi(array $config, string $method, string $path, string $rawQuery = ''): array
{
    $url = 'https://api.cloudinary.com/v1_1/' . $config['cloud_name'] . $path
        . ($rawQuery !== '' ? '?' . $rawQuery : '');

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_USERPWD, $config['api_key'] . ':' . $config['api_secret']);

    $raw  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    return [$code, is_array($decoded) ? $decoded : null];
}

/** Ekstrak public_id dari URL Cloudinary (untuk baris lama tanpa public_id). */
function extractPublicId(string $url): ?string
{
    if (!str_contains($url, 'res.cloudinary.com')) {
        return null;
    }
    if (preg_match('#/image/upload/(?:v\d+/)?(.+)\.[A-Za-z0-9]+(?:\?.*)?$#', $url, $m)) {
        return $m[1];
    }
    return null;
}

$config = cloudinaryConfig();
if ($config['cloud_name'] === '' || $config['api_key'] === '' || $config['api_secret'] === '') {
    fwrite(STDERR, "Cloudinary belum dikonfigurasi di .env\n");
    exit(1);
}

$folder = 'underflow';

// 1. Daftar aset di folder underflow (dengan paginasi cursor).
$assets = [];
$cursor = null;
do {
    $query = 'prefix=' . urlencode($folder . '/') . '&max_results=500';
    if ($cursor !== null) {
        $query .= '&next_cursor=' . urlencode($cursor);
    }
    [$code, $res] = cloudinaryApi($config, 'GET', '/resources/image/upload', $query);
    if ($code !== 200 || $res === null) {
        fwrite(STDERR, "Gagal mengambil daftar aset Cloudinary (HTTP {$code})\n");
        exit(1);
    }
    foreach (($res['resources'] ?? []) as $r) {
        $assets[] = ['public_id' => (string)$r['public_id'], 'created_at' => (string)$r['created_at']];
    }
    $cursor = $res['next_cursor'] ?? null;
} while ($cursor !== null);

// 2. public_id yang dirujuk database.
$pdo = getDB();
$referenced = [];
foreach ([
    'SELECT media_public_id FROM media_posts',
    'SELECT media_public_id FROM collab_media',
    'SELECT photo_profile_public_id FROM profiles',
] as $q) {
    foreach ($pdo->query($q)->fetchAll(PDO::FETCH_COLUMN) as $pid) {
        if (is_string($pid) && $pid !== '') {
            $referenced[$pid] = true;
        }
    }
}
foreach ([
    'SELECT media_url FROM media_posts',
    'SELECT media_url FROM collab_media',
    'SELECT photo_profile_url FROM profiles',
] as $q) {
    foreach ($pdo->query($q)->fetchAll(PDO::FETCH_COLUMN) as $url) {
        $pid = is_string($url) ? extractPublicId($url) : null;
        if ($pid !== null) {
            $referenced[$pid] = true;
        }
    }
}

// 3. Kandidat yatim: tidak dirujuk + melewati batas umur.
$cutoff = time() - $hours * 3600;
$candidates = [];
foreach ($assets as $asset) {
    if ($only !== null && $asset['public_id'] !== $only) {
        continue;
    }
    if (isset($referenced[$asset['public_id']])) {
        continue;
    }
    if (strtotime($asset['created_at']) > $cutoff) {
        continue;
    }
    $candidates[] = $asset['public_id'];
}

echo 'Mode: ' . ($execute ? 'EXECUTE (menghapus)' : 'DRY-RUN (laporan saja)') . "\n";
echo 'Aset di folder ' . $folder . ': ' . count($assets) . '; dirujuk DB: ' . count($referenced)
    . '; kandidat yatim (>' . $hours . ' jam): ' . count($candidates) . "\n";
foreach ($candidates as $pid) {
    echo '  YATIM: ' . $pid . "\n";
}

if (!$execute) {
    echo "DRY-RUN selesai: tidak ada yang dihapus. Tambahkan --execute untuk menghapus.\n";
    exit(0);
}

// 4. Hapus per 100 aset.
$deletedTotal = 0;
foreach (array_chunk($candidates, 100) as $chunk) {
    $query = implode('&', array_map(
        fn(string $pid) => 'public_ids[]=' . urlencode($pid),
        $chunk
    ));
    [$code, $res] = cloudinaryApi($config, 'DELETE', '/resources/image/upload', $query);
    if ($code !== 200 || $res === null) {
        fwrite(STDERR, "Gagal menghapus sebagian aset (HTTP {$code})\n");
        exit(1);
    }
    $deletedTotal += count($res['deleted'] ?? []);
}
echo 'Terhapus: ' . $deletedTotal . " aset.\n";
