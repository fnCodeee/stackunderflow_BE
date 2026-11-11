-- 20261111_drop_notification_body.sql
-- Notification detail removed (2026-11-11): notification rows no longer
-- carry a quoted detail (comment text / post description) — the subline is
-- just the action ("kimi liked your post"). The `body` column is unused.
ALTER TABLE notifications DROP COLUMN body;
