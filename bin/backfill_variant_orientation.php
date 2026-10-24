<?php
/**
 * Rebuild the previews of the photos whose original carries an EXIF
 * orientation tag.
 *
 * ffmpeg applies the EXIF orientation itself, and the site used to apply it a
 * second time when it built the web_/thumb_ previews. Every camera photo
 * therefore got a preview 180 degrees from the original: the grids showed
 * the photos upside down while the browser showed the original the right
 * way up - and because the manage page's rotate buttons are judged against
 * those previews, rotating looked like it turned the images the wrong way.
 *
 * The originals are untouched (the tag is still needed for the browser); only
 * the previews are rebuilt.
 *
 * Usage: php bin/backfill_variant_orientation.php [--dry-run] [--limit=N]
 *                                              [--from=PHOTO_ID]
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/app/bootstrap.php';

use App\Core\Database;

$dryRun = in_array('--dry-run', $argv, true);
$limit  = 0;
$from   = 0;
foreach ($argv as $arg) {
    if (strpos($arg, '--limit=') === 0) {
        $limit = (int) substr($arg, 8);
    }
    if (strpos($arg, '--from=') === 0) {
        $from = (int) substr($arg, 7);
    }
}

$config = config('app.uploads');
$dir    = $config['dir'];

$rows = Database::run(
    'SELECT id, filename FROM photos WHERE id > ? ORDER BY id ASC',
    [$from]
)->fetchAll();

echo "photos to look at: " . count($rows) . "\n";

$checked = 0;
$fixed    = 0;
$skipped  = 0;
$failed   = 0;

foreach ($rows as $row) {
    if ($limit > 0 && $checked >= $limit) {
        break;
    }
    $checked++;

    $filename = (string) $row['filename'];
    if (is_video($filename)) {
        $skipped++;
        continue;
    }

    $path = $dir . '/' . $filename;
    if (!is_file($path)) {
        $skipped++;
        continue;
    }

    // Only the tags that turn the picture need a new preview.
    $orientation = exif_orientation_of($path);
    if ($orientation <= 1) {
        $skipped++;
        continue;
    }

    if ($dryRun) {
        $fixed++;
        echo "would rebuild: {$filename} (orientation {$orientation})\n";
        continue;
    }

    // The webp and avif copies are only written when they are missing, so
    // the stale ones have to go first.
    foreach ([
        $dir . '/web_' . $filename,
        $dir . '/thumb_' . $filename,
    ] as $variant) {
        $base = preg_replace('/\.[^.]+$/', '', $variant);
        foreach (['.webp', '.avif'] as $ext) {
            if (is_file($base . $ext)) {
                @unlink($base . $ext);
            }
        }
    }

    $ok = create_image_variants(
        $path,
        $dir . '/web_' . $filename,
        $dir . '/thumb_' . $filename,
        $config['web_max_width'],
        $config['thumb_width'],
        $config['thumb_height']
    );

    if ($ok) {
        $fixed++;
        echo "rebuilt: {$filename} (orientation {$orientation})\n";
    } else {
        $failed++;
        echo "FAILED:  {$filename}\n";
    }

    // The last id is printed so a run that is cut short can be resumed.
    echo "last id: " . (int) $row['id'] . "\n";
}

printf(
    "checked %d, rebuilt %d, skipped %d, failed %d\n",
    $checked,
    $fixed,
    $skipped,
    $failed
);
exit(0);
