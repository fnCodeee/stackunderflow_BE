<?php

declare(strict_types=1);

/**
 * utils/SkillGuard.php
 * ─────────────────────────────────────────────────────────────────────────────
 * SKILL GUARD — validasi nama skill pribadi (POST /api/skills).
 *
 * Tiga lapis supaya user tidak bisa membuat tag aneh-aneh:
 *  1. Format: 2-30 karakter; hanya huruf, angka, spasi, dan simbol
 *     yang memang dipakai nama skill (+ # . / -); wajib mengandung
 *     huruf; tanpa alamat web/domain; tanpa nomor telepon; tanpa
 *     karakter berulang berlebihan.
 *  2. Blocklist ternormalisasi: nama diurai dari leetspeak
 *     (0->o, 1->i, 3->e, 4->a, 5->s, 7->t, @->a, $->s, !->i), lalu
 *     dicek dalam tiga bentuk:
 *       - substring atas bentuk "squashed" (semua non-alfanumerik
 *         dibuang) — menangkap "sl0t", "g a cor", "SlotGacor88";
 *       - kata utuh (token persis / nama squashed persis) untuk
 *         kata pendek ambigu — "ass" hanya kena sebagai kata utuh,
 *         jadi "assisten" tetap lolos;
 *       - kerangka konsonan (vokal dibuang) untuk kata yang
 *         vokalnya disamarkan simbol, mis. "k*nt*l" -> "kntl".
 *  3. Batas jumlah: maksimal MAX_PRIVATE_PER_USER skill pribadi
 *     per user (dihitung controller lewat SkillModel).
 *
 * validateName() mengembalikan pesan error (Bahasa Indonesia, siap
 * tampil ke user) atau null kalau nama lolos.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class SkillGuard
{
    public const MAX_PRIVATE_PER_USER = 20;

    private const MSG_FORMAT = 'Nama skill tidak valid. Pakai 2-30 karakter: huruf, angka, spasi, dan simbol + # . / - saja.';
    private const MSG_LINK = 'Nama skill tidak boleh berisi alamat web atau nomor telepon.';
    private const MSG_BLOCKED = 'Nama skill mengandung kata yang tidak diperbolehkan.';
    public const MSG_CAP = 'Kamu sudah mencapai batas 20 skill pribadi. Hapus dulu skill yang tidak dipakai.';

    /** Dilarang sebagai SUBSTRING dari bentuk squashed. */
    private const BLOCK_SUBSTRING = [
        // Kata kasar / hinaan (Indonesia)
        'anjing', 'babi', 'bangsat', 'jancok', 'dancok', 'kontol', 'memek',
        'ngentot', 'ngewe', 'bajingan', 'keparat', 'goblok', 'kampret', 'monyet',
        // Profanity (Inggris)
        'fuck', 'fck', 'bitch', 'pussy', 'whore', 'nigger', 'nigga', 'dick',
        // 18+ / seksual
        'porn', 'bokep', 'hentai', 'mesum', 'bugil', 'telanjang', 'esek', 'xxx',
        // Judi online: istilah generik + pola brand besar
        'gacor', 'togel', 'casino', 'kasino', 'maxwin', 'poker', 'sbobet',
        'pragmaticplay', 'mahjongways', 'gatesofolympus', 'spadegaming',
        'joker123', 'jokergaming',
    ];

    /** Kata pendek ambigu: hanya dilarang sebagai KATA UTUH. */
    private const BLOCK_WORD = [
        'ass', 'sex', 'jav', 'judi', 'slot', 'slots', 'bet', 'zeus', 'toto',
    ];

    /** Kata yang juga dicek lewat kerangka konsonan (anti samaran vokal). */
    private const BLOCK_MASKED = [
        'kontol', 'memek', 'ngentot', 'anjing', 'bangsat', 'fuck',
    ];

    /** @return string|null pesan error, atau null kalau lolos */
    public static function validateName(string $name): ?string
    {
        $length = mb_strlen($name);
        if ($length < 2 || $length > 30) {
            return self::MSG_FORMAT;
        }
        if (!preg_match('/^[\p{L}\p{N}][\p{L}\p{N} \+#\.\/-]*$/u', $name)) {
            return self::MSG_FORMAT;
        }
        if (!preg_match('/\p{L}/u', $name)) {
            return self::MSG_FORMAT; // wajib ada huruf (bukan angka/simbol semua)
        }
        // Alamat web / domain
        if (preg_match('/(www\.|https?|\.(com|net|org|info|biz|io|co|id|xyz|site|online|top|vip|pro|bet|win|click|link)\b)/i', $name)) {
            return self::MSG_LINK;
        }
        // Nomor telepon / nama yang didominasi angka
        $digitCount = preg_match_all('/\d/', $name) ?: 0;
        if (preg_match('/\d{8,}/', $name) || ($digitCount >= 6 && $digitCount / $length > 0.5)) {
            return self::MSG_LINK;
        }
        // Karakter berulang berlebihan ("aaaa", "testtt")
        if (preg_match('/(.)\1{3,}/u', mb_strtolower($name))) {
            return self::MSG_FORMAT;
        }
        if (self::isBlocked($name)) {
            return self::MSG_BLOCKED;
        }
        return null;
    }

    private static function isBlocked(string $name): bool
    {
        $squashed = self::squash($name);
        foreach (self::BLOCK_SUBSTRING as $bad) {
            if (str_contains($squashed, $bad)) {
                return true;
            }
        }
        foreach (self::BLOCK_WORD as $bad) {
            if ($squashed === $bad) {
                return true;
            }
        }
        foreach (self::tokens($name) as $token) {
            if (in_array($token, self::BLOCK_WORD, true)) {
                return true;
            }
        }
        $skeleton = self::skeleton($squashed);
        foreach (self::BLOCK_MASKED as $bad) {
            if (str_contains($skeleton, self::skeleton($bad))) {
                return true;
            }
        }
        return false;
    }

    /** Leetspeak diurai, lalu semua non-alfanumerik dibuang. */
    private static function squash(string $name): string
    {
        $s = self::deLeet(mb_strtolower($name));
        return preg_replace('/[^a-z0-9]/', '', $s) ?? '';
    }

    /** @return string[] token huruf/angka dari nama yang ternormalisasi */
    private static function tokens(string $name): array
    {
        $s = self::deLeet(mb_strtolower($name));
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s) ?? '';
        return array_values(array_filter(
            explode(' ', trim($s)),
            fn (string $t): bool => $t !== ''
        ));
    }

    /** Kerangka konsonan: vokal dibuang ("kontol" -> "kntl"). */
    private static function skeleton(string $squashed): string
    {
        return preg_replace('/[aeiou]/', '', $squashed) ?? '';
    }

    private static function deLeet(string $s): string
    {
        return strtr($s, [
            '0' => 'o', '1' => 'i', '3' => 'e', '4' => 'a',
            '5' => 's', '7' => 't', '@' => 'a', '$' => 's', '!' => 'i',
        ]);
    }
}
