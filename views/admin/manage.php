<?php $title = 'Manage: ' . $gallery['title']; ?>

<?php // Scheduled-publication notice: a queued gallery is hidden publicly until its publish moment. ?>
<?php if (!empty($gallery['published_at']) && $gallery['published_at'] > gmdate('Y-m-d H:i:s')): ?>
    <div style="border:1px solid var(--purple-400, #a855f7);border-radius:var(--card-radius,8px);background:var(--pink-100, #fdf4ff);padding:1rem;margin-bottom:1.25rem;">
        <strong>Scheduled for publication</strong> at <span class="muted"><?= e(tzdate('Y-m-d H:i', (string) $gallery['published_at'])) ?></span> (site time).
        This gallery is hidden from the public site until that moment, and its recommended X / Reddit posts are scheduled for the same time.
        <form class="inline" method="post" action="<?= url('/admin/galleries/' . (int) $gallery['id'] . '/publish-now') ?>" style="margin-top:.6rem;">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-sm">Publish now</button>
        </form>
    </div>
<?php endif; ?>

<?php // Per-photo controls: upload new files, edit caption/link, reorder, rotate images and remove from this gallery. ?>
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
        </select>
        <span class="muted">Members below this level cannot view the gallery.</span>
    </p>
    <p>
        <label><input type="hidden" name="featured" value="0"><input type="checkbox" name="featured" value="1" <?= !empty($gallery['featured']) ? 'checked' : '' ?>> Featured on the homepage</label>
    </p>
    <p>
        <label for="manage-ppv-price">Pay-per-view price (USD)</label>
        <input type="number" step="0.01" min="0" name="ppv_price" id="manage-ppv-price"
               value="<?= $gallery['ppv_price'] !== null && (float) $gallery['ppv_price'] > 0 ? e(number_format((float) $gallery['ppv_price'], 2, '.', '')) : '' ?>"
               placeholder="Leave empty for membership-only">
        <span class="muted">Non-members can unlock the full gallery with a code; leave empty to hide this option.</span>
    </p>
    <p>
        <label for="manage-tags">Tags</label>
        <input type="text" name="tags" id="manage-tags" value="<?= e($tagValue ?? '') ?>" placeholder="comma, separated, tags" style="min-width:280px;">
    </p>
    <p>
        <label><input type="hidden" name="is_test" value="0"><input type="checkbox" name="is_test" value="1" <?= !empty($gallery['is_test']) ? 'checked' : '' ?>> Test gallery (do not announce it)</label>
        <span class="muted">Members are not emailed about it, it does not show in their notifications, and it is not posted to social media.</span>
    </p>
    <?php if (\App\Core\Auth::isSuperAdmin()): ?>
    <?php $allowedIds = array_map('intval', array_column($allowedUsers ?? [], 'id')); ?>
    <p>
        <label><input type="checkbox" name="is_secret" value="1" <?= !empty($gallery['is_secret']) ? 'checked' : '' ?>> Secret gallery</label>
        <span class="muted">Only selected users can see this gallery, regardless of membership level.</span>
    </p>
    <p>
        <label for="manage-allowed-users">Allowed users</label>
        <select name="allowed_users[]" id="manage-allowed-users" multiple size="6" style="min-width:280px;">
            <?php foreach (($accessUsers ?? []) as $accessUser): ?>
                <option value="<?= (int) $accessUser['id'] ?>"<?= in_array((int) $accessUser['id'], $allowedIds, true) ? ' selected' : '' ?>><?= e($accessUser['email']) ?></option>
            <?php endforeach; ?>
        </select>
    </p>
    <?php endif; ?>
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

