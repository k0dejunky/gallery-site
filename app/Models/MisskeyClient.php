<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Misskey API client for the Auto Poster (also covers the Sharkey / Iceshrimp
 * / Firefish forks, which share the same POST-JSON API). Uses the account's
 * "i" API token from Settings → API.
 */
class MisskeyClient
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function isConfigured(): bool
    {
        return trim((string) ($this->config['instance'] ?? '')) !== ''
            && trim((string) ($this->config['api_token'] ?? '')) !== '';
    }

    public function isUserAuthorized(): bool
    {
        return $this->isConfigured();
    }

    public function ping(): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'Instance or API token missing.'];
        }

        [$status, , $body] = Http::request($this->base() . '/api/i', [
            'method' => 'POST',
            'json' => ['i' => (string) ($this->config['api_token'] ?? '')],
        ]);

        if ($status !== 200) {
            return ['ok' => false, 'error' => 'Misskey rejected the token (HTTP ' . $status . ').'];
        }

        $data = json_decode($body, true);
        $name = (string) ($data['username'] ?? '');

        return ['ok' => true, 'note' => '@' . $name];
    }

    public function post(string $text, array $media = [], array $meta = []): array
    {
        $token  = (string) ($this->config['api_token'] ?? '');
        $sensitive = (bool) ($meta['sensitive'] ?? true);

        try {
            $fileIds = [];
            foreach ($media as $m) {
                if (empty($m['tmp_name']) || !is_file($m['tmp_name'])) {
                    continue;
                }
                [$status, , $body] = Http::request($this->base() . '/api/drive/files/create', [
                    'method' => 'POST',
                    'multipart' => [
                        'i'    => $token,
                        'force' => 'true',
                        'file' => [
                            'file' => $m['tmp_name'],
                            'name' => $m['name'] ?? basename($m['tmp_name']),
                            'type' => (string) ($m['type'] ?? mime_content_type($m['tmp_name']) ?: 'application/octet-stream'),
                        ],
                    ],
                ]);

                if ($status !== 200) {
                    return ['ok' => false, 'error' => 'Misskey file upload failed (HTTP ' . $status . ').'];
                }
                $fileIds[] = (string) (json_decode($body, true)['id'] ?? '');
            }

            [$status, , $body] = Http::request($this->base() . '/api/notes/create', [
                'method' => 'POST',
                'json' => [
                    'i'          => $token,
                    'text'       => trim($text) !== '' ? $text : 'New upload',
                    'fileIds'    => array_values(array_filter($fileIds)),
                    'visibility' => 'public',
                    'localOnly'  => false,
                    'cw'         => $sensitive ? 'NSFW' : null,
                ],
            ]);

            if ($status !== 200) {
                return ['ok' => false, 'error' => 'Misskey note failed (HTTP ' . $status . ').'];
            }

            $id = (string) (json_decode($body, true)['createdNote']['id'] ?? '');

            return ['ok' => true, 'url' => $this->base() . '/notes/' . $id];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function base(): string
    {
        $instance = trim((string) ($this->config['instance'] ?? ''));

        return strpos($instance, 'http') === 0 ? rtrim($instance, '/') : 'https://' . $instance;
    }
}