<?php

namespace App\Models;

/**
 * Reddit API client for the Auto Poster. Supports both an OAuth2
 * user-authorization code flow (which obtains a refresh token with the
 * "submit" scope needed to post to subreddits as a user) and a fallback
 * client-credentials token. Submits link, text or image posts to any
 * subreddit the authenticated account can post to.
 */
class RedditClient
{
    private const OAUTH_URL      = 'https://www.reddit.com/api/v1/access_token';
    private const AUTHORIZE_URL  = 'https://www.reddit.com/api/v1/authorize';
    private const API_URL        = 'https://oauth.reddit.com';
    private const REQUIRED_SCOPE = 'submit';

    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Whether the required client credentials are present.
     */
    public function isConfigured(): bool
    {
        return !empty($this->config['client_id'])
            && !empty($this->config['client_secret'])
            && !empty($this->config['username']);
    }

    /**
     * Whether a user refresh token has been stored (i.e. the user has
     * completed the authorization flow).
     */
    public function isUserAuthorized(): bool
    {
        return !empty($this->config['refresh_token']);
    }

    /**
     * Build the URL a user must visit to authorize the app. Returns the full
     * authorization URL that redirects the user to Reddit.
     */
    public function authorizationUrl(string $state, string $redirectUri): string
    {
        $params = [
            'client_id'    => $this->config['client_id'],
            'response_type' => 'code',
            'state'        => $state,
            'redirect_uri' => $redirectUri,
            'duration'     => 'permanent',
            'scope'        => self::REQUIRED_SCOPE,
        ];

        return self::AUTHORIZE_URL . '?' . http_build_query($params);
    }

    /**
     * Exchange an authorization code (from the Reddit callback) for a refresh
     * token and initial access token. Returns the tokens on success.
     *
     * @return array{ok:bool, refresh_token?:string, access_token?:string, error?:string}
     */
    public function exchangeCode(string $code, string $redirectUri): array
    {
        [$status, , $body] = Http::request(self::OAUTH_URL, [
            'method'  => 'POST',
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($this->config['client_id'] . ':' . $this->config['client_secret']),
                'User-Agent'    => $this->userAgent(),
            ],
            'form'    => [
                'grant_type'   => 'authorization_code',
                'code'         => $code,
                'redirect_uri' => $redirectUri,
            ],
        ]);

        $data = json_decode($body, true);

        if ($status !== 200 || empty($data['refresh_token'])) {
            return ['ok' => false, 'error' => $data['error'] ?? 'Reddit authorization failed (HTTP ' . $status . ').'];
        }

