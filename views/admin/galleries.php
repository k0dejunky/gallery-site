<?php $title = 'Manage Galleries'; ?>

<?php
// Aggregate totals for the summary cards.
$totalImages = 0;
$totalVideos = 0;
$totalViews  = 0;
foreach ($galleries as $gallery) {
    $totalImages += (int) ($gallery['photo_count'] ?? 0);
    $totalVideos += (int) ($gallery['video_count'] ?? 0);
    $totalViews  += (int) ($gallery['views'] ?? 0);
}
$levelNames = [0 => 'Free', 1 => 'Silver', 2 => 'Gold', 3 => 'Platinum', 4 => 'Diamond'];
$levelPill  = [1 => 'pill-info', 2 => 'pill-warn', 3 => 'pill', 4 => 'pill-err'];

$filterType  = $filterType ?? 'all';
$filterLevel = $filterLevel ?? null;
$hasFilter   = $filterType !== 'all' || $filterLevel !== null;

$filterUrl = static function (string $type, string $level): string {
    $q = [];
    if ($type !== 'all') {
        $q[] = 'type=' . rawurlencode($type);
    }
    if ($level !== 'all') {
        $q[] = 'level=' . rawurlencode($level);
    }

    return url('/admin/galleries' . ($q ? '?' . implode('&', $q) : ''));
};

$filterLevelKey = $filterLevel === null ? 'all' : (string) $filterLevel;
?>

