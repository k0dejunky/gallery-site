<?php

declare(strict_types=1);

/**
 * PayPal subscription reconciler: asks PayPal for the status of every
 * pending PAYPAL-* subscription and, when PayPal reports it ACTIVE/APPROVED,
 * auto-activates the local membership (verified path — a webhook was likely
 * missed). Runs from /etc/cron.d/gallery-paypal-reconcile and can also be
 * triggered from the admin System / Subscriptions pages.
 *
 * A lock file prevents overlapping runs. Exits 0 on success, non-zero if the
 * reconcile itself failed to run.
 */

require __DIR__ . '/../app/Core/helpers.php';
spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $path = __DIR__ . '/../app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

use App\Core\Housekeeping;

$lockFile = dirname(__DIR__) . '/storage/logs/paypal-reconcile.lock';
$lockFp   = @fopen($lockFile, 'c');

if ($lockFp === false) {
    fwrite(STDERR, "paypal-reconcile: cannot open lock file {$lockFile}\n");
    exit(1);
}

if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "paypal-reconcile: another run is in progress\n");
    exit(0);
}

$summary = Housekeeping::reconcilePayPalSubscriptions();

flock($lockFp, LOCK_UN);
fclose($lockFp);

$line = sprintf(
    '%s | paypal_reconciled activated=%d closed=%d skipped=%d errors=%d',
    date('Y-m-d H:i:s'),
    (int) $summary['activated'],
    (int) $summary['closed'],
    (int) $summary['skipped'],
    (int) $summary['errors']
);

@file_put_contents(dirname(__DIR__) . '/storage/logs/paypal-reconcile.log', $line . "\n", FILE_APPEND);

exit(0);