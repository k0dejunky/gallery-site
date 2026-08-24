<?php

namespace App\Core;

/**
 * Minimal admin mailer. Uses PHP's mail() when the platform provides one;
 * otherwise every message is written as an .eml file into
 * storage/mail-outbox so nothing is silently lost on hosts without MTA.
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

        if (function_exists('mail')) {
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
