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

        \App\Models\Stats::recordContentView('photo', $photoId);

        // Race-safe view counting: insert the viewer row; a duplicate-key
        // exception means the user has already seen this photo (repeat view,
        // views +1) or a concurrent request won the race — never a 500.
        try {
            Database::run(
                'INSERT INTO photo_viewers (user_id, photo_id) VALUES (?, ?)',
                [$userId, $photoId]
            );
        } catch (\PDOException $e) {
            Database::run('UPDATE photos SET views = views + 1 WHERE id = ?', [$photoId]);
            return;
        }

        Database::run(
            'UPDATE photos SET views = views + 1, unique_views = unique_views + 1 WHERE id = ?',
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
     * Look up a photo by its stored filename, used to gate direct file access
     * by the gallery's minimum membership level.
     */
    public static function findByFilename(string $filename): ?array
    {
        $photo = Database::run(
            'SELECT * FROM photos WHERE filename = ? LIMIT 1',
            [$filename]
        )->fetch();

        return $photo ?: null;
    }

    /**
     * Everything the media controller needs about a file, in ONE query.
     *
     * The controller used to make up to nine queries per media request:
     * findByFilename, hasPublicGallery, userCanView, accessLevel,
     * unlocksPhoto and the auth checks around them. Every thumbnail in a
     * gallery grid is a request, so a guest grid of forty photos was up to
     * 360 queries - all of them for the same handful of facts about one
     * photo.
     *
     * The row:
     *   photo_id     the photo, or null when the file is not a photo
     *   access_level the STRICTEST effective level over the galleries holding it
     *   has_public   1 when a published, visible, non-secret gallery holds it
     *   has_secret   1 when a secret gallery holds it
     *   can_view     1 when the member may see it
     *   guest_view   1 when a logged-out visitor may see the bytes: a public
     *                gallery holds it and EVERY public gallery holding it is
     *                guest-viewable (safe, or adult in the free tier)
     *   unlocked     1 when the member paid for one of those galleries
     *
     * @return array<string, mixed>|null
     */
    public static function accessInfo(string $filename, int $userId = 0): ?array
    {
        $visible = Gallery::publishedVisibleSql('g');
        $allowed = '(g.is_secret = 0 OR EXISTS (
                        SELECT 1 FROM gallery_user_access gua
                        WHERE gua.gallery_id = g.id AND gua.user_id = ?))';

        $row = Database::run(
            'SELECT p.id AS photo_id,
                    COALESCE(MAX(' . Gallery::accessLevelSql('g') . '), 0) AS access_level,
                    COALESCE(MAX(CASE WHEN ' . $visible . ' AND g.is_secret = 0 THEN 1 ELSE 0 END), 0) AS has_public,
                    COALESCE(MIN(CASE WHEN ' . $visible . ' AND g.is_secret = 0
                                      THEN (' . Gallery::guestViewableSql('g') . ') ELSE NULL END), 0) AS guest_view,
                    COALESCE(MAX(CASE WHEN g.is_secret = 1 THEN 1 ELSE 0 END), 0) AS has_secret,
                    COALESCE(MAX(CASE WHEN ' . $visible . ' AND ' . $allowed . ' THEN 1 ELSE 0 END), 0) AS can_view,
                    COALESCE(MAX(CASE WHEN EXISTS (
                        SELECT 1 FROM purchases pu
                        WHERE pu.item_type = \'gallery\'
                          AND pu.item_id = g.id
                          AND pu.user_id = ?
                          AND pu.status IN (\'paid\', \'granted\')
                          AND g.ppv_price IS NOT NULL
                          AND g.deleted_at IS NULL
                    ) THEN 1 ELSE 0 END), 0) AS unlocked
             FROM photos p
             LEFT JOIN gallery_photo gp ON gp.photo_id = p.id
             LEFT JOIN galleries g ON g.id = gp.gallery_id
             WHERE p.filename = ?
             GROUP BY p.id
             LIMIT 1',
            [$userId, $userId, $filename]
        )->fetch();

        if (!$row) {
            return null;
        }

        // can_view means exactly what Photo::userCanView() means, so the two
        // can never disagree: a guest is nobody's viewer (the early return
        // in userCanView()), and a super admin manages (and reviews) secret
        // galleries, so they see every photo.
        if ($userId <= 0) {
            $row['can_view'] = 0;
        } elseif (\App\Core\Auth::isSuperAdmin()) {
            $row['can_view'] = 1;
        }

        return [
            'photo_id'     => (int) $row['photo_id'],
            'access_level' => (int) $row['access_level'],
            'has_public'   => (int) $row['has_public'] === 1,
            'has_secret'   => (int) $row['has_secret'] === 1,
            'can_view'     => (int) $row['can_view'] === 1,
            'guest_view'   => (int) ($row['guest_view'] ?? 0) === 1 && (int) $row['has_public'] === 1,
            'unlocked'     => (int) $row['unlocked'] === 1,
        ];
    }

    /** Whether the photo belongs to at least one gallery visible to the user. */
    public static function userCanView(int $photoId, int $userId): bool
    {
        if ($userId <= 0) return false;

        // Super admins manage (and review) secret galleries; they bypass the
        // per-gallery allow-list so their thumbnails and originals render on
        // the admin manage pages (manageGallery already limits who may open a
        // secret gallery to super admins).
        if (\App\Core\Auth::isSuperAdmin()) {
            return true;
        }

        return (bool) Database::run(
            'SELECT 1 FROM gallery_photo gp
             INNER JOIN galleries g ON g.id = gp.gallery_id
             WHERE gp.photo_id = ? AND ' . Gallery::publishedVisibleSql('g') . '
               AND (g.is_secret = 0 OR EXISTS (
                   SELECT 1 FROM gallery_user_access gua
                   WHERE gua.gallery_id = g.id AND gua.user_id = ?
               )) LIMIT 1',
            [$photoId, $userId]
        )->fetchColumn();
    }

    public static function hasPublicGallery(int $photoId): bool
    {
        return (bool) Database::run(
            'SELECT 1 FROM gallery_photo gp
             INNER JOIN galleries g ON g.id = gp.gallery_id
             WHERE gp.photo_id = ? AND ' . Gallery::publishedVisibleSql('g') . '
               AND g.is_secret = 0 LIMIT 1',
            [$photoId]
        )->fetchColumn();
    }

    public static function hasSecretGallery(int $photoId): bool
    {
        return (bool) Database::run(
            'SELECT 1 FROM gallery_photo gp
             INNER JOIN galleries g ON g.id = gp.gallery_id
             WHERE gp.photo_id = ? AND g.is_secret = 1 LIMIT 1',
            [$photoId]
        )->fetchColumn();
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

        \App\Core\Cache::bump('media');
        \App\Core\Cache::bump('gallery');

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
     * The lowest minimum membership level across every gallery that contains
     * this photo. Used to gate direct media views: a photo living in a free
     * (level 0) gallery stays viewable to logged-in free users even if a
     * sibling gallery is subscription-only.
     */
    public static function minimumGalleryLevel(int $photoId): int
    {
        $level = Database::run(
            'SELECT MIN(' . Gallery::accessLevelSql('g') . ') FROM gallery_photo gp
             INNER JOIN galleries g ON g.id = gp.gallery_id
             WHERE gp.photo_id = ?',
            [$photoId]
        )->fetchColumn();

        return ($level === false || $level === null) ? 0 : (int) $level;
    }

    /**
     * The alt text for a photo.
     *
     * NOT ONE of the 7,058 photos on production had a caption, so every
     * image on the site rendered alt="" and every /images/{id} page was
     * titled "Full-size image". A screen reader got nothing, and a search
     * engine got nothing.
     *
     * So: the caption when there is one, otherwise the gallery the photo
     * belongs to, otherwise the site name. The filename is a hash, so it
     * says nothing at all.
     */
    public static function altText(array $photo, ?string $galleryTitle = null): string
    {
        $caption = trim((string) ($photo['caption'] ?? ''));

        if ($caption !== '') {
            return $caption;
        }

        $title = trim((string) ($galleryTitle ?? ''));

        if ($title !== '') {
            return $title;
        }

        return (string) config('app.site_name', 'gallery');
    }

    /**
     * The level a member needs to see this photo's BYTES.
     *
     * A photo can live in more than one gallery - MediaUploader::commit()
     * attaches an existing photo (the same content hash) to a new gallery
     * automatically - so the level that matters is the STRICTEST across
     * every gallery that holds it.
     *
     * It used to be the MINIMUM, which meant the bytes of a photo that also
     * sat in a Silver gallery were served at Silver level even though the
     * Gold gallery advertised it as Gold content: a Silver member who
     * learned the file URL could fetch it. The gallery page used the
     * gallery's OWN level while the bytes were served at this minimum, so
     * the two disagreed about the same asset.
     *
     * This is the ONE place that decides it.
     */
    public static function accessLevel(int $photoId): int
    {
        $level = Database::run(
            'SELECT MAX(' . Gallery::accessLevelSql('g') . ') FROM gallery_photo gp
             INNER JOIN galleries g ON g.id = gp.gallery_id
             WHERE gp.photo_id = ?',
            [$photoId]
        )->fetchColumn();

        return ($level === false || $level === null) ? 0 : (int) $level;
    }

    /**
     * Whether a logged-out visitor may see this photo's bytes.
     *
     * The same STRICTEST rule accessLevel() uses, applied to the safe/free
     * question: there must be at least one public gallery holding the photo,
     * and every public gallery holding it must be guest-viewable (safe, or
     * adult in the free tier). A photo shared with a paid gallery therefore
     * stays behind the gate even if a safe gallery holds it too.
     */
    public static function guestViewable(int $photoId): bool
    {
        $visible = Gallery::publishedVisibleSql('g');

        $row = Database::run(
            'SELECT
                COALESCE(MAX(CASE WHEN ' . $visible . ' AND g.is_secret = 0 THEN 1 ELSE 0 END), 0) AS has_public,
                COALESCE(MIN(CASE WHEN ' . $visible . ' AND g.is_secret = 0
                                  THEN (' . Gallery::guestViewableSql('g') . ') ELSE NULL END), 0) AS all_guest
             FROM gallery_photo gp
             INNER JOIN galleries g ON g.id = gp.gallery_id
             WHERE gp.photo_id = ?',
            [$photoId]
        )->fetch();

        return $row !== false
            && (int) $row['has_public'] === 1
            && (int) $row['all_guest'] === 1;
    }

    /**
     * Staged uploads abandoned mid-upload: files sitting in the session
     * staging area (<code>storage/uploads/pending/&lt;session&gt;/</code>)
     * whose session ended before the gallery was created. Each original
     * file is named <code>pending_&lt;uniqid&gt;.&lt;ext&gt;</code> with
     * optional <code>thumb_</code>/<code>web_</code> variants alongside.
     */
    public static function abandonedPending(): array
    {
        $base  = config('app.uploads.dir') . '/pending';
        $rows  = [];

        foreach (glob($base . '/*', GLOB_ONLYDIR) ?: [] as $sessionDir) {
            $session = basename($sessionDir);

            // Completed staged uploads (resumable/assignable).
            foreach (glob($sessionDir . '/pending_*') ?: [] as $file) {
                $name = basename($file);

                if (!preg_match('/^pending_[A-Za-z0-9_.-]+\.[A-Za-z0-9]+$/', $name)) {
                    continue;
                }

                $rows[] = [
                    'session'    => $session,
                    'filename'   => $name,
                    'is_video'   => is_video($name) ? 1 : 0,
                    'size'       => (int) @filesize($file),
                    'modified'   => @filemtime($file) ?: null,
                    'incomplete' => false,
                ];
            }

            // Interrupted chunked uploads: parts staged under .chunks/<uid>/
            // but never assembled into a pending_* file, so they were invisible
            // to the abandoned-uploads page before.
            foreach (glob($sessionDir . '/.chunks/*', GLOB_ONLYDIR) ?: [] as $chunkSet) {
                self::appendChunkRow($rows, $session, basename($chunkSet), $chunkSet);
            }

            // Folder-import staging (pending/import/<gallery>/<uid>/part-*).
            if ($session === 'import') {
                foreach (glob($sessionDir . '/*/*', GLOB_ONLYDIR) ?: [] as $chunkSet) {
                    self::appendChunkRow($rows, $session, basename(dirname($chunkSet)) . '/' . basename($chunkSet), $chunkSet);
                }
            }
        }

        usort($rows, static function (array $a, array $b): int {
            return ($b['modified'] ?? 0) <=> ($a['modified'] ?? 0);
        });

        return $rows;
    }

    /** Add one incomplete chunk-set row (parts present, no assembled file). */
    private static function appendChunkRow(array &$rows, string $session, string $label, string $chunkSet): void
    {
        $parts = glob($chunkSet . '/part-*') ?: [];
        if ($parts === []) {
            return;
        }
        $rows[] = [
            'session'    => $session,
            'filename'   => $label,
            'is_video'   => 0,
            'size'       => array_sum(array_map(static fn (string $p): int => (int) @filesize($p), $parts)),
            'modified'   => @filemtime($chunkSet) ?: null,
            'incomplete' => true,
            'chunks'     => count($parts),
        ];
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
     * Total number of images and videos on the site, restricted to media in
     * visible (non-secret, published, not-deleted) galleries. Used for the
     * guest landing teaser. Cached for an hour.
     */
    public static function siteCounts(): array
    {
        $cached = \App\Core\Cache::rememberGen(
            'media',
            'site_counts',
            3600,
            static function (): string {
                $rows = Database::run(
                    'SELECT p.is_video, COUNT(DISTINCT p.id) AS c
                     FROM photos p
                     INNER JOIN gallery_photo gp ON gp.photo_id = p.id
                     INNER JOIN galleries g ON g.id = gp.gallery_id
                     WHERE g.is_secret = 0 AND ' . Gallery::publishedVisibleSql('g') . '
                     GROUP BY p.is_video'
                )->fetchAll();

                $images = $videos = 0;
                foreach ($rows as $row) {
                    if ((int) $row['is_video'] === 1) {
                        $videos = (int) $row['c'];
                    } else {
                        $images = (int) $row['c'];
                    }
                }

                return json_encode(['images' => $images, 'videos' => $videos]);
            }
        );

        // The cached value is a JSON string, but the cache can hand back an
        // array (a value stored under the array key). Both are accepted, so a
        // cached entry can never take the page down.
        $decoded = is_array($cached) ? $cached : json_decode((string) $cached, true);

        return [
            'images' => (int) ($decoded['images'] ?? 0),
            'videos' => (int) ($decoded['videos'] ?? 0),
        ];
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
        $bucket = 'media';
        $key = $kind . ':' . (int) $limit;

        $cached = \App\Core\Cache::rememberGen(
            $bucket,
            $key,
            \App\Models\ServerOptimizations::cacheTtl('recent'),
            static function () use ($isVideo, $limitSql, $kind, $limit): string {
                return json_encode(Database::run(
                    'SELECT p.id, p.filename, p.created_at,
                            (SELECT gp.gallery_id FROM gallery_photo gp
                              INNER JOIN galleries g ON g.id = gp.gallery_id
                              WHERE gp.photo_id = p.id AND g.is_secret = 0
                                AND ' . Gallery::publishedVisibleSql('g') . '
                              ORDER BY gp.gallery_id LIMIT 1) AS gallery_id
                     FROM photos p
                     WHERE p.is_video = ?
                       AND EXISTS (SELECT 1 FROM gallery_photo gp_public
                           INNER JOIN galleries g_public ON g_public.id = gp_public.gallery_id
                           WHERE gp_public.photo_id = p.id AND g_public.is_secret = 0
                             AND ' . Gallery::publishedVisibleSql('g_public') . ')
                     ORDER BY p.created_at DESC, p.id DESC' . $limitSql,
                    [$isVideo]
                )->fetchAll());
            }
        );

        // The cached value is a JSON string, but the cache can hand back an
        // array (a value stored under the array key). Both are accepted, so a
        // cached entry can never take the page down.
        $decoded = is_array($cached) ? $cached : json_decode((string) $cached, true);

        return is_array($decoded) ? $decoded : [];
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
     * Cache a video's duration in seconds (shown in playlist rows).
     */
    public static function setDuration(int $id, int $seconds): void
    {
        Database::run(
            'UPDATE photos SET duration_seconds = ? WHERE id = ?',
            [max(0, $seconds), $id]
        );
    }

    /**
     * Cache a media file's DISPLAY size in pixels and its display rotation.
     *
     * The player sizes its box from these, so a vertical clip gets a vertical
     * box from the very first paint instead of a wide one that snaps into
     * shape once the browser has read the video's metadata. A width of 0 (or
     * a null pair) means "not measured yet" and the player falls back to the
     * aspect it reads from the video itself.
     */
    public static function setDimensions(int $id, int $width, int $height, int $rotation = 0): void
    {
        if ($width <= 0 || $height <= 0) {
            return;
        }

        Database::run(
            'UPDATE photos SET width = ?, height = ?, rotation = ? WHERE id = ?',
            [
                min(65535, $width),
                min(65535, $height),
                ((int) round($rotation / 90) * 90) % 360,
                $id,
            ]
        );
    }

    /**
     * A photo's cached display size as ['w' => int, 'h' => int, 'rotation' =>
     * int], or null when it has never been measured.
     */
    public static function dimensions(int $id): ?array
    {
        $row = Database::run(
            'SELECT width, height, rotation FROM photos WHERE id = ?',
            [$id]
        )->fetch();

        if (!$row || empty($row['width']) || empty($row['height'])) {
            return null;
        }

        return [
            'w'        => (int) $row['width'],
            'h'        => (int) $row['height'],
            'rotation' => (int) ($row['rotation'] ?? 0),
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

        // The comments of a deleted photo go with it.
        \App\Models\Comment::deleteForEntity(\App\Models\Comment::TYPE_PHOTO, $photoId);

        $dir = config('app.uploads.dir');

        foreach ([$photo['filename'], 'thumb_' . $photo['filename'], 'web_' . $photo['filename']] as $file) {
            $path = $dir . '/' . $file;

            if (is_file($path)) {
                unlink($path);
            }
        }

        Database::run('DELETE FROM photos WHERE id = ?', [$photoId]);

        \App\Core\Cache::bump('media');
        \App\Core\Cache::bump('gallery');
        \App\Core\PageCache::invalidate();
    }
}
