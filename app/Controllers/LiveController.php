<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\RateLimiter;
use App\Core\Database;
use App\Models\LiveSession;
use App\Models\OperatorToken;

/**
 * Live video streaming.
 *
 *  - POST /webhooks/live/auth   MediaMTX auth webhook (publish = stream key,
 *                               read = signed playback token). CSRF-exempt.
 *  - POST /live/start           operator app issues a one-time RTMP stream key.
 *  - POST /live/stop            operator ends the broadcast.
 *  - GET  /live                 member player page (login + subscription).
 *  - GET  /live/status          JSON live/offline for badges.
 */
class LiveController extends Controller
{
    private const MEDIAMTX_API = 'http://127.0.0.1:9997';

    /** MediaMTX auth webhook: validate publish (stream key) or read (token). */
    public function auth(): void
    {
        $data   = json_decode($this->rawBody(), true);
        $action = (string) ($data['action'] ?? '');
        $path   = (string) ($data['path'] ?? '');
        $query  = (string) ($data['query'] ?? '');

        if ($action === 'publish') {
            // The stream key alone is NOT enough. The key is the HLS
            // playback path, so it is in every member's hands - a member
            // could otherwise push their own video into the operator's
            // path, and the recording is imported into a paid gallery and
            // auto-posted to social media. Publishing needs a second
            // secret that only the operator app ever sees.
            $streamKey = strtok($path, '/') ?: '';
            parse_str($query, $q);
            $publishSecret = (string) ($q['publish'] ?? '');

            if ($streamKey !== '' && LiveSession::validPublishSecret($streamKey, $publishSecret)) {
                LiveSession::markLive($streamKey);
                http_response_code(200);
                echo 'ok';
                return;
            }
            http_response_code(401);
            echo 'denied';
            return;
        }

        if ($action === 'read') {
            // The server's own recorder (loopback) authenticates as the
            // internal 'rec' reader (RTSP) or via ?rec=1&recpass= (HLS) so it
            // can capture the stream; member reads use a signed playback token.
            if ($this->validRecorder($data)) {
                http_response_code(200);
                echo 'ok';
                return;
            }

            $streamKey = strtok($path, '/') ?: '';
            parse_str($query, $q);
            if (($q['rec'] ?? '') === '1'
                && !empty($q['recpass'])
                && hash_equals(hash('sha256', (string) env_value('GALLERY_MEDIA_KEY', '')), (string) $q['recpass'])) {
                http_response_code(200);
                echo 'ok';
                return;
            }

            $token = (string) ($q['t'] ?? '');
            if ($streamKey !== '' && $token !== '' && LiveSession::validPlaybackToken($streamKey, $token)) {
                http_response_code(200);
                echo 'ok';
                return;
            }
            http_response_code(401);
            echo 'denied';
            return;
        }

        http_response_code(401);
        echo 'denied';
    }

    /**
     * Operator app: open a broadcast session. Returns the RTMP URL + one-time
     * stream key. Only one broadcast can be active at a time.
     */
    public function start(): void
    {
        $operator = $this->operator();
        if ($operator === null) {
            http_response_code(403);
            $this->json(['ok' => false, 'error' => 'A valid operator token is required.']);
            return;
        }

        $active = LiveSession::active();
        if ($active !== null) {
            // The previous session may be stale: 'pending' (created but never
            // published), or the operator's own earlier broadcast whose RTMP
            // connection is still lingering in MediaMTX (app backgrounded /
            // killed without /live/stop). Either way, when it is the same
            // operator we close the old path and start fresh. Only a
            // genuinely-live stream from a *different* account is blocked.
            $sameOperator = (int) $active['created_by'] === (int) $operator['created_by'];
            if (!$sameOperator) {
                $status        = LiveSession::status();
                $genuinelyLive = $status['live'] && $status['session_id'] === (int) $active['id'];
                if ($genuinelyLive) {
                    $this->json(['ok' => false, 'error' => 'A live stream is already active. Stop it first.']);
                    return;
                }
            }

            LiveSession::closeAllPaths();
            LiveSession::markEnded((int) $active['id']);
        }

        $rawKey        = bin2hex(random_bytes(24));
        $rawPublishKey = bin2hex(random_bytes(24));
        LiveSession::create($rawKey, (int) $operator['created_by'], $rawPublishKey);

        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        if ($host === '') {
            $host = parse_url((string) env_value('APP_URL', ''), PHP_URL_HOST) ?: '127.0.0.1';
        }

        $this->json([
            'ok'         => true,
            // The publish secret travels in the URL, because MediaMTX passes
            // the publish query string to the auth webhook. The url is built
            // by LiveSession::rtmpUrl(), which percent-encodes the query so
            // it survives the app's URL parser as a QUERY and not as part of
            // the play path.
            'rtmp_url'   => LiveSession::rtmpUrl($host, $rawKey, $rawPublishKey),
            'stream_key' => $rawKey,
        ]);
    }

