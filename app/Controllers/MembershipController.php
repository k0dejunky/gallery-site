<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\BraintreeGateway;
use App\Core\Controller;
use App\Core\Database;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\FavoriteCategory;
use App\Models\MediaProgress;
use App\Models\PaymentProcessor;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Sale;
use App\Models\SaleCode;
use App\Models\Gallery;
use App\Models\Photo;
use App\Models\Receipt;
use App\Models\RefundRequest;

/**
 * Membership flows for regular users: the pricing page, the user's own
 * subscription history, and the manual subscribe/cancel actions. When a
 * Braintree processor is configured, checkout collects card details via
 * Braintree's client JS and submits the nonce server-side for vaulting
 * and recurring billing.
 */
class MembershipController extends Controller
{
    /**
     * The signed-in member's home dashboard.
     */
    public function dashboard(): void
    {
        $siteEditorPreview = $this->request->query('se', '') === 'user';
        if (!$siteEditorPreview) {
            Auth::requireLogin();
        }

        $user   = $siteEditorPreview ? ['id' => 0, 'email' => '', 'billing_first_name' => ''] : Auth::user();
        $userId = (int) $user['id'];

        $recentlyViewed = $siteEditorPreview ? [] : Gallery::recentlyViewed($userId, 4);
        $cardCovers    = Gallery::firstPhotos(array_map('intval', array_column($recentlyViewed, 'id')));

        $this->view('membership/dashboard', [
            'user'           => $user,
            'emailUnverified' => !$siteEditorPreview && empty($user['email_verified_at']),
            'activeSub'      => $siteEditorPreview ? null : Subscription::activeFor($userId),
            'pendingSub'     => $siteEditorPreview ? null : Subscription::pendingFor($userId),
            'latestSub'      => $siteEditorPreview ? null : (Subscription::forUser($userId)[0] ?? null),
            'recentlyViewed' => $recentlyViewed,
            'cardCovers'     => $cardCovers,
            'recentImages'   => $siteEditorPreview ? [] : Photo::recentImages(4),
            'recentVideos'   => $siteEditorPreview ? [] : Photo::recentVideos(4),
            // The categories a member can pick as their interests. Without
            // one the "recommended for you" strip is empty - and the
            // recommendations are the first thing on the listing now.
            'interestCategories' => $siteEditorPreview ? [] : \App\Models\Category::all(),
            // What the member was watching, so they can pick it up again.
            'continueWatching' => $siteEditorPreview ? [] : \App\Models\MediaProgress::recent($userId, 6),
            'interestIds'        => $siteEditorPreview ? [] : array_map(
                'intval',
                array_column(\App\Models\FavoriteCategory::forUser($userId), 'category_id')
            ),
            'sidebarNav'     => true,
            'siteEditorPreview' => $siteEditorPreview,
        ]);
    }

