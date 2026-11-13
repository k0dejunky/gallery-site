<?php

namespace App\Models;

use App\Core\Database;

/**
 * Gifts: a membership bought for someone else.
 *
 * A gift is a one-off purchase (`purchases.item_type = 'gift'`, item_id = the
 * plan) plus the single-use 100%-off sale code that pays for the recipient's
 * membership. The code IS the gift: there is no recipient account, no
 * delivery token and no redemption table. The subscription created with the
 * code records `sale_code_id`, which is the redemption trail.
 *
 * Codes are issued LAZILY - when the purchaser's gifts page is read - so the
 * card path, the PayPal webhook and an admin marking an offline purchase paid
 * all produce the code without a second write path. The unique key on
 * `sale_codes.gift_purchase_id` makes that idempotent.
 */
class Gift
{
    public const STATE_PENDING   = 'pending';
    public const STATE_AVAILABLE = 'available';
    public const STATE_REDEEMED  = 'redeemed';
    public const STATE_EXPIRED   = 'expired';
    public const STATE_VOID      = 'void';

    /**
     * The code issued for a purchase, or null.
     */
    public static function codeForPurchase(int $purchaseId): ?array
    {
        $row = Database::run(
            'SELECT * FROM sale_codes WHERE gift_purchase_id = ? LIMIT 1',
            [$purchaseId]
        )->fetch();

        return $row ?: null;
    }

    /**
     * Ensure a settled gift purchase has its code.
     *
     * Returns the code row, or null when the purchase is not a settled gift
     * (pending, refunded, disputed, or for a plan that no longer exists).
     */
    public static function ensureCode(int $purchaseId): ?array
    {
        $purchase = Database::run('SELECT * FROM purchases WHERE id = ? LIMIT 1', [$purchaseId])->fetch();

        if ($purchase === false || (string) $purchase['item_type'] !== Purchase::TYPE_GIFT) {
            return null;
        }

        $status = (string) $purchase['status'];
        if (!in_array($status, [Purchase::STATUS_PAID, Purchase::STATUS_GRANTED], true)) {
            return null;
        }

        $existing = self::codeForPurchase($purchaseId);
        if ($existing !== null) {
            return $existing;
        }

        $plan = Plan::find((int) $purchase['item_id']);
        if ($plan === null) {
            return null;
        }

        try {
            SaleCode::createGift(
                $purchaseId,
                (int) $purchase['user_id'],
                (int) $plan['id'],
                (int) ($plan['level'] ?? 1),
                'Gift: ' . (string) $plan['name']
            );
        } catch (\PDOException $e) {
            // A concurrent page load won the race: the unique key refused the
            // second insert, so read the winner's row (that is the point of
            // the key - never issue two codes for one purchase).
            $existing = self::codeForPurchase($purchaseId);
            if ($existing !== null) {
                return $existing;
            }
            throw $e;
        }

        return self::codeForPurchase($purchaseId);
    }

    /**
     * Every gift a member bought, newest first, with the plan, the code and
     * whether it has been redeemed.
     *
     * Paid gifts without a code get one here (see ensureCode), so the page
     * never shows "processing" for a charge that has already settled.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function forPurchaser(int $userId): array
    {
        $rows = Database::run(
            "SELECT p.id AS purchase_id, p.status AS purchase_status, p.amount,
                    p.created_at AS purchased_at, p.note,
                    pl.name AS plan_name, pl.level AS plan_level, pl.billing_cycle,
                    sc.id AS code_id, sc.code, sc.active AS code_active,
                    sc.used_count, sc.max_uses,
                    sub.id AS redeemed_sub_id, sub.membership_number,
                    sub.created_at AS redeemed_at
             FROM purchases p
             LEFT JOIN plans pl ON pl.id = p.item_id
             LEFT JOIN sale_codes sc ON sc.gift_purchase_id = p.id
             LEFT JOIN subscriptions sub ON sub.sale_code_id = sc.id
             WHERE p.user_id = ? AND p.item_type = 'gift'
             ORDER BY p.id DESC",
            [$userId]
        )->fetchAll();

        $issued = false;
        foreach ($rows as $row) {
            if ($row['code_id'] === null && self::ensureCode((int) $row['purchase_id']) !== null) {
                $issued = true;
            }
        }

        // Re-read only when a code was issued on this read.
        if ($issued) {
            $rows = Database::run(
                "SELECT p.id AS purchase_id, p.status AS purchase_status, p.amount,
                        p.created_at AS purchased_at, p.note,
                        pl.name AS plan_name, pl.level AS plan_level, pl.billing_cycle,
                        sc.id AS code_id, sc.code, sc.active AS code_active,
                        sc.used_count, sc.max_uses,
                        sub.id AS redeemed_sub_id, sub.membership_number,
                        sub.created_at AS redeemed_at
                 FROM purchases p
                 LEFT JOIN plans pl ON pl.id = p.item_id
                 LEFT JOIN sale_codes sc ON sc.gift_purchase_id = p.id
                 LEFT JOIN subscriptions sub ON sub.sale_code_id = sc.id
                 WHERE p.user_id = ? AND p.item_type = 'gift'
                 ORDER BY p.id DESC",
                [$userId]
            )->fetchAll();
        }

        foreach ($rows as &$row) {
            $row['state'] = self::state($row);
            $row['state_label'] = self::stateLabel((string) $row['state']);
        }
        unset($row);

        return $rows;
    }

    /**
     * The state of one gift row.
     *
     * @param array<string, mixed> $row
     */
    public static function state(array $row): string
    {
        $purchaseStatus = (string) ($row['purchase_status'] ?? '');

        if ($purchaseStatus === Purchase::STATUS_PENDING) {
            return self::STATE_PENDING;
        }
        if (in_array($purchaseStatus, [Purchase::STATUS_REFUNDED, Purchase::STATUS_DISPUTED], true)) {
            return self::STATE_VOID;
        }
        if (!empty($row['redeemed_sub_id'])) {
            return self::STATE_REDEEMED;
        }
        if ($row['code_id'] === null) {
            return self::STATE_PENDING;
        }
        if ((int) ($row['code_active'] ?? 0) === 1 && (int) ($row['used_count'] ?? 0) < (int) ($row['max_uses'] ?? 1)) {
            return self::STATE_AVAILABLE;
        }

        return self::STATE_EXPIRED;
    }

    public static function stateLabel(string $state): string
    {
        $labels = [
            self::STATE_PENDING   => 'Awaiting payment',
            self::STATE_AVAILABLE => 'Ready to give',
            self::STATE_REDEEMED  => 'Redeemed',
            self::STATE_EXPIRED   => 'Expired',
            self::STATE_VOID      => 'Refunded',
        ];

        return $labels[$state] ?? ucfirst($state);
    }
}
