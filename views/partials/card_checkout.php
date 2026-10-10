<?php
/**
 * Reusable Braintree (card + PayPal) checkout block for one-off purchases
 * (PPV unlocks / tips). Include with unique variables:
 *   $ccAction   — the POST endpoint (e.g. url('/galleries/{id}/unlock-live'))
 *   $ccId       — unique DOM id (only [A-Za-z0-9_-])
 *   $ccAmount   — the amount to charge (label)
 *   $ccLabel    — what is being paid for (aria/label text)
 * The block renders a short card form (hosted fields), a Pay button and a
 * "or pay with PayPal" option; payment is only ever charged by the server.
 */
$ccAction   = (string) ($ccAction ?? '');
$ccId     = preg_replace('/[^A-Za-z0-9_-]/', '-', (string) ($ccId ?? 'cc'));
$ccAmount = (string) ($ccAmount ?? '0.00');
$ccLabel  = (string) ($ccLabel ?? '');
$ccAmountInput = !empty($ccAmountInput);
$ccTokenUrl = url('/checkout/token');
$ccCsrf   = \App\Core\Csrf::token();
$ccUser   = \App\Core\Auth::check();
// Card checkout is only considered "working" while a LIVE Braintree
// processor is enabled. Until then the card fields + Pay button stay visible
// but disabled ("coming soon"); the PayPal button keeps working.
$btLive = false;
foreach (\App\Models\PaymentProcessor::enabled() as $__pp) {
    if (strtolower((string) ($__pp['provider'] ?? '')) === 'braintree'
        && (int) ($__pp['enabled'] ?? 0) === 1
        && strtolower((string) ($__pp['mode'] ?? 'test')) === 'live') {
        $btLive = true;
        break;
    }
}
?>
<div class="cc-block" id="cc-<?= e($ccId) ?>" data-cc-live="<?= $btLive ? '1' : '0' ?>" style="margin:.6rem 0;">
    <?php if (!$ccUser): ?>
        <p class="muted" style="font-size:.85rem;"><a href="<?= url('/login') ?>">Log in</a> to pay by card.</p>
    <?php else: ?>
        <form method="post" action="<?= e($ccAction) ?>" data-cc-form>
            <input type="hidden" name="_token" value="<?= e($ccCsrf) ?>">
            <input type="hidden" name="provider" value="braintree">
            <input type="hidden" name="payment_method_nonce" data-cc-nonce>
            <?php if ($ccAmountInput): ?>
                <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:.5rem;">
                    <input type="number" step="0.01" min="0.01" name="amount" value="<?= (float) $ccAmount > 0 ? e($ccAmount) : '' ?>" placeholder="Amount" style="width:130px;" required>
                    <input type="text" name="note" placeholder="Add a note" maxlength="500" required style="flex:1;min-width:180px;">
                </div>
            <?php endif; ?>
            <?php if ($btLive): ?>
                <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:stretch;">
                    <div style="flex:2;min-width:220px;border:1px solid var(--pink-300);border-radius:var(--border-radius-sm);padding:.4rem .5rem;background:#fff;" data-cc-number aria-label="Card number"></div>
                    <div style="flex:1;min-width:90px;border:1px solid var(--pink-300);border-radius:var(--border-radius-sm);padding:.4rem .5rem;background:#fff;" data-cc-exp aria-label="Expiry"></div>
                    <div style="flex:1;min-width:80px;border:1px solid var(--pink-300);border-radius:var(--border-radius-sm);padding:.4rem .5rem;background:#fff;" data-cc-cvv aria-label="CVV"></div>
                </div>
            <?php else: ?>
                <p class="muted" style="font-size:.85rem;border:1px dashed var(--pink-300);border-radius:var(--border-radius-sm);padding:.5rem .6rem;">Card payments are coming soon.</p>
            <?php endif; ?>
            <?php if (!$ccAmountInput): ?>
                <input type="hidden" name="amount" value="<?= e($ccAmount) ?>">
                <input type="hidden" name="note" value="Card payment">
            <?php endif; ?>
            <p class="cc-status muted" style="display:none;font-size:.8rem;color:#b91c1c;"></p>
            <p style="margin:.55rem 0 0;display:flex;gap:.5rem;flex-wrap:wrap;">
                <button type="submit" class="btn btn-sm" data-cc-pay<?= $btLive ? '' : ' disabled' ?> style="<?= $btLive ? '' : 'opacity:.55;cursor:not-allowed;' ?>"<?= $btLive ? '' : ' aria-label="Pay by card (coming soon)" title="Braintree card checkout is not available yet."' ?>>Pay $<?= e($ccAmount) ?></button>
                <button type="button" class="btn btn-sm btn-outline" data-cc-paypal>Pay with PayPal</button>
            </p>
        </form>
    <?php endif; ?>