    /**
     * The pricing page is public; the account-facing actions require login.
     */
    public function index(): void
    {
        $user = Auth::user();

        // A paywall CTA carries ?from=gate, so this counts the clicks that
        // actually land on the plans (the gate itself records the view).
        if ((string) $this->request->query('from', '') === 'gate') {
            \App\Models\FunnelEvent::record(
                \App\Models\FunnelEvent::UPGRADE_CLICK,
                $user !== null ? (int) $user['id'] : null,
                (string) $this->request->query('tier', '')
            );
        }

        $plans = Plan::active();
        foreach ($plans as &$plan) {
            $plan['sale'] = Sale::activeForPlan((int) $plan['id']);
        }
        unset($plan);

        $data = [
            'plans' => $plans,
            // Only the processors a member may actually pick: a sandbox
            // processor cannot take a real payment, so it is not offered
            // while the site has a live one.
            'paymentProcessors' => PaymentProcessor::offerable(),
            'user' => $user,
            // How many galleries each tier opens - the plan cards say it, so
            // the member can see what the money buys.
            'levelCounts' => Gallery::countByLevel(),
            // Set by the paywall's CTA: the page the visitor was on.
            'returnTo' => safe_return_to((string) $this->request->query('return_to', '')),
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

        // A past-due membership needs a way to fix the card, so the page
        // needs the Braintree client token for the hosted fields. The SAME
        // form is the way an ACTIVE card membership changes its card before
        // it expires - the card used to be unchangeable until a charge had
        // already failed.
        $pastDueSub = null;
        foreach (Subscription::forUser($userId) as $sub) {
            if ((string) ($sub['status'] ?? '') === 'past_due') {
                $pastDueSub = $sub;
                break;
            }
        }

        $cardSub = $pastDueSub;
        if ($cardSub === null
            && $activeSub !== null
            && (string) ($activeSub['status'] ?? '') === 'active'
            && strpos((string) ($activeSub['transaction_ref'] ?? ''), 'BT-') === 0) {
            $cardSub = $activeSub;
        }

        $cardToken = null;
        if ($cardSub !== null && strpos((string) ($cardSub['transaction_ref'] ?? ''), 'BT-') === 0) {
            try {
                $processor = $this->braintreeProcessor();
                $gateway   = $processor !== null ? BraintreeGateway::fromConfig($processor) : null;
                if ($gateway !== null) {
                    $customerId = $this->btCustomerIdForUser($userId);
                    $cardToken  = $gateway->clientToken($customerId);
                }
            } catch (\Throwable $e) {
                error_log('[membership/my] the card token could not be built: ' . $e->getMessage());
            }
        }

        // The card-update form loads Braintree's JS from its CDN. The front
        // controller's policy already carries the nonce, js.braintreegateway,
        // the Hosted Fields frame origin and the analysis endpoints, so this
        // page needs no relaxation of its own (it used to REPLACE the policy
        // with one that allowed 'unsafe-inline').

        $this->view('membership/my', [
            'subscriptions' => Subscription::forUser($userId),
            'hasActive'     => $activeSub !== null,
            'activeSub'     => $activeSub,
            'pendingSub'    => Subscription::pendingFor($userId),
            'pastDueSub'    => $pastDueSub,
            'cardSub'       => $cardSub,
            'cardToken'     => $cardToken,
            'user'          => $user,
        ]);
    }

    /**
     * Braintree client token endpoint. Returns JSON with a client token
     * the JS SDK uses to initialise hosted fields / Drop-in.
     *
     * POST /membership/braintree-token  { plan_id }
     */
    public function braintreeToken(): void
    {
        if (!Auth::check()) {
            $this->json(['error' => 'Login required'], 401);
        }

        $planId = (int) $this->request->post('plan_id', (int) ($_GET['plan_id'] ?? 0));
        $plan   = Plan::find($planId);

        if ($plan === null || (int) $plan['active'] !== 1) {
            $this->json(['error' => 'Plan not available'], 400);
        }

        $processor = $this->braintreeProcessor();

        if ($processor === null) {
            $this->json(['error' => 'Braintree is not configured'], 500);
        }

        $gateway = BraintreeGateway::fromConfig($processor);

        if ($gateway === null) {
            $this->json(['error' => 'Braintree credentials are incomplete'], 500);
        }

        // If the user already has a Braintree customer id stored in
        // transaction_ref on a prior subscription, link the token to
        // that customer so payment methods are reused.
        $customerId = $this->btCustomerIdForUser((int) Auth::user()['id']);

        try {
            $token = $gateway->clientToken($customerId);
            $this->json([
                'client_token' => $token,
                'environment'  => $gateway->environment(),
                'public_key'   => $gateway->publicKey(),
            ]);
        } catch (\Throwable $e) {
            error_log('[membership/braintree-token] ' . $e->getMessage());
            $this->json(['error' => 'Could not generate payment token'], 500);
        }
    }

    /**
     * Dedicated Braintree checkout page. Loads the Braintree JS SDK from
     * the CDN and provides custom card fields + PayPal funding option.
     * This page overrides CSP so external Braintree scripts can load.
     *
     * GET /membership/checkout?plan_id=N
     */
    /**
     * Put a new card on a past-due membership.
     *
     * A past-due subscription keeps its access for a grace period, and the
     * past-due email links here - but there was NO WAY to enter a new card
     * anywhere on the site. The member could only wait, or ask for help.
     *
     * The member posts a Braintree payment-method nonce; the card is put on
     * the subscription at Braintree and the membership goes back to active.
     */
    public function braintreeUpdateCard(): void
    {
        Auth::requireLogin();

        $subscriptionId = (int) $this->request->post('subscription_id', 0);
        $subscription   = Subscription::find($subscriptionId);

        if ($subscription === null || (int) $subscription['user_id'] !== (int) Auth::user()['id']) {
            $this->flash('error', 'That membership was not found.');
            $this->redirect('/membership/my');
            return;
        }

        $ref = (string) ($subscription['transaction_ref'] ?? '');
        if (strpos($ref, 'BT-') !== 0) {
            $this->flash('error', 'This membership is not billed by card, so the card cannot be updated here. Please contact support.');
            $this->redirect('/membership/my');
            return;
        }

        $processor = $this->braintreeProcessor();
        $gateway   = $processor !== null ? BraintreeGateway::fromConfig($processor) : null;

        if ($gateway === null) {
            $this->flash('error', 'Card payments are not available right now.');
            $this->redirect('/membership/my');
            return;
        }

        $token = (string) ($this->request->post('payment_method_nonce', ''));
        if ($token === '') {
            $this->flash('error', 'No card details were received.');
            $this->redirect('/membership/my');
            return;
        }

        try {
            $customerId = $this->btCustomerIdForUser((int) Auth::user()['id']);

            if ($customerId === null) {
                $email = (string) Auth::user()['email'];
                $first = (string) (Auth::user()['billing_first_name'] ?? '');
                $last  = (string) (Auth::user()['billing_last_name'] ?? '');
                $customerId = $gateway->createCustomer($email, $first !== '' ? $first : null, $last !== '' ? $last : null);
            }

            $paymentMethod = $gateway->createPaymentMethod($customerId, $token, true);

            if (!isset($paymentMethod['token']) || (string) $paymentMethod['token'] === '') {
                throw new \RuntimeException('the card was not accepted');
            }

            if (!$gateway->updateSubscriptionPaymentMethod(substr($ref, strlen('BT-')), (string) $paymentMethod['token'])) {
                throw new \RuntimeException('the subscription did not accept the new card');
            }

            // A past-due membership is healthy again: clear the marker and
            // put the access back. An ACTIVE membership is only changing its
            // card, so there is nothing to clear and nothing to promise.
            $wasPastDue = (string) ($subscription['status'] ?? '') === 'past_due';

            Database::run(
                "UPDATE subscriptions
                 SET status = 'active', past_due_at = NULL, updated_at = CURRENT_TIMESTAMP
                 WHERE id = ? AND status = 'past_due'",
                [$subscriptionId]
            );

            $this->flash('success', $wasPastDue
                ? 'Your card has been updated. Your membership is active again.'
                : 'Your card has been updated. The next renewal will use it.');
        } catch (\Throwable $e) {
            error_log('[membership/update-card] ' . $e->getMessage());
            $this->flash('error', 'The card could not be updated. Please check the details and try again.');
        }

        $this->redirect('/membership/my');
    }

    public function braintreeCheckout(): void
    {
        Auth::requireLogin();

        $planId = (int) ($_GET['plan_id'] ?? 0);
        $plan   = Plan::find($planId);

        if ($plan === null || (int) $plan['active'] !== 1) {
            $this->flash('error', 'That plan is not available.');
            $this->redirect('/membership');
        }

        $processor = $this->braintreeProcessor();

        if ($processor === null) {
            $this->flash('error', 'Braintree is not configured for this site.');
            $this->redirect('/membership');
        }

        $sale = Sale::activeForPlan((int) $plan['id']);

        // Braintree's scripts and the Hosted Fields iframe are covered by the
        // front controller's nonce policy (see public/index.php); no page may
        // replace it with a weaker one.

        $this->viewStandalone('membership/braintree_checkout', [
            'plan'      => $plan,
            'sale'      => $sale,
            'processor' => $processor,
        ]);
    }

    /**
     * Request a membership for a chosen plan. A user can only hold one
     * pending request or active membership at a time, so resubscribing is
     * only allowed after a previous one was cancelled or expired.
     *
     * When the payment processor is Braintree, the form must have
     * submitted a payment_method_nonce. The server vaults the card and
     * creates a Braintree subscription, then activates locally.
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

        // A code can cover the WHOLE price. Resolve it before a processor is
        // chosen, because a $0 membership must not be sent to a card
        // processor: the card path charged the undiscounted plan price.
        $freeWithCode = false;
        if ($saleCode !== '') {
            try {
                $quote = Subscription::quote($planId, $saleCode);
            } catch (\RuntimeException $e) {
                $this->flash('error', $e->getMessage());
                $this->redirect('/membership');
                return;
            }
            $freeWithCode = SaleCode::isFree($quote['price']);
        }

        // Optional payment processor chosen at checkout. An explicit selection
        // of "none" (manual/offline) leaves the processor null; otherwise the
        // submitted processor must exist, be enabled AND be one a member may
        // pick (a sandbox processor is not offered while the site has a live
        // one, so a crafted request must not be able to select it either).
        $paymentProcessorId = null;
        $paymentChoice = $freeWithCode ? 0 : (int) $this->request->post('payment_processor', 0);
        if ($paymentChoice > 0) {
            $processor = PaymentProcessor::find($paymentChoice);
            if ($processor === null || (int) $processor['enabled'] !== 1
                || !PaymentProcessor::isOfferable($paymentChoice)) {
                $this->flash('error', 'That payment method is not available.');
                $this->redirect('/membership');
            }
            $paymentProcessorId = (int) $processor['id'];
        }

        if (Subscription::isActive($userId) || Subscription::pendingFor($userId) !== null) {
            $this->flash('error', 'You already have a membership or a pending request.');
            $this->redirect('/membership');
        }

        // ---------------------------------------------------------------
        // Braintree: vault the payment method and create the subscription
        // on Braintree's side. The subscription is activated immediately
        // if Braintree accepts the payment; otherwise it stays pending.
        // ---------------------------------------------------------------
        $nonce = trim((string) $this->request->post('payment_method_nonce', ''));
        $processorRow = $paymentProcessorId !== null ? PaymentProcessor::find($paymentProcessorId) : null;
        $isBraintree  = $processorRow !== null && strtolower((string) $processorRow['provider']) === 'braintree';

        if ($isBraintree && $nonce !== '') {
            $this->subscribeBraintree($userId, $plan, $processorRow, $nonce, $saleCode);
            return;
        }

        if ($isBraintree && $nonce === '') {
            $this->flash('error', 'Payment details are incomplete. Please try again.');
            $this->redirect('/membership/checkout?plan_id=' . (int) $planId);
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

        // Nothing left to pay: the code covered the whole price, so there is
        // no processor to wait for and no admin decision to make.
        if ($freeWithCode) {
            Subscription::approve($subscriptionId);
            $this->flash('success', 'Your code covered the whole price. Your "' . $plan['name'] . '" membership is now active.');
            $this->redirect('/membership/my');
            return;
        }

        // Hosted-checkout billers (CCBill/Epoch/SegPay) take over from here:
        // send the visitor to the biller's signup page; its postback will
        // activate this pending subscription when payment clears.
        if ($processorRow !== null) {
            $periodDays = ['monthly' => 30, 'yearly' => 365][$plan['billing_cycle'] ?? ''] ?? 3650;
            $checkout   = PaymentProcessor::checkoutUrl(
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

    /**
     * Record a PayPal-approved Silver subscription for admin reconciliation.
     * The PayPal webhook remains the source of truth for final activation.
     */
    public function paypalApprove(): void
    {
        Auth::requireLogin();
        header('Content-Type: application/json; charset=utf-8');

        $plan = Plan::find((int) $this->request->post('plan_id', 0));
        $paypalId = trim((string) $this->request->post('paypal_subscription_id', ''));

        $planSlug = strtolower((string) ($plan['slug'] ?? $plan['name']));
        if ($plan === null || !in_array($planSlug, ['silver', 'gold', 'platinum', 'chat-add-on'], true)
            || !preg_match('/\A[A-Za-z0-9_-]{6,100}\z/', $paypalId)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Invalid PayPal subscription.']);
            return;
        }

        $userId = (int) Auth::user()['id'];
        if (Subscription::isActive($userId) || Subscription::pendingFor($userId) !== null) {
            http_response_code(409);
            echo json_encode(['ok' => false, 'error' => 'You already have a membership or pending request.']);
            return;
        }

        $processor = null;
        foreach (PaymentProcessor::enabled() as $candidate) {
            if (strtolower((string) $candidate['provider']) === 'paypal') {
                $processor = $candidate;
                break;
            }
        }

        try {
            $subscriptionId = Subscription::create(
                $userId,
                (int) $plan['id'],
                null,
                false,
                $processor !== null ? (int) $processor['id'] : null,
                'PAYPAL-' . $paypalId
            );
            AuditLog::record($userId, 'create', 'subscription', $subscriptionId, 'PayPal ' . ucfirst($planSlug) . ' subscription pending verification', null, [
                'plan_id' => (int) $plan['id'],
                'paypal_subscription_id' => $paypalId,
            ]);
        } catch (\Throwable $exception) {
            error_log('[membership/paypal-approve] user=' . $userId . ' ' . $exception->getMessage());
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Could not record the PayPal subscription.']);
            return;
        }

        echo json_encode(['ok' => true]);
    }

    // ------------------------------------------------------------------
    // Braintree helpers
    // ------------------------------------------------------------------

    /**
     * Handle the Braintree-specific subscribe flow: vault the nonce,
     * create a Braintree subscription, then activate locally.
     */
    private function subscribeBraintree(int $userId, array $plan, array $processorRow, string $nonce, string $saleCode): void
    {
        $gateway = BraintreeGateway::fromConfig($processorRow);

        if ($gateway === null) {
            $this->flash('error', 'Braintree credentials are incomplete. Please try again later.');
            $this->redirect('/membership');
            return;
        }

        $cfg = PaymentProcessor::decodeConfig($processorRow);
        // Prefer the per-plan Braintree plan id provisioned on the plan
        // itself; fall back to the processor-level plan_id.
        $btPlanId = trim((string) ($plan['braintree_plan_id'] ?? ''));
        if ($btPlanId === '') {
            $btPlanId = trim((string) ($cfg['plan_id'] ?? ''));
        }

        if ($btPlanId === '') {
            $this->flash('error', 'Braintree plan ID is not configured. Please contact support.');
            $this->redirect('/membership');
            return;
        }

        // ----------------------------------------------------------------
        // Resolve the code BEFORE anything is charged.
        //
        // The old code looked the sale code up AFTER creating the Braintree
        // subscription, and it did so with a `plan_id` column that does not
        // exist on sale_codes. The query threw, so the member was subscribed
        // (and charged) at Braintree while the local row was never written.
        // An invalid code was also ignored: the member paid full price.
        // ----------------------------------------------------------------
        try {
            $quote = Subscription::quote((int) $plan['id'], trim($saleCode) !== '' ? $saleCode : null);
        } catch (\RuntimeException $e) {
            $this->flash('error', $e->getMessage());
            $this->redirect('/membership');
            return;
        }

        $codeRow = $quote['sale_code'];
        $sale    = $quote['sale'];
        $price   = $quote['price'];
        $level   = $quote['level'];

        // A fully discounted membership has nothing to charge, so it must
        // never reach a card. It is created on the manual path and approved
        // at once (there is no payment to wait for).
        if (SaleCode::isFree($price)) {
            try {
                $freeId = Subscription::create($userId, (int) $plan['id'], $saleCode, true);
                Subscription::approve($freeId);
                AuditLog::record($userId, 'create', 'subscription', $freeId, 'A 100% code covered "' . $plan['name'] . '"', null, [
                    'plan_id'   => (int) $plan['id'],
                    'sale_code' => $saleCode,
                ]);

                $this->flash('success', 'Your "' . $plan['name'] . '" membership is now active.');
                $this->redirect('/membership/my');
            } catch (\RuntimeException $e) {
                $this->flash('error', $e->getMessage());
                $this->redirect('/membership');
            }
            return;
        }

        $btSubscriptionId = null;

        try {
            $user = Auth::user();

            // 1. Create or find a Braintree customer
            $customerId = $this->btCustomerIdForUser($userId);
            $customer   = null;

            if ($customerId !== null) {
                $customer = $gateway->findCustomer($customerId);
            }

            if ($customer === null) {
                $customer = $gateway->createCustomer(
                    (string) $user['email'],
                    (string) ($user['billing_first_name'] ?? ''),
                    (string) ($user['billing_last_name'] ?? ''),
                );
                $customerId = (string) $customer['id'];
            }

            // 2. Vault the payment method from the nonce
            $pm = $gateway->createPaymentMethod($customerId, $nonce, true);
            $paymentMethodToken = (string) $pm['token'];

            // 3. Create the Braintree subscription at the DISCOUNTED price
            $btSubscription = $gateway->createSubscription($paymentMethodToken, $btPlanId, $price);
            $btSubscriptionId = (string) $btSubscription['id'];

            // 4. The local row: written in a transaction with the code
            //    redemption, so a half-written subscription can never grant
            //    access for a code that was not actually used up.
            $db = Database::connection();
            $db->beginTransaction();

            try {
                $transactionRef = 'BT-' . $btSubscriptionId;
                $saleId = $sale !== null ? (int) $sale['id'] : null;
                if ($codeRow !== null && !empty($codeRow['sale_id'])) {
                    $saleId = (int) $codeRow['sale_id'];
                }
                $saleCodeId = $codeRow !== null ? (int) $codeRow['id'] : null;

                Database::run(
                    'INSERT INTO subscriptions (user_id, plan_id, status, sale_id, sale_code_id, price_paid, access_level, payment_processor_id, transaction_ref, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)',
                    [$userId, (int) $plan['id'], 'active', $saleId, $saleCodeId, $price, $level, (int) $processorRow['id'], $transactionRef]
                );

                $subscriptionId = (int) $db->lastInsertId();
                Database::run("UPDATE subscriptions SET membership_number = LPAD(CAST(id AS CHAR), 5, '0') WHERE id = ?", [$subscriptionId]);

                // Set expiry based on billing cycle
                $expires = null;
                if (($plan['billing_cycle'] ?? '') === 'monthly') {
                    $expires = date('Y-m-d H:i:s', strtotime('+1 month'));
                } elseif (($plan['billing_cycle'] ?? '') === 'yearly') {
                    $expires = date('Y-m-d H:i:s', strtotime('+1 year'));
                }

                if ($expires !== null) {
                    Database::run(
                        'UPDATE subscriptions SET expires_at = ?, starts_at = CURRENT_TIMESTAMP WHERE id = ?',
                        [$expires, $subscriptionId]
                    );
                } else {
                    Database::run(
                        'UPDATE subscriptions SET starts_at = CURRENT_TIMESTAMP WHERE id = ?',
                        [$subscriptionId]
                    );
                }

                if ($codeRow !== null && !SaleCode::redeem((int) $codeRow['id'])) {
                    throw new \RuntimeException('That sale code has just reached its usage limit.');
                }

                $db->commit();
            } catch (\Throwable $localError) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $localError;
            }

            AuditLog::record($userId, 'create', 'subscription', $subscriptionId, 'Braintree payment for "' . $plan['name'] . '"', null, [
                'plan_id'               => (int) $plan['id'],
                'sale_code'             => $saleCode ?: null,
                'payment_processor_id'  => (int) $processorRow['id'],
                'transaction_ref'       => $transactionRef,
                'bt_customer_id'        => $customerId,
                'bt_subscription_id'    => $btSubscriptionId,
                'bt_payment_method'     => $paymentMethodToken,
            ]);

            $this->flash('success', 'Payment successful! Your "' . $plan['name'] . '" membership is now active.');
            $this->redirect('/membership/my');

        } catch (\Throwable $e) {
            // The subscription may exist at Braintree even though the local
            // row could not be written. Cancel it so the member is not billed
            // for access they do not have.
            if ($btSubscriptionId !== null) {
                try {
                    $gateway->cancelSubscription($btSubscriptionId);
                } catch (\Throwable $cancelError) {
                    error_log('[membership/subscribe/braintree] the orphaned Braintree subscription '
                        . $btSubscriptionId . ' could not be cancelled: ' . $cancelError->getMessage());
                }
            }
            error_log('[membership/subscribe/braintree] user=' . $userId . ' ' . $e->getMessage());
            $this->flash('error', 'We could not finish activating your membership. Please contact support before trying again.');
            $this->redirect('/membership');
        }
    }

