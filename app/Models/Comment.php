<?php

namespace App\Models;

use App\Core\Database;

/**
 * Member comments on galleries and wall posts. Member-authored; removal is an
 * admin (moderation) action. Soft-deleted.
 */
class Comment
{
    public const TYPE_GALLERY = 'gallery';
    public const TYPE_WALL    = 'wall_post';

    private const TYPES = [self::TYPE_GALLERY, self::TYPE_WALL];

    public static function add(int $userId, string $type, int $commentableId, string $body): int
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Unknown comment type "{$type}"');
        }

        Database::run(
            'INSERT INTO comments (user_id, commentable_type, commentable_id, body, created_at)
             VALUES (?, ?, ?, ?, NOW())',
            [$userId, $type, $commentableId, $body]
        );

        return (int) Database::connection()->lastInsertId();
    }

    public static function forEntity(string $type, int $commentableId, int $limit = 50): array
    {
        return Database::run(
            'SELECT c.*, u.role, u.created_at AS user_created
             FROM comments c
             JOIN users u ON u.id = c.user_id
             WHERE c.commentable_type = ? AND c.commentable_id = ? AND c.deleted_at IS NULL
             ORDER BY c.created_at ASC, c.id ASC
             LIMIT ' . max(1, (int) $limit),
            [$type, $commentableId]
        )->fetchAll();
    }

    public static function countFor(string $type, int $commentableId): int
    {
        $row = Database::run(
            'SELECT COUNT(*) AS n FROM comments
             WHERE commentable_type = ? AND commentable_id = ? AND deleted_at IS NULL',
            [$type, $commentableId]
        )->fetch();

        return (int) ($row['n'] ?? 0);
    }

    public static function find(int $id): ?array
    {
        $row = Database::run('SELECT * FROM comments WHERE id = ?', [$id])->fetch();
        return $row === false ? null : $row;
    }

    /** Newest comment on an entity written by a user other than $excludeUserId (reply target). */
    public static function previousAuthor(string $type, int $commentableId, int $excludeUserId): ?array
    {
        $row = Database::run(
            'SELECT user_id FROM comments
             WHERE commentable_type = ? AND commentable_id = ? AND deleted_at IS NULL
               AND user_id <> ?
             ORDER BY created_at DESC, id DESC
             LIMIT 1',
            [$type, $commentableId, $excludeUserId]
        )->fetch();

        return $row === false ? null : $row;
    }

    /** Whether a role is a staff account (their comments render as "Site team"). */
    public static function isStaff(string $role): bool
    {
        return in_array($role, ['super_admin', 'admin', 'editor', 'moderator', 'viewer'], true);
    }

    public static function delete(int $id): void
    {
        Database::run('UPDATE comments SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL', [$id]);
    }

    public static function recent(int $limit = 50): array
    {
        return Database::run(
            'SELECT c.*, u.role,
                    COALESCE(g.title, wp.body) AS entity_label
             FROM comments c
             JOIN users u ON u.id = c.user_id
             LEFT JOIN galleries g ON g.id = c.commentable_id AND c.commentable_type = \'gallery\'
             LEFT JOIN wall_posts wp ON wp.id = c.commentable_id AND c.commentable_type = \'wall_post\'
             WHERE c.deleted_at IS NULL
             ORDER BY c.created_at DESC, c.id DESC
             LIMIT ' . max(1, (int) $limit)
        )->fetchAll();
    }

    public static function all(): array
    {
        return Database::run(
            'SELECT * FROM comments WHERE deleted_at IS NULL ORDER BY created_at DESC, id DESC'
        )->fetchAll();
    }
}