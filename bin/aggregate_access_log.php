<?php

declare(strict_types=1);

/**
 * Aggregate the Apache access logs into the web_stats_* tables.
 *
 * Runs hourly from cron (via /etc/cron.d/gallery-access-stats) and on demand
 * from the admin Analytics page. Both paths are the same code, so a manual
 * re-parse and the scheduled refresh can never disagree about how a day is
 * counted.
 *
 * Usage:
 *   php /var/www/gallery/bin/aggregate_access_log.php [options]
 *
 *   --days=N          refresh the last N days (default 2: yesterday is still
 *                     settling after a rotation, today is still being written)
 *   --from=YYYY-MM-DD  explicit window start (overrides --days)
 *   --to=YYYY-MM-DD    explicit window end
 *   --files=a.log,b.gz  read these files instead of the configured globs
 *   --include-private also count private/loopback source addresses (staging
 *                     boxes whose visitors arrive from 192.168.x; the site's own
 *                     cron/health traffic comes from the same range)
 *   --dry-run         parse and count, write nothing
 *   --quiet           print nothing on success
 *
 * Exit codes: 0 success (including "another run is in progress"), 1 failure.
 *
 * A lock file prevents overlapping runs: a long backfill and the hourly cron
 * must not fight over the same days. Overlapping runs are not an error, so a
 * cron tick during a manual re-parse exits 0 quietly.
 */

require __DIR__ . '/../app/bootstrap.php';

use App\Core\AccessLogAggregator;

$options = ['days' => 2, 'dry_run' => false, 'quiet' => false, 'include_private' => false];

foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--days=(\d+)$/', $arg, $m)) {
        $options['days'] = max(1, min(366, (int) $m[1]));
    } elseif (preg_match('/^--from=(\d{4}-\d{2}-\d{2})$/', $arg, $m)) {
        $options['from'] = $m[1];
    } elseif (preg_match('/^--to=(\d{4}-\d{2}-\d{2})$/', $arg, $m)) {
        $options['to'] = $m[1];
    } elseif (preg_match('/^--files=(.+)$/', $arg, $m)) {
        $options['patterns'] = array_values(array_filter(array_map('trim', explode(',', $m[1]))));
    } elseif ($arg === '--include-private') {
        $options['include_private'] = true;
    } elseif ($arg === '--dry-run') {
        $options['dry_run'] = true;
    } elseif ($arg === '--quiet') {
        $options['quiet'] = true;
    } elseif ($arg === '--help' || $arg === '-h') {
        echo "Usage: php bin/aggregate_access_log.php [--days=N] [--from=YYYY-MM-DD] [--to=YYYY-MM-DD]"
            . " [--files=a.log,b.gz] [--include-private] [--dry-run] [--quiet]\n";
        exit(0);
    } else {
        fwrite(STDERR, "aggregate_access_log: unknown option {$arg}\n");
        exit(1);
    }
}

$logDir = dirname(__DIR__) . '/storage/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}

$lockFp = @fopen($logDir . '/access-stats.lock', 'c');
if ($lockFp === false) {
    fwrite(STDERR, "aggregate_access_log: cannot open lock file\n");
    exit(1);
}

if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    // A manual backfill is already working through the same days.
    fwrite(STDERR, "aggregate_access_log: another run is in progress\n");
    exit(0);
}

try {
    $summary = AccessLogAggregator::run($options);
} catch (Throwable $e) {
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
    fwrite(STDERR, 'aggregate_access_log: ' . $e->getMessage() . "\n");
    @file_put_contents($logDir . '/access-stats.log', date('c') . ' ERROR ' . $e->getMessage() . "\n", FILE_APPEND);
    exit(1);
}

flock($lockFp, LOCK_UN);
fclose($lockFp);

$line = sprintf(
    '%s | access_stats days=%d skipped_days=%d lines=%d skipped_lines=%d visits=%d urls=%d window=%s..%s%s%s in %ss',
    date('c'),
    (int) $summary['days_written'],
    (int) $summary['days_skipped'],
    (int) $summary['lines'],
    (int) $summary['skipped_lines'],
    (int) $summary['visits'],
    (int) $summary['urls'],
    (string) $summary['from'],
    (string) $summary['to'],
    $summary['dry_run'] ? ' DRY-RUN' : '',
    ($summary['error'] ?? null) ? ' error=' . $summary['error'] : '',
    (string) $summary['duration_sec']
);

@file_put_contents($logDir . '/access-stats.log', $line . "\n", FILE_APPEND);

if (!$summary['ok']) {
    fwrite(STDERR, 'aggregate_access_log: ' . (string) $summary['error'] . "\n");
    exit(1);
}

if (!$options['quiet']) {
    echo $line . "\n";
}

exit(0);