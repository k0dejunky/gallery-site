<?php

namespace App\Models;

use App\Core\Database;

/**
 * Data access for membership plans. A plan defines a tier (monthly, yearly,
 * lifetime) with a price and description. Payments are handled manually for
 * now, so a plan is simply an offer the admin switches on and off.
 */
class Plan
{
    /**
     * The lowest membership level. Favourites (e.g. selecting favourite
     * categories) require a membership of at least this tier.
     */
    public const SILVER_LEVEL = 1;

    /**
     * Every plan ordered by its sort position, each with a count of the
     * subscriptions currently on it (used in the admin plan list).
     */
    public static function all(): array
    {
        return Database::run(
            'SELECT p.*,
                    (SELECT COUNT(*) FROM subscriptions s WHERE s.plan_id = p.id) AS subscriber_count
             FROM plans p
             ORDER BY p.sort_order ASC, p.id ASC'
        )->fetchAll();
    }

    /**
     * Plans that are currently on sale (active), for the public pricing page.
     */
    public static function active(): array
    {
        return Database::run(
            'SELECT * FROM plans WHERE active = 1 ORDER BY sort_order ASC, id ASC'
        )->fetchAll();
    }

    /**
     * Fetch a single plan by id, or null when it does not exist.
     */
    public static function find(int $id): ?array
    {
        $plan = Database::run(
            'SELECT * FROM plans WHERE id = ? LIMIT 1',
            [$id]
        )->fetch();

        return $plan ?: null;
    }

    /**
     * Create a plan. Returns the new plan's id.
     */
    public static function create(string $name, string $billingCycle, float $price, string $description, int $sortOrder, int $level, bool $active): int
    {
        Database::run(
            'INSERT INTO plans (name, slug, price, billing_cycle, description, sort_order, level, active, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)',
            [$name, slugify($name), $price, $billingCycle, $description, $sortOrder, $level, $active ? 1 : 0]
        );

        return (int) Database::connection()->lastInsertId();
    }

    /**
     * Update a plan's details, regenerating its slug from the new name.
     */
    public static function update(int $id, string $name, string $billingCycle, float $price, string $description, int $sortOrder, int $level, bool $active): void
    {
        Database::run(
            'UPDATE plans
             SET name = ?, slug = ?, price = ?, billing_cycle = ?, description = ?, sort_order = ?, level = ?, active = ?
             WHERE id = ?',
            [$name, slugify($name), $price, $billingCycle, $description, $sortOrder, $level, $active ? 1 : 0, $id]
        );
    }

    /**
     * Delete a plan. Its subscriptions are removed by the database's foreign
     * key cascade.
     */
    public static function delete(int $id): void
    {
        Database::run('DELETE FROM plans WHERE id = ?', [$id]);
    }

    /**
     * Toggle a plan's active status (1 → 0 or 0 → 1).
     */
    public static function toggleActive(int $id): void
    {
        Database::run('UPDATE plans SET active = NOT active WHERE id = ?', [$id]);
    }

    /**
     * Human-readable label for a billing cycle (used in admin views).
     */
    public static function cycleLabel(string $cycle): string
    {
        $labels = [
            'monthly'  => 'Monthly',
            'yearly'   => 'Yearly',
            'lifetime' => 'Lifetime',
        ];

        return $labels[$cycle] ?? $cycle;
    }
}
