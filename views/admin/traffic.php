<?php $title = 'Traffic Links'; ?>

<script>
function trafficCopy(el) {
    navigator.clipboard.writeText(el.dataset.link).then(() => {
        const old = el.textContent;
        el.textContent = 'Copied!';
        setTimeout(() => { el.textContent = old; }, 1200);
    });
}
function trafficSlugify(s) {
    return s.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 64);
}
function trafficWireForm() {
    const form = document.getElementById('traffic-create-form');
    if (!form) return;
    const name = form.querySelector('[name=name]');
    const code = form.querySelector('[name=code]');
    const target = form.querySelector('[name=target_path]');
    const preview = document.getElementById('traffic-preview');
    const previewBase = document.getElementById('traffic-preview-base').value;
    if (!name || !code || !target || !preview) return;
    name.addEventListener('input', () => {
        if (!code.dataset.touched) { code.value = trafficSlugify(name.value); }
        trafficPreview();
    });
    code.addEventListener('input', () => { code.dataset.touched = '1'; trafficPreview(); });
    target.addEventListener('input', trafficPreview);
    function trafficPreview() {
        let p = target.value || '/signup';
        if (p && p[0] !== '/') p = '/' + p;
        preview.textContent = previewBase + p + '?c=' + encodeURIComponent(code.value);
    }
    trafficPreview();
}
document.addEventListener('DOMContentLoaded', trafficWireForm);
</script>

<h2>Create a Traffic Link</h2>
<p class="muted" style="margin-top:0">
    Generate a custom link (short <code>?c=code</code>, optionally with UTM tags) that records where
    visitors come from and credits the source at signup. Every share link is
    <strong>signed</strong> (<code>&amp;s=</code>), so forged <code>?c=</code> codes invented by
    visitors are ignored. Terminating a link expires it: it stops recording visits and stops being credited.
</p>
<form id="traffic-create-form" method="post" action="<?= url('/admin/traffic/create') ?>" style="max-width:640px; margin:0 auto;">
    <?= csrf_field() ?>
    <p style="text-align:center;">
        <input type="text" name="name" placeholder="Link name, e.g. TikTok Summer Promo" style="width:100%;" required>
    </p>
    <p style="text-align:center;">
        <input type="text" name="code" placeholder="Code (auto-generated from name if blank)" style="width:100%;">
    </p>
    <p style="text-align:center;">
        <input type="text" name="target_path" placeholder="/signup" value="/signup" style="width:100%;">
    </p>
    <p style="text-align:center;">
        <button type="submit" class="btn">Create Link</button>
    </p>
</form>
<p id="traffic-preview" class="muted" style="text-align:center; word-break:break-all;"></p>
<p class="muted" style="text-align:center; margin-top:0;">The <code>&amp;s=</code> signature is appended automatically once the link is created &mdash; use the Copy button in the table.</p>
<input type="hidden" id="traffic-preview-base" value="<?= e(rtrim(env_value('APP_URL', url('/')), '/')) ?>">

<h2>Links</h2>
<?php if (empty($links)): ?>
    <p class="muted">No traffic links yet. Create one above to start tracking where your signups come from.</p>
<?php else: ?>
    <table>
        <thead>
            <tr>
                <th>Link</th>
                <th>Targets</th>
                <th style="text-align:right;">Visits</th>
                <th style="text-align:right;">Visitors</th>
                <th style="text-align:right;">Signups</th>
                <th style="text-align:right;">Rate</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($links as $link): ?>
                <?php
                    $visits = (int) $link['visits'];
                    $signups = (int) $link['signups'];
                    $rate = $visits > 0 ? round(($signups / $visits) * 100, 1) : 0.0;
                    $share = \App\Models\Traffic::buildUrl($link['target_path'], $link['code']);
                ?>
                <tr>
                    <td>
                        <strong><?= e($link['code']) ?></strong>
                        <?php if (empty($link['active'])): ?><span class="pill pill-warn">terminated</span><?php endif; ?>
                        <br><span class="muted"><?= e($link['name']) ?></span>
                    </td>
                    <td><?= e($link['target_path']) ?></td>
                    <td style="text-align:right;"><?= number_format($visits) ?></td>
                    <td style="text-align:right;"><?= number_format((int) $link['visitors']) ?></td>
                    <td style="text-align:right;"><?= number_format($signups) ?></td>
                    <td style="text-align:right;"><?= $rate ?>%</td>
                    <td>
                        <button type="button" class="btn btn-sm btn-outline" data-link="<?= e($share) ?>" onclick="trafficCopy(this)">Copy</button>
                        <a class="btn btn-sm" href="<?= url('/admin/traffic/' . (int) $link['id']) ?>">Detail</a>
                        <form class="inline" method="post" action="<?= url('/admin/traffic/' . (int) $link['id'] . '/toggle') ?>">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-sm <?= empty($link['active']) ? 'btn-outline' : 'btn-danger' ?>">
                                <?= empty($link['active']) ? 'Reactivate' : 'Terminate' ?>
                            </button>
                        </form>
                        <form class="inline" method="post" action="<?= url('/admin/traffic/' . (int) $link['id'] . '/delete') ?>"
                              onsubmit="return confirm('Delete traffic link <?= e($link['code']) ?> and its visit history?');">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>