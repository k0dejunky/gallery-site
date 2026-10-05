<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Apache "combined" access-log parser, in the spirit of what AWStats does.
 *
 * Deliberately pure PHP with no dependencies (no DB, no config(), no
 * site_timezone(), no web server): the aggregator feeds it ordered log lines
 * and gets plain arrays back, so the exact same code runs under
 * `php tests/smoke.php` on CI, in a cron job, and in the on-demand re-parse.
 *
 * What it does with a line:
 *   - tolerantly splits the combined format; anything it cannot make sense of
 *     is counted in `skipped` rather than throwing (log format drift must
 *     never take the report down),
 *   - drops the site's own traffic (loopback / RFC1918) so the local
 *     housekeeping cron does not inflate the numbers,
 *   - classifies the target as page / asset / api, and the user agent as
 *     browser / OS / device / bot,
 *   - normalises the URL (query string stripped, base path removed) because
 *     a single thumbnail is otherwise a distinct "page" on every request,
 *   - folds requests into visits using a 30-minute inactivity window,
 *     streaming so memory stays bounded by the number of *open* visits
 *     rather than the number of log lines.
 *
 * Callers MUST feed lines in chronological order (the aggregator reads each
 * rotated file oldest-first, which is exactly what Apache writes).
 */
class AccessLogParser
{
    /** A gap longer than this ends the current visit and starts a new one. */
    public const VISIT_GAP_SECONDS = 1800;

    /** Caps, so one stuck scraper cannot invent a 12-hour "visit". */
    public const MAX_VISIT_SECONDS = 14400; // 4 hours
    public const MAX_VISIT_PAGES   = 500;

    public const MAX_URL_LENGTH    = 255;
    public const MAX_AGENT_LENGTH  = 48;

    /**
     * Safety valve for a scanner fuzzing random paths: past this many distinct
     * page paths in one day the remainder are folded into "(other)" so the
     * table cannot be used to blow up storage.
     */
    public const MAX_URLS_PER_DAY = 5000;

    /**
     * Extensions served as static files. .json is deliberately NOT here: the
     * app serves JSON API responses from many routes, and those belong in the
     * api bucket rather than counting as static assets.
     */
    private const ASSET_EXT = [
        'css', 'js', 'mjs', 'map', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif',
        'svg', 'ico', 'bmp', 'tif', 'tiff', 'woff', 'woff2', 'ttf', 'eot',
        'otf', 'mp4', 'webm', 'mov', 'm4v', 'mkv', 'mp3', 'm4a', 'wav', 'ogg',
        'oga', 'opus', 'flac', 'pdf', 'zip', 'gz', 'txt', 'xml', 'wasm',
    ];

    /**
     * Substrings that mark a robot. Matched case-insensitively against the
     * whole user agent. Kept as one flat list so a new agent is a one-word
     * change rather than a new expression.
     */
    private const BOT_TOKENS = [
        'bot', 'crawl', 'spider', 'slurp', 'scrapy', 'archiver', 'curl/',
        'wget', 'python-requests', 'python-urllib', 'httpclient', 'java/',
        'okhttp', 'go-http-client', 'libwww', 'lwp-', 'w3m', 'lynx', 'links/',
        'headlesschrome', 'phantomjs', 'puppeteer', 'selenium', 'facebookexternalhit',
        'facebookcatalog', 'twitterbot', 'linkedinbot', 'pinterest', 'slackbot',
        'discordbot', 'telegrambot', 'whatsapp', 'applebot', 'amazonbot',
        'ahrefsbot', 'ahrefs', 'semrushbot', 'semrush', 'mj12bot', 'dotbot',
        'petalbot', 'bytespider', 'gptbot', 'chatgpt-user', 'oai-searchbot',
        'claudebot', 'anthropic-ai', 'perplexitybot', 'ccbot', 'googlebot',
        'google-inspectiontool', 'bingbot', 'bingpreview', 'adidxbot', 'yandex',
        'duckduckbot', 'baiduspider', 'seznambot', 'exabot', 'ia_archiver',
        'archive.org_bot', 'adsbot-google', 'mediapartners-google', 'feedfetcher',
        'feedburner', 'uptimerobot', 'pingdom', 'statuscake', 'site24x7',
        'newrelic', 'datadog', 'prometheus', 'majestic', 'sogou', '360spider',
        'coccocot', 'panscient', 'zgrab', 'masscan', 'nmap', 'zmeu', 'sqlmap',
        'nikto', 'dirbuster', 'wpscan', 'rogerbot', 'spinn3r', 'megaindex',
        'serpstat', 'linkdexbot', 'gnowitbot', 'naver', 'seznam', 'woobot',
        'vuhuvbot', 'qwantify', 'bingpreview', 'teoma', 'gsa-crawler',
        'emoticonspider', 'ichiro', 'yeti/', 'mojeekbot', 'barkrowler',
    ];

