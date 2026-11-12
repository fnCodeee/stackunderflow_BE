-- ============================================================
-- schema.stg.sql — Skema STAGING Underflow
-- Acuan: kondisi live db_stack_underflow (MySQL 8.0.30) per 2026-11-12 07:37:16 WIB (snapshot laptop dev).
-- Isi: 24 tabel, 137 kolom, 57 index non-PK.
--
-- Cara pakai (jalankan SATU sesi mysql client, file ini memakai DELIMITER):
--   mysql -u <user> -p db_stack_underflow < schema.stg.sql
-- Alur: BAGIAN 1 validasi -> BAGIAN 2 perbaikan idempotent -> BAGIAN 3 validasi ulang.
-- Idempotent: aman dijalankan berulang; yang sudah sesuai tidak diubah.
-- File ini TIDAK menghapus tabel/kolom ekstra dan TIDAK mengubah data.
-- ============================================================

CREATE DATABASE IF NOT EXISTS `db_stack_underflow` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `db_stack_underflow`;
SET NAMES utf8mb4;

-- ============================================================
-- BAGIAN 0 — Tabel ekspektasi (snapshot acuan) + helper
-- ============================================================
DROP TABLE IF EXISTS stg_expected_columns;
CREATE TABLE stg_expected_columns (
  table_name  VARCHAR(64) NOT NULL,
  column_name VARCHAR(64) NOT NULL,
  column_type VARCHAR(128) NOT NULL,
  is_nullable VARCHAR(3) NOT NULL,
  PRIMARY KEY (table_name, column_name)
) ENGINE = MEMORY;
INSERT INTO stg_expected_columns VALUES ('circle', 'id', 'varchar(36)', 'NO');
INSERT INTO stg_expected_columns VALUES ('circle', 'collaboration_id', 'varchar(36)', 'NO');
INSERT INTO stg_expected_columns VALUES ('circle', 'title', 'varchar(255)', 'NO');
INSERT INTO stg_expected_columns VALUES ('circle', 'photo_profile_url', 'varchar(255)', 'YES');
INSERT INTO stg_expected_columns VALUES ('circle', 'timestamp', 'datetime', 'NO');
INSERT INTO stg_expected_columns VALUES ('collab_media', 'id', 'varchar(36)', 'NO');
INSERT INTO stg_expected_columns VALUES ('collab_media', 'collab_id', 'varchar(36)', 'NO');
INSERT INTO stg_expected_columns VALUES ('collab_media', 'media_url', 'varchar(255)', 'NO');
INSERT INTO stg_expected_columns VALUES ('collab_media', 'media_public_id', 'varchar(255)', 'YES');
INSERT INTO stg_expected_columns VALUES ('collab_media', 'created_at', 'timestamp', 'YES');
INSERT INTO stg_expected_columns VALUES ('collab_media', 'updated_at', 'timestamp', 'YES');
INSERT INTO stg_expected_columns VALUES ('collab_skills', 'id', 'varchar(36)', 'NO');
INSERT INTO stg_expected_columns VALUES ('collab_skills', 'collab_id', 'varchar(36)', 'NO');
INSERT INTO stg_expected_columns VALUES ('collab_skills', 'skill_id', 'varchar(36)', 'NO');
INSERT INTO stg_expected_columns VALUES ('collab_skills', 'created_at', 'timestamp', 'YES');
INSERT INTO stg_expected_columns VALUES ('collaboration_applications', 'id', 'varchar(36)', 'NO');
INSERT INTO stg_expected_columns VALUES ('collaboration_applications', 'user_id', 'varchar(36)', 'NO');
INSERT INTO stg_expected_columns VALUES ('collaboration_applications', 'collab_id', 'varchar(36)', 'NO');
INSERT INTO stg_expected_columns VALUES ('collaboration_applications', 'message', 'text', 'YES');
INSERT INTO stg_expected_columns VALUES ('collaboration_applications', 'status', 'enum(''pending'',''accepted'',''rejected'',''withdrawn'')', 'NO');
INSERT INTO stg_expected_columns VALUES ('collaboration_applications', 'created_at', 'timestamp', 'YES');
INSERT INTO stg_expected_columns VALUES ('collaboration_applications', 'updated_at', 'timestamp', 'YES');
INSERT INTO stg_expected_columns VALUES ('collaborations', 'id', 'varchar(36)', 'NO');
INSERT INTO stg_expected_columns VALUES ('collaborations', 'user_id', 'varchar(36)', 'NO');
INSERT INTO stg_expected_columns VALUES ('collaborations', 'title', 'varchar(255)', 'NO');
INSERT INTO stg_expected_columns VALUES ('collaborations', 'description', 'text', 'NO');
INSERT INTO stg_expected_columns VALUES ('collaborations', 'type', 'enum(''coursework'',''portfolio'',''product'')', 'NO');
INSERT INTO stg_expected_columns VALUES ('collaborations', 'required_member', 'int', 'NO');
INSERT INTO stg_expected_columns VALUES ('collaborations', 'due_date', 'date', 'YES');
INSERT INTO stg_expected_columns VALUES ('collaborations', 'communication_url', 'varchar(255)', 'YES');
INSERT INTO stg_expected_columns VALUES ('collaborations', 'status', 'enum(''open'',''in-progress'',''completed'')', 'NO');
INSERT INTO stg_expected_columns VALUES ('collaborations', 'created_at', 'timestamp', 'YES');
INSERT INTO stg_expected_columns VALUES ('collaborations', 'updated_at', 'timestamp', 'YES');
INSERT INTO stg_expected_columns VALUES ('collaborations', 'deleted_at', 'timestamp', 'YES');
INSERT INTO stg_expected_columns VALUES ('comment_likes', 'id', 'varchar(36)', 'NO');
INSERT INTO stg_expected_columns VALUES ('comment_likes', 'comment_id', 'varchar(36)', 'NO');
INSERT INTO stg_expected_columns VALUES ('comment_likes', 'user_id', 'varchar(36)', 'NO');
INSERT INTO stg_expected_columns VALUES ('comment_likes', 'created_at', 'timestamp', 'YES');
INSERT INTO stg_expected_columns VALUES ('comments', 'id', 'varchar(36)', 'NO');
INSERT INTO stg_expected_columns VALUES ('comments', 'user_id', 'varchar(36)', 'NO');
INSERT INTO stg_expected_columns VALUES ('comments', 'post_id', 'varchar(36)', 'NO');
INSERT INTO stg_expected_columns VALUES ('comments', 'content', 'text', 'NO');
INSERT INTO stg_expected_columns VALUES ('comments', 'created_at', 'timestamp', 'YES');
INSERT INTO stg_expected_columns VALUES ('comments', 'updated_at', 'timestamp', 'YES');
INSERT INTO stg_expected_columns VALUES ('comments', 'deleted_at', 'timestamp', 'YES');
INSERT INTO stg_expected_columns VALUES ('dm_conversations', 'id', 'varchar(36)', 'NO');
INSERT INTO stg_expected_columns VALUES ('dm_conversations', 'application_id', 'varchar(36)', 'YES');
INSERT INTO stg_expected_columns VALUES ('dm_conversations', 'created_at', 'timestamp', 'YES');
INSERT INTO stg_expected_columns VALUES ('dm_conversations', 'updated_at', 'timestamp', 'YES');
INSERT INTO stg_expected_columns VALUES ('dm_messages', 'id', 'varchar(36)', 'NO');
INSERT INTO stg_expected_columns VALUES ('dm_messages', 'conversation_id', 'varchar(36)', 'NO');
INSERT INTO stg_expected_columns VALUES ('dm_messages', 'sender_id', 'varchar(36)', 'NO');
INSERT INTO stg_expected_columns VALUES ('dm_messages', 'body', 'text', 'NO');
INSERT INTO stg_expected_columns VALUES ('dm_messages', 'is_read', 'tinyint(1)', 'NO');
INSERT INTO stg_expected_columns VALUES ('dm_messages', 'created_at', 'timestamp', 'YES');
INSERT INTO stg_expected_columns VALUES ('dm_participants', 'id', 'varchar(36)', 'NO');
INSERT INTO stg_expected_columns VALUES ('dm_participants', 'conversation_id', 'varchar(36)', 'NO');
INSERT INTO stg_expected_columns VALUES ('dm_participants', 'user_id', 'varchar(36)', 'NO');
INSERT INTO stg_expected_columns VALUES ('dm_participants', 'created_at', 'timestamp', 'YES');
INSERT INTO stg_expected_columns VALUES ('dm_participants', 'is_muted', 'tinyint(1)', 'NO');
INSERT INTO stg_expected_columns VALUES ('dm_participants', 'is_pinned', 'tinyint(1)', 'NO');
INSERT INTO stg_expected_columns VALUES ('dm_participants', 'cleared_at', 'timestamp', 'YES');
INSERT INTO stg_expected_columns VALUES ('dm_participants', 'hidden_at', 'timestamp', 'YES');
INSERT INTO stg_expected_columns VALUES ('like_posts', 'id', 'varchar(36)', 'NO');
INSERT INTO stg_expected_columns VALUES ('like_posts', 'post_id', 'varchar(36)', 'NO');
INSERT INTO stg_expected_columns VALUES ('like_posts', 'user_id', 'varchar(36)', 'NO');
INSERT INTO stg_expected_columns VALUES ('like_posts', 'created_at', 'timestamp', 'YES');
INSERT INTO stg_expected_columns VALUES ('media_posts', 'id', 'varchar(36)', 'NO');
INSERT INTO stg_expected_columns VALUES ('media_posts', 'post_id', 'varchar(36)', 'NO');
INSERT INTO stg_expected_columns VALUES ('media_posts', 'media_url', 'varchar(255)', 'NO');
INSERT INTO stg_expected_columns VALUES ('media_posts', 'media_public_id', 'varchar(150)', 'YES');
INSERT INTO stg_expected_columns VALUES ('media_posts', 'created_at', 'timestamp', 'YES');
INSERT INTO stg_expected_columns VALUES ('notifications', 'id', 'varchar(36)', 'NO');
INSERT INTO stg_expected_columns VALUES ('notifications', 'user_id', 'varchar(36)', 'NO');
INSERT INTO stg_expected_columns VALUES ('notifications', 'actor_id', 'varchar(36)', 'YES');
INSERT INTO stg_expected_columns VALUES ('notifications', 'type', 'varchar(50)', 'NO');
INSERT INTO stg_expected_columns VALUES ('notifications', 'title', 'varchar(255)', 'NO');
INSERT INTO stg_expected_columns VALUES ('notifications', 'target_type', 'varchar(50)', 'YES');
INSERT INTO stg_expected_columns VALUES ('notifications', 'target_id', 'varchar(36)', 'YES');
INSERT INTO stg_expected_columns VALUES ('notifications', 'is_read', 'tinyint(1)', 'NO');
INSERT INTO stg_expected_columns VALUES ('notifications', 'created_at', 'timestamp', 'YES');
INSERT INTO stg_expected_columns VALUES ('post_views', 'id', 'varchar(36)', 'NO');
INSERT INTO stg_expected_columns VALUES ('post_views', 'post_id', 'varchar(36)', 'NO');
INSERT INTO stg_expected_columns VALUES ('post_views', 'user_id', 'varchar(36)', 'NO');
INSERT INTO stg_expected_columns VALUES ('post_views', 'viewed_at', 'timestamp', 'YES');
INSERT INTO stg_expected_columns VALUES ('profile_social_medias', 'id', 'varchar(36)', 'NO');
INSERT INTO stg_expected_columns VALUES ('profile_social_medias', 'user_id', 'varchar(36)', 'NO');
INSERT INTO stg_expected_columns VALUES ('profile_social_medias', 'platform', 'varchar(50)', 'NO');
INSERT INTO stg_expected_columns VALUES ('profile_social_medias', 'url', 'varchar(100)', 'NO');
INSERT INTO stg_expected_columns VALUES ('profiles', 'id', 'varchar(36)', 'NO');
INSERT INTO stg_expected_columns VALUES ('profiles', 'user_id', 'varchar(36)', 'NO');
INSERT INTO stg_expected_columns VALUES ('profiles', 'full_name', 'varchar(100)', 'NO');
INSERT INTO stg_expected_columns VALUES ('profiles', 'bio', 'varchar(150)', 'YES');
INSERT INTO stg_expected_columns VALUES ('profiles', 'photo_profile_url', 'varchar(150)', 'YES');
INSERT INTO stg_expected_columns VALUES ('profiles', 'photo_profile_public_id', 'varchar(100)', 'YES');
INSERT INTO stg_expected_columns VALUES ('profiles', 'created_at', 'datetime', 'NO');
INSERT INTO stg_expected_columns VALUES ('public_posts', 'id', 'varchar(36)', 'NO');
INSERT INTO stg_expected_columns VALUES ('public_posts', 'user_id', 'varchar(36)', 'NO');
INSERT INTO stg_expected_columns VALUES ('public_posts', 'description', 'text', 'NO');
INSERT INTO stg_expected_columns VALUES ('public_posts', 'portfolio_type', 'enum(''link'',''images'')', 'NO');
INSERT INTO stg_expected_columns VALUES ('public_posts', 'external_url', 'varchar(255)', 'YES');
INSERT INTO stg_expected_columns VALUES ('public_posts', 'view_count', 'int', 'NO');
INSERT INTO stg_expected_columns VALUES ('public_posts', 'created_at', 'timestamp', 'YES');
INSERT INTO stg_expected_columns VALUES ('public_posts', 'updated_at', 'timestamp', 'YES');
INSERT INTO stg_expected_columns VALUES ('public_posts', 'deleted_at', 'timestamp', 'YES');
INSERT INTO stg_expected_columns VALUES ('skills', 'id', 'varchar(36)', 'NO');
INSERT INTO stg_expected_columns VALUES ('skills', 'skill_name', 'varchar(100)', 'NO');
INSERT INTO stg_expected_columns VALUES ('skills', 'scope', 'enum(''system'',''private'')', 'NO');
INSERT INTO stg_expected_columns VALUES ('skills', 'created_by', 'varchar(36)', 'YES');
INSERT INTO stg_expected_columns VALUES ('upload_grants', 'id', 'bigint unsigned', 'NO');
INSERT INTO stg_expected_columns VALUES ('upload_grants', 'user_id', 'varchar(36)', 'NO');
INSERT INTO stg_expected_columns VALUES ('upload_grants', 'created_at', 'datetime', 'NO');
INSERT INTO stg_expected_columns VALUES ('user_collaboration', 'id', 'varchar(36)', 'NO');
INSERT INTO stg_expected_columns VALUES ('user_collaboration', 'user_id', 'varchar(36)', 'NO');
INSERT INTO stg_expected_columns VALUES ('user_collaboration', 'collaboration_id', 'varchar(36)', 'NO');
INSERT INTO stg_expected_columns VALUES ('user_collaboration', 'status', 'enum(''not-started'',''pending'',''complete'',''revise'')', 'NO');
INSERT INTO stg_expected_columns VALUES ('user_collaboration', 'created_at', 'timestamp', 'YES');
INSERT INTO stg_expected_columns VALUES ('user_skill', 'id', 'varchar(36)', 'NO');
INSERT INTO stg_expected_columns VALUES ('user_skill', 'skill_id', 'varchar(36)', 'NO');
INSERT INTO stg_expected_columns VALUES ('user_skill', 'user_id', 'varchar(36)', 'NO');
INSERT INTO stg_expected_columns VALUES ('users', 'id', 'varchar(36)', 'NO');
INSERT INTO stg_expected_columns VALUES ('users', 'username', 'varchar(100)', 'NO');
INSERT INTO stg_expected_columns VALUES ('users', 'password', 'varchar(150)', 'NO');
INSERT INTO stg_expected_columns VALUES ('users', 'email', 'varchar(100)', 'NO');
INSERT INTO stg_expected_columns VALUES ('users', 'google_id', 'varchar(255)', 'YES');
INSERT INTO stg_expected_columns VALUES ('users', 'is_active', 'tinyint(1)', 'NO');
INSERT INTO stg_expected_columns VALUES ('users', 'created_at', 'datetime', 'NO');
INSERT INTO stg_expected_columns VALUES ('zone', 'id', 'varchar(36)', 'NO');
INSERT INTO stg_expected_columns VALUES ('zone', 'circle_id', 'varchar(36)', 'NO');
INSERT INTO stg_expected_columns VALUES ('zone', 'title', 'varchar(150)', 'NO');
INSERT INTO stg_expected_columns VALUES ('zone', 'allow_public_message', 'tinyint(1)', 'NO');
INSERT INTO stg_expected_columns VALUES ('zone', 'timestamp', 'datetime', 'NO');
INSERT INTO stg_expected_columns VALUES ('zone_messages', 'id', 'varchar(36)', 'NO');
INSERT INTO stg_expected_columns VALUES ('zone_messages', 'zone_id', 'varchar(36)', 'NO');
INSERT INTO stg_expected_columns VALUES ('zone_messages', 'user_id', 'varchar(36)', 'NO');
INSERT INTO stg_expected_columns VALUES ('zone_messages', 'message', 'text', 'NO');
INSERT INTO stg_expected_columns VALUES ('zone_messages', 'timestamp', 'datetime', 'NO');

