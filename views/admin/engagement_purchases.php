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

<?php if (empty($purchases)): ?>
    <p class="muted">No purchases recorded yet.</p>
<?php else: ?>
    <table style="width:100%;border-collapse:collapse;">
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
                    <td class="u-pad-04-05"><?= e((string) $purchase['status']) ?></td>
                    <td style="padding:.4rem .5rem;text-align:right;">
                        <form class="inline" method="post" action="<?= url('/admin/engagement/purchases/' . (int) $purchase['id']) ?>">
                            <?= csrf_field() ?>
                            <select name="status" style="width:auto;">
                                <?php foreach (['pending', 'paid', 'granted', 'refunded'] as $status): ?>
                                    <option value="<?= $status ?>"<?= $purchase['status'] === $status ? ' selected' : '' ?>><?= ucfirst($status) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn btn-sm">Save</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>