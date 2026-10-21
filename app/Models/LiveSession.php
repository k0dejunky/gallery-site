<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Live video sessions. The operator app opens a session (via /live/start)
 * which returns a one-time RTMP stream key; only its SHA-256 is stored. The
 * MediaMTX auth webhook validates publish (stream key) and read (signed
 * playback token), and the site polls MediaMTX's API to report live/offline.
 */
class LiveSession
{
    private const TABLE = 'live_sessions';

    public static function hashOf(string $rawKey): string
    {
        return hash('sha256', $rawKey);
    }

    /**
     * The raw stream key out of what the operator app posted.
     *
     * The app takes the key out of the RTMP url it was given
     * (rtmpUrl.substringAfterLast('/')), and that url carries the publish
     * secret percent-encoded ('<key>%3Fpublish%3D<secret>'). So the key
     * arrives url-encoded, and the secret has to be stripped off it - the
     * key alone is what identifies the session and the recording.
     *
     * A key that is already clean (the orphan importer reads it off the
     * filesystem) passes through unchanged.
     */
    public static function rawKeyFromUrl(string $value): string
    {
        $value = trim(rawurldecode(trim($value)));
        $q = strpos($value, '?');

        return $q === false ? $value : substr($value, 0, $q);
    }

    /** The active (pending or live) session, or null. */
    public static function active(): ?array
    {
        $row = Database::run(
            'SELECT * FROM ' . self::TABLE . ' WHERE status IN (?, ?) ORDER BY id DESC LIMIT 1',
            ['pending', 'live']
        )->fetch();

        return $row ?: null;
    }

    public static function create(string $rawKey, int $createdBy, ?string $rawPublishSecret = null): int
    {
        Database::run(
            'INSERT INTO ' . self::TABLE . ' (stream_key, publish_secret, created_by, status, created_at)
             VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)',
            [
                self::hashOf($rawKey),
                $rawPublishSecret !== null ? self::hashOf($rawPublishSecret) : null,
                $createdBy,
                'pending',
            ]
        );

