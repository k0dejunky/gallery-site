<?php

namespace App\Models;

use App\Core\Database;

/**
 * Lightweight usage statistics for the admin dashboard: which categories
 * users are viewing and which search terms return nothing (missed searches).
 * Each metric compares the current window against the previous one so the
 * dashboard can show how everything is trending, and can be viewed across
 * several periods (daily, weekly, monthly, yearly or all time).
 */
class Stats
{
    /**
     * Supported trend periods. "days" is the length of each comparison window
     * (current window vs the same length before it); null means all time,
     * which reports totals only (no comparison). "range" is the human label
     * used in the dashboard column headers.
     */
    private const PERIODS = [
        'daily'   => ['label' => 'Daily',   'range' => 'last 24 hours',  'days' => 1],
        'weekly'  => ['label' => 'Weekly',  'range' => 'last 7 days',    'days' => 7],
        'monthly' => ['label' => 'Monthly', 'range' => 'last 30 days',   'days' => 30],
        'yearly'  => ['label' => 'Yearly',  'range' => 'last 12 months', 'days' => 365],
        'all'     => ['label' => 'All time', 'range' => 'all time',      'days' => null],
    ];

    /**
     * The period definitions, exposed to the dashboard so the period selector
     * and column labels share a single source of truth.
     */
    public static function periods(): array
    {
        return self::PERIODS;
    }

    /**
     * Validate a period key, falling back to weekly for anything unknown.
     */
    public static function normalizePeriod(string $period): string
    {
        return isset(self::PERIODS[$period]) ? $period : 'weekly';
    }

    /**
     * Lifetime headline numbers for the dashboard's stat cards: total gallery
     * views, the number of distinct photos in active galleries, the number
     * of non-soft-deleted galleries, and the number of currently active
     * members. Soft-deleted galleries and their (orphaned) photos are
     * excluded so the cards match the dashboard table.
     */
    public static function summary(): array
    {
        $row = Database::run(
            'SELECT
                (SELECT COALESCE(SUM(g.views), 0) FROM galleries g WHERE g.deleted_at IS NULL) AS total_views,
                (SELECT COUNT(DISTINCT gp.photo_id) FROM gallery_photo gp JOIN galleries g ON g.id = gp.gallery_id WHERE g.deleted_at IS NULL) AS photos,
                (SELECT COUNT(DISTINCT gp.photo_id) FROM gallery_photo gp JOIN galleries g ON g.id = gp.gallery_id JOIN photos p ON p.id = gp.photo_id WHERE g.deleted_at IS NULL AND p.is_video = 1) AS videos,
                (SELECT COUNT(*) FROM galleries g WHERE g.deleted_at IS NULL) AS galleries,
                (SELECT COUNT(DISTINCT s.user_id) FROM subscriptions s JOIN plans p ON p.id = s.plan_id WHERE s.status = ? AND (s.expires_at IS NULL OR s.expires_at > CURRENT_TIMESTAMP) AND p.level >= 1) AS total_members,
                (SELECT COUNT(*) FROM users WHERE role = ?) AS total_users,
                (SELECT COUNT(DISTINCT s.user_id) FROM subscriptions s JOIN users u ON u.id = s.user_id WHERE s.status = ? AND (s.expires_at IS NULL OR s.expires_at > CURRENT_TIMESTAMP) AND u.last_seen_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)) AS logged_in_members',
            ['active', 'user', 'active']
        )->fetch();

