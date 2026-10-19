<?php

declare(strict_types=1);

/**
 * Media dimensions worker: measures the DISPLAY size of every media file and
 * stores it on the photos row (width / height / rotation).
 *
 * The player sizes its box from these values, so a vertical clip gets a
 * vertical box from the very first paint instead of a wide one that snaps
 * into shape once the browser has read the video's metadata - which is what
 * put black bars around portrait videos.
 *
 * This only PROBES (ffprobe / getimagesize); it never re-encodes anything, so
 * a full pass over the catalogue is cheap. It is also what tells the
 * rendition worker which files need a re-encode: a source whose current
 * rendition has the wrong shape (the old code sized a rotated clip to
 * 1280x2844 for its "720p" rung).
 *
 * Runs from /etc/cron.d ("--once"); a lock file prevents overlapping ticks.
 *
 * Usage:
 *   php bin/media_dimensions_worker.php --once [--limit=N] [--missing=1] [--photos=1,2,3]
 */

require __DIR__ . '/../app/bootstrap.php';

use App\Models\Photo;

$limit   = 0;
$missing = false;
$photos  = [];

foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = (int) $m[1];
    } elseif ($arg === '--missing=1' || $arg === '--missing') {
        $missing = true;
    } elseif (preg_match('/^--photos=([\d,]+)$/', $arg, $m)) {
        $photos = array_values(array_filter(array_map('intval', explode(',', $m[1]))));
    }
}

$logDir   = dirname(__DIR__) . '/storage/logs';
$lockFile = $logDir . '/dimensions.lock';
$logFile  = $logDir . '/dimensions.log';

if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}

$lock = fopen($lockFile, 'c');
if ($lock === false) {
    error_log('[dimensions] unable to open lock file ' . $lockFile);
    exit(1);
}

if (!flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}

$log = static function (string $line) use ($logFile): void {
    @file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . '] ' . $line . "\n", FILE_APPEND | LOCK_EX);
};

$uploads = rtrim((string) config('app.uploads.dir'), '/');

/**
 * The photos to measure. DB-driven so a file that is not attached to any
 * gallery is never probed.
 */
$candidates = static function () use ($uploads, $missing, $photos): array {
    if ($photos !== []) {
        $out = [];
        foreach ($photos as $id) {
            $row = \App\Core\Database::run(
                'SELECT id, filename, is_video, width, height FROM photos WHERE id = ?',
                [$id]
            )->fetch();
            if ($row) {
                $out[(int) $row['id']] = $row;
            }
        }

        return $out;
    }

    $sql    = 'SELECT id, filename, is_video, width, height FROM photos';
    $params = [];
    if ($missing) {
        $sql .= ' WHERE (width IS NULL OR height IS NULL)';
    }
    $sql .= ' ORDER BY id ASC';

    $rows = \App\Core\Database::run($sql, $params)->fetchAll();
    $out  = [];
    foreach ($rows as $row) {
        $out[(int) $row['id']] = $row;
    }

    return $out;
};

$rows = $candidates();
if ($rows === []) {
    $log('nothing to measure');
    exit(0);
}

$measured = 0;
$skipped  = 0;
$failed   = 0;
$changed  = 0;

foreach ($rows as $id => $row) {
    if ($limit > 0 && $measured >= $limit) {
        break;
    }

    $path = $uploads . '/' . basename((string) $row['filename']);
    if (!is_file($path)) {
        $skipped++;
        continue;
    }

    $isVideo = (int) ($row['is_video'] ?? 0) === 1;
    $dim     = media_display_dimensions($path, $isVideo);
    if ($dim === null) {
        $failed++;
        continue;
    }

    $measured++;

    $same = (int) ($row['width'] ?? 0) === $dim['w']
        && (int) ($row['height'] ?? 0) === $dim['h'];

    Photo::setDimensions($id, $dim['w'], $dim['h'], $dim['rotation']);
    if (!$same) {
        $changed++;
        $log(sprintf(
            'photo %d: %s -> %dx%d (rotation %d)',
            $id,
            basename((string) $row['filename']),
            $dim['w'],
            $dim['h'],
            $dim['rotation']
        ));
    }
}

$log(sprintf('measured %d, changed %d, skipped %d, failed %d', $measured, $changed, $skipped, $failed));

exit(0);
