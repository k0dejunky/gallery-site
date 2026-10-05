<?php

namespace App\Controllers;

use App\Core\AccessLogAggregator;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Models\WebStats;

/**
 * Admin web analytics, read from the Apache access logs that
 * AccessLogAggregator folded into the web_stats_* tables.
 *
 * All queries are range-limited through WebStats, which is the only place that
 * knows the include-bots rule: robots are stored beside human traffic and hidden
 * by default, and the ?bots=1 switch re-adds them everywhere at once.
 *
 * The page is read-only apart from two actions: a manual re-parse (which
 * re-reads the log files on disk) and a CSV export of the daily rows.
 */
class AnalyticsController extends Controller
{
    /**
     * Rows shown in each dimension table before it is cut off.
     */
    private const TABLE_LIMIT = 25;

    /**
     * Longest re-parse window an admin may ask for in one request, so a
     * mistyped date cannot tie up PHP for an hour.
     */
    private const REPARSE_MAX_DAYS = 31;

    public function __construct(Request $request)
    {
        parent::__construct($request);
        Auth::requirePermission('analytics');
    }

    /**
     * The analytics dashboard for the selected range.
     */
    public function index(): void
    {
        $timezone   = site_timezone();
        $range      = WebStats::normalizeRange((string) $this->request->query('range', '30days'));
        $includeBots = $this->request->query('bots') === '1';

        [$from, $to] = WebStats::resolveRange($range, $timezone);
        $health      = WebStats::health();

        $this->viewAdmin('web-stats', [
            'timezoneName' => $timezone,
            'ranges'       => WebStats::ranges(),
            'currentRange' => $range,
            'includeBots'  => $includeBots,
            'from'         => $from,
            'to'           => $to,
            'isAllTime'    => WebStats::isAllTime($range),
            'hasData'      => $health['has_data'],
            'health'       => $health,
            'summary'      => WebStats::summary($from, $to, $includeBots),
            'series'       => WebStats::dailySeries($from, $to, $includeBots),
            'hourly'       => WebStats::hourlyProfile($from, $to, $includeBots),
            'mix'          => WebStats::requestMix($from, $to, $includeBots),
            'topUrls'      => WebStats::topUrls($from, $to, self::TABLE_LIMIT, $includeBots),
            'entryPages'   => WebStats::entryPages($from, $to, self::TABLE_LIMIT),
            'exitPages'    => WebStats::exitPages($from, $to, self::TABLE_LIMIT),
            'referrers'    => WebStats::referrers($from, $to, self::TABLE_LIMIT, $includeBots),
            'agents'       => WebStats::agentBreakdown($from, $to, $includeBots),
            'statuses'     => WebStats::statuses($from, $to),
            'fileTypes'    => WebStats::fileTypes($from, $to),
            'visitors'     => WebStats::topVisitors($from, $to, self::TABLE_LIMIT, $includeBots),
            'quality'      => WebStats::visitQuality($from, $to),
            'robots'       => WebStats::robots($from, $to, self::TABLE_LIMIT),
            'tableLimit'   => self::TABLE_LIMIT,
        ]);
    }

    /**
     * Re-read the access logs for a window and rewrite those days.
     *
     * Runs inline (the button is right there and the result is the point), with
     * a file lock so it cannot collide with the hourly cron. An empty window
     * falls back to the last few days.
     */
    public function reparse(): void
    {
        $timezone = site_timezone();
        [$from, $to] = WebStats::clampDates(
            (string) $this->request->post('from', ''),
            (string) $this->request->post('to', ''),
            $timezone
        );

        $days = (int) ((new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days) + 1;
        if ($days > self::REPARSE_MAX_DAYS) {
            $to = (new \DateTimeImmutable($from))->modify('+' . (self::REPARSE_MAX_DAYS - 1) . ' days')->format('Y-m-d');
            $days = self::REPARSE_MAX_DAYS;
        }

        $summary = AccessLogAggregator::run(['from' => $from, 'to' => $to]);

        if (!$summary['ok']) {
            $this->flash('error', 'Re-parse failed: ' . (string) $summary['error']);
        } elseif ($summary['days_written'] === 0) {
            $this->flash(
                'error',
                'No log lines were found for ' . $from . ' to ' . $to
                . '. The rotated logs only go back so far.'
            );
        } else {
            $this->flash(
                'success',
                sprintf(
                    'Re-parsed %d day%s (%s to %s): %s lines, %d visits, %d URLs in %ss.',
                    $summary['days_written'],
                    $summary['days_written'] === 1 ? '' : 's',
                    $from,
                    $to,
                    number_format((int) $summary['lines']),
                    (int) $summary['visits'],
                    (int) $summary['urls'],
                    (string) $summary['duration_sec']
                )
                . ((int) $summary['skipped_lines'] > 0
                    ? ' ' . number_format((int) $summary['skipped_lines']) . ' lines could not be parsed.'
                    : '')
            );
        }

        $this->redirect('/admin/analytics?range=' . rawurlencode((string) $this->request->post('range', '30days'))
            . ($this->request->post('bots') === '1' ? '&bots=1' : ''));
    }

    /**
     * CSV of the daily rollup for the selected window, streamed as a download.
     *
     * Bots are always included: an export is the archival copy, and the panel
     * toggle is a display concern.
     */
    public function export(): void
    {
        $timezone = site_timezone();
        $range    = WebStats::normalizeRange((string) $this->request->query('range', '30days'));
        $fromQ    = (string) ($this->request->query('from') ?? '');
        $toQ      = (string) ($this->request->query('to') ?? '');
        [$from, $to] = WebStats::clampDates($fromQ, $toQ, $timezone);

        if ($fromQ === '' && $toQ === '') {
            // No explicit window: use the same range the page is showing.
            [$from, $to] = WebStats::resolveRange($range, $timezone);
        }

        $rows = WebStats::exportRows($from, $to);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="web-stats-' . $from . '-to-' . $to . '.csv"');
        header('Cache-Control: no-store');

        $out = fopen('php://output', 'wb');

        if ($rows === []) {
            fputcsv($out, ['No analytics data stored for ' . $from . ' to ' . $to]);
            fclose($out);
            exit;
        }

        fputcsv($out, array_keys($rows[0]));

        foreach ($rows as $row) {
            fputcsv($out, array_values($row));
        }

        fclose($out);
        exit;
    }
}