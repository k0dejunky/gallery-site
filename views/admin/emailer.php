<?php
$title = 'Emailer';
$config = $config ?? [];
$dowNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

switch ($config['mode'] ?? 'daily') {
    case 'hourly':
        $scheduleLabel = 'Every ' . (int) $config['every_hours'] . ' hour' . ((int) $config['every_hours'] === 1 ? '' : 's');
        break;
    case 'weekly':
        $scheduleLabel = $dowNames[(int) $config['day_of_week']] . ' at ' . sprintf('%02d:%02d', (int) $config['hour'], (int) $config['minute']);
        break;
    default:
        $scheduleLabel = 'Daily at ' . sprintf('%02d:%02d', (int) $config['hour'], (int) $config['minute']);
}
?>

<?php // ----- Status ----- ?>
<div class="stats-grid" style="margin-bottom:1rem;">
    <div class="stats-panel">
        <h2>Status</h2>
        <?php if (!empty($config['enabled'])): ?>
            <p class="muted" style="margin:.25rem 0;">
                <span style="color:var(--success,#2e7d32);font-weight:600;">&#10003; Enabled</span> &middot;
                sends <strong><?= e($scheduleLabel) ?></strong> in <strong><?= e((string) ($config['timezone'] ?? 'UTC')) ?></strong>.
            </p>
        <?php else: ?>
            <p class="muted" style="margin:.25rem 0;">
                <span style="color:#b3261e;font-weight:600;">Disabled</span> &middot; no digests are scheduled.
            </p>
        <?php endif; ?>
        <p class="muted" style="margin:.4rem 0;">
            Next send: <strong><?= $nextSend !== null ? e($nextSend) . ' (' . e((string) ($config['timezone'] ?? 'UTC')) . ')' : '—' ?></strong><br>
            Last sent: <strong><?= !empty($config['last_sent_at']) ? e((string) $config['last_sent_at']) . ' UTC' : 'never' ?></strong><br>
            Last watermark photo: <strong>#<?= (int) ($config['last_sent_photo_id'] ?? 0) ?></strong>
        </p>
        <p class="muted" style="margin:0;">
            <?= e(($config['subject_subscriber'] ?? '') !== '' ? str_replace(['{site}', '{count}'], [config('app.site_name'), '<i>N</i>'], (string) $config['subject_subscriber']) : '') ?> &middot; subscriber subject placeholder <code>{site}</code> / <code>{count}</code>
        </p>
    </div>
    <div class="stats-panel">
        <h2>Recipients &amp; queue</h2>
        <p class="muted" style="margin:.25rem 0;">
            Subscribers: <strong><?= number_format((int) $recipientCounts['subscriber']) ?></strong> &middot;
            Non-subscribers: <strong><?= number_format((int) $recipientCounts['non_subscriber']) ?></strong>
        </p>
        <p class="muted" style="margin:.4rem 0;">
            Queue: <strong><?= number_format((int) $queueCounts['queued']) ?></strong> queued &middot;
            <?= number_format((int) $queueCounts['sent']) ?> sent &middot;
            <?= number_format((int) $queueCounts['failed']) ?> failed
        </p>
        <p class="muted" style="margin:.4rem 0;">Subscribers see sharp thumbnails + a link into the gallery; non-subscribers see the public blurred previews + a link to the membership plans. Each email carries a one-click unsubscribe link.</p>
    </div>
</div>

