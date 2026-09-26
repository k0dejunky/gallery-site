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
        </div>

        <div class="live-chat">
            <h2>Live chat <span class="muted" style="font-weight:400;">(everyone watching)</span></h2>
            <div class="live-chat-messages" id="live-chat-messages"></div>
            <form class="live-chat-form" id="live-chat-form">
                <?= csrf_field() ?>
                <input type="text" id="live-chat-input" maxlength="500" placeholder="Say something…" disabled autocomplete="off">
                <button type="submit" class="btn btn-sm" disabled>Send</button>
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
    var sendBtn = chatForm ? chatForm.querySelector('button[type=submit]') : null;
    var csrf = chatForm ? chatForm.querySelector('input[name="_token"]').value : '';

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
    }

    function buildPlayer(key, token) {
        teardownPlayer();
        currentKey = key;
        function signed(u) { return u + (u.indexOf('?') >= 0 ? '&' : '?') + 't=' + encodeURIComponent(token); }

        if (window.Hls && window.Hls.isSupported()) {
            var h = new window.Hls({
                lowLatencyMode: true,
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

    function setLiveUI(live) {
        stateLive = live;
        if (badge) { badge.textContent = live ? '● LIVE' : 'Offline'; badge.className = 'live-badge ' + (live ? 'on' : 'off'); }
        if (offline) offline.style.display = live ? 'none' : 'grid';
        if (chatInput) chatInput.disabled = !live;
        if (sendBtn) sendBtn.disabled = !live;
    }

    function applyState(s) {
        if (!s || s.ok === false) return;
        var wasLive = stateLive;
        var keyChanged = currentKey !== s.streamKey;

        setLiveUI(s.live);
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