<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\CryptoSigner;

/**
 * Hive / PeakD client for the Auto Poster. Builds a signed "comment" operation
 * (a blog post) and broadcasts it to the Hive blockchain via a public JSON-RPC
 * node. Signing uses the account's posting key + the secp256k1 extension.
 */
class HiveClient
{
    private const NODE    = 'https://api.hive.blog';
    private const CHAIN_ID = 'beeab0de00000000000000000000000000000000000000000000000000000000';

    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function isConfigured(): bool
    {
        return trim((string) ($this->config['account'] ?? '')) !== ''
            && trim((string) ($this->config['posting_key'] ?? '')) !== '';
    }

    public function isUserAuthorized(): bool
    {
        return $this->isConfigured() && CryptoSigner::available();
    }

    public function ping(): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'Hive account or posting key missing.'];
        }
        if (!CryptoSigner::available()) {
            return ['ok' => false, 'error' => 'The secp256k1 PHP extension is required for Hive posting.'];
        }

        $result = $this->rpc('condenser_api.get_accounts', [[(string) $this->config['account']]]);

        if (!$result['ok']) {
            return $result;
        }

        $accounts = json_decode((string) $result['body'], true);
        $exists = !empty($accounts['result'][0]['name'] ?? null);

        return $exists ? ['ok' => true, 'note' => '@' . $this->config['account']] : ['ok' => false, 'error' => 'Hive account not found.'];
    }

    public function post(string $text, array $media = [], array $meta = []): array
    {
        try {
            if (!CryptoSigner::available()) {
                throw new \RuntimeException('The secp256k1 PHP extension is required for Hive posting.');
            }

            $account = (string) ($this->config['account'] ?? '');
            $seckey  = CryptoSigner::normalizeSeckey((string) $this->config['posting_key']);
            $title   = (string) ($meta['title'] ?? 'New upload');
            $body    = trim($text);
            if ($body === '') {
                $body = $title;
            }

            $props = $this->rpc('condenser_api.get_dynamic_global_properties', []);
            if (!$props['ok']) {
                return $props;
            }
            $gprops = json_decode((string) $props['body'], true)['result'] ?? [];
            $headBlockNumber = (int) ($gprops['head_block_number'] ?? 0);
            $blockId = (string) ($gprops['head_block_id'] ?? '');

            $refBlockNum = $headBlockNumber & 0xFFFF;
            $refBlockPrefix = 0;
            if (strlen($blockId) >= 8) {
                $bytes = array_values(unpack('C*', substr($blockId, 0, 8)));
                $refBlockPrefix = $bytes[3] << 24 | $bytes[2] << 16 | $bytes[1] << 8 | $bytes[0];
            }

            $permlink = self::makePermlink($title);
            $expiration = time() + 60;

            $opFields = [
                'author'           => $account,
                'body'             => $body,
                'json_metadata'    => '{}',
                'parent_author'    => '',
                'parent_permlink'  => 'gallery',
                'permlink'         => $permlink,
                'title'            => $title,
            ];
            ksort($opFields);

            $tx = self::pack('v', $refBlockNum)
                . self::pack('V', $refBlockPrefix)
                . self::pack('V', $expiration)
                . self::varint(1)                       // operation count
                . self::varint(1)                       // operation type: comment
                . self::varint(count($opFields));
            foreach ($opFields as $value) {
                $tx .= self::varint(strlen($value)) . $value;
            }
            $tx .= self::varint(0);                     // extensions

            $digest = hash('sha256', hex2bin(self::CHAIN_ID) . $tx, true);
            [$sig64, $recid] = CryptoSigner::signRecoverable($seckey, $digest);
            $signature = bin2hex($sig64 . chr($recid + 31));

            $operation = [
                [
                    'comment',
                    [
                        'parent_author'   => '',
                        'parent_permlink' => 'gallery',
                        'author'          => $account,
                        'permlink'        => $permlink,
                        'title'           => $title,
                        'body'            => $body,
                        'json_metadata'   => '{}',
                    ],
                ],
            ];

            $transaction = [
                'ref_block_num'    => $refBlockNum,
                'ref_block_prefix' => $refBlockPrefix,
                'expiration'       => gmdate('Y-m-d\TH:i:s', $expiration),
                'operations'       => $operation,
                'extensions'       => [],
                'signatures'       => [$signature],
            ];

            $broadcast = $this->rpc('condenser_api.broadcast_transaction', [$transaction]);
            if (!$broadcast['ok']) {
                return $broadcast;
            }

            $resp = json_decode((string) $broadcast['body'], true);
            if (isset($resp['error'])) {
                return ['ok' => false, 'error' => 'Hive rejected the transaction: ' . ($resp['error']['message'] ?? 'unknown')];
            }

            return [
                'ok'  => true,
                'url' => 'https://peakd.com/hive-105029/@' . $account . '/' . $permlink,
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function rpc(string $method, array $params): array
    {
        [$status, , $body] = Http::request(self::NODE, [
            'method' => 'POST',
            'json' => [
                'jsonrpc' => '2.0',
                'id'      => 1,
                'method'  => $method,
                'params'  => $params,
            ],
        ]);

        if ($status !== 200) {
            return ['ok' => false, 'error' => 'Hive node error (HTTP ' . $status . ').'];
        }

        return ['ok' => true, 'body' => $body];
    }

    private static function varint(int $value): string
    {
        $out = '';
        do {
            $b = $value & 0x7f;
            $value >>= 7;
            if ($value > 0) {
                $b |= 0x80;
            }
            $out .= chr($b);
        } while ($value > 0);

        return $out;
    }

    private static function pack(string $format, int $value): string
    {
        return pack($format, $value);
    }

    private static function makePermlink(string $title): string
    {
        $slug = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $title));
        $slug = trim($slug, '-');
        if ($slug === '') {
            $slug = 'post';
        }
        $slug = substr($slug, 0, 200) . '-' . gmdate('ymd-His');

        return $slug;
    }
}