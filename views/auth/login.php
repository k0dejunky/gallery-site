<?php
// Guest landing (the site root): a short value proposition, then a
// three-column layout — recent pictures on the left, the login form in the
// middle, recent videos on the right. Uses the shared auth shell (same layout
// as the signup page); only the form differs. Clicking a teaser thumbnail
// goes to the media page, which (thanks to login return_to) brings the guest
// straight back to it after signing in.
$authFormFile  = __DIR__ . '/partials/login_form.php';
$authHeroTitle = "Amethyst's Private Collection";
$authHeroText  = "A personal collection of photos and videos from the site's model, updated regularly — browse the catalog, save your favorites and chat. Log in to view, or sign up to start exploring.";
$authHeading   = 'Login';
$authLinksHtml = 'No account yet? <a href="' . url('/signup') . '">Sign up</a> &middot; <a href="' . url('/membership') . '">Membership</a> &middot; <a href="' . url('/admin') . '">Admin login</a>';

require __DIR__ . '/partials/auth_shell.php';