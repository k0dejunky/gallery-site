<?php
$title = 'Payment Processors';

// Absolute base used to show admins the postback URL to register with billers.
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$webhookBase = $scheme . '://' . $host . rtrim((string) config('app.base_path'), '/');
?>

<style>
    .pay-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1rem; }
    .pay-card { padding: 1rem; background: var(--card-bg); border: var(--input-border-width) solid var(--card-border); border-radius: var(--card-radius); box-shadow: var(--shadow); color: var(--card-text-color); }
    .pay-card.inactive { opacity: .62; }
    .pay-card h3 { margin: 0 0 .25rem; color: var(--card-title-color); }
    .pay-provider { display: inline-block; padding: .15rem .45rem; background: var(--filter-bg); border: var(--input-border-width) solid var(--filter-border); border-radius: var(--border-radius-sm); color: var(--filter-color); font-size: var(--font-size-xs); font-weight: bold; text-transform: capitalize; }
    .pay-mode { display: inline-block; padding: .15rem .45rem; border-radius: var(--chip-radius); background: var(--promo-code-bg); color: var(--promo-code-color); font-size: var(--font-size-xs); font-weight: bold; }
    .pay-mode.live { background: var(--btn-danger-bg); color: var(--btn-danger-color); }
    .pay-meta { margin: .6rem 0; color: var(--card-text-color); font-size: var(--font-size-sm); line-height: 1.7; }
    .pay-meta b { color: var(--purple-800); }
    .pay-secret { font-family: monospace; font-size: var(--font-size-sm); }
    .pay-actions { display: flex; gap: .5rem; flex-wrap: wrap; margin-top: .75rem; }
    .pay-form { margin-top: 2rem; padding: 1.25rem; background: var(--pink-100); border: 1px solid var(--pink-300); border-radius: var(--border-radius-lg); }
    .pay-form-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: .75rem 1rem; }
    .pay-form-grid label { display: block; color: var(--purple-800); font-size: var(--font-size-sm); }
    .pay-form-grid input, .pay-form-grid select { width: 100%; box-sizing: border-box; margin-top: .25rem; }
    .pay-form-wide { grid-column: 1 / -1; }
    .pay-check { display: flex; align-items: center; gap: .4rem; }
    .pay-check input { width: auto; margin: 0; }
    .pay-empty { padding: 1.5rem; text-align: center; color: var(--card-text-color); border: 1px dashed var(--card-border); border-radius: var(--border-radius); }
    .prov-cfg { display: none; margin-top: 1rem; padding: .75rem; border: 1px dashed var(--pink-300); border-radius: var(--border-radius); }
    .prov-cfg.active { display: block; }
    .prov-cfg legend { font-size: var(--font-size-sm); font-weight: bold; color: var(--purple-800); padding: 0 .4rem; }
    .prov-cfg label { display: block; color: var(--purple-800); font-size: var(--font-size-sm); }
    .prov-cfg input { width: 100%; box-sizing: border-box; margin: .15rem 0 .5rem; }
    @media (max-width: 720px) { .pay-form-grid { grid-template-columns: 1fr; } .pay-form-wide { grid-column: auto; } }
</style>

<h1>Payment Processors</h1>

<p class="muted">Configure the payment gateways visitors can use to subscribe to memberships.
Enable at least one processor to offer online checkout on the pricing page. Secret keys are
stored in the database and shown masked; leave a credential blank when editing to keep the
saved value.</p>

<?php if (empty($processors)): ?>
    <div class="pay-empty">No payment processors configured yet. Use the form below to add one.
    Supported providers: <?= e(implode(', ', array_map(fn ($p) => \App\Models\PaymentProcessor::providerLabel($p), \App\Models\PaymentProcessor::PROVIDERS))) ?>.
    CCBill, Epoch and SegPay use hosted checkout on the biller's site and confirm signups via postback.</div>
