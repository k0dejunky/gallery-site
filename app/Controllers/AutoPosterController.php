<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Models\AutoPosterConfig;
use App\Models\AuditLog;
use App\Models\AutoPostQueue;
use App\Models\RedditClient;
use App\Models\TwitterClient;
use DateTimeZone;

class AutoPosterController extends Controller
{
    public function __construct(Request $request)
    {
        parent::__construct($request);
        Auth::requirePermission('autoposter');
    }

    /**
     * Show the X (Twitter) Auto Poster page: X template, X credentials, Post
     * to X, recommended posts, the X queue and the X posting log.
     */
    public function index(): void
    {
        $this->renderPage('x');
    }

    /**
     * Show the Reddit Auto Poster page: Reddit template, Reddit credentials,
     * Post to Reddit, the Reddit queue and the Reddit posting log.
     */
    public function reddit(): void
    {
        $this->renderPage('reddit');
    }

    /**
     * Build the Auto Poster page for one platform. The queue, recent posts,
     * status counts and posting log are all scoped to that platform, and the
     * template panel shows only that platform's blueprint.
     */
    private function renderPage(string $platform): void
    {
        $isX       = $platform !== 'reddit';
        $queueKey  = $isX ? 'twitter' : 'reddit';
        $template  = AutoPostQueue::templateSettings($platform);

        $this->viewAdmin('auto_poster', [
            'platform'        => $isX ? 'x' : 'reddit',
            'config'          => AutoPosterConfig::all(),
            'apTemplate'      => $template,
            'templatePreview' => AutoPostQueue::buildText([
                'gallery_title' => 'Example gallery',
                'caption'       => 'Fresh uploads',
            ], ['amateur', 'redhead', 'new'], $template),
            'recommended'     => AutoPostQueue::recommendations(8, $isX ? 'x' : 'reddit'),
            'queue'           => AutoPostQueue::queued(0, $queueKey),
            'queueCounts'     => AutoPostQueue::statusCounts($queueKey),
            'recentPosts'     => AutoPostQueue::recentPosts(20, $queueKey),
            'log'             => AutoPosterConfig::logEntries(100, $queueKey),
        ]);
    }

    /**
     * Save the current page's auto-post template settings that shape the
     * wording, links, hashtag count and other generation knobs of every post
     * on that platform. The platform is picked from the page's hidden field.
     */
    public function saveTemplate(): void
    {
        $platform = $this->platformFromPost();
        $post     = fn (string $key, string $default = ''): string => trim((string) $this->request->post($key, $default));

        AutoPosterConfig::saveTemplate([
            'pattern'          => $post('pattern'),
            'max_tags'         => $post('max_tags', '20'),
            'max_length'       => $post('max_length', '280'),
            'schedule_minutes' => $post('schedule_minutes', '60'),
            'recent_days'      => $post('recent_days', '14'),
            'max_media'        => $post('max_media', '4'),
            'blur_percent'     => $post('blur_percent', '85'),
            'screenshots'      => $post('screenshots', '3'),
            'banned_words'     => $post('banned_words'),
        ], $platform);

        $this->flash('success', ($platform === 'reddit' ? 'Reddit' : 'X') . ' template saved — new posts use it.');
        $this->redirectPath($platform);
    }

    /**
     * Redirect the user to Reddit's authorization page so the app can post on
     * their behalf. Stores a state token in the session for CSRF protection.
     */
    public function authorizeReddit(): void
    {
        $config = AutoPosterConfig::all();
        $reddit = new RedditClient($config['reddit']);

        if (!$reddit->isConfigured()) {
            $this->flash('error', 'Save your Reddit client credentials first.');
            $this->redirectPath('reddit');
            return;
        }

        $state = bin2hex(random_bytes(16));
        $_SESSION['reddit_oauth_state'] = $state;

        $redirectUri = $this->absoluteUrl('/admin/auto-poster/reddit/callback');

        header('Location: ' . $reddit->authorizationUrl($state, $redirectUri));
        exit;
    }

