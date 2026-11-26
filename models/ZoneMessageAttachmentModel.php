<?php

/**
 * models/ZoneMessageAttachmentModel.php
 * =============================================================================
 * ZONE MESSAGE ATTACHMENT MODEL (Data Access Layer) — Circle attachments.
 *
 * Bertanggung jawab terhadap operasi database untuk tabel
 * `zone_message_attachments` (migration 20261119): file yang menempel
 * pada satu pesan zone. File-nya sendiri tinggal di akun Cloudinary
 * khusus proyek (folder assets/messageCircle); tabel ini hanya
 * mencatat url + public_id + nama + jenis + ukurannya. Baris ikut
 * terhapus saat pesannya terhapus (FK cascade).
 * =============================================================================
 */

declare(strict_types=1);

require_once BASE_PATH . '/config/database.php';

class ZoneMessageAttachmentModel
{
    /**
     * Simpan semua lampiran milik satu pesan. Mengembalikan baris
     * yang tersimpan (siap dipresentasikan oleh controller).
     *
     * @param  array<int, array{url: string, public_id: string, file_name: string, kind: string, size_bytes: int}> $items
     * @return array<int, array<string, mixed>>
     */
    public static function createMany(string $messageId, array $items, ?PDO $pdo = null): array
    {
        if ($items === []) {
            return [];
        }

        $db = $pdo ?? getDB();
        $stmt = $db->prepare(
            "INSERT INTO zone_message_attachments
                (id, message_id, url, public_id, file_name, kind, size_bytes)
             VALUES
                (:id, :message_id, :url, :public_id, :file_name, :kind, :size_bytes)"
        );

        $saved = [];
        foreach ($items as $item) {
            $id = ZoneMessageModel::generateUuid();
            $stmt->execute([
                ':id' => $id,
                ':message_id' => $messageId,
                ':url' => $item['url'],
                ':public_id' => $item['public_id'],
                ':file_name' => $item['file_name'],
                ':kind' => $item['kind'],
                ':size_bytes' => $item['size_bytes'],
            ]);
            $saved[] = [
                'id' => $id,
                'message_id' => $messageId,
                'url' => $item['url'],
                'public_id' => $item['public_id'],
                'file_name' => $item['file_name'],
                'kind' => $item['kind'],
                'size_bytes' => $item['size_bytes'],
            ];
        }

        return $saved;
    }

    /**
     * Semua lampiran milik sekumpulan pesan, terindeks oleh ID
     * pesan, supaya daftar pesan tidak melakukan query per pesan.
     *
     * @param  array<int, string> $messageIds
     * @return array<string, array<int, array<string, mixed>>>
     */
    public static function forMessages(array $messageIds, ?PDO $pdo = null): array
    {
        if ($messageIds === []) {
            return [];
        }

        $db = $pdo ?? getDB();
        $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
        $stmt = $db->prepare(
            "SELECT * FROM zone_message_attachments
             WHERE message_id IN ($placeholders)
             ORDER BY timestamp ASC, id ASC"
        );
        $stmt->execute(array_values($messageIds));

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(string) $row['message_id']][] = $row;
        }

        return $out;
    }
}
