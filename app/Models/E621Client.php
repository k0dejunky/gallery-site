<?php

declare(strict_types=1);

namespace App\Models;

/**
 * e621 API client for the Auto Poster. Uploads an image as an explicit-rated
 * post. Furry content only; e621 requires ≥10 descriptive tags, so they are
 * derived from the post title/description when possible.
 */
class E621Client
{
    private const API = 'https://e621.net';

    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function isConfigured(): bool
    {
        return trim((string) ($this->config['username'] ?? '')) !== ''
            && trim((string) ($this->config['api_key'] ?? '')) !== '';
    }

    public function isUserAuthorized(): bool
    {
        return $this->isConfigured();
    }

    public function ping(): array
    {
        [$status, , $body] = Http::request(self::API . '/users/current.json', [
            'headers' => ['Authorization' => 'Basic ' . base64_encode($this->config['username'] . ':' . $this->config['api_key'])],
        ]);

        if ($status !== 200) {
            return ['ok' => false, 'error' => 'e621 rejected the credentials (HTTP ' . $status . ').'];
        }

        $name = (string) (json_decode($body, true)['name'] ?? '');

        return ['ok' => true, 'note' => $name];
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
            return ['ok' => false, 'error' => 'e621 posts require an image file.'];
        }

        $tags = $this->buildTags((string) ($meta['title'] ?? '') . ' ' . $text);
        if (count($tags) < 10) {
            return ['ok' => false, 'error' => 'e621 requires at least 10 tags; only ' . count($tags) . ' could be derived from the post.'];
        }

        try {
            [$status, , $body] = Http::request(self::API . '/uploads.json', [
                'method' => 'POST',
                'headers' => ['Authorization' => 'Basic ' . base64_encode($this->config['username'] . ':' . $this->config['api_key'])],
                'multipart' => [
                    'upload[tag_string]' => implode(' ', $tags),
                    'upload[rating]'     => 'e',
                    'upload[description]' => trim($text),
                    'upload[source]'     => 'https://amethyst2213.com',
                    'upload[file]'       => [
                        'file' => $file['tmp_name'],
                        'name' => $file['name'] ?? basename($file['tmp_name']),
                        'type' => (string) ($file['type'] ?? mime_content_type($file['tmp_name']) ?: 'image/jpeg'),
                    ],
                ],
            ]);

            if ($status !== 201 && $status !== 200) {
                return ['ok' => false, 'error' => 'e621 upload failed (HTTP ' . $status . '): ' . $body];
            }

            $id = (int) (json_decode($body, true)['id'] ?? 0);

            return ['ok' => true, 'url' => $id > 0 ? 'https://e621.net/posts/' . $id : ''];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * @return list<string>
     */
    private function buildTags(string $text): array
    {
        $tags = [];
        $words = preg_split('/[\s,;]+/', strtolower((string) preg_replace('/[^A-Za-z0-9 _-]/', ' ', $text))) ?: [];
        foreach ($words as $word) {
            $word = trim($word, ' _-');
            if ($word === '' || strlen($word) < 3) {
                continue;
            }
            if (!in_array($word, $tags, true)) {
                $tags[] = $word;
            }
        }

        return $tags;
    }
}