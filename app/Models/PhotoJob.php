<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Queued photo-edit jobs (currently bulk rotate) processed by the supervised
 * photo_edit_worker. Moving long-running image work off the HTTP request avoids
 * timeouts when editing many large files.
 */
class PhotoJob
{
    private const MAX_ATTEMPTS = 3;

    public static function createBulkRotate(int $userId, int $galleryId, string $direction, array $photoIds): int
    {
        Database::run(
            'INSERT INTO photo_edit_jobs (user_id, gallery_id, operation, status, total, metadata_json)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                $userId,
                $galleryId,
                'bulk_rotate',
                'queued',
                count($photoIds),
                json_encode(['direction' => $direction, 'photo_ids' => $photoIds]),
            ]
        );
        return (int) Database::connection()->lastInsertId();
    }

    /** Queue a bulk-caption job for the selected photos. */
    public static function createBulkCaption(int $userId, int $galleryId, string $caption, array $photoIds): int
    {
        Database::run(
            'INSERT INTO photo_edit_jobs (user_id, gallery_id, operation, status, total, metadata_json)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                $userId,
                $galleryId,
                'bulk_caption',
                'queued',
                count($photoIds),
                json_encode(['caption' => $caption, 'photo_ids' => array_values($photoIds)]),
            ]
        );

        return (int) Database::connection()->lastInsertId();
    }

    /** Queue a bulk-delete job for the selected photos. */
    public static function createBulkDelete(int $userId, int $galleryId, array $photoIds): int
    {
        Database::run(
            'INSERT INTO photo_edit_jobs (user_id, gallery_id, operation, status, total, metadata_json)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                $userId,
                $galleryId,
                'bulk_delete',
                'queued',
                count($photoIds),
                json_encode(['photo_ids' => array_values($photoIds)]),
            ]
        );

        return (int) Database::connection()->lastInsertId();
    }

    /**
     * Queue a watermark job for the selected photos (images AND videos). The
     * logo path is snapshotted at queue time so a theme change mid-run cannot
     * produce a gallery watermarked with two different logos. The worker also
     * records each video's backup path here as it works, which is what
     * watermarkUndo() replays.
     */
    public static function createBulkWatermark(int $userId, int $galleryId, array $photoIds): int
    {
        Database::run(
            'INSERT INTO photo_edit_jobs (user_id, gallery_id, operation, status, total, metadata_json)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                $userId,
                $galleryId,
                'bulk_watermark',
                'queued',
                count($photoIds),
                json_encode([
                    'photo_ids' => array_values($photoIds),
                    'logo_path' => watermark_logo_path(),
                    'backups'   => [],
                ]),
            ]
        );

        return (int) Database::connection()->lastInsertId();
    }

    /** Record the backups taken so far for a watermark job (drives undo). */
    public static function addWatermarkBackup(int $id, int $photoId, string $backupPath): void
    {
        $row = self::findById($id);

        if ($row === null) {
            return;
        }

        $meta = json_decode((string) ($row['metadata_json'] ?? '{}'), true) ?: [];
        $meta['backups'][] = ['photo_id' => $photoId, 'path' => $backupPath];

        Database::run('UPDATE photo_edit_jobs SET metadata_json = ? WHERE id = ?', [
            json_encode($meta),
            $id,
        ]);
    }

    /** Latest job for a gallery (used to show status / disable buttons while running). */
    public static function latestForGallery(int $galleryId): ?array
    {
        return Database::run(
            'SELECT * FROM photo_edit_jobs WHERE gallery_id = ? ORDER BY id DESC LIMIT 1',
            [$galleryId]
        )->fetch() ?: null;
    }

    public static function findById(int $id): ?array
    {
        return Database::run('SELECT * FROM photo_edit_jobs WHERE id = ?', [$id])->fetch() ?: null;
    }

    /** Claim the oldest queued job for the supervised worker. */
    public static function claimNext(): ?int
    {
        $db = Database::connection();
        $db->beginTransaction();
        try {
            $job = Database::run(
                'SELECT id FROM photo_edit_jobs WHERE status = ? ORDER BY created_at ASC, id ASC LIMIT 1 FOR UPDATE',
                ['queued']
            )->fetch();
            if (!$job) {
                $db->commit();
                return null;
            }
            $id = (int) $job['id'];
            Database::run(
                'UPDATE photo_edit_jobs SET status = ?, progress = 1, attempts = attempts + 1, started_at = CURRENT_TIMESTAMP, error = NULL WHERE id = ? AND status = ?',
                ['running', $id, 'queued']
            );
            $db->commit();
            return $id;
        } catch (\Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }
    }

    public static function markProgress(int $id, int $done, int $failed): void
    {
        Database::run(
            'UPDATE photo_edit_jobs SET done = ?, failed = ?, progress = LEAST(100, GREATEST(1, ROUND((done + failed) * 100.0 / NULLIF(total, 0)))) WHERE id = ?',
            [$done, $failed, $id]
        );
    }

    public static function complete(int $id): void
    {
        Database::run(
            "UPDATE photo_edit_jobs SET status = 'completed', finished_at = CURRENT_TIMESTAMP WHERE id = ?",
            [$id]
        );
    }

    public static function fail(int $id, string $error): void
    {
        Database::run(
            "UPDATE photo_edit_jobs SET status = 'failed', error = ?, finished_at = CURRENT_TIMESTAMP WHERE id = ?",
            [$error, $id]
        );
    }

    public static function requeue(int $id): void
    {
        Database::run(
            "UPDATE photo_edit_jobs SET status = 'queued', progress = 0, started_at = NULL, finished_at = NULL WHERE id = ? AND status = 'failed' AND attempts < ?",
            [$id, self::MAX_ATTEMPTS]
        );
    }

    public static function recoverStale(): void
    {
        Database::run(
            "UPDATE photo_edit_jobs SET status = 'queued', progress = 0, started_at = NULL, finished_at = NULL WHERE status = 'running' AND started_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 6 HOUR) AND attempts < ?",
            [self::MAX_ATTEMPTS]
        );
    }
}
