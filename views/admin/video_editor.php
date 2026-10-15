<?php
// Fullscreen, layout-free video editor page rendered standalone so the
// editor fills the entire browser window without the admin sidebar/header.
$title = 'Video Editor';

$cssPath = dirname($_SERVER['DOCUMENT_ROOT']) . '/gallery/public/assets/video-editor/video-editor.css';
$jsPath  = dirname($_SERVER['DOCUMENT_ROOT']) . '/gallery/public/assets/video-editor/video-editor.js';
$cssVer = @filemtime($cssPath) ?: time();
$jsVer  = @filemtime($jsPath)  ?: time();
$backGallery = \App\Models\Photo::firstGalleryId((int) $photo['id']);

/* The site's filter chip colours live in the theme, so the panel edits them
   there. They used to be written into this project's JSON, where nothing
   read them, which made the old panel a no-op. */
$siteTheme = \App\Models\Theme::all(\App\Models\Theme::SCOPE_SITE);
$filterKeys = [
    'filter-bg' => 'Active background',
    'filter-color' => 'Active text',
    'filter-border' => 'Active border',
    'filter-hover-bg' => 'Active hover',
    'filter-inactive-bg' => 'Inactive background',
    'filter-inactive-color' => 'Inactive text',
    'filter-inactive-border' => 'Inactive border',
    'filter-inactive-hover' => 'Inactive hover',
];
$filterColors = [];
foreach ($filterKeys as $key => $label) {
    $filterColors[$key] = (string) ($siteTheme[$key] ?? '');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Video Editor</title>
<link rel="stylesheet" href="<?= url('/assets/video-editor/video-editor.css') ?>?v=<?= $cssVer ?>">
<style>
  :root { color-scheme: dark; }
  html, body { margin: 0; padding: 0; height: 100%; overflow: hidden; background: #0f1115; }
  #ve-shell { position: fixed; inset: 0; display: flex; flex-direction: column; background: #0f1115; z-index: 99999; }
  #ve-top { height: 40px; flex-shrink: 0; display: flex; align-items: center; gap: 8px; padding: 0 12px; background: #14171e; border-bottom: 1px solid #262c38; }
  #ve-top .ve-actions { display: flex; align-items: center; gap: 8px; min-width: 0; }
  #ve-top .ve-filename { margin-left: auto; color: #9aa3b2; font-size: 12px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 40%; }
  .ve-btn { display: inline-flex; align-items: center; gap: 5px; height: 26px; padding: 0 10px; border: 1px solid #333a48; border-radius: 4px; background: #20252f; color: #d8dde7; font: 500 11px/1 'Segoe UI', system-ui, sans-serif; text-decoration: none; cursor: pointer; white-space: nowrap; }
  .ve-btn:hover { background: #29303c; color: #fff; }
  .ve-btn.is-primary { background: #5b8af5; border-color: #5b8af5; color: #fff; }
  .ve-btn.is-primary:hover { background: #4a7ae8; }
  .ve-btn.is-on { background: #5b8af5; border-color: #5b8af5; color: #fff; }
  #ve-body { flex: 1; min-height: 0; position: relative; display: flex; }
  #ve-body > #root { flex: 1; min-width: 0; display: flex; flex-direction: column; }
  .ve-pop { position: absolute; top: 48px; right: 12px; width: 300px; padding: 12px 14px; background: #14171e; border: 1px solid #262c38; border-radius: 8px; box-shadow: 0 12px 32px rgba(0,0,0,.5); color: #d8dde7; font: 12px/1.45 'Segoe UI', system-ui, sans-serif; z-index: 100002; }
  .ve-pop[hidden] { display: none; }
  .ve-pop h4 { margin: 0 0 10px; font-size: 11px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: #9aa3b2; }
  .ve-pop .ve-row { display: flex; align-items: center; gap: 8px; margin: 7px 0; }
  .ve-pop label { flex: 1; font-size: 11px; color: #9aa3b2; }
  .ve-pop input[type="color"] { width: 40px; height: 26px; padding: 2px; border: 1px solid #333a48; border-radius: 4px; background: #20252f; cursor: pointer; }
  .ve-pop input[type="file"] { flex: 1; min-width: 0; font-size: 11px; color: #9aa3b2; }
  .ve-pop .ve-tip { margin: 10px 0 0; font-size: 10.5px; color: #6d7686; }
  #ve-thumb-status { min-height: 16px; margin-top: 8px; font-size: 11px; color: #8fb4ff; }
  #ve-thumb-status.error { color: #ff9b9b; }
</style>
</head>
<body>
<div id="ve-shell">
    <div id="ve-top">
        <div class="ve-actions">
            <a class="ve-btn" href="<?= url('/admin/video-projects') ?>">&larr; Video projects</a>
            <?php if ($backGallery): ?>
                <a class="ve-btn" href="<?= url('/admin/galleries/' . $backGallery) ?>">Back to gallery</a>
            <?php endif; ?>
            <button id="ve-max-btn" class="ve-btn" type="button" data-action="toggleMaximize">Maximize preview</button>
            <button id="ve-thumb-btn" class="ve-btn" type="button" data-action="toggleThumb">Thumbnail</button>
            <button id="ve-theme-btn" class="ve-btn" type="button" data-action="toggleTheme">Site filter colors</button>
        </div>
        <div class="ve-filename" title="<?= e($photo['filename']) ?>"><?= e($photo['filename']) ?></div>
    </div>
    <div id="ve-body"><div id="root"></div></div>
</div>

<div id="ve-thumb-panel" class="ve-pop" hidden>
    <h4>Thumbnail tools</h4>
    <form class="ve-row" id="ve-thumb-upload" data-op="thumbnail" enctype="multipart/form-data">
        <input type="file" name="thumbnail" accept="image/*" required>
        <button class="ve-btn" type="submit">Upload</button>
    </form>
    <form class="ve-row" id="ve-thumb-frame" data-op="frame">
        <label>Frame at the playhead</label>
        <button class="ve-btn" type="submit">Capture</button>
    </form>
    <form class="ve-row" id="ve-thumb-regen" data-op="regen">
        <button class="ve-btn" type="submit">Regenerate from the first frame</button>
    </form>
    <div id="ve-thumb-status"></div>
</div>

<div id="ve-theme-panel" class="ve-pop" hidden>
    <h4>Site filter colors</h4>
    <form id="ve-theme-form">
        <?php foreach ($filterKeys as $key => $label): ?>
            <div class="ve-row">
                <label for="ve-theme-<?= e($key) ?>"><?= e($label) ?></label>
                <input type="color" id="ve-theme-<?= e($key) ?>" name="<?= e($key) ?>" value="<?= e($filterColors[$key]) ?>">
            </div>
        <?php endforeach; ?>
        <p><button class="ve-btn is-primary" type="button" id="ve-theme-save">Save filter colors</button></p>
    </form>
    <div id="ve-theme-status"></div>
    <p class="ve-tip">These colours style the filter chips across the member site, so they belong to the theme rather than to this video project. <a href="<?= url('/admin/theme') ?>">Open the full theme editor</a>.</p>
    <p class="ve-tip">These colors style the filter chips across the member site, so they live in the theme rather than in this video project.</p>
</div>

<script nonce="<?= csp_nonce() ?>">
window.__VIDEO_EDITOR__ = <?= json_encode([
    'projectId' => (int) $project['id'],
    'sourceId' => (int) $photo['id'],
    'sourceUrl' => file_url($photo['filename']),
    'token' => \App\Core\Csrf::token(),
    'title' => $project['title'],
    'version' => (int) $project['version'],
    'project' => $project['project'] ?? [],
    'saveUrl' => url('/admin/video-projects/' . (int) $project['id']),
    'exportUrl' => url('/admin/video-projects/' . (int) $project['id'] . '/export'),
    'statusUrl' => url('/admin/video-exports/__JOB__'),
    'downloadUrl' => url('/admin/video-exports/__JOB__/download'),
    'streamUrl' => url('/admin/video-exports/__JOB__/stream'),
    'editUrl' => url('/admin/photos/' . (int) $photo['id'] . '/edit'),
    'themeUrl' => url('/admin/videos/' . (int) $photo['id'] . '/filter-colors'),
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script nonce="<?= csp_nonce() ?>">
var VE_CFG = window.__VIDEO_EDITOR__ || {};
var VE_PANELS = { thumb: 've-thumb-panel', theme: 've-theme-panel' };

function veOnly(which) {
    Object.keys(VE_PANELS).forEach(function (key) {
        var el = document.getElementById(VE_PANELS[key]);
        el.hidden = key !== which;
        document.getElementById('ve-' + key + '-btn').classList.toggle('is-on', key === which);
    });
}
function veStatus(msg, isErr, which) {
    var s = document.getElementById((which === 'theme' ? 've-theme-status' : 've-thumb-status'));
    s.textContent = msg;
    s.className = isErr ? 'error' : '';
}
/* Post a form with a 30s ceiling so the status always settles instead of
   hanging on a stalled request. */
function vePost(fd) {
    return Promise.race([
        fetch(VE_CFG.editUrl, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'fetch' } }),
        new Promise(function (_, reject) { setTimeout(function () { reject(new Error('timeout')); }, 30000); })
    ]);
}
/* Read the frame currently shown in the program monitor. Waits for the video to
   actually have frame data (readyState >= 2) before drawing, and reports false
   if canvas capture is unavailable so the caller can fall back to ffmpeg. */
function veReadMonitorFrame() {
    return new Promise(function (resolve) {
        var video = document.querySelector('.nle-stage-video');
        if (!video) { resolve({ ok: false, err: 'No preview loaded' }); return; }
        var tries = 0;
        function attempt() {
            if (video.readyState >= 2 && video.videoWidth > 0) {
                try {
                    var w = video.videoWidth, h = video.videoHeight;
                    var c = document.createElement('canvas');
                    c.width = w; c.height = h;
                    c.getContext('2d').drawImage(video, 0, 0, w, h);
                    resolve({ ok: true, dataUrl: c.toDataURL('image/jpeg', 0.92) });
                } catch (e) { resolve({ ok: false, err: 'Canvas capture blocked' }); }
                return;
            }
            if (++tries >= 25) { resolve({ ok: false, err: 'Preview not ready' }); return; }
            setTimeout(attempt, 200);
        }
        attempt();
    });
}
async function veCaptureFrame() {
    var form = document.getElementById('ve-thumb-frame');
    var btn = form.querySelector('button');
    btn.disabled = true;
    veStatus('Capturing the frame on screen...');
    try {
        var shot = await veReadMonitorFrame();
        var fd = new FormData();
        fd.set('_token', VE_CFG.token);
        if (shot.ok) {
            fd.set('operation', 'frame');
            fd.set('frame_data', shot.dataUrl);
        } else {
            /* Canvas capture unavailable: fall back to an ffmpeg grab at the
               playhead. */
            var video = document.querySelector('.nle-stage-video');
            var second = video && isFinite(video.currentTime) ? Math.max(0, Math.floor(video.currentTime)) : 0;
            fd.set('operation', 'frame');
            fd.set('second', String(second));
            veStatus('Capturing at ' + second + 's on the server...');
        }
        var res = await vePost(fd);
        var data = {};
        try { data = await res.json(); } catch (e) {}
        if (res.ok && data.ok) veStatus(data.message || 'Thumbnail captured');
        else veStatus(data.error || 'Could not set the thumbnail', true);
    } catch (e) {
        veStatus('Request failed: ' + (e && e.message ? e.message : 'network error'), true);
    } finally {
        btn.disabled = false;
    }
}
async function veThumbSubmit(formId) {
    var form = document.getElementById(formId);
    var fd = new FormData(form);
    fd.set('_token', VE_CFG.token);
    fd.set('operation', form.dataset.op);
    var btn = form.querySelector('button');
    btn.disabled = true;
    veStatus('Working...');
    try {
        var res = await vePost(fd);
        var data = {};
        try { data = await res.json(); } catch (e) {}
        if (res.ok && data.ok) veStatus(data.message || 'Thumbnail updated');
        else veStatus(data.error || 'Thumbnail update failed', true);
    } catch (e) {
        veStatus('Request failed', true);
    } finally {
        btn.disabled = false;
    }
}
async function veSaveThemeColors() {
    var btn = document.getElementById('ve-theme-save');
    var fd = new FormData(document.getElementById('ve-theme-form'));
    fd.set('_token', VE_CFG.token);
    btn.disabled = true;
    veStatus('Saving filter colors...', false, 'theme');
    try {
        var res = await fetch(VE_CFG.themeUrl, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'fetch' } });
        var data = {};
        try { data = await res.json(); } catch (e) {}
        if (res.ok && data.ok) { veStatus(data.message || 'Filter colors saved', false, 'theme'); }
        else veStatus(data.error || 'Saving failed', true, 'theme');
    } catch (e) {
        veStatus('Request failed', true, 'theme');
    } finally {
        btn.disabled = false;
    }
}
document.querySelectorAll('#ve-top [data-action]').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var action = btn.dataset.action;
        if (action === 'toggleMaximize') {
            btn.classList.toggle('is-on');
            btn.textContent = btn.classList.contains('is-on') ? 'Restore layout' : 'Maximize preview';
            window.dispatchEvent(new CustomEvent('ve:fullscreen'));
            return;
        }
        if (action === 'toggleThumb' || action === 'toggleTheme') {
            var panel = document.getElementById(action === 'toggleThumb' ? 've-thumb-panel' : 've-theme-panel');
            var show = panel.hidden;
            veOnly(show ? (action === 'toggleThumb' ? 'thumb' : 'theme') : null);
            return;
        }
});
document.getElementById('ve-theme-save').addEventListener('click', function () { veSaveThemeColors(); });
document.getElementById('ve-thumb-upload').addEventListener('submit', function (e) {
    e.preventDefault();
    veThumbSubmit('ve-thumb-upload');
});
document.getElementById('ve-thumb-frame').addEventListener('submit', function (e) {
    e.preventDefault();
    veCaptureFrame();
});
document.getElementById('ve-thumb-regen').addEventListener('submit', function (e) {
    e.preventDefault();
    veThumbSubmit('ve-thumb-regen');
});
</script>
<script nonce="<?= csp_nonce() ?>" type="module" src="<?= url('/assets/video-editor/video-editor.js') ?>?v=<?= $jsVer ?>"></script>
</body>
</html>
