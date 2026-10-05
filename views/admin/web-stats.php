<?php $title = 'Web Analytics'; ?>

<?php
use App\Core\Charts;

$query = static function (array $extra = []) use ($currentRange, $includeBots): string {
    return 'range=' . rawurlencode($currentRange) . ($includeBots ? '&bots=1' : '')
         . ($extra === [] ? '' : '&' . http_build_query($extra));
};

// Big-number formatting with thousands separators, so the cards read like the
// rest of the admin rather than raw ints.
$num = static fn ($v): string => number_format((float) $v);
$hr  = static function (float $seconds): string {
    $seconds = max(0, (int) round($seconds));
    if ($seconds < 60) {
        return $seconds . 's';
    }
    if ($seconds < 3600) {
        return intdiv($seconds, 60) . 'm ' . ($seconds % 60) . 's';
    }

    return intdiv($seconds, 3600) . 'h ' . intdiv($seconds % 3600, 60) . 'm';
};

// Change badge: null means "no previous period to compare against", which is
// shown as a dash rather than a fake 0% or an infinite jump.
$change = static function (?float $pct): string {
    if ($pct === null) {
        return '<span class="trend trend-flat">&mdash;</span>';
    }
    if ($pct === 0.0) {
        return '<span class="trend trend-flat">0%</span>';
    }

    $up = $pct > 0;
    $cls = $up ? 'trend trend-up' : 'trend trend-down';
    $arrow = $up ? '&#9650;' : '&#9660;';

    return '<span class="' . $cls . '">' . $arrow . ' ' . abs($pct) . '%</span>';
};

$formatValue = static function (array $card) use ($num, $hr): string {
    return match ($card['format']) {
        'bytes'    => format_bytes((float) $card['value']),
        'duration' => $hr((float) $card['value']),
        'decimal'  => rtrim(rtrim(number_format((float) $card['value'], 2), '0'), '.') ?: '0',
        default    => $num($card['value']),
    };
};

$statusClass = static fn (string $class): string => 'status-badge ' . match ($class) {
    'bad'  => 'cancelled',
    'warn' => 'pending',
    default => 'active',
};

$trafficRows   = $summary['totals']['hits'];
$humanShare    = $trafficRows > 0
    ? round($summary['totals']['human_hits'] / $trafficRows * 100, 1)
    : 0.0;
?>

<?php if (!$hasData): ?>
    <div class="flash error" style="margin:1rem 0;">
        <strong>No analytics yet.</strong>
        The access log has not been aggregated. Use <em>Re-parse logs</em> below
        (or wait for the hourly job) to build the first days of history.
    </div>
<?php endif; ?>

<?php // ---- Range selector + robots toggle ?>
<form method="get" action="<?= url('/admin/analytics') ?>" class="stats-period">
    <label for="ws-range">Range</label>
    <select name="range" id="ws-range" onchange="this.form.submit()">
        <?php foreach ($ranges as $key => $info): ?>
            <option value="<?= e($key) ?>"<?= $key === $currentRange ? ' selected' : '' ?>><?= e($info['label']) ?></option>
        <?php endforeach; ?>
    </select>

    <label style="display:flex;align-items:center;gap:.35rem;">
        <input type="checkbox" name="bots" value="1"<?= $includeBots ? ' checked' : '' ?> onchange="this.form.submit()">
        Include robots
    </label>

    <span class="muted"><?= e($from) ?> &rarr; <?= e($to) ?></span>

    <a class="btn btn-sm" href="<?= e(url('/admin/analytics/export?' . $query())) ?>">Download CSV</a>
</form>

<?php if ($includeBots): ?>
    <p class="muted" style="margin:.25rem 0 0;">
        Robots are included: every number below counts crawlers as visitors.
    </p>
<?php endif; ?>

<?php // ---- Headline cards ?>
<div class="stat-cards">
    <?php foreach (['hits', 'page_views', 'visits', 'uniq_ips', 'bytes', 'avg_duration'] as $key): ?>
        <?php $card = $summary[$key]; ?>
        <div class="stat-card">
            <small><?= e($card['label']) ?></small>
            <b><?= e($formatValue($card)) ?></b>
            <?= $change($card['change']) ?>
            <?php if (isset($card['share']) && $card['share'] > 0): ?>
                <small style="display:block;opacity:.7;"><?= e(rtrim(rtrim(number_format((float) $card['share'], 1), '0'), '.')) ?>% of hits</small>
            <?php endif; ?>
            <?php if ($key === 'bytes' && !$includeBots): ?>
                <small style="display:block;opacity:.7;">includes robots</small>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>

<?php if (!$isAllTime): ?>
    <p class="muted" style="margin:0 0 1rem;font-size:.8rem;">
        Changes compare against the <?= (int) (count($series)) ?> day(s) before this range.
    </p>
