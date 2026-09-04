<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Http;

/**
 * Minimal PayPal REST client — no SDK dependency. Used to obtain an access
 * token and to verify incoming webhook signatures. Credentials come from a
 * payment_processors row's config_json (client_id / client_secret / webhook_id)
 * via the gateway's fromConfig factory.
 *
 * Environment URLs:
 *   Sandbox: https://api-m.sandbox.paypal.com
 *   Production: https://api-m.paypal.com
 */
class PayPalGateway
{
    private string $clientId;
    private string $clientSecret;
    private string $baseUrl;

    public function __construct(string $clientId, string $clientSecret, string $environment = 'sandbox')
    {
        $this->clientId     = $clientId;
        $this->clientSecret = $clientSecret;
        $this->baseUrl      = strtolower($environment) === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
    }

    /**
     * Build a gateway from a payment_processors row's config_json, or null
     * when the PayPal REST credentials are not configured yet. Uses the
     * sandbox credential set when the processor mode is test, and the live
     * set when it is live.
     */
    public static function fromConfig(array $processor): ?self
    {
        $cfg = \App\Models\PaymentProcessor::decodeConfig($processor);
        $live = strtolower((string) $processor['mode']) === 'live';

        $clientId     = trim((string) ($cfg[$live ? 'client_id' : 'sandbox_client_id'] ?? ''));
        $clientSecret = trim((string) ($cfg[$live ? 'client_secret' : 'sandbox_client_secret'] ?? ''));

        if ($clientId === '' || $clientSecret === '') {
            return null;
        }

        $environment = $live ? 'live' : 'sandbox';

        return new self($clientId, $clientSecret, $environment);
    }

    /**
     * The configured webhook id (used to verify webhook signatures). Picks
     * the sandbox webhook id when the processor mode is test, otherwise the
     * live one.
     */
    public static function webhookId(array $processor): string
    {
        $cfg = \App\Models\PaymentProcessor::decodeConfig($processor);
        $live = strtolower((string) $processor['mode']) === 'live';

        return trim((string) ($cfg[$live ? 'webhook_id' : 'sandbox_webhook_id'] ?? ''));
    }

    /**
     * Fetch an OAuth2 access token using the client credentials grant.
     */
    public function accessToken(): string
    {
        $basic = base64_encode($this->clientId . ':' . $this->clientSecret);

        [$status, , $body] = Http::request($this->baseUrl . '/v1/oauth2/token', [
            'method'  => 'POST',
            'headers' => ['Authorization' => 'Basic ' . $basic],
            'form'    => ['grant_type' => 'client_credentials'],
            'timeout' => 30,
        ]);

        $data = json_decode((string) $body, true);

        if ($status !== 200 || empty($data['access_token'])) {
            throw new \RuntimeException('PayPal access token failed (HTTP ' . $status . '): ' . substr((string) $body, 0, 300));
        }

        return (string) $data['access_token'];
    }

    /**
     * Verify a webhook event against the PayPal REST
     * /v1/notifications/verify-webhook-signature endpoint. Returns true only
     * when PayPal reports SUCCESS.
     */
    public function verifyWebhookSignature(
        string $webhookId,
        string $authAlgo,
        string $certUrl,
        string $transmissionId,
        string $transmissionTime,
        string $signature,
        string $payload
    ): bool {
        $token = $this->accessToken();

        [$status, , $body] = Http::request($this->baseUrl . '/v1/notifications/verify-webhook-signature', [
            'method'  => 'POST',
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'json'    => [
                'auth_algo'         => $authAlgo,
                'cert_url'          => $certUrl,
                'transmission_id'   => $transmissionId,
                'transmission_sig'  => $signature,
                'transmission_time' => $transmissionTime,
                'webhook_id'        => $webhookId,
                'webhook_event'     => json_decode($payload, true),
            ],
            'timeout' => 30,
        ]);

        $data = json_decode((string) $body, true);

        return $status === 200 && ($data['verification_status'] ?? '') === 'SUCCESS';
    }

    /**
     * Fetch the current status of a PayPal subscription by its REST id.
     * Returns the status string (ACTIVE, APPROVED, SUSPENDED, CANCELLED,
     * EXPIRED, ...) or null when PayPal cannot be reached or the id is
     * unknown. Used by the scheduled reconciler to auto-activate paid
     * memberships when a webhook was missed.
     */
    public function getSubscriptionStatus(string $paypalSubscriptionId): ?string
    {
        $paypalSubscriptionId = trim($paypalSubscriptionId);

        if ($paypalSubscriptionId === '' || !preg_match('/\A[A-Za-z0-9_-]{6,100}\z/', $paypalSubscriptionId)) {
            return null;
        }

        $token = $this->accessToken();

        [$status, , $body] = Http::request(
            $this->baseUrl . '/v1/billing/subscriptions/' . rawurlencode($paypalSubscriptionId),
            [
                'method'  => 'GET',
                'headers' => ['Authorization' => 'Bearer ' . $token],
                'timeout' => 30,
            ]
        );

        if ($status !== 200) {
            error_log('[paypal-reconcile] status lookup failed (HTTP ' . $status . ') for ' . $paypalSubscriptionId . ': ' . substr((string) $body, 0, 300));
            return null;
        }

        $data = json_decode((string) $body, true);

        $result = (string) ($data['status'] ?? '');

        return $result !== '' ? $result : null;
    }

    /**
     * Cancel a PayPal subscription on PayPal's side so it stops billing.
     * POST /v1/billing/subscriptions/{id}/cancel with an empty JSON body.
     * Returns true when PayPal accepted the cancellation.
     */
    public function cancelSubscription(string $paypalSubscriptionId): bool
    {
        $paypalSubscriptionId = trim($paypalSubscriptionId);

        if ($paypalSubscriptionId === '' || !preg_match('/\A[A-Za-z0-9_-]{6,100}\z/', $paypalSubscriptionId)) {
            return false;
        }

        $token = $this->accessToken();

        [$status, , $body] = Http::request(
            $this->baseUrl . '/v1/billing/subscriptions/' . rawurlencode($paypalSubscriptionId) . '/cancel',
            [
                'method'  => 'POST',
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type'  => 'application/json',
                ],
                'json'    => new \stdClass(), // PayPal expects an empty JSON object {}
                'timeout' => 30,
            ]
        );

        if ($status !== 204 && $status !== 200) {
            error_log('[paypal-cancel] failed (HTTP ' . $status . ') for ' . $paypalSubscriptionId . ': ' . substr((string) $body, 0, 300));
            return false;
        }

        return true;
    }

    /**
     * Human-readable test for gateway configuration (separate from null
     * factory state).
     */
    public function isLive(): bool
    {
        return strpos($this->baseUrl, 'sandbox') === false;
    }
}
