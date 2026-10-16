<?php

declare(strict_types=1);

/**
 * Emailer worker: delivers the queued mail. Runs every 5 minutes from
 * /etc/cron.d/gallery-emailer ("--once"); a lock file prevents overlapping
 * ticks. Writes one line per run to storage/logs/emailer.log.
 *
 * Nothing is generated here any more. The emailer used to enqueue a scheduled
 * digest of the newest uploads on a wall-clock timer; that was removed, and
 * every remaining email is queued by the event that causes it (a chat reply, a
 * gallery being published, a live start, a renewal coming due, a lapsed member
 * or a failed charge). So this worker is now purely a delivery pump: it hands
 * the next batch of queued rows to Mailer::sendHtml() and marks them
 * sent/failed, with retry backoff handled per row.
 */

require __DIR__ . '/../app/bootstrap.php';

use App\Models\EmailQueue;

$once = in_array('--once', $argv, true);

$lockFile = dirname(__DIR__) . '/storage/logs/emailer.lock';
$logFile  = dirname(__DIR__) . '/storage/logs/emailer.log';

$lockDir = dirname($lockFile);
if (!is_dir($lockDir)) {
    @mkdir($lockDir, 0775, true);
}

$lock = fopen($lockFile, 'c');

if ($lock === false) {
    error_log('[emailer] unable to open lock file ' . $lockFile);
    exit(1);
}

if (!flock($lock, LOCK_EX | LOCK_NB)) {
    // Another worker tick is already running; it will pick up the queue.
    exit(0);
}

$log = static function (string $line) use ($logFile): void {
    @file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . '] ' . $line . "\n", FILE_APPEND | LOCK_EX);
};

do {
    try {
        $send = EmailQueue::sendDue(25);

        if ($send['attempted'] > 0) {
            $log(sprintf(
                'delivered batch: %d sent, %d failed (of %d attempted)',
                (int) $send['sent'],
                (int) $send['failed'],
                (int) $send['attempted']
            ));
        }
    } catch (Throwable $error) {
        $log('run failed: ' . $error->getMessage());
    }

    if ($once) {
        break;
    }

    sleep(60);
} while (true);
