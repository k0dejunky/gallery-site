<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
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
            $streamKey = strtok($path, '/') ?: '';
            if ($streamKey !== '' && LiveSession::validStreamKey($streamKey)) {
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
            // A previous session may be stale: 'pending' (created but never
            // published, e.g. the app dropped the connection before streaming)
            // or 'live' in the DB while MediaMTX no longer has the publisher
            // (operator crashed / network died without /live/stop). Only a
            // broadcast that is actually being published right now blocks a
            // new start; anything else is cleared so the operator can retry.
            $status         = LiveSession::status();
            $genuinelyLive  = $status['live'] && $status['session_id'] === (int) $active['id'];
            if ((string) $active['status'] === 'pending' || !$genuinelyLive) {
                LiveSession::markEnded((int) $active['id']);
            } else {
                $this->json(['ok' => false, 'error' => 'A live stream is already active. Stop it first.']);
                return;
            }
        }

        $rawKey  = bin2hex(random_bytes(24));
        LiveSession::create($rawKey, (int) $operator['created_by']);

        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        if ($host === '') {
            $host = parse_url((string) env_value('APP_URL', ''), PHP_URL_HOST) ?: '127.0.0.1';
        }

        $this->json([
            'ok'         => true,
            'rtmp_url'   => 'rtmp://' . $host . ':1935/' . $rawKey,
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
        $streamKey = trim((string) $this->request->post('stream_key', ''));
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
        ]);
    }

    /** Member sends a message to the live group chat. */
    public function chatSend(): void
    {
        Auth::requireLogin();
        Auth::requireSubscription();
        $user   = Auth::user();
        $userId = (int) $user['id'];

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

        Database::run(
            'INSERT INTO live_chat_messages (session_id, user_id, sender_role, message, created_at) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)',
            [
                (int) $status['session_id'],
                $userId,
                in_array((string) ($user['role'] ?? ''), \App\Core\Auth::ADMIN_ROLES, true) ? 'operator' : 'user',
                $message,
            ]
        );

        \App\Core\Cache::bump('chat');

        $this->json(['ok' => true, 'id' => (int) Database::connection()->lastInsertId()]);
    }

    /** SSE long-poll of the live group chat. */
    public function chatStream(): void
    {
        Auth::requireLogin();

        $since  = max(0, (int) $this->request->query('since', 0));
        $status = LiveSession::status();

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');
        session_write_close();
        @ini_set('output_buffering', 'off');
        @ini_set('zlib.output_compression', 'off');
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }

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

        // Legacy shared key (GALLERY_CHAT_KEY): the app may still be signed in
        // with it. Attribute the session to the first admin account.
        $expected = (string) env_value('GALLERY_CHAT_KEY', '');
        if ($expected !== '' && hash_equals($expected, $given)) {
            $admin = Database::run(
                'SELECT id FROM users WHERE role IN (' . implode(',', array_fill(0, count(\App\Core\Auth::ADMIN_ROLES), '?')) . ') ORDER BY id ASC LIMIT 1',
                \App\Core\Auth::ADMIN_ROLES
            )->fetch();

            return $admin !== false ? ['created_by' => (int) $admin['id']] : null;
        }

        return null;
    }

    /** Read the raw request body (JSON). */
    private function rawBody(): string
    {
        return (string) file_get_contents('php://input');
    }

    private function json(array $data): void
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_SLASHES);
        exit;
    }

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