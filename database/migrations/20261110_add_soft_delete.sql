-- Soft delete (2026-11-10): add deleted_at to the three user-facing
-- content tables. A row is "deleted" when deleted_at IS NOT NULL; the
-- row stays in the database (no hard DELETE) so it can be restored or
-- audited. No account deletion is implemented (user decision 2026-11-10).
-- Scope: public_posts, collaborations, comments. Messages (zone_messages,
-- dm_messages) and skills are NOT soft-deleted.
-- Idempotent: safe to re-run (checks information_schema first).

SET @db := DATABASE();

-- public_posts
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'public_posts' AND COLUMN_NAME = 'deleted_at');
SET @sql := IF(@col = 0,
  'ALTER TABLE public_posts ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL AFTER updated_at',
  'SELECT ''public_posts.deleted_at already exists''');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- collaborations
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'collaborations' AND COLUMN_NAME = 'deleted_at');
SET @sql := IF(@col = 0,
  'ALTER TABLE collaborations ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL AFTER updated_at',
  'SELECT ''collaborations.deleted_at already exists''');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- comments
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'comments' AND COLUMN_NAME = 'deleted_at');
SET @sql := IF(@col = 0,
  'ALTER TABLE comments ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL AFTER updated_at',
  'SELECT ''comments.deleted_at already exists''');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
