<?php

declare(strict_types=1);

namespace App\Models;

/**
 * DeviantArt API client for the Auto Poster. OAuth2 (authorization code +
 * refresh token, scopes "basic publish"); submits an image to Sta.sh and
 * publishes it as a deviation, flagged mature when configured. DeviantArt
 * prohibits explicit sexual material — use for artistic/softcore content.
 */
class DeviantArtClient
{
    private const AUTH_URL = 'https://www.deviantart.com/oauth2/authorize';
    private const TOKEN_URL = 'https://www.deviantart.com/oauth2/token';
    private const API_URL  = 'https://www.deviantart.com/api/v1/oauth2';

    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function isConfigured(): bool
    {
        return trim((string) ($this->config['client_id'] ?? '')) !== ''
            && trim((string) ($this->config['client_secret'] ?? '')) !== '';
    }

    public function isUserAuthorized(): bool
    {
        return $this->isConfigured() && trim((string) ($this->config['refresh_token'] ?? '')) !== '';
    }

    public function authorizationUrl(string $state, string $redirectUri): string
    {
        $params = http_build_query([
            'response_type' => 'code',
            'client_id'     => (string) $this->config['client_id'],
            'redirect_uri'  => $redirectUri,
            'scope'         => 'basic publish',
            'state'         => $state,
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
            return ['ok' => false, 'error' => 'DeviantArt token exchange failed (HTTP ' . $status . ').'];
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

        [$status, , $body] = Http::request(self::API_URL . '/placebo', [
            'form' => ['access_token' => $access['token']],
        ]);

        return $status === 200 ? ['ok' => true, 'note' => 'connected'] : ['ok' => false, 'error' => 'DeviantArt rejected the token (HTTP ' . $status . ').'];
    }

    public function post(string $text, array $media = [], array $meta = []): array
    {
        $access = $this->refreshAccess();
        if (!$access['ok']) {
            return $access;
        }

        $file = null;
        foreach ($media as $m) {
            if (!empty($m['tmp_name']) && is_file($m['tmp_name'])) {
                $file = $m;
                break;
            }
        }

        if ($file === null) {
            return ['ok' => false, 'error' => 'DeviantArt posts require an image file.'];
        }

        $sensitive = (bool) ($meta['sensitive'] ?? false);

        try {
            // 1) Submit the file into Sta.sh.
            [$status, , $body] = Http::request(self::API_URL . '/stash/submit', [
                'method' => 'POST',
                'multipart' => [
                    'access_token' => $access['token'],
                    'title'        => (string) ($meta['title'] ?? 'New upload'),
                    'description'  => trim($text),
                    'mature_content' => $sensitive ? '1' : '0',
                    'stash_media'  => [
                        'file' => $file['tmp_name'],
                        'name' => $file['name'] ?? basename($file['tmp_name']),
                        'type' => (string) ($file['type'] ?? mime_content_type($file['tmp_name']) ?: 'image/jpeg'),
                    ],
                ],
            ]);

            if ($status !== 200) {
                return ['ok' => false, 'error' => 'DeviantArt stash submit failed (HTTP ' . $status . '): ' . $body];
            }

            $stashId = (string) (json_decode($body, true)['stashid'] ?? '');
            if ($stashId === '') {
                return ['ok' => false, 'error' => 'DeviantArt returned no stash id.'];
            }

            // 2) Publish the stash item as a deviation.
            [$status, , $body] = Http::request(self::API_URL . '/stash/publish', [
                'method' => 'POST',
                'form' => [
                    'access_token'   => $access['token'],
                    'stashids'       => $stashId,
                    'title'          => (string) ($meta['title'] ?? 'New upload'),
                    'mature_content' => $sensitive ? '1' : '0',
                ],
            ]);

            if ($status !== 200) {
                return ['ok' => false, 'error' => 'DeviantArt publish failed (HTTP ' . $status . '): ' . $body];
            }

            $url = (string) (json_decode($body, true)['url'] ?? '');

            return ['ok' => true, 'url' => $url];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function refreshAccess(): array
    {
        $refresh = (string) ($this->config['refresh_token'] ?? '');
        if ($refresh === '') {
            return ['ok' => false, 'error' => 'DeviantArt is not authorized — complete the OAuth flow.'];
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
            return ['ok' => false, 'error' => 'DeviantArt refresh failed (HTTP ' . $status . ').'];
        }

        $token = (string) (json_decode($body, true)['access_token'] ?? '');

        return $token !== '' ? ['ok' => true, 'token' => $token] : ['ok' => false, 'error' => 'DeviantArt returned no access token.'];
    }
}