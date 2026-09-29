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
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;
use App\Models\Photo;

$rows = Database::run('SELECT id, filename FROM photos WHERE is_video = 1')->fetchAll();

$made = $skipped = $failed = $pruned = 0;
$uploadsDir = (string) config('app.uploads.dir');

foreach ($rows as $row) {
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
            echo "pruned clip for video {$id}\n";
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
            fwrite(STDERR, "no source for video {$id} ({$filename})\n");
            $failed++;
            continue;
        }
        $src = $web;
    }

    if (create_video_sample_clip($src, $dest)) {
        $made++;
        echo "created clip for video {$id}\n";
    } else {
        fwrite(STDERR, "failed to create clip for video {$id} ({$filename})\n");
        $failed++;
    }
}

echo "sample clips: {$made} created, {$skipped} already present, {$failed} failed, {$pruned} pruned\n";
exit($failed > 0 ? 1 : 0);