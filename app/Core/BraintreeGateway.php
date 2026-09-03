<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Minimal Braintree API client — no SDK dependency. Handles client token
 * generation, payment-method vaulting, subscription CRUD and webhook
 * signature verification via raw HTTP requests with PHP curl.
 *
 * Environment URLs:
 *   Sandbox: https://api.sandbox.braintreegateway.com
 *   Production: https://api.braintreegateway.com
 */
class BraintreeGateway
{
    private string $merchantId;
    private string $publicKey;
    private string $privateKey;
    private string $baseUrl;

    public function __construct(string $merchantId, string $publicKey, string $privateKey, string $environment = 'sandbox')
    {
        $this->merchantId = $merchantId;
        $this->publicKey  = $publicKey;
        $this->privateKey  = $privateKey;
        $this->baseUrl    = strtolower($environment) === 'live'
            ? 'https://api.braintreegateway.com'
            : 'https://api.sandbox.braintreegateway.com';
    }

    /**
     * Build a gateway from a payment_processors row's config_json.
     */
    public static function fromConfig(array $processor): ?self
    {
        $cfg = \App\Models\PaymentProcessor::decodeConfig($processor);

        $merchantId = trim((string) ($cfg['merchant_id'] ?? ''));
        $publicKey  = trim((string) ($cfg['public_key'] ?? ''));
        $privateKey = trim((string) ($cfg['private_key'] ?? ''));

        if ($merchantId === '' || $publicKey === '' || $privateKey === '') {
            return null;
        }

        $environment = strtolower((string) $processor['mode']) === 'live' ? 'live' : 'sandbox';

        return new self($merchantId, $publicKey, $privateKey, $environment);
    }

    /**
     * The public key — needed by the client-side JS SDK.
     */
    public function publicKey(): string
    {
        return $this->publicKey;
    }

    /**
     * The Braintree gateway environment name ('sandbox' or 'production').
     */
    public function environment(): string
    {
        return strpos($this->baseUrl, 'sandbox') !== false ? 'sandbox' : 'production';
    }

    // ------------------------------------------------------------------
    // Client token
    // ------------------------------------------------------------------

    /**
     * Generate a client token, optionally linked to an existing customer
     * so the client can vault additional payment methods.
     */
    public function clientToken(?string $customerId = null): string
    {
        $payload = [];

        if ($customerId !== null && $customerId !== '') {
            $payload['customer_id'] = $customerId;
        }

        $response = $this->post('/merchants/' . $this->merchantId . '/client_token', $payload);

        if (isset($response['client_token'])) {
            return $response['client_token'];
        }

        throw new \RuntimeException('Braintree client token failed: ' . json_encode($response));
    }

    // ------------------------------------------------------------------
    // Customers
    // ------------------------------------------------------------------

    /**
     * Create a Braintree customer. Returns the customer array with 'id'.
     */
    public function createCustomer(string $email, ?string $firstName = null, ?string $lastName = null, ?string $phone = null, ?string $customFields = null): array
    {
        $payload = [
            'customer' => array_filter([
                'email'      => $email,
                'first_name' => $firstName,
                'last_name'  => $lastName,
                'phone'      => $phone,
                'custom_fields' => $customFields,
            ], fn($v) => $v !== null && $v !== ''),
        ];

        $response = $this->post('/merchants/' . $this->merchantId . '/customers', $payload);

        if (isset($response['customer'])) {
            return $response['customer'];
        }

        throw new \RuntimeException('Braintree create customer failed: ' . json_encode($response));
    }

    /**
     * Find an existing customer by id.
     */
    public function findCustomer(string $customerId): ?array
    {
        try {
            $response = $this->get('/merchants/' . $this->merchantId . '/customers/' . $customerId);
            return $response['customer'] ?? null;
        } catch (\Throwable) {
            return null;
        }
    }

    // ------------------------------------------------------------------
    // Payment methods
    // ------------------------------------------------------------------

    /**
     * Vault a payment method from a nonce, returning the payment-method
     * token. The token is used to create subscriptions.
     */
    public function createPaymentMethod(string $customerId, string $nonce, bool $makeDefault = true): array
    {
        $payload = [
            'payment_method' => [
                'customer_id'    => $customerId,
                'payment_method_nonce' => $nonce,
                'options'        => [
                    'verify_card'                => true,
                    'make_default'               => $makeDefault,
                    'verification_merchant_account_id' => $this->merchantId,
                ],
            ],
        ];

        $response = $this->post('/merchants/' . $this->merchantId . '/payment_methods', $payload);

        if (isset($response['payment_method'])) {
            return $response['payment_method'];
        }

        throw new \RuntimeException('Braintree vault payment method failed: ' . json_encode($response));
    }

    // ------------------------------------------------------------------
    // Subscriptions
    // ------------------------------------------------------------------

