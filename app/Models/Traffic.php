<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Request;

/**
 * Traffic-link attribution: admin-generated custom links (a short ?c= code,
 * optionally carrying utm_source/medium/campaign/content/term parcels) record
 * where visitors come from, and the credited source is stored on the account
 * at signup so campaigns can be measured (visits, unique visitors, signups).
 *
 * Attribution uses a 30-day httponly cookie and a 1-year visitor-id cookie.
 * A terminated (deactivated/unknown) code stops recording new visits AND stops
 * being credited at signup — the link "expires" the moment the admin ends it.
 */
class Traffic
{
    private const COOKIE_REF     = 'traffic_ref';
    private const COOKIE_REF_TTL = 2592000;    // 30 days
    private const COOKIE_VISITOR = 'gvvid';
    private const COOKIE_VISITOR_TTL = 31536000; // 1 year

    // ------------------------------------------------------------------
    // Capture (public requests)
    // ------------------------------------------------------------------

    /**
     * Called from public/index.php for public GET requests. If the request
     * carries a ?c=<code> with a valid ?s=<hmac> signature (or any utm_* param),
     * the source is remembered in a 30-day cookie; a matching active link also
     * gets one daily visit row (deduped per link/day/visitor). Terminated links
     * record nothing and expire any previously stored cookie.
     *
     * Every admin-generated share link now carries a signed serialization
     * (?c=<code>&s=<signature>), so a guessed/forged code without a valid
     * signature is ignored completely: no visit and no attribution cookie.
     */
    public static function capture(Request $request): void
    {
        if ($request->method() !== 'GET') {
            return;
        }

        $uri = $request->uri();
        foreach (['/admin', '/webhooks', '/storage', '/assets'] as $prefix) {
            if (strpos($uri, $prefix) === 0) {
                return;
            }
        }

        $code = trim((string) $request->query('c', ''));
        $sig  = trim((string) $request->query('s', ''));
        $utm  = self::utmFromRequest($request);

        if ($code === '' && $utm === []) {
            return;
        }

        $link = null;
        if ($code !== '') {
            if (!self::validSignature($code, $sig)) {
                // Forged/unsigned code: ignore the request entirely and keep
                // any legitimate cookie already set by a previous visit.
                return;
            }

            $link = self::findActiveByCode($code);
            if ($link === null) {
                // Unknown or terminated code: the link has expired, so drop
                // any older cookie and record nothing.
                self::clearRefCookie();
                return;
            }
        }

        $payload = [
            'c'  => $code,
            's'  => $sig,
            'u'  => $utm['source'] ?? '',
            'm'  => $utm['medium'] ?? '',
            'ca' => $utm['campaign'] ?? '',
            'ct' => $utm['content'] ?? '',
            't'  => $utm['term'] ?? '',
        ];
        self::setRefCookie($payload);

        // Plain UTM links (no custom code) still get attribution credit but
        // have no traffic_links row to count visits against.
        if ($link === null) {
            return;
        }

        Database::run(
            'INSERT INTO traffic_visits (link_id, visitor_id, ref_date, ip, user_agent, landed_at)
             VALUES (?, ?, CURDATE(), ?, ?, CURRENT_TIMESTAMP)
             ON DUPLICATE KEY UPDATE landed_at = CURRENT_TIMESTAMP',
            [(int) $link['id'], self::visitorId($request), $request->ip(), self::userAgent()]
        );
    }

    /** Normalized UTM parcels from the query string (or [] when none present). */
    private static function utmFromRequest(Request $request): array
    {
        $out = [];
        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'] as $key) {
            $value = (string) $request->query($key, '');
            if ($value === '') {
                continue;
            }
            $out[str_replace('utm_', '', $key)] = self::cleanUtm($value);
        }

