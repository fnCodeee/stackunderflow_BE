-- Message attachments in Circle zones (2026-11-19):
-- a zone message can carry up to 3 files stored on the separate
-- project Cloudinary account (folder assets/messageCircle).
-- ON DELETE CASCADE: attachments follow their message.
CREATE TABLE zone_message_attachments (
    id VARCHAR(36) NOT NULL,
    message_id VARCHAR(36) NOT NULL,
    url VARCHAR(1024) NOT NULL,
    public_id VARCHAR(255) NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    kind ENUM('image', 'document') NOT NULL,
    size_bytes INT UNSIGNED NOT NULL DEFAULT 0,
    timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_zma_message_id (message_id),
    CONSTRAINT fk_zma_message
        FOREIGN KEY (message_id) REFERENCES zone_messages(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
