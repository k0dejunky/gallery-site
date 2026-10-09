<?php

namespace App\Models;

/**
 * Reddit API client for the Auto Poster. Supports both an OAuth2
 * user-authorization code flow (which obtains a refresh token with the
 * "submit" scope needed to post to subreddits as a user, plus "identity" so
 * the /api/v1/me health check can confirm the authorizing account) and a
 * fallback client-credentials token. Submits link, text or image posts to any
 * subreddit the authenticated account can post to.
 */
class RedditClient
{
    private const OAUTH_URL      = 'https://www.reddit.com/api/v1/access_token';
    private const AUTHORIZE_URL  = 'https://www.reddit.com/api/v1/authorize';
    private const API_URL        = 'https://oauth.reddit.com';
    private const REQUIRED_SCOPE = 'submit identity';

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
     * Publish through the generic platform dispatch (AutoPostQueue::post).
     * Title/body channels arrive pre-split: the title rides in $meta['title']
     * and $text is the body (falling back to splitForReddit() when it does
     * not). Reddit allows one image per image post, so only the first usable
     * file is attached; without media the post is a self/text post.
     *
     * @param array<int, array{tmp_name: string, name: string, type: string}> $media
     * @return array{ok:bool, url?:string, error?:string}
     */
    public function post(string $text, array $media = [], array $meta = []): array
    {
        $sub = self::cleanSubreddit((string) ($this->config['subreddit'] ?? ''));
        if ($sub === '') {
            return ['ok' => false, 'error' => 'Reddit has no target subreddit configured.'];
        }

        $title = trim((string) ($meta['title'] ?? ''));
        if ($title === '') {
            [$title, $text] = self::splitForReddit($text);
        }

        $file = null;
        foreach ($media as $m) {
            if (!empty($m['tmp_name']) && is_file((string) $m['tmp_name'])) {
                $file = $m;
                break;
            }
        }

        // Share-button method: when the API is not user-authorized but the
        // admin enabled the browser fallback, post exactly like the on-site
        // "Share on Reddit" button (headless-browser submit of the gallery
        // link or image to the target subreddit).
        if (!$this->isUserAuthorized() && ($this->config['browser_enabled'] ?? '') === '1') {
            return $this->postViaBrowser($sub, $title, (string) ($meta['url'] ?? ''), $file);
        }

        if ($file !== null) {
            return $this->submit($sub, $title, '', 'image', null, $file);
        }

        return $this->submit($sub, $title, trim($text), 'self');
    }

