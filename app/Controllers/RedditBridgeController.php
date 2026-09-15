<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Models\AutoPostQueue;
use App\Models\AutoPosterConfig;
use App\Models\RedditBridge;

/**
 * Server-side half of the Reddit PULL bridge. A Devvit Web app polls these
 * endpoints on a cron schedule, so the site never needs outbound internet
 * access to a bridge host nor a Reddit OAuth credential (Reddit removed
 * self-serve API access in Nov 2025): the Devvit app installed in the target
 * subreddit does the submission on Reddit's side.
 *
 *   GET  /webhooks/reddit/next   — claim the next due reddit queue row
 *   POST /webhooks/reddit/report — settle a claimed row (posted/failed)
 *
 * Both endpoints authenticate with the same shared secret (pull_secret in
 * autoposter.json), the same value the Devvit app holds in its settings.
 */
class RedditBridgeController extends Controller
{
    public function __construct(Request $request)
    {
        parent::__construct($request);
        // Intentionally no Auth::requireLogin(): only the Devvit bridge calls
        // here — authenticity comes from the shared pull secret.
    }

    /**
     * Claim the next due reddit queue row for the polling app. Returns the
     * full post payload (title, body, subreddit, image as a base64 data URL)
     * plus the claim token the app must echo back in its report. An empty
     * response (HTTP 200 + {"status":"empty"}) means nothing is due right
     * now. When the caller identifies its installation subreddit and it does
     * not match the configured target, nothing is returned so the "wrong"
     * instance never hoards rows.
     */
    public function next(): void
    {
        if (!$this->authorized()) {
            $this->json(401, ['status' => 'unauthorized']);
            return;
        }

        $config = AutoPosterConfig::all()['reddit'] ?? [];
        $target = RedditBridge::cleanSubreddit((string) ($config['subreddit'] ?? ''));

        if ($target === '' || !AutoPostQueue::redditPullEnabled()) {
            $this->json(200, ['status' => 'empty']);
            return;
        }

        // The app's own installation subreddit (forwarded as
        // X-Devvit-Subreddit) must match the configured target, or this
        // instance polls the wrong sub and would be unable to post.
        $installSub = RedditBridge::cleanSubreddit(
            (string) ($this->request->header('X-Devvit-Subreddit') ?? '')
        );
        if ($installSub !== '' && $installSub !== $target) {
            $this->json(200, ['status' => 'empty']);
            return;
        }

        try {
            $item = AutoPostQueue::nextRedditPending(600, 'devvit');

            if ($item === null) {
                $this->json(200, ['status' => 'empty']);
                return;
            }

            $claim  = (string) $item['picked_by'];
            $sentAt = (string) ($item['picked_at'] ?? gmdate('Y-m-d H:i:s'));
            $image  = AutoPostQueue::redditMediaDataUrl($item);

            [$title, $body] = RedditBridge::splitForReddit((string) $item['text']);

            $this->json(200, [
                'status' => 'pending',
                'item'   => [
                    'id'        => (int) $item['id'],
                    'claim'     => $claim,
                    'sentAt'    => $sentAt,
                    'subreddit' => $target,
                    'title'     => $title,
                    'body'      => $body,
                    'nsfw'      => true,
                    'siteUrl'   => 'https://' . AutoPostQueue::POST_DOMAIN,
                    'image'     => $image,
                ],
            ]);
        } catch (\Throwable $error) {
            error_log('[webhooks/reddit/next] claim failed: ' . $error->getMessage());
            $this->json(200, ['status' => 'empty']);
        }
    }

    /**
     * Settle a claimed reddit row from the polling app: mark it posted (with
     * the reddit URL) or failed (with the error). The claim token the app
     * received from /next must match the row's outstanding claim.
     */
    public function report(): void
    {
        if (!$this->authorized()) {
            $this->json(401, ['status' => 'unauthorized']);
            return;
        }

        $raw = file_get_contents('php://input') ?: '';
        $data = json_decode($raw, true);

        if (!is_array($data)) {
            $this->json(400, ['status' => 'bad-request', 'error' => 'Expected a JSON body']);
            return;
        }

        $id    = (int) ($data['id'] ?? 0);
        $claim = (string) ($data['claim'] ?? '');
        $ok    = (bool) ($data['ok'] ?? false);
        $url   = (string) ($data['url'] ?? '');
        $error = (string) ($data['error'] ?? '');

        if ($id <= 0) {
            $this->json(400, ['status' => 'bad-request', 'error' => 'Missing id']);
            return;
        }

        try {
            $settled = AutoPostQueue::reportRedditResult($id, $ok, $url, $error, $claim);

            $this->json($settled ? 200 : 409, [
                'status' => $settled ? 'ok' : 'no-matching-claim',
            ]);
        } catch (\Throwable $error) {
            error_log('[webhooks/reddit/report] failed: ' . $error->getMessage());
            $this->json(200, ['status' => 'ok']);
        }
    }

    /**
     * Verify the bearer shared secret: the Authorization header must carry
     * "Bearer <pull_secret>" where pull_secret is the same random value the
     * Devvit app holds in its settings.
     */
    private function authorized(): bool
    {
        $config = AutoPosterConfig::all()['reddit'] ?? [];
        $secret = trim((string) ($config['pull_secret'] ?? ''));

        if ($secret === '') {
            return false;
        }

        $header = (string) ($this->request->header('Authorization') ?? '');
        $token  = trim((string) preg_replace('#^Bearer\s+#i', '', $header));

        return $token !== '' && hash_equals($secret, $token);
    }

    private function json(int $status, array $payload): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    }
}