    /** Named robots, so the Robots panel reads "Googlebot" not "bot". */
    private const BOT_NAMES = [
        'Googlebot'        => '/googlebot/i',
        'Google-Image'      => '/google[- ]?image/i',
        'Google AdsBot'    => '/adsbot-google/i',
        'GoogleOther'      => '/googleother/i',
        'bingbot'          => '/bingbot/i',
        'bingpreview'      => '/bingpreview/i',
        'Amazonbot'        => '/amazonbot/i',
        'Applebot'         => '/applebot/i',
        'GPTBot'           => '/gptbot|oai-searchbot|chatgpt-user/i',
        'ClaudeBot'        => '/claudebot|anthropic-ai/i',
        'PerplexityBot'    => '/perplexitybot/i',
        'CCBot'            => '/ccbot/i',
        'AhrefsBot'        => '/ahrefsbot/i',
        'SemrushBot'       => '/semrushbot/i',
        'MJ12bot'          => '/mj12bot/i',
        'DotBot'           => '/dotbot/i',
        'PetalBot'         => '/petalbot/i',
        'Bytespider'       => '/bytespider/i',
        'YandexBot'        => '/yandex(bot|images)/i',
        'DuckDuckBot'      => '/duckduckbot/i',
        'Baiduspider'      => '/baiduspider/i',
        'SeznamBot'        => '/seznambot/i',
        'facebookexternalhit' => '/facebookexternalhit/i',
        'Twitterbot'       => '/twitterbot/i',
        'LinkedInBot'      => '/linkedinbot/i',
        'TelegramBot'      => '/telegrambot/i',
        'Discordbot'       => '/discordbot/i',
        'WhatsApp'         => '/whatsapp/i',
        'Pinterestbot'     => '/pinterest/i',
        'Slackbot'         => '/slackbot/i',
        'UptimeRobot'      => '/uptimerobot/i',
        'curl'             => '/curl\//i',
        'Wget'             => '/wget/i',
        'python-requests'  => '/python-(requests|urllib)/i',
        'Go-http-client'   => '/go-http-client/i',
        'Apache-HttpClient'=> '/apache-httpclient/i',
        'Java'             => '/java\/|okhttp/i',
        'libwww-perl'      => '/libwww-perl|lwp-trivial/i',
        'HeadlessChrome'   => '/headlesschrome|phantomjs|puppeteer/i',
        'masscan'          => '/masscan|nmap|zgrab|zmeu/i',
        'sqlmap'           => '/sqlmap|nikto|dirbuster|wpscan/i',
        'feedfetcher'      => '/feedfetcher|feedburner/i',
    ];

    /** Browser name => pattern, checked in order (most specific first). */
    private const BROWSERS = [
        'Edge'      => '/Edg(?:e|A|iOS)?\//i',
        'Opera'     => '/OPR\/|Opera/i',
        'Vivaldi'   => '/Vivaldi\//i',
        'Brave'     => '/Brave\//i',
        'Samsung'   => '/SamsungBrowser\//i',
        'Yandex'    => '/YaBrowser\//i',
        'UCBrowser' => '/UCBrowser\//i',
        'Firefox'   => '/Firefox\/|FxiOS\//i',
        'Chrome'    => '/Chrom(?:e|ium)\/|CriOS\//i',
        'Safari'    => '/Safari\//i',
        'MSIE'      => '/MSIE |Trident\//i',
    ];

    /** OS name => pattern, checked in order. */
    private const OPERATING_SYSTEMS = [
        'Windows'    => '/Windows NT|Windows Phone|Win64/i',
        'Android'    => '/Android/i',
        'iOS'        => '/iPhone|iPad|iPod|iOS/i',
        'macOS'      => '/Mac OS X|Macintosh/i',
        'Chrome OS'  => '/CrOS/i',
        'Linux'      => '/Linux|X11/i',
        'FreeBSD'    => '/FreeBSD/i',
    ];

    /** Path prefixes that are never a "page view". */
    private const API_PREFIXES = [
        '/webhooks/', '/cron/', '/health', '/chat/poll', '/chat/stream',
        '/live/chat', '/chat/attachment',
    ];

    /** Path prefixes served as static files by this app. */
    private const ASSET_PREFIXES = ['/assets/', '/files/'];

    /** Maps a path classification onto its per-day counter column. */
    private const DAY_COUNTER = [
        'page'  => 'page_views',
        'asset' => 'asset_hits',
        'api'   => 'api_hits',
    ];

/**
     * A fresh set of accumulators, one per table the parser feeds.
     */
    private static function emptyResult(): array
    {
        return [
            'days'      => [],
            'hours'     => [],
            'urls'      => [],
            'agents'    => [],
            'referrers' => [],
            'status'    => [],
            'filetypes' => [],
            'ips'       => [],
            'visits'    => [],
            'skipped'   => 0,
        ];
    }

