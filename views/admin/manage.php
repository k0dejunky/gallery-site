<?php $title = 'Manage: ' . $gallery['title']; ?>

<?php // Per-photo controls: upload new files, edit caption/link, reorder, rotate images and remove from this gallery. ?>
<h1>Manage &ldquo;<?= e($gallery['title']) ?>&rdquo;</h1>

<?php // Gallery-level view tracking stats. ?>
<p class="muted">
    <?= number_format((int) ($gallery['views'] ?? 0)) ?> total views &middot; <?= number_format((int) ($gallery['unique_views'] ?? 0)) ?> unique viewers
</p>

<h2>Categories</h2>
<form method="post" action="<?= url('/admin/galleries/' . (int) $gallery['id']) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="title" value="<?= e($gallery['title']) ?>">
    <input type="hidden" name="description" value="<?= e($gallery['description'] ?? '') ?>">
    <label class="type-switch" style="margin-bottom: var(--spacing-sm);">
        <span>Gallery type</span>
        <input type="hidden" name="type" value="images">
        <input type="checkbox" name="type" value="videos"<?= ($gallery['type'] ?? 'images') === 'videos' ? ' checked' : '' ?> aria-label="Toggle video gallery">
        <span class="type-switch-track" aria-hidden="true"></span>
        <strong class="type-switch-label"><?= ($gallery['type'] ?? 'images') === 'videos' ? 'Video Gallery' : 'Image Gallery' ?></strong>
    </label>
    <p class="muted" style="margin-top: var(--spacing-sm);">
        <label for="manage-min-level">Membership level required to view</label>
        <select name="min_level" id="manage-min-level">
            <option value="0"<?= (int) ($gallery['min_level'] ?? 0) === 0 ? ' selected' : '' ?>>Level 0 — Free for all registered users</option>
            <option value="1"<?= (int) ($gallery['min_level'] ?? 0) === 1 ? ' selected' : '' ?>>Level 1 — Silver</option>
            <option value="2"<?= (int) ($gallery['min_level'] ?? 0) === 2 ? ' selected' : '' ?>>Level 2 — Gold</option>
            <option value="3"<?= (int) ($gallery['min_level'] ?? 0) === 3 ? ' selected' : '' ?>>Level 3 — Platinum</option>
            <option value="4"<?= (int) ($gallery['min_level'] ?? 0) === 4 ? ' selected' : '' ?>>Level 4 — Diamond</option>
        </select>
        <span class="muted">Members below this level cannot view the gallery.</span>
    </p>
    <?php if (empty($categories)): ?>
        <p class="muted">No categories available.</p>
    <?php else: ?>
        <div class="chips">
            <?php foreach ($categories as $category): ?>
                <label class="chip favorite-option<?= in_array((int) $category['id'], $assigned ?? [], true) ? ' selected' : '' ?>" data-category-name="<?= e($category['name']) ?>">
                    <input type="checkbox" name="categories[]" value="<?= (int) $category['id'] ?>"<?= in_array((int) $category['id'], $assigned ?? [], true) ? ' checked' : '' ?>>
                    <?= e($category['name']) ?>
                </label>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <button type="submit" class="btn">Save Gallery Settings</button>
</form>
<script>
(function () {
    var labels = document.querySelectorAll('[data-category-name]');
    function updateCategories() {
        labels.forEach(function (label) {
            var input = label.querySelector('input[type="checkbox"]');
            label.classList.toggle('selected', input.checked);
        });
    }
    labels.forEach(function (label) {
        label.querySelector('input[type="checkbox"]').addEventListener('change', updateCategories);
    });
    updateCategories();

    var typeSwitch = document.querySelector('.type-switch input[type="checkbox"]');
    var typeLabel = document.querySelector('.type-switch-label');
    if (typeSwitch && typeLabel) {
        typeSwitch.addEventListener('change', function () {
            typeLabel.textContent = typeSwitch.checked ? 'Video Gallery' : 'Image Gallery';
        });
    }
})();
</script>

<?php // Accept multiple images/videos at once; the controller enforces the gallery type per file. ?>
<h2>Upload photos</h2>
<p class="muted">
    This is an <strong><?= ($gallery['type'] ?? 'images') === 'videos' ? 'Video Gallery' : 'Image Gallery' ?></strong> —
    <?= ($gallery['type'] ?? 'images') === 'videos' ? 'only video files can be uploaded' : 'only image files can be uploaded' ?>.
