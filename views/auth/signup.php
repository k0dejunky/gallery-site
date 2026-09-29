<?php
// Signup page: account form in the middle, with the recent pictures on the
// left and recent videos on the right. Uses the shared auth shell (same layout
// as the login page); only the form differs.
$authFormFile  = __DIR__ . '/partials/signup_form.php';
$authHeroTitle = "Amethyst's Private Collection";
$authHeroText  = "A personal collection of photos and videos from the site's model, updated regularly — browse the catalog, save your favorites and chat. Sign up to start exploring.";
$authHeading   = 'Create an Account';
$authLinksHtml = 'Already have an account? <a href="' . url('/login') . '">Log in</a> &middot; <a href="' . url('/membership') . '">Membership</a> &middot; <a href="' . url('/admin') . '">Admin login</a>';

require __DIR__ . '/partials/auth_shell.php';