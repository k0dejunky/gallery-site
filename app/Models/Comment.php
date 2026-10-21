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
    public const TYPE_PHOTO   = 'photo';

    private const TYPES = [self::TYPE_GALLERY, self::TYPE_WALL, self::TYPE_PHOTO];

    public static function add(int $userId, string $type, int $commentableId, string $body): int
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Unknown comment type "' . $type . '"');
        }

        Database::run(
            'INSERT INTO comments (user_id, commentable_type, commentable_id, body, created_at)
             VALUES (?, ?, ?, ?, NOW())',
            [$userId, $type, $commentableId, $body]
        );

        return (int) Database::connection()->lastInsertId();
    }

    /**
     * Delete the comments of an entity that is going away.
     *
     * comments.commentable_id is polymorphic (it cannot be a foreign key),
     * so a deleted gallery or photo left its comments behind - and the
     * gallery's comment count included comments for a gallery that no
     * longer exists.
     */
    public static function deleteForEntity(string $type, int $commentableId): int
    {
        if (!in_array($type, self::TYPES, true) || $commentableId <= 0) {
            return 0;
        }

        return (int) Database::run(
            'DELETE FROM comments WHERE commentable_type = ? AND commentable_id = ?',
            [$type, $commentableId]
        )->rowCount();
    }

    public static function forEntity(string $type, int $commentableId, int $limit = 50): array
    {
        return Database::run(
            'SELECT c.*, u.role, u.created_at AS user_created
             FROM comments c
             JOIN users u ON u.id = c.user_id
             WHERE c.commentable_type = ? AND c.commentable_id = ? AND c.deleted_at IS NULL
               AND c.parent_id IS NULL
             ORDER BY c.created_at ASC, c.id ASC
             LIMIT ' . max(1, (int) $limit),
            [$type, $commentableId]
        )->fetchAll();
    }

    /**
     * The replies to one comment, oldest first.
     */
    public static function replies(int $commentId): array
    {
        return self::repliesFor([$commentId])[$commentId] ?? [];
    }

    /**
     * The replies of a set of comments in ONE query.
     *
     * @return array<int, list<array>> comment id => the replies
     */
    public static function repliesFor(array $commentIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $commentIds), static fn (int $id): bool => $id > 0));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = Database::run(
            'SELECT c.*, u.role
             FROM comments c
             JOIN users u ON u.id = c.user_id
             WHERE c.parent_id IN (' . $placeholders . ') AND c.deleted_at IS NULL
             ORDER BY c.created_at ASC, c.id ASC',
            $ids
        )->fetchAll();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['parent_id']][] = $row;
        }

        return $out;
    }

    /**
     * Answer a comment. The reply belongs to the same thread, so it goes
     * to the parent comment's entity.
     */
    public static function addReply(int $userId, int $parentId, string $body): int
    {
        $parent = Database::run(
            'SELECT id, commentable_type, commentable_id FROM comments WHERE id = ? AND deleted_at IS NULL',
            [$parentId]
        )->fetch();

        if ($parent === false) {
            throw new \RuntimeException('That comment is gone.');
        }

        Database::run(
            'INSERT INTO comments (user_id, commentable_type, commentable_id, parent_id, body, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())',
            [$userId, (string) $parent['commentable_type'], (int) $parent['commentable_id'], $parentId, $body]
        );

        return (int) Database::connection()->lastInsertId();
    }

    /**
     * Toggle a member's reaction on a comment. Returns the new state.
     */
    public static function toggleReaction(int $userId, int $commentId, string $reaction): bool
    {
        $reaction = trim($reaction);
        if ($userId <= 0 || $commentId <= 0 || $reaction === '' || mb_strlen($reaction) > 16) {
            return false;
        }

        $exists = (int) Database::run(
            'SELECT COUNT(*) FROM comment_reactions WHERE comment_id = ? AND user_id = ? AND reaction = ?',
            [$commentId, $userId, $reaction]
        )->fetchColumn();

        if ($exists > 0) {
            Database::run(
                'DELETE FROM comment_reactions WHERE comment_id = ? AND user_id = ? AND reaction = ?',
                [$commentId, $userId, $reaction]
            );
            return false;
        }

        Database::run(
            'INSERT IGNORE INTO comment_reactions (comment_id, user_id, reaction) VALUES (?, ?, ?)',
            [$commentId, $userId, $reaction]
        );

        return true;
    }

    /**
     * The reactions of a set of comments: comment id => reaction => count.
     */
    public static function reactionsFor(array $commentIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $commentIds), static fn (int $id): bool => $id > 0));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = Database::run(
            'SELECT comment_id, reaction, COUNT(*) AS n
             FROM comment_reactions
             WHERE comment_id IN (' . $placeholders . ')
             GROUP BY comment_id, reaction',
            $ids
        )->fetchAll();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['comment_id']][(string) $row['reaction']] = (int) $row['n'];
        }

        return $out;
    }

    /**
     * The reactions the given member left on a set of comments.
     */
    public static function myReactions(int $userId, array $commentIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $commentIds), static fn (int $id): bool => $id > 0));
        if ($userId <= 0 || $ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = Database::run(
            'SELECT comment_id, reaction FROM comment_reactions
             WHERE user_id = ? AND comment_id IN (' . $placeholders . ')',
            array_merge([$userId], $ids)
        )->fetchAll();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['comment_id']][] = (string) $row['reaction'];
        }

        return $out;
    }

    /**
     * The comments of MANY entities in one query.
     *
     * The wall used to call forEntity() once per post - 20 queries for a
     * 20-post feed - and the admin engagement table called countFor() once
     * per post. Both now ask once.
     *
     * @param int[] $entityIds
     * @return array<int,array<int,array<string,mixed>>>  [entityId => comments]
     */
    public static function forEntities(string $type, array $entityIds, int $limit = 50): array
    {
        $entityIds = array_values(array_unique(array_map('intval', $entityIds)));
        $entityIds = array_values(array_filter($entityIds, static fn (int $id): bool => $id > 0));

        if ($entityIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($entityIds), '?'));

        $rows = Database::run(
            "SELECT c.*, u.role, u.created_at AS user_created
             FROM comments c
             JOIN users u ON u.id = c.user_id
             WHERE c.commentable_type = ? AND c.commentable_id IN ($placeholders)
               AND c.deleted_at IS NULL AND c.parent_id IS NULL
             ORDER BY c.commentable_id ASC, c.created_at ASC, c.id ASC",
            array_merge([$type], $entityIds)
        )->fetchAll();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['commentable_id']][] = $row;
        }

        // The per-entity limit, applied in PHP so one entity cannot take
        // the whole window.
        foreach ($out as $id => $list) {
            if (count($list) > $limit) {
                $out[$id] = array_slice($list, 0, $limit);
            }
        }

        return $out;
    }

    /**
     * The comment count of MANY entities in one query.
     *
     * @param int[] $entityIds
     * @return array<int,int>  [entityId => count]
     */
    public static function countsFor(string $type, array $entityIds): array
    {
        $entityIds = array_values(array_unique(array_map('intval', $entityIds)));
        $entityIds = array_values(array_filter($entityIds, static fn (int $id): bool => $id > 0));

        if ($entityIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($entityIds), '?'));

        $rows = Database::run(
            "SELECT commentable_id, COUNT(*) AS n FROM comments
             WHERE commentable_type = ? AND commentable_id IN ($placeholders) AND deleted_at IS NULL
             GROUP BY commentable_id",
            array_merge([$type], $entityIds)
        )->fetchAll();

        $out = [];
        foreach ($entityIds as $id) {
            $out[$id] = 0;
        }
        foreach ($rows as $row) {
            $out[(int) $row['commentable_id']] = (int) $row['n'];
        }

        return $out;
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

    /**
     * The gallery's unified comment thread: comments posted directly on the
     * gallery plus comments left on wall posts that promote that gallery
     * (WallPost.gallery_id). A conversation stays in one place - the wall
     * post or the gallery - and is surfaced in both.
     *
     * @return list<array{source: 'gallery'|'wall_post'}>
     */
    public static function forGallery(int $galleryId, int $limit = 50): array
    {
        return Database::run(
            'SELECT c.*, u.role, u.created_at AS user_created,
                    IF(c.commentable_type = \'wall_post\', \'wall_post\', \'gallery\') AS source
             FROM comments c
             JOIN users u ON u.id = c.user_id
             LEFT JOIN wall_posts wp ON wp.id = c.commentable_id AND c.commentable_type = \'wall_post\'
             WHERE c.deleted_at IS NULL
               AND (
                   (c.commentable_type = \'gallery\' AND c.commentable_id = ?)
                   OR (c.commentable_type = \'wall_post\' AND wp.gallery_id = ?)
               )
             ORDER BY c.created_at ASC, c.id ASC
             LIMIT ' . max(1, (int) $limit),
            [$galleryId, $galleryId]
        )->fetchAll();
    }

    public static function countForGallery(int $galleryId): int
    {
        $row = Database::run(
            'SELECT COUNT(*) AS n
             FROM comments c
             LEFT JOIN wall_posts wp ON wp.id = c.commentable_id AND c.commentable_type = \'wall_post\'
             WHERE c.deleted_at IS NULL
               AND (
                   (c.commentable_type = \'gallery\' AND c.commentable_id = ?)
                   OR (c.commentable_type = \'wall_post\' AND wp.gallery_id = ?)
               )',
            [$galleryId, $galleryId]
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

    /** Newest prior author across a gallery's unified thread (gallery + its promoting wall posts). */
    public static function previousAuthorForGallery(int $galleryId, int $excludeUserId): ?array
    {
        $row = Database::run(
            'SELECT c.user_id
             FROM comments c
             LEFT JOIN wall_posts wp ON wp.id = c.commentable_id AND c.commentable_type = \'wall_post\'
             WHERE c.deleted_at IS NULL AND c.user_id <> ?
               AND (
                   (c.commentable_type = \'gallery\' AND c.commentable_id = ?)
                   OR (c.commentable_type = \'wall_post\' AND wp.gallery_id = ?)
               )
             ORDER BY c.created_at DESC, c.id DESC
             LIMIT 1',
            [$excludeUserId, $galleryId, $galleryId]
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
                    COALESCE(g.title, wp.body, ph.caption) AS entity_label,
                    ph.filename AS photo_filename
             FROM comments c
             JOIN users u ON u.id = c.user_id
             LEFT JOIN galleries g ON g.id = c.commentable_id AND c.commentable_type = \'gallery\'
             LEFT JOIN wall_posts wp ON wp.id = c.commentable_id AND c.commentable_type = \'wall_post\'
             LEFT JOIN photos ph ON ph.id = c.commentable_id AND c.commentable_type = \'photo\'
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