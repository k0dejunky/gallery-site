<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * A member's request for their money back.
 *
 * The site could already refund a purchase from the admin ledger, but a
 * member had no way to ASK. One row per request, tied to exactly one charge:
 * a one-off purchase (which the site can refund by itself, because it holds
 * the processor's transaction id) or a membership (which it cannot, because
 * it stores the subscription id rather than the id of each monthly charge).
 *
 * Only an OPEN request can be decided, and a member can withdraw their own.
 */
final class RefundRequest
{
    public const STATUS_OPEN      = 'open';
    public const STATUS_APPROVED  = 'approved';
    public const STATUS_DENIED    = 'denied';
    public const STATUS_WITHDRAWN = 'withdrawn';

    public const MAX_REASON = 1000;

    /**
     * Record a request. Returns the new id, or throws with a message the
     * member can act on.
     *
     * The charge is re-read here rather than trusted from the form: a member
     * can only ask for a refund of something they were actually charged.
     */
    public static function create(int $userId, string $kind, int $chargeId, string $reason): int
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new \InvalidArgumentException('Please tell us why you are asking for a refund.');
        }
        if (mb_strlen($reason) > self::MAX_REASON) {
            $reason = mb_substr($reason, 0, self::MAX_REASON);
        }

        $purchaseId     = null;
        $subscriptionId = null;

        if ($kind === Receipt::KIND_PURCHASE) {
            $row = Database::run(
                'SELECT id, user_id, amount, status FROM purchases WHERE id = ? LIMIT 1',
                [$chargeId]
            )->fetch();
            if ($row === false || (int) $row['user_id'] !== $userId) {
                throw new \InvalidArgumentException('That charge was not found on your account.');
            }
            if (!in_array((string) $row['status'], [Purchase::STATUS_PAID, Purchase::STATUS_GRANTED], true)) {
                throw new \InvalidArgumentException('That charge is not in a refundable state.');
            }
            if ((float) $row['amount'] <= 0) {
                throw new \InvalidArgumentException('That charge has no amount to refund.');
            }
            $purchaseId = (int) $row['id'];
        } elseif ($kind === Receipt::KIND_SUBSCRIPTION) {
            $row = Database::run(
                'SELECT id, user_id, price_paid FROM subscriptions WHERE id = ? LIMIT 1',
                [$chargeId]
            )->fetch();
            if ($row === false || (int) $row['user_id'] !== $userId) {
                throw new \InvalidArgumentException('That membership was not found on your account.');
            }
            if ((float) ($row['price_paid'] ?? 0) <= 0) {
                throw new \InvalidArgumentException('That membership has no charge to refund.');
            }
            $subscriptionId = (int) $row['id'];
        } else {
            throw new \InvalidArgumentException('Unknown charge type.');
        }

        // One open request per charge. A member who was denied may ask again
        // with more detail; two open requests for the same charge are noise
        // for the admin and a way to lose track of the first one.
        $existing = Database::run(
            "SELECT id FROM refund_requests
             WHERE user_id = ? AND status = 'open'
               AND (purchase_id <=> ? OR subscription_id <=> ?)
             LIMIT 1",
            [$userId, $purchaseId, $subscriptionId]
        )->fetchColumn();
        if ($existing !== false) {
            throw new \InvalidArgumentException('You already have an open request for that charge.');
        }

        Database::run(
            'INSERT INTO refund_requests (user_id, purchase_id, subscription_id, reason, status, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())',
            [$userId, $purchaseId, $subscriptionId, $reason, self::STATUS_OPEN]
        );

        $id = (int) Database::connection()->lastInsertId();

        $amount = $kind === Receipt::KIND_PURCHASE
            ? (float) Database::run('SELECT amount FROM purchases WHERE id = ?', [$purchaseId])->fetchColumn()
            : (float) Database::run('SELECT price_paid FROM subscriptions WHERE id = ?', [$subscriptionId])->fetchColumn();

        AuditLog::record($userId, 'refund_request', 'refund_request', $id, sprintf(
            'Refund requested for %s #%d (%s %.2f)',
            $purchaseId !== null ? 'purchase' : 'membership',
            $purchaseId ?? (int) $subscriptionId,
            'USD',
            $amount
        ));

        return $id;
    }

    /** @return array<int, array<string, mixed>> */
    public static function forUser(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        return Database::run(
            'SELECT * FROM refund_requests WHERE user_id = ? ORDER BY created_at DESC, id DESC',
            [$userId]
        )->fetchAll();
    }

    /**
     * The open request for a charge, so the receipt row can say "requested"
     * instead of offering the button again.
     *
     * @param array<int, array<string, mixed>> $requests
     */
    public static function openFor(array $requests, string $kind, int $chargeId): ?array
    {
        foreach ($requests as $request) {
            if ((string) ($request['status'] ?? '') !== self::STATUS_OPEN) {
                continue;
            }
            $hit = $kind === Receipt::KIND_PURCHASE
                ? (int) ($request['purchase_id'] ?? 0) === $chargeId
                : (int) ($request['subscription_id'] ?? 0) === $chargeId;
            if ($hit) {
                return $request;
            }
        }

        return null;
    }

    /**
     * The admin queue: open requests first (oldest first - a member has been
     * waiting), then the decided ones newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function recent(int $limit = 100): array
    {
        $limit = max(1, $limit);

        return Database::run(
            "SELECT r.*, u.email AS user_email,
                    p.amount AS purchase_amount, p.currency AS purchase_currency,
                    p.item_type AS purchase_type, p.status AS purchase_status,
                    p.gateway AS purchase_gateway,
                    s.price_paid AS sub_amount, s.status AS sub_status,
                    pl.name AS plan_name
             FROM refund_requests r
             JOIN users u ON u.id = r.user_id
             LEFT JOIN purchases p ON p.id = r.purchase_id
             LEFT JOIN subscriptions s ON s.id = r.subscription_id
             LEFT JOIN plans pl ON pl.id = s.plan_id
             ORDER BY (r.status = 'open') DESC,
                      CASE WHEN r.status = 'open' THEN r.created_at END ASC,
                      r.created_at DESC, r.id DESC
             LIMIT " . $limit
        )->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public static function find(int $id): ?array
    {
        $row = Database::run('SELECT * FROM refund_requests WHERE id = ? LIMIT 1', [$id])->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Decide an open request.
     *
     * Returns 'approved', 'denied', 'missing', 'already' or
     * 'refund_failed'. On a PURCHASE approval the money is refunded FIRST and
     * the request is only marked approved when the processor confirms: a
     * request marked "approved" that never moved the money is the worst
     * possible answer.
     */
    public static function decide(int $id, string $status, string $note, int $actorId): string
    {
        if (!in_array($status, [self::STATUS_APPROVED, self::STATUS_DENIED], true)) {
            return 'missing';
        }

        $row = self::find($id);
        if ($row === null) {
            return 'missing';
        }
        if ((string) $row['status'] !== self::STATUS_OPEN) {
            return 'already';
        }

        $note = trim($note);
        if (mb_strlen($note) > self::MAX_REASON) {
            $note = mb_substr($note, 0, self::MAX_REASON);
        }

        $purchaseId = (int) ($row['purchase_id'] ?? 0);

        if ($status === self::STATUS_APPROVED && $purchaseId > 0) {
            $gateway = Purchase::refundAtGateway($purchaseId);
            if ($gateway === false) {
                return 'refund_failed';
            }
            // true = the processor confirmed it. null = the charge never
            // moved money at a processor (an unlock code, or an offline row)
            // - there is nothing to send back, but the ledger still has to
            // stop counting it as revenue and the unlock has to close.
            Purchase::setStatus($purchaseId, Purchase::STATUS_REFUNDED, $actorId);
        }

        Database::run(
            'UPDATE refund_requests
             SET status = ?, admin_note = ?, decided_by = ?, decided_at = NOW()
             WHERE id = ? AND status = ?',
            [$status, $note !== '' ? $note : null, $actorId > 0 ? $actorId : null, $id, self::STATUS_OPEN]
        );

        AuditLog::record($actorId > 0 ? $actorId : null, 'refund_' . $status, 'refund_request', $id, sprintf(
            'Refund request #%d %s (%s)%s',
            $id,
            $status,
            $purchaseId > 0 ? 'purchase #' . $purchaseId : 'membership #' . (int) ($row['subscription_id'] ?? 0),
            $note !== '' ? ': ' . $note : ''
        ));

        return $status;
    }

    /** A member withdrawing their own open request. */
    public static function withdraw(int $id, int $userId): bool
    {
        $stmt = Database::run(
            "UPDATE refund_requests SET status = ?, decided_at = NOW()
             WHERE id = ? AND user_id = ? AND status = ?",
            [self::STATUS_WITHDRAWN, $id, $userId, self::STATUS_OPEN]
        );

        return $stmt->rowCount() > 0;
    }

    public static function statusLabel(string $status): string
    {
        switch ($status) {
            case self::STATUS_OPEN:
                return 'Waiting for review';
            case self::STATUS_APPROVED:
                return 'Approved';
            case self::STATUS_DENIED:
                return 'Declined';
            case self::STATUS_WITHDRAWN:
                return 'Withdrawn';
            default:
                return ucfirst($status);
        }
    }
}
