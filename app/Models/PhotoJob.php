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

    /** Latest job for a gallery (used to show status / disable buttons while running). */
    public static function latestForGallery(int $galleryId): ?array
    {
        return Database::run(
            'SELECT * FROM photo_edit_jobs WHERE gallery_id = ? ORDER BY id DESC LIMIT 1',
            [$galleryId]
        )->fetch() ?: null;
    }

    /** Latest job for an admin user. */
    public static function latestForUser(int $userId): ?array
    {
        return Database::run(
            'SELECT * FROM photo_edit_jobs WHERE user_id = ? ORDER BY id DESC LIMIT 1',
            [$userId]
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
