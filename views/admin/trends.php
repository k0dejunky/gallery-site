<?php $title = 'Trends'; ?>

<?php
$isAllTime = $currentPeriod === 'all';
$rangeLabel = $periods[$currentPeriod]['range'];
$isCompare  = ($viewMode ?? 'single') === 'compare';

$trendBadge = static function (array $trend): string {
    $label = $trend['label'];

    if ($label === 'new') {
        return '<span class="trend trend-new">New</span>';
    }

    if ($label === 'flat' || $label === 'total') {
        return '<span class="trend trend-flat">&mdash;</span>';
    }

    $arrow = $label === 'up' ? '&#9650;' : '&#9660;';
    $class = $label === 'up' ? 'trend trend-up' : 'trend trend-down';

    return '<span class="' . $class . '">' . $arrow . ' ' . abs((int) $trend['pct']) . '%</span>';
};

// Mini sparkline as inline SVG (no external dependency)
$sparkline = static function (array $values, int $width = 120, int $height = 28, string $color = '#0ea5e9'): string {
    if (empty($values)) return '';
    $max = max($values);
    if ($max <= 0) return '<span class="muted" style="font-size:.75rem;">no data</span>';

    $points = [];
    $n = count($values);
    foreach ($values as $i => $v) {
        $x = round($i / max(1, $n - 1) * $width, 1);
        $y = round($height - ($v / $max) * ($height - 2) - 1, 1);
        $points[] = "$x,$y";
    }

    return '<svg width="' . $width . '" height="' . $height . '" viewBox="0 0 ' . $width . ' ' . $height . '" style="vertical-align:middle;">'
         . '<polyline points="' . implode(' ', $points) . '" fill="none" stroke="' . $color . '" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>'
         . '</svg>';
};
?>

<?php // Period + view mode selector ?>
<form method="get" action="<?= url('/admin/trends') ?>" class="stats-period" style="display:flex;gap:.75rem;align-items:center;flex-wrap:wrap;">
    <label for="stats-period-select">Trend period</label>
    <select name="period" id="stats-period-select" onchange="this.form.submit()">
        <?php foreach ($periods as $key => $info): ?>
            <option value="<?= e($key) ?>"<?= $key === $currentPeriod ? ' selected' : '' ?>><?= e($info['label']) ?></option>
        <?php endforeach; ?>
    </select>
    <span class="muted">|</span>
    <a href="<?= e(url('/admin/trends?period=' . $currentPeriod . '&view=single')) ?>"
       class="btn btn-sm<?= !$isCompare ? ' storage-period-active' : '' ?>">Single period</a>
    <a href="<?= e(url('/admin/trends?period=' . $currentPeriod . '&view=compare')) ?>"
       class="btn btn-sm<?= $isCompare ? ' storage-period-active' : '' ?>">Compare periods</a>
</form>

