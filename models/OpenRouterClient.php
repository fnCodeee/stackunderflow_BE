<?php

/**
 * models/OpenRouterClient.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Klien tipis OpenRouter chat completions untuk penilaian moderasi.
 * Satu method: judge() menilai satu konten (gambar dan/atau teks)
 * dengan satu model dan mengembalikan skor terstruktur.
 * Semua kegagalan (HTTP, jaringan, JSON rusak) dilempar sebagai
 * RuntimeException supaya rantai fallback di AiModerationService
 * bisa mengambil alih.
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

class OpenRouterClient
{
    /**
     * @return array{scores: array, usage: array, latency_ms: int}
     */
    public static function judge(array $cfg, string $model, ?string $imageBytes, ?string $text): array
    {
        $apiKey = (string) ($cfg['api_key'] ?? '');
        if ($apiKey === '') {
            throw new RuntimeException('OPENROUTER_API_KEY kosong.');
        }

        $userText = 'Nilai konten ini sesuai kebijakan. Balas hanya JSON.';
        if ($text !== null && trim($text) !== '') {
            $userText .= "\n\nTeks konten:\n" . mb_substr(trim($text), 0, 2000);
        }
        $content = [['type' => 'text', 'text' => $userText]];
        if ($imageBytes !== null) {
            $content[] = [
                'type' => 'image_url',
                'image_url' => ['url' => 'data:image/jpeg;base64,' . base64_encode($imageBytes)],
            ];
        }

        $payload = [
            'model' => $model,
            'temperature' => 0,
            'max_tokens' => (int) $cfg['max_tokens'],
            'messages' => [
                ['role' => 'system', 'content' => (string) $cfg['prompt']],
                ['role' => 'user', 'content' => $content],
            ],
        ];

        $started = microtime(true);
        $ch = curl_init((string) $cfg['endpoint']);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => (int) $cfg['timeout_seconds'],
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
        ]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $latencyMs = (int) round((microtime(true) - $started) * 1000);

        if ($body === false || $errno !== 0) {
            throw new RuntimeException("OpenRouter jaringan gagal (model $model): $error");
        }
        if ($code !== 200) {
            throw new RuntimeException("OpenRouter HTTP $code (model $model)");
        }

        $decoded = json_decode((string) $body, true);
        $raw = $decoded['choices'][0]['message']['content'] ?? null;
        if (!is_string($raw) || trim($raw) === '') {
            throw new RuntimeException("OpenRouter respons kosong (model $model)");
        }

        return [
            'scores' => self::parseScores($raw),
            'usage' => is_array($decoded['usage'] ?? null) ? $decoded['usage'] : [],
            'latency_ms' => $latencyMs,
        ];
    }

    /**
     * Ekstrak dan validasi objek skor dari teks respons model.
     * Model reasoning kadang membungkus JSON dengan teks lain, jadi
     * yang diambil adalah kurung kurawal terluar pertama.
     */
    public static function parseScores(string $raw): array
    {
        if (!preg_match('/\{.*\}/s', $raw, $m)) {
            throw new RuntimeException('Respons model tidak mengandung JSON.');
        }
        $parsed = json_decode($m[0], true);
        if (!is_array($parsed)) {
            throw new RuntimeException('JSON respons model tidak valid.');
        }
        $scores = [];
        foreach (['judol', 'seksual', 'kekerasan', 'miras', 'berbahaya', 'kata_kasar'] as $key) {
            if (!isset($parsed[$key]) || !is_numeric($parsed[$key])) {
                throw new RuntimeException("Skor '$key' hilang dari respons model.");
            }
            $scores[$key] = max(0, min(100, (int) $parsed[$key]));
        }
        $scores['alasan'] = mb_substr(trim((string) ($parsed['alasan'] ?? '')), 0, 500);
        return $scores;
    }
}