DELIMITER $$
DROP PROCEDURE IF EXISTS stg_ensure_column$$
CREATE PROCEDURE stg_ensure_column(IN p_table VARCHAR(64), IN p_column VARCHAR(64), IN p_definition TEXT, IN p_after VARCHAR(64))
BEGIN
  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND COLUMN_NAME = p_column) THEN
    SET @s = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_column, '` ', p_definition, IF(p_after = '', '', CONCAT(' AFTER `', p_after, '`')));
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
  END IF;
END$$
DROP PROCEDURE IF EXISTS stg_fix_column$$
CREATE PROCEDURE stg_fix_column(IN p_table VARCHAR(64), IN p_column VARCHAR(64), IN p_definition TEXT, IN p_type VARCHAR(128), IN p_nullable VARCHAR(3))
BEGIN
  DECLARE v_type VARCHAR(128); DECLARE v_null VARCHAR(3);
  SELECT COLUMN_TYPE, IS_NULLABLE INTO v_type, v_null FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND COLUMN_NAME = p_column;
  IF v_type IS NOT NULL AND (LOWER(v_type) <> LOWER(p_type) OR v_null <> p_nullable) THEN
    SET @s = CONCAT('ALTER TABLE `', p_table, '` MODIFY COLUMN `', p_column, '` ', p_definition);
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
  END IF;
