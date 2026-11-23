<?php $title = 'Earnings'; ?>

<?php // This view still carried its own <style> block built from MEMBER theme
      // variables (--card-bg, --purple-800), which do not exist inside the admin
      // shell, so the KPI cards rendered unstyled and fell into a 3+2 grid. The
      // shared .stat-cards system plus a toolbar card replaces all of it. ?>
<section class="adm-section">
    <div class="adm-section-head">
        <h2>Revenue</h2>
        <p>Active paid monthly memberships plus one-off PPV unlocks and tips.</p>
    </div>
    <div class="sys-card adm-toolbar">
        <form method="get" action="<?= url('/admin/earnings') ?>" class="adm-inline-form adm-inline-form-compact">
            <label class="adm-field">
                <span>From</span>
                <input type="date" name="from" value="<?= e($from) ?>">
            </label>
            <label class="adm-field">
                <span>To</span>
                <input type="date" name="to" value="<?= e($to) ?>">
            </label>
            <div class="adm-field-actions">
                <button type="submit" class="btn">Apply</button>
                <a class="btn btn-sm btn-outline" href="<?= url('/admin/earnings?export=csv&from=' . e($from) . '&to=' . e($to)) ?>">Export CSV</a>
            </div>
        </form>
    </div>

    <div class="stat-cards">
        <div class="stat-card kpi">
            <small>Monthly recurring</small>
            <b>$<?= number_format($mrr, 2) ?></b>
            <small>Active paid monthly memberships only</small>
        </div>
        <div class="stat-card kpi">
            <small>Subscription revenue</small>
            <b>$<?= number_format($subRevenue, 2) ?></b>
            <small>Same memberships, monthly value (not the date window)</small>
        </div>
        <div class="stat-card kpi">
            <small>One-off revenue</small>
            <b>$<?= number_format($oneOff['total'], 2) ?></b>
        </div>
        <div class="stat-card kpi">
            <small>Of which PPV unlocks</small>
            <b>$<?= number_format($oneOff['gallery'], 2) ?></b>
        </div>
        <div class="stat-card kpi">
            <small>Of which tips</small>
            <b>$<?= number_format($oneOff['tip'], 2) ?></b>
        </div>
        <div class="stat-card kpi">
            <small>Of which gifts</small>
            <b>$<?= number_format($oneOff['gift'] ?? 0, 2) ?></b>
        </div>
        <?php if (($oneOff['offline'] ?? 0) > 0): ?>
        <div class="stat-card kpi">
            <small>Offline (not collected)</small>
            <b>$<?= number_format($oneOff['offline'], 2) ?></b>
            <small>Recorded by members or taken outside the site. No money moved here, so they are not in the revenue above.</small>
        </div>
        <?php endif; ?>
    </div>
</section>

<section class="adm-section">
    <div class="adm-section-head">
        <h2>Per-month revenue</h2>
        <p><?= count($series) ? count($series) . ' month' . (count($series) === 1 ? '' : 's') : 'None yet' ?></p>
    </div>
    <div class="sys-card">
    <?php if (empty($series)): ?>
        <?= admin_empty('trend', 'No one-off revenue', 'PPV unlocks and tips land here once a member pays.') ?>
    <?php else: ?>
        <table>
            <thead><tr><th>Month</th><th class="num">PPV unlocks</th><th class="num">Tips</th><th class="num">Gifts</th><th class="num">Total</th></tr></thead>
            <tbody>
                <?php foreach ($series as $ym => $row): ?>
                    <tr>
                        <td><?= e($ym) ?></td>
                        <td class="num">$<?= number_format($row['gallery'], 2) ?></td>
                        <td class="num">$<?= number_format($row['tip'], 2) ?></td>
                        <td class="num">$<?= number_format($row['gift'] ?? 0, 2) ?></td>
                        <td class="num"><strong>$<?= number_format($row['total'], 2) ?></strong></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    </div>
</section>

<section class="adm-section">
    <div class="adm-section-head">
        <h2>Charges this window</h2>
        <p><?= count($charges) ? number_format(count($charges)) . ' charge' . (count($charges) === 1 ? '' : 's') : 'None yet' ?> &middot; every payment, renewals included</p>
    </div>
    <div class="sys-card">
    <?php if (empty($charges)): ?>
        <?= admin_empty('repeat', 'No charges', 'No membership payment arrived between ' . $from . ' and ' . $to . '.') ?>
    <?php else: ?>
        <table>
            <thead><tr><th>#</th><th>Plan</th><th class="num">Amount</th><th>Type</th><th>Gateway</th><th>Email</th><th>When</th></tr></thead>
            <tbody>
                <?php foreach ($charges as $c): ?>
                    <?php $kindLabel = ['initial' => 'New', 'renewal' => 'Renewal', 'adjustment' => 'Adjustment'][(string) $c['kind']] ?? 'Charge'; ?>
                    <tr>
                        <td class="muted"><?= (int) $c['id'] ?></td>
                        <td><?= e((string) $c['plan']) ?></td>
                        <td class="num">$<?= number_format((float) $c['amount'], 2) ?></td>
                        <td><span class="pill <?= (string) $c['kind'] === 'renewal' ? 'pill-info' : 'pill-soft' ?>"><?= e($kindLabel) ?></span></td>
                        <td class="muted"><?= e(ucfirst((string) ($c['gateway'] ?? '-'))) ?></td>
                        <td class="adm-clip"><?= e((string) ($c['email'] ?? '-')) ?></td>
                        <td class="muted mg-date"><?= e(tzdate('Y-m-d H:i', (string) $c['paid_at'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    </div>
</section>

<section class="adm-section">
    <div class="adm-section-head">
        <h2>Recent one-off purchases</h2>
        <p><?= count($recent) ? number_format(count($recent)) . ' shown' : 'None yet' ?></p>
    </div>
    <div class="sys-card">
    <?php if (empty($recent)): ?>
        <?= admin_empty('dollar', 'No PPV unlocks or tips yet', 'They appear here as soon as a member pays.') ?>
    <?php else: ?>
        <table>
            <thead><tr><th>#</th><th>Type</th><th>Gallery</th><th class="num">Amount</th><th>Gateway</th><th>Status</th><th>Email</th><th>When</th></tr></thead>
            <tbody>
                <?php foreach ($recent as $r): ?>
                    <tr>
                        <td class="muted"><?= (int) $r['id'] ?></td>
                        <td><?= $r['item_type'] === 'tip' ? 'Tip' : 'PPV' ?></td>
                        <td><?= e((string) ($r['gallery_title'] ?? '—')) ?></td>
                        <td class="num">$<?= number_format((float) $r['amount'], 2) ?></td>
                        <td><?= e((string) $r['gateway']) ?></td>
                        <td><?= e((string) $r['status']) ?></td>
                        <td class="adm-clip"><?= e((string) ($r['user_email'] ?? '')) ?></td>
                        <td class="muted mg-date"><?= e(tzdate('Y-m-d H:i', (string) $r['created_at'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    </div>
</section>