        return [
            'total_views'       => (int) ($row['total_views'] ?? 0),
            'photos'            => (int) ($row['photos'] ?? 0),
            'videos'            => (int) ($row['videos'] ?? 0),
            'galleries'         => (int) ($row['galleries'] ?? 0),
            'total_members'     => (int) ($row['total_members'] ?? 0),
            'total_users'       => (int) ($row['total_users'] ?? 0),
            'logged_in_members' => (int) ($row['logged_in_members'] ?? 0),
        ];
    }

    /**
     * Membership growth figures for the admin dashboard: monthly-equivalent
     * recurring revenue of active subscriptions, recent signups, and how
     * active members are distributed across payment processors.
     */
    public static function growth(): array
    {
        $mrr = (float) Database::run(
            'SELECT COALESCE(SUM(
                CASE p.billing_cycle
                    WHEN "monthly" THEN p.price
                    WHEN "yearly" THEN p.price / 12
                    ELSE p.price / 24
                END), 0)
             FROM subscriptions s
             JOIN plans p ON p.id = s.plan_id
             WHERE s.status = ? AND (s.expires_at IS NULL OR s.expires_at > CURRENT_TIMESTAMP)',
            ['active']
        )->fetchColumn();

        $signups = Database::run(
            'SELECT
                (SELECT COUNT(*) FROM users WHERE created_at >= CURDATE()) AS today,
                (SELECT COUNT(*) FROM users WHERE created_at >= CURDATE() - INTERVAL 7 DAY) AS week'
        )->fetch();

        $byProcessor = Database::run(
            'SELECT COALESCE(pp.name, "(none selected)") AS name, COUNT(DISTINCT s.user_id) AS members,
                    COALESCE(SUM(CASE p.billing_cycle WHEN "monthly" THEN p.price WHEN "yearly" THEN p.price / 12 ELSE p.price / 24 END), 0) AS mrr
             FROM subscriptions s
             JOIN plans p ON p.id = s.plan_id
             LEFT JOIN payment_processors pp ON pp.id = s.payment_processor_id
             WHERE s.status = ? AND (s.expires_at IS NULL OR s.expires_at > CURRENT_TIMESTAMP)
             GROUP BY pp.id, pp.name ORDER BY mrr DESC',
            ['active']
        )->fetchAll();

        return [
            'mrr'          => $mrr,
            'new_today'    => (int) ($signups['today'] ?? 0),
            'new_week'     => (int) ($signups['week'] ?? 0),
            'by_processor' => $byProcessor,
        ];
    }

    /**
     * Finance series for the dashboard charts: per-month revenue (paid
     * activations), new paid subscriptions and cancellations/expiries over
     * the trailing N months, plus current ARPU. Pending placeholder rows
     * (PENDING-… references) never count as revenue.
     */
    public static function finance(int $months = 6): array
    {
        $since = date('Y-m-01 00:00:00', strtotime('-' . ($months - 1) . ' months'));

        $revRows = Database::run(
            "SELECT DATE_FORMAT(COALESCE(s.starts_at, s.created_at), '%b') AS label,
                    DATE_FORMAT(COALESCE(s.starts_at, s.created_at), '%Y-%m') AS ym,
                    SUM(COALESCE(NULLIF(s.price_paid, 0), p.price)) AS revenue,
                    COUNT(*) AS paid
             FROM subscriptions s
             JOIN plans p ON p.id = s.plan_id
             WHERE s.transaction_ref NOT LIKE 'PENDING-%'
               AND COALESCE(s.starts_at, s.created_at) >= ?
             GROUP BY label, ym ORDER BY ym",
            [$since]
        )->fetchAll();

        $cancelRows = Database::run(
            "SELECT DATE_FORMAT(updated_at, '%b') AS label,
                    DATE_FORMAT(updated_at, '%Y-%m') AS ym,
                    COUNT(*) AS churn
             FROM subscriptions
             WHERE status IN ('cancelled', 'expired')
               AND updated_at >= ?
             GROUP BY label, ym ORDER BY ym",
            [$since]
        )->fetchAll();

        // Build a continuous month axis so gaps render as zero bars.
        $axis  = [];
        $rev   = [];
        $new   = [];
        $churn = [];

        for ($i = $months - 1; $i >= 0; $i--) {
            $key         = date('Y-m', strtotime("-$i months"));
            $axis[$key]  = date('M', strtotime("-$i months"));
            $rev[$key]   = 0.0;
            $new[$key]   = 0;
            $churn[$key] = 0;
        }

        foreach ($revRows as $r) {
            if (isset($rev[$r['ym']])) {
                $rev[$r['ym']]   = (float) $r['revenue'];
                $new[$r['ym']]   = (int) $r['paid'];
            }
        }

        foreach ($cancelRows as $r) {
            if (isset($churn[$r['ym']])) {
                $churn[$r['ym']] = (int) $r['churn'];
            }
        }

        // Current month-to-date revenue vs the same point last month.
        $mtd = (float) Database::run(
            "SELECT COALESCE(SUM(COALESCE(NULLIF(s.price_paid, 0), p.price)), 0)
             FROM subscriptions s
             JOIN plans p ON p.id = s.plan_id
             WHERE s.transaction_ref NOT LIKE 'PENDING-%'
               AND COALESCE(s.starts_at, s.created_at) >= ?",
            [date('Y-m-01 00:00:00')]
        )->fetchColumn();

        return [
            'labels' => array_values($axis),
            'revenue' => array_values($rev),
            'new_paid' => array_values($new),
            'churn' => array_values($churn),
            'mtd_revenue' => $mtd,
            'total_12mo' => (float) Database::run(
                "SELECT COALESCE(SUM(COALESCE(NULLIF(s.price_paid, 0), p.price)), 0)
                 FROM subscriptions s JOIN plans p ON p.id = s.plan_id
                 WHERE s.transaction_ref NOT LIKE 'PENDING-%'
                   AND COALESCE(s.starts_at, s.created_at) >= ?",
                [date('Y-m-d 00:00:00', strtotime('-12 months'))]
            )->fetchColumn(),
        ];
    }

    /**
     * Mixed recent-activity feed for the dashboard: signups, completed
     * payments, failed logins and admin actions merged into one timeline.
     */
    public static function feed(int $limit = 14): array
    {
        $items = [];

        foreach (Database::run(
            "SELECT id, email, role, created_at FROM users ORDER BY id DESC LIMIT 5"
        )->fetchAll() as $u) {
            $items[] = [
                'type' => 'signup',
                'text' => 'New signup: ' . $u['email'] . ' (' . $u['role'] . ')',
                'at'   => $u['created_at'],
                'link' => '/admin/users/' . (int) $u['id'],
            ];
        }

        foreach (Database::run(
            "SELECT s.id, s.user_id, s.transaction_ref, s.updated_at, u.email, p.name AS plan
             FROM subscriptions s
             LEFT JOIN users u ON u.id = s.user_id
             LEFT JOIN plans p ON p.id = s.plan_id
             WHERE s.status = 'active' AND s.transaction_ref NOT LIKE 'PENDING-%'
             ORDER BY s.updated_at DESC LIMIT 5"
        )->fetchAll() as $s) {
            $items[] = [
                'type' => 'payment',
                'text' => 'Payment: ' . ($s['email'] ?? 'user#' . $s['user_id']) . ' — ' . $s['plan'],
                'at'   => $s['updated_at'],
                'link' => '/admin/users/' . (int) $s['user_id'],
            ];
        }

        $fails = Database::run(
            "SELECT COUNT(*) AS c, MAX(attempted_at) AS last FROM login_attempts WHERE attempted_at >= ?",
            [date('Y-m-d H:i:s', time() - 3600)]
        )->fetch();

        if ((int) ($fails['c'] ?? 0) > 0) {
            $items[] = [
                'type' => 'failed_login',
                'text' => sprintf('%d failed login(s) in the last hour', (int) $fails['c']),
                'at'   => $fails['last'],
                'link' => '/admin/system#security',
            ];
        }

        foreach (Database::run(
            "SELECT l.action, l.entity_type, l.entity_id, l.description, l.created_at, u.email AS actor
             FROM admin_logs l LEFT JOIN users u ON u.id = l.user_id
             ORDER BY l.id DESC LIMIT 5"
        )->fetchAll() as $l) {
            $items[] = [
                'type' => 'admin',
                'text' => trim(($l['actor'] ?? 'admin') . ': ' . $l['description']),
                'at'   => $l['created_at'],
                'link' => '',
            ];
        }

        usort($items, fn (array $a, array $b): int => strcmp((string) $b['at'], (string) $a['at']));

        return array_slice($items, 0, $limit);
    }

    /**
     * Login-security snapshot: failures per hour (spike detection), the
     * most aggressive IPs/emails of the last 24h, and everything currently
     * locked out under Auth's thresholds.
     */
    public static function security(): array
    {
        $config = require dirname(__DIR__, 2) . '/config/app.php';
        $maxAttempts = (int) ($config['auth']['login_max_attempts'] ?? 5);
        $window      = (int) ($config['auth']['login_window_seconds'] ?? 900);
        $cutoffPair  = date('Y-m-d H:i:s', time() - $window);
        $cutoffDay   = date('Y-m-d H:i:s', time() - 86400);

        $failsHour = (int) Database::run(
            'SELECT COUNT(*) FROM login_attempts WHERE attempted_at >= ?',
            [date('Y-m-d H:i:s', time() - 3600)]
        )->fetchColumn();

        $topIps = Database::run(
            'SELECT ip, COUNT(*) AS c, MIN(attempted_at) AS first, MAX(attempted_at) AS last,
                    GROUP_CONCAT(DISTINCT email ORDER BY email SEPARATOR ", ") AS emails
             FROM login_attempts WHERE attempted_at >= ?
             GROUP BY ip ORDER BY c DESC LIMIT 5',
            [$cutoffDay]
        )->fetchAll();

        $topEmails = Database::run(
            'SELECT email, COUNT(*) AS c, MAX(attempted_at) AS last
             FROM login_attempts WHERE attempted_at >= ?
             GROUP BY email ORDER BY c DESC LIMIT 5',
            [$cutoffDay]
        )->fetchAll();

        $lockedPairs = Database::run(
            'SELECT email, ip, COUNT(*) AS c FROM login_attempts
             WHERE attempted_at >= ? GROUP BY email, ip HAVING COUNT(*) >= ? ORDER BY c DESC LIMIT 10',
            [$cutoffPair, $maxAttempts]
        )->fetchAll();

        $lockedIps = Database::run(
            'SELECT ip, COUNT(*) AS c FROM login_attempts
             WHERE attempted_at >= ? GROUP BY ip HAVING COUNT(*) >= ? ORDER BY c DESC LIMIT 10',
            [$cutoffPair, $maxAttempts * 3]
        )->fetchAll();

        return [
            'fails_hour'  => $failsHour,
            'max_pair'    => $maxAttempts,
            'max_ip'      => $maxAttempts * 3,
            'window_min'  => (int) round($window / 60),
            'top_ips'     => $topIps,
            'top_emails'  => $topEmails,
            'locked_pairs' => $lockedPairs,
            'locked_ips'  => $lockedIps,
        ];
    }

    /**
     * Storage usage over a selectable period, built from the housekeeping
     * snapshots (peak bytes per bucket).
     *
     * The bucket size adapts to how much history actually exists: hourly
     * buckets for spans up to 10 days, daily up to ~8 months, monthly
     * beyond that. A period whose window starts before the first snapshot
     * is clamped to the first snapshot, so growth that "day" shows is never
     * flattened away by empty leading buckets in wider windows — every
     * window containing the growth displays it.
     *
     * Periods: day / week / month / year / all.
     *
     * Returns labels + GB values plus summary stats:
     * current_gb, current_photos, current_videos, delta_gb, points,
     * granularity, first_snapshot (Y-m-d or null).
     */
    public static function storageTrend(?string $period = null): array
    {
        $periods = [
            'day'   => '-24 hours',
            'week'  => '-6 days',
            'month' => '-29 days',
            'year'  => '-11 months',
            'all'   => null,
        ];

        $period = is_string($period) && isset($periods[$period]) ? $period : 'week';

        $rows = Database::run(
            'SELECT captured_at, uploads_bytes, photos_count, video_count
             FROM storage_snapshots ORDER BY captured_at LIMIT 200000'
        )->fetchAll();

        if (!$rows) {
            return [
                'labels' => [], 'gb' => [], 'current_gb' => null,
                'current_photos' => null, 'current_videos' => null,
                'delta_gb' => null, 'points' => 0, 'granularity' => 'day',
                'first_snapshot' => null,
            ];
        }

        $now       = time();
        $oldestTs  = strtotime((string) $rows[0]['captured_at']);
        $windowRaw = $periods[$period] !== null ? strtotime($periods[$period], $now) : $oldestTs;

        // Never show a flat leading stretch of unknown history: start the
        // window at the first snapshot if it reaches back further.
        $start = max($windowRaw, $oldestTs);

        // Adaptive bucket size from the actual span: hour / day / month,
        // keeping the point count manageable (~240 max).
        $span    = max(3600, $now - $start);
        $gran    = $span <= 240 * 3600 ? 'hour' : ($span <= 240 * 86400 ? 'day' : 'month');
        $unitSec = $gran === 'hour' ? 3600 : ($gran === 'day' ? 86400 : 2635200);
        $fmtKey  = $gran === 'hour' ? 'Y-m-d H:00:00' : ($gran === 'day' ? 'Y-m-d 00:00:00' : 'Y-m-01 00:00:00');

        // Peak bytes per bucket key, in chronological order.
        $peaks    = [];
        $lastRow  = null;
        foreach ($rows as $r) {
            $ts   = strtotime((string) $r['captured_at']);
            $peak = (int) $r['uploads_bytes'];
            if ($ts >= $start) {
                $key = date($fmtKey, $ts);
                if (!isset($peaks[$key]) || $peak > $peaks[$key]) {
                    $peaks[$key] = $peak;
                }
            }
            $lastRow = ['at' => (string) $r['captured_at'], 'bytes' => $peak,
                        'photos' => (int) $r['photos_count'], 'videos' => (int) ($r['video_count'] ?? 0)];
        }

        // Bucket frames from the clamped window start to now.
        $bucketTs = [];
        $frameStart = strtotime(date($fmtKey, $start));
        if ($gran === 'month') {
            for ($t = $frameStart; $t <= $now; $t = strtotime('+1 month', $t)) {
                $bucketTs[] = strtotime(date('Y-m-01 00:00:00', $t));
            }
        } else {
            $step = $unitSec;
            for ($t = $frameStart; $t <= $now; $t += $step) {
                $bucketTs[] = strtotime(date($fmtKey, $t));
            }
        }

        // Carry-forward fill so interior gaps stay continuous. Leading
        // unknowns don't exist by construction (window starts at history).
        $labels = [];
        $gbs    = [];
        $carry  = null;
        foreach ($bucketTs as $i => $ts) {
            $key = date($fmtKey, $ts);
            if (array_key_exists($key, $peaks)) {
                $carry = $peaks[$key];
            }
            if ($carry === null && $i === count($bucketTs) - 1) {
                break;
            }
            if ($carry !== null) {
                if ($gran === 'hour') {
                    $labels[] = date('n/j G:00', $ts);
                } elseif ($gran === 'day') {
                    $labels[] = date('n/j', $ts);
                } else {
                    $labels[] = date('M y', $ts);
                }
                $gbs[] = round($carry / 1073741824, 2);
            }
        }
        // Ensure the final bucket reflects "now" even if no snapshot yet in
        // the newest bucket: repeat the last known value.
        if ($gbs && end($bucketTs) > strtotime(date($fmtKey, strtotime((string) $lastRow['at'])))) {
            $labels[] = $gran === 'hour' ? date('n/j G:00', end($bucketTs))
                      : ($gran === 'day' ? date('n/j', end($bucketTs)) : date('M y', end($bucketTs)));
            $gbs[] = round($lastRow['bytes'] / 1073741824, 2);
        }

        return [
            'labels'         => $labels,
            'gb'             => $gbs,
            'current_gb'     => round($lastRow['bytes'] / 1073741824, 2),
            'current_photos' => $lastRow['photos'],
            'current_videos' => $lastRow['videos'],
            'delta_gb'       => count($gbs) > 1 ? round(end($gbs) - reset($gbs), 2) : ($gbs ? 0.0 : null),
            'points'         => count($gbs),
            'granularity'    => $gran,
            'first_snapshot' => substr((string) $rows[0]['captured_at'], 0, 10),
        ];
    }

    /**
     * Record a category selection: every time a logged-in user opens a
     * category page it counts as one view of that category.
     */
    public static function recordCategoryView(int $categoryId, int $userId): void
    {
        if ($categoryId <= 0) {
            return;
        }

        Database::run(
            'INSERT INTO category_views (category_id, user_id, created_at) VALUES (?, ?, CURRENT_TIMESTAMP)',
            [$categoryId, $userId > 0 ? $userId : null]
        );
    }

    /**
     * Record a missed search: a search term that returned no results. The
     * term is stored exactly as typed (trimmed, capped at 255 chars) and
     * grouped case-insensitively when reported.
     */
    public static function recordMissedSearch(string $term, int $userId): void
    {
        $term = trim($term);

        if ($term === '') {
            return;
        }

        if (mb_strlen($term) > 255) {
            $term = mb_substr($term, 0, 255);
        }

        Database::run(
            'INSERT INTO search_stats (term, user_id, created_at) VALUES (?, ?, CURRENT_TIMESTAMP)',
            [$term, $userId > 0 ? $userId : null]
        );
    }

    /**
     * Per-category view counts for the current period and the one before it,
     * every category listed (categories with no views simply read zero).
     * Sorted by current-period views so the busiest categories are on top.
     * The "all" period reports lifetime totals instead of a comparison.
     */
    public static function categoryTrends(string $period = 'weekly'): array
    {
        $period = self::normalizePeriod($period);
        $days   = self::PERIODS[$period]['days'];

        if ($days === null) {
            $rows = Database::run(
                'SELECT c.id, c.name, c.slug,
                        (SELECT COUNT(*) FROM gallery_category gc2
                         INNER JOIN galleries g2 ON g2.id = gc2.gallery_id
                         WHERE gc2.category_id = c.id AND g2.deleted_at IS NULL) AS gallery_count,
                        COUNT(cv.id) AS cur_count,
                        0 AS prev_count
                 FROM categories c
                 LEFT JOIN category_views cv ON cv.category_id = c.id
                 GROUP BY c.id, c.name, c.slug
                 ORDER BY cur_count DESC, c.name ASC'
            )->fetchAll();

            return self::decorate($rows, true);
        }

        [$currentLow, $previousLow] = self::windows($days);

        $rows = Database::run(
            'SELECT c.id, c.name, c.slug,
                    (SELECT COUNT(*) FROM gallery_category gc2
                     INNER JOIN galleries g2 ON g2.id = gc2.gallery_id
                     WHERE gc2.category_id = c.id AND g2.deleted_at IS NULL) AS gallery_count,
                    COALESCE(SUM(CASE WHEN cv.created_at >= ? THEN 1 ELSE 0 END), 0) AS cur_count,
                    COALESCE(SUM(CASE WHEN cv.created_at >= ? AND cv.created_at < ? THEN 1 ELSE 0 END), 0) AS prev_count
             FROM categories c
             LEFT JOIN category_views cv ON cv.category_id = c.id AND cv.created_at >= ?
             GROUP BY c.id, c.name, c.slug
             ORDER BY cur_count DESC, prev_count DESC, c.name ASC',
            [$currentLow, $previousLow, $currentLow, $previousLow]
        )->fetchAll();

        return self::decorate($rows);
    }

    /**
     * Per-term counts of missed searches for the current period and the one
     * before it. Only terms with activity in the combined window appear.
     * Sorted by current-period searches so the most-searched terms are on
     * top. The "all" period reports lifetime totals instead.
     */
    public static function searchTrends(string $period = 'weekly'): array
    {
        $period = self::normalizePeriod($period);
        $days   = self::PERIODS[$period]['days'];

        if ($days === null) {
            $rows = Database::run(
                'SELECT LOWER(term) AS term_key, MAX(term) AS term,
                        COUNT(*) AS cur_count,
                        0 AS prev_count
                 FROM search_stats
                 GROUP BY LOWER(term)
                 ORDER BY cur_count DESC, term ASC'
            )->fetchAll();

            return self::decorate($rows, true);
        }

        [$currentLow, $previousLow] = self::windows($days);

        $rows = Database::run(
            'SELECT LOWER(term) AS term_key, MAX(term) AS term,
                    COALESCE(SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END), 0) AS cur_count,
                    COALESCE(SUM(CASE WHEN created_at >= ? AND created_at < ? THEN 1 ELSE 0 END), 0) AS prev_count
             FROM search_stats
             WHERE created_at >= ?
             GROUP BY LOWER(term)
             ORDER BY cur_count DESC, prev_count DESC, term ASC',
            [$currentLow, $previousLow, $currentLow, $previousLow]
        )->fetchAll();

        return self::decorate($rows);
    }

    /**
     * Lifetime counts of every missed search term, keyed by its lowercase
     * form. Used to flag terms that have been searched often enough to be
     * worth promoting to a real category, independent of the period chosen
     * on the Trends page.
     *
     * @return array<string, int>
     */
    public static function missedSearchLifetimeCounts(): array
    {
        $rows = Database::run(
            'SELECT LOWER(term) AS term_key, COUNT(*) AS total
             FROM search_stats
             GROUP BY LOWER(term)'
        )->fetchAll();

        $counts = [];

        foreach ($rows as $row) {
            $counts[(string) $row['term_key']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * All missed search terms whose lifetime count exceeds the given
     * threshold, regardless of the period selected on the Trends page.
     * Sorted by lifetime count so the most-searched terms are on top.
     *
     * @return array<int, array{term_key: string, term: string, count: int}>
     */
    public static function pendingCategoryApprovals(int $threshold): array
    {
        $rows = Database::run(
            'SELECT LOWER(term) AS term_key, MAX(term) AS term, COUNT(*) AS total
             FROM search_stats
             GROUP BY LOWER(term)
             HAVING COUNT(*) > ?
             ORDER BY total DESC, term ASC',
            [$threshold]
        )->fetchAll();

        return array_map(static fn (array $row): array => [
            'term_key' => (string) $row['term_key'],
            'term'     => (string) $row['term'],
            'count'    => (int) $row['total'],
        ], $rows);
    }

    /**
     * Attach a human/machine-readable trend to each row: "new" when there
     * was no previous activity, "up"/"down" with the percentage change, or
     * "flat" when counts match (including when there is no activity). For the
     * all-time period there is nothing to compare against, so rows carry a
     * "total" trend and a null previous count.
     */
    private static function decorate(array $rows, bool $allTime = false): array
    {
        return array_map(static function (array $row) use ($allTime): array {
            $row['cur']  = (int) $row['cur_count'];
            $row['prev'] = $allTime ? null : (int) $row['prev_count'];
            $row['trend'] = $allTime
                ? ['label' => 'total', 'pct' => 0]
                : self::trend($row['cur'], $row['prev']);

            return $row;
        }, $rows);
    }

    /**
     * Compute the trend for a pair of counts.
     *
     * @return array{label: string, pct: int}
     */
    private static function trend(int $cur, int $prev): array
    {
        if ($cur === 0 && $prev === 0) {
            return ['label' => 'flat', 'pct' => 0];
        }

        if ($prev === 0) {
            return ['label' => 'new', 'pct' => 100];
        }

        $pct = (int) round(($cur - $prev) / $prev * 100);

        if ($pct === 0) {
            return ['label' => 'flat', 'pct' => 0];
        }

        return ['label' => $pct > 0 ? 'up' : 'down', 'pct' => $pct];
    }

    /**
     * Boundary timestamps for the two comparison windows: the current window
     * runs from $previousLow to now, the previous one from $previousLow back
     * one more window.
     *
     * @return array{0: string, 1: string} [currentLow, previousLow]
     */
    private static function windows(int $days): array
    {
        $days        = max(1, $days);
        $currentLow  = date('Y-m-d H:i:s', time() - $days * 86400);
        $previousLow = date('Y-m-d H:i:s', time() - 2 * $days * 86400);

        return [$currentLow, $previousLow];
    }
}
