<?php $title = 'Trends'; ?>

<h1>Trends</h1>

<p class="muted">
    How categories and missed searches are trending. Pick a period to compare the current window with the
    same-length window before it, or <em>All time</em> for lifetime totals.
</p>

<?php
// The period selector drives both panels. All-time reports lifetime totals
// (no comparison), every other period compares the current window with the
// same-length window before it.
$isAllTime = $currentPeriod === 'all';
$rangeLabel = $periods[$currentPeriod]['range'];

// Render a trend badge for the given trend row: a red/green arrow with the
// percentage change, "New" when the current window has its first activity,
// or a muted dash when there is no change (or for all-time totals).
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
?>

<?php // Period selector: switches both panels between daily/weekly/monthly/yearly/all-time. ?>
<form method="get" action="<?= url('/admin/trends') ?>" class="stats-period">
    <label for="stats-period-select">Trend period</label>
    <select name="period" id="stats-period-select" onchange="this.form.submit()">
        <?php foreach ($periods as $key => $info): ?>
            <option value="<?= e($key) ?>"<?= $key === $currentPeriod ? ' selected' : '' ?>><?= e($info['label']) ?></option>
        <?php endforeach; ?>
    </select>
</form>

<div class="stats-grid">

    <?php // Trending categories: which categories users are viewing, compared with the previous window. ?>
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

    <div class="stats-stack">

    <?php // Pending approval: missed searches searched often enough to become categories, awaiting the admin's OK. ?>
    <section class="stats-panel">
        <h2>Pending Category Approval</h2>
        <p class="muted" style="margin-top:0">
            Missed searches searched more than <?= (int) $promotionThreshold ?> times. Approve one to create it as a new category.
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
                            <td style="text-align: center;">
                                <?php // Approval step: the admin must confirm before the term becomes a category. ?>
                                <form class="inline" method="post" action="<?= url('/admin/trends/promote') ?>"
                                      onsubmit="return confirm('Add &ldquo;<?= e($row['term']) ?>&rdquo; as a new category?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="term" value="<?= e($row['term']) ?>">
                                    <button type="submit" class="btn btn-sm">Add as Category</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <?php // Missed searches: terms users searched for that do not exist in the database. ?>
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
