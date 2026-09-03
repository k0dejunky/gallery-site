<?php

namespace App\Models;

use App\Core\Database;

/**
 * Data access for galleries, including their photo counts, category links,
 * search/pagination and photo ordering.
 */
class Gallery
{
    /**
     * Every gallery newest first, each with its image and video counts.
     */
    public static function all(): array
    {
        return Database::run(
            'SELECT g.*, COUNT(gp.photo_id) AS photo_count, ' . self::videoCountSql() . '
             FROM galleries g
             LEFT JOIN gallery_photo gp ON gp.gallery_id = g.id
             WHERE g.deleted_at IS NULL
             GROUP BY g.id
             ORDER BY g.created_at DESC'
        )->fetchAll();
    }

    /**
     * Paginated, filterable gallery list. Supports fulltext search across
     * titles/descriptions/category names, a category filter, a
     * multiple-category restriction (<code>category_ids</code>, used on the
     * home page to keep results inside the user's favourite categories) and
     * an images/videos type filter. Returns items plus paging metadata.
     */
    public static function paginate(int $page, int $perPage = 6, array $filters = []): array
    {
        $page    = max(1, $page);
        $where   = [];
        $params  = [];

        if (!empty($filters['q'])) {
            $like = '%' . $filters['q'] . '%';
            $ft   = self::fulltextQuery((string) $filters['q']);

            if ($ft !== '') {
                $where[] = '(MATCH(g.title, g.description) AGAINST (? IN BOOLEAN MODE) OR EXISTS (
                    SELECT 1 FROM gallery_category gc
                    INNER JOIN categories c ON c.id = gc.category_id
                    WHERE gc.gallery_id = g.id AND MATCH(c.name) AGAINST (? IN BOOLEAN MODE)
                ))';
                $params[] = $ft;
                $params[] = $ft;
            } else {
                $where[] = '(g.title LIKE ? OR g.description LIKE ? OR EXISTS (
                    SELECT 1 FROM gallery_category gc
                    INNER JOIN categories c ON c.id = gc.category_id
                    WHERE gc.gallery_id = g.id AND c.name LIKE ?
                ))';
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
            }
        }

        if (!empty($filters['category'])) {
            $where[] = 'EXISTS (SELECT 1 FROM gallery_category gc
                WHERE gc.gallery_id = g.id AND gc.category_id = ?)';
            $params[] = (int) $filters['category'];
        }

        if (!empty($filters['category_ids']) && is_array($filters['category_ids'])) {
            $ids = array_values(array_filter(
                array_map('intval', $filters['category_ids']),
                static fn (int $id): bool => $id > 0
            ));

            if ($ids) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $where[] = 'EXISTS (SELECT 1 FROM gallery_category gc
                    WHERE gc.gallery_id = g.id
                    AND gc.category_id IN (' . $placeholders . '))';
                foreach ($ids as $id) {
                    $params[] = $id;
                }
            }
        }

        if (!empty($filters['type']) && in_array($filters['type'], ['images', 'videos'], true)) {
            $where[] = self::mediaTypeCondition($filters['type']);
        }

        if (array_key_exists('max_level', $filters)) {
            $maxLevel = (int) $filters['max_level'];
            if ($maxLevel < PHP_INT_MAX) {
                $where[]  = 'g.min_level <= ?';
                $params[] = $maxLevel;
            }
        }

        $orderBy = 'g.created_at DESC';
        if (!empty($filters['sort'])) {
            match ($filters['sort']) {
                'views' => $orderBy = 'g.unique_views DESC, g.views DESC',
                'title' => $orderBy = 'g.title ASC',
                default => $orderBy = 'g.created_at DESC',
            };
        }

        $where[] = 'g.deleted_at IS NULL';
        $whereSql = ' WHERE ' . implode(' AND ', $where);

        $total = (int) Database::run(
            'SELECT COUNT(*) FROM galleries g' . $whereSql,
            $params
        )->fetchColumn();

        $pages  = max(1, (int) ceil($total / $perPage));
        $offset = ($page - 1) * $perPage;

        $items = Database::run(
            'SELECT g.*, COUNT(gp.photo_id) AS photo_count, ' . self::videoCountSql() . '
             FROM galleries g
             LEFT JOIN gallery_photo gp ON gp.gallery_id = g.id'
             . $whereSql . '
             GROUP BY g.id
             ORDER BY ' . $orderBy . '
             LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset,
            $params
        )->fetchAll();

        return compact('items', 'total', 'page', 'pages', 'perPage');
    }

    /**
     * Galleries belonging to any of the given categories, returned as a map
     * of [category_id => [gallery, ...]]. A gallery appears under every
     * category it belongs to; callers deduplicate across categories. This
     * avoids the N+1 pattern of one query per category.
     */
    public static function inCategories(array $categoryIds, string $type = '', int $maxLevel = PHP_INT_MAX): array
    {
        $result = [];

        $ids = array_values(array_filter(
            array_map('intval', $categoryIds),
            static fn (int $id): bool => $id > 0
        ));

        if (!$ids) {
            return $result;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $typeCondition = '';
        if (in_array($type, ['images', 'videos'], true)) {
            $typeCondition = ' AND ' . self::mediaTypeCondition($type);
        }

        $levelCondition = $maxLevel < PHP_INT_MAX ? ' AND g.min_level <= ' . (int) $maxLevel : '';

        $rows = Database::run(
            'SELECT g.*, gc.category_id, COUNT(gp.photo_id) AS photo_count, ' . self::videoCountSql() . '
             FROM galleries g
             INNER JOIN gallery_category gc ON gc.gallery_id = g.id
             LEFT JOIN gallery_photo gp ON gp.gallery_id = g.id
             WHERE gc.category_id IN (' . $placeholders . ') AND g.deleted_at IS NULL' . $typeCondition . $levelCondition . '
             GROUP BY g.id, gc.category_id
             ORDER BY g.created_at DESC',
            $ids
        )->fetchAll();

        foreach ($rows as $row) {
            $result[(int) $row['category_id']][] = $row;
        }

        return $result;
    }

    /**
     * Galleries tagged with no category at all (shown in the catch-all
     * "Uncategorized" section of the full listing).
     */
    public static function withoutCategory(string $type = '', int $maxLevel = PHP_INT_MAX): array
    {
        $typeCondition = '';
        if (in_array($type, ['images', 'videos'], true)) {
            $typeCondition = ' AND ' . self::mediaTypeCondition($type);
        }

        $levelCondition = $maxLevel < PHP_INT_MAX ? ' AND g.min_level <= ' . (int) $maxLevel : '';

        return Database::run(
            'SELECT g.*, NULL AS category_id, COUNT(gp.photo_id) AS photo_count, ' . self::videoCountSql() . '
             FROM galleries g
             LEFT JOIN gallery_photo gp ON gp.gallery_id = g.id
             WHERE g.deleted_at IS NULL
               AND NOT EXISTS (SELECT 1 FROM gallery_category gc WHERE gc.gallery_id = g.id)' . $typeCondition . $levelCondition . '
             GROUP BY g.id
             ORDER BY g.created_at DESC'
        )->fetchAll();
    }

    /**
     * Categories a gallery is tagged with.
     */
    public static function categories(int $galleryId): array
    {
        return Database::run(
            'SELECT c.id, c.name, c.slug
             FROM categories c
             INNER JOIN gallery_category gc ON gc.category_id = c.id
             WHERE gc.gallery_id = ?
             ORDER BY c.name ASC',
            [$galleryId]
        )->fetchAll();
    }

    /**
     * Replace a gallery's category assignment with the given list.
     */
    public static function setCategories(int $galleryId, array $categoryIds): void
    {
        Database::run('DELETE FROM gallery_category WHERE gallery_id = ?', [$galleryId]);

        foreach (array_filter($categoryIds, 'is_numeric') as $categoryId) {
            Database::run(
                'INSERT INTO gallery_category (gallery_id, category_id) VALUES (?, ?)',
                [$galleryId, (int) $categoryId]
            );
        }
    }

    /**
     * Turn a search phrase into a boolean-mode fulltext query string. Keeps
     * only words of 3+ characters, strips MySQL boolean operators and adds a
     * wildcard so partial words still match. Returns '' when nothing is
     * usable, which signals the caller to fall back to LIKE.
     */
    private static function fulltextQuery(string $term): string
    {
        $clean = (string) preg_replace('/[+\-<>~*"@()]/u', ' ', $term);
        $words = array_values(array_filter(
            preg_split('/\s+/u', $clean),
            static fn (string $word): bool => mb_strlen($word) >= 3
        ));

        if (!$words) {
            return '';
        }

        return implode(' ', array_map(
            static fn (string $word): string => $word . '*',
            $words
        ));
    }

    /**
     * EXISTS clause classifying a gallery by its content for type filters on
     * listings: an "image gallery" contains images only, a "video gallery"
     * contains videos only. Galleries that mix both types appear only under
     * "All Galleries", so the two filtered buckets never overlap. Media type
     * comes from the indexed <code>photos.is_video</code> column.
     */
    private static function mediaTypeCondition(string $type): string
    {
        $isVideo = 'p2.is_video = 1';
        $isImage = 'p2.is_video = 0';

        if ($type === 'videos') {
            return "EXISTS (SELECT 1 FROM photos p2
                INNER JOIN gallery_photo gp2 ON gp2.photo_id = p2.id
                WHERE gp2.gallery_id = g.id AND $isVideo)
                AND NOT EXISTS (SELECT 1 FROM photos p2
                INNER JOIN gallery_photo gp2 ON gp2.photo_id = p2.id
                WHERE gp2.gallery_id = g.id AND $isImage)";
        }

        return "EXISTS (SELECT 1 FROM photos p2
            INNER JOIN gallery_photo gp2 ON gp2.photo_id = p2.id
            WHERE gp2.gallery_id = g.id AND $isImage)
            AND NOT EXISTS (SELECT 1 FROM photos p2
            INNER JOIN gallery_photo gp2 ON gp2.photo_id = p2.id
            WHERE gp2.gallery_id = g.id AND $isVideo)";
    }

    /**
     * Correlated subquery counting videos per gallery, joined into list
     * queries as the video_count column.
     */
    private static function videoCountSql(): string
    {
        return '(SELECT COUNT(*) FROM photos p2
            INNER JOIN gallery_photo gp2 ON gp2.photo_id = p2.id
            WHERE gp2.gallery_id = g.id AND p2.is_video = 1) AS video_count';
    }

    /**
     * Fetch a single gallery by id, or null when it does not exist.
     * Soft-deleted galleries are hidden from normal lookups.
     */
    public static function find(int $id): ?array
    {
        $gallery = Database::run(
            'SELECT * FROM galleries WHERE id = ? AND deleted_at IS NULL',
            [$id]
        )->fetch();

        return $gallery ?: null;
    }

    /**
     * Fetch a gallery by id including soft-deleted ones, or null when it
     * does not exist at all (used by the permanent-purge flow).
     */
    public static function findIncludingDeleted(int $id): ?array
    {
        $gallery = Database::run(
            'SELECT * FROM galleries WHERE id = ?',
            [$id]
        )->fetch();

        return $gallery ?: null;
    }

    /**
     * Soft-delete a gallery: it disappears from the site but its row, photo
     * links, categories and view counts stay intact so it can be restored.
     */
    public static function softDelete(int $id): void
    {
        Database::run(
            'UPDATE galleries SET deleted_at = CURRENT_TIMESTAMP WHERE id = ? AND deleted_at IS NULL',
            [$id]
        );
    }

    /**
     * Restore a soft-deleted gallery, bringing it back exactly as it was.
     */
    public static function restore(int $id): void
    {
        Database::run(
            'UPDATE galleries SET deleted_at = NULL WHERE id = ?',
            [$id]
        );
    }

    /**
     * Record a gallery view for the given user: bump the total counter and,
     * when this user has never viewed the gallery before, the unique counter.
     */
    public static function recordView(int $galleryId, int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        \App\Models\Stats::recordContentView('gallery', $galleryId);

        $already = (int) Database::run(
            'SELECT COUNT(*) FROM gallery_viewers WHERE user_id = ? AND gallery_id = ?',
            [$userId, $galleryId]
        )->fetchColumn();

        if ($already === 0) {
            Database::run(
                'INSERT INTO gallery_viewers (user_id, gallery_id, viewed_at) VALUES (?, ?, CURRENT_TIMESTAMP)',
                [$userId, $galleryId]
            );
            Database::run(
                'UPDATE galleries SET views = views + 1, unique_views = unique_views + 1 WHERE id = ?',
                [$galleryId]
            );
            return;
        }

        Database::run(
            'UPDATE gallery_viewers SET viewed_at = CURRENT_TIMESTAMP WHERE user_id = ? AND gallery_id = ?',
            [$userId, $galleryId]
        );
        Database::run(
            'UPDATE galleries SET views = views + 1 WHERE id = ?',
            [$galleryId]
        );
    }

    /**
     * Insert a new gallery (image or video gallery) and return its id.
     */
    public static function create(string $title, string $description, string $type = 'images', int $minLevel = 0): int
    {
        $type = $type === 'videos' ? 'videos' : 'images';

        Database::run(
            'INSERT INTO galleries (title, description, type, min_level, created_at) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)',
            [$title, $description, $type, $minLevel]
        );

        return (int) Database::connection()->lastInsertId();
    }

    /**
     * Update a gallery's title, description and type.
     */
    public static function update(int $id, string $title, string $description, string $type = 'images', int $minLevel = 0): void
    {
        $type = $type === 'videos' ? 'videos' : 'images';

        Database::run(
            'UPDATE galleries SET title = ?, description = ?, type = ?, min_level = ? WHERE id = ?',
            [$title, $description, $type, $minLevel, $id]
        );
    }

    /**
     * Permanently delete a gallery and its photo links, then remove any
     * photos that are no longer referenced by any gallery (garbage
     * collection). This is the permanent purge: the gallery and any orphaned
     * files cannot be recovered afterwards.
     */
    public static function delete(int $id): void
    {
        $photoIds = array_column(Database::run(
            'SELECT photo_id FROM gallery_photo WHERE gallery_id = ?',
            [$id]
        )->fetchAll(), 'photo_id');

        Database::run('DELETE FROM gallery_photo WHERE gallery_id = ?', [$id]);
        Database::run('DELETE FROM galleries WHERE id = ?', [$id]);

        foreach ($photoIds as $photoId) {
            Photo::deleteIfOrphan((int) $photoId);
        }
    }

    /**
     * A page of photos inside a gallery (display order, limited to a window).
     * Used by the gallery viewer's "load more" pagination so large galleries
     * do not ship every item's markup in the initial page response.
     */
    public static function photosSlice(int $galleryId, int $limit, int $offset): array
    {
        $limit  = max(1, (int) $limit);
        $offset = max(0, (int) $offset);

        return Database::run(
            'SELECT p.*, gp.position
             FROM photos p
             INNER JOIN gallery_photo gp ON gp.photo_id = p.id
             WHERE gp.gallery_id = ?
             ORDER BY gp.position ASC, p.id ASC
             LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset,
            [$galleryId]
        )->fetchAll();
    }

    /**
     * Total number of photos inside a gallery.
     */
    public static function photoCount(int $galleryId): int
    {
        return (int) Database::run(
            'SELECT COUNT(*) FROM gallery_photo WHERE gallery_id = ?',
            [$galleryId]
        )->fetchColumn();
    }

    /**
     * Photos inside a gallery in display order.
     */
    public static function photos(int $galleryId): array
    {
        return Database::run(
            'SELECT p.*, gp.position
             FROM photos p
             INNER JOIN gallery_photo gp ON gp.photo_id = p.id
             WHERE gp.gallery_id = ?
             ORDER BY gp.position ASC, p.id ASC',
            [$galleryId]
        )->fetchAll();
    }

    /**
     * Bulk-load the first photo for multiple galleries in one query.
     * Returns [gallery_id => photo_row] map.
     */
    public static function firstPhotos(array $galleryIds): array
    {
        $galleryIds = array_values($galleryIds);
        if (empty($galleryIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($galleryIds), '?'));

        $rows = Database::run(
            "SELECT gp.gallery_id, p.*, gp.position
             FROM photos p
             INNER JOIN gallery_photo gp ON gp.photo_id = p.id
             WHERE gp.gallery_id IN ($placeholders)
             ORDER BY gp.position ASC, p.id ASC",
            $galleryIds
        )->fetchAll();

        $result = [];
        $seen   = [];

        foreach ($rows as $row) {
            $gid = (int) $row['gallery_id'];
            if (!isset($seen[$gid])) {
                $seen[$gid]    = true;
                $result[$gid]  = $row;
            }
        }

        return $result;
    }

    /**
     * Bulk-load categories for multiple galleries in one query.
     * Returns [gallery_id => [category, ...]] map.
     */
    public static function categoriesBulk(array $galleryIds): array
    {
        $galleryIds = array_values($galleryIds);
        if (empty($galleryIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($galleryIds), '?'));

        $rows = Database::run(
            "SELECT gc.gallery_id, c.id, c.name, c.slug
             FROM categories c
             INNER JOIN gallery_category gc ON gc.category_id = c.id
             WHERE gc.gallery_id IN ($placeholders)
             ORDER BY c.name ASC",
            $galleryIds
        )->fetchAll();

        $result = [];
        foreach ($rows as $row) {
            $gid = (int) $row['gallery_id'];
            $result[$gid][] = $row;
        }

        return $result;
    }

    /**
     * The first photo of a gallery, used as its cover on gallery cards.
     */
    public static function firstPhoto(int $galleryId): ?array
    {
        $photo = Database::run(
            'SELECT p.*, gp.position
             FROM photos p
             INNER JOIN gallery_photo gp ON gp.photo_id = p.id
             WHERE gp.gallery_id = ?
             ORDER BY gp.position ASC, p.id ASC
             LIMIT 1',
            [$galleryId]
        )->fetch();

        return $photo ?: null;
    }

    /**
     * Add a photo to a gallery at the end, skipping duplicates. The position
     * column preserves a manual display order.
     */
    public static function attachPhoto(int $galleryId, int $photoId): void
    {
        $exists = Database::run(
            'SELECT COUNT(*) FROM gallery_photo WHERE gallery_id = ? AND photo_id = ?',
            [$galleryId, $photoId]
        )->fetchColumn();

        if ((int) $exists > 0) {
            return;
        }

        $position = (int) Database::run(
            'SELECT COALESCE(MAX(position), 0) + 1 FROM gallery_photo WHERE gallery_id = ?',
            [$galleryId]
        )->fetchColumn();

        Database::run(
            'INSERT INTO gallery_photo (gallery_id, photo_id, position) VALUES (?, ?, ?)',
            [$galleryId, $photoId, $position]
        );
    }

    /**
     * Swap a photo's position with its neighbor (up or down) to reorder the
     * gallery display.
     */
    public static function movePhoto(int $galleryId, int $photoId, string $direction): void
    {
        $current = Database::run(
            'SELECT position FROM gallery_photo WHERE gallery_id = ? AND photo_id = ?',
            [$galleryId, $photoId]
        )->fetchColumn();

        if ($current === false) {
            return;
        }

        $current = (int) $current;

        if ($direction === 'up') {
            $neighbor = Database::run(
                'SELECT photo_id, position FROM gallery_photo
                 WHERE gallery_id = ? AND position < ?
                 ORDER BY position DESC LIMIT 1',
                [$galleryId, $current]
            )->fetch();
        } elseif ($direction === 'down') {
            $neighbor = Database::run(
                'SELECT photo_id, position FROM gallery_photo
                 WHERE gallery_id = ? AND position > ?
                 ORDER BY position ASC LIMIT 1',
                [$galleryId, $current]
            )->fetch();
        } else {
            $neighbor = null;
        }

        if ($neighbor === null) {
            return;
        }

        Database::run(
            'UPDATE gallery_photo SET position = ? WHERE gallery_id = ? AND photo_id = ?',
            [(int) $neighbor['position'], $galleryId, $photoId]
        );
        Database::run(
            'UPDATE gallery_photo SET position = ? WHERE gallery_id = ? AND photo_id = ?',
            [$current, $galleryId, (int) $neighbor['photo_id']]
        );
    }

    /**
     * Return the subset of gallery IDs the user has already viewed.
     */
    public static function viewedByIds(int $userId, array $galleryIds): array
    {
        $galleryIds = array_values($galleryIds);
        if (empty($galleryIds) || $userId <= 0) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($galleryIds), '?'));

        $rows = Database::run(
            "SELECT gallery_id FROM gallery_viewers
             WHERE user_id = ? AND gallery_id IN ($placeholders)",
            array_merge([$userId], $galleryIds)
        )->fetchAll();

        return array_map('intval', array_column($rows, 'gallery_id'));
    }

    /**
     * Return the user's most recently viewed galleries (newest first).
     */
    public static function recentlyViewed(int $userId, int $limit = 10): array
    {
        if ($userId <= 0) {
            return [];
        }

        return Database::run(
            'SELECT g.*, COUNT(gp.photo_id) AS photo_count, ' . self::videoCountSql() . '
             FROM gallery_viewers gv
             INNER JOIN galleries g ON g.id = gv.gallery_id
             LEFT JOIN gallery_photo gp ON gp.gallery_id = g.id
             WHERE gv.user_id = ? AND g.deleted_at IS NULL
             GROUP BY g.id
             ORDER BY gv.viewed_at DESC
             LIMIT ' . (int) $limit,
            [$userId]
        )->fetchAll();
    }

    /**
     * Return the subset of gallery IDs the user has favourited.
     */
    public static function favoriteIds(int $userId, array $galleryIds): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $galleryIds),
            static fn (int $id): bool => $id > 0
        )));

        if ($userId <= 0 || $ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = Database::run(
            "SELECT gallery_id FROM gallery_favorites
             WHERE user_id = ? AND gallery_id IN ($placeholders)",
            array_merge([$userId], $ids)
        )->fetchAll();

        return array_map('intval', array_column($rows, 'gallery_id'));
    }

    /**
     * Toggle a gallery favourite and return its new state.
     */
    public static function toggleFavorite(int $userId, int $galleryId): bool
    {
        $deleted = Database::run(
            'DELETE FROM gallery_favorites WHERE user_id = ? AND gallery_id = ?',
            [$userId, $galleryId]
        );

        if ($deleted->rowCount() > 0) {
            return false;
        }

        Database::run(
            'INSERT INTO gallery_favorites (user_id, gallery_id, created_at)
             VALUES (?, ?, CURRENT_TIMESTAMP)',
            [$userId, $galleryId]
        );

        return true;
    }

    /**
     * Fetch a user's newest favourite galleries with media counts and covers.
     * Covers are bulk-loaded to avoid one query per sidebar item.
     */
    public static function favoriteGalleries(int $userId, int $limit = 8): array
    {
        if ($userId <= 0) {
            return [];
        }

        $galleries = Database::run(
            'SELECT g.*, COUNT(gp.photo_id) AS photo_count, ' . self::videoCountSql() . '
             FROM gallery_favorites gf
             INNER JOIN galleries g ON g.id = gf.gallery_id
             LEFT JOIN gallery_photo gp ON gp.gallery_id = g.id
             WHERE gf.user_id = ? AND g.deleted_at IS NULL
             GROUP BY g.id, gf.created_at
             ORDER BY gf.created_at DESC
             LIMIT ' . max(1, (int) $limit),
            [$userId]
        )->fetchAll();

        $covers = self::firstPhotos(array_map('intval', array_column($galleries, 'id')));
        foreach ($galleries as &$gallery) {
            $gallery['first_photo'] = $covers[(int) $gallery['id']] ?? null;
        }
        unset($gallery);

        return $galleries;
    }
}
