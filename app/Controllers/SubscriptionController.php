<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
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
class SubscriptionController extends Controller
{
    public function __construct(Request $request)
    {
        parent::__construct($request);
        Auth::requirePermission('membership');
    }

    /**
     * Every subscription, newest first.
     */
    public function index(): void
    {
        $this->viewAdmin('subscriptions', [
            'subscriptions' => Subscription::all(),
            'plans'         => Plan::active(),
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

        if (Subscription::isActive($userId) || Subscription::pendingFor($userId) !== null) {
            $this->flash('error', 'That user already has a membership or pending request.');
            $this->redirect('/admin/subscriptions');
        }

        $id = Subscription::create($userId, $planId, null, false);
        Subscription::approve($id);
        AuditLog::record((int) Auth::user()['id'], 'create', 'subscription', $id, 'Granted "' . $plan['name'] . '" to ' . $user['email'], null, ['user_id' => $userId, 'plan_id' => $planId]);

        $this->flash('success', 'Membership granted to "' . $user['email'] . '".');
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
