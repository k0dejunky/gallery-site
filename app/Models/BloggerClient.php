<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Blogger (Google) API v3 client for the Auto Poster. OAuth2 (authorization
 * code + refresh token) with the Blogger scope; creates HTML posts on an
 * adult-flagged blog. Used as the SEO/promo layer.
 */
class BloggerClient
{
    private const AUTH_URL   = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL  = 'https://oauth2.googleapis.com/token';
    private const API_URL    = 'https://www.googleapis.com/blogger/v3';

    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function isConfigured(): bool
    {
        return trim((string) ($this->config['blog_id'] ?? '')) !== ''
            && trim((string) ($this->config['client_id'] ?? '')) !== ''
            && trim((string) ($this->config['client_secret'] ?? '')) !== '';
    }

    public function isUserAuthorized(): bool
    {
        return $this->isConfigured() && trim((string) ($this->config['refresh_token'] ?? '')) !== '';
    }

    public function authorizationUrl(string $state, string $redirectUri): string
    {
        $params = http_build_query([
            'response_type'          => 'code',
            'client_id'              => (string) $this->config['client_id'],
            'redirect_uri'           => $redirectUri,
            'scope'                  => 'https://www.googleapis.com/auth/blogger',
            'access_type'            => 'offline',
            'prompt'                 => 'consent',
            'include_granted_scopes' => 'true',
            'state'                  => $state,
        ]);

        return self::AUTH_URL . '?' . $params;
    }

    public function exchangeCode(string $code, string $redirectUri): array
    {
        [$status, , $body] = Http::request(self::TOKEN_URL, [
            'method' => 'POST',
            'form' => [
                'code'          => $code,
                'client_id'     => (string) $this->config['client_id'],
                'client_secret' => (string) $this->config['client_secret'],
                'redirect_uri'  => $redirectUri,
                'grant_type'    => 'authorization_code',
            ],
        ]);

        if ($status !== 200) {
            return ['ok' => false, 'error' => 'Blogger token exchange failed (HTTP ' . $status . ').'];
        }

        $data = json_decode($body, true);

        return [
            'ok'            => true,
            'refresh_token' => (string) ($data['refresh_token'] ?? ''),
            'access_token'  => (string) ($data['access_token'] ?? ''),
        ];
    }

    public function ping(): array
    {
        $access = $this->refreshAccess();

        if (!$access['ok']) {
            return $access;
        }

        [$status, , $body] = Http::request(self::API_URL . '/blogs/' . urlencode((string) $this->config['blog_id']), [
            'headers' => ['Authorization' => 'Bearer ' . $access['token']],
        ]);

        if ($status !== 200) {
            return ['ok' => false, 'error' => 'Blogger rejected the token (HTTP ' . $status . ').'];
        }

        $name = (string) (json_decode($body, true)['name'] ?? $this->config['blog_id']);

        return ['ok' => true, 'note' => $name];
    }

    public function post(string $text, array $media = [], array $meta = []): array
    {
        $access = $this->refreshAccess();
        if (!$access['ok']) {
            return $access;
        }

        try {
            $title = (string) ($meta['title'] ?? 'New upload');
            $body  = trim($text);
            if ($body === '') {
                $body = '<p>' . $title . '</p>';
            }

            // Wrap plain text into paragraphs so the blog post renders cleanly.
            if (strpos($body, '<') === false) {
                $paragraphs = preg_split('/\n{2,}/', $body) ?: [];
                $body = implode('', array_map(static fn (string $p): string => '<p>' . trim($p) . '</p>', array_filter($paragraphs)));
            }

            [$status, , $resp] = Http::request(self::API_URL . '/blogs/' . urlencode((string) $this->config['blog_id']) . '/posts', [
                'method' => 'POST',
                'headers' => ['Authorization' => 'Bearer ' . $access['token']],
                'json' => [
                    'kind'    => 'blogger#post',
                    'title'   => $title,
                    'content' => $body,
                ],
            ]);

            if ($status !== 200) {
                return ['ok' => false, 'error' => 'Blogger post failed (HTTP ' . $status . '): ' . $resp];
            }

            $url = (string) (json_decode($resp, true)['url'] ?? '');

            return ['ok' => true, 'url' => $url];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function refreshAccess(): array
    {
        $refresh = (string) ($this->config['refresh_token'] ?? '');
        if ($refresh === '') {
            return ['ok' => false, 'error' => 'Blogger is not authorized — complete the OAuth flow.'];
        }

        [$status, , $body] = Http::request(self::TOKEN_URL, [
            'method' => 'POST',
            'form' => [
                'refresh_token' => $refresh,
                'client_id'     => (string) $this->config['client_id'],
                'client_secret' => (string) $this->config['client_secret'],
                'grant_type'    => 'refresh_token',
            ],
        ]);

        if ($status !== 200) {
            return ['ok' => false, 'error' => 'Blogger refresh failed (HTTP ' . $status . ').'];
        }

        $token = (string) (json_decode($body, true)['access_token'] ?? '');

        return $token !== '' ? ['ok' => true, 'token' => $token] : ['ok' => false, 'error' => 'Blogger returned no access token.'];
    }
}