END$$
DROP PROCEDURE IF EXISTS stg_ensure_index$$
CREATE PROCEDURE stg_ensure_index(IN p_table VARCHAR(64), IN p_index VARCHAR(64), IN p_columns VARCHAR(255), IN p_unique TINYINT)
BEGIN
  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND INDEX_NAME = p_index) THEN
    SET @s = CONCAT('ALTER TABLE `', p_table, '` ADD ', IF(p_unique = 1, 'UNIQUE ', ''), 'INDEX `', p_index, '` (', p_columns, ')');
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
  END IF;
END$$
DELIMITER ;

-- ============================================================
-- BAGIAN 1 — VALIDASI (read-only): status tiap atribut vs acuan
-- ============================================================
SELECT e.table_name, e.column_name, e.column_type AS expected_type, c.COLUMN_TYPE AS actual_type,
  CASE WHEN c.COLUMN_NAME IS NULL THEN 'MISSING'
       WHEN LOWER(c.COLUMN_TYPE) <> LOWER(e.column_type) OR c.IS_NULLABLE <> e.is_nullable THEN 'MISMATCH'
       ELSE 'OK' END AS status
FROM stg_expected_columns e
LEFT JOIN INFORMATION_SCHEMA.COLUMNS c
  ON c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME = e.table_name AND c.COLUMN_NAME = e.column_name
UNION ALL
SELECT c.TABLE_NAME, c.COLUMN_NAME, NULL, c.COLUMN_TYPE, 'EXTRA_IN_DB'
FROM INFORMATION_SCHEMA.COLUMNS c
LEFT JOIN stg_expected_columns e ON e.table_name = c.TABLE_NAME AND e.column_name = c.COLUMN_NAME
WHERE c.TABLE_SCHEMA = DATABASE() AND e.column_name IS NULL
ORDER BY 1, 2;

-- ============================================================
-- BAGIAN 2 — PERBAIKAN IDEMPOTENT
-- 2a. Tabel yang belum ada dibuat persis seperti acuan.
-- ============================================================
SET FOREIGN_KEY_CHECKS = 0;
CREATE TABLE IF NOT EXISTS `circle` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid()),
  `collaboration_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `photo_profile_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `timestamp` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_circle_collaboration_id` (`collaboration_id`),
  KEY `idx_circle_collaboration_id` (`collaboration_id`),
  CONSTRAINT `fk_circle_collaboration` FOREIGN KEY (`collaboration_id`) REFERENCES `collaborations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `collab_media` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid()),
  `collab_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `media_url` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `media_public_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_collab_media_collab_id` (`collab_id`),
  CONSTRAINT `fk_collab_media_collab` FOREIGN KEY (`collab_id`) REFERENCES `collaborations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `collab_skills` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid()),
  `collab_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `skill_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_collab_skills` (`collab_id`,`skill_id`),
  KEY `idx_collab_skills_collab_id` (`collab_id`),
  KEY `idx_collab_skills_skill_id` (`skill_id`),
  CONSTRAINT `fk_collab_skills_collab` FOREIGN KEY (`collab_id`) REFERENCES `collaborations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_collab_skills_skill` FOREIGN KEY (`skill_id`) REFERENCES `skills` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `collaboration_applications` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid()),
  `user_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `collab_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `message` text COLLATE utf8mb4_unicode_ci,
  `status` enum('pending','accepted','rejected','withdrawn') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_collab_application` (`user_id`,`collab_id`),
  KEY `idx_collab_app_user_id` (`user_id`),
  KEY `idx_collab_app_collab_id` (`collab_id`),
  KEY `idx_collab_app_status` (`status`),
  CONSTRAINT `fk_collab_app_collab` FOREIGN KEY (`collab_id`) REFERENCES `collaborations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_collab_app_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `collaborations` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid()),
  `user_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` enum('coursework','portfolio','product') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'coursework',
  `required_member` int NOT NULL DEFAULT '1',
  `due_date` date DEFAULT NULL,
  `communication_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('open','in-progress','completed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_collaborations_user_id` (`user_id`),
  KEY `idx_collaborations_status` (`status`),
  KEY `idx_collaborations_created_at` (`created_at`),
  CONSTRAINT `fk_collaborations_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `comment_likes` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid()),
  `comment_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_comment_like` (`user_id`,`comment_id`),
  KEY `idx_comment_likes_comment_id` (`comment_id`),
  KEY `idx_comment_likes_user_id` (`user_id`),
  CONSTRAINT `fk_comment_likes_comment` FOREIGN KEY (`comment_id`) REFERENCES `comments` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_comment_likes_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `comments` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid()),
  `user_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `post_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `content` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_comments_post_id` (`post_id`),
  KEY `idx_comments_user_id` (`user_id`),
  CONSTRAINT `fk_comments_post` FOREIGN KEY (`post_id`) REFERENCES `public_posts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_comments_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `dm_conversations` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid()),
  `application_id` varchar(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_dm_conversations_application` (`application_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `dm_messages` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid()),
  `conversation_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sender_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `body` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_dm_messages_conversation_id` (`conversation_id`),
  KEY `idx_dm_messages_sender_id` (`sender_id`),
  KEY `idx_dm_messages_created_at` (`created_at`),
  CONSTRAINT `fk_dm_messages_conversation` FOREIGN KEY (`conversation_id`) REFERENCES `dm_conversations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_dm_messages_sender` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `dm_participants` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid()),
  `conversation_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `is_muted` tinyint(1) NOT NULL DEFAULT '0',
  `is_pinned` tinyint(1) NOT NULL DEFAULT '0',
  `cleared_at` timestamp NULL DEFAULT NULL,
  `hidden_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_dm_participant` (`conversation_id`,`user_id`),
  KEY `idx_dm_participants_conversation_id` (`conversation_id`),
  KEY `idx_dm_participants_user_id` (`user_id`),
  CONSTRAINT `fk_dm_participants_conversation` FOREIGN KEY (`conversation_id`) REFERENCES `dm_conversations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_dm_participants_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `like_posts` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid()),
  `post_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_post_like` (`user_id`,`post_id`),
  KEY `idx_like_posts_post_id` (`post_id`),
  KEY `idx_like_posts_user_id` (`user_id`),
  CONSTRAINT `fk_likes_post` FOREIGN KEY (`post_id`) REFERENCES `public_posts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_likes_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `media_posts` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid()),
  `post_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `media_url` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `media_public_id` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_media_posts_post_id` (`post_id`),
  CONSTRAINT `fk_media_post` FOREIGN KEY (`post_id`) REFERENCES `public_posts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `notifications` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid()),
  `user_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `actor_id` varchar(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `target_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `target_id` varchar(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_notifications_user_id` (`user_id`),
  KEY `idx_notifications_is_read` (`is_read`),
  KEY `idx_notifications_created_at` (`created_at`),
  KEY `fk_notifications_actor` (`actor_id`),
  CONSTRAINT `fk_notifications_actor` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `post_views` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid()),
  `post_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `viewed_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_post_view` (`user_id`,`post_id`),
  KEY `idx_post_views_post_id` (`post_id`),
  KEY `idx_post_views_user_id` (`user_id`),
  CONSTRAINT `fk_views_post` FOREIGN KEY (`post_id`) REFERENCES `public_posts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_views_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `profile_social_medias` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid()),
  `user_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `platform` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `url` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_social_media_user` (`user_id`),
  CONSTRAINT `fk_social_media_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `profiles` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid()),
  `user_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `full_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `bio` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `photo_profile_url` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `photo_profile_public_id` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_profiles_user_id` (`user_id`),
  CONSTRAINT `fk_profiles_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `public_posts` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid()),
  `user_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `portfolio_type` enum('link','images') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'link',
  `external_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `view_count` int NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_public_posts_user_id` (`user_id`),
  CONSTRAINT `fk_posts_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `skills` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid()),
  `skill_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `scope` enum('system','private') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'system',
  `created_by` varchar(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_skills_name` (`skill_name`),
  KEY `idx_skills_scope` (`scope`),
  KEY `idx_skills_created_by` (`created_by`),
  CONSTRAINT `fk_skills_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `upload_grants` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_upload_grants_user_time` (`user_id`,`created_at`),
  CONSTRAINT `fk_upload_grants_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=63 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_collaboration` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid()),
  `user_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `collaboration_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('not-started','pending','complete','revise') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'not-started',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_collaboration` (`user_id`,`collaboration_id`),
  KEY `idx_user_collab_user_id` (`user_id`),
  KEY `idx_user_collab_collab_id` (`collaboration_id`),
  CONSTRAINT `fk_user_collab_collab` FOREIGN KEY (`collaboration_id`) REFERENCES `collaborations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_user_collab_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_skill` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid()),
  `skill_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_skill` (`user_id`,`skill_id`),
  KEY `idx_user_skill_skill_id` (`skill_id`),
  KEY `idx_user_skill_user_id` (`user_id`),
  CONSTRAINT `fk_user_skill_skill` FOREIGN KEY (`skill_id`) REFERENCES `skills` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_user_skill_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `users` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid()),
  `username` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `google_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `uq_users_username` (`username`),
  UNIQUE KEY `uq_users_email` (`email`),
  UNIQUE KEY `google_id` (`google_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `zone` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid()),
  `circle_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `allow_public_message` tinyint(1) NOT NULL DEFAULT '0',
  `timestamp` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_zone_circle_id` (`circle_id`),
  CONSTRAINT `fk_zone_circle` FOREIGN KEY (`circle_id`) REFERENCES `circle` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `zone_messages` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid()),
  `zone_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `message` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `timestamp` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_zone_messages_zone_id` (`zone_id`),
  KEY `idx_zone_messages_user_id` (`user_id`),
  KEY `idx_zone_messages_timestamp` (`timestamp`),
  CONSTRAINT `fk_zone_messages_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_zone_messages_zone` FOREIGN KEY (`zone_id`) REFERENCES `zone` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2b. Kolom yang hilang ditambahkan (posisi mengikuti acuan);
