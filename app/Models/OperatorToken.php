<?php

namespace App\Models;

use App\Core\Database;

/**
 * Per-device operator tokens for the chat bridge. Only the SHA-256 hash is
 * stored, so a leaked database cannot expose usable tokens. Tokens are
 * revocable and optionally expiring; the raw token is returned to the caller
 * exactly once at creation.
 */
class OperatorToken
{
    /**
     * Create a new token and return the raw value (shown once). The stored
     * value is always the SHA-256 hash.
     */
    public static function create(string $label, int $createdBy, ?string $expiresAt = null, string $scopes = 'chat'): string
    {
        $raw = bin2hex(random_bytes(32));
        Database::run(
            'INSERT INTO operator_tokens (label, token_hash, scopes, expires_at, created_by)
             VALUES (?, ?, ?, ?, ?)',
            [$label, self::hashOf($raw), $scopes, $expiresAt ?: null, $createdBy]
        );

        return $raw;
    }

    /**
     * Authenticate a raw token: returns the token row when valid (not revoked
     * and not expired), updating last_used_at. Null otherwise.
     */
    public static function authenticate(string $raw): ?array
    {
        if ($raw === '') {
            return null;
        }

        $row = Database::run(
            'SELECT * FROM operator_tokens
             WHERE token_hash = ? AND revoked = 0
               AND (expires_at IS NULL OR expires_at > CURRENT_TIMESTAMP)
             LIMIT 1',
            [self::hashOf($raw)]
        )->fetch();

        if ($row === false || $row === null) {
            return null;
        }

        Database::run(
            'UPDATE operator_tokens SET last_used_at = CURRENT_TIMESTAMP WHERE id = ?',
            [(int) $row['id']]
        );

        return $row;
    }

    /** All tokens with the creating admin's email, newest first. */
    public static function all(): array
    {
        return Database::run(
            'SELECT t.*, u.email AS created_by_email
             FROM operator_tokens t
             LEFT JOIN users u ON u.id = t.created_by
             ORDER BY t.id DESC'
        )->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $row = Database::run(
            'SELECT * FROM operator_tokens WHERE id = ? LIMIT 1',
            [$id]
        )->fetch();

        return $row ?: null;
    }

    public static function revoke(int $id): bool
    {
        $stmt = Database::run(
            'UPDATE operator_tokens SET revoked = 1 WHERE id = ? AND revoked = 0',
            [$id]
        );

        return $stmt->rowCount() > 0;
    }

    public static function hashOf(string $raw): string
    {
        return hash('sha256', $raw);
    }
}