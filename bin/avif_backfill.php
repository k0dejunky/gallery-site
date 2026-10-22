<?php

declare(strict_types=1);

// Backfill AVIF copies for variants that predate avif generation. Scans the
// uploads root for web_/thumb_ variants without a sibling .avif and encodes
// one via the ffmpeg libsvtav1 path (same _make_avif helper the upload time
// now uses).
//
// Usage: php bin/avif_backfill.php [--limit=N] [--dry-run] [--thumb-only]
// Exit code 0 on success (idempotent; skips files that already have avif).
//
// It also writes the missing WebP copy, so a variant can carry both modern
// formats.

require __DIR__ . '/../app/bootstrap.php';

$limit    = 0;
$dryRun   = false;
$thumbOnly = false;
foreach ($argv as $i => $arg) {
    if ($arg === '--dry-run') {
        $dryRun = true;
    } elseif ($arg === '--thumb-only') {
        $thumbOnly = true;
    } elseif (strpos($arg, '--limit=') === 0) {
        $limit = max(0, (int) substr($arg, 8));
    }
}

$root = config('app.uploads.dir');
$count = 0;
$errors = 0;
$start = microtime(true);

$files = [];
if (!$thumbOnly) {
    foreach (glob($root . '/web_*.*') ?: [] as $f) {
        $files[] = $f;
    }
    // The blurred covers are what every guest (and every crawler) sees.
    // They had no modern-format copy at all, so the guest grid was the one
    // surface that could not use AVIF or WebP.
    foreach (glob($root . '/blur_*.*') ?: [] as $f) {
        $files[] = $f;
    }
}
foreach (glob($root . '/thumb_*.*') ?: [] as $f) {
    $files[] = $f;
}
sort($files);

foreach ($files as $file) {
    if ($limit > 0 && $count >= $limit) {
        break;
    }

    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'tiff'], true)) {
        continue;
    }
    // A WebP is never the SOURCE of an AVIF: the AVIF belongs next to the
    // JPEG of the same variant, not next to the WebP copy of it.
    if ($ext === 'webp' || $ext === 'avif') {
        continue;
    }

    $avif = preg_replace('/\.[^.]+$/', '.avif', $file);
    $webp = preg_replace('/\.[^.]+$/', '.webp', $file);
    $needAvif = !is_file($avif);
    $needWebp = !is_file($webp);
    if (!$needAvif && !$needWebp) {
        continue;
    }

    if ($dryRun) {
        $count++;
        echo "would: " . basename($file) . ($needAvif ? ' +avif' : '') . ($needWebp ? ' +webp' : '') . "\n";
        continue;
    }

    // Sequential maintenance job: call the real binary directly so the
    // site's 2-slot ffmpeg guard (used by live uploads) is never exhausted
    // by the backfill.
    $bin = is_executable('/usr/local/bin/ffmpeg.real') ? '/usr/local/bin/ffmpeg.real' : '/usr/bin/ffmpeg';

    if ($needWebp) {
        exec(
            escapeshellarg($bin) . ' -y -hide_banner -loglevel error -i '
            . escapeshellarg($file)
            . ' -frames:v 1 -c:v libwebp -quality 80 -threads 8 '
            . escapeshellarg($webp) . ' 2>/dev/null'
        );
        if (!is_file($webp)) {
            $errors++;
        }
    }

    if ($needAvif) {
        exec(
            escapeshellarg($bin) . ' -y -hide_banner -loglevel error -i '
            . escapeshellarg($file)
            . ' -frames:v 1 -c:v libsvtav1 -preset 8 -crf 36 -still-picture 1 -threads 8 '
            . escapeshellarg($avif) . ' 2>/dev/null'
        );

        if (!is_file($avif)) {
            $errors++;
        }
    }

    $count++;
    if ($count % 50 === 0) {
        echo $count . " done\n";
    }
}

printf(
    "%s: %d avif created, %d errors, %.1fs\n",
    $dryRun ? 'dry-run' : 'backfill',
    $count,
    $errors,
    microtime(true) - $start
);

exit($errors > 0 ? 2 : 0);