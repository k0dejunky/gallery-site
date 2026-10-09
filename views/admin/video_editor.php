<?php
// Fullscreen, layout-free video editor page rendered standalone so the
// editor fills the entire browser window without the admin sidebar/header.
$title = 'Video Editor';

$cssPath = dirname($_SERVER['DOCUMENT_ROOT']) . '/gallery/public/assets/video-editor/video-editor.css';
$jsPath  = dirname($_SERVER['DOCUMENT_ROOT']) . '/gallery/public/assets/video-editor/video-editor.js';
$cssVer = @filemtime($cssPath) ?: time();
$jsVer  = @filemtime($jsPath)  ?: time();
$backGallery = \App\Models\Photo::firstGalleryId((int) $photo['id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Video Editor</title>
<link rel="stylesheet" href="<?= url('/assets/video-editor/video-editor.css') ?>?v=<?= $cssVer ?>">
<style>
  html, body { margin: 0; padding: 0; height: 100%; overflow: hidden; background: #1e1e1e; }
  #ve-shell { position: fixed; inset: 0; display: flex; flex-direction: column; background: #1e1e1e; z-index: 99999; }
  #ve-top { height: 38px; flex-shrink: 0; display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 0 12px; background: #252526; border-bottom: 1px solid #333; }
  #ve-top .ve-actions { display: flex; align-items: center; gap: 8px; min-width: 0; }
  #ve-top .ve-filename { color: #aaa; font-size: 12px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; padding-left: 8px; }
  .ve-back-btn { display: inline-flex; align-items: center; gap: 5px; background: #5b8af5; color: #fff; text-decoration: none; border-radius: 3px; padding: 5px 10px; font-size: 11px; font-weight: 600; white-space: nowrap; }
  .ve-back-btn:hover { background: #4a7ae8; color: #fff; }
  .ve-media-btn { display: inline-flex; align-items: center; gap: 5px; background: #333; color: #ccc; text-decoration: none; border-radius: 3px; padding: 5px 10px; font-size: 11px; white-space: nowrap; }
  .ve-media-btn:hover { background: #444; color: #ddd; }
  #ve-body { flex: 1; min-height: 0; position: relative; display: flex; }
  #ve-body > #root { flex: 1; min-width: 0; display: flex; flex-direction: column; }
  #ve-body .nle { height: 100% !important; max-height: 100vh !important; }

  /* Taller, narrower program monitor: cap the preview width, center it and
     free up vertical space by slimming the timeline and bottom inspector. */
  .nle-left { align-items: center !important; }
  .nle-monitor { width: 100% !important; max-width: 68vw !important; }
  .nle-transport, .nle-timeline, .nle-inspector { width: 100% !important; }
  .nle-timeline-inner { min-height: 96px !important; }
  .nle-track { height: 30px !important; }
  .nle-inspector { max-height: 30vh !important; }

  /* Keep the video preview fully inside the monitor: frame it with padding so
     it never sits flush against (or under) the Program Monitor bar, and stop
     the bar overlaying the top of the video when the preview is fullscreen. */
  .nle-stage { padding: 10px 12px !important; }
  .nle-monitor.fullscreen .nle-monitor-bar { position: static !important; flex-shrink: 0 !important; }

  /* Bottom inspector: each section (Clip, Audio, Crop & mirror, ...) becomes its
     own column, listed evenly side by side in 4 columns. The section wrappers
     (.ve-isc) are created by veGroupInspector() below so the React-rendered
     tree stays untouched; this CSS just lays the wrappers out as columns. */
  .nle-inspector-content { display: grid !important; grid-template-columns: repeat(4, minmax(0, 1fr)) !important; gap: 6px 16px !important; align-items: start !important; }
  .nle-inspector-content > .ve-isc { display: grid !important; align-content: start !important; gap: 6px !important; min-width: 0 !important; }
  .nle-inspector-content .ve-isc h4 { margin: 0 0 4px !important; }
  .nle-inspector-content .ve-isc .nle-row, .nle-inspector-content .ve-isc textarea { width: 100% !important; }

  /* More usable option panels: roomier bars, bigger controls, clearer text. */
  .nle-panel-bar { padding: 7px 12px !important; font-size: 12px !important; }
  .nle-panel-body { padding: 10px 12px !important; }
  .nle-right { background: #252526 !important; }
  .nle label { color: #b0b0b0 !important; font-size: 12px !important; }
  .nle input[type="text"], .nle input[type="number"], .nle textarea, .nle select { padding: 4px 8px !important; font-size: 12px !important; }
  .nle h4 { font-size: 12px !important; }
  .nle button { font-size: 12px !important; padding: 5px 11px !important; }
  .nle button.small { font-size: 11px !important; padding: 4px 9px !important; }
  @media (min-width: 901px) { .nle-body { grid-template-columns: 1fr 340px !important; } }

  /* Maximized preview: hide the side panel, timeline and bottom inspector. */
  #root.ve-max .nle-right { display: none !important; }
  #root.ve-max .nle-timeline { display: none !important; }
  #root.ve-max .nle-inspector { display: none !important; }
  #root.ve-max .nle-body { grid-template-columns: 1fr !important; }
  #ve-max-btn.active { background: #e8a840 !important; color: #1e1e1e !important; }
  #ve-thumb-btn.active { background: #e8a840 !important; color: #1e1e1e !important; }
  #ve-thumb-panel { position: absolute; top: 44px; right: 12px; width: 300px; background: #2a2a2a; border: 1px solid #444; border-radius: 6px; padding: 10px 12px; z-index: 100002; box-shadow: 0 8px 30px rgba(0,0,0,.5); color: #ccc; font-size: 12px; }
  #ve-thumb-panel h4 { margin: 0 0 8px; color: #ddd; font-size: 12px; text-transform: uppercase; letter-spacing: .5px; }
  #ve-thumb-panel .ve-thumb-form { display: flex; gap: 6px; align-items: center; margin: 6px 0; }
  #ve-thumb-panel label { color: #aaa; font-size: 11px; flex-shrink: 0; }
  #ve-thumb-panel input[type=number], #ve-thumb-panel input[type=file] { background: #1e1e1e; border: 1px solid #444; color: #ddd; border-radius: 3px; padding: 4px 6px; font-size: 11px; flex: 1; min-width: 0; }
  #ve-thumb-panel button { background: #5b8af5; color: #fff; border: none; border-radius: 3px; padding: 5px 9px; font-size: 11px; cursor: pointer; flex-shrink: 0; }
  #ve-thumb-panel button:hover { background: #4a7ae8; }
  #ve-thumb-panel button:disabled { opacity: .5; cursor: default; }
  #ve-thumb-status { min-height: 14px; margin-top: 6px; font-size: 11px; color: #8cc5ff; }
  #ve-thumb-status.error { color: #ff8f8f; }

  /* Blur brush: switch the editor's blur tool on from a button and paint blur
     regions straight onto the video with the mouse. */
  #ve-blur-btn.active { background: #a855f7 !important; color: #fff !important; }
  #ve-blur-hint { position: absolute; top: 44px; left: 50%; transform: translateX(-50%); background: rgba(168,85,247,.14); color: #d8b4fe; border: 1px solid rgba(168,85,247,.55); border-radius: 4px; padding: 4px 12px; font-size: 11px; letter-spacing: .3px; z-index: 100003; pointer-events: none; white-space: nowrap; }
  .ve-blur-paint { pointer-events: none !important; cursor: crosshair !important; }
  #ve-filter-btn.active { background: #a855f7 !important; color: #fff !important; }
  #ve-filter-hint { position: absolute; top: 44px; left: 50%; transform: translateX(-50%); background: rgba(168,85,247,.14); color: #d8b4fe; border: 1px solid rgba(168,85,247,.55); border-radius: 4px; padding: 4px 12px; font-size: 11px; letter-spacing: .3px; z-index: 100003; pointer-events: none; white-space: nowrap; }
  #ve-filter-panel { position: absolute; top: 44px; right: 12px; width: 280px; background: #2a2a2a; border: 1px solid #444; border-radius: 6px; padding: 10px 12px; z-index: 100002; box-shadow: 0 8px 30px rgba(0,0,0,.5); color: #ccc; font-size: 12px; }
  #ve-filter-panel h4 { margin: 0 0 8px; color: #ddd; font-size: 12px; text-transform: uppercase; letter-spacing: .5px; }
  .ve-filter-grid { display: flex; flex-direction: column; gap: 8px; }
  .ve-filter-row { display: flex; gap: 8px; align-items: center; }
  .ve-filter-row label { color: #aaa; font-size: 11px; flex-shrink: 0; min-width: 130px; }
  .ve-filter-row input[type=color] { width: 40px; height: 28px; border: 1px solid #444; border-radius: 3px; background: #1e1e1e; cursor: pointer; }
  .ve-filter-row input[type=color]::-webkit-color-swatch-wrapper { padding: 0; }
  .ve-filter-row input[type=color]::-webkit-color-swatch { border: none; border-radius: 2px; }
  #ve-filter-panel .btn { margin-top: 8px; width: 100%; }
</style>
</head>
<body>
<div id="ve-shell">
    <div id="ve-top">
        <div class="ve-actions">
            <a class="ve-back-btn" href="<?= url('/admin/video-projects') ?>">&larr; Back to Video Projects</a>
            <a class="ve-media-btn" href="<?= url($backGallery ? '/admin/galleries/' . $backGallery : '/admin/video-projects') ?>">Back to Gallery</a>
            <button id="ve-max-btn" class="ve-media-btn" type="button" onclick="toggleMaximize()">Maximize Preview</button>
            <button id="ve-thumb-btn" class="ve-media-btn" type="button" onclick="toggleThumb()">Thumbnail</button>
            <button id="ve-blur-btn" class="ve-media-btn" type="button" onclick="toggleBlur()">Blur brush</button>
            <button id="ve-filter-btn" class="ve-media-btn" type="button" onclick="toggleFilter()">Filter colors</button>
        </div>
        <div class="ve-filename"><?= e($photo['filename']) ?></div>
    </div>
    <div id="ve-body"><div id="root"></div></div>
</div>
<div id="ve-blur-hint" hidden>Drag on the video to paint a blur region. Click the button again (or press V) to stop.</div>
<div id="ve-filter-hint" hidden>Click on the color pickers to choose filter colors. Click Save to apply to all pages.</div>
<div id="ve-thumb-panel" hidden>
    <h4>Thumbnail tools</h4>
    <form class="ve-thumb-form" id="ve-thumb-upload" data-op="thumbnail" enctype="multipart/form-data">
        <input type="file" name="thumbnail" accept="image/*" required>
        <button type="submit">Upload</button>
    </form>
    <form class="ve-thumb-form" id="ve-thumb-frame" data-op="frame">
        <label>Current frame</label>
        <button type="submit">Capture</button>
    </form>
    <form class="ve-thumb-form" id="ve-thumb-regen" data-op="regen">
        <button type="submit">Regenerate</button>
    </form>
    <div id="ve-thumb-status"></div>
</div>

<div id="ve-filter-panel" hidden>
    <h4>Filter colors</h4>
    <form id="ve-filter-form" class="ve-filter-form">
        <div class="ve-filter-grid">
            <div class="ve-filter-row">
                <label>Active filter background</label>
                <input type="color" name="site-filter-bg" value="<?= e($project['project']['filter_bg'] ?? '#f472b6') ?>">
            </div>
            <div class="ve-filter-row">
                <label>Active filter text color</label>
                <input type="color" name="site-filter-color" value="<?= e($project['project']['filter_color'] ?? '#3b0764') ?>">
            </div>
            <div class="ve-filter-row">
                <label>Active filter border</label>
                <input type="color" name="site-filter-border" value="<?= e($project['project']['filter_border'] ?? '#db2777') ?>">
            </div>
            <div class="ve-filter-row">
                <label>Active filter hover</label>
                <input type="color" name="site-filter-hover-bg" value="<?= e($project['project']['filter_hover_bg'] ?? '#f052a0') ?>">
            </div>
            <div class="ve-filter-row">
                <label>Inactive filter background</label>
                <input type="color" name="site-filter-inactive-bg" value="<?= e($project['project']['filter_inactive_bg'] ?? '#e8e8e8') ?>">
            </div>
            <div class="ve-filter-row">
                <label>Inactive filter text color</label>
                <input type="color" name="site-filter-inactive-color" value="<?= e($project['project']['filter_inactive_color'] ?? '#999999') ?>">
            </div>
            <div class="ve-filter-row">
                <label>Inactive filter border</label>
                <input type="color" name="site-filter-inactive-border" value="<?= e($project['project']['filter_inactive_border'] ?? '#cccccc') ?>">
            </div>
            <div class="ve-filter-row">
                <label>Inactive filter hover</label>
                <input type="color" name="site-filter-inactive-hover" value="<?= e($project['project']['filter_inactive_hover'] ?? '#dddddd') ?>">
            </div>
        </div>
        <p><button type="button" class="btn" onclick="saveFilterColors()">Save filter colors</button></p>
    </form>
</div>
<script>
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
    'editUrl' => url('/admin/photos/' . (int) $photo['id'] . '/edit'),
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script>
function toggleMaximize() {
    var root = document.getElementById('root');
    var on = root.classList.toggle('ve-max');
    var btn = document.getElementById('ve-max-btn');
    btn.textContent = on ? 'Exit Maximize' : 'Maximize Preview';
    btn.classList.toggle('active', on);
}
function toggleThumb() {
    var p = document.getElementById('ve-thumb-panel');
    var b = document.getElementById('ve-thumb-btn');
    var show = p.hidden;
    p.hidden = !show;
    b.classList.toggle('active', show);
}
function toggleFilter() {
    var p = document.getElementById('ve-filter-panel');
    var b = document.getElementById('ve-filter-btn');
    var show = p.hidden;
    p.hidden = !show;
    b.classList.toggle('active', show);
}
var VE_CFG = window.__VIDEO_EDITOR__ || {};
function veStatus(msg, isErr) {
    var s = document.getElementById('ve-thumb-status');
    s.textContent = msg;
    s.className = isErr ? 'error' : '';
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
        var res = await fetch(VE_CFG.editUrl, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'fetch' } });
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
/* Post a thumbnail operation with a 30s ceiling so the status always settles
   instead of hanging on a stalled request. */
function vePost(fd) {
    return Promise.race([
        fetch(VE_CFG.editUrl, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'fetch' } }),
        new Promise(function (_, reject) {
            setTimeout(function () { reject(new Error('timeout')); }, 30000);
        })
    ]);
}
/* Read the frame currently shown in the program monitor. Waits for the video
   to actually have frame data (readyState >= 2) before drawing, and reports
   false if canvas capture is unavailable so the caller can fall back. */
function veReadMonitorFrame() {
    return new Promise(function (resolve) {
        var video = document.querySelector('.nle-stage video');
        if (!video) { resolve({ ok: false, err: 'No preview loaded' }); return; }
        var tries = 0;
        function attempt() {
            if (video.readyState >= 2 && video.videoWidth > 0) {
                try {
                    var w = video.videoWidth, h = video.videoHeight;
                    var c = document.createElement('canvas');
                    c.width = w;
                    c.height = h;
                    c.getContext('2d').drawImage(video, 0, 0, w, h);
                    resolve({ ok: true, dataUrl: c.toDataURL('image/jpeg', 0.92), method: 'canvas' });
                } catch (e) {
                    resolve({ ok: false, err: 'Canvas capture blocked' });
                }
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
    veStatus('Capturing current frame...');
    try {
        var shot = await veReadMonitorFrame();
        if (shot.ok) {
            var fd = new FormData();
            fd.set('_token', VE_CFG.token);
            fd.set('operation', 'frame');
            fd.set('frame_data', shot.dataUrl);
            var res = await vePost(fd);
            var data = {};
            try { data = await res.json(); } catch (e) {}
            if (res.ok && data.ok) { veStatus(data.message || 'Thumbnail captured'); return; }
            veStatus(data.error || 'Could not set thumbnail', true);
            return;
        }
        // Canvas capture unavailable (e.g. tainted canvas, preview not ready):
        // fall back to an ffmpeg frame grab at the playhead position.
        var video = document.querySelector('.nle-stage video');
        var second = video && isFinite(video.currentTime) ? Math.max(0, Math.floor(video.currentTime)) : 0;
        veStatus('Capturing at ' + second + 's (server)...');
        var fd2 = new FormData();
        fd2.set('_token', VE_CFG.token);
        fd2.set('operation', 'frame');
        fd2.set('second', String(second));
        var res2 = await vePost(fd2);
        var data2 = {};
        try { data2 = await res2.json(); } catch (e) {}
        if (res2.ok && data2.ok) { veStatus(data2.message || 'Thumbnail captured'); return; }
        veStatus(data2.error || 'Could not set thumbnail', true);
    } catch (e) {
        veStatus('Request failed: ' + (e && e.message ? e.message : 'network error'), true);
    } finally {
        btn.disabled = false;
    }
}
async function saveFilterColors() {
    var btn = document.querySelector('#ve-filter-panel button');
    btn.disabled = true;
    veStatus('Saving filter colors...');
    try {
        var form = document.getElementById('ve-filter-form');
        var fd = new FormData();
        fd.set('_token', VE_CFG.token);
        fd.set('title', VE_CFG.title);
        fd.set('version', String(VE_CFG.version));
        var project = VE_CFG.project || {};
        // Update filter colors in the project
        var filterInputs = document.getElementById('ve-filter-form').querySelectorAll('input[type="color"]');
        filterInputs.forEach(function(input) {
            var key = input.name.replace(/^site-/, '');
            var path = key.split('-');
            var obj = project;
            for (var i = 0; i < path.length - 1; i++) {
                if (!(path[i] in obj)) obj[path[i]] = {};
                obj = obj[path[i]];
            }
            obj[path[path.length - 1]] = input.value;
        });
        fd.set('project', JSON.stringify(project));
        fd.set('version', String(VE_CFG.version));
        var res = await fetch(VE_CFG.saveUrl, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'fetch' } });
        var data = await res.json();
        if (res.ok && data.ok) {
            veStatus('Filter colors saved');
        } else {
            veStatus(data.error || 'Save failed', true);
        }
    } catch (e) {
        veStatus('Request failed', true);
    } finally {
        btn.disabled = false;
    }
}
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

/* Group the inspector's sections into columns. The video editor renders the
   inspector as one flat list (h4 header, then that section's controls, then the
   next h4, ...). Wrap each group in a .ve-isc div so the CSS grid above can
   lay the sections out side by side. Runs whenever the app (re)renders. */
function veGroupInspector() {
    var content = document.querySelector('.nle-inspector-content');
    if (!content) return;
    if (content.querySelector(':scope > .ve-isc')) return;
    var nodes = [];
    while (content.firstChild) {
        var n = content.firstChild;
        content.removeChild(n);
        if (n.nodeType === 1) nodes.push(n);
    }
    var current = null;
    nodes.forEach(function (node) {
        if (node.tagName === 'H4') {
            current = document.createElement('div');
            current.className = 've-isc';
            current.appendChild(node);
            content.appendChild(current);
        } else if (current) {
            current.appendChild(node);
        } else {
            current = document.createElement('div');
            current.className = 've-isc';
            current.appendChild(node);
            content.appendChild(current);
        }
    });
}
var veInspObserver = new MutationObserver(function () {
    veGroupInspector();
});
veInspObserver.observe(document.body, { childList: true, subtree: true });
var veGroupTimer = setInterval(function () {
    if (document.querySelector('.nle-inspector-content')) {
        veGroupInspector();
        clearInterval(veGroupTimer);
    }
}, 300);

 /* Preserve free-form brush data when the bundle autosaves its rectangle
    region, and render saved strokes above the editor's rectangle overlays. */
 var veBrushRegions = [];
 var vePendingBrushes = [];
 var veOriginalFetch = window.fetch.bind(window);
 function veBrushMatch(a, b) {
     var acx = (a.x || 0) + (a.w || 0) / 2, acy = (a.y || 0) + (a.h || 0) / 2;
     var bcx = (b.x || 0) + (b.w || 0) / 2, bcy = (b.y || 0) + (b.h || 0) / 2;
     return Math.abs(acx - bcx) < .25 && Math.abs(acy - bcy) < .25 &&
         Math.abs((a.w || 0) - (b.w || 0)) < .25 && Math.abs((a.h || 0) - (b.h || 0)) < .25;
 }
 function veRenderBrushes() {
     var inner = document.querySelector('.nle-stage-inner') || document.querySelector('.nle-stage');
     if (!inner) return;
     var old = inner.querySelector('.ve-brush-svg');
     if (old) old.remove();
     var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
     svg.className.baseVal = 've-brush-svg';
     svg.setAttribute('viewBox', '0 0 1 1');
     svg.setAttribute('preserveAspectRatio', 'none');
     var brushBoxes = [];
     veBrushRegions.forEach(function (br) {
         if (!Array.isArray(br.points) || br.points.length < 2) return;
         brushBoxes.push(br);
         var path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
         path.setAttribute('class', 've-brush-path');
         path.setAttribute('d', br.points.map(function (p, i) { return (i ? 'L' : 'M') + ' ' + p[0] + ' ' + p[1]; }).join(' '));
         path.style.strokeWidth = String(Math.max(.01, (br.r || .05) * 2));
         svg.appendChild(path);
     });
     inner.appendChild(svg);
     document.querySelectorAll('.nle-blur-region').forEach(function (el) {
         var x = parseFloat(el.style.left) / 100, y = parseFloat(el.style.top) / 100;
         var w = parseFloat(el.style.width) / 100, h = parseFloat(el.style.height) / 100;
         el.style.visibility = brushBoxes.some(function (br) { return veBrushMatch(br, { x: x, y: y, w: w, h: h }); }) ? 'hidden' : '';
     });
 }
 function veCaptureBrushProject(project) {
     if (!project || !Array.isArray(project.blur_regions)) return;
     veBrushRegions = project.blur_regions.filter(function (br) { return Array.isArray(br.points) && br.points.length >= 2; });
     veRenderBrushes();
 }
 window.fetch = function (input, init) {
     var url = typeof input === 'string' ? input : (input && input.url) || '';
     if (url.indexOf(VE_CFG.saveUrl) !== -1 && init && init.body) {
         var params = typeof init.body === 'string' ? new URLSearchParams(init.body) : init.body;
         if (params && typeof params.get === 'function') {
             try {
                 var project = JSON.parse(params.get('project') || '{}');
                 if (Array.isArray(project.blur_regions)) {
                     var pendingList = vePendingBrushes.slice();
                     if (!pendingList.length && window.__veLastBrush) pendingList.push(window.__veLastBrush);
                     pendingList.forEach(function (pending) {
                         var region = project.blur_regions.find(function (br) { return veBrushMatch(br, pending); }) ||
                             (project.blur_regions.length === 1 ? project.blur_regions[0] : project.blur_regions.find(function (br) { return !Array.isArray(br.points); }));
                         if (!region) return;
                         region.points = pending.points;
                         region.r = pending.r;
                         vePendingBrushes.splice(vePendingBrushes.indexOf(pending), 1);
                         if (pending === window.__veLastBrush) window.__veLastBrush = null;
                     });
                     veBrushRegions.forEach(function (saved) {
                         var region = project.blur_regions.find(function (br) { return veBrushMatch(br, saved); });
                         if (region && !Array.isArray(region.points)) {
                             region.points = saved.points;
                             region.r = saved.r;
                         }
                     });
                     params.set('project', JSON.stringify(project));
                     init = Object.assign({}, init, { body: params });
                     veCaptureBrushProject(project);
                 }
             } catch (ignore) {}
         }
     }
     return veOriginalFetch(input, init);
 };
 veCaptureBrushProject((window.__VIDEO_EDITOR__ || {}).project || {});

 /* ---- Blur brush -----------------------------------------------------------
   The editor already ships a blur tool behind the "B" shortcut: press B, then
   click & drag on the video. That tool works, but (a) it is hidden behind the
   keyboard shortcut and (b) a single drag creates one region per pointermove
   instead of one clean region (the closure it reads is stale). So this block
   exposes a visible "Blur brush" button that switches the tool on, draws a
   live preview rectangle while the user drags, and then commits exactly ONE
   region per drag by replaying the editor's own pointerdown/move/up sequence
   with a single move. The real drag is swallowed first so the editor's buggy
   live-draw never runs. */
var veBlurActive = false;
var veBlurCommit = false;
 var vePaint = null; // { stage, rect, x1, y1, pid, el, points, path }

function veBlurSync() {
    var stage = document.querySelector('.nle-stage');
    var on = !!stage && stage.classList.contains('tool-blur');
    var btn = document.getElementById('ve-blur-btn');
    var hint = document.getElementById('ve-blur-hint');
    if (btn) btn.classList.toggle('active', on);
    if (hint) hint.hidden = !on;
    veBlurActive = on;
}
function toggleBlur() {
    if (veBlurActive) {
        window.dispatchEvent(new KeyboardEvent('keydown', { code: 'KeyV', key: 'v', bubbles: true, cancelable: true, view: window }));
    } else {
        window.dispatchEvent(new KeyboardEvent('keydown', { code: 'KeyB', key: 'b', bubbles: true, cancelable: true, view: window }));
    }
    setTimeout(veBlurSync, 150);
}
/* Keep the button in sync if the tool is switched by keyboard too. */
var veBlurStageObserver = new MutationObserver(function () {
    veBlurSync();
});
var veBlurBoot = setInterval(function () {
    var stage = document.querySelector('.nle-stage');
    if (stage) {
        veBlurStageObserver.observe(stage, { attributes: true, attributeFilter: ['class'] });
        veBlurSync();
        clearInterval(veBlurBoot);
    }
}, 300);
setInterval(function () {
    veBlurSync();
}, 400);

document.addEventListener('pointerdown', function (e) {
    if (!veBlurActive || veBlurCommit) return;
    if (!e.target || !e.target.closest) return;
    if (!e.target.closest('.nle-stage')) return;
    if (e.target.closest('.nle-blur-region')) return; // let region click/edit through
    e.stopPropagation(); // keep the editor's buggy live-draw away
    e.preventDefault();
    var stage = e.target.closest('.nle-stage');
    var inner = stage.querySelector('.nle-stage-inner') || stage;
    var rect = stage.getBoundingClientRect();
     var el = document.createElement('div');
    el.className = 'nle-blur-region ve-blur-paint';
    el.style.left = '0%';
    el.style.top = '0%';
    el.style.width = '0%';
    el.style.height = '0%';
     inner.appendChild(el);
     var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
     svg.className.baseVal = 've-brush-svg';
     svg.setAttribute('viewBox', '0 0 1 1');
     svg.setAttribute('preserveAspectRatio', 'none');
     var path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
     path.setAttribute('class', 've-brush-path');
     path.style.strokeWidth = '.1';
     svg.appendChild(path);
     inner.appendChild(svg);
     function point(x, y) { return [Math.max(0, Math.min(1, (x - rect.left) / rect.width)), Math.max(0, Math.min(1, (y - rect.top) / rect.height))]; }
     vePaint = { stage: stage, rect: rect, x1: e.clientX, y1: e.clientY, pid: Date.now(), el: el, svg: svg, path: path, points: [point(e.clientX, e.clientY)] };
}, true);

window.addEventListener('pointermove', function (e) {
    if (!vePaint) return;
    var r = vePaint.rect;
    var pt = [Math.max(0, Math.min(1, (e.clientX - r.left) / r.width)), Math.max(0, Math.min(1, (e.clientY - r.top) / r.height))];
    var last = vePaint.points[vePaint.points.length - 1];
    if (!last || Math.abs(pt[0] - last[0]) + Math.abs(pt[1] - last[1]) > .003) vePaint.points.push(pt);
    window.__veLastBrush = { points: vePaint.points.slice(), r: .05 };
    vePaint.path.setAttribute('d', vePaint.points.map(function (p, i) { return (i ? 'L' : 'M') + ' ' + p[0] + ' ' + p[1]; }).join(' '));
    var L = Math.min(vePaint.x1, e.clientX), T = Math.min(vePaint.y1, e.clientY);
    var W = Math.abs(e.clientX - vePaint.x1), H = Math.abs(e.clientY - vePaint.y1);
    vePaint.el.style.left = (L - r.left) / r.width * 100 + '%';
    vePaint.el.style.top = (T - r.top) / r.height * 100 + '%';
    vePaint.el.style.width = W / r.width * 100 + '%';
    vePaint.el.style.height = H / r.height * 100 + '%';
}, true);

window.addEventListener('pointerup', function (e) {
    if (!vePaint) return;
    var p = vePaint;
    vePaint = null;
    if (p.el && p.el.parentNode) p.el.parentNode.removeChild(p.el);
    if (p.svg && p.svg.parentNode) p.svg.parentNode.removeChild(p.svg);
    var r = p.rect;
    var finalPoint = [Math.max(0, Math.min(1, (e.clientX - r.left) / r.width)), Math.max(0, Math.min(1, (e.clientY - r.top) / r.height))];
    p.points.push(finalPoint);
    var xs = p.points.map(function (pt) { return pt[0]; }), ys = p.points.map(function (pt) { return pt[1]; });
    var minX = Math.min.apply(Math, xs), maxX = Math.max.apply(Math, xs), minY = Math.min.apply(Math, ys), maxY = Math.max.apply(Math, ys);
    var W = (maxX - minX) * r.width, H = (maxY - minY) * r.height;
    if (W < r.width * 0.01 || H < r.height * 0.01) return; // treat as a click
    vePendingBrushes.push({ x: minX, y: minY, w: maxX - minX, h: maxY - minY, points: p.points, r: .05 });
    /* Commit one clean region through the editor's own draw pipeline: replay
       pointerdown + a single pointermove + pointerup so the bundle pushes one
       region (start/end/strength come from the panel). */
    var pid = Date.now();
    veBlurCommit = true;
     p.stage.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true, cancelable: true, clientX: r.left + minX * r.width, clientY: r.top + minY * r.height, button: 0, buttons: 1, pointerId: pid, isPrimary: true, pointerType: 'mouse', view: window }));
     window.dispatchEvent(new PointerEvent('pointermove', { bubbles: true, cancelable: true, clientX: r.left + maxX * r.width, clientY: r.top + maxY * r.height, button: 0, buttons: 1, pointerId: pid, isPrimary: true, pointerType: 'mouse', view: window }));
     window.dispatchEvent(new PointerEvent('pointerup', { bubbles: true, cancelable: true, clientX: r.left + maxX * r.width, clientY: r.top + maxY * r.height, button: 0, buttons: 0, pointerId: pid, isPrimary: true, pointerType: 'mouse', view: window }));
    veBlurCommit = false;
}, true);
</script>
<script type="module" src="<?= url('/assets/video-editor/video-editor.js') ?>?v=<?= $jsVer ?>"></script>
</body>
</html>
