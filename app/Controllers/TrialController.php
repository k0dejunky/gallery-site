<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\TrialLink;

/**
 * Public entry for an admin-generated free-trial link (GET /trial/{code}).
 * The link is a signup promotion: visiting it lands on the sign-up page with
 * the code pre-filled in the promotion-code box, and the trial is granted
 * (and the link's used count incremented) only when someone actually signs up
 * with that code.
 */
class TrialController extends Controller
{
    public function redeem(string $code): void
    {
        $code = trim($code);
        $link = TrialLink::redeemable($code);

        if ($link === null) {
            $this->flash('error', 'That trial link is not available or has been fully used.');
            $this->redirect('/signup');
            return;
        }

        $this->redirect('/signup/' . rawurlencode($code));
    }
}