<?php else: ?>
    <div class="pay-grid">
        <?php foreach ($processors as $p): ?>
            <div class="pay-card<?= (int) $p['enabled'] === 1 ? '' : ' inactive' ?>">
                <h3><?= e((string) $p['name']) ?></h3>
                <div style="display:flex;gap:.4rem;flex-wrap:wrap;">
                    <span class="pay-provider"><?= e(\App\Models\PaymentProcessor::providerLabel((string) $p['provider'])) ?></span>
                    <span class="pay-mode<?= strtolower((string) $p['mode']) === 'live' ? ' live' : '' ?>"><?= e(\App\Models\PaymentProcessor::modeLabel((string) $p['mode'])) ?></span>
                    <?php if ((int) $p['is_default'] === 1): ?><span class="pay-mode">Default</span><?php endif; ?>
                    <?php if ((int) $p['enabled'] !== 1): ?><span class="pay-mode">Disabled</span><?php endif; ?>
                </div>
                <div class="pay-meta">
                    <div>Currency: <b><?= e((string) $p['currency']) ?></b></div>
                    <div>API key: <span class="pay-secret"><?= e(\App\Models\PaymentProcessor::maskSecret((string) $p['api_key'])) ?: '—' ?></span></div>
                    <div>Secret key: <span class="pay-secret"><?= e(\App\Models\PaymentProcessor::maskSecret((string) $p['secret_key'])) ?: '—' ?></span></div>
                    <?php if (!empty($p['webhook_secret'])): ?><div>Webhook: <span class="pay-secret"><?= e(\App\Models\PaymentProcessor::maskSecret((string) $p['webhook_secret'])) ?></span></div><?php endif; ?>
                    <?php $cfg = \App\Models\PaymentProcessor::decodeConfig($p); ?>
                    <?php if (strtolower((string) $p['provider']) === 'ccbill'): ?>
                        <div>Account: <b><?= e((string) ($cfg['client_accnum'] ?? '')) ?: '&mdash;' ?></b><?= !empty($cfg['client_subacc']) ? ' / ' . e((string) $cfg['client_subacc']) : '' ?><?= !empty($cfg['form_name']) ? ' · Form: ' . e((string) $cfg['form_name']) : '' ?></div>
                        <?php if (!empty($cfg['dynamic_salt'])): ?><div>Pricing salt: <span class="pay-secret"><?= e(\App\Models\PaymentProcessor::maskSecret((string) $cfg['dynamic_salt'])) ?></span></div><?php endif; ?>
                        <div>Postback URL: <code class="pay-secret"><?= e($webhookBase . '/webhooks/ccbill') ?></code></div>
                    <?php elseif (strtolower((string) $p['provider']) === 'epoch'): ?>
                        <div>Company: <b><?= e((string) ($cfg['co'] ?? '')) ?: '&mdash;' ?></b><?= !empty($cfg['pi']) ? ' · Product: ' . e((string) $cfg['pi']) : '' ?></div>
                        <?php if (!empty($cfg['secret'])): ?><div>Postback secret: <span class="pay-secret"><?= e(\App\Models\PaymentProcessor::maskSecret((string) $cfg['secret'])) ?></span></div><?php endif; ?>
                        <div>Approval postback URL: <code class="pay-secret"><?= e($webhookBase . '/webhooks/epoch') ?></code></div>
                    <?php elseif (strtolower((string) $p['provider']) === 'segpay'): ?>
                        <div>Auth key: <span class="pay-secret"><?= !empty($cfg['auth_key']) ? e(\App\Models\PaymentProcessor::maskSecret((string) $cfg['auth_key'])) : '&mdash;' ?></span></div>
                        <?= !empty($cfg['api_user']) ? '<div>API user: <b>' . e((string) $cfg['api_user']) . '</b></div>' : '' ?>
                        <div>Postback URL: <code class="pay-secret"><?= e($webhookBase . '/webhooks/segpay') ?></code></div>
                    <?php endif; ?>
                    <div>Subscriptions: <b><?= (int) $p['usage_count'] ?></b></div>
                </div>
                <div class="pay-actions">
                    <a class="btn btn-sm" href="<?= url('/admin/payment-processors?edit=' . (int) $p['id']) ?>">Edit</a>
                    <form class="inline" method="post" action="<?= url('/admin/payment-processors/' . (int) $p['id'] . '/toggle') ?>">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-sm"><?= (int) $p['enabled'] === 1 ? 'Disable' : 'Enable' ?></button>
                    </form>
                    <form class="inline" method="post" action="<?= url('/admin/payment-processors/' . (int) $p['id'] . '/delete') ?>"
                          onsubmit="return confirm('Remove this payment processor?');">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php $editCfg = !empty($edit) ? \App\Models\PaymentProcessor::decodeConfig($edit) : []; ?>
