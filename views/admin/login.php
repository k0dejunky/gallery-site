<?php
// Standalone admin login (no shared layout, since admins aren't logged in yet).
$flash = \App\Core\Flash::all();
\App\Core\Flash::clear();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login — <?= e(config('app.site_name')) ?></title>
    <style>
<?= \App\Models\Theme::css(\App\Models\Theme::SCOPE_ADMIN, ':root') ?>
        body { font-family: sans-serif; max-width: 520px; margin: 3rem auto; padding: 0 1rem; background: var(--pink-200, #f9a8d4); color: #3b0764; }
        .title-header { text-align: center; margin-bottom: 1.25rem; }
        .title-header img { max-width: 100%; height: auto; border-radius: 10px; box-shadow: 0 2px 10px rgba(88, 28, 135, 0.3); }
        .card { background: #ffd9e8; border: 1px solid #f472b6; border-radius: 8px; padding: 1.5rem; box-shadow: 0 1px 4px rgba(88, 28, 135, 0.15); }
        h1 { color: #581c87; margin-top: 0; text-align: center; }
        a { color: #6b21a8; }
        label { color: #3b0764; }
        input[type="email"], input[type="password"] { width: 100%; box-sizing: border-box; padding: 0.5rem 0.6rem; border: 1px solid #f472b6; border-radius: 4px; background: #fff5f9; color: #3b0764; margin-top: 0.25rem; }
        input:focus { outline: 2px solid #a855f7; }
        .btn { display: inline-block; padding: 0.5rem 1rem; background: #f472b6; color: #3b0764; text-decoration: none; border-radius: 4px; border: none; cursor: pointer; margin-top: 0.5rem; }
        .btn:hover { background: #f052a0; }
        .flash { padding: 0.75rem 1rem; border-radius: 4px; margin-bottom: 1rem; color: #3b0764; }
        .flash.error { background: #f472b6; border-left: 4px solid #db2777; }
        .flash.success { background: #f9a8d4; border-left: 4px solid #9333ea; }
        .muted { color: #581c87; opacity: 0.8; }
    </style>
</head>
<body>
    <header class="title-header">
        <img src="<?= url('/assets/images/AmethystTitleImage.png') ?>" alt="<?= e(config('app.site_name')) ?> Admin">
    </header>
    <div class="card">
        <h1>Admin Login</h1>
        <?php foreach ($flash as $flashType => $messages): ?>
            <?php foreach ($messages as $message): ?>
                <div class="flash <?= e($flashType) ?>"><?= e($message) ?></div>
            <?php endforeach; ?>
        <?php endforeach; ?>
        <form method="post" action="<?= url('/admin') ?>">
            <?= csrf_field() ?>
            <p>
                <label for="email">Email</label><br>
                <input type="email" name="email" id="email" required autofocus>
            </p>
            <p>
                <label for="password">Password</label><br>
                <input type="password" name="password" id="password" required>
            </p>
            <p>
                <button type="submit" class="btn">Login to Admin</button>
            </p>
        </form>
        <p class="muted"><a href="<?= url('/login') ?>">Regular user login</a></p>
    </div>
</body>
</html>
