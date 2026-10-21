<?php

declare(strict_types=1);

/**
 * The housekeeping, from the command line.
 *
 * It used to be an HTTP call: /etc/cron.d/gallery-housekeeping ran
 *
 *     curl -fsS "http://127.0.0.1/gallery/cron/housekeeping?key=SECRET" > /dev/null 2>&1
 *
 * which had three problems:
 *
 *  1. The key was in the cron file, which is world-readable, and in the
 *     command line, which is in `ps` output while the job runs.
 *  2. `> /dev/null 2>&1` threw the response AND the exit code away, so a
 *     failing run left no trace anywhere. The cron log inside the run
 *     stopped too, because the log write was after a step that threw.
 *  3. A curl failure was indistinguishable from a successful run.
 *
 * This script does the same work with no HTTP hop, no key on the command
 * line and a log that records both the result and the failure.
 *
 * It writes one line per run to storage/logs/cron.log, the same file the
 * old endpoint used, so the existing age checks keep working.
 */

require __DIR__ . '/../app/bootstrap.php';

// The liveness heartbeat: a worker that only logs when it has work
// would look dead to the watchdog if it were watched by log mtime.
\App\Core\Heartbeat::touch('housekeeping');

use App\Core\Housekeeping;

$root = dirname(__DIR__);

// A second run must not overlap the first.
$lockFile = $root . '/storage/logs/housekeeping.lock';
$lockDir  = dirname($lockFile);
if (!is_dir($lockDir)) {
    @mkdir($lockDir, 0775, true);
}
$lock = @fopen($lockFile, 'c');
if ($lock === false) {
    fwrite(STDERR, "housekeeping: cannot open the lock file\n");
    exit(1);
}
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "housekeeping: another run is in progress\n");
    exit(0);
}

$failed = false;
try {
    $summary = Housekeeping::run(10);

    // Import any live-stream recordings left behind by a crashed or
    // disconnected operator (no /live/stop reached the server).
    try {
        $imported = \App\Models\LiveRecording::importOrphans();
        if ($imported > 0) {
            $summary['live_recordings_imported'] = $imported;
        }
    } catch (\Throwable $error) {
        $summary['live_recordings_error'] = $error->getMessage();
        $failed = true;
    }

    // A step that failed is a failed run, even though the run itself
    // carried on. The errors are already in the summary.
    if (!empty($summary['errors'])) {
        $failed = true;
    }

    echo json_encode(['ok' => !$failed] + $summary) . "\n";
} catch (\Throwable $error) {
    // Housekeeping::run() catches its own steps, so getting here means
    // something outside a step failed - the log write, most likely.
    $failed = true;
    fwrite(STDERR, 'housekeeping: ' . $error->getMessage() . "\n");
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}

exit($failed ? 1 : 0);
