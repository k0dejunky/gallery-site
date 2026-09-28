<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Lemmy API v3 client for the Auto Poster. Logs in with an account, uploads
 * one image (pictrs) when attached, resolves the target community and creates
 * a post (nsfw flag set from the meta).
 */
class LemmyClient
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function isConfigured(): bool
    {
        return trim((string) ($this->config['instance'] ?? '')) !== ''
            && trim((string) ($this->config['username'] ?? '')) !== ''
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

        return ['ok' => true, 'note' => $this->config['username'] . '@' . $this->host()];
    }

    public function post(string $text, array $media = [], array $meta = []): array
    {
        $auth = $this->login();
        if (!$auth['ok']) {
            return $auth;
        }

        $jwt = $auth['jwt'];
        $nsfw = (bool) ($meta['sensitive'] ?? true);
        $community = (string) ($meta['community'] ?? $this->config['community'] ?? '');

        try {
            $imageUrl = null;
            foreach ($media as $m) {
                if (empty($m['tmp_name']) || !is_file($m['tmp_name'])) {
                    continue;
                }
                [$status, , $body] = Http::request($this->base() . '/api/v3/image', [
                    'method' => 'POST',
                    'multipart' => ['images[]' => [
                        'file' => $m['tmp_name'],
                        'name' => $m['name'] ?? basename($m['tmp_name']),
                        'type' => (string) ($m['type'] ?? mime_content_type($m['tmp_name']) ?: 'application/octet-stream'),
                    ]],
                ]);

                if ($status === 200) {
                    $imageUrl = (string) (json_decode($body, true)['url'] ?? '');
                }
                break;
            }

            $communityId = $this->resolveCommunity($jwt, $community);
            if ($communityId === null) {
                return ['ok' => false, 'error' => 'Lemmy community not found: ' . $community];
            }

            [$status, , $body] = Http::request($this->base() . '/api/v3/post', [
                'method' => 'POST',
                'json' => [
                    'auth'         => $jwt,
                    'community_id' => $communityId,
                    'name'         => (string) ($meta['title'] ?? 'New upload'),
                    'body'         => trim($text) !== '' ? $text : null,
                    'url'          => $imageUrl !== null ? $imageUrl : null,
                    'nsfw'         => $nsfw,
                    'honeypot'     => null,
                ],
            ]);

            if ($status !== 200) {
                return ['ok' => false, 'error' => 'Lemmy createPost failed (HTTP ' . $status . '): ' . $body];
            }

            $postId = (int) (json_decode($body, true)['post_view']['post']['id'] ?? 0);

            return ['ok' => true, 'url' => $this->base() . '/post/' . $postId];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function login(): array
    {
        [$status, , $body] = Http::request($this->base() . '/api/v3/user/login', [
            'method' => 'POST',
            'json' => [
                'username_or_email' => (string) ($this->config['username'] ?? ''),
                'password'          => (string) ($this->config['password'] ?? ''),
            ],
        ]);

        if ($status !== 200) {
            return ['ok' => false, 'error' => 'Lemmy login failed (HTTP ' . $status . ').'];
        }

        $jwt = (string) (json_decode($body, true)['jwt'] ?? '');

        return $jwt !== '' ? ['ok' => true, 'jwt' => $jwt] : ['ok' => false, 'error' => 'Lemmy returned no session.'];
    }

    private function resolveCommunity(string $jwt, string $community): ?int
    {
        $name = trim($community);

        // Accept "c/name@instance" or "name@instance" or a bare name.
        $name = preg_replace('#^c/#', '', $name) ?? $name;
        $name = trim(explode('@', $name)[0]);

        if ($name === '') {
            return null;
        }

        [$status, , $body] = Http::request($this->base() . '/api/v3/community?name=' . urlencode($name), [
            'headers' => ['Authorization' => 'Bearer ' . $jwt],
        ]);

        if ($status !== 200) {
            return null;
        }

        $id = (int) (json_decode($body, true)['community_view']['community']['id'] ?? 0);

        return $id > 0 ? $id : null;
    }

    private function base(): string
    {
        $instance = trim((string) ($this->config['instance'] ?? ''));

        return strpos($instance, 'http') === 0 ? rtrim($instance, '/') : 'https://' . $instance;
    }

    private function host(): string
    {
        return (string) parse_url($this->base(), PHP_URL_HOST);
    }
}