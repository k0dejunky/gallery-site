<?php
/**
 * Backfill the subscription_charges ledger from the subscription rows.
 *
 * Revenue used to be derived from the subscriptions table, so only each
 * subscription's FIRST payment was ever recorded anywhere. The ledger starts
 * empty, and without a backfill every past month would read $0 until new
 * money arrived.
 *
 * What it can prove:
 *   - the initial charge: price_paid, dated COALESCE(starts_at, created_at),
 *     referenced by transaction_ref (or manual:<id>);
 *   - a renewal: only when last_payment_ref carries the payment date
 *     (paypal:<id>:<ISO date>, written by the reconciler). A bare payment id
 *     is the payment that produced the initial charge, so counting it again
 *     would double the revenue.
 *
 * Trials and pending rows are skipped, and a $0 row records nothing (no
 * money moved). Re-running is safe: the unique key (subscription_id,
 * reference) makes every row idempotent.
 *
 * Usage: php bin/backfill_subscription_charges.php [--dry-run] [--yes]
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\Subscription;

$dryRun = in_array('--dry-run', $argv, true);
$yes    = in_array('--yes', $argv, true);

// Real member accounts only: a staff comp carries the plan price in
// price_paid but no money ever moved, and it is excluded from every revenue
// figure by role. Do not put it in the ledger.
$rows = Database::run(
    "SELECT s.*, p.price AS plan_price, p.name AS plan_name
     FROM subscriptions s
     JOIN plans p ON p.id = s.plan_id
     JOIN users u ON u.id = s.user_id
     WHERE s.status <> 'pending'
       AND u.role = 'user'
       AND " . Subscription::notTrialSql() . "
       AND " . Subscription::notPendingSql() . "
     ORDER BY s.id"
)->fetchAll();

$planned  = [];
$warnings = [];

foreach ($rows as $row) {
    $amount = Subscription::paidAmount($row, (float) ($row['plan_price'] ?? 0));
    if ($amount <= 0) {
        continue;
    }

    $paidAt = (string) (($row['starts_at'] ?? null) ?: ($row['created_at'] ?? ''));
    $ref    = trim((string) ($row['transaction_ref'] ?? ''));
    if ($ref === '' || str_starts_with($ref, 'PENDING-')) {
        $ref = 'manual:' . (int) $row['id'];
    }

    $planned[] = [
        'subscription_id' => (int) $row['id'],
        'user_id'         => (int) $row['user_id'],
        'plan'            => (string) $row['plan_name'],
        'amount'          => $amount,
        'gateway'         => Subscription::gatewayFrom($ref),
        'reference'       => $ref,
        'kind'            => 'initial',
        'paid_at'         => $paidAt,
    ];

    // The last payment is a renewal only when its reference carries the date
    // the gateway took it. A bare id is the initial payment.
    $last           = trim((string) ($row['last_payment_ref'] ?? ''));
    $datedRenewal   = false;

    if ($last !== '' && preg_match('/:(\d{4}-\d{2}-\d{2}T[\d:]+Z)\z/', $last, $m) === 1) {
        $paidTs = strtotime($m[1]);
        $initTs = strtotime($paidAt);

        if ($paidTs !== false && $initTs !== false && $paidTs > $initTs + 5 * 86400) {
            $planned[] = [
                'subscription_id' => (int) $row['id'],
                'user_id'         => (int) $row['user_id'],
                'plan'            => (string) $row['plan_name'],
                'amount'          => $amount,
                'gateway'         => Subscription::gatewayFrom($last),
                'reference'       => $last,
                'kind'            => 'renewal',
                'paid_at'         => date('Y-m-d H:i:s', $paidTs),
            ];
            $datedRenewal = true;
        }
    }

    if (!$datedRenewal && (int) ($row['periods_granted'] ?? 0) >= 2) {
        $warnings[] = 'subscription #' . (int) $row['id'] . ' granted '
            . (int) $row['periods_granted'] . ' period(s), but only the last payment is dated - '
            . 'earlier renewals cannot be reconstructed from the subscription row.';
    }
}

$byMonth = [];
$total   = 0.0;
foreach ($planned as $charge) {
    $month = substr((string) $charge['paid_at'], 0, 7);
    $byMonth[$month] = ($byMonth[$month] ?? 0.0) + (float) $charge['amount'];
    $total += (float) $charge['amount'];
}
ksort($byMonth);

printf("%d charge(s) to backfill, $%.2f total\n", count($planned), $total);

foreach ($byMonth as $month => $sum) {
    printf("  %s  $%8.2f\n", $month, $sum);
}

if ($planned !== []) {
    echo "\n";
    foreach ($planned as $charge) {
        printf(
            "  sub #%-5d user %-5d %-12s $%7.2f  %-7s  %s  %s\n",
            $charge['subscription_id'],
            $charge['user_id'],
            $charge['plan'],
            $charge['amount'],
            $charge['kind'],
            $charge['paid_at'],
            $charge['reference']
        );
    }
}

foreach ($warnings as $warning) {
    echo "\nWARNING: " . $warning . "\n";
}

if ($dryRun) {
    echo "\ndry run - nothing was written\n";
    exit(0);
}

if (!$yes) {
    echo "\npass --yes to write the charges\n";
    exit(0);
}

$inserted = 0;
$skipped  = 0;

foreach ($planned as $charge) {
    if (Subscription::recordCharge(
        $charge['subscription_id'],
        (float) $charge['amount'],
        $charge['gateway'],
        (string) $charge['reference'],
        (string) $charge['kind'],
        (string) $charge['paid_at']
    )) {
        $inserted++;
    } else {
        $skipped++;
    }
}

printf("\ninserted %d charge(s), skipped %d already-present charge(s)\n", $inserted, $skipped);
exit(0);
