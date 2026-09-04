<?php

/**
 * Apply the desired cron-job schedules as declared in
 * storage/cron/schedules.json to /etc/cron.d/ and restart the two
 * background worker services.
 *
 * This script is intentionally root-only and invoked through a scoped
 * sudoers rule (see docs/MIGRATION.md). It is NOT reachable from the web;
 * the SystemController never writes to /etc directly. It reads:
 *
 *   /var/www/gallery/storage/cron/schedules.json
 *   /var/www/gallery/.env   (for GALLERY_CRON_KEY)
 *
 * Usage (root):  php /var/www/gallery/bin/apply_cron.php
 * Exit code 0 on success, non-zero otherwise.
 */

declare(strict_types=1);

const SITE_ROOT = '/var/www/gallery';

$schedulesFile = SITE_ROOT . '/storage/cron/schedules.json';
$envFile       = SITE_ROOT . '/.env';

$ok  = static function (bool $b, string $msg): void {
    if (!$b) {
        fwrite(STDERR, "apply_cron: {$msg}\n");
        exit(1);
    }
};

// Must actually be running as root: this file edits /etc/cron.d.
$ok(\function_exists('posix_geteuid') ? posix_geteuid() === 0 : \file_exists('/etc/cron.d'),
    'apply_cron must run as root (via the scoped sudoers rule)');

// --- Load the desired schedules -------------------------------------------------
$ok(\is_file($schedulesFile), "schedules file missing: {$schedulesFile}");
$json = \json_decode((string) \file_get_contents($schedulesFile), true);
$ok(\is_array($json), 'schedules file is not valid JSON');

// --- Load GALLERY_CRON_KEY from .env -------------------------------------------
$key = '';
foreach (\file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    if (\preg_match('/^\s*GALLERY_CRON_KEY\s*=\s*(.+)$/', $line, $m)) {
        $key = \trim($m[1]);
        break;
    }
}
$ok($key !== '', 'GALLERY_CRON_KEY not found in .env');

// --- Validation helpers ---------------------------------------------------------
$clamp = static function (int $v, int $min, int $max): int {
    return \min($max, \max($min, $v));
};
$intOr = static function ($v, int $default): int {
    return \ctype_digit((string) ($v ?? '')) ? (int) $v : $default;
};
$everyMin = $clamp($intOr($json['housekeeping']['every_minutes'] ?? null, 15), 1, 1440);
$postMin  = $clamp($intOr($json['autopost'      ]['every_minutes'] ?? null, 1), 1, 1440);
$ppMin    = $clamp($intOr($json['paypal-reconcile']['every_minutes'] ?? null, 5), 1, 1440);
$backupH  = $clamp($intOr($json['backup']['hour']   ?? null, 3), 0, 23);
$backupM  = $clamp($intOr($json['backup']['minute'] ?? null, 0), 0, 59);
$drillD   = $clamp($intOr($json['restore-drill']['dow']      ?? null, 0), 0, 6);
$drillH   = $clamp($intOr($json['restore-drill']['hour']     ?? null, 4), 0, 23);
$drillM   = $clamp($intOr($json['restore-drill']['minute']   ?? null, 0), 0, 59);

// --- Render the five cron.d entries --------------------------------------------
$php      = 'www-data /usr/bin/php ' . SITE_ROOT;
$crond    = [];
$crond['gallery-housekeeping'] =
    "*/{$everyMin} * * * * www-data curl -fsS \"http://127.0.0.1/gallery/cron/housekeeping?key={$key}\" > /dev/null 2>&1\n";
$crond['gallery-paypal-reconcile'] =
    "*/{$ppMin} * * * * {$php}/bin/paypal_reconcile.php >> " . SITE_ROOT . "/storage/logs/paypal-reconcile.log 2>&1\n";
$crond['gallery-autopost'] =
    "*/{$postMin} * * * * {$php}/bin/autopost_worker.php --once >> " . SITE_ROOT . "/storage/logs/autopost.log 2>&1\n";
$crond['gallery-backup'] =
    "{$backupM} {$backupH} * * * {$php}/bin/gallery_backup.php >> " . SITE_ROOT . "/storage/logs/backup.log 2>&1\n";
$crond['gallery-restore-drill'] =
    "{$drillM} {$drillH} * * {$drillD} root /usr/local/bin/restore-drill >> " . SITE_ROOT . "/storage/logs/drill.log 2>&1\n";

// --- Write the files atomically ------------------------------------------------
foreach ($crond as $name => $content) {
    $path = '/etc/cron.d/' . $name;
    $tmp  = $path . '.new';
    $ok(@\file_put_contents($tmp, $content) !== false, "write failed: {$tmp}");
    $ok(@\chmod($tmp, 0644), "chmod failed: {$tmp}");
    $ok(@\rename($tmp, $path), "rename failed: {$tmp} -> {$path}");
}

// --- Restart the supervised worker services ------------------------------------
\system('/usr/bin/systemctl restart gallery-video-export gallery-photo-edit > /dev/null 2>&1', $svcRc);
$ok($svcRc === 0, 'systemctl restart of worker services failed');

// Cron daemon picks up /etc/cron.d changes automatically; nothing else to do.
echo "apply_cron: wrote 5 /etc/cron.d entries and restarted worker services\n";
exit(0);