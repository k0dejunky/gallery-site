<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="robots" content="noindex, nofollow">
    <title>404 Not Found</title>
    <style>
        body { font-family: sans-serif; max-width: 600px; margin: 4rem auto; padding: 0 1rem; background: #f9a8d4; color: #3b0764; }
        h1 { color: #581c87; text-align: center; }
        a { color: #6b21a8; }
        .card { background: #f472b6; border: 1px solid #ec4899; border-radius: 8px; padding: 1.5rem; box-shadow: 0 1px 4px rgba(88, 28, 135, 0.15); }
        .title-header { text-align: center; margin-bottom: 1.25rem; }
        .title-header img { max-width: 100%; height: auto; border-radius: 10px; box-shadow: 0 2px 10px rgba(88, 28, 135, 0.3); }
    </style>
</head>
<body>
    <header class="title-header">
        <img src="<?= url('/assets/images/AmethystTitleImage.png') ?>" alt="<?= e(config('app.site_name')) ?>">
    </header>
    <div class="card">
        <h1>404</h1>
        <p>The page you requested could not be found. It may have moved or no longer exists.</p>
        <p style="text-align:center;">
            <a href="<?= url('/galleries') ?>" class="btn">Browse galleries</a>
            <a href="<?= url('/membership') ?>" class="btn">See membership</a>
            <a href="<?= url('/support') ?>" class="btn">Contact support</a>
        </p>
        <form method="get" action="<?= url('/galleries') ?>" style="margin-top:1rem;text-align:center;">
            <input type="text" name="q" placeholder="Search galleries…" aria-label="Search galleries" style="padding:.5rem;border:1px solid #ec4899;border-radius:6px;">
            <button type="submit" class="btn">Search</button>
        </form>
    </div>
</body>
</html>