    /**
     * Fold one parsed log line into the accumulators.
     *
     * $result is passed by reference (it is the big array) and $open is the
     * shared table of in-progress visits keyed by IP, which the caller owns so
     * it can survive a day boundary between calls.
     */
    private static function feed(array &$result, array &$open, array $request, string $basePath, string $selfHost, \DateTimeZone $timezone): void
    {
        $local = $request['time']->setTimezone($timezone);
        $day   = $local->format('Y-m-d');
        $hour  = (int) $local->format('G');

        $days   = &$result['days'];
        $hours  = &$result['hours'];
        $urls   = &$result['urls'];
        $agents = &$result['agents'];
        $refs   = &$result['referrers'];
        $status = &$result['status'];
        $files  = &$result['filetypes'];
        $ips    = &$result['ips'];

        self::touchDay($days, $day);

        if (!isset($status[$day])) {
            $status[$day] = [];
        }

        $agent    = self::classifyAgent($request['agent']);
        $isBot    = $agent['is_bot'] === 1;
        $path     = self::normalizeUrl($request['target'], $basePath);
        $kind     = self::classifyPath($request['target'], $path);
        $bytes    = $request['bytes'];
        $referrer = self::referrerHost($request['referrer'], $selfHost);

        // A POST/PUT to an HTML-looking path is an action, not a page view (the
        // visitor never looked at it), so it counts as API traffic instead.
        if ($kind === 'page' && !in_array($request['method'], ['GET', 'HEAD'], true)) {
            $kind = 'api';
        }

        // ---- per-day counters
        $days[$day]['hits']++;
        $days[$day]['bytes'] += $bytes;
        $days[$day][$isBot ? 'bot_hits' : 'human_hits']++;
        // page_views counts pages people looked at; bot page fetches are kept
        // separately so the include-bots toggle can add them back later without
        // re-reading the logs.
        if ($kind === 'page') {
            $days[$day][$isBot ? 'bot_page_views' : 'page_views']++;
        } else {
            $days[$day][self::DAY_COUNTER[$kind]]++;
        }
        $days[$day]['ips'][$request['ip']] = true;

        if (!$isBot) {
            $days[$day]['human_ips'][$request['ip']] = true;
        }

        // ---- hourly profile
        if (!isset($hours[$day][$hour])) {
            $hours[$day][$hour] = [
                'hits'       => 0,
                'human_hits' => 0,
                'bot_hits'   => 0,
                'bytes'      => 0,
                'ips'        => [],
            ];
        }
        $hours[$day][$hour]['hits']++;
        $hours[$day][$hour]['bytes'] += $bytes;
        $hours[$day][$hour][$isBot ? 'bot_hits' : 'human_hits']++;
        $hours[$day][$hour]['ips'][$request['ip']] = true;

        // ---- per-day per-IP rows (one row per address *and* bot flag, since an
        // address can appear both as a person and as a crawler and the toggle
        // has to be able to split them)
        $ipKey = $request['ip'] . ($isBot ? '|1' : '|0');
        if (!isset($ips[$day][$ipKey])) {
            $ips[$day][$ipKey] = [
                'ip'     => $request['ip'],
                'is_bot' => $isBot ? 1 : 0,
                'hits'   => 0,
                'bytes'  => 0,
                'visits' => 0,
            ];
        }
        $ips[$day][$ipKey]['hits']++;
        $ips[$day][$ipKey]['bytes'] += $bytes;

        // ---- status codes
        $statusCode = (int) $request['status'];
        if (!isset($status[$day][$statusCode])) {
            $status[$day][$statusCode] = ['hits' => 0, 'bytes' => 0];
        }
        $status[$day][$statusCode]['hits']++;
        $status[$day][$statusCode]['bytes'] += $bytes;

        if ($statusCode >= 400 && $statusCode < 500) {
            $days[$day]['status_4xx']++;
        } elseif ($statusCode >= 500) {
            $days[$day]['status_5xx']++;
        }

        // ---- page paths (assets are aggregated by file type instead)
        if ($kind === 'page') {
            if (!isset($urls[$day])) {
                $urls[$day] = [];
            }

            // Group case variants into one row. Scanners probe /.env, /.Env and
            // /.ENV, which are distinct PHP array keys but a single key in the
            // table (MySQL's default collation ignores case), so storing them
            // apart would abort the whole day on a duplicate-key error. The
            // first spelling seen is kept for display; counting adds up.
            $fold = mb_strtolower($path);

            // Overflow guard: past MAX_URLS_PER_DAY distinct paths in a day the
            // remainder fold into "(other)". The cap limits stored paths only —
            // counting continues either way.
            $key = isset($urls[$day][$fold]) || count($urls[$day]) < self::MAX_URLS_PER_DAY
                ? $fold
                : '(other)';

            if (!isset($urls[$day][$key])) {
                $urls[$day][$key] = [
                    'hits' => 0, 'human_hits' => 0, 'bytes' => 0, 'ips' => [],
                    'path' => $key === '(other)' ? '(other)' : $path,
                ];
            }

            $urls[$day][$key]['hits']++;
            $urls[$day][$key]['bytes'] += $bytes;
            if (!$isBot) {
                $urls[$day][$key]['human_hits']++;
                $urls[$day][$key]['ips'][$request['ip']] = true;
            }
        }

        if ($kind === 'asset') {
            $ext = self::extensionOf($request['target']);
            if ($ext !== '') {
                if (!isset($files[$day])) {
                    $files[$day] = [];
                }
                if (!isset($files[$day][$ext])) {
                    $files[$day][$ext] = ['hits' => 0, 'bytes' => 0];
                }
                $files[$day][$ext]['hits']++;
                $files[$day][$ext]['bytes'] += $bytes;
            }
        }

        // ---- referrers (bots skew these badly, so they are kept separate)
        $refKey = $referrer . ($isBot ? '|bot' : '|human');
        if (!isset($refs[$day])) {
            $refs[$day] = [];
        }
        $refs[$day][$refKey] = ($refs[$day][$refKey] ?? 0) + 1;

        // ---- agent classes
        $agentKey = $agent['browser'] . "\t" . $agent['os'] . "\t"
            . $agent['device'] . ($isBot ? "\t1" : '');
        if (!isset($agents[$day])) {
            $agents[$day] = [];
        }
        if (!isset($agents[$day][$agentKey])) {
            $agents[$day][$agentKey] = ['hits' => 0, 'bytes' => 0, 'ref' => $agent];
        }
        $agents[$day][$agentKey]['hits']++;
        $agents[$day][$agentKey]['bytes'] += $bytes;

        // ---- visit folding (one open visit per IP, owned by the caller so it
        // can outlive a day boundary)
        $ts       = $request['time']->getTimestamp();
        $current  = $open[$request['ip']] ?? null;

        // A timestamp that goes backwards means the stream was not in order;
        // end the session rather than folding the two requests together.
        if ($current !== null && ($ts <= $current['last_ts'] || $ts - $current['last_ts'] > self::VISIT_GAP_SECONDS)) {
            $result['visits'][] = self::closeVisit($current, $timezone);
            $current = null;
        }

        if ($current === null) {
            $current = [
                'day'        => $day,
                'ip'         => $request['ip'],
                'started_ts' => $ts,
                'last_ts'    => $ts,
                'pages'      => 0,
                'bytes'      => 0,
                'entry_url'  => $kind === 'page' ? $path : '',
                'exit_url'   => $kind === 'page' ? $path : '',
                'referrer'   => $referrer,
                'is_bot'     => $isBot,
                'agent'      => $agent,
            ];
        }

        $current['last_ts'] = $ts;
        $current['bytes'] += $bytes;

        if ($kind === 'page') {
            $current['pages']++;
            $current['exit_url'] = $path;
            if ($current['entry_url'] === '') {
                $current['entry_url'] = $path;
                // A session whose first page was an asset had no referrer yet;
                // take it from the page that actually opened the session.
                if ($current['referrer'] === '') {
                    $current['referrer'] = $referrer;
                }
            }
        }

        $open[$request['ip']] = $current;
    }

