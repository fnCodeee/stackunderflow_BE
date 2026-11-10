-- Google sign-in (2026-11-10): link a Google account to a user via the
-- Google `sub` claim. The frontend sends the Google ID token (Google
-- Identity Services) to POST /api/auth/google; the backend verifies it
-- with Google and finds/creates the user by this column.
-- Idempotent: safe to re-run (checks information_schema first).

SET @db := DATABASE();
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'google_id');
SET @sql := IF(@col = 0,
  'ALTER TABLE users ADD COLUMN google_id VARCHAR(255) NULL UNIQUE AFTER email',
  'SELECT ''users.google_id already exists''');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
