<?php

declare(strict_types=1);

/**
 * Emailer worker: whenever the configured schedule is due, enqueues a digest
 * of the newest uploads for every eligible recipient, then delivers the next
 * batch of queued rows to the mailer. Runs every 5 minutes from
 * /etc/cron.d/gallery-emailer ("--once"); a lock file prevents overlapping
 * ticks. Writes one line per run to storage/logs/emailer.log.
 */

require __DIR__ . '/../app/Core/helpers.php';
spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $path = __DIR__ . '/../app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

use App\Models\EmailerConfig;
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
        $config = EmailerConfig::all();

        if (!empty($config['enabled']) && EmailerConfig::due($config)) {
            $result = EmailQueue::enqueueDigest($config);

            if (empty($result['ok'])) {
                $log('enqueue skipped: ' . ($result['reason'] ?? 'unknown'));
            } else {
                $log(sprintf(
                    'enqueued digest: %d photo(s), %d subscriber, %d non-subscriber',
                    (int) $result['count'],
                    (int) $result['audience']['subscriber'],
                    (int) $result['audience']['non_subscriber']
                ));
            }
        }

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