<?php // AI category suggestions: proposals staged by bin/categorize_worker.php.
// Accept merges into the checkboxes above (the gallery's categories); dismiss
// only retires the proposal. Nothing here is ever applied automatically. ?>
<?php if (!empty($suggestions) || (($suggestionJob['status'] ?? '') === 'error') || (($suggestionJob['status'] ?? '') === 'queued') || (($suggestionJob['status'] ?? '') === 'running')): ?>
<h2 class="u-mt-15">AI Suggestions</h2>
<?php if (($suggestionJob['status'] ?? '') === 'error'): ?>
    <p class="muted" style="color:var(--red-600, #b91c1c);">
        Last analysis failed: <?= e($suggestionJob['error'] ?? 'unknown error') ?>
        <form class="inline" method="post" action="<?= url('/admin/category-suggestions/reanalyze') ?>" style="display:inline;margin-left:.5rem;">
            <?= csrf_field() ?>
            <input type="hidden" name="gallery_id" value="<?= (int) $gallery['id'] ?>">
            <button type="submit" class="btn btn-sm">Retry</button>
        </form>
    </p>
<?php elseif (($suggestionJob['status'] ?? '') === 'queued' || ($suggestionJob['status'] ?? '') === 'running'): ?>
    <p class="muted">Analysis <?= e($suggestionJob['status']) ?> — refresh shortly to see suggestions.</p>
<?php endif; ?>

<?php if (!empty($suggestions)): ?>
    <p class="muted">The AI reviewed this gallery's media and proposes these categories. Accept to add them (your current categories are kept), dismiss to ignore.</p>
    <div class="chips">
        <?php foreach ($suggestions as $suggestion): ?>
            <span class="chip" style="display:inline-flex;align-items:center;gap:.4rem;">
                <?= e($suggestion['category_name']) ?>
                <?php if ($suggestion['confidence'] !== null): ?>
                    <span class="muted" style="font-size:.8em;"><?= (int) round((float) $suggestion['confidence'] * 100) ?>%</span>
                <?php endif; ?>
                <form class="inline" method="post" action="<?= url('/admin/category-suggestions/accept') ?>" style="display:inline;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $suggestion['id'] ?>">
                    <button type="submit" class="btn btn-sm" title="Accept suggestion" aria-label="Accept <?= e($suggestion['category_name']) ?>">✓</button>
                </form>
                <form class="inline" method="post" action="<?= url('/admin/category-suggestions/dismiss') ?>" style="display:inline;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $suggestion['id'] ?>">
                    <button type="submit" class="btn btn-sm" title="Dismiss suggestion" aria-label="Dismiss <?= e($suggestion['category_name']) ?>">✕</button>
                </form>
            </span>
        <?php endforeach; ?>
    </div>
    <form class="inline" method="post" action="<?= url('/admin/category-suggestions/accept-all') ?>" style="margin-top:.6rem;">
        <?= csrf_field() ?>
        <input type="hidden" name="gallery_id" value="<?= (int) $gallery['id'] ?>">
        <button type="submit" class="btn btn-sm">Accept all</button>
    </form>
<?php endif; ?>
<?php endif; ?>

<?php // AI description suggestion: staged by bin/describe_worker.php. Accept
// writes galleries.description (optionally after editing); dismiss only
// retires the proposal. Nothing here is ever applied automatically. ?>
<?php if (!empty($descriptionSuggestion) || in_array(($descriptionJob['status'] ?? ''), ['error', 'queued', 'running'], true)): ?>
<h2 class="u-mt-15">AI Description</h2>
<?php if (($descriptionJob['status'] ?? '') === 'error'): ?>
    <p class="muted" style="color:var(--red-600, #b91c1c);">
        Last description attempt failed: <?= e($descriptionJob['error'] ?? 'unknown error') ?>
        <form class="inline" method="post" action="<?= url('/admin/description-suggestions/reanalyze') ?>" style="display:inline;margin-left:.5rem;">
            <?= csrf_field() ?>
            <input type="hidden" name="gallery_id" value="<?= (int) $gallery['id'] ?>">
            <button type="submit" class="btn btn-sm">Retry</button>
        </form>
    </p>
<?php elseif (in_array(($descriptionJob['status'] ?? ''), ['queued', 'running'], true)): ?>
    <p class="muted">Description generation <?= e($descriptionJob['status']) ?> — refresh shortly to see the proposal.</p>
<?php endif; ?>

