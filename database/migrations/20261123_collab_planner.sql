-- Collab Planner (Tasks) Fase 1 — keputusan user 2026-11-23.
-- Planner menempel ke collaboration (satu kedudukan dengan Circle,
-- bukan di dalam Circle): owner yang menentukan planner dibuat atau
-- tidak, ditandai kolom planner_enabled di collaborations.
--
-- collab_tasks: task cuma punya tanggal (due_date), bukan jam.
-- Relasi follower: follows_task_id menunjuk task yang diikuti;
-- gap_days = jarak hari task ini dari task yang diikuti (diisi saat
-- relasi dibuat dan dihitung ulang saat task digeser manual).
-- Menggeser task menggeser semua follower-nya sejauh delta yang
-- sama, jadi jarak antar mereka tidak berubah.
--
-- collab_planner_roles: role tambahan di dalam planner. Satu-satunya
-- role di Fase 1 adalah 'pm' — anggota yang diangkat owner, boleh
-- menggeser task siapa pun dan membuat task untuk orang lain.
-- Owner tidak perlu baris di sini; dia pemilik collaboration-nya.

ALTER TABLE `collaborations`
  ADD COLUMN `planner_enabled` tinyint(1) NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS `collab_planner_sections` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `collab_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `position` int NOT NULL DEFAULT 0,
  `timestamp` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_planner_sections_collab` (`collab_id`),
  CONSTRAINT `fk_planner_sections_collab` FOREIGN KEY (`collab_id`) REFERENCES `collaborations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `collab_planner_roles` (
  `collab_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pm',
  `timestamp` datetime NOT NULL,
  PRIMARY KEY (`collab_id`, `user_id`),
  KEY `idx_planner_roles_user` (`user_id`),
  CONSTRAINT `fk_planner_roles_collab` FOREIGN KEY (`collab_id`) REFERENCES `collaborations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_planner_roles_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `collab_tasks` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `collab_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `section_id` varchar(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `title` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `assignee_id` varchar(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `due_date` date DEFAULT NULL,
  `follows_task_id` varchar(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gap_days` int DEFAULT NULL,
  `position` int NOT NULL DEFAULT 0,
  `done_at` datetime DEFAULT NULL,
  `done_by` varchar(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `timestamp` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_collab_tasks_collab` (`collab_id`),
  KEY `idx_collab_tasks_assignee` (`assignee_id`),
  KEY `idx_collab_tasks_follows` (`follows_task_id`),
  CONSTRAINT `fk_collab_tasks_collab` FOREIGN KEY (`collab_id`) REFERENCES `collaborations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_collab_tasks_section` FOREIGN KEY (`section_id`) REFERENCES `collab_planner_sections` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_collab_tasks_assignee` FOREIGN KEY (`assignee_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_collab_tasks_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_collab_tasks_follows` FOREIGN KEY (`follows_task_id`) REFERENCES `collab_tasks` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
