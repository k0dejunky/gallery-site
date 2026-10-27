<?php

namespace App\Models;

use App\Core\Database;

/**
 * Data access for content categories. Categories are used to organise
 * galleries and (via user favorites) to build the home page navigation.
 */
class Category
{
    /**
     * Every category alphabetically, each with a count of the galleries
     * tagged with it (used in the admin category list). An optional search
     * term narrows the list to categories whose name contains it.
     */
    public static function all(string $search = ''): array
    {
        $search = trim($search);

        if ($search === '') {
            $cached = \App\Core\Cache::rememberGen(
                'category',
                'all',
                \App\Models\ServerOptimizations::cacheTtl('category'),
                static fn (): string => json_encode(Database::run(
                    'SELECT c.*, COUNT(gc.gallery_id) AS gallery_count
                     FROM categories c
                     LEFT JOIN gallery_category gc ON gc.category_id = c.id
                     GROUP BY c.id
                     ORDER BY c.name ASC'
                )->fetchAll())
            );

            $decoded = json_decode($cached, true);

            return is_array($decoded) ? $decoded : [];
        }

        return Database::run(
            'SELECT c.*, COUNT(gc.gallery_id) AS gallery_count
             FROM categories c
             LEFT JOIN gallery_category gc ON gc.category_id = c.id
             WHERE c.name LIKE ?
             GROUP BY c.id
             ORDER BY c.name ASC',
            ['%' . $search . '%']
        )->fetchAll();
    }

    /**
     * Fetch a category by id, or null when it does not exist.
     */
    public static function find(int $id): ?array
    {
        $category = Database::run(
            'SELECT * FROM categories WHERE id = ? LIMIT 1',
            [$id]
        )->fetch();

        return $category ?: null;
    }

    /**
     * Fetch a category by its URL slug (used for category page routes).
     */
    public static function findBySlug(string $slug): ?array
    {
        $category = Database::run(
            'SELECT * FROM categories WHERE slug = ? LIMIT 1',
            [$slug]
        )->fetch();

        return $category ?: null;
    }

    /**
     * Fetch a category by exact name (used to avoid duplicate categories).
     *
     * The comparison is case-insensitive on purpose: the admin types
     * "Pussy", the categorizer proposes "pussy" and the visitor searches
     * "PUSSY", and all three are the same category. It used to lean on the
     * column's collation for that, which breaks the moment the collation
     * changes, so the lower-casing is explicit now.
     */
    public static function findByName(string $name): ?array
    {
        $name = trim($name);

        if ($name === '') {
            return null;
        }

        $category = Database::run(
            'SELECT * FROM categories WHERE LOWER(name) = LOWER(?) LIMIT 1',
            [$name]
        )->fetch();

        return $category ?: null;
    }

    /**
     * The category a search term names, if any.
     *
     * A member searching for a category name should land on that category,
     * even when every gallery in it is above their level (the search shows
     * those galleries blurred). Without this the site recorded the search as
     * a MISS - "we do not have this" - for a category it plainly has.
     *
     * Exact name (case-insensitive) or the URL slug.
     */
    public static function matchForSearch(string $term): ?array
    {
        $term = trim($term);

        if ($term === '') {
            return null;
        }

        $category = Database::run(
            'SELECT c.*, (SELECT COUNT(*) FROM gallery_category gc WHERE gc.category_id = c.id) AS gallery_count
             FROM categories c
             WHERE LOWER(c.name) = LOWER(?) OR c.slug = ?
             LIMIT 1',
            [$term, slugify($term)]
        )->fetch();

        return $category ?: null;
    }

    /**
     * Create a category, generating its slug from the name.
     */
    public static function create(string $name): int
    {
        Database::run(
            'INSERT INTO categories (name, slug, created_at) VALUES (?, ?, CURRENT_TIMESTAMP)',
            [$name, slugify($name)]
        );

        \App\Core\Cache::bump('category');
        \App\Core\Cache::bump('gallery');

        return (int) Database::connection()->lastInsertId();
    }

    /**
     * Rename a category, regenerating its slug to match the new name.
     */
    public static function update(int $id, string $name): void
    {
        Database::run(
            'UPDATE categories SET name = ?, slug = ? WHERE id = ?',
            [$name, slugify($name), $id]
        );

        \App\Core\Cache::bump('category');
        \App\Core\Cache::bump('gallery');
    }

    /**
     * Delete a category. Its gallery_category links are removed by the
     * database's foreign key cascade.
     */
    public static function delete(int $id): void
    {
        Database::run('DELETE FROM categories WHERE id = ?', [$id]);

        \App\Core\Cache::bump('category');
        \App\Core\Cache::bump('gallery');
    }
}
