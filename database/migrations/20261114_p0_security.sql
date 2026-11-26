-- Migrasi P0 keamanan (audit profesional 2026-11-14):
-- tabel pendukung rate limiter + revokasi token JWT.
CREATE TABLE IF NOT EXISTS rate_limits (
  bucket_key VARCHAR(190) NOT NULL PRIMARY KEY,
  attempts INT NOT NULL DEFAULT 0,
  window_start INT NOT NULL DEFAULT 0,
  blocked_until INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS revoked_tokens (
  token_hash CHAR(64) NOT NULL PRIMARY KEY,
  expires_at DATETIME NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
