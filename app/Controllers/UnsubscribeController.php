<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Models\EmailQueue;

/**
 * Public, session-free stop page for the signed opt-out links embedded in
 * every sent digest. The token is verified against GALLERY_MEDIA_KEY, the
 * account's marketing_opt_out flag is set, and a confirmation page is shown —
 * no login, no session and nothing else is affected.
 */
class UnsubscribeController extends Controller
{
    public function index(): void
    {
        $uid   = (int) $this->request->query('uid', 0);
        $token = trim((string) $this->request->query('t', ''));

        $optOut  = false;
        $message = '';

        if (!EmailQueue::verifyUnsubscribe($uid, $token)) {
            $message = 'That unsubscribe link is invalid or expired. Reply to any update email and ask to be removed, and we will take care of it.';
        } elseif (EmailQueue::optOut($uid)) {
            $optOut = true;
        } else {
            $message = 'We could not find that account.';
        }

        $this->viewStandalone('unsubscribe', ['optOut' => $optOut, 'message' => $message]);
    }
}