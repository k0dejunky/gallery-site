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

        // The v1 API returns {"clientToken": {"value": "<token>"}} (the class
        // historically also expected a flat "client_token"). Accept both.
        $token = $response['clientToken']['value'] ?? $response['client_token'] ?? null;

        if (is_string($token) && $token !== '') {
            return $token;
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
                'email'          => $email,
                'first_name'     => $firstName,
                'last_name'      => $lastName,
                'phone'          => $phone,
                'custom_fields'  => $customFields,
            ], fn($v) => $v !== null && $v !== ''),
        ];

        $xml = $this->request('POST', '/merchants/' . $this->merchantId . '/customers', ['xml' => true, 'payload' => $payload]);

        return ['id' => (string) ($xml->id ?? '')];
    }

    /**
     * Find an existing customer by id.
     */
    public function findCustomer(string $customerId): ?array
    {
        try {
            $result = $this->request('GET', '/merchants/' . $this->merchantId . '/customers/' . rawurlencode($customerId), ['xml' => true]);
        } catch (\Throwable) {
            return null;
        }

        return ['id' => (string) ($result->id ?? $customerId)];
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
                'customer_id'         => $customerId,
                'payment_method_nonce' => $nonce,
                'options'             => [
                    'verify_card'                          => true,
                    'make_default'                         => $makeDefault,
                    'verification_merchant_account_id'     => $this->merchantId,
                ],
            ],
        ];

        $xml = $this->request('POST', '/merchants/' . $this->merchantId . '/payment_methods', ['xml' => true, 'payload' => $payload]);

        return [
            'token'  => (string) ($xml->token ?? ''),
            'id'     => (string) ($xml->id ?? ''),
        ];
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

        $xml = $this->request('POST', '/merchants/' . $this->merchantId . '/subscriptions', ['xml' => true, 'payload' => $payload]);

        return [
            'id'     => (string) ($xml->id ?? ''),
            'status' => (string) ($xml->status ?? ''),
        ];
    }

    // ------------------------------------------------------------------
    // Plans
    // ------------------------------------------------------------------

    /**
     * Create a Braintree subscription plan. The site uses one Braintree
     * plan per membership tier (id e.g. "silver_monthly"), provisioned by
     * Admin -> Plans -> "Provision Braintree plans". $options may set
     * price and billing_frequency; defaults come from the merchant account.
     */
    public function createPlan(string $name, string $id, ?string $price = null, ?int $billingFrequency = null, string $currency = 'USD', ?int $billingDayOfMonth = null, ?int $billingMonth = null): array
    {
        $payload = [
            'plan' => array_filter([
                'name'                 => $name,
                'id'                   => $id,
                'price'                => $price !== null ? number_format((float) $price, 2, '.', '') : null,
                'billing_frequency'    => $billingFrequency !== null ? max(1, min(12, $billingFrequency)) : null,
                'billing_day_of_month' => $billingDayOfMonth !== null ? max(1, min(31, $billingDayOfMonth)) : null,
                'billing_month'        => $billingMonth !== null ? max(1, min(12, $billingMonth)) : null,
                'currency_iso_code'    => $currency,
            ], fn($v) => $v !== null && $v !== ''),
        ];

        $response = $this->post('/merchants/' . $this->merchantId . '/plans', $payload);

        if (isset($response['plan'])) {
            return $response['plan'];
        }

        throw new \RuntimeException('Braintree create plan failed: ' . json_encode($response));
    }

    /**
     * Find a Braintree plan by id, or null when it does not exist.
     */
    public function findPlan(string $planId): ?array
    {
        try {
            $response = $this->get('/merchants/' . $this->merchantId . '/plans/' . rawurlencode($planId));
            return $response['plan'] ?? null;
        } catch (\Throwable) {
            return null;
        }
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

    /**
     * JSON POST (client token, plans). Returns the decoded response array.
     */
    private function post(string $path, array $data): array
    {
        return $this->request('POST', $path, ['payload' => $data]);
    }

    /**
     * JSON GET (plan lookup). Returns the decoded response array.
     */
    private function get(string $path): array
    {
        return $this->request('GET', $path);
    }

    /**
     * Perform an API call. JSON is the default; pass ['xml' => true] for the
     * customers / payment-methods / subscriptions endpoints, which are served
     * as XML and 406 on an "Accept: application/json" header. Every call
     * sends X-ApiVersion: 6, which the sandbox/live gateways require for
     * anything beyond the client token endpoint.
     *
     * Returns the decoded JSON array, or a SimpleXMLElement for XML calls.
     *
     * @throws \RuntimeException on network failure, HTTP >= 400, or a
     *         Braintree api error response.
     */
    private function request(string $method, string $path, array $opts = [])
    {
        $xml       = !empty($opts['xml']);
        $payload   = $opts['payload'] ?? null;
        $url       = $this->baseUrl . $path;

        $headers = [
            'Content-Type: application/json',
            'X-ApiVersion: 6',
        ];
        if (!$xml) {
            $headers[] = 'Accept: application/json';
        }

        $curlOpts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_USERPWD        => $this->publicKey . ':' . $this->privateKey,
            CURLOPT_TIMEOUT        => 30,
        ];

        $ch = curl_init($url);

        if ($method === 'POST') {
            $curlOpts[CURLOPT_POST] = true;
            if ($payload !== null) {
                $curlOpts[CURLOPT_POSTFIELDS] = json_encode($payload);
            }
        }

        curl_setopt_array($ch, $curlOpts);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException('Braintree cURL error: ' . $error);
        }

        if ($httpCode >= 400) {
            throw new \RuntimeException($this->describeError((string) $response, $httpCode, $xml));
        }

        if ($xml) {
            $parsed = @simplexml_load_string((string) $response);
            if ($parsed === false) {
                throw new \RuntimeException('Braintree non-XML response (HTTP ' . $httpCode . '): ' . substr((string) $response, 0, 500));
            }
            return $parsed;
        }

        $decoded = json_decode((string) $response, true);

        if (!is_array($decoded)) {
            throw new \RuntimeException('Braintree non-JSON response (HTTP ' . $httpCode . '): ' . substr((string) $response, 0, 500));
        }

        // Managed-plan/JSON API errors live in apiErrorResponse (plans).
        if (isset($decoded['apiErrorResponse'])) {
            $err = $decoded['apiErrorResponse'];
            $messages = [];
            foreach (['transaction', 'subscription', 'customer', 'paymentMethod', 'plan'] as $section) {
                if (isset($err['errors'][$section]['errors'])) {
                    foreach ($err['errors'][$section]['errors'] as $e) {
                        $messages[] = (string) ($e['message'] ?? '');
                    }
                }
            }
            $msg = implode('; ', array_filter($messages)) ?: (string) ($err['message'] ?? 'Unknown Braintree error');
            throw new \RuntimeException('Braintree API error: ' . $msg);
        }

        return $decoded;
    }

    /**
     * Turn an HTTP >= 400 body into a useful exception message regardless of
     * whether Braintree replied with XML (customers/subscriptions) or JSON
     * (plans/client token).
     */
    private function describeError(string $body, int $httpCode, bool $xml): string
    {
        if ($body === '') {
            return 'Braintree HTTP ' . $httpCode;
        }

        if ($xml) {
            $parsed = @simplexml_load_string($body);
            if ($parsed !== false) {
                $messages = [];
                foreach (($parsed->xpath('//error/message') ?? []) as $m) {
                    $messages[] = trim((string) $m);
                }
                $root = trim((string) ($parsed->message ?? ''));
                if ($root !== '') {
                    $messages[] = $root;
                }
                if ($messages !== []) {
                    return 'Braintree API error: ' . implode('; ', array_unique($messages));
                }
            }
            return 'Braintree HTTP ' . $httpCode . ': ' . substr($body, 0, 500);
        }

        $decoded = json_decode($body, true);
        if (is_array($decoded) && isset($decoded['apiErrorResponse']['message'])) {
            return 'Braintree API error: ' . $decoded['apiErrorResponse']['message'];
        }

        return 'Braintree HTTP ' . $httpCode . ': ' . substr($body, 0, 500);
    }
}
