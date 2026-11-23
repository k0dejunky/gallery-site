<?php
/**
 * Remove every file under storage/uploads that nothing refers to.
 *
 * "Orphaned" means one of three things, and all three are scanned:
 *
 *   root      an original (or a derivative whose source is gone) with no
 *             photos row,
 *   previews  an SEO sample clip whose video is gone,
 *   chat      a chat attachment whose message row is gone.
 *
 * The admin page (Uploads & Orphans) uses the SAME scan
 * (App\Models\OrphanedUploads), so the CLI and the page can never disagree
 * about what is deletable. Do not reintroduce a local prefix/theme list here:
 * that is how a --yes run once came within one edit of deleting every video
 * rendition.
 *
 * Usage: php bin/cleanup_orphan_uploads.php [--dry-run] [--yes] [--limit=N]
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/app/bootstrap.php';

use App\Models\OrphanedUploads;

$dryRun = in_array('--dry-run', $argv, true);
$yes    = in_array('--yes', $argv, true);
$limit  = 0;
foreach ($argv as $i => $arg) {
    if (strpos($arg, '--limit=') === 0) {
        $limit = (int) substr($arg, 8);
    }
}

$orphans  = OrphanedUploads::scan();
$bytes    = (int) array_sum(array_column($orphans, 'size'));
$bySource = [];

foreach ($orphans as $orphan) {
    $source = (string) ($orphan['source'] ?? 'root');
    $bySource[$source] = ($bySource[$source] ?? 0) + 1;
}

$detail = [];
foreach ($bySource as $source => $count) {
    $detail[] = $source . ' ' . $count;
}

printf(
    "%d orphan(s), %.1f GB%s\n",
    count($orphans),
    $bytes / 1073741824,
    $detail === [] ? '' : ' (' . implode(', ', $detail) . ')'
);

if ($dryRun) {
    echo "dry run - nothing was deleted\n";
    exit(0);
}

if (!$yes && $orphans !== []) {
    echo "pass --yes to delete them\n";
    exit(0);
}

$names = array_column($orphans, 'name');
if ($limit > 0) {
    $names = array_slice($names, 0, $limit);
}

printf("deleted %d file(s)\n", OrphanedUploads::delete($names));
exit(0);
