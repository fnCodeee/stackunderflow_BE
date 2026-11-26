-- 20261113_admin_panel.sql
-- ─────────────────────────────────────────────────────────────────────────────
-- ADMIN PANEL — FASE 0 FONDASI (spec: spec-admin.md, dikunci 2026-11-13)
--
-- Isi:
--  1. users: role (user/monitor/super_admin) + status suspend + metadata
--  2. public_posts / comments / collaborations: status moderasi reversible
--  3. dm_conversations: kind (direct/application/system) + backfill
--  4. Tabel baru: reports, action_requests, moderation_actions, appeals
--  5. Akun sistem "underflow-admin" (pengirim pemberitahuan moderasi)
--  6. Seed role awal: rakaalfarezi1 = super_admin (akun lain menyusul
--     setelah username tim dikonfirmasi)
-- ─────────────────────────────────────────────────────────────────────────────

-- 1. users ────────────────────────────────────────────────────────────────────
ALTER TABLE users
  ADD COLUMN role ENUM('user', 'monitor', 'super_admin') NOT NULL DEFAULT 'user' AFTER is_active,
  ADD COLUMN account_status ENUM('active', 'suspended') NOT NULL DEFAULT 'active' AFTER role,
  ADD COLUMN suspended_reason TEXT NULL AFTER account_status,
  ADD COLUMN suspended_at DATETIME NULL AFTER suspended_reason,
  ADD COLUMN suspended_by VARCHAR(36) NULL AFTER suspended_at;

-- 2. Status moderasi konten (reversible, terpisah dari deleted_at) ───────────
ALTER TABLE public_posts
  ADD COLUMN moderation_state ENUM('visible', 'removed') NOT NULL DEFAULT 'visible',
  ADD COLUMN removed_by VARCHAR(36) NULL,
  ADD COLUMN removed_at TIMESTAMP NULL,
  ADD COLUMN removal_reason TEXT NULL;

ALTER TABLE comments
  ADD COLUMN moderation_state ENUM('visible', 'removed') NOT NULL DEFAULT 'visible',
  ADD COLUMN removed_by VARCHAR(36) NULL,
  ADD COLUMN removed_at TIMESTAMP NULL,
  ADD COLUMN removal_reason TEXT NULL;

ALTER TABLE collaborations
  ADD COLUMN moderation_state ENUM('visible', 'removed') NOT NULL DEFAULT 'visible',
  ADD COLUMN removed_by VARCHAR(36) NULL,
  ADD COLUMN removed_at TIMESTAMP NULL,
  ADD COLUMN removal_reason TEXT NULL;

-- 3. Jenis thread inbox ───────────────────────────────────────────────────────
ALTER TABLE dm_conversations
  ADD COLUMN kind ENUM('direct', 'application', 'system') NOT NULL DEFAULT 'direct' AFTER application_id;

UPDATE dm_conversations SET kind = 'application' WHERE application_id IS NOT NULL;

