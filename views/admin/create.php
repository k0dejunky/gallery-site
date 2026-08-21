<?php $title = 'New Gallery'; ?>

<style>
    .create-page { max-width: none; }
    .create-page h1 { margin: 0 0 .25rem; }
    .create-page .page-sub { margin: 0 0 1.25rem; }

    .create-grid {
        display: grid;
        grid-template-columns: minmax(360px, 520px) 1fr;
        gap: 1.5rem;
        align-items: start;
    }
    @media (max-width: 860px) { .create-grid { grid-template-columns: 1fr; } }

    .create-form-card {
        border: 1px solid var(--card-border, #ddd);
        border-radius: var(--card-radius, 8px);
        background: var(--card-bg, #fff);
        padding: 1.25rem;
        position: sticky;
        top: 1rem;
    }
    .create-form-card h2 { margin: 0 0 1rem; font-size: var(--font-size-lg, 1.15rem); }
    .create-form-card input[type="text"],
    .create-form-card textarea,
    .create-form-card select { width: 100%; box-sizing: border-box; }
    .create-form-card .field { margin-bottom: 1rem; }
    .create-form-card .field label { display: block; margin-bottom: .35rem; font-weight: 600; }
    .create-form-card .field .muted { display: block; margin-top: .3rem; }
    .type-switch-row { display: flex; gap: .5rem; }
    .create-actions { display: flex; gap: .5rem; margin-top: 1.25rem; }
    .create-actions .btn { flex: 1; text-align: center; }
    .create-actions .btn:last-child { flex: 0 0 auto; }
    .cat-chips { display: flex; flex-wrap: wrap; gap: .4rem; max-height: 150px; overflow-y: auto; }

    .upload-panel { min-width: 0; }
    .drop-zone {
        border: 2px dashed var(--card-border, #bbb);
        border-radius: var(--border-radius, 6px);
        padding: 2rem 1.5rem;
        text-align: center;
        color: var(--text-muted, #666);
        background: var(--card-bg, #fafafa);
        cursor: pointer;
        transition: border-color .15s ease, background .15s ease;
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
    .empty-state { padding: 1.5rem; text-align: center; color: var(--text-muted, #888); border: 1px dashed var(--card-border, #ddd); border-radius: var(--border-radius, 6px); }
</style>

<div class="create-page">
    <h1>New Gallery</h1>
    <p class="page-sub muted">Upload files first — they appear as tiled previews. Then give the gallery a name and save.</p>

    <div class="create-grid">
        <aside class="create-form-card">
            <h2>Gallery details</h2>
            <form method="post" action="<?= url('/admin/galleries') ?>" id="gallery-form">
                <?= csrf_field() ?>

                <div class="field">
                    <label for="title">Title *</label>
                    <input type="text" name="title" id="title" placeholder="e.g. Beach Vacation" required>
                </div>

                <div class="field">
                    <label for="description">Description</label>
                    <textarea name="description" id="description" rows="3" placeholder="Optional description…"></textarea>
                </div>

                <div class="field">
                    <label>Gallery type</label>
                    <div class="type-switch-row">
                        <label class="chip active">
                            <input type="radio" name="type" value="images" checked>
                            🖼 Images
                        </label>
                        <label class="chip">
                            <input type="radio" name="type" value="videos">
                            ▶ Videos
                        </label>
                    </div>
                    <span class="muted">Image galleries accept only images; video galleries only videos.</span>
                </div>

                <div class="field">
                    <label>Categories</label>
                    <?php if (empty($categories)): ?>
                        <span class="muted">No categories yet — <a href="<?= url('/admin/categories') ?>">add some first</a>.</span>
                    <?php else: ?>
                        <div class="cat-chips">
                            <?php foreach ($categories as $category): ?>
                                <label class="chip favorite-option">
                                    <input type="checkbox" name="categories[]" value="<?= (int) $category['id'] ?>">
                                    <?= e($category['name']) ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="field">
                    <label for="min_level">Minimum membership level required</label>
                    <select name="min_level" id="min_level">
                        <option value="0" selected>No restriction (Level 0)</option>
                        <option value="1">Level 1 (Silver)</option>
                        <option value="2">Level 2 (Gold)</option>
                        <option value="3">Level 3 (Platinum)</option>
                        <option value="4">Level 4 (Diamond)</option>
                    </select>
                </div>

                <div class="create-actions">
                    <button type="submit" class="btn" id="save-btn">Save Gallery</button>
                    <a class="btn btn-outline" href="<?= url('/admin/galleries') ?>">Cancel</a>
                </div>
            </form>
        </aside>

        <section class="upload-panel">
            <div class="drop-zone" id="drop-zone" tabindex="0">
                <div class="dz-icon">&#128228;</div>
                <div class="dz-main">Drop files here or click to upload</div>
                <div class="dz-hint" id="dz-type-hint">Image files for an image gallery</div>
                <input type="file" id="file-input" name="photos[]" multiple style="display:none">
            </div>

            <div class="pending-head">
                <h2>Files to add</h2>
                <span class="count"><span id="pending-count">0</span> uploaded</span>
            </div>
            <div class="pending-tiles" id="pending-tiles"></div>
        </section>
    </div>
</div>

<script>
(function () {
    var csrf = document.querySelector('#gallery-form input[name="_token"]').value;
    var typeInputs = Array.prototype.slice.call(document.querySelectorAll('input[name="type"]'));
    var fileInput = document.getElementById('file-input');
    var dropZone = document.getElementById('drop-zone');
    var typeHint = document.getElementById('dz-type-hint');
    var tilesEl = document.getElementById('pending-tiles');
    var countEl = document.getElementById('pending-count');
    var saveBtn = document.getElementById('save-btn');
    var pendingFiles = [];

    function currentType() {
        var checked = typeInputs.find(function (i) { return i.checked; });
        return checked ? checked.value : 'images';
    }

    function updateTypeHint() {
        typeHint.textContent = currentType() === 'videos'
            ? 'Video files for a video gallery'
            : 'Image files for an image gallery';
    }

    typeInputs.forEach(function (input) { input.addEventListener('change', updateTypeHint); });

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function render() {
        countEl.textContent = pendingFiles.length;
        tilesEl.innerHTML = '';
        if (!pendingFiles.length) {
            tilesEl.innerHTML = '<div class="empty-state">No files uploaded yet.</div>';
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

    function uploadFiles(fileList) {
        var type = currentType();
        var data = new FormData();
        var names = [];
        Array.prototype.forEach.call(fileList, function (file) {
            data.append('photos[]', file);
            names.push(file.name);
        });
        data.append('type', type);
        data.append('_token', csrf);

        saveBtn.disabled = true;

        // Show the shared admin progress overlay with a real upload %
        // (browser-provided via XHR upload.onprogress).
        if (window.AdminProgress) {
            window.AdminProgress.show('Uploading files…');
            window.AdminProgress.progress(0, names.join(', '));
        }

        var xhr = new XMLHttpRequest();
        xhr.open('POST', '<?= url('/admin/galleries/pending/upload') ?>');
        xhr.upload.addEventListener('progress', function (e) {
            if (e.lengthComputable && window.AdminProgress) {
                window.AdminProgress.progress((e.loaded / e.total) * 100, names.join(', '));
            }
        });
        xhr.addEventListener('load', function () {
            try {
                var res = JSON.parse(xhr.responseText);
                if (res.ok) {
                    pendingFiles = res.files;
                    render();
                    if (window.AdminProgress) window.AdminProgress.hide();
                } else {
                    if (window.AdminProgress) window.AdminProgress.hide();
                    alert(res.error || 'Upload failed.');
                }
            } catch (err) {
                if (window.AdminProgress) window.AdminProgress.hide();
                alert('Upload failed.');
            }
            saveBtn.disabled = false;
            fileInput.value = '';
        });
        xhr.addEventListener('error', function () {
            if (window.AdminProgress) window.AdminProgress.hide();
            saveBtn.disabled = false;
            alert('Upload failed.');
        });
        xhr.send(data);
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
    updateTypeHint();
})();
</script>
