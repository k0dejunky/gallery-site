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
 * subscription. User-keyed retention emails (members with no subscription to
 * key on, e.g. the inactive-member win-back) are logged in retention_email_log
 * keyed by (user_id, event_key) instead. Billing dunning bypasses the
 * marketing opt-out (it is account-critical); win-backs and trial-ending
 * nudges honour it.
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

    /**
     * A payment was received: the member's receipt.
     *
     * Sent once per payment, so a member who renews every month gets one
     * receipt per month. The payment id is what makes it one per payment.
     */
    public static function onPaymentReceived(int $subscriptionId, string $paymentKey,
        string $receiptNumber = ''): bool
    {
        $sub = self::subscription($subscriptionId);
        if ($sub === null) {
            return false;
        }

        $expires = (string) ($sub['expires_at'] ?? '');
        $amount = (float) ($sub['price_paid'] ?? 0);
        if ($amount <= 0) {
            $amount = (float) ($sub['price'] ?? 0);
        }

        return self::send(
            'payment_received_' . substr(sha1((string) $paymentKey), 0, 20),
            $subscriptionId,
            'payment_received',
            'Your payment was received',
            [
                'planName'      => (string) ($sub['plan_name'] ?? 'your membership'),
                'amount'        => $amount,
                'receiptNumber' => (string) ($receiptNumber !== '' ? $receiptNumber : $paymentKey),
                'paidAt'        => date('F j, Y'),
                'expiresAt'     => $expires !== '' ? \tzdate('F j, Y', $expires) : '',
                'manageUrl'     => absolute_url('/membership/my'),
            ]
        );
    }

    /** Win-back for a lapsed user (honours marketing opt-out). */
    public static function onWinback(int $subscriptionId): bool    {
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
     * subscription. Free trials are excluded - the trial-ending nudge is a
     * separate audience. $onlyUserId narrows the scan to one member so tests
     * can exercise the real query without touching live inboxes (0 = all).
     * Returns the number of reminders queued.
     */
    public static function runRenewalReminders(int $daysBefore = 7, int $onlyUserId = 0): int
    {
        $scope  = $onlyUserId > 0 ? ' AND s.user_id = ?' : '';
        $params = ['active', $daysBefore, 'TRIAL-%'];
        if ($onlyUserId > 0) {
            $params[] = $onlyUserId;
        }

        $rows = Database::run(
            'SELECT s.id
             FROM subscriptions s
             JOIN plans p ON p.id = s.plan_id
             WHERE s.status = ? AND s.expires_at IS NOT NULL
               AND s.expires_at > CURRENT_TIMESTAMP
               AND s.expires_at <= DATE_ADD(CURRENT_TIMESTAMP, INTERVAL ? DAY)
               AND (s.transaction_ref IS NULL OR s.transaction_ref NOT LIKE ?)' . $scope,
            $params
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
     * The site's own dunning ladder for a failed payment.
     *
     * The gateway decides when it retries the charge. The site owns what the
     * member is TOLD, and what happens when the grace window closes: the paid
     * membership ends and the account drops to the FREE tier. The owner's
     * rule (2026-11-13) is explicit - a failed payment must never leave the
     * member on a cheaper PAID level for free, so there is no "downgrade to
     * Silver" step anywhere in this code.
     *
     * Two notices, each keyed once per subscription by subscription_email_log
     * (so the 15-minute housekeeping cadence cannot repeat one):
     *   payment_still_failing  three days after the failure, while grace remains
     *   access_ending          when the grace window is inside two days
     *
     * $onlyUserId narrows the scan to one member so tests can run the real
     * queries without touching live inboxes (0 = all).
     *
     * @return array{still_failing: int, access_ending: int}
     */
    public static function runPaymentDunning(int $onlyUserId = 0): array
    {
        $grace = \App\Models\Subscription::pastDueGraceDays();
        $out   = ['still_failing' => 0, 'access_ending' => 0];

        if ($grace <= 0) {
            // No grace window exists, so there is nothing to warn about: the
            // past-due row is ended by the housekeeping immediately.
            return $out;
        }

        $scope = $onlyUserId > 0 ? ' AND s.user_id = ?' : '';

        if ($grace > 3) {
            $params = array_merge(['past_due', $grace], $onlyUserId > 0 ? [$onlyUserId] : []);
            $rows = Database::run(
                'SELECT s.id
                 FROM subscriptions s
                 WHERE s.status = ?
                   AND s.past_due_at IS NOT NULL
                   AND s.past_due_at <= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 3 DAY)
                   AND DATE_ADD(s.past_due_at, INTERVAL ? DAY) > CURRENT_TIMESTAMP' . $scope,
                $params
            )->fetchAll();

            foreach ($rows as $row) {
                $sub = self::subscription((int) $row['id']);
                if ($sub === null) {
                    continue;
                }
                $graceEnds = \App\Models\Subscription::pastDueGraceEndsAt($sub);
                if (self::send('payment_still_failing', (int) $row['id'], 'payment_still_failing', "We still can't charge your card", [
                    'planName'      => (string) ($sub['plan_name'] ?? 'your membership'),
                    'amount'        => (float) ((float) ($sub['price_paid'] ?? 0) > 0 ? $sub['price_paid'] : $sub['price']),
                    'graceEndsOn'   => $graceEnds !== null ? \tzdate('F j, Y', $graceEnds) : '',
                    'manageUrl'     => absolute_url('/membership/my'),
                ])) {
                    $out['still_failing']++;
                }
            }
        }

        $params = array_merge(['past_due', $grace, $grace], $onlyUserId > 0 ? [$onlyUserId] : []);
        $rows = Database::run(
            'SELECT s.id
             FROM subscriptions s
             WHERE s.status = ?
               AND s.past_due_at IS NOT NULL
               AND DATE_ADD(s.past_due_at, INTERVAL ? DAY) > CURRENT_TIMESTAMP
               AND DATE_ADD(s.past_due_at, INTERVAL ? DAY) <= DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 2 DAY)' . $scope,
            $params
        )->fetchAll();

        foreach ($rows as $row) {
            $sub = self::subscription((int) $row['id']);
            if ($sub === null) {
                continue;
            }
            $graceEnds = \App\Models\Subscription::pastDueGraceEndsAt($sub);
            if (self::send('access_ending', (int) $row['id'], 'access_ending', 'Your paid access ends soon', [
                'planName'    => (string) ($sub['plan_name'] ?? 'your membership'),
                'endsOn'      => $graceEnds !== null ? \tzdate('F j, Y', $graceEnds) : '',
                'manageUrl'   => absolute_url('/membership/my'),
                'membershipUrl' => \App\Models\Traffic::buildUrl('/membership', 'email-digest'),
            ])) {
                $out['access_ending']++;
            }
        }

        return $out;
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
     * Scan for free trials ending within $daysBefore and send one "your trial
     * ends soon" nudge per trial (conversion, so it honours the marketing
     * opt-out). $onlyUserId narrows the scan to one member for tests
     * (0 = all). Returns the number of emails queued.
     */
    public static function runTrialEndings(int $daysBefore = 3, int $onlyUserId = 0): int
    {
        $scope  = $onlyUserId > 0 ? ' AND s.user_id = ?' : '';
        $params = [$daysBefore];
        if ($onlyUserId > 0) {
            $params[] = $onlyUserId;
        }

        $rows = Database::run(
            "SELECT s.id
             FROM subscriptions s
             JOIN users u ON u.id = s.user_id
             WHERE s.status = 'active'
               AND s.transaction_ref LIKE 'TRIAL-%'
               AND s.expires_at IS NOT NULL
               AND s.expires_at > CURRENT_TIMESTAMP
               AND s.expires_at <= DATE_ADD(CURRENT_TIMESTAMP, INTERVAL ? DAY)
               AND COALESCE(u.marketing_opt_out, 0) = 0" . $scope,
            $params
        )->fetchAll();

        $count = 0;
        foreach ($rows as $row) {
            $sub = self::subscription((int) $row['id']);
            if ($sub === null) {
                continue;
            }
            if (self::send('trial_ending', (int) $row['id'], 'trial_ending', 'Your free trial ends soon', [
                'planName'      => (string) ($sub['plan_name'] ?? 'your membership'),
                'endsOn'        => \tzdate('F j, Y', (string) ($sub['expires_at'] ?? '')),
                'galleryUrl'    => \App\Models\Traffic::buildUrl('/galleries', 'email-digest'),
                'membershipUrl' => \App\Models\Traffic::buildUrl('/membership', 'email-digest'),
            ])) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Scan for activated members with no usable subscription who have not been
     * seen for $daysInactive and queue a "come see what's new" win-back to
     * those who have not opted out. Keyed once-ever per member in
     * retention_email_log. $onlyUserId narrows the scan to one member for
     * tests (0 = all). Returns the number of emails queued.
     */
    public static function runInactiveWinbacks(int $daysInactive = 30, int $onlyUserId = 0): int
    {
        $scope  = $onlyUserId > 0 ? ' AND u.id = ?' : '';
        $params = ['user', 'active', $daysInactive];
        if ($onlyUserId > 0) {
            $params[] = $onlyUserId;
        }

        $rows = Database::run(
            'SELECT u.id
             FROM users u
             WHERE u.role = ?
               AND u.status = ?
               AND u.email_verified_at IS NOT NULL
               AND COALESCE(u.marketing_opt_out, 0) = 0
               AND u.last_seen_at IS NOT NULL
               AND u.last_seen_at <= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL ? DAY)
               AND NOT EXISTS (
                   SELECT 1 FROM subscriptions x
                   WHERE x.user_id = u.id AND ' . \App\Models\Subscription::activeWhere('x') . '
               )' . $scope . '
             ORDER BY u.last_seen_at ASC
             LIMIT 50',
            $params
        )->fetchAll();

        $count = 0;
        foreach ($rows as $row) {
            if (self::sendToUser('winback_inactive', (int) $row['id'], 'winback_inactive', "It's been a while", [
                'galleryUrl'    => \App\Models\Traffic::buildUrl('/galleries', 'email-digest'),
                'membershipUrl' => \App\Models\Traffic::buildUrl('/membership', 'email-digest'),
            ])) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Queue one user-keyed retention email, exactly once per member (the
     * member may have no subscription to key on). Honours the marketing
     * opt-out.
     */
    public static function sendToUser(string $eventKey, int $userId, string $template, string $subject, array $data, ?string $scheduledAt = null): bool
    {
        if (EmailQueue::userEventAlreadySent($userId, $eventKey)) {
            return false;
        }

        $user = self::user($userId);
        if ($user === null || (int) ($user['marketing_opt_out'] ?? 0) === 1) {
            return false;
        }

        $html = render_email($template, $data);
        $text = render_email($template . '.text', $data);

        $queueId = EmailQueue::enqueueTx($userId, $subject, $html, $text, $scheduledAt);

        if ($queueId === null) {
            return false;
        }

        EmailQueue::userEventRecord($userId, $eventKey, $queueId);

        return true;
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

    /**
     * A user row with the fields retention sending needs, or null.
     */
    private static function user(int $userId): ?array
    {
        return Database::run(
            'SELECT id, email, marketing_opt_out FROM users WHERE id = ? LIMIT 1',
            [$userId]
        )->fetch() ?: null;
    }
}