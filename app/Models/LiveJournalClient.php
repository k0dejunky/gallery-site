<?php

declare(strict_types=1);

namespace App\Models;

/**
 * LiveJournal XML-RPC client for the Auto Poster. Posts a journal entry via
 * the LJ XML-RPC interface (mode postevent). CAUTION: LiveJournal's ToS
 * restricts "unsolicited advertising" — use for genuine journal posts.
 */
class LiveJournalClient
{
    private const XMLRPC = 'https://www.livejournal.com/interface/xmlrpc';

    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function isConfigured(): bool
    {
        return trim((string) ($this->config['username'] ?? '')) !== ''
            && trim((string) ($this->config['password'] ?? '')) !== '';
    }

    public function isUserAuthorized(): bool
    {
        return $this->isConfigured();
    }

    public function ping(): array
    {
        [$status, , $body] = Http::request(self::XMLRPC, [
            'method' => 'POST',
            'headers' => ['Content-Type' => 'text/xml'],
            'body' => $this->xml('LJ.XMLRPC.login', [
                'username' => (string) $this->config['username'],
                'password' => md5((string) $this->config['password']),
                'ver'      => 1,
            ]),
        ]);

        if ($status !== 200) {
            return ['ok' => false, 'error' => 'LiveJournal login failed (HTTP ' . $status . ').'];
        }

        return strpos($body, '<fault>') === false
            ? ['ok' => true, 'note' => $this->config['username']]
            : ['ok' => false, 'error' => 'LiveJournal rejected the credentials.'];
    }

    public function post(string $text, array $media = [], array $meta = []): array
    {
        $sensitive = (bool) ($meta['sensitive'] ?? false);

        try {
            [$status, , $body] = Http::request(self::XMLRPC, [
                'method' => 'POST',
                'headers' => ['Content-Type' => 'text/xml'],
                'body' => $this->xml('LJ.XMLRPC.postevent', [
                    'username'      => (string) $this->config['username'],
                    'password'      => md5((string) $this->config['password']),
                    'ver'           => 1,
                    'event'         => trim($text),
                    'subject'       => (string) ($meta['title'] ?? 'New upload'),
                    'security'      => 'public',
                    'props'         => $sensitive ? ['adult_content' => '1'] : [],
                    'lineendings'   => 'unix',
                ]),
            ]);

            if ($status !== 200) {
                return ['ok' => false, 'error' => 'LiveJournal post failed (HTTP ' . $status . ').'];
            }

            if (strpos($body, '<fault>') !== false) {
                return ['ok' => false, 'error' => 'LiveJournal rejected the post.'];
            }

            $itemId = 0;
            if (preg_match('#<name>itemid</name><value><i4>(\d+)</i4></value>#', $body, $m)) {
                $itemId = (int) $m[1];
            }

            return ['ok' => true, 'url' => 'https://' . $this->config['username'] . '.livejournal.com/' . $itemId . '.html'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function xml(string $method, array $params): string
    {
        $out = '<?xml version="1.0"?><methodCall><methodName>' . $method . '</methodName><params>';
        foreach ($params as $name => $value) {
            $out .= '<param><value><struct>';
            $out .= '<member><name>name</name><value><string>' . htmlspecialchars((string) $name) . '</string></value></member>';
            $out .= '<member><name>value</name><value>' . $this->valueXml($value) . '</value></member>';
            $out .= '</struct></value></param>';
        }
        $out .= '</params></methodCall>';

        return $out;
    }

    private function valueXml($value): string
    {
        if (is_array($value)) {
            $out = '<struct>';
            foreach ($value as $k => $v) {
                $out .= '<member><name>' . htmlspecialchars((string) $k) . '</name><value>' . $this->valueXml($v) . '</value></member>';
            }
            return $out . '</struct>';
        }
        if (is_int($value)) {
            return '<i4>' . $value . '</i4>';
        }
        return '<string>' . htmlspecialchars((string) $value) . '</string>';
    }
}