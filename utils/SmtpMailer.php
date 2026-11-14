<?php

/**
 * utils/SmtpMailer.php
 * ─────────────────────────────────────────────────────────────────────────────
 * SMTP CLIENT MINIMAL UNTUK GMAIL (reset password, 2026-11-14)
 *
 * Backend tidak memakai Composer/PHPMailer, jadi pengiriman email
 * dilakukan dengan client SMTP mentah di atas stream socket:
 * koneksi ke smtp.gmail.com:587, STARTTLS, AUTH LOGIN, lalu kirim
 * pesan multipart (teks + HTML).
 *
 * Konfigurasi dari .env:
 *   SMTP_HOST (default smtp.gmail.com), SMTP_PORT (default 587),
 *   SMTP_USER (alamat Gmail pengirim), SMTP_PASS (App Password Google,
 *   BUKAN password akun biasa), SMTP_FROM_NAME (default Underflow).
 *
 * Semua kegagalan dicatat ke error_log dan menghasilkan false —
 * pemanggil (endpoint forgot-password) tidak pernah membocorkan
 * status pengiriman ke client.
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

class SmtpMailer
{
    public static function isConfigured(): bool
    {
        return self::user() !== '' && self::pass() !== '';
    }

    /** Kirim email multipart (plain text + HTML). True bila server menerima. */
    public static function send(string $to, string $subject, string $textBody, string $htmlBody): bool
    {
        if (!self::isConfigured()) {
            error_log('[SMTP] SMTP_USER/SMTP_PASS belum dikonfigurasi di .env');
            return false;
        }
        $fp = null;
        try {
            $host = trim((string) ($_ENV['SMTP_HOST'] ?? '')) ?: 'smtp.gmail.com';
            $port = (int) (($_ENV['SMTP_PORT'] ?? '') ?: 587);
            $user = self::user();
            $pass = self::pass();
            $fromName = trim((string) ($_ENV['SMTP_FROM_NAME'] ?? '')) ?: 'Underflow';

            $fp = stream_socket_client(
                "tcp://{$host}:{$port}",
                $errno,
                $errstr,
                15,
                STREAM_CLIENT_CONNECT
            );
            if ($fp === false) {
                error_log("[SMTP] koneksi gagal: {$errstr} ({$errno})");
                return false;
            }
            stream_set_timeout($fp, 15);

            self::expect($fp, [220]);
            self::command($fp, 'EHLO underflow.local', [250]);
            self::command($fp, 'STARTTLS', [220]);
            if (!stream_socket_enable_crypto($fp, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                error_log('[SMTP] STARTTLS gagal');
                return false;
            }
            self::command($fp, 'EHLO underflow.local', [250]);
            self::command($fp, 'AUTH LOGIN', [334]);
            self::command($fp, base64_encode($user), [334]);
            self::command($fp, base64_encode($pass), [235]);
            self::command($fp, "MAIL FROM:<{$user}>", [250]);
            self::command($fp, "RCPT TO:<{$to}>", [250, 251]);
            self::command($fp, 'DATA', [354]);

            $boundary = '=_underflow_' . bin2hex(random_bytes(8));
            $headers = [
                "From: {$fromName} <{$user}>",
                "To: <{$to}>",
                'Subject: ' . $subject,
                'MIME-Version: 1.0',
                "Content-Type: multipart/alternative; boundary=\"{$boundary}\"",
            ];
            $body = '--' . $boundary . "\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\n\r\n"
                . $textBody . "\r\n"
                . '--' . $boundary . "\r\n"
                . "Content-Type: text/html; charset=UTF-8\r\n\r\n"
                . $htmlBody . "\r\n"
                . '--' . $boundary . "--\r\n";
            // Dot-stuffing: baris yang diawali titik harus digandakan.
            $payload = implode("\r\n", $headers) . "\r\n\r\n"
                . preg_replace('/^\./m', '..', $body);
            fwrite($fp, $payload . "\r\n.\r\n");
            self::expect($fp, [250]);
            self::command($fp, 'QUIT', [221]);
            fclose($fp);
            return true;
        } catch (Throwable $e) {
            error_log('[SMTP] kirim gagal: ' . $e->getMessage());
            if (is_resource($fp)) {
                fclose($fp);
            }
            return false;
        }
    }

    private static function user(): string
    {
        return trim((string) ($_ENV['SMTP_USER'] ?? getenv('SMTP_USER') ?: ''));
    }

    private static function pass(): string
    {
        return trim((string) ($_ENV['SMTP_PASS'] ?? getenv('SMTP_PASS') ?: ''));
    }

    /** @param int[] $codes */
    private static function command($fp, string $line, array $codes): void
    {
        fwrite($fp, $line . "\r\n");
        self::expect($fp, $codes);
    }

    /** @param int[] $codes Baca respons (termasuk multiline 250-...) sampai kodenya cocok. */
    private static function expect($fp, array $codes): void
    {
        $response = '';
        while (($line = fgets($fp, 512)) !== false) {
            $response .= $line;
            // Baris terakhir respons: "250 " (kode + spasi); lanjutan: "250-".
            if (preg_match('/^\d{3} /', $line) === 1) {
                break;
            }
        }
        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $codes, true)) {
            throw new RuntimeException("respons SMTP {$code}: " . trim($response));
        }
    }
}
