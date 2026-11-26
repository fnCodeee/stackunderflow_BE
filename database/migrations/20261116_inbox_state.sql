-- Audit P1 #13: per-participant inbox state for archive and
-- server-side mark-as-unread.
ALTER TABLE dm_participants
  ADD COLUMN is_archived tinyint(1) NOT NULL DEFAULT 0 AFTER is_pinned,
  ADD COLUMN marked_unread tinyint(1) NOT NULL DEFAULT 0 AFTER is_archived;
