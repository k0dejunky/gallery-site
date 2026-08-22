<?php

namespace App\Models;

use App\Core\Database;

class AuditLog
{
    public static function record(?int $userId, string $action, string $entityType, ?int $entityId, string $description, ?array $before = null, ?array $after = null): int
    {
        Database::run(
            'INSERT INTO admin_logs (user_id, action, entity_type, entity_id, description, before_json, after_json)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$userId, $action, $entityType, $entityId, $description,
                $before === null ? null : json_encode($before),
                $after === null ? null : json_encode($after)]
        );

        return (int) Database::connection()->lastInsertId();
    }

    public static function recent(int $page = 1, int $perPage = 30): array
    {
        $page = max(1, $page);
        $total = (int) Database::run('SELECT COUNT(*) FROM admin_logs')->fetchColumn();
        $pages = max(1, (int) ceil($total / $perPage));
        $offset = ($page - 1) * $perPage;
        $items = Database::run(
            'SELECT l.*, u.email AS admin_email, r.email AS rollback_email
             FROM admin_logs l
             LEFT JOIN users u ON u.id = l.user_id
             LEFT JOIN users r ON r.id = l.rollback_by
             ORDER BY l.created_at DESC, l.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset
        )->fetchAll();

        return compact('items', 'total', 'page', 'pages', 'perPage');
    }

    public static function find(int $id): ?array
    {
        $row = Database::run('SELECT * FROM admin_logs WHERE id = ?', [$id])->fetch();

        return $row ?: null;
    }

    public static function markRolledBack(int $id, int $userId): void
    {
        Database::run(
            'UPDATE admin_logs SET rolled_back_at = CURRENT_TIMESTAMP, rollback_by = ? WHERE id = ? AND rolled_back_at IS NULL',
            [$userId, $id]
        );
    }

    /**
     * Build a human-readable list of the fields that actually changed between
     * a log's before/after snapshots. Each entry is
     * ['field' => label, 'before' => string, 'after' => string]. Theme logs
     * only list the palette keys whose color changed.
     *
     * Create events (only an after snapshot) list every recorded field as a
     * change from '(empty)', so "what was added" is visible too.
     */
    public static function diff(string $entityType, ?string $beforeJson, ?string $afterJson, array $categoryNames = []): array
    {
        if ($afterJson === null) {
            return [];
        }

        $after = json_decode($afterJson, true);

        if (!is_array($after)) {
            return [];
        }

        $afterFields = self::snapshotFields($entityType, $after, $categoryNames);

        if ($afterFields === null) {
            return [];
        }

        $before = null;

        if ($beforeJson !== null) {
            $decoded = json_decode($beforeJson, true);

            if (is_array($decoded)) {
                $before = $decoded;
            }
        }

        $beforeFields = $before !== null ? self::snapshotFields($entityType, $before, $categoryNames) : null;
        $changes = [];

        foreach ($afterFields as $field => $value) {
            $old = $beforeFields[$field] ?? null;

            if ($beforeFields === null || self::label($old) !== self::label($value)) {
                $changes[] = [
                    'field'  => $field,
                    'before' => self::label($old),
                    'after'  => self::label($value),
                ];
            }
        }

        return $changes;
    }

    /**
     * Normalize one snapshot into a field-label => display-value map. Theme
     * snapshots flatten to their palette values keyed by color name.
     */
    private static function snapshotFields(string $entityType, array $snapshot, array $categoryNames): ?array
    {
        switch ($entityType) {
            case 'gallery':
                return [
                    'Title'       => $snapshot['title'] ?? null,
                    'Description' => $snapshot['description'] ?? null,
                    'Type'        => $snapshot['type'] ?? null,
                    'Categories'  => self::categoryLabels($snapshot['categories'] ?? [], $categoryNames),
                ];

            case 'category':
                return ['Name' => $snapshot['name'] ?? null];

            case 'photo':
                return [
                    'Caption' => $snapshot['caption'] ?? null,
                    'Link'    => $snapshot['link'] ?? null,
                ];

            case 'plan':
                return [
                    'Name'        => $snapshot['name'] ?? null,
                    'Cycle'       => $snapshot['cycle'] ?? null,
                    'Price'       => isset($snapshot['price']) ? '$' . number_format((float) $snapshot['price'], 2) : null,
                    'Level'       => $snapshot['level'] ?? null,
                    'Description' => $snapshot['description'] ?? null,
                    'Sort order'  => $snapshot['sort_order'] ?? null,
                    'Active'      => isset($snapshot['active']) ? ($snapshot['active'] ? 'Yes' : 'No') : null,
                ];

            case 'user':
                return [
                    'Email'         => $snapshot['email'] ?? null,
                    'Role'          => $snapshot['role'] ?? null,
                    'Age verified'  => isset($snapshot['age_verified']) ? ($snapshot['age_verified'] ? 'Yes' : 'No') : null,
                ];

            case 'subscription':
                return [
                    'Status' => $snapshot['status'] ?? null,
                    'User'   => self::resolveUserEmail($snapshot['user_id'] ?? null),
                    'Plan'   => self::resolvePlanName($snapshot['plan_id'] ?? null),
                ];

            case 'theme':
                $fields = [];
                $colors = $snapshot['colors'] ?? $snapshot['values'] ?? null;
                $layout = $snapshot['layout'] ?? null;

                if (is_array($colors)) {
                    foreach ($colors as $key => $val) {
                        $fields['color:' . $key] = $val;
                    }
                }

                if (is_array($layout)) {
                    foreach ($layout as $key => $val) {
                        $fields['layout:' . $key] = $val;
                    }
                }

                return $fields ?: null;
        }

        return null;
    }

    private static function label($value): string
    {
        if (is_array($value)) {
            $parts = [];

            foreach ($value as $k => $v) {
                $parts[] = is_array($v) ? self::label($v) : (string) $v;
            }

            $value = implode(', ', $parts);
        }

        $value = trim((string) $value);

        return $value === '' ? '(empty)' : $value;
    }

    private static function categoryLabels(array $ids, array $categoryNames): string
    {
        $labels = [];

        foreach ($ids as $id) {
            $id      = (int) $id;
            $labels[] = isset($categoryNames[$id]) ? (string) $categoryNames[$id] : '#' . $id;
        }

        return implode(', ', $labels);
    }

    private static function resolveUserEmail($userId): string
    {
        if (empty($userId)) {
            return '(unknown)';
        }

        $row = Database::run('SELECT email FROM users WHERE id = ?', [(int) $userId])->fetch();

        return $row ? (string) $row['email'] : '#' . $userId;
    }

    private static function resolvePlanName($planId): string
    {
        if (empty($planId)) {
            return '(none)';
        }

        $row = Database::run('SELECT name FROM plans WHERE id = ?', [(int) $planId])->fetch();

        return $row ? (string) $row['name'] : '#' . $planId;
    }
}
