<?php
$title = 'Emailer';
$config = $config ?? [];

// What each mail actually is, and whether it is still gated by a switch.
$mailTypes = [
    'chat' => [
        'label'   => 'New chat reply',
        'gate'    => 'always',
        'detail'  => 'The operator answers a member in the operator chat (panel or Android app). One email to that member only, honouring their <em>new reply</em> preference and the global opt-out.',
    ],
    'gallery' => [
        'label'   => "Today's new gallery",
        'gate'    => 'broadcast',
        'detail'  => 'ONE email the day a gallery is posted, to every member who wants gallery alerts. A second gallery published the same day does not send another one.',
    ],
    'live' => [
        'label'   => 'Live start',
        'gate'    => 'broadcast',
        'detail'  => 'The member starts a live stream and everyone who wants live alerts is told once per stream.',
    ],
    'lifecycle' => [
        'label'   => 'Renewal reminder (7 days before a rebill)',
        'gate'    => 'toggle',
        'detail'  => 'Housekeeping finds a paid membership whose <code>expires_at</code> is within 7 days and mails the member once.',
    ],
    'winback' => [
        'label'   => 'Win-back',
        'gate'    => 'toggle',
        'detail'  => 'A member whose subscription lapsed 7+ days ago is invited back. Respects the global opt-out.',
    ],
    'billing' => [
        'label'   => 'Billing: payment failed / past due / expired',
        'gate'    => 'always',
        'detail'  => 'Sent when Braintree or PayPal reports a failure, a suspension or an expiry. Account-critical, so it ignores the opt-out and is never capped.',
    ],
];

$kindLabels = [
    'gallery'      => "Today's new gallery",
    'live'         => 'Live start',
    'chat'         => 'New chat reply',
    'lifecycle'    => 'Renewal / win-back / billing',
    'notification' => 'Other notification',
];

$broadcastsOn = !empty($config['enabled']);
$cap          = (int) ($config['notification_daily_cap'] ?? 1);
?>

<?php // ----- Status ----- ?>
<div class="stats-grid u-mb-1">
    <div class="stats-panel">
        <h2>What this sends</h2>
        <p class="muted" style="margin:.25rem 0;">
            There is no digest any more. The scheduled newsletter — every member, every day, a grid of the newest uploads —
            was removed. What is left is exactly these, and nothing else:
        </p>
        <table>
            <thead>
                <tr><th class="u-left">Email</th><th class="u-left">When</th></tr>
            </thead>
            <tbody>
                <?php foreach ($mailTypes as $type): ?>
                    <tr>
                        <td style="padding:.35rem .5rem;"><strong><?= e($type['label']) ?></strong></td>
                        <td style="padding:.35rem .5rem;" class="muted"><?= $type['detail'] ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="stats-panel">
        <h2>Queue</h2>
        <p class="muted" style="margin:.25rem 0;">
            <strong><?= number_format((int) $queueCounts['queued']) ?></strong> queued &middot;
            <?= number_format((int) $queueCounts['sent']) ?> sent &middot;
            <?= number_format((int) $queueCounts['failed']) ?> failed
        </p>
        <p class="muted" style="margin:.4rem 0;">Delivered by the cron worker every 5 minutes, with retry backoff on a transient SMTP failure.</p>
        <h3 style="margin:.75rem 0 .25rem;">By type</h3>
        <?php if (empty($kindCounts)): ?>
            <?= admin_empty('mail', 'Nothing queued yet', 'Use a test send below to verify the pipeline.') ?>
        <?php else: ?>
            <ul style="margin:0;padding-left:1.1rem;">
                <?php foreach ($kindCounts as $kind => $count): ?>
                    <li class="muted"><?= e($kindLabels[$kind] ?? $kind) ?>: <strong><?= number_format((int) $count) ?></strong></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>

