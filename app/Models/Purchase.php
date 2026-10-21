<?php

namespace App\Models;

use App\Core\Database;

/**
 * One-off ledger: PPV unlocks (from unlock codes) and tips. Membership revenue
 * stays in the subscriptions/sales tables; this table is the "everything else"
 * ledger that the admin Engagement page reconciles manually.
 */
class Purchase
{
    public const TYPE_GALLERY = 'gallery';
    public const TYPE_TIP     = 'tip';

    public const STATUS_PENDING  = 'pending';
    public const STATUS_PAID     = 'paid';
    public const STATUS_GRANTED  = 'granted';
    public const STATUS_REFUNDED = 'refunded';

    public static function create(
        int $userId,
        string $itemType,
        int $itemId,
        float $amount,
        string $gateway = 'offline',
        ?string $gatewayRef = null,
        ?string $note = null,
        string $status = self::STATUS_PAID,
        ?int $paymentProcessorId = null,
        ?string $currency = null,
        ?string $idempotencyKey = null
    ): int {
        Database::run(
            'INSERT INTO purchases (user_id, item_type, item_id, amount, currency, gateway, gateway_ref, payment_processor_id, idempotency_key, note, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [$userId, $itemType, $itemId, $amount, $currency ?? 'USD', $gateway, $gatewayRef, $paymentProcessorId, $idempotencyKey, $note, $status]
        );

        $id = (int) Database::connection()->lastInsertId();

        // Every purchase starts in the ledger.
        self::recordEvent($id, 'created', [
            'amount'   => $amount,
            'currency' => $currency ?? 'USD',
            'gateway'  => $gateway,
            'reason'   => $note,
        ]);

        return $id;
    }

    /**
     * The purchase a key already made, or null.
     *
     * A one-off charge carries a key so a double-click cannot charge
     * twice. The key is bound to the purchase: the same key with a
     * different amount or a different item is a client bug or an attempt to
     * reuse a key, and the caller must refuse it rather than answer with
     * the wrong purchase.
     */
    public static function findByIdempotencyKey(string $key): ?array
    {
        if (trim($key) === '') {
            return null;
        }

        $row = Database::run(
            'SELECT * FROM purchases WHERE idempotency_key = ? LIMIT 1',
            [$key]
        )->fetch();

        return $row ?: null;
    }

    /**
     * Whether a purchase row is the one a key refers to.
     */
    public static function idempotencyMatches(array $row, string $itemType, int $itemId, float $amount): bool
    {
        return (string) ($row['item_type'] ?? '') === $itemType
            && (int) ($row['item_id'] ?? 0) === $itemId
            && abs((float) ($row['amount'] ?? 0) - $amount) < 0.005;
    }

    /**
     * Settle an in-flight card charge: flips a pending purchase to paid (or
     * granted) when the (gateway, gateway_ref) pair matches an existing
     * pending row. Idempotent — a duplicate settlement event is a no-op.
     * Returns the settled purchase id, or null when nothing matched.
     */
    public static function settleByReference(string $gateway, string $gatewayRef): ?int
    {
        $row = Database::run(
            "SELECT id FROM purchases
             WHERE gateway = ? AND gateway_ref = ? AND status = 'pending'
             ORDER BY id ASC LIMIT 1",
            [$gateway, $gatewayRef]
        )->fetch();

        if ($row === false) {
            return null;
        }

        $id = (int) $row['id'];
        // The same guard as settleById(): only a pending purchase is settled,
        // so two concurrent settlement events cannot both write.
        $stmt = Database::run(
            "UPDATE purchases SET status = 'paid', updated_at = NOW() WHERE id = ? AND status = 'pending'",
            [$id]
        );

        if ($stmt->rowCount() > 0) {
            self::recordEvent($id, 'paid', ['gateway_ref' => $gatewayRef]);
        }

        return $id;
    }

