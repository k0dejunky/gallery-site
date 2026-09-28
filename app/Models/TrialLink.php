<?php

namespace App\Models;

use App\Core\Database;

/**
 * Admin-generated free-trial links. Each link grants a trial at a chosen
 * membership level for a chosen number of days, up to a signup quota
 * (max_uses). Redemption is enforced per user by Subscription::grantTrialFor.
 */
class TrialLink
{
    /** Generate a URL-safe, reasonably hard-to-guess link code. */
    public static function generateCode(): string
    {
        return bin2hex(random_bytes(9));
    }

    public static function find(int $id): ?array
    {
        $row = Database::run(
            'SELECT tl.*, u.email AS created_by_email
             FROM trial_links tl
             LEFT JOIN users u ON u.id = tl.created_by
             WHERE tl.id = ?',
            [$id]
        )->fetch();

        return $row === false ? null : $row;
    }

    public static function findByCode(string $code): ?array
    {
        $row = Database::run(
            'SELECT * FROM trial_links WHERE code = ?',
            [$code]
        )->fetch();

        return $row === false ? null : $row;
    }

    public static function all(): array
    {
        return Database::run(
            'SELECT tl.*, u.email AS created_by_email
             FROM trial_links tl
             LEFT JOIN users u ON u.id = tl.created_by
             ORDER BY tl.id DESC'
        )->fetchAll();
    }

    public static function create(int $createdBy, int $level, int $days, int $maxUses): int
    {
        Database::run(
            'INSERT INTO trial_links (code, level, days, max_uses, created_by, created_at)
             VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)',
            [self::generateCode(), max(1, min(3, $level)), max(1, min(90, $days)), max(1, $maxUses), $createdBy]
        );

        return (int) Database::connection()->lastInsertId();
    }

    public static function consume(int $id): void
    {
        Database::run(
            'UPDATE trial_links SET used_count = used_count + 1 WHERE id = ?',
            [$id]
        );
    }

    public static function toggle(int $id): void
    {
        Database::run(
            'UPDATE trial_links SET enabled = 1 - enabled WHERE id = ?',
            [$id]
        );
    }

    public static function delete(int $id): void
    {
        Database::run('DELETE FROM trial_links WHERE id = ?', [$id]);
    }

    /** Human-readable label for a trial level (1=Silver, 2=Gold, 3=Platinum). */
    public static function levelLabel(int $level): string
    {
        return match ($level) {
            2       => 'Gold',
            3       => 'Platinum',
            default => 'Silver',
        };
    }
}