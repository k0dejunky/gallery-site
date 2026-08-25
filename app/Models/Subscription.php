<?php

namespace App\Models;

use App\Core\Database;

/**
 * Data access for subscriptions. A subscription ties a user to a plan and
 * tracks whether it is active, pending approval, cancelled or expired.
 * Payments are manual/placeholder for now, so an admin approves each one.
 */
class Subscription
{
    /**
     * Whether the given user currently has a usable subscription: an active
     * one whose expiry (if any) is still in the future.
     */
    public static function isActive(int $userId): bool
    {
        return self::activeFor($userId) !== null;
    }

    /**
     * The user's current active subscription joined with its plan, or null
     * when they do not have usable access.
     */
    public static function activeFor(int $userId): ?array
    {
        $row = Database::run(
            'SELECT s.*, p.name AS plan_name, p.billing_cycle AS billing_cycle,
                    COALESCE(s.access_level, p.level) AS plan_level
             FROM subscriptions s
             JOIN plans p ON p.id = s.plan_id
             WHERE s.user_id = ?
               AND s.status = ?
               AND (s.expires_at IS NULL OR s.expires_at > CURRENT_TIMESTAMP)
             ORDER BY s.starts_at DESC
             LIMIT 1',
            [$userId, 'active']
        )->fetch();

        return $row ?: null;
    }

    /**
     * Whether the given user's active subscription reaches at least the given
     * plan level. Users without a subscription never reach a level.
     */
    public static function hasMinLevel(int $userId, int $minLevel): bool
    {
        $active = self::activeFor($userId);

        return $active !== null && (int) $active['plan_level'] >= $minLevel;
    }

    /**
     * Every subscription a user has ever had (newest first), joined with its
     * plan name, for their membership history page.
     */
    public static function forUser(int $userId): array
    {
        return Database::run(
            'SELECT s.*, p.name AS plan_name, p.billing_cycle AS billing_cycle,
                    s.price_paid, sa.name AS sale_name, sc.code AS sale_code,
                    pp.name AS payment_name, pp.provider AS payment_provider
             FROM subscriptions s
             JOIN plans p ON p.id = s.plan_id
             LEFT JOIN sales sa ON sa.id = s.sale_id
             LEFT JOIN sale_codes sc ON sc.id = s.sale_code_id
             LEFT JOIN payment_processors pp ON pp.id = s.payment_processor_id
             WHERE s.user_id = ?
             ORDER BY s.id DESC',
            [$userId]
        )->fetchAll();
    }

    /**
     * The user's most recent pending subscription joined with its plan, or
     * null when they have none awaiting approval.
     */
    public static function pendingFor(int $userId): ?array
    {
        $row = Database::run(
            'SELECT s.*, p.name AS plan_name, p.billing_cycle AS billing_cycle
             FROM subscriptions s
             JOIN plans p ON p.id = s.plan_id
             WHERE s.user_id = ? AND s.status = ?
             ORDER BY s.id DESC
             LIMIT 1',
            [$userId, 'pending']
        )->fetch();

        return $row ?: null;
    }

    /**
     * Every subscription (newest first) joined with the owning user and plan,
     * for the admin subscriptions list.
     */
    public static function all(): array
    {
        return Database::run(
            'SELECT s.*, u.email AS user_email, p.name AS plan_name, p.billing_cycle AS billing_cycle,
                    s.price_paid, sa.name AS sale_name, sc.code AS sale_code,
                    pp.name AS payment_name, pp.provider AS payment_provider
             FROM subscriptions s
             JOIN users u ON u.id = s.user_id
             JOIN plans p ON p.id = s.plan_id
             LEFT JOIN sales sa ON sa.id = s.sale_id
             LEFT JOIN sale_codes sc ON sc.id = s.sale_code_id
             LEFT JOIN payment_processors pp ON pp.id = s.payment_processor_id
             ORDER BY s.id DESC'
        )->fetchAll();
    }

    /**
     * Fetch a single subscription row by id, or null when it does not exist.
     */
    public static function find(int $id): ?array
    {
        $row = Database::run(
            'SELECT * FROM subscriptions WHERE id = ? LIMIT 1',
            [$id]
        )->fetch();

        return $row ?: null;
    }