        return (int) Database::connection()->lastInsertId();
    }

    /**
     * The RTMP url the operator app publishes to.
     *
     * The publish secret travels in the url, because that is how MediaMTX
     * passes it to the auth webhook. The '?' is PERCENT-ENCODED, and that
     * is not cosmetic:
     *
     * The app's RTMP library (RootEncoder 2.6.1) parses the url with
     * java.net.URI, which DECODES the path, and then splits it into an
     * RTMP 'app' name and a play path. With a literal '?' the query would
     * be folded into the play path, so the client would publish to the
     * path '<key>/publish=<secret>' and MediaMTX would see NO query at
     * all - the secret would never reach the auth webhook (the publish
     * would be denied), the published path would no longer match the HLS
     * read path, and the recorder's $MTX_PATH would fail its
     * ^[A-Za-z0-9]+$ check.
     *
     * Encoded, the library keeps the whole thing in the app name
     * ('<key>?publish=<secret>') and MediaMTX splits that back into the
     * path '<key>' and the query 'publish=<secret>' - which is exactly
     * what the auth webhook and the HLS read path expect.
     */
    public static function rtmpUrl(string $host, string $rawKey, string $rawPublishSecret): string
    {
        return 'rtmp://' . $host . ':1935/'
            . rawurlencode($rawKey . '?publish=' . $rawPublishSecret);
    }

    /**
     * Whether a publish request carries this session's publish secret.
     *
     * The stream key alone is NOT enough. The key is the HLS playback path,
     * so it is in every member's hands; publishing needs a second secret
     * that only the operator app ever sees.
     */
    public static function validPublishSecret(string $rawKey, string $rawSecret): bool
    {
        if ($rawKey === '' || $rawSecret === '') {
            return false;
        }

        $row = Database::run(
            'SELECT id FROM ' . self::TABLE . '
             WHERE stream_key = ? AND publish_secret = ? AND status IN (?, ?) LIMIT 1',
            [self::hashOf($rawKey), self::hashOf($rawSecret), 'pending', 'live']
        )->fetch();

        return $row !== false;
    }

    /** Mark a pending session live (first publisher connected). */
    public static function markLive(string $rawKey): bool
    {
        return (bool) Database::run(
            'UPDATE ' . self::TABLE . ' SET status = ?, started_at = COALESCE(started_at, CURRENT_TIMESTAMP)
             WHERE stream_key = ? AND status IN (?, ?)',
            ['live', self::hashOf($rawKey), 'pending', 'live']
        )->rowCount();
    }

    /** Mark a session ended. */
    public static function markEnded(int $id): void
    {
        Database::run(
            'UPDATE ' . self::TABLE . ' SET status = ?, ended_at = CURRENT_TIMESTAMP WHERE id = ?',
            ['ended', $id]
        );
    }

    /** Whether a raw stream key matches an active session. */
    /**
     * Force-close every currently-published MediaMTX path. Used when the
     * operator starts a fresh broadcast: the phone's previous RTMP connection
     * can linger in the background after a session ends (app backgrounded /
     * killed), leaving MediaMTX's path 'ready' and blocking a new start. The
     * site only ever has the operator's own stream, so closing all paths is
     * safe here.
     */
    public static function closeAllPaths(): void
    {
        try {
            [$status, , $body] = Http::request('http://127.0.0.1:9997/v2/paths/list', [
                'method'  => 'GET',
                'timeout' => 3,
            ]);
            if ($status < 200 || $status >= 300) {
                return;
            }
            $data = json_decode($body, true);
            foreach (($data['items'] ?? []) as $item) {
                $name = (string) ($item['name'] ?? '');
                if ($name === '') {
                    continue;
                }
                Http::request('http://127.0.0.1:9997/v2/paths/close/' . rawurlencode($name), [
                    'method'  => 'POST',
                    'timeout' => 3,
                ]);
            }
        } catch (\Throwable $error) {
            // MediaMTX unreachable: the next /live/start still creates a fresh
            // session; the stale row is cleared below regardless.
        }
    }

    /**
     * Whether a raw stream key is currently being published in MediaMTX (the
     * path is 'ready'). Used by the orphan importer so it never finalizes a
     * recording while the stream is still live.
     */
    public static function isPublishing(string $rawKey): bool
    {
        try {
            [$status, , $body] = Http::request('http://127.0.0.1:9997/v2/paths/list', [
                'method'  => 'GET',
                'timeout' => 3,
            ]);
            if ($status < 200 || $status >= 300) {
                return false;
            }
            $data = json_decode($body, true);
            foreach (($data['items'] ?? []) as $item) {
                if (!empty($item['ready']) && (string) ($item['name'] ?? '') === $rawKey) {
                    return true;
                }
            }
        } catch (\Throwable $error) {
            // MediaMTX unreachable: assume not publishing.
        }
        return false;
    }

    /**
     * Live state as seen by the site: checks MediaMTX's HTTP API for any
     * currently-published stream, matched back to our session row.
     *
     * @return array{live:bool, since:?string, viewers:int, stream_key:?string, session_id:?int}
     */
    public static function status(): array
    {
        try {
            [$status, , $body] = Http::request('http://127.0.0.1:9997/v2/paths/list', [
                'method'  => 'GET',
                'timeout' => 3,
            ]);

            if ($status >= 200 && $status < 300) {
                $data = json_decode($body, true);
                foreach (($data['items'] ?? []) as $item) {
                    if (!empty($item['ready'])) {
                        $key = (string) ($item['name'] ?? '');
                        $row = Database::run(
                            'SELECT * FROM ' . self::TABLE . ' WHERE stream_key = ? AND status = ? LIMIT 1',
                            [self::hashOf($key), 'live']
                        )->fetch();

                        if ($row !== false) {
                            self::maybeNotifyLive($row);
                        }

                        return [
                            'live'       => true,
                            'since'      => $row !== false ? (string) $row['started_at'] : null,
                            'viewers'    => (int) ($item['readers'] ?? 0),
                            'stream_key' => $key,
                            'session_id' => $row !== false ? (int) $row['id'] : null,
                            'paused'     => $row !== false && !empty($row['paused_at']),
                        ];
                    }
                }
            }
        } catch (\Throwable $error) {
            // MediaMTX down: treat as offline.
        }

        return ['live' => false, 'since' => null, 'viewers' => 0, 'stream_key' => null, 'session_id' => null, 'paused' => false];
    }

    /**
     * Email members who enabled notify_live that the stream is genuinely up,
     * exactly once per session (guarded by live_sessions.live_notified_at).
     * Called from status() because that is the first place a session is known
     * to be truly live (RTMP path ready); the atomic claim means a flood of
     * status() polls still enqueues only one mail.
     */
    private static function maybeNotifyLive(array $row): void
    {
        if (!empty($row['live_notified_at'])) {
            return;
        }

        // One live email a day (see the same guard in Gallery). Claiming the
        // marker before checking burned it on a mail that was never queued.
        if (\App\Models\EmailQueue::broadcastSentToday('live')) {
            return;
        }

        $claimed = Database::run(
            'UPDATE ' . self::TABLE . ' SET live_notified_at = CURRENT_TIMESTAMP
             WHERE id = ? AND live_notified_at IS NULL',
            [(int) $row['id']]
        );

        if ((int) $claimed->rowCount() !== 1) {
            return; // a concurrent status() poll won the race
        }

        $subject = 'She is live now';
        $html    = render_email('live_now', []);
        $text    = render_email('live_now.text', []);

        \App\Models\EmailQueue::enqueueNotification('notify_live', $subject, $html, $text);
    }

    /**
     * A signed playback token bound to a stream key + member. Embedded in the
     * HLS URL; the MediaMTX auth webhook validates it (signature + expiry +
     * the member still being eligible) without needing the viewer's session.
     */
    public static function playbackToken(string $streamKey, int $userId, int $ttl = 43200): string
    {
        $secret = (string) env_value('GALLERY_MEDIA_KEY', '');
        $exp    = time() + max(60, $ttl);
        $sig    = hash_hmac('sha256', $streamKey . ':' . $userId . ':' . $exp, $secret);

        return $userId . '.' . $exp . '.' . $sig;
    }

    /** Validate a playback token for a stream key. */
    public static function validPlaybackToken(string $streamKey, string $token): bool
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return false;
        }
        [$userId, $exp, $sig] = $parts;

        if ((int) $exp < time() || !ctype_digit($userId)) {
            return false;
        }

        $secret = (string) env_value('GALLERY_MEDIA_KEY', '');
        $expect = hash_hmac('sha256', $streamKey . ':' . $userId . ':' . $exp, $secret);

        if (!hash_equals($expect, (string) $sig)) {
            return false;
        }

        // The member who received the token must still be able to watch: admins
        // always qualify; members need an active subscription.
        $user = Database::run(
            'SELECT role FROM users WHERE id = ? LIMIT 1',
            [(int) $userId]
        )->fetch();
        if ($user === false) {
            return false;
        }
        if (\App\Core\Auth::isAdminRole($user)) {
            return true;
        }

        return \App\Models\Subscription::isActive((int) $userId);
    }
}