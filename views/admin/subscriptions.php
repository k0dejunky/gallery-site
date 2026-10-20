<?php $title = 'Subscriptions'; ?>

<?php if (!empty($reconciliation)): ?>
<h2>Needs Attention — Pending Biller Signups</h2>
<p class="muted">Checkouts that reached a payment processor but were never confirmed by its postback. PayPal rows are auto-checked against PayPal by the scheduled reconciler; approve after verifying the payment in the biller's admin, or cancel to release the member.</p>
<form method="post" action="<?= url('/admin/system/paypal-reconcile') ?>" class="inline" style="margin-bottom:.5rem;">
    <?= csrf_field() ?>
    <button type="submit" class="btn btn-sm">Run PayPal reconciliation now</button>
</form>
<div class="users-table-wrap">
<table>
    <thead><tr><th>Membership ID</th><th>Age</th><th>User</th><th>Plan</th><th>Processor</th><th>Reference</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($reconciliation as $rec): ?>
        <?php $stale = (int) $rec['age_hours'] >= 24; ?>
        <tr<?= $stale ? ' style="background:rgba(220,38,38,.08);"' : '' ?>>
            <td><code>#<?= e((string) ($rec['membership_number'] ?? sprintf('%05d', (int) $rec['id']))) ?></code></td>
            <td title="<?= e(tzdate('Y-m-d H:i', $rec['created_at'])) ?>"><?= (int) $rec['age_hours'] ?>h<?= $stale ? ' ⚠' : '' ?></td>
            <td><?= e((string) $rec['user_email']) ?></td>
            <td><?= e((string) $rec['plan_name']) ?> ($<?= number_format((float) $rec['price'], 2) ?>)</td>
            <td><?= !empty($rec['processor_name']) ? e($rec['processor_name']) : '—' ?></td>
            <td><code><?= e((string) ($rec['transaction_ref'] ?? '')) ?></code></td>
            <td style="white-space:nowrap;">
                <form class="inline" method="post" action="<?= url('/admin/subscriptions/' . (int) $rec['id'] . '/approve') ?>"
                      data-confirm="Mark this signup as paid and activate the membership?">
                    <?= csrf_field() ?><button type="submit" class="btn btn-sm">Approve</button>
                </form>
                <form class="inline" method="post" action="<?= url('/admin/subscriptions/' . (int) $rec['id'] . '/cancel') ?>"
                      data-confirm="Cancel this pending signup?">
                    <?= csrf_field() ?><button type="submit" class="btn btn-sm btn-danger">Cancel</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>

