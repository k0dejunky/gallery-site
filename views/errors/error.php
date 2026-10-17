<?php
/**
 * Generic error page (4xx/5xx raised by the app). Variables: $status, $message.
 *
 * Body content only; the document chrome lives in views/errors/partials/shell.php.
 */
$errorCode  = (string) ($status ?? 'Error');
$errorTitle = $errorCode . ' Error';

ob_start();
?>
        <p><?= e((string) ($message ?? 'Something went wrong.')) ?></p>
        <p class="error-actions"><a href="<?= url('/galleries') ?>" class="btn">Back to galleries</a></p>
<?php
$errorContent = (string) ob_get_clean();

require __DIR__ . '/partials/shell.php';
