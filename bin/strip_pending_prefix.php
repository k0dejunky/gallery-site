<?php
/**
 * One-time cleanup: strip the leftover "pending_" tracking prefix from photo
 * filenames. Staged gallery uploads used uniqid('pending_', ...) and
 * finalizePending() never stripped the prefix, so committed photos (and their
 * thumb_/web_/blur_thumb_ variants) kept it. This renames the files on disk and
 * updates photos.filename to match.
 *
 * Safe to re-run: records whose filename no longer starts with pending_ are
 * skipped.
 */
$root = dirname(__DIR__);
require $root . '/app/bootstrap.php';

$uploads = (string) config('app.uploads.dir');
$db      = \App\Core\Database::run('SELECT id, filename FROM photos WHERE filename LIKE ? ORDER BY id', ['pending\_%'])->fetchAll();

$renamed = 0;
$missing = 0;
$failed  = 0;
$skipped = 0;

foreach ($db as $photo) {
    $old = (string) $photo['filename'];
    if (strpos($old, 'pending_') !== 0) {
        $skipped++;
        continue;
    }
    $new = substr($old, strlen('pending_'));

    $src = $uploads . '/' . $old;
    $dst = $uploads . '/' . $new;

    if (!is_file($src)) {
        $missing++;
        echo "[skip] missing original: $old\n";
        continue;
    }
    if (is_file($dst)) {
        echo "[skip] destination exists: $new\n";
        $skipped++;
        continue;
    }

    if (!rename($src, $dst)) {
        $failed++;
        echo "[fail] rename original: $old -> $new\n";
        continue;
    }

    foreach (['thumb_', 'web_', 'blur_thumb_'] as $prefix) {
        $vSrc = $uploads . '/' . $prefix . $old;
        $vDst = $uploads . '/' . $prefix . $new;
        if (is_file($vSrc)) {
            if (is_file($vDst)) {
                echo "[warn] variant destination exists: " . $prefix . $new . "\n";
            } elseif (!rename($vSrc, $vDst)) {
                echo "[warn] rename variant failed: $prefix$old\n";
            }
        }
    }

    \App\Core\Database::run('UPDATE photos SET filename = ? WHERE id = ?', [$new, (int) $photo['id']]);
    $renamed++;
}

echo "\nDone: renamed=$renamed missing_originals=$missing failed=$failed skipped=$skipped\n";