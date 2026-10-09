<?php $title = 'Earnings'; ?>

<style>
    .earn-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; margin: 1rem 0; }
    .earn-card { padding: 1rem; background: var(--card-bg); border: var(--input-border-width) solid var(--card-border); border-radius: var(--card-radius); box-shadow: var(--shadow); }
    .earn-card .big { font-size: 1.5rem; font-weight: bold; color: var(--purple-800); margin-top: .15rem; }
    .earn-card .muted { font-size: .8rem; }
</style>

<form method="get" action="<?= url('/admin/earnings') ?>" style="margin: 1rem 0; display:flex; gap:.6rem; align-items:flex-end; flex-wrap:wrap;">
    <label>From <input type="date" name="from" value="<?= e($from) ?>"></label>
    <label>To <input type="date" name="to" value="<?= e($to) ?>"></label>
    <button type="submit" class="btn btn-sm">Apply</button>
    <a class="btn btn-sm btn-outline" href="<?= url('/admin/earnings?export=csv&from=' . e($from) . '&to=' . e($to)) ?>">Export CSV</a>
</form>

<div class="earn-grid">
    <div class="earn-card"><div class="muted">Monthly recurring revenue (MRR)</div><div class="big">$<?= number_format($mrr, 2) ?></div></div>
    <div class="earn-card"><div class="muted">Subscriptions this window</div><div class="big">$<?= number_format($subRevenue, 2) ?></div></div>
    <div class="earn-card"><div class="muted">One-off revenue this window</div><div class="big">$<?= number_format($oneOff['total'], 2) ?></div></div>
    <div class="earn-card"><div class="muted">… of which PPV unlocks</div><div class="big">$<?= number_format($oneOff['gallery'], 2) ?></div></div>
    <div class="earn-card"><div class="muted">… of which tips</div><div class="big">$<?= number_format($oneOff['tip'], 2) ?></div></div>
</div>

<div class="stats-panel" style="margin-bottom:1rem;">
    <h2>Per-month revenue</h2>
    <?php if (empty($series)): ?>
        <p class="muted">No one-off revenue in this window.</p>
    <?php else: ?>
        <table>
            <thead><tr><th>Month</th><th>PPV unlocks</th><th>Tips</th><th>Total</th></tr></thead>
            <tbody>
                <?php foreach ($series as $ym => $row): ?>
                    <tr>
                        <td><?= e($ym) ?></td>
                        <td>$<?= number_format($row['gallery'], 2) ?></td>
                        <td>$<?= number_format($row['tip'], 2) ?></td>
                        <td>$<?= number_format($row['total'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="stats-panel" style="margin-bottom:1rem;">
    <h2>Subscriptions this window</h2>
    <?php if (empty($industrySubs)): ?>
        <p class="muted">No paid subscriptions were created in this window.</p>
    <?php else: ?>
        <table>
            <thead><tr><th>#</th><th>Plan</th><th>Amount</th><th>Status</th><th>Email</th><th>When</th></tr></thead>
            <tbody>
                <?php foreach ($industrySubs as $s): ?>
                    <tr>
                        <td><?= (int) $s['id'] ?></td>
                        <td><?= e((string) $s['plan']) ?></td>
                        <td>$<?= number_format((float) $s['amount'], 2) ?></td>
                        <td><?= e((string) $s['status']) ?></td>
                        <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= e((string) $s['email']) ?></td>
                        <td><?= e(tzdate('Y-m-d H:i', (string) $s['created_at'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="stats-panel">
    <h2>Recent one-off purchases</h2>
    <?php if (empty($recent)): ?>
        <p class="muted">No PPV unlocks or tips yet.</p>
    <?php else: ?>
        <table>
            <thead><tr><th>#</th><th>Type</th><th>Gallery</th><th>Amount</th><th>Gateway</th><th>Status</th><th>Email</th><th>When</th></tr></thead>
            <tbody>
                <?php foreach ($recent as $r): ?>
                    <tr>
                        <td><?= (int) $r['id'] ?></td>
                        <td><?= $r['item_type'] === 'tip' ? 'Tip' : 'PPV' ?></td>
                        <td><?= e((string) ($r['gallery_title'] ?? '—')) ?></td>
                        <td>$<?= number_format((float) $r['amount'], 2) ?></td>
                        <td><?= e((string) $r['gateway']) ?></td>
                        <td><?= e((string) $r['status']) ?></td>
                        <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= e((string) ($r['user_email'] ?? '')) ?></td>
                        <td><?= e(tzdate('Y-m-d H:i', (string) $r['created_at'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>