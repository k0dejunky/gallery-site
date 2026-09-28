<?php

declare(strict_types=1);

namespace App\Models;

/**
 * WriteFreely / Write.as API client for the Auto Poster. Publishes a long-form
 * post (title + body) to a collection on an instance using an access token.
 */
class WriteFreelyClient
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
        [$status, , $body] = Http::request($this->base() . '/api/me', [
            'headers' => ['Authorization' => 'Token ' . $this->config['access_token']],
        ]);

        if ($status !== 200) {
            return ['ok' => false, 'error' => 'WriteFreely rejected the token (HTTP ' . $status . ').'];
        }

        $name = (string) (json_decode($body, true)['user']['username'] ?? '');

        return ['ok' => true, 'note' => $name];
    }

    public function post(string $text, array $media = [], array $meta = []): array
    {
        $collection = (string) ($meta['collection'] ?? $this->config['collection'] ?? '');
        if ($collection === '') {
            return ['ok' => false, 'error' => 'No WriteFreely collection configured.'];
        }

        try {
            [$status, , $body] = Http::request($this->base() . '/api/collections/' . rawurlencode($collection) . '/posts', [
                'method' => 'POST',
                'headers' => ['Authorization' => 'Token ' . $this->config['access_token']],
                'json' => [
                    'title' => (string) ($meta['title'] ?? 'New upload'),
                    'body'  => trim($text),
                ],
            ]);

            if ($status !== 201 && $status !== 200) {
                return ['ok' => false, 'error' => 'WriteFreely post failed (HTTP ' . $status . '): ' . $body];
            }

            $slug = (string) (json_decode($body, true)['data']['slug'] ?? '');

            return ['ok' => true, 'url' => $slug !== '' ? $this->base() . '/' . $collection . '/' . $slug : ''];
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