<div class="stats-grid">

    <?php if ($isCompare && !empty($multiTrends)): ?>
    <?php // === COMPARE MODE: multi-period table + sparklines === ?>
    <section class="stats-panel" style="flex:1 1 100%;">
        <h2>Category Trends — Multi-Period Comparison</h2>
        <p class="muted" style="margin-top:0;">
            Daily, weekly, and monthly views side-by-side. Columns show current window views and trend direction for each period.
        </p>
        <div class="users-table-wrap"><table class="users-table">
            <thead>
                <tr>
                    <th>Category</th>
                    <th>Galleries</th>
                    <th style="text-align:right;">24h</th>
                    <th style="text-align:center;">Trend</th>
                    <th style="text-align:right;">7 days</th>
                    <th style="text-align:center;">Trend</th>
                    <th style="text-align:right;">30 days</th>
                    <th style="text-align:center;">Trend</th>
                    <th>Last 30 days</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($multiTrends as $cat): ?>
                    <tr>
                        <td><a href="<?= url('/galleries/category/' . e($cat['slug'])) ?>"><?= e($cat['name']) ?></a></td>
                        <td><?= number_format($cat['gallery_count']) ?></td>
                        <?php foreach (['daily', 'weekly', 'monthly'] as $pk): ?>
                            <?php $p = $cat['periods'][$pk] ?? ['cur' => 0, 'trend' => ['label' => 'flat', 'pct' => 0]]; ?>
                            <td style="text-align:right;"><?= number_format($p['cur']) ?></td>
                            <td style="text-align:center;"><?= $trendBadge($p['trend']) ?></td>
                        <?php endforeach; ?>
                        <td>
                            <?php
                                $vals = $sparklines[$cat['id']] ?? [];
                                echo $sparkline(array_values($vals));
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($multiTrends)): ?>
                    <tr><td colspan="9" class="muted">No categories yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table></div>
    </section>

    <?php else: ?>
    <?php // === SINGLE PERIOD MODE (original behavior) === ?>
    <section class="stats-panel">
        <h2>Trending Categories</h2>
        <p class="muted" style="margin-top:0">
            <?= $isAllTime ? 'All-time category views.' : 'Category views over the ' . e($rangeLabel) . ' vs. the same period before.' ?>
        </p>
        <?php if (empty($categoryTrends)): ?>
            <p class="muted">No categories yet.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Category</th>
                        <th>Galleries</th>
                        <th>Views (<?= e($rangeLabel) ?>)</th>
                        <?php if (!$isAllTime): ?>
                            <th>Previous</th>
                            <th>Trend</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($categoryTrends as $row): ?>
                        <tr>
                            <td><a href="<?= url('/galleries/category/' . e($row['slug'])) ?>"><?= e($row['name']) ?></a></td>
                            <td><?= number_format((int) $row['gallery_count']) ?></td>
                            <td><?= number_format($row['cur']) ?></td>
                            <?php if (!$isAllTime): ?>
                                <td><?= $row['prev'] > 0 ? number_format($row['prev']) : '<span class="muted">0</span>' ?></td>
                                <td><?= $trendBadge($row['trend']) ?></td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <div class="stats-stack">

    <?php // Pending approval: missed searches searched often enough to become categories. ?>
    <section class="stats-panel">
        <h2>Pending Category Approval</h2>
        <p class="muted" style="margin-top:0">
            Missed searches searched more than <?= (int) $promotionThreshold ?> times. Approve one to create it as a new category, or dismiss it.
        </p>
        <?php if (empty($pendingApprovals)): ?>
            <p class="muted">Nothing waiting for approval.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Search term</th>
                        <th>Searches (all time)</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pendingApprovals as $row): ?>
                        <tr>
                            <td><?= e($row['term']) ?></td>
                            <td><?= number_format($row['count']) ?></td>
                            <td style="text-align: center; white-space:nowrap;">
                                <form class="inline" method="post" action="<?= url('/admin/trends/promote') ?>"
                                      onsubmit="return confirm('Add &ldquo;<?= e($row['term']) ?>&rdquo; as a new category?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="term" value="<?= e($row['term']) ?>">
                                    <button type="submit" class="btn btn-sm">Add as Category</button>
                                </form>
                                <form class="inline" method="post" action="<?= url('/admin/trends/dismiss') ?>"
                                      onsubmit="return confirm('Dismiss &ldquo;<?= e($row['term']) ?>&rdquo;? It will be removed from this list.');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="term" value="<?= e($row['term']) ?>">
                                    <button type="submit" class="btn btn-sm btn-danger">Dismiss</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <?php // Missed searches: terms users searched for that returned no results. ?>
    <section class="stats-panel">
        <h2>Missed Searches</h2>
        <p class="muted" style="margin-top:0">
            <?= $isAllTime ? 'All-time missed searches.' : 'Search terms that returned no results over the ' . e($rangeLabel) . ' vs. the same period before.' ?>
        </p>
        <?php if (empty($searchTrends)): ?>
            <p class="muted">No missed searches yet &mdash; every recent search found something.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Search term</th>
                        <th>Searches (<?= e($rangeLabel) ?>)</th>
                        <?php if (!$isAllTime): ?>
                            <th>Previous</th>
                            <th>Trend</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($searchTrends as $row): ?>
                        <tr>
                            <td><?= e($row['term']) ?></td>
                            <td><?= number_format($row['cur']) ?></td>
                            <?php if (!$isAllTime): ?>
                                <td><?= $row['prev'] > 0 ? number_format($row['prev']) : '<span class="muted">0</span>' ?></td>
                                <td><?= $trendBadge($row['trend']) ?></td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    </div>

</div>