<?php endif; ?>

<?php // ---- Traffic over time + hourly profile ?>
<div class="stats-grid">
    <div class="stats-panel">
        <h2>Traffic by day</h2>
        <?= Charts::bars(
            array_column($series, 'label'),
            array_column($series, 'hits'),
            640,
            150,
            '#7c3aed',
            '%s hits'
        ) ?>
        <p class="muted" style="font-size:.78rem;margin:.35rem 0 0;">
            <?= $includeBots ? 'All requests, robots included.' : 'Human requests only (robots hidden).' ?>
            Peak <?= e($num(max(array_column($series, 'hits') ?: [0]))) ?>,
            quietest <?= e($num(min(array_column($series, 'hits') ?: [0]))) ?>.
        </p>
    </div>

    <div class="stats-panel">
        <h2>Hourly profile <span class="muted" style="font-weight:400;font-size:.78rem;">(average per day)</span></h2>
        <?= Charts::bars(
            array_map(static fn (array $h): string => $h['hour'] % 6 === 0 ? $h['label'] : '', $hourly),
            array_column($hourly, 'hits'),
            640,
            150,
            '#0ea5e9',
            '%s hits'
        ) ?>
        <p class="muted" style="font-size:.78rem;margin:.35rem 0 0;">
            Shows when the site is busiest in <?= e($timezoneName) ?>.
        </p>
    </div>
</div>