        return $out;
    }

    /** Keep UTM values short and safe for storage/display. */
    private static function cleanUtm(string $value): string
    {
        $value = preg_replace('/[^a-zA-Z0-9 _.\-]/', '', $value) ?? '';

        return mb_substr(trim($value), 0, 120);
    }

    private static function userAgent(): ?string
    {
        $ua = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));

        return $ua === '' ? null : mb_substr($ua, 0, 255);
    }

    /**
     * Stable anonymous visitor id (1-year cookie, random 32 hex). Used to
     * dedupe visits per link/day without any personal data.
     */
    private static function visitorId(Request $request): string
    {
        $existing = (string) ($_COOKIE[self::COOKIE_VISITOR] ?? '');
        if (preg_match('/\A[a-f0-9]{32}\z/', $existing)) {
            return $existing;
        }

        $id = bin2hex(random_bytes(16));
        self::setCookie(self::COOKIE_VISITOR, $id, self::COOKIE_VISITOR_TTL);

        return $id;
    }

    // ------------------------------------------------------------------
    // Attribution
    // ------------------------------------------------------------------

    /**
     * The campaign credited to the current browser: reads the 30-day cookie
     * and resolves its code. Returns null when there is no stored source or
     * the stored code is terminated/unknown (and then clears the stale cookie).
     *
     * @return array{link_id: ?int, utm_source: string, utm_medium: string, utm_campaign: string, utm_content: string, utm_term: string}|null
     */
    public static function attribution(): ?array
    {
        $raw = (string) ($_COOKIE[self::COOKIE_REF] ?? '');
        $data = json_decode($raw, true);

        if (!is_array($data)) {
            return null;
        }

        $code = trim((string) ($data['c'] ?? ''));
        $sig  = trim((string) ($data['s'] ?? ''));
        $link = null;

        if ($code !== '') {
            if (!self::validSignature($code, $sig)) {
                // Cookie tampered with a forged code: expire it.
                self::clearRefCookie();
                return null;
            }

            $link = self::findActiveByCode($code);
            if ($link === null) {
                self::clearRefCookie();
                return null;
            }
        }

        return [
            'link_id'     => $link !== null ? (int) $link['id'] : null,
            'utm_source'  => self::cleanUtm((string) ($data['u'] ?? '')),
            'utm_medium'  => self::cleanUtm((string) ($data['m'] ?? '')),
            'utm_campaign'=> self::cleanUtm((string) ($data['ca'] ?? '')),
            'utm_content' => self::cleanUtm((string) ($data['ct'] ?? '')),
            'utm_term'    => self::cleanUtm((string) ($data['t'] ?? '')),
        ];
    }

    /** Persist the credited source on a new account, then consume the cookie. */
    public static function attachSignup(int $userId, ?array $attribution): void
    {
        if ($attribution === null) {
            return;
        }

        Database::run(
            'UPDATE users
             SET signup_source_link_id = ?, utm_source = ?, utm_medium = ?,
                 utm_campaign = ?, utm_content = ?, utm_term = ?
             WHERE id = ?',
            [
                $attribution['link_id'],
                $attribution['utm_source'] !== '' ? $attribution['utm_source'] : null,
                $attribution['utm_medium'] !== '' ? $attribution['utm_medium'] : null,
                $attribution['utm_campaign'] !== '' ? $attribution['utm_campaign'] : null,
                $attribution['utm_content'] !== '' ? $attribution['utm_content'] : null,
                $attribution['utm_term'] !== '' ? $attribution['utm_term'] : null,
                $userId,
            ]
        );

        self::clearRefCookie();
    }

    // ------------------------------------------------------------------
    // Links CRUD
    // ------------------------------------------------------------------

    public static function all(): array
    {
        return Database::run(
            'SELECT * FROM traffic_links ORDER BY created_at DESC, id DESC'
        )->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $row = Database::run(
            'SELECT * FROM traffic_links WHERE id = ? LIMIT 1',
            [$id]
        )->fetch();

        return $row ?: null;
    }

    public static function findActiveByCode(string $code): ?array
    {
        $row = Database::run(
            'SELECT * FROM traffic_links WHERE code = ? AND active = 1 LIMIT 1',
            [$code]
        )->fetch();

        return $row ?: null;
    }

    public static function codeExists(string $code, ?int $exceptId = null): bool
    {
        if (trim($code) === '') {
            return false;
        }

        $sql = 'SELECT COUNT(*) FROM traffic_links WHERE code = ?';
        $params = [$code];

        if ($exceptId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $exceptId;
        }

        return (int) Database::run($sql, $params)->fetchColumn() > 0;
    }

    public static function create(string $code, string $name, string $targetPath): int
    {
        Database::run(
            'INSERT INTO traffic_links (code, name, target_path) VALUES (?, ?, ?)',
            [$code, $name, $targetPath]
        );

        return (int) Database::connection()->lastInsertId();
    }

    public static function update(int $id, string $code, string $name, string $targetPath): bool
    {
        $stmt = Database::run(
            'UPDATE traffic_links SET code = ?, name = ?, target_path = ? WHERE id = ?',
            [$code, $name, $targetPath, $id]
        );

        return $stmt->rowCount() > 0;
    }

    public static function setActive(int $id, bool $active): bool
    {
        $stmt = Database::run(
            'UPDATE traffic_links SET active = ? WHERE id = ?',
            [$active ? 1 : 0, $id]
        );

        return $stmt->rowCount() > 0;
    }

    public static function delete(int $id): bool
    {
        $stmt = Database::run(
            'DELETE FROM traffic_links WHERE id = ?',
            [$id]
        );

        return $stmt->rowCount() > 0;
    }

    // ------------------------------------------------------------------
    // Reporting
    // ------------------------------------------------------------------

    /**
     * Per-link aggregates for the summary table.
     *
     * @return array<int, array>
     */
    public static function linksWithStats(): array
    {
        return Database::run(
            "SELECT l.*,
                (SELECT COUNT(*) FROM traffic_visits v WHERE v.link_id = l.id) AS visits,
                (SELECT COUNT(DISTINCT v.visitor_id) FROM traffic_visits v WHERE v.link_id = l.id) AS visitors,
                (SELECT COUNT(*) FROM users u WHERE u.signup_source_link_id = l.id) AS signups
             FROM traffic_links l
             ORDER BY l.created_at DESC, l.id DESC"
        )->fetchAll();
    }

    /**
     * Everything the per-link detail page needs: daily series (last 30 days),
     * the attributed signups and the most recent visits.
     *
     * @return array{link: array, days: array<int,array{date:string,visits:int,visitors:int,signups:int}>, signups: array, visits: array}|null
     */
    public static function linkStats(int $id): ?array
    {
        $link = self::find($id);
        if ($link === null) {
            return null;
        }

        $visits = [];
        foreach (Database::run(
            "SELECT ref_date AS d, COUNT(*) AS visits, COUNT(DISTINCT visitor_id) AS visitors
             FROM traffic_visits
             WHERE link_id = ? AND ref_date >= CURDATE() - INTERVAL 29 DAY
             GROUP BY ref_date ORDER BY ref_date",
            [$id]
        )->fetchAll() as $row) {
            $visits[$row['d']] = ['visits' => (int) $row['visits'], 'visitors' => (int) $row['visitors']];
        }

        $signupsByDay = [];
        foreach (Database::run(
            "SELECT DATE(created_at) AS d, COUNT(*) AS c
             FROM users
             WHERE signup_source_link_id = ? AND created_at >= CURDATE() - INTERVAL 29 DAY
             GROUP BY d",
            [$id]
        )->fetchAll() as $row) {
            $signupsByDay[$row['d']] = (int) $row['c'];
        }

        $days = [];
        for ($i = 29; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days"));
            $row = $visits[$date] ?? ['visits' => 0, 'visitors' => 0];
            $days[] = [
                'date'     => $date,
                'visits'   => $row['visits'],
                'visitors' => $row['visitors'],
                'signups'  => (int) ($signupsByDay[$date] ?? 0),
            ];
        }

        $attributed = Database::run(
            'SELECT u.id, u.email, u.created_at, u.utm_source, u.utm_medium, u.utm_campaign, u.utm_content, u.utm_term
             FROM users u
             WHERE u.signup_source_link_id = ?
             ORDER BY u.created_at DESC, u.id DESC
             LIMIT 100',
            [$id]
        )->fetchAll();

        $recentVisits = Database::run(
            'SELECT ref_date, landed_at, ip, user_agent, visitor_id
             FROM traffic_visits
             WHERE link_id = ?
             ORDER BY landed_at DESC, id DESC
             LIMIT 100',
            [$id]
        )->fetchAll();

        return [
            'link'   => $link,
            'days'   => $days,
            'signups'=> $attributed,
            'visits' => $recentVisits,
        ];
    }

    /**
     * The full shareable URL for a link. Every link is serialized with a
     * signature (?c=<code>&s=<hmac>) so visitors cannot forge traffic by
     * inventing codes — only links produced by Traffic::buildUrl() count.
     *
     * e.g. https://example.com/signup?c=summer2026&s=<64 hex>
     */
    public static function buildUrl(string $targetPath, string $code): string
    {
        return absolute_url($targetPath) . '?c=' . rawurlencode($code) . '&s=' . self::signCode($code);
    }

    // ------------------------------------------------------------------
    // Link serialization (anti-forgery signature)
    // ------------------------------------------------------------------

    /**
     * The HMAC secret used to sign ?c= codes. Prefer APP_KEY when present,
     * otherwise the media key (both already secret server-side values, never
     * code-reachable); the signed links can only be forged by someone who
     * knows this secret.
     */
    private static function signatureKey(): string
    {
        static $key = null;

        if ($key === null) {
            $key = env_value('APP_KEY', '');
            if ($key === '') {
                $key = env_value('GALLERY_MEDIA_KEY', '');
            }
        }

        return (string) $key;
    }

    /** HMAC-SHA256 signature for a ?c= code, as a 64-char hex string. */
    public static function signCode(string $code): string
    {
        return hash_hmac('sha256', 'traffic-link:' . $code, self::signatureKey());
    }

    /**
     * True when $signature authenticates $code. Compares in constant time
     * and rejects anything that is not a 64-char hex signature.
     */
    public static function validSignature(string $code, string $signature): bool
    {
        if ($code === '' || $signature === '') {
            return false;
        }
        if (preg_match('/\A[a-f0-9]{64}\z/', $signature) !== 1) {
            return false;
        }

        return hash_equals(self::signCode($code), $signature);
    }

    /**
     * Short slug from a link name (lowercased alphanumerics + dashes),
     * suitable as a ?c= code. Empty string when there is nothing usable.
     */
    public static function slugify(string $name): string
    {
        $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $name));
        $slug = trim($slug, '-');

        return mb_substr($slug, 0, 64);
    }

    /** Validate a ?c= code: lowercase letters, digits, underscore, dash. */
    public static function validCode(string $code): bool
    {
        return preg_match('/\A[a-z0-9_\-]+\z/', $code) === 1 && mb_strlen($code) <= 64;
    }

    // ------------------------------------------------------------------
    // Cookies
    // ------------------------------------------------------------------

    private static function setRefCookie(array $payload): void
    {
        self::setCookie(self::COOKIE_REF, (string) json_encode($payload), self::COOKIE_REF_TTL);
    }

    private static function clearRefCookie(): void
    {
        self::setCookie(self::COOKIE_REF, '', -3600);
    }

    private static function setCookie(string $name, string $value, int $ttl): void
    {
        setcookie($name, $value, [
            'expires'  => $ttl > 0 ? time() + $ttl : time() - 3600,
            'path'     => '/',
            'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}