<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Bluesky (AT Protocol) client for the Auto Poster. Authenticates with an
 * app password, uploads media blobs and creates app.bsky.feed.post records.
 * Sensitive posts are self-labeled 'porn' so they are hidden behind the
 * adult-content gate.
 */
class BlueskyClient
{
    private const PDS = 'https://bsky.social';

    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function isConfigured(): bool
    {
        return trim((string) ($this->config['handle'] ?? '')) !== ''
            && trim((string) ($this->config['app_password'] ?? '')) !== '';
    }

    public function isUserAuthorized(): bool
    {
        return $this->isConfigured();
    }

    public function ping(): array
    {
        $session = $this->createSession();

        if (!$session['ok']) {
            return ['ok' => false, 'error' => $session['error']];
        }

        return ['ok' => true, 'note' => $session['handle']];
    }

    public function post(string $text, array $media = [], array $meta = []): array
    {
        $session = $this->createSession();
        if (!$session['ok']) {
            return $session;
        }

        $jwt    = $session['jwt'];
        $did    = $session['did'];
        $handle = $session['handle'];
        $sensitive = (bool) ($meta['sensitive'] ?? true);

        try {
            // Upload each image as a blob.
            $images = [];
            foreach ($media as $m) {
                if (empty($m['tmp_name']) || !is_file($m['tmp_name'])) {
                    continue;
                }
                $blob = $this->uploadBlob($jwt, $m);
                if ($blob !== null) {
                    $images[] = [
                        'alt'   => '',
                        'image' => $blob,
                    ];
                }
            }

            $record = [
                '$type'    => 'app.bsky.feed.post',
                'text'     => trim($text) !== '' ? $text : 'New upload',
                'createdAt' => gmdate('c'),
                'langs'    => ['en'],
            ];

            if ($sensitive) {
                $record['labels'] = [
                    '$type' => 'com.atproto.label.defs#selfLabels',
                    'values' => [['val' => 'porn']],
                ];
            }

            if ($images !== []) {
                $record['embed'] = [
                    '$type' => 'app.bsky.embed.images',
                    'images' => $images,
                ];
            }

            [$status, , $body] = Http::request(self::PDS . '/xrpc/com.atproto.repo.createRecord', [
                'method' => 'POST',
                'headers' => ['Authorization' => 'Bearer ' . $jwt],
                'json' => [
                    'repo'       => $did,
                    'collection' => 'app.bsky.feed.post',
                    'record'     => $record,
                ],
            ]);

            if ($status !== 200) {
                return ['ok' => false, 'error' => 'Bluesky createRecord failed (HTTP ' . $status . '): ' . $body];
            }

            $uri = (string) (json_decode($body, true)['uri'] ?? '');
            $rkey = (string) (json_decode($body, true)['cid'] ?? '');
            $parts = explode('/', $uri);
            $rkey = end($parts);

            return ['ok' => true, 'url' => 'https://bsky.app/profile/' . $handle . '/post/' . $rkey];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function createSession(): array
    {
        [$status, , $body] = Http::request(self::PDS . '/xrpc/com.atproto.server.createSession', [
            'method' => 'POST',
            'json' => [
                'identifier' => (string) ($this->config['handle'] ?? ''),
                'password'   => (string) ($this->config['app_password'] ?? ''),
            ],
        ]);

        if ($status !== 200) {
            return ['ok' => false, 'error' => 'Bluesky login failed (HTTP ' . $status . ').'];
        }

        $data = json_decode($body, true);

        return [
            'ok'     => true,
            'jwt'    => (string) ($data['accessJwt'] ?? ''),
            'did'    => (string) ($data['did'] ?? ''),
            'handle' => (string) ($data['handle'] ?? $this->config['handle'] ?? ''),
        ];
    }

    private function uploadBlob(string $jwt, array $m): ?array
    {
        $mime = (string) ($m['type'] ?? mime_content_type($m['tmp_name']) ?: 'application/octet-stream');

        [$status, , $body] = Http::request(self::PDS . '/xrpc/com.atproto.repo.uploadBlob', [
            'method'  => 'POST',
            'headers' => [
                'Authorization' => 'Bearer ' . $jwt,
                'Content-Type'  => $mime,
            ],
            'body' => (string) file_get_contents($m['tmp_name']),
        ]);

        if ($status !== 200) {
            return null;
        }

        return json_decode($body, true)['blob'] ?? null;
    }
}