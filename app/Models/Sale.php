<?php

namespace App\Models;

use App\Core\Database;

class Sale
{
    public static function all(): array
    {
        return Database::run(
            'SELECT s.*, p.name AS plan_name, p.price AS plan_price,
                    (SELECT COUNT(*) FROM subscriptions x
                     WHERE x.sale_id = s.id AND x.status IN (\'pending\', \'active\')) AS reserved_count,
                    (SELECT COUNT(*) FROM sale_codes c WHERE c.sale_id = s.id) AS code_count
             FROM sales s JOIN plans p ON p.id = s.plan_id
             ORDER BY s.created_at DESC, s.id DESC'
        )->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $row = Database::run(
            'SELECT s.*, p.name AS plan_name, p.price AS plan_price
             FROM sales s JOIN plans p ON p.id = s.plan_id WHERE s.id = ? LIMIT 1',
            [$id]
        )->fetch();

        return $row ?: null;
    }

    public static function activeForPlan(int $planId): ?array
    {
        // The capacity is NOT checked here: this is an unlocked lookup, and
        // two checkouts on the last slot both saw room and both sold. The
        // real check is Sale::reserveSlot(), inside the subscription
        // transaction, with the sale row locked.
        $row = Database::run(
            'SELECT s.*, p.name AS plan_name, p.price AS plan_price
             FROM sales s JOIN plans p ON p.id = s.plan_id
             WHERE s.plan_id = ? AND s.active = 1
               AND (s.ends_at IS NULL OR s.ends_at > CURRENT_TIMESTAMP)
             ORDER BY s.id DESC LIMIT 1',
            [$planId]
        )->fetch();

        return $row ?: null;
    }

    /**
     * Reserve one slot of a sale, inside the caller's transaction.
     *
     * The sale row is locked FOR UPDATE, so a second checkout waits for the
     * first one to commit and then sees the updated count. The old unlocked
     * COUNT let two members take the last slot.
     */
    public static function reserveSlot(int $saleId): bool
    {
        $row = Database::run(
            'SELECT s.max_subscriptions,
                    (SELECT COUNT(*) FROM subscriptions x
                     WHERE x.sale_id = s.id AND x.status IN (\'pending\', \'active\')) AS used
             FROM sales s WHERE s.id = ? FOR UPDATE',
            [$saleId]
        )->fetch();

        if ($row === false) {
            return false;
        }

        if ($row['max_subscriptions'] === null) {
            return true;
        }

        return (int) $row['used'] < (int) $row['max_subscriptions'];
    }

    public static function create(int $planId, string $name, float $price, ?int $maxSubscriptions, ?string $endsAt, bool $active): int
    {
        Database::run(
            'INSERT INTO sales (plan_id, name, sale_price, max_subscriptions, ends_at, active, created_at)
             VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)',
            [$planId, $name, $price, $maxSubscriptions, $endsAt ?: null, $active ? 1 : 0]
        );

        return (int) Database::connection()->lastInsertId();
    }

    public static function toggleActive(int $id): void
    {
        Database::run('UPDATE sales SET active = NOT active WHERE id = ?', [$id]);
    }

    public static function delete(int $id): void
    {
        Database::run('DELETE FROM sales WHERE id = ?', [$id]);
    }
}
