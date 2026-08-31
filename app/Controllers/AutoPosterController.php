<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Models\AutoPosterConfig;
use App\Models\RedditClient;
use App\Models\TwitterClient;

class AutoPosterController extends Controller
{
    public function __construct(Request $request)
    {
        parent::__construct($request);
        Auth::requirePermission('autoposter');
    }

    /**
     * Show the Auto Poster admin page: credential settings, a posting form
     * (Reddit + X) and the posting history log.
     */
    public function index(): void
    {
        $this->viewAdmin('auto_poster', [
            'config'  => AutoPosterConfig::all(),
            'log'     => AutoPosterConfig::logEntries(),
        ]);
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
            $this->redirect('/admin/auto-poster');
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
            $this->redirect('/admin/auto-poster');
            return;
        }

        $expected = $_SESSION['reddit_oauth_state'] ?? '';
        unset($_SESSION['reddit_oauth_state']);

        if ($state === '' || !hash_equals($expected, $state)) {
            $this->flash('error', 'Reddit authorization state mismatch. Please try again.');
            $this->redirect('/admin/auto-poster');
            return;
        }

        if ($code === '') {
            $this->flash('error', 'Reddit did not return an authorization code.');
            $this->redirect('/admin/auto-poster');
            return;
        }

        $config = AutoPosterConfig::all();
        $reddit = new RedditClient($config['reddit']);
        $redirectUri = $this->absoluteUrl('/admin/auto-poster/reddit/callback');

        $result = $reddit->exchangeCode($code, $redirectUri);

        if (!$result['ok']) {
            $this->flash('error', $result['error'] ?? 'Reddit authorization failed.');
            $this->redirect('/admin/auto-poster');
            return;
        }

        AutoPosterConfig::saveRedditToken($result['refresh_token'], $result['access_token'] ?? '');

        $this->flash('success', 'Reddit authorized successfully. You can now post to subreddits.');
        $this->redirect('/admin/auto-poster');
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
            $this->redirect('/admin/auto-poster');
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
            $this->redirect('/admin/auto-poster');
            return;
        }

        $expected = $_SESSION['twitter_oauth_state'] ?? '';
        unset($_SESSION['twitter_oauth_state']);

        if ($state === '' || !hash_equals($expected, $state)) {
            $this->flash('error', 'X authorization state mismatch. Please try again.');
            $this->redirect('/admin/auto-poster');
            return;
        }

        $verifier = $_SESSION['twitter_pkce_verifier'] ?? '';
        unset($_SESSION['twitter_pkce_verifier']);

        if ($code === '') {
            $this->flash('error', 'X did not return an authorization code.');
            $this->redirect('/admin/auto-poster');
            return;
        }

        $config  = AutoPosterConfig::all();
        $twitter = new TwitterClient($config['twitter']);
        $redirectUri = $this->absoluteUrl('/admin/auto-poster/twitter/callback');

        $result = $twitter->exchangeCode($code, $verifier, $redirectUri);

        if (!$result['ok']) {
            $this->flash('error', $result['error'] ?? 'X authorization failed.');
            $this->redirect('/admin/auto-poster');
            return;
        }

        AutoPosterConfig::saveTwitterToken($result['refresh_token'], $result['access_token'] ?? '');

        $this->flash('success', 'X authorized successfully. You can now post tweets.');
        $this->redirect('/admin/auto-poster');
    }

    /**
     * Generate a random PKCE code_verifier (43-128 chars, unreserved chars).
     */
    private function pkceVerifier(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
    }

    /**
     * Save the Reddit and X/Twitter API credentials.
     */
    public function saveSettings(): void
    {
        $config = AutoPosterConfig::all();

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

        $twitter = [
            'client_id'     => trim((string) $this->request->post('twitter_client_id', '')),
            'client_secret' => trim((string) $this->request->post('twitter_client_secret', '')),
        ];

        // Keep existing values if the fields were left blank (masked in the form).
        if ($twitter['client_id'] === '' && !empty($config['twitter']['client_id'])) {
            $twitter['client_id'] = $config['twitter']['client_id'];
        }
        if ($twitter['client_secret'] === '' && !empty($config['twitter']['client_secret'])) {
            $twitter['client_secret'] = $config['twitter']['client_secret'];
        }

        // Preserve an existing authorization token across a settings save.
        foreach (['refresh_token', 'access_token', 'bearer_token'] as $key) {
            if (!empty($config['twitter'][$key])) {
                $twitter[$key] = $config['twitter'][$key];
            }
        }

        AutoPosterConfig::save($reddit, $twitter);

        $this->flash('success', 'Auto Poster settings saved.');
        $this->redirect('/admin/auto-poster');
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
            $this->redirect('/admin/auto-poster');
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
        $this->redirect('/admin/auto-poster');
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
            : 'X error: ' . ($result['error'] ?? 'Unknown error'));
        $this->redirect('/admin/auto-poster');
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
        AutoPosterConfig::clearLog();
        $this->flash('success', 'Auto Poster log cleared.');
        $this->redirect('/admin/auto-poster');
    }
}
