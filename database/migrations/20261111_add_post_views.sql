-- Post views per akun (2026-11-11): satu akun dihitung maksimal sekali per post.
-- Tabel post_views mencatat (user_id, post_id) yang unik; view_count di
-- public_posts hanya ditambah saat baris baru masuk (view pertama akun itu).
-- Alasan: tanpa ini, satu akun bisa menambah view berkali-kali dengan terus
-- membuka halaman detail. Mengikuti pola like_posts (UNIQUE user+post).
-- Idempotent: CREATE TABLE IF NOT EXISTS, aman dijalankan ulang.

CREATE TABLE IF NOT EXISTS post_views (
    id VARCHAR(36) NOT NULL DEFAULT (UUID()),
    post_id VARCHAR(36) NOT NULL,
    user_id VARCHAR(36) NOT NULL,
    viewed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_user_post_view (user_id, post_id),
    KEY idx_post_views_post_id (post_id),
    KEY idx_post_views_user_id (user_id),

    CONSTRAINT fk_views_post
        FOREIGN KEY (post_id) REFERENCES public_posts (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_views_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
