<?php $title = 'Traffic Link: ' . e($link['code']); ?>

<script>
function trafficCopy(el) {
    navigator.clipboard.writeText(el.dataset.link).then(() => {
        const old = el.textContent;
        el.textContent = 'Copied!';
        setTimeout(() => { el.textContent = old; }, 1200);
    });
}
</script>

<?php $share = \App\Models\Traffic::buildUrl($link['target_path'], $link['code']); ?>

<h2><?= e($link['name']) ?></h2>
<p class="muted" style="margin-top:0">
    Code <strong><?= e($link['code']) ?></strong> &middot; targets <strong><?= e($link['target_path']) ?></strong>
    <?php if (empty($link['active'])): ?> &middot; <span class="pill pill-warn">terminated</span><?php endif; ?>
</p>

<h3>Share Link</h3>
<p style="text-align:center;">
    <code id="traffic-share" style="word-break:break-all;"><?= e($share) ?></code>
    <br><br>
    <button type="button" class="btn" data-link="<?= e($share) ?>" onclick="trafficCopy(this)">Copy Link</button>
</p>

<h3>Edit Link</h3>
<form method="post" action="<?= url('/admin/traffic/' . (int) $link['id'] . '/update') ?>" style="max-width:560px; margin:0 auto;">
    <?= csrf_field() ?>
    <p style="text-align:center;">
        <input type="text" name="name" value="<?= e($link['name']) ?>" style="width:100%;" required>
    </p>
    <p style="text-align:center;">
        <input type="text" name="code" value="<?= e($link['code']) ?>" style="width:100%;" required>
    </p>
    <p style="text-align:center;">
        <input type="text" name="target_path" value="<?= e($link['target_path']) ?>" style="width:100%;">
    </p>
    <p style="text-align:center;">
        <button type="submit" class="btn">Save</button>
        <a class="btn btn-sm btn-outline" href="<?= url('/admin/traffic') ?>">Back to links</a>
    </p>
</form>

<h3>Last 30 Days</h3>
<?php
    $sparkline = static function (array $values, int $width = 160, int $height = 32, string $color = '#0ea5e9'): string {
        if (empty($values)) return '';
        $max = max($values);
        if ($max <= 0) return '<span class="muted" style="font-size:.75rem;">no data</span>';
        $step = $max / ($height - 4);
        $pts = [];
        $n = count($values);
        foreach ($values as $i => $val) {
            $x = $n === 1 ? $width / 2 : ($i / ($n - 1)) * $width;
            $y = $height - ($val / $step + 2);
            $pts[] = round($x, 1) . ',' . round($y, 1);
        }
        $points = implode(' ', $pts);
        return '<svg width="' . $width . '" height="' . $height . '" viewBox="0 0 ' . $width . ' ' . $height . '">'
            . '<polyline fill="none" stroke="' . $color . '" stroke-width="1.5" points="' . $points . '"/></svg>';
    };
    $visitSeries = array_column($days, 'visits');
    $totals = ['visits' => array_sum(array_column($days, 'visits')), 'visitors' => array_sum(array_column($days, 'visitors')), 'signups' => array_sum(array_column($days, 'signups'))];
?>
<p class="stats-panel">
    <?= $sparkline($visitSeries) ?>
</p>
<table>
    <thead>
        <tr>
            <th>Date</th>
            <th style="text-align:right;">Visits</th>
            <th style="text-align:right;">Visitors</th>
            <th style="text-align:right;">Signups</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($days as $day): ?>
            <tr>
                <td><?= e($day['date']) ?></td>
                <td style="text-align:right;"><?= number_format($day['visits']) ?></td>
                <td style="text-align:right;"><?= number_format($day['visitors']) ?></td>
                <td style="text-align:right;"><?= number_format($day['signups']) ?></td>
            </tr>
        <?php endforeach; ?>
        <tr>
            <th>Total (30 days)</th>
            <th style="text-align:right;"><?= number_format($totals['visits']) ?></th>
            <th style="text-align:right;"><?= number_format($totals['visitors']) ?></th>
            <th style="text-align:right;"><?= number_format($totals['signups']) ?></th>
        </tr>
    </tbody>
</table>

<h3>Attributed Signups</h3>
<?php if (empty($signups)): ?>
    <p class="muted">No signups attributed to this link yet.</p>
<?php else: ?>
    <table>
        <thead>
            <tr>
                <th>Account</th>
                <th>Signed up</th>
                <th>Source</th>
                <th>Campaign</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($signups as $s): ?>
                <tr>
                    <td><a href="<?= url('/admin/users/' . (int) $s['id']) ?>"><?= e($s['email']) ?></a></td>
                    <td><?= e(tzdate('M j, Y H:i', $s['created_at'])) ?></td>
                    <td>
                        <?php
                            $parts = array_filter([$s['utm_source'], $s['utm_medium']], static fn ($v) => $v !== null && $v !== '');
                            echo $parts === [] ? '<span class="muted">direct</span>' : e(implode(' / ', $parts));
                        ?>
                    </td>
                    <td><?= $s['utm_campaign'] !== null && $s['utm_campaign'] !== '' ? e($s['utm_campaign']) : '<span class="muted">&mdash;</span>' ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<h3>Recent Visits</h3>
<?php if (empty($visits)): ?>
    <p class="muted">No traffic recorded yet.</p>
<?php else: ?>
    <table>
        <thead>
            <tr>
                <th>When</th>
                <th>Date</th>
                <th>IP</th>
                <th>User agent</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($visits as $v): ?>
                <tr>
                    <td><?= e(tzdate('M j, Y H:i:s', $v['landed_at'])) ?></td>
                    <td><?= e($v['ref_date']) ?></td>
                    <td><?= $v['ip'] !== null ? e($v['ip']) : '<span class="muted">&mdash;</span>' ?></td>
                    <td class="muted"><?= $v['user_agent'] !== null ? e($v['user_agent']) : '&mdash;' ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>