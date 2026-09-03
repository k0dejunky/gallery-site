<?php

namespace App\Models;

/**
 * Sends Auto Poster queue items to a Devvit Web app, which does the actual
 * submission into the site's subreddit.
 *
 * REDDIT PULLED SELF-SERVE API ACCESS (Responsible Builder Policy, Nov 2025),
 * so the site can no longer post via the OAuth /api/submit endpoint (new apps
 * need manual approval that is effectively refused for this content type).
 * Instead, a Devvit Web app installed into the site's subreddit receives the
 * post and submits it on Reddit's side, sidestepping the OAuth wall (subject
 * to Reddit/Devvit NSFW policy).
 *
 * TRANSPORT: Devvit's "External Endpoints" capability. The app declares an
 * endpoint (e.g. /external/on/publish) and the server POSTs to its public URL
 * authenticating with a long-lived managed token in the Authorization header:
 *
 *     POST https://<app-slug>-<subreddit-id>-external.devvit.net/external/<path>
 *     Authorization: bearer devvit_at_...
 *
 * Devvit runs on Reddit's infrastructure, so it cannot read this server's
 * filesystem: images are binned into the JSON payload as base64 and Devvit
 * uploads them via media.upload() before submitting. The call is synchronous
 * and mirrors TwitterClient::post() — Devvit returns {ok, postId, url, error}
 * in the HTTP response, which AutoPostQueue::post() records via its normal
 * markPosted/markFailed path.
 */
class RedditBridge
{
    /**
     * Send one queue item to the Devvit bridge and await the outcome. Returns
     * the same shape as TwitterClient::post() so AutoPostQueue::post() can
     * record it identically. On success the returned url is the Reddit post.
     *
     * @param array<int, array{tmp_name: string, name: string, type: string}> $media
     * @return array{ok: bool, url?: string, error?: string}
     */
    public static function publish(string $text, array $media, array $config): array
    {
        $endpoint = trim((string) ($config['devvit_endpoint'] ?? ''));
        $token    = (string) ($config['bridge_secret'] ?? ''); // managed token (devvit_at_...)
        $sub      = self::cleanSubreddit((string) ($config['subreddit'] ?? ''));

        if ($endpoint === '' || $token === '') {
            return ['ok' => false, 'error' => 'Reddit bridge is not configured (set devvit_endpoint + bridge_secret).'];
        }

        if ($sub === '') {
            return ['ok' => false, 'error' => 'Reddit bridge has no target subreddit configured.'];
        }

        [$title, $body] = self::splitForReddit($text);

        // Only the first image is used: Reddit media posts allow a single image.
        $images = [];
        foreach ($media as $m) {
            $path = (string) ($m['tmp_name'] ?? '');
            if (!is_file($path)) {
                continue;
            }
            $data = base64_encode((string) file_get_contents($path));
            if ($data === '') {
                continue;
            }
            $images[] = [
                'name' => (string) ($m['name'] ?? basename($path)),
                'type' => (string) ($m['type'] ?? (mime_content_type($path) ?: 'image/png')),
                'b64'  => $data,
            ];
            break; // single image per Reddit media post
        }

        $payload = [
            'subreddit' => $sub,
            'title'     => $title,
            'body'      => $body,
            'image'     => $images[0] ?? null, // image or null => self text post
            'nsfw'      => true,
            'siteUrl'   => 'https://' . AutoPostQueue::POST_DOMAIN,
        ];

        [$status, , $response] = Http::request($endpoint, [
            'method'  => 'POST',
            'headers' => [
                'Authorization' => 'bearer ' . $token,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
            'json'    => $payload,
            'timeout' => 90,
        ]);

        $data = json_decode($response, true);

        if ($status >= 200 && $status < 300 && !empty($data['ok'])) {
            $postId = (string) ($data['postId'] ?? '');
            $url    = $postId !== ''
                ? 'https://www.reddit.com/r/' . $sub . '/comments/' . $postId
                : (string) ($data['url'] ?? 'https://www.reddit.com/r/' . $sub . '/');

            return ['ok' => true, 'url' => $url];
        }

        $error = (string) ($data['error'] ?? '');
        if (trim($error) === '') {
            $error = 'Devvit external endpoint returned HTTP ' . $status;
        }

        return ['ok' => false, 'error' => 'Reddit: ' . $error];
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
    private static function splitForReddit(string $text): array
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
        $body .= '[View on site](' . 'https://' . AutoPostQueue::POST_DOMAIN . ')';

        return [$cleanTitle, $body];
    }

    /**
     * Normalize a subreddit name (strip r/ prefix, whitespace, url-id chars).
     */
    private static function cleanSubreddit(string $sub): string
    {
        $sub = trim((string) preg_replace('#^r/#i', '', trim($sub)));
        $sub = (string) preg_replace('/[^A-Za-z0-9_]/', '', $sub);

        return mb_substr($sub, 0, 21);
    }
}
