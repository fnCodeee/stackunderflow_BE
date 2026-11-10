-- =========================================================
-- Migration: add the tables/columns the frontend needs
-- Date: 2026-11-10
-- Adds: notifications, dm_conversations, dm_participants,
-- dm_messages; user_collaboration.status (myStatus);
-- collaborations.type + due_date + status values;
-- zone_messages.message TEXT.
-- Run: mysql -u root -p db_stack_underflow < this file
-- (after importing database/schema.test.sql, or against a
-- DB created from it).
-- =========================================================

USE db_stack_underflow;

-- =========================================================
-- 1. NOTIFICATIONS (bell + panel + /notifications page)
-- =========================================================

CREATE TABLE IF NOT EXISTS notifications (
    id VARCHAR(36) NOT NULL DEFAULT (UUID()),
    user_id VARCHAR(36) NOT NULL,
    actor_id VARCHAR(36) NULL,
    `type` VARCHAR(50) NOT NULL,
    title VARCHAR(255) NOT NULL,
    body TEXT NULL,
    target_type VARCHAR(50) NULL,
    target_id VARCHAR(36) NULL,
    is_read BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_notifications_user_id (user_id),
    KEY idx_notifications_is_read (is_read),
    KEY idx_notifications_created_at (created_at),

    CONSTRAINT fk_notifications_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_notifications_actor
        FOREIGN KEY (actor_id) REFERENCES users(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- 2. DIRECT MESSAGES (Inbox 1-on-1 threads)
-- =========================================================

CREATE TABLE IF NOT EXISTS dm_conversations (
    id VARCHAR(36) NOT NULL DEFAULT (UUID()),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dm_participants (
    id VARCHAR(36) NOT NULL DEFAULT (UUID()),
    conversation_id VARCHAR(36) NOT NULL,
    user_id VARCHAR(36) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_dm_participant (conversation_id, user_id),
    KEY idx_dm_participants_conversation_id (conversation_id),
    KEY idx_dm_participants_user_id (user_id),

    CONSTRAINT fk_dm_participants_conversation
        FOREIGN KEY (conversation_id) REFERENCES dm_conversations(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_dm_participants_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dm_messages (
    id VARCHAR(36) NOT NULL DEFAULT (UUID()),
    conversation_id VARCHAR(36) NOT NULL,
    sender_id VARCHAR(36) NOT NULL,
    body TEXT NOT NULL,
    is_read BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_dm_messages_conversation_id (conversation_id),
    KEY idx_dm_messages_sender_id (sender_id),
    KEY idx_dm_messages_created_at (created_at),

    CONSTRAINT fk_dm_messages_conversation
        FOREIGN KEY (conversation_id) REFERENCES dm_conversations(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_dm_messages_sender
        FOREIGN KEY (sender_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- 3. MY COLLABORATIONS STATUS (per-user per-collab progress)
-- =========================================================

ALTER TABLE user_collaboration
    ADD COLUMN status ENUM('not-started', 'pending', 'complete', 'revise')
        NOT NULL DEFAULT 'not-started' AFTER collaboration_id;

-- =========================================================
-- 4. COLLABORATIONS: type + due date + status values
--    matching the frontend (open / in-progress / completed)
-- =========================================================

ALTER TABLE collaborations
    ADD COLUMN `type` ENUM('coursework', 'portfolio', 'product')
        NOT NULL DEFAULT 'coursework' AFTER description;

ALTER TABLE collaborations
    ADD COLUMN due_date DATE NULL AFTER required_member;

ALTER TABLE collaborations
    MODIFY COLUMN status ENUM('open', 'in-progress', 'completed')
        NOT NULL DEFAULT 'open';

-- =========================================================
-- 5. CIRCLE MESSAGES: allow longer text
-- =========================================================

ALTER TABLE zone_messages MODIFY COLUMN message TEXT NOT NULL;