<?php if (!empty($descriptionSuggestion)): ?>
    <p class="muted">The AI wrote this description; accept to save it onto the gallery (your current description, if any, is replaced), dismiss to ignore. Tweak the text right here, or on the <a href="<?= url('/admin/description-suggestions') ?>">AI Descriptions</a> page.</p>
    <form method="post" action="<?= url('/admin/description-suggestions/accept') ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int) $descriptionSuggestion['id'] ?>">
        <textarea class="u-w-full" name="text" rows="2" maxlength="600" aria-label="Proposed description"><?= e((string) $descriptionSuggestion['description']) ?></textarea>
        <div style="margin-top:.5rem;display:flex;gap:.5rem;flex-wrap:wrap;">
            <button type="submit" class="btn btn-sm">Accept description</button>
        </div>
    </form>
    <form class="inline" method="post" action="<?= url('/admin/description-suggestions/dismiss') ?>" style="margin-top:.5rem;">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int) $descriptionSuggestion['id'] ?>">
        <button type="submit" class="btn btn-sm btn-outline">Dismiss ✕</button>
    </form>
    <form class="inline" method="post" action="<?= url('/admin/description-suggestions/reanalyze') ?>" style="margin-top:.5rem;margin-left:.5rem;">
        <?= csrf_field() ?>
        <input type="hidden" name="gallery_id" value="<?= (int) $gallery['id'] ?>">
        <button type="submit" class="btn btn-sm btn-outline">Regenerate</button>
    </form>
<?php endif; ?>
<?php endif; ?>
<script nonce="<?= csp_nonce() ?>">
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

<?php // Drag-and-drop upload: stage files into this session's pending area
// (exactly like the gallery creation page), then commit them to this gallery.
// Staged files use the same pending endpoints and validation as the create
// page, so a file that passes here passes there (and vice versa). ?>

<?php // Accept multiple images/videos at once; the controller enforces the gallery type per file. ?>
<h2>Upload photos</h2>
<p class="muted">
    This is an <strong><?= ($gallery['type'] ?? 'images') === 'videos' ? 'Video Gallery' : 'Image Gallery' ?></strong> —
    <?= ($gallery['type'] ?? 'images') === 'videos' ? 'only video files can be uploaded' : 'only image files can be uploaded' ?>.
    Drop files here (or click to select) to stage them, then press <strong>Add staged files to this gallery</strong>.
</p>
<form method="post" action="<?= url('/admin/galleries/' . (int) $gallery['id'] . '/pending-commit') ?>" id="pending-commit-form">
    <?= csrf_field() ?>
    <?php
    $uploadHeading   = 'Staged files';
    $uploadCountNoun = 'staged';
    $uploadHint      = ($gallery['type'] ?? 'images') === 'videos' ? 'Video files for a video gallery' : 'Image files for an image gallery';
    ob_start();
    ?>
        <p class="u-mt-1">
            <button type="submit" class="btn" id="commit-btn" disabled>Add staged files to this gallery</button>
            <span class="muted">Staged files stay in your session until committed, so a reload won't lose them.</span>
        </p>
    <?php
    $uploadAfter = (string) ob_get_clean();
    require __DIR__ . '/partials/staged_uploads.php';
    ?>
</form>
<?php
// The staged-upload client is shared with create.php. This gallery has a fixed
// type, and "Add staged files…" stays disabled until something is staged.
$uploadConfig = [
    'token'        => '#pending-commit-form',
    'type'         => ($gallery['type'] ?? 'images') === 'videos' ? 'videos' : 'images',
    'busyButton'   => 'commit-btn',
    'requireFiles' => true,
    'empty'        => 'No files staged yet.',
];
require __DIR__ . '/partials/upload_client.php';
?>

<h2>Photos</h2>
<?php if (empty($photos)): ?>
    <p>No photos yet.</p>
