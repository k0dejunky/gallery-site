<?php

declare(strict_types=1);

namespace App\Models;

/**
 * RedGIFs API client for the Auto Poster. Uploads a video/GIF to the creator's
 * account using the API token issued to registered creators, and returns the
 * public watch URL for linking elsewhere.
 */
class RedGifsClient
{
    private const API = 'https://api.redgifs.com';

    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function isConfigured(): bool
    {
        return trim((string) ($this->config['username'] ?? '')) !== ''
            && trim((string) ($this->config['api_token'] ?? '')) !== '';
    }

    public function isUserAuthorized(): bool
    {
        return $this->isConfigured();
    }

    public function ping(): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'Username or API token missing.'];
        }

        [$status, , $body] = Http::request(self::API . '/v2/me', [
            'headers' => ['Authorization' => 'Bearer ' . $this->config['api_token']],
        ]);

        if ($status !== 200) {
            return ['ok' => false, 'error' => 'RedGIFs rejected the token (HTTP ' . $status . ').'];
        }

        return ['ok' => true, 'note' => $this->config['username']];
    }

    public function post(string $text, array $media = [], array $meta = []): array
    {
        $file = null;
        foreach ($media as $m) {
            if (!empty($m['tmp_name']) && is_file($m['tmp_name'])) {
                $file = $m;
                break;
            }
        }

        if ($file === null) {
            return ['ok' => false, 'error' => 'RedGIFs posts require a video/GIF file.'];
        }

        try {
            [$status, , $body] = Http::request(self::API . '/v2/users/' . urlencode((string) $this->config['username']) . '/gifs', [
                'method' => 'POST',
                'headers' => ['Authorization' => 'Bearer ' . $this->config['api_token']],
                'multipart' => [
                    'file' => [
                        'file' => $file['tmp_name'],
                        'name' => $file['name'] ?? basename($file['tmp_name']),
                        'type' => (string) ($file['type'] ?? mime_content_type($file['tmp_name']) ?: 'video/mp4'),
                    ],
                ],
            ]);

            if ($status !== 201 && $status !== 200) {
                return ['ok' => false, 'error' => 'RedGIFs upload failed (HTTP ' . $status . '): ' . $body];
            }

            $id = (string) (json_decode($body, true)['gif']['id'] ?? '');

            return ['ok' => true, 'url' => $id !== '' ? 'https://redgifs.com/watch/' . $id : ''];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}