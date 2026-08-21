<?php $title = 'Membership'; ?>

<?php
// Membership pricing page. Users who already have access see their current
// plan first; everyone sees the available plans with a subscribe button.
// Payments are manual/placeholder, so subscribing creates a request that an
// admin approves.
?>

<?php
$_activeSiteTpl = \App\Models\SiteTemplate::active(\App\Models\SiteTemplate::SCOPE_USER);
if ($_activeSiteTpl !== null):
$_tplChanges = json_decode((string) $_activeSiteTpl['config_json'], true) ?: [];
$_tplJson = json_encode($_tplChanges, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
?>
<script>
(function(){
    var changes=<?= $_tplJson ?>;
    function applyOrder(c){
        if(c.parentKey==='body')return;
        var p=c.parentKey==='body'?document.body:null;
        if(c.parentOrigin){p=document.querySelector(c.parentOrigin)||p;if(p&&c.parentKey)p.setAttribute('data-se-move-key',c.parentKey);}
        if(!p)return;
        (c.items||[]).map(function(item){var el=item.origin?document.querySelector(item.origin):null;if(!el)el=document.querySelector('[data-se-move-key="'+item.key+'"]');if(el&&item.key)el.setAttribute('data-se-move-key',item.key);if(el&&item.styles)Object.keys(item.styles).forEach(function(k){if(item.styles[k])el.style.setProperty(k,item.styles[k]);});return el;}).filter(Boolean).forEach(function(el){p.appendChild(el);});
    }
    changes.forEach(function(c){
        try{
            if(c.type==='order'){applyOrder(c);return;}
            var el=c.key?document.querySelector('[data-se-move-key="'+c.key+'"]'):null;
            if(!el&&c.origin){el=document.querySelector(c.origin);if(el&&c.key)el.setAttribute('data-se-move-key',c.key);}
            if(!el)el=document.querySelector(c.selector);
            if(!el)return;
            if(c.type==='hide'||c.type==='delete')el.style.display='none';
            else if(c.type==='move'){
                if(c.anchor||c.parent){var a=c.anchorKey?document.querySelector('[data-se-move-key="'+c.anchorKey+'"]'):null;if(!a&&c.anchorOrigin){a=document.querySelector(c.anchorOrigin);if(a&&c.anchorKey)a.setAttribute('data-se-move-key',c.anchorKey);}if(!a&&c.anchor)a=document.querySelector(c.anchor);var p=c.parent==='body'?document.body:document.querySelector(c.parent);if(a&&a!==el&&!el.contains(a)){if(c.position==='before')a.parentNode.insertBefore(el,a);else a.parentNode.insertBefore(el,a.nextSibling);}else if(p&&c.position==='append')p.appendChild(el);}
                else {
                var vw=document.documentElement.clientWidth||1,vh=document.documentElement.clientHeight||1;
                var rect=el.getBoundingClientRect();
                var mx=c.targetXRatio!=null?c.targetXRatio*vw-rect.left:(c.dxRatio!=null?c.dxRatio*vw:(c.dx||0));
                var my=c.targetYRatio!=null?c.targetYRatio*vh-rect.top:(c.dyRatio!=null?c.dyRatio*vh:(c.dy||0));
                el.style.setProperty('transform','translate('+mx+'px,'+my+'px)','important');
                }
            }
            else if(c.type==='restyle')Object.keys(c.styles||{}).forEach(function(k){el.style[k]=c.styles[k]});
            else if(c.type==='add'){
                var t=c.parent?document.querySelector(c.parent):document.body;
                if(t){var d=document.createElement(c.tag||'div');d.innerHTML=c.html||'';
                if(c.styles)Object.keys(c.styles).forEach(function(k){d.style[k]=c.styles[k]});
                if(c.position==='prepend')t.prepend(d);else if(c.position==='before')t.parentElement.insertBefore(d,t);
                else if(c.position==='after')t.parentElement.insertBefore(d,t.nextSibling);else t.appendChild(d);}
            }
        }catch(e){}
    });
})();
</script>
<?php endif; ?>

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
                    <?php else: ?>
                        <form method="post" action="<?= url('/membership/subscribe') ?>" style="order:2;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="plan_id" value="<?= (int) $plan['id'] ?>">
                            <button type="submit" class="btn">Subscribe</button>
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
