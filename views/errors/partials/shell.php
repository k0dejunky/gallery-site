<?php
/**
 * Shared shell for the 404 and generic error pages.
 *
 * Both pages used to carry the same 30-line document: the GA tag, the meta
 * head, an identical inline <style> block, the site title image and the card.
 * Only the heading, the message and the action links differ, so those are the
 * only things a page supplies now.
 *
 * The page sets:
 *   $errorCode     string  the big heading, e.g. '404' or '500'
 *   $errorContent  string  HTML for the inside of the card
 *   $errorTitle    optional <title> text (defaults to "{code} Error")
 *   $errorRobots   optional meta-robots value (defaults to noindex,nofollow)
 *
 * Styles live in public/assets/css/user.css under `.error-page` so the two
 * pages cannot drift apart.
 */
$errorCode    = (string) ($errorCode ?? 'Error');
$errorContent = (string) ($errorContent ?? '');
$errorTitle   = (string) ($errorTitle ?? $errorCode . ' Error');
$errorRobots  = (string) ($errorRobots ?? 'noindex, nofollow');
$siteName     = (string) config('app.site_name');
?><!DOCTYPE html>
<html lang="en">
<head>
    <?php require dirname(__DIR__, 2) . '/partials/ga.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="<?= e($errorRobots) ?>">
    <title><?= e($errorTitle) ?></title>
    <link rel="stylesheet" href="<?= e(asset_url('/assets/css/user.css')) ?>">
</head>
<body class="error-page">
    <header class="title-header">
        <img src="<?= url('/assets/images/AmethystTitleImage.png') ?>" alt="<?= e($siteName) ?>">
    </header>
    <div class="card">
        <h1><?= e($errorCode) ?></h1>
        <?= $errorContent ?>
    </div>
</body>
</html>