    /**
     * Post through the headless-browser worker (bin/reddit_post.php ->
     * bin/browser/reddit-post.mjs). Shares the gallery link or uploads the
     * image to /r/<sub>/submit and clicks Post, reusing the saved session.
     *
     * @param array|null $file an uploaded file array (tmp_name/name/type) or null
     * @return array{ok:bool, url?:string, error?:string}
     */
    private function postViaBrowser(string $sub, string $title, string $shareUrl, ?array $file): array
    {
        $bridge = dirname(__DIR__, 2) . '/bin/reddit_post.php';
        if (!is_file($bridge)) {
            return ['ok' => false, 'error' => 'Reddit browser worker is not installed on this server.'];
        }

        $payload = [
            'mode'      => $file !== null ? 'image' : 'link',
            'subreddit' => $sub,
            'title'     => $title,
        ];

        if ($file !== null) {
            $payload['imagePath'] = (string) $file['tmp_name'];
        } else {
            $payload['url'] = $shareUrl !== '' ? $shareUrl : absolute_url('/');
        }

        if (!empty($this->config['username'])) {
            $payload['username'] = (string) $this->config['username'];
        }
        if (!empty($this->config['password'])) {
            $payload['password'] = (string) $this->config['password'];
        }

        $tmp = @tempnam(sys_get_temp_dir(), 'reddit');
        if ($tmp === false || @file_put_contents($tmp, json_encode($payload)) === false) {
            return ['ok' => false, 'error' => 'Could not stage the Reddit browser payload.'];
        }

        $cmd   = 'php ' . escapeshellarg($bridge) . ' ' . escapeshellarg($tmp);
        $bytes = @exec($cmd . ' 2>&1', $outLines, $rc);
        @unlink($tmp);

        if ($bytes === false) {
            $outLines = [];
        }

        $raw = implode("\n", (array) $outLines);
        $res = json_decode(trim($raw), true);

        if (!is_array($res)) {
            return [
                'ok'    => false,
                'error' => 'Reddit browser worker returned no result (rc ' . $rc . '): ' . mb_substr($raw, 0, 220),
            ];
        }

        return [
            'ok'    => (bool) ($res['ok'] ?? false),
            'url'   => isset($res['url']) ? (string) $res['url'] : null,
            'error' => isset($res['error']) ? (string) $res['error'] : null,
        ];
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
     * Lightweight connectivity + authorization check through the v1 oauth
     * /me endpoint. Used by the admin "API health" section; never posts.
     *
     * @return array{ok:bool, status?:int, latency_ms?:int, note?:string, error?:string}
     */
    public function ping(): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'Reddit is not configured.'];
        }

        $t = $this->token();
        if (!$t['ok']) {
            return ['ok' => false, 'error' => $t['error'] ?? 'Reddit is not authorized.'];
        }

        $start = hrtime(true);
        [$status, , $body] = Http::request(self::API_URL . '/api/v1/me', [
            'headers' => [
                'Authorization' => 'Bearer ' . $t['token'],
                'User-Agent'    => $this->userAgent(),
            ],
        ]);
        $latency = (int) round((hrtime(true) - $start) / 1e6);

        $data = json_decode($body, true);

        if ($status === 200 && !empty($data['name'])) {
            return ['ok' => true, 'status' => $status, 'latency_ms' => $latency, 'note' => 'connected as u/' . $data['name']];
        }

        $error = $data['message'] ?? '';
        return ['ok' => false, 'status' => $status, 'latency_ms' => $latency, 'error' => $error !== '' ? $error : 'Reddit API check failed (HTTP ' . $status . ').'];
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
     * Split the X-style post text into a Reddit title and self-text body.
     *
     * The queue text is "<title> — <description> #hashtags CTA". Reddit titles
     * are <= 300 chars and shouldn't carry hashtag walls, so the part before
     * the first " — " becomes the title (cleaned + truncated), and the rest
     * (description + hashtags + CTA + link) becomes the self-text body.
     *
     * @return array{0: string, 1: string}
     */
    public static function splitForReddit(string $text): array
    {
        $text = trim((string) $text);

        $title     = $text;
        $remainder = '';
        if (($pos = strpos($text, '—')) !== false) {
            $title     = trim(mb_substr($text, 0, $pos));
            $remainder = trim(mb_substr($text, $pos + 1));
        }

        // Drop hashtag-style noise that reads badly at the end of a title.
        $cleanTitle = trim((string) preg_replace('/\s+#[A-Za-z0-9_]+\b/u', ' ', $title));
        $cleanTitle = rtrim((string) preg_replace('/\s+/u', ' ', $cleanTitle));

        if (mb_strlen($cleanTitle) > 300) {
            $cleanTitle = rtrim(mb_substr($cleanTitle, 0, 299)) . '…';
        }
        if ($cleanTitle === '') {
            $cleanTitle = 'New upload';
        }

        $body = trim($remainder);
        $body = (string) preg_replace('/\s+/u', ' ', $body);
        if ($body !== '') {
            $body .= "\n\n";
        }
        $body .= '[View on site](https://' . AutoPostQueue::POST_DOMAIN . ')';

        return [$cleanTitle, $body];
    }

    /**
     * Normalize a subreddit name (strip r/ prefix, whitespace, url-id chars).
     */
    public static function cleanSubreddit(string $sub): string
    {
        $sub = trim((string) preg_replace('#^r/#i', '', trim($sub)));
        $sub = (string) preg_replace('/[^A-Za-z0-9_]/', '', $sub);

        return mb_substr($sub, 0, 21);
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
