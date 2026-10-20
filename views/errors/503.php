<?php
/**
 * 503 — maintenance / temporarily unavailable.
 *
 * Deliberately a calm, self-contained dark screen: this is the page shown when
 * something upstream is unhealthy, so it must not depend on the member chrome.
 * Styles live in public/assets/css/user.css under `.error-503`.
 */
?><!DOCTYPE html>
<html lang="en">
<head>
    <?php require dirname(__DIR__) . '/partials/ga.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>Briefly unavailable</title>
    <link rel="stylesheet" href="<?= e(asset_url('/assets/css/user.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('/theme.css') . '?v=' . \App\Models\Theme::themeCssVersion()) ?>">
</head>
<body class="error-503">
    <div class="box">
        <h1><span class="dot"></span>We'll be right back</h1>
        <p>The site is down for scheduled maintenance. Everything will be back online shortly — please check again in a few minutes.</p>
    </div>
</body>
</html>
