<?php

namespace App\Core;

/**
 * Tiny Redis-backed cache with an in-memory fallback. Used for expensive
 * per-request computations that change rarely (e.g. rendered theme CSS).
 * Falls back to a static array when the phpredis extension or server is
 * unavailable, so a Redis outage never breaks the site.
 */
class Cache
{
    private static ?\Redis $redis = null;
    private static bool $checked = false;
    private static array $local = [];

    /**
     * Read a cached value, or compute and store it when missing.
     */
    public static function remember(string $key, int $ttl, callable $callback): string
    {
        $hit = self::get($key);

        if ($hit !== null) {
            return $hit;
        }

        // The stampede guard. Without it, N concurrent requests on a cold
        // cache all run the producer - and the producer for a listing is the
        // whole catalogue. One of them wins the lock and does the work; the
        // others wait for it and then read the answer.
        // When the store is unavailable the guard CANNOT work: acquireLock
        // returns null for everyone and waitFor would drain its whole
        // deadline on every producer, so run the callback directly.
        if (!self::available()) {
            return (string) $callback();
        }

        $lock = self::acquireLock($key, $ttl);

        if ($lock === null) {
            // Another process is producing it. Wait for the answer rather
            // than doing the work twice.
            $waited = self::waitFor($key, $ttl);

            if ($waited !== null) {
                return $waited;
            }
        }

        try {
            // Re-read: the winner may have finished while we waited.
            $hit = self::get($key);

            if ($hit !== null) {
                return $hit;
            }

            $value = (string) $callback();

            self::set($key, $value, $ttl);

            return $value;
        } finally {
            if ($lock !== null) {
                self::releaseLock($lock);
            }
        }
    }

    /**
     * Take the producer lock for a key, or null when someone else has it.
     *
     * The stampede guard: one process produces the value, the others wait
     * for it instead of doing the same work.
     */
    public static function lock(string $key, int $ttl): ?string
    {
        return self::acquireLock($key, $ttl);
    }

    /**
     * Give a lock back.
     */
    public static function unlock(?string $lock): void
    {
        if ($lock !== null) {
            self::releaseLock($lock);
        }
    }

    /**
     * Wait for another process to produce the value.
     */
    public static function wait(string $key, int $ttl): ?string
    {
        return self::waitFor($key, $ttl);
    }

    private static function acquireLock(string $key, int $ttl): ?string
    {
        $redis = self::connection();

        if ($redis === null) {
            return null;
        }

        $lockKey = self::lockKey($key);
        $token   = $lockKey . '|' . bin2hex(random_bytes(8));

        // SET NX EX: only one process can take it, and it expires on its
        // own so a crashed producer cannot wedge the key forever.
        $won = $redis->set($lockKey, $token, ['NX', 'EX' => max(5, (int) ceil($ttl / 3))]);

        return $won ? $token : null;
    }

    /**
     * Give the producer lock back, but only if we are still the holder.
     */
    private static function releaseLock(string $token): void
    {
        // The lock key is carried in the token so release() can find it
        // without re-deriving it.
        [$lockKey, $value] = array_pad(explode('|', $token, 2), 2, '');
        $redis = self::connection();

        if ($redis === null || $lockKey === '') {
            return;
        }

        // Only release our own lock.
        if ((string) $redis->get($lockKey) === $value) {
            $redis->del($lockKey);
        }
    }

    /**
     * Wait for another process to finish producing the value.
     */
    private static function waitFor(string $key, int $ttl): ?string
    {
        $deadline = microtime(true) + min(5.0, max(0.5, $ttl / 10));
        $sleep    = 50000;   // 50ms

        while (microtime(true) < $deadline) {
            usleep($sleep);
            $hit = self::get($key);
            if ($hit !== null) {
                return $hit;
            }
            if ($sleep < 500000) {
                $sleep *= 2;
            }
        }

        return null;
    }

    private static function lockKey(string $key): string
    {
        return 'lock:' . $key;
    }

    /**
     * Fetch a cached value, or null when absent.
     */
    public static function get(string $key): ?string
    {
        $redis = self::connection();

        if ($redis !== null) {
            $value = $redis->get($key);

            return $value === false ? null : (string) $value;
        }

        return self::localGet($key);
    }

    /**
     * Store a value with an expiry (seconds).
     */
    public static function set(string $key, string $value, int $ttl = 300): void
    {
        $redis = self::connection();

        if ($redis !== null) {
            $redis->setex($key, $ttl, $value);
            return;
        }

        self::$local[$key] = ['value' => $value, 'expires' => time() + $ttl];
        self::localPurge();
    }