--     kolom yang tipenya beda diperbarui (MODIFY).
CALL stg_ensure_column('circle', 'id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid())', '');
CALL stg_fix_column('circle', 'id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid())', 'varchar(36)', 'NO');
CALL stg_ensure_column('circle', 'collaboration_id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL', 'id');
CALL stg_fix_column('circle', 'collaboration_id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL', 'varchar(36)', 'NO');
CALL stg_ensure_column('circle', 'title', 'varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL', 'collaboration_id');
CALL stg_fix_column('circle', 'title', 'varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL', 'varchar(255)', 'NO');
CALL stg_ensure_column('circle', 'photo_profile_url', 'varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'title');
CALL stg_fix_column('circle', 'photo_profile_url', 'varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'varchar(255)', 'YES');
CALL stg_ensure_column('circle', 'timestamp', 'datetime NOT NULL DEFAULT CURRENT_TIMESTAMP', 'photo_profile_url');
CALL stg_fix_column('circle', 'timestamp', 'datetime NOT NULL DEFAULT CURRENT_TIMESTAMP', 'datetime', 'NO');
CALL stg_ensure_column('collab_media', 'id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid())', '');
CALL stg_fix_column('collab_media', 'id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid())', 'varchar(36)', 'NO');
CALL stg_ensure_column('collab_media', 'collab_id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL', 'id');
CALL stg_fix_column('collab_media', 'collab_id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL', 'varchar(36)', 'NO');
CALL stg_ensure_column('collab_media', 'media_url', 'varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL', 'collab_id');
CALL stg_fix_column('collab_media', 'media_url', 'varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL', 'varchar(255)', 'NO');
CALL stg_ensure_column('collab_media', 'media_public_id', 'varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'media_url');
CALL stg_fix_column('collab_media', 'media_public_id', 'varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'varchar(255)', 'YES');
CALL stg_ensure_column('collab_media', 'created_at', 'timestamp NULL DEFAULT CURRENT_TIMESTAMP', 'media_public_id');
CALL stg_fix_column('collab_media', 'created_at', 'timestamp NULL DEFAULT CURRENT_TIMESTAMP', 'timestamp', 'YES');
CALL stg_ensure_column('collab_media', 'updated_at', 'timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'created_at');
CALL stg_fix_column('collab_media', 'updated_at', 'timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'timestamp', 'YES');
CALL stg_ensure_column('collab_skills', 'id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid())', '');
CALL stg_fix_column('collab_skills', 'id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid())', 'varchar(36)', 'NO');
CALL stg_ensure_column('collab_skills', 'collab_id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL', 'id');
CALL stg_fix_column('collab_skills', 'collab_id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL', 'varchar(36)', 'NO');
CALL stg_ensure_column('collab_skills', 'skill_id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL', 'collab_id');
CALL stg_fix_column('collab_skills', 'skill_id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL', 'varchar(36)', 'NO');
CALL stg_ensure_column('collab_skills', 'created_at', 'timestamp NULL DEFAULT CURRENT_TIMESTAMP', 'skill_id');
CALL stg_fix_column('collab_skills', 'created_at', 'timestamp NULL DEFAULT CURRENT_TIMESTAMP', 'timestamp', 'YES');
CALL stg_ensure_column('collaboration_applications', 'id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid())', '');
CALL stg_fix_column('collaboration_applications', 'id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid())', 'varchar(36)', 'NO');
CALL stg_ensure_column('collaboration_applications', 'user_id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL', 'id');
CALL stg_fix_column('collaboration_applications', 'user_id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL', 'varchar(36)', 'NO');
CALL stg_ensure_column('collaboration_applications', 'collab_id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL', 'user_id');
CALL stg_fix_column('collaboration_applications', 'collab_id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL', 'varchar(36)', 'NO');
CALL stg_ensure_column('collaboration_applications', 'message', 'text COLLATE utf8mb4_unicode_ci', 'collab_id');
CALL stg_fix_column('collaboration_applications', 'message', 'text COLLATE utf8mb4_unicode_ci', 'text', 'YES');
CALL stg_ensure_column('collaboration_applications', 'status', 'enum(''pending'',''accepted'',''rejected'',''withdrawn'') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''pending''', 'message');
CALL stg_fix_column('collaboration_applications', 'status', 'enum(''pending'',''accepted'',''rejected'',''withdrawn'') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''pending''', 'enum(''pending'',''accepted'',''rejected'',''withdrawn'')', 'NO');
CALL stg_ensure_column('collaboration_applications', 'created_at', 'timestamp NULL DEFAULT CURRENT_TIMESTAMP', 'status');
CALL stg_fix_column('collaboration_applications', 'created_at', 'timestamp NULL DEFAULT CURRENT_TIMESTAMP', 'timestamp', 'YES');
CALL stg_ensure_column('collaboration_applications', 'updated_at', 'timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'created_at');
CALL stg_fix_column('collaboration_applications', 'updated_at', 'timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'timestamp', 'YES');
CALL stg_ensure_column('collaborations', 'id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid())', '');
CALL stg_fix_column('collaborations', 'id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid())', 'varchar(36)', 'NO');
CALL stg_ensure_column('collaborations', 'user_id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL', 'id');
CALL stg_fix_column('collaborations', 'user_id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL', 'varchar(36)', 'NO');
CALL stg_ensure_column('collaborations', 'title', 'varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL', 'user_id');
CALL stg_fix_column('collaborations', 'title', 'varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL', 'varchar(255)', 'NO');
CALL stg_ensure_column('collaborations', 'description', 'text COLLATE utf8mb4_unicode_ci NOT NULL', 'title');
CALL stg_fix_column('collaborations', 'description', 'text COLLATE utf8mb4_unicode_ci NOT NULL', 'text', 'NO');
CALL stg_ensure_column('collaborations', 'type', 'enum(''coursework'',''portfolio'',''product'') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''coursework''', 'description');
CALL stg_fix_column('collaborations', 'type', 'enum(''coursework'',''portfolio'',''product'') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''coursework''', 'enum(''coursework'',''portfolio'',''product'')', 'NO');
CALL stg_ensure_column('collaborations', 'required_member', 'int NOT NULL DEFAULT ''1''', 'type');
CALL stg_fix_column('collaborations', 'required_member', 'int NOT NULL DEFAULT ''1''', 'int', 'NO');
CALL stg_ensure_column('collaborations', 'due_date', 'date DEFAULT NULL', 'required_member');
CALL stg_fix_column('collaborations', 'due_date', 'date DEFAULT NULL', 'date', 'YES');
CALL stg_ensure_column('collaborations', 'communication_url', 'varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'due_date');
CALL stg_fix_column('collaborations', 'communication_url', 'varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'varchar(255)', 'YES');
CALL stg_ensure_column('collaborations', 'status', 'enum(''open'',''in-progress'',''completed'') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''open''', 'communication_url');
CALL stg_fix_column('collaborations', 'status', 'enum(''open'',''in-progress'',''completed'') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''open''', 'enum(''open'',''in-progress'',''completed'')', 'NO');
CALL stg_ensure_column('collaborations', 'created_at', 'timestamp NULL DEFAULT CURRENT_TIMESTAMP', 'status');
CALL stg_fix_column('collaborations', 'created_at', 'timestamp NULL DEFAULT CURRENT_TIMESTAMP', 'timestamp', 'YES');
CALL stg_ensure_column('collaborations', 'updated_at', 'timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'created_at');
CALL stg_fix_column('collaborations', 'updated_at', 'timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'timestamp', 'YES');
CALL stg_ensure_column('collaborations', 'deleted_at', 'timestamp NULL DEFAULT NULL', 'updated_at');
CALL stg_fix_column('collaborations', 'deleted_at', 'timestamp NULL DEFAULT NULL', 'timestamp', 'YES');
CALL stg_ensure_column('comment_likes', 'id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid())', '');
CALL stg_fix_column('comment_likes', 'id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid())', 'varchar(36)', 'NO');
CALL stg_ensure_column('comment_likes', 'comment_id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL', 'id');
CALL stg_fix_column('comment_likes', 'comment_id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL', 'varchar(36)', 'NO');
CALL stg_ensure_column('comment_likes', 'user_id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL', 'comment_id');
CALL stg_fix_column('comment_likes', 'user_id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL', 'varchar(36)', 'NO');
CALL stg_ensure_column('comment_likes', 'created_at', 'timestamp NULL DEFAULT CURRENT_TIMESTAMP', 'user_id');
CALL stg_fix_column('comment_likes', 'created_at', 'timestamp NULL DEFAULT CURRENT_TIMESTAMP', 'timestamp', 'YES');
CALL stg_ensure_column('comments', 'id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid())', '');
CALL stg_fix_column('comments', 'id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid())', 'varchar(36)', 'NO');
CALL stg_ensure_column('comments', 'user_id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL', 'id');
CALL stg_fix_column('comments', 'user_id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL', 'varchar(36)', 'NO');
CALL stg_ensure_column('comments', 'post_id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL', 'user_id');
CALL stg_fix_column('comments', 'post_id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL', 'varchar(36)', 'NO');
CALL stg_ensure_column('comments', 'content', 'text COLLATE utf8mb4_unicode_ci NOT NULL', 'post_id');
CALL stg_fix_column('comments', 'content', 'text COLLATE utf8mb4_unicode_ci NOT NULL', 'text', 'NO');
CALL stg_ensure_column('comments', 'created_at', 'timestamp NULL DEFAULT CURRENT_TIMESTAMP', 'content');
CALL stg_fix_column('comments', 'created_at', 'timestamp NULL DEFAULT CURRENT_TIMESTAMP', 'timestamp', 'YES');
CALL stg_ensure_column('comments', 'updated_at', 'timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'created_at');
CALL stg_fix_column('comments', 'updated_at', 'timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'timestamp', 'YES');
CALL stg_ensure_column('comments', 'deleted_at', 'timestamp NULL DEFAULT NULL', 'updated_at');
CALL stg_fix_column('comments', 'deleted_at', 'timestamp NULL DEFAULT NULL', 'timestamp', 'YES');
CALL stg_ensure_column('dm_conversations', 'id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid())', '');
CALL stg_fix_column('dm_conversations', 'id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid())', 'varchar(36)', 'NO');
CALL stg_ensure_column('dm_conversations', 'application_id', 'varchar(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'id');
CALL stg_fix_column('dm_conversations', 'application_id', 'varchar(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'varchar(36)', 'YES');
CALL stg_ensure_column('dm_conversations', 'created_at', 'timestamp NULL DEFAULT CURRENT_TIMESTAMP', 'application_id');
CALL stg_fix_column('dm_conversations', 'created_at', 'timestamp NULL DEFAULT CURRENT_TIMESTAMP', 'timestamp', 'YES');
CALL stg_ensure_column('dm_conversations', 'updated_at', 'timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'created_at');
CALL stg_fix_column('dm_conversations', 'updated_at', 'timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'timestamp', 'YES');
CALL stg_ensure_column('dm_messages', 'id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid())', '');
CALL stg_fix_column('dm_messages', 'id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid())', 'varchar(36)', 'NO');
CALL stg_ensure_column('dm_messages', 'conversation_id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL', 'id');
CALL stg_fix_column('dm_messages', 'conversation_id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL', 'varchar(36)', 'NO');
CALL stg_ensure_column('dm_messages', 'sender_id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL', 'conversation_id');
CALL stg_fix_column('dm_messages', 'sender_id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL', 'varchar(36)', 'NO');
CALL stg_ensure_column('dm_messages', 'body', 'text COLLATE utf8mb4_unicode_ci NOT NULL', 'sender_id');
CALL stg_fix_column('dm_messages', 'body', 'text COLLATE utf8mb4_unicode_ci NOT NULL', 'text', 'NO');
CALL stg_ensure_column('dm_messages', 'is_read', 'tinyint(1) NOT NULL DEFAULT ''0''', 'body');
CALL stg_fix_column('dm_messages', 'is_read', 'tinyint(1) NOT NULL DEFAULT ''0''', 'tinyint(1)', 'NO');
CALL stg_ensure_column('dm_messages', 'created_at', 'timestamp NULL DEFAULT CURRENT_TIMESTAMP', 'is_read');
CALL stg_fix_column('dm_messages', 'created_at', 'timestamp NULL DEFAULT CURRENT_TIMESTAMP', 'timestamp', 'YES');
CALL stg_ensure_column('dm_participants', 'id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid())', '');
CALL stg_fix_column('dm_participants', 'id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid())', 'varchar(36)', 'NO');
CALL stg_ensure_column('dm_participants', 'conversation_id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL', 'id');
CALL stg_fix_column('dm_participants', 'conversation_id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL', 'varchar(36)', 'NO');
CALL stg_ensure_column('dm_participants', 'user_id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL', 'conversation_id');
CALL stg_fix_column('dm_participants', 'user_id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL', 'varchar(36)', 'NO');
CALL stg_ensure_column('dm_participants', 'created_at', 'timestamp NULL DEFAULT CURRENT_TIMESTAMP', 'user_id');
CALL stg_fix_column('dm_participants', 'created_at', 'timestamp NULL DEFAULT CURRENT_TIMESTAMP', 'timestamp', 'YES');
CALL stg_ensure_column('dm_participants', 'is_muted', 'tinyint(1) NOT NULL DEFAULT ''0''', 'created_at');
CALL stg_fix_column('dm_participants', 'is_muted', 'tinyint(1) NOT NULL DEFAULT ''0''', 'tinyint(1)', 'NO');
CALL stg_ensure_column('dm_participants', 'is_pinned', 'tinyint(1) NOT NULL DEFAULT ''0''', 'is_muted');
CALL stg_fix_column('dm_participants', 'is_pinned', 'tinyint(1) NOT NULL DEFAULT ''0''', 'tinyint(1)', 'NO');
CALL stg_ensure_column('dm_participants', 'cleared_at', 'timestamp NULL DEFAULT NULL', 'is_pinned');
CALL stg_fix_column('dm_participants', 'cleared_at', 'timestamp NULL DEFAULT NULL', 'timestamp', 'YES');
CALL stg_ensure_column('dm_participants', 'hidden_at', 'timestamp NULL DEFAULT NULL', 'cleared_at');
CALL stg_fix_column('dm_participants', 'hidden_at', 'timestamp NULL DEFAULT NULL', 'timestamp', 'YES');
CALL stg_ensure_column('like_posts', 'id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid())', '');
CALL stg_fix_column('like_posts', 'id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid())', 'varchar(36)', 'NO');
CALL stg_ensure_column('like_posts', 'post_id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL', 'id');
CALL stg_fix_column('like_posts', 'post_id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL', 'varchar(36)', 'NO');
CALL stg_ensure_column('like_posts', 'user_id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL', 'post_id');
CALL stg_fix_column('like_posts', 'user_id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL', 'varchar(36)', 'NO');
CALL stg_ensure_column('like_posts', 'created_at', 'timestamp NULL DEFAULT CURRENT_TIMESTAMP', 'user_id');
CALL stg_fix_column('like_posts', 'created_at', 'timestamp NULL DEFAULT CURRENT_TIMESTAMP', 'timestamp', 'YES');
CALL stg_ensure_column('media_posts', 'id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid())', '');
CALL stg_fix_column('media_posts', 'id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid())', 'varchar(36)', 'NO');
CALL stg_ensure_column('media_posts', 'post_id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL', 'id');
CALL stg_fix_column('media_posts', 'post_id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL', 'varchar(36)', 'NO');
CALL stg_ensure_column('media_posts', 'media_url', 'varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL', 'post_id');
CALL stg_fix_column('media_posts', 'media_url', 'varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL', 'varchar(255)', 'NO');
CALL stg_ensure_column('media_posts', 'media_public_id', 'varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'media_url');
CALL stg_fix_column('media_posts', 'media_public_id', 'varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'varchar(150)', 'YES');
CALL stg_ensure_column('media_posts', 'created_at', 'timestamp NULL DEFAULT CURRENT_TIMESTAMP', 'media_public_id');
CALL stg_fix_column('media_posts', 'created_at', 'timestamp NULL DEFAULT CURRENT_TIMESTAMP', 'timestamp', 'YES');
CALL stg_ensure_column('notifications', 'id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid())', '');
CALL stg_fix_column('notifications', 'id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid())', 'varchar(36)', 'NO');
CALL stg_ensure_column('notifications', 'user_id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL', 'id');
CALL stg_fix_column('notifications', 'user_id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL', 'varchar(36)', 'NO');
CALL stg_ensure_column('notifications', 'actor_id', 'varchar(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'user_id');
CALL stg_fix_column('notifications', 'actor_id', 'varchar(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'varchar(36)', 'YES');
CALL stg_ensure_column('notifications', 'type', 'varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL', 'actor_id');
CALL stg_fix_column('notifications', 'type', 'varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL', 'varchar(50)', 'NO');
CALL stg_ensure_column('notifications', 'title', 'varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL', 'type');
CALL stg_fix_column('notifications', 'title', 'varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL', 'varchar(255)', 'NO');
CALL stg_ensure_column('notifications', 'target_type', 'varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'title');
CALL stg_fix_column('notifications', 'target_type', 'varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'varchar(50)', 'YES');
CALL stg_ensure_column('notifications', 'target_id', 'varchar(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'target_type');
CALL stg_fix_column('notifications', 'target_id', 'varchar(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'varchar(36)', 'YES');
CALL stg_ensure_column('notifications', 'is_read', 'tinyint(1) NOT NULL DEFAULT ''0''', 'target_id');
CALL stg_fix_column('notifications', 'is_read', 'tinyint(1) NOT NULL DEFAULT ''0''', 'tinyint(1)', 'NO');
CALL stg_ensure_column('notifications', 'created_at', 'timestamp NULL DEFAULT CURRENT_TIMESTAMP', 'is_read');
CALL stg_fix_column('notifications', 'created_at', 'timestamp NULL DEFAULT CURRENT_TIMESTAMP', 'timestamp', 'YES');
CALL stg_ensure_column('post_views', 'id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid())', '');
CALL stg_fix_column('post_views', 'id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid())', 'varchar(36)', 'NO');
CALL stg_ensure_column('post_views', 'post_id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL', 'id');
CALL stg_fix_column('post_views', 'post_id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL', 'varchar(36)', 'NO');
CALL stg_ensure_column('post_views', 'user_id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL', 'post_id');
CALL stg_fix_column('post_views', 'user_id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL', 'varchar(36)', 'NO');
CALL stg_ensure_column('post_views', 'viewed_at', 'timestamp NULL DEFAULT CURRENT_TIMESTAMP', 'user_id');
CALL stg_fix_column('post_views', 'viewed_at', 'timestamp NULL DEFAULT CURRENT_TIMESTAMP', 'timestamp', 'YES');
CALL stg_ensure_column('profile_social_medias', 'id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid())', '');
CALL stg_fix_column('profile_social_medias', 'id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid())', 'varchar(36)', 'NO');
CALL stg_ensure_column('profile_social_medias', 'user_id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL', 'id');
CALL stg_fix_column('profile_social_medias', 'user_id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL', 'varchar(36)', 'NO');
CALL stg_ensure_column('profile_social_medias', 'platform', 'varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL', 'user_id');
CALL stg_fix_column('profile_social_medias', 'platform', 'varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL', 'varchar(50)', 'NO');
CALL stg_ensure_column('profile_social_medias', 'url', 'varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL', 'platform');
CALL stg_fix_column('profile_social_medias', 'url', 'varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL', 'varchar(100)', 'NO');
CALL stg_ensure_column('profiles', 'id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid())', '');
CALL stg_fix_column('profiles', 'id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid())', 'varchar(36)', 'NO');
CALL stg_ensure_column('profiles', 'user_id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL', 'id');
CALL stg_fix_column('profiles', 'user_id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL', 'varchar(36)', 'NO');
CALL stg_ensure_column('profiles', 'full_name', 'varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL', 'user_id');
CALL stg_fix_column('profiles', 'full_name', 'varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL', 'varchar(100)', 'NO');
CALL stg_ensure_column('profiles', 'bio', 'varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'full_name');
CALL stg_fix_column('profiles', 'bio', 'varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'varchar(150)', 'YES');
CALL stg_ensure_column('profiles', 'photo_profile_url', 'varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'bio');
CALL stg_fix_column('profiles', 'photo_profile_url', 'varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'varchar(150)', 'YES');
CALL stg_ensure_column('profiles', 'photo_profile_public_id', 'varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'photo_profile_url');
CALL stg_fix_column('profiles', 'photo_profile_public_id', 'varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'varchar(100)', 'YES');
CALL stg_ensure_column('profiles', 'created_at', 'datetime NOT NULL DEFAULT CURRENT_TIMESTAMP', 'photo_profile_public_id');
CALL stg_fix_column('profiles', 'created_at', 'datetime NOT NULL DEFAULT CURRENT_TIMESTAMP', 'datetime', 'NO');
CALL stg_ensure_column('public_posts', 'id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid())', '');
CALL stg_fix_column('public_posts', 'id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid())', 'varchar(36)', 'NO');
CALL stg_ensure_column('public_posts', 'user_id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL', 'id');
CALL stg_fix_column('public_posts', 'user_id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL', 'varchar(36)', 'NO');
CALL stg_ensure_column('public_posts', 'description', 'text COLLATE utf8mb4_unicode_ci NOT NULL', 'user_id');
CALL stg_fix_column('public_posts', 'description', 'text COLLATE utf8mb4_unicode_ci NOT NULL', 'text', 'NO');
CALL stg_ensure_column('public_posts', 'portfolio_type', 'enum(''link'',''images'') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''link''', 'description');
CALL stg_fix_column('public_posts', 'portfolio_type', 'enum(''link'',''images'') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''link''', 'enum(''link'',''images'')', 'NO');
CALL stg_ensure_column('public_posts', 'external_url', 'varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'portfolio_type');
CALL stg_fix_column('public_posts', 'external_url', 'varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'varchar(255)', 'YES');
CALL stg_ensure_column('public_posts', 'view_count', 'int NOT NULL DEFAULT ''0''', 'external_url');
CALL stg_fix_column('public_posts', 'view_count', 'int NOT NULL DEFAULT ''0''', 'int', 'NO');
CALL stg_ensure_column('public_posts', 'created_at', 'timestamp NULL DEFAULT CURRENT_TIMESTAMP', 'view_count');
CALL stg_fix_column('public_posts', 'created_at', 'timestamp NULL DEFAULT CURRENT_TIMESTAMP', 'timestamp', 'YES');
CALL stg_ensure_column('public_posts', 'updated_at', 'timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'created_at');
CALL stg_fix_column('public_posts', 'updated_at', 'timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'timestamp', 'YES');
CALL stg_ensure_column('public_posts', 'deleted_at', 'timestamp NULL DEFAULT NULL', 'updated_at');
CALL stg_fix_column('public_posts', 'deleted_at', 'timestamp NULL DEFAULT NULL', 'timestamp', 'YES');
CALL stg_ensure_column('skills', 'id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid())', '');
CALL stg_fix_column('skills', 'id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid())', 'varchar(36)', 'NO');
CALL stg_ensure_column('skills', 'skill_name', 'varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL', 'id');
CALL stg_fix_column('skills', 'skill_name', 'varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL', 'varchar(100)', 'NO');
CALL stg_ensure_column('skills', 'scope', 'enum(''system'',''private'') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''system''', 'skill_name');
CALL stg_fix_column('skills', 'scope', 'enum(''system'',''private'') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''system''', 'enum(''system'',''private'')', 'NO');
CALL stg_ensure_column('skills', 'created_by', 'varchar(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'scope');
CALL stg_fix_column('skills', 'created_by', 'varchar(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'varchar(36)', 'YES');
CALL stg_ensure_column('upload_grants', 'id', 'bigint unsigned NOT NULL AUTO_INCREMENT', '');
CALL stg_fix_column('upload_grants', 'id', 'bigint unsigned NOT NULL AUTO_INCREMENT', 'bigint unsigned', 'NO');
CALL stg_ensure_column('upload_grants', 'user_id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL', 'id');
CALL stg_fix_column('upload_grants', 'user_id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL', 'varchar(36)', 'NO');
CALL stg_ensure_column('upload_grants', 'created_at', 'datetime NOT NULL DEFAULT CURRENT_TIMESTAMP', 'user_id');
CALL stg_fix_column('upload_grants', 'created_at', 'datetime NOT NULL DEFAULT CURRENT_TIMESTAMP', 'datetime', 'NO');
CALL stg_ensure_column('user_collaboration', 'id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid())', '');
CALL stg_fix_column('user_collaboration', 'id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid())', 'varchar(36)', 'NO');
CALL stg_ensure_column('user_collaboration', 'user_id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL', 'id');
CALL stg_fix_column('user_collaboration', 'user_id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL', 'varchar(36)', 'NO');
CALL stg_ensure_column('user_collaboration', 'collaboration_id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL', 'user_id');
CALL stg_fix_column('user_collaboration', 'collaboration_id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL', 'varchar(36)', 'NO');
CALL stg_ensure_column('user_collaboration', 'status', 'enum(''not-started'',''pending'',''complete'',''revise'') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''not-started''', 'collaboration_id');
CALL stg_fix_column('user_collaboration', 'status', 'enum(''not-started'',''pending'',''complete'',''revise'') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''not-started''', 'enum(''not-started'',''pending'',''complete'',''revise'')', 'NO');
CALL stg_ensure_column('user_collaboration', 'created_at', 'timestamp NULL DEFAULT CURRENT_TIMESTAMP', 'status');
CALL stg_fix_column('user_collaboration', 'created_at', 'timestamp NULL DEFAULT CURRENT_TIMESTAMP', 'timestamp', 'YES');
CALL stg_ensure_column('user_skill', 'id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid())', '');
CALL stg_fix_column('user_skill', 'id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid())', 'varchar(36)', 'NO');
CALL stg_ensure_column('user_skill', 'skill_id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL', 'id');
CALL stg_fix_column('user_skill', 'skill_id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL', 'varchar(36)', 'NO');
CALL stg_ensure_column('user_skill', 'user_id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL', 'skill_id');
CALL stg_fix_column('user_skill', 'user_id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL', 'varchar(36)', 'NO');
CALL stg_ensure_column('users', 'id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid())', '');
CALL stg_fix_column('users', 'id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid())', 'varchar(36)', 'NO');
CALL stg_ensure_column('users', 'username', 'varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL', 'id');
CALL stg_fix_column('users', 'username', 'varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL', 'varchar(100)', 'NO');
CALL stg_ensure_column('users', 'password', 'varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL', 'username');
CALL stg_fix_column('users', 'password', 'varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL', 'varchar(150)', 'NO');
CALL stg_ensure_column('users', 'email', 'varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL', 'password');
CALL stg_fix_column('users', 'email', 'varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL', 'varchar(100)', 'NO');
CALL stg_ensure_column('users', 'google_id', 'varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'email');
CALL stg_fix_column('users', 'google_id', 'varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'varchar(255)', 'YES');
CALL stg_ensure_column('users', 'is_active', 'tinyint(1) NOT NULL DEFAULT ''1''', 'google_id');
CALL stg_fix_column('users', 'is_active', 'tinyint(1) NOT NULL DEFAULT ''1''', 'tinyint(1)', 'NO');
CALL stg_ensure_column('users', 'created_at', 'datetime NOT NULL DEFAULT CURRENT_TIMESTAMP', 'is_active');
CALL stg_fix_column('users', 'created_at', 'datetime NOT NULL DEFAULT CURRENT_TIMESTAMP', 'datetime', 'NO');
CALL stg_ensure_column('zone', 'id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid())', '');
CALL stg_fix_column('zone', 'id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid())', 'varchar(36)', 'NO');
CALL stg_ensure_column('zone', 'circle_id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL', 'id');
CALL stg_fix_column('zone', 'circle_id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL', 'varchar(36)', 'NO');
CALL stg_ensure_column('zone', 'title', 'varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL', 'circle_id');
CALL stg_fix_column('zone', 'title', 'varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL', 'varchar(150)', 'NO');
CALL stg_ensure_column('zone', 'allow_public_message', 'tinyint(1) NOT NULL DEFAULT ''0''', 'title');
CALL stg_fix_column('zone', 'allow_public_message', 'tinyint(1) NOT NULL DEFAULT ''0''', 'tinyint(1)', 'NO');
CALL stg_ensure_column('zone', 'timestamp', 'datetime NOT NULL DEFAULT CURRENT_TIMESTAMP', 'allow_public_message');
CALL stg_fix_column('zone', 'timestamp', 'datetime NOT NULL DEFAULT CURRENT_TIMESTAMP', 'datetime', 'NO');
CALL stg_ensure_column('zone_messages', 'id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid())', '');
CALL stg_fix_column('zone_messages', 'id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid())', 'varchar(36)', 'NO');
CALL stg_ensure_column('zone_messages', 'zone_id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL', 'id');
CALL stg_fix_column('zone_messages', 'zone_id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL', 'varchar(36)', 'NO');
CALL stg_ensure_column('zone_messages', 'user_id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL', 'zone_id');
CALL stg_fix_column('zone_messages', 'user_id', 'varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL', 'varchar(36)', 'NO');
CALL stg_ensure_column('zone_messages', 'message', 'text COLLATE utf8mb4_unicode_ci NOT NULL', 'user_id');
CALL stg_fix_column('zone_messages', 'message', 'text COLLATE utf8mb4_unicode_ci NOT NULL', 'text', 'NO');
CALL stg_ensure_column('zone_messages', 'timestamp', 'datetime NOT NULL DEFAULT CURRENT_TIMESTAMP', 'message');
CALL stg_fix_column('zone_messages', 'timestamp', 'datetime NOT NULL DEFAULT CURRENT_TIMESTAMP', 'datetime', 'NO');

-- 2c. Index non-PK yang hilang ditambahkan.
CALL stg_ensure_index('circle', 'idx_circle_collaboration_id', '`collaboration_id`', 0);
CALL stg_ensure_index('circle', 'uq_circle_collaboration_id', '`collaboration_id`', 1);
CALL stg_ensure_index('collab_media', 'idx_collab_media_collab_id', '`collab_id`', 0);
CALL stg_ensure_index('collab_skills', 'idx_collab_skills_collab_id', '`collab_id`', 0);
CALL stg_ensure_index('collab_skills', 'idx_collab_skills_skill_id', '`skill_id`', 0);
CALL stg_ensure_index('collab_skills', 'uq_collab_skills', '`collab_id`, `skill_id`', 1);
CALL stg_ensure_index('collaboration_applications', 'idx_collab_app_collab_id', '`collab_id`', 0);
CALL stg_ensure_index('collaboration_applications', 'idx_collab_app_status', '`status`', 0);
CALL stg_ensure_index('collaboration_applications', 'idx_collab_app_user_id', '`user_id`', 0);
CALL stg_ensure_index('collaboration_applications', 'uq_collab_application', '`user_id`, `collab_id`', 1);
CALL stg_ensure_index('collaborations', 'idx_collaborations_created_at', '`created_at`', 0);
CALL stg_ensure_index('collaborations', 'idx_collaborations_status', '`status`', 0);
CALL stg_ensure_index('collaborations', 'idx_collaborations_user_id', '`user_id`', 0);
CALL stg_ensure_index('comment_likes', 'idx_comment_likes_comment_id', '`comment_id`', 0);
CALL stg_ensure_index('comment_likes', 'idx_comment_likes_user_id', '`user_id`', 0);
CALL stg_ensure_index('comment_likes', 'uq_user_comment_like', '`user_id`, `comment_id`', 1);
CALL stg_ensure_index('comments', 'idx_comments_post_id', '`post_id`', 0);
CALL stg_ensure_index('comments', 'idx_comments_user_id', '`user_id`', 0);
CALL stg_ensure_index('dm_conversations', 'uq_dm_conversations_application', '`application_id`', 1);
CALL stg_ensure_index('dm_messages', 'idx_dm_messages_conversation_id', '`conversation_id`', 0);
CALL stg_ensure_index('dm_messages', 'idx_dm_messages_created_at', '`created_at`', 0);
CALL stg_ensure_index('dm_messages', 'idx_dm_messages_sender_id', '`sender_id`', 0);
CALL stg_ensure_index('dm_participants', 'idx_dm_participants_conversation_id', '`conversation_id`', 0);
CALL stg_ensure_index('dm_participants', 'idx_dm_participants_user_id', '`user_id`', 0);
CALL stg_ensure_index('dm_participants', 'uq_dm_participant', '`conversation_id`, `user_id`', 1);
CALL stg_ensure_index('like_posts', 'idx_like_posts_post_id', '`post_id`', 0);
CALL stg_ensure_index('like_posts', 'idx_like_posts_user_id', '`user_id`', 0);
CALL stg_ensure_index('like_posts', 'uq_user_post_like', '`user_id`, `post_id`', 1);
CALL stg_ensure_index('media_posts', 'idx_media_posts_post_id', '`post_id`', 0);
CALL stg_ensure_index('notifications', 'fk_notifications_actor', '`actor_id`', 0);
CALL stg_ensure_index('notifications', 'idx_notifications_created_at', '`created_at`', 0);
CALL stg_ensure_index('notifications', 'idx_notifications_is_read', '`is_read`', 0);
CALL stg_ensure_index('notifications', 'idx_notifications_user_id', '`user_id`', 0);
CALL stg_ensure_index('post_views', 'idx_post_views_post_id', '`post_id`', 0);
CALL stg_ensure_index('post_views', 'idx_post_views_user_id', '`user_id`', 0);
CALL stg_ensure_index('post_views', 'uq_user_post_view', '`user_id`, `post_id`', 1);
CALL stg_ensure_index('profile_social_medias', 'idx_social_media_user', '`user_id`', 0);
CALL stg_ensure_index('profiles', 'uq_profiles_user_id', '`user_id`', 1);
CALL stg_ensure_index('public_posts', 'idx_public_posts_user_id', '`user_id`', 0);
CALL stg_ensure_index('skills', 'idx_skills_created_by', '`created_by`', 0);
CALL stg_ensure_index('skills', 'idx_skills_name', '`skill_name`', 0);
CALL stg_ensure_index('skills', 'idx_skills_scope', '`scope`', 0);
CALL stg_ensure_index('upload_grants', 'idx_upload_grants_user_time', '`user_id`, `created_at`', 0);
CALL stg_ensure_index('user_collaboration', 'idx_user_collab_collab_id', '`collaboration_id`', 0);
CALL stg_ensure_index('user_collaboration', 'idx_user_collab_user_id', '`user_id`', 0);
CALL stg_ensure_index('user_collaboration', 'uq_user_collaboration', '`user_id`, `collaboration_id`', 1);
CALL stg_ensure_index('user_skill', 'idx_user_skill_skill_id', '`skill_id`', 0);
CALL stg_ensure_index('user_skill', 'idx_user_skill_user_id', '`user_id`', 0);
CALL stg_ensure_index('user_skill', 'uq_user_skill', '`user_id`, `skill_id`', 1);
CALL stg_ensure_index('users', 'email', '`email`', 1);
CALL stg_ensure_index('users', 'google_id', '`google_id`', 1);
CALL stg_ensure_index('users', 'uq_users_email', '`email`', 1);
CALL stg_ensure_index('users', 'uq_users_username', '`username`', 1);
CALL stg_ensure_index('zone', 'idx_zone_circle_id', '`circle_id`', 0);
CALL stg_ensure_index('zone_messages', 'idx_zone_messages_timestamp', '`timestamp`', 0);
CALL stg_ensure_index('zone_messages', 'idx_zone_messages_user_id', '`user_id`', 0);
CALL stg_ensure_index('zone_messages', 'idx_zone_messages_zone_id', '`zone_id`', 0);
SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- BAGIAN 3 — VALIDASI ULANG (semua baris acuan harus OK)
-- ============================================================
SELECT e.table_name, e.column_name, e.column_type AS expected_type, c.COLUMN_TYPE AS actual_type,
  CASE WHEN c.COLUMN_NAME IS NULL THEN 'MISSING'
       WHEN LOWER(c.COLUMN_TYPE) <> LOWER(e.column_type) OR c.IS_NULLABLE <> e.is_nullable THEN 'MISMATCH'
       ELSE 'OK' END AS status
FROM stg_expected_columns e
LEFT JOIN INFORMATION_SCHEMA.COLUMNS c
  ON c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME = e.table_name AND c.COLUMN_NAME = e.column_name
UNION ALL
SELECT c.TABLE_NAME, c.COLUMN_NAME, NULL, c.COLUMN_TYPE, 'EXTRA_IN_DB'
FROM INFORMATION_SCHEMA.COLUMNS c
LEFT JOIN stg_expected_columns e ON e.table_name = c.TABLE_NAME AND e.column_name = c.COLUMN_NAME
WHERE c.TABLE_SCHEMA = DATABASE() AND e.column_name IS NULL
ORDER BY 1, 2;

DROP PROCEDURE IF EXISTS stg_ensure_column;
DROP PROCEDURE IF EXISTS stg_fix_column;
DROP PROCEDURE IF EXISTS stg_ensure_index;
DROP TABLE IF EXISTS stg_expected_columns;
-- Selesai.
