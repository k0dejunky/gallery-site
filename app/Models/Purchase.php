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
        ?string $currency = null
    ): int {
        Database::run(
            'INSERT INTO purchases (user_id, item_type, item_id, amount, currency, gateway, gateway_ref, payment_processor_id, note, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [$userId, $itemType, $itemId, $amount, $currency ?? 'USD', $gateway, $gatewayRef, $paymentProcessorId, $note, $status]
        );

        return (int) Database::connection()->lastInsertId();
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
        Database::run("UPDATE purchases SET status = 'paid', updated_at = NOW() WHERE id = ?", [$id]);

        return $id;
    }

    /**
     * Settle a purchase we already identified by id (synchronous card charge).
     */
    public static function settleById(int $id): void
    {
        Database::run("UPDATE purchases SET status = 'paid', updated_at = NOW() WHERE id = ? AND status = 'pending'", [$id]);
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
            "SELECT item_type, COALESCE(SUM(amount), 0) AS total FROM purchases
             WHERE status IN ('paid', 'granted') AND created_at >= ? AND created_at < ?
             GROUP BY item_type",
            [$from, $to]
        )->fetchAll();

        $result = ['gallery' => 0.0, 'tip' => 0.0, 'total' => 0.0];
        foreach ($rows as $row) {
            $v = (float) ($row['total'] ?? 0);
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

public static function setStatus(int $id, string $status): void
    {
        Database::run('UPDATE purchases SET status = ? WHERE id = ?', [$id, $status]);
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
             WHERE item_type = ? AND status IN (\'paid\', \'granted\')',
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