<?php // ---- What people actually requested ?>
<div class="stats-grid">
    <div class="stats-panel">
        <h2>Top pages</h2>
        <?php if ($topUrls === []): ?>
            <p class="muted">No page views in this range.</p>
        <?php else: ?>
            <div class="table-scroll">
                <table>
                    <thead><tr><th>Path</th><th style="text-align:right;">Views</th><th style="text-align:right;" title="Sum of the per-day unique addresses that loaded this path, so a visitor who returns counts once per day.">Visitors/day</th><th style="text-align:right;">Data</th></tr></thead>
                    <tbody>
                    <?php foreach ($topUrls as $row): ?>
                        <tr>
                            <td><code><?= e($row['url']) ?></code></td>
                            <td style="text-align:right;"><?= e($num($row['hits'])) ?></td>
                            <td style="text-align:right;"><?php if ($includeBots): ?>
                                    <span class="muted" title="Visitor addresses are counted for human traffic only.">&mdash;</span>
                                <?php else: ?>
                                    <?= e($num($row['uniq_ips'])) ?>
                                <?php endif; ?></td>
                            <td style="text-align:right;"><?= e(format_bytes($row['bytes'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if (count($topUrls) >= $tableLimit): ?>
                <p class="muted" style="font-size:.78rem;">Top <?= (int) $tableLimit ?> of all paths.</p>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <div class="stats-panel">
        <h2>Where visitors came from</h2>
        <?php if ($referrers === []): ?>
            <p class="muted">No referrer data yet.</p>
        <?php else: ?>
            <div class="table-scroll">
                <table>
                    <thead><tr><th>Referrer</th><th style="text-align:right;">Hits</th><th style="text-align:right;">Share</th></tr></thead>
                    <tbody>
                    <?php foreach ($referrers as $row): ?>
                        <tr>
                            <td><?= $row['host'] === '' ? '<span class="muted">Direct / no referrer</span>' : e($row['label']) ?></td>
                            <td style="text-align:right;"><?= e($num($row['hits'])) ?></td>
                            <td style="text-align:right;"><?= e(rtrim(rtrim(number_format($row['pct'], 1), '0'), '.')) ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php // ---- Entry / exit pages ?>
<div class="stats-grid">
    <?php foreach ([['Entry pages', $entryPages], ['Exit pages', $exitPages]] as [$heading, $rows]): ?>
        <div class="stats-panel">
            <h2><?= e($heading) ?></h2>
            <?php if ($rows === []): ?>
                <p class="muted">No sessions in this range.</p>
            <?php else: ?>
                <div class="table-scroll">
                    <table>
                        <thead><tr><th>Path</th><th style="text-align:right;">Sessions</th><th style="text-align:right;">Avg time</th></tr></thead>
                        <tbody>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><code><?= e($row['url']) ?></code></td>
                                <td style="text-align:right;"><?= e($num($row['hits'])) ?></td>
                                <td style="text-align:right;"><?= $row['avg_duration'] > 0 ? e($hr($row['avg_duration'])) : '<span class="muted">&mdash;</span>' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>

<?php // ---- Visitors ?>
<div class="stats-grid">
    <div class="stats-panel">
        <h2>Visit quality <span class="muted" style="font-weight:400;font-size:.78rem;">(humans only)</span></h2>
        <table>
            <tbody>
                <tr><td>Sessions</td><td style="text-align:right;"><?= e($num($quality['visits'])) ?></td></tr>
                <tr><td>Unique visitors</td><td style="text-align:right;"><?= e($num($quality['visitors'])) ?></td></tr>
                <tr><td>Average duration</td><td style="text-align:right;"><?= e($hr($quality['avg_duration'])) ?></td></tr>
                <tr><td>Pages per visit</td><td style="text-align:right;"><?= e(rtrim(rtrim(number_format($quality['avg_pages'], 2), '0'), '.')) ?></td></tr>
                <tr><td>Longest visit</td><td style="text-align:right;"><?= e($num($quality['max_pages'])) ?> pages</td></tr>
                <tr><td>Bounced (1 page)</td><td style="text-align:right;"><?= e($num($quality['bounces'])) ?> (<?= e(rtrim(rtrim(number_format($quality['bounce_rate'], 1), '0'), '.')) ?>%)</td></tr>
            </tbody>
        </table>
    </div>

    <div class="stats-panel">
        <h2>Busiest visitor addresses</h2>
        <?php if ($visitors === []): ?>
            <p class="muted">No visitor data yet.</p>
        <?php else: ?>
            <div class="table-scroll">
                <table>
                    <thead><tr><th>IP</th><th style="text-align:right;">Requests</th><th style="text-align:right;">Visits</th><th style="text-align:right;">Data</th></tr></thead>
                    <tbody>
                    <?php foreach ($visitors as $row): ?>
                        <tr>
                            <td>
                                <code><?= e($row['ip']) ?></code>
                                <?php if ($row['is_bot']): ?>
                                    <span class="status-badge pending">bot</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:right;"><?= e($num($row['hits'])) ?></td>
                            <td style="text-align:right;"><?= e($num($row['visits'])) ?></td>
                            <td style="text-align:right;"><?= e(format_bytes($row['bytes'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="muted" style="font-size:.78rem;">Raw addresses are stored on purpose.</p>
        <?php endif; ?>
    </div>
</div>

<?php // ---- Browsers / OS / devices ?>
<div class="stats-grid">
    <?php
    $agentPanels = [
        'Browsers' => $agents['browsers'],
        'Operating systems' => $agents['oses'],
        'Devices' => $agents['devices'],
    ];
    foreach ($agentPanels as $heading => $rows):
        ?>
        <div class="stats-panel">
            <h2><?= e($heading) ?></h2>
            <?php if ($rows === []): ?>
                <p class="muted">No data.</p>
            <?php else: ?>
                <table>
                    <tbody>
                    <?php foreach (array_slice($rows, 0, 10) as $row): ?>
                        <tr>
                            <td><?= e($row['label']) ?></td>
                            <td style="text-align:right;"><?= e($num($row['hits'])) ?></td>
                            <td style="text-align:right;width:5rem;">
                                <?= Charts::sparkline([(float) $row['pct']], 60, 14, '#7c3aed') ?>
                                <?= e(rtrim(rtrim(number_format($row['pct'], 1), '0'), '.')) ?>%
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>

<?php // ---- Request types, statuses, file types ?>
<div class="stats-grid">
    <div class="stats-panel">
        <h2>Request types <span class="muted" style="font-weight:400;font-size:.78rem;">(all traffic)</span></h2>
        <?php
        $mixSlices = array_map(static function (array $row, int $i): array {
            $colors = ['#7c3aed', '#0ea5e9', '#16a34a'];

            return ['label' => $row['label'], 'value' => (float) $row['value'], 'color' => $colors[$i % 3]];
        }, $mix, array_keys($mix));
        ?>
        <?= Charts::pie($mixSlices, 200, 200, static fn (float $v): string => $num($v) . ' requests') ?>
        <table>
            <tbody>
            <?php foreach ($mix as $row): ?>
                <tr>
                    <td><?= e($row['label']) ?></td>
                    <td style="text-align:right;"><?= e($num($row['value'])) ?></td>
                    <td style="text-align:right;"><?= e(rtrim(rtrim(number_format($row['pct'], 1), '0'), '.')) ?>%</td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="stats-panel">
        <h2>HTTP status codes <span class="muted" style="font-weight:400;font-size:.78rem;">(all traffic)</span></h2>
        <?php if ($statuses === []): ?>
            <p class="muted">No data.</p>
        <?php else: ?>
            <table>
                <thead><tr><th>Code</th><th>Meaning</th><th style="text-align:right;">Hits</th><th style="text-align:right;">Share</th></tr></thead>
                <tbody>
                <?php foreach ($statuses as $row): ?>
                    <tr>
                        <td><span class="<?= e($statusClass($row['class'])) ?>"><?= (int) $row['status'] ?></span></td>
                        <td class="muted"><?= e($row['label']) ?></td>
                        <td style="text-align:right;"><?= e($num($row['hits'])) ?></td>
                        <td style="text-align:right;"><?= e(rtrim(rtrim(number_format($row['pct'], 1), '0'), '.')) ?>%</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if ($summary['totals']['status_4xx'] > 0 || $summary['totals']['status_5xx'] > 0): ?>
                <p class="muted" style="font-size:.78rem;">
                    <?= e($num($summary['totals']['status_4xx'])) ?> client errors,
                    <?= e($num($summary['totals']['status_5xx'])) ?> server errors.
                </p>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <div class="stats-panel">
        <h2>Static file types <span class="muted" style="font-weight:400;font-size:.78rem;">(all traffic)</span></h2>
        <?php if ($fileTypes === []): ?>
            <p class="muted">No static files served.</p>
        <?php else: ?>
            <table>
                <thead><tr><th>Type</th><th style="text-align:right;">Requests</th><th style="text-align:right;">Data</th></tr></thead>
                <tbody>
                <?php foreach ($fileTypes as $row): ?>
                    <tr>
                        <td><?= e($row['label']) ?></td>
                        <td style="text-align:right;"><?= e($num($row['hits'])) ?></td>
                        <td style="text-align:right;"><?= e(format_bytes($row['bytes'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php // ---- Robots ?>
<div class="stats-panel" style="margin-bottom:1.5rem;">
    <h2>Robots <span class="muted" style="font-weight:400;font-size:.78rem;">
        (<?= e(rtrim(rtrim(number_format($summary['totals']['bot_share'], 1), '0'), '.')) ?>% of all requests,
        <?= e($humanShare) ?>% human)
    </span></h2>
    <?php if ($robots === []): ?>
        <p class="muted">No crawler traffic recorded.</p>
    <?php else: ?>
        <div class="table-scroll">
            <table>
                <thead><tr><th>Robot</th><th style="text-align:right;">Requests</th><th style="text-align:right;">Data</th></tr></thead>
                <tbody>
                <?php foreach ($robots as $row): ?>
                    <tr>
                        <td><?= e($row['label']) ?></td>
                        <td style="text-align:right;"><?= e($num($row['hits'])) ?></td>
                        <td style="text-align:right;"><?= e(format_bytes($row['bytes'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php // ---- Maintenance: re-parse + rollup health ?>
<div class="stats-panel" style="margin-bottom:1.5rem;">
    <h2>Log aggregation</h2>
    <p class="muted" style="font-size:.85rem;">
        Refreshing <?= e($from) ?> to <?= e($to) ?>, the last
        <?= (int) (count($series)) ?> day(s), and
        <?= e($health['days']) ?> day(s) of history are stored
        (<?= e($health['first_day'] ?? 'none') ?> to <?= e($health['last_day'] ?? 'none') ?>).
        Last update <?= e($health['updated_at'] ?? 'never') ?>.
    </p>

    <form method="post" action="<?= url('/admin/analytics/reparse') ?>" style="display:flex;gap:.75rem;align-items:end;flex-wrap:wrap;margin:.5rem 0 1rem;">
        <?= csrf_field() ?>
        <input type="hidden" name="range" value="<?= e($currentRange) ?>">
        <?php if ($includeBots): ?>
            <input type="hidden" name="bots" value="1">
        <?php endif; ?>

        <div>
            <label for="ws-from" style="display:block;font-size:.75rem;">From</label>
            <input type="date" id="ws-from" name="from" value="<?= e($from) ?>" min="<?= e($health['first_day'] ?? $from) ?>">
        </div>
        <div>
            <label for="ws-to" style="display:block;font-size:.75rem;">To</label>
            <input type="date" id="ws-to" name="to" value="<?= e($to) ?>">
        </div>
        <button type="submit" class="btn btn-sm">Re-parse logs</button>
        <span class="muted" style="font-size:.78rem;">Up to 31 days at a time.</span>
    </form>

    <table>
        <tbody>
            <tr><td>Requests stored</td><td style="text-align:right;"><?= e($num($health['hits'])) ?></td></tr>
            <tr><td>Bandwidth accounted</td><td style="text-align:right;"><?= e(format_bytes($health['bytes'])) ?></td></tr>
            <tr>
                <td>Unparsed lines</td>
                <td style="text-align:right;">
                    <?= e($num($health['skipped_lines'])) ?>
                    <?php if ($health['skipped_lines'] > 0): ?>
                        <span class="muted" style="font-size:.78rem;">&mdash; lines that did not match the log format</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php if ($health['latest_source'] !== ''): ?>
                <tr><td>Log files read</td><td style="text-align:right;"><code><?= e($health['latest_source']) ?></code></td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>