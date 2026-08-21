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
            return Database::run(
                'SELECT c.*, COUNT(gc.gallery_id) AS gallery_count
                 FROM categories c
                 LEFT JOIN gallery_category gc ON gc.category_id = c.id
                 GROUP BY c.id
                 ORDER BY c.name ASC'
            )->fetchAll();
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
     */
    public static function findByName(string $name): ?array
    {
        $category = Database::run(
            'SELECT * FROM categories WHERE name = ? LIMIT 1',
            [$name]
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
    }

    /**
     * Delete a category. Its gallery_category links are removed by the
     * database's foreign key cascade.
     */
    public static function delete(int $id): void
    {
        Database::run('DELETE FROM categories WHERE id = ?', [$id]);
    }
}
