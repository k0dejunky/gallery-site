<?php $title = 'Purchases & tips'; ?>

<p class="muted">Every one-off sale recorded by the site: gallery unlocks and tips. Subscriptions are tracked separately under Membership.</p>

<p style="display:flex;gap:1rem;flex-wrap:wrap;margin:1rem 0;">
    <a class="btn btn-sm btn-outline" href="<?= url('/admin/engagement') ?>">&larr; Back to Engagement</a>
</p>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;margin-bottom:1.5rem;">
    <div style="border:1px solid var(--card-border,#ddd);border-radius:8px;padding:1rem;background:var(--card-bg,#fff);">
        <div class="muted">Unlock revenue</div>
        <div style="font-size:1.6rem;">$<?= e(number_format((float) $unlockTotal, 2)) ?></div>
    </div>
    <div style="border:1px solid var(--card-border,#ddd);border-radius:8px;padding:1rem;background:var(--card-bg,#fff);">
        <div class="muted">Tips recorded</div>
        <div style="font-size:1.6rem;">$<?= e(number_format((float) $tipsTotal, 2)) ?></div>
    </div>
</div>

<p class="muted" style="margin-top:-.75rem;">
    A <strong>disputed</strong> charge is a chargeback the processor has not decided yet: it is not counted
    as revenue and its unlock is closed until the dispute is won.
</p>

<?php
// A member can ask for their money back from their own receipts page. The
// request lands here, where the charge lives. An approval on a one-off charge
// refunds the money at the processor before it is marked approved; a
// membership term is refunded in the processor by the owner, because the site
// stores the subscription id rather than each monthly charge.
$refundRequests = $refundRequests ?? [];
$openRefunds = array_values(array_filter($refundRequests, static function (array $r): bool {
    return (string) ($r['status'] ?? '') === \App\Models\RefundRequest::STATUS_OPEN;
}));
?>
<div class="card" style="margin-bottom:1.5rem;">
    <h2 style="margin-top:0;">Refund requests <?php if ($openRefunds !== []): ?><span class="pill pill-accent"><?= count($openRefunds) ?> waiting</span><?php endif; ?></h2>
    <?php if ($refundRequests === []): ?>
        <?= admin_empty('dollar', 'No refund requests', "A member asks for one from their own receipts page; it appears here for a decision.") ?>
    <?php else: ?>
        <table style="width:100%;border-collapse:collapse;">
            <thead>
                <tr>
                    <th class="u-left-pad">Opened</th>
                    <th class="u-left-pad">Member</th>
                    <th class="u-left-pad">Charge</th>
                    <th class="u-left-pad">Reason</th>
                    <th class="u-left-pad">Status</th>
                    <th style="text-align:right;padding:.4rem .5rem;">Decision</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($refundRequests as $request): ?>
                    <?php
                    $purchaseId = (int) ($request['purchase_id'] ?? 0);
                    $subId      = (int) ($request['subscription_id'] ?? 0);
                    $chargeNo   = $purchaseId > 0
                        ? \App\Models\Receipt::purchaseNumber($purchaseId)
                        : \App\Models\Receipt::subscriptionNumber($subId);
                    $amount     = $purchaseId > 0
                        ? (float) ($request['purchase_amount'] ?? 0)
                        : (float) ($request['sub_amount'] ?? 0);
                    $what       = $purchaseId > 0
                        ? ucfirst((string) ($request['purchase_type'] ?? 'purchase'))
                        : ('Membership' . (!empty($request['plan_name']) ? ' — ' . $request['plan_name'] : ''));
                    $isOpen     = (string) ($request['status'] ?? '') === \App\Models\RefundRequest::STATUS_OPEN;
                    ?>
                    <tr>
                        <td class="muted u-pad-04-05" style="white-space:nowrap;"><?= e(tzdate('Y-m-d H:i', (string) $request['created_at'])) ?></td>
                        <td class="u-pad-04-05"><?= e((string) $request['user_email']) ?></td>
                        <td class="u-pad-04-05">
                            <code><?= e($chargeNo) ?></code><br>
                            <span class="muted"><?= e($what) ?> — $<?= e(number_format($amount, 2)) ?></span>
                        </td>
                        <td class="u-pad-04-05"><?= nl2br(e(mb_substr((string) $request['reason'], 0, 300))) ?></td>
                        <td class="u-pad-04-05">
                            <?= e(\App\Models\RefundRequest::statusLabel((string) $request['status'])) ?>
                            <?php if (!empty($request['admin_note'])): ?>
                                <br><span class="muted"><?= e(mb_substr((string) $request['admin_note'], 0, 120)) ?></span>
                            <?php endif; ?>
                        </td>
                        <td style="padding:.4rem .5rem;text-align:right;">
                            <?php if ($isOpen && \App\Core\Auth::can('monetisation')): ?>
                                <form class="inline" method="post" action="<?= url('/admin/engagement/refunds/' . (int) $request['id']) ?>">
                                    <?= csrf_field() ?>
                                    <input type="text" name="admin_note" placeholder="Note (optional)" style="width:12rem;">
                                    <button type="submit" name="status" value="approved" class="btn btn-sm">Approve</button>
                                    <button type="submit" name="status" value="denied" class="btn btn-sm btn-ghost">Decline</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="muted" style="margin-top:.75rem;">
            Approving a one-off charge refunds it at the processor and revokes the unlock.
            A membership term is refunded in the processor by hand; the site records the decision.
        </p>
    <?php endif; ?>
