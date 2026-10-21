<?php $title = 'Live'; ?>

<?php
/* The next scheduled show, if the operator scheduled one. The banner is
   rendered server-side so it is there on the first paint, and the JS
   keeps the countdown ticking from the server's clock. */
$nextShow = LiveShow::toApi(\App\Models\LiveShow::next());
?>

<div class="live-page">
    <div class="live-top">
        <h1>Live</h1>
        <?php /* The badge changes to LIVE / PAUSED from JS. Without a live
                region a screen reader is never told the stream started. */ ?>
        <span class="live-badge off" id="live-badge" role="status" aria-live="polite" aria-atomic="true">Offline</span>
        <span class="muted" id="live-since"></span>
        <span class="muted" id="live-viewers" role="status" aria-live="polite"></span>
    </div>

    <?php if ($nextShow !== null): ?>
    <div class="live-countdown" id="live-countdown" role="status" aria-live="polite"
         data-starts-at="<?= e($nextShow['startsAtUtc']) ?>"
         data-server-now="<?= e(gmdate('Y-m-d\TH:i:s\Z')) ?>"
         data-title="<?= e($nextShow['title']) ?>">
        <div class="live-countdown-body">
            <span class="live-countdown-title" id="live-countdown-title"><?= e($nextShow['title']) ?></span>
            <span class="live-countdown-text" id="live-countdown-text"></span>
        </div>
    </div>
    <?php endif; ?>

    <div class="live-grid">
        <div class="live-player-wrap" data-player data-live data-fullscreen=".live-grid">
            <video id="live-video" controls autoplay muted playsinline></video>
            <div class="live-offline" id="live-offline">
                <p>The model is not live right now. Check back soon — a live show could start any moment.</p>
            </div>
            <div class="live-paused" id="live-paused" aria-hidden="true">
                <p>😴 The model will be back in a moment.</p>
            </div>
        </div>

        <div class="live-chat">
            <h2>Live chat <span class="muted" style="font-weight:400;">(everyone watching)</span></h2>
            <div class="live-chat-messages" id="live-chat-messages" role="log" aria-live="polite" aria-relevant="additions" aria-label="Live chat"></div>
            <form class="live-chat-form" id="live-chat-form">
                <?= csrf_field() ?>
                <input type="text" id="live-chat-input" maxlength="500" placeholder="Say something…" aria-label="Chat message" autocomplete="off">
                <button type="submit" class="btn btn-sm">Send</button>
            </form>
        </div>
    </div>
</div>

