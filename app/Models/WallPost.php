<?php

namespace App\Models;

use App\Core\Database;

/**
 * Creator wall feed. Posts are authored on the admin Engagement page and
 * read/commented on by members. Soft-deleted so a mistaken removal is
 * recoverable.
 */
class WallPost
{
    public static function allPublished(int $limit = 30): array
    {
        return Database::run(
            'SELECT * FROM wall_posts
             WHERE deleted_at IS NULL
             ORDER BY pinned DESC, created_at DESC, id DESC
             LIMIT ' . max(1, (int) $limit)
        )->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $row = Database::run('SELECT * FROM wall_posts WHERE id = ? AND deleted_at IS NULL', [$id])->fetch();
        return $row === false ? null : $row;
    }

    public static function create(int $userId, string $body, bool $pinned): int
    {
        Database::run(
            'INSERT INTO wall_posts (body, pinned) VALUES (?, ?)',
            [$body, $pinned ? 1 : 0]
        );

        $id = (int) Database::connection()->lastInsertId();

        if ($pinned) {
            Database::run('UPDATE wall_posts SET pinned = 0 WHERE id <> ? AND pinned = 1', [$id]);
        }

        return $id;
    }

    public static function delete(int $id): void
    {
        Database::run('UPDATE wall_posts SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL', [$id]);
    }

    public static function recentCount(int $days = 7): int
    {
        $row = Database::run(
            'SELECT COUNT(*) AS n FROM wall_posts
             WHERE deleted_at IS NULL AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)',
            [(int) $days]
        )->fetch();

        return (int) ($row['n'] ?? 0);
    }
}