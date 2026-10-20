<?php $title = 'Traffic Links'; ?>

<script nonce="<?= csp_nonce() ?>">
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
function trafficToggleSignups(id) {
    const row = document.getElementById('traffic-signups-' + id);
    if (row) row.style.display = (row.style.display === 'none') ? '' : 'none';
}
function trafficCopyPreview() {
    const el = document.getElementById('traffic-preview');
    if (!el || !el.textContent) return;
    navigator.clipboard.writeText(el.textContent).then(() => {
        const old = el.textContent;
        el.textContent = 'Copied!';
        setTimeout(() => { el.textContent = old; }, 1200);
    });
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

// The empty state links to #traffic-create. A hash alone will not open a
// <details>, so do it here.
function trafficOpenFromHash() {
    const panel = document.getElementById('traffic-create');
    if (panel && location.hash === '#traffic-create') {
        panel.setAttribute('open', '');
        panel.scrollIntoView({ block: 'nearest' });
    }
}
document.addEventListener('DOMContentLoaded', trafficOpenFromHash);
window.addEventListener('hashchange', trafficOpenFromHash);
</script>
<input type="hidden" id="traffic-preview-base" value="<?= e(rtrim(env_value('APP_URL', url('/')), '/')) ?>">

<?php
// Totals for the summary band. Computed here rather than in the controller
// because they are only ever read on this page.
$totalVisits = 0;
$totalVisitors = 0;
$totalSignups = 0;
$activeLinks = 0;
foreach ($links as $l) {
    $totalVisits += (int) $l['visits'];
    $totalVisitors += (int) $l['visitors'];
    $totalSignups += (int) $l['signups'];
    if (!empty($l['active'])) {
        $activeLinks++;
    }
}
$conversion = $totalVisits > 0 ? round(($totalSignups / $totalVisits) * 100, 1) : 0.0;
?>

<section class="adm-section">
    <div class="adm-section-head">
        <h2>Overview</h2>
        <p>Every share link is signed (<code>&amp;s=</code>), so a <code>?c=</code> code invented by a visitor is ignored.</p>
    </div>
    <div class="stat-cards">
        <div class="stat-card kpi"><b><?= number_format($activeLinks) ?></b><small>Active links</small></div>
        <div class="stat-card kpi"><b><?= number_format($totalVisits) ?></b><small>Link visits</small></div>
        <div class="stat-card kpi"><b><?= number_format($totalVisitors) ?></b><small>Unique visitors</small></div>
        <div class="stat-card kpi"><b><?= number_format($totalSignups) ?></b><small>Attributed signups</small></div>
        <div class="stat-card kpi"><b><?= $conversion ?>%</b><small>Visit → signup</small></div>
    </div>
</section>

<section class="adm-section">
    <div class="adm-section-head">
        <h2>Links</h2>
        <p>Terminating a link expires it: it stops recording visits and stops being credited.</p>
    </div>

    <details class="sys-card adm-create" id="traffic-create">
        <summary>New traffic link</summary>
        <form id="traffic-create-form" method="post" action="<?= url('/admin/traffic/create') ?>">
            <?= csrf_field() ?>
            <div class="adm-field-grid">
                <label>
                    <span>Link name</span>
                    <input type="text" name="name" placeholder="TikTok Summer Promo" required>
                </label>
                <label>
                    <span>Code</span>
                    <input type="text" name="code" placeholder="auto from name">
                    <small>Query value after <code>?c=</code>.</small>
                </label>
                <label>
                    <span>Target path</span>
                    <input type="text" name="target_path" value="/signup">
                    <small>Where the link lands.</small>
                </label>
            </div>
            <div class="adm-create-foot">
                <code id="traffic-preview" class="adm-preview"></code>
                <span class="adm-create-actions">
                    <button type="button" class="btn btn-sm btn-outline" data-action="trafficCopyPreview" aria-label="Copy the preview URL">Copy</button>
                    <button type="submit" class="btn btn-sm">Create link</button>
                </span>
            </div>
        </form>
    </details>

<?php if (empty($links)): ?>
    <?= admin_empty('trend', 'No traffic links yet', 'Create one above to start tracking where your signups come from.', ['href' => '#traffic-create', 'label' => 'New traffic link']) ?>
<?php else: ?>
    <div class="users-table-wrap">
    <table class="traffic-table">
        <thead>
            <tr>
                <th>Link</th>
                <th class="tl-target">Target</th>
                <th class="num">Visits</th>
                <th class="num">Visitors</th>
                <th class="num">Signups</th>
                <th class="num">Rate</th>
                <th class="act">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($links as $link): ?>
                <?php
                    $visits = (int) $link['visits'];
                    $signups = (int) $link['signups'];
                    $rate = $visits > 0 ? round(($signups / $visits) * 100, 1) : 0.0;
                    $share = \App\Models\Traffic::buildUrl($link['target_path'], $link['code']);
                    $rows = $linkSignups[(int) $link['id']] ?? [];
                    $dead = empty($link['active']);
                ?>
                <tr class="<?= $dead ? 'is-terminated' : '' ?>">
                    <td>
                        <div class="tl-name">
                            <strong><?= e($link['code']) ?></strong>
                            <?php if ($dead): ?><span class="pill pill-warn">terminated</span><?php endif; ?>
                        </div>
                        <span class="muted"><?= e($link['name']) ?></span>
                    </td>
                    <td class="tl-target"><code><?= e($link['target_path']) ?></code></td>
                    <td class="num"><?= number_format($visits) ?></td>
                    <td class="num"><?= number_format((int) $link['visitors']) ?></td>
                    <td class="num"><?= number_format($signups) ?></td>
                    <td class="num"><?= $visits > 0 ? $rate . '%' : '<span class="muted">&mdash;</span>' ?></td>
                    <td class="act">
                        <div class="adm-actions">
                            <button type="button" class="btn btn-sm btn-outline" data-link="<?= e($share) ?>" data-action="trafficCopy" title="Copy the signed share URL">Copy</button>
                            <button type="button" class="btn btn-sm btn-outline" data-action="trafficToggleSignups" data-arg="<?= (int) $link['id'] ?>"
                                    title="Show the signups attributed to this link">Signups<?php if ($rows !== []): ?> <span class="pill"><?= count($rows) ?></span><?php endif; ?></button>
                            <a class="btn btn-sm" href="<?= url('/admin/traffic/' . (int) $link['id']) ?>" title="Daily breakdown for this link">Detail</a>
                            <span class="adm-actions-gap"></span>
                            <form class="inline" method="post" action="<?= url('/admin/traffic/' . (int) $link['id'] . '/toggle') ?>">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm btn-outline" title="<?= $dead ? 'Start crediting this link again' : 'Stop recording visits and crediting this link' ?>">
                                    <?= $dead ? 'Reactivate' : 'Terminate' ?>
                                </button>
                            </form>
                            <form class="inline" method="post" action="<?= url('/admin/traffic/' . (int) $link['id'] . '/delete') ?>"
                                  data-confirm="Delete traffic link <?= e($link['code']) ?> and its visit history?">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm btn-danger" title="Delete this link and its visit history">Delete</button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php // Always rendered so the Signups toggle has something to
                      // reveal, even on a link that has not converted yet. ?>
                <tr id="traffic-signups-<?= (int) $link['id'] ?>" style="display:none;">
                    <td colspan="7" class="tl-detail">
                        <div class="tl-detail-head">
                            <strong><?= count($rows) ?> signup<?= count($rows) === 1 ? '' : 's' ?> from <?= e($link['code']) ?></strong>
                            <a href="<?= url('/admin/traffic/' . (int) $link['id']) ?>">Open full detail &rarr;</a>
                        </div>
                        <?php if ($rows === []): ?>
                            <p class="muted u-m0">No signups attributed to this link yet<?= $visits > 0 ? ' — it has ' . number_format($visits) . ' visit' . ($visits === 1 ? '' : 's') . ' but no conversions yet' : '' ?>.</p>
                        <?php else: ?>
                        <table class="tl-sub">
                            <thead>
                                <tr>
                                    <th>Account</th>
                                    <th>Signed up</th>
                                    <th>Plan</th>
                                    <th>Source</th>
                                    <th class="act">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $s): ?>
                                    <tr>
                                        <td><a href="<?= url('/admin/users/' . (int) $s['id']) ?>"><?= e($s['email']) ?></a></td>
                                        <td><?= e(tzdate('M j, Y H:i', $s['created_at'])) ?></td>
                                        <td><?= !empty($s['plan']) ? e($s['plan']) : '<span class="muted">&mdash;</span>' ?></td>
                                        <td><?= trafficSourceLabel($s) ?></td>
                                        <td class="act">
                                            <form class="inline" method="post" action="<?= url('/admin/traffic/signups/' . (int) $s['id'] . '/direct') ?>"
                                                  data-confirm="Mark <?= e($s['email']) ?> as a direct signup and remove this attribution?">
                                                <?= csrf_field() ?>
                                                <button type="submit" class="btn btn-sm btn-outline">Mark direct</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <th scope="row"><?= count($links) ?> link<?= count($links) === 1 ? '' : 's' ?></th>
                <td></td>
                <td class="num"><?= number_format($totalVisits) ?></td>
                <td class="num"><?= number_format($totalVisitors) ?></td>
                <td class="num"><?= number_format($totalSignups) ?></td>
                <td class="num"><?= $totalVisits > 0 ? $conversion . '%' : '&mdash;' ?></td>
                <td class="act"></td>
            </tr>
        </tfoot>
    </table>
    </div>