</div>
<?php if ($ccUser && !empty($ccAction)): ?>
<style>[data-cc-number].bt-field, [data-cc-exp].bt-field, [data-cc-cvv].bt-field { min-height:38px; }</style>
<script nonce="<?= csp_nonce() ?>">
(function () {
    var root = document.getElementById('cc-<?= e($ccId) ?>');
    if (!root || root.dataset.ccDone) return;
    root.dataset.ccDone = '1';
    var live = root.dataset.ccLive === '1';
    if (live && !window.galleryBtLoaded) {
        var n = document.createElement('script'); n.src = 'https://js.braintreegateway.com/web/3.103.0/js/client.min.js';
        var h = document.createElement('script'); h.src = 'https://js.braintreegateway.com/web/3.103.0/js/hosted-fields.min.js';
        (document.head || document.documentElement).appendChild(n); (document.head || document.documentElement).appendChild(h);
        window.galleryBtLoaded = 1;
    }
    var form = root.querySelector('[data-cc-form]');
    var statusEl = root.querySelector('.cc-status');
    var hosted = null;
    var busy = false;
    function status(msg) { statusEl.textContent = msg || ''; statusEl.style.display = msg ? 'block' : 'none'; }
    function post(fields) {
        var body = new URLSearchParams(fields);
        return fetch(form.action, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body, credentials: 'same-origin' })
            .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Unexpected response' }; }); });
    }
    function init(token) {
        if (window.braintree && braintree.client && braintree.hostedFields) {
            braintree.client.create({ authorization: token }, function (err, client) {
                if (err) { status('Payment form failed to load.'); return; }
                braintree.hostedFields.create({
                    client: client,
                    fields: {
                        number: { selector: '#cc-<?= e($ccId) ?> [data-cc-number]', placeholder: 'Card number' },
                        expirationDate: { selector: '#cc-<?= e($ccId) ?> [data-cc-exp]', placeholder: 'MM / YY' },
                        cvv: { selector: '#cc-<?= e($ccId) ?> [data-cc-cvv]', placeholder: 'CVV' }
                    }
                }, function (e, instance) { hosted = e ? null : instance; if (e) status('Card fields failed to load.'); });
            });
        }
    }
    if (live) {
        fetch('<?= e($ccTokenUrl) ?>', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) { if (d.client_token) init(d.client_token); else status(d.error || 'Card payments unavailable.'); })
            .catch(function () { status('Could not load the payment form.'); });
    }
    form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        if (busy || !live) return;
        if (!hosted) { status('Payment form not ready yet — please wait a moment.'); return; }
        busy = true; status('Processing…');
        hosted.tokenize({ vault: true }, function (err, payload) {
            busy = false;
            if (err) { status('Please check your card details.'); return; }
            form.querySelector('[data-cc-nonce]').value = payload.nonce;
            form.submit();
        });
    });
    var pp = root.querySelector('[data-cc-paypal]');
    if (pp) pp.addEventListener('click', function () {
        if (busy) return; busy = true; status('Redirecting to PayPal…');
        post({ _token: '<?= e($ccCsrf) ?>', provider: 'paypal' })
            .then(function (r) { busy = false; if (r && r.redirect) { window.location.href = r.redirect; } else { status((r && r.error) || 'PayPal could not start.'); } })
            .catch(function () { busy = false; status('PayPal is unavailable right now.'); });
    });
})();
</script>
<?php endif; ?>