    /**
     * Handle Reddit's OAuth callback. Verifies the state token, exchanges the
     * authorization code for a refresh token and stores it.
     */
    public function callbackReddit(): void
    {
        $code  = trim((string) $this->request->query('code', ''));
        $state = trim((string) $this->request->query('state', ''));
        $error = trim((string) $this->request->query('error', ''));

        if ($error !== '') {
            $this->flash('error', 'Reddit authorization was cancelled or failed: ' . $error);
            $this->redirectPath('reddit');
            return;
        }

        $expected = $_SESSION['reddit_oauth_state'] ?? '';
        unset($_SESSION['reddit_oauth_state']);

        if ($state === '' || !hash_equals($expected, $state)) {
            $this->flash('error', 'Reddit authorization state mismatch. Please try again.');
            $this->redirectPath('reddit');
            return;
        }

        if ($code === '') {
            $this->flash('error', 'Reddit did not return an authorization code.');
            $this->redirectPath('reddit');
            return;
        }

        $config = AutoPosterConfig::all();
        $reddit = new RedditClient($config['reddit']);
        $redirectUri = $this->absoluteUrl('/admin/auto-poster/reddit/callback');

        $result = $reddit->exchangeCode($code, $redirectUri);

        if (!$result['ok']) {
            $this->flash('error', $result['error'] ?? 'Reddit authorization failed.');
            $this->redirectPath('reddit');
            return;
        }

        AutoPosterConfig::saveRedditToken($result['refresh_token'], $result['access_token'] ?? '');

        $this->flash('success', 'Reddit authorized successfully. You can now post to subreddits.');
        $this->redirectPath('reddit');
    }

    /**
     * Redirect the admin to X's OAuth2 authorization page (PKCE + refresh
     * token) so the app can post tweets on their behalf. Stores the state and
     * PKCE code_verifier in the session for the callback.
     */
    public function authorizeTwitter(): void
    {
        $config  = AutoPosterConfig::all();
        $twitter = new TwitterClient($config['twitter']);

        if (!$twitter->isConfigured()) {
            $this->flash('error', 'Save your X client ID and secret first.');
            $this->redirectPath('x');
            return;
        }

        $state         = bin2hex(random_bytes(16));
        $codeVerifier  = $this->pkceVerifier();
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        $_SESSION['twitter_oauth_state']   = $state;
        $_SESSION['twitter_pkce_verifier'] = $codeVerifier;

        $redirectUri = $this->absoluteUrl('/admin/auto-poster/twitter/callback');

        header('Location: ' . $twitter->authorizationUrl($state, $codeChallenge, $redirectUri));
        exit;
    }

    /**
     * Handle X's OAuth callback. Verifies the state token, exchanges the code
     * for a refresh token (using the PKCE verifier) and stores it.
     */
    public function callbackTwitter(): void
    {
        $code  = trim((string) $this->request->query('code', ''));
        $state = trim((string) $this->request->query('state', ''));
        $error = trim((string) $this->request->query('error', ''));

        if ($error !== '') {
            $this->flash('error', 'X authorization was cancelled or failed: ' . $error);
            $this->redirectPath('x');
            return;
        }

        $expected = $_SESSION['twitter_oauth_state'] ?? '';
        unset($_SESSION['twitter_oauth_state']);

        if ($state === '' || !hash_equals($expected, $state)) {
            $this->flash('error', 'X authorization state mismatch. Please try again.');
            $this->redirectPath('x');
            return;
        }

        $verifier = $_SESSION['twitter_pkce_verifier'] ?? '';
        unset($_SESSION['twitter_pkce_verifier']);

        if ($code === '') {
            $this->flash('error', 'X did not return an authorization code.');
            $this->redirectPath('x');
            return;
        }

        $config  = AutoPosterConfig::all();
        $twitter = new TwitterClient($config['twitter']);
        $redirectUri = $this->absoluteUrl('/admin/auto-poster/twitter/callback');

        $result = $twitter->exchangeCode($code, $verifier, $redirectUri);

        if (!$result['ok']) {
            $this->flash('error', $result['error'] ?? 'X authorization failed.');
            $this->redirectPath('x');
            return;
        }

        AutoPosterConfig::saveTwitterToken($result['refresh_token'], $result['access_token'] ?? '');

        $this->flash('success', 'X authorized successfully. You can now post tweets.');
        $this->redirectPath('x');
    }

