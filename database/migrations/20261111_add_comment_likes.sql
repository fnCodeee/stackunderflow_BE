-- Migration: add comment_likes table (2026-11-11)
-- One row per (user, comment) like — modelled exactly on like_posts
-- (UNIQUE(user_id, comment_id), FKs CASCADE). A comment like is
-- persisted and counted, visible to other users, like a post like.
-- Applies to: db_stack_underflow
CREATE TABLE IF NOT EXISTS comment_likes (
    id VARCHAR(36) NOT NULL DEFAULT (UUID()),
    comment_id VARCHAR(36) NOT NULL,
    user_id VARCHAR(36) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_user_comment_like (user_id, comment_id),
    KEY idx_comment_likes_comment_id (comment_id),
    KEY idx_comment_likes_user_id (user_id),

    CONSTRAINT fk_comment_likes_comment
        FOREIGN KEY (comment_id) REFERENCES comments (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_comment_likes_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
