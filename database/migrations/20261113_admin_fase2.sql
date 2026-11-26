-- Fase 2 (spec-admin.md §8): pesan sistem membawa ikatan ke aksi
-- moderasi asal — dipakai tombol banding di Fase 3.
ALTER TABLE dm_messages
    ADD COLUMN moderation_action_id VARCHAR(36) NULL DEFAULT NULL AFTER body,
    ADD INDEX idx_dm_messages_action (moderation_action_id);