<?php else: ?>
    <form method="post" action="<?= url('/admin/galleries/' . (int) $gallery['id'] . '/photos/bulk-rotate') ?>" class="bulk-photo-form">
        <?= csrf_field() ?>
        <div class="bulk-photo-toolbar">
            <label><input type="checkbox" id="select-all-images"> Select all</label>
            <span class="muted" id="selected-image-count">0 selected</span>
            <?php /* The status line is ALWAYS there: the bulk actions post in
                the background now, so the page updates this text in place
                instead of reloading. */ ?>
            <span class="muted" id="edit-job-status">
                <?php if (!empty($activeEditJob) && in_array($activeEditJob['status'], ['queued', 'running'], true)): ?>
                    <?= $activeEditJob['operation'] === 'bulk_watermark' ? 'Watermark in progress' : 'Rotate in progress' ?>:
                    <?= e($activeEditJob['status']) ?>
                    (<?= (int) $activeEditJob['done'] + (int) $activeEditJob['failed'] ?>/<?= (int) $activeEditJob['total'] ?>, <?= (int) $activeEditJob['progress'] ?>%)
                <?php endif; ?>
            </span>
            <button type="submit" name="direction" value="left" class="btn btn-sm" disabled data-bulk-rotate>&larr; Rotate left</button>
            <button type="submit" name="direction" value="right" class="btn btn-sm" disabled data-bulk-rotate>Rotate right &rarr;</button>
            <input type="text" name="caption" placeholder="Caption for selected…" style="max-width:170px;" disabled data-bulk-caption-input>
            <button type="submit" class="btn btn-sm btn-outline" formaction="<?= url('/admin/galleries/' . (int) $gallery['id'] . '/photos/bulk-caption') ?>" disabled data-bulk-caption>Set caption</button>
            <button type="submit" class="btn btn-sm btn-danger" formaction="<?= url('/admin/galleries/' . (int) $gallery['id'] . '/photos/bulk-delete') ?>" disabled data-bulk-delete data-confirm="Remove the selected photos from this gallery? Files are deleted only if no other gallery uses them.">Delete selected</button>
            <?php // Watermark uses the theme title image, bottom-right at 20% opacity; the preview opens before anything runs. ?>
            <button type="button" class="btn btn-sm" id="watermark-open" disabled data-watermark-button>Watermark…</button>
            <?php
            $wmBackups = [];
            if (!empty($activeEditJob) && $activeEditJob['operation'] === 'bulk_watermark' && $activeEditJob['status'] === 'completed') {
                $wmMeta = json_decode((string) ($activeEditJob['metadata_json'] ?? '{}'), true) ?: [];
                $wmBackups = is_array($wmMeta['backups'] ?? null) ? $wmMeta['backups'] : [];
            }
            ?>
            <?php if ($wmBackups !== []): ?>
                <button type="submit" class="btn btn-sm btn-outline" id="watermark-undo" formaction="<?= url('/admin/galleries/' . (int) $gallery['id'] . '/watermark-undo') ?>" data-confirm="Restore the originals saved by the last watermark run for this gallery?">Undo watermark (<?= count($wmBackups) ?>)</button>
            <?php endif; ?>
            <?php if (!empty($activeEditJob) && in_array($activeEditJob['status'], ['queued', 'running'], true)): ?>
                <span class="muted" title="Another job is already processing this gallery.">Buttons disabled while a job runs.</span>
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
                        <?php // Videos are selectable too: the watermark job re-encodes them. ?>
                        <input type="checkbox" name="photo_ids[]" value="<?= (int) $photo['id'] ?>" data-image-select aria-label="Select <?= is_video($photo['filename']) ? 'video' : 'image' ?> <?= (int) $photo['id'] ?>">
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
                              data-confirm="Remove this photo from this gallery? The shared file will be kept if another gallery uses it.">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-sm btn-danger">Remove from gallery</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </form>
    <script nonce="<?= csp_nonce() ?>">
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
    <script nonce="<?= csp_nonce() ?>">
    (function () {
        /* The bulk form posts in the BACKGROUND: the page used to reload on
           every rotation, which threw the admin back to the top of a long
           gallery and lost their place. Now the request goes out, the
           "rotation in progress" text updates in place, and the thumbnails
           refresh when the job is done. Without JS the form still posts
           normally. */
        var bulkForm = document.querySelector('form.bulk-photo-form');
        if (!bulkForm) { return; }
        var statusEl = document.getElementById('edit-job-status');
        var base = (document.body && document.body.getAttribute('data-base')) || '';
        var pollTimer = null;

        function setStatus(text) { if (statusEl) { statusEl.textContent = text; } }

        function refreshThumbs() {
            document.querySelectorAll('.card-cover img, td img, .media-thumb img').forEach(function (img) {
                var src = img.getAttribute('src') || '';
                if (!src) { return; }
                img.src = src.split('?')[0] + '?size=thumb&v=' + Date.now();
            });
        }

        function busy(on) {
            window.__manageJobActive = on;
            if (typeof window.__manageUpdateButtons === 'function') { window.__manageUpdateButtons(); }
        }

        /* A finished watermark job can be undone, so the button appears
           without a reload. */
        function showUndo(count) {
            if (!count) { return; }
            var toolbar = document.querySelector('.bulk-photo-toolbar');
            if (!toolbar) { return; }
            var button = document.getElementById('watermark-undo');
            if (!button) {
                button = document.createElement('button');
                button.type = 'submit';
                button.id = 'watermark-undo';
                button.className = 'btn btn-sm btn-outline';
                button.setAttribute('formaction', toolbar.closest('form').getAttribute('action')
                    .replace(/\/photos\/bulk-rotate$/, '/watermark-undo'));
                button.setAttribute('data-confirm', 'Restore the originals saved by the last watermark run for this gallery?');
                toolbar.appendChild(button);
            }
            button.textContent = 'Undo watermark (' + count + ')';
        }

        function pollJob(jobId) {
            if (pollTimer) { clearInterval(pollTimer); }
            pollTimer = setInterval(function () {
                fetch(base + '/admin/photos/job/' + jobId, { headers: { 'Accept': 'application/json' } })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (!data || !data.ok) { return; }
                        if (data.active) {
                            setStatus((data.operation === 'bulk_watermark' ? 'Watermark' : 'Rotation')
                                + ' in progress: ' + data.done + '/' + data.total + '…');
                            return;
                        }
                        clearInterval(pollTimer);
                        pollTimer = null;
                        busy(false);
                        setStatus(data.failed > 0
                            ? 'Finished: ' + data.done + ' done, ' + data.failed + ' failed.'
                            : 'Finished: ' + data.done + ' done.');
                        refreshThumbs();
                        showUndo(data.backups);
                    })
                    .catch(function () { clearInterval(pollTimer); pollTimer = null; busy(false); });
            }, 1500);
        }

        // The other script on this page watches a job that is already running.
        window.__manageWatchJob = pollJob;

        bulkForm.addEventListener('submit', function (e) {
            /* The undo-watermark button posts to its own action and is a
               page-level action: let it submit normally. */
            var undo = e.submitter && String(e.submitter.id || '') === 'watermark-undo';
            if (undo) { return; }

            e.preventDefault();

            /* The buttons carry their own formaction (the caption and the
               delete actions), so the request has to go to the CLICKED
               button's action, not to the form's. The attribute is read
               directly: the formAction property falls back to the page URL
               when a button has no formaction of its own. */
            var submitter = e.submitter || null;
            var own = submitter ? submitter.getAttribute('formaction') : null;
            var action = own ? new URL(own, location.href).href : bulkForm.action;
            var fd = new FormData(bulkForm);
            busy(true);
            setStatus('Queuing…');

            fetch(action, {
                method: 'POST',
                body: fd,
                headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' }
            })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data || !data.ok) {
                        busy(false);
                        setStatus('That did not work: ' + ((data && data.error) || 'unknown error'));
                        return;
                    }
                    setStatus(data.message || 'Queued.');
                    if (data.job_id) { pollJob(data.job_id); }
                })
                .catch(function () {
                    busy(false);
                    setStatus('The request failed. Reload the page to try again.');
                });
        });
    }());
    </script>
    <script nonce="<?= csp_nonce() ?>">
    (function () {
        var all = document.getElementById('select-all-images');
        var boxes = Array.prototype.slice.call(document.querySelectorAll('[data-image-select]'));
        var buttons = Array.prototype.slice.call(document.querySelectorAll('[data-bulk-rotate], [data-bulk-caption], [data-bulk-delete]'));
        var captionInput = document.querySelector('[data-bulk-caption-input]');
        var count = document.getElementById('selected-image-count');
        var watermarkButton = document.getElementById('watermark-open');
        var jobActive = <?php echo (!empty($activeEditJob) && in_array($activeEditJob['status'], ['queued', 'running'], true)) ? 'true' : 'false'; ?>;
        if (jobActive) {
            boxes.forEach(function (box) { box.disabled = true; });
            if (all) all.disabled = true;
        }
        function update() {
            // A job queued from this page (the bulk rotate posts in the
            // background) keeps the buttons off until it is done.
            var busy = jobActive || window.__manageJobActive === true;
            var selected = boxes.filter(function (box) { return box.checked && !box.disabled; }).length;
            count.textContent = selected + ' selected';
            buttons.forEach(function (button) { button.disabled = selected === 0 || busy; });
            if (captionInput) captionInput.disabled = selected === 0 || busy;
            if (watermarkButton) watermarkButton.disabled = selected === 0 || busy;
            if (all) all.checked = boxes.length > 0 && selected === boxes.length;
        }
        window.__manageUpdateButtons = update;
        if (all) all.addEventListener('change', function () {
            boxes.forEach(function (box) { box.checked = all.checked; });
            update();
        });
        boxes.forEach(function (box) { box.addEventListener('change', update); });
        update();
    }());
    </script>
