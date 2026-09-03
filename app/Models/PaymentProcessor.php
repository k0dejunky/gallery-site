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
    public const PROVIDERS = ['stripe', 'paypal', 'braintree', 'ccbill', 'epoch', 'segpay', 'coinbase', 'square', 'venmo', 'cashapp', 'bitcoin'];

    /**
     * Providers whose checkout happens on the biller's own hosted page. The
     * site builds a signed signup URL, the biller collects payment, and its
     * server confirms the purchase via our /webhooks/{provider} postback.
     */
    public const HOSTED_PROVIDERS = ['ccbill', 'epoch', 'segpay'];

    /**
     * Per-provider credential fields stored in config_json. Labels are shown
     * on the Payments page form; every field is optional until the processor
     * is actually used for live checkout.
     */
    public const CONFIG_FIELDS = [
        'ccbill' => [
            'client_accnum' => 'Client account number',
            'client_subacc' => 'Client subaccount',
            'form_name'     => 'FlexForm name',
            'dynamic_salt'  => 'Dynamic pricing salt',
            'currency_code' => 'CCBill currency code (840=USD)',
        ],
        'epoch' => [
            'co'     => 'Epoch company ID (co)',
            'pi'     => 'Product ID (pi)',
            'secret' => 'Postback shared secret',
        ],
        'segpay' => [
            'auth_key' => 'Auth key (x-authkey)',
            'api_user' => 'Merchant API username',
            'api_pass' => 'Merchant API hash secret',
        ],
        'braintree' => [
            'merchant_id' => 'Merchant ID',
            'public_key'  => 'Public key',
            'private_key' => 'Private key',
            'plan_id'     => 'Braintree plan ID (for subscriptions)',
        ],
        'paypal' => [
            'client_id'     => 'REST API client ID',
            'client_secret' => 'REST API secret',
            'webhook_id'    => 'Webhook ID',
        ],
    ];

    /** ISO currency -> CCBill numeric currency code (CCBill docs). */
    public const CCBILL_CURRENCIES = [
        'USD' => '840', 'EUR' => '978', 'GBP' => '826', 'CAD' => '124', 'AUD' => '036', 'JPY' => '392',
    ];

    /**
     * Credential field definitions for a provider ([key => label]).
     */
    public static function configFields(string $provider): array
    {
        return self::CONFIG_FIELDS[strtolower($provider)] ?? [];
    }

    /**
     * Decode a processor row's config_json into an associative array.
     */
    public static function decodeConfig(array $processor): array
    {
        $json = $processor['config_json'] ?? null;

        if (!is_string($json) || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Merge posted provider-specific fields into a config JSON string for
     * storage. Blank values keep whatever was saved before so editing one
     * credential never wipes the others.
     */
    public static function buildConfig(string $provider, array $post, ?array $existingProcessor): ?string
    {
        $fields = self::configFields($provider);

        if ($fields === []) {
            return null;
        }

        $current = $existingProcessor !== null ? self::decodeConfig($existingProcessor) : [];
        $merged  = [];

        foreach ($fields as $key => $label) {
            $value = trim((string) ($post[$key] ?? ''));

            if ($value === '') {
                $merged[$key] = $current[$key] ?? '';
                continue;
            }

            // Never overwrite a stored secret with the masked placeholder.
            if (strpos($value, '**') !== false && ($current[$key] ?? '') !== '') {
                $merged[$key] = $current[$key];
                continue;
            }

            $merged[$key] = $value;
        }

        return json_encode($merged, JSON_UNESCAPED_SLASHES);
    }

    /**
     * Build the biller's hosted checkout URL for a subscription attempt, or
     * null when the provider does not use hosted checkout or lacks the
     * credentials it needs.
     *
     * - CCBill FlexForms: signup.cgi with dynamic pricing digest
     *   MD5(price(2dp) . periodDays . currencyCode . salt), uppercase hex.
     * - Epoch: WNU Transactor link with company + product IDs.
     * - SegPay: POS billing page keyed by the price point auth key.
     *
     * Each URL carries our pending subscription reference so the biller's
     * postback can be reconciled back to this exact signup.
     */
    public static function checkoutUrl(array $processor, float $amount, int $periodDays, string $ref): ?string
    {
        $provider = strtolower((string) $processor['provider']);

        if (!in_array($provider, self::HOSTED_PROVIDERS, true)) {
            return null;
        }

        $cfg  = self::decodeConfig($processor);
        $mode = strtolower((string) $processor['mode']) === 'live' ? 'live' : 'test';

        if ($provider === 'ccbill') {
            if ((string) ($cfg['client_accnum'] ?? '') === '' || (string) ($cfg['form_name'] ?? '') === '') {
                return null;
            }

            $currencyCode = (string) ($cfg['currency_code'] ?? '');
            if ($currencyCode === '') {
                $currencyCode = self::CCBILL_CURRENCIES[strtoupper((string) $processor['currency'])] ?? '840';
            }

            // Test accounts use CCBill's sandbox host; live uses production.
            $host = $mode === 'live' ? 'https://bill.ccbill.com' : 'https://sandbox.bill.ccbill.com';

            $params = [
                'clientAccnum' => (string) $cfg['client_accnum'],
                'clientSubacc' => (string) ($cfg['client_subacc'] ?? '0000'),
                'formName'     => (string) $cfg['form_name'],
                'currencyCode' => $currencyCode,
            ];

            $salt = (string) ($cfg['dynamic_salt'] ?? '');

            if ($salt !== '') {
                $price              = sprintf('%.2f', $amount);
                $params['formPrice']  = $price;
                $params['formPeriod'] = (string) $periodDays;
                $params['formDigest'] = strtoupper(md5($price . $periodDays . $currencyCode . $salt));
            }

            $params['X-ref'] = $ref;

            return $host . '/jpost/signup.cgi?' . http_build_query($params);
        }

        if ($provider === 'epoch') {
            if ((string) ($cfg['co'] ?? '') === '' || (string) ($cfg['pi'] ?? '') === '') {
                return null;
            }

            $params = [
                'co'  => (string) $cfg['co'],
                'pi'  => (string) $cfg['pi'],
                'ref' => $ref,
            ];

            return 'https://wnu.com/secure/services/?' . http_build_query($params);
        }

        // segpay
        if ((string) ($cfg['auth_key'] ?? '') === '') {
            return null;
        }

        $params = ['x-authkey' => (string) $cfg['auth_key'], 'x-ref' => $ref];

        return 'https://secure.segpay.com/billing/pos-billing.php?' . http_build_query($params);
    }

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
    public static function create(string $provider, string $name, string $mode, ?string $apiKey, ?string $secretKey, ?string $webhookSecret, string $currency, bool $isDefault, bool $enabled, ?string $configJson = null): int
    {
        if ($isDefault) {
            Database::run('UPDATE payment_processors SET is_default = 0');
        }

        Database::run(
            'INSERT INTO payment_processors (provider, name, mode, api_key, secret_key, webhook_secret, config_json, currency, is_default, enabled, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)',
            [$provider, $name, $mode, $apiKey, $secretKey, $webhookSecret, $configJson, strtoupper($currency), $isDefault ? 1 : 0, $enabled ? 1 : 0]
        );

        return (int) Database::connection()->lastInsertId();
    }

    /**
     * Update a processor. Blank credential fields are left unchanged (so a
     * masked secret that is not re-entered does not wipe the saved value).
     * Only one processor may be the default.
     */
    public static function update(int $id, string $provider, string $name, string $mode, ?string $apiKey, ?string $secretKey, ?string $webhookSecret, string $currency, bool $isDefault, bool $enabled, ?string $configJson = null): void
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

        // Provider-specific credentials live in config_json; only overwrite
        // when the provider supplies fields so other JSON stays intact.
        if ($configJson !== null) {
            $fields[] = 'config_json = ?';
            $params[] = $configJson;
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
            'braintree' => 'Braintree',
            'ccbill'  => 'CCBill',
            'epoch'   => 'Epoch',
            'segpay'  => 'SegPay',
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
