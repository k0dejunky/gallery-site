<?php

namespace App\Core;

/**
 * A small process-safe limiter whose state contains only key hashes and
 * counts.
 *
 * The state used to live in ONE file that every rate-limited request had to
 * lock exclusively, read, change and rewrite. Every request that touched a
 * limit - a comment, a rating, a chat message, a login attempt, a live chat
 * line - serialised on that one lock and rewrote the whole file, so the
 * limiter was the bottleneck of the site under load and a slow write made
 * every other request wait.
 *
 * The state is now SHARDED: an identifier's counter lives in the shard its
 * hash picks, so two different identifiers almost never touch the same
 * file, and the lock is held only for that shard. The limiter's behaviour
 * is unchanged - the same identifiers, the same limits, the same windows,
 * the same "deny when the file cannot be locked" fail-safe.
 */
class RateLimiter
{
    /** How many state files the counters are spread over. */
    private const SHARDS = 16;

    public static function allow(array $identifiers, int $limit, int $window): bool
    {
        $allowed = true;

        foreach ($identifiers as $identifier) {
            if (!self::allowOne((string) $identifier, $limit, $window)) {
                $allowed = false;
            }
        }

        return $allowed;
    }

    /**
     * One identifier: check the counter, count it, keep the window.
     *
     * The counter is keyed by a hash, so the state file never holds an
     * address, a token or anything else that identifies the caller.
     */
    private static function allowOne(string $identifier, int $limit, int $window): bool
    {
        $key   = hash('sha256', $identifier);
        $path  = self::path($key);
        $handle = @fopen($path, 'c+');

        if ($handle === false || !flock($handle, LOCK_EX)) {
            if (is_resource($handle)) {
                fclose($handle);
            }

            // Fail closed: a limiter that cannot count must not let the
            // request through.
            return false;
        }

        $contents = stream_get_contents($handle);
        $state    = is_string($contents) && $contents !== '' ? json_decode($contents, true) : [];
        $state    = is_array($state) ? $state : [];
        $now      = time();

        // Drop the counters whose window has passed.
        $state = array_filter(
            $state,
            static fn ($entry): bool => is_array($entry) && (int) ($entry['expires'] ?? 0) > $now
        );

        $entry = $state[$key] ?? ['count' => 0, 'expires' => $now + $window];
        if ((int) $entry['expires'] <= $now) {
            $entry = ['count' => 0, 'expires' => $now + $window];
        }

        $allowed = (int) $entry['count'] < $limit;
        $entry['count']++;
        $state[$key] = $entry;

        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode($state, JSON_THROW_ON_ERROR));
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);

        return $allowed;
    }

    /**
     * The state file an identifier's counter lives in.
     *
     * The shard is the first two hex characters of the key hash: 16 files,
     * each holding a fraction of the counters.
     */
    private static function path(string $key): string
    {
        $shard = substr($key, 0, 2);
        $index = hexdec($shard) % self::SHARDS;

        return dirname(__DIR__, 2) . '/storage/security-rate-limit-' . $index . '.json';
    }
}
