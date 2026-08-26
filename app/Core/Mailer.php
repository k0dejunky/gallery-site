<?php

namespace App\Core;

/**
 * SMTP mailer with Gmail support. When SMTP is not configured, it uses PHP's
 * mail() when available; otherwise messages are written to an outbox.
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

        $from = (string) env_value('MAIL_FROM', 'gallery@localhost');
        $headers = implode("\r\n", [
            'From: ' . $from,
            'X-Mailer: gallery-mvc',
            'Content-Type: text/plain; charset=UTF-8',
        ]);
        $raw = 'To: ' . $to . "\r\n" . 'Subject: ' . $subject . "\r\n" . $headers . "\r\n\r\n" . $body;

        if (self::smtpConfigured() && self::sendSmtp($to, $subject, $body, $from)) {
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
            @file_put_contents(
                $dir . '/' . date('Ymd-His') . '-' . preg_replace('/[^a-z0-9]+/i', '-', $subject === '' ? 'mail' : $subject) . '.eml',
                $raw
            );
        }

        return false;
    }

    private static function smtpConfigured(): bool
    {
        return env_value('MAIL_HOST', '') !== ''
            && env_value('MAIL_USERNAME', '') !== ''
            && env_value('MAIL_PASSWORD', '') !== '';
    }

    /**
     * Send one message through an SMTP server using STARTTLS and AUTH LOGIN.
     * Gmail accepts this with an App Password when 2-Step Verification is on.
     */
    private static function sendSmtp(string $to, string $subject, string $body, string $from): bool
    {
        $host = env_value('MAIL_HOST', 'smtp.gmail.com');
        $port = (int) env_value('MAIL_PORT', '587');
        $user = env_value('MAIL_USERNAME', '');
        $pass = env_value('MAIL_PASSWORD', '');
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
                . 'From: ' . $from . "\r\n"
                . 'Subject: ' . self::encodeHeader($subject) . "\r\n"
                . "MIME-Version: 1.0\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\n\r\n"
                . preg_replace('/\r?\n/', "\r\n", $body);
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
