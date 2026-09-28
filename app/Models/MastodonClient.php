<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Mastodon API client for the Auto Poster. One client serves the whole
 * Mastodon API family — Mastodon, Pleroma, Akkoma, GoToSocial — which all
 * expose the same /api/v1 surface. Multi-instance: config['instances'] maps
 * host => access token, and the post's meta['instance'] selects the account.
 */
class MastodonClient
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function isConfigured(): bool
    {
        return $this->instanceTokens() !== [];
    }

    public function isUserAuthorized(): bool
    {
        return $this->isConfigured();
    }

    /** Resolve instance host + token for a target (or the first configured). */
    public function resolve(?string $instance = null): array
    {
        $tokens = $this->instanceTokens();
        if ($tokens === []) {
            return ['', ''];
        }

        if ($instance !== null && $instance !== '') {
            $host = trim($instance);
            if (isset($tokens[$host])) {
                return [$host, $tokens[$host]];
            }
            // Allow "https://host" keys without the scheme.
            $host = (string) preg_replace('#^https?://#', '', $host);
            if (isset($tokens[$host])) {
                return [$host, $tokens[$host]];
            }
        }

        $host = array_key_first($tokens);

        return [$host, $tokens[$host]];
    }

    public function ping(): array
    {
        [$host, $token] = $this->resolve();
        if ($host === '') {
            return ['ok' => false, 'error' => 'No instance/token configured.'];
        }

        [$status, , $body] = Http::request($this->base($host) . '/api/v1/accounts/verify_credentials', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);

        if ($status !== 200) {
            return ['ok' => false, 'error' => 'Mastodon rejected the token on ' . $host . ' (HTTP ' . $status . ').'];
        }

        $data = json_decode($body, true);
        $acct = $data['acct'] ?? $data['username'] ?? '';

        return ['ok' => true, 'note' => '@' . $acct . '@' . $host];
    }

    public function post(string $text, array $media = [], array $meta = []): array
    {
        [$host, $token] = $this->resolve($meta['instance'] ?? null);
        if ($host === '') {
            return ['ok' => false, 'error' => 'No Mastodon instance configured.'];
        }

        $sensitive = (bool) ($meta['sensitive'] ?? false);
        $mediaIds  = [];

        try {
            foreach ($media as $m) {
                if (empty($m['tmp_name']) || !is_file($m['tmp_name'])) {
                    continue;
                }
                [$status, , $body] = Http::request($this->base($host) . '/api/v1/media', [
                    'method'  => 'POST',
                    'headers' => ['Authorization' => 'Bearer ' . $token],
                    'multipart' => ['file' => [
                        'file' => $m['tmp_name'],
                        'name' => $m['name'] ?? basename($m['tmp_name']),
                        'type' => (string) ($m['type'] ?? mime_content_type($m['tmp_name']) ?: 'application/octet-stream'),
                    ]],
                ]);

                if ($status !== 200) {
                    return ['ok' => false, 'error' => 'Mastodon media upload failed (HTTP ' . $status . ').'];
                }
                $mediaIds[] = (string) (json_decode($body, true)['id'] ?? '');
            }

            [$status, , $body] = Http::request($this->base($host) . '/api/v1/statuses', [
                'method' => 'POST',
                'headers' => ['Authorization' => 'Bearer ' . $token],
                'json' => [
                    'status'    => trim($text) !== '' ? $text : 'New upload',
                    'media_ids' => array_values(array_filter($mediaIds)),
                    'sensitive' => $sensitive,
                    'visibility' => 'public',
                ],
            ]);

            if ($status !== 200) {
                return ['ok' => false, 'error' => 'Mastodon status failed (HTTP ' . $status . ').'];
            }

            $data = json_decode($body, true);
            $id   = (string) ($data['id'] ?? '');
            $user = (string) ($data['account']['username'] ?? '');

            return ['ok' => true, 'url' => $this->base($host) . '/@' . $user . '/' . $id];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * @return array<string, string> host => token
     */
    private function instanceTokens(): array
    {
        $tokens = [];

        foreach ((array) ($this->config['instances'] ?? []) as $host => $token) {
            $host = trim((string) $host);
            $token = trim((string) $token);
            if ($host !== '' && $token !== '') {
                $tokens[$host] = $token;
            }
        }

        // Single-instance fallback (config['instance'] + config['access_token']).
        if ($tokens === [] && trim((string) ($this->config['instance'] ?? '')) !== '' && trim((string) ($this->config['access_token'] ?? '')) !== '') {
            $tokens[trim((string) $this->config['instance'])] = trim((string) $this->config['access_token']);
        }

        return $tokens;
    }

    private function base(string $host): string
    {
        $host = trim($host);

        return strpos($host, 'http') === 0 ? rtrim($host, '/') : 'https://' . $host;
    }
}