<div class="pay-form">
    <h2><?= !empty($edit) ? 'Edit Payment Processor #' . (int) $edit['id'] : 'Add Payment Processor' ?></h2>
    <?php if (!empty($edit)): ?><p class="muted">Leave secret fields blank to keep the saved values.</p><?php endif; ?>
    <form method="post" action="<?= url(!empty($edit) ? '/admin/payment-processors/' . (int) $edit['id'] : '/admin/payment-processors') ?>">
        <?= csrf_field() ?>
        <div class="pay-form-grid">
            <div>
                <label>Provider</label>
                <select name="provider" required>
                    <option value="">— select —</option>
                    <?php foreach (\App\Models\PaymentProcessor::PROVIDERS as $prov): ?>
                        <option value="<?= e($prov) ?>" <?= (!empty($edit) && strtolower((string) $edit['provider']) === $prov) ? 'selected' : '' ?>><?= e(\App\Models\PaymentProcessor::providerLabel($prov)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Display name</label>
                <input type="text" name="name" placeholder="e.g. Stripe (card)" required value="<?= e((string) ($edit['name'] ?? '')) ?>">
            </div>
            <div>
                <label>Mode</label>
                <select name="mode">
                    <option value="test" <?= (($edit['mode'] ?? 'test') === 'test') ? 'selected' : '' ?>>Test</option>
                    <option value="live" <?= (($edit['mode'] ?? '') === 'live') ? 'selected' : '' ?>>Live</option>
                </select>
            </div>
            <div>
                <label>API / publishable key</label>
                <input type="text" name="api_key" placeholder="<?= !empty($edit['api_key']) ? 'saved: ' . \App\Models\PaymentProcessor::maskSecret((string) $edit['api_key']) : 'pk_...' ?>">
            </div>
            <div>
                <label>Secret key</label>
                <input type="password" name="secret_key" placeholder="<?= !empty($edit['secret_key']) ? 'saved: ' . \App\Models\PaymentProcessor::maskSecret((string) $edit['secret_key']) : 'sk_...' ?>" autocomplete="new-password">
            </div>
            <div>
                <label>Webhook secret</label>
                <input type="password" name="webhook_secret" placeholder="<?= !empty($edit['webhook_secret']) ? 'saved: ' . \App\Models\PaymentProcessor::maskSecret((string) $edit['webhook_secret']) : 'whsec_...' ?>" autocomplete="new-password">
            </div>
            <div>
                <label>Currency</label>
                <input type="text" name="currency" value="<?= e((string) ($edit['currency'] ?? 'USD')) ?>" maxlength="8">
            </div>
            <div class="pay-check">
                <input type="checkbox" name="is_default" value="1" id="pd_default" <?= !empty($edit['is_default']) ? 'checked' : '' ?>>
                <label for="pd_default">Default</label>
            </div>
            <div class="pay-check">
                <input type="checkbox" name="enabled" value="1" id="pd_enabled" <?= (isset($edit['enabled']) && (int) $edit['enabled'] !== 1) ? '' : 'checked' ?>>
                <label for="pd_enabled">Enabled</label>
            </div>
        </div>

        <?php foreach (\App\Models\PaymentProcessor::CONFIG_FIELDS as $provKey => $fields): ?>
            <?php
                $isEditingThis = !empty($edit) && strtolower((string) $edit['provider']) === $provKey;
                $isSecretField = fn (string $k): bool => strpos($k, 'salt') !== false || strpos($k, 'secret') !== false || strpos($k, 'pass') !== false;
            ?>
            <fieldset class="prov-cfg" data-provider="<?= e($provKey) ?>">
                <legend><?= e(\App\Models\PaymentProcessor::providerLabel($provKey)) ?> credentials</legend>
                <?php foreach ($fields as $fieldKey => $label): ?>
                    <label><?= e($label) ?></label>
                    <?php if ($isSecretField($fieldKey)): ?>
                        <input type="password" name="<?= e($fieldKey) ?>" autocomplete="new-password"
                               placeholder="<?= $isEditingThis && !empty($editCfg[$fieldKey]) ? 'saved: ' . e(\App\Models\PaymentProcessor::maskSecret((string) $editCfg[$fieldKey])) : '' ?>">
                    <?php else: ?>
                        <input type="text" name="<?= e($fieldKey) ?>" autocomplete="off"
                               value="<?= $isEditingThis ? e((string) ($editCfg[$fieldKey] ?? '')) : '' ?>"
                               placeholder="">
                    <?php endif; ?>
                <?php endforeach; ?>
                <p class="muted" style="font-size:var(--font-size-sm);margin:.25rem 0 0;">
                    Register the postback URL <code><?= e($webhookBase . '/webhooks/' . $provKey) ?></code> in the
                    <?= e(\App\Models\PaymentProcessor::providerLabel($provKey)) ?> admin for automatic signup confirmation.
                </p>
            </fieldset>
        <?php endforeach; ?>

        <p style="margin-top:1rem;">
            <button type="submit" class="btn"><?= !empty($edit) ? 'Update Processor' : 'Save Processor' ?></button>
            <?php if (!empty($edit)): ?><a class="btn" href="<?= url('/admin/payment-processors') ?>">Cancel</a><?php endif; ?>
        </p>
    </form>
</div>

<script>
(function () {
    var sel = document.querySelector('.pay-form select[name="provider"]');

    if (!sel) { return; }

    function toggle() {
        document.querySelectorAll('.prov-cfg').forEach(function (f) {
            f.classList.toggle('active', f.getAttribute('data-provider') === sel.value);
        });
    }

    sel.addEventListener('change', toggle);
    toggle();
})();
</script>
