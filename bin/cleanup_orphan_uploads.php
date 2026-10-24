<?php
/**
 * Remove the files in storage/uploads that no photo points at.
 *
 * A file is written to disk before the photo row is made, so a file the site
 * already holds (the same hash) leaves a copy behind that nothing refers
 * to. Re-importing a folder does that for every file in it, and the copies
 * pile up until the disk fills.
 *
 * Usage: php bin/cleanup_orphan_uploads.php [--dry-run] [--yes]
 *
 * The file of a photo is its own name; the preview and the web copy are
 * web_<name> and thumb_<name>. A file whose name (without that prefix) is
 * not a photo's file is an orphan.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/app/bootstrap.php';

use App\Core\Database;

$dryRun = in_array('--dry-run', $argv, true);
$yes    = in_array('--yes', $argv, true);
$limit  = 0;
foreach ($argv as $i => $arg) {
    if (strpos($arg, '--limit=') === 0) {
        $limit = (int) substr($arg, 8);
    }
}

$dir = \config('app.uploads')['dir'];
if (!is_dir($dir)) {
    fwrite(STDERR, "the uploads folder is not there: {$dir}\n");
    exit(1);
}

$names = [];
foreach (Database::run('SELECT filename FROM photos')->fetchAll() as $row) {
    $names[(string) $row['filename']] = true;
}

$orphans = [];
$bytes   = 0;
$kept    = 0;
foreach (scandir($dir) as $entry) {
    if ($entry === '.' || $entry === '..') {
        continue;
    }
    $path = $dir . '/' . $entry;
    if (!is_file($path)) {
        continue;
    }
    $base = preg_replace('/^(web_|thumb_)/', '', $entry);
    if (isset($names[$base])) {
        $kept++;
        continue;
    }
    $size    = (int) @filesize($path);
    $bytes  += $size;
    $orphans[] = $path;
}

printf("kept %d file(s), %d orphan(s), %.1f GB\n", $kept, count($orphans), $bytes / 1073741824);

if ($dryRun) {
    echo "dry run - nothing was deleted\n";
    exit(0);
}

if (!$yes && $orphans !== []) {
    echo "pass --yes to delete them\n";
    exit(0);
}

$deleted = 0;
$failed  = 0;
$done    = 0;
foreach ($orphans as $path) {
    if ($limit > 0 && $done >= $limit) {
        break;
    }
    $done++;
    if (@unlink($path)) {
        $deleted++;
    } else {
        $failed++;
    }
}

printf("deleted %d file(s), %d could not be removed\n", $deleted, $failed);
exit(0);