<script nonce="<?= csp_nonce() ?>" src="<?= url('/assets/js/hls.min.js?v=1') ?>"></script>
<script nonce="<?= csp_nonce() ?>">
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

    /* ---- the countdown to the next scheduled show ------------------------
       The start time comes from the server, and so does the server's own
       clock, so the number every viewer sees is the same on every device
       in every timezone. Only the ticking is local. */
    var countdownEl = document.getElementById('live-countdown');
    var countdownText = document.getElementById('live-countdown-text');
    var countdownTitle = document.getElementById('live-countdown-title');
    var countdownAt = null;
    var serverSkew = 0;

    function parseUtc(s) {
        var t = new Date(s);
        return isNaN(t.getTime()) ? null : t;
    }

    function fmtRemaining(sec) {
        sec = Math.max(0, Math.floor(sec));
        var d = Math.floor(sec / 86400);
        var h = Math.floor((sec % 86400) / 3600);
        var m = Math.floor((sec % 3600) / 60);
        var s = sec % 60;
        if (d > 0) return d + 'd ' + h + 'h ' + m + 'm';
        if (h > 0) return h + 'h ' + m + 'm ' + s + 's';
        return m + 'm ' + s + 's';
    }

    function tickCountdown() {
        if (!countdownEl || countdownAt === null) return;
        var now = Date.now() / 1000 + serverSkew;
        var left = countdownAt.getTime() / 1000 - now;

        if (left <= 0) {
            countdownEl.classList.remove('live-countdown-soon');
            countdownEl.classList.add('live-countdown-now');
            if (countdownText) {
                countdownText.textContent = countdownTitle && countdownTitle.textContent
                    ? countdownTitle.textContent + ' is starting now — hang tight.'
                    : 'Starting now…';
            }
            return;
        }

        if (countdownText) {
            countdownText.textContent = 'Live in ' + fmtRemaining(left);
        }
    }

    function setShow(show) {
        if (!show || !countdownEl) {
            if (countdownEl) { countdownEl.hidden = true; }
            return;
        }
        var at = parseUtc(show.startsAtUtc);
        if (!at) { countdownEl.hidden = true; return; }
        countdownAt = at;
        if (countdownTitle) { countdownTitle.textContent = show.title; }
        countdownEl.hidden = false;
        countdownEl.classList.remove('live-countdown-now');
        countdownEl.classList.add('live-countdown-soon');
        tickCountdown();
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

    function teardownHls() {
        if (hls) { try { hls.destroy(); } catch (e) {} hls = null; }
        video.removeAttribute('src');
        try { video.load(); } catch (e) {}
    }

    function teardownPlayer() {
        teardownHls();
        if (es) { try { es.close(); } catch (e) {} es = null; }
        currentKey = '';
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
        // Only the stream is replaced: the chat stream stays open.
        teardownHls();
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
                if (!data || !data.fatal) { return; }
                if (data.type === window.Hls.ErrorTypes.NETWORK_ERROR) {
                    h.startLoad();
                    // A missing playlist (the publisher stopped for a pause or
                    // a mute) eventually kills the loader: the player is then
                    // rebuilt so the live edge is picked up again when the
                    // stream comes back, instead of freezing until a reload.
                    if (data.response && data.response.code === 404) { scheduleRebuild(); }
                } else if (data.type === window.Hls.ErrorTypes.MEDIA_ERROR) {
                    h.recoverMediaError();
                }
            });
            hls = h;
            if (window.PlayerUI) window.PlayerUI.setLevels(h);
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

    /* The stream can be interrupted without the page knowing: the model
       pauses the broadcast or mutes the camera and the publisher stops, so
       the playlist stops updating and the picture freezes. The page now
       watches for that and rebuilds the player, so the feed comes back on
       its own instead of after a reload. */
    var rebuildTimer = null;
    var lastKey = '';
    var lastToken = '';
    var wasPaused = <?= json_encode((bool) ($paused ?? false)) ?>;
    var lastMediaTime = -1;
    var stallRounds = 0;

    function rebuildPlayer(key, token) {
        if (!key) { return; }
        // A counter makes the recovery visible while debugging.
        window.__liveRebuilds = (window.__liveRebuilds || 0) + 1;
        lastKey = key;
        lastToken = token || lastToken;
        buildPlayer(lastKey, lastToken);
        lastMediaTime = -1;
        stallRounds = 0;
    }

    function scheduleRebuild() {
        if (rebuildTimer) { return; }
        rebuildTimer = setTimeout(function () {
            rebuildTimer = null;
            if (currentKey) { rebuildPlayer(currentKey, lastToken); }
        }, 2000);
    }

    function setLiveUI(live, paused) {
        stateLive = live;
        if (badge) {
            badge.textContent = paused ? '⏸ PAUSED' : (live ? '● LIVE' : 'Offline');
            badge.className = 'live-badge ' + (live ? 'on' : 'off');
        }
        if (offline) offline.style.display = live ? 'none' : 'grid';
        if (pausedEl) pausedEl.style.display = (live && paused) ? 'grid' : 'none';
    }

    /* A live feed that no longer advances is a dead player (the publisher
       stopped for a pause or a mute). Three rounds of the poll without any
       progress mean it is stuck, and the player is rebuilt. */
    function watchStall(s) {
        if (!s.live || s.paused || !currentKey || video.paused) {
            lastMediaTime = -1;
            stallRounds = 0;
            return;
        }
        var t = video.currentTime || 0;
        if (lastMediaTime < 0 || t > lastMediaTime + 0.05) {
            lastMediaTime = t;
            stallRounds = 0;
            return;
        }
        stallRounds++;
        if (stallRounds >= 3) {
            stallRounds = 0;
            lastMediaTime = -1;
            rebuildPlayer(currentKey, s.token || lastToken);
        }
    }

    function applyState(s) {
        if (!s || s.ok === false) return;
        var wasLive = stateLive;
        var keyChanged = currentKey !== s.streamKey;
        var paused = !!s.paused;

        setLiveUI(s.live, paused);
        if (sinceEl && s.since) sinceEl.textContent = 'since ' + hm(s.since);
        if (viewersEl && s.viewers) viewersEl.textContent = s.viewers + ' watching';

        /* The countdown follows the server's clock, so every viewer sees
           the same remaining time. */
        if (s.serverTime) {
            var st = parseUtc(s.serverTime);
            if (st) serverSkew = st.getTime() / 1000 - Date.now() / 1000;
        }
        if (s.nextShow !== undefined) setShow(s.nextShow);
        tickCountdown();

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

        // The broadcast was paused and is running again: the stream was
        // interrupted, so the player is rebuilt to pick up the live edge.
        if (wasPaused && !paused) {
            rebuildPlayer(s.streamKey || currentKey, s.token || lastToken);
        }
        wasPaused = paused;
        if (s.token) { lastToken = s.token; }
        if (s.streamKey) { lastKey = s.streamKey; }

        watchStall(s);
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
        paused: <?= json_encode((bool) ($paused ?? false)) ?>,
        since: <?= json_encode((string) ($since ?? '')) ?>,
        viewers: <?= json_encode((int) ($viewers ?? 0)) ?>,
        streamKey: <?= json_encode((string) ($streamKey ?? ''), JSON_UNESCAPED_SLASHES) ?>,
        token: <?= json_encode((string) ($token ?? ''), JSON_UNESCAPED_SLASHES) ?>,
        chatLatest: <?= json_encode((int) ($chatLatest ?? 0)) ?>,
        chatMessages: <?= json_encode($chatMessages ?? [], JSON_UNESCAPED_SLASHES) ?>,
        nextShow: <?= json_encode($nextShow, JSON_UNESCAPED_SLASHES) ?>,
        serverTime: <?= json_encode(gmdate('Y-m-d\TH:i:s\Z')) ?>
    });

    /* The countdown ticks locally between the 5s polls; the number itself
       always comes from the server's clock. */
    setInterval(tickCountdown, 1000);

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