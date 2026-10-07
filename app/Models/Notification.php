<?php

namespace App\Models;

use App\Core\Database;

/**
 * In-app notifications hub. Admins/features push rows to members via
 * broadcastToMembers(); per-user lifecycle rows (new wall posts, replies,
 * unlocks) go through add().
 */
class Notification
{
    public static function add(int $userId, string $type, string $title, ?string $body, string $url = ''): int
    {
        Database::run(
            'INSERT INTO notifications (user_id, type, title, body, url, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())',
            [$userId, $type, $title, $body, $url]
        );

        return (int) Database::connection()->lastInsertId();
    }

    /**
     * Fan out one row to every active member (not to staff accounts). Uses a
     * single INSERT..SELECT; content rows are short, so this stays cheap.
     */
    public static function broadcastToMembers(string $type, string $title, ?string $body, string $url): int
    {
        return (int) Database::run(
            'INSERT INTO notifications (user_id, type, title, body, url, created_at)
             SELECT id, ?, ?, ?, ?, NOW()
             FROM users
             WHERE status = \'active\' AND role = \'user\'',
            [$type, $title, $body, $url]
        )->rowCount();
    }

    public static function unreadCount(int $userId): int
    {
        $row = Database::run(
            'SELECT COUNT(*) AS n FROM notifications WHERE user_id = ? AND read_at IS NULL',
            [$userId]
        )->fetch();

        return (int) ($row['n'] ?? 0);
    }

    public static function forUser(int $userId, int $limit = 50): array
    {
        return Database::run(
            'SELECT * FROM notifications
             WHERE user_id = ?
             ORDER BY created_at DESC, id DESC
             LIMIT ' . max(1, (int) $limit),
            [$userId]
        )->fetchAll();
    }

    public static function markRead(int $id, int $userId): void
    {
        Database::run(
            'UPDATE notifications SET read_at = COALESCE(read_at, NOW())
             WHERE id = ? AND user_id = ?',
            [$id, $userId]
        );
    }

    public static function forUserOne(int $userId, int $id): ?array
    {
        $row = Database::run(
            'SELECT * FROM notifications WHERE id = ? AND user_id = ? LIMIT 1',
            [$id, $userId]
        )->fetch();

        return $row === false ? null : $row;
    }

    public static function markAllRead(int $userId): void
    {
        Database::run(
            'UPDATE notifications SET read_at = COALESCE(read_at, NOW())
             WHERE user_id = ? AND read_at IS NULL',
            [$userId]
        );
    }
}