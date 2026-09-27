<?php $title = 'Live'; ?>

<style>
    .live-page { width: 100%; max-width: none; }
    .live-top { display: flex; align-items: center; gap: .6rem; margin-bottom: .75rem; }
    .live-top h1 { margin: 0; }
    .live-badge { display: inline-block; padding: .15rem .6rem; border-radius: 999px; font-size: .78rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; }
    .live-badge.on { background: #dc2626; color: #fff; }
    .live-badge.off { background: var(--card-border, #ddd); color: var(--text-muted, #888); }
    .live-grid { display: grid; grid-template-columns: 1fr 320px; gap: 1rem; align-items: start; }
    @media (max-width: 860px) { .live-grid { grid-template-columns: 1fr; } }
    .live-player-wrap { position: relative; width: 100%; aspect-ratio: 16/9; background: #000; border-radius: var(--card-radius, 8px); overflow: hidden; }
    .live-player-wrap video { width: 100%; height: 100%; object-fit: contain; background: #000; }
    .live-offline { position: absolute; inset: 0; display: grid; place-items: center; color: var(--text-muted, #888); text-align: center; padding: 2rem; }
    .live-paused { position: absolute; inset: 0; display: none; place-items: center; background: rgba(0,0,0,.55); color: #fff; text-align: center; padding: 2rem; font-size: 1.05rem; backdrop-filter: blur(2px); z-index: 2; }
    .live-controls { position: absolute; top: .5rem; left: .5rem; display: flex; gap: .4rem; z-index: 3; }
    .live-controls button { background: rgba(0,0,0,.55); color: #fff; border: 0; border-radius: 6px; padding: .3rem .55rem; cursor: pointer; font-size: 1.05rem; line-height: 1; }
    .live-controls button:hover { background: rgba(0,0,0,.8); }
    .live-grid:fullscreen { background: #000; position: relative; }
    .live-grid:fullscreen .live-player-wrap {
        width: 100% !important;
        height: 100% !important;
        aspect-ratio: auto;
        max-height: none;
        margin: 0;
        border-radius: 0;
    }
    .live-grid:fullscreen .live-chat {
        position: absolute;
        top: 0;
        right: 0;
        bottom: 0;
        width: 300px;
        max-width: 70vw;
        max-height: none;
        border: 0;
        background: rgba(0, 0, 0, .35);
        backdrop-filter: blur(3px);
        color: #fff;
    }
    .live-grid:fullscreen .live-chat h2 { color: #fff; border-bottom-color: rgba(255,255,255,.25); }
    .live-grid:fullscreen .live-chat-msg .who { color: #ffd0e8; }
    .live-grid:fullscreen .live-chat-msg .when { color: rgba(255,255,255,.65); }
    .live-grid:fullscreen .live-chat-form { border-top-color: rgba(255,255,255,.25); }
    @media (max-width: 860px) {
        .live-grid:fullscreen .live-chat {
            left: 0;
            right: 0;
            top: auto;
            bottom: 0;
            width: 100%;
            max-width: none;
            height: 38vh;
            backdrop-filter: blur(4px);
        }
    }
    .live-chat { display: flex; flex-direction: column; height: 100%; max-height: 60vh; border: 1px solid var(--card-border, #ddd); border-radius: var(--card-radius, 8px); background: var(--card-bg, #fff); overflow: hidden; }
    .live-chat h2 { margin: 0; padding: .6rem .8rem; font-size: .95rem; border-bottom: 1px solid var(--card-border, #eee); }
    .live-chat-messages { flex: 1; overflow-y: auto; padding: .6rem .8rem; display: flex; flex-direction: column; gap: .35rem; }
    .live-chat-msg { font-size: .9rem; line-height: 1.35; word-wrap: break-word; }
    .live-chat-msg .who { font-weight: 700; color: var(--purple-700, #6b21a8); }
    .live-chat-msg.operator .who { color: #dc2626; }
    .live-chat-msg .when { color: var(--text-muted, #999); font-size: .72rem; margin-left: .3rem; }
    .live-chat-form { display: flex; gap: .4rem; padding: .6rem .8rem; border-top: 1px solid var(--card-border, #eee); }
    .live-chat-form input { flex: 1; }
    .live-chat-empty { color: var(--text-muted, #999); font-size: .85rem; text-align: center; padding: 1rem; }
</style>

<div class="live-page">
    <div class="live-top">
        <h1>Live</h1>
        <span class="live-badge off" id="live-badge">Offline</span>
        <span class="muted" id="live-since"></span>
        <span class="muted" id="live-viewers"></span>
    </div>

    <div class="live-grid">
        <div class="live-player-wrap">
            <video id="live-video" controls autoplay muted playsinline></video>
            <div class="live-offline" id="live-offline">
                <p>The model is not live right now. Check back soon — a live show could start any moment.</p>
            </div>
            <div class="live-paused" id="live-paused">
                <p>😴 The model will be back in a moment.</p>
            </div>
            <div class="live-controls" id="live-controls" hidden>
                <button type="button" id="live-mute" title="Mute / unmute audio" aria-label="Mute or unmute">🔇</button>
                <button type="button" id="live-fs" title="Full screen" aria-label="Toggle full screen">⛶</button>
            </div>
        </div>

        <div class="live-chat">
            <h2>Live chat <span class="muted" style="font-weight:400;">(everyone watching)</span></h2>
            <div class="live-chat-messages" id="live-chat-messages"></div>
            <form class="live-chat-form" id="live-chat-form">
                <?= csrf_field() ?>
                <input type="text" id="live-chat-input" maxlength="500" placeholder="Say something…" autocomplete="off">
                <button type="submit" class="btn btn-sm">Send</button>
            </form>
        </div>
    </div>
</div>

<script src="<?= url('/assets/js/hls.min.js') ?>"></script>
<script>
(function () {
    var video = document.getElementById('live-video');
    var offline = document.getElementById('live-offline');
    var badge = document.getElementById('live-badge');
    var sinceEl = document.getElementById('live-since');
    var viewersEl = document.getElementById('live-viewers');
    var chatMessages = document.getElementById('live-chat-messages');
    var chatForm = document.getElementById('live-chat-form');
    var chatInput = document.getElementById('live-chat-input');
    var csrf = chatForm ? chatForm.querySelector('input[name="_token"]').value : '';

    var controlsEl = document.getElementById('live-controls');
    var muteBtn = document.getElementById('live-mute');
    var fsBtn = document.getElementById('live-fs');

    // Mute / unmute the live audio.
    if (muteBtn && video) {
        muteBtn.addEventListener('click', function () {
            video.muted = !video.muted;
            muteBtn.textContent = video.muted ? '🔇' : '🔊';
        });
        video.addEventListener('volumechange', function () {
            if (muteBtn) muteBtn.textContent = video.muted ? '🔇' : '🔊';
        });
    }

    // Full screen the video + chat together.
    if (fsBtn && video) {
        function isFs() {
            return !!(document.fullscreenElement || document.webkitFullscreenElement);
        }
        function toggleFs() {
            var target = document.querySelector('.live-grid');
            if (isFs()) {
                var fn = document.exitFullscreen || document.webkitExitFullscreen;
                if (fn) fn.call(document);
            } else if (target) {
                var req = target.requestFullscreen || target.webkitRequestFullscreen;
                if (req) {
                    req.call(target);
                } else if (video.webkitEnterFullscreen) {
                    video.webkitEnterFullscreen();
                }
            }
            if (fsBtn) fsBtn.textContent = isFs() ? '✕' : '⛶';
        }
        fsBtn.addEventListener('click', toggleFs);
        document.addEventListener('fullscreenchange', function () {
            if (fsBtn) fsBtn.textContent = isFs() ? '✕' : '⛶';
        });
        document.addEventListener('webkitfullscreenchange', function () {
            if (fsBtn) fsBtn.textContent = isFs() ? '✕' : '⛶';
        });
    }

    var hls = null;
    var es = null;
    var latestId = 0;
    var currentKey = '';
    var stateLive = false;

    var hlsBase = <?= json_encode(url('/live/hls'), JSON_UNESCAPED_SLASHES) ?>;
    var stateUrl = <?= json_encode(url('/live/state'), JSON_UNESCAPED_SLASHES) ?>;
    var sseUrl = <?= json_encode(url('/live/chat/stream'), JSON_UNESCAPED_SLASHES) ?>;
    var sendUrl = <?= json_encode(url('/live/chat/send'), JSON_UNESCAPED_SLASHES) ?>;

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function hm(s) {
        var t = new Date(String(s == null ? '' : s).replace(' ', 'T') + 'Z');
        if (isNaN(t)) return '';
        return t.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }

    function addMsg(m) {
        if (m.id && m.id <= latestId) return;
        if (m.id) latestId = m.id;
        var div = document.createElement('div');
        div.className = 'live-chat-msg ' + esc(m.sender_role);
        div.innerHTML = '<span class="who">' + esc(m.name) + '</span>' +
            '<span class="when">' + esc(hm(m.created_at)) + '</span><br>' + esc(m.message);
        chatMessages.appendChild(div);
        chatMessages.scrollTop = chatMessages.scrollHeight;
    }

    function resetChat(initial) {
        chatMessages.innerHTML = '';
        latestId = 0;
        (initial || []).forEach(addMsg);
    }

    function teardownPlayer() {
        if (hls) { try { hls.destroy(); } catch (e) {} hls = null; }
        if (es) { try { es.close(); } catch (e) {} es = null; }
        currentKey = '';
        video.removeAttribute('src');
        try { video.load(); } catch (e) {}
        resetPlayerSize();
    }

    var playerWrap = null;
    function playerBox() {
        playerWrap = playerWrap || video.closest('.live-player-wrap');
        return playerWrap;
    }

    /** Reset the box to the default full-width 16:9 while not streaming. */
    function resetPlayerSize() {
        var wrap = playerBox();
        if (!wrap) return;
        wrap.style.width = '';
        wrap.style.height = '';
        wrap.style.marginLeft = '';
        wrap.style.marginRight = '';
    }

    /**
     * Size the player box so the video is fully visible in the viewport in
     * either orientation. Landscape takes the full column width (height follows
     * the 16:9 aspect); portrait is capped to the viewport height so it fits on
     * the screen. The box always matches the video's exact aspect ratio, so
     * there are no letterbox bars inside it.
     */
    function fitPlayer() {
        var wrap = playerBox();
        if (!wrap || !video.videoWidth || !video.videoHeight) return;
        var ar = video.videoWidth / video.videoHeight;
        // Measure the player's OWN grid column (its natural width), not the
        // whole grid - sizing it to the full grid would cover the chat panel.
        var prevW = wrap.style.width;
        var prevH = wrap.style.height;
        wrap.style.width = '';
        wrap.style.height = '';
        var column = wrap.offsetWidth || window.innerWidth;
        wrap.style.width = prevW;
        wrap.style.height = prevH;

        // Fit the video within the column width and the viewport height,
        // preserving its aspect: a wide (landscape) stream that fits
        // vertically takes the full column width; square/portrait streams are
        // capped to the viewport height so they stay fully visible.
        var maxH = Math.max(220, window.innerHeight - 150);
        var w = column;
        var h = w / ar;
        if (h > maxH) {
            h = maxH;
            w = Math.round(h * ar);
        }
        wrap.style.width = Math.round(w) + 'px';
        wrap.style.height = Math.round(h) + 'px';
        wrap.style.marginLeft = 'auto';
        wrap.style.marginRight = 'auto';
    }

    function buildPlayer(key, token) {
        teardownPlayer();
        currentKey = key;
        function signed(u) { return u + (u.indexOf('?') >= 0 ? '&' : '?') + 't=' + encodeURIComponent(token); }

        video.addEventListener('loadedmetadata', function onMeta() {
            fitPlayer();
            video.removeEventListener('loadedmetadata', onMeta);
        });
        if (!window.__liveResizeBound) {
            window.__liveResizeBound = true;
            window.addEventListener('resize', function () {
                if (video.videoWidth > 0 && currentKey) fitPlayer();
            });
        }

        if (window.Hls && window.Hls.isSupported()) {
            var h = new window.Hls({
                // MediaMTX serves MPEG-TS HLS here (needed for recordings);
                // lowLatencyMode is for fMP4 LL-HLS and causes stalls on TS, so
                // keep a small live buffer and always chase the live edge.
                lowLatencyMode: false,
                liveSyncDurationCount: 2,
                liveMaxLatencyDurationCount: 4,
                maxLiveSyncPlaybackRate: 1.5,
                maxBufferLength: 20,
                maxMaxBufferLength: 40,
                backBufferLength: 10,
                xhrSetup: function (xhr, url) { xhr.open('GET', signed(url), true); }
            });
            h.loadSource(hlsBase + '/' + encodeURIComponent(key) + '/index.m3u8');
            h.attachMedia(video);
            h.on(window.Hls.Events.ERROR, function (evt, data) {
                if (data && data.fatal) {
                    if (data.type === window.Hls.ErrorTypes.NETWORK_ERROR) h.startLoad();
                    else if (data.type === window.Hls.ErrorTypes.MEDIA_ERROR) h.recoverMediaError();
                }
            });
            hls = h;
        } else if (video.canPlayType('application/vnd.apple.mpegurl')) {
            video.src = signed(hlsBase + '/' + encodeURIComponent(key) + '/index.m3u8');
        }
    }

    function connectSse(since) {
        if (es) { try { es.close(); } catch (e) {} }
        es = new EventSource(sseUrl + '?since=' + since);
        es.onmessage = function (e) {
            try {
                var d = JSON.parse(e.data);
                if (d && d.messages) d.messages.forEach(addMsg);
            } catch (err) {}
        };
    }

    var pausedEl = document.getElementById('live-paused');

    function setLiveUI(live, paused) {
        stateLive = live;
        if (badge) {
            badge.textContent = paused ? '⏸ PAUSED' : (live ? '● LIVE' : 'Offline');
            badge.className = 'live-badge ' + (live ? 'on' : 'off');
        }
        if (offline) offline.style.display = live ? 'none' : 'grid';
        if (pausedEl) pausedEl.style.display = (live && paused) ? 'grid' : 'none';
        if (controlsEl) controlsEl.hidden = !live;
        if (muteBtn && live) muteBtn.textContent = video.muted ? '🔇' : '🔊';
    }

    function applyState(s) {
        if (!s || s.ok === false) return;
        var wasLive = stateLive;
        var keyChanged = currentKey !== s.streamKey;
        var paused = !!s.paused;

        setLiveUI(s.live, paused);
        if (sinceEl && s.since) sinceEl.textContent = 'since ' + hm(s.since);
        if (viewersEl && s.viewers) viewersEl.textContent = s.viewers + ' watching';

        if (!s.live) {
            teardownPlayer();
            resetChat([]);
            return;
        }

        // Live: (re)build the player + chat when the broadcast starts or the
        // stream key changes; otherwise just catch up anything SSE missed.
        if (!wasLive || keyChanged) {
            resetChat(s.chatMessages || []);
            buildPlayer(s.streamKey || '', s.token || '');
            connectSse(latestId);
        } else {
            (s.chatMessages || []).forEach(function (m) { if (m.id > latestId) addMsg(m); });
        }
    }

    if (chatForm) {
        chatForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var text = chatInput.value.trim();
            if (!text) return;
            var body = new FormData();
            body.append('_token', csrf);
            body.append('message', text);
            chatInput.value = '';
            fetch(sendUrl, { method: 'POST', body: body })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res.ok) { chatInput.value = text; alert(res.error || 'Could not send.'); }
                })
                .catch(function () { chatInput.value = text; });
        });
    }

    applyState({
        live: <?= json_encode((bool) $live) ?>,
        since: <?= json_encode((string) ($since ?? '')) ?>,
        viewers: <?= json_encode((int) ($viewers ?? 0)) ?>,
        streamKey: <?= json_encode((string) ($streamKey ?? ''), JSON_UNESCAPED_SLASHES) ?>,
        token: <?= json_encode((string) ($token ?? ''), JSON_UNESCAPED_SLASHES) ?>,
        chatLatest: <?= json_encode((int) ($chatLatest ?? 0)) ?>,
        chatMessages: <?= json_encode($chatMessages ?? [], JSON_UNESCAPED_SLASHES) ?>
    });

    function poll() {
        fetch(stateUrl, { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(applyState)
            .catch(function () {});
    }
    setInterval(poll, 5000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(); });
})();
</script>