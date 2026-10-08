<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Weasyl HTTP API client for the Auto Poster. Submits an image with a rating
 * (general/mature/explicit). Uses the account API key; best-effort — surfaces
 * the API's errors verbatim.
 */
class WeasylClient
{
    private const API = 'https://www.weasyl.com/api';

    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function isConfigured(): bool
    {
        return trim((string) ($this->config['username'] ?? '')) !== ''
            && trim((string) ($this->config['api_key'] ?? '')) !== '';
    }

    public function isUserAuthorized(): bool
    {
        return $this->isConfigured();
    }

    public function ping(): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'Username or API key missing.'];
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
            return ['ok' => false, 'error' => 'Weasyl posts require an image file.'];
        }

        $sensitive = (bool) ($meta['sensitive'] ?? false);
        $rating = $sensitive ? 'explicit' : 'mature';

        try {
            [$status, , $body] = Http::request(self::API . '/submissions/submit', [
                'method' => 'POST',
                'multipart' => [
                    'api_key'     => (string) $this->config['api_key'],
                    'title'       => (string) ($meta['title'] ?? 'New upload'),
                    'rating'      => $rating,
                    'content'     => trim($text),
                    'tags'        => 'gallery amateur nsfw',
                    'media'       => [
                        'file' => $file['tmp_name'],
                        'name' => $file['name'] ?? basename($file['tmp_name']),
                        'type' => (string) ($file['type'] ?? mime_content_type($file['tmp_name']) ?: 'image/jpeg'),
                    ],
                ],
            ]);

            if ($status !== 200 && $status !== 201) {
                // Cap the echoed upstream body: it is the whole API error
                // payload and can run to thousands of chars — far wider than
                // auto_poster_queue.error (VARCHAR 500), where this lands.
                $body = is_string($body) ? $body : (string) $body;
                if (mb_strlen($body) > 400) {
                    $body = mb_substr($body, 0, 397) . '...';
                }

                return ['ok' => false, 'error' => 'Weasyl submit failed (HTTP ' . $status . '): ' . $body];
            }

            $id = (int) (json_decode($body, true)['submitid'] ?? 0);

            return ['ok' => true, 'url' => $id > 0 ? 'https://www.weasyl.com/submission/' . $id : ''];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}