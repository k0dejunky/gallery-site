<?php

namespace App\Models;

use App\Core\Database;

/**
 * Named collections of galleries and/or individual videos a member can build
 * (playlists). A collection is owned by one user; each item is either a whole
 * gallery (gallery_id) or a single video (photo_id), kept in a manual order.
 */
class Collection
{
    /**
     * A user's collections, newest first, each with its gallery/video counts
     * and a cover thumbnail (the first item's image).
     */
    public static function forUser(int $userId): array
    {
        $rows = Database::run(
            "SELECT c.*,
                    (SELECT COUNT(*) FROM collection_items ci WHERE ci.collection_id = c.id AND ci.gallery_id IS NOT NULL) AS gallery_count,
                    (SELECT COUNT(*) FROM collection_items ci WHERE ci.collection_id = c.id AND ci.photo_id IS NOT NULL) AS video_count,
                    (SELECT CONCAT(IF(ci.photo_id IS NOT NULL, 'photo:', 'gallery:'),
                                   COALESCE(ci.photo_id, ci.gallery_id))
                       FROM collection_items ci WHERE ci.collection_id = c.id
                       ORDER BY ci.position ASC, ci.id ASC LIMIT 1) AS first_item
             FROM collections c
             WHERE c.user_id = ?
             ORDER BY c.id DESC",
            [$userId]
        )->fetchAll();

        // Resolve covers: gallery covers via firstPhotos, video covers via thumbs.
        $galleryIds = [];
        $photoIds   = [];
        foreach ($rows as $r) {
            if (!empty($r['first_item'])) {
                [$type, $id] = explode(':', (string) $r['first_item'], 2);
                if ($type === 'gallery') {
                    $galleryIds[] = (int) $id;
                } else {
                    $photoIds[] = (int) $id;
                }
            }
        }

        $gCovers = $galleryIds !== [] ? Gallery::firstPhotos($galleryIds) : [];
        $pThumbs = [];
        if ($photoIds !== []) {
            $ph = implode(',', array_fill(0, count($photoIds), '?'));
            foreach (Database::run("SELECT id, filename FROM photos WHERE id IN ($ph)", $photoIds)->fetchAll() as $p) {
                $pThumbs[(int) $p['id']] = file_url((string) $p['filename'], 'thumb');
            }
        }

        foreach ($rows as &$r) {
            $r['gallery_count'] = (int) $r['gallery_count'];
            $r['video_count']   = (int) $r['video_count'];
            $r['cover']         = null;
            if (!empty($r['first_item'])) {
                [$type, $id] = explode(':', (string) $r['first_item'], 2);
                if ($type === 'gallery' && isset($gCovers[(int) $id])) {
                    $r['cover'] = file_url((string) $gCovers[(int) $id]['filename'], 'thumb');
                } elseif (isset($pThumbs[(int) $id])) {
                    $r['cover'] = $pThumbs[(int) $id];
                }
            }
            unset($r['first_item']);
        }
        unset($r);

        return $rows;
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

    /**
     * The collection's individual video items in manual order, each with its
     * thumb/web URLs, page URL and cached duration (for playlist rows).
     */
    public static function videos(int $collectionId, int $userId): array
    {
        $items = Database::run(
            'SELECT ci.photo_id
             FROM collection_items ci
             JOIN collections c ON c.id = ci.collection_id
             WHERE ci.collection_id = ? AND c.user_id = ? AND ci.photo_id IS NOT NULL
             ORDER BY ci.position ASC, ci.id ASC',
            [$collectionId, $userId]
        )->fetchAll();

        $photoIds = array_map('intval', array_column($items, 'photo_id'));
        if ($photoIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($photoIds), '?'));
        $photos = Database::run(
            'SELECT p.* FROM photos p
             WHERE p.id IN (' . $placeholders . ') AND p.is_video = 1',
            $photoIds
        )->fetchAll();

        $byId = [];
        foreach ($photos as $p) {
            $byId[(int) $p['id']] = $p;
        }

        $ordered = [];
        foreach ($photoIds as $id) {
            if (isset($byId[$id])) {
                $p = $byId[$id];
                $p['thumb'] = file_url((string) $p['filename'], 'thumb');
                $p['web']   = file_url((string) $p['filename'], 'web');
                $p['url']   = url('/videos/' . (int) $p['id']);
                $ordered[]  = $p;
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

    /** Add an individual video to a collection owned by the user (deduped). */
    public static function addPhoto(int $collectionId, int $userId, int $photoId): bool
    {
        if (!self::owns($collectionId, $userId) || $photoId <= 0) {
            return false;
        }

        $photo = \App\Models\Photo::find($photoId);
        if ($photo === null || !is_video($photo['filename'])) {
            return false;
        }

        $exists = (int) Database::run(
            'SELECT COUNT(*) FROM collection_items WHERE collection_id = ? AND photo_id = ?',
            [$collectionId, $photoId]
        )->fetchColumn();

        if ($exists > 0) {
            return false;
        }

        $position = (int) Database::run(
            'SELECT COALESCE(MAX(position), 0) + 1 FROM collection_items WHERE collection_id = ?',
            [$collectionId]
        )->fetchColumn();

        Database::run(
            'INSERT INTO collection_items (collection_id, photo_id, position) VALUES (?, ?, ?)',
            [$collectionId, $photoId, $position]
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

    /** Remove an individual video from a collection owned by the user. */
    public static function removePhoto(int $collectionId, int $userId, int $photoId): bool
    {
        $deleted = Database::run(
            'DELETE ci FROM collection_items ci
             JOIN collections c ON c.id = ci.collection_id
             WHERE ci.collection_id = ? AND ci.photo_id = ? AND c.user_id = ?',
            [$collectionId, $photoId, $userId]
        );

        return $deleted->rowCount() > 0;
    }
}