<?php // ----- Sample preview ----- ?>
<div class="stats-panel" style="margin-bottom:1rem;">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;">
        <h2 style="margin:0;">Next digest sample</h2>
        <span class="muted" style="font-size:.85rem;"><?= (int) count($samples) ?> newest image upload(s) — what the next digest will show.</span>
    </div>
    <?php if (empty($samples)): ?>
        <p class="muted">No image uploads yet. Upload photos to a gallery first.</p>
    <?php else: ?>
        <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-top:.75rem;">
            <?php foreach ($samples as $photo): ?>
                <img src="<?= e(file_url((string) $photo['filename'], 'thumb')) ?>" alt="" width="80" height="60" style="border-radius:6px;object-fit:cover;background:#000;">
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-top:.9rem;">
        <form class="inline" method="post" action="<?= url('/admin/emailer/test') ?>" style="display:inline-flex;gap:.4rem;align-items:center;">
            <?= csrf_field() ?>
            <select name="audience" style="padding:.3rem .5rem;">
                <option value="subscriber">Subscriber preview</option>
                <option value="non_subscriber">Non-subscriber preview</option>
            </select>
            <button type="submit" class="btn btn-sm">Send test email</button>
        </form>
        <form class="inline" method="post" action="<?= url('/admin/emailer/send-now') ?>" onsubmit="return confirm('Queue a digest to ALL eligible recipients right now?');">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-sm" style="background:var(--success,#2e7d32);color:#fff;">Send now</button>
        </form>
    </div>
</div>

<?php // ----- Settings ----- ?>
<div class="stats-panel" style="margin-bottom:1rem;">
    <h2>Schedule &amp; content settings</h2>
    <form method="post" action="<?= url('/admin/emailer/save') ?>">
        <?= csrf_field() ?>

        <p>
            <label class="chip"><input type="checkbox" name="enabled" value="1"<?= !empty($config['enabled']) ? ' checked' : '' ?>> Enable the emailer (digests go out on the schedule below)</label>
        </p>

        <p>
            <label for="mode">How often</label><br>
            <select name="mode" id="mode" style="min-width:200px;">
                <option value="hourly"<?= ($config['mode'] ?? '') === 'hourly' ? ' selected' : '' ?>>Hourly</option>
                <option value="daily"<?= ($config['mode'] ?? '') === 'daily' ? ' selected' : '' ?>>Daily</option>
                <option value="weekly"<?= ($config['mode'] ?? '') === 'weekly' ? ' selected' : '' ?>>Weekly</option>
            </select>
        </p>

        <p id="row-hourly" style="display:none;">
            <label for="every_hours">Every N hours</label><br>
            <input type="number" name="every_hours" id="every_hours" min="1" max="720" value="<?= (int) ($config['every_hours'] ?? 6) ?>" style="width:120px;">
        </p>

        <p id="row-clock">
            <label><?= ($config['mode'] ?? 'daily') === 'weekly' ? 'Day &amp; time' : 'Time of day' ?></label><br>
            <span id="row-dow" style="display:none;">
                <select name="day_of_week">
                    <?php foreach ($dowNames as $di => $day): ?>
                        <option value="<?= $di ?>"<?= (int) ($config['day_of_week'] ?? 1) === $di ? ' selected' : '' ?>><?= $day ?></option>
                    <?php endforeach; ?>
                </select>
            </span>
            <input type="number" name="hour" min="0" max="23" value="<?= (int) ($config['hour'] ?? 9) ?>" style="width:80px;" title="Hour (0-23)"> :
            <input type="number" name="minute" min="0" max="59" value="<?= (int) ($config['minute'] ?? 0) ?>" style="width:80px;" title="Minute (0-59)">
            <span class="muted" style="font-size:.8rem;">(24-hour)</span>
        </p>

        <p>
            <label for="timezone">Schedule timezone</label><br>
            <select name="timezone" id="timezone" style="min-width:200px;">
                <?php foreach ($timezones as [$value, $label]): ?>
                    <option value="<?= e($value) ?>"<?= ($config['timezone'] ?? 'UTC') === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </p>

        <p>
            <label for="sample_count">Photos per digest</label><br>
            <input type="number" name="sample_count" id="sample_count" min="1" max="<?= \App\Models\EmailerConfig::MAX_SAMPLE ?>" value="<?= (int) ($config['sample_count'] ?? 6) ?>" style="width:120px;">
            <span class="muted" style="font-size:.8rem;">1&ndash;<?= \App\Models\EmailerConfig::MAX_SAMPLE ?> of the newest uploads</span>
        </p>

        <p>
            <label class="chip"><input type="checkbox" name="include_non_subscribers" value="1"<?= !empty($config['include_non_subscribers']) ? ' checked' : '' ?>> Also email non-subscribers (blurred previews + a membership teaser)</label>
        </p>

        <p>
            <label for="subject_subscriber">Subscriber subject <span class="muted">(<code>{site}</code>, <code>{count}</code>)</span></label><br>
            <input type="text" name="subject_subscriber" id="subject_subscriber" value="<?= e((string) ($config['subject_subscriber'] ?? '')) ?>" maxlength="200" style="width:100%;box-sizing:border-box;">
        </p>

        <p>
            <label for="subject_non_subscriber">Non-subscriber subject <span class="muted">(<code>{site}</code>, <code>{count}</code>)</span></label><br>
            <input type="text" name="subject_non_subscriber" id="subject_non_subscriber" value="<?= e((string) ($config['subject_non_subscriber'] ?? '')) ?>" maxlength="200" style="width:100%;box-sizing:border-box;">
        </p>

        <button type="submit" class="btn">Save settings</button>
    </form>
</div>

<?php // ----- Recent queue ----- ?>
<div class="stats-panel">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;">
        <h2 style="margin:0;">Recent emails</h2>
        <span class="muted" style="font-size:.85rem;">Sent by the cron worker (queue) or by "Send now" / tests. Failed rows can be retried.</span>
    </div>
    <?php if (empty($recent)): ?>
        <p class="muted">Nothing sent yet. Use "Send test email" to verify the pipeline.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Audience</th>
                    <th>Email</th>
                    <th>Subject</th>
                    <th>Status</th>
                    <th>Attempts</th>
                    <th>Error / When</th>
                    <th style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recent as $row): ?>
                    <tr>
                        <td><?= (int) $row['id'] ?></td>
                        <td><?= $row['audience'] === 'non_subscriber' ? 'free' : 'member' ?></td>
                        <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= e((string) $row['email']) ?></td>
                        <td style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= e((string) $row['subject']) ?></td>
                        <td>
                            <?php if ($row['status'] === 'sent'): ?>
                                <span style="color:var(--success,#2e7d32);font-weight:600;">sent</span>
                            <?php elseif ($row['status'] === 'failed'): ?>
                                <span style="color:#b3261e;font-weight:600;">failed</span>
                            <?php else: ?>
                                <span style="color:#b45309;font-weight:600;">queued</span>
                            <?php endif; ?>
                        </td>
                        <td><?= (int) $row['attempts'] ?></td>
                        <td style="max-width:200px;font-size:.8rem;">
                            <?php if (!empty($row['error'])): ?>
                                <span class="muted"><?= e((string) $row['error']) ?></span>
                            <?php else: ?>
                                <span class="muted"><?= e((string) ($row['sent_at'] ?: $row['created_at'])) ?></span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:right;white-space:nowrap;">
                            <?php if ($row['status'] === 'failed'): ?>
                                <form class="inline" method="post" action="<?= url('/admin/emailer/retry') ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="queue_id" value="<?= (int) $row['id'] ?>">
                                    <button type="submit" class="btn btn-sm">Retry</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<script>
(function () {
    var mode = document.getElementById('mode');
    var rowHourly = document.getElementById('row-hourly');
    var rowDow = document.getElementById('row-dow');
    var rowClock = document.getElementById('row-clock');

    function update() {
        var m = mode.value;
        rowHourly.style.display = m === 'hourly' ? '' : 'none';
        rowClock.style.display = m === 'hourly' ? 'none' : '';
        if (rowDow) rowDow.style.display = m === 'weekly' ? '' : 'none';
    }

    mode.addEventListener('change', update);
    update();
})();
</script>