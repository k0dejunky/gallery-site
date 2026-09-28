<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;

/**
 * Admin: subscription management. Because payments are manual/placeholder,
 * the admin reviews each membership request, activates it (setting its start
 * and expiry dates), or cancels/deletes records. Admins can also grant a
 * subscription to a user directly.
 */
class SubscriptionController extends MembershipAdminController
{
    /**
     * Every subscription, newest first, plus a reconciliation panel of
     * pending biller signups (PENDING-* refs) so stuck checkouts surface.
     */
    public function index(): void
    {
        $pendingReconciliation = Database::run(
            'SELECT s.id, s.user_id, s.transaction_ref, s.created_at,
                    u.email AS user_email,
                    p.name AS plan_name, p.price, p.billing_cycle,
                    pp.name AS processor_name, pp.provider AS processor_provider,
                    TIMESTAMPDIFF(HOUR, s.created_at, CURRENT_TIMESTAMP) AS age_hours
             FROM subscriptions s
             JOIN users u ON u.id = s.user_id
             JOIN plans p ON p.id = s.plan_id
             LEFT JOIN payment_processors pp ON pp.id = s.payment_processor_id
             WHERE s.status = ? AND (s.transaction_ref LIKE ? OR s.transaction_ref LIKE ?)
             ORDER BY s.created_at ASC',
            ['pending', 'PENDING-%', 'PAYPAL-%']
        )->fetchAll();

        $this->viewAdmin('subscriptions', [
            'subscriptions' => Subscription::all(),
            'plans'         => Plan::active(),
            'reconciliation'=> $pendingReconciliation,
            'trialLinks'    => \App\Models\TrialLink::all(),
        ]);
    }

    /**
     * Manually grant a subscription to a user for a plan. Used when a member
     * pays outside the normal flow; creates an already-active subscription
     * unless a plan with a billing cycle is given, in which case the expiry
     * is computed from today.
     */
    public function store(): void
    {
        $email  = trim($this->request->input('user_email'));
        $planId = (int) $this->request->input('plan_id', 0);
        $user   = $email !== '' ? User::findByEmail($email) : null;
        $plan   = Plan::find($planId);

        if ($user === null || $plan === null) {
            $this->flash('error', 'Choose a valid user and plan.');
            $this->redirect('/admin/subscriptions');
        }

        $userId = (int) $user['id'];

        // A manual grant always takes effect: if the user already has a
        // subscription, it is overwritten with the newly granted plan (any
        // pending request is cleared too) so the new level applies right away.
        $current = Subscription::activeFor($userId);
        if ($current !== null) {
            Subscription::cancel((int) $current['id']);
        }
        $pending = Subscription::pendingFor($userId);
        if ($pending !== null) {
            Subscription::delete((int) $pending['id']);
        }

        $id = Subscription::create($userId, $planId, null, false);
        Subscription::approve($id);
        AuditLog::record((int) Auth::user()['id'], 'create', 'subscription', $id, 'Granted "' . $plan['name'] . '" to ' . $user['email'] . ($current !== null ? ' (replaced prior subscription)' : ''), null, ['user_id' => $userId, 'plan_id' => $planId, 'replaced_subscription_id' => $current !== null ? (int) $current['id'] : null]);

        $this->flash('success', 'Membership granted to "' . $user['email'] . '".');
        $this->redirect('/admin/subscriptions');
    }

    /**
     * Grant a one-time free trial (SiteConfig::trialDays() days) to a
     * non-member who has never had a trial.
     */
    public function grantTrial(): void
    {
        $email = trim($this->request->input('user_email'));
        $user  = $email !== '' ? User::findByEmail($email) : null;

        if ($user === null) {
            $this->flash('error', 'No user found with that email.');
            $this->redirect('/admin/subscriptions');
        }

        $userId = (int) $user['id'];
        $days   = \App\Models\SiteConfig::trialDays();
        $id     = Subscription::grantTrial($userId, $days);

        if ($id === null) {
            $this->flash('error', 'That user already had a trial or holds an active membership.');
        } else {
            AuditLog::record((int) Auth::user()['id'], 'create', 'subscription', $id, 'Granted ' . $days . '-day free trial to ' . $user['email'], null, ['user_id' => $userId, 'days' => $days]);
            $this->flash('success', 'Granted a ' . $days . '-day free trial to "' . $user['email'] . '".');
        }
        $this->redirect('/admin/subscriptions');
    }

    /**
     * Configure the free-trial length in days (admin).
     */
    public function trialSettings(): void
    {
        $days = max(1, min(90, (int) $this->request->post('trial_days', '3')));
        \App\Models\SiteConfig::setTrialDays($days);
        $this->flash('success', 'Free trial length set to ' . $days . ' day' . ($days === 1 ? '' : 's') . '.');
        $this->redirect('/admin/subscriptions');
    }

