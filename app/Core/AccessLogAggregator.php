<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\SiteConfig;

/**
 * Turns the Apache access logs into the web_stats_* tables.
 *
 * Read-side lives in App\Models\WebStats; this is the write side.
 *
 * Two properties matter more than speed here:
 *
 *  1. Idempotence. A day is rewritten as DELETE ... WHERE day = ? plus inserts,
 *     inside a transaction. Re-running a day overwrites it exactly, so the
 *     hourly cron, a manual re-parse and a recovery run can all repeat freely
 *     and can never double-count.
 *
 *  2. Correctness across log rotation. Apache rotates to access.log.1 and
 *     compresses yesterday's file to .gz, so "yesterday" is no longer in the
 *     file the site is currently writing to. Every run therefore reads the
 *     whole matching set (plain plus .gz, oldest first) and recomputes each day
 *     in full. There is no byte-offset state to drift out of sync, which is the
 *     usual way log importers quietly lose data.
 *
 * Sessions are the one derived number the parser cannot finalise on its own
 * (a visit straddling midnight is only closed once its last line arrives), so
 * after the visit rows are written the daily rollups are recomputed from them
 * with SQL. That is exact and keeps days consistent with the session table.
 *
 * Nothing is ever pruned. Days accumulate indefinitely, so the report keeps
 * working long after the raw logs have rotated away.
 *
 * Requirement on the server: the vhost's access logs must rotate by rename
 * (Debian/Ubuntu's stock /etc/logrotate.d/apache2 does this) and must NOT use
 * copytruncate. With copytruncate the rotated copy and the live file contain the
 * same lines, every recomputed day would count them twice, and there is no
 * reliable way to tell a real duplicate from the overlap — so a wrong
 * logrotate stanza silently doubles the traffic figures. The app's own logs in
 * config/logrotate-gallery.conf do use copytruncate, but those are a different
 * file and are never read here.
 */
class AccessLogAggregator
{
    /**
     * Log files to read. Patterns are globbed so a deployment with extra vhosts
     * or extra-sites-enabled logs can add them without a code change; the
     * default covers Debian/Ubuntu Apache (this app is the only thing on its
     * vhost, so its combined logs are the traffic of record).
     *
     * The trailing "*" is what makes backfills work: after logrotate the older
     * days live in access.log.1 and access.log.2.gz, so naming only the live
     * file would silently limit history to "since the last rotation".
     *
     * Overridable with the ACCESS_LOG_FILES environment variable (colon
     * separated), which is how the test suite and a one-off manual import point
     * at a specific file.
     */
    public const DEFAULT_PATTERNS = [
        '/var/log/apache2/access.log*',
        '/var/log/apache2/access_ssl.log*',
    ];

    /** Files that must not be read even if a pattern happens to match them. */
    private const SKIP_SUFFIXES = ['.gz.old', '.xz', '.bz2', '.zip'];

    // ------------------------------------------------------------------
    // Log discovery
    // ------------------------------------------------------------------

    /**
     * The patterns actually in use: the ACCESS_LOG_FILES override when set,
     * otherwise the built-in defaults.
     */
    public static function patterns(): array
    {
        $env = trim((string) env_value('ACCESS_LOG_FILES', ''));

        if ($env !== '') {
            $custom = array_values(array_filter(array_map('trim', explode(':', $env)), static fn ($p) => $p !== ''));
            if ($custom !== []) {
                return $custom;
            }
        }

        return self::DEFAULT_PATTERNS;
    }

    /**
     * Every readable log file matching the patterns, oldest first.
     *
     * Ordering is by modification time, which is what the parser needs: it
     * assumes a chronological stream so it can hold one day of aggregates at a
     * time and keep sessions across midnight. Apache's rotated names sort wrong
     * on their own (access.log.2 is older than access.log.1 but sorts after
     * it), so mtime is authoritative.
     *
     * A logrotate pass renames a whole set of files at the same instant, so
     * mtime alone leaves a large tie and the name order would then interleave
     * days. Within one second the rotation index decides instead, descending:
     * access.log.14.gz, access.log.2.gz, access.log.1, then the live file.
     */
    public static function discover(array $patterns = []): array
    {
        $patterns = $patterns ?: self::patterns();
        $found    = [];

        foreach ($patterns as $pattern) {
            // A plain path needs no globbing; a pattern may match many files.
            $matches = strpbrk($pattern, '*?[') !== false ? (glob($pattern) ?: []) : [$pattern];

            foreach ($matches as $path) {
                if (!is_file($path) || !is_readable($path)) {
                    continue;
                }

                $lower = strtolower($path);
                foreach (self::SKIP_SUFFIXES as $suffix) {
                    if (str_ends_with($lower, $suffix)) {
                        continue 2;
                    }
                }

                $found[$path] = (int) @filemtime($path);
            }
        }

        // Oldest first; inside one second the rotation index wins, because a
        // logrotate pass gives every file it touches the same mtime.
        uksort($found, static function ($a, $b) use ($found): int {
            return $found[$a] <=> $found[$b]
                ?: self::rotationRank($b) <=> self::rotationRank($a)
                ?: strcmp($a, $b);
        });

        return array_keys($found);
    }

