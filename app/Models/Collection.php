<?php

namespace App\Models;

use App\Core\Database;

/**
 * Named collections of galleries a member can build (playlists). A collection
 * is owned by one user and holds galleries in a manual order; the same gallery
 * can appear in any number of collections.
 */
class Collection
{
    /**
     * A user's collections, newest first, each with its gallery count.
     */
    public static function forUser(int $userId): array
    {
        return Database::run(
            'SELECT c.*, (SELECT COUNT(*) FROM collection_items ci WHERE ci.collection_id = c.id) AS gallery_count
             FROM collections c
             WHERE c.user_id = ?
             ORDER BY c.id DESC',
            [$userId]
        )->fetchAll();
    }

    /** Fetch one collection, or null. */
    public static function find(int $id): ?array
    {
        $row = Database::run('SELECT * FROM collections WHERE id = ? LIMIT 1', [$id])->fetch();

        return $row ?: null;
    }

    /** Whether a collection belongs to the given user. */
    public static function owns(int $collectionId, int $userId): bool
    {
        return (int) Database::run(
            'SELECT COUNT(*) FROM collections WHERE id = ? AND user_id = ?',
            [$collectionId, $userId]
        )->fetchColumn() > 0;
    }

    /** Create a collection and return its id. */
    public static function create(int $userId, string $name): int
    {
        Database::run(
            'INSERT INTO collections (user_id, name) VALUES (?, ?)',
            [$userId, mb_substr(trim($name), 0, 120)]
        );

        return (int) Database::connection()->lastInsertId();
    }

    /** Rename a collection owned by the user. */
    public static function rename(int $collectionId, int $userId, string $name): bool
    {
        $updated = Database::run(
            'UPDATE collections SET name = ? WHERE id = ? AND user_id = ?',
            [mb_substr(trim($name), 0, 120), $collectionId, $userId]
        );

        return $updated->rowCount() > 0;
    }

    /** Delete a collection owned by the user (items cascade). */
    public static function delete(int $collectionId, int $userId): bool
    {
        $deleted = Database::run(
            'DELETE FROM collections WHERE id = ? AND user_id = ?',
            [$collectionId, $userId]
        );

        return $deleted->rowCount() > 0;
    }

    /**
     * Galleries inside a collection in manual order, with covers bulk-loaded.
     */
    public static function galleries(int $collectionId, int $userId, int $limit = 200): array
    {
        $items = Database::run(
            'SELECT ci.gallery_id
             FROM collection_items ci
             JOIN collections c ON c.id = ci.collection_id
             WHERE ci.collection_id = ? AND c.user_id = ?
             ORDER BY ci.position ASC, ci.id ASC
             LIMIT ' . max(1, (int) $limit),
            [$collectionId, $userId]
        )->fetchAll();

        $galleryIds = array_map('intval', array_column($items, 'gallery_id'));
        if ($galleryIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($galleryIds), '?'));
        [$accessCondition, $accessParams] = Gallery::userVisibleSql($userId, 'g');

        $galleries = Database::run(
            'SELECT g.*, (SELECT COUNT(*) FROM gallery_photo gp WHERE gp.gallery_id = g.id) AS photo_count, ' . Gallery::videoCountSql() . '
             FROM galleries g
             WHERE g.id IN (' . $placeholders . ')
               AND ' . Gallery::publishedVisibleSql('g') . ' AND ' . $accessCondition,
            array_merge($galleryIds, $accessParams)
        )->fetchAll();

        $covers = Gallery::firstPhotos($galleryIds);
        $byId = [];
        foreach ($galleries as $g) {
            $byId[(int) $g['id']] = $g;
            $byId[(int) $g['id']]['first_photo'] = $covers[(int) $g['id']] ?? null;
        }

        // Preserve the manual collection order.
        $ordered = [];
        foreach ($galleryIds as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
            }
        }

        return $ordered;
    }

    /** Add a gallery to a collection owned by the user (deduped). */
    public static function addGallery(int $collectionId, int $userId, int $galleryId): bool
    {
        if (!self::owns($collectionId, $userId) || $galleryId <= 0) {
            return false;
        }

        $exists = (int) Database::run(
            'SELECT COUNT(*) FROM collection_items WHERE collection_id = ? AND gallery_id = ?',
            [$collectionId, $galleryId]
        )->fetchColumn();

        if ($exists > 0) {
            return false;
        }

        $position = (int) Database::run(
            'SELECT COALESCE(MAX(position), 0) + 1 FROM collection_items WHERE collection_id = ?',
            [$collectionId]
        )->fetchColumn();

        Database::run(
            'INSERT INTO collection_items (collection_id, gallery_id, position) VALUES (?, ?, ?)',
            [$collectionId, $galleryId, $position]
        );

        return true;
    }

    /** Remove a gallery from a collection owned by the user. */
    public static function removeGallery(int $collectionId, int $userId, int $galleryId): bool
    {
        $deleted = Database::run(
            'DELETE ci FROM collection_items ci
             JOIN collections c ON c.id = ci.collection_id
             WHERE ci.collection_id = ? AND ci.gallery_id = ? AND c.user_id = ?',
            [$collectionId, $galleryId, $userId]
        );

        return $deleted->rowCount() > 0;
    }
}