    /** Operator app: end the broadcast (saving the recording into a gallery). */
    public function stop(): void
    {
        $operator = $this->operator();
        if ($operator === null) {
            http_response_code(403);
            $this->json(['ok' => false, 'error' => 'A valid operator token is required.']);
            return;
        }

        // Import the recording first (the .ts file is finalized once it exists).
        // The app derives the key from the RTMP url it was given, so it
        // arrives url-encoded with the publish secret attached.
        $streamKey = LiveSession::rawKeyFromUrl((string) $this->request->post('stream_key', ''));
        if ($streamKey !== '') {
            $galleryId = \App\Models\LiveRecording::finalize($streamKey);
            if ($galleryId !== null) {
                error_log('[live] recording saved to gallery #' . $galleryId);
            }
        }

        $active = LiveSession::active();
        if ($active !== null) {
            LiveSession::markEnded((int) $active['id']);
        }

        // Force-disconnect the publisher so MediaMTX drops the path (a phone
        // whose RTMP connection survives the app's stop can otherwise keep the
        // stream 'live' and block the next broadcast).
        LiveSession::closeAllPaths();

        $this->json(['ok' => true]);
    }

    /** Member player page (login + subscription) with the live group chat. */
    public function page(): void
    {
        Auth::requireLogin();
        Auth::requireSubscription();

        $this->view('live', $this->liveState() + ['title' => 'Live', 'noindex' => true]);
    }

    /** JSON live state (player URL + token + chat) polled by the /live page so
     *  the player/chat appear the moment a broadcast starts. */
    public function state(): void
    {
        Auth::requireLogin();
        Auth::requireSubscription();

        $this->json(['ok' => true] + $this->liveState());
    }

    /** Live state for the member page: player URL/token + recent chat rows. */
    private function liveState(): array
    {
        $status = LiveSession::status();

        $chatMessages = [];
        $chatLatest   = 0;
        if ($status['live'] && $status['session_id'] !== null) {
            $chatLatest = (int) Database::run(
                'SELECT COALESCE(MAX(id), 0) FROM live_chat_messages WHERE session_id = ?',
                [$status['session_id']]
            )->fetchColumn();
            $chatMessages = $this->chatRows((int) $status['session_id'], max(0, $chatLatest - 200));
        }

        return [
            'live'         => $status['live'],
            'since'        => $status['since'],
            'viewers'      => $status['viewers'],
            'paused'       => (bool) ($status['paused'] ?? false),
            'streamKey'    => $status['stream_key'],
            'token'        => $status['live'] && $status['stream_key'] !== null
                ? LiveSession::playbackToken((string) $status['stream_key'], (int) Auth::user()['id'])
                : '',
            'chatLatest'   => $chatLatest,
            'chatMessages' => $chatMessages,
        ];
    }

    /** Live/offline status JSON (drives badges on the dashboard + chat). */
    public function status(): void
    {
        $status = LiveSession::status();
        $this->json([
            'ok'      => true,
            'live'    => $status['live'],
            'since'   => $status['since'],
            'viewers' => $status['viewers'],
            'paused'  => (bool) ($status['paused'] ?? false),
        ]);
    }

