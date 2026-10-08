<?php

declare(strict_types=1);

use App\Models\ChatQuestionnaire;

require __DIR__ . '/../app/bootstrap.php';

$logDir = __DIR__ . '/../storage/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}
$log = $logDir . '/questionnaires.log';

$lockHandle = fopen($logDir . '/questionnaires.lock', 'c');
if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    exit(0);
}

$due = ChatQuestionnaire::due();
if ($due === []) {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    exit(0);
}

foreach ($due as $q) {
    $result = ChatQuestionnaire::send((int) $q['id']);
    $line = sprintf(
        '[%s] questionnaire #%d %s %s (%d notified/%d recipients)',
        date('Y-m-d H:i:s'),
        (int) $q['id'],
        $result['status'] ?? 'failed',
        $result['ok'] ? 'delivered' : 'error: ' . ($result['error'] ?? 'unknown'),
        (int) ($result['notified'] ?? 0),
        (int) ($result['recipients'] ?? 0)
    );
    @file_put_contents($log, $line . "\n", FILE_APPEND | LOCK_EX);
    echo $line . "\n";
}

flock($lockHandle, LOCK_UN);
fclose($lockHandle);
