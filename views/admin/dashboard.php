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

<?php // One-click entry points for the most common admin tasks, plus an
     // optional auto-refresh toggle that reloads the dashboard every minute. ?>
<div class="quick-actions">
    <span class="qa-label">Quick actions</span>
    <a class="btn btn-sm" href="<?= url('/admin/galleries/create') ?>">New Gallery</a>
    <a class="btn btn-sm" href="<?= url('/admin/galleries') ?>">Manage Galleries</a>
    <a class="btn btn-sm" href="<?= url('/admin/auto-poster') ?>">Auto-Poster<?php if ((int) ($autopostCounts['failed'] ?? 0) > 0): ?> <span class="pill pill-err"><?= (int) $autopostCounts['failed'] ?> failed</span><?php endif; ?></a>
    <a class="btn btn-sm" href="<?= url('/admin/test-suite') ?>">Run Test Suite</a>
    <form class="inline" method="post" action="<?= url('/admin/system/backup') ?>" onsubmit="return confirm('Create a full site backup now?');">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-sm btn-outline" aria-label="Create a site backup now">Backup now</button>
    </form>
    <label class="qa-live" style="margin-left:auto;display:inline-flex;align-items:center;gap:.4rem;font-size:var(--font-size-sm);cursor:pointer;" title="Reload this page automatically every 60 seconds">
        <input type="checkbox" id="qa-live-refresh">
        Auto-refresh (60s)
    </label>
</div>

<?php // Failed auto-posts need attention: show the most recent ones here with
     // a one-click retry instead of hiding them in the Auto Poster page. ?>
