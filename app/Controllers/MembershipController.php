<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\AuditLog;
use App\Models\PaymentProcessor;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Sale;

/**
 * Membership flows for regular users: the pricing page, the user's own
 * subscription history, and the manual subscribe/cancel actions. Payments
 * are manual/placeholder for now, so subscribing creates a request that an
 * admin approves before access is granted.
 */
class MembershipController extends Controller
{
    /**
     * The pricing page is public; the account-facing actions require login.
     */
    public function index(): void
    {
        $user = Auth::user();

        $plans = Plan::active();
        foreach ($plans as &$plan) {
            $plan['sale'] = Sale::activeForPlan((int) $plan['id']);
        }
        unset($plan);

        $data = [
            'plans' => $plans,
            'paymentProcessors' => PaymentProcessor::enabled(),
        ];

        if ($user === null) {
            $data += [
                'hasActive'  => false,
                'activeSub'  => null,
                'pendingSub' => null,
            ];

            $this->view('membership/index', $data);
            return;
        }

        $userId    = (int) $user['id'];
        $activeSub = Subscription::activeFor($userId);

        $data += [
            'hasActive'  => $activeSub !== null,
            'activeSub'  => $activeSub,
            'pendingSub' => Subscription::pendingFor($userId),
        ];

        $this->view('membership/index', $data);
    }

    /**
     * The user's membership history: current status plus every past
     * subscription.
     */
    public function my(): void
    {
        Auth::requireLogin();

        $user      = Auth::user();
        $userId    = (int) $user['id'];
        $activeSub = Subscription::activeFor($userId);

        $this->view('membership/my', [
            'subscriptions' => Subscription::forUser($userId),
            'hasActive'     => $activeSub !== null,
            'activeSub'     => $activeSub,
        ]);
    }

    /**
     * Request a membership for a chosen plan. A user can only hold one
     * pending request or active membership at a time, so resubscribing is
     * only allowed after a previous one was cancelled or expired.
     */
    public function subscribe(): void
    {
        Auth::requireLogin();

        $planId = (int) $this->request->post('plan_id', 0);
        $plan   = Plan::find($planId);

        if ($plan === null || (int) $plan['active'] !== 1) {
            $this->flash('error', 'That plan is not available.');
            $this->redirect('/membership');
        }

        $userId = (int) Auth::user()['id'];
        $saleCode = trim((string) $this->request->post('sale_code', ''));

        // Optional payment processor chosen at checkout. An explicit selection
        // of "none" (manual/offline) leaves the processor null; otherwise the
        // submitted processor must exist and be enabled.
        $paymentProcessorId = null;
        $paymentChoice = (int) $this->request->post('payment_processor', 0);
        if ($paymentChoice > 0) {
            $processor = \App\Models\PaymentProcessor::find($paymentChoice);
            if ($processor === null || (int) $processor['enabled'] !== 1) {
                $this->flash('error', 'That payment method is not available.');
                $this->redirect('/membership');
            }
            $paymentProcessorId = (int) $processor['id'];
        }

        if (Subscription::isActive($userId) || Subscription::pendingFor($userId) !== null) {
            $this->flash('error', 'You already have a membership or a pending request.');
            $this->redirect('/membership');
        }

        // Placeholder transaction reference: in a real gateway integration this
        // would be the charge/payment-intent id returned by the processor.
        $transactionRef = $paymentProcessorId !== null
            ? 'PENDING-' . strtoupper(substr(md5(uniqid((string) $userId, true)), 0, 12))
            : null;

        try {
            $subscriptionId = Subscription::create($userId, $planId, $saleCode !== '' ? $saleCode : null, true, $paymentProcessorId, $transactionRef);
        } catch (\RuntimeException $e) {
            $this->flash('error', $e->getMessage());
            $this->redirect('/membership');
        }
        AuditLog::record($userId, 'create', 'subscription', $subscriptionId, 'Requested "' . $plan['name'] . '" membership', null, ['plan_id' => $planId, 'sale_code' => $saleCode ?: null, 'payment_processor_id' => $paymentProcessorId, 'transaction_ref' => $transactionRef]);

        // Hosted-checkout billers (CCBill/Epoch/SegPay) take over from here:
        // send the visitor to the biller's signup page; its postback will
        // activate this pending subscription when payment clears.
        $processorRow = $paymentProcessorId !== null ? \App\Models\PaymentProcessor::find($paymentProcessorId) : null;

        if ($processorRow !== null) {
            $periodDays = ['monthly' => 30, 'yearly' => 365][$plan['billing_cycle'] ?? ''] ?? 3650;
            $checkout   = \App\Models\PaymentProcessor::checkoutUrl(
                $processorRow,
                (float) ($plan['price'] ?? 0),
                $periodDays,
                (string) $transactionRef
            );

            if ($checkout !== null) {
                $this->redirect($checkout);
            }
        }

        $this->flash('success', 'Membership requested for "' . $plan['name'] . '". We will review it shortly.');
        $this->redirect('/membership/my');
    }

    /**
     * Cancel the user's active membership. Access stops at its current expiry
     * date (or immediately for lifetime plans).
     */
    public function cancel(): void
    {
        Auth::requireLogin();

        $userId = (int) Auth::user()['id'];
        $active = Subscription::activeFor($userId);

        if ($active === null) {
            $this->flash('error', 'You do not have an active membership to cancel.');
            $this->redirect('/membership/my');
        }

        Subscription::cancel((int) $active['id']);
        AuditLog::record($userId, 'update', 'subscription', (int) $active['id'], 'Cancelled membership', null, ['plan_id' => (int) $active['plan_id']]);

        $this->flash('success', 'Your membership has been cancelled.');
        $this->redirect('/membership/my');
    }
}