    /**
     * Generate a random PKCE code_verifier (43-128 chars, unreserved chars).
     */
    private function pkceVerifier(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
    }

    /**
     * Save one platform's API credentials. The platform is picked from the
     * page's hidden field, so saving the X settings never touches the Reddit
     * credentials (and vice-versa); the scheduler timezone is only saved from
     * the X page.
     */
    public function saveSettings(): void
    {
        $platform = $this->platformFromPost();
        $config   = AutoPosterConfig::all();

        // Start from the stored values so the other platform is untouched.
        $reddit  = $config['reddit'];
        $twitter = $config['twitter'];

        if ($platform === 'reddit') {
            $reddit = [
                'client_id'     => trim((string) $this->request->post('reddit_client_id', '')),
                'client_secret' => trim((string) $this->request->post('reddit_client_secret', '')),
                'username'      => trim((string) $this->request->post('reddit_username', '')),
                'app_name'      => trim((string) $this->request->post('reddit_app_name', 'gallery-auto-poster')),
                'app_version'   => trim((string) $this->request->post('reddit_app_version', '1.0')),
            ];

            // Keep existing secret if the field was left blank (masked in the form).
            if ($reddit['client_secret'] === '' && !empty($config['reddit']['client_secret'])) {
                $reddit['client_secret'] = $config['reddit']['client_secret'];
            }
        } else {
            $twitter = [
                'client_id'          => trim((string) $this->request->post('twitter_client_id', '')),
                'client_secret'      => trim((string) $this->request->post('twitter_client_secret', '')),
                'consumer_key'       => trim((string) $this->request->post('twitter_consumer_key', '')),
                'consumer_secret'    => trim((string) $this->request->post('twitter_consumer_secret', '')),
                'oauth_token'        => trim((string) $this->request->post('twitter_oauth_token', '')),
                'oauth_token_secret' => trim((string) $this->request->post('twitter_oauth_token_secret', '')),
            ];

            // Keep existing values if the fields were left blank (masked in the form).
            foreach (['client_id', 'client_secret', 'consumer_key', 'consumer_secret', 'oauth_token', 'oauth_token_secret'] as $key) {
                if ($twitter[$key] === '' && !empty($config['twitter'][$key])) {
                    $twitter[$key] = $config['twitter'][$key];
                }
            }

            // Preserve an existing authorization token across a settings save.
            foreach (['refresh_token', 'access_token', 'bearer_token'] as $key) {
                if (!empty($config['twitter'][$key])) {
                    $twitter[$key] = $config['twitter'][$key];
                }
            }
        }

        // Persist the scheduler timezone (X page only), falling back to UTC
        // when the submitted value is not a valid PHP timezone identifier.
        $timezone = (string) ($config['timezone'] ?? 'UTC');
        try {
            $timezone = (new DateTimeZone(trim((string) $this->request->post('timezone', $timezone))))->getName();
        } catch (\Throwable $e) {
            // keep the current timezone
        }

        AutoPosterConfig::save($reddit, $twitter, $timezone);

        $this->flash('success', ($platform === 'reddit' ? 'Reddit' : 'X') . ' settings saved.');
        $this->redirectPath($platform);
    }

