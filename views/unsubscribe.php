<?php
/**
 * One-click unsubscribe landing page (no layout, no session required).
 * Variables: $optOut (bool) — whether the user was opted out, $message.
 */
$siteName = (string) config('app.site_name');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Unsubscribed — <?= e($siteName) ?></title>
    <style>
        body { margin: 0; padding: 0; background: #f7dfea; font-family: Arial, Helvetica, sans-serif; color: #3b2550; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
        .card { background: #fff; border: 1px solid #e9c3d6; border-radius: 12px; max-width: 420px; width: 100%; padding: 32px 28px; text-align: center; box-sizing: border-box; }
        h1 { font-size: 22px; margin: 0 0 10px; }
        p { font-size: 14px; line-height: 1.6; color: #6b5b82; margin: 0 0 18px; }
        .btn { display: inline-block; background: #6d2ea8; color: #fff; text-decoration: none; font-weight: bold; padding: 11px 26px; border-radius: 24px; }
        .badge { display: inline-block; font-size: 13px; font-weight: bold; color: #1e7e34; background: #e5f7e9; border: 1px solid #b7e4c0; border-radius: 20px; padding: 5px 12px; margin-bottom: 14px; }
    </style>
</head>
<body>
    <div class="card">
        <?php if ($optOut): ?>
            <span class="badge">Unsubscribed</span>
            <h1>You are unsubscribed</h1>
            <p>You will no longer receive photo-update emails from <?= e($siteName) ?>. Your account and membership are unaffected.</p>
        <?php else: ?>
            <h1>Link not recognised</h1>
            <p><?= e($message) ?></p>
        <?php endif; ?>
        <a class="btn" href="<?= e(absolute_url('/')) ?>">Back to <?= e($siteName) ?></a>
    </div>
</body>
</html>