    /** Operator pauses the broadcast without ending it (viewers see a message). */
    public function pause(): void
    {
        $operator = $this->operator();
        if ($operator === null) {
            http_response_code(403);
            $this->json(['ok' => false, 'error' => 'A valid operator token is required.']);
            return;
        }
        $active = LiveSession::active();
        if ($active !== null) {
            Database::run(
                'UPDATE live_sessions SET paused_at = COALESCE(paused_at, CURRENT_TIMESTAMP) WHERE id = ?',
                [(int) $active['id']]
            );
        }
        $this->json(['ok' => true]);
    }

    /** Operator resumes a paused broadcast. */
    public function resume(): void
    {
        $operator = $this->operator();
        if ($operator === null) {
            http_response_code(403);
            $this->json(['ok' => false, 'error' => 'A valid operator token is required.']);
            return;
        }
        $active = LiveSession::active();
        if ($active !== null) {
            Database::run(
                'UPDATE live_sessions SET paused_at = NULL WHERE id = ?',
                [(int) $active['id']]
            );
        }
        $this->json(['ok' => true]);
    }

    /** Member sends a message to the live group chat. */
    public function chatSend(): void
    {
        $user = $this->liveChatUser();
        if ($user === null) {
            http_response_code(403);
            $this->json(['ok' => false, 'error' => 'Not authorised to send live chat.']);
            return;
        }
        $userId = (int) $user['id'];

        // The live chat had NO rate limit at all: a member could post
        // unlimited messages, and there is no moderation queue for them
        // either - live_chat_messages has no admin screen.
        if (!RateLimiter::allow(['live_chat_send:' . $userId], 30, 60)) {
            http_response_code(429);
            $this->json(['ok' => false, 'error' => 'Slow down a moment.']);
            return;
        }

        $status = LiveSession::status();
        if (!$status['live'] || $status['session_id'] === null) {
            $this->json(['ok' => false, 'error' => 'No live stream is active right now.']);
            return;
        }

        $message = trim((string) $this->request->post('message', ''));
        if ($message === '' || mb_strlen($message) > 500) {
            $this->json(['ok' => false, 'error' => 'Message must be 1–500 characters.']);
            return;
        }

        $senderRole = \App\Core\Auth::isAdminRole($user) ? 'operator' : 'user';

        // Moderation applies to members only: mutes and the site-wide word
        // filter never block the operator's own replies.
        if ($senderRole === 'user') {
            $mutedUntil = (string) ($user['chat_muted_until'] ?? '');
            if ($mutedUntil !== '' && $mutedUntil > gmdate('Y-m-d H:i:s')) {
                $this->json([
                    'ok'    => false,
                    'error' => 'You are muted from live chat until ' . tzdate('Y-m-d H:i', $mutedUntil) . ' (site time).',
                ]);
                return;
            }

            $lower = mb_strtolower($message);
            foreach (\App\Core\ChatSettings::liveChatFilters() as $word) {
                if (mb_strpos($lower, $word) !== false) {
                    $this->json(['ok' => false, 'error' => 'Your message was blocked by the live-chat word filter.']);
                    return;
                }
            }
        }

        Database::run(
            'INSERT INTO live_chat_messages (session_id, user_id, sender_role, message, created_at) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)',
            [
                (int) $status['session_id'],
                $userId,
                $senderRole,
                $message,
            ]
        );

        \App\Core\Cache::bump('chat');

        $this->json(['ok' => true, 'id' => (int) Database::connection()->lastInsertId()]);
    }

    /** The current chat participant: a logged-in member, or the operator app
     *  (Bearer shared key / operator token), attributed to the admin account. */
    private function liveChatUser(): ?array
    {
        $member = Auth::user();
        if ($member !== null) {
            return $member;
        }

        $operator = $this->operator();
        if ($operator !== null) {
            $admin = Database::run(
                'SELECT id, role, chat_muted_until FROM users WHERE id = ? LIMIT 1',
                [(int) $operator['created_by']]
            )->fetch();
            if ($admin !== false) {
                return $admin;
            }
        }

        return null;
    }

