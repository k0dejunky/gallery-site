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
$levelNames = [0 => 'Free', 1 => 'Silver', 2 => 'Gold', 3 => 'Platinum'];
$levelPill  = [1 => 'pill-info', 2 => 'pill-warn', 3 => 'pill'];

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
                <?php foreach (['all' => 'All', '0' => 'Free', '1' => 'Silver', '2' => 'Gold', '3' => 'Platinum'] as $l => $lLabel): ?>
                    <a class="btn btn-sm mg-filter-btn<?= $filterLevelKey === (string) $l ? ' mg-filter-active' : '' ?>"
                       href="<?= e($filterUrl($filterType, $l)) ?>"><?= e($lLabel) ?></a>
                <?php endforeach; ?>
            </div>
            <?php if ($hasFilter): ?>
                <a class="mg-filter-clear" href="<?= url('/admin/galleries') ?>">Clear filters</a>
            <?php endif; ?>
        </div>
        <a class="btn btn-sm" href="<?= url('/admin/galleries/create') ?>">New Gallery</a>
        <a class="btn btn-sm btn-outline" href="<?= url('/admin/galleries/import') ?>">Import CSV</a>
        <a class="btn btn-sm btn-outline" href="<?= url('/admin/galleries/export') ?>">Export CSV</a>
    </div>

    <?php // Folder-import app settings (pulled by the Windows app / Ubuntu importer). ?>
    <?php $importS = is_array($importSettings ?? null) ? $importSettings : []; ?>
    <details class="mg-import-settings" style="border:1px solid var(--pink-300);border-radius:var(--card-radius,8px);padding:1rem 1.25rem;background:var(--pink-100);margin-bottom:1rem;">
        <summary style="cursor:pointer;font-weight:600;">
            Folder import app settings
            <span class="muted" style="font-weight:400;font-size:.82rem;">— the Windows app / Ubuntu importer pulls these on each run</span>
        </summary>
        <form method="post" action="<?= url('/admin/galleries/import-settings') ?>" style="margin-top:.75rem;">
            <?= csrf_field() ?>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:.75rem 1rem;">
                <div>
                    <label class="muted" style="font-size:.82rem;display:block;margin-bottom:.2rem;">Machine name (host/posted below apply to this machine)</label>
                    <input type="text" name="import_machine" value="<?= e((string) ($importS['machine_name'] ?? '')) ?>" placeholder="win-box / linux" style="width:100%;box-sizing:border-box;font-size:.85rem;padding:.3rem .4rem;border:1px solid #d1d5db;border-radius:4px;">
                    <span class="muted" style="font-size:.72rem;">Each machine keeps its own host/posted folders; blank = shared default.</span>
                </div>
                <div>
                    <label class="muted" style="font-size:.82rem;display:block;margin-bottom:.2rem;">Enabled</label>
                    <label class="chip"><input type="checkbox" name="import_enabled" value="1" <?= !empty($importS['enabled']) ? 'checked' : '' ?>> Run the importer on schedule</label>
                </div>
                <div>
                    <label class="muted" style="font-size:.82rem;display:block;margin-bottom:.2rem;">Daily schedule (HH:MM, blank = interval only)</label>
                    <input type="text" name="import_schedule" value="<?= e((string) ($importS['schedule'] ?? '')) ?>" placeholder="06:00" style="width:100%;box-sizing:border-box;font-size:.85rem;padding:.3rem .4rem;border:1px solid #d1d5db;border-radius:4px;">
                </div>
                <div>
                    <label class="muted" style="font-size:.82rem;display:block;margin-bottom:.2rem;">Interval (minutes, 0 = off)</label>
                    <input type="number" name="import_interval_minutes" min="0" value="<?= (int) ($importS['interval_minutes'] ?? 0) ?>" style="width:100%;box-sizing:border-box;font-size:.85rem;padding:.3rem .4rem;border:1px solid #d1d5db;border-radius:4px;">
                </div>
                <div>
                    <label class="muted" style="font-size:.82rem;display:block;margin-bottom:.2rem;">Host folder (on the Windows box / Ubuntu)</label>
                    <input type="text" name="import_host_folder" value="<?= e((string) ($importS['host_folder'] ?? '')) ?>" placeholder="C:\work\incoming" style="width:100%;box-sizing:border-box;font-size:.85rem;padding:.3rem .4rem;border:1px solid #d1d5db;border-radius:4px;">
                </div>
                <div>
                    <label class="muted" style="font-size:.82rem;display:block;margin-bottom:.2rem;">Posted folder (defaults to &lt;host&gt;/posted)</label>
                    <input type="text" name="import_posted_folder" value="<?= e((string) ($importS['posted_folder'] ?? '')) ?>" placeholder="C:\work\incoming\posted" style="width:100%;box-sizing:border-box;font-size:.85rem;padding:.3rem .4rem;border:1px solid #d1d5db;border-radius:4px;">
                </div>
                <div>
                    <label class="muted" style="font-size:.82rem;display:block;margin-bottom:.2rem;">Import token (GALLERY_IMPORT_KEY)</label>
                    <input type="password" name="import_token" value="" placeholder="<?= empty($importS['import_token']) ? 'set the key' : 'Leave blank to keep the saved key' ?>" style="width:100%;box-sizing:border-box;font-size:.85rem;padding:.3rem .4rem;border:1px solid #d1d5db;border-radius:4px;">
                </div>
                <div>
                    <label class="muted" style="font-size:.82rem;display:block;margin-bottom:.2rem;">Spacing (hours between galleries)</label>
                    <input type="number" name="import_spacing_hours" min="1" max="168" value="<?= (int) ($importS['spacing_hours'] ?? 24) ?>" style="width:100%;box-sizing:border-box;font-size:.85rem;padding:.3rem .4rem;border:1px solid #d1d5db;border-radius:4px;">
                </div>
                <div>
                    <label class="muted" style="font-size:.82rem;display:block;margin-bottom:.2rem;">Min membership level</label>
                    <input type="number" name="import_min_level" min="0" max="3" value="<?= (int) ($importS['min_level'] ?? 0) ?>" style="width:100%;box-sizing:border-box;font-size:.85rem;padding:.3rem .4rem;border:1px solid #d1d5db;border-radius:4px;">
                </div>
                <div>
                    <label class="muted" style="font-size:.82rem;display:block;margin-bottom:.2rem;">Gallery description</label>
                    <input type="text" name="import_description" value="<?= e((string) ($importS['description'] ?? '')) ?>" style="width:100%;box-sizing:border-box;font-size:.85rem;padding:.3rem .4rem;border:1px solid #d1d5db;border-radius:4px;">
                </div>
                <div>
                    <label class="muted" style="font-size:.82rem;display:block;margin-bottom:.2rem;">Secret gallery</label>
                    <label class="chip"><input type="checkbox" name="import_is_secret" value="1" <?= !empty($importS['is_secret']) ? 'checked' : '' ?>> Imported galleries are secret</label>
                </div>
            </div>
            <div style="margin-top:.75rem;">
                <button type="submit" class="btn btn-sm">Save import settings</button>
                <span class="muted" style="font-size:.78rem;margin-left:.5rem;">
                    Used by <code>gallery_import.py</code> on the training PC (192.168.1.250) and the Ubuntu cron.
                </span>
            </div>
        </form>
    </details>

    <?php // Collapsible gallery queue: galleries waiting for a future publish moment. ?>
    <details style="border:1px solid var(--pink-300);border-radius:var(--card-radius,8px);padding:1rem 1.25rem;background:var(--pink-100);margin-bottom:1rem;">
        <summary style="cursor:pointer;font-weight:600;">Gallery queue (<?= count($queuedGalleries ?? []) ?>)</summary>
        <?php if (empty($queuedGalleries)): ?>
            <p class="muted" style="margin-top:.75rem;">No galleries are scheduled for a future publication.</p>
        <?php else: ?>
            <table style="width:100%;border-collapse:collapse;margin-top:.75rem;">
                <thead>
                    <tr>
                        <th style="text-align:left;padding:.4rem .5rem;">Gallery</th>
                        <th style="text-align:left;padding:.4rem .5rem;">Scheduled</th>
                        <th style="text-align:right;padding:.4rem .5rem;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($queuedGalleries as $queued): ?>
                        <tr>
                            <td style="padding:.4rem .5rem;">
                                <a href="<?= url('/admin/galleries/' . (int) $queued['id']) ?>"><?= e((string) $queued['title']) ?></a>
                            </td>
                            <td style="padding:.4rem .5rem;" class="muted"><?= e(tzdate('Y-m-d H:i', (string) $queued['published_at'])) ?></td>
                            <td style="padding:.4rem .5rem;text-align:right;">
                                <form class="inline" method="post" action="<?= url('/admin/galleries/' . (int) $queued['id'] . '/publish-now') ?>">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn-sm">Publish now</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </details>

    <?php // Collapsible duplicate-gallery scan: identical media in multiple
        // galleries. The admin picks which gallery to remove (soft-delete —
        // photos are shared, so removing a copy never touches the media). ?>
    <details style="border:1px solid var(--pink-300);border-radius:var(--card-radius,8px);padding:1rem 1.25rem;background:var(--pink-100);margin-bottom:1rem;">
        <summary style="cursor:pointer;font-weight:600;" id="dup-galleries-summary">Duplicate galleries (<?= count($duplicateReport['exact'] ?? []) ?> group<?= count($duplicateReport['exact'] ?? []) === 1 ? '' : 's' ?>)</summary>
        <div style="margin-top:.75rem;">
            <p class="muted" style="font-size:.85rem;">
                Galleries that reference the same media, sometimes under different names (re-imports).
                Scanned <?= e((string) ($duplicateReport['scanned_at'] ?? 'never')) ?> —
                <?= (int) ($duplicateReport['total_galleries'] ?? 0) ?> galleries. Removing a copy soft-deletes
                the gallery only; the media stays in the kept gallery.
            </p>
            <form class="inline" method="post" action="<?= url('/admin/galleries/duplicates/scan') ?>" style="margin-bottom:.75rem;">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-sm">Scan now</button>
            </form>

            <?php $exact = $duplicateReport['exact'] ?? []; ?>
            <?php $near  = $duplicateReport['near'] ?? []; ?>
            <?php $contained = $duplicateReport['contained'] ?? []; ?>
            <?php if ($exact === [] && $near === [] && $contained === []): ?>
                <p class="muted" style="margin:.5rem 0 0;">No duplicate galleries found.</p>
            <?php else: ?>
                <?php foreach ($exact as $group): ?>
                    <div class="dup-group" style="border:1px solid var(--pink-300);border-radius:6px;padding:.75rem 1rem;margin-bottom:.75rem;background:#fff;">
                        <h4 style="margin:0 0 .4rem;font-size:.95rem;">
                            <?= count($group['galleries']) ?> galleries share the same <?= (int) $group['photos'] ?> photos
                        </h4>
                        <table style="width:100%;border-collapse:collapse;">
                            <tbody>
                                <?php foreach ($group['galleries'] as $dup): ?>
                                    <?php $dupId = (int) $dup['id']; ?>
                                    <tr data-gallery-id="<?= $dupId ?>">
                                        <td style="padding:.3rem .5rem;">
                                            <a href="<?= url('/admin/galleries/' . $dupId) ?>"><?= e((string) $dup['title']) ?></a>
                                            <?php if (!empty($dup['is_secret'])): ?><span class="pill pill-warn">Secret</span><?php endif; ?>
                                            <?php if (!empty($dup['published_at']) && $dup['published_at'] > gmdate('Y-m-d H:i:s')): ?><span class="pill pill-warn">Scheduled</span><?php endif; ?>
                                        </td>
                                        <td style="padding:.3rem .5rem;" class="muted"><?= (int) $dup['photo_count'] ?> photos</td>
                                        <td style="padding:.3rem .5rem;" class="muted">#<?= $dupId ?></td>
                                        <td style="padding:.3rem .5rem;text-align:right;">
                                            <form class="inline dup-remove-form" method="post"
                                                  action="<?= url('/admin/galleries/' . $dupId . '/delete') ?>"
                                                  data-title="<?= e((string) $dup['title']) ?>" data-id="<?= $dupId ?>">
                                                <?= csrf_field() ?>
                                                <button type="submit" class="btn btn-sm btn-danger">Remove</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endforeach; ?>

                <?php if ($near !== []): ?>
                    <h4 style="margin:.9rem 0 .4rem;font-size:.9rem;">Near-duplicates (share most photos)</h4>
                    <table style="width:100%;border-collapse:collapse;">
                        <tbody>
                            <?php foreach (array_slice($near, 0, 8) as $pair): ?>
                                <tr>
                                    <td style="padding:.3rem .5rem;">
                                        <a href="<?= url('/admin/galleries/' . (int) $pair['a']['id']) ?>"><?= e((string) $pair['a']['title']) ?></a>
                                        <span class="muted">vs</span>
                                        <a href="<?= url('/admin/galleries/' . (int) $pair['b']['id']) ?>"><?= e((string) $pair['b']['title']) ?></a>
                                    </td>
                                    <td style="padding:.3rem .5rem;text-align:right;" class="muted"><?= (int) round($pair['similarity'] * 100) ?>% shared</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

                <?php if ($contained !== []): ?>
                    <h4 style="margin:.9rem 0 .4rem;font-size:.9rem;">Contained (one gallery inside another)</h4>
                    <ul style="margin:0;padding-left:1.2rem;">
                        <?php foreach (array_slice($contained, 0, 8) as $pair): ?>
                            <li class="muted" style="font-size:.85rem;">
                                <a href="<?= url('/admin/galleries/' . (int) $pair['a']['id']) ?>"><?= e((string) $pair['a']['title']) ?></a>
                                fully inside
                                <a href="<?= url('/admin/galleries/' . (int) $pair['b']['id']) ?>"><?= e((string) $pair['b']['title']) ?></a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </details>

    <?php if (empty($galleries)): ?>
        <div class="mg-empty">
            <p>No galleries match the selected filters.</p>
            <a class="btn btn-sm" href="<?= url('/admin/galleries') ?>">Clear filters</a>
        </div>
    <?php else: ?>
    <div class="mg-table-wrap">
        <table id="mg-table">
            <thead>
                <tr>
                    <th>Cover</th>
                    <th>Gallery</th>
                    <th>Type</th>
                    <th>Categories</th>
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
                    <tr data-gallery-id="<?= $gid ?>">
                        <td>
                            <?php if ($cover !== null): ?>
                                <img class="mg-cover" src="<?= e(file_url((string) $cover['filename'], 'thumb')) ?>" alt="" loading="lazy">
                            <?php else: ?>
                                <div class="mg-cover-empty">No&nbsp;media</div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a class="mg-title" href="<?= url('/admin/galleries/' . $gid) ?>"><?= e((string) $gallery['title']) ?></a>
                            <?php if (!empty($gallery['is_secret'])): ?>
                                <span class="pill pill-warn">Secret</span>
                            <?php endif; ?>
                            <?php if (!empty($gallery['published_at']) && $gallery['published_at'] > gmdate('Y-m-d H:i:s')): ?>
                                <span class="pill pill-warn" title="Hidden from the public site until the scheduled time">Scheduled</span>
                            <?php endif; ?>
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
                        <td class="mg-categories">
                            <?php $cats = $galleryCategories[$gid] ?? []; ?>
                            <?php if ($cats === []): ?>
                                <span class="pill pill-err" title="This gallery has no categories">Uncategorized</span>
                            <?php else: ?>
                                <?php foreach (array_slice($cats, 0, 2) as $cat): ?>
                                    <span class="pill pill-muted"><?= e((string) $cat['name']) ?></span>
                                <?php endforeach; ?>
                                <?php if (count($cats) > 2): ?>
                                    <span class="pill pill-muted">+<?= count($cats) - 2 ?></span>
                                <?php endif; ?>
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
                                <form class="inline" method="post" action="<?= url('/admin/galleries/' . $gid . '/recommend') ?>"
                                      title="Queue this gallery for a recommended auto-post on X and Reddit">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn-sm">Recommend</button>
                                </form>
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
<script>
// Remove a duplicate gallery without reloading the page: the collapsible
// section stays open and the scroll position is preserved. On success the
// gallery row disappears from the duplicate list (and the main table) and,
// when a pair is broken, the whole group block + the summary count update.
(function () {
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form.classList || !form.classList.contains('dup-remove-form')) { return; }
        e.preventDefault();

        var group = form.closest('.dup-group');
        var row   = form.closest('tr[data-gallery-id]');
        var gid   = row ? row.getAttribute('data-gallery-id') : null;
        var title = form.getAttribute('data-title') || 'this gallery';
        var btn   = form.querySelector('button[type=submit]');

        if (!window.confirm('Soft-delete "' + title + '" (#' + (form.getAttribute('data-id') || '') + ')? Its photos are shared with the other copies and will not be deleted.')) {
            return;
        }

        btn.disabled = true;
        fetch(form.action, { method: 'POST', body: new FormData(form) })
            .then(function (r) {
                if (!r.ok) { throw new Error('HTTP ' + r.status); }
                if (row && row.parentNode) { row.parentNode.removeChild(row); }
                var mainRow = gid && document.querySelector('#mg-table tbody tr[data-gallery-id="' + gid + '"]');
                if (mainRow && mainRow.parentNode) { mainRow.parentNode.removeChild(mainRow); }
                if (group) {
                    var remaining = group.querySelectorAll('tr[data-gallery-id]');
                    if (remaining.length <= 1) {
                        if (group.parentNode) { group.parentNode.removeChild(group); }
                        updateCount();
                    }
                }
            })
            .catch(function () {
                alert('Could not delete the gallery. Please try again.');
            })
            .finally(function () {
                btn.disabled = false;
            });
    });

    function updateCount() {
        var summary = document.getElementById('dup-galleries-summary');
        if (!summary) { return; }
        var n = document.querySelectorAll('.dup-group').length;
        summary.textContent = 'Duplicate galleries (' + n + ' group' + (n === 1 ? '' : 's') + ')';
    }
})();
</script>
