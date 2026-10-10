<?php
// Cookie-consent banner for the optional Google Analytics snippet. Shown to
// guests and members alike on consent-aware pages until they accept, which
// sets a first-party ga_consent cookie and reloads (only then does the
// layout render the GA snippet). Rendered by views/layout.php.
if (!empty($gaShowBanner)):
?>
<div id="consent-banner" role="dialog" aria-label="Cookie consent"
     style="position:fixed;left:0;right:0;bottom:0;z-index:99996;background:#fff;border-top:2px solid var(--pink-300);padding:.65rem 1rem;display:flex;gap:.75rem 1rem;align-items:center;justify-content:center;flex-wrap:wrap;font-size:var(--font-size-sm);color:var(--purple-900);box-shadow:0 -6px 24px rgba(30,16,51,.18);">
    <span>We use cookies to keep you signed in and — only with your consent — to measure site traffic. See our <a class="thin-link" href="<?= url('/privacy') ?>">Privacy Policy</a>.</span>
    <button type="button" id="consent-accept" class="btn btn-sm">OK, continue</button>
</div>
<script nonce="<?= csp_nonce() ?>">
(function () {
    var banner = document.getElementById('consent-banner');
    if (!banner) return;
    var btn = document.getElementById('consent-accept');
    if (btn) {
        btn.addEventListener('click', function () {
            document.cookie = 'ga_consent=1; path=/; max-age=31536000; SameSite=Lax';
            banner.style.display = 'none';
            window.location.reload();
        });
    }
})();
</script>
<?php endif; ?>