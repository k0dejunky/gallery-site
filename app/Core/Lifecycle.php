<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\EmailQueue;

/**
 * Retention email lifecycle: dunning (payment failed, past due, expired) and
 * win-back messages on top of the existing subscription/webhook state machine.
 *
 * Every message is logged into subscription_email_log keyed by
 * (subscription_id, event_key), so each lifecycle email fires at most once per
 * subscription. Billing dunning bypasses the marketing opt-out (it is
 * account-critical); win-back honours it.
 */
final class Lifecycle
{
    /** Queue one lifecycle email for a subscription, exactly once. */
    public static function send(string $eventKey, int $subscriptionId, string $template, string $subject, array $data, ?string $scheduledAt = null): bool
    {
        if (EmailQueue::lifecycleAlreadySent($subscriptionId, $eventKey)) {
            return false;
        }

        $sub = self::subscription($subscriptionId);
        if ($sub === null) {
            return false;
        }

        $html = render_email($template, $data);
        $text = render_email($template . '.text', $data);

        $queueId = EmailQueue::enqueueTx((int) $sub['user_id'], $subject, $html, $text, $scheduledAt);

        if ($queueId === null) {
            return false;
        }

        EmailQueue::lifecycleRecord($subscriptionId, $eventKey, $queueId);

        return true;
    }

    /** Payment failed (Braintree subscription_charged_failed / failed). */
    public static function onPaymentFailed(int $subscriptionId): bool
    {
        $sub = self::subscription($subscriptionId);
        if ($sub === null) {
            return false;
        }

        return self::send('payment_failed', $subscriptionId, 'payment_failed', "Your payment didn't go through", [
            'planName'   => (string) ($sub['plan_name'] ?? 'your membership'),
            'amount'     => (float) ((float) ($sub['price_paid'] ?? 0) > 0 ? $sub['price_paid'] : $sub['price']),
            'attemptedAt' => date('F j, Y'),
            'manageUrl'  => absolute_url('/membership/my'),
        ]);
    }

    /** Subscription went past due (Braintree/PayPal suspension). */
    public static function onPastDue(int $subscriptionId): bool
    {
        $sub = self::subscription($subscriptionId);
        if ($sub === null) {
            return false;
        }

        return self::send('past_due', $subscriptionId, 'past_due', 'Your subscription is past due', [
            'planName'   => (string) ($sub['plan_name'] ?? 'your membership'),
            'dueAmount'  => (float) ((float) ($sub['price_paid'] ?? 0) > 0 ? $sub['price_paid'] : $sub['price']),
            'manageUrl'  => absolute_url('/membership/my'),
        ]);
    }

    /** Subscription expired. */
    public static function onExpired(int $subscriptionId): bool
    {
        $sub = self::subscription($subscriptionId);
        if ($sub === null) {
            return false;
        }

        return self::send('expired', $subscriptionId, 'expired', 'Your membership has ended', [
            'planName'  => (string) ($sub['plan_name'] ?? 'your membership'),
            'rejoinUrl' => absolute_url('/membership'),
        ]);
    }

    /** Win-back for a lapsed user (honours marketing opt-out). */
    public static function onWinback(int $subscriptionId): bool
    {
        $sub = self::subscription($subscriptionId);
        if ($sub === null || (int) ($sub['marketing_opt_out'] ?? 0) === 1) {
            return false;
        }

        return self::send('winback', $subscriptionId, 'winback', 'We miss you', [
            'planName'      => (string) ($sub['plan_name'] ?? 'your membership'),
            'galleryUrl'    => \App\Models\Traffic::buildUrl('/galleries', 'email-digest'),
            'membershipUrl' => \App\Models\Traffic::buildUrl('/membership', 'email-digest'),
        ]);
    }

    /**
     * Scan for renewals due within $daysBefore and send one reminder per
     * subscription. Returns the number of reminders queued.
     */
    public static function runRenewalReminders(int $daysBefore = 7): int
    {
        $rows = Database::run(
            'SELECT s.id
             FROM subscriptions s
             JOIN plans p ON p.id = s.plan_id
             WHERE s.status = ? AND s.expires_at IS NOT NULL
               AND s.expires_at > CURRENT_TIMESTAMP
               AND s.expires_at <= DATE_ADD(CURRENT_TIMESTAMP, INTERVAL ? DAY)',
            ['active', $daysBefore]
        )->fetchAll();

        $count = 0;
        foreach ($rows as $row) {
            $sub = self::subscription((int) $row['id']);
            if ($sub === null) {
                continue;
            }
            if (self::send('renewal_reminder', (int) $row['id'], 'renewal_reminder', 'Your renewal is coming up', [
                'planName'  => (string) ($sub['plan_name'] ?? 'your membership'),
                'renewsOn'  => \tzdate('F j, Y', (string) ($sub['expires_at'] ?? '')),
                'amount'    => (float) ((float) ($sub['price_paid'] ?? 0) > 0 ? $sub['price_paid'] : $sub['price']),
                'manageUrl' => absolute_url('/membership/my'),
            ])) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Scan for lapsed members (latest subscription expired/cancelled more than
     * $daysAfter ago, with no active sub) and queue a win-back to those who
     * have not opted out. Returns the number of win-backs queued.
     */
    public static function runWinbacks(int $daysAfter = 7): int
    {
        $rows = Database::run(
            'SELECT MAX(s.id) AS sub_id
             FROM subscriptions s
             JOIN users u ON u.id = s.user_id
             WHERE s.status IN (?, ?)
               AND NOT EXISTS (
                   SELECT 1 FROM subscriptions x WHERE x.user_id = s.user_id AND x.status = ?
               )
             GROUP BY s.user_id
             HAVING MAX(COALESCE(u.marketing_opt_out, 0)) = 0
                AND MAX(s.updated_at) <= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL ? DAY)',
            ['expired', 'cancelled', 'active', $daysAfter]
        )->fetchAll();

        $count = 0;
        foreach ($rows as $row) {
            if (self::onWinback((int) $row['sub_id'])) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * A subscription joined to its plan and user, or null.
     */
    private static function subscription(int $subscriptionId): ?array
    {
        return Database::run(
            'SELECT s.*, p.name AS plan_name, p.price,
                    u.id AS user_id, u.marketing_opt_out
             FROM subscriptions s
             JOIN plans p ON p.id = s.plan_id
             JOIN users u ON u.id = s.user_id
             WHERE s.id = ? LIMIT 1',
            [$subscriptionId]
        )->fetch() ?: null;
    }
}