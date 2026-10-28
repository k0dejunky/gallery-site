<?php
/**
 * Braintree checkout: custom card fields + PayPal funding option.
 * Standalone page (no site layout) with its own CSP override.
 */
$csrfToken = csrf_field();
$planName  = e($plan['name']);
$planPrice = number_format((float) ($sale !== null ? $sale['sale_price'] : $plan['price']), 2);
$planCycle = \App\Models\Plan::cycleLabel($plan['billing_cycle'] ?? '');
$planId    = (int) $plan['id'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<meta name="description" content="Secure checkout for your <?= e($plan['name']) ?> membership.">
<title>Checkout — <?= $planName ?></title>
<style>
:root {
    --purple-900:#f5edf7;--purple-800:#ffffff;--purple-700:#b9a0c4;--purple-600:#d946ef;
    --purple-500:#e879f9;--purple-400:#e9aefc;--purple-300:#c084fc;
    --pink-100:#0d0612;--pink-200:#050208;--pink-300:#2a1638;--pink-400:#d946ef;
    --card-bg:#150a1d;--card-border:#2a1638;--card-radius:12px;--border-radius:10px;
    --shadow:0 8px 32px rgba(0,0,0,.5);--input-padding:.55rem .75rem;
    --input-border-width:1px;--input-radius:8px;
}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:sans-serif;background:var(--pink-200);color:var(--purple-900);
     display:flex;justify-content:center;padding:2rem 1rem;min-height:100vh;}
