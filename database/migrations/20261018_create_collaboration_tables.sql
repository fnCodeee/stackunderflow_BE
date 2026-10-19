-- =============================================================================
-- Migration: 20261018_create_collaboration_tables.sql
-- Description: Phase 4B Collaboration & Applications Module Schema Migration
-- Author: Backend Migration Team (Express to PHP Native)
-- Target: MySQL 8.0+
-- =============================================================================

-- 1. Table: collaborations
-- Data utama postingan kolaborasi / proyek
CREATE TABLE IF NOT EXISTS collaborations (
    id VARCHAR(36) NOT NULL DEFAULT (UUID()),
    user_id VARCHAR(36) NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    required_member INT NOT NULL DEFAULT 1,
    communication_url VARCHAR(255) NOT NULL,
    status ENUM('open', 'closed') NOT NULL DEFAULT 'open',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    KEY idx_collaborations_user_id (user_id),
    KEY idx_collaborations_status (status),
    KEY idx_collaborations_created_at (created_at),

    CONSTRAINT fk_collaborations_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- 2. Table: collab_media
-- Media lampiran untuk collaboration (mediaUrls & mediaPublicIds 1-to-many)
CREATE TABLE IF NOT EXISTS collab_media (
    id VARCHAR(36) NOT NULL DEFAULT (UUID()),
    collab_id VARCHAR(36) NOT NULL,
    media_url VARCHAR(255) NOT NULL,
    media_public_id VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    KEY idx_collab_media_collab_id (collab_id),

    CONSTRAINT fk_collab_media_collab
        FOREIGN KEY (collab_id)
        REFERENCES collaborations(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- 3. Table: collab_skills
-- Pivot table relasi many-to-many: collaborations <-> skills
CREATE TABLE IF NOT EXISTS collab_skills (
    id VARCHAR(36) NOT NULL DEFAULT (UUID()),
    collab_id VARCHAR(36) NOT NULL,
    skill_id VARCHAR(36) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    UNIQUE KEY uq_collab_skills (collab_id, skill_id),

    KEY idx_collab_skills_collab_id (collab_id),
    KEY idx_collab_skills_skill_id (skill_id),

    CONSTRAINT fk_collab_skills_collab
        FOREIGN KEY (collab_id)
        REFERENCES collaborations(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT fk_collab_skills_skill
        FOREIGN KEY (skill_id)
        REFERENCES skills(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- 4. Table: collaboration_applications
-- Pengajuan user untuk bergabung ke kolaborasi
CREATE TABLE IF NOT EXISTS collaboration_applications (
    id VARCHAR(36) NOT NULL DEFAULT (UUID()),
    user_id VARCHAR(36) NOT NULL,
    collab_id VARCHAR(36) NOT NULL,
    message TEXT NULL,
    status ENUM('pending', 'accepted', 'rejected') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    UNIQUE KEY uq_collab_application (user_id, collab_id),

    KEY idx_collab_app_user_id (user_id),
    KEY idx_collab_app_collab_id (collab_id),
    KEY idx_collab_app_status (status),

    CONSTRAINT fk_collab_app_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT fk_collab_app_collab
        FOREIGN KEY (collab_id)
        REFERENCES collaborations(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- 5. Table: user_collaboration
-- Relasi anggota tim yang diterima ke collaboration untuk tracking project channel
CREATE TABLE IF NOT EXISTS user_collaboration (
    id VARCHAR(36) NOT NULL DEFAULT (UUID()),
    user_id VARCHAR(36) NOT NULL,
    collaboration_id VARCHAR(36) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    UNIQUE KEY uq_user_collaboration (user_id, collaboration_id),

    KEY idx_user_collab_user_id (user_id),
    KEY idx_user_collab_collab_id (collaboration_id),

    CONSTRAINT fk_user_collab_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT fk_user_collab_collab
        FOREIGN KEY (collaboration_id)
        REFERENCES collaborations(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