<?php endif; ?>

<?php // Watermark preview / apply dialog: sample one item before queueing the gallery-wide job. ?>
<dialog class="wm-dialog" id="wm-dialog" aria-labelledby="wm-title">
    <h3 id="wm-title">Watermark this gallery</h3>
    <div class="wm-spec">
        The theme's <strong>title image</strong> (<em>Admin &gt; Theme</em>) is stamped once,
        <strong>bottom-right</strong>, at <strong>20% opacity</strong>, sized to 15% of the media
        width. Preview one item below before running it across the whole gallery.
    </div>
    <div class="wm-scope">
        <label><input type="radio" name="wm-scope" value="selected" checked> <span id="wm-selected-label">Selected items</span></label>
        <label><input type="radio" name="wm-scope" value="all"> This whole gallery (<?= count($photos ?? []) ?> items)</label>
    </div>
    <p><label><input type="checkbox" id="wm-sample" checked> Preview a sample first</label></p>
    <div class="wm-preview" id="wm-preview" hidden><img id="wm-preview-img" alt="Watermark sample"></div>
    <p class="muted" id="wm-note">Videos are re-encoded with the watermark burned in, so a gallery with videos takes roughly as long as the videos themselves to play.</p>
    <div class="wm-actions">
        <button type="button" class="btn btn-primary" id="wm-apply" disabled>Apply watermark</button>
        <button type="button" class="btn btn-outline" id="wm-cancel">Cancel</button>
        <span class="wm-status muted" id="wm-status"></span>
    </div>
