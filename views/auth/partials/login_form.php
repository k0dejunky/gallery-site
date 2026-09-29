<?php
// Login form — injected into the shared auth shell.
?>
<form method="post" action="<?= url('/login') ?>">
    <?= csrf_field() ?>
    <p>
        <label for="email">Email</label><br>
        <input type="email" name="email" id="email" required autofocus>
    </p>
    <p>
        <label for="password">Password</label><br>
        <input type="password" name="password" id="password" required>
        <a href="<?= url('/forgot-password') ?>" style="font-size:0.85rem;margin-left:0.5rem;">Forgot password?</a>
    </p>
    <p>
        <label style="display:inline-flex;align-items:center;gap:.35rem;font-weight:normal;cursor:pointer;">
            <input type="checkbox" name="remember_me" value="1" id="remember_me" style="width:auto;">
            Keep me signed in on this device
        </label>
    </p>
    <p style="text-align:center;">
        <button type="submit" class="btn">Login</button>
    </p>
</form>