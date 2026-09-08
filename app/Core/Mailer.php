<?php

namespace App\Core;

/**
 * SMTP mailer using the site's own mail server (STARTTLS + AUTH LOGIN). When
 * SMTP is not configured, it uses PHP's mail() when available; otherwise
 * messages are written to an outbox.
 *
 * adminAlert() adds per-key cooldown dedupe (storage/logs/alerts.state) so
 * recurring conditions (disk low, login spikes, cron stale) cannot flood
 * the inbox: the same alert key is only re-sent after its cooldown passed.
 */
class Mailer
{
    /**
     * Whether alerts are configured at all. Without ADMIN_EMAIL we still
     * write outbox files when mail() is unavailable, but throttled alerts
     * skip entirely to avoid pointless disk churn.
     */
    public static function adminEmail(): string
    {
        return trim((string) env_value('ADMIN_EMAIL', ''));
    }

    /**
     * Send a plain-text email. Returns true when handed to a real MTA,
     * false when it was only parked in the outbox directory.
     */
    public static function send(string $to, string $subject, string $body): bool
    {
        $to      = trim($to);
        $subject = trim($subject);

        if ($to === '' || $subject === '') {
            return false;
        }

        $headers = implode("\r\n", [
            'From: ' . self::from(),
            'X-Mailer: gallery-mvc',
            'Content-Type: text/plain; charset=UTF-8',
        ]);

        return self::deliver($to, $subject, $headers, $body);
    }

    /**
     * Send an HTML email as multipart/alternative (a plain-text part first,
     * then the HTML), so mail clients with image-blocking still read the
     * digest. A text fallback is derived from the HTML when none is given.
     */
    public static function sendHtml(string $to, string $subject, string $html, ?string $text = null): bool
    {
        $to      = trim($to);
        $subject = trim($subject);
        $html    = trim($html);

        if ($to === '' || $subject === '' || $html === '') {
            return false;
        }

        $text = ($text !== null && trim($text) !== '') ? $text : self::htmlToText($html);

        $boundary = '=_gallery_' . bin2hex(random_bytes(8));
        $headers = implode("\r\n", [
            'From: ' . self::from(),
            'X-Mailer: gallery-mvc',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ]);

        $body = '--' . $boundary . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . $text . "\r\n"
            . '--' . $boundary . "\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . $html . "\r\n"
            . '--' . $boundary . "--\r\n";

        return self::deliver($to, $subject, $headers, $body);
    }

    /**
     * Shared delivery chain: SMTP when configured, else PHP mail(), else the
     * outbox directory. Returns true when handed to a real MTA.
     */
    private static function deliver(string $to, string $subject, string $headers, string $body): bool
    {
        if (self::smtpConfigured() && self::sendSmtp($to, $subject, $headers, $body)) {
            return true;
        }

        if (!self::smtpConfigured() && function_exists('mail')) {
            $ok = @mail($to, $subject, $body, $headers);

            if ($ok) {
                return true;
            }
        }

        $dir = self::outboxDir();

        if ($dir !== '') {
            $raw = 'To: ' . $to . "\r\n"
                . 'Subject: ' . $subject . "\r\n"
                . $headers . "\r\n\r\n"
                . $body;

            @file_put_contents(
                $dir . '/' . date('Ymd-His') . '-' . preg_replace('/[^a-z0-9]+/i', '-', $subject === '' ? 'mail' : $subject) . '.eml',
                $raw
            );
        }

        return false;
    }

    /**
     * The configured from-address, defaulting to the legacy placeholder.
     */
    private static function from(): string
    {
        return (string) env_value('MAIL_FROM', 'gallery@localhost');
    }

