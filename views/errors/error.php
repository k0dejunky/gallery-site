<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= $status ?> Error</title>
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
        <h1><?= e((string) $status) ?></h1>
        <p><?= e($message ?? 'Something went wrong.') ?></p>
        <p><a href="<?= url('/galleries') ?>">Back to galleries</a></p>
    </div>
</body>
</html>