<?php // ----- Settings ----- ?>
<div class="stats-panel u-mb-1">
    <h2>Switches</h2>
    <form method="post" action="<?= url('/admin/emailer/save') ?>">
        <?= csrf_field() ?>

        <p>
            <label class="chip"><input type="checkbox" name="enabled" value="1"<?= $broadcastsOn ? ' checked' : '' ?>> Send the broadcast emails (today's new gallery + live start)</label>
        </p>
        <p class="muted u-fs-08">
            Off stops those two only. A chat reply, a support answer, a renewal reminder, a win-back and any billing notice are
            transactional and are still delivered.
        </p>

        <p>
            <label class="chip"><input type="checkbox" name="lifecycle_renewal_reminders" value="1"<?= !empty($config['lifecycle_renewal_reminders']) ? ' checked' : '' ?>> Send a renewal reminder 7 days before a paid membership renews</label>
        </p>
        <p>
            <label class="chip"><input type="checkbox" name="lifecycle_winbacks" value="1"<?= !empty($config['lifecycle_winbacks']) ? ' checked' : '' ?>> Send a win-back email to lapsed members who haven't opted out (7 days after expiry/cancel)</label>
        </p>
        <p>
            <label class="chip"><input type="checkbox" name="lifecycle_trial_endings" value="1"<?= !empty($config['lifecycle_trial_endings']) ? ' checked' : '' ?>> Nudge free trials 3 days before they end (upgrade prompt; opted-out members skipped)</label>
        </p>
        <p>
            <label class="chip"><input type="checkbox" name="lifecycle_inactive_winbacks" value="1"<?= !empty($config['lifecycle_inactive_winbacks']) ? ' checked' : '' ?>> Win-back members with no membership who haven't visited for 30 days</label>
        </p>
        <p class="muted u-fs-08">
            Each lifecycle email is sent at most once per subscription, keyed in <code>subscription_email_log</code>;
            member-level nudges (trial ending, inactive win-back) are once per member in <code>retention_email_log</code>.
        </p>

        <p>
            <label for="notification_daily_cap">Broadcast emails per member per day</label><br>
            <input type="number" name="notification_daily_cap" id="notification_daily_cap" min="0" max="10" value="<?= $cap ?>" style="width:120px;">
        </p>
        <p class="muted u-fs-08">
            Applies to the gallery and live emails separately. At <code>1</code> a member gets one email the day a gallery is
            posted and one when a stream starts — not one per publication. Chat replies and billing mail are never capped.
            Set <code>0</code> to remove the cap.
        </p>

        <button type="submit" class="btn">Save settings</button>
    </form>
</div>

<?php // ----- Lifecycle ----- ?>
<div class="stats-panel u-mb-1">
    <h2>Test a template</h2>
    <p class="muted u-fs-085">
        Sends one email to <code><?= e((string) env_value('ADMIN_EMAIL', '(no ADMIN_EMAIL set)')) ?></code> with sample data.
        Nothing is queued and no member is contacted.
    </p>
    <table>
        <thead>
            <tr><th class="u-left">Email</th><th>Sent</th><th class="u-right">Test to admin</th></tr>
        </thead>
        <tbody>
            <?php
            $testable = [
                'new_gallery'      => ["Today's new gallery", 'gallery'],
                'live_now'         => ['Live start', 'live'],
                'chat_reply'       => ['New chat reply', 'chat'],
                'renewal_reminder' => ['Renewal reminder', 'lifecycle'],
                'winback'          => ['Win-back', 'lifecycle'],
                'payment_failed'   => ['Payment failed', 'lifecycle'],
                'past_due'         => ['Past due', 'lifecycle'],
                'expired'          => ['Expired', 'lifecycle'],
            ];
            ?>
            <?php foreach ($testable as $kind => [$label, $kindKey]): ?>
                <tr>
                    <td><?= e($label) ?></td>
                    <td><?= number_format((int) ($kindCounts[$kindKey] ?? 0)) ?></td>
                    <td class="u-right">
                        <form class="inline" method="post" action="<?= url('/admin/emailer/test-lifecycle/' . e($kind)) ?>">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-sm btn-outline">Send test</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php // ----- Recent queue ----- ?>
<?php
use App\Core\AdminTable;

$filterStatus = $filterStatus ?? '';
$filterKind   = $filterKind ?? '';
$queueBase    = url('/admin/emailer');
?>
<div class="stats-panel">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;">
        <h2 class="u-m0">Recent emails</h2>
        <div class="adm-list-head-aside">
            <span class="muted u-fs-085">Failed rows can be retried.</span>
            <?php if ((int) ($queueCounts['failed'] ?? 0) > 0): ?>
                <form method="post" action="<?= url('/admin/emailer/retry-all') ?>"
                      data-confirm="Move every failed email back to the queue?">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-sm">Retry all failed (<?= (int) $queueCounts['failed'] ?>)</button>
                </form>
            <?php endif; ?>
            <a class="btn btn-sm btn-outline" href="<?= e($queueBase . '/export' . ($filterStatus !== '' || $filterKind !== '' ? '?' . http_build_query(array_filter(['status' => $filterStatus, 'kind' => $filterKind])) : '')) ?>">Export CSV</a>
        </div>
    </div>

    <?php // The queue filters. They are part of the URL, so the export link
          // above can carry them and a shared link shows the same list. ?>
    <?= AdminTable::filterForm([
        'action' => $queueBase,
        'filters' => [
            [
                'name'       => 'status',
                'label'      => 'Status',
                'value'      => $filterStatus,
                'autosubmit' => true,
                'options'    => [
                    ''       => 'All statuses',
                    'queued' => 'Queued',
                    'sent'   => 'Sent',
                    'failed' => 'Failed',
                ],
            ],
            [
                'name'       => 'kind',
                'label'      => 'Type',
                'value'      => $filterKind,
                'autosubmit' => true,
                'options'    => ['' => 'All types'] + array_combine(
                    array_keys($kindLabels),
                    array_values($kindLabels)
                ),
            ],
        ],
        'actions' => '<button class="btn btn-sm" type="submit">Apply</button>'
            . ($filterStatus !== '' || $filterKind !== ''
                ? ' <a class="btn btn-sm btn-outline" href="' . e($queueBase) . '">Clear</a>'
                : ''),
    ]) ?>

    <?php if (empty($recent)): ?>
        <?= admin_empty('send', 'Nothing sent yet', 'Use a test send above to verify the pipeline.') ?>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Type</th>
                    <th>Email</th>
                    <th>Subject</th>
                    <th>Status</th>
                    <th>Attempts</th>
                    <th>Error / When</th>
                    <th class="u-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recent as $row): ?>
                    <tr>
                        <td><?= (int) $row['id'] ?></td>
                        <td><?= e($kindLabels[(string) $row['kind']] ?? (string) $row['kind']) ?></td>
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
                                <span class="muted"><?= e(tzdate('Y-m-d H:i', (string) ($row['sent_at'] ?: $row['created_at']))) ?></span>
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
