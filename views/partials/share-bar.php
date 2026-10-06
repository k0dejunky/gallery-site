<?php
// Social share row. Every outbound link carries the signed attribution
// (?c=&s=) for its share-* traffic code, so shares land in /admin/traffic.
// Expects $sharePath (site route, e.g. /galleries/5) and optional $shareTitle.
$sharePath     = (string) ($sharePath ?? '');
$shareTitle    = trim((string) ($shareTitle ?? ''));
$shareCanonical = absolute_url($sharePath);
$shareX        = \App\Models\Traffic::buildUrl($sharePath, 'share-x');
$shareFb       = \App\Models\Traffic::buildUrl($sharePath, 'share-fb');
$shareWa       = \App\Models\Traffic::buildUrl($sharePath, 'share-wa');
$shareReddit   = \App\Models\Traffic::buildUrl($sharePath, 'share-reddit');
$shareWaText   = ($shareTitle !== '' ? $shareTitle . ' — ' : '') . $shareWa;
?>
<div class="share-bar" style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;margin:1rem 0;">
    <span class="muted" style="font-size:.85rem;">Share:</span>
    <a class="btn btn-sm btn-outline" target="_blank" rel="noopener"
       href="https://twitter.com/intent/tweet?url=<?= e(rawurlencode($shareX)) ?><?= $shareTitle !== '' ? '&text=' . e(rawurlencode($shareTitle)) : '' ?>">X</a>
    <a class="btn btn-sm btn-outline" target="_blank" rel="noopener"
       href="https://www.facebook.com/sharer/sharer.php?u=<?= e(rawurlencode($shareFb)) ?>">Facebook</a>
    <a class="btn btn-sm btn-outline" target="_blank" rel="noopener"
       href="https://wa.me/?text=<?= e(rawurlencode($shareWaText)) ?>">WhatsApp</a>
    <a class="btn btn-sm btn-outline" target="_blank" rel="noopener"
       href="https://www.reddit.com/submit?url=<?= e(rawurlencode($shareReddit)) ?><?= $shareTitle !== '' ? '&title=' . e(rawurlencode($shareTitle)) : '' ?>">Reddit</a>
    <button type="button" class="btn btn-sm btn-outline" data-share-copy="<?= e($shareCanonical) ?>">Copy link</button>
</div>
<script>
(function () {
    document.querySelectorAll('[data-share-copy]').forEach(function (b) {
        if (b._shareBound) { return; }
        b._shareBound = 1;
        b.addEventListener('click', function () {
            var text = b.getAttribute('data-share-copy');
            var done = function () {
                var original = b.textContent;
                b.textContent = 'Copied!';
                setTimeout(function () { b.textContent = original; }, 1500);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(done, function () {});
            }
        });
    });
})();
</script>
