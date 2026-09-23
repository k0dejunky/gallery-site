<?php

declare(strict_types=1);

use App\Models\ChatBroadcast;

// Deliver due daily chat broadcasts to chat-eligible members.
//
// Invoked by the system cron (see bin/apply_cron.php):
//   */5 * * * * www-data /usr/bin/php /var/www/gallery/bin/daily_chat_worker.php --once >> /var/www/gallery/storage/logs/daily-chat.log 2>&1
//
// It finds every broadcast that is scheduled and due (schedule passed, or a
// manual "send now" entry) and delivers it. Each run logs one summary line.

require __DIR__ . '/../app/bootstrap.php';

$logDir = __DIR__ . '/../storage/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}
$log = $logDir . '/daily-chat.log';

// Single-instance lock: overlapping runs (cron + an admin "send now") must
// not double-deliver. The broadcast claim in ChatBroadcast::send() is the
// authoritative guard; the flock just prevents wasted parallel work.
$lockHandle = fopen($logDir . '/daily-chat.lock', 'c');
if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    exit(0);
}

$due = ChatBroadcast::due();
if ($due === []) {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    exit(0);
}

foreach ($due as $broadcast) {
    $result = ChatBroadcast::send((int) $broadcast['id']);
    $line = sprintf(
        '[%s] broadcast #%d %s %s (%d/%d recipients)%s',
        date('Y-m-d H:i:s'),
        (int) $broadcast['id'],
        $result['status'] ?? 'failed',
        $result['ok'] ? 'delivered' : 'error: ' . ($result['error'] ?? 'unknown'),
        (int) ($result['sent'] ?? 0),
        (int) ($result['recipients'] ?? 0),
        !empty($result['error']) ? ' — ' . $result['error'] : ''
    );
    @file_put_contents($log, $line . "\n", FILE_APPEND | LOCK_EX);
    echo $line . "\n";
}

flock($lockHandle, LOCK_UN);
fclose($lockHandle);