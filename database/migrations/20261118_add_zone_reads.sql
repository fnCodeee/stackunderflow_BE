-- Zone read state for Discord-style unread indicators (Circle).
-- One row per (user, zone): messages from others newer than
-- last_read_at count as unread. Applied 2026-11-18.
CREATE TABLE IF NOT EXISTS `zone_reads` (
  `user_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `zone_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_read_at` datetime DEFAULT NULL,
  PRIMARY KEY (`user_id`, `zone_id`),
  KEY `idx_zone_reads_zone_id` (`zone_id`),
  CONSTRAINT `fk_zone_reads_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_zone_reads_zone` FOREIGN KEY (`zone_id`) REFERENCES `zone` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
