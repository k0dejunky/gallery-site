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
     * when the PayPal REST credentials are not configured yet.
     */
    public static function fromConfig(array $processor): ?self
    {
        $cfg          = \App\Models\PaymentProcessor::decodeConfig($processor);
        $clientId     = trim((string) ($cfg['client_id'] ?? ''));
        $clientSecret = trim((string) ($cfg['client_secret'] ?? ''));

        if ($clientId === '' || $clientSecret === '') {
            return null;
        }

        $environment = strtolower((string) $processor['mode']) === 'live' ? 'live' : 'sandbox';

        return new self($clientId, $clientSecret, $environment);
    }

    /**
     * The configured webhook id (used to verify webhook signatures).
     */
    public static function webhookId(array $processor): string
    {
        $cfg = \App\Models\PaymentProcessor::decodeConfig($processor);

        return trim((string) ($cfg['webhook_id'] ?? ''));
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
     * Human-readable test for gateway configuration (separate from null
     * factory state).
     */
    public function isLive(): bool
    {
        return strpos($this->baseUrl, 'sandbox') === false;
    }
}
