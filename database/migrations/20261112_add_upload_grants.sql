-- =========================================================
-- Migration: tabel upload_grants
-- Tanggal: 2026-11-12
-- Tujuan: mencatat setiap tanda tangan upload langsung ke
-- Cloudinary yang diterbitkan POST /api/upload/signature.
-- Dipakai untuk (1) rate limit per user per jam dan
-- (2) audit siapa menerbitkan tanda tangan kapan.
-- =========================================================

CREATE TABLE IF NOT EXISTS upload_grants (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id VARCHAR(36) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_upload_grants_user_time (user_id, created_at),
    CONSTRAINT fk_upload_grants_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
