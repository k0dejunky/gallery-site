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
     * SQL fragment for a usable subscription on the given alias: active or
     * cancelled and, when an expiry is stored, still in the future. Single
     * source of truth for every eligibility query (membership access, chat
     * eligibility, email recipients) so a drift here cannot grant or revoke
     * access inconsistently across features.
     */
    public static function activeWhere(string $alias = 's'): string
    {
        // A PAST DUE subscription keeps its access for a grace period. The
        // Braintree webhook has always said "flag but don't cancel - give a
        // grace period", but there was no grace period: the flag was set and
        // the member lost their access on the first failed card, with no way
        // to fix the card. The window is measured from past_due_at, so it
        // starts when the subscription went past due rather than whenever
        // the row was last touched.
        return "($alias.status IN ('active', 'cancelled')"
            . " OR ($alias.status = 'past_due' AND $alias.past_due_at IS NOT NULL"
            . " AND $alias.past_due_at > DATE_SUB(NOW(), INTERVAL " . self::pastDueGraceDays() . " DAY)))"
            . " AND ($alias.expires_at IS NULL OR $alias.expires_at > CURRENT_TIMESTAMP)";
    }

    /**
     * How many days a past-due subscription keeps its access.
     *
     * Zero means no grace, which restores the old behaviour.
     */
    public static function pastDueGraceDays(): int
    {
        return max(0, (int) env_value('PAST_DUE_GRACE_DAYS', '7'));
    }

    /**
     * User ids holding a chat-eligible subscription (a plan flagged
     * can_chat). Shared by the chat feature, broadcasts and the operator
     * bridge so "who can chat" is decided in exactly one place.
     *
     * @return array<int, int>
     */
    public static function chatEligibleUserIds(): array
    {
        $rows = Database::run(
            'SELECT DISTINCT s.user_id
             FROM subscriptions s
             JOIN plans p ON p.id = s.plan_id
             WHERE p.can_chat = 1 AND ' . self::activeWhere('s')
        )->fetchAll();

        return array_map('intval', array_column($rows, 'user_id'));
    }
    /**
     * Whether the given user currently has a usable subscription: an active
     * one whose expiry (if any) is still in the future.
     */
    public static function isActive(int $userId): bool
    {
        return self::activeFor($userId) !== null;
    }

    /**
     * The user's current subscription that grants access, or null when they
     * do not have usable access. A cancelled subscription still grants access
     * until its expiry date (the member already paid for that period); a
     * lifetime plan cancelled without a stored expiry loses access at cancel
     * time (its expires_at is set to "now").
     */
    public static function activeFor(int $userId): ?array
    {
        $row = Database::run(
            'SELECT s.*, p.name AS plan_name, p.billing_cycle AS billing_cycle,
                    COALESCE(s.access_level, p.level) AS plan_level
             FROM subscriptions s
             JOIN plans p ON p.id = s.plan_id
             WHERE s.user_id = ?
               AND s.status IN (?, ?)
               AND (s.expires_at IS NULL OR s.expires_at > CURRENT_TIMESTAMP)
             ORDER BY s.id DESC
             LIMIT 1',
            [$userId, 'active', 'cancelled']
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

            // The capacity is reserved here, inside the transaction, with the
            // sale row locked - so the last slot can only be sold once.
            if ($sale !== null && !Sale::reserveSlot((int) $sale['id'])) {
                throw new \RuntimeException('That offer has just sold out.');
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
            $id = (int) $db->lastInsertId();
            Database::run(
                "UPDATE subscriptions SET membership_number = LPAD(CAST(id AS CHAR), 5, '0') WHERE id = ?",
                [$id]
            );

            if ($saleCode !== null) {
                if (!SaleCode::redeem((int) $saleCode['id'])) {
                    throw new \RuntimeException('That sale code has just reached its usage limit.');
                }
            }

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
     * Grant a one-time free trial at the given membership level for the given
     * number of days. Rejected when the user has already had a trial (TRIAL-
     * ref) or already holds an active membership. Level and days are clamped
     * (1-3 / 1-90). Returns the new subscription id or null.
     */
    public static function grantTrialFor(int $userId, int $level, int $days): ?int
    {
        if ($userId <= 0) {
            return null;
        }

        $level = max(1, min(3, $level));
        $days  = max(1, min(90, $days));

        $hadTrial = (int) Database::run(
            "SELECT COUNT(*) FROM subscriptions WHERE user_id = ? AND transaction_ref LIKE 'TRIAL-%'",
            [$userId]
        )->fetchColumn();
        if ($hadTrial > 0) {
            return null;
        }

        if (self::activeFor($userId) !== null) {
            return null;
        }

        $plan = Database::run(
            'SELECT id FROM plans WHERE level = ? AND active = 1 ORDER BY price ASC LIMIT 1',
            [$level]
        )->fetch();
        if ($plan === false) {
            return null;
        }

        $expiresAt = gmdate('Y-m-d H:i:s', time() + $days * 86400);

        Database::run(
            "INSERT INTO subscriptions (user_id, plan_id, status, price_paid, access_level, transaction_ref, expires_at, created_at, updated_at)
             VALUES (?, ?, 'active', 0, ?, 'TRIAL-" . bin2hex(random_bytes(6)) . "', ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)",
            [$userId, (int) $plan['id'], $level, $expiresAt]
        );
        $id = (int) Database::connection()->lastInsertId();
        Database::run(
            'UPDATE subscriptions SET membership_number = LPAD(CAST(id AS CHAR), 5, \'0\') WHERE id = ?',
            [$id]
        );

        return $id;
    }

    /**
     * Grant a one-time free trial at the Silver level for the given number of
     * days (the original admin tool; see grantTrialFor for a configurable
     * level).
     */
    public static function grantTrial(int $userId, int $days): ?int
    {
        return self::grantTrialFor($userId, Plan::SILVER_LEVEL, $days);
    }

    /**
     * The subscription states a payment may still activate.
     *
     * A cancelled (or already ended) subscription is FINAL: payment
     * processors retry postbacks, and without this a replayed
     * "subscription activated" would resurrect a cancelled membership with a
     * free billing period.
     */
    public const APPROVABLE = ['pending', 'past_due'];

    /**
     * Approve a pending subscription: mark it active and compute its expiry
     * from the plan's billing cycle (lifetime plans never expire).
     *
     * A subscription that is not in an approvable state is left alone.
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

        if (!in_array((string) $row['status'], self::APPROVABLE, true)) {
            return;
        }

        // The period runs from the PURCHASE date, not from the moment the
        // payment was approved: a subscription that sat in the queue for
        // three days used to still grant a full 30 days from the approval.
        //
        // A RETRY (a subscription that went past due and is paid again) is
        // different: its old expiry is behind it, and counting from the
        // purchase date would hand out a period that had already ended. It
        // starts from now instead.
        $from = !empty($row['created_at']) ? (string) $row['created_at'] : 'now';
        $oldExpiry = $row['expires_at'] ?? null;
        if ($oldExpiry !== null && $oldExpiry !== '' && strtotime((string) $oldExpiry) < time()) {
            $from = 'now';
        }

        $expires = null;
        if ($row['billing_cycle'] === 'monthly') {
            $expires = date('Y-m-d H:i:s', strtotime('+1 month', strtotime($from)));
        } elseif ($row['billing_cycle'] === 'yearly') {
            $expires = date('Y-m-d H:i:s', strtotime('+1 year', strtotime($from)));
        }

        // The status is in the WHERE clause as well, so a race between the
        // read and the write cannot resurrect a cancelled subscription.
        Database::run(
            'UPDATE subscriptions
             SET status = ?, past_due_at = NULL, starts_at = CURRENT_TIMESTAMP, expires_at = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND status IN (?, ?)',
            ['active', $expires, $id, 'pending', 'past_due']
        );
    }

    /**
     * How many days before the term ends a payment still counts as the
     * renewal of that term.
     *
     * A gateway charges a recurring subscription ON the day the term ends,
     * but some of them charge a little early. A payment that arrives well
     * BEFORE the term ends is for the term that was already granted, and it
     * must not hand out a second one.
     */
    public const RENEWAL_WINDOW_DAYS = 3;

    /**
     * Count a payment and extend the subscription by one billing period.
     *
     * A renewal arrives as a payment postback: PayPal sends
     * PAYMENT.SALE.COMPLETED (the sale carries the subscription's id in
     * billing_agreement_id), Braintree sends subscription_charged. The
     * subscription it belongs to is found by the caller; this method does
     * the accounting.
     *
     * The payment is identified by $paymentKey, so a replayed postback
     * grants nothing: a member is never given two months for one payment.
     *
     * $paidAt is when the payment happened (the postback's timestamp). A
     * payment made BEFORE the current term ends belongs to the term that
     * was already granted, so it grants nothing - that is what keeps the
     * first month's payment from handing out a second month when the
     * activation postback already granted the first one.
     *
     * $receiptNumber is what the member is shown as the receipt (the
     * gateway's invoice number when it gave one, the payment id otherwise).
     *
     * @return string 'renewed' | 'already' | 'duplicate' | 'cancelled' | 'ignored'
     */
    public static function renew(int $id, string $paymentKey, string $paidAt = '',
        string $receiptNumber = ''): string
    {
        if ($paymentKey === '') {
            // Without a payment id there is no way to tell a renewal from a
            // replay, and a replayed postback would grant a second period.
            error_log('[subscription] a payment for subscription #' . $id . ' was ignored: it carried no payment id');
            return 'ignored';
        }

        $row = Database::run(
            'SELECT s.*, p.billing_cycle AS billing_cycle, p.name AS plan_name
             FROM subscriptions s
             JOIN plans p ON p.id = s.plan_id
             WHERE s.id = ? LIMIT 1',
            [$id]
        )->fetch();

        if ($row === false) {
            return 'ignored';
        }

        $status = (string) $row['status'];

        if ($status === 'cancelled') {
            // A cancelled subscription is final: access does not come back.
            // The member may still have been charged, so the caller alerts
            // the admin and somebody reconciles it by hand.
            return 'cancelled';
        }

        if ((string) ($row['last_payment_ref'] ?? '') === $paymentKey) {
            return 'duplicate';
        }

        $cycle = $row['billing_cycle'] ?? '';
        if ($cycle !== 'monthly' && $cycle !== 'yearly') {
            // A lifetime plan has no term to extend.
            return 'ignored';
        }

        $now      = time();
        $oldExpiry = $row['expires_at'] ?? null;
        $oldTs    = ($oldExpiry !== null && $oldExpiry !== '') ? strtotime((string) $oldExpiry) : 0;

        if (in_array($status, self::APPROVABLE, true)) {
            // The first payment, or a retry after a failed one.
            self::approve($id);
            self::recordPayment($id, $paymentKey, $receiptNumber);
            return 'renewed';
        }

        // A live subscription: this payment has to be for the term that is
        // running out. A payment that arrives well before the term ends is
        // for the term that was already granted.
        if ($oldTs > 0 && $paidAt !== '') {
            $paidTs = strtotime($paidAt);
            if ($paidTs !== false && $paidTs < $oldTs - (self::RENEWAL_WINDOW_DAYS * 86400)) {
                // Remember the payment so its replay is a no-op, but grant
                // nothing: the term it paid for is the one that is running.
                self::recordPayment($id, $paymentKey, $receiptNumber);
                return 'already';
            }
        }

        // The renewal: the term runs on from where it ended, or from now if
        // it had already lapsed.
        $base = ($oldTs > $now) ? $oldTs : $now;
        $expires = $cycle === 'monthly'
            ? date('Y-m-d H:i:s', strtotime('+1 month', $base))
            : date('Y-m-d H:i:s', strtotime('+1 year', $base));

        Database::run(
            'UPDATE subscriptions
             SET status = ?, past_due_at = NULL, expires_at = ?, updated_at = CURRENT_TIMESTAMP,
                 last_payment_ref = ?, periods_granted = periods_granted + 1
             WHERE id = ? AND status <> ?',
            ['active', $expires, $paymentKey, $id, 'cancelled']
        );

        return 'renewed';
    }

    /**
     * Remember which payment was counted, so its postback cannot be counted
     * twice, and keep the period counter in step.
     */
    private static function recordPayment(int $id, string $paymentKey, string $receiptNumber = ''): void
    {
        Database::run(
            'UPDATE subscriptions
             SET last_payment_ref = ?, periods_granted = periods_granted + 1, updated_at = CURRENT_TIMESTAMP
             WHERE id = ?',
            [$paymentKey, $id]
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

        $ref = (string) ($row['transaction_ref'] ?? '');

        // Cancel at the GATEWAY first, so the member stops being charged
        // even if the local update fails. A local cancel that does not
        // reach the processor is how a member ends up paying for a
        // membership they cancelled.
        $gatewayCancelled = self::cancelAtGateway($row, $ref);

        Database::run(
            'UPDATE subscriptions
             SET status = ?, expires_at = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ?',
            ['cancelled', $expires, $id]
        );

        if ($gatewayCancelled === false) {
            // The local row is cancelled but the processor may still be
            // billing. Say so loudly: the reconciliation worker and the
            // gateway's own webhooks may fix it later, but the operator
            // has to know it happened.
            error_log('[subscription-cancel] the gateway did not confirm the cancellation for subscription ' . $id . ' (ref ' . $ref . ')');
        }
    }

    /**
     * Cancel a subscription at its payment processor.
     *
     * Returns true when the processor confirmed, false when it could not be
     * reached, and null when the subscription is not processor-billed at
     * all (a manual, trial or complimentary membership).
     *
     * @return bool|null
     */
    private static function cancelAtGateway(array $row, string $ref)
    {
        $processor = null;
        if (!empty($row['payment_processor_id'])) {
            $processor = \App\Models\PaymentProcessor::find((int) $row['payment_processor_id']);
        }

        if (strpos($ref, 'PAYPAL-') === 0) {
            $paypalId = substr($ref, strlen('PAYPAL-'));

            try {
                $gateway = $processor !== null
                    ? \App\Core\PayPalGateway::fromConfig($processor)
                    : null;

                if ($gateway === null) {
                    error_log('[subscription-cancel] no PayPal gateway for ' . $ref);
                    return false;
                }

                if (!$gateway->cancelSubscription($paypalId)) {
                    error_log('[paypal-cancel] PayPal did not confirm cancellation for ' . $ref);
                    return false;
                }

                return true;
            } catch (\Throwable $e) {
                error_log('[paypal-cancel] PayPal cancellation failed for ' . $ref . ': ' . $e->getMessage());
                return false;
            }
        }

        if (strpos($ref, 'BT-') === 0) {
            $btId = substr($ref, strlen('BT-'));

            try {
                $gateway = $processor !== null
                    ? \App\Core\BraintreeGateway::fromConfig($processor)
                    : null;

                if ($gateway === null) {
                    error_log('[subscription-cancel] no Braintree gateway for ' . $ref);
                    return false;
                }

                return $gateway->cancelSubscription($btId);
            } catch (\Throwable $e) {
                error_log('[braintree-cancel] cancellation failed for ' . $ref . ': ' . $e->getMessage());
                return false;
            }
        }

        // A trial, a manual grant or a complimentary membership: there is
        // no processor to cancel.
        return null;
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
     * Human-readable tier name for a membership level (the "min_level" /
     * plan "level" column). 0 = Free, 1-3 = the paid silver-to-platinum tiers.
     */
    public static function levelLabel(int $level): string
    {
        $labels = [0 => 'Free', 1 => 'Silver', 2 => 'Gold', 3 => 'Platinum'];

        return $labels[$level] ?? 'Level ' . $level;
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