-- 4. Tabel baru ───────────────────────────────────────────────────────────────
CREATE TABLE reports (
  id VARCHAR(36) NOT NULL PRIMARY KEY,
  reporter_id VARCHAR(36) NOT NULL,
  target_type ENUM('post', 'comment', 'collab', 'user') NOT NULL,
  target_id VARCHAR(36) NOT NULL,
  reason TEXT NOT NULL,
  status ENUM('open', 'actioned', 'dismissed') NOT NULL DEFAULT 'open',
  resolved_by VARCHAR(36) NULL,
  resolved_at TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_reports_target (target_type, target_id, status),
  INDEX idx_reports_status (status, created_at),
  CONSTRAINT fk_reports_reporter FOREIGN KEY (reporter_id) REFERENCES users (id),
  CONSTRAINT fk_reports_resolver FOREIGN KEY (resolved_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE action_requests (
  id VARCHAR(36) NOT NULL PRIMARY KEY,
  requester_id VARCHAR(36) NOT NULL,
  action_type ENUM('remove_post', 'remove_comment', 'remove_collab', 'suspend_user', 'dismiss_report') NOT NULL,
  target_type ENUM('post', 'comment', 'collab', 'user', 'report') NOT NULL,
  target_id VARCHAR(36) NOT NULL,
  reason TEXT NOT NULL,
  with_strike TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
  decided_by VARCHAR(36) NULL,
  decided_at TIMESTAMP NULL,
  decision_note TEXT NULL,
  executed_at TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_action_requests_status (status, created_at),
  INDEX idx_action_requests_requester (requester_id, status),
  CONSTRAINT fk_action_requests_requester FOREIGN KEY (requester_id) REFERENCES users (id),
  CONSTRAINT fk_action_requests_decider FOREIGN KEY (decided_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE moderation_actions (
  id VARCHAR(36) NOT NULL PRIMARY KEY,
  action_type ENUM('remove_post', 'remove_comment', 'remove_collab', 'suspend_user', 'dismiss_report', 'restore_content', 'unsuspend_user', 'grant_appeal', 'deny_appeal') NOT NULL,
  target_type ENUM('post', 'comment', 'collab', 'user', 'report') NOT NULL,
  target_id VARCHAR(36) NOT NULL,
  target_owner_id VARCHAR(36) NULL,
  actor_id VARCHAR(36) NOT NULL,
  requested_by VARCHAR(36) NULL,
  request_id VARCHAR(36) NULL,
  reason TEXT NULL,
  strike TINYINT(1) NOT NULL DEFAULT 0,
  reverted_at TIMESTAMP NULL,
  reverted_by VARCHAR(36) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_mod_actions_owner (target_owner_id, strike, reverted_at),
  INDEX idx_mod_actions_target (target_type, target_id),
  CONSTRAINT fk_mod_actions_actor FOREIGN KEY (actor_id) REFERENCES users (id),
  CONSTRAINT fk_mod_actions_owner FOREIGN KEY (target_owner_id) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_mod_actions_request FOREIGN KEY (request_id) REFERENCES action_requests (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE appeals (
  id VARCHAR(36) NOT NULL PRIMARY KEY,
  user_id VARCHAR(36) NOT NULL,
  moderation_action_id VARCHAR(36) NOT NULL,
  message TEXT NOT NULL,
  status ENUM('pending', 'granted', 'denied') NOT NULL DEFAULT 'pending',
  decided_by VARCHAR(36) NULL,
  decided_at TIMESTAMP NULL,
  decision_note TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_appeal_per_action (moderation_action_id),
  INDEX idx_appeals_status (status, created_at),
  CONSTRAINT fk_appeals_user FOREIGN KEY (user_id) REFERENCES users (id),
  CONSTRAINT fk_appeals_action FOREIGN KEY (moderation_action_id) REFERENCES moderation_actions (id),
  CONSTRAINT fk_appeals_decider FOREIGN KEY (decided_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- 5. Akun sistem ──────────────────────────────────────────────────────────────
-- Password = hash dari string acak yang dibuang (digenerate saat
-- migration dijalankan, token $2y$10$Gu6ILM/G11BR8zrUaCTnGOeYNfR9i0gs7d5rl.XwD.I4W5hG2nMH2 diganti oleh runner):
-- akun ini tidak untuk login manusia (pengirim pesan sistem oleh server).
INSERT INTO users (id, username, password, email, created_at)
SELECT
  '00000000-0000-4000-8000-0000000000ad',
  'underflow-admin',
  '$2y$10$Gu6ILM/G11BR8zrUaCTnGOeYNfR9i0gs7d5rl.XwD.I4W5hG2nMH2',
  'admin@underflow.app',
  NOW()
WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'underflow-admin');

-- 6. Seed role awal ───────────────────────────────────────────────────────────
UPDATE users SET role = 'super_admin' WHERE username = 'rakaalfarezi1';
