<?php $title = 'Membership'; ?>

<?php
// Membership pricing page. Users who already have access see their current
// plan first; everyone sees the available plans with a subscribe button.
// Payments are manual/placeholder, so subscribing creates a request that an
// admin approves.
// Template changes are applied by layout.php — no duplicate script needed here.
?>

    <div class="auth-panel" style="max-width: 720px; text-align:center; display:flex; flex-direction:column;">
    <h1 style="order:1;">Membership</h1>


    <?php if ($user !== null): ?>
        <div class="card" style="margin-bottom:1rem; order:2;">
            <h2>Account</h2>
            <p><strong>Email:</strong> <?= e($user['email'] ?? '') ?></p>
            <p><strong>First name:</strong> <?= e($user['billing_first_name'] ?? '') ?: '&mdash;' ?></p>
            <p><strong>Last name:</strong> <?= e($user['billing_last_name'] ?? '') ?: '&mdash;' ?></p>
            <p><strong>Membership type:</strong>
                <?= $activeSub !== null ? e($activeSub['plan_name']) : ($pendingSub !== null ? e($pendingSub['plan_name']) . ' (pending)' : 'None') ?>
            </p>
        </div>
    <?php endif; ?>

    <?php if ($hasActive): ?>
        <div class="card" style="order:3;">
            <h2>Your membership</h2>
            <p>
                You have an active <strong><?= e($activeSub['plan_name']) ?></strong> membership.
                <?php if (!empty($activeSub['expires_at'])): ?>
                    It is valid until <strong><?= e(date('F j, Y', strtotime($activeSub['expires_at']))) ?></strong>.
                <?php else: ?>
                    It never expires.
                <?php endif; ?>
            </p>
            <p>
                <a class="btn" href="<?= url('/membership/my') ?>">Manage membership</a>
            </p>
        </div>
    <?php elseif ($pendingSub !== null): ?>
        <div class="card" style="order:3;">
            <h2>Membership requested</h2>
            <p>
                You requested a <strong><?= e($pendingSub['plan_name']) ?></strong> membership. It is awaiting
                review, and your access will begin once it is approved.
            </p>
            <p><a class="btn" href="<?= url('/membership/my') ?>">View status</a></p>
        </div>
    <?php elseif ($user === null): ?>
        <div class="card" style="order:3;">
            <h2>Members-only content</h2>
            <p class="muted">
                All galleries and videos on <?= e(config('app.site_name')) ?> require an active membership.
                Create an account to subscribe, or log in to manage your membership.
            </p>
            <p style="text-align:center;">
                <a class="btn" href="<?= url('/signup') ?>">Create an account</a>
                <a class="btn btn-outline" href="<?= url('/login') ?>">Log in</a>
            </p>
        </div>
    <?php endif; ?>

    <?php if ($user !== null && !$hasActive && $pendingSub === null): ?>
        <div class="card" style="border:2px solid var(--filter-border); margin-top:1.25rem; order:4;">
            <h2>Redeem a promotion code</h2>
            <p class="muted">Have a sale or promotion code? Choose the plan it applies to and enter the code before requesting membership.</p>
            <form method="post" action="<?= url('/membership/subscribe') ?>">
                <?= csrf_field() ?>
                <p>
                    <label for="promotion_plan_id">Plan covered by the code</label><br>
                    <select name="plan_id" id="promotion_plan_id" required>
                        <option value="">Choose a plan</option>
                        <?php foreach ($plans as $plan): ?>
                            <option value="<?= (int) $plan['id'] ?>"><?= e($plan['name']) ?> — $<?= number_format((float) $plan['price'], 2) ?></option>
                        <?php endforeach; ?>
                    </select>
                </p>
                <p>
                    <label for="promotion_code">Promotion code</label><br>
                    <input type="text" name="sale_code" id="promotion_code" maxlength="120" required placeholder="Enter your code">
                </p>
                <button type="submit" class="btn">Redeem Code and Request Membership</button>
            </form>
        </div>
    <?php endif; ?>

    <h2 style="text-align:center; margin-top: 1.5rem; order:5;">Choose a plan</h2>
    <p class="muted" style="text-align:center; order:6;">All plans include full access to every gallery and video.</p>

    <?php if (empty($plans)): ?>
        <p class="muted" style="text-align:center; order:7;">No plans are available right now. Please check back soon.</p>
    <?php else: ?>
        <?php $silverPlanId = 0; foreach ($plans as $candidatePlan) { if (strtolower((string) ($candidatePlan['slug'] ?? $candidatePlan['name'])) === 'silver') { $silverPlanId = (int) $candidatePlan['id']; break; } } ?>
        <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); order:7;">
            <?php foreach ($plans as $plan): ?>
                <div class="card" style="display:flex; flex-direction:column; justify-content:space-between; text-align:center; margin:0;">
                    <div style="order:1;">
                        <h2 style="margin-top:0;"><?= e($plan['name']) ?></h2>
                        <p style="font-size:1.5rem; font-weight:bold; margin:0.5rem 0;">
                            <?php if (!empty($plan['sale'])): ?>
                                <del class="muted" style="font-size:0.95rem;">$<?= number_format((float) $plan['price'], 2) ?></del>
                                $<?= number_format((float) $plan['sale']['sale_price'], 2) ?>
                            <?php else: ?>
                                $<?= number_format((float) $plan['price'], 2) ?>
                            <?php endif; ?>
                            <span class="muted" style="font-size:0.85rem; font-weight:normal;">/ <?= e(\App\Models\Plan::cycleLabel($plan['billing_cycle'])) ?></span>
                        </p>
                        <?php if (!empty($plan['sale'])): ?>
                            <p><strong><?= e($plan['sale']['name']) ?></strong>
                                <?php if ($plan['sale']['max_subscriptions'] !== null): ?>
                                    <span class="muted">Limited offer</span>
                                <?php endif; ?>
                            </p>
                        <?php endif; ?>
                        <?php if (!empty($plan['description'])): ?>
                            <p class="muted"><?= e($plan['description']) ?></p>
                        <?php endif; ?>
                    </div>
                    <?php if ($hasActive || $pendingSub !== null): ?>
                        <button type="button" class="btn btn-disabled" disabled style="order:2;">Unavailable</button>
                    <?php elseif (strtolower((string) ($plan['slug'] ?? $plan['name'])) === 'silver'): ?>
                        <div style="order:2;">
                            <div id="paypal-button-container-P-2EE95782UN3086035NKHSZ4A"></div>
                            <input type="hidden" name="_token" value="<?= e(\App\Core\Csrf::token()) ?>" data-paypal-csrf>
                        </div>
                    <?php else: ?>
                        <form method="post" action="<?= url('/membership/subscribe') ?>" style="order:2;" id="subForm_<?= (int) $plan['id'] ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="plan_id" value="<?= (int) $plan['id'] ?>">
                            <?php if (!empty($paymentProcessors)): ?>
                                <div style="margin-bottom:.6rem;">
                                    <label for="pay_<?= (int) $plan['id'] ?>" class="muted" style="display:block;margin-bottom:.25rem;font-size:var(--font-size-sm);">Payment method</label>
                                    <select name="payment_processor" id="pay_<?= (int) $plan['id'] ?>" style="width:100%;box-sizing:border-box;" data-plan-id="<?= (int) $plan['id'] ?>" class="pp-select">
                                        <?php foreach ($paymentProcessors as $pp): ?>
                                            <option value="<?= (int) $pp['id'] ?>" data-provider="<?= e((string) $pp['provider']) ?>">
                                                <?= e(\App\Models\PaymentProcessor::providerLabel((string) $pp['provider'])) ?> — <?= e((string) $pp['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php endif; ?>
                            <button type="submit" class="btn" style="width:100%;">Subscribe</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <p class="muted" style="text-align:center; margin-top:1.5rem; order:8;">
        Subscriptions are approved manually. You will be able to browse immediately after approval.
    </p>
</div>

<?php if (!$hasActive && $pendingSub === null): ?>
<script src="https://www.paypal.com/sdk/js?client-id=BAAulxhXtOW_C1MbdQ9ieSDNNQYJhjbXAknX4UujE8n02reztiOBMnqH8cw0r-ZyKT9aIU0zZslsm3hyZc&vault=true&intent=subscription" data-sdk-integration-source="button-factory"></script>
<script>
(function () {
    if (!window.paypal) return;
    paypal.Buttons({
        style: { shape: 'rect', color: 'gold', layout: 'vertical', label: 'subscribe' },
        createSubscription: function (data, actions) {
            return actions.subscription.create({ plan_id: 'P-2EE95782UN3086035NKHSZ4A' });
        },
        onApprove: function (data) {
            var token = document.querySelector('[data-paypal-csrf]');
            var body = new URLSearchParams({
                _token: token ? token.value : '',
                plan_id: '<?= $silverPlanId ?>',
                paypal_subscription_id: data.subscriptionID || ''
            });
            fetch('<?= url('/membership/paypal-approve') ?>', { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: body })
                .then(function (response) { return response.json(); })
                .then(function (result) {
                    if (result.ok) window.location.href = '<?= url('/membership/my') ?>';
                    else alert(result.error || 'We could not record your subscription. Please contact support.');
                })
                .catch(function () { alert('We could not record your subscription. Please contact support.'); });
        }
    }).render('#paypal-button-container-P-2EE95782UN3086035NKHSZ4A');
}());
</script>
<?php endif; ?>

<script>
(function(){
    document.querySelectorAll('.pp-select').forEach(function(sel){
        sel.closest('form').addEventListener('submit', function(e){
            var opt = sel.options[sel.selectedIndex];
            if (opt && opt.getAttribute('data-provider') === 'braintree') {
                e.preventDefault();
                var planId = sel.closest('form').querySelector('input[name="plan_id"]').value;
                window.location.href = <?= json_encode(url('/membership/checkout')) ?> + '?plan_id=' + planId;
            }
        });
    });
})();
</script>
