<?php

namespace App\Core;

/**
 * Fragment caching for guest-facing gallery listings.
 *
 * Only the card GRIDS are cached (their HTML is pure for guests: no CSRF,
 * no personal data - covers are the blurred/thumb variants with deterministic
 * URLs). Everything else on the page - age-gate overlay, search forms, CSRF
 * tokens - still renders fresh per request so sessions and security stay
 * intact.
 *
 * Design notes:
 * - Guest-only: Auth::check() short-circuits, so member pages are never served
 *   from the cache.
 * - Hero/LCP handling: the first grid on a page gets fetchpriority=high and
 *   eager loading. Fragment keys are split into ":hero"/":plain" variants, so
 *   a cached hit for the first grid still "claims" the hero slot and the next
 *   grid is stored under its plain variant. heroClaim() is only ever true
 *   inside the FIRST fragment production of a request, so grids below the
 *   fold never bake the hero attributes.
 * - Invalidation: keys are scoped by Cache::genKey('listings', ...) - callers
 *   bump('listings') after any gallery/media write and stale fragments fall
 *   out immediately. TTL is a 120s floor on top of that.
 * - Kill switch: config('app.page_cache') === false disables caching entirely
 *   (views then just render live through the producer closure).
 */
class PageCache
{
    private static bool $claimed = false;
    private static bool $activeHero = false;

    /**
     * Render (and cache) a fragment of guest-facing markup.
     * The producer closure should echo the fragment; the buffered string is
     * returned so the caller can echo it too if preferred.
     */
    public static function fragment(string $key, int $ttl, callable $producer): string
    {
        $enabled = (bool) config('app.page_cache')
            && !\App\Core\Auth::check()
            && \App\Core\Cache::available();

        $hero = !self::$claimed;
        self::$activeHero = $hero;

        $cacheKey = \App\Core\Cache::genKey('listings', $key . '@' . ($hero ? 'hero' : 'plain'));

        if ($enabled) {
            $cached = \App\Core\Cache::get($cacheKey);

            if ($cached !== null) {
                if ($hero) {
                    self::$claimed = true;
                }
                self::$activeHero = false;

                return $cached;
            }
        }

        ob_start();
        try {
            $producer();
        } finally {
            self::$activeHero = false;
        }
        $html = (string) ob_get_clean();

        if ($hero) {
            self::$claimed = true;
        }

        if ($enabled && $html !== '') {
            \App\Core\Cache::set($cacheKey, $html, $ttl);
        }

        return $html;
    }

    /**
     * True ONCE per fragment production: the gallery card partial calls this
     * when rendering each card, and the first true answer is "consumed" so
     * exactly one cover on the page gets eager loading + fetchpriority high.
     * Outside a fragment production it always returns false (the
     * $coverFetchPriority view flag still works independently).
     */
    public static function claimHero(): bool
    {
        if (!self::$activeHero) {
            return false;
        }

        self::$activeHero = false;

        return true;
    }

    /**
     * Invalidate all cached listing fragments (bump the shared bucket).
     * Safe to call multiple times; idempotent per write.
     */
    public static function invalidate(): void
    {
        \App\Core\Cache::bump('listings');
    }

    public static function reset(): void
    {
        self::$claimed = false;
        self::$activeHero = false;
    }
}