<?php $title = 'Subscriptions'; ?>

<h1>Subscriptions</h1>

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
                <th>User</th>
                <th>Plan</th>
                <th>Price</th>
                <th>Sale</th>
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
                    <td><?= e($sub['user_email']) ?></td>
                    <td><?= e($sub['plan_name']) ?></td>
                    <td><?= $sub['price_paid'] !== null ? '$' . number_format((float) $sub['price_paid'], 2) : '&mdash;' ?></td>
                    <td><?= !empty($sub['sale_name']) ? e($sub['sale_name']) . (!empty($sub['sale_code']) ? ' (' . e($sub['sale_code']) . ')' : '') : '&mdash;' ?></td>
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
