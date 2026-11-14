-- Migrasi verifikasi email (2026-11-14):
-- kolom status verifikasi + tabel token verifikasi.
ALTER TABLE users ADD COLUMN email_verified_at DATETIME NULL;

CREATE TABLE IF NOT EXISTS email_verification_tokens (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id VARCHAR(36) NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_email_verification_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Akun yang sudah ada sebelum fitur ini dianggap terverifikasi
-- (grandfathered): mereka tidak perlu banner pengingat. Akun baru
-- mulai dari NULL lewat pendaftaran biasa.
UPDATE users SET email_verified_at = NOW() WHERE email_verified_at IS NULL;
