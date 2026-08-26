<?php

namespace App\Models;

use App\Core\Database;

class SavedSearch
{
    private const TYPES = ['images', 'videos'];
    private const SORTS = ['newest', 'views', 'title'];

    /** Normalize the only gallery filters that may be persisted. */
    public static function filters(array $input): array
    {
        $qValue = $input['q'] ?? '';
        $categoryValue = $input['category'] ?? 0;
        $typeValue = $input['type'] ?? '';
        $sortValue = $input['sort'] ?? '';
        $q = is_string($qValue) ? trim($qValue) : '';
        $category = is_scalar($categoryValue) && is_numeric($categoryValue) ? (int) $categoryValue : 0;
        $type = is_string($typeValue) && in_array($typeValue, self::TYPES, true) ? $typeValue : '';
        $sort = is_string($sortValue) && in_array($sortValue, self::SORTS, true) ? $sortValue : '';

        return [
            'q' => mb_substr($q, 0, 255),
            'category' => $category > 0 ? $category : 0,
            'type' => $type,
            'sort' => $sort,
        ];
    }

    public static function create(int $userId, array $input): void
    {
        $filters = self::filters($input);
        $query = json_encode([
            'q' => $filters['q'],
            'category' => $filters['category'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $exists = Database::run(
            'SELECT id FROM saved_searches WHERE user_id = ? AND query = ? AND type = ? AND sort = ? LIMIT 1',
            [$userId, $query, $filters['type'], $filters['sort']]
        )->fetchColumn();
        if ($exists !== false) {
            return;
        }

        Database::run(
            'INSERT INTO saved_searches (user_id, query, type, sort, created_at)
             VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)',
            [$userId, $query, $filters['type'], $filters['sort']]
        );
    }

    public static function forUser(int $userId): array
    {
        $rows = Database::run(
            'SELECT * FROM saved_searches WHERE user_id = ? ORDER BY created_at DESC, id DESC',
            [$userId]
        )->fetchAll();

        foreach ($rows as &$row) {
            $decoded = json_decode((string) $row['query'], true);
            $row['filters'] = is_array($decoded)
                ? self::filters(array_merge($decoded, ['type' => $row['type'], 'sort' => $row['sort']]))
                : self::filters(['q' => $row['query'], 'type' => $row['type'], 'sort' => $row['sort']]);
        }
        unset($row);

        return $rows;
    }

    public static function deleteForUser(int $id, int $userId): void
    {
        Database::run('DELETE FROM saved_searches WHERE id = ? AND user_id = ?', [$id, $userId]);
    }
}
