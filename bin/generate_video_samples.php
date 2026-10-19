<?php

/**
 * Generate the short public sample clips (first ~12s, ≤480p, blurred) for
 * every video that is missing one, so search engines can index the site's
 * videos (Google Video). Only videos in published, non-secret galleries get
 * clips; clips for videos that are unpublished/scheduled or secret are
 * pruned. Skips videos that already have a clip; prints a summary.
 *
 * Run as the web user (files are owned by www-data):
 *
 *   sudo -u www-data php /var/www/gallery/bin/generate_video_samples.php
 *
 * From /etc/cron.d ("--once --limit=2"): drains a couple of clips per tick
 * behind a lock, so a large backlog is filled gradually instead of saturating
 * the CPU with hundreds of ffmpeg runs at once.
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;
use App\Models\Photo;

$limit = 0;
$once  = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--once') {
        $once = true;
    } elseif (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = (int) $m[1];
    }
}

$logDir = dirname(__DIR__) . '/storage/logs';
$logFile = $logDir . '/video-samples.log';
$log = static function (string $line) use ($logFile): void {
    @file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . '] ' . $line . "\n", FILE_APPEND | LOCK_EX);
};

if ($once) {
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }
    $lock = fopen($logDir . '/video-samples.lock', 'c');
    if ($lock === false) {
        exit(1);
    }
    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        exit(0);
    }
    // Cron ticks log to the file instead of stdout; a manual run still prints.
    $say = static function (string $line) use ($log): void {
        $log($line);
    };
} else {
    $say = static function (string $line): void {
        echo $line . "\n";
    };
}

$rows = Database::run('SELECT id, filename FROM photos WHERE is_video = 1')->fetchAll();

$made = $skipped = $failed = $pruned = 0;
$uploadsDir = (string) config('app.uploads.dir');

foreach ($rows as $row) {
    // A bounded tick stops as soon as its clip budget is spent; pruning (below)
    // is never skipped, so a tick can still clean up unpublished clips.
    if ($limit > 0 && $made >= $limit) {
        break;
    }

    $id       = (int) $row['id'];
    $filename = (string) $row['filename'];
    $dest     = video_sample_path($filename);

    // Clips exist only for published, non-secret content. Prune anything
    // that no longer qualifies (scheduled/secret videos must never appear in
    // the public SEO section).
    if (!Photo::hasPublicGallery($id)) {
        if (is_file($dest)) {
            @unlink($dest);
            $pruned++;
            $say("pruned clip for video {$id}");
        }
        continue;
    }

    if (is_file($dest)) {
        $skipped++;
        continue;
    }

    $src = $uploadsDir . '/' . $filename;
    if (!is_file($src)) {
        $src = $uploadsDir . '/exports/' . $filename;
    }
    if (!is_file($src)) {
        $web = $uploadsDir . '/web_' . $filename;
        if (!is_file($web)) {
            $say("no source for video {$id} ({$filename})");
            $failed++;
            continue;
        }
        $src = $web;
    }

    if (create_video_sample_clip($src, $dest)) {
        $made++;
        $say("created clip for video {$id}");
    } else {
        $say("failed to create clip for video {$id} ({$filename})");
        $failed++;
    }
}

$summary = "sample clips: {$made} created, {$skipped} already present, {$failed} failed, {$pruned} pruned";
$say($summary);
if (!$once) {
    echo $summary . "\n";
}
exit($failed > 0 ? 1 : 0);