    /**
     * How far a log file has been rotated back: 0 for the live file, 2 for
     * access.log.2(.gz), and so on. Unrecognised names fall back to 0 and rely
     * on mtime plus the path tie-break.
     */
    private static function rotationRank(string $path): int
    {
        $name = basename($path);
        $name = preg_replace('/\.gz$/i', '', $name) ?? $name;

        return preg_match('/\.(\d{1,3})$/', $name, $m) ? (int) $m[1] : 0;
    }

    /**
     * Yield the lines of every log file in order, transparently handling gzip.
     *
     * Lines are generated rather than loaded so a multi-gigabyte backfill never
     * holds the file contents in memory. An unreadable or truncated gzip member
     * yields what it can and then stops that file rather than aborting the run:
     * a half-rolled log must still produce the days it does contain.
     *
     * @param string[] $files
     * @return \Generator<int, string>
     */
    public static function lines(array $files): \Generator
    {
        foreach ($files as $path) {
            if (str_ends_with(strtolower($path), '.gz')) {
                $handle = @gzopen($path, 'rb');
                if ($handle === false) {
                    continue;
                }
                while (($line = gzgets($handle)) !== false) {
                    yield $line;
                }
                gzclose($handle);
                continue;
            }

            $handle = @fopen($path, 'rb');
            if ($handle === false) {
                continue;
            }
            while (($line = fgets($handle)) !== false) {
                yield $line;
            }
            fclose($handle);
        }
    }

    /**
     * Yield the log lines in calendar order, one day at a time.
     *
     * The rotated HTTP and HTTPS logs cover overlapping dates and rotate
     * independently, so reading the files in name order walks the calendar
     * backwards and forwards: a day is reached, written, and then reached
     * again from a different file. Every repeat replaced the day with a
     * fragment of it, which is how a day ended up showing one hit next to
     * thousands of sessions.
     *
     * The files are read oldest first and the days in flight are buffered, so
     * each day is emitted exactly once, in order, with only a day or two of
     * lines held in memory no matter how many files there are.
     *
     * @param string[] $files
     * @return \Generator<int, string>
     */
    public static function linesInDayOrder(array $files, string|\DateTimeZone $timezone): \Generator
    {
        $tz = $timezone instanceof \DateTimeZone ? $timezone : new \DateTimeZone($timezone);

        // First pass: the earliest request in each file, which is what orders
        // them. Files with nothing datable in them are dropped here.
        $starts = [];

        foreach ($files as $path) {
            $earliest = null;

            foreach (self::lines([$path]) as $line) {
                $ts = AccessLogParser::lineTimestamp($line);
                if ($ts !== null && ($earliest === null || $ts < $earliest)) {
                    $earliest = $ts;
                }
            }

            if ($earliest !== null) {
                $starts[$path] = $earliest;
            }
        }

        asort($starts);
        $queued = array_values($starts);

        $buffer = [];
        $index  = 0;

        foreach ($starts as $path => $earliest) {
            foreach (self::lines([$path]) as $line) {
                $ts = AccessLogParser::lineTimestamp($line);

                if ($ts === null) {
                    // Not a request (a port scanner, a truncated write): it has
                    // no day to sort by, so it is passed straight through.
                    yield $line;
                    continue;
                }

                // Same conversion the parser uses for its day key, so a day is
                // never split differently by the two.
                $buffer[(new \DateTimeImmutable('@' . $ts))->setTimezone($tz)->format('Y-m-d')][] = $line;
            }

            // Days older than the next file's first day can no longer grow.
            $next    = $queued[$index + 1] ?? null;
            $limit   = $next === null ? '9999-12-31' : (new \DateTimeImmutable('@' . $next))->setTimezone($tz)->format('Y-m-d');

            foreach (self::takeBefore($buffer, $limit) as $line) {
                yield $line;
            }

            $index++;
        }

        // Whatever is left is the tail of the newest files.
        foreach (self::takeBefore($buffer, '9999-12-31') as $line) {
            yield $line;
        }
    }

