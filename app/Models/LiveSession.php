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

    /** The active (pending or live) session, or null. */
    public static function active(): ?array
    {
        $row = Database::run(
            'SELECT * FROM ' . self::TABLE . ' WHERE status IN (?, ?) ORDER BY id DESC LIMIT 1',
            ['pending', 'live']
        )->fetch();

        return $row ?: null;
    }

    public static function create(string $rawKey, int $createdBy): int
    {
        Database::run(
            'INSERT INTO ' . self::TABLE . ' (stream_key, created_by, status, created_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP)',
            [self::hashOf($rawKey), $createdBy, 'pending']
        );

        return (int) Database::connection()->lastInsertId();
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
    public static function validStreamKey(string $rawKey): bool
    {
        $row = Database::run(
            'SELECT id FROM ' . self::TABLE . ' WHERE stream_key = ? AND status IN (?, ?) LIMIT 1',
            [self::hashOf($rawKey), 'pending', 'live']
        )->fetch();

        return $row !== false;
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

                        return [
                            'live'       => true,
                            'since'      => $row !== false ? (string) $row['started_at'] : null,
                            'viewers'    => (int) ($item['readers'] ?? 0),
                            'stream_key' => $key,
                            'session_id' => $row !== false ? (int) $row['id'] : null,
                        ];
                    }
                }
            }
        } catch (\Throwable $error) {
            // MediaMTX down: treat as offline.
        }

        return ['live' => false, 'since' => null, 'viewers' => 0, 'stream_key' => null, 'session_id' => null];
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
        if (in_array((string) $user['role'], \App\Core\Auth::ADMIN_ROLES, true)) {
            return true;
        }

        return \App\Models\Subscription::isActive((int) $userId);
    }
}