<?php endif; ?>
</section>

<section class="adm-section">
    <div class="adm-section-head">
        <h2>Recent signups</h2>
        <p>The newest accounts attributed to any traffic link.</p>
    </div>
<?php if (empty($recentSignups)): ?>
    <?= admin_empty('users', 'No signups attributed yet', 'Once a visitor arrives through a link and signs up, they appear here with their source attached.') ?>
<?php else: ?>
    <div class="users-table-wrap">
    <table class="traffic-table">
        <thead>
            <tr>
                <th>Account</th>
                <th>Link</th>
                <th>Signed up</th>
                <th>Status</th>
                <th>Plan</th>
                <th>Source</th>
                <th>Campaign</th>
                <th>Content</th>
                <th>Term</th>
                <th class="act">Action</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($recentSignups as $s): ?>
                <tr>
                    <td><a href="<?= url('/admin/users/' . (int) $s['id']) ?>"><?= e($s['email']) ?></a></td>
                    <td>
                        <strong><?= e($s['link_code'] ?? '—') ?></strong>
                        <?php if (!empty($s['link_name'])): ?><br><span class="muted"><?= e($s['link_name']) ?></span><?php endif; ?>
                    </td>
                    <td><?= e(tzdate('M j, Y H:i', $s['created_at'])) ?></td>
                    <td><span class="status-badge <?= ($s['status'] ?? 'active') === 'suspended' ? 'cancelled' : '' ?>"><?= e($s['status'] ?? 'active') ?></span></td>
                    <td><?= !empty($s['plan']) ? e($s['plan']) : '<span class="muted">&mdash;</span>' ?></td>
                    <td><?= trafficSourceLabel($s) ?></td>
                    <td><?= !empty($s['utm_campaign']) ? e($s['utm_campaign']) : '<span class="muted">&mdash;</span>' ?></td>
                    <td><?= !empty($s['utm_content']) ? e($s['utm_content']) : '<span class="muted">&mdash;</span>' ?></td>
                    <td><?= !empty($s['utm_term']) ? e($s['utm_term']) : '<span class="muted">&mdash;</span>' ?></td>
                    <td class="act">
                        <form class="inline" method="post" action="<?= url('/admin/traffic/signups/' . (int) $s['id'] . '/direct') ?>"
                              data-confirm="Mark <?= e($s['email']) ?> as a direct signup and remove this attribution?">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-sm btn-outline">Mark direct</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
<?php endif; ?>
</section>