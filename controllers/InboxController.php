<?php

/**
 * controllers/InboxController.php
 * ─────────────────────────────────────────────────────────────────
 * INBOX / DM — endpoint fitur inbox (daftar percakapan, pesan,
 * kirim pesan, state per-peserta). Semua route memakai
 * AuthMiddleware; akses ke satu percakapan selalu diverifikasi
 * lewat baris dm_participants — bukan peserta = 404 (keberadaan
 * percakapan tidak dibocorkan).
 * ─────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

require_once BASE_PATH . '/models/InboxModel.php';
require_once BASE_PATH . '/models/UserModel.php';
require_once BASE_PATH . '/utils/Response.php';
require_once BASE_PATH . '/utils/Realtime.php';

class InboxController
{
    private static function userId(): string
    {
        $userId = $GLOBALS['user']['id'] ?? null;
        if (!$userId) {
            Response::json(null, 401, 'Unauthorized', false);
        }
        return (string) $userId;
    }

    /** @return array<string, mixed> */
    private static function body(): array
    {
        $body = $GLOBALS['body'] ?? [];
        if (empty($body)) {
            $raw = file_get_contents('php://input');
            if (!empty($raw)) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $body = $decoded;
                }
            }
        }
        return $body;
    }

    /** Pastikan pemanggil peserta percakapan; kembalikan baris pesertanya. */
    private static function requireParticipant(string $conversationId, string $userId): array
    {
        $row = InboxModel::participantRow(getDB(), $conversationId, $userId);
        if ($row === null) {
            Response::json(null, 404, 'Conversation not found', false);
        }
        return $row;
    }

    /** GET /api/inbox/summary — total belum dibaca (thread ter-mute tidak dihitung). */
    public static function summary(array $params = []): void
    {
        try {
            $userId = self::userId();
            $total = 0;
            foreach (InboxModel::listForUser($userId) as $conversation) {
                if (!$conversation['is_muted']) {
                    $total += $conversation['unread_count'];
                }
            }
            Response::json(['unreadCount' => $total], 200, 'Inbox summary', true);
        } catch (Throwable $e) {
            error_log('[INBOX SUMMARY ERROR] ' . $e->getMessage());
            Response::json(null, 500, $e->getMessage(), false);
        }
    }

    /** GET /api/inbox/conversations — daftar thread milik user. */
    public static function listConversations(array $params = []): void
    {
        try {
            $userId = self::userId();
            Response::json(InboxModel::listForUser($userId), 200, 'Conversations', true);
        } catch (Throwable $e) {
            error_log('[INBOX LIST ERROR] ' . $e->getMessage());
            Response::json(null, 500, $e->getMessage(), false);
        }
    }

    /** POST /api/inbox/conversations {userId} — find-or-create percakapan direct. */
    public static function createConversation(array $params = []): void
    {
        try {
            $userId = self::userId();
            $body = self::body();
            $otherId = trim((string) ($body['userId'] ?? $body['user_id'] ?? ''));

            if ($otherId === '') {
                Response::json(null, 400, 'userId diperlukan', false);
            }
            if ($otherId === $userId) {
                Response::json(null, 400, 'Tidak bisa membuat percakapan dengan diri sendiri', false);
            }
            $other = UserModel::findById($otherId);
            if ($other === null) {
                Response::json(null, 404, 'User tidak ditemukan', false);
            }

            // Dari halaman collab, collabId ikut dikirim: kalau
            // percakapan aplikasi untuk collab itu sudah ada,
            // percakapan itulah yang dilanjutkan (bukan thread baru).
            $collabId = trim((string) ($body['collabId'] ?? $body['collab_id'] ?? ''));
            $conversationId = InboxModel::ensureChat(
                $userId,
                $otherId,
                $collabId !== '' ? $collabId : null
            );
            Response::json(
                InboxModel::summaryFor($conversationId, $userId),
                200,
                'Conversation ready',
                true
            );
        } catch (Throwable $e) {
            error_log('[INBOX CREATE ERROR] ' . $e->getMessage());
            Response::json(null, 500, $e->getMessage(), false);
        }
    }

    /** GET /api/inbox/conversations/:id/messages */
    public static function listMessages(array $params = []): void
    {
        try {
            $userId = self::userId();
            $conversationId = $params['id'] ?? '';
            $messages = InboxModel::messagesForUser($conversationId, $userId);
            if ($messages === null) {
                Response::json(null, 404, 'Conversation not found', false);
            }
            Response::json(['messages' => $messages], 200, 'Messages', true);
        } catch (Throwable $e) {
            error_log('[INBOX MESSAGES ERROR] ' . $e->getMessage());
            Response::json(null, 500, $e->getMessage(), false);
        }
    }

    /** POST /api/inbox/conversations/:id/messages {body} */
    public static function sendMessage(array $params = []): void
    {
        try {
            $userId = self::userId();
            $conversationId = $params['id'] ?? '';
            self::requireParticipant($conversationId, $userId);

            $body = self::body();
            $text = trim((string) ($body['body'] ?? $body['text'] ?? ''));
            if ($text === '') {
                Response::json(null, 400, 'Pesan tidak boleh kosong', false);
            }

            $pdo = getDB();
            $message = InboxModel::insertMessage($pdo, $conversationId, $userId, $text);

            $otherId = InboxModel::otherParticipantId($pdo, $conversationId, $userId);
            if ($otherId !== null) {
                Realtime::publishInboxMessage($otherId, $conversationId, $message);
            }

            Response::json(['message' => $message], 201, 'Message sent', true);
        } catch (Throwable $e) {
            error_log('[INBOX SEND ERROR] ' . $e->getMessage());
            Response::json(null, 500, $e->getMessage(), false);
        }
    }

    /** POST /api/inbox/conversations/:id/read */
    public static function markRead(array $params = []): void
    {
        try {
            $userId = self::userId();
            $conversationId = $params['id'] ?? '';
            self::requireParticipant($conversationId, $userId);
            InboxModel::markRead(getDB(), $conversationId, $userId);
            Response::json(null, 200, 'Marked as read', true);
        } catch (Throwable $e) {
            error_log('[INBOX READ ERROR] ' . $e->getMessage());
            Response::json(null, 500, $e->getMessage(), false);
        }
    }

    /** PUT /api/inbox/conversations/:id/mute — toggle. */
    public static function toggleMute(array $params = []): void
    {
        self::toggleFlag($params, 'is_muted', 'muted');
    }

    /** PUT /api/inbox/conversations/:id/pin — toggle. */
    public static function togglePin(array $params = []): void
    {
        self::toggleFlag($params, 'is_pinned', 'pinned');
    }

    private static function toggleFlag(array $params, string $flag, string $label): void
    {
        try {
            $userId = self::userId();
            $conversationId = $params['id'] ?? '';
            self::requireParticipant($conversationId, $userId);
            $value = InboxModel::toggleFlag(getDB(), $conversationId, $userId, $flag);
            Response::json([$label => $value], 200, 'Updated', true);
        } catch (Throwable $e) {
            error_log('[INBOX FLAG ERROR] ' . $e->getMessage());
            Response::json(null, 500, $e->getMessage(), false);
        }
    }

    /** POST /api/inbox/conversations/:id/clear — sembunyikan riwayat untuk saya saja. */
    public static function clearConversation(array $params = []): void
    {
        try {
            $userId = self::userId();
            $conversationId = $params['id'] ?? '';
            self::requireParticipant($conversationId, $userId);
            InboxModel::clearFor(getDB(), $conversationId, $userId);
            Response::json(null, 200, 'Chat cleared', true);
        } catch (Throwable $e) {
            error_log('[INBOX CLEAR ERROR] ' . $e->getMessage());
            Response::json(null, 500, $e->getMessage(), false);
        }
    }

    /** DELETE /api/inbox/conversations/:id — sembunyikan thread untuk saya saja. */
    public static function hideConversation(array $params = []): void
    {
        try {
            $userId = self::userId();
            $conversationId = $params['id'] ?? '';
            self::requireParticipant($conversationId, $userId);
            InboxModel::hideFor(getDB(), $conversationId, $userId);
            Response::json(null, 200, 'Chat deleted', true);
        } catch (Throwable $e) {
            error_log('[INBOX HIDE ERROR] ' . $e->getMessage());
            Response::json(null, 500, $e->getMessage(), false);
        }
    }

    /** POST /api/inbox/conversations/:id/restore — undo Clear/Delete. */
    public static function restoreConversation(array $params = []): void
    {
        try {
            $userId = self::userId();
            $conversationId = $params['id'] ?? '';
            self::requireParticipant($conversationId, $userId);
            InboxModel::restoreFor(getDB(), $conversationId, $userId, true);
            Response::json(
                InboxModel::summaryFor($conversationId, $userId),
                200,
                'Chat restored',
                true
            );
        } catch (Throwable $e) {
            error_log('[INBOX RESTORE ERROR] ' . $e->getMessage());
            Response::json(null, 500, $e->getMessage(), false);
        }
    }
}
