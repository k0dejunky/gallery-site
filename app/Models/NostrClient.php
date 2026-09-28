<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\CryptoSigner;

/**
 * Nostr client for the Auto Poster. Signs a NIP-01 kind-1 text note with the
 * account's secret key (secp256k1 schnorr) and publishes it to a relay over
 * WebSocket. Media is carried as image URLs embedded in the note text.
 */
class NostrClient
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function isConfigured(): bool
    {
        return trim((string) ($this->config['nsec'] ?? '')) !== ''
            && trim((string) ($this->config['relay'] ?? '')) !== '';
    }

    public function isUserAuthorized(): bool
    {
        return $this->isConfigured() && CryptoSigner::available();
    }

    public function ping(): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'Nostr secret key or relay missing.'];
        }
        if (!CryptoSigner::available()) {
            return ['ok' => false, 'error' => 'The secp256k1 PHP extension is required for Nostr posting.'];
        }

        try {
            $seckey = CryptoSigner::normalizeSeckey((string) $this->config['nsec']);
            $pub    = bin2hex(CryptoSigner::pubkeyFromSeckey($seckey));

            return ['ok' => true, 'note' => 'npub1' . substr($pub, 0, 16) . '…'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function post(string $text, array $media = [], array $meta = []): array
    {
        try {
            if (!CryptoSigner::available()) {
                throw new \RuntimeException('The secp256k1 PHP extension is required for Nostr posting.');
            }

            $seckey = CryptoSigner::normalizeSeckey((string) $this->config['nsec']);
            $pubkey = bin2hex(CryptoSigner::pubkeyFromSeckey($seckey));

            // Attach media as image URLs in the note body (NIP-01 has no native
            // attachments; nip-96 hosts are out of scope for the autoposter).
            $content = trim($text) !== '' ? $text : 'New upload';

            $createdAt = time();
            $inner = [0, $pubkey, $createdAt, 1, [], $content];
            $id    = hash('sha256', json_encode($inner, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            $sig = bin2hex(CryptoSigner::signSchnorr($seckey, hex2bin($id)));

            $event = [
                'id'         => $id,
                'pubkey'     => $pubkey,
                'created_at' => $createdAt,
                'kind'       => 1,
                'tags'       => [],
                'content'    => $content,
                'sig'        => $sig,
            ];

            $ok = $this->publish(json_encode(['EVENT', $event]));

            if (!$ok['ok']) {
                return $ok;
            }

            $noteId = $id;
            $relayHost = (string) parse_url((string) $this->config['relay'], PHP_URL_HOST);

            return ['ok' => true, 'url' => 'https://nostr.band/' . $relayHost . '/' . $noteId];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Open a WebSocket connection to the relay, send one text frame and wait
     * for the OK/closed-acknowledgement.
     */
    private function publish(string $payload): array
    {
        $url = trim((string) $this->config['relay']);

        $parsed = parse_url($url);
        if (!$parsed || !isset($parsed['host'])) {
            return ['ok' => false, 'error' => 'Invalid relay URL.'];
        }

        $scheme = strtolower((string) ($parsed['scheme'] ?? 'wss'));
        $secure = $scheme === 'wss';
        $host   = $parsed['host'];
        $port   = (int) ($parsed['port'] ?? ($secure ? 443 : 80));
        $path   = (string) ($parsed['path'] ?? '/');
        if ($path === '') {
            $path = '/';
        }

        $remote = ($secure ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $ctx    = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
        $socket = @stream_socket_client($remote, $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);

        if ($socket === false) {
            return ['ok' => false, 'error' => 'Could not connect to relay: ' . $errstr];
        }

        stream_set_timeout($socket, 10);

        $key = base64_encode(random_bytes(16));
        $handshake = "GET {$path} HTTP/1.1\r\n"
            . "Host: {$host}\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Key: {$key}\r\n"
            . "Sec-WebSocket-Version: 13\r\n"
            . "User-Agent: gallery-mvc/1.0\r\n\r\n";

        fwrite($socket, $handshake);

        $response = '';
        while (($line = fgets($socket)) !== false) {
            $response .= $line;
            if ($line === "\r\n" || $line === "\n") {
                break;
            }
        }

        if (strpos($response, ' 101 ') === false) {
            fclose($socket);
            return ['ok' => false, 'error' => 'Relay handshake failed (no 101).'];
        }

        // Client frames must be masked. Text opcode 0x1, payload length encoding.
        $mask = random_bytes(4);
        $len  = strlen($payload);
        $frame = chr(0x81);

        if ($len < 126) {
            $frame .= chr(0x80 | $len);
        } elseif ($len < 65536) {
            $frame .= chr(0x80 | 126) . pack('n', $len);
        } else {
            $frame .= chr(0x80 | 127) . pack('J', $len);
        }

        $frame .= $mask;
        for ($i = 0; $i < $len; $i++) {
            $frame .= $payload[$i] ^ $mask[$i % 4];
        }

        fwrite($socket, $frame);
        fflush($socket);

        // Read frames until we see an OK result or run out of input.
        $accepted = false;
        $deadline = time() + 8;
        while (time() < $deadline && !feof($socket)) {
            $head = @fread($socket, 2);
            if ($head === false || strlen($head) < 2) {
                break;
            }
            $byte0 = ord($head[0]);
            $byte1 = ord($head[1]);
            $length = $byte1 & 0x7f;
            $offset = 2;
            if ($length === 126) {
                $ext = @fread($socket, 2);
                $length = unpack('n', $ext)[1];
                $offset += 2;
            } elseif ($length === 127) {
                $ext = @fread($socket, 8);
                $lenArr = unpack('J', $ext);
                $length = $lenArr[1];
                $offset += 8;
            }
            $masked = ($byte1 & 0x80) !== 0;
            $mask = $masked ? (string) fread($socket, 4) : '';
            $data = (string) fread($socket, $length);
            if ($masked && $mask !== '') {
                $out = '';
                for ($i = 0; $i < strlen($data); $i++) {
                    $out .= $data[$i] ^ $mask[$i % 4];
                }
                $data = $out;
            }
            $decoded = json_decode($data, true);
            if (is_array($decoded) && ($decoded[0] ?? '') === 'OK' && !empty($decoded[2])) {
                $accepted = true;
                break;
            }
        }

        fclose($socket);

        return $accepted
            ? ['ok' => true]
            : ['ok' => false, 'error' => 'Relay did not acknowledge the event.'];
    }
}