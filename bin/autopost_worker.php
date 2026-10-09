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
    // Liveness heartbeat for /health (surfaced as workers.autopost_heartbeat).
    @touch(dirname(__DIR__) . '/storage/logs/autopost.heartbeat');

    try {
        $due = AutoPostQueue::due(20);

        foreach ($due as $item) {
            // Reddit rows in browser mode are owned by the operator's
            // home-browser worker (the share-button method) — leave them for
            // it instead of trying a browser on this (blocked) cloud IP.
            if (\App\Models\AutoPostQueue::shouldDeferToBrowser($item)) {
                \App\Models\AutoPostQueue::releaseClaim((int) $item['id'], (string) ($item['claimed_by'] ?? ''));
                continue;
            }

            // Isolate each row: a throwing post() (an unforeseen DB error, an
            // oversized platform response, a filesystem hiccup) must not kill
            // the rest of the batch — drop the item, mark it, keep going. The
            // claimed row is released by markFailed via the catch inside post()
            // or by the next run's stale reclaim, so it can never wedge the
            // queue behind one unprocessable recommendation.
            try {
                $result = AutoPostQueue::post((int) $item['id']);
            } catch (Throwable $itemError) {
                try {
                    AutoPostQueue::markFailed((int) $item['id'], $itemError->getMessage());
                } catch (Throwable $markError) {
                    error_log('[autopost] queue #' . (int) $item['id'] . ': both post and markFailed threw: ' . $markError->getMessage());
                }
                error_log('[autopost] queue #' . (int) $item['id'] . ': failed (isolated): ' . $itemError->getMessage());
                continue;
            }

            error_log(sprintf(
                '[autopost] queue #%d: %s',
                (int) $item['id'],
                $result['ok']
                    ? 'posted ' . ($result['url'] ?? '')
                    : (empty($result['skipped'])
                        ? 'failed: ' . ($result['error'] ?? 'unknown')
                        : 'skipped: ' . ($result['error'] ?? 'platform not authorized'))
            ));

            // Gentle pacing between posts so no channel's rate limit is hit by
            // a burst (Discord allows ~5 webhook calls/2s; Telegram ~30 msg/s).
            usleep(750000);
        }

        // Rolling pipeline refill: at :30 each hour, top each authorized
        // platform back up to 24 hourly queued posts (also refill right away
        // if a platform is completely empty), so the queue never runs dry.
        $minute = (int) date('i');
        if (($minute >= 28 && $minute <= 31) || !AutoPostQueue::hasQueued()) {
            $refilled = AutoPostQueue::refillAhead(24);
            if ($refilled > 0) {
                error_log('[autopost] refilled pipeline with ' . $refilled . ' scheduled post(s)');
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