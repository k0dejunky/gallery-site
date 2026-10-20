<?php
// Cookie-consent banner for the optional Google Analytics snippet. Shown to
// guests and members alike on consent-aware pages until they accept, which
// sets a first-party ga_consent cookie and reloads (only then does the
// layout render the GA snippet). Rendered by views/layout.php.
if (!empty($gaShowBanner)):
?>
<div id="consent-banner" role="region" aria-label="Cookie consent" aria-live="polite">
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
            // The banner used to reload the page, which threw away the
            // member's scroll position and re-ran every query. It is hidden
            // in place instead; the analytics snippet starts with the NEXT
            // page load, which is what the consent is for.
            banner.hidden = true;
            banner.setAttribute('aria-hidden', 'true');
            try { localStorage.setItem('ga_consent', '1'); } catch (e) {}
        });
    }
})();
</script>
<?php endif; ?>