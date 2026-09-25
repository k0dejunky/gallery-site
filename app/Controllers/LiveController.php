<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
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
            $streamKey = strtok($path, '/') ?: '';
            parse_str($query, $q);
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
            // A stale 'pending' session (created but never actually published,
            // e.g. the app dropped the connection before streaming) blocks new
            // starts; clear it. A genuinely live stream must be stopped first.
            if ((string) $active['status'] === 'pending') {
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

    /** Operator app: end the broadcast. */
    public function stop(): void
    {
        $operator = $this->operator();
        if ($operator === null) {
            http_response_code(403);
            $this->json(['ok' => false, 'error' => 'A valid operator token is required.']);
            return;
        }

        $active = LiveSession::active();
        if ($active !== null) {
            LiveSession::markEnded((int) $active['id']);
        }

        $this->json(['ok' => true]);
    }

    /** Member player page. */
    public function page(): void
    {
        Auth::requireLogin();
        Auth::requireSubscription();

        $status = LiveSession::status();

        $this->view('live', [
            'title'      => 'Live',
            'live'       => $status['live'],
            'since'      => $status['since'],
            'viewers'    => $status['viewers'],
            'streamKey'  => $status['stream_key'],
            'token'      => $status['live'] && $status['stream_key'] !== null
                ? LiveSession::playbackToken((string) $status['stream_key'], (int) Auth::user()['id'])
                : '',
            'noindex'    => true,
        ]);
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

    /** Resolve the current operator from a Bearer operator token. */
    private function operator(): ?array
    {
        $given = trim((string) $this->request->header('Authorization', ''));
        if (stripos($given, 'bearer ') === 0) {
            $given = trim(substr($given, 7));
        }
        if ($given === '') {
            return null;
        }

        return OperatorToken::authenticate($given);
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
}