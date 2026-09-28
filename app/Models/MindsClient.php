<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Minds API client for the Auto Poster. Logs in to the Minds API, uploads
 * media and creates newsfeed posts (NSFW-tagged). Best-effort: Minds' API is
 * lightly documented, so failures are surfaced verbatim for inspection.
 */
class MindsClient
{
    private const BASE = 'https://www.minds.com/api';

    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function isConfigured(): bool
    {
        return trim((string) ($this->config['username'] ?? '')) !== ''
            && trim((string) ($this->config['password'] ?? '')) !== '';
    }

    public function isUserAuthorized(): bool
    {
        return $this->isConfigured();
    }

    public function ping(): array
    {
        $auth = $this->login();
        if (!$auth['ok']) {
            return $auth;
        }

        return ['ok' => true, 'note' => $this->config['username']];
    }

    public function post(string $text, array $media = [], array $meta = []): array
    {
        $auth = $this->login();
        if (!$auth['ok']) {
            return $auth;
        }

        $token = $auth['token'];
        $sensitive = (bool) ($meta['sensitive'] ?? true);

        try {
            $guid = null;
            foreach ($media as $m) {
                if (empty($m['tmp_name']) || !is_file($m['tmp_name'])) {
                    continue;
                }
                [$status, , $body] = Http::request(self::BASE . '/v2/media', [
                    'method' => 'POST',
                    'headers' => ['Authorization' => 'Bearer ' . $token, 'X-Minds-Sig' => $token],
                    'multipart' => ['file' => [
                        'file' => $m['tmp_name'],
                        'name' => $m['name'] ?? basename($m['tmp_name']),
                        'type' => (string) ($m['type'] ?? mime_content_type($m['tmp_name']) ?: 'application/octet-stream'),
                    ]],
                ]);

                if ($status !== 200) {
                    return ['ok' => false, 'error' => 'Minds media upload failed (HTTP ' . $status . ').'];
                }
                $guid = (string) (json_decode($body, true)['guid'] ?? '');
                break;
            }

            $payload = ['message' => trim($text) !== '' ? $text : 'New upload'];
            if ($guid !== '') {
                $payload['attachment_guids'] = [$guid];
            }
            if ($sensitive) {
                $payload['nsfw'] = [1];
            }

            [$status, , $body] = Http::request(self::BASE . '/v2/newsfeed', [
                'method' => 'POST',
                'headers' => ['Authorization' => 'Bearer ' . $token, 'X-Minds-Sig' => $token],
                'json' => $payload,
            ]);

            if ($status !== 200 && $status !== 201) {
                return ['ok' => false, 'error' => 'Minds post failed (HTTP ' . $status . '): ' . $body];
            }

            $data = json_decode($body, true);
            $activity = $data['activity'] ?? $data['guid'] ?? $data['entity_guid'] ?? '';

            $id = is_array($activity) ? (string) ($activity['guid'] ?? '') : (string) $activity;

            return ['ok' => true, 'url' => 'https://www.minds.com/newsfeed/' . $id];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function login(): array
    {
        [$status, , $body] = Http::request(self::BASE . '/v2/authenticate', [
            'method' => 'POST',
            'json' => [
                'username' => (string) ($this->config['username'] ?? ''),
                'password' => (string) ($this->config['password'] ?? ''),
            ],
        ]);

        if ($status !== 200) {
            return ['ok' => false, 'error' => 'Minds login failed (HTTP ' . $status . ').'];
        }

        $data = json_decode($body, true);
        $token = (string) ($data['access_token'] ?? '');

        return $token !== '' ? ['ok' => true, 'token' => $token] : ['ok' => false, 'error' => 'Minds returned no access token.'];
    }
}