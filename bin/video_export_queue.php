<?php

declare(strict_types=1);

require __DIR__ . '/../app/Core/helpers.php';
spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) return;
    $path = __DIR__ . '/../app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) require $path;
});

use App\Models\VideoProject;

$php = is_executable('/usr/bin/php') ? '/usr/bin/php' : PHP_BINARY;
$worker = __DIR__ . '/video_export_worker.php';
$once = in_array('--once', $argv, true);

do {
    VideoProject::recoverStaleExports();
    try {
        $jobId = VideoProject::claimNextExport();
    } catch (Throwable $error) {
        error_log('[video-queue] claim failed: ' . $error->getMessage());
        sleep(10);
        continue;
    }

    if ($jobId === null) {
        if ($once) break;
        sleep(5);
        continue;
    }

    $command = escapeshellarg($php) . ' ' . escapeshellarg($worker) . ' ' . $jobId . ' 2>&1';
    exec($command, $output, $status);
    if ($status !== 0) {
        VideoProject::requeueExport($jobId);
        error_log('[video-queue] export #' . $jobId . ' failed (attempt limit is 3)');
    }
} while (!$once);
