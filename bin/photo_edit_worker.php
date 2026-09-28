<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use App\Core\Auth;
use App\Core\ImageEditor;
use App\Models\AuditLog;
use App\Models\Gallery;
use App\Models\PhotoJob;

if ($argc < 2) {
    fwrite(STDERR, "usage: photo_edit_worker.php <jobId>\n");
    exit(2);
}

$jobId = (int) $argv[1];
$job   = PhotoJob::findById($jobId);
if ($job === null) {
    fwrite(STDERR, "[photo-edit] job #{$jobId} not found\n");
    exit(1);
}

switch ($job['operation']) {
    case 'bulk_rotate':
        $ok = runBulkRotate((int) $job['id'], $job);
        exit($ok ? 0 : 1);

    case 'bulk_caption':
        $ok = runBulkCaption((int) $job['id'], $job);
        exit($ok ? 0 : 1);

    case 'bulk_delete':
        $ok = runBulkDelete((int) $job['id'], $job);
        exit($ok ? 0 : 1);

    default:
        PhotoJob::fail($jobId, 'Unknown operation: ' . $job['operation']);
        fwrite(STDERR, "[photo-edit] unknown operation\n");
        exit(1);
}

function runBulkRotate(int $jobId, array $job): bool
{
    $galleryId   = (int) $job['gallery_id'];
    $gallery     = Gallery::find($galleryId);
    $meta        = json_decode((string) ($job['metadata_json'] ?? '{}'), true) ?: [];
    $direction   = in_array($meta['direction'] ?? 'right', ['left', 'right'], true) ? $meta['direction'] : 'right';
    $selected    = array_values(array_unique(array_filter(
        array_map('intval', (array) ($meta['photo_ids'] ?? [])),
        static fn (int $id): bool => $id > 0
    )));

    if ($gallery === null || $selected === []) {
        PhotoJob::fail($jobId, 'Gallery or photo selection missing.');
        return false;
    }

    $config       = config('app.uploads');
    $photos       = [];
    foreach (Gallery::photos($galleryId) as $photo) {
        $photos[(int) $photo['id']] = $photo;
    }

    $done   = 0;
    $failed = 0;

    foreach ($selected as $photoId) {
        $photo = $photos[$photoId] ?? null;
        if ($photo === null || is_video($photo['filename'])) {
            $failed++;
            PhotoJob::markProgress($jobId, $done, $failed);
            continue;
        }

        $path = $config['dir'] . '/' . $photo['filename'];
        try {
            if (is_file($path) && ImageEditor::rotate($path, $direction)) {
                create_image_variants(
                    $path,
                    $config['dir'] . '/web_' . $photo['filename'],
                    $config['dir'] . '/thumb_' . $photo['filename'],
                    $config['web_max_width'],
                    $config['thumb_width'],
                    $config['thumb_height']
                );
                $done++;
                try {
                    AuditLog::record(
                        (int) $job['user_id'],
                        'update',
                        'photo',
                        $photoId,
                        'Bulk-rotated image (background job #' . $jobId . ')',
                        ['filename' => $photo['filename'], 'direction' => $direction]
                    );
                } catch (\Throwable $logError) {
                    error_log('[photo-edit] audit log failed for photo #' . $photoId . ': ' . $logError->getMessage());
                }
            } else {
                $failed++;
            }
        } catch (\Throwable $error) {
            error_log('[photo-edit] rotate failed for photo #' . $photoId . ': ' . $error->getMessage());
            $failed++;
        }
        PhotoJob::markProgress($jobId, $done, $failed);
    }

    PhotoJob::complete($jobId);
    error_log(sprintf('[photo-edit] bulk rotate #%d done: %d rotated, %d skipped', $jobId, $done, $failed));
    return true;
}

function runBulkCaption(int $jobId, array $job): bool
{
    $galleryId = (int) $job['gallery_id'];
    $meta      = json_decode((string) ($job['metadata_json'] ?? '{}'), true) ?: [];
    $caption   = trim((string) ($meta['caption'] ?? ''));
    $selected  = array_values(array_unique(array_filter(
        array_map('intval', (array) ($meta['photo_ids'] ?? [])),
        static fn (int $id): bool => $id > 0
    )));

    if ($galleryId <= 0 || $selected === []) {
        PhotoJob::fail($jobId, 'Gallery or photo selection missing.');
        return false;
    }

    $done = 0;
    foreach ($selected as $photoId) {
        $photo = Photo::find($photoId);
        if ($photo === null) {
            continue;
        }
        Photo::updateCaption($photoId, $caption, (string) ($photo['link'] ?? ''));
        $done++;
        PhotoJob::markProgress($jobId, $done, 0);
    }

    PhotoJob::complete($jobId);
    error_log(sprintf('[photo-edit] bulk caption #%d done: %d captioned', $jobId, $done));
    return true;
}

function runBulkDelete(int $jobId, array $job): bool
{
    $galleryId = (int) $job['gallery_id'];
    $meta      = json_decode((string) ($job['metadata_json'] ?? '{}'), true) ?: [];
    $selected  = array_values(array_unique(array_filter(
        array_map('intval', (array) ($meta['photo_ids'] ?? [])),
        static fn (int $id): bool => $id > 0
    )));

    if ($galleryId <= 0 || $selected === []) {
        PhotoJob::fail($jobId, 'Gallery or photo selection missing.');
        return false;
    }

    $done = 0;
    foreach ($selected as $photoId) {
        $removed = \App\Core\Database::run(
            'DELETE FROM gallery_photo WHERE gallery_id = ? AND photo_id = ?',
            [$galleryId, $photoId]
        )->rowCount() > 0;

        if ($removed) {
            Photo::deleteIfOrphan($photoId);
            $done++;
            PhotoJob::markProgress($jobId, $done, 0);
        }
    }

    \App\Core\Cache::bump('gallery');
    \App\Core\Cache::bump('media');
    PhotoJob::complete($jobId);
    error_log(sprintf('[photo-edit] bulk delete #%d done: %d removed', $jobId, $done));
    return true;
}