    /**
     * Crude HTML-to-text conversion for the multipart text part: block tags
     * become newlines, link anchors keep their target in parentheses, and
     * leftover tags are stripped.
     */
    private static function htmlToText(string $html): string
    {
        $text = $html;

        $text = str_ireplace(
            ['</p>', '</div>', '</tr>', '</li>', '</h1>', '</h2>', '</h3>', '</h4>', '</h5>', '</h6>', '<br>', '<br/>', '<br />'],
            "\n",
            $text
        );
        $text = preg_replace('/<a\s[^>]*href="([^"]*)"[^>]*>(.*?)<\/a>/is', '$2 ($1)', $text) ?? $text;
        $text = preg_replace('/<[^>]+>/', '', $text) ?? $text;
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');

        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $out   = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '') {
                $out[] = $line;
            }
        }

        return implode("\n", $out);
    }

    private static function smtpConfigured(): bool
    {
        return env_value('MAIL_HOST', '') !== ''
            && env_value('MAIL_USERNAME', '') !== ''
            && env_value('MAIL_PASSWORD', '') !== '';
    }

    /**
     * Send one message through an SMTP server using STARTTLS and AUTH LOGIN.
     * Dovecot SASL on the site's own mail domain accepts mailbox credentials.
     */
    private static function sendSmtp(string $to, string $subject, string $headers, string $body): bool
    {
        $host = env_value('MAIL_HOST', 'amethyst2213.com');
        $port = (int) env_value('MAIL_PORT', '587');
        $user = env_value('MAIL_USERNAME', '');
        $pass = env_value('MAIL_PASSWORD', '');
        $from = self::from();
        $socket = @stream_socket_client(
            'tcp://' . $host . ':' . $port,
            $errno,
            $error,
            15,
            STREAM_CLIENT_CONNECT
        );

        if (!is_resource($socket)) {
            error_log('[MAIL] SMTP connection failed: ' . $error);
            return false;
        }

        stream_set_timeout($socket, 15);
        $ok = self::smtpRead($socket, 220)
            && self::smtpCommand($socket, 'EHLO gallery.local', 250)
            && self::smtpCommand($socket, 'STARTTLS', 220)
            && @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) === true
            && self::smtpCommand($socket, 'EHLO gallery.local', 250)
            && self::smtpCommand($socket, 'AUTH LOGIN', 334)
            && self::smtpCommand($socket, base64_encode($user), 334)
            && self::smtpCommand($socket, base64_encode($pass), 235)
            && self::smtpCommand($socket, 'MAIL FROM:<' . $from . '>', 250)
            && self::smtpCommand($socket, 'RCPT TO:<' . $to . '>', 250)
            && self::smtpCommand($socket, 'DATA', 354);

        if ($ok) {
            $message = 'To: ' . $to . "\r\n"
                . 'Subject: ' . self::encodeHeader($subject) . "\r\n"
                . $headers . "\r\n"
                . $body;
            $message = preg_replace('/^\./m', '..', $message) . "\r\n.";
            fwrite($socket, $message . "\r\n");
            $ok = self::smtpRead($socket, 250);
        }

        @fwrite($socket, "QUIT\r\n");
        @fclose($socket);

        return $ok;
    }

    private static function smtpCommand($socket, string $command, int $expected): bool
    {
        if (@fwrite($socket, $command . "\r\n") === false) {
            return false;
        }

        return self::smtpRead($socket, $expected);
    }

    private static function smtpRead($socket, int $expected): bool
    {
        $response = '';
        while (($line = @fgets($socket, 515)) !== false) {
            $response = $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }

        return (int) substr($response, 0, 3) === $expected;
    }

    private static function encodeHeader(string $value): string
    {
        return preg_match('/[^\x20-\x7E]/', $value)
            ? '=?UTF-8?B?' . base64_encode($value) . '?='
            : $value;
    }

    /**
     * Throttled admin notification. $key groups identical alerts; within
     * $cooldownSec seconds only the first occurrence is delivered.
     */
    public static function adminAlert(string $key, string $subject, string $body, int $cooldownSec = 1800): void
    {
        if (self::adminEmail() === '') {
            return;
        }

        $stateFile = self::stateFile();
        $state     = [];

        if (is_file($stateFile)) {
            $decoded = json_decode((string) @file_get_contents($stateFile), true);
            $state   = is_array($decoded) ? $decoded : [];
        }

        $now = time();
        $last = (int) ($state[$key] ?? 0);

        if ($now - $last < $cooldownSec) {
            return;
        }

        $state[$key] = $now;

        // Keep the state file small; drop entries older than 7 days.
        foreach ($state as $k => $ts) {
            if ($now - (int) $ts > 604800) {
                unset($state[$k]);
            }
        }

        @file_put_contents($stateFile, json_encode($state), LOCK_EX);
        self::send(self::adminEmail(), '[gallery] ' . $subject, $body);
    }

    private static function outboxDir(): string
    {
        $root = dirname(__DIR__, 2);
        $dir  = $root . '/storage/mail-outbox';

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return is_dir($dir) ? $dir : '';
    }

    private static function stateFile(): string
    {
        $root = dirname(__DIR__, 2);
        $dir  = $root . '/storage/logs';

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir . '/alerts.state';
    }
}
