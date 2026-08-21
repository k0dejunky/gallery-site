<?php

namespace App\Models;

use App\Core\Database;

class SaleCode
{
    public static function findValid(string $code, int $planId): ?array
    {
        $row = Database::run(
            'SELECT c.*, s.name AS sale_name, s.sale_price, s.max_subscriptions,
                    s.ends_at, s.active AS sale_active, s.plan_id
             FROM sale_codes c
             JOIN plans p ON p.id = ?
             LEFT JOIN sales s ON s.id = c.sale_id
             WHERE c.code = ? AND c.active = 1
               AND p.level = c.target_level
               AND (s.id IS NULL OR s.plan_id = p.id)
               AND (s.id IS NULL OR (s.active = 1 AND (s.ends_at IS NULL OR s.ends_at > CURRENT_TIMESTAMP)))
               AND (c.max_uses IS NULL OR c.used_count < c.max_uses)
               AND (s.id IS NULL OR s.max_subscriptions IS NULL OR
                    (SELECT COUNT(*) FROM subscriptions x
                     WHERE x.sale_id = s.id AND x.status IN (\'pending\', \'active\')) < s.max_subscriptions)
             LIMIT 1',
            [$planId, strtoupper(trim($code))]
        )->fetch();

        if (!$row) {
            return null;
        }

        $discountType = (string) $row['discount_type'];
        $discountValue = (float) $row['discount_value'];
        if (!in_array($discountType, ['none', 'fixed', 'percent'], true)) {
            return null;
        }
        if (($discountType === 'fixed' && $discountValue < 0)
            || ($discountType === 'percent' && ($discountValue < 0 || $discountValue > 100))
            || (int) $row['target_level'] < 1
            || ($row['upgrade_level'] !== null && (int) $row['upgrade_level'] < 1)) {
            return null;
        }

        return $row;
    }

    public static function create(?int $saleId, string $code, ?int $maxUses, string $discountType = 'none', float $discountValue = 0, ?int $upgradeLevel = null, int $targetLevel = 1, ?string $name = null): int
    {
        Database::run(
            'INSERT INTO sale_codes (sale_id, name, code, max_uses, used_count, discount_type, discount_value, upgrade_level, target_level)
             VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?)',
            [$saleId, $name ?: null, strtoupper(trim($code)), $maxUses, $discountType, $discountValue, $upgradeLevel, $targetLevel]
        );

        return (int) Database::connection()->lastInsertId();
    }

    public static function generate(?int $saleId, int $maxUses, string $discountType = 'none', float $discountValue = 0, ?int $upgradeLevel = null, int $targetLevel = 1, ?string $name = null): array
    {
        do {
            $code = 'SALE-' . strtoupper(bin2hex(random_bytes(5)));
            $exists = Database::run('SELECT id FROM sale_codes WHERE code = ? LIMIT 1', [$code])->fetch();
        } while ($exists);

        $id = self::create($saleId, $code, $maxUses, $discountType, $discountValue, $upgradeLevel, $targetLevel, $name);
        return ['id' => $id, 'name' => $name, 'code' => $code, 'max_uses' => $maxUses, 'used_count' => 0, 'active' => 1];
    }

    public static function redeem(int $id): bool
    {
        $changed = Database::run(
            'UPDATE sale_codes
             SET used_count = used_count + 1,
                 active = CASE WHEN max_uses IS NOT NULL AND used_count + 1 >= max_uses THEN 0 ELSE active END
             WHERE id = ? AND active = 1 AND (max_uses IS NULL OR used_count < max_uses)',
            [$id]
        );

        return $changed->rowCount() === 1;
    }

    public static function forSale(int $saleId): array
    {
        return Database::run('SELECT * FROM sale_codes WHERE sale_id = ? ORDER BY id DESC', [$saleId])->fetchAll();
    }

    public static function all(): array
    {
        return Database::run(
            'SELECT c.*, s.name AS sale_name FROM sale_codes c LEFT JOIN sales s ON s.id = c.sale_id ORDER BY c.created_at DESC, c.id DESC'
        )->fetchAll();
    }
}
