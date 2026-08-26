<?php $title = 'Subscriptions'; ?>

<h1>Subscriptions</h1>

<?php if (!empty($reconciliation)): ?>
<h2>Needs Attention — Pending Biller Signups</h2>
<p class="muted">Checkouts that reached a payment processor but were never confirmed by its postback. Approve after verifying the payment in the biller's admin, or cancel to release the member.</p>
<table>
    <thead><tr><th>Membership ID</th><th>Age</th><th>User</th><th>Plan</th><th>Processor</th><th>Reference</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($reconciliation as $rec): ?>
        <?php $stale = (int) $rec['age_hours'] >= 24; ?>
        <tr<?= $stale ? ' style="background:rgba(220,38,38,.08);"' : '' ?>>
            <td><code>#<?= e((string) ($rec['membership_number'] ?? sprintf('%05d', (int) $rec['id']))) ?></code></td>
            <td title="<?= e((string) $rec['created_at']) ?>"><?= (int) $rec['age_hours'] ?>h<?= $stale ? ' ⚠' : '' ?></td>
            <td><?= e((string) $rec['user_email']) ?></td>
            <td><?= e((string) $rec['plan_name']) ?> ($<?= number_format((float) $rec['price'], 2) ?>)</td>
            <td><?= !empty($rec['processor_name']) ? e($rec['processor_name']) : '—' ?></td>
            <td><code><?= e((string) ($rec['transaction_ref'] ?? '')) ?></code></td>
            <td style="white-space:nowrap;">
                <form class="inline" method="post" action="<?= url('/admin/subscriptions/' . (int) $rec['id'] . '/approve') ?>"
                      onsubmit="return confirm('Mark this signup as paid and activate the membership?');">
                    <?= csrf_field() ?><button type="submit" class="btn btn-sm">Approve</button>
                </form>
                <form class="inline" method="post" action="<?= url('/admin/subscriptions/' . (int) $rec['id'] . '/cancel') ?>"
                      onsubmit="return confirm('Cancel this pending signup?');">
                    <?= csrf_field() ?><button type="submit" class="btn btn-sm btn-danger">Cancel</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
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
<?php endif; ?>

<h2>All Subscriptions</h2>
<?php if (empty($subscriptions)): ?>
    <p>No subscriptions yet.</p>
<?php else: ?>
    <table>
        <thead>
            <tr>
                <th>Membership ID</th>
                <th>User</th>
                <th>Plan</th>
                <th>Price</th>
                <th>Sale</th>
                <th>Payment</th>
                <th>Status</th>
                <th>Started</th>
                <th>Expires</th>
                <th>Requested</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($subscriptions as $sub): ?>
                <tr>
                    <td><code>#<?= e((string) ($sub['membership_number'] ?? sprintf('%05d', (int) $sub['id']))) ?></code></td>
                    <td><?= e($sub['user_email']) ?></td>
                    <td><?= e($sub['plan_name']) ?></td>
                    <td><?= $sub['price_paid'] !== null ? '$' . number_format((float) $sub['price_paid'], 2) : '&mdash;' ?></td>
                    <td><?= !empty($sub['sale_name']) ? e($sub['sale_name']) . (!empty($sub['sale_code']) ? ' (' . e($sub['sale_code']) . ')' : '') : '&mdash;' ?></td>
                    <td>
                        <?= !empty($sub['payment_name']) ? e($sub['payment_name']) : '&mdash;' ?>
                        <?php if (!empty($sub['transaction_ref'])): ?><br><span class="muted" style="font-size:var(--font-size-xs);"><?= e($sub['transaction_ref']) ?></span><?php endif; ?>
                    </td>
                    <td><?= e(\App\Models\Subscription::statusLabel($sub['status'])) ?></td>
                    <td><?= !empty($sub['starts_at']) ? e($sub['starts_at']) : '&mdash;' ?></td>
                    <td><?= !empty($sub['expires_at']) ? e($sub['expires_at']) : '&mdash;' ?></td>
                    <td><?= e($sub['created_at']) ?></td>
                    <td>
                        <?php if ($sub['status'] === 'pending'): ?>
                            <form class="inline" method="post" action="<?= url('/admin/subscriptions/' . (int) $sub['id'] . '/approve') ?>">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm">Approve</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($sub['status'] === 'active'): ?>
                            <form class="inline" method="post" action="<?= url('/admin/subscriptions/' . (int) $sub['id'] . '/cancel') ?>">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm btn-outline">Cancel</button>
                            </form>
                        <?php endif; ?>
                        <form class="inline" method="post" action="<?= url('/admin/subscriptions/' . (int) $sub['id'] . '/delete') ?>"
                              onsubmit="return confirm('Delete this subscription record?');">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