    /**
     * Turn the raw accumulators into the result shape: sets folded to counts,
     * session rollups added to the day rows, per-page unique visitor counts,
     * and the referrer keys flattened.
     *
     * $visits is passed in rather than closed here so streamByDay() can leave a
     * session open across a day boundary and still attribute it to the day it
     * started on. The aggregator recomputes these rollups from the visit table
     * anyway, so a session that straddles midnight is counted once, correctly.
     */
    private static function foldDay(array $result, array $visits, int $lines): array
    {
        // Unique visitors per day.
        foreach ($result['days'] as $day => &$row) {
            $row['uniq_ips']       = count($row['ips'] ?? []);
            $row['human_uniq_ips'] = count($row['human_ips'] ?? []);
            $row['visits']         = 0;
            $row['visit_pages']    = 0;
            $row['visit_duration'] = 0;
            unset($row['ips'], $row['human_ips']);
        }
        unset($row);

        foreach ($result['hours'] as $day => $rows) {
            foreach ($rows as $hour => $row) {
                $result['hours'][$day][$hour]['uniq_ips'] = count($row['ips']);
                unset($result['hours'][$day][$hour]['ips']);
            }
        }

        // Unique human IPs per page path, for the top-pages table.
        foreach ($result['urls'] as $day => $rows) {
            foreach ($rows as $url => $row) {
                $result['urls'][$day][$url]['uniq_ips'] = count($row['ips']);
                unset($result['urls'][$day][$url]['ips']);
            }
        }

        // Sessions roll into the day they started on. Bots get no session
        // counts: a crawler does not browse.
        foreach ($visits as $visit) {
            if ($visit['is_bot'] === 1 || !isset($result['days'][$visit['day']])) {
                continue;
            }
            $result['days'][$visit['day']]['visits']++;
            $result['days'][$visit['day']]['visit_pages'] += $visit['pages'];
            $result['days'][$visit['day']]['visit_duration'] += $visit['duration_sec'];

            // The session belongs on the human row of that address.
            $key = $visit['ip'] . '|0';
            if (isset($result['ips'][$visit['day']][$key])) {
                $result['ips'][$visit['day']][$key]['visits']++;
            }
        }

        // Referrer keys were counted as "host|human" / "host|bot" internally;
        // flatten them into one row per host with a separate bot column.
        foreach ($result['referrers'] as $day => $rows) {
            $flat = [];
            foreach ($rows as $key => $hits) {
                [$host, $who]         = array_pad(explode('|', $key, 2), 2, 'human');
                $field                = $who === 'bot' ? 'bot_hits' : 'hits';
                $flat[$host][$field] = ($flat[$host][$field] ?? 0) + $hits;
            }
            $result['referrers'][$day] = $flat;
        }

        $result['visits']  = array_values($visits);
        $result['lines']   = $lines;
        $result['skipped'] = $result['skipped'] ?? 0;

        return $result;
    }