    /**
     * Yield the buffered lines of every day before $limit, in day order, and
     * drop those days from the buffer.
     *
     * @param array<string,string[]> $buffer
     * @return \Generator<int, string>
     */
    private static function takeBefore(array &$buffer, string $limit): \Generator
    {
        $ready = [];

        foreach ($buffer as $day => $lines) {
            if ($day < $limit) {
                $ready[$day] = $lines;
                unset($buffer[$day]);
            }
        }

        ksort($ready);

        foreach ($ready as $lines) {
            foreach ($lines as $line) {
                yield $line;
            }
        }
    }

    // ------------------------------------------------------------------
    // Running
    // ------------------------------------------------------------------

    /**
     * Aggregate the logs and return a summary for the caller to log.
     *
     * $options:
     *   days        how many recent days to refresh (default 2: yesterday is
     *                still moving after a rotation, today is still being written)
     *   from/to     explicit Y-m-d window, overriding days (used by the
     *                on-demand re-parse); both are clamped by the caller
     *   dry_run     parse and count but write nothing
     *   patterns    override the log globs
     *
     * @return array<string, mixed>
     */
    public static function run(array $options = []): array
    {
        $timezone = SiteConfig::timezone();
        $files    = self::discover($options['patterns'] ?? []);

        $window = self::window($options, $timezone);

        $summary = [
            'ok'             => false,
            'files'          => $files,
            'from'           => $window[0],
            'to'             => $window[1],
            'timezone'       => $timezone,
            'days_written'   => 0,
            'days_skipped'   => 0,
            'lines'          => 0,
            'skipped_lines'  => 0,
            'visits'         => 0,
            'urls'           => 0,
            'duration_sec'   => 0.0,
            'error'          => null,
            'dry_run'        => (bool) ($options['dry_run'] ?? false),
        ];

        if ($files === []) {
            $summary['error'] = 'no readable access log files found';

            return $summary;
        }

        $started  = microtime(true);
        $from     = $window[0];
        $to       = $window[1];
        $written  = [];
        $dryRun   = !empty($options['dry_run']);
        $basePath = (string) config('app.base_path', '');
        // Referrers pointing back at the site itself are direct traffic, so the
        // parser needs to know the hostname it is running under.
        $selfHost = (string) (parse_url((string) env_value('APP_URL', ''), PHP_URL_HOST) ?: '');
        // Staging/LAN installs serve real visitors from 192.168.x, which the
        // parser skips by default (that range is also where the site's own cron
        // and health checks come from). ANALYTICS_INCLUDE_PRIVATE=1 opts in.
        $keepLocal = self::flag($options['include_private'] ?? null)
            || self::flag(env_value('ANALYTICS_INCLUDE_PRIVATE', ''));

        // One transaction per day rather than one for the whole run: a long
        // backfill that dies halfway leaves whole days committed and correct,
        // and the next run picks up from there.
        $visitsByDay = [];

        $totals = AccessLogParser::streamByDay(
            self::linesInDayOrder($files, $timezone),
            ['base_path' => $basePath, 'site_host' => $selfHost, 'include_private' => $keepLocal, 'timezone' => $timezone],
            static function (array $day, string $dayKey) use ($from, $to, &$written, $dryRun, $files, &$summary, &$visitsByDay): void {
                // A chunk carries every session that closed while that day was
                // being read, including sessions that started on an earlier day.
                foreach ($day['visits'] ?? [] as $visit) {
                    $visitsByDay[(string) ($visit['day'] ?? $dayKey)][] = $visit;
                }

                if ($dayKey < $from || $dayKey > $to) {
                    // Outside the requested window: parsed (so sessions keep
                    // flowing) but not persisted.
                    $summary['days_skipped']++;
                    return;
                }

                if (!$dryRun) {
                    self::store($dayKey, $day, $files);
                }

                $summary['days_written']++;
                $summary['skipped_lines'] += (int) ($day['skipped'] ?? 0);
                $summary['visits']        += count($day['visits'] ?? []);
                $summary['urls']          += count($day['urls'][$dayKey] ?? []);
                $written[$dayKey] = true;
            }
        );

        // streamByDay() reports how much of the log it actually walked, which
        // includes the days outside the window that were parsed but not stored.
        if (!$dryRun && $written !== []) {
            // Sessions land last, so a visit that straddled midnight is counted
            // on the day it started, and a visit that closed during a later day
            // is still written to the right one.
            self::finalizeVisits(array_keys($written), $visitsByDay);
        }

        $summary['lines']        = $totals['lines'];
        $summary['ok']           = true;
        $summary['duration_sec'] = round(microtime(true) - $started, 2);

        return $summary;
    }