.checkout{max-width:480px;width:100%;}
h1{font-size:1.5rem;text-align:center;margin-bottom:.5rem;color:var(--purple-800);}
.plan-summary{text-align:center;margin-bottom:1.25rem;}
.plan-summary .price{font-size:1.6rem;font-weight:bold;}
.plan-summary .cycle{font-size:.85rem;color:var(--purple-700);opacity:.7;}
.plan-summary .sale{color:#fbbf24;font-weight:600;font-size:.85rem;margin-top:.25rem;}
.card{background:var(--card-bg);border:1px solid var(--card-border);border-radius:var(--card-radius);
      padding:1.25rem;margin-bottom:1rem;box-shadow:var(--shadow);}
.card h2{font-size:1.1rem;margin-bottom:.75rem;color:var(--purple-800);}
label{display:block;font-size:.82rem;font-weight:600;margin-bottom:.3rem;color:var(--purple-700);}
.bt-field{border:var(--input-border-width) solid var(--pink-300);border-radius:var(--input-radius);
          padding:.55rem .75rem;background:var(--pink-100);min-height:40px;margin-bottom:.75rem;
          transition:border-color .15s;}
.bt-field.focus{border-color:var(--purple-400);outline:2px solid var(--purple-400);}
.bt-field.error{border-color:#f87171;}
.field-row{display:flex;gap:.75rem;}
.field-row>div{flex:1;}
.error-text{color:#f87171;font-size:.78rem;margin-top:-.5rem;margin-bottom:.5rem;display:none;}
.error-text.show{display:block;}
.paypal-placeholder{border:2px dashed var(--pink-300);border-radius:var(--input-radius);
                     padding:1rem;text-align:center;color:var(--purple-700);opacity:.6;
                     font-size:.9rem;margin-bottom:.75rem;}
.btn{display:block;width:100%;padding:.75rem;border:none;border-radius:var(--border-radius);
     background:var(--purple-600);color:#0a0210;font-size:1rem;font-weight:600;cursor:pointer;
     transition:background .15s;margin-top:.5rem;}
.btn:hover{background:var(--purple-500);}
.btn:disabled{opacity:.5;cursor:not-allowed;}
.btn-outline{background:var(--pink-100);color:var(--purple-700);border:1px solid var(--pink-400);}
.btn-outline:hover{background:var(--pink-200);}
.spinner{display:none;width:20px;height:20px;border:3px solid rgba(255,255,255,.3);
         border-top-color:#fff;border-radius:50%;animation:spin .6s linear infinite;
         margin:0 auto;}
@keyframes spin{to{transform:rotate(360deg)}}
.status{padding:.75rem;border-radius:var(--input-radius);margin-bottom:.75rem;font-size:.85rem;display:none;}
.status.show{display:block;}
.status.error{background:#2a1010;color:#fca5a5;border:1px solid #7f1d1d;}
.status.success{background:#0a2015;color:#86efac;border:1px solid #166534;}
.back-link{display:block;text-align:center;margin-top:1rem;font-size:.85rem;color:var(--purple-600);text-decoration:none;}
.back-link:hover{text-decoration:underline;}
</style>
</head>
<body>
<div class="checkout">
    <h1>Checkout</h1>
    <div class="plan-summary">
        <div class="price">$<?= $planPrice ?></div>
        <div class="cycle">per <?= e(strtolower($planCycle)) ?></div>
        <?php if ($sale !== null): ?>
            <div class="sale"><?= e($sale['name']) ?> applied</div>
        <?php endif; ?>
    </div>

    <div id="status" class="status"></div>

    <form id="btForm" method="post" action="<?= url('/membership/subscribe') ?>">
        <?= $csrfToken ?>
        <input type="hidden" name="plan_id" value="<?= $planId ?>">
        <input type="hidden" name="payment_processor" value="<?= (int) $processor['id'] ?>">
        <input type="hidden" name="payment_method_nonce" id="nonce" value="">

        <!-- Card fields -->
        <div class="card" id="cardSection">
            <h2>Card details</h2>
            <div>
                <label for="card-number">Card number</label>
                <div class="bt-field" id="card-number"></div>
            </div>
            <div class="field-row">
                <div>
                    <label for="card-expiry">Expiry</label>
                    <div class="bt-field" id="card-expiry"></div>
                </div>
                <div>
                    <label for="card-cvv">CVV</label>
                    <div class="bt-field" id="card-cvv"></div>
                </div>
            </div>
            <div id="field-error" class="error-text"></div>
        </div>

        <!-- PayPal placeholder -->
        <div class="card" id="paypalSection" style="display:none;">
            <h2>PayPal</h2>
            <div id="paypal-button"></div>
        </div>

        <button type="submit" class="btn" id="submitBtn">
            <span id="btnLabel">Pay $<?= $planPrice ?></span>
            <span id="btnSpinner" class="spinner"></span>
        </button>
    </form>

    <a class="back-link" href="<?= url('/membership') ?>">&larr; Back to plans</a>
</div>

<script nonce="<?= csp_nonce() ?>" src="https://js.braintreegateway.com/web/3.103.0/js/client.min.js"></script>
<script nonce="<?= csp_nonce() ?>" src="https://js.braintreegateway.com/web/3.103.0/js/hosted-fields.min.js"></script>
<script nonce="<?= csp_nonce() ?>" src="https://js.braintreegateway.com/web/3.103.0/js/paypal-checkout.min.js"></script>
<script nonce="<?= csp_nonce() ?>">
(function(){
    var form = document.getElementById('btForm');
    var nonceInput = document.getElementById('nonce');
    var submitBtn = document.getElementById('submitBtn');
    var btnLabel = document.getElementById('btnLabel');
    var btnSpinner = document.getElementById('btnSpinner');
    var statusEl = document.getElementById('status');
    var fieldError = document.getElementById('field-error');
    var hostedFieldsInstance = null;

    function showStatus(msg, type) {
        statusEl.textContent = msg;
        statusEl.className = 'status show ' + type;
    }

    function setLoading(on) {
        submitBtn.disabled = on;
        btnLabel.style.display = on ? 'none' : '';
        btnSpinner.style.display = on ? 'inline-block' : 'none';
    }

    // Request a client token from the server
    fetch('<?= url('/membership/braintree-token') ?>?plan_id=<?= $planId ?>', {
        method: 'GET',
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
    })
    .then(function(r){ return r.json(); })
    .then(function(data){
        if (data.error) { showStatus(data.error, 'error'); return; }
        initBraintree(data.client_token, data.environment);
    })
    .catch(function(e){ showStatus('Could not load payment form. Please refresh.', 'error'); });

    function initBraintree(clientToken, environment) {
        braintree.client.create({ authorization: clientToken }, function(err, clientInstance) {
            if (err) { showStatus('Payment initialisation failed.', 'error'); return; }

            // Hosted Fields for card input
            braintree.hostedFields.create({
                client: clientInstance,
                styles: {
                    input: {
                        'font-size': '15px',
                        'font-family': 'sans-serif',
                        'color': '#1e1033'
                    },
                    'input:focus': { 'outline': 'none' }
                },
                fields: {
                    number: { selector: '#card-number', placeholder: 'Card number' },
                    expirationDate: { selector: '#card-expiry', placeholder: 'MM / YY' },
                    cvv: { selector: '#card-cvv', placeholder: 'CVV' }
                }
            }, function(err, instance) {
                if (err) { showStatus('Card fields failed to load.', 'error'); return; }
                hostedFieldsInstance = instance;

                // Focus styling
                instance.on('focus', function(e) {
                    document.getElementById('card-' + e.emittedBy).classList.add('focus');
                });
                instance.on('blur', function(e) {
                    document.getElementById('card-' + e.emittedBy).classList.remove('focus');
                });
                instance.on('cardTypeChange', function(e) {
                    // Could show card brand icon here
                });
                instance.on('validityChange', function(e) {
                    var field = document.getElementById('card-' + e.emittedBy);
                    if (e.valid) {
                        field.classList.remove('error');
                    }
                });
            });

            // PayPal funding button
            braintree.paypalCheckout.create({
                client: clientInstance
            }, function(err, paypalCheckoutInstance) {
                if (err) return; // PayPal not configured — silently skip
                document.getElementById('paypalSection').style.display = '';

                braintree.paypalCheckout.create({
                    client: clientInstance
                }, function(err, ppInstance) {
                    if (err) return;

                    window.paypal.Buttons({
                        style: { layout: 'vertical', color: 'gold', shape: 'rect', label: 'pay', height: 48 },
                        fundingAllowed: function(fundingSource) {
                            return fundingSource === window.paypal.FUNDING.PAYPAL;
                        },
                        createOrder: function() {
                            return ppInstance.createPayment({
                                flow: 'vault',
                                billingAgreementDescription: <?= json_encode($planName . ' — $' . $planPrice . '/' . strtolower($planCycle)) ?>,
                                currency: 'USD'
                            });
                        },
                        onApprove: function(data) {
                            return ppInstance.tokenizePayment(data).then(function(payload) {
                                nonceInput.value = payload.nonce;
                                form.submit();
                            });
                        },
                        onError: function(err) {
                            showStatus('PayPal error: ' + err, 'error');
                        }
                    }).render('#paypal-button').catch(function() {});
                });
            });
        });
    }

    // Form submit — tokenize card fields
    form.addEventListener('submit', function(e) {
        e.preventDefault();

        if (nonceInput.value !== '') {
            // PayPal already set the nonce — submit directly
            setLoading(true);
            form.submit();
            return;
        }

        if (!hostedFieldsInstance) {
            showStatus('Payment system not ready. Please refresh.', 'error');
            return;
        }

        setLoading(true);
        fieldError.classList.remove('show');
        fieldError.textContent = '';

        hostedFieldsInstance.tokenize({ vault: true }, function(err, payload) {
            if (err) {
                setLoading(false);
                if (err.code === 'HOSTED_FIELDS_FIELDS_INVALID') {
                    var msg = 'Please check your card details.';
                    if (err.details && err.details.invalidFieldKeys) {
                        msg = 'Invalid: ' + err.details.invalidFieldKeys.join(', ').replace(/number/g, 'card number').replace(/expirationDate/g, 'expiry').replace(/cvv/g, 'CVV');
                    }
                    fieldError.textContent = msg;
                    fieldError.classList.add('show');
                } else if (err.code === 'HOSTED_FIELDS_TOKENIZATION_CVV_VERIFICATION_FAILED') {
                    fieldError.textContent = 'CVV verification failed. Please check and try again.';
                    fieldError.classList.add('show');
                } else {
                    showStatus('Tokenization error: ' + (err.message || err.code || 'unknown'), 'error');
                }
                return;
            }

            nonceInput.value = payload.nonce;
            form.submit();
        });
    });
})();
</script>
</body>
</html>
