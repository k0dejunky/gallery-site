<?php $title = 'System'; ?>

<style>
    .sys-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 1rem; margin-top: 1rem; }
    .sys-card { background: var(--card-bg); border: 1px solid var(--card-border); border-radius: var(--border-radius); padding: 1rem; }
    .sys-card h2 { margin: 0 0 .5rem; font-size: var(--font-size-lg); color: var(--card-title-color); }
    .sys-card table { width: 100%; font-size: var(--font-size-sm); }
    .sys-card td, .sys-card th { padding: .25rem .35rem; text-align: left; border-bottom: 1px solid var(--table-border); }
    .sys-actions { display: flex; flex-wrap: wrap; gap: .5rem; margin-top: .75rem; }
    .sys-ok { color: #15803d; font-weight: bold; }
    .sys-bad { color: var(--btn-danger-color, #b91c1c); font-weight: bold; }
    /* Table-backed cards get their own full-width row below the compact ones */
    .sys-stack { display: flex; flex-direction: column; gap: 1rem; margin-top: 1rem; }
    .sys-stack .sys-card { width: 100%; }
</style>

<h1 class="section-title">System</h1>
<p class="muted">Disk free: <b><?= $diskFree !== false ? number_format((float) $diskFree / 1048576) . ' MB' : 'unknown' ?></b></p>

<script>
// Keep the page scroll position when a system button posts a form and the
// page reloads, so admins are not snapped back to the top after an action.
(function () {
    var key = 'system-scroll-pos';
    var saved = sessionStorage.getItem(key);
    if (saved !== null) {
        sessionStorage.removeItem(key);
        try { window.scrollTo(0, parseInt(saved, 10) || 0); } catch (e) {}
    }
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form || !form.matches('form')) return;
        if (form.getAttribute('data-no-progress') !== null) return;
        try { sessionStorage.setItem(key, String(window.scrollY || 0)); } catch (e) {}
    }, true);
})();
</script>

