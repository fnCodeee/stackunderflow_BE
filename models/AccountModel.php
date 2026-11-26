<?php

/**
 * models/AccountModel.php
 * ─────────────────────────────────────────────────────────────────────────────
 * ACCOUNT MODEL (audit P1 #14 — hapus akun)
 *
 * Penghapusan akun bersifat anonymize-in-place: identitas
 * dianonimkan dan konten milik user dibersihkan, tapi baris
 * users tetap ada supaya riwayat moderasi dan relasi lama
 * tidak rusak. Akun yang masih memiliki proyek dengan member
 * lain tidak boleh dihapus (tidak ada fitur transfer owner).
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

require_once BASE_PATH . '/models/ProfileModel.php';

class AccountModel
{
    /** Jumlah proyek milik user yang masih punya member lain. */
    public static function ownedProjectsWithOtherMembers(string $userId): int
    {
        $stmt = getDB()->prepare(
            'SELECT COUNT(DISTINCT c.id) FROM collaborations c
             JOIN user_collaboration uc ON uc.collaboration_id = c.id AND uc.user_id <> ?
             WHERE c.user_id = ? AND c.deleted_at IS NULL'
        );
        $stmt->execute([$userId, $userId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Anonimkan identitas user dan bersihkan data miliknya
     * dalam satu transaksi. $email adalah email ASLI user
     * (untuk membersihkan pending_registrations).
     */
    public static function anonymizeAndPurge(string $userId, string $email): void
    {
        $pdo = getDB();
        $tag = substr(str_replace('-', '', $userId), 0, 8);
        $now = date('Y-m-d H:i:s');

        $pdo->beginTransaction();
        try {
            // Kursi saya di proyek orang lain dibebaskan lebih dulu,
            // lalu proyek milik sendiri (sudah pasti tanpa member
            // lain — dicek controller) di-soft-delete.
            $run = static function (string $sql, array $params) use ($pdo): void {
                $pdo->prepare($sql)->execute($params);
            };

            $run('DELETE FROM user_collaboration WHERE user_id = ?', [$userId]);
            $run('UPDATE collaborations SET deleted_at = ? WHERE user_id = ? AND deleted_at IS NULL', [$now, $userId]);
            $run("UPDATE collaboration_applications SET status = 'withdrawn' WHERE user_id = ? AND status = 'pending'", [$userId]);

            $run('UPDATE public_posts SET deleted_at = ? WHERE user_id = ? AND deleted_at IS NULL', [$now, $userId]);
            $run('UPDATE comments SET deleted_at = ? WHERE user_id = ? AND deleted_at IS NULL', [$now, $userId]);

            $run('DELETE FROM notifications WHERE user_id = ?', [$userId]);
            $run('DELETE FROM dm_participants WHERE user_id = ?', [$userId]);
            $run('DELETE FROM user_notification_preferences WHERE user_id = ?', [$userId]);

            $run('DELETE FROM profile_social_medias WHERE user_id = ?', [$userId]);
            $run('DELETE FROM user_skill WHERE user_id = ?', [$userId]);
            $run(
                'UPDATE profiles SET full_name = ?, bio = NULL, photo_profile_url = ?, photo_profile_public_id = NULL WHERE user_id = ?',
                ['Deleted User', ProfileModel::DEFAULT_PHOTO_URL, $userId]
            );

            $run('DELETE FROM password_resets WHERE user_id = ?', [$userId]);
            $run('DELETE FROM email_verification_tokens WHERE user_id = ?', [$userId]);
            $run('DELETE FROM pending_registrations WHERE email = ?', [$email]);

            // Identitas dianonimkan terakhir; password_changed_at
            // (unix timestamp) membunuh semua token yang masih hidup.
            $run(
                "UPDATE users SET username = ?, email = ?, password = ?, google_id = NULL,
                    account_status = 'suspended', suspended_reason = 'Account deleted by user',
                    suspended_at = ?, password_changed_at = ? WHERE id = ?",
                [
                    'deleted-' . $tag,
                    'deleted-' . $tag . '@deleted.invalid',
                    password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT),
                    $now,
                    time(),
                    $userId,
                ]
            );

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
