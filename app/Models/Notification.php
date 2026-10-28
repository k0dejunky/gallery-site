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

        // The unread badge is cached for a minute, so every write that
        // changes it has to invalidate that cache.
        \App\Core\Cache::bump('notifications');

        return (int) Database::connection()->lastInsertId();
    }

    /**
     * Fan out one row to every active member (not to staff accounts). Uses a
     * single INSERT..SELECT; content rows are short, so this stays cheap.
     */
    public static function broadcastToMembers(string $type, string $title, ?string $body, string $url): int
    {
        $rows = (int) Database::run(
            'INSERT INTO notifications (user_id, type, title, body, url, created_at)
             SELECT id, ?, ?, ?, ?, NOW()
             FROM users
             WHERE status = \'active\' AND role = \'user\'',
            [$type, $title, $body, $url]
        )->rowCount();

        if ($rows > 0) {
            \App\Core\Cache::bump('notifications');
        }

        return $rows;
    }

    public static function unreadCount(int $userId): int
    {
        // The badge is on every member page, and the count only changes when
        // something is added or read - so it is cached for a minute and
        // recomputed on every write (see add()/markRead()/markAllRead()).
        return (int) \App\Core\Cache::rememberGen('notifications', 'unread:u' . $userId, 60, static function () use ($userId): string {
            $row = Database::run(
                'SELECT COUNT(*) AS n FROM notifications WHERE user_id = ? AND read_at IS NULL',
                [$userId]
            )->fetch();

            return (string) (int) ($row['n'] ?? 0);
        });
    }

    public static function forUser(int $userId, int $limit = 50): array
    {
        return Database::run(
            'SELECT n.*, TIMESTAMPDIFF(SECOND, n.created_at, NOW()) AS age_seconds
             FROM notifications n
             WHERE n.user_id = ?
             ORDER BY n.created_at DESC, n.id DESC
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

        \App\Core\Cache::bump('notifications');
    }

    public static function forUserOne(int $userId, int $id): ?array
    {
        $row = Database::run(
            'SELECT * FROM notifications WHERE id = ? AND user_id = ? LIMIT 1',
            [$id, $userId]
        )->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Mark a set of notifications read in ONE query.
     *
     * The wall SHOWS the inbox, so opening it is reading it: the nav badge
     * has to follow the attention, not the clicks. Marking them one by one
     * would be 30 queries on every wall view.
     */
    public static function markManyRead(array $ids, int $userId): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if ($ids === [] || $userId <= 0) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $changed = Database::run(
            'UPDATE notifications SET read_at = COALESCE(read_at, NOW())
             WHERE id IN (' . $placeholders . ') AND user_id = ? AND read_at IS NULL',
            array_merge($ids, [$userId])
        )->rowCount();

        if ($changed > 0) {
            \App\Core\Cache::bump('notifications');
        }

        return $changed;
    }

    public static function markAllRead(int $userId): void
    {
        Database::run(
            'UPDATE notifications SET read_at = COALESCE(read_at, NOW())
             WHERE user_id = ? AND read_at IS NULL',
            [$userId]
        );

        \App\Core\Cache::bump('notifications');
    }
}