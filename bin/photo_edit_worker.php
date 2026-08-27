<?php

declare(strict_types=1);

require __DIR__ . '/../app/Core/helpers.php';
spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) return;
    $path = __DIR__ . '/../app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) require $path;
});

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
