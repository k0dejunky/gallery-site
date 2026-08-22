<?php $title = 'My Membership'; ?>
<style>.membership-history th,.membership-history td{text-align:center}</style>

<div class="auth-panel" style="max-width: 720px; text-align:center;">
    <h1>My Membership</h1>

    <div class="card" style="margin-bottom:1rem;">
        <h2>Account</h2>
        <p><strong>Email:</strong> <?= e($user['email'] ?? '') ?></p>
        <p><strong>First name:</strong> <?= e($user['billing_first_name'] ?? '') ?: '&mdash;' ?></p>
        <p><strong>Last name:</strong> <?= e($user['billing_last_name'] ?? '') ?: '&mdash;' ?></p>
        <p><strong>Membership type:</strong>
            <?= $activeSub !== null ? e($activeSub['plan_name']) : 'None' ?>
        </p>
    </div>

    <p style="text-align:center;">
        <a href="<?= url('/membership') ?>">View plans</a>
        &middot;
        <a href="<?= url('/galleries') ?>">Browse galleries</a>
    </p>

    <?php if ($hasActive): ?>
        <div class="card">
            <h2>Current membership</h2>
            <p>
                <strong><?= e($activeSub['plan_name']) ?></strong> &mdash;
                <?php if (!empty($activeSub['expires_at'])): ?>
                    valid until <strong><?= e(date('F j, Y', strtotime($activeSub['expires_at']))) ?></strong>.
                <?php else: ?>
                    never expires.
                <?php endif; ?>
            </p>
            <form class="inline" method="post" action="<?= url('/membership/cancel') ?>"
                  onsubmit="return confirm('Cancel your membership? Access will stop at its expiry date.');">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-sm btn-danger">Cancel membership</button>
            </form>
        </div>
    <?php else: ?>
        <div class="card">
            <h2>No active membership</h2>
            <p class="muted">
                You currently do not have usable access. Choose a plan on the
                <a href="<?= url('/membership') ?>">membership page</a> to continue browsing.
            </p>
        </div>
    <?php endif; ?>

    <h2 style="text-align:center; margin-top:1.5rem;">History</h2>

    <?php if (empty($subscriptions)): ?>
        <p class="muted" style="text-align:center;">No subscription history yet.</p>
    <?php else: ?>
        <table class="membership-history">
            <thead>
                <tr>
                    <th>Plan</th>
                    <th>Price</th>
                    <th>Sale</th>
                    <th>Payment</th>
                    <th>Status</th>
                    <th>Started</th>
                    <th>Expires</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($subscriptions as $sub): ?>
                    <tr>
                        <td><?= e($sub['plan_name']) ?></td>
                        <td><?= $sub['price_paid'] !== null ? '$' . number_format((float) $sub['price_paid'], 2) : '&mdash;' ?></td>
                        <td><?= !empty($sub['sale_name']) ? e($sub['sale_name']) : '&mdash;' ?></td>
                        <td><?= !empty($sub['payment_name']) ? e($sub['payment_name']) : '&mdash;' ?></td>
                        <td><?= e(\App\Models\Subscription::statusLabel($sub['status'])) ?></td>
                        <td><?= !empty($sub['starts_at']) ? e($sub['starts_at']) : '&mdash;' ?></td>
                        <td><?= !empty($sub['expires_at']) ? e($sub['expires_at']) : '&mdash;' ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