</div>

<?php if (empty($purchases)): ?>
    <?= admin_empty('dollar', 'No purchases yet', 'A member unlocks a gallery or a one-off purchase is marked paid, it appears here.') ?>
<?php else: ?>    <table style="width:100%;border-collapse:collapse;">
        <thead>
            <tr>
                <th class="u-left-pad">ID</th>
                <th class="u-left-pad">When</th>
                <th class="u-left-pad">User</th>
                <th class="u-left-pad">Type</th>
                <th class="u-left-pad">Item / note</th>
                <th class="u-left-pad">Amount</th>
                <th class="u-left-pad">Gateway</th>
                <th class="u-left-pad">Status</th>
                <th style="text-align:right;padding:.4rem .5rem;">Set status</th>
            </tr>
        </thead>
        <tbody>
            <?php
            // "disputed" is a chargeback, not a bookkeeping state - the label
            // says so, because "Disputed" alone reads like a member complaint.
            $purchaseStatusLabels = [
                'pending'   => 'Pending',
                'paid'      => 'Paid',
                'granted'   => 'Granted',
                'refunded'  => 'Refunded',
                'disputed'  => 'Disputed (chargeback)',
            ];
            ?>
            <?php foreach ($purchases as $purchase): ?>
                <tr>
                    <td class="muted u-pad-04-05">#<?= (int) $purchase['id'] ?></td>
                    <td class="muted" style="padding:.4rem .5rem;white-space:nowrap;"><?= e(tzdate('Y-m-d H:i', (string) $purchase['created_at'])) ?></td>
                    <td class="u-pad-04-05"><?= e((string) $purchase['user_email']) ?></td>
                    <td class="u-pad-04-05"><?= e(ucfirst((string) $purchase['item_type'])) ?></td>
                    <td class="u-pad-04-05">
                        <?php if ($purchase['item_type'] === 'gallery'): ?>
                            <a href="<?= url('/galleries/' . (int) $purchase['item_id']) ?>" target="_blank" rel="noopener"><?= e((string) ($purchase['gallery_title'] ?? ('#' . $purchase['item_id']))) ?></a>
                        <?php else: ?>
                            <?= e(mb_substr((string) ($purchase['note'] ?? ''), 0, 80)) ?>
                        <?php endif; ?>
                    </td>
                    <td class="u-pad-04-05">$<?= e(number_format((float) $purchase['amount'], 2)) ?></td>
                    <td class="u-pad-04-05"><?= e((string) $purchase['gateway']) ?></td>
                    <td class="u-pad-04-05"><?= e($purchaseStatusLabels[(string) $purchase['status']] ?? (string) $purchase['status']) ?></td>
                    <td style="padding:.4rem .5rem;text-align:right;">
                        <?php if (\App\Core\Auth::can('monetisation')): ?>
                        <form class="inline" method="post" action="<?= url('/admin/engagement/purchases/' . (int) $purchase['id']) ?>">
                            <?= csrf_field() ?>
                            <select name="status" style="width:auto;">
                                <?php foreach (['pending', 'paid', 'granted', 'refunded', 'disputed'] as $status): ?>
                                    <option value="<?= $status ?>"<?= $purchase['status'] === $status ? ' selected' : '' ?>><?= e($purchaseStatusLabels[$status] ?? ucfirst($status)) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="text" name="note" placeholder="Note (optional)" style="width:9rem;">
                            <button type="submit" class="btn btn-sm">Save</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>