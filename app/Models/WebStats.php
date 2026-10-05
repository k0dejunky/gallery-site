<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Read side of the Apache access-log analytics written by
 * App\Core\AccessLogAggregator.
 *
 * Every aggregate lives per calendar day in the site timezone, so all queries
 * here filter on web_stats_daily.day and SUM the matching rows. Bot traffic is
 * stored beside human traffic in the same rows (never mixed into one counter),
 * which is what lets the admin page switch bots on and off without re-parsing
 * anything.
 *
 * Nothing in this class deletes history; pruning is deliberately not a feature.
 */
class WebStats
{
    /** Days the admin page can look back over in one view. */
    private const MAX_RANGE_DAYS = 366;

    /**
     * Preset ranges offered by the period selector. "days" is the window
     * length; the headline cards compare it against the window immediately
     * before it, which is what the "vs previous N days" figures mean.
     */
    private const RANGES = [
        'today'    => ['label' => 'Today',        'days' => 1],
        '7days'    => ['label' => 'Last 7 days',  'days' => 7],
        '30days'   => ['label' => 'Last 30 days', 'days' => 30],
        '90days'   => ['label' => 'Last 90 days', 'days' => 90],
        '12months' => ['label' => 'Last 12 months', 'days' => 365],
        'all'      => ['label' => 'All time',     'days' => null],
    ];

    /**
     * The range definitions, shared by the view so the selector and the
     * comparison labels have a single source of truth.
     */
    public static function ranges(): array
    {
        return self::RANGES;
    }

    /**
     * Normalise a range key from the query string, defaulting to 30 days.
     */
    public static function normalizeRange(string $range): string
    {
        return isset(self::RANGES[$range]) ? $range : '30days';
    }

    /**
     * True when the range is "everything we have", which has no previous
     * period to compare against.
     */
    public static function isAllTime(string $range): bool
    {
        return self::RANGES[self::normalizeRange($range)]['days'] === null;
    }

    /**
     * Resolve a range to concrete start/end days in the site timezone.
     *
     * Returns [from, to] as Y-m-d strings inclusive. "All time" spans from the
     * first day we have data (or today, when there is none yet) to today.
     */
    public static function resolveRange(string $range, string $timezone): array
    {
        $range = self::normalizeRange($range);
        $to    = (new \DateTimeImmutable('now', new \DateTimeZone($timezone)))->format('Y-m-d');

        if (self::RANGES[$range]['days'] === null) {
            $first = self::firstDayWithData();

            return [$first ?? $to, $to];
        }

        $days = (int) self::RANGES[$range]['days'];
        $from = (new \DateTimeImmutable($to, new \DateTimeZone($timezone)))
            ->modify('-' . ($days - 1) . ' days')
            ->format('Y-m-d');

        return [$from, $to];
    }

    /**
     * The window immediately before [$from, $to] of the same length, used for
     * the comparison figures on the headline cards.
     */
    public static function previousPeriod(string $from, string $to): array
    {
        $start    = new \DateTimeImmutable($from);
        $end      = new \DateTimeImmutable($to);
        $days     = (int) $start->diff($end)->days + 1;
        $prevEnd  = $start->modify('-1 day');
        $prevFrom = $prevEnd->modify('-' . ($days - 1) . ' days');

        return [$prevFrom->format('Y-m-d'), $prevEnd->format('Y-m-d')];
    }

