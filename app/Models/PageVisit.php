<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Unique-IP traffic to the public login and signup pages, feeding the admin
 * dashboard's view-trends section. One row per page + IP + calendar day; the
 * INSERT IGNORE means an IP that revisits the same form on the same day never
 * creates a second row, so COUNT(DISTINCT ip) over any window stays accurate
 * and the series can be charted per day (or per month for longer periods).
 */
class PageVisit
{
    private const PAGES = ['login' => true, 'signup' => true];

    private const PERIODS = [
        'day'   => ['days' => 1, 'gran' => 'day'],
        'week'  => ['days' => 7, 'gran' => 'day'],
        'month' => ['days' => 30, 'gran' => 'day'],
        'year'  => ['days' => null, 'gran' => 'month'],
        'all'   => ['days' => null, 'gran' => 'month'],
    ];

    /**
     * Record one unique-IP visit today to a tracked form page. Insert-ignore
     * semantics: a repeat render from the same IP on the same day adds no row.
     */
    public static function record(string $page, string $ip): void
    {
        $page = strtolower($page);
        if (!isset(self::PAGES[$page]) || $ip === '' || $ip === '0.0.0.0') {
            return;
        }

        Database::run(
            'INSERT IGNORE INTO page_ip_visits (page, ip, visit_date)
             VALUES (?, ?, CURDATE())',
            [$page, mb_substr($ip, 0, 45)]
        );
    }

    /**
     * Unique-IP series for the login and signup pages over the selected
     * period, plus the whole-window unique-IP totals. Short periods use daily
     * buckets; year and all time are bucketed by calendar month so the chart
     * never collapses. Bucketing happens in SQL (COUNT DISTINCT per bucket),
     * because summing distinct-per-day counts would over-count IPs that stayed
     * active across several days within one month.
     *
     * @return array{labels: array<int,string>, login: array<int,int>, signup: array<int,int>, total: array<int,int>, unique_login: int, unique_signup: int, granularity: string, period: string, first_visit: string}
     */
    public static function uniqueTrends(string $period): array
    {
        $period = strtolower($period);
        $cfg    = self::PERIODS[$period] ?? self::PERIODS['month'];

        [$where, $params] = self::since($cfg['days']);

        $group = $cfg['gran'] === 'month' ? "DATE_FORMAT(visit_date, '%Y-%m')" : 'visit_date';
        $rows  = Database::run(
            "SELECT page, {$group} AS k, COUNT(DISTINCT ip) AS n
             FROM page_ip_visits
             {$where}
             GROUP BY page, k
             ORDER BY k ASC",
            $params
        )->fetchAll();

        $perPage = ['login' => [], 'signup' => []];
        foreach ($rows as $row) {
            $page = (string) $row['page'];
            if (isset($perPage[$page])) {
                $perPage[$page][(string) $row['k']] = (int) $row['n'];
            }
        }

        $keys   = self::axisKeys($cfg['gran'], $period, $perPage);
        $labels = [];
        $login  = [];
        $signup = [];
        $total  = [];
        foreach ($keys as $key) {
            $l = $perPage['login'][$key] ?? 0;
            $s = $perPage['signup'][$key] ?? 0;
            $labels[] = $cfg['gran'] === 'month' ? date('M y', strtotime($key . '-01')) : date('n/j', strtotime($key));
            $login[]  = $l;
            $signup[] = $s;
            $total[]  = $l + $s;
        }

        $whole = self::uniqueTotals($where, $params);

        return [
            'labels'        => $labels,
            'login'         => $login,
            'signup'        => $signup,
            'total'         => $total,
            'unique_login'  => $whole['login'],
            'unique_signup' => $whole['signup'],
            'granularity'   => $cfg['gran'],
            'period'        => $period,
            'first_visit'   => (string) (Database::run('SELECT MIN(visit_date) AS d FROM page_ip_visits')->fetch()['d'] ?? ''),
        ];
    }

    /** WHERE clause + params limiting a query to a trailing window of days. */
    private static function since(?int $days): array
    {
        if ($days === null) {
            return ['', []];
        }

        return ['WHERE visit_date >= ?', [date('Y-m-d', time() - $days * 86400)]];
    }

    /**
     * Continuous axis of bucket keys for the selected granularity: the last
     * N days, or a run of calendar months (11 trailing months for "year",
     * the recorded history for "all time"). Every bucket is always present so
     * sparse rows draw a proper zero-filled baseline.
     *
     * @param array{login: array<string,int>, signup: array<string,int>} $perPage
     *
     * @return array<int, string>
     */
    private static function axisKeys(string $gran, string $period, array $perPage): array
    {
        if ($gran === 'day') {
            $days  = self::PERIODS[$period]['days'] ?? 1;
            $out   = [];
            for ($i = $days - 1; $i >= 0; $i--) {
                $out[] = date('Y-m-d', time() - $i * 86400);
            }

            return $out;
        }

        $known = array_keys($perPage['login'] + $perPage['signup']);
        if ($period === 'all') {
            $start = $known !== [] ? min($known) : date('Y-m');
        } else {
            $start = date('Y-m', mktime(0, 0, 0, (int) date('n') - 11, 1));
        }

        $out   = [];
        $ts    = mktime(0, 0, 0, (int) substr($start, 5, 2), 1, (int) substr($start, 0, 4));
        $until = time();
        $guard = 0;
        while ($ts <= $until && $guard < 1200) {
            $out[] = date('Y-m', $ts);
            $ts    = strtotime('+1 month', $ts);
            $guard++;
        }

        $lastMonth = end($out) ?: date('Y-m');
        if ($lastMonth !== date('Y-m')) {
            $out[] = date('Y-m');
        }

        return $out;
    }

    /** Distinct-IP counts over the whole selected window, per page. */
    private static function uniqueTotals(string $where, array $params): array
    {
        $rows = Database::run(
            "SELECT page, COUNT(DISTINCT ip) AS n
             FROM page_ip_visits
             {$where}
             GROUP BY page",
            $params
        )->fetchAll();

        $out = ['login' => 0, 'signup' => 0];
        foreach ($rows as $row) {
            $page = (string) $row['page'];
            if (isset($out[$page])) {
                $out[$page] = (int) $row['n'];
            }
        }

        return $out;
    }
}