<div class="sys-grid">
    <!-- Operational diagnostics -->
    <div class="sys-card">
        <h2>Operational diagnostics</h2>
        <table>
            <tr><th>Database</th><td class="<?= $diagnostics['db'] ? 'sys-ok' : 'sys-bad' ?>"><?= $diagnostics['db'] ? 'connected' : 'unavailable' ?></td></tr>
            <tr><th>Storage writable</th><td class="<?= $diagnostics['storage'] ? 'sys-ok' : 'sys-bad' ?>"><?= $diagnostics['storage'] ? 'yes' : 'no' ?></td></tr>
            <tr><th>SMTP configured</th><td><?= $diagnostics['smtp'] ? 'yes' : 'no' ?></td></tr>
            <tr><th>PayPal</th><td><?= $diagnostics['paypal']['configured'] ? 'configured' : 'not configured' ?><?= $diagnostics['paypal']['enabled'] ? ' / enabled' : '' ?></td></tr>
            <tr><th>Migrations</th><td><?= $diagnostics['migrations']['table'] ? (int) $diagnostics['migrations']['applied'] . ' applied, ' . (int) $diagnostics['migrations']['pending'] . ' pending' : 'table unavailable' ?></td></tr>
        </table>
        <form class="sys-actions" method="post" action="<?= url('/admin/system/smtp-test') ?>">
            <?= csrf_field() ?>
            <button class="btn" type="submit">Test SMTP</button>
            <span class="muted">Send a test email to <?= e(\App\Core\Mailer::adminEmail() ?: '(no admin email)') ?></span>
        </form>
    </div>

    <!-- Video export queue -->
    <div class="sys-card">
        <h2>Background jobs</h2>
        <table>
            <tr><th>Export worker</th><td class="<?= $exportQueue['service_active'] ? 'sys-ok' : 'sys-bad' ?>"><?= $exportQueue['service_active'] ? 'installed' : 'not installed' ?></td></tr>
            <tr><th>Queued</th><td class="<?= $exportQueue['queued'] ? 'sys-bad' : 'sys-ok' ?>"><?= (int) $exportQueue['queued'] ?></td></tr>
            <tr><th>Running</th><td class="<?= $exportQueue['running'] ? '' : 'sys-ok' ?>"><?= (int) $exportQueue['running'] ?><?= $exportQueue['stale'] ? ' <span class="sys-bad">(' . (int) $exportQueue['stale'] . ' stale)</span>' : '' ?></td></tr>
            <tr><th>Completed</th><td><?= (int) $exportQueue['completed'] ?></td></tr>
            <tr><th>Failed</th><td class="<?= $exportQueue['failed'] ? 'sys-bad' : 'sys-ok' ?>"><?= (int) $exportQueue['failed'] ?></td></tr>
            <tr><th>Last export</th><td class="muted"><?= $exportQueue['latest'] ? e((string) $exportQueue['latest']) : 'never' ?></td></tr>
        </table>
    </div>

    <!-- Photo edit queue -->
    <div class="sys-card">
        <h2>Photo edit queue</h2>
        <table>
            <tr><th>Edit worker</th><td class="<?= $photoEditQueue['service_active'] ? 'sys-ok' : 'sys-bad' ?>"><?= $photoEditQueue['service_active'] ? 'installed' : 'not installed' ?></td></tr>
            <tr><th>Queued</th><td class="<?= $photoEditQueue['queued'] ? 'sys-bad' : 'sys-ok' ?>"><?= (int) $photoEditQueue['queued'] ?></td></tr>
            <tr><th>Running</th><td><?= (int) $photoEditQueue['running'] ?></td></tr>
            <tr><th>Completed</th><td><?= (int) $photoEditQueue['completed'] ?></td></tr>
            <tr><th>Failed</th><td class="<?= $photoEditQueue['failed'] ? 'sys-bad' : 'sys-ok' ?>"><?= (int) $photoEditQueue['failed'] ?></td></tr>
            <tr><th>Last edit</th><td class="muted"><?= $photoEditQueue['latest'] ? e((string) $photoEditQueue['latest']) : 'never' ?></td></tr>
        </table>
    </div>

    <!-- Maintenance mode + housekeeping -->
    <div class="sys-card">
        <h2>Site status</h2>
        <?php if (!empty($maintenance)): ?>
            <p><span class="sys-bad">MAINTENANCE MODE ON</span> — visitors see a downtime page; staff can still browse.</p>
            <form class="sys-actions" method="post" action="<?= url('/admin/system/maintenance') ?>">
                <?= csrf_field() ?><input type="hidden" name="mode" value="off">
                <button class="btn" type="submit">Turn off maintenance mode</button>
            </form>
        <?php else: ?>
            <p class="muted">Site is live for everyone.</p>
            <form class="sys-actions" method="post" action="<?= url('/admin/system/maintenance') ?>"
                  onsubmit="return confirm('Take the site down for everyone except staff?');">
                <?= csrf_field() ?><input type="hidden" name="mode" value="on">
                <button class="btn btn-danger" type="submit">Enable maintenance mode</button>
            </form>
        <?php endif; ?>

        <h2 style="margin-top:1rem;">Scheduled housekeeping</h2>
        <?php $cronState = ($cronAgeMin === null) ? 'never run'
            : (($cronAgeMin > 45) ? '<b style="color:#b45309;">last run ' . (int) $cronAgeMin . ' min ago</b>'
                                  : 'last run ' . (int) $cronAgeMin . ' min ago'); ?>
        <p class="muted" style="font-size:.85rem;">
            Expires overdue subscriptions, removes staging folders idle 72h+, keeps the
            <?= (int) $retention ?> newest backups (HOUSEKEEPING_KEEP_BACKUPS), snapshots storage usage.
            Cron endpoint: <b><?= $cronKeySet ? 'configured ✓' : 'GALLERY_CRON_KEY missing in .env' ?></b> ·
            <?= $cronState ?>
        </p>
        <form class="sys-actions" method="post" action="<?= url('/admin/system/housekeeping') ?>">
            <?= csrf_field() ?>
            <button class="btn" type="submit">Run housekeeping now</button>
        </form>
    </div>

    <!-- Media variants -->
    <div class="sys-card">
        <h2>Media thumbnails</h2>
        <?php if ($variants['running']): ?>
            <p><b style="color:#b45309;">Regeneration in progress… refresh to update.</b></p>
        <?php endif; ?>
        <p class="muted" style="font-size:.85rem;">
            <?= (int) $variants['total'] ?> media items ·
            missing thumbs: <b class="<?= $variants['missing_thumb'] ? 'sys-bad' : 'sys-ok' ?>"><?= (int) $variants['missing_thumb'] ?></b> ·
            missing web copies: <b class="<?= $variants['missing_web'] ? 'sys-bad' : 'sys-ok' ?>"><?= (int) $variants['missing_web'] ?></b> ·
            originals gone: <b class="<?= $variants['broken'] ? 'sys-bad' : 'sys-ok' ?>"><?= (int) $variants['broken'] ?></b>
        </p>
        <form class="sys-actions" method="post" action="<?= url('/admin/system/variants') ?>">
            <?= csrf_field() ?>
            <button class="btn" type="submit"<?= !empty($variants['running']) ? ' disabled' : '' ?>>Regenerate missing</button>
        </form>
        <?php if (!empty($storageTrend['gb'])): ?>
            <h2 style="margin-top:1rem;">Storage growth (GB)</h2>
            <?= \App\Core\Charts::sparkline($storageTrend['gb'], 300, 48, '#0ea5e9') ?>
            <p class="muted" style="margin:.25rem 0 0;font-size:.8rem;">
                <?= e((string) reset($storageTrend['gb'])) ?> GB → <b><?= e((string) end($storageTrend['gb'])) ?> GB</b>
                over last <?= count($storageTrend['gb']) ?> day(s)
            </p>
        <?php endif; ?>
    </div>

    <!-- CSV exports -->
    <div class="sys-card">
        <h2>Exports</h2>
        <p class="muted" style="font-size:.85rem;">Download current data as CSV for spreadsheets and accounting.</p>
        <div class="sys-actions">
            <a class="btn" href="<?= url('/admin/export/users') ?>">Users CSV</a>
            <a class="btn" href="<?= url('/admin/export/subscriptions') ?>">Subscriptions CSV</a>
        </div>
    </div>