    /**
     * Clamp a user-supplied from/to pair (used by the reparse form and the CSV
     * export, which accept explicit dates rather than a range key). Reversed or
     * absurd spans are corrected instead of rejected, and the span is capped so
     * a typo cannot ask the aggregator for a decade of logs.
     */
    public static function clampDates(?string $from, ?string $to, string $timezone): array
    {
        $today = (new \DateTimeImmutable('now', new \DateTimeZone($timezone)))->format('Y-m-d');
        $from  = self::validDate($from) ?? $today;
        $to    = self::validDate($to) ?? $today;

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $span = (int) (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days;
        if ($span >= self::MAX_RANGE_DAYS) {
            $from = (new \DateTimeImmutable($to))->modify('-' . (self::MAX_RANGE_DAYS - 1) . ' days')->format('Y-m-d');
        }

        return [$from, $to];
    }

    /**
     * Accept a Y-m-d string only when it is a real calendar date (so
     * "2026-13-45" cannot reach SQL).
     */
    private static function validDate(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        [$y, $m, $d] = array_map('intval', explode('-', $value));
        if (!checkdate($m, $d, $y)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $y, $m, $d);
    }

    // ------------------------------------------------------------------
    // Coverage
    // ------------------------------------------------------------------

    /**
     * First day we hold aggregates for, or null when nothing has been parsed
     * yet. Used by "All time" so the range is never empty.
     */
    public static function firstDayWithData(): ?string
    {
        $row = Database::run('SELECT MIN(day) AS d FROM web_stats_daily')->fetch();

        return $row['d'] ?? null;
    }

    public static function lastDayWithData(): ?string
    {
        $row = Database::run('SELECT MAX(day) AS d FROM web_stats_daily')->fetch();

        return $row['d'] ?? null;
    }

    /**
     * True once the aggregator has written at least one day. The admin page
     * shows an empty-state prompt instead of charts when this is false.
     */
    public static function hasData(): bool
    {
        return self::firstDayWithData() !== null;
    }

    /**
     * Rollup health for the System/health page and the "last updated" line on
     * the analytics page: how many days are stored, which files the last run
     * read, and how many lines it could not parse. A run that suddenly skips
     * thousands of lines means the log format changed and someone should look.
     */
    public static function health(): array
    {
        $summary = Database::run(
            'SELECT COUNT(*) AS days, MIN(day) AS first_day, MAX(day) AS last_day,
                    SUM(skipped_lines) AS skipped, SUM(hits) AS hits, SUM(bytes) AS bytes
             FROM web_stats_daily'
        )->fetch() ?: [];

        $latest = Database::run(
            'SELECT day, source_files, skipped_lines, hits, updated_at
             FROM web_stats_daily ORDER BY day DESC LIMIT 1'
        )->fetch() ?: [];

        // Stored row counts, so growth is visible before it becomes a problem.
        $tables = ['web_stats_daily', 'web_stats_hourly', 'web_stats_urls', 'web_stats_referrers',
                   'web_stats_agents', 'web_stats_status', 'web_stats_filetypes', 'web_stats_ips', 'web_visits'];

        $rows = [];
        foreach ($tables as $table) {
            $rows[$table] = (int) (Database::run("SELECT COUNT(*) AS c FROM {$table}")->fetch()['c'] ?? 0);
        }

        return [
            'has_data'       => !empty($summary['days']),
            'days'           => (int) ($summary['days'] ?? 0),
            'first_day'      => $summary['first_day'] ?? null,
            'last_day'       => $summary['last_day'] ?? null,
            'hits'           => (int) ($summary['hits'] ?? 0),
            'bytes'          => (int) ($summary['bytes'] ?? 0),
            'skipped_lines'  => (int) ($summary['skipped'] ?? 0),
            'latest_source'  => (string) ($latest['source_files'] ?? ''),
            'latest_skipped' => (int) ($latest['skipped_lines'] ?? 0),
            'updated_at'     => $latest['updated_at'] ?? null,
            'rows'           => $rows,
        ];
    }

    // ------------------------------------------------------------------
    // Headline numbers
    // ------------------------------------------------------------------

    /**
     * Headline cards for [$from, $to]: hits, unique visitors, page views,
     * bandwidth and visits, each with its share of traffic and the matching
     * change against the previous window of the same length.
     *
     * $includeBots adds bot hits and bot page views to the totals. Visits are
     * only ever counted for humans — a crawler does not have a session.
     */
    public static function summary(string $from, string $to, bool $includeBots = false): array
    {
        $current = self::sumRange($from, $to, $includeBots);
        [$prevFrom, $prevTo] = self::previousPeriod($from, $to);
        $previous = self::sumRange($prevFrom, $prevTo, $includeBots);

        $metrics = [
            'hits'        => ['label' => 'Hits',        'format' => 'number'],
            'page_views'  => ['label' => 'Page views',  'format' => 'number'],
            'visits'      => ['label' => 'Visits',      'format' => 'number'],
            'uniq_ips'    => ['label' => 'Unique IPs',  'format' => 'number'],
            'bytes'       => ['label' => 'Bandwidth',   'format' => 'bytes'],
            'avg_duration'=> ['label' => 'Avg visit',   'format' => 'duration'],
            'pages_per_visit' => ['label' => 'Pages/visit', 'format' => 'decimal'],
        ];

        $cards = [];
        foreach ($metrics as $key => $meta) {
            $value    = (float) ($current[$key] ?? 0);
            $before   = (float) ($previous[$key] ?? 0);
            $cards[$key] = [
                'label'  => $meta['label'],
                'format' => $meta['format'],
                'value'  => $value,
                'change' => self::percentChange($value, $before),
            ];
        }

        // Part-of-whole sub-lines on the cards that have one: how many of all
        // requests were actual page views, and how many hits were sessions.
        $cards['page_views']['share'] = self::share($current['page_views'] ?? 0, $current['hits'] ?? 0);
        $cards['visits']['share']     = self::share($current['visits'] ?? 0, $current['hits'] ?? 0);

        // Traffic split, shown next to the cards. This always describes ALL
        // traffic (robots included) so the Robots panel can say "x% of every
        // request" no matter how the page above it is filtered.
        $all = $includeBots ? $current : self::sumRange($from, $to, true);

        $cards['totals'] = [
            'hits'        => (int) ($all['hits'] ?? 0),
            'human_hits'  => (int) ($all['human_hits'] ?? 0),
            'bot_hits'    => (int) ($all['bot_hits'] ?? 0),
            'bot_share'   => self::share($all['bot_hits'] ?? 0, $all['hits'] ?? 0),
            'page_views'  => (int) ($all['page_views'] ?? 0),
            'bot_page_views' => (int) ($all['bot_page_views'] ?? 0),
            'asset_hits'  => (int) ($all['asset_hits'] ?? 0),
            'api_hits'    => (int) ($all['api_hits'] ?? 0),
            'bytes'       => (int) ($all['bytes'] ?? 0),
            'status_4xx'  => (int) ($all['status_4xx'] ?? 0),
            'status_5xx'  => (int) ($all['status_5xx'] ?? 0),
        ];

        return $cards;
    }

    /**
     * Totals across a day range. Bot columns are summed unconditionally here
     * and only combined with the human ones at the very end, so callers that
     * want to show both sides (the Robots panel, the CSV export) can use the
     * same function.
     */
    private static function sumRange(string $from, string $to, bool $includeBots): array
    {
        $row = Database::run(
            'SELECT COALESCE(SUM(hits), 0) AS hits,
                    COALESCE(SUM(human_hits), 0) AS human_hits,
                    COALESCE(SUM(bot_hits), 0) AS bot_hits,
                    COALESCE(SUM(page_views), 0) AS page_views,
                    COALESCE(SUM(bot_page_views), 0) AS bot_page_views,
                    COALESCE(SUM(asset_hits), 0) AS asset_hits,
                    COALESCE(SUM(api_hits), 0) AS api_hits,
                    COALESCE(SUM(bytes), 0) AS bytes,
                    COALESCE(SUM(visits), 0) AS visits,
                    COALESCE(SUM(visit_pages), 0) AS visit_pages,
                    COALESCE(SUM(visit_duration), 0) AS visit_duration,
                    COALESCE(SUM(status_4xx), 0) AS status_4xx,
                    COALESCE(SUM(status_5xx), 0) AS status_5xx
             FROM web_stats_daily WHERE day BETWEEN ? AND ?',
            [$from, $to]
        )->fetch() ?: [];

        foreach ($row as $key => $value) {
            $row[$key] = (int) $value;
        }

        $visits = $row['visits'];
        $row['uniq_ips'] = 0;
        $row['avg_duration'] = $visits > 0 ? $row['visit_duration'] / $visits : 0.0;
        $row['pages_per_visit'] = $visits > 0 ? $row['visit_pages'] / $visits : 0.0;

        // Unique IPs cannot be summed across days (the same person appears on
        // several days), so it is counted over the raw per-day rows instead.
        $uniq = Database::run(
            'SELECT COUNT(DISTINCT ip) AS c FROM web_stats_ips
             WHERE day BETWEEN ? AND ?' . ($includeBots ? '' : ' AND is_bot = 0'),
            [$from, $to]
        )->fetch();
        $row['uniq_ips'] = (int) ($uniq['c'] ?? 0);

        // hits and page_views are stored as two separate counters (the human
        // one plus its robot counterpart), so "humans only" is just a matter of
        // picking the human column; including robots means adding them back.
        // The raw split stays reachable for the Robots panel.
        if ($includeBots) {
            $row['page_views'] += $row['bot_page_views'];
        } else {
            $row['hits'] = $row['human_hits'];
        }

        return $row;
    }

    /**
     * Percentage change between two values, or null when there is no baseline
     * (a brand new site has no previous period to be "up" from).
     */
    private static function percentChange(float $value, float $before): ?float
    {
        if ($before <= 0.0) {
            return $value > 0.0 ? null : 0.0;
        }

        return round((($value - $before) / $before) * 100.0, 1);
    }

    /** Part-of-whole as a 0-100 percentage, 0.0 when the whole is empty. */
    private static function share(float $part, float $whole): float
    {
        return $whole > 0.0 ? round(($part / $whole) * 100.0, 1) : 0.0;
    }

    // ------------------------------------------------------------------
    // Time series
    // ------------------------------------------------------------------

    /**
     * One row per day in the range for the main chart. Days with no stored data
     * are filled in with zeros so a gap in the graph reads as "no traffic"
     * rather than silently compressing the x axis.
     */
    public static function dailySeries(string $from, string $to, bool $includeBots = false): array
    {
        $rows = Database::run(
            'SELECT day, hits, human_hits, bot_hits, page_views, bot_page_views,
                    visits, human_uniq_ips, bytes, status_4xx, status_5xx
             FROM web_stats_daily WHERE day BETWEEN ? AND ? ORDER BY day ASC',
            [$from, $to]
        )->fetchAll() ?: [];

        $byDay = [];
        foreach ($rows as $row) {
            $byDay[$row['day']] = $row;
        }

        $series = [];
        $cursor = new \DateTimeImmutable($from);
        $end    = new \DateTimeImmutable($to);

        while ($cursor <= $end) {
            $day   = $cursor->format('Y-m-d');
            $row   = $byDay[$day] ?? null;
            $hits  = $row ? (int) ($includeBots ? $row['hits'] : $row['human_hits']) : 0;
            $views = $row ? (int) ($includeBots ? $row['page_views'] + $row['bot_page_views'] : $row['page_views']) : 0;

            $series[] = [
                'day'       => $day,
                'label'     => $cursor->format('M j'),
                'hits'      => $hits,
                'page_views'=> $views,
                'visits'    => $row ? (int) $row['visits'] : 0,
                'uniq_ips'  => $row ? (int) $row['human_uniq_ips'] : 0,
                'bytes'     => $row ? (int) $row['bytes'] : 0,
                'status_4xx'=> $row ? (int) $row['status_4xx'] : 0,
                'status_5xx'=> $row ? (int) $row['status_5xx'] : 0,
            ];
            $cursor = $cursor->modify('+1 day');
        }

        return $series;
    }

    /**
     * Average traffic per hour of the day, for the 24-hour profile chart. Each
     * day in the range contributes one bucket, so this is the average number of
     * hits in that hour, not a total (which would just scale with range length).
     */
    public static function hourlyProfile(string $from, string $to, bool $includeBots = false): array
    {
        $rows = Database::run(
            'SELECT hour,
                    COALESCE(AVG(hits), 0) AS avg_hits,
                    COALESCE(AVG(human_hits), 0) AS avg_human,
                    COALESCE(AVG(uniq_ips), 0) AS avg_ips
             FROM web_stats_hourly WHERE day BETWEEN ? AND ? GROUP BY hour ORDER BY hour ASC',
            [$from, $to]
        )->fetchAll() ?: [];

        $byHour = [];
        foreach ($rows as $row) {
            $byHour[(int) $row['hour']] = $row;
        }

        $profile = [];
        for ($hour = 0; $hour < 24; $hour++) {
            $row  = $byHour[$hour] ?? null;
            $all  = $row ? (float) $row['avg_hits'] : 0.0;
            $hum  = $row ? (float) $row['avg_human'] : 0.0;

            $profile[] = [
                'hour'      => $hour,
                'label'     => sprintf('%02d:00', $hour),
                'hits'      => $includeBots ? $all : $hum,
                'all_hits'  => $all,
                'human_hits'=> $hum,
                'uniq_ips'  => $row ? (float) $row['avg_ips'] : 0.0,
            ];
        }

        return $profile;
    }

    /**
     * Traffic split into pages / static assets / API calls, as a percentage of
     * hits, plus the 4xx/5xx health figure.
     *
     * Asset and API counters are only kept as all-traffic totals, so this split
     * always describes every request regardless of the robots toggle; the panel
     * says so rather than showing a mix where only the page slice is filtered.
     */
    public static function requestMix(string $from, string $to, bool $includeBots = true): array
    {
        $row  = self::sumRange($from, $to, true);
        $hits = max(1, (int) ($row['hits'] ?? 0));

        return [
            ['label' => 'Pages',  'value' => (int) $row['page_views'],  'pct' => round($row['page_views'] / $hits * 100, 1)],
            ['label' => 'Assets', 'value' => (int) $row['asset_hits'],  'pct' => round($row['asset_hits'] / $hits * 100, 1)],
            ['label' => 'API',    'value' => (int) $row['api_hits'],    'pct' => round($row['api_hits'] / $hits * 100, 1)],
        ];
    }

    // ------------------------------------------------------------------
    // Dimension tables
    // ------------------------------------------------------------------

    /**
     * Most requested pages. Bots are excluded by default via human_hits, so the
     * list is what people actually looked at; with bots on, hits is used
     * instead and crawlers that re-fetch the same path show up.
     */
    public static function topUrls(string $from, string $to, int $limit = 25, bool $includeBots = false): array
    {
        $col = $includeBots ? 'hits' : 'human_hits';

        $rows = Database::run(
            "SELECT url,
                    SUM($col) AS hits,
                    SUM(uniq_ips) AS uniq_ips,
                    SUM(bytes) AS bytes,
                    COUNT(DISTINCT day) AS days
             FROM web_stats_urls WHERE day BETWEEN ? AND ?
             GROUP BY url HAVING SUM($col) > 0 ORDER BY hits DESC LIMIT " . max(1, min(500, $limit)),
            [$from, $to]
        )->fetchAll() ?: [];

        return array_map([self::class, 'shapeUrlRow'], $rows);
    }

    /**
     * Entry pages (where sessions started) and exit pages (where they ended),
     * which is where a visitor first landed and what they did last.
     */
    public static function entryPages(string $from, string $to, int $limit = 25): array
    {
        return self::visitPages('entry_url', $from, $to, $limit);
    }

    public static function exitPages(string $from, string $to, int $limit = 25): array
    {
        return self::visitPages('exit_url', $from, $to, $limit);
    }

    /**
     * Group human visits by one of their page columns. Entry/exit are stored per
     * visit rather than pre-aggregated, so this stays correct for any range
     * without a second set of rollup tables.
     */
    private static function visitPages(string $column, string $from, string $to, int $limit): array
    {
        $allowed = ['entry_url', 'exit_url'];
        $column  = in_array($column, $allowed, true) ? $column : 'entry_url';

        $rows = Database::run(
            "SELECT {$column} AS url,
                    COUNT(*) AS entries,
                    COALESCE(AVG(duration_sec), 0) AS avg_duration,
                    COUNT(DISTINCT ip) AS uniq_ips
             FROM web_visits
             WHERE day BETWEEN ? AND ? AND is_bot = 0 AND {$column} <> ''
             GROUP BY {$column} ORDER BY entries DESC LIMIT " . max(1, min(500, $limit)),
            [$from, $to]
        )->fetchAll() ?: [];

        return array_map([self::class, 'shapeUrlRow'], $rows);
    }

    /**
     * Referrers with their share of traffic. An empty host means the visitor
     * arrived directly (no Referer header, or a self-referral), which is worth
     * showing as its own row rather than dropping it.
     */
    public static function referrers(string $from, string $to, int $limit = 25, bool $includeBots = false): array
    {
        $rows = Database::run(
            'SELECT host,
                    SUM(hits) AS hits,
                    SUM(bot_hits) AS bot_hits
             FROM web_stats_referrers WHERE day BETWEEN ? AND ?
             GROUP BY host ORDER BY SUM(hits) DESC LIMIT ' . max(1, min(500, $limit)),
            [$from, $to]
        )->fetchAll() ?: [];

        // Share is of all human referrer hits in the range, not just this page
        // of results, so the percentage is stable as the limit changes.
        $total = (int) (Database::run(
            'SELECT COALESCE(SUM(hits), 0) AS c FROM web_stats_referrers WHERE day BETWEEN ? AND ?',
            [$from, $to]
        )->fetch()['c'] ?? 0);

        return array_map(static function (array $row) use ($includeBots, $total): array {
            $human = (int) $row['hits'];
            $bot   = (int) $row['bot_hits'];
            $hits  = $includeBots ? $human + $bot : $human;

            return [
                'host'  => (string) $row['host'],
                'label' => (string) $row['host'] === '' ? 'Direct / no referrer' : (string) $row['host'],
                'hits'  => $hits,
                'human' => $human,
                'bot'   => $bot,
                'pct'   => $total > 0 ? round($human / $total * 100, 1) : 0.0,
            ];
        }, $rows);
    }

    /**
     * Browsers, operating systems and devices as three separate breakdowns
     * (AWStats-style), each with its share of human hits.
     */
    public static function agentBreakdown(string $from, string $to, bool $includeBots = false): array
    {
        $rows = Database::run(
            'SELECT browser, os, device, is_bot, SUM(hits) AS hits
             FROM web_stats_agents WHERE day BETWEEN ? AND ?
             GROUP BY browser, os, device, is_bot',
            [$from, $to]
        )->fetchAll() ?: [];

        $browsers = [];
        $oses     = [];
        $devices  = [];
        $total    = 0;

        foreach ($rows as $row) {
            if ($row['is_bot'] && !$includeBots) {
                continue;
            }

            $hits = (int) $row['hits'];
            $total += $hits;

            $browser = (string) $row['browser'];
            $os      = (string) $row['os'];
            $device  = (string) $row['device'];

            $browsers[$browser] = ($browsers[$browser] ?? 0) + $hits;
            $oses[$os]           = ($oses[$os] ?? 0) + $hits;
            $devices[$device]    = ($devices[$device] ?? 0) + $hits;
        }

        return [
            'browsers' => self::shareRows($browsers, $total),
            'oses'     => self::shareRows($oses, $total),
            'devices'  => self::shareRows($devices, $total),
        ];
    }

    /**
     * Turn a label => hits tally into rows sorted by share, largest first.
     */
    private static function shareRows(array $tally, int $total): array
    {
        $rows = [];
        foreach ($tally as $label => $hits) {
            $rows[] = [
                'label' => (string) $label === '' ? 'Unknown' : (string) $label,
                'hits'  => $hits,
                'pct'   => $total > 0 ? round($hits / $total * 100, 1) : 0.0,
            ];
        }

        // Name as the tie-break keeps the order stable between requests.
        usort($rows, static fn (array $a, array $b): int => $b['hits'] <=> $a['hits'] ?: strcmp($a['label'], $b['label']));

        return $rows;
    }

    /**
     * Robots by user-agent token, for the dedicated Robots panel. Always bot
     * traffic regardless of the include-bots toggle, because that is the point
     * of the panel.
     */
    public static function robots(string $from, string $to, int $limit = 25): array
    {
        $rows = Database::run(
            'SELECT browser, SUM(hits) AS hits, SUM(bytes) AS bytes
             FROM web_stats_agents WHERE day BETWEEN ? AND ? AND is_bot = 1
             GROUP BY browser ORDER BY hits DESC LIMIT ' . max(1, min(500, $limit)),
            [$from, $to]
        )->fetchAll() ?: [];

        return array_map(static fn (array $row): array => [
            'label' => (string) $row['browser'],
            'hits'  => (int) $row['hits'],
            'bytes' => (int) $row['bytes'],
        ], $rows);
    }

    /**
     * HTTP status codes. Not filtered by the bots toggle: a 404 is a server
     * fact regardless of who asked for it, and hiding 404s from crawlers would
     * make the panel quietly optimistic.
     */
    public static function statuses(string $from, string $to, int $limit = 20): array
    {
        $rows = Database::run(
            'SELECT status, SUM(hits) AS hits, SUM(bytes) AS bytes
             FROM web_stats_status WHERE day BETWEEN ? AND ?
             GROUP BY status ORDER BY hits DESC LIMIT ' . max(1, min(100, $limit)),
            [$from, $to]
        )->fetchAll() ?: [];

        $total = array_sum(array_map(static fn ($r) => (int) $r['hits'], $rows));

        return array_map(static function (array $row) use ($total): array {
            $status = (int) $row['status'];

            return [
                'status' => $status,
                'label'  => self::statusLabel($status),
                'class'  => self::statusClass($status),
                'hits'   => (int) $row['hits'],
                'bytes'  => (int) $row['bytes'],
                'pct'    => $total > 0 ? round((int) $row['hits'] / $total * 100, 1) : 0.0,
            ];
        }, $rows);
    }

    /** "200" => "OK", "404" => "Not Found", and so on. */
    public static function statusLabel(int $status): string
    {
        $known = [
            200 => 'OK', 201 => 'Created', 204 => 'No Content', 206 => 'Partial Content',
            301 => 'Moved Permanently', 302 => 'Found', 304 => 'Not Modified',
            307 => 'Temporary Redirect', 308 => 'Permanent Redirect',
            400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden', 404 => 'Not Found',
            405 => 'Method Not Allowed', 408 => 'Request Timeout', 410 => 'Gone',
            413 => 'Payload Too Large', 415 => 'Unsupported Media Type', 422 => 'Unprocessable',
            429 => 'Too Many Requests', 499 => 'Client Closed Request',
            500 => 'Internal Server Error', 501 => 'Not Implemented', 502 => 'Bad Gateway',
            503 => 'Service Unavailable', 504 => 'Gateway Timeout',
        ];

        return $known[$status] ?? ($status >= 500 ? 'Server Error' : 'Redirect/Other');
    }

    /** CSS suffix for the status badge: ok / warn / bad. */
    public static function statusClass(int $status): string
    {
        if ($status >= 500 || $status === 403 || $status === 401) {
            return 'bad';
        }
        if ($status >= 400 || $status === 429) {
            return 'warn';
        }

        return 'ok';
    }

    /**
     * Static file types served, by extension (jpg, webp, css, ...).
     */
    public static function fileTypes(string $from, string $to, int $limit = 20): array
    {
        $rows = Database::run(
            'SELECT ext, SUM(hits) AS hits, SUM(bytes) AS bytes
             FROM web_stats_filetypes WHERE day BETWEEN ? AND ?
             GROUP BY ext ORDER BY hits DESC LIMIT ' . max(1, min(100, $limit)),
            [$from, $to]
        )->fetchAll() ?: [];

        $total = array_sum(array_map(static fn ($r) => (int) $r['hits'], $rows));

        return array_map(static function (array $row) use ($total): array {
            return [
                'ext'   => (string) $row['ext'],
                'label' => '.' . $row['ext'],
                'hits'  => (int) $row['hits'],
                'bytes' => (int) $row['bytes'],
                'pct'   => $total > 0 ? round((int) $row['hits'] / $total * 100, 1) : 0.0,
            ];
        }, $rows);
    }

    /**
     * Busiest visitor addresses, with their sessions. Raw IPs are stored and
     * shown on purpose (see the migration header): this is the fastest way to
     * tell a scraper, a mirror or a stalker from a normal reader.
     */
    public static function topVisitors(string $from, string $to, int $limit = 25, bool $includeBots = false): array
    {
        $rows = Database::run(
            'SELECT ip, is_bot, SUM(hits) AS hits, SUM(visits) AS visits, SUM(bytes) AS bytes
             FROM web_stats_ips WHERE day BETWEEN ? AND ?' . ($includeBots ? '' : ' AND is_bot = 0') . '
             GROUP BY ip, is_bot ORDER BY hits DESC LIMIT ' . max(1, min(500, $limit)),
            [$from, $to]
        )->fetchAll() ?: [];

        return array_map(static fn (array $row): array => [
            'ip'     => (string) $row['ip'],
            'is_bot' => (int) $row['is_bot'],
            'hits'   => (int) $row['hits'],
            'visits' => (int) $row['visits'],
            'bytes'  => (int) $row['bytes'],
        ], $rows);
    }

    /**
     * Session quality: how long people stayed, how many pages they saw and how
     * many sessions were a single hit (bounces).
     */
    public static function visitQuality(string $from, string $to): array
    {
        $row = Database::run(
            'SELECT COUNT(*) AS visits,
                    COUNT(DISTINCT ip) AS visitors,
                    COALESCE(AVG(duration_sec), 0) AS avg_duration,
                    COALESCE(AVG(pages), 0) AS avg_pages,
                    COALESCE(MAX(pages), 0) AS max_pages,
                    COALESCE(AVG(bytes), 0) AS avg_bytes
             FROM web_visits WHERE day BETWEEN ? AND ? AND is_bot = 0',
            [$from, $to]
        )->fetch() ?: [];

        $visits = (int) ($row['visits'] ?? 0);
        $bounce = Database::run(
            'SELECT COUNT(*) AS c FROM web_visits
             WHERE day BETWEEN ? AND ? AND is_bot = 0 AND pages <= 1',
            [$from, $to]
        )->fetch();

        return [
            'visits'        => $visits,
            'visitors'      => (int) ($row['visitors'] ?? 0),
            'avg_duration'  => (float) ($row['avg_duration'] ?? 0),
            'avg_pages'     => (float) ($row['avg_pages'] ?? 0),
            'max_pages'     => (int) ($row['max_pages'] ?? 0),
            'avg_bytes'     => (int) ($row['avg_bytes'] ?? 0),
            'bounces'       => (int) ($bounce['c'] ?? 0),
            'bounce_rate'   => $visits > 0 ? round((int) ($bounce['c'] ?? 0) / $visits * 100, 1) : 0.0,
        ];
    }

    /**
     * Normalise a top-pages row so the view can rely on the same keys whether
     * the row came from web_stats_urls or from a web_visits aggregate.
     */
    private static function shapeUrlRow(array $row): array
    {
        return [
            'url'      => (string) ($row['url'] ?? ''),
            'hits'     => (int) ($row['hits'] ?? ($row['entries'] ?? 0)),
            'uniq_ips' => (int) ($row['uniq_ips'] ?? 0),
            'bytes'    => (int) ($row['bytes'] ?? 0),
            'days'     => (int) ($row['days'] ?? 0),
            'avg_duration' => (int) ($row['avg_duration'] ?? 0),
        ];
    }

    // ------------------------------------------------------------------
    // CSV export
    // ------------------------------------------------------------------

    /**
     * Flat rows for the CSV export: one line per day with everything worth
     * keeping. Bots are always included in an export (it is the archival view);
     * the include-bots argument only controls the human-only columns.
     */
    public static function exportRows(string $from, string $to): array
    {
        return Database::run(
            'SELECT * FROM web_stats_daily WHERE day BETWEEN ? AND ? ORDER BY day ASC',
            [$from, $to]
        )->fetchAll() ?: [];
    }
}