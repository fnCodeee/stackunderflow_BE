-- =============================================================================
-- Migration: 20261019_create_circle_table.sql
-- Description: Phase 4C Auto-create Circle & Adjust Collaborations Schema
-- Target: MySQL 8.0+
-- =============================================================================

-- 1. Modify collaborations.communication_url to be NULLable (no longer used/required)
ALTER TABLE collaborations MODIFY COLUMN communication_url VARCHAR(255) NULL DEFAULT NULL;

-- 2. Create table: circle
CREATE TABLE IF NOT EXISTS circle (
    id VARCHAR(36) NOT NULL DEFAULT (UUID()),
    collaboration_id VARCHAR(36) NOT NULL,
    title VARCHAR(255) NOT NULL,
    photo_profile_url VARCHAR(255) NULL DEFAULT NULL,
    timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    UNIQUE KEY uq_circle_collaboration_id (collaboration_id),
    KEY idx_circle_collaboration_id (collaboration_id),

    CONSTRAINT fk_circle_collaboration
        FOREIGN KEY (collaboration_id)
        REFERENCES collaborations(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
