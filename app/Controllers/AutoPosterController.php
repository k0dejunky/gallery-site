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
            'bearer_token' => trim((string) $this->request->post('twitter_bearer_token', '')),
        ];

        if ($twitter['bearer_token'] === '' && !empty($config['twitter']['bearer_token'])) {
            $twitter['bearer_token'] = $config['twitter']['bearer_token'];
        }

        AutoPosterConfig::save($reddit, $twitter);

        $this->flash('success', 'Auto Poster settings saved.');
        $this->redirect('/admin/auto-poster');
    }

    /**
     * Submit a post to Reddit (any subreddit, link or text).
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

        if ($subreddit === '' || $title === '') {
            $this->flash('error', 'Subreddit and title are required.');
            $this->redirect('/admin/auto-poster');
            return;
        }

        $result = $reddit->submit($subreddit, $title, $text, $type, $url);

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
     * Post to X (formerly Twitter).
     */
    public function postTwitter(): void
    {
        $config  = AutoPosterConfig::all();
        $twitter = new TwitterClient($config['twitter']);

        $text = trim((string) $this->request->post('twitter_text', ''));

        $result = $twitter->post($text);

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
     * Clear the posting history log.
     */
    public function clearLog(): void
    {
        AutoPosterConfig::clearLog();
        $this->flash('success', 'Auto Poster log cleared.');
        $this->redirect('/admin/auto-poster');
    }
}