<style>
    .mg-table-wrap { background: var(--pink-100); border: 1px solid var(--pink-300); border-radius: 10px; overflow: hidden; margin-top: 1rem; }
    .mg-table-wrap table { margin: 0; border: none; }
    .mg-table-wrap th, .mg-table-wrap td { border-color: var(--pink-200); }
    .mg-table-wrap thead th { background: var(--pink-200); text-transform: uppercase; letter-spacing: .04em; font-size: .72rem; color: var(--purple-700); }
    .mg-table-wrap tbody tr { transition: background .12s ease; }
    .mg-table-wrap tbody tr:hover { background: var(--pink-200); }

    .mg-cover { width: 76px; height: 52px; border-radius: 6px; object-fit: cover; display: block; background: var(--purple-900); border: 1px solid var(--pink-300); }
    .mg-cover-empty { width: 76px; height: 52px; border-radius: 6px; display: flex; align-items: center; justify-content: center; background: var(--pink-200); border: 1px dashed var(--pink-400); color: var(--purple-700); font-size: .7rem; }

    .mg-title { font-weight: 600; color: var(--purple-900); text-decoration: none; }
    .mg-title:hover { text-decoration: underline; }
    .mg-desc { color: var(--purple-800); opacity: .7; font-size: .8rem; margin: .15rem 0 0; max-width: 34rem; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }

    .mg-media { white-space: nowrap; font-size: .85rem; color: var(--purple-800); }
    .mg-media b { color: var(--purple-900); }

    .mg-date { white-space: nowrap; font-size: .82rem; color: var(--purple-800); }

    .mg-actions { display: flex; gap: .35rem; flex-wrap: wrap; justify-content: flex-end; align-items: center; }

    .mg-toolbar { display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap; margin: .75rem 0; }
    .mg-filters { display: flex; gap: 1.5rem; flex-wrap: wrap; align-items: center; }
    .mg-filter-group { display: flex; align-items: center; gap: .35rem; flex-wrap: wrap; }
    .mg-filter-label { font-size: .72rem; text-transform: uppercase; letter-spacing: .05em; color: var(--purple-700); margin-right: .2rem; }
    .mg-filter-btn { background: var(--pink-100); color: var(--purple-800); border: 1px solid var(--pink-300); }
    .mg-filter-btn:hover { background: var(--pink-200); border-color: var(--pink-400); }
    .mg-filter-active, .mg-filter-active:hover { background: var(--purple-700); color: #fff; border-color: var(--purple-700); }
    .mg-filter-clear { font-size: .8rem; color: var(--purple-700); text-decoration: none; }

    .mg-empty { padding: 2.5rem 1rem; text-align: center; color: var(--purple-800); }
    .mg-empty .btn { margin-top: .75rem; }
</style>

<?php if (empty($galleries) && !$hasFilter): ?>
    <div class="mg-empty">
        <p>No galleries yet.</p>
        <a class="btn btn-sm" href="<?= url('/admin/galleries/create') ?>">Create your first gallery</a>
    </div>
<?php else: ?>

    <?php // Summary cards. ?>
    <div class="stat-cards">
        <div class="stat-card">
            <b><?= number_format(count($galleries)) ?></b>
            <small>Galleries</small>
        </div>
        <div class="stat-card">
            <b><?= number_format($totalImages) ?></b>
            <small>Images</small>
        </div>
        <div class="stat-card">
            <b><?= number_format($totalVideos) ?></b>
            <small>Videos</small>
        </div>
        <div class="stat-card">
            <b><?= number_format($totalViews) ?></b>
            <small>Total views</small>
        </div>
    </div>

    <div class="mg-toolbar">
        <div class="mg-filters">
            <div class="mg-filter-group">
                <span class="mg-filter-label">Type</span>
                <?php foreach (['all' => 'All', 'images' => 'Images', 'videos' => 'Videos'] as $t => $tLabel): ?>
                    <a class="btn btn-sm mg-filter-btn<?= $filterType === $t ? ' mg-filter-active' : '' ?>"
                       href="<?= e($filterUrl($t, $filterLevelKey)) ?>"><?= e($tLabel) ?></a>
                <?php endforeach; ?>
            </div>
            <div class="mg-filter-group">
                <span class="mg-filter-label">Level</span>
                <?php foreach (['all' => 'All', '0' => 'Free', '1' => 'Silver', '2' => 'Gold', '3' => 'Platinum', '4' => 'Diamond'] as $l => $lLabel): ?>
                    <a class="btn btn-sm mg-filter-btn<?= $filterLevelKey === (string) $l ? ' mg-filter-active' : '' ?>"
                       href="<?= e($filterUrl($filterType, $l)) ?>"><?= e($lLabel) ?></a>
                <?php endforeach; ?>
            </div>
            <?php if ($hasFilter): ?>
                <a class="mg-filter-clear" href="<?= url('/admin/galleries') ?>">Clear filters</a>
            <?php endif; ?>
        </div>
        <a class="btn btn-sm" href="<?= url('/admin/galleries/create') ?>">New Gallery</a>
    </div>

    <?php if (empty($galleries)): ?>
        <div class="mg-empty">
            <p>No galleries match the selected filters.</p>
            <a class="btn btn-sm" href="<?= url('/admin/galleries') ?>">Clear filters</a>
        </div>
    <?php else: ?>
    <div class="mg-table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Cover</th>
                    <th>Gallery</th>
                    <th>Type</th>
                    <th>Media</th>
                    <th>Level</th>
                    <th>Created</th>
                    <th style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($galleries as $gallery): ?>
                    <?php $gid   = (int) $gallery['id']; ?>
                    <?php $cover = $covers[$gid] ?? null; ?>
                    <?php $level = (int) ($gallery['min_level'] ?? 0); ?>
                    <tr>
                        <td>
                            <?php if ($cover !== null): ?>
                                <img class="mg-cover" src="<?= e(file_url((string) $cover['filename'], 'thumb')) ?>" alt="" loading="lazy">
                            <?php else: ?>
                                <div class="mg-cover-empty">No&nbsp;media</div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a class="mg-title" href="<?= url('/admin/galleries/' . $gid) ?>"><?= e((string) $gallery['title']) ?></a>
                            <?php if (trim((string) ($gallery['description'] ?? '')) !== ''): ?>
                                <div class="mg-desc"><?= e((string) $gallery['description']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (($gallery['type'] ?? 'images') === 'videos'): ?>
                                <span class="pill pill-info">Videos</span>
                            <?php else: ?>
                                <span class="pill pill-muted">Images</span>
                            <?php endif; ?>
                        </td>
                        <td class="mg-media">
                            <b><?= number_format((int) ($gallery['photo_count'] ?? 0)) ?></b> image<?= (int) ($gallery['photo_count'] ?? 0) === 1 ? '' : 's' ?>
                            &middot;
                            <b><?= number_format((int) ($gallery['video_count'] ?? 0)) ?></b> video<?= (int) ($gallery['video_count'] ?? 0) === 1 ? '' : 's' ?>
                        </td>
                        <td>
                            <?php if ($level === 0): ?>
                                <span class="pill pill-muted">Free</span>
                            <?php else: ?>
                                <span class="pill <?= $levelPill[$level] ?? 'pill' ?>"><?= $levelNames[$level] ?? 'Level ' . $level ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="mg-date"><?= !empty($gallery['created_at']) ? e(tzdate('Y-m-d H:i', $gallery['created_at'])) : '' ?></td>
                        <td>
                            <div class="mg-actions">
                                <a class="btn btn-sm" href="<?= url('/admin/galleries/' . $gid) ?>">Manage</a>
                                <a class="btn btn-sm btn-outline" href="<?= url('/admin/galleries/' . $gid . '/edit') ?>">Edit</a>
                                <form class="inline" method="post" action="<?= url('/admin/galleries/' . $gid . '/delete') ?>"
                                      onsubmit="return confirm('Delete gallery &quot;<?= e((string) $gallery['title']) ?>&quot;?');">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
<?php endif; ?>