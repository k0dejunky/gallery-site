<?php

namespace App\Models;

use App\Core\Database;

/**
 * Star ratings (1-5) members leave on galleries. One per user per gallery.
 */
class GalleryRating
{
    public static function set(int $userId, int $galleryId, int $stars): void
    {
        $stars = max(1, min(5, $stars));

        Database::run(
            'INSERT INTO gallery_ratings (gallery_id, user_id, rating, created_at)
             VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE rating = VALUES(rating), created_at = NOW()',
            [$galleryId, $userId, $stars]
        );
    }

    public static function remove(int $userId, int $galleryId): void
    {
        Database::run('DELETE FROM gallery_ratings WHERE gallery_id = ? AND user_id = ?', [$galleryId, $userId]);
    }

    public static function averageFor(int $galleryId): float
    {
        $row = Database::run(
            'SELECT AVG(rating) AS avg_rating, COUNT(*) AS n FROM gallery_ratings WHERE gallery_id = ?',
            [$galleryId]
        )->fetch();

        return (float) ($row['avg_rating'] ?? 0);
    }

    public static function countFor(int $galleryId): int
    {
        $row = Database::run('SELECT COUNT(*) AS n FROM gallery_ratings WHERE gallery_id = ?', [$galleryId])->fetch();
        return (int) ($row['n'] ?? 0);
    }

    public static function userRating(int $userId, int $galleryId): ?int
    {
        $row = Database::run(
            'SELECT rating FROM gallery_ratings WHERE gallery_id = ? AND user_id = ?',
            [$galleryId, $userId]
        )->fetch();

        return $row === false ? null : (int) $row['rating'];
    }

    public static function all(): array
    {
        return Database::run('SELECT * FROM gallery_ratings ORDER BY created_at DESC')->fetchAll();
    }
}