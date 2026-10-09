<?php
// Google Analytics snippet. Rendered by views/layout.php ONLY when the
// visitor has consented (ga_consent cookie) and passed the 18+ check,
// and only on non-auth / non-legal pages. GA_ID comes from env per box.
$gaId = trim((string) env_value('GA_ID', ''));
if ($gaId !== ''):
?>
<!-- Google tag (gtag.js) -->
<script async src="https://www.googletagmanager.com/gtag/js?id=<?= e($gaId) ?>"></script>
<script>
    window.dataLayer = window.dataLayer || [];
    function gtag() { dataLayer.push(arguments); }
    gtag('js', new Date());
    gtag('config', '<?= e($gaId) ?>');
</script>
<?php endif; ?>