<?php

/**
 * models/OpenRouterClient.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Klien tipis chat completions untuk penilaian moderasi. Tiga
 * provider kompatibel OpenAI dipakai: OpenRouter, xKiro, dan
 * Token Harbor (ditambahkan 2026-11-20); provider dipilih per
 * model dari config 'model_providers'.
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
    /** Provider penampung model: 'xkiro', 'tokenharbor', atau 'openrouter'. */
    public static function providerFor(array $cfg, string $model): string
    {
        $provider = $cfg['model_providers'][$model] ?? 'openrouter';
        return in_array($provider, ['xkiro', 'tokenharbor'], true) ? $provider : 'openrouter';
    }

    /**
     * Rute panggilan untuk satu model: endpoint, API key, dan label
     * provider untuk pesan error.
     *
     * @return array{endpoint: string, api_key: string, label: string}
     */
    private static function route(array $cfg, string $model): array
    {
        if (self::providerFor($cfg, $model) === 'xkiro') {
            $apiKey = (string) ($cfg['xkiro_api_key'] ?? '');
            if ($apiKey === '') {
                throw new RuntimeException('XKIRO_API_KEY kosong.');
            }
            return [
                'endpoint' => (string) ($cfg['xkiro_endpoint'] ?? 'https://api.xkiro.com/v1/chat/completions'),
                'api_key' => $apiKey,
                'label' => 'xKiro',
            ];
        }
        if (self::providerFor($cfg, $model) === 'tokenharbor') {
            $apiKey = (string) ($cfg['tokenharbor_api_key'] ?? '');
            if ($apiKey === '') {
                throw new RuntimeException('TOKENHARBOR_API_KEY kosong.');
            }
            return [
                'endpoint' => (string) ($cfg['tokenharbor_endpoint'] ?? 'https://tokenharbor.ai/v1/chat/completions'),
                'api_key' => $apiKey,
                'label' => 'TokenHarbor',
            ];
        }
        $apiKey = (string) ($cfg['api_key'] ?? '');
        if ($apiKey === '') {
            throw new RuntimeException('OPENROUTER_API_KEY kosong.');
        }
        return [
            'endpoint' => (string) $cfg['endpoint'],
            'api_key' => $apiKey,
            'label' => 'OpenRouter',
        ];
    }

    public static function judge(array $cfg, string $model, ?string $imageBytes, ?string $text, ?string $reasonLang = null): array
    {
        $route = self::route($cfg, $model);
        $apiKey = $route['api_key'];

        $userText = 'Nilai konten ini sesuai kebijakan. Balas hanya JSON.';
        if ($text !== null && trim($text) !== '') {
            $userText .= "\n\nTeks konten:\n" . mb_substr(trim($text), 0, 2000);
        }
        // Bahasa alasan dikunci per permintaan mengikuti bahasa
        // konten (keputusan user 2026-11-17).
        if ($reasonLang === 'id') {
            $userText .= "\n\nTulis alasan dalam Bahasa Indonesia.";
        } elseif ($reasonLang === 'en') {
            $userText .= "\n\nWrite the reason (alasan) in English.";
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

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ];
        if (in_array($route['label'], ['xKiro', 'TokenHarbor'], true)) {
            // Cloudflare di depan api.xkiro.com menolak user agent
            // bawaan cURL (error 1010); UA browser wajib disertakan.
            // Token Harbor disertakan juga sebagai pencegahan
            // penyaringan bot generik di depan agregator.
            $headers[] = 'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';
        }

        $started = microtime(true);
        $ch = curl_init($route['endpoint']);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => (int) $cfg['timeout_seconds'],
            CURLOPT_HTTPHEADER => $headers,
        ]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $latencyMs = (int) round((microtime(true) - $started) * 1000);

        if ($body === false || $errno !== 0) {
            throw new RuntimeException($route['label'] . " jaringan gagal (model $model): $error");
        }
        if ($code !== 200) {
            throw new RuntimeException($route['label'] . " HTTP $code (model $model)");
        }

        $decoded = json_decode((string) $body, true);
        $raw = $decoded['choices'][0]['message']['content'] ?? null;
        if (!is_string($raw) || trim($raw) === '') {
            throw new RuntimeException($route['label'] . " respons kosong (model $model)");
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