    /**
     * Locate the first enabled Braintree processor, or null.
     */
    private function braintreeProcessor(): ?array
    {
        foreach (PaymentProcessor::enabled() as $pp) {
            if (strtolower((string) $pp['provider']) === 'braintree') {
                return $pp;
            }
        }

        return null;
    }

    /**
     * The member's receipts: everything the site has charged them, with a
     * stable invoice number, and a way to ask for a refund.
     *
     * A receipt is a view over subscriptions + purchases rather than a
     * second ledger, so it can never disagree with the money.
     */
    public function receipts(): void
    {
        Auth::requireLogin();

        $user   = Auth::user();
        $userId = (int) $user['id'];

        $receipts = Receipt::forUser($userId);
        $requests = RefundRequest::forUser($userId);

        $this->view('membership/receipts', [
            'user'      => $user,
            'receipts'  => $receipts,
            'requests'  => $requests,
            'totalPaid' => Receipt::totalPaid($userId),
        ]);
    }

    /**
     * One printable receipt. It is addressed by kind + the immutable row id,
     * and the row's owner is checked here: an invoice number is guessable, so
     * it must never be the only thing standing between two members.
     */
    public function receipt(string $kind, string $id): void
    {
        Auth::requireLogin();

        $user     = Auth::user();
        $receipt  = Receipt::findForUser((int) $user['id'], $kind, (int) $id);

        if ($receipt === null) {
            $this->notFound();
            return;
        }

        $open = null;
        foreach (RefundRequest::forUser((int) $user['id']) as $request) {
            if ((string) ($request['status'] ?? '') !== RefundRequest::STATUS_OPEN) {
                continue;
            }
            $hit = $kind === Receipt::KIND_PURCHASE
                ? (int) ($request['purchase_id'] ?? 0) === (int) $id
                : (int) ($request['subscription_id'] ?? 0) === (int) $id;
            if ($hit) {
                $open = $request;
                break;
            }
        }

        $this->view('membership/receipt', [
            'user'    => $user,
            'receipt' => $receipt,
            'open'    => $open,
        ]);
    }

