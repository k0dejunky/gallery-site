<?php

declare(strict_types=1);

/**
 * The watchdog: the thing that notices when a background job stops.
 *
 * The housekeeping cron failed on every 15-minute tick for NINETEEN HOURS
 * and nobody knew, because:
 *
 *  - the cron entry discarded the exit code and the output;
 *  - the housekeeping's own log write was after the step that threw, so
 *    the log stopped too;
 *  - the "cron is stale" alert lived on the admin dashboard, so it only
 *    fired if a human happened to load it.
 *
 * This runs from its own cron entry every 5 minutes and checks the
 * heartbeat of every worker that matters. A worker that has not touched
 * its heartbeat is genuinely not running.
 *
 * It checks the HEARTBEAT and not the log's mtime, because a worker that
 * only logs when it has work looks exactly like a dead one when it is
 * idle. (The first version of this watchdog watched the log mtimes and
 * immediately reported four perfectly healthy workers as stale.)
 *
 * The alert is throttled (Mailer::adminAlert takes a cooldown), so a
 * broken job produces one email per cooldown window rather than one every
 * five minutes.
 */

require __DIR__ . '/../app/bootstrap.php';

use App\Core\Heartbeat;
use App\Core\Mailer;

$logDir = dirname(__DIR__) . '/storage/logs';

// The workers that matter, and how long each may be quiet. A worker that
// runs every minute may be quiet for ten; the monthly one for a month.
$watch = [
    ['housekeeping',     45 * 60,      'the housekeeping'],
    ['paypal-reconcile', 15 * 60,      'the PayPal reconciler'],
    ['emailer',          30 * 60,      'the emailer'],
    ['autopost',         30 * 60,      'the auto-poster'],
    ['daily-chat',       30 * 60,      'the daily chat broadcast'],
    ['questionnaires',   30 * 60,      'the questionnaire sender'],
    ['chat-ai',          30 * 60,      'the background chat replies'],
    ['categorizer',      30 * 60,      'the category proposer'],
    ['describer',        30 * 60,      'the gallery describer'],
    ['rendition',        90 * 60,      'the video rendition worker'],
    ['chapters',         90 * 60,      'the video chapter worker'],
    ['video-samples',    60 * 60,      'the video sample worker'],
    ['dimensions',       3 * 3600,     'the media dimension worker'],
    ['web-analytics',    3 * 3600,     'the traffic aggregator'],
    ['ai-watchdog',      30 * 60,      'the AI watchdog'],
    ['backup',           35 * 86400,   'the backup'],
];

$stale = [];
$never = [];
foreach ($watch as [$worker, $maxAge, $label]) {
    $age = Heartbeat::age($worker);
    if ($age === 0) {
        // The worker has never run on this box. That is not the same as
        // having stopped, and it must not cry wolf on a dev box where a
        // worker is simply not configured.
        $never[] = $label . ' has never run here';
        continue;
    }
    if ($age > $maxAge) {
        $stale[] = $label . ' has not run for ' . round($age / 60) . ' minutes';
    }
}

$line = sprintf(
    '%s | checked=%d | stale=%d | never=%d%s%s',
    date('Y-m-d H:i:s'),
    count($watch),
    count($stale),
    count($never),
    $stale === [] ? '' : ' | ' . implode('; ', $stale),
    $never === [] ? '' : ' | never ran: ' . implode('; ', $never)
);
@file_put_contents($logDir . '/watchdog.log', $line . "\n", FILE_APPEND);

if ($stale !== []) {
    Mailer::adminAlert(
        'watchdog-stale',
        'A background job has stopped',
        "The watchdog found " . count($stale) . " job(s) that have not run:\n\n"
            . implode("\n", $stale) . "\n\n"
            . 'Check the logs in storage/logs/ and the cron entries in /etc/cron.d/.',
        3600
    );
    fwrite(STDERR, 'watchdog: ' . implode('; ', $stale) . "\n");
    exit(1);
}

exit(0);
