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
    .live-offline { display: grid; place-items: center; height: 100%; color: var(--text-muted, #888); text-align: center; padding: 2rem; }
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
        <?php if (!empty($live)): ?>
            <span class="live-badge on" id="live-badge">● LIVE</span>
        <?php else: ?>
            <span class="live-badge off" id="live-badge">Offline</span>
        <?php endif; ?>
        <span class="muted" id="live-since"><?= !empty($since) ? 'since ' . e(tzdate('H:i', (string) $since)) : '' ?></span>
        <span class="muted" id="live-viewers"></span>
    </div>

    <div class="live-grid">
        <div class="live-player-wrap">
            <?php if (!empty($live) && !empty($streamKey) && !empty($token)): ?>
                <video id="live-video" controls autoplay muted playsinline></video>
            <?php else: ?>
                <div class="live-offline">
                    <p>The model is not live right now. Check back soon — a live show could start any moment.</p>
                </div>
            <?php endif; ?>
        </div>

        <div class="live-chat">
            <h2>Live chat <span class="muted" style="font-weight:400;">(everyone watching)</span></h2>
            <div class="live-chat-messages" id="live-chat-messages">
                <?php if (!empty($chatMessages)): ?>
                    <?php foreach ($chatMessages as $cm): ?>
                        <div class="live-chat-msg <?= e((string) $cm['sender_role']) ?>">
                            <span class="who"><?= e((string) $cm['name']) ?></span>
                            <span class="when"><?= e(tzdate('H:i', (string) $cm['created_at'])) ?></span><br>
                            <?= e((string) $cm['message']) ?>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="live-chat-empty" id="live-chat-empty"><?= !empty($live) ? 'Say hello to everyone watching…' : 'Chat opens when the model goes live.' ?></div>
                <?php endif; ?>
            </div>
            <form class="live-chat-form" id="live-chat-form">
                <?= csrf_field() ?>
                <input type="text" id="live-chat-input" maxlength="500" placeholder="Say something…" <?= empty($live) ? 'disabled' : '' ?> autocomplete="off">
                <button type="submit" class="btn btn-sm" <?= empty($live) ? 'disabled' : '' ?>>Send</button>
            </form>
        </div>
    </div>
</div>

<?php if (!empty($live) && !empty($streamKey) && !empty($token)): ?>
<script src="https://cdn.jsdelivr.net/npm/hls.js@1"></script>
<?php endif; ?>
<script>
(function () {
    var chatMessages = document.getElementById('live-chat-messages');
    var chatEmpty = document.getElementById('live-chat-empty');
    var chatForm = document.getElementById('live-chat-form');
    var chatInput = document.getElementById('live-chat-input');
    var latestId = <?= (int) ($chatLatest ?? 0) ?>;
    var csrf = chatForm ? chatForm.querySelector('input[name="_token"]').value : '';

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function addMsg(m, prepend) {
        var div = document.createElement('div');
        div.className = 'live-chat-msg ' + esc(m.sender_role);
        div.innerHTML = '<span class="who">' + esc(m.name) + '</span>' +
            '<span class="when">' + esc(m.created_at || '') + '</span><br>' + esc(m.message);
        if (prepend) { chatMessages.appendChild(div); } else { chatMessages.appendChild(div); }
        chatMessages.scrollTop = chatMessages.scrollHeight;
        if (chatEmpty) chatEmpty.style.display = 'none';
    }

    <?php foreach ($chatMessages as $cm): ?>
        addMsg({ sender_role: <?= json_encode((string) $cm['sender_role']) ?>, name: <?= json_encode((string) $cm['name']) ?>, created_at: <?= json_encode(tzdate('H:i', (string) $cm['created_at'])) ?>, message: <?= json_encode((string) $cm['message']) ?> }, true);
    <?php endforeach; ?>

    if (chatForm) {
        chatForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var text = chatInput.value.trim();
            if (!text) return;
            var body = new FormData();
            body.append('_token', csrf);
            body.append('message', text);
            chatInput.value = '';
            fetch('<?= url('/live/chat/send') ?>', { method: 'POST', body: body })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res.ok) { chatInput.value = text; alert(res.error || 'Could not send.'); }
                })
                .catch(function () { chatInput.value = text; });
        });
    }

    // Live chat SSE.
    var es = new EventSource('<?= url('/live/chat/stream') ?>?since=' + latestId);
    es.onmessage = function (e) {
        try {
            var data = JSON.parse(e.data);
            if (data && data.messages) {
                data.messages.forEach(function (m) { addMsg(m); });
                latestId = data.latestId || latestId;
            }
        } catch (err) { /* ignore */ }
    };

    // Video player + live status polling.
    var video = document.getElementById('live-video');
    var streamKey = <?= json_encode((string) ($streamKey ?? ''), JSON_UNESCAPED_SLASHES) ?>;
    var token = <?= json_encode((string) ($token ?? ''), JSON_UNESCAPED_SLASHES) ?>;

    if (video && streamKey && token) {
        var base = '<?= url('/live/hls') ?>/' + encodeURIComponent(streamKey) + '/index.m3u8';
        function signed(url) { return url + (url.indexOf('?') >= 0 ? '&' : '?') + 't=' + encodeURIComponent(token); }
        if (window.Hls && window.Hls.isSupported()) {
            var hls = new window.Hls({ lowLatencyMode: true, xhrSetup: function (xhr, url) { xhr.open('GET', signed(url), true); } });
            hls.loadSource(base);
            hls.attachMedia(video);
            hls.on(window.Hls.Events.ERROR, function (evt, data) {
                if (data && data.fatal) {
                    if (data.type === window.Hls.ErrorTypes.NETWORK_ERROR) hls.startLoad();
                    else if (data.type === window.Hls.ErrorTypes.MEDIA_ERROR) hls.recoverMediaError();
                }
            });
        } else if (video.canPlayType('application/vnd.apple.mpegurl')) {
            video.src = signed(base);
        }
    }

    setInterval(function () {
        fetch('<?= url('/live/status') ?>', { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.ok) return;
                var badge = document.getElementById('live-badge');
                if (d.live) { badge.textContent = '● LIVE'; badge.className = 'live-badge on'; }
                else { badge.textContent = 'Offline'; badge.className = 'live-badge off'; }
                var v = document.getElementById('live-viewers');
                if (v && d.viewers) v.textContent = d.viewers + ' watching';
            })
            .catch(function () {});
    }, 15000);
})();
</script>