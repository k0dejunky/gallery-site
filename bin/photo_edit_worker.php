<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use App\Core\Auth;
use App\Core\ImageEditor;
use App\Models\AuditLog;
use App\Models\Gallery;
use App\Models\Photo;
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

    case 'bulk_watermark':
        $ok = runBulkWatermark((int) $job['id'], $job);
        exit($ok ? 0 : 1);

    default:
        PhotoJob::fail($jobId, 'Unknown operation: ' . $job['operation']);
        fwrite(STDERR, "[photo-edit] unknown operation\n");
        exit(1);
}

/**
 * Stamp the theme's title image into every selected photo, bottom-right, at
 * WATERMARK_OPACITY. Images are edited in place and have their web/thumb
 * variants rebuilt; videos are re-encoded with the logo burned in and the
 * original is preserved under uploads/backups/watermark/ so the job can be
 * undone. The whole job fails (leaving every file untouched) when the theme's
 * title image cannot be resolved, so an operator never has to guess whether a
 * partially watermarked gallery is complete.
 */
function runBulkWatermark(int $jobId, array $job): bool
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

    // The logo was snapshotted when the job was queued; a theme edit since then
    // must not silently change what a running job stamps.
    $logoPath = is_string($meta['logo_path'] ?? null) ? (string) $meta['logo_path'] : '';

    if ($logoPath === '' || !is_file($logoPath)) {
        PhotoJob::fail($jobId, 'The site title image is missing - add one under Admin > Theme and retry.');
        return false;
    }

    $config  = config('app.uploads');
    $uploads = rtrim((string) $config['dir'], '/');
    $backupDir = $uploads . '/backups/watermark/' . $galleryId . '/' . $jobId;

    $done   = 0;
    $failed = 0;

    foreach ($selected as $photoId) {
        $photo = Photo::find($photoId);

        if ($photo === null) {
            $failed++;
            PhotoJob::markProgress($jobId, $done, $failed);
            continue;
        }

        $path = $uploads . '/' . $photo['filename'];

        if (!is_file($path)) {
            $failed++;
            PhotoJob::markProgress($jobId, $done, $failed);
            continue;
        }

        try {
            // Every original is preserved first so watermarkUndo() can restore
            // it - images are edited in place, so they need the same protection
            // as a re-encoded video.
            if (!is_dir($backupDir)) {
                @mkdir($backupDir, 0775, true);
            }

            $backup = $backupDir . '/' . $photo['filename'];

            if (is_video($photo['filename'])) {
                // Burn into a copy with the SAME extension (so the container
                // matches what the site serves) before touching the original.
                // Dropping the extension would stop ffmpeg from picking the
                // right muxer and the encode would fail.
                $ext = (string) pathinfo($photo['filename'], PATHINFO_EXTENSION);
                $tmp = $path . '.wm-' . bin2hex(random_bytes(4)) . '.' . $ext;

                if (!burn_video_watermark($path, $tmp, $logoPath)) {
                    @unlink($tmp);
                    throw new RuntimeException('video watermark encode failed');
                }

                if (!@copy($path, $backup) || !@rename($tmp, $path)) {
                    // The original is still in place (the burn wrote elsewhere):
                    // drop the half-made copy and leave the file alone.
                    @unlink($tmp);
                    @unlink($backup);
                    throw new RuntimeException('could not swap the watermarked video into place');
                }

                PhotoJob::addWatermarkBackup($jobId, $photoId, $backup);

                // The re-encode can land on a different GOP/frame than the old
                // thumbnail was cut from, so re-pick it from the watermarked file.
                create_video_thumbnail(
                    $path,
                    $uploads . '/thumb_' . $photo['filename'],
                    $config['thumb_width'],
                    $config['thumb_height']
                );
            } else {
                if (!@copy($path, $backup)) {
                    throw new RuntimeException('could not back up the original');
                }

                if (!ImageEditor::watermarkLogo($path, $logoPath)) {
                    @unlink($backup);
                    throw new RuntimeException('image watermark failed');
                }

                create_image_variants(
                    $path,
                    $uploads . '/web_' . $photo['filename'],
                    $uploads . '/thumb_' . $photo['filename'],
                    $config['web_max_width'],
                    $config['thumb_width'],
                    $config['thumb_height']
                );

                PhotoJob::addWatermarkBackup($jobId, $photoId, $backup);
            }

            $done++;

            try {
                AuditLog::record(
                    (int) $job['user_id'],
                    'update',
                    'photo',
                    $photoId,
                    'Watermarked image/video (background job #' . $jobId . ')',
                    ['filename' => $photo['filename']]
                );
            } catch (\Throwable $logError) {
                error_log('[photo-edit] audit log failed for photo #' . $photoId . ': ' . $logError->getMessage());
            }
        } catch (\Throwable $error) {
            error_log('[photo-edit] watermark failed for photo #' . $photoId . ': ' . $error->getMessage());
            $failed++;
        }

        PhotoJob::markProgress($jobId, $done, $failed);
    }

    \App\Core\Cache::bump('gallery');
    \App\Core\Cache::bump('media');

    PhotoJob::complete($jobId);
    error_log(sprintf('[photo-edit] watermark #%d done: %d watermarked, %d failed', $jobId, $done, $failed));

    return true;
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
            // Bake the EXIF orientation into the pixels before rotating (see
            // PhotoController::rotate): the viewer shows the tag-rotated
            // image, so a raw-pixel rotation looks like the wrong direction.
            if (is_file($path) && ImageEditor::normalize($path) && ImageEditor::rotate($path, $direction)) {
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
