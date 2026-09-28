<?php $title = 'Refer a friend'; ?>
<h1>Refer a friend</h1>
<p class="muted">Share your personal link. When a friend signs up through it and becomes a paying member, you earn <strong>7 free days</strong> on your membership.</p>

<section class="card" style="padding:1rem;margin-bottom:1rem;">
    <h2 class="section-title">Your referral link</h2>
    <p style="margin:.25rem 0 .75rem;">
        <code style="word-break:break-all;"><?= e($link) ?></code>
    </p>
    <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
        <input type="text" id="ref-link" value="<?= e($link) ?>" readonly style="flex:1;min-width:260px;">
        <button type="button" class="btn btn-sm" onclick="var i=document.getElementById('ref-link');i.select();try{document.execCommand('copy');}catch(e){}">Copy link</button>
    </div>
    <p class="muted" style="font-size:.85rem;margin:.6rem 0 0;">The link is created for you automatically — just share it.</p>
</section>

<section class="card" style="padding:1rem;">
    <h2 class="section-title">Your referrals</h2>
    <table style="width:100%;max-width:480px;font-size:.9rem;">
        <tr><th style="text-align:left;">Friends who joined</th><td><?= (int) $referrals ?></td></tr>
        <tr><th style="text-align:left;">Rewards earned (7 days each)</th><td><?= (int) $rewarded ?> (<?= ((int) $rewarded) * 7 ?> days)</td></tr>
    </table>
    <p class="muted" style="font-size:.85rem;">A reward is credited when a referred friend becomes a paying member. A paid trial counts once they upgrade.</p>
</section>