-- AI Moderator (spec: spec-ai-moderator.md, 2026-11-17).
-- Queue + verdict audit + image verdict cache, plus the AI system
-- actor user (moderation_actions.actor_id references users.id).

CREATE TABLE IF NOT EXISTS ai_moderation_jobs (
  id VARCHAR(36) NOT NULL,
  target_type ENUM('post','comment','collab') NOT NULL,
  target_id VARCHAR(36) NOT NULL,
  trigger_source ENUM('new_content','edit','report','manual') NOT NULL DEFAULT 'new_content',
  report_id VARCHAR(36) DEFAULT NULL,
  priority TINYINT NOT NULL DEFAULT 0,
  status ENUM('pending','processing','done','failed') NOT NULL DEFAULT 'pending',
  attempts INT NOT NULL DEFAULT 0,
  next_attempt_at DATETIME DEFAULT NULL,
  last_error TEXT DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  processed_at DATETIME DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_ai_jobs_queue (status, next_attempt_at, priority, created_at),
  KEY idx_ai_jobs_target (target_type, target_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_moderation_verdicts (
  id VARCHAR(36) NOT NULL,
  job_id VARCHAR(36) DEFAULT NULL,
  target_type ENUM('post','comment','collab') NOT NULL,
  target_id VARCHAR(36) NOT NULL,
  model VARCHAR(120) DEFAULT NULL,
  prompt_version VARCHAR(20) NOT NULL,
  scores JSON DEFAULT NULL,
  decision ENUM('remove','flag','clean','gone','error') NOT NULL,
  reason TEXT DEFAULT NULL,
  moderation_action_id VARCHAR(36) DEFAULT NULL,
  latency_ms INT DEFAULT NULL,
  usage_json JSON DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ai_verdicts_target (target_type, target_id),
  KEY idx_ai_verdicts_decision (decision, created_at),
  KEY idx_ai_verdicts_created (created_at),
  CONSTRAINT fk_ai_verdicts_job FOREIGN KEY (job_id) REFERENCES ai_moderation_jobs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_image_hashes (
  sha256 CHAR(64) NOT NULL,
  scores JSON NOT NULL,
  model VARCHAR(120) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (sha256)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- AI system actor. Password is a bcrypt hash of random bytes that are
-- discarded: this account can never be logged into.
INSERT IGNORE INTO users (id, username, password, email, role, account_status, email_verified_at, terms_accepted_at)
VALUES ('00000000-0000-4000-8000-0000000000a1', 'ai-moderator', '$2y$10$vZLeMusS5Z/qbHaTeLIxR.TJi2OWOXxRHweHIXAKi6rS0OXJgzbsq', 'ai-moderator@underflow.app', 'super_admin', 'active', NOW(), NOW());

INSERT IGNORE INTO profiles (user_id, full_name, bio)
VALUES ('00000000-0000-4000-8000-0000000000a1', 'AI Moderator', 'Sistem moderasi otomatis Underflow');
