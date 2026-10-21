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
     * Start a re-parse in the BACKGROUND and return whether it was queued.
     *
     * The re-parse used to run inside the HTTP request: up to 31 days of
     * the access log, about five minutes of work, holding one FPM worker
     * for the whole of it while max_execution_time is an hour. It also had
     * no lock of its own, so it could run at the same time as the hourly
     * cron.
     */
    private static function queueReparse(string $from, string $to): bool
    {
        $lockDir = dirname(__DIR__, 2) . '/storage/logs';
        if (!is_dir($lockDir)) {
            @mkdir($lockDir, 0775, true);
        }

        $lockFile = $lockDir . '/reparse.lock';
        $lock     = @fopen($lockFile, 'c');

        if ($lock === false) {
            return false;
        }

        // One re-parse at a time, and never alongside another one.
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return false;
        }

        $cmd = sprintf(
            '%s %s --from=%s --to=%s >> %s 2>&1 &',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(dirname(__DIR__, 2) . '/bin/aggregate_access_log.php'),
            escapeshellarg($from),
            escapeshellarg($to),
            escapeshellarg($lockDir . '/reparse.log')
        );

        exec($cmd);
        flock($lock, LOCK_UN);
        fclose($lock);

        return true;
    }

    /**
     * Re-read the access logs for a window and rewrite those days.
     *
     * Runs in the BACKGROUND: the button is right there, but the work is
     * five minutes of log parsing and it does not belong in a request. An
     * empty window falls back to the last few days.
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

        // The re-parse used to run INSIDE this HTTP request: up to 31 days
        // of the access log, about five minutes of work, holding one FPM
        // worker for the whole of it while max_execution_time is an hour.
        // It is a background job now, with a lock of its own so it cannot
        // collide with the hourly cron, and the page says so.
        $queued = self::queueReparse($from, $to);

        if ($queued === false) {
            $this->flash('error', 'A re-parse is already running. Wait for it to finish.');
            $this->redirect('/admin/analytics?range=' . rawurlencode((string) $this->request->post('range', '30days')));
            return;
        }

        $this->flash(
            'success',
            sprintf(
                'The re-parse of %s to %s is running in the background. It takes a few minutes; refresh in a moment to see it.',
                $from,
                $to
            )
        );

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