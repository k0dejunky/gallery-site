<?php
$title = 'My Membership';
// The fragment renders before layout.php, so $user must come from the
// controller's view data (MembershipController@my passes it).
$user = $user ?? \App\Core\Auth::user();
?>
<?php $pendingSub = $pendingSub ?? null; ?>

<div class="auth-panel" style="max-width: 720px; text-align:center;">
    <h1>My Membership</h1>
    <p><a class="btn btn-outline" href="<?= e(url('/account')) ?>">&larr; Back to dashboard</a></p>

    <div class="card u-mb-1">
        <h2>Account</h2>
        <p><strong>Email:</strong> <?= e($user['email'] ?? '') ?></p>
        <p><strong>First name:</strong> <?= e($user['billing_first_name'] ?? '') ?: '&mdash;' ?></p>
        <p><strong>Last name:</strong> <?= e($user['billing_last_name'] ?? '') ?: '&mdash;' ?></p>
             <p><strong>Access level:</strong>
                 <?= $activeSub !== null ? (int) ($activeSub['plan_level'] ?? $activeSub['access_level'] ?? 0) : 'No active access' ?>
             </p>
             <p><strong>Membership type:</strong>
            <?= $activeSub !== null ? e($activeSub['plan_name']) : 'None' ?>
        </p>
    </div>

    <p class="u-center">
        <a href="<?= url('/membership') ?>">View plans</a>
        &middot;
        <a href="<?= url('/galleries') ?>">Browse galleries</a>
    </p>

    <?php if ($hasActive): ?>
         <div class="card">
            <h2>Current membership</h2>
            <?php $isRecurring = strpos((string) ($activeSub['transaction_ref'] ?? ''), 'BT-') === 0; ?>
            <?php $isTrial = strpos((string) ($activeSub['transaction_ref'] ?? ''), 'TRIAL-') === 0; ?>
            <?php $hasExpiry = !empty($activeSub['expires_at']); ?>
            <p>
                <strong><?= e($activeSub['plan_name']) ?></strong><?= $isTrial ? ' <span class="chip">free trial</span>' : '' ?> &mdash;
                <?php if (!empty($activeSub['expires_at'])): ?>
                    <?php if ($isRecurring): ?>
                        renews automatically; access is available through <strong><?= e(tzdate('F j, Y', $activeSub['expires_at'])) ?></strong>.
                    <?php else: ?>
                        access is available through <strong><?= e(tzdate('F j, Y', $activeSub['expires_at'])) ?></strong>; it does not renew automatically.
                    <?php endif; ?>
                <?php else: ?>
                    never expires.
                <?php endif; ?>
            </p>
            <p class="muted">
                <?php if ($hasExpiry): ?>
                    Cancelling stops future renewal and keeps access available through the expiry date.
                <?php else: ?>
                    Cancelling ends lifetime access immediately.
                <?php endif; ?>
            </p>
            <form class="inline" method="post" action="<?= url('/membership/cancel') ?>"
                  data-confirm="<?= $hasExpiry ? 'Cancel your membership? Access will remain available through its expiry date.' : 'Cancel your membership? Lifetime access will end immediately.' ?>">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-sm btn-danger">Cancel membership</button>
            </form>
        </div>

        <?php // A past-due membership: the card failed, and the member needs
              // somewhere to put a new one. Without this the past-due email
              // linked here and there was nothing to do. ?>
        <?php if (!empty($pastDueSub)): ?>
        <div class="card">
            <h2>Your payment failed</h2>
            <p>
                We could not charge the card on your <strong><?= e((string) ($pastDueSub['plan_name'] ?? 'membership')) ?></strong>
                membership, so it has been marked past due. You keep your access for
                <strong><?= (int) \App\Models\Subscription::pastDueGraceDays() ?> days</strong>
                while the card is retried.
            </p>
            <?php if (!empty($cardToken)): ?>
            <form method="post" action="<?= url('/membership/update-card') ?>" id="update-card-form">
                <?= csrf_field() ?>
                <input type="hidden" name="subscription_id" value="<?= (int) $pastDueSub['id'] ?>">
                <input type="hidden" name="payment_method_nonce" id="update-card-nonce">
                <div id="update-card-fields"></div>
                <p class="muted" id="update-card-status">Loading the card form&hellip;</p>
                <button type="submit" class="btn" id="update-card-submit" disabled>Update my card</button>
            </form>
            <script nonce="<?= csp_nonce() ?>" src="https://js.braintreegateway.com/web/3.103.0/js/client.min.js"></script>
            <script nonce="<?= csp_nonce() ?>">
            (function () {
                var status = document.getElementById('update-card-status');
                var submit = document.getElementById('update-card-submit');
                if (!window.braintree || !status || !submit) { return; }
                braintree.client.create({ authorization: <?= json_encode((string) $cardToken) ?> }, function (err, client) {
                    if (err) { status.textContent = 'The card form could not be loaded. Please contact support.'; return; }
                    var host = document.getElementById('update-card-fields');
                    client.request({
                        endpoint: 'payment_methods/credit_cards',
                        method: 'post',
                        data: { creditCard: { cardholderName: <?= json_encode((string) ($user['billing_first_name'] ?? '')) ?> } }
                    }, function (cerr, response) {
                        if (cerr || !response || !response.creditCards || !response.creditCards.length) {
                            status.textContent = 'The card form could not be loaded. Please contact support.';
                            return;
                        }
                        var iframe = document.createElement('iframe');
                        iframe.frameBorder = '0';
                        iframe.setAttribute('title', 'Card details');
                        iframe.style.width = '100%';
                        iframe.style.height = '150px';
                        iframe.src = response.creditCards[0].url;
                        host.appendChild(iframe);
                        document.getElementById('update-card-nonce').value = response.nonce;
                        status.textContent = 'Enter your new card details below.';
                        submit.disabled = false;
                    });
                });
            }());
            </script>
            <?php else: ?>
            <p class="muted">
                This membership is not billed by card, so the card cannot be updated here.
                Please contact support and we will sort it out.
            </p>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    <?php elseif ($pendingSub !== null): ?>
        <div class="card">
            <h2>Pending approval</h2>
            <p>Your <strong><?= e($pendingSub['plan_name']) ?></strong> request is pending. Access level after approval: <strong><?= (int) ($pendingSub['access_level'] ?? 0) ?></strong>.</p>
            <p class="muted">We will update your account when the request is processed. No renewal or expiry date applies until activation.</p>
        </div>
    <?php else: ?>
        <div class="card">
            <h2><?= !empty($subscriptions) ? e(\App\Models\Subscription::statusLabel((string) ($subscriptions[0]['status'] ?? 'expired'))) : 'No active membership' ?></h2>
            <p class="muted">You currently do not have usable gallery access. Choose a plan on the <a href="<?= e(url('/membership')) ?>">membership page</a> to continue browsing.</p>
        </div>
    <?php endif; ?>

    <?php if (!empty($subscriptions)): ?>
        <p class="muted">Cancelled memberships remain available through their expiry date. Expired memberships no longer grant gallery access.</p>
    <?php endif; ?>

    <h2 style="text-align:center; margin-top:1.5rem;">History</h2>

    <?php if (empty($subscriptions)): ?>
        <div class="empty-state">
            <p class="muted">No subscription history yet.</p>
            <a class="btn btn-sm" href="<?= e(url('/membership')) ?>">View membership plans</a>
        </div>
    <?php else: ?>
        <div class="table-scroll">
        <table class="membership-history">
            <thead>
                <tr>
                    <th>Membership ID</th>
                    <th>Plan</th>
                    <th>Price</th>
                    <th>Sale</th>
                    <th>Payment</th>
                    <th>Status</th>
                    <th>Access level</th>
                    <th>Started</th>
                    <th>Expires</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($subscriptions as $sub): ?>
                    <tr>
                        <td><code>#<?= e((string) ($sub['membership_number'] ?? sprintf('%05d', (int) $sub['id']))) ?></code></td>
                        <td><?= e($sub['plan_name']) ?></td>
                        <td><?= $sub['price_paid'] !== null ? '$' . number_format((float) $sub['price_paid'], 2) : '&mdash;' ?></td>
                        <td><?= !empty($sub['sale_name']) ? e($sub['sale_name']) : '&mdash;' ?></td>
                        <td><?= !empty($sub['payment_name']) ? e($sub['payment_name']) : '&mdash;' ?></td>
                        <?php $historyStatus = $sub['status'] === 'active' && !empty($sub['expires_at']) && strtotime($sub['expires_at']) <= time() ? 'expired' : (string) $sub['status']; ?>
                        <td><span class="status-badge <?= e($historyStatus) ?>"><?= e(\App\Models\Subscription::statusLabel($historyStatus)) ?></span></td>
                        <td><?= (int) ($sub['access_level'] ?? 0) ?></td>
                        <td><?= !empty($sub['starts_at']) ? e(tzdate('Y-m-d', $sub['starts_at'])) : '&mdash;' ?></td>
                        <td><?= !empty($sub['expires_at']) ? e(tzdate('Y-m-d', $sub['expires_at'])) : '&mdash;' ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>
