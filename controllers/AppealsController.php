<?php

/**
 * controllers/AppealsController.php
 * ─────────────────────────────────────────────────────────────────────────────
 * BANDING SISI USER (spec-admin.md §4.4 + §8, Fase 3)
 *
 * - POST /api/appeals            banding atas notice penghapusan
 *                                (user sedang login, dari thread sistem)
 * - GET  /api/appeals            banding milik user (status per aksi,
 *                                untuk state tombol di thread sistem)
 * - POST /api/appeals/suspended  banding dari layar login — tanpa sesi;
 *                                user terverifikasi lewat kredensial
 *                                yang barusan dipakai mencoba login
 *                                (password, atau ID token Google).
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

require_once BASE_PATH . '/models/AppealModel.php';
require_once BASE_PATH . '/models/UserModel.php';
require_once BASE_PATH . '/models/NotificationModel.php';
require_once BASE_PATH . '/utils/Realtime.php';
require_once BASE_PATH . '/utils/Response.php';
require_once BASE_PATH . '/utils/RateLimiter.php';

class AppealsController
{
    private static function body(): array
    {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw ?: '', true);
        return is_array($data) ? $data : [];
    }

    private static function actor(): array
    {
        return $GLOBALS['user'] ?? [];
    }

    private static function row(array $a): array
    {
        return [
            'id' => $a['id'],
            'moderation_action_id' => $a['moderation_action_id'],
            'message' => $a['message'],
            'status' => $a['status'],
            'decision_note' => $a['decision_note'] ?? null,
            'decided_at' => $a['decided_at'] ?? null,
            'created_at' => $a['created_at'],
            'action_type' => $a['action_type'] ?? null,
            'target_type' => $a['target_type'] ?? null,
            'target_id' => $a['target_id'] ?? null,
        ];
    }

    private static function notifySupers(string $actorId, string $appealId): void
    {
        try {
            $stmt = getDB()->prepare("SELECT id FROM users WHERE role = 'super_admin'");
            $stmt->execute();
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $superId) {
                if ((string) $superId === $actorId) {
                    continue;
                }
                $row = NotificationModel::createAdminNotification((string) $superId, $actorId, 'admin_appeal', 'Mengajukan banding', $appealId);
                if ($row !== null) {
                    $apiRow = NotificationModel::findApiRow($row['id']);
                    if ($apiRow !== null) {
                        Realtime::publishNotification((string) $superId, $apiRow);
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('[APPEAL NOTIFY ERROR] ' . $e->getMessage());
        }
    }

    /** POST /api/appeals — banding atas sebuah tindakan moderasi. */
    public static function create(array $params = []): void
    {
        try {
            $actor = self::actor();
            $body = self::body();
            $actionId = trim((string) ($body['moderation_action_id'] ?? ''));
            $message = trim((string) ($body['message'] ?? ''));
            if ($actionId === '' || mb_strlen($message) < 3) {
                Response::json(null, 400, 'Jelaskan alasan bandingmu.', false);
            }
            $action = ModerationService::actionById($actionId);
            if ($action === null) {
                Response::json(null, 404, 'Tindakan moderasi tidak ditemukan.', false);
            }
            if ((string) ($action['target_owner_id'] ?? '') !== (string) $actor['id']) {
                Response::json(null, 403, 'Banding hanya bisa diajukan untuk tindakan pada akun atau kontenmu.', false);
            }
            if (!in_array((string) $action['action_type'], ModerationService::APPEALABLE, true)) {
                Response::json(null, 400, 'Tindakan ini tidak bisa dibanding.', false);
            }
            if (!empty($action['reverted_at'])) {
                Response::json(null, 409, 'Tindakan ini sudah dibatalkan, banding tidak diperlukan.', false);
            }
            if (AppealModel::findByActionId($actionId) !== null) {
                Response::json(null, 409, 'Kamu sudah mengajukan banding untuk tindakan ini.', false);
            }
            $appeal = AppealModel::create((string) $actor['id'], $actionId, $message);
            self::notifySupers((string) $actor['id'], (string) $appeal['id']);
            $full = AppealModel::listForUser((string) $actor['id']);
            Response::json(['appeal' => self::row($full[0] ?? $appeal)], 201, 'Banding terkirim.', true);
        } catch (Throwable $e) {
            error_log('[APPEALS CREATE ERROR] ' . $e->getMessage());
            Response::json(null, 500, 'Terjadi kesalahan pada server.', false);
        }
    }

    /** GET /api/appeals — banding milik user yang login. */
    public static function mine(array $params = []): void
    {
        try {
            $actor = self::actor();
            $rows = array_map(static fn(array $a) => self::row($a), AppealModel::listForUser((string) $actor['id']));
            Response::json(['appeals' => $rows], 200, 'OK', true);
        } catch (Throwable $e) {
            error_log('[APPEALS MINE ERROR] ' . $e->getMessage());
            Response::json(null, 500, 'Terjadi kesalahan pada server.', false);
        }
    }

    /** POST /api/appeals/suspended — banding dari layar login (tanpa sesi). */
    public static function suspended(array $params = []): void
    {
        try {
            $body = self::body();
            // Rate limit banding-suspend (audit P0): endpoint ini
            // memverifikasi kredensial tanpa sesi, target empuk
            // brute force. Bucket per IP+username 10/jam, per IP 30/jam.
            $clientIp = RateLimiter::clientIp();
            $appealBucket = 'appeal-u:' . hash('sha256', $clientIp . '|' . strtolower(trim((string) ($body['username'] ?? ''))));
            $appealIpBucket = 'appeal-ip:' . $clientIp;
            if (RateLimiter::blocked($appealBucket) || RateLimiter::blocked($appealIpBucket)) {
                Response::json(null, 429, 'Terlalu banyak percobaan. Coba lagi nanti.', false);
            }
            $message = trim((string) ($body['message'] ?? ''));
            if (mb_strlen($message) < 3) {
                Response::json(null, 400, 'Jelaskan alasan bandingmu.', false);
            }
            $user = self::verifyCredentials($body);
            if ($user === null) {
                $appealAllowed = RateLimiter::hit($appealBucket, 10, 3600);
                $appealIpAllowed = RateLimiter::hit($appealIpBucket, 30, 3600);
                if (!$appealAllowed || !$appealIpAllowed) {
                    Response::json(null, 429, 'Terlalu banyak percobaan. Coba lagi nanti.', false);
                }
                // Sengaja generik: jangan bocorkan bagian mana yang salah.
                Response::json(null, 401, 'Kredensial tidak valid.', false);
            }
            RateLimiter::clear($appealBucket);
            if (($user['account_status'] ?? 'active') !== 'suspended') {
                Response::json(null, 400, 'Akun ini tidak sedang disuspend.', false);
            }
            $action = AppealModel::activeSuspendAction((string) $user['id']);
            if ($action === null) {
                Response::json(null, 409, 'Tidak ada suspend aktif yang bisa dibanding.', false);
            }
            $existing = AppealModel::findByActionId((string) $action['id']);
            if ($existing !== null) {
                Response::json(['appeal' => self::row($existing)], 409, 'Kamu sudah mengajukan banding untuk suspend ini.', false);
            }
            $appeal = AppealModel::create((string) $user['id'], (string) $action['id'], $message);
            self::notifySupers((string) $user['id'], (string) $appeal['id']);
            Response::json(['appeal' => self::row($appeal)], 201, 'Banding terkirim.', true);
        } catch (Throwable $e) {
            error_log('[APPEALS SUSPENDED ERROR] ' . $e->getMessage());
            Response::json(null, 500, 'Terjadi kesalahan pada server.', false);
        }
    }

    /**
     * Verifikasi kredensial untuk banding suspend: password akun, atau
     * ID token Google (verifikasi tokeninfo persis seperti
     * AuthController::google — aud, iss, exp, email terverifikasi).
     */
    private static function verifyCredentials(array $body): ?array
    {
        $idToken = trim((string) ($body['id_token'] ?? ''));
        if ($idToken !== '') {
            $clientId = trim((string) (getenv('GOOGLE_CLIENT_ID') ?: ''));
            $ch = curl_init('https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($idToken));
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_FAILONERROR => false,
            ]);
            $raw = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($raw === false || $httpCode !== 200) {
                return null;
            }
            $info = json_decode((string) $raw, true);
            if (!is_array($info)) {
                return null;
            }
            $iss = (string) ($info['iss'] ?? '');
            $emailVerified = (string) ($info['email_verified'] ?? '') === 'true';
            $googleId = (string) ($info['sub'] ?? '');
            $email = trim((string) ($info['email'] ?? ''));
            if ((string) ($info['aud'] ?? '') !== $clientId
                || ($iss !== 'accounts.google.com' && $iss !== 'https://accounts.google.com')
                || (int) ($info['exp'] ?? 0) <= time()
                || !$emailVerified
                || $googleId === ''
                || $email === ''
            ) {
                return null;
            }
            $user = UserModel::findByGoogleId($googleId);
            if ($user === null) {
                $user = UserModel::findByEmail($email);
            }
            return $user;
        }
        $username = trim((string) ($body['username'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        if ($username === '' || $password === '') {
            return null;
        }
        $user = UserModel::findByUsername($username);
        if ($user === null || !password_verify($password, (string) $user['password'])) {
            return null;
        }
        return $user;
    }
}
