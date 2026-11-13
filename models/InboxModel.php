<?php

/**
 * models/InboxModel.php
 * ─────────────────────────────────────────────────────────────────
 * INBOX / DM — persistensi fitur inbox di atas tabel dm_* (yang
 * sebelumnya ada di skema tapi tidak dipakai kode sama sekali).
 *
 * Satu sistem percakapan untuk DUA jenis thread yang ditampilkan UI:
 *  - direct: dm_conversations dengan application_id NULL, antara
 *    dua user;
 *  - aplikasi: dm_conversations yang tertaut 1:1 ke satu baris
 *    collaboration_applications (dibuat oleh flow apply).
 *
 * State per-peserta (mute / pin / cleared / hidden) tinggal di
 * dm_participants, jadi Clear / Delete / Mute / Pin bersifat
 * personal — tidak pernah destruktif buat sisi lawan bicara.
 * ─────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

require_once BASE_PATH . '/models/ProfileModel.php';
require_once BASE_PATH . '/utils/Realtime.php';

class InboxModel
{
    public static function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // versi 4
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // variant RFC 4122
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /** Status aplikasi bentuk API (DB 'rejected' diekspos 'declined'). */
    private static function mapStatus(string $dbStatus): string
    {
        return $dbStatus === 'rejected' ? 'declined' : $dbStatus;
    }

    // ── Pencarian / pembuatan percakapan ─────────────────────────

    /** ID percakapan direct antara dua user, atau null. */
    public static function findDirectConversation(PDO $pdo, string $a, string $b): ?string
    {
        $stmt = $pdo->prepare("
            SELECT p1.conversation_id
            FROM dm_participants p1
            JOIN dm_participants p2 ON p2.conversation_id = p1.conversation_id
            JOIN dm_conversations c ON c.id = p1.conversation_id
            WHERE p1.user_id = :a AND p2.user_id = :b AND c.application_id IS NULL
            LIMIT 1
        ");
        $stmt->execute([':a' => $a, ':b' => $b]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (string) $id;
    }

    /** ID percakapan milik satu aplikasi, atau null. */
    public static function findByApplication(PDO $pdo, string $applicationId): ?string
    {
        $stmt = $pdo->prepare("SELECT id FROM dm_conversations WHERE application_id = :app LIMIT 1");
        $stmt->execute([':app' => $applicationId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (string) $id;
    }

    /** Buat percakapan + baris peserta. Mengembalikan ID percakapan. */
    public static function createConversation(PDO $pdo, array $userIds, ?string $applicationId): string
    {
        $id = self::generateUuid();
        $stmt = $pdo->prepare("INSERT INTO dm_conversations (id, application_id) VALUES (:id, :app)");
        $stmt->execute([':id' => $id, ':app' => $applicationId]);

        $pStmt = $pdo->prepare("INSERT INTO dm_participants (id, conversation_id, user_id) VALUES (:id, :conv, :user)");
        foreach ($userIds as $userId) {
            $pStmt->execute([':id' => self::generateUuid(), ':conv' => $id, ':user' => $userId]);
        }
        return $id;
    }

    /** ID percakapan aplikasi untuk satu collab antara dua user, atau null. */
    public static function findCollabConversation(PDO $pdo, string $collabId, string $a, string $b): ?string
    {
        $stmt = $pdo->prepare("
            SELECT c.id
            FROM dm_conversations c
            JOIN collaboration_applications a ON a.id = c.application_id
            JOIN dm_participants p1 ON p1.conversation_id = c.id AND p1.user_id = :a
            JOIN dm_participants p2 ON p2.conversation_id = c.id AND p2.user_id = :b
            WHERE a.collab_id = :collab
            LIMIT 1
        ");
        $stmt->execute([':collab' => $collabId, ':a' => $a, ':b' => $b]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (string) $id;
    }

    /**
     * Target tombol Chat dari halaman collab: kalau sudah ada
     * percakapan aplikasi untuk collab ini dengan user tersebut,
     * lanjutkan percakapan itu — jangan buat thread direct kedua
     * untuk orang + project yang sama. Belum ada -> direct biasa.
     */
    public static function ensureChat(string $me, string $other, ?string $collabId): string
    {
        $pdo = getDB();
        if ($collabId !== null && $collabId !== '') {
            $existing = self::findCollabConversation($pdo, $collabId, $me, $other);
            if ($existing !== null) {
                self::restoreFor($pdo, $existing, $me, false);
                return $existing;
            }
        }
        return self::ensureDirect($me, $other);
    }

    /** Find-or-create percakapan direct; thread yang ter-hidden dihidupkan lagi untuk pemanggil. */
    public static function ensureDirect(string $me, string $other): string
    {
        $pdo = getDB();
        $existing = self::findDirectConversation($pdo, $me, $other);
        if ($existing !== null) {
            self::restoreFor($pdo, $existing, $me, false);
            return $existing;
        }
        return self::createConversation($pdo, [$me, $other], null);
    }

    /**
     * Find-or-create percakapan sebuah aplikasi (dipanggil flow apply
     * SETELAH commit). Saat percakapan baru dibuat, pesan lamaran
     * menjadi pesan pertamanya; saat aplikasi dibuka lagi (re-apply),
     * pesan baru ditambahkan sebagai pesan biasa. Pemilik project
     * menerima event realtime untuk pesan yang terkirim.
     *
     * @param array<string, mixed> $application Baris aplikasi (id, user_id, message, status)
     */
    public static function ensureForApplication(array $application, string $ownerId, string $applicantId, ?string $message): void
    {
        $pdo = getDB();
        $applicationId = (string) $application['id'];
        $conversationId = self::findByApplication($pdo, $applicationId);
        $created = false;
        if ($conversationId === null) {
            $conversationId = self::createConversation($pdo, [$applicantId, $ownerId], $applicationId);
            $created = true;
        }

        // Thread muncul lagi di daftar pelamar (dan pemilik, kalau ada pesan baru).
        self::restoreFor($pdo, $conversationId, $applicantId, false);

        if ($message !== null && $message !== '') {
            $sent = self::insertMessage($pdo, $conversationId, $applicantId, $message);
            Realtime::publishInboxMessage($ownerId, $conversationId, $sent);
        } elseif ($created) {
            Realtime::publishInboxUpdate($ownerId, $conversationId);
        }
    }

    // ── Pesan ────────────────────────────────────────────────────

    /**
     * Sisipkan pesan, bump updated_at percakapan, dan hidupkan lagi
     * thread di sisi pengirim. Mengembalikan pesan bentuk API.
     *
     * @return array{id: string, sender_id: string, body: string, is_read: bool, created_at: string}
     */
    public static function insertMessage(PDO $pdo, string $conversationId, string $senderId, string $body): array
    {
        $id = self::generateUuid();
        $stmt = $pdo->prepare("INSERT INTO dm_messages (id, conversation_id, sender_id, body) VALUES (:id, :conv, :sender, :body)");
        $stmt->execute([':id' => $id, ':conv' => $conversationId, ':sender' => $senderId, ':body' => $body]);

        $pdo->prepare("UPDATE dm_conversations SET updated_at = CURRENT_TIMESTAMP WHERE id = :id")
            ->execute([':id' => $conversationId]);
        $pdo->prepare("UPDATE dm_participants SET hidden_at = NULL WHERE conversation_id = :conv AND user_id = :user")
            ->execute([':conv' => $conversationId, ':user' => $senderId]);

        $row = $pdo->prepare("SELECT id, sender_id, body, is_read, created_at FROM dm_messages WHERE id = :id");
        $row->execute([':id' => $id]);
        $message = $row->fetch(PDO::FETCH_ASSOC);
        return [
            'id' => $message['id'],
            'sender_id' => $message['sender_id'],
            'body' => $message['body'],
            'is_read' => (bool) $message['is_read'],
            'created_at' => $message['created_at'],
        ];
    }

    /** Baris peserta (untuk cek akses + flag), atau null kalau bukan peserta. */
    /** Jenis thread: direct / application / system (spec-admin §3.6). */
    public static function kindOf(PDO $pdo, string $conversationId): ?string
    {
        $stmt = $pdo->prepare("SELECT kind FROM dm_conversations WHERE id = ? LIMIT 1");
        $stmt->execute([$conversationId]);
        $kind = $stmt->fetchColumn();
        return $kind === false ? null : (string) $kind;
    }

    public static function participantRow(PDO $pdo, string $conversationId, string $userId): ?array
    {
        $stmt = $pdo->prepare("SELECT * FROM dm_participants WHERE conversation_id = :conv AND user_id = :user LIMIT 1");
        $stmt->execute([':conv' => $conversationId, ':user' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** ID lawan bicara dalam percakapan 1:1, atau null. */
    public static function otherParticipantId(PDO $pdo, string $conversationId, string $userId): ?string
    {
        $stmt = $pdo->prepare("SELECT user_id FROM dm_participants WHERE conversation_id = :conv AND user_id <> :user LIMIT 1");
        $stmt->execute([':conv' => $conversationId, ':user' => $userId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (string) $id;
    }

    /**
     * Pesan-pesan sebuah percakapan untuk satu peserta (yang terlihat
     * olehnya: setelah cleared_at miliknya). Null = bukan peserta.
     *
     * @return array<int, array{id: string, sender_id: string, body: string, is_read: bool, created_at: string}>|null
     */
    public static function messagesForUser(string $conversationId, string $userId): ?array
    {
        $pdo = getDB();
        $participant = self::participantRow($pdo, $conversationId, $userId);
        if ($participant === null) {
            return null;
        }
        $stmt = $pdo->prepare("
            SELECT id, sender_id, body, is_read, created_at, moderation_action_id
            FROM dm_messages
            WHERE conversation_id = :conv
              AND (:cleared IS NULL OR created_at > :cleared2)
            ORDER BY created_at ASC, id ASC
        ");
        $stmt->execute([
            ':conv' => $conversationId,
            ':cleared' => $participant['cleared_at'],
            ':cleared2' => $participant['cleared_at'],
        ]);
        return array_map(static fn(array $m) => [
            'id' => $m['id'],
            'sender_id' => $m['sender_id'],
            'body' => $m['body'],
            'is_read' => (bool) $m['is_read'],
            'created_at' => $m['created_at'],
            'moderation_action_id' => $m['moderation_action_id'] ?? null,
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Tandai semua pesan dari lawan bicara sebagai dibaca. */
    public static function markRead(PDO $pdo, string $conversationId, string $userId): void
    {
        $pdo->prepare("UPDATE dm_messages SET is_read = 1 WHERE conversation_id = :conv AND sender_id <> :user AND is_read = 0")
            ->execute([':conv' => $conversationId, ':user' => $userId]);
    }

    // ── State per-peserta ────────────────────────────────────────

    /** Balik flag is_muted / is_pinned milik peserta; kembalikan nilai barunya. */
    public static function toggleFlag(PDO $pdo, string $conversationId, string $userId, string $flag): bool
    {
        if (!in_array($flag, ['is_muted', 'is_pinned'], true)) {
            throw new InvalidArgumentException('Unknown flag: ' . $flag);
        }
        $pdo->prepare("UPDATE dm_participants SET {$flag} = 1 - {$flag} WHERE conversation_id = :conv AND user_id = :user")
            ->execute([':conv' => $conversationId, ':user' => $userId]);
        $row = self::participantRow($pdo, $conversationId, $userId);
        return (bool) ($row[$flag] ?? false);
    }

    /** Clear chat: pesan sampai sekarang disembunyikan untuk peserta ini saja. */
    public static function clearFor(PDO $pdo, string $conversationId, string $userId): void
    {
        $pdo->prepare("UPDATE dm_participants SET cleared_at = CURRENT_TIMESTAMP WHERE conversation_id = :conv AND user_id = :user")
            ->execute([':conv' => $conversationId, ':user' => $userId]);
        self::markRead($pdo, $conversationId, $userId);
    }

    /** Delete chat: sembunyikan thread untuk peserta ini (hidup lagi kalau ada pesan baru). */
    public static function hideFor(PDO $pdo, string $conversationId, string $userId): void
    {
        $pdo->prepare("UPDATE dm_participants SET hidden_at = CURRENT_TIMESTAMP WHERE conversation_id = :conv AND user_id = :user")
            ->execute([':conv' => $conversationId, ':user' => $userId]);
    }

    /** Undo Clear/Delete: tampilkan lagi thread + riwayatnya untuk peserta ini. */
    public static function restoreFor(PDO $pdo, string $conversationId, string $userId, bool $restoreHistory = true): void
    {
        if ($restoreHistory) {
            $pdo->prepare("UPDATE dm_participants SET hidden_at = NULL, cleared_at = NULL WHERE conversation_id = :conv AND user_id = :user")
                ->execute([':conv' => $conversationId, ':user' => $userId]);
        } else {
            $pdo->prepare("UPDATE dm_participants SET hidden_at = NULL WHERE conversation_id = :conv AND user_id = :user")
                ->execute([':conv' => $conversationId, ':user' => $userId]);
        }
    }

    // ── Daftar percakapan ────────────────────────────────────────

    /**
     * Daftar percakapan satu user, bentuk API, urut aktivitas terbaru.
     * Thread ter-hidden hanya muncul lagi kalau ada pesan yang lebih
     * baru dari hidden_at-nya. $onlyId membatasi ke satu percakapan.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listForUser(string $userId, ?string $onlyId = null): array
    {
        $pdo = getDB();
        $sql = "
            SELECT
                c.id AS conversation_id,
                c.application_id,
                c.kind,
                c.updated_at,
                ou.id AS other_id,
                ou.username AS other_username,
                opr.photo_profile_url AS other_photo,
                p.is_muted,
                p.is_pinned,
                a.id AS app_id,
                a.status AS app_status,
                a.user_id AS app_user_id,
                a.collab_id AS app_collab_id,
                col.title AS col_title,
                col.description AS col_description,
                col.due_date AS col_due_date,
                col.required_member AS col_required,
                (SELECT COUNT(*) FROM user_collaboration uc
                  WHERE uc.collaboration_id = col.id) AS col_filled,
                lm.body AS last_body,
                lm.sender_id AS last_sender,
                lm.created_at AS last_at,
                (SELECT COUNT(*) FROM dm_messages m
                  WHERE m.conversation_id = c.id
                    AND m.sender_id <> :me_unread
                    AND m.is_read = 0
                    AND (p.cleared_at IS NULL OR m.created_at > p.cleared_at)) AS unread_count
            FROM dm_participants p
            JOIN dm_conversations c ON c.id = p.conversation_id
            JOIN dm_participants op ON op.conversation_id = c.id AND op.user_id <> p.user_id
            JOIN users ou ON ou.id = op.user_id
            LEFT JOIN profiles opr ON opr.user_id = ou.id
            LEFT JOIN collaboration_applications a ON a.id = c.application_id
            LEFT JOIN collaborations col ON col.id = a.collab_id
            LEFT JOIN dm_messages lm ON lm.id = (
                SELECT m2.id FROM dm_messages m2
                WHERE m2.conversation_id = c.id
                  AND (p.cleared_at IS NULL OR m2.created_at > p.cleared_at)
                ORDER BY m2.created_at DESC, m2.id DESC
                LIMIT 1
            )
            WHERE p.user_id = :me
              AND (p.hidden_at IS NULL OR EXISTS (
                    SELECT 1 FROM dm_messages m3
                    WHERE m3.conversation_id = c.id AND m3.created_at > p.hidden_at
              ))
        ";
        $params = [':me' => $userId, ':me_unread' => $userId];
        if ($onlyId !== null) {
            $sql .= " AND c.id = :only_id";
            $params[':only_id'] = $onlyId;
        }
        $sql .= " ORDER BY c.updated_at DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $application = null;
            if ($row['app_id'] !== null) {
                $description = (string) ($row['col_description'] ?? '');
                $application = [
                    'id' => $row['app_id'],
                    'collab_id' => $row['app_collab_id'],
                    'status' => self::mapStatus((string) $row['app_status']),
                    'direction' => (string) $row['app_user_id'] === $userId ? 'outgoing' : 'incoming',
                    'project_title' => $row['col_title'] ?? '',
                    'project_meta' => mb_substr(strtok($description, "\n") ?: '', 0, 80),
                    'due_date' => $row['col_due_date'],
                    'slots_filled' => (int) ($row['col_filled'] ?? 0),
                    'slots_total' => (int) ($row['col_required'] ?? 0),
                ];
            }
            $result[] = [
                'id' => $row['conversation_id'],
                'other' => [
                    'id' => $row['other_id'],
                    'username' => $row['other_username'] ?? '',
                    'photo_profile_url' => $row['other_photo'] ?? ProfileModel::DEFAULT_PHOTO_URL,
                ],
                'last_message' => $row['last_body'] === null ? null : [
                    'body' => $row['last_body'],
                    'sender_id' => $row['last_sender'],
                    'created_at' => $row['last_at'],
                ],
                'unread_count' => (int) $row['unread_count'],
                'is_muted' => (bool) $row['is_muted'],
                'is_pinned' => (bool) $row['is_pinned'],
                'updated_at' => $row['updated_at'],
                'application' => $application,
                'kind' => $row['kind'] ?? 'direct',
            ];
        }
        return $result;
    }

    /** Satu percakapan bentuk API untuk user ini, atau null. */
    public static function summaryFor(string $conversationId, string $userId): ?array
    {
        $rows = self::listForUser($userId, $conversationId);
        return $rows[0] ?? null;
    }
}