    /**
     * Bound the in-memory fallback so a Redis outage on a long-lived process
     * (FPM worker that never recycles, cron/worker daemon, SSE loop) cannot
     * grow memory without limit. Expired entries are dropped first, then the
     * soonest-to-expire entries are evicted until the array fits the cap.
     * Only used on the Redis-less fallback path; the live Redis path never
     * touches this array.
     */
    private static function localPurge(int $keep = 500): void
    {
        if (count(self::$local) <= $keep) {
            return;
        }

        $now  = time();
        $alive = [];

        foreach (self::$local as $key => $entry) {
            $expires = (int) ($entry['expires'] ?? 0);
            if ($expires <= $now) {
                unset(self::$local[$key]);
            } else {
                $alive[$key] = $expires;
            }
        }

        if (count(self::$local) > $keep) {
            asort($alive);
            $excess = count(self::$local) - $keep;
            foreach (array_slice(array_keys($alive), 0, $excess) as $key) {
                unset(self::$local[$key]);
            }
        }
    }

/**
 * Remove a key (used when the cached value's source data changes).
 */
public static function forget(string $key): void
{
    $redis = self::connection();

    if ($redis !== null) {
        $redis->del($key);
        return;
    }

    unset(self::$local[$key]);
}

/**
 * Read from the in-memory fallback, honouring the TTL each value was
 * stored with.
 */
private static function localGet(string $key): ?string
{
    $entry = self::$local[$key] ?? null;

    if ($entry === null) {
        return null;
    }

    if (($entry['expires'] ?? 0) < time()) {
        unset(self::$local[$key]);
        return null;
    }

    return (string) $entry['value'];
}

/**
     * Whether the Redis backing store is usable right now. Fast check - the
     * connection is lazily established and pinned once per process, so the
     * cost of the first real ping only ever happens once.
     */
    public static function available(): bool
    {
        try {
            return self::connection() !== null;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * A monotonically increasing generation counter for a data bucket
     * (e.g. 'gallery', 'category', 'media'). Cache keys built with
     * generation() are invalidated atomically by bump() — no wildcard
     * deletes needed, and the cache never serves stale data after a write.
     */
public static function generation(string $bucket): int
{
    $value = self::get('gen:' . $bucket);

    if ($value === null || $value === '' || !ctype_digit($value)) {
        return 1;
    }

    return (int) $value;
}

/** Invalidate every cache key built under a data bucket. */
public static function bump(string $bucket): void
{
    $next = self::generation($bucket) + 1;
    $redis = self::connection();

    if ($redis !== null) {
        $redis->setex('gen:' . $bucket, 86400 * 30, (string) $next);
        return;
    }

    self::$local['gen:' . $bucket] = ['value' => (string) $next, 'expires' => time() + 86400 * 30];
}

/**
 * A cache key namespaced by a bucket's generation, so bump($bucket)
 * automatically invalidates it.
 */
public static function genKey(string $bucket, string $key): string
{
    return 'gen' . self::generation($bucket) . ':' . $bucket . ':' . $key;
}

/**
 * Cache a value under a generation-scoped key. Returns the cached string,
 * or computes + stores the callback result when missing. Array callbacks are
 * supported transparently: an array result is JSON-encoded and stored under a
 * distinct key (suffix ":a") so it can never collide with a string cache, and
 * a hit is decoded back to an array. This makes "forget to stringify" bugs
 * impossible instead of fatalling on the (string) cast.
 *
 * @return string|array
 */
public static function rememberGen(string $bucket, string $key, int $ttl, callable $callback)
{
    $cacheKey = self::genKey($bucket, $key);

    // Arrays are stored under their own key (suffix ":a") so a string cache
    // can never misinterpret an old value, and legacy plain-key strings are
    // never mistaken for arrays. Check the array key first so a caller that
    // switched from string to array after a legacy write still gets an array.
    $arrayKey = $cacheKey . ':a';
    $arrayHit = self::get($arrayKey);

    if ($arrayHit !== null) {
        $decoded = json_decode($arrayHit, true);

        return is_array($decoded) ? $decoded : [];
    }

    $hit = self::get($cacheKey);

    if ($hit !== null) {
        return $hit;
    }

    // The stampede guard, as in remember(). When the store is unavailable
    // the guard cannot work (acquireLock fails for everyone and waitFor
    // would drain its whole deadline per caller), so run the callback now.
    if (!self::available()) {
        $value = $callback();

        if (is_array($value)) {
            return $value;
        }

        return (string) $value;
    }

    $lock = self::acquireLock($cacheKey, $ttl);

    if ($lock === null) {
        $waited = self::waitFor($cacheKey, $ttl);
        if ($waited !== null) {
            // The same shape the normal path returns. The plain key holds
            // strings (an array is stored under its own ":a" key), and
            // decoding it here handed the caller an ARRAY where it expected
            // a string - a caller that json_decodes the result then died
            // with a TypeError, which took the whole page down whenever two
            // requests raced for the same key.
            return $waited;
        }
    }

    try {
        $value = $callback();

        if (is_array($value)) {
            self::set($arrayKey, json_encode($value, JSON_UNESCAPED_SLASHES) ?: '[]', $ttl);

            return $value;
        }

        $string = (string) $value;
        self::set($cacheKey, $string, $ttl);

        return $string;
    } finally {
        if ($lock !== null) {
            self::releaseLock($lock);
        }
    }
}

    /**
     * Shared Redis connection, or null when unavailable.
     */
    private static function connection(): ?\Redis
    {
        if (!class_exists('Redis')) {
            return null;
        }

        if (!self::$checked) {
            self::$checked = true;

            try {
                self::$redis = new \Redis();
                self::$redis->pconnect('127.0.0.1', 6379, 0.5, 'gallery-cache');

                // Loopback-only Redis with a server-side requirepass is the
                // defense-in-depth posture; the password lives in .env
                // (REDIS_PASSWORD, root-only) and never in the repo.
                $password = (string) env_value('REDIS_PASSWORD', '');
                if ($password !== '') {
                    self::$redis->auth($password);
                }

                self::$redis->ping();
            } catch (\Throwable $e) {
                self::$redis = null;
            }
        }

        return self::$redis;
    }
}