    /** Ask for a refund of one charge. */
    public function requestRefund(): void
    {
        Auth::requireLogin();

        $user   = Auth::user();
        $kind   = (string) $this->request->post('kind', '');
        $charge = (int) $this->request->post('charge_id', 0);
        $reason = (string) $this->request->post('reason', '');

        try {
            RefundRequest::create((int) $user['id'], $kind, $charge, $reason);
            $this->flash('success', 'Your refund request was sent. We will review it and reply by email.');
        } catch (\InvalidArgumentException $e) {
            $this->flash('error', $e->getMessage());
        } catch (\Throwable $e) {
            error_log('[membership/refund] ' . $e->getMessage());
            $this->flash('error', 'The request could not be sent. Please try again or contact support.');
        }

        $this->redirect('/membership/receipts');
    }

    /** Withdraw an open request. */
    public function withdrawRefund(): void
    {
        Auth::requireLogin();

        $user = Auth::user();
        $id   = (int) $this->request->post('request_id', 0);

        if (RefundRequest::withdraw($id, (int) $user['id'])) {
            $this->flash('success', 'Your refund request was withdrawn.');
        } else {
            $this->flash('error', 'That request could not be withdrawn.');
        }

        $this->redirect('/membership/receipts');
    }

    /**
     * Look up the Braintree customer id previously stored on a
     * subscription's transaction_ref for this user. Returns null when
     * the user has no prior Braintree record.
     *
     * Braintree transaction_refs are stored as "BT-<subscriptionId>".
     * We find the most recent one and extract the BT customer id from
     * admin_logs.after_json.
     */
    private function btCustomerIdForUser(int $userId): ?string
    {
        $row = Database::run(
            "SELECT after_json
             FROM admin_logs
             WHERE user_id = ?
               AND entity_type = 'subscription'
               AND action = 'create'
               AND after_json LIKE '%bt_customer_id%'
             ORDER BY id DESC
             LIMIT 1",
            [$userId]
        )->fetch();

        if ($row === false) {
            return null;
        }

        $details = json_decode((string) $row['after_json'], true);

        if (!is_array($details)) {
            return null;
        }

        $customerId = (string) ($details['bt_customer_id'] ?? '');

        return $customerId !== '' ? $customerId : null;
    }
}
