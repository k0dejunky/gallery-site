<?php $title = 'Live'; ?>

<style>
    .live-page { width: 100%; max-width: none; }
    .live-player-wrap { position: relative; width: 100%; aspect-ratio: 16/9; background: #000; border-radius: var(--card-radius, 8px); overflow: hidden; }
    .live-player-wrap video { width: 100%; height: 100%; object-fit: contain; background: #000; }
    .live-badge { display: inline-block; padding: .15rem .6rem; border-radius: 999px; font-size: .78rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; }
    .live-badge.on { background: #dc2626; color: #fff; }
    .live-badge.off { background: var(--card-border, #ddd); color: var(--text-muted, #888); }
    .live-offline { display: grid; place-items: center; height: 100%; color: var(--text-muted, #888); text-align: center; padding: 2rem; }
</style>

<div class="live-page">
    <div style="display:flex;align-items:center;gap:.6rem;margin-bottom:.75rem;">
        <h1 style="margin:0;">Live</h1>
        <?php if (!empty($live)): ?>
            <span class="live-badge on" id="live-badge">● LIVE</span>
        <?php else: ?>
            <span class="live-badge off" id="live-badge">Offline</span>
        <?php endif; ?>
        <span class="muted" id="live-since"><?= !empty($since) ? 'since ' . e(tzdate('H:i', (string) $since)) : '' ?></span>
        <span class="muted" id="live-viewers"></span>
    </div>

    <div class="live-player-wrap">
        <?php if (!empty($live) && !empty($streamKey) && !empty($token)): ?>
            <video id="live-video" controls autoplay muted playsinline></video>
        <?php else: ?>
            <div class="live-offline">
                <p>The model is not live right now. Check back soon — a live show could start any moment.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($live) && !empty($streamKey) && !empty($token)): ?>
<script src="https://cdn.jsdelivr.net/npm/hls.js@1"></script>
<script>
(function () {
    var video = document.getElementById('live-video');
    var streamKey = <?= json_encode((string) $streamKey, JSON_UNESCAPED_SLASHES) ?>;
    var token = <?= json_encode((string) $token, JSON_UNESCAPED_SLASHES) ?>;
    var base = '<?= url('/live/hls') ?>/' + encodeURIComponent(streamKey) + '/index.m3u8';

    function signed(url) {
        return url + (url.indexOf('?') >= 0 ? '&' : '?') + 't=' + encodeURIComponent(token);
    }

    if (window.Hls && window.Hls.isSupported()) {
        var hls = new window.Hls({
            lowLatencyMode: true,
            xhrSetup: function (xhr, url) {
                xhr.open('GET', signed(url), true);
            }
        });
        hls.loadSource(base);
        hls.attachMedia(video);
        hls.on(window.Hls.Events.ERROR, function (evt, data) {
            if (data && data.fatal) {
                if (data.type === window.Hls.ErrorTypes.NETWORK_ERROR) {
                    hls.startLoad();
                } else if (data.type === window.Hls.ErrorTypes.MEDIA_ERROR) {
                    hls.recoverMediaError();
                }
            }
        });
    } else if (video.canPlayType('application/vnd.apple.mpegurl')) {
        // Safari native HLS.
        video.src = signed(base);
    }

    // Live/offline polling.
    setInterval(function () {
        fetch('<?= url('/live/status') ?>', { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.ok) return;
                var badge = document.getElementById('live-badge');
                if (d.live) {
                    badge.textContent = '● LIVE';
                    badge.className = 'live-badge on';
                } else {
                    badge.textContent = 'Offline';
                    badge.className = 'live-badge off';
                }
                var v = document.getElementById('live-viewers');
                if (v && d.viewers) v.textContent = d.viewers + ' watching';
            })
            .catch(function () {});
    }, 15000);
})();
</script>
<?php endif; ?>