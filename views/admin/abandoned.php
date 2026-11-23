<?php
$title = 'Uploads & Orphans';
// The destructive actions on this page (deleting staging folders, orphaned
// files) are super-admin only, exactly as they were on the System page.
$canOperate = \App\Core\Auth::can('system_ops');
?>

<h2>Staged uploads</h2>
<?php if (empty($uploads)): ?>
    <?= admin_empty('upload', 'No staged uploads', 'Uploads interrupted before their gallery was created appear here.') ?>
<?php else: ?>
    <?php $hasResumable = count(array_filter($uploads, static fn (array $u): bool => empty($u['incomplete']))) > 0; ?>
    <?php if ($hasResumable): ?>
    <div style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:center;margin:.75rem 0;">
        <strong>With selected:</strong>
        <button type="button" class="btn" id="resume-btn">Resume New Gallery</button>
        <span class="muted"><span id="selected-count">0</span> selected</span>
    </div>

    <form method="post" action="<?= url('/admin/abandoned-uploads/resume') ?>" id="resume-form">
        <?= csrf_field() ?>
    </form>
    <?php endif; ?>

    <table>
        <thead>
            <tr>
                <th><?= $hasResumable ? '<input type="checkbox" id="check-all" title="Select all">' : '' ?></th>
                <th>Preview</th>
                <th>File</th>
                <th>Type</th>
                <th>Size</th>
                <th>Assign To</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($uploads as $upload): ?>
                <?php if (!empty($upload['incomplete'])): ?>
                    <tr style="background:rgba(180,90,40,.06);">
                        <td></td>
                        <td><div style="width:120px;height:40px;display:flex;align-items:center;justify-content:center;background:rgba(180,90,40,.12);border-radius:6px;font-size:1.2rem;" title="Interrupted chunked upload">&#9888;</div></td>
                        <td><code><?= e((string) $upload['filename']) ?></code>
                            <span class="muted u-fs-078">(<?= (int) ($upload['chunks'] ?? 0) ?> chunks, never completed)</span></td>
                        <td><span class="pill pill-warn">Incomplete</span></td>
                        <td><?= number_format((int) $upload['size']) ?> B</td>
                        <td>
                            <form method="post" action="<?= url('/admin/abandoned-uploads/chunks-delete') ?>"
                                  data-confirm="Delete these <?= (int) ($upload['chunks'] ?? 0) ?> incomplete upload chunks?">
                                <?= csrf_field() ?>
                                <input type="hidden" name="session" value="<?= e((string) $upload['session']) ?>">
                                <input type="hidden" name="file" value="<?= e((string) $upload['filename']) ?>">
                                <button type="submit" class="btn btn-sm btn-danger">Delete chunks</button>
                            </form>
                        </td>
                    </tr>
                <?php else: ?>
                <?php $isVideo = (int) ($upload['is_video'] ?? 0) === 1; ?>
                <?php $session = rawurlencode($upload['session']); ?>
                <?php $file = rawurlencode($upload['filename']); ?>
                <?php $key = e($upload['session'] . '|' . $upload['filename']); ?>
                <tr>
                    <td>
                        <input type="checkbox" class="abandoned-check" value="<?= $key ?>" data-session="<?= $session ?>" data-file="<?= $file ?>">
                    </td>
                    <td>
                        <?php if ($isVideo): ?>
                            <video src="<?= e(url('/admin/abandoned-uploads/' . $session . '/' . $file)) ?>" width="160" muted controls preload="metadata"></video>
                        <?php else: ?>
                            <img src="<?= e(url('/admin/abandoned-uploads/' . $session . '/' . $file . '?size=thumb')) ?>" alt="" width="120">
                        <?php endif; ?>
                    </td>
                    <td><?= e($upload['filename']) ?></td>
                    <td><?= $isVideo ? 'Video' : 'Image' ?></td>
                    <td><?= number_format((int) $upload['size']) ?> B</td>
                    <td>
                        <form method="post" action="<?= url('/admin/abandoned-uploads/' . $session . '/' . $file) ?>">
                            <?= csrf_field() ?>
                            <select name="gallery_id" required>
                                <option value="">Choose a gallery</option>
                                <?php foreach ($galleries as $gallery): ?>
                                    <?php $galleryIsVideo = ($gallery['type'] ?? 'images') === 'videos'; ?>
                                    <?php if ($isVideo === $galleryIsVideo): ?>
                                        <option value="<?= (int) $gallery['id'] ?>"><?= e($gallery['title']) ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn btn-sm">Assign</button>
                        </form>
                    </td>
                </tr>
                <?php endif; ?>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($hasResumable): ?>
    <script nonce="<?= csp_nonce() ?>">
    (function () {
        var all = document.getElementById('check-all');
        var checks = Array.prototype.slice.call(document.querySelectorAll('.abandoned-check'));
        var countEl = document.getElementById('selected-count');
        var resumeBtn = document.getElementById('resume-btn');
        var resumeForm = document.getElementById('resume-form');

        function updateCount() {
            var n = checks.filter(function (c) { return c.checked; }).length;
            if (countEl) countEl.textContent = n;
        }

        if (all) {
            all.addEventListener('change', function () {
                checks.forEach(function (c) { c.checked = all.checked; });
                updateCount();
            });
        }
        checks.forEach(function (c) { c.addEventListener('change', updateCount); });

        if (resumeBtn && resumeForm) {
            resumeBtn.addEventListener('click', async function () {
                var chosen = checks.filter(function (c) { return c.checked; });
                if (!chosen.length) {
                    alert('Select at least one upload to resume.');
                    return;
                }
                var ok = await window.adminAsk(
                    'Stage ' + chosen.length + ' upload(s) for a new gallery? You can finish creating it from the next page.',
                    { confirmLabel: 'Stage them' }
                );
                if (!ok) return;
                chosen.forEach(function (c) {
                    var input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'files[]';
                    input.value = c.value;
                    resumeForm.appendChild(input);
                });
                resumeForm.submit();
            });
        }
    })();
    </script>
    <?php endif; ?>
