<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Discord webhook client for the Auto Poster. Posts content + attachments to
 * an age-restricted (18+) channel via its webhook URL — no OAuth needed.
 */
class DiscordClient
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function isConfigured(): bool
    {
        return trim((string) ($this->config['webhook_url'] ?? '')) !== '';
    }

    public function isUserAuthorized(): bool
    {
        return $this->isConfigured();
    }

    public function ping(): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'Webhook URL missing.'];
        }

        [$status, , $body] = Http::request($this->config['webhook_url']);

        if ($status !== 200) {
            return ['ok' => false, 'error' => 'Discord rejected the webhook (HTTP ' . $status . ').'];
        }

        $data = json_decode($body, true);
        $name = (string) ($data['name'] ?? 'webhook');

        return ['ok' => true, 'note' => $name];
    }

    public function post(string $text, array $media = [], array $meta = []): array
    {
        $url = (string) ($this->config['webhook_url'] ?? '');
        if ($url === '') {
            return ['ok' => false, 'error' => 'No Discord webhook configured.'];
        }

        try {
            $multipart = ['content' => trim($text) !== '' ? $text : 'New upload'];

            $idx = 0;
            foreach (array_slice($media, 0, 10) as $m) {
                if (empty($m['tmp_name']) || !is_file($m['tmp_name'])) {
                    continue;
                }
                $multipart['file' . $idx] = [
                    'file' => $m['tmp_name'],
                    'name' => $m['name'] ?? basename($m['tmp_name']),
                    'type' => (string) ($m['type'] ?? mime_content_type($m['tmp_name']) ?: 'application/octet-stream'),
                ];
                $idx++;
            }

            [$status, , $body] = Http::request($url . '?wait=true', ['method' => 'POST', 'multipart' => $multipart]);

            if ($status !== 200 && $status !== 204) {
                return ['ok' => false, 'error' => 'Discord webhook post failed (HTTP ' . $status . ').'];
            }

            $data = json_decode($body, true);
            $channelId = (string) ($data['channel_id'] ?? '');
            $messageId = (string) ($data['id'] ?? '');

            return [
                'ok'  => true,
                'url' => ($channelId !== '' && $messageId !== '')
                    ? 'https://discord.com/channels/@me/' . $channelId . '/' . $messageId
                    : '',
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}