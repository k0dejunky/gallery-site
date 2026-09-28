<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\Subscription;
use App\Models\TrialLink;

/**
 * Public redemption of an admin-generated free-trial link
 * (GET /trial/{code}). The link grants a trial at the level and duration
 * configured by the admin, up to its signup quota; a user may only ever
 * redeem one trial.
 */
class TrialController extends Controller
{
    public function redeem(string $code): void
    {
        Auth::requireLogin('/trial/' . $code);

        $link = TrialLink::findByCode($code);

        if ($link === null || !(int) $link['enabled']) {
            $this->flash('error', 'That trial link is not available.');
            $this->redirect('/membership');
            return;
        }

        if ((int) $link['used_count'] >= (int) $link['max_uses']) {
            $this->flash('error', 'That trial link has already been fully used.');
            $this->redirect('/membership');
            return;
        }

        $userId = (int) Auth::user()['id'];
        $id     = Subscription::grantTrialFor($userId, (int) $link['level'], (int) $link['days']);

        if ($id === null) {
            $this->flash('error', 'You have already used a free trial or hold an active membership.');
            $this->redirect('/membership');
            return;
        }

        TrialLink::consume((int) $link['id']);

        $this->flash(
            'success',
            'Your ' . (int) $link['days'] . '-day ' . TrialLink::levelLabel((int) $link['level']) . ' trial is active — enjoy!'
        );
        $this->redirect('/galleries');
    }
}