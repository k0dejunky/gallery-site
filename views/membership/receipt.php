<?php
$title = 'Receipt ' . ($receipt['receipt_no'] ?? '');
// One printable receipt. The refund form lives here rather than on the list:
// a member asks about ONE charge, and the page can explain what happens to
// that kind of charge (a one-off is refunded to the card; a membership term
// is refunded in the processor by the owner).
$receipt = $receipt ?? [];
$open    = $open ?? null;
?>
<div class="page-shell">
    <div class="section-head">
        <div>
            <p class="section-kicker">Receipt</p>
            <h1><?= e((string) ($receipt['receipt_no'] ?? '')) ?></h1>
        </div>
        <a class="btn btn-ghost" href="<?= e(url('/membership/receipts')) ?>">&larr; All receipts</a>
    </div>

    <div class="card">
        <p class="muted">Billed to</p>
        <p>
            <strong><?= e(trim(((string) ($user['billing_first_name'] ?? '')) . ' ' . ((string) ($user['billing_last_name'] ?? '')))) ?: e((string) ($user['email'] ?? '')) ?></strong><br>
            <?= e((string) ($user['email'] ?? '')) ?>
        </p>

        <table class="membership-history">
            <tbody>
                <tr><th>Receipt</th><td><code><?= e((string) ($receipt['receipt_no'] ?? '')) ?></code></td></tr>
                <tr><th>Date</th><td><?= !empty($receipt['paid_at']) ? e(tzdate('F j, Y', (string) $receipt['paid_at'])) : '&mdash;' ?></td></tr>
                <tr><th>What</th><td><?= e((string) ($receipt['description'] ?? '')) ?></td></tr>
                <?php if (!empty($receipt['periods_granted'])): ?>
                    <tr><th>Periods paid</th><td><?= (int) $receipt['periods_granted'] ?></td></tr>
                <?php endif; ?>
                <?php if (!empty($receipt['expires_at'])): ?>
                    <tr><th>Access through</th><td><?= e(tzdate('F j, Y', (string) $receipt['expires_at'])) ?></td></tr>
                <?php endif; ?>
                <tr><th>Paid with</th><td><?= e((string) ($receipt['gateway'] ?? '')) ?></td></tr>
                <tr><th>Status</th><td><?= e((string) ($receipt['status_label'] ?? '')) ?></td></tr>
                <tr><th>Amount</th><td><strong>$<?= number_format((float) ($receipt['amount'] ?? 0), 2) ?> <?= e((string) ($receipt['currency'] ?? 'USD')) ?></strong></td></tr>
            </tbody>
        </table>
    </div>

    <?php if ($open !== null): ?>
        <div class="card" id="refund">
            <h2>Refund requested</h2>
            <p>
                You asked for a refund on
                <strong><?= !empty($open['created_at']) ? e(tzdate('F j, Y', (string) $open['created_at'])) : 'this charge' ?></strong>.
                We will review it and reply by email.
            </p>
            <?php if (!empty($open['reason'])): ?>
                <p class="muted">Your reason: <?= e((string) $open['reason']) ?></p>
            <?php endif; ?>
            <form class="inline" method="post" action="<?= e(url('/membership/refund-request/withdraw')) ?>"
                  data-confirm="Withdraw this refund request?">
                <?= csrf_field() ?>
                <input type="hidden" name="request_id" value="<?= (int) $open['id'] ?>">
                <button type="submit" class="btn btn-sm btn-ghost">Withdraw request</button>
            </form>
        </div>
    <?php elseif (!empty($receipt['refundable'])): ?>
        <div class="card" id="refund">
            <h2>Ask for a refund</h2>
            <p class="muted"><?= e((string) ($receipt['refund_note'] ?? '')) ?></p>
            <form method="post" action="<?= e(url('/membership/refund-request')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="kind" value="<?= e((string) ($receipt['kind'] ?? '')) ?>">
                <input type="hidden" name="charge_id" value="<?= (int) ($receipt['id'] ?? 0) ?>">
                <p>
                    <label for="refund-reason">Why are you asking for a refund?</label>
                    <textarea id="refund-reason" name="reason" rows="4" maxlength="1000" required
                              placeholder="Tell us what went wrong so we can put it right."></textarea>
                </p>                <button type="submit" class="btn">Send refund request</button>
            </form>
        </div>
    <?php else: ?>
        <p class="muted">This charge is not in a state that can be refunded.</p>
    <?php endif; ?>
</div>
