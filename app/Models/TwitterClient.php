<?php

namespace App\Models;

/**
 * Minimal X (formerly Twitter) API client for the Auto Poster. Uses an OAuth2
 * bearer token (from the X developer portal) to create posts via the v2 API.
 * Supports attaching up to 4 images or 1 video by uploading them through the
 * v1.1 media/upload endpoint (with chunking for large files), then posting a
 * tweet that references the uploaded media_ids.
 *
 * Per-site limits (v2 API):
 *   - Up to 4 images per tweet, or 1 video.
 *   - Images: max 5 MB each; supported types jpg/png/webp/gif (animated gif ok).
 *   - Video: max 512 MB (standard account 15 MB, up to 2:20 duration).
 */
class TwitterClient
{
    private const API_URL      = 'https://api.x.com/2';
    private const MEDIA_URL    = 'https://upload.x.com/1.1/media/upload.json';
    private const OAUTH_TOKEN_URL  = 'https://api.x.com/2/oauth2/token';
    private const AUTHORIZE_URL    = 'https://x.com/i/oauth2/authorize';
    private const SCOPES = 'tweet.read tweet.write users.read offline.access';
    private const CHUNK_SIZE   = 5242880; // 5 MB per APPEND chunk
    private const MAX_IMAGES   = 4;
    private const MAX_IMAGE_BYTES = 5242880; // 5 MB
    private const MAX_VIDEO_BYTES = 536870912; // 512 MB

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
        return !empty($this->config['client_id']);
    }

    /**
     * Whether an owned-account refresh token has been stored, i.e. the user has
     * completed the OAuth2 authorization flow to let this app post on their
     * behalf. Auto-posting to X requires this (the app must be approved for
     * Read & Write with the automated-app OAuth2 flow).
     */
    public function isUserAuthorized(): bool
    {
        return !empty($this->config['refresh_token']);
    }

    /**
     * OAuth2 Enhanced-BC with Proof Key for Code Exchange (PKCE) is the flow X
     * requires for apps that automate posting on behalf of a single account.
     * Returns the URL the admin must visit to authorize this app.
     */
    public function authorizationUrl(string $state, string $codeChallenge, string $redirectUri): string
    {
        $params = [
            'response_type'         => 'code',
            'client_id'             => $this->config['client_id'],
            'redirect_uri'          => $redirectUri,
            'scope'                 => self::SCOPES,
            'state'                 => $state,
            'code_challenge'        => $codeChallenge,
            'code_challenge_method' => 'S256',
        ];

        return self::AUTHORIZE_URL . '?' . http_build_query($params);
    }

    /**
     * Exchange the authorization code (returned on the callback) for a refresh
     * token and initial access token. Uses the client credentials (Basic auth)
     * to identify the app.
     *
     * @return array{ok:bool, refresh_token?:string, access_token?:string, error?:string}
     */
    public function exchangeCode(string $code, string $codeVerifier, string $redirectUri): array
    {
        return $this->tokenRequest([
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $redirectUri,
            'code_verifier' => $codeVerifier,
        ]);
    }

    /**
     * Obtain an OAuth2 access token for the authorized account. Uses the saved
     * refresh token, renewing it when the response rotates it.
     *
     * @return array{ok:bool, token?:string, error?:string}
     */
    private function token(): array
    {
        if (!$this->isUserAuthorized()) {
            return ['ok' => false, 'error' => 'X is not user-authorized. Complete the authorization flow first.'];
        }

        $result = $this->tokenRequest([
            'grant_type'    => 'refresh_token',
            'refresh_token' => $this->config['refresh_token'],
        ]);

        if (!$result['ok']) {
            return $result;
        }

        // X rotates the refresh token on each use; persist the new one so the
        // next post keeps working.
        if (!empty($result['refresh_token']) && $result['refresh_token'] !== $this->config['refresh_token']) {
            AutoPosterConfig::saveTwitterToken($result['refresh_token'], $result['access_token'] ?? '');
        }

        return ['ok' => true, 'token' => $result['access_token'] ?? ''];
    }

    /**
     * POST to X's OAuth2 token endpoint. Adds the app's Basic auth header, the
     * x-api-key header and the client_id form parameter X requires, then
     * returns the normalized result array.
     */
    private function tokenRequest(array $form): array
    {
        $auth = base64_encode($this->config['client_id'] . ':' . ($this->config['client_secret'] ?? ''));

        [$status, , $body] = Http::request(self::OAUTH_TOKEN_URL, [
            'method'  => 'POST',
            'headers' => [
                'Authorization' => 'Basic ' . $auth,
                'x-api-key'     => $this->config['client_id'],
            ],
            'form' => array_merge(['client_id' => $this->config['client_id']], $form),
        ]);

        if ($status < 200 || $status >= 300) {
            $detail = json_decode($body, true);
            $msg = is_array($detail)
                ? ($detail['error_description'] ?? $detail['error'] ?? '')
                : '';
            return ['ok' => false, 'error' => $msg !== '' ? $msg : 'X OAuth failed (HTTP ' . $status . ').'];
        }

        $data = json_decode($body, true);

        if (empty($data['refresh_token']) && empty($data['access_token'])) {
            return ['ok' => false, 'error' => 'X returned no OAuth token.'];
        }

        return [
            'ok'            => true,
            'refresh_token' => $data['refresh_token'] ?? '',
            'access_token'  => $data['access_token'] ?? '',
        ];
    }

    /**
     * Create a post (tweet), optionally with attached media.
     *
     * $media is an array of uploaded files: each item has keys 'tmp_name'
     * (path), 'name' (original filename) and 'size' (bytes). Returns the tweet
     * id/url on success.
     *
     * @return array{ok:bool, id?:string, url?:string, error?:string}
     */
    public function post(string $content, array $media = []): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'X/Twitter is not configured.'];
        }

        $auth = $this->token();
        if (!$auth['ok']) {
            return ['ok' => false, 'error' => $auth['error'] ?? 'X is not authorized.'];
        }
        $token = $auth['token'];

        $text = mb_substr(trim($content), 0, 280);
        if ($text === '') {
            return ['ok' => false, 'error' => 'Post text cannot be empty.'];
        }

        $limitError = $this->validateMedia($media);
        if ($limitError !== null) {
            return ['ok' => false, 'error' => $limitError];
        }

        $payload = ['text' => $text];

        if (!empty($media)) {
            $mediaIds = [];
            foreach ($media as $file) {
                $upload = $this->uploadMedia($file, $token);
                if (!$upload['ok']) {
                    return $upload;
                }
                $mediaIds[] = $upload['media_id'];
            }
            $payload['media'] = ['media_ids' => $mediaIds];
        }

        [$status, , $body] = Http::request(self::API_URL . '/tweets', [
            'method'  => 'POST',
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'json'    => $payload,
        ]);

        $data = json_decode($body, true);

        if ($status !== 201) {
            $detail = $data['detail'] ?? '';
            if (empty($detail) && isset($data['errors'][0]['message'])) {
                $detail = $data['errors'][0]['message'];
            }
            return ['ok' => false, 'error' => $detail !== '' ? $detail : 'X/Twitter rejected the post (HTTP ' . $status . ').'];
        }

        $tweetId = $data['data']['id'] ?? '';

        return [
            'ok'  => true,
            'id'  => $tweetId,
            'url' => $tweetId !== '' ? 'https://x.com/i/status/' . $tweetId : '',
        ];
    }

    /**
     * Validate the uploaded media against X per-tweet limits. Returns an error
     * message or null when acceptable.
     */
    private function validateMedia(array $media): ?string
    {
        if (empty($media)) {
            return null;
        }

        $images = 0;
        $videos = 0;

        foreach ($media as $file) {
            $size = (int) ($file['size'] ?? filesize($file['tmp_name'] ?? '') ?: 0);
            $type = $file['type'] ?? '';

            if (strpos($type, 'image/') === 0) {
                $images++;
                if ($size > self::MAX_IMAGE_BYTES) {
                    return 'X images must be 5 MB or smaller.';
                }
            } elseif (strpos($type, 'video/') === 0) {
                $videos++;
                if ($size > self::MAX_VIDEO_BYTES) {
                    return 'X videos must be 512 MB or smaller.';
                }
            } else {
                return 'X only supports image or video attachments.';
            }
        }

        if ($videos > 1) {
            return 'X supports at most 1 video per tweet.';
        }
        if ($videos === 1 && $images > 0) {
            return 'X does not allow mixing images and video in one tweet.';
        }
        if ($images > self::MAX_IMAGES) {
            return 'X supports at most ' . self::MAX_IMAGES . ' images per tweet.';
        }

        return null;
    }

    /**
     * Upload a single file to X, returning its media_id. Uses the INIT /
     * APPEND / FINALIZE flow with chunking for files larger than the chunk size.
     *
     * @return array{ok:bool, media_id?:string, error?:string}
     */
    private function uploadMedia(array $file, string $token): array
    {
        $path = $file['tmp_name'] ?? '';
        if (!is_file($path)) {
            return ['ok' => false, 'error' => 'Media file not found.'];
        }

        $total = (int) filesize($path);
        $mime  = $file['type'] ?? (mime_content_type($path) ?: 'application/octet-stream');
        $isVideo = strpos($mime, 'video/') === 0;

        $headers = [
            'Authorization' => 'Bearer ' . $token,
            'x-api-key'     => $this->config['client_id'],
        ];

        // INIT
        [$initStatus, $initHeaders, $initBody] = Http::request(self::MEDIA_URL, [
            'method'  => 'POST',
            'headers' => $headers,
            'form'    => [
                'command'     => 'INIT',
                'total_bytes' => (string) $total,
                'media_type'  => $mime,
                'media_category' => $isVideo ? 'tweet_video' : 'tweet_image',
            ],
        ]);

        $initData = json_decode($initBody, true);
        if ($initStatus !== 200 || empty($initData['media_id_string'])) {
            $message = $initData['errors'][0]['message'] ?? null;
            if ($message === null) {
                // X often returns a bare status with no JSON body (e.g. the
                // silent 403 it gives when the account/app has media uploads
                // restricted, while text posts still succeed). Pass the raw
                // response through so the failure is diagnosable.
                $rate   = trim((string) ($initHeaders['x-rate-limit-remaining'] ?? ''));
                $hint   = $initStatus === 403 ? ' (media upload restricted for account/app)' : '';
                $detail = trim($initBody) !== '' ? ' body=' . mb_substr($initBody, 0, 400) : '';
                $message = 'X media INIT failed (HTTP ' . $initStatus . $hint . $detail . ')'
                    . ($rate !== '' ? ' remaining=' . $rate : '');
            }
            return ['ok' => false, 'error' => $message];
        }

        $mediaId = $initData['media_id_string'];

        // APPEND (chunked)
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return ['ok' => false, 'error' => 'Could not read media file.'];
        }

        $segmentIndex = 0;
        while (!feof($handle)) {
            $chunk = fread($handle, self::CHUNK_SIZE);
            if ($chunk === false) {
                fclose($handle);
                return ['ok' => false, 'error' => 'Could not read media chunk.'];
            }

            // Write the chunk to a temp file so multipart can send it as a file.
            $tmp = tempnam(sys_get_temp_dir(), 'xmedia');
            file_put_contents($tmp, $chunk);

            [, , $appendBody] = Http::request(self::MEDIA_URL, [
                'method'  => 'POST',
                'headers' => $headers,
                'multipart' => [
                    'command'       => 'APPEND',
                    'media_id'      => $mediaId,
                    'segment_index' => (string) $segmentIndex,
                    'media'         => ['file' => $tmp, 'name' => 'media', 'type' => 'application/octet-stream'],
                ],
            ]);

            @unlink($tmp);

            $appendData = json_decode($appendBody, true);
            if (!empty($appendData['errors'])) {
                fclose($handle);
                return ['ok' => false, 'error' => $appendData['errors'][0]['message'] ?? 'X media APPEND failed.'];
            }

            $segmentIndex++;
        }
        fclose($handle);

        // FINALIZE
        [$finStatus, , $finBody] = Http::request(self::MEDIA_URL, [
            'method'  => 'POST',
            'headers' => $headers,
            'form'    => [
                'command'  => 'FINALIZE',
                'media_id' => $mediaId,
            ],
        ]);

        $finData = json_decode($finBody, true);
        if ($finStatus !== 200) {
            return ['ok' => false, 'error' => $finData['errors'][0]['message'] ?? 'X media FINALIZE failed (HTTP ' . $finStatus . ').'];
        }

        return ['ok' => true, 'media_id' => $mediaId];
    }
}
