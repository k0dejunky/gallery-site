<?php
/**
 * 404 Not Found.
 *
 * Body content only; the document chrome lives in views/errors/partials/shell.php.
 */
$errorCode  = '404';
$errorTitle = '404 Not Found';

ob_start();
?>
        <p>The page you requested could not be found. It may have moved or no longer exists.</p>
        <p class="error-actions">
            <a href="<?= url('/galleries') ?>" class="btn">Browse galleries</a>
            <a href="<?= url('/membership') ?>" class="btn">See membership</a>
            <a href="<?= url('/support') ?>" class="btn">Contact support</a>
        </p>
        <form method="get" action="<?= url('/galleries') ?>" class="error-search">
            <input type="text" name="q" placeholder="Search galleries&hellip;" aria-label="Search galleries">
            <button type="submit" class="btn">Search</button>
        </form>
<?php
$errorContent = (string) ob_get_clean();

require __DIR__ . '/partials/shell.php';
