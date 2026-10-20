<?php

namespace App\Models;

use App\Core\Database;

/**
 * Where a member stopped watching.
 *
 * The player's resume position used to live in localStorage only: per
 * device, per browser, invisible to the site. This table is the server-side
 * copy, so the position follows the member to another device and the
 * dashboard can show what they were watching.
 */
class MediaProgress
{
    /**
     * Remember where the member stopped.
     */
    public static function save(int $userId, int $photoId, float $position, ?float $duration = null): void
    {
        if ($userId <= 0 || $photoId <= 0 || $position < 0) {
            return;
        }

        Database::run(
            'INSERT INTO media_progress (user_id, photo_id, position_seconds, duration_seconds, updated_at)
             VALUES (?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE position_seconds = VALUES(position_seconds),
                                     duration_seconds = VALUES(duration_seconds),
                                     updated_at = NOW()',
            [$userId, $photoId, round($position, 2), $duration !== null ? round($duration, 2) : null]
        );
    }

    /**
     * The position for one video, or 0.
     */
    public static function position(int $userId, int $photoId): float
    {
        if ($userId <= 0 || $photoId <= 0) {
            return 0.0;
        }

        $row = Database::run(
            'SELECT position_seconds FROM media_progress WHERE user_id = ? AND photo_id = ?',
            [$userId, $photoId]
        )->fetch();

        return $row === false ? 0.0 : (float) $row['position_seconds'];
    }

    /**
     * The videos the member was watching, most recent first.
     *
     * @return array<int, array> the rows with the photo joined in
     */
    public static function recent(int $userId, int $limit = 8): array
    {
        if ($userId <= 0) {
            return [];
        }

        return Database::run(
            'SELECT mp.photo_id, mp.position_seconds, mp.duration_seconds, mp.updated_at,
                    p.filename, p.caption, p.is_video
             FROM media_progress mp
             INNER JOIN photos p ON p.id = mp.photo_id
             WHERE mp.user_id = ? AND mp.position_seconds > 5
             ORDER BY mp.updated_at DESC
             LIMIT ' . max(1, (int) $limit),
            [$userId]
        )->fetchAll();
    }

    /**
     * Forget the progress of a video (it was finished, or the member asked).
     */
    public static function forget(int $userId, int $photoId): void
    {
        Database::run(
            'DELETE FROM media_progress WHERE user_id = ? AND photo_id = ?',
            [$userId, $photoId]
        );
    }

    /**
     * Drop the progress of videos that no longer exist.
     */
    public static function pruneOrphans(): int
    {
        return (int) Database::run(
            'DELETE mp FROM media_progress mp
             LEFT JOIN photos p ON p.id = mp.photo_id
             WHERE p.id IS NULL'
        )->rowCount();
    }
}