        return [
            'ok'            => true,
            'refresh_token' => $data['refresh_token'],
            'access_token'  => $data['access_token'] ?? '',
        ];
    }

    /**
     * Obtain an OAuth2 access token. When a user refresh token is present,
     * exchange it for a user-scoped access token (needed to submit posts).
     * Otherwise fall back to client-credentials (read-only).
     *
     * @return array{ok:bool, token?:string, error?:string}
     */
    private function token(): array
    {
        $form = ['grant_type' => 'client_credentials'];

        if ($this->isUserAuthorized()) {
            $form = [
                'grant_type'    => 'refresh_token',
                'refresh_token' => $this->config['refresh_token'],
            ];
        }

        [$status, , $body] = Http::request(self::OAUTH_URL, [
            'method'  => 'POST',
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($this->config['client_id'] . ':' . $this->config['client_secret']),
                'User-Agent'    => $this->userAgent(),
            ],
            'form'    => $form,
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
     * Submit a link, text or image post to a subreddit. $media is an optional
     * uploaded file array (keys: tmp_name, name, type) used for image posts.
     * Reddit supports one image per image post.
     *
     * @return array{ok:bool, url?:string, error?:string}
     */
    public function submit(string $subreddit, string $title, string $content, string $type = 'link', ?string $url = null, ?array $media = null): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'Reddit is not configured.'];
        }

        if (!$this->isUserAuthorized()) {
            return ['ok' => false, 'error' => 'Reddit is not user-authorized. Complete the authorization flow first.'];
        }

        if (!empty($media) && strpos($media['type'] ?? '', 'video/') === 0) {
            return ['ok' => false, 'error' => 'Reddit image posts do not support video uploads.'];
        }

        if ($type !== 'self' && empty($url) && empty($media)) {
            return ['ok' => false, 'error' => 'A link post requires a URL.'];
        }

        $t = $this->token();
        if (!$t['ok']) {
            return $t;
        }

        $form = [
            'sr'        => trim($subreddit, '/'),
            'title'     => $title,
            'resubmit'  => 'true',
            'api_type'  => 'json',
        ];

        if (!empty($media)) {
            // Image post: upload the image to Reddit's media asset, then submit
            // as an image post with the returned asset id.
            $asset = $this->uploadImage($t['token'], $media);
            if (!$asset['ok']) {
                return $asset;
            }
            $form['kind']   = 'image';
            $form['asset_id'] = $asset['asset_id'];
            $form['url']      = $asset['url'];
        } elseif ($type === 'self') {
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
     * Upload an image to Reddit's media asset endpoint and return the asset
     * id + public URL for use in an image post.
     *
     * @return array{ok:bool, asset_id?:string, url?:string, error?:string}
     */
    private function uploadImage(string $token, array $media): array
    {
        $path = $media['tmp_name'] ?? '';
        if (!is_file($path)) {
            return ['ok' => false, 'error' => 'Image file not found.'];
        }

        $filepath = $media['name'] ?? basename($path);
        $mimetype = $media['type'] ?? (mime_content_type($path) ?: 'image/jpeg');

        // Request an asset upload lease.
        [$leaseStatus, , $leaseBody] = Http::request(self::API_URL . '/api/media/asset', [
            'method'  => 'POST',
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'User-Agent'    => $this->userAgent(),
            ],
            'form'    => [
                'filepath'   => $filepath,
                'mimetype'   => $mimetype,
                'asset_type' => 'img',
            ],
        ]);

        $lease = json_decode($leaseBody, true);

        if ($leaseStatus !== 200 || empty($lease['args']) || empty($lease['asset']['asset_id'])) {
            return ['ok' => false, 'error' => 'Reddit could not start the image upload (HTTP ' . $leaseStatus . ').'];
        }

        $assetId = $lease['asset']['asset_id'];
        $fields  = $lease['args']['fields'] ?? [];

        // POST the image to the lease's upload URL.
        $uploadUrl = $lease['args']['action'] ?? '';
        $multipart = [];
        foreach ($fields as $f) {
            $name  = $f['name'] ?? '';
            $value = $f['value'] ?? '';
            if ($name === 'file') {
                $multipart['file'] = ['file' => $path, 'name' => $filepath, 'type' => $mimetype];
            } else {
                $multipart[$name] = $value;
            }
        }
        if (!isset($multipart['file'])) {
            $multipart['file'] = ['file' => $path, 'name' => $filepath, 'type' => $mimetype];
        }

        $uploadResult = Http::request($uploadUrl, [
            'method'    => 'POST',
            'headers'   => ['User-Agent' => $this->userAgent()],
            'multipart' => $multipart,
        ]);

        // Confirm the upload is complete.
        [, , $confirmBody] = Http::request(self::API_URL . '/api/media/asset/upload', [
            'method'  => 'POST',
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'User-Agent'    => $this->userAgent(),
            ],
            'json'    => [
                'asset_id' => $assetId,
            ],
        ]);

        $confirm = json_decode($confirmBody, true);

        return [
            'ok'       => true,
            'asset_id' => $confirm['asset']['asset_id'] ?? $assetId,
            'url'      => $confirm['asset']['websocket_url'] ?? $confirm['asset']['asset_id'] ?? '',
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