    /** SSE long-poll of the live group chat. */
    public function chatStream(): void
    {
        if ($this->liveChatUser() === null) {
            http_response_code(403);
            return;
        }

        $since  = max(0, (int) $this->request->query('since', 0));
        $status = LiveSession::status();

        start_sse();
        session_write_close();

        if (!$status['live'] || $status['session_id'] === null) {
            echo "data: {\"ok\":true,\"messages\":[],\"latestId\":0}\n\n";
            echo "retry: 3000\n\n";
            flush();
            exit;
        }

        $sid      = (int) $status['session_id'];
        $latestId = (int) Database::run(
            'SELECT COALESCE(MAX(id), 0) FROM live_chat_messages WHERE session_id = ?',
            [$sid]
        )->fetchColumn();

        $rows = $this->chatRows($sid, $since);
        if ($rows !== []) {
            $this->sse($rows, $latestId);
            flush();
            $since = $latestId;
        }

        $start = time();
        // Short window: Apache/mod_proxy_fcgi buffers SSE and flushes on
        // completion, so a ~5s loop delivers chat messages in quick bursts
        // instead of the 30s the member chat uses.
        while (time() - $start < 5) {
            $headId = (int) Database::run(
                'SELECT COALESCE(MAX(id), 0) FROM live_chat_messages WHERE session_id = ?',
                [$sid]
            )->fetchColumn();
            if ($headId > $since) {
                $rows = $this->chatRows($sid, $since);
                if ($rows !== []) {
                    $this->sse($rows, $headId);
                    flush();
                    $since = $headId;
                    continue;
                }
            }

            // Heartbeat so proxies don't kill the connection.
            echo ": ping\n\n";
            flush();
            sleep(1);
        }
    }

    /** Chat rows for a session newer than $since, with the sender's name. */
    private function chatRows(int $sessionId, int $since): array
    {
        $rows = Database::run(
            'SELECT m.id, m.user_id, m.sender_role, m.message, m.created_at, u.email
             FROM live_chat_messages m
             JOIN users u ON u.id = m.user_id
             WHERE m.session_id = ? AND m.id > ?
             ORDER BY m.id ASC LIMIT 200',
            [$sessionId, $since]
        )->fetchAll();

        foreach ($rows as &$r) {
            $name = (string) ($r['email'] ?? 'member');
            $r['name'] = $r['sender_role'] === 'operator' ? 'Operator' : (strpos($name, '@') !== false ? substr($name, 0, strpos($name, '@')) : $name);
        }
        unset($r);

        return $rows;
    }

    private function sse(array $rows, int $latestId): void
    {
        echo 'data: ' . json_encode(['ok' => true, 'messages' => $rows, 'latestId' => $latestId], JSON_UNESCAPED_SLASHES) . "\n\n";
    }

    /** Resolve the current operator from a Bearer token: a per-device operator
     *  token, or (for migration) the legacy shared GALLERY_CHAT_KEY, in which
     *  case the session is attributed to the first admin account. */
    private function operator(): ?array
    {
        $given = trim((string) $this->request->header('Authorization', ''));
        if (stripos($given, 'bearer ') === 0) {
            $given = trim(substr($given, 7));
        }
        if ($given === '') {
            return null;
        }

        $token = OperatorToken::authenticate($given);
        if ($token !== null) {
            return ['created_by' => (int) $token['created_by']];
        }

        // The legacy shared key (GALLERY_CHAT_KEY) is for the CHAT only. It
        // used to be accepted here too, which meant a key that is
        // distributed to every operator's phone could start and stop the
        // broadcast - and the recording of that broadcast is imported into a
        // paid gallery and auto-posted to social media. Live control needs a
        // DEVICE token, so a leaked shared key cannot take the stream.

        return null;
    }

    /** Read the raw request body (JSON). */

    /**
     * Whether the auth webhook call is from the server's own ffmpeg recorder
     * (user 'rec' + password = sha256 of GALLERY_MEDIA_KEY). Only allowed for
     * reads; the recorder runs on this box and captures the live stream.
     */
    private function validRecorder(array $data): bool
    {
        if ((string) ($data['user'] ?? '') !== 'rec') {
            return false;
        }
        $secret = (string) env_value('GALLERY_MEDIA_KEY', '');
        if ($secret === '') {
            return false;
        }

        return hash_equals(hash('sha256', $secret), (string) ($data['password'] ?? ''));
    }
}