    /**
     * Submit a post to Reddit (any subreddit, link, text or image).
     */
    public function postReddit(): void
    {
        $config = AutoPosterConfig::all();
        $reddit = new RedditClient($config['reddit']);

        $subreddit = trim((string) $this->request->post('reddit_subreddit', ''));
        $title     = trim((string) $this->request->post('reddit_title', ''));
        $type      = $this->request->post('reddit_type', 'link') === 'self' ? 'self' : 'link';
        $url       = trim((string) $this->request->post('reddit_url', ''));
        $text      = trim((string) $this->request->post('reddit_text', ''));
        $media     = $this->request->file('reddit_media');

        if ($subreddit === '' || $title === '') {
            $this->flash('error', 'Subreddit and title are required.');
            $this->redirectPath('reddit');
            return;
        }

        // Reddit supports one image per image post. If a file was uploaded,
        // use it as an image post (ignoring the link URL / text content).
        $mediaFile = $this->firstUploadedFile($media);
        if ($mediaFile !== null) {
            $result = $reddit->submit($subreddit, $title, '', 'image', null, $mediaFile);
        } else {
            $result = $reddit->submit($subreddit, $title, $text, $type, $url);
        }

        AutoPosterConfig::log(
            'reddit',
            'r/' . ltrim($subreddit, '/'),
            $result['ok'] ? 'success' : 'failed',
            $result['ok'] ? ($result['url'] ?? 'Posted') : ($result['error'] ?? 'Unknown error'),
            (int) Auth::user()['id']
        );

        $this->flash($result['ok'] ? 'success' : 'error', $result['ok']
            ? 'Posted to r/' . ltrim($subreddit, '/') . ': ' . ($result['url'] ?? '')
            : 'Reddit error: ' . ($result['error'] ?? 'Unknown error'));
        $this->redirectPath('reddit');
    }

    /**
     * Post to X (formerly Twitter), optionally with one or more images/video.
     */
    public function postTwitter(): void
    {
        $config  = AutoPosterConfig::all();
        $twitter = new TwitterClient($config['twitter']);

        $text  = trim((string) $this->request->post('twitter_text', ''));
        $media = $this->request->file('twitter_media');

        $files = $this->uploadedFiles($media);

        $result = $twitter->post($text, $files);

        AutoPosterConfig::log(
            'twitter',
            '',
            $result['ok'] ? 'success' : 'failed',
            $result['ok'] ? ($result['url'] ?? 'Posted') : ($result['error'] ?? 'Unknown error'),
            (int) Auth::user()['id']
        );

$this->flash($result['ok'] ? 'success' : 'error', $result['ok']
            ? 'Posted to X: ' . ($result['url'] ?? '')
            : (empty($result['skipped']) ? 'Post failed: ' : 'Not sent — ') . ($result['error'] ?? 'Unknown error'));
        $this->redirectPath('x');
    }

    /**
     * Repost a recorded post (from the Recent posts list) right away: copies
     * its text + media into a fresh queue row and publishes it immediately.
     */
    public function repostPosted(): void
    {
        $id = (int) $this->request->post('post_id', 0);
        $src = AutoPostQueue::find($id);

        if ($id <= 0 || $src === null) {
            $this->flash('error', 'Post not found.');
            $this->redirectItemPlatform($id);
            return;
        }

        $newId = AutoPostQueue::requeueFrom($id);
        if ($newId <= 0) {
            $this->flash('error', 'Could not re-queue that post.');
            $this->redirectItemPlatform($id);
            return;
        }

        $result = AutoPostQueue::post($newId);

        AuditLog::record(
            (int) Auth::user()['id'],
            'create',
            'auto_post_queue',
            $newId,
            'Reposted queue item #' . $id . ' as #' . $newId . ($result['ok'] ? '' : ': ' . ($result['error'] ?? ''))
        );

        $this->flash($result['ok'] ? 'success' : 'error', $result['ok']
            ? 'Reposted to ' . $this->platformLabel((string) ($src['platform'] ?? '')) . ': ' . ($result['url'] ?? '')
            : (empty($result['skipped']) ? 'Repost failed: ' : 'Not reposted — ') . ($result['error'] ?? 'Unknown error'));
        $this->redirectItemPlatform($id);
    }

