<?php

declare(strict_types=1);

namespace App\Models;

/**
 * SoFurry API v1 client for the Auto Poster. Uploads an image submission with
 * a rating using an OAuth bearer token. Best-effort — surfaces API errors.
 */
class SoFurryClient
{
    private const API = 'https://api.sofurry.com/v1';

    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function isConfigured(): bool
    {
        return trim((string) ($this->config['access_token'] ?? '')) !== '';
    }

    public function isUserAuthorized(): bool
    {
        return $this->isConfigured();
    }

    public function ping(): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'Access token missing.'];
        }

        [$status, , $body] = Http::request(self::API . '/user/me', [
            'headers' => ['Authorization' => 'Bearer ' . $this->config['access_token']],
        ]);

        if ($status !== 200) {
            return ['ok' => false, 'error' => 'SoFurry rejected the token (HTTP ' . $status . ').'];
        }

        $name = (string) (json_decode($body, true)['data']['name'] ?? '');

        return ['ok' => true, 'note' => $name];
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
            return ['ok' => false, 'error' => 'SoFurry posts require an image file.'];
        }

        $sensitive = (bool) ($meta['sensitive'] ?? false);
        $rating = $sensitive ? 'adult' : 'mature';

        try {
            [$status, , $body] = Http::request(self::API . '/submission', [
                'method' => 'POST',
                'headers' => ['Authorization' => 'Bearer ' . $this->config['access_token']],
                'multipart' => [
                    'title'       => (string) ($meta['title'] ?? 'New upload'),
                    'rating'      => $rating,
                    'description' => trim($text),
                    'content'     => [
                        'file' => $file['tmp_name'],
                        'name' => $file['name'] ?? basename($file['tmp_name']),
                        'type' => (string) ($file['type'] ?? mime_content_type($file['tmp_name']) ?: 'image/jpeg'),
                    ],
                ],
            ]);

            if ($status !== 200 && $status !== 201) {
                return ['ok' => false, 'error' => 'SoFurry upload failed (HTTP ' . $status . '): ' . $body];
            }

            $id = (int) (json_decode($body, true)['data']['id'] ?? 0);

            return ['ok' => true, 'url' => $id > 0 ? 'https://sofurry.com/s/' . $id : ''];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}