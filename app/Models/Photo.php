<?php

namespace App\Models;

use App\Core\Database;

/**
 * Data access for photos (both images and videos). Photos live in the uploads
 * folder and are linked to galleries through the gallery_photo join table.
 */
class Photo
{
    /**
     * Fetch a single photo by id, or null when it does not exist.
     */
    public static function find(int $id): ?array
    {
        $photo = Database::run(
            'SELECT * FROM photos WHERE id = ? LIMIT 1',
            [$id]
        )->fetch();

        return $photo ?: null;
    }

    /**
     * Record a photo view for the given user: bump the total counter and, when
     * this user has never viewed the photo before, the unique counter too.
     */
    public static function recordView(int $photoId, int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        $already = (int) Database::run(
            'SELECT COUNT(*) FROM photo_viewers WHERE user_id = ? AND photo_id = ?',
            [$userId, $photoId]
        )->fetchColumn();

        if ($already === 0) {
            Database::run(
                'INSERT INTO photo_viewers (user_id, photo_id) VALUES (?, ?)',
                [$userId, $photoId]
            );
            Database::run(
                'UPDATE photos SET views = views + 1, unique_views = unique_views + 1 WHERE id = ?',
                [$photoId]
            );
            return;
        }

        Database::run(
            'UPDATE photos SET views = views + 1 WHERE id = ?',
            [$photoId]
        );
    }

    /**
     * Look up a photo by content hash, used to skip duplicate uploads.
     */
    public static function findByHash(string $hash): ?array
    {
        $photo = Database::run(
            'SELECT * FROM photos WHERE hash = ? LIMIT 1',
            [$hash]
        )->fetch();

        return $photo ?: null;
    }

    /**
     * Insert a new photo record and return its id. The media type (image vs
     * video) is derived from the filename's extension and stored in
     * <code>is_video</code> so every media-type query can use an index-backed
     * column instead of scanning filenames.
     */
    public static function create(string $filename, string $hash): int
    {
        Database::run(
            'INSERT INTO photos (filename, is_video, hash, created_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP)',
            [$filename, is_video($filename) ? 1 : 0, $hash]
        );

        return (int) Database::connection()->lastInsertId();
    }

    /**
     * Most recent image uploads, used on the login page. A limit of 0 means
     * no limit, so the page can show every image when requested.
     */
    public static function recentImages(int $limit = 10): array
    {
        return self::recentMedia('image', $limit);
    }

    /**
     * The id of any gallery containing this photo, for building a back link
     * when a photo is shown outside a gallery context (e.g. the player).
     */
    public static function firstGalleryId(int $photoId): ?int
    {
        $id = Database::run(
            'SELECT gallery_id FROM gallery_photo WHERE photo_id = ? ORDER BY gallery_id LIMIT 1',
            [$photoId]
        )->fetchColumn();

        return ($id === false || $id === null) ? null : (int) $id;
    }

    /**
     * Photos saved successfully but not attached to any gallery.
     */
    public static function abandoned(): array
    {
        return Database::run(
            'SELECT p.*
             FROM photos p
             LEFT JOIN gallery_photo gp ON gp.photo_id = p.id
             WHERE gp.photo_id IS NULL
             ORDER BY p.created_at DESC, p.id DESC'
        )->fetchAll();
    }

    /**
     * Most recent video uploads, used on the login page. A limit of 0 means
     * no limit.
     */
    public static function recentVideos(int $limit = 10): array
    {
        return self::recentMedia('video', $limit);
    }

    /**
     * Shared query for recent images or videos, tagging each row with one of
     * its gallery ids so views can link to it. The media type comes from the
     * indexed <code>is_video</code> column (backed by
     * <code>idx_photos_media_created</code>), so no filename scan is needed.
     * A limit of 0 omits the LIMIT clause entirely (show everything).
     */
    private static function recentMedia(string $kind, int $limit): array
    {
        $isVideo = $kind === 'video' ? 1 : 0;
        $limitSql = $limit > 0 ? ' LIMIT ' . (int) $limit : '';

        return Database::run(
            "SELECT p.id, p.filename, p.created_at,
                    (SELECT gp.gallery_id FROM gallery_photo gp
                      WHERE gp.photo_id = p.id ORDER BY gp.gallery_id LIMIT 1) AS gallery_id
             FROM photos p
             WHERE p.is_video = ?
             ORDER BY p.created_at DESC, p.id DESC" . $limitSql,
            [$isVideo]
        )->fetchAll();
    }

    /**
     * Update a photo's caption and link metadata.
     */
    public static function updateCaption(int $id, string $caption, string $link): void
    {
        Database::run(
            'UPDATE photos SET caption = ?, link = ? WHERE id = ?',
            [$caption, $link, $id]
        );
    }

    /**
     * The photos that come immediately before and after a given photo in its
     * gallery, in gallery display order. Used to build the Previous/Next
     * buttons on the image and video pages. Returns [prev, next] where each
     * is a photo row or null at either end of the gallery.
     *
     * @return array{0: ?array, 1: ?array}
     */
    public static function galleryNeighbors(int $galleryId, int $photoId): array
    {
        $photos = Gallery::photos($galleryId);
        $index  = null;

        foreach ($photos as $i => $photo) {
            if ((int) $photo['id'] === $photoId) {
                $index = $i;
                break;
            }
        }

        if ($index === null) {
            return [null, null];
        }

        return [
            $index > 0 ? $photos[$index - 1] : null,
            $index < count($photos) - 1 ? $photos[$index + 1] : null,
        ];
    }

    /**
     * Remove a photo if no gallery references it anymore: deletes the file,
     * its thumbnail and the database row. Used after gallery deletions so
     * orphaned uploads do not accumulate.
     */
    public static function deleteIfOrphan(int $photoId): void
    {
        $refs = (int) Database::run(
            'SELECT COUNT(*) FROM gallery_photo WHERE photo_id = ?',
            [$photoId]
        )->fetchColumn();

        if ($refs > 0) {
            return;
        }

        $photo = self::find($photoId);

        if ($photo === null) {
            return;
        }

        $dir = config('app.uploads.dir');

        foreach ([$photo['filename'], 'thumb_' . $photo['filename'], 'web_' . $photo['filename']] as $file) {
            $path = $dir . '/' . $file;

            if (is_file($path)) {
                unlink($path);
            }
        }

        Database::run('DELETE FROM photos WHERE id = ?', [$photoId]);
    }
}
