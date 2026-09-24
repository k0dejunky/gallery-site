<?php

declare(strict_types=1);

/**
 * Auto-poster worker: publishes queued auto-posts whose scheduled_at time has
 * passed. Runs every minute from /etc/cron.d/gallery-autopost ("--once").
 * A lock file prevents two runs from overlapping (e.g. a slow multi-image
 * upload outliving the next cron tick).
 */

require __DIR__ . '/../app/bootstrap.php';

use App\Models\AutoPostQueue;

$once = in_array('--once', $argv, true);

$lockFile = dirname(__DIR__) . '/storage/logs/autopost.lock';

$lockDir = dirname($lockFile);
if (!is_dir($lockDir)) {
    @mkdir($lockDir, 0775, true);
}

$lock = fopen($lockFile, 'c');

if ($lock === false) {
    error_log('[autopost] unable to open lock file ' . $lockFile);
    exit(1);
}

if (!flock($lock, LOCK_EX | LOCK_NB)) {
    // Another worker is already publishing. A cron tick that finds the queue
    // busy should just return; the running worker will pick these rows up.
    exit(0);
}

do {
    try {
        $due = AutoPostQueue::due(20);

        foreach ($due as $item) {
            $result = AutoPostQueue::post((int) $item['id']);

            error_log(sprintf(
                '[autopost] queue #%d: %s',
                (int) $item['id'],
                $result['ok']
                    ? 'posted ' . ($result['url'] ?? '')
                    : (empty($result['skipped'])
                        ? 'failed: ' . ($result['error'] ?? 'unknown')
                        : 'skipped: ' . ($result['error'] ?? 'platform not authorized'))
            ));
        }

        // Nothing scheduled right now: schedule fresh posts from random
        // galleries so X / Reddit stay active (one per hour over the next
        // 24 hours).
        if ($due === []) {
            $requeued = AutoPostQueue::scheduleRandomGalleriesWhenIdle(24, 24);
            if ($requeued > 0) {
                error_log('[autopost] idle queue: scheduled ' . $requeued . ' random gallery post(s) over 24h');
            }
        }
    } catch (Throwable $error) {
        error_log('[autopost] run failed: ' . $error->getMessage());
    }

    if ($once) {
        break;
    }

    sleep(60);
} while (true);