<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Odysee / LBRY client for the Auto Poster. Publishes a video through a local
 * LBRY SDK daemon (lbrynet) via its JSON-RPC proxy — the daemon holds the
 * wallet and performs the on-chain claim. Best-effort: surfaces the daemon's
 * errors verbatim.
 */
class OdyseeClient
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function isConfigured(): bool
    {
        return trim((string) ($this->config['lbrynet_url'] ?? '')) !== '';
    }

    public function isUserAuthorized(): bool
    {
        return $this->isConfigured();
    }

    public function ping(): array
    {
        $result = $this->rpc('status');

        if (!$result['ok']) {
            return $result;
        }

        $data = json_decode((string) $result['body'], true);
        $wallet = $data['result']['wallet'] ?? [];

        return ['ok' => true, 'note' => 'lbrynet ' . ($data['result']['lbrynet_version'] ?? '?')];
    }

    public function post(string $text, array $media = [], array $meta = []): array
    {
        $file = null;
        foreach ($media as $m) {
            if (!empty($m['tmp_name']) && is_file($m['tmp_name'])) {
                $file = $m;
                break;
            }
        }

        if ($file === null) {
            return ['ok' => false, 'error' => 'Odysee posts require a video file.'];
        }

        $title = (string) ($meta['title'] ?? 'New upload');
        $tags  = ['mature'];
        $words = preg_split('/\s+/', strtolower((string) preg_replace('/[^A-Za-z0-9 ]/', ' ', $title))) ?: [];
        foreach ($words as $w) {
            $w = trim($w);
            if ($w !== '' && strlen($w) > 2 && count($tags) < 5 && !in_array($w, $tags, true)) {
                $tags[] = $w;
            }
        }

        $result = $this->rpc('publish', [
            'name'        => 'gallery-' . gmdate('ymd-His'),
            'file_path'   => $file['tmp_name'],
            'title'       => $title,
            'description' => trim($text),
            'tags'        => $tags,
            'license'     => 'None',
            'channel_id'  => (string) ($this->config['account'] ?? ''),
        ]);

        if (!$result['ok']) {
            return $result;
        }

        $resp = json_decode((string) $result['body'], true);
        if (isset($resp['error'])) {
            return ['ok' => false, 'error' => 'LBRY error: ' . ($resp['error']['message'] ?? 'unknown')];
        }

        $claimId = (string) ($resp['result']['claim_id'] ?? $resp['result']['outputs'][0]['claim_id'] ?? '');

        return ['ok' => true, 'url' => $claimId !== '' ? 'https://odysee.com/' . $claimId : ''];
    }

    private function rpc(string $method, array $params = []): array
    {
        $url = trim((string) ($this->config['lbrynet_url'] ?? ''));
        if ($url === '') {
            return ['ok' => false, 'error' => 'No LBRY SDK URL configured.'];
        }

        [$status, , $body] = Http::request($url, [
            'method' => 'POST',
            'json' => [
                'jsonrpc' => '2.0',
                'id'      => 1,
                'method'  => $method,
                'params'  => $params,
            ],
        ]);

        if ($status !== 200) {
            return ['ok' => false, 'error' => 'LBRY daemon error (HTTP ' . $status . ').'];
        }

        return ['ok' => true, 'body' => $body];
    }
}