    /**
     * Close every still-open visit into finished rows.
     */
    private static function closeOpenVisits(array &$open, \DateTimeZone $timezone): array
    {
        $visits = [];
        foreach ($open as $visit) {
            $visits[] = self::closeVisit($visit, $timezone);
        }

        return $visits;
    }

    /**
     * Parse an ordered stream of log lines into per-day aggregates.
     *
     * Holds every day in memory, so prefer streamByDay() for real logs; this
     * entry point is for tests and small windows.
     *
     * @param iterable<string> $lines
     * @param array{base_path?:string, site_host?:string, include_private?:bool, timezone?:string} $options
     * @return array<string, mixed> days/hours/urls/agents/referrers/status/filetypes/ips/visits/lines/skipped
     */
    public static function parse(iterable $lines, array $options = []): array
    {
        $basePath  = self::normalizeBasePath($options['base_path'] ?? '');
        $selfHost  = strtolower(trim((string) ($options['site_host'] ?? '')));
        $keepLocal = !empty($options['include_private']);
        $timezone  = new \DateTimeZone((string) ($options['timezone'] ?? 'UTC'));
        $result    = self::emptyResult();
        $open      = [];
        $linesSeen = 0;

        foreach ($lines as $line) {
            $line = rtrim((string) $line, "\r\n");
            // Blank and '#' lines are ignored rather than counted as skipped,
            // so a fixture file can be annotated.
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $linesSeen++;
            $request = self::parseLine($line);
            if ($request === null) {
                $result['skipped']++;
                continue;
            }
            // Private/loopback addresses are the site's own cron, health and
            // admin traffic, which would otherwise read as a busy visitor.
            // include_private opts back in for a LAN/staging box whose real
            // visitors all arrive from 192.168.x.
            if ($request['ip'] === '' || (!$keepLocal && self::isPrivateIp($request['ip']))) {
                continue;
            }

            self::feed($result, $open, $request, $basePath, $selfHost, $timezone);
        }

        // Sessions closed while feeding, plus anything still open at the end.
        $visits = array_merge($result['visits'], self::closeOpenVisits($open, $timezone));

        return self::foldDay($result, $visits, $linesSeen);
    }

    // ------------------------------------------------------------------
    // Streaming (bounded memory)
    // ------------------------------------------------------------------