    /**
     * Create a subscription request. Payments are manual/placeholder, so new
     * subscriptions start as "pending" until an admin approves them. When a
     * payment processor was selected at checkout, its id and a transaction
     * reference are recorded on the subscription for the admin's reference.
     */
    public static function create(int $userId, int $planId, ?string $code = null, bool $applySale = true, ?int $paymentProcessorId = null, ?string $transactionRef = null): int
    {
        $plan = Plan::find($planId);
        if ($plan === null) {
            throw new \RuntimeException('That plan is not available.');
        }

        $db = Database::connection();
        $db->beginTransaction();
        try {
            $sale = null;
            $saleCode = null;
            if ($applySale) {
                if ($code !== null && trim($code) !== '') {
                    $saleCode = SaleCode::findValid($code, $planId);
                    if ($saleCode === null) {
                        throw new \RuntimeException('That sale code is invalid, expired, or used up.');
                    }
                    $sale = Sale::activeForPlan($planId);
                    if ($sale === null && !empty($saleCode['sale_id'])) {
                        $sale = Sale::find((int) $saleCode['sale_id']);
                    }
                } else {
                    $sale = Sale::activeForPlan($planId);
                }
            }

            $price = $sale !== null ? (float) $sale['sale_price'] : (float) $plan['price'];
            $accessLevel = (int) ($plan['level'] ?? Plan::SILVER_LEVEL);
            if ($saleCode !== null) {
                if ($saleCode['discount_type'] === 'percent') {
                    $price -= $price * ((float) $saleCode['discount_value'] / 100);
                } elseif ($saleCode['discount_type'] === 'fixed') {
                    $price -= (float) $saleCode['discount_value'];
                }
                $price = max(0, round($price, 2));
                $accessLevel = max($accessLevel, (int) ($saleCode['upgrade_level'] ?? 0));
            }
            Database::run(
                'INSERT INTO subscriptions (user_id, plan_id, status, sale_id, sale_code_id, price_paid, access_level, payment_processor_id, transaction_ref, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)',
                [$userId, $planId, 'pending', $sale['id'] ?? null, $saleCode['id'] ?? null, $price, $accessLevel, $paymentProcessorId, $transactionRef !== null && $transactionRef !== '' ? $transactionRef : null]
            );

            if ($saleCode !== null) {
                if (!SaleCode::redeem((int) $saleCode['id'])) {
                    throw new \RuntimeException('That sale code has just reached its usage limit.');
                }
            }

            $id = (int) $db->lastInsertId();
            $db->commit();
            return $id;
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Approve a pending subscription: mark it active and compute its expiry
     * from the plan's billing cycle (lifetime plans never expire).
     */
    public static function approve(int $id): void
    {
        $row = Database::run(
            'SELECT s.*, p.billing_cycle AS billing_cycle
             FROM subscriptions s
             JOIN plans p ON p.id = s.plan_id
             WHERE s.id = ? LIMIT 1',
            [$id]
        )->fetch();

        if ($row === false) {
            return;
        }

        $expires = null;
        if ($row['billing_cycle'] === 'monthly') {
            $expires = date('Y-m-d H:i:s', strtotime('+1 month'));
        } elseif ($row['billing_cycle'] === 'yearly') {
            $expires = date('Y-m-d H:i:s', strtotime('+1 year'));
        }

        Database::run(
            'UPDATE subscriptions
             SET status = ?, starts_at = CURRENT_TIMESTAMP, expires_at = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ?',
            ['active', $expires, $id]
        );
    }

    /**
     * Activate a pending subscription after its biller's postback confirmed
     * payment: approve it (status/expiry per billing cycle) and replace the
     * placeholder PENDING-… reference with the biller's transaction id.
     */
    public static function activateWithTransaction(int $id, string $billerRef): void
    {
        self::approve($id);

        Database::run(
            'UPDATE subscriptions SET transaction_ref = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?',
            [$billerRef, $id]
        );
    }

    /**
     * Cancel a subscription. Access stops at the current expiry (or
     * immediately for lifetime plans, which have no expiry date).
     */
    public static function cancel(int $id): void
    {
        $row = self::find($id);

        if ($row === null) {
            return;
        }

        $expires = $row['expires_at'] ?? date('Y-m-d H:i:s');

        Database::run(
            'UPDATE subscriptions
             SET status = ?, expires_at = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ?',
            ['cancelled', $expires, $id]
        );
    }

    /**
     * Delete a subscription record entirely.
     */
    public static function delete(int $id): void
    {
        Database::run('DELETE FROM subscriptions WHERE id = ?', [$id]);
    }

    /**
     * Human-readable label for a subscription status.
     */
    public static function statusLabel(string $status): string
    {
        $labels = [
            'pending'   => 'Pending',
            'active'    => 'Active',
            'past_due'  => 'Past Due',
            'cancelled' => 'Cancelled',
            'expired'   => 'Expired',
        ];

        return $labels[$status] ?? $status;
    }

    /**
     * Find the subscription whose transaction_ref matches a Braintree
     * subscription ID (stored as "BT-<braintree_id>").
     */
    public static function findByBraintreeId(string $btSubscriptionId): ?array
    {
        $row = Database::run(
            'SELECT s.*, p.name AS plan_name, p.billing_cycle AS billing_cycle
             FROM subscriptions s
             JOIN plans p ON p.id = s.plan_id
             WHERE s.transaction_ref = ?
             ORDER BY s.id DESC
             LIMIT 1',
            ['BT-' . $btSubscriptionId]
        )->fetch();

        return $row ?: null;
    }
}
