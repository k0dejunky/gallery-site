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
                (SELECT COUNT(DISTINCT s.user_id) FROM subscriptions s JOIN users u ON u.id = s.user_id WHERE s.status = ? AND (s.expires_at IS NULL OR s.expires_at > CURRENT_TIMESTAMP) AND u.last_login_at IS NOT NULL) AS logged_in_members',
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