    /**
     * Append a row to the money ledger.
     *
     * A purchase row is MUTATED in place: pending -> paid -> refunded, and
     * an admin can set any status on any row. The row only ever showed
     * the LAST state, so "was this paid and then refunded, or never paid
     * at all" had no answer, and an admin's manual change was
     * indistinguishable from a gateway's.
     *
     * The ledger is the record. The purchase row is the current state, a
     * projection of it.
     */
    public static function recordEvent(int $purchaseId, string $event, array $data = []): int
    {
        $row = Database::run(
            'SELECT user_id, amount, currency, gateway, gateway_ref FROM purchases WHERE id = ? LIMIT 1',
            [$purchaseId]
        )->fetch();

        if ($row === false) {
            return 0;
        }

        Database::run(
            'INSERT INTO purchase_events
                (purchase_id, user_id, event, amount, currency, gateway, gateway_ref, actor_id, reason, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [
                $purchaseId,
                $row['user_id'] ?? null,
                $event,
                $data['amount'] ?? $row['amount'] ?? null,
                $data['currency'] ?? $row['currency'] ?? null,
                $data['gateway'] ?? $row['gateway'] ?? null,
                $data['gateway_ref'] ?? $row['gateway_ref'] ?? null,
                $data['actor_id'] ?? null,
                $data['reason'] ?? null,
            ]
        );

        return (int) Database::connection()->lastInsertId();
    }

    /**
     * The ledger for a purchase, oldest first.
     */
    public static function events(int $purchaseId): array
    {
        return Database::run(
            'SELECT * FROM purchase_events WHERE purchase_id = ? ORDER BY id ASC',
            [$purchaseId]
        )->fetchAll();
    }

    /**
     * The gateways where money actually moved.
     *
     * An OFFLINE tip is a member posting an amount and a note - nothing is
     * charged, and the amount is whatever the member typed (up to $10,000,
     * five times per ten minutes per IP). An unlock CODE is a comp. Both
     * used to be counted as revenue, so the earnings dashboard and its CSV
     * could be inflated by anyone who wanted to make the numbers look
     * better.
     *
     * They are still recorded and still shown, but separately.
     */
    public const REVENUE_GATEWAYS = ['braintree', 'paypal'];

    /**
     * Settle a purchase we already identified by id (synchronous card charge).
     */
    public static function settleById(int $id): void
    {
        $stmt = Database::run(
            "UPDATE purchases SET status = 'paid', updated_at = NOW() WHERE id = ? AND status = 'pending'",
            [$id]
        );

        if ($stmt->rowCount() > 0) {
            self::recordEvent($id, 'paid');
        }
    }

    /**
     * Refund a purchase AT ITS PAYMENT PROCESSOR.
     *
     * The admin's "refund" only flipped the local status, so the member
     * kept their card charge while the unlock was revoked. Both gateways
     * could already refund - BraintreeGateway::refund() and
     * PayPalGateway::refundCapture() - but neither was ever called.
     *
     * Returns:
     *   true  - the processor confirmed the refund
     *   false - the processor was asked and did not confirm
     *   null  - there was no money to refund (an unlock code or an offline
     *           tip: no money ever moved)
     */
    public static function refundAtGateway(int $id): ?bool
    {
        $row = Database::run(
            'SELECT * FROM purchases WHERE id = ? LIMIT 1',
            [$id]
        )->fetch();

        if ($row === false) {
            return null;
        }

        $gateway = (string) ($row['gateway'] ?? 'offline');
        $ref     = (string) ($row['gateway_ref'] ?? '');

        // An unlock code and an offline tip never moved money, so there is
        // nothing to refund at a processor.
        if ($gateway === 'code' || $gateway === 'offline' || $ref === '') {
            return null;
        }

        $processor = !empty($row['payment_processor_id'])
            ? \App\Models\PaymentProcessor::find((int) $row['payment_processor_id'])
            : null;

        try {
            if ($gateway === 'braintree') {
                $client = $processor !== null
                    ? \App\Core\BraintreeGateway::fromConfig($processor)
                    : null;
                if ($client === null) {
                    error_log('[purchase-refund] no Braintree gateway for purchase ' . $id);
                    return false;
                }
                return $client->refund($ref, (float) $row['amount']);
            }

            if ($gateway === 'paypal') {
                $client = $processor !== null
                    ? \App\Core\PayPalGateway::fromConfig($processor)
                    : null;
                if ($client === null) {
                    error_log('[purchase-refund] no PayPal gateway for purchase ' . $id);
                    return false;
                }
                return $client->refundCapture($ref, (float) $row['amount']);
            }
        } catch (\Throwable $e) {
            error_log('[purchase-refund] the refund failed for purchase ' . $id . ' (' . $gateway . '): ' . $e->getMessage());
            return false;
        }

        error_log('[purchase-refund] purchase ' . $id . ' has an unknown gateway "' . $gateway . '"');
        return false;
    }

    /**
     * Revoke an unlock after a refund/chargeback (paid/granted -> refunded).
     * Idempotent via the by-reference lookup.
     */
    public static function refundByReference(string $gateway, string $gatewayRef): bool
    {
        $stmt = Database::run(
            "UPDATE purchases SET status = 'refunded', updated_at = NOW()
             WHERE gateway = ? AND gateway_ref = ? AND status IN ('paid', 'granted')",
            [$gateway, $gatewayRef]
        );

        if ($stmt->rowCount() > 0) {
            $id = (int) Database::run(
                'SELECT id FROM purchases WHERE gateway = ? AND gateway_ref = ? LIMIT 1',
                [$gateway, $gatewayRef]
            )->fetchColumn();
            if ($id) {
                self::recordEvent((int) $id, 'refunded', ['gateway_ref' => $gatewayRef]);
            }
        }

        return $stmt->rowCount() > 0;
    }

    /**
     * One-off revenue in a date window, split per item type (gallery/tip),
     * for the earnings dashboard.
     *
     * @return array{gallery: float, tip: float, total: float}
     */
    public static function totalsBetween(string $from, string $to): array
    {
        $rows = Database::run(
            "SELECT item_type, gateway, COALESCE(SUM(amount), 0) AS total FROM purchases
             WHERE status IN ('paid', 'granted') AND created_at >= ? AND created_at < ?
             GROUP BY item_type, gateway",
            [$from, $to]
        )->fetchAll();

        $result = ['gallery' => 0.0, 'tip' => 0.0, 'total' => 0.0, 'offline' => 0.0];
        foreach ($rows as $row) {
            $v = (float) ($row['total'] ?? 0);
            $isRevenue = in_array((string) ($row['gateway'] ?? ''), self::REVENUE_GATEWAYS, true);
            if (!$isRevenue) {
                // An offline tip or a code redemption: not collected money.
                $result['offline'] += $v;
                continue;
            }
            $result[(string) ($row['item_type'] ?? '') === 'tip' ? 'tip' : 'gallery'] = $v;
            $result['total'] += $v;
        }

        return $result;
    }

    /**
     * A user "owns" a PPV gallery when they hold an active (non-refunded)
     * purchase row for it.
     */
    public static function userUnlocked(int $userId, int $galleryId): bool
    {
        $row = Database::run(
            'SELECT id FROM purchases
             WHERE user_id = ? AND item_type = \'gallery\' AND item_id = ? AND status IN (\'paid\', \'granted\')
             LIMIT 1',
            [$userId, $galleryId]
        )->fetch();

        return $row !== false;
    }

    /**
     * Which of these galleries the member has already unlocked.
     *
     * The wall asks for a dozen galleries at a time; one query for all of
     * them beats one per gallery.
     *
     * @return array<int, true> the unlocked gallery ids
     */
    public static function unlockedGalleryIds(int $userId, array $galleryIds): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $galleryIds),
            static fn (int $id): bool => $id > 0
        )));

        if ($userId <= 0 || $ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $rows = Database::run(
            "SELECT item_id FROM purchases
             WHERE user_id = ? AND item_type = 'gallery' AND item_id IN ($placeholders)
               AND status IN ('paid', 'granted')",
            array_merge([$userId], $ids)
        )->fetchAll();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['item_id']] = true;
        }

        return $out;
    }

    /** Index against an unlock code to keep used_count/notes verifiable. */
    public static function forCode(int $codeId): array
    {
        return Database::run(
            'SELECT p.*, u.email AS user_email
             FROM purchases p
             LEFT JOIN users u ON u.id = p.user_id
             WHERE p.gateway = \'code\' AND p.gateway_ref = ?
             ORDER BY p.created_at DESC',
            [(string) $codeId]
        )->fetchAll();
    }

    public static function recent(int $limit = 100): array
    {
        return Database::run(
            'SELECT p.*, u.email AS user_email, g.title AS gallery_title
             FROM purchases p
             JOIN users u ON u.id = p.user_id
             LEFT JOIN galleries g ON g.id = p.item_id AND p.item_type = \'gallery\'
             ORDER BY p.created_at DESC, p.id DESC
             LIMIT ' . max(1, (int) $limit)
        )->fetchAll();
    }

    /**
     * An admin's manual status change.
     *
     * It goes in the ledger like everything else, with the admin who made
     * it, so a manual change is never mistaken for a gateway's.
     */
    public static function setStatus(int $id, string $status, int $actorId = 0): void
    {
        $before = Database::run(
            'SELECT status FROM purchases WHERE id = ? LIMIT 1',
            [$id]
        )->fetchColumn();

        // The parameters were SWAPPED: "SET status = ? WHERE id = ?" was
        // given [$id, $status], so the id went into the status column and
        // the status into the id. The row matched nothing and the admin's
        // status change never happened.
        Database::run('UPDATE purchases SET status = ? WHERE id = ?', [$status, $id]);

        if ($before !== false && (string) $before !== $status) {
            self::recordEvent($id, 'status_changed', [
                'actor_id' => $actorId > 0 ? $actorId : null,
                'reason'    => 'from ' . $before . ' to ' . $status,
            ]);
        }
    }

    /**
     * True when the user has purchased a PPV unlock for any gallery holding
     * this photo — used to bypass the membership level gate at serve time.
     */
    public static function unlocksPhoto(int $userId, int $photoId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        return (bool) Database::run(
            'SELECT 1 FROM gallery_photo gp
             JOIN galleries g ON g.id = gp.gallery_id
             JOIN purchases p
                     ON p.item_type = \'gallery\'
                    AND p.item_id = g.id
                    AND p.user_id = ?
                    AND p.status IN (\'paid\', \'granted\')
             WHERE gp.photo_id = ? AND g.ppv_price IS NOT NULL AND g.deleted_at IS NULL
             LIMIT 1',
            [$userId, $photoId]
        )->fetchColumn();
    }

    public static function totalFor(string $itemType): float
    {
        $row = Database::run(
            'SELECT COALESCE(SUM(amount), 0) AS total FROM purchases
             WHERE item_type = ? AND status IN (\'paid\', \'granted\')
               AND gateway IN (\'braintree\', \'paypal\')',
            [$itemType]
        )->fetch();

        return (float) ($row['total'] ?? 0);
    }

    /**
     * Per-month one-off revenue (paid/granted) for the earnings dashboard.
     *
     * @return array<string,array{gallery: float, tip: float, total: float}>
     */
    public static function seriesBetween(string $from, string $to): array
    {
        $rows = Database::run(
            "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, item_type, COALESCE(SUM(amount), 0) AS total
             FROM purchases
             WHERE status IN ('paid', 'granted') AND created_at >= ? AND created_at < ?
               AND gateway IN ('braintree', 'paypal')
             GROUP BY ym, item_type ORDER BY ym ASC",
            [$from, $to]
        )->fetchAll();

        $series = [];
        foreach ($rows as $row) {
            $ym   = (string) $row['ym'];
            $v    = (float) ($row['total'] ?? 0);
            if (!isset($series[$ym])) {
                $series[$ym] = ['gallery' => 0.0, 'tip' => 0.0, 'total' => 0.0];
            }
            $key = (string) ($row['item_type'] ?? '') === 'tip' ? 'tip' : 'gallery';
            $series[$ym][$key] = $v;
            $series[$ym]['total'] += $v;
        }

        return $series;
    }
}