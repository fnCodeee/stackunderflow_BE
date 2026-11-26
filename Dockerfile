# Underflow backend — PHP Native + PDO MySQL, tanpa framework / Composer.
# Runtime mengikuti cara jalan yang sudah dipakai selama ini (start.sh):
# PHP built-in server dengan document root public/ (front controller).
FROM php:8.2-cli

# Ekstensi yang dipakai kode:
# - pdo_mysql : koneksi database (config/database.php) — tidak ada di image bawaan
# - curl      : Google OAuth, upload Cloudinary, publish realtime
#               (sudah termasuk di image resmi php:*-cli, sama seperti mbstring & openssl)
RUN docker-php-ext-install pdo_mysql

WORKDIR /var/www/html

# Salin seluruh kode backend.
# File .env TIDAK ikut masuk image (lihat .dockerignore) — env untuk Docker
# di-mount oleh docker-compose dari .env.docker menjadi .env di dalam container.
COPY . .

EXPOSE 8000

# 0.0.0.0 wajib di dalam container — "localhost" hanya bisa diakses dari dalam container.
CMD ["php", "-S", "0.0.0.0:8000", "-t", "public"]
