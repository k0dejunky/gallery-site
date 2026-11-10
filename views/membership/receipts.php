<?php
$title = 'Receipts';
// Everything the site has charged this member, with a stable invoice number.
// A receipt is a VIEW over subscriptions + purchases, so it can never
// disagree with the money. The refund form asks about ONE charge at a time.
$receipts  = $receipts ?? [];
$requests  = $requests ?? [];
$totalPaid = $totalPaid ?? 0.0;
?>
<div class="page-shell">
    <div class="section-head">
        <div>
            <p class="section-kicker">Billing</p>
            <h1>Receipts</h1>
        </div>
        <a class="btn btn-ghost" href="<?= e(url('/membership/my')) ?>">&larr; My membership</a>
    </div>

    <p class="muted">
        Every charge on your account, newest first. The receipt number is the
        reference to quote if you contact us about a payment.
    </p>

    <div class="stat-chip">
        <span>Total paid</span>
        <strong>$<?= number_format((float) $totalPaid, 2) ?></strong>
    </div>

    <?php if ($receipts === []): ?>
        <div class="empty-state">
            <p class="muted">You have not been charged yet.</p>
            <a class="btn btn-sm" href="<?= e(url('/membership')) ?>">View membership plans</a>
        </div>
    <?php else: ?>
        <div class="table-scroll">
            <table class="membership-history">
                <thead>
                    <tr>
                        <th>Receipt</th>
                        <th>Date</th>
                        <th>What</th>
                        <th>Paid with</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($receipts as $receipt): ?>
                        <?php
                        $kind   = (string) $receipt['kind'];
                        $charge = (int) $receipt['id'];
                        $open   = \App\Models\RefundRequest::openFor($requests, $kind, $charge);
                        $url    = url('/membership/receipts/' . $kind . '/' . $charge);
                        ?>
                        <tr>
                            <td><a href="<?= e($url) ?>"><code><?= e((string) $receipt['receipt_no']) ?></code></a></td>
                            <td><?= !empty($receipt['paid_at']) ? e(tzdate('Y-m-d', (string) $receipt['paid_at'])) : '&mdash;' ?></td>
                            <td>
                                <?= e((string) $receipt['description']) ?>
                                <?php if ((int) ($receipt['periods_granted'] ?? 0) === 0): ?>
                                    <span class="muted"><?= e((string) $receipt['detail']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?= e((string) $receipt['gateway']) ?></td>
                            <td>$<?= number_format((float) $receipt['amount'], 2) ?> <?= e((string) $receipt['currency']) ?></td>
                            <td><span class="status-badge <?= e((string) $receipt['status']) ?>"><?= e((string) $receipt['status_label']) ?></span></td>
                            <td>
                                <?php if ($open !== null): ?>
                                    <span class="pill pill-soft">Refund requested</span>
                                <?php elseif (!empty($receipt['refundable'])): ?>
                                    <a class="btn btn-sm btn-ghost" href="<?= e($url) ?>#refund">Request a refund</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if ($requests !== []): ?>
        <h2>Refund requests</h2>
        <div class="table-scroll">
            <table class="membership-history">
                <thead>
                    <tr>
                        <th>Opened</th>
                        <th>Charge</th>
                        <th>Status</th>
                        <th>Our note</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($requests as $request): ?>
                        <?php
                        $chargeKind = (int) ($request['purchase_id'] ?? 0) > 0
                            ? \App\Models\Receipt::KIND_PURCHASE
                            : \App\Models\Receipt::KIND_SUBSCRIPTION;
                        $chargeId   = (int) ($request['purchase_id'] ?? 0) > 0
                            ? (int) $request['purchase_id']
                            : (int) ($request['subscription_id'] ?? 0);
                        $chargeNo   = $chargeKind === \App\Models\Receipt::KIND_PURCHASE
                            ? \App\Models\Receipt::purchaseNumber($chargeId)
                            : \App\Models\Receipt::subscriptionNumber($chargeId);
                        ?>
                        <tr>
                            <td><?= !empty($request['created_at']) ? e(tzdate('Y-m-d', (string) $request['created_at'])) : '&mdash;' ?></td>
                            <td><a href="<?= e(url('/membership/receipts/' . $chargeKind . '/' . $chargeId)) ?>"><code><?= e($chargeNo) ?></code></a></td>
                            <td>
                                <span class="status-badge <?= e((string) $request['status']) ?>">
                                    <?= e(\App\Models\RefundRequest::statusLabel((string) $request['status'])) ?>
                                </span>
                            </td>
                            <td><?= !empty($request['admin_note']) ? e((string) $request['admin_note']) : '&mdash;' ?></td>
                            <td>
                                <?php if ((string) $request['status'] === \App\Models\RefundRequest::STATUS_OPEN): ?>
                                    <form class="inline" method="post" action="<?= e(url('/membership/refund-request/withdraw')) ?>"
                                          data-confirm="Withdraw this refund request?">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-ghost">Withdraw</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
