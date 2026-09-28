<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\CryptoSigner;

/**
 * DeSo (Decentralized Social) client for the Auto Poster. Validates the wallet
 * against a public DeSo node and uploads media.
 *
 * NOTE: posting to DeSo requires building + signing an on-chain transaction
 * with the account's derived key. That builder is the one piece not wired in
 * this client — post() reports the gap explicitly instead of producing an
 * invalid signature, so the channel can be enabled, health-checked and
 * credited without ever silently failing.
 */
class DeSoClient
{
    private const NODE = 'https://api.deso.org';

    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function isConfigured(): bool
    {
        return trim((string) ($this->config['public_key'] ?? '')) !== ''
            && trim((string) ($this->config['seed_hex'] ?? '')) !== '';
    }

    public function isUserAuthorized(): bool
    {
        return $this->isConfigured() && CryptoSigner::available();
    }

    public function ping(): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'DeSo public key or seed missing.'];
        }

        [$status, , $body] = Http::request(
            self::NODE . '/api/v0/users/' . urlencode((string) $this->config['public_key']) . '?NoRoutes=true'
        );

        if ($status !== 200) {
            return ['ok' => false, 'error' => 'DeSo node rejected the key (HTTP ' . $status . ').'];
        }

        $data = json_decode($body, true);
        $name = (string) ($data['ProfileEntryResponse']['Username'] ?? $this->config['public_key']);

        return ['ok' => true, 'note' => $name];
    }

    public function post(string $text, array $media = [], array $meta = []): array
    {
        return [
            'ok'    => false,
            'error' => 'DeSo posting is not wired: it needs an on-chain transaction builder for derived-key signing '
                . '(the account and secp256k1 backend are verified; the SubmitPost transaction serializer is not yet implemented).',
        ];
    }
}