    /** Truthy test for an option that may arrive as a bool, an int or a string. */
    private static function flag(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }

    /**
     * Resolve the day window to refresh.
     *
     * @return array{0: string, 1: string} [from, to] inclusive, Y-m-d
     */
    public static function window(array $options, string $timezone): array
    {
        $tz = new \DateTimeZone($timezone);

        if (!empty($options['from']) || !empty($options['to'])) {
            $from = self::day((string) ($options['from'] ?? ''), $tz) ?? (new \DateTimeImmutable('now', $tz))->format('Y-m-d');
            $to   = self::day((string) ($options['to'] ?? ''), $tz) ?? $from;
            if ($from > $to) {
                [$from, $to] = [$to, $from];
            }

            return [$from, $to];
        }

        $days = max(1, (int) ($options['days'] ?? 2));
        $to   = (new \DateTimeImmutable('now', $tz))->format('Y-m-d');

        return [(new \DateTimeImmutable($to, $tz))->modify('-' . ($days - 1) . ' days')->format('Y-m-d'), $to];
    }

    /** Parse a Y-m-d string into a day, or null when it is not one. */
    private static function day(string $value, \DateTimeZone $tz): ?string
    {
        $value = trim($value);
        if ($value === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $value));
        if (!checkdate($m, $d, $y)) {
            return null;
        }

