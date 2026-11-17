-- AI Moderator (spec-ai-moderator.md): flag resolution state.
-- A 'flag' verdict waits for a human decision; these columns record
-- how and by whom it was resolved. Meaningful only for flag rows.
ALTER TABLE ai_moderation_verdicts
  ADD COLUMN flag_state ENUM('open','removed','cleared') NOT NULL DEFAULT 'open',
  ADD COLUMN flag_resolved_by VARCHAR(36) DEFAULT NULL,
  ADD COLUMN flag_resolved_at DATETIME DEFAULT NULL;
