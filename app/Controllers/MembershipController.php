<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\BraintreeGateway;
use App\Core\Controller;
use App\Core\Database;
use App\Models\AuditLog;
use App\Models\PaymentProcessor;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Sale;
use App\Models\Gallery;
use App\Models\Photo;

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

        $this->view('membership/dashboard', [
            'user'           => $user,
            'emailUnverified' => false,
            'activeSub'      => $siteEditorPreview ? null : Subscription::activeFor($userId),
            'pendingSub'     => $siteEditorPreview ? null : Subscription::pendingFor($userId),
            'latestSub'      => $siteEditorPreview ? null : (Subscription::forUser($userId)[0] ?? null),
            'recentlyViewed' => $siteEditorPreview ? [] : Gallery::recentlyViewed($userId, 4),
            'recentImages'   => $siteEditorPreview ? [] : Photo::recentImages(4),
            'recentVideos'   => $siteEditorPreview ? [] : Photo::recentVideos(4),
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
            'pendingSub'    => Subscription::pendingFor($userId),
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
        header('Content-Type: application/json');

        if (!Auth::check()) {
            http_response_code(401);
            echo json_encode(['error' => 'Login required']);
            return;
        }

        $planId = (int) $this->request->post('plan_id', (int) ($_GET['plan_id'] ?? 0));
        $plan   = Plan::find($planId);

        if ($plan === null || (int) $plan['active'] !== 1) {
            http_response_code(400);
            echo json_encode(['error' => 'Plan not available']);
            return;
        }

        $processor = $this->braintreeProcessor();

        if ($processor === null) {
            http_response_code(500);
            echo json_encode(['error' => 'Braintree is not configured']);
            return;
        }

        $gateway = BraintreeGateway::fromConfig($processor);

        if ($gateway === null) {
            http_response_code(500);
            echo json_encode(['error' => 'Braintree credentials are incomplete']);
            return;
        }

        // If the user already has a Braintree customer id stored in
        // transaction_ref on a prior subscription, link the token to
        // that customer so payment methods are reused.
        $customerId = $this->btCustomerIdForUser((int) Auth::user()['id']);

        try {
            $token = $gateway->clientToken($customerId);
            echo json_encode([
                'client_token' => $token,
                'environment'  => $gateway->environment(),
                'public_key'   => $gateway->publicKey(),
            ]);
        } catch (\Throwable $e) {
            error_log('[membership/braintree-token] ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Could not generate payment token']);
        }
    }

    /**
     * Dedicated Braintree checkout page. Loads the Braintree JS SDK from
     * the CDN and provides custom card fields + PayPal funding option.
     * This page overrides CSP so external Braintree scripts can load.
     *
     * GET /membership/checkout?plan_id=N
     */
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

        // Override the Apache-set CSP so Braintree's external JS/CDN can
        // load on this page. This header replaces the one Apache sends.
        header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline' https://js.braintreegateway.com; font-src 'self' data: https://assets.braintreegateway.com; media-src 'self' blob:; connect-src 'self' https://api.braintreegateway.com; frame-src https://client-analytics.braintreegateway.com https://www.sandbox.paypal.com https://www.paypal.com; frame-ancestors 'self'");

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

        // Optional payment processor chosen at checkout. An explicit selection
        // of "none" (manual/offline) leaves the processor null; otherwise the
        // submitted processor must exist and be enabled.
        $paymentProcessorId = null;
        $paymentChoice = (int) $this->request->post('payment_processor', 0);
        if ($paymentChoice > 0) {
            $processor = PaymentProcessor::find($paymentChoice);
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

        if ($plan === null || strtolower((string) ($plan['slug'] ?? $plan['name'])) !== 'silver'
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
            AuditLog::record($userId, 'create', 'subscription', $subscriptionId, 'PayPal Silver subscription pending verification', null, [
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
        }

        $cfg = PaymentProcessor::decodeConfig($processorRow);
        $btPlanId = trim((string) ($cfg['plan_id'] ?? ''));

        if ($btPlanId === '') {
            $this->flash('error', 'Braintree plan ID is not configured. Please contact support.');
            $this->redirect('/membership');
        }

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

            // 3. Work out the price (respect sale codes)
            $price = (float) $plan['price'];
            if ($saleCode !== '') {
                $sale = Sale::activeForPlan((int) $plan['id']);
                if ($sale !== null) {
                    $price = (float) $sale['sale_price'];
                }
            }

            // 4. Create the Braintree subscription
            $btSubscription = $gateway->createSubscription($paymentMethodToken, $btPlanId, $price);
            $btSubscriptionId = (string) $btSubscription['id'];

            // 5. Create the local subscription and activate immediately
            //    (Braintree has already charged / will charge per its schedule)
            $transactionRef = 'BT-' . $btSubscriptionId;
            $saleId = null;
            $saleCodeId = null;

            // Resolve sale/saleCode for local records
            if ($saleCode !== '') {
                $sale = Sale::activeForPlan((int) $plan['id']);
                if ($sale !== null) {
                    $saleId = (int) $sale['id'];
                }
                // Look up the sale_code_id by code value
                $scRow = Database::run(
                    'SELECT id FROM sale_codes WHERE code = ? AND plan_id = ? LIMIT 1',
                    [$saleCode, (int) $plan['id']]
                )->fetch();
                if ($scRow !== false) {
                    $saleCodeId = (int) $scRow['id'];
                }
            } elseif (Sale::activeForPlan((int) $plan['id']) !== null) {
                $saleId = (int) Sale::activeForPlan((int) $plan['id'])['id'];
            }

            Database::run(
                'INSERT INTO subscriptions (user_id, plan_id, status, sale_id, sale_code_id, price_paid, access_level, payment_processor_id, transaction_ref, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)',
                [$userId, (int) $plan['id'], 'active', $saleId, $saleCodeId, $price, (int) ($plan['level'] ?? 1), (int) $processorRow['id'], $transactionRef]
            );

             $subscriptionId = (int) Database::connection()->lastInsertId();
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
            error_log('[membership/subscribe/braintree] user=' . $userId . ' ' . $e->getMessage());
            $this->flash('error', 'Payment failed: ' . $e->getMessage());
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
     * Look up the Braintree customer id previously stored on a
     * subscription's transaction_ref for this user. Returns null when
     * the user has no prior Braintree record.
     *
     * Braintree transaction_refs are stored as "BT-<subscriptionId>".
     * We find the most recent one and extract the BT customer id from
     * audit_log details.
     */
    private function btCustomerIdForUser(int $userId): ?string
    {
        $row = Database::run(
            "SELECT details
             FROM audit_log
             WHERE user_id = ?
               AND entity_type = 'subscription'
               AND action = 'create'
               AND details LIKE '%bt_customer_id%'
             ORDER BY id DESC
             LIMIT 1",
            [$userId]
        )->fetch();

        if ($row === false) {
            return null;
        }

        $details = json_decode((string) $row['details'], true);

        if (!is_array($details)) {
            return null;
        }

        $customerId = (string) ($details['bt_customer_id'] ?? '');

        return $customerId !== '' ? $customerId : null;
    }
}