    /**
     * Edit a failed post and then either publish it right away or schedule it
     * (from the Recent posts list). The edited wording is copied into a fresh
     * queue row, so the original failed row is left untouched for reference.
     * Dispatch is done via the submitted "action" field (repost | reschedule).
     */
    public function editPosted(): void
    {
        $id          = (int) $this->request->post('post_id', 0);
        $action      = (string) $this->request->post('action', 'repost');
        $text        = (string) $this->request->post('text', '');
        $scheduledAt = (string) $this->request->post('scheduled_at', '');
        $src         = AutoPostQueue::find($id);

        if ($id <= 0 || $src === null) {
            $this->flash('error', 'Post not found.');
            $this->redirectItemPlatform($id);
            return;
        }

        $newId = AutoPostQueue::requeueFrom($id, $scheduledAt, $text);
        if ($newId <= 0) {
            $this->flash('error', $action === 'reschedule'
                ? 'Could not schedule that post — use a valid date and time.'
                : 'Could not re-queue that post.');
            $this->redirectItemPlatform($id);
            return;
        }

        if ($action === 'reschedule') {
            AuditLog::record(
                (int) Auth::user()['id'],
                'create',
                'auto_post_queue',
                $newId,
                'Scheduled edited repost of #' . $id . ' as #' . $newId
            );
            $this->flash('success', 'Scheduled to publish again with your edited text — see the Posting queue.');
            $this->redirectItemPlatform($id);
            return;
        }

        $result = AutoPostQueue::post($newId);

        AuditLog::record(
            (int) Auth::user()['id'],
            'create',
            'auto_post_queue',
            $newId,
            'Reposted edited item #' . $id . ' as #' . $newId . ($result['ok'] ? '' : ': ' . ($result['error'] ?? ''))
        );

        $this->flash($result['ok'] ? 'success' : 'error', $result['ok']
            ? 'Reposted to ' . $this->platformLabel((string) ($src['platform'] ?? '')) . ': ' . ($result['url'] ?? '')
            : (empty($result['skipped']) ? 'Repost failed: ' : 'Not reposted — ') . ($result['error'] ?? 'Unknown error'));
        $this->redirectItemPlatform($id);
    }

    /**
     * list): copies its text + media into a fresh queued row at the chosen
     * time, where the autopost cron publishes it.
     */
    public function reschedulePosted(): void
    {
        $id          = (int) $this->request->post('post_id', 0);
        $scheduledAt = (string) $this->request->post('scheduled_at', '');
        $src         = AutoPostQueue::find($id);

        if ($id <= 0 || $src === null) {
            $this->flash('error', 'Post not found.');
            $this->redirectItemPlatform($id);
            return;
        }

        $newId = AutoPostQueue::requeueFrom($id, $scheduledAt);
        if ($newId <= 0) {
            $this->flash('error', 'Could not schedule that post — use a valid date and time.');
            $this->redirectItemPlatform($id);
            return;
        }

        AuditLog::record(
            (int) Auth::user()['id'],
            'create',
            'auto_post_queue',
            $newId,
            'Scheduled repost of #' . $id . ' as #' . $newId . ' (from recent posts)'
        );

        $this->flash('success', 'Scheduled to publish again — see the Posting queue.');
        $this->redirectItemPlatform($id);
    }

    /**
     * Return the first successfully uploaded file from a $_FILES entry, or
     * null when none was provided. Normalizes single- and multi-file arrays.
     */
    /**
     * Build an absolute URL (scheme + host + path) for a route. Reddit's
     * OAuth redirect_uri must be absolute, which the relative url() helper
     * does not produce.
     */
    private function absoluteUrl(string $path): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';

