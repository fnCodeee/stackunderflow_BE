-- Planner wiring (board UI ke API) — 2026-11-26.
--
-- Task dapat rentang tanggal (start_date .. due_date; due_date tetap
-- ujungnya / deadline), ikon dari detail picker, isi BlockBody
-- (blocks_json), dan status tentatif (pencilled). Job role per anggota
-- planner disimpan di collab_planner_job_roles (bukan role planner
-- 'pm' — itu tetap di collab_planner_roles).

ALTER TABLE `collab_tasks`
  ADD COLUMN `start_date` date DEFAULT NULL AFTER `due_date`,
  ADD COLUMN `icon` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `start_date`,
  ADD COLUMN `blocks_json` json DEFAULT NULL AFTER `icon`,
  ADD COLUMN `pencilled` tinyint(1) NOT NULL DEFAULT 0 AFTER `blocks_json`;

CREATE TABLE IF NOT EXISTS `collab_planner_job_roles` (
  `collab_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `job_role` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `timestamp` datetime NOT NULL,
  PRIMARY KEY (`collab_id`, `user_id`),
  KEY `idx_planner_job_roles_user` (`user_id`),
  CONSTRAINT `fk_planner_job_roles_collab` FOREIGN KEY (`collab_id`) REFERENCES `collaborations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_planner_job_roles_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
