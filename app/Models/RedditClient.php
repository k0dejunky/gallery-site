<?php

namespace App\Models;

/**
 * Minimal Reddit API client for the Auto Poster. Uses OAuth2 client-credential
 * flow to obtain a bearer token, then submits a link or text post to any
 * subreddit the authenticated account can post to.
 */
class RedditClient
{
    private const OAUTH_URL = 'https://www.reddit.com/api/v1/access_token';
    private const API_URL   = 'https://oauth.reddit.com';

    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Whether the required credentials are present.
     */
    public function isConfigured(): bool
    {
        return !empty($this->config['client_id'])
            && !empty($this->config['client_secret'])
            && !empty($this->config['username']);
    }

    /**
     * Obtain an OAuth2 access token for the configured app.
     *
     * @return array{ok:bool, token?:string, error?:string}
     */
    private function token(): array
    {
        [$status, , $body] = Http::request(self::OAUTH_URL, [
            'method'  => 'POST',
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($this->config['client_id'] . ':' . $this->config['client_secret']),
                'User-Agent'    => $this->userAgent(),
            ],
            'form'    => [
                'grant_type' => 'client_credentials',
            ],
        ]);

        if ($status !== 200) {
            return ['ok' => false, 'error' => 'Reddit OAuth failed (HTTP ' . $status . ').'];
        }

        $data = json_decode($body, true);

        if (empty($data['access_token'])) {
            return ['ok' => false, 'error' => 'Reddit returned no access token.'];
        }

        return ['ok' => true, 'token' => $data['access_token']];
    }

    /**
     * Submit a link or text post to a subreddit. Returns the created post URL
     * on success.
     *
     * @return array{ok:bool, url?:string, error?:string}
     */
    public function submit(string $subreddit, string $title, string $content, string $type = 'link', ?string $url = null): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'Reddit is not configured.'];
        }

        if ($type !== 'self' && empty($url)) {
            return ['ok' => false, 'error' => 'A link post requires a URL.'];
        }

        $t = $this->token();
        if (!$t['ok']) {
            return $t;
        }

        $form = [
            'sr'      => trim($subreddit, '/'),
            'title'   => $title,
            'resubmit' => 'true',
            'api_type' => 'json',
        ];

        if ($type === 'self') {
            $form['kind'] = 'self';
            $form['text'] = $content;
        } else {
            $form['kind'] = 'link';
            $form['url']  = $url;
        }

        [$status, , $body] = Http::request(self::API_URL . '/api/submit', [
            'method'  => 'POST',
            'headers' => [
                'Authorization' => 'Bearer ' . $t['token'],
                'User-Agent'    => $this->userAgent(),
            ],
            'form'    => $form,
        ]);

        $data = json_decode($body, true);

        if ($status !== 200 || !empty($data['json']['errors']) || empty($data['json']['data']['id'])) {
            $error = $data['json']['errors'][0][1] ?? ($data['json']['data']['reason'] ?? 'Reddit rejected the post (HTTP ' . $status . ').');
            return ['ok' => false, 'error' => $error];
        }

        $postId = $data['json']['data']['id'] ?? '';
        $sub    = ltrim(trim($subreddit), '/');

        return [
            'ok'  => true,
            'url' => 'https://www.reddit.com/r/' . $sub . '/comments/' . $postId,
        ];
    }

    /**
     * The user-agent Reddit requires (a unique descriptive UA).
     */
    private function userAgent(): string
    {
        $app = $this->config['app_name'] ?? 'gallery-auto-poster';
        $ver = $this->config['app_version'] ?? '1.0';

        return $app . ':' . $ver . ' (by /u/' . $this->config['username'] . ')';
    }
}