</p>
<form method="post" action="<?= url('/admin/galleries/' . (int) $gallery['id'] . '/photos') ?>" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <p>
        <input type="file" name="photos[]" accept="<?= ($gallery['type'] ?? 'images') === 'videos' ? 'video/*' : 'image/*' ?>" multiple>
        <button type="submit" class="btn">Upload</button>
    </p>
</form>

<h2>Photos</h2>
<?php if (empty($photos)): ?>
    <p>No photos yet.</p>
<?php else: ?>
    <form method="post" action="<?= url('/admin/galleries/' . (int) $gallery['id'] . '/photos/bulk-rotate') ?>" class="bulk-photo-form">
        <?= csrf_field() ?>
        <div class="bulk-photo-toolbar">
            <label><input type="checkbox" id="select-all-images"> Select all images</label>
            <span class="muted" id="selected-image-count">0 selected</span>
            <?php if (!empty($activeEditJob) && in_array($activeEditJob['status'], ['queued', 'running'], true)): ?>
                <span class="muted" id="edit-job-status">
                    Rotate in progress: <?= e($activeEditJob['status']) ?>
                    (<?= (int) $activeEditJob['done'] + (int) $activeEditJob['failed'] ?>/<?= (int) $activeEditJob['total'] ?>, <?= (int) $activeEditJob['progress'] ?>%)
                </span>
            <?php endif; ?>
            <button type="submit" name="direction" value="left" class="btn btn-sm" disabled data-bulk-rotate>&larr; Rotate left</button>
            <button type="submit" name="direction" value="right" class="btn btn-sm" disabled data-bulk-rotate>Rotate right &rarr;</button>
            <?php if (!empty($activeEditJob) && in_array($activeEditJob['status'], ['queued', 'running'], true)): ?>
                <span class="muted" title="Another rotation is already processing this gallery.">Buttons disabled while a job runs.</span>
            <?php endif; ?>
        </div>
    <table>
        <thead>
            <tr>
                <th aria-label="Select"></th>
                <th>Preview</th>
                <th>Caption / Link</th>
                <th>Views</th>
                <th>Order</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($photos as $photo): ?>
                <tr>
                    <td>
                        <?php if (!is_video($photo['filename'])): ?>
                            <input type="checkbox" name="photo_ids[]" value="<?= (int) $photo['id'] ?>" data-image-select aria-label="Select image <?= (int) $photo['id'] ?>">
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (is_video($photo['filename'])): ?>
                            <video src="<?= e(file_url($photo['filename'])) ?>" width="120" muted preload="metadata"></video>
                        <?php else: ?>
                            <img src="<?= e(file_url($photo['filename'], 'thumb')) ?>" alt="" width="80">
                        <?php endif; ?>
                    </td>
                    <td>
                        <form method="post" action="<?= url('/admin/galleries/' . (int) $gallery['id'] . '/photos/' . (int) $photo['id'] . '/caption') ?>">
                            <?= csrf_field() ?>
                            <input type="text" name="caption" value="<?= e($photo['caption']) ?>" placeholder="Caption"><br>
                            <input type="url" name="link" value="<?= e($photo['link']) ?>" placeholder="https://link-to (optional)" size="30">
                            <button type="submit" class="btn btn-sm">Save</button>
                        </form>
                    </td>
                    <td>
                        <span title="Total views / unique views"><?= number_format((int) $photo['views']) ?> / <?= number_format((int) $photo['unique_views']) ?></span>
                    </td>
                    <td>
                        <form class="inline" method="post" action="<?= url('/admin/galleries/' . (int) $gallery['id'] . '/photos/' . (int) $photo['id'] . '/move') ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="direction" value="up">
                            <button type="submit" class="btn btn-sm" title="Move up">&uarr;</button>
                        </form>
                        <form class="inline" method="post" action="<?= url('/admin/galleries/' . (int) $gallery['id'] . '/photos/' . (int) $photo['id'] . '/move') ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="direction" value="down">
                            <button type="submit" class="btn btn-sm" title="Move down">&darr;</button>
                        </form>
                    </td>
                    <td>
                        <?php if (is_video($photo['filename'])): ?>
                            <a class="btn btn-sm" href="<?= url('/admin/videos/' . (int) $photo['id'] . '/edit') ?>">Edit Video</a>
                        <?php else: ?>
                            <a class="btn btn-sm btn-outline" href="<?= url('/admin/photos/' . (int) $photo['id'] . '/edit?back=' . (int) $gallery['id']) ?>">Edit</a>
                        <?php endif; ?>
                        <?php if (!is_video($photo['filename'])): ?>
                            <form class="inline" method="post" action="<?= url('/admin/galleries/' . (int) $gallery['id'] . '/photos/' . (int) $photo['id'] . '/rotate') ?>" data-photo-rotate>
                                <?= csrf_field() ?>
                                <input type="hidden" name="direction" value="left">
                                <button type="submit" class="btn btn-sm" title="Rotate left">&larr;</button>
                            </form>
                            <form class="inline" method="post" action="<?= url('/admin/galleries/' . (int) $gallery['id'] . '/photos/' . (int) $photo['id'] . '/rotate') ?>" data-photo-rotate>
                                <?= csrf_field() ?>
                                <input type="hidden" name="direction" value="right">
                                <button type="submit" class="btn btn-sm" title="Rotate right">&rarr;</button>
                            </form>
                        <?php endif; ?>
                        <form class="inline" method="post" action="<?= url('/admin/galleries/' . (int) $gallery['id'] . '/photos/' . (int) $photo['id'] . '/delete') ?>"
                              onsubmit="return confirm('Remove this photo from this gallery? The shared file will be kept if another gallery uses it.');">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-sm btn-danger">Remove from gallery</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </form>
    <script>
    (function () {
        // Per-photo rotate is handled in place via AJAX so the admin keeps
        // their scroll position. Without JS the form still posts normally.
        var forms = Array.prototype.slice.call(document.querySelectorAll('[data-photo-rotate]'));
        forms.forEach(function (form) {
            form.addEventListener('submit', function (e) {
                e.preventDefault();

                var token = form.querySelector('input[name="_token"]');
                var direction = form.querySelector('input[name="direction"]');
                if (!token || !direction) return;

                var row = form.closest('tr');
                var img = row ? row.querySelector('td img') : null;
                var button = form.querySelector('button');

                if (button) button.disabled = true;

                var body = new FormData();
                body.append('_token', token.value);
                body.append('direction', direction.value);

                fetch(form.action, {
                    method: 'POST',
                    body: body,
                    headers: { 'X-Requested-With': 'fetch' },
                    redirect: 'manual'
                })
                    .then(function (r) {
                        // The server now answers with JSON (no redirect, no
                        // flash), so nothing accumulates in the session and
                        // there is no body to download. Refresh the preview.
                        if (img) {
                            var base = img.src.split('?')[0];
                            img.src = base + '?size=thumb&v=' + Date.now();
                        }
                    })
                    .catch(function () {})
                    .then(function () {
                        if (window.AdminProgress) window.AdminProgress.hide();
                        if (button) button.disabled = false;
                    });
            });
        });
    }());
    </script>
    <script>
    (function () {
        var all = document.getElementById('select-all-images');
        var boxes = Array.prototype.slice.call(document.querySelectorAll('[data-image-select]'));
        var buttons = Array.prototype.slice.call(document.querySelectorAll('[data-bulk-rotate]'));
        var count = document.getElementById('selected-image-count');
        var jobActive = <?php echo (!empty($activeEditJob) && in_array($activeEditJob['status'], ['queued', 'running'], true)) ? 'true' : 'false'; ?>;
        if (jobActive) {
            boxes.forEach(function (box) { box.disabled = true; });
            if (all) all.disabled = true;
        }
        function update() {
            var selected = boxes.filter(function (box) { return box.checked && !box.disabled; }).length;
            count.textContent = selected + ' selected';
            buttons.forEach(function (button) { button.disabled = selected === 0 || jobActive; });
            if (all) all.checked = boxes.length > 0 && selected === boxes.length;
        }
        if (all) all.addEventListener('change', function () {
            boxes.forEach(function (box) { box.checked = all.checked; });
            update();
        });
        boxes.forEach(function (box) { box.addEventListener('change', update); });
        update();
    }());
    </script>
<?php endif; ?>
