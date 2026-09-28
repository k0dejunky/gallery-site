<?php

declare(strict_types=1);

namespace App\Models;

/**
 * PeerTube API client for the Auto Poster. Uploads a video with title,
 * description and NSFW flag to an instance using a per-instance access token.
 */
class PeerTubeClient
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function isConfigured(): bool
    {
        return trim((string) ($this->config['instance'] ?? '')) !== ''
            && trim((string) ($this->config['access_token'] ?? '')) !== '';
    }

    public function isUserAuthorized(): bool
    {
        return $this->isConfigured();
    }

    public function ping(): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'Instance or access token missing.'];
        }

        [$status, , $body] = Http::request($this->base() . '/api/v1/users/me', [
            'headers' => ['Authorization' => 'Bearer ' . $this->config['access_token']],
        ]);

        if ($status !== 200) {
            return ['ok' => false, 'error' => 'PeerTube rejected the token (HTTP ' . $status . ').'];
        }

        $data = json_decode($body, true);
        $name = (string) ($data['username'] ?? '');

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
            return ['ok' => false, 'error' => 'PeerTube posts require a video file.'];
        }

        $nsfw = (bool) ($meta['sensitive'] ?? true);

        try {
            [$status, , $body] = Http::request($this->base() . '/api/v1/videos/upload', [
                'method' => 'POST',
                'headers' => ['Authorization' => 'Bearer ' . $this->config['access_token']],
                'multipart' => [
                    'name'        => (string) ($meta['title'] ?? 'New upload'),
                    'description' => trim($text) !== '' ? $text : '',
                    'privacy'     => '1',
                    'nsfw'        => $nsfw ? 'true' : 'false',
                    'channelId'   => '1',
                    'videofile'   => [
                        'file' => $file['tmp_name'],
                        'name' => $file['name'] ?? basename($file['tmp_name']),
                        'type' => (string) ($file['type'] ?? mime_content_type($file['tmp_name']) ?: 'video/mp4'),
                    ],
                ],
            ]);

            if ($status !== 200 && $status !== 201) {
                return ['ok' => false, 'error' => 'PeerTube upload failed (HTTP ' . $status . '): ' . $body];
            }

            $uuid = (string) (json_decode($body, true)['video']['uuid'] ?? json_decode($body, true)['uuid'] ?? '');

            return ['ok' => true, 'url' => $this->base() . '/w/' . $uuid];
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