    /**
     * Parse a chronological stream one day at a time, calling $onDay for each.
     *
     * A backfill reads every rotated log (hundreds of MB), so holding all days
     * at once would be wasteful. This walks the lines, watches the day change,
     * and hands each finished day to the callback and then drops it: peak memory
     * is one day of aggregates plus the open-visit table, which keeps carrying
     * across midnight so somebody browsing past the date boundary stays one
     * continuous session.
     *
     * The open-visit table is why the rollup numbers in the callback are not
     * authoritative for sessions: a session that straddles midnight is only
     * closed once its last line arrives. App\Core\AccessLogAggregator therefore
     * recomputes visits/visit_pages/visit_duration from the stored visit rows,
     * which is exact regardless of where a session was split.
     *
     * @param callable(array, string): void $onDay receives (folded day, day)
     * @return array{lines:int, skipped:int, days:int} totals for the whole stream
     */
    public static function streamByDay(iterable $lines, array $options, callable $onDay): array
    {
        $basePath = self::normalizeBasePath($options['base_path'] ?? '');
        $selfHost = strtolower(trim((string) ($options['site_host'] ?? '')));
        $keepLocal = !empty($options['include_private']);
        $timezone = new \DateTimeZone((string) ($options['timezone'] ?? 'UTC'));

        $day      = null;
        $result   = null;
        $dayLines = 0;
        $open     = [];
        $totals   = ['lines' => 0, 'skipped' => 0, 'days' => 0];

        // $open is deliberately NOT captured: sessions that are still running
        // at a day boundary must stay open across the flush, which is why only
        // the sessions closed inside this day are folded into it.
        $flush = static function () use (&$day, &$result, &$dayLines, $onDay, &$totals): void {
            if ($result === null || $day === null) {
                return;
            }
            // Sessions closed while this day was being read. Anything still open
            // stays open and will be closed by a later day.
            $onDay(self::foldDay($result, $result['visits'], $dayLines), $day);
            $totals['days']++;
            $result   = null;
            $dayLines = 0;
        };

        foreach ($lines as $line) {
            $line = rtrim((string) $line, "\r\n");
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $totals['lines']++;
            $request = self::parseLine($line);

            if ($request === null) {
                // Unparseable: charge it to the day being accumulated so the
                // skipped count surfaces on the right row instead of vanishing.
                $totals['skipped']++;
                if ($result !== null) {
                    $result['skipped']++;
                }
                continue;
            }

            if ($request['ip'] === '' || (!$keepLocal && self::isPrivateIp($request['ip']))) {
                continue;
            }

            $thisDay = $request['time']->setTimezone($timezone)->format('Y-m-d');

            if ($day !== null && $thisDay !== $day) {
                $flush();
            }
            if ($result === null) {
                $day    = $thisDay;
                $result = self::emptyResult();
            }
            $dayLines++;

            self::feed($result, $open, $request, $basePath, $selfHost, $timezone);
        }

        // End of stream. The last day is emitted once, with any session that was
        // still open folded in, so the final visit of the day is not lost.
        if ($result !== null && $day !== null) {
            $result['visits'] = array_merge($result['visits'], self::closeOpenVisits($open, $timezone));
            $onDay(self::foldDay($result, $result['visits'], $dayLines), $day);
            $totals['days']++;
        }

        return $totals;
    }