</dialog>
<form method="post" action="<?= url('/admin/galleries/' . (int) $gallery['id'] . '/photos/bulk-watermark') ?>" id="wm-form" hidden>
    <?= csrf_field() ?>
    <input type="hidden" name="photo_ids" id="wm-photo-ids">
    <input type="hidden" name="scope" id="wm-scope-input">
</form>
<script nonce="<?= csp_nonce() ?>">
(function () {
    var dialog      = document.getElementById('wm-dialog');
    var openBtn     = document.getElementById('watermark-open');
    var applyBtn    = document.getElementById('wm-apply');
    var cancelBtn   = document.getElementById('wm-cancel');
    var previewBox  = document.getElementById('wm-preview');
    var previewImg  = document.getElementById('wm-preview-img');
    var sampleCheck = document.getElementById('wm-sample');
    var statusEl    = document.getElementById('wm-status');
    var noteEl      = document.getElementById('wm-note');
    var form        = document.getElementById('wm-form');
    var idsInput    = document.getElementById('wm-photo-ids');
    var scopeInput  = document.getElementById('wm-scope-input');
    var selectedLbl = document.getElementById('wm-selected-label');
    if (!dialog || !openBtn || typeof dialog.showModal !== 'function') return;

    var sampleUrl = <?= json_encode(url('/admin/galleries/' . (int) $gallery['id'] . '/watermark-sample')) ?>;

    function boxes() {
        return Array.prototype.slice.call(document.querySelectorAll('[data-image-select]'));
    }
    function selected() {
        return boxes().filter(function (b) { return b.checked; }).map(function (b) { return b.value; });
    }

    function reset() {
        previewBox.hidden = true;
        previewImg.removeAttribute('src');
        applyBtn.disabled = false;
        statusEl.textContent = '';
        noteEl.hidden = false;
    }

    function loadSample() {
        var ids = selected();
        if (!ids.length || !sampleCheck.checked) {
            previewBox.hidden = true;
            applyBtn.disabled = ids.length === 0;
            return;
        }
        previewBox.hidden = false;
        previewImg.removeAttribute('src');
        statusEl.textContent = 'Rendering sample…';
        applyBtn.disabled = true;
        fetch(sampleUrl + '?photo=' + encodeURIComponent(ids[0]), { credentials: 'same-origin' })
            .then(function (r) {
                if (!r.ok) { return r.text().then(function (t) { throw new Error(t || 'Sample failed'); }); }
                return r.blob();
            })
            .then(function (blob) {
                previewImg.src = URL.createObjectURL(blob);
                statusEl.textContent = '';
                applyBtn.disabled = false;
            })
            .catch(function (err) {
                previewBox.hidden = true;
                statusEl.textContent = err.message || 'Could not render a sample.';
            });
    }

    openBtn.addEventListener('click', function () {
        var n = selected().length;
        document.querySelector('input[name="wm-scope"][value="selected"]').checked = true;
        selectedLbl.textContent = 'Selected items (' + n + ')';
        reset();
        dialog.showModal();
    });
    cancelBtn.addEventListener('click', function () { dialog.close(); });
    dialog.addEventListener('click', function (e) { if (e.target === dialog) dialog.close(); });
    sampleCheck.addEventListener('change', loadSample);

    applyBtn.addEventListener('click', function () {
        var checked = document.querySelector('input[name="wm-scope"]:checked');
        var scope = checked ? checked.value : 'selected';
        var ids = scope === 'all' ? [] : selected();
        if (scope !== 'all' && ids.length === 0) {
            statusEl.textContent = 'Select at least one item.';
            return;
        }
        idsInput.value = ids.join(',');
        scopeInput.value = scope === 'all' ? 'all' : '';
        applyBtn.disabled = true;
        statusEl.textContent = 'Queued — processing continues in the background…';
        form.submit();
    });

    /* A job that is already running when the page opens is watched in place.
       The page used to reload itself every five seconds while a job ran,
       which threw the admin back to the top of a long gallery; the progress
       line and the undo button now update without a reload. */
    var jobRunning = <?php echo (!empty($activeEditJob) && in_array($activeEditJob['status'], ['queued', 'running'], true)) ? 'true' : 'false'; ?>;
    if (jobRunning && typeof window.__manageWatchJob === 'function') {
        window.__manageWatchJob(<?php echo (int) ($activeEditJob['id'] ?? 0); ?>);
    }
}());
</script>

<?php // Collapsible gallery queue: galleries waiting for a future publish moment. ?>
<details style="margin-top:1.5rem;border:1px solid var(--card-border,#ddd);border-radius:var(--card-radius,8px);padding:1.25rem;background:var(--card-bg,#fff);">
    <summary style="cursor:pointer;font-weight:600;">Gallery queue (<?= count($queuedGalleries ?? []) ?>)</summary>
    <?php if (empty($queuedGalleries)): ?>
        <p class="muted u-mt-075">No galleries are scheduled for a future publication.</p>
    <?php else: ?>
        <table style="width:100%;border-collapse:collapse;margin-top:.75rem;">
            <thead>
                <tr>
                    <th class="u-left-pad">Gallery</th>
                    <th class="u-left-pad">Scheduled</th>
                    <th style="text-align:right;padding:.4rem .5rem;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($queuedGalleries as $queued): ?>
                    <tr>
                        <td class="u-pad-04-05">
                            <a href="<?= url('/admin/galleries/' . (int) $queued['id']) ?>"><?= e((string) $queued['title']) ?></a>
                        </td>
                        <td class="muted u-pad-04-05"><?= e(tzdate('Y-m-d H:i', (string) $queued['published_at'])) ?></td>
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
