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

<?php // Drag-and-drop upload: stage files into this session's pending area
// (exactly like the gallery creation page), then commit them to this gallery.
// Staged files use the same pending endpoints and validation as the create
// page, so a file that passes here passes there (and vice versa). ?>
<style>
    .drop-zone {
        border: 2px dashed var(--card-border, #bbb);
        border-radius: var(--border-radius, 6px);
        padding: 2rem 1.5rem;
        text-align: center;
        color: var(--text-muted, #666);
        background: var(--card-bg, #fafafa);
        cursor: pointer;
        transition: border-color .15s ease, background .15s ease;
        box-sizing: border-box;
        width: 100%;
    }
    .drop-zone:hover { border-color: var(--purple-400, #a855f7); }
    .drop-zone.dragover { border-color: var(--purple-500, #9333ea); background: color-mix(in srgb, var(--purple-500, #9333ea) 8%, transparent); }
    .drop-zone .dz-icon { font-size: 2rem; line-height: 1; margin-bottom: .35rem; }
    .drop-zone .dz-main { font-weight: 600; color: var(--purple-700, #6b21a8); }
    .drop-zone .dz-hint { font-size: var(--font-size-sm, .9rem); margin-top: .4rem; }

    .pending-head { display: flex; align-items: center; justify-content: space-between; margin: 1.25rem 0 .75rem; }
    .pending-head h2 { margin: 0; font-size: var(--font-size-lg, 1.15rem); }
    .pending-head .count { color: var(--text-muted, #888); font-size: var(--font-size-sm, .9rem); }

    .pending-tiles { display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: 1rem; }
    .pending-tile {
        border: 1px solid var(--card-border, #ddd);
        border-radius: var(--border-radius, 6px);
        overflow: hidden;
        background: var(--card-bg, #fff);
        position: relative;
    }
    .pending-tile .media { width: 100%; aspect-ratio: 4/3; object-fit: cover; display: block; background: #111; }
    .pending-tile .tile-name {
        display: block;
        padding: .3rem .45rem;
        font-size: var(--font-size-xs, .75rem);
        color: var(--text-muted, #666);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .pending-tile .tile-controls { display: flex; gap: .35rem; padding: .4rem .45rem; border-top: 1px solid var(--card-border, #eee); }
    .pending-tile .tile-controls button {
        flex: 1;
        border: 1px solid var(--card-border, #ddd);
        background: var(--card-bg, #f4f4f4);
        border-radius: 4px;
        padding: .25rem .3rem;
        font-size: var(--font-size-xs, .75rem);
        cursor: pointer;
    }
    .pending-tile .tile-controls button:hover { background: var(--pink-100, #eee); }
    .pending-tile .tile-controls button.danger { color: var(--danger, #c62828); border-color: var(--danger, #c62828); }
    .pending-tile.is-busy::after { content: ''; position: absolute; inset: 0; background: rgba(0,0,0,.25); }
    .tile-spinner { position: absolute; inset: 0; display: grid; place-items: center; background: rgba(255,255,255,.7); color: var(--text-muted, #888); font-size: var(--font-size-xs, .75rem); }
    .pending-tile.is-waiting { opacity: .85; }
    .pending-tile.is-failed { opacity: .55; border-color: var(--danger, #c62828); }
    .pending-tile.is-failed .tile-name { color: var(--danger, #c62828); }
    .empty-state { padding: 1.5rem; text-align: center; color: var(--text-muted, #888); border: 1px dashed var(--card-border, #ddd); border-radius: var(--border-radius, 6px); }
</style>

<?php // Accept multiple images/videos at once; the controller enforces the gallery type per file. ?>
<h2>Upload photos</h2>
<p class="muted">
    This is an <strong><?= ($gallery['type'] ?? 'images') === 'videos' ? 'Video Gallery' : 'Image Gallery' ?></strong> —
    <?= ($gallery['type'] ?? 'images') === 'videos' ? 'only video files can be uploaded' : 'only image files can be uploaded' ?>.
    Drop files here (or click to select) to stage them, then press <strong>Add staged files to this gallery</strong>.
</p>
<form method="post" action="<?= url('/admin/galleries/' . (int) $gallery['id'] . '/pending-commit') ?>" id="pending-commit-form">
    <?= csrf_field() ?>
    <div class="drop-zone" id="drop-zone" tabindex="0">
        <div class="dz-icon">&#128228;</div>
        <div class="dz-main">Drop files here or click to upload</div>
        <div class="dz-hint"><?= ($gallery['type'] ?? 'images') === 'videos' ? 'Video files for a video gallery' : 'Image files for an image gallery' ?></div>
        <input type="file" id="file-input" name="photos[]" multiple style="display:none">
    </div>

    <div class="pending-head">
        <h2>Staged files</h2>
        <span class="count"><span id="pending-count">0</span> staged</span>
    </div>
    <div class="pending-tiles" id="pending-tiles"></div>

    <p style="margin-top:1rem;">
        <button type="submit" class="btn" id="commit-btn" disabled>Add staged files to this gallery</button>
        <span class="muted">Staged files stay in your session until committed, so a reload won't lose them.</span>
    </p>
</form>
<script>
(function () {
    var csrf = document.querySelector('#pending-commit-form input[name="_token"]').value;
    var galleryType = <?= json_encode(($gallery['type'] ?? 'images') === 'videos' ? 'videos' : 'images', JSON_UNESCAPED_SLASHES) ?>;
    var fileInput = document.getElementById('file-input');
    var dropZone = document.getElementById('drop-zone');
    var tilesEl = document.getElementById('pending-tiles');
    var countEl = document.getElementById('pending-count');
    var commitBtn = document.getElementById('commit-btn');
    var pendingFiles = <?= json_encode($pendingFiles ?? [], JSON_UNESCAPED_SLASHES) ?> || [];
    var uploadQueue = [];
    var queuedTiles = []; // uploadQueue items that are showing an on-screen tile
    var uploading = false;
    // Files at/above CHUNK_MIN bytes are uploaded as CHUNK_SIZE chunks so a
    // multi-GB video uploads as many small fast requests (resumable) instead
    // of one long request the webserver/fastcgi timeouts would kill. Kept in
    // sync with config/app.php => uploads => chunk_size / chunk_min.
    var CHUNK_MIN = <?= (int) config('app.uploads.chunk_min') ?>;
    var CHUNK_SIZE = <?= (int) config('app.uploads.chunk_size') ?>;

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function render() {
        countEl.textContent = pendingFiles.length + (queuedTiles.length ? ' + ' + queuedTiles.length + ' queued' : '');
        commitBtn.disabled = uploading || pendingFiles.length === 0;
        tilesEl.innerHTML = '';
        if (!pendingFiles.length && !queuedTiles.length) {
            tilesEl.innerHTML = '<div class="empty-state">No files staged yet.</div>';
            return;
        }
        pendingFiles.forEach(function (f) {
            var tile = document.createElement('div');
            tile.className = 'pending-tile';
            tile.dataset.file = f.filename;
            tile.innerHTML =
                (f.is_image
                    ? '<img class="media" src="' + esc(f.thumb_url) + '" alt="" loading="lazy">'
                    : '<video class="media" src="' + esc(f.file_url) + '" poster="' + esc(f.thumb_url) + '" muted preload="metadata"></video>') +
                '<span class="tile-name">' + esc(f.original) + '</span>' +
                '<div class="tile-controls">' +
                    (f.is_image
                        ? '<button type="button" data-act="rotate" data-dir="left" title="Rotate left">&larr;</button>' +
                          '<button type="button" data-act="rotate" data-dir="right" title="Rotate right">&rarr;</button>'
                        : '') +
                    '<button type="button" data-act="delete" class="danger" title="Remove">&times;</button>' +
                '</div>';
            tilesEl.appendChild(tile);
        });
        queuedTiles.forEach(function (item) { tilesEl.appendChild(item.tile); });
    }

    function setBusy(tile, busy) {
        if (!tile) return;
        tile.classList.toggle('is-busy', busy);
        if (busy) {
            var sp = document.createElement('div');
            sp.className = 'tile-spinner';
            sp.textContent = 'Working…';
            tile.appendChild(sp);
        } else {
            var s = tile.querySelector('.tile-spinner');
            if (s) s.remove();
        }
    }

    function waitTile(name) {
        var tile = document.createElement('div');
        tile.className = 'pending-tile is-waiting';
        var sp = document.createElement('div');
        sp.className = 'tile-spinner';
        sp.textContent = 'Queued…';
        var nm = document.createElement('span');
        nm.className = 'tile-name';
        nm.textContent = name;
        tile.appendChild(sp);
        tile.appendChild(nm);
        return tile;
    }

    function uploadFiles(fileList) {
        // Queue every selected file and upload them ONE per request.
        // PHP silently truncates multi-file requests at max_file_uploads
        // (default 20), so a single mega-request would drop everything past
        // the 20th file. Per-file requests have no count limit, keep the
        // exact same server-side validation rules for every file, and let
        // one bad file fail without cancelling the rest of the batch.
        //
        // Every selected file gets an on-screen "Queued…" tile immediately so
        // a slow or mixed batch shows feedback the moment it is selected; the
        // tile is replaced by the real thumbnail once the server confirms the
        // upload (success) or marked failed (rejection) otherwise.
        var type = galleryType;
        var added = 0;
        Array.prototype.forEach.call(fileList, function (file) {
            var item = { file: file, type: type, tile: waitTile(file.name) };
            queuedTiles.push(item);
            tilesEl.appendChild(item.tile);
            uploadQueue.push(item);
            added++;
        });
        processQueue();
    }

    function processQueue() {
        if (uploading) return;
        if (!uploadQueue.length) return;

        uploading = true;
        commitBtn.disabled = true;

        var totalBytes = uploadQueue.reduce(function (sum, item) { return sum + item.file.size; }, 0);
        var sentBytes = 0; // cumulative bytes of fully-completed files
        var failures = [];

        if (window.AdminProgress) {
            window.AdminProgress.show('Uploading files…');
            window.AdminProgress.progress(0, uploadQueue.length + ' file(s)');
        }

        function report(pct, label) {
            if (window.AdminProgress) {
                window.AdminProgress.progress(pct == null ? (sentBytes / Math.max(totalBytes, 1)) * 100 : pct, label || uploadQueue.length + ' file(s) remaining');
            }
        }

        function next() {
            if (!uploadQueue.length) {
                uploading = false;
                commitBtn.disabled = pendingFiles.length === 0;
                fileInput.value = '';
                queuedTiles = [];
                render();
                if (window.AdminProgress) window.AdminProgress.hide();
                if (failures.length) {
                    alert('Some files could not be uploaded:\n\n' + failures.join('\n'));
                }
                return;
            }

            var item = uploadQueue[0];
            var sp = item.tile && item.tile.querySelector('.tile-spinner');
            if (sp) sp.textContent = 'Uploading…';
            if (item.file.size >= CHUNK_MIN) uploadChunked(item);
            else uploadDirect(item);
        }

        // Finish one file successfully and move to the next in the queue.
        function finishFile(item, data) {
            if (data && data.files) { pendingFiles = data.files; }
            var qi = queuedTiles.indexOf(item);
            if (qi >= 0) queuedTiles.splice(qi, 1);
            render();
            sentBytes += item.file.size;
            report();
            uploadQueue.shift();
            next();
        }

        // Drop one file with a message and move to the next in the queue.
        function failFile(item, message) {
            failures.push(message);
            uploadQueue.shift();
            if (item.tile) {
                var qi = queuedTiles.indexOf(item);
                if (qi >= 0) queuedTiles.splice(qi, 1);
                item.tile.classList.add('is-failed');
                var nm = item.tile.querySelector('.tile-name');
                if (nm) nm.textContent = item.file.name + ' — rejected';
                item.tile.title = message;
            }
            next();
        }

        // Small files: a single POST, exactly as the create page does.
        function uploadDirect(item) {
            var data = new FormData();
            data.append('photos[]', item.file);
            data.append('type', item.type);
            data.append('_token', csrf);

            var xhr = new XMLHttpRequest();
            xhr.open('POST', '<?= url('/admin/galleries/pending/upload') ?>');
            xhr.upload.addEventListener('progress', function (e) {
                if (e.lengthComputable) report(((sentBytes + e.loaded) / Math.max(totalBytes, 1)) * 100, item.file.name);
            });
            xhr.addEventListener('load', function () {
                var ok = false, skipped = [], reason = '', res = null;
                try { res = JSON.parse(xhr.responseText); ok = res.ok === true; skipped = res.skipped || []; reason = res.error || ''; } catch (err) {}
                if (!ok) failFile(item, item.file.name + ': rejected by server' + (reason ? ' — ' + reason : ''));
                else if (skipped.length) failFile(item, skipped[0] + ': could not be saved');
                else finishFile(item, res);
            });
            xhr.addEventListener('error', function () {
                failFile(item, item.file.name + ': network error');
            });
            xhr.send(data);
        }

        // Large files: slice into chunks, upload each chunk to /pending/chunk
        // (auto-retrying a chunk on a transient failure so the upload resumes
        // from the last good chunk), then finalise with /pending/complete.
        function uploadChunked(item) {
            var uid = 'u' + Date.now().toString(36) + Math.random().toString(36).slice(2, 8);
            var total = Math.max(1, Math.ceil(item.file.size / CHUNK_SIZE));
            var chunk = 0;

            function sendChunk() {
                if (chunk >= total) { completeFile(); return; }
                var start = chunk * CHUNK_SIZE;
                var end = Math.min(item.file.size, start + CHUNK_SIZE);
                var blob = item.file.slice(start, end);
                var fd = new FormData();
                fd.append('chunk', blob, 'chunk.bin');
                fd.append('upload_uid', uid);
                fd.append('chunk_index', String(chunk));
                fd.append('total_chunks', String(total));
                fd.append('type', item.type);
                fd.append('_token', csrf);

                var xhr = new XMLHttpRequest();
                xhr.open('POST', '<?= url('/admin/galleries/pending/chunk') ?>');
                xhr.upload.addEventListener('progress', function (e) {
                    if (e.lengthComputable) {
                        var done = Math.min(item.file.size, chunk * CHUNK_SIZE + e.loaded);
                        report(((sentBytes + done) / Math.max(totalBytes, 1)) * 100, chunk + '/' + total + ' — ' + item.file.name);
                    }
                });
                xhr.addEventListener('load', function () {
                    var ok = false;
                    try { ok = JSON.parse(xhr.responseText).ok === true; } catch (err) {}
                    if (!ok) {
                        failFile(item, item.file.name + ': rejected by server');
                        return;
                    }
                    chunk++;
                    sendChunk();
                });
                xhr.addEventListener('error', function () {
                    // Network drop: retry this chunk in place (resume). Give up
                    // after a few attempts so a dead link surfaces to the user.
                    var attempt = 0;
                    function retry() {
                        attempt++;
                        if (attempt <= 8) { setTimeout(sendChunk, 700 * attempt); return; }
                        failFile(item, item.file.name + ': network error (could not resume)');
                    }
                    retry();
                });
                xhr.send(fd);
            }

            // All chunks stored -> ask the server to assemble + validate.
            function completeFile() {
                var fd = new FormData();
                fd.append('upload_uid', uid);
                fd.append('original_name', item.file.name);
                fd.append('total_chunks', String(total));
                fd.append('type', item.type);
                fd.append('_token', csrf);

                var xhr = new XMLHttpRequest();
                xhr.open('POST', '<?= url('/admin/galleries/pending/complete') ?>');
                xhr.addEventListener('load', function () {
                    var res = null;
                    try { res = JSON.parse(xhr.responseText); } catch (err) {}
                    if (!res || res.ok !== true) {
                        var msg = res && res.error ? ' — ' + res.error : '';
                        failFile(item, item.file.name + ': rejected by server' + msg);
                        return;
                    }
                    finishFile(item, res);
                });
                xhr.addEventListener('error', function () {
                    failFile(item, item.file.name + ': network error');
                });
                xhr.send(fd);
            }

            sendChunk();
        }

        next();
    }

    dropZone.addEventListener('click', function () { fileInput.click(); });
    dropZone.addEventListener('dragover', function (e) {
        e.preventDefault();
        dropZone.classList.add('dragover');
    });
    dropZone.addEventListener('dragleave', function () { dropZone.classList.remove('dragover'); });
    dropZone.addEventListener('drop', function (e) {
        e.preventDefault();
        dropZone.classList.remove('dragover');
        if (e.dataTransfer && e.dataTransfer.files.length) uploadFiles(e.dataTransfer.files);
    });
    fileInput.addEventListener('change', function () { if (fileInput.files.length) uploadFiles(fileInput.files); });

    tilesEl.addEventListener('click', function (e) {
        var btn = e.target.closest('button[data-act]');
        if (!btn) return;
        var tile = btn.closest('.pending-tile');
        var filename = tile.dataset.file;
        var act = btn.dataset.act;

        if (act === 'delete') {
            setBusy(tile, true);
            var body = new FormData();
            body.append('_token', csrf);
            fetch('<?= url('/admin/galleries/pending') ?>/' + encodeURIComponent(filename) + '/delete', { method: 'POST', body: body })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res.ok) { pendingFiles = res.files; render(); }
                    else alert(res.error || 'Could not remove file.');
                })
                .catch(function () { setBusy(tile, false); alert('Could not remove file.'); });
            return;
        }

        if (act === 'rotate') {
            setBusy(tile, true);
            var rbody = new FormData();
            rbody.append('direction', btn.dataset.dir);
            rbody.append('_token', csrf);
            fetch('<?= url('/admin/galleries/pending') ?>/' + encodeURIComponent(filename) + '/rotate', { method: 'POST', body: rbody })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res.ok) { pendingFiles = res.files; render(); }
                    else { setBusy(tile, false); alert(res.error || 'Could not rotate image.'); }
                })
                .catch(function () { setBusy(tile, false); alert('Could not rotate image.'); });
        }
    });

    render();
})();
</script>

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

<?php // Collapsible gallery queue: galleries waiting for a future publish moment. ?>
<details style="margin-top:1.5rem;border:1px solid var(--card-border,#ddd);border-radius:var(--card-radius,8px);padding:1.25rem;background:var(--card-bg,#fff);">
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