        return (new \DateTimeImmutable($value, $tz))->format('Y-m-d');
    }

    // ------------------------------------------------------------------
    // Persistence
    // ------------------------------------------------------------------

    /**
     * Write one day of aggregates, replacing whatever was there before.
     *
     * The whole day is one transaction: either the new numbers are all in place
     * or the previous numbers are still intact. Every dimension table is keyed
     * by day, so replacing is a plain DELETE plus bulk inserts with no
     * upsert-key juggling.
     */
    public static function store(string $day, array $data, array $files): void
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();

        try {
            self::deleteDay($day);

            $summary = $data['days'][$day] ?? null;

            // The row is only created when the day actually had traffic. An
            // empty day in the middle of the window (the site was down) is
            // recorded as absent rather than as a row of zeros, so "no data"
            // and "no visitors" stay distinguishable.
            if ($summary !== null && (int) $summary['hits'] > 0) {
                self::insertDaily($day, $summary, $data, $files);
                self::insertHourly($day, $data['hours'][$day] ?? []);
                self::insertUrls($day, $data['urls'][$day] ?? []);
                self::insertReferrers($day, $data['referrers'][$day] ?? []);
                self::insertAgents($day, $data['agents'][$day] ?? []);
                self::insertStatuses($day, $data['status'][$day] ?? []);
                self::insertFileTypes($day, $data['filetypes'][$day] ?? []);
                self::insertIps($day, $data['ips'][$day] ?? []);
            }

            // Sessions are written once at the end of the run, grouped by the
            // day each visit started on: a visit can close while a later day is
            // being streamed, so its own day is often already written by now.
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** Remove every trace of a day from all the aggregate tables. */
    private static function deleteDay(string $day): void
    {
        $tables = ['web_stats_daily', 'web_stats_hourly', 'web_stats_urls', 'web_stats_referrers',
                   'web_stats_agents', 'web_stats_status', 'web_stats_filetypes', 'web_stats_ips'];

        foreach ($tables as $table) {
            Database::run("DELETE FROM {$table} WHERE day = ?", [$day]);
        }
        Database::run('DELETE FROM web_visits WHERE day = ?', [$day]);
    }

    private static function insertDaily(string $day, array $s, array $data, array $files): void
    {
        Database::run(
            'INSERT INTO web_stats_daily
                (day, hits, human_hits, bot_hits, page_views, bot_page_views, asset_hits, api_hits,
                 bytes, visits, visit_pages, visit_duration, uniq_ips, human_uniq_ips,
                 status_4xx, status_5xx, skipped_lines, source_files)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $day,
                (int) $s['hits'],
                (int) $s['human_hits'],
                (int) $s['bot_hits'],
                (int) $s['page_views'],
                (int) $s['bot_page_views'],
                (int) $s['asset_hits'],
                (int) $s['api_hits'],
                (int) $s['bytes'],
                (int) $s['visits'],
                (int) $s['visit_pages'],
                (int) $s['visit_duration'],
                (int) $s['uniq_ips'],
                (int) $s['human_uniq_ips'],
                (int) $s['status_4xx'],
                (int) $s['status_5xx'],
                (int) ($data['skipped'] ?? 0),
                self::sourceList($files),
            ]
        );
    }

    private static function insertHourly(string $day, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $sql = 'INSERT INTO web_stats_hourly (day, hour, hits, human_hits, bot_hits, bytes, uniq_ips)
                VALUES (?, ?, ?, ?, ?, ?, ?)';

        foreach ($rows as $hour => $row) {
            Database::run($sql, [
                $day, (int) $hour,
                (int) $row['hits'], (int) $row['human_hits'], (int) $row['bot_hits'],
                (int) $row['bytes'], (int) $row['uniq_ips'],
            ]);
        }
    }

    private static function insertUrls(string $day, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        // Merge on a key collision instead of aborting the day: the parser
        // already folds case variants, so anything left is an exotic collision
        // (two paths that differ only past the column limit, say) where
        // under-counting beats losing the whole day. uniq_ips takes the larger
        // count rather than a sum, which would double-count the address.
        $sql = 'INSERT INTO web_stats_urls (day, url, hits, human_hits, bytes, uniq_ips)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    hits       = hits + VALUES(hits),
                    human_hits = human_hits + VALUES(human_hits),
                    bytes      = bytes + VALUES(bytes),
                    uniq_ips   = GREATEST(uniq_ips, VALUES(uniq_ips))';

        foreach ($rows as $url => $r) {
            Database::run($sql, [
                $day, (string) ($r['path'] ?? $url),
                (int) $r['hits'], (int) $r['human_hits'], (int) $r['bytes'], (int) ($r['uniq_ips'] ?? 0),
            ]);
        }
    }

    private static function insertReferrers(string $day, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $sql = 'INSERT INTO web_stats_referrers (day, host, hits, bot_hits) VALUES (?, ?, ?, ?)';

        foreach ($rows as $host => $r) {
            Database::run($sql, [
                $day, (string) $host, (int) ($r['hits'] ?? 0), (int) ($r['bot_hits'] ?? 0),
            ]);
        }
    }

    private static function insertAgents(string $day, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $sql = 'INSERT INTO web_stats_agents (day, browser, os, device, is_bot, hits, bytes)
                VALUES (?, ?, ?, ?, ?, ?, ?)';

        foreach ($rows as $r) {
            $ref = $r['ref'];

            Database::run($sql, [
                $day,
                (string) $ref['browser'],
                (string) $ref['os'],
                (string) $ref['device'],
                (int) $ref['is_bot'],
                (int) $r['hits'],
                (int) $r['bytes'],
            ]);
        }
    }

    private static function insertStatuses(string $day, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $sql = 'INSERT INTO web_stats_status (day, status, hits, bytes) VALUES (?, ?, ?, ?)';

        foreach ($rows as $status => $r) {
            Database::run($sql, [$day, (int) $status, (int) ($r['hits'] ?? 0), (int) ($r['bytes'] ?? 0)]);
        }
    }

    private static function insertFileTypes(string $day, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $sql = 'INSERT INTO web_stats_filetypes (day, ext, hits, bytes) VALUES (?, ?, ?, ?)';

        foreach ($rows as $ext => $r) {
            Database::run($sql, [$day, (string) $ext, (int) $r['hits'], (int) $r['bytes']]);
        }
    }

    private static function insertIps(string $day, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        // An address has several text forms (0:0:0:0:0:0:0:1 vs ::1), so merge
        // rather than abort the day if two of them land in the same bucket.
        $sql = 'INSERT INTO web_stats_ips (day, ip, is_bot, hits, visits, bytes)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    hits   = hits + VALUES(hits),
                    visits = visits + VALUES(visits),
                    bytes  = bytes + VALUES(bytes)';

        foreach ($rows as $r) {
            Database::run($sql, [
                $day, (string) $r['ip'], (int) $r['is_bot'],
                (int) $r['hits'], (int) $r['visits'], (int) $r['bytes'],
            ]);
        }
    }

    /**
     * Session rows, each filed under the day it started on.
     *
     * Started/last-seen are stored in the site timezone (not UTC) so the
     * durations and the "session started at" column read the same as the rest of
     * the admin UI without every reader converting.
     */
    private static function insertVisits(array $visits): void
    {
        if ($visits === []) {
            return;
        }

        $sql = 'INSERT INTO web_visits
                    (day, ip, started_at, last_seen, duration_sec, pages, bytes,
                     entry_url, exit_url, referrer, browser, os, device, is_bot)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';

        foreach ($visits as $v) {
            $agent = $v['agent'] ?? [];

            Database::run($sql, [
                (string) $v['day'],
                (string) $v['ip'],
                (string) $v['started_at'],
                (string) $v['last_seen'],
                (int) $v['duration_sec'],
                (int) $v['pages'],
                (int) $v['bytes'],
                (string) $v['entry_url'],
                (string) $v['exit_url'],
                (string) $v['referrer'],
                (string) ($agent['browser'] ?? ''),
                (string) ($agent['os'] ?? ''),
                (string) ($agent['device'] ?? ''),
                (int) ($v['is_bot'] ?? 0),
            ]);
        }
    }

    /**
     * Write the sessions collected during the run and refresh their rollups.
     *
     * Each written day is rebuilt from scratch inside one transaction, so a
     * re-run replaces its sessions instead of stacking a second copy on top, and
     * days that no longer hold a session go back to zero rather than keeping a
     * stale count.
     *
     * @param array<string,array<int,array<string,mixed>>> $visitsByDay
     */
    private static function finalizeVisits(array $days, array $visitsByDay): void
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();

        try {
            foreach ($days as $day) {
                Database::run('DELETE FROM web_visits WHERE day = ?', [$day]);
                self::insertVisits($visitsByDay[$day] ?? []);
                self::refreshVisitRollupsFor($day);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Recompute the session-derived columns for one day from web_visits.
     *
     * The parser cannot finalise a session that is still open when the log runs
     * out (the last visitor of the day), and a visit straddling midnight is
     * closed while a later day is being written. Deriving these columns from
     * web_visits after the fact makes them exact, keeps every day consistent
     * with the session table, and means a session is always counted on the day
     * it started on no matter where the stream split it.
     *
     * Both statements are set-based on purpose. The per-address one used to be
     * a correlated subquery per driver row, and web_stats_ips holds hundreds of
     * addresses per day, so each of them re-probed that whole day's sessions
     * through the (day, is_bot) index: 0.9s warm and 6.9s cold on production,
     * hourly, and it was 39 of the 40 entries in the slow-query list. One
     * grouped pass measures 0.008s for the same values.
     *
     * @internal runs inside finalizeVisits()'s transaction
     */
    private static function refreshVisitRollupsFor(string $day): void
    {
        Database::run(
            'UPDATE web_stats_daily d
             LEFT JOIN (SELECT COUNT(*) AS c,
                               COALESCE(SUM(pages), 0) AS p,
                               COALESCE(SUM(duration_sec), 0) AS s
                        FROM web_visits
                        WHERE day = ? AND is_bot = 0) x ON 1 = 1
                SET d.visits         = COALESCE(x.c, 0),
                    d.visit_pages    = COALESCE(x.p, 0),
                    d.visit_duration = COALESCE(x.s, 0)
              WHERE d.day = ?',
            [$day, $day]
        );

        // Bots have no sessions, so only the human row of an address carries a
        // session count.
        Database::run(
            'UPDATE web_stats_ips i
             LEFT JOIN (SELECT ip, COUNT(*) AS c
                        FROM web_visits
                        WHERE day = ? AND is_bot = 0
                        GROUP BY ip) x ON x.ip = i.ip
                SET i.visits = COALESCE(x.c, 0)
              WHERE i.day = ? AND i.is_bot = 0',
            [$day, $day]
        );
    }

    /** Comma-separated basenames of the files a run read, for the audit trail. */
    private static function sourceList(array $files): string
    {
        $names = [];
        foreach ($files as $path) {
            $names[] = basename((string) $path);
        }
        $names = array_slice(array_values(array_unique($names)), 0, 8);

        return mb_substr(implode(',', $names), 0, 255);
    }
}
