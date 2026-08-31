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

        return self::$local[$key] ?? null;
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

        self::$local[$key] = $value;
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
                self::$redis->connect('127.0.0.1', 6379, 0.5);
                self::$redis->ping();
            } catch (\Throwable $e) {
                self::$redis = null;
            }
        }

        return self::$redis;
    }
}