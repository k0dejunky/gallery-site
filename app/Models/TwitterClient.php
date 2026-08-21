<?php

namespace App\Models;

/**
 * Minimal X (formerly Twitter) API client for the Auto Poster. Uses an OAuth2
 * bearer token (from the X developer portal) to create posts via the v2 API.
 * Media upload is not supported in this minimal client; only text posts.
 */
class TwitterClient
{
    private const API_URL = 'https://api.twitter.com/2';

    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Whether the required credentials are present.
     */
    public function isConfigured(): bool
    {
        return !empty($this->config['bearer_token']);
    }

    /**
     * Create a post (tweet). $content is capped at 280 characters.
     *
     * @return array{ok:bool, id?:string, url?:string, error?:string}
     */
    public function post(string $content): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'X/Twitter is not configured.'];
        }

        $text = mb_substr(trim($content), 0, 280);

        if ($text === '') {
            return ['ok' => false, 'error' => 'Post text cannot be empty.'];
        }

        [$status, , $body] = Http::request(self::API_URL . '/tweets', [
            'method'  => 'POST',
            'headers' => [
                'Authorization' => 'Bearer ' . $this->config['bearer_token'],
                'Content-Type'  => 'application/json',
            ],
            'json'    => ['text' => $text],
        ]);

        $data = json_decode($body, true);

        if ($status !== 201) {
            $detail = $data['detail'] ?? '';
            if (empty($detail) && isset($data['errors'][0]['message'])) {
                $detail = $data['errors'][0]['message'];
            }
            return ['ok' => false, 'error' => $detail !== '' ? $detail : 'X/Twitter rejected the post (HTTP ' . $status . ').'];
        }

        $tweetId = $data['data']['id'] ?? '';

        return [
            'ok'  => true,
            'id'  => $tweetId,
            'url' => $tweetId !== '' ? 'https://twitter.com/i/status/' . $tweetId : '',
        ];
    }
}
