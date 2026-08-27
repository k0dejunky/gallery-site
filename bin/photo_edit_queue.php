<?php

declare(strict_types=1);

require __DIR__ . '/../app/Core/helpers.php';
spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) return;
    $path = __DIR__ . '/../app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) require $path;
});

use App\Models\PhotoJob;

$php    = is_executable('/usr/bin/php') ? '/usr/bin/php' : PHP_BINARY;
$worker = __DIR__ . '/photo_edit_worker.php';
$once   = in_array('--once', $argv, true);

do {
    PhotoJob::recoverStale();
    try {
        $jobId = PhotoJob::claimNext();
    } catch (Throwable $error) {
        error_log('[photo-edit] claim failed: ' . $error->getMessage());
        sleep(10);
        continue;
    }

    if ($jobId === null) {
        if ($once) {
            break;
        }
        sleep(5);
        continue;
    }

    $command = escapeshellarg($php) . ' ' . escapeshellarg($worker) . ' ' . $jobId . ' 2>&1';
    exec($command, $output, $status);
    if ($status !== 0) {
        PhotoJob::requeue($jobId);
        error_log('[photo-edit] job #' . $jobId . ' failed (attempt limit is 3)');
    }
} while (!$once);
