<?php

/**
 * models/ReportModel.php
 * ─────────────────────────────────────────────────────────────────────────────
 * REPORT DARI USER (spec-admin.md §3.2)
 *
 * Report tersimpan di tabel `reports` dan menjadi bahan antrean
 * moderasi admin. Satu user hanya boleh punya satu report `open`
 * untuk target yang sama.
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

class ReportModel
{
    public static function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    public static function findOpenByReporterAndTarget(string $reporterId, string $targetType, string $targetId): ?array
    {
        $pdo = getDB();
        $stmt = $pdo->prepare(
            "SELECT * FROM reports
             WHERE reporter_id = ? AND target_type = ? AND target_id = ? AND status = 'open'
             LIMIT 1"
        );
        $stmt->execute([$reporterId, $targetType, $targetId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Hapus report yang masih open milik pelapor sendiri (Undo). */
    public static function deleteOpenById(string $id, string $reporterId): bool
    {
        $pdo = getDB();
        $stmt = $pdo->prepare("DELETE FROM reports WHERE id = ? AND reporter_id = ? AND status = 'open'");
        $stmt->execute([$id, $reporterId]);
        return $stmt->rowCount() > 0;
    }

    public static function create(string $reporterId, string $targetType, string $targetId, string $reason): array
    {
        $pdo = getDB();
        $id = self::generateUuid();
        $stmt = $pdo->prepare(
            "INSERT INTO reports (id, reporter_id, target_type, target_id, reason)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([$id, $reporterId, $targetType, $targetId, $reason]);

        $stmt = $pdo->prepare("SELECT * FROM reports WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: [];
    }
}
