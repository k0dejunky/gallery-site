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
    /**
     * The query string as a cache key, without the noise.
     *
     * The raw query string was part of the page cache key, so every
     * tracking parameter (?utm_source, ?fbclid, ?gclid, ...) was its own
     * cache entry - a whole page each - and Redis had no maxmemory. The
     * parameters that MATTER to a page (the search, the filters, the page
     * number) are kept; the rest are dropped.
     *
     * @var string[] $keep
     */
    public static function normaliseQuery(string $query, array $keep = ['q', 'page', 'page_images', 'page_videos', 'type', 'category', 'tag', 'sort', 'period', 'vt']): string
    {
        if ($query === '') {
            return '';
        }

        $pairs = [];
        parse_str($query, $parsed);

        foreach ($keep as $name) {
            if (!array_key_exists($name, $parsed)) {
                continue;
            }
            $value = $parsed[$name];
            if (is_array($value)) {
                $value = implode(',', $value);
            }
            $pairs[] = $name . '=' . (string) $value;
        }

        sort($pairs);

        return implode('&', $pairs);
    }

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

        // The stampede guard: one process renders the grid, the others wait
        // for it. Without it, a cold cache had every concurrent request
        // render the whole catalogue and write the same 393 KB back.
        $lock = $enabled ? \App\Core\Cache::lock($cacheKey, $ttl) : null;

        if ($enabled && $lock === null) {
            $waited = \App\Core\Cache::wait($cacheKey, $ttl);
            if ($waited !== null) {
                if ($hero) {
                    self::$claimed = true;
                }
                self::$activeHero = false;

                return $waited;
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

        if ($lock !== null) {
            \App\Core\Cache::unlock($lock);
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

    /**
     * Whether this handler's whole rendered page may be served from the guest
     * page cache. Only the hot PUBLIC listing pages qualify, and only for
     * guests with Redis + the kill-switch available - members always render
     * live so their personally-tailored data is never stale.
     */
    public static function pageAllows(string $handler): bool
    {
        $allowlist = [
            'HomeController@index',
            'GalleryController@index',
            'GalleryController@indexType',
            'GalleryController@category',
            'GalleryController@tag',
        ];

        return in_array($handler, $allowlist, true)
            && (bool) config('app.page_cache')
            && !\App\Core\Auth::check()
            && \App\Core\Cache::available();
    }

    public static function pageKey(string $identity): string
    {
        return 'page:' . md5((string) $identity);
    }

    /**
     * The cached page and the Link header it asked for.
     *
     * The header has to travel with the page: a header() call in a view is
     * lost when the page is served from the cache, because the action never
     * runs again. The value is kept next to the bytes instead.
     *
     * @return array{html: string, link: string|null}|null
     */
    public static function pageGet(string $key): ?array
    {
        $raw = \App\Core\Cache::get(\App\Core\Cache::genKey('listings', $key));
        if ($raw === null || $raw === '') {
            return null;
        }

        // A page cached before the Link header existed is plain HTML.
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['h'])) {
            return ['html' => $raw, 'link' => null];
        }

        return [
            'html' => (string) $decoded['h'],
            'link' => isset($decoded['l']) && (string) $decoded['l'] !== '' ? (string) $decoded['l'] : null,
        ];
    }

    public static function pagePut(string $key, string $html, int $ttl = 120, ?string $link = null): void
    {
        $payload = json_encode(['h' => $html, 'l' => $link], JSON_UNESCAPED_SLASHES);
        \App\Core\Cache::set(
            \App\Core\Cache::genKey('listings', $key),
            $payload === false ? $html : $payload,
            $ttl
        );
    }

    /**
     * The Link header a paginated page wants, carried inside the HTML.
     *
     * A header() call in the view is lost when the page comes from the
     * cache: the cached bytes are echoed and the action never runs, so the
     * header would only ever appear on the first (uncached) render. The
     * value travels with the page instead, and the router lifts it out.
     */
    public const LINK_MARKER = '<!--page-link:';

    /**
     * The Link header the current render asked for.
     *
     * The view sets it (it is the one that knows the pagination), the
     * router reads it back so the header can be stored with the cached
     * page: a header() call alone is lost when the page is served from the
     * cache.
     */
    private static ?string $link = null;

    public static function setLink(?string $link): void
    {
        self::$link = $link;
    }

    public static function link(): ?string
    {
        return self::$link;
    }

    /**
     * The Link header values the page asked for.
     *
     * A page can carry more than one: the category page paginates its
     * image section and its video section separately, and each one has its
     * own neighbours.
     *
     * @return string[]
     */
    public static function pageLinks(string $html): array
    {
        $values = [];
        $offset = 0;
        $marker = self::LINK_MARKER;
        $length = strlen($marker);

        while (($start = strpos($html, $marker, $offset)) !== false) {
            $end = strpos($html, '-->', $start);
            if ($end === false) {
                break;
            }
            $value = trim(substr($html, $start + $length, $end - $start - $length));
            if ($value !== '') {
                $values[] = $value;
            }
            $offset = $end + 3;
        }

        return $values;
    }

    /** The page with every Link marker taken out of it. */
    public static function pageStripLink(string $html): string
    {
        $offset = 0;
        $marker = self::LINK_MARKER;
        $length = strlen($marker);

        while (($start = strpos($html, $marker, $offset)) !== false) {
            $end = strpos($html, '-->', $start);
            if ($end === false) {
                break;
            }
            $html = substr($html, 0, $start) . substr($html, $end + 3);
            $offset = $start;
        }

        return $html;
    }

    /**
     * Make a cached page correct for the CURRENT requester: the page was
     * rendered under another guest session, so its CSP nonce and CSRF tokens
     * are stale. Swap every script nonce for this request's CSP_NONCE and
     * every form's _token for the requester's token before echoing.
     */
    public static function pageInject(string $html): string
    {
        $nonce = defined('CSP_NONCE') ? (string) CSP_NONCE : '';
        $html  = (string) preg_replace('/nonce="[A-Za-z0-9+\/=]+"/', 'nonce="' . $nonce . '"', $html);
        $token = \App\Core\Csrf::token();
        $html  = (string) preg_replace(
            '/name="_token" value="[^"]*"/',
            'name="_token" value="' . $token . '"',
            $html
        );
        // The member layout also publishes the session's CSRF token in a
        // meta tag, and the member JS posts with it. It was NOT rewritten,
        // so a guest served a cached page got the PRODUCER's token: their
        // AJAX posts came back 419, and the token of one session leaked to
        // another. The same goes for a data-csrf attribute.
        // The braces matter: the token is hex and often starts with a
        // digit, so '$1' + '67bc...' would be read as the group 167 and the
        // whole tag would be replaced by nothing.
        $html  = (string) preg_replace(
            '/(<meta\s+name="csrf-token"\s+content=")[^"]*(")/',
            '${1}' . $token . '${2}',
            $html
        );
        $html  = (string) preg_replace(
            '/(data-csrf=")[^"]*(")/',
            '${1}' . $token . '${2}',
            $html
        );
        // The age-gate overlay and any save-search back-links must return the
        // REQUESTER to their own page, not the caching producer's.
        $returnTo = isset($_SERVER['REQUEST_URI']) ? e((string) $_SERVER['REQUEST_URI']) : '';
        if ($returnTo !== '') {
            $html = (string) preg_replace(
                '/name="return_to" value="[^"]*"/',
                'name="return_to" value="' . $returnTo . '"',
                $html
            );
        }

        return $html;
    }

    public static function reset(): void
    {
        self::$link = null;
        self::$claimed = false;
        self::$activeHero = false;
    }
}