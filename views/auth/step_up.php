<?php http_response_code(428); ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? 'Confirm your password') ?></title>
<style>
  body { font-family: system-ui, sans-serif; background: #050208; color: #f5edf7; display: grid; place-items: center; min-height: 100vh; margin: 0; }
  .panel { background: #150a1d; border: 1px solid #2a1638; border-radius: 14px; padding: 1.6rem 2rem; width: min(360px, 92vw); box-shadow: 0 8px 24px rgba(0,0,0,.5); }
  h1 { font-size: 1.15rem; margin: 0 0 .5rem; }
  p.muted { color: #b9a0c4; margin: 0 0 1rem; font-size: .92rem; }
  label { font-size: .85rem; font-weight: 600; display: block; margin: .6rem 0 .25rem; }
  input[type=password], input[type=text] { width: 100%; box-sizing: border-box; padding: .6rem; border: 1px solid #2a1638; border-radius: 8px; background: #0d0612; color: #f5edf7; }
  .err { background: #2a1010; border: 1px solid #7f1d1d; color: #fca5a5; padding: .5rem .7rem; border-radius: 8px; font-size: .85rem; margin-bottom: .6rem; }
  .row { display: flex; gap: .6rem; margin-top: 1rem; }
  button { background: #e11d48; color: #ffffff; border: 0; border-radius: 8px; padding: .6rem 1.1rem; cursor: pointer; }
  a { color: #fda4af; text-decoration: none; font-size: .9rem; align-self: center; }
</style>
</head>
<body>
  <div class="panel">
    <h1><?= e($title ?? 'Confirm your password') ?></h1>
    <p class="muted">This action is sensitive - please confirm your password to continue.</p>
    <?php if (!empty($error)): ?><div class="err" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="post" action="<?= url('/reauthenticate') ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="return_to" value="<?= e($returnTo ?? url('/settings')) ?>">
        <label for="stepup-pass">Password</label>
        <input type="password" id="stepup-pass" name="password" autocomplete="current-password" required autofocus>
        <div class="row">
            <button type="submit">Confirm</button>
            <a href="<?= e($returnTo ?? url('/settings')) ?>">Cancel</a>
        </div>
    </form>
  </div>
</body>
</html>