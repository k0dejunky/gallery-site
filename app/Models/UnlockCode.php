<?php

namespace App\Models;

use App\Core\Database;

/**
 * One-off unlock codes the creator sells off-site (no live gateway). Each code
 * unlocks one gallery (or any PPV gallery when gallery_id is NULL) and credits
 * the merchant with `amount`. Redemption writes a purchases row.
 */
class UnlockCode
{
    public static function generate(?int $galleryId, ?float $amount, ?int $maxUses, ?string $note): array
    {
        do {
            $code = 'UNLOCK-' . strtoupper(bin2hex(random_bytes(5)));
            $exists = Database::run('SELECT id FROM unlock_codes WHERE code = ? LIMIT 1', [$code])->fetch();
        } while ($exists !== false);

        $id = self::create($code, $galleryId, $amount, $maxUses, $note);

        return [
            'id'         => $id,
            'code'       => $code,
            'gallery_id' => $galleryId,
            'amount'     => $amount,
            'max_uses'   => $maxUses,
            'used_count' => 0,
            'active'     => 1,
            'note'       => $note,
        ];
    }

    public static function create(string $code, ?int $galleryId, ?float $amount, ?int $maxUses, ?string $note): int
    {
        Database::run(
            'INSERT INTO unlock_codes (code, gallery_id, amount, max_uses, note, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())',
            [strtoupper(trim($code)), $galleryId, $amount, $maxUses, $note]
        );

        return (int) Database::connection()->lastInsertId();
    }

    public static function findValid(string $code, ?int $galleryId = null): ?array
    {
        $row = Database::run(
            'SELECT * FROM unlock_codes
             WHERE code = ? AND active = 1 AND (max_uses IS NULL OR used_count < max_uses)
             LIMIT 1',
            [strtoupper(trim($code))]
        )->fetch();

        if ($row === false) {
            return null;
        }

        if ($galleryId !== null && $row['gallery_id'] !== null && (int) $row['gallery_id'] !== $galleryId) {
            return null;
        }

        return $row;
    }

    /**
     * Atomically consume one use. Returns true only when it was actually ours
     * (guards races between two simultaneous redemptions of the same code).
     */
    public static function redeem(int $id): bool
    {
        $changed = Database::run(
            'UPDATE unlock_codes
             SET used_count = used_count + 1,
                 active = CASE WHEN max_uses IS NOT NULL AND used_count + 1 >= max_uses THEN 0 ELSE active END
             WHERE id = ? AND active = 1 AND (max_uses IS NULL OR used_count < max_uses)',
            [$id]
        );

        return $changed->rowCount() === 1;
    }

    public static function all(): array
    {
        return Database::run(
            'SELECT c.*, g.title AS gallery_title, g.type AS gallery_type
             FROM unlock_codes c
             LEFT JOIN galleries g ON g.id = c.gallery_id
             ORDER BY c.created_at DESC, c.id DESC'
        )->fetchAll();
    }

    public static function toggle(int $id): void
    {
        Database::run('UPDATE unlock_codes SET active = 1 - active WHERE id = ?', [$id]);
    }

    public static function delete(int $id): void
    {
        Database::run('DELETE FROM unlock_codes WHERE id = ?', [$id]);
    }
}