        return $scheme . '://' . $host . url($path);
    }

    private function firstUploadedFile($file): ?array
    {
        $files = $this->uploadedFiles($file);

        return $files[0] ?? null;
    }

    /**
     * Normalize a $_FILES entry into a flat list of uploaded file arrays, each
     * with 'tmp_name', 'name', 'type' and 'size'. Files with no data are skipped.
     */
    private function uploadedFiles($file): array
    {
        if ($file === null || !is_array($file)) {
            return [];
        }

        $out = [];

        // Single file: ['name'=>, 'tmp_name'=>, 'type'=>, 'size'=>, 'error'=>]
        if (isset($file['tmp_name']) && is_string($file['tmp_name'])) {
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_file($file['tmp_name'])) {
                $out[] = [
                    'tmp_name' => $file['tmp_name'],
                    'name'     => $file['name'] ?? basename($file['tmp_name']),
                    'type'     => $file['type'] ?? '',
                    'size'     => (int) ($file['size'] ?? filesize($file['tmp_name']) ?: 0),
                ];
            }

            return $out;
        }

        // Multi-file: ['name'=>[], 'tmp_name'=>[], 'type'=>[], 'size'=>[], 'error'=>[]]
        if (isset($file['name']) && is_array($file['name'])) {
            $count = count($file['name']);
            for ($i = 0; $i < $count; $i++) {
                if (($file['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    continue;
                }
                if (!isset($file['tmp_name'][$i]) || !is_file($file['tmp_name'][$i])) {
                    continue;
                }
                $out[] = [
                    'tmp_name' => $file['tmp_name'][$i],
                    'name'     => $file['name'][$i] ?? basename($file['tmp_name'][$i]),
                    'type'     => $file['type'][$i] ?? '',
                    'size'     => (int) ($file['size'][$i] ?? filesize($file['tmp_name'][$i]) ?: 0),
                ];
            }
        }

        return $out;
    }

    /**
     * Clear the posting history log.
     */
    public function clearLog(): void
    {
        $platform = $this->platformFromPost();
        AutoPosterConfig::clearLog($platform);
        $this->flash('success', ($platform === 'reddit' ? 'Reddit' : 'X') . ' posting log cleared.');
        $this->redirectPath($platform);
    }

    /**
     * Add a recommended gallery to the posting queue. Accepts an optional
     * edited post text and a publish date/time; when empty the standard
     * recommendation and default schedule are used.
     */
    public function queueRecommendation(): void
    {
        $galleryId   = (int) $this->request->post('gallery_id', 0);
        $text        = (string) $this->request->post('text', '');
        $scheduledAt = (string) $this->request->post('scheduled_at', '');
        $platform    = $this->platformFromPost();
        $queueKey    = $platform === 'reddit' ? 'reddit' : 'twitter';
        $queueId     = AutoPostQueue::enqueue($galleryId, $text, $scheduledAt, $queueKey);

        if ($queueId <= 0) {
            $this->flash('error', 'Gallery not found or not eligible.');
            $this->redirectPath($platform);
            return;
        }

        AuditLog::record(
            (int) Auth::user()['id'],
            'create',
            'auto_post_queue',
            $queueId,
            'Queued gallery #' . $galleryId . ' for auto-posting'
        );

        $this->flash('success', 'Added to the posting queue.');
        $this->redirectPath($platform);
    }

    /**
     * Post a queued item to its platform immediately. Accepts either a
     * queue_id (existing queue row) or a gallery_id (recommendation: enqueue it
     * first, then post on the spot).
     */
    public function postQueued(): void
    {
        $id = (int) $this->request->post('queue_id', 0);

        $galleryId = (int) $this->request->post('gallery_id', 0);
        if ($id <= 0 && $galleryId > 0) {
            $text        = (string) $this->request->post('text', '');
            $scheduledAt = (string) $this->request->post('scheduled_at', '');
            $queueKey    = $this->platformFromPost() === 'reddit' ? 'reddit' : 'twitter';
            $id          = AutoPostQueue::enqueue($galleryId, $text, $scheduledAt, $queueKey);
        }

        if ($id <= 0) {
            $this->flash('error', 'No gallery or queued item to post.');
            $this->redirectPath($this->platformFromPost());
            return;
        }

        $result = AutoPostQueue::post($id);

        AuditLog::record(
            (int) Auth::user()['id'],
            'update',
            'auto_post_queue',
            $id,
            $result['ok'] ? 'Posted queued auto-post #' . $id : 'Failed queued auto-post #' . $id . ': ' . ($result['error'] ?? '')
        );

$this->flash($result['ok'] ? 'success' : 'error', $result['ok']
            ? 'Posted to ' . $this->platformLabel($this->itemPlatform($id)) . ': ' . ($result['url'] ?? '')
            : (empty($result['skipped']) ? 'Post failed: ' : 'Not sent — ') . ($result['error'] ?? 'Unknown error'));
        $this->redirectItemPlatform($id);
    }

    /**
     * Change the publish date/time of a queued auto-post. An empty value
     * schedules the post for the next worker run.
     */
    public function rescheduleQueued(): void
    {
        $id          = (int) $this->request->post('queue_id', 0);
        $scheduledAt = (string) $this->request->post('scheduled_at', '');

        if ($id <= 0 || AutoPostQueue::find($id) === null) {
            $this->flash('error', 'Queue item not found.');
            $this->redirectItemPlatform($id);
            return;
        }

        if (!AutoPostQueue::reschedule($id, $scheduledAt)) {
            $this->flash('error', 'Could not update the schedule — use a valid date and time.');
            $this->redirectItemPlatform($id);
            return;
        }

        AuditLog::record(
            (int) Auth::user()['id'],
            'update',
            'auto_post_queue',
            $id,
            'Rescheduled auto-post queue item #' . $id . ' to ' . $scheduledAt
        );

        $this->flash('success', 'Schedule updated.');
        $this->redirectItemPlatform($id);
    }

    /**
     * Requeue a failed post and publish it right away. Used by the dashboard's
     * failed-posts list and the Auto Poster page retry button.
     */
    public function retryQueued(): void
    {
        $redirectTo = $this->resolveQueueRedirect();
        $id         = (int) $this->request->post('queue_id', 0);
        $platform   = $this->itemPlatform($id);

        if ($id <= 0 || !AutoPostQueue::requeue($id)) {
            $this->flash('error', 'Could not requeue that failed post.');
            $this->redirect($redirectTo);
            return;
        }

        $result = AutoPostQueue::post($id);

        $this->flash($result['ok'] ? 'success' : 'error', $result['ok']
            ? 'Posted to ' . $this->platformLabel($platform) . ': ' . ($result['url'] ?? '')
            : (empty($result['skipped']) ? 'Post failed: ' : 'Not sent — ') . ($result['error'] ?? 'Unknown error'));
        $this->redirect($redirectTo);
    }

    /**
     * Resolve where to send the user after a queue action. Prefers an explicit
     * redirect_to field (set by the dashboard so it stays on /admin instead of
     * jumping to the Auto Poster page). When none was given, the queue row's
     * own platform decides between the X and Reddit Auto Poster pages.
     */
    private function resolveQueueRedirect(): string
    {
        $redirectTo = trim((string) $this->request->post('redirect_to', ''));

        if ($redirectTo !== '') {
            $base = rtrim((string) config('app.base_path'), '/');
            if ($base !== '' && strpos($redirectTo, $base) === 0) {
                $redirectTo = substr($redirectTo, strlen($base));
            }
            if ($redirectTo !== '' && strpos($redirectTo, '/admin') === 0) {
                return $redirectTo;
            }
        }

        return $this->platformPath($this->itemPlatform((int) $this->request->post('queue_id', 0)));
    }

    /**
     * The queue row's platform ('twitter'/'reddit'), 'x' when unknown.
     */
    private function itemPlatform(int $id): string
    {
        $item = $id > 0 ? AutoPostQueue::find($id) : null;

        return (string) ($item['platform'] ?? '');
    }

    /**
     * The platform ("twitter"/"reddit") read from the page's hidden field.
     */
    private function platformFromPost(): string
    {
        return trim((string) $this->request->post('platform', 'x')) === 'reddit' ? 'reddit' : 'x';
    }

    /**
     * The admin path for a platform: the X Auto Poster page or the Reddit one.
     */
    private function platformPath(string $platform): string
    {
        return ($platform === 'reddit') ? '/admin/auto-poster/reddit' : '/admin/auto-poster';
    }

    /**
     * Redirect to the Auto Poster page that owns the given platform.
     */
    private function redirectPath(string $platform): void
    {
        $this->redirect($this->platformPath($platform));
    }

    /**
     * Redirect to the Auto Poster page that owns a queue row (defaults to X).
     */
    private function redirectItemPlatform(int $id): void
    {
        $this->redirectPath($this->itemPlatform($id));
    }

    /**
     * Human-friendly label for a queue platform ('X' for 'twitter', 'Reddit').
     */
    private function platformLabel(string $platform): string
    {
        return $platform === 'reddit' ? 'Reddit' : 'X';
    }

    /**
     * Publish every queued item on the current page's platform, stopping at
     * the first failure so a burst of posts cannot mask a systemic error
     * (e.g. depleted credits).
     */
    public function postAllQueued(): void
    {
        $platform = $this->platformFromPost();
        $queueKey = $platform === 'reddit' ? 'reddit' : 'twitter';
        $items    = AutoPostQueue::queued(50, $queueKey);
        $posted   = 0;
        $skipped  = 0;
        $failed   = 0;

        foreach ($items as $item) {
            $result = AutoPostQueue::post((int) $item['id']);
            if ($result['ok']) {
                $posted++;
            } elseif (!empty($result['skipped'])) {
                $skipped++;
            } else {
                $failed++;
                break;
            }
        }

        $summary = 'Posted ' . $posted . ' queued post(s).';
        if ($skipped > 0) {
            $summary .= ' Skipped ' . $skipped . ' (platform not authorized).';
        }
        if ($failed > 0) {
            $summary .= ' Stopped after a failure: ' . (string) ($result['error'] ?? '');
        }

        $this->flash($failed === 0 ? 'success' : 'error', $summary);
        $this->redirectPath($platform);
    }

    /**
     * Dismiss a queued or recommended post so its gallery is not offered again.
     * Accepts a queue_id or a gallery_id (recommendation with no row yet).
     */
    public function dismissQueued(): void
    {
        $redirectTo = $this->resolveQueueRedirect();

        $id = (int) $this->request->post('queue_id', 0);

        $galleryId = (int) $this->request->post('gallery_id', 0);
        if ($id <= 0 && $galleryId > 0) {
            $queueKey = $this->platformFromPost() === 'reddit' ? 'reddit' : 'twitter';
            $id       = AutoPostQueue::dismissGallery($galleryId, $queueKey);
            // Recommendation cards carry no queue_id, so fall back to the
            // submitting page's platform instead of the X page.
            $redirectTo = $this->platformPath($this->platformFromPost());
        }

        if ($id <= 0 || AutoPostQueue::find($id) === null) {
            $this->flash('error', 'Queue item not found.');
            $this->redirect($redirectTo);
            return;
        }

        AutoPostQueue::dismiss($id);

        AuditLog::record(
            (int) Auth::user()['id'],
            'delete',
            'auto_post_queue',
            $id,
            'Dismissed auto-post queue item #' . $id
        );

        $this->flash('success', 'Dismissed — this gallery will not be offered again.');
        $this->redirect($redirectTo);
    }
}
