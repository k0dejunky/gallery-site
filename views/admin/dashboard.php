<?php $title = 'Admin'; ?>

<?php // Operational alert banners: failed backup, silent cron, login spike. ?>
<?php if (!empty($backupFailure)): ?>
    <p style="background:#fee2e2;border:1px solid #ef4444;color:#991b1b;padding:.5rem .75rem;border-radius:var(--border-radius);">
        <b>Last background backup failed:</b> <?= e((string) $backupFailure) ?> — see <a href="<?= url('/admin/system') ?>">System → Backups</a>.
    </p>
<?php endif; ?>
<?php if ($cronAgeMin !== null && $cronAgeMin > 45): ?>
    <p style="background:#fef3c7;border:1px solid #f59e0b;color:#92400e;padding:.5rem .75rem;border-radius:var(--border-radius);">
        <b>Housekeeping cron quiet for <?= (int) $cronAgeMin ?> min</b> (expected ~15). Check /etc/cron.d on the server.
    </p>
<?php elseif ($cronAgeMin === null): ?>
    <p style="background:#fef3c7;border:1px solid #f59e0b;color:#92400e;padding:.5rem .75rem;border-radius:var(--border-radius);">
        <b>Housekeeping cron has never run.</b> Install the cron entry from System page instructions.
    </p>
<?php endif; ?>
<?php if ((int) $security['fails_hour'] >= 25): ?>
    <p style="background:#fee2e2;border:1px solid #ef4444;color:#991b1b;padding:.5rem .75rem;border-radius:var(--border-radius);">
        <b>Login-failure spike:</b> <?= number_format((int) $security['fails_hour']) ?> failed attempts in the last hour —
        <a href="<?= url('/admin/system') ?>">review offenders</a>.
    </p>
<?php endif; ?>

<?php // Membership growth cards: recurring revenue + recent signups. ?>
<div class="stat-cards">
    <div class="stat-card"><b>$<?= number_format((float) $growth['mrr'], 2) ?></b><small>Recurring / month</small></div>
    <div class="stat-card"><b><?= number_format($growth['new_today']) ?></b><small>Signups Today</small></div>
    <div class="stat-card"><b><?= number_format($growth['new_week']) ?></b><small>Signups 7 Days</small></div>
    <?php foreach (array_slice($growth['by_processor'], 0, 2) as $proc): ?>
        <div class="stat-card"><b><?= number_format((int) $proc['members']) ?> · $<?= number_format((float) $proc['mrr'], 0) ?>/mo</b><small>via <?= e((string) $proc['name']) ?></small></div>
    <?php endforeach; ?>
</div>

<?php // Headline stat cards: lifetime totals for the whole site. ?>
<div class="stat-cards">
    <div class="stat-card"><b><?= number_format($summary['total_views']) ?></b><small>Total Views</small></div>
    <div class="stat-card"><b><?= number_format($summary['photos']) ?></b><small>Photos</small></div>
    <div class="stat-card"><b><?= number_format($summary['videos']) ?></b><small>Videos</small></div>
    <div class="stat-card"><b><?= number_format($summary['galleries']) ?></b><small>Galleries</small></div>
    <div class="stat-card"><b><?= number_format($summary['total_members']) ?></b><small>Members</small></div>
    <div class="stat-card"><b><?= number_format($summary['total_users']) ?></b><small>Users</small></div>
    <div class="stat-card"><b><?= number_format($summary['logged_in_members']) ?></b><small>Logged In Members</small></div>
</div>

<?php // Revenue & churn: monthly bars + trailing totals, all server-rendered SVG. ?>
<div class="sys-card" style="margin-top:var(--spacing-lg);">
    <h2>Revenue &amp; churn — last 6 months</h2>
    <?php
        $mrrNow = (float) $growth['mrr'];
        $activeMembers = max(1, (int) $summary['total_members']);
    ?>
    <div style="display:flex;flex-wrap:wrap;gap:1.5rem;align-items:flex-start;">
        <div style="flex:2 1 420px;min-width:320px;">
            <p style="margin:.25rem 0 .5rem;"><b style="font-size:1.15rem;">$<?= number_format((float) $finance['mtd_revenue'], 2) ?></b>
                <span class="muted">month-to-date</span> ·
                <b>$<?= number_format((float) $finance['total_12mo'], 2) ?></b> <span class="muted">trailing 12 mo</span></p>
            <?= \App\Core\Charts::bars(
                array_map(fn ($l, $i) => $l, $finance['labels'], array_keys($finance['labels'])),
                $finance['revenue'],
                480, 120, '#16a34a',
                '$%s'
            ) ?>
            <p class="muted" style="margin:.35rem 0 0;font-size:.85rem;">Bars = collected revenue per month (hover for exact).</p>
        </div>
        <div style="flex:1 1 200px;">
            <table style="width:auto;">
                <tr><th></th><th>New paid</th><th>Churn</th></tr>
                <?php foreach ($finance['labels'] as $i => $label): ?>
                    <tr>
                        <td><b><?= e($label) ?></b></td>
                        <td><?= number_format((int) $finance['new_paid'][$i]) ?></td>
                        <td<?= (int) $finance['churn'][$i] > 0 ? ' style="color:#b45309;"' : '' ?>><?= number_format((int) $finance['churn'][$i]) ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr><td><b>ARPU</b></td><td colspan="2">$<?= number_format($mrrNow / $activeMembers, 2) ?>/mo</td></tr>
            </table>
        </div>
    </div>
</div>

<?php // Storage growth trend (from housekeeping snapshots). ?>
<div class="sys-card" style="margin-top:var(--spacing-lg);">
    <h2>Storage trend</h2>
    <?php if (empty($storageTrend['gb'])): ?>
        <p class="muted">No snapshots yet — the housekeeping cron records one every 15 minutes.</p>
    <?php else: ?>
        <?= \App\Core\Charts::sparkline($storageTrend['gb'], 480, 60, '#0ea5e9') ?>
        <p class="muted" style="margin:.35rem 0 0;font-size:.85rem;">
            Uploads over last <?= count($storageTrend['gb']) ?> day(s):
            <?= e((string) reset($storageTrend['gb'])) ?> GB →
            <b><?= e((string) end($storageTrend['gb'])) ?> GB</b>
        </p>
    <?php endif; ?>
</div>

<div class="sys-card" style="margin-top:var(--spacing-lg);">
    <h2>Recent activity</h2>
    <?php if (empty($feed)): ?>
        <p class="muted">Nothing recorded yet.</p>
    <?php else: ?>
        <table>
            <tbody>
            <?php foreach ($feed as $item): ?>
                <tr>
                    <td style="white-space:nowrap;width:9em;" class="muted"><?= e(substr((string) $item['at'], 5, 11)) ?></td>
                    <td>
                        <?php if (!empty($item['link'])): ?>
                            <a href="<?= url((string) $item['link']) ?>"><?= e((string) $item['text']) ?></a>
                        <?php else: ?>
                            <?= e((string) $item['text']) ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php // The gallery list lives on the Gallery Management tab now. ?>

