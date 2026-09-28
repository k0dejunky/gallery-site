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

        $value = (string) $callback();

        self::set($key, $value, $ttl);

        return $value;
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

    $value = $callback();

    if (is_array($value)) {
        self::set($arrayKey, json_encode($value, JSON_UNESCAPED_SLASHES) ?: '[]', $ttl);

        return $value;
    }

    $string = (string) $value;
    self::set($cacheKey, $string, $ttl);

    return $string;
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
                self::$redis->ping();
            } catch (\Throwable $e) {
                self::$redis = null;
            }
        }

        return self::$redis;
    }
}