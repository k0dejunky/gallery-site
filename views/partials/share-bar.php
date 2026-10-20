<?php
// Social share row. Every outbound link carries the signed attribution
// (?c=&s=) for its share-* traffic code, so shares land in /admin/traffic.
// Expects $sharePath (site route, e.g. /galleries/5) and optional $shareTitle.
$sharePath     = (string) ($sharePath ?? '');
$shareTitle    = trim((string) ($shareTitle ?? ''));
$shareCanonical = absolute_url($sharePath);
$shareX        = \App\Models\Traffic::buildUrl($sharePath, 'share-x');
$shareWa       = \App\Models\Traffic::buildUrl($sharePath, 'share-wa');
$shareReddit   = \App\Models\Traffic::buildUrl($sharePath, 'share-reddit');
$shareWaText   = ($shareTitle !== '' ? $shareTitle . ' — ' : '') . $shareWa;
?>
<div class="share-bar" style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;margin:1rem 0;">
    <span class="muted u-fs-085">Share:</span>
    <a class="btn btn-sm btn-outline" target="_blank" rel="noopener"
       href="https://twitter.com/intent/tweet?url=<?= e(rawurlencode($shareX)) ?><?= $shareTitle !== '' ? '&text=' . e(rawurlencode($shareTitle)) : '' ?>">X</a>
    <a class="btn btn-sm btn-outline" target="_blank" rel="noopener"
       href="https://wa.me/?text=<?= e(rawurlencode($shareWaText)) ?>">WhatsApp</a>
    <a class="btn btn-sm btn-outline" target="_blank" rel="noopener"
       href="https://www.reddit.com/submit?url=<?= e(rawurlencode($shareReddit)) ?><?= $shareTitle !== '' ? '&title=' . e(rawurlencode($shareTitle)) : '' ?>">Reddit</a>
    <button type="button" class="btn btn-sm btn-outline" data-share-copy="<?= e($shareCanonical) ?>">Copy link</button>
</div>
<script nonce="<?= csp_nonce() ?>">
(function () {
    document.querySelectorAll('[data-share-copy]').forEach(function (b) {
        if (b._shareBound) { return; }
        b._shareBound = 1;
        b.addEventListener('click', function () {
            var text = b.getAttribute('data-share-copy');
            // The shell publishes __copyText, which uses the Clipboard API
            // in a secure context and falls back to execCommand on a plain
            // http setup. The Clipboard API does not EXIST outside a secure
            // context, so the old code here did nothing at all on http://
            // and said nothing either - the button just sat there.
            var say = function (ok) {
                if (window.__copied) { window.__copied(b, ok); return; }
                var original = b.textContent;
                b.textContent = ok ? 'Copied' : 'Copy failed';
                setTimeout(function () { b.textContent = original; }, 1500);
            };
            if (window.__copyText) {
                window.__copyText(text).then(function () { say(true); }, function () { say(false); });
                return;
            }
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(function () { say(true); }, function () { say(false); });
            }
        });
    });
})();
</script>
