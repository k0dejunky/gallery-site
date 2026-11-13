<?php
/**
 * Remove the files in storage/uploads that no photo points at.
 *
 * A file is written to disk before the photo row is made, so a file the site
 * already holds (the same hash) leaves a copy behind that nothing refers to,
 * and a deleted photo leaves its derivatives behind (Photo::deleteIfOrphan
 * now removes those itself).
 *
 * Usage: php bin/cleanup_orphan_uploads.php [--dry-run] [--yes] [--limit=N]
 *
 * A file is an orphan when the photo it derives from no longer exists. The
 * derivative prefixes come from media_variant_prefixes() - blur_thumb_,
 * thumb_, web_, poster_ and every configured ladder rung (weblo_, webhi_).
 * This script used to know only web_ and thumb_, so a --yes run would have
 * deleted EVERY video rendition and every blurred teaser. Do not reintroduce
 * a local prefix list.
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
$stems = [];
foreach (Database::run('SELECT filename FROM photos')->fetchAll() as $row) {
    $name = (string) $row['filename'];
    $names[$name] = true;
    // Derivatives can change the extension (web_<stem>.webp for <stem>.jpg),
    // so the stem is the reliable second test.
    $stems[pathinfo($name, PATHINFO_FILENAME)] = true;
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
    $base = media_derivative_base($entry);
    if (isset($names[$base]) || isset($stems[pathinfo($base, PATHINFO_FILENAME)])) {
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
