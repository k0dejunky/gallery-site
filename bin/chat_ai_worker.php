<?php

declare(strict_types=1);

/**
 * Background chat-reply worker. Drains up to 3 queued chat_ai_jobs rows per
 * tick when `chat_ai_async` is enabled; each job runs the exact same
 * generation path the synchronous send used to run (ChatAiResponder), so the
 * stored reply is identical, it just arrives a moment after the member's POST
 * returned. flock prevents overlapping ticks; jobs stuck 'running' from a
 * killed worker are re-queued (attempts caps retries at 3).
 *
 * Runs every minute from /etc/cron.d ("--once") and writes one line per job
 * to storage/logs/chat_ai.log.
 */

require __DIR__ . '/../app/bootstrap.php';

use App\Models\ChatAiJob;

$logDir = dirname(__DIR__) . '/storage/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}
$logFile = $logDir . '/chat_ai.log';

$lock = fopen($logDir . '/chat_ai.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}

// Only work while the feature is on; flip the flag off and the queue idles.
$settings = \App\Core\ChatSettings::all();
if (empty($settings['chat_ai_async']) || empty($settings['ai_enabled'])) {
    flock($lock, LOCK_UN);
    fclose($lock);
    exit(0);
}

ChatAiJob::recoverStale();

$done = 0;
for ($i = 0; $i < 3; $i++) {
    $id = ChatAiJob::claimNext();
    if ($id === null) {
        break;
    }

    $ok = ChatAiJob::process($id);
    $job = ChatAiJob::find($id);
    $line = sprintf(
        '[%s] job #%d %s (conversation %s, attempts %s%s)',
        date('Y-m-d H:i:s'),
        $id,
        $ok ? 'reply stored' : 'failed',
        (string) ($job['conversation_id'] ?? '?'),
        (string) ($job['attempts'] ?? '?'),
        $ok ? '' : ', error: ' . (string) ($job['error'] ?? 'unknown')
    );
    @file_put_contents($logFile, $line . "\n", FILE_APPEND | LOCK_EX);
    echo $line . "\n";
    $done++;
}

flock($lock, LOCK_UN);
fclose($lock);