    /**
     * Create a shareable free-trial link (admin). The link grants a trial at
     * the chosen level for the chosen number of days, redeemable up to
     * max_uses times.
     */
    public function storeTrialLink(): void
    {
        $level   = max(1, min(3, (int) $this->request->post('trial_level', '1')));
        $days    = max(1, min(90, (int) $this->request->post('trial_days', '3')));
        $maxUses = max(1, (int) $this->request->post('max_uses', '1'));

        $id = \App\Models\TrialLink::create((int) Auth::user()['id'], $level, $days, $maxUses);
        AuditLog::record((int) Auth::user()['id'], 'create', 'trial_link', $id, 'Created trial link (' . \App\Models\TrialLink::levelLabel($level) . ', ' . $days . ' days, up to ' . $maxUses . ' uses)', null, ['level' => $level, 'days' => $days, 'max_uses' => $maxUses]);

        $this->flash('success', 'Trial link created.');
        $this->redirect('/admin/subscriptions');
    }

    /**
     * Enable/disable a trial link (admin).
     */
    public function toggleTrialLink(int $id): void
    {
        $link = \App\Models\TrialLink::find($id);
        if ($link === null) {
            $this->notFound();
            return;
        }

        \App\Models\TrialLink::toggle($id);
        AuditLog::record((int) Auth::user()['id'], 'update', 'trial_link', $id, 'Toggled trial link ' . (int) $link['enabled'] ? 'off' : 'on', ['enabled' => (int) $link['enabled']], ['enabled' => 1 - (int) $link['enabled']]);

        $this->flash('success', 'Trial link ' . ((int) $link['enabled'] ? 'disabled' : 'enabled') . '.');
        $this->redirect('/admin/subscriptions');
    }

    /**
     * Delete a trial link (admin).
     */
    public function destroyTrialLink(int $id): void
    {
        $link = \App\Models\TrialLink::find($id);
        if ($link === null) {
            $this->notFound();
            return;
        }

        \App\Models\TrialLink::delete($id);
        AuditLog::record((int) Auth::user()['id'], 'delete', 'trial_link', $id, 'Deleted trial link ' . $link['code'], ['level' => (int) $link['level'], 'days' => (int) $link['days']], null);

        $this->flash('success', 'Trial link deleted.');
        $this->redirect('/admin/subscriptions');
    }

    /**
     * Approve a pending membership request: set it active with start/expiry
     * dates computed from the plan's billing cycle.
     */
    public function approve(int $id): void
    {
        $sub = Subscription::find($id);

        if ($sub === null) {
            $this->notFound();
            return;
        }

        if ($sub['status'] !== 'pending') {
            $this->flash('error', 'Only pending requests can be approved.');
            $this->redirect('/admin/subscriptions');
        }

        Subscription::approve($id);
        AuditLog::record((int) Auth::user()['id'], 'update', 'subscription', $id, 'Approved membership request', ['status' => $sub['status'], 'user_id' => (int) $sub['user_id'], 'plan_id' => (int) $sub['plan_id']], ['status' => 'active', 'user_id' => (int) $sub['user_id'], 'plan_id' => (int) $sub['plan_id']]);

        $this->flash('success', 'Membership approved.');
        $returnTo = $this->request->post('return_to', 'subscriptions');
        $this->redirect($returnTo === 'logs' ? '/admin/logs' : '/admin/subscriptions');
    }

    /**
     * Cancel a subscription. Access stops at its current expiry (or
     * immediately for lifetime plans, which have no expiry date).
     */
    public function cancel(int $id): void
    {
        $sub = Subscription::find($id);

        if ($sub === null) {
            $this->notFound();
            return;
        }

        if ($sub['status'] === 'cancelled') {
            $this->flash('error', 'That subscription is already cancelled.');
            $this->redirect('/admin/subscriptions');
        }

        Subscription::cancel($id);
        AuditLog::record((int) Auth::user()['id'], 'update', 'subscription', $id, 'Cancelled membership', ['status' => $sub['status'], 'user_id' => (int) $sub['user_id'], 'plan_id' => (int) $sub['plan_id']], ['status' => 'cancelled', 'user_id' => (int) $sub['user_id'], 'plan_id' => (int) $sub['plan_id']]);

        $this->flash('success', 'Membership cancelled.');
        $this->redirect('/admin/subscriptions');
    }

    /**
     * Delete a subscription record entirely.
     */
    public function destroy(int $id): void
    {
        $sub = Subscription::find($id);

        if ($sub === null) {
            $this->notFound();
            return;
        }

        Subscription::delete($id);
        AuditLog::record((int) Auth::user()['id'], 'delete', 'subscription', $id, 'Deleted membership record', ['user_id' => (int) $sub['user_id'], 'plan_id' => (int) $sub['plan_id'], 'status' => $sub['status']]);

        $this->flash('success', 'Membership record deleted.');
        $this->redirect('/admin/subscriptions');
    }
}