</div>

<!-- Cards that contain tables: one per row, full width -->
<div class="sys-stack">
    <!-- Cron jobs -->
    <div class="sys-card">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:.5rem;flex-wrap:wrap;">
            <h2>Scheduled tasks (cron)</h2>
            <?php $cronOkCount = count(array_filter($cronJobs ?? [], fn($j) => !empty($j['ok']))); ?>
            <span class="muted" style="font-size:.85rem;">
                <?= $cronJobs === null || $cronJobs === [] ? 'no cron jobs detected' : (int) $cronOkCount . ' of ' . count($cronJobs) . ' healthy' ?>
            </span>
        </div>
        <?php if (empty($cronJobs)): ?>
            <p class="muted" style="margin-top:.5rem;font-size:.85rem;">
                No scheduled-task log files found. Install the cron entries from
                <code>docs/MIGRATION.md</code> (gallery-housekeeping, gallery-autopost,
                gallery-backup, gallery-restore-drill) and they will appear here.
            </p>
        <?php else: ?>
            <table>
                <tr><th>Task</th><th>Runs</th><th>Last run</th><th>Status</th><th>Details</th></tr>
                <?php foreach ($cronJobs as $job): ?>
                    <tr>
                        <td><b><?= e((string) $job['id']) ?></b><br>
                            <span class="muted" style="font-size:.8rem;"><?= e((string) $job['desc']) ?></span></td>
                        <td class="muted"><?= e((string) $job['schedule']) ?></td>
                        <td><?= $job['lastRun'] ? e((string) $job['lastRun']) . ' <span class="muted">(' . e((string) $job['lastAgo']) . ')</span>' : '<span class="muted">never</span>' ?></td>
                        <td>
                            <?php if ($job['ok']): ?>
                                <b class="sys-ok">&#10003; ok</b>
                            <?php else: ?>
                                <b class="sys-bad">&#9888; <?= $job['lastRun'] ? 'stale / failed' : 'not run' ?></b>
                            <?php endif; ?>
                        </td>
                        <td class="muted" style="font-size:.83rem;overflow-wrap:anywhere;"><?= $job['note'] ? e((string) $job['note']) : '&mdash;' ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>

        <?php if (!empty($cronScheduleIsSuper)): ?>
            <div style="border-top:1px solid var(--table-border);margin-top:1rem;padding-top:1rem;">
                <h3 style="margin:0 0 .25rem;text-align:left;color:var(--card-title-color);">Configure schedules</h3>
                <p class="muted" style="margin:0 0 .75rem;font-size:.82rem;">
                    Changes are written to <code>storage/cron/schedules.json</code> and applied to
                    <code>/etc/cron.d/</code> immediately, restarting the two worker services.
                </p>
                <form method="post" action="<?= url('/admin/system/cron-schedule') ?>" style="display:flex;flex-wrap:wrap;gap:1rem;align-items:flex-end;">
                    <?= csrf_field() ?>
                    <div>
                        <label style="font-size:.8rem;">Housekeeping — every</label><br>
                        <input type="number" name="cron_housekeeping_min" min="1" max="1440" value="<?= (int) ($cronSchedule['housekeeping']['every_minutes'] ?? 15) ?>">
                        <span class="muted" style="font-size:.75rem;">min</span>
                    </div>
                    <div>
                        <label style="font-size:.8rem;">Autopost — every</label><br>
                        <input type="number" name="cron_autopost_min" min="1" max="1440" value="<?= (int) ($cronSchedule['autopost']['every_minutes'] ?? 1) ?>">
                        <span class="muted" style="font-size:.75rem;">min</span>
                    </div>
                    <div>
                        <label style="font-size:.8rem;">Backup — daily at</label><br>
                        <input type="number" name="cron_backup_hour" min="0" max="23" value="<?= (int) ($cronSchedule['backup']['hour'] ?? 3) ?>" style="width:3.4rem;">
                        : <input type="number" name="cron_backup_minute" min="0" max="59" value="<?= (int) ($cronSchedule['backup']['minute'] ?? 0) ?>" style="width:3.4rem;">
                    </div>
                    <div>
                        <label style="font-size:.8rem;">Restore drill —</label><br>
                        <select name="cron_drill_dow" style="padding:.3rem;">
                            <?php $dow = (int) ($cronSchedule['restore-drill']['dow'] ?? 0); ?>
                            <?php foreach ([0=>'Sunday',1=>'Monday',2=>'Tuesday',3=>'Wednesday',4=>'Thursday',5=>'Friday',6=>'Saturday'] as $d=>$label): ?>
                                <option value="<?= $d ?>"<?= $d === $dow ? ' selected' : '' ?>><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="number" name="cron_drill_hour" min="0" max="23" value="<?= (int) ($cronSchedule['restore-drill']['hour'] ?? 4) ?>" style="width:3.4rem;">
                        : <input type="number" name="cron_drill_minute" min="0" max="59" value="<?= (int) ($cronSchedule['restore-drill']['minute'] ?? 0) ?>" style="width:3.4rem;">
                    </div>
                    <button class="btn" type="submit">Save &amp; apply schedules</button>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <!-- Pending upload staging folders -->
    <div class="sys-card">
        <h2>Pending uploads</h2>
        <?php if (empty($pendingDirs)): ?>
            <p class="muted">No staging folders. Nothing to clean.</p>
        <?php else: ?>
            <table>
                <tr><th>Folder</th><th>Files</th><th>Size</th><th>Age</th><th></th></tr>
                <?php foreach ($pendingDirs as $dir): ?>
                    <tr>
                        <td><code><?= e($dir['name']) ?></code></td>
                        <td><?= (int) $dir['files'] ?></td>
                        <td><?= number_format($dir['size'] / 1048576, 1) ?> MB</td>
                        <td><?= (int) $dir['age_h'] ?>h</td>
                        <td><form class="inline" method="post" action="<?= url('/admin/system/cleanup/pending') ?>"
                                  onsubmit="return confirm('Delete this staging folder?');">
                                <?= csrf_field() ?><input type="hidden" name="dir" value="<?= e($dir['name']) ?>">
                                <button class="btn btn-sm btn-danger" type="submit">Delete</button></form></td>
                    </tr>
                <?php endforeach; ?>
            </table>
            <form class="sys-actions" method="post" action="<?= url('/admin/system/cleanup/pending') ?>"
                  onsubmit="return confirm('Delete ALL <?= count($pendingDirs) ?> staging folders?');">
                <?= csrf_field() ?><input type="hidden" name="dir" value="all">
                <button class="btn btn-danger" type="submit">Delete all</button>
            </form>
        <?php endif; ?>
    </div>

    <!-- Orphaned files -->
    <div class="sys-card">
        <h2>Orphaned upload files</h2>
        <?php if (empty($orphans)): ?>
            <p class="muted">Every file in storage/uploads belongs to a photo record.</p>
        <?php else: ?>
            <p><?= count($orphans) ?> unreferenced file(s), <?= number_format(array_sum(array_column($orphans, 'size')) / 1048576, 1) ?> MB total.
            Preview:</p>
            <table>
                <?php foreach (array_slice($orphans, 0, 8) as $orphan): ?>
                    <tr><td><code><?= e($orphan['name']) ?></code></td><td><?= number_format($orphan['size'] / 1048576, 1) ?> MB</td></tr>
                <?php endforeach; ?>
            </table>
            <form class="sys-actions" method="post" action="<?= url('/admin/system/cleanup/orphans') ?>"
                  onsubmit="return confirm('Delete all <?= count($orphans) ?> orphaned files permanently?');">
                <?= csrf_field() ?>
                <button class="btn btn-danger" type="submit">Delete orphans</button>
            </form>
        <?php endif; ?>
    </div>

    <!-- Backups -->
    <div class="sys-card">
        <h2>Backups</h2>
        <?php if (!empty($backupFailure)): ?>
            <p style="background:#fee2e2;border:1px solid #ef4444;color:#991b1b;padding:.4rem .6rem;border-radius:var(--border-radius);font-size:.85rem;">
                <b>Last run failed:</b> <?= e((string) $backupFailure) ?>
            </p>
        <?php endif; ?>
        <?php if (!empty($lastSync)): ?>
            <p style="font-size:.85rem;margin:.25rem 0 .5rem;">
                Last archive: <code><?= e((string) $lastSync['file'] ?? '') ?></code>
                at <?= e((string) $lastSync['at'] ?? '') ?>
                — verify ✓, offsite copy
                <?php if ((int) ($lastSync['sync_rc'] ?? -1) === 0): ?>
                    <b style="color:var(--success-text,#15803d);">synced ✓</b>
                <?php else: ?>
                    <b style="color:#b45309;">not configured / rc=<?= (int) $lastSync['sync_rc'] ?></b>
                <?php endif; ?>
                (BACKUP_SYNC_CMD)
            </p>
        <?php endif; ?>
        <form class="sys-actions" method="post" action="<?= url('/admin/system/backup') ?>">
            <?= csrf_field() ?>
            <button class="btn" type="submit"<?= !empty($backupRunning) ? ' disabled' : '' ?>>Create backup now</button>
            <?php if (!empty($backupRunning)): ?>
                <b style="color:#b45309;">Backup in progress… refresh to update.</b>
            <?php else: ?>
                <span class="muted">Database + uploaded media (.tar.gz, split into 4 GB parts for parallel offsite sync)</span>
            <?php endif; ?>
        </form>
        <?php if (!empty($backups)): ?>
            <table style="margin-top:.75rem;">
                <tr><th>File</th><th>Size</th><th>Created</th><th></th></tr>
                <?php foreach ($backups as $backup): ?>
                    <tr>
                        <td><code><?= e($backup['name']) ?></code></td>
                        <td><?= number_format($backup['size'] / 1048576, 1) ?> MB<?= !empty($backup['parts']) ? ' <span class="muted">(' . (int) $backup['parts'] . ' parts)</span>' : '' ?></td>
                        <td><?= date('Y-m-d H:i', $backup['time']) ?></td>
                        <td style="white-space:nowrap;">
                            <a class="btn btn-sm" href="<?= url('/admin/system/backups/' . rawurlencode($backup['name'])) ?>">Download</a>
                            <form class="inline" method="post" action="<?= url('/admin/system/backups/' . rawurlencode($backup['name']) . '/delete') ?>"
                                  onsubmit="return confirm('Delete this backup?');">
                                <?= csrf_field() ?><button class="btn btn-sm btn-danger" type="submit">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php else: ?>
            <p class="muted" style="margin-top:.5rem;">No backups yet.</p>
        <?php endif; ?>
    </div>

    <!-- Schema health -->
    <div class="sys-card">
        <h2>Schema health</h2>
        <?php if (empty($schemaDiff)): ?>
            <p><span class="sys-ok">&#10003;</span> Live database matches <code>schema.sql</code>.</p>
        <?php else: ?>
            <p><span class="sys-bad">&#9888;</span> Drift detected between <code>schema.sql</code> and the live DB:</p>
            <table>
                <?php foreach ($schemaDiff as $table => $info): ?>
                    <tr>
                        <td><code><?= e((string) $table) ?></code></td>
                        <td>
                            <?php if (!empty($info['missing_table'])): ?>
                                <span class="sys-bad">missing entirely</span>
                            <?php else: ?>
                                <?php if (!empty($info['missing_cols'])): ?>
                                    missing: <span class="sys-bad"><?= e(implode(', ', $info['missing_cols'])) ?></span>
                                <?php endif; ?>
                                <?php if (!empty($info['extra_cols'])): ?>
                                    <?= !empty($info['missing_cols']) ? ' · ' : '' ?>live-only: <?= e(implode(', ', $info['extra_cols'])) ?>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    </div>

    <!-- Database tables -->
    <div class="sys-card">
        <h2>Database</h2>
        <table>
            <tr><th>Table</th><th>Rows</th><th>Size</th><th></th></tr>
            <?php foreach ($dbTables as $table): ?>
                <tr>
                    <td><code><?= e((string) $table['name']) ?></code></td>
                    <td><?= number_format((float) $table['rows']) ?></td>
                    <td><?= e((string) $table['size_mb']) ?> MB</td>
                    <td><form class="inline" method="post" action="<?= url('/admin/system/db/optimize') ?>">
                            <?= csrf_field() ?><input type="hidden" name="table" value="<?= e((string) $table['name']) ?>">
                            <button class="btn btn-sm" type="submit">Optimize</button></form></td>
                </tr>
            <?php endforeach; ?>
        </table>
        <form class="sys-actions" method="post" action="<?= url('/admin/system/db/optimize') ?>"
              onsubmit="return confirm('Optimize every table? May take a moment.');">
            <?= csrf_field() ?><input type="hidden" name="table" value="__all">
            <button class="btn" type="submit">Optimize all</button>
        </form>
    </div>

    <!-- Login security -->
    <div class="sys-card" id="security">
        <h2>Login security</h2>
        <p style="margin:.25rem 0 .5rem;">
            Failed logins last hour:
            <b style="font-size:1.1rem;" class="<?= $security['fails_hour'] >= 25 ? 'sys-bad' : 'sys-ok' ?>">
                <?= number_format((int) $security['fails_hour']) ?>
            </b>
            <span class="muted">(lockout: <?= (int) $security['max_pair'] ?> per account+IP,
                <?= (int) $security['max_ip'] ?> per IP, <?= (int) $security['max_ip'] ?> per account —
                <?= (int) $security['window_min'] ?> min window)</span>
        </p>

        <table style="margin-top:.5rem;">
            <tr><th>Top IPs (24h)</th><th>Fails</th><th>Targeting</th></tr>
            <?php foreach ($security['top_ips'] as $row): ?>
                <tr>
                    <td><code><?= e((string) $row['ip']) ?></code></td>
                    <td><?= number_format((int) $row['c']) ?></td>
                    <td class="muted"><?= e((string) $row['emails']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$security['top_ips']): ?><tr><td colspan="3" class="muted">No failures in the last 24h.</td></tr><?php endif; ?>
        </table>

        <table style="margin-top:.75rem;">
            <tr><th>Targeted accounts (24h)</th><th>Fails</th></tr>
            <?php foreach ($security['top_emails'] as $row): ?>
                <tr><td><?= e((string) $row['email']) ?></td><td><?= number_format((int) $row['c']) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$security['top_emails']): ?><tr><td colspan="2" class="muted">None.</td></tr><?php endif; ?>
        </table>

        <?php if ($security['locked_pairs'] || $security['locked_ips']): ?>
            <p style="margin-top:.75rem;font-size:.85rem;"><b>Currently locked out:</b></p>
            <ul style="margin:.25rem 0 0;padding-left:1.25rem;font-size:.85rem;">
                <?php foreach ($security['locked_ips'] as $row): ?>
                    <li>IP <code><?= e((string) $row['ip']) ?></code> — <?= (int) $row['c'] ?> fails</li>
                <?php endforeach; ?>
                <?php foreach ($security['locked_pairs'] as $row): ?>
                    <li><?= e((string) $row['email']) ?> from <code><?= e((string) $row['ip']) ?></code> — <?= (int) $row['c'] ?> fails</li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p class="muted" style="margin-top:.5rem;">Nothing currently locked out.</p>
        <?php endif; ?>
    </div>
</div>