    /** Normalise a configured base path to "/gallery" or "" (never a trailing slash). */
    /**
     * Coerce a log string to valid UTF-8.
     *
     * Apache logs the request target as raw bytes, and scanners send invalid
     * sequences on purpose (overlong slashes, lone continuation bytes). MySQL
     * refuses those outright with "Incorrect string value", which would abort a
     * whole day, so the bad bytes become U+FFFD here and the request still
     * counts.
     */
    public static function sanitize(string $value): string
    {
        if ($value === '' || mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        $previous = mb_substitute_character();
        mb_substitute_character(0xFFFD);
        $clean = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        mb_substitute_character($previous);

        return is_string($clean) ? $clean : '';
    }

    private static function normalizeBasePath($basePath): string
    {
        $basePath = trim((string) $basePath);

        return $basePath === '' ? '' : '/' . trim($basePath, '/');
    }

    /**
     * Split one combined-format line, or null when it is not parseable.
     *
     * Tolerant on purpose: the user agent and referrer may be empty or
     * malformed, the byte count may be "-", and the request may be absent.
     *
     * @return array{ip:string, time:\DateTime, method:string, target:string,
     *               status:string, bytes:int, referrer:string, agent:string}|null
     */
    public static function parseLine(string $line): ?array
    {
        $pattern = '/^(\S+)\s+\S+\s+\S+\s+\[([^\]]+)\]\s+"(.*?)"\s+(\d{3})\s+(\S+)\s+"(.*?)"\s+"(.*?)"\s*$/';

        if (preg_match($pattern, $line, $m) !== 1) {
            return null;
        }

        $time = \DateTime::createFromFormat('d/M/Y:H:i:s O', trim($m[2]));
        if ($time === false) {
            return null;
        }

        $request = $m[3] === '-' ? '' : trim($m[3]);
        if ($request === '') {
            return null;
        }

        $parts    = preg_split('/\s+/', $request) ?: [];
        $method   = strtoupper($parts[0] ?? 'GET');
        $target   = (string) ($parts[1] ?? '');
        $bytesRaw = $m[5];

        if ($target === '' || $target === '-') {
            return null;
        }

        return [
            'ip'       => self::sanitize($m[1]),
            'time'     => $time,
            'method'   => $method,
            'target'   => self::sanitize($target),
            'status'   => $m[4],
            'bytes'    => $bytesRaw === '-' ? 0 : (int) $bytesRaw,
            'referrer' => self::sanitize($m[6]),
            'agent'    => self::sanitize($m[7]),
        ];
    }

    /**
     * The timestamp of a log line, or null when the line is not a request.
     *
     * Only the bracket group is read, so ordering the files costs one cheap scan
     * instead of a full parse. The pattern is deliberately the same one
     * parseLine() uses, so a line it can date is a line it can parse.
     */
    public static function lineTimestamp(string $line): ?int
    {
        if (preg_match('/^\S+\s+\S+\s+\S+\s+\[([^\]]+)\]/', $line, $m) !== 1) {
            return null;
        }

        $time = \DateTime::createFromFormat('d/M/Y:H:i:s O', trim($m[1]));

        return $time === false ? null : $time->getTimestamp();
    }

    /**
     * browser / os / device / is_bot for a user agent.
     *
     * @return array{browser:string, os:string, device:string, is_bot:int, bot_name:string}
     */
    public static function classifyAgent(string $agent): array
    {
        $agent = trim($agent);
        $isBot = $agent === '' || $agent === '-' || self::isBotUserAgent($agent);

        if ($isBot) {
            return [
                'browser'  => self::botName($agent),
                'os'       => '-',
                'device'   => 'bot',
                'is_bot'   => 1,
                'bot_name' => self::botName($agent),
            ];
        }

        $browser = 'Other';
        foreach (self::BROWSERS as $name => $pattern) {
            if (preg_match($pattern, $agent) === 1) {
                $browser = $name;
                break;
            }
        }

        $os = 'Other';
        foreach (self::OPERATING_SYSTEMS as $name => $pattern) {
            if (preg_match($pattern, $agent) === 1) {
                $os = $name;
                break;
            }
        }

        $device = 'desktop';
        if (preg_match('/Mobile|Android|iPhone|iPod|Windows Phone|IEMobile|Silk/i', $agent) === 1) {
            $device = 'mobile';
        } elseif (preg_match('/iPad|Tablet|PlayBook|Silk/i', $agent) === 1) {
            $device = 'tablet';
        }

        return [
            'browser'  => self::clip($browser),
            'os'       => self::clip($os),
            'device'   => $device,
            'is_bot'   => 0,
            'bot_name' => '',
        ];
    }

    public static function isBotUserAgent(string $agent): bool
    {
        $agent = strtolower($agent);
        if ($agent === '' || $agent === '-') {
            return true;
        }
        foreach (self::BOT_TOKENS as $token) {
            if (str_contains($agent, strtolower($token))) {
                return true;
            }
        }
        return false;
    }

    /** The best display name for a robot, falling back to a generic "bot". */
    public static function botName(string $agent): string
    {
        foreach (self::BOT_NAMES as $name => $pattern) {
            if (preg_match($pattern, $agent) === 1) {
                return self::clip($name);
            }
        }
        return 'bot';
    }

    /**
     * Strip the query string and the app's base path so a thumbnail is not a
     * new "page" on every single request.
     */
    public static function normalizeUrl(string $target, string $basePath = ''): string
    {
        $path = parse_url($target, PHP_URL_PATH);
        $path = is_string($path) ? $path : '/';
        $path = rawurldecode($path);

        // The base path is only a prefix when it ends on a segment boundary:
        // "/galleries" must NOT lose its "gallery" prefix just because the app
        // happens to live under /gallery.
        if ($basePath !== '' && str_starts_with($path, $basePath)) {
            $rest = substr($path, strlen($basePath));
            if ($rest === '' || str_starts_with($rest, '/') || str_starts_with($rest, '?')) {
                $path = $rest === '' ? '/' : $rest;
            }
        }

        if ($path === '' || $path === '/') {
            return '/';
        }

        // Collapse duplicate slashes and drop a trailing slash, but keep "/".
        $path = preg_replace('#/+#', '/', $path) ?? $path;
        if (strlen($path) > 1 && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
            if ($path === '') {
                $path = '/';
            }
        }

        // Sanitize AFTER decoding: percent-escapes such as %C0%A0 turn back
        // into raw invalid bytes here, so cleaning the request line alone would
        // still leave an unstorable path.
        return self::clip(self::sanitize($path), self::MAX_URL_LENGTH);
    }

    /** page | asset | api - what a request target actually is. */
    public static function classifyPath(string $target, ?string $normalized = null): string
    {
        $path = $normalized ?? self::normalizeUrl($target);

        foreach (self::ASSET_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return 'asset';
            }
        }

        foreach (self::API_PREFIXES as $prefix) {
            if ($path === rtrim($prefix, '/') || str_starts_with($path, $prefix)) {
                return 'api';
            }
        }

        $ext = self::extensionOf($target);
        if ($ext !== '' && in_array($ext, self::ASSET_EXT, true)) {
            return 'asset';
        }

        return 'page';
    }

