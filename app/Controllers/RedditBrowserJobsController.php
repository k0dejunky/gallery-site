<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Models\AutoPosterConfig;
use App\Models\AutoPostQueue;
use App\Models\Traffic;

/**
 * Webhook endpoints that hand queued Reddit auto-posts to the operator's
 * home-browser worker (the "share-button method"). Reddit's network security
 * blocks this server's cloud IP for browsers, so a machine on a residential
 * IP polls these endpoints, opens /r/<sub>/submit exactly like the site's
 * "Share on Reddit" button, clicks Post, and reports the result back here.
 *
 * Both endpoints require the shared REDDIT_BROWSER_KEY Bearer token.
 */
class RedditBrowserJobsController extends Controller
{
    private const CLAIM = 'reddit-home';

    /**
     * Hand the next due reddit browser job to the home worker.
     * GET /webhooks/reddit/browser-jobs
     *
     * @return never
     */
    public function take(): void
    {
        if (!$this->authenticated()) {
            $this->json(['error' => 'Not authorized'], 401);
            return;
        }

        $row = AutoPostQueue::takeBrowserJob(self::CLAIM);

        if ($row === null) {
            $this->json(['ok' => true, 'empty' => true], 200);
            return;
        }

        $payload = $this->payloadFor($row);

        if (isset($payload['error'])) {
            // Nothing worth posting from the home box — release and move on.
            AutoPostQueue::releaseClaim((int) $row['id'], self::CLAIM);
            $this->json(['ok' => true, 'empty' => true], 200);
            return;
        }

        $payload['job_id'] = (int) $row['id'];
        $this->json(['ok' => true, 'job' => $payload], 200);
    }

    /**
     * Report the outcome of a claimed reddit browser job.
     * POST /webhooks/reddit/browser-jobs/{id}/report
     * body: { "ok": bool, "url"?: string, "error"?: string }
     *
     * @return never
     */
    public function report(int $id): void
    {
        if (!$this->authenticated()) {
            $this->json(['error' => 'Not authorized'], 401);
            return;
        }

        $body = json_decode($this->rawBody(), true);
        $ok   = is_array($body) ? !empty($body['ok']) : false;

        $row = AutoPostQueue::find((int) $id);
        if ($row === null || ($row['claimed_by'] ?? '') !== self::CLAIM) {
            $this->json(['ok' => false, 'error' => 'No claimed job with that id.'], 404);
            return;
        }

        $galleryId = (int) ($row['gallery_id'] ?? 0);
        $target    = (string) (AutoPosterConfig::channel('reddit')['subreddit'] ?? '');

        if ($ok) {
            $url = trim((string) ($body['url'] ?? ''));
            AutoPostQueue::markPosted((int) $id, $url);
            if ($galleryId > 0) {
                \App\Models\WallPost::createForGallery(0, $galleryId, trim((string) $row['text']));
            }
            AutoPosterConfig::log('reddit', $target, 'success', $url !== '' ? $url : 'Posted (no URL returned)');
            $this->json(['ok' => true]);
            return;
        }

        $err = trim((string) ($body['error'] ?? ''));
        AutoPostQueue::markFailed((int) $id, $err !== '' ? $err : 'The home worker reported a failure.');
        AutoPosterConfig::log('reddit', $target, 'failed', $err !== '' ? $err : 'failed');
        $this->json(['ok' => true]);
    }

    /**
     * Build the consumable job payload for the home worker: subreddit, split
     * title/body, and the attributed gallery link. Rows with media post as a
     * LINK — Reddit's web image editor rejects synthesised uploads, so the
     * gallery link (with its og:image thumbnail card) is the reliable way to
     * surface the content. True native image uploads use the OAuth API path.
     */
    private function payloadFor(array $item): array
    {
        $channel = AutoPosterConfig::channel('reddit');
        $sub     = (string) ($channel['subreddit'] ?? '');

        if ($sub === '') {
            return ['error' => 'Reddit has no target subreddit configured.'];
        }

        $split = \App\Core\AutoPostText::splitForPlatform((string) $item['text'], 'reddit');
        $base  = [
            'subreddit' => \App\Models\RedditClient::cleanSubreddit($sub),
            'title'     => (string) ($split['title'] ?? ''),
            'body'      => trim((string) ($split['body'] ?? '')),
        ];

        $gid = (int) ($item['gallery_id'] ?? 0);
        $base['mode'] = 'link';
        $base['url']  = $gid > 0 ? Traffic::buildUrl('/galleries/' . $gid, 'reddit') : absolute_url('/');
        return $base;
    }

    private function authenticated(): bool
    {
        $expected = env_value('REDDIT_BROWSER_KEY', '');
        if ($expected === '') {
            return false;
        }

        $given = trim((string) $this->request->header('Authorization', ''));
        if (stripos($given, 'bearer ') === 0) {
            $given = trim(substr($given, 7));
        }

        return $given !== '' && hash_equals($expected, $given);
    }
}