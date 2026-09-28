<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Telegram Bot API client for the Auto Poster. Posts text, photos or videos to
 * a channel/group via the official Bot API using a bot token (no OAuth).
 */
class TelegramClient
{
    private const API = 'https://api.telegram.org';

    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function isConfigured(): bool
    {
        return trim((string) ($this->config['bot_token'] ?? '')) !== ''
            && trim((string) ($this->config['chat_id'] ?? '')) !== '';
    }

    public function isUserAuthorized(): bool
    {
        return $this->isConfigured();
    }

    public function ping(): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'Bot token or chat id missing.'];
        }

        [$status, , $body] = Http::request($this->url('getMe'));

        if ($status !== 200) {
            return ['ok' => false, 'error' => 'Telegram rejected the token (HTTP ' . $status . ').'];
        }

        $data = json_decode($body, true);
        $user = $data['result'] ?? null;

        return $user ? ['ok' => true, 'note' => 'bot @' . ($user['username'] ?? 'unknown')] : ['ok' => false, 'error' => 'Unexpected getMe response.'];
    }

    public function post(string $text, array $media = [], array $meta = []): array
    {
        $chatId = (string) ($meta['chat_id'] ?? $this->config['chat_id'] ?? '');
        if ($chatId === '') {
            return ['ok' => false, 'error' => 'No Telegram chat id configured.'];
        }

        $text = trim($text);
        $files = [];

        foreach ($media as $m) {
            if (!empty($m['tmp_name']) && is_file($m['tmp_name'])) {
                $files[] = $m;
            }
        }

        try {
            if ($files === []) {
                $result = $this->sendMessage($chatId, $text);
            } elseif (count($files) === 1) {
                $result = $this->sendOne($chatId, $text, $files[0]);
            } else {
                $result = $this->sendGroup($chatId, $text, $files);
            }
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        return $result;
    }

    private function sendMessage(string $chatId, string $text): array
    {
        [$status, , $body] = Http::request($this->url('sendMessage'), [
            'json' => [
                'chat_id'                => $chatId,
                'text'                   => $text !== '' ? $text : 'New upload',
                'parse_mode'             => 'HTML',
                'disable_web_page_preview' => true,
            ],
        ]);

        return $this->interpret($status, $body);
    }

    private function sendOne(string $chatId, string $text, array $file): array
    {
        $mime = (string) ($file['type'] ?? mime_content_type($file['tmp_name']) ?: '');
        $isVideo = strpos($mime, 'video') !== false;
        $method  = $isVideo ? 'sendVideo' : 'sendPhoto';

        $multipart = [
            'chat_id' => $chatId,
        ];
        if ($text !== '') {
            $multipart['caption'] = $text;
            $multipart['parse_mode'] = 'HTML';
        }
        $multipart[$isVideo ? 'video' : 'photo'] = [
            'file' => $file['tmp_name'],
            'name' => $file['name'] ?? basename($file['tmp_name']),
            'type' => $mime !== '' ? $mime : 'application/octet-stream',
        ];

        [$status, , $body] = Http::request($this->url($method), ['multipart' => $multipart]);

        return $this->interpret($status, $body);
    }

    private function sendGroup(string $chatId, string $text, array $files): array
    {
        // Telegram media groups: each entry is inputMediaPhoto (images). Video
        // mixed into a group requires inputMediaVideo; images-only groups use
        // inputMediaPhoto. We cap at 10 and pass the caption on the first item.
        $items = [];
        $first = true;
        foreach (array_slice($files, 0, 10) as $file) {
            $mime = (string) ($file['type'] ?? mime_content_type($file['tmp_name']) ?: '');
            $isVideo = strpos($mime, 'video') !== false;
            $type = $isVideo ? 'video' : 'photo';

            $item = ['type' => $type, 'media' => 'attach://file' . count($items)];
            if ($first && $text !== '') {
                $item['caption'] = $text;
                $item['parse_mode'] = 'HTML';
            }
            $items[] = $item;
            $first = false;
        }

        $multipart = ['chat_id' => $chatId, 'media' => json_encode($items)];
        $idx = 0;
        foreach (array_slice($files, 0, 10) as $file) {
            $mime = (string) ($file['type'] ?? mime_content_type($file['tmp_name']) ?: '');
            $multipart['file' . $idx] = [
                'file' => $file['tmp_name'],
                'name' => $file['name'] ?? basename($file['tmp_name']),
                'type' => $mime !== '' ? $mime : 'application/octet-stream',
            ];
            $idx++;
        }

        [$status, , $body] = Http::request($this->url('sendMediaGroup'), ['multipart' => $multipart]);

        return $this->interpret($status, $body);
    }

    private function interpret(int $status, string $body): array
    {
        $data = json_decode($body, true);

        if ($status !== 200 || !($data['ok'] ?? false)) {
            $desc = (string) ($data['description'] ?? $data['error'] ?? 'Telegram error');
            return ['ok' => false, 'error' => 'Telegram: ' . $desc . ' (HTTP ' . $status . ')'];
        }

        $result = $data['result'] ?? [];
        $chat   = is_array($result) && isset($result['chat']) ? $result['chat'] : [];
        $mid    = (int) ($result['message_id'] ?? 0);
        $chatId = (string) ($chat['id'] ?? '');

        $url = '';
        if ($mid > 0) {
            if (isset($chat['username'])) {
                $url = 'https://t.me/' . $chat['username'] . '/' . $mid;
            } elseif ($chatId !== '') {
                $url = 'https://t.me/c/' . ltrim($chatId, '-') . '/' . $mid;
            }
        }

        return ['ok' => true, 'url' => $url];
    }

    private function url(string $method): string
    {
        return self::API . '/bot' . urlencode((string) ($this->config['bot_token'] ?? '')) . '/' . $method;
    }
}