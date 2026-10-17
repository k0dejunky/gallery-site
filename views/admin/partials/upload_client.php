<?php
/**
 * Staged-upload client: queues selected files, uploads them ONE per request
 * (PHP truncates a single request at max_file_uploads, and per-file requests
 * let one bad file fail without cancelling the batch), slices anything at or
 * above chunk_min into resumable chunks, and lets the operator rotate or delete
 * a staged file before it is committed.
 *
 * This is the single copy. admin/create.php and admin/manage.php each had their
 * own ~360-line version of it, byte-identical apart from the few knobs below.
 *
 * Set $uploadConfig before requiring:
 *   token         string  CSS selector of the form holding the _token input (required)
 *   typeInputs    string  optional selector for gallery-type radios; wins over `type`
 *   type          string  fallback type when there are no radios ('images')
 *   typeHint      string  optional element id whose text follows the selected type
 *   busyButton    string  optional id of the submit button to disable while uploading
 *   requireFiles  bool    also keep busyButton disabled when nothing is staged
 *   empty         string  empty-state text
 *   debug         bool    log queue transitions to the console
 */
$uploadConfig = array_merge([
    'token'        => '',
    'typeInputs'   => '',
    'type'         => 'images',
    'typeHint'     => '',
    'busyButton'   => '',
    'requireFiles' => false,
    'empty'        => 'No files yet.',
    'debug'        => false,
], $uploadConfig ?? []);
?>
<script nonce="<?= csp_nonce() ?>">
(function () {
    var cfg = <?= json_encode($uploadConfig, JSON_UNESCAPED_SLASHES) ?>;
    var csrfEl = document.querySelector(cfg.token + ' input[name="_token"]');
    if (!csrfEl) { return; }
    var csrf = csrfEl.value;

    var typeInputs = cfg.typeInputs
        ? Array.prototype.slice.call(document.querySelectorAll(cfg.typeInputs))
        : [];
    var fileInput = document.getElementById('file-input');
    var dropZone = document.getElementById('drop-zone');
    var typeHint = cfg.typeHint ? document.getElementById(cfg.typeHint) : null;
    var tilesEl = document.getElementById('pending-tiles');
    var countEl = document.getElementById('pending-count');
    var busyButton = cfg.busyButton ? document.getElementById(cfg.busyButton) : null;
    var pendingFiles = <?= json_encode($pendingFiles ?? [], JSON_UNESCAPED_SLASHES) ?> || [];
    var uploadQueue = [];
    var queuedTiles = []; // uploadQueue items that are showing an on-screen tile
    var uploading = false;

    // Upload debugging: window.__galleryUploadDebug = true prints every queue
    // transition to the console (handy if "add more" seems to do nothing).
    function debugLog() {
        if (!cfg.debug || !window.__galleryUploadDebug) return;
        console.info('[gallery-upload]', Array.prototype.slice.call(arguments).join(' '));
    }

    // Files at/above CHUNK_MIN bytes are uploaded as CHUNK_SIZE chunks so a
    // multi-GB video uploads as many small fast requests (resumable) instead
    // of one long request the webserver/fastcgi timeouts would kill. Kept in
    // sync with config/app.php => uploads => chunk_size / chunk_min.
    var CHUNK_MIN = <?= (int) config('app.uploads.chunk_min') ?>;
    var CHUNK_SIZE = <?= (int) config('app.uploads.chunk_size') ?>;

    function currentType() {
        if (!typeInputs.length) { return cfg.type; }
        var checked = typeInputs.find(function (i) { return i.checked; });
        return checked ? checked.value : cfg.type;
    }

    function updateTypeHint() {
        if (!typeHint) { return; }
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

    // Keep the submit button in step with the queue. create.php only disables it
    // while a batch is running; manage.php also keeps it off until something is
    // staged, hence cfg.requireFiles.
    function syncButtons() {
        if (!busyButton) { return; }
        busyButton.disabled = uploading || (cfg.requireFiles && pendingFiles.length === 0);
    }

    function render() {
        countEl.textContent = pendingFiles.length + (queuedTiles.length ? ' + ' + queuedTiles.length + ' queued' : '');
        syncButtons();
        tilesEl.innerHTML = '';
        if (!pendingFiles.length && !queuedTiles.length) {
            tilesEl.innerHTML = '<div class="empty-state">' + esc(cfg.empty) + '</div>';
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
        // Queue every selected file and upload them ONE per request. Every file
        // gets an on-screen "Queued…" tile immediately so a slow or mixed batch
        // shows feedback the moment it is selected; the tile is replaced by the
        // real thumbnail once the server confirms the upload (success) or marked
        // failed (rejection) otherwise.
        var type = currentType();
        var added = 0;
        Array.prototype.forEach.call(fileList, function (file) {
            var item = { file: file, type: type, tile: waitTile(file.name) };
            queuedTiles.push(item);
            tilesEl.appendChild(item.tile);
            uploadQueue.push(item);
            added++;
        });
        debugLog('selected', added, 'file(s); queue is now', uploadQueue.length);
        processQueue();
    }

    function processQueue() {
        if (uploading) return;
        if (!uploadQueue.length) return;

        uploading = true;
        syncButtons();

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
                fileInput.value = '';
                queuedTiles = [];
                render();
                if (window.AdminProgress) window.AdminProgress.hide();
                debugLog('queue drained');
                if (failures.length) {
                    alert('Some files could not be uploaded:\n\n' + failures.join('\n'));
                }
                return;
            }

            var item = uploadQueue[0];
            var sp = item.tile && item.tile.querySelector('.tile-spinner');
            if (sp) sp.textContent = 'Uploading…';
            debugLog('uploading', item.file.name, '(' + item.file.size + ' bytes)');
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
            debugLog('failed', item.file.name, '-', message);
            next();
        }

        // Small files: a single POST.
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
    updateTypeHint();
})();
</script>