<?php endif; ?>

<?php // ----- Staging folders ----- ?>
<div class="stats-panel" style="margin-top:1.5rem;">
    <h2 class="u-m0">Staging folders</h2>
    <p class="muted" style="margin:.35rem 0 .75rem;">A session folder left behind by an upload that never finished. Deleting one removes every file in it.</p>
    <?php if (empty($pendingDirs)): ?>
        <?= admin_empty('upload', 'No staging folders', 'Nothing to clean.') ?>
    <?php else: ?>
        <table>
            <tr><th>Folder</th><th>Files</th><th>Size</th><th>Age</th><th><span class="adm-sr-only">Actions</span></th></tr>
            <?php foreach ($pendingDirs as $dir): ?>
                <tr>
                    <td><code><?= e($dir['name']) ?></code></td>
                    <td><?= (int) $dir['files'] ?></td>
                    <td><?= number_format($dir['size'] / 1048576, 1) ?> MB</td>
                    <td><?= (int) $dir['age_h'] ?>h</td>
                    <td>
                        <?php if ($canOperate): ?>
                            <form class="inline" method="post" action="<?= url('/admin/abandoned-uploads/staging-delete') ?>"
                                  data-confirm="Delete this staging folder?">
                                <?= csrf_field() ?><input type="hidden" name="dir" value="<?= e($dir['name']) ?>">
                                <button class="btn btn-sm btn-danger" type="submit">Delete</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
        <?php if ($canOperate): ?>
            <form class="sys-actions" method="post" action="<?= url('/admin/abandoned-uploads/staging-delete') ?>"
                  data-confirm="Delete ALL <?= count($pendingDirs) ?> staging folders?">
                <?= csrf_field() ?><input type="hidden" name="dir" value="all">
                <button class="btn btn-danger" type="submit">Delete all</button>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php // ----- Orphaned files ----- ?>
<div class="stats-panel" style="margin-top:1.5rem;">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:.5rem;flex-wrap:wrap;">
        <h2 class="u-m0">Orphaned files</h2>
        <?php if (!empty($orphans)): ?>
            <span class="muted u-fs-085">
                <?= number_format((int) $orphanTotal) ?> file<?= (int) $orphanTotal === 1 ? '' : 's' ?> ·
                <?= number_format((float) ($orphanBytes ?? 0) / 1048576, 1) ?> MB
            </span>
        <?php endif; ?>
    </div>
    <p class="muted" style="margin:.35rem 0 .75rem;">
        Files under <code>storage/uploads</code> that nothing refers to: originals with no photo record,
        SEO sample clips whose video is gone, and chat attachments whose message was deleted.
        Click a card to view the full file in a new tab.
    </p>
    <?php if (empty($orphans)): ?>
        <?= admin_empty('upload', 'No orphaned files', 'Every file under storage/uploads belongs to something.') ?>
    <?php else: ?>
        <?php require __DIR__ . '/partials/orphans_grid.php'; ?>
        <?php if ($canOperate): ?>
            <form class="sys-actions" method="post" action="<?= url('/admin/abandoned-uploads/orphans/delete') ?>"
                  data-confirm="Delete all <?= (int) $orphanTotal ?> orphaned files permanently?">
                <?= csrf_field() ?>
                <input type="hidden" name="all" value="1">
                <button class="btn btn-danger" type="submit">Delete all orphans</button>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script nonce="<?= csp_nonce() ?>">
    (function () {
        var results = document.getElementById('orphan-results');
        if (!results) return;

        var loading = false;

        function currentPage() {
            var p = parseInt(new URL(window.location.href).searchParams.get('page') || '1', 10);
            return isNaN(p) || p < 1 ? 1 : p;
        }

        function loadPage(page) {
            if (loading || page < 1) return;
            loading = true;
            results.classList.add('is-loading');
            fetch(results.dataset.url + '?page=' + encodeURIComponent(page))
                .then(function (r) { return r.text(); })
                .then(function (html) {
                    var tmp = document.createElement('div');
                    tmp.innerHTML = html;
                    var fresh = tmp.querySelector('#orphan-results');
                    if (fresh) {
                        results.replaceWith(fresh);
                        results = fresh;
                    }
                    var u = new URL(window.location.href);
                    if (page > 1) { u.searchParams.set('page', String(page)); }
                    else { u.searchParams.delete('page'); }
                    history.pushState({ orphanPage: page }, '', u.toString());
                    loading = false;
                })
                .catch(function () {
                    loading = false;
                    window.location.href = results.dataset.url.slice(0, -'/orphans/page'.length) + (page > 1 ? '?page=' + page : '');
                });
        }

        document.addEventListener('click', function (e) {
            var link = e.target && e.target.closest ? e.target.closest('#orphan-results .pagination a[data-page]') : null;
            if (!link) return;
            e.preventDefault();
            loadPage(parseInt(link.getAttribute('data-page'), 10));
        });

        window.addEventListener('popstate', function () {
            loadPage(currentPage());
        });
    })();
</script>