<?php if (!empty($plans)): ?>
<h2>Grant Membership</h2>
<form method="post" action="<?= url('/admin/subscriptions') ?>">
    <?= csrf_field() ?>
    <p>
        <input type="email" name="user_email" placeholder="User email" required>
        <select name="plan_id">
            <?php foreach ($plans as $plan): ?>
                <option value="<?= (int) $plan['id'] ?>"><?= e($plan['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn">Grant</button>
    </p>
</form>

<h2 class="u-mt-1">Free Trial</h2>
<form method="post" action="<?= url('/admin/subscriptions/trial') ?>" style="margin-bottom:.5rem;">
    <?= csrf_field() ?>
    <p>
        <input type="email" name="user_email" placeholder="User email" required>
        <button type="submit" class="btn btn-outline">Grant <?= (int) \App\Models\SiteConfig::trialDays() ?>-day trial</button>
        <span class="muted u-fs-085">One trial per user; rejected if they are already a member.</span>
    </p>
</form>
<form method="post" action="<?= url('/admin/subscriptions/trial/settings') ?>" style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
    <?= csrf_field() ?>
    <label class="muted u-fs-09">Trial length (days)</label>
    <input type="number" name="trial_days" min="1" max="90" value="<?= (int) \App\Models\SiteConfig::trialDays() ?>" style="width:5rem;">
    <button type="submit" class="btn btn-sm btn-outline">Save</button>
</form>

<h2 class="u-mt-1">Trial Links</h2>
<p class="muted">Create a shareable link that grants a free trial at a chosen membership level for a chosen number of days, up to a signup quota. Share the link with prospective members; each account can only redeem one trial ever.</p>
<form method="post" action="<?= url('/admin/trial-links') ?>" style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;margin-bottom:.5rem;">
    <?= csrf_field() ?>
    <select name="trial_level">
        <option value="1">Silver</option>
        <option value="2">Gold</option>
        <option value="3">Platinum</option>
    </select>
    <label class="muted u-fs-09">Days</label>
    <input type="number" name="trial_days" min="1" max="90" value="3" style="width:4.5rem;">
    <label class="muted u-fs-09">Max signups</label>
    <input type="number" name="max_uses" min="1" max="10000" value="1" style="width:5rem;">
    <button type="submit" class="btn btn-sm">Create link</button>
</form>
<?php if (!empty($trialLinks)): ?>
<table>
    <thead><tr><th>Link</th><th>Level</th><th>Days</th><th>Uses</th><th>Status</th><th>Created</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($trialLinks as $tl): ?>
        <?php $linkUrl = absolute_url('/signup/' . rawurlencode($tl['code'])); ?>
        <tr>
            <td>
                <a href="<?= e($linkUrl) ?>" target="_blank" rel="noopener" style="word-break:break-all;"><code class="u-fs-08"><?= e($linkUrl) ?></code></a>
                <div style="margin-top:.3rem;">
                    <button type="button" class="btn btn-sm btn-outline" style="font-size:.75rem;" data-action="copyLinkValue" data-arg="<?= e($linkUrl) ?>">Copy link</button>
                </div>
            </td>
            <td><?= e(\App\Models\TrialLink::levelLabel((int) $tl['level'])) ?></td>
            <td><?= (int) $tl['days'] ?></td>
            <td><?= (int) $tl['used_count'] ?> / <?= (int) $tl['max_uses'] ?></td>
            <td><?= (int) $tl['enabled'] ? '<span class="pill pill-ok">Enabled</span>' : '<span class="pill">Disabled</span>' ?></td>
            <td><?= e((string) ($tl['created_by_email'] ?? '')) ?><br><small class="muted"><?= e(tzdate('Y-m-d H:i', $tl['created_at'])) ?></small></td>
            <td style="white-space:nowrap;">
                <form class="inline" method="post" action="<?= url('/admin/trial-links/' . (int) $tl['id'] . '/toggle') ?>">
                    <?= csrf_field() ?><button type="submit" class="btn btn-sm btn-outline"><?= (int) $tl['enabled'] ? 'Disable' : 'Enable' ?></button>
                </form>
                <form class="inline" method="post" action="<?= url('/admin/trial-links/' . (int) $tl['id'] . '/delete') ?>" data-confirm="Delete this trial link?">
                    <?= csrf_field() ?><button type="submit" class="btn btn-sm btn-danger">Delete</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php else: ?>
<p class="muted">No trial links yet.</p>
<?php endif; ?>
<?php endif; ?>

<h2>All Subscriptions</h2>
<?php
use App\Core\AdminTable;
?>
<?= AdminTable::shell([
    'title'  => 'All subscriptions',
    'count'  => count($subscriptions),
    'total'  => count($subscriptions),
    'empty'  => 'No subscriptions yet.',
    'columns' => [
        ['label' => 'Membership ID'],
        ['label' => 'User'],
        ['label' => 'Plan'],
        ['label' => 'Price', 'class' => 'num'],
        ['label' => 'Sale'],
        ['label' => 'Payment'],
        ['label' => 'Status'],
        ['label' => 'Started'],
        ['label' => 'Expires'],
        ['label' => 'Requested'],
        ['label' => 'Actions', 'class' => 'act'],
    ],
    'body'   => function () use ($subscriptions): void {
        ?>
        <?php foreach ($subscriptions as $sub): ?>
            <tr>
                <td><code>#<?= e((string) ($sub['membership_number'] ?? sprintf('%05d', (int) $sub['id']))) ?></code></td>
                <td><?= e($sub['user_email']) ?></td>
                <td><?= e($sub['plan_name']) ?></td>
                <td class="num"><?= $sub['price_paid'] !== null ? '$' . number_format((float) $sub['price_paid'], 2) : '&mdash;' ?></td>
                <td><?= !empty($sub['sale_name']) ? e($sub['sale_name']) . (!empty($sub['sale_code']) ? ' (' . e($sub['sale_code']) . ')' : '') : '&mdash;' ?></td>
                <td>
                    <?= !empty($sub['payment_name']) ? e($sub['payment_name']) : '&mdash;' ?>
                    <?php if (!empty($sub['transaction_ref'])): ?><br><span class="muted u-fs-08"><?= e($sub['transaction_ref']) ?></span><?php endif; ?>
                </td>
                <td><?= e(\App\Models\Subscription::statusLabel($sub['status'])) ?></td>
                <td><?= !empty($sub['starts_at']) ? e(tzdate('Y-m-d', $sub['starts_at'])) : '&mdash;' ?></td>
                <td><?= !empty($sub['expires_at']) ? e(tzdate('Y-m-d', $sub['expires_at'])) : '&mdash;' ?></td>
                <td><?= e(tzdate('Y-m-d H:i', $sub['created_at'])) ?></td>
                <td class="act">
                    <?php if ($sub['status'] === 'pending'): ?>
                        <form class="inline" method="post" action="<?= url('/admin/subscriptions/' . (int) $sub['id'] . '/approve') ?>">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-sm">Approve</button>
                        </form>
                    <?php endif; ?>
                    <?php if ($sub['status'] === 'active'): ?>
                        <form class="inline" method="post" action="<?= url('/admin/subscriptions/' . (int) $sub['id'] . '/cancel') ?>"
                              data-confirm="Cancel this active subscription? This will end the member’s access.">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-sm btn-outline">Cancel</button>
                        </form>
                    <?php endif; ?>
                    <form class="inline" method="post" action="<?= url('/admin/subscriptions/' . (int) $sub['id'] . '/delete') ?>"
                          data-confirm="Delete this subscription record?">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php
    },
]) ?>
