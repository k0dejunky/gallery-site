<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * A receipt is a VIEW, not a table.
 *
 * The site has exactly two things it charged money for: a membership
 * (subscriptions) and a one-off purchase (purchases). Both already carry the
 * amount, the currency, the gateway and the date, so a second table would
 * duplicate the ledger and drift from it. What was missing was a stable
 * number and a member-facing shape.
 *
 * The number is derived from the immutable row id, so it never changes:
 *
 *   INV-P000123   a one-off purchase (purchases.id = 123)
 *   INV-M000045   a membership (subscriptions.id = 45)
 *
 * The prefix keeps the two sequences apart: a purchase and a subscription can
 * share an id, and an invoice number must be unique. It is also what makes a
 * receipt addressable in a URL without leaking anything: the member's own id
 * is checked on read.
 */
final class Receipt
{
    public const KIND_PURCHASE     = 'purchase';
    public const KIND_SUBSCRIPTION = 'membership';

    /** The invoice number for a one-off purchase. */
    public static function purchaseNumber(int $id): string
    {
        return 'INV-P' . str_pad((string) max(0, $id), 6, '0', STR_PAD_LEFT);
    }

    /** The invoice number for a membership. */
    public static function subscriptionNumber(int $id): string
    {
        return 'INV-M' . str_pad((string) max(0, $id), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Everything this member has been charged, newest first.
     *
     * A membership contributes ONE line: its initial price, plus how many
     * periods the site has granted. The site does not keep a row per monthly
     * charge (subscriptions.periods_granted is the counter), so inventing
     * per-period dates would be a lie. The line says so instead.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function forUser(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $out = [];

        $subs = Database::run(
            'SELECT s.*, p.name AS plan_name, p.billing_cycle
             FROM subscriptions s
             JOIN plans p ON p.id = s.plan_id
             WHERE s.user_id = ?
             ORDER BY s.created_at DESC, s.id DESC',
            [$userId]
        )->fetchAll();

        foreach ($subs as $sub) {
            $out[] = self::fromSubscription($sub);
        }

        $purchases = Database::run(
            'SELECT p.*, g.title AS gallery_title, pl.name AS plan_name
             FROM purchases p
             LEFT JOIN galleries g ON g.id = p.item_id AND p.item_type = \'gallery\'
             LEFT JOIN plans pl ON pl.id = p.item_id AND p.item_type = \'gift\'
             WHERE p.user_id = ?
             ORDER BY p.created_at DESC, p.id DESC',
            [$userId]
        )->fetchAll();

        foreach ($purchases as $purchase) {
            $out[] = self::fromPurchase($purchase);
        }

        // Newest first. The date is normalised to a timestamp so a purchase
        // and a membership sort against each other, not against their own
        // string format.
        usort($out, static function (array $a, array $b): int {
            return ($b['_ts'] ?? 0) <=> ($a['_ts'] ?? 0);
        });

        return $out;
    }

    /**
     * One receipt, but only if it belongs to this member.
     *
     * @return array<string, mixed>|null
     */
    public static function findForUser(int $userId, string $kind, int $id): ?array
    {
        if ($userId <= 0 || $id <= 0) {
            return null;
        }

        if ($kind === self::KIND_PURCHASE) {
            $row = Database::run(
                'SELECT p.*, g.title AS gallery_title
                 FROM purchases p
                 LEFT JOIN galleries g ON g.id = p.item_id AND p.item_type = \'gallery\'
                 WHERE p.id = ? AND p.user_id = ? LIMIT 1',
                [$id, $userId]
            )->fetch();

            return $row === false ? null : self::fromPurchase($row);
        }

        if ($kind === self::KIND_SUBSCRIPTION) {
            $row = Database::run(
                'SELECT s.*, p.name AS plan_name, p.billing_cycle
                 FROM subscriptions s
                 JOIN plans p ON p.id = s.plan_id
                 WHERE s.id = ? AND s.user_id = ? LIMIT 1',
                [$id, $userId]
            )->fetch();

            return $row === false ? null : self::fromSubscription($row);
        }

        return null;
    }

    /**
     * What a member has actually paid, excluding refunded rows. Used by the
     * receipts page summary; a pending or refunded charge is not money the
     * site kept.
     */
    public static function totalPaid(int $userId): float
    {
        if ($userId <= 0) {
            return 0.0;
        }

        $subs = (float) Database::run(
            "SELECT COALESCE(SUM(price_paid), 0) FROM subscriptions
             WHERE user_id = ? AND price_paid IS NOT NULL",
            [$userId]
        )->fetchColumn();

        $purchases = (float) Database::run(
            "SELECT COALESCE(SUM(amount), 0) FROM purchases
             WHERE user_id = ? AND status IN ('paid', 'granted')",
            [$userId]
        )->fetchColumn();

        return round($subs + $purchases, 2);
    }

    /**
     * @param array<string, mixed> $sub
     * @return array<string, mixed>
     */
    private static function fromSubscription(array $sub): array
    {
        $id     = (int) ($sub['id'] ?? 0);
        $status = (string) ($sub['status'] ?? '');
        $expiry = $sub['expires_at'] ?? null;

        // An "active" row whose date has passed reads as expired: the cron
        // that flips the status runs every fifteen minutes and a member must
        // not be told they are active in between.
        if ($status === 'active' && $expiry !== null && $expiry !== '' && strtotime((string) $expiry) <= time()) {
            $status = 'expired';
        }

        $periods = max(1, (int) ($sub['periods_granted'] ?? 0));
        $cycle   = (string) ($sub['billing_cycle'] ?? '');
        $periodLabel = $cycle === 'monthly' ? 'month' : ($cycle === 'yearly' ? 'year' : 'term');

        $description = (string) ($sub['plan_name'] ?? 'Membership');
        if ($periods > 1) {
            // Inside if ($periods > 1) this is always plural; the ternary was dead.
            $description .= ' — ' . $periods . ' ' . $periodLabel . 's paid';
        }

        $date = (string) ($sub['starts_at'] ?? $sub['created_at'] ?? '');

        return [
            'kind'            => self::KIND_SUBSCRIPTION,
            'id'              => $id,
            'receipt_no'      => self::subscriptionNumber($id),
            'description'     => $description,
            'detail'          => 'Membership' . ($cycle !== '' ? ' (' . $cycle . ')' : ''),
            'amount'          => (float) ($sub['price_paid'] ?? 0),
            'currency'        => 'USD',
            'gateway'         => self::gatewayLabel((string) ($sub['transaction_ref'] ?? '')),
            'status'          => $status,
            'status_label'    => Subscription::statusLabel($status),
            'paid_at'         => $date,
            'periods_granted' => (int) ($sub['periods_granted'] ?? 0),
            'expires_at'      => $expiry,
            'refundable'      => (float) ($sub['price_paid'] ?? 0) > 0
                && $status !== 'refunded',
            // A membership stores the SUBSCRIPTION id, not the id of each
            // charge, so the site cannot refund one term on its own.
            'refund_note'     => 'A membership refund is processed in the payment processor by the site owner.',
            '_ts'             => (int) (strtotime($date) ?: 0),
        ];
    }

    /**
     * @param array<string, mixed> $purchase
     * @return array<string, mixed>
     */
    private static function fromPurchase(array $purchase): array
    {
        $id     = (int) ($purchase['id'] ?? 0);
        $type   = (string) ($purchase['item_type'] ?? '');
        $status = (string) ($purchase['status'] ?? '');
        $title  = trim((string) ($purchase['gallery_title'] ?? ''));

        if ($type === Purchase::TYPE_TIP) {
            $description = 'Tip';
            $detail      = 'A one-off tip';
        } elseif ($type === Purchase::TYPE_GIFT) {
            $planName    = trim((string) ($purchase['plan_name'] ?? ''));
            $description = $planName !== '' ? 'Gift membership — ' . $planName : 'Gift membership';
            $detail      = 'A one-off gift membership';
        } else {
            $description = $title !== '' ? 'Gallery unlock — ' . $title : 'Gallery unlock';
            $detail      = 'A one-off gallery unlock';
        }

        $date = (string) ($purchase['created_at'] ?? '');

        return [
            'kind'            => self::KIND_PURCHASE,
            'id'              => $id,
            'receipt_no'      => self::purchaseNumber($id),
            'description'     => $description,
            'detail'          => $detail,
            'amount'          => (float) ($purchase['amount'] ?? 0),
            'currency'        => (string) ($purchase['currency'] ?? 'USD'),
            'gateway'         => self::gatewayLabel((string) ($purchase['gateway'] ?? '')),
            'status'          => $status,
            'status_label'    => self::purchaseStatusLabel($status),
            'paid_at'         => $date,
            'periods_granted' => 0,
            'expires_at'      => null,
            'refundable'      => in_array($status, [Purchase::STATUS_PAID, Purchase::STATUS_GRANTED], true)
                && (float) ($purchase['amount'] ?? 0) > 0,
            'refund_note'     => 'A one-off charge is refunded to the card that paid for it.',
            '_ts'             => (int) (strtotime($date) ?: 0),
        ];
    }

    /**
     * A readable name for the payment path. A member does not know what
     * "BT-" means, so the receipt says where the money went instead.
     */
    private static function gatewayLabel(string $ref): string
    {
        if (strpos($ref, 'BT-') === 0) {
            return 'Card';
        }
        if (strpos($ref, 'PAYPAL-') === 0 || strpos($ref, 'I-') === 0) {
            return 'PayPal';
        }
        if (strpos($ref, 'TRIAL-') === 0) {
            return 'Free trial';
        }
        if (strpos($ref, 'PENDING-') === 0) {
            return 'Pending';
        }
        if ($ref === '') {
            return 'No payment';
        }

        return 'Other';
    }

    private static function purchaseStatusLabel(string $status): string
    {
        switch ($status) {
            case Purchase::STATUS_PENDING:
                return 'Pending';
            case Purchase::STATUS_PAID:
                return 'Paid';
            case Purchase::STATUS_GRANTED:
                return 'Paid';
            case Purchase::STATUS_REFUNDED:
                return 'Refunded';
            case Purchase::STATUS_DISPUTED:
                return 'Disputed';
            default:
                return ucfirst($status);
        }
    }
}
