<?php

namespace App\Models;

use App\Core\Database;

/**
 * Data access for a user's favourite categories. Favourites personalise the
 * home page: each favourite becomes a section and a navigation entry.
 */
class FavoriteCategory
{
    /**
     * A user's favourite categories in the order they were added.
     */
    public static function forUser(int $userId): array
    {
        return Database::run(
            'SELECT c.id, c.name, c.slug
             FROM user_favorite_categories uf
             INNER JOIN categories c ON c.id = uf.category_id
             WHERE uf.user_id = ?
             ORDER BY uf.created_at ASC',
            [$userId]
        )->fetchAll();
    }

    /**
     * Whether a category is already favourited by the user.
     */
    public static function isFavorite(int $userId, int $categoryId): bool
    {
        $count = Database::run(
            'SELECT COUNT(*) FROM user_favorite_categories WHERE user_id = ? AND category_id = ?',
            [$userId, $categoryId]
        )->fetchColumn();

        return (int) $count > 0;
    }

    /**
     * Add a favourite, ignoring requests that are already favourited.
     */
    public static function add(int $userId, int $categoryId): void
    {
        if (self::isFavorite($userId, $categoryId)) {
            return;
        }

        Database::run(
            'INSERT INTO user_favorite_categories (user_id, category_id, created_at)
             VALUES (?, ?, CURRENT_TIMESTAMP)',
            [$userId, $categoryId]
        );
    }

    /**
     * Remove a favourite.
     */
    public static function remove(int $userId, int $categoryId): void
    {
        Database::run(
            'DELETE FROM user_favorite_categories WHERE user_id = ? AND category_id = ?',
            [$userId, $categoryId]
        );
    }

    /**
     * Replace a user's whole favourites list (used by the settings page),
     * deduplicating and skipping invalid ids.
     */
    public static function replace(int $userId, array $categoryIds): void
    {
        Database::run(
            'DELETE FROM user_favorite_categories WHERE user_id = ?',
            [$userId]
        );

        $insert = Database::connection()->prepare(
            'INSERT INTO user_favorite_categories (user_id, category_id, created_at)
             VALUES (?, ?, CURRENT_TIMESTAMP)'
        );

        foreach (array_unique(array_map('intval', $categoryIds)) as $categoryId) {
            if ($categoryId > 0) {
                $insert->execute([$userId, $categoryId]);
            }
        }
    }
}
