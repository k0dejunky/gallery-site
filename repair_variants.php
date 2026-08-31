<?php

declare(strict_types=1);

/**
 * One-off repair: regenerate missing thumb_/web_ variants for every photo
 * row whose original still exists. Safe to re-run; skips anything complete.
 *
 * Usage (as www-data): php repair_variants.php [--dry-run]
 */

require __DIR__ . '/app/Core/helpers.php';

$config = require __DIR__ . '/config/app.php';
$uploadsDir = $config['uploads']['dir'];
$dryRun = in_array('--dry-run', $argv, true);

$db = parse_ini_file(__DIR__ . '/.env');
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $db['GALLERY_DB_HOST'], $db['GALLERY_DB_PORT'], $db['GALLERY_DB_NAME']),
    $db['GALLERY_DB_USER'],
    $db['GALLERY_DB_PASSWORD'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$rows = $pdo->query('SELECT filename, is_video FROM photos ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);

$fixedThumb = 0;
$fixedWeb = 0;
$missingOriginal = 0;

foreach ($rows as $row) {
    $filename = (string) $row['filename'];
    $src = $uploadsDir . '/' . $filename;

    if (!is_file($src)) {
        $missingOriginal++;
        continue;
    }

    $thumbPath = $uploadsDir . '/thumb_' . $filename;
    $webPath   = $uploadsDir . '/web_' . $filename;

    if (!empty($row['is_video'])) {
        if (!is_file($thumbPath)) {
            echo "video-thumb {$filename}\n";
            if (!$dryRun) {
                create_video_thumbnail($src, $thumbPath, $config['uploads']['thumb_width'], $config['uploads']['thumb_height']);
            }
            $fixedThumb++;
        }
        continue;
    }

    $needWeb = !is_file($webPath);
    $needThumb = !is_file($thumbPath);
    $webpWebPath   = preg_replace('/\.[^.]+$/', '.webp', $webPath);
    $webpThumbPath = preg_replace('/\.[^.]+$/', '.webp', $thumbPath);
    $needWebpWeb   = !is_file($webpWebPath);
    $needWebpThumb = !is_file($webpThumbPath);

    if ($needWeb || $needThumb || $needWebpWeb || $needWebpThumb) {
        echo "image " . ($needWeb && $needThumb ? 'thumb+web' : ($needWeb ? 'web' : 'thumb')) . ($needWebpWeb || $needWebpThumb ? '+webp' : '') . " {$filename}\n";
        if (!$dryRun) {
            // Generate all variants; the helper writes JPEG + WebP copies.
            create_image_variants($src, $webPath, $thumbPath, $config['uploads']['web_max_width'], $config['uploads']['thumb_width'], $config['uploads']['thumb_height']);
        }
        if ($needThumb) $fixedThumb++;
        if ($needWeb) $fixedWeb++;
    }
}

echo "done: thumbs={$fixedThumb} web={$fixedWeb} missing_originals={$missingOriginal} total=" . count($rows) . "\n";
