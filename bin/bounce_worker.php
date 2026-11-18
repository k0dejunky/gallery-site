<?php

/**
 * Read the hard-bounce mailbox and suppress the addresses that failed.
 *
 * Member mail leaves with the site's From address, so a dead recipient used
 * to bounce into the admin inbox (147 "Undelivered Mail Returned to Sender"
 * messages by mid-November). Mailer now uses bounce@amethyst2213.com as the
 * ENVELOPE sender (MAIL_BOUNCE), so the delivery status notifications land in
 * their own mailbox; this worker reads it, records the permanent failures in
 * `email_suppressions`, and moves the processed messages out of `new/`.
 *
 * Only a permanent failure is recorded: a 4.x.x status is a full mailbox or a
 * greylist and the member still wants their mail.
 *
 * Usage (root; the mailbox is a 0700 vmail Maildir):
 *   php bin/bounce_worker.php [--once] [--limit=500] [--dry-run]
 *
 * Cron: /etc/cron.d/gallery-bounces (written by bin/apply_cron.php).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('SITE_ROOT', dirname(__DIR__));

require SITE_ROOT . '/app/bootstrap.php';

use App\Models\EmailSuppression;

$dryRun = in_array('--dry-run', $argv, true);
$limit  = 500;
foreach ($argv as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = max(1, min(5000, (int) $m[1]));
    }
}

$maildir = rtrim((string) env_value('BOUNCE_MAILDIR', '/var/mail/vhosts/amethyst2213.com/bounce'), '/');
$newDir  = $maildir . '/new';
$curDir  = $maildir . '/cur';
$lockDir = SITE_ROOT . '/storage/logs';

$stamp = static fn (): string => date('Y-m-d H:i:s');

if (!is_dir($newDir)) {
    fwrite(STDOUT, $stamp() . " | no bounce mailbox at {$newDir} (nothing to do)\n");
    exit(0);
}

if (!is_dir($lockDir)) {
    @mkdir($lockDir, 0775, true);
}

$lockFile = $lockDir . '/bounce.lock';
$lock = @fopen($lockFile, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDOUT, $stamp() . " | another bounce run holds the lock\n");
    exit(0);
}

$files = glob($newDir . '/*') ?: [];
sort($files);
$files = array_slice($files, 0, $limit);

$scanned    = 0;
$suppressed = 0;
$soft       = 0;
$moved      = 0;

/**
 * Pull the failed recipients out of a delivery status notification.
 *
 * Postfix (and most MTAs) put one block per recipient in the
 * message/delivery-status part:
 *
 *   Final-Recipient: rfc822; user@example.com
 *   Action: failed
 *   Status: 5.1.1
 *   Diagnostic-Code: smtp; 550 5.1.1 ... user unknown
 *
 * @return array<int, array{email: string, status: string, action: string, diag: string}>
 */
$parseDsns = static function (string $raw): array {
    $out = [];

    if (preg_match_all(
        '/Final-Recipient:\s*(?:rfc822;\s*)?([^\s;<>]+)(.{0,900}?)(?=Final-Recipient:|$)/is',
        $raw,
        $blocks,
        PREG_SET_ORDER
    )) {
        foreach ($blocks as $block) {
            $email  = trim((string) $block[1], "<> \t\r\n");
            $rest   = (string) $block[2];
            $status = preg_match('/Status:\s*([245]\.\d{1,3}\.\d{1,3})/i', $rest, $sm) ? $sm[1] : '';
            $action = preg_match('/Action:\s*([a-z]+)/i', $rest, $am) ? strtolower($am[1]) : '';
            $diag   = preg_match('/Diagnostic-Code:\s*([^\r\n]+)/i', $rest, $dm) ? trim($dm[1]) : '';

            if ($email !== '') {
                $out[] = ['email' => $email, 'status' => $status, 'action' => $action, 'diag' => $diag];
            }
        }
    }

    // Some MTAs only expose the failed recipients in this header.
    if ($out === [] && preg_match('/^X-Failed-Recipients:\s*(.+)$/mi', $raw, $m)) {
        foreach (preg_split('/[,\s]+/', (string) $m[1]) ?: [] as $email) {
            $email = trim($email, "<> \t\r\n");
            if ($email !== '') {
                $out[] = ['email' => $email, 'status' => '', 'action' => 'failed', 'diag' => 'X-Failed-Recipients'];
            }
        }
    }

    return $out;
};

/** A permanent failure, as opposed to a full mailbox or a greylist. */
$isHard = static function (array $recipient): bool {
    $status = (string) ($recipient['status'] ?? '');
    $action = (string) ($recipient['action'] ?? '');
    $diag   = (string) ($recipient['diag'] ?? '');

    if ($status !== '') {
        return str_starts_with($status, '5.');
    }

    if ($action !== '' && $action !== 'failed') {
        return false;
    }

    return (bool) preg_match(
        '/\b(550|551|553|user unknown|unknown user|does not exist|no such user|no such recipient|recipient address rejected|mailbox unavailable|address rejected)\b/i',
        $diag
    );
};

foreach ($files as $file) {
    if (!is_file($file)) {
        continue;
    }

    $raw = (string) @file_get_contents($file);
    if ($raw === '') {
        continue;
    }

    $scanned++;
    $recipients = $parseDsns($raw);

    foreach ($recipients as $recipient) {
        $email = (string) $recipient['email'];

        if (!$isHard($recipient)) {
            $soft++;
            fwrite(STDOUT, $stamp() . " | soft bounce, kept: {$email} (" . ($recipient['status'] ?: 'no status') . ")\n");
            continue;
        }

        $detail = trim(($recipient['status'] !== '' ? $recipient['status'] . ' ' : '') . $recipient['diag']);

        if ($dryRun) {
            fwrite(STDOUT, $stamp() . " | would suppress: {$email} ({$detail})\n");
            $suppressed++;
            continue;
        }

        if (EmailSuppression::suppress($email, 'hard bounce', 'dsn', $detail)) {
            $suppressed++;
            fwrite(STDOUT, $stamp() . " | suppressed: {$email} ({$detail})\n");
        }
    }

    if ($dryRun) {
        continue;
    }

    // Move the processed message out of new/ so the next run does not read it
    // again. It stays in the mailbox (cur/) so the operator can inspect it in
    // the bounce account.
    if (!is_dir($curDir)) {
        @mkdir($curDir, 0700, true);
    }

    $name = basename($file);
    $target = $curDir . '/' . $name;
    if (!str_contains($name, ':2,')) {
        $target .= ':2,S';
    }

    if (@rename($file, $target)) {
        $moved++;
    } else {
        fwrite(STDOUT, $stamp() . " | could not move {$name} to cur/ (it will be read again)\n");
    }
}

flock($lock, LOCK_UN);
fclose($lock);

fwrite(STDOUT, sprintf(
    "%s | done: scanned=%d suppressed=%d soft=%d moved=%d%s\n",
    $stamp(),
    $scanned,
    $suppressed,
    $soft,
    $moved,
    $dryRun ? ' (dry run)' : ''
));
exit(0);