    /**
     * Create a Braintree subscription. The plan_id is the merchant's
     * Braintree plan id (e.g. "monthly-plan"). price can override the
     * plan's default price.
     */
    public function createSubscription(string $paymentMethodToken, string $planId, ?float $price = null, ?string $trialDays = null, ?string $merchantAccountId = null): array
    {
        $payload = [
            'subscription' => array_filter([
                'payment_method_token' => $paymentMethodToken,
                'plan_id'              => $planId,
                'price'                => $price !== null ? number_format($price, 2, '.', '') : null,
                'trial_duration'       => $trialDays,
                'trial_duration_unit'  => $trialDays !== null ? 'day' : null,
                'merchant_account_id'  => $merchantAccountId,
            ], fn($v) => $v !== null && $v !== ''),
        ];

        $response = $this->post('/merchants/' . $this->merchantId . '/subscriptions', $payload);

        if (isset($response['subscription'])) {
            return $response['subscription'];
        }

        throw new \RuntimeException('Braintree create subscription failed: ' . json_encode($response));
    }

    // ------------------------------------------------------------------
    // Webhooks
    // ------------------------------------------------------------------

    /**
     * Verify a Braintree webhook signature and return the notification
     * kind + subscription id. Throws on invalid signature.
     *
     * Braintree webhook verification uses HMAC-SHA1 with the public key
     * as the message and the private key as the secret. The signature
     * header contains: "timestamp|publicKey|signature".
     */
    public function verifyWebhook(string $signatureHeader, string $payloadBody): array
    {
        $parts = explode('|', $signatureHeader);

        if (count($parts) < 3) {
            throw new \RuntimeException('Invalid Braintree webhook signature format');
        }

        [$timestamp, $publicKey, $signature] = $parts;

        // Build the verification string: timestamp + public_key + body
        $verificationString = $timestamp . $this->publicKey . $payloadBody;
        $expectedSignature  = hash_hmac('sha1', $verificationString, $this->privateKey);

        if (!hash_equals($expectedSignature, $signature)) {
            throw new \RuntimeException('Braintree webhook signature mismatch');
        }

        // Parse the XML notification payload
        $xml = @simplexml_load_string($payloadBody);

        if ($xml === false) {
            throw new \RuntimeException('Invalid Braintree webhook XML');
        }

        $kind = (string) ($xml->kind ?? '');

        $subscriptionId = '';
        if (isset($xml->subscription->id)) {
            $subscriptionId = (string) $xml->subscription->id;
        }

        // Extract status if present
        $status = '';
        if (isset($xml->subscription->status)) {
            $status = (string) $xml->subscription->status;
        }

        return [
            'kind'            => $kind,
            'subscription_id' => $subscriptionId,
            'status'          => $status,
            'xml'             => $xml,
        ];
    }

    // ------------------------------------------------------------------
    // HTTP helpers
    // ------------------------------------------------------------------

    private function post(string $path, array $data): array
    {
        return $this->request('POST', $path, $data);
    }

    private function get(string $path): array
    {
        return $this->request('GET', $path);
    }

    private function put(string $path, array $data): array
    {
        return $this->request('PUT', $path, $data);
    }

    private function request(string $method, string $path, ?array $data = null): array
    {
        $url = $this->baseUrl . $path;

        $ch = curl_init($url);

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_USERPWD        => $this->publicKey . ':' . $this->privateKey,
            CURLOPT_TIMEOUT        => 30,
        ]);

        switch ($method) {
            case 'POST':
                curl_setopt($ch, CURLOPT_POST, true);
                if ($data !== null) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                }
                break;
            case 'PUT':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
                if ($data !== null) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                }
                break;
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException('Braintree cURL error: ' . $error);
        }

        $decoded = json_decode((string) $response, true);

        if (!is_array($decoded)) {
            throw new \RuntimeException('Braintree non-JSON response (HTTP ' . $httpCode . '): ' . substr((string) $response, 0, 500));
        }

        // Braintree API errors live in 'apiErrorResponse'
        if (isset($decoded['apiErrorResponse'])) {
            $err = $decoded['apiErrorResponse'];
            $messages = [];
            if (isset($err['errors']['transaction']['errors'])) {
                foreach ($err['errors']['transaction']['errors'] as $e) {
                    $messages[] = (string) ($e['message'] ?? '');
                }
            }
            if (isset($err['errors']['subscription']['errors'])) {
                foreach ($err['errors']['subscription']['errors'] as $e) {
                    $messages[] = (string) ($e['message'] ?? '');
                }
            }
            if (isset($err['errors']['customer']['errors'])) {
                foreach ($err['errors']['customer']['errors'] as $e) {
                    $messages[] = (string) ($e['message'] ?? '');
                }
            }
            if (isset($err['errors']['paymentMethod']['errors'])) {
                foreach ($err['errors']['paymentMethod']['errors'] as $e) {
                    $messages[] = (string) ($e['message'] ?? '');
                }
            }
            $msg = implode('; ', array_filter($messages)) ?: (string) ($err['message'] ?? 'Unknown Braintree error');
            throw new \RuntimeException('Braintree API error: ' . $msg);
        }

        if ($httpCode >= 400) {
            throw new \RuntimeException('Braintree HTTP ' . $httpCode . ': ' . substr((string) $response, 0, 500));
        }

        return $decoded;
    }
}
