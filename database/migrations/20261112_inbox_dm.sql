-- 20261112_inbox_dm.sql
-- Fungsikan inbox di atas tabel dm_* yang selama ini kosong:
--  - dm_conversations.application_id: tautan 1:1 ke
--    collaboration_applications (percakapan aplikasi). NULL =
--    percakapan direct biasa. UNIQUE supaya satu aplikasi hanya
--    punya satu percakapan.
--  - dm_participants: state per-peserta (mute / pin / cleared /
--    hidden) supaya Clear/Delete/Mute/Pin bersifat personal,
--    tidak pernah destruktif buat sisi lawan bicara.
--  - collaboration_applications.status: tambah 'withdrawn'
--    (pelamar menarik lamarannya sendiri).

ALTER TABLE dm_conversations
    ADD COLUMN application_id VARCHAR(36) NULL AFTER id,
    ADD UNIQUE KEY uq_dm_conversations_application (application_id);

ALTER TABLE dm_participants
    ADD COLUMN is_muted TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN is_pinned TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN cleared_at TIMESTAMP NULL DEFAULT NULL,
    ADD COLUMN hidden_at TIMESTAMP NULL DEFAULT NULL;

ALTER TABLE collaboration_applications
    MODIFY COLUMN status ENUM('pending', 'accepted', 'rejected', 'withdrawn')
        NOT NULL DEFAULT 'pending';
