<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Models\User;

/**
 * Refer-a-friend: /go/{code} attributes a visitor's signup to a member; the
 * member's referral page shows their link and stats. The referrer earns +7
 * free days when the referred member becomes paying (Housekeeping sweep).
 */
class ReferralController extends Controller
{
    public function __construct(Request $request)
    {
        parent::__construct($request);
    }

    /**
     * A visitor clicked a member's referral link. Record the referrer in the
     * session (credited to the next signup from this browser) and send new
     * visitors to the signup page; logged-in members go to their dashboard.
     */
    public function go(string $code): void
    {
        $referrerId = User::findReferralUserId($code);
        if ($referrerId !== null) {
            $_SESSION['referral_user_id'] = $referrerId;
        }

        if (Auth::check()) {
            $this->redirect('/account');
        }
        $this->redirect('/signup');
    }

    /** The member's referral page: personal link + stats. */
    public function page(): void
    {
        Auth::requireLogin();
        $userId = (int) $_SESSION['user_id'];

        $code     = User::referralCode($userId);
        $link     = absolute_url('/go/' . $code);
        $referrals = (int) Database::run(
            'SELECT COUNT(*) FROM users WHERE referred_by_user_id = ?',
            [$userId]
        )->fetchColumn();
        $rewarded = (int) Database::run(
            'SELECT COUNT(*) FROM users WHERE referred_by_user_id = ? AND referred_by_rewarded_at IS NOT NULL',
            [$userId]
        )->fetchColumn();

        $this->view('referrals', [
            'link'      => $link,
            'code'      => $code,
            'referrals' => $referrals,
            'rewarded'  => $rewarded,
            'trialDays' => \App\Models\SiteConfig::trialDays(),
        ]);
    }
}