<?php if (!empty($autopostFailed)): ?>
<div class="sys-card" style="margin-top:var(--spacing-lg);">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap;">
        <h2 style="margin:0;">Auto-poster failures <span class="pill pill-err"><?= count($autopostFailed) ?> need attention</span></h2>
        <a href="<?= url('/admin/auto-poster') ?>" class="btn btn-sm">Auto Poster</a>
    </div>
    <table>
        <thead><tr><th>#</th><th>Gallery</th><th>Error</th><th style="text-align:right;">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($autopostFailed as $item): ?>
            <tr>
                <td><?= (int) $item['id'] ?></td>
                <td><?= e((string) $item['gallery_title']) ?></td>
                <td style="max-width:340px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= e((string) $item['error']) ?>">
                    <span class="pill pill-err">failed</span> <?= e((string) $item['error']) ?>
                </td>
                <td style="text-align:right;white-space:nowrap;">
                    <form class="inline" method="post" action="<?= url('/admin/auto-poster/queue/retry') ?>" onsubmit="return confirm('Retry post #<?= (int) $item['id'] ?> now?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="queue_id" value="<?= (int) $item['id'] ?>">
                        <button type="submit" class="btn btn-sm" aria-label="Retry failed post #<?= (int) $item['id'] ?>">Retry</button>
                    </form>
                    <form class="inline" method="post" action="<?= url('/admin/auto-poster/queue/dismiss') ?>" onsubmit="return confirm('Dismiss this failed post?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="queue_id" value="<?= (int) $item['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-danger" aria-label="Dismiss failed post #<?= (int) $item['id'] ?>">Dismiss</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
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

<?php // Gallery access-level breakdown: how many galleries are behind each tier. ?>
<div class="sys-card" style="margin-top:var(--spacing-lg);">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap;">
        <h2 style="margin:0;">Galleries by access level</h2>
        <a href="<?= url('/admin/galleries/create') ?>" class="btn btn-sm">Gallery Management</a>
    </div>
    <div style="display:flex;flex-wrap:wrap;gap:1.5rem;align-items:flex-start;margin-top:.75rem;">
        <div style="flex:1 1 320px;min-width:260px;">
            <table>
                <tbody>
                    <tr><td><b>Free</b> <span class="muted">(all registered users)</span></td><td style="text-align:right;"><?= number_format($galleryLevels['levels'][0]) ?></td></tr>
                    <tr><td><b>Silver</b></td><td style="text-align:right;"><?= number_format($galleryLevels['levels'][1]) ?></td></tr>
                    <tr><td><b>Gold</b></td><td style="text-align:right;"><?= number_format($galleryLevels['levels'][2]) ?></td></tr>
                    <tr><td><b>Platinum</b></td><td style="text-align:right;"><?= number_format($galleryLevels['levels'][3]) ?></td></tr>
                    <tr><td><b>Diamond</b></td><td style="text-align:right;"><?= number_format($galleryLevels['levels'][4]) ?></td></tr>
                    <tr><td><b>Total</b></td><td style="text-align:right;"><?= number_format($galleryLevels['total']) ?></td></tr>
                </tbody>
            </table>
        </div>
        <div style="flex:1 1 240px;min-width:200px;">
            <div class="stat-card" style="border-left:4px solid var(--purple-400,#a855f7);">
                <b style="font-size:1.3rem;"><?= number_format($galleryLevels['total_gated']) ?></b>
                <small>Member-gated galleries (behind a paid tier)</small>
            </div>
        </div>
    </div>
</div>

<?php // System health: disk space pie + security summary. ?>
<?php if (!empty($disk)): ?>
    <?php
        $diskHelpers = [
            'gb' => static fn (float $b): string => number_format($b / 1073741824, 1),
            'bar' => static fn (float $f): string => 'width:' . number_format($f / (float) $disk['total'] * 100, 2) . '%;',
        ];
        $diskSlices = [
            ['label' => 'Free disk',      'value' => (float) $disk['free'],    'color' => '#16a34a'],
            ['label' => 'Backups',        'value' => (float) $disk['backups'], 'color' => '#f43f5e'],
            ['label' => 'Images',         'value' => (float) $disk['images'],  'color' => '#0ea5e9'],
            ['label' => 'Videos',         'value' => (float) $disk['videos'],  'color' => '#a855f7'],
            ['label' => 'Database',       'value' => (float) $disk['db'],      'color' => '#f59e0b'],
            ['label' => 'OS',             'value' => (float) $disk['os'],      'color' => '#64748b'],
        ];
    ?>
    <div class="sys-card" style="margin-top:var(--spacing-lg);">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap;">
            <h2 style="margin:0;">Disk space</h2>
            <span class="muted" style="font-size:.85rem;">
                <?= $diskHelpers['gb']((float) $disk['total']) ?> GB total · <?= $diskHelpers['gb']((float) $disk['free']) ?> GB free
            </span>
        </div>
        <div style="display:flex;flex-wrap:wrap;gap:1.5rem;align-items:center;margin-top:.75rem;">
            <div style="flex:0 0 auto;">
                <?= \App\Core\Charts::pie($diskSlices, 230, 230) ?>
            </div>
            <div style="flex:1 1 280px;min-width:220px;">
                <?php foreach ($diskSlices as $slice): ?>
                    <div style="display:flex;align-items:center;gap:.6rem;margin:.45rem 0;">
                        <i style="flex:0 0 auto;width:.85rem;height:.85rem;border-radius:.2rem;background:<?= e($slice['color']) ?>;display:inline-block;"></i>
                        <span style="flex:0 0 90px;"><?= e($slice['label']) ?></span>
                        <div style="flex:1 1 auto;min-width:60px;height:.55rem;background:rgba(120,120,140,.15);border-radius:.3rem;overflow:hidden;">
                            <div style="<?= $diskHelpers['bar']((float) $slice['value']) ?>;height:100%;background:<?= e($slice['color']) ?>;"></div>
                        </div>
                        <b style="flex:0 0 58px;text-align:right;font-variant-numeric:tabular-nums;">
                            <?= $diskHelpers['gb']((float) $slice['value']) ?> GB
                        </b>
                    </div>
                <?php endforeach; ?>
                <p class="muted" style="margin:.6rem 0 0;font-size:.85rem;">
                    OS = everything outside the site's uploads, backups and database (server, app, logs).
                </p>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="stat-cards" style="margin-top:var(--spacing-lg);">
    <?php if ((int) $security['locked_ips'] > 0 || (int) $security['locked_pairs'] > 0): ?>
        <div class="stat-card" style="background:#fef2f2;">
            <b style="color:#dc2626;font-size:1.3rem;"><?= count($security['locked_ips']) + count($security['locked_pairs']) ?></b>
            <small>Active lockouts (last <?= (int) $security['window_min'] ?>m)</small>
        </div>
    <?php endif; ?>
    <?php if (!empty($security['top_ips'])): ?>
        <div class="stat-card">
            <b><?= number_format((int) $security['top_ips'][0]['c']) ?></b>
            <small>Top offender IPs (24h)</small>
        </div>
    <?php endif; ?>
    <?php if ((int) $security['fails_hour'] > 0 && (int) $security['fails_hour'] < 25): ?>
        <div class="stat-card">
            <b><?= number_format((int) $security['fails_hour']) ?></b>
            <small>Login fails (last hour)</small>
        </div>
    <?php endif; ?>
</div>

<?php if (!empty($security['locked_ips']) || !empty($security['locked_pairs'])): ?>
<div class="sys-card" style="margin-top:var(--spacing-lg);">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap;">
        <h2 style="margin:0;">Security — active lockouts</h2>
        <a href="<?= url('/admin/system') ?>" class="btn btn-sm">System → Security</a>
    </div>
    <div style="display:flex;flex-wrap:wrap;gap:1.5rem;margin-top:.75rem;">
        <?php if (!empty($security['locked_ips'])): ?>
            <div style="flex:1 1 200px;">
                <h3 style="margin:0 0 .35rem;font-size:.9rem;">Locked IPs</h3>
                <table style="width:100%;">
                    <tbody>
                    <?php foreach (array_slice($security['locked_ips'], 0, 5) as $row): ?>
                        <tr>
                            <td class="muted" style="font-family:monospace;font-size:.85rem;"><?= e((string) $row['ip']) ?></td>
                            <td style="text-align:right;white-space:nowrap;"><b><?= (int) $row['c'] ?></b> fails</td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <?php if (!empty($security['locked_pairs'])): ?>
            <div style="flex:1 1 200px;">
                <h3 style="margin:0 0 .35rem;font-size:.9rem;">Locked email + IP pairs</h3>
                <table style="width:100%;">
                    <tbody>
                    <?php foreach (array_slice($security['locked_pairs'], 0, 5) as $row): ?>
                        <tr>
                            <td class="muted" style="font-size:.85rem;"><?= e((string) $row['email']) ?></td>
                            <td class="muted" style="font-family:monospace;font-size:.85rem;"><?= e((string) $row['ip']) ?></td>
                            <td style="text-align:right;white-space:nowrap;"><b><?= (int) $row['c'] ?></b></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
<?php endif; ?>
</div>

<script>
    // Optional live dashboard: auto-reload once a minute while the toggle is on.
    (function () {
        var toggle = document.getElementById('qa-live-refresh');
        if (!toggle) return;
        var KEY = 'admin_live_refresh';
        var on = false;
        try { on = localStorage.getItem(KEY) === '1'; } catch (ignore) {}
        toggle.checked = on;
        toggle.addEventListener('change', function () {
            var enable = toggle.checked;
            try { localStorage.setItem(KEY, enable ? '1' : '0'); } catch (ignore) {}
            if (enable) scheduleReload();
        });
        function scheduleReload() {
            try { if (localStorage.getItem(KEY) !== '1') return; } catch (ignore) {}
            setTimeout(function () { window.location.reload(); }, 60000);
        }
        if (on) scheduleReload();
    })();
</script>
</div>
<?php endif; ?>

<?php // Revenue & churn: monthly bars + trailing totals, all server-rendered SVG. ?>
<details class="sys-card" style="margin-top:var(--spacing-lg);" data-collapse-key="finance" open>
    <summary><h2>Revenue &amp; churn — last 6 months</h2></summary>
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
</details>

<?php // Storage growth trend (from housekeeping snapshots), selectable window. ?>
<details class="sys-card" style="margin-top:var(--spacing-lg);" data-collapse-key="storage" open>
    <summary><h2 style="margin:0;">Storage trend</h2></summary>
    <div class="storage-periods" role="navigation" aria-label="Storage trend period" style="margin-top:var(--spacing-sm);">
        <?php foreach (['day' => 'Day', 'week' => 'Week', 'month' => 'Month', 'year' => 'Year', 'all' => 'All time'] as $p => $label): ?>
            <a class="btn btn-sm<?= $storagePeriod === $p ? ' storage-period-active' : '' ?>"
               href="<?= e(url('/admin?period=' . $p)) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </div>
    <style>
        .storage-periods { display: flex; gap: .35rem; flex-wrap: wrap; }
        .storage-period-active { background: var(--sidebar-active-bg, #0ea5e9); color: var(--sidebar-active-color, #fff); border-color: transparent; }
    </style>
    <?php if (empty($storageTrend['gb'])): ?>
        <p class="muted">No snapshots yet — the housekeeping cron records one every 15 minutes.</p>
    <?php else: ?>
        <?php
            $cur    = (float) ($storageTrend['current_gb'] ?? 0);
            $delta  = (float) ($storageTrend['delta_gb'] ?? 0);
            $photos = (int) ($storageTrend['current_photos'] ?? 0);
            $videos = (int) ($storageTrend['current_videos'] ?? 0);
            $granLabels = ['hour' => 'hourly points', 'day' => 'daily points', 'month' => 'monthly points'];
            $winLabels  = ['day' => 'last 24 hours', 'week' => 'last 7 days', 'month' => 'last 30 days', 'year' => 'last 12 months', 'all' => 'all recorded history'];
        ?>
        <div class="stat-cards" style="margin:.75rem 0;">
            <div class="stat-card">
                <span class="muted">Current uploads storage</span>
                <b style="font-size:1.3rem;"><?= number_format($cur, 2) ?> GB</b>
                <span class="muted" style="font-size:.8rem;"><?= number_format($photos) ?> photos · <?= number_format($videos) ?> videos tracked</span>
            </div>
            <div class="stat-card">
                <span class="muted">Change over <?= e($winLabels[$storagePeriod] ?? $storagePeriod) ?></span>
                <b style="font-size:1.3rem;color:<?= $delta > 0 ? '#16a34a' : ($delta < 0 ? '#dc2626' : 'inherit') ?>;">
                    <?= $delta > 0 ? '+' : '' ?><?= number_format($delta, 2) ?> GB
                </b>
                <span class="muted" style="font-size:.8rem;"><?= e((string) reset($storageTrend['gb'])) ?> GB → <?= e((string) end($storageTrend['gb'])) ?> GB</span>
            </div>
        </div>
        <?= \App\Core\Charts::sparkline($storageTrend['gb'], 720, 90, '#0ea5e9') ?>
        <p class="muted" style="margin:.35rem 0 0;font-size:.85rem;">
            <?= count($storageTrend['gb']) ?> <?= e($granLabels[$storageTrend['granularity']] ?? 'points') ?>
            · snapshots every ~15 min<?php if (!empty($storageTrend['first_snapshot'])): ?>
            · history begins <?= e(date('n/j/Y', strtotime($storageTrend['first_snapshot']))) ?><?php endif; ?>
        </p>
    <?php endif; ?>
</details>

<?php // Daily view trends (from the content_views log) — gallery vs photo vs total. ?>
<details class="sys-card" style="margin-top:var(--spacing-lg);" data-collapse-key="views" open>
    <summary><h2 style="margin:0;">View trends — last 30 days</h2></summary>
    <?php
        $viewTotals = array_sum($viewTrends['total']);
        $viewNow    = (int) end($viewTrends['total']);
        $viewDw     = array_sum(array_slice($viewTrends['total'], -7));
    ?>
    <?php if ($viewTotals <= 0): ?>
        <p class="muted">No tracked views yet — a logged-in visit to a gallery or photo records a daily count from now on.</p>
    <?php else: ?>
        <div class="stat-cards" style="margin:.75rem 0;">
            <div class="stat-card"><span class="muted">Views (30 days)</span><b style="font-size:1.3rem;"><?= number_format($viewTotals) ?></b></div>
            <div class="stat-card"><span class="muted">Views (last 7 days)</span><b style="font-size:1.3rem;"><?= number_format($viewDw) ?></b></div>
            <div class="stat-card"><span class="muted">Views (yesterday)</span><b style="font-size:1.3rem;"><?= number_format($viewNow) ?></b></div>
        </div>
        <div style="display:flex;flex-wrap:wrap;gap:1.5rem;">
            <div style="flex:2 1 400px;min-width:300px;">
                <?= \App\Core\Charts::bars($viewTrends['labels'], $viewTrends['total'], 520, 140, '#0ea5e9', '%s') ?>
            </div>
            <div style="flex:1 1 180px;min-width:160px;">
                <p class="muted" style="margin:0 0 .35rem;font-size:.85rem;">Gallery vs photo views</p>
                <?= \App\Core\Charts::sparkline($viewTrends['gallery'], 200, 44, '#0ea5e9') ?>
                <p class="muted" style="margin:.15rem 0 .35rem;font-size:.8rem;">Galleries</p>
                <?= \App\Core\Charts::sparkline($viewTrends['photo'], 200, 44, '#a855f7') ?>
                <p class="muted" style="margin:.15rem 0 0;font-size:.8rem;">Photos</p>
            </div>
        </div>
    <?php endif; ?>
</details>

<?php // Content & membership growth over the trailing months. ?>
<details class="sys-card" style="margin-top:var(--spacing-lg);" data-collapse-key="growth" open>
    <summary><h2>Content &amp; membership growth — last 6 months</h2></summary>
    <div style="display:flex;flex-wrap:wrap;gap:1.5rem;">
        <div style="flex:2 1 420px;min-width:320px;">
            <p class="muted" style="margin:0 0 .35rem;font-size:.85rem;">New galleries</p>
            <?= \App\Core\Charts::sparkline($growthSeries['galleries'], 520, 40, '#16a34a') ?>
            <p class="muted" style="margin:.5rem 0 .35rem;font-size:.85rem;">New photos</p>
            <?= \App\Core\Charts::sparkline($growthSeries['photos'], 520, 40, '#0ea5e9') ?>
            <p class="muted" style="margin:.5rem 0 .35rem;font-size:.85rem;">New signups</p>
            <?= \App\Core\Charts::sparkline($growthSeries['users'], 520, 40, '#a855f7') ?>
            <p class="muted" style="margin:.35rem 0 0;font-size:.8rem;">Labels across the series: <?= e(implode(' · ', $growthSeries['labels'])) ?></p>
        </div>
        <div style="flex:1 1 240px;min-width:220px;">
            <table>
                <thead><tr><th>Month</th><th style="text-align:right;">Gal.</th><th style="text-align:right;">Photos</th><th style="text-align:right;">Videos</th><th style="text-align:right;">Users</th></tr></thead>
                <tbody>
                <?php foreach ($growthSeries['labels'] as $i => $label): ?>
                    <tr>
                        <td><b><?= e($label) ?></b></td>
                        <td style="text-align:right;"><?= number_format($growthSeries['galleries'][$i]) ?></td>
                        <td style="text-align:right;"><?= number_format($growthSeries['photos'][$i]) ?></td>
                        <td style="text-align:right;"><?= number_format($growthSeries['videos'][$i]) ?></td>
                        <td style="text-align:right;"><?= number_format($growthSeries['users'][$i]) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</details>

<?php // Top content by views. ?>
<details class="sys-card" style="margin-top:var(--spacing-lg);" data-collapse-key="topcontent" open>
    <summary><h2>Top content by views</h2></summary>
    <div style="display:flex;flex-wrap:wrap;gap:1.5rem;">
        <div style="flex:1 1 340px;min-width:280px;">
            <h3 style="margin:0 0 .35rem;font-size:.9rem;">Galleries</h3>
            <?php if (empty($topContentStats['galleries'])): ?>
                <p class="muted">No galleries yet — <a href="<?= url('/admin/galleries/create') ?>">create one</a>.</p>
            <?php else: ?>
                <table>
                    <thead><tr><th>Gallery</th><th style="text-align:right;">Views</th><th style="text-align:right;">Unique</th></tr></thead>
                    <tbody>
                    <?php foreach ($topContentStats['galleries'] as $g): ?>
                        <tr>
                            <td style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                <a href="<?= url('/admin/galleries/' . (int) $g['id']) ?>"><?= e((string) $g['title']) ?></a>
                                <span class="muted" style="font-size:.8rem;"> · <?= number_format((int) $g['media_count']) ?> media</span>
                            </td>
                            <td style="text-align:right;"><?= number_format((int) $g['views']) ?></td>
                            <td style="text-align:right;"><?= number_format((int) $g['unique_views']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <div style="flex:1 1 340px;min-width:280px;">
            <h3 style="margin:0 0 .35rem;font-size:.9rem;">Photos</h3>
            <?php if (empty($topContentStats['photos'])): ?>
                <p class="muted">No photos yet — <a href="<?= url('/admin/galleries') ?>">upload some</a>.</p>
            <?php else: ?>
                <table>
                    <thead><tr><th>Photo</th><th style="text-align:right;">Views</th><th style="text-align:right;">Unique</th></tr></thead>
                    <tbody>
                    <?php foreach ($topContentStats['photos'] as $p): ?>
                        <tr>
                            <td style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                <a href="<?= url('/admin/photos/' . (int) $p['id'] . '/edit') ?>"><?= e($p['caption'] !== '' ? $p['caption'] : basename((string) $p['filename'])) ?></a>
                                <span class="muted" style="font-size:.8rem;"> · <?= $p['is_video'] ? 'video' : 'image' ?></span>
                            </td>
                            <td style="text-align:right;"><?= number_format((int) $p['views']) ?></td>
                            <td style="text-align:right;"><?= number_format((int) $p['unique_views']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</details>

<?php // Membership plan distribution. ?>
<details class="sys-card" style="margin-top:var(--spacing-lg);" data-collapse-key="plans" open>
    <summary>
        <h2 style="margin:0;">Membership by plan</h2>
        <span class="collapsible-meta"><a href="<?= url('/admin/subscriptions') ?>" class="btn btn-sm">Subscriptions</a></span>
    </summary>
    <div style="display:flex;flex-wrap:wrap;gap:1.5rem;">
        <div style="flex:1 1 340px;min-width:280px;">
            <table>
                <thead><tr><th>Plan</th><th>Tier</th><th style="text-align:right;">Members</th><th style="text-align:right;">MRR</th></tr></thead>
                <tbody>
                <?php if (empty($planDistribution['plans'])): ?>
                    <tr><td colspan="4" class="muted">No plans yet — <a href="<?= url('/admin/plans/create') ?>">create a membership plan</a>.</td></tr>
                <?php endif; ?>
                <?php foreach ($planDistribution['plans'] as $plan): ?>
                    <tr>
                        <td><b><?= e($plan['name']) ?></b></td>
                        <td class="muted"><?= e(\App\Models\Subscription::levelLabel((int) $plan['level'])) ?></td>
                        <td style="text-align:right;"><?= number_format((int) $plan['members']) ?></td>
                        <td style="text-align:right;">$<?= number_format((float) $plan['mrr'], 2) ?>/mo</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div style="flex:1 1 260px;min-width:220px;">
            <div class="stat-card" style="border-left:4px solid var(--purple-400,#a855f7);">
                <b style="font-size:1.3rem;"><?= number_format((int) $planDistribution['total_members']) ?></b>
                <small>Active members</small>
            </div>
            <table style="margin-top:.75rem;">
                <tbody>
                <?php foreach ($planDistribution['by_tier'] as $tier => $info): ?>
                    <tr>
                        <td><b><?= e($tier) ?></b></td>
                        <td style="text-align:right;"><?= number_format((int) $info['members']) ?> members</td>
                        <td style="text-align:right;">$<?= number_format((float) $info['mrr'], 0) ?>/mo</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</details>

<?php // Support ticket workload. ?>
<div class="sys-card" style="margin-top:var(--spacing-lg);">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap;">
        <h2 style="margin:0;">Support</h2>
        <a href="<?= url('/admin/support') ?>" class="btn btn-sm">Support inbox</a>
    </div>
    <div class="stat-cards" style="margin-top:.75rem;">
        <div class="stat-card"><b><?= number_format((int) $supportStats['open']) ?></b><small>Open tickets</small></div>
        <div class="stat-card"><b><?= number_format((int) $supportStats['closed']) ?></b><small>Resolved</small></div>
        <div class="stat-card"><b><?= number_format((int) $supportStats['new_7d']) ?></b><small>New (7 days)</small></div>
        <div class="stat-card"><b><?= number_format((int) $supportStats['new_30d']) ?></b><small>New (30 days)</small></div>
        <div class="stat-card"><b><?= number_format((int) $supportStats['avg_response_min']) ?>m</b><small>Avg first response</small></div>
    </div>
</div>

<div class="sys-card" style="margin-top:var(--spacing-lg);">
    <h2>Recent activity</h2>
    <?php if (empty($feed)): ?>
        <p class="muted">Nothing recorded yet — activity appears here as actions are taken. See <a href="<?= url('/admin/logs') ?>">Logs</a>.</p>
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