    /**
     * Hostname of a referrer, or '' for direct traffic.
     *
     * $selfHost is the site's own hostname: a referrer pointing back at the
     * site (a crawler following an internal link, an in-site search result) is
     * NOT a traffic source, so it collapses into the direct bucket instead of
     * claiming the site's own name as a referrer.
     */
    public static function referrerHost(string $referrer, string $selfHost = ''): string
    {
        $referrer = trim($referrer);
        if ($referrer === '' || $referrer === '-') {
            return '';
        }
        $host = parse_url($referrer, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return '';
        }

        $host = strtolower($host);
        if ($selfHost !== '' && self::sameHost($host, $selfHost)) {
            return '';
        }

        return self::clip($host, 190);
    }

    /** Host comparison that ignores a leading "www." and a trailing dot. */
    private static function sameHost(string $a, string $b): bool
    {
        $strip = static fn (string $host): string => preg_replace('#^www\.#', '', rtrim(strtolower(trim($host)), '.')) ?? $host;

        return $strip($a) !== '' && $strip($a) === $strip($b);
    }

    public static function extensionOf(string $target): string
    {
        $path = parse_url($target, PHP_URL_PATH);
        if (!is_string($path)) {
            return '';
        }
        $dot = strrpos($path, '.');
        if ($dot === false) {
            return '';
        }
        $ext = strtolower(substr($path, $dot + 1));
        if ($ext === '' || strlen($ext) > 12 || preg_match('/^[a-z0-9]+$/', $ext) !== 1) {
            return '';
        }
        return $ext;
    }

    /** Loopback and RFC1918 / link-local space: the site's own traffic. */
    public static function isPrivateIp(string $ip): bool
    {
        if ($ip === '') {
            return true;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return !filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $packed = @inet_pton($ip);
            if ($packed === false) {
                return true;
            }
            if ($packed === str_repeat("\0", 15) . "\1") { // ::1
                return true;
            }
            $first = ord($packed[0]);
            if (($first & 0xFE) === 0xFC) {   // fc00::/7
                return true;
            }
            if ($first === 0xFE && (ord($packed[1]) & 0xC0) === 0x80) { // fe80::/10
                return true;
            }
            return false;
        }

        return true;
    }

    private static function closeVisit(array $open, \DateTimeZone $timezone): array
    {
        $duration = min(self::MAX_VISIT_SECONDS, max(0, $open['last_ts'] - $open['started_ts']));
        $agent    = $open['agent'];

        // Stored in the site's timezone, not UTC: `day` buckets are local, so a
        // row whose day says 2026-10-05 must not carry a timestamp from the
        // evening of 2026-10-04 UTC.
        return [
            'day'         => $open['day'],
            'ip'          => $open['ip'],
            'started_at'  => (new \DateTimeImmutable('@' . $open['started_ts']))->setTimezone($timezone)->format('Y-m-d H:i:s'),
            'last_seen'   => (new \DateTimeImmutable('@' . $open['last_ts']))->setTimezone($timezone)->format('Y-m-d H:i:s'),
            'duration_sec'=> (int) $duration,
            'pages'       => min(self::MAX_VISIT_PAGES, (int) $open['pages']),
            'bytes'       => (int) $open['bytes'],
            'entry_url'   => self::clip((string) $open['entry_url']),
            'exit_url'    => self::clip((string) $open['exit_url']),
            'referrer'    => (string) $open['referrer'],
            'browser'     => $agent['browser'],
            'os'          => $agent['os'],
            'device'      => $agent['device'],
            'is_bot'      => (int) $open['is_bot'],
        ];
    }

    private static function touchDay(array &$rows, string $day): void
    {
        if (isset($rows[$day])) {
            return;
        }

        $rows[$day] = [
            'hits'           => 0,
            'human_hits'     => 0,
            'bot_hits'       => 0,
            'page_views'     => 0,
            'bot_page_views' => 0,
            'asset_hits'     => 0,
            'api_hits'       => 0,
            'bytes'          => 0,
            'status_4xx'     => 0,
            'status_5xx'     => 0,
            'uniq_ips'       => 0,
            'human_uniq_ips' => 0,
            'visits'         => 0,
            'visit_pages'    => 0,
            'visit_duration' => 0,
            'ips'            => [],
            'human_ips'      => [],
        ];
    }

    /**
     * Clip to a column's byte budget, marked with a tilde.
     *
     * The cut is made on a character boundary: a plain substr() can slice a
     * multibyte character in half, and the leftover half is invalid UTF-8 that
     * MySQL refuses to store.
     */
    private static function clip(string $value, int $length = self::MAX_AGENT_LENGTH): string
    {
        if (strlen($value) <= $length) {
            return $value;
        }

        $cut = substr($value, 0, $length - 1);

        // Drop a trailing partial character (at most 3 stray continuation bytes).
        for ($i = 0; $i < 3 && $cut !== ''; $i++) {
            $trimmed = self::sanitize($cut);
            if ($trimmed === $cut) {
                break;
            }
            $cut = substr($cut, 0, -1);
        }

        return $cut . '~';
    }
}