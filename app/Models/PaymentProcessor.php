<?php

namespace App\Models;

use App\Core\Database;

/**
 * Data access for configured payment gateways (Stripe, PayPal, Coinbase, etc.).
 * Each row stores the gateway's provider, display name, API credentials and
 * settings. Secret keys are stored only in masked form in the session-facing
 * views; the full values live in the database. Enabling at least one processor
 * makes the pricing/subscribe flow show a payment method.
 */
class PaymentProcessor
{
    /** Provider identifiers the UI recognises; unknown providers are allowed. */
    public const PROVIDERS = ['stripe', 'paypal', 'coinbase', 'square', 'venmo', 'cashapp', 'bitcoin'];

    /**
     * Every configured processor, newest first, with the number of
     * subscriptions that used it (for the admin list).
     */
    public static function all(): array
    {
        return Database::run(
            'SELECT p.*,
                    (SELECT COUNT(*) FROM subscriptions s WHERE s.payment_processor_id = p.id) AS usage_count
             FROM payment_processors p
             ORDER BY p.is_default DESC, p.enabled DESC, p.id ASC'
        )->fetchAll();
    }

    /**
     * Processors that are enabled (usable at checkout), with the default first.
     */
    public static function enabled(): array
    {
        return Database::run(
            'SELECT * FROM payment_processors
             WHERE enabled = 1
             ORDER BY is_default DESC, id ASC'
        )->fetchAll();
    }

    /**
     * The first enabled processor, used as the checkout default when the user
     * does not make an explicit choice. Returns null when none are configured.
     */
    public static function default(): ?array
    {
        $row = Database::run(
            'SELECT * FROM payment_processors
             WHERE enabled = 1
             ORDER BY is_default DESC, id ASC
             LIMIT 1'
        )->fetch();

        return $row ?: null;
    }

    /**
     * Fetch a single processor by id, or null when it does not exist.
     */
    public static function find(int $id): ?array
    {
        $row = Database::run(
            'SELECT * FROM payment_processors WHERE id = ? LIMIT 1',
            [$id]
        )->fetch();

        return $row ?: null;
    }

    /**
     * Create a processor and return its id. Only one processor may be the
     * default at a time, so setting one clears the others.
     */
    public static function create(string $provider, string $name, string $mode, ?string $apiKey, ?string $secretKey, ?string $webhookSecret, string $currency, bool $isDefault, bool $enabled): int
    {
        if ($isDefault) {
            Database::run('UPDATE payment_processors SET is_default = 0');
        }

        Database::run(
            'INSERT INTO payment_processors (provider, name, mode, api_key, secret_key, webhook_secret, currency, is_default, enabled, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)',
            [$provider, $name, $mode, $apiKey, $secretKey, $webhookSecret, strtoupper($currency), $isDefault ? 1 : 0, $enabled ? 1 : 0]
        );

        return (int) Database::connection()->lastInsertId();
    }

    /**
     * Update a processor. Blank credential fields are left unchanged (so a
     * masked secret that is not re-entered does not wipe the saved value).
     * Only one processor may be the default.
     */
    public static function update(int $id, string $provider, string $name, string $mode, ?string $apiKey, ?string $secretKey, ?string $webhookSecret, string $currency, bool $isDefault, bool $enabled): void
    {
        if ($isDefault) {
            Database::run('UPDATE payment_processors SET is_default = 0 WHERE id <> ?', [$id]);
        }

        $fields = [];
        $params = [];

        foreach (['provider' => $provider, 'name' => $name, 'mode' => $mode] as $col => $val) {
            $fields[] = "$col = ?";
            $params[] = $val;
        }
        foreach (['api_key' => $apiKey, 'secret_key' => $secretKey, 'webhook_secret' => $webhookSecret] as $col => $val) {
            if ($val !== null && $val !== '') {
                $fields[] = "$col = ?";
                $params[] = $val;
            }
        }
        $fields[] = 'currency = ?';
        $params[] = strtoupper($currency);
        $fields[] = 'is_default = ?';
        $params[] = $isDefault ? 1 : 0;
        $fields[] = 'enabled = ?';
        $params[] = $enabled ? 1 : 0;

        $params[] = $id;
        Database::run(
            'UPDATE payment_processors SET ' . implode(', ', $fields) . ', updated_at = CURRENT_TIMESTAMP WHERE id = ?',
            $params
        );
    }

    /**
     * Toggle a processor's enabled flag (1 → 0 or 0 → 1).
     */
    public static function toggleEnabled(int $id): void
    {
        Database::run('UPDATE payment_processors SET enabled = NOT enabled, updated_at = CURRENT_TIMESTAMP WHERE id = ?', [$id]);
    }

    /**
     * Delete a processor. Subscriptions that referenced it keep their records
     * (the FK sets payment_processor_id to NULL).
     */
    public static function delete(int $id): void
    {
        Database::run('DELETE FROM payment_processors WHERE id = ?', [$id]);
    }

    /**
     * Human-readable label for a provider id (falls back to the raw id).
     */
    public static function providerLabel(string $provider): string
    {
        $labels = [
            'stripe'  => 'Stripe',
            'paypal'  => 'PayPal',
            'coinbase'=> 'Coinbase',
            'square'  => 'Square',
            'venmo'   => 'Venmo',
            'cashapp' => 'Cash App',
            'bitcoin' => 'Bitcoin',
        ];

        return $labels[$provider] ?? ucfirst($provider);
    }

    /**
     * Human-readable label for a processor mode.
     */
    public static function modeLabel(string $mode): string
    {
        return strtolower($mode) === 'live' ? 'Live' : 'Test';
    }

    /**
     * Mask a secret key for display, keeping the first 6 and last 4 characters
     * and replacing the middle with asterisks. Returns the input unchanged
     * when it is empty or too short.
     */
    public static function maskSecret(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (strlen($value) <= 10) {
            return str_repeat('*', strlen($value));
        }

        return substr($value, 0, 6) . str_repeat('*', 8) . substr($value, -4);
    }
}
