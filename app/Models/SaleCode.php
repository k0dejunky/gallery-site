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

    /**
     * The final price and access level a validated code gives on a plan.
     *
     * ONE place, because the manual checkout and the Braintree checkout used
     * to compute this differently: the Braintree path ignored the code's
     * discount entirely (it charged the plan price) and then looked the code
     * up by a column that does not exist, so the member was charged at
     * Braintree while the local row was never written.
     *
     * @param array<string, mixed> $codeRow a row from findValid()
     * @return array{price: float, level: int}
     */
    public static function apply(array $codeRow, float $price, int $planLevel): array
    {
        $price = max(0.0, $price);

        $type  = (string) ($codeRow['discount_type'] ?? 'none');
        $value = (float) ($codeRow['discount_value'] ?? 0);

        if ($type === 'percent') {
            $price -= $price * ($value / 100);
        } elseif ($type === 'fixed') {
            $price -= $value;
        }

        return [
            'price' => (float) max(0, round($price, 2)),
            'level' => max($planLevel, (int) ($codeRow['upgrade_level'] ?? 0)),
        ];
    }

    /**
     * A price of zero means there is nothing to send to a payment processor.
     */
    public static function isFree(float $price): bool
    {
        return round($price, 2) <= 0.0;
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

    /**
     * Issue the single-use 100%-off code that IS a gift.
     *
     * The code is the token: the recipient redeems it at checkout through the
     * normal sale-code field, and the subscription records `sale_code_id`. No
     * recipient entity, no delivery secret, no separate redemption table.
     */
    public static function createGift(int $purchaseId, int $purchaserId, int $planId, int $targetLevel, string $name = ''): int
    {
        do {
            $code = 'GIFT-' . strtoupper(bin2hex(random_bytes(3)))
                . '-' . strtoupper(bin2hex(random_bytes(3)));
            $exists = Database::run('SELECT id FROM sale_codes WHERE code = ? LIMIT 1', [$code])->fetch();
        } while ($exists);

        Database::run(
            'INSERT INTO sale_codes
                (sale_id, name, gift_purchaser_id, gift_plan_id, gift_purchase_id, code,
                 max_uses, used_count, active, discount_type, discount_value, upgrade_level, target_level)
             VALUES (NULL, ?, ?, ?, ?, ?, 1, 0, 1, ?, 100, NULL, ?)',
            [
                $name !== '' ? $name : 'Gift',
                $purchaserId,
                $planId,
                $purchaseId,
                $code,
                'percent',
                $targetLevel,
            ]
        );

        return (int) Database::connection()->lastInsertId();
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
